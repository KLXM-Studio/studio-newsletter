<?php
/**
 * Ausgabe: Editor (Angaben + Bausteine) mit Live-Vorschau, danach Testen/Versenden – bzw. Bericht für versendete Ausgaben.
 * @var array $campaign @var array $values @var list<array> $blocks @var array $errors @var bool $editable @var int $audience @var list<array> $events
 */
use Core\Fields;
use Klxm\Newsletter\AdminController;
use Klxm\Newsletter\Blocks;
use Klxm\Newsletter\Campaigns;
use Klxm\Newsletter\Newsletter;

$c = $campaign;
$id = (int) $c['id'];
$base = '/admin/newsletter/' . $id;
$status = (string) $c['status'];
$label = ['draft' => __('Entwurf'), 'scheduled' => __('Geplant'), 'sending' => __('Wird versendet'), 'paused' => __('Angehalten'), 'sent' => __('Versendet'), 'cancelled' => __('Abgebrochen')];
$typeLabel = ['hard' => __('Hard Bounce'), 'soft' => __('Soft Bounce'), 'block' => __('Blockiert (Absender)'), 'complaint' => __('Beschwerde'), 'unsubscribe' => __('Abmeldung'),
    'auto' => __('Abwesenheit'), 'delayed' => __('Verzögert'), 'unknown' => __('Unklar')];
$previewBar = function (string $src = '') use ($base): void { ?>
  <div class="nl-preview">
    <div class="nl-preview__bar" role="group" aria-label="<?= e(__('Vorschau')) ?>">
      <button type="button" class="adm-btn adm-btn--ghost is-on" data-nl-device="desktop" aria-pressed="true"><?= icon('desktop') ?> <?= e(__('Desktop')) ?></button>
      <button type="button" class="adm-btn adm-btn--ghost" data-nl-device="mobile" aria-pressed="false"><?= icon('device-mobile') ?> <?= e(__('Handy')) ?></button>
      <button type="button" class="adm-btn adm-btn--ghost" data-nl-dark aria-pressed="false"><?= icon('moon') ?> <?= e(__('Dunkel')) ?></button>
      <span class="nl-preview__state adm-muted" data-nl-state aria-live="polite"></span>
    </div>
    <div class="nl-preview__stage"><iframe class="nl-preview__frame" data-nl-frame title="<?= e(__('Vorschau der E-Mail')) ?>"<?= $src !== '' ? ' src="' . e(url($src)) . '"' : '' ?>></iframe></div>
  </div>
<?php };
?>
<?php if ($status !== 'draft'): $q = Campaigns::rates($c); ?>
<section class="adm-card">
  <h2><?= e(__('Versand')) ?> <span class="adm-badge"><?= e($label[$status] ?? $status) ?></span></h2>
  <dl class="adm-dl">
    <div><dt><?= e(__('Empfänger')) ?></dt><dd><?= e(fmt()->number($c['total'])) ?></dd></div>
    <div><dt><?= e(__('Versendet')) ?></dt><dd><?= e(fmt()->number($c['sent'])) ?><?php if ((int) $c['failed']): ?> · <?= e(__('{n} fehlgeschlagen', ['n' => $c['failed']])) ?><?php endif; ?></dd></div>
    <div><dt><?= e(__('Hard Bounces')) ?></dt><dd><?= e(fmt()->number($c['hard'])) ?> (<?= e(fmt()->decimal($q['hard'], 1)) ?> %)</dd></div>
    <div><dt><?= e(__('Soft Bounces')) ?></dt><dd><?= e(fmt()->number($c['soft'])) ?></dd></div>
    <div><dt><?= e(__('Blockiert')) ?></dt><dd><?= e(fmt()->number($c['block'])) ?></dd></div>
    <div><dt><?= e(__('Beschwerden')) ?></dt><dd><?= e(fmt()->number($c['complaint'])) ?> (<?= e(fmt()->decimal($q['complaint'], 2)) ?> %)</dd></div>
    <div><dt><?= e(__('Abmeldungen')) ?></dt><dd><?= e(fmt()->number($c['unsubscribe'])) ?></dd></div>
    <?php if ($c['started_at']): ?><div><dt><?= e(__('Gestartet')) ?></dt><dd><?= e(fmt()->datetime($c['started_at'])) ?></dd></div><?php endif; ?>
    <?php if ($c['finished_at']): ?><div><dt><?= e(__('Fertig')) ?></dt><dd><?= e(fmt()->datetime($c['finished_at'])) ?></dd></div><?php endif; ?>
    <?php if ($status === 'scheduled'): ?><div><dt><?= e(__('Geplant für')) ?></dt><dd><?= e(fmt()->datetime($c['scheduled_at'])) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($q['bounce'] > 2 && (int) $c['sent'] >= 50): ?><p class="adm-flash adm-flash--warning"><?= icon('warning') ?> <?= e(__('Mehr als 2 % Rückläufer: Die Liste enthält viele alte oder falsche Adressen. Gesperrte Empfänger erhalten künftig nichts mehr.')) ?></p><?php endif; ?>
  <?php if ((int) $c['block'] >= 5): ?><p class="adm-flash adm-flash--warning"><?= icon('warning') ?> <?= e(__('Mehrere Mailserver haben den Absender abgelehnt (Spamverdacht, Sperrliste, SPF/DKIM/DMARC). Einstellungen → Zustellbarkeit prüfen.')) ?></p><?php endif; ?>
  <div class="adm-actions">
    <?php if (in_array($status, ['sending', 'scheduled'], true)): ?>
    <form method="post" action="<?= e(url($base . '/anhalten')) ?>"><?= csrf_field() ?><button class="adm-btn"><?= icon('pause') ?> <?= e($status === 'scheduled' ? __('Planung aufheben') : __('Anhalten')) ?></button></form>
    <?php endif; ?>
    <?php if ($status === 'paused'): ?>
    <form method="post" action="<?= e(url($base . '/senden')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--primary"><?= e(__('Fortsetzen')) ?></button></form>
    <form method="post" action="<?= e(url($base . '/abbrechen')) ?>" data-confirm="<?= e(__('Versand endgültig abbrechen? Wer die Ausgabe noch nicht hat, bekommt sie nicht mehr.')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--danger"><?= e(__('Abbrechen')) ?></button></form>
    <?php endif; ?>
    <?php if (in_array($status, ['sending', 'paused', 'sent'], true)): ?><a class="adm-btn adm-btn--ghost" href="<?= e(Newsletter::webUrl($c)) ?>" target="_blank" rel="noopener"><?= icon('arrow-square-out') ?> <?= e(__('Webversion')) ?></a><?php endif; ?>
    <form method="post" action="<?= e(url($base . '/kopie')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--ghost"><?= icon('copy') ?> <?= e(__('Kopie anlegen')) ?></button></form>
  </div>
</section>
<?php endif; ?>

<?php if ($editable): ?>
<form method="post" action="<?= e(url($base)) ?>" class="nl-editor" data-nl-live="<?= e(url('/admin/newsletter/live')) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="nl-editor__form">
    <section class="adm-card"><div class="adm-fields"><?= Fields::renderForm(AdminController::campaignFields(), $values, $errors, 'f') ?></div>
      <p class="adm-muted"><?= e(__('Aktuell {n} aktive Empfänger in den gewählten Verteilern (ohne gesperrte Adressen).', ['n' => fmt()->number($audience)])) ?></p></section>
    <section class="adm-card"><?= Blocks::renderInput('blocks', $blocks, $errors) ?><?php if (isset($errors['blocks'])): ?><p class="f-error"><?= e($errors['blocks']) ?></p><?php endif; ?></section>
    <div class="adm-savebar">
      <button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button>
      <details class="nl-savetpl"><summary class="adm-btn adm-btn--ghost"><?= icon('copy') ?> <?= e(__('Als Vorlage speichern')) ?></summary>
        <div class="nl-inline"><label for="nl-tpl-name" class="adm-sr"><?= e(__('Name der Vorlage')) ?></label><input id="nl-tpl-name" name="template_name" type="text" maxlength="80" placeholder="<?= e(__('Name der Vorlage')) ?>">
          <button class="adm-btn" name="_next" value="template"><?= e(__('Speichern')) ?></button></div></details>
    </div>
  </div>
  <div class="nl-editor__preview"><?php $previewBar(); ?></div>
</form>
<section class="adm-card">
  <h2><?= e(__('Testen und versenden')) ?></h2>
  <form method="post" action="<?= e(url($base . '/test')) ?>" class="nl-inline"><?= csrf_field() ?>
    <label for="nl-test"><?= e(__('Testversand an')) ?></label>
    <input id="nl-test" name="test_to" type="text" placeholder="<?= e((string) (app()->auth->user()['email'] ?? '')) ?>">
    <button class="adm-btn"><?= icon('paper-plane-tilt') ?> <?= e(__('Test senden')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Bis zu 5 Adressen, durch Komma getrennt. Leer = an Sie selbst. Es gilt der gespeicherte Stand.')) ?></p>
  <form method="post" action="<?= e(url($base . '/senden')) ?>" class="nl-inline" data-confirm="<?= e(__('Ausgabe an {n} Empfänger versenden?', ['n' => fmt()->number($audience)])) ?>"><?= csrf_field() ?>
    <label for="nl-at"><?= e(__('Zeitpunkt (leer = jetzt)')) ?></label>
    <input id="nl-at" name="at" type="datetime-local" min="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($status === 'scheduled' && $c['scheduled_at'] ? date('Y-m-d\TH:i', (int) strtotime($c['scheduled_at'])) : '') ?>">
    <button class="adm-btn adm-btn--primary" <?= $audience ? '' : 'disabled' ?>><?= icon('paper-plane-tilt') ?> <?= e($status === 'scheduled' ? __('Neu planen') : __('Versenden')) ?></button>
  </form>
  <form method="post" action="<?= e(url($base . '/loeschen')) ?>" data-confirm="<?= e(__('Ausgabe löschen?')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--ghost adm-btn--danger"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button></form>
</section>
<?php else: ?>
<section class="adm-card"><h2><?= e($c['subject']) ?></h2><?php $previewBar($base . '/vorschau'); ?></section>
<?php endif; ?>

<?php if ($events): ?>
<section class="adm-card">
  <h2><?= e(__('Rückläufer und Ereignisse')) ?></h2>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Zeit')) ?></th><th><?= e(__('Art')) ?></th><th><?= e(__('Empfänger')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Meldung')) ?></th></tr></thead>
    <tbody><?php foreach ($events as $ev): ?>
      <tr><td><?= e(fmt()->datetime($ev['created_at'])) ?></td><td><?= e($typeLabel[$ev['type']] ?? $ev['type']) ?></td>
        <td><?php if ((int) $ev['subscriber_id']): ?><a href="<?= e(url('/admin/newsletter/empfaenger/' . (int) $ev['subscriber_id'])) ?>"><?= e($ev['email'] ?: '#' . $ev['subscriber_id']) ?></a><?php else: ?><?= e($ev['email']) ?><?php endif; ?></td>
        <td><?= e(trim($ev['status'] . ' ' . ((int) $ev['smtp'] ?: ''))) ?></td><td class="adm-muted"><?= e($ev['diagnostic']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
