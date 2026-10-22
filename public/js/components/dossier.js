// =====================================================================
// v3.7.0 (Paket betrieb) — Akte je Vertrag und Zähler: alle Dokumente an
// einer Stelle, neueste zuerst — übernommene Post aus dem Posteingang (mit
// ihrer Kernaussage), Rechnungen mit PDF, Belege am Vertrag, Fotos der
// Ablesungen. Aufklappbar unter der Karte; geladen wird erst beim Aufklappen
// (`GET /api/utility/{u}/dossier`). Filter nach Art und Suche im Text.
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml as esc } from '../lib/format.js';
import { dossierText, dossierKind, dossierKindLabel } from '../lib/inbox.js';

/** Platzhalter der Akte in einer Karte. `scope`: {utility, meterId} oder {utility, contractId}. */
export function dossierHtml({ utility, meterId = null, contractId = null }) {
  const attrs = `data-dossier-utility="${esc(utility)}"${meterId ? ` data-dossier-meter="${esc(meterId)}"` : ''}${contractId ? ` data-dossier-contract="${esc(contractId)}"` : ''}`;
  return `<details class="dossier" ${attrs}>
    <summary class="dossier__summary"><span aria-hidden="true">📁</span> ${esc(t('inbox.dossier.title'))}</summary>
    <div class="dossier__body" data-role="dossier-body"><p class="muted small">${esc(t('common.loading'))}</p></div>
  </details>`;
}

/** Ein Eintrag der Akte als Listenzeile. */
export function dossierItemHtml(item) {
  const href = item.inbox_id ? `#/inbox/${encodeURIComponent(item.inbox_id)}` : null;
  const file = item.attachment_id ? api.attachmentUrl(item.attachment_id) : null;
  const kind = dossierKind(item);
  const title = item.title || dossierKindLabel(kind);
  return `<li class="dossier__item" data-kind="${esc(kind)}">
    <span class="dossier__date">${esc(item.date ? fmt.date(item.date) : '–')}</span>
    <span class="dossier__kind badge">${esc(dossierKindLabel(kind))}</span>
    <span class="dossier__main"><span class="dossier__title">${href ? `<a href="${esc(href)}">${esc(title)}</a>` : esc(title)}</span>
      <span class="dossier__text muted small">${esc(dossierText(item))}</span></span>
    ${file ? `<a class="btn btn--ghost btn--sm dossier__open" href="${esc(file)}" target="_blank" rel="noopener" aria-label="${esc(t('attachments.open', { name: title }))}">${esc(t('inbox.dossier.open'))}</a>` : ''}
  </li>`;
}

/** Inhalt der Akte: Filter nach Art, Suche, Liste. */
export function dossierBodyHtml(items) {
  if (!items.length) return `<p class="muted small">${esc(t('inbox.dossier.empty'))}</p>`;
  const kinds = [...new Set(items.map(dossierKind))];
  return `<div class="dossier__tools">
      <select class="select select--sm" data-role="dossier-kind" aria-label="${esc(t('inbox.dossier.filter'))}">
        <option value="">${esc(t('inbox.dossier.allKinds'))}</option>
        ${kinds.map(k => `<option value="${esc(k)}">${esc(dossierKindLabel(k))}</option>`).join('')}
      </select>
      <input class="input input--sm" type="search" data-role="dossier-search" placeholder="${esc(t('inbox.dossier.search'))}" aria-label="${esc(t('inbox.dossier.search'))}">
      <span class="muted small" data-role="dossier-count">${esc(tp('inbox.dossier.count', items.length))}</span>
    </div>
    <ul class="dossier__list" data-role="dossier-list">${items.map(dossierItemHtml).join('')}</ul>`;
}

/** Liste filtern: Art und Suchtext (Titel und Satz, ohne Groß/klein). */
export function filterDossier(items, { kind = '', query = '' } = {}) {
  const q = query.trim().toLowerCase();
  return items.filter(i => (!kind || dossierKind(i) === kind)
    && (!q || `${i.title || ''} ${dossierText(i)} ${dossierKindLabel(dossierKind(i))}`.toLowerCase().includes(q)));
}

/** Alle Akten eines Containers: laden beim ersten Aufklappen, dann filtern. */
export function wireDossiers(container) {
  container.querySelectorAll('details.dossier').forEach(d => {
    let items = null;
    d.addEventListener('toggle', async () => {
      if (!d.open || items !== null) return;
      const body = d.querySelector('[data-role="dossier-body"]');
      try {
        const res = await api.dossier(d.dataset.dossierUtility, { meterId: d.dataset.dossierMeter, contractId: d.dataset.dossierContract });
        items = res?.items || [];
      } catch (e) {
        if (body) body.innerHTML = `<p class="muted small">${esc(t('inbox.dossier.error', { msg: e.message || e }))}</p>`;
        return;
      }
      if (!body) return;
      body.innerHTML = dossierBodyHtml(items);
      const kindSel = body.querySelector('[data-role="dossier-kind"]');
      const search = body.querySelector('[data-role="dossier-search"]');
      const list = body.querySelector('[data-role="dossier-list"]');
      const count = body.querySelector('[data-role="dossier-count"]');
      const redraw = () => {
        const shown = filterDossier(items, { kind: kindSel?.value || '', query: search?.value || '' });
        if (list) list.innerHTML = shown.length ? shown.map(dossierItemHtml).join('') : `<li class="muted small">${esc(t('inbox.dossier.none'))}</li>`;
        if (count) count.textContent = tp('inbox.dossier.count', shown.length);
      };
      kindSel?.addEventListener('change', redraw);
      search?.addEventListener('input', redraw);
    });
  });
}
