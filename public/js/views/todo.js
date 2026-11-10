// =====================================================================
// v3.12.0 (M2) — Zu tun › Offen: alles, was auf dich wartet, an einem Ort.
//
// Eine Liste, darüber Filter:
//   Alle · Fristen und Termine · Empfehlungen · Rechte · Post · KI-Vorschläge
// „Fristen und Termine“ sind die Zeilen aus der Agenda (buildTodo, dieselbe Quelle
// wie Kalender und Home Assistant), mit Haken und Knöpfen wie auf der Übersicht.
// Empfehlungen und Rechte zeigen die vollen Karten (recommendations.js) mit Brief,
// Normen und Ausblenden; unter „Alle“ steht davon eine Zeile, was nicht ohnehin in
// der Agenda steht, und führt zur Karte. KI-Vorschläge stehen hier, weil sie eine
// Frist haben; nur Verwalter sehen sie, die Prüfung am Server bleibt (adminOnly).
// Filter in der Adresse: #/todo?art=rec&rec=<id> (Sprungmarke auf eine Karte).
// =====================================================================

import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml as esc, fmt } from '../lib/format.js';
import { rich } from '../lib/rich.js';
import { icon } from '../lib/icons.js';
import { emptyHtml } from '../lib/empty.js';
import { atLeast, sessionRole } from '../lib/levels.js';
import { openEntries } from '../lib/inbox.js';
import { buildTodo, renderTodoBox } from './dashboard.js';
import { openCalendarSubscription } from './reminders.js';
import { render as renderRecommendations } from './recommendations.js';
import { proposalRowsHtml, proposalsNoteHtml, wireProposals } from '../components/mcp-card.js';

const isRights = (r) => r?.category === 'recht';

/**
 * Alles für die Liste. Eine Quelle, die nicht antwortet, fehlt in der Liste; die übrigen bleiben.
 * Die Seitenleiste zählt hiermit, deshalb stehen alle Abrufe im ersten Schritt (appScope).
 */
export async function loadOffen({ proposals: withProposals = true } = {}) {
  const admin = sessionRole() === 'admin';
  // v3.12.0 — die Übersicht fragt die Vorschläge der KI nur, wenn sie in der App bestätigt werden (sonst gibt es keine)
  const ask = admin && withProposals;
  // v3.12.0 — eine Quelle, die nicht antwortet, merkt sich die Liste: Sie sagt es, statt „Gerade wartet nichts“
  // (bis dahin nur, wenn Agenda und Empfehlungen beide fehlten, und dann zugleich mit dem Leerzustand)
  const errors = [];
  const miss = (e) => { errors.push(e); return null; };
  const [events, recs, inbox, proposals, status, rights] = await Promise.all([
    api.agenda(90).then(x => x?.events || []).catch(miss),
    api.recommendations().catch(miss),
    api.inbox().catch(miss),
    ask ? api.mcpProposals().catch(() => []) : [],
    ask ? api.mcpStatus().catch(() => null) : null,
    api.rights().catch(() => null),
  ]);
  const failed = errors.length > 0;
  const todo = buildTodo(events || [], recs || []);
  const inTodo = new Set(todo.filter(x => x.recId).map(x => x.recId));
  return {
    failed, admin, todo, status,
    failedAll: events === null && recs === null && inbox === null,
    error: errors[0] || null,
    recs: recs || [],
    restRecs: (recs || []).filter(r => !inTodo.has(r.id)),
    post: openEntries(inbox?.entries),
    proposals: Array.isArray(proposals) ? proposals : [],
    rightsOn: (recs || []).some(isRights) || !!rights?.enabled,
  };
}

/** Zahl am Menüpunkt: jede Zeile unter „Alle“. */
export const offenCount = (d) => d.todo.length + d.restRecs.length + d.post.length + d.proposals.length;

const SEV_TONE = { urgent: 'alert', warning: 'warn', info: 'info' };
const catLabel = (k) => { const v = t('recommendations.cat.' + k); return v === 'recommendations.cat.' + k ? k : v; };

/** Empfehlung als Zeile: Titel, Art; das Ganze führt zur Karte mit Einzelheiten und Aktionen. */
function recRowHtml(r) {
  return `<li class="dash-todo__item dash-todo__item--${SEV_TONE[r.severity] || 'info'}" data-role="todo-rec" data-rec="${esc(r.id)}">
    <a class="dash-todo__link" href="#/todo?art=rec&rec=${esc(encodeURIComponent(r.id))}">
      <span class="dash-todo__icon" aria-hidden="true">${icon(isRights(r) ? 'waage' : 'info', { size: 20 })}</span>
      <span class="dash-todo__text"><strong>${esc(r.title)}</strong><span class="muted">${esc(catLabel(r.category))}</span></span>
      <span class="btn btn--sm btn--ghost dash-todo__go">${esc(t('todo.recAction'))}</span>
      <span class="dash-todo__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span>
    </a>
  </li>`;
}

/** Dokument im Posteingang, das noch Arbeit macht. */
function postRowHtml(e) {
  return `<li class="dash-todo__item dash-todo__item--warn" data-role="todo-post">
    <a class="dash-todo__link" href="#/inbox/${esc(encodeURIComponent(e.id))}">
      <span class="dash-todo__icon" aria-hidden="true">${icon('eingang', { size: 20 })}</span>
      <span class="dash-todo__text"><strong>${esc(t('todo.post.title', { name: e.name || e.id }))}</strong>${e.created_at ? `<span class="muted">${esc(t('todo.post.received', { date: fmt.date(String(e.created_at).slice(0, 10)) }))}</span>` : ''}</span>
      <span class="btn btn--sm btn--ghost dash-todo__go">${esc(t('todo.post.action'))}</span>
      <span class="dash-todo__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span>
    </a>
  </li>`;
}

/** Filter, die es für diese Person und diesen Haushalt gibt. */
function filtersOf(d) {
  const all = [
    ['alle', t('todo.filter.all'), offenCount(d)],
    ['frist', t('todo.filter.frist'), d.todo.length],
    ['rec', t('todo.filter.rec'), d.recs.length],
  ];
  if (d.rightsOn) all.push(['rights', catLabel('recht'), d.recs.filter(isRights).length]);
  all.push(['post', t('todo.filter.post'), d.post.length]);
  if (d.admin && (d.status?.enabled || d.proposals.length)) all.push(['ki', t('todo.filter.ki'), d.proposals.length]);
  return all;
}

export async function render(container, _params = [], ctx = {}) {
  container.innerHTML = `<div class="loading" role="status">${esc(t('common.loading'))}</div>`;
  let d = await loadOffen();
  const target = ctx?.query?.get?.('rec') || null;
  let art = target ? 'rec' : ctx?.query?.get?.('art') || 'alle';

  const reload = async () => {
    d = await loadOffen();
    window.dispatchEvent(new CustomEvent('et:badges-refresh'));
    if (container.isConnected) draw();
  };

  /** Nur die Zahlen der Filter nachziehen (die Karten zeichnen sich selbst). */
  const refreshCounts = async () => {
    d = await loadOffen();
    for (const [key, , n] of filtersOf(d)) {
      const el = container.querySelector(`[data-art="${key}"] [data-role="todo-count"]`);
      if (el) el.textContent = fmt.int(n);
    }
  };

  const draw = () => {
    const filters = filtersOf(d);
    if (!filters.some(([key]) => key === art)) art = 'alle';
    container.innerHTML = `
      <div class="view-header">
        <div>
          <h1 class="view-header__title">${esc(t('todo.title'))}</h1>
          <div class="view-header__subtitle">${rich('todo.subtitle')}</div>
        </div>
        <div class="view-header__actions">
          <button type="button" class="btn btn--ghost" id="rem-subscribe">${icon('kalender')} ${esc(t('calendar.subscribe'))}</button>
          <a class="btn btn--primary" id="rem-add" href="#/todo/dates?add=1">${esc(t('reminders.add'))}</a>
        </div>
      </div>
      ${d.failed ? `<div class="infobox infobox--fehler" role="alert" data-role="todo-failed">${esc(t(d.failedAll ? 'todo.loadError' : 'todo.loadErrorPart'))}${d.error?.message ? ` <span class="muted small" data-role="error-detail">${esc(d.error.message)}</span>` : ''}</div>` : ''}
      <div class="seg todo-filter" role="group" aria-label="${esc(t('todo.filter.label'))}">
        ${filters.map(([key, label, n]) => `<button type="button" class="seg__btn${key === art ? ' active' : ''}" data-art="${key}" aria-pressed="${key === art}">${esc(label)} <span class="muted" data-role="todo-count">${esc(fmt.int(n))}</span></button>`).join('')}
      </div>
      <div data-role="todo-body"></div>`;

    container.querySelector('#rem-subscribe')?.addEventListener('click', () => openCalendarSubscription());
    container.querySelectorAll('[data-art]').forEach(b => b.addEventListener('click', () => {
      art = b.dataset.art;
      try { history.replaceState(history.state, '', art === 'alle' ? '#/todo' : `#/todo?art=${art}`); } catch { /* egal */ }
      draw();
      container.querySelector(`[data-art="${art}"]`)?.focus();
    }));
    // am iPhone wischt die Reihe quer: Der gewählte Filter steht im Bild
    if (art !== 'alle') container.querySelector('.todo-filter .active')?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
    drawBody(container.querySelector('[data-role="todo-body"]'));
  };

  const drawBody = (body) => {
    if (art === 'rec' || art === 'rights') {
      const query = new URLSearchParams(target && art === 'rec' ? { rec: target } : {});
      renderRecommendations(body, [], { query, only: art === 'rights' ? 'rights' : null, onChange: refreshCounts }).catch(e => {
        body.innerHTML = `<div class="infobox infobox--fehler">${esc(e.message || e)}</div>`;
      });
      return;
    }
    const kiRows = art === 'alle' || art === 'ki' ? proposalRowsHtml(d.proposals, !!d.status?.enabled) : '';
    const rest = art === 'alle' ? `${d.post.map(postRowHtml).join('')}${d.restRecs.map(recRowHtml).join('')}`
      : art === 'post' ? d.post.map(postRowHtml).join('') : '';
    const items = art === 'alle' || art === 'frist' ? d.todo : [];
    items.source = art === 'alle' || art === 'frist' ? d.todo.source : undefined;

    const noneText = { alle: ['todo.empty.title', 'todo.empty.text'], frist: ['todo.empty.fristTitle', 'todo.empty.fristText'],
      post: ['todo.empty.postTitle', 'todo.empty.postText'], ki: ['settings.mcp.proposalsNone', ''] }[art];
    // v3.12.0 — fehlt eine Quelle, ist „nichts“ keine Aussage: dann kein Leerzustand, die Fehlerzeile oben sagt es
    const empty = d.failed ? '' : emptyHtml({ icon: art === 'post' ? 'eingang' : art === 'ki' ? 'ki' : 'aufgaben', title: t(noneText[0]), text: noneText[1] ? t(noneText[1]) : '', role: 'todo-empty' });

    body.innerHTML = '<div data-role="todo" data-variant="page"></div><div data-role="todo-after"></div>';
    const box = body.querySelector('[data-role="todo"]');
    renderTodoBox(box, items, {
      page: true,
      beginner: !atLeast('advanced'),
      before: kiRows,
      after: rest,
      empty,
      refresh: reload,
      afterChange: refreshCounts,
      onDraw: (root) => wireProposals(root, d, () => { if (container.isConnected) { draw(); container.querySelector(`[data-art="${art}"]`)?.focus(); } }),
    });
    const after = body.querySelector('[data-role="todo-after"]');
    if ((art === 'ki' || (art === 'alle' && d.proposals.length)) && d.admin) {
      after.innerHTML = `<div class="todo-note">${proposalsNoteHtml(d.status)}
        ${art === 'ki' ? `<div class="section-actions">
          <button type="button" class="btn btn--ghost btn--sm" data-mcp-refresh>${esc(t('settings.mcp.refresh'))}</button>
          <a class="btn btn--ghost btn--sm" href="#/connections/ai">${esc(t('settings.mcp.title'))}</a>
        </div>` : ''}</div>`;
      after.querySelector('[data-mcp-refresh]')?.addEventListener('click', () => reload());
    }
  };

  draw();
}

