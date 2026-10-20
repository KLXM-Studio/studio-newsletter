<?php
/** Logo (Kopf): eigenes Bild oder Logo des Designs, sonst Name der Website. @var array $d @var array $s @var array $vars @var string $font */
use Klxm\Newsletter\Design;
use Klxm\Newsletter\View;

$align = in_array($d['align'] ?? 'left', ['left', 'center', 'right'], true) ? $d['align'] : 'left';
$w = max(40, min(560, (int) ($d['width'] ?? 160) ?: 160));
$img = (int) ($d['image'] ?? 0) > 0 ? View::image((int) $d['image'], min(1200, $w * 2)) : null;
$src = $img['src'] ?? Design::logoUrl($s);
$href = View::href($d['link'] ?? '') ?: abs_url(url('/'));
$site = (string) ($vars['site'] ?? '');
?>
<tr><td class="nl-pad" align="<?= $align ?>" style="padding:18px 36px;font-family:<?= $font ?>">
  <a href="<?= e($href) ?>" style="text-decoration:none;color:<?= e($s['heading']) ?>">
  <?php if ($src !== ''): ?><img src="<?= e($src) ?>" width="<?= $w ?>" alt="<?= e($site) ?>" style="display:<?= $align === 'left' ? 'block' : 'inline-block' ?>;width:<?= $w ?>px;max-width:100%;height:auto;border:0">
  <?php else: ?><span class="nl-h" style="font-size:16px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:<?= e($s['heading']) ?>"><?= e($site) ?></span><?php endif; ?>
  </a>
</td></tr>
