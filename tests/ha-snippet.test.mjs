// =====================================================================
// Home-Assistant-Vorlagen: App und Anleitung müssen übereinstimmen (v2.5.3).
//
//   node tests/ha-snippet.test.mjs
//
// Bis v2.5.2 gab es zwei Fassungen desselben Snippets — in den Einstellungen
// und in docs/HOME-ASSISTANT.md —, und keine war richtig: `float(0)` buchte
// bei nicht verfügbarem Sensor einen Zählerstand 0, und `"Bearer !secret …"`
// schickte den Text wörtlich (Review FE-01, DOC-01, DOC-02). Dieser Test
// vergleicht die Vorlagen aus public/js/lib/ha-snippet.js zeilenweise mit
// beiden Sprachfassungen der Anleitung, ohne Kommentare und URL.
// =====================================================================

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { haRestCommandYaml, haSecretsYaml, haAutomationYaml } from '../public/js/lib/ha-snippet.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const DOCS = ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md'];

let failed = 0, passed = 0;
function ok(cond, label) {
  if (cond) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}`);
}
function sameLines(actual, expected, label) {
  const n = Math.max(actual.length, expected.length);
  for (let i = 0; i < n; i++) {
    if (actual[i] !== expected[i]) {
      failed++;
      console.log(`  ✗ ${label}, Zeile ${i + 1}:\n      App:  ${JSON.stringify(expected[i])}\n      Doku: ${JSON.stringify(actual[i])}`);
      return;
    }
  }
  passed++;
}

const yamlBlocks = (md) => [...md.matchAll(/```yaml\n([\s\S]*?)```/g)].map(m => m[1]);

// Kommentare und Leerzeilen weg, URL neutralisieren. Kommentare sind in der
// App übersetzt und in der Doku je Sprache verschieden — verglichen wird die
// Struktur, die Home Assistant auswertet.
const normalize = (yaml) => yaml.split('\n')
  .filter(l => l.trim() !== '' && !l.trim().startsWith('#'))
  .map(l => l.replace(/\s+#\s.*$/, '').replace(/url: ".*"/, 'url: "<URL>"').trimEnd());

const between = (lines, first, last) =>
  lines.slice(lines.findIndex(l => l.startsWith(first)), lines.findIndex(l => l.startsWith(last)) + 1);

const appRest = normalize(haRestCommandYaml('http://example.invalid'));
const appAutomation = normalize(haAutomationYaml([
  { utility: 'strom', meter: 'stromzaehler_haus', sensor: 'sensor.stromzaehler_total_kwh' },
  { utility: 'gas',   meter: 'gaszaehler_haus',   sensor: 'sensor.gaszaehler_total_m3' },
]));

for (const file of DOCS) {
  const md = readFileSync(join(ROOT, file), 'utf8');
  const blocks = yamlBlocks(md);

  const rest = blocks.find(b => b.includes('rest_command:'));
  ok(!!rest, `${file}: REST-Command-Block vorhanden`);
  if (rest) sameLines(normalize(rest), appRest, `${file}: REST-Command wie in der App`);

  const secrets = blocks.find(b => b.trim().startsWith('energietracker_auth:'));
  ok(!!secrets, `${file}: secrets.yaml-Block mit energietracker_auth`);
  if (secrets) {
    ok(/^energietracker_auth: "Bearer et_[^"]+"$/.test(secrets.trim()), `${file}: Secret enthält den ganzen Header-Wert samt „Bearer"`);
    ok(haSecretsYaml().startsWith('energietracker_auth: "Bearer '), 'App: Secret enthält den ganzen Header-Wert');
  }

  // Schritt 4 — die ausführliche Automatisierung; Aktionen wie in der App.
  const step4 = blocks.find(b => b.includes('rest_command.energietracker_push') && b.includes('stromzaehler_haus'));
  ok(!!step4, `${file}: Automatisierung aus Schritt 4 vorhanden`);
  if (step4) {
    sameLines(between(normalize(step4), 'actions:', 'mode:'), between(appAutomation, 'actions:', 'mode:'),
      `${file}: Aktionen der Automatisierung wie in der App`);
  }

  // Jede Vorlage, auch die Anwendungsfälle: kein Ersatzwert, kein halbes
  // !secret, und vor jedem Push eine Verfügbarkeitsprüfung.
  for (const [i, b] of blocks.entries()) {
    ok(!b.includes('float(0)'), `${file}: YAML-Block ${i + 1} ohne float(0)`);
    ok(!/"Bearer !secret/.test(b), `${file}: YAML-Block ${i + 1} ohne "Bearer !secret …"`);
    const pushes = (b.match(/action: rest_command\.energietracker_push/g) || []).length;
    const guards = (b.match(/has_value\(/g) || []).length;
    ok(pushes === guards, `${file}: YAML-Block ${i + 1} prüft vor jedem Push has_value (${guards}/${pushes})`);
  }
}

// Die App-Vorlagen selbst
ok(!haRestCommandYaml('x').includes('float(0)'), 'App: REST-Command ohne float(0)');
ok(haRestCommandYaml('x').includes('Authorization: !secret energietracker_auth'), 'App: !secret als ganzer Wert');
ok(haAutomationYaml([]).includes("has_value('sensor.stromzaehler_total_kwh')"), 'App: Beispiel-Automatisierung ohne Aliase prüft has_value');

console.log(`ha-snippet: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
