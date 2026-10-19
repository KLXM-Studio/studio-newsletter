<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * Drosselung je Empfänger-Domain: wählt aus der Warteschlange einen Stapel für einen Lauf (z. B. eine Minute), ohne einen
 * Anbieter mit zu vielen Mails auf einmal zu treffen. Große Anbieter reagieren auf Spitzen mit 4xx („try again later“)
 * oder Sperren. Gruppen fassen Domains eines Anbieters zusammen (gmail.com + googlemail.com, gmx.de + gmx.net …).
 * Zusätzlich gedrosselt: Domains mit vielen Soft Bounces/Blockaden im letzten Lauf ($slow → halbe Menge).
 */
final class Throttle
{
    public const GROUPS = [
        'google' => ['gmail.com', 'googlemail.com'],
        'microsoft' => ['outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'live.de', 'msn.com'],
        'telekom' => ['t-online.de', 'magenta.de'],
        'united' => ['gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'web.de', 'mail.com'],
        'yahoo' => ['yahoo.com', 'yahoo.de', 'ymail.com', 'aol.com', 'aol.de'],
        'apple' => ['icloud.com', 'me.com', 'mac.com'],
        'freenet' => ['freenet.de'],
    ];
    /** Vorgaben je Lauf (Minute) – eigene Werte über $limits */
    public const DEFAULTS = ['google' => 60, 'microsoft' => 40, 'telekom' => 20, 'united' => 40, 'yahoo' => 30, 'apple' => 40, 'freenet' => 20, '*' => 30];

    /** @param array<string, int> $limits  @param list<string> $slow Gruppen mit Problemen im letzten Lauf */
    public function __construct(private array $limits = [], private int $total = 200, private array $slow = []) {}

    public static function group(string $domain): string
    {
        $domain = strtolower($domain);
        foreach (self::GROUPS as $g => $list) if (in_array($domain, $list, true)) return $g;
        return $domain;
    }

    public function limit(string $group): int
    {
        $l = $this->limits[$group] ?? (isset(self::DEFAULTS[$group]) ? self::DEFAULTS[$group] : ($this->limits['*'] ?? self::DEFAULTS['*']));
        return in_array($group, $this->slow, true) ? max(1, intdiv($l, 2)) : $l;
    }

    /**
     * Stapel wählen (Reihenfolge der Warteschlange bleibt erhalten).
     * @param list<array{id: int, domain: string}> $queue
     * @return list<int> IDs für diesen Lauf
     */
    public function pick(array $queue): array
    {
        $used = [];
        $out = [];
        foreach ($queue as $q) {
            if (count($out) >= $this->total) break;
            $g = self::group((string) $q['domain']);
            if (($used[$g] ?? 0) >= $this->limit($g)) continue;
            $used[$g] = ($used[$g] ?? 0) + 1;
            $out[] = (int) $q['id'];
        }
        return $out;
    }
}
