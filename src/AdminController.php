<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Core\Fields;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Theme;
use Klxm\Newsletter\Engine\Address;

/**
 * Verwaltung → Newsletter (Recht newsletter.manage): Ausgaben (schreiben, testen, planen, pausieren, Bericht),
 * Empfänger (Suche, Status, Verteiler, Sperre aufheben, löschen, Import), Verteiler, Protokoll, Einstellungen.
 */
final class AdminController extends \Core\Http\Controllers\Admin\AdminController
{
    private function guard(Request $r): array
    {
        if (!Newsletter::on()) throw new HttpException(404);
        return $this->auth($r, Newsletter::PERM);
    }

    private function show(string $view, array $vars = [], int $status = 200): Response
    {
        $vars += ['errors' => [], 'tab' => $view];
        $vars['flash'] = app()->session->takeFlash();
        $vars['user'] = app()->auth->user();
        $vars['counts'] = Repo::counts();
        $dir = dirname(__DIR__) . '/views/admin/';
        $GLOBALS['nl_shell'] = false;
        $content = Theme::capture($dir . '_nav.php', $vars) . Theme::capture($dir . $view . '.php', $vars)
            . (!empty($GLOBALS['nl_shell']) ? \Core\AdminUi::close() : '');
        $html = Theme::capture(ROOT . '/app/Admin/views/layout.php', $vars + ['content' => $content, 'view' => 'newsletter', 'title' => $vars['title'] ?? __('Newsletter')]);
        return new Response($html, $status);
    }

    private function to(string $path, string $type, string $msg): Response
    {
        app()->session->flash($type, $msg);
        return Response::redirect(url($path));
    }

    // ------------------------------------------------------------------ Ausgaben

    public function index(Request $r): Response
    {
        $this->guard($r);
        return $this->show('campaigns', ['campaigns' => Repo::campaigns(), 'title' => __('Newsletter')]);
    }

    public static function campaignFields(): array
    {
        $lists = [];
        foreach (Repo::lists() as $l) $lists[(string) $l['id']] = $l['name'] . ' (' . (int) $l['active'] . ')';
        return [
            ['name' => 'subject', 'label' => __('Betreff'), 'type' => 'text', 'required' => true, 'max' => 150,
                'help' => __('Kurz und konkret (unter 60 Zeichen wird er überall ganz angezeigt). Platzhalter: {name}.')],
            ['name' => 'preheader', 'label' => __('Vorschautext'), 'type' => 'text', 'max' => 150, 'help' => __('Erscheint im Postfach neben dem Betreff.')],
            ['name' => 'image', 'label' => __('Bild oben (optional)'), 'type' => 'media'],
            ['name' => 'body', 'label' => __('Inhalt'), 'type' => 'richtext', 'required' => true,
                'help' => __('Platzhalter: {name} (Name des Empfängers, kann leer sein). Abmeldelink und Absenderangaben fügt die Vorlage selbst an.')],
            ['name' => 'button_label', 'label' => __('Knopf: Beschriftung'), 'type' => 'text', 'max' => 60, 'width' => 'half'],
            ['name' => 'button_link', 'label' => __('Knopf: Ziel'), 'type' => 'link', 'width' => 'half'],
            ['name' => 'lists', 'label' => __('Verteiler'), 'type' => 'multiselect', 'options' => $lists, 'required' => true,
                'help' => __('In Klammern: aktive Empfänger. Wer in mehreren Verteilern steht, erhält die Ausgabe nur einmal.')],
        ];
    }

    public function edit(Request $r, string $id = '0', array $values = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        $c = (int) $id > 0 ? Repo::campaign((int) $id) : null;
        if ((int) $id > 0 && !$c) throw new HttpException(404);
        if (!Repo::lists()) Repo::defaultList();
        if (!$values) $values = $c ? ['subject' => $c['subject'], 'preheader' => $c['preheader'], 'image' => (int) $c['image'] ?: null, 'body' => $c['body'],
            'button_label' => $c['button_label'], 'button_link' => $c['button_link'], 'lists' => array_map('strval', Repo::campaignLists($c))]
            : ['lists' => [(string) Repo::defaultList()]];
        $editable = !$c || in_array($c['status'], ['draft', 'scheduled'], true);
        return $this->show('campaign', ['campaign' => $c, 'values' => $values, 'errors' => $errors, 'editable' => $editable,
            'audience' => Campaigns::audience((array) ($values['lists'] ?? [])), 'events' => $c ? Repo::events(['campaign' => (int) $c['id']], 100) : [],
            'tab' => 'campaign', 'title' => $c ? $c['subject'] : __('Neue Ausgabe')], $status);
    }

    public function save(Request $r, string $id = '0'): Response
    {
        $this->guard($r);
        $c = (int) $id > 0 ? Repo::campaign((int) $id) : null;
        if ($c && !in_array($c['status'], ['draft', 'scheduled'], true)) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Versendete Ausgaben lassen sich nicht mehr ändern – „Kopie anlegen“ nutzen.'));
        [$v, $errors] = Fields::sanitize(self::campaignFields(), (array) ($r->post['f'] ?? []));
        if (($v['button_label'] ?? '') !== '' && Message::href((string) ($v['button_link'] ?? '')) === '') $errors['button_link'] = __('Bitte ein Ziel für den Knopf wählen.');
        if ($errors) return $this->edit($r, $id, $v, $errors, 422);
        $data = ['subject' => (string) $v['subject'], 'preheader' => (string) ($v['preheader'] ?? ''), 'body' => (string) $v['body'], 'image' => (int) ($v['image'] ?? 0),
            'button_label' => (string) ($v['button_label'] ?? ''), 'button_link' => (string) ($v['button_link'] ?? ''),
            'lists' => implode(',', array_map('intval', (array) ($v['lists'] ?? [])))];
        $nid = Repo::saveCampaign((int) $id, $data);
        $next = (string) ($r->post['_next'] ?? '');
        if ($next === 'test') return $this->test($r, (string) $nid);
        return $this->to('/admin/newsletter/' . $nid, 'success', __('Ausgabe gespeichert.'));
    }

    public function test(Request $r, string $id): Response
    {
        $u = $this->guard($r);
        $c = Repo::campaign((int) $id) ?? throw new HttpException(404);
        $to = array_values(array_filter(array_map('trim', preg_split('~[,;\s]+~', (string) ($r->post['test_to'] ?? '')) ?: []), fn($e) => Address::valid($e)));
        if (!$to) $to = [(string) ($u['email'] ?? '')];
        $to = array_slice(array_filter($to), 0, 5);
        $res = Campaigns::test($c, $to);
        $bad = array_filter($res, fn($x) => !$x['ok']);
        if ($bad) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Testversand fehlgeschlagen: {msg}', ['msg' => (string) (array_values($bad)[0]['error'] ?? '')]));
        $note = ($res[0]['outcome'] ?? '') === 'redirected' ? ' ' . __('(Test-Umleitung aktiv – die Mail ging an die Test-Adresse.)') : (($res[0]['outcome'] ?? '') === 'logged' ? ' ' . __('(Testumgebung: nur protokolliert, nicht versendet.)') : '');
        return $this->to('/admin/newsletter/' . (int) $id, 'success', __('Testversand an {to}.', ['to' => implode(', ', $to)]) . $note);
    }

    public function send(Request $r, string $id): Response
    {
        $this->guard($r);
        $c = Repo::campaign((int) $id) ?? throw new HttpException(404);
        if (!Campaigns::audience(Repo::campaignLists($c))) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Die gewählten Verteiler haben keine aktiven Empfänger.'));
        if ($c['status'] === 'draft' && !\Core\Mail\Accounts::ready(\Core\Mail\Accounts::route(Newsletter::PURPOSE)) && !(Newsletter::mode() === 'mailjet_api' && Newsletter::mailjet()->configured())) {
            return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Kein versandbereites E-Mail-Konto für den Zweck „Newsletter“ (Grundeinstellungen → E-Mail-Versand).'));
        }
        $at = trim((string) ($r->post['at'] ?? ''));
        $when = $at !== '' && ($ts = strtotime($at)) && $ts > time() + 60 ? date('Y-m-d H:i:s', $ts) : null;
        Campaigns::schedule((int) $id, $when);
        return $this->to('/admin/newsletter/' . (int) $id, 'success', $when ? __('Versand geplant für {when}.', ['when' => fmt()->datetime($when)])
            : __('Versand gestartet – die Ausgabe geht in Stapeln hinaus (geplante Aufgabe „Newsletter versenden“, jede Minute).'));
    }

    public function pause(Request $r, string $id): Response
    {
        $this->guard($r);
        Campaigns::pause((int) $id);
        return $this->to('/admin/newsletter/' . (int) $id, 'success', __('Versand angehalten.'));
    }

    public function cancel(Request $r, string $id): Response
    {
        $this->guard($r);
        Campaigns::cancel((int) $id);
        return $this->to('/admin/newsletter/' . (int) $id, 'success', __('Versand abgebrochen.'));
    }

    public function copy(Request $r, string $id): Response
    {
        $this->guard($r);
        $c = Repo::campaign((int) $id) ?? throw new HttpException(404);
        $nid = Repo::saveCampaign(0, array_intersect_key($c, array_flip(['preheader', 'body', 'image', 'button_label', 'button_link', 'lists'])) + ['subject' => $c['subject']]);
        return $this->to('/admin/newsletter/' . $nid, 'success', __('Kopie angelegt.'));
    }

    public function delete(Request $r, string $id): Response
    {
        $this->guard($r);
        $c = Repo::campaign((int) $id) ?? throw new HttpException(404);
        if (in_array($c['status'], ['sending', 'scheduled'], true)) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Bitte den Versand zuerst anhalten.'));
        Repo::deleteCampaign((int) $id);
        return $this->to('/admin/newsletter', 'success', __('Ausgabe gelöscht.'));
    }

    public function preview(Request $r, string $id): Response
    {
        $this->guard($r);
        $c = Repo::campaign((int) $id) ?? throw new HttpException(404);
        $m = Message::campaign($c, ['id' => 0, 'email' => 'erika@example.org', 'name' => 'Erika Musterfrau'], false);
        return (new Response($m['html'], 200, ['Content-Type' => 'text/html; charset=utf-8']))->header('X-Frame-Options', 'SAMEORIGIN');
    }

    // ------------------------------------------------------------------ Empfänger

    public function subscribers(Request $r): Response
    {
        $this->guard($r);
        $f = ['status' => (string) ($r->query['status'] ?? ''), 'list' => (int) ($r->query['list'] ?? 0), 'q' => (string) ($r->query['q'] ?? '')];
        $page = max(1, (int) ($r->query['seite'] ?? 1));
        return $this->show('subscribers', ['filter' => $f, 'page' => $page, 'result' => Repo::subscribers($f, $page), 'lists' => Repo::lists(), 'tab' => 'subscribers', 'title' => __('Empfänger')]);
    }

    public function subscriber(Request $r, string $id): Response
    {
        $this->guard($r);
        $s = Repo::find((int) $id) ?? throw new HttpException(404);
        return $this->show('subscriber', ['sub' => $s, 'lists' => Repo::lists(), 'mine' => Repo::listIds((int) $id), 'events' => Repo::events(['subscriber' => (int) $id], 100),
            'suppressed' => Repo::suppressed((string) $s['email']), 'tab' => 'subscribers', 'title' => $s['email']]);
    }

    public function saveSubscriber(Request $r, string $id): Response
    {
        $this->guard($r);
        $s = Repo::find((int) $id) ?? throw new HttpException(404);
        $act = (string) ($r->post['_action'] ?? 'save');
        if ($act === 'delete') {
            Repo::deleteSubscriber((int) $id);
            return $this->to('/admin/newsletter/empfaenger', 'success', __('Empfänger gelöscht. Sperrgründe (Rückläufer, Beschwerde) bleiben anonym in der Sperrliste.'));
        }
        if ($act === 'unblock') {
            Repo::unsuppress((string) $s['email']);
            Repo::update((int) $id, ['status' => 'unsubscribed', 'bounce_reason' => '', 'soft_count' => 0, 'soft_first_at' => 0, 'soft_last_campaign' => 0, 'soft_campaigns' => 0,
                'note' => trim((string) $s['note'] . "\n" . date('Y-m-d') . ' ' . __('Sperre aufgehoben von {name}', ['name' => (string) (app()->auth->user()['name'] ?? '')]))]);
            return $this->to('/admin/newsletter/empfaenger/' . (int) $id, 'success', __('Sperre aufgehoben. Der Empfänger ist abgemeldet – erneut anmelden kann er sich selbst (Bestätigung per E-Mail).'));
        }
        if ($act === 'confirm') {
            if ($s['status'] !== 'pending') return $this->to('/admin/newsletter/empfaenger/' . (int) $id, 'error', __('Nur für ausstehende Anmeldungen.'));
            $err = Signup::sendConfirm($s);
            return $this->to('/admin/newsletter/empfaenger/' . (int) $id, $err ? 'error' : 'success', $err ?: __('Bestätigungsmail erneut gesendet.'));
        }
        if ($act === 'unsubscribe') {
            Signup::unsubscribe($s, 'admin');
            return $this->to('/admin/newsletter/empfaenger/' . (int) $id, 'success', __('Abgemeldet.'));
        }
        Repo::update((int) $id, ['name' => mb_substr(trim((string) ($r->post['name'] ?? '')), 0, 120), 'note' => mb_substr((string) ($r->post['note'] ?? ''), 0, 2000)]);
        Repo::setLists((int) $id, (array) ($r->post['lists'] ?? []), true);
        return $this->to('/admin/newsletter/empfaenger/' . (int) $id, 'success', __('Gespeichert.'));
    }

    /** Einfügen aus Tabelle/CSV: „E-Mail;Name“ je Zeile. Ohne Nachweis → Bestätigungsmail (Double-Opt-in) */
    public function import(Request $r): Response
    {
        $this->guard($r);
        $rows = preg_split('~\R~', (string) ($r->post['rows'] ?? '')) ?: [];
        $list = (int) ($r->post['list'] ?? 0) ?: Repo::defaultList();
        $proof = !empty($r->post['proof']);
        $proofText = trim((string) ($r->post['proof_text'] ?? ''));
        if ($proof && $proofText === '') return $this->to('/admin/newsletter/empfaenger', 'error', __('Bitte angeben, woher die Einwilligungen stammen (Nachweis).'));
        $n = ['new' => 0, 'skip' => 0, 'bad' => 0, 'blocked' => 0];
        foreach (array_slice($rows, 0, 5000) as $line) {
            $parts = array_map('trim', preg_split('~[;,\t]~', $line, 2) ?: []);
            $email = Address::normalize((string) ($parts[0] ?? ''));
            if ($email === '') continue;
            if (!Address::valid($email)) { $n['bad']++; continue; }
            if (Repo::suppressed($email)) { $n['blocked']++; continue; }
            if (Repo::byEmail($email)) { $n['skip']++; continue; }
            $s = Repo::upsert(['email' => $email, 'name' => (string) ($parts[1] ?? ''), 'source' => 'import', 'status' => $proof ? 'active' : 'pending',
                'consent' => $proof ? __('Import: {text}', ['text' => $proofText]) : '']);
            if ($proof) Repo::setLists((int) $s['id'], [$list]);
            else { Repo::update((int) $s['id'], ['pending_lists' => (string) $list, 'consent_at' => Repo::now()]); Signup::sendConfirm($s); }
            $n['new']++;
        }
        return $this->to('/admin/newsletter/empfaenger', 'success', __('Import: {new} neu, {skip} schon vorhanden, {blocked} gesperrt, {bad} ungültig.', $n)
            . ($proof || !$n['new'] ? '' : ' ' . __('Die neuen Empfänger haben eine Bestätigungsmail erhalten und sind erst danach aktiv.')));
    }

    public function export(Request $r): Response
    {
        $this->guard($r);
        $rows = app()->db->fetchAll('SELECT email, name, status, confirmed_at, created_at FROM newsletter_subscribers WHERE status = \'active\' ORDER BY email');
        return Response::csv('newsletter-empfaenger-' . date('Y-m-d') . '.csv', ['E-Mail', 'Name', 'Status', 'Bestätigt', 'Angelegt'], array_map('array_values', $rows));
    }

    // ------------------------------------------------------------------ Verteiler

    public function lists(Request $r): Response
    {
        $this->guard($r);
        return $this->show('lists', ['lists' => Repo::lists(), 'tab' => 'lists', 'title' => __('Verteiler')]);
    }

    public function saveList(Request $r): Response
    {
        $this->guard($r);
        $id = (int) ($r->post['id'] ?? 0);
        if (($r->post['_action'] ?? '') === 'delete' && $id) {
            if (count(Repo::lists()) < 2) return $this->to('/admin/newsletter/verteiler', 'error', __('Der letzte Verteiler kann nicht gelöscht werden.'));
            Repo::deleteList($id);
            return $this->to('/admin/newsletter/verteiler', 'success', __('Verteiler gelöscht. Die Empfänger bleiben erhalten.'));
        }
        if (trim((string) ($r->post['name'] ?? '')) === '') return $this->to('/admin/newsletter/verteiler', 'error', __('Bitte einen Namen eingeben.'));
        Repo::saveList($id, $r->post);
        return $this->to('/admin/newsletter/verteiler', 'success', __('Verteiler gespeichert.'));
    }

    // ------------------------------------------------------------------ Protokoll

    public function events(Request $r): Response
    {
        $this->guard($r);
        $type = (string) ($r->query['typ'] ?? '');
        return $this->show('events', ['events' => Repo::events(['type' => $type], 300), 'type' => $type, 'tab' => 'events', 'title' => __('Protokoll')]);
    }

    // ------------------------------------------------------------------ Einstellungen

    public static function settingsFields(): array
    {
        return [
            ['type' => 'heading', 'label' => __('Versand')],
            ['name' => 'mode', 'label' => __('Versandart'), 'type' => 'select', 'required' => true, 'options' => [
                'smtp' => __('Eigener SMTP-Server – Rückläufer über das Bounce-Postfach (IMAP)'),
                'mailjet_smtp' => __('Mailjet über SMTP – Rückläufer per Webhook'),
                'mailjet_api' => __('Mailjet über die API – Rückläufer per Webhook')],
                'help' => __('Absender, Absendername und SMTP-Zugang kommen vom E-Mail-Konto, das unter Grundeinstellungen → E-Mail-Versand dem Zweck „Newsletter versenden“ zugeordnet ist.')],
            ['name' => 'batch', 'label' => __('Mails je Minute'), 'type' => 'number', 'min' => 10, 'max' => 2000, 'width' => 'half',
                'help' => __('Höchstens so viele je Lauf; große Anbieter (Gmail, GMX/Web.de, T-Online, Outlook) werden zusätzlich einzeln gedrosselt.')],
            ['name' => 'footer', 'label' => __('Absenderangaben im Fuß jeder Ausgabe'), 'type' => 'textarea', 'rows' => 3, 'max' => 600,
                'help' => __('Pflichtangaben wie im Impressum: Name, Anschrift, Kontakt. Der Abmeldelink kommt automatisch dazu.')],
            ['name' => 'color', 'label' => __('Farbe für Linie, Links und Knopf'), 'type' => 'color', 'width' => 'half'],
            ['type' => 'heading', 'label' => __('Anmeldung')],
            ['name' => 'consent_text', 'label' => __('Text der Einwilligung'), 'type' => 'textarea', 'rows' => 3, 'max' => 600,
                'help' => __('Steht am Ankreuzfeld des Formulars und wird als Nachweis je Empfänger gespeichert. Leer = Standardtext.')],
            ['name' => 'doi_subject', 'label' => __('Bestätigungsmail: Betreff'), 'type' => 'text', 'max' => 150],
            ['name' => 'doi_text', 'label' => __('Bestätigungsmail: Text'), 'type' => 'textarea', 'rows' => 8, 'max' => 3000,
                'help' => __('Platzhalter: {name}, {site}, {link} (Bestätigungslink – muss enthalten sein), {days}. Leer = Standardtext.')],
            ['type' => 'heading', 'label' => __('Rückläufer (eigener SMTP-Server)'), 'show_if' => ['mode' => ['smtp']]],
            ['name' => 'bounce_address', 'label' => __('Rücklaufadresse'), 'type' => 'email', 'show_if' => ['mode' => ['smtp']],
                'help' => __('Postfach für unzustellbare Mails, z. B. bounce@news.ihre-domain.de. Jede Mail bekommt daraus eine eigene Adresse (bounce+…@), damit Rückläufer eindeutig zuzuordnen sind – das Postfach muss „+“-Adressen annehmen (bei Plesk Standard).')],
            ['name' => 'imap_host', 'label' => __('IMAP-Server'), 'type' => 'text', 'max' => 191, 'show_if' => ['mode' => ['smtp']], 'width' => 'half'],
            ['name' => 'imap_port', 'label' => __('Port'), 'type' => 'number', 'min' => 1, 'max' => 65535, 'show_if' => ['mode' => ['smtp']], 'width' => 'half'],
            ['name' => 'imap_security', 'label' => __('Verschlüsselung'), 'type' => 'select', 'options' => ['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)'], 'show_if' => ['mode' => ['smtp']], 'width' => 'half'],
            ['name' => 'imap_verify', 'label' => __('Zertifikat prüfen'), 'type' => 'bool', 'show_if' => ['mode' => ['smtp']], 'width' => 'half',
                'help' => __('Nur für eigene Server mit selbst signiertem Zertifikat ausschalten.')],
            ['name' => 'imap_user', 'label' => __('Benutzer'), 'type' => 'text', 'max' => 191, 'show_if' => ['mode' => ['smtp']], 'width' => 'half'],
            ['name' => 'imap_pass', 'label' => __('Passwort'), 'type' => 'secret', 'show_if' => ['mode' => ['smtp']], 'width' => 'half', 'help' => __('Leer lassen = unverändert.')],
            ['name' => 'imap_folder', 'label' => __('Ordner'), 'type' => 'text', 'max' => 120, 'show_if' => ['mode' => ['smtp']], 'width' => 'half'],
            ['name' => 'imap_after', 'label' => __('Verarbeitete Nachrichten'), 'type' => 'select', 'show_if' => ['mode' => ['smtp']], 'width' => 'half',
                'options' => ['seen' => __('als gelesen markieren'), 'move' => __('in Ordner „Newsletter-verarbeitet“ verschieben'), 'delete' => __('löschen')]],
            ['name' => 'soft_threshold', 'label' => __('Sperren nach Soft Bounces'), 'type' => 'number', 'min' => 1, 'max' => 10, 'width' => 'half',
                'help' => __('Vorübergehende Fehler (Postfach voll …) aus mindestens zwei Ausgaben, gezählt innerhalb des Zeitraums.')],
            ['name' => 'soft_window', 'label' => __('Zeitraum (Tage)'), 'type' => 'number', 'min' => 7, 'max' => 365, 'width' => 'half'],
            ['type' => 'heading', 'label' => __('Mailjet'), 'show_if' => ['mode' => ['mailjet_smtp', 'mailjet_api']]],
            ['name' => 'mailjet_key', 'label' => __('API-Schlüssel'), 'type' => 'text', 'max' => 120, 'show_if' => ['mode' => ['mailjet_smtp', 'mailjet_api']], 'width' => 'half'],
            ['name' => 'mailjet_secret', 'label' => __('Secret Key'), 'type' => 'secret', 'show_if' => ['mode' => ['mailjet_smtp', 'mailjet_api']], 'width' => 'half', 'help' => __('Leer lassen = unverändert.')],
            ['name' => 'mailjet_track', 'label' => __('Öffnungen und Klicks bei Mailjet messen'), 'type' => 'bool', 'show_if' => ['mode' => ['mailjet_smtp', 'mailjet_api']],
                'help' => __('Standard aus (Datenschutz). Eingeschaltet setzt Mailjet ein Zählpixel und leitet Links um – in der Datenschutzerklärung erwähnen.')],
            ['name' => 'mailjet_sandbox', 'label' => __('Sandbox (Mailjet prüft nur, versendet nicht)'), 'type' => 'bool', 'show_if' => ['mode' => ['mailjet_api']]],
            ['type' => 'heading', 'label' => __('Aufbewahrung')],
            ['name' => 'retention', 'label' => __('Protokoll aufbewahren (Tage)'), 'type' => 'number', 'min' => 30, 'max' => 3650, 'width' => 'half',
                'help' => __('Unbestätigte Anmeldungen werden nach {n} Tagen gelöscht.', ['n' => Newsletter::CONFIRM_DAYS + 1])],
        ];
    }

    private static function settingsValues(): array
    {
        $out = [];
        foreach (self::settingsFields() as $f) {
            if (!isset($f['name']) || ($f['type'] ?? '') === 'secret') continue;
            $out[$f['name']] = Newsletter::get($f['name'], null);
        }
        $out['mode'] = Newsletter::mode();
        $out += ['batch' => 200, 'imap_port' => 993, 'imap_security' => 'ssl', 'imap_verify' => true, 'imap_folder' => 'INBOX', 'imap_after' => 'seen',
            'soft_threshold' => 3, 'soft_window' => 60, 'retention' => 365];
        foreach (['batch' => 200, 'imap_port' => 993, 'soft_threshold' => 3, 'soft_window' => 60, 'retention' => 365, 'imap_folder' => 'INBOX', 'imap_after' => 'seen', 'imap_security' => 'ssl'] as $k => $d) {
            if ($out[$k] === null || $out[$k] === '') $out[$k] = $d;
        }
        if ($out['imap_verify'] === null) $out['imap_verify'] = true;
        if ((string) ($out['color'] ?? '') === '') $out['color'] = Message::color();
        foreach (['consent_text', 'doi_subject', 'doi_text'] as $k) if ((string) ($out[$k] ?? '') === '') $out[$k] = Newsletter::text($k);
        return $out;
    }

    public function settings(Request $r, array $values = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        return $this->show('settings', ['values' => $values ?: self::settingsValues(), 'errors' => $errors, 'tab' => 'settings', 'title' => __('Einstellungen'),
            'hook' => Newsletter::hookUrl(), 'dns' => Health::dns(true), 'imapLast' => json_decode((string) Newsletter::get('imap_last', ''), true) ?: [],
            'hookLast' => (int) Newsletter::get('hook_last', 0), 'account' => \Core\Mail\Accounts::route(Newsletter::PURPOSE)], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $this->guard($r);
        [$v, $errors] = Fields::sanitize(self::settingsFields(), (array) ($r->post['f'] ?? []));
        $doi = trim((string) ($v['doi_text'] ?? ''));
        if ($doi !== '' && !str_contains($doi, '{link}')) $errors['doi_text'] = __('Der Text braucht den Platzhalter {link}.');
        if (($v['mode'] ?? '') === 'smtp' && ($b = trim((string) ($v['bounce_address'] ?? ''))) !== '' && str_contains(strstr($b, '@', true) ?: '', '+')) {
            $errors['bounce_address'] = __('Bitte die Grundadresse ohne „+“ eintragen.');
        }
        if ($errors) return $this->settings($r, $v, $errors, 422);
        foreach (self::settingsFields() as $f) {
            $k = $f['name'] ?? null;
            if ($k === null) continue;
            if (($f['type'] ?? '') === 'secret') {
                $raw = ((array) ($r->post['f'] ?? []))[$k] ?? '';
                Newsletter::setSecret($k, trim(is_string($raw) ? $raw : ''));
                continue;
            }
            $val = $v[$k] ?? null;
            // Standardtexte nicht speichern (Sprache der Website bleibt maßgeblich)
            if (in_array($k, ['consent_text', 'doi_subject', 'doi_text'], true)) {
                Newsletter::set($k, '');
                $val = trim((string) $val) === trim(Newsletter::text($k)) ? '' : (string) $val;
            }
            Newsletter::set($k, is_bool($val) ? $val : (is_int($val) || is_float($val) ? (int) $val : (string) ($val ?? '')));
        }
        Health::dns(true, true);
        return $this->to('/admin/newsletter/einstellungen', 'success', __('Einstellungen gespeichert.'));
    }

    public function action(Request $r): Response
    {
        $this->guard($r);
        $act = (string) ($r->post['_action'] ?? '');
        try {
            switch ($act) {
                case 'imap':
                    $t = Bounces::test();
                    return $t['ok'] ? $this->to('/admin/newsletter/einstellungen', 'success', __('Bounce-Postfach erreichbar: {n} Nachrichten, davon {u} ungelesen.', ['n' => $t['messages'], 'u' => $t['unseen']]))
                        : $this->to('/admin/newsletter/einstellungen', 'error', __('Bounce-Postfach: {msg}', ['msg' => $t['error']]));
                case 'poll':
                    $p = Bounces::poll();
                    return $p['error'] !== '' ? $this->to('/admin/newsletter/einstellungen', 'error', $p['error'])
                        : $this->to('/admin/newsletter/einstellungen', 'success', __('{n} Rückläufer ausgewertet.', ['n' => $p['checked']]));
                case 'mailjet':
                    Newsletter::mailjet()->test();
                    return $this->to('/admin/newsletter/einstellungen', 'success', __('Mailjet: Zugang funktioniert.'));
                case 'webhooks':
                    $res = Newsletter::mailjet()->ensureWebhooks(Newsletter::hookUrl());
                    return $this->to('/admin/newsletter/einstellungen', 'success', __('Mailjet-Webhooks eingerichtet: {list}', ['list' => implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($res), $res))]));
            }
        } catch (\Throwable $e) {
            return $this->to('/admin/newsletter/einstellungen', 'error', $e->getMessage());
        }
        return $this->to('/admin/newsletter/einstellungen', 'error', __('Unbekannte Aktion.'));
    }
}
