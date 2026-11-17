// Texte der Projektseite, Deutsch und Englisch nebeneinander.
//
// Jede Aussage trägt die Kennungen ihrer Belege (`c`), die in aussagen.md stehen.
// `-` heißt: keine Tatsachenbehauptung (Navigation, Überschrift, Aufforderung).
// Auszeichnung wie in der App (rich): **fett**, `Code`, [Text](Adresse).
// Regeln: keine Gedankenstriche, kein Mittelpunkt als Trenner, keine Beträge,
// keine Download-, Nutzer- oder Sternzahlen. tools/build.mjs --check prüft das.
//
// Mit jedem Release mitziehen: HELD.neu und NEU (Kennungen neu-313 und folgende), „v3.14 erneut geprüft“,
// „3.163 Testmethoden“, die Grenzen aus dem CHANGELOG (Kennungen grenze-*, tests), die Animationen
// (NODE_PATH=… node tools/build-animations.mjs im Haupt-Repo, Stichtag mit --heute).

export const REPO = 'https://github.com/Bingerminger/energietracker';
export const REPO_HA = 'https://github.com/Bingerminger/energietracker-ha';
export const REPO_INTEGRATION = 'https://github.com/Bingerminger/energietracker-integration';
export const DEMO = 'https://bingerminger.github.io/energietracker/demo/';
export const HA_ADD = 'https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FBingerminger%2Fenergietracker-ha';
// Öffnet die Integration in Home Assistant. Ab 2026.11 leitet diese Adresse in den Marketplace (ehemals HACS) um,
// bis 2026.10 öffnet sie HACS; deshalb heißt der Weg in der Adresse noch hacs_repository.
export const INTEGRATION_OPEN = 'https://my.home-assistant.io/redirect/hacs_repository/?owner=Bingerminger&repository=energietracker-integration&category=integration';
export const KAFFEE = 'https://buymeacoffee.com/bingerminger';
export const GH_PRIVACY = 'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement';
export const GH_PAGES_DATA = 'https://docs.github.com/en/pages/getting-started-with-github-pages/about-github-pages#data-collection';

/** Handbuchseite: Deutsch unter docs/, Englisch unter docs/en/ */
export const doc = (lang, path, anchor = '') => `${REPO}/blob/main/docs/${lang === 'en' ? 'en/' : ''}${path}${anchor}`;
/** Datei im Hauptrepo, je Sprache eine eigene Fassung möglich */
export const datei = (lang, de, en = de) => `${REPO}/blob/main/${lang === 'en' ? en : de}`;
/** Sprung in die öffentliche Demo: Beispielhaushalt, Sprache, Ansicht */
export const demo = (lang, persona = '', route = '') =>
  `${DEMO}?${persona ? `persona=${persona}&` : ''}lang=${lang}${route ? `#${route}` : ''}`;

const T = (de, en, c) => ({ de, en, c });

export const META = {
  title: T('Energietracker: Energiekosten im Griff, selbst gehostet', 'Energietracker: your energy costs under control, self-hosted', ['-']),
  description: T(
    'Abschlag, Rechnung, Wechsel und Rechte für Strom, Gas, Wasser, Wärme und PV. Rechnet wie die Abrechnung, erinnert an Fristen. Kostenlos, offener Code, deine Daten bleiben bei dir.',
    'Advance payment, bill, switching and your rights for electricity, gas, water, heat and PV. Calculates the way the bill does and reminds you of deadlines. Free, open source, your data stays with you.',
    ['wertversprechen', 'arten', 'wie-abrechnung', 'kuendigung-erinnerung', 'agpl-kostenlos', 'daten-bleiben']),
  ueberTitle: T('Über das Projekt und Datenschutz: Energietracker', 'About the project and privacy: Energietracker', ['-']),
  ueberDescription: T(
    'Wer hinter dem Energietracker steht, wie du Kontakt aufnimmst und was diese Seite mit deinen Daten tut: nichts von außen, keine Cookies, kein Tracking.',
    'Who is behind Energietracker, how to get in touch and what this page does with your data: nothing from outside, no cookies, no tracking.',
    ['seite-selbst', 'kontakt-github']),
};

export const NAV = [
  { href: '#funktionen', text: T('Funktionen', 'Features', ['-']) },
  { href: '#wege', text: T('Für wen', 'Who for', ['-']) },
  { href: '#home-assistant', text: T('Home Assistant', 'Home Assistant', ['-']) },
  { href: '#vertrauen', text: T('Vertrauen', 'Trust', ['-']) },
  { href: '#fragen', text: T('Fragen', 'FAQ', ['-']) },
];

export const UI = {
  sprung: T('Zum Inhalt springen', 'Skip to content', ['-']),
  sprache: T('Sprache', 'Language', ['-']),
  inhalt: T('Abschnitte', 'Sections', ['-']),
  hinweis: T('This page is also available in English.', 'Diese Seite gibt es auch auf Deutsch.', ['-']),
  hinweisLink: T('Read in English', 'Auf Deutsch lesen', ['-']),
  hinweisZu: T('Close', 'Schließen', ['-']),
  kopieren: T('Kopieren', 'Copy', ['-']),
  kopiert: T('Kopiert', 'Copied', ['-']),
  gross: T('Bild in voller Größe öffnen', 'Open the picture at full size', ['-']),
  // Umschalter wie in der App (lib/theme.js, Katalog app.theme.*)
  thema: T('Darstellung umschalten (jetzt: {mode})', 'Switch appearance (now: {mode})', ['-']),
  themaSystem: T('wie das System', 'system', ['-']),
  themaHell: T('hell', 'light', ['-']),
  themaDunkel: T('dunkel', 'dark', ['-']),
  themaMeldungSystem: T('Automatisch: folgt dem System', 'Automatic: follows the system', ['-']),
  themaMeldungHell: T('Hell', 'Light', ['-']),
  themaMeldungDunkel: T('Dunkel', 'Dark', ['-']),
  themaA: T('A', 'A', ['-']),
  zurueck: T('Vorheriges Bild', 'Previous picture', ['-']),
  weiter: T('Nächstes Bild', 'Next picture', ['-']),
  folie: T('{n} von {m}', '{n} of {m}', ['-']),
  karussell: T('Karussell', 'carousel', ['-']),
  folieRolle: T('Bild', 'slide', ['-']),
  demoStarten: T('Demo starten', 'Start the demo', ['-']),
  installieren: T('Installieren', 'Install', ['-']),
  ueber: T('Über das Projekt und Datenschutz', 'About the project and privacy', ['-']),
  impressum: T('Impressum', 'Legal notice', ['-']),
  start: T('Zur Startseite', 'To the home page', ['-']),
  // Knopf an jeder Animation (seite.js)
  anhalten: T('Anhalten', 'Pause', ['-']),
  abspielen: T('Abspielen', 'Play', ['-']),
};

export const HELD = {
  neu: T('**Neu in 3.13.0:** Einspeisung nach der Sonne, HT und NT als ein Vertrag', '**New in 3.13.0:** feed-in by the sun, peak and off-peak as one contract', ['neu-313']),
  frei: T('Kostenlos, offener Code (AGPL)', 'Free, open source (AGPL)', ['agpl-kostenlos']),
  h1: T('Deine Energiekosten im Griff.', 'Your energy costs under control.', ['wertversprechen']),
  lead: T(
    '**Abschlag, Rechnung, Wechsel und Rechte:** Der Energietracker rechnet wie die Abrechnung und sagt dir rechtzeitig, was ansteht. Selbst gehostet, ohne Konto, deine Daten bleiben bei dir.',
    '**Advance payment, bill, switching and your rights:** Energietracker calculates the way the bill does and tells you in good time what is coming up. Self-hosted, no account, your data stays with you.',
    ['wie-abrechnung', 'kuendigung-erinnerung', 'zutun', 'tagline', 'daten-bleiben']),
  demoNote: T(
    'Die Demo läuft ganz im Browser, mit erfundenen Beispielhaushalten.',
    'The demo runs entirely in your browser, with invented sample households.',
    ['demo-browser', 'bsp-erfunden']),
  bildAlt: T(
    'Übersicht des Beispielhaushalts: Was ist zu tun? Bekomme ich Geld zurück? Mehr oder weniger als im Vorjahr? Darunter eine Kachel je Verbrauchsart mit Trend.',
    'Overview of the sample household: What needs doing? Will I get money back? More or less than last year? Below, one tile per utility with its trend.',
    ['uebersicht']),
  bildAltHandy: T(
    'Dieselbe Übersicht am iPhone, unten die Leiste mit Übersicht, Zu tun, Erfassen, Verbrauch und Mehr.',
    'The same overview on the iPhone, at the bottom the bar with Overview, To do, Capture, Consumption and More.',
    ['handy']),
  bildText: T(
    '**Die Übersicht antwortet zuerst,** am Mac wie am iPhone. Beispielhaushalt, alle Zahlen erfunden.',
    '**The overview answers first,** on the Mac as on the iPhone. Sample household, all figures invented.',
    ['uebersicht', 'handy', 'bsp-erfunden']),
};

/**
 * Neu in 3.13 (Kopf der Seite, Sprung vom Abzeichen): Hauptfunktionen laut CHANGELOG 3.13.0, ehrlich mit dem,
 * was gebaut und noch aus ist. `bilder`: Animationen (anim = Szene in tools/build-animations.mjs).
 */
export const NEU = {
  h2: T('Neu in 3.13', 'New in 3.13', ['-']),
  lead: T(
    '**Gebaut ist die Prognose nach dem Wetter, angezeigt wird nur, was seine Prüfung besteht.** Gemessen an einem Prüfhaushalt mit eigener Physik, gegen vorab festgelegte Schwellen.',
    '**The weather-based forecast is built, and only what passes its check is shown.** Measured on a test household with physics of its own, against thresholds set in advance.',
    ['pruefhaushalt']),
  bilder: [
    { anim: 'rueckfrage',
      alt: T('Animation: Unter Zählerstände wird beim Gaszähler ein Stand ohne Komma eingetippt. Schon unter dem Feld steht ein Hinweis, und beim Speichern fragt die App nach einem Tippfehler: Das wäre ein Vielfaches dessen, was nach dem Wetter seit der letzten Ablesung zu erwarten ist. Mit dem Komma an der richtigen Stelle wird gespeichert.',
        'Animation: under Meter readings a gas reading is typed without its decimal point. A note appears below the field, and on saving the app asks about a typo: that would be many times what the weather since the last reading suggests. With the decimal point in the right place it is saved.',
        ['rueckfrage-wetter']),
      text: T('**Komma vergessen?** Die Rückfrage vergleicht mit dem, was das Wetter seit der letzten Ablesung erwarten lässt, und schlägt beim Heizbeginn nicht mehr falsch an.',
        '**Forgot the decimal point?** The check compares with what the weather since the last reading suggests, and no longer raises a false alarm when the heating starts.',
        ['rueckfrage-wetter']) },
    { anim: 'prognose-strom',
      alt: T('Animation in der Prognose-Werkstatt: beim Strom das Beispiel E-Auto, dann die Gruppe Wärmepumpe (HT/NT) als ein Ziel mit Kosten, Verbrauch und Abschlag. Zuletzt die PV-Einspeisung unter Saldo und Vertrag mit Vergütung, bereits ausgezahlt, noch offen und der erwarteten Auszahlung bei der Abrechnung.',
        'Animation in the forecast workshop: for electricity the electric car example, then the group heat pump (peak/off-peak) as one target with cost, consumption and advance payment. Finally the PV feed-in under Balance and contract with remuneration, already paid out, still outstanding and the expected payout at billing.',
        ['htnt-gruppe', 'auszahlungen']),
      text: T('**HT und NT als ein Vertrag,** dazu die Einspeisung nach der Sonne mit dem, was schon ausgezahlt ist.',
        '**Peak and off-peak as one contract,** plus the feed-in by the sun with what has already been paid out.',
        ['htnt-gruppe', 'einspeisung-sonne', 'auszahlungen']) },
  ],
  liste: [
    { icon: 'art-pv_einspeisung', text: T('**Einspeisung nach der Sonne:** „Erwartet bei Abrechnung“ rechnet mit der Einstrahlung je Tag. Was der Netzbetreiber überwiesen hat, steht als bereits ausgezahlt und noch offen daneben.',
      '**Feed-in by the sun:** “Expected at billing” is calculated with the daily solar radiation. What the grid operator has transferred appears next to it as already paid out and still outstanding.', ['einspeisung-sonne', 'auszahlungen']) },
    { icon: 'art-strom', text: T('**HT und NT als ein Vertrag:** Die Prognose rechnet die Gruppe als Ganzes, jedes Zählwerk zu seinem Preis, mit Grundpreis.',
      '**Peak and off-peak as one contract:** the forecast calculates the group as a whole, each register at its own price, with the standing charge.', ['htnt-gruppe']) },
    { icon: 'auto', text: T('**Neuer Speicher, neue Wallbox:** Die Prognose der Einspeisung zieht die Ladung eines neuen Speichers von den Monaten davor ab. Eine neue Wallbox bekommt ihren ersten Winter aus den Ladevorgängen oder einer genannten Annahme.',
      '**New battery, new wall box:** the feed-in forecast takes the charging of a new battery off the months before it. A new wall box gets its first winter from the charging sessions or from a stated assumption.', ['speicher-wallbox-neu']) },
    { icon: 'abschlag', text: T('**Abschläge zählen wie beim Versorger:** tagesanteilig oder als ganze Rate am Fälligkeitstag, eine Einstellung für alle Verträge.',
      '**Advance payments count as your supplier does:** pro rata by day or as a full payment on the due day, one setting for all contracts.', ['abschlag-rate']) },
    { icon: 'eingang', text: T('**Listen im Posteingang je Eintrag:** Preise, Abschläge und Stände bestätigst, korrigierst oder verwirfst du einzeln. Übernahmen der KI nimmst du im Verlauf zurück.',
      '**Inbox lists entry by entry:** prices, advance payments and readings you confirm, correct or discard one at a time. Changes made by the AI you undo in the history.', ['posteingang-je-eintrag', 'ki-rueckgaengig']) },
    { icon: 'bausteine', text: T('**Für Home Assistant:** Die Integration 1.1.0 bringt den Füllstand von Öl und Pellets, die Prognose einer HT/NT-Gruppe und die Auszahlungen der Einspeisung.',
      '**For Home Assistant:** integration 1.1.0 brings the fill level of oil and pellets, the forecast of a peak/off-peak group and the feed-in payouts.', ['integration-11']) },
  ],
  ehrlich: T(
    '**Noch aus:** Die Wärmepumpe nach dem Wetter und die PV-Prognose sind gebaut, schaffen ihre Schwellen aber noch nicht. Sie rechnen weiter wie bisher, die App sagt es dort, und mit v3.14 kommen sie erneut auf den Prüfstand.',
    '**Still off:** the heat pump by the weather and the PV forecast are built but do not meet their thresholds yet. They keep calculating as before, the app says so there, and with v3.14 they go back on the test bench.',
    ['grenze-wp-wetter']),
  demo: { persona: 'showcase', route: '/forecast', text: T('Neues in der Demo ansehen', 'See what is new in the demo', ['demo-routen']) },
  changelog: { href: (l) => datei(l, 'CHANGELOG.md', 'CHANGELOG.en.md'), text: T('Alles im CHANGELOG', 'Everything in the CHANGELOG', ['-']) },
};

export const SCHRITTE = {
  h2: T('So funktioniert’s', 'How it works', ['-']),
  lead: T('**Drei Schritte, und du weißt, woran du bist.**', '**Three steps, and you know where you stand.**', ['-']),
  liste: [
    { icon: 'eingang', titel: T('Stände oder Rechnung rein', 'Readings or bill in', ['-']), text: T(
      'Von Hand, per CSV, als PDF aus der Versorgerpost oder automatisch aus Home Assistant.',
      'By hand, by CSV, as a PDF from your supplier’s mail or automatically from Home Assistant.',
      ['eingabe-wege']) },
    { icon: 'kolben', titel: T('Der Energietracker rechnet', 'Energietracker calculates', ['-']), text: T(
      'Mit deinem Vertrag auf den Tag genau, mit dem Wetter an deinem Ort und mit einer Prognose bis zur Abrechnung.',
      'With your contract to the day, with the weather where you live and with a forecast up to the bill.',
      ['vertrag-tagesgenau', 'wetter', 'prognose']) },
    { icon: 'glocke', titel: T('Du bekommst Antworten und Erinnerungen', 'You get answers and reminders', ['-']), text: T(
      'Saldo, Fristen, Briefe und Hinweise unter Zu tun, auf Wunsch auch im Kalender und in Home Assistant.',
      'Balance, deadlines, letters and notes under To do, and if you like in your calendar and in Home Assistant.',
      ['zutun', 'kalender', 'ha-notify']) },
  ],
};

/**
 * Bilder-Slider: Jedes Bild beantwortet eine Frage. `bild`: Name unter assets/img/screens.
 * `anim` (seit 3.13): Szene aus tools/build-animations.mjs; dann steht die Animation mit ihrem
 * Rückfallbild da (bei „Bewegung reduzieren“ nur das Bild), mit Knopf zum Anhalten.
 * `video`: { src, poster } mit lokalen Dateien; dann steht ein <video> mit Bedienelementen da, ohne Autostart.
 */
export const SLIDER = {
  h2: T('Antworten auf einen Blick', 'Answers at a glance', ['-']),
  lead: T(
    '**Jedes Bild beantwortet eine Frage.** Aus den Beispielhaushalten, alle Zahlen erfunden.',
    '**Every picture answers a question.** From the sample households, all figures invented.',
    ['bsp-erfunden']),
  reiter: T('Fragen', 'Questions', ['-']),
  demo: T('In der Demo ansehen', 'See it in the demo', ['demo-routen']),
  folien: [
    { id: 'abschlag', anim: 'saldo', persona: 'showcase', route: '/utility/gas?view=balance',
      frage: T('Bekomme ich Geld zurück?', 'Will I get money back?', ['-']),
      text: T('**Die Übersicht antwortet zuerst,** der Saldo je Vertrag rechnet es nach: gemessen bis zur letzten Ablesung, erwartet bei der Abrechnung, mit Spanne und einem Vorschlag für den Abschlag.',
        '**The overview answers first,** and the balance per contract works it out: measured up to the last reading, expected at the bill, with a range and a suggested advance payment.',
        ['uebersicht', 'saldo', 'abschlag']),
      alt: T('Animation im Beispielhaushalt: Die Übersicht beantwortet „Bekomme ich Geld zurück?“ je Verbrauchsart. Dann zeigt Gas unter Saldo und Vertrag den Saldo am Tag der letzten Ablesung, das erwartete Guthaben bei der Abrechnung mit Spanne und die Kurven von Kosten und Abschlägen.',
        'Animation in the sample household: the overview answers “Will I get money back?” per utility. Then gas shows under Balance and contract the balance on the day of the last reading, the expected credit at billing with a range and the curves of cost and advance payments.',
        ['uebersicht', 'saldo']) },
    { id: 'rechnung', bild: 'rechnung', persona: 'showcase', route: '/bill-check',
      frage: T('Stimmt die Rechnung?', 'Is the bill right?', ['-']),
      text: T('**Rechnung prüfen** rechnet die Rechnung des Versorgers Abschnitt für Abschnitt nach und sagt „passt“ oder „prüfen“, mit Gründen.',
        '**Check a bill** recalculates the supplier’s bill section by section and says “fits” or “check”, with reasons.',
        ['rechnung-pruefen']),
      alt: T('Rechnung prüfen für Gas: je Abschnitt Zeitraum, Stände, Tage, m³, Zustandszahl, Brennwert, kWh, Preis, Verbrauchskosten und feste Kosten.',
        'Check a bill for gas: per section period, readings, days, m³, condition factor, calorific value, kWh, price, consumption cost and fixed costs.',
        ['rechnung-pruefen', 'gas-umrechnung']) },
    { id: 'post', anim: 'posteingang', persona: 'showcase', route: '/inbox',
      frage: T('Was steht in der Post vom Versorger?', 'What does the supplier’s letter say?', ['-']),
      text: T('**Der Posteingang** liest die Rechnung als PDF aus, zeigt zu jedem Wert die Belegstelle und markiert, was nicht passt. Übernommen wird erst, wenn du es bestätigst, danach prüft die App die Rechnung gegen deine Daten.',
        '**The inbox** reads the bill as a PDF, shows the passage behind every value and marks what does not fit. Nothing is taken over until you confirm it, then the app checks the bill against your data.',
        ['posteingang', 'posteingang-dienst']),
      alt: T('Animation im Beispielhaushalt: Im Posteingang die Jahresabrechnung Gas öffnen. Ein markiertes Feld passt nicht zur Belegstelle und wird korrigiert, dann „Übernehmen und prüfen“: Die Abrechnung liegt über der eigenen Rechnung.',
        'Animation in the sample household: open the annual gas bill in the inbox. A marked field does not match its passage and is corrected, then “Take over and check”: the bill is above your own calculation.',
        ['posteingang']) },
    { id: 'wechsel', bild: 'wechsel', persona: 'showcase', route: '/tariffs',
      frage: T('Lohnt der Wechsel?', 'Is switching worth it?', ['-']),
      text: T('**Wechsel prüfen** nennt den erwarteten Jahresverbrauch für das Vergleichsportal, den Wechseltermin und den letzten Tag zum Kündigen.',
        '**Tariff switch** gives the expected annual consumption for the comparison site, the switching date and the last day to cancel.',
        ['wechsel']),
      alt: T('Wechsel prüfen: erwarteter Jahresverbrauch zum Kopieren, Wechsel möglich zum Vertragsende und der letzte Tag zum Kündigen.',
        'Tariff switch: expected annual consumption to copy, switching possible at the contract end and the last day to cancel.',
        ['wechsel']) },
    { id: 'zutun', bild: 'zutun', persona: 'showcase', route: '/todo',
      frage: T('Was steht an?', 'What is coming up?', ['-']),
      text: T('**Zu tun** sammelt Fristen, Termine, Empfehlungen, Rechte und Post in einer Liste, als Abo auch im eigenen Kalender.',
        '**To do** gathers deadlines, appointments, recommendations, rights and mail in one list, also as a subscription in your own calendar.',
        ['zutun', 'kalender']),
      alt: T('Zu tun: offene Punkte mit Fristen, Kündigungsterminen, erwarteten Abrechnungen und zuständigen Personen, mit Filtern nach Art.',
        'To do: open items with deadlines, notice dates, expected bills and the people in charge, with filters by kind.',
        ['zutun']) },
    { id: 'wirkung', bild: 'analyse', persona: 'showcase', route: '/analysis',
      frage: T('Hat die Dämmung gewirkt?', 'Did the insulation work?', ['-']),
      text: T('**Die Auswertung** misst die Wirkung einer Sanierung je Gradtag und sagt, ob sie statistisch belegt ist. Mehr verbraucht oder nur kälter? Jeder Monat steht gegen seine Erwartung bei diesem Wetter.',
        '**The analysis** measures the effect of a renovation per degree day and says whether it is statistically supported. Used more, or just colder? Every month stands against its expectation for that weather.',
        ['zaesur', 'wetter']),
      alt: T('Auswertung: Wirkung der Maßnahme mit Verbrauch je Heizgradtag vorher und nachher, darunter die Kurven Verbrauch gegen Heizgradtage und der Jahresvergleich.',
        'Analysis: effect of the measure with consumption per degree day before and after, below the curves of consumption against degree days and the year-on-year comparison.',
        ['zaesur', 'wetter']) },
    { id: 'prognose', bild: 'prognose', persona: 'showcase', route: '/forecast',
      frage: T('Was kostet das nächste Jahr?', 'What will next year cost?', ['-']),
      text: T('**Die Prognose** rechnet bis zu 24 Monate voraus, mit Unsicherheitsband und den Preisen, die dann gelten.',
        '**The forecast** looks up to 24 months ahead, with an uncertainty band and the prices that apply then.',
        ['prognose']),
      alt: T('Prognose: Kosten über das Jahr aufsummiert und Verbrauch Monat für Monat mit Bandbreite, Ausgangsfall und Szenario.',
        'Forecast: costs summed over the year and consumption month by month with a band, base case and scenario.',
        ['prognose']) },
    { id: 'werkstatt', bild: 'werkstatt', persona: 'showcase', route: '/forecast',
      frage: T('Was wäre, wenn?', 'What if?', ['-']),
      text: T('**Die Werkstatt** rechnet Winter, Dämmung, Raumtemperatur, Tarif, PV, Speicher oder einen Heizungstausch mit deinen Daten durch. Jede Annahme steht dabei.',
        '**The workshop** runs winter, insulation, room temperature, tariff, PV, battery or a new heating through your own data. Every assumption is shown.',
        ['werkstatt', 'werkstatt-pv', 'heizungstausch', 'werkstatt-annahmen']),
      alt: T('Prognose-Werkstatt beim Gas, Beispiel kalter Winter: Beispiele zum Anklicken, Stellschrauben für Winter, Sparen und Tarif, daneben das Ergebnis mit Kosten, Verbrauch, Abschlag und Saldo zur Abrechnung.',
        'Forecast workshop for gas, example cold winter: examples to click, controls for winter, saving and tariff, next to them the result with cost, consumption, advance payment and balance at the bill.',
        ['werkstatt']) },
    { id: 'jahr', bild: 'jahresbericht', persona: 'showcase', route: '/report',
      frage: T('Wie war mein Jahr?', 'How was my year?', ['-']),
      text: T('**Der Jahresbericht** fasst das Jahr für Leser ohne Technikhintergrund zusammen: das Wichtigste vorn, als PDF zum Drucken oder Weitergeben.',
        '**The annual report** sums up the year for readers without a technical background: the essentials first, as a PDF to print or pass on.',
        ['jahresbericht']),
      alt: T('Jahresbericht 2025: das Wichtigste mit Kosten, Energieverbrauch und CO₂, darunter auf einen Blick Kosten, Verbrauch und Abrechnung.',
        'Annual report 2025: the essentials with cost, energy consumption and CO₂, below at a glance cost, consumption and bill.',
        ['jahresbericht']) },
    { id: 'kennzahlen', bild: 'kennzahlen', persona: 'showcase', route: '/figures',
      frage: T('Wie effizient ist mein Haus?', 'How efficient is my home?', ['-']),
      text: T('**Kennzahlen:** Effizienz in kWh je m² mit Klasse, die Einordnung gegen Vergleichswerte als Ampel und der Bestand in den Tanks.',
        '**Key figures:** efficiency in kWh per m² with a class, the comparison against reference values as a traffic light and the stock in your tanks.',
        ['kennzahlen']),
      alt: T('Kennzahlen: Effizienz 2025 je Verbrauchsart mit Klasse, Einordnung mit Ampel und die Bestände in Heizöltank und Pelletlager.',
        'Key figures: efficiency 2025 per utility with class, comparison with traffic light and the stock in the oil tank and pellet store.',
        ['kennzahlen']) },
    { id: 'miete', bild: 'miete', persona: 'mieterin', route: '/tenancy',
      frage: T('Reicht die Vorauszahlung?', 'Is the prepayment enough?', ['-']),
      text: T('**Zur Miete:** Vorauszahlung gegen die erwarteten Kosten, mit passender Vorauszahlung je Monat und der Aufteilung der CO₂-Kosten mit dem Vermieter.',
        '**Renting:** prepayment against the expected costs, with a suitable prepayment per month and the split of CO₂ costs with the landlord.',
        ['miete-vorauszahlung', 'co2']),
      alt: T('Mietverhältnis im Beispielhaushalt zur Miete: erwartete Kosten, vorausgezahlt, voraussichtlich ein kleines Guthaben, passende Vorauszahlung je Monat, darunter CO₂-Kosten teilen mit Stufe und Anteil des Vermieters.',
        'Tenancy in the renting sample household: expected costs, prepaid, a small credit likely, suitable prepayment per month, below sharing CO₂ costs with stage and landlord’s share.',
        ['miete-vorauszahlung', 'co2']) },
    { id: 'brief', anim: 'fristen', persona: 'showcase', route: '/todo?art=rights',
      frage: T('Wann muss ich kündigen?', 'When do I have to cancel?', ['-']),
      text: T('**Kosten & Verträge** nennt den letzten Tag zum Kündigen, Zu tun sammelt Fristen und Rechte, und der Brief entsteht als Text und PDF aus festen Bausteinen und deinen Daten. Abschicken tust du ihn selbst.',
        '**Costs & contracts** gives the last day to cancel, To do gathers deadlines and rights, and the letter is made as text and PDF from fixed templates and your data. You send it yourself.',
        ['kuendigung-erinnerung', 'zutun', 'briefe']),
      alt: T('Animation im Beispielhaushalt: Kosten & Verträge mit dem letzten Tag zum Kündigen je Vertrag, dann Zu tun mit dem Filter Rechte und einer möglichen Sonderkündigung nach einer Preiserhöhung, zuletzt der Dialog Brief entwerfen mit Angaben aus Haushalt und Vertrag und der Vorschau des Briefs.',
        'Animation in the sample household: Costs & contracts with the last day to cancel per contract, then To do with the filter Rights and a possible special termination after a price increase, finally the dialog Draft a letter with details from household and contract and the preview of the letter.',
        ['kuendigung-erinnerung', 'rechte-sieben', 'briefe']) },
    { id: 'ki', anim: 'ki', persona: 'showcase', route: '/connections/ai?mcp=value',
      frage: T('Was sagt meine KI?', 'What does my AI say?', ['-']),
      text: T('**Deine KI** fragt im Energietracker nach und bekommt zu jeder Zahl ihre Herkunft: gemessen, eingetragen, verteilt, angenommen oder geschätzt. Ändern will sie etwas nur als Vorschlag, den du in der App bestätigst. Die Demo hat keine KI-Schnittstelle.',
        '**Your AI** asks Energietracker and gets the source of every figure: measured, entered, spread, assumed or estimated. Anything it wants to change is only a proposal that you confirm in the app. The demo has no AI interface.',
        ['ki-herkunft', 'ki-vorschlag', 'demo-ohne-ki']),
      alt: T('Animation: Der Wegweiser „Mit KI lokal“ zeigt den Weg über Open WebUI und Ollama und was du bekommst, darunter zu jeder Zahl ihre Herkunft. Unter Verbindungen › KI-Assistenten wartet ein Vorschlag, unter Zu tun wird er nach einer Rückfrage übernommen.',
        'Animation: the setup guide “With local AI” shows the route through Open WebUI and Ollama and what you get, including the source of every figure. Under Connections › AI assistants a proposal is waiting, under To do it is taken over after a confirmation.',
        ['wegweiser', 'ki-herkunft', 'ki-vorschlag']) },
    { id: 'handy', bild: 'erfassen', persona: 'showcase', route: '/zaehlerstaende', handy: true,
      frage: T('Geht das am Handy?', 'Does it work on my phone?', ['-']),
      text: T('**Am Handy:** Tab-Leiste in der Daumenzone, alle Zähler in einem Durchgang, als App auf dem Home-Bildschirm.',
        '**On your phone:** a tab bar in thumb reach, all meters in one pass, as an app on the home screen.',
        ['handy']),
      alt: T('Erfassen am iPhone: das Blatt mit Zählerstand, Lieferung, Termin und Dokument, unten die Tab-Leiste.',
        'Capture on the iPhone: the sheet with meter reading, delivery, appointment and document, the tab bar at the bottom.',
        ['handy']) },
  ],
};

export const NUTZEN = {
  h2: T('Was du davon hast', 'What you get out of it', ['-']),
  lead: T(
    '**Die finanzielle Seite, ohne Versprechen:** Du siehst deine eigenen Zahlen, keine erfundenen Sparbeträge.',
    '**The financial side, without promises:** you see your own figures, no made-up savings.',
    ['sparen-belegbar']),
  liste: [
    { icon: 'geld', text: T('**Keine Nachzahlung als Überraschung.** Was die Abrechnung bringt, siehst du lange vorher, mit Spanne.',
      '**No back-payment as a surprise.** You see long before what the bill will bring, with a range.', ['saldo']) },
    { icon: 'abschlag', text: T('**Den Abschlag rechtzeitig anpassen.** Ist er deutlich zu hoch, gibt es den Antrag gleich als Brief.',
      '**Adjust the advance payment in time.** If it is far too high, the request comes ready as a letter.', ['abschlag', 'abschlag-brief']) },
    { icon: 'beleg', text: T('**Fehler in der Rechnung finden:** Menge, Preis und feste Kosten nachgerechnet, ein fehlender Bonus fällt auf.',
      '**Find errors in the bill:** quantity, price and fixed costs recalculated, a missing bonus stands out.', ['rechnung-pruefen', 'rechte-sieben']) },
    { icon: 'etikett', text: T('**Den Wechsel durchrechnen,** mit den Preisen ab dem zweiten Jahr und dem letzten Tag zum Kündigen.',
      '**Work through a switch,** with the prices from the second year and the last day to cancel.', ['wechsel']) },
    { icon: 'kalender', text: T('**Keine Frist verpassen:** Kündigung und Sonderkündigung rechtzeitig unter Zu tun und im Kalender.',
      '**Miss no deadline:** notice and special termination in good time under To do and in your calendar.', ['kuendigung-erinnerung', 'rechte-fristen']) },
    { icon: 'schluessel', text: T('**Als Mieter die CO₂-Kosten teilen:** Mit eigener Gastherme rechnet die App den Anteil des Vermieters aus, mit Anschreiben.',
      '**Share the CO₂ costs as a tenant:** with your own gas boiler the app works out the landlord’s share, with a letter.', ['co2']) },
  ],
};

export const ARTEN = {
  h2: T('Für alle Energieträger', 'For every kind of energy', ['-']),
  lead: T(
    '**Neun Verbrauchsarten, eine Rechnung.** In sieben Sprachen: Deutsch, Englisch, Französisch, Italienisch, Spanisch, Portugiesisch und Niederländisch.',
    '**Nine utilities, one way of calculating.** In seven languages: German, English, French, Italian, Spanish, Portuguese and Dutch.',
    ['arten', 'sprachen']),
  liste: [
    { key: 'gas', name: T('Gas', 'Gas', ['arten']), text: T('m³ in kWh nach Stichtagen, Rechnung prüfen, Wetter herausgerechnet.', 'm³ to kWh by effective date, bill check, weather taken out.', ['art-gas']) },
    { key: 'strom', name: T('Strom', 'Electricity', ['arten']), text: T('HT/NT, Wärmepumpe und Wallbox, steuerbare Verbraucher, dynamische Tarife.', 'Peak/off-peak, heat pump and wallbox, controllable devices, dynamic tariffs.', ['art-strom']) },
    { key: 'wasser', name: T('Wasser', 'Water', ['arten']), text: T('Trinkwasser, Schmutzwasser und Niederschlag, Garten- und Warmwasserzähler.', 'Drinking water, waste water and rainwater, garden and hot water meters.', ['art-wasser']) },
    { key: 'fernwaerme', name: T('Fernwärme', 'District heating', ['arten']), text: T('Leistungs- und Messpreis wie auf der Rechnung, Rechnung prüfen.', 'Capacity and metering charge as on the bill, bill check.', ['art-fernwaerme']) },
    { key: 'heizoel', name: T('Heizöl', 'Heating oil', ['arten']), text: T('Tankbuch aus Lieferungen und Peilständen, Bestand über das Jahr.', 'Tank log from deliveries and dipstick readings, stock over the year.', ['art-heizoel']) },
    { key: 'pellets', name: T('Pellets', 'Pellets', ['arten']), text: T('Wie Heizöl, in Kilogramm, die Lieferrechnung ist der Vertrag.', 'Like heating oil, in kilograms, the delivery invoice is the contract.', ['art-pellets']) },
    { key: 'pv_einspeisung', name: T('PV-Einspeisung', 'PV feed-in', ['arten']), text: T('Vergütung je Abrechnungsjahr, Gutschriften des Direktvermarkters.', 'Remuneration per billing year, credits from the direct marketer.', ['art-pv-einspeisung']) },
    { key: 'pv_erzeugung', name: T('PV-Erzeugung', 'PV generation', ['arten']), text: T('Eigenverbrauch, Autarkie, Speicher, Balkonkraftwerk, Amortisation.', 'Self-consumption, self-sufficiency, battery, balcony plant, payback.', ['art-pv-erzeugung']) },
    { key: 'waerme', name: T('Heizwärme', 'Heat', ['arten']), text: T('Verbrauchsinfo des Messdienstes, Warmwasser, Jahresarbeitszahl der Wärmepumpe.', 'Consumption report of the metering service, hot water, heat pump performance factor.', ['art-waerme']) },
  ],
};

/** Anwendungsfälle: Nutzen zuerst, technisch klar. */
export const WEGE = {
  h2: T('Wo stehst du?', 'Where do you stand?', ['-']),
  lead: T(
    '**Such dir deinen Fall aus.** Jeder führt in einen passenden Beispielhaushalt der Demo.',
    '**Pick your case.** Each one leads to a matching sample household in the demo.',
    ['demo-routen']),
  demo: T('Im Beispielhaushalt ansehen', 'See it in the sample household', ['demo-routen']),
  karten: [
    { id: 'haus-wp', icon: 'art-pv_erzeugung', art: 'pv_erzeugung', persona: 'eigenheim-modern', route: '/',
      titel: T('Haus mit Wärmepumpe, PV, Speicher und E-Auto', 'Home with heat pump, PV, battery and electric car', ['-']),
      frage: T('**Was bringt die Sonne, und was kostet die Wärmepumpe?**', '**What does the sun bring, and what does the heat pump cost?**', ['-']),
      punkte: [
        T('Jeder Vertrag zahlt nur seinen Teil, auch mit Wärmepumpe und HT/NT.', 'Every contract pays only its share, also with heat pump and peak/off-peak.', ['messkonzept']),
        T('Eigenverbrauch, Autarkie, Speicher und Jahresarbeitszahl in Zahlen.', 'Self-consumption, self-sufficiency, battery and performance factor in figures.', ['pv', 'jaz']),
        T('Viertelstunden aus Home Assistant, Ladevorgänge aus evcc: Hat das Verschieben etwas gebracht?', 'Quarter hours from Home Assistant, charging sessions from evcc: did shifting pay off?', ['viertelstunden', 'flex', 'evcc']),
      ],
      ehrlich: T('Die Prognose nach dem Wetter für Wärmepumpe und PV ist gebaut, bleibt aber aus, bis sie ihre vorab festgelegte Prüfung besteht. Mit v3.14 wird sie erneut geprüft.',
        'The weather-based forecast for heat pump and PV is built but stays off until it passes its check, set in advance. It will be checked again with v3.14.', ['grenze-wp-wetter']) },
    { id: 'heizen', icon: 'art-gas', art: 'gas', persona: 'eigenheim-klassisch', route: '/',
      titel: T('Haus mit Gas, Öl oder Pellets', 'Home with gas, oil or pellets', ['-']),
      frage: T('**Reicht der Abschlag, und wann ist der Tank leer?**', '**Is the advance payment enough, and when does the tank run dry?**', ['-']),
      punkte: [
        T('Saldo und erwartete Abrechnung, dazu ein Vorschlag für den Abschlag.', 'Balance and expected bill, plus a suggested advance payment.', ['saldo', 'abschlag']),
        T('Mehr verbraucht oder nur kälter? Mit dem Wetter an deinem Ort.', 'Used more, or just colder? With the weather where you live.', ['heizgrenze']),
        T('Tankbuch für Öl und Pellets, Heizungstausch in der Werkstatt.', 'Tank log for oil and pellets, a new heating in the workshop.', ['tank', 'heizungstausch']),
      ] },
    { id: 'fernwaerme', icon: 'art-fernwaerme', art: 'fernwaerme', persona: 'etw-fernwaerme', route: '/',
      titel: T('Wohnung mit Fernwärme', 'Flat with district heating', ['-']),
      frage: T('**Stimmen Grundpreis und Abschlag?**', '**Are the standing charge and advance payment right?**', ['-']),
      punkte: [
        T('Leistungs- und Messpreis so, wie sie auf der Rechnung stehen.', 'Capacity and metering charge as they appear on the bill.', ['art-fernwaerme']),
        T('Rechnung prüfen, Abschnitt für Abschnitt.', 'Check a bill, section by section.', ['rechnung-pruefen']),
        T('Abschlag deutlich zu hoch? Der Antrag kommt als Brief.', 'Advance payment far too high? The request comes as a letter.', ['abschlag-brief']),
      ] },
    { id: 'miete', icon: 'schluessel', persona: 'mieterin', route: '/tenancy',
      titel: T('Zur Miete', 'Renting', ['-']),
      frage: T('**Reicht die Vorauszahlung, und stimmt die Nebenkostenabrechnung?**', '**Is the prepayment enough, and is the service charge statement right?**', ['-']),
      punkte: [
        T('Passende Vorauszahlung je Monat, eine Hilfsrechnung aus deinen Zählern.', 'A suitable prepayment per month, an estimate from your meters.', ['miete-vorauszahlung']),
        T('CO₂-Kosten mit dem Vermieter teilen, mit Anschreiben.', 'Share CO₂ costs with the landlord, with a letter.', ['co2']),
        T('Fristen für Abrechnung und Einwände im Kalender.', 'Deadlines for the statement and objections in your calendar.', ['miete-fristen']),
      ],
      ehrlich: T('Die CO₂-Aufteilung gilt nur in Deutschland und ist keine Rechtsberatung.', 'The CO₂ split applies only in Germany and is not legal advice.', ['co2-de']) },
    { id: 'rechte', icon: 'waage', persona: 'showcase', route: '/todo?art=rights',
      titel: T('Deine Rechte als Kunde', 'Your rights as a customer', ['-']),
      frage: T('**Prüfen, verstehen, Rechte kennen.**', '**Check, understand, know your rights.**', ['rechte-frage']),
      punkte: [
        T('Sieben Prüfungen nach deutschem Recht, etwa Rechnung zu spät oder Bonus fehlt.', 'Seven checks under German law, such as a late bill or a missing bonus.', ['rechte-sieben']),
        T('Jeder Hinweis nennt die Vorschrift mit Quelle und Stand.', 'Every note names the provision with its source and date.', ['rechte-quelle']),
        T('Der passende Brief als PDF, die Frist als Termin.', 'The matching letter as a PDF, the deadline as an appointment.', ['briefe', 'rechte-fristen']),
      ],
      ehrlich: T('Ein Hinweis, keine Rechtsberatung.', 'A note, not legal advice.', ['rechte-kein-rat']) },
    { id: 'ki', icon: 'ki', persona: 'showcase', route: '/connections/ai?mcp=value',
      titel: T('Mit deiner eigenen KI', 'With your own AI', ['-']),
      frage: T('**Frag deine KI, wie es um Strom und Gas steht.**', '**Ask your AI how electricity and gas are doing.**', ['ki-frage']),
      punkte: [
        T('Die KI ist die Oberfläche, der Energietracker die Akte dahinter.', 'The AI is the interface, Energietracker the file behind it.', ['ki-akte']),
        T('Jede Zahl mit Herkunft, dieselben Zahlen wie in der App.', 'Every figure with its basis, the same figures as in the app.', ['ki-herkunft', 'ki-werkzeuge']),
        T('Ab Werk aus; ändern nur nach deiner Bestätigung in der App.', 'Off by default; changes only after you confirm them in the app.', ['ki-aus', 'ki-vorschlag']),
      ],
      ehrlich: T('Geprüft mit Open WebUI und einem lokalen Modell über Ollama, andere Programme experimentell. Die Demo hat keine KI-Schnittstelle.',
        'Tested with Open WebUI and a local model through Ollama, other programs experimental. The demo has no AI interface.', ['ki-geprueft', 'demo-ohne-ki']),
      anleitung: { href: (l) => doc(l, 'anleitungen/ki-assistent.md'), text: T('KI-Assistent anbinden', 'Connecting an AI assistant', ['-']) } },
  ],
};

export const HA = {
  h2: T('Mit Home Assistant', 'With Home Assistant', ['-']),
  lead: T(
    '**Home Assistant misst, der Energietracker rechnet ab.** Verträge, Abschläge und Prognosen kommen dazu, Home Assistant liefert still im Hintergrund die Stände.',
    '**Home Assistant measures, Energietracker settles the bill.** Contracts, advance payments and forecasts are added, while Home Assistant quietly delivers the readings.',
    ['ha-tagline']),
  punkte: [
    { icon: 'stecker', text: T('**Stände kommen von selbst,** täglich oder je Viertelstunde, geschützt mit einem Schlüssel.',
      '**Readings arrive by themselves,** daily or per quarter hour, protected by a key.', ['ha-ingest']) },
    { icon: 'home', text: T('**Als App in Home Assistant OS:** in der Seitenleiste, mit deinen Benutzern und in den Sicherungen von Home Assistant.',
      '**As an app in Home Assistant OS:** in the sidebar, with your users and in Home Assistant backups.', ['ha-app']) },
    { icon: 'bausteine', text: T('**Sensoren ohne eine Zeile YAML:** Zu tun, nächster Termin und je Zähler Saldo, Verbrauch, Prognose und Tage seit Ablesung, dazu Füllstand von Öl und Pellets, die Prognose einer HT/NT-Gruppe und die Auszahlungen der Einspeisung.',
      '**Sensors without a line of YAML:** To do, next appointment and per meter balance, consumption, forecast and days since the reading, plus the fill level of oil and pellets, the forecast of a peak/off-peak group and the feed-in payouts.', ['integration-sensoren', 'integration-11']) },
    { icon: 'glocke', text: T('**Fristen und neue Post** meldet der Energietracker selbst als Benachrichtigung.',
      '**Deadlines and new mail** are sent by Energietracker itself as notifications.', ['ha-notify']) },
  ],
  schritteH3: T('So kommt er in Home Assistant', 'How it gets into Home Assistant', ['-']),
  schritte: [
    T('**App hinzufügen:** das Repository mit einem Klick eintragen, Energietracker installieren und starten.',
      '**Add the app:** add the repository with one click, install and start Energietracker.', ['install-ha']),
    T('**Sensoren dazu:** im Marketplace (ehemals HACS) nach „Energietracker“ suchen und die Integration installieren. In Home Assistant 2026.3 bis 2026.10 heißt der Weg HACS, ebenfalls über die Suche.',
      '**Add sensors:** search for “Energietracker” in the Marketplace (formerly HACS) and install the integration. On Home Assistant 2026.3 to 2026.10 use HACS instead and search the same way.', ['install-integration']),
    T('**Stände schicken:** Unter Verbindungen › Home Assistant stehen die Vorlagen zum Kopieren.',
      '**Send readings:** under Connections › Home Assistant the templates are ready to copy.', ['ha-vorlagen']),
  ],
  app: T('App zu Home Assistant hinzufügen', 'Add the app to Home Assistant', ['install-ha']),
  integration: T('In Home Assistant öffnen', 'Open in Home Assistant', ['install-integration']),
  anleitung: T('Home Assistant anbinden', 'Connecting Home Assistant', ['-']),
  // Das eigene Logo der Integration (brand/ im Repository der Integration), kein Logo Dritter
  logo: T('Logo der Energietracker-Integration', 'Logo of the Energietracker integration', ['integration-logo']),
  logoText: T('Integration für Home Assistant', 'Home Assistant integration', ['-']),
  ehrlich: T(
    '**Noch jung:** Die App ist als experimentell markiert. Die Integration steht in der Standardliste des Marketplace und braucht den Energietracker ab 3.9.0 und Home Assistant ab 2026.3.',
    '**Still young:** the app is marked experimental. The integration is in the default list of the Marketplace and needs Energietracker 3.9.0 or later and Home Assistant 2026.3 or later.',
    ['ha-experimentell', 'standardliste', 'integration-voraussetzung']),
  bildAlt: T(
    'Schaubild Mit Home Assistant: Smarte Zähler, Home Assistant liest sie aus und schickt Stände täglich oder je Viertelstunde an den Energietracker, zurück gehen Sensoren, Hinweise und Termine. evcc liefert Ladevorgänge. Außerhalb steht nur der Wetterdienst mit gerundetem Standort, abschaltbar.',
    'Diagram With Home Assistant: smart meters, Home Assistant reads them and sends readings daily or per quarter hour to Energietracker, sensors, hints and appointments go back. evcc delivers charging sessions. Outside there is only the weather service with a rounded location, which can be switched off.',
    ['ha-ingest', 'integration-sensoren', 'daten-aussen']),
  bildText: T('Aus dem Wegweiser der App, der sechs Wege zeigt, mit dem Energietracker zu arbeiten.', 'From the app’s setup guide, which shows six ways to work with Energietracker.', ['wegweiser']),
};

export const VERTRAUEN = {
  h2: T('Warum du dem Energietracker trauen kannst', 'Why you can trust Energietracker', ['-']),
  lead: T(
    '**Du musst nichts glauben, du kannst alles nachprüfen.** Hier steht, was die App tut, was sie nicht tut und wo es belegt ist.',
    '**You do not have to believe anything, you can check it all.** Here is what the app does, what it does not do and where that is documented.',
    ['-']),
  bildAlt: T(
    'Schaubild Dein Zuhause: Home Assistant, Versorgerpost, eine KI im Haus und du sprechen mit dem Energietracker. Außerhalb stehen nur ein Cloud-Dienst, experimentell und nur wenn ausdrücklich eingeschaltet, und der Wetterdienst mit gerundetem Standort, abschaltbar.',
    'Diagram Your home: Home Assistant, supplier mail, an AI at home and you talk to Energietracker. Outside there are only a cloud service, experimental and only when expressly switched on, and the weather service with a rounded location, which can be switched off.',
    ['daten-aussen']),
  bildText: T(
    '**Was ins Haus gehört, bleibt drin.** Hinaus geht ab Werk nur der Wetterabgleich, abschaltbar. Alles andere schaltest du selbst ein.',
    '**What belongs at home stays there.** By default only the weather sync goes out, and it can be switched off. Everything else you switch on yourself.',
    ['daten-aussen']),
  belege: [
    { icon: 'schloss', text: T('**Deine Daten bleiben bei dir.** Keine Konten, keine Werbung, keine Telemetrie, keine Datenbank, nur JSON-Dateien auf deinem Server.',
      '**Your data stays with you.** No accounts, no ads, no telemetry, no database, just JSON files on your server.', ['daten-bleiben']),
      beleg: { href: (l) => datei(l, 'README.de.md#deine-daten', 'README.md#your-data'), text: T('README, Deine Daten', 'README, Your data', ['-']) } },
    { icon: 'buch', text: T('**Offener Code unter AGPL.** Zu Hause gehört er dir. Unter Einstellungen › System führt ein Link zum Quellcode genau der laufenden Version.',
      '**Open source under the AGPL.** At home it is yours. Under Settings › System a link leads to the source code of exactly the running version.', ['agpl', 'quellcode-laufend']),
      beleg: { href: () => `${REPO}/blob/main/LICENSE`, text: T('Lizenz', 'Licence', ['-']) } },
    { icon: 'schild', text: T('**Signierte Images.** Seit 3.10.0 ist jedes Image signiert, ohne Schlüssel und im öffentlichen Transparenzprotokoll, dazu mit Stückliste und Herkunftsnachweis.',
      '**Signed images.** Since 3.10.0 every image is signed, keyless and in the public transparency log, and carries a bill of materials and provenance.', ['signiert', 'sbom']),
      beleg: { href: () => `${REPO}/blob/main/SECURITY.md`, text: T('Das Image prüfen', 'Checking the image', ['-']) } },
    { icon: 'puls', text: T('**Sicherheit mit Fristen.** Jeden Dienstag wird das neueste Release neu gebaut und auf bekannte Lücken geprüft. Lücken meldest du vertraulich, die Bestätigung kommt binnen 7 Tagen.',
      '**Security with deadlines.** Every Tuesday the latest release is rebuilt and scanned for known vulnerabilities. You report issues confidentially and get an acknowledgement within 7 days.', ['neubau', 'security-melden']),
      beleg: { href: () => `${REPO}/blob/main/SECURITY.md`, text: T('Sicherheitsrichtlinie', 'Security policy', ['-']) } },
    { icon: 'waage', text: T('**Rechtsquellen mit Stand.** Jede Vorschrift mit Link, dem Tag, an dem sie gelesen wurde, und wie sicher die Aussage ist. Unsichere Regeln nennt die App nie.',
      '**Legal sources with a date.** Every provision with a link, the day it was read and how certain the statement is. The app never states uncertain rules.', ['rechtsquellen']),
      beleg: { href: (l) => doc(l, 'referenz/rechtsgrundlagen.md'), text: T('Rechtsgrundlagen', 'Legal basis', ['-']) } },
    { icon: 'erfolg', text: T('**3.163 Testmethoden in 3.13.0.** Jede neue Regel hat eine Gegenprobe, die sie bricht. Rechenwege sind von Hand nachgerechnet, jede Zahl im README hängt an einem Test.',
      '**3,163 test methods in 3.13.0.** Every new rule has a counter-test that breaks it. Calculations are checked by hand, and every figure in the README is tied to a test.', ['tests']),
      beleg: { href: (l) => datei(l, 'CHANGELOG.md', 'CHANGELOG.en.md'), text: T('CHANGELOG, Tests', 'CHANGELOG, Tests', ['-']) } },
    { icon: 'zahnrad', text: T('**Verlässliche Schnittstellen.** API, CSV-Formate und Backup-Format ändern sich nur additiv. Entfernt wird erst mit einer neuen Hauptversion und nach Ankündigung.',
      '**Dependable interfaces.** APIs, CSV formats and the backup format change only additively. Anything is removed only with a new major version and after notice.', ['stabil']),
      beleg: { href: (l) => datei(l, 'README.de.md#stand', 'README.md#status'), text: T('README, Stand', 'README, Status', ['-']) } },
    { icon: 'kalender', text: T('**Offene Roadmap mit Takt.** Ein Release hat ein Thema und erscheint, wenn es fertig und geprüft ist, in der Regel nach ein bis drei Wochen.',
      '**An open roadmap with a rhythm.** A release has one theme and comes out when it is done and tested, usually after one to three weeks.', ['roadmap-takt']),
      beleg: { href: (l) => datei(l, 'roadmap.md', 'roadmap.en.md'), text: T('Roadmap', 'Roadmap', ['-']) } },
    { icon: 'idee', text: T('**Wünsche werden bewertet.** Vor jedem Release zählen Nutzen für viele, Nähe zur Rechnung, Aufwand und Datenschutz. Abgelehnte Ideen bekommen eine Begründung.',
      '**Ideas are assessed.** Before each release what counts is benefit for many, closeness to the bill, effort and privacy. Declined ideas get a reason.', ['ideen-bewertet']),
      beleg: { href: (l) => datei(l, 'roadmap.md#wünsche-und-fehler-melden', 'roadmap.en.md#suggest-ideas-report-bugs'), text: T('Roadmap, Wünsche', 'Roadmap, Ideas', ['-']) } },
    { icon: 'info', text: T('**Grenzen stehen dabei.** Seit 3.7.0 nennt jedes Release im CHANGELOG unter „Bekannte Grenzen“, was es noch nicht kann. Die Roadmap sagt, was bewusst nicht geplant ist.',
      '**Limits are stated.** Since 3.7.0 every release lists under “Known limits” in the CHANGELOG what it cannot do yet. The roadmap says what is deliberately not planned.', ['grenzen-changelog']),
      beleg: { href: (l) => datei(l, 'CHANGELOG.md', 'CHANGELOG.en.md'), text: T('Bekannte Grenzen', 'Known limits', ['-']) } },
  ],
  aussenH3: T('Was das Haus verlässt', 'What leaves your home', ['-']),
  aussenKopf: [T('Verbindung', 'Connection', ['-']), T('Ab Werk', 'By default', ['-']), T('Was hinausgeht', 'What goes out', ['-'])],
  aussen: [
    [T('Wetterabgleich mit Open-Meteo', 'Weather sync with Open-Meteo', ['aussen-wetter']), T('an, abschaltbar', 'on, can be switched off', ['aussen-wetter']), T('der Standort, auf rund 1 km gerundet, einmal am Tag', 'your location rounded to about 1 km, once a day', ['aussen-wetter'])],
    [T('Ortssuche bei Open-Meteo', 'Place search at Open-Meteo', ['aussen-ort']), T('nur, wenn du sie benutzt', 'only when you use it', ['aussen-ort']), T('der Ort, den du suchst', 'the place you search for', ['aussen-ort'])],
    [T('Hinweis auf neue Versionen', 'Note about new versions', ['aussen-version']), T('aus, du schaltest ihn ein', 'off, you switch it on', ['aussen-version']), T('einmal am Tag die Frage an GitHub nach der neuesten Versionsnummer', 'once a day GitHub is asked for the newest version number', ['aussen-version'])],
    [T('Börsenstrompreise von SMARD', 'Wholesale prices from SMARD', ['aussen-smard']), T('nur auf Knopfdruck', 'only when you tap', ['aussen-smard']), T('nichts von dir', 'nothing of yours', ['aussen-smard'])],
    [T('Texterkennung, evcc, Home Assistant', 'Text recognition, evcc, Home Assistant', ['aussen-heimnetz']), T('nur, wenn du sie einträgst', 'only when you set them up', ['aussen-heimnetz']), T('nur an deine eigenen Dienste im Heimnetz', 'only to your own services in your home network', ['aussen-heimnetz'])],
    [T('Auslesen in der Cloud, experimentell', 'Reading in the cloud, experimental', ['aussen-cloud']), T('aus, nur ein Verwalter schaltet es ein', 'off, only an administrator switches it on', ['aussen-cloud']), T('Dokumente an den gewählten Dienst', 'documents to the chosen service', ['aussen-cloud'])],
    [T('KI-Schnittstelle (MCP)', 'AI interface (MCP)', ['mcp-aus']), T('aus', 'off', ['mcp-aus']), T('nur Antworten auf Anfragen mit eigenem Schlüssel', 'only answers to requests with a key of its own', ['mcp-aus'])],
  ],
  aussenNote: T('Schriften, Chart.js und PDF.js liegen im Repository, zur Laufzeit kommt nichts von einem CDN.',
    'Fonts, Chart.js and PDF.js ship with the repository, nothing is loaded from a CDN at runtime.', ['vendored']),
  grenzenH3: T('Was der Energietracker noch nicht kann', 'What Energietracker cannot do yet', ['-']),
  grenzen: [
    T('**Prognose nach dem Wetter für Wärmepumpe und PV:** gebaut, aber aus, bis sie ihre vorab festgelegte Prüfung besteht. Mit v3.14 wird sie erneut geprüft.',
      '**Weather-based forecast for heat pump and PV:** built, but off until it passes its check, set in advance. It will be checked again with v3.14.', ['grenze-wp-wetter']),
    T('**KI:** geprüft nur mit Open WebUI und Ollama. Ob ein anderes Programm vor einer Übernahme nachfragt, ist nicht geprüft; deshalb bestätigst du ab Werk in der App.',
      '**AI:** tested only with Open WebUI and Ollama. Whether another program asks before applying a change is not tested; that is why by default you confirm in the app.', ['ki-geprueft', 'grenze-ki-nachfrage']),
    T('**Rechte nur nach deutschem Recht.** In anderen Ländern zeigt die App keine Hinweise zu Rechten.',
      '**Rights under German law only.** In other countries the app shows no notes on rights.', ['rechte-nur-de']),
    T('**Fachlich in Deutschland zu Hause:** Für acht weitere Länder setzen Länderprofile Währung, Formate und Wetter. Effizienzklassen gibt es dort nicht, Währungen rechnet die App nicht um.',
      '**At home in Germany in substance:** for eight more countries, profiles set currency, formats and weather. There are no efficiency classes there, and the app does not convert currencies.', ['laender']),
    T('**Home Assistant:** Die App ist als experimentell markiert.',
      '**Home Assistant:** the app is marked experimental.', ['ha-experimentell']),
    T('**iPhone und iPad** sind im Testbrowser mit WebKit geprüft, nicht auf einem echten Gerät.',
      '**iPhone and iPad** are tested in the WebKit test browser, not on a real device.', ['grenze-iphone']),
    T('**Rechtsgrundlagen:** Vor jedem Release liest ein Mensch jede Zeile gegen den geltenden Wortlaut. Für v3.11.0 steht das laut der Seite selbst noch aus.',
      '**Legal basis:** before each release a person reads every line against the wording in force. For v3.11.0 that is still pending, as the page itself says.', ['grenze-gegenlesen']),
  ],
  grenzenLink: { href: (l) => datei(l, 'CHANGELOG.md', 'CHANGELOG.en.md'), text: T('Alle bekannten Grenzen im CHANGELOG', 'All known limits in the CHANGELOG', ['grenzen-changelog']) },
};

export const FRAGEN = {
  h2: T('Häufige Fragen', 'Frequently asked questions', ['-']),
  liste: [
    { frage: T('Brauche ich Home Assistant?', 'Do I need Home Assistant?', ['-']),
      antwort: T('**Nein.** Stände trägst du von Hand ein, per CSV oder aus der Versorgerpost. Home Assistant ist ein Weg von mehreren, und ohne eine einzige Verbindung läuft die App vollständig.',
        '**No.** You enter readings by hand, by CSV or from your supplier’s mail. Home Assistant is one way among several, and the app runs fully without a single connection.',
        ['eingabe-wege', 'verbindungen']) },
    { frage: T('Kostet das etwas?', 'Does it cost anything?', ['-']),
      antwort: T('**Nein.** Nutzen, verändern und weitergeben ist kostenlos, ohne Konto und ohne Gebühr. Ein Kaffee für die Entwicklung ist freiwillig. Nur wer eine KI in der Cloud anbindet, zahlt dort womöglich deren Anbieter.',
        '**No.** Using, changing and passing it on is free, with no account and no fee. A coffee for the development is voluntary. Only if you connect a cloud AI may its provider charge you.',
        ['agpl-kostenlos', 'unterstuetzen', 'ki-cloud-kosten']) },
    { frage: T('Wo liegen meine Daten?', 'Where is my data?', ['-']),
      antwort: T('**Auf deinem eigenen Server,** als JSON-Dateien, mit Backup als Datei. Hinaus geht ab Werk nur der Wetterabgleich, und den kannst du abschalten.',
        '**On your own server,** as JSON files, with a backup as a file. By default only the weather sync goes out, and you can switch it off.',
        ['daten-bleiben', 'backup', 'daten-aussen']) },
    { frage: T('Geht das mit meinem Versorger?', 'Does it work with my supplier?', ['-']),
      antwort: T('**Ja, mit jedem.** Du trägst Vertrag und Preise selbst ein oder übernimmst sie aus der Rechnung. Eine Verbindung zum Versorger braucht es nicht.',
        '**Yes, with any.** You enter contract and prices yourself or take them over from the bill. No connection to the supplier is needed.',
        ['vertrag-eintragen', 'kein-versorger']) },
    { frage: T('Brauche ich eine KI?', 'Do I need an AI?', ['-']),
      antwort: T('**Nein.** Die KI-Schnittstelle ist ab Werk aus, und wer keine KI nutzt, hat keinen Nachteil. Für das Auslesen der Versorgerpost trägst du einen eigenen Dienst ein; eintragen geht immer auch von Hand.',
        '**No.** The AI interface is off by default, and if you do not use AI you lose nothing. To read supplier mail you set up a service of your own; entering by hand always works too.',
        ['ki-aus', 'ki-kein-nachteil', 'posteingang-dienst']) },
    { frage: T('Worauf läuft er?', 'What does it run on?', ['-']),
      antwort: T('**Auf allem, was Docker kann** (amd64 und arm64), als App in Home Assistant OS oder auf einem Webserver mit PHP ab 8.4. Bedient wird er im Browser, am Mac wie am iPhone.',
        '**On anything that runs Docker** (amd64 and arm64), as an app in Home Assistant OS or on a web server with PHP 8.4 or later. You use it in the browser, on the Mac as on the iPhone.',
        ['multiarch', 'ha-app', 'install-web', 'handy']) },
    { frage: T('Gilt das auch außerhalb Deutschlands?', 'Does it work outside Germany?', ['-']),
      antwort: T('**Rechnen ja,** mit Länderprofilen für acht weitere Länder. Effizienzklassen und die Prüfungen deiner Rechte gibt es nur nach deutschem Recht.',
        '**Calculating, yes,** with country profiles for eight more countries. Efficiency classes and the checks of your rights exist only under German law.',
        ['laender', 'rechte-nur-de']) },
  ],
};

export const INSTALL = {
  h2: T('In drei Schritten installiert', 'Installed in three steps', ['-']),
  lead: T(
    '**Wähle den Weg, der zu deinem Zuhause passt.** Überall läuft dasselbe Programm. Das Image gibt es für amd64 und arm64.',
    '**Pick the way that fits your home.** The same program runs everywhere. The image is built for amd64 and arm64.',
    ['image-gleich', 'multiarch']),
  wege: [
    {
      icon: 'bausteine', titel: T('Docker', 'Docker', ['-']),
      fuer: T('auf dem Rechner, dem NAS oder dem Server', 'on your computer, NAS or server', ['install-docker']),
      schritte: [
        T('Container starten:', 'Start the container:', ['install-docker']),
        T('`http://localhost:8080` öffnen.', 'Open `http://localhost:8080`.', ['install-docker']),
        T('**„Mit Beispieldaten ausprobieren“** zeigt sofort, wie alles aussieht; eigene Daten gehen dabei nicht verloren.',
          '**“Try with sample data”** shows right away what everything looks like; your own data is not lost.', ['install-docker']),
      ],
      code: { nach: 0, text: 'docker run -d --name energietracker \\\n  --restart unless-stopped -p 8080:80 \\\n  -v energietracker-data:/data \\\n  ghcr.io/bingerminger/energietracker:3' },
      link: { href: (l) => doc(l, 'betrieb/docker.md'), text: T('Docker-Betrieb', 'Running with Docker', ['-']) },
    },
    {
      icon: 'home', titel: T('Home Assistant OS', 'Home Assistant OS', ['-']),
      fuer: T('als App mit Eintrag in der Seitenleiste', 'as an app with an entry in the sidebar', ['ha-app']),
      schritte: [
        T('[Repository mit einem Klick hinzufügen](' + HA_ADD + ') oder unter Einstellungen › Apps › ⋮ › Repositories die Adresse eintragen:',
          '[Add the repository with one click](' + HA_ADD + ') or enter the address under Settings › Apps › ⋮ › Repositories:', ['install-ha']),
        T('**Energietracker** installieren und starten.', 'Install and start **Energietracker**.', ['install-ha']),
        T('In der Seitenleiste öffnen. Wer ihn zuerst öffnet, verwaltet die Installation.',
          'Open it in the sidebar. The first person to open it administers the installation.', ['install-ha']),
      ],
      code: { nach: 0, text: 'https://github.com/Bingerminger/energietracker-ha' },
      note: T('**Sensoren dazu:** die [Integration](' + INTEGRATION_OPEN + ') aus der Standardliste des Marketplace (ehemals HACS), zu finden über die Suche nach „Energietracker“. In Home Assistant 2026.3 bis 2026.10 heißt der Weg HACS, ebenfalls über die Suche.',
        '**Sensors as well:** the [integration](' + INTEGRATION_OPEN + ') from the default list of the Marketplace (formerly HACS), found by searching for “Energietracker”. On Home Assistant 2026.3 to 2026.10 use HACS instead and search the same way.', ['install-integration', 'standardliste']),
      link: { href: (l) => doc(l, 'anleitungen/home-assistant.md', l === 'en' ? '#as-an-app-in-home-assistant-os' : '#als-app-in-home-assistant-os'), text: T('Als App in Home Assistant OS', 'As an app in Home Assistant OS', ['-']) },
    },
    {
      icon: 'block', titel: T('NAS und Heimserver', 'NAS and home server', ['-']),
      fuer: T('Synology, Unraid, CasaOS oder Umbrel', 'Synology, Unraid, CasaOS or Umbrel', ['nas-vorlagen']),
      schritte: [
        T('Synology: im Container Manager unter Projekt › Erstellen einen Ordner wählen und die `docker-compose.yml` aus der Anleitung einfügen.',
          'Synology: in Container Manager under Project › Create, choose a folder and paste the `docker-compose.yml` from the guide.', ['install-nas']),
        T('Das Projekt starten.', 'Start the project.', ['install-nas']),
        T('`http://<NAS-Adresse>:8080` öffnen.', 'Open `http://<NAS address>:8080`.', ['install-nas']),
      ],
      note: T('Für Unraid, CasaOS und Umbrel liegen Vorlagen bei. Die Vorlage für Unraid ist gegen Format und Image geprüft, auf einem echten Unraid-Server aber nicht ausprobiert.',
        'Templates for Unraid, CasaOS and Umbrel are included. The Unraid template is checked against the format and the image, but not tried on a real Unraid server.', ['nas-vorlagen']),
      link: { href: (l) => doc(l, 'betrieb/docker.md', '#synology-container-manager'), text: T('Synology, Unraid, CasaOS, Umbrel', 'Synology, Unraid, CasaOS, Umbrel', ['-']) },
    },
    {
      icon: 'globus', titel: T('Eigener Webserver', 'Your own web server', ['-']),
      fuer: T('Apache oder nginx mit PHP ab 8.4, ohne Composer', 'Apache or nginx with PHP 8.4 or later, no Composer', ['install-web']),
      schritte: [
        T('Den Code auf den Server holen, etwa mit `git clone`.', 'Get the code onto the server, for example with `git clone`.', ['install-web']),
        T('Apache mit der mitgelieferten `.htaccess` oder nginx mit den Regeln aus `docker/nginx.conf` einrichten.',
          'Set up Apache with the included `.htaccess` or nginx with the rules from `docker/nginx.conf`.', ['install-web']),
        T('Dem Webserver Schreibrecht auf `data/` geben und die Adresse öffnen.', 'Give the web server write access to `data/` and open the address.', ['install-web']),
      ],
      link: { href: (l) => doc(l, 'betrieb/webserver.md'), text: T('Apache und nginx', 'Apache and nginx', ['-']) },
    },
  ],
  achtung: T(
    '**Ohne Anmeldung kann jeder, der die App erreicht, alles lesen und ändern.** Im eigenen Heimnetz ist das in Ordnung. Vor dem Zugriff von außen die Anmeldung einschalten.',
    '**Without sign-in, anyone who can reach the app can read and change everything.** On your own home network that is fine. Before allowing access from outside, switch sign-in on.',
    ['anmeldung']),
  achtungLink: { href: (l) => doc(l, 'betrieb/sicherheit.md'), text: T('Sicherheit und Netzbetrieb', 'Security and network operation', ['-']) },
};

export const VORHABEN = {
  h2: T('Ein Vorhaben, drei Repositories', 'One project, three repositories', ['-']),
  lead: T('**Alles ist öffentlich: Code, Handbuch, Roadmap und jede Änderung.**', '**Everything is public: code, handbook, roadmap and every change.**', ['offen']),
  repos: [
    { icon: 'home', name: T('Energietracker', 'Energietracker', ['-']), slug: 'Bingerminger/energietracker', href: REPO,
      text: T('Die App selbst, mit Handbuch auf Deutsch und Englisch, Roadmap und CHANGELOG. Lizenz AGPL-3.0-or-later.',
        'The app itself, with a handbook in German and English, roadmap and CHANGELOG. Licence AGPL-3.0-or-later.', ['repo-main', 'agpl']) },
    { icon: 'stecker', name: T('App für Home Assistant', 'Home Assistant app', ['-']), slug: 'Bingerminger/energietracker-ha', href: REPO_HA,
      text: T('Bringt den Energietracker als App in Home Assistant OS, mit demselben Image wie Docker. Ihre Version ist die des Energietrackers.',
        'Brings Energietracker to Home Assistant OS as an app, with the same image as Docker. Its version is the Energietracker version.', ['repo-ha']) },
    { icon: 'bausteine', name: T('Integration für Home Assistant', 'Home Assistant integration', ['-']), slug: 'Bingerminger/energietracker-integration', href: REPO_INTEGRATION,
      text: T('Sensoren in Home Assistant ohne YAML, aus dem Marketplace. Sie liest nur die stabilen Schnittstellen, ein neues Release des Energietrackers braucht kein neues der Integration.',
        'Sensors in Home Assistant without YAML, from the Marketplace. It reads only the stable interfaces, so a new Energietracker release needs no new integration release.', ['repo-integration', 'standardliste']) },
  ],
  linksH3: T('Zum Nachlesen', 'To read on', ['-']),
  links: [
    { href: (l) => demo(l), text: T('Demo im Browser', 'Demo in the browser', ['-']) },
    { href: (l) => doc(l, 'README.md'), text: T('Handbuch', 'Handbook', ['-']) },
    { href: () => `${REPO}/releases`, text: T('Releases', 'Releases', ['-']) },
    { href: (l) => datei(l, 'roadmap.md', 'roadmap.en.md'), text: T('Roadmap', 'Roadmap', ['-']) },
    { href: (l) => datei(l, 'CHANGELOG.md', 'CHANGELOG.en.md'), text: T('CHANGELOG', 'CHANGELOG', ['-']) },
    { href: () => `${REPO}/discussions`, text: T('Discussions', 'Discussions', ['-']) },
  ],
};

export const MITMACHEN = {
  h2: T('Mitmachen und unterstützen', 'Get involved and support', ['-']),
  lead: T(
    '**Der Energietracker ist unabhängig: keine Firma dahinter, keine Werbung, kein Cloud-Konto.** Er wird in der Freizeit gepflegt.',
    '**Energietracker is independent: no company behind it, no advertising, no cloud account.** It is maintained in spare time.',
    ['unabhaengig', 'freizeit']),
  liste: [
    { icon: 'stern', href: () => REPO, text: T('**Ein Stern auf GitHub** hilft, genauso wie Fehlermeldungen und Weitersagen.',
      '**A star on GitHub** helps, just like bug reports and telling others.', ['stern']), linkText: T('Zum Projekt auf GitHub', 'To the project on GitHub', ['-']) },
    { icon: 'idee', href: () => `${REPO}/discussions/categories/ideas`, text: T('**Ideen einreichen** unter Discussions › Ideas. Gib bestehenden Ideen ein Daumen hoch, so zeigt sich, was vielen wichtig ist.',
      '**Suggest ideas** under Discussions › Ideas. Give existing ideas a thumbs up, so it shows what matters to many.', ['ideen']), linkText: T('Ideas', 'Ideas', ['-']) },
    { icon: 'achtung', href: () => `${REPO}/issues/new/choose`, text: T('**Fehler melden** als Issue, am besten mit Version, Schritten und einem erfundenen Beispiel statt echter Rechnungen.',
      '**Report bugs** as an issue, ideally with version, steps and an invented example instead of real bills.', ['fehler-melden']), linkText: T('Issue anlegen', 'Open an issue', ['-']) },
    { icon: 'schild', href: () => `${REPO}/security/advisories/new`, text: T('**Sicherheitslücken** bitte nicht öffentlich, sondern vertraulich über GitHub melden.',
      '**Security issues** please not in public, report them confidentially through GitHub.', ['security-melden']), linkText: T('Vertraulich melden', 'Report confidentially', ['-']) },
    { icon: 'globus', href: (l) => datei(l, 'CONTRIBUTING.de.md', 'CONTRIBUTING.md'), text: T('**Übersetzen und mitentwickeln:** Pull Requests sind willkommen. Eine neue Sprache ist vor allem Übersetzung.',
      '**Translate and contribute:** pull requests are welcome. A new language is mostly translation.', ['mitwirken']), linkText: T('Mitwirken', 'Contributing', ['-']) },
    { icon: 'herz', href: () => KAFFEE, text: T('**Unterstützen, ganz freiwillig:** Wenn dir der Energietracker hilft und du etwas zurückgeben möchtest, kannst du einen Kaffee spendieren.',
      '**Support, entirely voluntary:** if Energietracker helps you and you want to give something back, you can buy a coffee.', ['unterstuetzen']), linkText: T('buymeacoffee.com/bingerminger', 'buymeacoffee.com/bingerminger', ['-']) },
  ],
};

export const FUSS = {
  lizenz: T('Der Energietracker ist ein unabhängiges Projekt ohne Gewinnabsicht, unter der GNU AGPL v3.0 oder neuer.',
    'Energietracker is an independent, non-profit project under the GNU AGPL v3.0 or later.', ['ohne-gewinn', 'agpl']),
  seite: T('**Diese Seite lädt nichts von fremden Servern, setzt keine Cookies und zählt keine Besuche.** Ausgeliefert wird sie von GitHub Pages.',
    '**This page loads nothing from other servers, sets no cookies and counts no visits.** It is served by GitHub Pages.', ['seite-selbst']),
  marken: T('Produktnamen wie Home Assistant, HACS, Open WebUI, Ollama, Docker und Synology stehen hier nur, um Funktionen und Installationswege zu beschreiben. Eine Verbindung zu ihren Inhabern besteht nicht.',
    'Product names such as Home Assistant, HACS, Open WebUI, Ollama, Docker and Synology appear here only to describe features and installation paths. There is no connection to their owners.', ['marken']),
  schrift: T('Schrift: DM Sans und DM Mono, SIL Open Font License 1.1.', 'Typeface: DM Sans and DM Mono, SIL Open Font License 1.1.', ['schrift']),
  marke: T('Markenhinweise', 'Trademark notices', ['-']),
  lizenzLink: T('Lizenz', 'Licence', ['-']),
};

/** Seite „Über das Projekt und Datenschutz“ (ueber/ und en/about/) */
export const UEBER = {
  h1: T('Über das Projekt und Datenschutz', 'About the project and privacy', ['-']),
  lead: T('**Der Energietracker ist ein privates Open-Source-Projekt ohne Gewinnabsicht.** Keine Firma, keine Werbung, nichts zu kaufen.',
    '**Energietracker is a private, non-profit open source project.** No company, no advertising, nothing to buy.', ['ohne-gewinn', 'unabhaengig']),
  abschnitte: [
    { h2: T('Wer dahintersteht', 'Who is behind it', ['-']), absaetze: [
      T('Entwickelt und gepflegt wird der Energietracker von **Bingerminger** auf GitHub, in der Freizeit.',
        'Energietracker is developed and maintained by **Bingerminger** on GitHub, in spare time.', ['pseudonym', 'freizeit']),
      T('Der Code steht unter der GNU AGPL v3.0 oder neuer. Jeder kann ihn nutzen, prüfen und weitergeben.',
        'The code is under the GNU AGPL v3.0 or later. Anyone can use, check and pass it on.', ['agpl']),
    ] },
    { h2: T('Kontakt', 'Contact', ['-']), absaetze: [
      T('**Kontakt läuft über GitHub:** Fragen und Ideen in den [Discussions](' + REPO + '/discussions), Fehler als [Issue](' + REPO + '/issues), Sicherheitslücken vertraulich über [Security](' + REPO + '/security/advisories/new).',
        '**Contact goes through GitHub:** questions and ideas in the [Discussions](' + REPO + '/discussions), bugs as an [issue](' + REPO + '/issues), security issues confidentially through [Security](' + REPO + '/security/advisories/new).',
        ['kontakt-github']),
    ] },
    { h2: T('Unterstützen', 'Support', ['-']), absaetze: [
      T('Ein Kaffee über [buymeacoffee.com/bingerminger](' + KAFFEE + ') ist freiwillig. Über Wünsche entscheiden Nutzen für viele, Nähe zur Rechnung, Aufwand und Datenschutz.',
        'A coffee through [buymeacoffee.com/bingerminger](' + KAFFEE + ') is voluntary. Ideas are decided by benefit for many, closeness to the bill, effort and privacy.',
        ['unterstuetzen', 'ideen-bewertet']),
    ] },
    { h2: T('Datenschutz auf dieser Seite', 'Privacy on this page', ['-']), liste: [
      T('**Keine Cookies, kein Tracking, keine Auswertung von Besuchen.**', '**No cookies, no tracking, no analysis of visits.**', ['seite-selbst']),
      T('**Nichts von fremden Servern:** Schriften, Bilder und Skripte liegen auf dieser Seite selbst.', '**Nothing from other servers:** fonts, pictures and scripts are on this site itself.', ['seite-selbst']),
      T('**Im Browser** merkt sich die Seite nur zwei Dinge: deine Wahl der Darstellung und ob du den Hinweis zur Sprache geschlossen hast. Beides bleibt auf deinem Gerät, löschen kannst du es in den Einstellungen deines Browsers.',
        '**In your browser** the page remembers only two things: your choice of appearance and whether you closed the language note. Both stay on your device; you can delete them in your browser settings.', ['seite-speicher']),
      T('**Hoster ist GitHub Pages** (GitHub, Inc.). Laut GitHub wird beim Aufruf einer Seite die IP-Adresse zur Sicherheit protokolliert, auch ohne Anmeldung. [Datenerhebung bei GitHub Pages](' + GH_PAGES_DATA + '), [Datenschutzerklärung von GitHub](' + GH_PRIVACY + ').',
        '**The host is GitHub Pages** (GitHub, Inc.). According to GitHub, the IP address is logged for security when a page is visited, even without signing in. [Data collection on GitHub Pages](' + GH_PAGES_DATA + '), [GitHub privacy statement](' + GH_PRIVACY + ').',
        ['github-pages-log']),
      T('**Links nach außen** führen zu GitHub, zur Demo, zu Home Assistant und zu buymeacoffee. Erst wenn du sie anklickst, gelten dort deren Regeln.',
        '**Links to the outside** lead to GitHub, the demo, Home Assistant and buymeacoffee. Only when you click them do their rules apply.', ['links-aussen']),
      T('**Die Demo** läuft ganz im Browser, mit erfundenen Beispielhaushalten.', '**The demo** runs entirely in your browser, with invented sample households.', ['demo-browser', 'bsp-erfunden']),
      T('**Verantwortlich** für diese Seite ist der Anbieter aus dem [Impressum](#u-impressum).',
        '**Responsible** for this page is the provider named in the [legal notice](#u-impressum).', ['verantwortlich']),
    ] },
    { h2: T('Die App selbst', 'The app itself', ['-']), absaetze: [
      T('Was der Energietracker bei dir zu Hause nach außen spricht, steht im README unter [Deine Daten](' + REPO + '/blob/main/README.de.md#deine-daten): ab Werk nur der Wetterabgleich, abschaltbar.',
        'What Energietracker talks to from your home is listed in the README under [Your data](' + REPO + '#your-data): by default only the weather sync, which can be switched off.',
        ['daten-aussen']),
    ] },
    { h2: T('Impressum', 'Legal notice', ['-']), absaetze: [
      T('Angaben nach § 18 Abs. 1 MStV:', 'Information under section 18 (1) of the German Interstate Media Treaty (MStV):', ['impressum']),
    ], anschrift: T('Bingerminger\nc/o Block Services\nStuttgarter Str. 106\n70736 Fellbach',
      'Bingerminger\nc/o Block Services\nStuttgarter Str. 106\n70736 Fellbach\nGermany', ['impressum']),
    danach: [
      T('**Schneller geht es über GitHub**, siehe [Kontakt](#u-kontakt).', '**GitHub is quicker**, see [Contact](#u-kontakt).', ['kontakt-github']),
    ] },
  ],
};
