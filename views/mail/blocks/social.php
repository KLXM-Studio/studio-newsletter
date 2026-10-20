<?php
/** Social-Media-Profile als Knöpfe oder Textlinks (keine Bild-Icons – SVG zeigen viele E-Mail-Programme nicht). @var array $d @var array $s @var string $font */
use Klxm\Newsletter\Blocks;

$items = [];
foreach ((array) ($d['items'] ?? []) as $it) {
    $u = trim((string) ($it['url'] ?? ''));
    if (!is_array($it) || !preg_match('~^https?://~i', $u)) continue;
    $items[] = [Blocks::NETWORKS[$it['network'] ?? ''] ?? 'Link', $u];
}
if (!$items) return;
$align = ($d['align'] ?? 'center') === 'left' ? 'left' : 'center';
$pill = ($d['style'] ?? 'pill') === 'pill';
$r = \Klxm\Newsletter\Design::radius($s, 'button');
?>
<tr><td class="nl-pad" align="<?= $align ?>" style="padding:12px 36px;font-family:<?= $font ?>;text-align:<?= $align ?>;line-height:2.6">
  <?php foreach ($items as [$label, $u]): ?>
    <?php if ($pill): ?><a href="<?= e($u) ?>" style="display:inline-block;margin:0 4px 6px;padding:6px 14px;border:1.5px solid <?= e($s['accent']) ?>;border-radius:<?= $r ?>;color:<?= e($s['accent']) ?>;font-size:13px;font-weight:700;line-height:1.2;text-decoration:none"><?= e($label) ?></a>
    <?php else: ?><a href="<?= e($u) ?>" style="margin:0 8px;color:<?= e($s['accent']) ?>;font-size:14px;font-weight:700;text-decoration:none"><?= e($label) ?></a><?php endif; ?>
  <?php endforeach; ?>
</td></tr>
