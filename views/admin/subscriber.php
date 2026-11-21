<?php
/** Ein Empfänger. @var array $sub @var list<array> $lists @var list<int> $mine @var list<array> $events @var ?string $suppressed */
$label = ['pending' => __('unbestätigt'), 'active' => __('aktiv'), 'unsubscribed' => __('abgemeldet'), 'bounced' => $sub['bounce_reason'] === 'manual' ? __('gesperrt (von Hand)') : __('gesperrt (Rückläufer)'), 'complained' => __('gesperrt (Beschwerde)'), 'reconfirm' => __('Bestätigung ausstehend (Altbestand)')];
$reason = ['hard' => __('Adresse existiert nicht (Hard Bounce)'), 'soft' => __('wiederholt nicht zustellbar (Soft Bounces)'), 'complaint' => __('als Spam gemeldet'), 'manual' => __('von Hand gesperrt')];
$typeLabel = ['hard' => __('Hard Bounce'), 'soft' => __('Soft Bounce'), 'block' => __('Blockiert (Absender)'), 'complaint' => __('Beschwerde'), 'unsubscribe' => __('Abmeldung'), 'manual' => __('Von Hand gesperrt'),
    'auto' => __('Abwesenheit'), 'delayed' => __('Verzögert'), 'unknown' => __('Unklar'), 'reconfirm' => __('Altbestand bestätigt')];
$base = url('/admin/newsletter/empfaenger/' . (int) $sub['id']);
?>
<section class="adm-card">
  <h2><?= e($sub['email']) ?> <span class="adm-badge"><?= e($label[$sub['status']] ?? $sub['status']) ?></span></h2>
  <dl class="adm-dl">
    <?php if ($sub['bounce_reason'] !== '' || $suppressed): ?><div><dt><?= e(__('Sperrgrund')) ?></dt><dd><?= e($reason[$sub['bounce_reason'] ?: (string) $suppressed] ?? (string) $suppressed) ?></dd></div><?php endif; ?>
    <?php if ((int) $sub['soft_count']): ?><div><dt><?= e(__('Soft Bounces')) ?></dt><dd><?= (int) $sub['soft_count'] ?> <?= e(__('aus {n} Ausgaben, seit {d}', ['n' => (int) $sub['soft_campaigns'], 'd' => fmt()->date((int) $sub['soft_first_at'])])) ?></dd></div><?php endif; ?>
    <div><dt><?= e(__('Angelegt')) ?></dt><dd><?= e(fmt()->datetime($sub['created_at'])) ?> (<?= e(['form' => __('Formular'), 'import' => __('Import'), 'admin' => __('Verwaltung'), 'legacy' => __('Altbestand')][$sub['source']] ?? $sub['source']) ?>)</dd></div>
    <?php if ($sub['confirmed_at']): ?><div><dt><?= e(__('Bestätigt')) ?></dt><dd><?= e(fmt()->datetime($sub['confirmed_at'])) ?></dd></div><?php endif; ?>
    <?php if ($sub['consent'] !== ''): ?><div><dt><?= e(__('Einwilligung')) ?></dt><dd><?= e($sub['consent']) ?><br><span class="adm-muted"><?= e(fmt()->datetime($sub['consent_at'])) ?><?= $sub['ip_hash'] !== '' ? ' · IP-Kürzel ' . e($sub['ip_hash']) : '' ?></span></dd></div><?php endif; ?>
    <?php if ($sub['unsubscribed_at']): ?><div><dt><?= e(__('Abgemeldet')) ?></dt><dd><?= e(fmt()->datetime($sub['unsubscribed_at'])) ?></dd></div><?php endif; ?>
  </dl>
</section>
<form method="post" action="<?= e($base) ?>"><?= csrf_field() ?>
  <section class="adm-card">
    <div class="f"><label for="nl-name"><?= e(__('Name')) ?></label><input id="nl-name" name="name" type="text" maxlength="120" value="<?= e($sub['name']) ?>"></div>
    <fieldset class="f"><legend><?= e(__('Verteiler')) ?></legend>
      <?php foreach ($lists as $l): ?><label><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>"<?= in_array((int) $l['id'], $mine, true) ? ' checked' : '' ?>> <?= e($l['name']) ?></label><br><?php endforeach; ?>
    </fieldset>
    <div class="f"><label for="nl-note"><?= e(__('Notiz (intern)')) ?></label><textarea id="nl-note" name="note" rows="3"><?= e((string) $sub['note']) ?></textarea></div>
  </section>
  <div class="adm-savebar"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
</form>
<section class="adm-card adm-actions">
  <?php if ($sub['status'] === 'pending'): ?><form method="post" action="<?= e($base) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="confirm"><button class="adm-btn"><?= e(__('Bestätigungsmail erneut senden')) ?></button></form><?php endif; ?>
  <?php if ($sub['status'] === 'active'): ?><form method="post" action="<?= e($base) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="unsubscribe"><button class="adm-btn"><?= e(__('Abmelden')) ?></button></form><?php endif; ?>
  <?php if (!empty($manager) && (in_array($sub['status'], ['bounced', 'complained'], true) || $suppressed)): ?>
  <form method="post" action="<?= e($base) ?>" data-confirm="<?= e(__('Sperre aufheben? Nur, wenn der Grund geklärt ist (z. B. Postfach wieder eingerichtet). Der Empfänger muss sich danach selbst neu anmelden.')) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="unblock"><button class="adm-btn"><?= e(__('Sperre aufheben')) ?></button></form>
  <?php endif; ?>
  <?php if (!empty($manager)): ?>
  <form method="post" action="<?= e($base) ?>" data-confirm="<?= e(__('Empfänger mit allen Daten löschen?')) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><button class="adm-btn adm-btn--danger"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button></form>
  <?php endif; ?>
</section>
<?php if (!empty($manager) && !in_array($sub['status'], ['bounced', 'complained'], true) && !$suppressed): ?>
<section class="adm-card">
  <h2><?= e(__('Sperren')) ?></h2>
  <form method="post" action="<?= e($base) ?>" class="nl-inline" data-confirm="<?= e(__('Adresse sperren? Sie erhält keine Ausgaben mehr und kann sich nicht neu anmelden, bis die Sperre aufgehoben wird.')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="_action" value="block">
    <label for="nl-reason" class="sr-only"><?= e(__('Grund')) ?></label>
    <input id="nl-reason" name="reason" type="text" maxlength="200" placeholder="<?= e(__('Grund, z. B. „Widerspruch per Telefon“')) ?>">
    <button class="adm-btn adm-btn--danger"><?= icon('lock') ?> <?= e(__('Sperren')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Für Widerspruch auf anderem Weg (Anruf, Brief) oder bekannte Problemadressen. Die Adresse kommt auf die Sperrliste – auch ein späterer Import oder eine neue Anmeldung trägt sie nicht wieder ein.')) ?></p>
</section>
<?php endif; ?>
<?php if ($events): ?>
<section class="adm-card">
  <h2><?= e(__('Verlauf')) ?></h2>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Zeit')) ?></th><th><?= e(__('Art')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Meldung')) ?></th><th><?= e(__('Quelle')) ?></th></tr></thead>
    <tbody><?php foreach ($events as $ev): ?><tr><td><?= e(fmt()->datetime($ev['created_at'])) ?></td><td><?= e($typeLabel[$ev['type']] ?? $ev['type']) ?></td>
      <td><?= e(trim($ev['status'] . ' ' . ((int) $ev['smtp'] ?: ''))) ?></td><td class="adm-muted"><?= e($ev['diagnostic']) ?></td><td><?= e($ev['source']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
