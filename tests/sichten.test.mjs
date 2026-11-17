// =====================================================================
// v3.12.0 (M4) — Übersicht, Sichten je Art, Zeilen und Frühere Verträge im echten Browser.
//
//  • Kachel: jede Einzelheit steht da (Trend als Grafik, Bezug, Tooltip, Spur, Stand, Sprünge).
//  • Sichten: Chart.js braucht sichtbare Fläche. Diagramme einer verdeckten Sicht werden gezeichnet,
//    wenn sie zu sehen ist; auch nach einer Größenänderung im Verdeckten haben sie Fläche.
//  • Kosten: Knöpfe an der Zeile öffnen den Dialog, ohne Umweg.
//  • Frühere Verträge: Filter, Saldo von Hand nachgerechnet (Sichtung S2).
//
// Browser: Playwright mit Chromium. Port: ET_SICHT_PORT (sonst ein freier).
// =====================================================================
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { cpSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const pw = require('playwright');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
/** Text eines HTML-Schnipsels für den Vergleich: Tags entfernen, bis keiner mehr übrig ist */
const ohneTags = (html) => { let p; do { p = html; html = html.replace(/<[^>]*>/g, ''); } while (html !== p); return html; };
let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.on('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});
const port = Number(process.env.ET_SICHT_PORT) || await freePort();
// v3.13.0 — der eigene Server oder keiner (wie box-raster): Lauscht auf dem Port schon ein anderer, scheitert der
// eigene still, und die Prüfung liefe gegen fremden Code und fremde Daten. Lieber rot.
// Ein gerade beendeter Vorgänger auf demselben Port (Zeitreise: box-raster, sichtung-layout) gibt ihn nach einem
// Augenblick frei: bis zu 10 s warten.
const portBelegt = (p) => fetch(`http://127.0.0.1:${p}/`).then(() => true, () => false);
const portFrei = async (p) => {
  for (let i = 0; i < 40; i++) { if (!(await portBelegt(p))) return true; await new Promise(r => setTimeout(r, 250)); }
  return false;
};
if (!(await portFrei(port))) { console.log(`  ✗ Port ${port} ist schon belegt (fremder Server)`); process.exit(1); }
const dataDir = mkdtempSync(join(tmpdir(), 'et-sicht-'));
cpSync(join(ROOT, 'demo-data'), dataDir, { recursive: true });
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], { cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null', ET_AUTH: 'off' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/`;
for (let i = 0; i < 40; i++) {
  if (await fetch(`${BASE}api.php/api/health`).then(r => r.status < 500).catch(() => false)) break;
  await new Promise(r => setTimeout(r, 250));
}
if (proc.exitCode !== null) { console.log(`  ✗ eigener Server auf Port ${port} nicht gestartet`); rmSync(dataDir, { recursive: true, force: true }); process.exit(1); }
const H = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
await fetch(`${BASE}api.php/api/demo/import`, { method: 'POST', headers: H, body: JSON.stringify({ force: true }) });
await fetch(`${BASE}api.php/api/settings`, { method: 'PATCH', headers: H, body: JSON.stringify({ ui_level: 'expert', setup_pending: false }) });

const browser = await pw.chromium.launch();
const open = async (width = 1440, height = 900) => {
  const mobile = width < 500;
  const ctx = await browser.newContext({ viewport: { width, height }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1, locale: 'de-DE', colorScheme: 'light', serviceWorkers: 'block' });
  const page = await ctx.newPage();
  // v3.12.0 — die Uhr der Seite folgt der dieses Prozesses (in der CI ein fester Tag per libfaketime);
  // erbt Chromium die verstellte Uhr nicht, stellt Playwright sie nach
  if (Math.abs(await page.evaluate(() => Date.now()) - Date.now()) > 60000) await ctx.clock.setSystemTime(Date.now());
  const errs = [];
  page.on('pageerror', e => errs.push(e.message));
  return { ctx, page, errs };
};
const go = async (page, route) => {
  await page.goto(`${BASE}#${route}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => { document.fonts?.ready; });
  await page.waitForTimeout(500);
};
/** Fläche und gezeichnete Bildpunkte eines Canvas: Hat das Diagramm wirklich etwas gemalt? */
const flaeche = (page, sel) => page.evaluate((s) => {
  const c = document.querySelector(s);
  if (!c) return null;
  const r = c.getBoundingClientRect();
  const hasChart = !!window.Chart?.getChart?.(c);
  let painted = 0;
  if (c.width && c.height) {
    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    for (let i = 3; i < d.length; i += 4 * 37) if (d[i] > 0) painted++;
  }
  return { w: Math.round(r.width), h: Math.round(r.height), hasChart, painted };
}, sel);
const drawn = (x) => !!x && x.w > 50 && x.h > 30 && x.hasChart && x.painted > 0;

try {
  // ── Kachel ─────────────────────────────────────────────────────────
  {
    const { ctx, page, errs } = await open();
    await go(page, '/dashboard');
    const gas = page.locator('.art-kachel[data-utility="gas"]');
    ok('Kacheln stehen in vier Spalten', await page.evaluate(() => getComputedStyle(document.querySelector('.art-raster')).gridTemplateColumns.split(' ').length) === 4);
    // Werte stoßen nicht aneinander, auch in schmalen Spalten (iPad hochkant, kleines Fenster)
    for (const breite of [820, 1024, 1280, 1440]) {
      await page.setViewportSize({ width: breite, height: 900 });
      await page.waitForTimeout(200);
      const stoss = await page.evaluate(() => {
        const out = [];
        for (const k of document.querySelectorAll('.art-kachel')) {
          const inner = k.getBoundingClientRect();
          for (const w of k.querySelectorAll('.kpi__value, .kpi__trend')) {
            const r = document.createRange(); r.selectNodeContents(w);
            const b = r.getBoundingClientRect();
            if (b.width && b.right > inner.right + 1) out.push(`${k.dataset.utility}: ${w.textContent.trim()}`);
          }
          const werte = [...k.querySelectorAll('.art-kachel__wert')];
          if (werte.length === 2 && Math.abs(werte[0].getBoundingClientRect().top - werte[1].getBoundingClientRect().top) < 2) {
            const v = werte[0].querySelector('.kpi__value');
            const r = document.createRange(); r.selectNodeContents(v);
            if (r.getBoundingClientRect().right > werte[1].getBoundingClientRect().left) out.push(`${k.dataset.utility}: Werte überlappen`);
          }
        }
        return out;
      });
      ok(`Kachel: Werte passen bei ${breite} px`, stoss.length === 0, stoss.slice(0, 4).join(' | '));
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForTimeout(200);
    ok('Kachel: Kopf führt zur Art, Fuß zu den Zählern', await gas.locator('a.art-kachel__kopf[href="#/utility/gas"]').count() === 1
      && await gas.locator('.art-kachel__fuss a[href="#/household/meters?art=gas"]').count() === 1);
    ok('Kachel: Zeitraum', /^Zeitraum: /.test(await gas.locator('.art-kachel__zeit').textContent()));
    ok('Kachel: Einheit am Wert', /kWh/.test(await gas.locator('.kpi__value').first().textContent()));
    ok('Kachel: Spur als Diagramm', drawn(await flaeche(page, 'canvas[data-spark="gas"]')));
    ok('Kachel: letzte Ablesung im Fuß', /Letzte Ablesung vor/.test(await gas.locator('[data-role="tile-last-reading"]').textContent()));
    ok('Kachel: Füllstand im Fuß', /Tank \d+\s*%, .+ von .+ L/.test(await page.locator('.art-kachel[data-utility="heizoel"] [data-role="tile-stock"]').textContent()));
    ok('Kachel: Lager bei Pellets', /Lager \d+\s*%/.test(await page.locator('.art-kachel[data-utility="pellets"] [data-role="tile-stock"]').textContent()));
    // v3.13.0 — nicht mehr an Gas gebunden: Das Schaufenster wird bis heute fortgeschrieben und wiederholt dabei das Jahr
    // davor. Liegen beide verglichenen Jahre in diesem Teil (etwa ab Anfang 2028), steht bei Gas „etwa gleich“ statt eines
    // Pfeils. Die Grafik prüft die Gas-Kachel in jeder Form, Vorzeichen, Wert und Satz den ersten Pfeil der Übersicht.
    // Grenze: Danach tragen nur Heizöl und Pellets einen Pfeil (±1 %, gemessen bis 29.02.2032); fehlt er einmal, meldet
    // „mindestens ein Pfeil“ das als Fehler.
    const gasTrend = gas.locator('.kpi__trend').first();
    ok('Trend: eigene Grafik statt Zeichen', await gasTrend.count() === 1 && await gasTrend.locator('svg use').count() === 1 && !/[▲▼]/.test(await gasTrend.textContent()));
    const trend = page.locator('.art-kachel .kpi__trend[data-trend]').first();
    const arrows = await trend.count();
    ok('Trend: mindestens ein Pfeil auf der Übersicht', arrows === 1);
    ok('Trend: Pfeil als eigene Grafik', arrows === 1 && await trend.locator('svg use').count() === 1 && !/[▲▼]/.test(await trend.textContent()));
    ok('Trend: Vorzeichen und Wert sichtbar', arrows === 1 && /^[+−]\d+\s%$/.test((await trend.textContent()).trim().replace(/ /g, ' ')), arrows ? await trend.textContent() : 'kein Pfeil');
    ok('Trend: Vergleichszeitraum als Tooltip', arrows === 1 && /gegenüber denselben Monaten ein Jahr zuvor/.test(await trend.getAttribute('title')));
    ok('Trend: Satz für Vorleser mit Richtung', arrows === 1 && /% (mehr|weniger): /.test(await trend.getAttribute('aria-label')));
    ok('Trend: Bezug steht sichtbar daneben', /zum Vorjahr/.test(await gas.locator('.kpi__trend-bezug').first().textContent()));
    const bgOk = await page.evaluate(() => { const up = document.querySelector('.kpi__trend--danger'); const dn = document.querySelector('.kpi__trend--success'); return !up || !dn || getComputedStyle(up).color !== getComputedStyle(dn).color; });
    ok('Trend: Ton nach Richtung', bgOk);
    ok('Trend: ehrlich bei „etwa gleich“: Grafik, Wort und Tooltip', await page.evaluate(() => { const s = document.querySelector('[data-role="trend-same"]'); return !s || (!!s.querySelector('svg use') && /etwa gleich/.test(s.textContent) && !!s.getAttribute('title')); }));
    ok('Gruppen stehen weiter in der Kachel', await page.locator('.art-kachel[data-utility="strom"] details.dash-groups').count() === 1);
    ok('Zahlenreihe führt zu den Kennzahlen', await page.locator('[data-role="numbers"] a.zahl-link[href="#/figures"]').count() >= 2);
    ok('Nur eine Form für Antworten: drei Karten mit Weg zu Zu tun', await page.locator('.dash-answers > .dash-answer').count() >= 3 && await page.locator('a[data-role="todo-all"]').count() === 1);
    // v3.12.0 (m4b) — der Auszug sagt, wie viel noch unter Zu tun steht
    ok('Auszug „Zu tun“: vier Zeilen und „Noch n weitere“', await page.locator('[data-role="todo"] .dash-todo__list > li').count() === 4
      && /^Noch \d+ weitere Einträge unter „Zu tun“\.$/.test((await page.locator('[data-role="todo-more"]').textContent() || '').trim()));
    // Spur und Zähler-Link liegen über der Fläche der Kachel
    const klick = await page.evaluate(() => { const a = document.querySelector('.art-kachel[data-utility="gas"] .art-kachel__fuss a'); a.scrollIntoView({ block: 'center' }); const r = a.getBoundingClientRect(); return document.elementFromPoint(r.left + 2, r.top + 2) === a; });
    ok('Zähler-Link bleibt erreichbar', klick);
    // Bausteine direkt: alle Fälle des Trends, die Beispieldaten gerade nicht zeigen
    const faelle = await page.evaluate(async () => {
      const { trendHtml } = await import('./public/js/components/trend.js');
      const { buildTodo, excerptItems } = await import('./public/js/views/dashboard.js');
      const { heatLimitEffect } = await import('./public/js/lib/heat-limit.js');
      const m = { months: ['2026-01', '2026-02'] };
      const ev = (kind) => ({ due_now: true, kind, href: '#/utility/gas?meter=x', title: 't', params: { label: 'Gas', meter: 'x' }, ref: { utility: 'gas', meter_id: 'x', date: '2026-01-01', counter: 1, best: 10, current: 15 } });
      return {
        mehrSchlecht: trendHtml({ ...m, pct: 5 }),
        mehrGut: trendHtml({ ...m, pct: 5 }, { moreIsGood: true }),
        wenigerGut: trendHtml({ ...m, pct: -5 }),
        wenigerSchlecht: trendHtml({ ...m, pct: -5 }, { moreIsGood: true }),
        gleich: trendHtml({ ...m, pct: 0.3 }),
        knapp: trendHtml({ ...m, pct: 0.6 }),
        keiner: trendHtml(null, { noneTitle: 'Grund' }),
        keinerBloss: trendHtml(null, { bare: true }),
        pruefen: trendHtml({ ...m, pct: 300 }, { checkHref: '#/utility/gas?view=readings' }),
        grossImSatz: trendHtml({ ...m, pct: 300 }, { noCheck: true, bare: true, decimals: 1 }),
        periode: trendHtml({ pct: 7, from: '2026-01-01', until: '2026-03-01', prevFrom: '2025-01-01', prevUntil: '2025-03-01', basis: 'period' }),
        kalender: trendHtml({ pct: 7, from: '2026-01-01', until: '2026-03-01', prevFrom: '2025-01-01', prevUntil: '2025-03-01', basis: 'calendar' }),
        zuPruefen: buildTodo([ev('reading_check')], []).map(x => x.href)[0],
        doppelt: buildTodo([ev('reading_duplicate')], []).map(x => x.href)[0],
        heiz: buildTodo([ev('heat_limit')], []).map(x => x.href)[0],
        wirkung: heatLimitEffect({ best: 10, effect: { until: '2026-12-31', before: { balance: -100 }, after: { balance: 50 } } }, { html: true })[0],
        wirkungText: heatLimitEffect({ best: 10, effect: { until: '2026-12-31', before: { balance: -100 }, after: { balance: 50 } } })[0],
        // v3.12.0 (m4b) — Auszug „Zu tun“: vier überfällige Termine, eine Rechte-Frist, eine Ablesung
        auszug: (() => {
          const rem = (n) => ({ due_now: true, kind: 'reminder', severity: 'overdue', title: `Termin ${n}`, href: '#/todo/dates', ref: { days_until: -n } });
          const rights = { due_now: true, kind: 'rights', severity: 'due', title: 'Frist', href: '#/todo', ref: { deadline: '2026-11-30' } };
          const lesen = { due_now: true, kind: 'reading_due', severity: 'due', href: '#/utility/gas', params: { label: 'Gas', meter: 'x' }, ref: { days_since_reading: 40 } };
          const voll = excerptItems(buildTodo([lesen, rem(1), rem(2), rem(3), rem(4), rights], []));
          const zwei = excerptItems(buildTodo([lesen, rem(1), rights, rem(2)], []));
          return { voll: voll.map(x => x.ev?.kind ?? 'reading_due'), zwei: zwei.map(x => x.ev?.kind ?? 'reading_due') };
        })(),
      };
    });
    ok('Trend: mehr ist schlecht, außer bei Einspeisung und Erzeugung', /kpi__trend--danger/.test(faelle.mehrSchlecht) && /trend-hoch/.test(faelle.mehrSchlecht) && /kpi__trend--success/.test(faelle.mehrGut) && /trend-hoch/.test(faelle.mehrGut));
    ok('Trend: weniger ist gut, außer bei Einspeisung und Erzeugung', /kpi__trend--success/.test(faelle.wenigerGut) && /trend-runter/.test(faelle.wenigerGut) && /kpi__trend--danger/.test(faelle.wenigerSchlecht));
    ok('Trend: unter 0,5 % etwa gleich, ab 0,5 % ein Pfeil', /data-role="trend-same"/.test(faelle.gleich) && /trend-gleich/.test(faelle.gleich) && !/data-trend/.test(faelle.gleich) && /data-trend="up"/.test(faelle.knapp));
    ok('Trend: kein Vergleich sagt es und nennt den Grund', /data-role="trend-none"/.test(faelle.keiner) && /title="Grund"/.test(faelle.keiner) && faelle.keinerBloss === '');
    ok('Trend: stark verändert führt zu den Ablesungen', /data-role="trend-check"/.test(faelle.pruefen) && /href="#\/utility\/gas\?view=readings"/.test(faelle.pruefen) && !/%/.test(faelle.pruefen.replace(/title="[^"]*"|aria-label="[^"]*"/g, '')));
    ok('Trend im Satz: Zahl mit Dezimalstelle, ohne Bezug', /\+300,0/.test(faelle.grossImSatz) && !/kpi__trend-bezug/.test(faelle.grossImSatz));
    ok('Trend: Abrechnungsjahr nennt beide Spannen, #36', /title="[^"]*im Abrechnungsjahr davor/.test(faelle.periode) && /aria-label="[^"]*im Abrechnungsjahr davor/.test(faelle.periode) && /zum Jahr davor/.test(faelle.periode));
    ok('Trend: dieselben Kalendertage, Nachtrag #36', /title="[^"]*denselben Kalendertagen/.test(faelle.kalender) && /aria-label="[^"]*denselben Kalendertagen/.test(faelle.kalender) && /zum Vorjahr/.test(faelle.kalender));
    ok('Zu tun führt in die Sicht der Sache', faelle.zuPruefen === '#/utility/gas?meter=x&view=readings' && faelle.doppelt === '#/utility/gas?meter=x&view=readings' && faelle.heiz === '#/utility/gas?meter=x&view=balance', JSON.stringify([faelle.zuPruefen, faelle.doppelt, faelle.heiz]));
    ok('Heizgrenze: Salden im Satz in Farbe, Wortlaut gleich', /ton-ok/.test(faelle.wirkung) && /ton-fehler/.test(faelle.wirkung)
      && ohneTags(faelle.wirkung).replace(/&nbsp;/g, '\u00a0') === faelle.wirkungText, faelle.wirkung);
    ok('Auszug „Zu tun“: eine Rechte-Frist fällt nie heraus, auch hinter vier überfälligen Terminen', JSON.stringify(faelle.auszug.voll) === '["reminder","reminder","reminder","rights"]', JSON.stringify(faelle.auszug.voll));
    ok('Auszug „Zu tun“: Überfälliges, dann Rechte, dann der Rest', JSON.stringify(faelle.auszug.zwei) === '["reminder","reminder","rights","reading_due"]', JSON.stringify(faelle.auszug.zwei));
    ok('Kachel: keine Seitenfehler', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  // Sechs Arten: 3 × 2, keine breite Kachel am Ende
  {
    const voll = (await (await fetch(`${BASE}api.php/api/settings`)).json()).data.active_utilities;
    await fetch(`${BASE}api.php/api/settings`, { method: 'PATCH', headers: H, body: JSON.stringify({ active_utilities: ['gas', 'strom', 'wasser', 'fernwaerme', 'heizoel', 'pellets'] }) });
    const { ctx, page } = await open();
    await go(page, '/dashboard');
    ok('Sechs Kacheln stehen 3 × 2', await page.evaluate(() => getComputedStyle(document.querySelector('.art-raster')).gridTemplateColumns.split(' ').length) === 3 && await page.locator('.art-raster > .raster-rest').count() === 0);
    await ctx.close();
    await fetch(`${BASE}api.php/api/settings`, { method: 'PATCH', headers: H, body: JSON.stringify({ active_utilities: voll }) });
  }
  // ── Sichten ────────────────────────────────────────────────────────
  {
    const { ctx, page, errs } = await open();
    await go(page, '/utility/gas');
    ok('Überblick: Monatsdiagramm gezeichnet', drawn(await flaeche(page, '#month-chart')));
    ok('Überblick: Saldo noch nicht gezeichnet', !(await flaeche(page, '#balance-chart'))?.hasChart);
    const kurz = await page.evaluate(() => {
      const num = (s) => (s || '').replace(/\s+/g, ' ').trim();
      return { sichtbar: !!document.querySelector('#saldo-kurz')?.offsetParent, wert: num(document.querySelector('[data-role="saldo-kurz-current"] strong')?.textContent), karte: num(document.querySelector('[data-role="balance-current"]')?.textContent),
        erwartet: num(document.querySelector('[data-role="saldo-kurz-expected"] strong')?.textContent), kartePunkt: num(document.querySelector('.balance-verdict__value')?.textContent), farbe: document.querySelector('[data-role="saldo-kurz-current"] strong')?.className };
    });
    ok('Überblick: Saldo kurz mit denselben Zahlen wie die Karte, in Farbe', kurz.sichtbar && kurz.wert === kurz.karte && kurz.erwartet.startsWith(kurz.kartePunkt) && /ton-(ok|fehler)/.test(kurz.farbe), JSON.stringify(kurz));
    await page.click('[data-sicht-goto="balance"]');
    await page.waitForTimeout(400);
    ok('Saldo kurz: Rechenweg führt in die Sicht', await page.locator('[data-sicht-btn="balance"][aria-pressed="true"]').count() === 1 && drawn(await flaeche(page, '#balance-chart')));
    await page.click('[data-sicht-btn="overview"]');
    await page.waitForTimeout(150);
    await page.click('[data-sicht-btn="balance"]');
    await page.waitForTimeout(600);
    ok('Saldo und Vertrag: Diagramm hat Fläche und Inhalt', drawn(await flaeche(page, '#balance-chart')), JSON.stringify(await flaeche(page, '#balance-chart')));
    ok('Adresse nennt die Sicht', (await page.evaluate(() => location.hash)).includes('view=balance'));
    await page.setViewportSize({ width: 1100, height: 900 });
    await page.waitForTimeout(200);
    await page.click('[data-sicht-btn="overview"]');
    await page.waitForTimeout(600);
    const m = await flaeche(page, '#month-chart');
    const box = await page.evaluate(() => Math.round(document.querySelector('#month-chart').parentElement.getBoundingClientRect().width));
    ok('Größenänderung im Verdeckten: Diagramm passt sich an', drawn(m) && Math.abs(m.w - box) <= 2, `${JSON.stringify(m)} / ${box}`);
    await page.setViewportSize({ width: 1440, height: 900 });
    await go(page, '/utility/gas?view=balance');
    ok('Direktsprung in die Sicht', await page.locator('[data-sicht-btn="balance"][aria-pressed="true"]').count() === 1 && await page.locator('#month-chart').isHidden());
    await page.click('[data-sicht-btn="overview"]');
    await page.waitForTimeout(600);
    ok('Direktsprung: Monatsdiagramm zeichnet beim Umschalten', drawn(await flaeche(page, '#month-chart')));
    await page.click('[data-sicht-btn="months"]');
    await page.waitForTimeout(150);
    ok('Monate: Tabelle sichtbar, Diagramm verdeckt', await page.locator('[data-sicht="months"] table').first().isVisible() && await page.locator('#month-chart').isHidden());
    await page.click('[data-sicht-btn="readings"]');
    await page.waitForTimeout(150);
    ok('Ablesungen: Liste und Knopf sichtbar', await page.locator('[data-role="list-card"]').isVisible() && await page.locator('#btn-new-reading').isVisible());
    await page.selectOption('#meter-select', { index: 1 });
    await page.waitForTimeout(700);
    ok('Jahreswahl der Ablesungen: mindestens 120 px', await page.evaluate(() => [...document.querySelectorAll('[data-role="list-year"]')].every(s => s.getBoundingClientRect().width >= 119.5)) && await page.locator('[data-role="list-year"]').count() >= 1);
    ok('Zählerwechsel bleibt in der Sicht', await page.locator('[data-sicht-btn="readings"][aria-pressed="true"]').count() === 1);
    await go(page, '/utility/heizoel');
    ok('Lieferung: keine Sicht „Saldo“, Sicht „Lieferungen“', await page.locator('[data-sicht-btn="balance"]').count() === 0 && /Lieferungen/.test(await page.locator('[data-sicht-btn="readings"]').textContent()));
    ok('Lieferung: Bestandsdiagramm', drawn(await flaeche(page, '#stock-chart')));
    await go(page, '/utility/gas?add=reading');
    ok('Sprung „Ablesung erfassen“ öffnet den Dialog über der Sicht Ablesungen', await page.locator('.modal').count() >= 1 && await page.locator('[data-sicht-btn="readings"][aria-pressed="true"]').count() === 1);
    ok('Sichten: keine Seitenfehler', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  // ── Zeilen unter Kosten ────────────────────────────────────────────
  {
    const { ctx, page, errs } = await open(390, 844);
    await go(page, '/contracts');
    const zeile = page.locator('.contract-overview[data-utility="gas"] .contract-overview__row').first();
    // v3.12.0 (m4b) — ein Knopf je Aufgabe an der Zeile, der Rest im Menü der Zeile; alles in einer Reihe
    const sichtbar = async () => (await zeile.locator('.zeile__aktionen button:visible, .zeile__aktionen a.btn:visible').count());
    ok('Zeile: am iPhone ein Knopf und das Menü, in einer Reihe', await zeile.locator('[data-row-advance]').isVisible() && await zeile.locator('.zeilenmenue__knopf').isVisible()
      && await sichtbar() === 2 && await zeile.evaluate(z => new Set([...z.querySelectorAll('.zeile__aktionen--rechts > .btn, .zeile__aktionen--rechts > .zeilenmenue > .btn')].map(b => Math.round(b.getBoundingClientRect().top))).size === 1), String(await sichtbar()));
    const menue = zeile.locator('.zeilenmenue__knopf');
    await menue.click();
    ok('Menü der Zeile: Bearbeiten, Brief schreiben, Verträge verwalten', await menue.getAttribute('aria-expanded') === 'true' && await zeile.locator('.zeilenmenue__liste [data-row-edit]').isVisible()
      && await zeile.locator('.zeilenmenue__liste [data-role="contract-letter"]').isVisible() && await zeile.locator('.zeilenmenue__liste a[href="#/utility/gas/contracts"]').isVisible());
    ok('Menü der Zeile: im Bild, nichts wischt quer', await page.evaluate(() => { const r = document.querySelector('.zeilenmenue--offen .zeilenmenue__liste').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && document.documentElement.scrollWidth <= innerWidth; }));
    await page.keyboard.press('Escape');
    await page.waitForTimeout(100);
    ok('Menü der Zeile: Escape schließt, Fokus zurück am Knopf', await menue.getAttribute('aria-expanded') === 'false' && await page.evaluate(() => document.activeElement?.classList.contains('zeilenmenue__knopf')));
    await menue.click();
    await zeile.locator('[data-row-edit]').click();
    await page.waitForSelector('.modal #contract-form', { timeout: 6000 });
    ok('Bearbeiten: Vertragsdialog sofort', await page.locator('.modal [name="provider"]').inputValue() !== '');
    await page.locator('.modal [data-act="cancel"]').first().click();
    await page.waitForTimeout(200);
    await zeile.locator('[data-row-advance]').click();
    await page.waitForSelector('.modal #contract-form', { timeout: 6000 });
    await page.waitForTimeout(300);
    ok('Abschlag ändern: Fokus im Feld des Abschlags', await page.evaluate(() => !!document.activeElement?.closest('[data-group="advance_payments"]')));
    await page.locator('.modal [data-act="cancel"]').first().click();
    await page.waitForTimeout(200);
    await menue.click();
    await zeile.locator('[data-role="contract-letter"]').click();
    await page.waitForSelector('.modal', { timeout: 6000 });
    ok('Brief schreiben: Auswahl öffnet', await page.locator('.modal').count() >= 1);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    ok('Einspeisung: kein Knopf „Abschlag ändern“', await page.locator('.contract-overview[data-utility="pv_einspeisung"] [data-row-advance]').count() === 0);
    ok('Zeilen: keine Seitenfehler', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  // ── Frühere Verträge ───────────────────────────────────────────────
  {
    const { ctx, page, errs } = await open();
    await go(page, '/contracts/past');
    const rows = () => page.locator('.past__tabelle tbody tr');
    const n = await rows().count();
    ok('Frühere Verträge: Zeilen', n >= 3, String(n));
    const api = async (p) => (await (await fetch(`${BASE}api.php${p}`)).json()).data;
    const st = await api('/api/utility/gas/meters/m_gas_main/contract-status');
    const past = (st.contracts || []).filter(c => c.phase === 'past');
    ok('Beispieldaten haben beendete Gasverträge', past.length >= 1);
    let handOk = 0;
    for (const c of past) {
      const zeile = page.locator(`tr[data-contract-id="${c.contract_id}"]`);
      const cell = (await zeile.locator('[data-role="past-balance"]').textContent()).replace(/ /g, ' ');
      // Von Hand: Verbraucht − Bezahlt; was zurückkam (+), mindert das Guthaben. Negativ heißt Guthaben (Sichtung S2)
      const hand = c.cost_to_date - c.advance_paid + (c.special_payment_net || 0);
      const zahl = Number(cell.match(/[+−]([\d.]+,\d\d) €/)[1].replace(/\./g, '').replace(',', '.'));
      const plus = /\+/.test(cell.match(/[+−]/)[0]);
      if (Math.abs(zahl - Math.abs(hand)) < 0.011 && plus === (hand < 0) && /Guthaben|Nachzahlung/.test(cell)
        && await zeile.locator('[data-role="past-balance"] strong').evaluate(el => el.classList.contains('ton-ok') || el.classList.contains('ton-fehler'))) handOk++;
    }
    ok('Saldo am Ende von Hand nachgerechnet, in Farbe und mit Wort', handOk === past.length, `${handOk} von ${past.length}`);
    ok('Abgelöst durch steht an der Zeile', await page.locator('.past__tabelle').getByText('abgelöst durch').count() >= 1);
    ok('Jede Zeile: Abschlag, Verbraucht, Bezahlt, Rechnung, Ansehen', await page.evaluate(() => [...document.querySelectorAll('.past__tabelle tbody tr')].every(tr => tr.cells.length === 9 && /€/.test(tr.cells[4].textContent) && /€/.test(tr.cells[5].textContent) && !!tr.querySelector('[data-past-view]'))));
    await page.click('[data-past-art="gas"]');
    await page.waitForTimeout(100);
    ok('Filter Art: genau die beendeten Verträge der Art, kein laufender', await rows().count() === past.length, `${await rows().count()} von ${past.length}`);
    ok('Filter Art', await page.evaluate(() => [...document.querySelectorAll('.past__tabelle tbody tr')].every(tr => tr.dataset.art === 'gas')) && await rows().count() < n);
    await page.click('[data-past-art=""]');
    const jahr = await page.locator('#past-year option').nth(1).getAttribute('value');
    await page.selectOption('#past-year', jahr);
    await page.waitForTimeout(100);
    ok('Filter Jahr', await rows().count() >= 1 && await rows().count() < n);
    await page.selectOption('#past-year', '');
    await page.locator('[data-past-view]').first().click();
    await page.waitForSelector('.modal', { timeout: 6000 });
    ok('Ansehen öffnet den Vertrag', await page.locator('.modal [name="provider"], .modal #water-form, .modal form').count() >= 1);
    await page.keyboard.press('Escape');
    // v3.13.0 — die Zahl am Reiter: dieselben Verträge wie die Tabelle („Alle n“), aus anderen Anfragen gezählt
    const alle = Number((await page.locator('[data-past-art=""] .muted').textContent()).trim());
    await page.waitForFunction(() => document.querySelector('.section-tabs [data-badge="contractsPast"] .sidebar__badge'), null, { timeout: 8000 }).catch(() => {});
    const badge = await page.evaluate(() => { const b = document.querySelector('.section-tabs [data-badge="contractsPast"] .sidebar__badge'); return b ? { n: Number(b.textContent), title: b.getAttribute('title'), cls: b.className } : null; });
    ok('Reiter „Frühere Verträge“ trägt die Zahl der Tabelle, ruhig gefärbt, mit Titel', !!badge && badge.n === alle && alle === n && /sidebar__badge--neutral/.test(badge.cls)
      && new RegExp(`^${alle} früherer? Vertr`).test(badge.title || ''), `${JSON.stringify(badge)} / Tabelle ${alle}`);
    // dieselbe Zahl unter den anderen Reitern von Kosten & Verträge
    await go(page, '/tariffs');
    ok('Zahl am Reiter auch unter „Wechsel prüfen“', Number(await page.locator('.section-tabs [data-badge="contractsPast"] .sidebar__badge').textContent().catch(() => '0')) === alle);
    // „Neuer Vertrag“ im Kopf von Kosten & Verträge: erst die Art, dann der Dialog der Art
    await go(page, '/contracts');
    const kopf = page.locator('.view-header [data-role="new-contract"] .zeilenmenue__knopf');
    ok('Kosten & Verträge: Knopf „Neuer Vertrag“ im Kopf', await kopf.count() === 1 && /Neuer Vertrag/.test(await kopf.textContent()), String(await kopf.count()));
    await kopf.click();
    const arten = await page.locator('.view-header [data-new-contract]').evaluateAll(bs => bs.filter(b => b.getBoundingClientRect().height > 1).map(b => b.dataset.newContract));
    ok('„Neuer Vertrag“ fragt die Verbrauchsart (nur Arten mit Verträgen)', arten.includes('gas') && arten.includes('strom') && !arten.includes('heizoel'), arten.join(', '));
    await page.locator('.view-header [data-new-contract="gas"]').click();
    await page.waitForSelector('.modal', { timeout: 6000 }).catch(() => {});
    ok('„Neuer Vertrag“ → Gas öffnet den leeren Vertragsdialog von Gas', await page.locator('.modal [name="provider"]').count() === 1
      && (await page.locator('.modal [name="provider"]').inputValue()) === '', await page.locator('.modal .modal__title, .modal h2').first().textContent().catch(() => 'kein Dialog'));
    await page.keyboard.press('Escape');
    ok('Frühere Verträge: keine Seitenfehler', errs.length === 0, errs.join(' | '));
    await ctx.close();
    const m = await open(390, 844);
    await go(m.page, '/contracts/past');
    ok('iPhone: gestapelt mit Beschriftung, kein Querwischen', await m.page.evaluate(() => {
      const t = document.querySelector('.past__tabelle');
      const w = t.closest('.table-wrap');
      return getComputedStyle(t.tBodies[0].rows[0]).display === 'block' && t.tBodies[0].rows[0].cells[3].dataset.label !== '' && w.scrollWidth <= w.clientWidth + 1;
    }));
    await m.ctx.close();
    const m2 = await open(390, 844);
    await go(m2.page, '/dashboard');
    ok('iPhone: Kacheln zweispaltig', await m2.page.evaluate(() => getComputedStyle(document.querySelector('.art-raster')).gridTemplateColumns.split(' ').length) === 2);
    await go(m2.page, '/settings');
    await m2.page.fill('#such-feld', 'Frühere Verträge');
    await m2.page.waitForTimeout(150);
    ok('Suche findet Frühere Verträge', await m2.page.locator('[data-search-key="contractsPast"] a[href="#/contracts/past"]').count() === 1);
    await m2.page.fill('#such-feld', 'Kennzahlen');
    await m2.page.waitForTimeout(150);
    ok('Suche findet Kennzahlen', await m2.page.locator('[data-search-key="figures"]').count() === 1);
    await m2.ctx.close();
    ok('Jahresbericht: Spanne über die Null mit jedem Ende in Farbe', /data-saldo-spanne>\$\{uncertainRangeHtml\(b\.uncertain\)\}/.test(readFileSync(join(ROOT, 'public/js/views/report-print.js'), 'utf8')));
  }
  // ── Kennzahlen ─────────────────────────────────────────────────────
  {
    const { ctx, page, errs } = await open();
    await go(page, '/figures');
    ok('Kennzahlen: Reiter unter Auswertungen', await page.locator('.section-tabs a[href="#/figures"][aria-current="page"], .section-tabs a.active[href="#/figures"]').count() === 1);
    // v3.12.0 (m4b) — Effizienz in eigener Reihe, Einordnung und Tanks als Paar: je Reihe gleich hoch, unter keiner Karte
    // mehr als ein Drittel leer (Regel R5); zu dritt blieb unter den beiden kürzeren mehr als die Hälfte leer
    const reihen = await page.evaluate(() => [...document.querySelectorAll('.kennzahl-raster')].map(r => [...r.children].map(c => {
      const b = c.getBoundingClientRect();
      const unten = Math.max(...[...c.children].filter(k => k.getBoundingClientRect().height > 0).map(k => k.getBoundingClientRect().bottom));
      return { h: Math.round(b.height), leer: (b.bottom - unten) / b.height };
    })));
    ok('Kennzahlen: zwei Raster, Karten je Raster gleich hoch', reihen.length === 2 && reihen.every(r => Math.max(...r.map(c => c.h)) - Math.min(...r.map(c => c.h)) <= 2), JSON.stringify(reihen));
    ok('Kennzahlen: unter keiner Karte mehr als ein Drittel leer', reihen.flat().every(c => c.leer <= 1 / 3), JSON.stringify(reihen));
    ok('Kennzahlen: Effizienz, Einordnung und Tanks stehen im Raster', await page.locator('.kennzahl-raster .dash-eff-list, .kennzahl-raster .dash-eff').count() === 1 && await page.locator('.kennzahl-raster [data-role="comparison"]').count() === 1 && await page.locator('.kennzahl-raster .tank-bar').count() >= 1);
    const kpis = await page.evaluate(() => [...document.querySelectorAll('.dash-strom-saldo .kpi')].map(c => Math.round(c.getBoundingClientRect().height)));
    ok('Strom-Saldo: gleich hohe Zahlen', kpis.length >= 3 && Math.max(...kpis) - Math.min(...kpis) <= 2, kpis.join(','));
    ok('Schaubilder da, Energiefluss in eigener Reihe', await page.locator('.dash-explainers .explainer').count() >= 2 && await page.evaluate(() => {
      const e = document.querySelector('.dash-explainer:has(.ex-energy)');
      const g = document.querySelector('.explainer-grid');
      return !e || Math.abs(e.getBoundingClientRect().width - g.getBoundingClientRect().width) < 3;
    }));
    await page.evaluate(() => document.querySelector('.dash-explainers .explainer').scrollIntoView());
    await page.waitForTimeout(600);
    ok('Schaubilder laufen an, sobald sie im Bild sind', await page.locator('.dash-explainers .explainer.ex--ready').count() >= 1);
    ok('Kennzahlen: keine Seitenfehler', errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  // ── v3.13.0 — Rückfrage beim Speichern: nach „Abbrechen“ steht der Fokus wieder auf dem Knopf ──────
  // Dialog „+ Ablesung“ (Gas, Strom; Abbrechen per Klick und per Escape) und Seite „Zählerstände“. Bis v3.12 fiel er im
  // Dialog auf <body> (der Dialog darunter war noch offen), seit der Erwartung nach dem Wetter auch auf der Seite (der
  // gesperrte Knopf verlor ihn, während die Seite wartete). Im echten Browser geprüft (Lektion 95).
  {
    const wo = () => { const a = document.activeElement; return !a || a === document.body ? 'BODY' : (a.dataset.act || a.dataset.action || a.tagName); };
    for (const art of ['gas', 'strom']) {
      for (const how of ['Klick', 'Escape']) {
        const { ctx, page, errs } = await open(1280, 900);
        await go(page, `/utility/${art}`);
        await page.click('#header-new-reading, #btn-new-reading');
        await page.waitForSelector('.modal #reading-form');
        await page.fill('.modal input[name="counter"]', '88888888');
        await page.focus('.modal [data-act="save"]');
        await page.keyboard.press('Enter');
        await page.waitForSelector('.modal .check-list', { timeout: 8000 });
        if (how === 'Klick') await page.click('.modal-backdrop:last-child [data-act="cancel"]'); else await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        ok(`Dialog „+ Ablesung“ ${art}, Rückfrage abgebrochen (${how}): Fokus auf „Anlegen“, Dialog offen`,
          await page.evaluate(wo) === 'save' && await page.locator('.modal-backdrop').count() === 1, await page.evaluate(wo));
        ok(`Dialog „+ Ablesung“ ${art}: keine Seitenfehler`, errs.length === 0, errs.join(' | '));
        await ctx.close();
      }
    }
    for (const how of ['Klick', 'Escape']) {
      const { ctx, page } = await open(1280, 900);
      await go(page, '/zaehlerstaende');
      await page.fill('.reading-card[data-utility="gas"] [data-role="counter"]', '88888888');
      await page.focus('[data-action="save-all"]');
      await page.keyboard.press('Enter');
      await page.waitForSelector('.modal .check-list', { timeout: 8000 });
      if (how === 'Klick') await page.click('.modal-backdrop:last-child [data-act="cancel"]'); else await page.keyboard.press('Escape');
      await page.waitForTimeout(400);
      ok(`Seite „Zählerstände“, Rückfrage abgebrochen (${how}): Fokus auf „Alle speichern“`, await page.evaluate(wo) === 'save-all', await page.evaluate(wo));
      await ctx.close();
    }
  }
  // ── v3.13.0 (Paket K2e) — Frühere Verträge zwischen 900 und 1280 px: „Rechnung …“ und „Ansehen“ im sichtbaren Rahmen ──
  // Die Tabelle wischt über 900 px in ihrem Rahmen quer; die Spalte der Aktionen ist angeheftet. Dazu die Sprache mit
  // dem längsten Knopf (nl), die Ränder 899 px (gestapelt) und 1440 px (passt ganz).
  {
    const imRahmen = () => {
      const wrap = document.querySelector('.past__tabelle')?.closest('.table-wrap');
      if (!wrap) return null;
      const w = wrap.getBoundingClientRect();
      const left = w.left + wrap.clientLeft, right = left + wrap.clientWidth;
      const knoepfe = [...wrap.querySelectorAll('[data-role="past-bill"], [data-past-view]')];
      const draussen = knoepfe.filter(b => { const r = b.getBoundingClientRect(); return r.right > right + 1 || r.left < left - 1; });
      const row = document.querySelector('.past__tabelle tbody tr');
      return { n: knoepfe.length, draussen: draussen.map(b => b.textContent.trim()), seite: document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
        gestapelt: !!row && getComputedStyle(row).display === 'block', wischt: wrap.scrollWidth > wrap.clientWidth + 1 };
    };
    for (const [breite, sprache] of [[900, 'de'], [1024, 'de'], [1180, 'de'], [1280, 'de'], [1024, 'nl'], [899, 'de'], [1440, 'de']]) {
      const { ctx, page } = await open(breite, 900);
      if (sprache !== 'de') {
        await go(page, '/dashboard');
        await page.evaluate((l) => localStorage.setItem('et-language', l), sprache);
        await page.goto(`${BASE}?sprache=${sprache}#/contracts/past`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
      } else await go(page, '/contracts/past');
      const r = await page.evaluate(imRahmen);
      ok(`Frühere Verträge ${breite} px (${sprache}): alle Knöpfe im sichtbaren Rahmen, die Seite wischt nicht`, !!r && r.n >= 6 && r.draussen.length === 0 && r.seite, JSON.stringify(r));
      if (breite === 899) ok('Frühere Verträge 899 px: gestapelt wie bisher', !!r && r.gestapelt && !r.wischt, JSON.stringify(r));
      if (breite === 1440) ok('Frühere Verträge 1440 px: die Tabelle passt ganz', !!r && !r.gestapelt && !r.wischt, JSON.stringify(r));
      await ctx.close();
    }
  }
  // ── v3.13.0 (Paket K2e) — Abzeichen „Schatten“, „Angebot“, „künftig“: mindestens 4,5:1 auf der Fläche, auf der sie
  // stehen, im Dunkel- und Hellmodus, auch mit dem Zeilen-Hover und bei 390 px. Bis v3.12 im Dunkeln 3,1 bis 4,1:1
  // (aus den Tokens gerechnet, tests/contrast.test.mjs).
  {
    const messen = () => {
      const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
      const lum = (c) => 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2]);
      const farbe = (s) => {
        let m = s.match(/^rgba?\(([^)]+)\)$/);
        if (m) { const p = m[1].split(/[\s,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; }
        m = s.match(/^color\(srgb ([\d.]+) ([\d.]+) ([\d.]+)(?: \/ ([\d.]+))?\)$/);
        if (m) return [m[1] * 255, m[2] * 255, m[3] * 255, m[4] != null ? Number(m[4]) : 1];
        return null;
      };
      const ueber = (oben, unten) => [0, 1, 2].map(i => oben[i] * oben[3] + unten[i] * (1 - oben[3]));
      const flaeche = (el) => {
        const stapel = [];
        for (let n = el; n; n = n.parentElement) {
          const c = farbe(getComputedStyle(n).backgroundColor);
          if (c && c[3] > 0) { stapel.push(c); if (c[3] >= 1) break; }
        }
        let grund = [255, 255, 255];
        for (const c of stapel.reverse()) grund = ueber(c, grund);
        return grund;
      };
      const vis = (el) => { const r = el.getBoundingClientRect(); return r.width > 1 && r.height > 1; };
      return [...document.querySelectorAll('.badge--shadow, .status-pill.shadow, .badge--info, .status-pill.future')].filter(vis).map(el => {
        const fg = farbe(getComputedStyle(el).color), bg = flaeche(el);
        const a = lum(fg), b = lum(bg);
        return { text: el.textContent.trim().slice(0, 20), kontrast: Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100 };
      });
    };
    for (const schema of ['dark', 'light']) {
      for (const [breite, hoehe] of [[1280, 900], [390, 844]]) {
        const mobile = breite < 500;
        const ctx = await browser.newContext({ viewport: { width: breite, height: hoehe }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1, locale: 'de-DE', colorScheme: schema, serviceWorkers: 'block' });
        const page = await ctx.newPage();
        const alle = [];
        for (const route of ['/tariffs', '/utility/gas/contracts']) {
          await go(page, route);
          alle.push(...(await page.evaluate(messen)).map(x => ({ ...x, route })));
          // Zeilen-Hover (--bg-2) unter dem ersten Abzeichen in einer Tabellenzeile
          const zeile = page.locator('tr:has(.badge--shadow), tr:has(.status-pill.shadow)').first();
          if (!mobile && await zeile.count()) { await zeile.hover(); await page.waitForTimeout(150); alle.push(...(await page.evaluate(messen)).map(x => ({ ...x, route: route + ' (Hover)' }))); }
        }
        const schwach = alle.filter(x => x.kontrast < 4.5);
        ok(`Abzeichen ${schema}, ${breite} px: Schatten/Angebot/künftig gefunden`, alle.some(x => /Schatten/.test(x.text)) && alle.some(x => /Angebot/i.test(x.text)), JSON.stringify(alle.slice(0, 6)));
        ok(`Abzeichen ${schema}, ${breite} px: mindestens 4,5:1 auf jeder Fläche`, alle.length > 0 && schwach.length === 0, JSON.stringify(schwach.slice(0, 6)));
        await ctx.close();
      }
    }
  }
  // ── v3.13.0 (Paket K2e) — Erster Start im Browser: Sprache und Land aus dem Browser (fr-CH, de-AT, en-GB) ──────
  // Leerer Datenordner, keine Anfrage vorher. Bis v3.12 schickte die Oberfläche bei den ersten Anfragen selbst „de“:
  // Ein französischer Browser bekam Deutsch, Deutschland und den deutschen Wetterort. Maßstab ist ein zweiter leerer
  // Ordner, den der Server allein mit derselben Kopfzeile anlegt.
  {
    // v3.13.0 — je Server ein freier Port des Systems: ET_SICHT_PORT + 1 und + 2 lagen in der Zeitreise auf dem Port des
    // nachgebauten Home Assistant und eins über dem eigenen Bereich; dort lief ein fremder Server mit Beispieldaten,
    // der eigene scheiterte still, und der Vergleich verglich mit dessen Daten
    const serve = async () => {
      const p = await freePort();
      if (await portBelegt(p)) throw new Error(`Erststart: Port ${p} ist schon belegt`);
      const dir = mkdtempSync(join(tmpdir(), 'et-erst-'));
      const srv = spawn('php', ['-S', `127.0.0.1:${p}`, 'router.php'], { cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dir, ET_LOG_DEST: 'null', ET_AUTH: 'off' }, stdio: 'ignore' });
      const B = `http://127.0.0.1:${p}/`;
      // bereit, sobald er Dateien ausliefert: ohne API-Aufruf (der legte sonst an)
      for (let w = 0; w < 40; w++) { if (await fetch(`${B}public/locales/languages.json`).then(r => r.ok).catch(() => false)) break; await new Promise(r => setTimeout(r, 250)); }
      const done = () => { srv.kill(); rmSync(dir, { recursive: true, force: true }); };
      if (srv.exitCode !== null) { done(); throw new Error(`Erststart: eigener Server auf Port ${p} nicht gestartet`); }
      return { B, done };
    };
    const kern = async (B) => {
      const s = (await (await fetch(`${B}api.php/api/settings`)).json()).data || {};
      const gas = ((await (await fetch(`${B}api.php/api/utility/gas/meters`)).json()).data || []).map(m => m.name);
      return { language: s.language, country: s.country, currency: s.currency, location_name: s.location_name, gas };
    };
    for (const [locale, lang, country] of [['fr-CH', 'fr', 'CH'], ['de-AT', 'de', 'AT'], ['en-GB', 'en', 'GB']]) {
      let app = null, ref = null;
      try {
        app = await serve();
        ref = await serve();
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale, serviceWorkers: 'block' });
        const page = await ctx.newPage();
        await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
        await page.route(/sync-open-meteo|geocode|update-check/, r => r.abort());
        let first = null;
        page.on('request', req => { if (!first && /api\.php\/api\//.test(req.url())) first = req.headers(); });
        await page.goto(app.B, { waitUntil: 'networkidle' });
        await page.waitForSelector('#setup-lang', { timeout: 15000 });
        const wahl = await page.evaluate(() => {
          const c = document.querySelector('#setup-country');
          return { lang: document.querySelector('#setup-lang')?.value, country: c?.value || c?.dataset.country, html: document.documentElement.lang };
        });
        ok(`Erststart ${locale}: erste Anfrage mit der Sprache des Browsers, ohne eigene Wahl`,
          !!first && String(first['accept-language'] || '').startsWith(locale) && !first['x-et-language'], JSON.stringify(first && { al: first['accept-language'], xl: first['x-et-language'] }));
        ok(`Erststart ${locale}: Assistent mit ${lang} und ${country}, Seite in ${lang}`, wahl.lang === lang && wahl.country === country && wahl.html === lang, JSON.stringify(wahl));
        const got = await kern(app.B);
        await fetch(`${ref.B}api.php/api/settings`, { headers: { 'Accept-Language': first?.['accept-language'] || locale } });
        const want = await kern(ref.B);
        ok(`Erststart ${locale}: Einstellungen und erster Zähler wie beim Server allein mit derselben Kopfzeile`,
          JSON.stringify(got) === JSON.stringify(want) && got.language === lang && got.country === country, `${JSON.stringify(got)} / ${JSON.stringify(want)}`);
        await ctx.close();
      } finally { app?.done(); ref?.done(); }
    }
  }
  // ── Weiterleitung und alte Wege ────────────────────────────────────
  {
    const { ctx, page } = await open();
    await go(page, '/analysis');
    ok('Analyse bleibt unter ihrer Adresse', await page.locator('.section-tabs a[href="#/analysis"].active, .section-tabs a[href="#/analysis"][aria-current="page"]').count() === 1);
    await ctx.close();
  }
} finally {
  await browser.close();
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
}
console.log(`  sichten: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
