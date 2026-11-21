<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Bounce;

/**
 * Regeln: was ein Befund für den Empfänger bedeutet. Reine Entscheidung ohne Datenbank – die Anbindung (Studio, später
 * REDAXO) speichert das Ergebnis.
 *
 *  hard        sofort gesperrt (Status bounced) + Sperrliste
 *  soft        Zähler +1, höchstens einmal je Kampagne; liegt der erste Soft Bounce länger als $window Tage zurück, beginnt
 *              die Zählung neu. Ab $threshold Soft Bounces aus mindestens 2 Kampagnen: gesperrt wie hard (Grund „soft“)
 *  complaint   abgemeldet + Sperrliste – nie wieder anschreiben
 *  unsubscribe abgemeldet (Abmeldung beim Versanddienst)
 *  block       Empfänger bleibt aktiv – Problem beim Absender, zählt für die Warnung der Kampagne
 *  delivered   Soft-Zähler zurück auf 0 (Zustellung belegt, z. B. Ereignis „sent“ eines Versanddienstes)
 *  auto, delayed, unknown  nur protokollieren
 *
 * Status reconfirm (Altbestand, Bestätigung ausstehend) wird bei hard und unsubscribe behandelt wie pending.
 * Eingabe $sub: status, soft_count, soft_first_at (Unix-Zeit), soft_last_campaign
 * @return array{changes: array<string, mixed>, suppress: ?string, stat: ?string}
 */
final class Policy
{
    public function __construct(public readonly int $threshold = 3, public readonly int $window = 60) {}

    public function decide(Result $r, array $sub, int $campaign, int $now): array
    {
        $out = ['changes' => [], 'suppress' => null, 'stat' => null];
        $status = (string) ($sub['status'] ?? 'active');
        switch ($r->type) {
            case Result::HARD:
                $out['stat'] = 'hard';
                if (in_array($status, ['active', 'pending', 'reconfirm'], true)) $out['changes'] = ['status' => 'bounced', 'bounce_reason' => 'hard'];
                $out['suppress'] = 'hard';
                break;
            case Result::SOFT:
                $out['stat'] = 'soft';
                if ($status !== 'active') break;
                $count = (int) ($sub['soft_count'] ?? 0);
                $first = (int) ($sub['soft_first_at'] ?? 0);
                $last = (int) ($sub['soft_last_campaign'] ?? 0);
                $campaigns = (int) ($sub['soft_campaigns'] ?? 0);
                if ($first > 0 && $now - $first > $this->window * 86400) { $count = 0; $first = 0; $campaigns = 0; $last = 0; }
                if ($campaign > 0 && $campaign === $last && $count > 0) break;   // dieselbe Kampagne zählt einmal
                $count++;
                if ($campaign > 0 && $campaign !== $last) $campaigns++;
                $out['changes'] = ['soft_count' => $count, 'soft_first_at' => $first ?: $now, 'soft_last_campaign' => $campaign ?: $last, 'soft_campaigns' => $campaigns];
                if ($count >= $this->threshold && $campaigns >= min(2, $this->threshold)) {
                    $out['changes'] += ['status' => 'bounced', 'bounce_reason' => 'soft'];
                    $out['suppress'] = 'soft';
                }
                break;
            case Result::COMPLAINT:
                $out['stat'] = 'complaint';
                $out['changes'] = ['status' => 'complained'];
                $out['suppress'] = 'complaint';
                break;
            case Result::UNSUBSCRIBE:
                $out['stat'] = 'unsubscribe';
                if (in_array($status, ['active', 'pending', 'reconfirm'], true)) $out['changes'] = ['status' => 'unsubscribed'];
                break;
            case Result::BLOCK:
                $out['stat'] = 'block';
                break;
            case Result::DELIVERED:
                if ((int) ($sub['soft_count'] ?? 0) > 0) $out['changes'] = ['soft_count' => 0, 'soft_first_at' => 0, 'soft_last_campaign' => 0, 'soft_campaigns' => 0];
                break;
        }
        return $out;
    }
}
