// =====================================================================
// v3.12.0 (M4) — Tabellen unter 900 px gestapelt statt quergewischt.
//
// Jede Zeile wird ein kleiner Block: erste Spalte als Titel, darunter „Spalte: Wert“. Die Beschriftung
// kommt aus dem Tabellenkopf (`data-label`), das CSS in components.css zeigt sie nur am schmalen
// Bildschirm. So gilt das für jede Tabelle der App, ohne dass jede Ansicht es wiederholt.
// Prüfung: tests/box-raster.test.mjs (keine Tabelle wischt quer).
// =====================================================================

const TABLE = 'table.table, table.data-table';

/** Beschriftung einer Spalte; was nur für Vorleser dasteht, bleibt am Bildschirm leer. */
function headText(th) {
  if (th.querySelector('.sr-only')) return '';
  return (th.textContent || '').replace(/\s+/g, ' ').trim();
}

/** Eine Tabelle einrichten; Zeilen, die dazukommen, holt der nächste Lauf nach. */
export function stackTable(table) {
  if (table.classList.contains('table--breit')) return;
  // Ohne Kopf stehen die Zellen ohne Beschriftung untereinander
  const heads = [...(table.tHead?.rows[table.tHead.rows.length - 1]?.cells || [])];
  const labels = heads.flatMap(th => Array.from({ length: th.colSpan || 1 }, () => headText(th)));
  table.classList.add('tabelle-stapel');
  for (const body of table.tBodies) {
    for (const row of body.rows) {
      if (row.dataset.gestapelt) continue;
      row.dataset.gestapelt = '1';
      let col = 0;
      for (const cell of row.cells) {
        const span = cell.colSpan || 1;
        cell.dataset.label = span > 1 ? '' : labels[col] || '';
        col += span;
      }
    }
  }
}

export function stackTables(root = document) {
  root.querySelectorAll(TABLE).forEach(stackTable);
}

/** Einmal beim Start: nach jedem Neuzeichnen. Schreibt nur in Zeilen, die neu sind. */
export function startTableStack(root = document.body) {
  if (typeof MutationObserver === 'undefined') return;
  let queued = false;
  const later = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (cb) => setTimeout(cb, 16);
  const run = () => {
    if (queued) return;
    queued = true;
    later(() => { queued = false; stackTables(root); });
  };
  new MutationObserver(run).observe(root, { childList: true, subtree: true });
  run();
}
