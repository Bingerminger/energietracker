// =====================================================================
// Abmelden leert die Caches mit den wirklichen Namen (v3.4.0, N1020).
//
//   node tests/cache-names.test.mjs
//
// sw.js legt seit v3.1.0 `et:<scope>:runtime-<version>` an; das Abmelden
// suchte bis v3.3 nach `et-runtime-…` und ließ API-Antworten (Zähler,
// Verträge, Stände) auf geteilten Geräten liegen. Der Test führt sw.js in
// einer Sandbox aus und gleicht die Namen, die der Worker wirklich anlegt,
// mit public/js/lib/cache-names.js ab — für mehrere Installationen auf einem
// Ursprung (ACC und Prod auf derselben NAS, Home-Assistant-Ingress).
// =====================================================================
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { cachePrefix, isRuntimeCache } from '../public/js/lib/cache-names.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
let failed = 0, passed = 0;
const ok = (cond, label) => {
  if (cond) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}`);
};

/** Namen, die sw.js für eine Installation unter `scope` anlegt. */
function swNames(scope) {
  const src = readFileSync(join(ROOT, 'sw.js'), 'utf8')
    + '\n;globalThis.__names = { PREFIX, STATIC_CACHE, RUNTIME_CACHE };';
  const self = {
    registration: { scope }, location: { origin: new URL(scope).origin },
    addEventListener() {}, skipWaiting() {}, clients: { claim() {} },
  };
  const ctx = vm.createContext({ self, URL, caches: {}, fetch() {}, Response, Headers });
  vm.runInContext(src, ctx);
  return ctx.__names;
}

const scopes = ['https://nas.local/energietracker/', 'http://192.168.178.4:8005/energietracker-acc/', 'http://localhost:8080/'];
for (const scope of scopes) {
  const n = swNames(scope);
  ok(typeof n?.RUNTIME_CACHE === 'string', `${scope}: sw.js liefert Cache-Namen`);
  ok(cachePrefix(scope + 'index.php') === n.PREFIX, `${scope}: Präfix wie in sw.js (${n.PREFIX})`);
  ok(isRuntimeCache(n.RUNTIME_CACHE, scope), `${scope}: Laufzeit-Cache (${n.RUNTIME_CACHE}) wird beim Abmelden geleert`);
  ok(!isRuntimeCache(n.STATIC_CACHE, scope), `${scope}: App-Hülle bleibt (offline startfähig)`);
}
const acc = swNames('https://nas.local/energietracker-acc/');
ok(!isRuntimeCache(acc.RUNTIME_CACHE, 'https://nas.local/energietracker/'), 'eine zweite Installation auf demselben Ursprung bleibt unberührt');
ok(isRuntimeCache('et-runtime-v3.0.0', 'https://nas.local/energietracker/'), 'der alte Name ohne Scope (bis v3.0) wird ebenfalls geleert');

const login = readFileSync(join(ROOT, 'public/js/components/login.js'), 'utf8');
ok(login.includes("import { isRuntimeCache } from '../lib/cache-names.js';"), 'login.js nimmt die Namen aus cache-names.js');
ok(/keys\.filter\(k => isRuntimeCache\(k\)\)/.test(login), 'login.js filtert die Caches mit isRuntimeCache');
ok(!/startsWith\('et-runtime-'\)/.test(login), 'kein fester alter Name mehr in login.js');
const sw = readFileSync(join(ROOT, 'sw.js'), 'utf8');
ok(sw.includes("if (sameOrigin && url.pathname.startsWith(SCOPE_PATH + 'data/')) return;"),
  'sw.js fängt data/ nicht ab (der Selbsttest fragt den Webserver, nicht einen Cache)');
const index = readFileSync(join(ROOT, 'index.php'), 'utf8');
ok(index.includes("var PREFIX = 'et:' + SCOPE + ':';"), 'index.php (Selbstheilung) benutzt dieselbe Präfixform');

console.log(`cache-names: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
