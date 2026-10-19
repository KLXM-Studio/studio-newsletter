<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Bounce;

/**
 * Ergebnis der Auswertung eines Rückläufers oder Ereignisses eines Versanddienstes.
 *
 *  type     hard | soft | block | complaint | unsubscribe | auto | delayed | delivered | unknown
 *  status   erweiterter Status nach RFC 3463 (z. B. „5.1.1“), sonst ''
 *  smtp     SMTP-Antwortcode (z. B. 550), sonst 0
 *  source   dsn | arf | auto | heuristic | mailjet | smtp
 */
final class Result
{
    public const HARD = 'hard';
    public const SOFT = 'soft';
    public const BLOCK = 'block';
    public const COMPLAINT = 'complaint';
    public const UNSUBSCRIBE = 'unsubscribe';
    public const AUTO = 'auto';
    public const DELAYED = 'delayed';
    public const DELIVERED = 'delivered';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $type,
        public readonly string $recipient = '',
        public readonly string $status = '',
        public readonly int $smtp = 0,
        public readonly string $diagnostic = '',
        public readonly string $source = 'dsn',
        /** @var array{campaign: int, subscriber: int}|null */
        public readonly ?array $ref = null,
        public readonly string $providerId = '',
    ) {}

    public function with(array $changes): self
    {
        $v = get_object_vars($this);
        foreach ($changes as $k => $x) if (array_key_exists($k, $v)) $v[$k] = $x;
        return new self(...$v);
    }

    /** Für Protokoll/JSON */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
