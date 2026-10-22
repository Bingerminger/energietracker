# Einrichten und Nutzungsstufen

**Deutsch** · [English](../en/einstieg/einrichtung.md)

[← Kompendium-Index](../README.md)

Seit **v3.2.0** beginnt eine neue Installation mit ein paar Fragen statt mit
einer leeren Oberfläche — seit **v3.5.0** zuerst nach Sprache und Land. Der
Einrichtungsassistent stellt danach Verbrauchsarten und Nutzungsstufe ein und
zeigt auf Wunsch einen Beispielhaushalt, der zu dir passt. Die Nutzungsstufe bestimmt, wie viel die Oberfläche zeigt — gerechnet
wird in jeder Stufe alles. Schaubilder erklären die wichtigsten Zahlen mit
deinen eigenen Daten.

---

## 1. Der Einrichtungsassistent

### Wann er erscheint

- **Beim allerersten Start** einer neuen Installation, also mit leerem
  Datenverzeichnis — etwa in einem frischen Docker-Container. Er kommt bei
  jedem Öffnen wieder, bis du ihn abschließt oder „Überspringen“ wählst.
- **Nicht nach einem Update.** Eine bestehende Installation behält ihre
  Oberfläche und steht danach auf der Stufe „Experte“.
- **In der öffentlichen Demo**, solange dieser Browser noch keinen
  Beispielhaushalt gewählt hat ([§ 5](#5-die-öffentliche-demo)).

### Die Schritte

Der Dialog „Einrichten“ hat fünf Schritte („Schritt 1 von 5“):

1. **„Sprache und Land“** *(seit v3.5.0)* — zwei Auswahlen:
   - **Sprache:** eine der sieben Oberflächensprachen. Vorgewählt ist die
     Sprache dieses Geräts, beim allerersten Start also die des Browsers. Eine
     andere Wahl gilt sofort: Der Assistent steht danach in der neuen Sprache.
   - **Land:** vorgewählt ist das Land, das der Erststart aus dem Browser
     abgeleitet hat (`de-AT` → Österreich, sonst das Land der Sprache).
     Darunter steht, was gilt bzw. was das Länderprofil beim Wechsel setzt:
     Schreibweise von Zahlen, Beträgen und Daten, Währung, Zeitzone,
     Wetterort, Heizgrenze, CO₂ Strom und die Einheit des Brennwerts
     ([Länderprofile](../verstehen/14-laenderprofile.md)). Die Schreibweise
     folgt Sprache *und* Land, aber nur, wenn die Sprache im Land gesprochen
     wird — Englisch mit Deutschland schreibt britisch („1,234.5“,
     „31/12/2026“). Die Währung ist nur Symbol und Beschriftung; Beträge
     werden nicht umgerechnet.
2. **„Wer bist du?“** — eine von fünf Antworten (Tabelle unten). Die Antwort
   schlägt Verbrauchsarten und eine Stufe vor.
3. **„Welche Energieträger nutzt du?“** — die vorgeschlagenen Verbrauchsarten
   sind angehakt; du kombinierst frei, mindestens eine muss bleiben. Ein
   Hinweis erklärt den Unterschied zwischen Fernwärme und Heizwärme
   ([§ 2](#fernwärme-oder-heizwärme)).
4. **„Wie viel Erfahrung hast du?“** — Einsteiger, Erfahren oder Experte
   ([§ 3](#3-nutzungsstufen)).
5. **„So geht es weiter“** — die Zusammenfassung und eine von drei Wegen
   *(seit v3.7.0)*:
   - **„Aus Dokumenten“** *(empfohlen)* führt in den Posteingang: Versorgerpost
     als PDF ablegen, auslesen lassen, und ein Entwurf deines Haushalts
     entsteht — [Haushalt aus Dokumenten](../anleitungen/haushalt-aus-dokumenten.md).
     Darunter steht, welche Dokumente für die Antwort aus Schritt 2 helfen. Ist
     noch kein Dienst zum Auslesen eingerichtet, öffnet **„Auslesen
     einrichten“** die Karte „Auslesen“ als Dialog über dem Assistenten (nur
     Verwalter; Mitglieder lesen, dass ein Verwalter das einrichtet).
   - **„Von Hand anlegen“** führt zu „Zählerstände“: Zähler prüfen, den ersten
     Stand erfassen — weiter mit den [Ersten Schritten](erste-schritte.md).
   - **„Mit Beispieldaten ansehen“** *(nur Verwalter)* lädt den
     Beispielhaushalt zur Antwort aus Schritt 2
     ([§ 2](#2-die-beispielhaushalte)). Vorher sichert die App den jetzigen
     Stand als Snapshot.

Gespeichert wird erst mit **„Los geht’s“**: die aktiven Verbrauchsarten, das
Wohnverhältnis (Einstellungen → Haushalt & Gebäude → „Ich wohne“: „zur Miete“
bei „Ich wohne zur Miete“, sonst „im Eigentum“), die Antwort aus Schritt 2
und die Stufe. Sprache und Land nur, wenn du sie geändert hast:

- Die **Sprache** wird zur Standardsprache der Installation (Jahresbericht,
  CSV, neue Geräte). Hat dieses Gerät — oder mit Anmeldung deine Person —
  eine eigene Sprache, zieht sie mit.
- Das **Land** geht denselben Weg wie in den Einstellungen und übernimmt das
  Länderprofil. Festgeschrieben wird dabei nur, was vom Standard abweicht —
  dieselbe Regel wie beim Erststart. Wer nach einem englischen Erststart
  Deutschland wählt, hat danach keine eingefrorenen deutschen Standardwerte
  in den Einstellungen; eine spätere Korrektur eines Standardwerts erreicht
  auch diese Installation.

„Zurück“ blättert, „Überspringen“ lässt alles, wie es ist — auch eine im
Assistenten gewählte Sprache gilt dann nicht mehr.

| Antwort in Schritt 2 | vorgeschlagene Verbrauchsarten | vorgeschlagene Stufe |
|---|---|---|
| „Ich wohne zur Miete“ | Strom, Heizwärme, Wasser | Einsteiger |
| „Eigentumswohnung mit Fernwärme“ | Strom, Fernwärme | Einsteiger |
| „Eigenheim mit Gas, Öl oder Pellets“ | Gas, Strom, Wasser | Erfahren |
| „Eigenheim mit Wärmepumpe und PV“ | Strom, Heizwärme, Wasser, PV-Erzeugung, PV-Einspeisung | Erfahren |
| „Erst einmal alles ansehen“ | alle | Experte |

Wer mit Heizöl oder Pellets heizt, hakt in Schritt 3 um: Heizöl oder
Holzpellets an, Gas aus.

### Erneut starten

Einstellungen → Allgemein → Karte „Nutzungsstufe und Einrichtung“ →
**„Einrichtungsassistent starten“**. Beim erneuten Start sind die Sprache
dieses Geräts, dein Land, deine aktiven Verbrauchsarten und deine Stufe
vorgewählt; unter dem Land steht, was gerade gilt („Eingestellt für …“).
Eine Antwort in Schritt 2 setzt die Haken in Schritt 3 auf ihren Vorschlag,
die Stufe lässt sie dann in Ruhe. So lädst du auch später einen anderen
Beispielhaushalt.

---

## 2. Die Beispielhaushalte

Zu jeder Antwort in Schritt 2 gibt es einen Beispielhaushalt — vier neue und
das **Schaufenster**, der bisherige Demo-Haushalt mit fast allen
Verbrauchsarten. Alle sind erfunden: Namen, Anbieter und Zählernummern sind
Beispiele. Standort ist Leipzig, die Daten reichen über drei Jahre und werden
beim Laden bis heute fortgeschrieben — Zählerstände, Monatswerte und
Temperaturen mit den Werten desselben Zeitraums im Vorjahr, Termine relativ zu
heute.

| Beispielhaushalt | Verbrauchsarten | Was drinsteckt | Gut zu sehen |
|---|---|---|---|
| **Mietwohnung** („Ich wohne zur Miete“) | Strom, Heizwärme, Wasser | 62 m², eine Person, Zentralheizung mit Gas im Keller. Strom mit eigenem Liefervertrag (Abrechnungsjahr ab Oktober), Anbieterwechsel mit Schlussrechnung und Verlängerung, die seit Ende September 2026 weiterläuft; Heizwärme als Monatswerte aus der Verbrauchsinfo des Messdienstes (mit Vormonat, Vorjahresmonat und Durchschnittsnutzer); Kalt- und Warmwasserzähler; Mietverhältnis mit Vorauszahlung für Heizung und Betriebskosten und zwei Nebenkostenabrechnungen samt CO₂-Kostenanteil des Vermieters | die Übersicht für Einsteiger mit „Reicht die Vorauszahlung?“, Heizwärme ohne eigenen Vertrag, [Als Mieter](../anleitungen/mieter.md) |
| **Eigentumswohnung mit Fernwärme** | Strom, Fernwärme | 85 m², zwei Personen; Fernwärme heizt und bereitet das Warmwasser. Wärmemengenzähler mit Monatsständen aus dem Kundenportal; eigener Fernwärmevertrag mit Arbeits-, Grund-, Leistungs- (6,5 kW) und Messpreis und mehreren Preisänderungen; die Jahresrechnungen 2024 und 2025 sind erfasst (2025 noch nicht gebucht); Strom mit zwei Anbieterwechseln samt Schlussrechnungen, jetzt unbefristet mit Mindestlaufzeit und Preisgarantie | Fernwärme als Liefervertrag mit Rechnung, [Jahresabrechnung prüfen](../anleitungen/jahresabrechnung.md), Eichfrist als Termin |
| **Eigenheim klassisch** („Eigenheim mit Gas, Öl oder Pellets“) | Gas, Strom, Wasser | Einfamilienhaus, 140 m², vier Personen, Gas-Brennwertkessel. Gasvertrag nach Preisvergleich gewechselt, mit Preisgarantie bis Juni 2026, seitdem weiterlaufend; Schlussrechnung und Jahresabrechnung als Versorgerrechnungen erfasst und als Sonderzahlungen gebucht (ebenso Strom und Wasser); Zäsur „Hydraulischer Abgleich“ im September 2024; Wasser mit Hauptzähler und Gartenzähler als Subzähler, Trink-, Schmutz- und Niederschlagswasser, die Wasserrechnung mit Zählermiete als Posten; seit v3.6.0 eine Einliegerwohnung mit Nachtspeicherheizung: Doppeltarifzähler (HT/NT) als Zählergruppe mit einem Gruppenvertrag und Jahresrechnungen, die erste kürzer (ab dem Einzug); ein stillgelegter Zähler (Gartenhaus) mit seiner Historie; ein Home-Assistant-Alias am Hauptzähler Strom; Termine für Wartung, Schornsteinfeger und Eichung | Wetterbereinigung und Zäsur, Wechsel prüfen, Subzähler, Zählergruppe mit Gruppenvertrag |
| **Eigenheim modern** („Eigenheim mit Wärmepumpe und PV“) | Strom, PV-Einspeisung, PV-Erzeugung, Heizwärme | Einfamilienhaus, 150 m², vier Personen. Haushaltszähler; Wärmepumpe mit eigenem Zähler und Wärmepumpentarif mit reduziertem Netzentgelt nach § 14a EnWG (Modul 1), zum 30.09.2026 gekündigt, Folgevertrag mit Mindestlaufzeit und Preisgarantie; Wallbox als Subzähler des Haushaltszählers, mit Ladevorgängen aus evcc; ein dynamischer Tarif als Angebot zum Vergleich; PV mit 9,8 kWp auf Ost- und Westdach mit Einspeisevergütung; Speicher mit 10 kWh; Wärmemengenzähler der Wärmepumpe | Jahresarbeitszahl, Energiefluss und Autarkie, [Ladevorgänge aus evcc](../anleitungen/evcc.md), [Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md), Dynamik-Check |
| **Schaufenster** („Erst einmal alles ansehen“) | Gas, Strom, Wasser, Fernwärme, Heizöl, Holzpellets, PV-Einspeisung, PV-Erzeugung | der bisherige Demo-Haushalt: ein Einfamilienhaus mit acht Verbrauchsarten — alle außer Heizwärme. Zählertausch, Zäsur, Angebote zum Vergleich, Tankbuch; Einzelheiten in [demo-data/README.md](../../demo-data/README.md) | alles auf einmal, für die Stufe „Experte“ |

### Neu in v3.5.0: wer, wann, was noch fehlt

Alle Beispielhaushalte und das Schaufenster zeigen seit v3.5.0 auch:

- **Verlauf „Erledigt“** mit erfundenen Personen: von Hand erledigte Termine
  (teils mit Notiz), automatisch Erledigtes mit Grund (Folgevertrag angelegt,
  Versorgerrechnung erfasst, Ablesung nach einer Lücke,
  Nebenkostenabrechnung), eine ausgeblendete Empfehlung und ein
  zurückgenommener Eintrag. Einträge zu Terminen wandern beim Laden mit den
  Terminen; alle anderen bleiben am Datum ihrer Daten.
- **Zuständig an Terminen:** ein bis drei Termine tragen einen Namen.
- **Versorgerrechnungen:** erfasst, unter „Rechnung prüfen“ mit der eigenen
  Rechnung verglichen und meist als Sonderzahlung gebucht — dazu eine
  kürzlich beendete Abrechnungsperiode ohne Rechnung, für die die App eine
  Abrechnung erwartet.
- **Verträge:** lückenlose Folgeverträge und ein weiterlaufender oder
  unbefristeter Vertrag mit Mindestlaufzeit und Preisgarantie im Zeitstrahl.

| Beispielhaushalt | Personen im Verlauf | Zuständig an Terminen | Abrechnung erwartet |
|---|---|---|---|
| Mietwohnung | Lena | Lena | Strom: Jahresabrechnung der Verlängerung (Periode bis 30.09.2026) |
| Eigentumswohnung mit Fernwärme | Katrin, Mehmet | Katrin, Mehmet | Fernwärme, immer seit 30 Tagen |
| Eigenheim klassisch | Thomas, Sabine, Jonas, Lukas (gelöscht) | Thomas, Sabine, Jonas | Gas, immer seit 56 Tagen — überfällig |
| Eigenheim modern | Julia, Ben | Julia, Ben | Strom: Schlussrechnung des Wärmepumpentarifs (gekündigt zum 30.09.2026) |
| Schaufenster | Anna, Paul | Anna, Paul | Strom, immer seit 21 Tagen |

Seit v3.6.0 sind diese Personen in jedem Haushalt **angelegt** — mit
fortlaufender Nummer und Rolle, je Haushalt mindestens eine, die verwaltet;
jeder Zuständige ist eine von ihnen. Im Eigenheim klassisch ist Lukas
gelöscht: Die Kündigung des alten Gasvertrags hat er erledigt, im Verlauf
steht sie unter „Lukas (gelöscht)“ ([Benutzer im Haushalt](../anleitungen/benutzer.md#personen-löschen)).

Wo „immer seit … Tagen“ steht, legt die App beim Laden den
Abrechnungsstichtag dieser Verbrauchsart relativ zu heute — wie die Termine,
damit der Hinweis zu jedem Zeitpunkt zu sehen ist. Die beiden anderen Hinweise
gehören zu festen Perioden und erscheinen, solange deren Ende höchstens 120
Tage zurückliegt (bis Ende Januar 2027). Rechnungen schreibt die App beim Laden
nicht fort — ausgenommen die Verbrauchsarten mit „immer seit … Tagen“: Dort
wandern die Jahresrechnungen mit dem Stichtag (die jüngste endet am Tag vor dem
Stichtag des Vorjahres, Schlussrechnungen bleiben), und die App rechnet ihre
Beträge neu aus. So zeigt das Abrechnungsjahr keine Zwischenperiode, und
„Rechnung prüfen“ passt weiter.

**Tagesfilm:** Das Eigenheim modern hat Viertelstunden für ganz 2025 und
bekommt beim Laden die letzten zwei bis drei Monate bis gestern dazu —
Netzbezug, Erzeugung, Einspeisung, Speicher und Börsenpreis, in jeder
Viertelstunde stimmig. Das Schaufenster hat bewusst keine Viertelstunden: Es
soll klein bleiben, und der Tagesfilm braucht einen Haushalt mit Speicher und
Verbrauchern — dafür steht das Eigenheim modern.

### Fernwärme oder Heizwärme?

Die Beispielhaushalte zeigen den Unterschied, den auch der Hinweis in Schritt 3
erklärt:

- **Fernwärme** hat einen eigenen Liefervertrag mit Rechnung vom Versorger —
  wie in der Eigentumswohnung ([Fernwärme](../verstehen/04-fernwaerme.md)).
- **Heizwärme** wird nur gemessen. Bezahlt wird sie über die Miete (die
  Mietwohnung), oder sie ist die Wärme einer Wärmepumpe (Eigenheim modern)
  ([Heizwärme](../verstehen/15-waerme.md)).

### Laden und zurück

- **Im Assistenten:** Antwort in Schritt 2 wählen, am Ende „Mit Beispieldaten
  ansehen“.
- **Einstellungen → Daten → „Demo-Daten laden“** und **„Mit Beispieldaten
  ausprobieren“** auf der leeren Übersicht laden das Schaufenster.

Ein Beispielhaushalt **ersetzt den ganzen Haushalt**: Zähler, Stände,
Verträge, Mietverhältnis, Termine und die Einstellungen des Haushalts.
Verbrauchsarten, die er nicht nutzt, sind danach leer. **Deine Nutzungsstufe
bleibt**, seit v3.5.0 ebenso **Sprache, Land, Währung, Zeitzone und die
Einheit des Brennwerts** (bis v3.4 fiel die Sprache dabei auf Deutsch
zurück). Standort, Heizgrenze und CO₂-Faktoren bringt der Beispielhaushalt
mit — er liegt in Leipzig. Vorher legt die App einen Snapshot an; sind schon Daten da, fragt
sie erst nach. Zurück geht es über Einstellungen → Daten → „Gespeicherte
Snapshots“ → „Einspielen …“. Mit [Benutzern](../anleitungen/benutzer.md)
lädt nur, wer die Installation verwaltet.

Über die API: `GET /api/demo/status` nennt unter `personas` die vorhandenen
Beispielhaushalte, `POST /api/demo/import` lädt mit `{"persona": "mieterin",
"force": true}` einen davon (`mieterin`, `etw-fernwaerme`,
`eigenheim-klassisch`, `eigenheim-modern`, `showcase`). Ein unbekannter Name
endet mit „Einen Beispielhaushalt „…“ gibt es nicht.“
([API-Referenz](../referenz/api.md)).

---

<a id="case-levels"></a>
## 3. Nutzungsstufen

Drei Stufen: **🌱 Einsteiger**, **🌿 Erfahren** und **🌳 Experte**. Sie
bestimmen, was du siehst — nicht, was die App rechnet. Seit v3.7.0 entscheiden
drei Sätze: Einsteiger wollen wissen, was sie verbrauchen, was es kostet und ob
der Abschlag reicht, und lassen die Post vom Versorger auslesen. Erfahrene
vergleichen und prüfen. Experten sehen alles und binden an. Wer PV, Speicher,
Wärmepumpe oder Wallbox hat, sieht deren Seiten in jeder Stufe. Jede Funktion
einzeln, mit drei Haushalten zum Wählen:
**[Was kann ich auf welcher Stufe?](../referenz/stufen.md)**

| Bereich | 🌱 Einsteiger | 🌿 Erfahren | 🌳 Experte |
|---|---|---|---|
| Übersicht | drei Antworten: Bekomme ich Geld zurück? Mehr oder weniger als im Vorjahr? Was ist zu tun? — mit einem Schaubild | alle Kennzahlen, seit v3.7.0 mit dem Abschnitt „Schaubilder“ | wie Erfahren |
| Zählerstände | erfassen, Foto als Beleg | dazu CSV-Import | dazu Zeitreihen aus Portalen |
| Verbrauch je Art | Monatsverlauf, Saldo, Schaubilder — auch für PV, Wallbox und Wärmepumpe | dazu Vorjahr, witterungsbereinigt, Tabelle; seit v3.7.0 Jahresarbeitszahl, Speicher und Amortisation, Ladestrom-Nachweis | dazu Warmwasser-Wärme und CO₂-Preis im Brennstoff |
| Verträge | Arbeitspreis, Grundpreis, Abschlag; seit v3.7.0 die Kündigungsfrist und bei Fernwärme Leistungs- und Messpreis | dazu Preiswechsel, Boni, Kündigungsweise, Mindestlaufzeit, Sonderzahlungen | dazu Gruppen und HT/NT, § 14a, Monatspreise, Kennwerte der Fernwärme |
| Wechsel prüfen, Rechnung prüfen | ausgeblendet — die Empfehlungen verweisen darauf | ja | dazu Dynamik-Check und Börsenpreise |
| Mietverhältnis (zur Miete) | „Reicht die Vorauszahlung?“ auf der Übersicht | die ganze Seite, seit v3.7.0 mit „CO₂-Kosten teilen“ | wie Erfahren |
| Auswertungen | Prognose-Werkstatt mit drei Reglern, Flexibilität mit Ergebnis, Jahresbericht | dazu Analyse, Wetterdaten, mehr Stellschrauben (seit v3.7.0 auch E-Auto und Jahresarbeitszahl) | dazu Zäsur, Modelle, Budget |
| Zähler-Aufbau | ausgeblendet | Subzähler, Rollen, seit v3.7.0 PV-Anlage und Stromzähler der Wärmepumpe | dazu Gruppen, Zäsuren, Zeitreihen |
| Einstellungen | Allgemein, Haushalt & Gebäude, Verbrauchsarten & Abrechnung, Daten | dazu Wetterdaten, Integrationen | alles, auch Zugriff, Experte und System |

### Ausblenden ist nicht abschalten

- Die App **rechnet in jeder Stufe alles**. Schnittstelle, CSV-Export,
  Backup, Home Assistant und Kalender-Abo liefern dasselbe, gleich welche
  Stufe eingestellt ist.
- **Ausgeblendete Felder bleiben im Formular.** Ein Vertrag, den du als
  Einsteiger speicherst, behält seine Preiswechsel und Boni.
- **Ausgeblendete Seiten bleiben erreichbar.** Ein Link darauf — aus den
  Empfehlungen, aus „Zu tun“ oder einem Lesezeichen — öffnet die Seite mit dem
  Hinweis „Diese Seite gehört zur Stufe „…“. Sie ist trotzdem offen.“ und dem
  Knopf „Auf „…“ umstellen“.
- **Nach einem Update** steht jede bestehende Installation auf „Experte“ —
  es verschwindet nichts.

### Umschalten

- **Kopfleiste**, neben Tag/Nacht: die Auswahl „Nutzungsstufe“. Die Stufe gilt
  sofort; Navigation und Ansicht bauen sich neu auf.
- **Einstellungen → Allgemein → „Nutzungsstufe und Einrichtung“**: dieselbe
  Auswahl, dazu eine Zeile je Stufe, was sie zeigt.

**Für wen sie gilt:** Ohne Anmeldung gilt die Stufe für die ganze
Installation, auf allen Geräten. Mit [Benutzern im
Haushalt](../anleitungen/benutzer.md) hat jede Person ihre eigene, auf allen
ihren Geräten; wer keine gewählt hat, sieht die Stufe der Installation. In der
öffentlichen Demo merkt sie sich der Browser. Gespeichert ist sie als
Einstellung `ui_level` bzw. als Einstellung der Person
([Einstellungen](../referenz/einstellungen.md)).

### Die Übersicht für Einsteiger

Sobald Verbrauchsdaten da sind, zeigt die Übersicht in der Stufe
„Einsteiger“ drei Antworten statt aller Kennzahlen:

- **„Bekomme ich Geld zurück?“** — je Vertrag mit Abschlag „rund … zurück“,
  „rund … Nachzahlung“ oder „etwa ausgeglichen“, mit dem Tag der Abrechnung.
  Ohne Vertrag: „Noch kein Vertrag mit Abschlag erfasst.“ und der Link
  „Vertrag eintragen“.
- **„Mehr oder weniger als im Vorjahr?“** — je Verbrauchsart die letzten
  zwölf Monate gegen dieselben Monate im Vorjahr, sobald mindestens sechs
  Monate vergleichbar sind; unter 2 % Abstand „etwa wie im Vorjahr“.
- **„Was ist zu tun?“** — bis zu vier Punkte aus „Zu tun“, etwa fällige
  Ablesungen, Termine und Kündigungsfristen.
- Zur Miete dazu **„Reicht die Vorauszahlung?“** mit der passenden
  Vorauszahlung je Monat, wenn es knapp wird.

Darunter erklärt das Schaubild „Wohin geht mein Geld?“ den Vertrag mit dem
höchsten Abschlag; oben steht der Knopf „Zählerstand erfassen“. Die Zahlen
sind dieselben wie in den anderen Stufen.

### Vorschläge zum Hochstufen

Braucht dein Haushalt mehr, als die Stufe zeigt, schlägt die Übersicht die
nächste Stufe vor: „Dein Haushalt hat mehr, als diese Stufe zeigt — „…“
blendet es ein.“

| Stufe | Anlass im Haushalt | Vorschlag |
|---|---|---|
| Einsteiger | seit v3.7.0 eine PV-Anlage oder ein Zähler mit der Rolle Speicher, Wärmepumpe oder Wallbox; Subzähler, Werte von Home Assistant (ein Zähler mit Alias), mehrere Zähler einer Verbrauchsart | Erfahren |
| Erfahren | seit v3.7.0 Viertelstundenwerte (bis v3.6 Wärmepumpe, Speicher oder Wallbox) | Experte |

„Auf „…“ umstellen“ stuft mit einem Klick hoch. Seit v3.7.0 erscheint jeder
Anlass **höchstens einmal** je Gerät und Stufe: Wer ihn gesehen hat, bekommt
ihn nicht wieder, auch ohne **„Nicht mehr fragen“**. Kommt ein neuer Anlass
dazu, erscheint nur er. Der Vorschlag blockiert nichts.

---

## 4. Schaubilder

Vier Bilder, die je eine Frage beantworten — mit deinen Zahlen:

| Schaubild | Was es zeigt | Erscheint, wenn … |
|---|---|---|
| **„Wohin geht mein Geld?“** | Abschläge gegen Kosten bis zur Abrechnung, die Kosten geteilt in Verbrauch, Grundpreis und „noch geschätzt“; das Ergebnis als Guthaben oder Nachzahlung | ein laufender Vertrag mit Abschlag da ist (nicht bei der Einspeisung) |
| **„Energiefluss im Haus“** | Sonne, Netz, Speicher, Haus und die Verbraucher Wärmepumpe, Wallbox und Übriges eines Jahres oder Monats: erzeugt, eingespeist, bezogen, geladen und entladen, Autarkie; Knöpfe heben die Flüsse eines Knotens hervor (seit v3.4.0) | eine PV-Erzeugung erfasst ist; Speicher und Verbraucher zeigt es mit den Zählern dieser Rollen |
| **„Kälter oder mehr verbraucht?“** | Verbrauch und Heizgradtage gegen dieselben Monate im Vorjahr, dazu das witterungsbereinigte Ergebnis: „Du hast wirklich weniger verbraucht“, „Du hast wirklich mehr verbraucht — nicht nur das Wetter“ oder „etwa gleich — der Unterschied kommt vom Wetter“ (Grenze ±2 %) | eine Heizenergie (Gas, Fernwärme, Heizöl, Holzpellets, Heizwärme) witterungsbereinigte Monate hat — gezählt werden nur ganze Monate mit bereinigtem Wert aus den letzten zwölf, mindestens sechs mit Vorjahr |
| **„Vertrag auf einen Blick“** | Beginn, heute, „Kündigen bis“ bzw. „jederzeit kündbar“, Ende bzw. frühestes Ende, Mindestlaufzeit, Preisgarantie, Preiserhöhung und Folgevertrag auf einem Zeitstrahl um heute | ein laufender Vertrag da ist |

**Wo sie stehen:**

- **Verbrauchsansicht einer Art**, in jeder Stufe: die Karte „Schaubilder“
  mit allen, die zu dieser Art passen.
- **Übersicht, ab Stufe Erfahren** (seit v3.7.0, bis v3.6 Experte): der Abschnitt „Schaubilder“ — Geld und
  Zeitstrahl für den Vertrag mit dem höchsten Abschlag, der Energiefluss des
  Vorjahres und „Kälter oder mehr verbraucht?“ für die Heizung.
- **Übersicht, Stufe Einsteiger:** ein Schaubild, „Wohin geht mein Geld?“.

**Bewegung:** Balken wachsen und Flüsse laufen, sobald ein Bild ins Blickfeld
kommt. Ist am Gerät „Bewegung reduzieren“ eingeschaltet, steht gleich das
fertige Bild da; beim Drucken ebenso. Die Aussage jedes Bildes steht als Text
darunter — die Grafik selbst ist für Screenreader ausgeblendet, der Text
nicht. Farben folgen Tag/Nacht und der Farbe der Verbrauchsart. Die Bilder
sind SVG mit CSS-Animation, ohne Bibliothek.

---

## 5. Die öffentliche Demo

Ohne Installation: [bingerminger.github.io/energietracker](https://bingerminger.github.io/energietracker/).
Beim ersten Besuch öffnet sich der Assistent mit vier Schritten — „Sprache
und Land“, „Wer bist du?“, „Wie viel Erfahrung hast du?“ und „So geht es
weiter“. Danach zeigt die Demo den gewählten Beispielhaushalt; „Überspringen“
zeigt das Schaufenster.

- **Sprache:** gilt je Gerät, wie die Sprachwahl in der Hinweisleiste. Das
  **Land** ist in der Demo das des Beispielhaushalts (Deutschland) und lässt
  sich nicht ändern — die Demo hat keinen Server, der es speichern könnte.
- **Der Browser merkt sich** Sprache, Beispielhaushalt und Stufe. Gespeichert
  wird in der Demo sonst nichts.
- **Wechseln:** Einstellungen → Allgemein → „Einrichtungsassistent starten“.
- **Direkt verlinken:** `?persona=` mit dem Namen des Beispielhaushalts,
  etwa
  [`…/energietracker/?persona=eigenheim-modern`](https://bingerminger.github.io/energietracker/?persona=eigenheim-modern).

---

[← Kompendium-Index](../README.md)
