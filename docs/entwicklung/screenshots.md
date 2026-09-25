# Screenshots

**Deutsch** · [English](../en/entwicklung/screenshots.md)

[← Kompendium-Index](../README.md)

Die Bilder der Doku sind echte Aufnahmen der laufenden App mit dem
mitgelieferten Beispieldatensatz ([`demo-data/`](../../demo-data/)) — keine
Attrappen. Sie liegen unter `docs/ui/screenshots/` (deutsche Oberfläche) und
`docs/ui/screenshots/en/` (englische Oberfläche); die deutsche Doku zeigt die
einen, die englische die anderen. Bis v2.13 lagen unter `docs/screenshots/`
noch fünf SVG-Attrappen aus v1.0.2.

---

## Aufnehmen

1. **Server gegen eine Kopie der Beispieldaten** — die eigenen Daten bleiben
   unberührt, die Bilder sind reproduzierbar:

   ```bash
   rm -rf /tmp/etshots && cp -R demo-data /tmp/etshots
   ET_DATA_DIR=/tmp/etshots php -S 127.0.0.1:8910 router.php
   ```

2. **Für die englischen Bilder** die Sprache der Kopie umstellen:

   ```bash
   curl -s -X PATCH -H 'Content-Type: application/json' \
     --data '{"language":"en"}' http://127.0.0.1:8910/api.php/api/settings
   ```

3. **Browser**: hell (`localStorage['et-theme'] = 'light'` — ein Browserprofil
   merkt sich die Wahl je Adresse), Fenster 1440 × 1500 bzw. 1440 × 900, fürs
   iPhone 375 × 812.
4. **Vor jeder Aufnahme** den Service Worker abmelden und seinen Cache leeren
   (in der Konsole: alle `navigator.serviceWorker.getRegistrations()`
   abmelden, `caches.keys()` löschen) — sonst zeigt der Browser zwischen zwei
   Ständen derselben Version alte Dateien. Nach CSS-Änderungen
   `touch public/js/app.js`.
5. **Warten, bis der Inhalt steht** (nicht „Lädt …“), dann aufnehmen. Diagramme
   animieren nach jeder Größenänderung neu — nach dem Aufklappen oder
   Umschalten kurz warten.
6. Die Datei unter demselben Namen ablegen; die Doku verweist auf die Namen.

Für den Willkommenszustand braucht es ein leeres Datenverzeichnis
(`ET_DATA_DIR` auf einen leeren Ordner).

## Die Bilder

| Datei | Ansicht | Adresse | Fenster | Englisch |
|---|---|---|---|---|
| `dashboard.png` | Übersicht | `#/dashboard` | 1440 × 1500 | ✓ |
| `willkommen.png` | Übersicht ohne Daten | `#/dashboard`, leeres Verzeichnis | 1440 × 900 | ✓ |
| `zaehlerstaende.png` | Zählerstand-Erfassung | `#/zaehlerstaende` | 1440 × 1500 | ✓ |
| `pruefung-zaehlerstand.png` | Rückfrage bei einem Sprung | Erfassung mit auffälligem Wert | Ausschnitt | — |
| `gas-view.png` | Verbrauch Gas | `#/utility/gas` | 1440 × 1500 | ✓ |
| `heizoel-view.png` | Verbrauch Heizöl | `#/utility/heizoel` | 1440 × 1500 | ✓ |
| `pv.png` | PV-Einspeisung | `#/utility/pv_einspeisung` | 1440 × 900 | ✓ |
| `zaehler-vertraege.png` | Zähler | `#/utility/strom/meters` | 1440 × 1500 | ✓ |
| `import-vorschau.png` | Vorschau des CSV-Imports | Zähler → CSV-Import | Ausschnitt | — |
| `navigation-mac.png` | Verträge & Abschläge | `#/contracts` | 1440 × 900 | ✓ |
| `navigation-iphone.png` | Übersicht am iPhone | `#/dashboard` | 375 × 812 | — |
| `erfassen-iphone.png` | Erfassen-Blatt am iPhone | ＋ Erfassen | 375 × 812 | — |
| `analyse.png` | Analyse | `#/analysis` | 1440 × 1500 | ✓ |
| `prognose.png` | Prognose | `#/forecast` | 1440 × 1500 | ✓ |
| `tarifvergleich.png` | Wechsel prüfen | `#/tariffs` | 1440 × 1522 | ✓ |
| `empfehlungen.png` | Empfehlungen | `#/recommendations` | 1440 × 1500 | ✓ |
| `termine.png` | Termine & Wartung | `#/reminders` | 1440 × 1500 | ✓ |
| `temperaturen.png` | Wetterdaten | `#/temperatures` | 1440 × 1500 | ✓ |
| `einstellungen.png` | Einstellungen | `#/settings` | 1440 × 1500 | ✓ |
| `laenderprofil.png` | Länderprofil übernehmen | Einstellungen → Land wechseln | Ausschnitt | — |
| `einstellungen-sicherheit.png` | Anmeldung & Zugriff | `#/settings/access` | Ausschnitt | — |
| `anmeldung.png` | Anmeldebildschirm | mit eingeschalteter Anmeldung | 1440 × 900 | — |
| `hilfe.png` | Hilfe | `#/help` | 1440 × 900 | ✓ |
| `erklaerung-mac.png` | ⓘ-Erklärung am Mac | Gas → Monatstabelle → ⓘ an HGT | Ausschnitt | ✓ |
| `erklaerung-iphone.png` | ⓘ-Erklärung als Blatt | wie oben | 375 × 812 | — |

Ein Test prüft, dass jedes verlinkte Bild existiert
([Tests](tests.md)).

---

[← Kompendium-Index](../README.md)
