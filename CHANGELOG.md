# Changelog

## 0.7.0 – 2026-11-21

Altbestand übernehmen und bestätigen lassen (Re-Permission):
- Neuer Bereich **Newsletter → Altbestand**, je Verteiler einschaltbar (Verteiler → „Altbestand bestätigen lassen“, Standard aus). Rechte wie bei den übrigen Newsletter-Funktionen: Verwaltung alle Verteiler, Redaktion nur ihre zugewiesenen.
- Adressen einfügen (Tabelle/CSV, „E-Mail;Name“, Kopfzeile wird erkannt, bis 5000 Zeilen je Durchgang, weitere im Entwurf) mit Pflichtangabe der Herkunft – „früher eingewilligt, ohne Nachweis“, „Bestandskunde (§ 7 Abs. 3 UWG)“, „unbekannt“ – und Pflicht-Häkchen „Diese Personen haben den Newsletter bisher erhalten“. Bei „unbekannt“ Warnhinweis; Versand nur nach zusätzlicher ausdrücklicher Bestätigung (wird mit Name und Zeit vermerkt).
- Übersprungen (mit Zählern): Sperrliste, Rückläufer/Beschwerde, schon bestätigt (aktiv im Verteiler), früher abgelehnt (dieser Verteiler), abgemeldet, laufende Anmeldung, doppelt, ungültig.
- Neuer Empfänger-Status **„Bestätigung ausstehend“** (`reconfirm`): in keinem Verteiler, erhält keine Ausgaben. Wer schon in anderen Verteilern aktiv ist, bleibt dort aktiv.
- Einmalige **Bestätigungsbitte**: feste neutrale Vorlage ohne Werbung und Bausteine (vom Design nur Logo, Trenner, Abstände, Fußtext), anpassbar sind Betreff und Einleitung (ohne Links). Knöpfe „Ja, weiter erhalten“ und „Nein, abmelden“ führen auf eine Seite mit Knopf (Link-Scanner ändern nichts); Ein-Klick-Abmeldung des E-Mail-Programms lehnt ab. Absender und Fußzeile des Verteilers, gedrosselter Versand über die vorhandene Engine (Cron „Newsletter versenden“, VERP/Bezug, Rückläufer nach den vorhandenen Regeln). Höchstens **eine Erinnerung** (von Hand oder nach N Tagen, Standard aus).
- **Frist** (Standard 30 Tage, 7–90): danach – oder mit „Jetzt beenden und Unbestätigte löschen“ – werden Unbestätigte und Unzustellbare gelöscht; die Einträge bleiben nur als anonyme Zähler. Abgelehnte behalten die Prüfsumme der Adresse, damit ein späterer Import sie für diesen Verteiler überspringt.
- **Nachweis** bei „Ja“: Zeitpunkt, IP-Kürzel, Herkunft, Verteiler, Wortlaut der Bitte mit SHA-256 (im Einwilligungsfeld bzw. bei schon Aktiven in der Notiz und im Eintrag); Ereignis „Altbestand bestätigt“ im Verlauf.
- „Nein“ meldet sofort vom Verteiler ab (wie die vorhandene Abmeldung).
- Übersicht je Aktion: bestätigt/offen/abgelehnt/unzustellbar mit Fortschrittsbalken, Datum der automatischen Löschung, Vorschau der Bitte.
- Rückläufer-Regeln: Hard Bounce und Abmeldung beim Versanddienst gelten auch für „Bestätigung ausstehend“.
- Selbsttest um 38 Prüfungen erweitert; Selbsttest räumt eine von Hand gesperrte Testadresse jetzt wirklich ab (Reihenfolge beim Aufräumen).

## 0.6.3 – 2026-10-22

Sicherheit (Befunde der Sicherheitsprüfung):
- Redaktion meldet einen Empfänger nur von den eigenen Verteilern ab (vorher von allen).
- Rückläufer aus dem Bounce-Postfach sperren nur mit gültigem Bezug (VERP/Kopfzeile) – eine gefälschte Beschwerde-Mail kann keine fremde Adresse mehr sperren. Der Mailjet-Webhook (mit Schlüssel) ordnet weiter über die Adresse zu.
- Von Hand gesperrte Adressen: Neuanmeldung schickt keine Bestätigung, ein Bestätigungslink hebt die Sperre nicht auf.
- Bestätigungslink nur für offene Anmeldungen – ein alter Link meldet nach einer Abmeldung nicht wieder an.

## 0.6.2 – 2026-10-21

- Editor: aufgeklappte Bausteine ragten bei schmaler Spalte rechts aus der Karte (Knöpfe und Auswahlfelder außerhalb) – Bausteine sind `<fieldset>` mit Browser-Vorgabe `min-width: min-content`; jetzt nie breiter als die Karte.

## 0.6.1 – 2026-10-21

- Redaktionskommentare `[# … #]` (z. B. aus übernommenen Einträgen einer Datentabelle) erscheinen nie mehr in Mail und Betreff.
- Editor: zwei Spalten erst ab 1440 px Fensterbreite; darunter Vorschau unter dem Formular (vorher überlagerte die Vorschau das Formular bei ca. 1280 px).
- Verteiler-Zuweisung: Administration (Rollen mit allen Rechten) steht nicht mehr zur Auswahl – sie darf ohnehin alles.
- Selbsttest räumt auch seine Protokolleinträge ohne Adresse (Abmeldung, Sperre) wieder ab.

## 0.6.0 – 2026-10-20

- Rechte: neues Recht „Ausgaben schreiben und versenden“ (`newsletter.send`) für die Redaktion – nur Ausgaben und Empfänger der zugewiesenen Verteiler. Zuweisung je Verteiler an Rollen und/oder Personen („Wer darf diesen Verteiler bedienen?“). Einstellungen, Designs, Verteiler, Protokoll, Löschen und Sperren bleiben bei `newsletter.manage`.
- Von Hand sperren: beim Empfänger mit Grund (Vermerk mit Datum und Person, Sperrliste mit Grund „manual“) und mehrere Adressen auf einmal, auch vorab für noch unbekannte Adressen.
- Kopfzahlen der Newsletter-Verwaltung zählen für die Redaktion nur ihre Verteiler.

## 0.5.0 – 2026-10-20

- Einstellungen: E-Mail-Konto direkt wählen; Versandweg ergibt sich aus dem Konto (Mailjet API/SMTP → Webhook, sonst Bounce-Postfach). Mailjet-Zugang und Bounce-Postfach (Rücklaufadresse = Absender, IMAP-Server smtp.→imap., Benutzer, Passwort) kommen aus dem Konto – eigene Werte nur noch zum Überschreiben.
- Verteiler mit eigenem Absender: Versandweg und Rücklaufadresse je Konto.
- Baustein „Einträge aus Datentabelle“: neueste/kommende, gefiltert (Suchbegriff, Feld-Bedingung, Sortierung) oder ausgewählt (Link-Picker, auch mehrere Tabellen, Reihenfolge per Ziehen).

## 0.4.0 – 2026-10-20

- Kopf und Fuß jedes Designs aus Bausteinen (wie CleverReach/Mailjet): neue Bausteine Logo, Linkleiste, Social Media, Fußtext mit Abmeldelink, Abstand.
- Gestaltung je Baustein: Hintergrund (Fläche, zarter Akzent, Akzent, dunkel, eigene Farbe) und Abstand; auf dunklem Grund helle Schrift, Knöpfe umgekehrt.
- Abmeldelink ist Pflicht: fehlt er in Kopf und Fuß, wird er angehängt.
- Baustein „Logo“ mit Titel und Unterzeile (rechts/links daneben, darunter, darüber); Platzhalter {subject}, {date} (Datum der Ausgabe), {site}.
- Vorschau: Klick auf einen Baustein öffnet ihn im Editor; Bausteine per Ziehen ordnen; ein Scrollbereich (Mausrad über der Mail scrollt die Vorschau, Rahmen passt seine Höhe laufend an).

## 0.3.0 – 2026-10-20

- Visueller Editor: Ausgaben aus Mail-Bausteinen im Block-Editor der Verwaltung, Live-Vorschau mit Desktop/Handy/Dunkel.
- Bausteine: Aufmacher, Überschrift, Text, Bild, Bild und Text, Spalten (2–3), Einträge aus Datentabelle (auch nur kommende Termine), Knopf, Zitat, Trenner.
- Designs: sechs mitgelieferte (Klar, Warm, Natur, Verein, Kontrast, Minimal), Editor mit Live-Vorschau und Kontrastprüfung; je Verteiler und je Ausgabe wählbar.
- Vorlagen: Klassisch, Magazin, Veranstaltungen, Kurzinfo, Einladung, Rückblick; eigene Ausgaben als Vorlage speichern.
- Ältere Ausgaben (Bild, Inhalt, Knopf) werden als Bausteine übernommen.
- Rich-Text auch im Aufmacher, in den Spalten und im Zitat (fett, kursiv, Links, Listen).
- „Neues Design“ auf Grundlage eines vorhandenen; Vorschau „Desktop“ in echter Mail-Breite (680 px, verkleinert), „Handy“ 390 px.

## 0.2.0 – 2026-10-20

- Mehrere Newsletter: Verteiler mit eigenem Auftritt (Fußzeile, Farbe, optional eigenes Absender-Konto) für Ausgaben und Bestätigungsmails; interne Verteiler.
- Block „Newsletter-Anmeldung“: angebotene Verteiler wählbar (signiertes Angebot, auch interne Verteiler).
- Abmeldung je Verteiler mit Häkchen und „Alle abbestellen“; Ein-Klick-Abmeldung nur vom Verteiler der Ausgabe; ohne Verteiler = abgemeldet.
- Braucht Core mit `Mailer`-Option `via` für eigene Absender (sonst gilt der Zweck).

## 0.1.0 – 2026-10-20

Erste Fassung.

- Anmeldung über den Block „Newsletter-Anmeldung“ mit Double-Opt-in, Spamschutz des Kerns, Verteilerwahl, Nachweis der Einwilligung (Text, Zeit, IP-Kürzel).
- Ausgaben mit Bild, Inhalt, Knopf, Vorschau, Testversand, Termin, Anhalten/Fortsetzen/Abbrechen, Kopie und Bericht; Webversion.
- Versand über die Cron-Warteschlange (jede Minute), gedrosselt je Anbieter-Gruppe; Versandarten eigener SMTP-Server, Mailjet über SMTP, Mailjet über die API.
- Rückläufer: Bounce-Postfach per IMAP mit VERP-Bezug (eigener IMAP-Client), Mailjet-Webhooks, Ablehnung beim Einliefern; Hard/Soft Bounce, Blockade, Beschwerde, Abwesenheit; Sperrliste als HMAC.
- Abmeldelink und Ein-Klick-Abmeldung (RFC 8058) in jeder Ausgabe; List-Id, Precedence.
- Versandweg „Mailjet (API)“ für alle E-Mail-Konten der Website.
- Health: Konto, Rückläufer-Weg, Webhook, SPF/DMARC, Quoten der letzten Ausgabe. Selbsttest `newsletter:selftest`.
- Fachlogik in `src/Engine` ohne CMS-Abhängigkeit (Grundlage für eine Fassung für REDAXO 6).
