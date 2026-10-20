<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Db\Table;

/**
 * Designs für Ausgaben: Logo, Kopf, Schrift, Farben, Ecken, Knopfform, Dunkelmodus. Tabelle newsletter_designs
 * (name, settings JSON). Mitgelieferte Designs (PRESETS) werden beim ersten Aufruf angelegt und lassen sich anpassen,
 * kopieren oder als Ausgangspunkt für eigene nutzen. Ein Verteiler wählt sein Design, eine Ausgabe kann es überschreiben.
 * Schriften nur als System-Stapel – Webfonts zeigen viele E-Mail-Programme (Outlook, Gmail) nicht.
 */
final class Design
{
    public const FONTS = [
        'system' => "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        'helvetica' => "Helvetica,Arial,sans-serif",
        'verdana' => "Verdana,Geneva,sans-serif",
        'trebuchet' => "'Trebuchet MS',Tahoma,sans-serif",
        'georgia' => "Georgia,'Times New Roman',serif",
    ];
    public const DEFAULTS = [
        'header' => 'name', 'logo' => 0, 'logo_width' => 160, 'font' => 'system',
        'bg' => '#f2f2f0', 'card' => '#ffffff', 'text' => '#2b2b2b', 'heading' => '#161616', 'muted' => '#6b6b6b',
        'accent' => '#1f3a5f', 'button_text' => '#ffffff', 'header_bg' => '#1f3a5f', 'header_text' => '#ffffff',
        'radius' => 8, 'button' => 'rounded', 'card_border' => true, 'dark' => true,
        'header_blocks' => [], 'footer_blocks' => [], 'blocks_set' => false,
    ];
    /** Mitgelieferte Designs – Kontraste nach WCAG AA geprüft (Selbsttest) */
    public const PRESETS = [
        'klar' => ['name' => 'Klar', 'accent' => '#1f3a5f', 'header' => 'name'],
        'warm' => ['name' => 'Warm', 'bg' => '#f6efe6', 'card' => '#fffaf3', 'text' => '#3b2f2a', 'heading' => '#2a1f1a', 'muted' => '#6e5d55',
            'accent' => '#a3471f', 'font' => 'trebuchet', 'radius' => 14, 'button' => 'pill'],
        'natur' => ['name' => 'Natur', 'bg' => '#eef3ec', 'accent' => '#2f6b3a', 'heading' => '#1d3a22', 'radius' => 6],
        'verein' => ['name' => 'Verein', 'bg' => '#e9eef6', 'accent' => '#1d4ed8', 'header' => 'band', 'header_bg' => '#1d4ed8', 'header_text' => '#ffffff', 'radius' => 10],
        'kontrast' => ['name' => 'Kontrast', 'bg' => '#161616', 'muted' => '#b8b8b8', 'card' => '#ffffff', 'accent' => '#161616', 'button_text' => '#ffd84a', 'header' => 'band',
            'header_bg' => '#161616', 'header_text' => '#ffd84a', 'radius' => 0, 'button' => 'square', 'card_border' => false],
        'minimal' => ['name' => 'Minimal', 'bg' => '#ffffff', 'card' => '#ffffff', 'accent' => '#161616', 'radius' => 0, 'button' => 'square', 'card_border' => false, 'font' => 'helvetica'],
    ];

    public static function table(): array
    {
        return ['newsletter_designs' => fn(Table $t) => $t->id()
            ->column('name', 'string', ['length' => 80, 'null' => false])
            ->column('preset', 'string', ['length' => 20, 'default' => ''])
            ->column('settings', 'text')
            ->column('sort', 'int', ['default' => 0])
            ->column('created_at', 'datetime')->column('updated_at', 'datetime')];
    }

    /** Mitgelieferte Designs anlegen, falls noch keine da sind */
    public static function ensure(): void
    {
        if ((int) app()->db->fetchValue('SELECT COUNT(*) FROM newsletter_designs')) return;
        $i = 0;
        foreach (self::PRESETS as $key => $p) {
            $name = (string) $p['name'];
            unset($p['name']);
            app()->db->insert('newsletter_designs', ['name' => $name, 'preset' => $key, 'settings' => json_encode($p + self::DEFAULTS), 'sort' => $i++,
                'created_at' => Repo::now(), 'updated_at' => Repo::now()]);
        }
    }

    /** @return list<array> mit entpackten Einstellungen unter 's' */
    public static function all(): array
    {
        self::ensure();
        return array_map(fn($r) => $r + ['s' => self::settings($r)], app()->db->fetchAll('SELECT * FROM newsletter_designs ORDER BY sort, id'));
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) return null;
        $r = app()->db->fetch('SELECT * FROM newsletter_designs WHERE id = ?', [$id]);
        return $r ? $r + ['s' => self::settings($r)] : null;
    }

    /** Design einer Ausgabe: eigenes, sonst das des ersten Verteilers, sonst das erste */
    public static function forCampaign(array $c): array
    {
        if ($d = self::find((int) ($c['design_id'] ?? 0))) return $d;
        $list = Repo::primaryList(Repo::campaignLists($c));
        return self::forList($list);
    }

    public static function forList(?array $list): array
    {
        if ($list && ($d = self::find((int) ($list['design_id'] ?? 0)))) return $d;
        $all = self::all();
        return $all[0] ?? ['id' => 0, 'name' => 'Klar', 's' => self::DEFAULTS];
    }

    /** Einstellungen mit Standardwerten; ältere Farbe eines Verteilers (color) überschreibt die Akzentfarbe */
    public static function settings(array $row, ?array $list = null): array
    {
        $s = json_decode((string) ($row['settings'] ?? ''), true);
        $s = (is_array($s) ? $s : []) + self::DEFAULTS;
        foreach (['bg', 'card', 'text', 'heading', 'muted', 'accent', 'button_text', 'header_bg', 'header_text'] as $k) {
            if (!preg_match('~^#[0-9a-f]{6}$~i', (string) $s[$k])) $s[$k] = self::DEFAULTS[$k];
        }
        if (!isset(self::FONTS[$s['font']])) $s['font'] = 'system';
        foreach (['header_blocks', 'footer_blocks'] as $k) $s[$k] = Blocks::items($s[$k]);
        $s['radius'] = max(0, min(24, (int) $s['radius']));
        $s['logo_width'] = max(60, min(320, (int) $s['logo_width']));
        return $s;
    }

    public static function save(int $id, string $name, array $s): int
    {
        $data = ['name' => mb_substr(trim($name), 0, 80) ?: __('Design'), 'settings' => json_encode(array_intersect_key($s, self::DEFAULTS) + self::DEFAULTS), 'updated_at' => Repo::now()];
        if ($id > 0) { app()->db->update('newsletter_designs', $data, 'id = :wid', ['wid' => $id]); return $id; }
        return app()->db->insert('newsletter_designs', $data + ['created_at' => Repo::now(), 'sort' => (int) app()->db->fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM newsletter_designs')]);
    }

    public static function delete(int $id): void
    {
        app()->db->query('DELETE FROM newsletter_designs WHERE id = ?', [$id]);
        app()->db->query('UPDATE newsletter_lists SET design_id = 0 WHERE design_id = ?', [$id]);
        app()->db->query('UPDATE newsletter_campaigns SET design_id = 0 WHERE design_id = ?', [$id]);
    }

    /** Formularfelder des Design-Editors */
    public static function fields(): array
    {
        $font = ['system' => __('System (wie Apple Mail, Outlook, Android)'), 'helvetica' => 'Helvetica / Arial', 'verdana' => 'Verdana', 'trebuchet' => 'Trebuchet', 'georgia' => __('Georgia (mit Serifen)')];
        return [
            ['type' => 'heading', 'label' => __('Logo')],
            ['name' => 'logo', 'label' => __('Logo des Designs'), 'type' => 'media', 'help' => __('Für den Baustein „Logo“ im Kopf, wenn dort kein eigenes Bild gewählt ist. PNG oder JPG, am besten doppelt so breit wie angezeigt – SVG zeigen viele E-Mail-Programme nicht.')],
            ['name' => 'logo_width', 'label' => __('Breite des Logos (px)'), 'type' => 'number', 'min' => 60, 'max' => 320, 'width' => 'half'],
            ['type' => 'heading', 'label' => __('Schrift und Farben')],
            ['name' => 'font', 'label' => __('Schrift'), 'type' => 'select', 'required' => true, 'options' => $font],
            ['name' => 'accent', 'label' => __('Akzent (Linie, Links, Knopf)'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'button_text', 'label' => __('Schrift auf dem Knopf'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'heading', 'label' => __('Überschriften'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'text', 'label' => __('Text'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'muted', 'label' => __('Nebentext (Fuß, Bildunterschrift)'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'card', 'label' => __('Inhaltsfläche'), 'type' => 'color', 'width' => 'half'],
            ['name' => 'bg', 'label' => __('Hintergrund'), 'type' => 'color', 'width' => 'half'],
            ['type' => 'heading', 'label' => __('Form')],
            ['name' => 'radius', 'label' => __('Ecken'), 'type' => 'select', 'width' => 'half', 'options' => ['0' => __('eckig'), '4' => __('leicht rund'), '8' => __('rund'), '14' => __('sehr rund')]],
            ['name' => 'button', 'label' => __('Knopf'), 'type' => 'select', 'width' => 'half', 'options' => ['rounded' => __('abgerundet'), 'pill' => __('Pille'), 'square' => __('eckig')]],
            ['name' => 'card_border', 'label' => __('Inhaltsfläche mit Rahmen'), 'type' => 'bool'],
            ['name' => 'dark', 'label' => __('Dunkelmodus der E-Mail-Programme berücksichtigen'), 'type' => 'bool',
                'help' => __('Apple Mail und Outlook stellen die Ausgabe dann dunkel dar, statt Farben selbst zu verfälschen.')],
        ];
    }

    /** Kontrastprobleme (WCAG AA 4,5:1) – Liste von Hinweisen */
    public static function contrastIssues(array $s): array
    {
        $out = [];
        $pairs = [['text', 'card', __('Text auf Inhaltsfläche')], ['heading', 'card', __('Überschriften auf Inhaltsfläche')], ['button_text', 'accent', __('Schrift auf dem Knopf')],
            ['accent', 'card', __('Links (Akzent) auf Inhaltsfläche')], ['muted', 'bg', __('Fuß auf Hintergrund')]];
        if ($s['header'] === 'band' && !$s['header_blocks']) $pairs[] = ['header_text', 'header_bg', __('Schrift der Farbfläche')];
        foreach ($pairs as [$a, $b, $label]) {
            $r = \Core\Contrast::ratio((string) $s[$a], (string) $s[$b]);
            if ($r < 4.5) $out[] = __('{what}: Kontrast {r}:1 (mindestens 4,5:1)', ['what' => $label, 'r' => fmt()->decimal($r, 1)]);
        }
        return $out;
    }

    /** Kopf und Fuß als Bausteine – gespeicherte oder aus den älteren Einstellungen abgeleitete (für den Editor) */
    public static function headerBlocks(array $s): array
    {
        if ($s['blocks_set'] || $s['header_blocks']) return $s['header_blocks'];   // im Editor gestaltet – auch „kein Kopf“
        $logo = ['type' => 'logo', 'data' => ['width' => (int) $s['logo_width'], 'align' => $s['header'] === 'logo_center' ? 'center' : 'left', '_bg' => 'none', '_pad' => 'normal']];
        if ($s['header'] === 'band') $logo['data'] = ['_bg' => 'custom', '_bgc' => $s['header_bg']] + $logo['data'];
        return [$logo, ['type' => 'divider', 'data' => ['style' => 'accent', '_pad' => 'none']]];
    }

    public static function footerBlocks(array $s): array
    {
        if ($s['blocks_set'] || $s['footer_blocks']) return $s['footer_blocks'];
        return [['type' => 'legal', 'data' => ['unsubscribe' => true, 'webversion' => false, 'align' => 'center']]];
    }

    /** Logo-Adresse (PNG/JPG, absolute URL) */
    public static function logoUrl(array $s): string
    {
        $id = (int) ($s['logo'] ?? 0);
        if ($id <= 0 || !($m = \Core\Media::find($id)) || !str_starts_with((string) ($m['mime'] ?? ''), 'image/') || str_contains((string) $m['mime'], 'svg')) return '';
        $w = min(640, (int) $s['logo_width'] * 2);
        $file = \Core\Media::localFile($m, $w, 'png');
        if (is_file($file)) return abs_url(\Core\Media::url($m, $w, 'png'));
        $file = \Core\Media::localFile($m, $w, 'jpg');
        return abs_url(is_file($file) ? \Core\Media::url($m, $w, 'jpg') : \Core\Media::url($m));
    }

    public static function radius(array $s, string $what = 'card'): string
    {
        $r = (int) $s['radius'];
        if ($what === 'button') return match ($s['button']) { 'pill' => '999px', 'square' => '0', default => max(4, $r) . 'px' };
        return $r . 'px';
    }
}
