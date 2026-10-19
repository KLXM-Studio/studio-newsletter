<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * Kleiner MIME-Leser für Rückläufer (Bounces, Feedback-Reports, Abwesenheitsnotizen) – kein vollständiger Parser, aber
 * robust gegen das, was Mailserver tatsächlich schicken: Kopfzeilen mit Fortsetzungen, verschachteltes multipart/*,
 * message/rfc822 bzw. text/rfc822-headers als eingebettete Originalnachricht, base64 und quoted-printable.
 *
 *   $m = Mime::parse($raw);
 *   $m->header('subject'); $m->parts();  // flache Liste aller Teile (Tiefe zuerst)
 */
final class Mime
{
    /** Höchstens so viele Teile/Ebenen – schützt vor präparierten Nachrichten */
    private const MAX_PARTS = 64;
    private const MAX_DEPTH = 6;

    /** @param array<string, list<string>> $headers kleingeschriebene Namen */
    private function __construct(
        public readonly array $headers,
        public readonly string $type,
        public readonly array $params,
        public readonly string $body,
        /** @var list<Mime> */
        public readonly array $children,
    ) {}

    public static function parse(string $raw, int $depth = 0): self
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = strpos($raw, "\n\n");
        $head = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 2);
        $headers = self::headers($head);
        [$type, $params] = self::contentType($headers['content-type'][0] ?? 'text/plain');
        $children = [];
        if ($depth < self::MAX_DEPTH) {
            if (str_starts_with($type, 'multipart/') && ($b = $params['boundary'] ?? '') !== '') {
                foreach (self::splitMultipart($body, $b) as $i => $chunk) {
                    if ($i >= self::MAX_PARTS) break;
                    $children[] = self::parse($chunk, $depth + 1);
                }
            } elseif ($type === 'message/rfc822' || $type === 'message/global') {
                $children[] = self::parse(self::decode($body, $headers), $depth + 1);
            }
        }
        return new self($headers, $type, $params, $body, $children);
    }

    /** Erster Wert einer Kopfzeile (dekodiert, ohne Zeilenumbrüche) */
    public function header(string $name): string
    {
        return self::decodeWords(trim((string) ($this->headers[strtolower($name)][0] ?? '')));
    }

    /** Alle Werte einer Kopfzeile */
    public function headerAll(string $name): array
    {
        return array_map(fn($v) => self::decodeWords(trim($v)), $this->headers[strtolower($name)] ?? []);
    }

    /** Inhalt dekodiert (Transfer-Encoding, Zeichensatz → UTF-8) */
    public function text(): string
    {
        $s = self::decode($this->body, $this->headers);
        $cs = strtolower((string) ($this->params['charset'] ?? 'utf-8'));
        if ($cs !== '' && $cs !== 'utf-8' && $cs !== 'us-ascii' && function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($s, 'UTF-8', $cs);
            if (is_string($conv)) $s = $conv;
        }
        return $s;
    }

    /** @return list<Mime> alle Teile, Tiefe zuerst, ohne die Wurzel */
    public function parts(): array
    {
        $out = [];
        foreach ($this->children as $c) {
            $out[] = $c;
            foreach ($c->parts() as $p) $out[] = $p;
            if (count($out) >= self::MAX_PARTS) break;
        }
        return $out;
    }

    /** Erster Teil eines Typs */
    public function find(string $type): ?self
    {
        foreach ($this->parts() as $p) if ($p->type === $type) return $p;
        return null;
    }

    /** Lesbarer Text der Nachricht (text/plain, sonst text/html ohne Tags) – für Musterprüfung */
    public function readable(int $max = 20000): string
    {
        $all = $this->children ? $this->parts() : [$this];
        $plain = '';
        $html = '';
        foreach ($all as $p) {
            if ($p->type === 'text/plain' && $plain === '') $plain = $p->text();
            elseif ($p->type === 'text/html' && $html === '') $html = $p->text();
        }
        $s = $plain !== '' ? $plain : trim(html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', '', $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return mb_substr($s, 0, $max);
    }

    /**
     * Feldblöcke wie in message/delivery-status (RFC 3464) oder message/feedback-report (RFC 5965): Leerzeilen trennen Blöcke.
     * @return list<array<string, string>> kleingeschriebene Namen
     */
    public static function fieldBlocks(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $out = [];
        foreach (preg_split("~\n\s*\n~", trim($text)) ?: [] as $block) {
            $f = [];
            foreach (self::headers($block) as $k => $v) $f[$k] = trim($v[0]);
            if ($f) $out[] = $f;
        }
        return $out;
    }

    /** @return array<string, list<string>> */
    private static function headers(string $head): array
    {
        $out = [];
        $head = preg_replace("~\n[ \t]+~", ' ', $head) ?? $head;   // Fortsetzungszeilen
        foreach (explode("\n", $head) as $line) {
            $p = strpos($line, ':');
            if ($p === false || $p === 0) continue;
            $name = strtolower(trim(substr($line, 0, $p)));
            if ($name === '' || preg_match('~\s~', $name)) continue;
            $out[$name][] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $v): array
    {
        $parts = explode(';', $v);
        $type = strtolower(trim((string) array_shift($parts)));
        $params = [];
        if (preg_match_all('~([a-z0-9*_-]+)\s*=\s*("([^"]*)"|[^;\s]+)~i', implode(';', $parts), $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $params[strtolower($x[1])] = ($x[3] ?? '') !== '' ? $x[3] : trim($x[2], '"');
        }
        return [$type !== '' ? $type : 'text/plain', $params];
    }

    /** @return list<string> */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $parts = preg_split('~^--' . preg_quote($boundary, '~') . '(--)?[ \t]*$~m', $body) ?: [];
        array_shift($parts);   // Präambel
        $out = [];
        foreach ($parts as $p) {
            $p = preg_replace('~^\n~', '', $p, 1) ?? $p;
            if (trim($p) === '') continue;
            $out[] = $p;
        }
        return $out;
    }

    /** @param array<string, list<string>> $headers */
    private static function decode(string $body, array $headers): string
    {
        $enc = strtolower(trim((string) ($headers['content-transfer-encoding'][0] ?? '')));
        return match ($enc) {
            'base64' => (string) base64_decode(preg_replace('~\s+~', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** RFC 2047 („=?utf-8?Q?…?=“) */
    private static function decodeWords(string $v): string
    {
        if (!str_contains($v, '=?')) return $v;
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($d)) return $d;
        }
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($v) : $v;
    }
}
