<?php
/** Text (Rich-Text). @var array $d @var array $s @var array $vars @var string $font */
use Klxm\Newsletter\View;

if (trim(strip_tags((string) ($d['text'] ?? ''))) === '') return;
$align = ($d['align'] ?? 'left') === 'center' ? 'center' : 'left';
?>
<tr><td class="nl-pad nl-body" style="padding:12px 36px 4px;font-family:<?= $font ?>;font-size:16px;line-height:1.6;text-align:<?= $align ?>;color:<?= e($s['text']) ?>"><?= View::rich((string) $d['text'], $vars) ?></td></tr>
