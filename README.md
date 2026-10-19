# Newsletter für KLXM Studio (`newsletter`)

Anmeldung mit Double-Opt-in, Verteiler, Ausgaben mit Testversand und Termin, gedrosselter Versand und eine saubere
Auswertung von Rückläufern – über den **eigenen SMTP-Server** (Rückläufer per IMAP) oder **Mailjet** (SMTP oder API,
Rückläufer per Webhook). Lizenz: MIT (`LICENSE`). Paket: `klxm/studio-newsletter`. Für [KLXM Studio](https://studio.klxm.de) ≥ 1.0.0.

## Funktionen

| | |
|---|---|
| Anmeldung | Block „Newsletter-Anmeldung“: E-Mail, optional Name, Themen (öffentliche Verteiler), Einwilligung. Double-Opt-in (Link 7 Tage gültig), Spamschutz des Kerns (Zeit-Token, Honigtopf, Rechenaufgabe, Mengenbegrenzung), höchstens 3 Bestätigungsmails je Adresse und Tag. Antworten verraten nie, ob eine Adresse schon eingetragen ist. Nachweis: Text der Einwilligung, Zeitpunkt, IP nur als HMAC-Kürzel. |
| Ausgaben | Betreff und Vorschautext mit `{name}`, Bild, Inhalt (Rich-Text), Knopf mit Ziel aus dem Struktur-Browser, Verteiler. Vorschau, Testversand (bis 5 Adressen), Versand sofort oder zum Termin, Anhalten, Fortsetzen, Abbrechen, Kopie. Bericht mit allen Meldungen der Mailserver, Webversion. |
| Versand | Cron-Warteschlange (jede Minute), „Mails je Minute“ einstellbar, je Anbieter-Gruppe gedrosselt (Gmail, Microsoft, T-Online, GMX/Web.de, Yahoo, Apple, Freenet); bei vielen Soft Bounces/Blockaden automatisch langsamer. Jede Person erhält eine Ausgabe höchstens einmal. Mail-Vorlage als Tabellen-Layout mit Inline-Stilen und Textfassung, ohne Zählpixel. |
| Pflichten | Abmeldelink und Ein-Klick-Abmeldung (RFC 8058, `List-Unsubscribe-Post`) in jeder Ausgabe, `List-Id`, `Precedence: bulk`, Absenderangaben im Fuß. Prüfung von SPF und DMARC der Absender-Domain. |
| Rückläufer | **Eigener SMTP-Server:** VERP-Rücklaufadresse (`bounce+{bezug}@…`), Bounce-Postfach per IMAP alle 5 Minuten (eigener Client, keine PHP-Erweiterung `imap` nötig). **Mailjet:** Webhooks (bounce, blocked, spam, unsub, sent), auf Knopfdruck eingerichtet. Dazu Ablehnungen beim Einliefern (5xx). |
| Regeln | Hard Bounce → sofort gesperrt. Soft Bounce → gezählt, einmal je Ausgabe; gesperrt ab 3 aus mindestens 2 Ausgaben in 60 Tagen (einstellbar), Zustellung setzt zurück. Beschwerde (Feedback-Loop/ARF, Mailjet spam) → gesperrt. Blockade (Spamverdacht, Sperrliste, SPF/DKIM/DMARC) → Empfänger bleibt, Hinweis an die Redaktion. Abwesenheitsnotizen werden ignoriert. Sperrliste nur als HMAC – bleibt nach dem Löschen eines Empfängers, ein Import trägt gesperrte Adressen nicht wieder ein. |
| Versandweg | „Mailjet (API)“ als Versandart für alle E-Mail-Konten der Website (Formulare, Bestätigungen …) – ohne `symfony/mailjet-mailer`. |

Erkannt werden Zustellberichte nach RFC 3464 (Postfix, Exim, Exchange, Gmail …), Feedback-Reports nach RFC 5965 und
Rückläufer ohne Normformat (deutsche und englische Texte, z. B. GMX/Web.de, T-Online).

## Installation

```bash
composer require klxm/studio-newsletter
php bin/console extensions:publish && php bin/console migrate --all
```

Aktivieren je Website: Administration → Funktionen & Erweiterungen (Funktion „Newsletter“, Standard aus). Recht
„Newsletter verwalten“ (`newsletter.manage`) an die passenden Rollen geben. Die geplanten Aufgaben laufen über die eine
Cron-Zeile des Kerns (`cron:run`).

1. Grundeinstellungen → E-Mail-Versand: Konto anlegen und dem Zweck **„Newsletter versenden“** zuordnen.
2. Newsletter → Einstellungen: Versandart, Absenderangaben, bei eigenem SMTP Rücklaufadresse + IMAP, bei Mailjet API-Schlüssel + „Webhooks bei Mailjet einrichten“.
3. Block „Newsletter-Anmeldung“ auf einer Seite einfügen.

Selbsttest: `php bin/console newsletter:selftest --site=…` · Befehle: `newsletter:send`, `newsletter:bounces`, `newsletter:purge`.

## Aufbau (und Weg nach REDAXO 6)

```
src/Engine/            Fachlogik ohne CMS – nur PHP 8.4 (+ cURL für Mailjet)
  Mime.php             MIME-Leser für Rückläufer (multipart, message/rfc822, QP/base64, RFC 2047)
  Bounce/Classifier    Einordnung nach RFC 3463, SMTP-Code und Text (DE/EN)
  Bounce/Parser        DSN (RFC 3464), ARF (RFC 5965), Abwesenheit, Heuristik
  Bounce/Policy        Regeln Hard/Soft/Beschwerde/Blockade – reine Entscheidung
  Bounce/Processor     Befund anwenden über Engine\Store
  Ref.php              Bezug (VERP, Kopfzeile, Message-ID) mit HMAC
  Imap/Client.php      IMAP über TLS (LOGIN, SELECT, UID SEARCH/FETCH/STORE/MOVE)
  Mailjet/Api, Events  Send API v3.1, Webhooks, Ereignisse → Befunde
  Throttle, Address, Template, Deliverability
  Store.php            Schnittstelle zum Speicher (Studio: Repo)
src/*.php              Anbindung an KLXM Studio (Tabellen, Versand über Core\Mailer, Cron, Verwaltung, Website)
```

Für eine Fassung als REDAXO-6-AddOn bleibt `src/Engine` unverändert; neu sind eine Store-Klasse (z. B. über `rex_sql`
oder YForm), der Versand (z. B. über `rex_mailer`/symfony_mailer), die Backend-Seiten und die Cronjobs.

---

## English

Newsletter extension for KLXM Studio: double opt-in sign-up block, lists, issues with test sending and scheduling, throttled
sending via the core cron queue and proper bounce handling – via your **own SMTP server** (bounces via IMAP and VERP) or
**Mailjet** (SMTP or API, bounces via webhooks). Hard bounces block immediately, soft bounces after 3 from at least 2 issues
within 60 days, complaints (ARF/feedback loop) block, sender blocks only warn. One-click unsubscribe (RFC 8058), suppression
list as HMAC only, SPF/DMARC check, no tracking pixel. Also registers “Mailjet (API)” as a sending method for all email accounts.
The business logic in `src/Engine` has no CMS dependency so it can be reused for a REDAXO 6 add-on.
Self-test: `php bin/console newsletter:selftest --site=…`.
