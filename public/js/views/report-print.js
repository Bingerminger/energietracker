// =====================================================================
// Energietracker v3.1.0 — Jahresbericht als Druckansicht (Review I18N-12)
//
// Das PDF setzt nur CP1252: „CO₂" wurde dort zu „CO", griechische oder
// kyrillische Texte verschwanden, Zahlen formatierte der Server. Die
// Druckansicht zeigt denselben Bericht im Browser — Zahlen, Daten und Beträge
// über Intl wie in der übrigen Oberfläche, jede Schrift, Diagramme als SVG
// (druckt scharf). „Drucken" bringt ihn auf Papier oder als PDF auf die Platte;
// print.css blendet dabei Seitenleiste und Knöpfe aus.
//
// v3.4.0 (F1025) — „Jahresbericht für alle“: dasselbe Datenmodell wie das PDF
// (`model` aus GET /api/reports/yearly, ReportModelService). Die Sätze kommen
// fertig in der Sprache dieses Geräts vom Server; Grafiken über die
// Schaubilder aus components/explainer.js (Geld, Energiefluss, „Kälter oder
// mehr verbraucht?“, Vertrags-Zeitstrahl) und schlichte SVG-Balken. Die
// Druckansicht ist die barrierefreie Fassung des Berichts: echte Überschriften,
// Tabellen mit Kopfzeilen, jede Grafik mit ihrer Aussage als Text.
// =====================================================================

import { api } from '../api.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { t, getLanguages, getLocale } from '../lib/i18n.js';
import { renderError } from '../components/error.js';
import { moneyHtml, energyHtml, weatherHtml, timelineHtml, playExplainers } from '../components/explainer.js';
// v3.5.0 — Optionen im Dialog (Jahr, Verbrauchsarten, Anhang)
import { openReportDialog, reportHref, loadReportChoice, demoLimits } from '../components/report-dialog.js';
// v3.6.0 — Seite „Flexibilität“ und „Szenario anhängen“
import { resultCardHtml } from '../components/result-card.js';
import { getUtility } from '../state.js';
import * as S from '../lib/scenario.js';
import { scenarioHtml } from './scenario-print.js';

const esc = escapeHtml;

/** Ampel: Symbol UND Wort, nie nur Farbe. */
const LIGHT_SYMBOL = { ok: '✔', warn: '▲', alert: '✖', none: '●' };

export async function render(container, params, ctx) {
  const now = new Date().getFullYear();
  const asked = Number(ctx?.query?.get('year'));
  const year = asked >= 2000 && asked <= 2100 ? asked : now - 1;
  const detail = ctx?.query?.get('detail') === '1';
  const billing = ctx?.query?.get('billing') === '1';   // v3.6.0 (F1030) — Anhang „Abrechnungsjahre“
  const flex = ctx?.query?.get('flex') === '1';         // v3.6.0 — Seite „Flexibilität“
  const scenario = ctx?.query?.get('scenario') || null; // v3.6.0 — gespeichertes Szenario (dieses Gerät)
  // v3.5.0 — Teilbericht aus dem Dialog (`utilities=gas,strom`); leer = ganzer Bericht
  const utilities = (ctx?.query?.get('utilities') || '').split(',').map(s => s.trim()).filter(s => /^[a-z_]+$/.test(s));
  // v3.6.0 — dieselbe Auswahl auch als PDF-Datei: „Drucken“ führt am iPad in den
  // Druckdialog, nicht zu einer Datei. Ohne Szenario (gibt es nur hier) und mit
  // den Grenzen der Demo; nur, wenn die Sprache in den PDF-Schriften steht.
  const pdfLimits = demoLimits('download');
  const savedLang = loadReportChoice().lang;
  const pdfHref = t('format.pdfCharset') !== 'none' ? reportHref('download', {
    year,
    lang: Object.keys(getLanguages()).includes(savedLang) ? savedLang : getLocale(),
    detail: pdfLimits.detail && detail,
    billing: pdfLimits.billing && billing,
    flex: pdfLimits.flex && flex,
    utilities: pdfLimits.subset && utilities.length ? utilities : null,
  }) : null;

  const toolbar = `
    <div class="view-header no-print">
      <div>
        <a class="muted" href="#/report">${esc(t('report.print.back'))}</a>
        <h1 class="view-header__title">${esc(t('report.title', { year }))}</h1>
        <p class="view-header__subtitle">${esc(t('report.print.hint'))}</p>
      </div>
      <div class="report-print__actions">
        <select class="select" id="print-year" aria-label="${esc(t('settings.pdf.year'))}">
          ${Array.from({ length: 7 }, (_, i) => now - i).map(y => `<option value="${y}"${y === year ? ' selected' : ''}>${y}</option>`).join('')}
        </select>
        <label class="report-print__detail"><input type="checkbox" id="print-detail"${detail ? ' checked' : ''}> ${esc(t('report.print.detail'))}</label>
        <button type="button" class="btn btn--ghost" id="print-options">${esc(t('report.dialog.options'))}</button>
        ${pdfHref ? `<a class="btn btn--ghost" id="print-pdf" href="${esc(pdfHref)}" download>${esc(t('settings.pdf.download'))}</a>` : ''}
        <button type="button" class="btn btn--primary" id="print-now">${esc(t('report.print.button'))}</button>
      </div>
    </div>`;
  container.innerHTML = `${toolbar}<div class="loading" role="status">${esc(t('common.loading'))}</div>`;

  let d;
  try {
    // immer mit Anhang (eine Antwort je Jahr, auch in der Demo); gezeigt wird er nur auf Wunsch
    d = await api.yearlyReport(year, { detail: true, utilities });
  } catch (e) {
    renderError(container.querySelector('.loading'), e, () => render(container, params, ctx));
    return;
  }
  const m = d.model || {};
  // Teilbericht: welche Verbrauchsarten er zeigt (Bezeichnungen aus dem Modell)
  const partial = utilities.length
    ? `<p class="report-print__partial">${esc(t('report.dialog.partial', { list: (m.utilities || []).map(u => u.label).filter(Boolean).join(', ') }))}</p>` : '';

  container.innerHTML = `${toolbar}
    <article class="report-print" lang="${esc(m.lang || '')}">
      ${partial}
      ${important(m)}
      ${successes(m)}
      ${tasks(m)}
      ${agenda(m)}
      ${money(m)}
      ${(m.pages || []).map(p => utilityPage(p, m)).join('')}
      ${topicPv(m)}
      ${topicHeatPump(m)}
      ${topicEv(m)}
      ${topicFlex(m)}
      ${topicTenancy(m)}
      ${flex ? flexPage(m) : ''}
      ${scenario ? `<div data-role="rp-scenario"><div class="loading" role="status">${esc(t('common.loading'))}</div></div>` : ''}
      ${glossary(m)}
      ${detail ? appendix(m) : ''}
      ${billing ? appendix(m, 'billing') : ''}
    </article>`;
  playExplainers(container);
  if (scenario) appendScenario(container.querySelector('[data-role="rp-scenario"]'), scenario);

  container.querySelector('#print-now')?.addEventListener('click', () => window.print());
  // Jahr und Anhang direkt; der Teilbericht bleibt dabei erhalten
  const go = () => {
    location.hash = reportHref('print', {
      year: container.querySelector('#print-year')?.value || year,
      detail: !!container.querySelector('#print-detail')?.checked,
      billing, flex, scenario,
      utilities: utilities.length ? utilities : null,
    });
  };
  container.querySelector('#print-year')?.addEventListener('change', go);
  container.querySelector('#print-detail')?.addEventListener('change', go);
  // v3.5.0 — alle Optionen im Dialog, vorbelegt mit dieser Ansicht
  container.querySelector('#print-options')?.addEventListener('click', () => {
    openReportDialog({ mode: 'print', initial: { year, detail, billing, flex, scenario, utilities: utilities.length ? utilities : null } });
  });
}

// ── Bausteine ────────────────────────────────────────────────────────

const section = (cls, title, body) => `
    <section class="print-section ${cls}">
      <h2>${esc(title)}</h2>
      ${body}
    </section>`;

/** Kasten „Stand heute (Datum)“ um Angaben, die nicht zum Berichtsjahr gehören. */
const today = (m, body) => `<div class="report-print__today"><p class="report-print__today-label">${esc(m.texts?.asOfToday || '')}</p>${body}</div>`;

const para = (text, cls = '') => (text ? `<p${cls ? ` class="${cls}"` : ''}>${esc(text)}</p>` : '');

/** Farbe einer Verbrauchsart: am Bildschirm die Oberflächenfarbe, im Druck die abgedunkelte. */
function colorVars(m, utility) {
  const u = (m.utilities || []).find(x => x.utility === utility);
  return u ? `--c:${esc(u.color)};--cp:${esc(u.print_color)}` : '';
}

let hatchSeq = 0;

/** Monatsbalken mit Vorjahr (grau), geschätzte Monate schraffiert. */
function monthBars(months, { label, unit, digits = 0, style = '' }) {
  const hatch = `rp-hatch-${++hatchSeq}`;
  const vals = months.flatMap(x => [Number(x.value) || 0, Number(x.prev) || 0]);
  const max = Math.max(1e-9, ...vals);
  const w = 11, gap = 8, h = 90;
  const cols = months.map((x, i) => {
    const x0 = i * (2 * w + gap);
    const ph = Math.round((Number(x.prev) || 0) / max * h);
    const vh = Math.round((Number(x.value) || 0) / max * h);
    const title = `${x.label}: ${fmt.unit(x.value, unit, digits)}${x.prev != null ? ` · ${fmt.unit(x.prev, unit, digits)}` : ''}`;
    return `<g><title>${esc(title)}</title>
      ${ph > 0 ? `<rect class="rp-prev" x="${x0}" y="${h - ph}" width="${w}" height="${ph}" rx="1"/>` : ''}
      ${vh > 0 ? `<rect class="rp-now"${x.estimated ? ` style="fill:url(#${hatch})"` : ''} x="${x0 + w}" y="${h - vh}" width="${w}" height="${vh}" rx="1"/>` : ''}
      <text x="${x0 + w}" y="${h + 13}" text-anchor="middle">${esc(x.label)}</text></g>`;
  }).join('');
  return `<svg class="report-print__bars" viewBox="0 0 ${12 * (2 * w + gap)} ${h + 18}" role="img" aria-label="${esc(label)}" style="${style}">
    <defs><pattern id="${hatch}" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="4" height="4" class="rp-hatch-bg"/><line x1="0" y1="0" x2="0" y2="4" class="rp-hatch-line"/></pattern></defs>
    ${cols}</svg>`;
}

/** Waagrechte Balken (Kosten je Art, Rollen, Anteile). */
function hbars(rows, { style = '' } = {}) {
  const max = Math.max(1e-9, ...rows.map(r => Math.abs(Number(r.value) || 0)));
  return `<div class="report-print__hbars">${rows.map(r => `
    <div class="report-print__hbar" style="${r.style || style}">
      <span class="report-print__hbar-label">${esc(r.label)}</span>
      <span class="report-print__hbar-track"><span class="report-print__hbar-fill" style="width:${(Math.abs(Number(r.value) || 0) / max * 100).toFixed(1)}%"></span></span>
      <span class="report-print__hbar-value">${esc(r.text)}</span>
    </div>`).join('')}</div>`;
}

function sparkline(values) {
  const nums = values.filter(v => v != null);
  if (nums.length < 2) return '';
  const lo = Math.min(...nums), hi = Math.max(...nums), span = hi - lo || 1;
  const pts = values.map((v, i) => (v == null ? null : [i / (values.length - 1) * 70, 14 - (v - lo) / span * 12])).filter(Boolean);
  return `<svg class="report-print__spark" viewBox="-2 -2 74 18" aria-hidden="true" focusable="false"><polyline points="${pts.map(p => p.map(n => n.toFixed(1)).join(',')).join(' ')}"/></svg>`;
}

function taskItem(x, compact = false) {
  const meta = [x.euro ? `${x.euro.text} (${x.euro.basis_text})` : '', x.deadline_text || ''].filter(Boolean).join(' · ');
  return `<li class="report-print__task report-print__task--p${esc(x.priority ?? 3)}">
    <div class="report-print__task-head"><span class="report-print__prio" aria-label="${esc(t('report.print.priority', { n: x.priority ?? 3 }))}">${esc(x.priority ?? 3)}</span> <strong>${esc(x.title)}</strong></div>
    ${meta ? `<p class="report-print__meta">${esc(meta)}</p>` : ''}
    ${para(x.reason)}
    ${compact ? '' : (x.steps || []).map(s => para(s, 'muted')).join('')}
  </li>`;
}

// ── Abschnitte ───────────────────────────────────────────────────────

function important(m) {
  const tx = m.texts || {};
  const tiles = (m.headline?.tiles || []).map(x => `
    <div class="report-print__tile">
      <div class="muted small">${esc(x.label)}</div>
      <div class="report-print__big">${esc(x.value_text)}</div>
      <div class="muted small">${esc(x.sub)}</div>
      ${sparkline(x.spark || [])}
    </div>`).join('');
  const lights = (m.headline?.lights || []).map(l => `
    <li class="report-print__light report-print__light--${esc(l.level)}">
      <span class="report-print__light-title">${esc(t(`report.light.title.${l.key}`))}${l.as_of_today ? ` · ${esc(t('report.light.today'))}` : ''}</span>
      <span class="report-print__light-word"><span aria-hidden="true">${LIGHT_SYMBOL[l.level] || '●'}</span> ${esc(l.word)}</span>
      <span class="small">${esc(l.detail)}</span>
    </li>`).join('');
  const top = m.headline?.top_tasks || [];
  return `
    <section class="print-section report-print__cover">
      <div class="report-print__brand">ENERGIETRACKER</div>
      <h2 class="report-print__title">${esc(tx.title || '')}</h2>
      ${m.location_name ? `<p class="report-print__location">${esc(m.location_name)}</p>` : ''}
      <p class="muted">${esc(t('report.createdOn', { date: fmt.date(m.created_on) }))}</p>
      <h3>${esc(tx.important || '')}</h3>
      <div class="report-print__tiles">${tiles}</div>
      <h3>${esc(tx.lights || '')}</h3>
      <ul class="report-print__lights">${lights}</ul>
      ${today(m, `<h3>${esc(tx.topTasks || '')}</h3>${top.length
        ? `<ol class="report-print__tasks">${top.map(x => taskItem(x, true)).join('')}</ol>`
        : para((m.agenda?.events || []).length ? tx.tasksNoneButAgenda : tx.tasksNone)}`)}
    </section>`;
}

function successes(m) {
  const tx = m.texts || {};
  const items = m.successes || [];
  const infos = m.infos || [];
  const list = items.length ? `<ul class="report-print__successes">${items.map(s => `
    <li><strong><span aria-hidden="true">★</span> ${esc(s.title)}</strong>
      ${s.euro ? `<p class="report-print__meta report-print__meta--good">${esc(`${s.euro.text} (${s.euro.basis_text})`)}</p>` : ''}
      ${para(s.reason)}</li>`).join('')}</ul>` : para(tx.successesNone, 'muted');
  const info = infos.length ? `<h3>${esc(tx.goodToKnow || '')}</h3><ul class="report-print__infos">${infos.map(i => `<li><strong>${esc(i.title)}</strong>${para(i.reason)}</li>`).join('')}</ul>` : '';
  return section('report-print__successes-section', tx.successes || '', list + info);
}

function tasks(m) {
  const tx = m.texts || {};
  const list = m.tasks || [];
  return section('report-print__tasks-section', tx.tasks || '', today(m, `${para(tx.tasksIntro, 'muted small')}${list.length
    ? `<ol class="report-print__tasks">${list.map(x => taskItem(x)).join('')}</ol>`
    : para((m.agenda?.events || []).length ? tx.tasksNoneButAgenda : tx.tasksNone)}`));
}

function agenda(m) {
  const tx = m.texts || {};
  const ev = m.agenda?.events || [];
  const tl = m.agenda?.timelines || [];
  if (!ev.length && !tl.length) return '';
  const table = ev.length ? `<table class="table report-print__table">
      <tbody>${ev.map(e => `<tr class="${e.days < 0 ? 'report-print__overdue' : ''}"><td>${esc(fmt.date(e.date))}</td><td>${esc(e.title)}</td><td class="num">${esc(e.state)}</td></tr>`).join('')}</tbody>
    </table>` : para(tx.agendaNone);
  // v3.9.0 (Skeptiker N18) — der Satz unter dem Zeitstrahl sagt schon, was der Satz des Berichts sagt:
  // nur einer von beiden (bis dahin zwei fast gleiche Sätze hintereinander)
  const lines = tl.map(c => {
    const fig = timelineHtml(c.explainer, m.today);
    return `
    <div class="report-print__timeline" style="${colorVars(m, c.utility)}">
      <h4>${esc(c.label)}${c.provider ? ` · ${esc(c.provider)}` : ''}</h4>
      ${fig}
      ${fig ? '' : para(c.sentence)}
    </div>`;
  }).join('');
  return section('report-print__agenda', tx.agenda || '', today(m, `${para(tx.agendaIntro, 'muted small')}${table}`) + (tl.length ? `<h3>${esc(tx.contracts || '')}</h3>${lines}` : ''));
}

function money(m) {
  const mo = m.money;
  if (!mo) return '';
  const tx = m.texts || {};
  const by = (mo.by_utility || []).map(r => ({ label: r.label, value: r.cost, text: r.text, style: colorVars(m, r.utility) }));
  const monthly = (mo.monthly || []).filter(x => x.value != null || x.prev != null).length
    ? monthBars(mo.monthly, { label: tx.costsByMonth || '', unit: '€', digits: 0 }) : '';
  const balances = (mo.balances || []).map(b => {
    const u = (m.utilities || []).find(x => x.utility === b.utility);
    return `<div class="report-print__balance">
      <h4>${esc(b.label)}${b.provider ? ` · ${esc(b.provider)}` : ''}</h4>
      ${moneyHtml(b.explainer, { color: u?.color })}
      <p><strong>${esc(b.text)}</strong></p>
      ${para(b.advance_text)}
    </div>`;
  }).join('');
  return section('report-print__money', tx.money || '', `
    ${para(mo.total?.sentence)}
    ${by.length ? `<h3>${esc(tx.costsByUtility || '')}</h3>${hbars(by)}` : ''}
    ${(mo.revenue || []).map(r => para(r.text)).join('')}
    ${monthly ? `<h3>${esc(tx.costsByMonth || '')}</h3>${monthly}` : ''}
    ${(mo.bonus_notes || []).map(b => para(b, 'muted small')).join('')}
    ${balances ? `<h3>${esc(tx.balances || '')}</h3>${today(m, balances + para(t('report.money.balanceLegend'), 'muted small'))}` : ''}
    ${mo.forecast ? para(mo.forecast.text, 'muted small') : ''}`);
}

function utilityPage(p, m) {
  const tx = m.texts || {};
  const style = colorVars(m, p.utility);
  const digits = p.unit === 'kWh' ? 0 : 1;
  const eff = p.efficiency;
  const scale = eff?.thresholds ? Object.keys(eff.thresholds) : [];
  const effHtml = eff ? `<h3>${esc(t('report.page.efficiencyTitle'))}</h3>
    ${scale.length ? `<ol class="report-print__scale" aria-hidden="true">${[...scale, 'H'].filter((c, i, a) => a.indexOf(c) === i).map(c => `<li class="${c === eff.class ? 'is-current' : ''}">${esc(c)}</li>`).join('')}</ol>` : ''}
    ${para(eff.text)}${eff.area_note ? `<p data-role="area-assumed"><strong>${esc(eff.area_note)}</strong></p>` : ''}` : '';
  const roles = p.roles ? `<h3>${esc(t('report.page.rolesTitle'))}</h3>${hbars(p.roles.rows.map(r => ({ label: r.label, value: r.kwh, text: r.text })), { style })}${para(p.roles.text, 'muted small')}` : '';
  return `
    <section class="print-section report-print__utility" data-utility="${esc(p.utility)}" style="${style}">
      <h2>${esc(t('report.page.title', { label: p.label, year: m.year }))}</h2>
      <p class="report-print__big">${esc(fmt.unit(p.value, p.unit, digits))}</p>
      ${para(p.sentence)}
      ${para(p.cost?.text)}
      ${monthBars(p.months || [], { label: p.sentence, unit: p.unit, digits, style })}
      <p class="muted small report-print__legend"><span class="rp-key rp-key--now"></span> ${esc(String(m.year))} <span class="rp-key rp-key--prev"></span> ${esc(String(m.year - 1))}${(p.months || []).some(x => x.estimated) ? ` <span class="rp-key rp-key--est"></span> ${esc(tx.estimated || '')}` : ''}</p>
      ${p.weather ? `<h3>${esc(t('report.page.weatherTitle'))}</h3>${weatherHtml(p.weather_rows)}${para(p.weather.text)}` : ''}
      ${effHtml}
      ${roles}
      ${p.water ? para(p.water.text) : ''}
      ${p.tank ? today(m, para(p.tank.text)) : ''}
      ${(p.sub_meters || []).map(s => para(s.text, 'muted small')).join('')}
      ${(p.notes || []).map(n => para(n, 'muted small')).join('')}
    </section>`;
}

function topicPv(m) {
  const pv = m.topics?.pv;
  if (!pv) return '';
  const tx = m.texts || {};
  return section('report-print__pv', tx.topicPv || '', `
    <div class="report-print__tiles">${pv.tiles.map(x => `<div class="report-print__tile"><div class="muted small">${esc(x.label)}</div><div class="report-print__big">${esc(x.text)}</div>${x.sub ? `<div class="muted small">${esc(x.sub)}</div>` : ''}</div>`).join('')}</div>
    ${energyHtml(pv.year_row)}
    ${para(pv.sentence)}
    ${(pv.notes || []).map(n => para(n, 'muted small')).join('')}
    ${para(pv.co2_text)}
    ${pv.payback ? today(m, `<h3>${esc(t('report.topic.pv.paybackTitle'))}</h3><progress max="${esc(pv.payback.investment_eur)}" value="${esc(Math.min(pv.payback.benefit_to_date_eur, pv.payback.investment_eur))}"></progress>${para(pv.payback.text)}`) : ''}
    ${pv.battery ? `<h3>${esc(t('report.topic.pv.batteryTitle'))}</h3>${para(pv.battery.text)}` : ''}`);
}

function topicHeatPump(m) {
  const hp = m.topics?.heat_pump;
  if (!hp) return '';
  const tx = m.texts || {};
  return section('report-print__heatpump', tx.topicHeatPump || '', hp.pumps.map(p => `
    ${para(p.sentence)}
    ${monthBars(p.months, { label: p.sentence, unit: '', digits: 1, style: colorVars(m, 'waerme') })}
    ${para(p.reference_text, 'muted small')}`).join(''));
}

function topicEv(m) {
  const ev = m.topics?.ev;
  if (!ev) return '';
  const tx = m.texts || {};
  return section('report-print__ev', tx.topicEv || '', ev.meters.map(e => `
    ${para(e.sentence)}
    ${para(e.cost_text)}
    ${monthBars(e.months.map(x => ({ ...x, prev: x.part })), { label: e.sentence, unit: 'kWh', style: colorVars(m, 'strom') })}
    ${e.has_solar ? `<p class="muted small report-print__legend"><span class="rp-key rp-key--prev"></span> ${esc(tx.solarShare || '')}</p>` : ''}
    ${para(e.sub_text, 'muted small')}`).join(''));
}

function topicFlex(m) {
  const fx = m.topics?.flexibility;
  if (!fx) return '';
  const tx = m.texts || {};
  return section('report-print__flex', tx.topicFlex || '', `
    ${para(fx.sentence)}
    <h3>${esc(tx.cheapShare || '')}</h3>
    ${hbars(fx.rows.map(r => ({ label: r.name, value: r.cheap_share, text: r.text })), { style: colorVars(m, 'strom') })}
    ${para(fx.module3_text)}
    ${para(fx.battery_text)}
    ${para(t('report.topic.flex.note'), 'muted small')}`);
}

/** v3.6.0 — Seite „Flexibilität“: dieselben Sätze wie im PDF (ReportModelService::flexPage). */
function flexPage(m) {
  const f = m.flex_page;
  if (!f) return '';
  const tx = m.texts || {};
  const hh = (h) => `${String(h).padStart(2, '0')}:00`;
  const card = resultCardHtml({ brand: t('flex.card.brand'), title: t('flex.card.title', { year: m.year }), tone: 'less',
    big: f.big, sentence: f.sentence, facts: f.facts || [], footer: f.footer || '' }, 'rp-flex-sentence');
  const band = (b) => `<h4>${esc(b.label)}</h4>
    <ol class="flex-band" aria-label="${esc(t('flex.ampel.day'))} · ${esc(b.label)}">${b.hours.map((h, i) => `
      <li class="flex-band__h flex-lv--${esc(h.level || 'none')}">
        <span class="flex-band__sun flex-sun--${esc(h.sun || 'none')}" aria-hidden="true">${h.sun === 'strong' || h.sun === 'some' ? '☀' : ''}</span>
        <span class="flex-band__cell" aria-hidden="true">${h.level ? LIGHT_SYMBOL[{ cheap: 'ok', mid: 'warn', expensive: 'alert' }[h.level]] : ''}</span>
        <span class="flex-band__label" aria-hidden="true">${i % 3 === 0 ? i : ''}</span>
        <span class="sr-only">${esc(t('flex.ampel.hourCell', { hour: hh(i), level: t(`flex.ampel.level.${h.level || 'none'}`), ct: h.ct != null ? `${fmt.num(h.ct, 1)} ct` : '–' }))}${h.sun === 'strong' || h.sun === 'some' ? `, ${esc(t(`flex.ampel.sun.${h.sun}`))}` : ''}</span>
      </li>`).join('')}</ol>
    ${para(b.summary, 'small')}`;
  const table = (head, rows) => `<table class="table report-print__table">
      <thead><tr>${head.map((h, i) => `<th${i ? ' class="num"' : ''} scope="col">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(r => `<tr>${r.map((c, i) => (i ? `<td class="num">${esc(c)}</td>` : `<th scope="row">${esc(c)}</th>`)).join('')}</tr>`).join('')}</tbody>
    </table>`;
  return section('report-print__flex-page', tx.flexPage || t('report.section.flexPage'), `
    ${card}
    ${(f.actions || []).length ? `<h3>${esc(t('flex.actions.title'))}</h3>${para(f.actions_lead, 'muted small')}
      <ul class="report-print__actions-list">${f.actions.map(a => `<li><strong>${esc(a.text)}</strong> <span class="muted small">${esc(a.tag)}${a.other ? ` · ${esc(a.other)}` : ''}</span></li>`).join('')}</ul>` : ''}
    ${(f.bands || []).length ? `<h3>${esc(t('flex.ampel.title'))}</h3>${para(f.band_lead, 'muted small')}${f.bands.map(band).join('')}
      <p class="flex-legend small">${['cheap', 'mid', 'expensive'].map((k, i) => `<span class="flex-key flex-lv--${k}" aria-hidden="true">${LIGHT_SYMBOL[['ok', 'warn', 'alert'][i]]}</span> ${esc(f.legend?.[i] || '')}`).join(' ')}
        <span class="flex-key flex-sun--strong" aria-hidden="true">☀</span> ${esc(f.legend?.[3] || '')}</p>` : ''}
    ${f.windows ? `<h3>${esc(t('flex.windows.title'))}</h3>${para(f.windows.lead, 'muted small')}${table(f.windows.head, f.windows.rows)}` : ''}
    ${f.levers ? `<h3>${esc(t('flex.lever.title'))}</h3>${table(f.levers.head, f.levers.rows.map(r => [r.label, `${r.signal} · ${r.real_text}`, r.kwh, r.eur]))}${para(f.levers.total)}` : ''}
    ${f.solar_text ? `<h3>${esc(t('flex.solar.title'))}</h3>${para(f.solar_text)}` : ''}
    ${(f.notes || []).map(n => para(n, 'muted small')).join('')}`);
}

/** v3.6.0 — ein gespeichertes Szenario der Prognose-Werkstatt als eigene Seite (gerechnet wie dort auf dem Server). */
async function appendScenario(box, name) {
  if (!box) return;
  const saved = S.savedScenarios().find(x => x.name === name);
  const title = t('report.print.scenarioTitle', { name });
  if (!saved?.state?.utility || !saved.state.meter_id) {
    box.innerHTML = section('report-print__scenario', title, para(t('report.print.scenarioMissing', { name }), 'muted'));
    return;
  }
  try {
    const [r, u, meters, contracts] = await Promise.all([
      api.scenario(S.scenarioBody(saved.state)), getUtility(saved.state.utility),
      api.meters(saved.state.utility).catch(() => []), api.contracts(saved.state.utility, saved.state.meter_id).catch(() => []),
    ]);
    box.innerHTML = r.valid
      ? `<div class="scenario-print">${scenarioHtml({ cur: { name: saved.name, state: saved.state }, r, u, meters, contracts, cardId: 'rp-scenario-sentence' })}</div>`
      : section('report-print__scenario', title, para(t(r.supported ? 'scenario.tooFew' : 'scenario.unsupported', { label: u?.label || '' }), 'muted'));
  } catch (e) {
    box.innerHTML = section('report-print__scenario', title, para(e.message, 'muted'));
  }
}

function topicTenancy(m) {
  const tn = m.topics?.tenancy;
  if (!tn) return '';
  const tx = m.texts || {};
  return section('report-print__tenancy', tx.topicTenancy || '', today(m, `<h3>${esc(tn.label)}</h3>${tn.sentences.map(s => para(s)).join('')}`));
}

function glossary(m) {
  const tx = m.texts || {};
  const g = m.glossary || [];
  return section('report-print__glossary', tx.glossary || '', `
    <dl class="report-print__glossary-list">${g.map(x => `<dt>${esc(x.term)}</dt><dd>${esc(x.text)}</dd>`).join('')}</dl>
    <h3>${esc(tx.sources || '')}</h3>
    ${(m.sources || []).map(s => para(s, 'muted small')).join('')}
    ${(m.errors || []).length ? para(tx.errors, 'muted small') : ''}`);
}

/** Anhang aus Tabellen: „Zahlen im Detail“ (`appendix`) oder v3.6.0 „Abrechnungsjahre“ (`billing`). */
function appendix(m, key = 'appendix') {
  const tables = m[key] || [];
  if (!tables.length) return '';
  const tx = m.texts || {};
  return section(`report-print__appendix report-print__${key}`, tx[key] || '', tables.map(tb => `
    <h3>${esc(tb.label)}</h3>
    <table class="table report-print__table">
      <thead><tr>${tb.head.map((h, i) => `<th${i ? ' class="num"' : ''} scope="col">${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${tb.rows.map(r => `<tr>${r.map((c, i) => (i ? `<td class="num">${esc(c)}</td>` : `<th scope="row">${esc(c)}</th>`)).join('')}</tr>`).join('')}</tbody>
      <tfoot><tr>${tb.total.map((c, i) => (i ? `<td class="num"><strong>${esc(c)}</strong></td>` : `<th scope="row">${esc(c)}</th>`)).join('')}</tr></tfoot>
    </table>
    ${tb.notes.map(n => para(n, 'muted small')).join('')}`).join(''));
}
