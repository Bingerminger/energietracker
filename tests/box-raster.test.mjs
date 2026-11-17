// =====================================================================
// v3.12.0 (Designsprache) — kein Flattersatz der Boxen.
//
// Die echte App im echten Browser, Beispieldaten, über Stufe und Breite. In jedem
// Behälter mit nebeneinanderstehenden Boxen gilt:
//   R1 gleiche Höhe je Reihe (±2 px)
//   R2 jede Reihe füllt den Behälter (keine Lücke rechts, ±4 px)
//   S3 jede Infobox ist so breit wie ihr Behälter (±2 px)
// Dazu auf jeder Seite: kein waagerechtes Scrollen, kein Platzhalter wie `{date}` im
// sichtbaren Text, und Ziffern in der Textschrift sind gleich breit (`tnum` in der
// eingebundenen DM Sans).
//
// Grenze: Der Test sieht Boxen, die einander als Geschwister folgen. Unruhige
// Innenabstände bleiben Sichtprüfung.
//
// ERLAUBT nennt, was die heutige Struktur noch nicht hergibt, mit dem Meilenstein,
// der es löst. Ein Eintrag, der nicht mehr gebraucht wird, lässt den Test scheitern.
//
// Browser: Playwright mit Chromium. Port: ET_BOX_PORT (sonst ein freier).
// =====================================================================
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { cpSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const pw = require('playwright');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const ROUTES = ['/dashboard', '/zaehlerstaende', '/inbox', '/utility/gas', '/utility/gas?view=balance', '/utility/gas?view=months', '/utility/gas?view=readings', '/utility/strom', '/utility/wasser', '/utility/wasser?view=balance', '/utility/fernwaerme', '/utility/heizoel', '/utility/heizoel?view=readings', '/utility/pv_einspeisung', '/utility/gas/contracts', '/utility/gas/meters',
  '/contracts', '/contracts/past', '/figures', '/tariffs', '/bill-check', '/analysis', '/forecast', '/flexibility', '/report', '/todo', '/todo?art=rec', '/todo?art=rights', '/todo/dates', '/todo/history', '/todo/history/ai',
  '/settings', '/settings/region', '/settings/display', '/household', '/household/people', '/household/building', '/household/utilities', '/settings/data', '/connections', '/connections/reading', '/connections/homeassistant', '/connections/homeassistant/meters', '/connections/homeassistant/notifications', '/connections/homeassistant/templates', '/connections/weather', '/connections/evcc', '/connections/calendar', '/connections/ai', '/connections/ai/connect', '/connections/keys', '/connections/homenet', '/settings/access',
  '/settings/calculation', '/settings/system', '/help', '/help/setups'];
const LAEUFE = [['expert', 1440], ['expert', 390], ['advanced', 1440], ['beginner', 1440], ['beginner', 390]];

/**
 * Bewusste Ausnahmen: Seite, Regel, Behälter → Meilenstein, der sie auflöst.
 * Schlüssel: `<Route>|<Regel>|<Behälter>`.
 */
const ERLAUBT = {};

/** Läuft im Browser: Befunde auf der aktuellen Seite. */
function pruefe() {
  const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 1 && r.height > 1 && s.display !== 'none' && s.visibility !== 'hidden'; };
  const BOX = '.art-kachel,.card,.kpi,.guide-tile,.rec-card,.contract-overview,.meter-card,.reading-card,.settings-card,.export-tile,.dash-answer,.dash-insight,.empty';
  const view = document.querySelector('#view');
  const parents = new Set();
  for (const box of view.querySelectorAll(BOX)) {
    if (!vis(box)) continue;
    let cell = box;
    while (cell.parentElement && cell.parentElement !== view && [...cell.parentElement.children].filter(vis).length === 1) cell = cell.parentElement;
    if (cell.parentElement) parents.add(cell.parentElement);
  }
  const befunde = [];
  const name = el => (el.className && typeof el.className === 'string' ? el.className.split(' ').slice(0, 2).join('.') : el.tagName);
  for (const p of parents) {
    const cells = [...p.children].filter(c => vis(c) && (c.matches(BOX) || c.querySelector(BOX)) && getComputedStyle(c).position !== 'absolute');
    if (cells.length < 2) continue;
    const rects = cells.map(c => { const box = c.matches(BOX) ? c : c.querySelector(BOX); const r = box.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), left: Math.round(r.left), right: Math.round(r.right), h: Math.round(r.height) }; });
    const rows = [];
    for (const r of rects) { const row = rows.find(x => Math.abs(x.top - r.top) <= 3); if (row) row.items.push(r); else rows.push({ top: r.top, items: [r] }); }
    if (rows.every(x => x.items.length === 1) && rects.every(r => Math.abs(r.right - rects[0].right) < 5 && Math.abs(r.left - rects[0].left) < 5)) continue;   // reine Stapel sind in Ordnung
    const pr = p.getBoundingClientRect();
    const cs = getComputedStyle(p);
    const innerR = pr.right - parseFloat(cs.paddingRight);
    const multi = rows.some(x => x.items.length > 1);
    let hoehe = 0, luecke = 0;
    for (const row of rows) {
      const hs = row.items.map(i => i.h);
      if (Math.max(...hs) - Math.min(...hs) > 2) hoehe++;
      const right = Math.max(...row.items.map(i => i.right));
      if (multi && innerR - right > 4) luecke++;
    }
    if (hoehe) befunde.push(`R1 Höhe|${name(p)}`);
    if (luecke) befunde.push(`R2 Lücke|${name(p)}`);
  }
  // v3.12.0 (Sichtung S3) — jede Infobox füllt ihren Behälter: In derselben Box sind alle gleich breit
  for (const info of view.querySelectorAll('.infobox')) {
    if (!vis(info) || info.closest('.infobox--schwebend')) continue;
    const eltern = info.parentElement, ps = getComputedStyle(eltern);
    if (ps.display.includes('flex') || ps.display.includes('inline')) continue;
    const innen = eltern.getBoundingClientRect().width - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight) - parseFloat(ps.borderLeftWidth) - parseFloat(ps.borderRightWidth);
    if (innen - info.getBoundingClientRect().width > 2) befunde.push(`Infobox schmaler als die Box|${name(eltern)}`);
  }
  const sw = document.documentElement.scrollWidth, w = document.documentElement.clientWidth;
  if (sw > w + 1) befunde.push(`Querscrollen|${sw}>${w}`);
  // v3.12.0 — kein Platzhalter im sichtbaren Text (eine Rückfrage hieß „Ablesung vom {date} prüfen“)
  const roh = (view.innerText || '').match(/\{[A-Za-z_][A-Za-z0-9_]*\}/g);
  if (roh) befunde.push(`Platzhalter im Text|${[...new Set(roh)].join(' ')}`);
  // v3.12.0 (M4) — unter 900 px stapeln Tabellen, nichts wischt in seinem Rahmen quer
  if (innerWidth < 900) {
    for (const wrap of view.querySelectorAll('.table-wrap')) {
      if (vis(wrap) && wrap.scrollWidth > wrap.clientWidth + 1) befunde.push(`Tabelle wischt quer|${wrap.scrollWidth}>${wrap.clientWidth}`);
    }
  }
  return befunde;
}

/**
 * v3.13.0 (Paket K2e) — Läuft im Browser: Knöpfe und Links einer Tabelle stehen im sichtbaren Rahmen (.table-wrap).
 * Über 900 px wischt eine breite Tabelle in ihrem Rahmen quer; bis v3.12 lagen dabei „Rechnung erfassen/prüfen“ und
 * „Ansehen“ der früheren Verträge zwischen 900 und 1280 px rechts außerhalb, ohne Hinweis aufs Wischen, bei Heizöl die
 * Knöpfe einer Lieferung bei 900 px. Geprüft werden die Zeilen; ein Erklär-Knopf im Kopf gehört zu seiner Spalte und
 * wischt mit ihr.
 */
function knoepfeImRahmen() {
  const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 1 && r.height > 1 && s.display !== 'none' && s.visibility !== 'hidden'; };
  const out = [];
  for (const wrap of document.querySelectorAll('#view .table-wrap')) {
    if (!vis(wrap)) continue;
    const w = wrap.getBoundingClientRect();
    const left = w.left + wrap.clientLeft, right = left + wrap.clientWidth;
    for (const b of wrap.querySelectorAll('tbody button, tbody a.btn, tbody a[href], tbody [role="button"]')) {
      if (!vis(b)) continue;
      const r = b.getBoundingClientRect();
      if (r.right > right + 1 || r.left < left - 1) out.push(`Knopf außerhalb des Tabellenrahmens|${(b.textContent || b.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 30)}`);
    }
  }
  return [...new Set(out)];
}

/**
 * v3.13.0 — Breite Tabellen mit Aktionen am Zeilenende: Wischt die Tabelle in ihrem Rahmen quer, bleiben die Knöpfe
 * der letzten Spalte im Rahmen (angeheftet, `.spalte-aktionen`). Ob eine Tabelle bei 900 bis 1280 px wirklich wischt,
 * hängt an Inhalt, Sprache und Schrift; die Prüfung oben fängt das Fehlen der Spalte nur dann. Hier wird die Tabelle
 * deshalb breiter gemacht als ihr Rahmen, und das Ergebnis hängt nicht mehr an den Beispieldaten.
 */
const BREIT = ['/todo/dates', '/contracts/past', '/utility/heizoel?view=readings', '/inbox'];

/** Läuft im Browser: Tabellen mit Knöpfen am Zeilenende, breiter als ihr Rahmen gemacht. */
function knoepfeBeimWischen() {
  const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 1 && r.height > 1 && s.display !== 'none' && s.visibility !== 'hidden'; };
  const out = [];
  let tabellen = 0;
  for (const wrap of document.querySelectorAll('#view .table-wrap')) {
    const table = wrap.querySelector('table');
    const knoepfe = table ? [...table.querySelectorAll('tbody tr > td:last-child')].flatMap(td => [...td.querySelectorAll('button, a.btn, a[href], [role="button"]')]).filter(vis) : [];
    if (!vis(wrap) || !knoepfe.length) continue;
    tabellen++;
    const vorher = table.style.minWidth;
    table.style.minWidth = `${wrap.clientWidth + 300}px`;
    wrap.scrollLeft = 0;
    const w = wrap.getBoundingClientRect();
    const left = w.left + wrap.clientLeft, right = left + wrap.clientWidth;
    if (wrap.scrollWidth <= wrap.clientWidth + 1) out.push('Tabelle wischt trotz Mindestbreite nicht');
    for (const b of knoepfe) {
      const r = b.getBoundingClientRect();
      if (r.right > right + 1 || r.left < left - 1) out.push(`Knopf beim Wischen außerhalb des Rahmens|${(b.getAttribute('aria-label') || b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30)}`);
    }
    table.style.minWidth = vorher;
  }
  return { tabellen, out: [...new Set(out)] };
}

/**
 * v3.13.0 — zur Route und warten, bis ihre Ansicht steht. Ein neuer Anker lädt kein neues Dokument, `networkidle`
 * ist dann sofort erfüllt; es blieben 250 ms. Unter Last (php -S beantwortet eine Anfrage nach der anderen, „Rechte“
 * davor rechnet lange) stand bei der Messung oft noch „Lädt …“ da: keine Tabelle, kein Befund, auch ohne die
 * angeheftete Spalte der Termine. Gewartet wird auf die neue Ansicht (der Router legt je Navigation einen neuen
 * `.view-host` an) ohne `.loading`.
 */
async function gehe(page, route) {
  await page.evaluate(x => { document.querySelector('#view .view-host')?.setAttribute('data-vorher', ''); location.hash = '#' + x; }, route);
  await page.waitForFunction(() => {
    const host = document.querySelector('#view .view-host:not([data-vorher])');
    return !!host && !host.querySelector('.loading');
  }, null, { timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
}

const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.on('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

const port = Number(process.env.ET_BOX_PORT) || await freePort();
// v3.13.0 — der eigene Server oder keiner: Lauscht auf dem Port schon ein anderer (etwa aus einem abgebrochenen
// Lauf), scheitert der eigene still, und die Prüfung liefe gegen fremden Code. So blieb das Fehlen der angehefteten
// Spalte der Termine grün, solange ein Server mit der Spalte auf dem Port lief. Lieber rot. Ein gerade beendeter
// Vorgänger gibt den Port nach einem Augenblick frei (in der Zeitreise teilen sich box-raster, sichtung-layout und
// sichten einen Port): bis zu 10 s warten.
const portFrei = async (p) => {
  for (let i = 0; i < 40; i++) {
    if (!(await fetch(`http://127.0.0.1:${p}/`).then(() => true, () => false))) return true;
    await new Promise(r => setTimeout(r, 250));
  }
  return false;
};
if (!(await portFrei(port))) {
  console.log(`  ✗ Port ${port} ist schon belegt (fremder Server)`);
  process.exit(1);
}
const dataDir = mkdtempSync(join(tmpdir(), 'et-box-'));
cpSync(join(ROOT, 'demo-data'), dataDir, { recursive: true });
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null', ET_AUTH: 'off' }, stdio: 'ignore',
});
const BASE = `http://127.0.0.1:${port}/`;
for (let i = 0; i < 40; i++) {
  const up = await fetch(`${BASE}api.php/api/health`).then(r => r.status < 500).catch(() => false);
  if (up) break;
  await new Promise(r => setTimeout(r, 250));
}
if (proc.exitCode !== null) {
  console.log(`  ✗ eigener Server auf Port ${port} nicht gestartet`);
  rmSync(dataDir, { recursive: true, force: true });
  process.exit(1);
}

const browser = await pw.chromium.launch();
const benutzt = new Set();
let seiten = 0, befunde = 0;
try {
  for (const [level, width] of LAEUFE) {
    const mobile = width < 500;
    const ctx = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1, locale: 'de-DE', colorScheme: 'light', serviceWorkers: 'block' });
    const page = await ctx.newPage();
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.evaluate(async (l) => {
      await fetch('api.php/api/settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ ui_level: l, setup_pending: false }) });
    }, level);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.querySelector('#primary-nav a'), { timeout: 20000 });

    // Gleich breite Ziffern: die Schrift muss sie wirklich haben, nicht nur die Regel im CSS
    if (level === 'expert' && !mobile) {
      const widths = await page.evaluate(async () => {
        await document.fonts.ready;
        const table = document.createElement('table');
        table.className = 'table';
        table.innerHTML = '<tbody><tr><td class="num"><span></span></td></tr></tbody>';
        table.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font-size:40px;width:auto';
        document.body.append(table);
        const probe = table.querySelector('span');
        probe.textContent = '0123456789';
        await document.fonts.load('600 40px "DM Sans Ziffern"', '0123456789');
        const out = [...'0123456789'].map(d => { probe.textContent = d.repeat(10); return probe.getBoundingClientRect().width; });
        const weight = (w) => { probe.style.fontWeight = w; probe.textContent = '1111111111'; return probe.getBoundingClientRect().width; };
        const w400 = weight('400'), w600 = weight('600');
        const family = getComputedStyle(probe).fontFamily;
        table.remove();
        return { out, w400, w600, family, ziffern: document.fonts.check('16px "DM Sans Ziffern"'), text: document.fonts.check('16px "DM Sans"'), bodyFamily: getComputedStyle(document.body).fontFamily };
      });
      ok('Ziffern in Tabellen gleich breit', Math.max(...widths.out) - Math.min(...widths.out) < 0.5, JSON.stringify(widths));
      ok('Ziffern bleiben über die Strichstärke gleich breit', Math.abs(widths.w400 - widths.w600) < 0.5, JSON.stringify(widths));
      ok('Die Textschrift ist DM Sans, die Ziffern sind geladen', /DM Sans/.test(widths.bodyFamily) && /DM Sans Ziffern/.test(widths.family) && widths.ziffern && widths.text, JSON.stringify(widths));
    }

    for (const r of ROUTES) {
      await gehe(page, r);
      await page.waitForTimeout(350);
      seiten++;
      for (const b of await page.evaluate(pruefe)) {
        const key = `${r}|${b}`;
        if (ERLAUBT[key]) { benutzt.add(key); continue; }
        befunde++;
        ok(`${r} (${level}, ${width} px)`, false, b);
      }
    }
    await ctx.close();
  }
  // v3.13.0 (Paket K2e) — zwischen 900 und 1280 px: Knöpfe der Tabellen im sichtbaren Rahmen, auf jeder Seite
  for (const width of [900, 1024, 1180, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, locale: 'de-DE', colorScheme: 'light', serviceWorkers: 'block' });
    const page = await ctx.newPage();
    await page.goto(BASE, { waitUntil: 'domcontentloaded' });
    await page.evaluate(async () => {
      await fetch('api.php/api/settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ ui_level: 'expert', setup_pending: false }) });
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.querySelector('#primary-nav a'), { timeout: 20000 });
    for (const r of ROUTES) {
      await gehe(page, r);
      await page.waitForTimeout(250);
      seiten++;
      for (const b of await page.evaluate(knoepfeImRahmen)) {
        befunde++;
        ok(`${r} (Tabellenrahmen, ${width} px)`, false, b);
      }
      if (BREIT.includes(r)) {
        const { tabellen, out } = await page.evaluate(knoepfeBeimWischen);
        ok(`${r} (beim Wischen, ${width} px): eine Tabelle mit Knöpfen am Zeilenende`, tabellen > 0);
        for (const b of out) {
          befunde++;
          ok(`${r} (beim Wischen, ${width} px)`, false, b);
        }
      }
    }
    await ctx.close();
  }
} finally {
  await browser.close();
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
}
for (const key of Object.keys(ERLAUBT)) ok(`Ausnahme wird noch gebraucht: ${key}`, benutzt.has(key), ERLAUBT[key]);
pass++;   // die Runde selbst
console.log(`\n  box-raster: ${pass} bestanden, ${fail} fehlgeschlagen (${seiten} Seitenläufe, ${befunde} Befunde)`);
process.exit(fail ? 1 : 0);
