<?php /** Verteiler. @var list<array> $lists */ ?>
<section class="adm-card">
  <p class="adm-muted"><?= e(__('Verteiler sind Themen oder Zielgruppen. Öffentliche Verteiler stehen im Anmeldeformular zur Auswahl (ab zwei), interne nur in der Verwaltung.')) ?></p>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('Beschreibung')) ?></th><th><?= e(__('Öffentlich')) ?></th><th class="num"><?= e(__('Aktive Empfänger')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lists as $l): ?>
      <tr><td colspan="5">
        <form method="post" action="<?= e(url('/admin/newsletter/verteiler')) ?>" class="nl-inline"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
          <input name="name" type="text" value="<?= e($l['name']) ?>" maxlength="120" aria-label="<?= e(__('Name')) ?>" required>
          <input name="description" type="text" value="<?= e($l['description']) ?>" maxlength="191" aria-label="<?= e(__('Beschreibung')) ?>">
          <label><input type="checkbox" name="public" value="1"<?= (int) $l['public'] ? ' checked' : '' ?>> <?= e(__('Öffentlich')) ?></label>
          <span class="adm-muted"><?= e(__('{n} aktiv', ['n' => fmt()->number($l['active'])])) ?></span>
          <button class="adm-btn"><?= e(__('Speichern')) ?></button>
          <button class="adm-btn adm-btn--ghost adm-btn--danger" name="_action" value="delete" data-confirm="<?= e(__('Verteiler löschen? Die Empfänger bleiben erhalten.')) ?>"><?= icon('trash') ?><span class="adm-sr"><?= e(__('Löschen')) ?></span></button>
        </form>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<section class="adm-card">
  <h2><?= e(__('Neuer Verteiler')) ?></h2>
  <form method="post" action="<?= e(url('/admin/newsletter/verteiler')) ?>" class="nl-inline"><?= csrf_field() ?>
    <input name="name" type="text" maxlength="120" placeholder="<?= e(__('Name')) ?>" aria-label="<?= e(__('Name')) ?>" required>
    <input name="description" type="text" maxlength="191" placeholder="<?= e(__('Beschreibung (optional)')) ?>" aria-label="<?= e(__('Beschreibung')) ?>">
    <label><input type="checkbox" name="public" value="1" checked> <?= e(__('Öffentlich')) ?></label>
    <button class="adm-btn adm-btn--primary"><?= icon('plus') ?> <?= e(__('Anlegen')) ?></button>
  </form>
</section>
