<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Bounce;

use Klxm\Newsletter\Engine\Mime;
use Klxm\Newsletter\Engine\Ref;

/**
 * Wertet eine Nachricht aus dem Bounce-Postfach aus:
 *  1. Abwesenheitsnotiz / Autoresponder (Auto-Submitted, X-Autoreply, typische Betreffzeilen) → auto (ignorieren)
 *  2. Beschwerde (message/feedback-report, RFC 5965 – Feedback-Loop von GMX, Web.de, Microsoft, Yahoo) → complaint
 *  3. Zustellbericht (message/delivery-status, RFC 3464): Action, Status, Diagnostic-Code je Empfänger
 *  4. Ohne Normformat: SMTP-Codes und Muster im Text (Heuristik)
 * Bezug zur versendeten Mail (Kampagne + Empfänger) über Ref – VERP-Adresse, X-Newsletter-Ref oder Message-ID irgendwo in
 * der Nachricht (eingebettete Originalkopfzeilen genügen).
 */
final class Parser
{
    private const AUTO_SUBJECT = '~^(auto(?:matic)?[ -]?(?:reply|response|antwort)|automatische antwort|abwesenheit|out of (?:the )?office|'
        . 'ich bin (?:zur zeit |derzeit )?nicht|im urlaub|on vacation|away from|réponse automatique|respuesta automática|afwezig)~iu';
    private const BOUNCE_SUBJECT = '~(undeliver|undelivered|delivery (?:status notification|failure|has failed)|returned mail|failure notice|'
        . 'mail delivery (?:failed|system)|nicht zustellbar|unzustellbar|zustellung fehlgeschlagen|unable to deliver|rejected|non[- ]?delivery)~iu';

    public static function parse(string $raw, string $key): Result
    {
        $m = Mime::parse($raw);
        $ref = Ref::find($raw, $key);
        $subject = $m->header('subject');

        // 2. Feedback-Loop (Beschwerde) vor allem anderen – kommt ebenfalls als multipart/report
        if ($fb = $m->find('message/feedback-report')) {
            $f = Mime::fieldBlocks($fb->text())[0] ?? [];
            $type = strtolower($f['feedback-type'] ?? 'abuse');
            $rcpt = self::addr($f['original-rcpt-to'] ?? '');
            return new Result(in_array($type, ['abuse', 'fraud'], true) ? Result::COMPLAINT : Result::UNKNOWN, $rcpt, '', 0,
                'Feedback-Type: ' . $type, 'arf', $ref);
        }

        // 3. Zustellbericht (RFC 3464)
        if ($ds = $m->find('message/delivery-status')) {
            $blocks = Mime::fieldBlocks($ds->text());
            array_shift($blocks);   // Angaben je Nachricht (Reporting-MTA …)
            $best = null;
            foreach ($blocks ?: [[]] as $f) {
                $diag = (string) ($f['diagnostic-code'] ?? '');
                [$smtp, $st2] = Classifier::codes($diag);
                $status = (string) ($f['status'] ?? '') ?: $st2;
                $type = Classifier::classify($status, $smtp, $diag . ' ' . $m->readable(4000), (string) ($f['action'] ?? ''));
                $r = new Result($type, self::addr($f['final-recipient'] ?? $f['original-recipient'] ?? ''), $status, $smtp,
                    self::clip($diag !== '' ? preg_replace('~^\s*smtp;\s*~i', '', $diag) ?? $diag : ''), 'dsn', $ref);
                if (!$best || self::rank($r->type) > self::rank($best->type)) $best = $r;
            }
            return $best->recipient === '' ? $best->with(['recipient' => self::guessRecipient($m)]) : $best;
        }

        // 1. Abwesenheit / Autoresponder
        $auto = strtolower($m->header('auto-submitted'));
        if (($auto !== '' && $auto !== 'no' && !preg_match(self::BOUNCE_SUBJECT, $subject))
            || $m->header('x-autoreply') !== '' || $m->header('x-autorespond') !== '' || $m->header('x-autoresponder') !== ''
            || preg_match('~^(auto_reply|auto-reply)$~i', $m->header('precedence'))
            || preg_match(self::AUTO_SUBJECT, $subject)) {
            return new Result(Result::AUTO, self::addr($m->header('from')), '', 0, self::clip($subject), 'auto', $ref);
        }

        // 4. Heuristik: Text eines Rückläufers ohne Normformat
        $text = $m->readable();
        if (preg_match(self::BOUNCE_SUBJECT, $subject) || preg_match('~mailer-daemon|postmaster~i', $m->header('from'))) {
            [$smtp, $status] = Classifier::codes($text);
            $type = Classifier::classify($status, $smtp, $text);
            return new Result($type === Result::UNKNOWN ? Result::UNKNOWN : $type, self::guessRecipient($m), $status, $smtp,
                self::clip(self::diagnosticLine($text)), 'heuristic', $ref);
        }
        return new Result(Result::UNKNOWN, '', '', 0, self::clip($subject), 'heuristic', $ref);
    }

    /** Schwerster Befund zählt, falls ein Bericht mehrere Empfänger enthält */
    private static function rank(string $type): int
    {
        return ['hard' => 6, 'block' => 5, 'soft' => 4, 'delayed' => 3, 'unknown' => 2, 'delivered' => 1][$type] ?? 0;
    }

    private static function addr(string $v): string
    {
        $v = preg_replace('~^\s*rfc822\s*;\s*~i', '', $v) ?? $v;
        return preg_match('~[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', $v, $m) ? strtolower($m[0]) : '';
    }

    /** Empfänger aus eingebetteter Originalnachricht (To) oder dem Text („<x@y>: …“) */
    private static function guessRecipient(Mime $m): string
    {
        foreach ($m->parts() as $p) {
            if (in_array($p->type, ['message/rfc822', 'text/rfc822-headers', 'message/rfc822-headers'], true)) {
                $inner = $p->children[0] ?? Mime::parse($p->text());
                if (($a = self::addr($inner->header('to'))) !== '') return $a;
            }
        }
        $text = $m->readable(4000);
        if (preg_match('~<([^<>\s]+@[^<>\s]+)>\s*:?~', $text, $x) && !preg_match('~mailer-daemon|postmaster~i', $x[1])) return strtolower($x[1]);
        return '';
    }

    private static function diagnosticLine(string $text): string
    {
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            if (preg_match('~\b[45]\d\d\b|[45]\.\d\.\d|unknown|rejected|full|quota|unbekannt|voll~i', $line)) return trim($line);
        }
        return '';
    }

    private static function clip(string $s): string
    {
        $s = trim(preg_replace('~\s+~', ' ', $s) ?? $s);
        return mb_substr($s, 0, 400);
    }
}
