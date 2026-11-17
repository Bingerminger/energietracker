#!/usr/bin/env node
/* =========================================================================
   Ein Testhaushalt mit Verträgen, die nicht glatt laufen (v3.13.0)

   Warum es ihn gibt: Die Beispielhaushalte (tools/build-personas.mjs) folgen
   dem Modell der App und kennen keinen Einzug mitten im Monat, kein
   Vertragsende ohne Folgevertrag, keine Zählergruppe mit verschiedenen
   Ablesetagen und keinen Abschlag mit Terminen. Der unordentliche Haushalt
   (tests/fixtures/messy-household) prüft Lücken, Tausch und Schätzung an
   einem Zähler. Dieser Haushalt bringt die Fälle der Verträge zusammen, damit
   ein Test Ansicht gegen Ansicht an einem ganzen Haushalt rechnen kann:

     Wohnung A (Miete, 71 m², Etagenheizung Gas) vom 03.04.2023 bis zum
     Auszug am 14.05.2026, Haus B ab dem Einzug am 10.05.2026.

     Gas A        Vertrag bis 14.05.2026 ohne Folgevertrag, Schlussablesung am
                  letzten Vertragstag; Abrechnungsjahr 2024 mit dem 29.02.;
                  Ablesungen an Neujahr; Mietverhältnis mit Auszug im Jahr
                  (CO₂-Aufteilung im Teiljahr).
     Strom A      Vertrag bis 14.05.2026 ohne Folgevertrag, Übergabe-Ablesung
                  erst am 19.05.; Stand zum 31.12.2024 vom Versorger geschätzt,
                  danach eine echte Ablesung (Schätzung an einer
                  Periodengrenze, Bekannte Grenze).
     Strom B      Zählergruppe HT/NT mit verschiedenen Preisen je Zählwerk,
                  Vertrag ab dem 10.05.2026 (Einzug, Abschlag anteilig), Ende
                  am 30.04.2027 mit einem Monat Frist; HT und NT an
                  verschiedenen Tagen abgelesen, NT zuletzt einen Monat früher.
     Wallbox B    eigener Vertrag ab 01.06.2026, Arbeitspreis erst ab 01.08.
                  (Juni ohne Preis), der Juli trägt nur ein Preis an der
                  Ablesung vom 01.08.; Ende am 30.11.2027 mit drei Monaten Frist.
     Wasser B     Abschlag mit Terminen (alle drei Monate am 15.), Gartenzähler
                  später abgelesen als der Hauptzähler, nach der letzten echten
                  Ablesung ein geschätzter Stand.

   Der Verbrauch folgt einer eigenen, einfachen Physik, nicht dem Modell der
   App (a × HGT + c × Tage):

     Heizung A    Wärmeverlust UA × (Raum − wirksame Außentemperatur) mit
                  Speichermasse, abzüglich innerer Gewinne, nur unter der
                  Heizgrenze A_HEAT_LIMIT_C; Therme mit festem Wirkungsgrad,
                  Warmwasser ganzjährig, Urlaub im August ohne Warmwasser.
     Strom A/B    Grundlast nach Jahreszeit und Wochentag; in Haus B dazu eine
                  Wärmepumpe mit Leistungszahl nach Außentemperatur; der
                  Anteil im Niedertarif je Verbraucher fest.
     Wallbox      Ladevorgänge an zufälligen Tagen, eine Woche Urlaub.
     Wasser       Personen × Liter je Tag; Gießen an heißen Tagen ohne Regen.

   Abgelesen wird wie im echten Leben, an unregelmäßigen Tagen; Ablesung am
   Tag D zählt bis D − 1 (Stand zu Beginn von D). Alle Werte sind erfunden;
   fester Seed, jeder Lauf schreibt dieselbe Datei. Abgeschlossen am
   31.10.2026: Die Tests rechnen mit festem „heute“ (01.11.2026).

     node tests/fixtures/edge-household/generate.mjs          # schreibt household.json
     node tests/fixtures/edge-household/generate.mjs --check  # vergleicht nur, Exit 1 bei Abweichung

   Geprüft von tests/unit/Services/EdgeHouseholdTest.php; beschrieben in
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
  FROM: '2023-03-01',          // erster Tag mit Temperatur
  TO: '2026-10-31',            // letzter Tag mit Daten (abgeschlossen)
  TODAY: '2026-11-01',         // „heute“ der Tests
  KWH_PER_M3: 10.27,           // Gas: Brennwert × Zustandszahl laut Versorger, fest
  A_ROOM_C: 21.0,              // Wohnung A: Raumtemperatur
  A_UA_W_PER_K: 118,           // Wärmeverlust der Wohnung
  A_HEAT_LIMIT_C: 13.8,        // Heizgrenze der Wohnung (nicht die 15 °C der App)
  A_GAINS_KWH: 5.2,            // innere Gewinne je Tag
  A_LAG_SHARE: 0.4,            // Anteil der Vortagstemperatur (Speichermasse)
  A_DHW_KWH: 4.1,              // Warmwasser je Tag
  A_ETA: 0.91,                 // Wirkungsgrad der Therme
  A_HOUSE_KWH: 5.6,            // Haushaltsstrom Wohnung A je Tag
  B_HOUSE_KWH: 7.9,            // Haushaltsstrom Haus B je Tag
  B_ROOM_C: 20.5,
  B_UA_W_PER_K: 165,
  B_HEAT_LIMIT_C: 14.5,
  B_GAINS_KWH: 7.5,
  B_NT_SHARE_HP: 0.58,         // Wärmepumpe läuft vor allem im Niedertarif
  B_NT_SHARE_HOUSE: 0.21,      // Haushalt zum kleineren Teil
  WB_SESSION_P: 0.31,          // Wahrscheinlichkeit eines Ladevorgangs je Tag
  WB_MIN_KWH: 6, WB_MAX_KWH: 21,
  W_PERSONS: 3, W_L_PER_PERSON: 117,
  G_HOT_C: 23.5, G_MIN_M3: 0.16, G_MAX_M3: 0.42,   // Gießen an heißen, trockenen Tagen
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
const weekday = (s) => toDate(s).getUTCDay();   // 0 = Sonntag

// ── Wetter: Jahresgang, träges Rauschen, ein milder und ein kalter Winter, Regen ─
const weatherRand = rng(47);
const temps = {};
const rain = {};
let ar = 0;
for (const d of days(P.FROM, P.TO)) {
  ar = 0.78 * ar + 2.3 * gauss(weatherRand);
  const y = Number(d.slice(0, 4)), m = Number(d.slice(5, 7));
  const winter = m >= 11 ? y : m <= 3 ? y - 1 : null;
  const anomaly = winter === 2023 ? 1.2 : winter === 2024 ? -1.5 : winter === 2025 ? 0.4 : 0;
  const avg = 10.1 - 9.0 * Math.cos(2 * Math.PI * (doy(d) - 20) / 365.25) + 0.6 * ar + anomaly;
  temps[d] = { avg: round(avg, 1), min: round(avg - 3.8 - weatherRand(), 1), max: round(avg + 4.4 + 1.5 * weatherRand(), 1) };
  const wet = weatherRand() < 0.43 - 0.09 * Math.sin(2 * Math.PI * (doy(d) - 80) / 365.25);
  rain[d] = wet ? round(0.5 + 9 * weatherRand() ** 2, 1) : 0;
}

// ── Tagesverbrauch nach eigener Physik ───────────────────────────────────
const useRand = rng(48);
const series = { gas_a_m3: {}, strom_a: {}, b_ht: {}, b_nt: {}, wallbox: {}, wasser_b: {}, garten: {} };
let prevT = null;
for (const d of days(P.FROM, P.TO)) {
  const t = temps[d].avg;
  const tEff = prevT === null ? t : (1 - P.A_LAG_SHARE) * t + P.A_LAG_SHARE * prevT;
  prevT = t;
  const md = d.slice(5);
  const away = md >= '08-05' && md <= '08-18';                       // Sommerurlaub
  const xmas = md >= '12-23' && md <= '12-27';                       // über Weihnachten bei der Familie
  const weekend = [0, 6].includes(weekday(d));

  // Wohnung A: Heizung und Warmwasser über die Therme (m³)
  const lossA = P.A_UA_W_PER_K * (P.A_ROOM_C - tEff) * 24 / 1000;
  const needA = tEff >= P.A_HEAT_LIMIT_C ? 0 : Math.max(0, lossA - P.A_GAINS_KWH) * (xmas ? 0.6 : 1);
  const dhwA = away ? 0 : P.A_DHW_KWH * (1 + 0.15 * Math.cos(2 * Math.PI * (doy(d) - 25) / 365.25));
  series.gas_a_m3[d] = Math.max(0, (needA + dhwA) / P.A_ETA * (1 + 0.06 * gauss(useRand))) / P.KWH_PER_M3;
  series.strom_a[d] = Math.max(0.4, P.A_HOUSE_KWH * (1 + 0.18 * Math.cos(2 * Math.PI * (doy(d) - 10) / 365.25))
    * (weekend ? 1.15 : 1) * (away ? 0.35 : 1) * (1 + 0.09 * gauss(useRand)));

  // Haus B: Haushalt und Wärmepumpe, HT und NT
  const lossB = P.B_UA_W_PER_K * (P.B_ROOM_C - tEff) * 24 / 1000;
  const heatB = tEff >= P.B_HEAT_LIMIT_C ? 0 : Math.max(0, lossB - P.B_GAINS_KWH);
  const cop = Math.min(4.6, Math.max(2.0, 2.4 + 0.085 * (t + 5)));
  const hp = heatB / cop + (away ? 0.3 : 1.1);                      // Warmwasser über die Wärmepumpe
  const house = P.B_HOUSE_KWH * (1 + 0.16 * Math.cos(2 * Math.PI * (doy(d) - 10) / 365.25)) * (weekend ? 1.12 : 1)
    * (away ? 0.3 : 1) * (1 + 0.08 * gauss(useRand));
  series.b_nt[d] = hp * P.B_NT_SHARE_HP + house * P.B_NT_SHARE_HOUSE;
  series.b_ht[d] = hp * (1 - P.B_NT_SHARE_HP) + house * (1 - P.B_NT_SHARE_HOUSE);

  // Wallbox: Ladevorgänge
  const charge = !away && useRand() < P.WB_SESSION_P * (weekend ? 1.4 : 1);
  series.wallbox[d] = charge ? P.WB_MIN_KWH + (P.WB_MAX_KWH - P.WB_MIN_KWH) * useRand() : 0;

  // Wasser B: Haushalt (m³), Garten an heißen Tagen ohne Regen an diesem und dem Vortag
  const dry = rain[d] === 0 && (rain[addDays(d, -1)] ?? 0) === 0;
  const garden = !away && dry && temps[d].max >= P.G_HOT_C ? P.G_MIN_M3 + (P.G_MAX_M3 - P.G_MIN_M3) * useRand() : 0;
  series.garten[d] = garden;
  series.wasser_b[d] = (away ? 0.15 : 1) * P.W_PERSONS * P.W_L_PER_PERSON / 1000 * (1 + 0.12 * gauss(useRand)) + garden;
}
const sumBetween = (s, from, toExcl) => { let x = 0; for (const d of days(from, addDays(toExcl, -1))) x += s[d] ?? 0; return x; };

// ── Ablesungen ───────────────────────────────────────────────────────────
export const DATES = {
  // Gas A: Einzug, an Neujahr, Schlussablesung am letzten Vertragstag
  gas_a: ['2023-04-03', '2023-05-06', '2023-06-11', '2023-08-02', '2023-10-01', '2023-11-04', '2023-12-02', '2024-01-01',
    '2024-02-03', '2024-03-09', '2024-04-14', '2024-06-01', '2024-08-30', '2024-10-05', '2024-11-02', '2024-12-07', '2025-01-01',
    '2025-02-08', '2025-03-15', '2025-05-03', '2025-07-12', '2025-09-27', '2025-11-08', '2025-12-06', '2026-01-01', '2026-02-07',
    '2026-03-14', '2026-04-18', '2026-05-14'],
  // Strom A: selten; Stand zum 31.12.2024 geschätzt, Übergabe fünf Tage nach dem Vertragsende
  strom_a: ['2023-04-03', '2023-10-14', '2024-01-02', '2024-06-22', '2024-12-31', '2025-01-03', '2025-07-19', '2026-01-04',
    '2026-03-28', '2026-05-19'],
  // Strom B: HT und NT an verschiedenen Tagen, am 28.09. beide, NT danach nicht mehr
  b_ht: ['2026-05-10', '2026-06-01', '2026-07-02', '2026-08-01', '2026-09-01', '2026-09-28', '2026-10-30'],
  b_nt: ['2026-05-10', '2026-06-07', '2026-07-11', '2026-08-16', '2026-09-28'],
  // Wallbox: monatlich, der Preis steht an der Ablesung vom 01.08.
  wallbox: ['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-10-31'],
  // Wasser B: Hauptzähler zuletzt echt am 03.10., am 31.10. geschätzt; Garten zuletzt am 27.10.
  wasser_b: ['2026-05-10', '2026-06-14', '2026-07-26', '2026-08-30', '2026-10-03', '2026-10-31'],
  garten: ['2026-05-10', '2026-07-05', '2026-08-23', '2026-10-27'],
};
export const ESTIMATED = { strom_a: '2024-12-31', wasser_b: '2026-10-31' };
const ESTIMATE_SHARE = { strom_a: 0.93, wasser_b: 1.09 };   // Versorger schätzt zu wenig, der Haushalt zu viel
export const WALLBOX_READING_PRICE = { date: '2026-08-01', ct: 30.9 };
const START = { gas_a: 11706.25, strom_a: 48211.6, b_ht: 15233.0, b_nt: 7712.0, wallbox: 0, wasser_b: 412.371, garten: 63.205 };
const DECIMALS = { gas_a: 3, strom_a: 1, b_ht: 1, b_nt: 1, wallbox: 1, wasser_b: 3, garten: 3 };
const SERIES = { gas_a: 'gas_a_m3', strom_a: 'strom_a', b_ht: 'b_ht', b_nt: 'b_nt', wallbox: 'wallbox', wasser_b: 'wasser_b', garten: 'garten' };
const METER = { gas_a: 'm_gas_a', strom_a: 'm_strom_a', b_ht: 'm_b_ht', b_nt: 'm_b_nt', wallbox: 'm_b_wb', wasser_b: 'm_wasser_b', garten: 'm_garten_b' };

function readingsOf(key) {
  const dates = DATES[key];
  const s = series[SERIES[key]];
  const truth = (d) => START[key] + sumBetween(s, dates[0], d);
  return dates.map((date, i) => {
    let counter = truth(date);
    const estimated = ESTIMATED[key] === date;
    if (estimated) {
      const prev = truth(dates[i - 1]);
      counter = prev + ESTIMATE_SHARE[key] * (counter - prev);
    }
    const price = key === 'wallbox' && date === WALLBOX_READING_PRICE.date ? WALLBOX_READING_PRICE.ct : null;
    return {
      id: `r_${key}_${String(i).padStart(2, '0')}`, meter_id: METER[key], device_id: `d_${key}`, date,
      counter: round(counter, DECIMALS[key]), price_cents: price, note: estimated ? 'geschätzt' : '',
      is_estimated: estimated, is_future: false,
    };
  });
}

// ── Zähler, Gruppe, Verträge, Rechnungen, Mietverhältnis ─────────────────
const device = (key) => ({ id: `d_${key}`, serial: null, installed_on: DATES[key][0], initial_counter: START[key],
  removed_on: null, final_counter: null, reason: null });
const meter = (key, name, extra = {}) => ({
  id: METER[key], name, icon: '', created_at: DATES[key][0], active: true, notes: '',
  parent_meter_id: null, meter_group_id: null, external_id: null, baseline_events: [], ...extra, devices: [device(key)],
});
const contract = (fields) => ({
  meter_id: null, provider: '', tariff_name: '', end: null, auto_renews: null, notice_period_months: null, notice_period_days: null,
  notice_mode: null, min_term_end: null, price_guarantee_until: null, signup_bonus_eur: null, notes: '',
  working_prices: [], base_prices: [], advance_payments: [], bonuses: [], special_payments: [], is_shadow: false, ...fields,
});

const contracts = {
  gas: [contract({
    id: 'c_gas_a', meter_id: 'm_gas_a', provider: 'Gaswerk Musterstadt', tariff_name: 'Therme A', start: '2023-04-03', end: '2026-05-14',
    auto_renews: false, notice_period_months: 1,
    working_prices: [{ from: '2023-04-03', ct_per_kwh: 11.38 }, { from: '2025-03-01', ct_per_kwh: 9.86 }],
    base_prices: [{ from: '2023-04-03', eur_per_month: 13.2 }],
    advance_payments: [{ from: '2023-04-03', amount_eur: 96.0 }, { from: '2025-03-01', amount_eur: 84.0 }],
  })],
  strom: [
    contract({
      id: 'c_strom_a', meter_id: 'm_strom_a', provider: 'Stromwerk Musterstadt', tariff_name: 'Wohnung A', start: '2023-04-03', end: '2026-05-14',
      auto_renews: false, notice_period_months: 1,
      working_prices: [{ from: '2023-04-03', ct_per_kwh: 33.7 }, { from: '2025-01-01', ct_per_kwh: 29.95 }],
      base_prices: [{ from: '2023-04-03', eur_per_month: 11.4 }],
      advance_payments: [{ from: '2023-04-03', amount_eur: 58.0 }, { from: '2025-01-01', amount_eur: 52.0 }],
    }),
    contract({
      id: 'c_b_dt', meter_group_id: 'g_b_dt', provider: 'Regionalstrom Musterstadt', tariff_name: 'Duo B', start: '2026-05-10', end: '2027-04-30',
      notice_period_months: 1,
      working_prices_by_meter: { m_b_ht: [{ from: '2026-05-10', ct_per_kwh: 34.15 }], m_b_nt: [{ from: '2026-05-10', ct_per_kwh: 23.8 }] },
      base_prices: [{ from: '2026-05-10', eur_per_month: 14.9 }],
      advance_payments: [{ from: '2026-05-10', amount_eur: 118.0 }],
    }),
    contract({
      id: 'c_b_wb', meter_id: 'm_b_wb', provider: 'Autostrom Musterstadt', tariff_name: 'Laden', start: '2026-06-01', end: '2027-11-30',
      notice_period_months: 3,
      working_prices: [{ from: '2026-08-01', ct_per_kwh: 27.4 }],
      base_prices: [{ from: '2026-06-01', eur_per_month: 6.5 }],
      advance_payments: [{ from: '2026-06-01', amount_eur: 32.0 }],
    }),
  ],
  wasser: [contract({
    id: 'c_wasser_b', meter_id: 'm_wasser_b', provider: 'Wasserwerk Musterstadt', tariff_name: 'Haus B', start: '2026-05-10',
    trinkwasser: { working_prices: [{ from: '2026-05-10', ct_per_m3: 237.0 }], base_prices: [{ from: '2026-05-10', eur_per_month: 4.8 }] },
    schmutzwasser: { basis: 'trinkwasser_minus_abzug', separater_zaehler_meter_id: null, abzug_meter_ids: ['m_garten_b'],
      working_prices: [{ from: '2026-05-10', ct_per_m3: 312.0 }], base_prices: [] },
    niederschlagswasser: { rates: [] },
    advance_payments: [{ from: '2026-05-10', amount_eur: 147.0, every_months: 3, due_dates: ['2026-05-15', '2026-08-15', '2026-11-15'] }],
  })],
};

const readings = Object.fromEntries(Object.keys(DATES).map((k) => [k, readingsOf(k)]));
const counterOn = (key, date) => readings[key].find((r) => r.date === date).counter;

// Jahresrechnung Gas 2024 (Schaltjahr, Ablesungen an beiden Neujahren): 11,38 ct, 12 × 13,20 € Grundpreis, 12 × 96 €
// Abschlag. Der Versorger rechnet wie die Handrechnung im Test.
const gasKwh2024 = round((counterOn('gas_a', '2025-01-01') - counterOn('gas_a', '2024-01-01')) * P.KWH_PER_M3, 1);
const gasAmount2024 = round(gasKwh2024 * 0.1138 + 12 * 13.2, 2);
const gasBills = [{
  id: 'b_gas_a_2024', meter_id: 'm_gas_a', contract_id: 'c_gas_a', kind: 'annual', period_from: '2024-01-01', period_to: '2024-12-31',
  issued_on: '2025-01-28',
  invoice: { energy_kwh: gasKwh2024, amount_eur: gasAmount2024, advances_paid_eur: 1152.0, result_eur: round(gasAmount2024 - 1152, 2) },
  items: [], attachment_ids: [], note: '', created_at: '2025-01-28T09:00:00+01:00',
}];

const tenancies = [{
  id: 't_wohnung_a', start: '2023-04-03', end: '2026-05-14', label: 'Wohnung A', landlord: 'Hausverwaltung Musterstadt',
  wohnflaeche_m2: 71, billing_anchor: '01-01',
  prepayments: [{ from: '2023-04-03', heating_eur_month: 0, operating_eur_month: 145 }],
  prices: [], fixed_costs: [], meter_ids: {}, notes: '',
}];

// ── Wahrheit je Monat (zum Vergleich in den Tests) ───────────────────────
const byMonth = (s, from, to, d = 1) => {
  const out = {};
  for (const [day, v] of Object.entries(s)) if (day >= from && day <= to) out[day.slice(0, 7)] = (out[day.slice(0, 7)] ?? 0) + v;
  return Object.fromEntries(Object.entries(out).map(([k, v]) => [k, round(v, d)]));
};

const household = {
  _comment: 'Erzeugt von tests/fixtures/edge-household/generate.mjs, nicht von Hand ändern. Verträge, die nicht glatt laufen; eigene Physik statt des App-Modells; alles erfunden.',
  params: P,
  settings: {
    country: 'DE',
    wohnverhaeltnis: 'miete',
    gas_conversion_factors: [{ from: null, kwh_per_m3: P.KWH_PER_M3 }],
    active_utilities: ['strom', 'gas', 'wasser'],
  },
  temperatures: temps,
  utilities: {
    gas: {
      meters: [meter('gas_a', 'Therme Wohnung A')],
      readings: readings.gas_a,
      contracts: contracts.gas,
      bills: gasBills,
    },
    strom: {
      meters: [
        meter('strom_a', 'Wohnung A'),
        meter('b_ht', 'Haus B HT', { meter_group_id: 'g_b_dt', obis: '1.8.1' }),
        meter('b_nt', 'Haus B NT', { meter_group_id: 'g_b_dt', obis: '1.8.2' }),
        meter('wallbox', 'Wallbox Carport'),
      ],
      meter_groups: [{ id: 'g_b_dt', name: 'Haus B Doppeltarif', created_at: '2026-05-10' }],
      readings: [...readings.strom_a, ...readings.b_ht, ...readings.b_nt, ...readings.wallbox].sort((a, b) => a.date.localeCompare(b.date) || a.id.localeCompare(b.id)),
      contracts: contracts.strom,
    },
    wasser: {
      meters: [meter('wasser_b', 'Haus B'), meter('garten', 'Garten', { parent_meter_id: 'm_wasser_b', role: 'garden' })],
      readings: [...readings.wasser_b, ...readings.garten].sort((a, b) => a.date.localeCompare(b.date) || a.id.localeCompare(b.id)),
      contracts: contracts.wasser,
    },
  },
  tenancies,
  truth: {
    gas_a_m3: byMonth(series.gas_a_m3, DATES.gas_a[0], '2026-05-13', 3),
    strom_a_kwh: byMonth(series.strom_a, DATES.strom_a[0], '2026-05-18'),
    b_ht_kwh: byMonth(series.b_ht, DATES.b_ht[0], P.TO), b_nt_kwh: byMonth(series.b_nt, DATES.b_nt[0], P.TO),
    wallbox_kwh: byMonth(series.wallbox, DATES.wallbox[0], P.TO),
    wasser_b_m3: byMonth(series.wasser_b, DATES.wasser_b[0], P.TO, 3), garten_m3: byMonth(series.garten, DATES.garten[0], P.TO, 3),
  },
};

const json = JSON.stringify(household, null, 1) + '\n';
const same = existsSync(OUT) && readFileSync(OUT, 'utf8') === json;
if (CHECK) {
  if (!same) { console.error('✗ tests/fixtures/edge-household/household.json weicht vom Generator ab'); process.exit(1); }
  console.log('Testhaushalt aktuell.');
} else {
  if (!same) writeFileSync(OUT, json);
  console.log(same ? 'Testhaushalt unverändert.' : 'Testhaushalt geschrieben.');
}
