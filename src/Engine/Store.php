<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

use Klxm\Newsletter\Engine\Bounce\Result;

/**
 * Speicher, den die Engine für Rückläufer und Ereignisse braucht. Das CMS liefert die Umsetzung
 * (KLXM Studio: Klxm\Newsletter\Repo; REDAXO 6: eigene Klasse über rex_sql/YForm).
 *
 * Empfänger als Array mit mindestens: id, email, status (pending|active|unsubscribed|bounced|complained),
 * soft_count, soft_first_at (Unix-Zeit), soft_last_campaign, soft_campaigns.
 */
interface Store
{
    public function subscriber(int $id): ?array;

    public function subscriberByEmail(string $email): ?array;

    /** Felder ändern (aus Policy::decide) */
    public function updateSubscriber(int $id, array $changes): void;

    /** Adresse auf die Sperrliste (nur Prüfsumme) */
    public function suppress(string $email, string $reason): void;

    /** Befund protokollieren; $subscriber/$campaign 0 = unbekannt */
    public function logEvent(Result $r, int $campaign, int $subscriber): void;

    /** Zähler der Kampagne erhöhen (hard, soft, block, complaint, unsubscribe) */
    public function countCampaign(int $campaign, string $stat): void;
}
