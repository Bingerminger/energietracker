// ESM-Loader: biegt die App-Module für den JSDOM-Ausführungstest um.
//  - api.js     → BASE zeigt auf den lokalen PHP-Testserver
//
// v2.15.0 — components/chart.js wird nicht mehr ersetzt. Bis v2.14 lud der
// Test an seiner Stelle einen Stub aus der Zeit, als Chart.js ein ES-Modul
// war; die echte Chart-Schicht (Registry, Farben, Kurzbeschreibungen,
// Datentabellen) lief im Test nie. Chart.js selbst kommt im Browser als
// globales `Chart` aus public/vendor/ — dafür setzt browser-render.test.mjs
// ein aufzeichnendes Stub auf `window.Chart` (JSDOM hat kein Canvas-2D).
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

export async function load(url, context, nextLoad) {
  if (url.startsWith('file://')) {
    const path = fileURLToPath(url);
    if (path.endsWith('/public/js/api.js')) {
      let src = await readFile(path, 'utf8');
      src = src.replace(
        "const BASE = 'api.php';",
        "const BASE = 'http://127.0.0.1:8899/api.php';"
      );
      return { format: 'module', source: src, shortCircuit: true };
    }
  }
  return nextLoad(url, context);
}
