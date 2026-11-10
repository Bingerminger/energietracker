// =====================================================================
// v3.12.0 (M5, Texte in sieben Sprachen) — lange Sprachen sprengen nichts.
//
// Die echte App im echten Browser mit dem Schaufenster-Haushalt, in allen sieben
// Sprachen am iPhone (390 px) und für die längeren Sprachen am Mac (1440 px):
//   L1 kein Text ragt aus seinem Kasten (Kachel, Karte, Knopf, Zelle, Feld, Infobox)
//   L2 kein Knopf mit Text bricht auf mehr als zwei Zeilen
//   L3 die Seite scrollt nicht quer
// Behälter, die absichtlich quer wischen (Untermenü, breite Tabelle), zählen nicht.
// Gemessen werden Textstücke, nicht Kästen: Ein Info-Knopf mit negativem Rand für
// die größere Tippfläche ist kein Befund.
//
// Aufklappbare Bereiche (<details>) öffnet der Test vor dem Messen.
//
// Grenze: nur die Hauptrouten und die erste Sicht ohne Dialoge.
// Browser: Playwright mit Chromium. Port: ET_LEN_PORT (sonst ein freier).
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

const ROUTES = ['/dashboard', '/utility/gas', '/utility/fernwaerme', '/household/utilities', '/contracts', '/contracts/past', '/todo', '/connections', '/settings/display', '/figures'];
const LAEUFE = [
  ...['fr', 'nl', 'de', 'en', 'it', 'es', 'pt'].map(l => [l, 390]),
  ...['fr', 'nl', 'it', 'es', 'pt'].map(l => [l, 1440]),
];

// Läuft in der Seite
function pruefe() {
  const out = [];
  // Aufklappbares zuerst öffnen: Was darin steht, muss aufgeklappt ebenso passen
  for (const d of document.querySelectorAll('#view details:not([open])')) d.open = true;
  const BOX = 'button, .btn, .kpi, .card, td, th, label, .badge, .tag, .infobox, .art-kachel__titel';
  const sichtbar = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const wischt = (el, bis) => { for (let p = el.parentElement; p && p !== bis; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll') return true; } return false; };
  // L1: Textstücke, die rechts über ihren Kasten hinausragen
  const walker = document.createTreeWalker(document.querySelector('#view') || document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const text = n.textContent.trim();
    if (text.length < 3) continue;
    const el = n.parentElement;
    if (!el || !sichtbar(el) || el.closest('svg, canvas, pre, code, [hidden], .sr-only, .visually-hidden')) continue;
    const box = el.closest(BOX);
    if (!box || wischt(el, box)) continue;
    const range = document.createRange(); range.selectNodeContents(n);
    const right = Math.max(...[...range.getClientRects()].map(r => r.right));
    const br = box.getBoundingClientRect();
    if (right > br.right + 2) out.push(`L1 ragt ${Math.round(right - br.right)} px aus ${box.tagName.toLowerCase()}.${[...box.classList].slice(0, 2).join('.')}: „${text.slice(0, 50)}“`);
  }
  // L2: Knopf mit Text über mehr als zwei Zeilen
  for (const b of document.querySelectorAll('#view button, #view .btn')) {
    if (!sichtbar(b) || b.closest('.tabbar, .mobile-nav')) continue;
    const range = document.createRange(); range.selectNodeContents(b);
    const zeilen = new Set([...range.getClientRects()].filter(r => r.width > 1).map(r => Math.round(r.top))).size;
    if (zeilen > 2) out.push(`L2 Knopf auf ${zeilen} Zeilen: „${b.innerText.trim().slice(0, 50)}“`);
  }
  // L3: Querscrollen
  const quer = document.documentElement.scrollWidth - window.innerWidth;
  if (quer > 1) out.push(`L3 Seite scrollt ${quer} px quer`);
  return out;
}

const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.on('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

const dataDir = mkdtempSync(join(tmpdir(), 'et-len-'));
cpSync(join(ROOT, 'demo-data'), dataDir, { recursive: true });
const port = Number(process.env.ET_LEN_PORT) || await freePort();
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null', ET_AUTH: 'off' }, stdio: 'ignore',
});
const BASE = `http://127.0.0.1:${port}/`;
for (let i = 0; i < 40; i++) {
  const up = await fetch(`${BASE}api.php/api/health`).then(r => r.status < 500).catch(() => false);
  if (up) break;
  await new Promise(r => setTimeout(r, 250));
}
const api = (method, path, body) => fetch(`${BASE}api.php${path}`, {
  method, headers: { 'content-type': 'application/json', 'x-requested-with': 'XMLHttpRequest' }, body: JSON.stringify(body),
});

let seiten = 0, befunde = 0;
const browser = await pw.chromium.launch();
try {
  // Schaufenster: fast alle Verbrauchsarten, Stufe Experte, damit alles zu sehen ist
  ok('Schaufenster geladen', (await api('POST', '/api/demo/import', { force: true, persona: 'showcase' })).status === 200);
  await api('PATCH', '/api/settings', { setup_pending: false, ui_level: 'expert' });
  for (const [lang, width] of LAEUFE) {
    const mobile = width < 900;
    const ctx = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1, colorScheme: 'light', serviceWorkers: 'block' });
    await ctx.addInitScript((l) => { try { localStorage.setItem('et-language', l); } catch { /* */ } }, lang);
    const page = await ctx.newPage();
    await page.goto(`${BASE}#/dashboard`);
    await page.waitForSelector('#view h1, #view .view-header__title', { timeout: 20000 });
    ok(`${lang}: Oberfläche in der Sprache`, await page.evaluate(() => document.documentElement.lang) === lang);
    for (const r of ROUTES) {
      await page.evaluate(x => { location.hash = '#' + x; }, r);
      await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(400);
      seiten++;
      for (const b of await page.evaluate(pruefe)) { befunde++; ok(`${r} (${lang}, ${width} px)`, false, b); }
    }
    await ctx.close();
  }
} finally {
  await browser.close();
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
}
pass++;   // die Runde selbst
console.log(`\n  laengen: ${pass} bestanden, ${fail} fehlgeschlagen (${seiten} Seitenläufe, ${befunde} Befunde)`);
process.exit(fail ? 1 : 0);
