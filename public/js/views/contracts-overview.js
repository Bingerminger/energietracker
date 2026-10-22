// =====================================================================
// Energietracker v2.11.0 — Verträge & Abschläge (Review UI-12)
//
// Die zweitwichtigste Frage der Nutzer — „Was kostet es, bin ich im Plan,
// wann muss ich kündigen?" — hatte bis v2.10 keinen Platz im Menü: Verträge
// erreichte man nur über „Verträge verwalten" auf der Seite einer
// Verbrauchsart. Diese Seite zeigt je Verbrauchsart und Zähler den laufenden
// Vertrag mit Frist, Abschlag und dem, was zur Abrechnung zu erwarten ist.
// Gerechnet wird nichts Neues: Die Zahlen kommen aus `contract-status`,
// derselben Quelle wie die Saldo-Karte der Verbrauchsart.
// =====================================================================

import { api } from '../api.js';
import { activeUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { t, tp } from '../lib/i18n.js';
// v3.4.0 (F1024) — Folgevertrag und Verlauf je Vertrag
import { openModal } from '../components/modal.js';
import { entryTitle, reasonText, whenWho } from '../lib/activity.js';
import { uncertainRangeText } from '../lib/semantics.js';   // v3.7.0 (Nachlauf)
// v3.7.0 — Zuständig: Vertrag, sonst Zähler; Filter wie in „Zu tun“ (je Gerät gemerkt)
import { assigneeOf, assigneeNames, chipHtml, filterHtml, bindFilter, filterNoteHtml, matchesAssignee, storedFilter } from '../lib/assignee.js';

/**
 * v3.7.0 — wer für die Fristen eines Zählers mit laufendem Vertrag zuständig
 * ist: die Person des Vertrags, sonst die des Zählers (wie die Agenda). Form
 * wie ein Agenda-Ereignis (`assignee*`, `assignee_source`).
 */
export function responsibleOf(contract, meter) {
  for (const [x, source] of [[contract, 'contract'], [meter, 'meter']]) {
    if (x && (x.assignee || x.assignee_former)) {
      return { assignee: x.assignee ?? null, assignee_id: x.assignee_id ?? null, assignee_former: !!x.assignee_former,
               assignee_deleted: !!x.assignee_deleted, assignee_source: source };
    }
  }
  return {};
}

export async function render(container) {
  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.contracts'))}</h1>
        <p class="view-header__subtitle">${escapeHtml(t('contractsOverview.subtitle'))}</p>
      </div>
    </div>`;
  container.innerHTML = `${header}<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;

  // v2.13.0 — ohne Heizöl und Pellets: deren Kosten stehen an den Lieferungen,
  // der Link „Vertrag anlegen" führte auf eine Seite ohne Verträge
  const utilities = (await activeUtilities()).filter(u => u.has_contracts !== false && u.reading_kind !== 'delivery');
  if (!utilities.length) {
    container.innerHTML = `${header}<div class="empty"><p>${escapeHtml(t('contractsOverview.noUtilities'))}</p></div>`;
    return;
  }

  // v3.4.0 (F1024) — Verlauf je Vertrag (erledigte Hinweise, Folgevertrag)
  const activityP = api.activity().catch(() => []);
  // Je Verbrauchsart die Zähler, je Zähler der Vertragsstatus — parallel
  const blocks = await Promise.all(utilities.map(async u => {
    try {
      // v3.7.0 — gespeicherte Verträge für die Zuständigkeit (contract-status trägt sie nicht)
      const [meters, stored] = await Promise.all([api.meters(u.key), api.contracts(u.key).catch(() => [])]);
      const byId = new Map((stored || []).map(c => [c.id, c]));
      const rows = await Promise.all(meters.map(async m => {
        const contracts = await api.contractStatus(u.key, m.id).then(d => d?.contracts || []).catch(() => []);
        const cur = contracts.find(x => x.is_current);
        return { meter: m, contracts, who: responsibleOf(cur ? byId.get(cur.contract_id) : null, m) };
      }));
      return { u, rows };
    } catch (e) {
      return { u, error: e };
    }
  }));

  const byContract = new Map();
  for (const e of await activityP) {
    const cid = e.ref?.contract_id;
    if (!cid) continue;
    if (!byContract.has(cid)) byContract.set(cid, []);
    byContract.get(cid).push(e);
  }
  historyByContract = byContract;
  draw(container, header, blocks);
}

/**
 * v3.7.0 — zeichnen, mit Filter nach Zuständigkeit, sobald ein laufender
 * Vertrag oder Zähler eine Person hat (oder ein Filter gemerkt ist).
 */
function draw(container, header, blocks) {
  const filter = storedFilter();
  const all = blocks.flatMap(b => b.rows || []);
  const withFilter = all.some(r => assigneeOf(r.who)) || filter !== 'all';
  const shown = (b) => withFilter && b.rows ? { ...b, rows: b.rows.filter(r => matchesAssignee(r.who, filter)) } : b;
  const visible = blocks.map(shown);
  const hidden = all.length - visible.reduce((n, b) => n + (b.rows?.length || 0), 0);
  container.innerHTML = `${header}
    ${withFilter ? filterHtml({ id: 'co-assignee', value: filter, names: assigneeNames(all.map(r => r.who)) }) : ''}
    ${visible.filter(b => b.error || !withFilter || b.rows.length).map(utilityCard).join('')}
    ${withFilter && hidden > 0 && visible.every(b => !b.error && !b.rows.length) ? `<p class="muted" data-role="assignee-empty">${escapeHtml(t('assignee.emptyFiltered'))}</p>` : ''}
    ${withFilter ? filterNoteHtml(hidden, filter) : ''}`;
  bindFilter(container, () => draw(container, header, blocks));
  container.querySelectorAll('[data-history]').forEach(b => b.addEventListener('click', () => openHistory(b.dataset.history, b.dataset.name)));
}

/** v3.4.0 (F1024) — Verlauf je Vertrags-ID (neueste zuerst, aus GET /api/activity). */
let historyByContract = new Map();

/** Verlauf eines Vertrags: was erledigt wurde, von wem und wann. */
function openHistory(cid, name) {
  const list = historyByContract.get(cid) || [];
  openModal({
    title: t('contractsOverview.historyTitle', { name }),
    body: list.length ? `<ul class="activity-list">${list.map(e => `<li class="activity-item${e.undone ? ' activity-item--undone' : ''}">
        <div class="activity-item__body">
          <div class="activity-item__title">${e.undone ? `<s>${escapeHtml(entryTitle(e))}</s>` : escapeHtml(entryTitle(e))}</div>
          <div class="muted small">${escapeHtml(whenWho(e.done_on, e.by))}</div>
          ${e.reason ? `<div class="muted small">${escapeHtml(t('activity.reasonLine', { reason: reasonText(e.reason) }))}</div>` : ''}
          ${e.note ? `<div class="activity-item__note">${escapeHtml(e.note)}</div>` : ''}
        </div></li>`).join('')}</ul>`
      : `<p class="muted">${escapeHtml(t('activity.empty'))}</p>`,
  });
}

function utilityCard({ u, rows, error }) {
  const manage = `<a class="btn btn--ghost btn--sm" href="#/utility/${escapeHtml(u.key)}/contracts">${escapeHtml(t('contractsOverview.manage'))}</a>`;
  let body;
  if (error) {
    body = `<p class="muted">${escapeHtml(t('contractsOverview.loadFailed', { msg: error.message || String(error) }))}</p>`;
  } else if (!rows.length) {
    body = `<p class="muted">${escapeHtml(t('contractsOverview.noMeter'))}</p>`;
  } else {
    body = rows.map(({ meter, contracts, who }) => meterBlock(u, meter, contracts, rows.length > 1, who)).join('');
  }
  return `
    <section class="card contract-overview card--${escapeHtml(u.key)}" data-utility="${escapeHtml(u.key)}">
      <div class="card__title">
        <span aria-hidden="true">${escapeHtml(u.icon || '')}</span> ${escapeHtml(u.label)}
        <span class="card__title-action">${manage}</span>
      </div>
      ${body}
    </section>`;
}

function meterBlock(u, meter, contracts, showMeter, who = {}) {
  const c = (contracts || []).find(x => x.is_current);
  const meterLine = showMeter ? `<div class="contract-overview__meter">${escapeHtml(t('contractsOverview.meter', { name: meter.name }))}</div>` : '';
  if (!c) {
    return `<div class="contract-overview__row">${meterLine}
      <p class="muted">${escapeHtml(t('contractsOverview.noContract'))}
        <a href="#/utility/${escapeHtml(u.key)}/contracts">${escapeHtml(t('contractsOverview.addContract'))}</a>${chipHtml(who)}</p>
    </div>`;
  }
  const name = [c.provider, c.tariff_name].filter(Boolean).join(' · ') || '–';
  const term = c.is_open_ended
    ? t('contractsOverview.openEnded', { start: fmt.date(c.start) })
    : c.renewed
      ? t('contractsOverview.renewed', { start: fmt.date(c.start) })
      : t('contractsOverview.term', { start: fmt.date(c.start), end: fmt.date(c.end) });

  const facts = [];
  // v3.4.0 (F1024) — lückenloser Folgevertrag: nichts mehr zu kündigen
  const next = c.successor;
  const gapless = next && Number(next.gap_days) === 0;
  if (gapless) {
    facts.push(`<span class="success-text">${escapeHtml(next.provider
      ? t('contractsOverview.successor', { provider: next.provider, date: fmt.date(next.start) })
      : t('contractsOverview.successorUnnamed', { date: fmt.date(next.start) }))}</span>`);
  } else if (c.cancel_missed) {
    facts.push(`<span class="danger-text">${escapeHtml(t('contractsOverview.cancelMissed', { date: fmt.date(c.cancel_by) }))}</span>`);
  } else if (c.renewed || c.is_open_ended) {
    // v3.4.0 — weiterlaufend oder unbefristet: kein datierter Stichtag (vorher
    // „Kündigen bis heute“, jeden Tag ein anderes Datum)
    facts.push(escapeHtml(t('contractsOverview.anytime')));
  } else if (c.cancel_by && c.remind_basis === 'cancel_by') {
    const days = Number(c.days_to_cancel);
    const soon = Number.isFinite(days) && days >= 0 && days <= 42;
    facts.push(`<span${soon ? ' class="warning-text"' : ''}>${escapeHtml(t('contractsOverview.cancelBy', { date: fmt.date(c.cancel_by) }))}${
      Number.isFinite(days) && days >= 0 ? ` (${escapeHtml(tp('contractsOverview.daysLeft', days))})` : ''}</span>`);
  }
  // v3.4.0 (F1024) — Folgevertrag mit Lücke: die Frist bleibt, die Lücke steht daneben
  if (next && !gapless) {
    facts.push(`<span class="warning-text">${escapeHtml(tp('contractsOverview.successorGap', Number(next.gap_days), { date: fmt.date(next.start) }))}</span>`);
  }
  if (c.current_advance_amount != null) {
    facts.push(escapeHtml(t('contractsOverview.advance', { amount: fmt.eur(c.current_advance_amount) })));
  }

  const verdict = c.verdict;
  const good = verdict === 'refund' || verdict === 'payout';
  const bad = verdict === 'surcharge' || verdict === 'reclaim';
  // v3.7.0 (Nachlauf vor dem Release) — unsichere Schätzung: die Spanne der Saldo-Karte statt eines Betrags
  const expected = c.projected_end_balance != null && c.projection_uncertain
    ? `<div class="contract-overview__expected" data-role="expected-range">
        ${escapeHtml(t('contractsOverview.expected', { date: fmt.date(c.effective_end) }))}
        <strong>${escapeHtml(uncertainRangeText(c.projection_uncertain))}</strong> <span class="muted">(${escapeHtml(t('balanceUncertain.verdict'))})</span>
      </div>`
    : c.projected_end_balance != null && verdict
    ? `<div class="contract-overview__expected">
        ${escapeHtml(t('contractsOverview.expected', { date: fmt.date(c.effective_end) }))}
        <strong class="${good ? 'success-text' : bad ? 'danger-text' : ''}">${escapeHtml(t('utility.verdict.' + verdict))} ${
          verdict === 'balanced' ? '' : fmt.eur(Math.abs(c.projected_end_balance))}</strong>
      </div>`
    : '';

  // v3.4.0 (F1024) — Verlauf an der Vertragskarte, sobald es Einträge gibt
  const entries = historyByContract.get(c.contract_id) || [];
  const historyBtn = entries.length
    ? `<button type="button" class="btn btn--ghost btn--sm contract-overview__history" data-history="${escapeHtml(c.contract_id)}" data-name="${escapeHtml(name)}">
        ${escapeHtml(t('contractsOverview.history', { count: entries.length }))}</button>`
    : '';

  return `<div class="contract-overview__row">
      ${meterLine}
      <div class="contract-overview__name">${escapeHtml(name)}${chipHtml(who)}</div>
      <div class="muted">${escapeHtml(term)}</div>
      ${facts.length ? `<div class="contract-overview__facts">${facts.join('<span aria-hidden="true"> · </span>')}</div>` : ''}
      ${expected}
      ${historyBtn}
    </div>`;
}
