// =====================================================================
// v3.12.0 (M6) — Bilder der Doku und des README aus dem Beispielhaushalt (Schaufenster):
// hell am Mac (1440 px) und am iPhone (390 px), fürs README dazu dunkel.
//
//   NODE_PATH=<node_modules mit playwright> node tools/build-screenshots.mjs <basis-url> <de|en> <ordner> [nur,diese,namen] [--leer|--anmeldung]
//
// Gegen einen eigenen Server mit LEEREM Datenverzeichnis (ET_DATA_DIR): Das Skript lädt den
// Beispielhaushalt (force) und stellt die Stufe Experte ein. Keine Nutzerdaten, nur Beispieldaten.
// Ablage und Namen: docs/entwicklung/screenshots.md. Nur Entwicklung, gehört nicht ins Image.
//
// v3.12.0 (Paket bilder) — auch Dialoge, Ausschnitte und die Werkstatt mit Heizöl und PV. Zwei
// Bildgruppen brauchen eine eigene Instanz mit leerem Datenverzeichnis:
//   --leer        Willkommen und Einrichtungsassistent; lädt nichts, schreibt nichts
//   --anmeldung   lädt den Beispielhaushalt, schaltet die Anmeldung mit einem Beispielpasswort ein
//                 (die Instanz ist danach gesperrt) und nimmt Anmeldung und „Zugang und Sicherheit“ auf
// Ein Bild, das Daten ändert (ablesung-pruefen senkt einen Stand der Beispieldaten), stellt sie danach
// wieder her.
//   --dunkel      dieselben Aufnahmen im dunklen Thema als <name>-dunkel.png (für die Projektseite)
// =====================================================================
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
const require = createRequire(join(process.env.NODE_PATH || '/Users/h.schubert/node_modules', 'x'));
const pw = require('playwright');
const FLAGS = process.argv.slice(2).filter(a => a.startsWith('--'));
const [BASE0, LANG = 'de', OUT = '.', ONLY = ''] = process.argv.slice(2).filter(a => !a.startsWith('--'));
const MODE = FLAGS.includes('--leer') ? 'leer' : FLAGS.includes('--anmeldung') ? 'anmeldung' : 'normal';
// --dunkel: dieselben Aufnahmen im dunklen Thema, Name mit Endung „-dunkel“ (für die Projektseite)
const DARK = FLAGS.includes('--dunkel');
const BASE = BASE0.replace(/\/$/, '');
const only = ONLY ? new Set(ONLY.split(',')) : null;
const PASSWORD = 'beispiel-passwort-2026';   // nur für die Wegwerf-Instanz der Aufnahme
mkdirSync(OUT, { recursive: true });
const call = async (method, path, body) => {
  const r = await fetch(`${BASE}/api.php/api${path}`, { method, headers: { 'content-type': 'application/json', 'x-et-language': LANG, 'x-requested-with': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, data: (await r.json().catch(() => ({})))?.data };
};
const api = async (method, path, body) => (await call(method, path, body)).status;
if (MODE !== 'leer') {
  await api('GET', '/settings');
  if (LANG !== 'de') await api('PATCH', '/settings', { language: LANG });
  console.log('Import', await api('POST', '/demo/import', { force: true }));
  console.log('Stufe', await api('PATCH', '/settings', { ui_level: 'expert', weather_auto_fill: false }));
}

// ---- Bausteine für Dialoge und Ausschnitte --------------------------------------------------
const settle = async (page, ms = 800) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
/** Beispiel der Werkstatt anklicken, vorher die Art wählen. */
const preset = (id, utility) => async (page) => {
  if (utility) { await page.selectOption('#util-select', utility); await settle(page, 1500); }
  await page.click(`#ws-presets [data-preset="${id}"]`);
  await settle(page, 2000);
};
/** Rechteck um alle Treffer (Seitenkoordinaten bei Scroll 0), mit Rand, im Fenster gehalten. */
const around = (sel, padX = 16, padY = padX) => async (page) => page.evaluate(([sel, padX, padY]) => {
  window.scrollTo(0, 0);
  const rs = [...document.querySelectorAll(sel)].map(e => e.getBoundingClientRect()).filter(r => r.width && r.height);
  if (!rs.length) throw new Error(`nichts gefunden: ${sel}`);
  const x0 = Math.max(0, Math.min(...rs.map(r => r.left)) - padX), y0 = Math.max(0, Math.min(...rs.map(r => r.top)) - padY);
  const x1 = Math.min(innerWidth, Math.max(...rs.map(r => r.right)) + padX);
  const y1 = Math.min(innerHeight, Math.max(...rs.map(r => r.bottom)) + padY);
  return { x: Math.round(x0), y: Math.round(y0), width: Math.round(x1 - x0), height: Math.round(y1 - y0) };
}, [sel, padX, padY]);
/** Erklärung (ⓘ) zu einem Begriff öffnen: der erste sichtbare Knopf. */
const explain = (id) => async (page) => {
  await page.locator(`.info-btn[data-glossary="${id}"]:visible`).first().click();
  await page.waitForSelector('.info-pop', { state: 'visible' });
  await page.waitForTimeout(500);
};
/** Assistent „Einrichten“ bis zum Schritt mit diesem Feld (Persona vorher gewählt). */
const wizardTo = (field, persona) => async (page) => {
  const next = page.locator('.modal [data-act="next"]');
  await next.click(); await page.waitForTimeout(500);
  if (persona) { await page.locator(`input[name="setup-persona"][value="${persona}"]`).check({ force: true }); await page.waitForTimeout(300); }
  for (let i = 0; i < 6 && !(await page.locator(`input[name="${field}"]`).count()); i++) { await next.click(); await page.waitForTimeout(500); }
  if (!(await page.locator(`input[name="${field}"]`).count())) throw new Error(`Schritt mit ${field} nicht erreicht`);
  await page.mouse.move(0, 0); await page.waitForTimeout(300);
};
/** CSV-Vorschau: Zeilen relativ zur letzten Ablesung des Hauptzählers Gas gebaut, damit jede Art Hinweis vorkommt. */
const csvPreview = async (page) => {
  const list = (await call('GET', '/utility/gas/readings?meter_id=m_gas_main')).data || [];
  const rows = (Array.isArray(list) ? list : list.readings || []).filter(r => r.meter_id === 'm_gas_main' && !r.is_future)
    .sort((a, b) => a.date.localeCompare(b.date));
  const last = rows.at(-1);
  if (!last) throw new Error('keine Ablesung am Hauptzähler Gas');
  const day = (d, n) => { const x = new Date(`${d}T12:00:00Z`); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
  const today = new Date().toISOString().slice(0, 10);
  const gap = Math.max(4, Math.round((Date.parse(today) - Date.parse(last.date)) / 864e5));
  const c = Number(last.counter), n = (v) => v.toFixed(1).replace('.', ',');
  const step = Math.max(1, Math.floor((gap - 1) / 3));
  const d1 = day(last.date, step), d2 = day(last.date, 2 * step), d3 = day(last.date, 3 * step);
  const csv = [
    `${last.date};${String(c).replace('.', ',')};;`,   // unverändert (genau der gespeicherte Stand)
    `${d1};${n(c + 1.8 * step)};;`,                 // neu
    `${d2};${n(c + 1.8 * step - 6)};;`,             // kleiner als die Zeile davor
    `${d3};${n((c + 1.8 * 3 * step) * 10)};;`,      // Tippfehler (eine Stelle zu viel), Datum doppelt …
    `${d3};${n(c + 1.8 * 3 * step)};;`,             // … die letzte Zeile gilt
    `${day(today, 21)};${n(c + 1.8 * (3 * step + 30))};;`,   // in der Zukunft
  ].join('\n') + '\n';
  await page.click('[data-import-readings="m_gas_main"]');
  await page.waitForSelector('#import-csv-input', { state: 'attached' });
  await page.setInputFiles('#import-csv-input', { name: 'gas.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.waitForSelector('#import-result table, #import-result [data-act="import"]');
  await settle(page, 600);
};
const READING = { id: 'r_gas_0028', low: 318, base: 418 };   // ein Stand der Beispieldaten, für das Bild zu niedrig

// [Name, Route, Breite, Höhe, {theme, page (ganze Seite bis Höhe), mobile, act, clip, prep, undo, mode, wait, login}]
const MAC = [1440, 900];
const SHOTS = [
  ['dashboard', '#/dashboard', 1440, 1500, { page: true }],
  // README: docs/assets/promo/screens/uebersicht[-dunkel].<de|en>.png und iphone[-dunkel].<de|en>.png
  ['readme-uebersicht', '#/dashboard', 1440, 1000, {}],
  ['readme-uebersicht-dunkel', '#/dashboard', 1440, 1000, { theme: 'dark' }],
  ['zu-tun', '#/todo', ...MAC, {}],
  ['zaehlerstaende', '#/zaehlerstaende', 1440, 1500, { page: true }],
  ['gas-view', '#/utility/gas', 1440, 1500, { page: true }],
  ['strom-view', '#/utility/strom', 1440, 1500, { page: true }],
  ['heizoel-view', '#/utility/heizoel', 1440, 1500, { page: true }],
  ['pv', '#/utility/pv_einspeisung', 1440, 1280, { page: true }],
  ['navigation-mac', '#/contracts', ...MAC, {}],
  ['fruehere-vertraege', '#/contracts/past', ...MAC, {}],
  ['tarifvergleich', '#/tariffs', 1440, 1500, { page: true }],
  ['kennzahlen', '#/figures', 1440, 1500, { page: true }],
  ['analyse', '#/analysis', 1440, 1500, { page: true }],
  ['empfehlungen', '#/todo?art=rec', 1440, 1500, { page: true }],
  ['termine', '#/todo/dates', 1440, 1500, { page: true }],
  ['erledigt', '#/todo/history', ...MAC, {}],
  ['haushalt', '#/household/meters', ...MAC, {}],
  ['personen', '#/household/people', ...MAC, {}],
  ['verbindungen', '#/connections', ...MAC, {}],
  ['ki-assistenten', '#/connections/ai', ...MAC, {}],
  ['ha-benachrichtigungen', '#/connections/homeassistant/notifications', ...MAC, {}],
  ['einstellungen-heimnetz', '#/connections/homenet', ...MAC, {}],
  ['temperaturen', '#/connections/weather', 1440, 1500, { page: true }],
  ['einstellungen', '#/settings', 1440, 1500, { page: true }],
  ['hilfe', '#/help', ...MAC, {}],
  ['handbuch', LANG === 'de' ? '#/help/doc/anleitungen/anwendungsfaelle.md' : '#/help/doc/en/anleitungen/anwendungsfaelle.md', ...MAC, {}],
  ['navigation-iphone', '#/dashboard', 390, 844, { mobile: true }],
  ['navigation-iphone-dunkel', '#/dashboard', 390, 844, { mobile: true, theme: 'dark' }],
  ['mehr-iphone', '#/dashboard', 390, 844, { mobile: true, act: 'more' }],
  ['erfassen-iphone', '#/dashboard', 390, 844, { mobile: true, act: 'capture' }],

  // ---- Paket bilder: Werkstatt, Bericht, Verträge, Dialoge, Ausschnitte ----
  ['prognose', '#/forecast', 1440, 1500, { page: true, act: preset('cold_winter') }],
  ['werkstatt-heizoel', '#/forecast', 1440, 1440, { page: true, act: preset('heat_pump', 'heizoel') }],
  ['werkstatt-pv', '#/forecast', 1440, 1440, { page: true, act: preset('battery', 'pv_einspeisung') }],
  ['jahresbericht', '#/report/print?year=2025', 1440, 1500, { page: true }],
  ['uebersicht-abrechnungsjahr', '#/dashboard', 1440, 1500, {
    act: async (page) => { await page.click('[data-period-mode="billing"]'); await settle(page, 1800); },
    // Kopf des Bereichs und die erste Reihe Kacheln
    clip: async (page) => page.evaluate(() => {
      window.scrollTo(0, 0);
      const sec = document.querySelector('[data-period-mode]').closest('section');
      const grid = [...sec.children].find(c => c.children.length >= 4);
      const tiles = [...grid.children], top0 = tiles[0].getBoundingClientRect().top;
      const bottom = Math.max(...tiles.filter(t => Math.abs(t.getBoundingClientRect().top - top0) < 2).map(t => t.getBoundingClientRect().bottom));
      const s = sec.getBoundingClientRect();
      return { x: Math.round(s.left - 16), y: Math.round(s.top - 16), width: Math.round(s.width + 32), height: Math.round(bottom - s.top + 32) };
    }),
  }],
  ['zaehler-vertraege', '#/utility/gas/contracts', 1440, 1500, { page: true, act: async (page) => {
    await page.evaluate(() => { const d = document.querySelector('details[data-phase-group="past"]'); if (d) d.open = true; });
    await page.waitForTimeout(400);
  } }],
  ['vertragsdialog', '#/utility/gas/contracts', 1440, 1500, { act: async (page) => {
    await page.click('[data-edit-contract]'); await page.waitForSelector('.modal [data-act="save"]'); await page.waitForTimeout(600);
  } }],
  ['vertragskonflikt', '#/utility/wasser/contracts', ...MAC, {}],
  ['rechte-hinweis', '#/todo?art=rights', ...MAC, {}],
  ['brief-dialog', '#/todo?art=rights', ...MAC, { act: async (page) => {
    await page.click('[data-role="letter-button"]');
    await page.waitForSelector('[data-role="letter-preview"]');
    await page.waitForFunction(() => (document.querySelector('[data-role="letter-preview"]')?.textContent || '').split('\n').length > 5);
    // Angaben zur Zuordnung oben, darunter die Vorschau ab den Nummern bis in den Brieftext
    await page.evaluate(() => {
      document.querySelector('.letter-dialog__refs').scrollIntoView({ block: 'start' });
      const pre = document.querySelector('[data-role="letter-preview"]');
      const lines = pre.textContent.split('\n');
      const at = lines.findIndex(l => /^(Sehr geehrte|Dear)/.test(l));
      pre.scrollTop = Math.max(0, (at - 9) * parseFloat(getComputedStyle(pre).lineHeight));
    });
    await page.waitForTimeout(500);
  } }],
  ['messkonzept', '#/household/meters?art=strom&concept=1', ...MAC, { act: async (page) => {
    await page.waitForSelector('.modal [data-act="next"]'); await page.click('.modal [data-act="next"]'); await page.waitForTimeout(700);
    await page.mouse.move(0, 0);
  } }],
  ['erklaerung-mac', '#/utility/gas?view=months', ...MAC, { act: explain('billingYear'), clip: around('.info-pop, [data-period-mode]', 20, 12) }],
  ['erklaerung-iphone', '#/utility/gas?view=months', 375, 812, { mobile: true, act: explain('billingYear') }],
  ['import-vorschau', '#/household/meters?art=gas', 1440, 1500, { act: csvPreview }],
  ['pruefung-zaehlerstand', '#/zaehlerstaende', ...MAC, { act: async (page) => {
    // Stand mit einer Stelle zu viel: die Rückfrage erscheint vor dem Speichern, gespeichert wird nichts
    const card = page.locator('.reading-card[data-meter-id="m_gas_main"]');
    const prev = (await call('GET', '/utility/gas/readings?meter_id=m_gas_main')).data || [];
    const last = (Array.isArray(prev) ? prev : []).filter(r => r.meter_id === 'm_gas_main' && !r.is_future).sort((a, b) => a.date.localeCompare(b.date)).at(-1);
    await card.locator('[data-role="counter"]').fill(String(Math.round(Number(last?.counter || 2000) * 10)));
    await page.click('[data-action="save-all"]');
    await page.waitForSelector('.modal', { state: 'visible' }); await page.waitForTimeout(500);
  } }],
  ['laenderprofil', '#/settings/region', ...MAC, { act: async (page) => {
    await page.selectOption('#country-select', 'CH');
    await page.waitForSelector('.modal', { state: 'visible' }); await page.waitForTimeout(600);
  } }],
  ['ablesung-pruefen', '#/todo', 1440, 1800, {
    prep: async () => api('PATCH', `/utility/gas/readings/${READING.id}`, { counter: READING.low }),
    undo: async () => api('PATCH', `/utility/gas/readings/${READING.id}`, { counter: READING.base }),
    clip: around('#view li:has([data-role="heat-limit-todo"]), #view li:has([data-role="reading-check-todo"])', 16, 0),
  }],

  // ---- eigene Instanz mit leerem Datenverzeichnis (--leer) ----
  ['einrichtung', '#/dashboard', ...MAC, { mode: 'leer', act: async (page) => {
    await page.waitForSelector('.modal [data-act="next"]'); await page.click('.modal [data-act="next"]'); await page.waitForTimeout(600);
    await page.mouse.move(0, 0);
  } }],
  ['einrichtung-zaehler', '#/dashboard', ...MAC, { mode: 'leer', act: async (page) => {
    await page.waitForSelector('.modal [data-act="next"]'); await wizardTo('setup-meters', 'eigenheim-modern')(page);
  } }],
  ['willkommen', '#/dashboard', ...MAC, { mode: 'leer', act: async (page) => {
    await page.waitForSelector('.modal'); await page.keyboard.press('Escape'); await page.waitForTimeout(600);
  } }],

  // ---- eigene Instanz mit eingeschalteter Anmeldung (--anmeldung) ----
  ['anmeldung', '#/dashboard', ...MAC, { mode: 'anmeldung', wait: '#login-pw' }],
  // Überschrift und die ersten vier Karten (Sicherheits-Check, Anmeldung, Einbetten, Hostnamen)
  ['einstellungen-sicherheit', '#/settings/access', 1440, 1600, { mode: 'anmeldung', login: true, clip: async (page) => page.evaluate(() => {
    window.scrollTo(0, 0);
    const cards = [...document.querySelectorAll('#view .card')].slice(0, 4).map(c => c.getBoundingClientRect());
    const h1 = document.querySelector('#view h1').getBoundingClientRect();
    const x0 = Math.min(...cards.map(r => r.left)) - 16, x1 = Math.max(...cards.map(r => r.right)) + 16;
    const y0 = h1.top - 16, y1 = Math.max(...cards.map(r => r.bottom)) + 16;
    return { x: Math.round(x0), y: Math.round(y0), width: Math.round(x1 - x0), height: Math.round(y1 - y0) };
  }) }],
];

if (MODE === 'anmeldung' && (!only || only.has('anmeldung') || only.has('einstellungen-sicherheit'))) {
  console.log('Anmeldung', await api('POST', '/session/password', { password: PASSWORD }));
}

// Datumsfelder (<input type="date">) formatiert der Browser nach seiner eigenen Sprache, nicht nach `locale`.
// Englisch: die schlanke Headless-Shell folgt `--lang=en-GB` („dd/mm/yyyy“). Deutsch: Die Shell setzt nur die
// Reihenfolge („dd.mm.yyyy“); das volle Chromium folgt am Mac der Systemsprache und schreibt am deutschen Mac
// „tt.mm.jjjj“. Ohne volles Chromium bleibt es bei der Shell.
const launchArgs = { args: [`--lang=${LANG === 'de' ? 'de-DE' : 'en-GB'}`] };
const browser = LANG === 'de'
  ? await pw.chromium.launch({ ...launchArgs, channel: 'chromium' }).catch(() => pw.chromium.launch(launchArgs))
  : await pw.chromium.launch(launchArgs);
const failures = [];
for (const [name, route, w, h, o] of SHOTS) {
  if (only && !only.has(name)) continue;
  if ((o.mode || 'normal') !== MODE) continue;
  const theme = DARK || o.theme === 'dark' ? 'dark' : 'light';
  const ctx = await browser.newContext({
    viewport: { width: w, height: h }, deviceScaleFactor: o.mobile ? 2 : 1, isMobile: !!o.mobile, hasTouch: !!o.mobile,
    locale: LANG === 'de' ? 'de-DE' : 'en-GB', timezoneId: 'Europe/Berlin', serviceWorkers: 'block',
    colorScheme: theme,
  });
  await ctx.addInitScript(([theme, lang]) => {
    try { localStorage.setItem('et-theme', theme); localStorage.setItem('et-language', lang); } catch {}
  }, [theme, LANG]);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e.message || e)));
  try {
    if (o.prep) await o.prep();
    if (o.login) {
      await page.goto(`${BASE}/#/dashboard`, { waitUntil: 'networkidle' });
      await page.fill('#login-pw', PASSWORD);
      await page.press('#login-pw', 'Enter');
      await page.waitForSelector('#login-pw', { state: 'detached' });
    }
    await page.goto(`${BASE}/${route}`, { waitUntil: 'networkidle' });
    if (o.wait) await page.waitForSelector(o.wait, { state: 'visible', timeout: 15000 });
    else await page.waitForFunction(() => !document.querySelector('#view > .loading') && document.querySelector('#view')?.children.length > 0, null, { timeout: 15000 });
    await page.waitForTimeout(1400);   // Diagramme zeichnen sich ein
    if (o.act === 'more') {
      await page.click('#tabbar [data-action="more"]'); await page.waitForTimeout(500);
      // bis zur Fußzeile (Version · Hilfe · Stern · Herz)
      await page.evaluate(() => { for (const el of [document.getElementById('sidebar'), document.getElementById('primary-nav')]) if (el) el.scrollTop = 1e6; });
      await page.waitForTimeout(300);
    }
    if (o.act === 'capture') { await page.click('#tabbar [data-action="capture"]'); await page.waitForTimeout(600); }
    if (typeof o.act === 'function') await o.act(page);
    let clip;
    if (o.page) {
      const full = await page.evaluate(() => document.documentElement.scrollHeight);
      if (full > h) { await page.setViewportSize({ width: w, height: Math.min(full, h) }); }
      clip = { x: 0, y: 0, width: w, height: Math.min(full, h) };
      await page.waitForTimeout(600);
    }
    if (o.clip) clip = await o.clip(page);
    await page.screenshot({ path: `${OUT}/${name}${DARK ? '-dunkel' : ''}.png`, clip });
    if (errors.length) failures.push(`${name}: ${errors.join(' | ')}`);
    console.log('ok', name, clip ? `${clip.width}×${clip.height}` : `${w}×${h}`);
  } catch (e) {
    failures.push(`${name}: ${e.message}`);
    console.log('FEHLER', name, e.message);
  } finally {
    if (o.undo) await o.undo();
  }
  await ctx.close();
}
await browser.close();
console.log(failures.length ? `Seitenfehler:\n${failures.join('\n')}` : 'keine Seitenfehler');
