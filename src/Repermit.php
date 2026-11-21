<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Db\Table;
use Klxm\Newsletter\Engine\Address;
use Klxm\Newsletter\Engine\Bounce\Processor;
use Klxm\Newsletter\Engine\Throttle;

/**
 * Altbestand übernehmen und bestätigen lassen (Re-Permission) – je Verteiler, Standard aus (newsletter_lists.repermit).
 *
 * Ablauf einer Aktion (newsletter_repermits):
 *  draft    Adressen eingefügt (Herkunft Pflicht, „haben den Newsletter bisher erhalten“ Pflicht), Betreff/Einleitung/Frist einstellbar
 *  running  einmalige Bestätigungsbitte geht gedrosselt hinaus (Cron newsletter:send, gleiche Engine wie Ausgaben: Throttle,
 *           VERP/Bezug, Rückläufer); höchstens eine Erinnerung (von Hand oder nach N Tagen)
 *  done     Frist abgelaufen oder von Hand beendet: Unbestätigte werden gelöscht, nur anonyme Zähler bleiben
 *
 * Einträge (newsletter_repermit_entries) je Aktion und Empfänger: open → confirmed | declined | bounced | expired.
 * Neue Adressen bekommen den Empfänger-Status „reconfirm“ (Bestätigung ausstehend) und stehen in KEINEM Verteiler – so
 * erhalten sie keine regulären Ausgaben. Wer schon in anderen Verteilern aktiv ist, bleibt dort aktiv und kommt erst mit
 * „Ja“ in diesen Verteiler. Nachweis der Bestätigung: Eintrag (Zeitpunkt, IP-Kürzel) + Aktion (Herkunft, Verteiler,
 * Wortlaut der Bitte mit SHA-256) + Einwilligungsfeld des Empfängers.
 */
final class Repermit
{
    public const ORIGINS = ['consent', 'customer', 'unknown'];
    public const DEADLINE = 30;
    public const MAX_ROWS = 5000;
    /** Fehlversuche je Mail (vorübergehende Fehler des Kontos), danach failed */
    private const ATTEMPTS = 3;
    private const SKIPS = ['invalid', 'duplicate', 'blocked', 'confirmed', 'declined', 'unsubscribed', 'other'];

    /** @return array<string, callable(Table): mixed> */
    public static function tables(): array
    {
        return [
            'newsletter_repermits' => fn(Table $t) => $t->id()
                ->column('list_id', 'int', ['null' => false])
                ->column('origin', 'string', ['length' => 12, 'default' => 'unknown'])
                ->column('status', 'string', ['length' => 12, 'default' => 'draft'])
                ->column('subject', 'string', ['length' => 191, 'default' => ''])
                ->column('intro', 'text')
                ->column('deadline_days', 'int', ['default' => self::DEADLINE])
                ->column('reminder', 'bool', ['default' => false])
                ->column('reminder_days', 'int', ['default' => 0])
                ->column('request_text', 'text')
                ->column('request_hash', 'string', ['length' => 64, 'default' => ''])
                ->column('skipped', 'text')
                ->column('unknown_ack', 'string', ['length' => 191, 'default' => ''])
                ->column('created_by', 'int', ['default' => 0])
                ->column('created_at', 'datetime')->column('started_at', 'datetime')->column('deadline_at', 'datetime')
                ->column('reminded_at', 'datetime')->column('finished_at', 'datetime')
                ->index('status'),
            'newsletter_repermit_entries' => fn(Table $t) => $t->id()
                ->column('action_id', 'int', ['null' => false])
                ->column('subscriber_id', 'int', ['default' => 0])
                ->column('email_hash', 'string', ['length' => 64, 'default' => ''])
                ->column('domain', 'string', ['length' => 191, 'default' => ''])
                ->column('secret', 'string', ['length' => 32, 'null' => false])
                ->column('state', 'string', ['length' => 10, 'default' => 'open'])
                ->column('mail', 'string', ['length' => 8, 'default' => ''])
                ->column('remind', 'string', ['length' => 8, 'default' => ''])
                ->column('attempts', 'int', ['default' => 0])
                ->column('error', 'string', ['length' => 400, 'default' => ''])
                ->column('ip_hash', 'string', ['length' => 16, 'default' => ''])
                ->column('created_at', 'datetime')->column('sent_at', 'datetime')->column('reminded_at', 'datetime')->column('answered_at', 'datetime')
                ->index(['action_id', 'state'])->index('subscriber_id')->index('email_hash'),
        ];
    }

    private static function db(): \Core\Database
    {
        return app()->db;
    }

    // ------------------------------------------------------------------ Lesen

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_repermits WHERE id = ?', [$id]);
    }

    /** @return list<array> Aktionen der Verteiler $lists (neueste zuerst) */
    public static function all(array $lists): array
    {
        $in = implode(',', array_map('intval', $lists)) ?: '0';
        return self::db()->fetchAll("SELECT * FROM newsletter_repermits WHERE list_id IN ($in) ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, id DESC");
    }

    /** Verteiler mit eingeschalteter Funktion, die die angemeldete Person bedienen darf */
    public static function lists(): array
    {
        $mine = Access::lists();
        return array_values(array_filter(Repo::lists(), fn($l) => (int) ($l['repermit'] ?? 0) === 1 && in_array((int) $l['id'], $mine, true)));
    }

    /** Darf die Person diese Aktion sehen und bedienen? (Verteiler zugewiesen; Funktion beim Verteiler an – beendete bleiben lesbar) */
    public static function allowed(array $a): bool
    {
        return in_array((int) $a['list_id'], Access::lists(), true);
    }

    /** @return array{total: int, confirmed: int, open: int, declined: int, bounced: int, expired: int, sent: int, queued: int} */
    public static function stats(int $id): array
    {
        $out = ['total' => 0, 'confirmed' => 0, 'open' => 0, 'declined' => 0, 'bounced' => 0, 'expired' => 0, 'sent' => 0, 'queued' => 0];
        foreach (self::db()->fetchAll('SELECT state, mail, COUNT(*) n FROM newsletter_repermit_entries WHERE action_id = ? GROUP BY state, mail', [$id]) as $r) {
            $n = (int) $r['n'];
            $out['total'] += $n;
            // offen, aber endgültig nicht zustellbar (Fehler beim Einliefern) zählt als unzustellbar
            $state = $r['state'] === 'open' && $r['mail'] === 'failed' ? 'bounced' : (string) $r['state'];
            if (isset($out[$state])) $out[$state] += $n;
            if ($r['mail'] === 'sent') $out['sent'] += $n;
            if ($r['mail'] === 'queued') $out['queued'] += $n;
        }
        return $out;
    }

    /** @return array<string, int> Übersprungene Adressen beim Einfügen (alle Importe der Aktion) */
    public static function skipped(array $a): array
    {
        $s = json_decode((string) ($a['skipped'] ?? ''), true);
        $out = array_fill_keys(self::SKIPS, 0);
        foreach ((is_array($s) ? $s : []) as $k => $v) if (isset($out[$k])) $out[$k] = (int) $v;
        return $out;
    }

    /** @return list<int> Verteiler, für die ein Empfänger gerade um Bestätigung gebeten wird (Rechte der Redaktion) */
    public static function pendingLists(int $subscriber): array
    {
        return array_map('intval', array_column(self::db()->fetchAll('SELECT DISTINCT a.list_id FROM newsletter_repermit_entries e JOIN newsletter_repermits a ON a.id = e.action_id
            WHERE e.subscriber_id = ? AND e.state = \'open\'', [$subscriber]), 'list_id'));
    }

    public static function entry(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_repermit_entries WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ Texte und Links

    public static function originLabel(string $origin): string
    {
        return match ($origin) {
            'consent' => __('früher eingewilligt, ohne Nachweis'),
            'customer' => __('Bestandskunde (§ 7 Abs. 3 UWG)'),
            default => __('unbekannt'),
        };
    }

    /** Herkunft in der Sprache der Website (Nachweis beim Empfänger) */
    private static function originText(string $origin): string
    {
        return match ($origin) {
            'consent' => lt('früher eingewilligt, ohne Nachweis'),
            'customer' => lt('Bestandskunde (§ 7 Abs. 3 UWG)'),
            default => lt('unbekannt'),
        };
    }

    public static function defaultSubject(): string
    {
        return lt('Möchten Sie unseren Newsletter weiter erhalten?');
    }

    public static function defaultIntro(): string
    {
        return lt('wir haben den Versand unseres Newsletters „{list}“ auf ein neues System umgestellt. Sie haben den Newsletter bisher von uns erhalten.');
    }

    /**
     * Wortlaut der Bitte (feste neutrale Vorlage, nur Betreff und Einleitung anpassbar) als Absätze. Platzhalter {name}, {list},
     * {site}, {date} (Tag der Löschung). Die beiden Knöpfe stehen an der Stelle „{buttons}“.
     * @return array{subject: string, paragraphs: list<string>}
     */
    public static function wording(array $a, bool $reminder = false): array
    {
        $subject = trim((string) $a['subject']) ?: self::defaultSubject();
        $intro = trim((string) ($a['intro'] ?? '')) ?: self::defaultIntro();
        $p = [lt('Hallo {name},')];
        if ($reminder) $p[] = lt('kurze Erinnerung: Wir haben von Ihnen noch keine Antwort auf unsere Frage erhalten.');
        $p[] = $intro;
        $p[] = lt('Damit wir Ihnen den Newsletter auch künftig schicken dürfen, bitten wir Sie um eine kurze Bestätigung. Ein Klick genügt:');
        $p[] = '{buttons}';
        $p[] = lt('Ohne Ihre Bestätigung entfernen wir Ihre Adresse am {date} aus dem Verteiler und löschen Ihre Daten. Sie erhalten dann keine weiteren E-Mails von uns.');
        return ['subject' => ($reminder ? lt('Erinnerung:') . ' ' : '') . $subject, 'paragraphs' => $p];
    }

    /** Fassung der Bitte als Text (Nachweis): Betreff + Absätze mit Platzhaltern */
    public static function requestText(array $a): string
    {
        $w = self::wording($a);
        return $w['subject'] . "\n\n" . implode("\n\n", str_replace('{buttons}', '[' . lt('Ja, weiter erhalten') . '] [' . lt('Nein, abmelden') . ']', $w['paragraphs']));
    }

    public static function token(array $e): string
    {
        return (int) $e['id'] . '-' . substr(hash_hmac('sha256', 'nl-rp|' . (int) $e['id'] . '|' . (string) $e['secret'], Newsletter::key()), 0, 24);
    }

    public static function fromToken(string $token): ?array
    {
        if (!preg_match('~^(\d{1,10})-([a-f0-9]{24})$~', $token, $m)) return null;
        $e = self::entry((int) $m[1]);
        if (!$e || !hash_equals(self::token($e), $token)) return null;
        return $e;
    }

    /** Seite mit den Knöpfen; $answer ja|nein wählt vor (ein Link allein ändert nichts – Link-Scanner) */
    public static function url(array $e, string $answer = ''): string
    {
        return absolute_url(Newsletter::BASE . '/altbestand/' . self::token($e)) . ($answer !== '' ? '?antwort=' . $answer : '');
    }

    // ------------------------------------------------------------------ Einfügen

    /** Neue Aktion (Entwurf) für einen Verteiler */
    public static function create(int $list, string $origin): int
    {
        return self::db()->insert('newsletter_repermits', ['list_id' => $list, 'origin' => in_array($origin, self::ORIGINS, true) ? $origin : 'unknown', 'status' => 'draft',
            'subject' => '', 'intro' => '', 'deadline_days' => self::DEADLINE, 'reminder' => 0, 'reminder_days' => 0, 'request_text' => '', 'skipped' => '{}',
            'created_by' => (int) (app()->auth->user()['id'] ?? 0), 'created_at' => Repo::now()]);
    }

    /**
     * Adressen in einen Entwurf übernehmen („E-Mail;Name“ je Zeile, auch Komma oder Tab). Übersprungen: ungültig, doppelt,
     * gesperrt (Sperrliste, Rückläufer, Beschwerde), schon bestätigt (aktiv in diesem Verteiler), früher abgelehnt (dieser
     * Verteiler), abgemeldet, andere (Anmeldung mit Bestätigung läuft, schon in einer offenen Aktion dieses Verteilers).
     * @return array{added: int, skipped: array<string, int>}
     */
    public static function import(int $actionId, string $rows): array
    {
        $a = self::find($actionId);
        $out = ['added' => 0, 'skipped' => array_fill_keys(self::SKIPS, 0)];
        if (!$a || $a['status'] !== 'draft') return $out;
        $list = (int) $a['list_id'];
        $key = Newsletter::key();
        $seen = [];
        $first = true;
        foreach (array_slice(preg_split('~\R~', $rows) ?: [], 0, self::MAX_ROWS) as $line) {
            $parts = array_map('trim', preg_split('~[;,\t]~', $line, 2) ?: []);
            $email = Address::normalize(trim((string) ($parts[0] ?? ''), " \"'<>"));
            if ($email === '') continue;
            $head = $first && !str_contains($email, '@');   // Kopfzeile einer Tabelle („E-Mail;Name“)
            $first = false;
            if ($head) continue;
            if (!Address::valid($email)) { $out['skipped']['invalid']++; continue; }
            $hash = Address::hash($email, $key);
            if (isset($seen[$hash])) { $out['skipped']['duplicate']++; continue; }
            $seen[$hash] = true;
            if (Repo::suppressed($email)) { $out['skipped']['blocked']++; continue; }
            if (self::db()->fetchValue('SELECT 1 FROM newsletter_repermit_entries e JOIN newsletter_repermits a ON a.id = e.action_id WHERE a.list_id = ? AND e.email_hash = ? AND e.state = \'declined\' LIMIT 1', [$list, $hash])) {
                $out['skipped']['declined']++;
                continue;
            }
            $s = Repo::byEmail($email);
            if ($s) {
                $why = match (true) {
                    $s['status'] === 'active' && in_array($list, Repo::listIds((int) $s['id']), true) => 'confirmed',
                    in_array($s['status'], ['bounced', 'complained'], true) => 'blocked',
                    $s['status'] === 'unsubscribed' => 'unsubscribed',
                    $s['status'] === 'pending' => 'other',
                    (bool) self::db()->fetchValue('SELECT 1 FROM newsletter_repermit_entries e JOIN newsletter_repermits a ON a.id = e.action_id
                        WHERE a.list_id = ? AND e.subscriber_id = ? AND e.state = \'open\' LIMIT 1', [$list, (int) $s['id']]) => 'duplicate',
                    default => '',
                };
                if ($why !== '') { $out['skipped'][$why]++; continue; }
            } else {
                $s = Repo::upsert(['email' => $email, 'name' => (string) ($parts[1] ?? ''), 'status' => 'reconfirm', 'source' => 'legacy', 'lang' => (string) (app()->lang ?? '')]);
            }
            self::db()->insert('newsletter_repermit_entries', ['action_id' => $actionId, 'subscriber_id' => (int) $s['id'], 'email_hash' => $hash,
                'domain' => Address::domain($email), 'secret' => bin2hex(random_bytes(16)), 'state' => 'open', 'created_at' => Repo::now()]);
            $out['added']++;
        }
        $sum = self::skipped($a);
        foreach ($out['skipped'] as $k => $n) $sum[$k] += $n;
        self::db()->update('newsletter_repermits', ['skipped' => json_encode($sum)], 'id = :wid', ['wid' => $actionId]);
        return $out;
    }

    /** Betreff, Einleitung, Frist und Erinnerung eines Entwurfs. @return array<string, string> Fehler je Feld */
    public static function saveDraft(int $id, array $v): array
    {
        $a = self::find($id);
        if (!$a || $a['status'] !== 'draft') return ['_' => __('Nur Entwürfe lassen sich ändern.')];
        $subject = mb_substr(trim(strip_tags((string) ($v['subject'] ?? ''))), 0, 150);
        $intro = mb_substr(trim(strip_tags((string) ($v['intro'] ?? ''))), 0, 600);
        $days = (int) ($v['deadline_days'] ?? self::DEADLINE);
        $reminder = !empty($v['reminder']);
        $rdays = max(0, (int) ($v['reminder_days'] ?? 0));
        $err = [];
        // neutral bleiben: keine Links in Betreff und Einleitung (kein Werbeinhalt in der Bitte)
        foreach (['subject' => $subject, 'intro' => $intro] as $k => $t) if (preg_match('~https?://|www\.|\.(de|com|org|net|eu)/~i', $t)) $err[$k] = __('Bitte keine Links – die Bestätigungsbitte bleibt neutral.');
        if ($days < 7 || $days > 90) $err['deadline_days'] = __('Die Frist muss zwischen 7 und 90 Tagen liegen.');
        if ($reminder && $rdays > 0 && $rdays >= $days) $err['reminder_days'] = __('Die Erinnerung muss vor dem Ende der Frist kommen.');
        if ($err) return $err;
        self::db()->update('newsletter_repermits', ['subject' => $subject === self::defaultSubject() ? '' : $subject, 'intro' => $intro === self::defaultIntro() ? '' : $intro,
            'deadline_days' => $days, 'reminder' => $reminder ? 1 : 0, 'reminder_days' => $reminder ? $rdays : 0], 'id = :wid', ['wid' => $id]);
        return [];
    }

    /** Entwurf verwerfen: Einträge weg, dafür angelegte Empfänger ebenfalls */
    public static function discard(int $id): void
    {
        $a = self::find($id);
        if (!$a || $a['status'] !== 'draft') return;
        $subs = array_map('intval', array_column(self::db()->fetchAll('SELECT subscriber_id FROM newsletter_repermit_entries WHERE action_id = ?', [$id]), 'subscriber_id'));
        self::db()->query('DELETE FROM newsletter_repermit_entries WHERE action_id = ?', [$id]);
        foreach ($subs as $sid) self::dropIfUnused($sid);
        self::db()->query('DELETE FROM newsletter_repermits WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ Versand

    /**
     * Versand starten: Wortlaut festhalten (Text + SHA-256), Frist ab jetzt, alle offenen Einträge in die Warteschlange.
     * Herkunft „unbekannt“ nur mit ausdrücklicher Bestätigung ($unknownOk; Name und Zeit werden vermerkt).
     */
    public static function start(int $id, bool $unknownOk, string $by): ?string
    {
        if ($err = Newsletter::imprintBlocked()) return $err;   // ohne Impressum der Website kein Versand
        $a = self::find($id);
        if (!$a || $a['status'] !== 'draft') return __('Diese Aktion läuft bereits oder ist beendet.');
        if (!(int) self::db()->fetchValue('SELECT COUNT(*) FROM newsletter_repermit_entries WHERE action_id = ? AND state = \'open\'', [$id])) return __('Noch keine Adressen übernommen.');
        if ($a['origin'] === 'unknown' && !$unknownOk) return __('Bei unbekannter Herkunft bitte ausdrücklich bestätigen, dass Sie die Personen trotzdem anschreiben wollen.');
        $text = self::requestText($a);
        $now = Repo::now();
        self::db()->update('newsletter_repermits', ['status' => 'running', 'started_at' => $now, 'deadline_at' => date('Y-m-d H:i:s', time() + max(7, (int) $a['deadline_days']) * 86400),
            'request_text' => $text, 'request_hash' => hash('sha256', $text), 'unknown_ack' => $a['origin'] === 'unknown' ? mb_substr($by . ' · ' . $now, 0, 191) : ''], 'id = :wid', ['wid' => $id]);
        self::db()->query('UPDATE newsletter_repermit_entries SET mail = \'queued\' WHERE action_id = ? AND state = \'open\'', [$id]);
        return null;
    }

    /** Erinnerung (höchstens eine je Aktion) an alle, die noch nicht geantwortet haben und die erste Mail bekommen haben */
    public static function remind(int $id): ?string
    {
        if ($err = Newsletter::imprintBlocked()) return $err;
        $a = self::find($id);
        if (!$a || $a['status'] !== 'running') return __('Die Aktion läuft nicht.');
        if (!(int) $a['reminder']) return __('Für diese Aktion ist keine Erinnerung vorgesehen.');
        if ($a['reminded_at']) return __('Die Erinnerung wurde schon versendet – mehr als eine gibt es nicht.');
        self::db()->update('newsletter_repermits', ['reminded_at' => Repo::now()], 'id = :wid', ['wid' => $id]);
        self::db()->query('UPDATE newsletter_repermit_entries SET remind = \'queued\', attempts = 0 WHERE action_id = ? AND state = \'open\' AND mail = \'sent\'', [$id]);
        return null;
    }

    /**
     * Ein Lauf (Cron newsletter:send, nach den Ausgaben): Fristen prüfen, fällige Erinnerung vormerken, Zustand der Empfänger
     * übernehmen (Rückläufer, Beschwerde, inzwischen selbst angemeldet), einen gedrosselten Stapel versenden.
     * @return array{sent: int, failed: int, finished: int}
     */
    public static function run(int $budget, ?\Closure $log = null): array
    {
        $log ??= fn(string $m) => null;
        $stat = ['sent' => 0, 'failed' => 0, 'finished' => 0];
        $stat['finished'] = self::expireDue();
        $db = self::db();
        $actions = $db->fetchAll('SELECT * FROM newsletter_repermits WHERE status = \'running\' ORDER BY id');
        if (!$actions) return $stat;
        $throttle = new Throttle([], max(0, $budget));
        $processor = new Processor(new Repo(), Newsletter::policy());
        $until = microtime(true) + 45;
        // Ohne Impressum der Website: Fristen und Zustände weiter pflegen, aber keine Bitten versenden
        $blocked = Newsletter::imprintBlocked();
        if ($blocked !== null) $log($blocked);
        foreach ($actions as $a) {
            self::sync($a);
            if ((int) $a['reminder'] && (int) $a['reminder_days'] > 0 && !$a['reminded_at'] && strtotime((string) $a['started_at']) + (int) $a['reminder_days'] * 86400 <= time()) {
                self::remind((int) $a['id']);
                $log("Altbestand {$a['id']}: Erinnerung vorgemerkt");
            }
            if ($blocked !== null || $budget <= 0 || microtime(true) > $until) continue;
            $rows = $db->fetchAll('SELECT * FROM newsletter_repermit_entries WHERE action_id = ? AND state = \'open\' AND (mail = \'queued\' OR remind = \'queued\') ORDER BY id LIMIT ' . ($budget * 4), [(int) $a['id']]);
            if (!$rows) continue;
            $pick = array_flip($throttle->pick(array_map(fn($r) => ['id' => (int) $r['id'], 'domain' => (string) $r['domain']], $rows)));
            $rows = array_values(array_filter($rows, fn($r) => isset($pick[(int) $r['id']])));
            $list = Repo::list((int) $a['list_id']);
            foreach (array_chunk($rows, 50) as $chunk) {
                if (microtime(true) > $until) break;
                $msgs = [];
                $map = [];
                foreach ($chunk as $e) {
                    $col = $e['mail'] === 'queued' ? 'mail' : 'remind';
                    $sub = Repo::find((int) $e['subscriber_id']);
                    if (!$sub || in_array($sub['status'], ['bounced', 'complained', 'unsubscribed'], true) || Repo::suppressed((string) $sub['email'])) {
                        $db->update('newsletter_repermit_entries', [$col => 'skipped'], 'id = :wid', ['wid' => (int) $e['id']]);
                        continue;
                    }
                    $msgs[] = Message::repermit($a, $sub, $e, $list, $col === 'remind');
                    $map[] = [$e, $col];
                }
                foreach (Sender::send($msgs) as $i => $res) {
                    [$e, $col] = $map[$i];
                    if ($res['ok']) {
                        $db->update('newsletter_repermit_entries', [$col => 'sent', $col === 'mail' ? 'sent_at' : 'reminded_at' => Repo::now(), 'attempts' => 0, 'error' => ''], 'id = :wid', ['wid' => (int) $e['id']]);
                        $stat['sent']++;
                        continue;
                    }
                    $final = $res['result'] !== null || (int) $e['attempts'] + 1 >= self::ATTEMPTS;
                    $db->update('newsletter_repermit_entries', [$col => $final ? 'failed' : 'queued', 'attempts' => (int) $e['attempts'] + 1, 'error' => mb_substr((string) $res['error'], 0, 400)], 'id = :wid', ['wid' => (int) $e['id']]);
                    if ($res['result']) $processor->apply($res['result']->with(['ref' => ['campaign' => 0, 'subscriber' => (int) $e['subscriber_id']]]), time());
                    if ($final) $stat['failed']++;
                }
                $budget -= count($chunk);
            }
            self::sync($a);
        }
        if ($stat['sent'] || $stat['failed']) $log("Altbestand: {$stat['sent']} Bitten versendet, {$stat['failed']} fehlgeschlagen");
        return $stat;
    }

    /** Zustand der Empfänger in die offenen Einträge übernehmen */
    private static function sync(array $a): void
    {
        $id = (int) $a['id'];
        $db = self::db();
        // Rückläufer (Hard Bounce, Sperre) → unzustellbar; Beschwerde → abgelehnt; gelöschter Empfänger → abgelaufen
        $db->query('UPDATE newsletter_repermit_entries SET state = \'bounced\', answered_at = ? WHERE action_id = ? AND state = \'open\'
            AND subscriber_id IN (SELECT id FROM newsletter_subscribers WHERE status = \'bounced\')', [Repo::now(), $id]);
        $db->query('UPDATE newsletter_repermit_entries SET state = \'declined\', answered_at = ? WHERE action_id = ? AND state = \'open\'
            AND subscriber_id IN (SELECT id FROM newsletter_subscribers WHERE status = \'complained\')', [Repo::now(), $id]);
        $db->query('UPDATE newsletter_repermit_entries SET state = \'expired\', subscriber_id = 0, email_hash = \'\' WHERE action_id = ? AND state = \'open\'
            AND subscriber_id NOT IN (SELECT id FROM newsletter_subscribers)', [$id]);
        // abgemeldet (z. B. beim Versanddienst) → abgelehnt
        $db->query('UPDATE newsletter_repermit_entries SET state = \'declined\', answered_at = ? WHERE action_id = ? AND state = \'open\'
            AND subscriber_id IN (SELECT id FROM newsletter_subscribers WHERE status = \'unsubscribed\')', [Repo::now(), $id]);
        // inzwischen selbst über das Formular angemeldet (Double-Opt-in) → bestätigt
        $db->query('UPDATE newsletter_repermit_entries SET state = \'confirmed\', answered_at = ? WHERE action_id = ? AND state = \'open\'
            AND subscriber_id IN (SELECT s.id FROM newsletter_subscribers s JOIN newsletter_subscriber_lists sl ON sl.subscriber_id = s.id WHERE s.status = \'active\' AND sl.list_id = ?)',
            [Repo::now(), $id, (int) $a['list_id']]);
    }

    /** Aktionen mit abgelaufener Frist beenden. @return int Zahl der beendeten */
    public static function expireDue(): int
    {
        $n = 0;
        foreach (self::db()->fetchAll('SELECT id FROM newsletter_repermits WHERE status = \'running\' AND deadline_at <= ?', [Repo::now()]) as $r) {
            self::finish((int) $r['id']);
            $n++;
        }
        return $n;
    }

    /**
     * Beenden (Frist abgelaufen oder von Hand): unbestätigte Einträge → abgelaufen, die dafür angelegten Empfänger löschen
     * (personenbezogene Daten weg; Sperrgründe bleiben als Prüfsumme), Einträge anonymisieren – nur die Zähler bleiben.
     * Abgelehnte behalten die Prüfsumme der Adresse, damit ein späterer Import sie für diesen Verteiler überspringt.
     * @return int gelöschte Empfänger
     */
    public static function finish(int $id): int
    {
        $a = self::find($id);
        if (!$a || $a['status'] !== 'running') return 0;
        self::sync($a);
        $db = self::db();
        $subs = array_map('intval', array_column($db->fetchAll('SELECT subscriber_id FROM newsletter_repermit_entries WHERE action_id = ? AND state IN (\'open\', \'bounced\') AND subscriber_id > 0', [$id]), 'subscriber_id'));
        $db->query('UPDATE newsletter_repermit_entries SET state = \'expired\' WHERE action_id = ? AND state = \'open\'', [$id]);
        $db->query('UPDATE newsletter_repermit_entries SET subscriber_id = 0, email_hash = \'\', ip_hash = \'\', error = \'\', mail = CASE WHEN mail = \'queued\' THEN \'skipped\' ELSE mail END,
            remind = CASE WHEN remind = \'queued\' THEN \'skipped\' ELSE remind END WHERE action_id = ? AND state IN (\'expired\', \'bounced\')', [$id]);
        $db->update('newsletter_repermits', ['status' => 'done', 'finished_at' => Repo::now()], 'id = :wid', ['wid' => $id]);
        $deleted = 0;
        foreach ($subs as $sid) $deleted += self::dropIfUnused($sid) ? 1 : 0;
        return $deleted;
    }

    /**
     * Empfänger aus dem Altbestand löschen, wenn er nie bestätigt hat, in keinem Verteiler steht und in keiner anderen
     * Aktion mehr offen ist. Abgemeldete (Nein) bleiben wie bei jeder Abmeldung erhalten.
     */
    private static function dropIfUnused(int $sid): bool
    {
        $s = Repo::find($sid);
        if (!$s || $s['source'] !== 'legacy' || !in_array($s['status'], ['reconfirm', 'bounced', 'complained'], true) || $s['confirmed_at']) return false;
        if (Repo::listIds($sid)) return false;
        if (self::db()->fetchValue('SELECT 1 FROM newsletter_repermit_entries WHERE subscriber_id = ? AND state = \'open\' LIMIT 1', [$sid])) return false;
        Repo::deleteSubscriber($sid);
        return true;
    }

    // ------------------------------------------------------------------ Antworten (Website)

    /**
     * „Ja, weiter erhalten“: Empfänger aktiv, in den Verteiler, Nachweis speichern.
     * @return string ok | already | expired | invalid
     */
    public static function confirm(array $e, string $ip): string
    {
        if ($e['state'] === 'confirmed') return 'already';
        if ($e['state'] !== 'open') return 'invalid';
        $a = self::find((int) $e['action_id']);
        if (!$a || $a['status'] !== 'running') return 'expired';
        $sub = Repo::find((int) $e['subscriber_id']);
        $list = Repo::list((int) $a['list_id']);
        if (!$sub || !$list) return 'invalid';
        if (Repo::suppressed((string) $sub['email']) === 'manual') return 'invalid';   // von Hand gesperrt – nur die Verwaltung hebt auf
        $now = Repo::now();
        $ipHash = substr(hash_hmac('sha256', $ip, Newsletter::key()), 0, 16);
        $proof = lt('Altbestand bestätigt: „{list}“ – Herkunft: {origin} – Bitte #{id} vom {date} (SHA-256 {hash}): {text}', ['list' => $list['name'], 'origin' => self::originText((string) $a['origin']),
            'id' => (int) $a['id'], 'date' => fmt()->date((string) $a['started_at']), 'hash' => substr((string) $a['request_hash'], 0, 16), 'text' => (string) $a['request_text']]);
        $changes = ['bounce_reason' => '', 'soft_count' => 0, 'soft_first_at' => 0, 'soft_last_campaign' => 0, 'soft_campaigns' => 0, 'unsubscribed_at' => null];
        if ($sub['status'] !== 'active') {
            // neue Einwilligung: Nachweis in den Feldern wie beim Double-Opt-in
            $changes += ['status' => 'active', 'confirmed_at' => $now, 'consent' => $proof, 'consent_at' => $now, 'ip_hash' => $ipHash];
        } else {
            // schon aktiv (andere Verteiler): deren Einwilligung bleibt, der Nachweis für diesen Verteiler steht im Eintrag und in der Notiz
            $changes = ['note' => trim((string) $sub['note'] . "\n" . date('Y-m-d') . ' ' . $proof)];
        }
        Repo::update((int) $sub['id'], $changes);
        Repo::setLists((int) $sub['id'], [(int) $a['list_id']]);
        Repo::unsuppress((string) $sub['email']);
        self::db()->update('newsletter_repermit_entries', ['state' => 'confirmed', 'answered_at' => $now, 'ip_hash' => $ipHash, 'mail' => $e['mail'] === 'queued' ? 'skipped' : $e['mail'],
            'remind' => $e['remind'] === 'queued' ? 'skipped' : $e['remind']], 'id = :wid', ['wid' => (int) $e['id']]);
        self::db()->insert('newsletter_events', ['created_at' => $now, 'campaign_id' => 0, 'subscriber_id' => (int) $sub['id'], 'email' => (string) $sub['email'],
            'type' => 'reconfirm', 'diagnostic' => mb_substr('Altbestand bestätigt: ' . $list['name'] . ' (Bitte #' . (int) $a['id'] . ')', 0, 400), 'source' => 'site']);
        return 'ok';
    }

    /**
     * „Nein, abmelden“ (auch One-Click aus dem E-Mail-Programm): sofort raus aus diesem Verteiler; ein neuer Empfänger aus
     * dem Altbestand gilt als abgemeldet (wie jede Abmeldung). Wer schon „Ja“ gesagt hatte, wird vom Verteiler abgemeldet.
     * @return string ok | already | invalid
     */
    public static function decline(array $e, string $how = 'link'): string
    {
        if ($e['state'] === 'declined') return 'already';
        if (!in_array($e['state'], ['open', 'confirmed'], true)) return 'invalid';
        $a = self::find((int) $e['action_id']);
        $sub = Repo::find((int) $e['subscriber_id']);
        if (!$a || !$sub) return 'invalid';
        $list = Repo::list((int) $a['list_id']);
        $now = Repo::now();
        $listed = in_array((int) $a['list_id'], Repo::listIds((int) $sub['id']), true);
        if ($listed) {
            Signup::unsubscribe($sub, $how, [(int) $a['list_id']]);   // protokolliert selbst
        } elseif ($sub['status'] === 'reconfirm' && !Repo::listIds((int) $sub['id'])
            && !self::db()->fetchValue('SELECT 1 FROM newsletter_repermit_entries WHERE subscriber_id = ? AND state = \'open\' AND id <> ? LIMIT 1', [(int) $sub['id'], (int) $e['id']])) {
            (new Repo())->updateSubscriber((int) $sub['id'], ['status' => 'unsubscribed']);
        }
        self::db()->update('newsletter_repermit_entries', ['state' => 'declined', 'answered_at' => $now, 'mail' => $e['mail'] === 'queued' ? 'skipped' : $e['mail'],
            'remind' => $e['remind'] === 'queued' ? 'skipped' : $e['remind']], 'id = :wid', ['wid' => (int) $e['id']]);
        if (!$listed) self::db()->insert('newsletter_events', ['created_at' => $now, 'campaign_id' => 0, 'subscriber_id' => (int) $sub['id'], 'email' => (string) $sub['email'],
            'type' => 'unsubscribe', 'diagnostic' => mb_substr('Altbestand abgelehnt' . ($how === 'oneclick' ? ' (One-Click)' : '') . ': ' . ($list['name'] ?? '#' . (int) $a['list_id']), 0, 400), 'source' => 'site']);
        return 'ok';
    }
}
