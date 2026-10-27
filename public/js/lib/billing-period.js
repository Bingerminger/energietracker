// =====================================================================
// v3.4.0 (N1021) — Abrechnungsperioden nach dem Stichtag der Verbrauchsart
// (Einstellung `billing_cycle_anchor_<art>`, MM-TT, Standard 01-01 =
// Kalenderjahr). „Rechnung prüfen“ beginnt mit der letzten abgeschlossenen
// Periode — auf beiden Wegen, über Kosten und über die Verbrauchsart. Bis
// v3.3 nahm der Weg über die Verbrauchsart das angezeigte (meist laufende)
// Jahr, der andere das Vorjahr, und der Stichtag blieb unbeachtet.
//
// Zeiträume hier sind „bis einschließlich“ (`until`), wie auf der Rechnung.
// Die Schnittstelle `bill-check` nimmt `to` ausschließlich: `exclusiveEnd()`.
// =====================================================================

import { t, getLocale } from './i18n.js';
import { fmt } from './format.js';

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Hinweis zum Stichtag, wenn er nicht zur letzten Rechnung (bzw. zu den Verträgen) passt — oder null.
 * v3.9.0 (Skeptiker M7) — eine Stelle für Abrechnungsjahr, Saldo-Karte und „Kosten & Verträge“.
 * @param {{anchor:string, suggested_anchor:?string, suggested_anchor_source:?string, last_bill_until:?string}} h
 */
export function anchorHintText(h) {
  if (!h?.suggested_anchor) return null;
  const md = (s) => { const [m, d] = String(s).split('-').map(Number); return new Date(Date.UTC(2000, m - 1, d)).toLocaleDateString(getLocale(), { day: 'numeric', month: 'long', timeZone: 'UTC' }); };
  if (h.suggested_anchor_source === 'contracts') return t('billing.suggestContracts', { anchor: md(h.anchor), day: md(h.suggested_anchor) });
  return h.last_bill_until ? t('billing.suggest', { date: fmt.date(h.last_bill_until), anchor: md(h.anchor), day: md(h.suggested_anchor) }) : null;
}

/** Stichtag wie der Server (SettingsService::isMonthDay, nextBillingAnchor): ungültig → 01-01, 29.–31.02. → 28.02. */
export function normalizeAnchor(anchor) {
  const m = /^(\d{2})-(\d{2})$/.exec(String(anchor || ''));
  if (!m) return '01-01';
  const mo = Number(m[1]), d = Number(m[2]);
  const probe = new Date(Date.UTC(2024, mo - 1, d));
  if (mo < 1 || mo > 12 || probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d) return '01-01';
  return mo === 2 && d > 28 ? '02-28' : `${m[1]}-${m[2]}`;
}

/** ISO-Datum ± Tage (UTC, ohne Sommerzeitsprung). */
export function addDays(iso, days) {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days));
  return dt.toISOString().slice(0, 10);
}

/**
 * Letzte abgeschlossene Abrechnungsperiode an einem Tag: von einem Stichtag
 * bis einschließlich des Tags vor dem nächsten, der höchstens heute ist.
 * Stichtag 01-01, heute 2026-10-13 → 2025-01-01 bis 2025-12-31; Stichtag
 * 06-01 → 2025-06-01 bis 2026-05-31; am Stichtag selbst endet sie gestern.
 * @returns {{from: string, until: string}}
 */
export function lastClosedPeriod(anchor, today) {
  const a = normalizeAnchor(anchor);
  const y = Number(String(today).slice(0, 4));
  const current = `${y}-${a}` <= today ? y : y - 1;   // Beginn der laufenden Periode
  return { from: `${current - 1}-${a}`, until: addDays(`${current}-${a}`, -1) };
}

/**
 * v3.6.0 (Paket abrechnung) — Welche Periode „Rechnung prüfen“ vorschlägt:
 * die jüngste abgeschlossene Abrechnungsperiode, wie im Abrechnungsjahr aus
 * den Perioden des Servers (Rechnungen, sonst Stichtag; GET …/billing-periods).
 * Gibt es keine, wie bisher die letzte abgeschlossene nach dem Stichtag. Bis
 * v3.5 nahm die Seite immer den Stichtag — rechnet der Versorger in einem
 * anderen Rhythmus ab, schlug sie einen Zeitraum vor, der quer zu den
 * Rechnungen lag.
 * @returns {{from: string, until: string}}
 */
export function periodToCheck(periods, anchor, today) {
  const done = (Array.isArray(periods) ? periods : []).filter(p => p && p.complete && !p.running
    && ISO.test(p.from || '') && ISO.test(p.until || '') && p.until < today && Number(p.days) > 0);
  const last = done[done.length - 1];
  return last ? { from: last.from, until: last.until } : lastClosedPeriod(anchor, today);
}

/** `until` (einschließlich) → `to` der Schnittstelle (ausschließlich). */
export function exclusiveEnd(until) {
  return ISO.test(until || '') ? addDays(until, 1) : until;
}

/** Ganzes Kalenderjahr? Dann reicht die Jahreszahl als Name. */
export function calendarYearOf({ from, until } = {}) {
  const y = String(from || '').slice(0, 4);
  return from === `${y}-01-01` && until === `${y}-12-31` ? Number(y) : null;
}

// =====================================================================
// v3.6.0 (F1030, GitHub #33) — Kalenderjahr oder Abrechnungsjahr. Eine Wahl
// je Gerät; sie wirkt in der Verbrauchsart, der Übersicht und der Analyse.
// Die Perioden und ihre Summen rechnet der Server
// (BillingPeriodService, GET …/billing-periods); hier wird nur angeordnet.
// =====================================================================

export const PERIOD_MODE_KEY = 'et-period-mode';

/** Gemerkte Wahl dieses Geräts: 'billing' oder 'calendar' (Speicher gesperrt → Kalenderjahr). */
export function periodMode() {
  try { return globalThis.localStorage?.getItem(PERIOD_MODE_KEY) === 'billing' ? 'billing' : 'calendar'; } catch { return 'calendar'; }
}

export function setPeriodMode(mode) {
  try { globalThis.localStorage?.setItem(PERIOD_MODE_KEY, mode === 'billing' ? 'billing' : 'calendar'); } catch { /* gilt nur jetzt */ }
}

/** Gibt es hier Abrechnungsjahre? Nur Arten mit Rechnungsprüfung und Zähler mit Ständen. */
export function billingApplies(u, meter = null) {
  return !!u?.supports_bill_check && meter?.capture !== 'period';
}

/** Name einer Periode: „2025/26“ bzw. „2025“ vom Server, sonst die Daten. */
export function periodName(p, fmtDate = (d) => d) {
  if (!p) return '';
  return p.label || `${fmtDate(p.from)}–${fmtDate(p.until)}`;
}

/** Perioden mit Daten (die laufende ohne Ablesung fällt weg). */
export function periodsWithData(periods) {
  return (Array.isArray(periods) ? periods : []).filter(p => Number(p?.days) > 0);
}

/** Die gewählte Periode (Beginn = `from`), sonst die jüngste mit Daten. */
export function pickPeriod(periods, from = null) {
  const list = periodsWithData(periods);
  return list.find(p => p.from === from) || list[list.length - 1] || null;
}

/** Erster Monat des Abrechnungsjahrs (1–12): Beginn der jüngsten Periode. */
export function refMonth(periods) {
  const list = periodsWithData(periods);
  const last = list[list.length - 1];
  return last ? Number(String(last.from).slice(5, 7)) : 1;
}

/**
 * Stelle eines Monats im Abrechnungsjahr (1 …): Monate seit dem ersten Monat
 * `ref`. Eine Teilperiode ab der ersten Ablesung steht so an ihrem Platz, und
 * bei einem Stichtag mitten im Monat steht dessen zweites Stück am Ende (13).
 */
export function slotOf(month, index, ref = 1) {
  const pos = (Number(month) - ref + 12) % 12;
  return (pos === 0 && index > 0 ? 12 : pos) + 1;
}

/** Spaltennamen 1 … n ab dem ersten Monat `ref`. */
export function slotLabels(n, ref, monthName) {
  return Array.from({ length: n }, (_, i) => monthName(((ref - 1 + i) % 12) + 1));
}

/**
 * Monatsreihe einer Periode als Zeilen wie die Monatszeilen der
 * Verbrauchsansicht; `slot` (slotOf) stellt die Vorperiode an dieselbe
 * Stelle. Temperatur und Vertrag aus dem Kalendermonat, Gradtage nach Tagen
 * anteilig, Saldo fortlaufend.
 */
export function periodRows(period, monthly = [], consKey = 'kwh', ref = null) {
  const byYm = new Map((monthly || []).map(m => [m.ym, m]));
  const first = Number(String(period?.from || '').slice(5, 7)) || 1;
  let cum = 0;
  return (period?.months || []).map((r, i) => {
    const cal = byYm.get(r.ym) || {};
    // v3.9.0 (F1032, Skeptiker M9) — Rest-Abrechnung am Elternzähler: Verbrauch = Messung,
    // die Menge der Verträge als `billed_kwh` (Preis je kWh auf diese Menge)
    const measured = consKey === 'kwh' && r.measured_kwh != null ? Number(r.measured_kwh) : null;
    const billed = measured != null && Math.abs(measured - (Number(r.kwh) || 0)) > 0.05 ? Number(r.kwh) || 0 : null;
    const q = billed != null ? measured : (Number(r[consKey]) || 0);
    const qPrice = billed ?? q;
    cum += (Number(r.cost) || 0) - (Number(r.advance_eur) || 0);
    return {
      ...r,
      ...(billed != null ? { kwh: measured, billed_kwh: billed } : {}),
      slot: slotOf(r.month, i, ref ?? first),
      contract_id: cal.contract_id ?? null,
      avg_temp: cal.avg_temp ?? null,
      hdd: cal.hdd != null && cal.days ? cal.hdd * r.days / cal.days : null,
      kwh_per_day: r.days ? q / r.days : null,
      working_price_ct: qPrice > 0 && r.kwh_cost != null ? r.kwh_cost / qPrice * 100 : null,
      cumulative_balance: Math.round(cum * 100) / 100,
    };
  });
}
