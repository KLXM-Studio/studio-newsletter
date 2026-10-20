<?php
/** Zitat mit Akzentlinie. @var array $d @var array $s @var array $vars @var string $font */
use Klxm\Newsletter\View;

if (trim(strip_tags((string) ($d['text'] ?? ''))) === '') return;
?>
<tr><td class="nl-pad" style="padding:16px 36px 8px;font-family:<?= $font ?>">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <td width="4" style="width:4px;background:<?= e($s['accent']) ?>;border-radius:2px">&nbsp;</td>
    <td style="padding:4px 0 4px 18px">
      <div class="nl-body nl-h" style="font-size:19px;line-height:1.45;font-style:italic;color:<?= e($s['heading']) ?>"><?= View::rich((string) $d['text'], $vars) ?></div>
      <?php if (($d['author'] ?? '') !== ''): ?><p class="nl-muted" style="margin:8px 0 0;font-size:14px;color:<?= e($s['muted']) ?>">– <?= View::plain($d['author'], $vars) ?></p><?php endif; ?>
    </td>
  </tr></table>
</td></tr>
