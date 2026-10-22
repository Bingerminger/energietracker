// =====================================================================
// v3.7.0 (Posteingang) — das Dokument neben dem Formular.
//
// „Von Hand erfassen“ öffnet das vorhandene Formular (Vertrag, Rechnung,
// Zählerstand …) — und das Dokument bleibt daneben stehen: ein Fenster am
// rechten Rand, außerhalb der Ansicht, das Seitenwechsel übersteht. Ein
// Dialog lässt es frei (CSS: `body.doc-dock-open`), man kann darin blättern,
// während man abschreibt. Auf schmalen Bildschirmen klappt es auf und zu.
// Schließen nur von Hand.
// =====================================================================

import { t } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { pdfSupported, openPdf, renderPages } from '../lib/pdf.js';

let cleanup = null;

/**
 * @param {{url: string, name: string, back?: string}} doc  `back`: Adresse des Eintrags im Posteingang
 */
export function openDocDock({ url, name, back = '' }) {
  closeDocDock();
  const dock = document.createElement('aside');
  dock.id = 'doc-dock';
  dock.className = 'doc-dock';
  dock.setAttribute('aria-label', t('inbox.dock.label'));
  dock.innerHTML = `
    <div class="doc-dock__head">
      <button type="button" class="doc-dock__fold btn btn--ghost btn--sm" aria-expanded="true" data-act="fold">📄 <span class="doc-dock__name">${esc(name)}</span></button>
      <span class="doc-dock__actions">
        ${back ? `<a class="btn btn--ghost btn--sm" href="${esc(back)}">${esc(t('inbox.dock.back'))}</a>` : ''}
        <a class="btn btn--ghost btn--sm" href="${esc(url)}" target="_blank" rel="noopener">${esc(t('inbox.doc.open'))}</a>
        <button type="button" class="btn btn--ghost btn--sm" data-act="close" aria-label="${esc(t('inbox.dock.close'))}">✕</button>
      </span>
    </div>
    <div class="doc-dock__body" id="doc-dock-body"></div>`;
  document.body.append(dock);
  document.body.classList.add('doc-dock-open');
  dock.querySelector('[data-act="close"]').addEventListener('click', closeDocDock);
  dock.querySelector('[data-act="fold"]').addEventListener('click', (e) => {
    const folded = dock.classList.toggle('doc-dock--folded');
    e.currentTarget.setAttribute('aria-expanded', String(!folded));
  });
  const body = dock.querySelector('#doc-dock-body');
  if (!pdfSupported()) {
    body.innerHTML = `<p class="muted">${esc(t('inbox.doc.noViewer'))}</p>`;
    return;
  }
  let stop = null;
  let dead = false;
  cleanup = () => { dead = true; stop?.(); };
  openPdf(url).then((doc) => {
    if (dead) return;
    stop = renderPages(doc, body, { label: (n) => t('inbox.doc.page', { n }) });
  }).catch(() => {
    if (!dead) body.innerHTML = `<p class="muted">${esc(t('inbox.doc.noViewer'))}</p>`;
  });
}

export function closeDocDock() {
  cleanup?.();
  cleanup = null;
  document.getElementById('doc-dock')?.remove();
  document.body.classList.remove('doc-dock-open');
}
