<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Testserver für den Selbsttest des IMAP-Clients (ohne Verschlüsselung, nur 127.0.0.1, eine Verbindung).
// Aufruf: php fake-imap.php {port-datei} {nachricht.eml}
declare(strict_types=1);

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
if (!$srv) exit(1);
file_put_contents($argv[1], (string) parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT));
$raw = (string) file_get_contents($argv[2]);
$c = @stream_socket_accept($srv, 10);
if (!$c) exit(2);
stream_set_timeout($c, 10);
$w = fn(string $s) => fwrite($c, $s . "\r\n");
$w('* OK Testserver bereit');
$flags = [];
while (($line = fgets($c)) !== false) {
    $line = rtrim($line, "\r\n");
    if (preg_match('~\{(\d+)\}$~', $line, $m)) { $w('+ weiter'); $line .= fread($c, (int) $m[1]) . rtrim((string) fgets($c), "\r\n"); }
    [$tag, $cmd] = array_pad(explode(' ', $line, 2), 2, '');
    $u = strtoupper($cmd);
    if (str_starts_with($u, 'CAPABILITY')) { $w('* CAPABILITY IMAP4rev1 MOVE'); $w("$tag OK fertig"); }
    elseif (str_starts_with($u, 'LOGIN')) { $w(str_contains($cmd, '"geheim \"x\""') ? "$tag OK angemeldet" : "$tag NO falsches Passwort"); }
    elseif (str_starts_with($u, 'SELECT')) { $w('* 2 EXISTS'); $w('* 0 RECENT'); $w("$tag OK [READ-WRITE] fertig"); }
    elseif (str_starts_with($u, 'UID SEARCH')) { $w('* SEARCH 9 7'); $w("$tag OK fertig"); }
    elseif (preg_match('~^UID FETCH (\d+)~', $u, $m)) { $w('* 1 FETCH (UID ' . $m[1] . ' BODY[]<0> {' . strlen($raw) . '}'); fwrite($c, $raw); $w(')'); $w("$tag OK fertig"); }
    elseif (preg_match('~^UID (STORE|MOVE) (\d+)~', $u, $m)) { $flags[] = $m[1] . ' ' . $m[2]; $w("$tag OK fertig"); }
    elseif (str_starts_with($u, 'LOGOUT')) { $w('* BYE'); $w("$tag OK tschüss"); break; }
    else { $w("$tag BAD unbekannt"); }
}
file_put_contents($argv[1] . '.log', implode("\n", $flags));
fclose($c);
