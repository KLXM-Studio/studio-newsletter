<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Mailjet;

use Klxm\Newsletter\Engine\Bounce\Classifier;
use Klxm\Newsletter\Engine\Bounce\Result;
use Klxm\Newsletter\Engine\Ref;

/**
 * Webhook-Ereignisse von Mailjet (Version 2: ein Ereignis oder eine Liste) → Result, gleiche Regeln wie das Bounce-Postfach.
 *
 *  bounce   hard_bounce = true → hard; error_related_to content/spam → block; sonst soft
 *  blocked  Mailjet hat gar nicht erst versendet: recipient → hard (Adresse bei Mailjet bereits als ungültig bekannt), sonst block
 *  spam     Beschwerde → complaint            unsub   Abmeldung über Mailjet → unsubscribe
 *  sent     zugestellt → delivered (setzt den Soft-Bounce-Zähler zurück)   open/click → delivered
 * Bezug über CustomID („nl.{ref}“, siehe Ref).
 */
final class Events
{
    /** @return list<Result> */
    public static function parse(array $payload, string $key): array
    {
        $list = array_is_list($payload) ? $payload : [$payload];
        $out = [];
        foreach ($list as $ev) {
            if (!is_array($ev) || !isset($ev['event'])) continue;
            $out[] = self::one($ev, $key);
            if (count($out) >= 1000) break;
        }
        return $out;
    }

    private static function one(array $e, string $key): Result
    {
        $type = strtolower((string) $e['event']);
        $email = strtolower(trim((string) ($e['email'] ?? '')));
        $custom = (string) ($e['CustomID'] ?? $e['customcampaign'] ?? '');
        $ref = $custom !== '' ? Ref::find(str_starts_with($custom, 'nl.') ? $custom : 'nl.' . $custom, $key) : null;
        $related = strtolower((string) ($e['error_related_to'] ?? ''));
        $err = trim((string) ($e['error'] ?? '') . ' ' . (string) ($e['comment'] ?? ''));
        [$smtp, $status] = Classifier::codes((string) ($e['smtp_reply'] ?? $e['comment'] ?? ''));
        $id = (string) ($e['MessageID'] ?? $e['mj_message_id'] ?? '');
        $t = match ($type) {
            'bounce' => !empty($e['hard_bounce']) ? Result::HARD : (in_array($related, ['content', 'spam'], true) ? Result::BLOCK : Result::SOFT),
            'blocked' => $related === 'recipient' ? Result::HARD : Result::BLOCK,
            'spam' => Result::COMPLAINT,
            'unsub' => Result::UNSUBSCRIBE,
            'sent', 'open', 'click' => Result::DELIVERED,
            default => Result::UNKNOWN,
        };
        $diag = trim($type . ($related !== '' ? ' (' . $related . ')' : '') . ($err !== '' ? ': ' . $err : ''));
        return new Result($t, $email, $status, $smtp, mb_substr($diag, 0, 400), 'mailjet', $ref, $id);
    }
}
