// =====================================================================
// v3.12.0 (M3) — Prüfung Code → Katalog: Jeder feste Schlüssel im Code steht im Katalog.
//
// `t()` gibt einen fehlenden Schlüssel zurück, und ein Test, der gegen denselben Aufruf vergleicht,
// merkt das nicht (in M2 hat ein gelöschter Schlüssel so die volle Runde überlebt). `CatalogUsageTest`
// prüft nur die andere Richtung: Katalog → Code.
//
// Was das hier NICHT erfasst: zusammengesetzte Schlüssel (`settings.field.${key}.label`). Für sie gibt
// es nur eine Probe: Mindestens ein Schlüssel muss mit dem festen Anfang beginnen. Ein einzelner fehlender
// Wert dahinter fällt nicht auf. Ebenso nicht: Schlüssel in Variablen und in Daten des Servers.
// Die Zahl beider Arten steht am Ende der Ausgabe.
// =====================================================================
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const walk = (d, f = []) => { for (const n of readdirSync(d)) { const p = `${d}/${n}`; if (statSync(p).isDirectory()) walk(p, f); else f.push(p); } return f; };
const flat = (o, p = '', out = new Set()) => {
  for (const [k, v] of Object.entries(o)) {
    const key = p ? `${p}.${k}` : k;
    out.add(key);
    if (v && typeof v === 'object') flat(v, key, out);
  }
  return out;
};
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const catalogs = Object.fromEntries(LANGS.map(l => [l, flat(JSON.parse(readFileSync(`${ROOT}/public/locales/${l}.json`, 'utf8')))]));
const de = catalogs.de;

// Aufrufe, die einen Katalogschlüssel nehmen. PHP: nur am I18n-Dienst, `t()` heißt dort auch anders.
const JS_FIXED = /(?<![\w.$])(?:t|tp|rich|hasTranslation)\(\s*'([A-Za-z][\w-]*(?:\.[\w-]+)+)'/g;
const JS_NAMED = /\b(?:unitKey|placeholderKey|labelKey|colKey|titleKey|hintKey|textKey|messageKey|label|terms|title):\s*(?:\[\s*)?'([a-z][\w-]*(?:\.[\w-]+)+)'/g;
const JS_TERMS = /terms:\s*\[([^\]]*)\]/g;
const JS_COMPOSED = /(?<![\w.$])(?:t|tp|rich|hasTranslation)\(\s*`([A-Za-z][\w-]*(?:\.[\w-]+)*\.?)\$\{/g;
const PHP_FIXED = /(?:->|::)(?:i18n->)?(?:t|tp|translate)\(\s*'([A-Za-z][\w-]*(?:\.[\w-]+)+)'/g;

const stripComments = (src) => src.replace(/^\s*(\/\/|\*|\/\*).*$/gm, '').replace(/\s\/\/ .*$/gm, '');
const fixed = new Map();
const composed = new Map();
const note = (map, key, file) => { if (!map.has(key)) map.set(key, new Set()); map.get(key).add(file.slice(ROOT.length + 1)); };

for (const f of walk(`${ROOT}/public/js`).filter(x => x.endsWith('.js'))) {
  const src = stripComments(readFileSync(f, 'utf8'));
  for (const m of src.matchAll(JS_FIXED)) note(fixed, m[1], f);
  for (const m of src.matchAll(JS_NAMED)) note(fixed, m[1], f);
  for (const m of src.matchAll(JS_TERMS)) for (const k of m[1].matchAll(/'([a-z][\w-]*(?:\.[\w-]+)+)'/g)) note(fixed, k[1], f);
  for (const m of src.matchAll(JS_COMPOSED)) note(composed, m[1], f);
}
for (const f of walk(`${ROOT}/src`).filter(x => x.endsWith('.php'))) {
  const src = stripComments(readFileSync(f, 'utf8'));
  for (const m of src.matchAll(PHP_FIXED)) note(fixed, m[1], f);
}

// Sieht aus wie ein Schlüssel, ist aber keiner: Dateinamen, Pfade
const notKey = (k) => /\.(js|json|md|css|php|pdf|csv|png|svg|html|yaml|yml)$/.test(k);

t('Die Prüfung findet Schlüssel in Oberfläche und Server', fixed.size > 1500, String(fixed.size));
t('… auch solche, die nur im Register der Suche stehen', fixed.has('settings.reading.modeCloud') && fixed.has('meters.merge'));
const missing = [...fixed.keys()].filter(k => !notKey(k) && !de.has(k));
t('Jeder feste Schlüssel steht im Katalog', missing.length === 0, missing.map(k => `${k} (${[...fixed.get(k)].join(', ')})`).join('\n    '));
for (const l of LANGS.slice(1)) {
  const gap = [...fixed.keys()].filter(k => !notKey(k) && de.has(k) && !catalogs[l].has(k));
  t(`${l}: hat dieselben Schlüssel`, gap.length === 0, gap.slice(0, 10).join(' '));
}

const noFamily = [...composed.keys()].filter(p => ![...de].some(k => k.startsWith(p) || k === p.replace(/\.$/, '')));
t('Zusammengesetzte Schlüssel: Zu jedem festen Anfang gibt es Einträge', noFamily.length === 0, noFamily.map(k => `${k} (${[...composed.get(k)].join(', ')})`).join('\n    '));

// Gegenprobe der Prüfung selbst: Ein erfundener Aufruf wird erkannt
const probe = [..."const x = t('gibt.es.nicht');".matchAll(JS_FIXED)].map(m => m[1]);
t('Die Prüfung erkennt einen festen Aufruf', probe[0] === 'gibt.es.nicht');

console.log(`\n  catalog-keys: ${pass} bestanden, ${fail} fehlgeschlagen (${fixed.size} feste Schlüssel, ${composed.size} zusammengesetzte Anfänge nur grob geprüft)`);
process.exit(fail ? 1 : 0);
