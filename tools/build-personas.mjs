#!/usr/bin/env node
/* =========================================================================
   build-personas.mjs — Beispielhaushalte je Persona (F1018, v3.2.0).

   Neben dem „Schaufenster“ (demo-data/energietracker-demo-backup.json, alle
   Verbrauchsarten auf einmal) je ein Haushalt, wie ihn der
   Einrichtungsassistent und die öffentliche Demo zeigen:

     mieterin             Stadtwohnung zur Miete: Strom mit eigenem Vertrag,
                          Heizwärme laut monatlicher Verbrauchsinfo, Warm- und
                          Kaltwasser, Mietverhältnis mit Nebenkostenabrechnungen
     etw-fernwaerme       Eigentumswohnung mit eigenem Fernwärmevertrag
                          (Leistungs- und Messpreis) und Versorgerrechnung
     eigenheim-klassisch  Einfamilienhaus mit Gasheizung, Garten-Subzähler,
                          Vertragswechsel und Terminen
     eigenheim-modern     Wärmepumpe mit eigenem Tarif, PV mit Speicher,
                          Wallbox, dynamischer Schattenvertrag

   Erzeugt statt von Hand gepflegt: Die Werte folgen aus einem Tagesmodell —
   Heizen nach Heizgradtagen der Demo-Temperaturen (gleicher Ort), Strom mit
   Jahreszeitenverlauf, PV nach Monat und Wetter, Speicher als Tagesbilanz —
   mit festem Seed. Jeder Lauf schreibt dieselben Dateien. Abrechnungen,
   Rechnungen und Sonderzahlungen rechnet das Skript aus denselben Tageswerten
   nach, damit sie zu den Ständen passen.

     node tools/build-personas.mjs           # schreibt demo-data/personas/<id>.json
     node tools/build-personas.mjs --check   # vergleicht nur, Exit 1 bei Abweichung

   Format wie ein Export (BackupService): backup_version 3.0, alle Töpfe aller
   Verbrauchsarten, auch leere — ein Import ersetzt so den ganzen Haushalt,
   auch die Arten, die die Persona nicht nutzt. Zeitraum: drei Jahre bis zum
   Exportstand des Demo-Backups; DemoDataAligner schreibt beim Import bis heute
   fort. Alle Namen sind erfunden, die Marktlokations-IDs synthetisch. Neue
   Texte brauchen einen Eintrag in demo-data/translations.json — das Skript
   nennt fehlende, DemoDataTranslatorTest prüft sie.
   ========================================================================= */

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = join(ROOT, 'demo-data', 'personas');
const CHECK = process.argv.includes('--check');

/** Version, mit der die Personas kamen (app_version im Backup). */
const PERSONA_VERSION = '3.2.0';

/** Reihenfolge wie Utilities::keys() bzw. BackupService::UTILITY_POTS (PersonaDemoTest prüft beides). */
const UTILITIES = ['gas', 'strom', 'wasser', 'fernwaerme', 'heizoel', 'pellets', 'pv_einspeisung', 'pv_erzeugung', 'waerme'];
const POTS = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups', 'periods', 'bills'];

/** Felder, die DemoDataTranslator übersetzt (DemoDataTranslator::FIELDS). */
const TEXT_FIELDS = ['name', 'notes', 'note', 'reason', 'label', 'tariff_name', 'shadow_label', 'title'];

const readJson = (rel) => JSON.parse(readFileSync(join(ROOT, rel), 'utf8'));
const DEMO = readJson('demo-data/energietracker-demo-backup.json');
const SCHEMA = (readFileSync(join(ROOT, 'src/Storage/Migrator.php'), 'utf8').match(/SCHEMA_VERSION = '([\d.]+)'/) || [])[1];
if (!SCHEMA) throw new Error('Schema-Version nicht gefunden (src/Storage/Migrator.php)');

// ── Datum (UTC, ohne Sommerzeit-Sprünge) ─────────────────────────────────

const toDate = (s) => new Date(s + 'T00:00:00Z');
const isoOf = (d) => d.toISOString().slice(0, 10);
function addDays(s, n) { const d = toDate(s); d.setUTCDate(d.getUTCDate() + n); return isoOf(d); }
function addMonths(s, n) { const d = toDate(s.slice(0, 8) + '01'); d.setUTCMonth(d.getUTCMonth() + n); return isoOf(d); }
const daysBetween = (a, b) => Math.round((toDate(b) - toDate(a)) / 86400000);
/**
 * Börsenstrompreise Day-Ahead DE-LU, Monatsmittel in ct/kWh — Quelle:
 * Bundesnetzagentur | SMARD.de, CC BY 4.0 (abgerufen 2026-10-09 über „Von SMARD
 * laden“). Für den Dynamik-Check im Haushalt „Eigenheim modern“.
 */
const SMARD_MONTHLY = {
  '2023-01': 11.783, '2023-02': 12.831, '2023-03': 10.252, '2023-04': 10.074, '2023-05': 8.172, '2023-06': 9.476,
  '2023-07': 7.761, '2023-08': 9.432, '2023-09': 10.072, '2023-10': 8.738, '2023-11': 9.112, '2023-12': 6.852,
  '2024-01': 7.657, '2024-02': 6.134, '2024-03': 6.47, '2024-04': 6.236, '2024-05': 6.721, '2024-06': 7.289,
  '2024-07': 6.77, '2024-08': 8.205, '2024-09': 7.831, '2024-10': 8.61, '2024-11': 11.391, '2024-12': 10.832,
  '2025-01': 11.414, '2025-02': 12.852, '2025-03': 9.473, '2025-04': 7.794, '2025-05': 6.734, '2025-06': 6.399,
  '2025-07': 8.78, '2025-08': 7.699, '2025-09': 8.351, '2025-10': 8.44, '2025-11': 10.188, '2025-12': 9.347,
  '2026-01': 11.009, '2026-02': 9.658, '2026-03': 9.929, '2026-04': 7.852, '2026-05': 9.754,
};
const daysInMonth = (ym) => new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7), 0)).getUTCDate();
const doy = (s) => daysBetween(s.slice(0, 4) + '-01-01', s);
function* days(from, toExcl) { for (let d = from; d < toExcl; d = addDays(d, 1)) yield d; }
function months(from, toExcl) { const out = []; for (let m = from.slice(0, 8) + '01'; m < toExcl; m = addMonths(m, 1)) out.push(m.slice(0, 7)); return out; }
const compact = (s) => s.replaceAll('-', '');
/** Versatz Europe/Berlin an einem Tag: Sommerzeit vom letzten Sonntag im März bis zum letzten im Oktober. */
const lastSunday = (y, m) => { const d = new Date(Date.UTC(y, m, 0)); d.setUTCDate(d.getUTCDate() - d.getUTCDay()); return isoOf(d); };
const berlinOffset = (d) => d >= lastSunday(+d.slice(0, 4), 3) && d < lastSunday(+d.slice(0, 4), 10) ? '+02:00' : '+01:00';

// Zeitraum: drei Jahre bis zum Exportstand der Haupt-Demo
const EXPORTED_AT = DEMO.exported_at;
const END = EXPORTED_AT.slice(0, 10);              // letzter Tag mit Daten
const END_EXCL = addDays(END, 1);
const START = addMonths(END_EXCL, -36);              // erster Stand
const TEMPS = DEMO.temperatures;
const SIM_START = Object.keys(TEMPS).sort()[0];      // Tagesmodell ab dem ersten Temperaturtag
for (const d of days(SIM_START, END_EXCL)) {
  if (!TEMPS[d]) throw new Error(`Temperatur fehlt für ${d}`);
}

// ── Zufall mit festem Seed ───────────────────────────────────────────────

function hex(text, len) { return createHash('sha256').update(text).digest('hex').slice(0, len); }
/** mulberry32 — je Reihe ein eigener Strom, damit eine Änderung die anderen nicht verschiebt. */
function seeded(text) {
  let a = parseInt(hex(text, 8), 16);
  return () => {
    a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const gauss = (rand) => Math.sqrt(-2 * Math.log(Math.max(rand(), 1e-12))) * Math.cos(2 * Math.PI * rand());
const between = (rand, a, b) => a + (b - a) * rand();
const clamp = (x, lo, hi) => Math.min(hi, Math.max(lo, x));
const round = (x, d = 2) => { const f = 10 ** d; return Math.round(x * f) / f; };
const ceilTo = (x, step) => Math.ceil(x / step) * step;

// ── Tagesmodell ──────────────────────────────────────────────────────────

const temp = (d) => TEMPS[d].avg;
/** Heizgradtage zur Heizgrenze 15 °C, wie die App sie zählt (hdd_base_temp). */
const hdd = (d) => Math.max(0, 15 - temp(d));
/** Jahreszeit: 1 ± amp, Maximum Mitte Januar. */
const season = (d, amp) => 1 + amp * Math.cos(2 * Math.PI * (doy(d) - 15) / 365.25);
const inRanges = (d, ranges) => ranges.some(([a, b]) => d >= a && d <= b);
const AVG_HDD_YEAR = (() => {
  let s = 0, n = 0;
  for (const d of days(START, END_EXCL)) { s += hdd(d); n++; }
  return s / n * 365.25;
})();

/** Tagesreihe Datum → Wert über den ganzen Modellzeitraum. */
function series(fn) { const s = new Map(); for (const d of days(SIM_START, END_EXCL)) s.set(d, fn(d)); return s; }
function sum(s, from, toExcl) { let t = 0; for (const d of days(from, toExcl)) t += s.get(d) ?? 0; return t; }
/** Reihe so skalieren, dass das Jahresmittel im Datenzeitraum `annual` ist. */
function scaleTo(s, annual) {
  const f = annual / (sum(s, START, END_EXCL) / daysBetween(START, END_EXCL) * 365.25);
  for (const [d, v] of s) s.set(d, v * f);
  return s;
}
const add = (...ss) => series(d => ss.reduce((t, s) => t + (s.get(d) ?? 0), 0));

/** Ablesetage: rund um den Monatsersten, ein Tag für alle Zähler des Haushalts. */
function readingDates(rand) {
  const out = [START];
  for (let m = 1; m <= 36; m++) {
    let d = addDays(addMonths(START, m), Math.floor(between(rand, -3, 3)));
    if (d > END) d = END;
    if (d > out.at(-1)) out.push(d);
  }
  return out;
}
/** Monatserste (Fernauslesung, Kundenportal), dazu der Exporttag als letzter Stand. */
const monthStarts = () => [...months(START, END_EXCL).map(ym => ym + '-01'), END];

// ── Datensätze, Felder wie MeterService/ContractService/… sie anlegen ────

const CREATED = START;

function device(id, serial, installedOn) {
  return { id, serial, installed_on: installedOn, initial_counter: 0, removed_on: null, final_counter: null, reason: null };
}

function meter(id, name, icon, notes, dev, extra = {}) {
  return {
    id, name, icon, created_at: CREATED, active: true, notes,
    parent_meter_id: null, meter_group_id: null, external_id: null, baseline_events: [],
    ...extra,
    devices: [dev],
  };
}

/** Stände aus einer Tagesreihe: Stand am Morgen des Ablesetags. */
function readings(prefix, m, dates, s, start, decimals, notes = {}) {
  let c = start;
  let prev = dates[0];
  return dates.map(date => {
    c += sum(s, prev, date);
    prev = date;
    return {
      id: `r_${prefix}_${compact(date)}`, meter_id: m.id, device_id: m.devices[0].id, date,
      counter: round(c, decimals), price_cents: null, note: notes[date] ?? '', is_estimated: false, is_future: false,
    };
  });
}
/** Stand an einem Tag aus den gespeicherten (gerundeten) Ständen — wie die App, linear. */
function counterAt(rs, date) {
  for (let i = 1; i < rs.length; i++) {
    if (rs[i].date < date) continue;
    const a = rs[i - 1], b = rs[i];
    const span = daysBetween(a.date, b.date);
    return a.counter + (b.counter - a.counter) * (span ? daysBetween(a.date, date) / span : 1);
  }
  return rs.at(-1).counter;
}

const prices = (field, rows) => rows.map(([from, v]) => ({ from, [field]: v }));
const wp = (...rows) => prices('ct_per_kwh', rows);
const bp = (...rows) => prices('eur_per_month', rows);
const ap = (...rows) => prices('amount_eur', rows);

function specialPayment(key, date, kind, amount, note, newAdvance = null, advanceFrom = null) {
  return { id: 'sp_' + hex(key, 10), date, kind, amount_eur: round(amount, 2), note, new_advance_eur: newAdvance, advance_from: advanceFrom };
}

/** Vertrag (Gas, Strom, Fernwärme, Einspeisung) mit allen Feldern, die ContractService::create schreibt. */
function contract(id, meterId, provider, tariff, start, end, o = {}) {
  return {
    id, meter_id: meterId, provider, tariff_name: tariff, start, end, notes: o.notes ?? '',
    working_prices: o.wp ?? [], base_prices: o.bp ?? [], advance_payments: o.ap ?? [],
    bonuses: o.bonuses ?? [], special_payments: o.special ?? [],
    ...(o.extra ?? {}),
    is_shadow: o.shadow !== undefined, shadow_label: o.shadow ?? null,
    notice_period_months: o.notice ?? null, notice_period_days: null, notice_mode: o.noticeMode ?? null,
    auto_renews: null, min_term_end: null, price_guarantee_until: o.guarantee ?? null, signup_bonus_eur: null,
  };
}

/** Gültiger Eintrag einer datierten Liste: der späteste mit `from` ≤ Tag (TenancyService::validAt). */
const validAt = (list, date) => {
  let best = null;
  for (const e of list) if (e.from <= date && (best === null || e.from >= best.from)) best = e;
  return best;
};
const valueOn = (list, field, date) => validAt(list, date)?.[field] ?? null;
/** Abschlagsplan samt „mit Auswirkung“-Sonderzahlungen (ContractService::effectiveAdvanceSchedule). */
const advanceOn = (c, date) => valueOn([
  ...c.advance_payments,
  ...c.special_payments.filter(s => s.new_advance_eur !== null).map(s => ({ from: s.advance_from, amount_eur: s.new_advance_eur })),
], 'amount_eur', date);
const fixedPerMonth = (c, date) => (valueOn(c.base_prices, 'eur_per_month', date) ?? 0)
  + (c.capacity_kw ? c.capacity_kw * (valueOn(c.capacity_prices ?? [], 'eur_per_kw_year', date) ?? 0) / 12 : 0)
  + (valueOn(c.metering_prices ?? [], 'eur_per_year', date) ?? 0) / 12
  - (valueOn(c.grid_reduction ?? [], 'eur_per_year', date) ?? 0) / 12;

/**
 * Abrechnung eines Vertrags über ganze Monate [from, toExcl): Arbeitspreis je
 * Tag, feste Kosten und Abschläge je Monat, Boni im Zeitraum. `kwhOf(a, b)`
 * liefert die Menge eines Abschnitts. Positives Ergebnis = Nachzahlung.
 */
function settle(c, kwhOf, from, toExcl) {
  let kwh = 0, energy = 0, fixed = 0, advances = 0;
  for (const ym of months(from, toExcl)) {
    const a = ym + '-01', b = addMonths(a, 1);
    const cuts = [...new Set([a, ...c.working_prices.map(e => e.from).filter(d => d > a && d < b), b])].sort();
    for (let i = 0; i + 1 < cuts.length; i++) {
      const q = kwhOf(cuts[i], cuts[i + 1]);
      kwh += q;
      energy += q * valueOn(c.working_prices, 'ct_per_kwh', cuts[i]) / 100;
    }
    fixed += fixedPerMonth(c, a);
    advances += advanceOn(c, a) ?? 0;
  }
  const bonus = c.bonuses.filter(x => x.credit_date >= from && x.credit_date < toExcl).reduce((t, x) => t + x.amount_eur, 0);
  const cost = energy + fixed - bonus;
  return { kwh, energy, fixed, bonus, cost, advances, result: cost - advances };
}
/** Jahresabrechnung als Sonderzahlung: Rückzahlung oder Nachzahlung, mit oder ohne neuen Abschlag. */
function settlementPayment(key, date, s, label, newAdvance = null, advanceFrom = null) {
  const refund = s.result < 0;
  const mit = newAdvance !== null;
  const kind = (refund ? 'rueckzahlung' : 'nachzahlung') + (mit ? '_mit' : '_ohne');
  const note = `${label} — ${refund ? 'Guthaben' : 'Nachzahlung'}${mit ? ', Abschlag angepasst' : ''}`;
  return specialPayment(key, date, kind, Math.abs(s.result), note, newAdvance, advanceFrom);
}

function reminder(key, title, category, nextDue, recurrence, recurrenceMonths, lastDone, notes) {
  return { id: 'rem_' + hex(key, 8), title, category, next_due: nextDue, recurrence, recurrence_months: recurrenceMonths, last_done: lastDone, notes, active: true };
}

/** Marktlokations-ID mit Prüfziffer nach BDEW (MeterService::isValidMalo) — synthetisch. */
function malo(first10) {
  let odd = 0, even = 0;
  for (let i = 0; i < 10; i++) { if (i % 2 === 0) odd += +first10[i]; else even += +first10[i]; }
  return first10 + String((10 - ((odd + 2 * even) % 10)) % 10);
}

const BASE_SETTINGS = {
  hdd_base_temp: DEMO.settings.hdd_base_temp,
  location_name: DEMO.settings.location_name,
  latitude: DEMO.settings.latitude,
  longitude: DEMO.settings.longitude,
  weather_auto_fill: true,
};

// ═════════════════════════════════════════════════════════════════════════
// Mieterin — Stadtwohnung, 62 m², Zentralheizung (Gas) über die Nebenkosten
// ═════════════════════════════════════════════════════════════════════════

function mieterin() {
  const P = 'mieterin';
  const r = (k) => seeded(`${P}|${k}`);
  const dates = readingDates(r('dates'));
  const away = [['2023-08-12', '2023-08-26'], ['2024-07-27', '2024-08-10'], ['2024-12-23', '2024-12-27'], ['2025-08-02', '2025-08-16']];

  const rs = r('strom');
  const strom = scaleTo(series(d => season(d, 0.16) * (1 + 0.10 * gauss(rs)) * (inRanges(d, away) ? 0.35 : 1)), 1650);
  const rk = r('kalt'), rw = r('warm');
  const kalt = scaleTo(series(d => Math.max(0, 1 + 0.18 * gauss(rk)) * (inRanges(d, away) ? 0.04 : 1)), 27);
  const warm = scaleTo(series(d => Math.max(0, 1 + 0.22 * gauss(rw)) * (inRanges(d, away) ? 0.02 : 1) * season(d, 0.08)), 16);
  // Raumwärme: Heizgradtage, ab 2025 gut 5 % weniger (programmierbare Thermostate)
  const rh = r('heat');
  const heat = series(d => 4900 / AVG_HDD_YEAR * hdd(d) * Math.max(0, 1 + 0.06 * gauss(rh)) * (d >= '2025-01-01' ? 0.945 : 1));

  const mStrom = meter('m_strom_wohnung', 'Stromzähler Wohnung', '⚡', 'Zählerschrank im Treppenhaus',
    device('d_strom_wohnung', 'S-2021-384', '2021-06-14'), { malo_id: malo('5123456789') });
  const mKalt = meter('m_wasser_kalt', 'Kaltwasserzähler', '💧', 'Bad, unter dem Waschbecken',
    device('d_wasser_kalt', 'KW-2022-1187', '2022-03-08'));
  const mWarm = meter('m_wasser_warm', 'Warmwasserzähler', '💧', 'Bad, unter dem Waschbecken',
    device('d_wasser_warm', 'WW-2022-1188', '2022-03-08'), { role: 'warm' });
  const mHeat = meter('m_waerme_wohnung', 'Heizung laut Verbrauchsinfo', '♨️', 'Monatliche Verbrauchsinformation des Messdienstes',
    device('d_waerme_wohnung', null, '2022-04-01'), { capture: 'period' });

  // Monatswerte der Verbrauchsinfo samt Vergleichswerten (Vormonat, Vorjahresmonat, Durchschnittsnutzer)
  const rr = r('reference');
  const monthKwh = (ym) => sum(heat, ym + '-01', addMonths(ym + '-01', 1));
  const periods = [];
  const byYm = {};
  for (const ym of months(START, END_EXCL)) {
    const v = Math.round(monthKwh(ym));
    byYm[ym] = v;
    const prevYm = addMonths(ym + '-01', -1).slice(0, 7);
    const lastYear = addMonths(ym + '-01', -12).slice(0, 7);
    periods.push({
      id: 'p_' + hex(`${P}|period|${ym}`, 12), meter_id: mHeat.id,
      from: ym + '-01', to: addDays(addMonths(ym + '-01', 1), -1),
      value: v, value_unit: 'consumption', is_estimated: false, source: 'manual',
      reference: {
        prev_month: byYm[prevYm] ?? Math.round(monthKwh(prevYm)),
        prev_year_month: byYm[lastYear] ?? Math.round(v * between(rr, 1.03, 1.14)),
        average_user: Math.round(v * 1.16 * (1 + 0.04 * gauss(rr))),
      },
      note: '',
    });
  }

  // Mietverhältnis: Umlagen je Jahr (Grundsteuerreform 2025), Vorauszahlung aus den Abrechnungen
  const umlagen = {
    2024: { Grundsteuer: 168.4, 'Müllbeseitigung': 152.8, 'Straßenreinigung': 38.6, 'Gebäudeversicherung': 196.3, Hausreinigung: 214.5, Gartenpflege: 88.2, Allgemeinstrom: 41.7, Hauswart: 156 },
    2025: { Grundsteuer: 191.2, 'Müllbeseitigung': 158.4, 'Straßenreinigung': 38.6, 'Gebäudeversicherung': 212.9, Hausreinigung: 214.5, Gartenpflege: 88.2, Allgemeinstrom: 41.7, Hauswart: 156 },
  };
  const rates = {
    2024: { heat: 0.1468, warm: 11.2, cold: 2.31, sewage: 2.86, co2: 45 },
    2025: { heat: 0.1392, warm: 10.85, cold: 2.39, sewage: 2.94, co2: 55 },
  };
  const tid = 't_' + hex(`${P}|tenancy`, 12);
  const prepayments = [{ from: '2022-04-01', heating_eur_month: 75, operating_eur_month: 105 }];
  const statements = [];
  const statementPrices = [];
  const received = { 2024: ['2025-06-12', '2025-06-14T19:12:00+02:00', '2025-08-01', true], 2025: ['2026-05-20', '2026-05-23T10:41:00+02:00', '2026-07-01', false] };
  for (const year of [2024, 2025]) {
    const from = `${year}-01-01`, toExcl = `${year + 1}-01-01`;
    const heatKwh = months(from, toExcl).reduce((t, ym) => t + byYm[ym], 0);
    const warmM3 = round(sum(warm, from, toExcl), 2);
    const coldM3 = round(sum(kalt, from, toExcl), 2);
    const k = rates[year];
    // CO₂ des Gebäudes, auf die Wohnung entfallend (Heizung + Warmwasser, Kessel 88 %)
    const gasKwh = (heatKwh + 2.5 * warmM3 * 50) / 0.88;
    const emissions = round(gasKwh * 0.18139, 1);
    const kgM2 = round(emissions / 62, 1);
    const stages = [[12, 0], [17, 10], [22, 20], [27, 30], [32, 40], [37, 50], [42, 60], [47, 70], [52, 80], [Infinity, 95]];
    const stage = stages.findIndex(([upper]) => kgM2 < upper) + 1;
    const share = stages[stage - 1][1];
    const co2Cost = round(emissions / 1000 * k.co2 * 1.19, 2);
    const landlord = round(co2Cost * share / 100, 2);
    const heatGross = round(heatKwh * k.heat, 2);
    const positions = [
      { label: 'Heizkosten', category: 'heating', amount_eur: heatGross, consumption: heatKwh, unit: 'kWh' },
      { label: 'CO₂-Kostenanteil Vermieter', category: 'heating', amount_eur: -landlord },
      { label: 'Warmwasser', category: 'warm_water', amount_eur: round(warmM3 * k.warm, 2), consumption: warmM3, unit: 'm³' },
      { label: 'Kaltwasser', category: 'cold_water', amount_eur: round(coldM3 * k.cold, 2), consumption: coldM3, unit: 'm³' },
      { label: 'Abwasser', category: 'sewage', amount_eur: round((coldM3 + warmM3) * k.sewage, 2), consumption: round(coldM3 + warmM3, 2), unit: 'm³' },
      ...Object.entries(umlagen[year]).map(([label, eur]) => ({ label, category: 'operating', amount_eur: eur })),
    ];
    const total = round(positions.reduce((t, p) => t + p.amount_eur, 0), 2);
    const prepaid = round(months(from, toExcl).reduce((t, ym) => {
      const pp = validAt(prepayments, ym + '-15');
      return t + pp.heating_eur_month + pp.operating_eur_month;
    }, 0), 2);
    const heatCost = round(heatGross - landlord, 2);
    const heatingNext = heatCost + positions[2].amount_eur;
    const operatingNext = total - heatingNext;
    const [receivedOn, createdAt, nextFrom, booked] = received[year];
    const sid = 's_' + hex(`${P}|statement|${year}`, 12);
    const s = {
      id: sid, tenancy_id: tid, period_from: from, period_to: `${year}-12-31`, received_on: receivedOn,
      total_cost_eur: total, prepaid_eur: prepaid, result_eur: round(total - prepaid, 2),
      positions,
      heat: { consumption: heatKwh, unit: 'kWh', cost_eur: heatCost },
      new_prepayment: { from: nextFrom, heating_eur_month: ceilTo(heatingNext / 12, 1), operating_eur_month: ceilTo(operatingNext / 12, 1) },
      co2: { emissions_kg: emissions, cost_eur: co2Cost, landlord_amount_eur: landlord, stage, landlord_share_pct: share },
      booked, note: '', attachment_ids: [], created_at: createdAt,
    };
    statements.push(s);
    prepayments.push(s.new_prepayment);
    // Preise wie TenancyService::derivePrices, gültig ab dem Tag nach dem Zeitraum
    statementPrices.push({
      from: toExcl,
      heat_eur_per_kwh: round(heatCost / heatKwh, 4),
      warm_water_eur_per_m3: round(positions[2].amount_eur / warmM3, 4),
      cold_water_eur_per_m3: round((positions[3].amount_eur + positions[4].amount_eur) / coldM3, 4),
      source: 'statement', statement_id: sid,
    });
  }
  const fixedCosts = [
    ...Object.entries(umlagen[2024]).map(([label, eur]) => ({ from: '2022-04-01', label, eur_per_year: eur })),
    ...Object.entries(umlagen[2025]).filter(([label, eur]) => umlagen[2024][label] !== eur)
      .map(([label, eur]) => ({ from: '2025-01-01', label, eur_per_year: eur })),
  ];
  const tenancy = {
    id: tid, start: '2022-04-01', end: null, label: 'Wohnung 2. OG links', landlord: 'Wohnbau Musterstadt eG',
    notes: 'Heizung und Warmwasser über die Zentralheizung im Keller (Gas)',
    wohnflaeche_m2: 62, billing_anchor: '01-01',
    prepayments,
    prices: [{ from: '2022-04-01', heat_eur_per_kwh: 0.14, warm_water_eur_per_m3: 10.5, cold_water_eur_per_m3: 5, source: 'estimate' }, ...statementPrices],
    fixed_costs: fixedCosts,
    co2_own_appliances: false, co2_restriction: 'none',
    meter_ids: { heat: [mHeat.id], warm_water: [mWarm.id], cold_water: [mKalt.id] },
  };

  // Strom: eigener Liefervertrag, Abrechnungsjahr ab April
  const stromReadings = readings('strom_wohnung', mStrom, dates, strom, 8214.3, 1);
  const kwhStrom = (a, b) => sum(strom, a, b);
  const c1 = contract('c_strom_basis', mStrom.id, 'Stadtwerke Musterstadt', 'Strom Basis 24', '2022-04-01', '2024-03-31', {
    wp: wp(['2022-04-01', 32.4], ['2023-01-01', 39.8]), bp: bp(['2022-04-01', 10.9], ['2023-01-01', 12.4]), ap: ap(['2022-04-01', 48], ['2023-01-01', 62]),
  });
  const c2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 12', '2024-04-01', '2025-03-31', {
    wp: wp(['2024-04-01', 29.6]), bp: bp(['2024-04-01', 11.5]), ap: ap(['2024-04-01', 47]),
    bonuses: [{ credit_date: '2024-06-15', amount_eur: 60, type: 'neukunde', label: 'Neukundenbonus' }], notice: 1,
  });
  c2.special_payments.push(settlementPayment(`${P}|sp|strom-2025`, '2025-04-17', settle(c2, kwhStrom, '2024-04-01', '2025-04-01'), 'Schlussrechnung Strom Online 12'));
  const c3 = contract('c_strom_online_2025', mStrom.id, 'Beispiel-Energie', 'Strom Online 12 — Verlängerung', '2025-04-01', '2026-03-31', {
    wp: wp(['2025-04-01', 31.2]), bp: bp(['2025-04-01', 11.9]), ap: ap(['2025-04-01', 45]), notice: 1,
    notes: 'Verlängert sich ohne Kündigung, danach monatlich kündbar',
  });
  const s3 = settle(c3, kwhStrom, '2025-04-01', '2026-04-01');
  c3.special_payments.push(settlementPayment(`${P}|sp|strom-2026`, '2026-04-16', s3, 'Jahresabrechnung Strom', ceilTo(s3.cost / 12, 1), '2026-05-01'));

  return {
    id: P,
    settings: {
      ...BASE_SETTINGS,
      active_utilities: ['strom', 'wasser', 'waerme'],
      wohnverhaeltnis: 'miete', wohnflaeche_m2: 62, gebaeudetyp: 'whg', wasser_personen_anzahl: 1,
      waerme_energietraeger: 'gas', warmwasser_energietraeger: 'waerme',
      billing_cycle_anchor_strom: '04-01',
      setup_persona: P,
    },
    reminders: [
      reminder(`${P}|rem|tarif`, 'Stromtarif vergleichen', 'custom', '2026-07-01', 'yearly', null, '2025-07-01', 'Vertrag läuft weiter und ist monatlich kündbar'),
      reminder(`${P}|rem|nka`, 'Nebenkostenabrechnung prüfen', 'custom', '2026-06-20', 'yearly', null, '2025-06-20', 'Belege beim Vermieter einsehen, Einwände innerhalb von zwölf Monaten'),
    ],
    utilities: {
      strom: { meters: [mStrom], readings: stromReadings, contracts: [c1, c2, c3] },
      wasser: {
        meters: [mKalt, mWarm],
        readings: [...readings('wasser_kalt', mKalt, dates, kalt, 41.268, 3), ...readings('wasser_warm', mWarm, dates, warm, 24.517, 3)],
      },
      waerme: { meters: [mHeat], periods },
    },
    tenancies: [tenancy],
    tenancy_statements: statements,
    summary: { strom, kalt, warm, heat },
  };
}

// ═════════════════════════════════════════════════════════════════════════
// Eigentumswohnung mit Fernwärme — 85 m², eigener Fernwärmevertrag
// ═════════════════════════════════════════════════════════════════════════

function etwFernwaerme() {
  const P = 'etw-fernwaerme';
  const r = (k) => seeded(`${P}|${k}`);
  const dates = readingDates(r('dates'));
  const away = [['2023-07-29', '2023-08-12'], ['2024-08-03', '2024-08-17'], ['2025-07-26', '2025-08-09'], ['2025-12-27', '2026-01-02']];

  const rf = r('fernwaerme');
  const fw = series(d => 6600 / AVG_HDD_YEAR * hdd(d) * Math.max(0, 1 + 0.05 * gauss(rf))
    + 4.8 * Math.max(0, 1 + 0.15 * gauss(rf)) * (inRanges(d, away) ? 0.25 : 1));
  const rs = r('strom');
  const strom = scaleTo(series(d => season(d, 0.15) * (1 + 0.09 * gauss(rs)) * (inRanges(d, away) ? 0.4 : 1)), 2350);

  const mFw = meter('m_fernwaerme_wohnung', 'Wärmemengenzähler', '🌡️', 'Wohnungsstation im Flur, Werte aus dem Kundenportal',
    device('d_fernwaerme_wohnung', 'WMZ-2021-5520', '2021-09-01'));
  const mStrom = meter('m_strom_wohnung', 'Stromzähler Wohnung', '⚡', 'Zählerschrank im Keller',
    device('d_strom_wohnung', 'S-2020-917', '2020-11-23'), { malo_id: malo('5987654321') });

  // Fernwärme: Monatserste aus dem Portal, Jahresablesung am 1. Januar
  const fwDates = monthStarts();
  const fwNotes = Object.fromEntries(fwDates.filter(d => d.endsWith('-01-01')).map(d => [d, 'Jahresablesung']));
  const fwReadings = readings('fernwaerme_wohnung', mFw, fwDates, fw, 21480, 1, fwNotes);
  const fwKwh = (a, b) => counterAt(fwReadings, b) - counterAt(fwReadings, a);

  const c = contract('c_fernwaerme', mFw.id, 'Stadtwerke Musterstadt', 'Fernwärme Wohnen', '2021-09-01', null, {
    notes: 'Preise nach Preisänderungsklausel des Versorgers',
    wp: wp(['2021-09-01', 8.9], ['2023-01-01', 15.6], ['2024-01-01', 13.4], ['2025-04-01', 12.1], ['2026-01-01', 12.5]),
    bp: bp(['2021-09-01', 4.2]),
    ap: ap(['2021-09-01', 95], ['2023-03-01', 160]),
    extra: {
      capacity_kw: 6.5,
      capacity_prices: prices('eur_per_kw_year', [['2021-09-01', 46.8], ['2023-01-01', 54], ['2025-04-01', 57.6]]),
      metering_prices: prices('eur_per_year', [['2021-09-01', 84], ['2024-01-01', 96]]),
      co2_g_per_kwh: 198, primary_energy_factor: 0.58,
    },
  });
  c.special_payments.push(specialPayment(`${P}|sp|fw-2023`, '2024-02-16', 'nachzahlung_mit', 186.4, 'Jahresabrechnung 2023 — Nachzahlung, Abschlag angepasst', 165, '2024-03-01'));
  const y2024 = settle(c, fwKwh, '2024-01-01', '2025-01-01');
  c.special_payments.push(settlementPayment(`${P}|sp|fw-2024`, '2025-02-14', y2024, 'Jahresabrechnung 2024', ceilTo(y2024.cost / 12, 5), '2025-03-01'));
  const y2025 = settle(c, fwKwh, '2025-01-01', '2026-01-01');
  const bill = {
    id: 'b_' + hex(`${P}|bill|2025`, 12), meter_id: mFw.id, contract_id: c.id, kind: 'annual',
    period_from: '2025-01-01', period_to: '2025-12-31', issued_on: '2026-02-12',
    invoice: { energy_kwh: round(y2025.kwh, 1), amount_eur: round(y2025.cost, 2), advances_paid_eur: round(y2025.advances, 2), result_eur: round(y2025.cost - y2025.advances, 2) },
    items: [],
    co2: { emissions_kg: round(y2025.kwh * 0.198, 1) },
    attachment_ids: [], note: '', created_at: '2026-02-15T18:40:00+01:00',
  };

  const stromReadings = readings('strom_wohnung', mStrom, dates, strom, 30562.8, 1);
  const kwhStrom = (a, b) => sum(strom, a, b);
  const s1 = contract('c_strom_natur', mStrom.id, 'Grünquell Energie', 'Naturstrom 24', '2023-01-01', '2024-12-31', {
    wp: wp(['2023-01-01', 38.9]), bp: bp(['2023-01-01', 13.9]), ap: ap(['2023-01-01', 92], ['2024-03-01', 86]), notice: 1,
  });
  s1.special_payments.push(settlementPayment(`${P}|sp|strom-2024`, '2025-01-23', settle(s1, kwhStrom, '2024-01-01', '2025-01-01'), 'Schlussrechnung Naturstrom 24'));
  const s2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 12', '2025-01-01', '2025-12-31', {
    wp: wp(['2025-01-01', 28.7]), bp: bp(['2025-01-01', 11.5]), ap: ap(['2025-01-01', 70]), notice: 1,
    bonuses: [{ credit_date: '2025-03-15', amount_eur: 75, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const st2 = settle(s2, kwhStrom, '2025-01-01', '2026-01-01');
  s2.special_payments.push(settlementPayment(`${P}|sp|strom-2025`, '2026-01-21', st2, 'Jahresabrechnung Strom'));
  const s3 = contract('c_strom_online_2026', mStrom.id, 'Beispiel-Energie', 'Strom Online 12 — 2026', '2026-01-01', '2026-12-31', {
    wp: wp(['2026-01-01', 29.5]), bp: bp(['2026-01-01', 11.9]), ap: ap(['2026-01-01', ceilTo(st2.cost / 12, 1)]), notice: 1,
  });

  return {
    id: P,
    settings: {
      ...BASE_SETTINGS,
      active_utilities: ['strom', 'fernwaerme'],
      wohnverhaeltnis: 'eigentum', wohnflaeche_m2: 85, gebaeudetyp: 'whg', wasser_personen_anzahl: 2,
      warmwasser_energietraeger: 'fernwaerme',
      setup_persona: P,
    },
    reminders: [
      reminder(`${P}|rem|eichung`, 'Eichung Wärmemengenzähler', 'waermezaehler_eichung', '2026-09-01', 'custom-months', 60, '2021-09-01', 'Eichfrist nach MessEV'),
      reminder(`${P}|rem|entlueften`, 'Heizkörper entlüften', 'custom', '2026-10-03', 'yearly', null, '2025-10-03', 'Vor Beginn der Heizperiode'),
    ],
    utilities: {
      strom: { meters: [mStrom], readings: stromReadings, contracts: [s1, s2, s3] },
      fernwaerme: { meters: [mFw], readings: fwReadings, contracts: [c], bills: [bill] },
    },
    tenancies: [],
    tenancy_statements: [],
    summary: { fernwaerme: fw, strom },
  };
}

// ═════════════════════════════════════════════════════════════════════════
// Einfamilienhaus klassisch — 140 m², Gas-Brennwertkessel, Garten
// ═════════════════════════════════════════════════════════════════════════

function eigenheimKlassisch() {
  const P = 'eigenheim-klassisch';
  const r = (k) => seeded(`${P}|${k}`);
  const dates = readingDates(r('dates'));
  const away = [['2023-08-05', '2023-08-19'], ['2024-08-10', '2024-08-24'], ['2025-08-02', '2025-08-16']];
  const BALANCED = '2024-09-15';   // hydraulischer Abgleich: Zäsur, danach gut 8 % weniger Heizwärme

  const factors = DEMO.settings.gas_conversion_factors;
  const factorOn = (d) => factors.filter(f => f.from === null || f.from <= d).at(-1).kwh_per_m3;
  const rg = r('gas');
  const gasKwh = series(d => 18000 / AVG_HDD_YEAR * hdd(d) * Math.max(0, 1 + 0.06 * gauss(rg)) * (d >= BALANCED ? 0.92 : 1)
    + 8.4 * Math.max(0, 1 + 0.12 * gauss(rg)) * (inRanges(d, away) ? 0.15 : 1));
  const gasM3 = series(d => gasKwh.get(d) / factorOn(d));
  const rs = r('strom');
  const strom = scaleTo(series(d => season(d, 0.15) * (1 + 0.09 * gauss(rs)) * (inRanges(d, away) ? 0.45 : 1)), 4100);
  const rw = r('wasser');
  const haus = scaleTo(series(d => Math.max(0, 1 + 0.15 * gauss(rw)) * (inRanges(d, away) ? 0.03 : 1)), 158);
  const rgarden = r('garten');
  const garten = scaleTo(series(d => {
    const m = +d.slice(5, 7);
    return m >= 4 && m <= 9 && !inRanges(d, away) && rgarden() < 0.55 ? Math.max(0, temp(d) - 15) : 0;
  }), 22);
  const haupt = add(haus, garten);

  const mGas = meter('m_gas_haus', 'Gaszähler', '🔥', 'Hausanschlussraum im Keller',
    device('d_gas_haus', 'G-2019-0412', '2019-04-10'),
    { baseline_events: [{ date: BALANCED, label: 'Hydraulischer Abgleich' }], malo_id: malo('5111222333') });
  const mStrom = meter('m_strom_haus', 'Hauptzähler Strom', '⚡', 'Keller',
    device('d_strom_haus', 'S-2017-208', '2017-09-05'), { malo_id: malo('5444555666') });
  const mHaupt = meter('m_wasser_haupt', 'Hauptzähler', '💧', 'Keller', device('d_wasser_haupt', 'W-2021-3310', '2021-03-31'));
  const mGarten = { ...meter('m_wasser_garten', 'Gartenzähler', '💧', 'Außenanschluss an der Terrasse', device('d_wasser_garten', 'W-2020-0716', '2020-05-02'), { role: 'garden' }), parent_meter_id: 'm_wasser_haupt' };

  const kwhGas = (a, b) => sum(gasKwh, a, b);
  const g1 = contract('c_gas_klassik', mGas.id, 'Stadtwerke Musterstadt', 'Gas Basis 24', '2022-07-01', '2024-06-30', {
    wp: wp(['2022-07-01', 13.9], ['2023-01-01', 15.2], ['2024-01-01', 11.4]), bp: bp(['2022-07-01', 14.5], ['2024-01-01', 15.9]),
    ap: ap(['2022-07-01', 210], ['2023-03-01', 260]), notice: 3,
  });
  g1.special_payments.push(settlementPayment(`${P}|sp|gas-2024`, '2024-08-06', settle(g1, kwhGas, '2023-07-01', '2024-07-01'), 'Schlussrechnung Gas Basis 24'));
  const g2 = contract('c_gas_fix', mGas.id, 'Energie Nordring', 'Gas Fix 24', '2024-07-01', '2026-06-30', {
    notes: 'Wechsel nach Preisvergleich, Preisgarantie über die ganze Laufzeit',
    wp: wp(['2024-07-01', 9.4]), bp: bp(['2024-07-01', 12.9]), ap: ap(['2024-07-01', 150]), notice: 1, guarantee: '2026-06-30',
    bonuses: [{ credit_date: '2024-10-15', amount_eur: 80, type: 'wechsel', label: 'Wechselbonus' }],
  });
  const gy = settle(g2, kwhGas, '2024-07-01', '2025-07-01');
  g2.special_payments.push(settlementPayment(`${P}|sp|gas-2025`, '2025-07-24', gy, 'Jahresabrechnung Gas', ceilTo(gy.cost / 12, 5), '2025-09-01'));

  const kwhStrom = (a, b) => sum(strom, a, b);
  const s1 = contract('c_strom_klassik', mStrom.id, 'Stadtwerke Musterstadt', 'Strom Klassik', '2023-01-01', '2024-12-31', {
    wp: wp(['2023-01-01', 40.2], ['2024-01-01', 33.8]), bp: bp(['2023-01-01', 13.5]), ap: ap(['2023-01-01', 145], ['2024-03-01', 120]), notice: 1,
  });
  s1.special_payments.push(settlementPayment(`${P}|sp|strom-2024`, '2025-01-20', settle(s1, kwhStrom, '2024-01-01', '2025-01-01'), 'Schlussrechnung Strom Klassik'));
  const s2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 24', '2025-01-01', '2026-12-31', {
    wp: wp(['2025-01-01', 29.4]), bp: bp(['2025-01-01', 12.9]), ap: ap(['2025-01-01', 110]), notice: 1, guarantee: '2026-12-31',
    bonuses: [{ credit_date: '2025-04-15', amount_eur: 120, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const sy = settle(s2, kwhStrom, '2025-01-01', '2026-01-01');
  s2.special_payments.push(settlementPayment(`${P}|sp|strom-2025`, '2026-01-22', sy, 'Jahresabrechnung Strom', ceilTo((sy.cost + sy.bonus) / 12, 5), '2026-03-01'));

  // Wasser: drei Bausteine, Schmutzwasser = Hauptzähler minus Gartenzähler
  const water = (id, year, tariff, end, notes, tw, twBase, sw, rate, advance) => ({
    id, meter_id: mHaupt.id, provider: 'Wasserwerke Musterstadt', tariff_name: tariff, start: `${year}-01-01`, end, notes,
    trinkwasser: { working_prices: prices('ct_per_m3', tw), base_prices: bp([`${year}-01-01`, twBase]) },
    schmutzwasser: { basis: 'trinkwasser_minus_abzug', separater_zaehler_meter_id: null, abzug_meter_ids: [mGarten.id], working_prices: prices('ct_per_m3', sw) },
    niederschlagswasser: { rates: [{ from: `${year}-01-01`, eur_per_m2_year: rate, versiegelte_flaeche_m2: 145 }] },
    advance_payments: ap([`${year}-01-01`, advance]), bonuses: [],
    is_shadow: false, shadow_label: null,
    notice_period_months: null, notice_period_days: null, notice_mode: null, auto_renews: null,
    min_term_end: null, price_guarantee_until: null, signup_bonus_eur: null,
  });

  return {
    id: P,
    settings: {
      ...BASE_SETTINGS,
      gas_conversion_factors: factors,
      active_utilities: ['gas', 'strom', 'wasser'],
      wohnverhaeltnis: 'eigentum', wohnflaeche_m2: 140, gebaeudetyp: 'efh', wasser_personen_anzahl: 4,
      warmwasser_energietraeger: 'gas',
      billing_cycle_anchor_gas: '07-01',
      setup_persona: P,
    },
    reminders: [
      reminder(`${P}|rem|wartung`, 'Heizungswartung', 'heizung_wartung', '2026-09-18', 'yearly', null, '2025-09-18', 'Jahreswartung durch den Installateur'),
      reminder(`${P}|rem|schornstein`, 'Schornsteinfeger', 'schornsteinfeger', '2026-11-05', 'yearly', null, '2025-11-05', 'Kehrung und Abgasmessung'),
      reminder(`${P}|rem|wasser`, 'Eichung Wasserzähler', 'wasserzaehler_eichung', '2027-03-31', 'custom-months', 72, '2021-03-31', 'Eichfrist nach MessEV — Austausch durch den Versorger'),
      reminder(`${P}|rem|gas`, 'Eichung Gaszähler', 'gaszaehler_eichung', '2027-04-10', 'custom-months', 96, '2019-04-10', 'Eichfrist nach MessEV'),
    ],
    utilities: {
      gas: { meters: [mGas], readings: readings('gas_haus', mGas, dates, gasM3, 18437.6, 1), contracts: [g1, g2] },
      strom: { meters: [mStrom], readings: readings('strom_haus', mStrom, dates, strom, 61208.4, 1), contracts: [s1, s2] },
      wasser: {
        meters: [mHaupt, mGarten],
        readings: [...readings('wasser_haupt', mHaupt, dates, haupt, 512.37, 2), ...readings('wasser_garten', mGarten, dates, garten, 61.84, 2)],
        contracts: [
          water('c_wasser_2023', 2023, 'Trink-, Schmutz- und Niederschlagswasser 2023', '2023-12-31', 'Standard-Haushaltstarif', [['2023-01-01', 228]], 7.9, [['2023-01-01', 268]], 1.35, 78),
          water('c_wasser_2024', 2024, 'Trink-, Schmutz- und Niederschlagswasser 2024', '2024-12-31', 'Tarifanpassung 2024', [['2024-01-01', 241]], 8.4, [['2024-01-01', 282]], 1.42, 82),
          water('c_wasser_2025', 2025, 'Trink-, Schmutz- und Niederschlagswasser 2025–2026', '2026-12-31', '', [['2025-01-01', 252], ['2026-01-01', 263]], 8.9, [['2025-01-01', 297], ['2026-01-01', 309]], 1.48, 86),
        ],
      },
    },
    tenancies: [],
    tenancy_statements: [],
    summary: { gas: gasKwh, gas_m3: gasM3, strom, wasser: haupt, garten },
  };
}

// ═════════════════════════════════════════════════════════════════════════
// Einfamilienhaus modern — 150 m², Wärmepumpe, PV 9,8 kWp, Speicher, Wallbox
// ═════════════════════════════════════════════════════════════════════════

function eigenheimModern() {
  const P = 'eigenheim-modern';
  const r = (k) => seeded(`${P}|${k}`);
  const dates = readingDates(r('dates'));
  const away = [['2023-08-19', '2023-09-02'], ['2024-07-20', '2024-08-03'], ['2025-08-09', '2025-08-23']];
  const COMMISSIONED = '2023-04-18';
  const CAPACITY = 10, USABLE = 9.5, ROUND_TRIP = 0.92;

  // Wärmepumpe: Raumwärme nach Heizgradtagen, Warmwasser; Arbeitszahl hängt an der Außentemperatur
  const rh = r('heat');
  const heatSh = series(d => 10200 / AVG_HDD_YEAR * hdd(d) * Math.max(0, 1 + 0.05 * gauss(rh)));
  const heatWw = series(d => 7.6 * Math.max(0, 1 + 0.1 * gauss(rh)) * (inRanges(d, away) ? 0.25 : 1));
  const copSh = (d) => clamp(3.5 + 0.08 * temp(d), 2.4, 4.9);
  const copWw = (d) => clamp(2.6 + 0.03 * temp(d), 2.4, 3.4);
  const wpElec = series(d => heatSh.get(d) / copSh(d) + heatWw.get(d) / copWw(d) + 0.25);
  const wpHeat = add(heatSh, heatWw);

  // Haushalt und Wallbox; PV nach Monat und Wetter
  const rs = r('strom');
  const household = scaleTo(series(d => season(d, 0.18) * (1 + 0.1 * gauss(rs)) * (inRanges(d, away) ? 0.4 : 1)), 3700);
  const re = r('wallbox');
  const WALLBOX = '2023-05-02';
  const evDay = new Map();
  const ev = series(d => {
    const m = +d.slice(5, 7);
    evDay.set(d, m >= 4 && m <= 9 ? 0.55 : 0.15);   // Anteil, der mittags lädt
    if (d < WALLBOX || inRanges(d, away)) return 0;
    return re() < 0.42 ? between(re, 6, 20) * season(d, 0.12) : 0;
  });
  scaleTo(ev, 2500);
  const SHARE = [2.5, 4.5, 8, 11.5, 13, 13, 13, 11.5, 9, 6, 3, 2];
  const shareSum = SHARE.reduce((a, b) => a + b, 0);
  const rp = r('pv');
  const gen = scaleTo(series(d => {
    if (d < COMMISSIONED) return 0;
    return SHARE[+d.slice(5, 7) - 1] / shareSum / daysInMonth(d.slice(0, 7)) * clamp(1 + 0.45 * gauss(rp), 0.2, 1.75);
  }), 9400);

  // Tagesbilanz: direkt verbraucht, Speicher laden/entladen, Rest ins Netz
  const charge = new Map(), discharge = new Map(), feedIn = new Map(), importHh = new Map();
  for (const d of days(SIM_START, END_EXCL)) {
    const g = gen.get(d), h = household.get(d), e = ev.get(d);
    const loadDay = h * 0.45 + e * evDay.get(d);
    const loadNight = h + e - loadDay;
    const direct = Math.min(g, loadDay) * 0.9;
    const surplus = g - direct;
    const c = Math.min(surplus, USABLE, loadNight / ROUND_TRIP);
    charge.set(d, c);
    discharge.set(d, c * ROUND_TRIP);
    feedIn.set(d, surplus - c);
    importHh.set(d, h + e - direct - c * ROUND_TRIP);
  }
  const fromCommissioning = (s) => sum(s, COMMISSIONED, START);

  const mHaus = meter('m_strom_haushalt', 'Haushalt (Bezug)', '⚡', 'Zweirichtungszähler im Zählerschrank',
    device('d_strom_haushalt', 'S-2023-0418', COMMISSIONED), { malo_id: malo('5777888999') });
  const mWp = meter('m_strom_waermepumpe', 'Wärmepumpe', '⚡', 'Eigener Zähler, steuerbare Verbrauchseinrichtung',
    device('d_strom_waermepumpe', 'S-2022-1012', '2022-10-12'), { role: 'heat_pump', heat_source: true, malo_id: malo('5777888000') });
  const mEv = { ...meter('m_strom_wallbox', 'Wallbox', '🔌', 'MID-Zähler in der Wallbox, Ladestrom des Dienstwagens',
    device('d_strom_wallbox', 'EV-2023-0233', WALLBOX), { role: 'ev_charger' }), parent_meter_id: mHaus.id };
  const mGen = meter('m_pv_erzeugung_wr', 'Wechselrichter', '🔆', '9,8 kWp, Ost- und Westdach',
    device('d_pv_erzeugung_wr', 'WR-2023-5501', COMMISSIONED), { investment_eur: 24800, commissioned_on: COMMISSIONED });
  const mCharge = meter('m_pv_speicher_laden', 'Speicher Ladung', '🔋', 'Batteriespeicher im Hauswirtschaftsraum',
    device('d_pv_speicher_laden', 'BAT-2023-0310', COMMISSIONED), { role: 'battery_charge', battery_capacity_kwh: CAPACITY });
  const mDischarge = meter('m_pv_speicher_entladen', 'Speicher Entladung', '🔋', 'Batteriespeicher im Hauswirtschaftsraum',
    device('d_pv_speicher_entladen', 'BAT-2023-0310', COMMISSIONED), { role: 'battery_discharge' });
  const mFeed = meter('m_pv_einspeisung', 'Einspeisezähler', '☀️', 'Zweirichtungszähler, Einspeiseregister',
    device('d_pv_einspeisung', 'S-2023-0418', COMMISSIONED));
  const mHeat = meter('m_waerme_waermepumpe', 'Wärmemengenzähler Wärmepumpe', '♨️', 'Im Vorlauf, misst Heizung und Warmwasser',
    device('d_waerme_waermepumpe', 'WMZ-2022-1012', '2022-10-12'), { role: 'heat_pump_output', heat_pump_meter_ids: [mWp.id] });

  const hh = contract('c_strom_direkt', mHaus.id, 'Energie Nordring', 'Strom Direkt 12', '2023-01-01', '2023-12-31', {
    wp: wp(['2023-01-01', 39.5]), bp: bp(['2023-01-01', 13.9]), ap: ap(['2023-01-01', 95]), notice: 1,
  });
  const hh2 = contract('c_strom_direkt_2024', mHaus.id, 'Energie Nordring', 'Strom Direkt 12 — Verlängerung', '2024-01-01', '2024-12-31', {
    wp: wp(['2024-01-01', 32.9]), bp: bp(['2024-01-01', 13.9]), ap: ap(['2024-01-01', 70]), notice: 1,
  });
  const kwhHh = (a, b) => sum(importHh, a, b);
  hh2.special_payments.push(settlementPayment(`${P}|sp|strom-2024`, '2025-01-24', settle(hh2, kwhHh, '2024-01-01', '2025-01-01'), 'Schlussrechnung Strom Direkt 12'));
  const hh3 = contract('c_strom_online', mHaus.id, 'Beispiel-Energie', 'Strom Online 24', '2025-01-01', '2026-12-31', {
    wp: wp(['2025-01-01', 29.8]), bp: bp(['2025-01-01', 12.9]), ap: ap(['2025-01-01', 62]), notice: 1, guarantee: '2026-12-31',
    bonuses: [{ credit_date: '2025-03-15', amount_eur: 100, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const dyn = contract('c_strom_dynamisch', mHaus.id, 'Beispiel-Energie', 'Strom Dynamisch', '2027-01-01', null, {
    notes: 'Angebot mit Börsenstrompreis, zum Vergleich',
    shadow: 'Strom Dynamisch', notice: 1,
    extra: { price_model: 'dynamic', dynamic: { markup_ct_per_kwh: 19.4, base_eur_month: 11.9, vat_pct: 19, weighting: 'flat' } },
  });
  const wpC = contract('c_strom_waermepumpe', mWp.id, 'Stadtwerke Musterstadt', 'Wärmepumpenstrom', '2022-11-01', null, {
    notes: 'Steuerbare Verbrauchseinrichtung nach § 14a EnWG, Modul 1',
    wp: wp(['2022-11-01', 31.9], ['2023-01-01', 36.4], ['2024-01-01', 27.9], ['2025-01-01', 25.6], ['2026-01-01', 24.9]),
    bp: bp(['2022-11-01', 9.5], ['2024-01-01', 10.9]),
    ap: ap(['2022-11-01', 105], ['2024-03-01', 95], ['2025-03-01', 85]), notice: 1,
    extra: { grid_reduction: [{ from: '2024-04-01', eur_per_year: 152.4, module: 1 }] },
  });
  const eeg = contract('c_pv_eeg', mFeed.id, 'Netzbetrieb Mitte', 'EEG-Einspeisevergütung (IB 04/2023)', COMMISSIONED, '2043-12-31', {
    notes: 'Feste Einspeisevergütung über 20 Jahre nach EEG 2023, §48 (Anlagen ≤ 10 kWp). Nach Ablauf typischerweise Vermarktung über sonstige Direktvermarktung.',
    wp: wp([COMMISSIONED, 8.2]),
  });

  // v3.2.0 (F1022) — Ladevorgänge, wie evcc sie führt: einer je Ladetag, mittags
  // im Sommerhalbjahr, sonst abends; Sonnenanteil aus der Tagesbilanz, Preis
  // wie evcc ihn rechnet (Netzanteil zum Arbeitspreis, Sonne zur Einspeisevergütung)
  const evStart = fromCommissioning(ev);
  const gridCt = (d) => valueOn([hh, hh2, hh3].find(c => c.start <= d && (!c.end || d <= c.end))?.working_prices ?? [], 'ct_per_kwh', d) ?? 0;
  const feedCt = valueOn(eeg.working_prices, 'ct_per_kwh', COMMISSIONED);
  const evSessions = [];
  const rt = r('evcc');
  for (const d of days(START, END_EXCL)) {
    const kwh = ev.get(d);
    if (!(kwh > 0)) continue;
    const midday = evDay.get(d) >= 0.5;
    const loadDay = household.get(d) * 0.45 + kwh * evDay.get(d);
    const solar = clamp(evDay.get(d) * Math.min(gen.get(d), loadDay) * 0.9 / loadDay, 0, 1);
    const startMin = (midday ? 10 * 60 + 30 : 17 * 60 + 15) + Math.floor(between(rt, 0, 50));
    const endMin = startMin + Math.ceil(kwh / (midday ? 5.5 : 11) * 60);
    const hm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}:00`;
    const created = `${d}T${hm(startMin)}${berlinOffset(d)}`;
    const meterStart = evStart + sum(ev, START, d);
    const price = kwh * ((1 - solar) * gridCt(d) + solar * feedCt) / 100;
    evSessions.push({
      id: 'evs_' + createHash('sha1').update(`${created}|Wallbox`).digest('hex').slice(0, 12),
      meter_id: mEv.id, created, finished: `${d}T${hm(endMin)}${berlinOffset(d)}`, date: d,
      loadpoint: 'Wallbox', vehicle: '', charged_kwh: round(kwh, 3), solar_pct: round(solar * 100, 1),
      price_eur: round(price, 2), price_per_kwh: round(price / kwh, 4),
      meter_start: round(meterStart, 3), meter_stop: round(meterStart + kwh, 3), source: 'evcc',
    });
  }

  return {
    id: P,
    ev_sessions: evSessions,
    // Börsenpreise wie nach „Von SMARD laden“ — der dynamische Schattenvertrag rechnet damit
    market_prices: {
      source: 'smard', area: 'DE-LU', unit: 'ct/kWh',
      months: Object.fromEntries(Object.entries(SMARD_MONTHLY).filter(([ym]) => ym <= END.slice(0, 7)).map(([ym, v]) => [ym, { avg_ct: v }])),
      imported_at: EXPORTED_AT,
    },
    settings: {
      ...BASE_SETTINGS,
      active_utilities: ['strom', 'pv_einspeisung', 'pv_erzeugung', 'waerme'],
      wohnverhaeltnis: 'eigentum', wohnflaeche_m2: 150, gebaeudetyp: 'efh', wasser_personen_anzahl: 4,
      warmwasser_energietraeger: 'strom',
      setup_persona: P,
    },
    reminders: [
      reminder(`${P}|rem|wartung`, 'Wartung Wärmepumpe', 'heizung_wartung', '2026-10-08', 'yearly', null, '2025-10-08', 'Jährliche Wartung durch den Fachbetrieb'),
      reminder(`${P}|rem|pv`, 'PV-Anlage prüfen', 'custom', '2027-04-20', 'yearly', null, '2026-04-20', 'Sichtprüfung der Module, Fehlerspeicher des Wechselrichters'),
      reminder(`${P}|rem|eichung`, 'Eichung Stromzähler', 'stromzaehler_eichung', '2030-10-12', 'custom-months', 96, '2022-10-12', 'Eichfrist nach MessEV'),
    ],
    utilities: {
      strom: {
        meters: [mHaus, mWp, mEv],
        readings: [
          ...readings('strom_haushalt', mHaus, dates, importHh, fromCommissioning(importHh), 1),
          ...readings('strom_waermepumpe', mWp, dates, wpElec, 3412.6, 1),
          ...readings('strom_wallbox', mEv, dates, ev, fromCommissioning(ev), 1),
        ],
        contracts: [hh, hh2, hh3, dyn, wpC],
      },
      pv_einspeisung: { meters: [mFeed], readings: readings('pv_einspeisung', mFeed, dates, feedIn, fromCommissioning(feedIn), 1), contracts: [eeg] },
      pv_erzeugung: {
        meters: [mGen, mCharge, mDischarge],
        readings: [
          ...readings('pv_erzeugung_wr', mGen, dates, gen, fromCommissioning(gen), 1),
          ...readings('pv_speicher_laden', mCharge, dates, charge, fromCommissioning(charge), 1),
          ...readings('pv_speicher_entladen', mDischarge, dates, discharge, fromCommissioning(discharge), 1),
        ],
      },
      waerme: { meters: [mHeat], readings: readings('waerme_waermepumpe', mHeat, dates, wpHeat, 11874, 1) },
    },
    tenancies: [],
    tenancy_statements: [],
    summary: { haushalt_bezug: importHh, haushalt_last: household, wallbox: ev, waermepumpe_strom: wpElec, waermepumpe_waerme: wpHeat, pv: gen, einspeisung: feedIn, speicher_laden: charge, speicher_entladen: discharge },
  };
}

// ── Zusammenbau ──────────────────────────────────────────────────────────

function backup(p) {
  const utilities = {};
  for (const u of UTILITIES) {
    utilities[u] = {};
    for (const pot of POTS) utilities[u][pot] = p.utilities[u]?.[pot] ?? [];
  }
  return {
    backup_version: '3.0',
    app_version: PERSONA_VERSION,
    exported_at: EXPORTED_AT,
    meta: { schema_version: SCHEMA, migrated_at: EXPORTED_AT, log: [{ step: 'persona-seed', persona: p.id, version: PERSONA_VERSION }] },
    temperatures: TEMPS,
    settings: p.settings,
    reminders: p.reminders,
    recommendations_dismissed: [],
    attachments: [],
    tenancies: p.tenancies,
    tenancy_statements: p.tenancy_statements,
    market_prices: p.market_prices ?? [],
    ev_sessions: p.ev_sessions ?? [],
    utilities,
  };
}

/** Texte der übersetzbaren Felder (wie DemoDataTranslatorTest sie sammelt). */
function texts(node, out = new Set()) {
  if (Array.isArray(node)) node.forEach(v => texts(v, out));
  else if (node && typeof node === 'object') {
    for (const [k, v] of Object.entries(node)) {
      if (typeof v === 'string' && TEXT_FIELDS.includes(k) && v.trim() !== '') out.add(v);
      else if (v && typeof v === 'object') texts(v, out);
    }
  }
  return out;
}

/** Eckwerte je Kalenderjahr (nur zur Kontrolle beim Bauen). */
function yearly(s) {
  const out = {};
  for (const y of ['2024', '2025']) out[y] = Math.round(sum(s, `${y}-01-01`, `${+y + 1}-01-01`));
  return out;
}

const translations = readJson('demo-data/translations.json').strings ?? {};
const missing = new Set();
let changed = 0;
if (!CHECK) mkdirSync(OUT_DIR, { recursive: true });
for (const build of [mieterin, etwFernwaerme, eigenheimKlassisch, eigenheimModern]) {
  const p = build();
  const payload = backup(p);
  const json = JSON.stringify(payload, null, 4) + '\n';
  const file = join(OUT_DIR, `${p.id}.json`);
  const same = existsSync(file) && readFileSync(file, 'utf8') === json;
  if (CHECK) {
    if (!same) { console.error(`✗ ${p.id}: weicht vom Generator ab`); changed++; }
  } else if (!same) {
    writeFileSync(file, json);
    changed++;
  }
  for (const t of texts({ utilities: payload.utilities, reminders: payload.reminders, tenancies: payload.tenancies, tenancy_statements: payload.tenancy_statements })) {
    if (!translations[t]) missing.add(t);
  }
  const eck = Object.entries(p.summary).map(([k, s]) => `${k} ${JSON.stringify(yearly(s))}`).join(' · ');
  console.log(`${p.id}: ${START} … ${END} — ${eck}`);
}
if (missing.size) {
  console.warn(`\nOhne Übersetzung in demo-data/translations.json (${missing.size}):`);
  for (const t of missing) console.warn(`  ${t}`);
}
if (CHECK && changed) process.exit(1);
console.log(CHECK ? 'Personas aktuell.' : `${changed} Datei(en) geschrieben nach demo-data/personas/.`);
