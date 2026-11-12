#!/usr/bin/env node
/* =========================================================================
   Prüfhaushalt Wärmepumpe (v3.13.0, Paket pruefhaushalt)

   Warum es ihn gibt: Die Beispielhaushalte (tools/build-personas.mjs) erzeugen
   den Wärmebedarf als Konstante × Heizgradtage zur Heizgrenze 15 °C, also genau
   das Modell der App. Daran lässt sich ein Wettermodell für die Wärmepumpe
   nicht beurteilen (Zirkelschluss). Dieser Generator rechnet mit eigener
   Physik und importiert weder Code noch Konstanten der App. Geteilt sind nur
   das Dateiformat (Backup, Klimanormal, Viertelstunden, Ladevorgänge) und die
   Definition der Heizgradtage fürs Klimanormal (Summe von max(0, Grenze − T)).

   Wetter      34 erfundene Jahre bei 51° N: 30 fürs Klimanormal (1992–2021),
               drei Datenjahre und ein Prüfjahr (Juli 2022 bis Juni 2026).
               Tagesmittel = Jahresgang + träges Rauschen (zwei AR-Prozesse);
               Winter 2023/24 mild (+ 2 K), Winter 2024/25 kalt (− 2,5 K).
               Einstrahlung = extraterrestrische Strahlung (Breite,
               Deklination) × Klarheitsindex je Jahreszeit; im Winter sind die
               klaren Tage die kalten, im Sommer die warmen.
   Gebäude     Wärmeverlust UA × (Raumtemperatur − wirksame Außentemperatur,
               träge über zwei Tage) gegen innere und solare Gewinne mit
               Ausnutzungsgrad (Speichermasse). Eine Heizgrenze wird nicht
               gesetzt, sie ergibt sich aus Verlust und Gewinnen.
   Wärmepumpe  Arbeitszahl 0,45 × Carnot zwischen Verdampfung und Kondensation
               (Heizkurve Vorlauf 35 °C bei − 10 °C, 27 °C bei + 15 °C),
               Abtauen, Heizstab bei großer Kälte, Bereitschaft, Warmwasser
               mittags mit kälterem Zulauf im Winter. Haushalt B: Sole/Wasser.
   Strom       Haushalt mit Tagesgang, Licht im Winter, Urlaub; PV 9,9 kWp Süd
               35° (Neigung isotrop umgerechnet, Zelltemperatur, Schnee,
               Einspeisung höchstens 70 %); Speicher 10 kWh ab Juli 2023;
               Wallbox ab 15.06.2024 mit E-Auto (Verbrauch steigt bei Kälte),
               mittags bei Überschuss, sonst nachts im Niedertarif.
   Messkonzept Haushalt A wie #40: Kaskade, Z1 vorn mit HT/NT (Gruppe mit
               Gruppenvertrag der Wärmepumpe), dahinter Z2 Haushalt mit HT/NT
               und eigenem Vertrag, Einspeise-Zählwerke an Z1 und Z2, PV-
               Erzeugung Z3, Speicher Ladung/Entladung, Wallbox. Haushalt B:
               Neubau, Wärmepumpe am Haushaltszähler, Heizung mit Strom.

   Gerechnet wird je Stunde in Normalzeit (MEZ, ohne Sommerzeit); Tage der
   Wahrheit sind MEZ-Tage. Abgelesen wird in drei Varianten derselben
   Wahrheit: V1 monatlich und unregelmäßig (eine Lücke, eine geschätzte
   Ablesung), V2 erst vierteljährlich, dann jährlich, V3 täglich wie Home
   Assistant, dazu Viertelstunden von Z2 für 2024. Die Wahrheit liegt getrennt
   (`a-truth.json`, `b-truth.json`); verglichen wird mit ihr, nicht mit
   Ablesungen. Alle Werte sind erfunden, fester Seed, jeder Lauf schreibt
   dieselben Dateien.

     node tests/fixtures/heatpump-household/generate.mjs          # schreibt die Dateien
     node tests/fixtures/heatpump-household/generate.mjs --check  # vergleicht nur, Exit 1 bei Abweichung

   Geprüft von tests/unit/Services/HeatPumpHouseholdTest.php, gemessen mit
   tools/rueckrechnung.php, beschrieben in docs/entwicklung/tests.md.
   ========================================================================= */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CHECK = process.argv.includes('--check');
const QUIET = process.argv.includes('--quiet');

// ── Parameter (erfunden, vor der ersten Rückrechnung festgelegt) ──────────
export const PARAMS = {
  location: { name: 'Musterstadt', lat: 51.0, lon: 10.0 },
  normal_years: [1992, 2021],
  weather_from: '1992-01-01',
  data_from: '2022-07-01',          // Haushalt A: erste Ablesung
  data_to: '2026-06-30',            // letzter Tag der Wahrheit (Prüfjahr bis hier)
  weather: {
    t_mean: 9.8, t_amp: 9.3, t_coldest_doy: 20,
    ar_phi: 0.82, sd_winter: 3.4, sd_summer: 2.3,      // träges Tagesrauschen
    slow_phi: 0.97, slow_sd: 0.9,                      // Großwetterlage über Wochen
    // Abweichung je Winter (Jahr seines Novembers), Dezember bis Februar voll, November und März 60 %
    winter_offset: { 2022: 0.0, 2023: 2.0, 2024: -2.5, 2025: 0.0 },
    kt_mean: 0.39, kt_amp: 0.12, kt_sd: 0.12, kt_coupling: 0.06,
    kt_slow_phi: 0.98, kt_slow_sd: 0.035,                // Bewölkung über Wochen (sonnige und trübe Jahre)
    albedo: 0.2,
  },
  a: {
    name: 'Haushalt A (Luft/Wasser, saniert, Kaskade wie #40)',
    from: '2022-07-01',
    room_c: 20.5, vacation_room_c: 17.0,
    ua_kw_per_k: 0.135, internal_kwh: 10.0, solar_aperture_m2: 6.2, tau_h: 70,
    heat_noise: 0.04, lag_tau_h: 48,
    dhw_persons: 3, dhw_l_per_person: 42, dhw_c: 48, dhw_loss_kwh: 1.3,
    source: 'air', evap_approach_k: 4, cond_approach_k: 4, carnot_eff: 0.45,
    flow_cold: [-10, 35], flow_warm: [15, 27],
    defrost: { from: -3, to: 5, factor: 0.90 },
    rod: { below: -7, per_k: 0.06, max: 0.4 },
    standby_w: 15,
    house_kwh_year: 3200, light_kw: 0.12,
    pv: { kwp: 9.9, tilt: 35, pr: 0.84, temp_coeff: -0.0035, cell_k_per_kw: 30, feed_limit_share: 0.70 },
    battery: { from: '2023-07-01', kwh: 10, usable: 9.5, kw: 5, eta: 0.95 },
    ev: { from: '2024-06-15', battery_kwh: 64, kwh_per_km_20c: 0.16, cold_plus_at_minus5: 0.35, loss: 0.10, kw: 11, min_pv_kw: 1.4 },
    billing_anchor: '10-01', feed_anchor: '01-01',
    vacation: [['2022-08-06', '2022-08-20'], ['2023-02-18', '2023-02-25'], ['2023-07-29', '2023-08-12'], ['2024-02-10', '2024-02-17'],
               ['2024-08-03', '2024-08-17'], ['2025-03-01', '2025-03-08'], ['2025-08-09', '2025-08-23'], ['2026-02-14', '2026-02-21']],
  },
  b: {
    name: 'Haushalt B (Neubau, Sole/Wasser, Wärmepumpe am Haushaltszähler)',
    from: '2023-07-01',
    room_c: 20.5, vacation_room_c: 20.5,
    ua_kw_per_k: 0.080, internal_kwh: 9.5, solar_aperture_m2: 6.0, tau_h: 100,
    heat_noise: 0.04, lag_tau_h: 48,
    dhw_persons: 3, dhw_l_per_person: 40, dhw_c: 50, dhw_loss_kwh: 1.1,
    source: 'brine', brine_mean: 6.5, brine_amp: 3.0, brine_peak_doy: 280, brine_load_k: 2.2,
    evap_approach_k: 3, cond_approach_k: 4, carnot_eff: 0.45,
    flow_cold: [-10, 31], flow_warm: [15, 25],
    defrost: null, rod: null,
    standby_w: 10,
    house_kwh_year: 2800, light_kw: 0.10,
    billing_anchor: '04-01',
    vacation: [['2023-08-12', '2023-08-26'], ['2024-07-27', '2024-08-10'], ['2025-08-02', '2025-08-16']],
  },
  // HT 06–22 Uhr, NT 22–06 Uhr (Normalzeit)
  ht_hours: [6, 22],
  // Preise fest über den ganzen Zeitraum; der Abschlag folgt jeder Jahresrechnung (erste Rate = Schätzung des Versorgers)
  prices: {
    wp: { provider: 'Wärmestrom Musterstadt', tariff: 'Wärmepumpe HT/NT', ht: 27.0, nt: 21.0, base: 10.0, first_advance: 45 },
    house: { provider: 'Musterversorger', tariff: 'Haushalt HT/NT', ht: 33.0, nt: 26.0, base: 12.0, first_advance: 50 },
    feed: { provider: 'Netzbetreiber Musterstadt', tariff: 'Einspeisevergütung', ct: 8.2 },
    b: { provider: 'Musterversorger', tariff: 'Wärme und Haushalt', ct: 29.5, base: 11.5, first_advance: 95 },
  },
};
const P = PARAMS;
const W = P.weather;

// ── Zufall mit festem Seed (mulberry32) ──────────────────────────────────
function rng(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const gauss = (r) => Math.sqrt(-2 * Math.log(Math.max(r(), 1e-12))) * Math.cos(2 * Math.PI * r());
const round = (x, d) => { const f = 10 ** d; return Math.round(x * f) / f; };
const clamp = (x, lo, hi) => Math.min(hi, Math.max(lo, x));
const sum = (a) => a.reduce((s, v) => s + v, 0);

// ── Kalender (UTC-Daten, Stunden in Normalzeit) ──────────────────────────
const toDate = (s) => new Date(s + 'T12:00:00Z');
const iso = (d) => d.toISOString().slice(0, 10);
const addDays = (s, n) => { const d = toDate(s); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
const addMonths = (s, n) => { const d = new Date(Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1 + n, 1, 12)); return iso(d).slice(0, 8) + s.slice(8, 10); };
const firstOfMonth = (s) => s.slice(0, 8) + '01';
function* days(from, toIncl) { for (let d = from; d <= toIncl; d = addDays(d, 1)) yield d; }
const doyOf = (s) => Math.round((toDate(s) - toDate(s.slice(0, 4) + '-01-01')) / 86400000) + 1;
const weekday = (s) => toDate(s).getUTCDay();   // 0 = Sonntag
const daysIn = (ym) => new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7), 0)).getUTCDate();
const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
const inRanges = (d, ranges) => ranges.some(([a, b]) => d >= a && d <= b);
const isHt = (h) => h >= P.ht_hours[0] && h < P.ht_hours[1];

// ── Sonne: Geometrie und Umrechnung auf die geneigte Fläche ──────────────
const RAD = Math.PI / 180;
const LAT = P.location.lat * RAD;
const declination = (doy) => 23.45 * RAD * Math.sin(2 * Math.PI * (284 + doy) / 365);
/** Extraterrestrische Tagessumme auf die Horizontale (kWh/m²). */
function h0Day(doy) {
  const dec = declination(doy);
  const ws = Math.acos(clamp(-Math.tan(LAT) * Math.tan(dec), -1, 1));
  const e0 = 1 + 0.033 * Math.cos(2 * Math.PI * doy / 365);
  return (24 / Math.PI) * 1.367 * e0 * (Math.cos(LAT) * Math.cos(dec) * Math.sin(ws) + ws * Math.sin(LAT) * Math.sin(dec));
}
/** Sonnenhöhe je Stunde (Mitte der Stunde, Normalzeit, Sonnenmittag 12:20 bei 10° Ost). */
function sunHours(doy) {
  const dec = declination(doy);
  const out = [];
  for (let h = 0; h < 24; h++) {
    const solar = h + 0.5 + (P.location.lon - 15) / 15;
    const w = 15 * RAD * (solar - 12);
    out.push({ w, cz: Math.sin(LAT) * Math.sin(dec) + Math.cos(LAT) * Math.cos(dec) * Math.cos(w), dec });
  }
  return out;
}
/** Diffusanteil der Tagessumme (Erbs). */
function diffuseShare(kt, doy) {
  const dec = declination(doy);
  const ws = Math.acos(clamp(-Math.tan(LAT) * Math.tan(dec), -1, 1)) / RAD;
  const k = Math.min(kt, 0.715);
  return ws <= 81.4
    ? clamp(1.391 - 3.560 * k + 4.189 * k * k - 2.137 * k ** 3, 0.18, 1)
    : clamp(1.311 - 3.022 * k + 3.427 * k * k - 1.821 * k ** 3, 0.18, 1);
}
/** Stündliche Einstrahlung horizontal und auf zwei Flächen nach Süden (Neigung in Grad), kWh/m². */
function hourlyRadiation(doy, H, kt, tilts) {
  const sh = sunHours(doy);
  const weights = sh.map(s => Math.max(0, s.cz));
  const wsum = sum(weights);
  const kd = diffuseShare(kt, doy);
  const out = { horiz: new Array(24).fill(0) };
  for (const t of tilts) out[t] = new Array(24).fill(0);
  if (wsum <= 0) return out;
  for (let h = 0; h < 24; h++) {
    if (weights[h] <= 0) continue;
    const g = H * weights[h] / wsum;
    const gd = kd * g, gb = g - gd;
    out.horiz[h] = g;
    for (const t of tilts) {
      const b = t * RAD;
      const ct = Math.sin(sh[h].dec) * Math.sin(LAT - b) + Math.cos(sh[h].dec) * Math.cos(LAT - b) * Math.cos(sh[h].w);
      const rb = Math.max(0, ct) / Math.max(sh[h].cz, 0.087);
      out[t][h] = gb * rb + gd * (1 + Math.cos(b)) / 2 + g * W.albedo * (1 - Math.cos(b)) / 2;
    }
  }
  return out;
}

// ── Wetter: Jahresgang, träges Rauschen, ein milder und ein kalter Winter ─
function winterWeight(d) {
  const m = +d.slice(5, 7);
  if (m === 12 || m === 1 || m === 2) return 1;
  if (m === 11 || m === 3) return 0.6;
  return 0;
}
function winterYear(d) { const m = +d.slice(5, 7), y = +d.slice(0, 4); return m >= 11 ? y : (m <= 3 ? y - 1 : null); }

const weather = {};   // Tag → {avg, min, max, rad, kt}
{
  // 1. träges Rauschen für alle Tage
  const r = rng(1301);
  const noise = {};
  let fast = 0, slow = 0;
  for (const d of days(P.weather_from, P.data_to)) {
    const season = Math.cos(2 * Math.PI * (doyOf(d) - W.t_coldest_doy) / 365.25);   // 1 im Januar, −1 im Juli
    const sd = (W.sd_winter + W.sd_summer) / 2 + (W.sd_winter - W.sd_summer) / 2 * season;
    fast = W.ar_phi * fast + sd * Math.sqrt(1 - W.ar_phi ** 2) * gauss(r);
    slow = W.slow_phi * slow + W.slow_sd * Math.sqrt(1 - W.slow_phi ** 2) * gauss(r);
    noise[d] = { fast, slow, sd, season };
  }
  // 2. Winter der Datenjahre: Dezember bis Februar weichen im Mittel genau um die
  //    vorgegebene Abweichung vom Jahresgang ab (der Zufall dieser Monate wird dafür
  //    verschoben, das Auf und Ab der Tage bleibt)
  const shift = {};
  for (const wy of Object.keys(W.winter_offset)) {
    const core = [...days(`${wy}-12-01`, `${+wy + 1}-02-28`)].filter(d => noise[d]);
    const realized = sum(core.map(d => noise[d].fast + noise[d].slow)) / core.length;
    shift[wy] = W.winter_offset[wy] - realized;
  }
  const r2 = rng(1302);
  // v3.13.0 (Korrektur nach dem ersten Lauf, im Bericht begründet) — Großwetterlage der Bewölkung: ohne sie
  // schwankte die Jahressumme der Einstrahlung um 0,9 % statt um 4 bis 5 % wie in echten Messreihen; ein
  // sonniges oder trübes Jahr kam nicht vor. Eigener Zufall, die Temperaturen bleiben dieselben.
  const r3 = rng(1303);
  let ktSlow = 0;
  for (const d of days(P.weather_from, P.data_to)) {
    const { fast: f, slow: s, sd, season } = noise[d];
    const doy = doyOf(d);
    const wy = winterYear(d);
    const offset = d >= P.data_from && wy !== null && shift[wy] !== undefined ? shift[wy] * winterWeight(d) : 0;
    const avg = W.t_mean - W.t_amp * season + f + s + offset;
    // Klarheit: im Winter klar = kalt, im Sommer klar = warm (an die Abweichung des Tages gekoppelt)
    const summer = Math.cos(2 * Math.PI * (doy - 172) / 365.25);
    const z = (f + s + offset) / sd;
    ktSlow = W.kt_slow_phi * ktSlow + W.kt_slow_sd * Math.sqrt(1 - W.kt_slow_phi ** 2) * gauss(r3);
    const kt = clamp(W.kt_mean + W.kt_amp * summer + W.kt_coupling * summer * z + ktSlow + W.kt_sd * gauss(r2), 0.06, 0.72);
    const rad = kt * h0Day(doy);
    const range = 3.0 + 7.5 * kt + 1.5 * (summer + 1) / 2 + 0.6 * r2();
    weather[d] = { avg: round(avg, 1), min: round(avg - 0.45 * range, 1), max: round(avg + 0.55 * range, 1), rad: round(rad, 3), kt };
  }
}
const hourTemps = (d) => {
  const w = weather[d];
  return Array.from({ length: 24 }, (_, h) => w.avg + (w.max - w.min) / 2 * Math.cos(2 * Math.PI * (h + 0.5 - 15) / 24));
};

// ── Klimanormal im Format der App (Heizgradtage je Grenze, Tagesmittel, Einstrahlung) ──
const BASES = []; for (let b = 6.0; b <= 22.0 + 1e-9; b += 0.5) BASES.push(round(b, 1));
const sdOf = (v) => { if (v.length < 2) return 0; const m = sum(v) / v.length; return Math.sqrt(sum(v.map(x => (x - m) ** 2)) / (v.length - 1)); };
const keyOf = (b) => b.toFixed(1);
function climateNormal(from, to, fetchedAt) {
  const sums = {}, cnt = {}, radSum = {};
  const doySum = new Array(366).fill(0), doyCnt = new Array(366).fill(0);
  for (const d of days(from, to)) {
    const w = weather[d];
    const y = +d.slice(0, 4), m = +d.slice(5, 7);
    (cnt[y] ??= {})[m] = (cnt[y][m] ?? 0) + 1;
    (radSum[y] ??= {})[m] = (radSum[y][m] ?? 0) + w.rad;
    let doy = doyOf(d);
    if (doy >= 60 && !isLeap(y)) doy++;
    doySum[doy - 1] += w.avg; doyCnt[doy - 1]++;
    for (const b of BASES) ((sums[b] ??= {})[y] ??= {})[m] = (sums[b][y][m] ?? 0) + Math.max(0, b - w.avg);
  }
  const complete = {};
  for (const y of Object.keys(cnt)) for (const m of Object.keys(cnt[y])) if (cnt[y][m] >= daysIn(`${y}-${String(m).padStart(2, '0')}`) - 1) (complete[y] ??= {})[m] = true;
  const hdd = {};
  for (const b of BASES) {
    const mean = [], sd = [];
    for (let m = 1; m <= 12; m++) {
      const vals = Object.keys(sums[b]).filter(y => complete[y]?.[m]).map(y => sums[b][y][m]);
      mean.push(vals.length ? round(sum(vals) / vals.length, 2) : null);
      sd.push(vals.length > 1 ? round(sdOf(vals), 2) : null);
    }
    const years = Object.keys(sums[b]).filter(y => [...Array(12).keys()].every(i => complete[y]?.[i + 1])).map(y => sum(Object.values(sums[b][y])));
    hdd[keyOf(b)] = { mean, sd, year_sd: years.length > 1 ? round(sdOf(years), 2) : null, years: years.length };
  }
  const doyAvg = doySum.map((s, i) => (doyCnt[i] > 0 ? round(s / doyCnt[i], 2) : null));
  if (doyAvg[59] === null && doyAvg[58] !== null && doyAvg[60] !== null) doyAvg[59] = round((doyAvg[58] + doyAvg[60]) / 2, 2);
  const radMonth = [];
  for (let m = 1; m <= 12; m++) {
    const vals = Object.keys(radSum).filter(y => complete[y]?.[m]).map(y => radSum[y][m]);
    radMonth.push(vals.length ? round(sum(vals) / vals.length, 2) : null);
  }
  return {
    version: 1, latitude: round(P.location.lat, 2), longitude: round(P.location.lon, 2),
    period: { from, to }, fetched_at: fetchedAt, days: [...days(from, to)].length,
    doy_avg: doyAvg, hdd, rad_month: radMonth,
  };
}
const NORMAL = climateNormal(`${P.normal_years[0]}-01-01`, `${P.normal_years[1]}-12-31`, '2022-06-15T03:00:00+02:00');
/** Je Kalenderjahr der Daten ein „Normal“ nur aus dem Ist-Wetter dieses Jahres (für „nachgerechnet“). */
const ACTUAL_NORMALS = {};
for (let y = +P.data_from.slice(0, 4); y <= +P.data_to.slice(0, 4); y++) {
  const to = `${y}-12-31` <= P.data_to ? `${y}-12-31` : P.data_to;
  ACTUAL_NORMALS[y] = climateNormal(`${y}-01-01`, to, `${y + 1}-01-02T03:00:00+01:00`);
}

// ── Gebäude und Wärmepumpe ───────────────────────────────────────────────
const flowTemp = (H, t) => clamp(H.flow_cold[1] + (H.flow_warm[1] - H.flow_cold[1]) * (t - H.flow_cold[0]) / (H.flow_warm[0] - H.flow_cold[0]), H.flow_warm[1], H.flow_cold[1]);
function cop(H, condC, evapC, hourT) {
  let c = H.carnot_eff * (condC + 273.15) / Math.max(5, condC - evapC);
  if (H.defrost && hourT >= H.defrost.from && hourT <= H.defrost.to) c *= H.defrost.factor;
  return clamp(c, 1.2, 8);
}
/** Ausnutzungsgrad der Gewinne (Verhältnis Gewinn/Verlust, Speichermasse über a). */
function gainUse(gamma, tauH) {
  const a = 1 + tauH / 15;
  if (Math.abs(gamma - 1) < 1e-6) return a / (a + 1);
  return (1 - gamma ** a) / (1 - gamma ** (a + 1));
}
/** Haushaltsstrom: Tagesgang (Mittel 1) */
const HOUSE_SHAPE = (() => {
  const s = [0.55, 0.5, 0.48, 0.47, 0.48, 0.55, 0.75, 1.0, 1.0, 0.95, 0.95, 1.05, 1.2, 1.1, 0.95, 0.9, 0.95, 1.15, 1.45, 1.6, 1.55, 1.35, 1.05, 0.75];
  const m = sum(s) / 24; return s.map(v => v / m);
})();

/**
 * Ein Haushalt Stunde für Stunde. Gibt Tagesreihen der Wahrheit zurück, dazu
 * die Viertelstunden-Quellen (Stundenwerte) und die Ladevorgänge.
 */
function simulate(key) {
  const H = P[key];
  const r = rng(key === 'a' ? 2201 : 2202);
  const rEv = rng(key === 'a' ? 2301 : 2302);
  const hasPv = key === 'a';
  const daysList = [...days(H.from, P.data_to)];
  const series = {};
  const put = (name, i, v) => { (series[name] ??= new Array(daysList.length).fill(0))[i] += v; };
  const hourly = {};   // Reihe → Stunde (Index ab erstem Tag × 24 + h) → kWh (nur Z2 für Viertelstunden)
  const sessions = [];

  // Haushaltsstrom: Jahresgang, Wochenende, Urlaub (weg), Licht in den dunklen wachen Stunden;
  // auf die Jahresmenge skaliert (ohne Rauschen gerechnet)
  const houseDayFactor = (d) => {
    const doy = doyOf(d);
    const wk = weekday(d);
    return (1 + 0.10 * Math.cos(2 * Math.PI * (doy - 355) / 365.25)) * (wk === 0 || wk === 6 ? 1.1 : 1) * (inRanges(d, H.vacation) ? 0.3 : 1);
  };
  const lightHours = (d) => sunHours(doyOf(d)).map((s, h) => (!inRanges(d, H.vacation) && h >= 6 && h <= 22 && s.cz < Math.sin(3 * RAD) ? 1 : 0));
  const baseDay = H.house_kwh_year / 365.25;
  let rawYear = 0;
  for (const d of daysList) rawYear += houseDayFactor(d) * baseDay + H.light_kw * sum(lightHours(d));
  const houseScale = (H.house_kwh_year * daysList.length / 365.25) / rawYear;

  let tEff = null;
  let soc = H.battery ? H.battery.usable / 2 : 0;
  const ev = H.ev ? { soc: 0.5 * H.ev.battery_kwh, plan: null, session: null, meter: 0 } : null;
  const evActive = (d) => H.ev && d >= H.ev.from;
  let prevMaxBelow = false;

  for (let i = 0; i < daysList.length; i++) {
    const d = daysList[i];
    const w = weather[d];
    const doy = doyOf(d);
    const T = hourTemps(d);
    const vac = inRanges(d, H.vacation);
    const rad = hourlyRadiation(doy, w.rad, w.kt, hasPv ? [90, H.pv.tilt] : [90]);
    // träge Außentemperatur (zwei Tage) je Stunde
    const alpha = 1 - Math.exp(-1 / H.lag_tau_h);
    const effH = [];
    for (let h = 0; h < 24; h++) { tEff = tEff === null ? T[h] : tEff + alpha * (T[h] - tEff); effH.push(tEff); }
    // Raumwärme des Tages: Verlust gegen Gewinne mit Ausnutzungsgrad
    const setC = vac ? H.vacation_room_c : H.room_c;
    const loss = H.ua_kw_per_k * sum(effH.map(t => setC - t));
    const solar = H.solar_aperture_m2 * sum(rad[90]);
    const gains = H.internal_kwh * (vac ? 0.4 : 1) + solar;
    let heat = 0;
    if (loss > 0) { const g = gains / loss; heat = Math.max(0, loss - gainUse(g, H.tau_h) * gains); }
    heat *= Math.max(0, 1 + H.heat_noise * gauss(r));
    // nach dem Defizit der Stunde verteilt (nachts mehr, in der Sonne weniger)
    const wts = T.map((t, h) => Math.max(0.05, setC - t - 0.5 * H.solar_aperture_m2 * rad[90][h] / H.ua_kw_per_k));
    const wsum = sum(wts);
    const heatH = wts.map(x => heat * x / wsum);
    // Warmwasser mittags (11–13 Uhr)
    const cold = 10 - 4 * Math.cos(2 * Math.PI * (doy - 60) / 365.25);
    const md = d.slice(5);
    const guests = md >= '12-23' || md <= '01-02';
    let dhw = H.dhw_persons * H.dhw_l_per_person * 1.163e-3 * (H.dhw_c - cold) * Math.max(0.5, 1 + 0.1 * gauss(r)) * (guests ? 1.3 : 1) + H.dhw_loss_kwh;
    if (vac) dhw = 0.6 * H.dhw_loss_kwh;
    // Wärmequelle
    const brine = H.source === 'brine' ? H.brine_mean + H.brine_amp * Math.cos(2 * Math.PI * (doy - H.brine_peak_doy) / 365.25) - H.brine_load_k * Math.min(1, heat / 55) : null;
    const flow = flowTemp(H, w.avg);
    const wpH = new Array(24).fill(0);
    let rod = 0, heatOut = 0;
    for (let h = 0; h < 24; h++) {
      const evap = (brine ?? T[h]) - H.evap_approach_k;
      let q = heatH[h], rodQ = 0;
      if (H.rod && T[h] < H.rod.below) { rodQ = q * Math.min(H.rod.max, H.rod.per_k * (H.rod.below - T[h])); q -= rodQ; }
      let e = q / cop(H, flow + H.cond_approach_k, evap, T[h]) + rodQ;
      if (h === 11 || h === 12) e += (dhw / 2) / cop(H, H.dhw_c + H.cond_approach_k, evap, T[h]);
      e += H.standby_w / 1000;
      wpH[h] = e; rod += rodQ; heatOut += heatH[h] + (h === 11 || h === 12 ? dhw / 2 : 0);
    }
    // Haushalt
    const lh = lightHours(d);
    const noiseDay = Math.max(0.6, 1 + 0.08 * gauss(r));
    const houseH = HOUSE_SHAPE.map((s, h) => houseScale * noiseDay * (houseDayFactor(d) * s * baseDay / 24 + H.light_kw * lh[h]));
    // PV (Zelltemperatur, Schnee)
    const pvH = new Array(24).fill(0);
    if (hasPv) {
      const snow = w.max < 0 && prevMaxBelow;
      for (let h = 0; h < 24; h++) {
        const g = rad[H.pv.tilt][h];
        const cell = T[h] + H.pv.cell_k_per_kw * g;
        pvH[h] = Math.max(0, H.pv.kwp * g * H.pv.pr * (1 + H.pv.temp_coeff * (cell - 25))) * (snow ? 0.3 : 1);
      }
    }
    prevMaxBelow = w.max < 0;

    // Auto: Fahrten, Laden mittags bei Überschuss, sonst nachts
    const evH = new Array(24).fill(0), evPvH = new Array(24).fill(0);
    if (evActive(d)) {
      const E = H.ev;
      const wk = weekday(d);
      const office = wk >= 2 && wk <= 4;
      let km = 0;
      if (!vac) {
        if (office) km = 38;
        else if (wk === 1 || wk === 5) km = 6;
        else if (wk === 6) km = 22;
        else km = rEv() < 0.4 ? 0 : 40 + 100 * rEv();
      }
      const perKm = E.kwh_per_km_20c * (1 + E.cold_plus_at_minus5 * clamp((20 - w.avg) / 25, 0, 1.4));
      const home = (h) => !vac && !(office && h >= 7 && h < 18) && !(wk === 6 && h >= 9 && h < 12) && !(wk === 0 && km > 0 && h >= 13 && h < 18);
      if (vac) { ev.soc = 0.35 * E.battery_kwh; if (ev.session) closeSession(ev, d, 6, sessions); }
      for (let h = 0; h < 24; h++) {
        // Abfahrt bzw. Abstecken am Morgen beendet eine Nachtladung, Abfahrt auch eine mittags
        if (ev.session && (!home(h) || (ev.session.kind === 'night' && h === 7))) closeSession(ev, d, h, sessions);
        if (h === 6) ev.plan = null;
        if (!home(h)) continue;
        const surplus = pvH[h] - houseH[h];
        let p = 0, pv = 0;
        if (h >= 10 && h < 16 && surplus >= E.min_pv_kw && ev.soc < 0.9 * E.battery_kwh) {
          p = Math.min(E.kw, surplus, (0.9 * E.battery_kwh - ev.soc) / (1 - E.loss));
          pv = p;
          openSession(ev, d, h, 'midday', rEv);
        } else if ((h >= 22 || h < 6) && ev.plan === 'night' && ev.soc < 0.8 * E.battery_kwh) {
          p = Math.min(E.kw, (0.8 * E.battery_kwh - ev.soc) / (1 - E.loss));
          openSession(ev, d, h, 'night', rEv);
        } else if (ev.session?.kind === 'midday' && h >= 16) {
          closeSession(ev, d, h, sessions);
        }
        if (p > 0) { ev.soc += p * (1 - E.loss); evH[h] = p; evPvH[h] = pv; ev.session.kwh += p; ev.session.pv += pv; ev.session.cost += pv * P.prices.feed.ct / 100 + (p - pv) * (isHt(h) ? P.prices.house.ht : P.prices.house.nt) / 100; }
        if (h === 21) ev.plan = ev.soc < 0.45 * E.battery_kwh ? 'night' : null;
      }
      ev.soc = Math.max(0.05 * E.battery_kwh, ev.soc - km * perKm);
    }

    // Speicher hinter Z2: lädt aus Überschuss, deckt nur den Haushalt (nicht das Auto)
    const chH = new Array(24).fill(0), disH = new Array(24).fill(0);
    const z1 = { imp_ht: 0, imp_nt: 0, exp: 0 }, z2 = { imp_ht: 0, imp_nt: 0, exp: 0 };
    let curtailed = 0;
    for (let h = 0; h < 24; h++) {
      let netPre = houseH[h] + evH[h] - pvH[h];
      if (H.battery && d >= H.battery.from) {
        const B = H.battery, lo = (B.kwh - B.usable) / 2, hi = B.kwh - lo;
        if (netPre < 0) { const c = Math.min(-netPre, B.kw, (hi - soc) / B.eta); if (c > 0) { chH[h] = c; soc += c * B.eta; } }
        else { const need = Math.max(0, houseH[h] - pvH[h]); const x = Math.min(need, B.kw, (soc - lo) * B.eta); if (x > 0) { disH[h] = x; soc -= x / B.eta; } }
      }
      let n2 = houseH[h] + evH[h] + chH[h] - pvH[h] - disH[h];
      let n1 = n2 + wpH[h];
      if (hasPv) {
        const limit = H.pv.feed_limit_share * H.pv.kwp;
        if (-n1 > limit) { const x = -n1 - limit; pvH[h] -= x; curtailed += x; n2 += x; n1 += x; }
      }
      const ht = isHt(h);
      if (n1 > 0) z1[ht ? 'imp_ht' : 'imp_nt'] += n1; else z1.exp += -n1;
      if (n2 > 0) z2[ht ? 'imp_ht' : 'imp_nt'] += n2; else z2.exp += -n2;
      if (hasPv) {
        (hourly.z2_ht ??= []).push(n2 > 0 && ht ? n2 : 0);
        (hourly.z2_nt ??= []).push(n2 > 0 && !ht ? n2 : 0);
      }
    }
    put('t_avg', i, w.avg);
    put('heat_space_kwh', i, heat);
    put('heat_dhw_kwh', i, dhw);
    put('wp_kwh', i, sum(wpH));
    put('wp_rod_kwh', i, rod);
    put('house_kwh', i, sum(houseH));
    if (hasPv) {
      put('ev_kwh', i, sum(evH));
      put('ev_pv_kwh', i, sum(evPvH));
      put('pv_kwh', i, sum(pvH));
      put('pv_curtailed_kwh', i, curtailed);
      put('bat_charge_kwh', i, sum(chH));
      put('bat_discharge_kwh', i, sum(disH));
      put('bat_soc_kwh', i, soc);
      put('z1_imp_ht', i, z1.imp_ht); put('z1_imp_nt', i, z1.imp_nt); put('z1_exp', i, z1.exp);
      put('z2_imp_ht', i, z2.imp_ht); put('z2_imp_nt', i, z2.imp_nt); put('z2_exp', i, z2.exp);
      put('rest_ht', i, z1.imp_ht - z2.imp_ht); put('rest_nt', i, z1.imp_nt - z2.imp_nt);
    } else {
      // Haushalt B: ein Zähler für Haushalt und Wärmepumpe (ein Zählwerk)
      put('imp_kwh', i, sum(houseH) + sum(wpH));
    }
  }
  return { days: daysList, series, hourly, sessions };
}

// Ladevorgänge wie evcc sie führt (Zeiten in Ortszeit mit Versatz)
const dstUtc = (y, m) => { const x = new Date(Date.UTC(y, m, 0)); x.setUTCDate(x.getUTCDate() - x.getUTCDay()); x.setUTCHours(1); return x.getTime() / 1000; };
function localStamp(d, minutesCet) {
  const ts = Date.UTC(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10)) / 1000 - 3600 + minutesCet * 60;
  const y = new Date(ts * 1000).getUTCFullYear();
  const off = ts >= dstUtc(y, 3) && ts < dstUtc(y, 10) ? 7200 : 3600;
  const l = new Date((ts + off) * 1000).toISOString().slice(0, 19);
  return { stamp: `${l}${off === 7200 ? '+02:00' : '+01:00'}`, day: l.slice(0, 10) };
}
function openSession(ev, d, h, kind, r) {
  if (ev.session && ev.session.kind === kind) return;
  if (ev.session) return;
  const plugMin = kind === 'midday' ? h * 60 + Math.floor(5 + 40 * r()) - 60 : 18 * 60 + Math.floor(90 * r());
  ev.session = { kind, startDay: kind === 'night' && h < 6 ? addDays(d, -1) : d, plugMin: Math.max(0, plugMin), kwh: 0, pv: 0, cost: 0, meterStart: ev.meter };
}
function closeSession(ev, d, h, sessions) {
  const s = ev.session;
  ev.session = null;
  if (!s || s.kwh <= 0) return;
  const created = localStamp(s.startDay, s.plugMin);
  const endMin = s.kind === 'midday' ? Math.max(h * 60, 16 * 60 + 30) : 6 * 60 + 45;
  const finished = localStamp(d, endMin);
  ev.meter += s.kwh;
  sessions.push({
    id: 'evs_' + created.stamp.replace(/[^0-9]/g, '').slice(0, 12), meter_id: 'm_strom_wallbox', created: created.stamp, finished: finished.stamp,
    date: finished.day, loadpoint: 'Wallbox', vehicle: '', charged_kwh: round(s.kwh, 3), solar_pct: round(100 * s.pv / s.kwh, 1),
    price_eur: round(s.cost, 2), price_per_kwh: round(s.cost / s.kwh, 4), meter_start: round(s.meterStart, 3), meter_stop: round(s.meterStart + s.kwh, 3), source: 'evcc',
  });
}

const SIM = { a: simulate('a'), b: simulate('b') };

// ── Zähler, Verträge, Ablesungen ─────────────────────────────────────────
const device = (id, installed, initial) => ({ id, serial: null, installed_on: installed, initial_counter: initial, removed_on: null, final_counter: null, reason: null });
const meter = (id, name, icon, installed, initial, extra = {}) => ({
  id, name, icon, created_at: installed, active: true, notes: '', parent_meter_id: null, meter_group_id: null, external_id: null,
  baseline_events: [], devices: [device('d_' + id.slice(2), installed, initial)], ...extra,
});
const contract = (id, target, pr, start, o) => ({
  id, meter_id: target.meter_id ?? null, provider: pr.provider, tariff_name: pr.tariff, start, end: null, notes: '',
  bonuses: [], is_shadow: false, shadow_label: null, notice_period_months: 1, min_term_end: null, price_guarantee_until: null,
  auto_renews: true, notice_period_days: null, notice_mode: null, signup_bonus_eur: null,
  working_prices: o.wp, base_prices: o.bp ?? [], advance_payments: o.ap ?? [], special_payments: [],
  ...(target.meter_group_id ? { meter_group_id: target.meter_group_id } : {}),
  ...(o.byMeter ? { working_prices_by_meter: o.byMeter } : {}),
});

/** Abrechnungsjahre ab dem ersten Stichtag nach `from` (MM-TT), mit Teilperiode am Anfang. */
function billingYears(from, to, anchor) {
  const out = [];
  let start = from;
  let next = `${from.slice(0, 4)}-${anchor}`;
  if (next <= from) next = addMonths(next, 12);
  while (start <= to) {
    out.push({ from: start, until: addDays(next, -1), complete: start === addMonths(next, -12) });
    start = next; next = addMonths(next, 12);
  }
  return out;
}
const dayIndex = (sim, d) => Math.round((toDate(d) - toDate(sim.days[0])) / 86400000);
const sumSeries = (sim, name, from, untilIncl) => {
  const s = sim.series[name]; let t = 0;
  const a = Math.max(0, dayIndex(sim, from)), b = Math.min(s.length - 1, dayIndex(sim, untilIncl));
  for (let i = a; i <= b; i++) t += s[i];
  return t;
};
const roundTo5 = (x) => Math.max(5, Math.round(x / 5) * 5);

/**
 * Wahre Rechnung je Abrechnungsjahr; Abschlag des Folgejahrs = Kosten je Monat (auf 5 € gerundet),
 * nach einer Teilperiode unter sechs Monaten bleibt die Schätzung des Versorgers.
 */
function bills(sim, cid, energy, pr, anchor, firstAdvance, kind) {
  const out = [], advances = [{ from: sim.days[0], amount_eur: firstAdvance }];
  let adv = firstAdvance;
  for (const by of billingYears(sim.days[0], P.data_to, anchor)) {
    if (by.until > P.data_to) break;
    const months = (+by.until.slice(0, 4) - +by.from.slice(0, 4)) * 12 + (+by.until.slice(5, 7) - +by.from.slice(5, 7)) + 1;
    const e = energy(by.from, by.until);
    let cost, energyEur, baseEur = 0;
    if (kind === 'feed_in') { energyEur = e.kwh * pr.ct / 100; cost = energyEur; }
    else { energyEur = (e.ht * pr.ht + e.nt * pr.nt) / 100; baseEur = pr.base * months; cost = energyEur + baseEur; }
    const paid = kind === 'feed_in' ? 0 : adv * months;
    out.push({ contract_id: cid, from: by.from, until: by.until, complete: by.complete, months,
      kwh: round(e.kwh, 1), kwh_ht: e.ht !== undefined ? round(e.ht, 1) : null, kwh_nt: e.nt !== undefined ? round(e.nt, 1) : null,
      energy_eur: round(energyEur, 2), base_eur: round(baseEur, 2), cost_eur: round(cost, 2), advance_eur: kind === 'feed_in' ? null : adv,
      advances_paid_eur: round(paid, 2), result_eur: round(cost - paid, 2) });
    if (kind !== 'feed_in' && months >= 6) {
      adv = roundTo5(cost / months);
      advances.push({ from: addDays(by.until, 1), amount_eur: adv });
    }
  }
  return { bills: out, advances: kind === 'feed_in' ? [] : advances };
}

// Ablesetage
function monthlyDates(from, seed, gapYm, estYm) {
  const r = rng(seed);
  const out = [{ date: from, est: false }];
  for (let m = addMonths(firstOfMonth(from), 1); m <= P.data_to; m = addMonths(m, 1)) {
    if (m.slice(0, 7) === gapYm) { r(); continue; }
    const d = addDays(m, Math.floor(r() * 13) - 6);
    if (d > P.data_to) break;
    out.push({ date: d, est: m.slice(0, 7) === estYm });
  }
  if (out.at(-1).date < P.data_to) out.push({ date: P.data_to, est: false });
  return out;
}
const READING_DATES = {
  a: {
    V1: monthlyDates(P.a.from, 4101, '2024-02', '2025-02'),
    V2: ['2022-07-01', '2022-10-03', '2023-01-07', '2023-04-04', '2023-07-02', '2023-10-05', '2024-01-04', '2024-10-02', '2025-10-01', P.data_to].map(date => ({ date, est: false })),
    V3: [...days(P.a.from, P.data_to)].map(date => ({ date, est: false })),
  },
  b: {
    V1: monthlyDates(P.b.from, 4102, '2024-12', '2025-01'),
    V2: ['2023-07-01', '2023-10-04', '2024-01-03', '2024-04-02', '2024-07-03', '2025-04-02', '2026-04-01', P.data_to].map(date => ({ date, est: false })),
    V3: [...days(P.b.from, P.data_to)].map(date => ({ date, est: false })),
  },
};

/** Stand am Morgen eines Tages (Summe aller Tage davor). */
function counterAt(sim, name, start, installed, date) {
  if (date <= installed) return start;
  return start + sumSeries(sim, name, installed, addDays(date, -1));
}
function readingsFor(sim, m, series, start, dates, decimals) {
  const installed = m.devices[0].installed_on;
  const list = [{ date: installed, est: false }, ...dates.filter(x => x.date > installed)];
  const out = [];
  let prev = null;
  for (const [k, x] of list.entries()) {
    let c = counterAt(sim, series, start, installed, x.date);
    if (x.est && prev && out.length >= 2) {
      // Karte nicht zur Hand: Stand aus der Tagesrate des vorigen Abstands geschätzt
      const a = out.at(-2), b = out.at(-1);
      const span = (toDate(b.date) - toDate(a.date)) / 86400000;
      const rate = span > 0 ? (b.counter - a.counter) / span : 0;
      c = b.counter + rate * (toDate(x.date) - toDate(b.date)) / 86400000;
    }
    out.push({
      id: `r_${m.id.slice(2)}_${x.date.replaceAll('-', '')}`, meter_id: m.id, device_id: m.devices[0].id, date: x.date,
      counter: round(c, decimals), price_cents: null, note: x.est ? 'geschätzt' : '', is_estimated: !!x.est, is_future: false,
    });
    prev = x;
  }
  return out;
}

// ── Haushalt A ───────────────────────────────────────────────────────────
const A = SIM.a;
const A_START = {
  m_strom_z1_ht: 21433.6, m_strom_z1_nt: 9872.1, m_strom_z2_ht: 14210.4, m_strom_z2_nt: 5012.9, m_strom_wallbox: 0,
  m_pv_einspeisung_z1: 6804.2, m_pv_einspeisung_z2: 9902.7, m_pv_erzeugung_z3: 14876.0, m_pv_erzeugung_bat_in: 0, m_pv_erzeugung_bat_out: 0,
};
const A_SERIES = {
  m_strom_z1_ht: 'z1_imp_ht', m_strom_z1_nt: 'z1_imp_nt', m_strom_z2_ht: 'z2_imp_ht', m_strom_z2_nt: 'z2_imp_nt', m_strom_wallbox: 'ev_kwh',
  m_pv_einspeisung_z1: 'z1_exp', m_pv_einspeisung_z2: 'z2_exp', m_pv_erzeugung_z3: 'pv_kwh', m_pv_erzeugung_bat_in: 'bat_charge_kwh', m_pv_erzeugung_bat_out: 'bat_discharge_kwh',
};
const A_METERS = {
  strom: [
    meter('m_strom_z1_ht', 'Zähler vorn (Wärmepumpe) HT', '⚡', P.a.from, A_START.m_strom_z1_ht, { role: 'heat_pump', heat_source: true, obis: '1.8.1', meter_group_id: 'g_strom_z1' }),
    meter('m_strom_z1_nt', 'Zähler vorn (Wärmepumpe) NT', '⚡', P.a.from, A_START.m_strom_z1_nt, { role: 'heat_pump', heat_source: true, obis: '1.8.2', meter_group_id: 'g_strom_z1' }),
    meter('m_strom_z2_ht', 'Haushalt HT', '⚡', P.a.from, A_START.m_strom_z2_ht, { obis: '1.8.1', parent_meter_id: 'm_strom_z1_ht', meter_group_id: 'g_strom_z2' }),
    meter('m_strom_z2_nt', 'Haushalt NT', '⚡', P.a.from, A_START.m_strom_z2_nt, { obis: '1.8.2', parent_meter_id: 'm_strom_z1_nt', meter_group_id: 'g_strom_z2' }),
    // hinter Z2; als Subzähler am vorderen Zähler, weil ein Subzähler (Z2) keine Subzähler haben darf
    meter('m_strom_wallbox', 'Wallbox', '🔌', P.a.ev.from, A_START.m_strom_wallbox, { role: 'ev_charger', parent_meter_id: 'm_strom_z1_ht' }),
  ],
  pv_einspeisung: [
    meter('m_pv_einspeisung_z1', 'Einspeisung', '☀️', P.a.from, A_START.m_pv_einspeisung_z1, { obis: '2.8.0' }),
    meter('m_pv_einspeisung_z2', 'Einspeisung Haushalt', '☀️', P.a.from, A_START.m_pv_einspeisung_z2, { obis: '2.8.0', cascade_meter_id: 'm_strom_z2_ht' }),
  ],
  pv_erzeugung: [
    meter('m_pv_erzeugung_z3', 'PV-Erzeugung', '🔆', P.a.from, A_START.m_pv_erzeugung_z3, { obis: '2.8.0' }),
    meter('m_pv_erzeugung_bat_in', 'Speicher Ladung', '🔆', P.a.battery.from, 0, { role: 'battery_charge' }),
    meter('m_pv_erzeugung_bat_out', 'Speicher Entladung', '🔆', P.a.battery.from, 0, { role: 'battery_discharge' }),
  ],
};
const A_GROUPS = [{ id: 'g_strom_z1', name: 'Zähler vorn (Wärmepumpe)', created_at: P.a.from }, { id: 'g_strom_z2', name: 'Haushalt', created_at: P.a.from }];
const htnt = (sim, ht, nt) => (from, until) => { const h = sumSeries(sim, ht, from, until), n = sumSeries(sim, nt, from, until); return { ht: h, nt: n, kwh: h + n }; };
const A_BILLS = {
  wp: bills(A, 'c_strom_wp', htnt(A, 'rest_ht', 'rest_nt'), P.prices.wp, P.a.billing_anchor, P.prices.wp.first_advance, 'consumption'),
  house: bills(A, 'c_strom_haus', htnt(A, 'z2_imp_ht', 'z2_imp_nt'), P.prices.house, P.a.billing_anchor, P.prices.house.first_advance, 'consumption'),
  feed: bills(A, 'c_pv_einspeisung_z1', (f, u) => ({ kwh: sumSeries(A, 'z1_exp', f, u) }), P.prices.feed, P.a.feed_anchor, 0, 'feed_in'),
};
const A_CONTRACTS = {
  strom: [
    contract('c_strom_wp', { meter_group_id: 'g_strom_z1' }, P.prices.wp, P.a.from, {
      wp: [{ from: P.a.from, ct_per_kwh: P.prices.wp.ht }], bp: [{ from: P.a.from, eur_per_month: P.prices.wp.base }], ap: A_BILLS.wp.advances,
      byMeter: { m_strom_z1_nt: [{ from: P.a.from, ct_per_kwh: P.prices.wp.nt }] } }),
    contract('c_strom_haus', { meter_group_id: 'g_strom_z2' }, P.prices.house, P.a.from, {
      wp: [{ from: P.a.from, ct_per_kwh: P.prices.house.ht }], bp: [{ from: P.a.from, eur_per_month: P.prices.house.base }], ap: A_BILLS.house.advances,
      byMeter: { m_strom_z2_nt: [{ from: P.a.from, ct_per_kwh: P.prices.house.nt }] } }),
  ],
  pv_einspeisung: [
    contract('c_pv_einspeisung_z1', { meter_id: 'm_pv_einspeisung_z1' }, { provider: P.prices.feed.provider, tariff: P.prices.feed.tariff }, P.a.from, {
      wp: [{ from: P.a.from, ct_per_kwh: P.prices.feed.ct }] }),
  ],
};

// ── Haushalt B ───────────────────────────────────────────────────────────
const B = SIM.b;
const B_METERS = { strom: [meter('m_strom_b', 'Haushalt und Wärmepumpe', '⚡', P.b.from, 37.5, { obis: '1.8.0' })] };
const B_BILLS = {
  main: bills(B, 'c_strom_b', (f, u) => { const k = sumSeries(B, 'imp_kwh', f, u); return { ht: k, nt: 0, kwh: k }; },
    { ht: P.prices.b.ct, nt: 0, base: P.prices.b.base }, P.b.billing_anchor, P.prices.b.first_advance, 'consumption'),
};
const B_CONTRACTS = {
  strom: [contract('c_strom_b', { meter_id: 'm_strom_b' }, { provider: P.prices.b.provider, tariff: P.prices.b.tariff }, P.b.from, {
    wp: [{ from: P.b.from, ct_per_kwh: P.prices.b.ct }], bp: [{ from: P.b.from, eur_per_month: P.prices.b.base }], ap: B_BILLS.main.advances })],
};

// ── Viertelstunden (V3, Haushalt A, Z2 HT und NT, Kalenderjahr 2024) ─────
const QH_YEAR = 2024;
function intervals(sim) {
  const start = Date.UTC(QH_YEAR - 1, 11, 31, 23) / 1000;   // 1.1. 00:00 MEZ
  const n = (Date.UTC(QH_YEAR, 11, 31, 23) / 1000 - start) / 900;
  const first = Date.UTC(+sim.days[0].slice(0, 4), +sim.days[0].slice(5, 7) - 1, +sim.days[0].slice(8, 10)) / 1000 - 3600;
  const isoAt = (ts) => { const y = new Date(ts * 1000).getUTCFullYear(); const off = ts >= dstUtc(y, 3) && ts < dstUtc(y, 10) ? 7200 : 3600;
    return new Date((ts + off) * 1000).toISOString().slice(0, 19) + (off === 7200 ? '+02:00' : '+01:00'); };
  const series = [], files = {};
  for (const [reg, mid] of [['z2_ht', 'm_strom_z2_ht'], ['z2_nt', 'm_strom_z2_nt']]) {
    const values = [];
    for (let i = 0; i < n; i++) {
      const hourIdx = Math.floor((start + i * 900 - first) / 3600);   // MEZ-Stunde seit dem ersten Tag
      values.push(round((sim.hourly[reg][hourIdx] ?? 0) / 4, 4));
    }
    const id = `e-strom-${mid}-${QH_YEAR}`.replace(/[^A-Za-z0-9_.-]/g, '-').toLowerCase();
    files[id] = { id, kind: 'energy', year: QH_YEAR, step: 900, start: isoAt(start), values };
    series.push({ id, kind: 'energy', utility: 'strom', meter_id: mid, unit: 'kWh', year: QH_YEAR, step: 900, slots: n, filled: n,
      from: isoAt(start), to: isoAt(start + n * 900), source: 'import', updated_at: '2025-01-02T03:00:00+01:00' });
  }
  return { index: { series, samples: {} }, files };
}

// ── Backups ──────────────────────────────────────────────────────────────
const UTILITIES = ['gas', 'strom', 'wasser', 'fernwaerme', 'heizoel', 'pellets', 'pv_einspeisung', 'pv_erzeugung', 'waerme'];
const POTS = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups', 'periods', 'bills'];
const temperaturesFrom = (from) => {
  const out = {};
  for (const d of days(addDays(from, -31), P.data_to)) { const w = weather[d]; out[d] = { avg: w.avg, min: w.min, max: w.max, rad: w.rad }; }
  return out;
};
function settingsFor(key) {
  const base = {
    location_name: P.location.name, latitude: P.location.lat, longitude: P.location.lon, country: 'DE', timezone: 'Europe/Berlin',
    waerme_energietraeger: 'strom',
  };
  return key === 'a'
    ? { ...base, active_utilities: ['strom', 'pv_einspeisung', 'pv_erzeugung'], billing_cycle_anchor_strom: P.a.billing_anchor, billing_cycle_anchor_pv_einspeisung: P.a.feed_anchor }
    : { ...base, active_utilities: ['strom'], billing_cycle_anchor_strom: P.b.billing_anchor };
}
function backup(key, variant) {
  const sim = SIM[key];
  const metersBy = key === 'a' ? A_METERS : B_METERS;
  const decimals = variant === 'V3' ? 2 : 1;
  const utilities = {};
  for (const u of UTILITIES) { utilities[u] = {}; for (const pot of POTS) utilities[u][pot] = []; }
  for (const [u, list] of Object.entries(metersBy)) {
    utilities[u].meters = list;
    utilities[u].readings = list.flatMap(m => readingsFor(sim, m, key === 'a' ? A_SERIES[m.id] : 'imp_kwh', m.devices[0].initial_counter, READING_DATES[key][variant], decimals));
  }
  for (const [u, list] of Object.entries(key === 'a' ? A_CONTRACTS : B_CONTRACTS)) utilities[u].contracts = list;
  if (key === 'a') utilities.strom.meter_groups = A_GROUPS;
  const qh = key === 'a' && variant === 'V3' ? intervals(sim) : { index: { series: [], samples: {} }, files: {} };
  return {
    _comment: `Erzeugt von tests/fixtures/heatpump-household/generate.mjs (${key.toUpperCase()}/${variant}), nicht von Hand ändern. Eigene Physik statt des App-Modells; alles erfunden.`,
    backup_version: '3.0', app_version: '3.12.0', exported_at: '2026-07-01T08:00:00+02:00',
    meta: { schema_version: '1.8.0', migrated_at: '2026-07-01T08:00:00+02:00', log: [{ step: 'fixture-seed', fixture: 'heatpump-household', household: key.toUpperCase(), variant }] },
    temperatures: temperaturesFrom(sim.days[0]),
    settings: settingsFor(key),
    reminders: [], recommendations_dismissed: [], attachments: [], tenancies: [], tenancy_statements: [], market_prices: [],
    ev_sessions: key === 'a' ? sim.sessions : [],
    intervals: qh.index, interval_files: qh.files,
    activity: [], people: [], inbox: [],
    utilities,
  };
}

// ── Wahrheit ─────────────────────────────────────────────────────────────
function yearSummary(sim, key) {
  const out = [];
  for (let start = sim.days[0]; start <= P.data_to; start = addMonths(start, 12)) {
    const until = addDays(addMonths(start, 12), -1);
    if (until > P.data_to) break;
    const s = (n) => (sim.series[n] ? round(sumSeries(sim, n, start, until), 1) : null);
    const heat = sumSeries(sim, 'heat_space_kwh', start, until) + sumSeries(sim, 'heat_dhw_kwh', start, until);
    const row = { from: start, until, heat_space_kwh: s('heat_space_kwh'), heat_dhw_kwh: s('heat_dhw_kwh'), wp_kwh: s('wp_kwh'),
      wp_rod_kwh: s('wp_rod_kwh'), jaz: round(heat / sumSeries(sim, 'wp_kwh', start, until), 2), house_kwh: s('house_kwh') };
    if (key === 'a') Object.assign(row, { ev_kwh: s('ev_kwh'), pv_kwh: s('pv_kwh'), pv_curtailed_kwh: s('pv_curtailed_kwh'), bat_charge_kwh: s('bat_charge_kwh'),
      bat_discharge_kwh: s('bat_discharge_kwh'), z1_imp_kwh: round(sumSeries(sim, 'z1_imp_ht', start, until) + sumSeries(sim, 'z1_imp_nt', start, until), 1),
      z1_exp_kwh: s('z1_exp'), z2_imp_kwh: round(sumSeries(sim, 'z2_imp_ht', start, until) + sumSeries(sim, 'z2_imp_nt', start, until), 1), z2_exp_kwh: s('z2_exp'),
      rest_kwh: round(sumSeries(sim, 'rest_ht', start, until) + sumSeries(sim, 'rest_nt', start, until), 1) });
    else row.imp_kwh = s('imp_kwh');
    out.push(row);
  }
  return out;
}
/**
 * Heizgrenze, die sich ergibt (nur zur Beschreibung, die Physik setzt keine): das Tagesmittel, ab dem
 * der mittlere Raumwärmebedarf (Klassen zu 1 K, mindestens zehn Tage) unter 10 % des Bedarfs bei
 * 0 °C (−1 bis + 1 °C) fällt. Die Trägheit des Hauses verschmiert die Grenze: Ein warmer Tag nach
 * kalten Tagen braucht noch Wärme.
 */
function emergentHeatLimit(sim) {
  const bins = {}, ref = [];
  sim.days.forEach((d, i) => {
    const t = sim.series.t_avg[i], q = sim.series.heat_space_kwh[i];
    (bins[Math.floor(t)] ??= []).push(q);
    if (t >= -1 && t <= 1) ref.push(q);
  });
  const at0 = sum(ref) / ref.length;
  const keys = Object.keys(bins).map(Number).filter(k => bins[k].length >= 10).sort((a, b) => a - b);
  let limit = null;
  for (let j = keys.length - 1; j >= 0; j--) {
    if (sum(bins[keys[j]]) / bins[keys[j]].length >= 0.10 * at0) { limit = keys[j] + 1; break; }
  }
  return { limit_c: limit, heat_at_0c_kwh_per_day: round(at0, 1), rule: 'Tagesmittel, ab dem der Raumwärmebedarf unter 10 % des Bedarfs bei 0 °C fällt' };
}
function truth(key) {
  const sim = SIM[key];
  const daily = {};
  for (const [n, v] of Object.entries(sim.series)) daily[n] = v.map(x => round(x, n === 't_avg' ? 1 : 4));
  const layout = key === 'a'
    ? { strom: { z1_group: 'g_strom_z1', z1_ht: 'm_strom_z1_ht', z1_nt: 'm_strom_z1_nt', z2_group: 'g_strom_z2', z2_ht: 'm_strom_z2_ht', z2_nt: 'm_strom_z2_nt', wallbox: 'm_strom_wallbox' },
        pv_einspeisung: { feed_z1: 'm_pv_einspeisung_z1', feed_z2: 'm_pv_einspeisung_z2' },
        pv_erzeugung: { gen: 'm_pv_erzeugung_z3', bat_in: 'm_pv_erzeugung_bat_in', bat_out: 'm_pv_erzeugung_bat_out' },
        contracts: { wp: 'c_strom_wp', house: 'c_strom_haus', feed: 'c_pv_einspeisung_z1' },
        meter_series: A_SERIES, battery_from: P.a.battery.from, wallbox_from: P.a.ev.from }
    : { strom: { main: 'm_strom_b' }, contracts: { main: 'c_strom_b' }, meter_series: { m_strom_b: 'imp_kwh' } };
  return {
    _comment: `Wahrheit zu Haushalt ${key.toUpperCase()} (tests/fixtures/heatpump-household/generate.mjs). Tageswerte in kWh je MEZ-Tag; verglichen wird mit ihr, nicht mit Ablesungen.`,
    household: key.toUpperCase(), name: P[key].name,
    daily: { from: sim.days[0], to: P.data_to, series: daily },
    bills: Object.fromEntries(Object.entries(key === 'a' ? A_BILLS : B_BILLS).map(([k, v]) => [k, v.bills])),
    years: yearSummary(sim, key),
    heat_limit: emergentHeatLimit(sim),
    layout,
  };
}

// ── Schwellen (Paketplan v3.13.0, vor der Arbeit am Modell festgelegt, maschinenlesbar) ──
const SCHWELLEN = {
  _comment: 'Schwellen aus dem Paketplan v3.13.0 (2026-11-12), vorab festgelegt; geprüft von den Fachpaketen mit tools/rueckrechnung.php --baseline. rel = Faktor gegen den alten Wert, abs = feste Grenze. Entscheidungen des Maintainers vom 2026-11-12: judge_from, households, feed_saldo_mean, measures_confirmed.',
  // Entscheidung 2026-11-12 (1): Wärmepumpe, Saldo und Einspeisung werden erst an Schnitten geurteilt, an denen
  // alle Geräte da sind (Speicher seit 07/2023, Wallbox seit 06/2024). Frühere Schnitte rechnet und zeigt das Werkzeug weiter.
  judge_from_default: '2024-07',
  // Entscheidung 2026-11-12 (2): Haushalt B zeigt das Wetter kaum (rund 15 % des Zählers); für ihn gelten nur die festen
  // Schwellen, eine relative Zeile wird dort zu „nicht schlechter als alt“ (Faktor 1).
  households: { B: { relative: 'not_worse', factor: 1.0, text: 'B: nur feste Schwellen; relative Zeilen als „nicht schlechter als alt“' } },
  // Entscheidung 2026-11-12 (4): die Messregeln unten sind bestätigt
  measures_confirmed: { on: '2026-11-12', by: 'Maintainer', ids: ['annual_err_pct', 'max_month_err_pct', 'energy_gap_kwh'],
    text: '„± x %“ = Mittel der Beträge über die Schnitte, der schlechteste Schnitt daneben; größter Monatsfehler nur über Monate mit mindestens 3 % der Jahresmenge; HT/NT je Monat' },
  measures: {
    annual_err_pct: 'Jahresfehler: (Summe Prognose − Summe Wahrheit) / Summe Wahrheit über die zwölf Monate ab dem Schnitt, in %; stat mean_abs = Mittel der Beträge über die Schnitte, der schlechteste Schnitt steht daneben (bestätigt 2026-11-12)',
    wape_pct: 'WAPE: Summe |Prognose − Wahrheit| der Monate / Summe Wahrheit, in %; Mittel über die Schnitte',
    max_month_err_pct: 'größter Monatsfehler |Prognose − Wahrheit| / Wahrheit über alle Schnitte, nur Monate mit mindestens 3 % der Jahreswahrheit (der Sommer einer Wärmepumpe hinter PV ist fast null; bestätigt 2026-11-12)',
    band_month_hit_pct: 'Anteil der Monate mit Band, deren Wahrheit im Band liegt, über alle Schnitte',
    band_year_hit: 'Jahresband enthält die Jahreswahrheit (nur Schnitte, deren Prognose im Monat des Schnitts beginnt); fehlt das Band, gilt es als nicht erfüllt',
    abs_err_eur: '|„Erwartet bei Abrechnung“ − wahres Rechnungsergebnis| nach 3, 6 und 9 Monaten des Abrechnungsjahrs (Bezugsverträge)',
    sign_ok: 'Vorzeichen (Nachzahlung/Guthaben) richtig, nur Fälle mit |Ergebnis| über 5 % der Jahreskosten',
    winter_wape_pct: 'WAPE der Monate November bis März des Wallbox-Zählers',
    energy_gap_kwh: 'größte Abweichung eines Monats |Gruppe − (HT + NT)| der Prognose (je Monat, bestätigt 2026-11-12)',
    feed_abs_err_eur: '|„Erwartet bei Abrechnung“ der Einspeisung − wahre Vergütung des Abrechnungsjahrs| nach 3, 6 und 9 Monaten',
    base_gap_eur: '|Kosten der Gruppe − Kosten der Zählwerke − 12 × Grundpreis| über die zwölf Prognosemonate',
    report_gap_eur: '|Jahresbericht „Erwartete Kosten“ (Strom) − Kosten der Gruppenprognosen|',
  },
  cut_sets: {
    after_mild: 'Schnitte April bis September nach dem milden Winter 2023/24 (Fenster mit dem kalten Winter 2024/25)',
    after_cold: 'Schnitte April bis Juni nach dem kalten Winter 2024/25 (Fenster mit dem Winter 2025/26)',
    mild_year: 'Fenster, die den milden Winter 2023/24 ganz enthalten (Nov. bis März)',
    cold_year: 'Fenster, die den kalten Winter 2024/25 ganz enthalten (Nov. bis März)',
    battery_year: 'Schnitte 3 bis 12 Monate nach dem Einbau des Speichers',
    wallbox_year: 'Schnitte 3 bis 12 Monate nach dem Einbau der Wallbox',
  },
  rows: [
    { id: 'hp_post_annual', block: 'heat_pump', mode: 'nachgerechnet', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'abs', max: 3.0, judge_from: '2024-07', text: 'Wärmepumpe nachgerechnet: Jahresfehler ± 3 %' },
    { id: 'hp_post_wape_v1', block: 'heat_pump', mode: 'nachgerechnet', variant: 'V1', measure: 'wape_pct', stat: 'mean', kind: 'abs', max: 10.0, judge_from: '2024-07', text: 'Wärmepumpe nachgerechnet: WAPE ≤ 10 % (V1)' },
    { id: 'hp_post_wape_v2', block: 'heat_pump', mode: 'nachgerechnet', variant: 'V2', measure: 'wape_pct', stat: 'mean', kind: 'abs', max: 15.0, judge_from: '2024-07', text: 'Wärmepumpe nachgerechnet: WAPE ≤ 15 % (V2)' },
    { id: 'hp_pre_mean', block: 'heat_pump', mode: 'vorab', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.8, judge_from: '2024-07', text: 'Wärmepumpe vorab: mittlerer Jahresfehler ≤ 0,8 × alt' },
    { id: 'hp_pre_after_cold', block: 'heat_pump', mode: 'vorab', cut_set: 'after_cold', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.6, judge_from: '2024-07', text: 'Wärmepumpe vorab: Jahr nach dem kalten Winter ≤ 0,6 × alt' },
    { id: 'hp_pre_after_mild', block: 'heat_pump', mode: 'vorab', cut_set: 'after_mild', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.6, judge_from: '2024-07', text: 'Wärmepumpe vorab: Jahr nach dem milden Winter ≤ 0,6 × alt' },
    { id: 'hp_pre_no_cut_worse', block: 'heat_pump', mode: 'vorab', measure: 'annual_err_pct', stat: 'max_cut_worse_pp', kind: 'delta', max: 3.0, judge_from: '2024-07', text: 'Wärmepumpe vorab: kein Schnitt mehr als 3 Punkte schlechter als alt' },
    { id: 'hp_max_month', block: 'heat_pump', mode: 'vorab', measure: 'max_month_err_pct', stat: 'max', kind: 'abs', max: 40.0, judge_from: '2024-07', text: 'Wärmepumpe: größter Monatsfehler ≤ 40 %' },
    { id: 'saldo_mean', block: 'saldo', mode: 'vorab', measure: 'abs_err_eur', stat: 'mean', kind: 'rel', factor: 0.8, judge_from: '2024-07', text: 'Erwartet bei Abrechnung (3, 6, 9 Monate): mittlerer Fehler ≤ 0,8 × alt' },
    { id: 'saldo_sign', block: 'saldo', mode: 'vorab', measure: 'sign_ok', stat: 'share_pct', kind: 'abs', min: 90.0, filter: 'abs_truth_over_5pct_cost', judge_from: '2024-07', text: 'Erwartet bei Abrechnung: Vorzeichen richtig in ≥ 90 % (Ergebnis über 5 % der Jahreskosten)' },
    { id: 'feed_saldo_mean', block: 'saldo', mode: 'vorab', measure: 'feed_abs_err_eur', stat: 'mean', kind: 'rel', factor: 0.8, judge_from: '2024-07', text: 'Erwartet bei Abrechnung der Einspeisung (3, 6, 9 Monate): mittlerer Fehler ≤ 0,8 × alt' },
    { id: 'band_months', block: 'heat_pump', mode: 'vorab', measure: 'band_month_hit_pct', stat: 'pooled', kind: 'range', min: 65.0, max: 90.0, judge_from: '2024-07', text: 'Band 80 %: Monate 65–90 % Treffer' },
    { id: 'band_year', block: 'heat_pump', mode: 'vorab', cut_set: ['cold_year', 'mild_year'], measure: 'band_year_hit', stat: 'all', kind: 'bool', judge_from: '2024-07', text: 'Band 80 %: Jahr enthält die Wahrheit im kalten und im milden Jahr' },
    { id: 'pv_post_annual', block: 'pv', mode: 'nachgerechnet', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'abs', max: 5.0, text: 'PV-Erzeugung nachgerechnet: Jahresfehler ± 5 %' },
    { id: 'pv_pre_mean', block: 'pv', mode: 'vorab', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.8, text: 'PV-Erzeugung vorab: ≤ 0,8 × alt' },
    { id: 'pv_pre_no_cut_worse', block: 'pv', mode: 'vorab', measure: 'annual_err_pct', stat: 'max_cut_worse_pp', kind: 'delta', max: 3.0, text: 'PV-Erzeugung vorab: kein Schnitt mehr als 3 Punkte schlechter' },
    { id: 'feed_post_annual', block: 'feed_in', mode: 'nachgerechnet', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'abs', max: 5.0, judge_from: '2024-07', text: 'Einspeisung nachgerechnet: Jahresfehler ± 5 %' },
    { id: 'feed_pre_mean', block: 'feed_in', mode: 'vorab', measure: 'annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.8, judge_from: '2024-07', text: 'Einspeisung vorab: ≤ 0,8 × alt' },
    { id: 'feed_pre_no_cut_worse', block: 'feed_in', mode: 'vorab', measure: 'annual_err_pct', stat: 'max_cut_worse_pp', kind: 'delta', max: 3.0, judge_from: '2024-07', text: 'Einspeisung vorab: kein Schnitt mehr als 3 Punkte schlechter' },
    { id: 'battery_import', block: 'battery', mode: 'vorab', cut_set: 'battery_year', measure: 'import_annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.6, text: 'Speicher neu: Bezug im Jahr nach dem Einbau ≤ 0,6 × alt' },
    { id: 'battery_feed', block: 'battery', mode: 'vorab', cut_set: 'battery_year', measure: 'feed_annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.6, text: 'Speicher neu: Einspeisung im Jahr nach dem Einbau ≤ 0,6 × alt' },
    { id: 'wallbox_parent', block: 'wallbox', mode: 'vorab', cut_set: 'wallbox_year', measure: 'parent_annual_err_pct', stat: 'mean_abs', kind: 'rel', factor: 0.6, text: 'Wallbox neu: Elternzähler im Jahr nach dem Einbau ≤ 0,6 × alt' },
    { id: 'wallbox_winter', block: 'wallbox', mode: 'vorab', cut_set: 'wallbox_year', measure: 'winter_wape_pct', stat: 'mean', kind: 'abs', max: 30.0, text: 'Wallbox neu: Wintermonate der Wallbox WAPE ≤ 30 %' },
    { id: 'htnt_energy', block: 'htnt', mode: 'vorab', measure: 'energy_gap_kwh', stat: 'max_abs', kind: 'abs', max: 0.1, text: 'HT/NT: Energie der Gruppe = HT + NT (± 0,1 kWh)' },
    { id: 'htnt_base', block: 'htnt', mode: 'vorab', measure: 'base_gap_eur', stat: 'max_abs', kind: 'abs', max: 0.01, text: 'HT/NT: Gruppe − Summe der Zählwerke = Grundpreis (± 0,01 €)' },
    { id: 'htnt_report', block: 'htnt', mode: 'vorab', measure: 'report_gap_eur', stat: 'max_abs', kind: 'abs', max: 0.01, text: 'HT/NT: Jahresbericht = Gruppe' },
    { id: 'others_bytes', block: 'others', kind: 'external', text: 'Alle anderen Arten: alt gegen neu byte-gleich (Vergleichsskript des Fachpakets)' },
  ],
};

// ── Schreiben bzw. Prüfen ────────────────────────────────────────────────
const files = {
  'climate_normal.json': NORMAL,
  'actual_normals.json': { _comment: 'Je Kalenderjahr ein Normal nur aus dem Ist-Wetter dieses Jahres (für „nachgerechnet“).', years: ACTUAL_NORMALS },
  'schwellen.json': SCHWELLEN,
  'a-truth.json': truth('a'),
  'b-truth.json': truth('b'),
};
for (const k of ['a', 'b']) for (const v of ['V1', 'V2', 'V3']) files[`${k}-${v.toLowerCase()}.json`] = backup(k, v);

let bad = 0, wrote = 0;
for (const [name, obj] of Object.entries(files)) {
  const path = join(HERE, name);
  const json = JSON.stringify(obj) + '\n';
  const same = existsSync(path) && readFileSync(path, 'utf8') === json;
  if (CHECK) { if (!same) { console.error(`✗ tests/fixtures/heatpump-household/${name} weicht vom Generator ab`); bad++; } }
  else if (!same) { writeFileSync(path, json); wrote++; }
}
if (CHECK) {
  if (bad) process.exit(1);
  console.log('Prüfhaushalt aktuell.');
} else {
  console.log(wrote ? `Prüfhaushalt geschrieben (${wrote} Dateien).` : 'Prüfhaushalt unverändert.');
  if (!QUIET) {
    for (const k of ['a', 'b']) {
      const t = files[`${k}-truth.json`];
      console.log(`\n${t.name}: Heizgrenze ≈ ${t.heat_limit.limit_c} °C, bei 0 °C ${t.heat_limit.heat_at_0c_kwh_per_day} kWh Raumwärme am Tag`);
      for (const y of t.years) console.log(`  ${y.from}–${y.until}: Wärme ${y.heat_space_kwh} + ${y.heat_dhw_kwh} kWh, WP ${y.wp_kwh} kWh (Heizstab ${y.wp_rod_kwh}), JAZ ${y.jaz}, Haushalt ${y.house_kwh}`
        + (k === 'a' ? `, Wallbox ${y.ev_kwh}, PV ${y.pv_kwh} (abgeregelt ${y.pv_curtailed_kwh}), Speicher ${y.bat_charge_kwh}/${y.bat_discharge_kwh}, Z1 ${y.z1_imp_kwh}/${y.z1_exp_kwh}, Z2 ${y.z2_imp_kwh}/${y.z2_exp_kwh}, Rest ${y.rest_kwh}` : `, Zähler ${y.imp_kwh}`));
      for (const [c, list] of Object.entries(t.bills)) for (const b of list) console.log(`  Rechnung ${c} ${b.from}–${b.until}${b.complete ? '' : ' (Teil)'}: ${b.kwh} kWh, Kosten ${b.cost_eur} €, Abschläge ${b.advances_paid_eur} €, Ergebnis ${b.result_eur} €`);
    }
  }
}
