// =====================================================================
// v3.12.0 (Sichtung S8) — Zähler als Baum (lib/meter-tree.js): Reihenfolge, Ebenen, kein Zähler geht verloren.
// Das Layout (Einrückung, Linie, iPhone) prüft tests/sichtung-layout.test.mjs.
// Aufruf: node tests/zaehlerbaum.test.mjs
// =====================================================================
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const { meterTree, meterTreeHtml } = await import(`${ROOT}/public/js/lib/meter-tree.js`);
let pass = 0, fail = 0;
const t = (name, ok, info = '') => { if (ok) { pass++; return; } fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); };
const shape = (nodes) => nodes.map(n => (n.children.length ? `${n.meter.id}(${shape(n.children)})` : n.meter.id)).join(',');

t('Ohne Zähler: nichts', meterTree([]).length === 0 && meterTree(null).length === 0 && meterTreeHtml([], () => 'x') === '');
t('Flach: Reihenfolge wie geliefert', shape(meterTree([{ id: 'a' }, { id: 'b' }])) === 'a,b');
t('Unterzähler unter dem Hauptzähler, auch wenn er vor ihm steht',
  shape(meterTree([{ id: 's1', parent_meter_id: 'h' }, { id: 'h' }, { id: 's2', parent_meter_id: 'h' }])) === 'h(s1,s2)');
t('Mehrere Ebenen', shape(meterTree([{ id: 'a' }, { id: 'b', parent_meter_id: 'a' }, { id: 'c', parent_meter_id: 'b' }])) === 'a(b(c))');
t('Tiefe stimmt', meterTree([{ id: 'a' }, { id: 'b', parent_meter_id: 'a' }, { id: 'c', parent_meter_id: 'b' }])[0].children[0].children[0].depth === 2);
t('Elternzähler fehlt: steht oben', shape(meterTree([{ id: 'x', parent_meter_id: 'weg' }])) === 'x');
t('Auf sich selbst verwiesen: steht oben', shape(meterTree([{ id: 'x', parent_meter_id: 'x' }])) === 'x');
const ring = meterTree([{ id: 'a', parent_meter_id: 'b' }, { id: 'b', parent_meter_id: 'a' }, { id: 'z' }]);
const alle = (nodes) => nodes.flatMap(n => [n.meter.id, ...alle(n.children)]);
t('Ring: kein Zähler verloren, keiner doppelt', alle(ring).sort().join() === 'a,b,z', shape(ring));

const html = meterTreeHtml([{ id: 'h', name: 'H' }, { id: 's', parent_meter_id: 'h', name: 'S' }, { id: 'g', parent_meter_id: 's', name: 'G' }], (m, d) => `<div>${m.name}:${d}</div>`);
t('HTML: verschachtelte Listen', /^<ul class="zaehlerbaum" data-role="meter-tree"><li class="zaehlerbaum__knoten" data-depth="0"><div>H:0<\/div><ul class="zaehlerbaum__kinder"><li class="zaehlerbaum__knoten" data-depth="1"><div>S:1<\/div><ul class="zaehlerbaum__kinder"><li class="zaehlerbaum__knoten" data-depth="2"><div>G:2<\/div><\/li><\/ul><\/li><\/ul><\/li><\/ul>$/.test(html), html);

const views = readFileSync(`${ROOT}/public/js/views/meters.js`, 'utf8');
t('Zählerseite nutzt den Baustein', /meterTreeHtml\(/.test(views) && !/margin-left:32px/.test(views) && !/↳/.test(views));

console.log(`\n  zaehlerbaum: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
