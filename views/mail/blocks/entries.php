<?php
/**
 * Einträge einer Datentabelle (z. B. Termine, Beiträge): Titel, Bild, Datum, kurzer Text, Link zur Detailseite.
 * Arten: neueste/kommende (Tabelle, Sortierung, „Nur kommende“), gefiltert (Suchbegriff, Feld-Bedingung, Sortierung), ausgewählt
 * (Einträge über den Link-Picker, auch aus mehreren Tabellen). Nur veröffentlichte Einträge.
 * @var array $d @var array $s @var array $vars @var bool $preview @var string $font
 */
use Core\Data\Entries;
use Core\Data\Tables;
use Klxm\Newsletter\View;

$mode = (string) ($d['mode'] ?? 'latest');
$none = function (string $msg) use ($preview, $s): void {
    if ($preview) echo '<tr><td class="nl-pad" style="padding:16px 36px">' . View::placeholder($s, 90, $msg) . '</td></tr>';
};
// Felder eines Eintrags: erstes Datums- und erstes längeres Textfeld (ohne Titel)
$fieldsOf = function (array $t): array {
    $date = $text = '';
    foreach ($t['fields'] as $f) {
        $ty = (string) ($f['type'] ?? '');
        if ($date === '' && in_array($ty, ['date', 'datetime'], true)) $date = (string) $f['name'];
        if ($text === '' && in_array($ty, ['textarea', 'richtext'], true) && (string) $f['name'] !== (string) $t['settings']['title_field']) $text = (string) $f['name'];
    }
    return [$date, $text];
};
$rows = [];   // [Tabelle, Eintrag]
if ($mode === 'pick') {
    foreach ((array) ($d['picks'] ?? []) as $p) {
        if (!is_array($p) || !preg_match('~^entry:([a-z][a-z0-9_]{0,40}):(\d+)$~', (string) ($p['entry'] ?? ''), $m)) continue;
        $pt = Tables::find($m[1]);
        if (!$pt || Tables::isInbox($pt)) continue;
        $e = Entries::find($pt, (int) $m[2]);
        if ($e && ($e['status'] ?? 'published') === 'published') $rows[] = [$pt, $e];
    }
    if (!$rows) { $none(__('Einträge auswählen')); return; }
} else {
    $t = ($ref = (string) ($d['table'] ?? '')) !== '' ? Tables::find($ref) : null;
    if (!$t || Tables::isInbox($t)) { $none(__('Datentabelle wählen')); return; }
    [$dateField] = $fieldsOf($t);
    $names = array_column($t['fields'], 'name');
    $o = ['status' => 'published', 'limit' => max(1, min(12, (int) ($d['count'] ?? 3) ?: 3))];
    $sort = (string) ($d['sort'] ?? '');
    if ($sort !== '' && str_starts_with($sort, $t['handle'] . '.')) { $o['sort'] = substr($sort, strlen($t['handle']) + 1); $o['dir'] = ($d['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'; }
    if ($mode === 'filter') {
        if (trim((string) ($d['q'] ?? '')) !== '') $o['q'] = trim((string) $d['q']);
        $ff = (string) ($d['filter_field'] ?? '');
        $fv = trim((string) ($d['filter_value'] ?? ''));
        if ($ff !== '' && $fv !== '' && str_starts_with($ff, $t['handle'] . '.') && in_array($fn = substr($ff, strlen($t['handle']) + 1), $names, true)) {
            if (mb_strtolower($fv) === 'heute' || mb_strtolower($fv) === 'today') $fv = date('Y-m-d');
            $op = in_array($d['filter_op'] ?? 'contains', ['contains', '=', '!=', '>=', '<='], true) ? (string) $d['filter_op'] : 'contains';
            $o['where'] = [[$fn, $op, $fv]];
        }
    } elseif (!empty($d['upcoming']) && $dateField !== '') {
        $o += ['where' => [[$dateField, '>=', date('Y-m-d')]], 'sort' => $dateField, 'dir' => 'asc'];
    }
    try { foreach (Entries::query($t, $o) as $e) $rows[] = [$t, $e]; } catch (\Throwable) {}
    if (!$rows) { $none(__('Keine passenden Einträge')); return; }
}
$layout = (string) ($d['layout'] ?? 'list');
$items = [];
foreach ($rows as [$t, $e]) {
    [$dateField, $textField] = $fieldsOf($t);
    $imgField = Tables::imageField($t);
    $u = Entries::url($t, $e);
    $items[] = ['title' => Entries::title($t, $e), 'href' => $u !== null ? abs_url($u) : '', 'img' => $imgField !== '' ? View::image((int) ($e[$imgField] ?? 0), 600) : null,
        'date' => $dateField !== '' && !empty($e[$dateField]) ? fmt()->date((string) $e[$dateField], 'long') : '',
        'text' => $textField !== '' ? mb_strimwidth(trim((string) preg_replace('~\s+~u', ' ', Entries::text($t, $e, $textField))), 0, 180, '…') : ''];
}
$r = \Klxm\Newsletter\Design::radius($s);
$title = fn(array $i, int $size) => '<a href="' . e($i['href']) . '" class="nl-h" style="font-size:' . $size . 'px;line-height:1.3;font-weight:800;color:' . e($s['heading']) . ';text-decoration:none">' . e($i['title']) . '</a>';
$more = View::href($d['more'] ?? '');
?>
<tr><td class="nl-pad" style="padding:14px 36px 4px;font-family:<?= $font ?>">
<?php if ($layout === 'cards'): ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <?php foreach (array_chunk($items, 2) as $pair): ?><tr>
    <?php foreach ($pair as $k => $i): ?>
      <?php if ($k): ?><td class="nl-col-gap" width="20" style="width:20px">&nbsp;</td><?php endif; ?>
      <td class="nl-col" width="254" valign="top" style="width:254px;vertical-align:top;padding-bottom:18px">
        <?php if ($i['img']): ?><a href="<?= e($i['href']) ?>"><img class="nl-img" src="<?= e($i['img']['src']) ?>" width="254" alt="" style="display:block;width:100%;max-width:254px;height:auto;border:0;border-radius:<?= $r ?>;margin-bottom:10px"></a><?php endif; ?>
        <?php if ($i['date'] !== ''): ?><p style="margin:0 0 4px;font-size:13px;font-weight:700;color:<?= e($s['accent']) ?>"><?= e($i['date']) ?></p><?php endif; ?>
        <?= $title($i, 17) ?>
        <?php if ($i['text'] !== ''): ?><p class="nl-txt" style="margin:6px 0 0;font-size:14px;line-height:1.5;color:<?= e($s['text']) ?>"><?= e($i['text']) ?></p><?php endif; ?>
      </td>
    <?php endforeach; ?>
    <?php if (count($pair) === 1): ?><td class="nl-col-gap" width="20">&nbsp;</td><td class="nl-col" width="254">&nbsp;</td><?php endif; ?>
  </tr><?php endforeach; ?>
  </table>
<?php else: ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <?php foreach ($items as $i): ?><tr>
    <?php if ($layout === 'list' && $i['img']): ?><td width="120" valign="top" style="width:120px;vertical-align:top;padding:0 16px 18px 0"><a href="<?= e($i['href']) ?>"><img src="<?= e($i['img']['src']) ?>" width="120" alt="" style="display:block;width:120px;height:auto;border:0;border-radius:<?= $r ?>"></a></td><?php endif; ?>
    <td valign="top" style="vertical-align:top;padding:0 0 <?= $layout === 'compact' ? 12 : 18 ?>px;<?= $layout === 'compact' ? 'border-bottom:1px solid ' . e(\Core\Contrast::mix($s['text'], $s['card'], 14)) . ';padding-top:10px;' : '' ?>" <?= $layout === 'list' && $i['img'] ? '' : 'colspan="2"' ?>>
      <?php if ($i['date'] !== ''): ?><p style="margin:0 0 3px;font-size:13px;font-weight:700;color:<?= e($s['accent']) ?>"><?= e($i['date']) ?></p><?php endif; ?>
      <?= $title($i, $layout === 'compact' ? 16 : 18) ?>
      <?php if ($layout === 'list' && $i['text'] !== ''): ?><p class="nl-txt" style="margin:5px 0 0;font-size:14px;line-height:1.5;color:<?= e($s['text']) ?>"><?= e($i['text']) ?></p><?php endif; ?>
    </td>
  </tr><?php endforeach; ?>
  </table>
<?php endif; ?>
<?php if ($more !== ''): ?><p style="margin:2px 0 6px"><a href="<?= e($more) ?>" style="font-size:15px;font-weight:700;color:<?= e($s['accent']) ?>;text-decoration:none"><?= e(lt('Alle ansehen')) ?> &rarr;</a></p><?php endif; ?>
</td></tr>
