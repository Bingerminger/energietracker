// =====================================================================
// v3.7.0 (Haushalt aus Dokumenten) — der Entwurf deines Haushalts aus allen
// ausgelesenen Dokumenten des Posteingangs (#/inbox/draft).
//
// Ein Bildschirm statt einer Liste je Dokument: oben die Zusammenfassung
// („1 Zähler, 1 Vertrag, 2 Ablesungen … aus 3 Dokumenten; 1 Punkt braucht
// dich“), darunter je Verbrauchsart Zähler, Verträge mit Preiskette,
// Abschläge, Ablesungen, Rechnungen und Termine. Ein Haken je Feldgruppe;
// nur Zweifelhaftes fragt nach (markierte Felder bestätigen, bei verschiedenen
// Angaben die richtige wählen). Die Belegstelle (Seite und Zitat) klappt auf
// Antippen auf. Was in keinem Dokument steht, steht freundlich unter „Fehlt
// noch“ — kein Fehler.
//
// Nach dem Übernehmen: was entstanden ist, was noch fehlt (mit Sprung zur
// Stelle) und „Rechnung prüfen“ für die übernommenen Rechnungen. Während der
// Stapel ausgelesen wird (lib/inbox-queue.js), wächst der Entwurf mit.
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml as esc } from '../lib/format.js';
import { toastOk, toastErr, toastWarn } from '../components/toast.js';
import { getUtilities } from '../state.js';
import { DEMO } from '../lib/demo-mode.js';
import { docUrl } from '../lib/docs.js';
import { queueProgress } from '../lib/inbox-queue.js';
import { typeLabel } from '../lib/inbox.js';
import {
  statusText, reasonText, fieldLabel, valueText, basisText, itemText, itemCount, summaryText, summaryNote,
  findingText, missingText, docStateText, initialSelection, needsConfirm, applyPayload, resultLines, billCheckHref,
  blockedBy, dependentsOf, pickItem, dropBlocked,
} from '../lib/inbox-draft.js';

const BADGE = { new: 'badge--info', exists: 'badge--success', conflict: 'badge--danger', overlap: 'badge--warning', update: 'badge--info' };

export async function render(container) {
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  const utilities = await getUtilities().catch(() => []);
  const util = (k) => utilities.find(u => u.key === k) || { key: k, label: k, icon: '', unit: '' };
  let draft;
  let sel;
  let result = null;
  let busy = false;
  let lastDone = queueProgress().done;

  const load = async () => {
    draft = await api.inboxDraft();
    const prev = sel;
    sel = initialSelection(draft);
    if (prev) {
      // Auswahl behalten, soweit es die Einträge noch gibt (der Entwurf wächst beim Auslesen)
      const ids = new Set(draft.items.map(i => i.id));
      for (const id of prev.touched) if (ids.has(id)) { if (prev.items.has(id)) sel.items.add(id); else sel.items.delete(id); }
      for (const [id, v] of prev.confirm) if (ids.has(id)) sel.confirm.set(id, v);
      for (const [id, v] of prev.choose) if (ids.has(id)) sel.choose.set(id, v);
      sel.touched = new Set([...prev.touched].filter(id => ids.has(id)));
    }
    sel.touched ??= new Set();
    dropBlocked(draft, sel);
  };

  try {
    await load();
  } catch (e) {
    container.innerHTML = `${header()}<div class="banner banner--error">${esc(t('inbox.loadError', { msg: e.message || e }))}</div>`;
    return;
  }

  const draw = () => {
    const s = draft.summary || {};
    const byU = draft.utilities.map(k => ({ u: util(k), items: draft.items.filter(i => i.utility === k) }));
    container.innerHTML = `
      ${header()}
      ${DEMO ? `<div class="banner banner--info">${esc(t('inbox.demo.note'))}</div>` : ''}
      <div id="draft-queue">${queueHtml(draft)}</div>
      <div id="draft-result" role="status" aria-live="polite">${result ? resultHtml(result, util) : ''}</div>
      <section class="card draft-summary" id="draft-summary" aria-labelledby="draft-summary-title">
        <h2 class="card__title" id="draft-summary-title">${esc(t('inboxDraft.summaryTitle'))}</h2>
        <p class="draft-summary__text">${esc(summaryText(s))}</p>
        ${summaryNote(s) ? `<p class="muted small">${esc(summaryNote(s))}</p>` : ''}
        ${!s.documents ? `<p><a class="btn btn--primary" href="#/inbox">${esc(t('inboxDraft.toInbox'))}</a></p>` : ''}
      </section>
      ${byU.map(({ u, items }) => utilityHtml(u, items, draft, sel)).join('')}
      ${missingHtml(draft.missing || [], util)}
      ${documentsHtml(draft.documents || [])}
      ${draft.items.some(i => i.actionable) ? applyBarHtml(sel) : ''}`;
    wire();
  };

  const syncBar = () => {
    const btn = container.querySelector('#draft-apply');
    if (!btn) return;
    btn.textContent = tp('inboxDraft.apply', sel.items.size);
    btn.disabled = DEMO || busy || sel.items.size === 0;
  };

  // Preise, Abschläge und Rechnungen eines Vertrags, der erst entsteht: mit seinem Haken gekoppelt
  const syncDependents = (id) => {
    const lis = [...container.querySelectorAll('li[data-item]')];
    for (const d of dependentsOf(draft, id)) {
      const li = lis.find(x => x.dataset.item === d.id);
      if (!li) continue;
      const blocked = !!blockedBy(d, sel);
      li.classList.toggle('draft-item--blocked', blocked);
      const pick = li.querySelector('input[data-pick]');
      if (pick) { pick.checked = sel.items.has(d.id); pick.disabled = blocked || !d.actionable || DEMO; }
      li.querySelectorAll('input[data-confirm]').forEach(x => { x.disabled = blocked || DEMO; });
    }
  };

  const wire = () => {
    container.querySelectorAll('input[data-pick]').forEach(c => c.addEventListener('change', () => {
      const id = c.dataset.pick;
      pickItem(draft, sel, id, c.checked);
      syncDependents(id);
      syncBar();
    }));
    container.querySelectorAll('input[data-confirm]').forEach(c => c.addEventListener('change', () => {
      const id = c.dataset.confirm;
      const set = sel.confirm.get(id) || new Set();
      if (c.checked) set.add(c.dataset.name); else set.delete(c.dataset.name);
      sel.confirm.set(id, set);
      // ein bestätigtes Feld nimmt seinen Eintrag mit
      if (c.checked && !sel.items.has(id)) {
        sel.items.add(id); sel.touched.add(id);
        const pick = [...container.querySelectorAll('input[data-pick]')].find(x => x.dataset.pick === id);
        if (pick) pick.checked = true;
      }
      syncBar();
    }));
    container.querySelectorAll('input[data-choose]').forEach(r => r.addEventListener('change', () => {
      const id = r.dataset.choose;
      const map = sel.choose.get(id) || new Map();
      map.set(r.dataset.name, Number(r.value));
      sel.choose.set(id, map);
      syncBar();
    }));
    container.querySelector('#draft-apply')?.addEventListener('click', apply);
    syncBar();
  };

  const apply = async () => {
    if (busy || DEMO || !sel.items.size) return;
    busy = true;
    syncBar();
    try {
      result = await api.inboxDraftApply(applyPayload(sel));
      if (result.failed?.length) toastWarn(tp('inboxDraft.toast.partly', result.failed.length));
      else toastOk(tp('inbox.toast.applied', result.applied.length));
      window.dispatchEvent(new CustomEvent('et:badges-refresh'));
      sel = null;
    } catch (e) {
      if (e.code === 'errors.inbox.proposalStale') toastWarn(t('inboxDraft.stale'));
      else toastErr(e.message || String(e));
    }
    busy = false;
    try { await load(); } catch (e) { toastErr(e.message || String(e)); }
    if (container.isConnected) draw();
    container.querySelector('#draft-result')?.scrollIntoView?.({ block: 'start' });
  };

  // Auslesen läuft: Fortschritt zeigen, nach jedem fertigen Dokument den Entwurf neu holen
  let timer = null;
  const onQueue = () => {
    const box = container.querySelector('#draft-queue');
    const run = queueProgress();
    if (box) box.innerHTML = queueHtml(draft, run);
    const done = run.done + run.failed;
    if ((done !== lastDone || !run.running) && !busy) {
      lastDone = done;
      clearTimeout(timer);
      timer = setTimeout(async () => {
        try { await load(); if (container.isConnected) draw(); } catch { /* beim nächsten Mal */ }
      }, 600);
    }
  };
  window.addEventListener('et:inbox-queue', onQueue);
  draw();
  return () => { window.removeEventListener('et:inbox-queue', onQueue); clearTimeout(timer); };
}

function header() {
  return `<div class="view-header"><div>
    <a class="inbox-back" href="#/inbox">← ${esc(t('inbox.back'))}</a>
    <h1 class="view-header__title">${esc(t('inboxDraft.title'))}</h1>
    <p class="view-header__subtitle">${esc(t('inboxDraft.subtitle'))} <a href="${esc(docUrl('inboxDraft'))}">${esc(t('common.guide'))}</a></p>
  </div></div>`;
}

/** Fortschritt des Auslesens: der Entwurf wächst mit. */
function queueHtml(draft, run = queueProgress()) {
  if (run.running) {
    return `<div class="banner banner--info draft-queue" role="status" aria-live="polite">
      ${esc(t('inboxDraft.queue.running', { done: run.done + run.failed, total: run.total }))}</div>`;
  }
  const waiting = draft?.queue?.queued || 0;
  if (!waiting) return '';
  return `<div class="banner banner--info draft-queue">${esc(tp('inbox.queue.waiting', waiting))}
    <a href="#/inbox">${esc(t('inboxDraft.queue.resume'))}</a></div>`;
}

function utilityHtml(u, items, draft, sel) {
  const findings = (draft.findings || []).filter(f => f.utility === u.key);
  return `<section class="card draft-utility" data-utility="${esc(u.key)}" aria-labelledby="draft-u-${esc(u.key)}">
    <h2 class="card__title" id="draft-u-${esc(u.key)}"><span aria-hidden="true">${esc(u.icon || '')}</span> ${esc(u.label)}</h2>
    ${findings.length ? `<ul class="draft-findings" aria-label="${esc(t('inboxDraft.findings.title'))}">${findings.map(f => `<li>⚠ ${esc(findingText(f))}</li>`).join('')}</ul>` : ''}
    <ul class="draft-items">${items.map(i => itemHtml(i, u, sel, draft)).join('')}</ul>
  </section>`;
}

function itemHtml(item, u, sel, draft) {
  const on = sel.items.has(item.id);
  const count = itemCount(item);
  const fields = item.fields || [];
  // vorhandene Zeilen langer Listen gesammelt, damit das Neue oben steht
  const shown = fields.filter(f => f.status !== 'exists' || fields.length <= 4);
  const hidden = fields.filter(f => !shown.includes(f));
  // v3.9.0 — hängt an einem Vertrag, der erst entsteht: nur mit ihm (ohne seinen Haken gesperrt)
  const parent = item.requires ? (draft?.items || []).find(i => i.id === item.requires) : null;
  const blocked = !!blockedBy(item, sel);
  return `<li class="draft-item draft-item--${esc(item.status)}${item.check ? ' draft-item--check' : ''}${blocked ? ' draft-item--blocked' : ''}" data-item="${esc(item.id)}" data-kind="${esc(item.kind)}">
    <div class="draft-item__head">
      <label class="draft-item__pick"><input type="checkbox" data-pick="${esc(item.id)}"${on ? ' checked' : ''}${!item.actionable || DEMO || blocked ? ' disabled' : ''}>
        <span class="draft-item__title">${esc(itemText(item))}</span></label>
      <span class="badge ${BADGE[item.status] || ''}">${esc(statusText(item.status))}</span>
      ${item.check && item.actionable ? `<span class="badge badge--warning">${esc(t('inbox.pstatus.check'))}</span>` : ''}
    </div>
    ${item.check && item.actionable ? `<p class="draft-item__reason">${esc(reasonText(item.reasons))} ${esc(t('inboxDraft.tickToConfirm'))}</p>` : ''}
    ${parent && item.actionable ? `<p class="draft-item__requires muted small">${esc(t('inboxDraft.requires', { contract: itemText(parent) }))}</p>` : ''}
    ${item.optional ? `<p class="muted small">${esc(t('inboxDraft.optional'))}</p>` : ''}
    ${count ? `<p class="muted small">${esc(count)}</p>` : ''}
    ${shown.length ? `<ul class="draft-fields">${shown.map(f => fieldHtml(item, f, u, sel)).join('')}</ul>` : ''}
    ${hidden.length ? `<details class="draft-exists"><summary>${esc(tp('inboxDraft.existsRows', hidden.length))}</summary>
      <ul class="draft-fields">${hidden.map(f => fieldHtml(item, f, u, sel)).join('')}</ul></details>` : ''}
  </li>`;
}

function fieldHtml(item, f, u, sel) {
  const exists = f.status === 'exists';
  const confirm = needsConfirm(f) && item.actionable;
  const blocked = !!blockedBy(item, sel);
  const confirmed = sel.confirm.get(item.id)?.has(f.name);
  const chosen = sel.choose.get(item.id)?.get(f.name) ?? 0;
  const basis = basisText(f);
  return `<li class="draft-field${f.check && !exists ? ' draft-field--check' : ''}${exists ? ' draft-field--exists' : ''}" data-field="${esc(f.name)}">
    <div class="draft-field__row">
      <span class="draft-field__label">${esc(fieldLabel(item, f))}</span>
      <span class="draft-field__value">${f.options ? '' : esc(valueText(f, f.value, { unit: u.unit }))}</span>
      ${exists ? `<span class="draft-field__mark muted small">${esc(t('inbox.pstatus.exists'))}</span>` : ''}
    </div>
    ${basis ? `<p class="draft-field__hint muted small">${esc(basis)}</p>` : ''}
    ${f.status === 'conflict' ? `<p class="draft-field__hint small">${esc(t('inboxDraft.existing', { value: valueText(f, f.existing, { unit: u.unit }) }))}</p>` : ''}
    ${f.options && item.actionable ? choiceHtml(item, f, u, chosen) : ''}
    ${confirm ? `<p class="draft-field__confirm"><span class="draft-field__reason">${esc(reasonText(f.reasons))}</span>
      <label><input type="checkbox" data-confirm="${esc(item.id)}" data-name="${esc(f.name)}"${confirmed ? ' checked' : ''}${DEMO || blocked ? ' disabled' : ''}> ${esc(t('inboxDraft.confirm'))}</label></p>` : ''}
    ${!f.options ? sourcesHtml(f.sources) : ''}
  </li>`;
}

/** Verschiedene Angaben in den Dokumenten: die richtige wählen, je mit ihrer Belegstelle. */
function choiceHtml(item, f, u, chosen) {
  const name = `ch-${item.id}-${f.name}`;
  return `<fieldset class="draft-choice"><legend>${esc(t('inboxDraft.choose'))}</legend>
    ${f.options.map((o, i) => `<div class="draft-choice__opt">
      <label><input type="radio" name="${esc(name)}" value="${i}" data-choose="${esc(item.id)}" data-name="${esc(f.name)}"${i === chosen ? ' checked' : ''}${DEMO ? ' disabled' : ''}>
        ${esc(valueText(f, o.value, { unit: u.unit }))}</label>
      ${sourcesHtml(o.sources)}</div>`).join('')}
  </fieldset>`;
}

/** Belegstelle auf Antippen: Dokument, Seite, Zitat. */
function sourcesHtml(sources) {
  const list = (sources || []).filter(s => s.doc);
  if (!list.length) return '';
  return `<details class="draft-src"><summary>${esc(tp('inboxDraft.sources', list.length))}</summary><ul>
    ${list.map(s => `<li><a href="#/inbox/${esc(encodeURIComponent(s.doc))}">${esc(s.name || typeLabel(s.type))}</a>${s.page ? ` · ${esc(t('inbox.doc.page', { n: s.page }))}` : ''}
      ${s.quote ? `<blockquote class="inbox-quote">${esc(s.quote)}</blockquote>` : ''}</li>`).join('')}
  </ul></details>`;
}

function missingHtml(list, util) {
  if (!list.length) return '';
  return `<section class="card draft-missing" id="draft-missing" aria-labelledby="draft-missing-title">
    <h2 class="card__title" id="draft-missing-title">${esc(t('inboxDraft.missing.title'))}</h2>
    <p class="muted small">${esc(t('inboxDraft.missing.hint'))}</p>
    <ul>${list.map(m => `<li>${esc(missingText(m, util(m.utility).label))} ${m.href ? `<a href="${esc(m.href)}">${esc(t('inboxDraft.missing.go'))}</a>` : ''}</li>`).join('')}</ul>
  </section>`;
}

function documentsHtml(docs) {
  if (!docs.length) return '';
  const name = (id) => docs.find(d => d.id === id)?.name || id;
  return `<details class="card draft-docs" id="draft-docs"><summary>${esc(tp('inboxDraft.documents.title', docs.length))}</summary>
    <ul>${docs.map(d => `<li data-doc="${esc(d.id)}"><a href="#/inbox/${esc(encodeURIComponent(d.id))}">${esc(d.name || d.id)}</a>
      · ${esc(typeLabel(d.type))} · <span class="${d.state === 'used' ? 'muted' : ''}">${esc(d.state === 'duplicate'
        ? t('inboxDraft.docState.duplicateOf', { name: name(d.duplicate_of) }) : docStateText(d.state))}</span></li>`).join('')}</ul>
  </details>`;
}

function applyBarHtml(sel) {
  return `<div class="draft-applybar">
    <button type="button" class="btn btn--primary" id="draft-apply"${DEMO ? ' disabled' : ''}>${esc(tp('inboxDraft.apply', sel.items.size))}</button>
    <span class="muted small">${esc(t('inboxDraft.applyHint'))}</span>
  </div>`;
}

function resultHtml(res, util) {
  const lines = resultLines(res);
  return `<section class="card draft-result" aria-labelledby="draft-result-title">
    <h2 class="card__title" id="draft-result-title">${esc(t('inboxDraft.result.title'))}</h2>
    ${lines.length ? `<ul>${lines.map(l => `<li>${esc(l)}</li>`).join('')}</ul>` : `<p>${esc(t('inboxDraft.result.nothing'))}</p>`}
    ${res.failed?.length ? `<div class="banner banner--warning"><p>${esc(tp('inboxDraft.toast.partly', res.failed.length))}</p>
      <ul>${res.failed.map(f => `<li>${esc(f.error || '')}</li>`).join('')}</ul></div>` : ''}
    ${res.bills?.length ? `<p>${res.bills.map(b => `<a class="btn btn--ghost btn--sm" href="${esc(billCheckHref(b))}">${esc(t('inboxDraft.result.billCheck', { utility: util(b.utility).label,
      from: fmtDate(b.period_from), to: fmtDate(b.period_to) }))}</a>`).join(' ')}</p>` : ''}
    ${res.missing?.length ? `<p class="small"><strong>${esc(t('inboxDraft.missing.title'))}:</strong></p>
      <ul>${res.missing.map(m => `<li>${esc(missingText(m, util(m.utility).label))} ${m.href ? `<a href="${esc(m.href)}">${esc(t('inboxDraft.missing.go'))}</a>` : ''}</li>`).join('')}</ul>` : ''}
    ${res.snapshot ? `<p class="muted small">${esc(t('inboxDraft.result.snapshot'))} <a href="#/settings/data">${esc(t('inboxDraft.result.snapshotLink'))}</a></p>` : ''}
    ${res.documents?.open ? `<p class="muted small">${esc(tp('inboxDraft.result.open', res.documents.open))}</p>` : ''}
  </section>`;
}

const fmtDate = (d) => fmt.date(d);
