<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

/**
 * Rechte im Newsletter:
 *  newsletter.manage  alles – Einstellungen, Designs, Verteiler, Protokoll, alle Ausgaben und Empfänger, Sperren
 *  newsletter.send    Redaktion – Ausgaben schreiben, testen, versenden und Empfänger pflegen, aber nur für die Verteiler,
 *                     die ihr zugewiesen sind (Verteiler → „Wer darf ihn bedienen?“: Rollen und/oder einzelne Personen)
 * Zuweisung je Verteiler in newsletter_lists.editors: „r:{rolle}“ bzw. „u:{benutzer-id}“, durch Komma getrennt.
 */
final class Access
{
    public static function manager(): bool
    {
        return can(Newsletter::PERM);
    }

    public static function any(): bool
    {
        return can(Newsletter::PERM) || can(Newsletter::PERM_SEND);
    }

    /** @return list<int> Verteiler, die die angemeldete Person bedienen darf */
    public static function lists(): array
    {
        $all = Repo::lists();
        if (self::manager()) return array_map('intval', array_column($all, 'id'));
        $u = app()->auth->user();
        if (!$u || !can(Newsletter::PERM_SEND)) return [];
        $mine = ['r:' . (string) ($u['role'] ?? ''), 'u:' . (int) $u['id']];
        return array_values(array_map(fn($l) => (int) $l['id'], array_filter($all, fn($l) => (bool) array_intersect($mine, self::editors($l)))));
    }

    /** @return list<string> Zuweisung eines Verteilers */
    public static function editors(array $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($list['editors'] ?? '')))));
    }

    /** Darf die Person die Ausgabe sehen und bearbeiten? Nur, wenn alle ihre Verteiler zugewiesen sind */
    public static function campaign(array $c): bool
    {
        if (self::manager()) return true;
        $lists = Repo::campaignLists($c);
        return $lists !== [] && !array_diff($lists, self::lists());
    }

    /** Darf die Person den Empfänger sehen? Wenn er in einem ihrer Verteiler steht oder dort angemeldet werden will */
    public static function subscriber(array $s): bool
    {
        if (self::manager()) return true;
        $ids = array_merge(Repo::listIds((int) $s['id']), array_map('intval', array_filter(explode(',', (string) ($s['pending_lists'] ?? '')))));
        return (bool) array_intersect($ids, self::lists());
    }

    /** Auswahl für die Zuweisung: Rollen mit Newsletter-Recht und Personen */
    public static function choices(): array
    {
        $out = [];
        foreach (\Core\Permissions::roles() as $key => $r) {
            if (in_array('*', $r['permissions'], true)) continue;   // Administration darf ohnehin alles
            $out['r:' . $key] = __('Rolle „{name}“', ['name' => $r['name']]) . (in_array(Newsletter::PERM_SEND, $r['permissions'], true) || in_array(Newsletter::PERM, $r['permissions'], true) ? '' : ' ' . __('(ohne Newsletter-Recht)'));
        }
        foreach (app()->db->fetchAll('SELECT id, email, name, role FROM users WHERE disabled = 0 ORDER BY name, email') as $u) {
            $out['u:' . (int) $u['id']] = trim((string) $u['name']) !== '' ? $u['name'] . ' (' . $u['email'] . ')' : (string) $u['email'];
        }
        return $out;
    }
}
