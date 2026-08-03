// =====================================================================
// Energietracker v2.3.0 — Tarifvergleich
//
// Die Ansicht beantwortet die Frage, um die es im Energietracker geht:
// **Soll ich wechseln?** Sie ist in zwei Blöcke geteilt, und die
// Reihenfolge ist Absicht:
//
//   1. Wechselentscheidung (oben) — der erwartete Jahresverbrauch als
//      Zahl zum Mitnehmen, der frühestmögliche Wechseltermin, und die
//      Angebote im Ranking. Das ist die Handlung.
//   2. Rückblick (unten) — dieselben Tarife auf die tatsächlich
//      gemessenen Monate gelegt. Das ist der Beleg: Wer sieht, dass die
//      Rechnung auf echten Daten aufgeht, glaubt auch der Prognose.
//
// Neu gegenüber v2.2.0:
//   - Verbrauchsprognose statt reinem Rückblick; Vergleichsfenster sind
//     12 Monate ab Wechseltermin, saisonal gewichtet.
//   - Jahr 1 (mit Neukundenbonus) und ab Jahr 2 getrennt. Sortiert wird
//     nach Jahr 2 — sonst gewinnt jedes Lockangebot.
//   - Break-even-Verbrauch statt einer Ersparnis auf den Euro genau.
//   - Kostenverlauf als Overlay: Angebot über den Bestandsvertrag gelegt.
//   - Kündigungsfrist, Preisgarantie und Bonus am Angebot pflegbar.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';
import { makeChart } from '../components/chart.js';
import { fmt as f, escapeHtml as esc, monthShortNames } from '../lib/format.js';
import { t } from '../lib/i18n.js';

let sel = { utility: null, meterId: null, year: null, switchDate: null };
let charts = { switch: null, retro: null };
let lastSwitch = null;
let lastRetro = null;
// Das Utility-Objekt aus der SSOT — wegen `color`. Die CSS-Variable
// `--util-<key>` taugt dafür nicht: Sie ist im Hellmodus ein
// `color-mix()`-Ausdruck, den die Canvas-API nicht versteht.
let currentUtility = null;

function destroyCharts() {
  for (const k of Object.keys(charts)) {
    if (charts[k]) { try { charts[k].destroy(); } catch {} charts[k] = null; }
  }
}

export async function render(container) {
  container.innerHTML = `<div class="loading" role="status">${esc(t('tariff.loading'))}</div>`;
  let utilities, settings;
  try {
    [utilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }

  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities : utilities.map(u => u.key);
  // Nur vertragsbasierte Arten. Wasser nutzt ein Drei-Komponenten-Modell,
  // Heizöl/Pellets sind lieferbasiert (die Rechnung ist die Kostenbasis),
  // PV-Erzeugung führt gar keine Verträge.
  const usable = utilities.filter(u =>
    active.includes(u.key) &&
    u.key !== 'wasser' &&
    u.reading_kind !== 'delivery' &&
    u.has_contracts !== false);

  if (usable.length === 0) {
    container.innerHTML = `<div class="banner banner--info">${esc(t('tariff.noUsable'))}</div>`;
    return;
  }
  if (!sel.utility || !usable.find(u => u.key === sel.utility)) {
    sel.utility = usable[0].key;
    sel.meterId = null;
    sel.switchDate = null;
  }
  const utility = usable.find(u => u.key === sel.utility);
  currentUtility = utility;
  container.setAttribute('data-utility', utility.key);

  let meters = [];
  try { meters = await api.meters(sel.utility); } catch {}
  if (!sel.meterId || !meters.find(m => m.id === sel.meterId)) {
    sel.meterId = meters[0]?.id || null;
  }

  container.innerHTML = `
    <div class="view-head">
      <h1>${esc(t('tariff.title'))}</h1>
      <p class="muted">${esc(t('tariff.subtitle'))}</p>
    </div>
    <div class="toolbar">
      <label>${esc(t('tariff.utility'))}
        <select id="t-util">${usable.map(u =>
          `<option value="${esc(u.key)}" ${u.key === sel.utility ? 'selected' : ''}>${esc(u.label)}</option>`).join('')}</select>
      </label>
      <label>${esc(t('tariff.meter'))}
        <select id="t-meter">${meters.map(m =>
          `<option value="${esc(m.id)}" ${m.id === sel.meterId ? 'selected' : ''}>${esc(m.name || m.id)}</option>`).join('')}</select>
      </label>
      <button class="btn btn--util" id="t-addshadow">${esc(t('tariff.addShadow'))}</button>
    </div>

    <section id="t-switch" aria-live="polite">
      <div class="loading" role="status">${esc(t('tariff.loadingComparison'))}</div>
    </section>

    <section id="t-retro" style="margin-top: var(--sp-5)"></section>
  `;

  container.querySelector('#t-util').addEventListener('change', e => {
    sel.utility = e.target.value; sel.meterId = null; sel.switchDate = null;
    destroyCharts(); render(container);
  });
  container.querySelector('#t-meter').addEventListener('change', e => {
    sel.meterId = e.target.value; sel.switchDate = null; loadAll(container);
  });
  container.querySelector('#t-addshadow').addEventListener('click', () =>
    openShadowForm(container, null));

  await loadAll(container);
  return destroyCharts;
}

async function loadAll(container) {
  destroyCharts();
  await Promise.all([loadSwitch(container), loadRetro(container)]);
}

// ─────────────────────────────────────────────────────────────────────
// Block 1 — Wechselentscheidung
// ─────────────────────────────────────────────────────────────────────

async function loadSwitch(container) {
  const box = container.querySelector('#t-switch');
  if (!box) return;
  if (!sel.meterId) {
    box.innerHTML = `<div class="banner banner--info">${esc(t('tariff.noMeter'))}</div>`;
    return;
  }
  box.innerHTML = `<div class="loading" role="status">${esc(t('tariff.loadingComparison'))}</div>`;

  let d;
  try {
    d = await api.tariffSwitch(sel.utility, sel.meterId, sel.switchDate);
  } catch (e) {
    box.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }
  lastSwitch = d;

  if (!d.supported) {
    box.innerHTML = `<div class="banner banner--info">${esc(d.note || t('tariff.notSupported'))}</div>`;
    return;
  }

  const unit = d.unit || 'kWh';
  const offers = (d.candidates || []).filter(c => !c.is_reference);

  box.innerHTML = `
    <h2 class="section-title">${esc(t('tariff.switch.heading'))}</h2>

    <div class="switch-head">
      ${consumptionCardHtml(d, unit)}
      ${timingCardHtml(d)}
    </div>

    ${offers.length === 0
      ? `<div class="banner banner--info switch-empty">
           <p>${esc(t('tariff.switch.noOffers', { value: f.int(d.expected_consumption), unit }))}</p>
           <button class="btn btn--util" id="t-addshadow-2">${esc(t('tariff.switch.addOffer'))}</button>
         </div>`
      : `
        <div class="table-wrap">
          <table class="data-table tariff-table">
            <thead><tr>
              <th scope="col">${esc(t('tariff.switch.col.tariff'))}</th>
              <th scope="col" class="num" title="${esc(t('tariff.switch.year1Title'))}">${esc(t('tariff.switch.col.year1'))}</th>
              <th scope="col" class="num" title="${esc(t('tariff.switch.year2Title'))}">${esc(t('tariff.switch.col.year2'))}</th>
              <th scope="col" class="num">${esc(t('tariff.switch.col.diff'))}</th>
              <th scope="col" class="num" title="${esc(t('tariff.switch.breakEvenTitle'))}">${esc(t('tariff.switch.col.breakEven'))}</th>
              <th scope="col"><span class="sr-only">${esc(t('common.actions'))}</span></th>
            </tr></thead>
            <tbody>${d.candidates.map(c => candidateRowHtml(c, unit, d)).join('')}</tbody>
          </table>
        </div>
        <p class="muted small">${esc(t('tariff.switch.rankingHint'))}</p>

        <div class="card" style="margin-top: var(--sp-4)">
          <div class="card__title">${esc(t('tariff.switch.chartTitle'))}</div>
          <div class="chart-wrap h300"><canvas id="t-switch-chart"></canvas></div>
        </div>`}
  `;

  const add2 = box.querySelector('#t-addshadow-2');
  if (add2) add2.addEventListener('click', () => openShadowForm(container, null));

  wireConsumptionCopy(box, d, unit);
  wireSwitchDate(box, container);
  wireRowActions(box, container);
  if (offers.length) drawSwitchChart(box, d, unit);
}

/**
 * Die Karte, die den ganzen Ablauf trägt: Der erwartete Jahresverbrauch
 * ist exakt die Eingabe, die CHECK24 und Verivox verlangen. Deshalb steht
 * er groß und ist mit einem Klick kopierbar — der Nutzer geht damit raus
 * und kommt mit einem Angebot zurück.
 */
function consumptionCardHtml(d, unit) {
  const q = d.forecast || {};
  const quality = q.months_of_history != null
    ? t('tariff.switch.basis', { months: q.months_of_history })
    : '';
  return `
    <div class="card switch-card switch-card--consumption">
      <div class="card__title">${esc(t('tariff.switch.expectedConsumption'))}</div>
      <p class="switch-bignum">
        <strong>${f.int(d.expected_consumption)}</strong> <span class="unit">${esc(unit)}</span>
      </p>
      <p class="muted small">${esc(t('tariff.switch.expectedHint'))}</p>
      <button class="btn btn--sm btn--ghost" id="t-copy-consumption">
        <span aria-hidden="true">⧉</span> ${esc(t('tariff.switch.copy'))}
      </button>
      ${quality ? `<p class="muted small">${esc(quality)}</p>` : ''}
    </div>`;
}

/**
 * Wechseltermin und Kündigungsfrist. Die Frist ist das, was man im Alltag
 * verpasst — deshalb wird sie mit Restlaufzeit angezeigt und farblich
 * hervorgehoben, sobald es eng wird.
 */
function timingCardHtml(d) {
  const c = d.current;
  const src = {
    contract: t('tariff.switch.source.contract'),
    override: t('tariff.switch.source.override'),
    default:  t('tariff.switch.source.default'),
  }[d.switch_date_source] || '';

  let cancelLine = '';
  if (c && c.cancel_by) {
    const days = c.days_to_cancel;
    const cls = days == null ? '' : (days < 0 ? 'danger-text' : (days <= 45 ? 'warning-text' : ''));
    const note = days == null ? ''
      : days < 0 ? t('tariff.switch.cancelPassed')
      : t('tariff.switch.cancelIn', { days });
    cancelLine = `<p class="${cls}">
      ${esc(t('tariff.switch.cancelBy', { date: f.date(c.cancel_by) }))}
      ${note ? `<span class="muted">· ${esc(note)}</span>` : ''}
    </p>`;
  } else if (c) {
    // Ohne gepflegte Frist keinen Termin behaupten, sondern danach fragen.
    cancelLine = `<p class="muted small">${esc(t('tariff.switch.noNotice'))}</p>`;
  }

  // Ist der Folgevertrag schon abgeschlossen, ist der Wechsel für die nächste
  // Periode bereits vollzogen — das muss dastehen, sonst wirkt der weit
  // entfernte Termin wie ein Fehler.
  const followUp = c?.follow_up
    ? `<p class="muted small">${esc(t('tariff.switch.followUp', {
        label: c.follow_up.label,
        from: f.date(c.follow_up.start),
        to: c.follow_up.end ? f.date(c.follow_up.end) : '—',
      }))}</p>`
    : '';

  return `
    <div class="card switch-card">
      <div class="card__title">${esc(t('tariff.switch.switchDate'))}</div>
      <div class="switch-date-row">
        <input type="date" id="t-switch-date" value="${esc(d.switch_date || '')}"
               aria-label="${esc(t('tariff.switch.switchDate'))}">
        ${src ? `<span class="badge badge--info">${esc(src)}</span>` : ''}
        ${sel.switchDate ? `<button class="btn btn--xs btn--ghost" id="t-switch-reset">${esc(t('tariff.switch.reset'))}</button>` : ''}
      </div>
      ${c ? `<p class="muted small">${esc(t('tariff.switch.currentContract', { label: c.label }))}
             ${c.end ? esc(t('tariff.switch.runsUntil', { date: f.date(c.end) })) : ''}</p>` : ''}
      ${followUp}
      ${cancelLine}
      <p class="muted small">${esc(t('tariff.switch.window', {
        from: d.window.from, to: d.window.to, months: d.window.months }))}</p>
    </div>`;
}

function candidateRowHtml(c, unit, d) {
  if (!c.computable) {
    return `<tr class="${c.is_shadow ? 'row-shadow' : ''}" data-contract="${esc(c.contract_id)}">
      <td>${badgeFor(c)}${esc(c.label)}</td>
      <td colspan="4" class="muted">${esc(c.note || '')}</td>
      <td class="cell-actions">${rowActionsHtml(c)}</td>
    </tr>`;
  }

  const diff = c.vs_reference_year2_eur;
  const diffCls = diff == null ? '' : (diff < 0 ? 'success-text' : (diff > 0 ? 'danger-text' : ''));
  const diffStr = c.is_reference
    ? `<span class="muted">${esc(t('tariff.switch.reference'))}</span>`
    : diff == null ? '<span class="dim">–</span>'
    : `${diff > 0 ? '+' : ''}${f.eur(diff)}${c.vs_reference_year2_pct != null
        ? ` <span class="muted">(${c.vs_reference_year2_pct > 0 ? '+' : ''}${f.num(c.vs_reference_year2_pct, 1)} %)</span>` : ''}`;

  // Der Break-even sagt mehr als eine Ersparnis auf den Euro: Liegt er weit
  // vom erwarteten Verbrauch weg, trägt die Entscheidung auch dann, wenn die
  // Prognose danebenliegt. Deshalb steht der Abstand in Prozent dabei.
  const be = c.break_even_consumption;
  const beStr = c.is_reference ? '<span class="dim">–</span>'
    : be == null ? `<span class="dim" title="${esc(t('tariff.switch.breakEvenNone'))}">–</span>`
    : `${f.int(be)} <span class="muted">${esc(unit)}</span>${c.break_even_delta_pct != null
        ? `<div class="muted small">${esc(t('tariff.switch.breakEvenDelta', {
             pct: (c.break_even_delta_pct > 0 ? '+' : '') + f.num(c.break_even_delta_pct, 0) }))}</div>` : ''}`;

  const bonusLine = c.signup_bonus_eur
    ? `<div class="muted small">${esc(t('tariff.switch.bonusIncluded', { value: f.eur(c.signup_bonus_eur) }))}</div>`
    : '';

  const guaranteeLine = c.guarantee_ends_in_window
    ? `<div class="muted small warning-text">${esc(t('tariff.switch.guaranteeEnds', {
         date: f.date(c.guarantee_until) }))}</div>`
    : '';

  const sens = c.sensitivity
    ? `<div class="muted small">${esc(t('tariff.switch.sensitivity', {
         low: f.eur(c.sensitivity.low), high: f.eur(c.sensitivity.high) }))}</div>`
    : '';

  return `<tr class="${c.is_shadow ? 'row-shadow' : ''}${c.is_reference ? ' row-reference' : ''}"
              data-contract="${esc(c.contract_id)}">
    <td>${badgeFor(c)}${esc(c.label)}${guaranteeLine}</td>
    <td class="num">${f.eur(c.year1_eur)}${bonusLine}</td>
    <td class="num"><strong>${f.eur(c.year2_eur)}</strong>${sens}</td>
    <td class="num ${diffCls}">${diffStr}</td>
    <td class="num">${beStr}</td>
    <td class="cell-actions">${rowActionsHtml(c)}</td>
  </tr>`;
}

function badgeFor(c) {
  if (c.is_reference) return `<span class="badge">${esc(t('tariff.switch.current'))}</span> `;
  if (c.is_shadow)    return `<span class="badge badge--shadow">${esc(t('tariff.shadowBadge'))}</span> `;
  return '';
}

function rowActionsHtml(c) {
  if (!c.is_shadow) return '';
  return `
    <button class="btn btn--xs btn--ghost" data-edit-shadow="${esc(c.contract_id)}"
            title="${esc(t('tariff.action.edit'))}" aria-label="${esc(t('tariff.action.edit'))}"><span aria-hidden="true">✎</span></button>
    <button class="btn btn--xs btn--ghost" data-delete-shadow="${esc(c.contract_id)}"
            title="${esc(t('tariff.action.delete'))}" aria-label="${esc(t('tariff.action.delete'))}"><span aria-hidden="true">🗑</span></button>`;
}

/**
 * Der Kostenverlauf über das Vergleichsfenster — ein Angebot über den
 * Bestandsvertrag gelegt. Monatlich statt als Jahressumme, weil man erst
 * daran sieht, wo die Differenz herkommt: Bei Gas entsteht sie fast
 * vollständig im Winter.
 *
 * Monate jenseits der Preisgarantie werden gestrichelt gezeichnet — dort
 * ist der Preis eine Annahme, keine Zusage.
 */
function drawSwitchChart(box, d, unit) {
  const canvas = box.querySelector('#t-switch-chart');
  if (!canvas) return;
  const usable = (d.candidates || []).filter(c => c.computable && c.monthly?.length);
  if (!usable.length) return;

  // Farben als Hex aus der Utilities-SSOT bzw. als feste Palette. NICHT über
  // `getComputedStyle(--util-…)`: Das liefert im Hellmodus
  // `color-mix(in srgb, … )`, und die Canvas-API verwirft solche Werte
  // stillschweigend — die Fläche wird schwarz und verdeckt alles darunter.
  const uColor = currentUtility?.color || '#4a90e2';
  const offerColors = ['#8b5cf6', '#2563eb', '#d97706', '#dc2626'];

  const months = monthShortNames();
  const labels = usable[0].monthly.map(m => {
    const mn = parseInt(m.ym.slice(5, 7), 10);
    return `${months[mn - 1]} ${m.ym.slice(2, 4)}`;
  });

  let offerIndex = 0;
  const datasets = usable.map(c => {
    let color = uColor;
    if (!c.is_reference) {
      color = offerColors[offerIndex % offerColors.length];
      offerIndex++;
    }
    return {
      label: c.label,
      data: c.monthly.map(m => m.cost),
      borderColor: color,
      backgroundColor: color + '22',
      borderWidth: c.is_reference ? 2.5 : 2,
      // Nur die Referenz wird gefüllt, und zwar schwach: Die Angebote laufen
      // darüber und müssen sichtbar bleiben — sonst verdeckt der Bestand
      // genau das, was man vergleichen will.
      fill: c.is_reference ? 'origin' : false,
      tension: 0.25,
      pointRadius: 2,
      // Abschnitte jenseits der Preisgarantie gestrichelt.
      segment: {
        borderDash: ctx => (c.monthly[ctx.p1DataIndex]?.beyond_guarantee ? [5, 4] : undefined),
      },
    };
  });

  charts.switch = makeChart(canvas, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: true, position: 'bottom' },
        tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${f.eur(ctx.parsed.y)}` } },
      },
      scales: { y: { beginAtZero: true, title: { display: true, text: '€' } } },
    },
  }, { label: t('tariff.switch.chartAlt', { unit }) });
}

function wireConsumptionCopy(box, d, unit) {
  const btn = box.querySelector('#t-copy-consumption');
  if (!btn) return;
  btn.addEventListener('click', () => {
    // Ohne Tausendertrennung und ohne Einheit — so, wie die Portale es
    // im Eingabefeld erwarten.
    const raw = String(Math.round(d.expected_consumption));
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(raw)
        .then(() => toastOk(t('tariff.switch.copied', { value: raw, unit })))
        .catch(() => toastErr(t('tariff.switch.copyFail')));
    } else {
      toastErr(t('tariff.switch.copyFail'));
    }
  });
}

function wireSwitchDate(box, container) {
  const input = box.querySelector('#t-switch-date');
  if (input) {
    input.addEventListener('change', () => {
      sel.switchDate = input.value || null;
      loadSwitch(container);
    });
  }
  const reset = box.querySelector('#t-switch-reset');
  if (reset) {
    reset.addEventListener('click', () => { sel.switchDate = null; loadSwitch(container); });
  }
}

// ─────────────────────────────────────────────────────────────────────
// Block 2 — Rückblick auf echte Monate
// ─────────────────────────────────────────────────────────────────────

async function loadRetro(container) {
  const box = container.querySelector('#t-retro');
  if (!box || !sel.meterId) return;

  let data;
  try {
    data = await api.tariffComparison(sel.utility, sel.meterId, sel.year);
  } catch (e) {
    box.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }
  lastRetro = data;

  if (!data.supported || !data.rows || data.rows.length === 0) {
    box.innerHTML = '';
    return;
  }

  const unit = data.unit || 'kWh';
  const anyPartial = data.rows.some(r => !r.covers_full_period);

  box.innerHTML = `
    <details class="retro-block">
      <summary>
        <h2 class="section-title">${esc(t('tariff.retro.heading'))}</h2>
      </summary>
      <p class="muted small">${esc(t('tariff.retro.intro'))}</p>

      <div class="toolbar toolbar--sub">
        <label>${esc(t('tariff.year'))}
          <select id="t-year"><option value="">${esc(t('tariff.wholePeriod'))}</option>${yearOpts()}</select>
        </label>
        <span class="muted small">
          ${data.period.from
            ? esc(t('tariff.periodRange', { label: data.period.label, from: data.period.from, to: data.period.to }))
            : esc(t('tariff.period', { label: data.period.label }))}
          ${data.real_total_eur != null
            ? ` · ${esc(t('tariff.realTotal', { value: f.eur(data.real_total_eur) }))}` : ''}
        </span>
      </div>

      <div class="table-wrap">
        <table class="data-table tariff-table">
          <thead><tr>
            <th scope="col">${esc(t('tariff.col.tariff'))}</th>
            <th scope="col">${esc(t('tariff.col.period'))}</th>
            <th scope="col" class="num">${esc(t('tariff.col.consumption'))}</th>
            <th scope="col" class="num">${esc(t('tariff.col.cost'))}</th>
            <th scope="col" class="num" title="${esc(t('tariff.unitCostTitle'))}">${esc(t('tariff.col.unitCost', { unit }))}</th>
            <th scope="col" class="num">${esc(t('tariff.col.savings'))}</th>
          </tr></thead>
          <tbody>${data.rows.map(r => retroRowHtml(r, unit)).join('')}</tbody>
        </table>
      </div>

      ${anyPartial ? `<p class="muted small">${esc(t('tariff.partialHint'))}</p>` : ''}
      <p class="muted small">${esc(t('tariff.legend'))}</p>
    </details>
  `;

  const yearSel = box.querySelector('#t-year');
  if (yearSel) {
    yearSel.value = sel.year || '';
    yearSel.addEventListener('change', e => {
      sel.year = e.target.value || null;
      // Das <details> offen halten — der Nutzer arbeitet gerade darin.
      loadRetro(container).then(() => {
        const d = container.querySelector('.retro-block');
        if (d) d.open = true;
      });
    });
  }
}

function retroRowHtml(r, unit) {
  const vs = r.vs_real_eur;
  const vsCls = vs == null ? '' : (vs < 0 ? 'success-text' : (vs > 0 ? 'danger-text' : ''));
  const vsStr = vs == null
    ? '<span class="dim">–</span>'
    : `${vs > 0 ? '+' : ''}${f.eur(vs)}${r.vs_real_pct != null
        ? ` <span class="muted">(${r.vs_real_pct > 0 ? '+' : ''}${f.num(r.vs_real_pct, 1)} %)</span>` : ''}`;

  const monthsLabel = r.months_covered === 1
    ? t('tariff.monthsOne')
    : t('tariff.months', { count: r.months_covered });
  const periodCell = r.covers_full_period
    ? `<span class="muted">${esc(monthsLabel)}</span>`
    : `<span class="badge badge--info">${esc(monthsLabel)}</span>`;

  const showProjection = sel.year != null && !r.covers_full_period
    && r.projected_full_eur != null && lastRetro;
  const projected = showProjection
    ? `<div class="muted small">${esc(t('tariff.projectedFull', {
        months: lastRetro.period.months, value: f.eur(r.projected_full_eur) }))}</div>`
    : '';

  return `<tr class="${r.is_shadow ? 'row-shadow' : ''}">
    <td>${r.is_shadow ? `<span class="badge badge--shadow">${esc(t('tariff.shadowBadge'))}</span> ` : ''}${esc(r.label)}</td>
    <td>${periodCell}</td>
    <td class="num">${f.int(r.consumption)} ${esc(unit)}</td>
    <td class="num"><strong>${r.total_eur != null ? f.eur(r.total_eur) : '<span class="dim">–</span>'}</strong>${projected}</td>
    <td class="num">${r.unit_cost_ct != null ? f.num(r.unit_cost_ct, 2) : '<span class="dim">–</span>'}</td>
    <td class="num ${vsCls}">${vsStr}</td>
  </tr>`;
}

// ─────────────────────────────────────────────────────────────────────
// Angebote pflegen
// ─────────────────────────────────────────────────────────────────────

function wireRowActions(box, container) {
  box.querySelectorAll('[data-edit-shadow]').forEach(b =>
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-shadow');
      let existing = null;
      try {
        const all = await api.contracts(sel.utility);
        existing = all.find(c => c.id === id) || null;
      } catch (e) { toastErr(e.message || e); return; }
      openShadowForm(container, existing);
    }));

  box.querySelectorAll('[data-delete-shadow]').forEach(b =>
    b.addEventListener('click', async () => {
      const ok = await confirmModal({
        title: t('tariff.shadow.deleteTitle'),
        message: t('tariff.shadow.deleteMessage'),
        confirmLabel: t('tariff.shadow.deleteConfirm'),
        danger: true,
      });
      if (!ok) return;
      try {
        await api.deleteContract(sel.utility, b.getAttribute('data-delete-shadow'));
        toastOk(t('tariff.shadow.deleted'));
        await loadAll(container);
      } catch (e) { toastErr(e.message || e); }
    }));
}

/**
 * Anlegen und Bearbeiten teilen ein Formular. `existing` = null → anlegen.
 *
 * Die Felder folgen dem, was auf einem Angebot bei CHECK24 oder Verivox
 * tatsächlich steht: Arbeitspreis, Grundpreis, Neukundenbonus als Betrag
 * (nicht als Gutschriftsdatum — das kennt beim Anlegen niemand),
 * Preisgarantie und Mindestlaufzeit.
 */
function openShadowForm(container, existing) {
  const isEdit = !!existing;
  const unit = (lastSwitch && lastSwitch.unit) || (lastRetro && lastRetro.unit) || 'kWh';
  const wp0 = existing?.working_prices?.[0] || {};
  const bp0 = existing?.base_prices?.[0] || {};
  // Ein neues Angebot gilt ab dem Wechseltermin — das ist die Zahl, die die
  // App gerade ausgerechnet hat, und damit die sinnvollste Vorbelegung.
  const start = existing?.start
    || (lastSwitch && lastSwitch.switch_date)
    || `${new Date().getFullYear()}-01-01`;

  openModal({
    title: isEdit ? t('tariff.shadow.titleEdit') : t('tariff.shadow.title'),
    body: `
      <p class="muted small">${esc(t('tariff.shadow.intro'))}</p>
      <div class="form-grid">
        <label>${esc(t('tariff.shadow.label'))}<input type="text" id="s-label"
          value="${esc(existing?.shadow_label || existing?.tariff_name || '')}"
          placeholder="${esc(t('tariff.shadow.labelPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.provider'))}<input type="text" id="s-prov"
          value="${esc(existing?.provider || '')}"
          placeholder="${esc(t('tariff.shadow.providerPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.workingPrice', { unit }))}<input type="number" step="0.01" id="s-wp"
          value="${wp0.ct_per_kwh ?? ''}" placeholder="${esc(t('tariff.shadow.workingPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.basePrice'))}<input type="number" step="0.01" id="s-bp"
          value="${bp0.eur_per_month ?? ''}" placeholder="${esc(t('tariff.shadow.basePlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.signupBonus'))}<input type="number" step="1" min="0" id="s-bonus"
          value="${existing?.signup_bonus_eur ?? ''}" placeholder="${esc(t('tariff.shadow.signupBonusPlaceholder'))}">
          <span class="settings-field__hint">${esc(t('tariff.shadow.signupBonusHint'))}</span></label>
        <label>${esc(t('tariff.shadow.priceGuarantee'))}<input type="date" id="s-guarantee"
          value="${esc(existing?.price_guarantee_until || '')}">
          <span class="settings-field__hint">${esc(t('tariff.shadow.priceGuaranteeHint'))}</span></label>
        <label>${esc(t('tariff.shadow.start'))}<input type="date" id="s-start" value="${esc(start)}"></label>
        <label>${esc(t('tariff.shadow.end'))}<input type="date" id="s-end" value="${esc(existing?.end || '')}">
          <span class="settings-field__hint">${esc(t('tariff.shadow.endHint'))}</span></label>
        <label>${esc(t('tariff.shadow.noticePeriod'))}<input type="number" step="1" min="0" max="24" id="s-notice"
          value="${existing?.notice_period_months ?? ''}"
          placeholder="${esc(t('tariff.shadow.noticePeriodPlaceholder'))}">
          <span class="settings-field__hint">${esc(t('tariff.shadow.noticePeriodHint'))}</span></label>
      </div>`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="save">${esc(isEdit ? t('tariff.shadow.save') : t('tariff.shadow.create'))}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const val = id => bodyEl.querySelector(id).value;
        const startVal = val('#s-start');
        const wp = parseFloat(val('#s-wp'));
        const bp = parseFloat(val('#s-bp'));
        const bonus = parseFloat(val('#s-bonus'));
        const notice = parseInt(val('#s-notice'), 10);
        const label = val('#s-label').trim();
        if (!label || !startVal || isNaN(wp)) { toastErr(t('tariff.shadow.validation')); return; }

        const payload = {
          meter_id: sel.meterId,
          provider: val('#s-prov').trim(),
          tariff_name: label,
          start: startVal,
          end: val('#s-end') || null,
          is_shadow: true,
          shadow_label: label,
          working_prices: [{ from: startVal, ct_per_kwh: wp }],
          base_prices: isNaN(bp) ? [] : [{ from: startVal, eur_per_month: bp }],
          signup_bonus_eur: isNaN(bonus) ? null : bonus,
          price_guarantee_until: val('#s-guarantee') || null,
          notice_period_months: isNaN(notice) ? null : notice,
        };
        try {
          if (isEdit) await api.updateContract(sel.utility, existing.id, payload);
          else        await api.createContract(sel.utility, payload);
          toastOk(isEdit ? t('tariff.shadow.updated') : t('tariff.shadow.created'));
          close(null);
          await loadAll(container);
        } catch (e) { toastErr(t('tariff.shadow.error', { msg: e.message || e })); }
      });
    },
  });
}

function yearOpts() {
  const now = new Date().getFullYear();
  let o = '';
  for (let y = now; y >= now - 6; y--) {
    o += `<option value="${y}" ${String(y) === String(sel.year) ? 'selected' : ''}>${y}</option>`;
  }
  return o;
}
