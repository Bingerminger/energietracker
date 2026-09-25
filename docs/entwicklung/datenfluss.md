# Datenfluss & Algorithmen

**Deutsch** · [English](../en/entwicklung/datenfluss.md)

[← Kompendium-Index](../README.md)

Wie aus Zählerständen Monatsverbrauch, Kosten und Saldo werden und wie die
Prognose rechnet — der Weg durch `ConsumptionService` und `ForecastService`.
Schichten, Services und Verzeichnisse beschreibt die [Architektur](architektur.md),
jede Einstellung die [Einstellungsreferenz](../referenz/einstellungen.md), die
Formeln im fachlichen Zusammenhang [Grundlagen & Methodik](../verstehen/00-overview.md).

> Bis v2.13 hieß diese Seite `docs/ARCHITECTURE.md` („Kurzfassung“, Stand
> v1.4.4). Modulkarte, Schichten und Settings-Inventar doppelten die
> Architektur-Seite und sind entfallen; geblieben ist, was es nur hier gab.

---

## 1. Datenfluss: Zählerstand → Monatsverbrauch → Saldo

### Schritt 1 — Reading-Sammlung

`ConsumptionService::forMeter($utility, $meter)` startet mit allen
Readings des Meters, filtert solche mit `is_future: true` oder Datum
in der Zukunft heraus, und sortiert chronologisch. Mindestens zwei
Readings müssen vorliegen — sonst leeres Ergebnis.

### Schritt 2 — Intervall-zu-Monats-Verteilung

Für jedes Paar konsekutiver Readings `(prev, curr)`:

1. Tage = `(curr.date − prev.date).days`
2. Counter-Delta wird via `consumptionBetween()` berechnet — bei
   Device-Wechsel zwischen `prev` und `curr` wird das so überbrückt:
   ```
   consumption = (old_device.final_counter − prev.counter)
               + (curr.counter − new_device.initial_counter)
   ```
3. Counter-Delta × `unit_to_kwh`-Faktor (für Gas: 11.5 kWh/m³ als Default).
4. **Lineare Verteilung auf die Monate**: täglich werden die kWh und
   die anteiligen Tage in den jeweiligen `YYYY-MM`-Bucket geschoben.

### Schritt 3 — Wetter-Anreicherung

Jeder Monat bekommt aus `temperatures.json` zugeordnet:

- `avg_temp` = Mittelwert aller Tagestemperaturen im Monat
- `min_temp` = Minimum
- `max_temp` = Maximum
- **`hdd`** (Heizgradtage) = Σ max(0, base − avg_day) über alle Tage,
  base = `settings.hdd_base_temp` (Default 15 °C)

### Schritt 4 — Utility-Felder

- `kwh_per_day = kwh / days`
- `m3 = kwh / unit_to_kwh_factor` (nur Gas)
- `co2_kg = kwh × Faktor / 1000` — Faktor über `SettingsService::co2Factor(Schlüssel, Jahr)`; Strom je Jahr aus `co2_strom_years` (seit v2.10.0)

### Schritt 5 — Contract-Application (seit v2.9.0 tagesgenau)

`ContractService::segmentsBetween(...)` teilt jeden Monat an jedem
Vertragsbeginn und -ende und an jedem Stichtag der Arbeitspreise,
Grundpreise und Abschläge. Welcher Vertrag an einem Tag gilt, entscheidet
`resolveForDate(...)`: bei Überlappung der spätere Beginn; nach einem
Vertragsende ohne Nachfolger der zuletzt beendete Vertrag als Annahme
(`assumed`), solange er nicht als gekündigt markiert ist
(`auto_renews: false`). Je Abschnitt:

```
Arbeitspreis = Verbrauch der Abschnittstage (aus Schritt 2)
               × Preis am Abschnittstag (valueOnDate; nach dem Ende
                 der Preis am letzten Vertragstag, priceDate)
Grundpreis   = Monatsbetrag × Abschnittstage / Monatstage
Abschlag     = Monatsbetrag am ersten Vertragstag des Monats
               × Vertragstage / Monatstage
Bonus        = je Vertrag; nicht für angenommene Abschnitte nach dem Ende
```

Die Monatszeile:

- `contract_id` = Vertrag mit den meisten Tagen, `contract_assumed`
- `working_price_ct` = mengengewichteter Arbeitspreis des Monats
- `kwh_cost`, `base_price_eur`, `advance_eur`, `bonus_eur` = Summen der
  Abschnitte
- `cost = kwh_cost + base_price_eur − bonus_eur`
- `monthly_balance = cost − advance_eur` (positiv = Unterzahlt)
- `cumulative_balance` = laufender Saldo pro Vertrag-ID
- `contract_parts[]` — nur bei mehr als einem Vertrag im Monat: je Vertrag
  `days`, `kwh`, `kwh_cost`, `base_price_eur`, `advance_eur`,
  `bonus_eur`, `cost`, `assumed`

Bis v2.8 galt der Vertrag vom Monatsersten für den ganzen Monat
(`findActiveForDate(Monatserster)`). **Wasser** rechnet weiterhin so: Das
Drei-Komponenten-Modell kennt keine Abschläge, und seine Tarife ändern sich
zum Jahreswechsel.

### Schritt 6 — Moving Averages

- `ma3` = 3-Monats-Mittel über `kwh` (für Wasser über `m3`)
- `ma6` = 6-Monats-Mittel
- `ma12` = 12-Monats-Mittel

### Schritt 7 — Vertragsaggregation (`contractStatus`)

Nach den Monatszeilen liefert `ConsumptionService::contractStatus()` pro
Contract-ID die Aggregation:

```
actual_kwh       = Σ kwh           (gemessene Monate; bei zwei Verträgen
actual_cost      = Σ cost           im Monat nur der Teil dieses Vertrags)
actual_kwh_cost  = Σ kwh_cost
actual_base_total  = Σ base_price_eur
actual_bonus_total = Σ bonus_eur
months_actual    = count(Monate)
```

Der **Saldo** rechnet seit v2.8.0 nach Kalender bis heute
(`balanceProjection`), wie die Jahresabrechnung:

```
cost_to_date          = energy_cost_to_date + base_to_date − bonus_to_date
advance_paid          = Abschläge nach Zahlungsplan bis einschließlich
                        des laufenden Monats
current_balance       = cost_to_date − advance_paid + special_payment_net
projected_end_balance = current_balance + estimated_cost_remaining
                        − advance_remaining
```

Der Verbrauch seit der letzten Ablesung wird geschätzt (Heizmodell bzw.
Saisonprofil). Offene und weiterlaufende Verträge (`renewed`, seit v2.9.0)
rechnen bis zum nächsten Abrechnungsstichtag. Dazu kommen seit v2.9.0 der
Kündigungsstichtag (`ContractService::switchTiming`: `cancel_by`,
`switch_date`, `notice_basis`), die Erinnerungsstufe relativ zu diesem Tag
(`remind_basis`), `cancel_missed` und die nächste eingetragene
Preiserhöhung (`price_increase`).

`verdict`-Schwelle:
- `Nachzahlung` wenn `projected > +5 €`
- `Erstattung` wenn `projected < −5 €`
- `Ausgeglichen` dazwischen

---

## 2. Prognose-Algorithmus

`ForecastService::forMeter($utility, $meter)` mischt zwei Quellen:

1. **Regressions-Komponente** — auf den vergangenen `(hdd, kwh)`-
   Paaren fittet das in den Settings konfigurierte Modell. Predict pro
   Forecast-Monat verwendet die HGT-Vorhersage aus dem Saisonprofil der
   letzten Jahre (Tages-Mittelwerte über den Kalendertag, aggregiert
   auf den Monat).
2. **Saison-Komponente** — Mittelwert des Verbrauchs in jedem
   Kalendermonat über alle vergangenen Jahre.

Die finale Vorhersage ist:

```
weight = min(blend_max, r2)   // settings.blend_max = 0.8 default
forecast_kwh = weight × regression_pred + (1 − weight) × seasonal_avg
```

Bei `hgt_relevant: false` (Wasser) wird `weight = 0` gesetzt — die
Vorhersage besteht ausschließlich aus dem Saisonprofil.

**Kostenprognose (F-02, seit v1.1.0).** Pro Prognosemonat entstehen
`cost_estimated` (Arbeitspreis × Menge + Grundpreis − bekannte Boni),
`advance_estimated` (der gültige Abschlag) und `balance_running`
(kumuliert Kosten − Abschlag). Seit v2.9.0 teilt `projectStandardMonth()`
den Monat wie die Ist-Rechnung an Vertrags- und Preisstichtagen
(`segmentsBetween`); die Menge verteilt sich nach Tagen auf die Abschnitte,
Grundpreis und Abschlag anteilig. Nach einem Vertragsende ohne Nachfolger
läuft der letzte Vertrag als Annahme weiter (`contract_assumed`). Wasser
löst den Vertrag weiterhin am Monatsersten auf (`resolveForDate`). Künftige
Boni werden nicht fortgeschrieben. Fehlt jeder Vertrag, greift
`last_price_ct` als Fallback-Arbeitspreis.


---

## 3. Erster Start

Findet der Server ein leeres Datenverzeichnis vor (weder `meta.json` noch ein
v0.9.0-Altbestand), legt `Storage\Migrator::initFresh()` an — statt zu migrieren:

- je Verbrauchsart `meters.json`, `readings.json` bzw. `deliveries.json`,
  `contracts.json` und `meter_groups.json`;
- für Gas, Strom, Wasser und Fernwärme je einen **Standardzähler**; Heizöl,
  Pellets und die PV-Arten bleiben leer, bis jemand einen Zähler bzw. Tank anlegt;
- `temperatures.json`, `reminders.json` und ein leeres `settings.json` —
  die Standardwerte kommen aus `SettingsService::DEFAULTS`, gespeichert wird
  nur, was abweicht (etwa das Länderprofil beim ersten Start);
- zuletzt `meta.json` mit `schema_version` und `created_at`.

Bis v1.9.1 lief ein leeres Verzeichnis durch die Migration, und eine frische
Installation hatte keinen einzigen Zähler.

---

## 4. Lokal ausprobieren und Fehler suchen

```bash
# Server immer mit router.php — ohne ihn liefert PHP auch data/ aus
php -S 127.0.0.1:8080 router.php

# gegen eine Kopie der Beispieldaten, ohne die eigenen anzufassen
cp -R demo-data /tmp/etdata && ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php

# ein Endpunkt per curl
curl -s http://127.0.0.1:8080/api.php/api/diagnostics
```

- `ET_DEBUG=1` hängt Datei, Zeile und Ausnahmetyp an Fehlerantworten — nur
  kurzzeitig, nie im offenen Betrieb.
- `ET_LOG_LEVEL=debug` schreibt zusätzlich einen Eintrag je Anfrage ins
  Protokoll (JSON-Zeilen; Ziel über `ET_LOG_DEST`).
- Ein `500` nennt eine Fehler-ID; dieselbe steht im Protokoll.
- Tests und ihre Harnesse: [Tests](tests.md).

---

[← Kompendium-Index](../README.md)
