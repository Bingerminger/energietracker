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

1. Beide Zählwerke als eigene Zähler anlegen und unter ⚙️ Zähler **„Zu Gruppe
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

Wärmepumpen, Wallboxen und Batteriespeicher mit mehr als 4,2 kW gelten als
**steuerbare Verbrauchseinrichtungen** nach § 14a EnWG: Der Netzbetreiber darf
ihre Leistung bei einem Engpass vorübergehend drosseln, dafür sinkt das
Netzentgelt. Drei Module:

| Modul | Was es ist | In der App |
|---|---|---|
| **1** | ein fester Betrag im Jahr, um den das Netzentgelt sinkt | im Stromvertrag die Liste **„Reduziertes Netzentgelt (§ 14a EnWG, Modul 1)“**: ab wann und wie viel im Jahr. Die App zieht den Betrag tagesgenau von den festen Kosten ab — 120 € im Jahr sind 10 € je Monat — und weist ihn je Monat aus |
| **2** | ein eigener Zähler für Wärmepumpe oder Wallbox; dessen Netz-Arbeitspreis sinkt auf 40 % | ein eigener Stromzähler mit der Rolle „Wärmepumpe (Heizstrom)“ bzw. „Wallbox“ und ein eigener Vertrag mit dem niedrigeren Arbeitspreis. Hängt er hinter dem Haushaltszähler, als Subzähler ([Meter-Topologie](13-meter-topologie.md#modul-2-ein-eigener-zähler-mit-eigenem-vertrag-v310)) |
| **3** | zeitvariable Netzentgelte zusätzlich zu Modul 1, je Viertelstunde | nicht umgesetzt — dafür braucht es Viertelstundenwerte; die App rechnet in Tagen und Monaten |

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

**Was der Check nicht kann.** Er rechnet mit dem Monatsmittel, als verteile
sich dein Verbrauch gleichmäßig über den Tag. Abends, wenn viele kochen und
heizen, ist Strom meist teurer als im Mittel; wer dann viel verbraucht, zahlt
mit einem dynamischen Tarif mehr, als der Check zeigt. Wer Verbrauch in
günstige Stunden schieben kann — Wallbox, Wärmepumpe, Speicher —, zahlt
weniger. Ein Lastprofil (das Standardlastprofil H25 des BDEW) nutzt die App
bewusst nicht: Es ist ohne Lizenz veröffentlicht.

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
