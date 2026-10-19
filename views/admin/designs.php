<?php /** Designs. @var list<array> $designs */ ?>
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
