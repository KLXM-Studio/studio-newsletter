<?php
/** Bild mit Bildunterschrift und Link. @var array $d @var array $s @var array $vars @var bool $preview @var string $font */
use Klxm\Newsletter\View;

$img = View::image((int) ($d['image'] ?? 0));
if (!$img && !$preview) return;
$full = ($d['width'] ?? 'full') === 'full';
$href = View::href($d['link'] ?? '');
$alt = trim((string) ($d['alt'] ?? '')) ?: ($img['alt'] ?? '');
$tag = $img ? '<img class="nl-img" src="' . e($img['src']) . '" width="' . ($full ? 600 : 528) . '" alt="' . e($alt) . '" style="display:block;width:100%;max-width:' . ($full ? 600 : 528) . 'px;height:auto;border:0;' . ($full ? '' : 'border-radius:' . \Klxm\Newsletter\Design::radius($s) . ';') . '">' : View::placeholder($s, 220);
?>
<tr><td<?= $full ? '' : ' class="nl-pad" style="padding:16px 36px 4px"' ?>><?= $href !== '' && $img ? '<a href="' . e($href) . '">' . $tag . '</a>' : $tag ?></td></tr>
<?php if (($d['caption'] ?? '') !== ''): ?><tr><td class="nl-pad nl-muted" style="padding:8px 36px 4px;font-family:<?= $font ?>;font-size:13px;line-height:1.45;color:<?= e($s['muted']) ?>"><?= View::plain($d['caption'], $vars) ?></td></tr><?php endif; ?>
