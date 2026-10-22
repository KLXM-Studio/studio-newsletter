<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
/**
 * Erweiterung „newsletter“: Anmeldung mit Double-Opt-in (Block), Verteiler, Ausgaben mit Testversand und Termin,
 * gedrosselter Versand über die Cron-Warteschlange, Rückläufer (Hard/Soft Bounce, Beschwerden) mit Sperrliste.
 *
 * Versandarten: eigener SMTP-Server + Bounce-Postfach (IMAP, VERP) | Mailjet über SMTP | Mailjet über die API (je mit Webhooks).
 * Aufbau: src/Engine = Fachlogik ohne CMS (für eine spätere Fassung für REDAXO 6), src/*.php = Anbindung an KLXM Studio.
 * Aktivieren je Website: Administration → Funktionen & Erweiterungen. Recht: „Newsletter verwalten“ (newsletter.manage). Doku: README.md.
 */
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Klxm\\Newsletter\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Klxm\\Newsletter\\'))) . '.php';
        if (is_file($file)) require $file;
    }
});

use Klxm\Newsletter\Access;
use Klxm\Newsletter\AdminController;
use Klxm\Newsletter\Newsletter;
use Klxm\Newsletter\Repo;
use Klxm\Newsletter\SiteController;

return [
    'name' => 'newsletter',
    'label' => 'Newsletter – Anmeldung, Versand, Rückläufer',
    'version' => '0.6.3',
    'requires' => '>=1.0.0',
    'description' => 'Newsletter mit Double-Opt-in, Verteilern und gedrosseltem Versand – über den eigenen SMTP-Server (Rückläufer per IMAP) oder Mailjet (SMTP oder API, Rückläufer per Webhook). Hard und Soft Bounces, Beschwerden und Sperrliste.',
    'author' => 'KLXM Crossmedia GmbH and contributors',
    'license' => 'MIT',
    'provides' => ['Menüpunkt „Newsletter“ (Ausgaben, Empfänger, Verteiler, Protokoll, Einstellungen)', 'Block „Newsletter-Anmeldung“',
        'Seiten unter /newsletter (Bestätigen, Abmelden, Webversion)', 'E-Mail-Zweck „Newsletter versenden“', 'Versandweg „Mailjet (API)“ für E-Mail-Konten',
        'Geplante Aufgaben: versenden (jede Minute), Rückläufer abrufen (alle 5 Minuten), aufräumen (täglich)', 'Recht „Newsletter verwalten“'],
    'commands' => ['newsletter:send', 'newsletter:bounces', 'newsletter:purge', 'newsletter:selftest'],
    'schedule' => [
        'send' => ['label' => 'Newsletter versenden', 'command' => 'newsletter:send', 'cron' => '* * * * *', 'scope' => 'site', 'timeout' => 120],
        'bounces' => ['label' => 'Newsletter: Rückläufer abrufen', 'command' => 'newsletter:bounces', 'cron' => '*/5 * * * *', 'scope' => 'site', 'timeout' => 300],
        'purge' => ['label' => 'Newsletter: Protokoll und unbestätigte Anmeldungen aufräumen', 'command' => 'newsletter:purge', 'cron' => '35 4 * * *', 'scope' => 'site'],
    ],
    'usage' => function (): ?string {
        try {
            $c = Repo::counts();
        } catch (\Throwable) {
            return null;
        }
        return $c['active'] || $c['pending'] ? __('{n} Empfänger', ['n' => $c['active']]) . ($c['pending'] ? ' · ' . __('{n} unbestätigt', ['n' => $c['pending']]) : '') : null;
    },
    'boot' => function (Core\Extension $x): void {
        $x->feature(Newsletter::FEATURE, 'Newsletter: Anmeldung, Versand, Rückläufer', [Newsletter::PERM, Newsletter::PERM_SEND], false);
        $x->permissions('Newsletter', [Newsletter::PERM => 'Newsletter verwalten (alles: Einstellungen, Designs, Verteiler, alle Ausgaben und Empfänger, Sperren)',
            Newsletter::PERM_SEND => 'Ausgaben schreiben und versenden – nur für zugewiesene Verteiler (Newsletter → Verteiler)']);
        foreach (Repo::tables() as $name => $define) $x->table($name, $define);
        $x->mailPurpose('send', 'Newsletter versenden', 'Ausgaben und Bestätigungsmails der Newsletter-Erweiterung');
        $x->mailTransport('mailjet', 'Mailjet (API)', fn(array $acc) => Klxm\Newsletter\MailjetTransport::fromAccount($acc));

        // Verteiler zur Auswahl im Block: nur in der Verwaltung laden (Editor-Formular, Speichern) – auf der Website keine Abfrage
        $listOptions = [];
        // (Erweiterungen starten vor der Anfrage – deshalb die aufgerufene Adresse, auch bei eigener Verwaltungsadresse)
        $uri = PHP_SAPI === 'cli' ? '' : (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($uri !== '' && str_starts_with($uri . '/', rtrim(url('/admin'), '/') . '/')) {
            try { foreach (Repo::lists() as $l) $listOptions[(string) $l['id']] = $l['name'] . ((int) $l['public'] ? '' : ' (' . __('intern') . ')'); } catch (\Throwable) {}
        }
        $x->blocks(['newsletter_signup' => ['label' => __('Newsletter-Anmeldung'), 'icon' => 'envelope-simple', 'group' => 'Formulare',
            'help' => __('Formular zur Anmeldung mit Bestätigung per E-Mail (Double-Opt-in). Verteiler und Texte: Verwaltung → Newsletter.'),
            'fields' => [
                ['name' => 'title', 'label' => __('Überschrift'), 'type' => 'text', 'max' => 120, 'default' => 'Newsletter abonnieren'],
                ['name' => 'text', 'label' => __('Text (optional)'), 'type' => 'richtext'],
                ['name' => 'lists', 'label' => __('Angebotene Verteiler'), 'type' => 'multiselect', 'options' => $listOptions,
                    'help' => __('Leer = alle öffentlichen Verteiler. Gewählte Verteiler erscheinen auch, wenn sie intern sind – so bekommt eine Seite ihren eigenen Newsletter.')],
                ['name' => 'ask_name', 'label' => __('Nach dem Namen fragen (freiwillig)'), 'type' => 'bool'],
                ['name' => 'button', 'label' => __('Beschriftung des Knopfs'), 'type' => 'text', 'max' => 40, 'default' => 'Anmelden'],
            ]]]);

        $x->docs('manual', ['newsletter' => ['title' => 'Newsletter', 'after' => 'medien', 'file' => __DIR__ . '/docs/manual.php']]);
        $x->docs('technical', ['newsletter' => ['title' => 'Newsletter (Erweiterung): Versand und Rückläufer', 'part' => 3, 'after' => 'live', 'file' => __DIR__ . '/docs/technical.php']]);
        $x->health(fn(): array => Klxm\Newsletter\Health::checks());
        $x->adminAssets(fn(string $view) => $view === 'newsletter' ? ['css/newsletter-admin.css', 'js/newsletter-admin.js'] : []);
        $x->adminPage(['href' => '/admin/newsletter', 'label' => 'Newsletter', 'kind' => 'content', 'icon' => 'paper-plane-tilt', 'visible' => fn() => Access::any(),
            'feature' => Newsletter::FEATURE, 'description' => 'Ausgaben schreiben und versenden, Empfänger und Rückläufer']);
        $x->dashboard(function (array $user): array {
            if (!Newsletter::on()) return [];
            $c = Repo::counts();
            return ['tiles' => [['key' => 'newsletter', 'label' => __('Newsletter'), 'value' => fmt()->number($c['active']), 'text' => __('aktive Empfänger'), 'href' => '/admin/newsletter/empfaenger']]];
        }, Newsletter::PERM);   // Kennzahl der ganzen Website nur für die Verwaltung

        $x->routes(function (Core\Http\Router $r): void {
            $s = SiteController::class;
            $b = Newsletter::BASE;
            $r->get($b . '/token', [$s, 'token']);
            $r->post($b . '/anmelden', [$s, 'subscribe']);
            $r->get($b . '/bestaetigen/{token}', [$s, 'confirm']);
            $r->get($b . '/abmelden/{token}', [$s, 'unsubscribeForm']);
            $r->post($b . '/abmelden/{token}', [$s, 'unsubscribe']);
            $r->get($b . '/ausgabe/{token}', [$s, 'web']);
            $r->get($b . '/hook/mailjet/{token}', [$s, 'mailjet']);
            $r->post($b . '/hook/mailjet/{token}', [$s, 'mailjet']);

            $c = AdminController::class;
            // Recht prüft der Controller genauer (Verwaltung bzw. Redaktion mit zugewiesenen Verteilern); hier genügt eines von beiden
            $p = ['perm' => [Newsletter::PERM, Newsletter::PERM_SEND]];
            $r->get('/admin/newsletter', [$c, 'index'], $p);
            $r->get('/admin/newsletter/neu', [$c, 'create'], $p);
            $r->post('/admin/newsletter/neu', [$c, 'createFrom'], $p);
            $r->post('/admin/newsletter/live', [$c, 'live'], $p);
            $r->get('/admin/newsletter/vorlage/{key}/vorschau', [$c, 'templatePreview'], $p);
            $r->post('/admin/newsletter/vorlagen/{id}/loeschen', [$c, 'deleteTemplate'], $p);
            $r->get('/admin/newsletter/designs', [$c, 'designs'], $p);
            $r->post('/admin/newsletter/designs/live', [$c, 'designLive'], $p);
            $r->post('/admin/newsletter/designs/neu', [$c, 'newDesign'], $p);
            $r->get('/admin/newsletter/designs/{id}', [$c, 'design'], $p);
            $r->post('/admin/newsletter/designs/{id}', [$c, 'saveDesign'], $p);
            $r->get('/admin/newsletter/designs/{id}/vorschau', [$c, 'designPreview'], $p);
            $r->get('/admin/newsletter/empfaenger', [$c, 'subscribers'], $p);
            $r->post('/admin/newsletter/empfaenger/import', [$c, 'import'], $p);
            $r->post('/admin/newsletter/empfaenger/sperren', [$c, 'blockMany'], $p);
            $r->get('/admin/newsletter/empfaenger/export.csv', [$c, 'export'], $p);
            $r->get('/admin/newsletter/empfaenger/{id}', [$c, 'subscriber'], $p);
            $r->post('/admin/newsletter/empfaenger/{id}', [$c, 'saveSubscriber'], $p);
            $r->get('/admin/newsletter/verteiler', [$c, 'lists'], $p);
            $r->post('/admin/newsletter/verteiler', [$c, 'saveList'], $p);
            $r->get('/admin/newsletter/protokoll', [$c, 'events'], $p);
            $r->get('/admin/newsletter/einstellungen', [$c, 'settings'], $p);
            $r->post('/admin/newsletter/einstellungen', [$c, 'saveSettings'], $p);
            $r->post('/admin/newsletter/einstellungen/aktion', [$c, 'action'], $p);
            $r->get('/admin/newsletter/{id}', [$c, 'edit'], $p);
            $r->post('/admin/newsletter/{id}', [$c, 'save'], $p);
            $r->get('/admin/newsletter/{id}/vorschau', [$c, 'preview'], $p);
            $r->post('/admin/newsletter/{id}/test', [$c, 'test'], $p);
            $r->post('/admin/newsletter/{id}/senden', [$c, 'send'], $p);
            $r->post('/admin/newsletter/{id}/anhalten', [$c, 'pause'], $p);
            $r->post('/admin/newsletter/{id}/abbrechen', [$c, 'cancel'], $p);
            $r->post('/admin/newsletter/{id}/kopie', [$c, 'copy'], $p);
            $r->post('/admin/newsletter/{id}/loeschen', [$c, 'delete'], $p);
        });

        $on = fn(): bool => Newsletter::on();
        $x->command('newsletter:send', 'Newsletter: fällige Ausgaben starten und einen gedrosselten Stapel versenden', function (array $args) use ($on): int {
            if (!$on()) return 0;
            Klxm\Newsletter\Campaigns::run(fn(string $m) => print($m . "\n"));
            return 0;
        });
        $x->command('newsletter:bounces', 'Newsletter: Bounce-Postfach (IMAP) abrufen und auswerten', function (array $args) use ($on): int {
            if (!$on() || Newsletter::mode() !== 'smtp') return 0;
            $p = Klxm\Newsletter\Bounces::poll(200, fn(string $m) => print($m . "\n"));
            return $p['error'] !== '' ? 1 : 0;
        });
        $x->command('newsletter:purge', 'Newsletter: Protokoll nach Aufbewahrungsfrist und unbestätigte Anmeldungen löschen', function (array $args) use ($on): int {
            if (!$on()) return 0;
            $r = Repo::purge((int) Newsletter::get('retention', 365) ?: 365);
            echo "Protokoll: {$r['events']} gelöscht, unbestätigte Anmeldungen: {$r['pending']} gelöscht\n";
            return 0;
        });
        $x->command('newsletter:selftest', 'Newsletter: Bounce-Erkennung, VERP, Regeln, Mailjet-Ereignisse, IMAP-Protokoll, Drosselung, Datenbank', fn(array $args): int => Klxm\Newsletter\SelfTest::run());
    },
];
