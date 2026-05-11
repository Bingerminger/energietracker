# Screenshots

> **Diese Bilder sind aktuell SVG-Mockups, keine echten Screenshots.**

Sie zeigen das Layout und die Anordnung der UI-Elemente, sind aber von
Hand als SVG gezeichnet — sie spiegeln nicht die exakte Pixel-Darstellung
der laufenden App wider. Wer die App lokal betreibt, sollte sie durch
echte Screenshots ersetzen.

## Echte Screenshots erstellen

1. App lokal starten:
   ```bash
   php -S 127.0.0.1:8080
   ```
2. Browser auf <http://127.0.0.1:8080> öffnen, Demo-Daten ggf. laden
   (siehe README → *Demo-Daten*).
3. Pro Hauptansicht einen Screenshot anfertigen:
   - Dashboard → `dashboard.png`
   - Gas-Ansicht (mit aktivem Vertrag und Saldo-Karte) → `gas-view.png`
   - Korrelationsanalyse → `analyse.png`
   - Prognose → `prognose.png`
   - Migrationsdialog → `migration.png`
4. PNG-Dateien neben die SVG-Mockups legen. Im README oben die
   `.svg`-Endung in den Bildreferenzen durch `.png` ersetzen (oder
   die SVG-Dateien einfach löschen).

## Aktuelle Mockups

| Datei | Zeigt |
|---|---|
| `dashboard.svg` | 12-Monats-KPIs, Gas- und Strom-Verlaufscharts, letzte Ablesungen |
| `gas-view.svg` | Gas-Detailseite mit Status-Banner, Jahres-Pills, KPI-Grid, Saldo-Karte aktueller Vertrag und Vertragstabelle |
| `analyse.svg` | HGT-Korrelation mit allen vier Regressionsmodellen, R²-Vergleichstabelle, Anomalien |
| `prognose.svg` | 12-Monats-Forecast, Saldo der aktiven Verträge |
| `migration.svg` | Migrationsdialog für v0.9.0-Backup-Import |

## Anmerkungen zu den Mockups

- Die Werte (Verbrauch, Kosten, Saldo) in den Mockups sind illustrativ.
  Sie matchen NICHT die Demo-Daten unter `demo-data/`.
- Der Layout-Aufbau ist akkurat: 220 px linke Sidebar, 48 px Topbar,
  4-Spalten-KPI-Grid, 4-Spalten-Saldo-Grid (mit Verdict-Box rechts),
  Vertragstabelle mit den Spalten *Anbieter/Tarif*, *Zeitraum*, *Status*,
  *Tarif*, *Abschlag*, *Verbraucht*, *Bezahlt*, *Bonus*, *Saldo heute*,
  *Erw. Saldo*.
- Die Farbpalette in den SVGs entspricht den CSS-Tokens in
  `public/css/tokens.css` (orange Gas `#ff7b2e`, mint-grün Strom
  `#2de8a4`, blau Wasser `#3b82f6`, Akzent-Blau `#4a90e2`, gelb
  `#f5c842` für Abschlag/Warning, violett `#8b5cf6` für CO₂).
