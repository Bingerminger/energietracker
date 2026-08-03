# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

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
