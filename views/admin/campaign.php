<?php
/**
 * Ausgabe schreiben / Bericht. @var ?array $campaign @var array $values @var array $errors @var bool $editable @var int $audience @var list<array> $events
 */
use Core\Fields;
use Klxm\Newsletter\AdminController;
use Klxm\Newsletter\Campaigns;
use Klxm\Newsletter\Newsletter;

$c = $campaign;
$id = (int) ($c['id'] ?? 0);
$base = '/admin/newsletter/' . ($id ?: 'neu');
$status = (string) ($c['status'] ?? 'draft');
$label = ['draft' => __('Entwurf'), 'scheduled' => __('Geplant'), 'sending' => __('Wird versendet'), 'paused' => __('Angehalten'), 'sent' => __('Versendet'), 'cancelled' => __('Abgebrochen')];
$typeLabel = ['hard' => __('Hard Bounce'), 'soft' => __('Soft Bounce'), 'block' => __('Blockiert (Absender)'), 'complaint' => __('Beschwerde'), 'unsubscribe' => __('Abmeldung'),
    'auto' => __('Abwesenheit'), 'delayed' => __('Verzögert'), 'unknown' => __('Unklar')];
?>
<?php if ($c && $status !== 'draft'): $q = Campaigns::rates($c); ?>
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
<form method="post" action="<?= e(url($base)) ?>" novalidate>
  <?= csrf_field() ?>
  <section class="adm-card"><div class="adm-fields"><?= Fields::renderForm(AdminController::campaignFields(), $values, $errors, 'f') ?></div>
    <p class="adm-muted"><?= e(__('Aktuell {n} aktive Empfänger in den gewählten Verteilern (ohne gesperrte Adressen).', ['n' => fmt()->number($audience)])) ?></p></section>
  <div class="adm-savebar">
    <button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button>
    <?php if ($id): ?><a class="adm-btn adm-btn--ghost" href="<?= e(url($base . '/vorschau')) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> <?= e(__('Vorschau')) ?></a><?php endif; ?>
  </div>
</form>
<?php if ($id): ?>
<section class="adm-card">
  <h2><?= e(__('Testen und versenden')) ?></h2>
  <form method="post" action="<?= e(url($base . '/test')) ?>" class="nl-inline"><?= csrf_field() ?>
    <label for="nl-test"><?= e(__('Testversand an')) ?></label>
    <input id="nl-test" name="test_to" type="text" placeholder="<?= e((string) (app()->auth->user()['email'] ?? '')) ?>">
    <button class="adm-btn"><?= icon('paper-plane-tilt') ?> <?= e(__('Test senden')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Bis zu 5 Adressen, durch Komma getrennt. Leer = an Sie selbst.')) ?></p>
  <form method="post" action="<?= e(url($base . '/senden')) ?>" class="nl-inline" data-confirm="<?= e(__('Ausgabe an {n} Empfänger versenden?', ['n' => fmt()->number($audience)])) ?>"><?= csrf_field() ?>
    <label for="nl-at"><?= e(__('Zeitpunkt (leer = jetzt)')) ?></label>
    <input id="nl-at" name="at" type="datetime-local" min="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($status === 'scheduled' && $c['scheduled_at'] ? date('Y-m-d\TH:i', (int) strtotime($c['scheduled_at'])) : '') ?>">
    <button class="adm-btn adm-btn--primary" <?= $audience ? '' : 'disabled' ?>><?= icon('paper-plane-tilt') ?> <?= e($status === 'scheduled' ? __('Neu planen') : __('Versenden')) ?></button>
  </form>
  <form method="post" action="<?= e(url($base . '/loeschen')) ?>" data-confirm="<?= e(__('Ausgabe löschen?')) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--ghost adm-btn--danger"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button></form>
</section>
<?php endif; ?>
<?php elseif ($c): ?>
<section class="adm-card">
  <h2><?= e($c['subject']) ?></h2>
  <iframe class="nl-preview" title="<?= e(__('Vorschau')) ?>" src="<?= e(url($base . '/vorschau')) ?>" loading="lazy" ></iframe>
</section>
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
