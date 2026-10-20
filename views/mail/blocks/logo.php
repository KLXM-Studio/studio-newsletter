<?php
/**
 * Logo (Kopf) mit optionalem Titel und Unterzeile – rechts/links daneben, darunter oder darüber.
 * Logo: eigenes Bild oder Logo des Designs, sonst Name der Website. Platzhalter im Titel: {subject}, {date}, {site}.
 * @var array $d @var array $s @var array $vars @var string $font
 */
use Klxm\Newsletter\Design;
use Klxm\Newsletter\Engine\Template;
use Klxm\Newsletter\View;

$align = in_array($d['align'] ?? 'left', ['left', 'center', 'right'], true) ? $d['align'] : 'left';
$w = max(40, min(560, (int) ($d['width'] ?? 160) ?: 160));
$img = (int) ($d['image'] ?? 0) > 0 ? View::image((int) $d['image'], min(1200, $w * 2)) : null;
$src = $img['src'] ?? Design::logoUrl($s);
$href = View::href($d['link'] ?? '') ?: abs_url(url('/'));
$site = (string) ($vars['site'] ?? '');
$title = trim(Template::fill((string) ($d['title'] ?? ''), $vars));
$sub = trim(Template::fill((string) ($d['subtitle'] ?? ''), $vars));
$pos = in_array($d['title_pos'] ?? '', ['right', 'left', 'below', 'above'], true) ? (string) $d['title_pos'] : 'right';
$size = ['s' => 15, 'm' => 19, 'l' => 26][$d['title_size'] ?? 'm'] ?? 19;

$logo = '<a href="' . e($href) . '" style="text-decoration:none;color:' . e($s['heading']) . '">'
    . ($src !== '' ? '<img src="' . e($src) . '" width="' . $w . '" alt="' . e($site) . '" style="display:inline-block;width:' . $w . 'px;max-width:100%;height:auto;border:0;vertical-align:middle">'
        : '<span class="nl-h" style="font-size:16px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:' . e($s['heading']) . '">' . e($site) . '</span>') . '</a>';
$text = $title === '' && $sub === '' ? '' : ($title !== '' ? '<div class="nl-h" style="font-size:' . $size . 'px;line-height:1.2;font-weight:800;color:' . e($s['heading']) . '">' . e($title) . '</div>' : '')
    . ($sub !== '' ? '<div class="nl-muted" style="margin-top:3px;font-size:13px;line-height:1.4;color:' . e($s['muted']) . '">' . e($sub) . '</div>' : '');
?>
<tr><td class="nl-pad" align="<?= $align ?>" style="padding:18px 36px;font-family:<?= $font ?>;text-align:<?= $align ?>">
<?php if ($text === ''): ?>
  <?= $logo ?>
<?php elseif ($pos === 'below' || $pos === 'above'): ?>
  <?php if ($pos === 'above'): ?><div style="margin-bottom:12px"><?= $text ?></div><?php endif; ?>
  <?= $logo ?>
  <?php if ($pos === 'below'): ?><div style="margin-top:12px"><?= $text ?></div><?php endif; ?>
<?php else: ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <?php $l = '<td valign="middle" style="vertical-align:middle;' . ($src !== '' ? 'width:' . $w . 'px' : 'white-space:nowrap') . '">' . $logo . '</td>';
          $t = '<td valign="middle" align="' . ($pos === 'left' ? 'left' : 'right') . '" style="vertical-align:middle;padding-' . ($pos === 'left' ? 'right' : 'left') . ':16px;text-align:' . ($pos === 'left' ? 'left' : 'right') . '">' . $text . '</td>'; ?>
    <?= $pos === 'left' ? $t . $l : $l . $t ?>
  </tr></table>
<?php endif; ?>
</td></tr>
