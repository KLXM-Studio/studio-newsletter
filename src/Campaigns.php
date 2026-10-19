<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Bounce\Processor;
use Klxm\Newsletter\Engine\Throttle;

/**
 * Ausgaben versenden: planen → Warteschlange füllen (beim Start, damit spätere Anmeldungen dabei sind) → je Minute ein
 * gedrosselter Stapel (Cron-Aufgabe newsletter.send, Befehl newsletter:send) → fertig.
 *
 *  draft → scheduled (Termin) → sending → sent;   sending ⇄ paused;   draft/scheduled/paused → cancelled
 * Empfänger: aktiv, in einem der Verteiler, nicht auf der Sperrliste. Jede Person erhält eine Ausgabe höchstens einmal
 * (unique campaign_id + subscriber_id).
 */
final class Campaigns
{
    /** Höchstdauer eines Laufs (Sekunden) – Cron startet jede Minute */
    private const RUN_SECONDS = 50;
    /** Fehlversuche je Nachricht (vorübergehende Fehler des Kontos), danach failed */
    private const ATTEMPTS = 3;

    public static function schedule(int $id, ?string $at = null): void
    {
        $c = Repo::campaign($id);
        if (!$c || !in_array($c['status'], ['draft', 'scheduled', 'paused'], true)) return;
        if ($c['status'] === 'paused') { Repo::saveCampaign($id, ['status' => 'sending']); return; }
        Repo::saveCampaign($id, ['status' => 'scheduled', 'scheduled_at' => $at ?: Repo::now()]);
    }

    public static function pause(int $id): void
    {
        if (($c = Repo::campaign($id)) && in_array($c['status'], ['sending', 'scheduled'], true)) Repo::saveCampaign($id, ['status' => $c['status'] === 'scheduled' ? 'draft' : 'paused']);
    }

    public static function cancel(int $id): void
    {
        $c = Repo::campaign($id);
        if (!$c || !in_array($c['status'], ['draft', 'scheduled', 'paused'], true)) return;
        app()->db->query('UPDATE newsletter_queue SET status = \'skipped\', error = \'cancelled\' WHERE campaign_id = ? AND status = \'queued\'', [$id]);
        Repo::saveCampaign($id, ['status' => $c['status'] === 'paused' ? 'cancelled' : 'draft', 'scheduled_at' => null]);
    }

    /** Zahl der Empfänger, die eine Ausgabe jetzt bekäme */
    public static function audience(array $lists): int
    {
        $lists = array_values(array_filter(array_map('intval', $lists)));
        if (!$lists) return 0;
        $in = implode(',', $lists);
        return (int) app()->db->fetchValue("SELECT COUNT(DISTINCT s.id) FROM newsletter_subscribers s JOIN newsletter_subscriber_lists sl ON sl.subscriber_id = s.id
            WHERE sl.list_id IN ($in) AND s.status = 'active' AND s.email_hash NOT IN (SELECT email_hash FROM newsletter_suppressions)");
    }

    /** Warteschlange füllen (einmal je Empfänger) */
    public static function enqueue(array $c): int
    {
        $lists = Repo::campaignLists($c);
        if (!$lists) return 0;
        $in = implode(',', $lists);
        $db = app()->db;
        $db->query("INSERT OR IGNORE INTO newsletter_queue (campaign_id, subscriber_id, domain, status, attempts)
            SELECT DISTINCT ?, s.id, lower(substr(s.email, instr(s.email, '@') + 1)), 'queued', 0 FROM newsletter_subscribers s
            JOIN newsletter_subscriber_lists sl ON sl.subscriber_id = s.id
            WHERE sl.list_id IN ($in) AND s.status = 'active' AND s.email_hash NOT IN (SELECT email_hash FROM newsletter_suppressions)", [(int) $c['id']]);
        $total = (int) $db->fetchValue('SELECT COUNT(*) FROM newsletter_queue WHERE campaign_id = ?', [(int) $c['id']]);
        Repo::saveCampaign((int) $c['id'], ['total' => $total]);
        return $total;
    }

    /**
     * Ein Lauf (Cron): fällige Ausgaben starten, Stapel versenden, fertige abschließen.
     * @return array{started: int, sent: int, failed: int, done: int}
     */
    public static function run(?\Closure $log = null): array
    {
        $log ??= fn(string $m) => null;
        $stat = ['started' => 0, 'sent' => 0, 'failed' => 0, 'done' => 0];
        $db = app()->db;
        foreach ($db->fetchAll('SELECT * FROM newsletter_campaigns WHERE status = \'scheduled\' AND scheduled_at <= ?', [Repo::now()]) as $c) {
            $n = self::enqueue($c);
            Repo::saveCampaign((int) $c['id'], ['status' => 'sending', 'started_at' => Repo::now()]);
            $log("Ausgabe {$c['id']} gestartet: $n Empfänger");
            $stat['started']++;
        }
        $until = microtime(true) + self::RUN_SECONDS;
        $budget = Newsletter::batch();
        $throttle = new Throttle([], $budget, self::slowGroups());
        $processor = new Processor(new Repo(), Newsletter::policy());
        foreach ($db->fetchAll('SELECT * FROM newsletter_campaigns WHERE status = \'sending\' ORDER BY id') as $c) {
            if ($budget <= 0 || microtime(true) > $until) break;
            $rows = $db->fetchAll('SELECT id, subscriber_id, domain, attempts FROM newsletter_queue WHERE campaign_id = ? AND status = \'queued\' ORDER BY id LIMIT ' . ($budget * 4), [(int) $c['id']]);
            if (!$rows) { self::finish($c); $stat['done']++; continue; }
            $pick = array_flip($throttle->pick(array_map(fn($r) => ['id' => (int) $r['id'], 'domain' => (string) $r['domain']], $rows)));
            $rows = array_values(array_filter($rows, fn($r) => isset($pick[(int) $r['id']])));
            foreach (array_chunk($rows, 50) as $chunk) {
                if (microtime(true) > $until) break;
                $msgs = [];
                $map = [];
                foreach ($chunk as $q) {
                    $sub = Repo::find((int) $q['subscriber_id']);
                    if (!$sub || $sub['status'] !== 'active' || Repo::suppressed((string) $sub['email'])) {
                        $db->update('newsletter_queue', ['status' => 'skipped', 'error' => 'inactive'], 'id = :wid', ['wid' => (int) $q['id']]);
                        continue;
                    }
                    $msgs[] = Message::campaign($c, $sub);
                    $map[] = $q;
                }
                foreach (Sender::send($msgs) as $i => $res) {
                    $q = $map[$i];
                    if ($res['ok']) {
                        $db->update('newsletter_queue', ['status' => 'sent', 'sent_at' => Repo::now(), 'provider_id' => mb_substr((string) $res['id'], 0, 64), 'attempts' => (int) $q['attempts'] + 1], 'id = :wid', ['wid' => (int) $q['id']]);
                        $stat['sent']++;
                        continue;
                    }
                    $final = $res['result'] !== null || (int) $q['attempts'] + 1 >= self::ATTEMPTS;
                    $db->update('newsletter_queue', ['status' => $final ? 'failed' : 'queued', 'attempts' => (int) $q['attempts'] + 1, 'error' => mb_substr((string) $res['error'], 0, 400)], 'id = :wid', ['wid' => (int) $q['id']]);
                    if ($res['result']) $processor->apply($res['result']->with(['ref' => ['campaign' => (int) $c['id'], 'subscriber' => (int) $q['subscriber_id']]]), time());
                    if ($final) $stat['failed']++;
                }
                $budget -= count($chunk);
            }
            self::recount($c);
            if (!(int) $db->fetchValue('SELECT COUNT(*) FROM newsletter_queue WHERE campaign_id = ? AND status = \'queued\'', [(int) $c['id']])) { self::finish($c); $stat['done']++; }
        }
        if ($stat['sent'] || $stat['failed']) $log("Versendet: {$stat['sent']}, fehlgeschlagen: {$stat['failed']}");
        return $stat;
    }

    private static function recount(array $c): void
    {
        $r = app()->db->fetch('SELECT SUM(status = \'sent\') sent, SUM(status = \'failed\') failed FROM newsletter_queue WHERE campaign_id = ?', [(int) $c['id']]) ?? [];
        app()->db->update('newsletter_campaigns', ['sent' => (int) ($r['sent'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0)], 'id = :wid', ['wid' => (int) $c['id']]);
    }

    private static function finish(array $c): void
    {
        self::recount($c);
        Repo::saveCampaign((int) $c['id'], ['status' => 'sent', 'finished_at' => Repo::now()]);
    }

    /** Anbieter mit auffällig vielen Soft Bounces/Blockaden in den letzten 15 Minuten → halbe Menge */
    private static function slowGroups(): array
    {
        $rows = app()->db->fetchAll('SELECT email FROM newsletter_events WHERE created_at >= ? AND type IN (\'soft\', \'block\')', [date('Y-m-d H:i:s', time() - 900)]);
        $n = [];
        foreach ($rows as $r) {
            $g = Throttle::group(\Klxm\Newsletter\Engine\Address::domain((string) $r['email']));
            if ($g !== '') $n[$g] = ($n[$g] ?? 0) + 1;
        }
        return array_keys(array_filter($n, fn($x) => $x >= 5));
    }

    /** Testversand an Adressen (ohne Empfängerdatensatz, Bezug Empfänger 0) */
    public static function test(array $c, array $emails): array
    {
        $msgs = [];
        foreach ($emails as $e) $msgs[] = Message::campaign($c, ['id' => 0, 'email' => $e, 'name' => (string) (app()->auth->user()['name'] ?? '')], true);
        return Sender::send($msgs);
    }

    /** Kennzahlen einer Ausgabe mit Quoten (in Prozent, bezogen auf versendet) */
    public static function rates(array $c): array
    {
        $sent = max(1, (int) $c['sent']);
        return ['bounce' => round(((int) $c['hard'] + (int) $c['soft']) * 100 / $sent, 1), 'hard' => round((int) $c['hard'] * 100 / $sent, 1),
            'complaint' => round((int) $c['complaint'] * 100 / $sent, 2), 'unsubscribe' => round((int) $c['unsubscribe'] * 100 / $sent, 1)];
    }
}
