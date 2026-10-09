# Einrichten und Nutzungsstufen

**Deutsch** · [English](../en/einstieg/einrichtung.md)

[← Kompendium-Index](../README.md)

Seit **v3.2.0** beginnt eine neue Installation mit drei Fragen statt mit einer
leeren Oberfläche. Der Einrichtungsassistent stellt danach Verbrauchsarten und
Nutzungsstufe ein und zeigt auf Wunsch einen Beispielhaushalt, der zu dir
passt. Die Nutzungsstufe bestimmt, wie viel die Oberfläche zeigt — gerechnet
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

Der Dialog „Einrichten“ hat vier Schritte („Schritt 1 von 4“):

1. **„Wer bist du?“** — eine von fünf Antworten (Tabelle unten). Die Antwort
   schlägt Verbrauchsarten und eine Stufe vor.
2. **„Welche Energieträger nutzt du?“** — die vorgeschlagenen Verbrauchsarten
   sind angehakt; du kombinierst frei, mindestens eine muss bleiben. Ein
   Hinweis erklärt den Unterschied zwischen Fernwärme und Heizwärme
   ([§ 2](#fernwärme-oder-heizwärme)).
3. **„Wie viel Erfahrung hast du?“** — Einsteiger, Erfahren oder Experte
   ([§ 3](#3-nutzungsstufen)).
4. **„So geht es weiter“** — die Zusammenfassung und eine Wahl:
   - **„Mit Beispieldaten ansehen“** lädt den Beispielhaushalt zur Antwort aus
     Schritt 1 ([§ 2](#2-die-beispielhaushalte)). Vorher sichert die App den
     jetzigen Stand als Snapshot.
   - **„Mit eigenen Daten starten“** führt zu „Zählerstände“: Zähler prüfen,
     den ersten Stand erfassen — weiter mit den
     [Ersten Schritten](erste-schritte.md).

Gespeichert wird erst mit **„Los geht’s“**: die aktiven Verbrauchsarten, das
Wohnverhältnis (Einstellungen → Haushalt & Gebäude → „Ich wohne“: „zur Miete“
bei „Ich wohne zur Miete“, sonst „im Eigentum“), die Antwort aus Schritt 1
und die Stufe. „Zurück“ blättert, „Überspringen“ lässt
alles, wie es ist.

| Antwort in Schritt 1 | vorgeschlagene Verbrauchsarten | vorgeschlagene Stufe |
|---|---|---|
| „Ich wohne zur Miete“ | Strom, Heizwärme, Wasser | Einsteiger |
| „Eigentumswohnung mit Fernwärme“ | Strom, Fernwärme | Einsteiger |
| „Eigenheim mit Gas, Öl oder Pellets“ | Gas, Strom, Wasser | Erfahren |
| „Eigenheim mit Wärmepumpe und PV“ | Strom, Heizwärme, Wasser, PV-Erzeugung, PV-Einspeisung | Erfahren |
| „Erst einmal alles ansehen“ | alle | Experte |

Wer mit Heizöl oder Pellets heizt, hakt in Schritt 2 um: Heizöl oder
Holzpellets an, Gas aus.

### Erneut starten

Einstellungen → Allgemein → Karte „Nutzungsstufe und Einrichtung“ →
**„Einrichtungsassistent starten“**. Beim erneuten Start sind deine aktiven
Verbrauchsarten und deine Stufe vorgewählt. Eine Antwort in Schritt 1 setzt
die Haken in Schritt 2 auf ihren Vorschlag, die Stufe lässt sie dann in Ruhe.
So lädst du auch später einen anderen Beispielhaushalt.

---

## 2. Die Beispielhaushalte

Zu jeder Antwort in Schritt 1 gibt es einen Beispielhaushalt — vier neue und
das **Schaufenster**, der bisherige Demo-Haushalt mit fast allen
Verbrauchsarten. Alle sind erfunden: Namen, Anbieter und Zählernummern sind
Beispiele. Standort ist Leipzig, die Daten reichen über drei Jahre und werden
beim Laden bis heute fortgeschrieben — Zählerstände, Monatswerte und
Temperaturen mit den Werten desselben Zeitraums im Vorjahr, Termine relativ zu
heute.

| Beispielhaushalt | Verbrauchsarten | Was drinsteckt | Gut zu sehen |
|---|---|---|---|
| **Mietwohnung** („Ich wohne zur Miete“) | Strom, Heizwärme, Wasser | 62 m², eine Person, Zentralheizung mit Gas im Keller. Strom mit eigenem Liefervertrag, Anbieterwechsel und Verlängerung; Heizwärme als Monatswerte aus der Verbrauchsinfo des Messdienstes (mit Vormonat, Vorjahresmonat und Durchschnittsnutzer); Kalt- und Warmwasserzähler; Mietverhältnis mit Vorauszahlung für Heizung und Betriebskosten und zwei Nebenkostenabrechnungen samt CO₂-Kostenanteil des Vermieters | die Übersicht für Einsteiger mit „Reicht die Vorauszahlung?“, Heizwärme ohne eigenen Vertrag, [Als Mieter](../anleitungen/mieter.md) |
| **Eigentumswohnung mit Fernwärme** | Strom, Fernwärme | 85 m², zwei Personen; Fernwärme heizt und bereitet das Warmwasser. Wärmemengenzähler mit Monatsständen aus dem Kundenportal; eigener Fernwärmevertrag mit Arbeits-, Grund-, Leistungs- (6,5 kW) und Messpreis und mehreren Preisänderungen; die Jahresrechnung 2025 ist erfasst; Strom mit Anbieterwechsel | Fernwärme als Liefervertrag mit Rechnung, [Jahresabrechnung prüfen](../anleitungen/jahresabrechnung.md), Eichfrist als Termin |
| **Eigenheim klassisch** („Eigenheim mit Gas, Öl oder Pellets“) | Gas, Strom, Wasser | Einfamilienhaus, 140 m², vier Personen, Gas-Brennwertkessel. Gasvertrag nach Preisvergleich gewechselt, mit Preisgarantie; Schlussrechnung mit Guthaben und Jahresabrechnung mit Nachzahlung und neuem Abschlag als Sonderzahlungen; Zäsur „Hydraulischer Abgleich“ im September 2024; Wasser mit Hauptzähler und Gartenzähler als Subzähler, Trink-, Schmutz- und Niederschlagswasser; Termine für Wartung, Schornsteinfeger und Eichung | Wetterbereinigung und Zäsur, Wechsel prüfen, Subzähler |
| **Eigenheim modern** („Eigenheim mit Wärmepumpe und PV“) | Strom, PV-Einspeisung, PV-Erzeugung, Heizwärme | Einfamilienhaus, 150 m², vier Personen. Haushaltszähler; Wärmepumpe mit eigenem Zähler und Wärmepumpentarif mit reduziertem Netzentgelt nach § 14a EnWG (Modul 1); Wallbox als Subzähler des Haushaltszählers, mit Ladevorgängen aus evcc; ein dynamischer Tarif als Angebot zum Vergleich; PV mit 9,8 kWp auf Ost- und Westdach mit Einspeisevergütung; Speicher mit 10 kWh; Wärmemengenzähler der Wärmepumpe | Jahresarbeitszahl, Energiefluss und Autarkie, [Ladevorgänge aus evcc](../anleitungen/evcc.md), [Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md), Dynamik-Check |
| **Schaufenster** („Erst einmal alles ansehen“) | Gas, Strom, Wasser, Fernwärme, Heizöl, Holzpellets, PV-Einspeisung, PV-Erzeugung | der bisherige Demo-Haushalt: ein Einfamilienhaus mit acht Verbrauchsarten — alle außer Heizwärme. Zählertausch, Zäsur, Angebote zum Vergleich, Tankbuch; Einzelheiten in [demo-data/README.md](../../demo-data/README.md) | alles auf einmal, für die Stufe „Experte“ |

### Fernwärme oder Heizwärme?

Die Beispielhaushalte zeigen den Unterschied, den auch der Hinweis in Schritt 2
erklärt:

- **Fernwärme** hat einen eigenen Liefervertrag mit Rechnung vom Versorger —
  wie in der Eigentumswohnung ([Fernwärme](../verstehen/04-fernwaerme.md)).
- **Heizwärme** wird nur gemessen. Bezahlt wird sie über die Miete (die
  Mietwohnung), oder sie ist die Wärme einer Wärmepumpe (Eigenheim modern)
  ([Heizwärme](../verstehen/15-waerme.md)).

### Laden und zurück

- **Im Assistenten:** Antwort in Schritt 1 wählen, am Ende „Mit Beispieldaten
  ansehen“.
- **Einstellungen → Daten → „Demo-Daten laden“** und **„Mit Beispieldaten
  ausprobieren“** auf der leeren Übersicht laden das Schaufenster.

Ein Beispielhaushalt **ersetzt den ganzen Haushalt**: Zähler, Stände,
Verträge, Mietverhältnis, Termine und die Einstellungen des Haushalts.
Verbrauchsarten, die er nicht nutzt, sind danach leer. **Deine Nutzungsstufe
bleibt.** Vorher legt die App einen Snapshot an; sind schon Daten da, fragt
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

## 3. Nutzungsstufen

Drei Stufen: **🌱 Einsteiger**, **🌿 Erfahren** und **🌳 Experte**. Sie
bestimmen, was du siehst — nicht, was die App rechnet.

| Bereich | 🌱 Einsteiger | 🌿 Erfahren | 🌳 Experte |
|---|---|---|---|
| Übersicht | drei Antworten: Bekomme ich Geld zurück? Mehr oder weniger als im Vorjahr? Was ist zu tun? — mit einem Schaubild | alle Kennzahlen | dazu der Abschnitt „Schaubilder“ |
| Zählerstände | erfassen, Foto als Beleg | dazu CSV-Import | dazu Zeitreihen aus Portalen und Texterkennung |
| Verbrauch je Art | Monatsverlauf, Saldo, Schaubilder | dazu Vorjahr, witterungsbereinigt, Tabelle | alles, etwa Anomalien, CO₂-Preis, Jahresarbeitszahl, Ladestrom-Nachweis |
| Verträge | Arbeitspreis, Grundpreis, Abschlag | dazu Preiswechsel, Boni, Kündigung, Sonderzahlungen | dazu Gruppen und HT/NT, § 14a, Schattenverträge, Monatspreise, Leistungspreis der Fernwärme |
| Wechsel prüfen, Rechnung prüfen | ausgeblendet — die Empfehlungen verweisen darauf | ja | dazu Dynamik-Check und Börsenpreise |
| Mietverhältnis (zur Miete) | „Reicht die Vorauszahlung?“ auf der Übersicht | die ganze Seite | dazu CO₂-Aufteilung und Prüfung |
| Auswertungen | Jahresbericht | dazu Analyse, Prognose, Wetterdaten | dazu Zäsur, Modelle, Szenarien |
| Zähler-Aufbau | ausgeblendet | Subzähler, Rollen | dazu Gruppen, Alias, Geräte |
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

Haben deine Daten mehr zu bieten, als die Stufe zeigt, schlägt die Übersicht
die nächste Stufe vor: „Deine Daten können mehr, als diese Stufe zeigt —
„…“ blendet es ein.“

| Stufe | Anlass in den Daten | Vorschlag |
|---|---|---|
| Einsteiger | Subzähler, Werte von Home Assistant (ein Zähler mit Alias), mehrere Zähler einer Verbrauchsart | Erfahren |
| Erfahren | ein Zähler mit der Rolle Wärmepumpe, Speicher oder Wallbox | Experte |

„Auf „…“ umstellen“ stuft mit einem Klick hoch. **„Nicht mehr fragen“**
merkt sich dieses Gerät, je Stufe und Anlass. Der Vorschlag blockiert nichts.

---

## 4. Schaubilder

Vier Bilder, die je eine Frage beantworten — mit deinen Zahlen:

| Schaubild | Was es zeigt | Erscheint, wenn … |
|---|---|---|
| **„Wohin geht mein Geld?“** | Abschläge gegen Kosten bis zur Abrechnung, die Kosten geteilt in Verbrauch, Grundpreis und „noch geschätzt“; das Ergebnis als Guthaben oder Nachzahlung | ein laufender Vertrag mit Abschlag da ist (nicht bei der Einspeisung) |
| **„Energiefluss im Haus“** | PV, Haus, Netz und Speicher eines Jahres: erzeugt, selbst genutzt, eingespeist, bezogen, Autarkie | eine PV-Erzeugung erfasst ist; den Speicher zeigt es mit Speicherzählern |
| **„Kälter oder mehr verbraucht?“** | Verbrauch und Heizgradtage gegen dieselben Monate im Vorjahr, dazu das witterungsbereinigte Ergebnis: „Du hast wirklich weniger verbraucht“, „Du hast wirklich mehr verbraucht — nicht nur das Wetter“ oder „etwa gleich — der Unterschied kommt vom Wetter“ (Grenze ±2 %) | eine Heizenergie (Gas, Fernwärme, Heizöl, Holzpellets, Heizwärme) witterungsbereinigte Monate hat — gezählt werden nur ganze Monate mit bereinigtem Wert aus den letzten zwölf, mindestens sechs mit Vorjahr |
| **„Vertrag auf einen Blick“** | Beginn, heute, „Kündigen bis“, Preiserhöhung und Ende auf einem Zeitstrahl | ein laufender Vertrag da ist |

**Wo sie stehen:**

- **Verbrauchsansicht einer Art**, in jeder Stufe: die Karte „Schaubilder“
  mit allen, die zu dieser Art passen.
- **Übersicht, Stufe Experte:** der Abschnitt „Schaubilder“ — Geld und
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
Beim ersten Besuch öffnet sich der Assistent mit drei Schritten — „Wer bist
du?“, „Wie viel Erfahrung hast du?“ und „So geht es weiter“. Danach zeigt die
Demo den gewählten Beispielhaushalt; „Überspringen“ zeigt das Schaufenster.

- **Der Browser merkt sich** Beispielhaushalt und Stufe. Gespeichert wird in
  der Demo sonst nichts.
- **Wechseln:** Einstellungen → Allgemein → „Einrichtungsassistent starten“.
- **Direkt verlinken:** `?persona=` mit dem Namen des Beispielhaushalts,
  etwa
  [`…/energietracker/?persona=eigenheim-modern`](https://bingerminger.github.io/energietracker/?persona=eigenheim-modern).

---

[← Kompendium-Index](../README.md)
