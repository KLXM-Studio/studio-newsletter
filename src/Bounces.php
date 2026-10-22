<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Bounce\Parser;
use Klxm\Newsletter\Engine\Bounce\Processor;
use Klxm\Newsletter\Engine\Bounce\Result;
use Klxm\Newsletter\Engine\Imap\Client;
use Klxm\Newsletter\Engine\Mailjet\Events;

/**
 * Rückläufer einsammeln:
 *  - Bounce-Postfach per IMAP (Versandart smtp): Cron-Aufgabe newsletter.bounces alle 5 Minuten, höchstens 200 Nachrichten je Lauf
 *  - Webhook von Mailjet (Versandarten mailjet_*): SiteController::mailjet
 * Verarbeitete Nachrichten im Postfach: als gelesen markieren (Standard), in einen Ordner verschieben oder löschen.
 */
final class Bounces
{
    /**
     * Vorgaben aus dem E-Mail-Konto (eigener Server): Rücklaufadresse = Absender, IMAP-Server = SMTP-Server mit „imap.“ statt
     * „smtp.“/„mail.“ bleibt, Benutzer und Passwort = die des Kontos. So genügt ein Konto; eigene Werte überschreiben.
     * @return array{address: string, host: string, user: string, pass: string}
     */
    public static function defaults(?array $acc = null): array
    {
        $acc ??= Newsletter::account();
        if (Newsletter::modeFor($acc) !== 'smtp') return ['address' => '', 'host' => '', 'user' => '', 'pass' => ''];
        $host = strtolower(trim((string) ($acc['host'] ?? '')));
        if (str_starts_with($host, 'smtp.')) $host = 'imap.' . substr($host, 5);
        return ['address' => (string) ($acc['from'] ?? ''), 'host' => $host, 'user' => (string) ($acc['user'] ?? ''), 'pass' => (string) ($acc['pass'] ?? '')];
    }

    /** Wirksame Werte: eigene Einstellungen, sonst aus dem Konto */
    public static function settings(): array
    {
        $d = self::defaults();
        return ['address' => trim((string) Newsletter::get('bounce_address', '')) ?: $d['address'], 'host' => trim((string) Newsletter::get('imap_host', '')) ?: $d['host'],
            'user' => trim((string) Newsletter::get('imap_user', '')) ?: $d['user'], 'pass' => Newsletter::secret('imap_pass') ?: $d['pass']];
    }

    public static function imapConfigured(): bool
    {
        $s = self::settings();
        return $s['host'] !== '' && $s['user'] !== '' && $s['pass'] !== '';
    }

    public static function client(): Client
    {
        $s = self::settings();
        return new Client($s['host'], (int) Newsletter::get('imap_port', 993) ?: 993, (string) Newsletter::get('imap_security', 'ssl'), $s['user'], $s['pass'],
            20, (bool) Newsletter::get('imap_verify', true));
    }

    /** Verbindung prüfen: Zahl der Nachrichten im Ordner */
    public static function test(): array
    {
        $c = self::client();
        try {
            $c->connect();
            $n = $c->select((string) Newsletter::get('imap_folder', 'INBOX') ?: 'INBOX');
            $unseen = count($c->search('UNSEEN', 10000));
            return ['ok' => true, 'messages' => $n, 'unseen' => $unseen];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $c->logout();
        }
    }

    /** @return array{checked: int, by_type: array<string, int>, error: string} */
    public static function poll(int $limit = 200, ?\Closure $log = null): array
    {
        $log ??= fn(string $m) => null;
        $out = ['checked' => 0, 'by_type' => [], 'error' => ''];
        if (!self::imapConfigured()) return $out;
        $c = self::client();
        $processor = new Processor(new Repo(), Newsletter::policy());
        $after = (string) Newsletter::get('imap_after', 'seen');
        $folder = (string) Newsletter::get('imap_folder', 'INBOX') ?: 'INBOX';
        $target = trim((string) Newsletter::get('imap_move_to', '')) ?: 'Newsletter-verarbeitet';
        try {
            $c->connect();
            $c->select($folder);
            if ($after === 'move') $c->ensureMailbox($target);
            foreach ($c->search('UNSEEN', $limit) as $uid) {
                $raw = $c->fetch($uid);
                $r = Parser::parse($raw, Newsletter::key());
                $processor->apply($r, time(), false);   // Postfach: nur mit gültigem Bezug sperren
                $out['checked']++;
                $out['by_type'][$r->type] = ($out['by_type'][$r->type] ?? 0) + 1;
                match ($after) {
                    'delete' => $c->delete($uid),
                    'move' => $c->move($uid, $target),
                    default => $c->seen($uid),
                };
            }
            if ($after !== 'seen') $c->expunge();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            $log('Bounce-Postfach: ' . $e->getMessage());
        } finally {
            $c->logout();
        }
        Newsletter::set('imap_last', json_encode(['at' => time(), 'checked' => $out['checked'], 'error' => $out['error']]));
        if ($out['checked']) $log('Rückläufer: ' . $out['checked'] . ' (' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($out['by_type']), $out['by_type'])) . ')');
        return $out;
    }

    /** Webhook-Daten von Mailjet verarbeiten */
    public static function mailjet(array $payload): int
    {
        $processor = new Processor(new Repo(), Newsletter::policy());
        $n = 0;
        foreach (Events::parse($payload, Newsletter::key()) as $r) {
            // „sent“ ohne Bezug ist uninteressant (Mails anderer Herkunft über dasselbe Mailjet-Konto)
            if ($r->type === Result::DELIVERED && !$r->ref) continue;
            $processor->apply($r, time());
            $n++;
        }
        Newsletter::set('hook_last', time());
        return $n;
    }
}
