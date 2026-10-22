#!/usr/bin/env node
/* =========================================================================
   webkit-check.mjs — Sichtprüfung in Safari/WebKit wie am iPad (v3.7.0).

   Punkt der Release-Checkliste (docs/entwicklung/release-prozess.md). Lädt
   die laufende App in WebKit (Playwright, Gerät „iPad Pro 11“) bei 820 px
   hochkant und 1180 px quer und prüft je Seite, ohne etwas zu verändern:
     • keine Eingabe unter 16 px Schrift (sonst zoomt Safari beim Fokus und
       die Seite springt),
     • Eingaben und Auswahlfelder einer Seite gleich hoch (Safari behält sonst
       die 26-px-Systemauswahl),
     • Beschriftung über dem Feld (label[for] endet oberhalb),
     • jedes Passwortfeld in einem <form> (sonst legt Safari Vorschläge über
       die Felder),
     • kein waagerechtes Scrollen (scrollWidth ≤ Breite),
     • kein Skriptfehler.

     NODE_PATH=<node_modules mit playwright> node tools/webkit-check.mjs [optionen] [route …]
       --base=http://127.0.0.1:8899   Instanz (Standard: ET_BASE oder http://127.0.0.1:8899)
       --widths=820,1180              Breiten (Höhe: die andere Kante des iPad)
       --login=<passwort>             vorher anmelden (Instanz mit eingeschalteter Anmeldung)
       --shots[=<ordner>]             Bildschirmfotos (Standard: Temp-Ordner), danach löschen
     Routen ohne „#“; Standard: /settings/access /settings/general
     /settings/household /settings/utilities /dashboard

   Ausgabe: eine Zeile je Seite und Befund, am Ende „n Befunde“; Rückgabewert 1
   bei Befunden. Voraussetzung (einmalig, nicht Teil des Repos):
   `npm i playwright && npx playwright install webkit`.
   ========================================================================= */
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Playwright über NODE_PATH oder ein node_modules oberhalb des Repos
const require = createRequire(import.meta.url);
let pw;
try { pw = require('playwright'); } catch {
  console.error('playwright fehlt: npm i playwright && npx playwright install webkit (NODE_PATH auf das node_modules setzen)');
  process.exit(2);
}

const args = process.argv.slice(2);
const opt = (k, d) => (args.find(a => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=') || d;
const BASE = opt('base', process.env.ET_BASE || 'http://127.0.0.1:8899').replace(/\/$/, '');
const SHOTS = args.some(a => a === '--shots' || a.startsWith('--shots='));
const OUT = opt('shots', join(tmpdir(), 'et-webkit'));
const WIDTHS = opt('widths', '820,1180').split(',').map(Number);
const LOGIN = opt('login', '');
const ROUTES = args.filter(a => !a.startsWith('--'));
const routes = ROUTES.length ? ROUTES : ['/settings/access', '/settings/general', '/settings/household', '/settings/utilities', '/dashboard'];
if (SHOTS) mkdirSync(OUT, { recursive: true });

const browser = await pw.webkit.launch();
const findings = [];
for (const w of WIDTHS) {
  const h = w >= 1000 ? 820 : 1180;
  const ctx = await browser.newContext({ ...pw.devices['iPad Pro 11'], viewport: { width: w, height: h }, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  page.on('pageerror', e => findings.push(`${w}px: Skriptfehler ${e.message}`));
  if (LOGIN) {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    const st = await page.evaluate(async (pwd) => (await fetch('api.php/api/session', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: pwd }) })).status, LOGIN);
    if (st !== 200) findings.push(`${w}px: Anmeldung fehlgeschlagen (HTTP ${st})`);
  }
  for (const route of routes) {
    await page.goto(`${BASE}/?wk=${Date.now()}#${route}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const r = await page.evaluate(() => {
      // zugeklappte Bereiche (<details>) zählen nicht — WebKit meldet dort trotzdem offsetParent
      const visible = (el) => !!el.offsetParent && getComputedStyle(el).visibility !== 'hidden' && !el.closest('details:not([open])');
      const ctl = [...document.querySelectorAll('#view input, #view select, #view textarea')]
        .filter(el => visible(el) && !['hidden', 'checkbox', 'radio', 'file', 'range', 'color'].includes(el.type));
      const out = { coarse: matchMedia('(pointer: coarse)').matches, scrollW: document.documentElement.scrollWidth, small: [], heights: {}, labelBelow: [], pwOutside: [] };
      for (const el of ctl) {
        const name = el.id || el.getAttribute('data-key') || el.name || el.className;
        const fs = parseFloat(getComputedStyle(el).fontSize);
        if (fs < 16) out.small.push(`${name} (${fs}px)`);
        if (el.tagName !== 'TEXTAREA') {
          const hgt = Math.round(el.getBoundingClientRect().height);
          (out.heights[hgt] ||= []).push(name);
        }
        const lab = el.id ? document.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
        if (lab && visible(lab) && lab.getBoundingClientRect().bottom > el.getBoundingClientRect().top + 1) out.labelBelow.push(name);
        if (el.type === 'password' && !el.closest('form')) out.pwOutside.push(name);
      }
      return out;
    });
    const tag = `${w}px #${route}`;
    if (!r.coarse) findings.push(`${tag}: Hinweis — (pointer: coarse) nicht emuliert, Touch-Regeln greifen nur über die Breite`);
    if (r.small.length) findings.push(`${tag}: Schrift unter 16 px: ${r.small.join(', ')}`);
    const hs = Object.keys(r.heights);
    if (hs.length > 1) findings.push(`${tag}: ungleiche Feldhöhen ${hs.map(k => `${k}px: ${r.heights[k].slice(0, 4).join(', ')}${r.heights[k].length > 4 ? ' …' : ''}`).join(' | ')}`);
    if (r.labelBelow.length) findings.push(`${tag}: Beschriftung nicht über dem Feld: ${r.labelBelow.join(', ')}`);
    if (r.pwOutside.length) findings.push(`${tag}: Passwortfeld ohne <form>: ${r.pwOutside.join(', ')}`);
    if (r.scrollW > w) findings.push(`${tag}: waagerechtes Scrollen (${r.scrollW} > ${w})`);
    console.log(`${tag}: ${Object.values(r.heights).flat().length} Felder, Höhen ${hs.join('/') || '–'} px, scrollWidth ${r.scrollW}`);
    if (SHOTS) await page.screenshot({ path: join(OUT, `webkit-${w}-${route.replace(/[^a-z0-9]+/gi, '_')}.png`), fullPage: true });
  }
  await ctx.close();
}
await browser.close();
const real = findings.filter(f => !f.includes('Hinweis —'));
for (const f of findings) console.log(`${f.includes('Hinweis —') ? '·' : '✗'} ${f}`);
console.log(`\n${real.length} Befunde (${BASE})${SHOTS ? ` — Fotos unter ${OUT}, danach löschen` : ''}`);
process.exit(real.length ? 1 : 0);
