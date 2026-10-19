<?php
/** Überschrift. @var array $d @var array $s @var array $vars @var string $font */
use Klxm\Newsletter\View;

if (trim((string) ($d['text'] ?? '')) === '') return;
$size = ['l' => 28, 'm' => 22, 's' => 18][$d['size'] ?? 'm'] ?? 22;
$align = ($d['align'] ?? 'left') === 'center' ? 'center' : 'left';
?>
<tr><td class="nl-pad" align="<?= $align ?>" style="padding:22px 36px 6px;font-family:<?= $font ?>"><h2 class="nl-h" style="margin:0;font-size:<?= $size ?>px;line-height:1.2;font-weight:800;text-align:<?= $align ?>;color:<?= e($s['heading']) ?>"><?= View::plain($d['text'], $vars) ?></h2></td></tr>
