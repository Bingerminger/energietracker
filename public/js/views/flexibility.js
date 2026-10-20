// =====================================================================
// v3.3.0 (F1021) — Flexibilität bewerten: Was hat das Verschieben gebracht?
//
// Gesteuert wird außerhalb (evcc, Home Assistant, der Speicher selbst); hier
// wird mit Verbrauch und Börsenpreis je Viertelstunde nachgerechnet:
//   • je Stromzähler: kWh in günstigen und negativen Viertelstunden, eigener
//     Durchschnittspreis gegen das Börsenmittel (Profilfaktor), Vorteil
//   • Speicher: geladen zu welchem Preis, entladen zu welchem
//   • § 14a Modul 3: Ersparnis aus den Zeitfenstern am Vertrag
// Gerechnet wird nur der Börsenanteil — ohne Steuern, Umlagen, Netzentgelt.
//
// v3.6.0 — die Seite lenkt: was du tun kannst (`insights`,
// FlexibilityInsightService). Alle Zahlen kommen vom Server; hier wird nur
// formatiert und ausgewählt, welche Jahreszeit die Ampel zeigt.
//   • Ergebniskarte „Dein Hebel“ mit großer Zahl, als Bild speicherbar
//     (components/result-card.js wie in der Prognose-Werkstatt)
//   • Handlungssätze je Gerät mit Zeitfenster der laufenden Jahreszeit
//   • Ampel: ein typischer Tag (Einsteiger) und die Woche (ab Erfahren),
//     grün/gelb/rot mit Symbol und Text für Screenreader, ☀ für die Sonne
//   • beste Zeitfenster der Woche, Sonnenstrom, „Dein Hebel“ als Tabelle
//   • ohne Daten eine Einladung: was fehlt und was es bringt
//   • Nachgerechnet (bisherige Karten) ab der Stufe Experte
//
// v3.6.0 (Paket flexplus) — die große Zahl aus `insights.summary` (dieselbe
// wie die Kachel „Dein Hebel“ der Übersicht), Beträge zum Börsenpreis mit
// Umsatzsteuer, das Auto mit seinen Steckzeiten aus evcc (Schild und Satz
// zur Grundlage). Die Sätze stehen in lib/flex-text.js.
// =====================================================================
import { api } from '../api.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastErr, toastOk } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { t } from '../lib/i18n.js';
import { info } from '../components/info.js';
import { docUrl } from '../lib/docs.js';
import { resultCardHtml, downloadResultCard } from '../components/result-card.js';
import { windowText, leverLabel, actionText, evBasisText } from '../lib/flex-text.js';

const esc = escapeHtml;

export async function render(container, _params = [], ctx = {}) {
  const now = new Date().getFullYear();
  const wanted = Number(ctx.query?.get('year'));
  const year = wanted >= 2018 && wanted <= now ? wanted : now - 1;
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  let r, series;
  try {
    [r, series] = await Promise.all([api.flexibility(year), api.intervals().catch(() => ({ series: [] }))]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(e.message)}</div>`;
    return;
  }
  const years = [now, now - 1, now - 2, now - 3];
  const meters = [...(r.meters || [])].sort((a, b) => Number(b.controllable) - Number(a.controllable));
  const ins = r.insights || null;
  let season = ins?.season_now || 'winter';
  const spec = ins ? cardSpec(ins, year) : null;

  container.innerHTML = `
    <div class="section-head">
      <h1>${esc(t('flex.title'))}</h1>
      <div class="section-actions">
        <select class="select" id="flex-year" aria-label="${esc(t('flex.year'))}">
          ${years.map(y => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}
        </select>
        <button type="button" class="btn btn--ghost" id="flex-smard">${esc(t('flex.loadPrices', { year }))}</button>
      </div>
    </div>
    <div class="load-progress" data-role="smard-progress" role="status" aria-live="polite" hidden>
      <progress max="12" value="0"></progress> <span class="muted small" data-role="smard-text"></span>
    </div>
    <p class="muted">${esc(t('flex.intro'))} <a href="${esc(docUrl('flexibility'))}">${esc(t('common.guide'))}</a></p>
    ${ins ? missingCard(ins, year) : legacyBanners(r, meters, year)}
    ${spec ? `<div class="flex-result">${resultCardHtml(spec)}
      <div class="ws-actions"><button type="button" class="btn btn--primary" id="flex-image">${esc(t('flex.card.image'))}</button></div></div>` : ''}
    ${ins ? actionsCard(ins) : ''}
    ${ins?.pattern ? `<div class="card flex-ampel" id="flex-ampel">${ampelHtml(ins, season, year)}</div>` : ''}
    ${ins ? windowsCard(ins, season) : ''}
    ${ins?.solar ? solarCard(ins, year) : ''}
    ${ins ? leversCard(ins) : ''}
    ${r.prices?.available || r.battery || (r.module3 || []).length ? `<section class="flex-check" data-min-level="expert">
      <h2 class="flex-check__title">${esc(t('flex.check.title'))}</h2>
      <p class="muted small">${esc(t('flex.check.lead'))}</p>
      ${r.prices?.available ? meters.map(m => meterCard(m, r.year)).join('') : ''}
      ${r.battery ? batteryCard(r.battery) : ''}
      ${(r.module3 || []).map(module3Card).join('')}
    </section>` : ''}
    ${seriesCard(series.series || [], new Map(meters.map(m => [m.meter_id, m.name])))}
    ${r.prices?.available ? `<p class="muted small">${esc(t('flex.attribution'))}</p>` : ''}`;

  container.querySelector('#flex-year').addEventListener('change', (e) => { location.hash = `#/flexibility?year=${e.target.value}`; });
  // v3.6.0 — Jahreszeit der Ampel: dieselben Zahlen vom Server, nur eine andere Auswahl
  container.querySelector('#flex-ampel')?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-season]');
    if (!b) return;
    season = b.dataset.season;
    container.querySelector('#flex-ampel').innerHTML = ampelHtml(ins, season, year);
    const w = container.querySelector('#flex-windows');
    if (w) w.outerHTML = windowsCard(ins, season);
    container.querySelector(`#flex-ampel [data-season="${season}"]`)?.focus();
  });
  container.querySelector('#flex-image')?.addEventListener('click', async () => {
    const ok = await downloadResultCard(spec, `${t('flex.card.filename')}-${year}.png`);
    if (!ok) toastErr(t('scenario.share.imageFailed'));
  });
  // v3.5.1 — Monat für Monat mit Fortschritt: ein ganzes Jahr sind rund 53
  // Wochenpakete von smard.de; bei langsamer Verbindung wartete man bisher stumm
  const smard = async (e) => {
    const btn = e.currentTarget;
    const box = container.querySelector('[data-role="smard-progress"]');
    const bar = box.querySelector('progress');
    const text = box.querySelector('[data-role="smard-text"]');
    const now = new Date();
    const last = year < now.getFullYear() ? 12 : now.getMonth() + 1;
    btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    bar.max = last; bar.value = 0; box.hidden = false;
    let slots = 0;
    try {
      for (let m = 1; m <= last; m++) {
        text.textContent = t('flex.loadingPrices', { month: month(year, m), n: m, total: last });
        const s = await api.syncSmardIntervals(year, m);
        slots = s.slots;
        bar.value = m;
      }
      toastOk(t('flex.pricesLoaded', { year, slots: fmt.int(slots) }));
      render(container, _params, ctx);
    } catch (err) {
      toastErr(err.message);
      btn.disabled = false; btn.removeAttribute('aria-busy'); box.hidden = true;
    }
  };
  container.querySelector('#flex-smard').addEventListener('click', smard);
  container.querySelector('[data-act="flex-smard"]')?.addEventListener('click', () => container.querySelector('#flex-smard').click());
  container.querySelectorAll('[data-series-del]').forEach(b => b.addEventListener('click', async () => {
    if (!await confirmModal({ message: t('flex.series.confirmDelete'), confirmLabel: t('flex.series.delete'), danger: true })) return;
    try { await api.deleteInterval(b.dataset.seriesDel); render(container, _params, ctx); }
    catch (err) { toastErr(err.message); }
  }));
}

const month = (y, m) => fmt.month(`${y}-${String(m).padStart(2, '0')}`);
const ct = (v) => (v == null ? '–' : `${fmt.num(v, 2)} ct`);
const ct1 = (v) => (v == null ? '–' : `${fmt.num(v, 1)} ct`);
const hh = (h) => `${String(h).padStart(2, '0')}:00`;
const list = (key) => t(key).split(',').map(s => s.trim());
/** Ampel: Symbol UND Wort, nie nur Farbe (wie im Jahresbericht). */
const LEVEL_SYMBOL = { cheap: '✔', mid: '▲', expensive: '✖' };

// ── Sätze aus den Daten des Servers (lib/flex-text.js) ───────────────

/** v3.6.0 — Ergebniskarte aus `insights.summary`: dieselbe Zahl und Regel wie die Kachel der Übersicht. */
function cardSpec(ins, year) {
  const s = ins.summary;
  if (!s) return null;
  const best = s.action || (ins.actions || [])[0];
  const facts = best ? [{ label: t('flex.card.factBest', { name: leverLabel(best) }), value: fmt.eur0(best.eur) }] : [];
  if (s.dynamic_eur != null) facts.push({ label: t('flex.card.factDynamic'), value: t('flex.card.upTo', { eur: fmt.eur0(s.dynamic_eur) }) });
  if (ins.solar) facts.push({ label: t('flex.card.factSelf'), value: fmt.pct(ins.solar.self_share, 0) });
  if (ins.achieved?.module3_eur) facts.push({ label: t('flex.card.factModule3'), value: fmt.eur0(ins.achieved.module3_eur) });
  return {
    brand: t('flex.card.brand'), title: t('flex.card.title', { year }), tone: 'less',
    big: fmt.eur0(s.eur),
    sentence: t(s.real ? 'flex.card.real' : 'flex.card.dynamic'),
    facts: facts.slice(0, 3),
    footer: t(ins.signal === 'spot' ? 'flex.card.footer' : 'flex.card.footerOwn', { year, date: fmt.date(new Date().toISOString().slice(0, 10)) }),
  };
}

// ── Karten ────────────────────────────────────────────────────────────

/** v3.6.0 — Einladung: was fehlt und was es bringt. */
function missingCard(ins, year) {
  const miss = ins.missing || [];
  if (!miss.length) return '';
  return `<div class="card flex-missing">
    <h2 class="card__title">${esc(t('flex.missing.title'))}</h2>
    <ul class="flex-missing__list">${miss.map(k => `<li data-missing="${esc(k)}">${t(`flex.missing.${k}`, { year })}${k === 'prices'
      ? ` <button type="button" class="btn btn--sm btn--primary" data-act="flex-smard">${esc(t('flex.loadPrices', { year }))}</button>` : ''}</li>`).join('')}</ul>
    <p class="muted small"><a href="${esc(docUrl('flexibility'))}">${esc(t('flex.missing.guide'))}</a></p>
  </div>`;
}

/** Ohne `insights` (ältere Antwort, Demo vor v3.6): die bisherigen Hinweise. */
function legacyBanners(r, meters, year) {
  return `${!r.prices?.available ? `<div class="banner banner--info">${esc(t('flex.noPrices', { year }))}</div>` : ''}
    ${!meters.length ? `<div class="banner banner--info">${esc(t('flex.noIntervals', { year }))}</div>` : ''}`;
}

function tagFor(x, ins = null) {
  return `<span class="tag flex-tag flex-tag--${x.real ? 'real' : 'dynamic'}">${esc(t(x.real ? 'flex.real.yes' : 'flex.real.no'))}</span>${x.basis === 'assumed'
    ? ` <span class="tag flex-tag flex-tag--assumed">${esc(t('flex.basis.assumed'))}</span>` : ''}${availabilityTag(x.kind === 'ev' ? ins?.ev_availability : null)}`;
}

/**
 * v3.6.0 (Paket flexplus, Nachtrag) — Schild „netto, ohne USt“ an Karten mit
 * reinem Börsenanteil (Nachrechnung je Zähler, Speicher, Zeitfenster nach
 * Börsenpreis) — daneben rechnen die Hebel inkl. USt.
 */
function netTag() {
  return ` <span class="tag flex-tag flex-tag--net" data-net>${esc(t('flex.net'))}</span>`;
}

/** v3.6.0 — Schild am Auto: Steckzeiten aus evcc oder die Annahme „ganzer Ladetag“ (Beispielwert). */
function availabilityTag(ev) {
  if (!ev) return '';
  const sessions = ev.basis === 'sessions';
  return ` <span class="tag flex-tag flex-tag--${sessions ? 'sessions' : 'assumed'}" data-availability="${sessions ? 'sessions' : 'day'}">${esc(t(sessions ? 'flex.availability.sessions' : 'flex.availability.day'))}</span>`;
}

function actionsCard(ins) {
  const acts = (ins.actions || []).filter(a => a.window);
  if (!acts.length) return '';
  const other = ins.season_now === 'summer' ? 'winter' : 'summer';
  return `<div class="card flex-actions">
    <h2 class="card__title">${esc(t('flex.actions.title'))}</h2>
    <p class="muted small">${esc(t('flex.actions.lead', { season: t(`flex.season.${ins.season_now}`) }))}</p>
    <ol class="flex-actions__list">${acts.map(a => `<li class="flex-action flex-action--${esc(a.signal)}">
      <span class="flex-action__icon" aria-hidden="true">${a.signal === 'solar' ? '☀' : a.signal === 'tou' ? '🌙' : '⏱'}</span>
      <span class="flex-action__body"><strong>${esc(actionText(a))}</strong> ${tagFor(a, ins)}
        ${a.other ? `<span class="muted small flex-action__other">${esc(t('flex.actions.other', { season: t(`flex.seasonBtn.${other}`), window: windowText(a.other) }))}</span>` : ''}</span>
    </li>`).join('')}</ol>
  </div>`;
}

/** Die Ampel einer Jahreszeit: Tagesband für alle, Wochenraster ab Erfahren. */
function ampelHtml(ins, season, year) {
  const p = ins.pattern[season];
  const hasPrice = ins.signal != null;
  const lvl = (l) => (l ? t(`flex.ampel.level.${l}`) : t('flex.ampel.level.none'));
  const sunWord = (s) => (s ? t(`flex.ampel.sun.${s}`) : '');
  const seg = `<span class="seg" role="group" aria-label="${esc(t('flex.ampel.seasonLabel'))}">${['winter', 'summer', 'all'].map(s =>
    `<button type="button" class="seg__btn ${s === season ? 'active' : ''}" data-season="${s}" aria-pressed="${s === season}">${esc(t(`flex.seasonBtn.${s}`))}</button>`).join('')}</span>`;
  const lead = !hasPrice ? t('flex.ampel.leadSun') : ins.signal === 'tou' ? t('flex.ampel.leadTou') : t('flex.ampel.lead', { year });
  const band = `<ol class="flex-band" aria-label="${esc(t('flex.ampel.day'))}">${p.hours.map((h, i) => `
    <li class="flex-band__h flex-lv--${esc(h.level || 'none')}">
      <span class="flex-band__sun flex-sun--${esc(h.sun_level || 'none')}" aria-hidden="true">${h.sun_level === 'strong' || h.sun_level === 'some' ? '☀' : ''}</span>
      <span class="flex-band__cell" aria-hidden="true">${h.level ? LEVEL_SYMBOL[h.level] : ''}</span>
      <span class="flex-band__label" aria-hidden="true">${i % 3 === 0 ? i : ''}</span>
      <span class="sr-only">${esc(t('flex.ampel.hourCell', { hour: hh(i), level: lvl(h.level), ct: h.ct != null ? ct1(h.ct) : '–' }))}${h.sun_level && h.sun_level !== 'none' ? `, ${esc(sunWord(h.sun_level))}` : ''}</span>
    </li>`).join('')}</ol>`;
  const days = list('flex.weekdays.short'), long = list('flex.weekdays.long');
  const week = hasPrice ? `<div class="flex-week-wrap" data-min-level="advanced">
    <h3 class="flex-ampel__sub">${esc(t('flex.ampel.week'))}</h3>
    <table class="flex-week">
      <caption class="sr-only">${esc(t('flex.ampel.weekCaption', { season: t(`flex.seasonBtn.${season}`) }))}</caption>
      <thead><tr><td></td>${Array.from({ length: 24 }, (_, h) => `<th scope="col"><span${h % 3 ? ' class="sr-only"' : ''}>${h}</span></th>`).join('')}</tr></thead>
      <tbody>${p.week.map((row, w) => `<tr><th scope="row"><span aria-hidden="true">${esc(days[w] || '')}</span><span class="sr-only">${esc(long[w] || '')}</span></th>${row.map((c, h) =>
        `<td class="flex-lv--${esc(c.level || 'none')}"><span class="sr-only">${esc(t('flex.ampel.hourCell', { hour: hh(h), level: lvl(c.level), ct: c.ct != null ? ct1(c.ct) : '–' }))}</span></td>`).join('')}</tr>`).join('')}</tbody>
    </table></div>` : '';
  const cheap = p.windows?.[0];
  const peak = hasPrice ? p.day_peak : null;
  const summary = cheap ? t('flex.ampel.summary', { cheap: windowText(cheap), peak: peak ? windowText(peak) : '–' }) : '';
  return `<div class="flex-ampel__head"><h2 class="card__title">${esc(t('flex.ampel.title'))}</h2>${seg}</div>
    <p class="muted small">${esc(lead)}</p>
    <h3 class="flex-ampel__sub">${esc(t('flex.ampel.day'))}</h3>
    ${band}
    <p class="flex-legend small"><span class="flex-key flex-lv--cheap" aria-hidden="true">${LEVEL_SYMBOL.cheap}</span> ${esc(lvl('cheap'))}
      <span class="flex-key flex-lv--mid" aria-hidden="true">${LEVEL_SYMBOL.mid}</span> ${esc(lvl('mid'))}
      <span class="flex-key flex-lv--expensive" aria-hidden="true">${LEVEL_SYMBOL.expensive}</span> ${esc(lvl('expensive'))}
      ${p.hours.some(h => h.sun_level === 'strong') ? `<span class="flex-key flex-sun--strong" aria-hidden="true">☀</span> ${esc(sunWord('strong'))}` : ''}</p>
    ${summary ? `<p class="flex-ampel__summary">${esc(summary)}</p>` : ''}
    ${hasPrice && p.min_ct != null ? `<p class="muted small" data-role="ampel-range">${esc(t(ins.signal === 'spot' ? 'flex.ampel.range' : 'flex.ampel.rangeTou', { min: ct1(p.min_ct), max: ct1(p.max_ct), mean: ct1(p.mean_ct) }))}</p>` : ''}
    ${week}`;
}

function windowsCard(ins, season) {
  const p = ins.pattern?.[season];
  const ws = ins.signal ? (p?.windows || []) : [];
  if (!ws.length) return '';
  return `<div class="card" id="flex-windows" data-min-level="advanced">
    <h2 class="card__title">${esc(t('flex.windows.title'))}${ins.signal === 'spot' ? netTag() : ''}</h2>
    <p class="muted small">${esc(t('flex.windows.lead', { season: t(`flex.seasonBtn.${season}`), hours: ins.assumptions?.window_hours ?? 3 }))}</p>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th scope="col">${esc(t('flex.windows.col.window'))}</th><th scope="col" class="num">${esc(t(ins.signal === 'tou' ? 'flex.windows.col.grid' : 'flex.windows.col.price'))}</th>
        <th scope="col" class="num flex-col-opt">${esc(t('flex.windows.col.vsMean'))}</th><th scope="col">${esc(t('flex.windows.col.sun'))}</th></tr></thead>
      <tbody>${ws.map(w => `<tr><th scope="row">${esc(windowText(w))}</th><td class="num">${esc(ct1(w.ct))}</td>
        <td class="num flex-col-opt">${w.below_ct == null ? '–' : esc(t('flex.windows.below', { ct: ct1(w.below_ct) }))}</td>
        <td>${w.sun == null ? '–' : esc(t(`flex.ampel.sun.${w.sun >= 0.5 ? 'strong' : w.sun >= 0.15 ? 'some' : 'none'}`))}</td></tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}

function solarCard(ins, year) {
  const s = ins.solar;
  return `<div class="card flex-solar" data-min-level="advanced">
    <h2 class="card__title"><span aria-hidden="true">☀</span> ${esc(t('flex.solar.title'))}</h2>
    <p>${esc(t('flex.solar.now', { year, self: fmt.unit(s.self_kwh, 'kWh'), gen: fmt.unit(s.generation_kwh, 'kWh'), share: fmt.pct(s.self_share, 0), feed: fmt.unit(s.feed_kwh, 'kWh') }))}</p>
    <div class="flex-solar__bars" aria-hidden="true">
      <span class="flex-solar__bar flex-solar__bar--self" style="width:${(s.self_share * 100).toFixed(1)}%"></span>
      <span class="flex-solar__bar flex-solar__bar--more" style="width:${(s.more_share * 100).toFixed(1)}%"></span>
    </div>
    ${s.more_kwh > 0 ? `<p>${esc(t('flex.solar.more', { more: fmt.unit(s.more_kwh, 'kWh'), after: fmt.pct(s.self_share_after, 0), share: fmt.pct(s.self_share, 0),
      eur: fmt.eur0(s.more_eur), wp: ct1(s.working_ct), feed: ct1(s.feed_ct) }))}</p>` : `<p class="muted">${esc(t('flex.solar.noMore'))}</p>`}
  </div>`;
}

function leversCard(ins) {
  const levers = ins.levers || [];
  if (!levers.length) return '';
  const sig = (l) => t(`flex.signal.${l.signal}`);
  const a = ins.assumptions || {};
  const ap = a.appliances || {};
  return `<div class="card flex-levers" data-min-level="advanced">
    <h2 class="card__title">${esc(t('flex.lever.title'))}</h2>
    <p class="muted small">${esc(t('flex.lever.lead'))}</p>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th scope="col">${esc(t('flex.lever.col.device'))}</th><th scope="col">${esc(t('flex.lever.col.signal'))}</th>
        <th scope="col" class="num flex-col-opt">${esc(t('flex.lever.col.shift'))}</th><th scope="col" class="num">${esc(t('flex.lever.col.eur'))}</th></tr></thead>
      <tbody>${levers.map(l => `<tr data-lever="${esc(l.kind)}" data-signal="${esc(l.signal)}"><th scope="row">${esc(leverLabel(l))}${l.basis === 'assumed' ? ` <span class="tag flex-tag flex-tag--assumed">${esc(t('flex.basis.assumed'))}</span>` : ''}${availabilityTag(l.kind === 'ev' ? ins.ev_availability : null)}</th>
        <td>${esc(sig(l))} <span class="muted small">· ${esc(t(l.real ? 'flex.real.yes' : 'flex.real.no'))}</span></td>
        <td class="num flex-col-opt">${esc(fmt.unit(l.shift_kwh, 'kWh'))}</td><td class="num"><strong>${esc(fmt.eur0(l.eur))}</strong></td></tr>`).join('')}</tbody>
    </table></div>
    <p class="small">${esc(t('flex.lever.total', { eur: fmt.eur0(ins.total_eur), real: fmt.eur0(ins.total_real_eur) }))}</p>
    <p class="muted small">${esc(t('flex.lever.assumptions', {
      hp: fmt.pct(a.heat_pump_share ?? 0.3, 0),
      wkwh: fmt.dec(ap.washer?.kwh_per_cycle ?? 0.9, 1), wn: fmt.dec(ap.washer?.cycles_per_week ?? 4, 1),
      dkwh: fmt.dec(ap.dishwasher?.kwh_per_cycle ?? 1, 1), dn: fmt.dec(ap.dishwasher?.cycles_per_week ?? 5, 1),
      h: fmt.int(ap.washer?.hours ?? 2) }))}</p>
    ${ins.ev_availability ? `<p class="muted small" data-role="ev-basis">${esc(evBasisText(ins.ev_availability))}</p>` : ''}
    <p class="muted small">${esc(t('flex.lever.upper', { vat: fmt.pct((ins.vat_pct ?? 19) / 100, 0) }))}</p>
  </div>`;
}

function kpis(items) {
  return `<div class="kpi-grid">${items.map(([label, value, help]) => `
    <div class="kpi"><div class="kpi__label">${esc(label)}${help ? info(help) : ''}</div><div class="kpi__value">${esc(value)}</div></div>`).join('')}</div>`;
}

function meterCard(m, year) {
  const tot = m.total;
  return `<div class="card">
    <h2 class="card__title">${esc(m.name)}${m.controllable ? ` <span class="tag">${esc(t('flex.controllable'))}</span>` : ''}${tot ? netTag() : ''}</h2>
    ${tot ? kpis([
      [t('flex.kpi.kwh'), `${fmt.num(tot.priced_kwh, 0)} kWh`],
      [t('flex.kpi.cheap'), fmt.pct(tot.cheap_share, 0)],
      [t('flex.kpi.negative'), `${fmt.num(tot.negative_kwh, 0)} kWh`],
      [t('flex.kpi.own'), `${ct(tot.own_ct)} / ${ct(tot.market_ct)}`],
      [t('flex.kpi.factor'), tot.profile_factor == null ? '–' : fmt.num(tot.profile_factor, 2), 'profileFactor'],
      [t('flex.kpi.advantage'), fmt.eur(tot.advantage_eur)],
    ]) : `<p class="muted">${esc(t('flex.noOverlap'))}</p>`}
    ${tot ? `<p class="small">${esc(t(tot.advantage_eur >= 0 ? 'flex.verdict.good' : 'flex.verdict.bad', { eur: fmt.eur(Math.abs(tot.advantage_eur)), factor: fmt.num(tot.profile_factor ?? 1, 2) }))}</p>` : ''}
    <details class="flex-months"><summary>${esc(t('flex.months'))}</summary>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>${esc(t('flex.col.month'))}</th><th class="num">kWh</th><th class="num">${esc(t('flex.col.cheap'))}</th>
          <th class="num">${esc(t('flex.col.negative'))}</th><th class="num">${esc(t('flex.col.own'))}</th><th class="num">${esc(t('flex.col.market'))}</th>
          <th class="num">${esc(t('flex.col.advantage'))}</th><th class="num">${esc(t('flex.col.coverage'))}</th></tr></thead>
        <tbody>${(m.months || []).map(x => `<tr><td>${esc(month(year, x.month))}</td>
          <td class="num">${fmt.num(x.kwh, 1)}</td><td class="num">${fmt.num(x.cheap_kwh, 1)}</td><td class="num">${fmt.num(x.negative_kwh, 1)}</td>
          <td class="num">${ct(x.own_ct)}</td><td class="num">${ct(x.market_ct)}</td>
          <td class="num">${x.advantage_eur == null ? '–' : fmt.eur(x.advantage_eur)}</td><td class="num">${fmt.pct(x.coverage, 0)}</td></tr>`).join('')}</tbody>
      </table></div>
    </details>
  </div>`;
}

function batteryCard(b) {
  const tot = b.total;
  return `<div class="card">
    <h2 class="card__title">${esc(t('flex.battery.title'))}${netTag()}</h2>
    ${kpis([
      [t('flex.battery.charged'), `${fmt.num(tot.charged_kwh, 0)} kWh · ${ct(tot.charge_ct)}`],
      [t('flex.battery.discharged'), `${fmt.num(tot.discharged_kwh, 0)} kWh · ${ct(tot.discharge_ct)}`],
      [t('flex.battery.value'), fmt.eur(tot.value_eur)],
    ])}
    <p class="small muted">${esc(t('flex.battery.hint'))}</p>
  </div>`;
}

function module3Card(m) {
  const tot = m.total;
  return `<div class="card">
    <h2 class="card__title">${esc(t('flex.module3.title', { name: m.name }))}${info('module14a')}</h2>
    ${kpis([
      [t('flex.module3.low'), `${fmt.num(tot.low_kwh, 0)} kWh`],
      [t('flex.module3.high'), `${fmt.num(tot.high_kwh, 0)} kWh`],
      [t('flex.module3.standard'), `${fmt.num(tot.standard_kwh, 0)} kWh`],
      [t('flex.module3.saving'), fmt.eur(tot.saving_eur)],
    ])}
    <p class="small muted">${esc(t('flex.module3.hint'))}</p>
  </div>`;
}

/** names: Zählername je ID aus der Auswertung — Reihen anderer Zähler (Speicher an der PV) zeigen Art · ID. */
function seriesCard(list, names = new Map()) {
  if (!list.length) return '';
  return `<div class="card" data-min-level="expert">
    <h2 class="card__title">${esc(t('flex.series.title'))}</h2>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>${esc(t('flex.series.what'))}</th><th class="num">${esc(t('flex.year'))}</th>
        <th class="num">${esc(t('flex.col.coverage'))}</th><th>${esc(t('flex.series.source'))}</th><th></th></tr></thead>
      <tbody>${list.map(s => `<tr>
        <td>${esc(s.kind === 'price' ? t('flex.series.prices') : (names.get(s.meter_id) || `${s.utility} · ${s.meter_id}`))}</td>
        <td class="num">${s.year}</td><td class="num">${fmt.pct(s.slots ? s.filled / s.slots : 0, 0)}</td>
        <td>${esc(t('flex.series.sources.' + (['import', 'ingest', 'smard'].includes(s.source) ? s.source : 'import')))}</td>
        <td class="row-actions"><button type="button" class="icon-btn" data-series-del="${esc(s.id)}" aria-label="${esc(t('flex.series.delete'))}"><span aria-hidden="true">🗑️</span></button></td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}
