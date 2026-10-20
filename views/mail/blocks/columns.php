<?php
/** 2–3 Spalten mit Bild, Überschrift, Text, Link. @var array $d @var array $s @var array $vars @var bool $preview @var string $font */
use Klxm\Newsletter\View;

$items = array_values(array_filter((array) ($d['items'] ?? []), fn($i) => is_array($i) && (trim((string) ($i['title'] ?? '')) !== '' || (int) ($i['image'] ?? 0) > 0 || trim((string) ($i['text'] ?? '')) !== '')));
if (!$items) return;
$items = array_slice($items, 0, 3);
$n = count($items);
$gap = 20;
$w = (int) floor((528 - $gap * ($n - 1)) / $n);
?>
<tr><td class="nl-pad" style="padding:18px 36px 6px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
  <?php foreach ($items as $k => $it): $img = View::image((int) ($it['image'] ?? 0), 600); $href = View::href($it['link'] ?? ''); ?>
    <?php if ($k): ?><td class="nl-col-gap" width="<?= $gap ?>" style="width:<?= $gap ?>px">&nbsp;</td><?php endif; ?>
    <td class="nl-col" width="<?= $w ?>" valign="top" style="width:<?= $w ?>px;vertical-align:top;font-family:<?= $font ?>;padding-bottom:12px">
      <?php if ($img): ?><img class="nl-img" src="<?= e($img['src']) ?>" width="<?= $w ?>" alt="<?= e($img['alt']) ?>" style="display:block;width:100%;max-width:<?= $w ?>px;height:auto;border:0;border-radius:<?= \Klxm\Newsletter\Design::radius($s) ?>;margin-bottom:10px">
      <?php elseif ($preview && (int) ($it['image'] ?? 0) === 0): ?><div style="margin-bottom:10px"><?= View::placeholder($s, 120) ?></div><?php endif; ?>
      <?php if (($it['title'] ?? '') !== ''): ?><h3 class="nl-h" style="margin:0 0 6px;font-size:17px;line-height:1.3;font-weight:800;color:<?= e($s['heading']) ?>"><?= View::plain($it['title'], $vars) ?></h3><?php endif; ?>
      <?php if (trim(strip_tags((string) ($it['text'] ?? ''))) !== ''): ?><div class="nl-body nl-txt" style="margin:0 0 8px;font-size:14px;line-height:1.5;color:<?= e($s['text']) ?>"><?= View::rich((string) $it['text'], $vars) ?></div><?php endif; ?>
      <?php if (($it['button'] ?? '') !== '' && $href !== ''): ?><a href="<?= e($href) ?>" style="font-size:14px;font-weight:700;color:<?= e($s['accent']) ?>;text-decoration:none"><?= e((string) $it['button']) ?> &rarr;</a><?php endif; ?>
    </td>
  <?php endforeach; ?>
  </tr></table>
</td></tr>
