# Changelog

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
