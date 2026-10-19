<?php
/** Trenner: Linie, kurze Akzentlinie oder nur Abstand. @var array $d @var array $s */
$st = (string) ($d['style'] ?? 'line');
?>
<?php if ($st === 'space'): ?><tr><td style="height:28px;line-height:28px;font-size:0">&nbsp;</td></tr>
<?php elseif ($st === 'accent'): ?><tr><td class="nl-pad" style="padding:20px 36px"><div style="width:56px;height:4px;background:<?= e($s['accent']) ?>;border-radius:2px;font-size:0;line-height:0">&nbsp;</div></td></tr>
<?php else: ?><tr><td class="nl-pad" style="padding:20px 36px"><div class="nl-rule" style="border-top:1px solid <?= e(\Core\Contrast::mix($s['text'], $s['card'], 18)) ?>;font-size:0;line-height:0">&nbsp;</div></td></tr><?php endif; ?>
