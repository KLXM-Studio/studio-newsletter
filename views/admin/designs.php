<?php /** Designs. @var list<array> $designs */ ?>
<section class="adm-card">
  <h2><?= e(__('Neues Design')) ?></h2>
  <form method="post" action="<?= e(url('/admin/newsletter/designs/neu')) ?>" class="nl-inline"><?= csrf_field() ?>
    <label for="nl-dn"><?= e(__('Name')) ?></label><input id="nl-dn" name="name" type="text" maxlength="80" required placeholder="<?= e(__('z. B. Vereins-News')) ?>">
    <label for="nl-df"><?= e(__('Grundlage')) ?></label>
    <select id="nl-df" name="from"><?php foreach ($designs as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <button class="adm-btn adm-btn--primary"><?= icon('plus') ?> <?= e(__('Anlegen')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Das neue Design übernimmt alle Einstellungen der Grundlage; danach passen Sie Logo, Farben, Schrift und Formen im Editor an.')) ?></p>
</section>
<section class="adm-card">
  <p class="adm-muted"><?= e(__('Ein Design legt Kopf mit Logo, Schrift, Farben und Formen der Ausgaben fest. Jeder Verteiler wählt sein Design; eine Ausgabe kann ein anderes nehmen. Die mitgelieferten Designs lassen sich anpassen oder als Kopie weiterentwickeln.')) ?></p>
  <div class="nl-tpls">
  <?php foreach ($designs as $d): ?>
    <a class="nl-tpl__btn nl-tpl" href="<?= e(url('/admin/newsletter/designs/' . (int) $d['id'])) ?>">
      <span class="nl-tpl__thumb"><iframe src="<?= e(url('/admin/newsletter/designs/' . (int) $d['id'] . '/vorschau')) ?>" title="" tabindex="-1" loading="lazy" aria-hidden="true"></iframe></span>
      <span class="nl-tpl__name"><?= e($d['name']) ?></span>
      <span class="nl-tpl__desc nl-swatches" aria-hidden="true"><?php foreach (['accent', 'heading', 'bg', 'card'] as $k): ?><span class="nl-swatch" style="background:<?= e($d['s'][$k]) ?>"></span><?php endforeach; ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</section>
