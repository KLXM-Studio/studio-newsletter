<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Address;
use Klxm\Newsletter\Engine\Bounce\Classifier;
use Klxm\Newsletter\Engine\Bounce\Parser;
use Klxm\Newsletter\Engine\Bounce\Policy;
use Klxm\Newsletter\Engine\Bounce\Processor;
use Klxm\Newsletter\Engine\Bounce\Result;
use Klxm\Newsletter\Engine\Deliverability;
use Klxm\Newsletter\Engine\Imap\Client;
use Klxm\Newsletter\Engine\Mailjet\Api;
use Klxm\Newsletter\Engine\Mailjet\Events;
use Klxm\Newsletter\Engine\Ref;
use Klxm\Newsletter\Engine\Template;
use Klxm\Newsletter\Engine\Throttle;

/**
 * php bin/console newsletter:selftest [--site=…] – Engine ohne Netz (Rückläufer aus der Praxis, Regeln, Mailjet, IMAP gegen
 * lokalen Testserver, Drosselung, DNS mit Testdaten) und der Ablauf in der Datenbank der Website (Testdaten werden entfernt).
 */
final class SelfTest
{
    private static int $ok = 0;
    private static array $fails = [];

    private static function eq(string $label, mixed $got, mixed $want): void
    {
        if ($got === $want) { self::$ok++; echo "  ✓ $label\n"; return; }
        self::$fails[] = $label;
        echo "  ✗ $label\n      erwartet: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n      erhalten: " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
    }

    public static function run(): int
    {
        $key = 'selftest-key';
        echo "Bezug (VERP, Kopfzeile, Message-ID)\n";
        $ref = Ref::make(12, 345, $key);
        self::eq('VERP-Adresse', (bool) preg_match('~^bounce\+12\.345\.[0-9a-f]{10}@news\.example\.org$~', Ref::verp('bounce@news.example.org', 12, 345, $key)), true);
        self::eq('Bezug aus VERP', Ref::find('To: <' . Ref::verp('bounce@news.example.org', 12, 345, $key) . '>', $key), ['campaign' => 12, 'subscriber' => 345]);
        self::eq('Bezug aus Kopfzeile (Großbuchstaben)', Ref::find(Ref::HEADER . ': ' . strtoupper($ref), $key), ['campaign' => 12, 'subscriber' => 345]);
        self::eq('Bezug aus Message-ID', Ref::find('Message-ID: <' . Ref::messageId('news.example.org', 12, 345, $key) . '>', $key), ['campaign' => 12, 'subscriber' => 345]);
        self::eq('gefälschter Bezug abgewiesen', Ref::find('bounce+12.346.' . substr($ref, -10) . '@x', $key), null);
        self::eq('anderer Schlüssel abgewiesen', Ref::find($ref, 'anderer'), null);

        echo "Einordnung (RFC 3463, SMTP, Text)\n";
        self::eq('5.1.1 → hard', Classifier::classify('5.1.1'), Result::HARD);
        self::eq('5.2.2 Postfach voll → soft', Classifier::classify('5.2.2'), Result::SOFT);
        self::eq('4.4.1 → soft', Classifier::classify('4.4.1'), Result::SOFT);
        self::eq('5.7.1 Spam → block', Classifier::classify('5.7.1', 550, 'Message rejected due to spam content'), Result::BLOCK);
        self::eq('5.7.1 user unknown → hard', Classifier::classify('5.7.1', 550, 'Recipient address rejected: User unknown in local recipient table'), Result::HARD);
        self::eq('550 ohne Status, deutsch „unbekannt“ → hard', Classifier::classify('', 550, 'Empfänger unbekannt'), Result::HARD);
        self::eq('552 → soft', Classifier::classify('', 552, 'Requested mail action aborted: exceeded storage allocation'), Result::SOFT);
        self::eq('554 Spamhaus → block', Classifier::classify('', 554, 'Service unavailable; Client host blocked using zen.spamhaus.org'), Result::BLOCK);
        self::eq('Action delayed → delayed', Classifier::classify('4.4.7', 0, '', 'delayed'), Result::DELAYED);
        self::eq('Codes aus Diagnose', Classifier::codes('smtp; 550 5.1.1 <a@b.de>: Recipient address rejected'), [550, '5.1.1']);

        echo "Rückläufer aus dem Postfach\n";
        $verp = Ref::verp('bounce@news.example.org', 7, 42, $key);
        $r = Parser::parse(self::dsn($verp, 'erika@example.org', '5.1.1', 'smtp; 550 5.1.1 <erika@example.org>: Recipient address rejected: User unknown'), $key);
        self::eq('Postfix-DSN: hard, Empfänger, Bezug', [$r->type, $r->recipient, $r->status, $r->smtp, $r->ref], [Result::HARD, 'erika@example.org', '5.1.1', 550, ['campaign' => 7, 'subscriber' => 42]]);
        $r = Parser::parse(self::dsn('', 'max@example.org', '4.2.2', 'smtp; 452 4.2.2 Mailbox full', 'X-Newsletter-Ref: ' . Ref::make(7, 43, $key)), $key);
        self::eq('Postfach voll + Bezug nur aus Originalkopf', [$r->type, $r->ref], [Result::SOFT, ['campaign' => 7, 'subscriber' => 43]]);
        $r = Parser::parse(self::dsn('', 'x@example.org', '5.7.1', 'smtp; 550 5.7.1 Message rejected as spam by Content Filtering'), $key);
        self::eq('Exchange 5.7.1 Spam → block', $r->type, Result::BLOCK);
        $r = Parser::parse("From: MAILER-DAEMON@mx.example.net\r\nTo: $verp\r\nSubject: Mail delivery failed: returning message to sender\r\n\r\nThis message was created automatically by mail delivery software.\r\n\r\nA message that you sent could not be delivered to one or more of its recipients.\r\n\r\n  <anna@example.org>\r\n    host mx.example.org: 550 Requested action not taken: mailbox unavailable\r\n", $key);
        self::eq('Exim ohne DSN (Heuristik): hard, Empfänger aus Text', [$r->type, $r->recipient, $r->source], [Result::HARD, 'anna@example.org', 'heuristic']);
        $r = Parser::parse("From: mailer-daemon@gmx.net\r\nTo: $verp\r\nSubject: Unzustellbar: Ihre Nachricht\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nIhre Nachricht konnte nicht zugestellt werden.\r\nDas Postfach des Empf=C3=A4ngers ist voll.\r\n", $key);
        self::eq('GMX deutsch, quoted-printable: Postfach voll → soft', $r->type, Result::SOFT);
        $r = Parser::parse(self::arf('leser@example.org', Ref::make(7, 44, $key)), $key);
        self::eq('Feedback-Loop (ARF) → complaint', [$r->type, $r->recipient, $r->ref], [Result::COMPLAINT, 'leser@example.org', ['campaign' => 7, 'subscriber' => 44]]);
        $r = Parser::parse("From: chef@example.org\r\nTo: $verp\r\nSubject: Abwesenheitsnotiz: Ihr Newsletter\r\nAuto-Submitted: auto-replied\r\n\r\nIch bin bis 12.11. nicht im Büro.\r\n", $key);
        self::eq('Abwesenheitsnotiz → auto', $r->type, Result::AUTO);
        $r = Parser::parse("From: chef@example.org\r\nSubject: Re: Newsletter\r\n\r\nDanke!\r\n", $key);
        self::eq('normale Antwort → unknown', $r->type, Result::UNKNOWN);

        echo "Regeln (Policy)\n";
        $p = new Policy(3, 60);
        $now = 1_800_000_000;
        $sub = ['status' => 'active', 'soft_count' => 0, 'soft_first_at' => 0, 'soft_last_campaign' => 0, 'soft_campaigns' => 0];
        self::eq('hard sperrt sofort', $p->decide(new Result(Result::HARD), $sub, 1, $now)['changes']['status'] ?? null, 'bounced');
        $d1 = $p->decide(new Result(Result::SOFT), $sub, 1, $now);
        $s2 = $d1['changes'] + $sub;
        $d2 = $p->decide(new Result(Result::SOFT), $s2, 1, $now + 60);
        self::eq('Soft Bounce zählt je Ausgabe einmal', [$d1['changes']['soft_count'], $d2['changes']], [1, []]);
        $s3 = $p->decide(new Result(Result::SOFT), $s2, 2, $now + 86400)['changes'] + $s2;
        $d4 = $p->decide(new Result(Result::SOFT), $s3, 3, $now + 2 * 86400);
        self::eq('3 Soft Bounces aus 3 Ausgaben sperren (Grund soft)', [$d4['changes']['status'] ?? null, $d4['suppress']], ['bounced', 'soft']);
        $old = $p->decide(new Result(Result::SOFT), $s3, 3, $now + 90 * 86400);
        self::eq('nach Ablauf des Zeitraums beginnt die Zählung neu', [$old['changes']['soft_count'], $old['changes']['status'] ?? null], [1, null]);
        self::eq('block ändert den Empfänger nicht', $p->decide(new Result(Result::BLOCK), $sub, 1, $now)['changes'], []);
        self::eq('Zustellung setzt Zähler zurück', $p->decide(new Result(Result::DELIVERED), $s3, 4, $now)['changes']['soft_count'] ?? null, 0);
        self::eq('Beschwerde → Sperrliste', $p->decide(new Result(Result::COMPLAINT), $sub, 1, $now)['suppress'], 'complaint');

        echo "Mailjet\n";
        $ev = Events::parse([
            ['event' => 'bounce', 'email' => 'a@example.org', 'hard_bounce' => true, 'error_related_to' => 'recipient', 'error' => 'user unknown', 'CustomID' => 'nl.' . Ref::make(5, 6, $key), 'MessageID' => 1234],
            ['event' => 'bounce', 'email' => 'b@example.org', 'hard_bounce' => false, 'error_related_to' => 'spam', 'error' => 'sender blocked'],
            ['event' => 'blocked', 'email' => 'c@example.org', 'error_related_to' => 'recipient', 'error' => 'preblocked'],
            ['event' => 'spam', 'email' => 'd@example.org', 'source' => 'JMRPP'],
            ['event' => 'unsub', 'email' => 'e@example.org'],
            ['event' => 'sent', 'email' => 'f@example.org'],
        ], $key);
        self::eq('Ereignisse → Arten', array_map(fn($r) => $r->type, $ev), [Result::HARD, Result::BLOCK, Result::HARD, Result::COMPLAINT, Result::UNSUBSCRIBE, Result::DELIVERED]);
        self::eq('CustomID → Bezug, MessageID übernommen', [$ev[0]->ref, $ev[0]->providerId], [['campaign' => 5, 'subscriber' => 6], '1234']);
        self::eq('einzelnes Ereignis (kein Array)', count(Events::parse(['event' => 'spam', 'email' => 'x@example.org'], $key)), 1);
        $calls = [];
        $api = new Api('key', 'secret', 5, function (string $m, string $url, array $h, ?string $json) use (&$calls) {
            $calls[] = [$m, $url, json_decode((string) $json, true)];
            return [200, json_encode(['Messages' => [['Status' => 'success', 'To' => [['MessageID' => 99]]], ['Status' => 'error', 'Errors' => [['ErrorMessage' => 'Invalid email']]]]])];
        });
        $res = $api->send([['from' => 'a@example.org', 'to' => 'b@example.org', 'subject' => 'S', 'text' => 'T', 'custom_id' => 'nl.x', 'headers' => ['List-Unsubscribe' => '<https://x>']],
            ['from' => 'a@example.org', 'to' => 'kaputt', 'subject' => 'S', 'text' => 'T']]);
        self::eq('Send API: Ergebnis je Nachricht', [$res[0]['ok'], $res[0]['id'], $res[1]['ok'], $res[1]['error']], [true, '99', false, 'Invalid email']);
        self::eq('Send API: v3.1, Messung aus, Kopfzeilen', [str_ends_with($calls[0][1], '/v3.1/send'), $calls[0][2]['Messages'][0]['TrackOpens'], $calls[0][2]['Messages'][0]['Headers']['List-Unsubscribe']], [true, 'disabled', '<https://x>']);

        echo "IMAP-Client (lokaler Testserver)\n";
        self::imap($key);

        echo "Drosselung, Adressen, Vorlage, DNS\n";
        $t = new Throttle(['google' => 2], 5);
        $q = [];
        foreach (['gmail.com', 'googlemail.com', 'gmail.com', 'web.de', 'gmx.net', 'example.org', 'gmail.com', 'example.org'] as $i => $d) $q[] = ['id' => $i + 1, 'domain' => $d];
        self::eq('Gmail-Gruppe höchstens 2, gesamt 5', $t->pick($q), [1, 2, 4, 5, 6]);
        self::eq('Adresse vereinheitlicht', Address::normalize('  Erika.Muster@EXAMPLE.org '), 'erika.muster@example.org');
        self::eq('Prüfsumme gleich bei anderer Schreibweise', Address::hash('A@Example.org', $key) === Address::hash('a@example.org', $key), true);
        self::eq('Platzhalter in HTML escaped', Template::fill('Hallo {name}, {x}', ['name' => '<b>Eva</b>'], true), 'Hallo &lt;b&gt;Eva&lt;/b&gt;, {x}');
        self::eq('Textfassung mit Links', Template::toText('<p>Hallo <a href="https://example.org/a">hier</a></p><ul><li>Eins</li></ul>'), "Hallo hier (https://example.org/a)\n\n- Eins");
        $dns = Deliverability::check('news.beispiel.de', fn(string $n, int $t) => match (true) {
            $t === DNS_TXT && $n === 'news.beispiel.de' => [['txt' => 'v=spf1 include:spf.mailjet.com -all']],
            $t === DNS_TXT && $n === '_dmarc.news.beispiel.de' => [['txt' => 'v=DMARC1; p=quarantine; rua=mailto:x@beispiel.de']],
            $t === DNS_MX => [['target' => 'mx.beispiel.de']],
            default => [],
        });
        self::eq('DNS: SPF, DMARC (quarantine), MX', [$dns['spf'], $dns['dmarc'], $dns['dmarc_policy'], $dns['mx']], [true, true, 'quarantine', true]);

        echo "Konto → Versandweg, Bounce-Postfach aus dem Konto\n";
        self::eq('Mailjet (API), Mailjet (SMTP), eigener Server erkannt', [
            Newsletter::modeFor(['transport' => 'ext:mailjet']), Newsletter::modeFor(['transport' => 'smtp', 'host' => 'in-v3.mailjet.com']),
            Newsletter::modeFor(['transport' => 'dsn', 'dsn' => 'mailjet+api://k:s@default']), Newsletter::modeFor(['transport' => 'smtp', 'host' => 'smtp.example.org'])],
            ['mailjet_api', 'mailjet_smtp', 'mailjet_smtp', 'smtp']);
        self::eq('Bounce-Vorgaben aus dem Konto (smtp. → imap.)', Bounces::defaults(['transport' => 'smtp', 'host' => 'smtp.example.org', 'from' => 'news@example.org', 'user' => 'news@example.org', 'pass' => 'x']),
            ['address' => 'news@example.org', 'host' => 'imap.example.org', 'user' => 'news@example.org', 'pass' => 'x']);
        self::eq('Mailjet-Konto: keine IMAP-Vorgaben', Bounces::defaults(['transport' => 'ext:mailjet', 'from' => 'a@b.c'])['host'], '');

        echo "Designs und Vorlagen\n";
        foreach (Design::PRESETS as $k => $p) self::eq("Design „{$p['name']}“: Kontraste WCAG AA", Design::contrastIssues(Design::settings(['settings' => json_encode($p + Design::DEFAULTS)])), []);
        foreach (Templates::builtin() as $k => $t) {
            [$clean, $err] = Blocks::sanitize(array_map(fn($b) => ['type' => $b['type'], 'data' => $b['data']], $t['blocks']));
            $html = Blocks::render($clean, Design::settings(['settings' => '{}']), ['name' => 'Erika', 'site' => 'Muster'], true);
            self::eq("Vorlage „{$t['name']}“: Bausteine gültig und dargestellt", [$err, count($clean) === count($t['blocks']), str_contains($html, '<tr>')], [[], true, true]);
        }
        $dk = Design::settings(['settings' => '{}']);
        [$bgc, $sd] = Blocks::surface(['_bg' => 'dark'], $dk);
        self::eq('dunkler Hintergrund → helle Schrift (Kontrast ≥ 4,5)', \Core\Contrast::ratio($sd['text'], $bgc) >= 4.5, true);
        [$bga, $sa] = Blocks::surface(['_bg' => 'accent'], $dk);
        self::eq('Akzent-Hintergrund → Knopf weiß mit Grundfarbe', [$sa['accent'], $sa['button_text']], ['#ffffff', $bga]);
        $noLegal = Design::settings(['settings' => json_encode(['blocks_set' => true, 'header_blocks' => [], 'footer_blocks' => [['type' => 'spacer', 'data' => []]]])]);
        $mail = Message::html(['id' => 0, 'subject' => 'S', 'blocks' => [['type' => 'text', 'data' => ['text' => '<p>x</p>']]], 'design_id' => 0, 'lists' => ''],
            ['name' => '', 'site' => 'Muster'], null, ['design' => $noLegal, 'unsubscribe' => 'https://example.org/ab/1']);
        self::eq('Fuß ohne Abmeldelink → wird angehängt', str_contains($mail, 'https://example.org/ab/1'), true);
        $legacy = Blocks::of(['blocks' => '', 'image' => 0, 'body' => '<p>Alt</p>', 'button_label' => 'Los', 'button_link' => 'https://example.org']);
        self::eq('ältere Ausgabe (Inhalt + Knopf) als Bausteine', array_column($legacy, 'type'), ['text', 'button']);
        [$b2] = Blocks::sanitize([['type' => 'boese', 'data' => []], ['type' => 'heading', 'data' => ['text' => '<script>x</script>Hallo', 'size' => 'l']]]);
        self::eq('unbekannte Bausteine verworfen, Text escaped', [count($b2), str_contains(Blocks::render($b2, Design::settings(['settings' => '{}']), [], false), '<script>')], [1, false]);

        echo "Ablauf in der Datenbank (Website „" . site()->key . "“)\n";
        self::database();

        echo "Altbestand bestätigen lassen (Repermit)\n";
        self::repermit();

        if (class_exists(\Core\Mail\Imprint::class)) {   // Kern mit Impressum für E-Mails
            echo "Impressum der Website in jeder Mail (Core\\Mail\\Imprint)\n";
            self::imprint();
        }

        echo "\n  " . self::$ok . ' Prüfungen bestanden, ' . count(self::$fails) . " fehlgeschlagen\n";
        return self::$fails ? 1 : 0;
    }

    private static function imap(string $key): void
    {
        $dir = sys_get_temp_dir() . '/nl-imap-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $portFile = $dir . '/port';
        file_put_contents($dir . '/m.eml', self::dsn(Ref::verp('bounce@example.org', 3, 4, $key), 'z@example.org', '5.1.1', 'smtp; 550 5.1.1 unknown'));
        $php = \Core\Network\Stats::phpBinary();
        $proc = proc_open([$php, dirname(__DIR__) . '/tests/fake-imap.php', $portFile, $dir . '/m.eml'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !is_file($portFile); $i++) usleep(50000);
            $port = (int) @file_get_contents($portFile);
            if (!$port) { self::eq('Testserver gestartet', false, true); return; }
            $c = new Client('127.0.0.1', $port, 'none', 'bounce@example.org', 'geheim "x"', 5);
            $c->connect();
            self::eq('Anmeldung mit Sonderzeichen im Passwort, MOVE erkannt', $c->has('MOVE'), true);
            self::eq('Ordner: 2 Nachrichten', $c->select('INBOX'), 2);
            self::eq('Suche sortiert', $c->search('UNSEEN'), [7, 9]);
            $raw = $c->fetch(7);
            self::eq('Nachricht vollständig (Literal)', Parser::parse($raw, $key)->ref, ['campaign' => 3, 'subscriber' => 4]);
            $c->seen(7);
            $c->move(9, 'Newsletter-verarbeitet');
            $c->logout();
            usleep(100000);
            self::eq('Markieren und Verschieben gesendet', (string) @file_get_contents($portFile . '.log'), "STORE 7\nMOVE 9");
            $bad = new Client('imap.example.org', 143, 'none', 'u', 'p', 2);
            try { $bad->connect(); self::eq('Klartext zu fremdem Server verweigert', false, true); } catch (\Throwable $e) { self::eq('Klartext zu fremdem Server verweigert', str_contains($e->getMessage(), 'ohne Verschlüsselung') || str_contains($e->getMessage(), 'Verbindung'), true); }
        } catch (\Throwable $e) {
            self::eq('IMAP-Gespräch: ' . $e->getMessage(), false, true);
        } finally {
            if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private static function database(): void
    {
        $key = Newsletter::key();
        $email = 'nl-selftest-' . bin2hex(random_bytes(3)) . '@example.org';
        $ids = ['sub' => 0, 'list' => 0, 'camp' => 0, 'list2' => 0];
        $subIds = [];
        try {
            $ids['list'] = Repo::saveList(0, ['name' => 'Selbsttest', 'public' => false]);
            $s = Repo::upsert(['email' => strtoupper($email), 'name' => 'Erika', 'status' => 'pending', 'source' => 'admin']);
            $ids['sub'] = (int) $s['id'];
            $subIds[] = $ids['sub'];
            self::eq('Empfänger angelegt, Adresse vereinheitlicht', $s['email'], $email);
            Repo::update($ids['sub'], ['pending_lists' => (string) $ids['list'], 'consent_at' => Repo::now()]);
            $tok = Newsletter::token('confirm', (array) Repo::find($ids['sub']));
            self::eq('falsches Bestätigungs-Token', Signup::confirm(substr($tok, 0, -1) . (str_ends_with($tok, 'a') ? 'b' : 'a')), 'invalid');
            self::eq('Bestätigung aktiviert und ordnet Verteiler zu', [Signup::confirm($tok), Repo::find($ids['sub'])['status'] ?? '', Repo::listIds($ids['sub'])], ['ok', 'active', [$ids['list']]]);
            $ids['camp'] = Repo::saveCampaign(0, ['subject' => 'Test {name}', 'body' => '<p>Hallo {name}, <a href="/kontakt">Kontakt</a></p>', 'lists' => (string) $ids['list']]);
            $c = (array) Repo::campaign($ids['camp']);
            self::eq('Empfängerzahl der Ausgabe', Campaigns::audience([$ids['list']]), 1);
            self::eq('Warteschlange einmal je Empfänger', [Campaigns::enqueue($c), Campaigns::enqueue($c)], [1, 1]);
            $m = Message::campaign($c, (array) Repo::find($ids['sub']));
            self::eq('Nachricht: Betreff, Abmeldelink, One-Click, Bezug', [$m['subject'], str_starts_with($m['headers']['List-Unsubscribe'], '<http'), $m['headers']['List-Unsubscribe-Post'],
                Ref::find(Ref::HEADER . ': ' . $m['headers'][Ref::HEADER], $key)], ['Test Erika', true, 'List-Unsubscribe=One-Click', ['campaign' => $ids['camp'], 'subscriber' => $ids['sub']]]);
            self::eq('Relativer Link im Inhalt absolut', (bool) preg_match('~href="https?://[^"]+/kontakt"~', $m['html']), true);
            self::eq('Textfassung vorhanden', str_contains($m['text'], 'Hallo Erika'), true);
            self::eq('Abmeldelink gehört zum Empfänger', Newsletter::fromToken('unsub', basename((string) parse_url(trim($m['headers']['List-Unsubscribe'], '<>'), PHP_URL_PATH)))['id'] ?? 0, $ids['sub']);
            // Rückläufer: hard → gesperrt, Sperrliste, Zähler der Ausgabe
            $raw = self::dsn('', 'irgendwer@example.net', '5.1.1', 'smtp; 550 5.1.1 User unknown', 'X-Newsletter-Ref: ' . $m['ref']);
            (new Processor(new Repo(), Newsletter::policy()))->apply(Parser::parse($raw, $key), time());
            self::eq('Hard Bounce sperrt Empfänger (über Bezug, nicht Adresse)', [Repo::find($ids['sub'])['status'] ?? '', Repo::suppressed($email), (int) (Repo::campaign($ids['camp'])['hard'] ?? 0)], ['bounced', 'hard', 1]);
            self::eq('Gesperrte zählen nicht mehr', Campaigns::audience([$ids['list']]), 0);

            // Mehrere Verteiler: Auftritt, Angebot des Blocks, Abmeldung je Verteiler
            $ids['list2'] = Repo::saveList(0, ['name' => 'Selbsttest Presse', 'public' => false, 'footer' => 'Pressestelle Musterstadt', 'color' => '#aa3300', 'own_color' => 1, 'account' => 'gibt-es-nicht']);
            $l2 = (array) Repo::list($ids['list2']);
            self::eq('Verteiler: Farbe, Fußzeile, unbekanntes Konto verworfen', [$l2['color'], $l2['footer'], $l2['account']], ['#aa3300', 'Pressestelle Musterstadt', '']);
            $offer = Newsletter::signOffer([$ids['list2']]);
            self::eq('Angebot des Blocks signiert', [Newsletter::verifyOffer($offer), Newsletter::verifyOffer($ids['list'] . substr($offer, strpos($offer, '.')))], [[$ids['list2']], []]);
            Repo::update($subIds[] = $s2 = (int) Repo::upsert(['email' => 'x' . $email, 'status' => 'active', 'source' => 'admin'])['id'], []);
            Repo::setLists($s2, [$ids['list'], $ids['list2']]);
            // Rechte: Redaktion sieht nur Empfänger ihrer Verteiler; Zuweisung bleibt bei anderen Speichervorgängen erhalten
            app()->db->update('newsletter_lists', ['editors' => 'r:selbsttest'], 'id = ?', [$ids['list2']]);
            Repo::saveList($ids['list2'], ['name' => 'Selbsttest Presse', 'public' => false, 'footer' => 'Pressestelle Musterstadt', 'color' => '#aa3300', 'own_color' => 1]);
            self::eq('Verteiler-Zuweisung bleibt beim Speichern ohne Formular', Access::editors((array) Repo::list($ids['list2'])), ['r:selbsttest']);
            self::eq('Filter „nur diese Verteiler“', [array_column(Repo::subscribers(['only' => [$ids['list2']]])['rows'], 'id'), Repo::subscribers(['only' => []])['total']], [[$s2], 0]);
            $c2 = (array) Repo::campaign(Repo::saveCampaign($ids['camp'], ['lists' => (string) $ids['list2']]));
            $m2 = Message::campaign($c2, (array) Repo::find($s2));
            self::eq('Mail trägt Fußzeile und Farbe des Verteilers, Abmeldelink mit Ausgabe', [str_contains($m2['html'], 'Pressestelle Musterstadt'), str_contains($m2['html'], '#aa3300'), str_contains($m2['headers']['List-Unsubscribe'], '?a=' . $ids['camp'])], [true, true, true]);
            Signup::unsubscribe((array) Repo::find($s2), 'oneclick', Repo::campaignLists($c2), $ids['camp']);
            self::eq('Ein-Klick: nur vom Verteiler der Ausgabe abgemeldet', [Repo::find($s2)['status'] ?? '', Repo::listIds($s2)], ['active', [$ids['list']]]);
            Signup::unsubscribe((array) Repo::find($s2), 'link', [$ids['list']]);
            self::eq('letzter Verteiler abbestellt → abgemeldet', Repo::find($s2)['status'] ?? '', 'unsubscribed');
            Repo::block($s2, 'Widerspruch per Telefon', 'Selbsttest');
            self::eq('Von Hand gesperrt: Status, Grund, Sperrliste, Vermerk', [Repo::find($s2)['status'] ?? '', Repo::find($s2)['bounce_reason'] ?? '', Repo::suppressed('x' . $email), str_contains((string) (Repo::find($s2)['note'] ?? ''), 'Widerspruch per Telefon')], ['bounced', 'manual', 'manual', true]);
            Signup::subscribe('x' . $email, '', [], '127.0.0.1');
            self::eq('Von Hand gesperrt: Neuanmeldung ändert nichts', [Repo::find($s2)['status'] ?? '', Repo::suppressed('x' . $email)], ['bounced', 'manual']);
            self::eq('Von Hand gesperrt: Bestätigungslink greift nicht', Signup::confirm(Newsletter::token('confirm', (array) Repo::find($s2))), 'invalid');
            // Gefälschte Beschwerde an das Bounce-Postfach (ohne Bezug): nur protokollieren, nicht sperren
            $victim = 'v' . $email;
            (new Processor(new Repo(), Newsletter::policy()))->apply(new Result(Result::COMPLAINT, $victim), time(), false);
            self::eq('Beschwerde ohne Bezug aus dem Postfach sperrt niemanden', Repo::suppressed($victim), null);
            app()->db->query('DELETE FROM newsletter_events WHERE email = ?', [$victim]);
            Repo::deleteSubscriber($ids['sub']);
            $ids['sub'] = 0;
            self::eq('Nach dem Löschen bleibt nur die Prüfsumme gesperrt', [Repo::byEmail($email), Repo::suppressed($email)], [null, 'hard']);
        } catch (\Throwable $e) {
            self::eq('Datenbank: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false, true);
        } finally {
            // Protokoll der Test-Empfänger (Abmeldungen, Sperren …) zuerst – manche Einträge tragen keine Adresse
            if ($subIds) app()->db->query('DELETE FROM newsletter_events WHERE subscriber_id IN (' . implode(',', array_map('intval', $subIds)) . ')');
            if ($ids['sub']) Repo::deleteSubscriber($ids['sub']);
            if ($ids['camp']) Repo::deleteCampaign($ids['camp']);
            if ($ids['list']) Repo::deleteList($ids['list']);
            if ($ids['list2']) Repo::deleteList($ids['list2']);
            // erst löschen (löschen trägt Sperrgründe in die Sperrliste ein), dann die Sperrliste räumen
            if ($x = Repo::byEmail('x' . $email)) Repo::deleteSubscriber((int) $x['id']);
            app()->db->query('DELETE FROM newsletter_suppressions WHERE email_hash IN (?, ?)', [Address::hash($email, $key), Address::hash('x' . $email, $key)]);
            app()->db->query('DELETE FROM newsletter_events WHERE email LIKE ? OR (subscriber_id = 0 AND diagnostic LIKE ?)', ['%nl-selftest-%', '%User unknown%']);
        }
    }

    /**
     * Altbestand: Einfügen mit Herkunft, Überspringen (gesperrt, bestätigt, abgemeldet, doppelt, ungültig, früher abgelehnt),
     * keine Ausgaben an Ausstehende, Versand über die Engine (Mails als Datei abgelegt), Ja/Nein/One-Click, Rückläufer,
     * höchstens eine Erinnerung, Frist → Löschen mit anonymen Zählern. Alle Testdaten werden entfernt.
     */
    private static function repermit(): void
    {
        $key = Newsletter::key();
        $tag = 'nl-selftest-rp-' . bin2hex(random_bytes(3));
        $mail = fn(string $n) => $tag . '-' . $n . '@example.org';
        $lists = [];
        $actions = [];
        $dumpDir = sys_get_temp_dir() . '/' . $tag;
        $prevDump = \Core\Mailer::$testDump;
        \Core\Mailer::$testDump = $dumpDir;
        $dumped = fn() => count(glob($dumpDir . '/*.eml') ?: []);
        $sub = fn(string $n) => Repo::byEmail($mail($n));
        try {
            $lists['rp'] = Repo::saveList(0, ['name' => 'Selbsttest Altbestand', 'public' => false, 'footer' => 'Verein Musterstadt, Musterweg 1']);
            $lists['other'] = Repo::saveList(0, ['name' => 'Selbsttest Andere', 'public' => false]);
            self::eq('Funktion je Verteiler standardmäßig aus', (int) (Repo::list($lists['rp'])['repermit'] ?? 1), 0);
            Repo::saveList($lists['rp'], ['name' => 'Selbsttest Altbestand', 'public' => false, 'footer' => 'Verein Musterstadt, Musterweg 1', '_editors' => 1, 'repermit' => 1]);
            Repo::saveList($lists['rp'], ['name' => 'Selbsttest Altbestand', 'public' => false, 'footer' => 'Verein Musterstadt, Musterweg 1']);
            self::eq('Verteiler-Formular schaltet ein, anderes Speichern behält es', (int) (Repo::list($lists['rp'])['repermit'] ?? 0), 1);

            // Ausgangslage: schon bestätigt, gesperrt, abgemeldet, aktiv in anderem Verteiler
            $s = Repo::upsert(['email' => $mail('aktiv'), 'status' => 'active', 'source' => 'admin']);
            Repo::setLists((int) $s['id'], [$lists['rp']]);
            Repo::suppressHash(Address::hash($mail('gesperrt'), $key), 'hard');
            Repo::upsert(['email' => $mail('ab'), 'status' => 'unsubscribed', 'source' => 'admin']);
            $o = Repo::upsert(['email' => $mail('andere'), 'status' => 'active', 'source' => 'form', 'consent' => 'Formular alt']);
            Repo::setLists((int) $o['id'], [$lists['other']]);

            $actions[] = $aid = Repermit::create($lists['rp'], 'consent');
            $rows = "E-Mail;Name\n" . implode("\n", [$mail('neu1') . ';Erika', strtoupper($mail('neu2')), $mail('neu3') . ";Max", $mail('neu4'), $mail('neu5'), $mail('neu1'),
                'keine-adresse', $mail('aktiv'), $mail('gesperrt'), $mail('ab'), $mail('andere') . ';Andrea']);
            $res = Repermit::import($aid, $rows);
            self::eq('Einfügen: übernommen und übersprungen (Kopfzeile erkannt)', [$res['added'], $res['skipped']['duplicate'], $res['skipped']['invalid'], $res['skipped']['confirmed'], $res['skipped']['blocked'], $res['skipped']['unsubscribed']],
                [6, 1, 1, 1, 1, 1]);
            self::eq('Neue Adressen: Status „Bestätigung ausstehend“, Herkunft Altbestand, in keinem Verteiler', [$sub('neu1')['status'] ?? '', $sub('neu1')['source'] ?? '', $sub('neu1')['name'] ?? '', Repo::listIds((int) ($sub('neu1')['id'] ?? 0))],
                ['reconfirm', 'legacy', 'Erika', []]);
            self::eq('Aktiv in anderem Verteiler: bleibt aktiv, Einwilligung unverändert', [$sub('andere')['status'] ?? '', $sub('andere')['consent'] ?? ''], ['active', 'Formular alt']);
            self::eq('Zweites Einfügen derselben Adressen: alle übersprungen', Repermit::import($aid, $mail('neu1') . "\n" . $mail('andere'))['added'], 0);

            // Keine regulären Ausgaben an Ausstehende
            self::eq('Ausgabe an den Verteiler: nur bestätigte Empfänger', Campaigns::audience([$lists['rp']]), 1);

            // Entwurf: Texte prüfen
            self::eq('Einleitung mit Link abgelehnt', array_keys(Repermit::saveDraft($aid, ['subject' => 'Weiter dabei?', 'intro' => 'Mehr unter https://example.org', 'deadline_days' => 30])), ['intro']);
            self::eq('Frist außerhalb 7–90 Tage abgelehnt', array_keys(Repermit::saveDraft($aid, ['deadline_days' => 3])), ['deadline_days']);
            self::eq('Entwurf gespeichert (Erinnerung von Hand)', Repermit::saveDraft($aid, ['subject' => 'Weiter dabei bei {list}?', 'intro' => '', 'deadline_days' => 30, 'reminder' => 1, 'reminder_days' => 0]), []);
            $a = (array) Repermit::find($aid);
            $e1 = (array) app()->db->fetch('SELECT * FROM newsletter_repermit_entries WHERE action_id = ? AND subscriber_id = ?', [$aid, (int) $sub('neu1')['id']]);
            $m = Message::repermit($a, (array) $sub('neu1'), $e1, Repo::list($lists['rp']));
            self::eq('Bitte: Betreff, Name, Knöpfe Ja/Nein, Fußzeile des Verteilers, kein Werbeinhalt', [$m['subject'], str_contains($m['text'], 'Hallo Erika'), str_contains($m['html'], e(Repermit::url($e1, 'ja'))),
                str_contains($m['html'], e(Repermit::url($e1, 'nein'))), str_contains($m['html'], 'Verein Musterstadt'), str_contains($m['html'], 'data-nl-i')],
                ['Weiter dabei bei Selbsttest Altbestand?', true, true, true, true, false]);
            self::eq('Bitte: One-Click auf die Seite der Bitte, Bezug für Rückläufer', [$m['headers']['List-Unsubscribe'], $m['headers']['List-Unsubscribe-Post'], Ref::find(Ref::HEADER . ': ' . $m['headers'][Ref::HEADER], $key), Ref::find('Message-ID: <' . $m['message_id'] . '>', $key)],
                ['<' . Repermit::url($e1) . '>', 'List-Unsubscribe=One-Click', ['campaign' => 0, 'subscriber' => (int) $sub('neu1')['id']], ['campaign' => 0, 'subscriber' => (int) $sub('neu1')['id']]]);

            // Herkunft „unbekannt“: Versand nur mit ausdrücklicher Bestätigung
            $actions[] = $uid = Repermit::create($lists['rp'], 'unknown');
            Repermit::import($uid, $mail('unbekannt'));
            self::eq('Herkunft unbekannt: ohne Bestätigung kein Versand', [Repermit::start($uid, false, 'Selbsttest') !== null, Repermit::find($uid)['status'] ?? ''], [true, 'draft']);
            self::eq('Herkunft unbekannt: mit Bestätigung, vermerkt', [Repermit::start($uid, true, 'Selbsttest'), str_starts_with((string) (Repermit::find($uid)['unknown_ack'] ?? ''), 'Selbsttest')], [null, true]);
            Repermit::finish($uid);
            self::eq('Beenden ohne Antwort: Adresse gelöscht', $sub('unbekannt'), null);

            // Versand
            self::eq('Start: Wortlaut mit SHA-256 festgehalten, Frist gesetzt', [Repermit::start($aid, false, 'Selbsttest'), strlen((string) Repermit::find($aid)['request_hash']), (string) Repermit::find($aid)['request_hash'] === hash('sha256', (string) Repermit::find($aid)['request_text']),
                abs(strtotime((string) Repermit::find($aid)['deadline_at']) - (time() + 30 * 86400)) < 60], [null, 64, true, true]);
            $before = $dumped();
            $run = Repermit::run(100);
            self::eq('Versand über die Engine: 6 Bitten, als Datei abgelegt', [$run['sent'], $dumped() - $before, Repermit::stats($aid)['sent']], [6, 6, 6]);
            self::eq('Zweiter Lauf versendet nichts doppelt', Repermit::run(100)['sent'], 0);

            // Antworten
            $tok = fn(string $n) => (array) Repermit::entry((int) app()->db->fetchValue('SELECT id FROM newsletter_repermit_entries WHERE action_id = ? AND subscriber_id = ?', [$aid, (int) ($sub($n)['id'] ?? 0)]));
            $t1 = Repermit::token($tok('neu1'));
            self::eq('Falsches Token', Repermit::fromToken(substr($t1, 0, -1) . (str_ends_with($t1, 'a') ? 'b' : 'a')), null);
            self::eq('Ja: aktiv, im Verteiler, Nachweis mit Herkunft und Hash', [Repermit::confirm((array) Repermit::fromToken($t1), '127.0.0.1'), $sub('neu1')['status'] ?? '', Repo::listIds((int) $sub('neu1')['id']),
                str_contains((string) $sub('neu1')['consent'], substr((string) Repermit::find($aid)['request_hash'], 0, 16)), str_contains((string) $sub('neu1')['consent'], lt('früher eingewilligt, ohne Nachweis')), (string) $sub('neu1')['ip_hash'] !== '', (bool) $sub('neu1')['confirmed_at']],
                ['ok', 'active', [$lists['rp']], true, true, true, true]);
            self::eq('Ja zweimal geklickt', Repermit::confirm($tok('neu1'), '127.0.0.1'), 'already');
            $andere = Repo::listIds((int) $sub('andere')['id']);
            self::eq('Ja (schon aktiv in anderem Verteiler): Verteiler dazu, alte Einwilligung bleibt', [Repermit::confirm($tok('andere'), '127.0.0.1'), count(Repo::listIds((int) $sub('andere')['id'])) - count($andere), $sub('andere')['consent'] ?? '', str_contains((string) $sub('andere')['note'], 'Altbestand')],
                ['ok', 1, 'Formular alt', true]);
            self::eq('Nein: abgemeldet, nicht im Verteiler', [Repermit::decline($tok('neu2')), $sub('neu2')['status'] ?? '', Repo::listIds((int) $sub('neu2')['id'])], ['ok', 'unsubscribed', []]);
            self::eq('One-Click im E-Mail-Programm = Nein', [Repermit::decline($tok('neu3'), 'oneclick'), $sub('neu3')['status'] ?? ''], ['ok', 'unsubscribed']);
            // Rückläufer (Hard Bounce mit Bezug) → unzustellbar
            $m4 = Message::repermit((array) Repermit::find($aid), (array) $sub('neu4'), $tok('neu4'), Repo::list($lists['rp']));
            (new Processor(new Repo(), Newsletter::policy()))->apply(Parser::parse(self::dsn('', $mail('neu4'), '5.1.1', 'smtp; 550 5.1.1 User unknown', 'X-Newsletter-Ref: ' . $m4['ref']), $key), time());
            Repermit::run(0);
            self::eq('Hard Bounce: gesperrt, als unzustellbar gezählt', [$sub('neu4')['status'] ?? '', Repermit::stats($aid)['bounced']], ['bounced', 1]);
            self::eq('Ausgabe an den Verteiler: jetzt 3 (zwei bestätigt)', Campaigns::audience([$lists['rp']]), 3);

            // Erinnerung höchstens einmal, nur an Offene
            $before = $dumped();
            self::eq('Erinnerung vorgemerkt', Repermit::remind($aid), null);
            self::eq('Erinnerung geht nur an Offene (1)', [Repermit::run(100)['sent'], $dumped() - $before], [1, 1]);
            self::eq('Zweite Erinnerung abgelehnt', Repermit::remind($aid) !== null, true);
            $mr = Message::repermit((array) Repermit::find($aid), (array) $sub('neu5'), $tok('neu5'), Repo::list($lists['rp']), true);
            self::eq('Erinnerung: eigener Betreff und eigene Message-ID', [str_starts_with($mr['subject'], lt('Erinnerung:')), $mr['message_id'] !== $m4['message_id'] && str_contains($mr['message_id'], 'e@')], [true, true]);

            // Früher abgelehnt: neuer Import überspringt
            $actions[] = $aid2 = Repermit::create($lists['rp'], 'consent');
            self::eq('Neuer Import: früher abgelehnt und schon bestätigt übersprungen', [Repermit::import($aid2, $mail('neu2') . "\n" . $mail('neu1'))['skipped']['declined'], Repermit::skipped((array) Repermit::find($aid2))['confirmed']], [1, 1]);
            Repermit::discard($aid2);
            self::eq('Entwurf verworfen', Repermit::find($aid2), null);

            // Frist abgelaufen → automatisch beendet, Unbestätigte gelöscht, Zähler bleiben
            app()->db->update('newsletter_repermits', ['deadline_at' => date('Y-m-d H:i:s', time() - 60)], 'id = :wid', ['wid' => $aid]);
            $openEntry = $tok('neu5');
            Repermit::run(100);
            $st = Repermit::stats($aid);
            self::eq('Frist: beendet, Unbestätigte und Unzustellbare gelöscht', [Repermit::find($aid)['status'] ?? '', $sub('neu5'), $sub('neu4')], ['done', null, null]);
            self::eq('Frist: anonyme Zähler bleiben', [$st['total'], $st['confirmed'], $st['declined'], $st['bounced'], $st['expired'], $st['open']], [6, 2, 2, 1, 1, 0]);
            self::eq('Frist: Einträge ohne Bezug zur Person', (int) app()->db->fetchValue('SELECT COUNT(*) FROM newsletter_repermit_entries WHERE action_id = ? AND state IN (\'expired\', \'bounced\') AND (subscriber_id > 0 OR email_hash <> \'\')', [$aid]), 0);
            self::eq('Frist: Gesperrte bleiben als Prüfsumme auf der Sperrliste', Repo::suppressed($mail('neu4')), 'hard');
            self::eq('Frist: Bestätigte und Abgemeldete bleiben', [$sub('neu1')['status'] ?? '', $sub('neu2')['status'] ?? ''], ['active', 'unsubscribed']);
            self::eq('Nach der Frist: Link ungültig', Repermit::confirm((array) Repermit::entry((int) $openEntry['id']), '127.0.0.1'), 'invalid');
        } catch (\Throwable $e) {
            self::eq('Altbestand: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false, true);
        } finally {
            \Core\Mailer::$testDump = $prevDump;
            foreach (glob($dumpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dumpDir);
            $subs = array_map('intval', array_column(app()->db->fetchAll('SELECT id FROM newsletter_subscribers WHERE email LIKE ?', [$tag . '-%']), 'id'));
            if ($subs) app()->db->query('DELETE FROM newsletter_events WHERE subscriber_id IN (' . implode(',', $subs) . ')');
            foreach ($subs as $sid) Repo::deleteSubscriber($sid);
            foreach ($actions as $aid) {
                app()->db->query('DELETE FROM newsletter_repermit_entries WHERE action_id = ?', [$aid]);
                app()->db->query('DELETE FROM newsletter_repermits WHERE id = ?', [$aid]);
            }
            foreach ($lists as $l) Repo::deleteList($l);
            foreach (['gesperrt', 'neu4', 'aktiv', 'neu1', 'neu2', 'neu3', 'neu5', 'andere', 'ab', 'unbekannt'] as $n) app()->db->query('DELETE FROM newsletter_suppressions WHERE email_hash = ?', [Address::hash($mail($n), $key)]);
            app()->db->query('DELETE FROM newsletter_events WHERE email LIKE ? OR (subscriber_id = 0 AND diagnostic LIKE ?)', [$tag . '-%', '%User unknown%']);
        }
    }

    /**
     * Impressum (Core\Mail\Imprint) in Ausgabe, Bestätigung, Altbestand-Bitte und -Erinnerung, Testmail und Webversion – genau einmal,
     * auch über den Mailer (Option 'imprint' => 'included'); ohne Impressum sind Ausgaben und Bitten gesperrt. Einstellungen werden
     * zurückgesetzt, Testdaten entfernt, Mails nur als Datei abgelegt.
     */
    private static function imprint(): void
    {
        $s = app()->settings;
        $keys = [\Core\Mail\Imprint::SETTING, 'imprint_page', 'impressum_seite'];
        $prev = [];
        foreach ($keys as $k) $prev[$k] = $s->get($k);
        $tag = 'nl-selftest-imp-' . bin2hex(random_bytes(3));
        $mark = 'Selbsttest-Impressum GmbH';
        $dumpDir = sys_get_temp_dir() . '/' . $tag;
        $prevDump = \Core\Mailer::$testDump;
        \Core\Mailer::$testDump = $dumpDir;
        $last = function () use ($dumpDir): string {
            $f = glob($dumpDir . '/*.eml') ?: [];
            sort($f);
            return $f ? quoted_printable_decode((string) file_get_contents((string) end($f))) : '';
        };
        $list = 0; $sid = 0; $camp = 0; $aid = 0;
        try {
            $s->set(\Core\Mail\Imprint::SETTING, $mark . "\nMusterweg 1, 12345 Musterstadt");
            \Core\Mail\Imprint::flush();
            $imp = \Core\Mail\Imprint::get();
            $list = Repo::saveList(0, ['name' => 'Selbsttest Impressum', 'public' => false, 'footer' => 'Verein Musterstadt, Musterweg 1']);
            $sub = Repo::upsert(['email' => $tag . '@example.org', 'name' => 'Erika', 'status' => 'pending', 'source' => 'admin']);
            $sid = (int) $sub['id'];
            $camp = Repo::saveCampaign(0, ['subject' => 'Impressum {name}', 'body' => '<p>Hallo {name}</p>', 'lists' => (string) $list]);
            $c = (array) Repo::campaign($camp);

            $m = Message::campaign($c, (array) Repo::find($sid));
            self::eq('Ausgabe: Impressum im HTML (einmal), Fußtext des Verteilers bleibt', [substr_count($m['html'], $mark), str_contains($m['html'], 'Verein Musterstadt')], [1, true]);
            self::eq('Ausgabe: Textfassung mit „-- “ und Impressum (einmal)', [str_contains($m['text'], "\n-- \n" . $imp['label']), substr_count($m['text'], $mark)], [true, 1]);
            self::eq('Ausgabe: Link zur Impressum-Seite', $imp['url'] === '' || str_contains($m['html'], e($imp['url'])), true);
            $res = Sender::send([$m]);
            $eml = $last();
            self::eq('Versand über den Mailer: Impressum genau einmal je Teil (imprint => included)', [$res[0]['ok'] ?? false, substr_count($eml, $mark)], [true, 2]);
            $t = Campaigns::test($c, [$tag . '-test@example.org']);
            self::eq('Testmail: Impressum genau einmal je Teil', [$t[0]['ok'] ?? false, substr_count($last(), $mark)], [true, 2]);
            self::eq('Webversion: Impressum', substr_count(Message::web($c), $mark), 1);

            Repo::update($sid, ['pending_lists' => (string) $list, 'consent_at' => Repo::now()]);
            $mc = Message::confirm((array) Repo::find($sid), Repo::list($list));
            self::eq('Bestätigung (Double-Opt-in): Impressum in HTML und Text', [substr_count($mc['html'], $mark), substr_count($mc['text'], $mark), str_contains($mc['text'], "\n-- \n")], [1, 1, true]);
            self::eq('Bestätigung über den Mailer: genau einmal je Teil', [Signup::sendConfirm(['pending_lists' => (string) $list] + (array) Repo::find($sid)), substr_count($last(), $mark)], [null, 2]);

            $a = ['id' => 0, 'subject' => '', 'intro' => '', 'deadline_days' => 30, 'origin' => 'consent'];
            $mr = Message::repermit($a, ['id' => 0, 'name' => 'Erika', 'email' => $tag . '@example.org'], ['id' => 0, 'secret' => ''], Repo::list($list), false, true);
            $me = Message::repermit($a, ['id' => 0, 'name' => 'Erika', 'email' => $tag . '@example.org'], ['id' => 0, 'secret' => ''], Repo::list($list), true, true);
            self::eq('Altbestand-Bitte und Erinnerung: Impressum in HTML und Text', [substr_count($mr['html'], $mark), substr_count($mr['text'], $mark), substr_count($me['html'], $mark), substr_count($me['text'], $mark)], [1, 1, 1, 1]);

            // Ohne Text und ohne Impressum-Seite: einzelne Mails gehen, Massenversand gesperrt
            $s->set(\Core\Mail\Imprint::SETTING, '');
            $s->set('imprint_page', 0);
            $s->set('impressum_seite', 0);
            \Core\Mail\Imprint::flush();
            self::eq('Ohne Impressum: Sperre gemeldet', \Core\Mail\Imprint::bulkError() !== null, true);
            self::eq('Ohne Impressum: Ausgabe lässt sich nicht starten', [Campaigns::schedule($camp) !== null, Repo::campaign($camp)['status'] ?? ''], [true, 'draft']);
            $aid = Repermit::create($list, 'consent');
            Repermit::import($aid, $tag . '-rp@example.org');
            self::eq('Ohne Impressum: Altbestand-Bitte lässt sich nicht starten', [Repermit::start($aid, false, 'Selbsttest') !== null, Repermit::find($aid)['status'] ?? ''], [true, 'draft']);
            app()->db->update('newsletter_campaigns', ['status' => 'scheduled', 'scheduled_at' => Repo::now()], 'id = :wid', ['wid' => $camp]);
            self::eq('Ohne Impressum: geplante Ausgabe wartet (Cron versendet nichts)', [Campaigns::run()['started'], Repo::campaign($camp)['status'] ?? ''], [0, 'scheduled']);
            app()->db->update('newsletter_campaigns', ['status' => 'draft', 'scheduled_at' => null], 'id = :wid', ['wid' => $camp]);
            self::eq('Ohne Impressum: Bestätigung (einzelne Mail) geht trotzdem', Signup::sendConfirm(['pending_lists' => (string) $list] + (array) Repo::find($sid)), null);
        } catch (\Throwable $e) {
            self::eq('Impressum: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false, true);
        } finally {
            foreach ($prev as $k => $v) $v === null ? $s->delete($k) : $s->set($k, $v);
            \Core\Mail\Imprint::flush();
            \Core\Mailer::$testDump = $prevDump;
            foreach (glob($dumpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dumpDir);
            if ($aid) {
                $rs = array_map('intval', array_column(app()->db->fetchAll('SELECT subscriber_id FROM newsletter_repermit_entries WHERE action_id = ?', [$aid]), 'subscriber_id'));
                app()->db->query('DELETE FROM newsletter_repermit_entries WHERE action_id = ?', [$aid]);
                app()->db->query('DELETE FROM newsletter_repermits WHERE id = ?', [$aid]);
                foreach ($rs as $r) if ($r) Repo::deleteSubscriber($r);
            }
            if ($camp) Repo::deleteCampaign($camp);
            if ($sid) { app()->db->query('DELETE FROM newsletter_events WHERE subscriber_id = ?', [$sid]); Repo::deleteSubscriber($sid); }
            if ($list) Repo::deleteList($list);
        }
    }

    /** Zustellbericht (RFC 3464) wie von Postfix */
    private static function dsn(string $to, string $rcpt, string $status, string $diag, string $origHeader = ''): string
    {
        $b = 'B' . md5($rcpt . $status);
        return "From: MAILER-DAEMON@mx.example.net (Mail Delivery System)\r\nTo: " . ($to ?: 'bounce@news.example.org') . "\r\nSubject: Undelivered Mail Returned to Sender\r\n"
            . "Auto-Submitted: auto-replied\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=\"$b\"\r\n\r\n"
            . "--$b\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nI'm sorry to have to inform you that your message could not be delivered.\r\n\r\n"
            . "--$b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example.net\r\nArrival-Date: Mon, 19 Oct 2026 10:00:00 +0200\r\n\r\n"
            . "Final-Recipient: rfc822; $rcpt\r\nOriginal-Recipient: rfc822;$rcpt\r\nAction: failed\r\nStatus: $status\r\nDiagnostic-Code: $diag\r\n\r\n"
            . "--$b\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: news@example.org\r\nTo: $rcpt\r\nSubject: Newsletter\r\n" . ($origHeader !== '' ? $origHeader . "\r\n" : '') . "\r\n--$b--\r\n";
    }

    /** Beschwerde (RFC 5965) */
    private static function arf(string $rcpt, string $ref): string
    {
        $b = 'ARF' . md5($rcpt);
        return "From: fbl@provider.example\r\nTo: abuse@example.org\r\nSubject: FW: Newsletter\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=feedback-report; boundary=\"$b\"\r\n\r\n"
            . "--$b\r\nContent-Type: text/plain\r\n\r\nThis is an email abuse report.\r\n\r\n--$b\r\nContent-Type: message/feedback-report\r\n\r\nFeedback-Type: abuse\r\nUser-Agent: FBL/1.0\r\nVersion: 1\r\nOriginal-Rcpt-To: <$rcpt>\r\n\r\n"
            . "--$b\r\nContent-Type: message/rfc822\r\n\r\nFrom: news@example.org\r\nTo: $rcpt\r\nX-Newsletter-Ref: $ref\r\nSubject: Newsletter\r\n\r\nHallo\r\n\r\n--$b--\r\n";
    }
}
