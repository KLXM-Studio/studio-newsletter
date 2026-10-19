<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Template;

/**
 * Hilfen für die Mail-Bausteine (views/mail/blocks): Bild als JPEG/PNG mit absoluter Adresse, Links, Knopf, Text mit
 * Platzhaltern. Alles mit Inline-Stilen – E-Mail-Programme ignorieren Klassen oft.
 */
final class View
{
    /** @return array{src: string, alt: string, w: int, h: int}|null */
    public static function image(int $id, int $width = 1200): ?array
    {
        if ($id <= 0 || !($m = \Core\Media::find($id)) || !str_starts_with((string) ($m['mime'] ?? ''), 'image/') || str_contains((string) $m['mime'], 'svg')) return null;
        $file = \Core\Media::localFile($m, $width, 'jpg');
        $src = abs_url(is_file($file) ? \Core\Media::url($m, $width, 'jpg') : \Core\Media::url($m));
        $w = (int) ($m['width'] ?? 0);
        $h = (int) ($m['height'] ?? 0);
        return ['src' => $src, 'alt' => (string) ($m['alt'] ?? ''), 'w' => $w, 'h' => $h];
    }

    /** Platzhalter für leere Bilder – nur in Vorschau und Vorlagen-Ansicht */
    public static function placeholder(array $s, int $height = 220, string $label = ''): string
    {
        return '<div style="height:' . $height . 'px;background:' . e(\Core\Contrast::mix($s['accent'], $s['card'], 14)) . ';color:' . e($s['accent']) . ';font:600 14px/'
            . $height . 'px sans-serif;text-align:center">' . e($label !== '' ? $label : __('Bild wählen')) . '</div>';
    }

    public static function href(mixed $link): string
    {
        return Message::href(is_string($link) ? $link : '');
    }

    /** Rich-Text sicher, Platzhalter gefüllt, relative Links absolut */
    public static function rich(string $html, array $vars): string
    {
        $h = Template::fill(rich($html), $vars, true);
        return (string) preg_replace_callback('~\b(href|src)="(/[^"/][^"]*|/)"~', fn($m) => $m[1] . '="' . e(abs_url(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"', $h);
    }

    public static function plain(mixed $text, array $vars): string
    {
        return nl2br(e(Template::fill(is_string($text) ? $text : '', $vars)));
    }

    /** Knopf als Tabelle (Outlook) – $align left|center|full, $style filled|outline */
    public static function button(string $label, string $href, array $s, string $font, string $align = 'left', string $style = 'filled'): string
    {
        if ($label === '' || $href === '') return '';
        $r = Design::radius($s, 'button');
        $outline = $style === 'outline';
        $bg = $outline ? 'transparent' : $s['accent'];
        $color = $outline ? $s['accent'] : $s['button_text'];
        $full = $align === 'full';
        $a = '<a href="' . e($href) . '" style="display:' . ($full ? 'block' : 'inline-block') . ';padding:13px 24px;font-family:' . $font . ';font-size:16px;font-weight:700;line-height:1.2;color:'
            . e($color) . ';text-decoration:none;border-radius:' . $r . ';' . ($outline ? 'border:2px solid ' . e($s['accent']) . ';' : '') . '">' . e($label) . '</a>';
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"' . ($full ? ' width="100%"' : '') . ($align === 'center' ? ' align="center"' : '')
            . ' style="margin:0' . ($align === 'center' ? ' auto' : '') . '"><tr><td align="center" style="border-radius:' . $r . ';background:' . e($bg) . '">' . $a . '</td></tr></table>';
    }

    /** Zeile mit Innenabstand */
    public static function row(string $inner, string $pad = '12px 36px', string $align = 'left'): string
    {
        return '<tr><td class="nl-pad" align="' . e($align) . '" style="padding:' . $pad . '">' . $inner . '</td></tr>';
    }
}
