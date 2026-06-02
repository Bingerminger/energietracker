# Meter-Topologie — Subzähler & Zählergruppen (F1006)

[← PV](12-pv.md) · [Kompendium-Index](../README.md)

Seit **v1.8.0** (Schema 1.2.0) können Zähler in Beziehung zueinander stehen.
Das löst zwei sehr häufige Alltagssituationen sauber: **„ein Zähler steckt
hinter einem anderen"** und **„mehrere Zähler gehören eigentlich zusammen"**.

| Beziehung | Feld am Zähler | Wirkung |
|---|---|---|
| **Subzähler** (Reihenschaltung) | `parent_meter_id` | Verbrauch wird vom Elternzähler **abgezogen** |
| **Gruppe** | `meter_group_id` | Verbräuche werden im Dashboard **zusammengefasst** |

Beide Felder sind optional (Default `null`) und additiv — bestehende Daten
bleiben unverändert.

---

## 1. Subzähler (Reihenschaltung)

**Situation:** Ein Verbraucher hängt *hinter* dem Hauptzähler und wird von
diesem mitgemessen. Klassiker:

- **Wärmepumpe** hinter dem Haushaltsstrom-Zähler,
- **Wallbox** hinter dem Hausanschluss,
- **Gartenwasser** hinter dem Hauptwasserzähler.

Der Hauptzähler misst hier **brutto** — also inklusive des Subzählers. Würde
man beide einfach addieren, wäre der Subzähler-Anteil doppelt gezählt.

**Lösung:** Setze beim Subzähler den Elternzähler (`parent_meter_id`). Dann gilt:

```
Eigenverbrauch Elternzähler (netto) = Brutto-Stand − Σ Subzähler-Verbräuche
Utility-Gesamtsumme                 = nur Zähler OHNE parent_meter_id
```

Im Dashboard erscheint der Subzähler eingerückt unter seinem Elternzähler; in
der Verbrauchsart-Summe taucht er **nicht** zusätzlich auf.

> **Beispiel.** Haushaltsstrom misst im Januar 300 kWh (brutto). Davon entfallen
> laut Wärmepumpen-Subzähler 120 kWh auf die Wärmepumpe. Die Strom-Gesamtsumme
> für Januar bleibt **300 kWh** (nicht 420) — die 120 kWh sind nur die
> Aufschlüsselung, wie viel davon die Wärmepumpe war.

### Regeln

- **Maximal eine Ebene.** Ein Subzähler darf nicht selbst Elternzähler eines
  weiteren Subzählers sein (keine mehrstufigen Ketten, keine Zyklen).
- **Löschschutz.** Ein Elternzähler mit zugeordneten Subzählern lässt sich nicht
  löschen, ohne die Zuordnung vorher zu lösen.

---

## 2. Zählergruppen

**Situation:** Mehrere Zähler gehören logisch zusammen und sollen im Dashboard
als *ein* Posten erscheinen:

- **NT + HT Strom** (zwei Zählwerke / zwei Zähler für denselben Anschluss),
- **mehrere Wallboxen** an einem Standort,
- **mehrere Einheiten** desselben Hauses (siehe
  [Use-Cases → Vermieter](../USE-CASES.md)).

**Lösung:** Lege eine Gruppe an und ordne die Zähler zu (`meter_group_id`). Die
Gruppe summiert die Verbräuche fürs Dashboard und ist aufklappbar, sodass die
Einzelzähler weiterhin sichtbar bleiben.

### Merge-Wizard

Am schnellsten geht das Bündeln über **Einstellungen → Zähler / Verträge →
„Zähler zusammenführen"**: mehrere bestehende Zähler auswählen, Gruppenname
vergeben, fertig. Im Hintergrund setzt der Wizard `meter_group_id` bei allen
gewählten Zählern.

### Was Gruppen (noch) nicht tun

In v1.8.0 fassen Gruppen ausschließlich den **Verbrauch fürs Dashboard**
zusammen. **Verträge bleiben pro Zähler** — es gibt (noch) keinen
Gruppen-Vertrag mit gemeinsamem Saldo. Diese Erweiterung ist bewusst auf ein
späteres Release vertagt, um Doppelzählungen in der Saldo-Logik zu vermeiden.

---

## 3. Zusammenspiel & Datenmodell

Ein Zähler darf **gleichzeitig** Subzähler *und* Gruppenmitglied sein. Die
Aggregation rechnet immer erst die Subzähler-Netto-Werte, dann die
Gruppensumme — so kann nichts doppelt einfließen.

Die technische Sicht (Felder, `meter_groups.json`, Validierung, API-Endpoints)
steht im [Datenmodell](../technical/04-data-model.md) und in der
[API-Referenz](../technical/03-api-reference.md). Die Aliase für die
Home-Assistant-Anbindung (`external_id`) sind unabhängig davon und in
[Home Assistant](../HOME-ASSISTANT.md) beschrieben.

---

[← PV](12-pv.md) · [Kompendium-Index](../README.md)
