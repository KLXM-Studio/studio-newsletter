<?php
/**
 * Fußtext: Absenderangaben (Rich-Text oder aus Einstellungen/Verteiler), Abmeldelink, Webversion.
 * Abmeldelink: $vars['unsubscribe'] – in Vorschau „#“, in Bestätigungsmails leer (dann ohne Link).
 * @var array $d @var array $s @var array $vars @var string $font
 */
use Klxm\Newsletter\View;

$align = ($d['align'] ?? 'center') === 'left' ? 'left' : 'center';
$text = trim(strip_tags((string) ($d['text'] ?? ''))) !== '' ? View::rich((string) $d['text'], $vars) : nl2br(e((string) ($vars['footer'] ?? '')));
$links = [];
if (!empty($d['webversion']) && ($vars['webversion'] ?? '') !== '') $links[] = '<a href="' . e($vars['webversion']) . '" style="color:' . e($s['muted']) . '">' . e(lt('Im Browser ansehen')) . '</a>';
if (($d['unsubscribe'] ?? true) && ($vars['unsubscribe'] ?? '') !== '') $links[] = e(lt('Sie erhalten diese E-Mail, weil Sie den Newsletter bestellt haben.')) . ' <a href="' . e($vars['unsubscribe']) . '" style="color:' . e($s['muted']) . '">' . e(lt('Abmelden')) . '</a>';
if ($text === '' && !$links) return;
?>
<tr><td class="nl-pad nl-muted nl-body" align="<?= $align ?>" style="padding:14px 36px;font-family:<?= $font ?>;font-size:12px;line-height:1.6;text-align:<?= $align ?>;color:<?= e($s['muted']) ?>">
  <?php if ($text !== ''): ?><div><?= $text ?></div><?php endif; ?>
  <?php if ($links): ?><p style="margin:<?= $text !== '' ? '10px' : '0' ?> 0 0"><?= implode('<br>', $links) ?></p><?php endif; ?>
</td></tr>
