# Szenario: Eigenheimbesitzer

[← Szenario Wohnung](07-szenario-wohnung.md) · [Kompendium-Index](../README.md)

Typische Ausgangslage: Einfamilienhaus, **eine** Heizquelle
(Gas / Fernwärme / Wärmepumpe-Strom / Heizöl / Pellets), eigener
**Strom**, eigener **Wasser**zähler, ggf. **Tank** für Öl/Pellets.
Hier entfaltet Energietracker den vollen Funktionsumfang.

---

## 1. Empfohlene Einrichtung

1. **Wohnfläche, Baujahr, Gebäudetyp** in den Einstellungen pflegen —
   Grundlage der **Effizienzklasse**.
2. **Eine** Heizquelle als aktiv führen (siehe §3). Strom + Wasser
   zusätzlich.
3. **Temperaturen** aktivieren: Standortkoordinaten setzen
   (Default Leipzig) und Open-Meteo-Sync nutzen oder CSVs importieren —
   ohne Temperaturreihe keine HGT-Analyse/Wetterbereinigung.
4. **Regelmäßig erfassen**: kumulative Zähler monatlich ablesen;
   Öl/Pellet-Lieferungen direkt nach Erhalt eintragen (Menge +
   Rechnungsbetrag).

---

## 2. Den vollen Analysezyklus nutzen

```
Ablesungen/Lieferungen ─► Monatsverbrauch ─► HGT-Regression
        │                                          │
        ▼                                          ▼
   Wetterbereinigung ◄──────────────────── Saisonprofil
        │                                          │
        └──────────────► Prognose (R²-Blend) ◄─────┘
                                │
                                ▼
                  Effizienzklasse · Empfehlungen
```

Konkret:

- **Heizsignatur** (Analyse): Wie stark hängt dein Verbrauch von der
  Kälte ab? Hohes $R^2$ = stark heizgetrieben. Die `sigmoid`-Kurve
  zeigt die Sättigung bei sehr kalten Tagen.
- **Wetterbereinigung**: Trennt „kalter Winter" von echtem
  Mehrverbrauch — die Kernfrage nach einer Sanierung („bringt die neue
  Dämmung wirklich etwas, wetterbereinigt?").
- **Effizienzklasse pro Quelle** (seit v1.4.0): Eine ehrliche
  kWh/m²·a-Einordnung deiner Hauptheizung.
- **Empfehlungen**: sieben statistische Regeln (Mehrverbrauch-Trend,
  Sommer-Sockel, Anomalie, Tank-Niveau, Vertragsende, Effizienz …) —
  rein aus den Eigendaten, keine Werbung.

---

## 3. Genau EINE Heizquelle aktiv führen

Ein Haus heizt real mit einer Quelle. Sind mehrere Heizarten gleichzeitig
aktiv, wäre die *kombinierte* Effizienzklasse unsinnig (Summe mehrerer
Vollheizungen auf dieselbe Fläche). Deshalb:

- Führe deine reale Heizquelle aktiv (z. B. nur `gas`).
- Die anderen Heizarten **inaktiv** lassen (Daten bleiben erhalten,
  nur aus Sidebar/Dashboard ausgeblendet).
- **Ausnahme** — bewusst kombinierter Betrieb (z. B. Pellet-Grundlast
  + Gas-Spitzenlast): mehrere aktiv lassen und die `combined`-Sicht der
  Effizienz nutzen; `per_source` zeigt zusätzlich jede Quelle einzeln.

---

## 4. Öl-/Pellet-Haushalte: Tank im Blick

- Trage **jede Lieferung** mit Menge und **Rechnungsbetrag** ein
  (`total_eur` hat seit v1.4.2 Vorrang — enthält Liefergebühr/Rabatt).
- Die **Bestandskurve** (seit v1.4.0 kalibriert) zeigt einen
  realistischen Sägezahn und warnt über `tank_warn_pct` rechtzeitig vor
  Leerstand → rechtzeitig nachbestellen (idealerweise im Sommer, wenn
  Öl/Pellets günstiger sind).
- Plane die nächste Lieferung als `is_planned` vor — sie erscheint,
  verfälscht aber Bilanz/Bestand nicht.

Details und Formeln: [Heizöl](05-heizoel.md) / [Pellets](06-pellets.md).

---

## 5. Vor/Nach einer Sanierung messen

Die wertvollste Anwendung. Vorgehen:

1. Mindestens **eine volle Heizperiode vor** der Maßnahme sauber
   erfassen (für eine belastbare Regression).
2. Maßnahme (Dämmung, Fenster, Heizungstausch, hydraulischer Abgleich).
3. Danach die **Wetterbereinigung** vergleichen — nur sie trennt den
   Effekt der Maßnahme vom Effekt des Wetters. Eine reine
   kWh-Differenz Jahr/Jahr ist irreführend, wenn die Winter
   unterschiedlich kalt waren.

$$
\text{Einsparung}_{\text{echt}} \approx
\text{Verbrauch}^{\text{vorher}}_{\text{wetterbereinigt}}
-
\text{Verbrauch}^{\text{nachher}}_{\text{wetterbereinigt}}
$$

---

## 6. Termine & Wartung

Lege wiederkehrende Termine an (Heizungswartung, Schornsteinfeger,
Zähler-Eichfrist). Fällige/überfällige Termine erscheinen auf dem
Dashboard; beim Erledigen wird der nächste Termin gemäß Recurrence
fortgeschrieben.

---

[← Szenario Wohnung](07-szenario-wohnung.md) · [Glossar →](09-glossar.md)
