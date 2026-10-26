// =====================================================================
// v3.7.0 — Posteingang für Versorgerpost.
//
// Liste (#/inbox): PDF ablegen (Dateiauswahl, am iPad aus der Dateien-App,
// oder Ziehen), Status je Dokument, Auslesen einzeln oder alle (Warteschlange
// mit Fortschritt und Abbrechen), danach die Prüfliste mit nur den markierten
// Feldern (#/inbox?view=review).
//
// Vorschau (#/inbox/<id>): links das Dokument (pdf.js), rechts Art und
// Auslesen, die Zuordnung (Verbrauchsart, Zähler, Vertrag — vorbelegt,
// änderbar), die Felder mit ihrer Belegstelle (markierte hervorgehoben,
// bestätigen oder korrigieren) und die vorgeschlagenen Änderungen mit dem
// Abgleich gegen den Bestand. Übernommen wird nur, was angehakt ist — über
// dieselben Dienste wie die Handeingabe. „Von Hand erfassen“ öffnet das
// passende Formular, das Dokument bleibt daneben (components/doc-dock.js).
//
// Ohne Dienst zum Auslesen bleibt alles außer dem Auslesen: ablegen, zuordnen,
// von Hand erfassen. Ein Anbieter außerhalb des Heimnetzes liest erst nach
// Zustimmung („Dieses Dokument geht an …“).
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml as esc, parseDecimal, formatForInput } from '../lib/format.js';
import { toastOk, toastErr, toastWarn } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { activeUtilities } from '../state.js';
import { DEMO } from '../lib/demo-mode.js';
import { docUrl } from '../lib/docs.js';
import { pdfSupported, openPdf, renderPages } from '../lib/pdf.js';
import { readDocument, runQueue, runScope, cancelQueue, queueProgress } from '../lib/inbox-queue.js';
import {
  openCount, TYPES, typeLabel, statusLabel, fieldLabel, reasonText, valueText, itemText,
  proposalText, detailText, orderedFields, manualTargets,
  gistText, gistFigure, gistActionLabel, gistDetails,
  proposalLines,   // v3.9.0 (F1034) — Aufschlüsselung der Preise im Vorschlag
} from '../lib/inbox.js';
import { openDocDock } from '../components/doc-dock.js';
import { draftEntryHtml } from '../lib/inbox-draft.js';   // v3.7.0 (Haushalt aus Dokumenten)
import { readingSetupButtonHtml, openReadingSetup } from '../components/reading-setup.js';   // v3.7.0 — „Auslesen einrichten“ als Dialog
import { fieldHints, proposalNotes, duplicateHtml } from '../lib/inbox-marks.js';   // v3.7.0 (treffsicher)
import { scanFolder } from '../lib/inbox-folder.js';
import { priceBasisText, grossOf } from '../lib/price-basis.js';   // v3.7.0 — Basis eines Preises am Eingabefeld

// v3.7.0 (Paket betrieb) — Kernaussage je Dokument: ein Satz, eine Zahl, ein Knopf, der Rest aufklappbar

/** Knopf der Kernaussage. In der Liste führen „Übernehmen“ und „Termin“ in die Vorschau. */
function gistActionHtml(g, { entryId = '', inList = false } = {}) {
  const a = g?.action;
  if (!a || DEMO && a.kind !== 'link') return '';
  const label = esc(gistActionLabel(a));
  if (a.kind === 'link') return `<a class="btn btn--primary btn--sm" data-gist-action="link" href="${esc(a.href)}">${label}</a>`;
  if (inList && (a.kind === 'apply' || a.kind === 'remind')) {
    return `<a class="btn btn--primary btn--sm" data-gist-action="${esc(a.kind)}" href="#/inbox/${esc(encodeURIComponent(entryId))}">${label}</a>`;
  }
  if (a.kind === 'remind') {
    return `<span class="inbox-gist__remind"><label class="sr-only" for="inbox-remind-date">${esc(t('inbox.gist.remindDate'))}</label>
      <input class="input" type="date" id="inbox-remind-date" value="${esc(a.due || '')}">
      <button type="button" class="btn btn--primary btn--sm" data-gist-action="remind">${label}</button></span>`;
  }
  return `<button type="button" class="btn btn--primary btn--sm" data-gist-action="${esc(a.kind)}"${entryId ? ` data-id="${esc(entryId)}"` : ''}>${label}</button>`;
}

/**
 * Karte „Was heißt das für dich?“ in der Vorschau. `extra`: Hinweis, der mit der
 * Kernaussage reist — v3.7.0 (treffsicher) „Wohl dasselbe Dokument wie …“, am
 * iPhone damit vor dem Dokument.
 */
function gistCardHtml(g, extra = '') {
  if (!g) return '';
  const details = gistDetails(g);
  const figure = gistFigure(g.figure);
  return `<div class="card inbox-card inbox-gist inbox-gist--${esc(g.figure?.tone || 'neutral')}" id="inbox-gist" data-gist="${esc(g.key)}">
    <h2 class="card__title">${esc(t('inbox.gist.title'))}</h2>
    ${figure ? `<p class="inbox-gist__figure" data-role="gist-figure">${esc(figure)}</p>` : ''}
    <p class="inbox-gist__text" data-role="gist-text">${esc(gistText(g))}</p>
    ${extra}
    ${g.action ? `<p class="inbox-gist__action">${gistActionHtml(g)}</p>` : `<p class="muted small">${esc(t('inbox.gist.nothingToDo'))}</p>`}
    ${details.length ? `<details class="inbox-gist__more"><summary>${esc(t('inbox.gist.more'))}</summary>
      <ul>${details.map(d => `<li>${esc(d)}</li>`).join('')}</ul></details>` : ''}
  </div>`;
}

/** Zeile der Kernaussage unter dem Dokumentnamen in der Liste. */
function gistLineHtml(e) {
  if (!e.gist) return '';
  const figure = gistFigure(e.gist.figure);
  // v3.7.0 (treffsicher) — dasselbe Schreiben in einer zweiten Datei: kenntlich in der Liste
  const dup = e.duplicate_of ? ` · <span data-role="duplicate">${esc(t('inbox.duplicate.short'))}</span>` : '';
  return `<div class="inbox-gist-line inbox-gist-line--${esc(e.gist.figure?.tone || 'neutral')}" data-role="gist">${figure ? `<strong>${esc(figure)}</strong> · ` : ''}${esc(gistText(e.gist))}${dup}</div>`;
}

/** Hinweis auf die anderen Wege in den Posteingang: Kurzbefehl, Ordner, E-Mail über Home Assistant. */
function channelsHtml(folder) {
  if (DEMO) return '';
  const pending = folder?.pending ? ` ${esc(tp('inbox.channels.pending', folder.pending))}` : '';
  return `<p class="muted small inbox-channels" id="inbox-channels">${esc(t('inbox.channels.text', { dir: folder?.dir || 'posteingang' }))}${pending}
    <a href="${esc(docUrl('inbox', 'inbox-shortcut'))}">${esc(t('inbox.channels.shortcut'))}</a> ·
    <a href="${esc(docUrl('inbox', 'inbox-folder'))}">${esc(t('inbox.channels.folder'))}</a> ·
    <a href="${esc(docUrl('inbox', 'inbox-email'))}">${esc(t('inbox.channels.email'))}</a></p>`;
}

export async function render(container, params = [], ctx = {}) {
  if (params[0]) return renderDetail(container, params[0], ctx);
  return renderList(container, ctx);
}

const header = (title, sub, back = '') => `
  <div class="view-header">
    <div>
      ${back ? `<a class="inbox-back" href="#/inbox">← ${esc(t('inbox.back'))}</a>` : ''}
      <h1 class="view-header__title">${esc(title)}</h1>
      ${sub ? `<p class="view-header__subtitle">${sub}</p>` : ''}
    </div>
  </div>`;

/** Hinweis zum Dienst: wer ausliest, oder wie man es einrichtet. */
function providerHtml(p) {
  if (DEMO) return `<div class="banner banner--info" data-role="provider">${esc(t('inbox.demo.note'))}</div>`;
  if (!p) return '';
  if (!p.known) return `<div class="banner banner--warning" data-role="provider">${esc(t('errors.inbox.providerUnknown', { provider: p.id }))}</div>`;
  if (!p.available && !p.local) {
    // v3.7.0 (cloud) — gewählt, aber nicht bereit (aus oder ohne Schlüssel, etwa nach einer Sicherung von einem anderen Server)
    return `<div class="banner banner--warning" data-role="provider">${esc(t('inbox.provider.cloudOff', { service: p.label }))}
      <a href="#/settings/expert">${esc(t('inbox.provider.setup'))}</a></div>`;
  }
  if (!p.available) {
    return `<div class="banner banner--info" data-role="provider">${esc(t('inbox.provider.off', { model: p.recommended_model }))}
      <a href="#/settings/expert?field=ocr_endpoint">${esc(t('inbox.provider.setup'))}</a></div>`;
  }
  return `<p class="muted small" data-role="provider">${esc(t(p.local ? 'inbox.provider.on' : 'inbox.provider.onCloud', { service: p.label, model: p.model || '–' }))}</p>`;
}

/**
 * Zustimmung, bevor ein Dokument das Heimnetz verlässt: „Dieses Dokument geht
 * an …“ mit dem Ziel (Host) und dem Hinweis des Anschlusses (was hinausgeht,
 * wer es sieht) — beides liefert der Server (`provider.destination`, `.notice`).
 */
async function consentFor(provider, count = 1) {
  if (!provider || provider.local) return false;
  const to = provider.destination ? `${provider.label} – ${provider.destination}` : provider.label;
  const ok = await confirmModal({
    title: t('inbox.consent.title'),
    message: tp('inbox.consent.text', count, { provider: to }),
    detail: [provider.notice || '', provider.experimental ? t('inboxCloud.experimental') : ''].filter(Boolean).join(' '),
    confirmLabel: t('inbox.consent.ok'),
  });
  if (!ok) throw Object.assign(new Error('consent'), { code: 'consent' });
  return true;
}

const announce = (entries) => window.dispatchEvent(new CustomEvent('et:inbox', { detail: openCount(entries) }));

// ── Liste ────────────────────────────────────────────────────────────────

async function renderList(container, ctx = {}) {
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  let data;
  // v3.7.0 (Paket betrieb) — beim Öffnen den überwachten Ordner einlesen
  await scanFolder();
  try {
    data = await api.inbox();
  } catch (e) {
    container.innerHTML = `${header(t('inbox.title'))}<div class="banner banner--error">${esc(t('inbox.loadError', { msg: e.message || e }))}</div>`;
    return;
  }
  const mode = ctx.query?.get('view') === 'review' ? 'review' : 'list';
  let review = null;
  if (mode === 'review') review = await api.inboxReview().catch(() => []);

  const reload = async () => {
    data = await api.inbox();
    if (mode === 'review') review = await api.inboxReview().catch(() => []);
    if (container.isConnected) draw();
  };

  const draw = () => {
    announce(data.entries);
    const flagged = data.entries.reduce((n, e) => n + (e.status === 'read' ? (e.flags || 0) : 0), 0);
    container.innerHTML = `
      ${header(t('inbox.title'), `${esc(t('inbox.subtitle'))} <a href="${esc(docUrl('inbox'))}">${esc(t('common.guide'))}</a>`)}
      ${providerHtml(data.provider)}
      ${readingSetupButtonHtml(data.provider)}
      <label class="drop-zone inbox-drop${DEMO ? ' inbox-drop--off' : ''}" id="inbox-drop" for="inbox-file">
        <input type="file" id="inbox-file" accept="application/pdf,.pdf" multiple class="sr-only"${DEMO ? ' disabled' : ''}>
        <span class="inbox-drop__icon" aria-hidden="true">📥</span>
        <span class="inbox-drop__title">${esc(t('inbox.drop.title'))}</span>
        <span class="inbox-drop__hint">${esc(t('inbox.drop.hint'))}</span>
      </label>
      ${channelsHtml(data.folder)}
      <div id="inbox-queue">${queueHtml(data)}</div>
      ${draftEntryHtml(data.entries)}
      <nav class="section-tabs inbox-tabs" aria-label="${esc(t('inbox.title'))}">
        <a class="section-tabs__tab${mode === 'list' ? ' active' : ''}" href="#/inbox"${mode === 'list' ? ' aria-current="page"' : ''}>${esc(tp('inbox.tab.documents', data.entries.length))}</a>
        <a class="section-tabs__tab${mode === 'review' ? ' active' : ''}" href="#/inbox?view=review"${mode === 'review' ? ' aria-current="page"' : ''}>${esc(tp('inbox.tab.review', flagged))}</a>
      </nav>
      ${mode === 'review' ? reviewHtml(review) : listHtml(data.entries)}`;
    wire();
  };

  const upload = async (files) => {
    const list = [...files];
    if (!list.length) return;
    let last = null, ok = 0;
    for (const f of list) {
      try {
        last = await api.inboxUpload(f, f.name);
        ok++;
      } catch (e) {
        if (e.code === 'errors.inbox.duplicate') toastWarn(e.message);
        else toastErr(t('inbox.toast.uploadFailed', { name: f.name, msg: e.message || e }));
      }
    }
    if (!ok) return;
    toastOk(tp('inbox.toast.uploaded', ok));
    if (list.length === 1 && last?.id) { location.hash = `#/inbox/${encodeURIComponent(last.id)}`; return; }
    await reload();
  };

  const wire = () => {
    container.querySelector('[data-act="reading-setup"]')?.addEventListener('click', () => { openReadingSetup().then(() => reload()).catch(() => {}); });
    const input = container.querySelector('#inbox-file');
    input?.addEventListener('change', () => { upload(input.files || []).catch(() => {}); });
    const drop = container.querySelector('#inbox-drop');
    if (drop && !DEMO) {
      drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
      drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
      drop.addEventListener('drop', (e) => {
        e.preventDefault();
        drop.classList.remove('dragover');
        upload(e.dataTransfer?.files || []).catch(() => {});
      });
    }
    // v3.7.0 (cloud) — Warteschlange mit einem Cloud-Anbieter: einmal fragen für
    // alle Dokumente dieses Laufs, mit ihrer Zahl; der Lauf liest genau diese (runScope)
    const startRun = async (resume) => {
      const scope = runScope(data.entries, { resume });
      const consent = await consentFor(data.provider, scope.count || 1);
      if (!resume) await api.inboxQueue();
      runQueue({ consent, only: consent ? scope.ids : null }).then(() => reload()).catch(() => {});
      if (!resume) await reload();
    };
    container.querySelector('#inbox-read-all')?.addEventListener('click', async () => {
      try { await startRun(false); } catch (e) { if (e.code !== 'consent') toastErr(e.message || e); }
    });
    container.querySelector('#inbox-resume')?.addEventListener('click', async () => {
      try { await startRun(true); } catch (e) { if (e.code !== 'consent') toastErr(e.message || e); }
    });
    container.querySelector('#inbox-cancel')?.addEventListener('click', async () => {
      try { await cancelQueue(); await reload(); } catch (e) { toastErr(e.message || e); }
    });
    // v3.7.0 (Paket betrieb) — „Ablegen“ direkt aus der Kernaussage
    container.querySelectorAll('[data-gist-action="file"][data-id]').forEach(b => b.addEventListener('click', async () => {
      try { await api.updateInbox(b.dataset.id, { status: 'filed' }); await reload(); } catch (err) { toastErr(err.message || err); }
    }));
    container.querySelectorAll('[data-act="delete"]').forEach(b => b.addEventListener('click', async () => {
      const e = data.entries.find(x => x.id === b.dataset.id);
      if (!e || !(await confirmModal({ title: t('inbox.delete.title'), message: t('inbox.delete.text', { name: e.name || e.id }),
        confirmLabel: t('inbox.delete.ok'), danger: true }))) return;
      try { await api.deleteInbox(e.id); toastOk(t('inbox.toast.deleted')); await reload(); } catch (err) { toastErr(err.message || err); }
    }));
  };

  // Fortschritt der Warteschlange: nur das Feld neu zeichnen
  const onQueue = () => {
    const box = container.querySelector('#inbox-queue');
    if (box) box.innerHTML = queueHtml(data);
    box?.querySelector('#inbox-cancel')?.addEventListener('click', async () => {
      try { await cancelQueue(); await reload(); } catch (e) { toastErr(e.message || e); }
    });
    if (!queueProgress().running) reload().catch(() => {});
  };
  window.addEventListener('et:inbox-queue', onQueue);
  draw();
  return () => window.removeEventListener('et:inbox-queue', onQueue);
}

/** Feld der Warteschlange: alle auslesen, Fortschritt mit Abbrechen, oder fortsetzen. */
function queueHtml(data) {
  const run = queueProgress();
  const p = data.provider;
  const canRead = !DEMO && p?.available;
  if (run.running) {
    const pct = run.total ? Math.round(100 * (run.done + run.failed) / run.total) : 0;
    return `<div class="card inbox-queue" role="status" aria-live="polite">
      <p><strong>${esc(t('inbox.queue.running', { done: run.done + run.failed, total: run.total }))}</strong>
        ${run.current ? `· ${esc(t('inbox.queue.current', { name: run.current }))}` : ''}
        ${run.step ? `· ${esc(t('inbox.step.' + run.step))}` : ''}</p>
      <progress max="100" value="${pct}" aria-label="${esc(t('inbox.queue.progress'))}"></progress>
      <p><button type="button" class="btn btn--ghost btn--sm" id="inbox-cancel">${esc(t('inbox.queue.cancel'))}</button></p>
    </div>`;
  }
  const queued = data.queue?.queued || 0;
  const todo = data.entries.filter(e => ['new', 'failed'].includes(e.status)).length;
  if (queued && canRead) {
    return `<div class="card inbox-queue"><p>${esc(tp('inbox.queue.waiting', queued))}</p>
      <p><button type="button" class="btn btn--primary btn--sm" id="inbox-resume">${esc(t('inbox.queue.resume'))}</button>
        <button type="button" class="btn btn--ghost btn--sm" id="inbox-cancel">${esc(t('inbox.queue.cancel'))}</button></p></div>`;
  }
  if (todo > 1 && canRead) {
    return `<p class="inbox-queue"><button type="button" class="btn btn--primary" id="inbox-read-all">${esc(tp('inbox.queue.readAll', todo))}</button>
      <span class="muted small">${esc(t('inbox.queue.hint'))}</span></p>`;
  }
  return '';
}

function listHtml(entries) {
  if (!entries.length) return `<div class="banner banner--info">${esc(t('inbox.empty'))}</div>`;
  return `<div class="table-wrap"><table class="data-table inbox-table">
    <thead><tr><th scope="col">${esc(t('inbox.col.document'))}</th><th scope="col">${esc(t('inbox.col.type'))}</th>
      <th scope="col">${esc(t('inbox.col.status'))}</th><th scope="col">${esc(t('inbox.col.added'))}</th>
      <th scope="col"><span class="sr-only">${esc(t('inbox.col.actions'))}</span></th></tr></thead>
    <tbody id="inbox-list">${entries.map(e => `
      <tr data-entry="${esc(e.id)}" data-status="${esc(e.status)}">
        <td><a href="#/inbox/${esc(encodeURIComponent(e.id))}">${esc(e.name || e.id)}</a>${gistLineHtml(e)}</td>
        <td>${esc(e.type ? typeLabel(e.type) : '–')}</td>
        <td>${statusBadge(e)}</td>
        <td>${esc(fmt.date(String(e.created_at || '').slice(0, 10)))}</td>
        <td class="cell-actions">${gistActionHtml(e.gist, { entryId: e.id, inList: true })}
          <a class="btn btn--ghost btn--sm" href="#/inbox/${esc(encodeURIComponent(e.id))}">${esc(t('inbox.open'))}</a>
          ${DEMO ? '' : `<button type="button" class="btn btn--ghost btn--sm" data-act="delete" data-id="${esc(e.id)}" aria-label="${esc(t('inbox.delete.label', { name: e.name || e.id }))}">✕</button>`}</td>
      </tr>`).join('')}</tbody></table></div>`;
}

function statusBadge(e) {
  const cls = { new: 'badge--info', queued: 'badge--info', read: e.flags ? 'badge--warning' : 'badge--success', failed: 'badge--danger', done: 'badge--success', filed: '' }[e.status] || '';
  const extra = e.status === 'read' && e.flags ? ` · ${tp('inbox.flags', e.flags)}` : '';
  return `<span class="badge ${cls}">${esc(statusLabel(e.status) + extra)}</span>`;
}

function reviewHtml(list) {
  if (!list?.length) return `<div class="banner banner--success">${esc(t('inbox.review.empty'))}</div>`;
  return `<p class="muted">${esc(t('inbox.review.hint'))}</p><ul class="inbox-review" id="inbox-review">${list.map(r => `
    <li class="inbox-review__item">
      <a href="#/inbox/${esc(encodeURIComponent(r.entry_id))}?field=${esc(encodeURIComponent(r.field))}"><strong>${esc(fieldLabel(r.field))}</strong></a>
      · ${esc(r.name || '')} · ${esc(typeLabel(r.type))}
      <span class="inbox-review__reason">${esc(reasonText(r.reasons))}</span>
      ${r.quote ? `<blockquote class="inbox-quote">${esc(r.quote)}${r.page ? ` <span class="muted">(${esc(t('inbox.doc.page', { n: r.page }))})</span>` : ''}</blockquote>` : ''}
    </li>`).join('')}</ul>`;
}

// ── Vorschau ─────────────────────────────────────────────────────────────

async function renderDetail(container, rawId, ctx = {}) {
  const id = decodeURIComponent(rawId);
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  let pv, info;
  try {
    [pv, info] = await Promise.all([api.inboxEntry(id), api.inbox()]);
  } catch (e) {
    container.innerHTML = `${header(t('inbox.title'), '', true)}<div class="banner banner--error">${esc(e.status === 404 ? t('errors.inbox.notFound') : t('inbox.loadError', { msg: e.message || e }))}</div>`;
    return;
  }
  const provider = info.provider;
  // Versorgerpost gibt es für Arten mit Verträgen (nicht für die reine PV-Erzeugung)
  const utilities = (await activeUtilities().catch(() => [])).filter(u => u.has_contracts !== false);
  const lists = new Map();   // Zähler und Verträge je Verbrauchsart
  const loadLists = async (u) => {
    if (!u) return { meters: [], contracts: [] };
    if (!lists.has(u)) {
      lists.set(u, Promise.all([api.meters(u).catch(() => []), api.contracts(u).catch(() => [])])
        .then(([meters, contracts]) => ({ meters, contracts: contracts.filter(c => !c.is_shadow) })));
    }
    return lists.get(u);
  };
  const busy = { reading: false, cancelled: false, step: null, since: 0 };
  let timer = null;
  const fileUrl = api.inboxFileUrl(pv.entry.attachment_id);

  container.innerHTML = `
    ${header(pv.entry.name || t('inbox.title'), '', true)}
    ${DEMO ? `<div class="banner banner--info">${esc(t('inbox.demo.note'))}</div>` : ''}
    <div class="inbox-detail">
      <section class="inbox-doc card" id="inbox-doc" aria-label="${esc(t('inbox.doc.label'))}">
        <p class="inbox-doc__bar"><a class="btn btn--ghost btn--sm" href="${esc(fileUrl)}" target="_blank" rel="noopener">${esc(t('inbox.doc.open'))}</a></p>
        <div class="inbox-doc__pages" id="inbox-pages"></div>
      </section>
      <section class="inbox-side" id="inbox-side"></section>
    </div>`;

  // Dokument links: Seiten mit pdf.js, sonst der Link auf die Datei
  const pagesEl = container.querySelector('#inbox-pages');
  let stopPages = null;
  if (pdfSupported() && pv.entry.attachment_id) {
    openPdf(fileUrl).then((doc) => {
      if (!container.isConnected) return;
      stopPages = renderPages(doc, pagesEl, { label: (n) => t('inbox.doc.page', { n }) });
    }).catch(() => { pagesEl.innerHTML = `<p class="muted">${esc(t('inbox.doc.noViewer'))}</p>`; });
  } else {
    pagesEl.innerHTML = `<p class="muted">${esc(t('inbox.doc.noViewer'))}</p>`;
  }
  const showPage = (n) => {
    const el = container.querySelector(`#pdf-page-${Number(n)}`);
    el?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
  };

  const side = container.querySelector('#inbox-side');
  const save = async (patch) => {
    try {
      pv = await api.updateInbox(id, patch);
      await drawSide();
    } catch (e) { toastErr(e.message || e); }
  };

  const read = async () => {
    if (busy.reading) return;
    let consent = false;
    try { consent = await consentFor(provider); } catch { return; }
    Object.assign(busy, { reading: true, cancelled: false, step: 'text', since: Date.now() });
    timer = setInterval(() => { const el = side.querySelector('[data-role="elapsed"]'); if (el) el.textContent = elapsed(); }, 1000);
    await drawSide();
    try {
      const res = await readDocument(pv.entry, { consent, fresh: !!pv.entry.extraction,
        onStep: (s) => { busy.step = s; const el = side.querySelector('[data-role="step"]'); if (el) el.textContent = t('inbox.step.' + s); },
        cancelled: () => busy.cancelled });
      if (busy.cancelled) toastWarn(t('inbox.toast.cancelled'));
      else if (res) { pv = res; toastOk(res.recognize_only ? t('inbox.toast.filed', { type: typeLabel(res.entry.type) }) : t('inbox.toast.read')); }
    } catch (e) {
      toastErr(e.code === 'unsupported' ? t('inbox.doc.noViewer') : (e.message || String(e)));
      pv = await api.inboxEntry(id).catch(() => pv);
    } finally {
      clearInterval(timer);
      busy.reading = false;
      if (container.isConnected) await drawSide();
      api.inbox().then(d => announce(d.entries)).catch(() => {});
    }
  };
  const elapsed = () => t('inbox.step.elapsed', { s: Math.round((Date.now() - busy.since) / 1000) });

  const drawSide = async () => {
    const e = pv.entry;
    const as = pv.assignment || {};
    const { meters, contracts } = await loadLists(as.utility);
    const uObj = utilities.find(u => u.key === as.utility);
    const unit = uObj?.unit || '';
    const canRead = !DEMO && provider?.available;
    const fields = e.extraction?.fields || {};
    const meterContracts = contracts.filter(c => !as.meter_id || c.meter_id === as.meter_id || c.meter_group_id);
    side.innerHTML = `
      ${busy.reading ? '' : gistCardHtml(pv.gist, duplicateHtml(pv))}
      <div class="card inbox-card" id="inbox-state">
        <div class="inbox-state__row">
          ${statusBadge({ status: e.status, flags: e.extraction?.flags })}
          <label class="inbox-type">${esc(t('inbox.col.type'))}
            <select class="select" id="inbox-type"${DEMO || busy.reading ? ' disabled' : ''}>
              <option value=""${e.type ? '' : ' selected'}>${esc(t('inbox.type.unknown'))}</option>
              ${TYPES.map(x => `<option value="${x}"${x === e.type ? ' selected' : ''}>${esc(typeLabel(x))}</option>`).join('')}
            </select></label>
        </div>
        ${e.error ? `<div class="banner banner--warning">${esc(t(e.error.key, e.error.params || {}))}</div>` : ''}
        ${busy.reading ? `<p role="status" aria-live="polite"><span class="spinner" aria-hidden="true"></span>
            <span data-role="step">${esc(t('inbox.step.' + busy.step))}</span> · <span data-role="elapsed">${esc(elapsed())}</span>
            <button type="button" class="btn btn--ghost btn--sm" id="inbox-read-cancel">${esc(t('inbox.queue.cancel'))}</button></p>`
          : `<p>${canRead ? `<button type="button" class="btn btn--primary" id="inbox-read">${esc(t(e.extraction ? 'inbox.reread' : 'inbox.read'))}</button>` : ''}
            ${e.extraction ? `<span class="muted small">${esc(t('inbox.extracted', { model: e.extraction.model || '–', date: fmt.date(String(e.extraction.extracted_at || '').slice(0, 10)),
              s: Math.round((e.extraction.seconds?.classify || 0) + (e.extraction.seconds?.extract || 0)) }))}</span>` : ''}</p>
            ${!canRead && !DEMO ? providerHtml(provider) : ''}`}
      </div>
      ${pv.recognize_only ? `<div class="banner banner--info" id="inbox-filed">${esc(t('inbox.recognizeOnly', { type: typeLabel(e.type) }))}</div>` : ''}
      ${pv.gist && !busy.reading ? '' : duplicateHtml(pv)}
      <div class="card inbox-card" id="inbox-assign">
        <h2 class="card__title">${esc(t('inbox.assign.title'))}</h2>
        <p class="muted small">${esc(pv.assignment_saved ? t('inbox.assign.saved') : (pv.suggested?.by?.length ? t('inbox.assign.suggested', { by: pv.suggested.by.map(b => t('inbox.assign.by.' + b)).join(', ') }) : t('inbox.assign.none')))}</p>
        <div class="form-row">
          <div class="field"><label for="inbox-utility">${esc(t('inbox.assign.utility'))}</label>
            <select class="select" id="inbox-utility"${DEMO ? ' disabled' : ''}><option value="">–</option>
              ${utilities.map(u => `<option value="${esc(u.key)}"${u.key === as.utility ? ' selected' : ''}>${esc((u.icon || '') + ' ' + u.label)}</option>`).join('')}</select></div>
          <div class="field"><label for="inbox-meter">${esc(t('inbox.assign.meter'))}</label>
            <select class="select" id="inbox-meter"${DEMO || !as.utility ? ' disabled' : ''}><option value="">–</option>
              ${meters.map(m => `<option value="${esc(m.id)}"${m.id === as.meter_id ? ' selected' : ''}>${esc(m.name)}</option>`).join('')}</select></div>
          <div class="field inbox-assign__contract"><label for="inbox-contract">${esc(t('inbox.assign.contract'))}</label>
            <select class="select" id="inbox-contract"${DEMO || !as.utility ? ' disabled' : ''}><option value="">${esc(t('inbox.assign.noContract'))}</option>
              ${meterContracts.map(c => `<option value="${esc(c.id)}"${c.id === as.contract_id ? ' selected' : ''}>${esc(`${c.provider || '–'} · ${fmt.date(c.start)}–${c.end ? fmt.date(c.end) : '…'}`)}</option>`).join('')}</select></div>
        </div>
      </div>
      ${e.extraction && !pv.recognize_only ? fieldsHtml(pv, fields) : ''}
      ${e.extraction && !pv.recognize_only ? proposalsHtml(pv, unit) : ''}
      <div class="card inbox-card" id="inbox-manual">
        <h2 class="card__title">${esc(t('inbox.manual.title'))}</h2>
        <p class="muted small">${esc(t('inbox.manual.hint'))}</p>
        <p class="inbox-manual__list">${manualTargets(e.type, { utility: as.utility, meterId: as.meter_id,
          from: fieldValue(fields, 'period_from'), until: fieldValue(fields, 'period_to') }).map((m, i) =>
          `<a class="btn ${i === 0 ? 'btn--primary' : 'btn--ghost'} btn--sm" href="${esc(m.href)}" data-manual="${esc(m.key)}">${esc(t('inbox.manual.' + m.key))}</a>`).join(' ')}</p>
      </div>
      ${DEMO ? '' : `<p class="inbox-actions">
        ${e.status === 'filed' || e.status === 'done'
          ? `<button type="button" class="btn btn--ghost btn--sm" id="inbox-reopen">${esc(t('inbox.reopen'))}</button>`
          : `<button type="button" class="btn btn--ghost btn--sm" id="inbox-file-away">${esc(t('inbox.fileAway'))}</button>`}
        <button type="button" class="btn btn--danger btn--sm" id="inbox-delete">${esc(t('inbox.delete.ok'))}</button></p>`}`;
    wireSide();
  };

  const fieldRow = (name) => [...side.querySelectorAll('[data-field]')].find(el => el.dataset.field === name) || null;

  const wireSide = () => {
    // v3.7.0 (Paket betrieb) — Knopf der Kernaussage: übernehmen, Termin anlegen, ablegen (Links führen selbst)
    side.querySelector('[data-gist-action="apply"]')?.addEventListener('click', () => {
      const btn = side.querySelector('#inbox-apply');
      if (btn && !btn.disabled) btn.click();
      else side.querySelector('#inbox-proposals-card')?.scrollIntoView?.({ block: 'start' });
    });
    side.querySelector('[data-gist-action="remind"]')?.addEventListener('click', async () => {
      const date = side.querySelector('#inbox-remind-date')?.value || '';
      try {
        pv = await api.inboxRemind(id, date);
        toastOk(t('inbox.toast.reminded', { date: fmt.date(date) }));
        window.dispatchEvent(new CustomEvent('et:badges-refresh'));
        await drawSide();
      } catch (e) { toastErr(e.message || e); }
    });
    side.querySelector('[data-gist-action="file"]')?.addEventListener('click', () => save({ status: 'filed' }));
    side.querySelector('#inbox-read')?.addEventListener('click', () => { read().catch(() => {}); });
    side.querySelector('#inbox-read-cancel')?.addEventListener('click', () => { busy.cancelled = true; });
    side.querySelector('#inbox-type')?.addEventListener('change', (ev) => { if (ev.target.value) save({ type: ev.target.value }); });
    const as = () => ({ ...(pv.assignment || {}) });
    side.querySelector('#inbox-utility')?.addEventListener('change', (ev) => save({ assignment: { utility: ev.target.value || null, meter_id: null, contract_id: null } }));
    side.querySelector('#inbox-meter')?.addEventListener('change', (ev) => save({ assignment: { ...as(), meter_id: ev.target.value || null, contract_id: null } }));
    side.querySelector('#inbox-contract')?.addEventListener('change', (ev) => save({ assignment: { ...as(), contract_id: ev.target.value || null } }));
    side.querySelectorAll('[data-page]').forEach(b => b.addEventListener('click', () => showPage(b.dataset.page)));
    side.querySelectorAll('[data-confirm]').forEach(b => b.addEventListener('click', () => {
      const f = pv.entry.extraction.fields[b.dataset.confirm];
      save({ edits: { [b.dataset.confirm]: f?.value } });
    }));
    side.querySelectorAll('[data-correct]').forEach(b => b.addEventListener('click', () => editField(b.dataset.correct)));
    const applyBtn = side.querySelector('#inbox-apply');
    const sync = () => {
      const n = side.querySelectorAll('input[data-proposal]:checked').length;
      if (applyBtn) { applyBtn.disabled = n === 0 || DEMO; applyBtn.textContent = tp('inbox.applyButton', n); }
    };
    side.querySelectorAll('input[data-proposal]').forEach(c => c.addEventListener('change', sync));
    sync();
    applyBtn?.addEventListener('click', async () => {
      const ids = [...side.querySelectorAll('input[data-proposal]:checked')].map(c => c.dataset.proposal);
      applyBtn.disabled = true;
      try {
        const res = await api.inboxApply(id, ids);
        if (res.applied.length) toastOk(tp('inbox.toast.applied', res.applied.length));
        if (res.failed.length) toastErr(res.failed.map(f => f.error).join(' · '));
        // v3.7.0 (Paket betrieb) — eine Rechnung ist danach geprüft: das Urteil gleich nennen
        if (res.gist?.check?.verdict) (res.gist.check.verdict === 'ok' ? toastOk : toastWarn)(gistText(res.gist));
        pv = await api.inboxEntry(id);
        window.dispatchEvent(new CustomEvent('et:badges-refresh'));
      } catch (e) { toastErr(e.message || e); }
      await drawSide();
    });
    side.querySelectorAll('[data-manual]').forEach(a => a.addEventListener('click', () => {
      openDocDock({ url: fileUrl, name: pv.entry.name || pv.entry.id, back: `#/inbox/${encodeURIComponent(id)}` });
    }));
    side.querySelector('#inbox-file-away')?.addEventListener('click', () => save({ status: 'filed' }));
    side.querySelector('#inbox-reopen')?.addEventListener('click', () => save({ status: 'new' }));
    side.querySelector('#inbox-delete')?.addEventListener('click', async () => {
      if (!(await confirmModal({ title: t('inbox.delete.title'), message: t('inbox.delete.text', { name: pv.entry.name || id }),
        confirmLabel: t('inbox.delete.ok'), danger: true }))) return;
      try { await api.deleteInbox(id); toastOk(t('inbox.toast.deleted')); location.hash = '#/inbox'; } catch (e) { toastErr(e.message || e); }
    });
  };

  /** Ein Feld korrigieren: Eingabe nach seiner Art an Ort und Stelle. */
  const editField = (name) => {
    const row = fieldRow(name);
    const def = pv.schema?.[name] || {};
    const f = pv.entry.extraction?.fields?.[name] || {};
    const cur = pv.entry.edits?.[name] ?? f.value;
    if (!row) return;
    const inputHtml = def.kind === 'enum'
      ? `<select class="select" data-role="edit">${(def.options || []).map(o => `<option value="${esc(o)}"${o === cur ? ' selected' : ''}>${esc(t('inbox.option.' + o))}</option>`).join('')}</select>`
      : def.kind === 'date'
        ? `<input class="input" type="date" data-role="edit" value="${esc(cur || '')}">`
        : ['number', 'money', 'price_ct'].includes(def.kind)
          ? `<input class="input" type="text" inputmode="decimal" data-role="edit" value="${esc(formatForInput(cur))}">`
          : `<input class="input" type="text" data-role="edit" maxlength="200" value="${esc(cur ?? '')}">`;
    const box = document.createElement('div');
    box.className = 'inbox-edit';
    // v3.7.0 — Nettopreis mit gelesenem Satz: eingegeben wird wie gedruckt, die App rechnet um (lib/price-basis.js)
    const basis = (v) => (f.net && v !== null ? priceBasisText({ vatPct: f.net.vat_pct, gross: grossOf(v, f.net.vat_pct), kind: def.kind }) : '');
    box.innerHTML = `${inputHtml} <button type="button" class="btn btn--primary btn--sm" data-role="ok">${esc(t('common.save'))}</button>
      <button type="button" class="btn btn--ghost btn--sm" data-role="no">${esc(t('common.cancel'))}</button>
      ${f.net ? `<p class="muted small" data-role="basis">${esc(basis(typeof cur === 'number' ? cur : parseDecimal(String(cur ?? ''))))}</p>` : ''}`;
    row.append(box);
    const input = box.querySelector('[data-role="edit"]');
    if (f.net) input?.addEventListener('input', () => { box.querySelector('[data-role="basis"]').textContent = basis(parseDecimal(input.value)); });
    input?.focus();
    box.querySelector('[data-role="no"]').addEventListener('click', () => box.remove());
    box.querySelector('[data-role="ok"]').addEventListener('click', () => {
      let v = input.value;
      if (['number', 'money', 'price_ct'].includes(def.kind)) {
        v = parseDecimal(v);
        if (v === null) { toastErr(t('errors.inbox.editInvalid', { field: fieldLabel(name), value: input.value })); return; }
      }
      save({ edits: { [name]: v } });
    });
  };

  await drawSide();
  // Sprung aus der Prüfliste: das Feld zeigen
  const want = ctx.query?.get('field');
  if (want) fieldRow(want)?.scrollIntoView?.({ block: 'center' });
  return () => { stopPages?.(); clearInterval(timer); };
}

/** Wert eines Felds aus der Auslesung (für die Sprünge „Von Hand erfassen“). */
function fieldValue(fields, name) {
  const v = fields?.[name]?.value;
  return typeof v === 'string' ? v : '';
}

/** Die Felder der Auslesung mit Belegstelle; markierte zuerst. */
function fieldsHtml(pv, fields) {
  const schema = pv.schema || {};
  const edits = pv.entry.edits || {};
  const names = orderedFields(fields, schema);
  // Nachbesserung 4 — ein Hilfsfeld „nein“ (etwa: Stand nicht geschätzt) zeigt die Vorschau nicht
  const shown = names.filter(n => fields[n] && fields[n].status !== 'empty' && !(schema[n]?.aux && fields[n].value === false));
  // v3.7.0 (treffsicher, Nachbesserung 2) — Hilfsfelder (netto/brutto, Steuersatz) nur, wenn sie dastehen
  const empty = names.filter(n => (!fields[n] || fields[n].status === 'empty') && !schema[n]?.aux);
  const row = (n) => {
    const f = fields[n] || { status: 'empty', reasons: [] };
    const def = schema[n] || {};
    const edited = Object.prototype.hasOwnProperty.call(edits, n);
    const check = f.status === 'check' && !edited;
    const value = def.kind === 'list'
      ? (f.items || []).map(i => `<li${i.status === 'check' ? ' class="inbox-item--check"' : ''}>${esc(itemText(n, i.value, i))}
          ${i.quote ? `<blockquote class="inbox-quote">${esc(i.quote)}${i.page ? ` <button type="button" class="link-btn" data-page="${esc(i.page)}">${esc(t('inbox.doc.page', { n: i.page }))}</button>` : ''}</blockquote>` : ''}</li>`).join('')
      : esc(valueText(def.kind, edited ? edits[n] : f.value));
    return `<li class="inbox-field${check ? ' inbox-field--check' : ''}${edited ? ' inbox-field--edited' : ''}" data-field="${esc(n)}">
      <div class="inbox-field__head"><span class="inbox-field__label">${esc(fieldLabel(n))}</span>
        <span class="inbox-field__mark" aria-hidden="true">${check ? '⚠' : edited ? '✎' : f.status === 'empty' ? '–' : '✓'}</span></div>
      ${def.kind === 'list' ? `<ul class="inbox-field__items">${value}</ul>` : `<div class="inbox-field__value">${value}</div>`}
      ${check ? `<p class="inbox-field__reason">${esc(reasonText(f.reasons))}</p>` : ''}
      ${!check && !edited && fieldHints(f).length ? `<p class="inbox-field__reason muted" data-role="hint">${esc(reasonText(fieldHints(f)))}</p>` : ''}
      ${edited ? `<p class="inbox-field__reason">${esc(t('inbox.field.edited', { value: valueText(def.kind, f.value) }))}</p>` : ''}
      ${def.kind !== 'list' && f.quote ? `<blockquote class="inbox-quote">${esc(f.quote)}${f.page ? ` <button type="button" class="link-btn" data-page="${esc(f.page)}">${esc(t('inbox.doc.page', { n: f.page }))}</button>` : ''}</blockquote>` : ''}
      ${check && def.kind !== 'list' && !DEMO ? `<p class="inbox-field__actions">
        ${f.value !== null && f.value !== undefined ? `<button type="button" class="btn btn--ghost btn--xs" data-confirm="${esc(n)}">${esc(t('inbox.field.confirm'))}</button>` : ''}
        <button type="button" class="btn btn--ghost btn--xs" data-correct="${esc(n)}">${esc(t('inbox.field.correct'))}</button></p>` : ''}
      ${!check && edited && !DEMO ? `<p class="inbox-field__actions"><button type="button" class="btn btn--ghost btn--xs" data-correct="${esc(n)}">${esc(t('inbox.field.correct'))}</button></p>` : ''}
    </li>`;
  };
  const flags = pv.entry.extraction?.flags || 0;
  return `<div class="card inbox-card" id="inbox-fields-card">
    <h2 class="card__title">${esc(t('inbox.fields.title'))}</h2>
    <p class="muted small">${esc(flags ? tp('inbox.fields.flagged', flags) : t('inbox.fields.allOk'))}${pv.review_all ? ` ${esc(t('inbox.fields.reviewAll'))}` : ''}</p>
    <ul class="inbox-fields" id="inbox-fields">${shown.map(row).join('')}</ul>
    ${empty.length ? `<details class="inbox-empty"><summary>${esc(tp('inbox.fields.empty', empty.length))}</summary>
      <ul class="inbox-fields">${empty.map(row).join('')}</ul></details>` : ''}
  </div>`;
}

/** Die vorgeschlagenen Änderungen mit Abgleich; angehakt ist, was neu und unmarkiert ist. */
function proposalsHtml(pv, unit) {
  const list = pv.proposals || [];
  const body = list.length
    ? `<ul class="inbox-proposals" id="inbox-proposals">${list.map(p => {
        const exists = p.status === 'exists';
        const detail = detailText(p, { unit });
        return `<li class="inbox-proposal inbox-proposal--${esc(p.status)}${p.check ? ' inbox-proposal--check' : ''}">
          <label><input type="checkbox" data-proposal="${esc(p.id)}"${p.checked ? ' checked' : ''}${exists || DEMO ? ' disabled' : ''}>
            <span class="inbox-proposal__text">${esc(proposalText(p, { unit }))}</span></label>
          <span class="badge ${{ new: 'badge--info', exists: 'badge--success', conflict: 'badge--danger', overlap: 'badge--warning' }[p.status] || ''}">${esc(t('inbox.pstatus.' + p.status))}</span>
          ${p.check ? `<span class="badge badge--warning">${esc(t('inbox.pstatus.check'))}</span>` : ''}
          ${detail ? `<p class="inbox-proposal__detail">${esc(detail)}</p>` : ''}
          ${proposalLines(p).length ? `<ul class="inbox-proposal__lines" data-role="lines">${proposalLines(p).map(l => `<li>${esc(l)}</li>`).join('')}</ul>` : ''}
          ${proposalNotes(p).map(n => `<p class="inbox-proposal__detail" data-role="note">${esc(n)}</p>`).join('')}
        </li>`;
      }).join('')}</ul>
      <p><button type="button" class="btn btn--primary" id="inbox-apply"${DEMO ? ' disabled' : ''}>${esc(tp('inbox.applyButton', list.filter(p => p.checked).length))}</button>
        <span class="muted small">${esc(t('inbox.applyHint'))}</span></p>`
    : `<p class="muted">${esc(t(pv.assignment?.utility ? 'inbox.proposals.none' : 'inbox.proposals.assignFirst'))}</p>`;
  return `<div class="card inbox-card" id="inbox-proposals-card"><h2 class="card__title">${esc(t('inbox.proposals.title'))}</h2>${body}</div>`;
}
