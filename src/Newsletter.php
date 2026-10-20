<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Bounce\Policy;
use Klxm\Newsletter\Engine\Mailjet\Api;

/**
 * Zentrale Angaben und Einstellungen der Erweiterung (Studio-Anbindung). Die Fachlogik liegt in src/Engine (ohne CMS).
 *
 * Versandarten (newsletter.mode):
 *  smtp          eigener SMTP-Server (Konto für den Zweck „Newsletter“), Rückläufer per VERP ins Bounce-Postfach (IMAP)
 *  mailjet_smtp  Konto mit Mailjet-SMTP (in-v3.mailjet.com), Rückläufer per Webhook von Mailjet
 *  mailjet_api   Mailjet Send API v3.1 (50 je Aufruf), Rückläufer per Webhook
 * Außerhalb der Produktion (und bei Test-Umleitung) geht jede Mail über Core\Mailer – also umgeleitet oder nur protokolliert.
 */
final class Newsletter
{
    public const NAME = 'newsletter';
    public const FEATURE = 'newsletter';
    public const PERM = 'newsletter.manage';
    public const PURPOSE = 'ext:newsletter.send';
    public const BASE = '/newsletter';
    public const MODES = ['smtp', 'mailjet_smtp', 'mailjet_api'];
    /** Bestätigungslink gültig (Tage) */
    public const CONFIRM_DAYS = 7;

    public static function on(): bool
    {
        return \Core\Extensions::isActive(self::NAME) && \Core\Features::on(self::FEATURE);
    }

    public static function asset(string $path): string
    {
        $x = \Core\Extensions::active()[self::NAME] ?? null;
        return $x ? $x->asset($path) : '';
    }

    public static function key(): string
    {
        return app()->key();
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        return app()->settings->get('newsletter.' . $name, $default);
    }

    public static function set(string $name, mixed $value): void
    {
        app()->settings->set('newsletter.' . $name, $value);
    }

    /** Geheimnis verschlüsselt speichern (leer = unverändert lassen) */
    public static function setSecret(string $name, string $value): void
    {
        if ($value === '') return;
        self::set($name, \Core\Settings::encrypt($value));
    }

    public static function secret(string $name): string
    {
        $v = (string) self::get($name, '');
        if ($v === '') return '';
        try { return \Core\Settings::decrypt($v); } catch (\Throwable) { return ''; }
    }

    /**
     * E-Mail-Konto für den Versand: in den Einstellungen gewählt (Grundeinstellungen → E-Mail-Versand), sonst das Konto des
     * Zwecks „Newsletter versenden“. Ein nicht versandbereites gewähltes Konto fällt auf den Zweck zurück.
     */
    public static function account(): array
    {
        $id = (string) self::get('account', '');
        if ($id !== '' && ($a = \Core\Mail\Accounts::find($id)) && \Core\Mail\Accounts::ready($a)) return $a;
        return \Core\Mail\Accounts::route(self::PURPOSE);
    }

    /** Konto eines Versands: eigenes Konto des Verteilers ($via) oder das des Newsletters */
    public static function accountFor(string $via = ''): array
    {
        if ($via !== '' && ($a = \Core\Mail\Accounts::find($via)) && \Core\Mail\Accounts::ready($a)) return $a;
        return self::account();
    }

    /**
     * Versandweg aus dem Konto: „Mailjet (API)“ → mailjet_api, Server *.mailjet.com bzw. DSN mailjet+… → mailjet_smtp
     * (Rückläufer per Webhook), sonst smtp (eigener Server, Rückläufer per Bounce-Postfach).
     */
    public static function modeFor(array $acc): string
    {
        $t = (string) ($acc['transport'] ?? '');
        if ($t === 'ext:mailjet') return 'mailjet_api';
        if (str_contains(strtolower((string) ($acc['host'] ?? '')), 'mailjet.com') || ($t === 'dsn' && str_starts_with(strtolower((string) ($acc['dsn'] ?? '')), 'mailjet'))) return 'mailjet_smtp';
        return 'smtp';
    }

    public static function mode(): string
    {
        return self::modeFor(self::account());
    }

    public static function usesMailjet(): bool
    {
        return self::mode() !== 'smtp';
    }

    /** Mailjet-API mit den Zugangsdaten des Kontos (Benutzer = API-Schlüssel, Passwort = Secret); ältere Einstellungen als Rückfall */
    public static function mailjet(?array $acc = null): Api
    {
        $acc ??= self::account();
        if (self::modeFor($acc) !== 'smtp' && ($acc['user'] ?? '') !== '' && ($acc['pass'] ?? '') !== '') return new Api((string) $acc['user'], (string) $acc['pass']);
        return new Api((string) self::get('mailjet_key', ''), self::secret('mailjet_secret'));
    }

    public static function policy(): Policy
    {
        return new Policy(max(1, (int) self::get('soft_threshold', 3)), max(7, (int) self::get('soft_window', 60)));
    }

    /** Stapel je Lauf (Minute) */
    public static function batch(): int
    {
        return max(10, min(2000, (int) self::get('batch', 200)));
    }

    /** Geheimer Teil der Webhook-Adresse (einmal erzeugt) */
    public static function hookToken(): string
    {
        $t = (string) self::get('hook_token', '');
        if (!preg_match('~^[a-f0-9]{32}$~', $t)) {
            $t = bin2hex(random_bytes(16));
            self::set('hook_token', $t);
        }
        return $t;
    }

    public static function hookUrl(): string
    {
        return absolute_url(self::BASE . '/hook/mailjet/' . self::hookToken());
    }

    // ------------------------------------------------------------------ Links mit Signatur (ohne gespeicherte Tokens)

    public static function token(string $purpose, array $sub): string
    {
        return (int) $sub['id'] . '-' . substr(hash_hmac('sha256', 'nl-' . $purpose . '|' . (int) $sub['id'] . '|' . (string) $sub['secret'], self::key()), 0, 24);
    }

    /** @return ?array Empfänger zum Token oder null */
    public static function fromToken(string $purpose, string $token): ?array
    {
        if (!preg_match('~^(\d{1,10})-([a-f0-9]{24})$~', $token, $m)) return null;
        $sub = Repo::find((int) $m[1]);
        if (!$sub || !hash_equals(self::token($purpose, $sub), $token)) return null;
        return $sub;
    }

    /** Abmeldelink; $campaign = Ausgabe, aus der abgemeldet wird (Ein-Klick: nur deren Verteiler) */
    public static function unsubscribeUrl(array $sub, int $campaign = 0): string
    {
        return absolute_url(self::BASE . '/abmelden/' . self::token('unsub', $sub)) . ($campaign > 0 ? '?a=' . $campaign : '');
    }

    public static function confirmUrl(array $sub): string
    {
        return absolute_url(self::BASE . '/bestaetigen/' . self::token('confirm', $sub));
    }

    public static function webUrl(array $campaign): string
    {
        $id = (int) $campaign['id'];
        return absolute_url(self::BASE . '/ausgabe/' . $id . '-' . substr(hash_hmac('sha256', 'nl-web|' . $id, self::key()), 0, 16));
    }

    public static function webId(string $token): int
    {
        if (!preg_match('~^(\d{1,10})-([a-f0-9]{16})$~', $token, $m)) return 0;
        return hash_equals(substr(hash_hmac('sha256', 'nl-web|' . (int) $m[1], self::key()), 0, 16), $m[2]) ? (int) $m[1] : 0;
    }

    /** Angebot eines Blocks (Verteiler-IDs) signiert ins Formular – so lassen sich auch interne Verteiler gezielt anbieten */
    public static function signOffer(array $ids): string
    {
        $ids = implode(',', array_values(array_filter(array_map('intval', $ids))));
        return $ids . '.' . substr(hash_hmac('sha256', 'nl-offer|' . $ids, self::key()), 0, 16);
    }

    /** @return list<int> geprüfte Verteiler-IDs eines Angebots (leer bei falscher Signatur) */
    public static function verifyOffer(string $offer): array
    {
        if (!preg_match('~^([0-9,]{1,190})\.([a-f0-9]{16})$~', $offer, $m)) return [];
        if (!hash_equals(substr(hash_hmac('sha256', 'nl-offer|' . $m[1], self::key()), 0, 16), $m[2])) return [];
        return array_values(array_filter(array_map('intval', explode(',', $m[1]))));
    }

    /** Domain für Message-ID und Rücklaufadresse */
    public static function mailDomain(): string
    {
        $b = Bounces::settings()['address'] ?: (string) (self::account()['from'] ?? '');
        if (($at = strrpos($b, '@')) !== false) return substr($b, $at + 1);
        return (string) (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost');
    }

    /** Standardtexte (Website-Sprache, lt()) – leer gespeichert = Standard */
    public static function text(string $name): string
    {
        $v = trim((string) self::get($name, ''));
        if ($v !== '') return $v;
        return match ($name) {
            'consent_text' => lt('Ja, ich möchte den Newsletter per E-Mail erhalten. Die Einwilligung kann ich jederzeit über den Link in jeder Ausgabe widerrufen.'),
            'doi_subject' => lt('Bitte bestätigen Sie Ihre Anmeldung'),
            'doi_text' => lt("Hallo {name},\n\nbitte bestätigen Sie Ihre Anmeldung zum Newsletter von {site} mit diesem Link:\n\n{link}\n\nDer Link ist {days} Tage gültig. Wenn Sie sich nicht angemeldet haben, ignorieren Sie diese E-Mail einfach – Sie erhalten dann keine weiteren Nachrichten."),
            default => '',
        };
    }
}
