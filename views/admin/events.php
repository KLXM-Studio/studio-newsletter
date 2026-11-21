<?php
/** Protokoll der Rückläufer und Ereignisse. @var list<array> $events @var string $type */
$typeLabel = ['hard' => __('Hard Bounce'), 'soft' => __('Soft Bounce'), 'block' => __('Blockiert (Absender)'), 'complaint' => __('Beschwerde'), 'unsubscribe' => __('Abmeldung'), 'manual' => __('Von Hand gesperrt'),
    'auto' => __('Abwesenheit'), 'delayed' => __('Verzögert'), 'unknown' => __('Unklar'), 'reconfirm' => __('Altbestand bestätigt')];
?>
<section class="adm-card">
  <form method="get" action="<?= e(url('/admin/newsletter/protokoll')) ?>" class="nl-inline">
    <select name="typ" aria-label="<?= e(__('Art')) ?>"><option value=""><?= e(__('Alle Arten')) ?></option><?php foreach ($typeLabel as $k => $l): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="adm-btn"><?= e(__('Filtern')) ?></button>
  </form>
  <?php if (!$events): ?><p class="adm-muted"><?= e(__('Noch keine Einträge.')) ?></p><?php else: ?>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Zeit')) ?></th><th><?= e(__('Art')) ?></th><th><?= e(__('Empfänger')) ?></th><th><?= e(__('Ausgabe')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Meldung')) ?></th><th><?= e(__('Quelle')) ?></th></tr></thead>
    <tbody><?php foreach ($events as $ev): ?>
      <tr><td><?= e(fmt()->datetime($ev['created_at'])) ?></td><td><?= e($typeLabel[$ev['type']] ?? $ev['type']) ?></td>
        <td><?php if ((int) $ev['subscriber_id']): ?><a href="<?= e(url('/admin/newsletter/empfaenger/' . (int) $ev['subscriber_id'])) ?>"><?= e($ev['email'] ?: '#' . $ev['subscriber_id']) ?></a><?php else: ?><?= e($ev['email'] ?: '–') ?><?php endif; ?></td>
        <td><?php if ((int) $ev['campaign_id']): ?><a href="<?= e(url('/admin/newsletter/' . (int) $ev['campaign_id'])) ?>">#<?= (int) $ev['campaign_id'] ?></a><?php else: ?>–<?php endif; ?></td>
        <td><?= e(trim($ev['status'] . ' ' . ((int) $ev['smtp'] ?: ''))) ?></td><td class="adm-muted"><?= e($ev['diagnostic']) ?></td><td><?= e($ev['source']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
