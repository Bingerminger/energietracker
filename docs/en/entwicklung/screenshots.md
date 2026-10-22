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

1. **Server with its own empty data directory** — your own data stays
   untouched:

   ```bash
   rm -rf /tmp/etshots && mkdir /tmp/etshots
   ET_DATA_DIR=/tmp/etshots php -S 127.0.0.1:8910 router.php
   ```

2. **Load the sample household** — since v3.4.0 the same way as “Try with
   sample data”: carried forward to today (readings, reminders, weather) and
   with names and notes in the language of the request. Then set the “Expert”
   level so that every card appears:

   ```bash
   A=http://127.0.0.1:8910/api.php/api
   curl -s $A/settings > /dev/null
   curl -s -X POST -H 'Content-Type: application/json' -H 'X-ET-Language: de' \
     --data '{"force":true}' $A/demo/import
   curl -s -X PATCH -H 'Content-Type: application/json' \
     --data '{"ui_level":"expert"}' $A/settings
   ```

   For the English images use a second instance with its own directory: set
   `{"language":"en"}` before the import and import with `X-ET-Language: en`.
   Since v3.1.0 a language the browser has stored
   for this device (`localStorage['et-language']`) takes precedence over the
   setting — remove it first or set it to match. A copy of `demo-data/`
   (`cp -R demo-data /tmp/etshots`) still works, but shows the state of the
   export with German names.

3. **Browser**: light (`localStorage['et-theme'] = 'light'` — a browser profile
   remembers the choice per address), window 1440 × 1500 or 1440 × 900, for the
   iPhone 375 × 812 at double pixel density (image 750 × 1624). Since v3.7.0 the
   **browser region matching the language**: `en-GB` for the English images
   (Playwright `locale: 'en-GB'`, Chrome `--lang=en-GB`), `de-DE` for the German
   ones. The app formats dates and numbers itself by language and country; date
   fields (`<input type="date">`) and their placeholder come from the browser,
   though — with a German region the English contract dialog showed
   “01.01.2026” and “tt.mm.jjjj” instead of “01/01/2026” and “dd/mm/yyyy”. Plus
   the time zone `Europe/Berlin`, like the demo data.
4. **Before every capture** unregister the service worker and clear its cache
   (in the console: unregister every `navigator.serviceWorker.getRegistrations()`,
   delete `caches.keys()`) — otherwise the browser shows old files between two
   states of the same version. After CSS changes `touch public/js/app.js`. A
   fresh browser profile (Playwright: `serviceWorkers: 'block'`) saves this.
5. **Wait until the content is there** (not "Loading…"), then capture. Charts
   animate again after every resize — wait a moment after expanding or
   switching; a full-page capture changes the window size and catches the
   charts empty.
6. Store the file under the same name; the docs refer to the names.
   `pngquant --quality=88-100 --strip` roughly halves the images without
   visible loss.

The welcome state needs an empty data directory (`ET_DATA_DIR` pointing to an
empty folder); close the setup assistant with Escape. “Reminders & tips →
Done” only fills up after a few notices have been ticked off (✓ on the
overview, hide a recommendation) — so capture it after the overview and the
reminders. The sign-in screen and “Sign-in & access” come from a separate
instance with sign-in switched on.

## The images

| File | View | Address | Window | English |
|---|---|---|---|---|
| `dashboard.png` | Overview | `#/dashboard` | 1440 × 1500 | ✓ |
| `willkommen.png` | Overview without data | `#/dashboard`, empty directory | 1440 × 900 | ✓ |
| `zaehlerstaende.png` | Meter-reading capture | `#/zaehlerstaende` | 1440 × 1500 | ✓ |
| `pruefung-zaehlerstand.png` | Question on a jump | capture with a conspicuous value → “Save all” | 1440 × 900 | — |
| `gas-view.png` | Consumption gas | `#/utility/gas` | 1440 × 1500 | ✓ |
| `heizoel-view.png` | Consumption heating oil | `#/utility/heizoel` | 1440 × 1500 | ✓ |
| `pv.png` | PV feed-in with energy flow | `#/utility/pv_einspeisung` | 1440 × 1280 | ✓ |
| `zaehler-vertraege.png` | Contract list of a utility (v3.4.0) | `#/utility/gas/contracts`, “Ended” expanded | 1440 × 1500 | ✓ |
| `vertragsdialog.png` | Contract dialog in the form grid (v3.4.0) | contract list → “Edit” | 1440 × 1500 | ✓ |
| `import-vorschau.png` | CSV import preview | Meters → CSV import | 1440 × 1500 | — |
| `navigation-mac.png` | Contracts & payments | `#/contracts` | 1440 × 900 | ✓ |
| `navigation-iphone.png` | Overview on the iPhone | `#/dashboard` | 375 × 812 | ✓ |
| `erfassen-iphone.png` | Add sheet on the iPhone | ＋ Add | 375 × 812 | ✓ |
| `analyse.png` | Analysis | `#/analysis` | 1440 × 1500 | ✓ |
| `prognose.png` | Forecast workshop (v3.6.0) | `#/forecast`, example “Cold winter” clicked | 1440 × 1500 | ✓ |
| `jahresbericht.png` | Print view of the annual report (v3.4.0) | `#/report/print?year=2025` | 1440 × 1500 | ✓ |
| `tarifvergleich.png` | Tariff switch | `#/tariffs` | 1440 × 1522 | ✓ |
| `empfehlungen.png` | Recommendations | `#/recommendations` | 1440 × 1500 | ✓ |
| `termine.png` | Reminders & maintenance | `#/reminders` | 1440 × 1500 | ✓ |
| `erledigt.png` | Reminders & tips → Done (v3.4.0) | `#/activity` | 1440 × 900 | ✓ |
| `temperaturen.png` | Weather data | `#/temperatures` | 1440 × 1500 | ✓ |
| `einstellungen.png` | Settings | `#/settings` | 1440 × 1500 | ✓ |
| `laenderprofil.png` | Apply country profile | Settings → change country | 1440 × 900 | — |
| `einstellungen-sicherheit.png` | Access: security check, sign-in, host names, API keys | `#/settings/access` | detail | — |
| `anmeldung.png` | Sign-in screen | with sign-in switched on | 1440 × 900 | — |
| `hilfe.png` | Help | `#/help` | 1440 × 900 | ✓ |
| `handbuch.png` | Handbook in the app (v3.4.0) | `#/help/doc/anleitungen/anwendungsfaelle.md` or `#/help/doc/en/anleitungen/anwendungsfaelle.md` | 1440 × 900 | ✓ |
| `erklaerung-mac.png` | ⓘ explanation on the Mac | Gas → monthly table → ⓘ at HDD | detail | ✓ |
| `erklaerung-iphone.png` | ⓘ explanation as a sheet | as above | 375 × 812 | ✓ |

A test checks that every linked image exists ([Tests](tests.md)).

## Promo images

The images at the top of the README and on the GitHub project page come from
two standalone HTML pages under `docs/assets/promo/`. They embed the
screenshots of this directory — renew the screenshots first, then the promo
images. The directory is not part of the Docker image (`.dockerignore`, a test
checks this).

| File | Template | Size | Language |
|---|---|---|---|
| `banner.png` | `promo.html` | 1720 × 860 | English, in the README with `width="860"` |
| `banner.de.png` | `promo.html?lang=de` | 1720 × 860 | German, in README.de.md |
| `social-preview.png` | `social-preview.html` | 1280 × 640 | English |

1. **Serve statically** so that the relative paths (screenshots, font from
   `public/vendor/fonts/`, icon from `public/img/`) resolve:
   `php -S 127.0.0.1:8920` in the project directory.
2. **Capture** at exactly the window size from the table, pixel density 1,
   after the font has loaded (`document.fonts.ready`), e.g.
   `http://127.0.0.1:8920/docs/assets/promo/promo.html?lang=de`.
3. **Shrink** with `pngquant --quality=90-100 --strip`.
4. **Upload the social preview:** GitHub does not read it from the repository.
   Upload `docs/assets/promo/social-preview.png` by hand under
   *Settings → General → Social preview* — again after every change.

Design: colours from `public/css/tokens.css` (light theme) and the utility
colours, typeface DM Sans. Both pages keep their texts in a `TEXT` table
(en/de); every claim must hold (open source under the AGPL, self-hosted, no
cloud, no telemetry) and only sample data is shown.

---

[← Compendium index](../README.md)
