<?php
/** Knopf. @var array $d @var array $s @var string $font */
use Klxm\Newsletter\View;

$btn = View::button((string) ($d['label'] ?? ''), View::href($d['link'] ?? ''), $s, $font, (string) ($d['align'] ?? 'left'), (string) ($d['style'] ?? 'filled'));
if ($btn === '') return;
?>
<tr><td class="nl-pad" align="<?= ($d['align'] ?? '') === 'center' ? 'center' : 'left' ?>" style="padding:16px 36px 12px"><?= $btn ?></td></tr>
