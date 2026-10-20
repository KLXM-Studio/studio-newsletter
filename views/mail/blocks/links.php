<?php
/** Linkleiste (z. B. im Kopf: Website · Termine · Kontakt). @var array $d @var array $s @var string $font */
use Klxm\Newsletter\View;

$items = [];
foreach ((array) ($d['items'] ?? []) as $it) {
    if (!is_array($it) || trim((string) ($it['label'] ?? '')) === '' || ($href = View::href($it['link'] ?? '')) === '') continue;
    $items[] = [(string) $it['label'], $href];
}
if (!$items) return;
$align = in_array($d['align'] ?? 'center', ['left', 'center', 'right'], true) ? $d['align'] : 'center';
$size = (string) ($d['size'] ?? 'm');
$style = 'color:' . e($s['heading']) . ';text-decoration:none;font-weight:700;' . match ($size) { 's' => 'font-size:13px;', 'caps' => 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;', default => 'font-size:15px;' };
?>
<tr><td class="nl-pad" align="<?= $align ?>" style="padding:10px 36px;font-family:<?= $font ?>;text-align:<?= $align ?>;line-height:2">
  <?= implode('<span style="color:' . e($s['muted']) . ';padding:0 10px" aria-hidden="true">·</span>', array_map(fn($i) => '<a href="' . e($i[1]) . '" class="nl-h" style="' . $style . '">' . e($i[0]) . '</a>', $items)) ?>
</td></tr>
