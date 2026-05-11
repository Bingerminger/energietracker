# Demo-Daten

Drei Jahre simulierte Verbrauchsdaten für ein Einfamilienhaus in Leipzig
(Stand 2023-01-01 bis 2025-12-31). Inhalt:

| Utility | Zähler                              | Verträge                       | Highlights |
|---------|-------------------------------------|--------------------------------|------------|
| Gas     | Hauptzähler                         | 3 (Stadtwerke → Vattenfall)    | **Zählertausch am 2024-10-01** (1562 m³ → 10 m³, Eichtausch) |
| Strom   | Hauptzähler                         | 3 (Stadtwerke → E.ON)          | Neukundenbonus 120 € (2024)  |
| Wasser  | Hauptzähler + Gartenzähler          | 2 (Kommunale Wasserwerke)      | F3 — zwei Zähler pro Utility, Garten nur Mai–September |

Temperaturen: 1277 Tage Leipzig (~Jan 2023 bis Juni 2026, leicht
zufälliger sinusförmiger Jahresverlauf).

## Verwendung

```bash
cp -r demo-data/* data/
```

> ⚠️ **Vorher prüfen, dass `data/` leer ist** — sonst werden die eigenen
> Verträge und Readings überschrieben.

Danach die Anwendung im Browser öffnen. Dashboard, Analyse, Forecast
sind alle sofort mit echten Werten gefüllt.

## Was die Demo zeigt

- **Witterungsbereinigung** über drei Heizperioden → HGT-Scatter mit
  klar erkennbarem Trend
- **Vertragswechsel** mit unterschiedlichen Arbeitspreisen und einem
  Neukundenbonus
- **Zählertausch** in der Gas-Sektion → die Bridge-Logik aus F2 wird
  sichtbar im Monatsverlauf rund um Oktober 2024
- **Mehrere Zähler pro Utility** in der Wasser-Sektion → das
  Zähler-Dropdown filtert auf Haupt- oder Gartenzähler

## Daten regenerieren

Der Generator liegt nicht im Repo (verändert keine Logik, nur
Beispieldaten). Wer eigene Demo-Daten erzeugen möchte, kann das im
Skript-Repo unter `build_demo_data.py` anpassen — Seed `42` macht den
Lauf reproduzierbar.
