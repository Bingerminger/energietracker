# Screenshots

[Deutsch](../../entwicklung/screenshots.md) · **English**

[← Compendium index](../README.md)

The images in these docs are real captures of the running app with the bundled
sample dataset ([`demo-data/`](../../../demo-data/)) — no mock-ups. They live
under `docs/ui/screenshots/` (German interface) and `docs/ui/screenshots/en/`
(English interface); the German docs show the former, the English docs the
latter. Up to v2.13, `docs/screenshots/` still held five SVG mock-ups from
v1.0.2.

---

## Capturing

1. **Server against a copy of the sample data** — your own data stays
   untouched, the images are reproducible:

   ```bash
   rm -rf /tmp/etshots && cp -R demo-data /tmp/etshots
   ET_DATA_DIR=/tmp/etshots php -S 127.0.0.1:8910 router.php
   ```

2. **For the English images** switch the copy's language:

   ```bash
   curl -s -X PATCH -H 'Content-Type: application/json' \
     --data '{"language":"en"}' http://127.0.0.1:8910/api.php/api/settings
   ```

3. **Browser**: light (`localStorage['et-theme'] = 'light'` — a browser profile
   remembers the choice per address), window 1440 × 1500 or 1440 × 900, for the
   iPhone 375 × 812.
4. **Before every capture** unregister the service worker and clear its cache
   (in the console: unregister every `navigator.serviceWorker.getRegistrations()`,
   delete `caches.keys()`) — otherwise the browser shows old files between two
   states of the same version. After CSS changes `touch public/js/app.js`.
5. **Wait until the content is there** (not "Loading…"), then capture. Charts
   animate again after every resize — wait a moment after expanding or
   switching.
6. Store the file under the same name; the docs refer to the names.

The welcome state needs an empty data directory (`ET_DATA_DIR` pointing to an
empty folder).

## The images

| File | View | Address | Window | English |
|---|---|---|---|---|
| `dashboard.png` | Overview | `#/dashboard` | 1440 × 1500 | ✓ |
| `willkommen.png` | Overview without data | `#/dashboard`, empty directory | 1440 × 900 | ✓ |
| `zaehlerstaende.png` | Meter-reading capture | `#/zaehlerstaende` | 1440 × 1500 | ✓ |
| `pruefung-zaehlerstand.png` | Question on a jump | capture with a conspicuous value | detail | — |
| `gas-view.png` | Consumption gas | `#/utility/gas` | 1440 × 1500 | ✓ |
| `heizoel-view.png` | Consumption heating oil | `#/utility/heizoel` | 1440 × 1500 | ✓ |
| `pv.png` | PV feed-in | `#/utility/pv_einspeisung` | 1440 × 900 | ✓ |
| `zaehler-vertraege.png` | Meters | `#/utility/strom/meters` | 1440 × 1500 | ✓ |
| `import-vorschau.png` | CSV import preview | Meters → CSV import | detail | — |
| `navigation-mac.png` | Contracts & payments | `#/contracts` | 1440 × 900 | ✓ |
| `navigation-iphone.png` | Overview on the iPhone | `#/dashboard` | 375 × 812 | — |
| `erfassen-iphone.png` | Add sheet on the iPhone | ＋ Add | 375 × 812 | — |
| `analyse.png` | Analysis | `#/analysis` | 1440 × 1500 | ✓ |
| `prognose.png` | Forecast | `#/forecast` | 1440 × 1500 | ✓ |
| `tarifvergleich.png` | Tariff switch | `#/tariffs` | 1440 × 1522 | ✓ |
| `empfehlungen.png` | Recommendations | `#/recommendations` | 1440 × 1500 | ✓ |
| `termine.png` | Reminders & maintenance | `#/reminders` | 1440 × 1500 | ✓ |
| `temperaturen.png` | Weather data | `#/temperatures` | 1440 × 1500 | ✓ |
| `einstellungen.png` | Settings | `#/settings` | 1440 × 1500 | ✓ |
| `laenderprofil.png` | Apply country profile | Settings → change country | detail | — |
| `einstellungen-sicherheit.png` | Sign-in & access | `#/settings/access` | detail | — |
| `anmeldung.png` | Sign-in screen | with sign-in switched on | 1440 × 900 | — |
| `hilfe.png` | Help | `#/help` | 1440 × 900 | ✓ |
| `erklaerung-mac.png` | ⓘ explanation on the Mac | Gas → monthly table → ⓘ at HDD | detail | ✓ |
| `erklaerung-iphone.png` | ⓘ explanation as a sheet | as above | 375 × 812 | — |

A test checks that every linked image exists ([Tests](tests.md)).

---

[← Compendium index](../README.md)
