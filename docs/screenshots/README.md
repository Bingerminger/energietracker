# Screenshots

Die UI-Referenz ([`docs/ui/01-views.md`](../ui/01-views.md)) zeigt **echte
Screenshots** der laufenden App. Die Bilddateien liegen unter
[`docs/ui/screenshots/`](../ui/screenshots/) (eine PNG je View).

> Hinweis: Dieses Verzeichnis (`docs/screenshots/`) enthält nur noch diese
> Anleitung. Die eigentlichen Bilder liegen bei der UI-Referenz unter
> `docs/ui/screenshots/`.

## Screenshots neu erzeugen

Bei UI-Änderungen die betroffene(n) View(s) neu aufnehmen:

1. **Server mit Demo-Daten starten** (frisches Verzeichnis, damit die Bilder
   reproduzierbar sind):
   ```bash
   rm -rf /tmp/etshots && cp -r demo-data /tmp/etshots
   ET_DATA_DIR=/tmp/etshots php -S 127.0.0.1:8910 router.php
   ```
2. **Im Browser** auf <http://127.0.0.1:8910> öffnen. Theme: hell
   (Topbar-Toggle), Viewport ~1440×900.
3. **Pro View** den Hash ansteuern und eine **Vollseiten**-Aufnahme machen.
   Dateinamen exakt wie in `ui/01-views.md` referenziert:

   | View | Hash | Datei |
   |---|---|---|
   | Dashboard | `#/dashboard` | `dashboard.png` |
   | Zählerstand-Erfassung | `#/zaehlerstaende` | `zaehlerstaende.png` |
   | Verbrauch kumulativ (Gas) | `#/utility/gas` | `gas-view.png` |
   | Verbrauch lieferbasiert (Heizöl) | `#/utility/heizoel` | `heizoel-view.png` |
   | Analyse | `#/analysis` | `analyse.png` |
   | Prognose | `#/forecast` | `prognose.png` |
   | Tarifvergleich | `#/tariffs` | `tarifvergleich.png` |
   | Empfehlungen | `#/recommendations` | `empfehlungen.png` |
   | Termine | `#/reminders` | `termine.png` |
   | Temperaturen | `#/temperatures` | `temperaturen.png` |
   | Einstellungen | `#/settings` | `einstellungen.png` |
   | Zähler & Verträge | `#/utility/strom/meters` | `zaehler-vertraege.png` |
   | PV | `#/utility/pv_einspeisung` | `pv.png` |

4. PNGs nach `docs/ui/screenshots/` legen (bestehende ersetzen).

> Die aktuellen Bilder wurden mit dem mitgelieferten Demo-Datensatz aufgenommen
> (Stand v1.9.2). Die gezeigten Werte entsprechen also exakt `demo-data/`.
