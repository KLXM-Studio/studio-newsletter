<?php
/** Empfänger. @var array $filter @var int $page @var array $result @var list<array> $lists */
$label = ['pending' => __('unbestätigt'), 'active' => __('aktiv'), 'unsubscribed' => __('abgemeldet'), 'bounced' => __('gesperrt (Rückläufer)'), 'complained' => __('gesperrt (Beschwerde)')];
$pages = max(1, (int) ceil($result['total'] / 50));
$q = fn(array $o) => url('/admin/newsletter/empfaenger') . '?' . http_build_query(array_filter($o + $filter + ['seite' => $page], fn($v) => $v !== '' && $v !== 0 && $v !== 1));
?>
<section class="adm-card">
  <form method="get" action="<?= e(url('/admin/newsletter/empfaenger')) ?>" class="nl-inline">
    <label class="adm-sr" for="nl-q"><?= e(__('Suchen')) ?></label>
    <input id="nl-q" type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="<?= e(__('E-Mail oder Name')) ?>">
    <select name="status" aria-label="<?= e(__('Status')) ?>"><option value=""><?= e(__('Alle Status')) ?></option><?php foreach ($label as $k => $l): ?><option value="<?= e($k) ?>"<?= $filter['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="list" aria-label="<?= e(__('Verteiler')) ?>"><option value="0"><?= e(__('Alle Verteiler')) ?></option><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"<?= (int) $filter['list'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
    <button class="adm-btn"><?= e(__('Filtern')) ?></button>
    <a class="adm-btn adm-btn--ghost" href="<?= e(url('/admin/newsletter/empfaenger/export.csv')) ?>"><?= icon('download-simple') ?> <?= e(__('Aktive als CSV')) ?></a>
  </form>
  <p class="adm-muted"><?= e(__('{n} Einträge', ['n' => fmt()->number($result['total'])])) ?></p>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('E-Mail')) ?></th><th><?= e(__('Name')) ?></th><th><?= e(__('Status')) ?></th><th class="num"><?= e(__('Soft Bounces')) ?></th><th><?= e(__('Angemeldet')) ?></th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $s): ?>
      <tr><td><a href="<?= e(url('/admin/newsletter/empfaenger/' . (int) $s['id'])) ?>"><?= e($s['email']) ?></a></td><td><?= e($s['name']) ?></td>
        <td><span class="adm-badge"><?= e($label[$s['status']] ?? $s['status']) ?></span></td><td class="num"><?= (int) $s['soft_count'] ?: '' ?></td>
        <td><?= e(fmt()->date($s['confirmed_at'] ?: $s['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($pages > 1): ?><nav class="nl-pager" aria-label="<?= e(__('Seiten')) ?>">
    <?php if ($page > 1): ?><a href="<?= e($q(['seite' => $page - 1])) ?>"><?= e(__('Zurück')) ?></a><?php endif; ?>
    <span><?= e(__('Seite {p} von {n}', ['p' => $page, 'n' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($q(['seite' => $page + 1])) ?>"><?= e(__('Weiter')) ?></a><?php endif; ?>
  </nav><?php endif; ?>
</section>
<section class="adm-card">
  <h2><?= e(__('Empfänger hinzufügen')) ?></h2>
  <form method="post" action="<?= e(url('/admin/newsletter/empfaenger/import')) ?>"><?= csrf_field() ?>
    <div class="f"><label for="nl-rows"><?= e(__('Adressen – eine je Zeile, optional mit Name: „E-Mail;Name“')) ?></label>
      <textarea id="nl-rows" name="rows" rows="6" placeholder="erika@example.org;Erika Musterfrau"></textarea></div>
    <div class="f"><label for="nl-list"><?= e(__('Verteiler')) ?></label>
      <select id="nl-list" name="list"><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="f"><label><input type="checkbox" name="proof" value="1"> <?= e(__('Für alle liegt eine nachweisbare Einwilligung vor – ohne Bestätigungsmail eintragen')) ?></label></div>
    <div class="f"><label for="nl-proof"><?= e(__('Nachweis (z. B. „Anmeldeformular alte Website bis 10/2026“)')) ?></label><input id="nl-proof" name="proof_text" type="text" maxlength="200"></div>
    <p class="adm-muted"><?= e(__('Ohne Haken erhalten alle eine Bestätigungsmail (Double-Opt-in) und sind erst danach aktiv. Gesperrte Adressen (Rückläufer, Beschwerden) werden übersprungen.')) ?></p>
    <button class="adm-btn"><?= e(__('Hinzufügen')) ?></button>
  </form>
</section>
