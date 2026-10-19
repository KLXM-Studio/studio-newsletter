<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * Platzhalter in Betreff und Inhalt: {name}, {email}, {unsubscribe}, {webversion} und eigene. In HTML werden Werte
 * escaped, URLs nur als http(s). Unbekannte Platzhalter bleiben stehen (sichtbar im Testversand).
 * Dazu die Textfassung aus HTML (Links als „Text (URL)“), weil Spamfilter Mails ohne Textteil abwerten.
 */
final class Template
{
    /** @param array<string, string> $vars */
    public static function fill(string $s, array $vars, bool $html = false): string
    {
        return (string) preg_replace_callback('~\{([a-z_]{2,30})\}~', function ($m) use ($vars, $html) {
            if (!array_key_exists($m[1], $vars)) return $m[0];
            $v = (string) $vars[$m[1]];
            return $html ? htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v;
        }, $s);
    }

    public static function toText(string $html): string
    {
        $h = preg_replace('~<(script|style|head)\b.*?</\1>~is', '', $html) ?? $html;
        $h = preg_replace_callback('~<a\b[^>]*href=("|\')(.*?)\1[^>]*>(.*?)</a>~is', function ($m) {
            $text = trim(strip_tags($m[3]));
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~^(https?:|mailto:)~i', $url)) return $text;
            return $text === '' || $text === $url ? $url : $text . ' (' . $url . ')';
        }, $h) ?? $h;
        $h = preg_replace('~<li\b[^>]*>~i', "\n- ", $h) ?? $h;
        $h = preg_replace('~<(br)\b[^>]*>~i', "\n", $h) ?? $h;
        $h = preg_replace('~</(p|div|h[1-6]|tr|table|ul|ol|blockquote)>~i', "\n\n", $h) ?? $h;
        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("~[ \t]+~", ' ', $t) ?? $t;
        $t = preg_replace("~ *\n *~", "\n", $t) ?? $t;
        return trim(preg_replace("~\n{3,}~", "\n\n", $t) ?? $t);
    }
}
