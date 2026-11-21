<?php
/**
 * Verteiler: Name, Beschreibung, öffentlich/intern und eigener Auftritt (Absender-Konto optional, Fußzeile, Farbe).
 * @var list<array> $lists
 */
use Klxm\Newsletter\Design;

$accounts = ['' => __('wie eingestellt (Zweck „Newsletter versenden“)')];
foreach (\Core\Mail\Accounts::all() as $id => $a) {
    if ($id === 'network') continue;
    $accounts[(string) $id] = ($a['label'] ?: ($id === 'standard' ? __('Standard') : $id)) . ($a['from'] !== '' ? ' – ' . $a['from'] : '') . (\Core\Mail\Accounts::ready($a) ? '' : ' (' . __('nicht versandbereit') . ')');
}
$designs = ['0' => __('erstes Design')];
foreach (Design::all() as $d) $designs[(string) $d['id']] = $d['name'];
$choices = \Klxm\Newsletter\Access::choices();
$form = function (array $l) use ($accounts, $designs, $choices): void {
    $id = (int) ($l['id'] ?? 0);
    $p = 'nl-l' . $id;
    ?>
    <form method="post" action="<?= e(url('/admin/newsletter/verteiler')) ?>" class="nl-listform"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="nl-listform__grid">
        <div class="f"><label for="<?= $p ?>-name"><?= e(__('Name')) ?></label><input id="<?= $p ?>-name" name="name" type="text" value="<?= e((string) ($l['name'] ?? '')) ?>" maxlength="120" required></div>
        <div class="f"><label for="<?= $p ?>-desc"><?= e(__('Beschreibung')) ?></label><input id="<?= $p ?>-desc" name="description" type="text" value="<?= e((string) ($l['description'] ?? '')) ?>" maxlength="191"></div>
        <div class="f"><label for="<?= $p ?>-acc"><?= e(__('Absender (optional)')) ?></label>
          <select id="<?= $p ?>-acc" name="account"><?php foreach ($accounts as $k => $label): ?><option value="<?= e($k) ?>"<?= (string) ($l['account'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <p class="f-help"><?= e(__('Eigenes E-Mail-Konto für diesen Verteiler, z. B. presse@… statt news@… Konten legen Sie unter Grundeinstellungen → E-Mail-Versand an.')) ?></p></div>
        <div class="f"><label for="<?= $p ?>-design"><?= e(__('Design')) ?></label>
          <select id="<?= $p ?>-design" name="design_id"><?php foreach ($designs as $k => $label): ?><option value="<?= e((string) $k) ?>"<?= (string) (int) ($l['design_id'] ?? 0) === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <p class="f-help"><a href="<?= e(url('/admin/newsletter/designs')) ?>"><?= e(__('Designs ansehen und anpassen')) ?></a></p>
          <?php if (($l['color'] ?? '') !== ''): ?><input type="hidden" name="color" value="<?= e($l['color']) ?>"><label class="nl-inline-check"><input type="checkbox" name="own_color" value="1" checked> <?= e(__('abweichende Akzentfarbe {c} behalten', ['c' => $l['color']])) ?></label><?php endif; ?></div>
        <div class="f nl-listform__wide"><label for="<?= $p ?>-foot"><?= e(__('Absenderangaben im Fuß (optional)')) ?></label>
          <textarea id="<?= $p ?>-foot" name="footer" rows="2" maxlength="600" placeholder="<?= e(__('leer = wie in den Einstellungen')) ?>"><?= e((string) ($l['footer'] ?? '')) ?></textarea></div>
        <fieldset class="f nl-listform__wide nl-editors"><legend><?= e(__('Wer darf diesen Verteiler bedienen?')) ?></legend>
          <input type="hidden" name="_editors" value="1">
          <?php $sel = \Klxm\Newsletter\Access::editors($l); foreach ($choices as $k => $label): ?>
          <label class="nl-inline-check"><input type="checkbox" name="editors[]" value="<?= e($k) ?>"<?= in_array($k, $sel, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
          <p class="f-help"><?= e(__('Für Rollen und Personen mit dem Recht „Newsletter: Ausgaben schreiben und versenden“. Sie sehen nur Ausgaben und Empfänger der Verteiler, die ihnen hier zugewiesen sind. Die Newsletter-Verwaltung darf alle.')) ?></p></fieldset>
      </div>
      <div class="nl-inline">
        <label><input type="checkbox" name="public" value="1"<?= !$id || (int) ($l['public'] ?? 1) ? ' checked' : '' ?>> <?= e(__('Öffentlich (im Anmeldeformular wählbar)')) ?></label>
        <label><input type="checkbox" name="repermit" value="1"<?= (int) ($l['repermit'] ?? 0) ? ' checked' : '' ?>> <?= e(__('Altbestand bestätigen lassen (Newsletter → Altbestand)')) ?></label>
        <?php if ($id): ?><span class="adm-muted"><?= e(__('{n} aktiv', ['n' => fmt()->number($l['active'])])) ?></span><?php endif; ?>
        <button class="adm-btn<?= $id ? '' : ' adm-btn--primary' ?>"><?= $id ? e(__('Speichern')) : icon('plus') . ' ' . e(__('Anlegen')) ?></button>
        <?php if ($id): ?><button class="adm-btn adm-btn--ghost adm-btn--danger" name="_action" value="delete" data-confirm="<?= e(__('Verteiler löschen? Die Empfänger bleiben erhalten.')) ?>"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button><?php endif; ?>
      </div>
    </form>
    <?php
};
?>
<section class="adm-card">
  <p class="adm-muted"><?= e(__('Verteiler sind eigene Newsletter oder Themen. Öffentliche erscheinen im Anmeldeformular, interne nur, wenn ein Block sie ausdrücklich anbietet. Design, Absender und Fußzeile eines Verteilers gelten für seine Ausgaben und Bestätigungsmails – leer = wie in den Einstellungen.')) ?></p>
</section>
<?php foreach ($lists as $l): ?>
<section class="adm-card"><h2><?= e($l['name']) ?><?php if (!(int) $l['public']): ?> <span class="adm-badge"><?= e(__('intern')) ?></span><?php endif; ?></h2><?php $form($l); ?></section>
<?php endforeach; ?>
<section class="adm-card"><h2><?= e(__('Neuer Verteiler')) ?></h2><?php $form([]); ?></section>
