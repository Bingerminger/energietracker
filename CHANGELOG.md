# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

---

## [2.8.0] — 2026-09-25 — Rechnen wie die Abrechnung

MINOR-Release (F1013). Kein Schema-Bump, keine Datenmigration. Neue Felder
sind additiv; drei alte Felder sind als veraltet markiert und werden weiter
geliefert.

**Der Anlass.** Das Gesamtreview hat die Rechenkerne gegen synthetische Daten
mit bekannter Wahrheit geprüft. Auf sauberen Daten meldete die App jeden
Sommer der Heizarten als Anomalie, die Wetterbereinigung verglich Januar mit
Oktober („+58 %"), eine Prognose ohne eigenen Januar setzte für ihn weniger als
ein Zehntel an, und der Saldo zählte Abschläge nur für abgelesene Monate
(Review CALC-02, -03, -05, -06, -08, -12, -13, -14, -22, -30, FE-18, DOC-08, API-24, UI-36).

### ⚠️ Für bestehende Installationen

- **Werte ändern sich** — Klasse B der Stabilitätszusage: Die dokumentierten
  Felder bleiben, ihre Berechnung wird korrigiert.
  - **Saldo** (`contract-status`, Saldo-Karte): `advance_paid` zählt die
    Abschläge nach Kalender bis einschließlich des laufenden Monats, die Kosten
    reichen bis heute — ab der letzten Ablesung geschätzt und so ausgewiesen.
    Wer länger nicht abgelesen hat, sieht einen anderen, richtigen Saldo.
  - **Anomalien und Empfehlungen:** neue Erwartung je Monat und robuste
    Streuung. Auf den Demo-Daten sinken die Empfehlungen von 7 auf 2 und die
    Anomalien von 22 auf 7. Entfallen sind Trend- und Sommermeldungen für
    Heizöl und Pellets (Artefakte der Gradtagverteilung), ein halber
    Strom-März und Fernwärme-Sommer mit der Erwartung 0; die verbliebenen
    Meldungen sind echte Abweichungen in den Daten.
  - **Prognose:** Heizgradtage aus dem Klimanormal; Kalendermonate ohne eigene
    Historie kommen aus dem Modell. Bei kurzer Historie ändert sich der
    Jahreswert deutlich.
  - `hdd` zählt nur noch die Tage mit Verbrauch (Teilmonate); das
    Knickmodell ist stetig, seine Parameter und sein R² ändern sich.
- **Neu: täglicher Abruf bei Open-Meteo.** `weather_auto_fill` (Default an)
  war bisher ohne Wirkung. Jetzt holt die App beim Öffnen höchstens einmal am
  Tag die fehlenden Temperaturen und beim ersten Mal das Klimanormal
  (30 Jahre). Übermittelt wird nur der Standort, auf zwei Nachkommastellen
  gerundet (rund 1 km). Wer das nicht will: *Einstellungen → Wetter
  automatisch füllen* aus. Der README-Satz „stellt keine externen Anfragen"
  ist entsprechend geändert.
- **`confidence_band_sigma`** (Default 1,28) wirkt jetzt: Es bestimmt die
  Breite des Prognosebands (1,28 σ ≈ 80 % der Jahre).

### Deprecated

- `expected_hgt`, `weather_adjusted` und `delta_pct` in den Monatszeilen von
  `GET …/consumption`. Nachfolger: `expected_heat`, `heat_adjusted`,
  `weather_delta_pct`. Die alten Felder werden unverändert weiter geliefert
  und entfallen frühestens mit v3.0.0. `weather_adjusted` skalierte auch das
  Warmwasser mit dem HGT-Verhältnis (ein September wurde um 54 % „bereinigt"),
  `delta_pct` maß die Jahreszeit statt des Mehrverbrauchs.

### Added

- **Klimanormal (CALC-30):** Tagesmittel der letzten 30 vollen Kalenderjahre
  am Standort, einmal geholt und nur als Kennzahlen gespeichert
  (`data/climate_normal.json`): Heizgradtage je Kalendermonat mit Streuung
  für Heizgrenzen von 10 bis 22 °C. Neu geladen bei Umzug (> 0,05°) oder
  neuem Jahr. Ohne Klimanormal rechnet die App mit der eigenen
  Temperaturhistorie und sagt das dazu.
- **Heizmodell je Zähler (CALC-05):** `Verbrauch = a × HGT + c × Tage` —
  Verbrauch je Gradtag und Grundlast je Tag, gefittet ohne Achsenabschnitt
  über alle Monate ab der Zäsur (Sommer eingeschlossen), mit einem
  Robustheitsschritt. Daraus je Monat `expected_heat`, `weather_delta_pct`,
  `hdd_normal` und `heat_adjusted` (umgerechnet wird nur der Wettereinfluss
  laut Modell, die eigene Abweichung des Monats bleibt; unter der Grundlast
  bleibt ein Monat, wie er ist).
- **Unsicherheitsband der Prognose (CALC-13):** je Monat `band_low`/
  `band_high` und fürs Jahr `annual` (`value`, `low`, `high`, `sigma`,
  `level_pct`) aus der Streuung der Winter und dem Rauschen des Modells;
  dazu `hdd_source`, `climate_normal` und `warnings` (`history_short`,
  `no_climate_normal`). Die Prognoseansicht zeigt das Band als Fläche und
  eine Zeile „Jahr: …, in 80 % der Jahre zwischen … und …".
- **Saldo nach Kalender (CALC-02):** neue Felder `balance_as_of`,
  `projection_method`, `measured_until`, `cost_to_date`,
  `energy_cost_to_date`, `base_to_date`, `bonus_to_date`,
  `estimated_cost_to_date`, `estimated_cost_remaining`, `advance_remaining`,
  `suggested_advance`, `projection_factor`. Die Schätzung seit der letzten
  Ablesung läuft je Tag über das Heizmodell (bzw. die Tagesrate des
  Kalendermonats) und folgt dem jüngsten gemessenen Niveau (Faktor 0,5–1,5).
  Die Saldo-Karte schlüsselt die Kosten in Arbeitspreis und Grundpreis auf,
  nennt Mess- und Schätzzeitraum und schlägt bei spürbarer Abweichung einen
  Abschlag vor.
- **Temperaturen mit Quelle (DOC-08):** Jeder Tag trägt `source`
  (`archive`, `forecast`, `csv`, `manual`). Vorhersagen werden durch
  Archivwerte ersetzt, sobald diese vorliegen; eigene Werte nie.
  `POST /api/temperatures/sync-open-meteo` kennt `reload=1` (auch ältere
  Werte durch Archivwerte ersetzen) und `auto=1` (höchstens einmal am Tag)
  und liefert `measured_until`, `forecast_until` und den Status des
  Klimanormals. Die Temperaturansicht nennt, bis wann gemessen und bis wann
  vorhergesagt ist.
- **Wirkung einer Maßnahme mit Beleg (CALC-13):** `baseline_comparison`
  trägt `se` je Steigung, `significant` (Test der Steigungsdifferenz) und
  `delta_pct_ci95`; die Analyse sagt, ob der Unterschied belegt ist.
- **Regressionen:** `curve` (Kurvenpunkte vom Backend), `se_a` beim linearen
  Modell, `regression_point` je Monat; für Heizöl und Pellets
  `regressions_note: "delivery_modelled"` statt Kurven.
- Tarifwechsel: Der Block `forecast` trägt `warnings`, `hdd_source` und
  `annual_band`.

### Changed

- **Eine Regel für die Punkte der Heizkurve (CALC-14, FE-18):** Analyse,
  Bereinigung, Prognose und Anomalien wählen ihre Monate über
  `isRegressionCandidate()` — genug Tage, Temperaturen für ≥ 90 % der Tage,
  genug Heizgradtage. Derselbe Zähler zeigte bisher R² 0,42 in der Analyse
  und 0,56 in der Prognose. Das Streudiagramm zeichnet Monate außerhalb des
  Fits hohl und die Kurven aus dem Backend.
- **Heizgradtage eines Monats** zählen nur die Tage mit Verbrauch
  (`temp_days` sagt, für wie viele Temperaturen vorliegen).
- **Anomalien (CALC-06):** Erwartung je Monat aus dem Heizmodell bzw. aus
  demselben Kalendermonat anderer Jahre (im ersten Jahr den Nachbarmonaten),
  nie aus dem geprüften Monat selbst; Median und MAD statt Mittel und
  Standardabweichung, mit einer Untergrenze von 10 % eines typischen Monats.
  Heizöl und Pellets liefern keine Anomalien mehr.
- **Empfehlungen:** R1 misst am Heizmodell, R2 vergleicht die bereinigten
  Werte mit denselben Monaten des Vorjahrs (ab neun Paaren), R3 nur volle
  Monate und ohne Lieferarten, R4 aus der Anomalie-Erkennung (bisher lief R4
  für Wasser nie, weil es `kwh` las) und ohne PV.
- **Prognose (CALC-03, CALC-12):** Saisonprofil als Tagesrate; der
  Temperaturversatz verschiebt die Heizgrenze statt linear über alle
  Monatstage; Abschläge aus dem effektiven Zahlungsplan; nach dem Ende des
  letzten Vertrags läuft er als Annahme weiter (`contract_assumed`, in der
  Tabelle mit Sternchen). Die Spalte „Methode" ist übersetzt.
- **Knickmodell (CALC-22):** stetig — Sockel und Heizast treffen sich im
  Knick.
- **Analyse:** Hinweis unter der Modelltabelle: R² misst die Anpassung an
  die gezeigten Monate, keine Vorhersagegüte.
- **Verbrauchsansicht:** Liegt die letzte Ablesung im laufenden Jahr zurück,
  heißt die Abschlagskachel „Abschläge bis ‹Datum›" — sie summiert
  abgelesene Monate, die Saldo-Karte rechnet nach Kalender.
- PDF-Jahresbericht: bereinigte Werte aus dem Heizmodell, mit Legende.

### Fixed

- Analyse: Die Überschrift sprach von vier Regressionsmodellen (es sind
  fünf), die Sigmoid-Formel zeigte bei negativem θ₀ „HGT−-10.0", Koeffizienten
  und R² standen mit Dezimalpunkt statt im Zahlenformat der Sprache. Die
  Prognosetabelle zeigte die Methode als Rohwert `blend(reg=…)`.
- Doku: Die Saldo-Formel im Wohnungs-Szenario hatte das umgekehrte
  Vorzeichen; veraltete Service- und Controller-Zahlen in Architektur-Doku
  und README entfernt statt nachgezählt.

### Tests

- Neu: `WeatherModelTest` (20 Tests, synthetische Daten nach
  `a × HGT + c × Tage`), `TemperatureSyncTest` (11, Open-Meteo als Attrappe
  über das neue Interface `WeatherSource`); `BaselineCutoffTest` an die neue
  Erwartung angepasst; Roundtrip von `source` im Backup.
- 378 Testmethoden (vorher 346), Frontend-API-Shape 49/49, Browser-Render
  73/73. Jede neue Rechenregel hat eine Gegenprobe (25, alle rot).

### Lessons Learned

- Eine Erwartung, die den geprüften Wert enthält, prüft nichts — Erwartungen
  gehören zum Monat, der geprüfte Wert nie in die eigene Erwartung.
- Synthetische Testdaten müssen die alte Rechnung widerlegen können: Mehrere
  Regeln waren auf glatten Daten auch ohne sich selbst grün.
- Ein Saldo folgt dem Kalender, nicht den Ablesungen — und eine
  Aufschlüsselung muss ihre Summe ergeben.
- Wer eine wirkungslose Einstellung zum Leben erweckt, prüft, welche
  Doku-Aussagen an ihrem Nichtstun hingen („stellt keine externen Anfragen").
- Eine Formel für Jahreswerte taugt nicht ungeprüft für Monate: Die
  Heizanteil-Skalierung nach VDI 3807 machte im PDF der Demo aus einem warmen
  September +32 %. Aufgefallen ist es erst im fertigen Bericht, nicht im Test.

Ausführlich: [Release-Prozess §5](docs/technical/06-release-process.md).

---

## [2.7.0] — 2026-09-25 — Länderprofile

MINOR-Release (N1014). Kein Schema-Bump, keine Datenmigration. Vier neue
Einstellungen mit Defaults, die dem bisherigen Verhalten entsprechen — wer
nichts umstellt, merkt an bestehenden Schnittstellen nichts.

**Der Anlass.** Die Oberfläche spricht seit v2.0.0 sieben Sprachen, alles
andere war deutsch geblieben: Euro und Cent, Effizienzklassen nach dem
Gebäudeenergiegesetz, der deutsche Strommix als CO₂-Faktor, 15 °C Heizgrenze,
Leipzig als Wetterstandort, Europe/Berlin — und das Backend schrieb Zahlen
fest deutsch: Im englischen PDF stand „10.359 kWh". Ein Länderprofil bündelt
diese Voreinstellungen jetzt für Deutschland, Österreich, die Schweiz,
Frankreich, Italien, Spanien, Portugal, die Niederlande und das Vereinigte
Königreich (Review I18N-01 bis -05, I18N-17 teilweise, MKT-21).

### ⚠️ Für bestehende Installationen

- **Nichts umstellen = nichts ändert sich.** Die Defaults sind das deutsche
  Profil, und das deutsche Profil ist das bisherige Verhalten — ein Test hält
  beides gleich. Englische Oberflächen schreiben weiter englische Zahlen.
- **PDF-Jahresbericht und Empfehlungstexte** schreiben Zahlen, Beträge, Daten
  und Monate jetzt in der Sprache der Oberfläche: In einer englischen
  Installation wird aus „10.359 kWh" „10,359 kWh", aus „2025-01" „Jan 2025".
- **Wer das Land wechselt**, sieht die Effizienzklasse nur noch in
  Deutschland; andere Länder zeigen die Kennzahl kWh/m²·a ohne Klasse.

### Added — Länderprofile

- **Einstellungen → Sprache & Land:** Land, Währung und Zeitzone neben der
  Sprache; alle vier wirken sofort. Beim Wechsel des Landes zeigt ein Dialog,
  welche Werte das Profil ändern würde (bisher → neu, mit Quelle des
  CO₂-Faktors): **Alle übernehmen**, **Nur Land ändern** oder **Abbrechen**.
  Zähler, Verträge und Ablesungen bleiben unberührt.
- **Erststart nach Browsersprache (I18N-01):** Ein leeres Datenverzeichnis
  übernimmt Sprache und Land aus `Accept-Language` („fr-CH" → Französisch,
  Schweiz); die Standardzähler heißen gleich richtig („Compteur principal").
  Geschrieben werden nur Werte, die vom Default abweichen — ein deutscher
  Erststart schreibt nichts fest, spätere Default-Korrekturen greifen weiter.
- **Profile (SSOT `src/Config/Countries.php`):** Währung, Zeitzone,
  Heizgrenze nach nationaler Gradtag-Konvention (FR 18 °C, IT 20 °C, NL 18 °C,
  UK 15,5 °C), CO₂-Faktor Strom (Ember 2024 über Our World in Data; DE behält
  380 g/kWh), Wetterstandort (Hauptstadt), Effizienzskala, Brennwert-Einheit.
- **Währung (I18N-03):** EUR, CHF, GBP. Symbol und Untereinheit in allen
  Texten („Rp./kWh", „p/kWh", Achsen in CHF oder £). Beträge werden **nicht**
  umgerechnet; die Datenfelder (`ct_per_kwh`, `*_eur`) bedeuten Haupt- und
  Untereinheit der gewählten Währung.
- **Gas (I18N-05):** Brennwert-Eingabe in kWh/m³, MJ/m³ oder GJ/Smc,
  gespeichert immer in kWh/m³; Hinweise für britische, italienische und
  niederländische Rechnungen. Im Gasvertrag rechnet **„Preis je m³
  umrechnen"** einen Arbeitspreis je m³ oder Smc in ct/kWh um — geteilt durch
  den Brennwert am gewählten Tag — und trägt ihn auf Wunsch ein.
- **Temperaturen:** Hinweis, wenn der Standort noch die Voreinstellung des
  Landes ist (die Gradtagzahlen rechnen dann mit dem Wetter eines anderen Orts).
- **`GET /api/countries`** — die Profile (Klasse C).
- Neues Kapitel [Länderprofile](docs/functional/14-laenderprofile.md) (DE/EN).

### Changed

- **Schreibweise aus Sprache und Land (I18N-17, teilweise):** Wird die Sprache
  im Land gesprochen, verfeinert das Land die Region (de-AT „€ 1.234,56",
  de-CH „1'234.50", fr-CH). Sonst gilt die Region der Sprache — Englisch in
  Deutschland bleibt en-GB. Das Backend folgt derselben Regel.
- **Effizienzklasse nur mit Skala (I18N-04):** Heute hat nur Deutschland eine
  (GEG). Andernorts sind die Klassenfelder `null`, `scale` ist `null`, und
  `scale_note` nennt den Grund; die Empfehlung „schwache Effizienzklasse"
  entfällt dort. Dashboard und PDF zeigen die Kennzahl ohne Klasse.
- **Empfehlungstexte:** Monate als „Jan. 2025" statt „2025-01", Zahlen mit
  landesüblichem Dezimalzeichen.
- **PDF:** Stufe der Empfehlung aus dem Katalog („[Dringend]" statt
  „[URGENT]").

### Changed — Schnittstellen (additiv)

- `GET|PATCH /api/settings`: neue Schlüssel `country` (Default `DE`),
  `currency` (`EUR`), `timezone` (`Europe/Berlin`), `gas_cv_unit` (`kwh`).
  Unbekannte Werte → 400 `errors.settings.valueInvalid`.
- `GET /api/benchmarks/efficiency`: neue Felder `scale`, `scale_note`.
- CSV-Spaltenköpfe mit Währung nennen die gewählte Währung („Kosten (CHF)");
  für Euro unverändert.

### Fixed

- **PDF-Jahresbericht (I18N-02):** Zahlen, Beträge, das Erstellungsdatum und
  die Monate standen in jeder Sprache deutsch formatiert, Beträge immer in €.
- **Termine:** Fälligkeiten standen im Dashboard und in der Terminliste als
  „2026-07-22"; ebenso die Zäsuren am Zähler und die Vorschau der
  v0.9.0-Migration.
- **Wetterdaten:** Die Tagesmittel von Open-Meteo wurden immer in
  Europe/Berlin gebildet — außerhalb dieser Zeitzone lag jede Tagesgrenze
  daneben. Jetzt gilt die Zeitzone der Installation.

### Migration

Keine. Schema bleibt 1.5.0. Die neuen Schlüssel erscheinen in
`settings.json` erst, wenn sie vom Default abweichen.

### Tests

324 → 346 Testmethoden (380 Fälle): `CountriesTest` (Profile vollständig,
DE-Profil = ausgelieferte Defaults, Namen in allen Katalogen,
Accept-Language, Einheiten Frontend = Backend), `CountryProfileTest`
(Schreibweise für acht Sprach-Land-Kombinationen, Währung in Katalogtexten,
kein fest eingeschriebenes Währungszeichen, Validierung, Effizienz ohne
Skala, PDF in Englisch und für Österreich, Zeitzone der Wetterabfrage),
`FirstStartProfileTest` (Erststart über einen echten `php -S`-Server mit
fr-FR, en-GB und de-DE). Frontend ohne Server: `format.test.mjs` erweitert
(Region, Währung, Katalog-Platzhalter, Gas-Einträge; 62 Prüfungen). API-Shape
44/44 und Browser-Render 64/64 — neu: Länderprofile, Effizienzskala,
Länderauswahl, Brennwert-Einheit und die Umrechnungshilfe. **14 Schutzstellen per
Gegenprobe als greifend nachgewiesen.** Im Browser abgenommen:
Länderwechsel mit Dialog, Österreich (Formate, Effizienz ohne Klasse),
Vereinigtes Königreich (£, MJ/m³), Umrechnungshilfe — bei 1280 und 375 px.

**Doku** DE + EN: Kapitel Länderprofile, API-Referenz (Route, Einstellungen,
`scale`), Datenmodell, Gas, UI-Referenz mit neuen Screenshots, README.
45 neue Katalogschlüssel × 7 Sprachen; 36 Texte tragen die Währung jetzt als
Platzhalter (`{cur}`, `{minor}`, `{code}`).

### Lessons Learned

- **„Sprache-Land" ist nicht immer eine Region, die jemand will.** Der erste
  Entwurf bildete `${sprache}-${land}` — für Englisch mit dem Default-Land DE
  also „en-DE", und `Intl` schreibt dort deutsche Zahlen. Jede bestehende
  englische Installation hätte sie über Nacht bekommen. Ein älterer Test fiel
  darüber; die Regel lautet jetzt: Das Land verfeinert nur Sprachen, die dort
  gesprochen werden.
- **Ein Default-Profil ist ein Versprechen an alle Bestandsinstallationen.**
  Dass das deutsche Profil genau den bisherigen Defaults entspricht, prüft
  ein Test — sonst würde eine kleine Abweichung (Standortname, CO₂-Wert) still
  jede bestehende Installation verändern, sobald jemand „Alle übernehmen"
  anbietet.
- **Beim Erststart nur Abweichungen schreiben.** Wer alle Profilwerte
  festschreibt, friert die Defaults ein: Eine spätere Korrektur (etwa der
  CO₂-Faktoren) erreichte neue Installationen nicht mehr.

---

## [2.6.0] — 2026-09-25 — Anmeldung, Plausibilität, sichere Backups

MINOR-Release (N1013). Kein Schema-Bump, keine Datenmigration. Alles Neue ist
**opt-in oder additiv**: Wer nichts einschaltet, merkt an bestehenden
Schnittstellen nichts — außer dass Fehler jetzt früher und deutlicher
gemeldet werden.

**Der Anlass.** Paket B des Gesamtreviews (Sicherheit und Datensicherheit).
Drei Befunde wogen am schwersten: Ohne Anmeldung konnte jeder im Netz alles
lesen und löschen, und auf Apache-Instanzen waren `data/` samt Vollbackups
sowie `.git/` per HTTP abrufbar. Ein einziger falscher Zählerstand — etwa eine
0 aus Home Assistant — machte aus einem normalen Monat einen Verbrauch in Höhe
des ganzen Zählerstands. Und ein Backup-Import schrieb ungeprüft Topf für Topf;
ein fehlerhaftes Backup legte danach Übersicht und Auswertungen lahm.

### ⚠️ Für bestehende Installationen

- **Nichts einschalten = nichts ändert sich.** Die Anmeldung ist standardmäßig
  aus; Home Assistant, Skripte und Backups laufen unverändert weiter.
- **Apache/Synology:** Die `.htaccess` sperrt jetzt `data/`, `src/`, `.git/`
  und andere Nicht-Auslieferungsdateien (404). Voraussetzung wie bisher für die
  Cache-Regeln: `AllowOverride FileInfo` und `mod_rewrite`. Prüfen:
  [Sicherheit → Webserver](docs/technical/08-security.md).
- **Home Assistant:** Ein Wert, der **kleiner** ist als der vorige desselben
  Zählers, wird weiter angenommen (201), aber als Verdacht markiert und zählt
  erst nach Bestätigung. Wer die Anmeldung einschaltet, braucht für den Push
  einen Token.
- **Entwicklungsserver:** immer `php -S 127.0.0.1:8080 router.php` — ohne
  Router liefert PHP jede Datei aus, auch `data/`.

### Added — Anmeldung (opt-in)

- **Passwort-Anmeldung** in *Einstellungen → Anmeldung & Zugriff*: einschalten,
  ändern, ausschalten (mit dem bisherigen Passwort). Sitzungs-Cookie 30 Tage,
  HttpOnly, SameSite=Strict, `Secure` hinter HTTPS; nach fünf Fehlversuchen in
  15 Minuten 5 Minuten Sperre; ein neues Passwort meldet alle anderen Geräte ab.
  Anmeldebildschirm und **Abmelden** in der Kopfleiste.
- **Anmeldung über einen vorgeschalteten Proxy** (Authelia, Authentik …):
  `ET_AUTH=proxy`, Benutzer aus `Remote-User`/`X-Forwarded-User` — nur von
  Adressen in `ET_TRUSTED_PROXIES`.
- **API-Schlüssel für Skripte** (`Authorization: Bearer etk_…`), Berechtigung
  *Lesen* oder *Verwalten*, Klartext einmalig, „zuletzt benutzt".
- **Umgebungsvariablen** `ET_AUTH`, `ET_ADMIN_PASSWORD_HASH`,
  `ET_TRUSTED_PROXIES`, `ET_ALLOWED_HOSTS` (DNS-Rebinding → 421),
  `ET_FRAME_ANCESTORS`, `ET_DEBUG`.
- Mit eingeschalteter Anmeldung braucht der Home-Assistant-Push einen Token;
  `/api/health` antwortet ohne Anmeldung nur mit `{status, version}`.

### Added — Plausibilität der Zählerstände

- **Rückfragen vor dem Speichern** (Ablese-Dialog und Zählerstand-Erfassung):
  Tagesverbrauch über dem Dreifachen des üblichen, „Komma vergessen?",
  kleiner als der letzte Stand (mit Link zum Zählertausch), Datum in der
  Zukunft, schon ein Stand am selben Tag (**Ersetzen** statt doppeln).
  Hinweise schon beim Tippen; wer ablehnt, behält die Eingabe.
- **Ausreißer fallen aus der Rechnung.** Eingeklemmte Spitzen und Dellen
  desselben Geräts werden erkannt und übergangen; die Verbrauchsansicht nennt
  sie in einem Hinweis und markiert sie in der Tabelle. Bis v2.5.3 zählte das
  Intervall nach einer 0 den ganzen Zählerstand als Verbrauch.
- **Verdacht aus Home Assistant:** fallende Werte gespeichert, markiert
  („PRÜFEN"), per ✅ zu bestätigen.
- **Überlauf des Zählwerks:** Mit gepflegten *Stellen des Zählwerks* (Zähler
  bearbeiten) ist 99.998 → 12 ein Verbrauch von 14.
- Ein **fallender Stand ohne Tausch** wird gemeldet statt still verworfen.

### Added — Backups und Snapshots

- **Import mit Prüfung und Vorschau:** Jeder Topf wird vor dem Schreiben
  geprüft; ein fehlerhaftes Backup ändert nichts und nennt die Fundstellen.
  Die Vorschau zeigt, was eingespielt wird und was unverändert bleibt.
  Scheitert eine Datei mitten im Schreiben, werden die schon geschriebenen
  zurückgesetzt. Die Hülle von `GET /api/backup/export` wird akzeptiert.
- **Snapshots verwalten:** Liste mit Zeitpunkt, Anlass und Größe;
  herunterladen, einspielen (vorher Sicherung des jetzigen Stands), löschen.
  Aufräumen: eigene die letzten zehn, automatische 30 Tage (mindestens drei je
  Anlass). Scheitert der Sicherungs-Snapshot, wird nicht mehr still ohne
  Rückweg eingespielt (409, nur mit ausdrücklicher Zustimmung).
- **Ausgeblendete Empfehlungen** gehören jetzt zum Backup (Lektion 18).
- Große Backups: Snapshots entstehen gestreamt; das Docker-Image bringt eine
  `php.ini` mit (256 MB, 32 MB Upload, 120 s).

### Security

- Webserver-Regeln für Apache (`.htaccess`, `data/.htaccess`), nginx und den
  Entwicklungsserver: Nutzdaten, Quelltext, `.git/` und Punktdateien werden
  nicht mehr ausgeliefert.
- **Content-Security-Policy** mit Nonce, `frame-ancestors 'self'` (einbetten
  nur für freigegebene Adressen, Einstellung *Einbetten*),
  `Referrer-Policy`, `Permissions-Policy`, kein `X-Powered-By`.
- **Fehlerantworten ohne Interna:** Datei, Zeile und Pfade nur mit
  `ET_DEBUG=1`; ein 500 nennt eine Fehler-ID, die Einzelheiten stehen im Log.
- **Downgrade-Schutz:** Daten einer neueren Version werden erkannt; die App
  schreibt nichts (503) statt sie still auf das alte Schema zurückzustempeln.
- **CSV-Export:** Zellen, die mit `= + - @` beginnen, werden neutralisiert
  (Formel-Einschleusung in Excel).
- Docker: Anwendungscode gehört `root`, OPcache an, Build mit Provenance und
  SBOM; Dependabot schlägt Updates für Basis-Image und Actions vor.
- `.gitignore` als Whitelist für `data/` — Laufzeitdateien mit Nutzdaten
  können nicht mehr versehentlich ins Repository geraten.

### Changed — Schnittstellen (additiv)

- **Stabile Fehlercodes:** jede Fehlerantwort trägt `code` (Katalogschlüssel);
  Skripte werten ihn statt des übersetzten Texts aus.
- **Stabilitätszusage** mit drei Klassen (Fremdsysteme, Auswertungen,
  Oberfläche) und Regeln für Änderungen — siehe
  [API-Referenz](docs/technical/03-api-reference.md).
- `HEAD` wie `GET`, falsche Methode → `405` mit `Allow` (bisher 404);
  unbekannter Datensatz in der URL einheitlich `404` (bisher teils 400).
- `/api/health`: `status` (ok/degraded/error), `checks`, `last_ingest`,
  HTTP 503 bei `error` — der Docker-Healthcheck erkennt Störungen jetzt.
- Neue Felder: `warnings` (Verbrauch je Zähler), `is_suspect`/`source`
  (Ablesung), `digits` (Gerät), `typical_per_day`/`suspect_count`/
  `last_reading.id` (Erfassung), `suspect`/`previous` (Ingest),
  `last_used_at` (Token), `ignored_keys` (Einstellungen).
- Parametergrenzen für Prognose, Jahresbericht und Temperatur-Sync (400 statt
  stiller Übernahme).
- Die früher in `docs/API.md` beschriebenen Körper für Zähler (`device {…}`)
  und Zählertausch (`removed_on`, `final_counter`, `new_device {…}`) werden
  als Alias angenommen — bisher wurden sie still ignoriert bzw. abgelehnt.

### Fixed

- **CSV-Import:** Windows-1252-Dateien aus Excel (Umlaute) brachen mit HTTP 500
  mitten im Import ab; jetzt werden sie umgewandelt. Spalten werden an der
  Kopfzeile erkannt — auch das eigene Exportformat lässt sich wieder einlesen.
  Großimporte schreiben einmal statt je Zeile (2.000 Zeilen: rund 4 s → 15 ms,
  lokal gemessen).
- **Leistung:** `settings.json` wurde je Anfrage bis zu 44.000-mal gelesen
  (Gas mit datierten Brennwerten); Einstellungen und Faktoren werden jetzt
  zwischengespeichert, Verbrauchsrechnungen je Datenstand.
- **Service Worker:** API-Antworten wurden im Unterverzeichnis nie für den
  Offline-Betrieb gespeichert, im Wurzelbetrieb dagegen alle — auch das
  Vollbackup. Jetzt: nur eine Freigabeliste von Datenansichten, exakt samt
  Parametern, Downloads nie; offline zeigt die Kopfleiste „Offline – Stand
  vom …".
- **Atomares Schreiben mit `fsync`**; verwaiste Temp-Dateien älter als eine
  Stunde räumt der Health-Check auf.
- **Einstellungen:** Nach einem Import oder Sprachwechsel meldete ein
  veralteter Handler beim Schließen „ungespeicherte Änderungen".
- **Meldungen** lagen bei offenem Dialog über dem Speichern-Knopf und fingen
  dessen Klicks ab; jetzt erscheinen sie dann oben (auf dem iPhone über die
  volle Breite). Netzwerkfehler heißen „Keine Verbindung … – nichts
  gespeichert" statt „Failed to fetch".
- **Unbekannte Einstellungsschlüssel** wurden still verworfen; die Antwort
  nennt sie jetzt (`ignored_keys`).

### Migration

Keine. Schema bleibt 1.5.0. Neue Felder erscheinen erst, wenn sie gesetzt
werden; `data/auth.json` bekommt weitere Einträge erst beim Einschalten der
Anmeldung.

### Tests

285 → 324 Testmethoden (336 Fälle): `ReadingPlausibilityTest`
(Ausreißer, Verdacht, Überlauf, Ingest), `BackupSafetyTest` (Prüfung vor dem
Schreiben, Rückweg, Snapshot-Rotation, Abdeckung aller Schreibstellen),
`CsvImportRobustnessTest`, `LegacyBodyAliasTest`, `AuthFlowTest` (Anmeldung,
Sperre, Schlüssel und Ingest über einen echten `php -S`-Server),
`HealthCheckServiceTest` erweitert; `ReleaseConsistencyTest` prüft jetzt,
dass **jede Route** in der API-Referenz steht (DE und EN). Frontend ohne
Server: `tests/plausibility.test.mjs` (24 Prüfungen, in der CI).
API-Shape 41/41, Browser-Render 58/58. **13 Schutzstellen per Gegenprobe als
greifend nachgewiesen** (Code gebrochen → zugehöriger Test rot): Ausreißer,
Verdacht, Überlauf, Ingest-Markierung, Anmeldung, Host-Liste,
Fehlerdetails, Backup-Prüfung, Downgrade, Zeichensatz, Formel-Neutralisierung,
Alias, Routen-Doku. Im Browser abgenommen: Anmeldung samt
Fehlversuch und Sitzung über Neuladen, Schlüssel, Snapshots, Import mit
Vorschau und Fundstellen, 409-Rückfrage, Rückfragen bei der Erfassung,
Verdacht bestätigen, Zählwerk-Stellen — bei 1440, 393 und 375 px.

**Doku** DE + EN: neues Kapitel [Sicherheit & Netzbetrieb](docs/technical/08-security.md),
`SECURITY.md`, API-Referenz (Anmeldung, Fehlercodes, Stabilitätszusage,
12 neue Routen), `docs/API.md` an den Code angeglichen, Datenmodell,
Installation, Docker, Home Assistant, Zählerstände, UI-Referenz mit drei neuen
Screenshots; Warnhinweis und Schnellstart mit `router.php` in README und
INSTALL. 152 neue Katalogschlüssel × 7 Sprachen.

### Lessons Learned

- **Das negative Intervall zu verwerfen ist kein Schutz.** Die Rechnung warf
  nach einer 0 nur den Rückgang weg und zählte das nächste Intervall ab dem
  falschen Stand voll. Der Fehler sitzt im **Stand**, nicht im Intervall —
  also muss der Stand heraus (Lektion 6, andere Stelle).
- **Ein Schutz, der eine Tür bewacht, suggeriert ein Schloss.** Der
  Home-Assistant-Token schützte nur den Push, der Text in den Einstellungen
  legte nahe, er schließe die API. Und eine Anmeldung ohne Webserver-Regeln
  wäre über `data/backups/` zu umgehen gewesen — erst die Regeln, dann die
  Anmeldung.
- **Eine Routenliste ohne Test ist eine Behauptung.** `docs/API.md` kannte 37
  von 70 Routen, die Referenz behauptete „68, v1.9.2". Jetzt vergleicht ein Test
  die Routen des Codes mit der Referenz in beiden Sprachen.

---

## [2.5.3] — 2026-09-24 — Keine stillen Fehlbuchungen

PATCH-Release. Kein Schema-Bump, keine Datenmigration, keine geänderte
Schnittstelle. Neu abgelehnt wird nur, was bisher still falsch gespeichert
wurde.

**Der Anlass.** Ein Gesamtreview (Berechnungen, Oberfläche auf Mac und
iPhone, Übersetzungen, Doku, API) fand eine Fehlerklasse quer durch die App:
Eingaben, die nicht passen, wurden nicht abgelehnt, sondern still in etwas
Gültiges verwandelt. Ein leeres Zahlenfeld wurde zur 0, „12345,6" je nach
Browser zu gar nichts, ein nicht verfügbarer Home-Assistant-Sensor zu einem
Zählerstand 0, ein unlesbarer Abrechnungsstichtag zum 1. Januar. Jede dieser
Stellen erzeugt eine Buchung, die plausibel aussieht und Kosten, Saldo und
Prognose verfälscht, bis jemand sie findet. Dieses Release schließt sie.

### ⚠️ Für Home-Assistant-Nutzer

Die Vorlage in den Einstellungen und in `docs/HOME-ASSISTANT.md` enthielt bis
v2.5.2 `"value": {{ states(sensor_entity) | float(0) }}`. Nach einem
HA-Neustart oder Funkaussetzer zum Push-Zeitpunkt wurde damit ein Zählerstand
**0** gebucht; die nächste echte Ablesung zählte als Verbrauch eines einzigen
Tages. **Bitte die Vorlage ersetzen:** `| float` ohne Ersatzwert und vor jedem
Push die Bedingung `has_value(…)`. Die Einstellungen erzeugen die
Automatisierung jetzt fertig aus den Zähler-Aliasen. Außerdem funktionierte
`Authorization: "Bearer !secret …"` aus der Anleitung nie (Antwort `401`):
`!secret` wirkt nur als ganzer Wert — `Authorization: !secret
energietracker_auth`, in der `secrets.yaml` steht `"Bearer et_…"`.
Bestehende Konfigurationen laufen unverändert weiter; der Endpunkt
`POST /api/ingest` ist gleich geblieben.

### Fixed — stille Fehlbuchungen

- **Zahlenfelder** (Ablesung, zentrale Erfassung, Lieferung, Zählertausch,
  Zähler, Vertrag, Wasservertrag, Tarifangebot, Einstellungen, Gasfaktoren,
  Standort, Prognose, Termine): Text mit Dezimaltastatur statt
  `type="number"`, gelesen von einem gemeinsamen Parser, der Komma, Punkt und
  Tausendertrennung versteht. Leer bleibt leer, Unlesbares wird am Feld
  gemeldet — nie mehr eine stille 0.
- **Zählertausch:** Der Endstand ist Pflicht; der Dialog zeigt den letzten
  bekannten Stand, fragt bei einem kleineren Endstand nach und fasst den Tausch
  vor dem Ausführen zusammen. Ein leerer Endstand schloss das alte Gerät
  bisher mit 0 ab — nicht rückgängig zu machen.
- **Leerer Vertrag:** Anbieter oder Tarif sowie mindestens ein Arbeitspreis
  sind Pflicht. Ein versehentlich gespeicherter leerer Vertrag lief als
  „aktiv" und löste ab seinem Beginn den laufenden ab. Neu: Der Beginn ist mit
  dem Tag nach der laufenden Bindung vorbelegt, und bevor ein Vertrag einen
  laufenden ablöst, fragt die App nach. Standard- und Wasservertrag gleich.
- **Dialoge:** Ein Doppelklick auf „Speichern" legte zwei Datensätze an.
  Markieren über den Dialogrand hinaus schloss den Dialog samt Eingaben;
  Escape auf einer Rückfrage schloss auch das Formular darunter; nach
  „Zurück" blieb ein Dialog verwaist offen.
- **Ablesung vor dem Einbau des ersten Geräts** wurde abgelehnt („kein Gerät
  aktiv"). Das traf jede frische Installation, sobald man ältere Stände
  nachtrug — der Standardzähler gilt ab dem Installationstag. Jetzt wird das
  erste Gerät zurückdatiert; Lücken zwischen zwei Geräten bleiben ein Fehler.
- **Bonus mit Gutschrift nach Vertragsende** fiel aus Saldo und Tarifvergleich
  heraus. Er zählt jetzt im letzten Vertragsmonat.
- **CSV-Monatsexport:** Abschlag, Monats-Saldo, Saldo kumuliert und CO₂ waren
  immer leer.
- **Heizöl und Pellets:** „+ Ablesung" legte Datensätze an, die nirgends
  erschienen und nichts bewirkten. Dort steht jetzt „+ Lieferung".
- **Dashboard:** Monate ohne Daten erschienen im Verlaufschart als
  Nullverbrauch; jetzt als Lücke.
- **„Heute"** wurde teils in UTC berechnet — kurz nach Mitternacht war es noch
  gestern.
- **Charts** formatierten Zahlen nach der Browser-Sprache statt der
  App-Sprache („1,000" für tausend neben „1,000" für eins in der Tabelle).

### Security

- **Schreibende Anfragen von fremden Webseiten** werden abgelehnt (`403`,
  geprüft über `Sec-Fetch-Site`, ersatzweise `Origin`). Home Assistant, curl
  und Skripte senden diese Kopfzeilen nicht und sind nicht betroffen.
- **Gespeicherte Werte werden in der Oberfläche escaped**, auch in
  Formularfeldern. Ein unlesbares Datum erscheint als „–" statt roh im HTML;
  präparierte Werte aus einer fremden Backup-Datei konnten bisher Skripte
  ausführen.
- **Datumsfelder werden auf allen Schreibpfaden kalendergenau geprüft:**
  Ablesung, Zähler, Tausch, Vertrag samt Preiszeilen, Boni und
  Sonderzahlungen, Lieferung, Termin, Temperatur, CSV-Import und Ingest. Ein
  „2026-02-30" legte bisher Auswertungen, CSV und PDF lahm.

### Fixed — Speicher

- **Beschädigte Datendatei:** Sie wurde still als leer gelesen, und der
  nächste Schreibzugriff vernichtete den Bestand. Jetzt antwortet die App mit
  `503`, die Datei bleibt unangetastet, und daneben liegt eine
  Quarantäne-Kopie `<datei>.corrupt-<prüfsumme>`.
- **Gleichzeitige Schreibzugriffe** (etwa ein Home-Assistant-Push während
  einer Eingabe) konnten Änderungen verlieren. Eine globale Schreibsperre
  ordnet sie nacheinander.
- **Snapshot vor der Schema-Migration:** Beim ersten Start nach einem Update
  mit neuem Schema entsteht jetzt `data/backups/pre-migration-…`. Die Doku
  versprach diesen Snapshot seit Langem; es gab ihn nicht.

### Changed — Oberfläche

- **iPhone und kleinere Macs:** Die Seite lief in 13 von 21 Ansichten seitlich
  über (am Mac mit 1280 px in der Gas-Ansicht). Jetzt in keiner mehr — geprüft
  über alle 19 Routen bei 375, 393 und 1280 px.
- Nach einem Menüklick lag der **Seitentitel unter der Kopfleiste**, samt den
  Kopf-Aktionen.
- Die **Speichern-Leiste** der Zählerstand-Erfassung überdeckte am Mac die
  Seitenleiste.
- **Sonderzahlungen im Vertragsformular:** eigenes Raster statt zerbrochener
  Zeilen (das Datum war rund 25 px breit, Beträge abgeschnitten).
- **Home-Assistant-Karte:** REST-Command, Eintrag für die `secrets.yaml` und
  eine fertige Automatisierung aus den Aliasen, alle drei zum Kopieren.

### Changed — Texte

- **Glossar:** Das Saldo-Vorzeichen war umgekehrt. Richtig ist Kosten −
  Abschläge: positiv heißt Nachzahlung, negativ Guthaben (DE + EN).
- **Übersetzungen:** Wasser hatte in fünf Sprachen einen „Energiepreis";
  „Rückforderung" las sich in fünf Sprachen wie eine Gutschrift statt einer
  Rückzahlung; EN „Surcharge" (Aufschlag) für Nachzahlung und „Compensation"
  (Schadenersatz) für die Einspeisevergütung.
- **Update-Anleitung:** `docker compose pull` holt die in der
  `docker-compose.yml` eingetragene Version, nicht die neueste; der Rückweg
  nach einer Schema-Migration führt nur über das Backup.

### Schnittstellen

Unverändert: Endpunkte, Felder, Backup-Format 3.0, CSV-Formate,
Docker-Variablen. Neu abgelehnt wird nur, was bisher still falsch gespeichert
wurde: ungültige Kalenderdaten und Beträge, die keine Zahl sind (`400`),
schreibende Browser-Anfragen mit fremdem Ursprung (`403`), Zugriffe auf eine
beschädigte Datendatei (`503` statt leerer Liste).

### Migration

Keine. Schema bleibt 1.5.0.

### Tests

256 → 285 Testmethoden (297 Fälle): `CrossSiteGuardTest`,
`JsonStoreCorruptionTest`, `WriteLockTest` (vier Prozesse schreiben
gleichzeitig, kein Update geht verloren), `MigrationSnapshotTest`,
`InputValidationTest`, `BonusAttributionTest`, `CsvMonthlyExportTest`;
`ReadingEdgeCasesTest` auf die neue Semantik umgestellt. Neu und ohne
Server, beide in der CI: `tests/format.test.mjs` (Zahlenparser,
Datumsformat) und `tests/ha-snippet.test.mjs` — vergleicht die
Home-Assistant-Vorlage der App zeilenweise mit beiden Sprachfassungen der
Anleitung. Browser-Render 58/58. **Sieben Kernannahmen per Toggle als
greifend nachgewiesen**, die HA-Vorlage zusätzlich per Gegenprobe.

**Doku** DE + EN: Home Assistant, Glossar, Installation, Docker.
30 neue Katalogschlüssel × 7 Sprachen; 18 Schlüssel in einzelnen Sprachen
korrigiert.

### Lessons Learned

- **Lektion 24 lebte im Frontend weiter.** `type="number"` liefert für
  „12,5" je nach Browser einen leeren String, und `Number("")` ist 0. Zehn
  Formulare buchten so eine stille 0. Zahlen gehören als Text mit
  Dezimaltastatur erfasst und von **einem** Parser gelesen, der „leer"
  kennt.
- **Eine Vorlage, die Nutzer kopieren, ist Code.** Das HA-Snippet mit
  `float(0)` stand an zwei Stellen, und beide waren falsch. Es lebt jetzt in
  einem Modul, und ein Test hält die Anleitung daneben.
- **Ein Scroll-Rahmen beschneidet nur, was sich an ihm ausrichtet.** Das
  unsichtbare `.sr-only`-Label im Tabellenkopf ist absolut positioniert und
  entkam dem `overflow-x: auto` — die ganze Seite wurde breiter. Rahmen mit
  Überlauf bekommen `position: relative`.

---

## [2.5.2] — 2026-09-15 — Rechnungsprüfung: Zählerstände mit Ableseart

PATCH-Release (UX-Politur). Kein Schema-Bump, keine Datenänderung.

**Der Anlass.** Beim Abgleich zweier echter Jahresrechnungen mit der
Rechnungsprüfung (v2.5.0) fiel auf, woran der Vergleich hakt: Die Rechnung
schneidet ihre Zeilen an Brennwert- und Preisgrenzen mit **geschätzten**
Zwischenständen und markiert das per Fußnote — „vom Marktpartner
übermittelt (geschätzt)", „programmseitig hochgerechnet". Die App zeigte je
Abschnitt nur m³ und kWh; ob ein Abschnitt an einer echten Ablesung oder an
einem interpolierten Zwischenstand endet, war nicht zu sehen, und zum
Zählerstand der Rechnung gab es keine Zahl zum Danebenlegen.

**Neu.** Die Tabelle führt je Abschnitt **Stand alt** und **Stand neu**,
jeder Stand mit seiner **Ableseart** wie die Fußnoten der Rechnung: ohne
Zusatz ein abgelesener Stand, `S` eine als geschätzt erfasste Ablesung, `E`
ein **Ersatzwert** — an diesem Tag gibt es keinen Zählerstand, er ist
tagesgenau zwischen den umschließenden Ablesungen interpoliert. Das sind
genau die Stellen, an denen auch der Versorger schätzt; je weniger `E`,
desto weniger Schätzung steckt im Vergleich. Tooltip je Stand, Fußnote
unter der Tabelle. Über einen Zählertausch hinweg gibt es keinen
fortlaufenden Stand: der Ersatzwert bleibt dann ohne Zahl statt eine
falsche zu zeigen.

**API.** `bill-check`-Zeilen tragen `counter_from`/`counter_to` mit
`counter_from_kind`/`counter_to_kind` ∈ `reading`, `reading_estimated`,
`interpolated`, `null`.

**Tests.** 254 → 256: zwei PHPUnit-Fälle (Interpolation, geschätzte
Ablesung, Verkettung Stand neu = Stand alt; Zählertausch und Tage ohne
Intervall ohne Zahl), API-Shape (Felder, Ablesearten, Verkettung),
Browser-Render führt die Rechnungsprüfung jetzt wirklich aus (Spalten,
`E`-Kürzel mit Tooltip, abgelesene Stände ohne Kürzel, Fußnote). **Sieben
Kernannahmen per Toggle als greifend nachgewiesen.**

**Doku** DE + EN: Gas-Kapitel (Rechnungsprüfung), API-Referenz.
8 Katalogschlüssel × 7 Sprachen.

---

## [2.5.1] — 2026-09-15 — Sonderzahlungen in der Tabelle „Verträge & Abschläge"

PATCH-Release (UX-Politur). Kein Schema-Bump, keine Datenänderung.

**Der Anlass.** Die Tabelle *Verträge & Abschläge* in der Verbrauchsansicht
führte je Vertrag eine Spalte *Bonus*, aber keine Sonderzahlungen — eine
Rückzahlung aus der Jahresabrechnung stand nur in der Saldo-Karte des
laufenden Vertrags und verschwand aus dem Blick, sobald der Vertrag
vergangen war. Beim Nachrechnen einer echten Endabrechnung (v2.5.0,
Rechnungsprüfung) fiel das auf: Der Saldo eines abgeschlossenen Vertrags
war ohne die gebuchte Gutschrift nicht zu deuten.

**Neu.** Spalte **Sonderzahlungen** neben *Bonus*, für jeden Vertrag der
Historie: das **Netto aus Kundensicht** — Rückzahlungen erhalten positiv,
Nach- und Abschlagszahlungen geleistet negativ (dieselbe Größe, die der
Saldo als Sonderzahlungs-Netto addiert), bei mehreren Posten mit Anzahl.
Der Tooltip der Zelle listet jeden Posten mit Datum, Art, Betrag und Notiz;
ein Hinweistext unter der Tabelle erklärt *Bonus* und *Sonderzahlungen*.
Die Spalte erscheint nur bei Verbrauchsarten mit Abschlagsverträgen
(Gas, Strom, Fernwärme) — Wasser und PV-Einspeisung kennen keine
Sonderzahlungen, dort entfällt sie. Entschieden gegen eine gemeinsame
Spalte „Boni & Sonder": Bonus ist Vertragsbestandteil und mindert die
Kosten, eine Sonderzahlung ist Geld außerhalb des Abschlagsplans — zwei
Dinge, die in einer Zahl nicht mehr unterscheidbar wären. Die Tabelle
scrollt in schmalen Ansichten ohnehin horizontal; die Breite war kein
Grund, sie zu verschmelzen.

**API.** `contract-status` trägt je Vertrag `special_payments[]`
(`date`, `kind`, `amount_eur`, `note`; nach Datum sortiert, Beträge
positiv — die Richtung steckt in `kind`). Das Feld fehlt bei Wasser und
Einspeisung; sein Fehlen ist für die Oberfläche das Signal, die Spalte
nicht zu zeigen.

**Tests.** 250 → 254: `ContractStatusSpecialPaymentsTest` (Einzelposten und
Feldsatz, Netto-Vorzeichen, leere Liste, kein Feld bei Wasser), API-Shape
(Posten, Netto = Σ Rückzahlung − Σ gezahlt, Wasser ohne Feld),
Browser-Render (Spaltenkopf mit Erklärung, Zelle mit Netto und Tooltip,
Hinweistext, keine Spalte bei Wasser). **Neun Kernannahmen per Toggle als
greifend nachgewiesen**; ein zehnter Toggle deckte totes `abs()` auf — die
Beträge sind seit dem Speichern positiv — und wurde entfernt.

**Doku** DE + EN: Sonderzahlungen-Kapitel um „Wo Sonderzahlungen
erscheinen", UI-Referenz (Spalten der Vertragstabelle), API-Referenz und
API.md (`special_payments`). 3 Katalogschlüssel × 7 Sprachen.

---

## [2.5.0] — 2026-09-15 — F1012: Gas-Umrechnung mit Stichtagen und Rechnungsprüfung

MINOR-Release. **Schema 1.4.0 → 1.5.0** (additiv, Auto-Migration). Folge von
GitHub **#21** — der Melder wollte Gas in m³ erfassen; das ging immer
(v2.4.2 korrigierte nur das Etikett). Beim Nachsehen fiel auf, was wirklich
fehlte: ein Umrechnungsfaktor, der sich mit der Rechnung ändern darf.

**Das Problem.** Der Energietracker kannte **einen** Faktor
`gas_conversion_factor` für die ganze Historie. Eine Gasrechnung rechnet aber
mit zwei Größen — der **Zustandszahl** (an der Entnahmestelle, praktisch
konstant) und dem **Brennwert** (Periodenmittel des Netzbetreibers, wechselt
mehrmals im Jahr). Eine Jahresrechnung führt typischerweise drei bis vier
Brennwerte mit eigenen Zeiträumen. Wer den Faktor nachzog, veränderte damit
rückwirkend jeden Monat der Vergangenheit; wer ihn stehen ließ, hatte einen
kWh-Verbrauch, der zur Rechnung nicht mehr passte — Kosten je kWh, CO₂ und
Tarifvergleich inklusive.

**Die Lösung — F1012.**

- **Datierte Liste** `gas_conversion_factors` in den Einstellungen: je Eintrag
  Stichtag, Zustandszahl, Brennwert; der Faktor kWh/m³ wird daraus berechnet
  (fünf Nachkommastellen) — wer keine Aufschlüsselung hat, trägt ihn direkt
  ein. Die Zustandszahl wird aus dem letzten Eintrag vorbelegt.
- **Wirksam ist der letzte Eintrag, dessen Stichtag nicht nach dem Tag
  liegt.** Der undatierte Eintrag ist der migrierte Altwert und gilt für alles
  davor — die Historie rechnet exakt wie vor v2.5.0, nichts springt
  rückwirkend.
- **Tagesgenau.** Ein Stichtag mitten im Ableseintervall teilt das Intervall;
  jeder Tag rechnet mit seinem Faktor. Der Versorger tut dasselbe mit
  *geschätzten* Zwischenständen (Ableseart „S"); hier braucht es keine
  Schätzung, weil der Verbrauch ohnehin linear über das Intervall verteilt
  wird.
- **Plausibilitätsprüfung beim Speichern** (Zustandszahl 0,8–1,1, Brennwert
  8–13, Faktor 5–15 kWh/m³, höchstens ein undatierter Eintrag, keine
  doppelten Stichtage, Dezimalkomma erlaubt). Ein Tippfehler wie 115 statt
  11,5 hätte sonst jeden Verbrauch still verzehnfacht. Beim **Lesen** ist die
  Prüfung tolerant — ein Altbestand außerhalb der Bänder wird nicht wortlos
  durch den Default ersetzt.
- **Rechnungsprüfung** in der Gas-Verbrauchsansicht: für einen Zeitraum
  entstehen Abschnitte an jeder Ablesung und jedem Brennwertwechsel, je
  Abschnitt Tage · m³ · Zustandszahl · Brennwert · kWh/m³ · kWh — genau die
  Zeilen der Versorgerrechnung. Abschnitte ohne umschließende Ablesung
  erscheinen ohne Verbrauch, damit die Lücke sichtbar bleibt. Neuer Endpunkt
  `GET /api/utility/gas/meters/{id}/bill-check?from=&to=` (nur Gas).
- Heizöl und Pellets behalten ihren skalaren Faktor.

**Migration 1.4.0 → 1.5.0.** Der Skalar in `settings.json` wird zum
undatierten Listeneintrag; eine bereits vorhandene Liste bleibt unangetastet,
der Schritt ist idempotent. Als Lehre aus v2.4.1 hängt er in
`Migrator::UPGRADE_STEPS` — `needsMigration()` und `migrate()` lesen
dieselbe Liste, `MigrationCompletenessTest` wacht darüber. Der
v0.9.0-Legacy-Import liefert weiter den Skalar und wird im selben Lauf
umgewandelt.

**Oberfläche.** Einstellungen → Gas-Umrechnungsfaktoren als Tabelle mit
Vorschau des berechneten Faktors; Rechnungsprüfung als Karte in der
Gas-Verbrauchsansicht (Zeitraum vorbelegt mit dem gewählten Jahr). 48 neue
Katalogschlüssel × 7 Sprachen (2 entfallen), chirurgisch gesetzt.

**Demo-Daten** führen vier Perioden vor (undatiert 11,5 · 2024 · 2025 ·
Oktober 2025) — Verzeichnis und Backup, mit Gleichstands-Test.

**Tests.** 230 → 250. `ConversionFactorServiceTest` (19 Fälle: Ableitung,
Tagesauflösung, Grenzen, tagesgenaue Teilung am Monats- und mitten im Monat,
abgeleiteter Faktor schlägt mitgeschickten, Rechnungsaufschlüsselung mit
Lücken, Migration idempotent, Liste gewinnt über Skalar); API-Shape
(Settings-Form, `bill-check`-Zeilen, Strom → 400); Browser-Render (Tabelle,
JSON-Feld, Karte nur bei Gas). **12 Kernannahmen per Toggle als greifend
nachgewiesen** — zwei Tests wurden erst dadurch scharf (Stichtag auf
Monatsgrenze hatte die Teilung nie geprüft; die Ableitung war nie gegen
einen mitgeschickten Faktor getestet).

**Doku** DE + EN: `functional/01-gas.md` neu gefasst (Hintergrund, Stichtage,
Rechnungsprüfung), API-Referenz (Settings-Form, neuer Endpunkt),
Datenmodell/Glossar/Übersicht auf Schema 1.5.0, README-Funktionsliste
(F1011 dort nachgetragen).

---

## [2.4.2] — 2026-09-15 — Bugfix: Gas-Zählerstand war in der Erfassung mit kWh beschriftet

PATCH-Release. Kein Schema-Bump, keine Datenänderung. GitHub **#21**.

**Der Fehler.** Die zentrale Zählerstand-Erfassung (`#/zaehlerstaende`)
beschriftete das Eingabefeld für Gas mit „kWh". Ein Gaszähler zählt aber
Kubikmeter; kWh entsteht erst über den Umrechnungsfaktor aus den
Einstellungen. Gespeichert und gerechnet wurde **immer in m³** — nur das
Etikett war falsch. Die Zählerliste der Verbrauchsansicht zeigte korrekt m³.

**Die Ursache.** In der Utilities-SSOT gibt es zwei Einheiten je
Verbrauchsart: `unit` für den Zählerstand (Gas: m³) und `consumption_unit`
für den Verbrauch (Gas: kWh). Der Aggregat-Endpunkt `GET /api/readings-overview`
lieferte seit seiner Einführung (F1004, v1.6.0) **nur** `consumption_unit`. Die
Maske hatte damit keine andere Einheit zur Wahl und beschriftete den Stand mit
der des Verbrauchs. Unter den kumulativen Verbrauchsarten ist Gas die
**einzige**, bei der die beiden auseinanderfallen — Strom, Fernwärme und PV
zählen in kWh, Wasser in m³, jeweils identisch mit dem Verbrauch. Deshalb fiel
der Fehler nur bei Gas auf, und dort seit fünfzehn Releases nicht.

**Der Fix.** Der Endpunkt liefert jetzt beide Einheiten; die Maske beschriftet
Zählerstand, letzten Stand und Differenz-Vorschau mit `unit`. Keine Änderung
an Katalogen nötig — alle Texte tragen die Einheit als Platzhalter.

**Für Betroffene.** Wer dem Etikett gefolgt ist und Gasstände vor der Eingabe
selbst in kWh umgerechnet hat, hat Stände eingetragen, die um den
Umrechnungsfaktor zu hoch sind. Diese Ablesungen in der Verbrauchsansicht auf
den abgelesenen m³-Wert korrigieren; der Verbrauch berechnet sich danach
richtig.

**Tests.** Drei Schichten, jede per Toggle als greifend nachgewiesen:
`ReadingOverviewUnitsTest` (3 Fälle — beide Einheiten je Verbrauchsart gegen
die SSOT, und dass der Gasstand roh gespeichert und erst beim Verbrauch
umgerechnet wird), der API-Shape-Test verlangt `unit` und prüft für Gas
m³/kWh, der Browser-Render-Test verlangt m³ am Gas-Eingabefeld. Wird `unit`
aus der Antwort entfernt, werden alle drei rot; wird nur die Maske
zurückgedreht, der Render-Test. 227 → 230 Tests.

---

## [2.4.1] — 2026-08-20 — Hotfix: die v1.4.0-Migration lief auf Bestandsdaten nicht

PATCH-Release. Kein Schema-Bump, keine Datenänderung am Inhalt.

**Der Fehler.** In v2.4.0 wurde die neue Migrationsstufe `upgradeToV140()` in
`migrate()` eingehängt — aber nicht in `needsMigration()`, die Bedingung, die
`migrate()` überhaupt auslöst. Sie endete weiter bei v1.3.0.

Auf einer **Bestandsinstallation** (Schema 1.3.0, `external_id` vorhanden) lief
damit Folgendes:

1. `needsMigration()` meldete **false** — es gab aus ihrer Sicht nichts zu tun.
2. Der Bootstrap fiel in seinen dritten Zweig `!isAlreadyMigrated() →
   initFresh()`. Dieser Zweig ist als Netz für ein unvollständig angelegtes
   Verzeichnis gedacht und geht davon aus: „nicht migriert und keine Migration
   nötig" heiße „frisches Verzeichnis".
3. `initFresh()` schrieb `meta.json` mit der neuen Schemaversion, ohne die
   Zähler anzufassen.

Danach galt die Installation als migriert, obwohl kein Zähler das Feld
`baseline_events` trug — und die Migration konnte es **nie mehr nachholen**.
Die Anwendung lief weiter, weil alle Lesestellen über `?? []` abgesichert sind;
F1011 war dort schlicht nicht scharf.

**Nutzdaten waren nie in Gefahr.** `initFresh()` legt Dateien nur an, wenn sie
fehlen. Nachgemessen auf der betroffenen Installation: 48 von 49 Dateien
byte-identisch zum Stand davor, alle Zählungen (Ablesungen, Verträge, Zähler,
Lieferungen, Temperaturen, Einstellungen) unverändert. Verändert war
ausschließlich `meta.json` — erkennbar am `created_at` statt `migrated_at`.

**Der Fix.** Die Migrationsstufen stehen jetzt in **einer** Liste
(`Migrator::UPGRADE_STEPS`), aus der `needsMigration()` und `migrate()` beide
lesen. Eine neue Stufe ist ab jetzt ein Listeneintrag, sonst nichts.

**Warum kein Test das gefangen hat:** Alle bestehenden Migrationstests riefen
`needsVXXXUpgrade()` und `upgradeToVXXX()` **direkt** auf — also genau an
`needsMigration()` vorbei. Neu ist `MigrationCompletenessTest` (5 Fälle):

- Ein Reflection-Wächter hält die Liste vollständig: Jede
  `needsVXXXUpgrade()`-Methode **muss** eingetragen sein.
- Zu jeder Prüfmethode muss die Ausführmethode existieren.
- Eine Installation auf dem Vorgängerschema muss migrieren **wollen**.
- Nach der Migration trägt jeder Zähler das Feld, und `meta.json` hat
  `migrated_at` **und** kein `created_at` — Letzteres wäre das Zeichen, dass
  wieder der falsche Zweig lief.
- Ein zweiter Lauf ändert nichts.

222 → 227 Tests. Zwei Toggle-Beweise: Fehlt die v1.4.0-Stufe in der Liste, wird
der Wächter rot; wird `needsMigration()` auf die alte, doppelt gepflegte Form
zurückgedreht, wird der Bestandsdaten-Test rot.

---

## [2.4.0] — 2026-08-20 — Analyse-Zäsur: Auswertungen ab der Sanierung

MINOR-Release. **F1011** (GitHub #20), Schema 1.3.0 → 1.4.0 (additiv).

**Das Problem.** Nach einer baulichen Maßnahme ist ein Gebäude thermisch ein
anderes: Es braucht dauerhaft weniger je Kältegrad. Der Energietracker rechnete
bisher **immer über die volle Historie** — ein Zeitfenster gab es nirgends.
Damit beschreibt die Heizkurve keinen der beiden Zustände, sondern einen
gewichteten Mittelwert aus beiden, und zwar so lange, bis die neuen Monate die
alten überwiegen. Bei zwölf Jahren Historie dauert das Jahre.

Betroffen war weit mehr als das Diagramm. Acht Auswertungen hingen an derselben
Basislinie: die fünf Regressionsmodelle im Analyse-Chart, der Erwartungswert
`expected_hgt`, die Abweichung `delta_pct`, die Prognose (Heizkurve **und**
Saisonmittel), die Anomalie-Erkennung, die Empfehlungen R1/R2/R4 — und über die
Prognose auch der Tarifvergleich. Praktische Folge für Betroffene: Die App
meldete **jeden Monat** „unter dem Mittel", die Mehrverbrauchs-Empfehlung konnte
faktisch nicht mehr auslösen, und der erwartete Jahresverbrauch — die Eingabe
für jeden Tarifvergleich — fiel systematisch zu hoch aus.

**Die Lösung.** Am Zähler lassen sich **Analyse-Zäsuren** eintragen: ein Datum
mit einer Bezeichnung („Dachdämmung", „Wärmepumpe", „neue Fenster"). Wirksam ist
die späteste Zäsur, deren Datum erreicht ist; ein künftiges Datum darf
vorgemerkt werden und wirkt noch nicht. Ohne Zäsur verhält sich alles exakt wie
bisher.

- **Eine Markierung, alle Verbraucher.** Die Monatszeilen bekommen einmal ein
  `pre_baseline` — dort, wo bereits `device_swap` gesetzt wird. Jede Auswertung
  überspringt markierte Zeilen. Damit ist die Bereichsregel per `grep` prüfbar,
  statt in fünf Aggregatoren einzeln nachgebaut zu werden. Genau daran scheiterte
  die Subzähler-Regel aus F1006, die in `forUtility` stand und in drei weiteren
  Auswertungen nicht (behoben in v2.1.3).
- **Daten bleiben sichtbar.** Punkte vor der Zäsur werden im Chart ausgegraut
  statt entfernt — ausgeschlossen wird aus dem *Modell*, nicht aus der Anzeige.
  `weather_adjusted` wird für sie weiter berechnet: Der Wert ist
  gebäudeunabhängig und trägt den Vergleich.
- **Der Übergangsmonat zählt als „davor".** Fällt die Zäsur nicht auf den
  Monatsersten, mischt dieser Monat beide Zustände und bleibt aus dem Modell.
- **Wirkung der Maßnahme.** Beide Epochen werden getrennt gefittet und die
  Steigungen ausgewiesen — Verbrauch je Gradtag, also bereits
  witterungsbereinigt: *„0,42 → 0,28 m³ je Gradtag, −33 %."* Damit deckt der Code,
  was `docs/functional/08-szenario-eigenheim.md` §5 seit jeher als **wertvollste
  Anwendung** beschrieb und was bisher nichts ausrechnete. §5 ist entsprechend
  neu gefasst.
- **Drei stumme Untergrenzen sprechen jetzt.** Unter 12 Monaten entfällt die
  Wetterbereinigung komplett, unter 8 Punkten die Regression, unter 5 Monaten die
  Anomalie-Erkennung — bislang jeweils **wortlos**. Die Oberfläche schreibt nun
  aus, was fehlt und wie viel („Die Regression braucht mindestens 8 Messpunkte ab
  der Zäsur — vorhanden sind 5"). Der Hinweis greift **auch ohne Zäsur**: Wer
  schlicht erst sieben Monate Daten hat, bekommt dieselbe Erklärung. Kein stiller
  Rückfall auf die volle Historie — der wäre die gefährlichste Variante gewesen.

**Schema-Migration** 1.3.0 → 1.4.0: neues Zählerfeld `baseline_events` mit
Default `[]`, idempotent nach dem Muster von `external_id`. Das Feld reist in
`meters.json` automatisch im Backup mit; ein eigener Datentopf hätte in die
hartkodierte `BackupService`-Feldliste gemusst (Falle aus v2.1.2). Ein
Roundtrip-Test hält es trotzdem fest.

**Tests:** neue `BaselineCutoffTest` mit 25 Fällen, dazu ein Demo-Daten-Test.
196 → 222. **17 Kernannahmen per Toggle als greifend nachgewiesen** — jede
Manipulation ging rot, die Syntax jeder manipulierten Datei wurde geprüft, damit
kein Rotlauf durch einen Syntaxfehler als Beweis durchgeht. Zusätzlich 28
Prüfungen über echtes HTTP gegen einen laufenden Server und ein Browser-Durchlauf
der Analyse- und Zählersicht.

**Demo-Daten** führen die Zäsur vor (Gas-Hauptzähler, 2024-08-01), damit der
Vorher/Nachher-Vergleich nach „Demo laden" tatsächlich zu sehen ist — in
Verzeichnisform **und** im Demo-Backup, mit Test auf Gleichstand.

**Sprachen:** 26 neue Katalogschlüssel × 7 Sprachen, chirurgisch in die Kataloge
gesetzt statt sie neu zu formatieren — die Kataloge sind handgepflegt, kein
Dumper reproduziert sie byte-gleich (nachgemessen).

**Doku** DE und EN nachgezogen: Datenmodell, API-Referenz, Architektur,
Szenario-Kapitel.

---

## [2.3.5] — 2026-08-03 — README zeigt, was das Projekt kann

PATCH-Release. Nur Dokumentation und Tests.

### Added

- **Statusabzeichen im README** (DE und EN): CI und Docker-Publish als
  Live-Badges aus GitHub Actions, dazu Version, Lizenz, PHP-Anforderung,
  Testzahl, PWA, Docker-Architekturen, Sprachen, Verbrauchsarten und
  Abhängigkeiten. Bisher standen dort drei Abzeichen; die Eigenschaften, die
  das Projekt ausmachen — installierbar als PWA, multi-arch-Container, sieben
  Sprachen, acht Verbrauchsarten, **null Laufzeit-Abhängigkeiten** — waren nur
  im Fließtext zu finden.

- **Zwei Tests, die die Abzeichen ehrlich halten**
  (`ReleaseConsistencyTest`):
  - Die Zahlen für Tests, Sprachen und Verbrauchsarten werden gegen die
    Wirklichkeit geprüft (Testmethoden unter `tests/unit/`, Kataloge unter
    `public/locales/`, Einträge in der Utilities-SSOT). Ein Abzeichen, das
    „196 Tests" behauptet, während es 210 sind, sieht nach geprüfter
    Information aus und ist deshalb schlimmer als keines.
  - Die CI-Abzeichen müssen auf Workflows zeigen, die es gibt — sonst meldet
    GitHub dauerhaft „no status".

### Fixed

- **„Chart.js via CDN" stimmte seit v2.2.0 nicht mehr.** Beide READMEs
  schränkten die Aussage „keine externen Abhängigkeiten" mit einem Verweis auf
  ein CDN ein. Chart.js und die Schriften liegen seit v2.2.0 unter
  `public/vendor/` im Repository; die Anwendung stellt keine externen Anfragen
  — geprüft durch `testFrontendHasNoExternalResourceReferences`. Der Text sagt
  das jetzt auch.

194 → 196 Tests. **Kein Schema-Bump** — Schema bleibt 1.3.0.

---

## [2.3.4] — 2026-08-03 — Release-Prozess entrümpelt

PATCH-Release. Nur Dokumentation, kein Codeänderung.

### Changed

- **Der ZIP-Bau ist aus dem Release-Prozess entfallen** (DE und EN). Seit
  v2.0.0 trägt kein Release mehr Anhänge; die Installation läuft über das
  Container-Image von GHCR, `git clone` oder `git checkout` eines Tags. Die
  Beschreibung stand seit v1.4.2 unverändert da und beschrieb einen Ablauf,
  den es nicht mehr gab.

- **Das CI-Gate steht jetzt ausdrücklich zwischen Push und Tag.** Bisher
  zeigte die Anleitung `git push origin main --tags` — beides in einem
  Schritt. Damit entsteht der Tag, bevor die Pipeline ihn bestätigt hat, und
  er ist die Grundlage für Container-Image und GitHub-Release. Der Ablauf ist
  jetzt in drei Schritte getrennt, mit dem Gate dazwischen.

- **Der Smoke-Test läuft gegen eine Kopie der Demo-Daten** statt gegen ein
  entpacktes Archiv — über `ET_DATA_DIR`, nie gegen das lokale `data/`, in dem
  echte Nutzdaten liegen.

Keine Auswirkung auf Anwendung, Daten oder Schema. 194 Tests unverändert.

---

## [2.3.3] — 2026-08-03 — Beispieldaten in der Dokumentation

PATCH-Release. Kein Codeänderung, keine Funktionsänderung.

### Changed

- **Die Migrationsdoku (`MIGRATION-FROM-V090.md`, DE und EN) zeigte Datensätze
  aus einer realen Installation** statt erfundener Beispiele — mit Vertrags-
  und Ablese-IDs, Anbieter- und Tarifnamen, einem Zählerstand, einem
  Abschlagsbetrag und einer Nummer im `notes`-Feld, die wie ein Zählpunkt
  aussieht. Die Struktur der Beispiele ist unverändert; nur die Werte sind
  jetzt eindeutig fiktiv.

- **CHANGELOG und Roadmap** beschrieben Befunde aus einer konkreten
  Installation mit Vertragslaufzeiten und Beträgen. Die Sachverhalte bleiben
  vollständig erhalten, die Daten sind entfernt.

Zur Klarstellung: Nutzdaten waren zu keinem Zeitpunkt versioniert. Unter
`data/` liegen ausschließlich vier leere `.gitkeep`-Dateien; `.gitignore`
schließt Ablesungen, Verträge, Zähler, Lieferungen, Temperaturen,
Einstellungen und Backups aus, und die Historie enthält nie etwas anderes.
Betroffen waren allein die oben genannten Beispiele und Beschreibungen.

**Regel für künftige Releases:** Befunde aus einer konkreten Installation
gehören nicht mit Laufzeiten, Anbietern, Nummern oder Beträgen in Repository,
Commit-Message oder Release-Notes. Sachverhalt beschreiben, Daten weglassen.

---

## [2.3.2] — 2026-08-03 — Überlappende Verträge werden deterministisch aufgelöst

PATCH-Release aus einem Qualitätsdurchlauf über Code, Kataloge und Doku.

### Fixed

- **Bei überlappenden Verträgen entschied die Speicherreihenfolge, welcher
  Tarif gilt.** `ContractService::findActiveForDate()` nahm den ersten
  passenden Eintrag des Arrays — und dessen Position ergibt sich aus der
  Anlage-Reihenfolge in der JSON-Datei, nicht aus der Fachlichkeit. Dieselbe
  Datenlage konnte damit unterschiedliche Kosten ergeben, je nachdem, in
  welcher Reihenfolge die Verträge irgendwann gespeichert wurden.

  Jetzt gewinnt der **späteste Beginn**: Ein neu abgeschlossener Vertrag löst
  den älteren ab. Ohne Überlappung ändert sich nichts.

  Der Fall ist praxisrelevant und nicht konstruiert: Ein Vertrag, der
  vollständig innerhalb der Laufzeit eines anderen liegt, entsteht leicht
  beim Nachtragen älterer Verträge. Die Methode wird von vier Services genutzt
  (Verbrauch, Prognose, Tarifvergleich, Wechselentscheidung), die Auflösung
  wirkt also auf Kosten, Prognose und Wechselempfehlung gleichermaßen.

### Tests

- `OverlappingContractsTest` (5 Fälle): späterer Beginn gewinnt unabhängig von
  der Array-Reihenfolge, außerhalb der Überlappung gilt weiter der verbleibende
  Vertrag, ohne Überlappung bleibt alles wie zuvor, unbefristete Verträge
  greifen weiterhin, und die Kostenrechnung setzt je Monat genau einen Vertrag
  an — nie beide zugleich.
- 189 → 194 Tests.

**Kein Schema-Bump** — Schema bleibt 1.3.0.

---

## [2.3.1] — 2026-08-03 — Hotfix: Tarifvergleich startete nicht, Folgeverträge wurden übergangen

PATCH-Release. Behebt zwei Fehler aus v2.3.0, beide erst im laufenden Betrieb
aufgefallen, plus eine Lücke in der Vertragspflege.

### Fixed

- **Der Tarifvergleich startete nicht: „api.tariffSwitch is not a function".**
  Der Server lieferte die richtige `api.js` aus, der Browser nutzte eine
  gecachte Vorversion ohne die neue Funktion.

  Die Wurzel lag tiefer als der v2.2.3-Fix reichte: Der Cache-Buster hing nur
  am Einstiegspunkt `app.js`. Die Module importieren einander ohne Query
  (`./api.js`, nicht `./api.js?v=…`), also durfte jeder Cache eine beliebig
  alte Kopie liefern. Die Selbstheilung aus v2.2.3 räumt zwar die Cache-API
  auf, nicht aber den **HTTP-Cache des Browsers** — und Produktion sendete für
  Module überhaupt kein `Cache-Control`, nur ETag und Last-Modified. Safari
  cachte entsprechend heuristisch.

  Zwei Maßnahmen:

  1. **Import-Map.** Die Shell erzeugt aus dem Dateibestand eine Map, die jeden
     Modulpfad auf `?v=<version>-<mtime>` abbildet. Weil der Browser
     Import-Specifier vor dem Laden auflöst, bekommt damit auch ein Import tief
     im Graphen seine Version — ohne dass eine einzige `import`-Zeile angefasst
     werden muss.
  2. **Cache-Header.** Eine `.htaccess` (Apache/Synology) und die
     Docker-nginx-Konfiguration lassen `/public/js/` und `/public/locales/`
     immer revalidieren. Mit ETag kostet das ein 304, keine erneute
     Übertragung. Schriften bleiben langlebig gecacht.

- **Bereits abgeschlossene Folgeverträge wurden übergangen.** Lief ein
  Anschlussvertrag schon, meldete das Modul dessen Beginn als „Wechseltermin" —
  obwohl der Wechsel damit längst vollzogen war. Schlimmer: Die Vergleichsbasis
  rechnete das ganze Folgejahr mit den Preisen des **auslaufenden** Vertrags
  weiter, obwohl der neue galt. Je nach Preisunterschied weicht die Referenz
  dadurch um einen dreistelligen Betrag pro Jahr ab.

  Der Vergleich folgt jetzt der **Bindungskette**: dem laufenden Vertrag plus
  allem, was lückenlos anschließt. Deren Ende bestimmt Kündigungstermin und
  Wechseldatum, und jeder Monat des Vergleichsfensters rechnet mit dem Tarif,
  der dann gilt. Eine Lücke von mehr als einem Tag beendet die Kette. Die
  Oberfläche weist einen abgeschlossenen Anschlussvertrag aus.

### Added

- **Kündigungsfrist, Mindestlaufzeit und Preisgarantie im Vertragsformular.**
  Bisher ließen sich diese Felder nur beim Anlegen eines Angebots im
  Tarifvergleich setzen — an bestehenden Verträgen waren sie unerreichbar, und
  ohne sie kann der Vergleich weder einen Termin errechnen noch vor einer
  ablaufenden Frist warnen.

### Tests

- `ModuleCacheSafetyTest` prüft die Import-Map (vorhanden, vor dem ersten
  Modul-Script, genau eine, aus dem Dateibestand erzeugt) und dass beide
  Server-Konfigurationen Anwendungscode revalidieren lassen.
- `TariffSwitchServiceTest` um vier Fälle erweitert: Folgevertrag verschiebt
  den Termin, Referenz nutzt dessen Preise, eine Lücke beendet die Kette, und
  ein Fenster über zwei Verträge rechnet jeden Monat mit dem richtigen Tarif.
- 183 → 189 Tests.

---

## [2.3.0] — 2026-08-03 — Tarifvergleich wird zur Wechselentscheidung

MINOR-Release. Das Modul beantwortete bisher „Was hätte Tarif X gekostet?" —
eine Frage über die Vergangenheit. Jetzt beantwortet es „Soll ich wechseln?".

Kein Schema-Bump: Die neuen Vertragsfelder sind additiv und optional,
Bestandsdaten bleiben unverändert gültig (Schema bleibt 1.3.0).

### Added

- **Wechselentscheidung als neuer Block im Tarifvergleich.** Die Ansicht führt
  jetzt den Ablauf, um den es geht: Der **erwartete Jahresverbrauch** aus der
  Prognose steht groß und kopierbar oben — genau die Zahl, die CHECK24 und
  Verivox als Eingabe verlangen. Der Nutzer geht damit raus, sucht selbst und
  trägt das gefundene Angebot als Schattenvertrag ein. Eine Anbindung an
  Vergleichsportale gibt es bewusst nicht.

- **Wechseltermin und Kündigungsfrist.** Verträge tragen optional
  `notice_period_months`, `min_term_end` und `price_guarantee_until`. Daraus
  errechnet die Anwendung den frühestmöglichen Wechseltermin und den Stichtag,
  bis zu dem gekündigt werden muss — mit Restlaufzeit und Hervorhebung, sobald
  es eng wird. Ohne gepflegte Frist wird kein Termin behauptet, sondern nach
  der Angabe gefragt. Im Vergleich lässt sich das Datum frei überschreiben.

- **Jahr 1 und ab Jahr 2 getrennt.** Angebote tragen den Neukundenbonus als
  Betrag (`signup_bonus_eur`) statt als Gutschriftsdatum — auf dem Portal steht
  „Bonus 130 €", wann er gutgeschrieben wird, weiß beim Anlegen niemand.
  **Sortiert wird nach dem dauerhaften Preis**, sonst gewinnt jedes
  Lockangebot die Rangfolge.

- **Break-even-Verbrauch** statt einer Ersparnis auf den Euro genau: „günstiger,
  solange über 3.600 kWh". Das ist die belastbare Antwort auf eine unsichere
  Prognose — liegt der Schnittpunkt weit vom erwarteten Verbrauch weg, trägt
  die Entscheidung auch dann, wenn die Prognose danebenliegt. Ergänzend eine
  Spanne für ±10 % Verbrauch.

- **Kostenverlauf als Overlay.** Die Angebote werden als Linie über den
  Bestandsvertrag gelegt, monatlich statt als Jahressumme — erst daran sieht
  man, wo die Differenz herkommt (bei Gas fast vollständig im Winter). Monate
  jenseits der Preisgarantie sind gestrichelt: Dort ist der Preis eine Annahme.

- **Neuer Endpunkt** `GET /api/utility/{u}/meters/{id}/tariff-switch`,
  optional mit `?switch_date=YYYY-MM-DD`.

- **Demo-Daten** enthalten ein vollständiges Wechselszenario: Kündigungsfrist
  am laufenden Vertrag und je zwei gegenläufig gebaute Angebote für Gas und
  Strom (niedriger Arbeitspreis bei hohem Grundpreis und umgekehrt), damit der
  Break-even in der Demo sichtbar wird statt theoretisch zu bleiben.

### Fixed

- **Kündigungsfristen verfehlten den Stichtag um bis zu vier Wochen.** PHPs
  `strtotime('2026-03-31 -1 month')` liefert **2026-03-03**: Der 31. Februar
  existiert nicht, der Überlauf bleibt stehen. Die Monatsarithmetik klemmt den
  Tag jetzt auf das Monatsende. Der Fehler entstand mit diesem Release und
  wurde vor der Auslieferung gefunden — für einen Nutzer, der sich auf den
  Stichtag verlässt, hätte er ein weiteres Vertragsjahr bedeutet.

### Changed

- **Der Rückblick bleibt, rückt aber nach unten** und ist eingeklappt.
  Dieselben Tarife auf die tatsächlich gemessenen Monate gelegt — er ist der
  Beleg, dass die Rechnung auf echten Daten aufgeht, aber nicht der Grund,
  warum jemand die Ansicht öffnet.

- **Vergleichsfenster sind zwölf Monate ab Wechseltermin**, saisonal gewichtet
  statt in Zwölfteln. Ein Wechsel zum 1. Juli deckt damit trotzdem einen vollen
  Winter ab.

### Tests

- `TariffSwitchServiceTest` (17 Fälle): Wechseltermin aus der Kündigungsfrist
  inklusive Monatsüberlauf, saisonale Verteilung, Bonus nur im ersten Jahr,
  Rangfolge nach dem dauerhaften Preis, Break-even gegen die analytische
  Lösung, Preisgarantie-Markierung — und als Regression, dass Schattenverträge
  weiterhin **nicht** in die Prognose selbst einfließen (v2.2.0-Fix).
- `SwitchFieldsBackupTest` (2 Fälle): Die neuen Vertragsfelder überstehen einen
  Backup-Roundtrip, und Backups von vor v2.3.0 lassen sich weiterhin
  importieren. Anlass ist v2.1.2, wo eine hartkodierte Feldliste im
  `BackupService` drei Datentöpfe lautlos verschluckt hat — ein verlorener
  Kündigungstermin fällt erst auf, wenn die Frist verstrichen ist.
- Der Browser-Render-Test der CI prüft die Ansicht jetzt mit sechs Zusicherungen
  statt einer: Wechselblock gefüllt, Jahresverbrauch sichtbar und kopierbar,
  Wechseltermin wählbar, Rückblick vorhanden und mit Zeilen. Er hing zuvor an
  einem Element (`#t-result`), das es nach dem Umbau nicht mehr gibt.
- 164 → 183 Tests (PHPUnit) plus 42 Browser-Render-Zusicherungen.

---

## [2.2.3] — 2026-08-03 — Hotfix: Oberfläche blieb nach dem Update bei „Lädt…"

PATCH-Release. Behebt einen Fehler, der bestehende Installationen nach dem
Update auf v2.2.0 oder neuer unbrauchbar machte.

### Fixed

- **Nach dem Update blieb die Oberfläche bei „Lädt…" stehen.** Die ES-Module
  importieren einander ohne Cache-Buster (`./lib/sidebar.js`, nicht
  `…?v=2.2.2`). Der Service Worker lieferte sie unter
  `stale-while-revalidate` aus dem alten Cache aus, während die Shell bereits
  neu war. Ergebnis: Ein frisches `app.js` importierte `refreshSidebarBadges`
  aus einem gecachten `sidebar.js` der Vorversion, das diesen Export nicht
  kennt — `SyntaxError`, der Modulgraph brach vollständig ab, und es blieb bei
  der Ladeanzeige. Betroffen war jede Installation mit installiertem Service
  Worker, also praktisch jede laufende Instanz.

  Zwei Maßnahmen:

  1. **Selbstheilung.** Ein kleines Skript in der Shell läuft vor den Modulen.
     Trägt ein Cache eine andere Version als die ausgelieferte Seite, räumt es
     Caches und Worker ab und lädt genau einmal neu (Sperre in `sessionStorage`
     gegen Schleifen). Bestehende kaputte Installationen reparieren sich damit
     beim nächsten Aufruf von selbst — ohne Zutun der Nutzer.
  2. **Ursache beseitigt.** Anwendungscode und Sprachkataloge (`/public/js/`,
     `/public/locales/`) laufen im Service Worker jetzt **network-first** statt
     stale-while-revalidate. Ein einzelner veralteter Baustein legt die ganze
     Anwendung lahm; das ist den Geschwindigkeitsvorteil nicht wert. Stile,
     Schriften und Chart.js bleiben stale-while-revalidate — sie stehen für
     sich und reißen nichts mit. Offline funktioniert unverändert über den
     Cache-Rückfall.

  Nachgestellt und geprüft: Installation auf v2.1.5 mit aktivem Worker, Wechsel
  auf v2.2.2 → `SyntaxError`, Ladeanzeige. Mit dem Fix heilt derselbe Browser
  beim ersten Aufruf, ohne Konsolenfehler, alte Caches abgeräumt.

---

## [2.2.2] — 2026-08-03 — Demo-Termine, vollständige Testabdeckung

PATCH-Release. Die Demo-Daten zeigen jetzt auch das Termin-Modul, und die
letzten beiden Dienste ohne Test haben einen bekommen. Keine Schema- oder
API-Änderung.

### Fixed

- **Die Demo-Daten brachten keine Termine mit.** Weder
  `demo-data/reminders.json` noch das Demo-Backup führten Einträge, sodass die
  Termin-Ansicht nach „Demo laden" leer blieb — obwohl die Anwendung das Modul
  mitbringt. Dieselbe Klasse wie die fehlenden Heizöl- und Pellets-Lieferungen
  in v2.1.2: Das Backup trägt eine feste Feldliste, und ein neuer Datentopf
  muss dort mitgezogen werden. Jetzt sechs Termine über fünf Kategorien, mit
  überfälligem, fälligem und ruhendem Eintrag, damit die Statusfarben sichtbar
  werden.

### Added

- **`MigrationServiceTest`** (10 Fälle) — der v0.9.0-Migrationspfad war der
  einzige Weg, über den fremde Bestandsdaten hereinkommen, und hatte keinen
  Test. Geprüft: Formaterkennung samt Ablehnung unbekannter Versionen,
  Übersetzung ins aktuelle Schema, die Zählerwechsel-Heuristik (nur explizite
  Hinweise werden Kandidat, `is_notable` allein nicht), beide Schreibmodi und
  die Sicherheitskopie vor jedem Schreiben.
- **`PdfReportServiceTest`** (5 Fälle) — inklusive der Subzähler-Regel aus
  v2.1.3, die im Bericht bisher nur „analog" abgedeckt war. Die Prüfung liest
  die gedruckten Zahlen direkt aus dem Dokument: Ohne den Ausschluss stehen dort
  1.680 statt 1.200 kWh. Dazu: gültiges Dokument, leerer Jahrgang ohne Fehler,
  Aufbau in allen sieben Sprachen ohne unaufgelöste Katalogschlüssel und das
  Weglassen abgeschalteter Verbrauchsarten.
- **`DemoServiceTest`** um drei Fälle erweitert: Termine kommen aus dem Backup,
  decken mehrere Kategorien und Zustände ab, und Verzeichnis- wie Backup-Pfad
  führen dieselben Einträge — die beiden Wege liefen bisher auseinander.

**Damit haben alle 29 Dienste einen Test.** PHPUnit 143 → 161 (721 Assertions).

PATCH-Release. Der Rest der Backend-Texte ist katalogisiert, ein sprachabhängiger
Fehler im HTTP-Status behoben, und die UI-Referenz zeigt wieder den echten Stand.
Keine Schema- oder API-Änderung.

### Fixed

- **Der HTTP-Status hing an der Anzeigesprache.** `ErrorHandler::statusFor()`
  leitete „nicht gefunden" aus dem Wortlaut der Ausnahme ab
  (`str_contains($msg, 'nicht gefunden')` bzw. `'not found'`). Seit v2.0.0
  werfen die Dienste lokalisiert — eine spanische Oberfläche meldet „Contador no
  encontrado", eine französische „Compteur introuvable". Beide Muster griffen
  nicht, und der Client bekam **500 statt 404**: ein fehlender Datensatz sah aus
  wie ein Serverfehler. Jetzt entscheidet der Typ (`Http\NotFoundException`);
  die Textprüfung bleibt als Rückfall.

### Added

- **Die restlichen nutzersichtbaren Backend-Texte sind katalogisiert.** Alle acht
  Controller haben jetzt Zugriff auf den Übersetzungsdienst: Zähler- und
  Vertragsmeldungen, der Hinweis nach dem Erzeugen eines API-Tokens, die
  Rückmeldungen der v0.9.0-Migration, die Temperatur- und CSV-Importfehler sowie
  die Kopfzeilen des Monatsexports folgen der eingestellten Sprache. Auch die
  Bezeichner in den Vertragsprüfungen („Arbeitspreis", „Trinkwasser-Grundpreis" …)
  erschienen bisher deutsch in einer sonst übersetzten Fehlermeldung.
- `NotFoundStatusTest` hält fest, dass der Typ auch in einer Sprache greift,
  deren Wortlaut weder dem deutschen noch dem englischen Muster entspricht.

### Changed

- **Alle 13 Screenshots der UI-Referenz neu aufgenommen.** Die bisherigen
  stammten aus v1.9.2 und zeigten unter anderem den Tarifvergleich mit dem
  Rechenfehler, den v2.2.0 behoben hat (viermal derselbe Verbrauch bei völlig
  verschiedenen Kosten). Die neuen zeigen den aktuellen Stand samt der
  Verbrauchsart-Farben für alle acht Arten. Trotz größerem Bildausschnitt sind
  sie mit 824 KB kleiner als die alten 3,4 MB.

### Bewusst nicht übersetzt

Vier Bereiche bleiben deutsch, jeweils aus einem Grund:

- `JsonStore` (Speicherfehler) — eine Übersetzung erzeugte eine
  Zirkelabhängigkeit: JsonStore → I18n → Settings → JsonStore.
- `Storage\Migrator` (Migrationsprotokoll in `meta.json`) — Betriebsdokumentation,
  die einmal geschrieben und nie neu übersetzt wird.
- `Router` (unbekannte Route) — läuft vor der Anwendungsschicht und richtet sich
  an Entwickler; jetzt englisch statt deutsch.
- Ausnahmen, die Programmierfehler melden (`DeliveryConsumptionService`,
  `Utilities::get()`) — sie erreichen nie eine Oberfläche.

---

## [2.2.0] — 2026-08-03 — Tarifvergleich neu, Farben aus der SSOT, eigene Assets

MINOR-Release aus einem vollständigen Review von Code, Oberfläche, Sprachen,
Tests und Dokumentation. Vier stille Rechenfehler behoben, der Tarifvergleich
neu aufgesetzt, die Verbrauchsart-Farben ändern sich sichtbar, Schriften und
Chart.js kommen nicht mehr von fremden Servern. Keine Schema- oder
Datenänderung (Schema bleibt 1.3.0); die API des Tarifvergleichs liefert
zusätzliche Felder, die bestehenden behalten ihre Bedeutung.

### Changed — Tarifvergleich

- **Jede Kennzahl bezieht sich jetzt auf die Monate, die der Vertrag wirklich
  abdeckt.** Bisher meldete jede Zeile den Gesamtverbrauch des Zeitraums,
  rechnete die Kosten aber nur über die eigenen Monate. Ein Schattenvertrag ab
  Juli zeigte damit den vollen Jahresverbrauch neben einem halben Jahr Kosten —
  und wirkte etwa doppelt so günstig, wie er ist. Im Reproduktionsfall wurden
  49 % Ersparnis ausgewiesen, wo es real rund 15 % waren.
- **Neue Spalte „ct/Einheit"** — Vollkosten je kWh bzw. m³ aus Arbeitspreis,
  Grundpreis und Boni. Sie ist die einzige zeitraumunabhängige Größe und macht
  unterschiedlich lange Laufzeiten überhaupt erst vergleichbar; die Rangfolge
  richtet sich nach ihr.
- **Hochrechnung auf die volle Periode** bei Teilabdeckung, damit „was hätte
  das ganze Jahr gekostet?" beantwortbar bleibt, ohne die Ist-Zahlen zu
  verfälschen. Das Balkendiagramm vergleicht auf dieser Basis.
- **Die Differenz geht gegen die real abgerechneten Kosten derselben Monate**,
  nicht mehr gegen die Summe aller echten Verträge des Zeitraums. Bei einem
  Anbieterwechsel im Jahr wies vorher jeder echte Vertrag eine Differenz gegen
  sich selbst aus. Zusätzlich als Prozentwert.
- **Schattenverträge lassen sich im Modul bearbeiten und löschen**, mit
  Ende-Datum. Bisher konnte man sie nur anlegen — und wurde sie in der
  Vertragsansicht nicht wieder los, weil sie dort nicht als Hypothese
  erkennbar waren.
- Verträge ohne einen einzigen Monat im Zeitraum erzeugen keine Leerzeile mehr;
  die Einheit kommt aus der Utilities-SSOT statt aus einem festen „kWh"; die
  Hinweistexte sind übersetzt.
- Abschläge und Sonderzahlungen bleiben bewusst außen vor: Sie sind
  Zahlungsströme gegen den Saldo, keine Tarifkosten. Der Legendentext sagt das
  jetzt auch.

### Changed — Darstellung

- **Verbrauchsart-Farben kommen zur Laufzeit aus der SSOT.** Die
  handgepflegten `--util-*`-Token kannten nur Gas, Strom und Wasser — die fünf
  später ergänzten Arten (Fernwärme, Heizöl, Pellets, PV-Einspeisung,
  PV-Erzeugung) hatten weder Überschriften- noch Button- noch KPI-Farbe, keinen
  Aktiv-Marker in der Seitenleiste und keine hervorgehobene Jahres-Pille. Ein
  Heizöl-Haushalt sah eine entfärbte Anwendung. **Sichtbare Folge:** Gas und
  Strom wechseln auf ihre SSOT-Farbe (Amber statt Orange, Cyan statt Mint) —
  bisher zeigte das Diagramm eine andere Farbe als das Bedienelement daneben.
- **Textkontrast auf WCAG AA gehoben.** `--text-2` erreichte auf Kartengrund
  4,38:1 und auf verschachtelten Flächen 3,71:1; die Werte tragen als `.muted`
  fast alle Sekundärtexte. Jetzt 6,6:1 bzw. 5,7:1. Auch das helle Theme hatte
  einen Ausreißer.
- **Meldungen lassen sich schließen** und pausieren beim Zeigen; Fehler melden
  sich assertiv statt höflich (sie standen bisher hinter laufenden Ausgaben an
  und verschwanden nach Sekunden unwiderruflich).
- **Dialoge machen den Rest der Seite inert** und sperren das
  Hintergrund-Scrollen. Der Fokus-Trap fing nur die Tabulatortaste — der
  virtuelle Cursor eines Screenreaders wanderte weiterhin frei dahinter.
- **Die Ablesungstabelle folgt der Jahresauswahl.** Sie zeigte alle Ablesungen
  aller Jahre; mit dem Home-Assistant-Ingest entstehen tägliche Werte, was die
  Ansicht nach kurzer Zeit unbrauchbar machte.
- **Prognose und Analyse zeigen nur aktive Verbrauchsarten**, wie Dashboard und
  Seitenleiste.
- **Ungespeicherte Einstellungen** werden am Speichern-Knopf markiert, und das
  Schließen des Tabs fragt nach.
- Die Seitenleiste wartet nicht mehr auf ihre Zähler-Badges: Der erste
  Bildschirminhalt brauchte vier serielle Roundtrips, zwei davon nur für zwei
  Zahlen.

### Changed — Auslieferung

- **Schriften und Chart.js liegen im Repository** (`public/vendor/`, 260 KB).
  Bisher kamen sie von `fonts.googleapis.com` und `cdn.jsdelivr.net` — bei einer
  selbst gehosteten Anwendung wanderte damit die IP jedes Aufrufs zu Dritten,
  und der erste Start ohne Internet hatte weder Schrift noch Diagramme.
  Lizenzen liegen bei (SIL OFL 1.1 bzw. MIT).
- **Der Service Worker legt die App-Shell vollständig ab** (Stile, Schriften,
  Chart.js, Einstiegsmodul) statt nur der SPA-Wurzel.
- **Der Cache-Buster hängt an der VERSION** statt an der Änderungszeit von
  `app.js`. Die änderte sich nicht, wenn ein Release nur Views oder CSS anfasste
  — Browser behielten dann die alten Dateien.

### Added

- **i18n der nutzersichtbaren Backend-Texte**: Effizienz-Hinweise, Abbruchgrund
  der Prognose, automatisch erzeugte Termintitel, Notizen der v0.9.0-Migration
  sowie die Default-Zählernamen. Eine englische Frischinstallation begrüßte
  bisher mit „Hauptzähler". Verbrauchsart-Namen und Zählernamen lösen jetzt
  zentral über `I18nService::utilityLabel()` / `defaultMeterName()` auf —
  vorher trug jeder Konsument seine eigene Kopie, und zwei hatten gar keine.
- `ReleaseConsistencyTest`: prüft, dass Service-Worker-Cache,
  `docker-compose.yml`, CHANGELOG sowie README und INSTALL zur VERSION passen —
  und dass im Frontend kein Verweis auf einen fremden Server steht.

### Tests

- `TariffComparisonServiceTest` (8 Fälle) für das neu aufgesetzte Modul,
  darunter der Halbjahres-Fall, der die alte Rechnung entlarvt.
- `I18nServiceTest` prüft für **jede** Verbrauchsart in **jeder** Sprache, dass
  Name und Default-Zählername vorhanden sind.
- PHPUnit 105 → 130 Tests.

### Fixed — stille Rechenfehler

- **Prognose rechnete mit Schattenverträgen.** `ForecastService` holte die
  Verträge ohne `is_shadow`-Filter — anders als `ConsumptionService`, das ihn an
  beiden Stellen setzt. Sobald der letzte echte Vertrag vor dem Prognosehorizont
  endete, übernahm ein Schattenvertrag Arbeitspreis, Grundpreis und
  Abschlagsplan; die Prognose zeigte dann Kosten für einen Tarif, den es nie gab.
  Genau der Normalfall — man legt Schattenverträge an, *weil* der laufende
  Vertrag ausläuft. Im Reproduktionsfall beruhten 7 von 12 Prognosemonaten auf
  der Hypothese (797 € statt ~85 € pro Monat).
- **Wasser-Monatschart war eine Nullreihe.** `drawMonthChart()` las hart
  `m.kwh`, während `applyUtilityFields()` bei m³-nativen Verbrauchsarten den
  Verbrauch nach `m3` schiebt und `kwh` auf 0 setzt. Der Chart nutzt jetzt
  denselben `consKey` wie KPI und Monatstabelle. Letzter Rest von Fix #14
  (v1.6.1), der Tabelle und Kennzahl repariert hatte.
- **Wasser-Vertragsformular speicherte stillschweigend 0.** `collectWaterForm()`
  wandelte leere Zahlenfelder mit `parseFloat(x || 0)` in eine echte 0 um: Wer
  ein Stichtagsdatum eintrug und den Preis vergaß, legte damit einen Tarif von
  0 ct/m³ an — die Wasserkosten fielen ab diesem Datum auf den Grundpreis, ohne
  Fehlermeldung. Leere Felder erreichen jetzt den Backend-Guard, der die halb
  gefüllte Zeile ablehnt. Zusätzlich markiert das Formular solche Zeilen schon
  vor dem Absenden (wie das Standard-Vertragsformular).
- **Zahlen, Datumsangaben und Monatsnamen in fünf Sprachen deutsch.**
  `lib/format.js` bildete nur `de` und `en` ab; die 2026 ergänzten Sprachen
  (es/fr/it/nl/pt) fielen still auf `de-DE` zurück und zeigten deutsche
  Tausendertrennung, deutsche Datumstrennung und deutsche Monatskürzel („Mär",
  „Mai", „Dez") in einer ansonsten übersetzten Oberfläche. Formatierung kommt
  jetzt aus `Intl`; die handgepflegten Monatstabellen in `analysis.js` und der
  zweite Formatierer in `readings-entry.js` entfallen.

### Changed — Nebenbefunde

- **Schattenverträge sind in der Vertragsliste als solche erkennbar.** Sie
  erschienen dort mit dem Status „AKTIV", obwohl Saldo, Vertragsstatus und
  Prognose sie herausfiltern. Jetzt tragen sie ein eigenes Kennzeichen und einen
  Hinweis, dass sie in keiner Kostenrechnung mitzählen.
- **`docker-compose.yml` pinnte noch `1.9.3`** — sieben Releases alt. Wer aus dem
  Repository heraus startete, bekam eine Version vor dem gesamten v2.x-Bündel
  (Mehrsprachigkeit, PWA, Barrierefreiheit, Subzähler, Home-Assistant-Ingest).

### Tests — Regressionen

- Neuer `ForecastShadowContractTest`: Prognose darf nie auf einem
  Schattenvertrag beruhen, und das Ergebnis muss mit und ohne Schattenvertrag
  identisch sein. Gegen den v2.1.5-Stand schlagen beide Fälle fehl.
- `WaterContractEdgeCasesTest` um drei Fälle erweitert: halb gefüllte Preiszeile
  wird abgelehnt, komplett leere Vorlagezeile weiterhin still verworfen, und die
  Invariante `kwh = 0 / m3 > 0`, auf der der Chart-Fix beruht.
- PHPUnit 100 → 105 Tests (347 Assertions), Frontend-Render 37/37.

---

## [2.1.5] — 2026-06-28 — Polish: Chart-Farben, Prognose-Linie, Reminder-Datumslogik

PATCH-Release. Vier risikoarme Politur-/Robustheits-Korrekturen; keine Schema-
oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Monatschart-Farben (A).** `drawMonthChart` nutzte eine hartkodierte
  2-Farben-Palette (nur Gas/Strom, Rest Blau) statt der Utility-Farbe aus der
  SSOT → Wasser/Fernwärme/Heizöl/Pellets/PV erschienen blau. Jetzt zieht der Chart
  `u.color` (wie der Rest der UI und die Prognose-View).
- **Reminder-Datumsfortschreibung (C).** `markDone`/`suggestNextDelivery` nutzten
  `modify('+N months')`, das am Monatsende überläuft (31.08. + 6 Mon. → 03.03.
  statt 28.02.). Der Tag wird jetzt auf die Ziel-Monatslänge geclamped.
- **Reminder-Robustheit (D).** Ein kaputtes `next_due` (Import/Legacy) sprengte
  `listWithStatus` mit einer DateTime-Exception (500). Jetzt defensiv abgefangen.

### Changed (UX)

- **Prognose-Chart (B).** Historie und Prognose sind jetzt durchgehend verbunden
  (die gestrichelte Prognoselinie dockt am letzten Historie-Punkt an) statt mit
  einer Lücke an der Grenze.

### Docs

- Lessons in DE **und** EN (`docs/technical/06-release-process.md` + `docs/en/…`)
  synchron ergänzt.

### Migration

Keine. Schema bleibt 1.3.0.

### Tests

- Neuer `ReminderServiceTest` (Monatsende-Clamp 31.08.+6 → 28.02.; jährlich behält
  den Tag; kaputtes `next_due` → kein Crash). PHPUnit 97 → **100**. Chart-Farben
  gegen die API-SSOT verifiziert; Browser-Render grün.

### Lessons Learned

- Keine zweite Farb-/Wert-Quelle neben der SSOT (Utilities) — Anzeige-
  Eigenschaften von dort ziehen; Datums-Arithmetik gegen Monatsende-Überlauf
  absichern.

---

## [2.1.4] — 2026-06-28 — Wasser-Schmutzwasser-Fix + UX-Politur (Lösch-Dialog, Liefer-Modal, Tank-Validierung)

PATCH-Release. Vier risikoarme Korrekturen aus einem Code-Sweep; keine Schema-
oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Wasser: Schmutzwasser ohne Zähler-Referenz (A).** `applyWaterContracts`
  rechnete bei `schmutzwasser.basis = 'separater_zaehler'` **ohne** hinterlegten
  Zähler still auf dem **Trinkwasser-Volumen** ab statt auf 0 — entgegen dem
  eigenen Code-Kommentar. Der Speicherpfad (`ContractService`) erzwingt zwar eine
  Referenz; un­validierte Daten (Backup-Import/Legacy) können sie aber missen.
  Jetzt: ohne Referenz `swM3 = 0` (keine still falschen Kosten).

### Changed (UX)

- **Lösch-Dialoge vereinheitlicht (B).** Das Löschen von Ablesungen/Lieferungen
  in der Verbrauchsansicht nutzt jetzt das gestylte, lokalisierte `confirmModal`
  (wie die Zählerverwaltung) statt des nativen `confirm()`.
- **Liefer-Modal rechnet mit (C).** Gesamtbetrag und Menge × Stückpreis sind live
  verknüpft: zwei Felder gesetzt → das dritte füllt sich automatisch (beidseitig).
- **Tank-Kapazität: Sofort-Validierung (D).** Beim Anlegen/Bearbeiten eines
  Heizöl-/Pellets-Tanks meldet die UI eine fehlende Kapazität sofort, statt erst
  den Backend-Fehler abzuwarten.

### Docs

- **EN-Kompendium nachgezogen.** `docs/en/technical/06-release-process.md`
  (Lessons v2.1.1–v2.1.4) und `docs/en/ui/01-views.md` (Tank-Felder) hingen hinter
  dem DE-Stand — jetzt synchron. DE bleibt kanonisch.

### Migration

Keine. Schema bleibt 1.3.0.

### Tests

- Regression `WaterContractEdgeCasesTest::testSeparaterZaehlerWithoutMeterReferenceBillsZeroNotTrinkwasserVolume`;
  PHPUnit 96 → **97**. B/C/D zusätzlich im echten Browser verifiziert (Liefer-
  Auto-Rechnung bidirektional, gestyltes Lösch-Modal, Kapazitäts-Block), 0
  Konsolenfehler.

### Lessons Learned

- Save-Pfad-Validierung schützt den Berechnungs-Pfad nicht: unvalidierte Daten
  (Restore/Legacy) erreichen die Compute-Schicht, die deshalb ihrem dokumentierten
  Verhalten folgen muss — nicht der Validierung des Schreibpfads vertrauen.

---

## [2.1.3] — 2026-06-28 — Bugfix: Subzähler-Doppelzählung in PDF-Bericht & Effizienzklasse

PATCH-Release. Behebt eine **F1006-Subzähler-Doppelzählung** in mehreren
Aggregationen. Reine Anzeige-/Report-Korrektheit, kein Datenverlust, keine
Schema- oder API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Subzähler wurden doppelt gezählt** in drei Aggregationen, die selbst über
  Zähler summieren und die F1006-Ausschlussregel aus
  `ConsumptionService::forUtility` nicht spiegelten (ein Subzähler steckt
  bereits im Brutto-Verbrauch seines Elternzählers):
  - **`PdfReportService::yearAggregate`** — Zusammenfassungstabelle im
    Jahresbericht-PDF (Verbrauch/Kosten/CO₂ je Art); betraf **alle**
    Verbrauchsarten.
  - **`BenchmarkService::yearKwhForUtility`** — Effizienzklasse (kWh/m²·a) der
    Heizquellen.
  - **`groupBreakdown` (Dashboard)** — Gruppen-Summe, falls eine Gruppe einen
    Eltern- *und* seinen Subzähler enthält.
  Alle drei schließen Subzähler jetzt analog zur Utility-Gesamtsumme aus.

### Migration

Keine. Schema bleibt 1.3.0; nur Anzeige-/Berechnungslogik betroffen, keine
gespeicherten Daten geändert.

### Tests

- **Regression** (`MeterTopologyTest::testSubmeterDoesNotInflateEfficiency`):
  Gas-Heizquelle mit Eltern + Subzähler — `BenchmarkService::efficiency()`
  zählt nur den Elternzähler. Wäre vor dem Fix rot. PHPUnit 95 → **96**.
- Vollständige Konsumenten-Prüfung: `CsvExportService`, `PvSummaryService`,
  `StromSaldoService` (nutzen `forUtility`) sowie die pro-Zähler-Pfade
  (`Forecast`/`Recommendation`/`TariffComparison`/PDF-Detailseiten) sind
  korrekt — nur die drei obigen rollten eigene Summen.

### Lessons Learned

- Eine bereichs-spezifische Ausschlussregel (F1006: Subzähler nicht in
  Utility-Summen) muss in **jeden** Aggregator propagiert werden, der selbst
  über Zähler summiert — nicht nur in `forUtility`. Drei Stellen (PDF,
  Benchmark, Dashboard-Gruppen) hatten sie seit v1.8.0 nie bekommen. Eine
  gemeinsame „Root-Meter"-Quelle wäre robuster (als Folgeoption notiert).

---

## [2.1.2] — 2026-06-28 — Bugfix: Backup/Restore verlor Lieferungen, Gruppen & Reminders

PATCH-Release. Behebt einen **stillen Datenverlust** im Backup/Restore und
vervollständigt die mitgelieferten Demo-Daten. Keine Schema- oder
API-Änderung (Schema bleibt 1.3.0).

### Fixed

- **Backup/Restore sicherte nicht alle Daten.** `BackupService::export()`
  und `import()` verarbeiteten pro Verbrauchsart nur `meters`/`readings`/
  `contracts`. Damit fielen bei **jedem** Backup lautlos unter den Tisch:
  **`deliveries`** (Heizöl-/Pellets-Lieferungen, seit v1.3.0),
  **`meter_groups`** (F1006-Zählergruppen, seit v1.8.0) und die top-level
  **`reminders`**. Ein Export/Restore verlor damit die komplette Liefer- und
  Verbrauchshistorie der Liefer-Utilities sowie Gruppen und Wartungstermine.
  Export und Import sichern/restaurieren diese Töpfe jetzt; `import()` bleibt
  über `isset`-Guards **abwärtskompatibel** zu älteren Backups ohne die
  Schlüssel (fehlende Töpfe werden übersprungen, nichts gelöscht).

### Changed

- **Demo-Daten vervollständigt.** Das mitgelieferte Demo-Backup
  (`demo-data/energietracker-demo-backup.json`, auch im Docker-Image) enthielt
  für den Heizöltank und das Pelletlager keine Lieferungen — der „Demo-Daten
  laden"-Button zeigte leere Tanks. Es trägt jetzt je drei realistische
  Jahres-Lieferungen (2023–2025), passend zum Datei-Baum unter `demo-data/`.

### Migration

Keine. Schema bleibt 1.3.0; bestehende Backups ohne die neuen Schlüssel
importieren unverändert weiter.

### Tests

- **Roundtrip-Regression** (`BackupServiceRestoreGuardTest`): export → leeren
  → import bewahrt `deliveries`, `meter_groups` und `reminders`.
- **Demo-Restore** (`DemoServiceTest`): Import des echten Demo-Backups stellt
  je drei Heizöl-/Pellets-Lieferungen wieder her. Beide Tests wären vor dem
  Fix rot.
- PHPUnit 93 → **95 Tests / 299 Assertions**. Zusätzlich HTTP-End-to-End
  verifiziert (`POST /api/backup/import` → `GET …/deliveries` = 3 + 3).

### Lessons Learned

- Ein Serializer mit **hartkodierter Feldliste** wird bei jedem neuen
  Datentopf still unvollständig: `deliveries` (v1.3.0) und `meter_groups`
  (v1.8.0) kamen dazu, die Backup-Liste nie. Ungeprüfte Vollständigkeits-
  Annahmen (die Roadmap nahm „BackupService zieht ohnehin alle JSON-Dateien"
  an) + fehlender Roundtrip-Test verzögerten den Fund. Neue Datentöpfe
  gehören in `export()` **und** einen export→import-Roundtrip-Test.

---

## [2.1.1] — 2026-06-28 — Bugfix: Tank für Heizöl/Pellets im UI anlegbar

PATCH-Release. Reiner Frontend-Fix, keine Schema- oder API-Änderung
(Schema bleibt 1.3.0). Behebt, dass sich für lieferbasierte Verbrauchsarten
(Heizöl, Pellets) über die Oberfläche kein Tank anlegen ließ.

### Added

- Zwei neue Oberflächen-Schlüssel `meters.modal.capacity` und
  `meters.modal.initialStock` in allen sieben Katalogen
  (`de/en/fr/it/es/pt/nl`).

### Changed

- **Versionsstempel synchronisiert.** `INSTALL.md` und `INSTALL.de.md`
  (Docker-Pull-Beispiel + VERSION-Verweis) standen noch auf 2.0.1 und
  ziehen jetzt auf 2.1.1 mit.

### Fixed

- **Tank für Heizöl/Pellets ließ sich nicht anlegen ([#18]).** Das „Neuer
  Zähler"-Formular (`public/js/views/meters.js`) rendert für lieferbasierte
  Verbrauchsarten jetzt die Pflichtfelder **Tank-Kapazität** und
  **Anfangsbestand** und sendet sie an `POST /api/utility/<key>/meters`.
  Bisher fehlten beide Felder, sodass `MeterService::create()` mit
  „Tank-Kapazität (capacity) > 0 ist Pflicht" abbrach — eine Fehlermeldung
  ohne zugehöriges Eingabefeld. Die Felder erscheinen auch im
  Bearbeiten-Dialog (vorbefüllt); der kumulative „Anfangsstand" entfällt
  für Tanks.

### Migration

Keine. Schema bleibt 1.3.0; Bestandsdaten und bereits angelegte Tanks
(auch die per `ensureDefault` erzeugten) laufen unverändert weiter.

### Tests

- Browser-verifiziert (Playwright gegen Demo-Daten): Tank anlegen — Felder
  sichtbar, Speichern ohne Fehler, `capacity`/`initial_stock` korrekt
  persistiert — und bearbeiten (Werte vorbefüllt), 0 Konsolenfehler. Die
  PHPUnit-Suite bleibt unverändert grün; `MeterService` war bereits korrekt.

### Lessons Learned

- Ein Backend-Pflichtfeld ohne passendes Eingabefeld im Frontend ist per
  Konstruktion ein Fehlerpfad. Bei `reading_kind`-abhängigen Pflichtfeldern
  Formular und `MeterService::create()` zusammen denken.

[#18]: https://github.com/Bingerminger/energietracker/issues/18

---

## [2.1.0] — 2026-06-10 — Weitere UI-Sprachen + vollständige englische Doku

MINOR-Release. Erste Lokalisierungs-Welle nach dem v2.0.0-i18n-Fundament:
fünf zusätzliche Oberflächen-Sprachen, eine datengetriebene Sprach-Registry
sowie die **vollständige englische Spiegelung des Dokumentations-Kompendiums**.
Dazu ein überarbeiteter App-Logo-/Icon-Satz und ein Datenpfad-Fix in `index.php`.
**Keine Schema-Änderung** (bleibt 1.3.0); alle Neuerungen sind additiv.

### Added

- **Fünf neue Oberflächen-Sprachen.** Vollständige Kataloge unter
  `public/locales/{fr,it,es,pt,nl}.json` — **Französisch, Italienisch, Spanisch,
  Portugiesisch und Niederländisch** — jeweils mit demselben Schlüsselsatz wie
  `de.json`/`en.json` (1073 Schlüssel, geprüft auf vollständige Schlüssel- und
  Platzhalter-Deckung). Umschaltbar unter *Einstellungen → Sprache*; die
  HTML-`lang`- und `dir`-Attribute folgen der Auswahl.
- **Datengetriebene Sprach-Registry.** Neue Datei `public/locales/languages.json`
  (Code → Eigenbezeichnung) ist jetzt die einzige Wahrheitsquelle der
  verfügbaren Sprachen. Frontend (`i18n.js` lädt sie via `loadLanguages()`) und
  Backend (`I18nService::supported()` liest die Schlüssel, mit
  `FALLBACK_SUPPORTED=['de','en']`) leiten ihre Sprachliste daraus ab — eine
  weitere Sprache erfordert nur noch Katalog + Registry-Eintrag, keinen
  Code-Eingriff mehr. Der Sprach-Dropdown zeigt die Sprachen in ihrer jeweiligen
  Eigenbezeichnung (Endonym).
- **Vollständige englische Dokumentation.** Das gesamte Kompendium ist nun
  zweisprachig: unter `docs/en/` liegt die englische Spiegelung aller Kapitel
  (functional 00–13, technical 01–07, UI-Referenz, `API.md`, `ARCHITECTURE.md`,
  Erste Schritte, Home Assistant, Use-Cases, Migration). Jede deutsche und
  englische Seite trägt eine Sprachleiste zum jeweiligen Gegenstück. **Deutsch
  bleibt kanonisch** und wird bei jedem Release synchron gehalten; beide Sprachen
  werden ab sofort weitergepflegt.

### Changed

- **Überarbeiteter App-Logo-/Icon-Satz.** Neue, verbesserte Logos in den
  kompletten Icon-Satz übernommen (`public/img/icon-{light,dark}-*.png`,
  `icon-{light,dark}.png`, `public/favicon.ico`), inkl. PWA-Icons in allen
  Größen; verlustarm komprimiert.
- **Dropdown-Sprachliste statt Hardcodierung.** `settings.js` baut die Auswahl
  aus `getLanguages()` (Endonyme) statt aus festen `settings.lang.*`-Schlüsseln.

### Fixed

- **Sprach-Einstellung bei eigenem Datenverzeichnis (`ET_DATA_DIR`).** `index.php`
  liest die `language`-Einstellung jetzt aus dem konfigurierten Datenpfad
  (`ET_DATA_DIR`) statt fest aus `./data`, sodass der initiale Server-Render bei
  ausgelagertem Datenverzeichnis die korrekte Sprache liefert.

### Notes

- Die weiteren geplanten Sprach-Wellen (u. a. cs, uk, pl, el, tr, hr, sr, sl, fi,
  no, da, lv, et, hu, bg, ro) sind in der [Roadmap](roadmap.md) vermerkt und
  werden bedarfsgetrieben nachgezogen.
- F1008 (NKA/Mieter-Datenmodell) bleibt für ein späteres Release vorgemerkt.

---

## [2.0.1] — 2026-06-10 — Bugfix: Zählergruppen im Dashboard

PATCH-Release. Behebt einen seit F1006 (v1.8.0) bestehenden Fehler.

### Fixed

- **Zählergruppen wurden nicht im Dashboard angezeigt ([#16](https://github.com/Bingerminger/energietracker/issues/16)).**
  Mit der Meter-Topologie (F1006) angelegte Zähler-Gruppen tauchten in der
  Übersicht nicht auf, obwohl die Roadmap „Gruppen (Dashboard-Summe)" zusagte.
  Die Backend-Daten (`meter_groups` samt `meter_group_id` je Zähler) wurden
  zwar geliefert, im Dashboard aber nie gerendert. Jede Verbrauchsart-Karte mit
  gruppierten Zählern zeigt nun eine **aufklappbare Gruppen-Übersicht** mit dem
  12-Monats-Verbrauch (und, falls vorhanden, den Kosten) je Gruppe. Karten ohne
  Gruppen bleiben unverändert.

*(Der ebenfalls in #16 geäußerte Wunsch nach Verträgen pro Gruppe ist ein
Feature und für ein späteres Release vorgemerkt.)*

---

## [2.0.0] — 2026-06-10 — Internationalisierung, Englisch, Barrierefreiheit, PWA

MAJOR-Release. Großes Bündel: Full-Stack-Internationalisierung, englische
Lokalisierung, durchgängige Barrierefreiheit, UX-Politur und PWA-/Offline-
Fähigkeit. **Keine Schema-Änderung** (bleibt 1.3.0) — die Sprache ist additiv
als `language`-Setting hinterlegt.

### Added

- **Full-Stack-Internationalisierung (N1007).** Gemeinsame JSON-Sprachkataloge
  unter `public/locales/{de,en}.json`, im Frontend über `t()`
  (`public/js/lib/i18n.js`) und im Backend über `I18nService` genutzt. Die
  Sprache ist ein additives `language`-Setting (de|en), umschaltbar in den
  Einstellungen.
- **Englische Lokalisierung (EN-L10n).** Vollständige englische Übersetzung
  aller Ansichten, Komponenten, Fehlermeldungen und des PDF-Jahresberichts
  (DE/EN paritätsgleich).
- **Progressive Web App (N1008).** `manifest.webmanifest` + Service-Worker
  (`sw.js`, Root-Scope) + 192/512-Icons. Installierbar (standalone) und
  offline-fähig: App-Shell, alle View-Module, Schriften/CDN sowie zuletzt
  geladene API-Daten werden gecacht; die App startet und rendert ohne Netz.
- **Barrierefreiheit (N1009).** Skip-Link, sichtbarer Fokus-Ring
  (`:focus-visible`), `prefers-reduced-motion`, dynamisches `<html lang>`,
  ARIA-Landmarks, Fokus-Management bei SPA-Navigation, Modal-Focus-Trap mit
  Fokus-Rückgabe, Label↔Feld-Verknüpfung in allen Formularen, `scope="col"`
  in allen Tabellen, zugängliche Namen für Icon-Buttons, Text-Alternativen
  für alle Diagramme, tastaturbedienbare CSV-Drop-Zonen und Status-/Hinweis-
  Ansagen über Live-Regions.

### Changed

- **UX-Politur.** Dashboard-Trend-Indikatoren als dezent getönte Chips,
  gleichmäßigere Karten-Abstände im Raster, Null-Kosten als gedämpftes „—"
  statt „0 €". Einheitlicher Zeilen-Hover für alle Tabellen.
- **Sprach-Priorität.** Das `language`-Setting ist nun die maßgebliche Quelle
  für ALLE serverseitigen Texte (auch PDF-Report und Fehlermeldungen); der
  Browser-`Accept-Language`-Header dient nur noch als Fallback ohne gesetzte
  Sprache.

### Fixed

- **PDF-Report in falscher Sprache.** Der PDF-Jahresbericht (und die Backend-
  Fehlermeldungen) folgten dem Browser-`Accept-Language` statt der App-Sprache
  — bei englischer App auf deutschem Browser kam ein deutsches PDF. Jetzt
  maßgeblich: das `language`-Setting.
- **Unsichtbarer Text im Hellmodus.** In der Zählerstands-Erfassung nutzten
  Feld-Labels, die „Letzter Stand"-Zeile und Karten-Untertitel ein nicht
  definiertes CSS-Token (`--fg-muted`) mit weißem Fallback — im Light-Theme
  praktisch unsichtbar. Auf ein theme-bewusstes Token umgestellt.

---

## [1.9.3] — 2026-06-02 — Echte UI-Screenshots statt SVG-Mockups

PATCH-Release. **Nur Dokumentation** — kein Anwendungscode.

### Changed

- **UI-Referenz mit echten Screenshots.** Die handgezeichneten SVG-Mockups
  unter `docs/ui/mockups/` wurden durch **echte Bildschirmaufnahmen** der
  laufenden App (mit Demo-Datensatz, Light-Theme) ersetzt — eine PNG je View
  unter `docs/ui/screenshots/`. `docs/ui/01-views.md` umfassend überarbeitet:
  jetzt **alle 12 Ansichten** inkl. der neuen **PV-View** (F1005) und der
  **Topologie-Hinweise** in der Zähler-View (F1006).
- Disclaimer „schematische SVG-Mockups" in README und Kompendium-Index durch
  „echte Screenshots" ersetzt; `docs/screenshots/README.md` zu einer schlanken
  Anleitung zum Neu-Erzeugen der Screenshots umgeschrieben.

### Removed

- Die 11 SVG-Mockup-Dateien unter `docs/ui/mockups/` (durch echte PNGs ersetzt).

---

## [1.9.2] — 2026-06-02 — Dokumentations-Review + Roadmap-Neusortierung

PATCH-Release. **Nur Dokumentation und Roadmap** — kein Anwendungscode, keine
Schema- oder Verhaltensänderung.

### Roadmap

- **Smart-Meter-Major (v3.0.0) gestrichen.** Echtes Metering (Smart-Meter-
  Auslesung) wird bewusst an Home Assistant delegiert (F1009-Ingest); der
  Energietracker bleibt die schlanke Vertrags-/Kosten-/Prognose-Oberfläche.
- **N1011** (API-Versionierung) aus der festen Reihenfolge in den
  „Bedarfsgetrieben"-Block verschoben (war nur Smart-Meter-Vorbereitung).
- **Neue strategische Leitlinie:** Ausbau der Home-Assistant-Integration
  (als bedarfsgetriebenes F1010+ skizziert). Geplante Reihenfolge endet bei
  EN-Lokalisierung (v2.0.0); nächster Slot unverändert F1008.

### Dokumentation

- **Vollständiger Faktenabgleich** aller Docs auf den Code-Stand: Schema 1.3.0,
  68 API-Routen, 24 Services / 20 Controller, 12 Views, 40 Settings-Schlüssel,
  Testzahlen (86/274 PHPUnit, 20/20 + 36/36 Frontend). Veraltete Smart-Meter-
  Verweise bereinigt; Schema-Historie ergänzt.
- **Neue Features dokumentiert:** Meter-Topologie (F1006) und
  Home-Assistant-Anbindung (F1009) durchgängig in Datenmodell, API-Referenz,
  Architektur, UI-Referenz und README.
- **Neue, nutzerorientierte Dokumente:**
  - `docs/ERSTE-SCHRITTE.md` — geführtes Beispiel von der Installation bis zur
    ersten Prognose.
  - `docs/USE-CASES.md` — vier durchgerechnete Praxisfälle: WG mit geteilten
    Zählern, Smart-Home-Vollausbau, PV-Haushalt mit Wärmepumpe, Vermieter mit
    mehreren Einheiten.
  - `docs/functional/13-meter-topologie.md` — Subzähler & Zählergruppen
    fachlich erklärt.
- **Verbesserte Struktur & Verlinkung:** Kompendium-Index um Einstiegs- und
  Praxis-Sektion erweitert, Rollen-Wegweiser ausgebaut, Szenario-Docs mit
  „Weiterführend"-Verweisen. Alle internen Doku-Links geprüft.

---

## [1.9.1] — 2026-06-01 — Frisch-Install-Fix + CI-Wartung

PATCH-Release. Keine Schema-Änderung, keine neuen Features.

### Fixed

- **Frischer Docker-Container startet jetzt mit Standard-Zählern.** Bei einem
  komplett leeren Datenverzeichnis (echter Erststart) lief bisher `migrate()`
  statt `initFresh()` — dadurch hatte ein frischer Container 0 Zähler für
  Gas/Strom/Wasser. Neu erkennt `Migrator::isPristine()` das leere Verzeichnis,
  sodass beim Erststart `initFresh()` läuft und die Standard-Zähler anlegt.
  Bestehende Installationen und Migrationspfade (v0.9.0-Altdaten, Demo-Daten
  mit vorhandener `meta.json`) sind nicht betroffen.

### Changed

- **CI: Node-24-Opt-in.** `.github/workflows/ci.yml` setzt nun
  `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` (analog `docker-publish.yml`, N1012),
  damit `actions/cache@v4` & Co. auf Node 24 laufen — die Node-20-Deprecations-
  Annotation entfällt.

### Tests

- 5 neue PHPUnit-Tests (`PristineInitTest`) für die Frisch-Install-Erkennung.
  Gesamt: 86 Tests / 274 Assertions.

---

## [1.9.0] — 2026-06-01 — F1009 Home-Assistant-Anbindung

MINOR-Release. Offizielle Integration für **Home Assistant**: HA liest Smart
Meter aus und pusht Zählerstände an den Energietracker, der weiterhin Verträge,
Kosten und Prognosen übernimmt. Ersetzt eine kursierende, technisch falsche
Forenanleitung durch eine saubere, abwärtskompatible Lösung. Schema-Migration
**1.2.0 → 1.3.0** (rein additiv).

### Added

- **F1009 — Push-Ingest-Endpoint `POST /api/ingest`.** Nimmt
  `{ utility, meter, value, date? }` und macht ein **Upsert pro
  (Zähler, Datum)**: ein erneuter Push am selben Tag aktualisiert den Wert,
  statt Duplikate anzulegen (idempotent — robust gegen mehrfaches Senden).
  `date` ist optional (Default heute) und akzeptiert auch volle ISO-Zeitstempel
  (wird auf das Datum gekürzt).
- **Opt-in Token-Authentifizierung.** Neue Endpoints
  `GET/POST/DELETE /api/auth/token`. Solange **kein** Token gesetzt ist, bleibt
  die API unverändert offen (keine Breaking Change für bestehende
  LAN-Installationen). Sobald ein Token existiert, verlangt `/api/ingest` einen
  `Authorization: Bearer <token>`-Header. Der Token wird **einmalig** im
  Klartext angezeigt und nur als SHA-256-**Hash** in einer separaten
  `data/auth.json` gespeichert (nicht in `settings.json`; vom Backup
  ausgenommen). Verifikation per `hash_equals` (konstante Zeit).
- **Zähler-Alias `external_id`.** Jeder Zähler kann optional eine pro
  Verbrauchsart eindeutige, frei vergebbare ID erhalten (z. B.
  `stromzaehler_haus`), die in HA statt der internen ID verwendet wird. Der
  Ingest akzeptiert Alias **oder** interne ID.
- **Einstellungs-Sektion „🏠 Home-Assistant-Anbindung".** Token
  erzeugen/anzeigen (einmalig)/widerrufen, Zähler-Aliase pflegen und ein
  fertiges, kopierbares HA-`rest_command`-YAML-Snippet.
- **Doku:** neue [`docs/HOME-ASSISTANT.md`](docs/HOME-ASSISTANT.md) mit
  Schritt-für-Schritt-Anleitung, Fehlersuche und zwei Use-Cases (Eigenheim mit
  PV/Fernwärme, Mietwohnung Strom/Gas/Wasser); `docs/API.md` um Auth- und
  Ingest-Endpoints erweitert.

### Changed

- **Schema 1.2.0 → 1.3.0.** Jeder Zähler trägt nun `external_id` (Default
  `null`). Migration additiv und idempotent; Auto-Migration beim ersten Start.

### Validation

- `external_id`: 1–64 Zeichen aus `[A-Za-z0-9_.-]`, eindeutig je Utility.
- `/api/ingest` lehnt Delivery-Utilities (Heizöl/Pellets — nutzen Lieferungen
  statt Ablesungen), unbekannte Zähler und nicht-numerische Werte mit `400` ab;
  fehlender/falscher Token bei aktiver Auth → `401`.

### Security-Hinweis

- Die kursierende Forenanleitung (`POST /api.php` mit
  `action`/`value`/`timestamp` und Token aus `settings.json`) ist **falsch** —
  keiner dieser Bestandteile existierte je. Die offizielle Schnittstelle ist
  oben beschrieben und in der Doku klar als korrekt gekennzeichnet.

### Tests

- 15 neue PHPUnit-Tests (`HomeAssistantIngestTest`): Token-Hash/`hash_equals`,
  Auth-401/200, Ingest-Upsert-by-date (idempotent), Alias-Auflösung +
  Eindeutigkeit, Migration `external_id`. Gesamt: 81 Tests / 261 Assertions.
- Frontend-API-Shape um Auth-Status, `external_id` und einen Ingest-Roundtrip
  erweitert; CI-Migrations-Smoke prüft jetzt 1.3.0 inkl. `external_id`.

---

## [1.8.0] — 2026-06-01 — F1006 Meter-Topologie (Subzähler + Gruppen)

MINOR-Release. Zähler können jetzt in Beziehung zueinander stehen:
**Subzähler** (Reihenschaltung) werden vom Elternzähler abgezogen,
**Gruppen** fassen mehrere Zähler im Dashboard zusammen. Schema-Migration
**1.1.0 → 1.2.0** (rein additiv, verlustfrei).

### Added

- **F1006 — Meter-Topologie.** Zwei neue, optionale Zähler-Beziehungen:
  - **Subzähler / Reihenschaltung** (`parent_meter_id`): z. B. eine
    Wärmepumpe hinter dem Haushaltsstrom. Der Elternzähler misst brutto
    inklusive seiner Subzähler; in der Verbrauchsart-Gesamtsumme zählt daher
    nur der Elternzähler, der Subzähler wird **nicht doppelt** addiert,
    sondern als Aufschlüsselung eingerückt dargestellt.
  - **Gruppen** (`meter_group_id`): mehrere Zähler (z. B. NT + HT Strom oder
    mehrere Wallboxen) werden im Dashboard zu einem Eintrag zusammengefasst.
    Neue Gruppen-Stammdaten je Verbrauchsart in `meter_groups.json`.
- **Merge-Wizard.** Geführter Dialog „Zähler zusammenführen" im Zähler-View:
  mehrere bestehende Zähler per Mehrfachauswahl zu einer (neuen oder
  bestehenden) Gruppe zusammenfassen.
- **Neue API-Endpoints** (Zählergruppen):
  `GET/POST /api/utility/{utility}/meter-groups`,
  `PATCH/DELETE /api/utility/{utility}/meter-groups/{groupId}`,
  `POST /api/utility/{utility}/meter-groups/merge`. Der Consumption-Endpoint
  liefert zusätzlich `meter_groups[]` für die Dashboard-Aufschlüsselung.

### Changed

- **Schema 1.1.0 → 1.2.0.** Jeder Zähler trägt nun `parent_meter_id` und
  `meter_group_id` (Default `null`). Die Migration ist additiv und idempotent
  (folgt dem bestehenden Migrator-Muster); bestehende Daten bleiben
  unangetastet. Auto-Migration beim ersten Start hebt vorhandene
  Installationen automatisch an.
- Verträge bleiben in dieser Version **unverändert pro Zähler** — Gruppen
  fassen ausschließlich den Verbrauch fürs Dashboard zusammen. Ein
  Gruppen-Vertrags-Saldo (Vertrag gegen Gruppensumme) ist bewusst auf ein
  späteres Release vertagt.

### Validation

- **Keine mehrstufigen Subzähler-Ketten** (max. 1 Ebene): der gewählte
  Elternzähler darf selbst kein Subzähler sein und umgekehrt.
- **Keine Selbstreferenz / unbekannte Referenzen:** `parent_meter_id` und
  `meter_group_id` müssen auf existierende, gültige Ziele zeigen.
- **delete-Guards erweitert:** ein Elternzähler mit zugeordneten Subzählern
  kann nicht gelöscht werden, ohne die Zuordnung vorher aufzulösen; das
  Löschen einer Gruppe löst ihre Mitglieder (statt zu blockieren).

### Tests

- 15 neue PHPUnit-Tests (`MeterTopologyTest`): Migration (idempotent +
  Feld-Defaults), Validierung (Ketten/Zyklen/Existenz), Gruppen-CRUD,
  Merge-Wizard, delete-Guards und Aggregation ohne Doppelzählung.
  Gesamt: 66 Tests / 219 Assertions.
- Frontend-API-Shape um Gruppen-Endpoint + Topologie-Felder erweitert;
  CI-Migrations-Smoke prüft jetzt 1.2.0 inkl. `meter_groups.json`.

---

## [1.7.4] — 2026-05-31 — F1007 Demo-Daten-Import über die Einstellungen

MINOR-Release. Macht den Demo-Datensatz per Knopfdruck in der UI ladbar —
ideal für einen frisch installierten oder containerisierten, leeren
Energietracker.

### Added

- **F1007 — Demo-Daten-Komfort-Import.** Im Einstellungs-View unter
  „Backup & Wiederherstellung" ein neuer Button **„Demo-Daten laden"**.
  - Lädt das mitgelieferte Demo-JSON-Backup über den bestehenden
    Restore-Pfad (`BackupService::import()`) — inklusive Schema-Guard und
    automatischem Pre-Restore-Snapshot (N1004), also verlustfrei rückholbar.
  - **Warnung vorab**, wenn bereits Daten vorhanden sind (Bestätigungsdialog);
    bei leerem Tracker direkter Import ohne Nachfrage.
  - Neuer Service `DemoService` + Controller, Endpoints
    `GET /api/demo/status` (`{available, is_empty}`) und
    `POST /api/demo/import` (Body `{force}`). „Leer" = kein Zähler über alle
    Verbrauchsarten.
  - Architektur: **Variante B (serverseitig)** — das Demo-Backup wird vom
    Server gelesen; im Docker-Image ist genau diese eine Datei enthalten
    (`.dockerignore`-Ausnahme), nicht das ganze `demo-data/`-Verzeichnis.

### Tests

- Neue PHPUnit-Klasse `DemoServiceTest` (4 Tests): leer-Erkennung +
  Verfügbarkeit, Import füllt Zähler, Abbruch ohne `force` bei vorhandenen
  Daten, erzwungener Re-Import.

---

## [1.7.3] — 2026-05-31 — N1005 Docker-Image + N1010 strukturiertes Logging

MINOR-Release (zwei nicht-funktionale Anforderungen, kein Schemawechsel).
Macht Energietracker als Container reproduzierbar betreibbar und gibt ihm
zum ersten Mal echtes strukturiertes Logging — beide Themen gehören
zusammen, weil ein Container ohne maschinenlesbare Logs nur halb betreibbar
ist.

### Added

- **N1005 — Docker-Image (Single-Container).** `Dockerfile` auf Basis
  `php:8.4-fpm-alpine` mit nginx + php-fpm, von `supervisord`
  zusammengehalten. Ein `docker run -p 8080:80 -v ./data:/data …` genügt.
  - `docker-compose.yml` mit Volume-Mount für `./data` und Log-ENV.
  - `docker/nginx.conf` spiegelt 1:1 das Routing aus `router.php`
    (statische Assets, `/data` + `/src` gesperrt, `/api(.php)/…` → api.php,
    SPA-Fallback auf index.php).
  - `docker/php-fpm-app.conf`: `clear_env = no` (reicht `ET_*`-ENV an PHP
    durch) und `catch_workers_output = yes` (Worker-stderr → `docker logs`).
  - Container-`HEALTHCHECK` nutzt den N1003-Endpoint `GET /api/health`.
  - **GHCR-Publikation**: neuer Workflow `.github/workflows/docker-publish.yml`
    baut bei jedem `v*`-Tag und pusht nach
    `ghcr.io/bingerminger/energietracker` (Tags `{version}`, `{major}.{minor}`,
    `latest`).
  - **CI**: neuer Job `docker` baut das Image und smoke-testet es end-to-end
    (Health, `/api/…` und `/api.php/api/…`, statisches Asset, SPA-Fallback,
    `/data`-Sperre).
- **N1010 — strukturiertes Logging.** Neuer, abhängigkeitsfreier
  `Energietracker\Logging\Logger` (PSR-3-*orientiert*, ohne `psr/log`-
  Dependency — die Laufzeit bleibt Composer-frei). Ein JSON-Objekt pro
  Zeile („JSON Lines"), Default-Ziel stderr.
  - Steuerung per ENV: `ET_LOG_LEVEL` (debug|info|warning|error, Default
    info), `ET_LOG_DEST` (stderr|file|null, Default stderr), `ET_LOG_FILE`
    (Default `<dataDir>/logs/app.log`).
  - `ErrorHandler` loggt ab jetzt jede Exception und jeden fatalen Fehler
    (Level error, mit Typ/Datei/Zeile/HTTP-Status), bevor die JSON-Antwort
    rausgeht — vorher gingen Fehler ungeloggt verloren.
  - App-Lebenszyklus wird geloggt: Migration ausgeführt / frische
    Initialisierung (info), ein Access-Log-Eintrag pro Request (debug, im
    Default-Level also stumm).

### Tests

- Neue PHPUnit-Klasse `LoggerTest` (4 Tests): JSON-Lines-Format,
  Level-Schwellwert, Null-Ziel (aus), Fallback bei unbekanntem Level.
  Suite jetzt **47 Tests / 166 Assertions**, alle grün.

### Fixed

- **Multi-Arch-Image** (Nachzügler am selben Tag): der erste GHCR-Publish
  baute nur `linux/amd64`, sodass `docker pull` auf Apple Silicon /
  arm64 mit „no matching manifest for linux/arm64/v8" scheiterte. Der
  Publish-Workflow baut jetzt via QEMU/Buildx eine Manifest-Liste für
  **`linux/amd64` + `linux/arm64`**; das `v1.7.3`-Image wurde neu
  veröffentlicht.

---

## [1.7.2] — 2026-05-23 — P-PV-01: PV-Einspeisung als Erlös statt Kosten + realistische Forecast-Demodaten

PATCH-Release. Behebt das in v1.7.1 beobachtete Artefakt P-PV-01: die
PV-Einspeisungs-Detail-View rechnete und färbte den Vergütungs-Erlös mit
der generischen Verbrauchs-Semantik („Nachzahlung", „Unterzahlt", rot).

### Fixed

- **P-PV-01 (Backend)** — `ConsumptionService::contractStatus()` für
  `accounting_kind = feed_in`:
  - **Verdict-Achse umgedreht**: positiver Saldo ist eine `Auszahlung`
    des Netzbetreibers (gut), keine `Nachzahlung`. Negativer Saldo →
    `Rückforderung` statt `Erstattung`.
  - **Projektionshorizont begrenzt**: feed_in-Verträge werden bis zur
    nächsten Jahresabrechnung (`billing_cycle_anchor`) projiziert, nicht
    bis zum Vertragsende. Ein 20-Jahres-EEG-Vertrag (Ende z.B. 2043)
    erzeugte vorher ein absurdes „erwartet +10.756 €"; jetzt ~+2.000 €
    bis zur nächsten Abrechnung.
- **P-PV-01 (Frontend)** — `public/js/views/utility.js` für feed_in:
  - KPI „Verbrauch" → „Einspeisung", „Kosten" → „Erlös" (grün).
  - „Abschläge"-KPI ausgeblendet (Einspeisung hat keine Abschläge).
  - Saldo-Karte: „Verbraucht" → „Vergütung", „Abschlag bezahlt" →
    „Bereits ausgezahlt (über Netzbetreiber)", „Aktueller Saldo" →
    „Vergütungsanspruch / Guthaben beim Netzbetreiber". Positiver Saldo
    jetzt **grün** (Vergütung), nicht rot.
  - 3-Monats-Trend-Banner für `feed_in`/`generation` unterdrückt — der
    Vergleich ist bei sonnengetriebenen Reihen reine Saisonalität
    (Frühling vs. Winter), kein echter Verbrauchstrend.

### Changed

- **Demo-Daten** `demo-data/pv_einspeisung/` und `demo-data/pv_erzeugung/`
  neu generiert mit realistischer Jahres-Streuung (gutes/schlechtes
  Sonnenjahr ±8–12 %: 2024 ~10.260 kWh, 2025 ~9.370 kWh) plus
  Monatsrauschen, Reihe bis 2026-05. Damit liefert das Forecast-
  Saisonprofil für PV eine aussagekräftige, nicht-triviale Demo
  (Sommer-Peak ~910 kWh/Monat, Herbst fallend).

### Migration

Keine. Schema bleibt **1.1.0**. Reine Anzeige-/Berechnungs-Korrektur und
Demo-Daten — bestehende User-Daten unberührt.

### Tests

- `vendor/bin/phpunit` → **43 Tests / 152 Assertions, alle grün**
  (41 aus v1.7.1 + 2 neue):
  - `ContractStatusFeedInTest` (2) — feed_in-Verdict ist „Auszahlung"
    (nicht „Nachzahlung"); Projektion bleibt innerhalb der nächsten
    Abrechnungsperiode statt bis EEG-Vertragsende 2043.
- Frontend-API-Shape 14/14, Browser-Render 36/36 grün.

### Lessons Learned

- **Generische Views brauchen explizite Sonderfall-Schalter.** F1005
  (v1.7.0) hat PV als eigene Utility sauber modelliert, aber die
  Detail-View blind die Verbrauchs-Semantik wiederverwendet — Code lief
  grün durch alle Tests, war fachlich aber invertiert. Erst der Blick
  auf den realen Screenshot („+10.756 € Nachzahlung") deckte es auf.
  Lesson: bei einem neuen `accounting_kind` ist die Annahme „die
  bestehende View passt schon" zu prüfen, nicht vorauszusetzen.
- **Demo-Daten ohne Streuung verstecken Forecast-Bugs.** Die erste
  PV-Demo (v1.7.1) hatte jährlich identische Werte → perfekte Regression,
  die Forecast-Qualität war nicht beurteilbar. Realistische Jahres-/
  Monats-Streuung macht die Demo erst als Test- und Verkaufsartefakt
  brauchbar.

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.7.1] — 2026-05-23 — N1004 (Backup/Restore-UI) + Demo-Daten und Doku für PV nachgereicht

### Added

- **N1004** — Backup-Restore-Sicherungen:
  - Vor jedem `BackupService::import()` legt die App automatisch einen
    Snapshot der aktuellen Daten unter `data/backups/pre-restore-<ts>.json`
    ab. Wer mit dem Import-Ergebnis unzufrieden ist, kann diesen
    Snapshot wieder einspielen.
  - Schema-Version-Check: Backups mit `meta.schema_version >`
    `Migrator::SCHEMA_VERSION` werden hart abgelehnt
    (`InvalidArgumentException`). Vermeidet das stille Einspielen eines
    1.2.0-Backups in eine 1.1.0-App.
  - Restore-Report trägt jetzt `auto_snapshot_before_restore` (Dateiname
    oder Fehlerobjekt). Frontend zeigt den Namen im Erfolgs-Toast.
- **Demo-Daten v1.7.0 nachgereicht** (`demo-data/pv_einspeisung/` und
  `demo-data/pv_erzeugung/`):
  - Realistisches 10-kWp-Eigenheim-Szenario, Inbetriebnahme 2023-04-01,
    Standort Leipzig (analog Strom-Demo).
  - 36 monatliche Ablesungen je Zähler bis 2026-03-15.
  - Jahresertrag ~9.500 kWh, Eigenverbrauchsquote 30 %, Autarkiequote
    ~46 %, EEG-Einspeisevergütung 8,2 ct/kWh (IB 04/2023 nach § 48 EEG
    2023). `demo-data/settings.json` aktiviert beide PV-Utilities.
- **Szenario-Doku für PV**:
  - [`docs/functional/08-szenario-eigenheim.md`](docs/functional/08-szenario-eigenheim.md)
    um Sektion 6 „Photovoltaik — Einrichtung und Lesart" erweitert
    (welche Zähler bei welcher Anlage, EEG-Sätze, Strom-Saldo-Lesart,
    Autarkie- und EV-Quote, CO₂-als-vermieden, Erfassungs-Disziplin).
  - [`docs/functional/07-szenario-wohnung.md`](docs/functional/07-szenario-wohnung.md)
    um Sektion 7 „Sonderfall Balkonkraftwerk" ergänzt (warum
    `pv_einspeisung` dort nichts bringt, `pv_erzeugung` optional als
    Performance-Kontrolle).

### Changed

- UI: der Restore-Confirm-Dialog erklärt jetzt den automatischen
  Pre-Restore-Snapshot — verringert die Reibung beim Klick, weil der
  User weiß, dass ein Rollback-Pfad existiert.
- `BackupService::saveSnapshot()` akzeptiert optional einen
  `$prefix`-Parameter (Default `backup_`). Bestehende Aufrufer
  unverändert; intern genutzt für `pre-restore-` Sicherungen.

### Migration

Keine. Schema bleibt **1.1.0**. Bestehende User-Daten werden nicht
angefasst; Demo-Daten-Erweiterung ist nur für frische Installationen
relevant.

### Tests

- `vendor/bin/phpunit` → **41 Tests / 146 Assertions, alle grün**
  (38 aus v1.7.0 + 3 neue):
  - `BackupServiceRestoreGuardTest` (3) — Schema-Guard wirft bei
    neuerem Backup-Schema, akzeptiert gleiches Schema, legt Auto-
    Snapshot im `backups/`-Verzeichnis ab.

### Lessons Learned

- **Demo + Doku gehören zum Feature.** v1.7.0 lieferte Code, Tests und
  Detail-Konzept, aber keine Demo-Daten und keine ausführliche
  Szenario-Doku. Effekt: ein User, der die Demo lädt, sah keinen
  Mehrwert von F1005, ein User, der die Eigenheim-Doku las, fand kein
  Wort zu PV. Lesson: bei Feature-Releases die Demo-Daten- und
  Szenario-Doku-Erweiterung als verpflichtende Sub-Tasks im
  Release-Plan führen.
- **Bestehender Code zuerst lesen, dann skizzieren.** Die Roadmap-Skizze
  für N1004 forderte „ZIP-Stream" — der bestehende BackupService liefert
  JSON, was menschenlesbar, leicht diff-bar und ohne `ext-zip`-Abhängig-
  keit ist. Die JSON-Lösung beizubehalten und nur die fehlenden
  Sicherungen (Schema-Guard, Auto-Snapshot vor Restore) zu ergänzen,
  war der richtige Code-First-Move.

---

## [1.7.0] — 2026-05-23 — F1005: PV-Einspeisung + Erzeugung + Autarkiequote, N1003: Health-Check

Erstes funktionales Release seit v1.6.0 — F1004. Die NFR-Slots N1001/N1002
in v1.6.2/v1.6.3 haben das Regression-Safety-Net geschaffen, das die
substanziellere Erweiterung um Photovoltaik erst risikoarm möglich gemacht
hat.

### Added

- **F1005** — Photovoltaik. Zwei neue Utilities erweitern das
  6-Utility-Modell auf 8:
  - `pv_einspeisung` — Einspeisezähler des Verteilnetzbetreibers, mit
    vereinfachtem Vertragsmodell (nur ct/kWh-Einspeisevergütung; kein
    Grundpreis, kein Abschlagsplan, keine Sonderzahlungen — der
    Verteilnetzbetreiber zahlt nach Erzeugung, nicht nach Plan).
  - `pv_erzeugung` — Wechselrichter-Gesamtertrag, rein statistisch
    (keine Verträge — Vertrag-Create wird hart abgelehnt).
  Beide ohne Default-Meter (PV ist optional; wer keine Anlage hat,
  bekommt keine „Phantom-Zähler" angeboten). Beide cumulative,
  kWh-nativ, `accounting_kind`-Property neu in der `Utilities`-SSOT.
- **F1005 — Strom-Saldo** (`StromSaldoService` + `GET /api/strom-saldo`).
  Kombinierte KPI `bezug_cost − einspeisung_revenue` mit
  Vorzeichen-Konvention `positiv = Netto-Kosten`, `negativ = Netto-Erlös`.
  Liefert monatliche und jährliche Aggregate; Hauptdashboard zeigt das
  laufende Jahr als neue Insight-Karte.
- **F1005 — PV-Eigenverbrauch + Autarkiequote** (`PvSummaryService` +
  `GET /api/pv-summary`).
  `eigenverbrauch = erzeugung − einspeisung` (auf ≥ 0 geklammt),
  `eigenverbrauchsquote = eigenverbrauch / erzeugung`,
  `autarkiequote = eigenverbrauch / (eigenverbrauch + bezug)`.
  Quoten sind null, wenn der Nenner < 0,1 kWh ist; Feld
  `has_generation_meter` zeigt an, ob die App den Eigenverbrauch
  überhaupt berechnen kann.
- **CO₂-Anzeige als „vermieden"** für `pv_einspeisung`. Eingespeiste
  kWh × `co2_strom`-Faktor wird als negativer Wert mit Tooltip
  „Vereinfachte Modellrechnung; PV-Lebenszyklus nicht berücksichtigt"
  ausgewiesen.
- **N1003** — Health-Check. `GET /api/health` liefert `version`,
  `schema_version`, `data_dir_writable`, `migrations_pending`,
  `data_initialized_at`, `php_version`, `timezone`. Eignet sich für
  Synology-Healthcheck und „bei mir geht nichts"-Triage.
- Neue Frontend-API-Wrapper `api.stromSaldo()`, `api.pvSummary()`,
  `api.health()`.
- Neues Konzept-Dokument
  [`docs/functional/12-pv.md`](docs/functional/12-pv.md).

### Changed

- `Utilities` SSOT um die Helper `hasContracts()`, `accountingKind()`,
  `isFeedIn()`, `isGenerationOnly()` erweitert. `hasAdvancePaymentContracts()`
  schließt PV nun explizit aus (kein Abschlagsmodell).
- `Migrator::initFresh()` legt für PV-Utilities (wie für Heizöl/Pellets)
  keinen Default-Meter an.
- `ContractService::create()` weist Vertrag-Anlage auf Statistik-Utilities
  (`pv_erzeugung`) hart mit `InvalidArgumentException` zurück und
  ignoriert für `pv_einspeisung` die Felder `base_prices`,
  `advance_payments`, `special_payments`.
- `JsonStore::__construct()` löst das `rootDir` einmal per `realpath()`
  auf. Pre-existing macOS-Bug: `/tmp` ist ein Symlink auf `/private/tmp`;
  die Path-Prüfung mit dem unaufgelösten Pfad warf bisher bei jedem
  lokalen Test-Setup „Ungültiger Speicherpfad". CI auf Linux war nicht
  betroffen.
- Browser-Render-Test um Render-Smokes für `pv_einspeisung` und
  `pv_erzeugung` erweitert.

### Migration

Keine. Schema bleibt **1.1.0**. Bestehende Installationen erhalten beim
nächsten Lese-/Schreibzugriff transparent leere PV-Verzeichnisse über
die defensive `JsonStore::read()`-Default-Logik. PV-Utilities sind im
SettingsService-Default `active_utilities = ['gas', 'strom', 'wasser']`
NICHT enthalten — wer PV erfassen möchte, aktiviert sie in den
Einstellungen.

### Tests

- `vendor/bin/phpunit` → **38 Tests / 139 Assertions, alle grün**
  (25 aus v1.6.3 + 13 neue für F1005/N1003):
  - `PvUtilityReadingPathSmokeTest` (3) — Standard-Pfade greifen für PV
    ohne Anpassung; Helper-Klassifikation korrekt.
  - `ContractServiceFeedInTariffTest` (2) — Feed-in-Schema vereinfacht;
    Generation-only-Utility lehnt Vertrag-Create ab.
  - `StromSaldoServiceTest` (3) — leer, Bezug-ohne-PV, PV-flippt-Saldo-
    negativ.
  - `PvSummaryServiceTest` (3) — ohne Erzeugungsmeter null-Quoten,
    Standard-Rechnung, Klammern bei inkonsistenten Daten.
  - `HealthCheckServiceTest` (2) — Shape stabil, Version stimmt mit
    VERSION-Datei.
- Frontend-API-Shape: **14/14 grün**.
- Browser-Render: **36/36 grün** (vorher 34; zwei neue PV-Render-Smokes).

### Lessons Learned

- **Macht der SSOT.** Zwei neue Utilities durch einen einzigen
  Eintrag in `Utilities.php` plus minimalem Migrator-Patch reichten,
  damit die kompletten Service-Pfade (Bridging, Anomaly, Forecast,
  ContractStatus, CSV-Export) ohne weitere Änderung greifen.
  Mit weniger SSOT-Disziplin wäre F1005 ein 10-Datei-Eingriff geworden.
- **Pre-existing JsonStore-Bug erst bei lokalem Endpunkt-Smoke sichtbar.**
  PHPUnit umging ihn mit `realpath()`-Workaround im Harness; der lokale
  PHP-Server stolperte sofort beim ersten `/api/health`-Aufruf.
  Lesson: lokaler Endpunkt-Smoke ist nicht ersetzbar durch grüne
  PHPUnit-Suite — der Workaround im Test-Harness hat den Bug *im
  Produktionscode* maskiert. Fix gehört in den Production-Code
  (`JsonStore::__construct`), nicht ins Test-Setup.
- **„Klein anfangen" vs. „Kundenwert".** Die Roadmap hatte F1005 als „S"
  geplant (nur Einspeisezähler). Die Multiple-Choice-Klärung mit dem
  User hat ergeben, dass Erzeugungszähler + Autarkiequote essenziell für
  den PV-Eigentümer-Use-Case sind — Slot wurde bewusst auf M–L
  hochgestuft, dafür ist v1.7.0 das erste Release in einer Weile, das
  sichtbaren Mehrwert für eine neue User-Gruppe liefert.

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.6.3] — 2026-05-22 — N1002: Edge-Case-Test-Suite

### Added

- **N1002** — Edge-Case-Suite aufbauend auf der PHPUnit-Foundation aus
  N1001. Alle 10 Konstellationen aus dem Roadmap-Slot abgedeckt, in vier
  neuen Test-Klassen unter `tests/unit/Services/`:
  - `ConsumptionEdgeCasesTest` — Zählerüberlauf, lange Lücke,
    doppeltes Datum, negativer Verbrauch, Schaltjahr, DST-Übergang,
    leerer/einzelner Zähler.
  - `ContractEdgeCasesTest` — Vertragswechsel mitten im Monat
    (Stichtag-Konvention: am 1. des Monats aktiver Vertrag bekommt den
    ganzen Monat) und Wechsel exakt zum 1.
  - `WaterContractEdgeCasesTest` — Wasser-Vertrag ohne Schmutz- und
    ohne Niederschlagswasser-Komponente.
  - `ReadingEdgeCasesTest` — Schreibpfad: Ablesung vor `installed_on`
    wird abgewiesen, Bulk-Import meldet sie als `skipped`+`errors` und
    importiert die übrigen Zeilen sauber; doppelte Daten im Import
    überschreiben statt zu duplizieren.

### Changed

- Keine. Diese Suite dokumentiert ausschließlich vorhandenes Verhalten;
  kein Test deckt einen Bug auf, kein Service-Code wurde geändert.

### Migration

Keine. Schema bleibt **1.1.0**.

### Tests

- `vendor/bin/phpunit` → **25 Tests / 80 Assertions, alle grün**
  (12 Tests aus v1.6.2 + 13 neue Edge-Case-Tests).
- Regression-Safety-Net für F1006 (Meter-Topologie) steht damit
  vollständig — `ConsumptionService::forMeter()` darf in v1.8.0 ohne
  Angst angefasst werden.

### Lessons Learned

- **Vorrechnen lohnt sich:** der erste DST-Test rechnete „15.→31.März =
  16 Tage", korrekt ist 17 (`DateTime::diff` liefert die Differenz, nicht
  die Kalendertage). Lieber den Test-Code laufen lassen und das
  beobachtete Verhalten dokumentieren, als das erwartete Verhalten
  vorzuformulieren.
- **PHPUnit-Risky-Test bei leerem Iterator:** ein `foreach`-Loop, der
  über `[]` läuft, führt zu null Assertions und wird als „risky" gemeldet
  (`failOnRisky=true` lässt den Test rot werden). Lösung: vor dem Loop
  eine Gesamt-Assertion (`array_sum`/`assertEmpty`), die den
  Verwerfungs-Pfad explizit prüft.

---

## [1.6.2] — 2026-05-22 — N1001: PHPUnit-Foundation für die Service-Schicht

### Added

- **N1001** — PHPUnit-Foundation. Erstmalige Composer-Nutzung im Repo,
  konsequent **dev-only**: `composer.json` führt `phpunit/phpunit ^11.5`
  als `require-dev`, der Runtime-Autoloader in `src/bootstrap.php` bleibt
  unverändert hand-rolled, der Auslieferungs-ZIP enthält weiterhin kein
  `vendor/`.
- Test-Verzeichnis `tests/unit/` mit Support-Basisklasse
  `ServiceTestCase`. Jeder Test bekommt ein frisches temporäres
  `data/`-Verzeichnis (via `Migrator::initFresh()`), die Services werden
  identisch zum App-Container zusammengesteckt — **keine Mocks der
  Storage-Schicht** (Lesson „Mocks nicht über echte Disk-I/O", v1.4.x).
- Drei Test-Klassen mit zusammen 12 Tests, fokussiert auf die in
  Issue [#13] gefixten Pfade:
  - `ConsumptionServiceBridgingTest` — 5 Bridging-Pfade
    (sauber, Off-by-one am Tausch-Tag, fehlender `final_counter`,
    Plausibilitäts-Cap, `device_swap`-Flagging).
  - `MeterServiceReplaceDeviceTest` — `old_final_counter` als Pflicht,
    `deviceOnDate`-Stichtag-Konvention (`>= removed_on` → neues Gerät).
  - `AnomalyServiceTest` — Wechsel-Monat aus z-Score-Erkennung
    ausgeschlossen, Feldnamen-Vertrag stabil.

### Changed

- `.github/workflows/ci.yml`: neuer Job `phpunit` (läuft nach
  `lint-php`), inkl. Composer-Cache. `lint-php` ignoriert jetzt
  zusätzlich `vendor/`.
- `.gitignore`: `.phpunit.cache/` und `.phpunit.result.cache` ergänzt.

### Migration

Keine. Schema bleibt **1.1.0**. Composer ist optional und wird
ausschließlich für die Test-Suite gebraucht — eine bestehende
Installation muss nichts tun.

### Tests

- `vendor/bin/phpunit` → **12 Tests, 41 Assertions, alle grün**.
- Bestehende Frontend-API-Shape- und Browser-Render-Tests unverändert,
  bleiben grün.

### Lessons Learned

- macOS-Spezial: `sys_get_temp_dir()` liefert `/var/folders/...` (Symlink
  auf `/private/var/folders/...`). `JsonStore::path()` prüft per
  `realpath()` und würde sonst „Ungültiger Speicherpfad" werfen — im
  Test-Harness das `dataDir` einmal mit `realpath()` auflösen.
- PHP 8.5-Deprecation „Implicit nullable parameter" ist hier schon
  aktiv. `?string $x = null` statt `string $x = null` schreiben, auch
  wenn die Production noch auf 8.4 läuft.
- Gas-Tests sind ungeeignet als Default-Fixture: der Conversion-Faktor
  `gas_kwh_per_m3` (Default 11.5) verzerrt erwartete Verbrauchssummen.
  Für Bridging-Unit-Tests `strom` wählen (`unit_to_kwh = false`,
  Conversion-Faktor = 1.0).

[#13]: https://github.com/Bingerminger/energietracker/issues/13

---

## [1.6.1] — 2026-05-21 — Bugfix: Wasser-KPI & Zählerwechsel-Ausschlag

### Fixed

- **Issue [#14] — Wasser-Sub-Dashboard zeigte 0 m³ Verbrauch.**
  `public/js/views/utility.js` summierte das KPI „Verbrauch" und die
  Spalte „m³" der Monatstabelle stur aus `m.kwh`. Wasser ist im
  Backend m³-nativ — `applyUtilityFields` legt den Wert nach
  Aggregation in `m.m3` um und nullt `m.kwh`. Folge: Wasser-View
  zeigte 0, obwohl Haupt-Dashboard und `m³/Tag`-Spalte (gespeist aus
  einem vor der Umlage gesetzten Feld) korrekt waren. Fix: `consKey`
  (`'kwh'` für kWh-Utilities, `'m3'` für m³-Utilities) wird in beiden
  Render-Funktionen (`render` und `monthlyTable`) auf Funktions-Scope
  gehoben und konsistent verwendet — KPI-Wert, Monatstabellen-Zelle
  und Footer-Total.

- **Issue [#13] — Riesiger Ausschlag im Monat des Zählertauschs.**
  Bei einem Tausch sah das Dashboard einen Spike von ~200 000 kWh
  (Gas) bzw. ~1 100 m³ (Wasser) im Wechsel-Monat. Vier Ursachen
  wurden gefunden und gefixt:

  1. **`MeterService::replaceDevice`** setzte still
     `final_counter = 0`, wenn das Frontend das Feld leer ließ. Das
     ist eine versteckte Datenkorruption: nach `replaceDevice`
     scheint das alte Gerät „sauber geschlossen", obwohl der echte
     Endstand fehlt. Jetzt wirft die API einen 400-Fehler, wenn
     `old_final_counter` fehlt.

  2. **Off-by-one in `MeterService::deviceOnDate`** und
     `ConsumptionService::deviceIdOnDate`: am Tausch-Tag
     (`removed_on`) wurde noch das ALTE Gerät zurückgegeben, weil
     `$date > $d['removed_on']` am exakten Wechsel-Tag false ist.
     Ablesungen am Tausch-Tag bekamen dadurch zur Anlegezeit
     fälschlich `device_id=alt`. Geändert auf `$date >=
     $d['removed_on']` → am Wechsel-Tag greift das neue Gerät.

  3. **Plausibilitäts-Check in `ConsumptionService::consumptionBetween`**:
     wenn der Vor-Stand (`prev.counter`) eines Bridging-Intervalls
     außerhalb des Wertebereichs `[initial_counter_alt,
     final_counter_alt]` des angeblich alten Geräts liegt, ist die
     `device_id` der Ablesung offensichtlich falsch zugewiesen
     (typischer Fall: Tausch-Tags-Ablesung mit dem frischen Stand des
     neuen Zählers — z. B. 0,1 m³ — wurde fälschlich `device=alt`
     gespeichert). Das Intervall wird verworfen statt einen
     200-fachen Ausschlag zu rechnen. Schützt auch bei
     Bestandsdaten, die noch mit dem Off-by-one (#2) angelegt wurden.

  4. **`AnomalyService::detect`** ignoriert Wechsel-Monate. Über ein
     neues `device_swap`-Flag pro Monatszeile (gesetzt von
     `ConsumptionService::markSwapMonths` für alle Monate, in denen
     `installed_on` oder `removed_on` eines nicht-initialen Geräts
     liegt) werden Tausch-Monate aus der z-Score-Erkennung
     ausgeschlossen — ein Tausch ist ein erklärlicher Sondereffekt,
     keine fachliche Anomalie.

### Tests

- `tests/frontend-api-shape.test.js` ergänzt um zwei Regressions-
  Checks: (a) Wasser-Monthly hat `m3 ≠ 0`, `kwh = 0`; (b)
  Monatszeilen tragen `device_swap`-Flag. **14/14 Checks bestanden.**
- `tests/browser-render.test.mjs` unverändert. **34/34 bestanden.**

### Migration

Keine Datenmodell-Änderungen. Schema bleibt **1.1.0**.

Hinweis: bestehende Ablesungen, deren `device_id` am Tausch-Tag durch
den alten Off-by-one falsch gesetzt wurde, bleiben in der JSON
gespeichert wie sie sind — der Plausibilitäts-Check (#3 oben)
verhindert lediglich den falschen Ausschlag im Chart. Wer die
Zuordnung sauber korrigieren möchte: Ablesung am Tausch-Tag im UI
löschen und neu anlegen (sie bekommt dann mit v1.6.1 die korrekte
`device_id=neu`).

### Lessons Learned

- **Default-Werte sind eine versteckte Datenkorruption.**
  `(float)($input['old_final_counter'] ?? 0)` ließ einen unvollständig
  konfigurierten Tausch aussehen wie einen sauber geschlossenen. Eine
  fehlende Pflichtangabe muss eine explizite 400-Antwort sein, kein
  stiller Default.
- **Off-by-one am Stichtag.** `>` und `>=` am Wechsel-Tag waren
  semantisch nicht durchgehend gleich definiert. Wenn ein Intervall
  am Tag X endet und das nächste am Tag X beginnt, gehört X
  konventionsmäßig zum NEUEN Intervall. Diese Konvention muss
  überall identisch sein (Anlage UND Auswertung).
- **Frontend-Backend-Feldnamen über mehrere Stages.** `kwh_per_day`
  wurde VOR `applyUtilityFields` gesetzt (also auf dem rohen `kwh`-
  Feld), dann legte `applyUtilityFields` `kwh` nach `m3` um. Beide
  Felder konnten danach widersprüchliche Aussagen tragen
  (M³-Spalte 0, M³/Tag 0,3). Lehre: utility-spezifische Umlagen
  müssen entweder ganz am Ende stattfinden ODER alle abgeleiteten
  Felder konsistent mitführen.
- **Defensive Plausibilitäts-Checks > kosmetische Ausschlag-
  Filter.** Erste Idee war ein „verwerfen wenn `total > 100×
  finalOld`". Das hat den Bug NICHT gefangen, weil Viktors Werte
  knapp darunter lagen. Erst der **inhaltliche** Check „liegt
  `prev.counter` überhaupt im Wertebereich des angeblich alten
  Geräts" greift, weil er die Ursache (falsche device_id) prüft,
  nicht das Symptom (großer Wert).

[#13]: https://github.com/Bingerminger/energietracker/issues/13
[#14]: https://github.com/Bingerminger/energietracker/issues/14

---

## [1.6.0] — 2026-05-18 — F1004: Zentrale Zählerstand-Erfassung

### Added

- **F1004 — Zentraler Zählerstand-View** (`#/zaehlerstaende`).
  Neuer Menüpunkt an erster Position (eigene Gruppe „Erfassung"),
  mobile-first für die schnelle Vor-Ort-Erfassung auf dem iPhone.
  Bündelt alle kumulativen Zähler (**Gas, Strom, Wasser, Fernwärme**)
  in einer Karten-Liste mit jeweils:
  - Bezeichnung + Standort + Verbrauchsart-Icon
  - letzter bekannter Stand (Wert, Datum, ggf. „geschätzt"-Tag)
  - Eingabefeld für den neuen Stand (`inputmode="decimal"` →
    Zahlentastatur)
  - Datumsfeld (Default heute, pro Zeile überschreibbar)
  - „Geschätzt"-Toggle (mappt auf das bestehende `is_estimated`-Flag)
  - aufklappbare Notiz
  Ein Sticky-Save-Button am unteren Rand speichert alle ausgefüllten
  Karten sequenziell.

- **Neuer Aggregat-Endpunkt** `GET /api/readings-overview`. Liefert
  alle aktiven kumulativen Zähler plus jeweils letzte reale Ablesung
  in einem Roundtrip — beim Öffnen der Ansicht **ein** API-Call.
  Speichern erfolgt danach pro Zeile über die bestehende Route
  `POST /api/utility/{u}/readings`.

- **Mobile-First-Optimierung.** Touch-Targets ≥ 48 px,
  `env(safe-area-inset-bottom)` für den iPhone-Home-Indicator-Bereich,
  einspaltiges Layout unter 600 px, Karten-Maxbreite 720 px für
  Desktop/Tablet.

- **Inline-Validierung.** Rückwärts-Zählerstand wird mit einem
  orangefarbenen Hinweis markiert (nicht hart blockiert — Zählertausch
  ist ein realer Fall). Leere Karten werden beim Speichern still
  übersprungen.

- **Robust gegen Teilfehler.** Eine fehlerhafte Karte blockiert die
  anderen nicht; pro Karte erscheint ✓ oder ✗, am Ende fasst ein
  Toast zusammen. Nach erfolgreichem Speichern wird der „letzter
  Stand" in der Karte aktualisiert.

- **Doku.** Neue Seite [`docs/functional/11-zaehlerstaende.md`](docs/functional/11-zaehlerstaende.md)
  mit Aufbau, Validierungsregeln, Mobile-First-Designentscheidungen
  und expliziter Liste der bewusst nicht in v1.6.0 enthaltenen
  Features (Foto, Offline-Cache, OCR, Batch-Endpoint).

- **Tests.** `frontend-api-shape.test.js` deckt den neuen Endpunkt
  (Shape, Scope-Whitelist) ab; `browser-render.test.mjs` deckt die
  neue View (Render, Karten-Anzahl, `inputmode="decimal"`,
  ISO-Datum, Sticky-Save, Delivery-Ausschluss) ab. Stand v1.6.0:
  **12/12 Frontend-API-Shape**, **34/34 Browser-Render**.

### Internal

- `ReadingService::overview(array $activeUtilities)` als
  Aggregations-Helfer. Filtert auf `Utilities::isCumulative()` und
  ignoriert inaktive Zähler.
- `ReadingController::overview()` bindet den Endpunkt an;
  Settings-Injection im Konstruktor.
- Frontend: neues Modul `public/js/views/readings-entry.js`,
  Sidebar-Eintrag in eigener Gruppe „Erfassung" oberhalb des
  Dashboards, Router-Eintrag `#/zaehlerstaende`, API-Client-Methode
  `api.readingsOverview()`, neues Stylesheet
  `public/css/readings-entry.css`.

### Migration

- **Kein Migrationsschritt nötig.** Reine additive Erweiterung
  (neuer Endpunkt, neue View, kein Schemafeld). Bestehende
  Readings/Meters/Contracts unverändert. Schema bleibt **1.1.0**.

### Bewusst nicht enthalten

- Foto-Aufnahme, Offline-Modus, OCR/Ziffernerkennung,
  Batch-Speicher-Endpunkt. Begründet und dokumentiert in der
  funktionalen Doku (`11-zaehlerstaende.md`, Abschnitt „Was bewusst
  nicht in v1.6.0 ist").

---

## [1.5.1] — 2026-05-18 — CI-Fix: Testserver-Routing (router.php)

### Fixed

- **Browser-Render-Test in der CI scheiterte am Modulgraph-Crawl.**
  Der Testserver wurde mit `php -S … api.php` gestartet — damit lief
  **jeder** Request (auch `/public/js/app.js`) durch `api.php`, das nur
  `/api/*` kennt → HTTP 404 für statische Assets, der Modulgraph-Crawl
  brach ab. Neu: **`router.php`** als Built-in-Server-Router, der das
  nginx-Verhalten spiegelt:
  1. existierende statische Dateien direkt ausliefern,
  2. `/data/` und `/src/` sowie `*.php`-Quelltext mit 404 sperren
     (wie `location ~ ^/data/ { deny all; }`),
  3. `/api.php/…` **und** `/api/…` an `api.php` delegieren
     (SCRIPT_NAME korrekt gesetzt),
  4. sonst `index.php` (SPA-Shell, entspricht `try_files`).
- **CI-Server-Step überlebte den Schritt nicht.** Server-Start (`&`)
  und Testläufe lagen in getrennten `run:`-Blöcken; in GitHub Actions
  ist jeder `run:` eine eigene Shell, der Hintergrundprozess war im
  Folge-Step weg. Server-Start, Readiness-Probe, beide Test-Suites und
  Teardown (`trap … EXIT`) laufen jetzt in **einem** Step.
- Doku (`tests/README.md`, `docs/technical/05-testing.md`) auf
  `router.php` umgestellt, inkl. Begründung warum nicht `api.php`.

### Verifikation

- `frontend-api-shape`: **9/9** mit `router.php`.
- `browser-render`: Modulgraph lädt **alle 21 Module** via HTTP (200);
  der ursprünglich fehlschlagende Crawl ist behoben. Voller
  Suite-Durchlauf lokal mit altem Router bereits **28/28**; die
  Router-Änderung ist rein additiv/strikter (statische Auslieferung
  und `/api/`-Routing nachweislich 200, `/data//src` nachweislich 404).

### Hinweis

- **Kein Eingriff in Anwendungscode.** Reine Test-/CI-Infrastruktur.
  `router.php` wird ausschließlich von `php -S` benutzt; Produktiv­
  betrieb läuft unverändert über nginx/Apache. Schema unverändert 1.1.0.

---

## [1.5.0] — 2026-05-17 — F1003: Sonderzahlungen

### Added

- **F1003 — Sonderzahlungen bei vertragsrelevanten Energieträgern**
  (Gas, Strom, Fernwärme). Pro Standard-Vertrag lässt sich eine Liste
  von Sonderzahlungen pflegen, mit exakt fünf Arten:
  1. **Rückzahlung (mit Auswirkung auf Abschlagszahlungen)** — Guthaben
     vom Versorger; setzt zusätzlich einen neuen Monatsabschlag ab
     Stichtag.
  2. **Rückzahlung (ohne Auswirkung auf Abschlagszahlungen)** — reine
     Gutschrift, Abschlag unverändert.
  3. **Nachzahlung (mit Auswirkung auf Abschlagszahlungen)** — Zuzahlung
     nach Abrechnung; setzt zusätzlich einen neuen Monatsabschlag.
  4. **Nachzahlung (ohne Auswirkung auf Abschlagszahlungen)** — reine
     Zuzahlung, Abschlag unverändert.
  5. **Abschlagszahlung** — zusätzliche/einmalige Abschlagszahlung des
     Kunden.
- **Saldo-Integration.** Der Saldo rechnet jetzt
  `Kosten − gezahlte Abschläge + Sonderzahlungs-Netto`, wobei
  `Netto = Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung`.
  Rückzahlungen gleichen eine Überzahlung aus (Saldo steigt),
  Nach-/Abschlagszahlungen senken die Schuld.
- **„mit Auswirkung auf Abschlagszahlungen".** Solche Einträge tragen
  zusätzlich `new_advance_eur` + `advance_from`. Diese Punkte werden in
  den effektiven Abschlagsplan (`ContractService::effectiveAdvance‐
  Schedule()`) gemischt; jede monatliche Abschlagsbildung greift
  automatisch — ohne Sonderlogik in der Saldo-Aggregation.
- **UI.** Neue Sektion „Sonderzahlungen" im Vertragsformular (nur
  Gas/Strom/Fernwärme), mit 5-Arten-Auswahl und kontextabhängig
  eingeblendeten Abschlagsfeldern. Die Saldo-Karte weist
  Rückzahlung/Nachzahlung/Abschlagszahlung getrennt aus; die
  Vertragskarte zeigt die Anzahl.
- **Validierung** (F4-analog): leere Zeilen werden still verworfen,
  halb-gefüllte Datum/Betrag-Zeilen abgelehnt, unbekannte Arten
  abgelehnt, `*_mit` mit nur einem von neuem-Abschlag/Stichtag
  abgelehnt. Beträge werden auf positiv erzwungen (Vorzeichen ergibt
  sich aus der Art).

### Internal

- `Utilities::hasAdvancePaymentContracts()` als Single-Source-of-Truth
  für den F1003-Scope (kumulativ und nicht Wasser → Gas/Strom/
  Fernwärme). Frontend spiegelt dieselbe Logik.
- `ContractService`: `normalizeSpecialPayments()`,
  `effectiveAdvanceSchedule()`, `specialPaymentSummary()`.
- `ConsumptionService` nutzt im Standard-Pfad den effektiven
  Abschlagsplan statt `advance_payments` direkt; Wasser-Pfad
  unverändert (No-op, da Wasser keine Sonderzahlungen hat).
- `contractStatus()` liefert zusätzlich `special_refund_total`,
  `special_surcharge_total`, `special_advance_total`,
  `special_payment_net`, `special_payments_count`.

### Migration

- **Kein Migrationsschritt nötig.** `special_payments` ist additiv und
  defaultet beim Normalisieren auf `[]` (gleiches Muster wie
  `bonuses`). Bestandsverträge ohne das Feld funktionieren unverändert.
  Schema bleibt **1.1.0** (abwärtskompatibel).

---

## [1.4.5] — 2026-05-17 — CI: Node-24-Action-Runtime

### Fixed

- **GitHub-Actions-Deprecation-Warnung behoben.** `actions/checkout`
  und `actions/setup-node` von `@v4` auf `@v5` gehoben. Die `@v4`-Tags
  laufen intern auf Node.js 20, das von GitHub deprecated wurde (ab
  2026-06-02 ist Node 24 Runner-Default, ab 2026-09-16 wird Node 20
  entfernt). `@v5` ist die etablierte Node-24-Baseline. **Kein
  funktionaler Eingriff** — die CI lief auch mit `@v4` grün durch
  (es war eine Warnung, kein Fehler); der Bump entfernt sie und macht
  die Pipeline zukunftssicher.
- Bewusst **nicht** geändert: `node-version: "20"` im Test-Job (das ist
  die Node-Version für die *Testausführung*, nicht die Action-Runtime
  — die Test-Harnesses sind auf Node ≥ 20 ausgelegt) sowie
  `shivammathur/setup-php@v2` (von der Deprecation nicht betroffen,
  in GitHubs Warnung nicht gelistet).

---

## [1.4.4] — 2026-05-17 — Code-Qualität, CI-Pipeline, Audit-Härtung

### Added

- **CI/CD-Pipeline (GitHub Actions).** Neues `.github/workflows/ci.yml`
  mit zwei Jobs:
  - `test` — startet den PHP-Backend-Server mit Demo-Daten, führt
    `frontend-api-shape.test.js` und `browser-render.test.mjs` aus.
  - `lint-php` — prüft die Syntax aller PHP-Dateien parallel mit
    `php -l` (PHP 8.4). Beide Jobs laufen auf `push` und `pull_request`
    gegen `main`.
- **`ET_DATA_DIR`-Umgebungsvariable in `api.php`.** Der Storage-Pfad
  ist jetzt per ENV überschreibbar (Fallback: `./data`). Ermöglicht
  CI-Tests gegen `/tmp/etdata` ohne Änderung an produktivem Code.
- **`DeliveryConsumptionService` (neue Klasse).** Die drei Delivery-
  Berechnungsmethoden (`dailyDeliveryConsumption`, `dailyDeliveryStockDraw`,
  `deliveryMeterStartDate`) wurden aus `ConsumptionService` in eine
  eigenständige Klasse extrahiert. `ConsumptionService` delegiert über
  schlanke Wrapper; das öffentliche API bleibt vollständig kompatibel.
  Der interne Dispatch (`computeForDeliveryMeter`) nutzt einen
  Lazy-Getter, der auch funktioniert, wenn `DeliveryConsumptionService`
  nicht explizit injiziert wird — kein Breaking Change für bestehende
  Aufrufer. `ConsumptionService` schrumpft dadurch um ~350 Zeilen.

### Fixed

- **`JsonStore::path()` Traversal-Schutz (Defense-in-Depth).** Nach dem
  Pfadaufbau wird per `realpath` + Präfix-Check geprüft, dass der
  resultierende Pfad innerhalb von `rootDir` liegt. Bisher war nur der
  Service-Layer-Whitelist-Check in `Utilities::exists()` aktiv; der neue
  Schutz auf Speicherebene ist unabhängig davon und fängt jeden Pfad ab,
  der aus dem Datenverzeichnis ausbricht.
- **`DiagnosticsService` Fallback-Version.** `'1.2.0'` → `'unknown'`.
  Der Fallback tritt nur auf, wenn die `VERSION`-Datei fehlt; eine
  konkrete veraltete Versionsnummer war irreführend.
- **Demo-Daten `schema_version`.** `demo-data/meta.json` wurde auf
  `"schema_version": "1.1.0"` aktualisiert und `demo-data/reminders.json`
  angelegt. Damit startet eine Demo-Instanz ohne unnötige
  Migrations-Durchläufe und der Migrator-Status ist konsistent mit dem
  tatsächlichen Datenstand.

### Changed

- **`tests/backend-shape.test.js` → `tests/frontend-api-shape.test.js`.**
  Datei umbenannt — der neue Name spiegelt die tatsächliche Perspektive
  wider (Frontend-seitige Erwartungen an die API-Shapes, nicht
  Backend-interne Prüfung). Leerer `loadModule`-Stub entfernt (war
  toter, nie aufgerufener Code). `tests/README.md` entsprechend
  aktualisiert.
- **Stale Versions-Kommentare bereinigt.** `utility.js` (trug `v1.0.2`),
  `api.js` (`v1.2.0`) und `api.php` (`v1.2.0`) trugen veraltete
  Versionsnummern im Datei-Header. Kommentare auf neutralen Text ohne
  konkrete Versionsnummer umgestellt — die kanonische Quelle ist
  `VERSION`.

### Internal

- `src/bootstrap.php` initialisiert `DeliveryConsumptionService` explizit
  vor `ConsumptionService` und übergibt es als Abhängigkeit.
- `ConsumptionService`-Konstruktor hat neuen optionalen Parameter
  `?DeliveryConsumptionService $deliveryConsumption = null` (abwärtskompatibel).

---

## [1.4.3] — 2026-05-16 — Sigmoid in der Analyse, Vertragslogik je Energieart, valides Doku-Markdown, korrekter App-Name

### Fixed

- **#1 Sigmoid-Kurve fehlte im Analyse-Korrelationschart.** Der
  HGT-Streudiagramm-Block iterierte nur über vier Modelle (linear,
  polynomial, robust, segmentiert). `sigmoid` ist jetzt ergänzt: in der
  Vorhersagefunktion (spiegelt `RegressionService::sigmoidPredict`
  exakt — numerisch verifiziert, Abweichung < 1e-9), im Kurvenstil, in
  der Modell-Iteration und in der R²-Koeffizienten-Übersicht.
- **#2 Vertrags-Views ergaben für lieferbasierte Arten keinen Sinn.**
  Heizöl/Pellets haben per Logik keine Verträge (die Tankrechnung ist
  die Kostenbasis). Behoben an drei Stellen:
  - `utility.js` blendet die Vertrags- und Saldo-Karte für
    lieferbasierte Arten aus.
  - Die Vertrags-View (`contracts.js`) zeigt für Heizöl/Pellets einen
    erklärenden Hinweis statt eines (sinnlosen) Gas-Vertragsformulars
    und leitet zu den Lieferungen.
  - Der Tarifvergleich (`tariff.js`) schließt lieferbasierte Arten aus
    (Schattenverträge sind dort nicht anwendbar) — zusätzlich zum
    bereits ausgeschlossenen Wasser.
  - Geprüft und bestätigt: **Fernwärme** ist korrekt kumulativ mit
    echten Verträgen (Arbeits- + Grundpreis) — bleibt unverändert.
- **#3 Doku-Markdown wurde teils falsch dargestellt.** Alle
  LaTeX-Formeln (`$…$` / `$$…$$`) wurden durch GitHub-sichere
  Klartext-Codeblöcke ersetzt (rendern überall identisch). Das
  Architektur- und das Analysezyklus-Diagramm wurden mit sauber
  ausgerichtetem Plain-ASCII neu gezeichnet. Alle Codeblöcke erhielten
  eine Sprachangabe; geprüft: balancierte Fences, keine defekten
  internen Links, keine LaTeX-Reste, keine render-kritischen
  Lint-Fehler.
- **#4 Falscher App-Name im Topbar-Titel.** „ENERGIE TRACKING" →
  **„ENERGIETRACKER"** (einzige Fundstelle; die extrahierten Logo-Icons
  sind bereits textfrei).

### Changed

- **Test-Harness browser-realistisch erweitert.** `browser-render`
  löst relative URLs jetzt wie ein Browser gegen die Basis-URL auf und
  stellt einen Chart.js-Stub bereit (zuvor zwei Setup-Lücken, die
  Render-Checks blockierten). Neue Regressionstests für #1 (Sigmoid in
  der Analyse) und #2 (Liefer-Arten ohne Verträge / kumulative Arten
  mit Verträgen). Stand: **Backend-Shape 9/9, Browser-Render 28/28**.

### Verifiziert (numerische Logik)

- Eigenständige Referenz-Nachrechnung von 16 Kern-Formeln gegen den
  realen Code: HGT, lineare Tagesinterpolation, R², Regressions-
  steigung/-achsenabschnitt, Sigmoid (fit↔predict konsistent),
  Prognose-Blend (`w = min(R², blend_max)`).
- End-to-End: `total_eur`-Vorrang isoliert exakt (1500 €/2000 L → genau
  7,5000 ct/kWh, Energiebilanz aufs kWh genau); Saldo-Formel
  (`current_balance = actual_cost − advance_paid`) und Kostenzerlegung
  (`Arbeit + Grund − Bonus`) über alle Demo-Verträge mit 0,00
  Abweichung; Tank-Bestandskurve nie negativ / nie über Kapazität.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Reine UI-/Anzeige- und
  Doku-Korrekturen; keine Daten- oder API-Änderung.

---

## [1.4.2] — 2026-05-16 — Export aller Energiearten, Datumsformat, PDF-Kennzahlen, neues Logo, Doku-Kompendium

### Added

- **CSV-Lieferungs-Export** für lieferbasierte Arten:
  `GET /api/export/{utility}/deliveries.csv` (Heizöl/Pellets) mit
  korrekter Einheit (L bzw. kg) im Spaltenkopf.
- **Neues App-Icon** (Haus mit Farbring) aus dem gelieferten Logo
  extrahiert — Tag-/Nacht-Variante, als Favicon, Apple-Touch-Icon und
  themenabhängiges Topbar-Logo eingebunden (`public/img/`).
- **Doku-Kompendium** unter `docs/` — getrennt technisch
  (`docs/technical/`, 6 Kapitel), fachlich (`docs/functional/`, je
  Energieart + Szenarien + Glossar) und UI (`docs/ui/` mit Mockups
  aller 11 Views). Wird ab dieser Version bei jedem Release gepflegt.

### Fixed

- **#2 Export war nicht für alle Energiearten verfügbar.** Die
  Export-UI in den Einstellungen war auf gas/strom/wasser hartkodiert.
  Sie baut die Kacheln jetzt dynamisch aus den aktiven Verbrauchsarten;
  Fernwärme erhält Monats-/Ablesungs-Export, Heizöl/Pellets den neuen
  Lieferungs-Export.
- **#3 PDF-Diagramm ohne Achsen/Beschriftung.** Das achsenlose
  Mini-Liniendiagramm im Jahresbericht wurde entfernt und durch eine
  Kennzahlen-Leiste (Jahresverbrauch, Ø/Monat, Gesamtkosten,
  stärkster/schwächster Monat) plus die bestehende Monatstabelle
  ersetzt.
- **#4 Abrechnungszyklus im falschen Datumsformat.** Der Stichtag wurde
  als `MM-TT` angezeigt/eingegeben. Die UI nutzt jetzt das deutsche
  Format `TT-MM`; die Speicherung bleibt kanonisch `MM-TT`, damit das
  Backend valide Datümer baut (Konvertierung nur an der UI-Grenze,
  inkl. Validierung/Februar-Klemmung).

### Changed

- **#5/#6 Heizöl/Pellets-Kosten — Gesamtbetrag hat Vorrang.** Ist auf
  einer Tankrechnung der Gesamtbetrag (`total_eur`) erfasst, wird
  dieser als Kostenbasis genutzt (enthält Liefergebühr/Rabatt); der
  effektive Stückpreis wird daraus abgeleitet. Nur ohne Gesamtbetrag
  wird wie bisher `unit_price_cents` × Menge gerechnet. Bestätigt:
  Heizöl/Pellets haben bewusst **keine** Vertrags-Entität — die
  Tankrechnung selbst ist die Kostenbasis.
- **Doku ersetzt.** Die alten Einzeldateien `docs/API.md`,
  `docs/ARCHITECTURE.md` und `docs/screenshots/` sind in das
  strukturierte Kompendium übergegangen. `docs/MIGRATION-FROM-V090.md`
  bleibt erhalten und wird aus dem Kompendium verlinkt.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Neue Exporte und der
  neue Lieferungs-Endpoint sind rein additiv. Bestehende
  `billing_cycle_anchor_*`-Werte bleiben gültig (kanonische Speicherung
  unverändert `MM-TT`; geändert wurde nur die Anzeige).

---

## [1.4.1] — 2026-05-16 — Bugfix: Sigmoid-Modell in der Prognose wählbar

### Fixed

- **Sigmoid-Regressionsmodell war in der Prognose nicht auswählbar.**
  Der Modell-Selektor in der Forecast-Ansicht
  (`public/js/views/forecast.js`) listete nur vier der fünf Modelle —
  `sigmoid` fehlte, obwohl es in den Einstellungen als Standardmodell
  wählbar war und das Backend (`ForecastService` →
  `RegressionService::fit`) es längst unterstützte. Reine Frontend-
  Lücke; Option ergänzt. Der Browser-Render-Test prüft jetzt, dass
  alle fünf Modelle (linear, polynomial, robust, segmented, sigmoid)
  in der Prognose wählbar sind.

### Migration

- Keine Schema-, Datenmodell- oder API-Änderung. Reiner UI-Bugfix.

## [1.4.0] — 2026-05-16 — Tank-Bestandsmodell & Effizienz pro Heizquelle

Korrektur zweier Modell-/Darstellungsschwächen, die beim Anlegen der
Demo-Daten für die neuen Energieträger sichtbar wurden, plus ein
kritischer Startup-Bugfix.

### Fixed

- **App startete nicht (kritisch).** `public/js/lib/sidebar.js`
  importierte `./state.js` / `./api.js` statt `../state.js` /
  `../api.js`. Da `sidebar.js` von `app.js` geladen wird, brach dieser
  404 den gesamten ES-Modulgraphen — die Oberfläche blieb bei „Lade…"
  stehen. Pfade korrigiert; neuer Modulgraph-Regressionstest
  (`tests/browser-render.test.mjs`) crawlt `app.js` samt aller
  transitiven Importe über HTTP und fängt solche Fehler künftig.
- **Tank-Bestandskurve zeigte konstruktionsbedingt immer ~0 %.** Das
  alte Modell verteilte die *gesamte* gelieferte Energie HGT-gewichtet
  über die Laufzeit und erzwang damit Endbestand ≈ 0 — unabhängig von
  den Liefermengen. Zusätzlich mischte `stockHistory()` Liter mit kWh
  (latenter Einheitenfehler, von der 0-Normierung maskiert). Neu:
  `ConsumptionService::dailyDeliveryStockDraw()` berechnet den Abzug in
  Mengeneinheiten aus einer **aus den geschlossenen Lieferintervallen
  kalibrierten kWh/HGT-Rate**. Die Bestandskurve ist jetzt ein
  realistischer Sägezahn ohne Zwang auf 0. Die Kosten-/Effizienz-
  Berechnung (`dailyDeliveryConsumption()`, Energiebilanz) bleibt
  unverändert — sie ist für die Verbrauchs-/Kostensicht korrekt.

### Changed

- **Effizienz-Benchmark jetzt pro Heizquelle.** Statt alle
  Heizenergie-Arten zu summieren (was bei mehreren aktiven Heizquellen
  eine unsinnige Klasse ergab), weist `/api/benchmarks/efficiency` nun
  `per_source` (Klasse je Quelle), `primary` (größte Quelle) und
  `combined` (Summe, nur für bewusst kombinierten Heizbetrieb) aus.
  Die Top-Level-Felder `class`/`kwh_per_m2`/`total_kwh` bleiben als
  rückwärtskompatible Aliase erhalten, zeigen aber nun die **primäre
  Heizquelle** statt der Summe. Dashboard-Karte und PDF-Bericht zeigen
  bei mehreren Quellen eine Aufschlüsselung; die Empfehlungsregel R7
  bewertet jede Quelle einzeln.
- **Demo-Daten vollständig.** Demo-Datensätze für Fernwärme, Heizöl und
  Pellets ergänzt (zuvor nur Gas/Strom/Wasser), inkl. realistischer
  Tankgrößen und Lieferkadenz, sodass jede Verbrauchsart ab Start
  bedienbar ist.

### Migration

- Keine Schema- oder Datenmodell-Änderung; Schema bleibt **1.1.0**.
  Reine Berechnungs-/Darstellungs- und Bugfix-Änderungen. API-Antwort
  von `/api/benchmarks/efficiency` ist additiv erweitert; bestehende
  Felder bleiben kompatibel.

---

## [1.3.0] — 2026-05-16 — Lieferbasierte Energieträger, Effizienz, Insights

Das bislang umfangreichste Release. Drei neue Verbrauchsarten, ein
zweites Datenmodell (lieferbasiert statt kumulativ), Wetterbereinigung,
Effizienzklassen, eine Empfehlungs-Engine, Termin-/Wartungsverwaltung,
Tarifvergleich mit Schattenverträgen und ein PDF-Jahresbericht.

**Schema-Migration 1.0.3 → 1.1.0** — automatisch und idempotent beim
ersten Start. Bestehende Gas-/Strom-/Wasser-Daten bleiben unverändert;
neue Verbrauchsarten und `data/reminders.json` werden angelegt.

### Added

- **Drei neue Verbrauchsarten.** Fernwärme (kumulativ, kWh,
  HGT-relevant), Heizöl und Pellets (beide **lieferbasiert** —
  Brennstofflieferungen statt Zählerständen). Heizöl rechnet in Litern,
  Pellets in kg; Energiegehalt je Einheit ist konfigurierbar
  (`heizoel_kwh_per_l`, `pellets_kwh_per_kg`).
- **Lieferbasiertes Datenmodell.** Für Heizöl/Pellets werden Lieferungen
  erfasst (`{date, quantity, unit_price_cents, total_eur, supplier,
  note, is_planned}`). Der Monatsverbrauch wird aus
  Anfangsbestand + Lieferungen energetisch bilanziert und über einen
  konfigurierbaren Sockelanteil (flach) plus HGT-Gewichtung (Rest)
  auf die Monate verteilt. Tank-Bestandskurve über die Zeit.
- **Wetterbereinigung.** Monatstabelle HGT-relevanter Arten erhält
  `expected_hgt` (Regressionserwartung), `weather_adjusted`
  (auf das langjährige Kalendermonats-HGT normierter Verbrauch nach
  VDI-3807-Logik) und `delta_pct`. Schwachlastmonate werden korrekt
  ausgeblendet.
- **Effizienzklasse.** Heizenergiebedarf in kWh/m²·a über alle
  Heizenergie-Arten, eingestuft in A+…H gegen konfigurierbare
  Bandgrenzen (`/api/benchmarks/efficiency`). Wohnfläche, Baujahr und
  Gebäudetyp sind in den Einstellungen pflegbar.
- **Empfehlungs-Engine.** Sieben rein statistische Regelfamilien aus
  den Eigendaten (wetterbereinigter Mehrverbrauch, schleichender Trend,
  hoher Sommerverbrauch, Anomalie, Tank-Niveau, Vertragsende,
  Effizienzklasse) mit Schweregrad und stabiler ID; einzeln
  ausblendbar (`/api/recommendations`).
- **Termin- & Wartungsverwaltung.** Wiederkehrende Termine
  (Heizungswartung, Schornsteinfeger, Zähler-Eichfristen u. a.) mit
  Fälligkeitsstatus und Recurrence-Fortschreibung beim Erledigen
  (`/api/reminders`).
- **Tarifvergleich mit Schattenverträgen.** Hypothetische Tarife
  (`is_shadow`) lassen sich auf die tatsächlichen historischen
  Verbräuche rechnen und echten Verträgen gegenüberstellen, ohne Saldo
  oder Prognose zu beeinflussen
  (`/api/utility/{u}/meters/{id}/tariff-comparison`).
- **PDF-Jahresbericht.** Mehrseitiger A4-Bericht (Deckblatt,
  Übersicht/Effizienz, je Verbrauchsart Tabelle + Verlaufsdiagramm,
  Empfehlungen) als Datei-Download — erzeugt von einem eigenen,
  abhängigkeitsfreien PDF-Writer (`/api/reports/yearly.pdf`).
- **Neue Regressionsmodelle.** `sigmoid` (Heizsignatur nach
  TU-München/BDEW-Form, robust gefittet) sowie `segmented` mit
  **datenbasiertem Knickpunkt** (`segmented_split_mode = auto`).
- **Aktivierbare Verbrauchsarten.** `active_utilities` steuert, welche
  Arten in Sidebar, Dashboard und Bericht erscheinen — inaktive Daten
  bleiben erhalten. Sidebar wird dynamisch daraus aufgebaut.
- **Dashboard-Insight-Karten.** Effizienzklasse, Tank-Bestände,
  Top-Empfehlungen und anstehende Termine auf einen Blick.

### Changed

- **Einstellungen erweitert** von 28 auf **50 Schlüssel** plus die
  `active_utilities`-Auswahl: Gebäude/Effizienz, Energieträger-
  Energiegehalte, Tank-Warnschwelle, Termin- und Empfehlungs-Schwellen,
  Abrechnungs-Stichtage der neuen Arten, Segment-Knickpunktmodus,
  `sigmoid` im Prognosemodell-Picker, PDF-Download.
- **Sidebar** ist nicht mehr statisch in `index.php` verdrahtet, sondern
  wird zur Laufzeit aus aktiven Verbrauchsarten und neuen Views
  (Tarifvergleich, Empfehlungen, Termine) erzeugt; Badges für offene
  Empfehlungen und fällige Termine.

### Migration

- Schema-Version steigt auf **1.1.0**. Der Migrator erkennt 1.0.3 und
  ergänzt fehlende Verzeichnisse/Dateien idempotent — kein manueller
  Schritt nötig. Ein Sicherheits-Snapshot wird wie üblich vor dem
  ersten Schreiben angelegt. Ein Downgrade auf 1.0.x wird nicht
  unterstützt (die neuen Verbrauchsarten würden ignoriert).

### Hinweise

- Der PDF-Writer nutzt bewusst **keine externe Bibliothek** (kein
  composer, mPDF, gd oder mbstring) — konsistent mit der
  abhängigkeitsfreien, flat-file-Architektur der App.
- Die Tank-Bestandskurve ist eine Modellschätzung (Anfangsbestand +
  Lieferungen − HGT-gewichteter Verbrauch), keine Tankpeilung; bei
  langen Lieferintervallen kann der modellierte Bestand 0 erreichen.
- Tarifvergleich ist für Wasser (Drei-Komponenten-Tarif) bewusst nicht
  enthalten.

---

## [1.2.0] — 2026-05-15 — Theme-Toggle + Diagnose-Bugfix

Tag/Nacht-Umschaltung für die gesamte UI plus eine kosmetische
Aufräumung der System-Diagnose. Keine Backend- oder Datenmodell-
Änderungen — Schema bleibt unverändert bei **1.0.3**.

### Added

- **Tag/Nacht-Umschaltung.** Ein Toggle-Knopf rechts in der Topbar
  schaltet zwischen Dunkel- und Hellmodus. Die Wahl wird in
  `localStorage["et-theme"]` persistiert; auf den ersten Besuch ohne
  gespeicherte Wahl wird `prefers-color-scheme` respektiert. Ein
  Anti-Flash-Inline-Skript in `index.php` setzt das Theme bereits vor
  dem CSS-Laden, damit es keinen Aufblitz-Effekt gibt. Solange der User
  noch nicht selbst geklickt hat, folgt die App OS-Theme-Änderungen
  live (z.B. macOS schaltet abends auf dunkel). Implementierung via
  `[data-theme="light|dark"]`-Attribut auf `<html>` plus CSS-Variablen
  in `tokens.css` — keine Style-Duplikation.

### Fixed

- **[#1] System-Diagnose-Optik.** In v1.0.4 → v1.1.0 sah die System-
  Diagnose (unter Einstellungen) hässlich aus, vor allem die Zeilen
  `utilities`, `temperatures` und `settings_known_keys` waren rohe
  JSON-Dumps und liefen seitlich aus dem Layout. Neu:
  - Header-Felder (App-Version, PHP, Datenverzeichnis usw.) in einem
    sauberen Definition-Grid.
  - `utilities` als kompakte Tabelle mit Spalten Zähler / Ablesungen /
    Verträge / Letzte Ablesung.
  - `temperatures` als „N Tageswerte gespeichert"-Klartext.
  - `settings_known_keys` als gewickelte Mono-Chips.
  - Boolean-Felder (`data_dir_writable`, `curl_available`,
    `migration_needed`) als farbige Badges.

### Notes

- Mehrere bislang hardcodierte `rgba(…)`-Farbwerte in `app.css` und
  `components.css` wurden durch `var(--token)` bzw. `color-mix(in srgb,
  var(--token) NN%, transparent)` ersetzt, damit das Light-Theme korrekt
  durchschlägt. Funktional unverändert für das Dark-Theme.
- `components/chart.js` liest Theme-Farben jetzt live aus CSS-Variablen
  (per Proxy). Beim Theme-Wechsel werden `Chart.defaults` neu gesetzt;
  bereits gerenderte Charts ziehen die neuen Farben aber erst beim
  nächsten Re-Render an (eine Navigation in der App genügt).
- Keine neuen Settings-Keys, keine Datenmodell-Änderung. Schema-Version
  unverändert `1.0.3`.

[1.2.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.2.0

---

Erstes MINOR-Release nach der v1.0.x-Bugfix-Serie. Sieben neue Funktionen
und ein Datenmodell-Fix — alle additiv und abwärtskompatibel. **Das
Speicherschema bleibt unverändert bei 1.0.3**; die neuen Einstellungen
werden über die Defaults gemerged, eine `settings.json` aus einer älteren
Version funktioniert ohne Migrationsschritt weiter.

### Added

- **[F-02] Vertragsbasierte Kostenprognose.** Die Prognose rechnete bisher
  mit dem letzten bekannten Arbeitspreis. Jetzt löst der `ForecastService`
  pro Prognosemonat den dann aktiven Vertrag auf und verwendet den **für
  diesen Monat gültigen** Arbeits- und Grundpreis aus der Preishistorie —
  ein im Vertrag für die Zukunft gepflegter Preiswechsel schlägt damit
  korrekt in `cost_estimated` durch. Die Monatsdetail-Tabelle hat zwei
  neue Spalten: *projizierter Abschlag* und *laufender Saldo* (kumuliert
  Kosten − Abschlag). Künftige Boni werden nicht fortgeschrieben — nur im
  Vertrag mit Gutschriftdatum gepflegte Boni fließen ein.
- **[F-03] Abrechnungszyklus je Verbrauchsart.** Drei neue Settings-Keys
  `billing_cycle_anchor_gas|strom|wasser` (Format `MM-TT`, Default
  `01-01`). Der Saldo offener Verträge (`end = null`) wird nun bis zum
  nächsten Abrechnungsstichtag projiziert statt bis „heute + 12 Monate".
  Verträge mit gepflegtem Ende verwenden weiterhin dieses Ende.
- **[F-04] Jahresvergleich mit Monatsdeltas.** Die Korrelations-Ansicht
  bekommt ein eigenes Widget mit der Monat-für-Monat-Differenz der beiden
  jüngsten Jahre mit Daten — absolut und prozentual, inklusive
  Summenzeile über die gemeinsamen Monate. Das bestehende
  „Jahresvergleich"-Liniendiagramm bleibt unverändert daneben bestehen.
- **[F-05] Erinnerung an Vertragsende.** Die `contract-status`-Antwort
  enthält pro Vertrag `days_until_end`, `should_remind` und `remind_stage`
  (0–3). Drei Schwellen sind als Settings-Keys `contract_remind_days_1|2|3`
  konfigurierbar (Default 90 / 30 / 1 Tage). Die Korrelations-Ansicht
  zeigt fällige Verträge als gestuften Hinweis-Banner.
- **[F-06] CSV-Import von Ablesungen.** Neuer zähler-gebundener Endpoint
  `POST /api/utility/{utility}/meters/{id}/readings/import-csv` (Body:
  CSV als `text/plain`). Bereits vorhandene Ablesungen am selben Datum
  werden **überschrieben und im Ergebnis gemeldet** (`imported`,
  `overwritten`, `skipped`, `errors`). Akzeptiert `;`/`,` als Trenner,
  `TT.MM.JJJJ` oder ISO-Datum, deutsches Dezimalkomma. Die Import-Logik
  steckt im quell-agnostischen `ReadingImportService` — eine künftige
  Smart-Meter-Anbindung (siehe Roadmap) kann denselben Kern ohne
  CSV-Parsing wiederverwenden. UI: „CSV-Import"-Knopf je Zähler in der
  Zählerverwaltung.
- **[F-07] CSV-Export.** Drei neue Endpoints liefern tabellarische
  Exporte als Datei-Download: `GET /api/export/{utility}/monthly.csv`
  (Monatsaggregate), `GET /api/export/{utility}/readings.csv`
  (Rohablesungen), `GET /api/export/temperatures.csv` (Temperaturreihe).
  Semikolon-getrennt, UTF-8 mit BOM, deutsches Dezimalkomma — direkt in
  Excel/LibreOffice/Google Sheets nutzbar. UI: Export-Kacheln in den
  Einstellungen. Ergänzt das vollständige JSON-Backup, ersetzt es nicht.
- **[F-10] Wasser-Spar-Index.** Die Korrelations-Ansicht zeigt für Wasser
  einen Index `(Liter/Person/Tag) / Referenz × 100` über die jüngsten bis
  zu 12 Monate, mit Einordnung auf einem Band. Die Bandgrenzen sind als
  Settings-Keys `wasser_sparindex_gut` / `wasser_sparindex_warnung`
  konfigurierbar (Default 100 / 150).
- Einstellungs-Ansicht überarbeitet: gruppierte Settings-Karten in einem
  responsiven Raster, Feld-Erklärungen, Einheitenkennzeichnung. Das
  Settings-Inventar wächst von 20 auf 28 Schlüssel.

### Fixed

- **[#2 — Datenmodell] Schmutzwasser-Basis `separater_zaehler` war ohne
  Wirkung.** Im Wasser-Vertragsmodell (v1.0.3) konnte für die
  Schmutzwasser-Komponente die Basis `separater_zaehler` gewählt werden —
  die Verbrauchsberechnung nutzte aber in jedem Fall das Trinkwasser-m³.
  Jetzt löst der `ConsumptionService` die Basis `separater_zaehler` über
  das Feld `separater_zaehler_meter_id` auf und rechnet mit dem
  monatlichen m³ des referenzierten Zählers. Eine Rekursionssperre
  verhindert Endlosschleifen bei (fehlerhaft) gegenseitig
  referenzierenden Zählern. Kein Schema-Eingriff — das Feld existierte
  bereits, wurde nur nicht ausgewertet.

### Notes

- Schema-Version unverändert **1.0.3**. Keine Migration nötig: fehlende
  Settings-Keys werden beim Lesen aus den Defaults ergänzt.
- Bekannte Einschränkung F-02: bei Wasser mit Schmutzwasser-Basis
  `separater_zaehler` nutzt die *Vorausschau* das Trinkwasser-Volumen als
  Schmutzwasser-Basis (der separate Zähler wird nicht selbst prognostiziert).
  Die *historische* Auswertung rechnet das separate Volumen korrekt.

[1.1.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.1.0

---

Drei via GitHub gemeldete Bugs in der Vertragsverwaltung und in der
Korrelations-Ansicht.

### Fixed

- **[#1] Vertrags-Button irreführend benannt.** Auf den Utility-Seiten
  (Gas/Strom/Wasser) zeigte der Action-Button in der „Verträge &
  Abschläge"-Karte den Text *„+ Neuer Vertrag"* — er führte aber zur
  Vertragsverwaltungs-Seite, nicht zum Vertrag-Anlegen-Dialog. Neu:
  *„⚙️ Verträge verwalten"* mit passendem Icon. Das eigentliche
  Anlegen ist weiterhin in der Vertragsverwaltung selbst per
  „+ Neuer Vertrag"-Button erreichbar.

- **[#2] Speichern/Abbrechen-Buttons im Vertrag-Edit-Modal funktionierten
  nicht bei manchen Verträgen.** Wenn beim Aufbau des Modals ein
  Wiring-Schritt (Boni-Sektion oder Zeilen-Buttons einer Stichtag-
  Gruppe) eine Exception warf — etwa weil ein migriertes v0.9.0-Format
  unerwartete Strukturen mitbrachte — wurden die Fußleisten-Buttons nie
  verbunden, weil sie nach den anderen Handlern gebunden wurden.
  Fixes:
  - Cancel/Save werden jetzt **als allererstes** gebunden, bevor
    irgendein anderer Sub-Handler läuft. Selbst wenn ein nachfolgender
    Schritt fehlschlägt, bleibt das Modal benutzbar.
  - Alle DOM-Lookups in `bindRowHandlers`, `bindBonusHandlers`,
    `bindBonusRow` defensiv mit Optional-Chaining (`?.`). Fehlt ein
    Element, gibt's eine `console.warn`, aber kein TypeError mehr.
  - Die globale ID `#bonus-section` wurde durch `[data-section="bonus"]`
    ersetzt, damit gestapelte Modale nicht über IDs kollidieren können.

- **[#3] Anomalien-Tabelle in der Korrelations-Ansicht zeigte leere
  Werte.** Feldnamen-Mismatch zwischen Backend und Frontend:
  `AnomalyService` liefert `value` / `z_score` / `deviation` / `percent`
  / `kind` / `hdd` / `avg_temp` — das Frontend las `a.actual` und `a.z`
  (existieren nicht), wodurch *Verbrauch* und *Abweichung (σ)* immer
  NaN bzw. leer waren. Das schuf den Eindruck, dass die Anomalien nicht
  zu den eigentlichen Zählerdaten passten.
  Die Anomalien-Tabelle wurde dabei gleich angereichert: Absolute
  Abweichung, Prozent-Abweichung, σ-Wert, plus HGT und ø-Temperatur bei
  HGT-relevanten Utilities. Empty-State-Text erklärt jetzt, gegen welches
  Modell die Schwelle gerechnet wird.

### Notes

- Das geänderte Anomalien-Markup ist nicht-breaking; alle Backend-Felder
  waren bereits vorhanden, nur das Frontend hat sie schlicht nicht
  gelesen.
- Schema-Version (`data/meta.json`) bleibt auf `1.0.3` — keine
  Datenmodell-Änderung in v1.0.4.

[1.0.4]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.4

---

## [1.0.3] — 2026-05-11 — Wasser-Vertragsmodell

Fachlicher Bugfix am Wassermodul. In v1.0.2 wurden Wasserverträge mit
demselben Schema wie Gas und Strom modelliert (ein `working_prices`-Array
mit `ct_per_kwh`), was bei Wasser strukturell falsch ist: eine deutsche
Wasserrechnung enthält drei separate Positionen — Trinkwasser, Schmutzwasser
und Niederschlagswasser — mit jeweils eigener Berechnungslogik.

### Changed — Wasser-Vertragsmodell (Schema 1.0.3)

Wasserverträge haben jetzt drei Komponenten-Blöcke. Gas- und Strom-
verträge bleiben strukturell unverändert.

```json
{
  "id": "c_wasser_...",
  "meter_id": "m_wasser_haupt",
  "provider": "Kommunale Wasserwerke Leipzig",
  "tariff_name": "Trink-, Schmutz- und Niederschlagswasser 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",

  "trinkwasser": {
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 255.0}],
    "base_prices":    [{"from": "2025-01-01", "eur_per_month": 8.50}]
  },

  "schmutzwasser": {
    "basis": "trinkwasser",
    "separater_zaehler_meter_id": null,
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 305.0}]
  },

  "niederschlagswasser": {
    "rates": [
      {"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}
    ]
  },

  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

**Berechnungen pro Monat:**

- *Trinkwasser*: `m³ × ct_per_m3 / 100 + eur_per_month`
- *Schmutzwasser*: `m³ × ct_per_m3 / 100`. `m³` ist standardmäßig der
  Trinkwasser-Verbrauch (Basis `trinkwasser`, Standard in 95% der DE-Haushalte).
  Bei Basis `separater_zaehler` wird in v1.0.3 vorerst der gleiche Trinkwasser-
  Wert verwendet — eine echte Auswertung des separaten Zählers folgt in
  einer späteren Version.
- *Niederschlagswasser*: `(versiegelte_flaeche_m2 × eur_per_m2_year) / 12`.
  Stichtag-Historie für Tarif **und** Fläche, falls sich beides ändert.

### Added — Auto-Migration

Beim ersten Start auf v1.0.3 prüft `Storage\Migrator::needsWaterContractsUpgrade()`
ob noch Wasser-Verträge in der alten v1.0.2-Form (`working_prices` + `base_prices` direkt
am Vertrag) vorliegen. Falls ja:

- Alte `working_prices` wandern nach `trinkwasser.working_prices`, wobei das
  Feld `ct_per_kwh` (das bei Wasser semantisch schon ct/m³ war) zu `ct_per_m3`
  umbenannt wird.
- Alte `base_prices` wandern nach `trinkwasser.base_prices`.
- `schmutzwasser` und `niederschlagswasser` werden mit leeren Arrays
  initialisiert. Eine Notiz im `notes`-Feld weist die User darauf hin,
  beide Komponenten manuell nachzupflegen, da die alten Daten sie
  nicht enthalten haben.
- `advance_payments` und `bonuses` bleiben unverändert.

Schema-Marker in `data/meta.json` springt von `1.0.0` auf `1.0.3`.

### Added — UI

- **Wasser-Saldo-Karte** zeigt unterhalb der 4 Hauptspalten eine
  Komponenten-Zeile mit drei Kacheln (Trinkwasser, Schmutzwasser,
  Niederschlagswasser), jeweils mit aktuellem Tarif, kumuliertem
  Verbrauchsanteil und Grundpreis-Aufschlüsselung.
- **Wasser-Vertragsdialog** (`openWaterContractModal`) mit drei
  ein-/ausklappbaren Komponenten-Sektionen plus Abschläge und Boni.
  Schmutzwasser-Basis ist umschaltbar (Trinkwasser-Verbrauch /
  separater Zähler).
- **Vertragstabelle** für Wasser zeigt die Anzahl der Stichtage pro
  Komponente statt der Standard-Spalten.

### Backend-Änderungen

- `ContractService::normalizeWater()` — strikte F4-Validierung der
  drei Komponenten-Blöcke. Halb-ausgefüllte Niederschlagswasser-Zeilen
  (z.B. Fläche fehlt, Tarif vorhanden) werden mit HTTP 400 abgelehnt.
- `ConsumptionService::applyWaterContracts()` — neue Pro-Monat-Berechnung
  mit drei Komponenten und Aufschlüsselung in `monthly[].trinkwasser`,
  `.schmutzwasser`, `.niederschlagswasser`.
- `ConsumptionService::contractStatus()` für Wasser ergänzt um
  `components.{trinkwasser, schmutzwasser, niederschlagswasser}` mit
  Pro-Komponenten-Summen und aktuellen Tarifwerten.

### Migration

Update von v1.0.2 → v1.0.3 ist beim ersten App-Start automatisch.
Bestehende Backups (Format `backup_version: "3.0"`) bleiben kompatibel,
weil sie unter `utilities.wasser.contracts` die alte Struktur
enthielten — beim Import wird sie wie laufende v1.0.2-Daten erkannt und
in die neue Struktur überführt.

[1.0.3]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.3

---

## [1.0.2] — 2026-05-11 — Initial public release

Erste öffentliche Version des Energietrackers. Selbst-gehostete Web-App
zum Erfassen und Analysieren des privaten Energie- und Wasserverbrauchs.
Single-File-PHP-Backend, Vanilla-JS-SPA-Frontend, flat-file JSON-Persistenz.

Eine private Vorgängerversion existierte unter dem Namen *Energietracker
v0.9.0* (Backup-Format `version: "2.1"`). Backups aus dieser Version
können mit dem eingebauten Migrator importiert werden — siehe
[`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md). Außerhalb
dieses Migrationspfads ist v0.9.0 für die öffentliche Codebase irrelevant.

### Funktionen

- **Drei Verbrauchsarten parallel**: Gas, Strom, Wasser. Pro Utility
  eigene Zähler, Verträge, Tarifhistorie und Berechnungslogik.
- **Mehrere Zähler pro Utility**, jeder mit unabhängiger Vertragsserie
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  beliebig viele Geräte (Devices) mit Seriennummer, Einbaudatum,
  Anfangs-/Endzähler, Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt überbrückt.
- **Vertragshistorie** mit stichtag-genauen Arbeits- und Grundpreisen,
  monatlichen Abschlägen und Boni. Mehrere Stichtage pro Vertrag werden
  als forward-fill auf die Monatszeilen angewandt.
- **Saldo-Berechnung pro Vertrag**: aktueller Stand (Kosten −
  Abschläge) und erwarteter End-Saldo (extrapoliert über die
  verbleibenden Monate). Verdict: Erstattung, Nachzahlung, Ausgeglichen.
- **Heizgradtage** (HGT) je Monat gegen die Basistemperatur, mit
  Open-Meteo-Sync und CSV-Import für historische Tagestemperaturen
  (Format `DD.MM.YYYY"avg"min"max`, double-quote-getrennt).
- **Vier Regressionsmodelle** für HGT vs. Verbrauch: linear,
  polynomial Grad 2, robust (Huber), segmentiert
  (Heiz-/Sommerlast bei HGT = 50 als Default-Schwelle).
- **12-Monats-Forecast** als R²-gewichtete Mischung aus Regressions-
  modell und Saisonprofil.
- **Anomalie-Erkennung**: Monate mit > 2σ Abweichung vom
  Modell-Erwartungswert.
- **Backup & Restore** über die UI (Format `backup_version: "3.0"`),
  inklusive automatischer Sicherheits-Snapshots im Datenverzeichnis.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`)
  kann zweistufig importiert werden — erst Preview mit Übersicht, dann
  *Ersetzen* oder *Zusammenführen* (Konflikt-Resolution per ID).

### Technische Architektur

- **Backend (PHP ≥ 8.4)**: 13 Services + 11 Controllers, organisiert
  in einem kleinen App-Container (`src/bootstrap.php`). Speicher-Layer
  via `JsonStore` mit `LOCK_EX`-Schreibverbindung. Routing über einen
  kompakten Hand-rolled Router (`src/Http/Router.php`).
- **Frontend (Vanilla JS, ES Modules)**: 8 Views + 3 Komponenten + 1
  Hash-Router, 1 API-Fetch-Wrapper, 1 Lightweight-State-Cache. Kein
  Build-Step, keine Node-Toolchain nötig zur Auslieferung.
- **Daten**: flache JSON-Dateien unter `data/`. Schema-Version
  in `data/meta.json`. Atomic-write-Pattern überall.
- **Visualisierung**: Chart.js 4 per CDN-Import in `index.php`.
- **Typografie**: DM Mono (Zahlen, Datumsangaben) und DM Sans
  (Prosa) per Google Fonts.

### Datenmodell (siehe README → Datenmodell für Details)

- **Reading**: `{id, meter_id, device_id, date, counter, price_cents?,
  note, is_estimated, is_future}`
- **Meter**: `{id, name, icon, created_at, active, notes, devices: [...]}`
- **Device**: `{id, serial, installed_on, initial_counter, removed_on,
  final_counter, reason}`
- **Contract**: `{id, meter_id, provider, tariff_name, start, end, notes,
  working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, bonuses}`

### Settings-Inventar (20 Schlüssel)

```
gas_conversion_factor (kWh/m³)     hdd_base_temp (°C)
co2_gas (g/kWh)                    co2_strom (g/kWh)
co2_wasser (g/m³)                  min_days_period
min_hdd_regression                 blend_max
forecast_months                    min_temp_days_forecast
forecast_model                     dashboard_months
alert_days_since_reading           anomaly_threshold
location_name                      latitude
longitude                          weather_auto_fill
wasser_personen_anzahl             wasser_personen_referenz
```

### Bekannte Einschränkungen

- HGT-Anwendung nur für Gas und Strom relevant; bei Wasser fällt der
  Forecast auf 100 % Saisonprofil zurück.
- Open-Meteo-Sync benötigt `curl` als PHP-Extension.
- Keine Mehrbenutzer- oder Auth-Schicht — Single-Tenant per Design.

[1.0.2]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.2

---

## Roadmap

Nicht terminiert — Reihenfolge und Umfang können sich ändern.

### Smart-Meter-Anbindung (Aufbau auf F-06)

F-06 hat den Ablesungs-Import in zwei Schichten getrennt: die
CSV-Parsing-Schicht und den quell-agnostischen Kern
`ReadingImportService::importRows()`. Damit ist **Variante 1** (gekapselte,
wiederverwendbare Importlogik) bereits umgesetzt.

Offen ist **Variante 2** — ein unbeaufsichtigter Endpoint, über den ein
Smart-Meter-Gateway oder ein Heimautomatisierungs-System Ablesungen ohne
UI-Interaktion einliefert. Dafür nötig:

- Ein Token-geschützter Endpoint (z.B.
  `POST /api/ingest/{utility}/{meter}` mit `Authorization: Bearer …`),
  da die übrige App bewusst auth-frei und Single-Tenant ist.
- Token-Verwaltung (Erzeugen/Widerrufen) in den Einstellungen.
- `importRows()` ist bereits der passende Aufsetzpunkt — der neue
  Endpoint muss nur Auth + Payload-Parsing ergänzen, die Schreib- und
  Überschreiblogik bleibt unverändert.

### Weitere Verbrauchsarten

- Heizöl und Pellets als zusätzliche Utilities (`Config/Utilities.php`
  ist die Single Source of Truth — additiv erweiterbar, ähnlich wie
  Wasser in v1.0.0).

### Internationalisierung

- Englische UI-Sprache. Aktuell sind Labels und Meldungen
  durchgängig deutsch verdrahtet.
