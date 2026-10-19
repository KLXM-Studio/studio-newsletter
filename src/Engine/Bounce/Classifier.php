<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Bounce;

/**
 * Einordnung eines Zustellfehlers: erweiterter Status (RFC 3463) zuerst, dann SMTP-Code, dann Text der Diagnose.
 *
 *  hard   Adresse gibt es nicht (mehr): x.1.1 Nutzer unbekannt, x.1.2 Domain unbekannt, 5.1.3/5.1.10 ungültig, 5.2.1 Postfach gesperrt
 *  soft   vorübergehend: 4.x.x, 5.2.2 Postfach voll, 5.2.3 zu groß, 5.3.x/5.4.x Netzwerk/Weiterleitung
 *  block  Absender-Problem (Spamverdacht, Sperrliste, SPF/DKIM/DMARC, Reputation): Empfänger NICHT sperren, Hinweis an die Redaktion
 *
 * Ein 5.7.x ist mehrdeutig: „user unknown“ im Text → hard, sonst block. Texte in Deutsch und Englisch, weil deutsche
 * Provider (GMX, Web.de, T-Online) oft eigene Formulierungen schicken.
 */
final class Classifier
{
    private const HARD_TEXT = '~(user unknown|unknown user|no such user|no such (?:mail)?box|mailbox (?:not found|unavailable|does not exist|disabled|inactive)|'
        . 'recipient (?:address )?rejected: (?:user unknown|undeliverable|unknown)|address (?:rejected|does not exist|not found)|does not exist|'
        . 'invalid (?:recipient|mailbox|address)|account (?:has been )?(?:disabled|closed|deactivated|suspended)|unrouteable address|'
        . 'domain (?:not found|does not exist)|host (?:not found|unknown)|no mx|unbekannt|existiert nicht|ungültige? (?:empfänger|adresse)|postfach (?:gesperrt|deaktiviert|existiert nicht))~iu';
    private const SOFT_TEXT = '~(mailbox (?:is )?full|over ?quota|quota exceeded|insufficient (?:storage|space)|too many (?:messages|connections)|'
        . 'try (?:again )?later|temporar(?:y|ily)|timed? ?out|connection (?:refused|reset|timed out)|greylist|message too (?:large|big)|'
        . 'postfach[^.\n]{0,40}\bvoll\b|mailbox[^.\n]{0,30}\bfull\b|speicherplatz|später erneut|vorübergehend)~iu';
    private const BLOCK_TEXT = '~(spam|blacklist|blocklist|block ?list|listed (?:at|on|in)|\brbl\b|spamhaus|barracuda|reputation|'
        . 'policy (?:violation|rejection)|content rejected|rejected (?:due to|for policy|because)|not accepted|dmarc|\bspf\b|dkim|'
        . 'authentication (?:required|failed)|unauthenticated|bulk mail|abuse|banned|blocked)~iu';

    public static function classify(string $status = '', int $smtp = 0, string $diagnostic = '', string $action = ''): string
    {
        $action = strtolower(trim($action));
        if ($action === 'delayed') return Result::DELAYED;
        if ($action === 'delivered' || $action === 'relayed' || $action === 'expanded') return Result::DELIVERED;
        $d = ' ' . $diagnostic . ' ';
        if (preg_match('~^([245])\.(\d{1,3})\.(\d{1,3})$~', trim($status), $m)) {
            [$cls, $sub, $det] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($cls === 2) return Result::DELIVERED;
            if ($cls === 4) return Result::SOFT;
            // Klasse 5
            if ($sub === 1) return in_array($det, [0, 1, 2, 3, 10], true) || $det === 6 ? Result::HARD : self::byText($d, Result::HARD);
            if ($sub === 2) return match ($det) { 1 => Result::HARD, 2, 3 => Result::SOFT, default => self::byText($d, Result::SOFT) };
            if ($sub === 3 || $sub === 4) return self::byText($d, Result::SOFT);
            if ($sub === 7) return preg_match(self::HARD_TEXT, $d) ? Result::HARD : Result::BLOCK;
            return self::byText($d, Result::HARD);
        }
        if ($smtp >= 400 && $smtp < 500) return Result::SOFT;
        if ($smtp >= 500 && $smtp < 600) {
            if (preg_match(self::BLOCK_TEXT, $d) && !preg_match(self::HARD_TEXT, $d)) return Result::BLOCK;
            if ($smtp === 552 || preg_match(self::SOFT_TEXT, $d)) return Result::SOFT;
            return Result::HARD;
        }
        return self::byText($d, Result::UNKNOWN);
    }

    /** Nur Text: zuerst „Adresse unbekannt“, dann Sperre, dann vorübergehend */
    private static function byText(string $d, string $fallback): string
    {
        if (preg_match(self::HARD_TEXT, $d)) return Result::HARD;
        if (preg_match(self::BLOCK_TEXT, $d)) return Result::BLOCK;
        if (preg_match(self::SOFT_TEXT, $d)) return Result::SOFT;
        return $fallback;
    }

    /** SMTP-Code und erweiterten Status aus einer Diagnose wie „smtp; 550 5.1.1 <x@y>: Recipient address rejected“ */
    public static function codes(string $diagnostic): array
    {
        $smtp = preg_match('~(?:^|[\s;:])([245]\d\d)(?:[\s-]|$)~', $diagnostic, $m) ? (int) $m[1] : 0;
        $status = preg_match('~\b([245]\.\d{1,3}\.\d{1,3})\b~', $diagnostic, $m) ? $m[1] : '';
        return [$smtp, $status];
    }
}
