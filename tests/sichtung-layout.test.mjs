// =====================================================================
// v3.12.0 (Sichtung M1) — was der Maintainer am Gerät beanstandet hat, im echten Browser.
//
//   S1  Farbe eines Saldos: Kontrast gegen die Fläche, hell und dunkel
//   S4  Einstellungen bei 390 px: Beschriftung über dem Feld, Feld breit genug, Wert lesbar,
//       auch in einer langen Sprache. Umbruchbreite der Formulare: 900 px.
//   S5  Übersicht: ein Knopf fürs Erfassen
//   S6  Knopfreihen: gleich hoch, auf einer Linie
//   S8  Zähler: Unterzähler eingerückt unter dem Hauptzähler
//   S9  Verbrauchsarten: Messkonzept als zweite Box
//   S11 Datenexport: Knöpfe in der Farbe der Art, Kontrast, Name und Grafik als zweites Zeichen
//
// JSDOM kennt kein Layout. Browser: Playwright mit Chromium. Port: ET_SICHT_PORT (sonst ein freier).
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

const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.on('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

const dataDir = mkdtempSync(join(tmpdir(), 'et-sicht-'));
cpSync(join(ROOT, 'demo-data'), dataDir, { recursive: true });
const port = Number(process.env.ET_SICHT_PORT) || await freePort();
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null', ET_AUTH: 'off' }, stdio: 'ignore',
});
const BASE = `http://127.0.0.1:${port}/`;
for (let i = 0; i < 40; i++) {
  const up = await fetch(`${BASE}api.php/api/health`).then(r => r.status < 500).catch(() => false);
  if (up) break;
  await new Promise(r => setTimeout(r, 250));
}

const browser = await pw.chromium.launch();

/** Neue Seite mit Stufe und Sprache der Installation. */
async function open({ width = 1440, level = 'expert', language = 'de', theme = 'light' } = {}) {
  const mobile = width < 500;
  const ctx = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1, locale: 'de-DE', colorScheme: theme, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.goto(BASE, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async ({ level, language }) => {
    await fetch('api.php/api/settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ ui_level: level, language, setup_pending: false }) });
  }, { level, language });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.querySelector('#primary-nav a'), { timeout: 20000 });
  if (theme === 'dark') await page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
  return { ctx, page };
}
const go = async (page, route) => {
  await page.evaluate(x => { location.hash = '#' + x; }, route);
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(300);
};

// Läuft im Browser: Kontrast einer Schrift gegen die Fläche dahinter.
const CONTRAST = `
  // Über die Leinwand aufgelöst: Berechnete Farben können color(srgb ...) oder color-mix sein, kein Textvergleich trägt das
  window.__ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
  window.__rgba = (css) => {
    const c = window.__ctx;
    c.clearRect(0, 0, 1, 1);
    c.fillStyle = css;
    c.fillRect(0, 0, 1, 1);
    const d = c.getImageData(0, 0, 1, 1).data;
    return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
  };
  window.__lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  window.__bg = (el) => {
    const stack = [];
    for (let e = el; e; e = e.parentElement) { const c = window.__rgba(getComputedStyle(e).backgroundColor); if (c.a > 0) { stack.push(c); if (c.a === 1) break; } }
    let base = { r: 255, g: 255, b: 255 };
    for (const c of stack.reverse()) base = { r: c.r * c.a + base.r * (1 - c.a), g: c.g * c.a + base.g * (1 - c.a), b: c.b * c.a + base.b * (1 - c.a) };
    return base;
  };
  window.__ratio = (fg, bg) => { const a = window.__lum(fg), b = window.__lum(bg); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); };
`;

try {
  // ── S1: Salden, Kontrast hell und dunkel ─────────────────────────────
  for (const theme of ['light', 'dark']) {
    const { ctx, page } = await open({ theme });
    await page.evaluate(CONTRAST);
    const SALDO_SEL = '#view [data-saldo], #view .balance-col__value--credit, #view .balance-col__value--due, #view .contracts-table td.ton-ok, #view .contracts-table td.ton-fehler, #view .balance-verdict.refund .balance-verdict__value, #view .balance-verdict.surcharge .balance-verdict__value, #view .contract-overview__expected strong[class]';
    for (const r of ['/contracts', '/utility/gas', '/utility/strom', '/forecast']) {
      await go(page, r);
      // Kalter Server in der CI: Die erste Vertragsseite rechnet länger, als go() auf Ruhe im Netz wartet (8 s).
      // Gewartet wird auf die Salden selbst; erscheinen sie nicht, schlägt die Prüfung unten weiter fehl.
      if (r === '/contracts') await page.waitForFunction(sel => document.querySelectorAll(sel).length >= 4, SALDO_SEL, { timeout: 30000 }).catch(() => {});
      const res = await page.evaluate((SEL) => {
        const out = { n: 0, bad: [] };
        for (const el of document.querySelectorAll(SEL)) {
          if (!el.textContent.trim() || el.dataset.saldo === 'even') continue;
          const c = window.__rgba(getComputedStyle(el).color);
          const ratio = window.__ratio(c, window.__bg(el));
          out.n++;
          out.min = Math.min(out.min ?? 99, ratio);
          if (ratio < 4.5) out.bad.push(`${el.textContent.trim().slice(0, 24)} ${ratio.toFixed(2)}:1`);
        }
        return out;
      }, SALDO_SEL);
      ok(`S1 ${r} (${theme}): Salden lesbar`, res.bad.length === 0, res.bad.join(', '));
      if (res.n) console.log(`  Kontrast Salden ${r} (${theme}): kleinster Wert ${res.min.toFixed(2)}:1 bei ${res.n} Stellen`);
      if (r === '/contracts') ok(`S1 ${r} (${theme}): Salden gefunden`, res.n >= 4, String(res.n));
    }
    await ctx.close();
  }

  // ── S4: Einstellungen am iPhone ──────────────────────────────────────
  const SETTINGS = ['/settings', '/settings/region', '/settings/display', '/household/people', '/household/building', '/household/utilities', '/settings/data', '/settings/calculation', '/connections', '/connections/reading', '/connections/homeassistant', '/connections/homeassistant/meters', '/connections/homeassistant/notifications', '/connections/homeassistant/templates', '/connections/weather', '/connections/evcc', '/connections/calendar', '/connections/ai', '/connections/ai/connect', '/connections/keys', '/connections/homenet', '/settings/access', '/settings/expert', '/settings/system'];
  for (const language of ['de', 'fr']) {
    const { ctx, page } = await open({ width: 390, language });
    let felder = 0;
    for (const r of SETTINGS) {
      await go(page, r);
      const res = await page.evaluate(() => {
        document.querySelectorAll('#view details').forEach(d => { d.open = true; });
        const out = { n: 0, bad: [], sw: document.documentElement.scrollWidth, w: document.documentElement.clientWidth };
        const FELD = '#view input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]):not([type=color]), #view select, #view textarea';
        for (const f of document.querySelectorAll(FELD)) {
          const b = f.getBoundingClientRect();
          if (b.width < 2 || b.height < 2 || f.closest('table')) continue;
          out.n++;
          const name = (f.labels?.[0]?.textContent || f.getAttribute('aria-label') || f.name || f.id || '').trim().replace(/\s+/g, ' ').slice(0, 40);
          if (b.width < 140) out.bad.push(`zu schmal ${Math.round(b.width)} px: ${name}`);
          if (parseFloat(getComputedStyle(f).fontSize) < 16) out.bad.push(`Schrift unter 16 px: ${name}`);
          if (f.tagName !== 'TEXTAREA' && !f.readOnly && f.value && f.scrollWidth > f.clientWidth + 1) out.bad.push(`Wert abgeschnitten: ${name} (${f.value.slice(0, 20)})`);
          const lab = f.labels?.[0];
          if (lab && !lab.contains(f)) {
            const l = lab.getBoundingClientRect();
            if (l.bottom > b.top + 2) out.bad.push(`Beschriftung nicht über dem Feld: ${name}`);
          }
          // Im Settings-Formular: volle Breite
          const row = f.closest('.settings-field');
          if (row && !f.closest('.fgrid, .rowlist, .input-pair, .input-row')) {
            const inner = row.getBoundingClientRect().width;
            if (inner - b.width > 2) out.bad.push(`nicht volle Breite ${Math.round(b.width)}/${Math.round(inner)}: ${name}`);
          }
        }
        return out;
      });
      felder += res.n;
      ok(`S4 ${r} (${language}, 390 px)`, res.bad.length === 0, res.bad.slice(0, 6).join(' | '));
      ok(`S4 ${r} (${language}): kein Querscrollen`, res.sw <= res.w + 1, `${res.sw} > ${res.w}`);
    }
    ok(`S4 (${language}): Felder gefunden`, felder > 80, String(felder));
    await ctx.close();
  }
  // Umbruchbreite: darunter über dem Feld, darüber daneben (R2)
  for (const [width, oben] of [[899, true], [900, false]]) {
    const { ctx, page } = await open({ width });
    await go(page, '/household/building');
    const res = await page.evaluate(() => {
      const f = document.querySelector('#view .settings-field > .input, #view .settings-field > .select');
      const l = f.closest('.settings-field').querySelector('label');
      return { lb: l.getBoundingClientRect().bottom, ft: f.getBoundingClientRect().top, fw: f.getBoundingClientRect().width };
    });
    ok(`S4 bei ${width} px: Beschriftung ${oben ? 'über' : 'neben'} dem Feld`, oben ? res.lb <= res.ft + 2 : res.lb > res.ft + 2, JSON.stringify(res));
    await ctx.close();
  }

  // ── S5: Übersicht, ein Knopf fürs Erfassen ───────────────────────────
  for (const level of ['beginner', 'advanced', 'expert']) {
    for (const width of [1440, 390]) {
      const { ctx, page } = await open({ width, level });
      await go(page, '/dashboard');
      const res = await page.evaluate(() => {
        const vis = (el) => { const b = el.getBoundingClientRect(); const s = getComputedStyle(el); return b.width > 1 && b.height > 1 && s.display !== 'none' && s.visibility !== 'hidden'; };
        return {
          immer: [...document.querySelectorAll('[data-action="capture"], [data-action="open-capture"]')].filter(vis).length,
          kopf: [...document.querySelectorAll('#view .section-head .btn, #view .section-actions .btn')].filter(b => vis(b) && b.getAttribute('href') === '#/zaehlerstaende').length,
        };
      });
      ok(`S5 ${level}, ${width} px: genau ein Knopf „Erfassen“`, res.immer === 1, String(res.immer));
      ok(`S5 ${level}, ${width} px: kein zweiter Knopf im Kopf der Übersicht`, res.kopf === 0, String(res.kopf));
      if (width === 390 && level === 'beginner') {
        // Der Weg: Tab-Leiste, Blatt, erster Eintrag führt zu den Zählerständen
        await page.click('[data-action="capture"]');
        await page.waitForSelector('.capture-sheet__item');
        const first = await page.$eval('.capture-sheet__item', a => a.getAttribute('href'));
        ok('S5 iPhone: erster Eintrag im Blatt sind die Zählerstände', first === '#/zaehlerstaende', first);
        await page.click('.capture-sheet__item');
        await page.waitForTimeout(500);
        ok('S5 iPhone: zwei Tipps bis zu den Zählerständen', (await page.evaluate(() => location.hash)).startsWith('#/zaehlerstaende'));
      }
      await ctx.close();
    }
  }

  // ── S8: Zähler, Unterzähler eingerückt unter dem Hauptzähler ─────────
  for (const width of [1280, 390]) {
    const { ctx, page } = await open({ width });
    await go(page, '/household/meters?art=strom');
    const res = await page.evaluate(() => {
      const box = (el) => el.getBoundingClientRect();
      const main = document.querySelector('.zaehlerbaum > .zaehlerbaum__knoten > .meter-card');
      const kids = [...document.querySelectorAll('.zaehlerbaum__kinder .meter-card')];
      const knoten = kids[0]?.closest('.zaehlerbaum__knoten');
      const after = knoten ? getComputedStyle(knoten, '::after') : null;
      const before = knoten ? getComputedStyle(knoten, '::before') : null;
      return {
        mainLeft: box(main).left, mainTop: box(main).top, kinder: kids.length,
        kidLeft: kids.map(k => box(k).left), kidTop: kids.map(k => box(k).top), kidW: kids.map(k => box(k).width),
        linie: after?.borderTopWidth, senkrecht: before?.borderLeftWidth, gruppe: kids.every(k => /Gruppe/.test(k.textContent)),
        sub: kids.every(k => k.classList.contains('meter-card--sub')),
        sw: document.documentElement.scrollWidth, w: document.documentElement.clientWidth,
        knoepfe: kids.every(k => [...k.querySelectorAll('button')].every(b => box(b).right <= document.documentElement.clientWidth + 1)),
      };
    });
    ok(`S8 ${width} px: Unterzähler unter dem Hauptzähler`, res.kinder === 2 && res.kidTop.every(t => t > res.mainTop), JSON.stringify(res));
    ok(`S8 ${width} px: eingerückt`, res.kidLeft.every(l => l - res.mainLeft >= 16), JSON.stringify(res.kidLeft));
    ok(`S8 ${width} px: feine Linie`, res.linie === '1px' && res.senkrecht === '1px', `${res.linie} ${res.senkrecht}`);
    ok(`S8 ${width} px: Gruppe bleibt zu sehen, Art genannt`, res.gruppe && res.sub);
    ok(`S8 ${width} px: Karte bleibt breit, Knöpfe erreichbar, kein Querscrollen`, res.kidW.every(w => w >= 300) && res.knoepfe && res.sw <= res.w + 1, JSON.stringify(res.kidW));
    // Tastatur: Die Knöpfe bleiben in der Reihenfolge des Baums erreichbar
    const fokus = await page.evaluate(() => {
      const list = [...document.querySelectorAll('.zaehlerbaum button, .zaehlerbaum summary')];
      return { n: list.length, alleFokussierbar: list.every(b => b.tabIndex === 0 && !b.disabled) };
    });
    ok(`S8 ${width} px: per Tastatur erreichbar`, fokus.n >= 6 && fokus.alleFokussierbar, JSON.stringify(fokus));
    await ctx.close();
  }

  // ── S9: Verbrauchsarten, Messkonzept als zweite Box ──────────────────
  for (const level of ['beginner', 'advanced', 'expert']) {
    const { ctx, page } = await open({ level });
    await go(page, '/household/utilities');
    const ids = await page.evaluate(() => [...document.querySelectorAll('#view .settings-grid > .card')]
      .filter(c => c.getBoundingClientRect().height > 1).slice(0, 2).map(c => c.id));
    ok(`S9 ${level}: Messkonzept direkt nach den Verbrauchsarten`, ids[0] === 'active-utils-card' && ids[1] === 'concept-card', ids.join());
    await ctx.close();
  }

  // ── S10: Sprache, was gilt und warum ─────────────────────────────────
  {
    const { ctx, page } = await open({ width: 1280 });
    const quelle = () => page.evaluate(() => {
      const e = document.querySelector('[data-role="lang-effective"]');
      return { s: e?.dataset.source, text: e?.textContent.replace(/\s+/g, ' ').trim(), lang: document.documentElement.lang, wahl: document.querySelector('#lang-select')?.value };
    });
    await go(page, '/settings/region');
    let q = await quelle();
    ok('S10 ohne eigene Wahl: die Vorgabe gilt', q.s === 'installation' && /Vorgabe für alle/.test(q.text) && q.lang === 'de' && q.wahl === '', JSON.stringify(q));
    // Wahl auf dem Gerät: geht vor, und die App zeigt wirklich diese Sprache
    await page.evaluate(() => localStorage.setItem('et-language', 'en'));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.querySelector('#primary-nav a'), { timeout: 20000 });
    await go(page, '/settings/region');
    q = await quelle();
    ok('S10 Wahl auf dem Gerät geht der Vorgabe vor', q.s === 'device' && q.lang === 'en' && /In use now: English/.test(q.text) && q.wahl === 'en', JSON.stringify(q));
    // Vorgabe ändern, während das Gerät eine eigene Wahl hat: Die Anzeige bleibt
    await page.selectOption('#lang-default-select', 'fr');
    await page.waitForTimeout(800);
    q = await quelle();
    ok('S10 neue Vorgabe ändert nichts an der eigenen Wahl', q.s === 'device' && q.lang === 'en', JSON.stringify(q));
    await page.evaluate(() => fetch('api.php/api/settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ language: 'de' }) }));
    await page.evaluate(() => localStorage.removeItem('et-language'));
    await ctx.close();
  }

  // ── v3.12.0 (M3b): Zählertausch aus dem Posteingang, jeder Weg aus dem Dialog ──
  for (const [weg, raus] of [
    ['Abbrechen', (p) => p.click('.modal [data-act="cancel"]')],
    ['Schließen', (p) => p.click('.modal .modal__close')],
    ['Escape', (p) => p.keyboard.press('Escape')],
  ]) {
    const { ctx, page } = await open({ width: 1280 });
    await page.evaluate(() => { location.hash = '#/household/meters?art=gas&replace=m_gas_main&date=2026-01-02&old=5&new=1'; });
    await page.waitForSelector('.modal #replace-form', { timeout: 8000 });
    await raus(page);
    await page.waitForTimeout(500);
    const zu = !(await page.$('.modal'));
    const adresse = await page.evaluate(() => location.hash);
    await page.reload();
    await page.waitForSelector('#view h1', { timeout: 8000 });
    await page.waitForTimeout(600);
    ok(`Zählertausch ${weg}: Dialog bleibt zu, Adresse sauber, Neuladen öffnet ihn nicht`, zu && adresse === '#/household/meters?art=gas' && !(await page.$('.modal')), `zu ${zu}, ${adresse}`);
    await ctx.close();
  }

  // v3.12.0 (M3b) — ohne letzten Stand gibt es keinen Hinweis, das Feld darf nicht auf ihn verweisen
  {
    const { ctx, page } = await open({ width: 1280 });
    const H = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const id = await page.evaluate(async (h) => (await (await fetch('api.php/api/utility/gas/meters', { method: 'POST', headers: h, body: JSON.stringify({ name: 'Ohne Stand' }) })).json()).data?.id, H);
    await page.evaluate((m) => { location.hash = `#/household/meters?art=gas&replace=${m}&date=2026-01-02`; }, id);
    await page.waitForSelector('.modal #replace-form', { timeout: 8000 });
    await page.waitForTimeout(150);
    const leer = await page.evaluate(() => [...document.querySelectorAll('.modal [aria-describedby]')].flatMap(e => e.getAttribute('aria-describedby').split(/\s+/)).filter(x => !document.getElementById(x)));
    ok('Zählertausch ohne letzten Stand: kein Verweis ins Leere', !!id && leer.length === 0, `${id} ${leer.join()}`);
    await page.click('.modal [data-act="cancel"]');
    await page.evaluate(async ([m, h]) => { await fetch(`api.php/api/utility/gas/meters/${m}`, { method: 'DELETE', headers: h }); }, [id, H]);
    await ctx.close();
  }

  // ── S11: Datenexport, Knöpfe in der Farbe der Art ────────────────────
  for (const theme of ['light', 'dark']) {
    const { ctx, page } = await open({ theme });
    await page.evaluate(CONTRAST);
    await go(page, '/settings/data');
    const res = await page.evaluate(() => {
      const out = { n: 0, bad: [], farben: {}, namen: true };
      for (const a of document.querySelectorAll('#view a[data-csv][data-utility]')) {
        const s = getComputedStyle(a);
        const c = window.__rgba(s.color);
        const ratio = window.__ratio(c, window.__bg(a));
        out.n++;
        out.farben[a.dataset.utility] = s.color;
        out.min = Math.min(out.min ?? 99, ratio);
        if (!a.textContent.trim()) out.namen = false;
        if (ratio < 4.5) out.bad.push(`${a.dataset.utility} ${ratio.toFixed(2)}:1`);
      }
      return out;
    });
    ok(`S11 ${theme}: Knöpfe gefunden, jeder mit Namen`, res.n >= 10 && res.namen, String(res.n));
    ok(`S11 ${theme}: Kontrast mindestens 4,5:1`, res.bad.length === 0, res.bad.join(', '));
    console.log(`  Kontrast Export (${theme}): kleinster Wert ${res.min.toFixed(2)}:1 bei ${res.n} Knöpfen`);
    ok(`S11 ${theme}: jede Art hat ihre Farbe`, new Set(Object.values(res.farben)).size >= 6, JSON.stringify(res.farben));
    await ctx.close();
  }
  {
    const src = (await import('node:fs')).readFileSync(join(ROOT, 'public/js/views/settings-data.js'), 'utf8');
    ok('S11: keine Farbwerte im Code', !/#[0-9a-fA-F]{3,6}\b|rgb\(/.test(src));
  }

  // ── S12: Feinjustierung ──────────────────────────────────────────────
  {
    const { ctx, page } = await open({ width: 1440 });
    await go(page, '/household/utilities');
    const tab = await page.evaluate(() => {
      document.querySelectorAll('#view details').forEach(d => { d.open = true; });
      const t = [...document.querySelectorAll('#view .settings-field table')].find(x => x.getBoundingClientRect().width > 1);
      const f = t?.closest('.settings-field');
      return t ? { w: t.parentElement.getBoundingClientRect().width, f: f.getBoundingClientRect().width, wrap: t.parentElement.scrollWidth - t.parentElement.clientWidth } : null;
    });
    ok('S12 Tabelle in den Einstellungen nutzt die ganze Breite', tab && tab.f > 500 && tab.f - tab.w <= 2 && tab.wrap <= 1, JSON.stringify(tab));
    await go(page, '/settings/data');
    const spalten = await page.evaluate(() => {
      const kids = [...document.querySelectorAll('#view .export-grid > .export-tile')].filter(x => x.getBoundingClientRect().width > 1);
      const zeilen = new Map();
      for (const k of kids) { const top = Math.round(k.getBoundingClientRect().top); zeilen.set(top, (zeilen.get(top) || 0) + 1); }
      return [...zeilen.values()];
    });
    ok('S12 Exportkacheln: volle Reihen', spalten.length >= 1 && new Set(spalten).size === 1, spalten.join('/'));
    await ctx.close();
  }

  // ── S7: Keine Emoji und Symbolzeichen im Bild, auch aus Daten (Jahresbericht, Flexibilität) ──
  {
    const { ctx, page } = await open();
    const SEITEN = ['/report', '/report/print?year=2025&detail=1&flex=1', '/flexibility?year=2025', '/dashboard', '/utility/gas', '/analysis', '/forecast', '/recommendations'];
    for (const r of SEITEN) {
      await go(page, r);
      await page.waitForTimeout(500);
      const bad = await page.evaluate(() => {
        const SYM = /(?:\p{Extended_Pictographic}|[\u2460-\u24FF\u25A0-\u27BF\u2B00-\u2BFF]|[＋☰✓✕✎›‹✔✖ⓘ])/u;
        const walker = document.createTreeWalker(document.querySelector('#view'), NodeFilter.SHOW_TEXT);
        const out = [];
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
          if (!SYM.test(n.textContent)) continue;
          out.push(n.textContent.trim().slice(0, 50));
        }
        return out;
      });
      ok(`S7 ${r}: kein Emoji, kein Symbolzeichen im Bild`, bad.length === 0, bad.slice(0, 5).join(' | '));
    }
    await ctx.close();
  }

  // ── S6: Knopfreihen ──────────────────────────────────────────────────
  const REIHEN = ['/dashboard', '/zaehlerstaende', '/inbox', '/utility/gas', '/utility/gas/contracts', '/household/meters?art=gas', '/contracts', '/contracts/past', '/figures', '/utility/gas?view=balance', '/utility/gas?view=readings', '/tariffs', '/bill-check', '/analysis', '/forecast', '/flexibility', '/report', '/todo', '/todo?art=rec', '/todo?art=rights', '/todo/dates', '/todo/history', '/todo/history/ai', '/activity',
    '/settings', '/household/people', '/settings/data', '/connections', '/connections/reading', '/connections/homeassistant', '/connections/homeassistant/meters', '/connections/homeassistant/notifications', '/connections/homeassistant/templates', '/connections/weather', '/connections/evcc', '/connections/calendar', '/connections/ai', '/connections/ai/connect', '/connections/keys', '/connections/homenet', '/settings/expert', '/settings/system'];
  for (const width of [1440, 390]) {
    const { ctx, page } = await open({ width });
    const pruefeReihen = () => page.evaluate(() => {
      const vis = (el) => { const b = el.getBoundingClientRect(); const s = getComputedStyle(el); return b.width > 1 && b.height > 1 && s.display !== 'none' && s.visibility !== 'hidden'; };
      const groups = new Map();
      for (const b of document.querySelectorAll('#view .btn')) {
        if (!vis(b)) continue;
        if (!groups.has(b.parentElement)) groups.set(b.parentElement, []);
        groups.get(b.parentElement).push(b);
      }
      const out = [];
      let reihen = 0;
      for (const [p, list] of groups) {
        const rows = [];
        for (const b of list) {
          const r = b.getBoundingClientRect();
          const row = rows.find(x => Math.abs(x.mid - (r.top + r.height / 2)) < 14);
          if (row) row.items.push(r); else rows.push({ mid: r.top + r.height / 2, items: [r] });
        }
        for (const row of rows) {
          if (row.items.length < 2) continue;
          reihen++;
          const hs = row.items.map(r => Math.round(r.height)), tops = row.items.map(r => Math.round(r.top));
          if (Math.max(...hs) - Math.min(...hs) > 1 || Math.max(...tops) - Math.min(...tops) > 1) out.push(`${String(p.className).slice(0, 30)}: ${hs.join('/')} @ ${tops.join('/')}`);
        }
      }
      return { out, reihen };
    });
    let reihen = 0;
    for (const r of REIHEN) {
      await go(page, r);
      const res = await pruefeReihen();
      reihen += res.reihen;
      ok(`S6 ${r} (${width} px): Knöpfe einer Reihe gleich hoch`, res.out.length === 0, res.out.slice(0, 4).join(' | '));
    }
    // Posteingang: auch das Dokument
    await go(page, '/inbox');
    await page.evaluate(() => document.querySelector('.inbox-table a[href^="#/inbox/"]')?.click());
    await page.waitForTimeout(800);
    const doc = await pruefeReihen();
    reihen += doc.reihen;
    ok(`S6 Posteingang, Dokument (${width} px)`, doc.out.length === 0, doc.out.slice(0, 4).join(' | '));
    ok(`S6 (${width} px): Reihen gefunden`, reihen >= 8, String(reihen));
    await ctx.close();
  }
} finally {
  await browser.close();
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
}
console.log(`\n  sichtung-layout: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
