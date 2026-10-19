<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Mailer;
use Klxm\Newsletter\Engine\Bounce\Classifier;
use Klxm\Newsletter\Engine\Bounce\Result;
use Klxm\Newsletter\Engine\Mailjet\Api;

/**
 * Versand eines Stapels fertiger Nachrichten (Message) über die eingestellte Versandart.
 *  smtp / mailjet_smtp  Core\Mailer mit dem Konto des Zwecks „Newsletter“ (Verbindung bleibt für den Stapel offen)
 *  mailjet_api          Mailjet Send API, 50 je Aufruf – nur in der Produktion ohne Test-Umleitung; sonst Core\Mailer
 *                       (umgeleitet oder protokolliert), damit nie aus einer Testumgebung an echte Empfänger versendet wird
 * Lehnt der Server eine Adresse sofort ab (5xx beim Einliefern), entsteht direkt ein Befund (Quelle smtp).
 *
 * @return list<array{ok: bool, id: string, error: string, outcome: string, result: ?Result}> je Nachricht
 */
final class Sender
{
    public static function send(array $messages): array
    {
        if (!$messages) return [];
        $mode = Newsletter::mode();
        $live = environment() === 'production' && !Mailer::testRedirect() && !app()->config->get('mail_dump');
        if ($mode === 'mailjet_api' && $live) return self::viaApi($messages);
        $out = [];
        try {
            foreach ($messages as $m) $out[] = self::viaMailer($m, $mode);
        } finally {
            Mailer::closeTransports();
        }
        return $out;
    }

    private static function viaMailer(array $m, string $mode): array
    {
        $headers = $m['headers'];
        if ($mode === 'mailjet_smtp') {
            // Mailjet-SMTP: Bezug für Webhooks, keine Messung (Datenschutz – nur mit Einstellung)
            $headers['X-MJ-CustomID'] = $m['custom_id'];
            $track = (bool) Newsletter::get('mailjet_track', false);
            $headers['X-Mailjet-TrackOpen'] = $track ? '1' : '0';
            $headers['X-Mailjet-TrackClick'] = $track ? '1' : '0';
        }
        $o = ['purpose' => Newsletter::PURPOSE, 'html' => $m['html'], 'headers' => $headers, 'message_id' => $m['message_id'], 'keep_alive' => true];
        if (($m['via'] ?? '') !== '') $o['via'] = $m['via'];   // eigener Absender des Verteilers
        if ($m['return_path'] !== '') $o['return_path'] = $m['return_path'];
        $err = Mailer::send($m['subject'], $m['text'], [$m['to']], $o);
        if ($err === null) return ['ok' => true, 'id' => '', 'error' => '', 'outcome' => Mailer::$outcome, 'result' => null];
        [$smtp, $status] = Classifier::codes($err);
        $result = null;
        if ($smtp >= 500 || str_starts_with($status, '5.')) {
            $type = Classifier::classify($status, $smtp, $err);
            // Nur Empfänger-Fehler zählen als Rückläufer – Fehler des Kontos (Anmeldung, Relay) nicht
            if (in_array($type, [Result::HARD, Result::SOFT], true) && !preg_match('~auth|relay|sender|from address|absender~i', $err)) {
                $result = new Result($type, $m['to'], $status, $smtp, mb_substr($err, 0, 400), 'smtp');
            }
        }
        return ['ok' => false, 'id' => '', 'error' => mb_substr($err, 0, 400), 'outcome' => 'failed', 'result' => $result];
    }

    private static function viaApi(array $messages): array
    {
        $route = \Core\Mail\Accounts::route(Newsletter::PURPOSE);
        $accOf = function (array $m) use ($route): array {
            $via = ($m['via'] ?? '') !== '' ? \Core\Mail\Accounts::find((string) $m['via']) : null;
            return $via && filter_var($via['from'], FILTER_VALIDATE_EMAIL) ? $via : $route;
        };
        $api = Newsletter::mailjet();
        $out = [];
        foreach (array_chunk($messages, Api::BATCH) as $chunk) {
            $payload = array_map(fn($m) => ($acc = $accOf($m)) ? ['from' => $acc['from'], 'from_name' => (string) ($acc['from_name'] ?? ''), 'reply_to' => (string) ($acc['reply_to'] ?? ''),
                'to' => $m['to'], 'to_name' => $m['to_name'], 'subject' => $m['subject'], 'text' => $m['text'], 'html' => $m['html'],
                'custom_id' => $m['custom_id'], 'headers' => array_diff_key($m['headers'], ['Precedence' => 1])] : [], $chunk);
            try {
                $res = $api->send($payload, (bool) Newsletter::get('mailjet_sandbox', false), (bool) Newsletter::get('mailjet_track', false));
            } catch (\Throwable $e) {
                $res = array_fill(0, count($chunk), ['ok' => false, 'id' => '', 'error' => $e->getMessage()]);
            }
            foreach ($res as $r) $out[] = $r + ['outcome' => $r['ok'] ? 'sent' : 'failed', 'result' => null];
        }
        return $out;
    }
}
