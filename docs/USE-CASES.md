# Anwendungsbeispiele & Use-Cases

**Deutsch** · [English](en/USE-CASES.md)

[← Kompendium-Index](README.md)

Vier durchgerechnete Praxisfälle, die zeigen, wie Energietracker in konkreten
Wohnsituationen eingerichtet und genutzt wird. Jeder Fall nennt die **Zähler**,
die **Einstellungen** und einen **typischen Ablauf**. Für die Grundeinrichtung
siehe zuerst [Erste Schritte](ERSTE-SCHRITTE.md).

| Use-Case | Schwerpunkt | Features |
|---|---|---|
| [A — WG mit geteilten Zählern](#a--wg-mit-geteilten-zählern) | Subzähler, Kostenteilung | F1006 |
| [B — Smart Home / Home Assistant](#b--smart-home--home-assistant-vollausbau) | Automatischer Push | F1009 |
| [C — PV-Haushalt mit Wärmepumpe](#c--pv-haushalt-mit-wärmepumpe) | PV + Subzähler | F1005, F1006 |
| [D — Vermieter mit mehreren Einheiten](#d--vermieter-mit-mehreren-einheiten) | Gruppen je Einheit | F1006 |

---

## A — WG mit geteilten Zählern

**Situation.** Vierer-WG, ein gemeinsamer Stromhauptzähler. Eine Person betreibt
einen stromhungrigen Server/Gaming-PC mit eigenem Steckdosenzähler und will
ihren Anteil sauber heraushalten.

**Einrichtung.**

1. Hauptzähler `strom` anlegen: *„Hausanschluss WG"*.
2. Zweiten Zähler `strom` anlegen: *„Arbeitszimmer (Server)"*.
3. Beim zweiten Zähler unter **Zähler / Verträge** den **Elternzähler** auf
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
Doppelzählung bleiben. Details: [Meter-Topologie](functional/13-meter-topologie.md).

> **Tipp.** Den WG-Vertrag am Hauptzähler pflegen. Der Subzähler braucht keinen
> eigenen Vertrag — für die interne Umlage genügt seine kWh-Zahl.

---

## B — Smart Home / Home Assistant-Vollausbau

**Situation.** Technikaffiner Haushalt mit Home Assistant (HA), das bereits alle
Zähler digital ausliest (Strom + Gas per Lesekopf, Wasser per Impulszähler).
Niemand will mehr Werte abtippen.

**Einrichtung.**

1. In **Einstellungen → 🏠 Home-Assistant-Anbindung** einen **API-Token**
   erzeugen (einmalig kopieren).
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
[Home Assistant](HOME-ASSISTANT.md).

> **Sicherheit.** Solange kein Token gesetzt ist, ist der Ingest offen (nur fürs
> LAN gedacht). Sobald ein Token existiert, verlangt `/api/ingest` ihn als
> `Authorization: Bearer …`. Der Token liegt serverseitig nur als Hash vor.

---

## C — PV-Haushalt mit Wärmepumpe

**Situation.** Eigenheim mit PV-Anlage und Wärmepumpe. Gewünscht: Autarkiequote,
Einspeisevergütung **und** der separate Stromverbrauch der Wärmepumpe.

**Einrichtung.**

1. In **Einstellungen → Aktive Verbrauchsarten** `pv_einspeisung` und
   `pv_erzeugung` aktivieren.
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
  entfällt — ideal, um die WP-Effizienz (JAZ) im Blick zu behalten — ohne die
  Strom-Gesamtsumme zu verdoppeln.

Hintergrund: [PV](functional/12-pv.md) und
[Meter-Topologie](functional/13-meter-topologie.md). Wer die WP-Werte ebenfalls
aus HA zieht, kombiniert das mit Use-Case B (Alias `strom_waermepumpe`).

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
2. Pro Einheit eine **Gruppe** bilden (Merge-Wizard): *„Wohnung 1"* fasst
   WHG-1-Zähler zusammen, *„Wohnung 2"* die der zweiten Einheit.
3. Verträge je Zähler pflegen (jede Wohnung hat ihren eigenen Liefervertrag).

**Was passiert.** Das Dashboard zeigt pro Gruppe einen aufklappbaren Sammelposten
— *„Wohnung 1: 2.940 kWh / 78 m³"* — und darunter die Einzelzähler. So sieht der
Vermieter Einheit für Einheit, ohne die Zähler manuell zusammenrechnen zu müssen.

> **Ausblick NKA.** Die strukturierte Nebenkostenabrechnung für Mieter
> (relevante Zählerstände, pauschale Umlagen, jährliche Endabrechnung mit
> PDF-Upload) ist als Feature **F1008** geplant und baut genau auf dieser
> Gruppen-/Topologie-Struktur auf. Bis dahin liefern Gruppen + der
> [CSV-Export](technical/03-api-reference.md) je Einheit eine solide Grundlage.

---

## Welcher Use-Case passt zu mir?

- **Ich tippe Werte selbst ab, will aber Ordnung bei Haupt-/Unterzählern.** → A
- **Ich habe Home Assistant und will nie wieder abtippen.** → B
- **Ich habe PV (+ Wärmepumpe).** → C
- **Ich verwalte mehrere Wohneinheiten.** → D

Alle vier lassen sich kombinieren — z. B. Vermieter (D) mit HA-Push (B) je
Einheit. Für den Einstieg: [Erste Schritte](ERSTE-SCHRITTE.md).

---

[← Kompendium-Index](README.md)
