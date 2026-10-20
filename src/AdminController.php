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

    /** Angaben einer Ausgabe neben den Bausteinen */
    public static function campaignFields(): array
    {
        $lists = [];
        foreach (Repo::lists() as $l) $lists[(string) $l['id']] = $l['name'] . ' (' . (int) $l['active'] . ')';
        // Schlüssel „d{id}“: rein numerische Schlüssel ab 0 hielte Core\Fields für eine Liste ohne Schlüssel
        $designs = ['d0' => __('wie beim Verteiler')];
        foreach (Design::all() as $d) $designs['d' . (int) $d['id']] = $d['name'];
        return [
            ['name' => 'subject', 'label' => __('Betreff'), 'type' => 'text', 'required' => true, 'max' => 150,
                'help' => __('Kurz und konkret (unter 60 Zeichen wird er überall ganz angezeigt). Platzhalter: {name}.')],
            ['name' => 'preheader', 'label' => __('Vorschautext'), 'type' => 'text', 'max' => 150, 'help' => __('Erscheint im Postfach neben dem Betreff.')],
            ['name' => 'lists', 'label' => __('Verteiler'), 'type' => 'multiselect', 'options' => $lists, 'required' => true,
                'help' => __('In Klammern: aktive Empfänger. Wer in mehreren Verteilern steht, erhält die Ausgabe nur einmal.')],
            ['name' => 'design_id', 'label' => __('Design'), 'type' => 'select', 'options' => $designs, 'required' => true, 'width' => 'half'],
        ];
    }

    /** Neue Ausgabe: Vorlage wählen */
    public function create(Request $r): Response
    {
        $this->guard($r);
        if (!Repo::lists()) Repo::defaultList();
        return $this->show('gallery', ['builtin' => Templates::builtin(), 'saved' => Templates::saved(), 'tab' => 'campaign', 'title' => __('Neue Ausgabe')]);
    }

    public function createFrom(Request $r): Response
    {
        $this->guard($r);
        $key = (string) ($r->post['vorlage'] ?? '');
        $t = $key === 'leer' ? ['subject' => '', 'blocks' => [['type' => 'text', 'data' => []]], 'design_id' => 0] : Templates::find($key);
        if (!$t) return $this->to('/admin/newsletter/neu', 'error', __('Vorlage nicht gefunden.'));
        $id = Repo::saveCampaign(0, ['subject' => (string) ($t['subject'] ?: __('Neue Ausgabe')), 'blocks' => json_encode($t['blocks'], JSON_UNESCAPED_UNICODE),
            'design_id' => (int) $t['design_id'], 'lists' => (string) Repo::defaultList()]);
        return Response::redirect(url('/admin/newsletter/' . $id));
    }

    public function edit(Request $r, string $id, array $values = [], array $blocks = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        $c = ctype_digit($id) ? Repo::campaign((int) $id) : null;
        if (!$c) throw new HttpException(404);
        if (!$values) $values = ['subject' => $c['subject'], 'preheader' => $c['preheader'], 'lists' => array_map('strval', Repo::campaignLists($c)), 'design_id' => 'd' . (int) $c['design_id']];
        $editable = in_array($c['status'], ['draft', 'scheduled'], true);
        return $this->show('campaign', ['campaign' => $c, 'values' => $values, 'blocks' => $blocks ?: Blocks::of($c), 'errors' => $errors, 'editable' => $editable,
            'audience' => Campaigns::audience((array) ($values['lists'] ?? [])), 'events' => Repo::events(['campaign' => (int) $c['id']], 100),
            'tab' => 'campaign', 'title' => $c['subject']], $status);
    }

    /** Eingabe des Editors → [Angaben, Bausteine, Fehler] */
    private static function input(Request $r): array
    {
        [$v, $errors] = Fields::sanitize(self::campaignFields(), (array) ($r->post['f'] ?? []));
        [$blocks, $berr] = Blocks::sanitize($r->post['blocks'] ?? []);
        return [$v, $blocks, $errors + $berr];
    }

    public function save(Request $r, string $id): Response
    {
        $this->guard($r);
        $c = ctype_digit($id) ? Repo::campaign((int) $id) : null;
        if (!$c) throw new HttpException(404);
        if (!in_array($c['status'], ['draft', 'scheduled'], true)) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Versendete Ausgaben lassen sich nicht mehr ändern – „Kopie anlegen“ nutzen.'));
        [$v, $blocks, $errors] = self::input($r);
        if (!$blocks) $errors['blocks'] = __('Bitte mindestens einen Baustein hinzufügen.');
        if ($errors) return $this->edit($r, $id, $v, $blocks, $errors, 422);
        Repo::saveCampaign((int) $id, ['subject' => (string) $v['subject'], 'preheader' => (string) ($v['preheader'] ?? ''), 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
            'design_id' => (int) ltrim((string) ($v['design_id'] ?? ''), 'd'), 'lists' => implode(',', array_map('intval', (array) ($v['lists'] ?? [])))]);
        $next = (string) ($r->post['_next'] ?? '');
        if ($next === 'test') return $this->test($r, $id);
        if ($next === 'template') {
            Templates::saveFrom((array) Repo::campaign((int) $id), (string) ($r->post['template_name'] ?? '') ?: (string) $v['subject']);
            return $this->to('/admin/newsletter/' . (int) $id, 'success', __('Als Vorlage gespeichert – sie steht bei „Neue Ausgabe“ zur Wahl.'));
        }
        return $this->to('/admin/newsletter/' . (int) $id, 'success', __('Ausgabe gespeichert.'));
    }

    /** Live-Vorschau des Editors (ungespeicherter Stand) */
    public function live(Request $r): Response
    {
        $this->guard($r);
        [$v, $blocks] = self::input($r);
        $c = ['id' => (int) ($r->post['id'] ?? 0), 'subject' => (string) ($v['subject'] ?? ''), 'preheader' => (string) ($v['preheader'] ?? ''), 'blocks' => $blocks,
            'design_id' => (int) ltrim((string) ($v['design_id'] ?? ''), 'd'), 'lists' => implode(',', array_map('intval', (array) ($v['lists'] ?? [])))];
        return $this->mail(Message::html($c, Message::vars(['name' => 'Erika Musterfrau', 'email' => 'erika@example.org']) + ['unsubscribe' => '#', 'webversion' => '#'],
            Repo::primaryList(Repo::campaignLists($c)), ['preview' => true, 'unsubscribe' => '#', 'webversion' => '#', 'dark' => !empty($r->post['_dark'])]));
    }

    /** Vorschau einer Vorlage (Galerie) */
    public function templatePreview(Request $r, string $key): Response
    {
        $this->guard($r);
        $t = Templates::find($key) ?? throw new HttpException(404);
        $c = ['id' => 0, 'subject' => (string) $t['subject'], 'preheader' => '', 'blocks' => $t['blocks'], 'design_id' => (int) $t['design_id'], 'lists' => ''];
        return $this->mail(Message::html($c, Message::vars(['name' => 'Erika Musterfrau']) + ['unsubscribe' => '#'], null, ['preview' => true, 'unsubscribe' => '#']));
    }

    public function deleteTemplate(Request $r, string $id): Response
    {
        $this->guard($r);
        Templates::delete((int) $id);
        return $this->to('/admin/newsletter/neu', 'success', __('Vorlage gelöscht.'));
    }

    /** Mail-HTML für Vorschau-Rahmen: eigene CSP, nur gleiche Herkunft darf einbetten */
    private function mail(string $html): Response
    {
        return (new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']))
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN')->header('Cache-Control', 'no-store');
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
        if (!Blocks::of($c)) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Die Ausgabe hat noch keinen Inhalt.'));
        if (!Campaigns::audience(Repo::campaignLists($c))) return $this->to('/admin/newsletter/' . (int) $id, 'error', __('Die gewählten Verteiler haben keine aktiven Empfänger.'));
        if ($c['status'] === 'draft' && !\Core\Mail\Accounts::ready(Newsletter::account())) {
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
        $nid = Repo::saveCampaign(0, ['subject' => $c['subject'], 'preheader' => $c['preheader'], 'blocks' => json_encode(Blocks::of($c), JSON_UNESCAPED_UNICODE),
            'design_id' => (int) $c['design_id'], 'lists' => $c['lists']]);
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
        return $this->mail(Message::html($c, Message::vars(['name' => 'Erika Musterfrau', 'email' => 'erika@example.org']) + ['unsubscribe' => '#', 'webversion' => '#'],
            Repo::primaryList(Repo::campaignLists($c)), ['preview' => in_array($c['status'], ['draft', 'scheduled'], true), 'unsubscribe' => '#', 'webversion' => '#']));
    }

    // ------------------------------------------------------------------ Designs

    public function designs(Request $r): Response
    {
        $this->guard($r);
        return $this->show('designs', ['designs' => Design::all(), 'tab' => 'designs', 'title' => __('Designs')]);
    }

    public function design(Request $r, string $id, array $values = [], string $name = '', int $status = 200): Response
    {
        $this->guard($r);
        $d = Design::find((int) $id) ?? throw new HttpException(404);
        $s = $values ? Design::settings(['settings' => json_encode($values)]) : $d['s'];
        return $this->show('design', ['design' => $d, 'name' => $name ?: $d['name'], 'values' => $values ?: $d['s'] + ['radius' => (string) $d['s']['radius']],
            'head' => Design::headerBlocks($s), 'foot' => Design::footerBlocks($s),
            'issues' => Design::contrastIssues($values ? Design::settings(['settings' => json_encode($values)]) : $d['s']), 'tab' => 'designs', 'title' => $d['name']], $status);
    }

    /** Neues Design auf Grundlage eines vorhandenen – danach direkt im Editor */
    public function newDesign(Request $r): Response
    {
        $this->guard($r);
        $base = Design::find((int) ($r->post['from'] ?? 0)) ?? (Design::all()[0] ?? null);
        $name = trim((string) ($r->post['name'] ?? ''));
        if ($name === '') return $this->to('/admin/newsletter/designs', 'error', __('Bitte einen Namen für das Design eingeben.'));
        $id = Design::save(0, $name, $base['s'] ?? Design::DEFAULTS);
        return $this->to('/admin/newsletter/designs/' . $id, 'success', __('Design „{name}“ angelegt – jetzt Logo, Farben und Schrift anpassen.', ['name' => $name]));
    }

    private static function designInput(Request $r): array
    {
        [$v] = Fields::sanitize(Design::fields(), (array) ($r->post['f'] ?? []));
        $v['logo'] = (int) ($v['logo'] ?? 0);
        $v['radius'] = (int) ($v['radius'] ?? 8);
        [$v['header_blocks']] = Blocks::sanitize($r->post['header_blocks'] ?? [], 'header_blocks');
        [$v['footer_blocks']] = Blocks::sanitize($r->post['footer_blocks'] ?? [], 'footer_blocks');
        $v['blocks_set'] = true;
        return Design::settings(['settings' => json_encode($v)]);
    }

    public function saveDesign(Request $r, string $id): Response
    {
        $this->guard($r);
        $d = Design::find((int) $id) ?? throw new HttpException(404);
        $s = self::designInput($r);
        $act = (string) ($r->post['_action'] ?? 'save');
        if ($act === 'delete') {
            if (count(Design::all()) < 2) return $this->to('/admin/newsletter/designs', 'error', __('Das letzte Design kann nicht gelöscht werden.'));
            Design::delete((int) $id);
            return $this->to('/admin/newsletter/designs', 'success', __('Design gelöscht. Verteiler und Ausgaben damit nutzen jetzt das erste Design.'));
        }
        if ($act === 'copy') {
            $nid = Design::save(0, __('{name} (Kopie)', ['name' => $d['name']]), $d['s']);
            return $this->to('/admin/newsletter/designs/' . $nid, 'success', __('Kopie angelegt.'));
        }
        Design::save((int) $id, (string) ($r->post['name'] ?? $d['name']), $s);
        $issues = Design::contrastIssues($s);
        return $this->to('/admin/newsletter/designs/' . (int) $id, $issues ? 'warning' : 'success', __('Design gespeichert.') . ($issues ? ' ' . __('Bitte Kontraste prüfen.') : ''));
    }

    /** Live-Vorschau des Design-Editors mit der Vorlage „Klassisch“ */
    public function designLive(Request $r): Response
    {
        $this->guard($r);
        $s = self::designInput($r);
        $t = Templates::builtin()['klassisch'];
        $c = ['id' => 0, 'subject' => (string) $t['subject'], 'preheader' => '', 'blocks' => $t['blocks'], 'design_id' => 0, 'lists' => ''];
        return $this->mail(Message::html($c, Message::vars(['name' => 'Erika Musterfrau']) + ['unsubscribe' => '#'], null,
            ['preview' => true, 'unsubscribe' => '#', 'dark' => !empty($r->post['_dark']), 'design' => $s]));
    }

    public function designPreview(Request $r, string $id): Response
    {
        $this->guard($r);
        $d = Design::find((int) $id) ?? throw new HttpException(404);
        $t = Templates::builtin()['klassisch'];
        $c = ['id' => 0, 'subject' => (string) $t['subject'], 'preheader' => '', 'blocks' => $t['blocks'], 'design_id' => (int) $d['id'], 'lists' => ''];
        return $this->mail(Message::html($c, Message::vars(['name' => 'Erika Musterfrau']) + ['unsubscribe' => '#'], null, ['preview' => true, 'unsubscribe' => '#']));
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
            else { Repo::update((int) $s['id'], ['pending_lists' => (string) $list, 'consent_at' => Repo::now()]); Signup::sendConfirm(['pending_lists' => (string) $list] + $s); }
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
        $accounts = ['' => __('wie Zweck „Newsletter versenden“ (Grundeinstellungen)')];
        foreach (\Core\Mail\Accounts::all() as $id => $a) {
            if ($id === 'network') continue;
            $kind = ['smtp' => __('eigener Server'), 'mailjet_smtp' => 'Mailjet (SMTP)', 'mailjet_api' => 'Mailjet (API)'][Newsletter::modeFor($a)];
            $accounts[(string) $id] = ($a['label'] ?: ($id === 'standard' ? __('Standard') : $id)) . ($a['from'] !== '' ? ' – ' . $a['from'] : '') . ' · ' . $kind
                . (\Core\Mail\Accounts::ready($a) ? '' : ' (' . __('nicht versandbereit') . ')');
        }
        $mode = Newsletter::mode();
        $acc = Newsletter::account();
        $d = Bounces::defaults($acc);
        $f = [
            ['type' => 'heading', 'label' => __('Versand')],
            ['name' => 'account', 'label' => __('E-Mail-Konto'), 'type' => 'select', 'options' => $accounts,
                'help' => __('Konten legen Sie unter Grundeinstellungen → E-Mail-Versand an. Daraus ergibt sich alles Weitere: Mailjet-Konto → Rückläufer per Webhook, sonst Bounce-Postfach (IMAP) – die passenden Einstellungen erscheinen nach dem Speichern.')],
            ['name' => 'batch', 'label' => __('Mails je Minute'), 'type' => 'number', 'min' => 10, 'max' => 2000, 'width' => 'half',
                'help' => __('Höchstens so viele je Lauf; große Anbieter (Gmail, GMX/Web.de, T-Online, Outlook) werden zusätzlich einzeln gedrosselt.')],
            ['name' => 'footer', 'label' => __('Absenderangaben im Fuß jeder Ausgabe'), 'type' => 'textarea', 'rows' => 3, 'max' => 600,
                'help' => __('Pflichtangaben wie im Impressum: Name, Anschrift, Kontakt. Der Abmeldelink kommt automatisch dazu.')],
            ['type' => 'heading', 'label' => __('Anmeldung')],
            ['name' => 'consent_text', 'label' => __('Text der Einwilligung'), 'type' => 'textarea', 'rows' => 3, 'max' => 600,
                'help' => __('Steht am Ankreuzfeld des Formulars und wird als Nachweis je Empfänger gespeichert. Leer = Standardtext.')],
            ['name' => 'doi_subject', 'label' => __('Bestätigungsmail: Betreff'), 'type' => 'text', 'max' => 150],
            ['name' => 'doi_text', 'label' => __('Bestätigungsmail: Text'), 'type' => 'textarea', 'rows' => 8, 'max' => 3000,
                'help' => __('Platzhalter: {name}, {site}, {link} (Bestätigungslink – muss enthalten sein), {days}. Leer = Standardtext.')],
        ];
        if ($mode === 'smtp') {
            $from = fn(string $v) => $v !== '' ? __('leer = vom E-Mail-Konto: {v}', ['v' => $v]) : __('leer = vom E-Mail-Konto');
            $f = array_merge($f, [
                ['type' => 'heading', 'label' => __('Rückläufer (Bounce-Postfach)')],
                ['name' => 'bounce_address', 'label' => __('Rücklaufadresse'), 'type' => 'email', 'placeholder' => $d['address'],
                    'help' => __('Leer = Absender des Kontos ({v}). Jede Mail bekommt daraus eine eigene Adresse (…+bezug@), damit Rückläufer eindeutig zuzuordnen sind – das Postfach muss „+“-Adressen annehmen (bei Plesk Standard). Ein eigenes Postfach (z. B. bounce@…) hält das Absender-Postfach sauber.', ['v' => $d['address'] ?: '–'])],
                ['name' => 'imap_host', 'label' => __('IMAP-Server'), 'type' => 'text', 'max' => 191, 'width' => 'half', 'placeholder' => $d['host'], 'help' => $from($d['host'])],
                ['name' => 'imap_user', 'label' => __('Benutzer'), 'type' => 'text', 'max' => 191, 'width' => 'half', 'placeholder' => $d['user'], 'help' => $from($d['user'])],
                ['name' => 'imap_pass', 'label' => __('Passwort'), 'type' => 'secret', 'width' => 'half', 'help' => __('Leer = Passwort des E-Mail-Kontos bzw. unverändert.')],
                ['name' => 'imap_port', 'label' => __('Port'), 'type' => 'number', 'min' => 1, 'max' => 65535, 'width' => 'half'],
                ['name' => 'imap_security', 'label' => __('Verschlüsselung'), 'type' => 'select', 'options' => ['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)'], 'width' => 'half'],
                ['name' => 'imap_verify', 'label' => __('Zertifikat prüfen'), 'type' => 'bool', 'width' => 'half', 'help' => __('Nur für eigene Server mit selbst signiertem Zertifikat ausschalten.')],
                ['name' => 'imap_folder', 'label' => __('Ordner'), 'type' => 'text', 'max' => 120, 'width' => 'half'],
                ['name' => 'imap_after', 'label' => __('Verarbeitete Nachrichten'), 'type' => 'select', 'width' => 'half',
                    'options' => ['seen' => __('als gelesen markieren'), 'move' => __('in Ordner „Newsletter-verarbeitet“ verschieben'), 'delete' => __('löschen')]],
            ]);
        } else {
            $f = array_merge($f, [
                ['type' => 'heading', 'label' => __('Mailjet')],
                ['name' => 'mailjet_track', 'label' => __('Öffnungen und Klicks bei Mailjet messen'), 'type' => 'bool',
                    'help' => __('Standard aus (Datenschutz). Eingeschaltet setzt Mailjet ein Zählpixel und leitet Links um – in der Datenschutzerklärung erwähnen.')],
            ]);
            if ($mode === 'mailjet_api') $f[] = ['name' => 'mailjet_sandbox', 'label' => __('Sandbox (Mailjet prüft nur, versendet nicht)'), 'type' => 'bool'];
        }
        return array_merge($f, [
            ['type' => 'heading', 'label' => __('Regeln und Aufbewahrung')],
            ['name' => 'soft_threshold', 'label' => __('Sperren nach Soft Bounces'), 'type' => 'number', 'min' => 1, 'max' => 10, 'width' => 'half',
                'help' => __('Vorübergehende Fehler (Postfach voll …) aus mindestens zwei Ausgaben, gezählt innerhalb des Zeitraums.')],
            ['name' => 'soft_window', 'label' => __('Zeitraum (Tage)'), 'type' => 'number', 'min' => 7, 'max' => 365, 'width' => 'half'],
            ['name' => 'retention', 'label' => __('Protokoll aufbewahren (Tage)'), 'type' => 'number', 'min' => 30, 'max' => 3650, 'width' => 'half',
                'help' => __('Unbestätigte Anmeldungen werden nach {n} Tagen gelöscht.', ['n' => Newsletter::CONFIRM_DAYS + 1])],
        ]);
    }

    private static function settingsValues(): array
    {
        $out = [];
        foreach (self::settingsFields() as $f) {
            if (!isset($f['name']) || ($f['type'] ?? '') === 'secret') continue;
            $out[$f['name']] = Newsletter::get($f['name'], null);
        }
        $out += ['batch' => 200, 'imap_port' => 993, 'imap_security' => 'ssl', 'imap_verify' => true, 'imap_folder' => 'INBOX', 'imap_after' => 'seen',
            'soft_threshold' => 3, 'soft_window' => 60, 'retention' => 365];
        foreach (['batch' => 200, 'imap_port' => 993, 'soft_threshold' => 3, 'soft_window' => 60, 'retention' => 365, 'imap_folder' => 'INBOX', 'imap_after' => 'seen', 'imap_security' => 'ssl'] as $k => $d) {
            if (($out[$k] ?? null) === null || $out[$k] === '') $out[$k] = $d;
        }
        if (array_key_exists('imap_verify', $out) && $out['imap_verify'] === null) $out['imap_verify'] = true;
        foreach (['consent_text', 'doi_subject', 'doi_text'] as $k) if ((string) ($out[$k] ?? '') === '') $out[$k] = Newsletter::text($k);
        return $out;
    }

    public function settings(Request $r, array $values = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        return $this->show('settings', ['values' => $values ?: self::settingsValues(), 'errors' => $errors, 'tab' => 'settings', 'title' => __('Einstellungen'),
            'hook' => Newsletter::hookUrl(), 'dns' => Health::dns(true), 'imapLast' => json_decode((string) Newsletter::get('imap_last', ''), true) ?: [],
            'hookLast' => (int) Newsletter::get('hook_last', 0), 'account' => Newsletter::account()], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $this->guard($r);
        [$v, $errors] = Fields::sanitize(self::settingsFields(), (array) ($r->post['f'] ?? []));
        $doi = trim((string) ($v['doi_text'] ?? ''));
        if ($doi !== '' && !str_contains($doi, '{link}')) $errors['doi_text'] = __('Der Text braucht den Platzhalter {link}.');
        if (($b = trim((string) ($v['bounce_address'] ?? ''))) !== '' && str_contains(strstr($b, '@', true) ?: '', '+')) {
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
