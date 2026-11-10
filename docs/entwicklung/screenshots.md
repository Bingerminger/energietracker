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

1. **Server mit eigenem, leerem Datenverzeichnis** — die eigenen Daten bleiben
   unberührt:

   ```bash
   rm -rf /tmp/etshots && mkdir /tmp/etshots
   ET_DATA_DIR=/tmp/etshots php -S 127.0.0.1:8910 router.php
   ```

2. **Beispielhaushalt laden** — seit v3.4.0 so wie „Mit Beispieldaten
   ausprobieren“: bis heute fortgeschrieben (Ablesungen, Termine, Wetter) und
   mit Namen und Notizen in der Sprache der Anfrage. Danach die Stufe
   „Experte“, damit jede Karte erscheint:

   ```bash
   A=http://127.0.0.1:8910/api.php/api
   curl -s $A/settings > /dev/null
   curl -s -X POST -H 'Content-Type: application/json' -H 'X-ET-Language: de' \
     --data '{"force":true}' $A/demo/import
   curl -s -X PATCH -H 'Content-Type: application/json' \
     --data '{"ui_level":"expert","weather_auto_fill":false}' $A/settings
   ```

   `weather_auto_fill: false` *(v3.9.0)*: Der Import schreibt das Wetter schon
   fort; ohne den Schalter fragt der Browser beim Aufnehmen Open-Meteo.

   Für die englischen Bilder eine zweite Instanz mit eigenem Verzeichnis:
   vor dem Import `{"language":"en"}` setzen und mit `X-ET-Language: en`
   importieren. Seit v3.1.0 gilt eine Sprache, die der Browser für dieses Gerät
   gespeichert hat (`localStorage['et-language']`), vor der Einstellung — sie
   vorher entfernen oder gleich passend setzen. Eine Kopie von `demo-data/`
   (`cp -R demo-data /tmp/etshots`) geht weiterhin, zeigt aber den Stand des
   Exports mit deutschen Namen.

3. **Browser**: hell (`localStorage['et-theme'] = 'light'` — ein Browserprofil
   merkt sich die Wahl je Adresse), Fenster 1440 × 1500 bzw. 1440 × 900, fürs
   iPhone 375 × 812 mit doppelter Pixeldichte (Bild 750 × 1624). Seit v3.7.0
   die **Browser-Region passend zur Sprache**: für die englischen Bilder
   `en-GB` (Playwright `locale: 'en-GB'`, Chrome `--lang=en-GB`), für die
   deutschen `de-DE`. Die App formatiert Datum und Zahlen selbst nach Sprache
   und Land; Datumsfelder (`<input type="date">`) und ihr Platzhalter kommen
   aber vom Browser — mit deutscher Region stand im englischen Vertragsdialog
   „01.01.2026“ und „tt.mm.jjjj“ statt „01/01/2026“ und „dd/mm/yyyy“. Dazu die
   Zeitzone `Europe/Berlin` wie die Beispieldaten.
4. **Vor jeder Aufnahme** den Service Worker abmelden und seinen Cache leeren
   (in der Konsole: alle `navigator.serviceWorker.getRegistrations()`
   abmelden, `caches.keys()` löschen) — sonst zeigt der Browser zwischen zwei
   Ständen derselben Version alte Dateien. Nach CSS-Änderungen
   `touch public/js/app.js`. Ein frisches Browserprofil (Playwright:
   `serviceWorkers: 'block'`) erspart das.
5. **Warten, bis der Inhalt steht** (nicht „Lädt …“), dann aufnehmen. Diagramme
   animieren nach jeder Größenänderung neu — nach dem Aufklappen oder
   Umschalten kurz warten; eine Ganzseitenaufnahme ändert die Fenstergröße und
   erwischt die Diagramme leer.
6. Die Datei unter demselben Namen ablegen; die Doku verweist auf die Namen.
   Mit `pngquant --quality=88-100 --strip` lassen sich die Bilder ohne
   sichtbaren Verlust etwa halbieren.

„Zu tun → Verlauf“ ist erst nach ein paar erledigten Hinweisen gefüllt (✓ auf der Übersicht, Empfehlung
ausblenden) — also nach Übersicht und Terminen aufnehmen. Willkommen, Einrichtungsassistent und Anmeldung
brauchen eine eigene Instanz (siehe unten).

## Die Bilder

<!-- doc-claim: screenshots-de-en (ScreenshotsDocTest) -->
**Seit v3.12.0 nimmt ein Skript alle Bilder der Tabelle auf:** `tools/build-screenshots.mjs` lädt den
Beispielhaushalt, stellt die Stufe Experte ein und nimmt jede Ansicht hell auf, am Mac und am iPhone. Dazu
die Bilder für das README (hell und dunkel) unter `docs/assets/promo/screens/`. Danach
`pngquant --quality=88-100 --strip`, **kein Bild über 150 KB**. **Jedes Bild zeigt die Oberfläche von v3.12.0,
jedes auf Deutsch und Englisch.**

**Drei Läufe je Sprache**, jeder gegen einen eigenen Server mit leerem Datenverzeichnis:

| Lauf | Bilder | Was er tut |
|---|---|---|
| ohne Schalter | alle übrigen | lädt den Beispielhaushalt |
| `--leer` | `willkommen`, `einrichtung`, `einrichtung-zaehler` | lädt nichts; der Assistent öffnet von selbst, für das Willkommen schließt Escape ihn |
| `--anmeldung` | `anmeldung`, `einstellungen-sicherheit` | lädt den Beispielhaushalt und schaltet die Anmeldung mit einem Beispielpasswort ein; **danach ist die Instanz gesperrt** |

```bash
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8910 de /tmp/shots-de
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8912 de /tmp/shots-de '' --leer
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8913 de /tmp/shots-de '' --anmeldung
NODE_PATH=… node tools/build-screenshots.mjs http://127.0.0.1:8911 en /tmp/shots-en   # usw. für Englisch
```

Ein vierter Wert nimmt nur einzelne Bilder auf (`prognose,werkstatt-pv`). **Was das Skript dazu tut:**

- **Dialoge und Ausschnitte öffnet es selbst:** Brief, Vertrag, Messkonzept, CSV-Vorschau, Rückfrage,
  Länderprofil, ⓘ-Erklärung.
- **Die CSV-Vorschau** baut ihre Zeilen von der letzten Ablesung des Hauptzählers Gas aus, damit jede Art
  Hinweis vorkommt (unverändert, Rückgang, Tippfehler, Datum doppelt, Zukunft). Importiert wird nichts.
- **„Ablesung prüfen“** senkt dafür einen Stand der Beispieldaten und stellt ihn danach wieder her. Die
  Rückfrage bei einem Sprung speichert nichts.
- **Datumsfelder:** Englisch mit der Headless-Shell und `--lang=en-GB` („dd/mm/yyyy“), Deutsch mit dem
  vollen Chromium, das am Mac der Systemsprache folgt („tt.mm.jjjj“ am deutschen Mac).

| Datei | Ansicht | Adresse | Fenster |
|---|---|---|---|
| `dashboard.png` | Übersicht: Antworten, Kachel je Art | `#/dashboard` | 1440 × 1500 |
| `zu-tun.png` | Zu tun: Offen mit Fristen, Empfehlungen, Post | `#/todo` | 1440 × 900 |
| `empfehlungen.png` | Zu tun, Filter Empfehlungen | `#/todo?art=rec` | 1440 × 1500 |
| `termine.png` | Zu tun → Termine | `#/todo/dates` | 1440 × 1500 |
| `erledigt.png` | Zu tun → Verlauf | `#/todo/history` | 1440 × 900 |
| `zaehlerstaende.png` | Zählerstand-Erfassung | `#/zaehlerstaende` | 1440 × 1500 |
| `gas-view.png` | Verbrauch Gas, Sicht Überblick | `#/utility/gas` | 1440 × 1500 |
| `strom-view.png` | Verbrauch Strom | `#/utility/strom` | 1440 × 1500 |
| `heizoel-view.png` | Verbrauch Heizöl | `#/utility/heizoel` | 1440 × 1500 |
| `pv.png` | PV-Einspeisung | `#/utility/pv_einspeisung` | 1440 × 1280 |
| `navigation-mac.png` | Kosten & Verträge | `#/contracts` | 1440 × 900 |
| `fruehere-vertraege.png` | Kosten → Frühere Verträge | `#/contracts/past` | 1440 × 900 |
| `tarifvergleich.png` | Wechsel prüfen | `#/tariffs` | 1440 × 1500 |
| `kennzahlen.png` | Auswertungen → Kennzahlen | `#/figures` | 1440 × 1500 |
| `analyse.png` | Analyse | `#/analysis` | 1440 × 1500 |
| `haushalt.png` | Haushalt → Zähler | `#/household/meters` | 1440 × 900 |
| `personen.png` | Haushalt → Personen | `#/household/people` | 1440 × 900 |
| `verbindungen.png` | Verbindungen | `#/connections` | 1440 × 900 |
| `ki-assistenten.png` | Verbindungen → KI-Assistenten | `#/connections/ai` | 1440 × 900 |
| `ha-benachrichtigungen.png` | Verbindungen → Benachrichtigungen | `#/connections/homeassistant/notifications` | 1440 × 900 |
| `einstellungen-heimnetz.png` | Verbindungen → Sicherheit im Heimnetz | `#/connections/homenet` | 1440 × 900 |
| `temperaturen.png` | Verbindungen → Wetterdaten | `#/connections/weather` | 1440 × 1500 |
| `einstellungen.png` | Einstellungen mit Suche und „Für dich“ | `#/settings` | 1440 × 1500 |
| `hilfe.png` | Hilfe mit Linkreihe und Wegweiser | `#/help` | 1440 × 900 |
| `handbuch.png` | Handbuch in der App | `#/help/doc/anleitungen/anwendungsfaelle.md` bzw. `…/en/…` | 1440 × 900 |
| `navigation-iphone.png` | Übersicht am iPhone | `#/dashboard` | 390 × 844 |
| `mehr-iphone.png` | „Mehr“ am iPhone, unten die Fußzeile | `#/dashboard`, „Mehr“ | 390 × 844 |
| `erfassen-iphone.png` | Erfassen-Blatt am iPhone | `#/dashboard`, ＋ | 390 × 844 |
| `prognose.png` | Prognose-Werkstatt, Beispiel „Kalter Winter“ | `#/forecast` | 1440 × 1500 |
| `werkstatt-heizoel.png` | Werkstatt beim Heizöl, Beispiel „Wärmepumpe statt Kessel“ | `#/forecast`, Art Heizöl | 1440 × 1440 |
| `werkstatt-pv.png` | Werkstatt bei der PV, Beispiel „Speicher dazu“ | `#/forecast`, Art PV-Einspeisung | 1440 × 1440 |
| `jahresbericht.png` | Jahresbericht 2025 | `#/report/print?year=2025` | 1440 × 1500 |
| `uebersicht-abrechnungsjahr.png` | Übersicht im Abrechnungsjahr, erste Reihe der Kacheln | `#/dashboard`, „Abrechnungsjahr“ | Ausschnitt |
| `zaehler-vertraege.png` | Verträge einer Art, beendete aufgeklappt | `#/utility/gas/contracts` | 1440 × 1500 |
| `vertragsdialog.png` | Vertragsdialog | Vertragsliste → „Bearbeiten“ | 1440 × 1500 |
| `vertragskonflikt.png` | Hinweis bei widersprüchlichen Verträgen | `#/utility/wasser/contracts` | 1440 × 900 |
| `rechte-hinweis.png` | Zu tun, Filter Rechte | `#/todo?art=rights` | 1440 × 900 |
| `brief-dialog.png` | Brief entwerfen | Rechte → „Brief entwerfen“ | 1440 × 900 |
| `ablesung-pruefen.png` | „Heizgrenze anpassen“ und „Ablesung prüfen“ | `#/todo`, ein Stand gesenkt | Ausschnitt |
| `messkonzept.png` | Assistent „Messkonzept einrichten“, Schritt 2 | `#/household/meters?art=strom&concept=1` | 1440 × 900 |
| `willkommen.png` | Übersicht ohne Daten | `#/dashboard`, `--leer` | 1440 × 900 |
| `einrichtung.png` | Einrichtungsassistent, „Wer bist du?“ | `--leer` | 1440 × 900 |
| `einrichtung-zaehler.png` | Einrichtungsassistent, Zähler | `--leer`, „Eigenheim mit Wärmepumpe und PV“ | 1440 × 900 |
| `erklaerung-mac.png` | ⓘ-Erklärung am Mac | `#/utility/gas?view=months`, ⓘ am Abrechnungsjahr | Ausschnitt |
| `erklaerung-iphone.png` | ⓘ-Erklärung als Blatt | wie oben | 375 × 812 |
| `import-vorschau.png` | Vorschau des CSV-Imports | `#/household/meters?art=gas` → „CSV-Import“ | 1440 × 1500 |
| `pruefung-zaehlerstand.png` | Rückfrage bei einem Sprung | `#/zaehlerstaende` | 1440 × 900 |
| `laenderprofil.png` | Länderprofil übernehmen | `#/settings/region`, Land Schweiz | 1440 × 900 |
| `einstellungen-sicherheit.png` | Zugang und Sicherheit | `#/settings/access`, `--anmeldung` | Ausschnitt |
| `anmeldung.png` | Anmeldebildschirm | `--anmeldung` | 1440 × 900 |

Ein Test prüft, dass jedes verlinkte Bild existiert
([Tests](tests.md)).

## Werbebilder

**Seit v3.12.0 zeigt das README Logo, Symbole und Bilder aus `docs/assets/promo/`:** `logo.svg` und
`icons/*.svg` erzeugt `tools/build-logo.mjs` (dieselben Grafiken wie in der App, dazu alle
`public/img/icon-*.png` und `favicon.ico`), die Bilder unter `screens/` das Aufnahmeskript oben. Das
Banner und die Social Preview entstehen aus zwei eigenständigen HTML-Seiten unter `docs/assets/promo/`. Sie binden die
Screenshots dieses Verzeichnisses ein — erst die Screenshots erneuern, dann
die Werbebilder. Das Verzeichnis gehört nicht ins Docker-Image
(`.dockerignore`, ein Test prüft das).

| Datei | Vorlage | Größe | Sprache |
|---|---|---|---|
| `banner.png` | `promo.html` | 1720 × 860 | Englisch, für Beiträge (seit v3.12.0 nicht mehr im README) |
| `banner.de.png` | `promo.html?lang=de` | 1720 × 860 | Deutsch, für Beiträge (seit v3.12.0 nicht mehr im README) |
| `social-preview.png` | `social-preview.html` | 1280 × 640 | Englisch |

1. **Statisch ausliefern**, damit die relativen Pfade (Screenshots, Schrift
   aus `public/vendor/fonts/`, Symbol aus `public/img/`) greifen:
   `php -S 127.0.0.1:8920` im Projektverzeichnis.
2. **Aufnehmen** mit genau der Fenstergröße aus der Tabelle, Pixeldichte 1,
   nachdem die Schrift geladen ist (`document.fonts.ready`), z. B.
   `http://127.0.0.1:8920/docs/assets/promo/promo.html?lang=de`.
3. **Verkleinern** mit `pngquant --quality=90-100 --strip`.
4. **Social Preview hochladen:** GitHub liest sie nicht aus dem Repository.
   Unter *Settings → General → Social preview* von Hand
   `docs/assets/promo/social-preview.png` hochladen — nach jeder Änderung neu.

Gestaltung: Farben aus `public/css/tokens.css` (helles Theme) und den Farben
der Verbrauchsarten, Schrift DM Sans. Texte stehen in beiden Seiten als
Tabelle `TEXT` (en/de); alle Aussagen müssen stimmen (Open Source unter AGPL,
selbst gehostet, keine Cloud, keine Telemetrie) und gezeigt werden nur
Beispieldaten.

---

[← Kompendium-Index](../README.md)
