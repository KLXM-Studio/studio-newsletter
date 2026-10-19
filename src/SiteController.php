<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\SpamGuard;

/**
 * Website: /newsletter/…
 *  GET  /newsletter/token                Prüf-Token (Core\SpamGuard) für das Formular – der Block steht auf gecachten Seiten
 *  POST /newsletter/anmelden             Anmeldung (Formular des Blocks; JSON bei fetch, sonst Seite)
 *  GET  /newsletter/bestaetigen/{token}  Double-Opt-in
 *  GET  /newsletter/abmelden/{token}     Seite mit Knopf (Link-Scanner melden so niemanden ab)
 *  POST /newsletter/abmelden/{token}     Abmelden – auch One-Click aus dem E-Mail-Programm (RFC 8058, ohne Sitzung)
 *  GET  /newsletter/ausgabe/{token}      Webversion einer versendeten Ausgabe (ohne persönliche Angaben)
 *  POST /newsletter/hook/mailjet/{token} Webhook von Mailjet (Token in der Adresse)
 */
final class SiteController
{
    public const FORM = 'newsletter';

    private function guard(Request $r): void
    {
        if (!Newsletter::on()) throw new HttpException(404);
        (new \Core\Http\Controllers\FormController())->useLang($r);
    }

    public function page(string $title, string $text, int $status = 200, string $view = 'message', array $vars = []): Response
    {
        $theme = app()->theme;
        $page = ['id' => 0, 'title' => $title, 'slug' => ltrim(Newsletter::BASE, '/'), 'is_home' => 0, 'meta_description' => '', 'noindex' => 1, 'status' => 'published'];
        app()->currentPage = $page;
        $content = '<link rel="stylesheet" href="' . e(Newsletter::asset('css/newsletter.css')) . '">'
            . '<div class="wrap nl-wrap">' . \Core\Theme::capture(dirname(__DIR__) . '/views/site/' . $view . '.php', $vars + ['title' => $title, 'text' => $text]) . '</div>';
        $seo = \Core\Seo::forError(200);
        $seo['title'] = $title;
        $html = $theme->render('layout', ['page' => $page, 'content' => $content, 'seo' => $seo, 'editor' => null, 'toolbar' => null,
            'extraCss' => $theme->conditionalCss(['form'])]);
        return (new \Core\Http\Controllers\SiteController())->respond($html, false, $status, true)->header('Cache-Control', 'private, no-store');
    }

    public function token(Request $r): Response
    {
        $this->guard($r);
        return Response::json(SpamGuard::challenge(self::FORM))->header('Cache-Control', 'no-store');
    }

    public function subscribe(Request $r): Response
    {
        $this->guard($r);
        $in = $r->post;
        $json = $r->wantsJson();
        $fail = fn(string $msg, string $field = '', int $st = 422) => $json ? Response::json(['ok' => false, 'error' => $msg, 'field' => $field], $st)
            : $this->page(lt('Anmeldung nicht möglich'), $msg, $st);
        $spam = SpamGuard::check(self::FORM, $in, $r->ip());
        $done = fn() => $json ? Response::json(['ok' => true, 'message' => lt('Fast geschafft: Bitte bestätigen Sie Ihre Anmeldung über den Link in der E-Mail, die wir Ihnen gerade geschickt haben.')])
            : $this->page(lt('Bitte bestätigen'), lt('Fast geschafft: Bitte bestätigen Sie Ihre Anmeldung über den Link in der E-Mail, die wir Ihnen gerade geschickt haben.'));
        if (!$spam['ok']) return !empty($spam['silent']) ? $done() : $fail((string) ($spam['error'] ?? lt('Bitte versuchen Sie es erneut.')));
        if (empty($in['consent'])) return $fail(lt('Bitte bestätigen Sie, dass Sie den Newsletter erhalten möchten.'), 'consent');
        $res = Signup::subscribe((string) ($in['email'] ?? ''), mb_substr(trim((string) ($in['name'] ?? '')), 0, 120), (array) ($in['lists'] ?? []), $r->ip());
        return $res['ok'] ? $done() : $fail((string) $res['error'], (string) ($res['field'] ?? ''));
    }

    public function confirm(Request $r, string $token): Response
    {
        $this->guard($r);
        return match (Signup::confirm($token)) {
            'ok' => $this->page(lt('Anmeldung bestätigt'), lt('Vielen Dank! Sie erhalten ab jetzt unseren Newsletter. Abmelden können Sie sich jederzeit über den Link am Ende jeder Ausgabe.')),
            'expired' => $this->page(lt('Link abgelaufen'), lt('Dieser Bestätigungslink ist abgelaufen. Bitte melden Sie sich erneut an.'), 410),
            default => $this->page(lt('Link ungültig'), lt('Dieser Bestätigungslink ist ungültig.'), 404),
        };
    }

    public function unsubscribeForm(Request $r, string $token): Response
    {
        $this->guard($r);
        $sub = Newsletter::fromToken('unsub', $token);
        if (!$sub) return $this->page(lt('Link ungültig'), lt('Dieser Abmeldelink ist ungültig. Bitte nutzen Sie den Link aus der neuesten Ausgabe.'), 404);
        if (!in_array($sub['status'], ['active', 'pending'], true)) return $this->page(lt('Abgemeldet'), lt('Sie sind bereits abgemeldet.'));
        return $this->page(lt('Newsletter abbestellen'), lt('Möchten Sie den Newsletter an {email} abbestellen?', ['email' => self::mask((string) $sub['email'])]), 200, 'unsubscribe',
            ['action' => url(Newsletter::BASE . '/abmelden/' . $token)]);
    }

    public function unsubscribe(Request $r, string $token): Response
    {
        $this->guard($r);
        $sub = Newsletter::fromToken('unsub', $token);
        $oneClick = ($r->post['List-Unsubscribe'] ?? '') === 'One-Click';
        if (!$sub) return $oneClick ? new Response('', 404) : $this->page(lt('Link ungültig'), lt('Dieser Abmeldelink ist ungültig.'), 404);
        Signup::unsubscribe($sub, $oneClick ? 'oneclick' : 'link');
        if ($oneClick) return new Response('', 200, ['Content-Type' => 'text/plain']);
        return $this->page(lt('Abgemeldet'), lt('Sie wurden abgemeldet und erhalten keinen Newsletter mehr.'));
    }

    public function web(Request $r, string $token): Response
    {
        $this->guard($r);
        $id = Newsletter::webId($token);
        $c = $id ? Repo::campaign($id) : null;
        if (!$c || !in_array($c['status'], ['sending', 'paused', 'sent'], true)) throw new HttpException(404);
        // eigenes Dokument (Mail-HTML, Inline-Stile) – strenge CSP der Website gilt, Inline-Stile im HTML sind Attribute → erlaubt per eigener CSP
        return (new Response(Message::web($c), 200, ['Content-Type' => 'text/html; charset=utf-8']))
            ->header('Content-Security-Policy', "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->header('X-Robots-Tag', 'noindex');
    }

    public function mailjet(Request $r, string $token): Response
    {
        if (!\Core\Extensions::isActive(Newsletter::NAME)) throw new HttpException(404);
        if (!hash_equals(Newsletter::hookToken(), $token)) return Response::json(['ok' => false], 403);
        if ($r->method !== 'POST') return Response::json(['ok' => true]);   // Prüfaufruf von Mailjet
        try {
            $n = Bounces::mailjet($r->post);
        } catch (\Throwable $e) {
            error_log('[newsletter] Webhook: ' . $e->getMessage());
            return Response::json(['ok' => false], 500);   // Mailjet versucht es erneut
        }
        return Response::json(['ok' => true, 'processed' => $n]);
    }

    private static function mask(string $email): string
    {
        [$l, $d] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($l, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($l) - 2))) . '@' . $d;
    }
}
