<?php
/**
 * Altbestand übernehmen und bestätigen lassen: Aktionen je Verteiler und Formular für neue Adressen.
 * @var list<array> $actions @var list<array> $lists (Funktion an, zugewiesen) @var array<int, string> $names @var bool $manager
 */
use Klxm\Newsletter\Repermit;

$status = ['draft' => __('Entwurf'), 'running' => __('Läuft'), 'done' => __('Beendet')];
?>
<section class="adm-card">
  <h2><?= e(__('Altbestand übernehmen und bestätigen lassen')) ?></h2>
  <p><?= e(__('Für alte Listen ohne Nachweis einer Einwilligung (z. B. aus einem früheren Newsletter-System oder von Hand gepflegt): Die Personen erhalten einmal eine neutrale Bitte, den Newsletter zu bestätigen. Wer bestätigt, ist danach regulärer Empfänger; wer nicht antwortet, wird nach Ablauf der Frist gelöscht. Bis dahin erhalten sie keine Ausgaben.')) ?></p>
  <p class="adm-muted"><?= e(__('Nur für Personen, die den Newsletter früher bereits erhalten und dafür eingewilligt haben. Keine Rechtsberatung – im Zweifel fragen Sie Ihre Datenschutzbeauftragte bzw. Ihren Datenschutzbeauftragten. Mehr im Handbuch unter „Newsletter“.')) ?></p>
</section>

<?php if ($actions): ?>
<section class="adm-card">
  <h2><?= e(__('Aktionen')) ?></h2>
  <div class="nl-scroll"><table class="adm-table">
    <thead><tr><th><?= e(__('Verteiler')) ?></th><th><?= e(__('Herkunft')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Fortschritt')) ?></th><th><?= e(__('Löschung der Unbestätigten')) ?></th></tr></thead>
    <tbody><?php foreach ($actions as $a): $st = Repermit::stats((int) $a['id']); ?>
      <tr>
        <td><a href="<?= e(url('/admin/newsletter/altbestand/' . (int) $a['id'])) ?>"><?= e($names[(int) $a['list_id']] ?? '#' . (int) $a['list_id']) ?></a> <span class="adm-muted">#<?= (int) $a['id'] ?></span></td>
        <td><?= e(Repermit::originLabel((string) $a['origin'])) ?></td>
        <td><span class="adm-badge<?= $a['status'] === 'draft' ? ' adm-badge--draft' : ($a['status'] === 'done' ? ' adm-badge--muted' : '') ?>"><?= e($status[$a['status']] ?? $a['status']) ?></span></td>
        <td><?= e(__('{c} von {t} bestätigt', ['c' => fmt()->number($st['confirmed']), 't' => fmt()->number($st['total'])])) ?></td>
        <td><?= $a['status'] === 'running' ? e(fmt()->date((string) $a['deadline_at'], 'long')) : ($a['status'] === 'done' ? e(__('erledigt am {d}', ['d' => fmt()->date((string) $a['finished_at'])])) : '–') ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<?php if (!$lists): ?>
<section class="adm-card">
  <h2><?= e(__('Neue Adressen übernehmen')) ?></h2>
  <p><?= e($manager ? __('Die Funktion ist für keinen Verteiler eingeschaltet. Schalten Sie sie unter Newsletter → Verteiler beim gewünschten Verteiler ein („Altbestand bestätigen lassen“).')
      : __('Die Funktion ist für keinen Ihrer Verteiler eingeschaltet – bitte die Newsletter-Verwaltung fragen.')) ?></p>
  <?php if ($manager): ?><p><a class="adm-btn" href="<?= e(url('/admin/newsletter/verteiler')) ?>"><?= icon('list-bullets') ?> <?= e(__('Zu den Verteilern')) ?></a></p><?php endif; ?>
</section>
<?php else: ?>
<section class="adm-card">
  <h2><?= e(__('Neue Adressen übernehmen')) ?></h2>
  <form method="post" action="<?= e(url('/admin/newsletter/altbestand')) ?>" class="nl-rp-form"><?= csrf_field() ?>
    <div class="f"><label for="nl-rp-list"><?= e(__('Verteiler')) ?></label>
      <select id="nl-rp-list" name="list"><?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="f"><label for="nl-rp-rows"><?= e(__('Adressen – eine je Zeile, optional mit Name: „E-Mail;Name“ (aus Tabelle oder CSV einfügen)')) ?></label>
      <textarea id="nl-rp-rows" name="rows" rows="8" required placeholder="erika@example.org;Erika Musterfrau"></textarea>
      <p class="f-help"><?= e(__('Bis zu {n} Zeilen je Durchgang; eine Kopfzeile wird erkannt. Weitere Adressen können Sie danach im Entwurf ergänzen.', ['n' => fmt()->number(Repermit::MAX_ROWS)])) ?></p></div>
    <fieldset class="f nl-rp-origin"><legend><?= e(__('Herkunft der Adressen (Pflicht)')) ?></legend>
      <label class="nl-rp-choice"><input type="radio" name="origin" value="consent" required> <span><b><?= e(__('früher eingewilligt, ohne Nachweis')) ?></b><small><?= e(__('Die Personen haben sich früher selbst angemeldet oder eingewilligt, der Nachweis (z. B. Zeitpunkt, Wortlaut) fehlt aber.')) ?></small></span></label>
      <label class="nl-rp-choice"><input type="radio" name="origin" value="customer"> <span><b><?= e(__('Bestandskunde (§ 7 Abs. 3 UWG)')) ?></b><small><?= e(__('Adresse aus einem Kauf, Werbung nur für ähnliche eigene Angebote, mit Hinweis auf das Widerspruchsrecht.')) ?></small></span></label>
      <label class="nl-rp-choice"><input type="radio" name="origin" value="unknown"> <span><b><?= e(__('unbekannt')) ?></b><small><?= e(__('Woher die Adressen stammen, ist nicht bekannt.')) ?></small></span></label>
    </fieldset>
    <div class="adm-flash adm-flash--warning nl-rp-warn" role="note"><?= icon('warning') ?> <?= e(__('Bei unbekannter Herkunft: nicht anschreiben. Gerichte werten schon die E-Mail mit der Bitte um Bestätigung als Werbung, wenn keine Einwilligung vorlag. Der Versand ist für diese Gruppe nur nach einer zusätzlichen ausdrücklichen Bestätigung möglich.')) ?></div>
    <div class="f"><label class="nl-inline-check"><input type="checkbox" name="received" value="1" required> <?= e(__('Diese Personen haben den Newsletter bisher erhalten.')) ?></label></div>
    <p class="adm-muted"><?= e(__('Übersprungen werden Adressen auf der Sperrliste, schon bestätigte, früher abgelehnte und abgemeldete. Die übrigen stehen danach mit „Bestätigung ausstehend“ in den Empfängern und erhalten keine Ausgaben. Versendet wird erst, wenn Sie im nächsten Schritt „Bitte versenden“ wählen.')) ?></p>
    <button class="adm-btn adm-btn--primary"><?= icon('user-check') ?> <?= e(__('Adressen übernehmen')) ?></button>
  </form>
</section>
<?php endif; ?>
