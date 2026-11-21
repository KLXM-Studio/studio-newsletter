<?php
/** Kopf und Bereichsliste „Newsletter“. @var string $tab @var array $flash @var array $counts */
$items = [
    'campaigns' => ['/admin/newsletter', __('Ausgaben'), 'paper-plane-tilt'],
    'subscribers' => ['/admin/newsletter/empfaenger', __('Empfänger'), 'users'],
    'repermit' => ['/admin/newsletter/altbestand', __('Altbestand'), 'user-check'],
    'lists' => ['/admin/newsletter/verteiler', __('Verteiler'), 'list-bullets'],
    'designs' => ['/admin/newsletter/designs', __('Designs'), 'palette'],
    'events' => ['/admin/newsletter/protokoll', __('Protokoll'), 'clipboard-text'],
    'settings' => ['/admin/newsletter/einstellungen', __('Einstellungen'), 'gear-six'],
];
// Redaktion: nur ihre Verteiler; „Altbestand“ nur, wenn die Funktion bei einem ihrer Verteiler eingeschaltet ist
if (!\Klxm\Newsletter\Access::manager()) $items = array_intersect_key($items, ['campaigns' => 1, 'subscribers' => 1] + (\Klxm\Newsletter\Repermit::lists() || $tab === 'repermit' ? ['repermit' => 1] : []));
$current = ['campaign' => 'campaigns', 'subscriber' => 'subscribers', 'design' => 'designs'][$tab] ?? $tab;
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= e(__('Erweiterung')) ?> · <?= e(__('Newsletter')) ?></p><h1><?= e(__('Newsletter')) ?></h1>
    <p class="adm-muted"><?= e(__('{a} aktive Empfänger · {p} unbestätigt · {b} gesperrt', ['a' => fmt()->number($counts['active'] ?? 0), 'p' => fmt()->number($counts['pending'] ?? 0), 'b' => fmt()->number(($counts['bounced'] ?? 0) + ($counts['complained'] ?? 0))])) ?></p></div>
  <?php if ($tab === 'campaigns'): ?><a class="adm-btn adm-btn--primary" href="<?= e(url('/admin/newsletter/neu')) ?>"><?= icon('plus') ?> <?= e(__('Neue Ausgabe')) ?></a><?php endif; ?>
</header>
<?php
$nav = [];
foreach ($items as $k => [$href, $label, $ico]) $nav[$k] = ['href' => url($href), 'label' => $label, 'icon' => $ico, 'badge' => null];
echo \Core\AdminUi::open($nav, $current, __('Newsletter'), false);
$GLOBALS['nl_shell'] = true;
