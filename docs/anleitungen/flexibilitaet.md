# Flexibilität bewerten

**Deutsch** · [English](../en/anleitungen/flexibilitaet.md)

[← Kompendium-Index](../README.md)

Die Wärmepumpe läuft nachts, das Auto lädt mittags, der Speicher gibt abends
ab: Wer Verbrauch verschiebt, will wissen, ob es sich gelohnt hat — und wann es
sich lohnt. Seit **v3.3.0** rechnet der Energietracker das je Viertelstunde
nach, mit deinem Verbrauch und dem Börsenstrompreis derselben Viertelstunde.
Seit **v3.6.0** lenkt die Seite **Auswertungen → Flexibilität** auch: Eine
Ampel zeigt, wann Strom günstig oder teuer ist und wann die Sonne scheint,
„Dein Hebel“ rechnet in Euro, was Verschieben je Gerät im Jahr bringen kann,
und klare Sätze sagen, wann du was laufen lassen solltest. Die große Zahl gibt
es als Bild zum Weitergeben, als Seite im Jahresbericht und als Kachel
„Dein Hebel“ auf der Übersicht.

> **Der Energietracker steuert nichts — er rechnet nach.** Gesteuert wird
> woanders: evcc lädt das Auto, Home Assistant schaltet die Wärmepumpe, der
> Speicher regelt sich selbst. Wie das zusammenspielt, zeigen die
> [Anwendungsfälle](anwendungsfaelle.md#g--steuern-mit-evcc-oder-home-assistant--nachrechnen-im-energietracker).

**Drei Preissignale, zwei Arten von Geld.** Der Börsenpreis je Viertelstunde
zählt nur den **Börsenanteil** des Strompreises — ohne Umlagen, Netzentgelt und
den Aufschlag des Lieferanten. Bares Geld wird daraus nur mit einem dynamischen
Tarif, der je Viertelstunde abrechnet; die Seite sagt deshalb dazu „mit
dynamischem Tarif“. Seit v3.6.0 enthalten die Euro-Beträge, die auf dem
Börsenpreis beruhen, die **Umsatzsteuer**, so wie ein dynamischer Tarif sie
abrechnet: den Satz deines dynamischen Angebots unter Wechsel prüfen, ohne
Angebot den Regelsatz von 19 % (§ 12 Abs. 1 UStG). Mit dem Vertrag, wie er
ist, wirken zwei andere Signale: das **zeitvariable Netzentgelt** nach § 14a
Modul 3 (§ 6) und der **Sonnenstrom** — jede kWh, die statt aus dem Netz von
der eigenen Anlage kommt, spart den Arbeitspreis (wie auf der Rechnung, mit
Umsatzsteuer) und kostet nur die Einspeisevergütung, die sonst gezahlt worden
wäre. Diese Beträge tragen „mit deinem Tarif“. Seite, Bericht und Kachel
sagen deshalb „inkl. USt“.

---

## 1. Was du brauchst

- **Jede Stufe.** Seit v3.6.0 steht „Flexibilität“ für alle unter
  Auswertungen (bis v3.5.0 nur für „Experte“). **Einsteiger** sehen die
  Ergebniskarte, die Handlungssätze und den typischen Tag der Ampel, ab
  **„Erfahren“** dazu die Woche, die besten Zeitfenster, „Sonnenstrom selbst
  nutzen“ und „Dein Hebel“, ab **„Experte“** die Nachrechnung je Zähler, den
  Speicher, Modul 3 und die Viertelstundenreihen
  ([Nutzungsstufen](../einstieg/einrichtung.md#3-nutzungsstufen)).
- Für die **Ampel** genügen die **Börsenpreise je Viertelstunde** des Jahres
  von SMARD (§ 3) — oder, ohne sie, die Zeitfenster für Modul 3 am
  Stromvertrag (§ 6).
- **Verbrauch je Viertelstunde** für mindestens einen Stromzähler (§ 2): Damit
  rechnet die Seite nach, was dein Verschieben gebracht hat, und „Dein Hebel“
  rechnet mit gemessenen Werten. Am meisten sagt er bei einem eigenen Zähler
  für Wärmepumpe oder Wallbox (Rolle am Zähler).
- Für **„Sonnenstrom selbst nutzen“** Erzeugung und Einspeisung je
  Viertelstunde (PV-Erzeugung, PV-Einspeisung).
- Für den **Speicher** zwei Zähler unter PV-Erzeugung mit den Rollen
  „Speicher – Ladung“ und „Speicher – Entladung“, beide mit Viertelstunden
  (§ 5).
- Für **§ 14a Modul 3** die Zeitfenster am Stromvertrag (§ 6). Börsenpreise
  braucht Modul 3 nicht.
- Für die **Ladefenster des Autos** die Ladevorgänge aus evcc am Zähler der
  Wallbox ([evcc](evcc.md)): Ein Vorgang reicht vom Anstecken bis zum
  Abstecken. Ohne sie nimmt die Seite an, dass das Auto am Ladetag den ganzen
  Tag bereitsteht, und kennzeichnet das als Beispielwert (§ 4, „Dein Hebel“).

Gerechnet wird je Kalenderjahr in der Zeitzone der Installation
(Einstellungen → Allgemein → Zeitzone). Ein Jahr hat 35.040 Viertelstunden,
ein Schaltjahr 35.136; die Tage der Zeitumstellung haben 92 bzw. 100.

---

## 2. Verbrauch je Viertelstunde

Zwei Wege, auch nebeneinander. Ein neuer Wert ersetzt einen vorhandenen in
derselben Viertelstunde.

### Aus einer Portaldatei

Den Lastgang vom Netz- oder Messstellenbetreiber, vom Wechselrichter oder von
der Wärmepumpe liest du mit **„Zeitreihe importieren“** ein
([Zeitreihen aus Portalen](daten-aus-portalen.md)). Stehen die Werte im
15-, 30- oder 60-Minuten-Takt in der Datei, behält der Energietracker sie
zusätzlich zu den Tageswerten je Viertelstunde:

- Den Takt bestimmt der häufigste Abstand zwischen zwei Zeitstempeln.
- **Verbrauch je Intervall** verteilt sich gleichmäßig auf seine
  Viertelstunden — ein Stundenwert auf vier, ein Halbstundenwert auf zwei.
- **Zählerstände** ergeben die Differenz zweier aufeinanderfolgender Stände.
  Fehlt dazwischen ein Stempel oder sinkt der Stand, bleibt die Lücke leer.
- **Tageswerte** ergeben keine Viertelstunden.

Die Vorschau zeigt die Viertelstunden nicht eigens. Nach „Übernehmen“ steht die
Reihe in der Liste „Viertelstundenreihen“ (§ 8).

### Aus Home Assistant, alle 15 Minuten

Home Assistant schickt den Zählerstand **mit Zeitstempel**: Die Vorlage des
REST-Commands enthält seit v3.3.0 die Zeile
`"timestamp": "{{ now().isoformat() }}"`
([Home Assistant, Schritt 3](home-assistant.md#schritt-3--rest-command-in-home-assistant)).
Der Energietracker verteilt die Differenz zum vorigen Stand nach Zeitanteil auf
die Viertelstunden dazwischen. Damit daraus Viertelstunden werden, sendet eine
zweite Automatisierung alle 15 Minuten; die tägliche aus Schritt 4 bleibt, wie
sie ist. Für eine Wärmepumpe und eine Wallbox:

```yaml
alias: "Energie: Wärmepumpe und Wallbox je Viertelstunde"
description: "Sendet alle 15 Minuten die Zählerstände mit Zeitstempel"
triggers:
  - trigger: time_pattern
    minutes: "/15"
actions:
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.waermepumpe_strom_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "strom_waermepumpe"
          sensor_entity: "sensor.waermepumpe_strom_total_kwh"
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.wallbox_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "strom_wallbox"
          sensor_entity: "sensor.wallbox_total_kwh"
mode: single
```

`minutes: "/15"` löst zu jeder vollen Viertelstunde aus (:00, :15, :30, :45).
Jeder Push aktualisiert wie bisher den Stand des Tages; die Datei der
Zählerstände wächst weiter um einen Eintrag je Tag. Die Antwort nennt in
`intervals`, wie viele Viertelstunden der Stand berührt hat.

**Nichts verteilt** wird bei

- dem ersten Stand mit Zeitstempel — er ist nur der Anfang;
- einem Stand, der nicht neuer ist als der vorige (doppelt gesendet oder
  nachgeliefert);
- einem Stand, der kleiner ist als der vorige
  ([Fallende Werte](home-assistant.md#fallende-werte-seit-v260));
- einer Lücke von mehr als sechs Stunden, etwa nach einem Ausfall. Der neue
  Stand gilt dann als neuer Anfang; die Lücke bleibt leer. Füllen kann sie eine
  Portaldatei.

Deshalb entstehen aus dem täglichen Push allein keine Viertelstunden: Zwischen
zwei Abenden liegen mehr als sechs Stunden.

---

## 3. Börsenpreise je Viertelstunde von SMARD

Oben auf der Seite: **„Börsenpreise {Jahr} von SMARD laden“** (fehlen sie,
steht derselbe Knopf auch in der Einladung, § 4). Der Server holt die
Day-Ahead-Preise für Deutschland/Luxemburg (DE-LU) des gewählten Jahres von
SMARD, der Strommarktplattform der Bundesnetzagentur, Woche für Woche, und
rechnet €/MWh in ct/kWh um. Das dauert einen Moment; danach meldet die App
etwa „Börsenpreise 2025 geladen: 35.040 Viertelstunden.“

- **Nur auf Knopfdruck**, je Jahr. Hinaus geht nur die Anfrage an SMARD, keine
  Daten von dir.
- **Laufendes Jahr:** Geladen wird, was SMARD bis dahin veröffentlicht hat.
  Später noch einmal laden ergänzt den Rest; vorhandene Werte werden ersetzt.
- **Vor dem 1. Oktober 2025** liefert SMARD Stundenpreise: Dann steht in allen
  vier Viertelstunden einer Stunde derselbe Preis.
- Die Monatsmittel der Karte „Börsenstrompreise“ unter Wechsel prüfen sind eine
  eigene Liste; dieser Knopf füllt sie nicht.
- Quelle: Bundesnetzagentur | SMARD.de, Lizenz CC BY 4.0 — die Seite nennt sie
  unter den Zahlen und im Fuß der Ergebniskarte.

---

## 4. Die Seite Karte für Karte

**Auswertungen → Flexibilität**, oben das Jahr: das laufende und die drei
davor, vorgewählt ist das Vorjahr. Alle Zahlen rechnet der Server; die Seite
formatiert nur und wählt, welche Jahreszeit die Ampel zeigt.

### Fehlt etwas: die Einladung

Statt Fehlermeldungen steht oben **„Was die Seite noch zeigen kann“** — je
fehlendem Baustein ein Satz, was er bringt:

| Fehlt | Was er bringt |
|---|---|
| **Börsenpreise je Viertelstunde** | Ampel mit günstigen und teuren Stunden, „Dein Hebel“ in Euro — auch für Wasch- und Spülmaschine. Mit dem Knopf „Börsenpreise {Jahr} von SMARD laden“ |
| **Verbrauch je Viertelstunde** | wann du verbrauchst, und die Nachrechnung, was dein Verschieben gebracht hat (§ 2) |
| **Wallbox oder Wärmepumpe mit eigenem Zähler** | „Dein Hebel“ mit gemessenen Werten statt nur Beispielwerten |
| **Erzeugung und Einspeisung je Viertelstunde** | wie viel Sonnenstrom du mehr selbst nutzen könntest (erscheint nur mit PV-Zählern) |
| **Dynamischer Tarif oder Modul 3** | wann Verschieben bares Geld bringt: ein dynamisches Angebot unter Wechsel prüfen oder Zeitfenster am Vertrag (§ 6) |

Schon mit den Börsenpreisen allein zeigt die Seite die Ampel, die besten
Zeitfenster und die Beispielwerte für Wasch- und Spülmaschine.

### Ergebniskarte „Dein Hebel“

Eine große Zahl: was du **mit deinem Tarif** im Jahr sparen kannst, wenn du
Verbrauch in günstige und sonnige Stunden verschiebst. Gibt es keinen Hebel mit
dem Vertrag, wie er ist, zeigt sie, was ein dynamischer Tarif brächte, und sagt
das. Darunter bis zu drei Angaben: der größte Hebel (mit deinem Tarif, wenn die
große Zahl so rechnet), „Mit dynamischem Tarif: bis zu …“, „Sonnenstrom selbst
genutzt“ oder „Modul 3 schon gespart“. Der Fuß nennt Quelle und Stand und
„Beträge inkl. USt“. Dieselbe Zahl zeigt die Kachel **„Dein Hebel“ auf der
Übersicht** ([Ansichten](../referenz/ansichten.md#1-übersicht-dashboard)).

**„Als Bild speichern“** zeichnet die Karte im Browser als PNG
(1200 × 630 Pixel, das Format von Vorschaubildern) — ohne Dienst nach außen,
wie die Ergebniskarte der Prognose-Werkstatt
([Was wäre wenn?](was-waere-wenn.md)).

### Was du tun kannst

Je Gerät ein Satz mit Zeitfenster und Betrag, größter zuerst, etwa „Lade das
Auto mit Sonnenstrom, 11:00–14:00 Uhr: bis zu 65 € im Jahr.“ Daneben steht, ob
er **mit deinem Tarif** oder **mit dynamischem Tarif** gilt und ob ein
**Beispielwert** dahintersteckt. Das Zeitfenster gilt für das laufende
Halbjahr (April bis September Sommer, sonst Winter); darunter klein das
Fenster des anderen.

Je Gerät gewinnt der größte Hebel mit dem Vertrag, wie er ist; nur wo es
keinen gibt — oder wo er im laufenden Halbjahr kein Zeitfenster hat —, der mit
einem dynamischen Tarif. Die Fenster:

| Gerät, Signal | Zeitfenster |
|---|---|
| Auto mit Steckzeiten aus evcc | nur Stunden, in denen das Auto im Halbjahr meist angesteckt ist (in mindestens der Hälfte der Ladevorgänge): mit Börsenpreis die günstigsten drei, mit Sonnenstrom die sonnigsten — ist es mittags nie da, gibt es keinen Satz mit Sonne |
| Auto ohne Steckzeiten, Wasch- und Spülmaschine mit Börsenpreis | das günstigste Fenster der Woche (Wochentag und Uhrzeit, 3 bzw. 2 Stunden) |
| Wärmepumpe mit Börsenpreis | die günstigsten drei Stunden des typischen Tages, jeden Tag |
| Speicher | die teuersten drei Stunden des typischen Tages — dann entladen |
| alle mit Modul 3 | das Niedrigtarif-Fenster laut Vertrag |
| alle anderen mit Sonnenstrom | die sonnigsten Stunden des typischen Tages |

Neben dem Satz zum Auto steht, welche Grundlage gilt: **„Steckzeiten aus
evcc“** oder **„ganzer Ladetag (Beispielwert)“**.

### Wann Strom günstig ist (Ampel)

Oben die Wahl **Winterhalbjahr · Sommerhalbjahr · Ganzes Jahr** (vorgewählt
das laufende Halbjahr).

- **Ein typischer Tag** (alle Stufen): 24 Stunden als Band. Je Stunde der
  mittlere Börsenpreis; **grün ✔** das günstigste Drittel, **gelb ▲** das
  mittlere, **rot ✖** das teuerste — die Stufe immer als Farbe **und** Symbol,
  für Screenreader als Satz („13:00 Uhr: günstig, 8,3 ct, viel Sonne“). Darüber
  **☀**, wann die Anlage kräftig erzeugt (mindestens die Hälfte der stärksten
  Stunde; blass ab 15 %). Darunter ein Satz: „Am günstigsten: sonntags
  12:00–15:00 Uhr. Am teuersten: 17:00–20:00 Uhr.“ und die Spanne der Preise.
- **Die Woche** (ab „Erfahren“): dieselben Stufen je Wochentag und Stunde,
  7 × 24 Felder, als Tabelle mit Text je Feld.

Die ct-Werte der Ampel sind der **Börsenanteil netto, ohne USt** — so steht
es über dem Band und an der Spanne („… (Börsenanteil netto, ohne USt und
Netzentgelt)“). Die Euro-Beträge der Hebel daneben rechnen inkl. USt.

Ohne Börsenpreise, aber mit Zeitfenstern für Modul 3 zeigt die Ampel das
Netzentgelt: grün der Niedrigtarif, gelb der Standardtarif, rot der Hochtarif.
Ohne beides nur die Sonne.

```text
Preis einer Stunde   = Mittel aller Viertelstunden dieser Stunde (und dieses Wochentags) im Halbjahr
günstig / teuer      = unteres / oberes Drittel der 24 Stunden (Tag) bzw. der 168 Felder (Woche)
                       fallen beide Grenzen auf denselben Preis, ist er „mittel“
Sonne einer Stunde   = mittlere Erzeugung je Viertelstunde ÷ die der stärksten Stunde
```

### Beste Zeitfenster der Woche

Ab „Erfahren“: die drei günstigsten Fenster von je drei Stunden am Stück im
gewählten Halbjahr, die sich an einem Tag nicht überschneiden — mit Ø
Börsenpreis, wie viel günstiger als das Mittel und wie viel Sonne. Die Karte
trägt das Schild **„netto, ohne USt“**, die Spalte heißt „Ø Börsenpreis
(netto)“.

### Sonnenstrom selbst nutzen

Ab „Erfahren“, mit Erzeugung und Einspeisung je Viertelstunde: wie viel du
selbst genutzt hast (Erzeugung minus Einspeisung), wie viel ins Netz ging und
wie viel mehr die Hebel für Sonnenstrom (unten) selbst nutzen ließen — mit dem
Eigenverbrauch danach und dem Betrag aus Arbeitspreis minus
Einspeisevergütung. Der Balken zeigt beides. Die Zahlen können um weniger als
1 % von der PV-Seite abweichen: Dort zählen die Ablesungen (der Verbrauch
zwischen zwei Ständen gleichmäßig auf die Tage verteilt), hier die
Viertelstunden des Kalenderjahres.

### Dein Hebel

Ab „Erfahren“ die Tabelle: je Gerät und Preissignal, wie viele kWh wandern und
was das im Jahr bringt, größter zuerst. Darunter die Summe — je Gerät zählt
nur sein größter Hebel, sonst würde dieselbe kWh zweimal verschoben — und
davon der Teil mit deinem Tarif, beide inkl. USt.

**So rechnet der Server:** Verschoben wird nur **innerhalb eines Tages**
(Ortszeit) und höchstens mit der **größten gemessenen Leistung** des Geräts je
Viertelstunde. Der freie Teil des Tagesverbrauchs füllt die günstigsten
Viertelstunden des Tages zuerst. Der heutige Ablauf ist eine der möglichen
Verteilungen — ein Hebel ist deshalb **nie negativ**. Er ist eine
**Obergrenze**: Die günstigsten Viertelstunden kennt man am Vortag
(Day-Ahead), und das Gerät muss dann auch bereitstehen. Hebel unter 1 € im Jahr
erscheinen nicht.

**Das Auto lädt nur, solange es angesteckt ist.** Mit Ladevorgängen aus evcc
am Zähler der Wallbox ist jeder Vorgang vom Anstecken bis zum Abstecken ein
**Ladefenster** — abends angesteckt auch über Mitternacht bis zum nächsten
Morgen. Die Ladung wandert nur innerhalb ihres Fensters; Laden außerhalb eines
Fensters bleibt, wo es ist. An Tagen ganz ohne Ladevorgang (evcc lief noch
nicht, ein Vorgang fehlt) gilt die Annahme „am Ladetag den ganzen Tag bereit“.
Unter der Tabelle steht, welche Grundlage gilt, mit der Zahl der Ladevorgänge
und — wenn nicht alle Ladung in einem Fenster liegt — ihrem Anteil.

```text
Börsenpreis            Hebel = Σ Tage (Kosten heute − Kosten, wenn der freie Teil in den günstigsten
                                Viertelstunden des Tages liefe) ÷ 100 × (1 + USt)
Modul 3                Hebel = dasselbe mit dem Netzentgelt der Viertelstunde, ohne Aufschlag
Auto mit Steckzeiten   Tage   → Ladefenster (Anstecken bis Abstecken), sonst wie oben
Speicher               Hebel = Σ Tage (Wert, wenn das Entladen in den teuersten Viertelstunden läge, in
                                denen das Haus Strom braucht und der Speicher nicht lädt − Wert heute)
                                ÷ 100 × (1 + USt)
Sonnenstrom            kWh   = Verbrauch in Viertelstunden ohne Einspeisung (höchstens der Netzbezug des
                                Hauses dort), der in Viertelstunden mit Einspeisung des selben Tages
                                (beim Auto: desselben Ladefensters) passt
                       Hebel = kWh × (Arbeitspreis − Einspeisevergütung) ÷ 100
Wasch-/Spülmaschine    Hebel = Σ Tage kWh je Tag × (Mittel aller Startzeiten − bestes Fenster) ÷ 100
                                × (1 + USt) mit Börsenpreis
USt                    = Satz des dynamischen Angebots (Wechsel prüfen), ohne Angebot 19 %
```

- **Gemessen:** die Wallbox und die Wärmepumpe (Rollen der Stromzähler) und
  der Speicher. **Sonnenstrom** erreicht nur den Hausanschluss und seine
  Subzähler — eine Wärmepumpe mit eigenem Zähler und eigenem Tarif nicht. Die
  Einspeisung eines Tages wird dabei einmal verbraucht: zuerst vom Auto, dann
  von Wasch- und Spülmaschine.
- **Arbeitspreis** ist der des Hausanschlusses zur Monatsmitte,
  **Einspeisevergütung** die des Einspeisezählers.

**Beispielwerte** (auf der Seite und im Bericht so gekennzeichnet):

| Annahme | Wert |
|---|---|
| Wärmepumpe: verschiebbarer Teil des Tagesverbrauchs (Gebäude und Puffer) | 30 % |
| Auto ohne Ladevorgänge aus evcc: am Ladetag den ganzen Tag verfügbar | ganzer Ladestrom des Tages |
| Waschmaschine | 0,9 kWh je Gang, 4 Gänge je Woche, 2 Stunden |
| Spülmaschine | 1,0 kWh je Gang, 5 Gänge je Woche, 2 Stunden |

Wasch- und Spülmaschine haben keinen eigenen Zähler: Gerechnet wird gegen
einen **Start zu beliebiger Zeit** (das Mittel aller Startzeiten des Tages).

### Nachgerechnet: was das Verschieben gebracht hat

Ab „Experte“ die bisherigen Karten. Sie rechnen mit dem reinen Börsenanteil,
**ohne Umsatzsteuer** — ein Maß für das Verbrauchsprofil, keine Rechnung. Das
sagen sie auch: der Satz unter der Überschrift, das Schild **„netto, ohne
USt“** an jeder Zählerkarte und am Speicher, „(netto)“ an „Eigener Ø / Börse
Ø“, „Vorteil“ und „Wert der Verschiebung“. Die Karte zu Modul 3 trägt es nicht:
Sie rechnet mit den Beträgen am Vertrag.
**Eine Karte je Stromzähler** mit Viertelstunden im gewählten Jahr. Zähler mit der Rolle „Wärmepumpe
(Heizstrom)“ oder „Wallbox“ tragen das Schild **„steuerbar“** und stehen oben;
die übrigen — etwa der Hausanschluss — folgen zum Vergleich.

| Kennzahl | Bedeutung |
|---|---|
| **Verbrauch mit Preis** | kWh in Viertelstunden, für die es Verbrauch und Börsenpreis gibt — nur über sie wird gerechnet |
| **In günstigen Viertelstunden** | Anteil davon in Viertelstunden, deren Preis **unter dem Mittel ihres Monats** liegt |
| **Bei negativem Preis** | kWh in Viertelstunden mit einem Börsenpreis unter null |
| **Eigener Ø / Börse Ø (netto)** | dein Börsenpreis je kWh, nach Verbrauch gewichtet, gegen das Börsenmittel |
| **Profilfaktor** ⓘ | eigener Ø geteilt durch Börse Ø |
| **Vorteil (Börsenanteil, netto)** | was dein Verbrauchsprofil gegenüber dem Börsenmittel gespart (positiv) oder gekostet (negativ) hat |

```text
günstig        Preis der Viertelstunde < Mittel aller Viertelstunden ihres Monats
eigener Ø      = Σ (kWh × Preis) ÷ Σ kWh
Börse Ø        = Mittel aller Viertelstunden des Monats
                 fürs Jahr: Σ (Börse Ø des Monats × kWh des Monats) ÷ Σ kWh
Profilfaktor   = eigener Ø ÷ Börse Ø
Vorteil in €   = (Börse Ø − eigener Ø) × kWh ÷ 100
```

Alle Summen laufen über die Viertelstunden mit Verbrauch und Preis.

- **Profilfaktor unter 1:** Du verbrauchst eher, wenn Strom billig ist.
  **Über 1:** eher, wenn er teuer ist. Liegt das Börsenmittel nahe null (unter
  0,01 ct), bleibt der Profilfaktor leer.
- **Nur innerhalb des Monats.** Das Börsenmittel des Jahres ist nach deinem
  Verbrauch je Monat gewichtet. Es zählt also, wann du innerhalb eines Monats
  verbrauchst — dass eine Wärmepumpe im Winter mehr läuft, wenn Strom meist
  teurer ist, geht nicht ein.
- **Hausanschluss und Wallbox.** Ist die Wallbox ein Subzähler des
  Hausanschlusses, steckt ihr Netzbezug auch in dessen Karte. Die Karte der
  Wallbox zählt alles, was sie lädt, auch den Sonnenstrom mittags — als
  Verbrauchsprofil des Geräts, nicht als Rechnung.

Darunter ein Satz: „Günstiger als der Durchschnitt: … gespart beim
Börsenanteil“ oder „Teurer als der Durchschnitt: … mehr beim Börsenanteil“, mit
dem Profilfaktor. **„Je Monat“** klappt eine Tabelle auf: Monat, kWh (aller
Viertelstunden mit Verbrauch), günstig, negativ, eigener Ø, Börse Ø, Vorteil
und **Abdeckung** — der Anteil der Viertelstunden des Monats mit Verbrauch und
Preis.

Danach die Karten „Speicher nach Börsenpreis“ (§ 5) und „§ 14a Modul 3 —
{Zähler}“ (§ 6), wenn es die Daten dafür gibt, und unten die Liste aller
Viertelstundenreihen (§ 8).

---

## 5. Speicher

Die Karte **„Speicher nach Börsenpreis“** braucht zwei Zähler unter
**PV-Erzeugung** mit den Rollen „Speicher – Ladung“ und „Speicher – Entladung“
([PV §7](../verstehen/12-pv.md#7-speicher-v310)), beide mit Viertelstunden, und
die Börsenpreise des Jahres.

| Kennzahl | Bedeutung |
|---|---|
| **Geladen · Ø Preis** | geladene kWh und der Börsenpreis beim Laden, nach Menge gewichtet |
| **Entladen · Ø Preis** | entladene kWh und der Börsenpreis beim Entladen |
| **Wert der Verschiebung (netto)** | was das Verschieben über den Tag zum Börsenpreis wert war — Börsenanteil netto, ohne USt (Schild an der Karte) |

```text
Ø Ladepreis     = Σ (geladen × Preis) ÷ Σ geladen
Ø Entladepreis  = Σ (entladen × Preis) ÷ Σ entladen
Wert in €       = (Σ entladen × Preis − Σ geladen × Preis) ÷ 100
```

Jede kWh zählt mit dem Börsenpreis ihrer Viertelstunde: beim Laden, was der
Strom in diesem Moment an der Börse wert war, beim Entladen, was er dann wert
war. Der Speicherverlust steckt darin — es kommt weniger heraus, als
hineingeht. Nicht darin stecken der Vorteil des Eigenverbrauchs gegenüber dem
Bezugspreis und das Netzentgelt. Verluste, Wirkungsgrad und Vollzyklen zeigt
die Karte „Speicher {Jahr}“ unter PV-Erzeugung; ihre Mengen kommen aus den
Ablesungen und können deshalb um weniger als 1 % von den Viertelstunden hier
abweichen. Wie viel ein Entladen in den teuersten Stunden zusätzlich brächte,
steht unter „Dein Hebel“ (§ 4).

---

## 6. § 14a Modul 3: zeitvariables Netzentgelt

### Rechtslage kurz (Stand 9. Oktober 2026)

Keine Rechtsberatung — maßgeblich sind Gesetz, Festlegung und die Preisblätter
deines Netzbetreibers.

**Modul 3** ist ein zeitvariables Netzentgelt für steuerbare
Verbrauchseinrichtungen nach § 14a EnWG, in drei Stufen (Hochlast, Standard,
Niedriglast) — nur zusätzlich zu Modul 1 und mit intelligentem Messsystem.
Grundlage sind zwei Festlegungen der Bundesnetzagentur, zur Steuerung
(Beschlusskammer 6, BK6-22-300) und zu den Netzentgelten (BK8-22/010-A).
Netzbetreiber müssen Modul 3 seit dem 1. April 2025 anbieten und abrechnen; im
Mai 2026 stellte die Bundesnetzagentur fest, dass das vielerorts nicht oder nur
unzureichend geschieht. Quellen:
[Bundesnetzagentur, 27.11.2023](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2023/20231127_14a.html),
[Bundesnetzagentur, Reduzierung des Netzentgelts](https://www.bundesnetzagentur.de/DE/Vportal/Energie/SteuerbareVBE/Netzentgelt_table.html),
[Bundesnetzagentur, 28.05.2026](https://www.bundesnetzagentur.de/1108084).
Mehr, auch zu Modul 1 und 2, in den
[Anwendungsfällen](anwendungsfaelle.md#rechtslage-kurz-stand-9-oktober-2026).

### Zeitfenster am Vertrag eintragen

**Verbrauch → Strom → „⚙️ Verträge verwalten“**, den Vertrag des Zählers
bearbeiten, dessen Viertelstunden du hast — etwa den der Wärmepumpe. In der
Feldgruppe **„§ 14a Modul 3: zeitvariables Netzentgelt“** (Stufe „Experte“):

1. **„Standardtarif (ct/kWh)“** — das Netzentgelt je kWh außerhalb der
   Zeitfenster.
2. **„+ Zeitfenster“** je Zeile im Preisblatt: die **Quartale** (Q1 = Januar
   bis März … Q4 = Oktober bis Dezember), **„Von“**, **„Bis (HH:MM, auch
   24:00)“** und **„ct/kWh im Fenster“**.
3. Speichern.

Ein Fenster über Mitternacht sind zwei Fenster: 22:00 bis 24:00 und 00:00 bis
06:00. Höchstens 24 Fenster; überschneiden sich zwei, gilt das erste. Die App
rechnet mit den Beträgen, die du einträgst — ob netto aus dem Preisblatt oder
mit Umsatzsteuer, entscheidest du. Damit Modul 3 wie die übrigen Hebel der
Seite mit Umsatzsteuer rechnet, trag die Beträge mit Umsatzsteuer ein, so wie
sie auf der Rechnung ankommen; im Beispielhaushalt stehen sie so. Sind
Standardtarif und alle Fenster leer, entfällt Modul 3 für diesen Vertrag wieder.

Beispiel, wie im Beispielhaushalt (§ 9; erfundene Preise):

| Quartale | Von | Bis | ct/kWh |
|---|---|---|---|
| Standardtarif | | | 8,1 |
| Q1, Q2, Q3, Q4 | 00:00 | 06:00 | 2,4 |
| Q1, Q4 | 17:00 | 20:00 | 11,9 |

### Was die Karte zeigt

Die Karte **„§ 14a Modul 3 — {Zähler}“** erscheint für jeden Stromzähler mit
Viertelstunden, dessen Vertrag Zeitfenster hat:

```text
Netzentgelt einer Viertelstunde = ct des ersten Fensters, das Quartal und Uhrzeit trifft,
                                  sonst der Standardtarif
Im Niedrigtarif / Hochtarif / Standardtarif = kWh mit Netzentgelt unter / über / gleich Standard
Ersparnis gegen Standard in €   = Σ kWh × (Standardtarif − Netzentgelt der Viertelstunde) ÷ 100
```

Je Monat gilt der Vertrag, der am Monatsersten läuft. Verbrauch im Hochtarif
senkt die Ersparnis; sie kann auch negativ werden. Was mehr Verbrauch im
Niedrigtarif zusätzlich brächte, steht unter „Dein Hebel“ (§ 4); ohne
Börsenpreise färben die Zeitfenster die Ampel.

**Nur hier.** Die Zeitfenster ändern die Kosten des Vertrags nicht: Saldo,
Prognose und Rechnungsprüfung rechnen weiter mit dem Arbeitspreis, den du
einträgst. Die Ersparnis steht allein auf dieser Seite.

---

## 7. Dynamik-Check mit eigenem Lastgang

Unter **Kosten & Verträge → Wechsel prüfen** (Strom) rechnet der **„Rückblick
auf echte Monate“** ein Angebot mit dem Häkchen „Dynamischer Tarif (Börsenpreis
je Monat)“ bisher mit dem Monatsmittel der Börse
([Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310)).
Seit v3.3.0 nimmt er für Monate mit genug Viertelstunden deinen eigenen
Lastgang:

```text
Börsenpreis des Monats = Σ (kWh × Preis) ÷ Σ kWh      wenn mindestens 90 % der Viertelstunden
                                                      des Monats Verbrauch und Preis haben
                       = Monatsmittel                 sonst
Arbeitspreis           = Börsenpreis × (1 + USt) + Aufschlag
```

- Der Lastgang muss vom **Zähler** stammen, den du vergleichst — meist dem
  Hausanschluss. Viertelstunden eines Subzählers zählen dort nicht.
- Unter dem Angebot steht dann „Börsenpreis mit deinem Lastgang je
  Viertelstunde in {N} Monaten, sonst Monatsmittel.“
- Für die übrigen Monate braucht es wie bisher die Monatsmittel der Karte
  „Börsenstrompreise“.
- Die Rangliste für den Wechsel nach vorn rechnet weiter mit Monatsmitteln: Für
  künftige Monate gibt es keinen Lastgang.

---

## 8. Viertelstundenreihen verwalten

Die Karte **„Viertelstundenreihen“** unten auf der Seite (Stufe „Experte“)
listet jede Reihe: Verbrauchsart · Zähler-ID bzw. „Börsenpreise DE-LU“, Jahr,
Abdeckung (Anteil der Viertelstunden mit Wert) und Quelle — „Datei“, „Home
Assistant“ oder „SMARD“. Mit 🗑️ **„Reihe löschen“** und der Rückfrage „Diese
Reihe löschen? Zählerstände und Tageswerte bleiben.“ verschwinden nur die
Viertelstunden. Neu einlesen oder neu laden legt die Reihe wieder an.

**Ablage.** Je Reihe und Jahr eine Datei:

```text
data/intervals.json                                  Verzeichnis aller Reihen
data/intervals/e-strom-m_strom_waermepumpe-2025.json Verbrauch: e-<Verbrauchsart>-<Zähler-ID>-<Jahr>
data/intervals/p-de-lu-2025.json                     Börsenpreise DE-LU: p-de-lu-<Jahr>
```

Jede Reihe hat einen Platz je Viertelstunde des Jahres; Platz i beginnt
i × 15 Minuten nach dem 1. Januar, 00:00 Uhr Ortszeit. Leere Plätze sind
`null`. Verbrauch steht in kWh je Viertelstunde, Preise in ct/kWh. Es gilt nur,
was im Verzeichnis steht.

**Datenmenge.** Eine volle Reihe für ein ganzes Jahr belegt rund 250 KB (die
Datei wird kompakt geschrieben). Im Beispielhaushalt sind es acht Reihen für
2025, zusammen rund 1,5 MB; beim Laden kommen die letzten zwei bis drei Monate
bis gestern dazu (§ 9).

**Backup.** Das Backup enthält alle Reihen, auch ein Backup ohne Belege. Bringt
es ein Verzeichnis der Reihen mit, fallen beim Einspielen Reihen weg, die darin
nicht stehen — nach einem Wechsel des Beispielhaushalts bleiben keine Reihen
des vorigen liegen.

---

## 9. Zum Ansehen: der Beispielhaushalt

Der Beispielhaushalt **„Eigenheim mit Wärmepumpe und PV“** hat für 2025
Viertelstunden: den Haushalt (Netzbezug), die gesteuerte Wärmepumpe (sie läuft
bevorzugt zwischen 0 und 6 Uhr und meidet 17 bis 20 Uhr), die Wallbox aus ihren
Ladevorgängen (seit v3.6.0 mit Steckzeiten: im Winterhalbjahr abends angesteckt
bis zum nächsten Morgen, im Sommerhalbjahr mittags bis zum späten Nachmittag;
geladen wird gleich nach dem Anstecken), seit v3.5.0 auch Erzeugung und Einspeisung der PV-Anlage und den
Speicher — so, dass jede Viertelstunde für sich aufgeht (Erzeugung = selbst
genutzt + eingespeist + geladen; der Speicher lädt vom Überschuss und entlädt
abends ins Haus). Dazu die echten Börsenpreise 2025 je Viertelstunde von SMARD
und am Vertrag der Wärmepumpe die Modul-3-Fenster aus § 6. Beim Laden schreibt
die App die letzten zwei bis drei Monate bis gestern fort — Tag für Tag aus
demselben Kalendertag des Vorjahres, auch die Börsenpreise; das zeigt der
Tagesfilm. Der Verbrauch ist erfunden.

So lädst du ihn: Einstellungen → Allgemein → **„Einrichtungsassistent
starten“**, „Eigenheim mit Wärmepumpe und PV“, eine Stufe wählen, am Ende „Mit
Beispieldaten ansehen“
([Beispielhaushalte](anwendungsfaelle.md#beispielhaushalte-zum-ansehen-v320)).
Das ersetzt alle Daten der Installation; vorher legt die App einen Snapshot an.
Dann **Auswertungen → Flexibilität**, Jahr 2025.

**Dein Hebel.** Die Ergebniskarte — und die Kachel auf der Übersicht — zeigt
**91 €** im Jahr mit dem Vertrag, wie er ist, mit einem dynamischen Tarif bis
zu **251 €**, beides inkl. USt (Börsenpreis mit den 19 % des Angebots „Strom
Dynamisch“):

```text
Gerät                          Preissignal                              verschoben   im Jahr
E-Auto (Wallbox)               Börsenpreis, mit dynamischem Tarif        2.316 kWh     140 €
Wärmepumpe                     Börsenpreis, mit dynamischem Tarif          944 kWh      52 €
Wärmepumpe                     Netzentgelt (Modul 3), mit deinem Tarif     878 kWh      40 €
Spülmaschine (Beispielwert)    Sonnenstrom, mit deinem Tarif               105 kWh      23 €
Speicher                       Börsenpreis, mit dynamischem Tarif          327 kWh      19 €
Waschmaschine (Beispielwert)   Sonnenstrom, mit deinem Tarif                78 kWh      17 €
Spülmaschine (Beispielwert)    Börsenpreis, mit dynamischem Tarif          261 kWh      16 €
Waschmaschine (Beispielwert)   Börsenpreis, mit dynamischem Tarif          188 kWh      12 €
E-Auto (Wallbox)               Sonnenstrom, mit deinem Tarif                53 kWh      11 €

mit deinem Tarif            40 € (Wärmepumpe, Modul 3) + 23 € + 17 € (Sonne) + 11 € (Auto, Sonne)  ≈ 91 €
größter Hebel je Gerät     140 € (Auto) + 52 € (Wärmepumpe) + 23 € + 19 € (Speicher) + 17 €      ≈ 251 €
```

Das Auto rechnet mit seinen **Steckzeiten aus evcc** (153 Ladevorgänge 2025,
alle Ladung in einem Ladefenster). Mit einem dynamischen Tarif brächte es am
meisten, im Winterhalbjahr nachts geladen statt gleich nach dem Anstecken am
Abend, im Sommerhalbjahr in den günstigsten Mittagsstunden. Mit Sonnenstrom
bringt es nur 11 €: Im Sommer lädt es mittags ohnehin, im Winter ist es mittags
nicht da. Unter der Annahme „ganzer Ladetag“ — so rechnete die Seite bis zu
den Steckzeiten — wären es 65 € mit Sonnenstrom und 154 € mit dynamischem Tarif
gewesen: Die Annahme überschätzt, was ein Dienstwagen kann, der werktags
unterwegs ist.

Der größte Hebel mit dem Vertrag, wie er ist, ist deshalb die Wärmepumpe im
Niedrigtarif. Die Sätze dazu lauten im Winterhalbjahr etwa „Lade das Auto
02:00–05:00 Uhr: bis zu 140 € im Jahr.“ (mit dynamischem Tarif, Steckzeiten
aus evcc) und „Lass die Wärmepumpe mehr im Niedrigtarif laufen, 00:00–06:00
Uhr: bis zu 40 € im Jahr.“ Die Ampel zeigt 2025 sonntags 12 bis 15 Uhr als
günstigstes Fenster der Woche, im Sommerhalbjahr sogar mit Preisen unter null;
teuer ist es abends.

**Sonnenstrom.** 3.570 von 9.198 kWh selbst genutzt (39 %); mit den Hebeln für
Sonnenstrom rund 236 kWh mehr (41 %), 51 € im Jahr.

**Nachgerechnet** (Stufe „Experte“):

```text
Zähler                    mit Preis  günstig  negativ   eigener Ø  Börse Ø   Profilfaktor  Vorteil
Wärmepumpe (steuerbar)    3.891 kWh    55 %   112 kWh    9,60 ct   10,08 ct      0,95      18,78 €
Wallbox (steuerbar)       2.618 kWh    48 %   469 kWh    9,41 ct    9,07 ct      1,04      −9,01 €
Haushalt (Bezug)          2.862 kWh    40 %   303 kWh   10,79 ct    9,41 ct      1,15     −39,46 €

Speicher       1.695 kWh geladen zu Ø 8,11 ct, 1.556 kWh entladen zu Ø 11,61 ct
               Wert = (1.555,91 × 11,612 − 1.694,70 × 8,114) ÷ 100 ≈ 43,15 €
Modul 3 (Wärmepumpe)
               1.512 kWh im Niedrigtarif, 129 kWh im Hochtarif, 2.250 kWh im Standardtarif
               Ersparnis = (1.511,79 × (8,1 − 2,4) + 129,41 × (8,1 − 11,9)) ÷ 100 ≈ 81,25 €
```

Die Wärmepumpe verbraucht dank der Steuerung zu rund 5 % unter dem
Börsenmittel — 18,78 € beim Börsenanteil. Die Wallbox lädt viel bei negativen
Preisen und trotzdem über dem Mittel; der Haushalt bezieht, wie meist, abends
Strom, wenn er teurer ist — mittags deckt ihn die Sonne. Der Speicher lädt
mittags, wenn Strom an der Börse billig ist, und entlädt abends. Ein Test
rechnet jede dieser Zahlen unabhängig aus den Viertelstunden der Datei nach.

Im Rückblick unter Wechsel prüfen rechnet das Angebot „Strom Dynamisch“ für den
Zähler „Haushalt (Bezug)“ 2025 in zwölf Monaten mit dem eigenen Lastgang.

---

## 10. Im Jahresbericht

Im Dialog des Jahresberichts setzt **„Mit Seite „Flexibilität“ (Ampel, Hebel,
Zeitfenster)“** eine eigene Seite dazu — im PDF und in der Druckansicht
(`flex=1`), mit denselben Zahlen wie hier: die große Zahl, die Handlungssätze,
den typischen Tag für Winter- und Sommerhalbjahr, die besten Zeitfenster,
„Dein Hebel“ und den Sonnenstrom. Die Beträge sind wie hier inkl. USt (Fuß der
Karte, Summe und Hinweis unter „Dein Hebel“); die ct-Werte von Band und
Zeitfenstern stehen als „Börsenanteil netto, ohne USt“ da (Satz über dem Band,
Spalte „Ø Börsenpreis (netto)“, eigener Hinweis), ebenso im Thema „Flexibilität“
des Berichts der Wert des Speichers und die Beträge der Nachrechnung. Die Hinweise nennen auch die
Grundlage der Ladefenster des Autos (Steckzeiten aus evcc oder Beispielwert),
der Satz zum Auto trägt sie als Schild. Im PDF ist die Sonne ein Punkt über der
Stunde (das PDF kennt kein ☀). Ohne Börsenpreise, Zeitfenster für Modul 3 und
Erzeugung je Viertelstunde entfällt die Seite
([Jahresbericht lesen](jahresbericht-lesen.md)).

---

## 11. Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| Einladung „Was die Seite noch zeigen kann“ | Es fehlen Bausteine (§ 4); jeder Satz sagt, welcher und was er bringt. |
| „Keine Viertelstunde mit Verbrauch und Börsenpreis.“ | Verbrauch und Preise überschneiden sich nicht — etwa Preise nur bis zu einem Tag, Verbrauch erst danach. Preise noch einmal laden. |
| „SMARD war nicht erreichbar oder lieferte keine Werte — …“ | SMARD antwortet nicht oder hat für das Jahr nichts (Antwort `502`). Später noch einmal versuchen. Eine Datei mit Viertelstundenpreisen nimmt die App nicht an. |
| „Zeitfenster für Modul 3 ungültig (…): …“ | Beim Speichern des Vertrags: kein Quartal gewählt, eine Zeit nicht im Format HH:MM, „Von“ nicht vor „Bis“ (über Mitternacht: zwei Fenster), ein Preis außerhalb 0 bis 100 ct, kein Standardtarif oder mehr als 24 Fenster. In Klammern steht das Feld, etwa `windows[0]` für das erste Fenster. |
| „Zeitstempel „…“ nicht lesbar — erwartet ISO 8601 mit Zeitzone, …“ | Ein Push aus Home Assistant mit `timestamp` ohne Zeitzone oder in einem anderen Format (Antwort `400`). Die Vorlage `{{ now().isoformat() }}` passt. |
| Viertelstunden aus Home Assistant bleiben leer | Die Zeile `"timestamp"` fehlt im REST-Command (Vorlage vor v3.3.0), es läuft nur der tägliche Push, oder einer der Fälle aus § 2 „Nichts verteilt“. |
| „Reihe „…“ nicht gefunden.“ | Die Reihe ist schon gelöscht (Antwort `404`). |
| „Ungültiges Jahr: …“ | Über die API ein Jahr außerhalb 2018 bis 2100 (Antwort `400`). |
| Woche, Zeitfenster und „Dein Hebel“ fehlen | Die Stufe ist „Einsteiger“ (§ 1). |
| Die Nachrechnung je Zähler fehlt | Die Stufe ist nicht „Experte“. |
| Ein Gerät fehlt unter „Dein Hebel“ | Sein Hebel liegt unter 1 € im Jahr, oder ihm fehlen Viertelstunden bzw. die Rolle am Zähler. Für Sonnenstrom: Es hängt nicht am Hausanschluss (eigener Zähler) — oder das Auto ist laut Steckzeiten nie angesteckt, wenn die Sonne scheint. |
| Kein Satz zum Auto mit Sonnenstrom im Winter | Mit Steckzeiten aus evcc: Das Auto ist im Winterhalbjahr mittags meist nicht angesteckt. Der Satz nennt dann den Hebel mit dynamischem Tarif. |
| Die Kachel „Dein Hebel“ fehlt auf der Übersicht | Für das Vorjahr gibt es keine gemessenen Viertelstunden (Strom oder Erzeugung und Einspeisung) oder keinen Hebel ab 1 €. Die Seite „Flexibilität“ sagt, was fehlt. |
| Die Karte „Speicher nach Börsenpreis“ fehlt | Es fehlt einer der beiden Speicherzähler unter PV-Erzeugung, seine Viertelstunden oder die Börsenpreise. |
| Die Karte „§ 14a Modul 3“ fehlt | Der Vertrag, der am Monatsersten läuft, hat keine Zeitfenster, oder sie stehen an einem anderen Zähler als dem mit den Viertelstunden. |

---

## 12. Über die API

```text
GET    /api/flexibility?year=2025                         → {year, prices, meters, battery, module3, insights}
GET    /api/flexibility?year=2025&summary=1               → {year, summary}  (nur die Zahl der Karte, v3.6.0)
GET    /api/intervals                                     → {series: [...]}
DELETE /api/intervals/{id}                                → {deleted: true}
POST   /api/market-prices/sync-smard-intervals?year=2025  → {year, slots, from, to}
POST   /api/market-prices/sync-smard-intervals?year=2025&month=3  → nur März (v3.5.1)
```

Ohne `year` gilt das Vorjahr. Seit v3.6.0 trägt die Antwort zusätzlich
`insights` — Ampel (`pattern`), Hebel (`levers`, `total_eur`,
`total_real_eur`, inkl. USt mit `vat_pct`), die Zahl der Karte (`summary`),
Ladefenster des Autos (`ev_availability`, je Hebel `availability`),
Handlungssätze (`actions`), Sonnenstrom (`solar`), was fehlt (`missing`) und
die Beispielwerte (`assumptions`); alle Felder in der
[API-Referenz](../referenz/api.md#flexibilität-bewerten-v330). Mit
`summary=1` kommt nur `summary` — so holt die Übersicht ihre Kachel, ohne die
Nachrechnung je Zähler. Der Jahresbericht nimmt die Seite mit `flex=1` auf.

Viertelstunden kommen außerdem über
`POST /api/utility/{u}/meters/{id}/import-series` (Antwort neu
`intervals: {step, slots}`, bei Tageswerten `null`) und `POST /api/ingest` mit
`timestamp` (Antwort neu `intervals`, die Zahl der berührten Viertelstunden).
Ein Stapel verarbeitet Einträge mit Zeitstempel je Zähler in zeitlicher
Reihenfolge. Am Stromvertrag heißt das Feld `grid_tou`:

```json
{ "grid_tou": { "standard_ct": 8.1,
                "windows": [ { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 },
                             { "quarters": [1, 4], "from": "17:00", "to": "20:00", "ct": 11.9 } ] } }
```

Alle Felder und Fehlercodes: [API-Referenz](../referenz/api.md); die Ablage im
[Datenmodell](../referenz/datenmodell.md).

---

[← Kompendium-Index](../README.md)
