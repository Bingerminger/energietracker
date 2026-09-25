// =====================================================================
// v2.13.0 (Review FE-10) — Ampel eines Tanks aus der Einstellung
// `tank_warn_pct` (Standard 15 %): „warn" ab der Schwelle, „alert" ab der
// Hälfte davon. Bis v2.12 standen hier feste 8 und 15 % — die Einstellung
// wirkte nur auf die Empfehlungen des Servers.
// =====================================================================

/** @returns {'ok'|'warn'|'alert'} */
export function tankLevel(pct, warnPct = 15) {
  const n = Number(warnPct);
  const warn = Number.isFinite(n) && n > 0 ? n : 15;
  if (pct <= Math.max(1, warn / 2)) return 'alert';
  return pct <= warn ? 'warn' : 'ok';
}
