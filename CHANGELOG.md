# Changelog

## 0.3.0 – 2026-10-20

- Visueller Editor: Ausgaben aus Mail-Bausteinen im Block-Editor der Verwaltung, Live-Vorschau mit Desktop/Handy/Dunkel.
- Bausteine: Aufmacher, Überschrift, Text, Bild, Bild und Text, Spalten (2–3), Einträge aus Datentabelle (auch nur kommende Termine), Knopf, Zitat, Trenner.
- Designs: sechs mitgelieferte (Klar, Warm, Natur, Verein, Kontrast, Minimal), Editor mit Live-Vorschau und Kontrastprüfung; je Verteiler und je Ausgabe wählbar.
- Vorlagen: Klassisch, Magazin, Veranstaltungen, Kurzinfo, Einladung, Rückblick; eigene Ausgaben als Vorlage speichern.
- Ältere Ausgaben (Bild, Inhalt, Knopf) werden als Bausteine übernommen.
- Rich-Text auch im Aufmacher, in den Spalten und im Zitat (fett, kursiv, Links, Listen).

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
