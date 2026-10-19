<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * Bezug einer versendeten Mail: Kampagne + Empfänger, signiert (HMAC) – steckt in drei Stellen jeder Mail, damit ein
 * Rückläufer auch dann zugeordnet werden kann, wenn ein Mailserver nur Teile der Originalnachricht zurückschickt:
 *  - Rücklaufadresse (VERP): bounce+{c}.{s}.{sig}@news.example.org   (Return-Path / Envelope-From)
 *  - Kopfzeile X-Newsletter-Ref: {c}.{s}.{sig}
 *  - Message-ID: <nl.{c}.{s}.{sig}@news.example.org>
 * Signatur: 10 Hex-Zeichen (Kleinbuchstaben – manche Server ändern die Schreibweise des lokalen Teils). Kampagne 0 =
 * Bestätigungs- oder Testmail.
 */
final class Ref
{
    public const HEADER = 'X-Newsletter-Ref';

    public static function make(int $campaign, int $subscriber, string $key): string
    {
        return $campaign . '.' . $subscriber . '.' . self::sig($campaign, $subscriber, $key);
    }

    /** Rücklaufadresse aus der Basisadresse (bounce@domain → bounce+ref@domain); leere Basis = keine VERP-Adresse */
    public static function verp(string $base, int $campaign, int $subscriber, string $key): string
    {
        $at = strrpos($base, '@');
        if ($at === false) return '';
        return substr($base, 0, $at) . '+' . self::make($campaign, $subscriber, $key) . substr($base, $at);
    }

    public static function messageId(string $domain, int $campaign, int $subscriber, string $key): string
    {
        return 'nl.' . self::make($campaign, $subscriber, $key) . '@' . $domain;
    }

    /**
     * Ersten gültigen Bezug in einem Text finden (Kopfzeilen, eingebettete Originalnachricht, Diagnose).
     * @return array{campaign: int, subscriber: int}|null  null = kein Bezug oder Signatur falsch
     */
    public static function find(string $text, string $key): ?array
    {
        if (!preg_match_all('~(?:\+|\bnl\.|' . preg_quote(self::HEADER, '~') . ':\s*)(\d{1,10})\.(\d{1,10})\.([0-9a-f]{10})\b~i', $text, $m, PREG_SET_ORDER)) return null;
        foreach ($m as $x) {
            [$c, $s] = [(int) $x[1], (int) $x[2]];
            if (hash_equals(self::sig($c, $s, $key), strtolower($x[3]))) return ['campaign' => $c, 'subscriber' => $s];
        }
        return null;
    }

    private static function sig(int $campaign, int $subscriber, string $key): string
    {
        return substr(hash_hmac('sha256', 'nl-ref|' . $campaign . '|' . $subscriber, $key), 0, 10);
    }
}
