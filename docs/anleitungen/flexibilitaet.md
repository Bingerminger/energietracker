# Flexibilität bewerten

**Deutsch** · [English](../en/anleitungen/flexibilitaet.md)

[← Kompendium-Index](../README.md)

Die Wärmepumpe läuft nachts, das Auto lädt mittags, der Speicher gibt abends
ab: Wer Verbrauch verschiebt, will wissen, ob es sich gelohnt hat. Seit
**v3.3.0** rechnet der Energietracker das je Viertelstunde nach — mit deinem
Verbrauch und dem Börsenstrompreis derselben Viertelstunde. Die Seite
**Auswertungen → Flexibilität** zeigt je Stromzähler, wie viel in günstigen
Viertelstunden lief und welchen Börsenpreis du im Schnitt gezahlt hättest, dazu
was der Speicher und das zeitvariable Netzentgelt nach § 14a Modul 3 gebracht
haben.

> **Der Energietracker steuert nichts — er rechnet nach.** Gesteuert wird
> woanders: evcc lädt das Auto, Home Assistant schaltet die Wärmepumpe, der
> Speicher regelt sich selbst. Wie das zusammenspielt, zeigen die
> [Anwendungsfälle](anwendungsfaelle.md#g--steuern-mit-evcc-oder-home-assistant--nachrechnen-im-energietracker).

Gerechnet wird **nur der Börsenanteil** des Strompreises — ohne Steuern,
Umlagen, Netzentgelt und den Aufschlag des Lieferanten. Das ist der Teil, der
davon abhängt, wann du verbrauchst (Ausnahme: das zeitvariable Netzentgelt,
§ 6). Bares Geld wird daraus nur mit einem dynamischen Tarif, der je
Viertelstunde abrechnet; mit festem Arbeitspreis zeigt die Seite, was ein
solcher Tarif mit deinem Verbrauchsverhalten gebracht hätte.

---

## 1. Was du brauchst

- Die Stufe **„Experte“** — erst dann steht „Flexibilität“ unter
  Auswertungen ([Nutzungsstufen](../einstieg/einrichtung.md#3-nutzungsstufen)).
- **Verbrauch je Viertelstunde** für mindestens einen Stromzähler (§ 2). Am
  meisten sagt er bei einem eigenen Zähler für Wärmepumpe oder Wallbox.
- **Börsenpreise je Viertelstunde** für dasselbe Jahr, von SMARD (§ 3).
- Für den **Speicher** zwei Zähler unter PV-Erzeugung mit den Rollen
  „Speicher – Ladung“ und „Speicher – Entladung“, beide mit Viertelstunden
  (§ 5).
- Für **§ 14a Modul 3** die Zeitfenster am Stromvertrag (§ 6). Börsenpreise
  braucht Modul 3 nicht.

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

Oben auf der Seite: **„Börsenpreise {Jahr} von SMARD laden“**. Der Server holt
die Day-Ahead-Preise für Deutschland/Luxemburg (DE-LU) des gewählten Jahres
von SMARD, der Strommarktplattform der Bundesnetzagentur, Woche für Woche, und
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
  unter den Zahlen.

---

## 4. Die Seite Karte für Karte

**Auswertungen → Flexibilität**, oben das Jahr: das laufende und die drei
davor, vorgewählt ist das Vorjahr. Fehlt etwas, sagt ein Hinweis, was:

- „Für {Jahr} fehlen die Börsenpreise je Viertelstunde — oben von SMARD
  laden.“ Ohne Preise zeigt die Seite keine Zählerkarten.
- „Für {Jahr} gibt es noch keinen Verbrauch je Viertelstunde. …“ — § 2.

### Je Stromzähler

Eine Karte je Stromzähler mit Viertelstunden im gewählten Jahr. Zähler mit der
Rolle „Wärmepumpe (Heizstrom)“ oder „Wallbox“ tragen das Schild **„steuerbar“**
und stehen oben; die übrigen — etwa der Hausanschluss — folgen zum Vergleich.

| Kennzahl | Bedeutung |
|---|---|
| **Verbrauch mit Preis** | kWh in Viertelstunden, für die es Verbrauch und Börsenpreis gibt — nur über sie wird gerechnet |
| **In günstigen Viertelstunden** | Anteil davon in Viertelstunden, deren Preis **unter dem Mittel ihres Monats** liegt |
| **Bei negativem Preis** | kWh in Viertelstunden mit einem Börsenpreis unter null |
| **Eigener Ø / Börse Ø** | dein Börsenpreis je kWh, nach Verbrauch gewichtet, gegen das Börsenmittel |
| **Profilfaktor** ⓘ | eigener Ø geteilt durch Börse Ø |
| **Vorteil (Börsenanteil)** | was dein Verbrauchsprofil gegenüber dem Börsenmittel gespart (positiv) oder gekostet (negativ) hat |

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

Darunter ein Satz: „Günstiger als der Durchschnitt: … gespart beim
Börsenanteil“ oder „Teurer als der Durchschnitt: … mehr beim Börsenanteil“, mit
dem Profilfaktor.

**„Je Monat“** klappt eine Tabelle auf: Monat, kWh (aller Viertelstunden mit
Verbrauch), günstig, negativ, eigener Ø, Börse Ø, Vorteil und **Abdeckung** —
der Anteil der Viertelstunden des Monats mit Verbrauch und Preis.

### Speicher und Modul 3

Die Karten „Speicher nach Börsenpreis“ (§ 5) und „§ 14a Modul 3 — {Zähler}“
(§ 6) erscheinen, wenn es die Daten dafür gibt.

### Viertelstundenreihen

Unten die Liste aller Reihen, aller Jahre (§ 8).

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
| **Wert der Verschiebung** | was das Verschieben über den Tag zum Börsenpreis wert war |

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
die Karte „Speicher {Jahr}“ unter PV-Erzeugung.

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
mit Umsatzsteuer, entscheidest du. Sind Standardtarif und alle Fenster leer,
entfällt Modul 3 für diesen Vertrag wieder.

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
senkt die Ersparnis; sie kann auch negativ werden.

**Nur hier.** Die Zeitfenster ändern die Kosten des Vertrags nicht: Saldo,
Prognose und Rechnungsprüfung rechnen weiter mit dem Arbeitspreis, den du
einträgst. Die Ersparnis steht allein auf dieser Karte.

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

Die Karte **„Viertelstundenreihen“** unten auf der Seite listet jede Reihe:
Verbrauchsart · Zähler-ID bzw. „Börsenpreise DE-LU“, Jahr, Abdeckung (Anteil
der Viertelstunden mit Wert) und Quelle — „Datei“, „Home Assistant“ oder
„SMARD“. Mit 🗑️ **„Reihe löschen“** und der Rückfrage „Diese Reihe löschen?
Zählerstände und Tageswerte bleiben.“ verschwinden nur die Viertelstunden. Neu
einlesen oder neu laden legt die Reihe wieder an.

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
Ladevorgängen, seit v3.5.0 auch Erzeugung und Einspeisung der PV-Anlage und den
Speicher — so, dass jede Viertelstunde für sich aufgeht (Erzeugung = selbst
genutzt + eingespeist + geladen; der Speicher lädt vom Überschuss und entlädt
abends ins Haus). Dazu die echten Börsenpreise 2025 je Viertelstunde von SMARD
und am Vertrag der Wärmepumpe die Modul-3-Fenster aus § 6. Beim Laden schreibt
die App die letzten zwei bis drei Monate bis gestern fort — Tag für Tag aus
demselben Kalendertag des Vorjahres, auch die Börsenpreise; das zeigt der
Tagesfilm. Der Verbrauch ist erfunden.

So lädst du ihn: Einstellungen → Allgemein → **„Einrichtungsassistent
starten“**, „Eigenheim mit Wärmepumpe und PV“, bei der Stufe **„Experte“**
wählen, am Ende „Mit Beispieldaten ansehen“
([Beispielhaushalte](anwendungsfaelle.md#beispielhaushalte-zum-ansehen-v320)).
Das ersetzt alle Daten der Installation; vorher legt die App einen Snapshot an.
Dann **Auswertungen → Flexibilität**, Jahr 2025:

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
Börsenmittel — 18,78 € beim Börsenanteil. Die Wallbox lädt viel bei negativen Preisen und
trotzdem über dem Mittel; der Haushalt bezieht, wie meist, abends Strom, wenn er
teurer ist — mittags deckt ihn die Sonne. Der Speicher lädt mittags, wenn Strom
an der Börse billig ist, und entlädt abends.

Im Rückblick unter Wechsel prüfen rechnet das Angebot „Strom Dynamisch“ für den
Zähler „Haushalt (Bezug)“ 2025 in zwölf Monaten mit dem eigenen Lastgang.

---

## 10. Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| „Für {Jahr} fehlen die Börsenpreise je Viertelstunde — oben von SMARD laden.“ | Für das Jahr sind keine Preise geladen (§ 3). |
| „Für {Jahr} gibt es noch keinen Verbrauch je Viertelstunde. …“ | Kein Stromzähler hat Viertelstunden in diesem Jahr (§ 2). |
| „Keine Viertelstunde mit Verbrauch und Börsenpreis.“ | Verbrauch und Preise überschneiden sich nicht — etwa Preise nur bis zu einem Tag, Verbrauch erst danach. Preise noch einmal laden. |
| „SMARD war nicht erreichbar oder lieferte keine Werte — …“ | SMARD antwortet nicht oder hat für das Jahr nichts (Antwort `502`). Später noch einmal versuchen. Eine Datei mit Viertelstundenpreisen nimmt die App nicht an. |
| „Zeitfenster für Modul 3 ungültig (…): …“ | Beim Speichern des Vertrags: kein Quartal gewählt, eine Zeit nicht im Format HH:MM, „Von“ nicht vor „Bis“ (über Mitternacht: zwei Fenster), ein Preis außerhalb 0 bis 100 ct, kein Standardtarif oder mehr als 24 Fenster. In Klammern steht das Feld, etwa `windows[0]` für das erste Fenster. |
| „Zeitstempel „…“ nicht lesbar — erwartet ISO 8601 mit Zeitzone, …“ | Ein Push aus Home Assistant mit `timestamp` ohne Zeitzone oder in einem anderen Format (Antwort `400`). Die Vorlage `{{ now().isoformat() }}` passt. |
| Viertelstunden aus Home Assistant bleiben leer | Die Zeile `"timestamp"` fehlt im REST-Command (Vorlage vor v3.3.0), es läuft nur der tägliche Push, oder einer der Fälle aus § 2 „Nichts verteilt“. |
| „Reihe „…“ nicht gefunden.“ | Die Reihe ist schon gelöscht (Antwort `404`). |
| „Ungültiges Jahr: …“ | Über die API ein Jahr außerhalb 2018 bis 2100 (Antwort `400`). |
| „Flexibilität“ fehlt unter Auswertungen | Die Stufe ist nicht „Experte“. |
| Die Karte „Speicher nach Börsenpreis“ fehlt | Es fehlt einer der beiden Speicherzähler unter PV-Erzeugung, seine Viertelstunden oder die Börsenpreise. |
| Die Karte „§ 14a Modul 3“ fehlt | Der Vertrag, der am Monatsersten läuft, hat keine Zeitfenster, oder sie stehen an einem anderen Zähler als dem mit den Viertelstunden. |

---

## 11. Über die API

```text
GET    /api/flexibility?year=2025                         → {year, prices, meters, battery, module3}
GET    /api/intervals                                     → {series: [...]}
DELETE /api/intervals/{id}                                → {deleted: true}
POST   /api/market-prices/sync-smard-intervals?year=2025  → {year, slots, from, to}
```

Ohne `year` gilt das Vorjahr. Viertelstunden kommen außerdem über
`POST /api/utility/{u}/meters/{id}/import-series` (Antwort neu
`intervals: {step, slots}`, bei Tageswerten `null`) und `POST /api/ingest` mit
`timestamp` (Antwort neu `intervals`, die Zahl der berührten Viertelstunden).
Ein Stapel verarbeitet Einträge mit Zeitstempel je Zähler in zeitlicher
Reihenfolge. Am
Stromvertrag heißt das Feld `grid_tou`:

```json
{ "grid_tou": { "standard_ct": 8.1,
                "windows": [ { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 },
                             { "quarters": [1, 4], "from": "17:00", "to": "20:00", "ct": 11.9 } ] } }
```

Alle Felder und Fehlercodes: [API-Referenz](../referenz/api.md); die Ablage im
[Datenmodell](../referenz/datenmodell.md).

---

[← Kompendium-Index](../README.md)
