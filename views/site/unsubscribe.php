<?php /** Abmelden mit Knopf (POST) – ein Link allein meldet nicht ab. @var string $title @var string $text @var string $action */ ?>
<section class="nl-page">
  <h1><?= e($title) ?></h1>
  <p><?= e($text) ?></p>
  <form method="post" action="<?= e($action) ?>">
    <button class="btn btn--primary" type="submit"><?= e(lt('Jetzt abbestellen')) ?></button>
  </form>
</section>
