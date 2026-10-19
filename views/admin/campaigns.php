<?php
/** Ausgaben. @var list<array> $campaigns */
use Klxm\Newsletter\Campaigns;

$label = ['draft' => __('Entwurf'), 'scheduled' => __('Geplant'), 'sending' => __('Wird versendet'), 'paused' => __('Angehalten'), 'sent' => __('Versendet'), 'cancelled' => __('Abgebrochen')];
?>
<section class="adm-card">
  <?php if (!$campaigns): ?>
    <h2><?= e(__('Noch keine Ausgabe')) ?></h2>
    <p><?= e(__('So geht es los: 1. Einstellungen prüfen (Versandart, Absenderangaben), 2. den Block „Newsletter-Anmeldung“ auf einer Seite einfügen, 3. erste Ausgabe schreiben und an sich selbst testen.')) ?></p>
    <p><a class="adm-btn adm-btn--primary" href="<?= e(url('/admin/newsletter/neu')) ?>"><?= icon('plus') ?> <?= e(__('Neue Ausgabe')) ?></a></p>
  <?php else: ?>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Betreff')) ?></th><th><?= e(__('Status')) ?></th><th class="num"><?= e(__('Versendet')) ?></th><th class="num"><?= e(__('Rückläufer')) ?></th><th class="num"><?= e(__('Abmeldungen')) ?></th><th><?= e(__('Datum')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): $q = Campaigns::rates($c); ?>
      <tr>
        <td><a href="<?= e(url('/admin/newsletter/' . (int) $c['id'])) ?>"><?= e($c['subject']) ?></a></td>
        <td><span class="adm-badge"><?= e($label[$c['status']] ?? $c['status']) ?></span><?php if ($c['status'] === 'scheduled' && $c['scheduled_at']): ?> <span class="adm-muted"><?= e(fmt()->datetime($c['scheduled_at'])) ?></span><?php endif; ?></td>
        <td class="num"><?= (int) $c['total'] ? e(fmt()->number($c['sent'])) . ' / ' . e(fmt()->number($c['total'])) : '–' ?></td>
        <td class="num"><?= (int) $c['sent'] ? e(fmt()->number((int) $c['hard'] + (int) $c['soft'])) . ' <span class="adm-muted">(' . e(fmt()->decimal($q['bounce'], 1)) . ' %)</span>' : '–' ?></td>
        <td class="num"><?= (int) $c['sent'] ? e(fmt()->number($c['unsubscribe'])) : '–' ?></td>
        <td><?= e(fmt()->datetime($c['finished_at'] ?: $c['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
