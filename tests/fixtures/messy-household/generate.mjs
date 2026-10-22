#!/usr/bin/env node
/* =========================================================================
   Ein unordentlicher Testhaushalt (v3.7.0, Paket interpolation, GitHub #37–#39)

   Warum es ihn gibt: #37, #38 und #39 fand ein Nutzer mit echten Daten, nicht
   unsere Tests. Die Beispielhaushalte (tools/build-personas.mjs) erzeugen den
   Heizverbrauch als Konstante × Heizgradtage zur Heizgrenze 15 °C plus Rauschen
   und werden regelmäßig abgelesen — genau das Modell der App. Darauf passt das
   lineare Heizmodell immer, und verteilte Monate kommen nicht vor: ein
   Zirkelschluss.

   Dieser Haushalt rechnet deshalb bewusst NICHT mit dem App-Modell
   (a × HGT + c × Tage), sondern mit einer eigenen, einfachen Physik:

     Heizung     Wärmeverlust UA × (Raumtemperatur − wirksame Außentemperatur),
                 abzüglich innerer Wärme und Sonnengewinnen; die wirksame
                 Temperatur hängt um einen Tag nach (Speichermasse). Geheizt wird
                 erst unter der Heizgrenze HEAT_LIMIT_C (Sommerbetrieb darüber).
                 Der Brennwertkessel verliert bei Kälte Wirkungsgrad (höhere
                 Rücklauftemperatur) — die Kurve über den Heizgradtagen ist
                 konvex wie in #38.
     Warmwasser  ganzjährig, im Winter mehr (kälteres Zulaufwasser), Urlaub im
                 August ohne Warmwasser.
     PV          Sonnenstunden nach Jahreszeit × Bewölkung des Tages.
     Strom       Grundlast nach Jahreszeit, abzüglich des selbst genutzten
                 Sonnenstroms.

   Abgelesen wird wie im echten Leben: unregelmäßige Tage, manchmal zwei im
   selben Monat, eine Lücke über drei Monate im Herbst, eine Sommerpause, eine
   (falsch) geschätzte Ablesung, ein Zählertausch mitten im Monat; PV-Erzeugung
   nur bei Inbetriebnahme und gut zwei Jahre später, Einspeisung monatlich,
   Strombezug einmal im Jahr. Alle Werte sind erfunden; fester Seed, jeder Lauf
   schreibt dieselbe Datei.

     node tests/fixtures/messy-household/generate.mjs          # schreibt household.json
     node tests/fixtures/messy-household/generate.mjs --check  # vergleicht nur, Exit 1 bei Abweichung

   Geprüft von tests/unit/Services/MessyHouseholdTest.php; beschrieben in
   docs/entwicklung/tests.md.
   ========================================================================= */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, 'household.json');
const CHECK = process.argv.includes('--check');

// ── Parameter (erfunden) ─────────────────────────────────────────────────
export const PARAMS = {
  FROM: '2022-10-01',          // erster Tag mit Temperatur
  TO: '2026-01-31',            // letzter Tag mit Temperatur
  ROOM_C: 20.5,                // Raumtemperatur
  UA_KW_PER_K: 0.13,           // Wärmeverlust des Hauses
  INFILTRATION_PER_K: 0.012,   // Lüftungsverlust wächst mit dem Temperaturunterschied
  HEATED_SHARE_MILD: 0.45,     // Anteil geheizter Räume knapp unter der Heizgrenze …
  FULL_HEAT_SPAN_K: 10,        // … ganz geheizt erst 10 K darunter
  HEAT_LIMIT_C: 12.5,          // Heizgrenze: darüber kein Heizbetrieb
  INTERNAL_KWH: 9.0,           // innere Wärme je Tag (Menschen, Geräte)
  SOLAR_KWH_PEAK: 26.0,        // Sonnengewinn durch Fenster an einem klaren Tag im Juni
  LAG_SHARE: 0.45,             // Anteil der Vortagstemperatur (Speichermasse)
  DHW_KWH: 6.2,                // Warmwasser je Tag
  KWH_PER_M3: 10.4,            // Brennwert × Zustandszahl, fest
  PV_KWP: 3.4,
  HOUSE_KWH: 8.8,              // Haushaltsstrom je Tag im Jahresmittel
};
const P = PARAMS;

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

// ── Kalender ─────────────────────────────────────────────────────────────
const toDate = (s) => new Date(s + 'T12:00:00Z');
const iso = (d) => d.toISOString().slice(0, 10);
const addDays = (s, n) => { const d = toDate(s); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
function* days(from, toIncl) { for (let d = from; d <= toIncl; d = addDays(d, 1)) yield d; }
const doy = (s) => Math.round((toDate(s) - toDate(s.slice(0, 4) + '-01-01')) / 86400000) + 1;

// ── Wetter: Jahresgang, träges Rauschen, ein milder und ein kalter Winter ─
const weatherRand = rng(37);
const temps = {};
const clouds = {};
let ar = 0;
for (const d of days(P.FROM, P.TO)) {
  ar = 0.8 * ar + 0.6 * 2.6 * gauss(weatherRand);
  const y = Number(d.slice(0, 4)), m = Number(d.slice(5, 7));
  const winter = m >= 11 ? y : m <= 3 ? y - 1 : null;   // Winter = Jahr seines Novembers
  const anomaly = winter === 2023 ? 1.6 : winter === 2024 ? -2.1 : 0;
  const avg = 9.6 - 9.4 * Math.cos(2 * Math.PI * (doy(d) - 18) / 365.25) + ar + anomaly;
  temps[d] = { avg: round(avg, 1), min: round(avg - 3.5 - weatherRand(), 1), max: round(avg + 4 + weatherRand(), 1) };
  clouds[d] = Math.min(1, Math.max(0.12, 0.62 + 0.28 * gauss(weatherRand)));   // 1 = klar
}

// ── Tagesverbrauch nach eigener Physik ───────────────────────────────────
const sunShare = (d) => 0.5 - 0.5 * Math.cos(2 * Math.PI * (doy(d) - 172 + 182.6) / 365.25);   // 0 im Dezember, 1 im Juni
const useRand = rng(38);
const gasKwh = {};      // Tag → kWh Gas (Brennstoff)
const pvKwh = {};       // Tag → kWh Erzeugung
const importKwh = {};   // Tag → kWh Netzbezug
const feedKwh = {};     // Tag → kWh Einspeisung
let prevT = null;
for (const d of days(P.FROM, P.TO)) {
  const t = temps[d].avg;
  const tEff = prevT === null ? t : (1 - P.LAG_SHARE) * t + P.LAG_SHARE * prevT;
  prevT = t;
  const solar = P.SOLAR_KWH_PEAK * (0.15 + 0.85 * sunShare(d)) * clouds[d];
  // Konvex wie in echten Häusern: In der Übergangszeit wird nur ein Teil der
  // Räume geheizt, je kälter, desto mehr; Lüftung und Undichtigkeit wachsen mit
  // dem Temperaturunterschied (Kamineffekt)
  const dT = P.ROOM_C - tEff;
  const heatedShare = Math.min(1, P.HEATED_SHARE_MILD + (1 - P.HEATED_SHARE_MILD) * Math.max(0, P.HEAT_LIMIT_C - tEff) / P.FULL_HEAT_SPAN_K);
  const loss = P.UA_KW_PER_K * dT * (1 + P.INFILTRATION_PER_K * dT) * 24 * heatedShare;
  const need = tEff >= P.HEAT_LIMIT_C ? 0 : Math.max(0, loss - P.INTERNAL_KWH - solar);
  const eta = 0.97 - 0.006 * Math.max(0, 8 - tEff);                         // Kessel bei Kälte schlechter
  const md = d.slice(5);
  const away = md >= '08-03' && md <= '08-17';                               // Urlaub
  const guests = md >= '12-23' || md <= '01-02';
  const dhw = away ? 0.3 : P.DHW_KWH * (1 + 0.18 * Math.cos(2 * Math.PI * (doy(d) - 20) / 365.25)) * (guests ? 1.35 : 1);
  const noise = 1 + 0.07 * gauss(useRand);
  gasKwh[d] = Math.max(0, (need * (guests ? 1.08 : 1) / eta + dhw / 0.86) * noise);

  const gen = P.PV_KWP * (0.25 + 4.6 * sunShare(d)) * clouds[d];
  const load = P.HOUSE_KWH * (1 + 0.22 * Math.cos(2 * Math.PI * (doy(d) - 15) / 365.25)) * (away ? 0.35 : 1) * (1 + 0.1 * gauss(useRand));
  const selfUse = Math.min(0.42 * gen, 0.55 * load);
  pvKwh[d] = gen;
  importKwh[d] = Math.max(0, load - selfUse);
  feedKwh[d] = gen - selfUse;
}
const sumBetween = (series, from, toExcl) => { let s = 0; for (const d of days(from, addDays(toExcl, -1))) s += series[d] ?? 0; return s; };

// ── Ablesungen wie im echten Leben ───────────────────────────────────────
const GAS_DATES = [
  '2022-11-03', '2022-12-01', '2023-01-04', '2023-02-02', '2023-02-27', '2023-03-30', '2023-05-02',
  '2023-06-05', '2023-07-03', '2023-08-29', '2023-10-01', '2023-11-04', '2023-11-28', '2023-12-30',
  '2024-02-10', '2024-03-02', '2024-04-06', '2024-05-03', '2024-06-01', '2024-07-06', '2024-08-03',
  '2024-09-07', /* Herbst: über drei Monate nichts */ '2024-12-19', '2025-01-18',
  '2025-02-14', /* Zählertausch mitten im Monat */ '2025-03-15', '2025-04-12', '2025-05-10',
  '2025-06-03', /* Sommerpause */ '2025-09-02', '2025-10-04', '2025-11-01', '2025-12-03', '2026-01-05',
];
export const GAS_ESTIMATED = '2024-02-10';   // Karte nicht zur Hand: 15 % zu wenig geschätzt
export const GAS_SWAP = '2025-02-14';
const GAS_START_M3 = 4210.5, NEW_DEVICE_M3 = 0.4;

const m3 = (kwh) => kwh / P.KWH_PER_M3;
const gasReadings = [];
let oldFinal = null;
for (const [i, date] of GAS_DATES.entries()) {
  const used = m3(sumBetween(gasKwh, GAS_DATES[0], date));
  const beforeSwap = date < GAS_SWAP;
  let counter = beforeSwap ? GAS_START_M3 + used : NEW_DEVICE_M3 + (used - m3(sumBetween(gasKwh, GAS_DATES[0], GAS_SWAP)));
  if (date === GAS_ESTIMATED) {
    const prev = GAS_START_M3 + m3(sumBetween(gasKwh, GAS_DATES[0], GAS_DATES[i - 1]));
    counter = prev + 0.85 * (counter - prev);
  }
  gasReadings.push({
    id: `r_gas_messy_${String(i).padStart(2, '0')}`, meter_id: 'm_gas_messy', device_id: beforeSwap ? 'd_gas_old' : 'd_gas_new',
    date, counter: round(counter, 1), price_cents: null, note: date === GAS_ESTIMATED ? 'geschätzt' : '',
    is_estimated: date === GAS_ESTIMATED, is_future: false,
  });
}
oldFinal = round(GAS_START_M3 + m3(sumBetween(gasKwh, GAS_DATES[0], GAS_SWAP)), 1);

const PV_DATES = ['2023-05-01', '2025-08-15'];            // Inbetriebnahme und gut zwei Jahre später
const FEED_DATES = [];
for (let y = 2023, m = 5; y < 2026 || (y === 2026 && m === 1); m === 12 ? (y++, m = 1) : m++) {
  const day = 1 + ((y * 7 + m * 3) % 3);                   // am 1., 2. oder 3.
  FEED_DATES.push(`${y}-${String(m).padStart(2, '0')}-0${day}`);
}
const IMPORT_DATES = ['2023-01-05', '2024-01-08', '2025-01-10', '2026-01-12'];   // einmal im Jahr

const counterReadings = (prefix, meterId, deviceId, dates, series, start, decimals) => dates.map((date, i) => ({
  id: `r_${prefix}_${String(i).padStart(2, '0')}`, meter_id: meterId, device_id: deviceId, date,
  counter: round(start + sumBetween(series, dates[0], date), decimals), price_cents: null, note: '', is_estimated: false, is_future: false,
}));

const device = (id, serial, installed, initial, removed = null, final = null, reason = null) =>
  ({ id, serial, installed_on: installed, initial_counter: initial, removed_on: removed, final_counter: final, reason });
const meter = (id, name, devices, extra = {}) => ({
  id, name, icon: '', created_at: devices[0].installed_on, active: true, notes: '',
  parent_meter_id: null, meter_group_id: null, external_id: null, baseline_events: [], ...extra, devices,
});
const contract = (id, meterId, start, wp, bp, ap) => ({
  id, meter_id: meterId, provider: 'Testversorger', tariff_name: 'Testtarif', start, end: null, notes: '',
  working_prices: [{ from: start, ct_per_kwh: wp }], base_prices: [{ from: start, eur_per_month: bp }],
  advance_payments: [{ from: start, amount_eur: ap }], bonuses: [], special_payments: [], is_shadow: false,
});

// ── Wahrheit je Monat (zum Vergleich in den Tests) ───────────────────────
const byMonth = (series) => {
  const out = {};
  for (const [d, v] of Object.entries(series)) out[d.slice(0, 7)] = round((out[d.slice(0, 7)] ?? 0) + v, 1);
  return out;
};

const household = {
  _comment: 'Erzeugt von tests/fixtures/messy-household/generate.mjs — nicht von Hand ändern. Eigene Physik statt des App-Modells, Ablesungen wie im echten Leben; alles erfunden.',
  params: P,
  settings: {
    gas_conversion_factors: [{ from: null, kwh_per_m3: P.KWH_PER_M3 }],
    hdd_base_temp: 15.0,
    active_utilities: ['gas', 'strom', 'pv_einspeisung', 'pv_erzeugung'],
  },
  temperatures: temps,
  utilities: {
    gas: {
      meters: [meter('m_gas_messy', 'Gaszähler', [
        device('d_gas_old', 'G-OLD', '2022-11-03', GAS_START_M3, GAS_SWAP, oldFinal, 'Eichtausch'),
        device('d_gas_new', 'G-NEW', GAS_SWAP, NEW_DEVICE_M3),
      ])],
      readings: gasReadings,
      contracts: [contract('c_gas_messy', 'm_gas_messy', '2022-11-01', 11.2, 13.5, 150)],
    },
    strom: {
      meters: [meter('m_strom_messy', 'Stromzähler', [device('d_strom', 'S-1', IMPORT_DATES[0], 21877.0)])],
      readings: counterReadings('strom_messy', 'm_strom_messy', 'd_strom', IMPORT_DATES, importKwh, 21877.0, 1),
      contracts: [contract('c_strom_messy', 'm_strom_messy', '2023-01-01', 34.0, 11.0, 75)],
    },
    pv_erzeugung: {
      meters: [meter('m_pv_erz_messy', 'Wechselrichter', [device('d_pv_erz', 'W-1', PV_DATES[0], 0)])],
      readings: counterReadings('pv_erz_messy', 'm_pv_erz_messy', 'd_pv_erz', PV_DATES, pvKwh, 0, 1),
      contracts: [],
    },
    pv_einspeisung: {
      meters: [meter('m_pv_einsp_messy', 'Einspeisezähler', [device('d_pv_einsp', 'E-1', FEED_DATES[0], 0)])],
      readings: counterReadings('pv_einsp_messy', 'm_pv_einsp_messy', 'd_pv_einsp', FEED_DATES, feedKwh, 0, 1),
      contracts: [],
    },
  },
  truth: { gas_kwh: byMonth(gasKwh), pv_kwh: byMonth(pvKwh), import_kwh: byMonth(importKwh) },
};

const json = JSON.stringify(household, null, 1) + '\n';
const same = existsSync(OUT) && readFileSync(OUT, 'utf8') === json;
if (CHECK) {
  if (!same) { console.error('✗ tests/fixtures/messy-household/household.json weicht vom Generator ab'); process.exit(1); }
  console.log('Testhaushalt aktuell.');
} else {
  if (!same) writeFileSync(OUT, json);
  console.log(same ? 'Testhaushalt unverändert.' : 'Testhaushalt geschrieben.');
}
