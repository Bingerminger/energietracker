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

export async function render(container) {
  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.contracts'))}</h1>
        <p class="view-header__subtitle">${escapeHtml(t('contractsOverview.subtitle'))}</p>
      </div>
    </div>`;
  container.innerHTML = `${header}<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;

  const utilities = (await activeUtilities()).filter(u => u.has_contracts !== false);
  if (!utilities.length) {
    container.innerHTML = `${header}<div class="empty"><p>${escapeHtml(t('contractsOverview.noUtilities'))}</p></div>`;
    return;
  }

  // Je Verbrauchsart die Zähler, je Zähler der Vertragsstatus — parallel
  const blocks = await Promise.all(utilities.map(async u => {
    try {
      const meters = await api.meters(u.key);
      const rows = await Promise.all(meters.map(async m => ({
        meter: m,
        contracts: await api.contractStatus(u.key, m.id).then(d => d?.contracts || []).catch(() => []),
      })));
      return { u, rows };
    } catch (e) {
      return { u, error: e };
    }
  }));

  container.innerHTML = `${header}${blocks.map(utilityCard).join('')}`;
}

function utilityCard({ u, rows, error }) {
  const manage = `<a class="btn btn--ghost btn--sm" href="#/utility/${escapeHtml(u.key)}/contracts">${escapeHtml(t('contractsOverview.manage'))}</a>`;
  let body;
  if (error) {
    body = `<p class="muted">${escapeHtml(t('contractsOverview.loadFailed', { msg: error.message || String(error) }))}</p>`;
  } else if (!rows.length) {
    body = `<p class="muted">${escapeHtml(t('contractsOverview.noMeter'))}</p>`;
  } else {
    body = rows.map(({ meter, contracts }) => meterBlock(u, meter, contracts, rows.length > 1)).join('');
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

function meterBlock(u, meter, contracts, showMeter) {
  const c = (contracts || []).find(x => x.is_current);
  const meterLine = showMeter ? `<div class="contract-overview__meter">${escapeHtml(t('contractsOverview.meter', { name: meter.name }))}</div>` : '';
  if (!c) {
    return `<div class="contract-overview__row">${meterLine}
      <p class="muted">${escapeHtml(t('contractsOverview.noContract'))}
        <a href="#/utility/${escapeHtml(u.key)}/contracts">${escapeHtml(t('contractsOverview.addContract'))}</a></p>
    </div>`;
  }
  const name = [c.provider, c.tariff_name].filter(Boolean).join(' · ') || '–';
  const term = c.is_open_ended
    ? t('contractsOverview.openEnded', { start: fmt.date(c.start) })
    : c.renewed
      ? t('contractsOverview.renewed', { start: fmt.date(c.start) })
      : t('contractsOverview.term', { start: fmt.date(c.start), end: fmt.date(c.end) });

  const facts = [];
  if (c.cancel_missed) {
    facts.push(`<span class="danger-text">${escapeHtml(t('contractsOverview.cancelMissed', { date: fmt.date(c.cancel_by) }))}</span>`);
  } else if (c.cancel_by) {
    const days = Number(c.days_to_cancel);
    const soon = Number.isFinite(days) && days >= 0 && days <= 42;
    facts.push(`<span${soon ? ' class="warning-text"' : ''}>${escapeHtml(t('contractsOverview.cancelBy', { date: fmt.date(c.cancel_by) }))}${
      Number.isFinite(days) && days >= 0 ? ` (${escapeHtml(tp('contractsOverview.daysLeft', days))})` : ''}</span>`);
  }
  if (c.current_advance_amount != null) {
    facts.push(escapeHtml(t('contractsOverview.advance', { amount: fmt.eur(c.current_advance_amount) })));
  }

  const verdict = c.verdict;
  const good = verdict === 'refund' || verdict === 'payout';
  const bad = verdict === 'surcharge' || verdict === 'reclaim';
  const expected = c.projected_end_balance != null && verdict
    ? `<div class="contract-overview__expected">
        ${escapeHtml(t('contractsOverview.expected', { date: fmt.date(c.effective_end) }))}
        <strong class="${good ? 'success-text' : bad ? 'danger-text' : ''}">${escapeHtml(t('utility.verdict.' + verdict))} ${
          verdict === 'balanced' ? '' : fmt.eur(Math.abs(c.projected_end_balance))}</strong>
      </div>`
    : '';

  return `<div class="contract-overview__row">
      ${meterLine}
      <div class="contract-overview__name">${escapeHtml(name)}</div>
      <div class="muted">${escapeHtml(term)}</div>
      ${facts.length ? `<div class="contract-overview__facts">${facts.join('<span aria-hidden="true"> · </span>')}</div>` : ''}
      ${expected}
    </div>`;
}
