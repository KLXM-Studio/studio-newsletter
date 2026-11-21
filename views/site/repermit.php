<?php
/**
 * Bestätigungsbitte an den Altbestand: zwei Knöpfe (POST) – ein Link allein ändert nichts (Link-Scanner der Postfächer).
 * @var string $title @var string $text @var string $action @var string $answer ja|nein|'' (Vorauswahl aus der Mail) @var string $deadline
 */
$yesFirst = $answer !== 'nein';
?>
<section class="nl-page">
  <h1><?= e($title) ?></h1>
  <p><?= e($text) ?></p>
  <form method="post" action="<?= e($action) ?>" class="nl-form">
    <div class="nl-actions">
      <?php if ($yesFirst): ?>
      <button class="btn btn--primary" type="submit" name="answer" value="ja"><?= e(lt('Ja, weiter erhalten')) ?></button>
      <button class="btn" type="submit" name="answer" value="nein"><?= e(lt('Nein, abmelden')) ?></button>
      <?php else: ?>
      <button class="btn btn--primary" type="submit" name="answer" value="nein"><?= e(lt('Nein, abmelden')) ?></button>
      <button class="btn" type="submit" name="answer" value="ja"><?= e(lt('Ja, weiter erhalten')) ?></button>
      <?php endif; ?>
    </div>
  </form>
  <p class="nl-note"><?= e(lt('Ohne Bestätigung entfernen wir Ihre Adresse am {date} aus dem Verteiler und löschen Ihre Daten.', ['date' => $deadline])) ?></p>
</section>
