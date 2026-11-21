<?php
/**
 * Eine Altbestands-Aktion: Zahlen mit Fortschrittsbalken, Entwurf (Betreff, Einleitung, Frist, Erinnerung, weitere Adressen,
 * Versand starten), laufend (Erinnerung, beenden), Vorschau der Bitte.
 * @var array $action @var ?array $list @var array $stats @var array<string, int> $skipped @var array $errors @var array $values
 */
use Klxm\Newsletter\Repermit;

$a = $action;
$id = (int) $a['id'];
$base = '/admin/newsletter/altbestand/' . $id;
$st = $stats;
$label = ['draft' => __('Entwurf'), 'running' => __('Läuft'), 'done' => __('Beendet')];
$total = max(1, $st['total']);
$pct = fn(int $n): string => rtrim(rtrim(number_format($n * 100 / $total, 2, '.', ''), '0'), '.');
$err = fn(string $k): string => isset($errors[$k]) ? '<p class="f-error" id="nl-rp-' . e($k) . '-err">' . e($errors[$k]) . '</p>' : '';
$inv = fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="nl-rp-' . e($k) . '-err"' : '';
$parts = ['confirmed' => __('bestätigt'), 'declined' => __('abgelehnt'), 'bounced' => __('unzustellbar'), 'expired' => __('gelöscht (keine Antwort)'), 'open' => __('offen')];
$wording = Repermit::wording($a);
?>
<p><a href="<?= e(url('/admin/newsletter/altbestand')) ?>"><?= icon('arrow-left') ?> <?= e(__('Alle Aktionen')) ?></a></p>
<section class="adm-card">
  <h2><?= e(__('Altbestand für „{name}“', ['name' => (string) ($list['name'] ?? '#' . (int) $a['list_id'])])) ?> <span class="adm-badge<?= $a['status'] === 'draft' ? ' adm-badge--draft' : ($a['status'] === 'done' ? ' adm-badge--muted' : '') ?>"><?= e($label[$a['status']] ?? $a['status']) ?></span></h2>
  <p><?= e(__('Herkunft: {o}', ['o' => Repermit::originLabel((string) $a['origin'])])) ?><?php if ($a['unknown_ack'] !== ''): ?> · <span class="adm-muted"><?= e(__('trotz unbekannter Herkunft freigegeben: {who}', ['who' => $a['unknown_ack']])) ?></span><?php endif; ?></p>
  <?php if ($a['origin'] === 'unknown' && $a['status'] === 'draft'): ?>
  <div class="adm-flash adm-flash--warning nl-rp-warn" role="note"><?= icon('warning') ?> <?= e(__('Herkunft unbekannt: Wir empfehlen, diese Personen nicht anzuschreiben. Gerichte werten schon die Bitte um Bestätigung als Werbung, wenn keine Einwilligung vorlag. Besser: Entwurf verwerfen.')) ?></div>
  <?php endif; ?>

  <div class="nl-rp-bar" role="img" aria-label="<?= e(__('{c} von {t} bestätigt, {d} abgelehnt, {b} unzustellbar, {o} offen', ['c' => $st['confirmed'], 't' => $st['total'], 'd' => $st['declined'], 'b' => $st['bounced'], 'o' => $st['open']])) ?>">
    <?php foreach ($parts as $k => $l): if ($st[$k] > 0): ?><span class="nl-rp-bar__<?= e($k) ?>" style="width:<?= $pct($st[$k]) ?>%"></span><?php endif; endforeach; ?>
  </div>
  <ul class="nl-rp-legend">
    <?php foreach ($parts as $k => $l): if ($k === 'expired' && !$st[$k]) continue; ?>
    <li><span class="nl-rp-dot nl-rp-bar__<?= e($k) ?>" aria-hidden="true"></span> <b><?= e(fmt()->number($st[$k])) ?></b> <?= e($l) ?></li>
    <?php endforeach; ?>
  </ul>
  <dl class="adm-dl">
    <div><dt><?= e(__('Adressen')) ?></dt><dd><?= e(fmt()->number($st['total'])) ?></dd></div>
    <?php if ($a['status'] !== 'draft'): ?>
    <div><dt><?= e(__('Bitte versendet')) ?></dt><dd><?= e(fmt()->number($st['sent'])) ?><?php if ($st['queued']): ?> · <?= e(__('{n} in der Warteschlange', ['n' => fmt()->number($st['queued'])])) ?><?php endif; ?></dd></div>
    <div><dt><?= e(__('Gestartet')) ?></dt><dd><?= e(fmt()->datetime((string) $a['started_at'])) ?></dd></div>
    <?php endif; ?>
    <?php if ($a['status'] === 'running'): ?>
    <div><dt><?= e(__('Automatische Löschung der Unbestätigten')) ?></dt><dd><?= e(fmt()->datetime((string) $a['deadline_at'])) ?></dd></div>
    <?php elseif ($a['status'] === 'done'): ?>
    <div><dt><?= e(__('Beendet')) ?></dt><dd><?= e(fmt()->datetime((string) $a['finished_at'])) ?></dd></div>
    <?php else: ?>
    <div><dt><?= e(__('Frist')) ?></dt><dd><?= e(__('{n} Tage ab Versand', ['n' => (int) $a['deadline_days']])) ?></dd></div>
    <?php endif; ?>
    <div><dt><?= e(__('Erinnerung')) ?></dt><dd><?= e(!(int) $a['reminder'] ? __('keine') : ($a['reminded_at'] ? __('versendet am {d}', ['d' => fmt()->datetime((string) $a['reminded_at'])])
        : ((int) $a['reminder_days'] > 0 ? __('automatisch {n} Tage nach dem Versand', ['n' => (int) $a['reminder_days']]) : __('von Hand')))) ?></dd></div>
    <?php if (array_sum($skipped)): ?>
    <div><dt><?= e(__('Beim Übernehmen übersprungen')) ?></dt><dd><?= e(__('{b} gesperrt, {c} schon bestätigt, {d} früher abgelehnt, {u} abgemeldet, {o} andere (Anmeldung läuft), {x} doppelt, {i} ungültig',
        ['b' => $skipped['blocked'], 'c' => $skipped['confirmed'], 'd' => $skipped['declined'], 'u' => $skipped['unsubscribed'], 'o' => $skipped['other'], 'x' => $skipped['duplicate'], 'i' => $skipped['invalid']])) ?></dd></div>
    <?php endif; ?>
    <?php if ($a['request_hash'] !== ''): ?><div><dt><?= e(__('Fassung der Bitte (SHA-256)')) ?></dt><dd><code><?= e(substr((string) $a['request_hash'], 0, 16)) ?></code></dd></div><?php endif; ?>
  </dl>
  <?php if ($st['open'] && $a['status'] !== 'done'): ?><p><a href="<?= e(url('/admin/newsletter/empfaenger') . '?status=reconfirm') ?>"><?= e(__('Empfänger mit „Bestätigung ausstehend“ ansehen')) ?></a></p><?php endif; ?>

  <?php if ($a['status'] === 'running'): ?>
  <div class="adm-actions">
    <?php if ((int) $a['reminder'] && !$a['reminded_at']): ?>
    <form method="post" action="<?= e(url($base)) ?>" data-confirm="<?= e(__('Erinnerung an alle ohne Antwort senden (offen: {n})? Es gibt nur eine Erinnerung.', ['n' => fmt()->number($st['open'])])) ?>"><?= csrf_field() ?>
      <input type="hidden" name="_action" value="remind"><button class="adm-btn"><?= icon('bell-ringing') ?> <?= e(__('Erinnerung senden')) ?></button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url($base)) ?>" data-confirm="<?= e(__('Jetzt beenden? Alle ohne Bestätigung (offen: {n}) werden sofort gelöscht, Bestätigungslinks gelten nicht mehr.', ['n' => fmt()->number($st['open'])])) ?>"><?= csrf_field() ?>
      <input type="hidden" name="_action" value="finish"><button class="adm-btn adm-btn--danger"><?= icon('trash') ?> <?= e(__('Jetzt beenden und Unbestätigte löschen')) ?></button></form>
  </div>
  <?php endif; ?>
</section>

<?php if ($a['status'] === 'draft'): $v = $values; ?>
<section class="adm-card">
  <h2><?= e(__('Bitte anpassen')) ?></h2>
  <p class="adm-muted"><?= e(__('Die Bestätigungsbitte ist eine feste, neutrale Vorlage ohne Werbung und ohne Bausteine – nur Betreff und Einleitung lassen sich anpassen. Absender, Logo und Fußzeile kommen vom Verteiler.')) ?></p>
  <form method="post" action="<?= e(url($base)) ?>" novalidate><?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">
    <div class="f"><label for="nl-rp-subject"><?= e(__('Betreff')) ?></label>
      <input id="nl-rp-subject" name="subject" type="text" maxlength="150" value="<?= e((string) (($v['subject'] ?? '') ?: Repermit::defaultSubject())) ?>"<?= $inv('subject') ?>><?= $err('subject') ?></div>
    <div class="f"><label for="nl-rp-intro"><?= e(__('Einleitung')) ?></label>
      <textarea id="nl-rp-intro" name="intro" rows="3" maxlength="600"<?= $inv('intro') ?>><?= e((string) (($v['intro'] ?? '') ?: Repermit::defaultIntro())) ?></textarea><?= $err('intro') ?>
      <p class="f-help"><?= e(__('Steht nach „Hallo {name},“. Platzhalter: {list} (Name des Verteilers), {site}. Ohne Links, höchstens 600 Zeichen. Danach folgen fest die Bitte, die Knöpfe „Ja, weiter erhalten“ und „Nein, abmelden“ und der Hinweis auf die Löschung.')) ?></p></div>
    <div class="nl-listform__grid">
      <div class="f"><label for="nl-rp-days"><?= e(__('Frist in Tagen')) ?></label>
        <input id="nl-rp-days" name="deadline_days" type="number" min="7" max="90" value="<?= (int) ($v['deadline_days'] ?? Repermit::DEADLINE) ?>"<?= $inv('deadline_days') ?>><?= $err('deadline_days') ?>
        <p class="f-help"><?= e(__('Ab Versand. Danach werden alle ohne Bestätigung automatisch gelöscht. Standard: 30 Tage.')) ?></p></div>
      <div class="f"><label class="nl-inline-check"><input type="checkbox" name="reminder" value="1"<?= !empty($v['reminder']) ? ' checked' : '' ?>> <?= e(__('Eine Erinnerung erlauben')) ?></label>
        <label for="nl-rp-rdays"><?= e(__('Erinnerung nach Tagen (0 = nur von Hand)')) ?></label>
        <input id="nl-rp-rdays" name="reminder_days" type="number" min="0" max="89" value="<?= (int) ($v['reminder_days'] ?? 0) ?>"<?= $inv('reminder_days') ?>><?= $err('reminder_days') ?>
        <p class="f-help"><?= e(__('Höchstens eine Erinnerung an alle, die noch nicht geantwortet haben. Standard: aus.')) ?></p></div>
    </div>
    <div class="adm-actions"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
  </form>
</section>

<section class="adm-card">
  <h2><?= e(__('Weitere Adressen hinzufügen')) ?></h2>
  <form method="post" action="<?= e(url('/admin/newsletter/altbestand')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="action_id" value="<?= $id ?>">
    <div class="f"><label for="nl-rp-more"><?= e(__('Adressen – eine je Zeile, optional mit Name: „E-Mail;Name“')) ?></label>
      <textarea id="nl-rp-more" name="rows" rows="4" placeholder="erika@example.org;Erika Musterfrau"></textarea></div>
    <p class="adm-muted"><?= e(__('Es gelten Herkunft und Angaben dieser Aktion.')) ?></p>
    <button class="adm-btn"><?= icon('plus') ?> <?= e(__('Hinzufügen')) ?></button>
  </form>
</section>

<section class="adm-card">
  <h2><?= e(__('Bitte versenden')) ?></h2>
  <p><?= e(__('Jede Person erhält die Bitte einmal, gedrosselt wie eine Ausgabe (Rückläufer werden ausgewertet). Die Frist von {d} Tagen beginnt mit dem Versand.', ['d' => (int) $a['deadline_days']])) ?></p>
  <form method="post" action="<?= e(url($base)) ?>" class="nl-rp-start" data-confirm="<?= e($a['origin'] === 'unknown'
      ? __('Herkunft unbekannt – trotzdem anschreiben (Empfänger: {n})? Das kann als unerlaubte Werbung gelten.', ['n' => fmt()->number($st['open'])])
      : __('Bestätigungsbitte jetzt versenden? Empfänger: {n}', ['n' => fmt()->number($st['open'])])) ?>"><?= csrf_field() ?>
    <input type="hidden" name="_action" value="start">
    <?php if ($a['origin'] === 'unknown'): ?>
    <div class="f"><label class="nl-inline-check"><input type="checkbox" name="unknown_ok" value="1" required> <?= e(__('Ich habe geprüft, dass diese Personen eingewilligt haben, und will sie trotz unbekannter Herkunft anschreiben.')) ?></label></div>
    <?php endif; ?>
    <div class="adm-actions">
      <button class="adm-btn adm-btn--primary"<?= $st['open'] ? '' : ' disabled' ?>><?= icon('paper-plane-tilt') ?> <?= e(__('Bitte versenden')) ?></button>
    </div>
  </form>
  <form method="post" action="<?= e(url($base)) ?>" data-confirm="<?= e(__('Entwurf verwerfen? Die übernommenen Adressen werden wieder entfernt.')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="_action" value="discard"><button class="adm-btn adm-btn--ghost adm-btn--danger"><?= icon('trash') ?> <?= e(__('Entwurf verwerfen')) ?></button></form>
</section>
<?php endif; ?>

<section class="adm-card">
  <h2><?= e(__('Vorschau der Bitte')) ?></h2>
  <p class="adm-muted"><?= e(__('Betreff: {s}', ['s' => \Klxm\Newsletter\Engine\Template::fill($wording['subject'], ['list' => (string) ($list['name'] ?? ''), 'site' => site_name()])])) ?></p>
  <div class="nl-preview">
    <div class="nl-preview__bar" role="group" aria-label="<?= e(__('Vorschau')) ?>">
      <button type="button" class="adm-btn adm-btn--ghost is-on" data-nl-device="desktop" aria-pressed="true"><?= icon('desktop') ?> <?= e(__('Desktop')) ?></button>
      <button type="button" class="adm-btn adm-btn--ghost" data-nl-device="mobile" aria-pressed="false"><?= icon('device-mobile') ?> <?= e(__('Handy')) ?></button>
      <?php if ((int) $a['reminder']): ?><a class="adm-btn adm-btn--ghost" href="<?= e(url($base . '/vorschau') . '?erinnerung=1') ?>" target="_blank" rel="noopener"><?= icon('bell-ringing') ?> <?= e(__('Erinnerung ansehen')) ?></a><?php endif; ?>
    </div>
    <div class="nl-preview__stage"><div class="nl-preview__box"><iframe class="nl-preview__frame" title="<?= e(__('Vorschau der Bestätigungsbitte')) ?>" src="<?= e(url($base . '/vorschau')) ?>"></iframe></div></div>
  </div>
</section>
