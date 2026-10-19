<?php
/** Neue Ausgabe: Vorlage wählen. @var array $builtin @var list<array> $saved */
$card = function (string $key, string $name, string $desc, bool $preview = true, ?int $delete = null): void { ?>
  <div class="nl-tpl">
    <form method="post" action="<?= e(url('/admin/newsletter/neu')) ?>" class="nl-tpl__pick"><?= csrf_field() ?>
      <input type="hidden" name="vorlage" value="<?= e($key) ?>">
      <button class="nl-tpl__btn" aria-label="<?= e(__('Vorlage „{name}“ verwenden', ['name' => $name])) ?>">
        <span class="nl-tpl__thumb"><?php if ($preview): ?><iframe src="<?= e(url('/admin/newsletter/vorlage/' . $key . '/vorschau')) ?>" title="" tabindex="-1" loading="lazy" aria-hidden="true"></iframe><?php else: ?><span class="nl-tpl__empty"><?= icon('plus') ?></span><?php endif; ?></span>
        <span class="nl-tpl__name"><?= e($name) ?></span>
        <span class="nl-tpl__desc"><?= e($desc) ?></span>
      </button>
    </form>
    <?php if ($delete): ?><form method="post" action="<?= e(url('/admin/newsletter/vorlagen/' . $delete . '/loeschen')) ?>" data-confirm="<?= e(__('Vorlage „{name}“ löschen?', ['name' => $name])) ?>"><?= csrf_field() ?><button class="adm-btn adm-btn--ghost adm-btn--danger nl-tpl__del"><?= icon('trash') ?> <?= e(__('Löschen')) ?></button></form><?php endif; ?>
  </div>
<?php };
?>
<section class="adm-card">
  <h2><?= e(__('Vorlage wählen')) ?></h2>
  <p class="adm-muted"><?= e(__('Jede Vorlage bringt Bausteine mit Beispieltexten und ein passendes Design mit – beides lässt sich danach frei ändern.')) ?></p>
  <div class="nl-tpls">
    <?php $card('leer', __('Leer'), __('Mit einem Textbaustein beginnen.'), false); ?>
    <?php foreach ($builtin as $k => $t) $card($k, $t['name'], $t['description']); ?>
  </div>
</section>
<?php if ($saved): ?>
<section class="adm-card">
  <h2><?= e(__('Eigene Vorlagen')) ?></h2>
  <div class="nl-tpls">
    <?php foreach ($saved as $t) $card('t' . (int) $t['id'], $t['name'], $t['description'] ?: __('Gespeichert am {d}', ['d' => fmt()->date($t['created_at'])]), true, (int) $t['id']); ?>
  </div>
</section>
<?php endif; ?>
