# Was kann ich auf welcher Stufe?

**Deutsch** · [English](../en/referenz/stufen.md)

[← Kompendium-Index](../README.md)

Die Nutzungsstufe bestimmt, was die Oberfläche zeigt: **🌱 Einsteiger**,
**🌿 Erfahren** oder **🌳 Experte**. Diese Seite sagt, was du auf jeder Stufe
tun kannst, und hilft beim Wählen. Wie du umstellst und wer welche Stufe hat,
steht unter [Einrichten und Nutzungsstufen](../einstieg/einrichtung.md#3-nutzungsstufen).

Seit **v3.7.0** ist die Zuordnung neu durchdacht. Drei Sätze entscheiden:

| Stufe | Der Satz dazu |
|---|---|
| 🌱 Einsteiger | „Ich will wissen, was ich verbrauche, was es kostet und ob der Abschlag reicht. Die Post vom Versorger soll die App für mich lesen.“ |
| 🌿 Erfahren | „Ich will vergleichen und prüfen.“ |
| 🌳 Experte | „Ich will alles sehen und anbinden.“ |

Der **Haushalt** bestimmt, welche Seiten du brauchst, nicht die Stufe. Wer PV,
einen Speicher, eine Wärmepumpe oder eine Wallbox hat, sieht deren Seiten auch
als Einsteiger, nur mit einfachem Inhalt. Ein Eigenheim mit Gas, PV und Wallbox
braucht mehr Seiten als eine Mietwohnung mit Strom, aber nicht mehr Fachwissen.

**Was die Stufe nicht ändert:**

- Die App **rechnet in jeder Stufe alles**. Schnittstelle, CSV-Export, Backup,
  Home Assistant und Kalender-Abo liefern dasselbe.
- **Ausgeblendetes bleibt erreichbar.** Ein Link oder ein Lesezeichen öffnet
  die Seite mit dem Hinweis „Diese Seite gehört zur Stufe „…“. Sie ist
  trotzdem offen.“
- **Ausgeblendete Felder bleiben im Formular.** Ein Vertrag, den du als
  Einsteiger speicherst, behält seine Boni und Preiswechsel.
- **Nach einem Update** steht eine bestehende Installation auf „Experte“.
- Mit [Benutzern im Haushalt](../anleitungen/benutzer.md) hat **jede Person
  ihre eigene Stufe**.

---

## 1. Welche Stufe passt zu mir?

**Mieterin, nur Strom.** 🌱 Einsteiger reicht. Die Übersicht beantwortet
„Bekomme ich Geld zurück?“, „Mehr oder weniger als im Vorjahr?“ und „Was ist zu
tun?“. Den Zählerstand trägt sie unter Zählerstände ein, die Jahresrechnung und
eine Preisänderung legt sie in den Posteingang. Mit der Prognose-Werkstatt
spielt sie durch, was ein kalter Winter oder ein anderer Tarif kostet. Will
sie die Jahresrechnung nachrechnen oder einen Wechsel prüfen, stellt sie auf
🌿 Erfahren.

**Wohnung mit Fernwärme.** 🌱 Einsteiger reicht für den Alltag. Seit v3.7.0
zeigt der Vertrag schon dort Leistungspreis, Messpreis und Anschlussleistung,
die auf jeder Fernwärmerechnung stehen. Die Verbrauchsansicht erklärt mit
„Kälter oder mehr verbraucht?“, ob der Winter oder das eigene Heizen den
Unterschied macht. 🌿 Erfahren lohnt sich für „Rechnung prüfen“ und die
Monatstabelle mit witterungsbereinigten Werten.

**Eigenheim mit Gas, PV und Wallbox.** 🌱 Einsteiger zeigt alle Verbrauchsarten,
die PV mit dem Schaubild „Energiefluss im Haus“, die Wallbox als eigenen
Zähler und auf der Seite Flexibilität das Ergebnis mit „Dein Hebel“. Weil der
Haushalt PV und Wallbox hat, schlägt die App einmal 🌿 Erfahren vor (§ 3).
Erfahren zeigt dazu den Energiefluss je Monat, Speicher und Amortisation, den
Ladestrom-Nachweis, die Ladevorgänge aus evcc, „Wechsel prüfen“ für Gas und
Strom und den Zähler-Aufbau. 🌳 Experte braucht dieser Haushalt erst mit
Viertelstundenwerten, etwa aus Home Assistant oder dem Portal des
Netzbetreibers: Dann rechnet die Flexibilität je Zähler nach.

---

## 2. Funktionen nach Aufgaben

✓ heißt: Die Stufe zeigt es. – heißt: ausgeblendet, aber per Link erreichbar.
Die Spalten gelten von links nach rechts: Was Einsteiger sehen, sehen alle
höheren Stufen auch. Ein Test prüft jede Zeile mit Sprungmarke gegen den Code.

### Erfassen

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-readings-entry"></a>**Zählerstände** — alle Zähler in einem Durchgang, Foto als Beleg, ohne Netz in der Warteschlange | ✓ | ✓ | ✓ | den Stand eintragen; mit eingerichteter Texterkennung schlägt das Foto ihn vor |
| <a id="level-contracts-overview"></a><a id="level-contracts"></a>**Verträge & Abschläge** — Anbieter, Laufzeit, Arbeits- und Grundpreis, Abschlag | ✓ | ✓ | ✓ | was es kostet, ob der Abschlag reicht |
| <a id="level-el-notice"></a>Vertrag: **Kündigungsfrist** | ✓ | ✓ | ✓ | *seit v3.7.0 für alle:* Aus ihr entsteht „Kündigen bis …“ in „Zu tun“ |
| <a id="level-el-fw-prices"></a>Fernwärme: **Leistungspreis, Messpreis, Anschlussleistung** | ✓ | ✓ | ✓ | *seit v3.7.0 für alle:* stehen auf jeder Fernwärmerechnung |
| <a id="level-el-contract-terms"></a>Vertrag: Preisgarantie, „Kündigung wirkt“, Mindestlaufzeit, „Verlängert sich ohne Kündigung“ | – | ✓ | ✓ | Wechsel planen |
| <a id="level-el-price-changes"></a>Vertrag: weitere Preiszeilen („+ Zeile“), Boni, Sonderzahlungen | – | ✓ | ✓ | Preiswechsel und Rückzahlungen genau abbilden |
| <a id="level-el-contract-expert"></a>Vertrag: Kennwerte der Fernwärme (CO₂, Primärenergie), Preise je Zähler einer Gruppe, § 14a Modul 1 und Modul 3, Gutschriften des Direktvermarkters, „Monatspreise importieren“, Zählungen je Vertrag | – | – | ✓ | Sonderfälle von Tarif und Netzentgelt |
| Ablesung: **„Nur für die Abrechnung“** und **„Stand ist richtig“** im Bearbeiten-Dialog *(v3.9.0)* | ✓ | ✓ | ✓ | ein Stand, mit dem der Versorger abgerechnet hat, der aber die Monate verzieht |
| Vertrag: **Abschlag im Rhythmus** (alle 2 oder 3 Monate, Termine laut Rechnung) *(v3.9.0)* | ✓ | ✓ | ✓ | wie der Versorger abbucht; Saldo und Vorschlag zählen je Termin |
| <a id="level-meters"></a>**Zähler-Aufbau** — Subzähler, Rollen, Geräte und Zählertausch, CSV-Import von Ständen; seit v3.9.0 Zählwerk (OBIS) und Heizgrenze je Zähler | – | ✓ | ✓ | Zähler einer Art verwalten (Link „⚙️ Zähler“ in der Verbrauchsansicht) |
| Assistent **„Messkonzept einrichten“** — Zähler, Zählwerke, Subzähler und Verträge aus dem Aufbau *(v3.9.0)* | ✓ | ✓ | ✓ | über Einstellungen → Verbrauchsarten & Abrechnung und die Ersteinrichtung; der Knopf auf der Zählerseite ab Erfahren |
| <a id="level-el-meter-plant"></a>Zähler: **PV-Anlage** (Investition, Inbetriebnahme, Speicher, Balkonkraftwerk), **Stromzähler der Wärmepumpe** | – | ✓ | ✓ | *seit v3.7.0 ab Erfahren:* Grundlage für Amortisation und Jahresarbeitszahl |
| <a id="level-el-meter-expert"></a>Zähler: Zählergruppe, Analyse-Zäsuren, „Zeitreihe importieren“ | – | – | ✓ | Gruppenvertrag, Rechnen ab einer Sanierung, Viertelstunden aus Portalen |

### Verstehen

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-dashboard"></a>**Übersicht** | drei Antworten | alle Kennzahlen | alle Kennzahlen | Geld zurück? Mehr als im Vorjahr? Was ist zu tun? Zur Miete dazu „Reicht die Vorauszahlung?“ |
| <a id="level-el-dash-explainers"></a>Übersicht: Abschnitt **„Schaubilder“** | – | ✓ | ✓ | *seit v3.7.0 ab Erfahren:* Geld, Zeitstrahl, Energiefluss, Wetter auf einen Blick (Einsteiger haben „Wohin geht mein Geld?“) |
| <a id="level-utility"></a>**Verbrauch je Art** — Kennzahlen, Monatsverlauf, Saldo, Schaubilder; bei Heizöl und Pellets Tank und Lieferungen | ✓ | ✓ | ✓ | auch PV, Wallbox und Wärmepumpe haben ihre Seite in jeder Stufe |
| <a id="level-el-utility-advanced"></a>Verbrauch: Monatstabelle, „Gemessen“/„Witterungsbereinigt“, CO₂-Kachel, Energiefluss je Monat der PV | – | ✓ | ✓ | Monate vergleichen, Wetter herausrechnen |
| <a id="level-el-pv-extras"></a>PV-Erzeugung: **Speicher** und **Amortisation** | – | ✓ | ✓ | *seit v3.7.0 ab Erfahren:* Hat sich die Anlage gelohnt, was verliert der Speicher? |
| <a id="level-el-heat-pump"></a>Karte **„Wärmepumpe“** mit Jahresarbeitszahl | – | ✓ | ✓ | *seit v3.7.0 ab Erfahren:* wie effizient die Wärmepumpe arbeitet |
| <a id="level-el-utility-expert"></a>Verbrauch: Warmwasser-Wärme, CO₂-Preis im Brennstoff | – | – | ✓ | Rechengrößen hinter den Kosten |
| <a id="level-recommendations"></a>**Empfehlungen** | ✓ | ✓ | ✓ | Hinweise aus deinen Daten |
| „Zu tun“: **Heizgrenze anpassen** mit „Übernehmen (nur dieser Zähler)“ und „Nicht jetzt“, auch an Saldo-Karte, Prognose und Werkstatt *(v3.9.0)* | ✓ | ✓ | ✓ | ob der Abschlag reicht, hängt an der Heizgrenze |
| „Zu tun“: **Ablesung prüfen**, doppelte Stände, Hinweis auf die Analyse-Zäsur *(v3.9.0)* | ✓ | ✓ | ✓ | ein vertippter Stand verzieht sonst still die Monate |
| <a id="level-report"></a><a id="level-report-print"></a>**Jahresbericht** — auch als PDF und zum Drucken | ✓ | ✓ | ✓ | das Jahr auf einer Seite |
| <a id="level-analysis"></a>**Analyse** — Heizsignatur, Jahre im Vergleich | – | ✓ | ✓ | Verbrauch und Temperatur nebeneinander |
| <a id="level-el-baseline"></a>Analyse: Zäsur und „Wirkung der Maßnahme“ | – | – | ✓ | vorher und nachher einer Sanierung |
| <a id="level-temperatures"></a>**Wetterdaten** — Standort, Abgleich, CSV | – | ✓ | ✓ | Grundlage der Wetterbereinigung |
| <a id="level-help"></a><a id="level-help-doc"></a>**Hilfe**, Glossar und Handbuch | ✓ | ✓ | ✓ | Begriffe und Anleitungen |

### Prüfen und vergleichen

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-tariffs"></a>**Wechsel prüfen** — Angebote, Wechseltermin, Break-even | – | ✓ | ✓ | lohnt sich ein anderer Tarif? Die Empfehlungen verweisen auch Einsteiger dorthin |
| <a id="level-el-market"></a>Wechsel prüfen: dynamischer Tarif und „Börsenstrompreise“ | – | – | ✓ | Börsenpreise je Viertelstunde |
| <a id="level-bill-check"></a>**Rechnung prüfen** | – | ✓ | ✓ | die Jahresrechnung nachrechnen und buchen |
| <a id="level-el-bill-check-link"></a>Verbrauch: Karte „Rechnung prüfen“ | – | ✓ | ✓ | der Weg dorthin aus der Verbrauchsansicht |
| <a id="level-tenancy"></a>**Mietverhältnis** — Vorauszahlung, Nebenkostenabrechnungen, Fristen | – | ✓ | ✓ | Einsteiger sehen „Reicht die Vorauszahlung?“ auf der Übersicht |
| <a id="level-el-co2-split"></a>Mietverhältnis: **„CO₂-Kosten teilen“** | – | ✓ | ✓ | *seit v3.7.0 mit der Seite:* den Anteil des Vermieters prüfen |
| <a id="level-el-ev-report"></a>Wallbox: **„Ladestrom-Nachweis (Dienstwagen)“** | – | ✓ | ✓ | *seit v3.7.0 ab Erfahren:* den Ladestrom nachweisen |

### Planen

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-forecast"></a><a id="level-scenario-print"></a>**Prognose-Werkstatt** — Winter, Sparen, Tarif; Ergebnis drucken oder als Bild | ✓ | ✓ | ✓ | was wäre, wenn … |
| <a id="level-el-ws-advanced"></a>Werkstatt: „Mehr Stellschrauben“ — Raumtemperatur, Grundlast, Prognosemodell, Maßnahmen, E-Auto, Jahresarbeitszahl | – | ✓ | ✓ | E-Auto und Wärmepumpe *seit v3.7.0 ab Erfahren*, wenn der Haushalt sie hat |
| <a id="level-el-ws-results"></a>Werkstatt: „Monat für Monat“, Rangliste der Maßnahmen | – | ✓ | ✓ | Maßnahmen vergleichen |
| <a id="level-el-ws-expert"></a>Werkstatt: „Für Fachleute“ — Regressionsmodell, Budget mit bester Kombination, Modellvergleich, CSV | – | – | ✓ | Rechenweg und Rohdaten |
| <a id="level-el-fc-classic"></a>Prognose (aufgeklappt): Modell, CO₂-Preis-Szenario | – | – | ✓ | Rechenparameter |
| <a id="level-flexibility"></a>**Flexibilität** — Ergebnis, Handlungssätze, typischer Tag; „Dein Hebel“ auf der Übersicht | ✓ | ✓ | ✓ | wann Strom günstig ist |
| <a id="level-el-flex-advanced"></a>Flexibilität: die Woche, beste Zeitfenster, „Sonnenstrom selbst nutzen“, „Dein Hebel“ je Gerät | – | ✓ | ✓ | Verschieben planen |
| <a id="level-el-flex-expert"></a>Flexibilität: Nachrechnung je Zähler, Speicher, § 14a Modul 3, Viertelstundenreihen | – | – | ✓ | mit Viertelstundenwerten nachrechnen |

### Post vom Versorger

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-inbox"></a>**Posteingang** — PDF ablegen, auslesen lassen, jede Angabe mit Belegstelle prüfen, übernehmen; von Hand neben dem Dokument | ✓ | ✓ | ✓ | die Post vom Versorger liest die App |
| <a id="level-inbox-draft"></a>Entwurf aus Dokumenten — den Haushalt aus vielen Dokumenten auf einmal anlegen *(v3.7.0)* | ✓ | ✓ | ✓ | Erstbefüllung ohne Tippen |
| Akte je Vertrag und Zähler — alle Dokumente in zeitlicher Folge *(v3.7.0)* | ✓ | ✓ | ✓ | nur eine Liste der Dokumente |
| Auslesen einrichten — Karte „Auslesen“ (Lokal oder Cloud) unter Einstellungen → Experte | – | – | ✓ | einmal einrichten, meist durch die Person, die die Installation verwaltet; Posteingang und Einrichtungsassistent öffnen dieselbe Karte als Dialog, auf jeder Stufe |
| Kurzbefehl „Teilen → Energietracker“, überwachter Ordner *(v3.7.0)* | – | ✓ | ✓ | Eingangskanäle, wie die Integrationen |

### Haushalt und Personen

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-reminders"></a>**Termine & Wartung** und „Zu tun“ — mit Zuständigen, als Kalender-Abo | ✓ | ✓ | ✓ | nichts verpassen |
| <a id="level-activity"></a>**Erledigt** — wer was wann erledigt hat | – | ✓ | ✓ | nachsehen und zurücknehmen |
| <a id="level-settings-general"></a>Einstellungen → **Allgemein** — Sprache und Land, Stufe, eigenes Konto, Hinweis auf neue Versionen | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-general"></a>Allgemein: Anzeige, Erinnerungen | – | ✓ | ✓ | Zeiträume und Vorlauf der Erinnerungen |
| <a id="level-settings-household"></a>Einstellungen → **Haushalt & Gebäude** — Wohnfläche, Gebäude, Wohnverhältnis, Heizung und Warmwasser | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-household"></a>Haushalt: eigene Vergleichswerte, Wasser-Referenzwerte | – | ✓ | ✓ | Einordnung mit eigenen Zahlen |
| <a id="level-settings-utilities"></a>Einstellungen → **Verbrauchsarten & Abrechnung** — aktive Arten, Abrechnungsstichtage | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-utilities"></a>Verbrauchsarten: physikalische Konstanten, Energieträger (Lieferung), eigene Werte der Flexibilität, Photovoltaik | – | ✓ | ✓ | Photovoltaik *seit v3.7.0 ab Erfahren* |
| <a id="level-el-settings-co2"></a>Verbrauchsarten: CO₂-Emissionsfaktoren | – | – | ✓ | Rechenparameter |
| **Personen im Haushalt** anlegen, umbenennen, löschen (Einstellungen → Haushalt & Gebäude), auch ohne Anmeldung *(v3.9.0)* | ✓ | ✓ | ✓ | Namen für „Zuständig“ an Terminen, Zählern und Verträgen |
| Zugänge und Rollen (Einstellungen → Zugriff → „Benutzer und Rechte“) | – | – | ✓ | wer sich anmeldet und welche Rolle gilt; hängt an der Anmeldung. Bis v3.8 entstanden Personen nur hier |

### Anbinden

| Funktion | 🌱 | 🌿 | 🌳 | Wofür |
|---|---|---|---|---|
| <a id="level-settings-data"></a>Einstellungen → **Daten** — Export, Sicherung, Wiederherstellung, Beispieldaten | ✓ | ✓ | ✓ | deine Daten gehören dir |
| <a id="level-settings-integrations"></a>Einstellungen → **Integrationen** — Home Assistant: Token, Alias, Vorlagen; seit v3.9.0 Benachrichtigungen an Home Assistant | – | ✓ | ✓ | Zählerstände von außen, Fristen und neue Post nach Home Assistant |
| <a id="level-el-evcc"></a>Wallbox: **„Ladevorgänge aus evcc“** | – | ✓ | ✓ | Ladevorgänge hochladen oder abrufen |
| <a id="level-settings-access"></a>Einstellungen → **Zugriff** — Anmeldung, Benutzer und Rechte, API-Schlüssel, Einbetten, Hostnamen | – | – | ✓ | wer hineinkommt |
| <a id="level-settings-expert"></a>Einstellungen → **Experte** — Auslesen, Belege, Texterkennung, evcc-Adresse; eingeklappt die Rechenparameter | – | – | ✓ | Dienste im Heimnetz, Feinabstimmung |
| <a id="level-settings-system"></a>Einstellungen → **System** — Version, Diagnose, Sicherheits-Check | – | – | ✓ | Betrieb |

---

## 3. Die App schlägt vor

Braucht dein Haushalt mehr, als die Stufe zeigt, schlägt die Übersicht die
nächste Stufe vor: „Dein Haushalt hat mehr, als diese Stufe zeigt — „…“ blendet
es ein.“ Darunter steht der Anlass.

| Stufe | Anlass | Vorschlag | Was dazukommt |
|---|---|---|---|
| 🌱 Einsteiger | PV-Anlage, Speicher, Wärmepumpe oder Wallbox | 🌿 Erfahren | Energiefluss je Monat, Speicher und Amortisation, Jahresarbeitszahl, Ladestrom-Nachweis, Ladevorgänge aus evcc |
| 🌱 Einsteiger | Subzähler, mehrere Zähler einer Verbrauchsart, Werte von Home Assistant | 🌿 Erfahren | Zähler-Aufbau, Integrationen |
| 🌿 Erfahren | Viertelstundenwerte | 🌳 Experte | Nachrechnung je Zähler, Speicher und § 14a Modul 3 in der Flexibilität, Viertelstundenreihen |

- **Höchstens einmal je Anlass.** Hast du einen Anlass gesehen, kommt er auf
  diesem Gerät in dieser Stufe nicht wieder — gleich ob du umstellst, „Nicht
  mehr fragen“ wählst oder weiterklickst. Kommt ein neuer Anlass dazu (etwa
  eine Wallbox), erscheint nur er.
- **„Auf „…“ umstellen“** stuft mit einem Klick hoch. Der Vorschlag blockiert
  nichts.
- Der **Einrichtungsassistent** schlägt die Stufe aus der Antwort auf „Wer
  bist du?“ vor ([Einrichten](../einstieg/einrichtung.md#die-schritte)).

---

[← Kompendium-Index](../README.md)
