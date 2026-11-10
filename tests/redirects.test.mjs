// =====================================================================
// v3.12.0 (Designsprache) — Weiterleitungen im Router: alte Adresse → neue, dauerhaft.
//
// Der Server nennt Adressen selbst (Agenda, Empfehlungen, Rechte, Posteingang,
// Manifest), Home Assistant und Kalender tragen sie nach außen. Der Test
//  • prüft den Mechanismus mit einer gestellten Liste (Query bleibt, Zurück geht
//    nicht auf die alte Adresse, keine Schleife),
//  • listet alle Server-Ausgaben, die Routen nennen, und ruft jede davon auf:
//    Sie muss über Weiterleitung und Router bei einer Ansicht ankommen,
//  • hält fest, dass eine Route, die ROUTES verlässt, in REDIRECTS weiterlebt.
// =====================================================================
import { createRequire } from 'node:module';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
const JS = pathToFileURL(resolve(ROOT, 'public/js')).href;
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const dom = new JSDOM(`<!DOCTYPE html><html><head><title>x</title></head><body>
  <main id="view"></main><div id="toast-stack"></div><div id="modal-root"></div></body></html>`,
{ url: 'http://127.0.0.1/#/start', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'getComputedStyle', 'sessionStorage', 'localStorage']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* */ }
dom.window.scrollTo = () => {};
global.fetch = async () => new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
const { startRouter, redirectFor, routeExists, REDIRECTS } = await import(`${JS}/router.js`);

// ── Mechanismus ──────────────────────────────────────────────────────
const LIST = [
  [/^\/alt$/, '/neu'],
  [/^\/alt\/([a-z]+)$/, (m) => `/neu?art=${m[1]}`],
  [/^\/kette$/, '/alt'],
  [/^\/kreis-a$/, '/kreis-b'],
  [/^\/kreis-b$/, '/kreis-a'],
];
const q = (s) => new URLSearchParams(s);
t('Treffer: feste Adresse', redirectFor('/alt', q(''), LIST) === '#/neu');
t('Kein Treffer: null', redirectFor('/bleibt', q(''), LIST) === null);
t('Query bleibt', redirectFor('/alt', q('field=x&rec=y'), LIST) === '#/neu?field=x&rec=y');
t('Ziel aus dem Pfad', redirectFor('/alt/gas', q(''), LIST) === '#/neu?art=gas');
t('Beide Queries zusammen, die alte Adresse gilt', redirectFor('/alt/gas', q('art=strom&z=1'), LIST) === '#/neu?art=strom&z=1');

const seen = [];
const views = { neu: { section: 'overview', load: async () => ({ render: (c, _p, ctx) => { seen.push(ctx.query.toString()); c.innerHTML = '<h1>Neu</h1>'; } }) } };
const view = document.getElementById('view');
console.error = () => {};
startRouter(view, { routes: [[/^\/neu$/, 'neu']], views, redirects: LIST });
await sleep(20);
const before = history.length;
location.hash = '#/alt?field=x';
await sleep(40);
t('Alte Adresse zeigt die neue Seite', view.textContent.includes('Neu'), view.textContent.trim());
t('Die Adresszeile zeigt die neue Adresse, mit Query', location.hash === '#/neu?field=x', location.hash);
t('Die Ansicht bekommt die Query', seen.at(-1) === 'field=x', seen.join('|'));
t('Kein zusätzlicher Eintrag für die alte Adresse', history.length <= before + 1, `${before} → ${history.length}`);
location.hash = '#/kette';
await sleep(40);
t('Kette über zwei Umzüge kommt an', location.hash === '#/neu' && view.textContent.includes('Neu'), location.hash);
location.hash = '#/kreis-a';
await sleep(40);
t('Ein Kreis hängt nicht', /kreis/.test(location.hash), location.hash);

const walk = (d, f = []) => { for (const n of readdirSync(d)) { const p = `${d}/${n}`; if (statSync(p).isDirectory()) walk(p, f); else f.push(p); } return f; };

// ── Die Liste selbst ─────────────────────────────────────────────────
t('REDIRECTS ist exportiert und eine Liste', Array.isArray(REDIRECTS));
for (const [pattern, to] of REDIRECTS) {
  const sample = typeof to === 'function' ? null : to;
  t(`Weiterleitung ${pattern}: Ziel ist eine Route`, sample === null || routeExists(String(sample).split('?')[0]), String(sample));
}

// ── Jede umgezogene Adresse (v3.12.0 M2): alte Lesezeichen führen weiter, die Query bleibt ──
const FAELLE = [
  ['/reminders', '', '#/todo/dates'],
  ['/reminders', 'add=1', '#/todo/dates?add=1'],
  ['/recommendations', '', '#/todo?art=rec'],
  ['/recommendations', 'rec=rights-a', '#/todo?art=rec&rec=rights-a'],
  ['/activity', '', '#/todo/history'],
  ['/temperatures', '', '#/connections/weather'],
  ['/settings/integrations', '', '#/connections'],
  ['/settings/integrations', 'mcp=value', '#/connections/ai?mcp=value'],
  ['/settings/integrations', 'field=ha_notify_url', '#/connections/homeassistant/notifications?field=ha_notify_url'],
  ['/settings/integrations', 'field=mcp_enabled', '#/connections/ai?field=mcp_enabled'],
  ['/settings/expert', 'field=ocr_endpoint', '#/connections/reading?field=ocr_endpoint'],
  ['/settings/expert', 'field=inbox_model', '#/connections/reading?field=inbox_model'],
  ['/settings/expert', 'field=evcc_endpoint', '#/connections/evcc?field=evcc_endpoint'],
  ['/settings/expert', 'field=home_ca_pem', '#/connections/homenet?field=home_ca_pem'],
  ['/settings/expert', 'field=home_tls_insecure', '#/connections/homenet?field=home_tls_insecure'],
  // v3.12.0 (M3)
  ['/utility/gas/meters', '', '#/household/meters?art=gas'],
  ['/utility/gas/meters', 'replace=m1&date=2026-01-02', '#/household/meters?art=gas&replace=m1&date=2026-01-02'],
  ['/utility/strom/meters', 'concept=1', '#/household/meters?art=strom&concept=1'],
  ['/settings/general', '', '#/settings'],
  ['/settings/general', 'field=dashboard_months', '#/settings/display?field=dashboard_months'],
  ['/settings/general', 'field=language', '#/settings/region?field=language'],
  ['/settings', 'field=contract_remind_days_1', '#/settings/display?field=contract_remind_days_1'],
  ['/settings/household', '', '#/household/building'],
  ['/settings/household', 'field=wohnflaeche_m2', '#/household/building?field=wohnflaeche_m2'],
  // v3.12.0 — „Personen verwalten“ aus v3.11 führte mit diesem Feld auf Gebäude, die Personen stehen unter Personen
  ['/settings/household', 'field=person_name', '#/household/people?field=person_name'],
  ['/settings/utilities', '', '#/household/utilities'],
  ['/settings/utilities', 'field=billing_cycle_anchor_gas', '#/household/utilities?field=billing_cycle_anchor_gas'],
  ['/settings/utilities', 'field=co2_strom', '#/settings/calculation?field=co2_strom'],
  ['/settings/expert', '', '#/settings/calculation'],
  ['/settings/expert', 'field=attachments_max_mb', '#/settings/data?field=attachments_max_mb'],
];
for (const [path, query, want] of FAELLE) {
  const got = redirectFor(path, q(query));
  t(`Lesezeichen #${path}${query ? `?${query}` : ''} führt nach ${want}`, got === want, String(got));
  t(`… und dort ist eine Seite: ${want}`, routeExists(want.slice(1).split('?')[0]));
}
t('Experte heißt jetzt Rechnen', redirectFor('/settings/expert', q('')) === '#/settings/calculation' && redirectFor('/settings/expert', q('field=min_days_period')) === '#/settings/calculation?field=min_days_period');
t('Zugriff bleibt, wo es ist', redirectFor('/settings/access', q('')) === null);

// Oberfläche, Wegweiser, Assistent, Demo: keine Datei nennt noch eine alte Adresse, außer der Liste selbst
const OLD = /#\/(reminders|recommendations|activity|temperatures|settings\/(integrations|household|utilities|expert|general)|utility\/[^/'"`\s]+\/meters)\b/;
const uiFiles = [...walk(resolve(ROOT, 'public/js')).filter(x => x.endsWith('.js') && !x.endsWith('/router.js')), ...walk(resolve(ROOT, 'src')).filter(x => x.endsWith('.php'))];
const stale = uiFiles.filter(x => readFileSync(x, 'utf8').split('\n').some(l => OLD.test(l) && !/^\s*(\*|\/\/)/.test(l)));
t('Kein Code und kein Server nennt noch eine alte Adresse', stale.length === 0, stale.map(x => x.slice(ROOT.length + 1)).join(', '));
for (const lang of ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl']) {
  const hit = OLD.test(readFileSync(resolve(ROOT, `public/locales/${lang}.json`), 'utf8'));
  t(`Katalog ${lang} nennt keine alte Adresse`, !hit);
}

// ── Adressen, die der Server ausgibt ─────────────────────────────────
// Webhook-Pfade und API-Versionen sind keine Seiten der App, `#/…#` in PHP ist ein regulärer Ausdruck
const FALSE_HITS = /^#\/(api\b|v\d*$|[^a-z]|$)/;
const found = new Map();
for (const abs of [...walk(resolve(ROOT, 'src')).filter(x => x.endsWith('.php')), resolve(ROOT, 'manifest.webmanifest')]) {
  const rel = abs.slice(ROOT.length + 1);
  readFileSync(abs, 'utf8').split('\n').forEach((line) => {
    if (/^\s*(\*|\/\/|\/\*)/.test(line)) return;
    for (const m of line.matchAll(/#\/[a-zA-Z0-9_\-/$.{}]*/g)) {
      if (FALSE_HITS.test(m[0])) continue;
      // PHP setzt Teile ein: `'#/utility/' . $key` → Beispielwert
      const path = m[0].slice(1).replace(/\/$/, '').replace(/\{\$?[a-zA-Z_]+\}|\$[a-zA-Z_]+/g, 'gas');
      if (!found.has(path)) found.set(path, new Set());
      found.get(path).add(rel);
    }
  });
}
const sites = [...found.values()].flatMap(s => [...s]);
// Was heute Adressen nennt (die Liste für die Umzüge ab M2). Eine neue Quelle muss hier eingetragen werden.
const QUELLEN = ['src/Controllers/ManifestController.php', 'src/Services/AgendaService.php', 'src/Services/BillExpectedService.php',
  'src/Services/Inbox/Gist.php', 'src/Services/Inbox/HouseholdDraft.php', 'src/Services/RecommendationService.php', 'src/Services/RightsService.php', 'manifest.webmanifest'];
const unknown = [...new Set(sites)].filter(s => !QUELLEN.includes(s));
t('Alle Server-Ausgaben mit Adressen sind bekannt', unknown.length === 0, unknown.join(', '));
for (const q2 of QUELLEN) t(`Quelle nennt weiter Adressen: ${q2}`, sites.includes(q2));
const known = (p) => {
  let path = p;
  for (let i = 0; i < 5; i++) { const to = redirectFor(path); if (!to) break; path = to.slice(1).split('?')[0]; }
  return routeExists(path);
};
for (const [path, from] of found) {
  // Ein abgeschnittener Pfad (`#/utility/` + Art) wird mit einem Beispielwert aufgerufen
  const probes = path === '/utility' ? ['/utility/gas', '/utility/gas/meters', '/utility/gas/contracts'] : path === '/inbox' ? ['/inbox', '/inbox/x'] : [path];
  t(`Adresse kommt an: ${path}`, probes.every(known), [...from].join(', '));
}
t('Mindestens acht verschiedene Adressen geprüft', found.size >= 8, [...found.keys()].join(' '));

// ── v3.12.0 (M6) — Doku und Einstiegsdateien: die neue Navigation ─────
// Jede Adresse `#/…` ist eine heutige Route, keine, die erst eine Weiterleitung findet, und kein Satz führt
// über die alten Seiten (Einstellungen → Allgemein, Integrationen, Experte, Zugriff, Hinweise …). Ausgenommen:
// Zeilen, die die Weiterleitung selbst erklären, fremde Oberflächen (Open WebUI, GitHub) und v3.3.0 (Migration).
{
  const docFiles = [...walk(resolve(ROOT, 'docs')).filter(x => x.endsWith('.md')),
    ...['README.md', 'README.de.md', 'INSTALL.md', 'INSTALL.de.md', 'CONTRIBUTING.md', 'CONTRIBUTING.de.md'].map(x => resolve(ROOT, x))];
  const EXEMPT = /Open WebUI|Social preview|3\.3\.0|führen weiter|lead on|Weiterleitung|redirect|umgezogen|moved/i;
  const OLD_NAMES = [
    /Einstellungen\**\s*(?:→|›)\s*\**\s*(?:Allgemein|Integrationen|Experte|Zugriff\b|Haushalt|Verbrauchsarten|Wetterdaten|Daten(?! und Sicherung))/,
    /Settings\**\s*(?:→|›)\s*\**\s*(?:General|Integrations|Expert\b|Access(?! and security)|Household|Utilities|Weather|Data(?! and backup))/,
    /Hinweise\s*(?:→|›)\s*(?:Erledigt|Empfehlungen|Termine)|Reminders & tips\s*(?:→|›)/,
  ];
  const oldRoutes = [], oldNames = [];
  let routes = 0;
  for (const abs of docFiles) {
    const rel = abs.slice(ROOT.length + 1);
    let inCode = false;
    readFileSync(abs, 'utf8').split('\n').forEach((line, i) => {
      if (/^\s*(```|~~~)/.test(line)) { inCode = !inCode; return; }
      if (inCode || EXEMPT.test(line) || /^<!-- (umgezogen|moved):/.test(line)) return;
      for (const m of line.matchAll(/#\/[a-z][\w/-]*(?:\?[\w=&<>.-]*)?/g)) {
        if (m[0].startsWith('#/help/doc/')) continue;
        const [p, qs = ''] = m[0].replace(/[<>]/g, 'x').slice(1).split('?');
        const path = p.replace(/\/$/, '').replace(/…$/, '');
        if (/^\/(pfad|path)$/.test(path)) continue;   // Platzhalter in der Architektur-Doku
        routes++;
        if (redirectFor(path, q(qs))) oldRoutes.push(`${rel}:${i + 1} ${m[0]}`);
        else if (!routeExists(path)) oldRoutes.push(`${rel}:${i + 1} ${m[0]} (unbekannt)`);
      }
      if (OLD_NAMES.some(re => re.test(line))) oldNames.push(`${rel}:${i + 1}`);
    });
  }
  t(`Doku: jede Adresse #/… ist eine heutige Route (${routes} geprüft)`, oldRoutes.length === 0 && routes > 30, oldRoutes.slice(0, 6).join(' · '));
  t('Doku: kein Weg über die alten Seiten der Einstellungen', oldNames.length === 0, oldNames.slice(0, 8).join(' · '));
}

console.log(`\n  redirects: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
