<?php
/** Bild neben Text (auf dem Handy untereinander). @var array $d @var array $s @var array $vars @var bool $preview @var string $font */
use Klxm\Newsletter\View;

$img = View::image((int) ($d['image'] ?? 0), 600);
$imgHtml = $img ? '<img class="nl-img" src="' . e($img['src']) . '" width="250" alt="' . e($img['alt']) . '" style="display:block;width:100%;max-width:250px;height:auto;border:0;border-radius:' . \Klxm\Newsletter\Design::radius($s) . '">'
    : ($preview ? View::placeholder($s, 170) : '');
$href = View::href($d['link'] ?? '');
ob_start(); ?>
  <?php if (($d['title'] ?? '') !== ''): ?><h3 class="nl-h" style="margin:0 0 8px;font-size:20px;line-height:1.25;font-weight:800;color:<?= e($s['heading']) ?>"><?= View::plain($d['title'], $vars) ?></h3><?php endif; ?>
  <div class="nl-body" style="font-size:15px;line-height:1.55;color:<?= e($s['text']) ?>"><?= View::rich((string) ($d['text'] ?? ''), $vars) ?></div>
  <?php if (($d['button'] ?? '') !== '' && $href !== ''): ?><p style="margin:4px 0 0"><a href="<?= e($href) ?>" style="color:<?= e($s['accent']) ?>;font-weight:700;text-decoration:none"><?= e((string) $d['button']) ?> &rarr;</a></p><?php endif; ?>
<?php $textHtml = (string) ob_get_clean();
$right = ($d['position'] ?? 'left') === 'right';
$cell = fn(string $c, int $w) => '<td class="nl-col" width="' . $w . '" valign="top" style="width:' . $w . 'px;vertical-align:top;font-family:' . $font . '">' . $c . '</td>';
?>
<tr><td class="nl-pad" style="padding:18px 36px 6px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <?php if ($imgHtml === ''): ?><?= $cell($textHtml, 528) ?>
    <?php elseif ($right): ?><?= $cell($textHtml, 258) ?><td class="nl-col-gap" width="20" style="width:20px">&nbsp;</td><?= $cell($imgHtml, 250) ?>
    <?php else: ?><?= $cell($imgHtml, 250) ?><td class="nl-col-gap" width="20" style="width:20px">&nbsp;</td><?= $cell($textHtml, 258) ?><?php endif; ?>
  </tr></table>
</td></tr>
