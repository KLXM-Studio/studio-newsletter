<?php
/**
 * Mail-Vorlage (Ausgabe, Bestätigung, Webversion): Tabellen-Layout 600 px, Inline-Stile für Outlook, dazu ein <style>
 * für Programme, die es lesen (Gmail, Apple Mail). Keine externen Schriften, keine Skripte, kein Zählpixel.
 * @var string $subject @var string $preheader @var string $body (bereits sicheres HTML) @var string $image @var string $button_label
 * @var string $button_href @var string $unsubscribe @var string $webversion @var bool $test @var string $site @var string $color
 * @var string $footer @var string $lang
 */
$font = "font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?= e($subject) ?></title>
<style>
  body{margin:0;padding:0;background:#f2f2f0}
  .nl-body p{margin:0 0 16px}
  .nl-body h2,.nl-body h3{margin:24px 0 12px;line-height:1.25;color:#161616}
  .nl-body ul,.nl-body ol{margin:0 0 16px;padding-left:22px}
  .nl-body a{color:<?= e($color) ?>}
  @media (max-width:620px){.nl-wrap{width:100%!important}.nl-pad{padding-left:20px!important;padding-right:20px!important}}
  @media (prefers-color-scheme:dark){body,.nl-bg{background:#161616!important}.nl-card{background:#232323!important}.nl-body,.nl-body h2,.nl-body h3{color:#ececec!important}.nl-muted{color:#a8a8a8!important}}
</style>
</head>
<body style="margin:0;padding:0;background:#f2f2f0">
<?php if ($preheader !== ''): ?><div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent"><?= e($preheader) ?><?= str_repeat('&#8199;&#65279;&#847; ', 40) ?></div><?php endif; ?>
<table role="presentation" class="nl-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f2f0">
  <tr><td align="center" style="padding:24px 12px">
    <table role="presentation" class="nl-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">
      <?php if ($test): ?>
      <tr><td style="padding:10px 16px;background:#fff3c4;color:#161616;<?= $font ?>;font-size:13px;border-radius:6px 6px 0 0"><?= e(__('Testversand – so sieht die Ausgabe für Empfänger aus. Platzhalter sind mit Beispielwerten gefüllt.')) ?></td></tr>
      <?php endif; ?>
      <?php if ($webversion !== ''): ?>
      <tr><td class="nl-muted" align="right" style="padding:0 4px 10px;<?= $font ?>;font-size:12px;color:#6b6b6b"><a href="<?= e($webversion) ?>" style="color:#6b6b6b"><?= e(lt('Im Browser ansehen')) ?></a></td></tr>
      <?php endif; ?>
      <tr><td class="nl-card" style="background:#ffffff;border-radius:8px;overflow:hidden">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td class="nl-pad" style="padding:22px 36px;border-top:4px solid <?= e($color) ?>;<?= $font ?>;font-size:14px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#161616"><?= e($site) ?></td></tr>
          <?php if ($image !== ''): ?>
          <tr><td><img src="<?= e($image) ?>" width="600" alt="" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>
          <?php endif; ?>
          <tr><td class="nl-pad nl-body" style="padding:28px 36px 12px;<?= $font ?>;font-size:16px;line-height:1.6;color:#262626">
            <?= $body ?>
          </td></tr>
          <?php if ($button_label !== '' && $button_href !== ''): ?>
          <tr><td class="nl-pad" style="padding:4px 36px 32px">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:6px;background:<?= e($color) ?>">
              <a href="<?= e($button_href) ?>" style="display:inline-block;padding:13px 24px;<?= $font ?>;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px"><?= e($button_label) ?></a>
            </td></tr></table>
          </td></tr>
          <?php endif; ?>
        </table>
      </td></tr>
      <tr><td class="nl-muted" style="padding:20px 16px 8px;<?= $font ?>;font-size:12px;line-height:1.55;color:#6b6b6b;text-align:center">
        <?php if ($footer !== ''): ?><?= nl2br(e($footer)) ?><br><br><?php endif; ?>
        <?php if ($unsubscribe !== ''): ?><?= e(lt('Sie erhalten diese E-Mail, weil Sie den Newsletter bestellt haben.')) ?> <a href="<?= e($unsubscribe) ?>" style="color:#6b6b6b"><?= e(lt('Abmelden')) ?></a><?php endif; ?>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
