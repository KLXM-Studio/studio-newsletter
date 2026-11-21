<?php /** Handbuch · Kapitel „Newsletter“ (Erweiterung newsletter) */ ?>
  <p class="lead">Mit dem <b>Newsletter</b> melden sich Besucher auf Ihrer Website an, bestätigen per E-Mail (Double-Opt-in) und erhalten Ihre Ausgaben. Unzustellbare Adressen und Spam-Beschwerden erkennt die Erweiterung selbst und sperrt sie – das schützt den Ruf Ihres Absenders.</p>
  <h3>Einrichten</h3>
  <ol>
    <li><b>E-Mail-Konto:</b> Unter <b>Grundeinstellungen → E-Mail-Versand</b> ein Konto anlegen (Absender z. B. news@ihre-domain.de) und dem Zweck <b>„Newsletter versenden“</b> zuordnen.</li>
    <li><b>Newsletter → Einstellungen:</b> das E-Mail-Konto wählen – Mailjet oder eigener Server ergibt sich daraus, ebenso Mailjet-Zugang und Bounce-Postfach (aus den Zugangsdaten des Kontos), Absenderangaben für den Fuß (Name, Anschrift – Pflicht wie im Impressum) und bei Bedarf Texte der Einwilligung und der Bestätigungsmail anpassen.</li>
    <li><b>Anmeldung auf der Website:</b> den Block <b>„Newsletter-Anmeldung“</b> auf einer Seite einfügen (Gruppe „Formulare“). Mit zwei oder mehr öffentlichen Verteilern können Besucher Themen wählen.</li>
  </ol>
  <h3>Mehrere Newsletter (Verteiler)</h3>
  <ul>
    <li>Unter <b>Newsletter → Verteiler</b> legen Sie beliebig viele Newsletter an, z. B. „Vereins-News“ und „Presse“. <b>Öffentliche</b> Verteiler stehen im Anmeldeformular zur Wahl, <b>interne</b> nur, wenn ein Block sie ausdrücklich anbietet.</li>
    <li>Jeder Verteiler kann einen <b>eigenen Auftritt</b> haben: Fußzeile mit Absenderangaben, Farbe und – optional – ein eigenes E-Mail-Konto als Absender (z. B. presse@… statt news@…). Leer = wie in den Einstellungen. Eine Ausgabe an mehrere Verteiler trägt den Auftritt des ersten (Reihenfolge der Liste).</li>
    <li>Im Block <b>„Newsletter-Anmeldung“</b> wählen Sie unter „Angebotene Verteiler“, welche Newsletter er anbietet – so bekommt jede Seite ihren eigenen. Leer = alle öffentlichen.</li>
    <li><b>Abmelden</b>: Die Abmeldeseite zeigt die eigenen Newsletter mit Häkchen (vorausgewählt: die der gerade erhaltenen Ausgabe) und „Alle abbestellen“. Die Ein-Klick-Abmeldung im E-Mail-Programm meldet nur vom Newsletter dieser Ausgabe ab. Wer keinen Newsletter mehr hat, gilt als abgemeldet.</li>
  </ul>
  <h3>Wer darf was? (Rechte)</h3>
  <ul>
    <li><b>Newsletter verwalten</b> (Recht unter Benutzer → Rollen): alles – Einstellungen, Designs, Verteiler, Protokoll, alle Ausgaben und Empfänger, Sperren und Löschen.</li>
    <li><b>Ausgaben schreiben und versenden</b>: für die Redaktion, z. B. eine Rolle „Pressestelle“. Wer nur dieses Recht hat, sieht unter Newsletter nur <b>Ausgaben</b> und <b>Empfänger</b> – und dort nur die Verteiler, die ihm zugewiesen sind. Er kann Ausgaben anlegen, testen und versenden sowie Empfänger seiner Verteiler hinzufügen und pflegen, aber nicht löschen oder sperren.</li>
    <li><b>Zuweisen</b>: unter <b>Newsletter → Verteiler</b> bei „Wer darf diesen Verteiler bedienen?“ Rollen und/oder einzelne Personen ankreuzen. Eine Ausgabe an mehrere Verteiler darf nur bearbeiten, wem alle zugewiesen sind.</li>
  </ul>
  <h3>Versandarten</h3>
  <ul>
    <li><b>Eigener SMTP-Server:</b> Versand über Ihr Konto. Für die Rückläufer eine <b>Rücklaufadresse</b> (z. B. bounce@news.ihre-domain.de) und deren IMAP-Zugang eintragen – die Erweiterung holt unzustellbare Mails alle 5 Minuten ab und wertet sie aus.</li>
    <li><b>Mailjet über SMTP:</b> Konto mit Server in-v3.mailjet.com, Port 587, TLS; Benutzer = API-Schlüssel, Passwort = Secret Key. API-Schlüssel und Secret zusätzlich unter Newsletter → Einstellungen eintragen und <b>„Webhooks bei Mailjet einrichten“</b> klicken.</li>
    <li><b>Mailjet über die API:</b> wie oben, nur ohne SMTP – schneller bei großen Listen. Absender und Antwortadresse kommen weiter vom Konto.</li>
  </ul>
  <h3>Ausgaben gestalten: Vorlagen, Bausteine, Designs</h3>
  <ul>
    <li><b>Neue Ausgabe</b> beginnt mit einer <b>Vorlage</b>: Klassisch, Magazin, Veranstaltungen, Kurzinfo, Einladung, Rückblick – oder leer. Jede bringt Bausteine mit Beispieltexten und ein passendes Design mit. Eigene Ausgaben lassen sich mit <b>„Als Vorlage speichern“</b> wiederverwenden.</li>
    <li>Der Inhalt besteht aus <b>Bausteinen</b> wie im Block-Editor: Aufmacher, Überschrift, Text, Bild, Bild und Text, Spalten, Einträge aus Datentabelle (neueste bzw. kommende – z. B. die nächsten Termine, automatisch aktuell –, gefiltert nach Suchbegriff oder Feld, oder einzeln ausgewählt), Knopf, Zitat, Trenner. Hinzufügen, mit ↑ ↓ ordnen, aufklappen zum Bearbeiten.</li>
    <li>Rechts zeigt die <b>Live-Vorschau</b> die fertige Mail – umschaltbar auf Handy und Dunkelmodus. Leere Bilder erscheinen dort als Platzhalter, in der Mail fehlen sie einfach.</li>
    <li><b>Kopf und Fuß</b> gestalten Sie im Design selbst – mit Bausteinen wie im Inhalt: Logo (mit Titel und Unterzeile rechts, links, darunter oder darüber – z. B. „Newsletter · {date}“; Platzhalter {subject}, {date}, {site}), Linkleiste, Bild, Social Media, Fußtext mit Anschrift und Abmeldelink, Abstand und alle übrigen. Jeder Baustein hat unter „Gestaltung“ einen Hintergrund (Inhaltsfläche, zarter Akzent, Akzentfarbe, dunkel oder eigene Farbe) und den Abstand oben/unten; auf dunklem Grund wird die Schrift automatisch hell. Der Abmeldelink ist Pflicht – fehlt er, hängt die Erweiterung ihn an.</li>
    <li>In der Vorschau öffnet ein <b>Klick auf einen Baustein</b> ihn links zum Bearbeiten; Bausteine lassen sich am Titel <b>ziehen</b>.</li>
    <li><b>Designs</b> (Newsletter → Designs) legen Kopf mit Logo, Schrift, Farben, Ecken und Knopfform fest. Sechs Designs sind dabei (Klar, Warm, Natur, Verein, Kontrast, Minimal). Ein eigenes Design legen Sie unter <b>Designs → Neues Design</b> an: Name eingeben, Grundlage wählen – danach Logo, Farben, Schrift und Formen im Editor anpassen, die Vorschau rechts zeigt jede Änderung. Zu wenig Kontrast meldet der Editor. Jeder Verteiler wählt sein Design, eine Ausgabe kann ein anderes nehmen.</li>
  </ul>
  <h3>Eine Ausgabe versenden</h3>
  <ol>
    <li>Ausgabe gestalten (siehe oben), Betreff und Vorschautext (erscheint im Postfach) eintragen, Verteiler wählen. Platzhalter <code>{name}</code> setzt den Namen ein.</li>
    <li><b>Test senden</b> – an sich selbst oder bis zu fünf Adressen.</li>
    <li><b>Versenden</b> – sofort oder zu einem Zeitpunkt. Die Ausgabe geht in Stapeln hinaus (Einstellung „Mails je Minute“); große Anbieter wie Gmail, GMX/Web.de und T-Online bekommen je Minute nur eine begrenzte Zahl. Anhalten und Fortsetzen ist jederzeit möglich.</li>
    <li>Der <b>Bericht</b> der Ausgabe zeigt Versendete, Rückläufer, Beschwerden und Abmeldungen mit allen Meldungen der Mailserver.</li>
  </ol>
  <h3>Rückläufer und Sperren</h3>
  <ul>
    <li><b>Hard Bounce</b> (Adresse gibt es nicht): Der Empfänger wird sofort gesperrt.</li>
    <li><b>Soft Bounce</b> (Postfach voll, Server vorübergehend nicht erreichbar): wird gezählt – gesperrt erst nach mehreren Fehlschlägen aus verschiedenen Ausgaben (Standard: 3 innerhalb von 60 Tagen). Kommt eine Mail wieder an, beginnt die Zählung neu.</li>
    <li><b>Beschwerde</b> (als Spam gemeldet): sofort abgemeldet und nie wieder angeschrieben.</li>
    <li><b>Blockiert</b> (der empfangende Server lehnt Ihren Absender ab, z. B. Spamverdacht): Der Empfänger bleibt aktiv – das Problem liegt beim Absender. Unter Einstellungen → <b>Zustellbarkeit</b> prüfen Sie SPF und DMARC Ihrer Domain.</li>
    <li>Abwesenheitsnotizen werden erkannt und ignoriert.</li>
    <li><b>Von Hand sperren</b>: beim Empfänger unter „Sperren“ mit Grund (z. B. Widerspruch per Telefon) – Grund, Datum und wer gesperrt hat, stehen danach in der Notiz. Mehrere Adressen auf einmal sperren Sie unter <b>Empfänger → Adressen sperren</b>; das geht auch für Adressen, die (noch) nicht in der Liste stehen. Nur für die Newsletter-Verwaltung.</li>
    <li>Gesperrte Adressen bleiben auch nach dem Löschen eines Empfängers als anonyme Prüfsumme auf der Sperrliste – ein späterer Import trägt sie nicht wieder ein. <b>Sperre aufheben</b> nur, wenn der Grund geklärt ist; der Empfänger meldet sich dann selbst neu an.</li>
  </ul>
  <h3>Altbestand übernehmen und bestätigen lassen</h3>
  <p>Beim Umstieg auf ein neues Newsletter-System gibt es oft alte Listen – ohne Double-Opt-in oder von Hand gepflegt. Diese Personen können Sie einmal anschreiben: mit dem Hinweis auf das neue System und der Bitte, den Newsletter zu bestätigen. Wer nicht innerhalb der Frist bestätigt, wird automatisch gelöscht.</p>
  <ol>
    <li><b>Einschalten:</b> Newsletter → Verteiler → beim gewünschten Verteiler <b>„Altbestand bestätigen lassen“</b> ankreuzen und speichern (Standard aus). Danach erscheint der Bereich <b>Newsletter → Altbestand</b> – für die Verwaltung und für die Redaktion, der der Verteiler zugewiesen ist.</li>
    <li><b>Adressen übernehmen:</b> Verteiler wählen, Adressen einfügen (eine je Zeile, optional „E-Mail;Name“ – aus einer Tabelle oder CSV-Datei kopiert; eine Kopfzeile wird erkannt). Pflicht sind die <b>Herkunft</b> und das Häkchen <b>„Diese Personen haben den Newsletter bisher erhalten“</b>. Übersprungen werden Adressen auf der Sperrliste, schon bestätigte, abgemeldete und solche, die eine frühere Bitte für diesen Verteiler abgelehnt haben – die Zahlen stehen in der Meldung.</li>
    <li>Die übernommenen Personen stehen unter Empfänger mit dem Status <b>„Bestätigung ausstehend“</b>. Sie erhalten <b>keine Ausgaben</b>, bis sie bestätigt haben.</li>
    <li><b>Bitte anpassen:</b> Die Bestätigungsbitte ist eine feste, neutrale Vorlage ohne Werbung. Sie können Betreff und Einleitung ändern (ohne Links), die Frist (Standard 30 Tage) und ob es höchstens <b>eine Erinnerung</b> geben soll – von Hand oder automatisch nach einigen Tagen. Absender, Logo und Fußzeile kommen vom Verteiler. Die Vorschau zeigt die fertige Mail.</li>
    <li><b>Bitte versenden:</b> Jede Person erhält die Bitte einmal, gedrosselt wie eine Ausgabe. Mit <b>„Ja, weiter erhalten“</b> wird sie regulärer Empfänger des Verteilers, mit <b>„Nein, abmelden“</b> sofort abgemeldet. Unzustellbare Adressen werden wie bei Ausgaben erkannt.</li>
    <li><b>Übersicht:</b> Die Aktion zeigt bestätigt, offen, abgelehnt und unzustellbar mit Fortschrittsbalken und das Datum der automatischen Löschung. Mit <b>„Erinnerung senden“</b> geht die Erinnerung an alle ohne Antwort (nur einmal), mit <b>„Jetzt beenden und Unbestätigte löschen“</b> endet die Aktion sofort.</li>
    <li><b>Nach der Frist</b> werden alle ohne Bestätigung samt ihren Daten gelöscht; es bleiben nur anonyme Zahlen für die Übersicht.</li>
  </ol>
  <p><b>Nachweis:</b> Zu jeder Bestätigung speichert die Erweiterung Zeitpunkt, ein Kürzel der IP-Adresse, die Herkunft des Altbestands, den Verteiler und den Wortlaut der Bitte (mit Prüfsumme) – wie bei einer normalen Anmeldung.</p>
  <div class="doc-note doc-note--important"><strong>Rechtlicher Hinweis (keine Rechtsberatung)</strong><p>Eine Bitte um erneute Bestätigung ist nur für Personen gedacht, die früher eingewilligt haben (bzw. als Bestandskunden unter § 7 Abs. 3 UWG fallen). Gerichte werten schon die E-Mail mit der Bitte um Bestätigung als Werbung, wenn keine Einwilligung vorlag. Bei <b>unbekannter Herkunft</b> empfehlen wir deshalb, die Personen nicht anzuschreiben – die Erweiterung verlangt dann vor dem Versand eine zusätzliche ausdrückliche Bestätigung. Im Zweifel fragen Sie Ihre Datenschutzbeauftragte bzw. Ihren Datenschutzbeauftragten.</p></div>
  <h3>Empfänger und Datenschutz</h3>
  <ul>
    <li>Jede Anmeldung speichert den Text der Einwilligung, den Zeitpunkt und ein Kürzel der IP-Adresse (nicht die Adresse selbst) als Nachweis.</li>
    <li>Jede Ausgabe enthält einen Abmeldelink und die Ein-Klick-Abmeldung für E-Mail-Programme (verlangt von Gmail und Yahoo).</li>
    <li><b>Empfänger hinzufügen</b>: Adressen einfügen (eine je Zeile, optional „E-Mail;Name“). Ohne Nachweis einer Einwilligung erhalten alle eine Bestätigungsmail; mit Nachweis (z. B. altes Anmeldeformular) sind sie sofort aktiv.</li>
    <li>Öffnungen und Klicks werden nicht gemessen. Bei Mailjet lässt sich das einschalten – dann in der Datenschutzerklärung erwähnen.</li>
    <li>Unbestätigte Anmeldungen werden nach 8 Tagen gelöscht, das Protokoll nach der eingestellten Frist.</li>
  </ul>
