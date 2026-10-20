<?php
/**
 * Einstellungen. @var array $values @var array $errors @var string $hook @var array $dns @var array $imapLast @var int $hookLast @var array $account
 */
use Core\Fields;
use Klxm\Newsletter\AdminController;
use Klxm\Newsletter\Bounces;
use Klxm\Newsletter\Newsletter;

$mode = Newsletter::mode();
$ok = fn(?bool $v) => $v === null ? '<span class="adm-muted">' . e(__('nicht prüfbar')) . '</span>' : ($v ? icon('check-circle') . ' ' . e(__('vorhanden')) : icon('warning') . ' ' . e(__('fehlt')));
$act = fn(string $a, string $label, string $icon = 'arrows-clockwise') => '<form method="post" action="' . e(url('/admin/newsletter/einstellungen/aktion')) . '">' . csrf_field()
    . '<input type="hidden" name="_action" value="' . e($a) . '"><button class="adm-btn">' . icon($icon) . ' ' . e($label) . '</button></form>';
?>
<?php $kind = ['smtp' => __('eigener Server – Rückläufer über das Bounce-Postfach (IMAP)'), 'mailjet_smtp' => __('Mailjet über SMTP – Rückläufer per Webhook'), 'mailjet_api' => __('Mailjet über die API – Rückläufer per Webhook')][$mode];
      $bs = Bounces::settings(); ?>
<section class="adm-card">
  <h2><?= e(__('So wird versendet')) ?></h2>
  <dl class="adm-dl">
    <div><dt><?= e(__('Konto')) ?></dt><dd><?= e((string) ($account['label'] ?: $account['id'])) ?> – <?= e(\Core\Mail\Accounts::describe($account)) ?> · <a href="<?= e(url('/admin/system#mail')) ?>"><?= e(__('E-Mail-Versand öffnen')) ?></a></dd></div>
    <div><dt><?= e(__('Versandweg')) ?></dt><dd><?= e($kind) ?></dd></div>
    <?php if ($mode === 'smtp'): ?><div><dt><?= e(__('Bounce-Postfach')) ?></dt><dd><?= e(__('Server: {h}', ['h' => $bs['host'] ?: '–'])) ?> · <?= e(__('Benutzer: {u}', ['u' => $bs['user'] ?: '–'])) ?> · <?= e(__('Rücklaufadresse: {a}', ['a' => $bs['address'] ?: '–'])) ?>
      <?php if (!Bounces::imapConfigured()): ?><br><span class="adm-muted"><?= icon('warning') ?> <?= e(__('Unvollständig – das Konto hat keinen Benutzer bzw. kein Passwort. Werte unten unter „Rückläufer“ eintragen.')) ?></span><?php endif; ?></dd></div><?php endif; ?>
  </dl>
  <p class="adm-muted"><?= e(__('Das Konto wählen Sie unten. Für Mailjet: im Konto Server in-v3.mailjet.com (SMTP) oder die Versandart „Mailjet (API)“ – jeweils Benutzer = API-Schlüssel, Passwort = Secret Key. Für die Webhooks nutzt die Erweiterung dieselben Zugangsdaten.')) ?></p>
</section>

<form method="post" action="<?= e(url('/admin/newsletter/einstellungen')) ?>" novalidate>
  <?= csrf_field() ?>
  <section class="adm-card"><div class="adm-fields"><?= Fields::renderForm(AdminController::settingsFields(), $values, $errors, 'f') ?></div></section>
  <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
</form>

<?php if ($mode === 'smtp'): ?>
<section class="adm-card">
  <h2><?= e(__('Bounce-Postfach')) ?></h2>
  <?php if (!Bounces::imapConfigured()): ?><p class="adm-muted"><?= e(__('Noch nicht eingerichtet: Rücklaufadresse und IMAP-Zugang oben eintragen.')) ?></p>
  <?php else: ?>
    <?php if ($imapLast): ?><p><?= e(__('Letzter Abruf: {when}, {n} Nachrichten ausgewertet', ['when' => fmt()->datetime((int) $imapLast['at']), 'n' => (int) ($imapLast['checked'] ?? 0)])) ?><?php if (($imapLast['error'] ?? '') !== ''): ?> – <strong><?= e($imapLast['error']) ?></strong><?php endif; ?></p><?php endif; ?>
    <div class="adm-actions"><?= $act('imap', __('Verbindung testen'), 'plugs-connected') ?><?= $act('poll', __('Jetzt abrufen')) ?></div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="adm-card">
  <h2><?= e(__('Mailjet')) ?></h2>
  <p><?= e(__('Webhook-Adresse für Rückläufer, Beschwerden und Abmeldungen:')) ?></p>
  <p><code><?= e($hook) ?></code></p>
  <p class="adm-muted"><?= $hookLast ? e(__('Letzter Webhook: {when}', ['when' => fmt()->datetime($hookLast)])) : e(__('Noch kein Webhook empfangen.')) ?></p>
  <div class="adm-actions"><?= $act('mailjet', __('Zugang testen'), 'plugs-connected') ?><?= $act('webhooks', __('Webhooks bei Mailjet einrichten'), 'link') ?></div>
</section>
<?php endif; ?>

<section class="adm-card">
  <h2><?= e(__('Zustellbarkeit')) ?></h2>
  <?php if (($dns['domain'] ?? '') === ''): ?><p class="adm-muted"><?= e(__('Kein Absender im Konto eingetragen.')) ?></p><?php else: ?>
  <dl class="adm-dl">
    <div><dt><?= e(__('Absender-Domain')) ?></dt><dd><?= e($dns['domain']) ?></dd></div>
    <div><dt>SPF</dt><dd><?= $ok($dns['spf']) ?><?php if ($dns['spf_record'] !== ''): ?> <code><?= e(mb_substr($dns['spf_record'], 0, 120)) ?></code><?php endif; ?></dd></div>
    <div><dt>DMARC</dt><dd><?= $ok($dns['dmarc']) ?><?= $dns['dmarc_policy'] !== '' ? ' (p=' . e($dns['dmarc_policy']) . ')' : '' ?></dd></div>
    <div><dt>DKIM</dt><dd class="adm-muted"><?= e(__('beim Mailserver bzw. bei Mailjet einrichten (Domain authentifizieren) – hier nicht prüfbar')) ?></dd></div>
  </dl>
  <?php endif; ?>
  <ul class="adm-muted">
    <li><?= e(__('Gmail und Yahoo verlangen für Massenversand SPF, DKIM, DMARC und die Ein-Klick-Abmeldung (macht die Erweiterung automatisch).')) ?></li>
    <li><?= e(__('Eigene Subdomain für den Newsletter (z. B. news.ihre-domain.de) schützt den normalen Mailverkehr.')) ?></li>
    <li><?= e(__('Neue Absender langsam steigern: anfangs wenige hundert Mails am Tag (Einstellung „Mails je Minute“ niedrig halten).')) ?></li>
  </ul>
</section>
