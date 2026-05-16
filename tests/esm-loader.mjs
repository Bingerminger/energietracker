// ESM-Loader: biegt die App-Module für den JSDOM-Ausführungstest um.
//  - api.js     → BASE zeigt auf den lokalen PHP-Testserver
//  - chart.js   → Chart.js-freier Stub (JSDOM hat kein Canvas-2D)
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const CHART_STUB = `
export function makeChart(canvas, cfg) {
  // Stub: merkt sich nur, dass ein Chart angefordert wurde.
  if (canvas) canvas.setAttribute('data-chart-rendered', '1');
  return { destroy(){}, update(){}, data: cfg && cfg.data };
}
export function themeColors() {
  return { text:'#000', grid:'#ccc', accent:'#4a90e2',
           gas:'#f59e0b', strom:'#10b981', wasser:'#3b82f6',
           fernwaerme:'#f43f5e', heizoel:'#8b5cf6', pellets:'#a16207' };
}
export default { makeChart, themeColors };
`;

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
    if (path.endsWith('/public/js/components/chart.js')) {
      return { format: 'module', source: CHART_STUB, shortCircuit: true };
    }
  }
  return nextLoad(url, context);
}
