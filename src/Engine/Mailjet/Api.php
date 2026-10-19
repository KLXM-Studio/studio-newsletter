<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Mailjet;

/**
 * Mailjet-API (https://dev.mailjet.com): Versand über Send API v3.1 (bis 50 Nachrichten je Aufruf), Webhooks
 * (eventcallbackurl) einrichten, Zugang prüfen. Nur cURL – keine SDK-Abhängigkeit, damit der Code in REDAXO 6 ebenso läuft.
 * Aufbau nach dem REDAXO-AddOn FriendsOfREDAXO/mailjet_connect.
 *
 * Nachricht für send(): ['from' => 'a@b', 'from_name' => '…', 'to' => 'x@y', 'to_name' => '…', 'subject' => '…',
 *   'text' => '…', 'html' => '…', 'custom_id' => '…', 'reply_to' => '…', 'headers' => ['List-Unsubscribe' => '…'],
 *   'cc' => […], 'bcc' => […], 'attachments' => [['type' => …, 'name' => …, 'data' => …, 'cid' => …]]]
 */
final class Api
{
    public const HOST = 'https://api.mailjet.com/';
    public const BATCH = 50;
    /** Ereignisse, für die die Erweiterung einen Webhook braucht (open/click nur mit eingeschalteter Messung) */
    public const EVENTS = ['bounce', 'blocked', 'spam', 'unsub', 'sent'];

    /** @var null|callable(string, string, array, ?string): array{0: int, 1: string} Test-Ersatz für HTTP */
    private $http;

    public function __construct(private string $key, private string $secret, private int $timeout = 20, ?callable $http = null)
    {
        $this->key = self::clean($key);
        $this->secret = self::clean($secret);
        $this->http = $http;
    }

    public function configured(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    /** @return array{status: int, body: mixed} */
    public function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        if (!$this->configured()) throw new MailjetException('Mailjet: API-Schlüssel und Secret fehlen.');
        $url = self::HOST . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $json = $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$status, $raw] = $this->http ? ($this->http)($method, $url, ['Authorization: Basic ' . base64_encode($this->key . ':' . $this->secret)], $json)
            : $this->curl($method, $url, $json);
        $body = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 400) {
            $msg = is_array($body) ? (string) ($body['ErrorMessage'] ?? $body['Messages'][0]['Errors'][0]['ErrorMessage'] ?? $body['StatusCode'] ?? '') : '';
            throw new MailjetException('Mailjet antwortet mit ' . $status . ($msg !== '' ? ': ' . $msg : ($status === 401 ? ' (Schlüssel prüfen)' : '')), $status);
        }
        return ['status' => $status, 'body' => $body];
    }

    /** Zugang prüfen (liest die Webhooks – braucht keine besonderen Rechte) */
    public function test(): bool
    {
        return $this->request('GET', 'v3/REST/eventcallbackurl', ['Limit' => 1])['status'] === 200;
    }

    /**
     * Versand (Send API v3.1). $sandbox = Mailjet prüft nur, versendet nicht.
     * @param list<array> $messages höchstens BATCH
     * @return list<array{ok: bool, id: string, error: string}> in derselben Reihenfolge
     */
    public function send(array $messages, bool $sandbox = false, bool $track = false): array
    {
        if (count($messages) > self::BATCH) throw new MailjetException('Mailjet: höchstens ' . self::BATCH . ' Nachrichten je Aufruf.');
        $out = array_fill(0, count($messages), ['ok' => false, 'id' => '', 'error' => '']);
        if (!$messages) return [];
        $payload = ['Messages' => array_map(fn($m) => self::message($m, $track), array_values($messages))];
        if ($sandbox) $payload['SandboxMode'] = true;
        try {
            $res = $this->request('POST', 'v3.1/send', [], $payload);
        } catch (MailjetException $e) {
            // 400 mit Einzelergebnissen: trotzdem je Nachricht auswerten, sonst alle als Fehler
            if ($e->getCode() !== 400) return array_map(fn() => ['ok' => false, 'id' => '', 'error' => $e->getMessage()], $out);
            $res = ['body' => null];
        }
        foreach ((array) ($res['body']['Messages'] ?? []) as $i => $r) {
            if (!isset($out[$i])) continue;
            $ok = strtolower((string) ($r['Status'] ?? '')) === 'success';
            $out[$i] = ['ok' => $ok, 'id' => (string) ($r['To'][0]['MessageID'] ?? $r['To'][0]['MessageUUID'] ?? ''),
                'error' => $ok ? '' : (string) ($r['Errors'][0]['ErrorMessage'] ?? 'Mailjet hat die Nachricht abgelehnt.')];
        }
        return $out;
    }

    /**
     * Webhooks auf $url setzen (je Ereignis einer; vorhandene mit anderer Adresse werden umgestellt).
     * @return array<string, string> Ereignis → created|updated|unchanged
     */
    public function ensureWebhooks(string $url, array $events = self::EVENTS): array
    {
        $have = [];
        foreach ((array) ($this->request('GET', 'v3/REST/eventcallbackurl', ['Limit' => 100])['body']['Data'] ?? []) as $w) {
            $have[strtolower((string) ($w['EventType'] ?? ''))] = $w;
        }
        $out = [];
        foreach ($events as $ev) {
            $w = $have[$ev] ?? null;
            if ($w && (string) ($w['Url'] ?? '') === $url && (int) ($w['Version'] ?? 1) === 2) { $out[$ev] = 'unchanged'; continue; }
            if ($w) {
                $this->request('PUT', 'v3/REST/eventcallbackurl/' . (int) $w['ID'], [], ['Url' => $url, 'Version' => 2, 'Status' => 'alive']);
                $out[$ev] = 'updated';
            } else {
                $this->request('POST', 'v3/REST/eventcallbackurl', [], ['EventType' => $ev, 'Url' => $url, 'Version' => 2, 'Status' => 'alive']);
                $out[$ev] = 'created';
            }
        }
        return $out;
    }

    private static function message(array $m, bool $track): array
    {
        $msg = [
            'From' => ['Email' => (string) $m['from'], 'Name' => (string) ($m['from_name'] ?? '')],
            'To' => [['Email' => (string) $m['to'], 'Name' => (string) ($m['to_name'] ?? '')]],
            'Subject' => (string) $m['subject'],
            'TextPart' => (string) ($m['text'] ?? ''),
            'CustomID' => (string) ($m['custom_id'] ?? ''),
            'TrackOpens' => $track ? 'enabled' : 'disabled',
            'TrackClicks' => $track ? 'enabled' : 'disabled',
        ];
        if (($m['html'] ?? '') !== '') $msg['HTMLPart'] = (string) $m['html'];
        if (($m['reply_to'] ?? '') !== '') $msg['ReplyTo'] = ['Email' => (string) $m['reply_to']];
        if (!empty($m['headers'])) $msg['Headers'] = array_map('strval', (array) $m['headers']);
        // Anhänge / eingebettete Bilder: [['type' => 'application/pdf', 'name' => 'x.pdf', 'data' => '…', 'cid' => '' (nur eingebettet)]]
        foreach ((array) ($m['attachments'] ?? []) as $a) {
            $item = ['ContentType' => (string) ($a['type'] ?? 'application/octet-stream'), 'Filename' => (string) ($a['name'] ?? 'datei'), 'Base64Content' => base64_encode((string) ($a['data'] ?? ''))];
            if (($a['cid'] ?? '') !== '') $msg['InlinedAttachments'][] = $item + ['ContentID' => (string) $a['cid']];
            else $msg['Attachments'][] = $item;
        }
        foreach (['cc' => 'Cc', 'bcc' => 'Bcc'] as $k => $field) {
            foreach ((array) ($m[$k] ?? []) as $addr) $msg[$field][] = ['Email' => (string) $addr];
        }
        if ($msg['To'][0]['Name'] === '') unset($msg['To'][0]['Name']);
        return $msg;
    }

    /** @return array{0: int, 1: string} */
    private function curl(string $method, string $url, ?string $json): array
    {
        if (!function_exists('curl_init')) throw new MailjetException('Mailjet: PHP-Erweiterung cURL fehlt.');
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($json !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERPWD => $this->key . ':' . $this->secret, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
        if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) throw new MailjetException('Mailjet nicht erreichbar: ' . $err);
        return [$status, (string) $raw];
    }

    /** Schlüssel ohne Leerzeichen/Anführungszeichen (häufiger Kopierfehler) */
    private static function clean(string $v): string
    {
        return trim(trim($v), "\"'");
    }
}
