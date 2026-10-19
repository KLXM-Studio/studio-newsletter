<?php
// SPDX-License-Identifier: MIT
// KLXM Newsletter – Engine (ohne Abhängigkeit zum CMS). Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter\Engine;

/**
 * DNS-Prüfung der Absender-Domain: SPF (v=spf1 im TXT), DMARC (_dmarc, Richtlinie p=), MX. DKIM lässt sich ohne den
 * Selektor des Mailservers nicht prüfen – nur Hinweis. Gmail und Yahoo verlangen für Massenversand SPF, DKIM und DMARC.
 * $resolver für Tests: fn(string $name, int $type): list<array>
 */
final class Deliverability
{
    /** @return array{domain: string, spf: ?bool, spf_record: string, dmarc: ?bool, dmarc_policy: string, mx: ?bool} null = nicht prüfbar */
    public static function check(string $domain, ?callable $resolver = null): array
    {
        $domain = strtolower(trim($domain, '. '));
        $out = ['domain' => $domain, 'spf' => null, 'spf_record' => '', 'dmarc' => null, 'dmarc_policy' => '', 'mx' => null];
        $resolve = $resolver ?? (function_exists('dns_get_record') ? fn(string $n, int $t) => @dns_get_record($n, $t) ?: [] : null);
        if ($domain === '' || !$resolve || preg_match('~(^|\.)(localhost|test|example|invalid|local)$~', $domain)) return $out;
        $spf = array_values(array_filter(array_map(fn($r) => (string) ($r['txt'] ?? ''), $resolve($domain, DNS_TXT)), fn($t) => stripos($t, 'v=spf1') === 0));
        $out['spf'] = (bool) $spf;
        $out['spf_record'] = $spf[0] ?? '';
        $dm = array_values(array_filter(array_map(fn($r) => (string) ($r['txt'] ?? ''), $resolve('_dmarc.' . $domain, DNS_TXT)), fn($t) => stripos($t, 'v=DMARC1') === 0));
        $out['dmarc'] = (bool) $dm;
        if ($dm && preg_match('~\bp\s*=\s*(none|quarantine|reject)~i', $dm[0], $m)) $out['dmarc_policy'] = strtolower($m[1]);
        $out['mx'] = (bool) $resolve($domain, DNS_MX);
        return $out;
    }
}
