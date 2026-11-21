<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Mailer;
use Core\RateLimiter;
use Klxm\Newsletter\Engine\Address;

/**
 * Anmelden (Double-Opt-in), bestätigen, abmelden. Antworten verraten nie, ob eine Adresse schon eingetragen ist –
 * die Website zeigt immer „Bitte bestätigen Sie …“. Nachweis der Einwilligung: Text, Zeitpunkt, IP nur als HMAC-Kürzel.
 */
final class Signup
{
    /**
     * @param list<int> $lists gewünschte Verteiler (nur öffentliche; leer = erster Verteiler)
     * @return array{ok: bool, error?: string, field?: string}
     */
    public static function subscribe(string $email, string $name, array $lists, string $ip, string $source = 'form', array $offered = []): array
    {
        $email = Address::normalize($email);
        if (!Address::valid($email)) return ['ok' => false, 'error' => lt('Bitte geben Sie eine gültige E-Mail-Adresse ein.'), 'field' => 'email'];
        if (!Address::mailDomain($email)) return ['ok' => false, 'error' => lt('Für diese E-Mail-Adresse gibt es keinen Mailserver. Bitte prüfen Sie die Schreibweise.'), 'field' => 'email'];
        // erlaubt: öffentliche Verteiler und die (signiert) vom Block angebotenen; ohne Wahl: das Angebot bzw. der erste Verteiler
        $existing = array_map('intval', array_column(Repo::lists(), 'id'));
        $allowed = array_values(array_intersect(array_unique(array_merge(array_map('intval', array_column(Repo::lists(true), 'id')), $offered)), $existing));
        $lists = array_values(array_intersect(array_map('intval', $lists), $allowed)) ?: (array_values(array_intersect($offered, $existing)) ?: [Repo::defaultList()]);

        // Höchstens 3 Bestätigungsmails je Adresse und Tag – sonst ließe sich jemand mit Bestätigungen zuschütten
        $rl = new RateLimiter(app()->db);
        $k = 'newsletter:doi:' . Address::hash($email, Newsletter::key());
        if ($rl->tooMany($k, 3, 86400)) return ['ok' => true];
        // Von Hand gesperrt: keine Bestätigungsmail, nach außen dieselbe Antwort (nichts verraten)
        if (Repo::suppressed($email) === 'manual') return ['ok' => true];

        $sub = Repo::byEmail($email);
        if ($sub && $sub['status'] === 'active') {
            $missing = array_diff($lists, Repo::listIds((int) $sub['id']));
            if (!$missing) return ['ok' => true];   // schon angemeldet – nichts verraten, keine Mail
        }
        $consent = Newsletter::text('consent_text');
        $ipHash = substr(hash_hmac('sha256', $ip, Newsletter::key()), 0, 16);
        if (!$sub) {
            $sub = Repo::upsert(['email' => $email, 'name' => $name, 'lang' => (string) (app()->lang ?? ''), 'source' => $source, 'consent' => $consent, 'ip_hash' => $ipHash, 'status' => 'pending']);
        } else {
            // erneute Anmeldung (abgemeldet, gesperrt, ausstehend oder neuer Verteiler): neue Einwilligung, Bestätigung nötig
            $changes = ['consent' => $consent, 'consent_at' => Repo::now(), 'ip_hash' => $ipHash];
            if ($name !== '' && $sub['name'] === '') $changes['name'] = mb_substr($name, 0, 120);
            if ($sub['status'] !== 'active') $changes['status'] = 'pending';
            Repo::update((int) $sub['id'], $changes);
            $sub = (array) Repo::find((int) $sub['id']);
        }
        Repo::update((int) $sub['id'], ['pending_lists' => implode(',', $lists)]);
        $rl->hit($k);
        self::sendConfirm(['pending_lists' => implode(',', $lists)] + $sub);
        return ['ok' => true];
    }

    public static function sendConfirm(array $sub): ?string
    {
        $pending = array_filter(array_map('intval', explode(',', (string) ($sub['pending_lists'] ?? ''))));
        $m = Message::confirm($sub, Repo::primaryList($pending));
        $o = ['purpose' => Newsletter::PURPOSE, 'html' => $m['html'], 'headers' => array_diff_key($m['headers'], ['Precedence' => 1, 'List-Id' => 1]), 'message_id' => $m['message_id'], 'imprint' => 'included'];
        $acc = Newsletter::accountFor((string) $m['via']);
        $o['via'] = (string) $acc['id'];
        if ($m['return_path'] !== '' && Newsletter::modeFor($acc) === 'smtp') $o['return_path'] = $m['return_path'];
        $err = Mailer::send($m['subject'], $m['text'], [$m['to']], $o);
        if ($err) error_log('[newsletter] Bestätigung an #' . (int) $sub['id'] . ': ' . $err);
        return $err;
    }

    /** @return string ok | expired | invalid */
    public static function confirm(string $token): string
    {
        $sub = Newsletter::fromToken('confirm', $token);
        if (!$sub) return 'invalid';
        $pending = (string) ($sub['pending_lists'] ?? '');
        if ($sub['status'] === 'active' && $pending === '') return 'ok';   // Link zweimal geklickt
        // Nur offene Anmeldungen bestätigen – ein alter Link meldet nach einer Abmeldung nicht wieder an
        if ($sub['status'] !== 'pending' && $pending === '') return 'invalid';
        if (Repo::suppressed((string) $sub['email']) === 'manual') return 'invalid';   // von Hand gesperrt – nur die Verwaltung hebt auf
        if (strtotime((string) ($sub['consent_at'] ?: $sub['created_at'])) < time() - Newsletter::CONFIRM_DAYS * 86400) return 'expired';
        Repo::update((int) $sub['id'], ['status' => 'active', 'confirmed_at' => Repo::now(), 'bounce_reason' => '', 'soft_count' => 0, 'soft_first_at' => 0,
            'soft_last_campaign' => 0, 'soft_campaigns' => 0, 'unsubscribed_at' => null, 'pending_lists' => '']);
        Repo::setLists((int) $sub['id'], $pending !== '' ? explode(',', $pending) : [Repo::defaultList()]);
        // ausdrückliche neue Einwilligung hebt eine Sperre auf (auch nach Beschwerde oder Hard Bounce)
        Repo::unsuppress((string) $sub['email']);
        return 'ok';
    }

    /**
     * Abmelden – von allen Verteilern ($lists null) oder nur von einzelnen. Bleibt kein Verteiler übrig, ist der Empfänger abgemeldet.
     * $campaign: Ausgabe, aus der abgemeldet wurde (Zähler im Bericht); 0 = die zuletzt versendete
     */
    public static function unsubscribe(array $sub, string $how = 'link', ?array $lists = null, int $campaign = 0): void
    {
        if (!in_array($sub['status'], ['active', 'pending'], true)) return;
        $sid = (int) $sub['id'];
        $names = [];
        if ($lists !== null) {
            $own = Repo::listIds($sid);
            $lists = array_values(array_intersect(array_map('intval', $lists), $own));
            if (!$lists) return;
            foreach (Repo::lists() as $l) if (in_array((int) $l['id'], $lists, true)) $names[] = $l['name'];
            $none = Repo::removeLists($sid, $lists);
        } else {
            $none = true;
        }
        if ($none) (new Repo())->updateSubscriber($sid, ['status' => 'unsubscribed']);
        $label = ['oneclick' => 'One-Click (E-Mail-Programm)', 'admin' => 'Verwaltung'][$how] ?? 'Abmeldelink';
        app()->db->insert('newsletter_events', ['created_at' => Repo::now(), 'campaign_id' => $campaign, 'subscriber_id' => $sid, 'email' => (string) $sub['email'],
            'type' => 'unsubscribe', 'diagnostic' => mb_substr($label . ($names ? ': ' . implode(', ', $names) : ': alle Verteiler'), 0, 400), 'source' => 'site']);
        $cid = $campaign ?: (int) app()->db->fetchValue('SELECT campaign_id FROM newsletter_queue WHERE subscriber_id = ? AND status = \'sent\' ORDER BY sent_at DESC LIMIT 1', [$sid]);
        if ($cid) (new Repo())->countCampaign($cid, 'unsubscribe');
    }
}
