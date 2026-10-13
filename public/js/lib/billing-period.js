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

const ISO = /^\d{4}-\d{2}-\d{2}$/;

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

/** `until` (einschließlich) → `to` der Schnittstelle (ausschließlich). */
export function exclusiveEnd(until) {
  return ISO.test(until || '') ? addDays(until, 1) : until;
}

/** Ganzes Kalenderjahr? Dann reicht die Jahreszahl als Name. */
export function calendarYearOf({ from, until } = {}) {
  const y = String(from || '').slice(0, 4);
  return from === `${y}-01-01` && until === `${y}-12-31` ? Number(y) : null;
}
