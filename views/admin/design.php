<?php
/** Design bearbeiten mit Live-Vorschau. @var array $design @var string $name @var array $values @var list<string> $issues */
use Core\Fields;
use Klxm\Newsletter\Design;

$base = '/admin/newsletter/designs/' . (int) $design['id'];
?>
<form method="post" action="<?= e(url($base)) ?>" class="nl-editor" data-nl-live="<?= e(url('/admin/newsletter/designs/live')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="nl-editor__form">
    <section class="adm-card">
      <div class="f"><label for="nl-dname"><?= e(__('Name')) ?></label><input id="nl-dname" name="name" type="text" maxlength="80" value="<?= e($name) ?>" required></div>
      <?php if ($issues): ?><div class="adm-flash adm-flash--warning"><?= icon('warning') ?> <?= e(__('Zu wenig Kontrast – schwer lesbar:')) ?><ul><?php foreach ($issues as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="adm-fields"><?= Fields::renderForm(Design::fields(), $values, [], 'f') ?></div>
    </section>
    <div class="adm-savebar">
      <button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button>
      <button class="adm-btn adm-btn--ghost" name="_action" value="copy"><?= icon('copy') ?> <?= e(__('Kopie anlegen')) ?></button>
      <button class="adm-btn adm-btn--ghost adm-btn--danger" name="_action" value="delete" data-confirm="<?= e(__('Design „{name}“ löschen?', ['name' => $name])) ?>"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button>
    </div>
  </div>
  <div class="nl-editor__preview">
    <div class="nl-preview">
      <div class="nl-preview__bar" role="group" aria-label="<?= e(__('Vorschau')) ?>">
        <button type="button" class="adm-btn adm-btn--ghost is-on" data-nl-device="desktop" aria-pressed="true"><?= icon('desktop') ?> <?= e(__('Desktop')) ?></button>
        <button type="button" class="adm-btn adm-btn--ghost" data-nl-device="mobile" aria-pressed="false"><?= icon('device-mobile') ?> <?= e(__('Handy')) ?></button>
        <button type="button" class="adm-btn adm-btn--ghost" data-nl-dark aria-pressed="false"><?= icon('moon') ?> <?= e(__('Dunkel')) ?></button>
        <span class="nl-preview__state adm-muted" data-nl-state aria-live="polite"></span>
      </div>
      <div class="nl-preview__stage"><div class="nl-preview__box"><iframe class="nl-preview__frame" data-nl-frame title="<?= e(__('Vorschau mit der Vorlage „Klassisch“')) ?>"></iframe></div></div>
    </div>
  </div>
</form>
