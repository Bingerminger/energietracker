# Anwendungsbeispiele & Use-Cases

**Deutsch** · [English](../en/anleitungen/anwendungsfaelle.md)

[← Kompendium-Index](../README.md)

Acht durchgerechnete Praxisfälle, die zeigen, wie Energietracker in konkreten
Wohnsituationen eingerichtet und genutzt wird. Jeder Fall nennt die **Zähler**,
die **Einstellungen** und einen **typischen Ablauf**. Für die Grundeinrichtung
siehe zuerst [Erste Schritte](../einstieg/erste-schritte.md).

| Use-Case | Schwerpunkt | Features | Beispielhaushalt |
|---|---|---|---|
| [A — WG mit geteilten Zählern](#a--wg-mit-geteilten-zählern) | Subzähler, Kostenteilung | F1006 | — |
| [B — Smart Home / Home Assistant](#b--smart-home--home-assistant-vollausbau) | Automatischer Push | F1009 | — |
| [C — PV-Haushalt mit Wärmepumpe](#c--pv-haushalt-mit-wärmepumpe) | PV + Subzähler | F1005, F1006 | Eigenheim mit Wärmepumpe und PV |
| [D — Vermieter mit mehreren Einheiten](#d--vermieter-mit-mehreren-einheiten) | Zähler je Einheit | F1006 | — |
| [E — Wallbox und Dienstwagen](#e--wallbox-und-dienstwagen) | Ladestrom-Nachweis, § 14a EnWG | v3.1.0 | Eigenheim mit Wärmepumpe und PV |
| [F — Fernwärme oder Heizwärme?](#f--fernwärme-oder-heizwärme) | die passende Verbrauchsart | v3.1.0, F1018 | Ich wohne zur Miete · Eigentumswohnung mit Fernwärme · Eigenheim mit Wärmepumpe und PV |
| [G — Steuern mit evcc oder Home Assistant](#g--steuern-mit-evcc-oder-home-assistant--nachrechnen-im-energietracker) | nachrechnen statt steuern | F1009, F1021, F1022 | Eigenheim mit Wärmepumpe und PV |
| [H — Kaskade mit Wärmepumpe, HT/NT und PV](#h--kaskade-mit-wärmepumpe-htnt-und-pv-v390) | Zähler hintereinander, Rest-Abrechnung | F1006, F1032 | — |

## Beispielhaushalte zum Ansehen *(v3.2.0)*

Vier Haushalte gibt es fertig eingerichtet, mit erfundenen Daten. So lädst du
einen:

1. Einstellungen → Allgemein → **„Einrichtungsassistent starten“**.
2. Bei „Wer bist du?“ den Haushalt wählen: „Ich wohne zur Miete“,
   „Eigentumswohnung mit Fernwärme“, „Eigenheim mit Gas, Öl oder Pellets“ oder
   „Eigenheim mit Wärmepumpe und PV“. „Erst einmal alles ansehen“ lädt den
   Haushalt mit allen neun Verbrauchsarten.
3. Am Ende **„Mit Beispieldaten ansehen“** wählen.

Das ersetzt **alle** Daten dieser Installation. Vorher legt die App einen
Snapshot an; unter Einstellungen → Daten spielst du ihn wieder ein. Sind
Benutzer eingerichtet, darf das nur ein Verwalter.

In der [öffentlichen Demo](https://bingerminger.github.io/energietracker/)
fragt der Assistent beim ersten Aufruf von selbst, welchen Haushalt du sehen
willst. Einen anderen wählst du dort ebenso über Einstellungen → Allgemein →
Einrichtungsassistent. Mehr: [Einrichtung](../einstieg/einrichtung.md).

---

<a id="case-shared-meters"></a>
## A — WG mit geteilten Zählern

**Situation.** Vierer-WG, ein gemeinsamer Stromhauptzähler. Eine Person betreibt
einen stromhungrigen Server/Gaming-PC mit eigenem Steckdosenzähler und will
ihren Anteil sauber heraushalten.

**Einrichtung.**

1. Hauptzähler `strom` anlegen: *„Hausanschluss WG"*.
2. Zweiten Zähler `strom` anlegen: *„Arbeitszimmer (Server)"*.
3. Beim zweiten Zähler unter **⚙️ Zähler → Bearbeiten** den **Elternzähler** auf
   *„Hausanschluss WG"* setzen → er wird zum **Subzähler**.

**Was passiert.** Der Server-Subzähler wird vom Hausanschluss abgezogen. Im
Dashboard erscheint:

```
⚡ Hausanschluss WG ........ 612 kWh   (netto, ohne Server)
   ↳ Arbeitszimmer (Server)  188 kWh   (Aufschlüsselung)
Strom-Gesamtsumme ......... 612 kWh
```

So lässt sich der Server-Anteil (188 kWh) für die interne WG-Abrechnung exakt
beziffern, während die WG-Gesamtkosten (612 kWh × Tarif) korrekt ohne
Doppelzählung bleiben. Details: [Meter-Topologie](../verstehen/13-meter-topologie.md).

> **Tipp.** Den WG-Vertrag am Hauptzähler pflegen. Der Subzähler braucht keinen
> eigenen Vertrag — für die interne Umlage genügt seine kWh-Zahl.

---

<a id="case-home-assistant"></a>
## B — Smart Home / Home Assistant-Vollausbau

**Situation.** Technikaffiner Haushalt mit Home Assistant (HA), das bereits alle
Zähler digital ausliest (Strom + Gas per Lesekopf, Wasser per Impulszähler).
Niemand will mehr Werte abtippen.

**Einrichtung.**

1. In **Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung** einen
   **API-Token** erzeugen (einmalig kopieren).
2. Jedem Zähler einen **Alias** geben: `strom_haus`, `gas_haus`, `wasser_haus`.
3. In HA das fertige `rest_command`-YAML (aus den Einstellungen kopierbar)
   einfügen und eine Automatisierung bauen, die abends um 23:55 alle Zähler
   pusht.

**HA-Automatisierung (gekürzt):**

```yaml
alias: "Energie → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data: { utility: "strom",  meter: "strom_haus",  sensor_entity: "sensor.strom_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "gas",    meter: "gas_haus",    sensor_entity: "sensor.gas_total_m3" }
  - action: rest_command.energietracker_push
    data: { utility: "wasser", meter: "wasser_haus", sensor_entity: "sensor.wasser_total_m3" }
```

**Was passiert.** Jeden Abend landet ein Tageszählerstand pro Zähler im
Energietracker — **idempotent**: ein zweiter Push am selben Tag (z. B. ein
manueller Test) überschreibt den Wert, statt ein Duplikat zu erzeugen. Die
komplette Schritt-für-Schritt-Anleitung inkl. Fehlersuche steht in
[Home Assistant](home-assistant.md).

> **Sicherheit.** Der Token schützt nur den Push-Endpoint `/api/ingest`, nicht
> die App: Ohne Token nimmt der Ingest Werte ohne Kopfzeile an (nur fürs
> Heimnetz gedacht), mit Token verlangt er `Authorization: Bearer …`. Die App
> selbst schützt die optionale Anmeldung (seit v2.6.0); ist sie eingeschaltet,
> ist der Token Pflicht. Siehe [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).
> Der Token liegt serverseitig nur als Hash vor.

---

<a id="case-pv-heat-pump"></a>
## C — PV-Haushalt mit Wärmepumpe

**Situation.** Eigenheim mit PV-Anlage und Wärmepumpe. Gewünscht: Autarkiequote,
Einspeisevergütung **und** der separate Stromverbrauch der Wärmepumpe.

**Einrichtung.**

1. In **Einstellungen → Verbrauchsarten & Abrechnung → Aktive
   Verbrauchsarten** `pv_einspeisung` und `pv_erzeugung` aktivieren.
2. Zähler anlegen:
   - `strom` *„Hausanschluss"* (Netzbezug),
   - `strom` *„Wärmepumpe"* → **Subzähler** von *„Hausanschluss"*,
   - `pv_einspeisung` *„Einspeisezähler"* (EEG-Vergütung),
   - `pv_erzeugung` *„Wechselrichter"* (Gesamterzeugung).
3. Beim Einspeisezähler den vereinfachten PV-Vertrag (nur ct/kWh) hinterlegen.

**Was die App zeigt.**

- **Strom-Saldo** (`/api/strom-saldo`): Netzbezug − Einspeisung, also die reale
  Stromrichtung übers Jahr.
- **Autarkiequote & Eigenverbrauch** (`/api/pv-summary`) aus Erzeugung vs.
  Bezug.
- Der **Wärmepumpen-Subzähler** zeigt, wie viel des Hausstroms aufs Heizen
  entfällt, ohne die Strom-Gesamtsumme zu verdoppeln; mit der Rolle
  „Wärmepumpe (Heizstrom)“ zählt er in der Effizienzkennzahl.

**Seit v3.1.0 dazu:**

- **Jahresarbeitszahl.** Mit einem Wärmemengenzähler — Heizwärme, Rolle
  „Wärmemenge der Wärmepumpe“, verknüpft mit dem Wärmepumpen-Stromzähler —
  zeigen beide Zähler die Karte „Wärmepumpe {Jahr}“: etwa 9.000 kWh Wärme aus
  2.500 kWh Strom, JAZ 3,6 (erfundenes Beispiel;
  [Heizwärme §7](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)).
- **Speicher.** Lade- und Entladezähler unter PV-Erzeugung (Rollen „Speicher –
  Ladung“ und „Speicher – Entladung“) ergeben Verluste, Wirkungsgrad und
  Vollzyklen ([PV §7](../verstehen/12-pv.md#7-speicher-v310)).
- **Amortisation.** Investition und Inbetriebnahme am Wechselrichter-Zähler
  zeigen, wann sich die Anlage bezahlt gemacht hat
  ([PV §9](../verstehen/12-pv.md#9-amortisation-v310)).
- **Netzentgelt nach § 14a EnWG.** Ist die Wärmepumpe steuerbar, steht die
  Reduzierung (Modul 1) im Stromvertrag; mit einem eigenen Zähler des
  Netzbetreibers (Modul 2) bekommt der Wärmepumpen-Zähler einen eigenen
  Vertrag ([Strom](../verstehen/02-strom.md#steuerbare-verbraucher-v310)).
- **Daten aus den Portalen** von Wechselrichter und Wärmepumpe lassen sich als
  Datei einlesen ([Zeitreihen aus Portalen](daten-aus-portalen.md)).

Hintergrund: [PV](../verstehen/12-pv.md),
[Szenario Eigenheim §6a](../verstehen/08-szenario-eigenheim.md#6a-wärmepumpe-v310)
und [Meter-Topologie](../verstehen/13-meter-topologie.md). Wer die WP-Werte
ebenfalls aus HA zieht, kombiniert das mit Use-Case B (Alias
`strom_waermepumpe`, für die Wärmemenge etwa `waerme_wp` —
[Home Assistant](home-assistant.md#use-case-c--wärmepumpe-mit-wärmemengenzähler)).

**Zum Ansehen:** Beispielhaushalt „Eigenheim mit Wärmepumpe und PV“ —
PV-Anlage mit 9,8 kWp, Speicher mit 10 kWh, Wärmepumpe mit eigenem Zähler und
Wärmemengenzähler, Wallbox ([Beispielhaushalte](#beispielhaushalte-zum-ansehen-v320)).

---

<a id="case-landlord"></a>
## D — Vermieter mit mehreren Einheiten

**Situation.** Zweifamilienhaus, vermietet. Pro Wohneinheit eigene Zähler für
Strom und Wasser; der Vermieter will je Einheit getrennt auswerten und die
spätere Nebenkostenabrechnung vorbereiten.

**Einrichtung.**

1. Pro Einheit und Art je einen Zähler anlegen:
   `strom` *„WHG 1 Strom"*, `strom` *„WHG 2 Strom"*,
   `wasser` *„WHG 1 Wasser"*, `wasser` *„WHG 2 Wasser"*,
   plus `wasser` *„Allgemein/Garten"*.
2. Verträge je Zähler pflegen (jede Wohnung hat ihren eigenen Liefervertrag).
3. Hat eine Einheit mehrere Zähler **derselben Art** (etwa HT/NT oder eine
   Wallbox), fasst eine **Gruppe** sie zusammen (⚙️ Zähler → „Zu Gruppe
   zusammenfassen“). Gruppen gelten je Verbrauchsart — Strom und Wasser einer
   Wohnung lassen sich nicht zu einer Gruppe bündeln. Seit v3.1.0 kann ein
   Vertrag der ganzen Gruppe gehören, etwa ein HT/NT-Vertrag mit einem
   Grundpreis und zwei Arbeitspreisen
   ([Gruppenvertrag](../verstehen/13-meter-topologie.md#gruppenvertrag-v310)).

**Was passiert.** Unter **Verbrauch → Strom** bzw. **Wasser** zeigt die Auswahl
oben jeden Zähler mit Verbrauch, Kosten und Saldo seines Vertrags; die
Übersicht zeigt die Summe je Verbrauchsart. Der CSV-Export der Zählerstände
(Einstellungen → Daten) trägt je Zeile den Zähler — daraus lässt sich die
Einheit herausfiltern. Die Monatsübersicht exportiert die Summe je
Verbrauchsart, nicht je Einheit.

> **Nebenkosten.** Seit v3.1.0 gibt es die Sicht des **Mieters**: Vorauszahlung
> gegen erwartete Kosten und die Nebenkostenabrechnung mit ihren Fristen
> (F1008, [#15](https://github.com/Bingerminger/energietracker/issues/15),
> [Als Mieter](mieter.md)). Eine Abrechnung für den Vermieter über mehrere
> Einheiten erstellt die App nicht. Verträge je Zählergruppe
> ([#17](https://github.com/Bingerminger/energietracker/issues/17)) gibt es
> seit v3.1.0.

---

<a id="case-wallbox"></a>
## E — Wallbox und Dienstwagen

**Situation.** Eigenheim mit Wallbox hinter dem Haushaltszähler. Der
Dienstwagen wird zu Hause geladen; der Arbeitgeber erstattet den Strom und will
eine Aufstellung je Monat.

**Einrichtung.**

1. `strom` *„Hausanschluss“* mit dem Stromvertrag.
2. `strom` *„Wallbox“* mit der Rolle **„Wallbox“** und dem Elternzähler
   *„Hausanschluss“* → Subzähler.
3. Die Wallbox monatlich ablesen, am besten am Monatsersten — von Hand, per
   Home Assistant oder aus dem Portal der Wallbox. Seit v3.2.0 kommen die
   Zählerstände auch mit den Ladevorgängen aus evcc
   ([Ladevorgänge aus evcc](evcc.md)).
4. Optional: Ist die Wallbox nach § 14a EnWG steuerbar, im Stromvertrag die
   Liste „Reduziertes Netzentgelt (§ 14a EnWG, Modul 1)“ füllen.

**Was passiert.** Die Verbrauchsansicht der Wallbox zeigt die Karte
**„Ladestrom-Nachweis (Dienstwagen)“**: Jahr wählen, Preis wählen —
Vertragspreis mit anteiligem Grundpreis oder Strompreispauschale (2026:
34 ct/kWh, 2027: 32 ct/kWh) —, CSV oder PDF herunterladen. Die Strom-Gesamtsumme bleibt richtig,
weil die Wallbox als Subzähler im Hausanschluss steckt.

```
Beispiel (erfunden), Vertragspreis, März:
  Hausanschluss 400 kWh, Arbeitskosten 120,00 € → 30 ct/kWh, Grundpreis 12,00 €
  Wallbox 100 kWh → 30,00 € + 12,00 € × 100/400 = 33,00 €
```

Eine Aufstellung, keine Steuerberatung — Schritt für Schritt:
[Ladestrom für den Dienstwagen nachweisen](ladestrom-nachweis.md).

**Zum Ansehen:** Beispielhaushalt „Eigenheim mit Wärmepumpe und PV“ — die
Wallbox hängt als Subzähler am Haushaltszähler, ihre Ladevorgänge kommen aus
evcc ([Use-Case G](#g--steuern-mit-evcc-oder-home-assistant--nachrechnen-im-energietracker)).

---

<a id="case-heat"></a>
## F — Fernwärme oder Heizwärme?

**Situation.** Zwei Verbrauchsarten klingen fast gleich und zählen beide Wärme
in kWh: **Fernwärme** und **Heizwärme**. Der Unterschied liegt nicht im Zähler,
sondern im Vertrag — wer schickt dir die Rechnung?

| | Fernwärme | Heizwärme |
|---|---|---|
| Liefervertrag | **dein eigener** mit dem Wärmeversorger | keiner auf deinen Namen |
| Bezahlt wird | an den Versorger: Arbeitspreis, Grundpreis, Leistungs- und Messpreis | über die Nebenkostenabrechnung der Miete — oder gar nicht eigens, weil es die Wärme deiner Wärmepumpe ist |
| In der App | Verträge, Abschläge, Saldo, Rechnungsprüfung | keine Verträge; Kosten über das [Mietverhältnis](mieter.md), bei der Wärmepumpe die Jahresarbeitszahl |

**Die Faustregel:** Hast du selbst einen Vertrag mit dem Wärmeversorger, lege
**Fernwärme** an. Sonst ist es **Heizwärme**. Das gilt auch, wenn dein Mietshaus
am Fernwärmenetz hängt: Den Vertrag hat der Vermieter, für dich ist es
Heizwärme. Unter Einstellungen → Haushalt & Gebäude → „Heizwärme kommt aus“
wählst du dann „Fernwärme“ — das zählt nur für die CO₂-Näherung
([Heizwärme §5](../verstehen/15-waerme.md#5-co₂--eine-näherung)). Ebenso in
einer Eigentumswohnung, in der die Gemeinschaft den Vertrag hat und die Wärme
über das Hausgeld abrechnet.

Drei Beispielhaushalte zeigen die Fälle
([laden](#beispielhaushalte-zum-ansehen-v320)):

**1. „Ich wohne zur Miete“ — Heizwärme aus der Verbrauchsinfo.** Mietwohnung
mit 62 m²; die Zentralheizung (Gas) im Keller gehört dem Vermieter.

- `strom` *„Stromzähler Wohnung“* mit eigenem Stromvertrag — der einzige eigene
  Liefervertrag.
- `waerme` *„Heizung laut Verbrauchsinfo“* mit der Erfassung **„Verbrauch je
  Zeitraum“**: je Monat der Wert aus der monatlichen Verbrauchsinformation des
  Messdienstes, dazu die Vergleichswerte. Monatlich muss sie kommen, sobald die
  Geräte fernablesbar sind (HeizkostenV § 6a, seit 2022).
- `wasser` *„Kaltwasserzähler“* und *„Warmwasserzähler“* (Rolle „Warmwasser“).
- Ein **Mietverhältnis** mit Vorauszahlungen für Heizung und Betriebskosten und
  den Abrechnungen 2024 und 2025.

```text
Verbrauchsinfo April 2026 (erfunden):
  Heizwärme 297 kWh · Vormonat 679 kWh · Vorjahresmonat 316 kWh · Durchschnittsnutzer 341 kWh
Nebenkostenabrechnung 2025: Heizwärme 4.668 kWh, Heizkosten 649,79 €
```

Schritt für Schritt: [Als Mieter](mieter.md#3-einen-zähler-für-die-verbrauchsinfo-anlegen).

**2. „Eigentumswohnung mit Fernwärme“ — eigener Liefervertrag.** 85 m², eine
Wohnungsstation im Flur; die Zählerstände kommen aus dem Kundenportal des
Versorgers.

- `fernwaerme` *„Wärmemengenzähler“* mit dem Vertrag *„Fernwärme Wohnen“*:
  Arbeitspreis, Grundpreis, Anschlussleistung, Leistungs- und Messpreis,
  CO₂-Faktor des Netzes, Abschlag.
- `strom` *„Stromzähler Wohnung“* mit eigenem Stromvertrag.

```text
Feste Kosten je Monat ab April 2025 (erfunden):
  Grundpreis 4,20 € + 6,5 kW × 57,60 €/(kW·a) / 12 + Messpreis 96 €/a / 12
  = 4,20 € + 31,20 € + 8,00 € = 43,40 €
Arbeitspreis ab 2026: 12,5 ct/kWh · CO₂-Faktor des Netzes: 198 g/kWh
```

Die Felder für Anschlussleistung, Leistungs- und Messpreis zeigt der
Vertragsdialog seit v3.7.0 in jeder Stufe (bis v3.6 nur „Experte“), den
CO₂-Faktor des Netzes ab „Experte“; gerechnet wird mit allen in jeder Stufe
([Fernwärme](../verstehen/04-fernwaerme.md#feste-kosten-leistungs--und-messpreis-v310)).

**3. „Eigenheim mit Wärmepumpe und PV“ — Heizwärme als Wärmemenge der
Wärmepumpe.** Hier gibt es gar keine Wärmerechnung, bezahlt wird der Strom der
Wärmepumpe. Den Wärmemengenzähler legst du an, damit die App die
Jahresarbeitszahl rechnet.

- `strom` *„Wärmepumpe“*: eigener Zähler mit der Rolle „Wärmepumpe
  (Heizstrom)“ und eigenem Vertrag *„Wärmepumpenstrom“*, darin das reduzierte
  Netzentgelt nach § 14a EnWG (Modul 1).
- `waerme` *„Wärmemengenzähler Wärmepumpe“* mit der Rolle „Wärmemenge der
  Wärmepumpe“, verknüpft mit dem Stromzähler *„Wärmepumpe“*.

```text
Karte „Wärmepumpe 2025“ (erfunden):
  12.953 kWh Wärme / 3.896 kWh Strom = Jahresarbeitszahl 3,3 · Heizperiode 3,4
```

Die Karte steht seit v3.7.0 ab der Stufe „Erfahren“ (bis v3.6 „Experte“)
([Heizwärme §7](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)).

> **Nicht doppelt.** Die Wärmemenge der Wärmepumpe zählt weder in den Summen der
> Heizwärme noch in der Effizienzkennzahl — dort steht die Wärmepumpe schon mit
> ihrem Strom ([Heizwärme §4](../verstehen/15-waerme.md#4-effizienzkennzahl)).

---

<a id="case-control"></a>
## G — Steuern mit evcc oder Home Assistant — nachrechnen im Energietracker

**Situation.** Ein Energieberater fragt nach einem „Controller“: Er soll den
Hausspeicher und das Auto laden, wenn Strom an der Börse billig oder sogar
negativ ist, und das Auto bidirektional als Speicher fürs Haus nutzen.

**Die Haltung.** Der Energietracker steuert nichts. Er schaltet keine Wallbox,
keinen Speicher und keine Wärmepumpe. Das machen Werkzeuge, die dafür gebaut
sind:

- **evcc** lädt das Auto mit PV-Überschuss oder, mit einem dynamischen Tarif,
  in den Stunden unter einer Preisgrenze
  ([evcc: Dynamische Stromtarife](https://docs.evcc.io/de/features/dynamic-prices));
  unterstützt der Wechselrichter es, lädt evcc auch den Hausspeicher in
  günstigen Stunden aus dem Netz.
- **Home Assistant** schaltet mit Automatisierungen, was es erreicht — etwa
  nach dem Börsenpreis der nächsten Stunden.

Der Energietracker **rechnet nach**, was dabei herauskam:

| Frage | Im Energietracker | Woher die Werte kommen |
|---|---|---|
| Wie viel hat das Auto geladen, wie viel davon aus der Sonne, zu welchem Preis? | Wallbox → Karte „Ladevorgänge aus evcc“ (ab Stufe „Erfahren“) | CSV-Export aus evcc oder „Von evcc abrufen“ ([Ladevorgänge aus evcc](evcc.md)) |
| Was kostet der Ladestrom des Dienstwagens? | Wallbox → Karte „Ladestrom-Nachweis (Dienstwagen)“ | Zählerstände aus evcc, Home Assistant oder von Hand ([Use-Case E](#e--wallbox-und-dienstwagen)) |
| Hätte sich ein dynamischer Tarif gelohnt? | Kosten & Verträge → Wechsel prüfen → „Börsenstrompreise“ und ein Angebot „Dynamischer Tarif“; seit v3.3.0 im Rückblick mit dem eigenen Lastgang | Börsenpreise von SMARD, nur auf Knopfdruck ([Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310)); Viertelstunden wie in der nächsten Zeile |
| Hat das Verschieben in günstige Viertelstunden etwas gebracht? Was bringen Speicher und § 14a Modul 3? | Auswertungen → Flexibilität (seit v3.3.0) | Verbrauch je Viertelstunde aus einer Portaldatei oder aus Home Assistant, Börsenpreise je Viertelstunde von SMARD ([Flexibilität bewerten](flexibilitaet.md)) |
| Wie gut arbeitet die Wärmepumpe, was bringt § 14a Modul 1? | Karte „Wärmepumpe {Jahr}“; im Vertrag „Reduziertes Netzentgelt (§ 14a EnWG, Modul 1)“ | eigener Zähler und Wärmemengenzähler ([Use-Case C](#c--pv-haushalt-mit-wärmepumpe)) |
| Was verliert der Speicher? | PV-Erzeugung → Karte „Speicher {Jahr}“ | Lade- und Entladezähler ([PV §7](../verstehen/12-pv.md#7-speicher-v310)) |
| Alle Stände ohne Abtippen | jede Verbrauchsart | Home Assistant schickt sie abends über `POST /api/ingest` ([Home Assistant](home-assistant.md)) |

Ladestrom-Nachweis und Wärmepumpen-Karte zeigt die App seit v3.7.0 ab der
Stufe „Erfahren“, die Seite Flexibilität seit v3.6.0 in jeder Stufe (mit der
Nachrechnung je Zähler ab „Experte“), die Börsenpreise unter „Wechsel prüfen“
ab „Experte“.

**Im Beispielhaushalt „Eigenheim mit Wärmepumpe und PV“:**

```text
Ladevorgänge aus evcc 2025 (erfunden): 152 Vorgänge, 2.618 kWh,
  davon rund 27 % aus der Sonne (April bis September knapp 50 %, Oktober bis März 9 bis 14 %),
  Preis laut evcc zusammen 629 €
Angebot „Strom Dynamisch“ zum Vergleich: Aufschlag 19,4 ct/kWh, Grundpreis 11,90 €/Monat
Flexibilität 2025 (Verbrauch erfunden, Börsenpreise je Viertelstunde echt von SMARD):
  Wärmepumpe Profilfaktor 0,95 · Speicher lädt zu Ø 4,51 ct, entlädt zu Ø 10,68 ct (rund 104 €)
  · § 14a Modul 3 an der Wärmepumpe rund 81 € Ersparnis
```

Die Monatsmittel der Börsenpreise und für 2025 die Börsenpreise je
Viertelstunde bringt der Beispielhaushalt mit; in deiner Installation holst du
sie mit „Von SMARD laden“ bzw. auf der Seite Flexibilität. Alle Zahlen:
[Flexibilität bewerten → Beispielhaushalt](flexibilitaet.md#9-zum-ansehen-der-beispielhaushalt).

**Seit v3.3.0: „Flexibilität bewerten“.** Der Energietracker speichert Werte je
Viertelstunde — aus Portaldateien, aus Home Assistant (Ingest mit Zeitstempel)
und Börsenpreise von SMARD, nur auf Knopfdruck — und zeigt damit unter
Auswertungen → Flexibilität:

- **Erfolg der Verschiebung:** kWh in günstigen und negativen Viertelstunden,
  eigener Durchschnittspreis gegen das Börsenmittel (Profilfaktor) und der
  Vorteil beim Börsenanteil, je Monat und je Stromzähler; Wallbox und
  Wärmepumpe als steuerbar vorn.
- **Dynamik-Check mit echtem Lastgang** statt Monatsmittel, im Rückblick unter
  Wechsel prüfen.
- **Speicher nach Börsenpreis:** was Laden bei niedrigen und Entladen bei hohen
  Preisen bringt.
- **§ 14a Modul 3:** die Ersparnis durch Verschieben in die
  Niedrigtarif-Zeitfenster des Netzbetreibers, mit den Zeitfenstern am Vertrag.

Gesteuert wird auch dann außerhalb. Schritt für Schritt:
[Flexibilität bewerten](flexibilitaet.md).

**Was der Energietracker nicht kann (Stand v3.3.0).**

- Viertelstunden bekommt er nur aus einer Portaldatei oder aus Home Assistant;
  aus den Ladevorgängen von evcc bildet er keine.
- Die Wechselentscheidung nach vorn rechnet einen dynamischen Tarif weiter mit
  Monatsmitteln; den eigenen Lastgang nimmt nur der Rückblick.
- Modul 3 ändert die Kosten des Vertrags nicht: Saldo, Prognose und
  Rechnungsprüfung rechnen mit dem eingetragenen Arbeitspreis; die Ersparnis
  steht nur auf der Seite Flexibilität.
- Strom, den das Auto zurückgibt (V2H, V2G), bildet er nicht ab; die
  Ladevorgänge aus evcc zählen nur, was geladen wurde.
- Einspeisung bei negativem Börsenpreis zählt er nicht. Für neue PV-Anlagen
  weist er nur auf die Regel hin
  ([PV §10](../verstehen/12-pv.md#10-negative-börsenpreise-v310)).

### Rechtslage kurz (Stand 9. Oktober 2026)

Keine Rechtsberatung — maßgeblich sind Gesetz, Festlegung und die Preisblätter
deines Netzbetreibers.

- **§ 14a EnWG — steuerbare Verbrauchseinrichtungen.** Seit 1. Januar 2024
  gelten dafür zwei Festlegungen der Bundesnetzagentur — zur Steuerung
  (Beschlusskammer 6, BK6-22-300) und zu den Netzentgelten (BK8-22/010-A) —,
  etwa für Wärmepumpen und private Wallboxen: Der Netzbetreiber darf sie bei Engpässen
  drosseln, dafür sinkt das Netzentgelt. **Modul 1** ist eine pauschale
  Reduzierung im Jahr. **Modul 2** senkt mit eigenem Zähler den
  Netzentgelt-Arbeitspreis auf 40 %. **Modul 3** ist ein zeitvariables
  Netzentgelt in drei Stufen (Hochlast, Standard, Niedriglast) — nur zusätzlich
  zu Modul 1 und mit intelligentem Messsystem. Netzbetreiber müssen Modul 3
  seit dem 1. April 2025 anbieten und abrechnen; im Mai 2026 stellte die
  Bundesnetzagentur fest, dass das vielerorts nicht oder nur unzureichend
  geschieht, und drohte zwei Netzbetreibern Zwangsgelder an (Frist
  30.09.2026). Quellen:
  [Bundesnetzagentur, 27.11.2023](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2023/20231127_14a.html),
  [Bundesnetzagentur, Reduzierung des Netzentgelts](https://www.bundesnetzagentur.de/DE/Vportal/Energie/SteuerbareVBE/Netzentgelt_table.html),
  [Bundesnetzagentur, 28.05.2026](https://www.bundesnetzagentur.de/1108084).
- **Negative Preise — „Solarspitzengesetz“.** Das Gesetz zur Vermeidung von
  temporären Erzeugungsüberschüssen (BGBl. 2025 I Nr. 51, verkündet am
  24.02.2025) gilt für PV-Anlagen, die **ab dem 25.02.2025** in Betrieb gehen.
  In Zeiten mit negativem Börsenpreis sinkt ihre Vergütung auf null (§ 51 EEG) —
  bei Anlagen unter 100 kW ab dem Kalenderjahr nach dem Einbau eines
  intelligenten Messsystems. Dafür verlängert sich die Förderdauer
  (§ 51a EEG). Bis ein intelligentes Messsystem eingebaut und die Steuerung
  getestet ist, dürfen neue Anlagen unter 100 kW mit Einspeisevergütung höchstens
  60 % ihrer installierten Leistung einspeisen (§ 9 Abs. 2 EEG;
  Steckersolargeräte bis 2 kW ausgenommen). Ältere Anlagen bleiben beim alten
  Recht (§ 100 Abs. 3b und 46 EEG) — auch die von 2023 im Beispielhaushalt.
  Quellen: [BGBl. 2025 I Nr. 51](https://www.recht.bund.de/bgbl/1/2025/51/VO.html),
  [§ 51 EEG](https://www.gesetze-im-internet.de/eeg_2014/__51.html),
  [§ 51a EEG](https://www.gesetze-im-internet.de/eeg_2014/__51a.html),
  [§ 9 EEG](https://www.gesetze-im-internet.de/eeg_2014/__9.html),
  [§ 100 EEG](https://www.gesetze-im-internet.de/eeg_2014/__100.html).
- **MiSpeL — Speicher mit Netz- und Solarstrom.** Am 1. Oktober 2026 hat die
  Bundesnetzagentur die Festlegung zur Marktintegration von Speichern und
  Ladepunkten beschlossen (Az. 618-25-02). Speicher und bidirektionale
  Ladepunkte dürfen damit Netz- und Solarstrom gemischt speichern und
  zurückspeisen, ohne die EEG-Förderung zu verlieren. Zwei Wege: die
  **Abgrenzungsoption** (eigener Zähler, Erfassung je Viertelstunde, für alle)
  und die **Pauschaloption** für Solaranlagen bis 30 kWp (ein
  viertelstundengenauer Zähler am Hausanschluss genügt). Als Förderung gibt es
  in beiden nur die Marktprämie, also die Direktvermarktung; mit fester
  Einspeisevergütung — wie im Beispielhaushalt — bleibt es bei der
  „Ausschließlichkeitsoption“, der Speicher darf dann für die Förderung nur
  Strom aus der eigenen Anlage speichern. Bis zum 30.09.2027 gilt die
  Festlegung nur im Einverständnis mit Netz- und Messstellenbetreiber; die
  Pauschaloption wartet außerdem auf die beihilferechtliche Genehmigung der
  EU-Kommission. Quellen:
  [Bundesnetzagentur, 01.10.2026](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2026/20261001_Mispel.html),
  [Verfahrensseite MiSpeL](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/start.html),
  [Hintergrundpapier](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/DL/Hintergrundpapier.pdf?__blob=publicationFile&v=3),
  [Festlegung](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/DL/MiSpeL_TenorMitBegruendung.pdf?__blob=publicationFile&v=2)
  (Tenorziffern 5 und 9).
- **Bidirektionales Laden.** Geklärt ist: Für die Netzentgeltbefreiung von
  Speichern verweist § 118 Abs. 6 Satz 3 EnWG auf § 21 EnFG, und der stellt
  Ladepunkte für E-Autos Stromspeichern gleich. Für Netzstrom, den das Auto
  später zurück ins Netz gibt, entfallen damit Umlagen und Netzentgelte; für
  den Fahrstrom fallen sie weiter an. Die Mengen sind mess- und
  eichrechtskonform zu erfassen; wie genau, regelt MiSpeL — laut
  Bundesnetzagentur gehen V2H und V2G damit „auch ohne zweiten Zähler“. Offen
  ist: die Übergangszeit bis 30.09.2027 und die Genehmigung der Pauschaloption
  (siehe MiSpeL). Wie Netzentgelte für Speicher und bidirektionale Ladepunkte
  künftig aussehen, klärt die Bundesnetzagentur im laufenden Verfahren zur
  allgemeinen Netzentgeltsystematik (AgNes). Die Befreiung nach § 118 Abs. 6
  EnWG gilt nur für Speicher, die bis zum 04.08.2029 in Betrieb gehen, für 20
  Jahre und nur für die Entgelte für den Netzzugang. **Stromsteuer:** Wer beim
  bidirektionalen Laden Strom aus dem Auto abgibt, gilt insoweit nicht als
  Versorger; wird der Strom am Ladepunkt ohne öffentliches Netz verbraucht
  (V2H), entsteht keine Stromsteuer (§ 5a Abs. 3 StromStG). Für V2G ins Netz
  sagt die Vorschrift das nicht. Quellen:
  [§ 118 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__118.html),
  [§ 21 EnFG](https://www.gesetze-im-internet.de/enfg/__21.html),
  [§ 5a StromStG](https://www.gesetze-im-internet.de/stromstg/__5a.html),
  Hintergrundpapier und Festlegung (Begründung, Abschnitt 3.2.2.2) wie oben.

---

<a id="case-cascade"></a>
## H — Kaskade mit Wärmepumpe, HT/NT und PV *(v3.9.0)*

**Situation.** Eigenheim mit PV-Anlage und Wärmepumpe. Die Wärmepumpe hat einen
eigenen Tarif, Haushalt und Wärmepumpe rechnen je Hoch- und Niedertarif (HT/NT).
Drei Zähler hängen hintereinander
([#40](https://github.com/Bingerminger/energietracker/issues/40)):

```text
            Netz
              │
   ┌──────────┴──────────┐
   │ Z1  Zweirichtungs-  │  Bezug HT (1.8.1) · Bezug NT (1.8.2) · Einspeisung (2.8.0)
   │     zähler          │
   └──────────┬──────────┘
              ├────────────────── Wärmepumpe   = Z1 − Z2 (je HT und NT), eigener Tarif
   ┌──────────┴──────────┐
   │ Z2  Haushalt        │  HT (1.8.1) · NT (1.8.2), eigener Tarif
   └──────────┬──────────┘
              ├────────────────── Haushalt
              │
   ┌──────────┴──────────┐
   │ Z3  PV-Erzeugung    │  Eigenverbrauch = Z3 − Einspeisung
   └──────────┬──────────┘
              PV-Anlage
```

Drei Verträge: Wärmepumpe (HT/NT), Haushalt (HT/NT) und die Einspeisevergütung.
Z1 misst alles, was durch den Netzanschluss geht. Die Wärmepumpe hat keinen
eigenen Zähler: Ihr Strom ist der **Rest** von Z1 nach Abzug von Z2.

**Wie das heißt.** Die Namen der Messkonzepte wechseln je Netzbetreiber. Der
Verband der Bayerischen Energie- und Wasserwirtschaft nennt diesen Aufbau
„MK C3: Überschusseinspeisung mit Kaskadenmessung“: Z1 für Bezug und Lieferung,
Z2 für Bezug, Z3 für die Lieferung der Erzeugungsanlage, dazwischen nur
steuerbare Verbrauchseinrichtungen wie die Wärmepumpe (VBEW-Messkonzepte,
Handout zur Auswahl der Messkonzepte, Ausgabe 09.2024,
[PDF](https://www.swm-infrastruktur.de/dam/jcr:94f532d5-e88c-4339-b0c7-e91180681ed7/vbew-messkonzepte-erzeugungsanlagen.pdf)).
Andere beschreiben die Kaskade umgekehrt, mit dem Zähler der Wärmepumpe hinter
dem Hauptzähler
([Verbraucherzentrale Niedersachsen](https://www.verbraucherzentrale-niedersachsen.de/node/126144),
Stand 13.10.2026). Maßgeblich ist das Messkonzept, das dein Netzbetreiber
bestätigt. Die App rechnet beide Varianten: Der hintere Zähler mit eigenem
Vertrag wird abgezogen.

**Einrichtung.**

1. Unter **Einstellungen → Verbrauchsarten & Abrechnung → Aktive
   Verbrauchsarten** Strom, PV-Einspeisung und PV-Erzeugung aktivieren.
2. Je **Zählwerk** einen Stromzähler anlegen:
   - „Z1 Bezug HT“ und „Z1 Bezug NT“, Rolle „Wärmepumpe (Heizstrom)“,
   - „Z2 Haushalt HT“ mit Elternzähler „Z1 Bezug HT“ und „Z2 Haushalt NT“ mit
     Elternzähler „Z1 Bezug NT“, Rolle „Haushalt“.

   HT hängt hinter HT, NT hinter NT. Dann zwei **Gruppen**: „Z1 Wärmepumpe“ mit
   Z1 HT und NT, „Z2 Haushalt“ mit Z2 HT und NT
   ([Meter-Topologie](../verstehen/13-meter-topologie.md#2-zählergruppen)).
3. Unter PV-Einspeisung den Zähler „Z1 Einspeisung“, unter PV-Erzeugung
   „Z3 PV-Erzeugung“ anlegen.
4. Verträge: ein **Gruppenvertrag** an „Z1 Wärmepumpe“ mit dem Arbeitspreis je
   Zählwerk (HT, NT), einer an „Z2 Haushalt“, und die Einspeisevergütung an
   „Z1 Einspeisung“.

**Was die App rechnet** *(erfundene Werte eines Monats)*:

<!-- doc-claim: cascade-example (CascadeMeteringTest) -->
```text
Z1 Bezug HT 400 kWh, NT 300 kWh · Z2 Haushalt HT 150 kWh, NT 100 kWh
Einspeisung 250 kWh · PV-Erzeugung 600 kWh

Wärmepumpe  (400 − 150) × 30 ct + (300 − 100) × 20 ct + 10 € =  125,00 €
Haushalt     150 × 35 ct + 100 × 25 ct + 12 €              =   89,50 €
Strom gesamt 700 kWh                                          214,50 €
Einspeisevergütung 250 × 8 ct                                 20,00 € Erlös
Eigenverbrauch 600 − 250 = 350 kWh · Autarkie 350 / (350 + 700) = 33 %
```

- Der Vertrag der Wärmepumpe rechnet nur den **Rest**. Am Zähler, an der
  Saldo-Karte und in „Rechnung prüfen“ steht, wie: „450 kWh = 700 kWh gemessen
  − 150 kWh Z2 Haushalt HT − 100 kWh Z2 Haushalt NT“
  ([Rest-Abrechnung](../verstehen/13-meter-topologie.md#rest-abrechnung-v390)).
- Die Summe Strom ist der Netzbezug an Z1 (700 kWh, Z2 nicht zusätzlich), die
  Kosten die beider Verträge.
- Eigenverbrauch und Autarkie rechnen mit Z1 als Netzbezug, der Einspeisung an
  Z1 und der Erzeugung an Z3 ([PV](../verstehen/12-pv.md)).
- Jahresarbeitszahl, Effizienz und „Strom nach Rollen“ im Jahresbericht nehmen
  für die Wärmepumpe den Rest, nicht die ganze Messung von Z1.

**Grenzen.**

- Z2 misst nur den Bezug. Fließt Sonnenstrom vom Haushalt zur Wärmepumpe
  zurück, misst das kein Zähler. Die App ordnet den selbst genutzten
  Sonnenstrom im Jahresbericht dem Haushalt zu.
- Speicher, die Netz- und Solarstrom mischen (MiSpeL), rechnet die App noch
  nicht. Was die Festlegung erlaubt, steht unter
  [G → Rechtslage kurz](#rechtslage-kurz-stand-9-oktober-2026).

---

## Welcher Use-Case passt zu mir?

| Wenn du sagst … | Use-Case | Zum Ansehen ([laden](#beispielhaushalte-zum-ansehen-v320)) |
|---|---|---|
| „Ich tippe Werte selbst ab, will aber Ordnung bei Haupt-/Unterzählern.“ | A | „Eigenheim mit Gas, Öl oder Pellets“ (Gartenzähler als Subzähler) |
| „Ich habe Home Assistant und will nie wieder abtippen.“ | B | — |
| „Ich habe PV (+ Wärmepumpe).“ | C | „Eigenheim mit Wärmepumpe und PV“ |
| „Ich verwalte mehrere Wohneinheiten.“ | D | — |
| „Ich lade einen Dienstwagen zu Hause.“ | E | „Eigenheim mit Wärmepumpe und PV“ |
| „Ich wohne zur Miete; die Heizung kommt mit der Verbrauchsinfo.“ | F | „Ich wohne zur Miete“ |
| „Ich habe einen Fernwärmevertrag.“ | F | „Eigentumswohnung mit Fernwärme“ |
| „Ich heize mit Gas, Öl oder Pellets.“ | [Erste Schritte](../einstieg/erste-schritte.md) | „Eigenheim mit Gas, Öl oder Pellets“ |
| „evcc oder Home Assistant steuern bei mir Auto, Speicher oder Wärmepumpe.“ | G (+ B) | „Eigenheim mit Wärmepumpe und PV“ |
| „Meine Zähler hängen hintereinander (Kaskade), mit HT/NT und PV.“ | H | — |
| „Ich will erst einmal alles sehen.“ | — | „Erst einmal alles ansehen“ (alle neun Verbrauchsarten) |

Alle Fälle lassen sich kombinieren — z. B. Vermieter (D) mit HA-Push (B) je
Einheit. Für den Einstieg: [Erste Schritte](../einstieg/erste-schritte.md).

---

[← Kompendium-Index](../README.md)
