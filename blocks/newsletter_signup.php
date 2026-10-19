<?php
/**
 * Block „Newsletter-Anmeldung“ (Erweiterung newsletter): Formular mit E-Mail, optional Name, Verteilern und Einwilligung.
 * Spamschutz wie die Formulare des Kerns (Core\SpamGuard) – das Prüf-Token holt das Skript beim Laden (gecachte Seiten).
 * Ohne Skript: normales Absenden, Antwort als eigene Seite.
 * @var \Core\Block $b  @var array $d
 */
use Klxm\Newsletter\Newsletter;
use Klxm\Newsletter\Repo;

$on = Newsletter::on();
// Angebot: im Block gewählte Verteiler (auch interne), sonst alle öffentlichen
$chosen = array_values(array_filter(array_map('intval', (array) ($d['lists'] ?? []))));
$lists = $on ? array_values(array_filter(Repo::lists(), fn($l) => $chosen ? in_array((int) $l['id'], $chosen, true) : (int) $l['public'] === 1)) : [];
$id = 'nl-' . substr(md5((string) ($b->id ?? uniqid())), 0, 6);
?>
<div class="<?= e(app()->theme->def['container_class'] ?? 'wrap') ?> nl-block">
  <?php if (trim((string) ($d['title'] ?? '')) !== '' || is_editing()): ?><h2 class="nl-block__title"<?= $b->edit('title') ?>><?= e((string) ($d['title'] ?? '')) ?></h2><?php endif; ?>
  <?php if (trim((string) ($d['text'] ?? '')) !== ''): ?><div class="nl-block__text"><?= rich((string) $d['text']) ?></div><?php endif; ?>
  <?php if (!$on): ?>
    <?php if (is_editing()): ?><p class="nl-note"><?= e(__('Die Funktion „Newsletter“ ist auf dieser Website ausgeschaltet.')) ?></p><?php endif; ?>
  <?php else: ?>
  <link rel="stylesheet" href="<?= e(Newsletter::asset('css/newsletter.css')) ?>">
  <form class="nl-form" method="post" action="<?= e(url(Newsletter::BASE . '/anmelden')) ?>" data-nl data-token-url="<?= e(url(Newsletter::BASE . '/token')) ?>" data-error="<?= e(lt('Die Anmeldung hat nicht geklappt. Bitte versuchen Sie es erneut.')) ?>" data-error-network="<?= e(lt('Keine Verbindung – bitte versuchen Sie es erneut.')) ?>" novalidate>
    <input type="hidden" name="_token" value="" data-nl-token>
    <input type="hidden" name="_pow" value="" data-nl-pow>
    <input type="hidden" name="offer" value="<?= e(Newsletter::signOffer(array_column($lists, 'id'))) ?>">
    <div class="nl-hp" aria-hidden="true"><label><?= e(lt('Bitte leer lassen')) ?><input type="text" name="<?= e(\Core\SpamGuard::HONEYPOT) ?>" tabindex="-1" autocomplete="off"></label></div>
    <div class="nl-form__row">
      <?php if (!empty($d['ask_name'])): ?>
      <div class="nl-field"><label for="<?= e($id) ?>-name"><?= e(lt('Name')) ?> <span class="nl-opt">(<?= e(lt('optional')) ?>)</span></label>
        <input id="<?= e($id) ?>-name" name="name" type="text" autocomplete="name" maxlength="120"></div>
      <?php endif; ?>
      <div class="nl-field"><label for="<?= e($id) ?>-email"><?= e(lt('E-Mail-Adresse')) ?></label>
        <input id="<?= e($id) ?>-email" name="email" type="email" autocomplete="email" required maxlength="254" inputmode="email"></div>
    </div>
    <?php if (count($lists) > 1): ?>
    <fieldset class="nl-lists"><legend><?= e(lt('Themen')) ?></legend>
      <?php foreach ($lists as $i => $l): ?>
      <label class="nl-check"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> <span><?= e($l['name']) ?><?php if ($l['description'] !== ''): ?><small><?= e($l['description']) ?></small><?php endif; ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <?php elseif ($lists): ?><input type="hidden" name="lists[]" value="<?= (int) $lists[0]['id'] ?>"><?php endif; ?>
    <label class="nl-check nl-consent"><input type="checkbox" name="consent" value="1" required> <span><?= e(Newsletter::text('consent_text')) ?></span></label>
    <p class="nl-msg" role="status" aria-live="polite" data-nl-msg hidden></p>
    <button class="btn btn--primary nl-submit" type="submit"><?= e(trim((string) ($d['button'] ?? '')) ?: lt('Anmelden')) ?></button>
  </form>
  <script src="<?= e(Newsletter::asset('js/newsletter.js')) ?>" defer></script>
  <?php endif; ?>
</div>
