<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Fields;

/**
 * Mail-Bausteine einer Ausgabe – eigene, E-Mail-taugliche Blöcke (Tabellen-Layout, Inline-Stile), bearbeitet im gewohnten
 * Block-Editor der Verwaltung (gleiches Markup wie Core\Data\EntryBlocks → admin.js initBlockFields: hinzufügen, verschieben,
 * entfernen, auf-/zuklappen). Gespeichert als Liste {type, data} in newsletter_campaigns.blocks; Darstellung views/mail/blocks/{typ}.php.
 */
final class Blocks
{
    public const MAX = 40;

    /** @return array<string, array{label: string, icon: string, fields: list<array>}> */
    public static function defs(): array
    {
        $align = ['left' => __('links'), 'center' => __('mittig')];
        return [
            'hero' => ['label' => __('Aufmacher'), 'icon' => 'image', 'fields' => [
                ['name' => 'image', 'label' => __('Bild'), 'type' => 'media'],
                ['name' => 'kicker', 'label' => __('Dachzeile'), 'type' => 'text', 'max' => 80],
                ['name' => 'title', 'label' => __('Überschrift'), 'type' => 'text', 'max' => 140],
                ['name' => 'text', 'label' => __('Text'), 'type' => 'richtext'],
                ['name' => 'button', 'label' => __('Knopf: Beschriftung'), 'type' => 'text', 'max' => 60, 'width' => 'half'],
                ['name' => 'link', 'label' => __('Knopf: Ziel'), 'type' => 'link', 'width' => 'half'],
            ]],
            'heading' => ['label' => __('Überschrift'), 'icon' => 'text-t', 'fields' => [
                ['name' => 'text', 'label' => __('Überschrift'), 'type' => 'text', 'max' => 140],
                ['name' => 'size', 'label' => __('Größe'), 'type' => 'select', 'width' => 'half', 'options' => ['l' => __('groß'), 'm' => __('mittel'), 's' => __('klein')]],
                ['name' => 'align', 'label' => __('Ausrichtung'), 'type' => 'select', 'width' => 'half', 'options' => $align],
            ]],
            'text' => ['label' => __('Text'), 'icon' => 'paragraph', 'fields' => [
                ['name' => 'text', 'label' => __('Text'), 'type' => 'richtext', 'help' => __('Platzhalter: {name}')],
                ['name' => 'align', 'label' => __('Ausrichtung'), 'type' => 'select', 'width' => 'half', 'options' => $align],
            ]],
            'image' => ['label' => __('Bild'), 'icon' => 'image', 'fields' => [
                ['name' => 'image', 'label' => __('Bild'), 'type' => 'media'],
                ['name' => 'alt', 'label' => __('Alternativtext'), 'type' => 'text', 'max' => 160, 'help' => __('Leer = aus der Mediathek.')],
                ['name' => 'caption', 'label' => __('Bildunterschrift'), 'type' => 'text', 'max' => 200],
                ['name' => 'link', 'label' => __('Link'), 'type' => 'link', 'width' => 'half'],
                ['name' => 'width', 'label' => __('Breite'), 'type' => 'select', 'width' => 'half', 'options' => ['full' => __('ganze Breite'), 'inset' => __('mit Rand')]],
            ]],
            'image_text' => ['label' => __('Bild und Text'), 'icon' => 'layout', 'fields' => [
                ['name' => 'image', 'label' => __('Bild'), 'type' => 'media'],
                ['name' => 'title', 'label' => __('Überschrift'), 'type' => 'text', 'max' => 140],
                ['name' => 'text', 'label' => __('Text'), 'type' => 'richtext'],
                ['name' => 'button', 'label' => __('Link: Beschriftung'), 'type' => 'text', 'max' => 60, 'width' => 'half'],
                ['name' => 'link', 'label' => __('Link: Ziel'), 'type' => 'link', 'width' => 'half'],
                ['name' => 'position', 'label' => __('Bild'), 'type' => 'select', 'width' => 'half', 'options' => ['left' => __('links'), 'right' => __('rechts')]],
            ]],
            'columns' => ['label' => __('Spalten'), 'icon' => 'squares-four', 'fields' => [
                ['name' => 'items', 'label' => __('Spalten'), 'type' => 'repeater', 'item_label' => __('Spalte'), 'title_field' => 'title', 'max_items' => 3, 'fields' => [
                    ['name' => 'image', 'label' => __('Bild'), 'type' => 'media'],
                    ['name' => 'title', 'label' => __('Überschrift'), 'type' => 'text', 'max' => 120],
                    ['name' => 'text', 'label' => __('Text'), 'type' => 'richtext'],
                    ['name' => 'button', 'label' => __('Link: Beschriftung'), 'type' => 'text', 'max' => 40, 'width' => 'half'],
                    ['name' => 'link', 'label' => __('Link: Ziel'), 'type' => 'link', 'width' => 'half'],
                ], 'help' => __('2 oder 3 Spalten – auf dem Handy untereinander.')],
            ]],
            'entries' => ['label' => __('Einträge aus Datentabelle'), 'icon' => 'table', 'fields' => [
                ['name' => 'table', 'label' => __('Datentabelle'), 'type' => 'datatable'],
                ['name' => 'count', 'label' => __('Anzahl'), 'type' => 'number', 'min' => 1, 'max' => 12, 'width' => 'half'],
                ['name' => 'layout', 'label' => __('Darstellung'), 'type' => 'select', 'width' => 'half', 'options' => ['list' => __('Liste mit Bild'), 'compact' => __('kompakte Liste'), 'cards' => __('Kacheln (2 je Zeile)')]],
                ['name' => 'upcoming', 'label' => __('Nur kommende (Tabellen mit Datum)'), 'type' => 'bool'],
                ['name' => 'more', 'label' => __('Link „Alle ansehen“ zur Übersicht'), 'type' => 'link'],
            ]],
            'button' => ['label' => __('Knopf'), 'icon' => 'arrow-right', 'fields' => [
                ['name' => 'label', 'label' => __('Beschriftung'), 'type' => 'text', 'max' => 60],
                ['name' => 'link', 'label' => __('Ziel'), 'type' => 'link'],
                ['name' => 'align', 'label' => __('Ausrichtung'), 'type' => 'select', 'width' => 'half', 'options' => $align + ['full' => __('ganze Breite')]],
                ['name' => 'style', 'label' => __('Stil'), 'type' => 'select', 'width' => 'half', 'options' => ['filled' => __('gefüllt'), 'outline' => __('Umriss')]],
            ]],
            'quote' => ['label' => __('Zitat'), 'icon' => 'quotes', 'fields' => [
                ['name' => 'text', 'label' => __('Zitat'), 'type' => 'richtext'],
                ['name' => 'author', 'label' => __('Von'), 'type' => 'text', 'max' => 120],
            ]],
            'divider' => ['label' => __('Trenner'), 'icon' => 'minus', 'fields' => [
                ['name' => 'style', 'label' => __('Art'), 'type' => 'select', 'options' => ['line' => __('Linie'), 'space' => __('nur Abstand'), 'accent' => __('kurze Akzentlinie')]],
            ]],
        ];
    }

    public static function def(string $type): ?array
    {
        $d = self::defs()[$type] ?? null;
        if (!$d) return null;
        // Auswahlfelder: erste Möglichkeit als Vorgabe (kein „keine Auswahl“), Anzahl 3
        foreach ($d['fields'] as &$f) {
            if (($f['type'] ?? '') === 'select' && !isset($f['default'])) { $f['default'] = (string) array_key_first($f['options']); $f['required'] = true; }
            if (($f['name'] ?? '') === 'count') $f['default'] ??= 3;
        }
        return $d;
    }

    /** Gespeicherte Bausteine einer Ausgabe; ältere Ausgaben (Bild, Inhalt, Knopf) werden als Bausteine gelesen */
    public static function of(array $c): array
    {
        $list = self::items($c['blocks'] ?? '');
        if ($list) return $list;
        $out = [];
        if ((int) ($c['image'] ?? 0) > 0) $out[] = ['type' => 'image', 'data' => ['image' => (int) $c['image'], 'width' => 'full']];
        if (trim((string) ($c['body'] ?? '')) !== '') $out[] = ['type' => 'text', 'data' => ['text' => (string) $c['body']]];
        if (trim((string) ($c['button_label'] ?? '')) !== '') $out[] = ['type' => 'button', 'data' => ['label' => (string) $c['button_label'], 'link' => (string) $c['button_link']]];
        return $out;
    }

    public static function items(mixed $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        return is_array($raw) ? array_values(array_filter($raw, fn($b) => is_array($b) && self::def((string) ($b['type'] ?? '')))) : [];
    }

    /**
     * Formular-Eingabe ({i: {type, data}}) bereinigen – jedes Feld über Core\Fields wie überall in der Verwaltung.
     * @return array{0: list<array>, 1: array<string, string>} Bausteine, Fehler (Schlüssel „blocks.{i}.{feld}“)
     */
    public static function sanitize(mixed $raw): array
    {
        $out = [];
        $errors = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $i => $b) {
            $def = is_array($b) ? self::def((string) ($b['type'] ?? '')) : null;
            if (!$def) continue;
            [$data, $err] = Fields::sanitize($def['fields'], (array) ($b['data'] ?? []));
            foreach ($err as $k => $msg) $errors['blocks.' . count($out) . '.' . $k] = $msg;
            $out[] = ['type' => (string) $b['type'], 'data' => $data];
            if (count($out) >= self::MAX) break;
        }
        return [$out, $errors];
    }

    /** Darstellung aller Bausteine für die Mail ($preview: leere Bilder als Platzhalter zeigen) */
    public static function render(array $blocks, array $design, array $vars, bool $preview = false): string
    {
        $h = '';
        foreach ($blocks as $i => $b) {
            $file = dirname(__DIR__) . '/views/mail/blocks/' . preg_replace('~[^a-z_]~', '', (string) $b['type']) . '.php';
            if (!is_file($file)) continue;
            $h .= \Core\Theme::capture($file, ['d' => (array) ($b['data'] ?? []), 's' => $design, 'vars' => $vars, 'preview' => $preview, 'first' => $i === 0,
                'font' => Design::FONTS[$design['font']] ?? Design::FONTS['system']]);
        }
        return $h;
    }

    // ------------------------------------------------------------------ Eingabe (Block-Editor der Verwaltung)

    public static function renderInput(string $name, array $blocks, array $errors = []): string
    {
        $defs = self::defs();
        $h = '<div class="f f--blocks nl-blocks" data-blocks data-name="' . e($name) . '"><span class="f-label">' . e(__('Inhalt')) . '</span>'
            . '<p class="f-help">' . e(__('Bausteine hinzufügen, mit ↑ ↓ ordnen, aufklappen zum Bearbeiten – die Vorschau rechts zeigt die fertige Mail.')) . '</p><div class="blk-items" data-blk-items>';
        foreach ($blocks as $i => $b) {
            $open = count($blocks) <= 2 || (bool) array_filter(array_keys($errors), fn($k) => str_starts_with((string) $k, "blocks.$i."));
            $h .= self::item($name, (string) $i, $b, $errors, "blocks.$i", $open);
        }
        $h .= '</div>';
        foreach (array_keys($defs) as $t) $h .= '<template data-blk-tpl="' . e($t) . '">' . self::item($name, '__blk__', ['type' => $t, 'data' => []], [], '', true) . '</template>';
        $h .= '<div class="blk-add"><button type="button" class="btn btn--small adm-btn" data-blk-add aria-haspopup="menu" aria-expanded="false">' . icon('plus') . ' ' . e(__('Baustein hinzufügen')) . '</button>'
            . '<div class="blk-menu" role="menu" hidden>';
        foreach ($defs as $t => $def) {
            $h .= '<button type="button" role="menuitem" data-blk-type="' . e($t) . '">' . \Core\Icons::render($def['icon']) . '<span>' . e($def['label']) . '</span></button>';
        }
        return $h . '</div></div></div>';
    }

    private static function item(string $name, string $idx, array $b, array $errors, string $path, bool $open): string
    {
        $type = (string) $b['type'];
        $def = self::def($type) ?? ['label' => $type, 'icon' => 'squares-four', 'fields' => []];
        $data = array_replace(Fields::defaults($def['fields']), is_array($b['data'] ?? null) ? $b['data'] : []);
        $n = $name . '[' . $idx . ']';
        $peek = self::peek($type, $data);
        return '<fieldset class="rep-item blk-item' . ($open ? '' : ' is-collapsed') . '" data-blk-item data-type="' . e($type) . '"><legend>'
            . '<button type="button" class="rep-toggle" data-blk="toggle" aria-expanded="' . ($open ? 'true' : 'false') . '"><span class="rep-chev" aria-hidden="true"></span>'
            . '<span class="rep-title">' . \Core\Icons::render($def['icon']) . ' ' . e($def['label']) . ($peek !== '' ? ' <span class="blk-peek">– ' . e($peek) . '</span>' : '') . '</span></button></legend>'
            . '<div class="rep-tools"><button type="button" class="icon-btn" data-blk="up" aria-label="' . e(__('Nach oben')) . '">↑</button>'
            . '<button type="button" class="icon-btn" data-blk="down" aria-label="' . e(__('Nach unten')) . '">↓</button>'
            . '<button type="button" class="icon-btn icon-btn--danger" data-blk="remove" aria-label="' . e(__('Baustein „{label}“ entfernen', ['label' => $def['label']])) . '">✕</button></div>'
            . '<input type="hidden" name="' . e($n) . '[type]" value="' . e($type) . '">'
            . '<div class="rep-fields blk-fields">' . Fields::renderForm($def['fields'], $data, $errors, $n . '[data]', $path) . '</div></fieldset>';
    }

    private static function peek(string $type, array $d): string
    {
        foreach (['title', 'text', 'label', 'caption', 'author'] as $k) {
            $v = trim(strip_tags((string) (is_scalar($d[$k] ?? null) ? $d[$k] : '')));
            if ($v !== '') return mb_strimwidth($v, 0, 60, '…');
        }
        return '';
    }
}
