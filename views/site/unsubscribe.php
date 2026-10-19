<?php
/** Abmelden je Verteiler (POST) – ein Link allein meldet nicht ab. @var string $title @var string $text @var string $action @var list<array> $lists @var list<int> $checked */
?>
<section class="nl-page">
  <h1><?= e($title) ?></h1>
  <p><?= e($text) ?></p>
  <form method="post" action="<?= e($action) ?>" class="nl-form">
    <?php if (count($lists) > 1): ?>
    <fieldset class="nl-lists"><legend class="nl-sr"><?= e(lt('Newsletter')) ?></legend>
      <?php foreach ($lists as $l): ?>
      <label class="nl-check"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>"<?= in_array((int) $l['id'], array_map('intval', $checked), true) ? ' checked' : '' ?>> <span><?= e($l['name']) ?><?php if ($l['description'] !== ''): ?><small><?= e($l['description']) ?></small><?php endif; ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="nl-actions">
      <button class="btn btn--primary" type="submit"><?= e(lt('Ausgewählte abbestellen')) ?></button>
      <button class="btn" type="submit" name="all" value="1"><?= e(lt('Alle abbestellen')) ?></button>
    </div>
    <?php else: ?>
      <?php foreach ($lists as $l): ?><input type="hidden" name="lists[]" value="<?= (int) $l['id'] ?>"><?php endforeach; ?>
      <?php if (!$lists): ?><input type="hidden" name="all" value="1"><?php endif; ?>
      <button class="btn btn--primary" type="submit"><?= e(lt('Jetzt abbestellen')) ?></button>
    <?php endif; ?>
  </form>
</section>
