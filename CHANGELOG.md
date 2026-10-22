# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

---

## [3.7.0] — 2026-10-22 — Post vom Versorger. Automatisches Einlesen und Verwalten von Dokumenten

MINOR-Release ohne Schemaschritt (bleibt 1.8.0): ein **Posteingang für
Versorgerpost**. PDF ablegen, auslesen lassen — von einer KI im eigenen
Heimnetz oder, experimentell und nur mit Opt-in, von einem Cloud-Dienst —,
jede Angabe an ihrer Belegstelle prüfen und mit einem Klick in Verträge,
Zähler, Rechnungen und Ablesungen übernehmen. Aus einem Stapel Briefe entsteht
der ganze Haushalt; im laufenden Betrieb sagt jeder Brief, was er bedeutet und
was jetzt zu tun ist, die Post kommt auch per Kurzbefehl, Ordner oder E-Mail,
und jeder Vertrag und Zähler hat eine Akte. Dazu: Monatswerte sagen, ob sie
gemessen oder zwischen zwei Ablesungen verteilt sind (GitHub [#37], [#39]),
eine Gütesperre für das Heizmodell mit Vorschlag für die Heizgrenze ([#38]),
ein Abschlagsvorschlag, der seine Unsicherheit nennt, Stufen nach dem
Haushalt, Zuständige an Zählern und Verträgen, die Nachbesserungen zu [#33]
und [#35] und Dialoge für Tastatur und Bildschirmleser. API, CSV-Format 1,
Backup-Format 3.0 und Docker-Umgebung wachsen nur additiv.

### ⚠️ Für bestehende Installationen

- **Kosten ohne Bonus** ([#33]): Die Kachel „Kosten“ zeigt die Kosten ohne
  Bonus, der Bonus steht als eigene Zeile darunter; nur der Saldo rechnet ihn
  ein. Die API (`cost`) bleibt, wie sie war.
- **Wasser mit Abschlägen:** Wasserverträge mit Abschlagsplan haben jetzt einen
  Saldo nach Kalender wie Gas und Strom. Saldo und erwarteter Endsaldo können
  sich gegenüber 3.6.0 ändern.
- **Verteilte Monate** ([#37], [#39]): Monate aus einem Ablesungsabstand über
  45 Tage zählen nicht mehr wie gemessen. Bei Zählern mit wenigen Ablesungen
  können sich Erwartung, bereinigter Verbrauch, Abweichungen, Empfehlungen,
  Prognose und die Saldo-Schätzung bis heute ändern; Monatswerte, Kosten,
  Abschläge und der Saldo bis zur letzten Ablesung bleiben gleich. Empfehlungen
  kommen nur noch aus den letzten 24 Monaten.
- **Prognose im Sommer:** Im Sommer gilt der gemessene Saisonwert statt der
  Heizkurve. Prognose, Menge fürs Vergleichsportal und die Prognose im
  Jahresbericht können sich ändern (Fehler behoben, siehe „Fixed“).
- **Abschlag mit Spanne:** Ist die Schätzung unsicher, nennt die Saldo-Karte
  statt eines Abschlags eine Spanne mit Grund, und `suggested_advance` ist
  `null` — auch im Sensor für Home Assistant.
- **Abgelöste Verträge:** Beginnt ein Folgevertrag, während der alte noch
  läuft, zählt der alte Abschläge, Grundpreis und Saldo nur bis zum Tag davor.
  Sein Saldo kann sich deutlich ändern.
- **Buchung ohne Ausweichen:** „Als Sonderzahlung buchen“ nimmt nicht mehr den
  jüngsten Vertrag, wenn keiner am Periodenende gilt, sondern den Vertrag des
  Zeitraums oder lehnt ab (Fehler behoben, siehe „Fixed“).
- **„Rechnung prüfen“** kennt das Urteil „keine Preise“ und nimmt eine
  Ablesung am letzten Tag als Grenzstand statt als Schätzung; gerechnet wird
  wie bisher, das Urteil lautet seltener „prüfen“.
- **Kurze Reste** ([#33]): Ein Rest unter 14 Tagen zwischen Stichtag und
  Lieferantenwechsel geht in die Nachbarperiode auf. Grenzen und Namen der
  Abrechnungsperioden können sich verschieben, die Summen bleiben gleich.
- **Stufen:** Elf Teile von Seiten liegen eine Stufe tiefer, weil sie zum
  Haushalt gehören und nicht zum Fachwissen; Einsteiger und Erfahrene sehen
  dadurch mehr.
- **Dialoge** öffnen mit dem Fokus im ersten Feld statt auf „×“; Rückfragen
  stehen auf „Abbrechen“.
- **Klimanormal:** Damit es Heizgrenzen ab 6 °C abdeckt, holt die App ein
  gespeichertes Klimanormal beim nächsten Wetterabgleich einmal neu bei
  Open-Meteo — dieselbe Abfrage wie beim ersten Start.
- **Ordner `posteingang/`:** Startet ein Verwalter die App (ohne Anmeldung
  also jeder), legt sie im Datenverzeichnis den überwachten Ordner an. Gelöscht
  wird darin nichts.
- **Keine neue Verbindung nach außen ohne Zutun:** Lokal liest der Dienst, der
  für die Texterkennung eingetragen ist; der Cloud-Weg bleibt aus, bis ein
  Verwalter ihn einschaltet.

### Added

#### Haushalt aus Dokumenten

- **Ein Entwurf über alle Dokumente:** Versorgerpost ablegen und auslesen
  lassen — statt einer Liste je Dokument entsteht ein Entwurf des ganzen
  Haushalts: Zähler, Verträge mit Preiskette und Abschlägen, Ablesungen,
  Rechnungen mit Buchung, Gas-Umrechnung und Termine. Geprüft wird nur, was
  zweifelhaft ist; ein Klick übernimmt den Rest über dieselben Dienste und
  Prüfungen wie die Handeingabe, vorher legt die App eine Sicherung an. Der
  Entwurf wächst mit jedem ausgelesenen Dokument, ein zweites Übernehmen ändert
  nichts.
- **Zähler und Lieferverhältnisse:** Zähler entstehen aus Zählernummer und
  Marktlokation; eine neue Nummer an derselben Messstelle wird ein Zählertausch,
  der leere Standardzähler wird übernommen, und ein Zähler, an dem nichts
  hängt, entsteht nicht. Dokumente gehören über Vertrags- oder Kundennummer
  zusammen oder über denselben Versorger in anderer Schreibweise (Rechtsform,
  „Stadtwerke“, Marke eines Versorgers).
- **Verträge aus allen Briefen:** Beginn aus Vertragsbestätigung,
  Lieferbeginn-Brief oder erster Rechnung, Ende aus Kündigung, Schlussrechnung
  oder dem Tag vor dem Folgevertrag; der Abschlag ist kein Pflichtfeld, sondern
  ein offener Punkt. Preise aus Bestätigung und Preisänderung bilden die Kette,
  ein Rechnungspreis bestätigt sie (brutto gegen brutto) oder fällt als Befund
  auf. Eine Rechnungskorrektur ersetzt die frühere Fassung.
- **Abgleich über die Dokumente hinweg:** Endstand gleich Anfangsstand wird
  eine Ablesung, Lücken und Überschneidungen zwischen Verträgen fallen auf,
  inhaltsgleiche Dokumente zählen einmal. „Fehlt noch“ nennt, was die Post
  nicht hergibt — etwa Preise für einen Abschnitt oder den Endstand des alten
  Zählers —, mit Sprung zum Feld.
- **Einrichtungsassistent:** Am Ende drei Wege — aus Dokumenten (empfohlen, mit
  den Dokumenten, die für deinen Haushalt helfen), von Hand anlegen oder mit
  Beispieldaten ansehen. „Auslesen einrichten“ öffnet die Karte „Auslesen“ als
  Dialog, im Assistenten wie im Posteingang.

#### Post im laufenden Betrieb

- **Posteingang** für alle Stufen, in der Navigation und als „Versorgerpost
  ablegen“ im Erfassen-Blatt: PDF auswählen oder hineinziehen, am iPad aus der
  Dateien-App. Dieselbe Datei erkennt die App an der Prüfsumme, dasselbe
  Schreiben in einer anderen Datei nach dem Auslesen am Inhalt („Wohl dasselbe
  Dokument wie …“). Ohne KI bleibt er ein Ablageort; „Von Hand erfassen“ öffnet
  das passende Formular, das Dokument steht daneben.
- **Vorschau neben dem Dokument:** Zuordnung zu Verbrauchsart, Zähler und
  Vertrag vorbelegt und änderbar, Abgleich mit dem Bestand (neu, schon
  vorhanden, Widerspruch, Überschneidung). Übernommen wird nur das Angehakte,
  über dieselben Prüfungen wie bei der Handeingabe: Ablesungen, Rechnung und
  Sonderzahlung, neuer Abschlag, neuer Vertrag mit Preisen, Bonus, Fristen und
  Nummern, Preisänderung mit Termin fürs Sonderkündigungsrecht, Kündigung,
  Lieferbeginn, Zählertausch, Zustandszahl und Brennwert für die Gas-Umrechnung
  ([#35]). Danach hängt das Dokument als Beleg am Datensatz, der Verlauf sagt
  „Aus Dokument übernommen“, ein zweites Übernehmen ändert nichts.
- **Was heißt das für dich?** Je Dokument ein Satz, eine Zahl, ein Knopf. Bei
  einer Preisänderung die Mehrkosten im Jahr aus der eigenen Prognose und der
  Weg zum Tarifvergleich, wenn ein Angebot spart; eine Rechnung prüft die App
  beim Übernehmen gleich mit („passt“ oder „liegt … über deiner Rechnung“, mit
  Grund); ein neuer Abschlag steht gegen den, mit dem die Abrechnung bei null
  endet; eine Kündigung ohne Anschlussvertrag bekommt einen Termin davor; eine
  Zählerstandsanforderung landet als Termin bei der zuständigen Person und
  führt direkt zur Erfassung; ein Zählerwechsel öffnet den Zählertausch
  vorbelegt; eine Mahnung bekommt einen Termin.
- **Viele auf einmal:** „Alle auslesen“ arbeitet die Dokumente nacheinander ab,
  fortsetzbar und abbrechbar; danach zeigt die Prüfliste nur die markierten
  Felder.
- **Post ohne Browser:** Kurzbefehl „Teilen → Energietracker“ für iPhone und
  iPad mit einem API-Schlüssel, der nur ablegen darf (neuer Bereich „nur
  Posteingang“); überwachter Ordner `posteingang/` im Datenverzeichnis
  (NAS-Freigabe, Docker-Volume) — nur PDF nach Inhalt, keine symbolischen
  Links, Dateien wandern nach `übernommen/` oder `fehler/`, gelöscht wird nie;
  E-Mail-Anhänge über die IMAP-Integration von Home Assistant (Anleitung). In
  der Home-Assistant-App ist der Ordner nicht erreichbar, dort gehen Kurzbefehl
  und E-Mail.
- **Akte je Vertrag und Zähler:** alle Dokumente an einer Stelle — übernommene
  Post, Rechnungen mit PDF, Belege, Fotos der Ablesungen —, mit Kernaussage,
  Filter und Suche. Vertragskarte und Rechnung nennen ihre Belege beim
  Dateinamen.
- **Termine „Zählerstand melden“** führen direkt in die Erfassung des Zählers.

#### Auslesen lokal oder in der Cloud (experimentell)

- **Lokal als Standard:** Es liest eine KI im eigenen Heimnetz, der Dienst der
  Texterkennung (Ollama oder OpenAI-kompatibel), empfohlen `qwen3.8` mit rund
  18 GB Arbeitsspeicher. Erst die Art, dann die Felder dieser Art, jedes mit
  Wert, wörtlicher Belegstelle und Seite. Seiten mit AGB gehen nicht mit, bei
  Ollama passt die App das Kontextfenster an die Länge des Dokuments an und
  fragt einmal mit größerem Antwortbudget nach, wenn eine Antwort am Fenster
  abbricht (ebenso beim OpenAI-kompatiblen Dienst); das Ergebnis bleibt je Dokument, Modell und Schema zwischengespeichert. Es gilt
  die Heimnetz-Regel der Texterkennung, geprüft bei jedem Aufruf; die
  Textebene liest der Browser (PDF.js, mitgeliefert), gespeichert wird sie
  nicht.
- **Was gelesen wird:** Rechnung, Vertragsbestätigung, Preisänderung,
  Kündigung und Lieferbeginn; Zählerwechsel, Zählerstandsanforderung und
  Abschlagsänderung mit Prüfung jedes Felds; Mahnung, Widerruf und Sonstiges
  werden erkannt und abgelegt.
- **Eine Karte „Auslesen“** unter Einstellungen → Experte mit dem Umschalter
  Lokal | Cloud; die Wahl gilt für den ganzen Posteingang. „Lokal“ zeigt
  Adresse und Schnittstelle (dieselben wie für die Zählerfotos), Textmodell,
  Zeitlimit und „Verbindung prüfen“.
- **In der Cloud als Opt-in:** Claude (Anthropic) bekommt das PDF und liefert
  die Belegstelle als wörtliches Zitat samt Seite; OpenAI, Mistral, OpenRouter,
  Google Gemini oder eine eigene OpenAI-kompatible `https://`-Adresse bekommen
  den Text. Das Modell wählst du aus der Liste des Dienstes. Aus, bis ein
  Verwalter es einschaltet; vor jedem Auslesen nennt die App Dienst, Ziel und
  was hinausgeht, die Warteschlange fragt einmal je Lauf. Alle Cloud-Wege sind
  als experimentell gekennzeichnet, bis Rückmeldungen aus echtem Betrieb
  vorliegen.
- **Was hinausgeht und was nicht:** Jedes Dokument, das du in der Cloud
  auslesen lässt, verlässt das Heimnetz; der gewählte Dienst sieht den ganzen
  Inhalt und verarbeitet ihn nach seinen Bedingungen. Hinaus gehen nur das
  Dokument, die Leseanweisung und der Schlüssel zur Anmeldung — keine
  Zählerstände, Verträge, Einstellungen oder anderen Haushaltsdaten. Das sagen
  die Karte „Auslesen“ in der Ansicht „Cloud“ und ihr Zustand, der Posteingang
  und die Anleitung.
- **Schlüssel und Verbindung:** Der API-Schlüssel bleibt auf dem Server — nie
  im Browser, im Backup oder im Protokoll — und gehört zu seinem Dienst.
  Adressen im Heimnetz lehnt der Cloud-Weg ab; verbunden wird nur mit der
  geprüften Adresse, ohne Weiterleitung und ohne Proxy. Im Docker-Image wartet
  nginx beim Auslesen bis zu 660 s (Zeitlimit der Cloud bis 600 s), sonst
  weiter 310 s.

#### Treffsicherheit

- **Die Belegstelle prüft der Server:** Ein Feld ist zu prüfen, wenn sein Zitat
  fehlt oder nicht im Dokument steht oder der Wert nicht im eigenen Zitat.
  Deutsche Zahlen liest er deutsch („12.345“ ist zwölftausend). Zitate aus
  Tabellen gelten, wenn ihre Teile in Reihenfolge nah beieinanderstehen (dieselbe
  Zeile oder höchstens zwei darunter); Umlaute, die die Textebene als zwei Zeichen oder in
  Mac Roman liefert, stören nicht. Eine Marktlokations-ID muss ihre Form haben
  (11 Ziffern, Prüfziffer nach BDEW).
- **Marken nur bei echtem Zweifel:** Ein Feld, das im Dokument fehlt, markiert
  nur, wenn ein Vorschlag es braucht (Kernfelder je Vorschlag); „voraussichtlich“
  ist ein Hinweis; ein Preis, der zum Vertrag passt, ist „schon vorhanden“;
  Auswahlfelder stützen sich auf andere Angaben (Zählernummer am bekannten
  Zähler, Einheiten, Tarif). Unsicher gelesene Nebenangaben übernimmt die App
  nicht still.
- **Gegenrechnung der Rechnung:** Die gezahlten Abschläge zählt der Server
  selbst zusammen, und das Ergebnis muss Gesamtbetrag minus Abschläge sein.
  Geht die Rechnung nicht auf, sind alle beteiligten Beträge markiert, nicht nur
  das Ergebnis.
- **Nettopreise wie auf der Rechnung:** Weist die Rechnung netto mit
  Steuersatz aus, rechnet die App in brutto um und vergleicht brutto mit brutto;
  der Vertrag bekommt den Bruttopreis. Den Satz liest sie aus dem Zitat, aus
  derselben Textzeile, aus einer Steuerspalte („USt %“) oder aus der einen
  Umsatzsteuerzeile — gelesen, nie vermutet, und nur aus Belegstellen, die im
  Dokument stehen; bei zwei Sätzen rechnet sie nicht um. Gleicher Nettopreis mit anderem Steuersatz ist eine neue Preiszeile, kein
  Widerspruch.
- **Nie mehr an den falschen Vertrag:** Die Vorbelegung nach Datum gilt nur für
  Verträge desselben Versorgers, sonst bietet der Posteingang „Vertrag aus
  diesem Dokument anlegen“ an; der neue Abschlag einer Rechnung geht nur an
  einen Folgevertrag desselben Versorgers. Beginnt ein neuer Vertrag am selben
  Zähler, ist „bisheriger Vertrag endet am Vortag“ vorab angehakt, ebenso der
  Anfangsstand der Rechnung, wenn er zwischen die bekannten Stände passt.
- **Zählertausch auf der Jahresabrechnung:** Stehen zwei Zähler auf der
  Rechnung, schlägt der Posteingang den Tausch mit Endstand und Anfangsstand
  vor — vorab angehakt, wenn Nummern, Tag und Stände zusammenpassen. Mehrere
  Ablesezeiträume eines Zählers werden ein Eintrag, die Zwischenstände eigene
  Ablesungen; ein Endstand des neuen Zählers landet nie am alten Gerät.
- **Geschätzte Zählerstände** erkennt der Posteingang am Wort („geschätzt“,
  „hochgerechnet“, „rechnerisch ermittelt“), an einer Fußnote mit Erklärung oder
  an einem Ablesecode, den die Rechnung selbst erklärt („S = geschätzt“, auch in
  einer Codegruppe oder einer Spalte „Ableseart“); die Ablesung entsteht als geschätzt und zählt nicht
  als Messung.
- **Datum und Titel jedes Dokuments** werden mitgelesen; sie ordnen die Post
  zeitlich und zeigen eine Korrektur als solche.

#### Gemessen oder verteilt

- **Jeder Monatswert sagt, ob er gemessen ist** ([#37]): Liegen zwei Ablesungen
  weit auseinander, ist der Monatswert die gleichmäßige Verteilung eines
  längeren Zeitraums. Maßgeblich ist die Länge des Abstands (bis 45 Tage
  gemessen), nicht, ob im Monat abgelesen wurde; geschätzte Ablesungen messen
  nicht. Verbrauchsart und Analyse sagen es mit der Zahl der Ablesungen,
  Tabellen zeigen „≈“, Diagramme schraffierte Säulen.
- ⚠️ **Verteilte Monate lernen nichts:** Abweichungstabelle, Empfehlungen,
  Heizmodell und Heizkurve, Saisonprofil der Prognose, Trend gegen das Vorjahr,
  Saldo-Schätzung, „Wie im Vorjahr“ der Werkstatt und die Vorjahresvergleiche
  im Jahresbericht lassen sie aus. Bei wenigen Ablesungen rechnet das
  Heizmodell über die Abstände zwischen den Ablesungen. Empfehlungen kommen nur
  aus den letzten 24 Monaten; die Analyse zeigt weiter alle Abweichungen.
- **Jahresbericht:** Jahressummen vergleicht er weiter, wenn an beiden
  Jahreswechseln abgelesen wurde, auch mit verteilten Monaten dazwischen; sonst
  sagt eine Notiz, warum der Vergleich fehlt.
- **PV mit wenigen Ablesungen** ([#39]): Die Übersicht sagt, wie viele
  Monatswerte verteilt sind und aus wie vielen Ablesungen, dass Eigenverbrauch,
  Autarkiequote, Ersparnis und Amortisation für diese Monate Schätzungen sind,
  und markiert die Monate im Energiefluss.

#### Gütesperre und Heizgrenze-Vorschlag

- **Gütesperre** ([#38]): Passt das Heizmodell schlecht — Streuung über 15 %
  eines mittleren gemessenen Monats oder Grundlast weit neben den gemessenen
  Sommern —, sagt die Abweichungstabelle das, statt leer zu bleiben, nennt die
  Grenze, unter der sie nichts erkennt, und führt zur Heizgrenze.
- **Heizgrenze-Vorschlag je Zähler** ([#38]): Die Analyse rechnet die
  gemessenen Monate eines Zählers mit Heizgrenzen von 6 bis 18 °C durch und
  schlägt eine deutlich besser passende vor. Übernommen wird sie nur per Klick
  (ab Erfahren) und nur für diesen Zähler, zurücknehmbar; die Einstellung
  bleibt der Standard der übrigen.
- **Klimanormal ab 6 °C** für niedrige Heizgrenzen; außerhalb des Rasters sagt
  die Prognose es, statt still an der Grenze zu rechnen.
- **Jahresband und Heizanteil nur bei passendem Heizmodell**, sonst sagt die
  Prognose, warum sie ohne Band rechnet. Ungültige Heizkurven nennen den Grund
  (zu wenige Punkte, passt nicht, nicht berechenbar), und jeder bereinigte
  Monat sagt, worauf er beruht.

#### Stufen

- **Stufen überdacht:** Die Stufe folgt drei Sätzen — Einsteiger wollen wissen,
  was sie verbrauchen, was es kostet und ob der Abschlag reicht; Erfahrene
  vergleichen und prüfen; Experten sehen alles und binden an. Wer PV, Speicher,
  Wärmepumpe oder Wallbox hat, sieht die Seiten dazu in jeder Stufe.
- **Für alle:** Kündigungsfrist im Vertrag; bei Fernwärme Leistungspreis,
  Messpreis und Anschlussleistung. **Ab Erfahren** (bis 3.6.0 Experte):
  Schaubilder auf der Übersicht, Jahresarbeitszahl, Speicher und Amortisation
  der PV, Ladestrom-Nachweis, E-Auto und JAZ in der Werkstatt, „CO₂-Kosten
  teilen“, PV-Anlage und Stromzähler der Wärmepumpe im Zählerdialog,
  Einstellung Photovoltaik.
- **Vorschlag aus dem Haushalt:** PV, Speicher, Wärmepumpe oder Wallbox
  schlagen Einsteigern „Erfahren“ vor, Viertelstundenwerte Erfahrenen
  „Experte“ — je Anlass höchstens einmal.
- **„Was kann ich auf welcher Stufe?“** im Handbuch: jede Funktion mit ihrer
  Stufe, nach Aufgaben geordnet, mit drei Haushalten zum Wählen; verlinkt aus
  Einstellungen und Assistent, von einem Test gegen den Code geprüft.

#### Nachzüge aus 3.6.0

- **Flexibilität mit eigenen Werten:** Wärmepumpenanteil, Wasch- und
  Spülgänge und die Steckzeit des Autos ohne evcc lassen sich unter
  Einstellungen → Verbrauchsarten einstellen (ab Erfahren); Seite und Bericht
  sagen dann „eigener Wert“ statt „Beispielwert“. Steckzeiten aus evcc gehen
  vor.
- **§ 14a Modul 3 ohne USt:** Netzentgelte lassen sich netto eintragen
  („Beträge ohne USt“); die App rechnet sie mit ihrem Umsatzsteuersatz auf
  brutto.
- ⚠️ **Wasser mit Abschlägen** ([#34]): Wasserverträge mit Abschlagsplan haben
  einen Saldo nach Kalender wie Gas und Strom, mit Abschlagsvorschlag und
  Saldo-Verlauf; der Arbeitspreis ist Trink- plus Schmutzwasser. Der
  Jahresbericht zeigt Saldo und Abschlags-Rat. Ohne Abschläge bleibt alles wie
  bisher.

#### Abrechnungsjahr und Gutschriften

- ⚠️ **Kosten ohne Bonus** ([#33]): Die Kachel „Kosten“ (Verbrauchsart im
  Kalender- und Abrechnungsjahr, Übersicht, Jahresbericht) zeigt die Kosten
  ohne Bonus; der Bonus steht als eigene Zeile darunter. Der Saldo rechnet ihn
  ein wie die Rechnung, der Ø je Monat nicht — eine einmalige Gutschrift ist
  kein Monatspreis. Die API (`cost`) bleibt nach Abzug, neu ist `bonus_items`;
  die CSV der Abrechnungsjahre bekommt am Ende „Bonus“ und „Kosten ohne Bonus“.
- **Stichtag eindeutig** ([#33]): Einstellungen, Handbuch und API sagen, dass
  der Stichtag der erste Tag der Abrechnungsperiode ist (01-09 heißt 1.9. bis
  31.8.). Ohne erfasste Rechnung schlägt die App ihn aus den Vertragsbeginnen
  vor.
- **Sonstige Gutschrift** ([#35]): neue Bonusart mit Notiz, etwa für eine
  Kulanzgutschrift; Art und Notiz stehen in Vertrag, Jahresbericht und
  Tarifvergleich.
- **Gas-Umrechnung im Vertrag** ([#35]): Das Vertragsformular zeigt bei Gas
  die heute geltende Umrechnung (Zustandszahl × Brennwert) mit Sprung zur
  Einstellung und sagt, warum sie am Netz hängt und nicht am Vertrag.

#### Zuständig an Zählern und Verträgen

- **Zuständig:** Im Zähler- und Vertragsformular wählst du eine Person aus dem
  Haushalt. Fristen landen dann bei ihr: Kündigungsfrist, Vertragsende,
  Preisgarantie und „Abrechnung erwartet“ beim Vertrag, Ablesung und Bestand
  beim Zähler.
- **Fristen erben die Zuständigkeit:** Ein Termin ohne eigene Zuständigkeit
  bekommt die des Vertrags, sonst die des Zählers. Der Chip sagt „über den
  Vertrag“ bzw. „über den Zähler“ — in den Terminen, unter „Zu tun“, auf
  Verträge & Abschläge und im Kalender. Eine eigene Zuständigkeit am Termin geht
  immer vor.
- **Termin am Zähler:** Eichfrist, Zählerwechsel oder Lieferung lassen sich
  einem Zähler zuordnen und erben so dessen Person.
- **„Meine Aufgaben“ überall:** Der Filter zählt Geerbtes mit und steht jetzt
  auch auf Verträge & Abschläge. Wer angemeldet ist, abonniert den Kalender auf
  Wunsch nur mit dem, was bei ihr oder ihm liegt.
- **Person löschen** übergibt auch Zähler und Verträge an die Person, die
  löscht; ohne Anmeldung bleiben sie mit „(gelöscht)“ stehen. Der Dialog nennt
  die Zahl vorher.

#### Qualität

- **Dialoge für Tastatur und Bildschirmleser:** Jeder Dialog öffnet mit dem
  Fokus im ersten Feld (am Touchscreen auf dem Dialog, ohne dass die Tastatur
  aufspringt); Rückfragen stehen auf „Abbrechen“ und werden samt Frage
  vorgelesen, der Titel ist eine Überschrift. Die Tabulator-Taste bleibt im
  Dialog, Escape schließt, und der Fokus kehrt zum Knopf zurück, auch wenn die
  Liste nach dem Speichern neu aufgebaut wird. Fehlermeldungen hängen am Feld.
  Eine automatische Prüfung klickt sich durch alle Seiten und jeden Dialog,
  auch künftige.
- **Schneller mit vielen Daten:** Mit zehn Jahren täglicher Ablesungen
  antworten Übersicht, Abrechnungsperioden, Jahresbericht und
  Prognose-Werkstatt zwei- bis viermal schneller, und die Zeit wächst nur noch
  linear mit der Datenmenge — bei gleichen Zahlen.
- **Englische Screenshots mit englischem Datum** (dd/mm/yyyy).
- **Release-Prüfliste** mit den mitgelieferten Bibliotheken (Chart.js, PDF.js)
  und der Sichtprüfung in Safari/WebKit (`tools/webkit-check.mjs`).

#### API und Daten (additiv)

- **Routen:** `GET`/`POST /api/inbox`, `GET /api/inbox/review`,
  `POST`/`DELETE /api/inbox/queue`, `GET`/`PATCH`/`DELETE /api/inbox/{id}`,
  `POST /api/inbox/{id}/classify|extract|apply|remind`,
  `GET /api/inbox/draft`, `POST /api/inbox/draft/apply`,
  `POST /api/inbox/folder`, `GET`/`PATCH /api/inbox/cloud`,
  `GET /api/inbox/cloud/models`, `GET /api/inbox/local/check`,
  `GET /api/utility/{u}/dossier`; `POST /api/inbox` nimmt auch JSON mit
  `pdf_base64`.
- **Daten:** neuer Topf `inbox.json` (im Backup); am Vertrag
  `contract_number`, `customer_number`, `attachment_ids`, `assignee_id`; am
  Zähler `assignee_id`, `hdd_base_temp`; an Terminen `utility`, `meter_id`; an
  Rechnungen `factors`; Ablesungen mit `source: inbox`; API-Schlüssel-Bereich
  `inbox`; Einstellungen `inbox_*`, `inbox_cloud_*`, `flex_*`.
- **Antworten:** je Monat `interpolated`, `measured_share`,
  `reading_gap_days`, dazu `interpolation` und `anomaly_quality`;
  `bonus_items`; `projection_uncertain`, `price_missing`, `superseded_on` am
  Vertragsstatus; in „Rechnung prüfen“ `no_prices`, `boundaries`,
  `price_missing_at`, `factor_mismatches`; `suggested_anchor_source` an den
  Abrechnungsperioden; `period_last_day` in `GET /api/summary`;
  `assignee_source` in der Agenda; `?person=` am Kalender; `example_keys` in
  `GET /api/settings`.
- **CSV:** Abrechnungsjahre mit „Bonus“ und „Kosten ohne Bonus“ am Ende, die
  lokale Monatsübersicht mit „Bonus“; Format 1 bleibt unverändert.

### Changed

- **Unsicher heißt überall unsicher:** Saldo-Karte, Übersicht (auch für Home
  Assistant), Jahresbericht und Werkstatt nennen dieselben Gründe. Wo die
  Schätzung unsicher ist, steht nur die Spanne — auf der Karte, bei „Erwartet
  zum …“ unter Kosten & Verträge und in der Verträge-Tabelle. Passt das
  Heizmodell schlecht, sagt „Kälter oder mehr verbraucht?“ nicht mehr „wirklich
  weniger verbraucht“, sondern dass es sich nicht belegen lässt; dann schätzt
  der Saldo mit einem Faktor für das Ganze (⚠️ geschätzte Kosten und Saldo
  dieser Zähler ändern sich).
- **Jahresvergleich der Monate:** Ein angefangener Monat steht mit * und wird
  nicht mit einem vollen verglichen (bestand vor 3.7.0). Verteilte Monate mit
  einer geschätzten Ablesung als Stütze heißen nicht mehr „gleichmäßig
  verteilt“.
- ⚠️ **Abschlag mit Spanne, wenn er unsicher ist:** Passt das Heizmodell
  schlecht oder klemmt seine Kalibrierung an einer Grenze, nennt die
  Saldo-Karte statt eines Betrags eine Spanne mit Grund; der Jahresbericht
  macht daraus einen Hinweis statt einer Aufgabe, die Prognose-Werkstatt zeigt
  die Spanne. `suggested_advance` ist dann `null` (`projection_uncertain`), auch
  im Sensor für Home Assistant.
- ⚠️ **Abgelöste Verträge:** Beginnt ein Folgevertrag, während der alte noch
  läuft, enden dessen Abschläge, Grundpreis und Saldo am Tag davor
  (`superseded_on`); gespeichert bleibt das eingetragene Ende. Bisher zählten
  seine Abschläge bis heute weiter. Lückenlose Ketten rechnen wie bisher.
- ⚠️ **„Rechnung prüfen“ mit Aussage:** neues Urteil „keine Preise“ mit Sprung
  zum Vertrag, auch wenn zugleich die Menge abweicht; eine Ablesung am letzten
  Tag des Zeitraums ist ein Grenzstand, keine Schätzung; ein Brennwertwechsel,
  den Gas-Umrechnung und Rechnung gleich kennen, ist kein Grund mehr, sonst
  stehen beide Werte da. Die Rechnung selbst rechnet unverändert.
- **Vertrag ohne Arbeitspreis:** Saldo-Karte und Abrechnungsjahr sagen „Preis
  fehlt“.
- **Beispieldaten:** Der Schaufenster-Haushalt hat eine erfundene
  Jahresabrechnung im Posteingang mit vorberechneter Auslesung, einen Entwurf
  aus Dokumenten und einen vierteljährlich abgelesenen Zwischenzähler, an dem
  die verteilten Monate zu sehen sind; jeder Haushalt hat einen Vertrag und
  einen Zähler mit Zuständigen, Eigenheim klassisch eine sonstige Gutschrift.
  Die öffentliche Demo zeigt den Posteingang ohne Auslesen.
- **Handbuch:** neue Anleitungen
  [Posteingang](docs/anleitungen/posteingang.md) und
  [Haushalt aus Dokumenten](docs/anleitungen/haushalt-aus-dokumenten.md), die
  Funktionstabelle [Was kann ich auf welcher Stufe?](docs/referenz/stufen.md)
  und für Entwickler die
  [Messung mit großen Datenmengen](docs/entwicklung/messung-grosse-daten.md).

### Fixed

- **Verteilte Monate als Abweichung** ([#37]): Abweichungstabelle, Empfehlung
  r1 und das Heizmodell nahmen Monate, die nur aus einem langen
  Ablesungsabstand verteilt waren, wie gemessen und meldeten Abweichungen, die
  es nicht gab — jeden Monat dieselben aufs Neue.
- **Heizmodell bei konvexer Heizkurve** ([#38]): Passte das Modell schlecht,
  blieb die Abweichungstabelle leer und sagte nicht, warum.
- **PV mit zwei Ablesungen** ([#39]): Die PV-Übersicht zeigte gleiche Monate
  und Jahre ohne Hinweis, dass sie verteilt sind.
- **Ein-Tages-Perioden** ([#33]): Lag der Stichtag ein paar Tage neben dem
  Vertragsbeginn (etwa einen Tag davor), blieb bei jedem Wechsel
  eine Mini-Periode übrig; der Hinweis zum Stichtag las sich als Endtag. Ein
  Rest unter 14 Tagen geht jetzt in die Nachbarperiode auf, die Summen bleiben
  gleich.
- **Bonus-Notiz** ([#35]): Das Vertragsformular löschte beim Speichern still
  die Notiz eines Bonus, das Wasserformular speicherte jeden Bonus als
  „Neukunde“.
- ⚠️ **Als Sonderzahlung buchen** nahm ohne Vertrag am Periodenende den
  jüngsten Vertrag des Zählers, auch wenn er den Zeitraum gar nicht berührte
  (seit v3.1.0, auch ohne Posteingang). Jetzt bucht die App auf den Vertrag,
  der den Zeitraum überwiegend abrechnet — etwa bei einer Schlussrechnung einen
  Tag über das Vertragsende —, sonst lehnt sie mit dem Zeitraum ab.
- ⚠️ **Prognose im Sommer:** Die Heizkurve rechnete auch unterhalb ihres
  kleinsten Punkts; bei konvexer Heizkurve mit viel Warmwasser lagen
  Jahresprognose und Menge fürs Vergleichsportal deutlich zu niedrig. Jetzt
  gilt im Sommer der gemessene Saisonwert, und bei schlecht passendem
  Heizmodell rechnet keine Heizkurve.
- **„Abrechnung erwartet“** übersah eine gebuchte Rück- oder Nachzahlung im
  Zeitraum; sie zählt jetzt als eingegangene Rechnung.
- **Stufenhinweis:** Einsteiger sahen über „Wechsel prüfen“, „Rechnung prüfen“
  und „Mietverhältnis“ den Hinweis „Diese Seite gehört zur Stufe …“ nicht.
- **Dialoge:** Der Fokus stand beim Öffnen auf „×“; Felder im Kalender-Abo und
  in den Tabellen des Wasservertrags hatten keine Beschriftung; Grün hatte im
  Hellmodus zu wenig Kontrast.
- **„Zu tun“ mit „Meine Aufgaben“:** Die Sammelzeile der fälligen Ablesungen
  fiel ganz weg.

### Bekannte Grenzen (folgen in 3.7.x)

- **Photovoltaik mit sehr wenigen Ablesungen:** Der Energiefluss kappt die
  gemessene Einspeisung an der gleichmäßig verteilten Erzeugung, und der Block
  „Sonnenstrom“ im Jahresbericht vergleicht Teiljahre. Die PV-Übersicht sagt,
  dass die Monatswerte verteilt sind; Eigenverbrauch und Autarkie je Monat sind
  in diesem Fall nur grobe Schätzungen.
- **Saldo-Karte und Prognose-Werkstatt** rechnen den erwarteten Saldo über
  verschiedene Zeiträume und können verschiedene Abrechnungstage nennen; ist
  die Schätzung unsicher, zeigt die Karte nur die Spanne.
- **Posteingang:** Ein Zählertausch, dessen Nummer nur in der Kopfzeile über
  den Ständen steht, wird angeboten, aber nicht vorab angehakt; Ablesecodes in
  Codegruppen oder einer Spalte „Ableseart“ erkennt er noch nicht als geschätzt.

### Migration

Keine. Schema bleibt 1.8.0. Neu im Backup ist der Topf `inbox.json`; der
Schlüssel eines Cloud-Diensts liegt in einer eigenen Datei und bleibt bewusst
draußen. Das Auslesen steht auf „Lokal“, der Cloud-Weg auf „aus“.

### Tests

- **Posteingang:** `InboxServiceTest`, `InboxEvidenceTest` (Belegprüfung),
  `InboxMarksTest` (Marken, Vorschläge, Übernehmen), `InboxLocalProviderTest`
  (gegen ein nachgebautes Ollama über HTTP), `InboxGistTest`,
  `InboxFolderTest`, `DossierServiceTest`, `InboxOperationDocsTest`,
  `BillVerdictTest`; über HTTP `InboxEndpointTest`,
  `InboxChannelsEndpointTest`, `InboxDraftEndpointTest`,
  `LongRequestTimeLimitTest`, `NginxReadTimeoutTest`.
- **Haushalt aus Dokumenten:** `HouseholdDraftTest`,
  `InterpolationFromDraftTest`, Node-Suite `inbox-draft.test`.
- **Cloud:** `CloudExtractionTest` gegen einen nachgebauten Dienst (Form der
  Anfrage, Belegstellen, Fehler, Adressprüfung, Schlüssel in keiner Meldung;
  kein echter Aufruf), `InboxCloudEndpointTest` (Schlüssel in keiner Antwort,
  keinem Backup, keinem Protokoll).
- **Gemessen oder verteilt, Gütesperre:** `InterpolationTest` (Monatswerte,
  Kosten, Abschläge und Saldo bit-gleich gegen Aufnahmen mit dem Code von
  3.6.0), `MessyHouseholdTest` mit einem unordentlichen Testhaushalt aus eigener
  Physik (`tests/fixtures/messy-household/`), `RealDataFollowUpTest`.
- **Nachzüge, [#33], [#35]:** `ShortRestPeriodsTest`, `CostWithoutBonusTest`,
  `WaterCalendarBalanceTest`, `SummaryPeriodLastDayTest`.
- **Zuständig, Qualität:** `ResponsibilityTest`, `ResponsibilityHttpTest`,
  `ReleaseChecklistTest`; neue Node-Suiten `inbox.test`, `a11y.test` (klickt
  jede erreichbare Seite und jeden Dialog durch) und `large-data.test` (zehn
  Jahre Tageswerte, Laufzeit linear).
- Erweitert: `BackupSafetyTest`, `BaselineCutoffTest`, `BillExpectedTest`,
  `BillingPeriodServiceTest`, `DemoCoverageTest`, `FlexibilityInsightTest`,
  `FlexibilityTest`, `OutboundHostsTest`, `PersonaPeopleTest`,
  `ReportFlexPageTest`, `ReportModelServiceTest`, `ViewSumsConsistencyTest`,
  `WaterSpecialPaymentsTest`; `levels.test`, `assignee.test`, `chart.test`,
  Render-, Shape- und Demo-Tests.
- 1477 Testmethoden (+403), PHPUnit zählt 1795 Tests. Jede neue Regel hat eine
  Gegenprobe, die sie bricht: 937 in 14 Paketen, alle rot.

### Lessons Learned

- **Beispieldaten aus dem eigenen Modell bestätigen sich selbst.** [#37], [#38]
  und [#39] fand ein Nutzer mit echten Daten, nicht unsere Tests: Die
  Beispielhaushalte entstehen aus dem Heizmodell der App und werden regelmäßig
  abgelesen — an ihnen kann eine Gütesperre nie anschlagen. Neu sind darum ein
  unordentlicher Testhaushalt mit eigener Physik (konvexe Heizkurve, Lücken,
  Zählertausch, geschätzte Ablesung), ein Abgleich der Auswertungen mit echten
  Haushaltsdaten, der nur lokal läuft und nichts ins Repo bringt, und vor jeder
  Freigabe ein Skeptiker-Lauf, der jede angezeigte Zahl auf Herkunft und
  Plausibilität prüft.
- **Treffer je Feld sind noch kein Nutzen.** Im Nutzentest mit echter
  Versorgerpost las die KI die Felder fast immer richtig, und trotzdem entstand
  kaum ein brauchbarer Haushalt: Verträge blieben liegen, weil ein Pflichtfeld
  fehlte, die meisten Marken waren Fehlalarm, die Vorbelegung traf den Vertrag
  eines anderen Versorgers, und „Rechnung prüfen“ urteilte fast immer
  „prüfen“. Gemessen wird seitdem am fertigen Haushalt — derselbe Test mit
  denselben gespeicherten Auslesungen, nach jeder Nachbesserung wiederholt.
- **Ein Betrag ohne Unsicherheit ist eine Behauptung.** Der Abschlagsvorschlag
  nannte auch bei schlecht passendem Modell einen genauen Betrag; der Abgleich
  mit echten Daten zeigte, wie weit er danebenliegen kann. Jetzt nennt die App
  eine Spanne und sagt, wovon sie abhängt.

[#33]: https://github.com/Bingerminger/energietracker/issues/33
[#34]: https://github.com/Bingerminger/energietracker/issues/34
[#35]: https://github.com/Bingerminger/energietracker/issues/35
[#37]: https://github.com/Bingerminger/energietracker/issues/37
[#38]: https://github.com/Bingerminger/energietracker/issues/38
[#39]: https://github.com/Bingerminger/energietracker/issues/39

---

## [3.6.0] — 2026-10-20 — Was wäre wenn und wie auf der Rechnung

MINOR-Release ohne Schemaschritt (bleibt 1.8.0): die Prognose-Werkstatt
„Was wäre wenn?“, Auswertungen auch nach Abrechnungsjahr statt nur nach
Kalenderjahr (GitHub [#33]) bis in Übersicht, Zählergruppen und „Rechnung
prüfen“, eine Flexibilitätsseite mit Ampel und Euro je Gerät, Personen mit
fester Nummer, ein Hinweis auf neue Versionen und „Was ist neu“ nach einem
Update, das Image-Tag `:3`. API, CSV-Format 1, Backup-Format 3.0 und
Docker-Umgebung wachsen nur additiv.

### ⚠️ Für bestehende Installationen

- **Geänderte Werte** (Fehler behoben, siehe „Fixed“): Der Saldo des
  laufenden Vertrags beginnt nach der letzten gebuchten Rechnung statt beim
  Vertragsbeginn; „Rechnung prüfen“ rechnet beim Wasser das Schmutzwasser mit;
  Zählergruppen zählen einen Subzähler nicht mehr doppelt; der Endsaldo der
  Prognose-Werkstatt enthält den Rest des laufenden Monats.
- **Person löschen:** Offene Termine gehen jetzt an die Person, die löscht;
  Erledigtes behält den Namen mit „(gelöscht)“. Bisherige Personen bekommen
  beim ersten Öffnen ihre Nummer.
- **Weitere geänderte Werte:** Die Euro-Hebel der Flexibilität zum Börsenpreis
  enthalten jetzt die Umsatzsteuer; das Auto verschiebt nur, solange es
  angesteckt ist (Steckzeiten aus evcc); Abrechnungsperioden enden am
  Lieferantenwechsel; der Jahresbericht zählt Heizöl und Pellets in den
  Kosten; die Einordnung nimmt Wohnfläche und Personen erst, wenn sie
  eingetragen sind.
- **Ohne Anmeldung** sagt die Karte „Benutzer und Rechte“ jetzt, dass Personen
  mit eigenem Zugang erst mit eingeschalteter Anmeldung entstehen, und springt
  zu „Anmeldung einschalten“.
- **Ohne Anmeldung** (Login aus) liefert `GET /api/people` jetzt die
  Personen aus dem Verzeichnis statt einer leeren Liste. Mit Anmeldung gilt
  die Anmeldung wie bisher.
- **„Was ist neu“:** Jedes Gerät zeigt nach dem Update einmal „Aktualisiert auf
  3.6.0“ mit Link zu den Release-Notes — ohne Anfrage nach außen.
- **Hinweis auf neue Versionen** (`update_check`) bleibt bei bestehenden
  Installationen aus, bis ihn jemand unter Einstellungen → Allgemein
  einschaltet; die Einrichtung neuer Installationen schlägt ihn vor.
- **Beispiele und Vorlagen auf `:3`:** Compose-Beispiel, README, Doku und die
  Unraid-Vorlage nennen jetzt das Tag `:3` (immer die neueste 3.x, kein Sprung
  auf eine neue Hauptversion). Laufende Container ändern sich nicht; CasaOS
  und Umbrel bleiben auf einer festen Version, wie ihre Stores es verlangen.

### Added

- **Prognose-Werkstatt — „Was wäre wenn?“ (F1031):** Was kostet ein kalter
  Winter, was bringt die Dämmung, lohnt der Wechsel? Regler für Winter, Sparen
  und Tarif, dazu Raumtemperatur, Grundlast, Maßnahmen mit Kosten,
  Prognosemodell, E-Auto, Wärmepumpe und Budget. Das Ergebnis erscheint sofort
  als Ergebniskarte mit großer Zahl, mit Kurven, empfohlenem Abschlag, Saldo
  zur nächsten Abrechnung, CO₂ und der Rangliste „Was bringt am meisten?“;
  dazu die beste Kombination unter einem Budget. Beispielszenarien, Speichern
  und Vergleichen, Bild, Druck/PDF, CSV; die Demo hat die Beispiele
  vorgerechnet.
- **Übersicht-Karte „Was wäre wenn? Probier es aus“**, die Prognose gibt es
  jetzt schon ab der Stufe Einsteiger.
- **Flexibilität lenkt:** Ampel für günstige und teure Stunden mit Sonne,
  „Dein Hebel“ in Euro je Gerät, Handlungssätze mit Zeitfenster, beste
  Zeitfenster, „Sonnenstrom selbst nutzen“, Ergebniskarte als Bild;
  Lastverschiebung gegen Viertelstundenpreise, gerechnet auf dem Server
  (`insights`). Die Seite gibt es für alle Stufen, ohne Daten mit einer
  Einladung. Im Jahresbericht die Seite „Flexibilität“ (`flex=1`), in der
  Druckansicht „Szenario anhängen“.
- **Kachel „Dein Hebel“ auf der Übersicht:** dieselbe Zahl wie die
  Ergebniskarte der Flexibilität, klein „bis zu …“ mit dynamischem Tarif, ein
  Handlungssatz; nur mit gemessenen Viertelstunden, auch für Einsteiger.
- **Personen mit fester Nummer:** Jede Person im Haushalt bekommt eine
  fortlaufende Nummer, die nie wieder vergeben wird (Personenverzeichnis
  `people.json`, gehört zum Backup). Löschen nur durch Verwalter; offene
  Termine gehen an die Person, die löscht, der Dialog sagt vorher, wie viele
  an wen. Erledigtes behält den Namen mit „(gelöscht)“: im Verlauf, an
  erledigten Terminen, in der Verlaufs-CSV und im Kalender. Personen aus
  einem Backup oder Beispielhaushalt stehen unter Zugriff mit dem Vermerk
  „ohne Zugang“, lassen sich löschen oder per „Zugang anlegen“ zu einem
  Zugang mit derselben Nummer machen.
- **Einordnung als Ampel:** Die Kachel zeigt Haushaltsstrom, Heizung und
  Wasser je Person mit Ampel und einem klaren Satz (Wasser gelb erst ab 5 %
  über dem Richtwert); fehlt eine Angabe, führt
  genau ein Link direkt zum Feld. Die Links auf die Startseiten von Strom-
  und Heizspiegel sind entfallen.
- **Abrechnungsjahr statt Kalenderjahr** ([#33]): Umschalter „Kalenderjahr ·
  Abrechnungsjahr“ in Verbrauchsart, Übersicht und Analyse; die Wahl merkt
  sich das Gerät. Die Perioden kommen aus den erfassten Versorgerrechnungen —
  genau wie auf der Rechnung, auch unregelmäßig wie 15.04.–30.03. —, davor,
  dazwischen und danach aus dem Abrechnungsstichtag je Verbrauchsart.
  Tagesgenau mit Menge, Kosten, Abschlägen (tagesanteilig) und Saldo;
  Teilmonate am Anfang und Ende. Passt der Stichtag nicht zur letzten
  Rechnung, schlägt die App einen vor. Arten ohne Rechnungsprüfung (Heizöl,
  Pellets, Sonnenstrom) bleiben beim Kalenderjahr.
- **Jahresbericht** mit Anhang „Abrechnungsjahre“ (Option im Dialog; die
  übrigen Seiten bleiben Kalenderjahr, damit die Summen über alle Arten
  zusammenpassen); **CSV-Export je Abrechnungsperiode**
  (`monthly.csv?period=billing`, Kachel unter Einstellungen → Daten).
- **Laufendes Jahr erklärt:** ⓘ an „Abschläge bis …“: Das Kalenderjahr zählt
  Grundpreis und Abschlag des angebrochenen Monats ganz, das Abrechnungsjahr
  tagesgenau; ganze Jahre sind gleich.
- **Personen ohne Zugang bearbeiten:** Unter Einstellungen → Zugriff lassen
  sich Name und Rolle von Personen aus einem Backup oder Beispielhaushalt
  ändern (nur Verwalter). Offene Termine zeigen den neuen Namen, Erledigtes
  behält den alten. „Umbenennen“ gibt es auch für Zugänge mit Passwort; Namen
  sind über alle Personen eindeutig.
- **Angenommene Fläche sichtbar:** Effizienzklasse und CO₂-Aufteilung für
  Mieter rechnen ohne eingetragene Fläche weiter mit 100 m², sagen das aber
  jetzt („Angenommene Wohnfläche 100 m² – bitte eintragen“) und führen direkt
  zum Feld, auch im Jahresbericht (`area_assumed`).
- **Abrechnungsjahr bis in den letzten Winkel:** Die Zählergruppen einer
  Karte zeigen dieselbe Periode wie die Karte; „Rechnung prüfen“ schlägt die
  jüngste abgeschlossene Abrechnungsperiode vor; die Saldo-Karte sagt
  „Gerechnet ab …“. Ein Test hält Menge, Kosten, Abschläge und Saldo in allen
  Ansichten deckungsgleich, im Kalender- wie im Abrechnungsjahr.
- **API additiv:** `GET /api/utility/{u}/meters/{id}/billing-periods`, dazu für
  Zählergruppen und die ganze Verbrauchsart; Jahresbericht `billing=1`;
  `GET /api/update-check`; `POST /api/forecast/scenario` (rechnet nur,
  speichert nichts, auch mit Lese-Schlüssel); `GET /api/users/{id}/handover`;
  `insights` in `GET /api/flexibility`; Jahresbericht `flex=1`;
  `balance_from` am Vertragssaldo; `rating`, `water`, `missing` an der
  Einordnung; `no`, `has_account` an Personen; `ends_at_switch` an den
  Abrechnungsperioden; `assumed_keys` in den Einstellungen;
  `GET /api/flexibility?summary=1`, in `insights` dazu `summary`, `vat_pct`,
  `ev_availability`; `PATCH /api/users/{id}` auch für Personen ohne Zugang.
- **Hinweis auf neue Versionen:** Einmal am Tag sieht der Server bei GitHub
  nach, ob es eine neuere Version gibt, und die App zeigt sie mit Link zu den
  Release-Notes, bis man den Hinweis für diese Version schließt. Nur
  eingeschaltet; GitHub sieht dabei nur die Adresse des Servers.
- **„Was ist neu“** nach einem Update, mit einer Zeile zum Unterstützen.
- **Image-Tag `:3`** (und `:3-rootless`) — bleibt aktuell, ohne ungefragt auf
  eine neue Hauptversion zu springen.

### Changed

- ⚠️ **Abrechnungsjahr beim Lieferantenwechsel:** Beginnt mitten in einer
  Periode der Vertrag eines anderen Versorgers und ist keine Schlussrechnung
  erfasst, endet die Periode am letzten Tag beim alten Versorger, die nächste
  beginnt mit dem neuen Vertrag. Verlängerung und neuer Tarif beim selben
  Versorger teilen nicht.
- ⚠️ **Hebel zum Börsenpreis mit Umsatzsteuer** (Satz des dynamischen
  Angebots, sonst 19 %); Seite, Bericht und Kachel sagen „inkl. USt“. Was netto
  bleibt, sagt es: ct-Werte in Ampel und Zeitfenstern und die Nachrechnung
  (Profilfaktor, Vorteil, Speicherwert) als „netto, ohne USt“.
- ⚠️ **Das Auto lädt, solange es angesteckt ist:** Ladefenster aus den
  evcc-Steckzeiten statt „ganzer Ladetag“; Seite und Bericht nennen die
  Grundlage.
- **Einordnung: Standardwerte zählen nicht.** Wohnfläche und Personen ordnen
  erst ein, wenn du sie eingetragen hast, auch derselbe Wert wie der Standard
  (100 m², 2 Personen). Bis dahin lädt die Kachel zum Feld ein. Wer die Werte
  schon gespeichert hat, behält seine Ampel.
- **Doku: wöchentlicher Neubau erklärt.** Der „Docker Rebuild“ am Dienstag ist
  kein Release: gleicher Code und Tag, neues Image mit aktuellem Unterbau und
  neuem Digest. Update-Wächter melden ihn deshalb, GHCR zählt Downloads je
  Version neu.
- **Druckansicht mit PDF-Knopf:** Neben „Drucken / als PDF sichern“ lädt
  „Jahresbericht herunterladen“ die PDF-Datei mit derselben Auswahl. Am iPad
  führt „Drucken“ in den Druckdialog, nicht direkt zu einer Datei.
- **CO₂-Faktoren zugeklappt:** Unter Einstellungen → Verbrauchsarten steht
  die lange Liste zugeklappt, derselbe Aufklappbereich wie „Prognose im
  Detail“; bei einem Fehler oder nach dem Speichern klappt sie auf.
- **Ruhige Formulare am iPad:** Auswahlfelder so hoch wie Eingaben, 16 px
  Schrift auch mit Tastatur oder Trackpad (kein Zoom beim Antippen),
  einheitliche Abstände, Passwortfelder in Formularen, in allen
  Einstellungen.
- **Beispieldaten für alle Anwendungsfälle:** Jede Zuständigkeit gehört zu
  einer angelegten Person, im Eigenheim klassisch zeigt „Lukas (gelöscht)“
  die Historisierung; dazu eine Einliegerwohnung mit HT/NT-Zählern als
  Zählergruppe mit Gruppenvertrag, ein stillgelegter Zähler, ein
  Home-Assistant-Alias, eine geschätzte Ablesung und ein Posten auf der
  Wasserrechnung. Ein Test verlangt jeden der 52 Anwendungsfälle in
  mindestens einem Haushalt.
- **Beispielhaushalte:** Eigenheim klassisch bringt eigene, erfundene
  Vergleichswerte mit und zeigt die Einordnung mit voller Ampel; Eigenheim
  modern hat Steckzeiten aus evcc, „Dein Hebel“ zeigt dort 91 €, mit
  dynamischem Tarif bis zu 251 € (vorher 139 € und 224 €).
- **Beispieldaten: Stichtag und Rechnungen im selben Rhythmus.** Wo die Demo
  den Abrechnungsstichtag relativ zu heute legt, wandern die Jahresrechnungen
  mit (Schlussrechnungen bleiben), der Import rechnet ihre Beträge neu: kein
  Zwischenzeitraum und kein Stichtag-Vorschlag im Abrechnungsjahr, „Rechnung
  prüfen“ passt weiter. Die Eigentumswohnung erwartet die Abrechnung jetzt bei
  der Fernwärme.
- **Lizenz in einfachen Worten** im README: zu Hause frei nutzen, ändern,
  weitergeben; Pflichten erst, wer eine veränderte Fassung anderen als
  Webdienst anbietet.
- **Release-Notes** gehören jetzt zu den erlaubten GitHub-Links der App.
- **Unterstützen-Link** auch im README der Home-Assistant-App.
- **Beispieldaten:** Der Beispielhaushalt mit Gas, Strom und Wasser zeigt eine
  Nachzahlung am Wasservertrag ([#34]).

### Fixed

- **Abschlag im Wechselmonat:** „Rechnung prüfen“ und die Abrechnungsjahre
  zählen den Abschlag eines Monats mit zwei Verträgen je Vertrag nach seinen
  Tagen; bisher bekam eine Schlussrechnung zum 14. einen Teil des neuen
  Abschlags.
- **Jahresbericht, Kosten mit Heizöl und Pellets:** Kachel „Kosten“, Verlauf
  und „Geld“ zählen die Kosten der Lieferungen wie die Übersicht; die Seiten
  nennen sie statt „keine Preise hinterlegt“.
- **Rechnung prüfen, Wasser:** Das Schmutzwasser fehlte, wenn es über einen
  eigenen Zähler oder als Hauptzähler minus Gartenzähler läuft, und damit
  auch in jedem Abrechnungsjahr.
- **Saldo des laufenden Vertrags** zählte alle Jahre seit Vertragsbeginn;
  jetzt ab dem Tag nach der letzten gebuchten Rechnung (`balance_from`).
- **Zählergruppen:** Ein Subzähler, dessen Elternzähler in derselben Gruppe
  ist, zählte bei Verbrauch, Rechnungsprüfung und Abrechnungsjahr doppelt.
- **Prognose-Werkstatt:** Dem Endsaldo fehlte der Rest des angebrochenen
  Monats.

### Migration

Keine. Schema bleibt 1.8.0; die neue Einstellung `update_check` steht auf
„aus“.

### Tests

- `BillingPeriodServiceTest` (12), `UpdateCheckServiceTest` (5),
  `ScenarioServiceTest`, `SimplexTest`, `ScenarioEndpointTest`, neue
  Node-Suiten `release-notice.test` und `scenario.test`; erweitert
  `DemoDataAlignerTest`, `DemoServiceTest`, `PersonaUseCasesTest` (drei
  Stichtage), `setup-wizard.test`,
  `DeployTemplatesTest`, `ReleaseConsistencyTest`, Render-Tests.
- Neu außerdem `PeopleServiceTest`, `PersonDeletionTest`,
  `PersonaPeopleTest`, `ComparisonTrafficLightTest`, `FlexibilityPersonaTest`
  (rechnet die Kennzahlen des Beispielhaushalts unabhängig nach),
  `ViewSumsConsistencyTest` (gleiche Summen in allen Ansichten),
  `DemoCoverageTest`, `BillingWindowTest`, `SupplierSwitchPeriodsTest`,
  `PersonEditTest`, `ReportFlexPageTest`.
- 1074 Testmethoden (+145, davon 8 Randfälle: Zählertausch, fremde und ungültige Rechnungen, Rechnung über 14 Monate, Vorabversion, kaputte Antwort). 345 Gegenproben, alle rot.

### Lessons Learned

- **Ein Hinweis in der App erreicht nur, wer ihn schon hat.** Installationen
  auf alten Versionen erfahren von neuen nur über GitHub, Update-Werkzeuge oder
  ein Tag wie `:3` — darum kommt der Hinweis früh und das Tag gleich mit.

[#33]: https://github.com/Bingerminger/energietracker/issues/33
[#34]: https://github.com/Bingerminger/energietracker/issues/34

---

## [3.5.1] — 2026-10-14 — Am iPad

PATCH-Release: Fehler, die bei der Nutzung am iPad (Safari) auffielen, dazu
der erste echte Test der Texterkennung mit Ollama. Kein Schemaschritt (bleibt
1.8.0); die Schnittstelle wächst nur additiv (`month` am Abruf der
Börsenpreise).

### Fixed

- **Einrichtungsassistent: Karten wirkten mehrfach ausgewählt.** Nach jeder
  Wahl sprang der Fokus auf die erste Karte, deren Fokusrahmen wie eine
  zweite Auswahl aussah; dazu blieb in Safari der Hover-Rahmen nach dem
  Antippen stehen. Jetzt bleibt der Fokus an der gewählten Karte, Hover gilt
  nur mit Maus, der Fokusrahmen nur bei Tastaturbedienung.
- **„Rechnung prüfen“: überlappende Felder.** Datums- und Zeitfelder bringen
  in Safari eine eigene Mindestbreite mit und ragten in die Nachbarspalte —
  sie folgen jetzt app-weit dem Formularraster.
- **Einrichtungsassistent: Land nicht wählbar.** Schlug der Abruf der
  Länderliste beim ersten Öffnen fehl, bot der Assistent nur das aktuelle
  Land an; er lädt die Liste jetzt nach. In der Demo steht das Land des
  Beispielhaushalts als Text mit Erklärung statt als gesperrtes Feld.
- **Einstellungen → Zugriff:** Hinweise in einer Schriftgröße; die
  Passwortfelder stehen in einem Formular, damit Safari sie als
  Passwortformular erkennt.
- **Börsenpreise von SMARD laden ohne Rückmeldung:** Die Seite Flexibilität
  lädt das Jahr jetzt Monat für Monat mit Fortschrittsbalken („März 2025,
  3 von 12“); jeder Knopf, der gerade arbeitet, zeigt ein drehendes Rad (bei
  „Bewegung reduzieren“ steht es still). API additiv:
  `POST /api/market-prices/sync-smard-intervals?year=&month=` (1–12),
  `errors.flexibility.monthInvalid`.

### Changed

- **Home-Assistant-App sichtbarer:** Knopf „Repository zu Home Assistant
  hinzufügen“ im README (DE/EN) und im Handbuch, Abzeichen
  „Home Assistant App“, Hinweis mit Link auf der Karte Home Assistant in der
  App.
- **Texterkennung mit Ollama ausprobiert:** Ollama 0.40.4 mit dem Modell
  `glm-ocr` las ein Testbild eines Zählerstands in rund 4 Sekunden richtig;
  das Modell gibt keine Sicherheit an, der Wert wird wie immer nur
  vorbelegt.

### Migration

Keine. Schema bleibt 1.8.0.

### Tests

- `IntervalServiceTest` (Börsenpreise für einen Monat), `setup-wizard.test`
  (Fokus an der gewählten Karte, Länderliste nachgeladen, Demo-Land als
  Text), `forms.test` (Hover, Fokusrahmen, Datumsfelder, Zugriff, Rad,
  Fortschritt, Knopf zur Home-Assistant-App).
- 929 Testmethoden (+1). 14 Gegenproben, alle rot.

### Lessons Learned

- **Sichtprüfung nur in Chromium reicht nicht.** Klebendes Hover,
  Datumsfelder mit Eigenbreite und Passwortfelder ohne Formular zeigen sich
  erst in Safari. Für Oberflächenänderungen gehört Safari/WebKit in die
  Prüfung — oder die Abnahme am iPad.

---

## [3.5.0] — 2026-10-14 — Wer, wann, wohin

MINOR-Release ohne Schemaschritt (bleibt 1.8.0): Aufgaben im Haushalt bekommen
eine zuständige Person, fehlende Abrechnungen fallen auf, und der Energiefluss
lässt sich über einen Tag abspielen. Dazu Home Assistant OS als App, ein Image
ohne root und eine Einrichtung, die zuerst nach Sprache und Land fragt.
**F1027** Tagesfilm, **F1028** Zuständig an Terminen, **F1029** Abrechnung
erwartet, **N1022** Einrichtung mit Sprache und Land, **N1023** iPad,
**N1024** Image ohne root, **N1025** Home-Assistant-App, **N1026**
Beispieldaten; dazu der Rechtsstand 2027 und GitHub [#34]. API, CSV-Format 1,
Backup-Format 3.0, Docker-Umgebung und der Home-Assistant-Ingest wachsen nur
additiv.

### ⚠️ Für bestehende Installationen

- **Abrechnung erwartet:** Nach dem Update erscheint „Abrechnung erwartet“ für
  Abrechnungszeiträume, die in den letzten 120 Tagen endeten und keine
  erfasste Versorgerrechnung haben — abhaken oder die Rechnung erfassen. Die
  Hinweise stehen auch in `summary.agenda` und im Kalender-Abo.
- **Energieausweis-nahe Kennzahl ab 1. Januar 2027:** Sie rechnet nach dem
  Verfahren, das am Rechentag gilt. Ab 2027 ist das § 82 GModG in neuer
  Fassung (BGBl. 2026 I Nr. 226): Gas nach Brennwert statt Heizwert (die Zahl
  steigt um rund 10 %), Warmwasser-Zuschlag 16 statt 20 kWh/(m²·a), Daten
  über zwei Jahre statt 36 Monate. Die Klassengrenzen bleiben. Bis dahin
  zeigt die Übersicht in Deutschland eine Vorschau.
- **Wasserrechnungen, die seit v3.1.0 als Sonderzahlung „gebucht“ wurden,**
  gelten wieder als nicht gebucht und lassen sich buchen — die Buchung war
  nie im Vertrag angekommen. Sonderzahlungen, die per API an einem Heizöl-,
  Pellet- oder Einspeisevertrag gespeichert wurden, zählen im Saldo nicht
  mehr (die Oberfläche bot sie dort nie an).
- **iPad hochkant:** Die Seitenleiste erscheint erst ab 900 px Breite,
  darunter die Tab-Leiste mit „Mehr“ wie am Telefon.
- **Nicht beschreibbares Datenverzeichnis:** Die App antwortet mit 503 und
  `errors.storage.notWritable` statt 500; `/api/health` meldet
  `data_dir_writable: false`, das Log nennt UID, Gruppe und den nötigen
  `chown`.

### Added

- **Tagesfilm (F1027):** Der Energiefluss spielt einen Tag in Viertelstunden
  ab, wahlweise einen bestimmten Tag oder den typischen Tag eines Monats
  (Mittel je Viertelstunde). In den Knoten steht die Leistung, in den Knöpfen
  die Summe seit Mitternacht, darunter der Börsenpreis mit Cursor. Lücken
  sind gekennzeichnet, nie 0. Abspielen, Pause, Schieberegler mit Uhrzeit und
  Tastatur; bei „Bewegung reduzieren“ nur der Schieberegler. Erscheint nur
  mit Viertelstundenwerten des Netzbezugs. Neue Routen
  `GET /api/energy-flow/days`, `/api/energy-flow/day?date=`,
  `/api/energy-flow/typical-day?month=`.
- **Zuständig an Terminen (F1028):** Feld „Zuständig“ im Termin-Dialog (Person
  aus der Anmeldung oder freier Name), Namens-Chip in den Terminen und unter
  „Zu tun“, Zeile „Zuständig: …“ im Kalender; Filter „Alle · Meine Aufgaben ·
  Ohne Zuständigkeit · Namen“, ohne Anmeldung mit „Ich bin …“ je Gerät.
  Keine Benachrichtigung. API: `assignee`, `assignee_id`, `assignee_former`
  an Terminen und Agenda-Ereignissen, `GET /api/people`.
- **Abrechnung erwartet (F1029):** Endet ein Abrechnungszeitraum (Stichtag
  oder Vertragsende → Schlussrechnung) ohne erfasste Versorgerrechnung, steht
  er unter „Zu tun“ mit Sprung zum Erfassen; bei Strom und Gas nach sechs
  Wochen „überfällig — gesetzliche Frist verstrichen, beim Versorger
  nachfragen“ (§ 40c Abs. 2 EnWG), Fernwärme und Wasser ohne gesetzliche
  Frist nur „erwartet“. Erledigt sich mit der erfassten Rechnung, lässt sich
  abhaken; bis 120 Tage nach dem Periodenende. Agenda-Art `bill_expected`.
- **Verlauf als CSV** zum Nachweis (etwa für Vermieter oder Hausverwaltung):
  Knopf „Als CSV“ unter Hinweise → Erledigt mit den gewählten Filtern;
  `GET /api/activity.csv` (Datum, Aktion, Titel, Wer, Grund, Notiz; Format 1
  oder „local“, Schutz gegen Formeln).
- **Einrichtung fragt zuerst Sprache und Land (N1022):** Schritt 1 wählt
  Sprache und Land, vorgewählt aus dem Browser; die Sprache gilt sofort, unter
  dem Land steht, was das Länderprofil setzt. Gespeichert wird nur, was sich
  ändert. Auch in der Demo (Sprache je Gerät). Neue Route
  `POST /api/settings/country`: Erststart, Einstellungen und Einrichtung
  speichern beim Landwechsel nur Abweichungen vom Standard.
- **Jahresbericht mit Dialog:** Jahr, Verbrauchsarten (Teilbericht), Sprache
  des PDF und Anhang; das Gerät merkt sich die Auswahl. Die Druckansicht
  versteht den Teilbericht.
- **Image ohne root (N1024):** zusätzliche Variante `X.Y.Z-rootless` /
  `latest-rootless` — läuft als UID 10001 auf Port 8080, braucht keine
  Capabilities, gleiche Plattformen, wöchentlich neu gebaut und gescannt, in
  der CI gestartet; das Standard-Image bleibt unverändert.
- **Home-Assistant-App (N1025):** Mit Home Assistant OS läuft der
  Energietracker als App: eigenes Repository
  [energietracker-ha](https://github.com/Bingerminger/energietracker-ha),
  Seitenleiste über Ingress, Anmeldung über die HA-Benutzer, Daten in den
  Sicherungen von Home Assistant, direkter Port standardmäßig aus. Gleiches
  Image wie mit Docker, vorerst als experimentell gekennzeichnet.
- **Beispieldaten (N1026):** Alle Beispielhaushalte und das Schaufenster
  zeigen den Verlauf „Erledigt“ mit erfundenen Personen, Termine mit
  Zuständigkeit, erfasste Versorgerrechnungen, eine Abrechnung, die noch
  aussteht, und lückenlose Folgeverträge; das Eigenheim mit Wärmepumpe und PV
  hat Viertelstunden für Erzeugung, Einspeisung und Speicher und beim Laden
  die letzten Monate bis gestern für den Tagesfilm.
- **Strompreispauschale 2027:** 32 ct/kWh im Länderprofil Deutschland
  (Destatis, 1. Halbjahr 2026: 32,14 ct, abgerundet; BMF-Schreiben vom
  11.11.2025, Rn. 30). Fehlt der Wert eines Jahres, sagt die Meldung, woher
  er kommt und wann er feststeht.
- **API additiv:** `certificate.rules`, `fuel_basis`, `fuel_basis_approx`,
  `dhw_rule`, `months_required`, `months_in_window`, `upcoming` an der
  Effizienz-Kennzahl (`months_36` unverändert); `has_special_payments` in
  `/api/utilities`; `special_payments` am Wasservertrag und in dessen
  `contract-status`.

### Changed

- **Verlauf „Erledigt“ ohne Lücken:** Ein neuer Zeitraum bei Zählern mit
  Verbrauch je Zeitraum erledigt „Zählerstand ablesen“ wie eine Ablesung;
  eine geänderte Nebenkostenabrechnung steht als „geändert“ im Verlauf (ohne
  doppelte Erledigung).
- **Effizienzklassen gegen das Gesetz geprüft:** A+ bis H sind die Grenzen
  der Anlage 10 und bleiben ab 2027 unverändert (GModG Art. 2 Nr. 54;
  BT-Drs. 21/6278). Heizöl und Pellets zählen ab 2027 als Näherung nach
  Heizwert, bis ein amtlicher Faktor veröffentlicht ist.
- **Doku CO₂-Preis:** Stand 2027 mit Fundstellen (BEHG-Entwurf BT-Drs.
  21/7869, ETS2 ab 2028 nach VO (EU) 2026/667, Wert für die
  Kostenaufteilung nach § 4 Abs. 1 Nr. 3 CO2KostAufG) und was die App für
  2027 ohne eingetragenen Preis rechnet.
- **Anleitungen geprüft** und je Anleitung gekennzeichnet, was ausprobiert
  und was nach Herstellerdoku geprüft ist: Texterkennung (LM Studio ohne
  „Require Authentication“), openHAB (Rückgabe der HTTP-Aktion),
  Unraid-Vorlage (neu `ET_PROXY_USER_HEADER`, `ET_LOCK_WAIT_S`), evcc und
  Texterkennung (Link-local gilt seit v3.4.0 nicht als Heimnetz).
- **Actions aktualisiert** (Dependabot #29–#32): docker/login-action 4.6.0,
  docker/metadata-action 6.2.0, docker/setup-buildx-action 4.4.1,
  actions/setup-node 7; die CI testet mit Node 24.

### Fixed

- **Sonderzahlungen bei Wasserverträgen** ([#34]): Der Wasservertrag hat
  jetzt den Abschnitt „Sonderzahlungen“ wie Gas, Strom und Fernwärme — Rück-
  und Nachzahlung (mit oder ohne Auswirkung auf den Abschlag) und zusätzliche
  Abschlagszahlung. Sie wirken im Saldo der Übersicht und der Verbrauchsart
  und in der Hochrechnung bis Vertragsende; „mit Auswirkung“ setzt den
  Abschlag ab dem Stichtag, auch in der Prognose. „Als Sonderzahlung buchen“
  in der Rechnungsprüfung kommt beim Wasservertrag jetzt an.
- **Home Assistant OS:** Die `options.json` des Supervisors (nur für root
  lesbar) gilt nicht mehr als beschädigte Datei — bisher antwortete
  `/api/health` ab dem zweiten Start mit 503, und der Watchdog startete die
  App neu.
- **Kalender-Abo unter Ingress:** nennt die Adresse im Netz von Home
  Assistant statt der Ingress-Adresse, mit Hinweis für Handy und Computer.
- **Beispielhaushalt laden** setzte Sprache und Land auf Deutsch zurück.
- **Englische Installation** mit Stufe „Erfahren“ oder „Einsteiger“ zeigte
  nach dem Start deutsche Namen der Verbrauchsarten in der Seitenleiste.
- **„Mit eigenen Daten starten“** sprang zu „Zählerstände“ und gleich wieder
  zurück.

### Migration

Kein Schemaschritt. Neue Felder sind optional; ältere Termine und
Wasserverträge laufen unverändert weiter.

### Tests

- Neue PHPUnit-Klassen u. a.: `EnergyFlowDayTest`, `AssigneeTest`,
  `AssigneeAccessTest`, `BillExpectedTest`, `ActivityAdditionsTest`,
  `WaterSpecialPaymentsTest`, `RootlessImageTest`, `UnwritableDataDirTest`,
  `OtherSystemsGuideTest`, `HomeAssistantAppTest`, `HomeAssistantAppDocsTest`,
  `PersonaUseCasesTest`; erweitert u. a. `EfficiencyCertificateTest`,
  `EvChargingReportTest`, `DemoDataAlignerTest`, `DeployTemplatesTest`.
- Neue Node-Suiten: `day-film.test`, `assignee.test`, `setup-wizard.test`,
  `nav-threshold.test`, `report-dialog.test`.
- Neue CI-Schritte im Docker-Job: Image ohne root (Rechte, fremder
  Datenordner, beliebige UID mit Gruppe 0) und „wie als Home-Assistant-App“.
- 928 Testmethoden (+115). 300 Gegenproben, alle rot.

### Lessons Learned

- **Ein Prädikat beantwortet eine Frage.** „Hat Abschläge“ entschied auch, wo
  es Sonderzahlungen gibt — Wasser fiel bei beidem heraus, obwohl es
  Abschläge und Nachzahlungen hat. Jetzt fragt `hasSpecialPayments()` nur
  das.
- **Ein neuer Hinweis braucht ein Fenster für den Bestand.** Ein Jahr
  rückwirkend hätte fast jede Installation nach dem Update mit „überfällig“
  überflutet; 120 Tage zeigen, was noch aussteht.
- **Neue Werte brauchen ihre Beispiele in jedem Paket.** Eine neue Aktion im
  Verlauf und ein Test „jede Aktion in jedem Haushalt“ entstanden parallel —
  erst der gemeinsame Stand zeigte den Widerspruch.
- **Ein Testprozess ist keine Anfrage.** Über 1000 Tests sammelt PHPUnit
  Speicher an; das Limit dafür steht jetzt in der Testkonfiguration, die
  Anfrage selbst bleibt nachweislich unter 128 MB.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

[#34]: https://github.com/Bingerminger/energietracker/issues/34

---

## [3.4.0] — 2026-10-13 — Klar und sicher

MINOR-Release ohne Schemaschritt (bleibt 1.8.0): Die App wird für alle im
Haushalt lesbar, aufgeräumt und sicherer — auch ohne Internet. **N1019** Hilfe
auf dem Server, **N1020** Sicher im Haushalt, **F1025** Jahresbericht für alle,
**F1024** Erledigt mit Verlauf, **F1026** Schaubilder in zweiter Fassung,
**N1021** Aufgeräumte Oberfläche. API, CSV-Format 1, Backup-Format 3.0 und der
Home-Assistant-Ingest wachsen additiv; entfernt wird nur der Import alter
v0.9.0-Backups (Stabilitätsklasse C).

### ⚠️ Für bestehende Installationen

- **v0.9.0-Backups:** Der Import entfällt. Wer noch eines hat: v3.3.0
  installieren, dort migrieren, dann auf 3.4.0 aktualisieren.
- **Proxy-Anmeldung:** Unter Home-Assistant-Ingress gelten nur noch die vom
  Supervisor gesetzten Kopfzeilen; hinter einem anderen Proxy legt
  `ET_PROXY_USER_HEADER` die eine Kopfzeile fest, die zählt. Ohne Einstellung
  bleibt das bisherige Verhalten, der neue Sicherheits-Check unter Zugriff
  rät dann zur Einstellung.
- **Größere Uploads:** Anfragen sind standardmäßig auf 8 MB begrenzt, nur
  Backup-Import, Belege und Datei-Importe dürfen größer sein (bis 256 MB).
  Wer Apache selbst betreibt, findet die Entsprechung in der Doku.
- **Docker:** Das Image läuft auf PHP 8.5, PHP ist im Container nur noch
  intern erreichbar, und das Image enthält jetzt die Doku (rund 7 MB mehr).
- **Beispielhaushalt „Eigenheim mit Wärmepumpe und PV“:** Der Hausspeicher
  entlädt nicht mehr ins Auto; Bezug, Einspeisung, Speicherwerte und
  Abschläge des Beispiels ändern sich.
- **Autarkie mit Speicher korrigiert:** Speicherverluste zählen nicht mehr
  als selbst genutzter Strom, Netzladung bleibt Netzstrom. Ohne Speicher
  ändert sich nichts; mit Speicher sinkt der Wert meist um rund einen
  Prozentpunkt (auch `summary.pv.autarky_pct_ytd`).
- **Vertragsstatus:** „VERGANGEN“ heißt jetzt „BEENDET“; ein abgelaufener,
  weiterlaufender Vertrag heißt in beiden Listen „VERLÄNGERT“.
- **Erinnerungen an Verträge:** Ein lückenlos anschließender Folgevertrag
  erledigt Kündigungsstichtag, Vertragsende, Preisgarantie und Preiserhöhung
  des Vorgängers von selbst. Weiterlaufende und unbefristete Verträge haben
  keinen täglich wandernden Stichtag mehr — wer den Kalender abonniert oder
  `/api/summary` in Home Assistant nutzt, sieht dort entsprechend weniger
  Termine.
- **Backup:** Der neue Verlauf erledigter Hinweise reist im Backup mit, samt
  den Namen der Personen als Momentaufnahme.

### Added

- **Hilfe auf dem Server (N1019):** Das Handbuch (Deutsch und Englisch) kommt
  vom eigenen Server in der Fassung dieser Version und öffnet in der App
  (`#/help/doc/…`) — mit Inhaltsverzeichnis, Umschalter Deutsch/Englisch und
  Bildern; Tabellen und Code scrollen am iPhone in sich, gelesene Seiten gehen
  offline, auch in der öffentlichen Demo. Die übrigen Oberflächensprachen
  lesen die englische Fassung mit einem Hinweis. Alle sieben Anwendungsfälle
  stehen als Kurztext in sieben Sprachen in der Hilfe, je mit Sprung in den
  Abschnitt des Handbuchs. Ein eigener Markdown-Renderer ohne Abhängigkeit
  zeigt die Seiten; er escaped alles und lässt nur erlaubte Links und Bilder
  zu. Neue Routen `GET /api/docs?path=…` und `GET /api/docs/asset` liefern nur
  Dateien aus einer Freigabeliste, mit Anmeldung, falls eingeschaltet. Nach
  GitHub führen nur noch Fehlermeldung, Projektseite und Quellcode der
  Version.
- **Jahresbericht für alle (F1025):** Das PDF ist neu für Leser ohne
  Technikhintergrund — „Das Wichtigste“ mit großen Zahlen, Drei-Jahres-Linien,
  vier Ampeln (immer mit Symbol und Wort) und den drei wichtigsten Aufgaben;
  „Was hat sich gelohnt“; „Was ist zu tun“; Fristen und Termine; Geld; je
  Verbrauchsart eine Seite; Themenseiten Sonnenstrom und Speicher,
  Wärmepumpe, E-Auto, günstige Stunden und Mietwohnung (nur mit Daten); ein
  kleines Wörterbuch. Die Monatstabellen stehen auf Wunsch als Anhang
  „Zahlen im Detail“ (`?detail=1`).
  - **Handlungsempfehlungen** mit Priorität, Frist und Betrag — Beträge nur
    aus eigenen Ablesungen und Vertragspreisen, gekennzeichnet als gemessen,
    hochgerechnet oder Rechenbeispiel; was nicht zum Berichtsjahr gehört,
    steht im Kasten „Stand heute“.
  - **Grafiken** als PDF-Vektoren ohne Bibliothek: Monatsbalken mit Vorjahr,
    Saldo-Linie, Effizienzskala, Ampeln, Zeitstrahl, Energiefluss; Farben aus
    der Farbtabelle der Verbrauchsarten, für den Druck abgedunkelt.
  - **Barrierearm:** Fließtext ab 10 pt, Fußzeile „Seite x von y“,
    Dokumenttitel, Sprache und Lesezeichen im PDF; die Druckansicht zeigt
    dasselbe als HTML.
  - **Sprache und Teilbericht per Link:** `?lang=` und `?utilities=`; der
    PDF-Link der Oberfläche entsteht in der Sprache des Geräts.
  - Neue Anleitung [Jahresbericht lesen](docs/anleitungen/jahresbericht-lesen.md)
    mit einem Rezept, wie Home Assistant den Bericht verschickt.
- **Sicherheits-Check (N1020)** unter Einstellungen → Zugriff und System:
  Anmeldeart, Proxy-Kopfzeile, Hostliste und ein Selbsttest, ob `data/` vom
  Webserver gesperrt ist. **„Überall abmelden“** beendet die Sitzungen einer
  Person auf allen Geräten (`POST /api/session/logout-everywhere`).
- **Hostliste in der Oberfläche:** Verwalter tragen unter Zugriff ein, unter
  welchen Namen die App erreichbar ist (`allowed_hosts`, zusätzlich zu
  `ET_ALLOWED_HOSTS`); der aktuelle Name muss dabei sein, aussperren geht
  nicht.
- **Energiefluss im Haus neu (F1026):** kreuzungsfrei — Sonne oben, Netz
  links, Speicher rechts, Haus unten, darunter Wärmepumpe, Wallbox und
  Übriges; Werte nur in den Kreisen, Ringe für Herkunft und Verbleib, Bänder
  nach Menge, Punkte mit gleichem Tempo und Dichte nach Menge; Jahr und Monat
  umschaltbar mit Übergang an Ort und Stelle; ein Tippen auf Sonne, Netz,
  Speicher oder Haus hebt dessen Flüsse hervor. „Bewegung reduzieren“ zeigt
  das ruhige Endbild; Screenreader bekommen die Flüsse als Liste.
- **Vertrag auf einen Blick** zeigt jetzt Mindestlaufzeit, Preisgarantie und
  Folgevertrag.
- **Blättern in langen Listen (N1021):** ab 51 Zeilen, 25/50/100 je Seite,
  je Gerät gemerkt; Filter „Nur auffällige“. Ablesungen und Zeiträume zeigen
  „N in Jahr · M gesamt“ und haben die Jahreswahl samt „Alle Jahre“ in der
  Karte.
- **API (additiv):** `phase` (`future`, `current`, `renewed`, `past`,
  `shadow`) und `values_on` an den Vertragsantworten, `phase` auch an
  `contract-status`; `contract-status` zusätzlich `min_term_end`,
  `price_guarantee_until`, `auto_renews`; `pv-summary` je Monat und Jahr mit
  `battery`, `heat_pump_kwh`, `ev_charger_kwh` und `flow`.
- **Unterstützen (GitHub #28):** Einstellungen → System führt zu Buy Me a
  Coffee; dazu ein Abschnitt im README und der „Sponsor“-Knopf auf GitHub.
- **Erledigt mit Verlauf (F1024):** Hinweise lassen sich abhaken — Haken bei
  „Zu tun“ und bei den Terminen, ein kleiner Dialog mit Datum
  (rückdatierbar), Notiz und Beleg, bei einer Kündigung „gekündigt,
  verlängert sich nicht“, Rückgängig im Hinweis. Neuer Tab **Hinweise →
  Erledigt** (ab „Erfahren“): wer was wann erledigt hat, nach Monaten, mit
  Filter nach Person, Zeitraum und Bereich; automatische Einträge nennen
  ihren Grund (Folgevertrag, Ablesung, Lieferung, Nebenkostenabrechnung;
  Home Assistant und evcc als Integration); Rückgängig streicht durch statt
  zu löschen, Notizen lassen sich nachtragen. Verwalter stellen die
  Aufbewahrung ein (`activity_keep_months`, Standard 60 Monate) und leeren
  den Verlauf. Einwand- und CO₂-Erstattungsfrist sowie Preiserhöhungen sind
  jetzt abhakbar und stehen unter „Zu tun“. Beim Löschen einer Person kann der
  Verwalter ihren Namen im Verlauf durch „ehemalige Person“ ersetzen. Neue
  Anleitung [Hinweise erledigen](docs/anleitungen/hinweise-erledigen.md).
- **API (additiv, Klasse C):** `POST /api/agenda/done`, `GET`/`DELETE
  /api/activity`, `POST /api/activity/{id}/undo`, `PATCH /api/activity/{id}`;
  Agenda-Ereignisse mit `occurrence`, `can_mark_done`, `done`, `title_key`;
  `contract-status` mit `successor`; `reminders/{id}/done` und `dismiss`
  antworten zusätzlich mit `activity_id`.

### Changed

- **Doku-Links** in Hilfe, Home Assistant, Einrichtung, Personen, evcc,
  Flexibilität und Jahresbericht öffnen das Handbuch in der App statt GitHub.
- **Sitzung** gleitet: 30 Tage ab der letzten Nutzung statt ab der Anmeldung.
- **Docker:** PHP 8.5 im Image und in der CI-Matrix (GitHub #27); das Image
  enthält `docs/` und `CHANGELOG.md`; ein gehärtetes Compose-Beispiel steht
  in der Doku, die CI startet das Image so; ein wöchentlicher Neubau des
  neuesten Release-Tags mit Image-Scan hält die Basis aktuell.
- **PDF-Schreiber** misst Text mit den echten Breiten der Standardschriften;
  auch CO₂-Anschreiben und Ladestrom-Nachweis brechen damit sauber um.
- **Verträge rückwärts durch die Zeit (N1021):** Kosten → Verträge und
  „Verträge & Abschläge“ der Verbrauchsart zeigen eine Reihenfolge —
  Zukünftig · Aktuell · Beendet (ab drei eingeklappt) · Angebote zum
  Vergleich mit Verweis auf den Tarifvergleich; der Gruppenvertrag ist
  eingereiht, Sonderzahlungen stehen neueste zuerst. Die Karten nennen die
  heute gültigen Werte (Arbeitspreis, Grundpreis, Abschlag), die Zählungen
  erst ab „Experte“. Im Vertragsdialog stehen die Preiszeilen neueste oben,
  „+ Zeile“ fügt oben ein.
- **Rechnung prüfen:** ein Zeitraum „bis einschließlich“ für Prüfen und
  Erfassen; „Rechnung des Versorgers erfassen (optional)“ steht eingeklappt
  unter dem Ergebnis.
- **Ein Formularraster für alle Dialoge (N1021):** sechs Spalten, Breite nach
  Inhalt (Datum und Zahl schmal, Text breit, Notiz über die volle Breite), am
  iPhone zwei Spalten; Beschriftung, Feld und Hinweis einer Zeile stehen auf
  gleicher Höhe. Zusammengehörige Felder stehen in einem Kasten mit
  Überschrift und eigener Feldfläche, auch im Dunkelmodus. Zeilenlisten
  (Preise, Boni, Sonderzahlungen, Zeitfenster, Posten) folgen einem Muster
  — Datum | Betrag | Zusatz | ✕ —, beschriftet nur über der ersten Zeile.
  Steuerelemente sind 36 px hoch, bei Touch 44 px.
- **Vertragsdialog** in der Reihenfolge einer Rechnung: Vertrag → Laufzeit
  und Kündigung → Preise → Netzentgelt § 14a → Boni → Sonderzahlungen →
  Erlöse → Notizen. Im **Zählerdialog** stehen Rolle und Erfassung oben.
- **Einstellungen:** alle Karten im Raster, oben bündig, schmale
  nebeneinander, breite am Seitenende; „Aktive Verbrauchsarten“ ist schmal
  mit Kästchen in Spalten; Home Assistant und „Anmeldung & Zugriff“ sind je in
  zwei Karten geteilt.
- **Darstellung (Hell/Dunkel):** Das Symbol zeigt, was gilt (☀️/🌙), ein „A“
  markiert „wie das System“, und jeder Klick meldet kurz den neuen Zustand —
  vorher sah der Schritt auf „wie das System“ wie ein wirkungsloser Klick aus.
- **Folgevertrag statt Erinnerung:** Schließt ein Folgevertrag lückenlos an,
  entfallen Kündigungsempfehlung, Hinweise der Auswertung und „Kündigen
  bis“; die Übersicht zeigt „Folgevertrag … ab …“, mit Lücke nennt die
  Erinnerung die Lücke. Weiterlaufende und unbefristete Verträge heißen in
  der Übersicht „Jederzeit kündbar“.
- **Kalender:** Erledigtes behält seine UID, bekommt „✓ “ und keine
  Vorab-Erinnerung; `/api/summary` zählt Erledigtes nicht mehr.
- **Ausblenden einer Kündigungsempfehlung** hält mindestens bis zur Frist
  („Bis … ausblenden“).

### Removed

- **Import von v0.9.0-Backups** samt Routen `POST /api/migration/v09/*`,
  Karte in den Einstellungen und Anleitung.

### Security

Beschrieben nach Klasse, nicht nach Schritten. Gefunden bei einer eigenen
Sicherheitsprüfung; Hinweise bitte weiter wie in [SECURITY.md](SECURITY.md).

- **Rechteausweitung im Proxy-Modus** über selbst gesetzte Benutzer-
  Kopfzeilen geschlossen.
- **Fehlende Rollenprüfung** beim Import alter Backups — die Funktion
  entfällt; Snapshots löschen dürfen nur noch Verwalter.
- **Docker:** PHP-FPM ist im Container nur noch über 127.0.0.1 erreichbar;
  die CI prüft das.
- **Sperre nach Fehlversuchen** je Gerät und je Name statt für den ganzen
  Haushalt, dazu eine Notbremse; die Antwortzeit der Anmeldung verrät nicht
  mehr, ob ein Name existiert.
- **Schutz gegen DNS-Rebinding** per Hostliste in der Oberfläche.
- **Verfügbarkeit:** Anfragekörper werden erst nach der Anmeldung und nur bis
  zur Größengrenze gelesen (413); Aufrufe fremder Dienste halten die
  Schreibsperre nicht mehr, die Sperre hat eine Frist (503 mit Retry-After).
- **Apache:** Der Schutz von `data/` greift auch ohne mod_rewrite.
- **Abmelden** leert die zwischengespeicherten Daten wieder vollständig
  (Rückschritt aus v3.1.0).
- **CSV-Export:** Formelschutz für alle Zellen außer Zahlen; Kennungen werden
  beim Anlegen und im Backup geprüft. Werte in der Home-Assistant-Vorlage
  sind gequotet.
- **Verbindungen nach außen** begrenzt (Protokoll, Weiterleitungen,
  Antwortgröße); Link-local-Adressen zählen nicht mehr als Heimnetz.
- Der Hostname des Containers geht nur noch an den vertrauenswürdigen Proxy;
  Speicherpfade werden lexikalisch geprüft; Katalogtexte dürfen nur noch
  `strong`, `em` und `code` enthalten.
- **Lieferkette:** fremde GitHub-Actions per Commit-Hash, ausdrückliche Rechte
  je Workflow, Dependabot auch für Composer.

### Fixed

- **Jahresbericht:** Text lief über den rechten Rand; „-0“ in
  Prozentspalten; „Jahresverbrauch“ über PV und Speicher; Temperatur und
  Gradtage bei Strom, Wasser und PV; „0,00 €“ bei Subzählern, Wärmemenge und
  Nebenkosten (jetzt erklärt, bei Miete der Betrag nach den Mietpreisen);
  Empfehlungen aus anderen Jahren; „keine offenen Empfehlungen“ trotz
  Terminen; Spaltenköpfe im Ladestrom-Nachweis; „Abschlag 0 €“ als
  Vorschlag.
- **Wasser** wird nicht mehr zum Tarifwechsel empfohlen — auch nicht in den
  Empfehlungen der App.
- **Rechnung prüfen** über Kosten → Rechnung prüfen zeigte keine
  Verbrauchsliste: Die Seite rechnete nur mit einem Zeitraum in der Adresse.
  Jetzt rechnet sie auf beiden Wegen sofort, mit der letzten abgeschlossenen
  Abrechnungsperiode nach dem Stichtag der Art; ein Wechsel von Art oder
  Zähler behält den Zeitraum.
- **Ablesungen:** „43 Ablesungen“ im Kopf, aber 10 in der Liste — die Zahl
  galt für alle Jahre, die Liste für das gewählte. Jetzt steht beides da.
- **Saldo-Karte** zeigte bei überlappenden Verträgen den abgelösten älteren.
- **Energiefluss:** Linien kreuzten sich mit Speicher, Zahlen lagen
  übereinander; Sonne→Haus lieferte mehr, als erzeugt wurde; in
  unvollständigen Jahren fehlte der Fluss Sonne→Haus.
- **Vertrag auf einen Blick:** verlängerte Verträge zeigten ein vergangenes
  Ende, unbefristete einen Kündigungsstichtag „heute“, ein verpasster
  Stichtag wurde trotzdem als Frist genannt, Beschriftungen überlappten, und
  „heute“ lag nachts einen Tag daneben.
- **Geld- und Wetterbild:** Prozentzahlen liefen über den Rand, Legende und
  Beschriftungen überlappten in einigen Sprachen.
- **Überlappende und schwebende Felder** in Vertrags- und Zählerdialog,
  Checkbox-Texte in Versalien, schwebende ✕-Knöpfe, „+ Zeile“ über die volle
  Breite und das tiefer sitzende Feld im Zählertausch.
- **„Angebot erfassen“** zeigte Aufschlag und Umsatzsteuer des dynamischen
  Tarifs auch ohne dynamischen Tarif — `hidden` wirkt jetzt überall.
- **§ 14a Modul 3:** „von“ und „bis“ werden gleich eingegeben; „6“ oder
  „1530“ wird zu 06:00 bzw. 15:30.
- **Wasservertrag** scrollte am iPhone seitlich.
- **Wandernder Kündigungsstichtag:** Weiterlaufende und unbefristete
  Verträge erzeugten jeden Tag einen neuen „letzten Tag für die Kündigung“ in
  Agenda, Kalender und Home Assistant (`summary.contract.cancel_by` = heute).
- **Ausgeblendete Kündigungsempfehlungen** kamen vor dem Stichtag zurück.
- **Ein angelegter Folgevertrag** ließ alle Erinnerungen des Vorgängers
  stehen.

### Migration

Kein Schemaschritt. Neue Einstellungen (`allowed_hosts`, `activity_keep_months`)
haben Voreinstellungen; neue Umgebungsvariablen `ET_PROXY_USER_HEADER` und
`ET_LOCK_WAIT_S` sind optional.

### Tests

- Neue PHPUnit-Klassen: `DocsServiceTest`, `DocsEndpointTest`,
  `HouseholdSecurityTest`, `HouseholdHardeningTest`, `ContainerHardeningTest`,
  `PdfWriterTest`, `ReportAdviceServiceTest`, `ReportModelServiceTest`,
  `ContractPhaseTest`, `ContractPhaseEndpointTest`, `ContractStatusTimelineTest`,
  `ActivityServiceTest`, `ActivityAccessTest`; erweitert u. a.
  `PvSummaryServiceTest`, `PersonaDemoTest`, `WebrootRulesTest`, `IngressTest`.
- Neue Node-Suiten: `docs.test` (jede Handbuchseite, Angriffs-Payloads),
  `cache-names.test`, `poisoned-render.test` (präparierte Daten über alle
  Ansichten), `lists.test`, `explainer.test` (Geometrie, Bilanz, 7 Sprachen),
  `forms.test` (Formularraster).
- Die Testwerkzeuge laufen parallel auf eigenen Ports (`ET_TEST_HOST`,
  `ET_DEMO_PORT`).
- 813 Testmethoden (+118). 265 Gegenproben, alle rot.

### Lessons Learned

- **Parallel bauen heißt sorgfältig zusammenführen.** Sechs Pakete entstanden
  in getrennten Arbeitskopien; beim Zusammenführen blieben einmal
  Konfliktmarker in der CI-Datei stehen, weil nur das Ende der Merge-Ausgabe
  gelesen wurde. Seitdem wird nach jedem Zusammenführen das ganze Repo nach
  Markern durchsucht und die Liste der offenen Dateien geprüft.
- **Eine Formel an zwei Stellen braucht eine gemeinsame Probe.** Die Autarkie
  rechnen Server und Schaubild; beide prüft jetzt dieselbe Datei mit
  Beispielfällen.
- **Ein Stichtag „heute“ ist kein Stichtag.** Weiterlaufende Verträge meldeten
  jeden Tag eine neue Frist; Kalender und Home Assistant zeigten sie als
  Termin. Eine Erinnerung braucht ein festes Datum oder gar keins.
- **Ausblenden muss überall wirken.** Eine Formularregel mit `display:flex`
  übersteuerte `hidden`; Felder des dynamischen Tarifs standen sichtbar
  daneben. Eine globale `[hidden]`-Regel ersetzt sieben Einzelflicken.
- **Vertrauen in Kopfzeilen braucht einen Filter davor.** Eine
  Benutzer-Kopfzeile ist nur so vertrauenswürdig wie der Dienst, der sie
  setzt und fremde entfernt.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

## [3.3.0] — 2026-10-12 — Was Flexibilität wert ist

MINOR-Release mit Schemaschritt: **F1021** rechnet nach, was das Verschieben
von Verbrauch gebracht hat — Wallbox, Wärmepumpe und Speicher gegen den
Börsenpreis je Viertelstunde, dazu das zeitvariable Netzentgelt nach § 14a
EnWG (Modul 3). Gesteuert wird weiter außerhalb (evcc, Home Assistant, der
Speicher selbst); der Energietracker misst nach. **Schema 1.7.0 → 1.8.0**
(neuer Datentopf für Viertelstundenreihen). API, CSV-Format 1, Backup-Format
3.0 und der Home-Assistant-Ingest wachsen additiv; nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Schemaschritt 1.8.0:** Der erste Aufruf nach dem Update legt
  `data/intervals.json` an (leerer Index) — mit Sicherung vorher wie bei jedem
  Schritt. Bestehende Daten bleiben unverändert.
- **Neue Seite nur für Experten:** „Flexibilität“ unter Auswertungen erscheint
  in der Stufe Experte; nach einem Update steht die Installation dort ohnehin.
- **Home Assistant:** Die Vorlage in den Einstellungen schickt jetzt
  `"timestamp"` mit; die Automatisierung bleibt bei einem Push am Tag. Für
  Viertelstunden kommt eine zweite Automatisierung alle 15 Minuten dazu
  ([Anleitung](docs/anleitungen/home-assistant.md)). Wer die alte Vorlage
  behält, verliert nichts — der Tageswert kommt weiter an.
- **Docker:** Die Beispielhaushalte bringen ein Jahr Viertelstunden mit und
  wachsen zusammen auf rund 2,2 MB.

### Added

- **Flexibilität bewerten (F1021):** neue Seite „Flexibilität“ je Jahr.
  - **Je Stromzähler mit Viertelstunden:** kWh gesamt, davon in günstigen
    Viertelstunden (Börsenpreis unter dem Monatsmittel) und bei negativem
    Preis; eigener Durchschnittspreis (nach Verbrauch gewichtet) gegen das
    Börsenmittel, **Profilfaktor** = eigener ÷ Börsenmittel (unter 1: mehr in
    günstigen Zeiten) und der Vorteil in Euro — nur der Börsenanteil, ohne
    Steuern, Umlagen und Netzentgelte. Aufklappbar je Monat.
  - **Speicher** (Zähler mit Rolle Laden/Entladen): geladen und entladen zu
    welchem Börsenpreis — der Wert der Verschiebung.
  - **Modul 3:** Zeitfenster mit eigenem Netzentgelt je Quartal am Vertrag
    (`grid_tou`, Expertenfeld im Vertragsdialog); die Seite rechnet die
    Ersparnis gegen das Standard-Netzentgelt aus den eigenen Viertelstunden.
  - Gerechnet wird nur über Viertelstunden, für die Verbrauch und Preis
    vorliegen; die Abdeckung steht jeweils dabei.
- **Viertelstundenreihen:** je Zähler und Jahr eine Reihe mit 35 040
  Viertelstunden (Schaltjahr 35 136), Jahresgrenzen in der Zeitzone der
  Installation. Drei Quellen:
  - **Home Assistant:** `POST /api/ingest` nimmt optional `timestamp` (ISO 8601
    mit Zone). Die Differenz zum vorigen Stand verteilt sich anteilig auf die
    Viertelstunden dazwischen; nach mehr als sechs Stunden Pause, bei
    fallendem, doppeltem oder älterem Stand wird nichts verteilt. Ein als
    unplausibel markierter Stand zählt nicht.
  - **Netzbetreiber-Portal:** Der Import von Lastgängen behält bei einem Takt
    von 15, 30 oder 60 Minuten auch die Viertelstunden; die Antwort nennt
    `intervals` (Takt, Zahl).
  - **Börsenpreise:** „Börsenpreise … von SMARD laden“ holt den Day-Ahead-Preis DE-LU je
    Viertelstunde eines Jahres von SMARD (Bundesnetzagentur | SMARD.de,
    CC BY 4.0) — nur auf Knopfdruck. Vor dem 1. Oktober 2025 gab es
    Stundenprodukte; dann gilt derselbe Wert für alle vier Viertelstunden.
- **Dynamik-Check mit eigenem Lastgang:** Liegen für einen Monat mindestens
  90 % der Viertelstunden mit Verbrauch und Preis vor, rechnet der dynamische
  Schattentarif mit dem nach Verbrauch gewichteten Börsenpreis statt dem
  einfachen Monatsmittel. Der Tarifvergleich nennt, für wie viele Monate
  (`dynamic_profile_months`).
- **Neue Routen:** `GET /api/flexibility?year=`, `GET /api/intervals`,
  `DELETE /api/intervals/{id}`, `POST /api/market-prices/sync-smard-intervals?year=`.
  Neue Fehlercodes `errors.flexibility.yearInvalid`,
  `errors.intervals.notFound`, `errors.ingest.timestampFormat`,
  `errors.contract.gridTouInvalid`,
  `errors.marketPrices.syncQuarterHoursFailed`.
- **Glossar:** „Profilfaktor“ in allen sieben Sprachen; neue Anleitung
  [Flexibilität bewerten](docs/anleitungen/flexibilitaet.md).
- **Beispielhaushalt „Eigenheim mit Wärmepumpe und PV“:** ein Jahr
  Viertelstunden für Haushalt, Wärmepumpe, Wallbox und Speicher, echte
  Börsenpreise je Viertelstunde 2025 (SMARD, CC BY 4.0) und ein Vertrag mit
  Modul-3-Zeitfenstern. Die Zeitfenster und ihre Preise sind erfunden.

### Changed

- **Backup:** Die Viertelstundenreihen reisen im Backup mit (Topf
  `intervals`, Dateien in `interval_files`). Bringt ein Backup den Topf mit,
  werden beim Einspielen Reihen entfernt, die es nicht kennt; ein älteres
  Backup ohne den Topf lässt die Reihen stehen (Teil-Restore wie bisher). Wie
  jedes Backup mit neuerem Schema lässt sich eines aus 3.3.0 nicht in eine
  ältere Version einspielen.
- **Home-Assistant-Vorlage** mit `"timestamp": "{{ now().isoformat() }}"`.
- **Zeitreihen-Import:** Die Vorschau nennt die Werte je Viertelstunde.
- **Speicherung:** Viertelstundenreihen werden kompakt geschrieben (bis zu rund
  250 KB je Reihe und Jahr), alle übrigen Dateien weiter eingerückt.

### Migration

Schema **1.7.0 → 1.8.0**: legt `intervals.json` an (`{"series": {},
"samples": {}}`). Die Reihen selbst liegen unter `data/intervals/` und
entstehen erst mit dem ersten Viertelstundenwert. Der Schritt ändert keine
bestehenden Daten.

### Tests

- Neue Testklassen `IntervalServiceTest` (Migration, Zeitumstellung,
  Zählerstände, Backup, Portal-Import, Ingest, SMARD) und `FlexibilityTest`
  (Verschiebung, Speicher, Modul 3, Validierung, gewichteter Börsenpreis).
- Render-Test der Seite „Flexibilität“; Stufen- und Demo-Klicktest kennen sie.
- 695 Testmethoden (+16). 22 Gegenproben, alle rot.

### Lessons Learned

- **Eine Viertelstunde ist eine Ortszeit.** Ein Jahr hat 35 040 Viertelstunden
  in UTC gezählt, aber der Monat und die Uhrzeit eines Zeitfensters hängen an
  der Zeitzone — zweimal im Jahr fehlen oder doppeln sich vier davon.
- **Ein Zählerstand ohne Zeitpunkt ist ein Tageswert.** Erst der Zeitstempel
  aus Home Assistant macht aus Ständen einen Lastgang; ohne ihn bleibt alles,
  wie es war.
- **Rechnen nur mit Abdeckung.** Eine Ersparnis aus der Hälfte der
  Viertelstunden sieht genauso genau aus wie eine aus allen; deshalb steht die
  Abdeckung neben jeder Zahl, und der Dynamik-Check fällt unter 90 % auf das
  Monatsmittel zurück.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

## [3.2.0] — 2026-10-09 — Leicht für jeden

MINOR-Release: Der Einstieg wird leicht, ohne dass Erfahrene etwas verlieren.
**F1019** Nutzungsstufen, **F1020** Schaubilder, **F1018** Einrichtungsassistent
mit vier Beispielhaushalten, **F1023** Personen im Haushalt und **F1022**
Ladevorgänge aus evcc. **Kein Schemaschritt** (bleibt 1.7.0). API, CSV-Format 1,
Backup-Format 3.0 und der Home-Assistant-Ingest wachsen additiv; nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Es verschwindet nichts:** Nach dem Update steht die Installation auf der
  Stufe „Experte“ — die Oberfläche sieht aus wie bisher. Der
  Einrichtungsassistent erscheint nur beim allerersten Start einer neuen
  Installation, nicht nach einem Update.
- **Anmeldung mit Passwort:** Das bisherige Passwort gehört jetzt dem ersten
  Verwalter („admin“). Die Anmeldung ohne Namen funktioniert weiter, bis du
  Personen anlegst; danach fragt die Anmeldung nach dem Namen.
- **Proxy-Anmeldung:** Die erste Person, die der vorgeschaltete Dienst meldet,
  wird Verwalter; alle weiteren sind Mitglieder, bis ein Verwalter das ändert.
- **Docker:** Das Image enthält jetzt auch die Beispielhaushalte
  (`demo-data/personas/`, zusammen rund 1,2 MB).
- **CO₂-Kostenaufteilung (Mieter):** Wer unter v3.1.0 die CO₂-Kosten aus einer
  Versorgerrechnung eingetragen hat, bekam rund 19 % zu viel ausgewiesen (siehe
  „Fixed“). Ein damit erstelltes Anschreiben an den Vermieter bitte neu
  erzeugen.

### Added

- **Nutzungsstufen (F1019):** Einsteiger 🌱, Erfahren 🌿, Experte 🌳 — ein
  Umschalter in der Kopfleiste neben Tag/Nacht und eine Karte unter
  Einstellungen → Allgemein. Die Stufe bestimmt, was die Oberfläche zeigt,
  nicht was die App rechnet: API, Daten und Export sind in jeder Stufe gleich,
  ausgeblendete Formularfelder bleiben beim Speichern erhalten. Einsteiger
  sehen auf der Übersicht drei Antworten (Geld zurück? Mehr als im Vorjahr?
  Was ist zu tun?) mit einem Schaubild. Seiten über der Stufe öffnen mit einem
  Hinweis zum Hochstufen; die Übersicht schlägt eine höhere Stufe vor, wenn
  Daten da sind, die erst sie zeigt (Subzähler, Home Assistant, Wärmepumpe,
  Speicher, Wallbox) — abgelehnt bleibt abgelehnt. Einstellung `ui_level`.
- **Schaubilder (F1020):** „Wohin geht mein Geld“, „Energiefluss im Haus“,
  „Kälter oder mehr verbraucht“ und „Vertrags-Zeitstrahl“ — mit den eigenen
  Daten, als SVG mit CSS-Bewegung ohne Bibliothek, still bei „Bewegung
  reduzieren“, die Aussage als Text für Screenreader. Auf der Übersicht
  (Experte: eigener Abschnitt) und in der Verbrauchsansicht jeder Art.
- **Einrichtungsassistent und Beispielhaushalte (F1018):** Haushalt wählen →
  Verbrauchsarten → Erfahrung → eigene Daten oder Beispiel laden. Vier
  Beispielhaushalte über drei Jahre — Mietwohnung mit Heizwärme aus der
  monatlichen Verbrauchsinfo und Nebenkostenabrechnung, Eigentumswohnung mit
  Fernwärme, Eigenheim mit Gas, Eigenheim mit Wärmepumpe, PV, Speicher und
  Wallbox — dazu der bisherige Haushalt mit allen Arten als Schaufenster. Sie
  zeigen auch den Unterschied zwischen Fernwärme (eigener Liefervertrag) und
  Heizwärme (gemessen, bezahlt über die Miete oder als Wärmemenge der
  Wärmepumpe). In der GitHub-Demo mit Wahl des Haushalts. Einstellungen
  `setup_pending`, `setup_persona`; `POST /api/demo/import` nimmt `persona`,
  `GET /api/demo/status` nennt `personas`.
- **Personen im Haushalt (F1023):** eigene Personen mit Name und Passwort,
  im Proxy-Betrieb am gemeldeten Namen erkannt. **Verwalter** ändern den
  Zugriff (Personen, Schlüssel, Anmeldung), spielen Backups und
  Beispielhaushalte ein und tragen Netzadressen ein; **Mitglieder** erfassen,
  sehen alles und stellen ihre eigene Stufe und Sprache ein. Ein neues Passwort
  meldet die Person auf allen Geräten ab. Personen stehen in `auth.json`, nicht
  im Backup. Neue Routen `PATCH /api/session/me`,
  `POST /api/session/me/password`, `GET/POST /api/users`,
  `PATCH/DELETE /api/users/{id}`; `GET /api/session` nennt zusätzlich
  `named_login`, `user`, `role`.
- **Ladevorgänge aus evcc (F1022):** den CSV-Export aus evcc hochladen (jede
  Sprache von evcc) oder — mit eingetragener Adresse — „Von evcc abrufen“, nur
  im eigenen Netz wie die Texterkennung. Je Vorgang Menge, Sonnenanteil und
  Preis laut evcc; die Zählerstände der Wallbox kommen gleich mit (aus ihrem
  Zähler oder aufsummiert). Vorschau zuerst, Wiederholen ersetzt statt zu
  verdoppeln. Karte in der Wallbox-Ansicht; neuer Backup-Topf `ev_sessions`,
  Einstellung `evcc_endpoint`, Routen `POST …/strom/meters/{id}/import-evcc`,
  `…/sync-evcc`, `GET /api/ev-sessions`.
- **Hilfe:** Anwendungsfälle in allen sieben Sprachen — „Fernwärme oder
  Heizwärme?“, „Steuern mit evcc oder Home Assistant“ (der Energietracker
  steuert nichts, er rechnet nach) und „Erst einfach, später alles“. Neue
  Anleitungen [Einrichten](docs/einstieg/einrichtung.md),
  [Personen im Haushalt](docs/anleitungen/benutzer.md),
  [Ladevorgänge aus evcc](docs/anleitungen/evcc.md).
- **Versorgerrechnungen bearbeiten** — bisher nur anlegen und löschen.

### Changed

- **Beispieldaten:** Der Haushalt „Eigenheim mit Wärmepumpe und PV“ bringt die
  Börsenstrompreise (Monatsmittel 2023–2026, Bundesnetzagentur | SMARD.de,
  CC BY 4.0) für den Dynamik-Check mit; Ladevorgänge werden wie Lieferungen
  bis heute fortgeschrieben.
- **README / Hilfe „Deine Daten“:** nennt den Abruf aus evcc im Heimnetz.

### Fixed

- **CO₂-Kosten laut Rechnung doppelt besteuert:** Der Lieferant weist den
  CO₂-Preisbestandteil mit Umsatzsteuer aus („zuzüglich einer auf diesen Betrag
  anfallenden Umsatzsteuer“, § 3 Abs. 3 CO2KostAufG). Die App las ihn als netto
  und schlug 19 % auf — Erstattung und Anschreiben lagen rund 19 % zu hoch.
  Jetzt gilt der Rechnungsbetrag als brutto.
- **Umsatzsteuer auf Gas und Fernwärme 10/2022–3/2024:** Gerechnete CO₂-Kosten
  nahmen immer 19 %; in dieser Zeit galten 7 % (§ 28 UStG) — auch für
  Heizwärme aus Gas oder Fernwärme. Jetzt je Monat, für
  das Jahr nach Verbrauch gewichtet; `GET /api/co2-costs` nennt je Zeile `vat`.
- **Dynamischer Tarif als Angebot mit künftigem Beginn** meldete „keine
  Marktdaten“: Die Monatspreise begannen erst mit dem Vertragsbeginn. Ein
  Schattenvertrag ist ein Preisblatt für den ganzen Zeitraum — jetzt auch der
  dynamische.
- **Ladestrom-Nachweis:** Lädt PV hinter dem Hauszähler, misst die Wallbox mehr
  als der Netzbezug; der Anteil am Grundpreis überstieg dann den ganzen
  Grundpreis. Jetzt höchstens 100 %.
- **Beispieldaten laden ersetzt den ganzen Haushalt** — Töpfe, die eine
  Demo-Datei nicht kennt, werden leer eingespielt statt stehen zu bleiben.
- **Rechtsangaben der Doku** gegen die Primärquellen geprüft (56 Aussagen,
  Stand 09.10.2026) und korrigiert, wo sie abwichen — darunter § 51 EEG
  (negative Preise erst ab dem Jahr nach dem Einbau des intelligenten
  Messsystems), Fristen der CO₂-Erstattung und die Umbenennung des GEG in
  Gebäudemodernisierungsgesetz (GModG) seit 29.07.2026.

### Migration

Kein Schemaschritt. Neue Einstellungsschlüssel (`ui_level`, `setup_pending`,
`setup_persona`, `evcc_endpoint`) haben Defaults; `ev_sessions.json` entsteht
beim ersten Import. Das Backup-Format bleibt 3.0; ältere Versionen ignorieren
den neuen Topf.

### Tests

- Neue Testklassen `HouseholdUsersTest`, `HouseholdAccessTest`,
  `EvccServiceTest`, `PersonaDemoTest`; erweitert `DemoServiceTest`,
  `DemoDataAlignerTest`, `DemoDataTranslatorTest`, `EvChargingReportTest`,
  `MarketPriceTest`, `FirstStartProfileTest`, `OutboundHostsTest`.
- Neue Node-Suite `tests/levels.test.mjs` (Stufen, Navigation, CSS,
  Schaubilder); der Demo-Klicktest geht jeden Beispielhaushalt in zwei Stufen
  durch.
- 679 Testmethoden (+40). 32 Gegenproben, alle rot.

### Lessons Learned

- **Eine Liste von Hand ist eine Liste, die veraltet.** `completePayload`
  zählte die Töpfe selbst auf und vergaß den ersten neuen; jetzt liest es
  `BackupService::TOP_POTS`.
- **Zustandslose Sitzungen brauchen eine Epoche.** Ein signiertes Cookie
  überlebt einen Passwortwechsel, wenn nichts an der Person sich ändert, das
  mitsigniert ist.
- **Beispieldaten sind Tests mit echten Fragen.** Der Haushalt mit Wallbox und
  dynamischem Angebot fand den Fehler im Dynamik-Check, den kein Unit-Test
  gesucht hatte.
- **Ausblenden statt abschalten** hält die Stufen billig: eine Mindeststufe je
  Seite und ein Attribut am Element, gerechnet wird immer alles.
- **Eine Rechtsprüfung ist eine Rechenprüfung.** Der Wortlaut „zuzüglich
  Umsatzsteuer“ entschied, ob ein Betrag brutto oder netto ist — und damit über
  19 % in jeder Erstattung.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [3.1.0] — 2026-10-07 — Für jeden Haushalt

MINOR-Release mit den letzten beiden Paketen des Gesamtreviews in einem
Schritt: **Paket G „International“** (Sprache pro Gerät, Terminologie,
CSV in der Landessprache) und **Paket H „Ökosystem und Ausbau“** (H1–H8:
Home Assistant liest mit, Belege und Offline-Erfassung, Mieter-Paket, CO₂-Preis,
Rechnungen für alle Arten, Strom mit Gruppenvertrag und Börsenpreisen,
Wärmepumpe und PV, Einordnung und Portal-Importe). Damit sind
[#15] (Nebenkosten für Mieter) und [#17] (Verträge je Zählergruppe) umgesetzt.
**Ein Schemaschritt 1.6.0 → 1.7.0**, der nur leere Töpfe anlegt. API,
CSV-Format 1, Backup-Format 3.0 und der Home-Assistant-Ingest wachsen additiv;
nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Schema 1.7.0:** Beim ersten Start legt die Migration neue, leere Töpfe an
  (Liste unter „Migration“) und ändert keinen Datensatz. Ein Snapshot entsteht
  vorher wie bei jedem Schemaschritt.
- **Sprache pro Gerät:** Die Oberfläche folgt jetzt der Sprache, die du auf dem
  Gerät wählst (Einstellungen → Allgemein → „Sprache auf diesem Gerät“). Die
  bisherige Einstellung heißt „Standardsprache der Installation“ und gilt für
  neue Geräte, den PDF-Jahresbericht, CSV „local“, den Kalender und Home
  Assistant.
- **Eigener Webserver (Apache/nginx):** Mit Belegen werden Backups größer. Das
  Docker-Image erlaubt jetzt 256 MB je Anfrage (`post_max_size`,
  `upload_max_filesize`, nginx `client_max_body_size`), `memory_limit` 768 MB
  und `fastcgi_read_timeout` 310 s. Wer selbst betreibt, passt die Werte an
  ([Webserver](docs/betrieb/webserver.md)). Die Sperrliste umfasst jetzt auch
  `tools/`, `dist/` und `deploy/` — `.htaccess` erledigt das für Apache, eine
  eigene nginx-Konfiguration braucht die drei Verzeichnisse von Hand.
- **PHP ab 8.2** statt 8.4 (CI prüft 8.2, 8.3 und 8.4; das Docker-Image bleibt
  bei 8.4).
- **Zählerdialog:** Die Auswahl „Rolle“ ersetzt das Häkchen „Heizstrom
  (Wärmepumpe)“. Das Feld `heat_source` bleibt und wird mit `role` gleich
  gehalten.

### Added

**Sprachen (Paket G)**

- **Sprache pro Gerät (I18N-29):** Die Oberfläche schickt `X-ET-Language`;
  Reihenfolge Gerät → Standardsprache der Installation → `Accept-Language`.
  API-Antworten tragen `Vary: X-ET-Language, Accept-Language`. Das
  App-Manifest gibt es je Sprache (`GET /api/manifest?lang=`, I18N-30).
- **CSV in der Landessprache (I18N-10/11):** `?format=local[&lang=]` mit
  Kopfzeilen, Dezimal- und Feldtrenner, Datum und Ja/Nein der Sprache. Format 1
  bleibt Standard der API und ist eingefroren (Stabilitätsklasse A). Der Import
  liest ISO-Datum und Tag-vor-Monat in jeder Schreibweise, Kopfzeilen aus allen
  sieben Sprachen und jede eigene Export-Datei; Monat-vor-Tag wird nie
  umgedeutet (`errors.import.dateMonthFirst`).
- **Jahresbericht als Druckansicht (I18N-12):** `#/report/print?year=` in der
  Sprache des Geräts, Diagramme als SVG, „Drucken / als PDF sichern“.
  `GET /api/reports/yearly` liefert die Daten als JSON.
- **So heißt das auf deiner Rechnung (I18N-27):** `GET /api/countries` trägt
  `bill_terms` (Wortlaut der Rechnung je Land) und `comparison_portal`
  (amtliche Vergleichsportale in AT, FR, IT, ES, PT). ⓘ-Erklärungen und
  Glossar zeigen den Begriff, die Wechselentscheidung verlinkt das Portal.
- **Demo-Daten in der Sprache der Oberfläche (I18N-24):** Zähler, Tarife,
  Notizen und Termine des Beispielhaushalts werden beim Import übersetzt
  (`demo-data/translations.json`); Firmennamen, IDs und Zahlen bleiben.
- **Seite „Übersetzen und Sprachen“** mit Stilguide und Checklisten für eine
  neue Sprache und einen neuen Schlüssel.

**Home Assistant liest mit (H1)**

- **`GET /api/summary`** (`summary_version: 1`, Klasse A): Kennzahlen je
  Zähler (Saldo, Prognose, Tage seit Ablesung, Vertrag, Tank) für REST-Sensoren;
  die App erzeugt die passende YAML-Vorlage. Zugang mit API-Schlüssel `read`.
- **Agenda und Kalender-Abo:** `GET /api/agenda` bündelt alle Fristen und
  Termine — „Zu tun“ auf der Übersicht liest daraus. `GET /api/calendar.ics`
  (iCalendar, stabile UIDs, Vorwarnung) mit eigenem Schlüsselbereich
  `calendar`, der nur für das Abo gilt.
- **Stapel-Ingest:** `POST /api/ingest` nimmt bis zu 500 Stände auf einmal
  (Liste oder `{"readings": [...]}`), Antwort je Eintrag; das Einzelobjekt
  bleibt unverändert.
- **Ingress und Plattformen:** Betrieb unter Home-Assistant-Ingress (Proxy-
  Anmeldung erkennt `X-Remote-User-Name`/`-Id`), Vorlagen für Unraid, CasaOS
  und Umbrel unter `deploy/`. Anleitungen für den Kalender und für ioBroker,
  Node-RED und openHAB.

**Belege, offline, Foto (H2)**

- **Belege:** Fotos und PDFs an Ablesungen, Rechnungen und Abrechnungen
  (`/api/attachments`), geprüft am Inhalt, mit Speichergrenze
  `attachments_max_mb`. Im Backup als `attachment_files`
  (`?attachments=0` lässt sie weg), beim Import gegen SHA-256 geprüft.
- **Offline erfassen (FE-32):** Scheitert das Speichern am Netz, wartet der
  Stand im Browser und geht automatisch nach; `client_ref` verhindert doppelte
  Stände (Antwort 200 mit `duplicate: true`). Konflikte mit einem anderen
  Stand am selben Tag fragt die App nach.
- **Foto am Zähler (MKT-08):** „📷 Foto“ in „Zählerstände“, im Browser
  verkleinert, EXIF samt GPS entfernt.
- **Texterkennung im Heimnetz (MKT-08):** optional, aus per Standard. Ein
  eigener Bilddienst (Ollama oder OpenAI-kompatibel, z. B. LM Studio) liest den
  Stand vom Foto; die Adresse muss im eigenen Netz liegen
  (`errors.ocr.notLocal`), gespeichert wird nur nach Klick.

**Mieter-Paket (H3, F1008, [#15])**

- **Verbrauchsart „Heizwärme“ (`waerme`):** Wärmemengenzähler oder monatliche
  Verbrauchsinformation, mit Wetterbereinigung und Prognose wie bei Gas; CO₂
  über den eingestellten Energieträger als Näherung. Neun Verbrauchsarten.
- **Verbrauch je Zeitraum:** dritte Erfassungsart neben Zählerständen und
  Lieferungen (`capture: period`), Routen `…/periods`, CSV-Import und -Export,
  Vergleichswerte der Verbrauchsinfo (§ 6a HeizkostenV).
- **Zählerrollen:** Haushalt, Wärmepumpe, Wallbox; Kalt-, Warm-, Gartenwasser;
  Erzeugung, Speicher laden/entladen; Heizwärme, Wärmemenge der Wärmepumpe.
  Messende Rollen (Speicher, Wärmemenge der Wärmepumpe) zählen wie Subzähler
  nicht in die Summen.
- **Warmwasser-Wärme:** Wasserzähler mit Rolle „warm“ zeigen die Wärme nach
  § 9 HeizkostenV (2,5 × m³ × (t − 10)).
- **Mietverhältnis:** Vorauszahlungen, Preise, Umlagen und Zähler; Budget gegen
  die Nebenkostenvorauszahlung mit Risiko und passender Vorauszahlung;
  Nebenkostenabrechnungen erfassen und Preise übernehmen; Abrechnungs- und
  Einwendungsfrist in Agenda und Kalender. Hilfsrechnung, keine
  Nebenkostenabrechnung.

**CO₂-Preis (H4)**

- **CO₂-Preis im Brennstoff (CALC-27):** Ausweis nach BEHG-Standardfaktoren
  mit dem Preis je Jahr (Länderprofil DE, eigene Werte möglich), Karte in der
  Verbrauchsansicht, `GET /api/co2-costs`. Szenario in der Prognose
  (MKT-26).
- **CO₂-Kosten mit dem Vermieter teilen (MKT-15):** Stufe nach CO2KostAufG,
  Vermieteranteil für Etagenheizungen samt PDF-Anschreiben, Prüfung der
  Heizkostenabrechnung bei Zentralheizung, Frist zum Einfordern im Kalender
  (`co2_claim_deadline`). `GET /api/co2-split`,
  `GET /api/reports/co2-split.pdf`.

**Rechnungen, Fernwärme, Wechsel (H5)**

- **Rechnungsprüfung für Gas, Strom, Wasser und Fernwärme (UI-35, MKT-17):**
  Nachrechnung je Abschnitt; die Versorgerrechnung wird erfasst
  (`/api/utility/{u}/bills`), verglichen (`…/check`, Urteil „passt“ oder
  „prüfen“ mit Gründen) und als Sonderzahlung gebucht (`…/book`, einmal je
  Rechnung).
- **Fernwärme (CALC-31):** Anschlussleistung, Leistungs- und Messpreis mit
  Stichtagen fließen in die festen Kosten; Netzfaktor aus dem Vertrag für
  CO₂-Bilanz und CO₂-Preis.
- **Lieferantenwechsel (MKT-24):** MaLo- und MeLo-ID am Zähler (mit
  Prüfziffer), Karte „Für den Wechsel bereithalten“, Hinweis bei Laufzeiten
  über 24 Monate, Empfehlung bei einer gepflegten Preiserhöhung
  (Sonderkündigungsrecht).

**Strom (H6, F1017, [#17])**

- **Ein Vertrag für eine Zählergruppe:** `meter_group_id` statt `meter_id`,
  optional ein Arbeitspreis je Mitglied (HT/NT). Grundpreis, Abschläge und
  Boni trägt das erste Mitglied, damit jede Summe stimmt. Auswertungen der
  Gruppe unter `/api/utility/{u}/meter-groups/{id}/…`; eine Versorgerrechnung
  an einem Mitglied wird mit der ganzen Gruppe verglichen und in den
  Gruppenvertrag gebucht.
- **§ 14a EnWG, Modul 1 (MKT-13):** reduziertes Netzentgelt als Abzug von den
  festen Kosten.
- **Börsenstrompreise und Dynamik-Check (MKT-12):** Monatsmittel Day-Ahead aus
  einer SMARD-Datei oder auf Knopfdruck von SMARD (`/api/market-prices`);
  Schattenvertrag „dynamischer Tarif“ in der Wechselentscheidung. Monatspreise
  eines Vertrags lassen sich aus CSV importieren.
- **Ladestrom-Nachweis für den Dienstwagen (MKT-14):** je Monat nach
  Vertragspreis oder Pauschale, als JSON, CSV und PDF
  (`/api/reports/ev-charging`). Keine Steuerberatung.

**Wärmepumpe und PV (H7)**

- **Jahresarbeitszahl (MKT-18):** Wärmemenge gegen Strom der Wärmepumpe je
  Monat und Jahr, mit Feldtest-Vergleichswerten (`GET /api/heat-pump`).
- **PV (CALC-29, MKT-16):** Speicher (geladen, entladen, Verluste,
  Vollzyklen), Balkonkraftwerk mit angenommenem Eigenverbrauch, Amortisation,
  Hinweis zu § 51 EEG, eigener Vermeidungsfaktor, Gutschriften des
  Direktvermarkters am Einspeisevertrag.
- **Vorher/Nachher ohne Heizkurve:** Die Analyse-Zäsur vergleicht jetzt auch
  Strom, Wasser und PV (`method: seasonal_mean`).

**Einordnen und übernehmen (H8)**

- **Eigene Vergleichswerte (MKT-11):** Karte „Einordnung“ auf der Übersicht
  gegen selbst eingetragene Werte, mit Links auf Strom- und Heizspiegel
  (`GET /api/benchmarks/comparison`).
- **Zeitreihen aus Portalen (MKT-19):** Viertelstunden-, Stunden- oder
  Tageswerte von Netzbetreiber, Wechselrichter oder Wärmepumpe mit
  Spaltenzuordnung, Vorschau und Trockenlauf
  (`POST …/meters/{id}/import-series`); Zeitumstellung wird richtig gezählt.

**Wasser**

- **Schmutzwasser = Hauptzähler minus Abzugszähler (CALC-20):** neue Basis
  `trinkwasser_minus_abzug` mit `abzug_meter_ids`, z. B. für den Gartenzähler;
  die Prognose rechnet den Anteil je Monat.

### Changed

- **Sprache:** Terminologie nach einer Termbase (= Glossar), Anrede je Sprache,
  Typografie der Zielsprachen, Plural über CLDR-Regeln im Browser und auf dem
  Server, Bezeichnungen nicht mehr mitten im Satz (I18N-06/09/15/19/20/21).
  Die Kürzel der Rechnungsprüfung folgen der Sprache (en/fr/es/pt E/I,
  it/nl S/I, de unverändert S/E; I18N-08).
- **Fehlermeldungen übersetzt (I18N-14):** Wetterabgleich mit
  `archive_error_code`/`forecast_error_code`, beschädigte Datendatei; der Code
  bleibt gleich.
- **PDF:** Zeichensatz je Sprache (`format.pdfCharset`); eine Sprache ohne
  passenden Zeichensatz bekommt 422 `errors.report.pdfUnsupportedLanguage`
  statt falscher Zeichen.
- **„Zu tun“** kommt aus der Agenda — dieselben Regeln, eine Logik für
  Übersicht, Kalender und Home Assistant.
- **Service Worker:** Die Caches tragen den Pfad der Installation im Namen;
  die Selbstheilung räumt nur eigene Caches ab.
- **Demo-Daten:** erfundene Anbieter; der Gartenzähler ist Subzähler des
  Hauptzählers und Abzugszähler fürs Schmutzwasser.
- **Docker:** größere Upload- und Speichergrenzen (siehe oben).

### Fixed

- **Webserver-Sperrliste:** `tools/` und `dist/` (seit 3.0.0 im Repository)
  fehlten auf der Apache-Sperrliste; jetzt gesperrt wie `deploy/` und
  `requirements.txt`.
  `WebrootRulesTest` verlangt, dass jedes Verzeichnis außer `public/` auf der
  Liste steht.
- **Zwei Installationen auf einem Ursprung** (z. B. Test und Betrieb auf
  derselben NAS, oder Ingress): Der Service Worker der einen löschte die
  Caches der anderen.
- **Demo zählte den Gartenwasserzähler doppelt** (eigener Wurzelzähler mit
  eigenem Trinkwasserpreis, CALC-20).
- **Portugiesisch:** Pluralregeln nach pt-PT statt pt-BR.

### Deprecated

- `errors.billCheck.gasOnly` wird nicht mehr gesendet; eine Art ohne
  Rechnungsprüfung bekommt `errors.billCheck.unsupportedUtility`. Der Schlüssel
  bleibt bis zur nächsten Hauptversion im Katalog.

### Migration

Schema **1.6.0 → 1.7.0**, automatisch beim ersten Start, nur additiv:

- neue, leere Töpfe `attachments.json`, `tenancies.json`,
  `tenancy_statements.json`, `market_prices.json`;
- je Verbrauchsart `periods.json` und `bills.json`;
- die Verbrauchsart `waerme` mit leeren Grundtöpfen, ohne Standardzähler.

`data/instance.json` (Kennung der Installation für Kalender und Home Assistant)
entsteht beim ersten Aufruf und ist bewusst nicht im Backup. Das Backup-Format
bleibt 3.0; ältere Versionen ignorieren die neuen Schlüssel.

### Tests

- 31 neue Testklassen: `CatalogStyleTest` (Termbase, Anrede,
  Typografie, Satzbau, Kürzel), `CatalogUsageTest`, `HardcodedTextTest`,
  `PluralRulesTest`, `CsvFormatV1Test` (Golden Files), `CsvLocalFormatTest`,
  `PdfCharsetTest`, `DeviceLanguageTest`, `AgendaServiceTest`,
  `AgendaAccessTest`, `BulkIngestTest`, `IngressTest`, `DeployTemplatesTest`,
  `WebrootRulesTest`, `AttachmentServiceTest`, `BackupAttachmentRoundtripTest`,
  `ReadingClientRefTest`, `OcrServiceTest`, `OutboundHostsTest`,
  `PeriodCaptureTest`, `MeterRolesAndHeatTest`, `TenancyTest`, `Co2CostTest`,
  `BillServiceTest`, `GroupContractTest` (HT/NT: 964 € wie von Hand),
  `MarketPriceTest`, `EvChargingReportTest`, `HeatPumpAndPvTest`,
  `SeriesImportAndReferenceTest`, `WaterDeductionMeterTest`,
  `DemoDataTranslatorTest`; Basis `HttpServerTestCase` für HTTP-Tests.
- Neue Node-Suiten: `tests/plural.test.mjs`, `tests/hardcoded-text.test.mjs`,
  `tests/outbox.test.mjs`.
- Frontend-API-Shape 112/112, Browser-Render 218/218, Demo-Klicktest 46 Seiten
  ohne fehlende Antwort.
- 639 Testmethoden (+167). 57 Gegenproben, alle rot.

### Lessons Learned

- **Ein Schemaschritt für viele neue Töpfe** — leer anlegen, nichts umbauen;
  dann bleibt die Migration auch über acht Pakete hinweg trivial.
- **Messende Rollen gehören nicht in Summen.** Speicher und Wärmemenge einer
  Wärmepumpe messen etwas, das schon gezählt ist — wie ein Subzähler.
- **Die Doku-Beschreibung ist ein Code-Review.** Wer jede Antwort und jeden
  Fehlercode beschreiben muss, findet die Stellen, an denen der Code etwas
  anderes tut.
- **Erst die Lizenz der Daten, dann der Einbau.** Lastprofile und
  Spiegel-Tabellen blieben draußen; eigene Werte und Links tragen genauso.
- **Zeitreihen in UTC erzeugen** — ein Viertelstunden-Takt in Ortszeit
  überspringt die doppelte Stunde der Zeitumstellung.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

[#15]: https://github.com/Bingerminger/energietracker/issues/15
[#17]: https://github.com/Bingerminger/energietracker/issues/17

---

## [3.0.0] — 2026-10-07 — Frei zum Ausprobieren

MAJOR-Release ohne Bruch: Die Hauptversion markiert den **Lizenzwechsel zur
GNU AGPL v3.0 oder neuer**. Daten, Schnittstellen, CSV- und Backup-Format
bleiben unverändert, **kein Schema-Wechsel** (bleibt 1.6.0). Dazu kommt eine
**öffentliche Demo** auf GitHub Pages.

### ⚠️ Für bestehende Installationen

- **Lizenz:** Ab 3.0.0 gilt die [GNU AGPL v3.0 oder neuer](LICENSE). Für die
  eigene Installation ändert sich nichts — nutzen, ändern und weitergeben sind
  frei. Wer eine veränderte Fassung anderen als Netzdienst anbietet, muss auch
  deren Quellcode zugänglich machen. **Versionen bis 2.16.0 bleiben unter der
  MIT-Lizenz verfügbar**, unter der sie erschienen sind; alle Releases, Tags
  und Images bleiben bestehen.
- **Docker:** Wer `latest` zieht, bekommt ab jetzt die AGPL-Fassung; die Tags
  `2.16` und `2.16.0` bleiben beim letzten MIT-Stand.

### Added

- **Öffentliche Demo** unter <https://bingerminger.github.io/energietracker/>
  (Review MKT-22): die echte Oberfläche mit einem Beispielhaushalt, in allen
  sieben Sprachen (folgt dem Browser, umschaltbar), ohne Installation. Die
  Antworten berechnet der echte Server beim Bauen (`tools/build-demo.mjs`);
  `lib/demo-mode.js` beantwortet sie im Browser, Speichern ist abgeschaltet.
  Gebaut wird beim GitHub-Release und montags, damit die Daten bis heute
  reichen (`.github/workflows/pages.yml`).
- **Demo-Daten bis heute** (`DemoDataAligner`): „Mit Beispieldaten
  ausprobieren“ schreibt Zählerstände, Lieferungen und Temperaturen mit den
  Werten desselben Zeitraums im Vorjahr fort und legt die Termine relativ zu
  heute (einer ist fällig). Bis 2.16.0 endeten die Demo-Ablesungen im März
  2026.
- **Quellcode dieser Version:** Einstellungen → System verlinkt den Tag der
  laufenden Version (AGPL §13).
- **`CREDITS.md`** (mitgelieferte Bestandteile, Dienste, Datenquellen) und
  **`TRADEMARKS.md`** (genannte Produktnamen).

### Changed

- **Lizenz:** `LICENSE` (AGPL-Text, von GitHub erkannt), README-Abzeichen und
  -Abschnitt, `CONTRIBUTING` (Beiträge unter AGPL, ohne CLA), `composer.json`,
  Docker-Label `org.opencontainers.image.licenses`, Hinweis in der App in sieben
  Sprachen, FAQ „Was bedeutet die AGPL für mich?“.

### Fixed

- **Ein Test lief mit dem Kalender:** `TankModelTest` legte Tage relativ zu
  heute in ein jahreszeitliches Klima; seit dem 07.10.2026 lief der Tank darin
  leer, und die geprüfte Bilanz gilt nur für einen nicht leeren Tank. Der
  Ablauf ist jetzt kalenderfest, die Vorbedingung eine eigene Zusicherung.

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `LicenseConsistencyTest` (neu, +4): Lizenztext, `composer.json`,
  Docker-Label, README-Abzeichen samt MIT-Hinweis bis 2.16.0, CONTRIBUTING und
  die Systemseite in sieben Sprachen mit Link auf den Versions-Tag.
- `DemoDataAlignerTest` (neu, +8): nichts Neues am Exporttag; Stände wachsen
  bis heute mit dem Verbrauch des Vorjahreszeitraums, auch über mehrere Jahre
  und über einen Zählertausch; Temperaturen lückenlos, Januar wie im Vorjahr;
  genau ein fälliger Termin; Lieferungen einmal je Jahr; 29. Februar ohne
  Überlauf.
- `DemoServiceTest` (+1): der Import reicht bis heute.
- `tests/demo.test.mjs` (neu, CI): baut die Demo und klickt die echte App durch
  45 Seiten — keine fehlende Antwort, kein Fehler, Schreiben abgelehnt, CSV und
  PDF als Dateien.
- 472 Testmethoden. 14 Gegenproben, alle rot.

### Lessons Learned

- **Eine Demo ist nur so aktuell wie ihre Daten** — fortschreiben statt
  einfrieren.
- **Fortschreiben am letzten Stand, nicht am Stichtag des Schnappschusses** —
  sonst erfindet ein langes Intervall einen Vorjahresvergleich.
- **Tests mit Daten relativ zu heute laufen mit dem Kalender.**
- **Ein neuer Dateiname kann schon vergeben sein.**

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.16.0] — 2026-09-25 — Was die Rechnung weiß

MINOR-Release, zweiter Teil von Paket F des Gesamtreviews („Auswertungen, die
man sieht“, F1016): Die Diagramme zeigen, was die Rechnung längst wusste. Mit
diesem Release sind die Pakete A bis F umgesetzt. **Kein Schema-Wechsel**
(bleibt 1.6.0); die API wächst additiv um ein Feld, nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Die Übersicht zeigt kleine Vielfache.** Jede Karte trägt ihren eigenen
  Verlauf mit dem Vorjahr; darunter steht statt des gemeinsamen
  Linienverlaufs „Energie gesamt (kWh)“ als gestapelte Säulen — nur der
  Verbrauch in kWh, Wasser und PV stehen in ihren Karten.

### Added

- **Saldo-Verlauf (Review FE-31):** Unter den Zahlen der Saldo-Karte stehen die
  aufsummierten Kosten und das Bezahlte (Abschläge, Sonderzahlungen) über den
  Abrechnungszeitraum — gestrichelt, wo geschätzt ist, hohle Punkte nach heute.
  Der Abstand der Linien ist der Saldo, der letzte Punkt die erwartete
  Abrechnung; die Zahlen stehen als Tabelle darunter.
- **Gemessen oder witterungsbereinigt (Review FE-19):** Heizarten schalten im
  Monatschart um. Bereinigt wird nach dem Heizmodell auf ein Normaljahr am
  Standort (`heat_adjusted`); dann zeigt der Vergleich mit dem Vorjahr, ob
  gespart wurde, nicht ob der Winter mild war. Die Monatstabelle hat dafür die
  Spalte „bereinigt“ — bis v2.15 stand die Bereinigung nur im PDF.
- **Vorjahr im Monatschart (FE-31):** Neben jedem Monat steht derselbe Monat
  des Vorjahres als Umriss; die Kurzbeschreibung nennt dessen Summe. Der
  Tooltip nennt einen Zählertausch im Monat.
- **PV-Energiefluss (Review FE-17, FE-31):** Beide PV-Seiten zeigen je Monat,
  wohin der Sonnenstrom ging — selbst genutzt und eingespeist, gestapelt zur
  Erzeugung — und als Linie, was trotzdem aus dem Netz kam.
- **Kleine Vielfache auf der Übersicht (FE-17):** ein Mini-Verlauf je Karte
  über ihr Fenster, das Vorjahr als gepunktete Linie.
- **API (additiv):** `GET /api/utility/{u}/meters/{id}/contract-status` trägt
  am laufenden Vertrag `balance_path` — dieselbe Rechnung wie
  `projected_end_balance` als Monatsreihe (`ym`, `cost`, `paid`, `balance`,
  `estimated`, `future`); andere Verträge `null`.

### Changed

- **„Energie gesamt (kWh)“** ersetzt den Zwei-Achsen-Verlauf der Übersicht, in
  dem Strom plattgedrückt am Boden lag (Review FE-17).
- **Temperaturen:** Die Spanne vom Minimum zum Maximum ist eine gefüllte
  Fläche, das Mittel die Linie darin (FE-31).

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `BalancePathTest` (neu, +3): Der letzte Punkt der Reihe ist der erwartete
  Endsaldo; Abschläge wachsen nach Plan, ein gemessener Monat kostet
  Arbeitspreis plus Grundpreis, eine Rückzahlung vermindert das Bezahlte in
  ihrem Monat, nur der laufende Vertrag trägt eine Reihe.
- Frontend-API-Shape 62/62 (+1): `balance_path` endet beim erwarteten Endsaldo.
- Browser-Render 189/189 (+11): Saldo-Verlauf, Vorjahr links, Umschalter mit
  `heat_adjusted` statt des Altfelds, Spalte „bereinigt“ nur bei Heizarten,
  Energiefluss mit „erzeugt = selbst genutzt + eingespeist“, kleine Vielfache,
  „Energie gesamt“ ohne Wasser und PV, Temperaturband.
- 459 Testmethoden. 14 Gegenproben, alle rot.

### Lessons Learned

- **Ein Diagramm rechnet wie die Zahl daneben:** Der Saldo-Verlauf entsteht
  im Backend in derselben Funktion wie der erwartete Saldo.
- **Eine Summe nur über vergleichbare Monate** — sonst ergeben die Teile das
  Ganze nicht.
- **Bei Chart.js ordnet `order` auch die Balken.**

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.15.0] — 2026-09-25 — Diagramme, die stimmen

MINOR-Release, erster Teil von Paket F des Gesamtreviews („Auswertungen, die
man sieht“, F1016): Die Diagramme zeigen, was die Daten hergeben — und nicht
mehr. **Kein Schema-Wechsel** (bleibt 1.6.0), **keine Änderung an der API**.

### ⚠️ Für bestehende Installationen

- **Trendpfeile rechnen anders.** Das Banner einer Verbrauchsart und die Karten
  der Übersicht vergleichen jetzt dieselben vollen Monate ein Jahr zuvor. Das
  Banner rechnet bei Heizarten witterungsbereinigt nach dem Heizmodell
  (`heat_adjusted`), wenn für alle beteiligten Monate ein Wert vorliegt; die
  Karten vergleichen gemessene Werte. Bis v2.14 standen die letzten drei Monate
  gegen die drei davor bzw. zwölf gegen zwölf mit einem halben Monat am Ende —
  die Prozentzahlen ändern sich deshalb, teils deutlich. Ohne Vorjahresmonate
  steht kein Pfeil.
- **Chart.js 4.5.1** statt 4.4.1 (unverändert aus dem npm-Paket, Integrität
  gegen die Registry geprüft).

### Added

- **Teilmonate kenntlich (Review FE-08):** Der erste Monat nach dem Einbau, der
  laufende oder ein Monat bis zur letzten Ablesung steht blass (Balken) bzw. als
  hohler Punkt am Ende einer gestrichelten Linie da; der Tooltip sagt
  „Teilmonat: 14 von 31 Tagen“, die Monatstabelle „14 / 31“ mit Legende. Das
  Saisonprofil mittelt nur volle Monate.
- **Zeitraum je Karte (FE-08):** Jede Karte der Übersicht nennt ihr Fenster
  („Zeitraum: Apr. 2025 – März 2026 · März 2026: 14 von 31 Tagen“) — die
  Verbrauchsarten enden in verschiedenen Monaten.
- **Daten als Tabelle (Review FE-20):** unter dem Verlauf der Übersicht, den
  Temperaturen, der Prognose, der Heizsignatur, dem Saisonprofil und dem
  Jahresvergleich, zum Aufklappen (am iPhone 44 px hoch).
- **Kurzbeschreibungen mit Kernaussage (FE-20):** „Balkendiagramm: Gas 2026 je
  Monat, zusammen 1.630 kWh; am meisten im Jan. 2026 (692 kWh), am wenigsten
  im März 2026 (313 kWh).“ Prognose und Temperaturen nennen ihren Zeitraum,
  die Übersicht ihre Verbrauchsarten.
- **Jahr und Zähler in der Adresse (Review FE-22, FE-29, UI-28):**
  `#/utility/gas?year=2025` (bei mehreren Zählern mit `&meter=…`) öffnet genau
  diese Auswahl. Jede Verbrauchsart merkt sich ihr Jahr — bis v2.14 öffneten
  Heizöl und PV nach einem Besuch bei der Fernwärme im Jahr 2025, obwohl es 2026
  gab. Jahre ergeben sich auch aus Ablesungen und Lieferungen; die aktive
  Jahres-Pille trägt `aria-pressed`.

### Changed

- **Farben folgen dem Theme (Review FE-11):** Offene Diagramme färben sich beim
  Umschalten sofort um — Datensätze, Achsen, Gitter. Im hellen Theme sind die
  Farben der Verbrauchsarten abgedunkelt wie im Rest der Oberfläche: Strom
  stand bis v2.14 mit 1,8:1 auf Weiß, jetzt über 4,5:1. Dasselbe gilt für die
  Farben der Modelle, Jahre und Angebote.
- **Jahresvergleich:** Linienstil und Punktform je Jahr, das jüngste Jahr
  kräftiger; bis v2.14 unterschieden sich die Jahre nur in der Farbe (FE-20).
- **Übersicht:** Die m³-Achse erscheint nur, wenn es Wasser gibt (FE-17).
- **Temperaturen:** Die Reihen heißen „Maximum“, „Mittel“ und „Minimum“ in der
  Sprache der Oberfläche; bis v2.14 fest „Max“, „ø“, „Min“.
- **Anomalien:** Ohne Erwartung steht „–“ statt „+0,0 %“ (FE-08).

### Fixed

- **Prognose (Review FE-16):** Zwei schnelle Klicks auf „Aktualisieren“ warfen
  „Canvas is already in use …“ roh und englisch in einen Toast; jetzt zeichnet
  nur der zuletzt gestartete Lauf, und ein Fehler von Chart.js landet in der
  Konsole statt in der Oberfläche.
- **Tarifvergleich (FE-16):** Jeder Wechsel des Wechseltermins hängte ein
  Diagramm ab, das im Speicher blieb.
- **Analyse (FE-20):** Die Kurzbeschreibungen von Jahresvergleich und
  Saisonprofil waren vertauscht — Screenreader hörten „Balkendiagramm“ zu einer
  Linie.
- Übersicht und Temperaturen hielten ihre Diagramme in `window`-Globals; alle
  Diagramme laufen jetzt über eine Registry, die beim Seitenwechsel aufräumt.

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `tests/chart.test.mjs` (neu, 30 Prüfungen, auch in der CI): ein Chart je
  Canvas, Aufräumen beim Seitenwechsel, Neuzeichnen beim Theme-Wechsel samt
  Achsenfarben, kein Wurf bei einem Chart.js-Fehler, jede Chartfarbe mit 3:1 auf
  der Karte in beiden Themes; Teilmonat, Trend gegen das Vorjahr, bereinigt nur
  mit Werten für alle Monate.
- Browser-Render 178/178 (+21): Der Test lädt jetzt die echte Chart-Schicht —
  bis v2.14 ersetzte der Test-Loader sie durch einen Stub — und prüft am
  aufgezeichneten Diagramm Farben, Teilmonat, Tooltip, Kurzbeschreibung,
  Datentabellen, Jahr aus der Adresse und die Prognose nach zwei schnellen
  Läufen.
- `ReleaseConsistencyTest` (+1): Die Architektur-Doku nennt die mitgelieferte
  Chart.js-Version.
- 456 Testmethoden. 22 Gegenproben, alle rot.

### Lessons Learned

- **Ein Update liest die Vorgaben nicht neu:** Chart.js kopiert
  Achsen-Vorgaben beim Anlegen; beim Theme-Wechsel blieben die Achsen alt.
- **Ein ersetztes Modul ist ein ungetestetes Modul:** Der Render-Test lud einen
  Stub statt der Chart-Schicht.
- **Ein Trend braucht denselben Zeitraum** — drei Monate gegen drei maßen die
  Jahreszeit.
- **Eine Beschreibung, die niemand sieht, prüft niemand** — die vertauschten
  Kurzbeschreibungen fielen jahrelang nicht auf.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.14.0] — 2026-09-25 — Nachlesbar

MINOR-Release, zweiter Teil von Paket E des Gesamtreviews („Versteht sich von
selbst“): die Dokumentation, geordnet nach dem, was man vorhat. **Kein
Schema-Wechsel** (bleibt 1.6.0), **keine Änderung an der API**.

### ⚠️ Für bestehende Installationen

- **Die Doku ist umgezogen.** `docs/` gliedert sich in Einstieg, Anleitungen,
  Verstehen, Referenz, Betrieb und Entwicklung; die englische Fassung liegt
  unter `docs/en/` mit denselben Dateinamen. Alle 61 alten Pfade — etwa
  `docs/HOME-ASSISTANT.md` oder `docs/technical/03-api-reference.md` — bleiben
  als Weiterleitung stehen: Lesezeichen, Links aus Foren und Issues und die
  Hilfe älterer Versionen führen weiter ans Ziel.
- **`scripts/init_data.py` ist veraltet** und entfällt mit v3.0.0. Es kennt nur
  Gas und Strom und schreibt fest nach `./data`, also nicht in einen Container.
  Stattdessen den CSV-Import an der Zählerkarte nutzen (⚙️ Zähler →
  CSV-Import). `--help` zeigt jetzt die Beschreibung — bis v2.13 startete auch
  dieser Aufruf den Import, und die Installationsanleitung empfahl genau ihn.

### Added

- **Neue Seiten, Deutsch und Englisch (Review DOC-09, DOC-16, DOC-20):**
  - *Was der Energietracker kann* — alle Funktionen auf einer Seite, nach
    Fragen geordnet.
  - *Fragen und Antworten* — 20 Fragen, von „Brauche ich Home Assistant oder
    einen Smart Meter?“ bis „Welche Daten verlassen meinen Server?“.
  - *Auf dem Handy nutzen* — im Heimnetz aufrufen, als App auf den
    Home-Bildschirm legen, schnell ablesen, was ohne Netz geht.
  - *Jahresabrechnung eintragen und prüfen* — Schritt für Schritt an einer
    erfundenen Beispielrechnung: Stände, Gasfaktoren, Vertrag, Nachrechnen,
    Saldo vergleichen, Guthaben buchen.
  - *Fehlersuche* — Symptom, Ursache, Abhilfe für Start, Speichern, Rechnen
    und Home Assistant, darunter Docker Desktop mit `mounts denied`.
  - *Webserver: Apache und nginx* — eine Fassung nach den getesteten Regeln
    des Docker-Images und der `.htaccess` statt dreier auseinanderlaufender.
  - *Einstellungen — alle Schlüssel* — alle 60 Schlüssel mit Ort in der
    Oberfläche, Standard und Wirkung, die veralteten gekennzeichnet.
- **Hilfe:** Links zu „Häufige Fragen“ und „Fehlersuche“, in allen sieben
  Sprachen beschriftet.
- **Englische Screenshots (Review DOC-13, DOC-19):** 17 Aufnahmen der
  englischen Oberfläche für die englische Ansichtsreferenz und Anleitung —
  bisher zeigten beide die deutsche.
- **Mitwirken (Review DOC-23):** `CONTRIBUTING.md` / `CONTRIBUTING.de.md` mit
  Einrichtung, Tests, Grundsätzen, Doku-Regeln und dem Weg zu einer neuen
  Sprache; Vorlagen für Fehlermeldungen und Wünsche auf GitHub (Version,
  Betriebsart, Diagnose, Hinweis auf das öffentliche Repository).

### Changed

- **README als Schaufenster (Review DOC-06, DOC-07, MKT-01, MKT-23):** was die
  App beantwortet, für wen, was sie neben dem Energie-Dashboard von Home
  Assistant kann, Schnellstart, was mit den Daten geschieht, Hilfe, Länder,
  Ausblick — in 140 statt rund 600 Zeilen; die Einzelheiten stehen in der Doku.
  INSTALL ist entsprechend kürzer.
- **Kompendium-Index** nach Zielgruppen, mit Einstieg für Neue; die Roadmap
  beginnt mit einer Kurzfassung (jetzt, als Nächstes, bewusst nicht).
- **Datenfluss & Algorithmen** ersetzt `docs/ARCHITECTURE.md` (Stand v1.4.4,
  Review DOC-12): Was die Architektur-Seite schon beschreibt, ist entfallen;
  geblieben sind der Weg vom Stand zum Saldo, die Prognose, der erste Start und
  das lokale Ausprobieren. Die API-Beispiele verweisen auf die Referenz als
  maßgebliche Quelle.
- **Englisch einheitlich (Review DOC-19):** „unit price“ und „standing charge“
  in Oberfläche und Doku, statt „working price“, „energy price“, „base price“
  und „base charge“ für dieselben zwei Dinge.
- **Pluralformen:** Die Zählerübersicht sagt „1 Gruppe“ statt „1 Gruppe(n)“
  (englisch „1 meter“ statt „1 meters“), die Meldung zurückgestellter Stände
  „1 Stand“ statt „Stand/Stände“ — in allen sieben Sprachen.
- Anleitungen nennen Menüpunkte so, wie die App sie heute nennt
  („Auswertungen → Analyse“, „Zu Gruppe zusammenfassen“).

### Removed

- Die SVG-Entwürfe unter `docs/screenshots/` (Review DOC-12) — seit v1.9.3
  durch echte Aufnahmen ersetzt und nirgends mehr verlinkt.

### Fixed

- **Doku-Index (Review DOC-11):** „Alle 68 Endpunkte“ — es sind 86; die
  Referenz selbst war vollständig, nur der Index nicht.
- **Englische Hilfe:** Der Hinweis zur Diagnose war missverständlich
  formuliert.

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `DocsIntegrityTest` (neu, +6, Review DOC-11): jeder relative Link und jedes
  Bild in exakter Schreibweise, jeder Anker, der englische Spiegel jeder Seite,
  keine Links auf Weiterleitungen, der Index vollständig, die
  Einstellungsreferenz gegen `SettingsService::DEFAULTS` und jeder Doku-Link
  der App gegen echte Seiten.
- `LocaleCatalogTest` (+1): Jeder Schlüssel, den der Code an `tp()`
  übergibt, hat in jeder Sprache `one` und `other` — die Literalprüfung sah
  nur `t()`; dazu die Zusatzkategorien einzelner Sprachen.
  `ReleaseConsistencyTest` und `ha-snippet.test.mjs` auf die neuen Pfade.
- Browser-Render 157/157 (+1): Die Hilfe verlinkt sechs echte Seiten,
  darunter FAQ und Fehlersuche.
- 455 Testmethoden. 14 Gegenproben, alle rot.

### Lessons Learned

- **Eine Doku ohne Test verrottet** — der Index versprach 68 Endpunkte, als es
  86 waren. Ein Linkprüfer muss Groß und klein unterscheiden, auch wenn der
  Mac es nicht tut.
- **Umziehen heißt weiterleiten** — auf alte Pfade zeigen Leute, die man nicht
  erreicht.
- **Ein dokumentierter Befehl muss einmal gelaufen sein** — `--help` startete
  einen Import.
- **Eine Übersetzung braucht ein Glossar** — vier Wörter für zwei Begriffe.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.13.0] — 2026-09-25 — Erklärt

MINOR-Release, erster Teil von Paket E des Gesamtreviews („Versteht sich von
selbst“): die App erklärt sich selbst. **Kein Schema-Wechsel** (bleibt 1.6.0).
Die API wächst additiv — drei Felder an den Verbrauchsarten, eins an der
Ablese-Übersicht, ein Einstellungsschlüssel —, nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Der Saldo steht in Kundensicht.** Karten und Kacheln sagen „Guthaben“
  (grün) oder „Nachzahlung“ (rot) ohne Vorzeichen; in Tabellen heißt
  **+ Guthaben** und **− Nachzahlung**, mit einer Legende darunter. Bis v2.12
  stand dort die Buchhaltungssicht (Kosten − Abschläge, Minus = Guthaben).
  API und CSV-Export behalten ihr Vorzeichen — Integrationen sind nicht
  betroffen.
- **Abrechnungsstichtage:** Heizöl und Pellets entfallen in der Oberfläche —
  sie haben keine Abschläge und damit keinen Stichtag; die Felder wirkten
  nicht. Neu ist der Stichtag der **PV-Einspeisung**. Die Schlüssel bleiben in
  der API (siehe Deprecated).
- **PV-Einspeisung:** Die Vertragstabelle zeigt „Vergütung“ und „Erhalten“
  statt „Verbraucht“ und „Bezahlt“, die Monatstabelle „Erlös“ statt „Kosten“.
  Vermiedenes CO₂ steht ohne Minus da.

### Added

- **Hilfe (`#/help`)** in der Fußzeile der Seitenleiste, am iPhone unter
  „Mehr“: Erste Schritte mit Häkchen aus den Daten, Dokumentation in der
  passenden Sprache (Deutsch oder Englisch, mit Hinweis), Fragen und Fehler
  (GitHub, Diagnose), Deine Daten, und ein **Glossar mit 33 Begriffen** in
  allen sieben Sprachen, durchsuchbar, mit Sprung `#/help?term=…`.
- **ⓘ-Erklärungen zum Antippen (Review DOC-10, UI-17):** an Heizgradtagen,
  R², Modellen, gleitenden Mitteln, Saldo, Zäsur, Gewinnschwelle,
  Kündigungsstichtag, Wechseltermin, Sonderzahlungen, Effizienz, Autarkie,
  vermiedenem CO₂ und weiteren Kennzahlen. Am Mac als Blase, am iPhone als
  Blatt über der Tab-Leiste; Escape oder ein Tipp daneben schließt. Dieselben
  Texte wie im Glossar. Erklärungen je Zeile — warum ein Stand „PRÜFEN“ oder
  „UNPLAUSIBEL“ trägt, was „VOLL“ bei einer Lieferung heißt, worauf sich die
  energieausweis-nahe Kennzahl bezieht — öffnet das ⓘ daneben.
- **Willkommen (Review UI-32, DOC-31):** Ohne Daten zeigt die Übersicht, was
  die App tut, die ersten Schritte und „Mit Beispieldaten ausprobieren“
  (vorher sichert der Server den jetzigen Stand).
- **Leerzustände (Review UI-27):** Eine Verbrauchsart ohne Monatswerte sagt,
  warum (kein oder erst ein Stand), und führt zur Erfassung dieses Zählers.
  Empfehlungen sagen bei zu wenig Daten genau das statt „alles im grünen
  Bereich“.
- **Einstellungen:** Hinweise an 18 weiteren Feldern; **Breite des
  Prognosebands** (`confidence_band_sigma`, wirkt seit v2.8.0, bisher nur per
  API) unter Experte; Prognosemodell und Split-Modus mit Namen statt
  Schlüsseln; Stichtag **PV-Einspeisung** (`billing_cycle_anchor_pv_einspeisung`,
  Default `01-01`; bis v2.12 galt fest der Jahresbeginn); die
  Home-Assistant-Anleitung als Link in der passenden Sprache statt als
  Dateipfad.
- **Quellenangabe der Wetterdaten (Review DOC-22):** „Wetterdaten von
  Open-Meteo.com (CC BY 4.0)“ unter den Temperaturen und im PDF-Jahresbericht,
  sobald er Temperaturen zeigt — die Lizenz verlangt es.
- **API (additiv):**
  - `GET /api/utilities` trägt je Verbrauchsart `has_contracts`,
    `has_advance_payment_contracts` und `accounting_kind` (`consumption`,
    `feed_in`, `generation`) — die Oberfläche fragt die Eigenschaften ab,
    statt Listen zu pflegen (Review FE-14).
  - `GET /api/readings-overview` trägt je Zähler `reading_count`.

### Changed

- **PV durchgehend als Einspeisung und Erzeugung (Review FE-06, UI-18,
  UI-19):**
  - Übersicht: „Einspeisung“ und „Vergütung“, die Erzeugung ohne
    Kostenkachel; mehr ist bei beiden gut (grüner Pfeil). Der gemeinsame
    Verlauf zeigt nur noch Bezug — PV-Kilowattstunden standen dort als
    Verbrauch.
  - Verbrauchsart: Untertitel und Diagrammtitel je Art; Einspeisung mit Erlös,
    offenem Anspruch in Grün und Legende „+ Guthaben, − Rückforderung“;
    beide PV-Arten ohne Temperatur und Heizgradtage.
  - Analyse: Anomalien und Vorjahresvergleich werten weniger Einspeisung als
    Rückgang, nicht als Ersparnis.
- **Erklärungen sichtbar statt im Tooltip (Review UI-27):** Sonderzahlungen
  zum Aufklappen, Spaltenerklärungen des Tarifvergleichs unter den Tabellen,
  Grundpreis ausgeschrieben („Grundpreis 12,95 €/Monat“ statt „13 € GP“),
  „HGT“ in der Sprache der Oberfläche (HDD, DJU, GG, GD).
- **Prognose:** Modellname übersetzt, Saldo-Spalte in Kundensicht mit ⓘ,
  Modell und Horizont aus den Einstellungen vorbelegt.
- **Verträge & Abschläge:** ohne Heizöl und Pellets — der Link „Vertrag
  anlegen“ führte auf eine Seite ohne Verträge.
- Am iPhone ist auch die Fußzeile der Seitenleiste (Hilfe, GitHub) mit 44 px
  antippbar.

### Deprecated

- `billing_cycle_anchor_heizoel` und `billing_cycle_anchor_pellets` — ohne
  Wirkung, werden weiter geliefert und angenommen, entfallen mit v3.0.0.

### Fixed

- **PV-Einspeisung:** Ein offener Vergütungsanspruch stand rot wie eine
  Nachzahlung; die Erlöskachel war nicht eingefärbt.
- **Tankwarnung:** Übersicht und Verbrauchsart färbten den Füllstand mit
  festen 8 und 15 %; die Einstellung `tank_warn_pct` wirkte nur auf die
  Empfehlungen. Jetzt gilt sie überall (Warnung ab dem Wert, Alarm ab der
  Hälfte).

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `LocaleCatalogTest` (+1): zusammengesetzte Schlüssel — Glossar-Einträge und
  Beschriftungen der Einstellungsfelder in allen Sprachen, keine verwaisten
  Glossar-Einträge.
- `PdfReportServiceTest` (+1): Quellenangabe nur, wenn Temperaturen im Bericht
  stehen. `TotalsAndSettingsRulesTest` (+1): Stichtag PV-Einspeisung.
  `ReadingOverviewUnitsTest` (+1): `reading_count`.
- Browser-Render 156/156 (+32): Hilfe mit Glossar, Suche und Sprung; ⓘ öffnen
  und mit Escape schließen; Willkommen nur ohne Daten; PV-Semantik in
  Übersicht, Monats- und Vertragstabelle; Saldo in Kundensicht; Quellenangabe;
  Einstellungen (Modellnamen, Prognoseband, Stichtage, HA-Link).
- Frontend-API-Shape 61/61: `has_contracts`, `has_advance_payment_contracts`,
  `accounting_kind`, `reading_count`.
- 448 Testmethoden. 26 Gegenproben, alle rot.

### Lessons Learned

- **Ein lokaler Name überschattet einen Import:** `const info = …` in der
  Prognose machte den neuen ⓘ-Helfer `info()` unerreichbar; die Tabelle blieb
  leer, ohne dass die Seite abstürzte. Ein bestehender Render-Test fiel.
- **Ein Tooltip ist keine Erklärung** — auf dem iPhone gibt es ihn nicht.
- **Ein Vorzeichen ist eine Perspektive:** Rechnung und API behalten die
  Buchhaltungssicht, die Oberfläche spricht in Worten.
- **Zusammengesetzte Schlüssel sieht keine Literalprüfung** — sie brauchen
  eine eigene.

---

## [2.12.0] — 2026-09-25 — Aufgeräumt

MINOR-Release, zweiter Teil von Paket D des Gesamtreviews („Mobil und
aufgeräumt"). **Kein Schema-Wechsel** (bleibt 1.6.0). Die API wächst additiv —
zwei Routen, ein Feld, eine Option —, nichts entfällt.

### ⚠️ Für bestehende Installationen

- **Die Einstellungen sind Unterseiten:** Allgemein · Haushalt & Gebäude ·
  Verbrauchsarten & Abrechnung · Wetterdaten · Daten · Integrationen ·
  Zugriff · Experte · System.
  - Gespeichert wird je Seite über die Leiste unten; sie erscheint erst bei
    einer Änderung. Wer mit ungespeicherten Änderungen die Seite verlässt,
    wird gefragt.
  - Die Stellschrauben der Modelle (Regression, Prognosemodell, Schwellen der
    Empfehlungen) stehen eingeklappt unter **Experte**.
  - Standort und Wetter stehen nur noch unter **Wetterdaten**.
- **Zählerstand-Erfassung:** Das Datum je Karte ist eingeklappt
  („Anderes Datum"); sonst gilt das Datum oben.
- **CSV-Import von Ablesungen:** erst die Vorschau, dann „Importieren". Die
  Dateiauswahl allein schreibt nichts mehr.

### Added

- **Einstellungen als Unterseiten (Review UI-13)** mit Speicherleiste,
  „Verwerfen" und Rückfrage beim Verlassen. Alle sechs Abrechnungsstichtage
  stehen in einer Karte.
- **Wetterdaten (Review UI-30):**
  - Ortssuche über das Open-Meteo-Geocoding: `GET /api/geocode?q=…`.
  - Der Standort speichert beim Ändern; der tägliche Abgleich ist ein
    Schalter.
  - Das CSV-Beispiel nutzt das übliche Format `TT.MM.JJJJ;Mittel;Min;Max`;
    Tabulator und das alte Format mit Anführungszeichen bleiben lesbar.
- **„Zu tun" auf der Übersicht (Review UI-21):** fällige und überfällige
  Termine, Zähler ohne Ablesung seit `alert_days_since_reading` Tagen,
  Kündigungsfristen und Tanks — jeweils mit Sprung dorthin. Ab drei fälligen
  Zählern steht eine Sammelzeile da; jede Zeile ist als Ganzes antippbar. Die
  Terminkarte darunter entfällt.
- **Zählerstand-Erfassung (Review UI-15, UI-33):**
  - Direktsprung je Zähler `#/zaehlerstaende?meter=<id>` — für Lesezeichen
    auf dem Home-Bildschirm, Kurzbefehle und „Zu tun".
  - Weiter-Taste: Enter springt ins nächste Zählerfeld, beim letzten auf
    „Alle speichern".
  - Nach dem Speichern steht da, was sich geändert hat („Gas +5,4 m³"), mit
    „Rückgängig" für 10 Sekunden.
  - Fehler stehen am Feld statt nur im Titel des ✗.
- **„Rückgängig" (Review UI-22)** für „Termin erledigt" und „Empfehlung
  ausblenden", jeweils 10 Sekunden:
  - `DELETE /api/recommendations/{id}/dismiss` blendet wieder ein.
  - `PATCH /api/reminders/{id}` nimmt `last_done` an (Datum oder `null`).
- **CSV-Import mit Vorschau (Review UI-23):** `POST
  /api/utility/{u}/meters/{id}/readings/import-csv?dry_run=1` liest, ohne zu
  schreiben. Die Vorschau zeigt jede Zeile mit ihrer Wirkung (neu, ersetzt,
  unverändert) und den Rückfragen der Erfassung: Rückgang, Sprung,
  Größenordnung, Zukunft, doppeltes Datum.
- **Tarifvergleich:** Die Antwort trägt `years`, die Jahre mit Daten.

### Changed

- **Einheitliche Seiten (Review UI-20):**
  - Termine, Empfehlungen und Tarifvergleich hatten eine 32-px-Überschrift,
    jetzt 20 px wie alle anderen Seiten.
  - Die Auswahlfelder im Tarifvergleich sind gestaltet; die Termine stehen in
    einer Karte.
  - Pluralformen: „1 Arbeitspreis" und „Alle 48 Monate" statt
    „1 Arbeitspreise" und „Alle N Monate (48)". Die „(en)"-Hilfsformen
    entfallen.
  - Monate im Tarifvergleich in der Schreibweise der Sprache („Jan. 2027"
    statt „2027-01").
  - Löschen-Knöpfe auf Karten haben eine Umrandung statt einer roten
    Vollfläche.
- **Rückfragen nennen das Objekt (Review UI-22):** Vertrag, Angebot und Termin
  beim Löschen; die Vorlesetexte der Tabellenknöpfe nennen den Termin. Fehler
  im Termin- und im Gruppen-Dialog stehen am Feld statt im Toast.
- **Benennung (Review UI-31):**
  - „Zu Gruppe zusammenfassen" statt „Zähler zusammenführen" — die Zähler
    bleiben getrennt, die Gruppe zeigt die Summe.
  - „Angebot erfassen" statt „Schattenvertrag anlegen".
  - Der Rückblick bietet nur Jahre mit Daten an.
- **Vertragsformular (Review UI-16):** Die ersten Preiszeilen gelten ab
  Vertragsbeginn und folgen ihm, bis jemand ihr Datum ändert. Eine Zeile mit
  vorbelegtem Datum und ohne Betrag bleibt leer und blockiert das Speichern
  nicht. „⇧ Start" heißt „Ab Beginn" und erklärt sich im Tooltip.
- **Übersicht:** Die Kachel „Aktive Zähler" je Verbrauchsart entfällt;
  Kennzahl-Kacheln heben sich nicht mehr beim Überfahren (sie sind nicht
  klickbar).
- **Ablesungs-Import:** versteht Leerzeichen und Apostroph als
  Tausendertrenner („1 395,2", „1'395.2").

### Fixed

- **Temperatur-CSV mit Dezimalkomma:** `01.01.2024;4,2;-1,0;7,1` (Excel
  deutsch) wurde auch am Komma getrennt — Mittel 4, Min 2, Max −1. Jetzt gilt
  ein Trenner je Zeile.
- **Tarifvergleich:** Ein Jahr ohne Zeilen blendete den ganzen Rückblick samt
  Jahresauswahl aus; man kam nicht mehr zurück.

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `UndoAndPreviewTest` (8): Rückgängig für Termine und Empfehlungen,
  Trockenlauf ohne Schreibzugriff, Tausendertrenner, Jahre im Rückblick.
- `TemperatureSyncTest` (+2): Ortssuche; CSV mit Semikolon, Tabulator und dem
  alten Format.
- Browser-Render (124): Einstellungsseiten, „Zu tun" mit Sammelzeile, Erfassung (Datum,
  Weiter-Taste, Feldfehler, Direktsprung), Feldfehler im Termin-Dialog,
  Seitenköpfe, Pluralformen, Vertragsbeginn in der ersten Preiszeile.
- Frontend-API-Shape 59/59: Trockenlauf, Wieder-Einblenden, `years`.
- 444 Testmethoden. 27 Gegenproben, alle rot.

### Lessons Learned

- **Ein Trenner je Zeile:** `[;,]` als Trenner trifft in Ländern mit
  Dezimalkomma die Zahl selbst. Den Trenner an der Zeile erkennen, nicht als
  Zeichenklasse raten.
- **Vorbelegt ist nicht eingegeben:** Ein Datum, das die App vorschlägt, darf
  eine leere Zeile nicht zur halb ausgefüllten machen — sonst blockiert der
  Vorschlag das Speichern. Die Vorbelegung trägt eine Markierung, bis jemand
  sie ändert.
- **Rückgängig braucht einen Rückweg in der API:** Der Toast ist schnell
  gebaut; ohne `last_done` im PATCH und ein DELETE für das Ausblenden hätte er
  nichts zurückzunehmen.

---

## [2.11.0] — 2026-09-25 — Mobil

MINOR-Release, erster Teil von Paket D des Gesamtreviews („Mobil und
aufgeräumt"). **Kein Schema-Wechsel** (bleibt 1.6.0). Die API bleibt, wie sie
ist; neu ist nur die Option `inline=1` am Jahresbericht.

### ⚠️ Für bestehende Installationen

- **Das Menü ist neu geordnet** — sieben Bereiche statt 17 Einträgen. Wo
  steht was jetzt?
  - Verträge, Tarifvergleich („Wechsel prüfen") und Rechnungsprüfung: unter
    **Kosten & Verträge**
  - Korrelation („Analyse"), Prognose und PDF-Jahresbericht: unter
    **Auswertungen**
  - Empfehlungen und Termine: unter **Hinweise**
  - Temperaturen („Wetterdaten"): unter **Einstellungen**

  Gespeicherte Adressen (`#/tariffs`, `#/temperatures` …) funktionieren
  weiter.
- **Darstellung in drei Stufen:** System (Standard), hell, dunkel. Wer nie
  umgeschaltet hat, folgt wie bisher dem System.

### Added

- **Navigation nach Nutzerfragen (Review UI-12):** Übersicht · Zählerstände ·
  Verbrauch · Kosten & Verträge · Auswertungen · Hinweise · Einstellungen.
  - Bereiche mit mehreren Seiten zeigen diese als Tabs über der Ansicht.
  - Menüname und Seitentitel stimmen überein.
  - Der Tab bzw. das Fenster nennt die Ansicht („Prognose · Energietracker").
- **iPhone (Review UI-05):**
  - Tab-Leiste unten mit Übersicht, Verbrauch, ＋ Erfassen, Kosten und Mehr.
  - „Mehr" öffnet das Menü als Schublade; die Verbrauchsarten stehen als Tabs
    über der Ansicht.
  - Bis v2.10 stand das ganze Menü als Block über jedem Inhalt — auf dem
    iPhone SE drei Viertel des Bildschirms.
- **Erfassen-Blatt:** Zählerstände, Lieferung und Peilstand (Heizöl,
  Pellets), Termin. Am Mac als „＋ Erfassen" in der Kopfleiste.
- **Neue Seiten:**
  - **Verträge & Abschläge:** alle laufenden Verträge mit Kündigungsfrist,
    Abschlag und dem Ergebnis, das zur Abrechnung zu erwarten ist.
  - **Rechnung prüfen:** bisher nur am Ende der Gas-Seite; die Gas-Seite
    verweist jetzt dorthin.
  - **Jahresbericht:** bisher in den Einstellungen, jetzt mit „Im Browser
    öffnen" für die Home-Bildschirm-App.
- `GET /api/reports/yearly.pdf?inline=1` zeigt das PDF im Browser; ohne die
  Option bleibt es ein Download.
- **Zurück-Taste und Wischgeste** schließen offene Dialoge und das Menü, statt
  die Seite darunter zu verlassen.
- **Home-Bildschirm-App (PWA):**
  - `viewport-fit=cover` mit Abständen für Uhr, Dynamic Island und
    Home-Balken.
  - Statusleiste `default`: Die weiße Schrift der durchsichtigen Leiste stand
    im Hellmodus auf weißem Grund.
  - `theme-color` folgt der Darstellung.
  - Maskierbares Icon, Kurzbefehle „Zählerstände erfassen" und „Verträge".

### Changed

- **Router (Review FE-05):** Jede Navigation bekommt einen eigenen Container,
  ein Token und ein Abbruchsignal. Bis v2.10 geschah Folgendes:
  - Eine langsame Antwort überschrieb die schon geöffnete nächste Seite
    („Übersicht" über „Strom").
  - Der Cleanup der verlassenen Ansicht ging verloren; ihr Diagramm lief
    weiter.
  - Leseanfragen der verlassenen Ansicht liefen weiter; jetzt werden sie
    abgebrochen.

  Den Zählerwechsel in der Verbrauchsansicht schützt ein eigener Zähler.
- **Laden (Review FE-26):** Ansichten laden erst beim Öffnen und danach im
  Leerlauf. Offline ist die App damit weiter vollständig. Die Übersicht lädt
  die Tanks parallel zum Rest statt einzeln danach.
- **Zeitlimit (Review FE-25):** 30 s für Lesen, 120 s für Schreiben, 5 min für
  Import und Wetterabgleich, jeweils mit übersetzter Meldung. Scheitert eine
  Ansicht, bietet sie „Erneut versuchen" an.
- **Einstellungen (Review FE-12, UI-24):**
  - Speichern hält den Zwischenspeicher aktuell; die Seitenleiste folgt einer
    geänderten Auswahl der Verbrauchsarten sofort.
  - Nach Demo-Daten, Import oder Wiederherstellung startet die App neu und
    meldet danach den Erfolg.
- **Kontraste nach WCAG AA (Review UI-09):**
  - Primärknöpfe: im Hellmodus weiße Schrift (5,2:1 statt 3,8:1).
  - Statusfarben im Hellmodus: Grün 5,5:1, Orange 5,6:1, Rot 6,5:1.
  - Die Farbe jeder Verbrauchsart wird je Theme so weit getönt, bis sie als
    Schrift 4,5:1 erreicht.
  - Schrift auf farbigen Knöpfen und der aktiven Jahres-Pille: die mit dem
    besseren Kontrast. Bis v2.10 stand weiße Schrift auf Gas-Orange (2,2:1).
  - Knöpfe ohne Farbverlauf.
  - Löschen-Knöpfe 4,8:1, Gruppenlabels 11 px statt 9 px.
- **Bedienung am iPhone (Review UI-10, UI-16):**
  - Tippziele mindestens 44 px, Eingaben 16 px (Safari zoomt nicht mehr bei
    jedem Fokus), Grundschrift 16 px.
  - Dialoge erscheinen als Blatt von unten; Kopf- und Fußleiste stehen fest.
  - Das „×" einer Formularzeile hat eine Umrandung statt einer roten
    Vollfläche.
- **Kopieren (Review FE-24):** Token, YAML und Jahresverbrauch lassen sich
  auch beim Betrieb über `http://` kopieren. Bisher kam dort immer
  „Zwischenablage nicht verfügbar".
- **Barrierefreiheit (Review UI-26):**
  - Screenreader lesen die Symbole der Navigation nicht mehr vor.
  - Die Überschriften der Einstellungen springen nicht mehr von H1 auf H3.
  - Die „alle"-Links der Übersicht stehen neben der Überschrift statt darin.
  - Empfehlungen zeigen „30 Tage ausblenden" als Text statt eines „✕".
- **Übersicht:**
  - „Temperaturen" als Kopf-Aktion entfällt.
  - Fällige Termine heißen „seit 65 Tagen überfällig" statt „jetzt".
  - Lange Kachelnamen („Eigenverbrauchsquote") brechen um, statt
    abgeschnitten zu werden.
- **Versionsnummer:** steht unten in der Seitenleiste statt in der
  Kopfleiste; „flat-file JSON" entfällt.

### Fixed

- **Temperaturansicht (FE-12):** Nach dem Abgleich zeigten die Felder den
  alten Standort, und der nächste Klick schrieb ihn zurück.
- **Nach Demo-Daten (UI-24):** Die Seitenleiste zeigte weiter drei
  Verbrauchsarten, und beim Neuladen fragte der Browser nach
  „ungespeicherten Änderungen".
- **Zählerstand-Erfassung:** Hinweise standen in festem Amber auf Weiß (2,2:1).

### Migration

Keine. Schema bleibt 1.6.0.

### Tests

- `tests/router.test.mjs` (20 Prüfungen): veraltete Antworten, Cleanup,
  Query, „Erneut versuchen", Abbruch von Leseanfragen, Zurück-Taste.
- `tests/contrast.test.mjs` (73 Paare nach WCAG AA, beide Themes, alle
  Farben der Verbrauchsarten).
- Browser-Render (93) mit den neuen Seiten und dem Navigationsmodell; der
  Modulgraph-Crawl folgt jetzt auch dynamischen Importen.
- 434 Testmethoden, Frontend-API-Shape 56/56. 19 Gegenproben, alle rot.

### Lessons Learned

- **Gemeinsamer Container:** Hängen alle Ansichten an denselben Container,
  entscheidet die langsamste Antwort, was zu sehen ist. Ein eigener
  Container je Navigation beendet diesen Wettlauf ohne eine Zeile in den
  Ansichten.
- **`history.back()` nach einer Navigation macht sie rückgängig:** Ein
  Dialog nimmt seinen History-Eintrag nur zurück, wenn er selbst schließt,
  nicht beim Seitenwechsel.
- **Kontraste gehören an einen Test:** v2.2.0 hatte sie schon einmal
  korrigiert; ein Farbverlauf und neue Verbrauchsarten brachten sie zurück.

---

## [2.10.0] — 2026-09-25 — Energieträger ehrlich

MINOR-Release (F1015). **Schema 1.5.0 → 1.6.0** (additiv, siehe Migration).
Neue Felder sind additiv; bestehende Felder bleiben.

**Der Anlass.** Das Gesamtreview hat Heizöl, Pellets, CO₂, PV und die
Effizienzklasse nachgerechnet: Eine Heizöllieferung von heute erhöhte den
Verbrauch aller Vorjahre um rund 20 %, der Anfangsbestand war kostenlos, der
Gas-CO₂-Faktor bezog sich auf den Heizwert, während die App Brennwert zählt,
Strom hatte einen Faktor für alle Jahre, die PV-Erzeugung stand als Emission
im Jahresbericht, und ein halbes Jahr ergab die Effizienzklasse „A"
(Review CALC-04, -07, -17, -18, -19, -24, -25).

### ⚠️ Für bestehende Installationen

- **Werte ändern sich** — Klasse B der Stabilitätszusage: Die Felder bleiben,
  ihre Berechnung wird korrigiert.
  - **Heizöl und Pellets:** Verbrauch, Kosten und Bestandskurve kommen aus
    einer Rechnung (Tankbuch). Ohne Peilstand und ohne Lieferung „bis voll"
    gilt die Rate der Bestandskurve jetzt auch für Verbrauch und Kosten — die
    Jahreswerte liegen typisch niedriger, weil der Tank nicht mehr als heute
    leer gilt. Kosten zum Durchschnittspreis des Tankinhalts; der
    Anfangsbestand kostet den Preis der ersten Lieferung (bis v2.9: 0 €).
    Ohne jede Lieferung und ohne Peilstand gibt es keinen Verbrauch mehr (bis
    v2.9 galt der ganze Anfangsbestand als bis heute verbraucht).
  - **Effizienzklasse:** Jahre mit weniger als 360 Tagen Daten bekommen keine
    Klasse mehr; Grenzen gelten einschließlich („bis 100" ist C statt D).
  - **PV-Quoten** rechnen nur über Monate, in denen Bezug, Einspeisung und
    Erzeugung Daten haben; in anderen Monaten sind Eigenverbrauch und Quoten
    `null` statt 0.
  - **Tarifwechsel und Rückblick der Einspeisung:** höhere Vergütung zuerst.
- **Keine Zahl ändert sich bei den CO₂-Faktoren und der Wasser-Referenz von
  selbst:** Die Migration schreibt die bisherigen Defaults fest, wo sie nie
  gespeichert wurden. Die Einstellungen zeigen „Neuere Standardwerte
  verfügbar" — übernommen wird nur auf Knopfdruck.
- **Rückweg:** Nach dem Update auf Schema 1.6.0 startet eine ältere Version
  nicht mehr auf diesen Daten (Downgrade-Schutz, 503). Vor der Migration legt
  die App einen Snapshot `pre-migration-1.5.0_…` an.

### Added

- **Tankbuch (CALC-04, CALC-25):** Stützstellen mit bekanntem Bestand —
  Anfangsbestand, Lieferung `fill_to_full` („bis voll getankt"), Peilstände
  `tank_levels` am Tank. Dazwischen ist der Verbrauch gerechnet
  (`Bestand_vorher + Lieferungen − Bestand_nachher`), verteilt nach Grundlast
  und Gradtagen; danach mit einer kalibrierten Rate geschätzt und so
  gekennzeichnet. `stock-history` liefert `anchors`, `estimated_from`,
  `calibration`, `warnings` und `estimated` je Tag; Monatszeilen
  `estimated_days` und den effektiven `working_price_ct`. Preis des
  Anfangsbestands `initial_stock_price_ct` am Tank. Oberfläche: Bestandsverlauf
  mit bekannten Beständen, Peilstände erfassen und löschen, „Bis voll
  getankt" im Lieferdialog, „≈" für geschätzte Monate.
- **Energieausweis-nahe Kennzahl (CALC-07):** `certificate` in
  `/api/benchmarks/efficiency` — Gas × 0,906 (Brennwert → Heizwert),
  witterungsbereinigt (Heizmodell bzw. Klimanormal), bezogen auf die
  Gebäudenutzfläche (1,2 bzw. 1,35 × Wohnfläche), Warmwasser-Zuschlag 20
  kWh/m²·a. Neue Einstellungen `beheizter_keller`, `warmwasser_dezentral`;
  Stromzähler lassen sich als Wärmepumpe (`heat_source`) kennzeichnen und
  zählen dann als Heizquelle. `coverage_days`/`complete` je Quelle.
- **CO₂ je Jahr und mit Quelle (CALC-19):** `co2_strom_years`
  (Umweltbundesamt, Emissionsfaktor Strommix 2015–2025); neue Defaults Gas
  182 (BAFA 201 auf Heizwert × 0,906), Pellets 36, Fernwärme 280 (BAFA),
  Wasser-Referenz 122 L (BDEW 2024). Hinweise mit Quelle je Faktor in den
  Einstellungen, Jahreswerte als Tabelle. `GET /api/settings/default-updates`
  und die Karte „Neuere Standardwerte verfügbar". Hinweis, wenn nur der
  Standard-Gasfaktor 11,5 aktiv ist.
- **PV (CALC-17, CALC-18):** `pv-summary` mit `covered`/`months_covered`,
  `savings_eur` (Eigenverbrauch × Arbeitspreis des Bezugs),
  `feed_in_revenue_eur`, `pv_benefit_eur`; Dashboard-Kachel „Ersparnis
  Eigenverbrauch". `higher_is_better` in Tarifwechsel und Rückblick.

### Changed

- **Heizöl/Pellets:** Fehlende Tagestemperaturen füllt das Klimanormal statt
  einer flachen Verteilung; die Tagesform nutzt die Gradtage eines
  Normaljahrs, damit ein Sommerintervall vor allem Grundlast trägt.
- **PV:** Die Erzeugung zeigt vermiedenes CO₂ und keine Kostenkachel; im
  Jahresbericht steht die Einspeisung als Erlös, das vermiedene CO₂ einmal.
- **Effizienz** im Dashboard und im Jahresbericht mit der zweiten Zahl und
  einem Hinweis für unvollständige Jahre.
- **Gebäudetyp** im Klartext statt als Kürzel (Ein-/Zweifamilienhaus,
  Reihenhaus, Mehrfamilienhaus, Wohnung): Er bestimmt jetzt die
  Gebäudenutzfläche der neuen Kennzahl.
- **PV-Kachel „Autarkiequote":** nennt die Zahl der Monate, solange es weniger als
  zwölf mit Daten aller drei Zähler sind (auch im laufenden Jahr).

### Fixed

- CO₂-Faktoren für Heizöl und Pellets waren in den Einstellungen mit g/L und
  g/kg beschriftet; gerechnet wurde (und wird) je kWh.
- Zahlen, die auf die angezeigte Stelle zu 0 runden, erschienen als „-0,0"
  (etwa −0,04 °C).
- Die Erzeugungsansicht nannte die erzeugte Menge „Verbrauch".

### Migration

Schema **1.6.0**: Für `co2_gas`, `co2_strom_years`, `co2_pellets`,
`co2_fernwaerme` und `wasser_personen_referenz` schreibt der Migrator bei
Daten älter als 1.6.0 den bisherigen Default in `settings.json`, wenn der
Schlüssel dort fehlt (201 / [] / 26 / 180 / 127). Gesetzte Werte bleiben.
Neue Installationen starten mit den korrigierten Defaults. Snapshot vor der
Migration wie gewohnt.

### Tests

- Neu: `TankModelTest` (16), `Co2FactorsTest` (8), `PvSemanticsTest` (5),
  `EfficiencyCertificateTest` (6), Demo-Test für den Peilstand, Prüfungen
  gegen negative Null; Schema-Pins auf 1.6.0.
- 434 Testmethoden (vorher 398), Frontend-API-Shape 56/56, Browser-Render
  81/81. 36 Gegenproben, alle rot.

### Lessons Learned

- Zwei Rechnungen für dieselbe Größe widersprechen sich irgendwann — die
  Antwort war eine richtige Rechnung, nicht zwei.
- Eine Größe, die ein Jahr beschreibt, gehört aus einem Jahr geschätzt.
- Ein korrigierter Default braucht einen Migrationsschritt (Lektion 36), und
  der darf nur alte Daten betreffen.
- Eine Einheit an der Oberfläche ist eine Behauptung, die ein Test prüfen muss.
- Ein Kennzeichen wirkt nur, wo es gelesen wird.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.9.0] — 2026-09-25 — Verträge wie die Rechnung

MINOR-Release (F1014). Kein Schema-Bump, keine Datenmigration. Neue
Vertrags- und Antwortfelder sind additiv; zwei wirkungslose Einstellungen sind
als veraltet markiert und werden weiter geliefert.

**Der Anlass.** Das Gesamtreview hat die Vertragsrechnung neben die
Jahresabrechnung gelegt: Ein Vertragswechsel zum 15. rechnete den ganzen Monat
zum alten Preis, eine Preiserhöhung zur Monatsmitte griff erst im Folgemonat,
Verbrauch nach einem vergessenen Vertragsende kostete 0 €, und mit einem Monat
Kündigungsfrist kamen zwei von drei Erinnerungen nach dem letzten
Kündigungstag (Review CALC-10, -11, -15, -21, -23, -24).

### ⚠️ Für bestehende Installationen

- **Werte ändern sich** — Klasse B der Stabilitätszusage: Die Felder bleiben,
  ihre Berechnung wird korrigiert.
  - **Monate mit Vertragswechsel oder Preisänderung zur Monatsmitte** werden
    am Stichtag geteilt: Arbeitspreis nach dem Verbrauch der Tage, Grundpreis
    nach Tagen. Die Monatszeile gehört dem Vertrag mit den meisten Tagen;
    beide Teile stehen in `contract_parts`.
  - **Abschläge in Monaten mit Vertragsbeginn oder -ende** zählen anteilig
    nach den Vertragstagen. Bisher bekam der Vertrag vom Monatsersten den
    vollen Abschlag.
  - **Nach einem Vertragsende ohne Nachfolger** läuft der Vertrag zu seinen
    letzten Preisen und Abschlägen weiter, wie beim Versorger — bisher kostete
    dieser Verbrauch 0 €. Wer gekündigt hat: am Vertrag „Verlängert sich ohne
    Kündigung" abwählen, dann endet er wie bisher.
  - **Erinnerungen** (Analyse, Empfehlung „Vertrag") zählen bei gepflegter
    Kündigungsfrist bis zum Kündigungsstichtag statt bis zum Vertragsende —
    sie kommen also um die Frist früher.
  - **Tarifvergleich, Rückblick:** Schattenverträge gelten als Preisblatt für
    den ganzen Zeitraum; Angebote mit kurzer Laufzeit zeigen andere Werte.
    Echte Verträge zeigen, was die Monatsrechnung gebucht hat.
  - **Zähler außer Betrieb** (nicht aktiv) zählen im PDF-Jahresbericht und in
    der Effizienzklasse mit ihrer Historie — wie schon in Verbrauchsansicht,
    Dashboard und CSV.
  - **Banner „Ablesung überfällig"** folgt der Einstellung *Warnung nach*
    (Default 45: Warnung ab 30, Alarm ab 45 Tagen; bisher fest 30
    und 60).
- **Abrechnungsstichtag** wird beim Speichern geprüft: Ein Wert, der kein
  Kalendertag ist (`13-45`, `02-30`), wird mit 400 abgelehnt. Ein bereits
  gespeicherter ungültiger Wert rechnet mit dem 1. Januar.

### Deprecated

- Einstellungen `min_temp_days_forecast` (seit v2.8.0 durch die 90-%-Regel
  ersetzt) und `baujahr` (floss nie in eine Rechnung ein): ohne Wirkung,
  nicht mehr in der Oberfläche. `GET`/`PATCH /api/settings` liefern und
  nehmen sie weiter an; sie entfallen frühestens mit v3.0.0.

### Added

- **Verträge tagesgenau (CALC-10):** `ContractService::segmentsBetween()`
  teilt Monate an Vertragsbeginn und -ende und an jedem Stichtag von
  Arbeitspreis, Grundpreis und Abschlag. Monatszeilen tragen
  `contract_assumed` und bei zwei Verträgen im Monat `contract_parts[]`
  (`contract_id`, `days`, `kwh`, `kwh_cost`, `base_price_eur`,
  `advance_eur`, `bonus_eur`, `cost`, `assumed`); der Saldo jedes
  Vertrags zählt nur seinen Teil. Wasser bleibt beim Vertrag des
  Monatsersten.
- **Weiterlaufende Verträge:** Vertragsfeld `auto_renews` (`false` =
  gekündigt), in `contract-status` `renewed`. Die Vertragstabelle zeigt
  **VERLÄNGERT**, der Saldo rechnet bis zur nächsten Abrechnung, und die
  Saldo-Karte sagt, dass der Vertrag jederzeit mit höchstens einem Monat Frist
  kündbar ist (§ 309 Nr. 9 BGB).
- **Kündigungsstichtag (CALC-11):** Frist in Monaten, Wochen oder Tagen
  (`notice_period_days`, 0–730, Vorrang vor den Monaten) und Kündigungsweise
  `notice_mode` (`term_end`, `month_end`, `any_day`). `contract-status`
  liefert `cancel_by`, `days_to_cancel`, `switch_date`, `notice_basis`,
  `remind_basis` und `cancel_missed`; Erinnerung und Empfehlung nennen den
  Stichtag, die Saldo-Karte einen verpassten.
- **Preiserhöhung (CALC-11):** `price_increase` — die nächste eingetragene
  Erhöhung von Arbeits- oder Grundpreis. Die Saldo-Karte weist darauf hin, in
  Deutschland mit dem Sonderkündigungsrecht zum Zeitpunkt der Erhöhung
  (§ 41 Abs. 5 EnWG).
- **Wärmezähler-Eichung** als Terminkategorie (fünf Jahre, CALC-24).
- Zählerdialog: Hinweis, was „aktiv" abwählen bewirkt.

### Changed

- **Prognose:** Die Kostenrechnung teilt Monate wie die Ist-Rechnung
  (`ForecastService::projectStandardMonth()`); ein Vertragswechsel im
  Prognosezeitraum wirkt ab seinem Tag.
- **Tarifvergleich (CALC-21):** Rückblick wie unter ⚠️ beschrieben. Die
  Wechselentscheidung nimmt einen weiterlaufenden Vertrag als Bindung, statt
  „kein laufender Vertrag" zu melden; `current` trägt `notice_period_days`,
  `notice_mode` und `renewed`.
- **Zähler außer Betrieb (CALC-15):** Was „inaktiv" heißt, steht an einer
  Stelle (`MeterService::countsInTotals()`, `inService()`): Die Historie
  zählt in jeder Summe; Erfassung, Warnungen und Empfehlungen lassen den
  Zähler aus.
- **Einstellungen mit Wirkung (CALC-23):** `dashboard_months` bestimmt den
  Verlauf auf dem Dashboard (3–36 Monate), `alert_days_since_reading` das
  Banner „Ablesung überfällig".
- Vertragsformular: Kündigungsfrist mit Einheit, Kündigungsweise, Haken
  „Verlängert sich ohne Kündigung".

### Fixed

- Verbrauch nach einem Vertragsende ohne Nachfolger kostete 0 € (CALC-10).
- Ein ungültiger Abrechnungsstichtag ergab ein unmögliches Datum und null
  verbleibende Monate im Saldo (CALC-24).
- Derselbe Zähler außer Betrieb ergab im PDF-Jahresbericht und in der
  Effizienzklasse eine andere Jahressumme als in der Verbrauchsansicht
  (CALC-15).
- Saldo-Karte offener Verträge: Sie nannte „geschätztes Ende … (offener
  Vertrag, +12 M)", gerechnet wird aber bis zum nächsten Abrechnungsstichtag.
  Jetzt steht dort „nächste Abrechnung: …".

### Migration

Keine. Schema bleibt 1.5.0. Verträge ohne die neuen Felder laufen weiter:
Ohne `auto_renews` verlängert sich ein Vertrag ohne Nachfolger, die
Kündigungsfrist bleibt in Monaten.

### Tests

- Neu: `ContractDayAccurateTest` (13 Tests: Preisänderung zur Monatsmitte,
  Verlängerung, Kündigung, Lücke zwischen Verträgen, Stichtag und Erinnerung,
  verpasste Frist, Preiserhöhung, anteilige Abschläge, Prognose),
  `TotalsAndSettingsRulesTest` (4), drei neue Fälle in
  `TariffComparisonServiceTest`. Der Test, der den Monatsersten festschrieb,
  ist bewusst umgeschrieben (`testContractSwitchMidMonthSplitsTheMonthByDay`).
- 398 Testmethoden (vorher 379), Frontend-API-Shape 50/50, Browser-Render
  76/76. Jede neue Regel hat eine Gegenprobe (26, alle rot).

### Lessons Learned

- Ein Test kann auch einen Fehler festschreiben — dann wird er umbenannt und
  begründet, nicht still angepasst.
- Ohne Datensatz ist nicht null: Wo Daten fehlen, gehört die fachlich
  richtige Annahme hin, als Annahme gekennzeichnet.
- Eine Erinnerung zählt bis zum letzten Tag, an dem man handeln kann.
- Ein Vergleich braucht denselben Zeitraum.
- Eine wirkungslose Einstellung entfernt man nicht still aus der API.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.8.1] — 2026-09-25 — Saldo ohne Messwerte

PATCH-Release. Kein Schema-Bump, keine Datenmigration.

### Fixed

- **Verbrauchsansicht brach mit HTTP 500 ab**, wenn an einem Zähler ein
  Vertrag mit Abschlägen hängt (Gas, Strom, Fernwärme), aber noch keine zwei
  Ablesungen vorliegen — der übliche Einstieg: erst den Vertrag anlegen, dann
  ablesen. Dasselbe, wenn alle Ablesungen vor einer frisch gesetzten Zäsur
  liegen. Die in v2.8.0 eingeführte Saldo-Hochrechnung hatte dann nichts
  hochzurechnen und rief eine leere Schätzung auf; `GET …/contract-status`
  antwortete mit 500, und die Ansicht zeigte nur noch die Fehlermeldung.
  Jetzt rechnet der Saldo bis heute mit Grundpreis und Abschlägen und ohne
  geschätzten Verbrauch (`projection_method: "flat_average"`).

### Tests

- Neu: `testBalanceWithoutUsableMonthsDoesNotFail` (beide Fälle); per
  Gegenprobe als greifend nachgewiesen. 379 Testmethoden.

### Lessons Learned

- Jede neue Hochrechnung braucht den Fall „nichts hochzurechnen". Die
  Browser- und Shape-Tests laufen gegen die vollständigen Demo-Daten und
  sehen den Einstieg eines neuen Nutzers nie; der Fehler fiel beim Schreiben
  eines Tests für das nächste Paket auf.

---

## [2.8.0] — 2026-09-25 — Rechnen wie die Abrechnung

MINOR-Release (F1013). Kein Schema-Bump, keine Datenmigration. Neue Felder
sind additiv; drei alte Felder sind als veraltet markiert und werden weiter
geliefert.

**Der Anlass.** Das Gesamtreview hat die Rechenkerne gegen synthetische Daten
mit bekannter Wahrheit geprüft. Auf sauberen Daten meldete die App jeden
Sommer der Heizarten als Anomalie, die Wetterbereinigung verglich Januar mit
Oktober („+58 %"), eine Prognose ohne eigenen Januar setzte für ihn weniger als
ein Zehntel an, und der Saldo zählte Abschläge nur für abgelesene Monate
(Review CALC-02, -03, -05, -06, -08, -12, -13, -14, -22, -30, FE-18, DOC-08, API-24, UI-36).

### ⚠️ Für bestehende Installationen

- **Werte ändern sich** — Klasse B der Stabilitätszusage: Die dokumentierten
  Felder bleiben, ihre Berechnung wird korrigiert.
  - **Saldo** (`contract-status`, Saldo-Karte): `advance_paid` zählt die
    Abschläge nach Kalender bis einschließlich des laufenden Monats, die Kosten
    reichen bis heute — ab der letzten Ablesung geschätzt und so ausgewiesen.
    Wer länger nicht abgelesen hat, sieht einen anderen, richtigen Saldo.
  - **Anomalien und Empfehlungen:** neue Erwartung je Monat und robuste
    Streuung. Auf den Demo-Daten sinken die Empfehlungen von 7 auf 2 und die
    Anomalien von 22 auf 7. Entfallen sind Trend- und Sommermeldungen für
    Heizöl und Pellets (Artefakte der Gradtagverteilung), ein halber
    Strom-März und Fernwärme-Sommer mit der Erwartung 0; die verbliebenen
    Meldungen sind echte Abweichungen in den Daten.
  - **Prognose:** Heizgradtage aus dem Klimanormal; Kalendermonate ohne eigene
    Historie kommen aus dem Modell. Bei kurzer Historie ändert sich der
    Jahreswert deutlich.
  - `hdd` zählt nur noch die Tage mit Verbrauch (Teilmonate); das
    Knickmodell ist stetig, seine Parameter und sein R² ändern sich.
- **Neu: täglicher Abruf bei Open-Meteo.** `weather_auto_fill` (Default an)
  war bisher ohne Wirkung. Jetzt holt die App beim Öffnen höchstens einmal am
  Tag die fehlenden Temperaturen und beim ersten Mal das Klimanormal
  (30 Jahre). Übermittelt wird nur der Standort, auf zwei Nachkommastellen
  gerundet (rund 1 km). Wer das nicht will: *Einstellungen → Wetter
  automatisch füllen* aus. Der README-Satz „stellt keine externen Anfragen"
  ist entsprechend geändert.
- **`confidence_band_sigma`** (Default 1,28) wirkt jetzt: Es bestimmt die
  Breite des Prognosebands (1,28 σ ≈ 80 % der Jahre).

### Deprecated

- `expected_hgt`, `weather_adjusted` und `delta_pct` in den Monatszeilen von
  `GET …/consumption`. Nachfolger: `expected_heat`, `heat_adjusted`,
  `weather_delta_pct`. Die alten Felder werden unverändert weiter geliefert
  und entfallen frühestens mit v3.0.0. `weather_adjusted` skalierte auch das
  Warmwasser mit dem HGT-Verhältnis (ein September wurde um 54 % „bereinigt"),
  `delta_pct` maß die Jahreszeit statt des Mehrverbrauchs.

### Added

- **Klimanormal (CALC-30):** Tagesmittel der letzten 30 vollen Kalenderjahre
  am Standort, einmal geholt und nur als Kennzahlen gespeichert
  (`data/climate_normal.json`): Heizgradtage je Kalendermonat mit Streuung
  für Heizgrenzen von 10 bis 22 °C. Neu geladen bei Umzug (> 0,05°) oder
  neuem Jahr. Ohne Klimanormal rechnet die App mit der eigenen
  Temperaturhistorie und sagt das dazu.
- **Heizmodell je Zähler (CALC-05):** `Verbrauch = a × HGT + c × Tage` —
  Verbrauch je Gradtag und Grundlast je Tag, gefittet ohne Achsenabschnitt
  über alle Monate ab der Zäsur (Sommer eingeschlossen), mit einem
  Robustheitsschritt. Daraus je Monat `expected_heat`, `weather_delta_pct`,
  `hdd_normal` und `heat_adjusted` (umgerechnet wird nur der Wettereinfluss
  laut Modell, die eigene Abweichung des Monats bleibt; unter der Grundlast
  bleibt ein Monat, wie er ist).
- **Unsicherheitsband der Prognose (CALC-13):** je Monat `band_low`/
  `band_high` und fürs Jahr `annual` (`value`, `low`, `high`, `sigma`,
  `level_pct`) aus der Streuung der Winter und dem Rauschen des Modells;
  dazu `hdd_source`, `climate_normal` und `warnings` (`history_short`,
  `no_climate_normal`). Die Prognoseansicht zeigt das Band als Fläche und
  eine Zeile „Jahr: …, in 80 % der Jahre zwischen … und …".
- **Saldo nach Kalender (CALC-02):** neue Felder `balance_as_of`,
  `projection_method`, `measured_until`, `cost_to_date`,
  `energy_cost_to_date`, `base_to_date`, `bonus_to_date`,
  `estimated_cost_to_date`, `estimated_cost_remaining`, `advance_remaining`,
  `suggested_advance`, `projection_factor`. Die Schätzung seit der letzten
  Ablesung läuft je Tag über das Heizmodell (bzw. die Tagesrate des
  Kalendermonats) und folgt dem jüngsten gemessenen Niveau (Faktor 0,5–1,5).
  Die Saldo-Karte schlüsselt die Kosten in Arbeitspreis und Grundpreis auf,
  nennt Mess- und Schätzzeitraum und schlägt bei spürbarer Abweichung einen
  Abschlag vor.
- **Temperaturen mit Quelle (DOC-08):** Jeder Tag trägt `source`
  (`archive`, `forecast`, `csv`, `manual`). Vorhersagen werden durch
  Archivwerte ersetzt, sobald diese vorliegen; eigene Werte nie.
  `POST /api/temperatures/sync-open-meteo` kennt `reload=1` (auch ältere
  Werte durch Archivwerte ersetzen) und `auto=1` (höchstens einmal am Tag)
  und liefert `measured_until`, `forecast_until` und den Status des
  Klimanormals. Die Temperaturansicht nennt, bis wann gemessen und bis wann
  vorhergesagt ist.
- **Wirkung einer Maßnahme mit Beleg (CALC-13):** `baseline_comparison`
  trägt `se` je Steigung, `significant` (Test der Steigungsdifferenz) und
  `delta_pct_ci95`; die Analyse sagt, ob der Unterschied belegt ist.
- **Regressionen:** `curve` (Kurvenpunkte vom Backend), `se_a` beim linearen
  Modell, `regression_point` je Monat; für Heizöl und Pellets
  `regressions_note: "delivery_modelled"` statt Kurven.
- Tarifwechsel: Der Block `forecast` trägt `warnings`, `hdd_source` und
  `annual_band`.

### Changed

- **Eine Regel für die Punkte der Heizkurve (CALC-14, FE-18):** Analyse,
  Bereinigung, Prognose und Anomalien wählen ihre Monate über
  `isRegressionCandidate()` — genug Tage, Temperaturen für ≥ 90 % der Tage,
  genug Heizgradtage. Derselbe Zähler zeigte bisher R² 0,42 in der Analyse
  und 0,56 in der Prognose. Das Streudiagramm zeichnet Monate außerhalb des
  Fits hohl und die Kurven aus dem Backend.
- **Heizgradtage eines Monats** zählen nur die Tage mit Verbrauch
  (`temp_days` sagt, für wie viele Temperaturen vorliegen).
- **Anomalien (CALC-06):** Erwartung je Monat aus dem Heizmodell bzw. aus
  demselben Kalendermonat anderer Jahre (im ersten Jahr den Nachbarmonaten),
  nie aus dem geprüften Monat selbst; Median und MAD statt Mittel und
  Standardabweichung, mit einer Untergrenze von 10 % eines typischen Monats.
  Heizöl und Pellets liefern keine Anomalien mehr.
- **Empfehlungen:** R1 misst am Heizmodell, R2 vergleicht die bereinigten
  Werte mit denselben Monaten des Vorjahrs (ab neun Paaren), R3 nur volle
  Monate und ohne Lieferarten, R4 aus der Anomalie-Erkennung (bisher lief R4
  für Wasser nie, weil es `kwh` las) und ohne PV.
- **Prognose (CALC-03, CALC-12):** Saisonprofil als Tagesrate; der
  Temperaturversatz verschiebt die Heizgrenze statt linear über alle
  Monatstage; Abschläge aus dem effektiven Zahlungsplan; nach dem Ende des
  letzten Vertrags läuft er als Annahme weiter (`contract_assumed`, in der
  Tabelle mit Sternchen). Die Spalte „Methode" ist übersetzt.
- **Knickmodell (CALC-22):** stetig — Sockel und Heizast treffen sich im
  Knick.
- **Analyse:** Hinweis unter der Modelltabelle: R² misst die Anpassung an
  die gezeigten Monate, keine Vorhersagegüte.
- **Verbrauchsansicht:** Liegt die letzte Ablesung im laufenden Jahr zurück,
  heißt die Abschlagskachel „Abschläge bis ‹Datum›" — sie summiert
  abgelesene Monate, die Saldo-Karte rechnet nach Kalender.
- PDF-Jahresbericht: bereinigte Werte aus dem Heizmodell, mit Legende.

### Fixed

- Analyse: Die Überschrift sprach von vier Regressionsmodellen (es sind
  fünf), die Sigmoid-Formel zeigte bei negativem θ₀ „HGT−-10.0", Koeffizienten
  und R² standen mit Dezimalpunkt statt im Zahlenformat der Sprache. Die
  Prognosetabelle zeigte die Methode als Rohwert `blend(reg=…)`.
- Doku: Die Saldo-Formel im Wohnungs-Szenario hatte das umgekehrte
  Vorzeichen; veraltete Service- und Controller-Zahlen in Architektur-Doku
  und README entfernt statt nachgezählt.

### Tests

- Neu: `WeatherModelTest` (20 Tests, synthetische Daten nach
  `a × HGT + c × Tage`), `TemperatureSyncTest` (11, Open-Meteo als Attrappe
  über das neue Interface `WeatherSource`); `BaselineCutoffTest` an die neue
  Erwartung angepasst; Roundtrip von `source` im Backup.
- 378 Testmethoden (vorher 346), Frontend-API-Shape 49/49, Browser-Render
  73/73. Jede neue Rechenregel hat eine Gegenprobe (25, alle rot).

### Lessons Learned

- Eine Erwartung, die den geprüften Wert enthält, prüft nichts — Erwartungen
  gehören zum Monat, der geprüfte Wert nie in die eigene Erwartung.
- Synthetische Testdaten müssen die alte Rechnung widerlegen können: Mehrere
  Regeln waren auf glatten Daten auch ohne sich selbst grün.
- Ein Saldo folgt dem Kalender, nicht den Ablesungen — und eine
  Aufschlüsselung muss ihre Summe ergeben.
- Wer eine wirkungslose Einstellung zum Leben erweckt, prüft, welche
  Doku-Aussagen an ihrem Nichtstun hingen („stellt keine externen Anfragen").
- Eine Formel für Jahreswerte taugt nicht ungeprüft für Monate: Die
  Heizanteil-Skalierung nach VDI 3807 machte im PDF der Demo aus einem warmen
  September +32 %. Aufgefallen ist es erst im fertigen Bericht, nicht im Test.

Ausführlich: [Release-Prozess §5](docs/entwicklung/release-prozess.md).

---

## [2.7.0] — 2026-09-25 — Länderprofile

MINOR-Release (N1014). Kein Schema-Bump, keine Datenmigration. Vier neue
Einstellungen mit Defaults, die dem bisherigen Verhalten entsprechen — wer
nichts umstellt, merkt an bestehenden Schnittstellen nichts.

**Der Anlass.** Die Oberfläche spricht seit v2.0.0 sieben Sprachen, alles
andere war deutsch geblieben: Euro und Cent, Effizienzklassen nach dem
Gebäudeenergiegesetz, der deutsche Strommix als CO₂-Faktor, 15 °C Heizgrenze,
Leipzig als Wetterstandort, Europe/Berlin — und das Backend schrieb Zahlen
fest deutsch: Im englischen PDF stand „10.359 kWh". Ein Länderprofil bündelt
diese Voreinstellungen jetzt für Deutschland, Österreich, die Schweiz,
Frankreich, Italien, Spanien, Portugal, die Niederlande und das Vereinigte
Königreich (Review I18N-01 bis -05, I18N-17 teilweise, MKT-21).

### ⚠️ Für bestehende Installationen

- **Nichts umstellen = nichts ändert sich.** Die Defaults sind das deutsche
  Profil, und das deutsche Profil ist das bisherige Verhalten — ein Test hält
  beides gleich. Englische Oberflächen schreiben weiter englische Zahlen.
- **PDF-Jahresbericht und Empfehlungstexte** schreiben Zahlen, Beträge, Daten
  und Monate jetzt in der Sprache der Oberfläche: In einer englischen
  Installation wird aus „10.359 kWh" „10,359 kWh", aus „2025-01" „Jan 2025".
- **Wer das Land wechselt**, sieht die Effizienzklasse nur noch in
  Deutschland; andere Länder zeigen die Kennzahl kWh/m²·a ohne Klasse.

### Added — Länderprofile

- **Einstellungen → Sprache & Land:** Land, Währung und Zeitzone neben der
  Sprache; alle vier wirken sofort. Beim Wechsel des Landes zeigt ein Dialog,
  welche Werte das Profil ändern würde (bisher → neu, mit Quelle des
  CO₂-Faktors): **Alle übernehmen**, **Nur Land ändern** oder **Abbrechen**.
  Zähler, Verträge und Ablesungen bleiben unberührt.
- **Erststart nach Browsersprache (I18N-01):** Ein leeres Datenverzeichnis
  übernimmt Sprache und Land aus `Accept-Language` („fr-CH" → Französisch,
  Schweiz); die Standardzähler heißen gleich richtig („Compteur principal").
  Geschrieben werden nur Werte, die vom Default abweichen — ein deutscher
  Erststart schreibt nichts fest, spätere Default-Korrekturen greifen weiter.
- **Profile (SSOT `src/Config/Countries.php`):** Währung, Zeitzone,
  Heizgrenze nach nationaler Gradtag-Konvention (FR 18 °C, IT 20 °C, NL 18 °C,
  UK 15,5 °C), CO₂-Faktor Strom (Ember 2024 über Our World in Data; DE behält
  380 g/kWh), Wetterstandort (Hauptstadt), Effizienzskala, Brennwert-Einheit.
- **Währung (I18N-03):** EUR, CHF, GBP. Symbol und Untereinheit in allen
  Texten („Rp./kWh", „p/kWh", Achsen in CHF oder £). Beträge werden **nicht**
  umgerechnet; die Datenfelder (`ct_per_kwh`, `*_eur`) bedeuten Haupt- und
  Untereinheit der gewählten Währung.
- **Gas (I18N-05):** Brennwert-Eingabe in kWh/m³, MJ/m³ oder GJ/Smc,
  gespeichert immer in kWh/m³; Hinweise für britische, italienische und
  niederländische Rechnungen. Im Gasvertrag rechnet **„Preis je m³
  umrechnen"** einen Arbeitspreis je m³ oder Smc in ct/kWh um — geteilt durch
  den Brennwert am gewählten Tag — und trägt ihn auf Wunsch ein.
- **Temperaturen:** Hinweis, wenn der Standort noch die Voreinstellung des
  Landes ist (die Gradtagzahlen rechnen dann mit dem Wetter eines anderen Orts).
- **`GET /api/countries`** — die Profile (Klasse C).
- Neues Kapitel [Länderprofile](docs/verstehen/14-laenderprofile.md) (DE/EN).

### Changed

- **Schreibweise aus Sprache und Land (I18N-17, teilweise):** Wird die Sprache
  im Land gesprochen, verfeinert das Land die Region (de-AT „€ 1.234,56",
  de-CH „1'234.50", fr-CH). Sonst gilt die Region der Sprache — Englisch in
  Deutschland bleibt en-GB. Das Backend folgt derselben Regel.
- **Effizienzklasse nur mit Skala (I18N-04):** Heute hat nur Deutschland eine
  (GEG). Andernorts sind die Klassenfelder `null`, `scale` ist `null`, und
  `scale_note` nennt den Grund; die Empfehlung „schwache Effizienzklasse"
  entfällt dort. Dashboard und PDF zeigen die Kennzahl ohne Klasse.
- **Empfehlungstexte:** Monate als „Jan. 2025" statt „2025-01", Zahlen mit
  landesüblichem Dezimalzeichen.
- **PDF:** Stufe der Empfehlung aus dem Katalog („[Dringend]" statt
  „[URGENT]").

### Changed — Schnittstellen (additiv)

- `GET|PATCH /api/settings`: neue Schlüssel `country` (Default `DE`),
  `currency` (`EUR`), `timezone` (`Europe/Berlin`), `gas_cv_unit` (`kwh`).
  Unbekannte Werte → 400 `errors.settings.valueInvalid`.
- `GET /api/benchmarks/efficiency`: neue Felder `scale`, `scale_note`.
- CSV-Spaltenköpfe mit Währung nennen die gewählte Währung („Kosten (CHF)");
  für Euro unverändert.

### Fixed

- **PDF-Jahresbericht (I18N-02):** Zahlen, Beträge, das Erstellungsdatum und
  die Monate standen in jeder Sprache deutsch formatiert, Beträge immer in €.
- **Termine:** Fälligkeiten standen im Dashboard und in der Terminliste als
  „2026-07-22"; ebenso die Zäsuren am Zähler und die Vorschau der
  v0.9.0-Migration.
- **Wetterdaten:** Die Tagesmittel von Open-Meteo wurden immer in
  Europe/Berlin gebildet — außerhalb dieser Zeitzone lag jede Tagesgrenze
  daneben. Jetzt gilt die Zeitzone der Installation.

### Migration

Keine. Schema bleibt 1.5.0. Die neuen Schlüssel erscheinen in
`settings.json` erst, wenn sie vom Default abweichen.

### Tests

324 → 346 Testmethoden (380 Fälle): `CountriesTest` (Profile vollständig,
DE-Profil = ausgelieferte Defaults, Namen in allen Katalogen,
Accept-Language, Einheiten Frontend = Backend), `CountryProfileTest`
(Schreibweise für acht Sprach-Land-Kombinationen, Währung in Katalogtexten,
kein fest eingeschriebenes Währungszeichen, Validierung, Effizienz ohne
Skala, PDF in Englisch und für Österreich, Zeitzone der Wetterabfrage),
`FirstStartProfileTest` (Erststart über einen echten `php -S`-Server mit
fr-FR, en-GB und de-DE). Frontend ohne Server: `format.test.mjs` erweitert
(Region, Währung, Katalog-Platzhalter, Gas-Einträge; 62 Prüfungen). API-Shape
44/44 und Browser-Render 64/64 — neu: Länderprofile, Effizienzskala,
Länderauswahl, Brennwert-Einheit und die Umrechnungshilfe. **14 Schutzstellen per
Gegenprobe als greifend nachgewiesen.** Im Browser abgenommen:
Länderwechsel mit Dialog, Österreich (Formate, Effizienz ohne Klasse),
Vereinigtes Königreich (£, MJ/m³), Umrechnungshilfe — bei 1280 und 375 px.

**Doku** DE + EN: Kapitel Länderprofile, API-Referenz (Route, Einstellungen,
`scale`), Datenmodell, Gas, UI-Referenz mit neuen Screenshots, README.
45 neue Katalogschlüssel × 7 Sprachen; 36 Texte tragen die Währung jetzt als
Platzhalter (`{cur}`, `{minor}`, `{code}`).

### Lessons Learned

- **„Sprache-Land" ist nicht immer eine Region, die jemand will.** Der erste
  Entwurf bildete `${sprache}-${land}` — für Englisch mit dem Default-Land DE
  also „en-DE", und `Intl` schreibt dort deutsche Zahlen. Jede bestehende
  englische Installation hätte sie über Nacht bekommen. Ein älterer Test fiel
  darüber; die Regel lautet jetzt: Das Land verfeinert nur Sprachen, die dort
  gesprochen werden.
- **Ein Default-Profil ist ein Versprechen an alle Bestandsinstallationen.**
  Dass das deutsche Profil genau den bisherigen Defaults entspricht, prüft
  ein Test — sonst würde eine kleine Abweichung (Standortname, CO₂-Wert) still
  jede bestehende Installation verändern, sobald jemand „Alle übernehmen"
  anbietet.
- **Beim Erststart nur Abweichungen schreiben.** Wer alle Profilwerte
  festschreibt, friert die Defaults ein: Eine spätere Korrektur (etwa der
  CO₂-Faktoren) erreichte neue Installationen nicht mehr.

---

## [2.6.0] — 2026-09-25 — Anmeldung, Plausibilität, sichere Backups

MINOR-Release (N1013). Kein Schema-Bump, keine Datenmigration. Alles Neue ist
**opt-in oder additiv**: Wer nichts einschaltet, merkt an bestehenden
Schnittstellen nichts — außer dass Fehler jetzt früher und deutlicher
gemeldet werden.

**Der Anlass.** Paket B des Gesamtreviews (Sicherheit und Datensicherheit).
Drei Befunde wogen am schwersten: Ohne Anmeldung konnte jeder im Netz alles
lesen und löschen, und auf Apache-Instanzen waren `data/` samt Vollbackups
sowie `.git/` per HTTP abrufbar. Ein einziger falscher Zählerstand — etwa eine
0 aus Home Assistant — machte aus einem normalen Monat einen Verbrauch in Höhe
des ganzen Zählerstands. Und ein Backup-Import schrieb ungeprüft Topf für Topf;
ein fehlerhaftes Backup legte danach Übersicht und Auswertungen lahm.

### ⚠️ Für bestehende Installationen

- **Nichts einschalten = nichts ändert sich.** Die Anmeldung ist standardmäßig
  aus; Home Assistant, Skripte und Backups laufen unverändert weiter.
- **Apache/Synology:** Die `.htaccess` sperrt jetzt `data/`, `src/`, `.git/`
  und andere Nicht-Auslieferungsdateien (404). Voraussetzung wie bisher für die
  Cache-Regeln: `AllowOverride FileInfo` und `mod_rewrite`. Prüfen:
  [Sicherheit → Webserver](docs/betrieb/sicherheit.md).
- **Home Assistant:** Ein Wert, der **kleiner** ist als der vorige desselben
  Zählers, wird weiter angenommen (201), aber als Verdacht markiert und zählt
  erst nach Bestätigung. Wer die Anmeldung einschaltet, braucht für den Push
  einen Token.
- **Entwicklungsserver:** immer `php -S 127.0.0.1:8080 router.php` — ohne
  Router liefert PHP jede Datei aus, auch `data/`.

### Added — Anmeldung (opt-in)

- **Passwort-Anmeldung** in *Einstellungen → Anmeldung & Zugriff*: einschalten,
  ändern, ausschalten (mit dem bisherigen Passwort). Sitzungs-Cookie 30 Tage,
  HttpOnly, SameSite=Strict, `Secure` hinter HTTPS; nach fünf Fehlversuchen in
  15 Minuten 5 Minuten Sperre; ein neues Passwort meldet alle anderen Geräte ab.
  Anmeldebildschirm und **Abmelden** in der Kopfleiste.
- **Anmeldung über einen vorgeschalteten Proxy** (Authelia, Authentik …):
  `ET_AUTH=proxy`, Benutzer aus `Remote-User`/`X-Forwarded-User` — nur von
  Adressen in `ET_TRUSTED_PROXIES`.
- **API-Schlüssel für Skripte** (`Authorization: Bearer etk_…`), Berechtigung
  *Lesen* oder *Verwalten*, Klartext einmalig, „zuletzt benutzt".
- **Umgebungsvariablen** `ET_AUTH`, `ET_ADMIN_PASSWORD_HASH`,
  `ET_TRUSTED_PROXIES`, `ET_ALLOWED_HOSTS` (DNS-Rebinding → 421),
  `ET_FRAME_ANCESTORS`, `ET_DEBUG`.
- Mit eingeschalteter Anmeldung braucht der Home-Assistant-Push einen Token;
  `/api/health` antwortet ohne Anmeldung nur mit `{status, version}`.

### Added — Plausibilität der Zählerstände

- **Rückfragen vor dem Speichern** (Ablese-Dialog und Zählerstand-Erfassung):
  Tagesverbrauch über dem Dreifachen des üblichen, „Komma vergessen?",
  kleiner als der letzte Stand (mit Link zum Zählertausch), Datum in der
  Zukunft, schon ein Stand am selben Tag (**Ersetzen** statt doppeln).
  Hinweise schon beim Tippen; wer ablehnt, behält die Eingabe.
- **Ausreißer fallen aus der Rechnung.** Eingeklemmte Spitzen und Dellen
  desselben Geräts werden erkannt und übergangen; die Verbrauchsansicht nennt
  sie in einem Hinweis und markiert sie in der Tabelle. Bis v2.5.3 zählte das
  Intervall nach einer 0 den ganzen Zählerstand als Verbrauch.
- **Verdacht aus Home Assistant:** fallende Werte gespeichert, markiert
  („PRÜFEN"), per ✅ zu bestätigen.
- **Überlauf des Zählwerks:** Mit gepflegten *Stellen des Zählwerks* (Zähler
  bearbeiten) ist 99.998 → 12 ein Verbrauch von 14.
- Ein **fallender Stand ohne Tausch** wird gemeldet statt still verworfen.

### Added — Backups und Snapshots

- **Import mit Prüfung und Vorschau:** Jeder Topf wird vor dem Schreiben
  geprüft; ein fehlerhaftes Backup ändert nichts und nennt die Fundstellen.
  Die Vorschau zeigt, was eingespielt wird und was unverändert bleibt.
  Scheitert eine Datei mitten im Schreiben, werden die schon geschriebenen
  zurückgesetzt. Die Hülle von `GET /api/backup/export` wird akzeptiert.
- **Snapshots verwalten:** Liste mit Zeitpunkt, Anlass und Größe;
  herunterladen, einspielen (vorher Sicherung des jetzigen Stands), löschen.
  Aufräumen: eigene die letzten zehn, automatische 30 Tage (mindestens drei je
  Anlass). Scheitert der Sicherungs-Snapshot, wird nicht mehr still ohne
  Rückweg eingespielt (409, nur mit ausdrücklicher Zustimmung).
- **Ausgeblendete Empfehlungen** gehören jetzt zum Backup (Lektion 18).
- Große Backups: Snapshots entstehen gestreamt; das Docker-Image bringt eine
  `php.ini` mit (256 MB, 32 MB Upload, 120 s).

### Security

- Webserver-Regeln für Apache (`.htaccess`, `data/.htaccess`), nginx und den
  Entwicklungsserver: Nutzdaten, Quelltext, `.git/` und Punktdateien werden
  nicht mehr ausgeliefert.
- **Content-Security-Policy** mit Nonce, `frame-ancestors 'self'` (einbetten
  nur für freigegebene Adressen, Einstellung *Einbetten*),
  `Referrer-Policy`, `Permissions-Policy`, kein `X-Powered-By`.
- **Fehlerantworten ohne Interna:** Datei, Zeile und Pfade nur mit
  `ET_DEBUG=1`; ein 500 nennt eine Fehler-ID, die Einzelheiten stehen im Log.
- **Downgrade-Schutz:** Daten einer neueren Version werden erkannt; die App
  schreibt nichts (503) statt sie still auf das alte Schema zurückzustempeln.
- **CSV-Export:** Zellen, die mit `= + - @` beginnen, werden neutralisiert
  (Formel-Einschleusung in Excel).
- Docker: Anwendungscode gehört `root`, OPcache an, Build mit Provenance und
  SBOM; Dependabot schlägt Updates für Basis-Image und Actions vor.
- `.gitignore` als Whitelist für `data/` — Laufzeitdateien mit Nutzdaten
  können nicht mehr versehentlich ins Repository geraten.

### Changed — Schnittstellen (additiv)

- **Stabile Fehlercodes:** jede Fehlerantwort trägt `code` (Katalogschlüssel);
  Skripte werten ihn statt des übersetzten Texts aus.
- **Stabilitätszusage** mit drei Klassen (Fremdsysteme, Auswertungen,
  Oberfläche) und Regeln für Änderungen — siehe
  [API-Referenz](docs/referenz/api.md).
- `HEAD` wie `GET`, falsche Methode → `405` mit `Allow` (bisher 404);
  unbekannter Datensatz in der URL einheitlich `404` (bisher teils 400).
- `/api/health`: `status` (ok/degraded/error), `checks`, `last_ingest`,
  HTTP 503 bei `error` — der Docker-Healthcheck erkennt Störungen jetzt.
- Neue Felder: `warnings` (Verbrauch je Zähler), `is_suspect`/`source`
  (Ablesung), `digits` (Gerät), `typical_per_day`/`suspect_count`/
  `last_reading.id` (Erfassung), `suspect`/`previous` (Ingest),
  `last_used_at` (Token), `ignored_keys` (Einstellungen).
- Parametergrenzen für Prognose, Jahresbericht und Temperatur-Sync (400 statt
  stiller Übernahme).
- Die früher in `docs/API.md` beschriebenen Körper für Zähler (`device {…}`)
  und Zählertausch (`removed_on`, `final_counter`, `new_device {…}`) werden
  als Alias angenommen — bisher wurden sie still ignoriert bzw. abgelehnt.

### Fixed

- **CSV-Import:** Windows-1252-Dateien aus Excel (Umlaute) brachen mit HTTP 500
  mitten im Import ab; jetzt werden sie umgewandelt. Spalten werden an der
  Kopfzeile erkannt — auch das eigene Exportformat lässt sich wieder einlesen.
  Großimporte schreiben einmal statt je Zeile (2.000 Zeilen: rund 4 s → 15 ms,
  lokal gemessen).
- **Leistung:** `settings.json` wurde je Anfrage bis zu 44.000-mal gelesen
  (Gas mit datierten Brennwerten); Einstellungen und Faktoren werden jetzt
  zwischengespeichert, Verbrauchsrechnungen je Datenstand.
- **Service Worker:** API-Antworten wurden im Unterverzeichnis nie für den
  Offline-Betrieb gespeichert, im Wurzelbetrieb dagegen alle — auch das
  Vollbackup. Jetzt: nur eine Freigabeliste von Datenansichten, exakt samt
  Parametern, Downloads nie; offline zeigt die Kopfleiste „Offline – Stand
  vom …".
- **Atomares Schreiben mit `fsync`**; verwaiste Temp-Dateien älter als eine
  Stunde räumt der Health-Check auf.
- **Einstellungen:** Nach einem Import oder Sprachwechsel meldete ein
  veralteter Handler beim Schließen „ungespeicherte Änderungen".
- **Meldungen** lagen bei offenem Dialog über dem Speichern-Knopf und fingen
  dessen Klicks ab; jetzt erscheinen sie dann oben (auf dem iPhone über die
  volle Breite). Netzwerkfehler heißen „Keine Verbindung … – nichts
  gespeichert" statt „Failed to fetch".
- **Unbekannte Einstellungsschlüssel** wurden still verworfen; die Antwort
  nennt sie jetzt (`ignored_keys`).

### Migration

Keine. Schema bleibt 1.5.0. Neue Felder erscheinen erst, wenn sie gesetzt
werden; `data/auth.json` bekommt weitere Einträge erst beim Einschalten der
Anmeldung.

### Tests

285 → 324 Testmethoden (336 Fälle): `ReadingPlausibilityTest`
(Ausreißer, Verdacht, Überlauf, Ingest), `BackupSafetyTest` (Prüfung vor dem
Schreiben, Rückweg, Snapshot-Rotation, Abdeckung aller Schreibstellen),
`CsvImportRobustnessTest`, `LegacyBodyAliasTest`, `AuthFlowTest` (Anmeldung,
Sperre, Schlüssel und Ingest über einen echten `php -S`-Server),
`HealthCheckServiceTest` erweitert; `ReleaseConsistencyTest` prüft jetzt,
dass **jede Route** in der API-Referenz steht (DE und EN). Frontend ohne
Server: `tests/plausibility.test.mjs` (24 Prüfungen, in der CI).
API-Shape 41/41, Browser-Render 58/58. **13 Schutzstellen per Gegenprobe als
greifend nachgewiesen** (Code gebrochen → zugehöriger Test rot): Ausreißer,
Verdacht, Überlauf, Ingest-Markierung, Anmeldung, Host-Liste,
Fehlerdetails, Backup-Prüfung, Downgrade, Zeichensatz, Formel-Neutralisierung,
Alias, Routen-Doku. Im Browser abgenommen: Anmeldung samt
Fehlversuch und Sitzung über Neuladen, Schlüssel, Snapshots, Import mit
Vorschau und Fundstellen, 409-Rückfrage, Rückfragen bei der Erfassung,
Verdacht bestätigen, Zählwerk-Stellen — bei 1440, 393 und 375 px.

**Doku** DE + EN: neues Kapitel [Sicherheit & Netzbetrieb](docs/betrieb/sicherheit.md),
`SECURITY.md`, API-Referenz (Anmeldung, Fehlercodes, Stabilitätszusage,
12 neue Routen), `docs/API.md` an den Code angeglichen, Datenmodell,
Installation, Docker, Home Assistant, Zählerstände, UI-Referenz mit drei neuen
Screenshots; Warnhinweis und Schnellstart mit `router.php` in README und
INSTALL. 152 neue Katalogschlüssel × 7 Sprachen.

### Lessons Learned

- **Das negative Intervall zu verwerfen ist kein Schutz.** Die Rechnung warf
  nach einer 0 nur den Rückgang weg und zählte das nächste Intervall ab dem
  falschen Stand voll. Der Fehler sitzt im **Stand**, nicht im Intervall —
  also muss der Stand heraus (Lektion 6, andere Stelle).
- **Ein Schutz, der eine Tür bewacht, suggeriert ein Schloss.** Der
  Home-Assistant-Token schützte nur den Push, der Text in den Einstellungen
  legte nahe, er schließe die API. Und eine Anmeldung ohne Webserver-Regeln
  wäre über `data/backups/` zu umgehen gewesen — erst die Regeln, dann die
  Anmeldung.
- **Eine Routenliste ohne Test ist eine Behauptung.** `docs/API.md` kannte 37
  von 70 Routen, die Referenz behauptete „68, v1.9.2". Jetzt vergleicht ein Test
  die Routen des Codes mit der Referenz in beiden Sprachen.

---

## [2.5.3] — 2026-09-24 — Keine stillen Fehlbuchungen

PATCH-Release. Kein Schema-Bump, keine Datenmigration, keine geänderte
Schnittstelle. Neu abgelehnt wird nur, was bisher still falsch gespeichert
wurde.

**Der Anlass.** Ein Gesamtreview (Berechnungen, Oberfläche auf Mac und
iPhone, Übersetzungen, Doku, API) fand eine Fehlerklasse quer durch die App:
Eingaben, die nicht passen, wurden nicht abgelehnt, sondern still in etwas
Gültiges verwandelt. Ein leeres Zahlenfeld wurde zur 0, „12345,6" je nach
Browser zu gar nichts, ein nicht verfügbarer Home-Assistant-Sensor zu einem
Zählerstand 0, ein unlesbarer Abrechnungsstichtag zum 1. Januar. Jede dieser
Stellen erzeugt eine Buchung, die plausibel aussieht und Kosten, Saldo und
Prognose verfälscht, bis jemand sie findet. Dieses Release schließt sie.

### ⚠️ Für Home-Assistant-Nutzer

Die Vorlage in den Einstellungen und in `docs/HOME-ASSISTANT.md` enthielt bis
v2.5.2 `"value": {{ states(sensor_entity) | float(0) }}`. Nach einem
HA-Neustart oder Funkaussetzer zum Push-Zeitpunkt wurde damit ein Zählerstand
**0** gebucht; die nächste echte Ablesung zählte als Verbrauch eines einzigen
Tages. **Bitte die Vorlage ersetzen:** `| float` ohne Ersatzwert und vor jedem
Push die Bedingung `has_value(…)`. Die Einstellungen erzeugen die
Automatisierung jetzt fertig aus den Zähler-Aliasen. Außerdem funktionierte
`Authorization: "Bearer !secret …"` aus der Anleitung nie (Antwort `401`):
`!secret` wirkt nur als ganzer Wert — `Authorization: !secret
energietracker_auth`, in der `secrets.yaml` steht `"Bearer et_…"`.
Bestehende Konfigurationen laufen unverändert weiter; der Endpunkt
`POST /api/ingest` ist gleich geblieben.

### Fixed — stille Fehlbuchungen

- **Zahlenfelder** (Ablesung, zentrale Erfassung, Lieferung, Zählertausch,
  Zähler, Vertrag, Wasservertrag, Tarifangebot, Einstellungen, Gasfaktoren,
  Standort, Prognose, Termine): Text mit Dezimaltastatur statt
  `type="number"`, gelesen von einem gemeinsamen Parser, der Komma, Punkt und
  Tausendertrennung versteht. Leer bleibt leer, Unlesbares wird am Feld
  gemeldet — nie mehr eine stille 0.
- **Zählertausch:** Der Endstand ist Pflicht; der Dialog zeigt den letzten
  bekannten Stand, fragt bei einem kleineren Endstand nach und fasst den Tausch
  vor dem Ausführen zusammen. Ein leerer Endstand schloss das alte Gerät
  bisher mit 0 ab — nicht rückgängig zu machen.
- **Leerer Vertrag:** Anbieter oder Tarif sowie mindestens ein Arbeitspreis
  sind Pflicht. Ein versehentlich gespeicherter leerer Vertrag lief als
  „aktiv" und löste ab seinem Beginn den laufenden ab. Neu: Der Beginn ist mit
  dem Tag nach der laufenden Bindung vorbelegt, und bevor ein Vertrag einen
  laufenden ablöst, fragt die App nach. Standard- und Wasservertrag gleich.
- **Dialoge:** Ein Doppelklick auf „Speichern" legte zwei Datensätze an.
  Markieren über den Dialogrand hinaus schloss den Dialog samt Eingaben;
  Escape auf einer Rückfrage schloss auch das Formular darunter; nach
  „Zurück" blieb ein Dialog verwaist offen.
- **Ablesung vor dem Einbau des ersten Geräts** wurde abgelehnt („kein Gerät
  aktiv"). Das traf jede frische Installation, sobald man ältere Stände
  nachtrug — der Standardzähler gilt ab dem Installationstag. Jetzt wird das
  erste Gerät zurückdatiert; Lücken zwischen zwei Geräten bleiben ein Fehler.
- **Bonus mit Gutschrift nach Vertragsende** fiel aus Saldo und Tarifvergleich
  heraus. Er zählt jetzt im letzten Vertragsmonat.
- **CSV-Monatsexport:** Abschlag, Monats-Saldo, Saldo kumuliert und CO₂ waren
  immer leer.
- **Heizöl und Pellets:** „+ Ablesung" legte Datensätze an, die nirgends
  erschienen und nichts bewirkten. Dort steht jetzt „+ Lieferung".
- **Dashboard:** Monate ohne Daten erschienen im Verlaufschart als
  Nullverbrauch; jetzt als Lücke.
- **„Heute"** wurde teils in UTC berechnet — kurz nach Mitternacht war es noch
  gestern.
- **Charts** formatierten Zahlen nach der Browser-Sprache statt der
  App-Sprache („1,000" für tausend neben „1,000" für eins in der Tabelle).

### Security

- **Schreibende Anfragen von fremden Webseiten** werden abgelehnt (`403`,
  geprüft über `Sec-Fetch-Site`, ersatzweise `Origin`). Home Assistant, curl
  und Skripte senden diese Kopfzeilen nicht und sind nicht betroffen.
- **Gespeicherte Werte werden in der Oberfläche escaped**, auch in
  Formularfeldern. Ein unlesbares Datum erscheint als „–" statt roh im HTML;
  präparierte Werte aus einer fremden Backup-Datei konnten bisher Skripte
  ausführen.
- **Datumsfelder werden auf allen Schreibpfaden kalendergenau geprüft:**
  Ablesung, Zähler, Tausch, Vertrag samt Preiszeilen, Boni und
  Sonderzahlungen, Lieferung, Termin, Temperatur, CSV-Import und Ingest. Ein
  „2026-02-30" legte bisher Auswertungen, CSV und PDF lahm.

### Fixed — Speicher

- **Beschädigte Datendatei:** Sie wurde still als leer gelesen, und der
  nächste Schreibzugriff vernichtete den Bestand. Jetzt antwortet die App mit
  `503`, die Datei bleibt unangetastet, und daneben liegt eine
  Quarantäne-Kopie `<datei>.corrupt-<prüfsumme>`.
- **Gleichzeitige Schreibzugriffe** (etwa ein Home-Assistant-Push während
  einer Eingabe) konnten Änderungen verlieren. Eine globale Schreibsperre
  ordnet sie nacheinander.
- **Snapshot vor der Schema-Migration:** Beim ersten Start nach einem Update
  mit neuem Schema entsteht jetzt `data/backups/pre-migration-…`. Die Doku
  versprach diesen Snapshot seit Langem; es gab ihn nicht.

### Changed — Oberfläche

- **iPhone und kleinere Macs:** Die Seite lief in 13 von 21 Ansichten seitlich
  über (am Mac mit 1280 px in der Gas-Ansicht). Jetzt in keiner mehr — geprüft
  über alle 19 Routen bei 375, 393 und 1280 px.
- Nach einem Menüklick lag der **Seitentitel unter der Kopfleiste**, samt den
  Kopf-Aktionen.
- Die **Speichern-Leiste** der Zählerstand-Erfassung überdeckte am Mac die
  Seitenleiste.
- **Sonderzahlungen im Vertragsformular:** eigenes Raster statt zerbrochener
  Zeilen (das Datum war rund 25 px breit, Beträge abgeschnitten).
- **Home-Assistant-Karte:** REST-Command, Eintrag für die `secrets.yaml` und
  eine fertige Automatisierung aus den Aliasen, alle drei zum Kopieren.

### Changed — Texte

- **Glossar:** Das Saldo-Vorzeichen war umgekehrt. Richtig ist Kosten −
  Abschläge: positiv heißt Nachzahlung, negativ Guthaben (DE + EN).
- **Übersetzungen:** Wasser hatte in fünf Sprachen einen „Energiepreis";
  „Rückforderung" las sich in fünf Sprachen wie eine Gutschrift statt einer
  Rückzahlung; EN „Surcharge" (Aufschlag) für Nachzahlung und „Compensation"
  (Schadenersatz) für die Einspeisevergütung.
- **Update-Anleitung:** `docker compose pull` holt die in der
  `docker-compose.yml` eingetragene Version, nicht die neueste; der Rückweg
  nach einer Schema-Migration führt nur über das Backup.

### Schnittstellen

Unverändert: Endpunkte, Felder, Backup-Format 3.0, CSV-Formate,
Docker-Variablen. Neu abgelehnt wird nur, was bisher still falsch gespeichert
wurde: ungültige Kalenderdaten und Beträge, die keine Zahl sind (`400`),
schreibende Browser-Anfragen mit fremdem Ursprung (`403`), Zugriffe auf eine
beschädigte Datendatei (`503` statt leerer Liste).

### Migration

Keine. Schema bleibt 1.5.0.

### Tests

256 → 285 Testmethoden (297 Fälle): `CrossSiteGuardTest`,
`JsonStoreCorruptionTest`, `WriteLockTest` (vier Prozesse schreiben
gleichzeitig, kein Update geht verloren), `MigrationSnapshotTest`,
`InputValidationTest`, `BonusAttributionTest`, `CsvMonthlyExportTest`;
`ReadingEdgeCasesTest` auf die neue Semantik umgestellt. Neu und ohne
Server, beide in der CI: `tests/format.test.mjs` (Zahlenparser,
Datumsformat) und `tests/ha-snippet.test.mjs` — vergleicht die
Home-Assistant-Vorlage der App zeilenweise mit beiden Sprachfassungen der
Anleitung. Browser-Render 58/58. **Sieben Kernannahmen per Toggle als
greifend nachgewiesen**, die HA-Vorlage zusätzlich per Gegenprobe.

**Doku** DE + EN: Home Assistant, Glossar, Installation, Docker.
30 neue Katalogschlüssel × 7 Sprachen; 18 Schlüssel in einzelnen Sprachen
korrigiert.

### Lessons Learned

- **Lektion 24 lebte im Frontend weiter.** `type="number"` liefert für
  „12,5" je nach Browser einen leeren String, und `Number("")` ist 0. Zehn
  Formulare buchten so eine stille 0. Zahlen gehören als Text mit
  Dezimaltastatur erfasst und von **einem** Parser gelesen, der „leer"
  kennt.
- **Eine Vorlage, die Nutzer kopieren, ist Code.** Das HA-Snippet mit
  `float(0)` stand an zwei Stellen, und beide waren falsch. Es lebt jetzt in
  einem Modul, und ein Test hält die Anleitung daneben.
- **Ein Scroll-Rahmen beschneidet nur, was sich an ihm ausrichtet.** Das
  unsichtbare `.sr-only`-Label im Tabellenkopf ist absolut positioniert und
  entkam dem `overflow-x: auto` — die ganze Seite wurde breiter. Rahmen mit
  Überlauf bekommen `position: relative`.

---

## [2.5.2] — 2026-09-15 — Rechnungsprüfung: Zählerstände mit Ableseart

PATCH-Release (UX-Politur). Kein Schema-Bump, keine Datenänderung.

**Der Anlass.** Beim Abgleich zweier echter Jahresrechnungen mit der
Rechnungsprüfung (v2.5.0) fiel auf, woran der Vergleich hakt: Die Rechnung
schneidet ihre Zeilen an Brennwert- und Preisgrenzen mit **geschätzten**
Zwischenständen und markiert das per Fußnote — „vom Marktpartner
übermittelt (geschätzt)", „programmseitig hochgerechnet". Die App zeigte je
Abschnitt nur m³ und kWh; ob ein Abschnitt an einer echten Ablesung oder an
einem interpolierten Zwischenstand endet, war nicht zu sehen, und zum
Zählerstand der Rechnung gab es keine Zahl zum Danebenlegen.

**Neu.** Die Tabelle führt je Abschnitt **Stand alt** und **Stand neu**,
jeder Stand mit seiner **Ableseart** wie die Fußnoten der Rechnung: ohne
Zusatz ein abgelesener Stand, `S` eine als geschätzt erfasste Ablesung, `E`
ein **Ersatzwert** — an diesem Tag gibt es keinen Zählerstand, er ist
tagesgenau zwischen den umschließenden Ablesungen interpoliert. Das sind
genau die Stellen, an denen auch der Versorger schätzt; je weniger `E`,
desto weniger Schätzung steckt im Vergleich. Tooltip je Stand, Fußnote
unter der Tabelle. Über einen Zählertausch hinweg gibt es keinen
fortlaufenden Stand: der Ersatzwert bleibt dann ohne Zahl statt eine
falsche zu zeigen.

**API.** `bill-check`-Zeilen tragen `counter_from`/`counter_to` mit
`counter_from_kind`/`counter_to_kind` ∈ `reading`, `reading_estimated`,
`interpolated`, `null`.

**Tests.** 254 → 256: zwei PHPUnit-Fälle (Interpolation, geschätzte
Ablesung, Verkettung Stand neu = Stand alt; Zählertausch und Tage ohne
Intervall ohne Zahl), API-Shape (Felder, Ablesearten, Verkettung),
Browser-Render führt die Rechnungsprüfung jetzt wirklich aus (Spalten,
`E`-Kürzel mit Tooltip, abgelesene Stände ohne Kürzel, Fußnote). **Sieben
Kernannahmen per Toggle als greifend nachgewiesen.**

**Doku** DE + EN: Gas-Kapitel (Rechnungsprüfung), API-Referenz.
8 Katalogschlüssel × 7 Sprachen.

---

## [2.5.1] — 2026-09-15 — Sonderzahlungen in der Tabelle „Verträge & Abschläge"

PATCH-Release (UX-Politur). Kein Schema-Bump, keine Datenänderung.

**Der Anlass.** Die Tabelle *Verträge & Abschläge* in der Verbrauchsansicht
führte je Vertrag eine Spalte *Bonus*, aber keine Sonderzahlungen — eine
Rückzahlung aus der Jahresabrechnung stand nur in der Saldo-Karte des
laufenden Vertrags und verschwand aus dem Blick, sobald der Vertrag
vergangen war. Beim Nachrechnen einer echten Endabrechnung (v2.5.0,
Rechnungsprüfung) fiel das auf: Der Saldo eines abgeschlossenen Vertrags
war ohne die gebuchte Gutschrift nicht zu deuten.

**Neu.** Spalte **Sonderzahlungen** neben *Bonus*, für jeden Vertrag der
Historie: das **Netto aus Kundensicht** — Rückzahlungen erhalten positiv,
Nach- und Abschlagszahlungen geleistet negativ (dieselbe Größe, die der
Saldo als Sonderzahlungs-Netto addiert), bei mehreren Posten mit Anzahl.
Der Tooltip der Zelle listet jeden Posten mit Datum, Art, Betrag und Notiz;
ein Hinweistext unter der Tabelle erklärt *Bonus* und *Sonderzahlungen*.
Die Spalte erscheint nur bei Verbrauchsarten mit Abschlagsverträgen
(Gas, Strom, Fernwärme) — Wasser und PV-Einspeisung kennen keine
Sonderzahlungen, dort entfällt sie. Entschieden gegen eine gemeinsame
Spalte „Boni & Sonder": Bonus ist Vertragsbestandteil und mindert die
Kosten, eine Sonderzahlung ist Geld außerhalb des Abschlagsplans — zwei
Dinge, die in einer Zahl nicht mehr unterscheidbar wären. Die Tabelle
scrollt in schmalen Ansichten ohnehin horizontal; die Breite war kein
Grund, sie zu verschmelzen.

**API.** `contract-status` trägt je Vertrag `special_payments[]`
(`date`, `kind`, `amount_eur`, `note`; nach Datum sortiert, Beträge
positiv — die Richtung steckt in `kind`). Das Feld fehlt bei Wasser und
Einspeisung; sein Fehlen ist für die Oberfläche das Signal, die Spalte
nicht zu zeigen.

**Tests.** 250 → 254: `ContractStatusSpecialPaymentsTest` (Einzelposten und
Feldsatz, Netto-Vorzeichen, leere Liste, kein Feld bei Wasser), API-Shape
(Posten, Netto = Σ Rückzahlung − Σ gezahlt, Wasser ohne Feld),
Browser-Render (Spaltenkopf mit Erklärung, Zelle mit Netto und Tooltip,
Hinweistext, keine Spalte bei Wasser). **Neun Kernannahmen per Toggle als
greifend nachgewiesen**; ein zehnter Toggle deckte totes `abs()` auf — die
Beträge sind seit dem Speichern positiv — und wurde entfernt.

**Doku** DE + EN: Sonderzahlungen-Kapitel um „Wo Sonderzahlungen
erscheinen", UI-Referenz (Spalten der Vertragstabelle), API-Referenz und
API.md (`special_payments`). 3 Katalogschlüssel × 7 Sprachen.

---

## [2.5.0] — 2026-09-15 — F1012: Gas-Umrechnung mit Stichtagen und Rechnungsprüfung

MINOR-Release. **Schema 1.4.0 → 1.5.0** (additiv, Auto-Migration). Folge von
GitHub **#21** — der Melder wollte Gas in m³ erfassen; das ging immer
(v2.4.2 korrigierte nur das Etikett). Beim Nachsehen fiel auf, was wirklich
fehlte: ein Umrechnungsfaktor, der sich mit der Rechnung ändern darf.

**Das Problem.** Der Energietracker kannte **einen** Faktor
`gas_conversion_factor` für die ganze Historie. Eine Gasrechnung rechnet aber
mit zwei Größen — der **Zustandszahl** (an der Entnahmestelle, praktisch
konstant) und dem **Brennwert** (Periodenmittel des Netzbetreibers, wechselt
mehrmals im Jahr). Eine Jahresrechnung führt typischerweise drei bis vier
Brennwerte mit eigenen Zeiträumen. Wer den Faktor nachzog, veränderte damit
rückwirkend jeden Monat der Vergangenheit; wer ihn stehen ließ, hatte einen
kWh-Verbrauch, der zur Rechnung nicht mehr passte — Kosten je kWh, CO₂ und
Tarifvergleich inklusive.

**Die Lösung — F1012.**

- **Datierte Liste** `gas_conversion_factors` in den Einstellungen: je Eintrag
  Stichtag, Zustandszahl, Brennwert; der Faktor kWh/m³ wird daraus berechnet
  (fünf Nachkommastellen) — wer keine Aufschlüsselung hat, trägt ihn direkt
  ein. Die Zustandszahl wird aus dem letzten Eintrag vorbelegt.
- **Wirksam ist der letzte Eintrag, dessen Stichtag nicht nach dem Tag
  liegt.** Der undatierte Eintrag ist der migrierte Altwert und gilt für alles
  davor — die Historie rechnet exakt wie vor v2.5.0, nichts springt
  rückwirkend.
- **Tagesgenau.** Ein Stichtag mitten im Ableseintervall teilt das Intervall;
  jeder Tag rechnet mit seinem Faktor. Der Versorger tut dasselbe mit
  *geschätzten* Zwischenständen (Ableseart „S"); hier braucht es keine
  Schätzung, weil der Verbrauch ohnehin linear über das Intervall verteilt
  wird.
- **Plausibilitätsprüfung beim Speichern** (Zustandszahl 0,8–1,1, Brennwert
  8–13, Faktor 5–15 kWh/m³, höchstens ein undatierter Eintrag, keine
  doppelten Stichtage, Dezimalkomma erlaubt). Ein Tippfehler wie 115 statt
  11,5 hätte sonst jeden Verbrauch still verzehnfacht. Beim **Lesen** ist die
  Prüfung tolerant — ein Altbestand außerhalb der Bänder wird nicht wortlos
  durch den Default ersetzt.
- **Rechnungsprüfung** in der Gas-Verbrauchsansicht: für einen Zeitraum
  entstehen Abschnitte an jeder Ablesung und jedem Brennwertwechsel, je
  Abschnitt Tage · m³ · Zustandszahl · Brennwert · kWh/m³ · kWh — genau die
  Zeilen der Versorgerrechnung. Abschnitte ohne umschließende Ablesung
  erscheinen ohne Verbrauch, damit die Lücke sichtbar bleibt. Neuer Endpunkt
  `GET /api/utility/gas/meters/{id}/bill-check?from=&to=` (nur Gas).
- Heizöl und Pellets behalten ihren skalaren Faktor.

**Migration 1.4.0 → 1.5.0.** Der Skalar in `settings.json` wird zum
undatierten Listeneintrag; eine bereits vorhandene Liste bleibt unangetastet,
der Schritt ist idempotent. Als Lehre aus v2.4.1 hängt er in
`Migrator::UPGRADE_STEPS` — `needsMigration()` und `migrate()` lesen
dieselbe Liste, `MigrationCompletenessTest` wacht darüber. Der
v0.9.0-Legacy-Import liefert weiter den Skalar und wird im selben Lauf
umgewandelt.

**Oberfläche.** Einstellungen → Gas-Umrechnungsfaktoren als Tabelle mit
Vorschau des berechneten Faktors; Rechnungsprüfung als Karte in der
Gas-Verbrauchsansicht (Zeitraum vorbelegt mit dem gewählten Jahr). 48 neue
Katalogschlüssel × 7 Sprachen (2 entfallen), chirurgisch gesetzt.

**Demo-Daten** führen vier Perioden vor (undatiert 11,5 · 2024 · 2025 ·
Oktober 2025) — Verzeichnis und Backup, mit Gleichstands-Test.

**Tests.** 230 → 250. `ConversionFactorServiceTest` (19 Fälle: Ableitung,
Tagesauflösung, Grenzen, tagesgenaue Teilung am Monats- und mitten im Monat,
abgeleiteter Faktor schlägt mitgeschickten, Rechnungsaufschlüsselung mit
Lücken, Migration idempotent, Liste gewinnt über Skalar); API-Shape
(Settings-Form, `bill-check`-Zeilen, Strom → 400); Browser-Render (Tabelle,
JSON-Feld, Karte nur bei Gas). **12 Kernannahmen per Toggle als greifend
nachgewiesen** — zwei Tests wurden erst dadurch scharf (Stichtag auf
Monatsgrenze hatte die Teilung nie geprüft; die Ableitung war nie gegen
einen mitgeschickten Faktor getestet).

**Doku** DE + EN: `functional/01-gas.md` neu gefasst (Hintergrund, Stichtage,
Rechnungsprüfung), API-Referenz (Settings-Form, neuer Endpunkt),
Datenmodell/Glossar/Übersicht auf Schema 1.5.0, README-Funktionsliste
(F1011 dort nachgetragen).

---

## [2.4.2] — 2026-09-15 — Bugfix: Gas-Zählerstand war in der Erfassung mit kWh beschriftet

PATCH-Release. Kein Schema-Bump, keine Datenänderung. GitHub **#21**.

**Der Fehler.** Die zentrale Zählerstand-Erfassung (`#/zaehlerstaende`)
beschriftete das Eingabefeld für Gas mit „kWh". Ein Gaszähler zählt aber
Kubikmeter; kWh entsteht erst über den Umrechnungsfaktor aus den
Einstellungen. Gespeichert und gerechnet wurde **immer in m³** — nur das
Etikett war falsch. Die Zählerliste der Verbrauchsansicht zeigte korrekt m³.

**Die Ursache.** In der Utilities-SSOT gibt es zwei Einheiten je
Verbrauchsart: `unit` für den Zählerstand (Gas: m³) und `consumption_unit`
für den Verbrauch (Gas: kWh). Der Aggregat-Endpunkt `GET /api/readings-overview`
lieferte seit seiner Einführung (F1004, v1.6.0) **nur** `consumption_unit`. Die
Maske hatte damit keine andere Einheit zur Wahl und beschriftete den Stand mit
der des Verbrauchs. Unter den kumulativen Verbrauchsarten ist Gas die
**einzige**, bei der die beiden auseinanderfallen — Strom, Fernwärme und PV
zählen in kWh, Wasser in m³, jeweils identisch mit dem Verbrauch. Deshalb fiel
der Fehler nur bei Gas auf, und dort seit fünfzehn Releases nicht.

**Der Fix.** Der Endpunkt liefert jetzt beide Einheiten; die Maske beschriftet
Zählerstand, letzten Stand und Differenz-Vorschau mit `unit`. Keine Änderung
an Katalogen nötig — alle Texte tragen die Einheit als Platzhalter.

**Für Betroffene.** Wer dem Etikett gefolgt ist und Gasstände vor der Eingabe
selbst in kWh umgerechnet hat, hat Stände eingetragen, die um den
Umrechnungsfaktor zu hoch sind. Diese Ablesungen in der Verbrauchsansicht auf
den abgelesenen m³-Wert korrigieren; der Verbrauch berechnet sich danach
richtig.

**Tests.** Drei Schichten, jede per Toggle als greifend nachgewiesen:
`ReadingOverviewUnitsTest` (3 Fälle — beide Einheiten je Verbrauchsart gegen
die SSOT, und dass der Gasstand roh gespeichert und erst beim Verbrauch
umgerechnet wird), der API-Shape-Test verlangt `unit` und prüft für Gas
m³/kWh, der Browser-Render-Test verlangt m³ am Gas-Eingabefeld. Wird `unit`
aus der Antwort entfernt, werden alle drei rot; wird nur die Maske
zurückgedreht, der Render-Test. 227 → 230 Tests.

---

## [2.4.1] — 2026-08-20 — Hotfix: die v1.4.0-Migration lief auf Bestandsdaten nicht

PATCH-Release. Kein Schema-Bump, keine Datenänderung am Inhalt.

**Der Fehler.** In v2.4.0 wurde die neue Migrationsstufe `upgradeToV140()` in
`migrate()` eingehängt — aber nicht in `needsMigration()`, die Bedingung, die
`migrate()` überhaupt auslöst. Sie endete weiter bei v1.3.0.

Auf einer **Bestandsinstallation** (Schema 1.3.0, `external_id` vorhanden) lief
damit Folgendes:

1. `needsMigration()` meldete **false** — es gab aus ihrer Sicht nichts zu tun.
2. Der Bootstrap fiel in seinen dritten Zweig `!isAlreadyMigrated() →
   initFresh()`. Dieser Zweig ist als Netz für ein unvollständig angelegtes
   Verzeichnis gedacht und geht davon aus: „nicht migriert und keine Migration
   nötig" heiße „frisches Verzeichnis".
3. `initFresh()` schrieb `meta.json` mit der neuen Schemaversion, ohne die
   Zähler anzufassen.

Danach galt die Installation als migriert, obwohl kein Zähler das Feld
`baseline_events` trug — und die Migration konnte es **nie mehr nachholen**.
Die Anwendung lief weiter, weil alle Lesestellen über `?? []` abgesichert sind;
F1011 war dort schlicht nicht scharf.

**Nutzdaten waren nie in Gefahr.** `initFresh()` legt Dateien nur an, wenn sie
fehlen. Nachgemessen auf der betroffenen Installation: 48 von 49 Dateien
byte-identisch zum Stand davor, alle Zählungen (Ablesungen, Verträge, Zähler,
Lieferungen, Temperaturen, Einstellungen) unverändert. Verändert war
ausschließlich `meta.json` — erkennbar am `created_at` statt `migrated_at`.

**Der Fix.** Die Migrationsstufen stehen jetzt in **einer** Liste
(`Migrator::UPGRADE_STEPS`), aus der `needsMigration()` und `migrate()` beide
lesen. Eine neue Stufe ist ab jetzt ein Listeneintrag, sonst nichts.

**Warum kein Test das gefangen hat:** Alle bestehenden Migrationstests riefen
`needsVXXXUpgrade()` und `upgradeToVXXX()` **direkt** auf — also genau an
`needsMigration()` vorbei. Neu ist `MigrationCompletenessTest` (5 Fälle):

- Ein Reflection-Wächter hält die Liste vollständig: Jede
  `needsVXXXUpgrade()`-Methode **muss** eingetragen sein.
- Zu jeder Prüfmethode muss die Ausführmethode existieren.
- Eine Installation auf dem Vorgängerschema muss migrieren **wollen**.
- Nach der Migration trägt jeder Zähler das Feld, und `meta.json` hat
  `migrated_at` **und** kein `created_at` — Letzteres wäre das Zeichen, dass
  wieder der falsche Zweig lief.
- Ein zweiter Lauf ändert nichts.

222 → 227 Tests. Zwei Toggle-Beweise: Fehlt die v1.4.0-Stufe in der Liste, wird
der Wächter rot; wird `needsMigration()` auf die alte, doppelt gepflegte Form
zurückgedreht, wird der Bestandsdaten-Test rot.

---

## [2.4.0] — 2026-08-20 — Analyse-Zäsur: Auswertungen ab der Sanierung

MINOR-Release. **F1011** (GitHub #20), Schema 1.3.0 → 1.4.0 (additiv).

**Das Problem.** Nach einer baulichen Maßnahme ist ein Gebäude thermisch ein
anderes: Es braucht dauerhaft weniger je Kältegrad. Der Energietracker rechnete
bisher **immer über die volle Historie** — ein Zeitfenster gab es nirgends.
Damit beschreibt die Heizkurve keinen der beiden Zustände, sondern einen
gewichteten Mittelwert aus beiden, und zwar so lange, bis die neuen Monate die
alten überwiegen. Bei zwölf Jahren Historie dauert das Jahre.

Betroffen war weit mehr als das Diagramm. Acht Auswertungen hingen an derselben
Basislinie: die fünf Regressionsmodelle im Analyse-Chart, der Erwartungswert
`expected_hgt`, die Abweichung `delta_pct`, die Prognose (Heizkurve **und**
Saisonmittel), die Anomalie-Erkennung, die Empfehlungen R1/R2/R4 — und über die
Prognose auch der Tarifvergleich. Praktische Folge für Betroffene: Die App
meldete **jeden Monat** „unter dem Mittel", die Mehrverbrauchs-Empfehlung konnte
faktisch nicht mehr auslösen, und der erwartete Jahresverbrauch — die Eingabe
für jeden Tarifvergleich — fiel systematisch zu hoch aus.

**Die Lösung.** Am Zähler lassen sich **Analyse-Zäsuren** eintragen: ein Datum
mit einer Bezeichnung („Dachdämmung", „Wärmepumpe", „neue Fenster"). Wirksam ist
die späteste Zäsur, deren Datum erreicht ist; ein künftiges Datum darf
vorgemerkt werden und wirkt noch nicht. Ohne Zäsur verhält sich alles exakt wie
bisher.

- **Eine Markierung, alle Verbraucher.** Die Monatszeilen bekommen einmal ein
  `pre_baseline` — dort, wo bereits `device_swap` gesetzt wird. Jede Auswertung
  überspringt markierte Zeilen. Damit ist die Bereichsregel per `grep` prüfbar,
  statt in fünf Aggregatoren einzeln nachgebaut zu werden. Genau daran scheiterte
  die Subzähler-Regel aus F1006, die in `forUtility` stand und in drei weiteren
  Auswertungen nicht (behoben in v2.1.3).
- **Daten bleiben sichtbar.** Punkte vor der Zäsur werden im Chart ausgegraut
  statt entfernt — ausgeschlossen wird aus dem *Modell*, nicht aus der Anzeige.
  `weather_adjusted` wird für sie weiter berechnet: Der Wert ist
  gebäudeunabhängig und trägt den Vergleich.
- **Der Übergangsmonat zählt als „davor".** Fällt die Zäsur nicht auf den
  Monatsersten, mischt dieser Monat beide Zustände und bleibt aus dem Modell.
- **Wirkung der Maßnahme.** Beide Epochen werden getrennt gefittet und die
  Steigungen ausgewiesen — Verbrauch je Gradtag, also bereits
  witterungsbereinigt: *„0,42 → 0,28 m³ je Gradtag, −33 %."* Damit deckt der Code,
  was `docs/functional/08-szenario-eigenheim.md` §5 seit jeher als **wertvollste
  Anwendung** beschrieb und was bisher nichts ausrechnete. §5 ist entsprechend
  neu gefasst.
- **Drei stumme Untergrenzen sprechen jetzt.** Unter 12 Monaten entfällt die
  Wetterbereinigung komplett, unter 8 Punkten die Regression, unter 5 Monaten die
  Anomalie-Erkennung — bislang jeweils **wortlos**. Die Oberfläche schreibt nun
  aus, was fehlt und wie viel („Die Regression braucht mindestens 8 Messpunkte ab
  der Zäsur — vorhanden sind 5"). Der Hinweis greift **auch ohne Zäsur**: Wer
  schlicht erst sieben Monate Daten hat, bekommt dieselbe Erklärung. Kein stiller
  Rückfall auf die volle Historie — der wäre die gefährlichste Variante gewesen.

**Schema-Migration** 1.3.0 → 1.4.0: neues Zählerfeld `baseline_events` mit
Default `[]`, idempotent nach dem Muster von `external_id`. Das Feld reist in
`meters.json` automatisch im Backup mit; ein eigener Datentopf hätte in die
hartkodierte `BackupService`-Feldliste gemusst (Falle aus v2.1.2). Ein
Roundtrip-Test hält es trotzdem fest.

**Tests:** neue `BaselineCutoffTest` mit 25 Fällen, dazu ein Demo-Daten-Test.
196 → 222. **17 Kernannahmen per Toggle als greifend nachgewiesen** — jede
Manipulation ging rot, die Syntax jeder manipulierten Datei wurde geprüft, damit
kein Rotlauf durch einen Syntaxfehler als Beweis durchgeht. Zusätzlich 28
Prüfungen über echtes HTTP gegen einen laufenden Server und ein Browser-Durchlauf
der Analyse- und Zählersicht.

**Demo-Daten** führen die Zäsur vor (Gas-Hauptzähler, 2024-08-01), damit der
Vorher/Nachher-Vergleich nach „Demo laden" tatsächlich zu sehen ist — in
Verzeichnisform **und** im Demo-Backup, mit Test auf Gleichstand.

**Sprachen:** 26 neue Katalogschlüssel × 7 Sprachen, chirurgisch in die Kataloge
gesetzt statt sie neu zu formatieren — die Kataloge sind handgepflegt, kein
Dumper reproduziert sie byte-gleich (nachgemessen).

**Doku** DE und EN nachgezogen: Datenmodell, API-Referenz, Architektur,
Szenario-Kapitel.

---

## [2.3.5] — 2026-08-03 — README zeigt, was das Projekt kann

PATCH-Release. Nur Dokumentation und Tests.

### Added

- **Statusabzeichen im README** (DE und EN): CI und Docker-Publish als
  Live-Badges aus GitHub Actions, dazu Version, Lizenz, PHP-Anforderung,
  Testzahl, PWA, Docker-Architekturen, Sprachen, Verbrauchsarten und
  Abhängigkeiten. Bisher standen dort drei Abzeichen; die Eigenschaften, die
  das Projekt ausmachen — installierbar als PWA, multi-arch-Container, sieben
  Sprachen, acht Verbrauchsarten, **null Laufzeit-Abhängigkeiten** — waren nur
  im Fließtext zu finden.

- **Zwei Tests, die die Abzeichen ehrlich halten**
  (`ReleaseConsistencyTest`):
  - Die Zahlen für Tests, Sprachen und Verbrauchsarten werden gegen die
    Wirklichkeit geprüft (Testmethoden unter `tests/unit/`, Kataloge unter
    `public/locales/`, Einträge in der Utilities-SSOT). Ein Abzeichen, das
    „196 Tests" behauptet, während es 210 sind, sieht nach geprüfter
    Information aus und ist deshalb schlimmer als keines.
  - Die CI-Abzeichen müssen auf Workflows zeigen, die es gibt — sonst meldet
    GitHub dauerhaft „no status".

### Fixed

- **„Chart.js via CDN" stimmte seit v2.2.0 nicht mehr.** Beide READMEs
  schränkten die Aussage „keine externen Abhängigkeiten" mit einem Verweis auf
  ein CDN ein. Chart.js und die Schriften liegen seit v2.2.0 unter
  `public/vendor/` im Repository; die Anwendung stellt keine externen Anfragen
  — geprüft durch `testFrontendHasNoExternalResourceReferences`. Der Text sagt
  das jetzt auch.

194 → 196 Tests. **Kein Schema-Bump** — Schema bleibt 1.3.0.

---

## [2.3.4] — 2026-08-03 — Release-Prozess entrümpelt

PATCH-Release. Nur Dokumentation, kein Codeänderung.

### Changed

- **Der ZIP-Bau ist aus dem Release-Prozess entfallen** (DE und EN). Seit
  v2.0.0 trägt kein Release mehr Anhänge; die Installation läuft über das
  Container-Image von GHCR, `git clone` oder `git checkout` eines Tags. Die
  Beschreibung stand seit v1.4.2 unverändert da und beschrieb einen Ablauf,
  den es nicht mehr gab.

- **Das CI-Gate steht jetzt ausdrücklich zwischen Push und Tag.** Bisher
  zeigte die Anleitung `git push origin main --tags` — beides in einem
  Schritt. Damit entsteht der Tag, bevor die Pipeline ihn bestätigt hat, und
  er ist die Grundlage für Container-Image und GitHub-Release. Der Ablauf ist
  jetzt in drei Schritte getrennt, mit dem Gate dazwischen.

- **Der Smoke-Test läuft gegen eine Kopie der Demo-Daten** statt gegen ein
  entpacktes Archiv — über `ET_DATA_DIR`, nie gegen das lokale `data/`, in dem
  echte Nutzdaten liegen.

Keine Auswirkung auf Anwendung, Daten oder Schema. 194 Tests unverändert.

---

## [2.3.3] — 2026-08-03 — Beispieldaten in der Dokumentation

PATCH-Release. Kein Codeänderung, keine Funktionsänderung.

### Changed

- **Die Migrationsdoku (`MIGRATION-FROM-V090.md`, DE und EN) zeigte Datensätze
  aus einer realen Installation** statt erfundener Beispiele — mit Vertrags-
  und Ablese-IDs, Anbieter- und Tarifnamen, einem Zählerstand, einem
  Abschlagsbetrag und einer Nummer im `notes`-Feld, die wie ein Zählpunkt
  aussieht. Die Struktur der Beispiele ist unverändert; nur die Werte sind
  jetzt eindeutig fiktiv.

- **CHANGELOG und Roadmap** beschrieben Befunde aus einer konkreten
  Installation mit Vertragslaufzeiten und Beträgen. Die Sachverhalte bleiben
  vollständig erhalten, die Daten sind entfernt.

Zur Klarstellung: Nutzdaten waren zu keinem Zeitpunkt versioniert. Unter
`data/` liegen ausschließlich vier leere `.gitkeep`-Dateien; `.gitignore`
schließt Ablesungen, Verträge, Zähler, Lieferungen, Temperaturen,
Einstellungen und Backups aus, und die Historie enthält nie etwas anderes.
Betroffen waren allein die oben genannten Beispiele und Beschreibungen.

**Regel für künftige Releases:** Befunde aus einer konkreten Installation
gehören nicht mit Laufzeiten, Anbietern, Nummern oder Beträgen in Repository,
Commit-Message oder Release-Notes. Sachverhalt beschreiben, Daten weglassen.

---

## [2.3.2] — 2026-08-03 — Überlappende Verträge werden deterministisch aufgelöst

PATCH-Release aus einem Qualitätsdurchlauf über Code, Kataloge und Doku.

### Fixed

- **Bei überlappenden Verträgen entschied die Speicherreihenfolge, welcher
  Tarif gilt.** `ContractService::findActiveForDate()` nahm den ersten
  passenden Eintrag des Arrays — und dessen Position ergibt sich aus der
  Anlage-Reihenfolge in der JSON-Datei, nicht aus der Fachlichkeit. Dieselbe
  Datenlage konnte damit unterschiedliche Kosten ergeben, je nachdem, in
  welcher Reihenfolge die Verträge irgendwann gespeichert wurden.

  Jetzt gewinnt der **späteste Beginn**: Ein neu abgeschlossener Vertrag löst
  den älteren ab. Ohne Überlappung ändert sich nichts.

  Der Fall ist praxisrelevant und nicht konstruiert: Ein Vertrag, der
  vollständig innerhalb der Laufzeit eines anderen liegt, entsteht leicht
  beim Nachtragen älterer Verträge. Die Methode wird von vier Services genutzt
  (Verbrauch, Prognose, Tarifvergleich, Wechselentscheidung), die Auflösung
  wirkt also auf Kosten, Prognose und Wechselempfehlung gleichermaßen.

### Tests

- `OverlappingContractsTest` (5 Fälle): späterer Beginn gewinnt unabhängig von
  der Array-Reihenfolge, außerhalb der Überlappung gilt weiter der verbleibende
  Vertrag, ohne Überlappung bleibt alles wie zuvor, unbefristete Verträge
  greifen weiterhin, und die Kostenrechnung setzt je Monat genau einen Vertrag
  an — nie beide zugleich.
- 189 → 194 Tests.

**Kein Schema-Bump** — Schema bleibt 1.3.0.

---

## [2.3.1] — 2026-08-03 — Hotfix: Tarifvergleich startete nicht, Folgeverträge wurden übergangen

PATCH-Release. Behebt zwei Fehler aus v2.3.0, beide erst im laufenden Betrieb
aufgefallen, plus eine Lücke in der Vertragspflege.

### Fixed

- **Der Tarifvergleich startete nicht: „api.tariffSwitch is not a function".**
  Der Server lieferte die richtige `api.js` aus, der Browser nutzte eine
  gecachte Vorversion ohne die neue Funktion.

  Die Wurzel lag tiefer als der v2.2.3-Fix reichte: Der Cache-Buster hing nur
  am Einstiegspunkt `app.js`. Die Module importieren einander ohne Query
  (`./api.js`, nicht `./api.js?v=…`), also durfte jeder Cache eine beliebig
  alte Kopie liefern. Die Selbstheilung aus v2.2.3 räumt zwar die Cache-API
  auf, nicht aber den **HTTP-Cache des Browsers** — und Produktion sendete für
  Module überhaupt kein `Cache-Control`, nur ETag und Last-Modified. Safari
  cachte entsprechend heuristisch.

  Zwei Maßnahmen:

  1. **Import-Map.** Die Shell erzeugt aus dem Dateibestand eine Map, die jeden
     Modulpfad auf `?v=<version>-<mtime>` abbildet. Weil der Browser
     Import-Specifier vor dem Laden auflöst, bekommt damit auch ein Import tief
     im Graphen seine Version — ohne dass eine einzige `import`-Zeile angefasst
     werden muss.
  2. **Cache-Header.** Eine `.htaccess` (Apache/Synology) und die
     Docker-nginx-Konfiguration lassen `/public/js/` und `/public/locales/`
     immer revalidieren. Mit ETag kostet das ein 304, keine erneute
     Übertragung. Schriften bleiben langlebig gecacht.

- **Bereits abgeschlossene Folgeverträge wurden übergangen.** Lief ein
  Anschlussvertrag schon, meldete das Modul dessen Beginn als „Wechseltermin" —
  obwohl der Wechsel damit längst vollzogen war. Schlimmer: Die Vergleichsbasis
  rechnete das ganze Folgejahr mit den Preisen des **auslaufenden** Vertrags
  weiter, obwohl der neue galt. Je nach Preisunterschied weicht die Referenz
  dadurch um einen dreistelligen Betrag pro Jahr ab.

  Der Vergleich folgt jetzt der **Bindungskette**: dem laufenden Vertrag plus
  allem, was lückenlos anschließt. Deren Ende bestimmt Kündigungstermin und
  Wechseldatum, und jeder Monat des Vergleichsfensters rechnet mit dem Tarif,
  der dann gilt. Eine Lücke von mehr als einem Tag beendet die Kette. Die
  Oberfläche weist einen abgeschlossenen Anschlussvertrag aus.

### Added

- **Kündigungsfrist, Mindestlaufzeit und Preisgarantie im Vertragsformular.**
  Bisher ließen sich diese Felder nur beim Anlegen eines Angebots im
  Tarifvergleich setzen — an bestehenden Verträgen waren sie unerreichbar, und
  ohne sie kann der Vergleich weder einen Termin errechnen noch vor einer
  ablaufenden Frist warnen.

### Tests

- `ModuleCacheSafetyTest` prüft die Import-Map (vorhanden, vor dem ersten
  Modul-Script, genau eine, aus dem Dateibestand erzeugt) und dass beide
  Server-Konfigurationen Anwendungscode revalidieren lassen.
- `TariffSwitchServiceTest` um vier Fälle erweitert: Folgevertrag verschiebt
  den Termin, Referenz nutzt dessen Preise, eine Lücke beendet die Kette, und
  ein Fenster über zwei Verträge rechnet jeden Monat mit dem richtigen Tarif.
- 183 → 189 Tests.

---

## [2.3.0] — 2026-08-03 — Tarifvergleich wird zur Wechselentscheidung

MINOR-Release. Das Modul beantwortete bisher „Was hätte Tarif X gekostet?" —
eine Frage über die Vergangenheit. Jetzt beantwortet es „Soll ich wechseln?".

Kein Schema-Bump: Die neuen Vertragsfelder sind additiv und optional,
Bestandsdaten bleiben unverändert gültig (Schema bleibt 1.3.0).

### Added

- **Wechselentscheidung als neuer Block im Tarifvergleich.** Die Ansicht führt
  jetzt den Ablauf, um den es geht: Der **erwartete Jahresverbrauch** aus der
  Prognose steht groß und kopierbar oben — genau die Zahl, die CHECK24 und
  Verivox als Eingabe verlangen. Der Nutzer geht damit raus, sucht selbst und
  trägt das gefundene Angebot als Schattenvertrag ein. Eine Anbindung an
  Vergleichsportale gibt es bewusst nicht.

- **Wechseltermin und Kündigungsfrist.** Verträge tragen optional
  `notice_period_months`, `min_term_end` und `price_guarantee_until`. Daraus
  errechnet die Anwendung den frühestmöglichen Wechseltermin und den Stichtag,
  bis zu dem gekündigt werden muss — mit Restlaufzeit und Hervorhebung, sobald
  es eng wird. Ohne gepflegte Frist wird kein Termin behauptet, sondern nach
  der Angabe gefragt. Im Vergleich lässt sich das Datum frei überschreiben.

- **Jahr 1 und ab Jahr 2 getrennt.** Angebote tragen den Neukundenbonus als
  Betrag (`signup_bonus_eur`) statt als Gutschriftsdatum — auf dem Portal steht
  „Bonus 130 €", wann er gutgeschrieben wird, weiß beim Anlegen niemand.
  **Sortiert wird nach dem dauerhaften Preis**, sonst gewinnt jedes
  Lockangebot die Rangfolge.

- **Break-even-Verbrauch** statt einer Ersparnis auf den Euro genau: „günstiger,
  solange über 3.600 kWh". Das ist die belastbare Antwort auf eine unsichere
  Prognose — liegt der Schnittpunkt weit vom erwarteten Verbrauch weg, trägt
  die Entscheidung auch dann, wenn die Prognose danebenliegt. Ergänzend eine
  Spanne für ±10 % Verbrauch.

- **Kostenverlauf als Overlay.** Die Angebote werden als Linie über den
  Bestandsvertrag gelegt, monatlich statt als Jahressumme — erst daran sieht
  man, wo die Differenz herkommt (bei Gas fast vollständig im Winter). Monate
  jenseits der Preisgarantie sind gestrichelt: Dort ist der Preis eine Annahme.

- **Neuer Endpunkt** `GET /api/utility/{u}/meters/{id}/tariff-switch`,
  optional mit `?switch_date=YYYY-MM-DD`.

- **Demo-Daten** enthalten ein vollständiges Wechselszenario: Kündigungsfrist
  am laufenden Vertrag und je zwei gegenläufig gebaute Angebote für Gas und
  Strom (niedriger Arbeitspreis bei hohem Grundpreis und umgekehrt), damit der
  Break-even in der Demo sichtbar wird statt theoretisch zu bleiben.

### Fixed

- **Kündigungsfristen verfehlten den Stichtag um bis zu vier Wochen.** PHPs
  `strtotime('2026-03-31 -1 month')` liefert **2026-03-03**: Der 31. Februar
  existiert nicht, der Überlauf bleibt stehen. Die Monatsarithmetik klemmt den
  Tag jetzt auf das Monatsende. Der Fehler entstand mit diesem Release und
  wurde vor der Auslieferung gefunden — für einen Nutzer, der sich auf den
  Stichtag verlässt, hätte er ein weiteres Vertragsjahr bedeutet.

### Changed

- **Der Rückblick bleibt, rückt aber nach unten** und ist eingeklappt.
  Dieselben Tarife auf die tatsächlich gemessenen Monate gelegt — er ist der
  Beleg, dass die Rechnung auf echten Daten aufgeht, aber nicht der Grund,
  warum jemand die Ansicht öffnet.

- **Vergleichsfenster sind zwölf Monate ab Wechseltermin**, saisonal gewichtet
  statt in Zwölfteln. Ein Wechsel zum 1. Juli deckt damit trotzdem einen vollen
  Winter ab.

### Tests

- `TariffSwitchServiceTest` (17 Fälle): Wechseltermin aus der Kündigungsfrist
  inklusive Monatsüberlauf, saisonale Verteilung, Bonus nur im ersten Jahr,
  Rangfolge nach dem dauerhaften Preis, Break-even gegen die analytische
  Lösung, Preisgarantie-Markierung — und als Regression, dass Schattenverträge
  weiterhin **nicht** in die Prognose selbst einfließen (v2.2.0-Fix).
- `SwitchFieldsBackupTest` (2 Fälle): Die neuen Vertragsfelder überstehen einen
  Backup-Roundtrip, und Backups von vor v2.3.0 lassen sich weiterhin
  importieren. Anlass ist v2.1.2, wo eine hartkodierte Feldliste im
  `BackupService` drei Datentöpfe lautlos verschluckt hat — ein verlorener
  Kündigungstermin fällt erst auf, wenn die Frist verstrichen ist.
- Der Browser-Render-Test der CI prüft die Ansicht jetzt mit sechs Zusicherungen
  statt einer: Wechselblock gefüllt, Jahresverbrauch sichtbar und kopierbar,
  Wechseltermin wählbar, Rückblick vorhanden und mit Zeilen. Er hing zuvor an
  einem Element (`#t-result`), das es nach dem Umbau nicht mehr gibt.
- 164 → 183 Tests (PHPUnit) plus 42 Browser-Render-Zusicherungen.

---

## [2.2.3] — 2026-08-03 — Hotfix: Oberfläche blieb nach dem Update bei „Lädt…"

PATCH-Release. Behebt einen Fehler, der bestehende Installationen nach dem
Update auf v2.2.0 oder neuer unbrauchbar machte.

### Fixed

- **Nach dem Update blieb die Oberfläche bei „Lädt…" stehen.** Die ES-Module
  importieren einander ohne Cache-Buster (`./lib/sidebar.js`, nicht
  `…?v=2.2.2`). Der Service Worker lieferte sie unter
  `stale-while-revalidate` aus dem alten Cache aus, während die Shell bereits
  neu war. Ergebnis: Ein frisches `app.js` importierte `refreshSidebarBadges`
  aus einem gecachten `sidebar.js` der Vorversion, das diesen Export nicht
  kennt — `SyntaxError`, der Modulgraph brach vollständig ab, und es blieb bei
  der Ladeanzeige. Betroffen war jede Installation mit installiertem Service
  Worker, also praktisch jede laufende Instanz.

  Zwei Maßnahmen:

  1. **Selbstheilung.** Ein kleines Skript in der Shell läuft vor den Modulen.
     Trägt ein Cache eine andere Version als die ausgelieferte Seite, räumt es
     Caches und Worker ab und lädt genau einmal neu (Sperre in `sessionStorage`
     gegen Schleifen). Bestehende kaputte Installationen reparieren sich damit
     beim nächsten Aufruf von selbst — ohne Zutun der Nutzer.
  2. **Ursache beseitigt.** Anwendungscode und Sprachkataloge (`/public/js/`,
     `/public/locales/`) laufen im Service Worker jetzt **network-first** statt
     stale-while-revalidate. Ein einzelner veralteter Baustein legt die ganze
     Anwendung lahm; das ist den Geschwindigkeitsvorteil nicht wert. Stile,
     Schriften und Chart.js bleiben stale-while-revalidate — sie stehen für
     sich und reißen nichts mit. Offline funktioniert unverändert über den
     Cache-Rückfall.

  Nachgestellt und geprüft: Installation auf v2.1.5 mit aktivem Worker, Wechsel
  auf v2.2.2 → `SyntaxError`, Ladeanzeige. Mit dem Fix heilt derselbe Browser
  beim ersten Aufruf, ohne Konsolenfehler, alte Caches abgeräumt.

---

## [2.2.2] — 2026-08-03 — Demo-Termine, vollständige Testabdeckung

PATCH-Release. Die Demo-Daten zeigen jetzt auch das Termin-Modul, und die
letzten beiden Dienste ohne Test haben einen bekommen. Keine Schema- oder
API-Änderung.

### Fixed

- **Die Demo-Daten brachten keine Termine mit.** Weder
  `demo-data/reminders.json` noch das Demo-Backup führten Einträge, sodass die
  Termin-Ansicht nach „Demo laden" leer blieb — obwohl die Anwendung das Modul
  mitbringt. Dieselbe Klasse wie die fehlenden Heizöl- und Pellets-Lieferungen
  in v2.1.2: Das Backup trägt eine feste Feldliste, und ein neuer Datentopf
  muss dort mitgezogen werden. Jetzt sechs Termine über fünf Kategorien, mit
  überfälligem, fälligem und ruhendem Eintrag, damit die Statusfarben sichtbar
  werden.

### Added

- **`MigrationServiceTest`** (10 Fälle) — der v0.9.0-Migrationspfad war der
  einzige Weg, über den fremde Bestandsdaten hereinkommen, und hatte keinen
  Test. Geprüft: Formaterkennung samt Ablehnung unbekannter Versionen,
  Übersetzung ins aktuelle Schema, die Zählerwechsel-Heuristik (nur explizite
  Hinweise werden Kandidat, `is_notable` allein nicht), beide Schreibmodi und
  die Sicherheitskopie vor jedem Schreiben.
- **`PdfReportServiceTest`** (5 Fälle) — inklusive der Subzähler-Regel aus
  v2.1.3, die im Bericht bisher nur „analog" abgedeckt war. Die Prüfung liest
  die gedruckten Zahlen direkt aus dem Dokument: Ohne den Ausschluss stehen dort
  1.680 statt 1.200 kWh. Dazu: gültiges Dokument, leerer Jahrgang ohne Fehler,
  Aufbau in allen sieben Sprachen ohne unaufgelöste Katalogschlüssel und das
  Weglassen abgeschalteter Verbrauchsarten.
- **`DemoServiceTest`** um drei Fälle erweitert: Termine kommen aus dem Backup,
  decken mehrere Kategorien und Zustände ab, und Verzeichnis- wie Backup-Pfad
  führen dieselben Einträge — die beiden Wege liefen bisher auseinander.

**Damit haben alle 29 Dienste einen Test.** PHPUnit 143 → 161 (721 Assertions).

PATCH-Release. Der Rest der Backend-Texte ist katalogisiert, ein sprachabhängiger
Fehler im HTTP-Status behoben, und die UI-Referenz zeigt wieder den echten Stand.
Keine Schema- oder API-Änderung.

### Fixed

- **Der HTTP-Status hing an der Anzeigesprache.** `ErrorHandler::statusFor()`
  leitete „nicht gefunden" aus dem Wortlaut der Ausnahme ab
  (`str_contains($msg, 'nicht gefunden')` bzw. `'not found'`). Seit v2.0.0
  werfen die Dienste lokalisiert — eine spanische Oberfläche meldet „Contador no
  encontrado", eine französische „Compteur introuvable". Beide Muster griffen
  nicht, und der Client bekam **500 statt 404**: ein fehlender Datensatz sah aus
  wie ein Serverfehler. Jetzt entscheidet der Typ (`Http\NotFoundException`);
  die Textprüfung bleibt als Rückfall.

### Added

- **Die restlichen nutzersichtbaren Backend-Texte sind katalogisiert.** Alle acht
  Controller haben jetzt Zugriff auf den Übersetzungsdienst: Zähler- und
  Vertragsmeldungen, der Hinweis nach dem Erzeugen eines API-Tokens, die
  Rückmeldungen der v0.9.0-Migration, die Temperatur- und CSV-Importfehler sowie
  die Kopfzeilen des Monatsexports folgen der eingestellten Sprache. Auch die
  Bezeichner in den Vertragsprüfungen („Arbeitspreis", „Trinkwasser-Grundpreis" …)
  erschienen bisher deutsch in einer sonst übersetzten Fehlermeldung.
- `NotFoundStatusTest` hält fest, dass der Typ auch in einer Sprache greift,
  deren Wortlaut weder dem deutschen noch dem englischen Muster entspricht.

### Changed

- **Alle 13 Screenshots der UI-Referenz neu aufgenommen.** Die bisherigen
  stammten aus v1.9.2 und zeigten unter anderem den Tarifvergleich mit dem
  Rechenfehler, den v2.2.0 behoben hat (viermal derselbe Verbrauch bei völlig
  verschiedenen Kosten). Die neuen zeigen den aktuellen Stand samt der
  Verbrauchsart-Farben für alle acht Arten. Trotz größerem Bildausschnitt sind
  sie mit 824 KB kleiner als die alten 3,4 MB.

### Bewusst nicht übersetzt

Vier Bereiche bleiben deutsch, jeweils aus einem Grund:

- `JsonStore` (Speicherfehler) — eine Übersetzung erzeugte eine
  Zirkelabhängigkeit: JsonStore → I18n → Settings → JsonStore.
- `Storage\Migrator` (Migrationsprotokoll in `meta.json`) — Betriebsdokumentation,
  die einmal geschrieben und nie neu übersetzt wird.
- `Router` (unbekannte Route) — läuft vor der Anwendungsschicht und richtet sich
  an Entwickler; jetzt englisch statt deutsch.
- Ausnahmen, die Programmierfehler melden (`DeliveryConsumptionService`,
  `Utilities::get()`) — sie erreichen nie eine Oberfläche.

---

## [2.2.0] — 2026-08-03 — Tarifvergleich neu, Farben aus der SSOT, eigene Assets

MINOR-Release aus einem vollständigen Review von Code, Oberfläche, Sprachen,
Tests und Dokumentation. Vier stille Rechenfehler behoben, der Tarifvergleich
neu aufgesetzt, die Verbrauchsart-Farben ändern sich sichtbar, Schriften und
Chart.js kommen nicht mehr von fremden Servern. Keine Schema- oder
Datenänderung (Schema bleibt 1.3.0); die API des Tarifvergleichs liefert
zusätzliche Felder, die bestehenden behalten ihre Bedeutung.

### Changed — Tarifvergleich

- **Jede Kennzahl bezieht sich jetzt auf die Monate, die der Vertrag wirklich
  abdeckt.** Bisher meldete jede Zeile den Gesamtverbrauch des Zeitraums,
  rechnete die Kosten aber nur über die eigenen Monate. Ein Schattenvertrag ab
  Juli zeigte damit den vollen Jahresverbrauch neben einem halben Jahr Kosten —
  und wirkte etwa doppelt so günstig, wie er ist. Im Reproduktionsfall wurden
  49 % Ersparnis ausgewiesen, wo es real rund 15 % waren.
- **Neue Spalte „ct/Einheit"** — Vollkosten je kWh bzw. m³ aus Arbeitspreis,
  Grundpreis und Boni. Sie ist die einzige zeitraumunabhängige Größe und macht
  unterschiedlich lange Laufzeiten überhaupt erst vergleichbar; die Rangfolge
  richtet sich nach ihr.
- **Hochrechnung auf die volle Periode** bei Teilabdeckung, damit „was hätte
  das ganze Jahr gekostet?" beantwortbar bleibt, ohne die Ist-Zahlen zu
  verfälschen. Das Balkendiagramm vergleicht auf dieser Basis.
- **Die Differenz geht gegen die real abgerechneten Kosten derselben Monate**,
  nicht mehr gegen die Summe aller echten Verträge des Zeitraums. Bei einem
  Anbieterwechsel im Jahr wies vorher jeder echte Vertrag eine Differenz gegen
  sich selbst aus. Zusätzlich als Prozentwert.
- **Schattenverträge lassen sich im Modul bearbeiten und löschen**, mit
  Ende-Datum. Bisher konnte man sie nur anlegen — und wurde sie in der
  Vertragsansicht nicht wieder los, weil sie dort nicht als Hypothese
  erkennbar waren.
- Verträge ohne einen einzigen Monat im Zeitraum erzeugen keine Leerzeile mehr;
  die Einheit kommt aus der Utilities-SSOT statt aus einem festen „kWh"; die
  Hinweistexte sind übersetzt.
- Abschläge und Sonderzahlungen bleiben bewusst außen vor: Sie sind
  Zahlungsströme gegen den Saldo, keine Tarifkosten. Der Legendentext sagt das
  jetzt auch.

### Changed — Darstellung

- **Verbrauchsart-Farben kommen zur Laufzeit aus der SSOT.** Die
  handgepflegten `--util-*`-Token kannten nur Gas, Strom und Wasser — die fünf
  später ergänzten Arten (Fernwärme, Heizöl, Pellets, PV-Einspeisung,
  PV-Erzeugung) hatten weder Überschriften- noch Button- noch KPI-Farbe, keinen
  Aktiv-Marker in der Seitenleiste und keine hervorgehobene Jahres-Pille. Ein
  Heizöl-Haushalt sah eine entfärbte Anwendung. **Sichtbare Folge:** Gas und
  Strom wechseln auf ihre SSOT-Farbe (Amber statt Orange, Cyan statt Mint) —
  bisher zeigte das Diagramm eine andere Farbe als das Bedienelement daneben.
- **Textkontrast auf WCAG AA gehoben.** `--text-2` erreichte auf Kartengrund
  4,38:1 und auf verschachtelten Flächen 3,71:1; die Werte tragen als `.muted`
  fast alle Sekundärtexte. Jetzt 6,6:1 bzw. 5,7:1. Auch das helle Theme hatte
  einen Ausreißer.
- **Meldungen lassen sich schließen** und pausieren beim Zeigen; Fehler melden
  sich assertiv statt höflich (sie standen bisher hinter laufenden Ausgaben an
  und verschwanden nach Sekunden unwiderruflich).
- **Dialoge machen den Rest der Seite inert** und sperren das
  Hintergrund-Scrollen. Der Fokus-Trap fing nur die Tabulatortaste — der
  virtuelle Cursor eines Screenreaders wanderte weiterhin frei dahinter.
- **Die Ablesungstabelle folgt der Jahresauswahl.** Sie zeigte alle Ablesungen
  aller Jahre; mit dem Home-Assistant-Ingest entstehen tägliche Werte, was die
  Ansicht nach kurzer Zeit unbrauchbar machte.
- **Prognose und Analyse zeigen nur aktive Verbrauchsarten**, wie Dashboard und
  Seitenleiste.
- **Ungespeicherte Einstellungen** werden am Speichern-Knopf markiert, und das
  Schließen des Tabs fragt nach.
- Die Seitenleiste wartet nicht mehr auf ihre Zähler-Badges: Der erste
  Bildschirminhalt brauchte vier serielle Roundtrips, zwei davon nur für zwei
  Zahlen.

### Changed — Auslieferung

- **Schriften und Chart.js liegen im Repository** (`public/vendor/`, 260 KB).
  Bisher kamen sie von `fonts.googleapis.com` und `cdn.jsdelivr.net` — bei einer
  selbst gehosteten Anwendung wanderte damit die IP jedes Aufrufs zu Dritten,
  und der erste Start ohne Internet hatte weder Schrift noch Diagramme.
  Lizenzen liegen bei (SIL OFL 1.1 bzw. MIT).
- **Der Service Worker legt die App-Shell vollständig ab** (Stile, Schriften,
  Chart.js, Einstiegsmodul) statt nur der SPA-Wurzel.
- **Der Cache-Buster hängt an der VERSION** statt an der Änderungszeit von
  `app.js`. Die änderte sich nicht, wenn ein Release nur Views oder CSS anfasste
  — Browser behielten dann die alten Dateien.

### Added

- **i18n der nutzersichtbaren Backend-Texte**: Effizienz-Hinweise, Abbruchgrund
  der Prognose, automatisch erzeugte Termintitel, Notizen der v0.9.0-Migration
  sowie die Default-Zählernamen. Eine englische Frischinstallation begrüßte
  bisher mit „Hauptzähler". Verbrauchsart-Namen und Zählernamen lösen jetzt
  zentral über `I18nService::utilityLabel()` / `defaultMeterName()` auf —
  vorher trug jeder Konsument seine eigene Kopie, und zwei hatten gar keine.
- `ReleaseConsistencyTest`: prüft, dass Service-Worker-Cache,
  `docker-compose.yml`, CHANGELOG sowie README und INSTALL zur VERSION passen —
  und dass im Frontend kein Verweis auf einen fremden Server steht.

### Tests

- `TariffComparisonServiceTest` (8 Fälle) für das neu aufgesetzte Modul,
  darunter der Halbjahres-Fall, der die alte Rechnung entlarvt.
- `I18nServiceTest` prüft für **jede** Verbrauchsart in **jeder** Sprache, dass
  Name und Default-Zählername vorhanden sind.
- PHPUnit 105 → 130 Tests.

### Fixed — stille Rechenfehler

- **Prognose rechnete mit Schattenverträgen.** `ForecastService` holte die
  Verträge ohne `is_shadow`-Filter — anders als `ConsumptionService`, das ihn an
  beiden Stellen setzt. Sobald der letzte echte Vertrag vor dem Prognosehorizont
  endete, übernahm ein Schattenvertrag Arbeitspreis, Grundpreis und
  Abschlagsplan; die Prognose zeigte dann Kosten für einen Tarif, den es nie gab.
  Genau der Normalfall — man legt Schattenverträge an, *weil* der laufende
  Vertrag ausläuft. Im Reproduktionsfall beruhten 7 von 12 Prognosemonaten auf
  der Hypothese (797 € statt ~85 € pro Monat).
- **Wasser-Monatschart war eine Nullreihe.** `drawMonthChart()` las hart
  `m.kwh`, während `applyUtilityFields()` bei m³-nativen Verbrauchsarten den
  Verbrauch nach `m3` schiebt und `kwh` auf 0 setzt. Der Chart nutzt jetzt
  denselben `consKey` wie KPI und Monatstabelle. Letzter Rest von Fix #14
  (v1.6.1), der Tabelle und Kennzahl repariert hatte.
- **Wasser-Vertragsformular speicherte stillschweigend 0.** `collectWaterForm()`
  wandelte leere Zahlenfelder mit `parseFloat(x || 0)` in eine echte 0 um: Wer
  ein Stichtagsdatum eintrug und den Preis vergaß, legte damit einen Tarif von
  0 ct/m³ an — die Wasserkosten fielen ab diesem Datum auf den Grundpreis, ohne
  Fehlermeldung. Leere Felder erreichen jetzt den Backend-Guard, der die halb
  gefüllte Zeile ablehnt. Zusätzlich markiert das Formular solche Zeilen schon
  vor dem Absenden (wie das Standard-Vertragsformular).
- **Zahlen, Datumsangaben und Monatsnamen in fünf Sprachen deutsch.**
  `lib/format.js` bildete nur `de` und `en` ab; die 2026 ergänzten Sprachen
  (es/fr/it/nl/pt) fielen still auf `de-DE` zurück und zeigten deutsche
  Tausendertrennung, deutsche Datumstrennung und deutsche Monatskürzel („Mär",
  „Mai", „Dez") in einer ansonsten übersetzten Oberfläche. Formatierung kommt
  jetzt aus `Intl`; die handgepflegten Monatstabellen in `analysis.js` und der
  zweite Formatierer in `readings-entry.js` entfallen.

### Changed — Nebenbefunde

- **Schattenverträge sind in der Vertragsliste als solche erkennbar.** Sie
  erschienen dort mit dem Status „AKTIV", obwohl Saldo, Vertragsstatus und
  Prognose sie herausfiltern. Jetzt tragen sie ein eigenes Kennzeichen und einen
  Hinweis, dass sie in keiner Kostenrechnung mitzählen.
- **`docker-compose.yml` pinnte noch `1.9.3`** — sieben Releases alt. Wer aus dem
  Repository heraus startete, bekam eine Version vor dem gesamten v2.x-Bündel
  (Mehrsprachigkeit, PWA, Barrierefreiheit, Subzähler, Home-Assistant-Ingest).

### Tests — Regressionen

- Neuer `ForecastShadowContractTest`: Prognose darf nie auf einem
  Schattenvertrag beruhen, und das Ergebnis muss mit und ohne Schattenvertrag
  identisch sein. Gegen den v2.1.5-Stand schlagen beide Fälle fehl.
- `WaterContractEdgeCasesTest` um drei Fälle erweitert: halb gefüllte Preiszeile
  wird abgelehnt, komplett leere Vorlagezeile weiterhin still verworfen, und die
  Invariante `kwh = 0 / m3 > 0`, auf der der Chart-Fix beruht.
- PHPUnit 100 → 105 Tests (347 Assertions), Frontend-Render 37/37.

---

## [2.1.5] — 2026-06-28 — Polish: Chart-Farben, Prognose-Linie, Reminder-Datumslogik

PATCH-Release. Vier risikoarme Politur-/Robustheits-Korrekturen; keine Schema-
oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Monatschart-Farben (A).** `drawMonthChart` nutzte eine hartkodierte
  2-Farben-Palette (nur Gas/Strom, Rest Blau) statt der Utility-Farbe aus der
  SSOT → Wasser/Fernwärme/Heizöl/Pellets/PV erschienen blau. Jetzt zieht der Chart
  `u.color` (wie der Rest der UI und die Prognose-View).
- **Reminder-Datumsfortschreibung (C).** `markDone`/`suggestNextDelivery` nutzten
  `modify('+N months')`, das am Monatsende überläuft (31.08. + 6 Mon. → 03.03.
  statt 28.02.). Der Tag wird jetzt auf die Ziel-Monatslänge geclamped.
- **Reminder-Robustheit (D).** Ein kaputtes `next_due` (Import/Legacy) sprengte
  `listWithStatus` mit einer DateTime-Exception (500). Jetzt defensiv abgefangen.

### Changed (UX)

- **Prognose-Chart (B).** Historie und Prognose sind jetzt durchgehend verbunden
  (die gestrichelte Prognoselinie dockt am letzten Historie-Punkt an) statt mit
  einer Lücke an der Grenze.

### Docs

- Lessons in DE **und** EN (`docs/technical/06-release-process.md` + `docs/en/…`)
  synchron ergänzt.

### Migration

Keine. Schema bleibt 1.3.0.

### Tests

- Neuer `ReminderServiceTest` (Monatsende-Clamp 31.08.+6 → 28.02.; jährlich behält
  den Tag; kaputtes `next_due` → kein Crash). PHPUnit 97 → **100**. Chart-Farben
  gegen die API-SSOT verifiziert; Browser-Render grün.

### Lessons Learned

- Keine zweite Farb-/Wert-Quelle neben der SSOT (Utilities) — Anzeige-
  Eigenschaften von dort ziehen; Datums-Arithmetik gegen Monatsende-Überlauf
  absichern.

---

## [2.1.4] — 2026-06-28 — Wasser-Schmutzwasser-Fix + UX-Politur (Lösch-Dialog, Liefer-Modal, Tank-Validierung)

PATCH-Release. Vier risikoarme Korrekturen aus einem Code-Sweep; keine Schema-
oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Wasser: Schmutzwasser ohne Zähler-Referenz (A).** `applyWaterContracts`
  rechnete bei `schmutzwasser.basis = 'separater_zaehler'` **ohne** hinterlegten
  Zähler still auf dem **Trinkwasser-Volumen** ab statt auf 0 — entgegen dem
  eigenen Code-Kommentar. Der Speicherpfad (`ContractService`) erzwingt zwar eine
  Referenz; un­validierte Daten (Backup-Import/Legacy) können sie aber missen.
  Jetzt: ohne Referenz `swM3 = 0` (keine still falschen Kosten).

### Changed (UX)

- **Lösch-Dialoge vereinheitlicht (B).** Das Löschen von Ablesungen/Lieferungen
  in der Verbrauchsansicht nutzt jetzt das gestylte, lokalisierte `confirmModal`
  (wie die Zählerverwaltung) statt des nativen `confirm()`.
- **Liefer-Modal rechnet mit (C).** Gesamtbetrag und Menge × Stückpreis sind live
  verknüpft: zwei Felder gesetzt → das dritte füllt sich automatisch (beidseitig).
- **Tank-Kapazität: Sofort-Validierung (D).** Beim Anlegen/Bearbeiten eines
  Heizöl-/Pellets-Tanks meldet die UI eine fehlende Kapazität sofort, statt erst
  den Backend-Fehler abzuwarten.

### Docs

- **EN-Kompendium nachgezogen.** `docs/en/technical/06-release-process.md`
  (Lessons v2.1.1–v2.1.4) und `docs/en/ui/01-views.md` (Tank-Felder) hingen hinter
  dem DE-Stand — jetzt synchron. DE bleibt kanonisch.

### Migration

Keine. Schema bleibt 1.3.0.

### Tests

- Regression `WaterContractEdgeCasesTest::testSeparaterZaehlerWithoutMeterReferenceBillsZeroNotTrinkwasserVolume`;
  PHPUnit 96 → **97**. B/C/D zusätzlich im echten Browser verifiziert (Liefer-
  Auto-Rechnung bidirektional, gestyltes Lösch-Modal, Kapazitäts-Block), 0
  Konsolenfehler.

### Lessons Learned

- Save-Pfad-Validierung schützt den Berechnungs-Pfad nicht: unvalidierte Daten
  (Restore/Legacy) erreichen die Compute-Schicht, die deshalb ihrem dokumentierten
  Verhalten folgen muss — nicht der Validierung des Schreibpfads vertrauen.

---

## [2.1.3] — 2026-06-28 — Bugfix: Subzähler-Doppelzählung in PDF-Bericht & Effizienzklasse

PATCH-Release. Behebt eine **F1006-Subzähler-Doppelzählung** in mehreren
Aggregationen. Reine Anzeige-/Report-Korrektheit, kein Datenverlust, keine
Schema- oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Subzähler wurden doppelt gezählt** in drei Aggregationen, die selbst über
  Zähler summieren und die F1006-Ausschlussregel aus
  `ConsumptionService::forUtility` nicht spiegelten (ein Subzähler steckt
  bereits im Brutto-Verbrauch seines Elternzählers):
  - **`PdfReportService::yearAggregate`** — Zusammenfassungstabelle im
    Jahresbericht-PDF (Verbrauch/Kosten/CO₂ je Art); betraf **alle**
    Verbrauchsarten.
  - **`BenchmarkService::yearKwhForUtility`** — Effizienzklasse (kWh/m²·a) der
    Heizquellen.
  - **`groupBreakdown` (Dashboard)** — Gruppen-Summe, falls eine Gruppe einen
    Eltern- *und* seinen Subzähler enthält.
  Alle drei schließen Subzähler jetzt analog zur Utility-Gesamtsumme aus.

### Migration

Keine. Schema bleibt 1.3.0; nur Anzeige-/Berechnungslogik betroffen, keine
gespeicherten Daten geändert.

### Tests

- **Regression** (`MeterTopologyTest::testSubmeterDoesNotInflateEfficiency`):
  Gas-Heizquelle mit Eltern + Subzähler — `BenchmarkService::efficiency()`
  zählt nur den Elternzähler. Wäre vor dem Fix rot. PHPUnit 95 → **96**.
- Vollständige Konsumenten-Prüfung: `CsvExportService`, `PvSummaryService`,
  `StromSaldoService` (nutzen `forUtility`) sowie die pro-Zähler-Pfade
  (`Forecast`/`Recommendation`/`TariffComparison`/PDF-Detailseiten) sind
  korrekt — nur die drei obigen rollten eigene Summen.

### Lessons Learned

- Eine bereichs-spezifische Ausschlussregel (F1006: Subzähler nicht in
  Utility-Summen) muss in **jeden** Aggregator propagiert werden, der selbst
  über Zähler summiert — nicht nur in `forUtility`. Drei Stellen (PDF,
  Benchmark, Dashboard-Gruppen) hatten sie seit v1.8.0 nie bekommen. Eine
  gemeinsame „Root-Meter"-Quelle wäre robuster (als Folgeoption notiert).

---

## [2.1.2] — 2026-06-28 — Bugfix: Backup/Restore verlor Lieferungen, Gruppen & Reminders

PATCH-Release. Behebt einen **stillen Datenverlust** im Backup/Restore und
vervollständigt die mitgelieferten Demo-Daten. Keine Schema- oder
API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Backup/Restore sicherte nicht alle Daten.** `BackupService::export()`
  und `import()` verarbeiteten pro Verbrauchsart nur `meters`/`readings`/
  `contracts`. Damit fielen bei **jedem** Backup lautlos unter den Tisch:
  **`deliveries`** (Heizöl-/Pellets-Lieferungen, seit v1.3.0),
  **`meter_groups`** (F1006-Zählergruppen, seit v1.8.0) und die top-level
  **`reminders`**. Ein Export/Restore verlor damit die komplette Liefer- und
  Verbrauchshistorie der Liefer-Utilities sowie Gruppen und Wartungstermine.
  Export und Import sichern/restaurieren diese Töpfe jetzt; `import()` bleibt
  über `isset`-Guards **abwärtskompatibel** zu älteren Backups ohne die
  Schlüssel (fehlende Töpfe werden übersprungen, nichts gelöscht).

### Changed

- **Demo-Daten vervollständigt.** Das mitgelieferte Demo-Backup
  (`demo-data/energietracker-demo-backup.json`, auch im Docker-Image) enthielt
  für den Heizöltank und das Pelletlager keine Lieferungen — der „Demo-Daten
  laden"-Button zeigte leere Tanks. Es trägt jetzt je drei realistische
  Jahres-Lieferungen (2023–2025), passend zum Datei-Baum unter `demo-data/`.

### Migration

Keine. Schema bleibt 1.3.0; bestehende Backups ohne die neuen Schlüssel
importieren unverändert weiter.

### Tests

- **Roundtrip-Regression** (`BackupServiceRestoreGuardTest`): export → leeren
  → import bewahrt `deliveries`, `meter_groups` und `reminders`.
- **Demo-Restore** (`DemoServiceTest`): Import des echten Demo-Backups stellt
  je drei Heizöl-/Pellets-Lieferungen wieder her. Beide Tests wären vor dem
  Fix rot.
- PHPUnit 93 → **95 Tests / 299 Assertions**. Zusätzlich HTTP-End-to-End
  verifiziert (`POST /api/backup/import` → `GET …/deliveries` = 3 + 3).

### Lessons Learned

- Ein Serializer mit **hartkodierter Feldliste** wird bei jedem neuen
  Datentopf still unvollständig: `deliveries` (v1.3.0) und `meter_groups`
  (v1.8.0) kamen dazu, die Backup-Liste nie. Ungeprüfte Vollständigkeits-
  Annahmen (die Roadmap nahm „BackupService zieht ohnehin alle JSON-Dateien"
  an) + fehlender Roundtrip-Test verzögerten den Fund. Neue Datentöpfe
  gehören in `export()` **und** einen export→import-Roundtrip-Test.

---

## [2.1.1] — 2026-06-28 — Bugfix: Tank für Heizöl/Pellets im UI anlegbar

PATCH-Release. Reiner Frontend-Fix, keine Schema- oder API-Änderung
(Schema bleibt 1.3.0). Behebt, dass sich für lieferbasierte Verbrauchsarten
(Heizöl, Pellets) über die Oberfläche kein Tank anlegen ließ.

### Added

- Zwei neue Oberflächen-Schlüssel `meters.modal.capacity` und
  `meters.modal.initialStock` in allen sieben Katalogen
  (`de/en/fr/it/es/pt/nl`).

### Changed

- **Versionsstempel synchronisiert.** `INSTALL.md` und `INSTALL.de.md`
  (Docker-Pull-Beispiel + VERSION-Verweis) standen noch auf 2.0.1 und
  ziehen jetzt auf 2.1.1 mit.

### Fixed

- **Tank für Heizöl/Pellets ließ sich nicht anlegen ([#18]).** Das „Neuer
  Zähler"-Formular (`public/js/views/meters.js`) rendert für lieferbasierte
  Verbrauchsarten jetzt die Pflichtfelder **Tank-Kapazität** und
  **Anfangsbestand** und sendet sie an `POST /api/utility/<key>/meters`.
  Bisher fehlten beide Felder, sodass `MeterService::create()` mit
  „Tank-Kapazität (capacity) > 0 ist Pflicht" abbrach — eine Fehlermeldung
  ohne zugehöriges Eingabefeld. Die Felder erscheinen auch im
  Bearbeiten-Dialog (vorbefüllt); der kumulative „Anfangsstand" entfällt
  für Tanks.

### Migration

Keine. Schema bleibt 1.3.0; Bestandsdaten und bereits angelegte Tanks
(auch die per `ensureDefault` erzeugten) laufen unverändert weiter.

### Tests

- Browser-verifiziert (Playwright gegen Demo-Daten): Tank anlegen — Felder
  sichtbar, Speichern ohne Fehler, `capacity`/`initial_stock` korrekt
  persistiert — und bearbeiten (Werte vorbefüllt), 0 Konsolenfehler. Die
  PHPUnit-Suite bleibt unverändert grün; `MeterService` war bereits korrekt.

### Lessons Learned

- Ein Backend-Pflichtfeld ohne passendes Eingabefeld im Frontend ist per
  Konstruktion ein Fehlerpfad. Bei `reading_kind`-abhängigen Pflichtfeldern
  Formular und `MeterService::create()` zusammen denken.

[#18]: https://github.com/Bingerminger/energietracker/issues/18

---

## [2.1.0] — 2026-06-10 — Weitere UI-Sprachen + vollständige englische Doku

MINOR-Release. Erste Lokalisierungs-Welle nach dem v2.0.0-i18n-Fundament:
fünf zusätzliche Oberflächen-Sprachen, eine datengetriebene Sprach-Registry
sowie die **vollständige englische Spiegelung des Dokumentations-Kompendiums**.
Dazu ein überarbeiteter App-Logo-/Icon-Satz und ein Datenpfad-Fix in `index.php`.
**Keine Schema-Änderung** (bleibt 1.3.0); alle Neuerungen sind additiv.

### Added

- **Fünf neue Oberflächen-Sprachen.** Vollständige Kataloge unter
  `public/locales/{fr,it,es,pt,nl}.json` — **Französisch, Italienisch, Spanisch,
  Portugiesisch und Niederländisch** — jeweils mit demselben Schlüsselsatz wie
  `de.json`/`en.json` (1073 Schlüssel, geprüft auf vollständige Schlüssel- und
  Platzhalter-Deckung). Umschaltbar unter *Einstellungen → Sprache*; die
  HTML-`lang`- und `dir`-Attribute folgen der Auswahl.
- **Datengetriebene Sprach-Registry.** Neue Datei `public/locales/languages.json`
  (Code → Eigenbezeichnung) ist jetzt die einzige Wahrheitsquelle der
  verfügbaren Sprachen. Frontend (`i18n.js` lädt sie via `loadLanguages()`) und
  Backend (`I18nService::supported()` liest die Schlüssel, mit
  `FALLBACK_SUPPORTED=['de','en']`) leiten ihre Sprachliste daraus ab — eine
  weitere Sprache erfordert nur noch Katalog + Registry-Eintrag, keinen
  Code-Eingriff mehr. Der Sprach-Dropdown zeigt die Sprachen in ihrer jeweiligen
  Eigenbezeichnung (Endonym).
- **Vollständige englische Dokumentation.** Das gesamte Kompendium ist nun
  zweisprachig: unter `docs/en/` liegt die englische Spiegelung aller Kapitel
  (functional 00–13, technical 01–07, UI-Referenz, `API.md`, `ARCHITECTURE.md`,
  Erste Schritte, Home Assistant, Use-Cases, Migration). Jede deutsche und
  englische Seite trägt eine Sprachleiste zum jeweiligen Gegenstück. **Deutsch
  bleibt kanonisch** und wird bei jedem Release synchron gehalten; beide Sprachen
  werden ab sofort weitergepflegt.

### Changed

- **Überarbeiteter App-Logo-/Icon-Satz.** Neue, verbesserte Logos in den
  kompletten Icon-Satz übernommen (`public/img/icon-{light,dark}-*.png`,
  `icon-{light,dark}.png`, `public/favicon.ico`), inkl. PWA-Icons in allen
  Größen; verlustarm komprimiert.
- **Dropdown-Sprachliste statt Hardcodierung.** `settings.js` baut die Auswahl
  aus `getLanguages()` (Endonyme) statt aus festen `settings.lang.*`-Schlüsseln.

### Fixed

- **Sprach-Einstellung bei eigenem Datenverzeichnis (`ET_DATA_DIR`).** `index.php`
  liest die `language`-Einstellung jetzt aus dem konfigurierten Datenpfad
  (`ET_DATA_DIR`) statt fest aus `./data`, sodass der initiale Server-Render bei
  ausgelagertem Datenverzeichnis die korrekte Sprache liefert.

### Notes

- Die weiteren geplanten Sprach-Wellen (u. a. cs, uk, pl, el, tr, hr, sr, sl, fi,
  no, da, lv, et, hu, bg, ro) sind in der [Roadmap](roadmap.md) vermerkt und
  werden bedarfsgetrieben nachgezogen.
- F1008 (NKA/Mieter-Datenmodell) bleibt für ein späteres Release vorgemerkt.

---

## [2.0.1] — 2026-06-10 — Bugfix: Zählergruppen im Dashboard

PATCH-Release. Behebt einen seit F1006 (v1.8.0) bestehenden Fehler.

### Fixed

- **Zählergruppen wurden nicht im Dashboard angezeigt ([#16](https://github.com/Bingerminger/energietracker/issues/16)).**
  Mit der Meter-Topologie (F1006) angelegte Zähler-Gruppen tauchten in der
  Übersicht nicht auf, obwohl die Roadmap „Gruppen (Dashboard-Summe)" zusagte.
  Die Backend-Daten (`meter_groups` samt `meter_group_id` je Zähler) wurden
  zwar geliefert, im Dashboard aber nie gerendert. Jede Verbrauchsart-Karte mit
  gruppierten Zählern zeigt nun eine **aufklappbare Gruppen-Übersicht** mit dem
  12-Monats-Verbrauch (und, falls vorhanden, den Kosten) je Gruppe. Karten ohne
  Gruppen bleiben unverändert.

*(Der ebenfalls in #16 geäußerte Wunsch nach Verträgen pro Gruppe ist ein
Feature und für ein späteres Release vorgemerkt.)*

---

## [2.0.0] — 2026-06-10 — Internationalisierung, Englisch, Barrierefreiheit, PWA

MAJOR-Release. Großes Bündel: Full-Stack-Internationalisierung, englische
Lokalisierung, durchgängige Barrierefreiheit, UX-Politur und PWA-/Offline-
Fähigkeit. **Keine Schema-Änderung** (bleibt 1.3.0) — die Sprache ist additiv
als `language`-Setting hinterlegt.

### Added

- **Full-Stack-Internationalisierung (N1007).** Gemeinsame JSON-Sprachkataloge
  unter `public/locales/{de,en}.json`, im Frontend über `t()`
  (`public/js/lib/i18n.js`) und im Backend über `I18nService` genutzt. Die
  Sprache ist ein additives `language`-Setting (de|en), umschaltbar in den
  Einstellungen.
- **Englische Lokalisierung (EN-L10n).** Vollständige englische Übersetzung
  aller Ansichten, Komponenten, Fehlermeldungen und des PDF-Jahresberichts
  (DE/EN paritätsgleich).
- **Progressive Web App (N1008).** `manifest.webmanifest` + Service-Worker
  (`sw.js`, Root-Scope) + 192/512-Icons. Installierbar (standalone) und
  offline-fähig: App-Shell, alle View-Module, Schriften/CDN sowie zuletzt
  geladene API-Daten werden gecacht; die App startet und rendert ohne Netz.
- **Barrierefreiheit (N1009).** Skip-Link, sichtbarer Fokus-Ring
  (`:focus-visible`), `prefers-reduced-motion`, dynamisches `<html lang>`,
  ARIA-Landmarks, Fokus-Management bei SPA-Navigation, Modal-Focus-Trap mit
  Fokus-Rückgabe, Label↔Feld-Verknüpfung in allen Formularen, `scope="col"`
  in allen Tabellen, zugängliche Namen für Icon-Buttons, Text-Alternativen
  für alle Diagramme, tastaturbedienbare CSV-Drop-Zonen und Status-/Hinweis-
  Ansagen über Live-Regions.

### Changed

- **UX-Politur.** Dashboard-Trend-Indikatoren als dezent getönte Chips,
  gleichmäßigere Karten-Abstände im Raster, Null-Kosten als gedämpftes „—"
  statt „0 €". Einheitlicher Zeilen-Hover für alle Tabellen.
- **Sprach-Priorität.** Das `language`-Setting ist nun die maßgebliche Quelle
  für ALLE serverseitigen Texte (auch PDF-Report und Fehlermeldungen); der
  Browser-`Accept-Language`-Header dient nur noch als Fallback ohne gesetzte
  Sprache.

### Fixed

- **PDF-Report in falscher Sprache.** Der PDF-Jahresbericht (und die Backend-
  Fehlermeldungen) folgten dem Browser-`Accept-Language` statt der App-Sprache
  — bei englischer App auf deutschem Browser kam ein deutsches PDF. Jetzt
  maßgeblich: das `language`-Setting.
- **Unsichtbarer Text im Hellmodus.** In der Zählerstands-Erfassung nutzten
  Feld-Labels, die „Letzter Stand"-Zeile und Karten-Untertitel ein nicht
  definiertes CSS-Token (`--fg-muted`) mit weißem Fallback — im Light-Theme
  praktisch unsichtbar. Auf ein theme-bewusstes Token umgestellt.

---

## [1.9.3] — 2026-06-02 — Echte UI-Screenshots statt SVG-Mockups

PATCH-Release. **Nur Dokumentation** — kein Anwendungscode.

### Changed

- **UI-Referenz mit echten Screenshots.** Die handgezeichneten SVG-Mockups
  unter `docs/ui/mockups/` wurden durch **echte Bildschirmaufnahmen** der
  laufenden App (mit Demo-Datensatz, Light-Theme) ersetzt — eine PNG je View
  unter `docs/ui/screenshots/`. `docs/ui/01-views.md` umfassend überarbeitet:
  jetzt **alle 12 Ansichten** inkl. der neuen **PV-View** (F1005) und der
  **Topologie-Hinweise** in der Zähler-View (F1006).
- Disclaimer „schematische SVG-Mockups" in README und Kompendium-Index durch
  „echte Screenshots" ersetzt; `docs/screenshots/README.md` zu einer schlanken
  Anleitung zum Neu-Erzeugen der Screenshots umgeschrieben.

### Removed

- Die 11 SVG-Mockup-Dateien unter `docs/ui/mockups/` (durch echte PNGs ersetzt).

---

## [1.9.2] — 2026-06-02 — Dokumentations-Review + Roadmap-Neusortierung

PATCH-Release. **Nur Dokumentation und Roadmap** — kein Anwendungscode, keine
Schema- oder Verhaltensänderung.

### Roadmap

- **Smart-Meter-Major (v3.0.0) gestrichen.** Echtes Metering (Smart-Meter-
  Auslesung) wird bewusst an Home Assistant delegiert (F1009-Ingest); der
  Energietracker bleibt die schlanke Vertrags-/Kosten-/Prognose-Oberfläche.
- **N1011** (API-Versionierung) aus der festen Reihenfolge in den
  „Bedarfsgetrieben"-Block verschoben (war nur Smart-Meter-Vorbereitung).
- **Neue strategische Leitlinie:** Ausbau der Home-Assistant-Integration
  (als bedarfsgetriebenes F1010+ skizziert). Geplante Reihenfolge endet bei
  EN-Lokalisierung (v2.0.0); nächster Slot unverändert F1008.

### Dokumentation

- **Vollständiger Faktenabgleich** aller Docs auf den Code-Stand: Schema 1.3.0,
  68 API-Routen, 24 Services / 20 Controller, 12 Views, 40 Settings-Schlüssel,
  Testzahlen (86/274 PHPUnit, 20/20 + 36/36 Frontend). Veraltete Smart-Meter-
  Verweise bereinigt; Schema-Historie ergänzt.
- **Neue Features dokumentiert:** Meter-Topologie (F1006) und
  Home-Assistant-Anbindung (F1009) durchgängig in Datenmodell, API-Referenz,
  Architektur, UI-Referenz und README.
- **Neue, nutzerorientierte Dokumente:**
  - `docs/ERSTE-SCHRITTE.md` — geführtes Beispiel von der Installation bis zur
    ersten Prognose.
  - `docs/USE-CASES.md` — vier durchgerechnete Praxisfälle: WG mit geteilten
    Zählern, Smart-Home-Vollausbau, PV-Haushalt mit Wärmepumpe, Vermieter mit
    mehreren Einheiten.
  - `docs/functional/13-meter-topologie.md` — Subzähler & Zählergruppen
    fachlich erklärt.
- **Verbesserte Struktur & Verlinkung:** Kompendium-Index um Einstiegs- und
  Praxis-Sektion erweitert, Rollen-Wegweiser ausgebaut, Szenario-Docs mit
  „Weiterführend"-Verweisen. Alle internen Doku-Links geprüft.

---

## [1.9.1] — 2026-06-01 — Frisch-Install-Fix + CI-Wartung

PATCH-Release. Keine Schema-Änderung, keine neuen Features.

### Fixed

- **Frischer Docker-Container startet jetzt mit Standard-Zählern.** Bei einem
  komplett leeren Datenverzeichnis (echter Erststart) lief bisher `migrate()`
  statt `initFresh()` — dadurch hatte ein frischer Container 0 Zähler für
  Gas/Strom/Wasser. Neu erkennt `Migrator::isPristine()` das leere Verzeichnis,
  sodass beim Erststart `initFresh()` läuft und die Standard-Zähler anlegt.
  Bestehende Installationen und Migrationspfade (v0.9.0-Altdaten, Demo-Daten
  mit vorhandener `meta.json`) sind nicht betroffen.

### Changed

- **CI: Node-24-Opt-in.** `.github/workflows/ci.yml` setzt nun
  `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` (analog `docker-publish.yml`, N1012),
  damit `actions/cache@v4` & Co. auf Node 24 laufen — die Node-20-Deprecations-
  Annotation entfällt.

### Tests

- 5 neue PHPUnit-Tests (`PristineInitTest`) für die Frisch-Install-Erkennung.
  Gesamt: 86 Tests / 274 Assertions.

---

## [1.9.0] — 2026-06-01 — F1009 Home-Assistant-Anbindung

MINOR-Release. Offizielle Integration für **Home Assistant**: HA liest Smart
Meter aus und pusht Zählerstände an den Energietracker, der weiterhin Verträge,
Kosten und Prognosen übernimmt. Ersetzt eine kursierende, technisch falsche
Forenanleitung durch eine saubere, abwärtskompatible Lösung. Schema-Migration
**1.2.0 → 1.3.0** (rein additiv).

### Added

- **F1009 — Push-Ingest-Endpoint `POST /api/ingest`.** Nimmt
  `{ utility, meter, value, date? }` und macht ein **Upsert pro
  (Zähler, Datum)**: ein erneuter Push am selben Tag aktualisiert den Wert,
  statt Duplikate anzulegen (idempotent — robust gegen mehrfaches Senden).
  `date` ist optional (Default heute) und akzeptiert auch volle ISO-Zeitstempel
  (wird auf das Datum gekürzt).
- **Opt-in Token-Authentifizierung.** Neue Endpoints
  `GET/POST/DELETE /api/auth/token`. Solange **kein** Token gesetzt ist, bleibt
  die API unverändert offen (keine Breaking Change für bestehende
  LAN-Installationen). Sobald ein Token existiert, verlangt `/api/ingest` einen
  `Authorization: Bearer <token>`-Header. Der Token wird **einmalig** im
  Klartext angezeigt und nur als SHA-256-**Hash** in einer separaten
  `data/auth.json` gespeichert (nicht in `settings.json`; vom Backup
  ausgenommen). Verifikation per `hash_equals` (konstante Zeit).
- **Zähler-Alias `external_id`.** Jeder Zähler kann optional eine pro
  Verbrauchsart eindeutige, frei vergebbare ID erhalten (z. B.
  `stromzaehler_haus`), die in HA statt der internen ID verwendet wird. Der
  Ingest akzeptiert Alias **oder** interne ID.
- **Einstellungs-Sektion „🏠 Home-Assistant-Anbindung".** Token
  erzeugen/anzeigen (einmalig)/widerrufen, Zähler-Aliase pflegen und ein
  fertiges, kopierbares HA-`rest_command`-YAML-Snippet.
- **Doku:** neue [`docs/HOME-ASSISTANT.md`](docs/anleitungen/home-assistant.md) mit
  Schritt-für-Schritt-Anleitung, Fehlersuche und zwei Use-Cases (Eigenheim mit
  PV/Fernwärme, Mietwohnung Strom/Gas/Wasser); `docs/API.md` um Auth- und
  Ingest-Endpoints erweitert.

### Changed

- **Schema 1.2.0 → 1.3.0.** Jeder Zähler trägt nun `external_id` (Default
  `null`). Migration additiv und idempotent; Auto-Migration beim ersten Start.

### Validation

- `external_id`: 1–64 Zeichen aus `[A-Za-z0-9_.-]`, eindeutig je Utility.
- `/api/ingest` lehnt Delivery-Utilities (Heizöl/Pellets — nutzen Lieferungen
  statt Ablesungen), unbekannte Zähler und nicht-numerische Werte mit `400` ab;
  fehlender/falscher Token bei aktiver Auth → `401`.

### Security-Hinweis

- Die kursierende Forenanleitung (`POST /api.php` mit
  `action`/`value`/`timestamp` und Token aus `settings.json`) ist **falsch** —
  keiner dieser Bestandteile existierte je. Die offizielle Schnittstelle ist
  oben beschrieben und in der Doku klar als korrekt gekennzeichnet.

### Tests

- 15 neue PHPUnit-Tests (`HomeAssistantIngestTest`): Token-Hash/`hash_equals`,
  Auth-401/200, Ingest-Upsert-by-date (idempotent), Alias-Auflösung +
  Eindeutigkeit, Migration `external_id`. Gesamt: 81 Tests / 261 Assertions.
- Frontend-API-Shape um Auth-Status, `external_id` und einen Ingest-Roundtrip
  erweitert; CI-Migrations-Smoke prüft jetzt 1.3.0 inkl. `external_id`.

---

## [1.8.0] — 2026-06-01 — F1006 Meter-Topologie (Subzähler + Gruppen)

MINOR-Release. Zähler können jetzt in Beziehung zueinander stehen:
**Subzähler** (Reihenschaltung) werden vom Elternzähler abgezogen,
**Gruppen** fassen mehrere Zähler im Dashboard zusammen. Schema-Migration
**1.1.0 → 1.2.0** (rein additiv, verlustfrei).

### Added

- **F1006 — Meter-Topologie.** Zwei neue, optionale Zähler-Beziehungen:
  - **Subzähler / Reihenschaltung** (`parent_meter_id`): z. B. eine
    Wärmepumpe hinter dem Haushaltsstrom. Der Elternzähler misst brutto
    inklusive seiner Subzähler; in der Verbrauchsart-Gesamtsumme zählt daher
    nur der Elternzähler, der Subzähler wird **nicht doppelt** addiert,
    sondern als Aufschlüsselung eingerückt dargestellt.
  - **Gruppen** (`meter_group_id`): mehrere Zähler (z. B. NT + HT Strom oder
    mehrere Wallboxen) werden im Dashboard zu einem Eintrag zusammengefasst.
    Neue Gruppen-Stammdaten je Verbrauchsart in `meter_groups.json`.
- **Merge-Wizard.** Geführter Dialog „Zähler zusammenführen" im Zähler-View:
  mehrere bestehende Zähler per Mehrfachauswahl zu einer (neuen oder
  bestehenden) Gruppe zusammenfassen.
- **Neue API-Endpoints** (Zählergruppen):
  `GET/POST /api/utility/{utility}/meter-groups`,
  `PATCH/DELETE /api/utility/{utility}/meter-groups/{groupId}`,
  `POST /api/utility/{utility}/meter-groups/merge`. Der Consumption-Endpoint
  liefert zusätzlich `meter_groups[]` für die Dashboard-Aufschlüsselung.

### Changed

- **Schema 1.1.0 → 1.2.0.** Jeder Zähler trägt nun `parent_meter_id` und
  `meter_group_id` (Default `null`). Die Migration ist additiv und idempotent
  (folgt dem bestehenden Migrator-Muster); bestehende Daten bleiben
  unangetastet. Auto-Migration beim ersten Start hebt vorhandene
  Installationen automatisch an.
- Verträge bleiben in dieser Version **unverändert pro Zähler** — Gruppen
  fassen ausschließlich den Verbrauch fürs Dashboard zusammen. Ein
  Gruppen-Vertrags-Saldo (Vertrag gegen Gruppensumme) ist bewusst auf ein
  späteres Release vertagt.

### Validation

- **Keine mehrstufigen Subzähler-Ketten** (max. 1 Ebene): der gewählte
  Elternzähler darf selbst kein Subzähler sein und umgekehrt.
- **Keine Selbstreferenz / unbekannte Referenzen:** `parent_meter_id` und
  `meter_group_id` müssen auf existierende, gültige Ziele zeigen.
- **delete-Guards erweitert:** ein Elternzähler mit zugeordneten Subzählern
  kann nicht gelöscht werden, ohne die Zuordnung vorher aufzulösen; das
  Löschen einer Gruppe löst ihre Mitglieder (statt zu blockieren).

### Tests

- 15 neue PHPUnit-Tests (`MeterTopologyTest`): Migration (idempotent +
  Feld-Defaults), Validierung (Ketten/Zyklen/Existenz), Gruppen-CRUD,
  Merge-Wizard, delete-Guards und Aggregation ohne Doppelzählung.
  Gesamt: 66 Tests / 219 Assertions.
- Frontend-API-Shape um Gruppen-Endpoint + Topologie-Felder erweitert;
  CI-Migrations-Smoke prüft jetzt 1.2.0 inkl. `meter_groups.json`.

---

## [1.7.4] — 2026-05-31 — F1007 Demo-Daten-Import über die Einstellungen

MINOR-Release. Macht den Demo-Datensatz per Knopfdruck in der UI ladbar —
ideal für einen frisch installierten oder containerisierten, leeren
Energietracker.

### Added

- **F1007 — Demo-Daten-Komfort-Import.** Im Einstellungs-View unter
  „Backup & Wiederherstellung" ein neuer Button **„Demo-Daten laden"**.
  - Lädt das mitgelieferte Demo-JSON-Backup über den bestehenden
    Restore-Pfad (`BackupService::import()`) — inklusive Schema-Guard und
    automatischem Pre-Restore-Snapshot (N1004), also verlustfrei rückholbar.
  - **Warnung vorab**, wenn bereits Daten vorhanden sind (Bestätigungsdialog);
    bei leerem Tracker direkter Import ohne Nachfrage.
  - Neuer Service `DemoService` + Controller, Endpoints
    `GET /api/demo/status` (`{available, is_empty}`) und
    `POST /api/demo/import` (Body `{force}`). „Leer" = kein Zähler über alle
    Verbrauchsarten.
  - Architektur: **Variante B (serverseitig)** — das Demo-Backup wird vom
    Server gelesen; im Docker-Image ist genau diese eine Datei enthalten
    (`.dockerignore`-Ausnahme), nicht das ganze `demo-data/`-Verzeichnis.

### Tests

- Neue PHPUnit-Klasse `DemoServiceTest` (4 Tests): leer-Erkennung +
  Verfügbarkeit, Import füllt Zähler, Abbruch ohne `force` bei vorhandenen
  Daten, erzwungener Re-Import.

---

## [1.7.3] — 2026-05-31 — N1005 Docker-Image + N1010 strukturiertes Logging

MINOR-Release (zwei nicht-funktionale Anforderungen, kein Schemawechsel).
Macht Energietracker als Container reproduzierbar betreibbar und gibt ihm
zum ersten Mal echtes strukturiertes Logging — beide Themen gehören
zusammen, weil ein Container ohne maschinenlesbare Logs nur halb betreibbar
ist.

### Added

- **N1005 — Docker-Image (Single-Container).** `Dockerfile` auf Basis
  `php:8.4-fpm-alpine` mit nginx + php-fpm, von `supervisord`
  zusammengehalten. Ein `docker run -p 8080:80 -v ./data:/data …` genügt.
  - `docker-compose.yml` mit Volume-Mount für `./data` und Log-ENV.
  - `docker/nginx.conf` spiegelt 1:1 das Routing aus `router.php`
    (statische Assets, `/data` + `/src` gesperrt, `/api(.php)/…` → api.php,
    SPA-Fallback auf index.php).
  - `docker/php-fpm-app.conf`: `clear_env = no` (reicht `ET_*`-ENV an PHP
    durch) und `catch_workers_output = yes` (Worker-stderr → `docker logs`).
  - Container-`HEALTHCHECK` nutzt den N1003-Endpoint `GET /api/health`.
  - **GHCR-Publikation**: neuer Workflow `.github/workflows/docker-publish.yml`
    baut bei jedem `v*`-Tag und pusht nach
    `ghcr.io/bingerminger/energietracker` (Tags `{version}`, `{major}.{minor}`,
    `latest`).
  - **CI**: neuer Job `docker` baut das Image und smoke-testet es end-to-end
    (Health, `/api/…` und `/api.php/api/…`, statisches Asset, SPA-Fallback,
    `/data`-Sperre).
- **N1010 — strukturiertes Logging.** Neuer, abhängigkeitsfreier
  `Energietracker\Logging\Logger` (PSR-3-*orientiert*, ohne `psr/log`-
  Dependency — die Laufzeit bleibt Composer-frei). Ein JSON-Objekt pro
  Zeile („JSON Lines"), Default-Ziel stderr.
  - Steuerung per ENV: `ET_LOG_LEVEL` (debug|info|warning|error, Default
    info), `ET_LOG_DEST` (stderr|file|null, Default stderr), `ET_LOG_FILE`
    (Default `<dataDir>/logs/app.log`).
  - `ErrorHandler` loggt ab jetzt jede Exception und jeden fatalen Fehler
    (Level error, mit Typ/Datei/Zeile/HTTP-Status), bevor die JSON-Antwort
    rausgeht — vorher gingen Fehler ungeloggt verloren.
  - App-Lebenszyklus wird geloggt: Migration ausgeführt / frische
    Initialisierung (info), ein Access-Log-Eintrag pro Request (debug, im
    Default-Level also stumm).

### Tests

- Neue PHPUnit-Klasse `LoggerTest` (4 Tests): JSON-Lines-Format,
  Level-Schwellwert, Null-Ziel (aus), Fallback bei unbekanntem Level.
  Suite jetzt **47 Tests / 166 Assertions**, alle grün.

### Fixed

- **Multi-Arch-Image** (Nachzügler am selben Tag): der erste GHCR-Publish
  baute nur `linux/amd64`, sodass `docker pull` auf Apple Silicon /
  arm64 mit „no matching manifest for linux/arm64/v8" scheiterte. Der
  Publish-Workflow baut jetzt via QEMU/Buildx eine Manifest-Liste für
  **`linux/amd64` + `linux/arm64`**; das `v1.7.3`-Image wurde neu
  veröffentlicht.

---

## [1.7.2] — 2026-05-23 — P-PV-01: PV-Einspeisung als Erlös statt Kosten + realistische Forecast-Demodaten

PATCH-Release. Behebt das in v1.7.1 beobachtete Artefakt P-PV-01: die
PV-Einspeisungs-Detail-View rechnete und färbte den Vergütungs-Erlös mit
der generischen Verbrauchs-Semantik („Nachzahlung", „Unterzahlt", rot).

### Fixed

- **P-PV-01 (Backend)** — `ConsumptionService::contractStatus()` für
  `accounting_kind = feed_in`:
  - **Verdict-Achse umgedreht**: positiver Saldo ist eine `Auszahlung`
    des Netzbetreibers (gut), keine `Nachzahlung`. Negativer Saldo →
    `Rückforderung` statt `Erstattung`.
  - **Projektionshorizont begrenzt**: feed_in-Verträge werden bis zur
    nächsten Jahresabrechnung (`billing_cycle_anchor`) projiziert, nicht
    bis zum Vertragsende. Ein 20-Jahres-EEG-Vertrag (Ende z.B. 2043)
    erzeugte vorher ein absurdes „erwartet +10.756 €"; jetzt ~+2.000 €
    bis zur nächsten Abrechnung.
- **P-PV-01 (Frontend)** — `public/js/views/utility.js` für feed_in:
  - KPI „Verbrauch" → „Einspeisung", „Kosten" → „Erlös" (grün).
  - „Abschläge"-KPI ausgeblendet (Einspeisung hat keine Abschläge).
  - Saldo-Karte: „Verbraucht" → „Vergütung", „Abschlag bezahlt" →
    „Bereits ausgezahlt (über Netzbetreiber)", „Aktueller Saldo" →
    „Vergütungsanspruch / Guthaben beim Netzbetreiber". Positiver Saldo
    jetzt **grün** (Vergütung), nicht rot.
  - 3-Monats-Trend-Banner für `feed_in`/`generation` unterdrückt — der
    Vergleich ist bei sonnengetriebenen Reihen reine Saisonalität
    (Frühling vs. Winter), kein echter Verbrauchstrend.

### Changed

- **Demo-Daten** `demo-data/pv_einspeisung/` und `demo-data/pv_erzeugung/`
  neu generiert mit realistischer Jahres-Streuung (gutes/schlechtes
  Sonnenjahr ±8–12 %: 2024 ~10.260 kWh, 2025 ~9.370 kWh) plus
  Monatsrauschen, Reihe bis 2026-05. Damit liefert das Forecast-
  Saisonprofil für PV eine aussagekräftige, nicht-triviale Demo
  (Sommer-Peak ~910 kWh/Monat, Herbst fallend).

### Migration

Keine. Schema bleibt **1.1.0**. Reine Anzeige-/Berechnungs-Korrektur und
Demo-Daten — bestehende User-Daten unberührt.

### Tests

- `vendor/bin/phpunit` → **43 Tests / 152 Assertions, alle grün**
  (41 aus v1.7.1 + 2 neue):
  - `ContractStatusFeedInTest` (2) — feed_in-Verdict ist „Auszahlung"
    (nicht „Nachzahlung"); Projektion bleibt innerhalb der nächsten
    Abrechnungsperiode statt bis EEG-Vertragsende 2043.
- Frontend-API-Shape 14/14, Browser-Render 36/36 grün.

### Lessons Learned

- **Generische Views brauchen explizite Sonderfall-Schalter.** F1005
  (v1.7.0) hat PV als eigene Utility sauber modelliert, aber die
  Detail-View blind die Verbrauchs-Semantik wiederverwendet — Code lief
  grün durch alle Tests, war fachlich aber invertiert. Erst der Blick
  auf den realen Screenshot („+10.756 € Nachzahlung") deckte es auf.
  Lesson: bei einem neuen `accounting_kind` ist die Annahme „die
  bestehende View passt schon" zu prüfen, nicht vorauszusetzen.
- **Demo-Daten ohne Streuung verstecken Forecast-Bugs.** Die erste
  PV-Demo (v1.7.1) hatte jährlich identische Werte → perfekte Regression,
  die Forecast-Qualität war nicht beurteilbar. Realistische Jahres-/
  Monats-Streuung macht die Demo erst als Test- und Verkaufsartefakt
  brauchbar.

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.7.1] — 2026-05-23 — N1004 (Backup/Restore-UI) + Demo-Daten und Doku für PV nachgereicht

### Added

- **N1004** — Backup-Restore-Sicherungen:
  - Vor jedem `BackupService::import()` legt die App automatisch einen
    Snapshot der aktuellen Daten unter `data/backups/pre-restore-<ts>.json`
    ab. Wer mit dem Import-Ergebnis unzufrieden ist, kann diesen
    Snapshot wieder einspielen.
  - Schema-Version-Check: Backups mit `meta.schema_version >`
    `Migrator::SCHEMA_VERSION` werden hart abgelehnt
    (`InvalidArgumentException`). Vermeidet das stille Einspielen eines
    1.2.0-Backups in eine 1.1.0-App.
  - Restore-Report trägt jetzt `auto_snapshot_before_restore` (Dateiname
    oder Fehlerobjekt). Frontend zeigt den Namen im Erfolgs-Toast.
- **Demo-Daten v1.7.0 nachgereicht** (`demo-data/pv_einspeisung/` und
  `demo-data/pv_erzeugung/`):
  - Realistisches 10-kWp-Eigenheim-Szenario, Inbetriebnahme 2023-04-01,
    Standort Leipzig (analog Strom-Demo).
  - 36 monatliche Ablesungen je Zähler bis 2026-03-15.
  - Jahresertrag ~9.500 kWh, Eigenverbrauchsquote 30 %, Autarkiequote
    ~46 %, EEG-Einspeisevergütung 8,2 ct/kWh (IB 04/2023 nach § 48 EEG
    2023). `demo-data/settings.json` aktiviert beide PV-Utilities.
- **Szenario-Doku für PV**:
  - [`docs/functional/08-szenario-eigenheim.md`](docs/verstehen/08-szenario-eigenheim.md)
    um Sektion 6 „Photovoltaik — Einrichtung und Lesart" erweitert
    (welche Zähler bei welcher Anlage, EEG-Sätze, Strom-Saldo-Lesart,
    Autarkie- und EV-Quote, CO₂-als-vermieden, Erfassungs-Disziplin).
  - [`docs/functional/07-szenario-wohnung.md`](docs/verstehen/07-szenario-wohnung.md)
    um Sektion 7 „Sonderfall Balkonkraftwerk" ergänzt (warum
    `pv_einspeisung` dort nichts bringt, `pv_erzeugung` optional als
    Performance-Kontrolle).

### Changed

- UI: der Restore-Confirm-Dialog erklärt jetzt den automatischen
  Pre-Restore-Snapshot — verringert die Reibung beim Klick, weil der
  User weiß, dass ein Rollback-Pfad existiert.
- `BackupService::saveSnapshot()` akzeptiert optional einen
  `$prefix`-Parameter (Default `backup_`). Bestehende Aufrufer
  unverändert; intern genutzt für `pre-restore-` Sicherungen.

### Migration

Keine. Schema bleibt **1.1.0**. Bestehende User-Daten werden nicht
angefasst; Demo-Daten-Erweiterung ist nur für frische Installationen
relevant.

### Tests

- `vendor/bin/phpunit` → **41 Tests / 146 Assertions, alle grün**
  (38 aus v1.7.0 + 3 neue):
  - `BackupServiceRestoreGuardTest` (3) — Schema-Guard wirft bei
    neuerem Backup-Schema, akzeptiert gleiches Schema, legt Auto-
    Snapshot im `backups/`-Verzeichnis ab.

### Lessons Learned

- **Demo + Doku gehören zum Feature.** v1.7.0 lieferte Code, Tests und
  Detail-Konzept, aber keine Demo-Daten und keine ausführliche
  Szenario-Doku. Effekt: ein User, der die Demo lädt, sah keinen
  Mehrwert von F1005, ein User, der die Eigenheim-Doku las, fand kein
  Wort zu PV. Lesson: bei Feature-Releases die Demo-Daten- und
  Szenario-Doku-Erweiterung als verpflichtende Sub-Tasks im
  Release-Plan führen.
- **Bestehender Code zuerst lesen, dann skizzieren.** Die Roadmap-Skizze
  für N1004 forderte „ZIP-Stream" — der bestehende BackupService liefert
  JSON, was menschenlesbar, leicht diff-bar und ohne `ext-zip`-Abhängig-
  keit ist. Die JSON-Lösung beizubehalten und nur die fehlenden
  Sicherungen (Schema-Guard, Auto-Snapshot vor Restore) zu ergänzen,
  war der richtige Code-First-Move.

---

## [1.7.0] — 2026-05-23 — F1005: PV-Einspeisung + Erzeugung + Autarkiequote, N1003: Health-Check

Erstes funktionales Release seit v1.6.0 — F1004. Die NFR-Slots N1001/N1002
in v1.6.2/v1.6.3 haben das Regression-Safety-Net geschaffen, das die
substanziellere Erweiterung um Photovoltaik erst risikoarm möglich gemacht
hat.

### Added

- **F1005** — Photovoltaik. Zwei neue Utilities erweitern das
  6-Utility-Modell auf 8:
  - `pv_einspeisung` — Einspeisezähler des Verteilnetzbetreibers, mit
    vereinfachtem Vertragsmodell (nur ct/kWh-Einspeisevergütung; kein
    Grundpreis, kein Abschlagsplan, keine Sonderzahlungen — der
    Verteilnetzbetreiber zahlt nach Erzeugung, nicht nach Plan).
  - `pv_erzeugung` — Wechselrichter-Gesamtertrag, rein statistisch
    (keine Verträge — Vertrag-Create wird hart abgelehnt).
  Beide ohne Default-Meter (PV ist optional; wer keine Anlage hat,
  bekommt keine „Phantom-Zähler" angeboten). Beide cumulative,
  kWh-nativ, `accounting_kind`-Property neu in der `Utilities`-SSOT.
- **F1005 — Strom-Saldo** (`StromSaldoService` + `GET /api/strom-saldo`).
  Kombinierte KPI `bezug_cost − einspeisung_revenue` mit
  Vorzeichen-Konvention `positiv = Netto-Kosten`, `negativ = Netto-Erlös`.
  Liefert monatliche und jährliche Aggregate; Hauptdashboard zeigt das
  laufende Jahr als neue Insight-Karte.
- **F1005 — PV-Eigenverbrauch + Autarkiequote** (`PvSummaryService` +
  `GET /api/pv-summary`).
  `eigenverbrauch = erzeugung − einspeisung` (auf ≥ 0 geklammt),
  `eigenverbrauchsquote = eigenverbrauch / erzeugung`,
  `autarkiequote = eigenverbrauch / (eigenverbrauch + bezug)`.
  Quoten sind null, wenn der Nenner < 0,1 kWh ist; Feld
  `has_generation_meter` zeigt an, ob die App den Eigenverbrauch
  überhaupt berechnen kann.
- **CO₂-Anzeige als „vermieden"** für `pv_einspeisung`. Eingespeiste
  kWh × `co2_strom`-Faktor wird als negativer Wert mit Tooltip
  „Vereinfachte Modellrechnung; PV-Lebenszyklus nicht berücksichtigt"
  ausgewiesen.
- **N1003** — Health-Check. `GET /api/health` liefert `version`,
  `schema_version`, `data_dir_writable`, `migrations_pending`,
  `data_initialized_at`, `php_version`, `timezone`. Eignet sich für
  Synology-Healthcheck und „bei mir geht nichts"-Triage.
- Neue Frontend-API-Wrapper `api.stromSaldo()`, `api.pvSummary()`,
  `api.health()`.
- Neues Konzept-Dokument
  [`docs/functional/12-pv.md`](docs/verstehen/12-pv.md).

### Changed

- `Utilities` SSOT um die Helper `hasContracts()`, `accountingKind()`,
  `isFeedIn()`, `isGenerationOnly()` erweitert. `hasAdvancePaymentContracts()`
  schließt PV nun explizit aus (kein Abschlagsmodell).
- `Migrator::initFresh()` legt für PV-Utilities (wie für Heizöl/Pellets)
  keinen Default-Meter an.
- `ContractService::create()` weist Vertrag-Anlage auf Statistik-Utilities
  (`pv_erzeugung`) hart mit `InvalidArgumentException` zurück und
  ignoriert für `pv_einspeisung` die Felder `base_prices`,
  `advance_payments`, `special_payments`.
- `JsonStore::__construct()` löst das `rootDir` einmal per `realpath()`
  auf. Pre-existing macOS-Bug: `/tmp` ist ein Symlink auf `/private/tmp`;
  die Path-Prüfung mit dem unaufgelösten Pfad warf bisher bei jedem
  lokalen Test-Setup „Ungültiger Speicherpfad". CI auf Linux war nicht
  betroffen.
- Browser-Render-Test um Render-Smokes für `pv_einspeisung` und
  `pv_erzeugung` erweitert.

### Migration

Keine. Schema bleibt **1.1.0**. Bestehende Installationen erhalten beim
nächsten Lese-/Schreibzugriff transparent leere PV-Verzeichnisse über
die defensive `JsonStore::read()`-Default-Logik. PV-Utilities sind im
SettingsService-Default `active_utilities = ['gas', 'strom', 'wasser']`
NICHT enthalten — wer PV erfassen möchte, aktiviert sie in den
Einstellungen.

### Tests

- `vendor/bin/phpunit` → **38 Tests / 139 Assertions, alle grün**
  (25 aus v1.6.3 + 13 neue für F1005/N1003):
  - `PvUtilityReadingPathSmokeTest` (3) — Standard-Pfade greifen für PV
    ohne Anpassung; Helper-Klassifikation korrekt.
  - `ContractServiceFeedInTariffTest` (2) — Feed-in-Schema vereinfacht;
    Generation-only-Utility lehnt Vertrag-Create ab.
  - `StromSaldoServiceTest` (3) — leer, Bezug-ohne-PV, PV-flippt-Saldo-
    negativ.
  - `PvSummaryServiceTest` (3) — ohne Erzeugungsmeter null-Quoten,
    Standard-Rechnung, Klammern bei inkonsistenten Daten.
  - `HealthCheckServiceTest` (2) — Shape stabil, Version stimmt mit
    VERSION-Datei.
- Frontend-API-Shape: **14/14 grün**.
- Browser-Render: **36/36 grün** (vorher 34; zwei neue PV-Render-Smokes).

### Lessons Learned

- **Macht der SSOT.** Zwei neue Utilities durch einen einzigen
  Eintrag in `Utilities.php` plus minimalem Migrator-Patch reichten,
  damit die kompletten Service-Pfade (Bridging, Anomaly, Forecast,
  ContractStatus, CSV-Export) ohne weitere Änderung greifen.
  Mit weniger SSOT-Disziplin wäre F1005 ein 10-Datei-Eingriff geworden.
- **Pre-existing JsonStore-Bug erst bei lokalem Endpunkt-Smoke sichtbar.**
  PHPUnit umging ihn mit `realpath()`-Workaround im Harness; der lokale
  PHP-Server stolperte sofort beim ersten `/api/health`-Aufruf.
  Lesson: lokaler Endpunkt-Smoke ist nicht ersetzbar durch grüne
  PHPUnit-Suite — der Workaround im Test-Harness hat den Bug *im
  Produktionscode* maskiert. Fix gehört in den Production-Code
  (`JsonStore::__construct`), nicht ins Test-Setup.
- **„Klein anfangen" vs. „Kundenwert".** Die Roadmap hatte F1005 als „S"
  geplant (nur Einspeisezähler). Die Multiple-Choice-Klärung mit dem
  User hat ergeben, dass Erzeugungszähler + Autarkiequote essenziell für
  den PV-Eigentümer-Use-Case sind — Slot wurde bewusst auf M–L
  hochgestuft, dafür ist v1.7.0 das erste Release in einer Weile, das
  sichtbaren Mehrwert für eine neue User-Gruppe liefert.

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.6.3] — 2026-05-22 — N1002: Edge-Case-Test-Suite

### Added

- **N1002** — Edge-Case-Suite aufbauend auf der PHPUnit-Foundation aus
  N1001. Alle 10 Konstellationen aus dem Roadmap-Slot abgedeckt, in vier
  neuen Test-Klassen unter `tests/unit/Services/`:
  - `ConsumptionEdgeCasesTest` — Zählerüberlauf, lange Lücke,
    doppeltes Datum, negativer Verbrauch, Schaltjahr, DST-Übergang,
    leerer/einzelner Zähler.
  - `ContractEdgeCasesTest` — Vertragswechsel mitten im Monat
    (Stichtag-Konvention: am 1. des Monats aktiver Vertrag bekommt den
    ganzen Monat) und Wechsel exakt zum 1.
  - `WaterContractEdgeCasesTest` — Wasser-Vertrag ohne Schmutz- und
    ohne Niederschlagswasser-Komponente.
  - `ReadingEdgeCasesTest` — Schreibpfad: Ablesung vor `installed_on`
    wird abgewiesen, Bulk-Import meldet sie als `skipped`+`errors` und
    importiert die übrigen Zeilen sauber; doppelte Daten im Import
    überschreiben statt zu duplizieren.

### Changed

- Keine. Diese Suite dokumentiert ausschließlich vorhandenes Verhalten;
  kein Test deckt einen Bug auf, kein Service-Code wurde geändert.

### Migration

Keine. Schema bleibt **1.1.0**.

### Tests

- `vendor/bin/phpunit` → **25 Tests / 80 Assertions, alle grün**
  (12 Tests aus v1.6.2 + 13 neue Edge-Case-Tests).
- Regression-Safety-Net für F1006 (Meter-Topologie) steht damit
  vollständig — `ConsumptionService::forMeter()` darf in v1.8.0 ohne
  Angst angefasst werden.

### Lessons Learned

- **Vorrechnen lohnt sich:** der erste DST-Test rechnete „15.→31.März =
  16 Tage", korrekt ist 17 (`DateTime::diff` liefert die Differenz, nicht
  die Kalendertage). Lieber den Test-Code laufen lassen und das
  beobachtete Verhalten dokumentieren, als das erwartete Verhalten
  vorzuformulieren.
- **PHPUnit-Risky-Test bei leerem Iterator:** ein `foreach`-Loop, der
  über `[]` läuft, führt zu null Assertions und wird als „risky" gemeldet
  (`failOnRisky=true` lässt den Test rot werden). Lösung: vor dem Loop
  eine Gesamt-Assertion (`array_sum`/`assertEmpty`), die den
  Verwerfungs-Pfad explizit prüft.

---

## [1.6.2] — 2026-05-22 — N1001: PHPUnit-Foundation für die Service-Schicht

### Added

- **N1001** — PHPUnit-Foundation. Erstmalige Composer-Nutzung im Repo,
  konsequent **dev-only**: `composer.json` führt `phpunit/phpunit ^11.5`
  als `require-dev`, der Runtime-Autoloader in `src/bootstrap.php` bleibt
  unverändert hand-rolled, der Auslieferungs-ZIP enthält weiterhin kein
  `vendor/`.
- Test-Verzeichnis `tests/unit/` mit Support-Basisklasse
  `ServiceTestCase`. Jeder Test bekommt ein frisches temporäres
  `data/`-Verzeichnis (via `Migrator::initFresh()`), die Services werden
  identisch zum App-Container zusammengesteckt — **keine Mocks der
  Storage-Schicht** (Lesson „Mocks nicht über echte Disk-I/O", v1.4.x).
- Drei Test-Klassen mit zusammen 12 Tests, fokussiert auf die in
  Issue [#13] gefixten Pfade:
  - `ConsumptionServiceBridgingTest` — 5 Bridging-Pfade
    (sauber, Off-by-one am Tausch-Tag, fehlender `final_counter`,
    Plausibilitäts-Cap, `device_swap`-Flagging).
  - `MeterServiceReplaceDeviceTest` — `old_final_counter` als Pflicht,
    `deviceOnDate`-Stichtag-Konvention (`>= removed_on` → neues Gerät).
  - `AnomalyServiceTest` — Wechsel-Monat aus z-Score-Erkennung
    ausgeschlossen, Feldnamen-Vertrag stabil.

### Changed

- `.github/workflows/ci.yml`: neuer Job `phpunit` (läuft nach
  `lint-php`), inkl. Composer-Cache. `lint-php` ignoriert jetzt
  zusätzlich `vendor/`.
- `.gitignore`: `.phpunit.cache/` und `.phpunit.result.cache` ergänzt.

### Migration

Keine. Schema bleibt **1.1.0**. Composer ist optional und wird
ausschließlich für die Test-Suite gebraucht — eine bestehende
Installation muss nichts tun.

### Tests

- `vendor/bin/phpunit` → **12 Tests, 41 Assertions, alle grün**.
- Bestehende Frontend-API-Shape- und Browser-Render-Tests unverändert,
  bleiben grün.

### Lessons Learned

- macOS-Spezial: `sys_get_temp_dir()` liefert `/var/folders/...` (Symlink
  auf `/private/var/folders/...`). `JsonStore::path()` prüft per
  `realpath()` und würde sonst „Ungültiger Speicherpfad" werfen — im
  Test-Harness das `dataDir` einmal mit `realpath()` auflösen.
- PHP 8.5-Deprecation „Implicit nullable parameter" ist hier schon
  aktiv. `?string $x = null` statt `string $x = null` schreiben, auch
  wenn die Production noch auf 8.4 läuft.
- Gas-Tests sind ungeeignet als Default-Fixture: der Conversion-Faktor
  `gas_kwh_per_m3` (Default 11.5) verzerrt erwartete Verbrauchssummen.
  Für Bridging-Unit-Tests `strom` wählen (`unit_to_kwh = false`,
  Conversion-Faktor = 1.0).

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.6.1] — 2026-05-21 — Bugfix: Wasser-KPI & Zählerwechsel-Ausschlag

### Fixed

- **Issue [#14] — Wasser-Sub-Dashboard zeigte 0 m³ Verbrauch.**
  `public/js/views/utility.js` summierte das KPI „Verbrauch" und die
  Spalte „m³" der Monatstabelle stur aus `m.kwh`. Wasser ist im
  Backend m³-nativ — `applyUtilityFields` legt den Wert nach
  Aggregation in `m.m3` um und nullt `m.kwh`. Folge: Wasser-View
  zeigte 0, obwohl Haupt-Dashboard und `m³/Tag`-Spalte (gespeist aus
  einem vor der Umlage gesetzten Feld) korrekt waren. Fix: `consKey`
  (`'kwh'` für kWh-Utilities, `'m3'` für m³-Utilities) wird in beiden
  Render-Funktionen (`render` und `monthlyTable`) auf Funktions-Scope
  gehoben und konsistent verwendet — KPI-Wert, Monatstabellen-Zelle
  und Footer-Total.

- **Issue [#13] — Riesiger Ausschlag im Monat des Zählertauschs.**
  Bei einem Tausch sah das Dashboard einen Spike von ~200 000 kWh
  (Gas) bzw. ~1 100 m³ (Wasser) im Wechsel-Monat. Vier Ursachen
  wurden gefunden und gefixt:

  1. **`MeterService::replaceDevice`** setzte still
     `final_counter = 0`, wenn das Frontend das Feld leer ließ. Das
     ist eine versteckte Datenkorruption: nach `replaceDevice`
     scheint das alte Gerät „sauber geschlossen", obwohl der echte
     Endstand fehlt. Jetzt wirft die API einen 400-Fehler, wenn
     `old_final_counter` fehlt.

  2. **Off-by-one in `MeterService::deviceOnDate`** und
     `ConsumptionService::deviceIdOnDate`: am Tausch-Tag
     (`removed_on`) wurde noch das ALTE Gerät zurückgegeben, weil
     `$date > $d['removed_on']` am exakten Wechsel-Tag false ist.
     Ablesungen am Tausch-Tag bekamen dadurch zur Anlegezeit
     fälschlich `device_id=alt`. Geändert auf `$date >=
     $d['removed_on']` → am Wechsel-Tag greift das neue Gerät.

  3. **Plausibilitäts-Check in `ConsumptionService::consumptionBetween`**:
     wenn der Vor-Stand (`prev.counter`) eines Bridging-Intervalls
     außerhalb des Wertebereichs `[initial_counter_alt,
     final_counter_alt]` des angeblich alten Geräts liegt, ist die
     `device_id` der Ablesung offensichtlich falsch zugewiesen
     (typischer Fall: Tausch-Tags-Ablesung mit dem frischen Stand des
     neuen Zählers — z. B. 0,1 m³ — wurde fälschlich `device=alt`
     gespeichert). Das Intervall wird verworfen statt einen
     200-fachen Ausschlag zu rechnen. Schützt auch bei
     Bestandsdaten, die noch mit dem Off-by-one (#2) angelegt wurden.

  4. **`AnomalyService::detect`** ignoriert Wechsel-Monate. Über ein
     neues `device_swap`-Flag pro Monatszeile (gesetzt von
     `ConsumptionService::markSwapMonths` für alle Monate, in denen
     `installed_on` oder `removed_on` eines nicht-initialen Geräts
     liegt) werden Tausch-Monate aus der z-Score-Erkennung
     ausgeschlossen — ein Tausch ist ein erklärlicher Sondereffekt,
     keine fachliche Anomalie.

### Tests

- `tests/frontend-api-shape.test.js` ergänzt um zwei Regressions-
  Checks: (a) Wasser-Monthly hat `m3 ≠ 0`, `kwh = 0`; (b)
  Monatszeilen tragen `device_swap`-Flag. **14/14 Checks bestanden.**
- `tests/browser-render.test.mjs` unverändert. **34/34 bestanden.**

### Migration

Keine Datenmodell-Änderungen. Schema bleibt **1.1.0**.

Hinweis: bestehende Ablesungen, deren `device_id` am Tausch-Tag durch
den alten Off-by-one falsch gesetzt wurde, bleiben in der JSON
gespeichert wie sie sind — der Plausibilitäts-Check (#3 oben)
verhindert lediglich den falschen Ausschlag im Chart. Wer die
Zuordnung sauber korrigieren möchte: Ablesung am Tausch-Tag im UI
löschen und neu anlegen (sie bekommt dann mit v1.6.1 die korrekte
`device_id=neu`).

### Lessons Learned

- **Default-Werte sind eine versteckte Datenkorruption.**
  `(float)($input['old_final_counter'] ?? 0)` ließ einen unvollständig
  konfigurierten Tausch aussehen wie einen sauber geschlossenen. Eine
  fehlende Pflichtangabe muss eine explizite 400-Antwort sein, kein
  stiller Default.
- **Off-by-one am Stichtag.** `>` und `>=` am Wechsel-Tag waren
  semantisch nicht durchgehend gleich definiert. Wenn ein Intervall
  am Tag X endet und das nächste am Tag X beginnt, gehört X
  konventionsmäßig zum NEUEN Intervall. Diese Konvention muss
  überall identisch sein (Anlage UND Auswertung).
- **Frontend-Backend-Feldnamen über mehrere Stages.** `kwh_per_day`
  wurde VOR `applyUtilityFields` gesetzt (also auf dem rohen `kwh`-
  Feld), dann legte `applyUtilityFields` `kwh` nach `m3` um. Beide
  Felder konnten danach widersprüchliche Aussagen tragen
  (M³-Spalte 0, M³/Tag 0,3). Lehre: utility-spezifische Umlagen
  müssen entweder ganz am Ende stattfinden ODER alle abgeleiteten
  Felder konsistent mitführen.
- **Defensive Plausibilitäts-Checks > kosmetische Ausschlag-
  Filter.** Erste Idee war ein „verwerfen wenn `total > 100×
  finalOld`". Das hat den Bug NICHT gefangen, weil Viktors Werte
  knapp darunter lagen. Erst der **inhaltliche** Check „liegt
  `prev.counter` überhaupt im Wertebereich des angeblich alten
  Geräts" greift, weil er die Ursache (falsche device_id) prüft,
  nicht das Symptom (großer Wert).

[#13]: https://github.com/Bingerminger/energietracker/issues/13
[#14]: https://github.com/Bingerminger/energietracker/issues/14

---

## [1.6.0] — 2026-05-18 — F1004: Zentrale Zählerstand-Erfassung

### Added

- **F1004 — Zentraler Zählerstand-View** (`#/zaehlerstaende`).
  Neuer Menüpunkt an erster Position (eigene Gruppe „Erfassung"),
  mobile-first für die schnelle Vor-Ort-Erfassung auf dem iPhone.
  Bündelt alle kumulativen Zähler (**Gas, Strom, Wasser, Fernwärme**)
  in einer Karten-Liste mit jeweils:
  - Bezeichnung + Standort + Verbrauchsart-Icon
  - letzter bekannter Stand (Wert, Datum, ggf. „geschätzt"-Tag)
  - Eingabefeld für den neuen Stand (`inputmode="decimal"` →
    Zahlentastatur)
  - Datumsfeld (Default heute, pro Zeile überschreibbar)
  - „Geschätzt"-Toggle (mappt auf das bestehende `is_estimated`-Flag)
  - aufklappbare Notiz
  Ein Sticky-Save-Button am unteren Rand speichert alle ausgefüllten
  Karten sequenziell.

- **Neuer Aggregat-Endpunkt** `GET /api/readings-overview`. Liefert
  alle aktiven kumulativen Zähler plus jeweils letzte reale Ablesung
  in einem Roundtrip — beim Öffnen der Ansicht **ein** API-Call.
  Speichern erfolgt danach pro Zeile über die bestehende Route
  `POST /api/utility/{u}/readings`.

- **Mobile-First-Optimierung.** Touch-Targets ≥ 48 px,
  `env(safe-area-inset-bottom)` für den iPhone-Home-Indicator-Bereich,
  einspaltiges Layout unter 600 px, Karten-Maxbreite 720 px für
  Desktop/Tablet.

- **Inline-Validierung.** Rückwärts-Zählerstand wird mit einem
  orangefarbenen Hinweis markiert (nicht hart blockiert — Zählertausch
  ist ein realer Fall). Leere Karten werden beim Speichern still
  übersprungen.

- **Robust gegen Teilfehler.** Eine fehlerhafte Karte blockiert die
  anderen nicht; pro Karte erscheint ✓ oder ✗, am Ende fasst ein
  Toast zusammen. Nach erfolgreichem Speichern wird der „letzter
  Stand" in der Karte aktualisiert.

- **Doku.** Neue Seite [`docs/functional/11-zaehlerstaende.md`](docs/verstehen/11-zaehlerstaende.md)
  mit Aufbau, Validierungsregeln, Mobile-First-Designentscheidungen
  und expliziter Liste der bewusst nicht in v1.6.0 enthaltenen
  Features (Foto, Offline-Cache, OCR, Batch-Endpoint).

- **Tests.** `frontend-api-shape.test.js` deckt den neuen Endpunkt
  (Shape, Scope-Whitelist) ab; `browser-render.test.mjs` deckt die
  neue View (Render, Karten-Anzahl, `inputmode="decimal"`,
  ISO-Datum, Sticky-Save, Delivery-Ausschluss) ab. Stand v1.6.0:
  **12/12 Frontend-API-Shape**, **34/34 Browser-Render**.

### Internal

- `ReadingService::overview(array $activeUtilities)` als
  Aggregations-Helfer. Filtert auf `Utilities::isCumulative()` und
  ignoriert inaktive Zähler.
- `ReadingController::overview()` bindet den Endpunkt an;
  Settings-Injection im Konstruktor.
- Frontend: neues Modul `public/js/views/readings-entry.js`,
  Sidebar-Eintrag in eigener Gruppe „Erfassung" oberhalb des
  Dashboards, Router-Eintrag `#/zaehlerstaende`, API-Client-Methode
  `api.readingsOverview()`, neues Stylesheet
  `public/css/readings-entry.css`.

### Migration

- **Kein Migrationsschritt nötig.** Reine additive Erweiterung
  (neuer Endpunkt, neue View, kein Schemafeld). Bestehende
  Readings/Meters/Contracts unverändert. Schema bleibt **1.1.0**.

### Bewusst nicht enthalten

- Foto-Aufnahme, Offline-Modus, OCR/Ziffernerkennung,
  Batch-Speicher-Endpunkt. Begründet und dokumentiert in der
  funktionalen Doku (`11-zaehlerstaende.md`, Abschnitt „Was bewusst
  nicht in v1.6.0 ist").

---

## [1.5.1] — 2026-05-18 — CI-Fix: Testserver-Routing (router.php)

### Fixed

- **Browser-Render-Test in der CI scheiterte am Modulgraph-Crawl.**
  Der Testserver wurde mit `php -S … api.php` gestartet — damit lief
  **jeder** Request (auch `/public/js/app.js`) durch `api.php`, das nur
  `/api/*` kennt → HTTP 404 für statische Assets, der Modulgraph-Crawl
  brach ab. Neu: **`router.php`** als Built-in-Server-Router, der das
  nginx-Verhalten spiegelt:
  1. existierende statische Dateien direkt ausliefern,
  2. `/data/` und `/src/` sowie `*.php`-Quelltext mit 404 sperren
     (wie `location ~ ^/data/ { deny all; }`),
  3. `/api.php/…` **und** `/api/…` an `api.php` delegieren
     (SCRIPT_NAME korrekt gesetzt),
  4. sonst `index.php` (SPA-Shell, entspricht `try_files`).
- **CI-Server-Step überlebte den Schritt nicht.** Server-Start (`&`)
  und Testläufe lagen in getrennten `run:`-Blöcken; in GitHub Actions
  ist jeder `run:` eine eigene Shell, der Hintergrundprozess war im
  Folge-Step weg. Server-Start, Readiness-Probe, beide Test-Suites und
  Teardown (`trap … EXIT`) laufen jetzt in **einem** Step.
- Doku (`tests/README.md`, `docs/technical/05-testing.md`) auf
  `router.php` umgestellt, inkl. Begründung warum nicht `api.php`.

### Verifikation

- `frontend-api-shape`: **9/9** mit `router.php`.
- `browser-render`: Modulgraph lädt **alle 21 Module** via HTTP (200);
  der ursprünglich fehlschlagende Crawl ist behoben. Voller
  Suite-Durchlauf lokal mit altem Router bereits **28/28**; die
  Router-Änderung ist rein additiv/strikter (statische Auslieferung
  und `/api/`-Routing nachweislich 200, `/data//src` nachweislich 404).

### Hinweis

- **Kein Eingriff in Anwendungscode.** Reine Test-/CI-Infrastruktur.
  `router.php` wird ausschließlich von `php -S` benutzt; Produktiv­
  betrieb läuft unverändert über nginx/Apache. Schema unverändert 1.1.0.

---

## [1.5.0] — 2026-05-17 — F1003: Sonderzahlungen

### Added

- **F1003 — Sonderzahlungen bei vertragsrelevanten Energieträgern**
  (Gas, Strom, Fernwärme). Pro Standard-Vertrag lässt sich eine Liste
  von Sonderzahlungen pflegen, mit exakt fünf Arten:
  1. **Rückzahlung (mit Auswirkung auf Abschlagszahlungen)** — Guthaben
     vom Versorger; setzt zusätzlich einen neuen Monatsabschlag ab
     Stichtag.
  2. **Rückzahlung (ohne Auswirkung auf Abschlagszahlungen)** — reine
     Gutschrift, Abschlag unverändert.
  3. **Nachzahlung (mit Auswirkung auf Abschlagszahlungen)** — Zuzahlung
     nach Abrechnung; setzt zusätzlich einen neuen Monatsabschlag.
  4. **Nachzahlung (ohne Auswirkung auf Abschlagszahlungen)** — reine
     Zuzahlung, Abschlag unverändert.
  5. **Abschlagszahlung** — zusätzliche/einmalige Abschlagszahlung des
     Kunden.
- **Saldo-Integration.** Der Saldo rechnet jetzt
  `Kosten − gezahlte Abschläge + Sonderzahlungs-Netto`, wobei
  `Netto = Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung`.
  Rückzahlungen gleichen eine Überzahlung aus (Saldo steigt),
  Nach-/Abschlagszahlungen senken die Schuld.
- **„mit Auswirkung auf Abschlagszahlungen".** Solche Einträge tragen
  zusätzlich `new_advance_eur` + `advance_from`. Diese Punkte werden in
  den effektiven Abschlagsplan (`ContractService::effectiveAdvance‐
  Schedule()`) gemischt; jede monatliche Abschlagsbildung greift
  automatisch — ohne Sonderlogik in der Saldo-Aggregation.
- **UI.** Neue Sektion „Sonderzahlungen" im Vertragsformular (nur
  Gas/Strom/Fernwärme), mit 5-Arten-Auswahl und kontextabhängig
  eingeblendeten Abschlagsfeldern. Die Saldo-Karte weist
  Rückzahlung/Nachzahlung/Abschlagszahlung getrennt aus; die
  Vertragskarte zeigt die Anzahl.
- **Validierung** (F4-analog): leere Zeilen werden still verworfen,
  halb-gefüllte Datum/Betrag-Zeilen abgelehnt, unbekannte Arten
  abgelehnt, `*_mit` mit nur einem von neuem-Abschlag/Stichtag
  abgelehnt. Beträge werden auf positiv erzwungen (Vorzeichen ergibt
  sich aus der Art).

### Internal

- `Utilities::hasAdvancePaymentContracts()` als Single-Source-of-Truth
  für den F1003-Scope (kumulativ und nicht Wasser → Gas/Strom/
  Fernwärme). Frontend spiegelt dieselbe Logik.
- `ContractService`: `normalizeSpecialPayments()`,
  `effectiveAdvanceSchedule()`, `specialPaymentSummary()`.
- `ConsumptionService` nutzt im Standard-Pfad den effektiven
  Abschlagsplan statt `advance_payments` direkt; Wasser-Pfad
  unverändert (No-op, da Wasser keine Sonderzahlungen hat).
- `contractStatus()` liefert zusätzlich `special_refund_total`,
  `special_surcharge_total`, `special_advance_total`,
  `special_payment_net`, `special_payments_count`.

### Migration

- **Kein Migrationsschritt nötig.** `special_payments` ist additiv und
  defaultet beim Normalisieren auf `[]` (gleiches Muster wie
  `bonuses`). Bestandsverträge ohne das Feld funktionieren unverändert.
  Schema bleibt **1.1.0** (abwärtskompatibel).

---

## [1.4.5] — 2026-05-17 — CI: Node-24-Action-Runtime

### Fixed

- **GitHub-Actions-Deprecation-Warnung behoben.** `actions/checkout`
  und `actions/setup-node` von `@v4` auf `@v5` gehoben. Die `@v4`-Tags
  laufen intern auf Node.js 20, das von GitHub deprecated wurde (ab
  2026-06-02 ist Node 24 Runner-Default, ab 2026-09-16 wird Node 20
  entfernt). `@v5` ist die etablierte Node-24-Baseline. **Kein
  funktionaler Eingriff** — die CI lief auch mit `@v4` grün durch
  (es war eine Warnung, kein Fehler); der Bump entfernt sie und macht
  die Pipeline zukunftssicher.
- Bewusst **nicht** geändert: `node-version: "20"` im Test-Job (das ist
  die Node-Version für die *Testausführung*, nicht die Action-Runtime
  — die Test-Harnesses sind auf Node ≥ 20 ausgelegt) sowie
  `shivammathur/setup-php@v2` (von der Deprecation nicht betroffen,
  in GitHubs Warnung nicht gelistet).

---

## [1.4.4] — 2026-05-17 — Code-Qualität, CI-Pipeline, Audit-Härtung

### Added

- **CI/CD-Pipeline (GitHub Actions).** Neues `.github/workflows/ci.yml`
  mit zwei Jobs:
  - `test` — startet den PHP-Backend-Server mit Demo-Daten, führt
    `frontend-api-shape.test.js` und `browser-render.test.mjs` aus.
  - `lint-php` — prüft die Syntax aller PHP-Dateien parallel mit
    `php -l` (PHP 8.4). Beide Jobs laufen auf `push` und `pull_request`
    gegen `main`.
- **`ET_DATA_DIR`-Umgebungsvariable in `api.php`.** Der Storage-Pfad
  ist jetzt per ENV überschreibbar (Fallback: `./data`). Ermöglicht
  CI-Tests gegen `/tmp/etdata` ohne Änderung an produktivem Code.
- **`DeliveryConsumptionService` (neue Klasse).** Die drei Delivery-
  Berechnungsmethoden (`dailyDeliveryConsumption`, `dailyDeliveryStockDraw`,
  `deliveryMeterStartDate`) wurden aus `ConsumptionService` in eine
  eigenständige Klasse extrahiert. `ConsumptionService` delegiert über
  schlanke Wrapper; das öffentliche API bleibt vollständig kompatibel.
  Der interne Dispatch (`computeForDeliveryMeter`) nutzt einen
  Lazy-Getter, der auch funktioniert, wenn `DeliveryConsumptionService`
  nicht explizit injiziert wird — kein Breaking Change für bestehende
  Aufrufer. `ConsumptionService` schrumpft dadurch um ~350 Zeilen.

### Fixed

- **`JsonStore::path()` Traversal-Schutz (Defense-in-Depth).** Nach dem
  Pfadaufbau wird per `realpath` + Präfix-Check geprüft, dass der
  resultierende Pfad innerhalb von `rootDir` liegt. Bisher war nur der
  Service-Layer-Whitelist-Check in `Utilities::exists()` aktiv; der neue
  Schutz auf Speicherebene ist unabhängig davon und fängt jeden Pfad ab,
  der aus dem Datenverzeichnis ausbricht.
- **`DiagnosticsService` Fallback-Version.** `'1.2.0'` → `'unknown'`.
  Der Fallback tritt nur auf, wenn die `VERSION`-Datei fehlt; eine
  konkrete veraltete Versionsnummer war irreführend.
- **Demo-Daten `schema_version`.** `demo-data/meta.json` wurde auf
  `"schema_version": "1.1.0"` aktualisiert und `demo-data/reminders.json`
  angelegt. Damit startet eine Demo-Instanz ohne unnötige
  Migrations-Durchläufe und der Migrator-Status ist konsistent mit dem
  tatsächlichen Datenstand.

### Changed

- **`tests/backend-shape.test.js` → `tests/frontend-api-shape.test.js`.**
  Datei umbenannt — der neue Name spiegelt die tatsächliche Perspektive
  wider (Frontend-seitige Erwartungen an die API-Shapes, nicht
  Backend-interne Prüfung). Leerer `loadModule`-Stub entfernt (war
  toter, nie aufgerufener Code). `tests/README.md` entsprechend
  aktualisiert.
- **Stale Versions-Kommentare bereinigt.** `utility.js` (trug `v1.0.2`),
  `api.js` (`v1.2.0`) und `api.php` (`v1.2.0`) trugen veraltete
  Versionsnummern im Datei-Header. Kommentare auf neutralen Text ohne
  konkrete Versionsnummer umgestellt — die kanonische Quelle ist
  `VERSION`.

### Internal

- `src/bootstrap.php` initialisiert `DeliveryConsumptionService` explizit
  vor `ConsumptionService` und übergibt es als Abhängigkeit.
- `ConsumptionService`-Konstruktor hat neuen optionalen Parameter
  `?DeliveryConsumptionService $deliveryConsumption = null` (abwärtskompatibel).

---

## [1.4.3] — 2026-05-16 — Sigmoid in der Analyse, Vertragslogik je Energieart, valides Doku-Markdown, korrekter App-Name

### Fixed

- **#1 Sigmoid-Kurve fehlte im Analyse-Korrelationschart.** Der
  HGT-Streudiagramm-Block iterierte nur über vier Modelle (linear,
  polynomial, robust, segmentiert). `sigmoid` ist jetzt ergänzt: in der
  Vorhersagefunktion (spiegelt `RegressionService::sigmoidPredict`
  exakt — numerisch verifiziert, Abweichung < 1e-9), im Kurvenstil, in
  der Modell-Iteration und in der R²-Koeffizienten-Übersicht.
- **#2 Vertrags-Views ergaben für lieferbasierte Arten keinen Sinn.**
  Heizöl/Pellets haben per Logik keine Verträge (die Tankrechnung ist
  die Kostenbasis). Behoben an drei Stellen:
  - `utility.js` blendet die Vertrags- und Saldo-Karte für
    lieferbasierte Arten aus.
  - Die Vertrags-View (`contracts.js`) zeigt für Heizöl/Pellets einen
    erklärenden Hinweis statt eines (sinnlosen) Gas-Vertragsformulars
    und leitet zu den Lieferungen.
  - Der Tarifvergleich (`tariff.js`) schließt lieferbasierte Arten aus
    (Schattenverträge sind dort nicht anwendbar) — zusätzlich zum
    bereits ausgeschlossenen Wasser.
  - Geprüft und bestätigt: **Fernwärme** ist korrekt kumulativ mit
    echten Verträgen (Arbeits- + Grundpreis) — bleibt unverändert.
- **#3 Doku-Markdown wurde teils falsch dargestellt.** Alle
  LaTeX-Formeln (`$…$` / `$$…$$`) wurden durch GitHub-sichere
  Klartext-Codeblöcke ersetzt (rendern überall identisch). Das
  Architektur- und das Analysezyklus-Diagramm wurden mit sauber
  ausgerichtetem Plain-ASCII neu gezeichnet. Alle Codeblöcke erhielten
  eine Sprachangabe; geprüft: balancierte Fences, keine defekten
  internen Links, keine LaTeX-Reste, keine render-kritischen
  Lint-Fehler.
- **#4 Falscher App-Name im Topbar-Titel.** „ENERGIE TRACKING" →
  **„ENERGIETRACKER"** (einzige Fundstelle; die extrahierten Logo-Icons
  sind bereits textfrei).

### Changed

- **Test-Harness browser-realistisch erweitert.** `browser-render`
  löst relative URLs jetzt wie ein Browser gegen die Basis-URL auf und
  stellt einen Chart.js-Stub bereit (zuvor zwei Setup-Lücken, die
  Render-Checks blockierten). Neue Regressionstests für #1 (Sigmoid in
  der Analyse) und #2 (Liefer-Arten ohne Verträge / kumulative Arten
  mit Verträgen). Stand: **Backend-Shape 9/9, Browser-Render 28/28**.

### Verifiziert (numerische Logik)

- Eigenständige Referenz-Nachrechnung von 16 Kern-Formeln gegen den
  realen Code: HGT, lineare Tagesinterpolation, R², Regressions-
  steigung/-achsenabschnitt, Sigmoid (fit↔predict konsistent),
  Prognose-Blend (`w = min(R², blend_max)`).
- End-to-End: `total_eur`-Vorrang isoliert exakt (1500 €/2000 L → genau
  7,5000 ct/kWh, Energiebilanz aufs kWh genau); Saldo-Formel
  (`current_balance = actual_cost − advance_paid`) und Kostenzerlegung
  (`Arbeit + Grund − Bonus`) über alle Demo-Verträge mit 0,00
  Abweichung; Tank-Bestandskurve nie negativ / nie über Kapazität.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Reine UI-/Anzeige- und
  Doku-Korrekturen; keine Daten- oder API-Änderung.

---

## [1.4.2] — 2026-05-16 — Export aller Energiearten, Datumsformat, PDF-Kennzahlen, neues Logo, Doku-Kompendium

### Added

- **CSV-Lieferungs-Export** für lieferbasierte Arten:
  `GET /api/export/{utility}/deliveries.csv` (Heizöl/Pellets) mit
  korrekter Einheit (L bzw. kg) im Spaltenkopf.
- **Neues App-Icon** (Haus mit Farbring) aus dem gelieferten Logo
  extrahiert — Tag-/Nacht-Variante, als Favicon, Apple-Touch-Icon und
  themenabhängiges Topbar-Logo eingebunden (`public/img/`).
- **Doku-Kompendium** unter `docs/` — getrennt technisch
  (`docs/technical/`, 6 Kapitel), fachlich (`docs/functional/`, je
  Energieart + Szenarien + Glossar) und UI (`docs/ui/` mit Mockups
  aller 11 Views). Wird ab dieser Version bei jedem Release gepflegt.

### Fixed

- **#2 Export war nicht für alle Energiearten verfügbar.** Die
  Export-UI in den Einstellungen war auf gas/strom/wasser hartkodiert.
  Sie baut die Kacheln jetzt dynamisch aus den aktiven Verbrauchsarten;
  Fernwärme erhält Monats-/Ablesungs-Export, Heizöl/Pellets den neuen
  Lieferungs-Export.
- **#3 PDF-Diagramm ohne Achsen/Beschriftung.** Das achsenlose
  Mini-Liniendiagramm im Jahresbericht wurde entfernt und durch eine
  Kennzahlen-Leiste (Jahresverbrauch, Ø/Monat, Gesamtkosten,
  stärkster/schwächster Monat) plus die bestehende Monatstabelle
  ersetzt.
- **#4 Abrechnungszyklus im falschen Datumsformat.** Der Stichtag wurde
  als `MM-TT` angezeigt/eingegeben. Die UI nutzt jetzt das deutsche
  Format `TT-MM`; die Speicherung bleibt kanonisch `MM-TT`, damit das
  Backend valide Datümer baut (Konvertierung nur an der UI-Grenze,
  inkl. Validierung/Februar-Klemmung).

### Changed

- **#5/#6 Heizöl/Pellets-Kosten — Gesamtbetrag hat Vorrang.** Ist auf
  einer Tankrechnung der Gesamtbetrag (`total_eur`) erfasst, wird
  dieser als Kostenbasis genutzt (enthält Liefergebühr/Rabatt); der
  effektive Stückpreis wird daraus abgeleitet. Nur ohne Gesamtbetrag
  wird wie bisher `unit_price_cents` × Menge gerechnet. Bestätigt:
  Heizöl/Pellets haben bewusst **keine** Vertrags-Entität — die
  Tankrechnung selbst ist die Kostenbasis.
- **Doku ersetzt.** Die alten Einzeldateien `docs/API.md`,
  `docs/ARCHITECTURE.md` und `docs/screenshots/` sind in das
  strukturierte Kompendium übergegangen. `docs/MIGRATION-FROM-V090.md`
  bleibt erhalten und wird aus dem Kompendium verlinkt.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Neue Exporte und der
  neue Lieferungs-Endpoint sind rein additiv. Bestehende
  `billing_cycle_anchor_*`-Werte bleiben gültig (kanonische Speicherung
  unverändert `MM-TT`; geändert wurde nur die Anzeige).

---

## [1.4.1] — 2026-05-16 — Bugfix: Sigmoid-Modell in der Prognose wählbar

### Fixed

- **Sigmoid-Regressionsmodell war in der Prognose nicht auswählbar.**
  Der Modell-Selektor in der Forecast-Ansicht
  (`public/js/views/forecast.js`) listete nur vier der fünf Modelle —
  `sigmoid` fehlte, obwohl es in den Einstellungen als Standardmodell
  wählbar war und das Backend (`ForecastService` →
  `RegressionService::fit`) es längst unterstützte. Reine Frontend-
  Lücke; Option ergänzt. Der Browser-Render-Test prüft jetzt, dass
  alle fünf Modelle (linear, polynomial, robust, segmented, sigmoid)
  in der Prognose wählbar sind.

### Migration

- Keine Schema-, Datenmodell- oder API-Änderung. Reiner UI-Bugfix.

## [1.4.0] — 2026-05-16 — Tank-Bestandsmodell & Effizienz pro Heizquelle

Korrektur zweier Modell-/Darstellungsschwächen, die beim Anlegen der
Demo-Daten für die neuen Energieträger sichtbar wurden, plus ein
kritischer Startup-Bugfix.

### Fixed

- **App startete nicht (kritisch).** `public/js/lib/sidebar.js`
  importierte `./state.js` / `./api.js` statt `../state.js` /
  `../api.js`. Da `sidebar.js` von `app.js` geladen wird, brach dieser
  404 den gesamten ES-Modulgraphen — die Oberfläche blieb bei „Lade…"
  stehen. Pfade korrigiert; neuer Modulgraph-Regressionstest
  (`tests/browser-render.test.mjs`) crawlt `app.js` samt aller
  transitiven Importe über HTTP und fängt solche Fehler künftig.
- **Tank-Bestandskurve zeigte konstruktionsbedingt immer ~0 %.** Das
  alte Modell verteilte die *gesamte* gelieferte Energie HGT-gewichtet
  über die Laufzeit und erzwang damit Endbestand ≈ 0 — unabhängig von
  den Liefermengen. Zusätzlich mischte `stockHistory()` Liter mit kWh
  (latenter Einheitenfehler, von der 0-Normierung maskiert). Neu:
  `ConsumptionService::dailyDeliveryStockDraw()` berechnet den Abzug in
  Mengeneinheiten aus einer **aus den geschlossenen Lieferintervallen
  kalibrierten kWh/HGT-Rate**. Die Bestandskurve ist jetzt ein
  realistischer Sägezahn ohne Zwang auf 0. Die Kosten-/Effizienz-
  Berechnung (`dailyDeliveryConsumption()`, Energiebilanz) bleibt
  unverändert — sie ist für die Verbrauchs-/Kostensicht korrekt.

### Changed

- **Effizienz-Benchmark jetzt pro Heizquelle.** Statt alle
  Heizenergie-Arten zu summieren (was bei mehreren aktiven Heizquellen
  eine unsinnige Klasse ergab), weist `/api/benchmarks/efficiency` nun
  `per_source` (Klasse je Quelle), `primary` (größte Quelle) und
  `combined` (Summe, nur für bewusst kombinierten Heizbetrieb) aus.
  Die Top-Level-Felder `class`/`kwh_per_m2`/`total_kwh` bleiben als
  rückwärtskompatible Aliase erhalten, zeigen aber nun die **primäre
  Heizquelle** statt der Summe. Dashboard-Karte und PDF-Bericht zeigen
  bei mehreren Quellen eine Aufschlüsselung; die Empfehlungsregel R7
  bewertet jede Quelle einzeln.
- **Demo-Daten vollständig.** Demo-Datensätze für Fernwärme, Heizöl und
  Pellets ergänzt (zuvor nur Gas/Strom/Wasser), inkl. realistischer
  Tankgrößen und Lieferkadenz, sodass jede Verbrauchsart ab Start
  bedienbar ist.

### Migration

- Keine Schema- oder Datenmodell-Änderung; Schema bleibt **1.1.0**.
  Reine Berechnungs-/Darstellungs- und Bugfix-Änderungen. API-Antwort
  von `/api/benchmarks/efficiency` ist additiv erweitert; bestehende
  Felder bleiben kompatibel.

---

## [1.3.0] — 2026-05-16 — Lieferbasierte Energieträger, Effizienz, Insights

Das bislang umfangreichste Release. Drei neue Verbrauchsarten, ein
zweites Datenmodell (lieferbasiert statt kumulativ), Wetterbereinigung,
Effizienzklassen, eine Empfehlungs-Engine, Termin-/Wartungsverwaltung,
Tarifvergleich mit Schattenverträgen und ein PDF-Jahresbericht.

**Schema-Migration 1.0.3 → 1.1.0** — automatisch und idempotent beim
ersten Start. Bestehende Gas-/Strom-/Wasser-Daten bleiben unverändert;
neue Verbrauchsarten und `data/reminders.json` werden angelegt.

### Added

- **Drei neue Verbrauchsarten.** Fernwärme (kumulativ, kWh,
  HGT-relevant), Heizöl und Pellets (beide **lieferbasiert** —
  Brennstofflieferungen statt Zählerständen). Heizöl rechnet in Litern,
  Pellets in kg; Energiegehalt je Einheit ist konfigurierbar
  (`heizoel_kwh_per_l`, `pellets_kwh_per_kg`).
- **Lieferbasiertes Datenmodell.** Für Heizöl/Pellets werden Lieferungen
  erfasst (`{date, quantity, unit_price_cents, total_eur, supplier,
  note, is_planned}`). Der Monatsverbrauch wird aus
  Anfangsbestand + Lieferungen energetisch bilanziert und über einen
  konfigurierbaren Sockelanteil (flach) plus HGT-Gewichtung (Rest)
  auf die Monate verteilt. Tank-Bestandskurve über die Zeit.
- **Wetterbereinigung.** Monatstabelle HGT-relevanter Arten erhält
  `expected_hgt` (Regressionserwartung), `weather_adjusted`
  (auf das langjährige Kalendermonats-HGT normierter Verbrauch nach
  VDI-3807-Logik) und `delta_pct`. Schwachlastmonate werden korrekt
  ausgeblendet.
- **Effizienzklasse.** Heizenergiebedarf in kWh/m²·a über alle
  Heizenergie-Arten, eingestuft in A+…H gegen konfigurierbare
  Bandgrenzen (`/api/benchmarks/efficiency`). Wohnfläche, Baujahr und
  Gebäudetyp sind in den Einstellungen pflegbar.
- **Empfehlungs-Engine.** Sieben rein statistische Regelfamilien aus
  den Eigendaten (wetterbereinigter Mehrverbrauch, schleichender Trend,
  hoher Sommerverbrauch, Anomalie, Tank-Niveau, Vertragsende,
  Effizienzklasse) mit Schweregrad und stabiler ID; einzeln
  ausblendbar (`/api/recommendations`).
- **Termin- & Wartungsverwaltung.** Wiederkehrende Termine
  (Heizungswartung, Schornsteinfeger, Zähler-Eichfristen u. a.) mit
  Fälligkeitsstatus und Recurrence-Fortschreibung beim Erledigen
  (`/api/reminders`).
- **Tarifvergleich mit Schattenverträgen.** Hypothetische Tarife
  (`is_shadow`) lassen sich auf die tatsächlichen historischen
  Verbräuche rechnen und echten Verträgen gegenüberstellen, ohne Saldo
  oder Prognose zu beeinflussen
  (`/api/utility/{u}/meters/{id}/tariff-comparison`).
- **PDF-Jahresbericht.** Mehrseitiger A4-Bericht (Deckblatt,
  Übersicht/Effizienz, je Verbrauchsart Tabelle + Verlaufsdiagramm,
  Empfehlungen) als Datei-Download — erzeugt von einem eigenen,
  abhängigkeitsfreien PDF-Writer (`/api/reports/yearly.pdf`).
- **Neue Regressionsmodelle.** `sigmoid` (Heizsignatur nach
  TU-München/BDEW-Form, robust gefittet) sowie `segmented` mit
  **datenbasiertem Knickpunkt** (`segmented_split_mode = auto`).
- **Aktivierbare Verbrauchsarten.** `active_utilities` steuert, welche
  Arten in Sidebar, Dashboard und Bericht erscheinen — inaktive Daten
  bleiben erhalten. Sidebar wird dynamisch daraus aufgebaut.
- **Dashboard-Insight-Karten.** Effizienzklasse, Tank-Bestände,
  Top-Empfehlungen und anstehende Termine auf einen Blick.

### Changed

- **Einstellungen erweitert** von 28 auf **50 Schlüssel** plus die
  `active_utilities`-Auswahl: Gebäude/Effizienz, Energieträger-
  Energiegehalte, Tank-Warnschwelle, Termin- und Empfehlungs-Schwellen,
  Abrechnungs-Stichtage der neuen Arten, Segment-Knickpunktmodus,
  `sigmoid` im Prognosemodell-Picker, PDF-Download.
- **Sidebar** ist nicht mehr statisch in `index.php` verdrahtet, sondern
  wird zur Laufzeit aus aktiven Verbrauchsarten und neuen Views
  (Tarifvergleich, Empfehlungen, Termine) erzeugt; Badges für offene
  Empfehlungen und fällige Termine.

### Migration

- Schema-Version steigt auf **1.1.0**. Der Migrator erkennt 1.0.3 und
  ergänzt fehlende Verzeichnisse/Dateien idempotent — kein manueller
  Schritt nötig. Ein Sicherheits-Snapshot wird wie üblich vor dem
  ersten Schreiben angelegt. Ein Downgrade auf 1.0.x wird nicht
  unterstützt (die neuen Verbrauchsarten würden ignoriert).

### Hinweise

- Der PDF-Writer nutzt bewusst **keine externe Bibliothek** (kein
  composer, mPDF, gd oder mbstring) — konsistent mit der
  abhängigkeitsfreien, flat-file-Architektur der App.
- Die Tank-Bestandskurve ist eine Modellschätzung (Anfangsbestand +
  Lieferungen − HGT-gewichteter Verbrauch), keine Tankpeilung; bei
  langen Lieferintervallen kann der modellierte Bestand 0 erreichen.
- Tarifvergleich ist für Wasser (Drei-Komponenten-Tarif) bewusst nicht
  enthalten.

---

## [1.2.0] — 2026-05-15 — Theme-Toggle + Diagnose-Bugfix

Tag/Nacht-Umschaltung für die gesamte UI plus eine kosmetische
Aufräumung der System-Diagnose. Keine Backend- oder Datenmodell-
Änderungen — Schema bleibt unverändert bei **1.0.3**.

### Added

- **Tag/Nacht-Umschaltung.** Ein Toggle-Knopf rechts in der Topbar
  schaltet zwischen Dunkel- und Hellmodus. Die Wahl wird in
  `localStorage["et-theme"]` persistiert; auf den ersten Besuch ohne
  gespeicherte Wahl wird `prefers-color-scheme` respektiert. Ein
  Anti-Flash-Inline-Skript in `index.php` setzt das Theme bereits vor
  dem CSS-Laden, damit es keinen Aufblitz-Effekt gibt. Solange der User
  noch nicht selbst geklickt hat, folgt die App OS-Theme-Änderungen
  live (z.B. macOS schaltet abends auf dunkel). Implementierung via
  `[data-theme="light|dark"]`-Attribut auf `<html>` plus CSS-Variablen
  in `tokens.css` — keine Style-Duplikation.

### Fixed

- **[#1] System-Diagnose-Optik.** In v1.0.4 → v1.1.0 sah die System-
  Diagnose (unter Einstellungen) hässlich aus, vor allem die Zeilen
  `utilities`, `temperatures` und `settings_known_keys` waren rohe
  JSON-Dumps und liefen seitlich aus dem Layout. Neu:
  - Header-Felder (App-Version, PHP, Datenverzeichnis usw.) in einem
    sauberen Definition-Grid.
  - `utilities` als kompakte Tabelle mit Spalten Zähler / Ablesungen /
    Verträge / Letzte Ablesung.
  - `temperatures` als „N Tageswerte gespeichert"-Klartext.
  - `settings_known_keys` als gewickelte Mono-Chips.
  - Boolean-Felder (`data_dir_writable`, `curl_available`,
    `migration_needed`) als farbige Badges.

### Notes

- Mehrere bislang hardcodierte `rgba(…)`-Farbwerte in `app.css` und
  `components.css` wurden durch `var(--token)` bzw. `color-mix(in srgb,
  var(--token) NN%, transparent)` ersetzt, damit das Light-Theme korrekt
  durchschlägt. Funktional unverändert für das Dark-Theme.
- `components/chart.js` liest Theme-Farben jetzt live aus CSS-Variablen
  (per Proxy). Beim Theme-Wechsel werden `Chart.defaults` neu gesetzt;
  bereits gerenderte Charts ziehen die neuen Farben aber erst beim
  nächsten Re-Render an (eine Navigation in der App genügt).
- Keine neuen Settings-Keys, keine Datenmodell-Änderung. Schema-Version
  unverändert `1.0.3`.

[1.2.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.2.0

---

Erstes MINOR-Release nach der v1.0.x-Bugfix-Serie. Sieben neue Funktionen
und ein Datenmodell-Fix — alle additiv und abwärtskompatibel. **Das
Speicherschema bleibt unverändert bei 1.0.3**; die neuen Einstellungen
werden über die Defaults gemerged, eine `settings.json` aus einer älteren
Version funktioniert ohne Migrationsschritt weiter.

### Added

- **[F-02] Vertragsbasierte Kostenprognose.** Die Prognose rechnete bisher
  mit dem letzten bekannten Arbeitspreis. Jetzt löst der `ForecastService`
  pro Prognosemonat den dann aktiven Vertrag auf und verwendet den **für
  diesen Monat gültigen** Arbeits- und Grundpreis aus der Preishistorie —
  ein im Vertrag für die Zukunft gepflegter Preiswechsel schlägt damit
  korrekt in `cost_estimated` durch. Die Monatsdetail-Tabelle hat zwei
  neue Spalten: *projizierter Abschlag* und *laufender Saldo* (kumuliert
  Kosten − Abschlag). Künftige Boni werden nicht fortgeschrieben — nur im
  Vertrag mit Gutschriftdatum gepflegte Boni fließen ein.
- **[F-03] Abrechnungszyklus je Verbrauchsart.** Drei neue Settings-Keys
  `billing_cycle_anchor_gas|strom|wasser` (Format `MM-TT`, Default
  `01-01`). Der Saldo offener Verträge (`end = null`) wird nun bis zum
  nächsten Abrechnungsstichtag projiziert statt bis „heute + 12 Monate".
  Verträge mit gepflegtem Ende verwenden weiterhin dieses Ende.
- **[F-04] Jahresvergleich mit Monatsdeltas.** Die Korrelations-Ansicht
  bekommt ein eigenes Widget mit der Monat-für-Monat-Differenz der beiden
  jüngsten Jahre mit Daten — absolut und prozentual, inklusive
  Summenzeile über die gemeinsamen Monate. Das bestehende
  „Jahresvergleich"-Liniendiagramm bleibt unverändert daneben bestehen.
- **[F-05] Erinnerung an Vertragsende.** Die `contract-status`-Antwort
  enthält pro Vertrag `days_until_end`, `should_remind` und `remind_stage`
  (0–3). Drei Schwellen sind als Settings-Keys `contract_remind_days_1|2|3`
  konfigurierbar (Default 90 / 30 / 1 Tage). Die Korrelations-Ansicht
  zeigt fällige Verträge als gestuften Hinweis-Banner.
- **[F-06] CSV-Import von Ablesungen.** Neuer zähler-gebundener Endpoint
  `POST /api/utility/{utility}/meters/{id}/readings/import-csv` (Body:
  CSV als `text/plain`). Bereits vorhandene Ablesungen am selben Datum
  werden **überschrieben und im Ergebnis gemeldet** (`imported`,
  `overwritten`, `skipped`, `errors`). Akzeptiert `;`/`,` als Trenner,
  `TT.MM.JJJJ` oder ISO-Datum, deutsches Dezimalkomma. Die Import-Logik
  steckt im quell-agnostischen `ReadingImportService` — eine künftige
  Smart-Meter-Anbindung (siehe Roadmap) kann denselben Kern ohne
  CSV-Parsing wiederverwenden. UI: „CSV-Import"-Knopf je Zähler in der
  Zählerverwaltung.
- **[F-07] CSV-Export.** Drei neue Endpoints liefern tabellarische
  Exporte als Datei-Download: `GET /api/export/{utility}/monthly.csv`
  (Monatsaggregate), `GET /api/export/{utility}/readings.csv`
  (Rohablesungen), `GET /api/export/temperatures.csv` (Temperaturreihe).
  Semikolon-getrennt, UTF-8 mit BOM, deutsches Dezimalkomma — direkt in
  Excel/LibreOffice/Google Sheets nutzbar. UI: Export-Kacheln in den
  Einstellungen. Ergänzt das vollständige JSON-Backup, ersetzt es nicht.
- **[F-10] Wasser-Spar-Index.** Die Korrelations-Ansicht zeigt für Wasser
  einen Index `(Liter/Person/Tag) / Referenz × 100` über die jüngsten bis
  zu 12 Monate, mit Einordnung auf einem Band. Die Bandgrenzen sind als
  Settings-Keys `wasser_sparindex_gut` / `wasser_sparindex_warnung`
  konfigurierbar (Default 100 / 150).
- Einstellungs-Ansicht überarbeitet: gruppierte Settings-Karten in einem
  responsiven Raster, Feld-Erklärungen, Einheitenkennzeichnung. Das
  Settings-Inventar wächst von 20 auf 28 Schlüssel.

### Fixed

- **[#2 — Datenmodell] Schmutzwasser-Basis `separater_zaehler` war ohne
  Wirkung.** Im Wasser-Vertragsmodell (v1.0.3) konnte für die
  Schmutzwasser-Komponente die Basis `separater_zaehler` gewählt werden —
  die Verbrauchsberechnung nutzte aber in jedem Fall das Trinkwasser-m³.
  Jetzt löst der `ConsumptionService` die Basis `separater_zaehler` über
  das Feld `separater_zaehler_meter_id` auf und rechnet mit dem
  monatlichen m³ des referenzierten Zählers. Eine Rekursionssperre
  verhindert Endlosschleifen bei (fehlerhaft) gegenseitig
  referenzierenden Zählern. Kein Schema-Eingriff — das Feld existierte
  bereits, wurde nur nicht ausgewertet.

### Notes

- Schema-Version unverändert **1.0.3**. Keine Migration nötig: fehlende
  Settings-Keys werden beim Lesen aus den Defaults ergänzt.
- Bekannte Einschränkung F-02: bei Wasser mit Schmutzwasser-Basis
  `separater_zaehler` nutzt die *Vorausschau* das Trinkwasser-Volumen als
  Schmutzwasser-Basis (der separate Zähler wird nicht selbst prognostiziert).
  Die *historische* Auswertung rechnet das separate Volumen korrekt.

[1.1.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.1.0

---

Drei via GitHub gemeldete Bugs in der Vertragsverwaltung und in der
Korrelations-Ansicht.

### Fixed

- **[#1] Vertrags-Button irreführend benannt.** Auf den Utility-Seiten
  (Gas/Strom/Wasser) zeigte der Action-Button in der „Verträge &
  Abschläge"-Karte den Text *„+ Neuer Vertrag"* — er führte aber zur
  Vertragsverwaltungs-Seite, nicht zum Vertrag-Anlegen-Dialog. Neu:
  *„⚙️ Verträge verwalten"* mit passendem Icon. Das eigentliche
  Anlegen ist weiterhin in der Vertragsverwaltung selbst per
  „+ Neuer Vertrag"-Button erreichbar.

- **[#2] Speichern/Abbrechen-Buttons im Vertrag-Edit-Modal funktionierten
  nicht bei manchen Verträgen.** Wenn beim Aufbau des Modals ein
  Wiring-Schritt (Boni-Sektion oder Zeilen-Buttons einer Stichtag-
  Gruppe) eine Exception warf — etwa weil ein migriertes v0.9.0-Format
  unerwartete Strukturen mitbrachte — wurden die Fußleisten-Buttons nie
  verbunden, weil sie nach den anderen Handlern gebunden wurden.
  Fixes:
  - Cancel/Save werden jetzt **als allererstes** gebunden, bevor
    irgendein anderer Sub-Handler läuft. Selbst wenn ein nachfolgender
    Schritt fehlschlägt, bleibt das Modal benutzbar.
  - Alle DOM-Lookups in `bindRowHandlers`, `bindBonusHandlers`,
    `bindBonusRow` defensiv mit Optional-Chaining (`?.`). Fehlt ein
    Element, gibt's eine `console.warn`, aber kein TypeError mehr.
  - Die globale ID `#bonus-section` wurde durch `[data-section="bonus"]`
    ersetzt, damit gestapelte Modale nicht über IDs kollidieren können.

- **[#3] Anomalien-Tabelle in der Korrelations-Ansicht zeigte leere
  Werte.** Feldnamen-Mismatch zwischen Backend und Frontend:
  `AnomalyService` liefert `value` / `z_score` / `deviation` / `percent`
  / `kind` / `hdd` / `avg_temp` — das Frontend las `a.actual` und `a.z`
  (existieren nicht), wodurch *Verbrauch* und *Abweichung (σ)* immer
  NaN bzw. leer waren. Das schuf den Eindruck, dass die Anomalien nicht
  zu den eigentlichen Zählerdaten passten.
  Die Anomalien-Tabelle wurde dabei gleich angereichert: Absolute
  Abweichung, Prozent-Abweichung, σ-Wert, plus HGT und ø-Temperatur bei
  HGT-relevanten Utilities. Empty-State-Text erklärt jetzt, gegen welches
  Modell die Schwelle gerechnet wird.

### Notes

- Das geänderte Anomalien-Markup ist nicht-breaking; alle Backend-Felder
  waren bereits vorhanden, nur das Frontend hat sie schlicht nicht
  gelesen.
- Schema-Version (`data/meta.json`) bleibt auf `1.0.3` — keine
  Datenmodell-Änderung in v1.0.4.

[1.0.4]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.4

---

## [1.0.3] — 2026-05-11 — Wasser-Vertragsmodell

Fachlicher Bugfix am Wassermodul. In v1.0.2 wurden Wasserverträge mit
demselben Schema wie Gas und Strom modelliert (ein `working_prices`-Array
mit `ct_per_kwh`), was bei Wasser strukturell falsch ist: eine deutsche
Wasserrechnung enthält drei separate Positionen — Trinkwasser, Schmutzwasser
und Niederschlagswasser — mit jeweils eigener Berechnungslogik.

### Changed — Wasser-Vertragsmodell (Schema 1.0.3)

Wasserverträge haben jetzt drei Komponenten-Blöcke. Gas- und Strom-
verträge bleiben strukturell unverändert.

```json
{
  "id": "c_wasser_...",
  "meter_id": "m_wasser_haupt",
  "provider": "Kommunale Wasserwerke Leipzig",
  "tariff_name": "Trink-, Schmutz- und Niederschlagswasser 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",

  "trinkwasser": {
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 255.0}],
    "base_prices":    [{"from": "2025-01-01", "eur_per_month": 8.50}]
  },

  "schmutzwasser": {
    "basis": "trinkwasser",
    "separater_zaehler_meter_id": null,
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 305.0}]
  },

  "niederschlagswasser": {
    "rates": [
      {"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}
    ]
  },

  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

**Berechnungen pro Monat:**

- *Trinkwasser*: `m³ × ct_per_m3 / 100 + eur_per_month`
- *Schmutzwasser*: `m³ × ct_per_m3 / 100`. `m³` ist standardmäßig der
  Trinkwasser-Verbrauch (Basis `trinkwasser`, Standard in 95% der DE-Haushalte).
  Bei Basis `separater_zaehler` wird in v1.0.3 vorerst der gleiche Trinkwasser-
  Wert verwendet — eine echte Auswertung des separaten Zählers folgt in
  einer späteren Version.
- *Niederschlagswasser*: `(versiegelte_flaeche_m2 × eur_per_m2_year) / 12`.
  Stichtag-Historie für Tarif **und** Fläche, falls sich beides ändert.

### Added — Auto-Migration

Beim ersten Start auf v1.0.3 prüft `Storage\Migrator::needsWaterContractsUpgrade()`
ob noch Wasser-Verträge in der alten v1.0.2-Form (`working_prices` + `base_prices` direkt
am Vertrag) vorliegen. Falls ja:

- Alte `working_prices` wandern nach `trinkwasser.working_prices`, wobei das
  Feld `ct_per_kwh` (das bei Wasser semantisch schon ct/m³ war) zu `ct_per_m3`
  umbenannt wird.
- Alte `base_prices` wandern nach `trinkwasser.base_prices`.
- `schmutzwasser` und `niederschlagswasser` werden mit leeren Arrays
  initialisiert. Eine Notiz im `notes`-Feld weist die User darauf hin,
  beide Komponenten manuell nachzupflegen, da die alten Daten sie
  nicht enthalten haben.
- `advance_payments` und `bonuses` bleiben unverändert.

Schema-Marker in `data/meta.json` springt von `1.0.0` auf `1.0.3`.

### Added — UI

- **Wasser-Saldo-Karte** zeigt unterhalb der 4 Hauptspalten eine
  Komponenten-Zeile mit drei Kacheln (Trinkwasser, Schmutzwasser,
  Niederschlagswasser), jeweils mit aktuellem Tarif, kumuliertem
  Verbrauchsanteil und Grundpreis-Aufschlüsselung.
- **Wasser-Vertragsdialog** (`openWaterContractModal`) mit drei
  ein-/ausklappbaren Komponenten-Sektionen plus Abschläge und Boni.
  Schmutzwasser-Basis ist umschaltbar (Trinkwasser-Verbrauch /
  separater Zähler).
- **Vertragstabelle** für Wasser zeigt die Anzahl der Stichtage pro
  Komponente statt der Standard-Spalten.

### Backend-Änderungen

- `ContractService::normalizeWater()` — strikte F4-Validierung der
  drei Komponenten-Blöcke. Halb-ausgefüllte Niederschlagswasser-Zeilen
  (z.B. Fläche fehlt, Tarif vorhanden) werden mit HTTP 400 abgelehnt.
- `ConsumptionService::applyWaterContracts()` — neue Pro-Monat-Berechnung
  mit drei Komponenten und Aufschlüsselung in `monthly[].trinkwasser`,
  `.schmutzwasser`, `.niederschlagswasser`.
- `ConsumptionService::contractStatus()` für Wasser ergänzt um
  `components.{trinkwasser, schmutzwasser, niederschlagswasser}` mit
  Pro-Komponenten-Summen und aktuellen Tarifwerten.

### Migration

Update von v1.0.2 → v1.0.3 ist beim ersten App-Start automatisch.
Bestehende Backups (Format `backup_version: "3.0"`) bleiben kompatibel,
weil sie unter `utilities.wasser.contracts` die alte Struktur
enthielten — beim Import wird sie wie laufende v1.0.2-Daten erkannt und
in die neue Struktur überführt.

[1.0.3]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.3

---

## [1.0.2] — 2026-05-11 — Initial public release

Erste öffentliche Version des Energietrackers. Selbst-gehostete Web-App
zum Erfassen und Analysieren des privaten Energie- und Wasserverbrauchs.
Single-File-PHP-Backend, Vanilla-JS-SPA-Frontend, flat-file JSON-Persistenz.

Eine private Vorgängerversion existierte unter dem Namen *Energietracker
v0.9.0* (Backup-Format `version: "2.1"`). Backups aus dieser Version
können mit dem eingebauten Migrator importiert werden — siehe
[`docs/anleitungen/migration-v090.md` in v3.3.0](https://github.com/Bingerminger/energietracker/tree/v3.3.0/docs/anleitungen/migration-v090.md) (entfallen mit v3.4.0). Außerhalb
dieses Migrationspfads ist v0.9.0 für die öffentliche Codebase irrelevant.

### Funktionen

- **Drei Verbrauchsarten parallel**: Gas, Strom, Wasser. Pro Utility
  eigene Zähler, Verträge, Tarifhistorie und Berechnungslogik.
- **Mehrere Zähler pro Utility**, jeder mit unabhängiger Vertragsserie
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  beliebig viele Geräte (Devices) mit Seriennummer, Einbaudatum,
  Anfangs-/Endzähler, Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt überbrückt.
- **Vertragshistorie** mit stichtag-genauen Arbeits- und Grundpreisen,
  monatlichen Abschlägen und Boni. Mehrere Stichtage pro Vertrag werden
  als forward-fill auf die Monatszeilen angewandt.
- **Saldo-Berechnung pro Vertrag**: aktueller Stand (Kosten −
  Abschläge) und erwarteter End-Saldo (extrapoliert über die
  verbleibenden Monate). Verdict: Erstattung, Nachzahlung, Ausgeglichen.
- **Heizgradtage** (HGT) je Monat gegen die Basistemperatur, mit
  Open-Meteo-Sync und CSV-Import für historische Tagestemperaturen
  (Format `DD.MM.YYYY"avg"min"max`, double-quote-getrennt).
- **Vier Regressionsmodelle** für HGT vs. Verbrauch: linear,
  polynomial Grad 2, robust (Huber), segmentiert
  (Heiz-/Sommerlast bei HGT = 50 als Default-Schwelle).
- **12-Monats-Forecast** als R²-gewichtete Mischung aus Regressions-
  modell und Saisonprofil.
- **Anomalie-Erkennung**: Monate mit > 2σ Abweichung vom
  Modell-Erwartungswert.
- **Backup & Restore** über die UI (Format `backup_version: "3.0"`),
  inklusive automatischer Sicherheits-Snapshots im Datenverzeichnis.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`)
  kann zweistufig importiert werden — erst Preview mit Übersicht, dann
  *Ersetzen* oder *Zusammenführen* (Konflikt-Resolution per ID).

### Technische Architektur

- **Backend (PHP ≥ 8.4)**: 13 Services + 11 Controllers, organisiert
  in einem kleinen App-Container (`src/bootstrap.php`). Speicher-Layer
  via `JsonStore` mit `LOCK_EX`-Schreibverbindung. Routing über einen
  kompakten Hand-rolled Router (`src/Http/Router.php`).
- **Frontend (Vanilla JS, ES Modules)**: 8 Views + 3 Komponenten + 1
  Hash-Router, 1 API-Fetch-Wrapper, 1 Lightweight-State-Cache. Kein
  Build-Step, keine Node-Toolchain nötig zur Auslieferung.
- **Daten**: flache JSON-Dateien unter `data/`. Schema-Version
  in `data/meta.json`. Atomic-write-Pattern überall.
- **Visualisierung**: Chart.js 4 per CDN-Import in `index.php`.
- **Typografie**: DM Mono (Zahlen, Datumsangaben) und DM Sans
  (Prosa) per Google Fonts.

### Datenmodell (siehe README → Datenmodell für Details)

- **Reading**: `{id, meter_id, device_id, date, counter, price_cents?,
  note, is_estimated, is_future}`
- **Meter**: `{id, name, icon, created_at, active, notes, devices: [...]}`
- **Device**: `{id, serial, installed_on, initial_counter, removed_on,
  final_counter, reason}`
- **Contract**: `{id, meter_id, provider, tariff_name, start, end, notes,
  working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, bonuses}`

### Settings-Inventar (20 Schlüssel)

```
gas_conversion_factor (kWh/m³)     hdd_base_temp (°C)
co2_gas (g/kWh)                    co2_strom (g/kWh)
co2_wasser (g/m³)                  min_days_period
min_hdd_regression                 blend_max
forecast_months                    min_temp_days_forecast
forecast_model                     dashboard_months
alert_days_since_reading           anomaly_threshold
location_name                      latitude
longitude                          weather_auto_fill
wasser_personen_anzahl             wasser_personen_referenz
```

### Bekannte Einschränkungen

- HGT-Anwendung nur für Gas und Strom relevant; bei Wasser fällt der
  Forecast auf 100 % Saisonprofil zurück.
- Open-Meteo-Sync benötigt `curl` als PHP-Extension.
- Keine Mehrbenutzer- oder Auth-Schicht — Single-Tenant per Design.

[1.0.2]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.2

---

## Roadmap

Nicht terminiert — Reihenfolge und Umfang können sich ändern.

### Smart-Meter-Anbindung (Aufbau auf F-06)

F-06 hat den Ablesungs-Import in zwei Schichten getrennt: die
CSV-Parsing-Schicht und den quell-agnostischen Kern
`ReadingImportService::importRows()`. Damit ist **Variante 1** (gekapselte,
wiederverwendbare Importlogik) bereits umgesetzt.

Offen ist **Variante 2** — ein unbeaufsichtigter Endpoint, über den ein
Smart-Meter-Gateway oder ein Heimautomatisierungs-System Ablesungen ohne
UI-Interaktion einliefert. Dafür nötig:

- Ein Token-geschützter Endpoint (z.B.
  `POST /api/ingest/{utility}/{meter}` mit `Authorization: Bearer …`),
  da die übrige App bewusst auth-frei und Single-Tenant ist.
- Token-Verwaltung (Erzeugen/Widerrufen) in den Einstellungen.
- `importRows()` ist bereits der passende Aufsetzpunkt — der neue
  Endpoint muss nur Auth + Payload-Parsing ergänzen, die Schreib- und
  Überschreiblogik bleibt unverändert.

### Weitere Verbrauchsarten

- Heizöl und Pellets als zusätzliche Utilities (`Config/Utilities.php`
  ist die Single Source of Truth — additiv erweiterbar, ähnlich wie
  Wasser in v1.0.0).

### Internationalisierung

- Englische UI-Sprache. Aktuell sind Labels und Meldungen
  durchgängig deutsch verdrahtet.
