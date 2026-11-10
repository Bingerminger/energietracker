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
     --data '{"ui_level":"expert","weather_auto_fill":false}' $A/settings
   ```

   `weather_auto_fill: false` *(v3.9.0)*: the import already carries the
   weather forward; without the switch the browser asks Open-Meteo while you
   capture.

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

“To do → History” only fills up after a few notices have been ticked off (✓ on the overview, hide a
recommendation) — so capture it after the overview and the reminders. Welcome, setup assistant and sign-in
need an instance of their own (see below).

## The images

<!-- doc-claim: screenshots-de-en (ScreenshotsDocTest) -->
**Since v3.12.0 a script takes every image of the table:** `tools/build-screenshots.mjs` loads the sample
household, sets the Expert level and takes every view in light mode, on the Mac and on the iPhone. It also
takes the README images (light and dark) under `docs/assets/promo/screens/`. Then
`pngquant --quality=88-100 --strip`, **no image above 150 KB**. **Every image shows the interface of v3.12.0,
each in German and English.**

**Three runs per language**, each against a server of its own with an empty data directory:

| Run | Images | What it does |
|---|---|---|
| no switch | all others | loads the sample household |
| `--leer` | `willkommen`, `einrichtung`, `einrichtung-zaehler` | loads nothing; the assistant opens by itself, Escape closes it for the welcome |
| `--anmeldung` | `anmeldung`, `einstellungen-sicherheit` | loads the sample household and switches sign-in on with a sample password; **the instance is locked afterwards** |

```bash
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8910 de /tmp/shots-de
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8912 de /tmp/shots-de '' --leer
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8913 de /tmp/shots-de '' --anmeldung
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8911 en /tmp/shots-en   # and so on for English
```

A fourth value takes single images only (`prognose,werkstatt-pv`). **What the script does on top:**

- **It opens dialogs and details itself:** letter, contract, metering concept, CSV preview, question on a
  jump, country profile, ⓘ explanation.
- **The CSV preview** builds its rows from the last reading of the main gas meter, so that every kind of
  note appears (unchanged, decrease, typo, date twice, future). Nothing is imported.
- **“Check reading”** lowers one reading of the sample data for it and restores it afterwards. The question
  on a jump saves nothing.
- **Date fields:** English with the headless shell and `--lang=en-GB` (“dd/mm/yyyy”), German with the full
  Chromium, which follows the system language on the Mac (“tt.mm.jjjj” on a German Mac).

| File | View | Address | Window |
|---|---|---|---|
| `dashboard.png` | Overview: answers, a tile per utility | `#/dashboard` | 1440 × 1500 |
| `zu-tun.png` | To do: open items with deadlines, recommendations, mail | `#/todo` | 1440 × 900 |
| `empfehlungen.png` | To do, filter Recommendations | `#/todo?art=rec` | 1440 × 1500 |
| `termine.png` | To do → Dates | `#/todo/dates` | 1440 × 1500 |
| `erledigt.png` | To do → History | `#/todo/history` | 1440 × 900 |
| `zaehlerstaende.png` | Entering meter readings | `#/zaehlerstaende` | 1440 × 1500 |
| `gas-view.png` | Consumption gas, view Overview | `#/utility/gas` | 1440 × 1500 |
| `strom-view.png` | Consumption electricity | `#/utility/strom` | 1440 × 1500 |
| `heizoel-view.png` | Consumption heating oil | `#/utility/heizoel` | 1440 × 1500 |
| `pv.png` | PV feed-in | `#/utility/pv_einspeisung` | 1440 × 1280 |
| `navigation-mac.png` | Costs & contracts | `#/contracts` | 1440 × 900 |
| `fruehere-vertraege.png` | Costs → Past contracts | `#/contracts/past` | 1440 × 900 |
| `tarifvergleich.png` | Tariff switch | `#/tariffs` | 1440 × 1500 |
| `kennzahlen.png` | Insights → Key figures | `#/figures` | 1440 × 1500 |
| `analyse.png` | Analysis | `#/analysis` | 1440 × 1500 |
| `haushalt.png` | Household → Meters | `#/household/meters` | 1440 × 900 |
| `personen.png` | Household → People | `#/household/people` | 1440 × 900 |
| `verbindungen.png` | Connections | `#/connections` | 1440 × 900 |
| `ki-assistenten.png` | Connections → AI assistants | `#/connections/ai` | 1440 × 900 |
| `ha-benachrichtigungen.png` | Connections → Notifications | `#/connections/homeassistant/notifications` | 1440 × 900 |
| `einstellungen-heimnetz.png` | Connections → Home network security | `#/connections/homenet` | 1440 × 900 |
| `temperaturen.png` | Connections → Weather data | `#/connections/weather` | 1440 × 1500 |
| `einstellungen.png` | Settings with search and “For you” | `#/settings` | 1440 × 1500 |
| `hilfe.png` | Help with link row and guide | `#/help` | 1440 × 900 |
| `handbuch.png` | Handbook in the app | `#/help/doc/anleitungen/anwendungsfaelle.md` or `…/en/…` | 1440 × 900 |
| `navigation-iphone.png` | Overview on the iPhone | `#/dashboard` | 390 × 844 |
| `mehr-iphone.png` | “More” on the iPhone, footer at the bottom | `#/dashboard`, “More” | 390 × 844 |
| `erfassen-iphone.png` | Add sheet on the iPhone | `#/dashboard`, ＋ | 390 × 844 |
| `prognose.png` | Forecast workshop, example “Cold winter” | `#/forecast` | 1440 × 1500 |
| `werkstatt-heizoel.png` | Workshop for heating oil, example “Heat pump instead of boiler” | `#/forecast`, utility heating oil | 1440 × 1440 |
| `werkstatt-pv.png` | Workshop for solar, example “Add a battery” | `#/forecast`, utility PV feed-in | 1440 × 1440 |
| `jahresbericht.png` | Annual report 2025 | `#/report/print?year=2025` | 1440 × 1500 |
| `uebersicht-abrechnungsjahr.png` | Overview in the billing year, first row of tiles | `#/dashboard`, “Billing year” | detail |
| `zaehler-vertraege.png` | Contracts of a utility, ended ones expanded | `#/utility/gas/contracts` | 1440 × 1500 |
| `vertragsdialog.png` | Contract dialog | contract list → “Edit” | 1440 × 1500 |
| `vertragskonflikt.png` | Note on contradicting contracts | `#/utility/wasser/contracts` | 1440 × 900 |
| `rechte-hinweis.png` | To do, filter Rights | `#/todo?art=rights` | 1440 × 900 |
| `brief-dialog.png` | Draft a letter | Rights → “Draft a letter” | 1440 × 900 |
| `ablesung-pruefen.png` | “Adjust the heating limit” and “Check the reading” | `#/todo`, one reading lowered | detail |
| `messkonzept.png` | Assistant “Set up metering concept”, step 2 | `#/household/meters?art=strom&concept=1` | 1440 × 900 |
| `willkommen.png` | Overview without data | `#/dashboard`, `--leer` | 1440 × 900 |
| `einrichtung.png` | Setup assistant, “Who are you?” | `--leer` | 1440 × 900 |
| `einrichtung-zaehler.png` | Setup assistant, meters | `--leer`, “Own house with heat pump and solar” | 1440 × 900 |
| `erklaerung-mac.png` | ⓘ explanation on the Mac | `#/utility/gas?view=months`, ⓘ at the billing year | detail |
| `erklaerung-iphone.png` | ⓘ explanation as a sheet | as above | 375 × 812 |
| `import-vorschau.png` | CSV import preview | `#/household/meters?art=gas` → “CSV import” | 1440 × 1500 |
| `pruefung-zaehlerstand.png` | Question on a jump | `#/zaehlerstaende` | 1440 × 900 |
| `laenderprofil.png` | Apply country profile | `#/settings/region`, country Switzerland | 1440 × 900 |
| `einstellungen-sicherheit.png` | Access and security | `#/settings/access`, `--anmeldung` | detail |
| `anmeldung.png` | Sign-in screen | `--anmeldung` | 1440 × 900 |

A test checks that every linked image exists ([Tests](tests.md)).

## Promo images

**Since v3.12.0 the README shows the logo, icons and images from `docs/assets/promo/`:** `logo.svg` and
`icons/*.svg` come from `tools/build-logo.mjs` (the same graphics as in the app, plus every
`public/img/icon-*.png` and `favicon.ico`), the images under `screens/` from the capture script above. The
banner and the social preview come from two standalone HTML pages under `docs/assets/promo/`. They embed the
screenshots of this directory — renew the screenshots first, then the promo
images. The directory is not part of the Docker image (`.dockerignore`, a test
checks this).

| File | Template | Size | Language |
|---|---|---|---|
| `banner.png` | `promo.html` | 1720 × 860 | English, for posts (not in the README since v3.12.0) |
| `banner.de.png` | `promo.html?lang=de` | 1720 × 860 | German, for posts (not in the README since v3.12.0) |
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
