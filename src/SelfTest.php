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
        try {
            $ids['list'] = Repo::saveList(0, ['name' => 'Selbsttest', 'public' => false]);
            $s = Repo::upsert(['email' => strtoupper($email), 'name' => 'Erika', 'status' => 'pending', 'source' => 'admin']);
            $ids['sub'] = (int) $s['id'];
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
            Repo::update($s2 = (int) Repo::upsert(['email' => 'x' . $email, 'status' => 'active', 'source' => 'admin'])['id'], []);
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
            Repo::deleteSubscriber($ids['sub']);
            $ids['sub'] = 0;
            self::eq('Nach dem Löschen bleibt nur die Prüfsumme gesperrt', [Repo::byEmail($email), Repo::suppressed($email)], [null, 'hard']);
        } catch (\Throwable $e) {
            self::eq('Datenbank: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false, true);
        } finally {
            if ($ids['sub']) Repo::deleteSubscriber($ids['sub']);
            if ($ids['camp']) Repo::deleteCampaign($ids['camp']);
            if ($ids['list']) Repo::deleteList($ids['list']);
            if ($ids['list2']) Repo::deleteList($ids['list2']);
            app()->db->query('DELETE FROM newsletter_suppressions WHERE email_hash IN (?, ?)', [Address::hash($email, $key), Address::hash('x' . $email, $key)]);
            if ($x = Repo::byEmail('x' . $email)) Repo::deleteSubscriber((int) $x['id']);
            app()->db->query('DELETE FROM newsletter_events WHERE email LIKE ? OR (subscriber_id = 0 AND diagnostic LIKE ?)', ['%nl-selftest-%', '%User unknown%']);
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
