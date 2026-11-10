// =====================================================================
// v3.12.0 (Sichtung S8) — Zähler als Baum: Unterzähler stehen eingerückt unter ihrem Hauptzähler,
// verbunden mit einer feinen Linie im Stil des Messkonzepts (`.zaehlerbaum` in components.css).
//
// Der Baustein kennt nur `id` und `parent_meter_id`; was eine Zeile zeigt, sagt der Aufrufer.
// Der Server erlaubt heute eine Ebene (MeterService), der Baum zeigt auch tiefere, und er verliert
// keinen Zähler: Ohne vorhandenen Elternzähler oder in einem Ring steht er oben.
// Verschachtelte Listen: Reihenfolge für Tastatur und Vorleser bleibt die Reihenfolge im Baum.
// =====================================================================

/**
 * @param {Array<{id: string, parent_meter_id?: ?string}>} meters
 * @returns {Array<{meter: object, depth: number, children: Array}>} Wurzeln, Reihenfolge wie geliefert
 */
export function meterTree(meters) {
  const list = Array.isArray(meters) ? meters : [];
  const ids = new Set(list.map(m => m.id));
  const kids = new Map();
  const roots = [];
  for (const m of list) {
    const p = m.parent_meter_id;
    if (p && p !== m.id && ids.has(p)) {
      if (!kids.has(p)) kids.set(p, []);
      kids.get(p).push(m);
    } else {
      roots.push(m);
    }
  }
  const seen = new Set();
  const node = (meter, depth) => {
    seen.add(meter.id);
    const children = (kids.get(meter.id) || []).filter(c => !seen.has(c.id)).map(c => node(c, depth + 1));
    return { meter, depth, children };
  };
  const tree = roots.map(m => node(m, 0));
  // Ein Ring (a → b → a) hat keine Wurzel: Der erste Zähler daraus steht oben, keiner geht verloren
  for (const m of list) if (!seen.has(m.id)) tree.push(node(m, 0));
  return tree;
}

/**
 * @param {Array<object>} meters
 * @param {(meter: object, depth: number) => string} card  HTML einer Zeile; `depth` 0 = Hauptzähler
 * @returns {string} leer ohne Zähler
 */
export function meterTreeHtml(meters, card) {
  const tree = meterTree(meters);
  if (!tree.length) return '';
  const items = (nodes) => nodes.map(n => `<li class="zaehlerbaum__knoten" data-depth="${n.depth}">${card(n.meter, n.depth)}${
    n.children.length ? `<ul class="zaehlerbaum__kinder">${items(n.children)}</ul>` : ''}</li>`).join('');
  return `<ul class="zaehlerbaum" data-role="meter-tree">${items(tree)}</ul>`;
}
