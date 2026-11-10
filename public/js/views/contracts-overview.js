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
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { addDays as addDaysIso, anchorHintText } from '../lib/billing-period.js';   // v3.9.0 (L13, Skeptiker M7)
import { t, tp } from '../lib/i18n.js';
// v3.4.0 (F1024) — Folgevertrag und Verlauf je Vertrag
import { openModal } from '../components/modal.js';
import { entryTitle, reasonText, whenWho } from '../lib/activity.js';
import { uncertainRangeHtml, expectedBalance, bandText, balanceView, verdictKey, isFeedIn } from '../lib/semantics.js';   // v3.7.0 (Nachlauf)
import { advanceAmountText } from '../lib/advance-rhythm.js';   // v3.9.0 (F1034) — Abschlag mit Rhythmus
import { restNoteText } from '../lib/rest-billing.js';   // v3.9.0 (Skeptiker N7) — Rest-Abrechnung am Elternvertrag
// v3.7.0 — Zuständig: Vertrag, sonst Zähler; Filter wie in „Zu tun“ (je Gerät gemerkt)
import { assigneeOf, assigneeNames, chipHtml, filterHtml, bindFilter, filterNoteHtml, matchesAssignee, storedFilter } from '../lib/assignee.js';
import { artIcon } from '../lib/icons.js';
import { contractLetterButton, bindContractLetters } from '../lib/letter.js';   // v3.12.0 (M4) — Brief an der Zeile
import { rowMenuHtml, bindRowMenus } from '../lib/row-menu.js';   // v3.12.0 (m4b) — ein Knopf je Zeile, der Rest im Menü
import { dispatchContractModal } from './contracts.js';

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

/**
 * v3.9.0 (Gruppenverträge) — Zeilen der Mitglieder einer Gruppe, die über den
 * Gruppenvertrag rechnen, zu einer Zeile an der Stelle des ersten Mitglieds:
 * der Vertrag mit Saldo einmal, die Zähler zusammen genannt. Bis dahin stand an
 * jedem Mitglied „Kein laufender Vertrag erfasst. Vertrag anlegen“.
 * @param {Array<{meter: object, contracts: Array, group: ?{id: string, name: string, contract: object}, who: object}>} rows
 * @returns {Array<object>} dieselben Zeilen, Gruppenmitglieder zusammengefasst (`meters`)
 */
export function mergeGroupRows(rows) {
  const out = [];
  const byGroup = new Map();
  for (const r of rows || []) {
    if (!r.group) { out.push(r); continue; }
    const first = byGroup.get(r.group.id);
    if (first) { first.meters.push(r.meter); continue; }
    const row = { ...r, meters: [r.meter] };
    byGroup.set(r.group.id, row);
    out.push(row);
  }
  return out;
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

  rowCtx = new Map();
  storedByContract = new Map();
  // v3.4.0 (F1024) — Verlauf je Vertrag (erledigte Hinweise, Folgevertrag)
  const activityP = api.activity().catch(() => []);
  // Je Verbrauchsart die Zähler, je Zähler der Vertragsstatus — parallel
  const blocks = await Promise.all(utilities.map(async u => {
    try {
      // v3.7.0 — gespeicherte Verträge für die Zuständigkeit (contract-status trägt sie nicht)
      const [meters, stored, groups] = await Promise.all([api.meters(u.key), api.contracts(u.key).catch(() => []),
        api.meterGroups(u.key).catch(() => [])]);
      const byId = new Map((stored || []).map(c => [c.id, c]));
      // v3.9.0 (Gruppenverträge) — Mitglieder einer Gruppe mit Gruppenvertrag: dessen Status, je Gruppe einmal geholt
      const groupStatus = new Map();
      const groupContract = (ref) => {
        if (!groupStatus.has(ref.group_id)) {
          groupStatus.set(ref.group_id, api.contractStatus(u.key, ref.group_id)
            .then(d => (d?.contracts || []).find(c => c.contract_id === ref.contract_id && c.is_current) || null).catch(() => null));
        }
        return groupStatus.get(ref.group_id);
      };
      const rows = await Promise.all(meters.map(async m => {
        const st = await api.contractStatus(u.key, m.id).catch(() => null);
        const contracts = st?.contracts || [];
        const cur = contracts.find(x => x.is_current);
        const ref = !cur && st?.group_contract?.contract_id ? st.group_contract : null;
        const gc = ref ? await groupContract(ref) : null;
        const group = gc ? { id: ref.group_id, name: (groups || []).find(g => g.id === ref.group_id)?.name || ref.group_id, contract: gc } : null;
        return { meter: m, contracts, group, who: responsibleOf(cur ? byId.get(cur.contract_id) : gc ? byId.get(gc.contract_id) : null, m) };
      }));
      rowCtx.set(u.key, { u, meters, stored: stored || [], groups: groups || [] });
      for (const c of stored || []) storedByContract.set(c.id, c);
      return { u, rows: mergeGroupRows(rows) };
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
    <section class="card zeilen contract-rows" aria-label="${escapeHtml(t('nav.contracts'))}"><ul class="zeilen__liste">
    ${visible.filter(b => b.error || !withFilter || b.rows.length).map(utilityCard).join('')}
    </ul></section>
    ${withFilter && hidden > 0 && visible.every(b => !b.error && !b.rows.length) ? `<p class="muted" data-role="assignee-empty">${escapeHtml(t('assignee.emptyFiltered'))}</p>` : ''}
    ${withFilter ? filterNoteHtml(hidden, filter) : ''}`;
  bindFilter(container, () => draw(container, header, blocks));
  container.querySelectorAll('[data-history]').forEach(b => b.addEventListener('click', () => openHistory(b.dataset.history, b.dataset.name)));
  // v3.12.0 (M4) — Knöpfe an der Zeile: derselbe Dialog wie unter „Verträge verwalten“, danach neu lesen
  const openRow = async (b, focusAdvance) => {
    const x = rowCtx.get(b.dataset.utilityKey);
    const id = b.dataset.rowEdit || b.dataset.rowAdvance;
    const stored = x?.stored.find(c => c.id === id);
    if (!stored) return;
    const closed = dispatchContractModal(x.u, x.meters, stored, x.stored, x.groups);
    if (focusAdvance) focusAdvanceField();
    if (await closed) render(container);
  };
  container.querySelectorAll('[data-row-edit]').forEach(b => b.addEventListener('click', () => openRow(b, false)));
  container.querySelectorAll('[data-row-advance]').forEach(b => b.addEventListener('click', () => openRow(b, true)));
  for (const [key, x] of rowCtx) {
    const root = container.querySelector(`.contract-overview[data-utility="${key}"]`);
    if (root) bindContractLetters(root, key, x.stored);
  }
  bindRowMenus(container);   // v3.12.0 (m4b)
}

/** Dialog offen: das Feld des jüngsten Abschlags in den Blick (Strom und Gas: Gruppe, Wasser: Tabelle). */
async function focusAdvanceField() {
  const sel = '.modal [data-group="advance_payments"] input[data-role="amount"], .modal input[data-field="amount_eur"]';
  for (let i = 0; i < 40; i++) {
    const el = document.querySelector(sel);
    if (el) { el.focus(); el.scrollIntoView?.({ block: 'center' }); return; }
    await new Promise(r => setTimeout(r, 50));
  }
}

/** v3.4.0 (F1024) — Verlauf je Vertrags-ID (neueste zuerst, aus GET /api/activity). */
let historyByContract = new Map();
/** v3.12.0 (M4) — je Art, was der Vertragsdialog braucht; gespeicherte Verträge nach ID (Knöpfe an der Zeile). */
let rowCtx = new Map();
let storedByContract = new Map();

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
  const manage = `<a class="btn btn--ghost btn--sm" href="#/utility/${escapeHtml(u.key)}/contracts" aria-label="${escapeHtml(`${t('contractsOverview.manage')}: ${u.label}`)}">${escapeHtml(t('contractsOverview.manage'))}</a>`;
  let body;
  if (error) {
    body = `<div class="zeile-wrap"><div class="zeile"><span class="zeile__icon">${artIcon(u, { size: 20 })}</span><div class="zeile__text"><span class="zeile__titel">${escapeHtml(u.label)}</span><span class="zeile__meta">${escapeHtml(t('contractsOverview.loadFailed', { msg: error.message || String(error) }))}</span></div></div></div>`;
  } else if (!rows.length) {
    body = `<div class="zeile-wrap"><div class="zeile"><span class="zeile__icon">${artIcon(u, { size: 20 })}</span><div class="zeile__text"><span class="zeile__titel">${escapeHtml(u.label)}</span><span class="zeile__meta">${escapeHtml(t('contractsOverview.noMeter'))}</span></div></div></div>`;
  } else {
    // v3.12.0 (m4b) — „Verträge verwalten“ im Menü der ersten Zeile mit Vertrag; ohne Vertrag führt „Vertrag anlegen“ dorthin
    const withContract = rows.findIndex(r => r.group || (r.contracts || []).some(x => x.is_current));
    body = rows.map(({ meter, contracts, who, group, meters }, i) => meterBlock(u, meter, contracts, rows.length > 1, who, group, meters, i === withContract ? manage : '')).join('');
  }
  return `<li class="contract-overview" data-utility="${escapeHtml(u.key)}"><div class="contract-overview__rows">${body}</div></li>`;
}

// v3.11.0 (Nachprüfung) — exportiert für tests/rights.test.mjs
// v3.12.0 (M4) — Zeile statt Karte: Knöpfe an der Zeile (Abschlag ändern, Bearbeiten, Brief schreiben)
export function meterBlock(u, meter, contracts, showMeter, who = {}, group = null, meters = null, manage = '') {
  // v3.9.0 (Gruppenverträge) — über die Gruppe: deren Vertrag, die Zähler zusammen genannt
  const c = group ? group.contract : (contracts || []).find(x => x.is_current);
  const names = (group && meters?.length ? meters : [meter]).map(m => m.name).join(', ');
  const meterLine = showMeter ? `<div class="contract-overview__meter">${escapeHtml(t('contractsOverview.meter', { name: names }))}</div>` : '';
  const groupNote = group ? `<p class="muted small" data-role="overview-group-note">${escapeHtml(t('contractsOverview.viaGroup', { name: group.name }))}</p>` : '';
  if (!c) {
    return `<div class="contract-overview__row zeile-wrap">
      <div class="zeile">
        <span class="zeile__icon" aria-hidden="true">${artIcon(u, { size: 20 })}</span>
        <div class="zeile__text">
          <div class="zeile__titel contract-overview__name">${escapeHtml(u.label)}</div>
          ${meterLine}
          <p class="muted">${escapeHtml(t('contractsOverview.noContract'))}
            <a href="#/utility/${escapeHtml(u.key)}/contracts">${escapeHtml(t('contractsOverview.addContract'))}</a>${chipHtml(who)}</p>
        </div>
      </div>
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
  // v3.11.0 (Nachprüfung NP4) — verlängert bis zum Folgevertrag: dieser gilt, kein Kündigungstag einer Verlängerung
  if (gapless || (c.renewed && next?.gap_covered)) {
    facts.push(`<span class="ton-ok">${escapeHtml(next.provider
      ? t('contractsOverview.successor', { provider: next.provider, date: fmt.date(next.start) })
      : t('contractsOverview.successorUnnamed', { date: fmt.date(next.start) }))}</span>`);
  } else if (c.cancel_missed) {
    // v3.11.0 (Nachprüfung NP3) — Fernwärme nach § 32 AVBFernwärmeV: bis wann sie sich verlängert
    facts.push(`<span class="ton-fehler">${escapeHtml(c.renewal_term
      ? t('contractsOverview.cancelMissedHeat', { date: fmt.date(c.cancel_by), until: fmt.date(c.renewal_term.until) })
      : t('contractsOverview.cancelMissed', { date: fmt.date(c.cancel_by) }))}</span>`);
  } else if (c.is_open_ended && !c.renewed && c.notice_basis === 'unknown') {
    // v3.9.0 (L13) — unbefristet ohne eingetragene Frist: nicht „jederzeit kündbar“
    // (das behauptete die Kachel, während die Übersicht „nicht eingetragen“ sagte)
    facts.push(`<span class="muted">${escapeHtml(t('contractsOverview.noNotice'))}</span>`);
  } else if (c.is_open_ended && !c.renewed && c.switch_date && c.switch_date > addDaysIso(todayIso(), 1)) {
    // v3.9.0 (L13) — unbefristet mit Frist: frühestes Ende (letzter Tag vor dem Wechseltag);
    // ohne Frist (0 Monate) bleibt es „jederzeit kündbar“
    facts.push(escapeHtml(t('contractsOverview.earliestEnd', { date: fmt.date(addDaysIso(c.switch_date, -1)) })));
  } else if (c.renewed && c.notice_basis === 'renewal_term' && c.cancel_by) {
    // v3.11.0 (Skeptiker H3) — Fernwärme nach § 32 AVBFernwärmeV: Kündigungstag der laufenden Verlängerung
    facts.push(escapeHtml(t('contractsOverview.cancelBy', { date: fmt.date(c.cancel_by) })));
  } else if (c.renewed && c.notice_basis === 'basic_supply') {
    // v3.11.0 (Nachprüfung) — Grundversorgung: zwei Wochen Frist (§ 20 StromGVV/GasGVV, Prüfbericht 26)
    facts.push(escapeHtml(t('contractsOverview.renewedBasic')));
  } else if (c.renewed) {
    // v3.11.0 (Skeptiker H3) — verlängert: nicht „jederzeit kündbar“ (für Strom und Gas nicht sicher
    // belegt, für Fernwärme falsch); die Frist steht im Vertrag
    facts.push(`<span class="muted">${escapeHtml(t('contractsOverview.renewedNotice'))}</span>`);
  } else if (c.is_open_ended) {
    // v3.4.0 — weiterlaufend oder unbefristet: kein datierter Stichtag (vorher
    // „Kündigen bis heute“, jeden Tag ein anderes Datum)
    facts.push(escapeHtml(t('contractsOverview.anytime')));
  } else if (c.cancel_by && c.remind_basis === 'cancel_by') {
    const days = Number(c.days_to_cancel);
    const soon = Number.isFinite(days) && days >= 0 && days <= 42;
    facts.push(`<span${soon ? ' class="ton-warn"' : ''}>${escapeHtml(t('contractsOverview.cancelBy', { date: fmt.date(c.cancel_by) }))}${
      Number.isFinite(days) && days >= 0 ? ` (${escapeHtml(tp('contractsOverview.daysLeft', days))})` : ''}</span>`);
  }
  // v3.4.0 (F1024) — Folgevertrag mit Lücke: die Frist bleibt, die Lücke steht daneben
  // v3.11.0 (Skeptiker N10) — füllt die Verlängerung ohne Kündigung die Lücke, ist es keine
  if (next && !gapless && !next.gap_covered) {
    facts.push(`<span class="ton-warn">${escapeHtml(tp('contractsOverview.successorGap', Number(next.gap_days), { date: fmt.date(next.start) }))}</span>`);
  }
  // v3.9.0 (F1034) — alle zwei oder drei Monate: Betrag je Termin, nicht „im Monat“
  const advanceHtml = c.current_advance_amount == null ? '' : escapeHtml(Number(c.current_advance_every_months) > 1
    ? t('contractsOverview.advanceEvery', { amount: advanceAmountText(c.current_advance_amount, c.current_advance_every_months) })
    : t('contractsOverview.advance', { amount: fmt.eur(c.current_advance_amount) }));

  // v3.10.0 — dieselbe Quelle wie die Saldo-Karte (expectedBalance): Summe einer Abrechnung über
  // mehrere Verträge, Spanne bzw. Breite der Schätzung
  const exp = expectedBalance(c);
  const verdict = c.settle_verdict || c.verdict;
  // v3.12.0 (Sichtung S1) — Farbe und Wort nach dem Vorzeichen, neutral nur bei genau 0
  const bv = exp ? balanceView(exp.value, u) : null;
  const even = !bv || bv.credit === null;
  // v3.7.0 (Nachlauf vor dem Release) — unsichere Schätzung: die Spanne der Saldo-Karte statt eines Betrags
  const expected = exp && exp.unc
    ? `<div class="contract-overview__expected" data-role="expected-range">
        ${escapeHtml(t('contractsOverview.expected', { date: fmt.date(c.settle_on || c.effective_end) }))}
        <strong>${uncertainRangeHtml(exp.unc)}</strong> <span class="muted">(${escapeHtml(t('balanceUncertain.verdict'))})</span>
      </div>`
    : exp && verdict
    ? `<div class="contract-overview__expected">
        ${escapeHtml(t('contractsOverview.expected', { date: fmt.date(c.settle_on || c.effective_end) }))}
        <strong class="${even ? '' : bv.cls}">${escapeHtml(t('utility.verdict.' + verdictKey(exp.value, u)))} ${
          even ? '' : exp.band && bv.amount >= 1 ? `≈ ${fmt.eur0(bv.amount)}` : fmt.eur(bv.amount)}</strong>
        ${exp.band ? `<span class="muted small" data-role="expected-band">${escapeHtml(bandText(exp.band))}</span>` : ''}
      </div>`
    : '';
  // v3.9.0 (Skeptiker M7) — passt der Stichtag nicht zur letzten Rechnung: derselbe Hinweis wie an der Karte
  const anchorHint = anchorHintText(c.billing_period);

  // v3.4.0 (F1024) — Verlauf an der Vertragskarte, sobald es Einträge gibt
  const entries = historyByContract.get(c.contract_id) || [];
  const historyBtn = entries.length
    ? `<button type="button" class="btn btn--ghost btn--sm contract-overview__history" data-history="${escapeHtml(c.contract_id)}" data-name="${escapeHtml(name)}">
        ${escapeHtml(t('contractsOverview.history', { count: entries.length }))}</button>`
    : '';
  // v3.12.0 (M4) — Knöpfe an der Zeile: T03, T04 und T18 ohne den Umweg über „Verträge verwalten“
  // v3.12.0 (m4b) — ein Knopf je Zeile sichtbar (der Abschlag, bei der Einspeisung „Bearbeiten“), der Rest im
  // Menü der Zeile; die Knöpfe stehen rechts in der Zeile statt in einer eigenen Reihe darunter. Am iPhone
  // standen vier Knöpfe in zwei Reihen, am Mac war die Seite 1.340 px hoch.
  const stored = storedByContract.get(c.contract_id);
  // Die Einspeisung kennt keine Abschläge (der Netzbetreiber zahlt aus)
  const advanceBtn = stored && !isFeedIn(u) ? `<button type="button" class="btn btn--ghost btn--sm" data-row-advance="${escapeHtml(c.contract_id)}" data-utility-key="${escapeHtml(u.key)}" aria-label="${escapeHtml(t('contractsOverview.rowAdvanceNamed', { name }))}">${escapeHtml(t('contractsOverview.rowAdvance'))}</button>` : '';
  const editBtn = stored ? `<button type="button" class="btn btn--ghost btn--sm" data-row-edit="${escapeHtml(c.contract_id)}" data-utility-key="${escapeHtml(u.key)}" aria-label="${escapeHtml(t('contractsOverview.rowEditNamed', { name }))}">${escapeHtml(t('contractsOverview.rowEdit'))}</button>` : '';
  const first = advanceBtn || editBtn;
  const menu = rowMenuHtml({ label: t('rowMenu.label', { name: `${u.label}: ${name}` }),
    items: [advanceBtn ? editBtn : '', stored ? contractLetterButton(stored) : '', historyBtn, manage] });
  const actions = `${first}${menu}`;

  return `<div class="contract-overview__row zeile-wrap"${group ? ` data-group="${escapeHtml(group.id)}"` : ''} data-contract="${escapeHtml(c.contract_id)}">
      <div class="zeile">
        <span class="zeile__icon" aria-hidden="true">${artIcon(u, { size: 20 })}</span>
        <div class="zeile__text">
          <div class="zeile__titel contract-overview__name">${escapeHtml(u.label)}: ${escapeHtml(name)}${chipHtml(who)}</div>
          ${meterLine}
          ${groupNote}
          <div class="zeile__meta">${escapeHtml(term)}</div>
          ${facts.length ? `<div class="zeile__meta contract-overview__facts">${facts.join('<span aria-hidden="true"> · </span>')}</div>` : ''}
          ${c.rest_of ? `<p class="muted small" data-role="overview-rest-note">${escapeHtml(restNoteText(c.rest_of, u.consumption_unit))}</p>` : ''}
          ${anchorHint ? `<p class="muted small" data-role="overview-anchor-hint">${escapeHtml(anchorHint)}</p>` : ''}
        </div>
        <div class="zeile__werte">
          <div class="zeile__wertspalte">
            ${advanceHtml ? `<div class="zeile__wert" data-role="overview-advance">${advanceHtml}</div>` : ''}
            ${actions.trim() ? `<div class="zeile__aktionen zeile__aktionen--rechts">${actions}</div>` : ''}
          </div>
          ${expected}
        </div>
      </div>
    </div>`;
}
