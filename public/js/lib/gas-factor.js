// =====================================================================
// v2.7.0 — Gas-Umrechnung im Frontend (F1012 / N1014).
// Welcher Eintrag der datierten Liste `gas_conversion_factors` an einem Tag
// gilt, und in welchen Einheiten der Brennwert ein- und ausgegeben wird.
// Spiegel von ConversionFactorService::entryOn(): Der undatierte Eintrag gilt
// vor dem ersten Stichtag; liegen alle Stichtage in der Zukunft, der früheste.
// =====================================================================

// Brennwert-Einheiten der Eingabe. Gespeichert wird immer kWh/m³;
// `perKwh` rechnet 1 kWh/m³ in die Einheit um (1 kWh = 3,6 MJ = 0,0036 GJ).
export const CV_UNITS = {
  kwh: { label: 'kWh/m³', perKwh: 1,      digits: 3, sample: 11.4,   colKey: 'settings.gasFactors.colHs' },
  mj:  { label: 'MJ/m³',  perKwh: 3.6,    digits: 3, sample: 39.5,   colKey: 'settings.gasFactors.colHsMj' },
  gj:  { label: 'GJ/Smc', perKwh: 0.0036, digits: 6, sample: 0.0385, colKey: 'settings.gasFactors.colHsGj' },
};

/** Einheit zum Einstellungswert `gas_cv_unit`; Unbekanntes gilt als kWh/m³. */
export function cvUnit(key) {
  return CV_UNITS[key] || CV_UNITS.kwh;
}

/** kWh je m³ eines Eintrags: Zustandszahl × Brennwert, sonst der direkte Faktor. */
export function gasFactorOf(e) {
  const z = Number(e?.zustandszahl), hs = Number(e?.brennwert);
  if (Number.isFinite(z) && Number.isFinite(hs) && z > 0 && hs > 0) return z * hs;
  return Number(e?.kwh_per_m3);
}

/** Eintrag, der am Tag `date` (JJJJ-MM-TT) gilt — null bei leerer Liste. */
export function gasEntryOn(list, date) {
  const sorted = [...(Array.isArray(list) ? list : [])]
    .sort((a, b) => String(a.from ?? '').localeCompare(String(b.from ?? '')));
  let best = null;
  for (const e of sorted) {
    if (!e.from) { best ??= e; continue; }
    if (e.from <= date) best = e;
  }
  return best ?? sorted[0] ?? null;
}
