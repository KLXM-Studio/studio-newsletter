<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Ref;
use Klxm\Newsletter\Engine\Template;

/**
 * Baut eine versandfertige Nachricht (Ausgabe, Bestätigung, Test, Vorschau): Betreff, HTML aus Design + Bausteinen
 * (views/mail/layout.php, views/mail/blocks), Textfassung, Kopfzeilen (List-Unsubscribe + One-Click nach RFC 8058, Bezug),
 * Rücklaufadresse (VERP), Message-ID und Absender-Konto des Verteilers (via). Ergebnis ist ein neutrales Array.
 */
final class Message
{
    /** Ausgabe an einen Empfänger ($sub id 0 = Testversand an eine Adresse ohne Empfängerdatensatz) */
    public static function campaign(array $c, array $sub, bool $test = false): array
    {
        $list = Repo::primaryList(Repo::campaignLists($c));
        $unsub = (int) ($sub['id'] ?? 0) > 0 ? Newsletter::unsubscribeUrl($sub, (int) $c['id']) : absolute_url(Newsletter::BASE . '/abmelden');
        $vars = self::vars($sub) + ['unsubscribe' => $unsub, 'webversion' => (int) ($c['id'] ?? 0) ? Newsletter::webUrl($c) : ''];
        $subject = Template::fill((string) $c['subject'], $vars);
        $html = self::html($c, $vars, $list, ['subject' => $subject, 'unsubscribe' => $unsub, 'webversion' => $vars['webversion'], 'test' => $test]);
        return self::envelope($subject, $html, (int) $c['id'], $sub, $unsub) + ['via' => (string) ($list['account'] ?? '')];
    }

    /**
     * HTML einer Ausgabe – auch für Vorschau (Bild-Platzhalter, Beispielname) und Webversion.
     * $o: subject, unsubscribe, webversion, test, preview, dark, design (Einstellungen statt gespeichertem Design – Design-Editor)
     */
    public static function html(array $c, array $vars, ?array $list = null, array $o = []): string
    {
        $s = self::applyList(is_array($o['design'] ?? null) ? $o['design'] : Design::forCampaign($c)['s'], $list);
        $content = Blocks::render(Blocks::of($c), $s, $vars, !empty($o['preview']));
        return self::layout([
            'subject' => (string) ($o['subject'] ?? Template::fill((string) $c['subject'], $vars)),
            'preheader' => Template::fill((string) ($c['preheader'] ?? ''), $vars),
            'content' => $content, 'unsubscribe' => (string) ($o['unsubscribe'] ?? ''), 'webversion' => (string) ($o['webversion'] ?? ''),
            'test' => !empty($o['test']), 'forceDark' => !empty($o['dark']),
        ], $s, $list);
    }

    /** Bestätigungsmail (Double-Opt-in) – Text plus HTML im Design des Verteilers */
    public static function confirm(array $sub, ?array $list = null): array
    {
        $vars = ['name' => (string) ($sub['name'] ?: lt('Leserin, lieber Leser')), 'site' => self::siteName(), 'link' => Newsletter::confirmUrl($sub), 'days' => (string) Newsletter::CONFIRM_DAYS];
        $subject = Template::fill(Newsletter::text('doi_subject'), $vars);
        $text = Template::fill(Newsletter::text('doi_text'), $vars);
        $s = self::applyList(Design::forList($list)['s'], $list);
        $font = Design::FONTS[$s['font']] ?? Design::FONTS['system'];
        $rows = '';
        foreach (preg_split("~\n\s*\n~", $text) ?: [] as $p) {
            $p = trim($p);
            if ($p === $vars['link']) { $rows .= View::row(View::button(lt('Anmeldung bestätigen'), $vars['link'], $s, $font), '8px 36px 16px'); continue; }
            $rows .= '<tr><td class="nl-pad nl-body" style="padding:8px 36px;font-family:' . $font . ';font-size:16px;line-height:1.6;color:' . e($s['text']) . '">' . nl2br(e($p)) . '</td></tr>';
        }
        $html = self::layout(['subject' => $subject, 'preheader' => '', 'content' => $rows, 'unsubscribe' => '', 'webversion' => '', 'test' => false], $s, $list);
        $m = self::envelope($subject, $html, 0, $sub, '');
        $m['text'] = $text;   // eigener Text statt Umwandlung (Link vollständig)
        return $m + ['via' => (string) ($list['account'] ?? '')];
    }

    /** HTML für die Webversion (ohne persönliche Angaben) */
    public static function web(array $c): string
    {
        return self::html($c, self::vars([]) + ['unsubscribe' => '', 'webversion' => ''], Repo::primaryList(Repo::campaignLists($c)));
    }

    /** Platzhalter-Werte */
    public static function vars(array $sub): array
    {
        return ['name' => (string) ($sub['name'] ?? ''), 'email' => (string) ($sub['email'] ?? ''), 'site' => self::siteName()];
    }

    /** Älterer Verteiler mit eigener Farbe: überschreibt den Akzent des Designs */
    private static function applyList(array $s, ?array $list): array
    {
        $c = (string) ($list['color'] ?? '');
        if (preg_match('~^#[0-9a-f]{6}$~i', $c)) $s['accent'] = $c;
        return $s;
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

    private static function layout(array $v, array $s, ?array $list): string
    {
        $footer = trim((string) ($list['footer'] ?? ''));
        $v += ['site' => self::siteName(), 's' => $s, 'footer' => $footer !== '' ? $footer : trim((string) Newsletter::get('footer', '')), 'lang' => app()->lang ?? 'de', 'forceDark' => false];
        return \Core\Theme::capture(dirname(__DIR__) . '/views/mail/layout.php', $v);
    }

    public static function siteName(): string
    {
        return site_name();
    }

    /** Akzentfarbe (Design des Verteilers bzw. erstes Design) – für Bestätigung und Verwaltung */
    public static function color(?array $list = null): string
    {
        return self::applyList(Design::forList($list)['s'], $list)['accent'];
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
}
