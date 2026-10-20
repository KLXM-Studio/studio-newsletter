<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Db\Table;

/**
 * Vorlagen für neue Ausgaben: mitgelieferte Bausteinfolgen mit Beispieltexten (BUILTIN) und eigene, aus einer Ausgabe
 * gespeicherte (Tabelle newsletter_templates). Eine Vorlage bringt Bausteine und optional ein Design mit; Bilder bleiben leer
 * (Platzhalter in der Vorschau) – erscheinen in der Mail erst, wenn ein Bild gewählt ist.
 */
final class Templates
{
    public static function table(): array
    {
        return ['newsletter_templates' => fn(Table $t) => $t->id()
            ->column('name', 'string', ['length' => 80, 'null' => false])
            ->column('description', 'string', ['length' => 191, 'default' => ''])
            ->column('blocks', 'longtext')
            ->column('design_id', 'int', ['default' => 0])
            ->column('subject', 'string', ['length' => 191, 'default' => ''])
            ->column('created_at', 'datetime')];
    }

    /** @return array<string, array{name: string, description: string, design: string, subject: string, blocks: list<array>}> */
    public static function builtin(): array
    {
        $p = fn(string ...$x) => '<p>' . implode('</p><p>', $x) . '</p>';
        return [
            'klassisch' => ['name' => __('Klassisch'), 'description' => __('Aufmacher mit Bild, Text und Knopf – für fast alles.'), 'design' => 'klar',
                'subject' => __('Neuigkeiten aus {site}'), 'blocks' => [
                ['type' => 'hero', 'data' => ['kicker' => __('Newsletter'), 'title' => __('Das Wichtigste dieses Monats'), 'text' => $p(__('Ein bis zwei Sätze, worum es in dieser Ausgabe geht – kurz und neugierig machend.')), 'button' => __('Mehr erfahren'), 'link' => '/']],
                ['type' => 'text', 'data' => ['text' => $p(__('Hallo {name},'), __('hier steht der Haupttext. Schreiben Sie, was neu ist, was ansteht und was Ihre Leserinnen und Leser davon haben.'))]],
                ['type' => 'divider', 'data' => ['style' => 'line']],
                ['type' => 'image_text', 'data' => ['title' => __('Ein weiteres Thema'), 'text' => $p(__('Kurzer Text zum Thema mit einem Link zur ausführlichen Seite.')), 'button' => __('Weiterlesen'), 'link' => '/', 'position' => 'left']],
            ]],
            'magazin' => ['name' => __('Magazin'), 'description' => __('Aufmacher und drei Themen-Kacheln – für Ausgaben mit mehreren Beiträgen.'), 'design' => 'klar',
                'subject' => __('{site}: Die Themen dieser Ausgabe'), 'blocks' => [
                ['type' => 'hero', 'data' => ['kicker' => __('Titelthema'), 'title' => __('Die große Geschichte dieser Ausgabe'), 'text' => $p(__('Der Anreißer zum wichtigsten Beitrag.')), 'button' => __('Zum Beitrag'), 'link' => '/']],
                ['type' => 'heading', 'data' => ['text' => __('Außerdem in dieser Ausgabe'), 'size' => 'm']],
                ['type' => 'columns', 'data' => ['items' => [
                    ['title' => __('Thema eins'), 'text' => $p(__('Zwei Zeilen Anreißer.')), 'button' => __('Lesen'), 'link' => '/'],
                    ['title' => __('Thema zwei'), 'text' => $p(__('Zwei Zeilen Anreißer.')), 'button' => __('Lesen'), 'link' => '/'],
                    ['title' => __('Thema drei'), 'text' => $p(__('Zwei Zeilen Anreißer.')), 'button' => __('Lesen'), 'link' => '/'],
                ]]],
                ['type' => 'divider', 'data' => ['style' => 'accent']],
                ['type' => 'quote', 'data' => ['text' => $p(__('„Ein starkes Zitat aus einem der Beiträge.“')), 'author' => __('Name, Funktion')]],
            ]],
            'veranstaltungen' => ['name' => __('Veranstaltungen'), 'description' => __('Die nächsten Termine automatisch aus einer Datentabelle.'), 'design' => 'natur',
                'subject' => __('Die nächsten Termine'), 'blocks' => [
                ['type' => 'heading', 'data' => ['text' => __('Unsere nächsten Termine'), 'size' => 'l']],
                ['type' => 'text', 'data' => ['text' => $p(__('Hallo {name}, das steht in den nächsten Wochen an – wir freuen uns auf Sie!'))]],
                ['type' => 'entries', 'data' => ['count' => 5, 'layout' => 'list', 'upcoming' => true]],
                ['type' => 'button', 'data' => ['label' => __('Alle Termine ansehen'), 'link' => '/', 'align' => 'left']],
            ]],
            'kurzinfo' => ['name' => __('Kurzinfo'), 'description' => __('Nur Überschrift, Text und Knopf – schnell geschrieben, wirkt persönlich.'), 'design' => 'minimal',
                'subject' => __('Kurz notiert'), 'blocks' => [
                ['type' => 'heading', 'data' => ['text' => __('Kurz notiert'), 'size' => 'm']],
                ['type' => 'text', 'data' => ['text' => $p(__('Hallo {name},'), __('eine kurze Nachricht in ein paar Sätzen.'), __('Viele Grüße'))]],
                ['type' => 'button', 'data' => ['label' => __('Mehr dazu'), 'link' => '/', 'style' => 'outline']],
            ]],
            'einladung' => ['name' => __('Einladung'), 'description' => __('Großes Bild, Termin und Anmeldeknopf – mittig gesetzt.'), 'design' => 'warm',
                'subject' => __('Einladung: {site}'), 'blocks' => [
                ['type' => 'image', 'data' => ['width' => 'full']],
                ['type' => 'heading', 'data' => ['text' => __('Sie sind herzlich eingeladen'), 'size' => 'l', 'align' => 'center']],
                ['type' => 'text', 'data' => ['align' => 'center', 'text' => $p(__('<b>Samstag, 14. November, 18 Uhr</b>'), __('Ort, Straße, Stadt'), __('Ein paar Sätze, was die Gäste erwartet.'))]],
                ['type' => 'button', 'data' => ['label' => __('Jetzt anmelden'), 'link' => '/', 'align' => 'center']],
                ['type' => 'divider', 'data' => ['style' => 'space']],
            ]],
            'rueckblick' => ['name' => __('Rückblick'), 'description' => __('Bilder und Stimmen eines Jahres oder einer Veranstaltung.'), 'design' => 'verein',
                'subject' => __('Unser Rückblick'), 'blocks' => [
                ['type' => 'hero', 'data' => ['kicker' => __('Rückblick'), 'title' => __('Was für ein Jahr!'), 'text' => $p(__('Danke an alle, die dabei waren.'))]],
                ['type' => 'image_text', 'data' => ['title' => __('Höhepunkt eins'), 'text' => $p(__('Was passiert ist und warum es besonders war.')), 'position' => 'left']],
                ['type' => 'image_text', 'data' => ['title' => __('Höhepunkt zwei'), 'text' => $p(__('Noch ein schöner Moment.')), 'position' => 'right']],
                ['type' => 'quote', 'data' => ['text' => $p(__('„Eine Stimme aus dem Publikum oder dem Team.“')), 'author' => __('Name')]],
                ['type' => 'button', 'data' => ['label' => __('Alle Bilder ansehen'), 'link' => '/', 'align' => 'center']],
            ]],
        ];
    }

    /** @return list<array> */
    public static function saved(): array
    {
        return app()->db->fetchAll('SELECT * FROM newsletter_templates ORDER BY name');
    }

    public static function find(string $key): ?array
    {
        if (preg_match('~^t(\d+)$~', $key, $m)) {
            $r = app()->db->fetch('SELECT * FROM newsletter_templates WHERE id = ?', [(int) $m[1]]);
            return $r ? ['name' => $r['name'], 'description' => $r['description'], 'subject' => $r['subject'], 'blocks' => Blocks::items($r['blocks']), 'design_id' => (int) $r['design_id']] : null;
        }
        $b = self::builtin()[$key] ?? null;
        if (!$b) return null;
        $design = 0;
        foreach (Design::all() as $d) if ($d['preset'] === $b['design']) { $design = (int) $d['id']; break; }
        return $b + ['design_id' => $design];
    }

    public static function saveFrom(array $c, string $name, string $description = ''): int
    {
        return app()->db->insert('newsletter_templates', ['name' => mb_substr(trim($name), 0, 80) ?: __('Vorlage'), 'description' => mb_substr(trim($description), 0, 191),
            'blocks' => json_encode(Blocks::of($c), JSON_UNESCAPED_UNICODE), 'design_id' => (int) ($c['design_id'] ?? 0), 'subject' => (string) $c['subject'], 'created_at' => Repo::now()]);
    }

    public static function delete(int $id): void
    {
        app()->db->query('DELETE FROM newsletter_templates WHERE id = ?', [$id]);
    }
}
