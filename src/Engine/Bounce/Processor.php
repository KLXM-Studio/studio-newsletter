<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Bounce;

use Klxm\Newsletter\Engine\Store;

/**
 * Wendet einen Befund an: Empfänger finden (Bezug aus der Mail, sonst Adresse), Regel anwenden, speichern, protokollieren.
 * Gleich für Bounce-Postfach (IMAP), Ablehnung beim Einliefern (SMTP) und Webhooks von Versanddiensten.
 */
final class Processor
{
    public function __construct(private Store $store, private Policy $policy) {}

    /**
     * $byAddress: ohne gültigen Bezug (Ref) auch über die gemeldete Adresse zuordnen. Nur für geschützte Quellen (Mailjet-Webhook
     * mit Schlüssel) – eine Mail an das Bounce-Postfach kann jeder schicken; sonst ließe sich jede Adresse per gefälschter
     * Beschwerde sperren. Ohne Bezug wird dann nur protokolliert.
     * @return array{subscriber: int, campaign: int, applied: array}
     */
    public function apply(Result $r, int $now, bool $byAddress = true): array
    {
        $campaign = (int) ($r->ref['campaign'] ?? 0);
        $sub = null;
        if ($r->ref) {
            $sub = $this->store->subscriber((int) $r->ref['subscriber']);
            // Bezug passt, aber gemeldete Adresse gehört jemand anderem (Weiterleitung): Bezug gilt – Weiterleitungen melden die Zieladresse
        }
        if (!$sub && $r->recipient !== '' && $byAddress) $sub = $this->store->subscriberByEmail($r->recipient);
        $sid = (int) ($sub['id'] ?? 0);
        $applied = ['changes' => [], 'suppress' => null, 'stat' => null];
        if ($sub) {
            $applied = $this->policy->decide($r, $sub, $campaign, $now);
            if ($applied['changes']) $this->store->updateSubscriber($sid, $applied['changes']);
            if ($applied['suppress']) $this->store->suppress((string) $sub['email'], (string) $applied['suppress']);
        } elseif ($r->type === Result::COMPLAINT && $r->recipient !== '' && $byAddress) {
            // Beschwerde ohne bekannten Empfänger: trotzdem sperren
            $this->store->suppress($r->recipient, 'complaint');
            $applied['suppress'] = 'complaint';
        }
        if ($campaign > 0 && $applied['stat']) $this->store->countCampaign($campaign, (string) $applied['stat']);
        if ($r->type !== Result::DELIVERED) $this->store->logEvent($r, $campaign, $sid);
        return ['subscriber' => $sid, 'campaign' => $campaign, 'applied' => $applied];
    }
}
