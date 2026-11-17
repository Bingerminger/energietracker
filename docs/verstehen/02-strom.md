# Strom

**Deutsch** · [English](../en/verstehen/02-strom.md)

[← Gas](01-gas.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in kWh) |
| Abrechnungseinheit | kWh |
| Umrechnung | keine (bereits kWh) |
| HGT-relevant | **nein** |
| Farbe | Mint-Grün |

## Fachlicher Hintergrund

Strom wird direkt in kWh gemessen — keine Umrechnung. Anders als Gas
hängt der Stromverbrauch **nicht** systematisch von der Außentemperatur
ab (Ausnahmen: Wärmepumpe, Klimaanlage, elektrische Zusatzheizung — die
würden eine HGT-Kopplung erzeugen, hier aber bewusst nicht modelliert,
weil sie haushaltsindividuell ist).

## Was Energietracker damit macht

- **Monatsverbrauch** durch lineare Interpolation.
- **Kein HGT, keine Heizsignatur-Regression.** Die Analyse zeigt
  stattdessen das **Saisonprofil** (Monatsmittel) und Trends.
- **Prognose**: reines Saisonprofil — Regression entfällt
  (siehe [Grundlagen §6](00-overview.md)).
- **Grundlast**: Ein konstanter Sockel (Kühlschrank, Standby, Router)
  plus variable Spitzen. Auffällige Sockelanstiege erkennt die
  Anomalie-/Trendregel.

## Verträge

Wie Gas: Arbeitspreis (ct/kWh), Grundpreis (€/Monat), Abschläge, Boni.
Schattenverträge erlauben „Was hätte Tarif X gekostet?" auf den echten
Verbrauch — ohne Saldo/Prognose zu verfälschen.

Rück-/Nachzahlungen und zusätzliche Abschlagszahlungen werden als
**[Sonderzahlungen](10-sonderzahlungen.md)** (F1003) erfasst und gehen
in den Saldo ein.

## Hoch- und Niedertarif: ein Vertrag für eine Zählergruppe *(v3.1.0)*

Ein Doppeltarifzähler hat zwei Zählwerke — Hochtarif (HT) und Niedertarif
(NT) —, abgerechnet wird aber **ein** Vertrag mit einem Grundpreis und zwei
Arbeitspreisen. Seit v3.1.0 bildet die App das so ab:

1. Beide Zählwerke als eigene Zähler anlegen und unter Haushalt → Zähler **„Zu Gruppe
   zusammenfassen“** zu einer Gruppe bündeln
   ([Meter-Topologie](13-meter-topologie.md)).
2. Im Vertragsdialog unter **„Zähler“** die Gruppe wählen — sie steht unter
   „Zählergruppen (ein Vertrag für alle)“.
3. Unter **„Arbeitspreis je Zähler (z. B. HT/NT)“** je Zählwerk seinen
   Arbeitspreis eintragen. Ein leeres Feld nimmt den Arbeitspreis oben.

Jedes Zählwerk rechnet seinen Verbrauch zu seinem Preis; Grundpreis,
Abschläge und Boni zählen einmal.

```text
Beispiel: HT 2.000 kWh × 30 ct + NT 1.000 kWh × 22 ct + 12 × 12 € Grundpreis
        = 600 € + 220 € + 144 € = 964 € im Jahr
```

Dasselbe geht für Gas und Fernwärme, etwa wenn ein Vertrag zwei Zähler
umfasst. Saldo, Prognose, Wechselentscheidung und Rechnungsprüfung gibt es für
die ganze Gruppe; Einzelheiten unter
[Meter-Topologie → Gruppenvertrag](13-meter-topologie.md#gruppenvertrag-v310).

## Steuerbare Verbraucher *(v3.1.0)*

Wärmepumpen, Wallboxen, Klimaanlagen und Batteriespeicher mit mehr als 4,2 kW,
die seit dem 01.01.2024 in Betrieb gehen, gelten als **steuerbare
Verbrauchseinrichtungen** nach § 14a EnWG: Der Netzbetreiber darf ihre Leistung
bei einem Engpass vorübergehend drosseln — auf mindestens 4,2 kW, nicht auf
null —, dafür sinkt das Netzentgelt. Drei Module:

| Modul | Was es ist | In der App |
|---|---|---|
| **1** | ein fester Betrag im Jahr, um den das Netzentgelt sinkt | im Stromvertrag die Liste **„Reduziertes Netzentgelt (§ 14a EnWG, Modul 1)“**: ab wann und wie viel im Jahr. Die App zieht den Betrag tagesgenau von den festen Kosten ab — 120 € im Jahr sind 10 € je Monat — und weist ihn je Monat aus |
| **2** | ein eigener Zähler für Wärmepumpe oder Wallbox; dessen Netz-Arbeitspreis sinkt auf 40 % | ein eigener Stromzähler mit der Rolle „Wärmepumpe (Heizstrom)“ bzw. „Wallbox“ und ein eigener Vertrag mit dem niedrigeren Arbeitspreis. Hängt er hinter dem Haushaltszähler, als Subzähler ([Meter-Topologie](13-meter-topologie.md#modul-2-ein-eigener-zähler-mit-eigenem-vertrag-v310)) |
| **3** | zeitvariable Netzentgelte zusätzlich zu Modul 1, je Viertelstunde | *(v3.3.0)* im Stromvertrag (Stufe „Experte“) die Zeitfenster aus dem Preisblatt des Netzbetreibers: Standard-Netzentgelt und Fenster mit eigenem Preis je Quartal. Mit Viertelstundenwerten rechnet [Flexibilität bewerten](../anleitungen/flexibilitaet.md#6--14a-modul-3-zeitvariables-netzentgelt) die Ersparnis gegen das Standard-Netzentgelt aus; Kosten, Saldo und Rechnungsprüfung rechnen weiter ohne sie |

Im Tarifvergleich und in der Wechselentscheidung bleibt die Reduzierung außen
vor: Sie gilt für jeden Lieferanten gleich.

## Dynamische Tarife *(v3.1.0)*

Bei einem **dynamischen Tarif** folgt der Arbeitspreis dem Börsenpreis
(§ 41a EnWG). Seit 2025 muss jeder Lieferant einen anbieten; abgerechnet wird
er je Viertelstunde, wofür es ein intelligentes Messsystem braucht.

**Lohnt sich das? — der Dynamik-Check.** Unter **Kosten & Verträge → Wechsel
prüfen** (Strom) steht die Karte **„Börsenstrompreise“**. „Von SMARD laden“
holt die Monatsmittel der Großhandelspreise Deutschland/Luxemburg von SMARD,
der Plattform der Bundesnetzagentur — nur auf Knopfdruck; „Datei importieren“
liest einen SMARD-Download oder eine eigene Liste. Dann ein Angebot mit dem
Häkchen **„Dynamischer Tarif (Börsenpreis je Monat)“** anlegen, dazu den
**Aufschlag je kWh** (alles außer dem Börsenpreis: Netzentgelt, Umlagen,
Steuern, Marge — er steht im Preisblatt), den Grundpreis und die
Umsatzsteuer auf den Börsenpreis (Standard 19 %).

```text
Arbeitspreis je Monat = Börsenpreis (Monatsmittel) × (1 + USt) + Aufschlag
Beispiel: 3.000 kWh, Börse 10 ct, USt 19 %, Aufschlag 15 ct, Grundpreis 10 €
          → 3.000 × 26,9 ct + 12 × 10 € = 807 € + 120 € = 927 € im Jahr
```

Für künftige Monate ohne Börsenwert nimmt die App denselben Monat des Vorjahres
an und sagt, für wie viele Monate. Ohne Börsenpreise erscheint das Angebot
nicht, sondern ein Hinweis.

**Was der Check nicht kann.** Ohne Viertelstundenwerte rechnet er mit dem
Monatsmittel, als verteile sich dein Verbrauch gleichmäßig über den Tag.
Abends, wenn viele kochen und heizen, ist Strom meist teurer als im Mittel; wer
dann viel verbraucht, zahlt mit einem dynamischen Tarif mehr, als der Check
zeigt. Wer Verbrauch in günstige Stunden schieben kann — Wallbox, Wärmepumpe,
Speicher —, zahlt weniger. Ein Lastprofil (das Standardlastprofil H25 des BDEW)
nutzt die App bewusst nicht: Es ist ohne Lizenz veröffentlicht. *(v3.3.0)*
Liegen für einen Monat mindestens 90 % der Viertelstunden mit deinem Verbrauch
und dem Börsenpreis vor, nimmt der Rückblick stattdessen deinen eigenen
Lastgang ([Dynamik-Check mit eigenem Lastgang](../anleitungen/flexibilitaet.md#7-dynamik-check-mit-eigenem-lastgang));
die Rangliste für den Wechsel rechnet weiter mit dem Monatsmittel.

**Einen dynamischen Vertrag führen.** Hast du einen, trägst du ihn als echten
Vertrag mit **Preisen je Monat** ein: auf der Vertragskarte **„Monatspreise
importieren“** mit einer Datei `Monat;ct/kWh[;Grundpreis]` — etwa
`01.2026;31,42;9,90` je Zeile, aus der Rechnung oder dem Kundenportal. Die
Vorschau nennt die Monate; „Übernehmen“ trägt je Monat einen Arbeitspreis ab
dem Ersten ein. Saldo und Rechnungsprüfung rechnen dann mit diesen Preisen.
Das Häkchen „Dynamischer Tarif“ gibt es nur für Angebote, nicht für echte
Verträge.

## Wallbox und Dienstwagen *(v3.1.0)*

Ein Stromzähler mit der Rolle **„Wallbox“** zählt wie jeder andere — als
Subzähler hinter dem Haushaltszähler zieht die App ihn dort ab. Zusätzlich
zeigt seine Verbrauchsansicht die Karte **„Ladestrom-Nachweis
(Dienstwagen)“**: die geladene Menge je Monat mit Preis, als CSV und PDF für
den Arbeitgeber. Schritt für Schritt:
[Ladestrom für den Dienstwagen nachweisen](../anleitungen/ladestrom-nachweis.md).

## Wallbox neu im Verlauf *(v3.13.0)*

**Eine neue Wallbox kennt ihre ersten Winter nicht.** Bis v3.12 nahm die Prognose
für jeden Kalendermonat ohne Vorgeschichte das Mittel aller gemessenen Monate, nach
einem Sommer also zu wenig für den Winter.

<!-- doc-claim: wallbox-arrival -->
**Seit v3.13.0 rechnet die Prognose der Wallbox solche Monate nach einer Annahme:**
Ein E-Auto braucht je Grad unter 20 °C rund 1 % mehr Strom, gefahren wird gleich viel.
Die Grade kommen aus dem Klimanormal (sonst aus deinen eigenen Tagestemperaturen), die
Höhe aus den gemessenen Monaten. Quelle: Lee, Song, Lim, Park (2024), Energy
Conversion and Management 306, 118289: bei −15 °C 35,4 % mehr Energie als bei 24 °C,
am sparsamsten zwischen 20 und 30 °C. Kennt der Zähler einen Monat nur verteilt
(seltene Ablesungen) und gibt es Ladevorgänge aus evcc, kommt der Monat aus den
Ladevorgängen. Nötig sind drei gemessene Monate und Temperaturen für jeden
Kalendermonat (ohne sie bleibt es beim Mittel); die Prognose nennt die Monate, die so
entstehen.

**Der Zähler vor der Wallbox rechnet weiter mit den Monaten davor**, in denen das Auto
fehlt. Gebaut ist, dass diese Monate den Netzbezug der Wallbox desselben Kalendermonats
bekommen (ohne den Sonnenanteil der Ladevorgänge aus evcc); am Prüfhaushalt hat das
seine Prüfung nicht bestanden. Ein Hinweis sagt es und schlägt eine Zäsur am Tag des
Einbaus vor ([Glossar: Zäsur](09-glossar.md)). In der Kaskade (vorn die Wärmepumpe,
dahinter der Haushalt) gilt der Hinweis auch für den Haushaltszähler: Die Wallbox
hängt dort am vorderen Zähler, steckt aber im Haushalt. Die Hinweise stehen zwei Jahre
ab dem Einbau, also ab dem Einbaudatum am Wallbox-Zähler.

## Wärmepumpe nach dem Wetter *(v3.13.0, noch nicht freigeschaltet)*

**Der Strom einer Wärmepumpe folgt dem Wetter, die Prognose rechnet ihn bisher wie
Haushaltsstrom:** mit dem Saisonprofil der gemessenen Jahre. Nach einem milden
Winter liegt sie deshalb zu niedrig, nach einem kalten zu hoch.

**Welche Zähler dem Wetter folgen**, entscheidet eine Stelle (`UtilityWeather`), nur an
Feldern, nie am Namen:

| Zähler | folgt dem Wetter |
|---|---|
| Rolle **Wärmepumpe**, eigener Zähler (neben oder hinter dem Haushalt) | ja |
| **Kaskade:** vorderer Zähler mit der Rolle Wärmepumpe, Haushalt dahinter | ja, gerechnet wird der **Rest** (die Menge, die sein Vertrag rechnet), nicht der ganze Zähler |
| **Hauptstromzähler**, wenn die Heizung mit Strom läuft (Haushalt › Gebäude) und es keinen eigenen Wärmepumpenzähler gibt | ja; bei mehreren Zählern ohne Elternzähler keiner (die App rät nicht) |
| **Gruppe** (etwa HT/NT einer Wärmepumpe) | ja, wenn alle Mitglieder es tun |

**Gebaut ist derselbe Weg wie beim Gas:** Heizmodell aus Gradtagen und Tagen (auch über
Ablesungsabstände), Klimanormal statt Wetterglück, Gütesperre, Band. Dazu wählt die
Rechnung die Heizgrenze selbst, wenn der Zähler keine eigene hat, und nimmt für
Prognose und Saldo dieselbe Kalibrierung am jüngsten Niveau.

**Anders als beim Gas** rechnet das Klimanormal der Wärmepumpe mit dem **Trend der
Temperatur**: Das Mittel der dreißig Jahre lag an zehn Wetterstationen des DWD die
Gradtage des Folgejahrs **9 bis 12 % zu hoch**, mit dem Trend 2 bis 3 %
([Messung](../entwicklung/messung-temperaturtrend.md)). Die
**HT/NT-Gruppe** einer Wärmepumpe rechnet **ein** Modell und verteilt es nach den
Anteilen ihrer Zählwerke je Kalendermonat; Gruppe = HT + NT bleibt.

**Angezeigt wird es erst, wenn es seine Prüfung besteht.** Gemessen wird an einem
Prüfhaushalt mit eigener Physik, der nicht aus dem Modell der App stammt, gegen vorab
festgelegte Schwellen (Werkzeug `tools/rueckrechnung.php`, [Tests](../entwicklung/tests.md)).
In v3.13.0 schafft die Rechnung sie noch nicht; Prognose, Band und „Erwartet bei
Abrechnung“ bleiben beim Saisonprofil, und **ein Hinweis sagt es** in der Prognose, an
der Saldo-Karte und in der Werkstatt.

**Mindestens nötig**, wenn sie freigeschaltet ist: 8 gemessene Monate oder 8 Abstände
zwischen Ablesungen ab 20 Tagen, darunter ein Winter- und ein Sommermonat, dazu ein
Standort für das Klimanormal. Darunter bleibt das Saisonprofil, der Hinweis nennt, was
fehlt (zeigen statt raten).

**Bekannte Grenze:** Das Modell ist linear. In einem sehr kalten Winter sinkt die
Arbeitszahl, und ein Heizstab springt an; dann liegt es eher zu niedrig.

## Typische Stolpersteine

- **Erwartung einer Temperaturkorrelation.** Bei reinem Haushaltsstrom
  ist eine schwache/keine HGT-Kopplung normal — kein Fehler.
- **Wärmepumpen-Strom** vermischt Heiz- und Haushaltsstrom. Wer das
  trennen will, legt einen zweiten Zähler mit der Rolle „Wärmepumpe
  (Heizstrom)“ an, meist als Subzähler. Mit einem Wärmemengenzähler dazu
  rechnet die App die Jahresarbeitszahl
  ([Heizwärme](15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)).
- **Zwei Verträge für ein HT/NT-Paar.** Ein Zählwerk mit eigenem Vertrag und
  zugleich ein Gruppenvertrag im selben Zeitraum lehnt die App ab — sonst
  zählten Grundpreis und Verbrauch doppelt. Den alten Vertrag vorher beenden.

[← Gas](01-gas.md) · [Wasser →](03-wasser.md)
