<?php
/** Aufmacher: Bild über die ganze Breite, Dachzeile, große Überschrift, Text, Knopf. @var array $d @var array $s @var array $vars @var bool $preview @var string $font */
use Klxm\Newsletter\View;

$img = View::image((int) ($d['image'] ?? 0));
?>
<?php if ($img): ?><tr><td><img class="nl-img" src="<?= e($img['src']) ?>" width="600" alt="<?= e($img['alt']) ?>" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>
<?php elseif ($preview): ?><tr><td><?= View::placeholder($s, 260) ?></td></tr><?php endif; ?>
<tr><td class="nl-pad" style="padding:28px 36px 8px;font-family:<?= $font ?>">
  <?php if (($d['kicker'] ?? '') !== ''): ?><p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:<?= e($s['accent']) ?>"><?= View::plain($d['kicker'], $vars) ?></p><?php endif; ?>
  <?php if (($d['title'] ?? '') !== ''): ?><h1 class="nl-h" style="margin:0 0 12px;font-size:30px;line-height:1.15;font-weight:800;color:<?= e($s['heading']) ?>"><?= View::plain($d['title'], $vars) ?></h1><?php endif; ?>
  <?php if (trim(strip_tags((string) ($d['text'] ?? ''))) !== ''): ?><div class="nl-body nl-txt" style="margin:0 0 18px;font-size:17px;line-height:1.55;color:<?= e($s['text']) ?>"><?= View::rich((string) $d['text'], $vars) ?></div><?php endif; ?>
  <?= View::button((string) ($d['button'] ?? ''), View::href($d['link'] ?? ''), $s, $font) ?>
</td></tr>
