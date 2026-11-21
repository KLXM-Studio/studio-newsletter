<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Db\Table;
use Klxm\Newsletter\Engine\Address;
use Klxm\Newsletter\Engine\Bounce\Result;
use Klxm\Newsletter\Engine\Store;

/**
 * Datenbank der Erweiterung (Datenbank der Website, Präfix newsletter_):
 *
 *  newsletter_lists              Verteiler (Name, Beschreibung, auf der Website wählbar)
 *  newsletter_subscribers        Empfänger: Adresse + Prüfsumme, Status pending|active|unsubscribed|bounced|complained,
 *                                Soft-Bounce-Zähler, Einwilligung (Text, Zeit, IP nur als HMAC-Kürzel), secret für signierte Links
 *  newsletter_subscriber_lists   Zuordnung Empfänger ↔ Verteiler
 *  newsletter_campaigns          Ausgaben: Betreff, Vorschautext, Inhalt, Verteiler, Status draft|scheduled|sending|paused|sent|cancelled, Zähler
 *  newsletter_queue              Warteschlange je Ausgabe und Empfänger (queued|sent|failed|skipped), Domain für die Drosselung
 *  newsletter_events             Rückläufer und Ereignisse (Protokoll, Aufbewahrung einstellbar)
 *  newsletter_suppressions       Sperrliste – nur HMAC der Adresse, bleibt auch nach dem Löschen eines Empfängers
 *  newsletter_repermits(_entries) Altbestand bestätigen lassen (Repermit)
 *
 * Zugleich der Speicher der Engine (Engine\Store) für Rückläufer.
 */
final class Repo implements Store
{
    /** reconfirm = Altbestand, Bestätigung ausstehend (Repermit) – in keinem Verteiler, erhält keine Ausgaben */
    public const STATUSES = ['pending', 'active', 'unsubscribed', 'bounced', 'complained', 'reconfirm'];
    public const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'paused', 'sent', 'cancelled'];
    public const STATS = ['hard', 'soft', 'block', 'complaint', 'unsubscribe'];

    /** @return array<string, callable(Table): mixed> */
    public static function tables(): array
    {
        return [
            'newsletter_lists' => fn(Table $t) => $t->id()
                ->column('name', 'string', ['length' => 120, 'null' => false])
                ->column('description', 'string', ['length' => 191, 'default' => ''])
                ->column('public', 'bool', ['default' => true])
                ->column('account', 'string', ['length' => 64, 'default' => ''])
                ->column('footer', 'text')
                ->column('color', 'string', ['length' => 7, 'default' => ''])
                ->column('design_id', 'int', ['default' => 0])
                ->column('editors', 'text')
                ->column('repermit', 'bool', ['default' => false])
                ->column('sort', 'int', ['default' => 0])
                ->column('created_at', 'datetime'),
            'newsletter_subscribers' => fn(Table $t) => $t->id()
                ->column('email', 'string', ['length' => 254, 'null' => false])
                ->column('email_hash', 'string', ['length' => 64, 'null' => false])
                ->column('name', 'string', ['length' => 120, 'default' => ''])
                ->column('lang', 'string', ['length' => 10, 'default' => ''])
                ->column('status', 'string', ['length' => 12, 'default' => 'pending'])
                ->column('bounce_reason', 'string', ['length' => 12, 'default' => ''])
                ->column('soft_count', 'int', ['default' => 0])
                ->column('soft_first_at', 'int', ['default' => 0])
                ->column('soft_last_campaign', 'int', ['default' => 0])
                ->column('soft_campaigns', 'int', ['default' => 0])
                ->column('secret', 'string', ['length' => 32, 'null' => false])
                ->column('consent', 'text')
                ->column('consent_at', 'datetime')
                ->column('ip_hash', 'string', ['length' => 16, 'default' => ''])
                ->column('source', 'string', ['length' => 12, 'default' => 'form'])
                ->column('pending_lists', 'string', ['length' => 191, 'default' => ''])
                ->column('note', 'text')
                ->column('created_at', 'datetime')->column('confirmed_at', 'datetime')
                ->column('unsubscribed_at', 'datetime')->column('updated_at', 'datetime')->column('last_event_at', 'datetime')
                ->unique('email_hash')->index('status'),
            'newsletter_subscriber_lists' => fn(Table $t) => $t->id()
                ->column('subscriber_id', 'int', ['null' => false])->column('list_id', 'int', ['null' => false])
                ->column('created_at', 'datetime')
                ->unique(['subscriber_id', 'list_id'])->index('list_id'),
            'newsletter_campaigns' => fn(Table $t) => $t->id()
                ->column('subject', 'string', ['length' => 191, 'null' => false])
                ->column('preheader', 'string', ['length' => 191, 'default' => ''])
                ->column('body', 'longtext')
                ->column('blocks', 'longtext')
                ->column('design_id', 'int', ['default' => 0])
                ->column('image', 'int', ['default' => 0])
                ->column('button_label', 'string', ['length' => 80, 'default' => ''])
                ->column('button_link', 'string', ['length' => 500, 'default' => ''])
                ->column('lists', 'string', ['length' => 191, 'default' => ''])
                ->column('status', 'string', ['length' => 12, 'default' => 'draft'])
                ->column('scheduled_at', 'datetime')->column('started_at', 'datetime')->column('finished_at', 'datetime')
                ->column('created_by', 'int', ['default' => 0])
                ->column('created_at', 'datetime')->column('updated_at', 'datetime')
                ->column('total', 'int', ['default' => 0])->column('sent', 'int', ['default' => 0])->column('failed', 'int', ['default' => 0])
                ->column('hard', 'int', ['default' => 0])->column('soft', 'int', ['default' => 0])->column('block', 'int', ['default' => 0])
                ->column('complaint', 'int', ['default' => 0])->column('unsubscribe', 'int', ['default' => 0])
                ->index('status'),
            'newsletter_queue' => fn(Table $t) => $t->id()
                ->column('campaign_id', 'int', ['null' => false])->column('subscriber_id', 'int', ['null' => false])
                ->column('domain', 'string', ['length' => 191, 'default' => ''])
                ->column('status', 'string', ['length' => 10, 'default' => 'queued'])
                ->column('attempts', 'int', ['default' => 0])
                ->column('provider_id', 'string', ['length' => 64, 'default' => ''])
                ->column('error', 'string', ['length' => 400, 'default' => ''])
                ->column('sent_at', 'datetime')
                ->unique(['campaign_id', 'subscriber_id'])->index(['campaign_id', 'status']),
            'newsletter_events' => fn(Table $t) => $t->id()
                ->column('created_at', 'datetime')
                ->column('campaign_id', 'int', ['default' => 0])->column('subscriber_id', 'int', ['default' => 0])
                ->column('email', 'string', ['length' => 254, 'default' => ''])
                ->column('type', 'string', ['length' => 12, 'null' => false])
                ->column('status', 'string', ['length' => 12, 'default' => ''])
                ->column('smtp', 'int', ['default' => 0])
                ->column('diagnostic', 'string', ['length' => 400, 'default' => ''])
                ->column('source', 'string', ['length' => 12, 'default' => ''])
                ->column('provider_id', 'string', ['length' => 64, 'default' => ''])
                ->index('subscriber_id')->index('campaign_id')->index('created_at'),
            ...Design::table(),
            ...Templates::table(),
            ...Repermit::tables(),
            'newsletter_suppressions' => fn(Table $t) => $t->id()
                ->column('email_hash', 'string', ['length' => 64, 'null' => false])
                ->column('reason', 'string', ['length' => 12, 'null' => false])
                ->column('created_at', 'datetime')
                ->unique('email_hash'),
        ];
    }

    private static function db(): \Core\Database
    {
        return app()->db;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ Verteiler

    /** @return list<array> */
    public static function lists(bool $publicOnly = false): array
    {
        return self::db()->fetchAll('SELECT l.*, (SELECT COUNT(*) FROM newsletter_subscriber_lists sl JOIN newsletter_subscribers s ON s.id = sl.subscriber_id
            WHERE sl.list_id = l.id AND s.status = \'active\') AS active FROM newsletter_lists l' . ($publicOnly ? ' WHERE l.public = 1' : '') . ' ORDER BY l.sort, l.name');
    }

    public static function list(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_lists WHERE id = ?', [$id]);
    }

    public static function saveList(int $id, array $v): int
    {
        $color = (string) ($v['color'] ?? '');
        $acc = (string) ($v['account'] ?? '');
        $data = ['name' => mb_substr(trim((string) $v['name']), 0, 120), 'description' => mb_substr(trim((string) ($v['description'] ?? '')), 0, 191), 'public' => !empty($v['public']) ? 1 : 0,
            'account' => $acc !== '' && \Core\Mail\Accounts::find($acc) ? $acc : '', 'footer' => mb_substr(trim((string) ($v['footer'] ?? '')), 0, 600),
            'color' => preg_match('~^#[0-9a-f]{6}$~i', $color) && !empty($v['own_color']) ? strtolower($color) : '',
            'design_id' => array_key_exists('design_id', $v) ? max(0, (int) $v['design_id']) : (int) (self::list($id)['design_id'] ?? 0),
            'editors' => isset($v['_editors'])   // nur das Verteiler-Formular setzt die Zuweisung, andere Aufrufe behalten sie
                ? implode(',', array_values(array_intersect(array_map('strval', (array) ($v['editors'] ?? [])), array_keys(Access::choices()))))
                : (string) (self::list($id)['editors'] ?? ''),
            // Altbestand bestätigen lassen: nur das Verteiler-Formular schaltet um (Standard aus)
            'repermit' => isset($v['_editors']) ? (!empty($v['repermit']) ? 1 : 0) : (int) (self::list($id)['repermit'] ?? 0)];
        if ($id > 0) { self::db()->update('newsletter_lists', $data, 'id = :wid', ['wid' => $id]); return $id; }
        $data['sort'] = (int) self::db()->fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM newsletter_lists');
        return self::db()->insert('newsletter_lists', $data + ['created_at' => self::now()]);
    }

    public static function deleteList(int $id): void
    {
        self::db()->query('DELETE FROM newsletter_subscriber_lists WHERE list_id = ?', [$id]);
        self::db()->query('DELETE FROM newsletter_lists WHERE id = ?', [$id]);
    }

    /** Erster Verteiler – wird angelegt, wenn es noch keinen gibt */
    public static function defaultList(): int
    {
        $id = (int) self::db()->fetchValue('SELECT id FROM newsletter_lists ORDER BY sort, id LIMIT 1');
        return $id ?: self::saveList(0, ['name' => lt('Newsletter'), 'public' => true]);
    }

    // ------------------------------------------------------------------ Empfänger

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_subscribers WHERE id = ?', [$id]);
    }

    public static function byEmail(string $email): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_subscribers WHERE email_hash = ?', [Address::hash($email, Newsletter::key())]);
    }

    /** @return list<int> */
    public static function listIds(int $subscriber): array
    {
        return array_map('intval', array_column(self::db()->fetchAll('SELECT list_id FROM newsletter_subscriber_lists WHERE subscriber_id = ?', [$subscriber]), 'list_id'));
    }

    /** Neu anlegen oder vorhandenen zurückgeben; Felder: email, name, lang, source, consent, ip_hash, status */
    public static function upsert(array $v): array
    {
        $email = Address::normalize((string) $v['email']);
        if ($s = self::byEmail($email)) return $s;
        $id = self::db()->insert('newsletter_subscribers', [
            'email' => $email, 'email_hash' => Address::hash($email, Newsletter::key()),
            'name' => mb_substr(trim((string) ($v['name'] ?? '')), 0, 120), 'lang' => (string) ($v['lang'] ?? ''),
            'status' => in_array($v['status'] ?? '', self::STATUSES, true) ? $v['status'] : 'pending',
            'secret' => bin2hex(random_bytes(16)), 'consent' => (string) ($v['consent'] ?? ''), 'consent_at' => ($v['consent'] ?? '') !== '' ? self::now() : null,
            'ip_hash' => (string) ($v['ip_hash'] ?? ''), 'source' => (string) ($v['source'] ?? 'form'),
            'created_at' => self::now(), 'updated_at' => self::now(),
            'confirmed_at' => ($v['status'] ?? '') === 'active' ? self::now() : null,
        ]);
        return (array) self::find($id);
    }

    public static function setLists(int $subscriber, array $lists, bool $replace = false): void
    {
        if ($replace) self::db()->query('DELETE FROM newsletter_subscriber_lists WHERE subscriber_id = ?', [$subscriber]);
        foreach (array_unique(array_map('intval', $lists)) as $l) {
            if ($l > 0) self::db()->query('INSERT OR IGNORE INTO newsletter_subscriber_lists (subscriber_id, list_id, created_at) VALUES (?, ?, ?)', [$subscriber, $l, self::now()]);
        }
    }

    public static function update(int $id, array $changes): void
    {
        if (!$changes) return;
        self::db()->update('newsletter_subscribers', $changes + ['updated_at' => self::now()], 'id = :wid', ['wid' => $id]);
    }

    /** Löschen (DSGVO): Daten weg; Sperrgründe bleiben als Prüfsumme in der Sperrliste */
    public static function deleteSubscriber(int $id): void
    {
        $s = self::find($id);
        if (!$s) return;
        if (in_array($s['status'], ['bounced', 'complained'], true)) self::suppressHash((string) $s['email_hash'], $s['status'] === 'complained' ? 'complaint' : ((string) $s['bounce_reason'] ?: 'hard'));
        self::db()->query('DELETE FROM newsletter_subscriber_lists WHERE subscriber_id = ?', [$id]);
        self::db()->query('DELETE FROM newsletter_queue WHERE subscriber_id = ? AND status = \'queued\'', [$id]);
        self::db()->query('UPDATE newsletter_events SET email = \'\', subscriber_id = 0 WHERE subscriber_id = ?', [$id]);
        // Altbestand: offene Bitten enden, Zähler bleiben (abgelehnte behalten die Prüfsumme für den Verteiler)
        self::db()->query('UPDATE newsletter_repermit_entries SET state = CASE WHEN state = \'open\' THEN \'expired\' ELSE state END, subscriber_id = 0,
            email_hash = CASE WHEN state = \'declined\' THEN email_hash ELSE \'\' END, ip_hash = \'\' WHERE subscriber_id = ?', [$id]);
        self::db()->query('DELETE FROM newsletter_subscribers WHERE id = ?', [$id]);
    }

    /** @return array{rows: list<array>, total: int} */
    public static function subscribers(array $f, int $page = 1, int $per = 50): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (($f['status'] ?? '') !== '' && in_array($f['status'], self::STATUSES, true)) { $where[] = 's.status = :st'; $p['st'] = $f['status']; }
        if ((int) ($f['list'] ?? 0) > 0) { $where[] = 's.id IN (SELECT subscriber_id FROM newsletter_subscriber_lists WHERE list_id = :li)'; $p['li'] = (int) $f['list']; }
        if (isset($f['only'])) {   // Redaktion: nur Empfänger dieser Verteiler (auch unbestätigte, die dort angemeldet werden wollen)
            $in = implode(',', array_map('intval', (array) $f['only'])) ?: '0';
            $or = implode(' OR ', array_map(fn($i) => "(',' || s.pending_lists || ',') LIKE '%," . (int) $i . ",%'", (array) $f['only'])) ?: '0';
            $where[] = "(s.id IN (SELECT subscriber_id FROM newsletter_subscriber_lists WHERE list_id IN ($in)) OR $or"
                . " OR s.id IN (SELECT e.subscriber_id FROM newsletter_repermit_entries e JOIN newsletter_repermits a ON a.id = e.action_id WHERE e.state = 'open' AND a.list_id IN ($in)))";
        }
        if (trim((string) ($f['q'] ?? '')) !== '') { $where[] = '(s.email LIKE :q OR s.name LIKE :q)'; $p['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $f['q'])) . '%'; }
        $w = implode(' AND ', $where);
        $total = (int) self::db()->fetchValue("SELECT COUNT(*) FROM newsletter_subscribers s WHERE $w", $p);
        $rows = self::db()->fetchAll("SELECT s.* FROM newsletter_subscribers s WHERE $w ORDER BY s.created_at DESC, s.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (max(0, $page - 1) * $per), $p);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, int> */
    public static function counts(?array $only = null): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        $in = $only === null ? '' : ' WHERE id IN (SELECT subscriber_id FROM newsletter_subscriber_lists WHERE list_id IN (' . (implode(',', array_map('intval', $only)) ?: '0') . '))';
        foreach (self::db()->fetchAll('SELECT status, COUNT(*) n FROM newsletter_subscribers' . $in . ' GROUP BY status') as $r) $out[$r['status']] = (int) $r['n'];
        return $out;
    }

    // ------------------------------------------------------------------ Sperrliste

    /** Von Hand sperren: Status gesperrt (Grund „manual“), Sperrliste, Vermerk mit Grund und Person */
    public static function block(int $id, string $reason, string $by): void
    {
        $s = self::find($id);
        if (!$s) return;
        (new self())->updateSubscriber($id, ['status' => 'bounced', 'bounce_reason' => 'manual',
            'note' => trim((string) $s['note'] . "\n" . date('Y-m-d') . ' ' . __('Gesperrt von {name}', ['name' => $by]) . ($reason !== '' ? ': ' . $reason : ''))]);
        self::suppressHash((string) $s['email_hash'], 'manual');
        self::db()->insert('newsletter_events', ['created_at' => self::now(), 'campaign_id' => 0, 'subscriber_id' => $id, 'email' => (string) $s['email'],
            'type' => 'manual', 'diagnostic' => mb_substr(__('Von Hand gesperrt') . ($reason !== '' ? ': ' . $reason : ''), 0, 400), 'source' => 'admin']);
    }

    public static function suppressed(string $email): ?string
    {
        $r = self::db()->fetchValue('SELECT reason FROM newsletter_suppressions WHERE email_hash = ?', [Address::hash($email, Newsletter::key())]);
        return $r === null || $r === false ? null : (string) $r;
    }

    public static function suppressHash(string $hash, string $reason): void
    {
        self::db()->query('INSERT OR IGNORE INTO newsletter_suppressions (email_hash, reason, created_at) VALUES (?, ?, ?)', [$hash, $reason, self::now()]);
    }

    public static function unsuppress(string $email): void
    {
        self::db()->query('DELETE FROM newsletter_suppressions WHERE email_hash = ?', [Address::hash($email, Newsletter::key())]);
    }

    // ------------------------------------------------------------------ Ausgaben

    public static function campaign(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM newsletter_campaigns WHERE id = ?', [$id]);
    }

    /** @return list<array> */
    public static function campaigns(int $limit = 100): array
    {
        return self::db()->fetchAll('SELECT * FROM newsletter_campaigns ORDER BY CASE status WHEN \'sending\' THEN 0 WHEN \'scheduled\' THEN 1 WHEN \'paused\' THEN 2 WHEN \'draft\' THEN 3 ELSE 4 END, COALESCE(finished_at, updated_at) DESC LIMIT ' . (int) $limit);
    }

    public static function saveCampaign(int $id, array $data): int
    {
        $data['updated_at'] = self::now();
        if ($id > 0) { self::db()->update('newsletter_campaigns', $data, 'id = :wid', ['wid' => $id]); return $id; }
        return self::db()->insert('newsletter_campaigns', $data + ['status' => 'draft', 'created_at' => self::now(), 'created_by' => (int) (app()->auth->user()['id'] ?? 0)]);
    }

    public static function deleteCampaign(int $id): void
    {
        self::db()->query('DELETE FROM newsletter_queue WHERE campaign_id = ?', [$id]);
        self::db()->query('DELETE FROM newsletter_campaigns WHERE id = ?', [$id]);
    }

    /**
     * Verteiler, dessen Auftritt eine Ausgabe trägt (Absender-Konto, Fußzeile, Farbe): der erste der gewählten in der
     * Reihenfolge der Verteiler. Leere Angaben fallen auf die Einstellungen des Newsletters zurück.
     */
    public static function primaryList(array $ids): ?array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return null;
        return self::db()->fetch('SELECT * FROM newsletter_lists WHERE id IN (' . implode(',', $ids) . ') ORDER BY sort, id LIMIT 1');
    }

    /** Verteiler entfernen; ohne Verteiler gilt der Empfänger als abgemeldet */
    public static function removeLists(int $subscriber, array $lists): bool
    {
        $lists = array_values(array_filter(array_map('intval', $lists)));
        if ($lists) self::db()->query('DELETE FROM newsletter_subscriber_lists WHERE subscriber_id = ? AND list_id IN (' . implode(',', $lists) . ')', [$subscriber]);
        return !self::listIds($subscriber);
    }

    /** @return list<int> */
    public static function campaignLists(array $c): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $c['lists']))));
    }

    // ------------------------------------------------------------------ Engine\Store

    public function subscriber(int $id): ?array
    {
        return self::find($id);
    }

    public function subscriberByEmail(string $email): ?array
    {
        return self::byEmail($email);
    }

    public function updateSubscriber(int $id, array $changes): void
    {
        if (($changes['status'] ?? '') === 'unsubscribed' || ($changes['status'] ?? '') === 'complained') $changes['unsubscribed_at'] = self::now();
        self::update($id, $changes + ['last_event_at' => self::now()]);
        if (isset($changes['status']) && $changes['status'] !== 'active') {
            self::db()->query('UPDATE newsletter_queue SET status = \'skipped\', error = ? WHERE subscriber_id = ? AND status = \'queued\'', ['status ' . $changes['status'], $id]);
        }
    }

    public function suppress(string $email, string $reason): void
    {
        self::suppressHash(Address::hash($email, Newsletter::key()), $reason);
    }

    public function logEvent(Result $r, int $campaign, int $subscriber): void
    {
        self::db()->insert('newsletter_events', ['created_at' => self::now(), 'campaign_id' => $campaign, 'subscriber_id' => $subscriber,
            'email' => mb_substr($r->recipient, 0, 254), 'type' => $r->type, 'status' => mb_substr($r->status, 0, 12), 'smtp' => $r->smtp,
            'diagnostic' => mb_substr($r->diagnostic, 0, 400), 'source' => $r->source, 'provider_id' => mb_substr($r->providerId, 0, 64)]);
    }

    public function countCampaign(int $campaign, string $stat): void
    {
        if (!in_array($stat, self::STATS, true)) return;
        self::db()->query("UPDATE newsletter_campaigns SET $stat = $stat + 1 WHERE id = ?", [$campaign]);
    }

    /** @return list<array> */
    public static function events(array $f = [], int $limit = 200): array
    {
        $where = ['1 = 1'];
        $p = [];
        if ((int) ($f['subscriber'] ?? 0) > 0) { $where[] = 'subscriber_id = :s'; $p['s'] = (int) $f['subscriber']; }
        if ((int) ($f['campaign'] ?? 0) > 0) { $where[] = 'campaign_id = :c'; $p['c'] = (int) $f['campaign']; }
        if (($f['type'] ?? '') !== '') { $where[] = 'type = :t'; $p['t'] = (string) $f['type']; }
        return self::db()->fetchAll('SELECT * FROM newsletter_events WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . (int) $limit, $p);
    }

    /** Aufbewahrung: Ereignisse älter als $days löschen, unbestätigte Anmeldungen nach CONFIRM_DAYS + 1 */
    public static function purge(int $days): array
    {
        $ev = self::db()->query('DELETE FROM newsletter_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - max(30, $days) * 86400)])->rowCount();
        $old = self::db()->fetchAll('SELECT id FROM newsletter_subscribers WHERE status = \'pending\' AND created_at < ?', [date('Y-m-d H:i:s', time() - (Newsletter::CONFIRM_DAYS + 1) * 86400)]);
        foreach ($old as $o) self::deleteSubscriber((int) $o['id']);
        return ['events' => $ev, 'pending' => count($old)];
    }
}
