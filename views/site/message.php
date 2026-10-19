<?php /** Meldung (Anmeldung, Bestätigung, Abmeldung). @var string $title @var string $text */ ?>
<section class="nl-page">
  <h1><?= e($title) ?></h1>
  <p><?= e($text) ?></p>
  <p><a class="btn" href="<?= e(url('/')) ?>"><?= e(lt('Zur Startseite')) ?></a></p>
</section>
