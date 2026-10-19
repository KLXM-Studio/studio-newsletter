<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine\Imap;

/**
 * Schlanker IMAP-Client (RFC 9051/3501) für das Bounce-Postfach – ohne PHP-Erweiterung „imap“ (seit PHP 8.4 nicht mehr
 * im Kern, auf vielen Servern fehlt sie). Kann genau das, was die Auswertung braucht:
 * anmelden, Ordner wählen, ungelesene Nachrichten suchen, Rohdaten holen (begrenzt), als gelesen markieren, verschieben, löschen.
 *
 *   $c = new Client('mail.example.org', 993, 'ssl', 'bounce@example.org', '…');
 *   $c->connect(); $c->select('INBOX');
 *   foreach ($c->search('UNSEEN') as $uid) { $raw = $c->fetch($uid); …; $c->seen($uid); }
 *   $c->logout();
 *
 * Sicherheit: TLS mit Zertifikatsprüfung (abschaltbar nur für eigene Server mit selbst signiertem Zertifikat), Klartext-
 * Anmeldung ohne TLS wird verweigert. Antworten werden zeilenweise mit Längenbegrenzung gelesen.
 */
final class Client
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;
    /** @var list<string> */
    private array $caps = [];
    public int $exists = 0;

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $security = 'ssl',
        private string $user = '',
        private string $pass = '',
        private int $timeout = 20,
        private bool $verifyPeer = true,
    ) {}

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $err = '';
        $sock = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new ImapException("Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: $err ($errno)");
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) throw new ImapException('Unerwartete Begrüßung: ' . self::clip($greeting));
        if ($this->security === 'starttls') {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new ImapException('STARTTLS fehlgeschlagen.');
            }
        } elseif ($this->security !== 'ssl' && !in_array($this->host, ['127.0.0.1', '::1', 'localhost'], true)) {
            // ohne Verschlüsselung nur zum eigenen Rechner (Selbsttest, lokaler Testserver)
            throw new ImapException('Anmeldung ohne Verschlüsselung ist nicht erlaubt (SSL/TLS oder STARTTLS wählen).');
        }
        $this->capabilities();
        $this->command('LOGIN ' . $this->astring($this->user) . ' ' . $this->astring($this->pass));
        $this->capabilities();
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        foreach ($this->command('CAPABILITY') as [$line]) {
            if (preg_match('~^\* CAPABILITY (.+)$~i', $line, $m)) $this->caps = array_map('strtoupper', preg_split('~\s+~', trim($m[1])) ?: []);
        }
        return $this->caps;
    }

    public function has(string $cap): bool
    {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    public function select(string $mailbox = 'INBOX'): int
    {
        foreach ($this->command('SELECT ' . $this->astring($mailbox)) as [$line]) {
            if (preg_match('~^\* (\d+) EXISTS~i', $line, $m)) $this->exists = (int) $m[1];
        }
        return $this->exists;
    }

    /** Ordner anlegen, falls nicht vorhanden (Fehler „existiert schon“ wird ignoriert) */
    public function ensureMailbox(string $mailbox): void
    {
        try { $this->command('CREATE ' . $this->astring($mailbox)); } catch (ImapException) {}
    }

    /** @return list<int> UIDs, aufsteigend, höchstens $limit */
    public function search(string $criteria = 'UNSEEN', int $limit = 200): array
    {
        if (!preg_match('~^[A-Z0-9 "\-:.@_]+$~i', $criteria)) throw new ImapException('Ungültiges Suchkriterium.');
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as [$line]) {
            if (preg_match('~^\* SEARCH\b(.*)$~i', $line, $m)) foreach (preg_split('~\s+~', trim($m[1])) ?: [] as $u) if (ctype_digit($u)) $uids[] = (int) $u;
        }
        sort($uids);
        return array_slice($uids, 0, $limit);
    }

    /** Rohe Nachricht (ohne das Gelesen-Flag zu setzen), höchstens $max Bytes */
    public function fetch(int $uid, int $max = 524288): string
    {
        foreach ($this->command('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . $max . '>)') as [$line, $literal]) {
            if ($literal !== null && preg_match('~FETCH \(.*BODY\[\]~i', $line)) return $literal;
        }
        return '';
    }

    public function seen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Seen)');
    }

    public function delete(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\Deleted)');
    }

    public function expunge(): void
    {
        $this->command('EXPUNGE');
    }

    /** In einen Ordner verschieben (MOVE, sonst COPY + löschen) */
    public function move(int $uid, string $mailbox): void
    {
        if ($this->has('MOVE')) { $this->command('UID MOVE ' . $uid . ' ' . $this->astring($mailbox)); return; }
        $this->command('UID COPY ' . $uid . ' ' . $this->astring($mailbox));
        $this->delete($uid);
    }

    public function logout(): void
    {
        if (!$this->sock) return;
        try { $this->command('LOGOUT'); } catch (\Throwable) {}
        @fclose($this->sock);
        $this->sock = null;
    }

    public function __destruct()
    {
        if ($this->sock) @fclose($this->sock);
    }

    // ------------------------------------------------------------------ Protokoll

    /**
     * Befehl senden und Antwort bis zur getaggten Statuszeile lesen.
     * @return list<array{0: string, 1: ?string}> [Zeile, Literal-Daten oder null]
     */
    private function command(string $cmd): array
    {
        if (!$this->sock) throw new ImapException('Nicht verbunden.');
        $tag = 'K' . (++$this->tag);
        // Literale ({n}) in Argumenten: erst nach „+“ des Servers senden
        $parts = preg_split('~(\{\d+\}\r\n)~', $cmd, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$cmd];
        $this->write($tag . ' ' . array_shift($parts));
        while ($parts) {
            $this->write(array_shift($parts));   // „{n}\r\n“
            $cont = $this->line();
            if (!str_starts_with($cont, '+')) throw new ImapException('Server lehnt Daten ab: ' . self::clip($cont));
            $this->write((string) array_shift($parts));
        }
        $this->write("\r\n");
        $out = [];
        while (true) {
            $line = $this->line();
            $literal = null;
            if (preg_match('~\{(\d+)\}$~', $line, $m)) {
                $literal = $this->read((int) $m[1]);
                $rest = $this->line();   // Rest der Zeile nach dem Literal, z. B. „)“
                $line .= ' ' . $rest;
            }
            if (str_starts_with($line, $tag . ' ')) {
                $resp = substr($line, strlen($tag) + 1);
                // Fehlermeldung des Servers (enthält nie das Passwort – das steht nur im Befehl)
                if (!preg_match('~^OK\b~i', $resp)) throw new ImapException(self::clip($resp));
                return $out;
            }
            $out[] = [$line, $literal];
            if (count($out) > 100000) throw new ImapException('Antwort zu lang.');
        }
    }

    private function write(string $s): void
    {
        if ($s === '') return;
        if (@fwrite($this->sock, $s) === false) throw new ImapException('Schreiben fehlgeschlagen.');
    }

    private function line(): string
    {
        $l = @fgets($this->sock, 65536);
        if ($l === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new ImapException(!empty($meta['timed_out']) ? 'Zeitüberschreitung.' : 'Verbindung unterbrochen.');
        }
        return rtrim($l, "\r\n");
    }

    private function read(int $n): string
    {
        if ($n > 16 * 1024 * 1024) throw new ImapException('Literal zu groß.');
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->sock, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out']) || feof($this->sock)) throw new ImapException('Verbindung beim Lesen unterbrochen.');
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Argument als quoted string oder – bei Sonderzeichen/Umbrüchen/Nicht-ASCII – als Literal */
    private function astring(string $s): string
    {
        if (preg_match('~^[\x20-\x7e]*$~', $s) && !str_contains($s, "\0")) return '"' . addcslashes($s, '"\\') . '"';
        return '{' . strlen($s) . "}\r\n" . $s;
    }

    private static function clip(string $s): string
    {
        return mb_substr(trim($s), 0, 200);
    }
}
