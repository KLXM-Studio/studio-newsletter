<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Ref;
use Klxm\Newsletter\Engine\Template;

/**
 * Baut eine versandfertige Nachricht (Ausgabe, Bestätigung, Test): Betreff, HTML (Vorlage views/mail/layout.php,
 * Tabellen-Layout mit Inline-Stilen), Textfassung, Kopfzeilen (List-Unsubscribe + One-Click nach RFC 8058, Bezug),
 * Rücklaufadresse (VERP) und Message-ID. Ergebnis ist ein neutrales Array – Sender entscheidet über den Versandweg.
 */
final class Message
{
    /** Ausgabe an einen Empfänger ($sub id 0 = Testversand an eine Adresse ohne Empfängerdatensatz) */
    public static function campaign(array $c, array $sub, bool $test = false): array
    {
        $unsub = (int) ($sub['id'] ?? 0) > 0 ? Newsletter::unsubscribeUrl($sub) : absolute_url(Newsletter::BASE . '/abmelden');
        $vars = ['name' => (string) ($sub['name'] ?? ''), 'email' => (string) ($sub['email'] ?? ''), 'unsubscribe' => $unsub, 'webversion' => Newsletter::webUrl($c),
            'site' => self::siteName()];
        $subject = Template::fill((string) $c['subject'], $vars);
        $html = self::layout([
            'subject' => $subject, 'preheader' => Template::fill((string) $c['preheader'], $vars),
            'body' => self::absoluteLinks(Template::fill(rich((string) $c['body']), $vars, true)),
            'image' => self::imageUrl((int) $c['image']), 'button_label' => (string) $c['button_label'], 'button_href' => self::href((string) $c['button_link']),
            'unsubscribe' => $unsub, 'webversion' => $vars['webversion'], 'test' => $test,
        ]);
        return self::envelope($subject, $html, (int) $c['id'], $sub, $unsub);
    }

    /** Bestätigungsmail (Double-Opt-in) – reiner Text plus schlichtes HTML */
    public static function confirm(array $sub): array
    {
        $vars = ['name' => (string) ($sub['name'] ?: lt('Leserin, lieber Leser')), 'site' => self::siteName(), 'link' => Newsletter::confirmUrl($sub), 'days' => (string) Newsletter::CONFIRM_DAYS];
        $subject = Template::fill(Newsletter::text('doi_subject'), $vars);
        $text = Template::fill(Newsletter::text('doi_text'), $vars);
        $paras = '';
        foreach (preg_split("~\n\s*\n~", $text) ?: [] as $p) {
            $p = trim($p);
            if ($p === $vars['link']) { $paras .= '<p><a href="' . e($vars['link']) . '" style="display:inline-block;padding:12px 22px;border-radius:6px;background:' . e(self::color()) . ';color:#ffffff;text-decoration:none;font-weight:700">' . e(lt('Anmeldung bestätigen')) . '</a></p>'; continue; }
            $paras .= '<p>' . nl2br(e($p)) . '</p>';
        }
        $html = self::layout(['subject' => $subject, 'preheader' => '', 'body' => $paras, 'image' => '', 'button_label' => '', 'button_href' => '',
            'unsubscribe' => '', 'webversion' => '', 'test' => false]);
        $m = self::envelope($subject, $html, 0, $sub, '');
        $m['text'] = $text;   // eigener Text statt Umwandlung (Link vollständig)
        return $m;
    }

    /** HTML für die Webversion (ohne persönliche Angaben) */
    public static function web(array $c): string
    {
        $vars = ['name' => '', 'email' => '', 'unsubscribe' => '', 'webversion' => '', 'site' => self::siteName()];
        return self::layout(['subject' => Template::fill((string) $c['subject'], $vars), 'preheader' => '',
            'body' => self::absoluteLinks(Template::fill(rich((string) $c['body']), $vars, true)), 'image' => self::imageUrl((int) $c['image']),
            'button_label' => (string) $c['button_label'], 'button_href' => self::href((string) $c['button_link']), 'unsubscribe' => '', 'webversion' => '', 'test' => false]);
    }

    private static function envelope(string $subject, string $html, int $campaign, array $sub, string $unsub): array
    {
        $sid = (int) ($sub['id'] ?? 0);
        $key = Newsletter::key();
        $ref = Ref::make($campaign, $sid, $key);
        $headers = [Ref::HEADER => $ref, 'Precedence' => 'bulk'];
        if ($unsub !== '' && $sid > 0) {
            $headers['List-Unsubscribe'] = '<' . $unsub . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        $headers['List-Id'] = '<newsletter.' . Newsletter::mailDomain() . '>';
        $bounce = (string) Newsletter::get('bounce_address', '');
        return [
            'to' => (string) ($sub['email'] ?? ''), 'to_name' => (string) ($sub['name'] ?? ''),
            'subject' => $subject, 'html' => $html, 'text' => Template::toText($html), 'headers' => $headers,
            'ref' => $ref, 'custom_id' => 'nl.' . $ref,
            'message_id' => Ref::messageId(Newsletter::mailDomain(), $campaign, $sid, $key),
            'return_path' => Newsletter::mode() === 'smtp' && $bounce !== '' ? Ref::verp($bounce, $campaign, $sid, $key) : '',
        ];
    }

    private static function layout(array $v): string
    {
        $v += ['site' => self::siteName(), 'color' => self::color(), 'footer' => trim((string) Newsletter::get('footer', '')), 'lang' => app()->lang ?? 'de'];
        return \Core\Theme::capture(dirname(__DIR__) . '/views/mail/layout.php', $v);
    }

    public static function siteName(): string
    {
        return site_name();
    }

    public static function color(): string
    {
        $c = (string) Newsletter::get('color', '');
        return preg_match('~^#[0-9a-f]{6}$~i', $c) ? $c : '#1f3a5f';
    }

    private static function imageUrl(int $id): string
    {
        if ($id <= 0 || !($m = \Core\Media::find($id)) || !str_starts_with((string) ($m['mime'] ?? ''), 'image/')) return '';
        // JPEG für E-Mail-Programme ohne WebP (Outlook); fehlt die Größe, das Original
        $file = \Core\Media::localFile($m, 1200, 'jpg');
        return abs_url(is_file($file) ? \Core\Media::url($m, 1200, 'jpg') : \Core\Media::url($m));
    }

    /** Verweis (page:ID, /pfad, https://…) → absolute Adresse */
    public static function href(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';
        if (\Core\Links::isRef($link)) $link = (string) (\Core\Links::href($link) ?? '');
        if ($link === '') return '';
        if (str_starts_with($link, '/')) return abs_url($link);
        return preg_match('~^(https?:|mailto:)~i', $link) ? $link : '';
    }

    /** Relative Links und Bilder im Inhalt absolut machen – E-Mail-Programme kennen die Website nicht */
    private static function absoluteLinks(string $html): string
    {
        return (string) preg_replace_callback('~\b(href|src)="(/[^"/][^"]*|/)"~', fn($m) => $m[1] . '="' . e(abs_url(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"', $html);
    }
}
