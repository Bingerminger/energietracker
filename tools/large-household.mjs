#!/usr/bin/env node
/* =========================================================================
   large-household.mjs — ein erfundener Haushalt mit vielen Daten (v3.7.0).

   Für Messungen mit großen Datenmengen (N1006 „Speicher-Umbau“): Wie lange
   brauchen Übersicht, Verbrauchsart, Analyse & Co., wenn ein Haushalt zehn
   Jahre lang jeden Tag abliest und zwei Jahre Viertelstunden hat?

     node tools/large-household.mjs --out /tmp/et-large            # 10 Jahre, 2 Jahre Viertelstunden
     node tools/large-household.mjs --out /tmp/et-20 --years 20 --qh-years 2
     ET_DATA_DIR=/tmp/et-large php -S 127.0.0.1:8930 router.php

   Inhalt (alles erfunden, fester Startwert — derselbe Aufruf am selben Tag
   ergibt dieselben Dateien):
     • Gas, Strom, Wasser, PV-Erzeugung, PV-Einspeisung: je ein Zähler mit
       einer Ablesung an JEDEM Tag bis gestern,
     • Tagestemperaturen für denselben Zeitraum (Heizgradtage für Gas),
     • je Kalenderjahr ein Vertrag für Gas, Strom und Wasser, eine
       Einspeisevergütung über 20 Jahre,
     • Viertelstunden der letzten vollen Kalenderjahre für Strom, PV-Erzeugung
       und PV-Einspeisung (Summe je Tag = Ablesung) und Börsenpreise dazu.
   Gemessen wird mit tools/measure-large-household.mjs; das Ergebnis steht in
   docs/entwicklung/messung-grosse-daten.md.

   Nicht für demo-data/ gedacht: Die Beispieldaten entstehen in
   tools/build-personas.mjs.
   ========================================================================= */
import { mkdirSync, readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DAY = 86400000;

// ── Zufall mit festem Startwert (mulberry32), je Reihe ein eigener Strom ──
function seeded(text) {
  let h = 2166136261;
  for (const ch of text) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); }
  let a = h >>> 0;
  return () => {
    a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const gauss = (rnd) => Math.sqrt(-2 * Math.log(rnd() || 1e-9)) * Math.cos(2 * Math.PI * rnd());
const round = (x, d) => Math.round(x * 10 ** d) / 10 ** d;

const iso = (ms) => new Date(ms).toISOString().slice(0, 10);
const parse = (s) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
const doy = (s) => Math.round((parse(s) - Date.UTC(+s.slice(0, 4), 0, 1)) / DAY);
/** Europe/Berlin: Sommerzeit vom letzten Sonntag im März 01:00 UTC bis zum letzten im Oktober 01:00 UTC. */
function lastSundayUtc(y, m) { const d = new Date(Date.UTC(y, m + 1, 0)); d.setUTCDate(d.getUTCDate() - d.getUTCDay()); return d.getTime() + 3600000; }
const berlinOffset = (ms) => { const y = new Date(ms).getUTCFullYear(); return ms >= lastSundayUtc(y, 2) && ms < lastSundayUtc(y, 9) ? 7200000 : 3600000; };
const yearStart = (y) => Date.UTC(y, 0, 1) - 3600000;   // 1.1. 00:00 Berlin (Winterzeit)
const isoLocal = (ms) => { const off = berlinOffset(ms); return new Date(ms + off).toISOString().slice(0, 19) + (off === 7200000 ? '+02:00' : '+01:00'); };

/**
 * Baut den Haushalt in `dir` (muss leer sein oder fehlen).
 *
 * @param {string} dir
 * @param {{years?: number, qhYears?: number, end?: string}} [o]  end = letzter Tag mit Ablesung (Standard: gestern)
 * @returns {{readings: number, days: number, intervals: number, from: string, to: string, qhYearsList: number[]}}
 */
export function buildLargeHousehold(dir, { years = 10, qhYears = 2, end = iso(Date.now() - DAY) } = {}) {
  if (existsSync(dir) && readdirSync(dir).length) throw new Error(`${dir} ist nicht leer`);
  mkdirSync(dir, { recursive: true });
  const write = (rel, data) => { mkdirSync(dirname(join(dir, rel)), { recursive: true }); writeFileSync(join(dir, rel), JSON.stringify(data)); };
  const endMs = parse(end);
  const from = iso(Date.UTC(new Date(endMs).getUTCFullYear() - years, new Date(endMs).getUTCMonth(), new Date(endMs).getUTCDate() + 1));
  const days = [];
  for (let t = parse(from); t <= endMs; t += DAY) days.push(iso(t));

  // ── Wetter ──
  const rT = seeded('temperatur');
  const temps = {};
  const hdd = {};
  for (const d of days) {
    const avg = 9.5 + 9.5 * Math.sin(2 * Math.PI * (doy(d) - 110) / 365.25) + 2.6 * gauss(rT);
    const spread = 3 + 2.5 * rT();
    temps[d] = { min: round(avg - spread, 1), avg: round(avg, 1), max: round(avg + spread, 1), source: 'import' };
    hdd[d] = Math.max(0, 15 - avg);
  }
  write('temperatures.json', temps);

  // ── Tagesverbrauch je Art ──
  const rG = seeded('gas'), rS = seeded('strom'), rW = seeded('wasser'), rP = seeded('pv');
  const KWH_PER_M3 = 10.6;
  const daily = { gas: {}, strom: {}, wasser: {}, pv_erzeugung: {}, pv_einspeisung: {} };
  for (const d of days) {
    const winter = Math.cos(2 * Math.PI * (doy(d) - 15) / 365.25);
    const weekday = new Date(parse(d)).getUTCDay();
    daily.gas[d] = Math.max(0.05, (4.2 + 3.1 * hdd[d]) * (1 + 0.08 * gauss(rG))) / KWH_PER_M3;          // m³
    daily.strom[d] = Math.max(2, (8.6 + 1.6 * winter + (weekday === 0 || weekday === 6 ? 1.2 : 0)) * (1 + 0.12 * gauss(rS)));
    daily.wasser[d] = Math.max(0.05, (0.31 + (weekday === 6 ? 0.08 : 0)) * (1 + 0.15 * gauss(rW)));
    const sun = 16.8 * (0.55 + 0.45 * Math.cos(2 * Math.PI * (doy(d) - 172) / 365.25)) * (0.35 + 0.75 * rP());
    daily.pv_erzeugung[d] = Math.max(0, sun);
    daily.pv_einspeisung[d] = daily.pv_erzeugung[d] * (0.55 + 0.15 * rP());
  }

  // ── Zähler, Ablesungen (jeden Tag), Verträge ──
  const ICONS = { gas: '🔥', strom: '⚡', wasser: '💧', pv_erzeugung: '☀️', pv_einspeisung: '🔆' };
  const NAMES = { gas: 'Gaszähler Keller', strom: 'Stromzähler Flur', wasser: 'Wasserzähler Keller', pv_erzeugung: 'Wechselrichter Dach', pv_einspeisung: 'Zweirichtungszähler' };
  const START_COUNTER = { gas: 4210.5, strom: 23810, wasser: 512.25, pv_erzeugung: 0, pv_einspeisung: 0 };
  const DEC = { gas: 3, strom: 1, wasser: 3, pv_erzeugung: 1, pv_einspeisung: 1 };
  let readingCount = 0;
  for (const u of Object.keys(daily)) {
    const meterId = `m_${u}_gross`;
    const deviceId = `d_${u}_gross`;
    write(`${u}/meters.json`, [{
      id: meterId, name: NAMES[u], icon: ICONS[u], created_at: from, active: true, notes: '',
      devices: [{ id: deviceId, serial: `X-${u.length}${years}0`, installed_on: from, initial_counter: START_COUNTER[u], removed_on: null, final_counter: null, reason: null }],
      parent_meter_id: null, meter_group_id: null, external_id: null, baseline_events: [],
    }]);
    let counter = START_COUNTER[u];
    const readings = [];
    days.forEach((d, i) => {
      if (i > 0) counter += daily[u][days[i - 1]];
      readings.push({ id: `r_${u}_${String(i + 1).padStart(5, '0')}`, meter_id: meterId, device_id: deviceId, date: d,
        counter: round(counter, DEC[u]), price_cents: null, note: '', is_estimated: false, is_future: false });
    });
    readingCount += readings.length;
    write(`${u}/readings.json`, readings);
    write(`${u}/meter_groups.json`, []);
  }
  const firstYear = +from.slice(0, 4), lastYear = +end.slice(0, 4);
  const contract = (u, y, extra) => ({
    id: `c_${u}_${y}`, meter_id: `m_${u}_gross`, provider: u === 'wasser' ? 'Wasserverband Beispieltal' : (y % 2 ? 'Musterwerke Nord' : 'Beispielenergie Süd'),
    tariff_name: `${u === 'gas' ? 'Gas' : u === 'strom' ? 'Strom' : 'Wasser'} ${y}`, start: `${y}-01-01`, end: `${y}-12-31`, notes: '',
    bonuses: [], notice_period_months: 1, min_term_end: null, price_guarantee_until: null, signup_bonus_eur: null, ...extra,
  });
  const gasPrice = (y) => round(6.4 + 0.25 * (y - firstYear) + (y === 2022 || y === 2023 ? 5.5 : 0), 2);
  const stromPrice = (y) => round(28 + 0.6 * (y - firstYear) + (y === 2022 || y === 2023 ? 9 : 0), 2);
  const gas = [], strom = [], wasser = [];
  for (let y = firstYear; y <= lastYear; y++) {
    gas.push(contract('gas', y, { working_prices: [{ from: `${y}-01-01`, ct_per_kwh: gasPrice(y) }], base_prices: [{ from: `${y}-01-01`, eur_per_month: 14.9 }], advance_payments: [{ from: `${y}-01-01`, amount_eur: Math.round(gasPrice(y) * 9.5) }] }));
    strom.push(contract('strom', y, { working_prices: [{ from: `${y}-01-01`, ct_per_kwh: stromPrice(y) }], base_prices: [{ from: `${y}-01-01`, eur_per_month: 11.5 }], advance_payments: [{ from: `${y}-01-01`, amount_eur: Math.round(stromPrice(y) * 2.9) }] }));
    wasser.push(contract('wasser', y, {
      trinkwasser: { working_prices: [{ from: `${y}-01-01`, ct_per_m3: 215 + 4 * (y - firstYear) }], base_prices: [{ from: `${y}-01-01`, eur_per_month: 7.2 }] },
      schmutzwasser: { basis: 'trinkwasser', separater_zaehler_meter_id: null, abzug_meter_ids: [], working_prices: [{ from: `${y}-01-01`, ct_per_m3: 260 + 5 * (y - firstYear) }] },
      niederschlagswasser: { rates: [{ from: `${y}-01-01`, eur_per_m2_year: 1.3, versiegelte_flaeche_m2: 110 }] },
      advance_payments: [{ from: `${y}-01-01`, amount_eur: 60 }],
    }));
  }
  write('gas/contracts.json', gas);
  write('strom/contracts.json', strom);
  write('wasser/contracts.json', wasser);
  write('pv_einspeisung/contracts.json', [{
    id: 'c_pv_einspeisung_eeg', meter_id: 'm_pv_einspeisung_gross', provider: 'Netzbetrieb Beispieltal', tariff_name: 'Einspeisevergütung',
    start: from, end: iso(parse(from) + 20 * 365.25 * DAY), notes: '', working_prices: [{ from, ct_per_kwh: 8.1 }],
    base_prices: [], advance_payments: [], special_payments: [], bonuses: [], is_shadow: false, shadow_label: null,
  }]);

  // ── Viertelstunden der letzten vollen Kalenderjahre ──
  const qhYearsList = [];
  for (let y = lastYear - qhYears; y < lastYear; y++) if (y >= firstYear && `${y}-01-01` >= from) qhYearsList.push(y);
  const series = [];
  let intervalCount = 0;
  const shapeStrom = (h) => [0.5, 0.45, 0.42, 0.42, 0.45, 0.6, 0.95, 1.4, 1.2, 0.95, 0.9, 1.0, 1.1, 0.95, 0.85, 0.85, 0.95, 1.3, 1.75, 1.8, 1.6, 1.25, 0.9, 0.65][h];
  const shapeSun = (h, d) => { const len = 8 + 8 * (0.5 + 0.5 * Math.cos(2 * Math.PI * (doy(d) - 172) / 365.25)); const x = (h + 0.5 - 13.2) / (len / 2); return Math.abs(x) < 1 ? Math.cos(x * Math.PI / 2) ** 2 : 0; };
  const rPrice = seeded('boerse');
  for (const y of qhYearsList) {
    const start = yearStart(y);
    const n = (yearStart(y + 1) - start) / 900000;
    const slotDay = [], slotHour = [];
    for (let i = 0; i < n; i++) {
      const loc = new Date(start + i * 900000 + berlinOffset(start + i * 900000));
      slotDay.push(loc.toISOString().slice(0, 10));
      slotHour.push(loc.getUTCHours() + loc.getUTCMinutes() / 60);
    }
    const spread = (u, shape) => {
      const wsum = new Map();
      for (let i = 0; i < n; i++) wsum.set(slotDay[i], (wsum.get(slotDay[i]) || 0) + shape(Math.floor(slotHour[i]), slotDay[i]));
      return slotDay.map((d, i) => { const w = wsum.get(d); const v = daily[u][d]; return v == null || !w ? null : round(v * shape(Math.floor(slotHour[i]), d) / w, 4); });
    };
    const add = (meta, values, unit) => {
      const filled = values.filter(v => v !== null).length;
      const first = values.findIndex(v => v !== null);
      let last = -1; for (let i = values.length - 1; i >= 0; i--) if (values[i] !== null) { last = i; break; }
      write(`intervals/${meta.id}.json`, { id: meta.id, kind: meta.kind, year: y, step: 900, start: isoLocal(start), values });
      series.push({ ...meta, unit, year: y, step: 900, slots: n, filled, from: first >= 0 ? isoLocal(start + first * 900000) : null,
        to: last >= 0 ? isoLocal(start + (last + 1) * 900000) : null, source: meta.kind === 'price' ? 'smard' : 'import', updated_at: `${end}T12:00:00+02:00` });
      intervalCount += filled;
    };
    for (const [u, shape] of [['strom', shapeStrom], ['pv_erzeugung', shapeSun], ['pv_einspeisung', shapeSun]]) {
      add({ id: `e-${u}-m_${u}_gross-${y}`.replace(/[^A-Za-z0-9_.-]/g, '-').toLowerCase(), kind: 'energy', utility: u, meter_id: `m_${u}_gross` }, spread(u, shape), 'kWh');
    }
    // Börsenpreis: nachts und mittags günstig, morgens und abends teuer, im Sommer mittags auch negativ
    const prices = slotHour.map((h, i) => {
      const summer = 0.5 + 0.5 * Math.cos(2 * Math.PI * (doy(slotDay[i]) - 172) / 365.25);
      const noon = Math.exp(-((h - 13) ** 2) / 6) * (4 + 9 * summer);
      const peak = Math.exp(-((h - 8) ** 2) / 2) * 4 + Math.exp(-((h - 19) ** 2) / 3) * 6;
      return round(9.5 + peak - noon + (h < 5 ? -2 : 0) + 2.2 * gauss(rPrice), 3);
    });
    add({ id: `p-de-lu-${y}`, kind: 'price', area: 'DE-LU' }, prices, 'ct/kWh');
  }
  write('intervals.json', { series, samples: {} });

  // ── Einstellungen (Grundlage: Beispieldaten), Schema, leere Töpfe ──
  const base = JSON.parse(readFileSync(join(ROOT, 'demo-data', 'settings.json'), 'utf8'));
  write('settings.json', {
    ...base,
    gas_conversion_factors: [{ from: null, zustandszahl: null, brennwert: null, kwh_per_m3: KWH_PER_M3 }],
    location_name: 'Beispieltal', active_utilities: ['gas', 'strom', 'wasser', 'pv_einspeisung', 'pv_erzeugung'],
    weather_auto_fill: false, setup_pending: false, ui_level: 'expert', wohnflaeche_m2: 135, wasser_personen_anzahl: 3,
  });
  const { SCHEMA } = schemaVersion();
  write('meta.json', { schema_version: SCHEMA, migrated_at: `${end}T12:00:00+02:00`, log: [{ step: 'large-household', years, qh_years: qhYears }] });
  for (const f of ['reminders.json', 'activity.json', 'people.json', 'attachments.json', 'tenancies.json', 'tenancy_statements.json', 'market_prices.json']) {
    if (!existsSync(join(dir, f))) write(f, []);
  }
  return { readings: readingCount, days: days.length, intervals: intervalCount, from, to: end, qhYearsList };
}

/** Schemaversion aus dem Migrator (Quelle, keine Kopie — Lektion 34). */
function schemaVersion() {
  const src = readFileSync(join(ROOT, 'src', 'Storage', 'Migrator.php'), 'utf8');
  const m = src.match(/const SCHEMA_VERSION\s*=\s*'([\d.]+)'/);
  if (!m) throw new Error('SCHEMA_VERSION nicht gefunden');
  return { SCHEMA: m[1] };
}

// ── Aufruf von der Kommandozeile ──
if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  const args = process.argv.slice(2);
  const opt = (k, d) => { const i = args.indexOf(`--${k}`); return i >= 0 ? args[i + 1] : d; };
  if (args.includes('--help') || !opt('out')) {
    console.log('node tools/large-household.mjs --out <leeres Verzeichnis> [--years 10] [--qh-years 2] [--end JJJJ-MM-TT]');
    process.exit(args.includes('--help') ? 0 : 1);
  }
  const t0 = Date.now();
  const r = buildLargeHousehold(resolve(opt('out')), { years: Number(opt('years', 10)), qhYears: Number(opt('qh-years', 2)), ...(opt('end') ? { end: opt('end') } : {}) });
  console.log(`${r.days} Tage (${r.from} bis ${r.to}), ${r.readings} Ablesungen, ${r.intervals} Viertelstundenwerte (${r.qhYearsList.join(', ') || '–'}) in ${Date.now() - t0} ms → ${opt('out')}`);
}
