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
     --data '{"ui_level":"expert"}' $A/settings
   ```

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

Für den Willkommenszustand braucht es ein leeres Datenverzeichnis
(`ET_DATA_DIR` auf einen leeren Ordner); den Einrichtungsassistenten mit
Escape schließen. „Hinweise → Erledigt“ ist erst nach ein paar erledigten
Hinweisen gefüllt (✓ auf der Übersicht, Empfehlung ausblenden) — also nach
Übersicht und Terminen aufnehmen. Anmeldebildschirm und „Anmeldung & Zugriff“
entstehen in einer eigenen Instanz mit eingeschalteter Anmeldung.

## Die Bilder

| Datei | Ansicht | Adresse | Fenster | Englisch |
|---|---|---|---|---|
| `dashboard.png` | Übersicht | `#/dashboard` | 1440 × 1500 | ✓ |
| `willkommen.png` | Übersicht ohne Daten | `#/dashboard`, leeres Verzeichnis | 1440 × 900 | ✓ |
| `zaehlerstaende.png` | Zählerstand-Erfassung | `#/zaehlerstaende` | 1440 × 1500 | ✓ |
| `pruefung-zaehlerstand.png` | Rückfrage bei einem Sprung | Erfassung mit auffälligem Wert → „Alle speichern“ | 1440 × 900 | — |
| `gas-view.png` | Verbrauch Gas | `#/utility/gas` | 1440 × 1500 | ✓ |
| `heizoel-view.png` | Verbrauch Heizöl | `#/utility/heizoel` | 1440 × 1500 | ✓ |
| `pv.png` | PV-Einspeisung mit Energiefluss | `#/utility/pv_einspeisung` | 1440 × 1280 | ✓ |
| `zaehler-vertraege.png` | Vertragsliste einer Art (v3.4.0) | `#/utility/gas/contracts`, „Beendet“ aufgeklappt | 1440 × 1500 | ✓ |
| `vertragsdialog.png` | Vertragsdialog im Formularraster (v3.4.0) | Vertragsliste → „Bearbeiten“ | 1440 × 1500 | ✓ |
| `import-vorschau.png` | Vorschau des CSV-Imports | Zähler → CSV-Import | 1440 × 1500 | — |
| `navigation-mac.png` | Verträge & Abschläge | `#/contracts` | 1440 × 900 | ✓ |
| `navigation-iphone.png` | Übersicht am iPhone | `#/dashboard` | 375 × 812 | ✓ |
| `erfassen-iphone.png` | Erfassen-Blatt am iPhone | ＋ Erfassen | 375 × 812 | ✓ |
| `analyse.png` | Analyse | `#/analysis` | 1440 × 1500 | ✓ |
| `prognose.png` | Prognose-Werkstatt (v3.6.0) | `#/forecast`, Beispiel „Kalter Winter“ angeklickt | 1440 × 1500 | ✓ |
| `jahresbericht.png` | Druckansicht des Jahresberichts (v3.4.0) | `#/report/print?year=2025` | 1440 × 1500 | ✓ |
| `tarifvergleich.png` | Wechsel prüfen | `#/tariffs` | 1440 × 1522 | ✓ |
| `empfehlungen.png` | Empfehlungen | `#/recommendations` | 1440 × 1500 | ✓ |
| `termine.png` | Termine & Wartung | `#/reminders` | 1440 × 1500 | ✓ |
| `erledigt.png` | Hinweise → Erledigt (v3.4.0) | `#/activity` | 1440 × 900 | ✓ |
| `temperaturen.png` | Wetterdaten | `#/temperatures` | 1440 × 1500 | ✓ |
| `einstellungen.png` | Einstellungen | `#/settings` | 1440 × 1500 | ✓ |
| `laenderprofil.png` | Länderprofil übernehmen | Einstellungen → Land wechseln | 1440 × 900 | — |
| `einstellungen-sicherheit.png` | Zugriff: Sicherheits-Check, Anmeldung, Hostnamen, API-Schlüssel | `#/settings/access` | Ausschnitt | — |
| `anmeldung.png` | Anmeldebildschirm | mit eingeschalteter Anmeldung | 1440 × 900 | — |
| `hilfe.png` | Hilfe | `#/help` | 1440 × 900 | ✓ |
| `handbuch.png` | Handbuch in der App (v3.4.0) | `#/help/doc/anleitungen/anwendungsfaelle.md` bzw. `#/help/doc/en/anleitungen/anwendungsfaelle.md` | 1440 × 900 | ✓ |
| `erklaerung-mac.png` | ⓘ-Erklärung am Mac | Gas → Monatstabelle → ⓘ an HGT | Ausschnitt | ✓ |
| `erklaerung-iphone.png` | ⓘ-Erklärung als Blatt | wie oben | 375 × 812 | ✓ |

Ein Test prüft, dass jedes verlinkte Bild existiert
([Tests](tests.md)).

## Werbebilder

Die Bilder oben im README und auf der GitHub-Projektseite entstehen aus zwei
eigenständigen HTML-Seiten unter `docs/assets/promo/`. Sie binden die
Screenshots dieses Verzeichnisses ein — erst die Screenshots erneuern, dann
die Werbebilder. Das Verzeichnis gehört nicht ins Docker-Image
(`.dockerignore`, ein Test prüft das).

| Datei | Vorlage | Größe | Sprache |
|---|---|---|---|
| `banner.png` | `promo.html` | 1720 × 860 | Englisch, im README mit `width="860"` |
| `banner.de.png` | `promo.html?lang=de` | 1720 × 860 | Deutsch, in README.de.md |
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
