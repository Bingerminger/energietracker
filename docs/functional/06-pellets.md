# Holzpellets

[← Heizöl](05-heizoel.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **lieferbasiert** (Lieferrechnungen) |
| Eingabeeinheit | **Kilogramm (kg)** |
| Abrechnungseinheit | kWh |
| Heizwert | `pellets_kwh_per_kg` (Default **4,8 kWh/kg**, DIN EN ISO 17225-2 A1) |
| HGT-relevant | **ja** |
| Farbe | Bernstein/Braun |

---

## 1. Wie Heizöl — mit einer wichtigen Einheit-Differenz

Pellets funktionieren mechanisch **identisch zu Heizöl**: kein Zähler,
sondern Lager (`capacity`, `initial_stock`) plus Lieferungen. Das
gesamte Tankmodell, die Energiebilanz, die kalibrierte Bestandskurve
(v1.4.0) und der Gesamtbetrag-Vorrang (v1.4.2) gelten unverändert —
siehe **[Heizöl §3–§5](05-heizoel.md)**.

**Der eine Unterschied: die Einheit.** Pellets werden in **kg**
geliefert und gelagert, nicht in Litern. Entsprechend:

- `volume_unit = "kg"`, `capacity_unit = "kg"`
- Lieferpreis ist **ct/kg**, Gesamtbetrag €
- Heizwert `pellets_kwh_per_kg` statt `heizoel_kwh_per_l`

```text
kWh = kg × Hu     (Hu ≈ 4,8 kWh/kg)
```

Alle Exporte, Tabellen und Tooltips zeigen entsprechend „kg" statt „L"
(seit v1.4.2 auch der Lieferungs-CSV-Export mit korrekter Einheit im
Spaltenkopf).

---

## 2. Verträge? — Nein, die Lieferrechnung *ist* der Vertrag

Wie bei Heizöl: keine Vertrags-Entität. Trage Menge (kg) und
Rechnungsbetrag (€) der Pellet-Lieferung ein; `total_eur` hat Vorrang
vor einem ct/kg-Stückpreis (enthält Anlieferung/Einblasen/Rabatt).

---

## 3. Heizwert-Hinweis

4,8 kWh/kg entspricht zertifizierten Qualitätspellets (DIN EN ISO
17225-2 Klasse A1, Wassergehalt ≤ 10 %). Feuchtere oder minderwertige
Ware liegt darunter — bei Bedarf `pellets_kwh_per_kg` in den
Einstellungen anpassen, sonst sind kWh und Effizienzklasse zu optimistisch.

---

## 4. Demo-Beispiel

5000-kg-Lager, Start 4000 kg, jährliche Sommer-Lieferung ~2400 kg →
realistischer Sägezahn (Min ~44 %, Max ~98 %), Effizienzklasse im
Bereich D–E (gut gedämmtes Pellet-Haus).

---

[← Heizöl](05-heizoel.md) · [Szenario Wohnung →](07-szenario-wohnung.md)
