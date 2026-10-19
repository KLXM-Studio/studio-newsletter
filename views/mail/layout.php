<?php
/**
 * Mail-Vorlage (Ausgabe, Bestätigung, Webversion, Vorschau): Tabellen-Layout 600 px mit dem Design ($s, Klxm\Newsletter\Design),
 * Inline-Stile für Outlook, dazu <style> für Programme, die es lesen (Gmail, Apple Mail). Keine Webfonts, keine Skripte, kein Zählpixel.
 * @var string $subject @var string $preheader @var string $content (sicheres HTML der Bausteine) @var string $unsubscribe @var string $webversion
 * @var bool $test @var string $site @var array $s Design @var string $footer @var string $lang @var bool $forceDark (Vorschau „dunkel“)
 */
use Klxm\Newsletter\Design;

$font = Design::FONTS[$s['font']] ?? Design::FONTS['system'];
$r = Design::radius($s);
$logo = Design::logoUrl($s);
$band = $s['header'] === 'band';
$headColor = $band ? $s['header_text'] : $s['heading'];
$forceDark = !empty($forceDark);
$accDark = \Core\Contrast::mix($s['accent'], '#ffffff', 45);
$darkRules = '[style*="color:' . $s['accent'] . '"]{color:' . $accDark . '!important}.nl-body a{color:' . $accDark . '!important}.nl-bg{background:#121212!important}.nl-card{background:#1e1e1e!important;border-color:#333!important}.nl-body,.nl-body p,.nl-body li,.nl-txt{color:#e6e6e6!important}'
    . '.nl-h{color:#ffffff!important}.nl-muted,.nl-muted a{color:#a8a8a8!important}.nl-rule{border-color:#3a3a3a!important}';
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<?php if ($s['dark']): ?><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><?php endif; ?>
<title><?= e($subject) ?></title>
<style>
  body{margin:0;padding:0;background:<?= e($s['bg']) ?>}
  .nl-body p{margin:0 0 16px}
  .nl-body ul,.nl-body ol{margin:0 0 16px;padding-left:22px}
  .nl-body a{color:<?= e($s['accent']) ?>}
  .nl-body h2,.nl-body h3{margin:22px 0 10px;line-height:1.25;color:<?= e($s['heading']) ?>}
  @media (max-width:620px){.nl-wrap{width:100%!important}.nl-pad{padding-left:22px!important;padding-right:22px!important}.nl-col{display:block!important;width:100%!important;max-width:100%!important}.nl-col-gap{display:none!important}.nl-img{width:100%!important;height:auto!important}}
  <?php if ($s['dark']): ?>@media (prefers-color-scheme:dark){<?= $darkRules ?>}<?php endif; ?>
  <?php if ($forceDark): ?><?= str_replace('!important', ' !important', $darkRules) ?><?php endif; ?>
</style>
</head>
<body style="margin:0;padding:0;background:<?= e($s['bg']) ?>">
<?php if ($preheader !== ''): ?><div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent"><?= e($preheader) ?><?= str_repeat('&#8199;&#65279;&#847; ', 40) ?></div><?php endif; ?>
<table role="presentation" class="nl-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?= e($s['bg']) ?>">
  <tr><td align="center" style="padding:24px 12px">
    <table role="presentation" class="nl-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">
      <?php if ($test): ?>
      <tr><td style="padding:10px 16px;background:#fff3c4;color:#161616;font-family:<?= $font ?>;font-size:13px;border-radius:6px;margin-bottom:8px"><?= e(__('Testversand – so sieht die Ausgabe für Empfänger aus. Platzhalter sind mit Beispielwerten gefüllt.')) ?></td></tr>
      <tr><td style="height:8px;line-height:8px;font-size:0">&nbsp;</td></tr>
      <?php endif; ?>
      <?php if ($webversion !== ''): ?>
      <tr><td class="nl-muted" align="right" style="padding:0 4px 10px;font-family:<?= $font ?>;font-size:12px;color:<?= e($s['muted']) ?>"><a href="<?= e($webversion) ?>" style="color:<?= e($s['muted']) ?>"><?= e(lt('Im Browser ansehen')) ?></a></td></tr>
      <?php endif; ?>
      <tr><td class="nl-card" style="background:<?= e($s['card']) ?>;border-radius:<?= $r ?>;overflow:hidden;<?= $s['card_border'] ? 'border:1px solid rgba(0,0,0,.08);' : '' ?>">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td class="nl-pad" align="<?= $s['header'] === 'logo_center' ? 'center' : 'left' ?>" style="padding:22px 36px;<?= $band ? 'background:' . e($s['header_bg']) . ';' : 'border-top:4px solid ' . e($s['accent']) . ';' ?>font-family:<?= $font ?>">
            <?php if ($logo !== '' && $s['header'] !== 'name'): ?>
              <img src="<?= e($logo) ?>" width="<?= (int) $s['logo_width'] ?>" alt="<?= e($site) ?>" style="display:block;<?= $s['header'] === 'logo_center' ? 'margin:0 auto;' : '' ?>width:<?= (int) $s['logo_width'] ?>px;max-width:100%;height:auto;border:0">
            <?php else: ?>
              <span class="<?= $band ? '' : 'nl-h' ?>" style="font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?= e($headColor) ?>"><?= e($site) ?></span>
            <?php endif; ?>
          </td></tr>
          <?= $content ?>
          <tr><td style="height:16px;line-height:16px;font-size:0">&nbsp;</td></tr>
        </table>
      </td></tr>
      <tr><td class="nl-muted" style="padding:20px 16px 8px;font-family:<?= $font ?>;font-size:12px;line-height:1.55;color:<?= e($s['muted']) ?>;text-align:center">
        <?php if ($footer !== ''): ?><?= nl2br(e($footer)) ?><br><br><?php endif; ?>
        <?php if ($unsubscribe !== ''): ?><?= e(lt('Sie erhalten diese E-Mail, weil Sie den Newsletter bestellt haben.')) ?> <a href="<?= e($unsubscribe) ?>" style="color:<?= e($s['muted']) ?>"><?= e(lt('Abmelden')) ?></a><?php endif; ?>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
