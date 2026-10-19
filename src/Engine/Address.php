<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * E-Mail-Adressen: Schreibweise vereinheitlichen, Prüfsumme für die Sperrliste (gelöschte Empfänger bleiben nur als
 * HMAC gespeichert – kein Klartext, ein erneuter Import erkennt sie trotzdem), Domain für die Drosselung, Plausibilität.
 */
final class Address
{
    /** Bekannte Wegwerf-Domains (Auszug) – nur für den Hinweis bei der Anmeldung */
    private const DISPOSABLE = ['mailinator.com', 'guerrillamail.com', 'trashmail.com', '10minutemail.com', 'yopmail.com', 'temp-mail.org',
        'wegwerfmail.de', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'throwawaymail.com', 'maildrop.cc', 'fakeinbox.com'];

    public static function normalize(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) return mb_strtolower($email);
        $domain = mb_strtolower(substr($email, $at + 1));
        if (function_exists('idn_to_ascii') && preg_match('~[^\x20-\x7e]~', $domain)) $domain = (string) (idn_to_ascii($domain) ?: $domain);
        // lokaler Teil: Groß/klein ist bei allen relevanten Anbietern gleich – einheitlich klein, damit Dubletten auffallen
        return mb_strtolower(substr($email, 0, $at)) . '@' . $domain;
    }

    public static function valid(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function domain(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    public static function hash(string $email, string $key): string
    {
        return hash_hmac('sha256', 'nl-addr|' . self::normalize($email), $key);
    }

    public static function disposable(string $email): bool
    {
        return in_array(self::domain($email), self::DISPOSABLE, true);
    }

    /** Hat die Domain einen Mailserver (MX, sonst A)? Ohne DNS-Funktion: true (nicht blockieren) */
    public static function mailDomain(string $email): bool
    {
        $d = self::domain($email);
        if ($d === '' || !function_exists('checkdnsrr')) return true;
        return @checkdnsrr($d . '.', 'MX') || @checkdnsrr($d . '.', 'A') || @checkdnsrr($d . '.', 'AAAA');
    }
}
