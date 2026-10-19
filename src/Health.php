<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Address;
use Klxm\Newsletter\Engine\Deliverability;

/**
 * Prüfungen für `php bin/console health` und die Übersicht: Konto, Rückläufer-Weg, DNS der Absender-Domain,
 * Quoten der letzten Ausgabe (Bounces über 2 %, Beschwerden über 0,1 % – ab da werden Gmail/Microsoft misstrauisch).
 */
final class Health
{
    /** DNS-Prüfung der Absender-Domain, einen Tag zwischengespeichert */
    public static function dns(bool $useCache = true, bool $refresh = false): array
    {
        $acc = \Core\Mail\Accounts::route(Newsletter::PURPOSE);
        $domain = Address::domain((string) ($acc['from'] ?? ''));
        $cache = json_decode((string) Newsletter::get('dns', ''), true);
        if ($useCache && !$refresh && is_array($cache) && ($cache['domain'] ?? '') === $domain && (int) ($cache['at'] ?? 0) > time() - 86400) return $cache;
        $res = Deliverability::check($domain) + ['at' => time()];
        Newsletter::set('dns', json_encode($res));
        return $res;
    }

    /** @return array<string, ?bool> Text → true (ok) | false (Fehler) | null (Hinweis) */
    public static function checks(): array
    {
        if (!Newsletter::on()) return [];
        $out = [];
        $mode = Newsletter::mode();
        $acc = \Core\Mail\Accounts::route(Newsletter::PURPOSE);
        if ($mode !== 'mailjet_api' && !\Core\Mail\Accounts::ready($acc)) $out[__('Newsletter: kein versandbereites E-Mail-Konto für den Zweck „Newsletter versenden“')] = false;
        if ($mode === 'smtp') {
            if (trim((string) Newsletter::get('bounce_address', '')) === '') $out[__('Newsletter: keine Rücklaufadresse – Rückläufer landen beim Absender und werden nicht ausgewertet')] = null;
            if (!Bounces::imapConfigured()) $out[__('Newsletter: Bounce-Postfach (IMAP) nicht eingerichtet – unzustellbare Adressen werden nicht gesperrt')] = null;
            $last = json_decode((string) Newsletter::get('imap_last', ''), true);
            if (is_array($last) && ($last['error'] ?? '') !== '') $out[__('Newsletter: Bounce-Postfach nicht erreichbar ({msg})', ['msg' => mb_substr((string) $last['error'], 0, 120)])] = false;
        } else {
            if (!Newsletter::mailjet()->configured()) $out[__('Newsletter: Mailjet-Schlüssel fehlen')] = false;
            $hook = (int) Newsletter::get('hook_last', 0);
            $sent = (int) app()->db->fetchValue('SELECT COUNT(*) FROM newsletter_campaigns WHERE status IN (\'sent\', \'sending\') AND sent > 0');
            if ($sent && !$hook) $out[__('Newsletter: noch kein Webhook von Mailjet empfangen – unter Einstellungen „Webhooks bei Mailjet einrichten“')] = null;
        }
        $dns = self::dns();
        if ($dns['domain'] !== '' && $dns['spf'] === false) $out[__('Newsletter: Absender-Domain {d} ohne SPF-Eintrag', ['d' => $dns['domain']])] = null;
        if ($dns['domain'] !== '' && $dns['dmarc'] === false) $out[__('Newsletter: Absender-Domain {d} ohne DMARC-Eintrag (Gmail/Yahoo verlangen ihn für Massenversand)', ['d' => $dns['domain']])] = null;
        $c = app()->db->fetch('SELECT * FROM newsletter_campaigns WHERE status = \'sent\' AND sent >= 50 ORDER BY finished_at DESC LIMIT 1');
        if ($c) {
            $q = Campaigns::rates($c);
            if ($q['bounce'] > 2) $out[__('Newsletter: letzte Ausgabe mit {p} % Rückläufern – Liste prüfen (über 2 % schadet dem Ruf des Absenders)', ['p' => fmt()->decimal($q['bounce'], 1)])] = null;
            if ($q['complaint'] > 0.1) $out[__('Newsletter: letzte Ausgabe mit {p} % Spam-Beschwerden (Grenze 0,1 %)', ['p' => fmt()->decimal($q['complaint'], 2)])] = false;
        }
        return $out;
    }
}
