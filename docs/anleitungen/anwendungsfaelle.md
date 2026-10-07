# Anwendungsbeispiele & Use-Cases

**Deutsch** · [English](../en/anleitungen/anwendungsfaelle.md)

[← Kompendium-Index](../README.md)

Fünf durchgerechnete Praxisfälle, die zeigen, wie Energietracker in konkreten
Wohnsituationen eingerichtet und genutzt wird. Jeder Fall nennt die **Zähler**,
die **Einstellungen** und einen **typischen Ablauf**. Für die Grundeinrichtung
siehe zuerst [Erste Schritte](../einstieg/erste-schritte.md).

| Use-Case | Schwerpunkt | Features |
|---|---|---|
| [A — WG mit geteilten Zählern](#a--wg-mit-geteilten-zählern) | Subzähler, Kostenteilung | F1006 |
| [B — Smart Home / Home Assistant](#b--smart-home--home-assistant-vollausbau) | Automatischer Push | F1009 |
| [C — PV-Haushalt mit Wärmepumpe](#c--pv-haushalt-mit-wärmepumpe) | PV + Subzähler | F1005, F1006 |
| [D — Vermieter mit mehreren Einheiten](#d--vermieter-mit-mehreren-einheiten) | Zähler je Einheit | F1006 |
| [E — Wallbox und Dienstwagen](#e--wallbox-und-dienstwagen) | Ladestrom-Nachweis, § 14a EnWG | v3.1.0 |

---

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

---

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

## E — Wallbox und Dienstwagen

**Situation.** Eigenheim mit Wallbox hinter dem Haushaltszähler. Der
Dienstwagen wird zu Hause geladen; der Arbeitgeber erstattet den Strom und will
eine Aufstellung je Monat.

**Einrichtung.**

1. `strom` *„Hausanschluss“* mit dem Stromvertrag.
2. `strom` *„Wallbox“* mit der Rolle **„Wallbox“** und dem Elternzähler
   *„Hausanschluss“* → Subzähler.
3. Die Wallbox monatlich ablesen, am besten am Monatsersten — von Hand, per
   Home Assistant oder aus dem Portal der Wallbox.
4. Optional: Ist die Wallbox nach § 14a EnWG steuerbar, im Stromvertrag die
   Liste „Reduziertes Netzentgelt (§ 14a EnWG, Modul 1)“ füllen.

**Was passiert.** Die Verbrauchsansicht der Wallbox zeigt die Karte
**„Ladestrom-Nachweis (Dienstwagen)“**: Jahr wählen, Preis wählen —
Vertragspreis mit anteiligem Grundpreis oder Strompreispauschale (2026:
34 ct/kWh) —, CSV oder PDF herunterladen. Die Strom-Gesamtsumme bleibt richtig,
weil die Wallbox als Subzähler im Hausanschluss steckt.

```
Beispiel (erfunden), Vertragspreis, März:
  Hausanschluss 400 kWh, Arbeitskosten 120,00 € → 30 ct/kWh, Grundpreis 12,00 €
  Wallbox 100 kWh → 30,00 € + 12,00 € × 100/400 = 33,00 €
```

Eine Aufstellung, keine Steuerberatung — Schritt für Schritt:
[Ladestrom für den Dienstwagen nachweisen](ladestrom-nachweis.md).

---

## Welcher Use-Case passt zu mir?

- **Ich tippe Werte selbst ab, will aber Ordnung bei Haupt-/Unterzählern.** → A
- **Ich habe Home Assistant und will nie wieder abtippen.** → B
- **Ich habe PV (+ Wärmepumpe).** → C
- **Ich verwalte mehrere Wohneinheiten.** → D
- **Ich lade einen Dienstwagen zu Hause.** → E

Alle fünf lassen sich kombinieren — z. B. Vermieter (D) mit HA-Push (B) je
Einheit. Für den Einstieg: [Erste Schritte](../einstieg/erste-schritte.md).

---

[← Kompendium-Index](../README.md)
