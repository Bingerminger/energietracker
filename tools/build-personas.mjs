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

   v3.5.0 (N1026) — dazu je Haushalt: Verlauf „Erledigt“ (activity, erfundene
   Personen), Termine mit Zuständigkeit (assignee), Versorgerrechnungen wie die
   App sie nachrechnet (bills) und eine beendete Periode ohne Rechnung
   (F1029; wo nötig mit Stichtag relativ zu heute über demo_align),
   Folgeverträge; im Haushalt mit Sonnenstrom Viertelstunden, die je
   Viertelstunde aufgehen (dispatchDay). Außerdem ergänzt das Skript das
   Schaufenster um dieselben Anwendungsfälle (showcase()) — Backup und
   Verzeichnisform, alles andere bleibt byte-gleich.
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
/**
 * v3.3.0 (F1021) — § 14a Modul 3 im Haushalt mit Wärmepumpe: Netzentgelt
 * (Arbeitspreis) Standard 8,1 ct, Niedrigtarif 00–06 Uhr ganzjährig 2,4 ct,
 * Hochtarif 17–20 Uhr im ersten und vierten Quartal 11,9 ct (erfundene Werte
 * in der Größenordnung veröffentlichter Preisblätter).
 */
const GRID_TOU = {
  standard_ct: 8.1,
  windows: [
    { quarters: [1, 2, 3, 4], from: '00:00', to: '06:00', ct: 2.4 },
    { quarters: [1, 4], from: '17:00', to: '20:00', ct: 11.9 },
  ],
};
/** Börsenpreise 2025 je Viertelstunde (SMARD, CC BY 4.0) — Quelle für die Beispieldaten. */
const SMARD_QH_2025 = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', 'demo-data', 'sources', 'smard-day-ahead-de-lu-2025-qh.json'), 'utf8'));

/** Beginn der Sommerzeit (UTC) — letzter Sonntag im März/Oktober, 01:00 UTC. */
const dstUtc = (y, m) => { const d = new Date(Date.UTC(y, m, 0)); d.setUTCDate(d.getUTCDate() - d.getUTCDay()); d.setUTCHours(1); return d.getTime() / 1000; };
const berlinAt = (ts) => {
  const y = new Date(ts * 1000).getUTCFullYear();
  const off = ts >= dstUtc(y, 3) && ts < dstUtc(y, 10) ? 7200 : 3600;
  const l = new Date((ts + off) * 1000);
  return { day: l.toISOString().slice(0, 10), hour: l.getUTCHours(), minute: l.getUTCHours() * 60 + l.getUTCMinutes(), off };
};
/** Plätze eines Jahres (1.1. 00:00 MEZ bis 1.1. 00:00 MEZ) mit Ortszeit je Platz. */
const yearSlots = (year) => {
  const start = Date.UTC(year - 1, 11, 31, 23) / 1000;
  const n = (Date.UTC(year, 11, 31, 23) / 1000 - start) / 900;
  return { start, n, clock: Array.from({ length: n }, (_, i) => ({ ts: start + i * 900, ...berlinAt(start + i * 900) })) };
};

/**
 * Viertelstundenreihen eines Jahres wie IntervalService sie ablegt: Index
 * {series, samples} und je Reihe {id, kind, year, step, start, values}. Eine
 * Reihe aus Tageswerten verteilt jeden Tag nach `shape(stunde)`, eine aus
 * `slots` übernimmt UTC-Viertelstunde → kWh, eine mit `values` ist fertig
 * gerechnet (je Platz des Jahres).
 */
function intervalSeries(year, defs) {
  const { start, n, clock } = yearSlots(year);
  const isoAt = (ts) => { const c = berlinAt(ts); const l = new Date((ts + c.off) * 1000).toISOString().slice(0, 19); return `${l}${c.off === 7200 ? '+02:00' : '+01:00'}`; };
  const series = [], files = {};
  const add = (meta, values, unit, decimals) => {
    const filled = values.reduce((k, v) => k + (v === null ? 0 : 1), 0);
    const first = values.findIndex(v => v !== null);
    let last = -1; for (let i = values.length - 1; i >= 0; i--) if (values[i] !== null) { last = i; break; }
    const vals = values.map(v => (v === null ? null : round(v, decimals)));
    files[meta.id] = { id: meta.id, kind: meta.kind, year, step: 900, start: isoAt(start), values: vals };
    series.push({ ...meta, unit, year, step: 900, slots: n, filled,
      from: first >= 0 ? isoAt(start + first * 900) : null, to: last >= 0 ? isoAt(start + (last + 1) * 900) : null,
      source: meta.kind === 'price' ? 'smard' : 'import', updated_at: EXPORTED_AT });
  };
  for (const d of defs) {
    let values;
    if (d.values) {
      values = d.values;
    } else if (d.slots) {
      values = clock.map((_, i) => d.slots.get(start + i * 900) ?? 0);
    } else {
      const wsum = new Map();
      clock.forEach(c => wsum.set(c.day, (wsum.get(c.day) ?? 0) + d.shape(c.hour)));
      values = clock.map(c => { const w = wsum.get(c.day); return w > 0 ? (d.daily.get(c.day) ?? 0) * d.shape(c.hour) / w : 0; });
    }
    const id = `e-${d.utility}-${d.meter}-${year}`.replace(/[^A-Za-z0-9_.-]/g, '-').toLowerCase();
    add({ id, kind: 'energy', utility: d.utility, meter_id: d.meter }, values, 'kWh', 4);
  }
  if (SMARD_QH_2025.year === year && SMARD_QH_2025.values.length === n) {
    add({ id: `p-de-lu-${year}`, kind: 'price', area: 'DE-LU' }, SMARD_QH_2025.values, 'ct/kWh', 3);
  }
  return { index: { series, samples: {} }, files };
}
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

// ── Viertelstunden: Sonnenlauf, Lastgang, Verteilung eines Tages ──────────
//
// v3.5.0 (N1026) — für den Tagesfilm (F1027) muss jede Viertelstunde für sich
// aufgehen: Erzeugung = selbst genutzt + Einspeisung + Laden, Hausverbrauch =
// Netzbezug + selbst genutzt + Entladen. Die Tagessummen bleiben die des
// Tagesmodells (damit die Viertelstunden zwischen zwei Ablesungen genau die
// Differenz der Stände ergeben); verteilt wird je Tag:
//
//   Erzeugung   nach Sonnenhöhe am Ort der Demo (Tageslänge und Mittag je
//               Datum), mit Wolkenrauschen — je trüber der Tag, desto unruhiger
//   Haushalt    Lastgang mit Morgen-, Mittags- und Abendspitze; ein Anteil folgt
//               der Sonne (Geräte mit Startzeit), so groß, dass genau der
//               Eigenverbrauch des Tagesmodells zusammenkommt
//   Wallbox     aus ihren Ladevorgängen
//   Speicher    lädt vom Überschuss ab dem Morgen (höchstens 4 kW), entlädt
//               nur ins Haus (nicht ins Auto, s. F1025), erst abends, dann in
//               den frühen Morgenstunden
//
// Netzbezug und Einspeisung fallen so nie in dieselbe Viertelstunde, der
// Speicher lädt und entlädt nie gleichzeitig.

const LAT = DEMO.settings.latitude * Math.PI / 180;
const LON = DEMO.settings.longitude;
/** Sinus der Sonnenhöhe in der Mitte einer Viertelstunde (Beginn in UTC-Sekunden). */
function sunSin(ts) {
  const t = new Date((ts + 450) * 1000);
  const n = Math.floor((t.getTime() - Date.UTC(t.getUTCFullYear(), 0, 1)) / 86400000) + 1;
  const decl = 23.44 * Math.PI / 180 * Math.sin(2 * Math.PI * (284 + n) / 365);
  const b = 2 * Math.PI * (n - 81) / 364;
  const eot = 9.87 * Math.sin(2 * b) - 7.53 * Math.cos(b) - 1.5 * Math.sin(b);   // Zeitgleichung (min)
  const solarMin = t.getUTCHours() * 60 + t.getUTCMinutes() + t.getUTCSeconds() / 60 + 4 * LON + eot;
  const ha = (solarMin / 4 - 180) * Math.PI / 180;
  return Math.sin(LAT) * Math.sin(decl) + Math.cos(LAT) * Math.cos(decl) * Math.cos(ha);
}
/** Haushalt ohne Wallbox: Gewicht je Stunde (Ortszeit), dazwischen linear. */
const HOUSE_HOURLY = [0.42, 0.38, 0.36, 0.35, 0.36, 0.42, 0.7, 1.15, 1.25, 1.0, 0.88, 0.92,
  1.05, 0.95, 0.85, 0.85, 0.95, 1.25, 1.6, 1.7, 1.55, 1.3, 0.95, 0.62];
const houseAt = (minute) => {
  const x = minute / 60 + 0.125 - 0.5;   // Mitte der Viertelstunde, Stützstellen in der Stundenmitte
  const i = Math.floor(x), f = x - i;
  return HOUSE_HOURLY[(i + 24) % 24] * (1 - f) + HOUSE_HOURLY[(i + 25) % 24] * f;
};
/** Leistungsgrenze des Speichers je Viertelstunde (4 kW). */
const BATTERY_SLOT_KWH = 1.0;

/**
 * Ein Tag in Viertelstunden. `slots`: [{ts, minute}] des Tages; Tageswerte G
 * (Erzeugung), H (Haushalt ohne Wallbox), D (selbst genutzt), C (geladen),
 * X (entladen); `ev(ts)` die Wallbox je Viertelstunde. Liefert je Platz gen,
 * feed, charge, discharge, grid (Netzbezug des Haushaltszählers) und die
 * Abweichungen, falls eine Tagessumme nicht unterzubringen war.
 */
function dispatchDay(slots, G, H, D, C, X, ev, rand) {
  const n = slots.length;
  // Erzeugung: Sonnenhöhe, Wolken als geglättetes Rauschen
  const clear = slots.map(s => Math.max(0, sunSin(s.ts) - 0.02) ** 1.15);
  const clearSum = clear.reduce((a, b) => a + b, 0);
  const amp = clamp(0.42 - 0.3 * (G / Math.max(1e-9, clearSum * 2.2)), 0.04, 0.38);
  let ar = 0;
  const cloud = slots.map(() => { ar = 0.85 * ar + 0.53 * gauss(rand); return Math.max(0.08, 1 + amp * ar); });
  const sunW = clear.map((c, i) => c * cloud[i]);
  const sunSum = sunW.reduce((a, b) => a + b, 0);
  const sun = sunW.map(w => (sunSum > 0 ? w / sunSum : 0));
  const gen = sun.map(s => G * s);
  // Haushalt: Lastgang mit Rauschen; der sonnengeführte Anteil a ergibt den Eigenverbrauch
  let nr = 0;
  const baseW = slots.map(s => { nr = 0.6 * nr + 0.8 * gauss(rand); return houseAt(s.minute) * Math.max(0.35, 1 + 0.18 * nr); });
  const baseSum = baseW.reduce((a, b) => a + b, 0);
  const base = baseW.map(w => w / baseSum);
  const evs = slots.map(s => ev(s.ts));
  // a > 0: ein Teil folgt der Sonne; a < 0: ein Teil liegt außerhalb der Sonnenstunden (werktags außer Haus)
  const peak = Math.max(1e-9, ...clear);
  const antiW = base.map((b, i) => b * (1 - clear[i] / peak));
  const antiSum = antiW.reduce((x, y) => x + y, 0);
  const anti = antiW.map(w => (antiSum > 0 ? w / antiSum : 0));
  const hhOf = (a) => base.map((b, i) => H * (a >= 0 ? (1 - a) * b + a * sun[i] : (1 + a) * b - a * anti[i]));
  const overlap = (a) => { const hh = hhOf(a); let t = 0; for (let i = 0; i < n; i++) t += Math.min(gen[i], hh[i] + evs[i]); return t; };
  let lo = -1, hi = 1, a;
  if (overlap(lo) >= D) a = lo;
  else if (overlap(hi) < D) a = hi;
  else { for (let k = 0; k < 40; k++) { const m = (lo + hi) / 2; if (overlap(m) >= D) hi = m; else lo = m; } a = hi; }
  const hh = hhOf(a);
  const full = overlap(a);
  // gekürzt wird nur, wenn selbst a = −1 mehr Überdeckung ergibt als das Tagesmodell vorsieht
  // (Lastspitzen innerhalb der Viertelstunde: Bezug und Einspeisung in derselben Viertelstunde)
  const scale = full > 0 && D / full < 0.9999 ? D / full : (full > 0 ? 1 : 0);
  const direct = hh.map((h, i) => Math.min(gen[i], h + evs[i]) * scale);
  const surplus = direct.map((d, i) => (gen[i] - d > 1e-7 ? gen[i] - d : 0));
  // vom Eigenverbrauch zuerst der Haushalt, der Rest ins Auto; der Speicher deckt nur den Haushalt
  const hhGap = direct.map((d, i) => Math.max(0, hh[i] - Math.min(d, hh[i])));
  // Laden: ab dem Morgen aus dem Überschuss
  const charge = new Array(n).fill(0);
  let rest = C;
  for (let i = 0; i < n && rest > 1e-12; i++) {
    const q = Math.min(surplus[i], BATTERY_SLOT_KWH, rest);
    charge[i] = q; rest -= q;
  }
  for (let i = 0; i < n && rest > 1e-12; i++) {        // Rest ohne Leistungsgrenze (sehr kurze Tage)
    const q = Math.min(surplus[i] - charge[i], rest);
    charge[i] += q; rest -= q;
  }
  const chargeShort = rest;
  // Entladen: abends nach dem letzten Überschuss, dann früh vor dem ersten, dann Lücken am Tag
  const firstSun = surplus.findIndex(s => s > 1e-9);
  let lastSun = -1; for (let i = n - 1; i >= 0; i--) if (surplus[i] > 1e-9) { lastSun = i; break; }
  const order = firstSun < 0 ? [...Array(n).keys()]
    : [...Array.from({ length: n - lastSun - 1 }, (_, k) => lastSun + 1 + k), ...Array.from({ length: firstSun }, (_, k) => k),
       ...Array.from({ length: lastSun - firstSun + 1 }, (_, k) => firstSun + k)];
  const discharge = new Array(n).fill(0);
  rest = X;
  for (const i of order) {
    if (rest <= 1e-12) break;
    if (charge[i] > 0) continue;
    const q = Math.min(hhGap[i], BATTERY_SLOT_KWH, rest);
    discharge[i] = q; rest -= q;
  }
  // Reicht der Haushalt allein nicht (Wintertage mit viel Eigenverbrauch am Tag),
  // deckt der Speicher den Rest des Abends, solange das Haus Strom braucht
  for (const i of order) {
    if (rest <= 1e-12) break;
    if (charge[i] > 0) continue;
    const q = Math.min(hh[i] + evs[i] - direct[i] - discharge[i], BATTERY_SLOT_KWH - discharge[i], rest);
    if (q > 0) { discharge[i] += q; rest -= q; }
  }
  const dischargeShort = rest;
  const feed = surplus.map((s, i) => Math.max(0, s - charge[i]));
  const grid = hh.map((h, i) => Math.max(0, h + evs[i] - direct[i] - discharge[i]));
  return { gen, feed, charge, discharge, grid, a, scale, directShort: Math.max(0, D - full * scale), chargeShort, dischargeShort };
}

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
    auto_renews: o.renews ?? null, min_term_end: o.minTerm ?? null, price_guarantee_until: o.guarantee ?? null, signup_bonus_eur: null,
  };
}
/** v3.5.0 (N1026) — Ablesetage ohne den Tag nahe `around` (Lücke, etwa nach dem Urlaub). */
function withoutReading(dates, around) {
  const i = dates.reduce((best, d, k) => (Math.abs(daysBetween(d, around)) < Math.abs(daysBetween(dates[best], around)) ? k : best), 0);
  return { dates: dates.filter((_, k) => k !== i), before: dates[i - 1], after: dates[i + 1] };
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
  ...(c.special_payments ?? []).filter(s => s.new_advance_eur != null).map(s => ({ from: s.advance_from, amount_eur: s.new_advance_eur })),
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
/**
 * v3.5.0 (N1026) — Wasser über ganze Monate wie ConsumptionService::waterRowCosts:
 * Trinkwasser (Arbeits- und Grundpreis), Schmutzwasser nach Abzug des
 * Gartenzählers und Niederschlagswasser. v3.6.0 — seit die App das
 * Schmutzwasser mit Abzugszähler nachrechnet, gehört es zum Betrag (bis v3.5
 * ein Posten auf der Rechnung). `kwh` ist hier die Menge in m³.
 */
function waterSettle(c, haupt, abzug, from, toExcl) {
  let m3 = 0, energy = 0, fixed = 0, sewage = 0, advances = 0;
  for (const ym of months(from, toExcl)) {
    const a = ym + '-01', b = addMonths(a, 1);
    const q = haupt(a, b);
    m3 += q;
    energy += q * valueOn(c.trinkwasser.working_prices, 'ct_per_m3', a) / 100;
    const rate = validAt(c.niederschlagswasser.rates, a);
    fixed += (valueOn(c.trinkwasser.base_prices, 'eur_per_month', a) ?? 0) + (rate ? rate.eur_per_m2_year * rate.versiegelte_flaeche_m2 / 12 : 0);
    sewage += Math.max(0, q - abzug(a, b)) * valueOn(c.schmutzwasser.working_prices, 'ct_per_m3', a) / 100;
    advances += valueOn(c.advance_payments, 'amount_eur', a) ?? 0;
  }
  const cost = energy + sewage + fixed;
  return { kwh: m3, energy: energy + sewage, fixed, bonus: 0, cost, advances, result: cost - advances, sewage };
}

/** Jahresabrechnung als Sonderzahlung: Rückzahlung oder Nachzahlung, mit oder ohne neuen Abschlag. */
function settlementPayment(key, date, s, label, newAdvance = null, advanceFrom = null) {
  const refund = s.result < 0;
  const mit = newAdvance !== null;
  const kind = (refund ? 'rueckzahlung' : 'nachzahlung') + (mit ? '_mit' : '_ohne');
  const note = `${label} — ${refund ? 'Guthaben' : 'Nachzahlung'}${mit ? ', Abschlag angepasst' : ''}`;
  return specialPayment(key, date, kind, Math.abs(s.result), note, newAdvance, advanceFrom);
}

/** Termin; v3.5.0 (F1028) — `assignee`: zuständig (freier Name; die Demo hat keine Personen-IDs). */
function reminder(key, title, category, nextDue, recurrence, recurrenceMonths, lastDone, notes, assignee = null) {
  return { id: 'rem_' + hex(key, 8), title, category, next_due: nextDue, recurrence, recurrence_months: recurrenceMonths,
    last_done: lastDone, notes, active: true, ...(assignee ? { assignee } : {}) };
}

/** Marktlokations-ID mit Prüfziffer nach BDEW (MeterService::isValidMalo) — synthetisch. */
function malo(first10) {
  let odd = 0, even = 0;
  for (let i = 0; i < 10; i++) { if (i % 2 === 0) odd += +first10[i]; else even += +first10[i]; }
  return first10 + String((10 - ((odd + 2 * even) % 10)) % 10);
}

// ── v3.5.0 (N1026) — Versorgerrechnungen, Termine mit Zuständigkeit, Verlauf ──

/** Verbrauch zwischen zwei Tagen aus den Ständen, linear wie die App (BillService/billBreakdown). */
const linearKwh = (rs) => (a, b) => counterAt(rs, b) - counterAt(rs, a);
/** Gas: m³ aus den Ständen × Faktor des Abschnitts (geteilt an den Stichtagen der Faktoren). */
function gasLinearKwh(rs, factors) {
  const factorOn = (d) => factors.filter(f => f.from === null || f.from <= d).at(-1).kwh_per_m3;
  const cuts = factors.map(f => f.from).filter(Boolean);
  return (a, b) => {
    const pts = [...new Set([a, ...cuts.filter(c => c > a && c < b), b])].sort();
    let t = 0;
    for (let i = 0; i + 1 < pts.length; i++) t += (counterAt(rs, pts[i + 1]) - counterAt(rs, pts[i])) * factorOn(pts[i]);
    return t;
  };
}
const stamp = (date, hm) => `${date}T${hm}:00${berlinOffset(date)}`;

/**
 * Versorgerrechnung wie BillService sie ablegt. `s` ist die Abrechnung aus
 * settle() über denselben Zeitraum (mit linearen Mengen wie die App), so dass
 * „Rechnung prüfen“ passt; `o.items` sind Posten, die die App nicht nachbildet.
 */
function supplierBill(key, meterId, c, kind, from, toExcl, issuedOn, s, o = {}) {
  const items = o.items ?? [];
  const amount = round(s.cost + items.reduce((t, x) => t + x.amount_eur, 0), 2);
  const advances = round(s.advances, 2);
  return {
    id: 'b_' + hex(key, 12), meter_id: meterId,
    period_from: from, period_to: addDays(toExcl, -1), issued_on: issuedOn, kind,
    contract_id: c.id,
    ...(o.sp ? { special_payment_id: o.sp.id } : {}),
    invoice: { [o.water ? 'volume_m3' : 'energy_kwh']: round(s.kwh, o.water ? 2 : 1), amount_eur: amount,
      advances_paid_eur: advances, result_eur: round(amount - advances, 2) },
    items,
    co2: o.co2 ?? null,
    note: o.note ?? '',
    attachment_ids: [],
    created_at: stamp(addDays(issuedOn, o.enteredAfter ?? 2), '19:12'),
  };
}

/** Katalog der Oberfläche (deutsch) für die Momentaufnahme `title` im Verlauf. */
const DE = JSON.parse(readFileSync(join(ROOT, 'public', 'locales', 'de.json'), 'utf8'));
/** Schlüssel, die erst mit F1029 in den Katalog kommen — deutscher Ersatz für die Momentaufnahme. */
const TITLE_FALLBACK = {
  'calendar.event.billExpected': '{label}: Abrechnung erwartet ({provider})',
  'calendar.event.finalBillExpected': '{label}: Schlussrechnung erwartet ({provider})',
};
const deDate = (iso) => `${iso.slice(8, 10)}.${iso.slice(5, 7)}.${iso.slice(0, 4)}`;
function deTitle(key, params) {
  const raw = key.split('.').reduce((n, k) => (n && typeof n === 'object' ? n[k] : undefined), DE) ?? TITLE_FALLBACK[key];
  if (typeof raw !== 'string') throw new Error(`Katalogschlüssel fehlt: ${key}`);
  return raw.replace(/\{(\w+)\}/g, (_, k) => {
    const v = params[k];
    return typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) ? deDate(v) : String(v ?? '');
  });
}
const UTILITY_LABEL = Object.fromEntries(Object.entries(DE.utilityNames));

/**
 * Verlauf „Erledigt“ (F1024) eines Haushalts. Ein Eintrag wie ActivityService
 * ihn schreibt; Personen tragen eine erfundene ID (wie aus auth.json) und einen
 * Namen. Einträge zu Terminen verschiebt DemoDataAligner mit den Terminen
 * (relativ zu heute), alle anderen bleiben am Datum ihrer Daten (Verträge,
 * Rechnungen, Ablesungen, Abrechnungen, Empfehlungen zu einem Monat).
 */
function activityLog(P) {
  const out = [];
  const person = (name) => ({ type: 'person', id: 'u_' + hex(`${P}|person|${name}`, 8), name });
  const entry = (key, e) => {
    const by = e.by === 'auto' ? { type: 'auto', ...(e.reason ? { reason: e.reason } : {}) } : person(e.by);
    const x = {
      id: 'act_' + hex(`${P}|act|${key}`, 12), action: e.action,
      subject: e.subject, ref: e.ref,
      title_key: e.titleKey ?? null, params: e.params ?? {},
      title: e.title ?? deTitle(e.titleKey, e.params ?? {}),
      done_on: e.on, recorded_at: stamp(e.on, e.at ?? '19:05'),
      by, note: e.note ?? '',
      ...(e.reason ? { reason: e.reason } : {}),
      ...(e.revert ? { revert: e.revert } : {}),
      ...(e.undone ? { undone: { at: stamp(e.undone.on, e.undone.at ?? '19:20'), by: person(e.undone.by) } } : {}),
    };
    out.push(x);
    return x;
  };
  const occ = (uid, anchor) => `${uid}@${anchor}`;
  return {
    entries: out,
    /** Termin von Hand erledigt; `due` = Fälligkeit davor, `next` = danach. */
    reminder(r, on, by, o = {}) {
      const uid = 'reminder-' + r.id;
      return entry(`rem|${r.id}|${on}`, {
        action: 'done', on, by, note: o.note, at: o.at, undone: o.undone,
        subject: { kind: 'reminder', uid, occurrence: occ(uid, o.due) },
        ref: { reminder_id: r.id, category: r.category },
        titleKey: 'calendar.event.reminder', params: { title: r.title },
        revert: { type: 'reminder', id: r.id, before: { next_due: o.due, active: true, last_done: o.prev ?? null },
          after: { next_due: o.next, active: true, last_done: on } },
      });
    },
    /** Kündigungsstichtag von Hand erledigt, „gekündigt“: der Vertrag verlängert sich nicht. */
    cancelled(u, c, on, by, o = {}) {
      const uid = 'cancel_by-' + c.id;
      c.auto_renews = false;
      return entry(`cancel|${c.id}`, {
        action: 'done', on, by, note: o.note,
        subject: { kind: 'cancel_by', uid, occurrence: occ(uid, c.end) },
        ref: { utility: u, meter_id: c.meter_id, contract_id: c.id, cancelled: true },
        titleKey: 'calendar.event.cancelBy', params: { label: UTILITY_LABEL[u], provider: c.provider },
        revert: { type: 'contract_auto_renews', utility: u, id: c.id, before: null, after: false },
      });
    },
    /** Lückenloser Folgevertrag angelegt (ActivityService::successorRecorded). */
    successor(u, pred, succ, on, by, o = {}) {
      const uid = 'contract-' + pred.id;
      const reason = { key: 'activity.reason.successor', params: { provider: succ.provider, date: succ.start } };
      return entry(`succ|${pred.id}`, {
        action: 'auto_done', on, by, reason,
        subject: { kind: 'contract', uid, occurrence: occ(uid, o.renewed ? 'renewed' : pred.end) },
        ref: { utility: u, meter_id: pred.meter_id, contract_id: pred.id, successor_id: succ.id },
        titleKey: 'calendar.event.termEnd', params: { label: UTILITY_LABEL[u], provider: pred.provider },
      });
    },
    /** Versorgerrechnung erfasst — „Abrechnung erwartet“ erledigt (F1029, BillExpectedService). */
    bill(u, c, b, final, by) {
      const uid = `bill_expected-${c.id}-${b.period_to}`;
      return entry(`bill|${b.id}`, {
        action: 'auto_done', on: b.issued_on, by,
        reason: { key: 'activity.reason.bill', params: { date: b.period_to } },
        subject: { kind: 'bill_expected', uid, occurrence: occ(uid, b.period_to) },
        ref: { utility: u, meter_id: b.meter_id, contract_id: c.id, bill_id: b.id },
        titleKey: final ? 'calendar.event.finalBillExpected' : 'calendar.event.billExpected',
        params: { label: UTILITY_LABEL[u], provider: c.provider, from: b.period_from, until: b.period_to },
      });
    },
    /** Ablesung nach langer Pause erfasst (ActivityService::readingRecorded). */
    reading(u, m, prevLast, date, by) {
      const uid = `reading-${u}-${m.id}`;
      return entry(`read|${m.id}|${date}`, {
        action: 'auto_done', on: date, by, at: '08:40',
        reason: { key: 'activity.reason.reading', params: { date } },
        subject: { kind: 'reading_due', uid, occurrence: occ(uid, prevLast) },
        ref: { utility: u, meter_id: m.id },
        titleKey: 'calendar.event.readingDue', params: { label: UTILITY_LABEL[u], meter: m.name },
      });
    },
    /** Nebenkostenabrechnung erfasst (ActivityService::statementRecorded). */
    statement(t, s, by) {
      const uid = `tenancy_statement_due-${t.id}-${s.period_to}`;
      return entry(`stmt|${s.id}`, {
        action: 'auto_done', on: s.received_on, by,
        reason: { key: 'activity.reason.statement', params: { date: s.period_to } },
        subject: { kind: 'tenancy_statement_due', uid, occurrence: occ(uid, s.period_to) },
        ref: { tenancy_id: t.id, statement_id: s.id },
        titleKey: 'calendar.event.tenancyStatementDue', params: { label: t.label, period: s.period_to },
      });
    },
    /** Nebenkostenabrechnung nachträglich geändert (ActivityService::statementChanged, Aktion `updated`). */
    statementChanged(t, s, on, by) {
      const uid = `tenancy_statement-${s.id}`;
      return entry(`stmtchg|${s.id}|${on}`, {
        action: 'updated', on, by,
        subject: { kind: 'tenancy_statement', uid, occurrence: occ(uid, s.period_to) },
        ref: { tenancy_id: t.id, statement_id: s.id },
        titleKey: 'activity.changed.statement', params: { label: t.label, from: s.period_from, to: s.period_to },
      });
    },
    /** Lieferung bei niedrigem Bestand erfasst (ActivityService::deliveryRecorded). */
    delivery(u, m, del, prevLatest, by) {
      const uid = `tank_reorder-${u}-${m.id}`;
      return entry(`del|${del.id}`, {
        action: 'auto_done', on: del.date, by,
        reason: { key: 'activity.reason.delivery', params: { date: del.date } },
        subject: { kind: 'tank_reorder', uid, occurrence: occ(uid, prevLatest) },
        ref: { utility: u, meter_id: m.id, delivery_id: del.id },
        titleKey: 'calendar.event.tankReorder', params: { label: UTILITY_LABEL[u], meter: m.name },
      });
    },
    /**
     * Empfehlung ausgeblendet (ActivityService::dismissed), 30 Tage; Titel wie
     * die Empfehlung damals. `rule`/`ctx` wie RecommendationService (ID).
     */
    dismissed(rule, ctx, title, on, by, o = {}) {
      const id = ruleId(rule, ctx);
      const until = addDays(on, 30);
      const [utility, meterId, third] = ctx;
      return entry(`dismiss|${id}|${on}`, {
        action: 'dismissed', on, by, note: o.note, at: o.at, undone: o.undone, title,
        reason: { key: 'activity.reason.dismissed', params: { date: until } },
        subject: { kind: 'recommendation', uid: id, occurrence: occ(id, until) },
        ref: { recommendation_id: id, utility, meter_id: meterId, ...(rule === 'r6' ? { contract_id: third } : {}) },
        revert: { type: 'recommendation_dismiss', id, until },
      });
    },
  };
}
/** Stabile ID einer Empfehlung wie RecommendationService::ruleId. */
const ruleId = (rule, ctx) => `${rule}_${createHash('md5').update(rule + '|' + ctx.join('|')).digest('hex').slice(0, 12)}`;
/** Nächste Fälligkeit wie ReminderService::addMonths (Tag auf die Monatslänge geklemmt). */
function reminderNext(date, months) {
  const t = addMonths(date, months);
  return t.slice(0, 8) + String(Math.min(+date.slice(8, 10), daysInMonth(t.slice(0, 7)))).padStart(2, '0');
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
  // v3.5.0 (N1026) — im September 2025 nach dem Urlaub nicht abgelesen: Lücke bis Oktober
  const gap = withoutReading(readingDates(r('dates')), '2025-09-01');
  const dates = gap.dates;
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

  // Strom: eigener Liefervertrag, Abrechnungsjahr ab Oktober.
  // v3.5.0 (N1026) — Der erste Vertrag lief nach der Erstlaufzeit weiter und
  // endete mit dem Wechsel zum 01.10.2024 (Schlussrechnung erfasst); der zweite
  // ist mit Rechnung abgerechnet, die Verlängerung läuft seit dem 30.09.2026
  // weiter — ihre Jahresabrechnung steht noch aus („Abrechnung erwartet“).
  const stromReadings = readings('strom_wohnung', mStrom, dates, strom, 8214.3, 1);
  const kwhStrom = linearKwh(stromReadings);
  const c1 = contract('c_strom_basis', mStrom.id, 'Stadtwerke Musterstadt', 'Strom Basis 24', '2022-04-01', '2024-03-31', {
    wp: wp(['2022-04-01', 32.4], ['2023-01-01', 39.8]), bp: bp(['2022-04-01', 10.9], ['2023-01-01', 12.4]), ap: ap(['2022-04-01', 48], ['2023-01-01', 62]), notice: 1,
  });
  const st1 = settle(c1, kwhStrom, '2023-10-01', '2024-10-01');
  const sp1 = settlementPayment(`${P}|sp|strom-2024`, '2024-10-17', st1, 'Schlussrechnung Strom Basis 24');
  c1.special_payments.push(sp1);
  const c2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 12', '2024-10-01', '2025-09-30', {
    wp: wp(['2024-10-01', 29.6]), bp: bp(['2024-10-01', 11.5]), ap: ap(['2024-10-01', 47]),
    bonuses: [{ credit_date: '2024-12-15', amount_eur: 60, type: 'neukunde', label: 'Neukundenbonus' }], notice: 1,
  });
  const st2 = settle(c2, kwhStrom, '2024-10-01', '2025-10-01');
  const sp2 = settlementPayment(`${P}|sp|strom-2025`, '2025-10-16', st2, 'Jahresabrechnung Strom');
  c2.special_payments.push(sp2);
  const c3 = contract('c_strom_online_2025', mStrom.id, 'Beispiel-Energie', 'Strom Online 12 — Verlängerung', '2025-10-01', '2026-09-30', {
    wp: wp(['2025-10-01', 31.2]), bp: bp(['2025-10-01', 11.9]), ap: ap(['2025-10-01', ceilTo(st2.cost / 12, 1)]), notice: 1,
    notes: 'Verlängert sich ohne Kündigung, danach monatlich kündbar', renews: true,
  });
  const bills = [
    supplierBill(`${P}|bill|strom-2024`, mStrom.id, c1, 'final', '2023-10-01', '2024-10-01', '2024-10-17', st1, { sp: sp1 }),
    supplierBill(`${P}|bill|strom-2025`, mStrom.id, c2, 'annual', '2024-10-01', '2025-10-01', '2025-10-16', st2, { sp: sp2 }),
  ];

  // Termine, zwei davon mit Zuständigkeit (F1028)
  const LENA = 'Lena';
  const remTarif = reminder(`${P}|rem|tarif`, 'Stromtarif vergleichen', 'custom', '2026-07-01', 'yearly', null, '2025-07-01', 'Vertrag läuft weiter und ist monatlich kündbar', LENA);
  const remNka = reminder(`${P}|rem|nka`, 'Nebenkostenabrechnung prüfen', 'custom', '2026-06-20', 'yearly', null, '2025-06-20', 'Belege beim Vermieter einsehen, Einwände innerhalb von zwölf Monaten');
  const remFridge = reminder(`${P}|rem|kuehlschrank`, 'Kühlschrank abtauen', 'custom', '2026-10-12', 'custom-months', 6, '2026-04-12', 'Eisschicht über 5 mm kostet Strom', LENA);
  const remSmoke = reminder(`${P}|rem|rauchmelder`, 'Rauchmelder-Wartung', 'custom', '2026-11-03', 'yearly', null, '2025-11-03', 'Termin hängt die Hausverwaltung im Treppenhaus aus', 'Hausverwaltung Krüger');

  return {
    id: P,
    settings: {
      ...BASE_SETTINGS,
      active_utilities: ['strom', 'wasser', 'waerme'],
      wohnverhaeltnis: 'miete', wohnflaeche_m2: 62, gebaeudetyp: 'whg', wasser_personen_anzahl: 1,
      waerme_energietraeger: 'gas', warmwasser_energietraeger: 'waerme',
      billing_cycle_anchor_strom: '10-01',
      setup_persona: P,
    },
    reminders: [remTarif, remNka, remFridge, remSmoke],
    activity: (() => {
      const log = activityLog(P);
      // aus den Daten (bleiben an ihrem Datum)
      log.successor('strom', c1, c2, '2024-08-20', LENA, { renewed: true });
      log.bill('strom', c1, bills[0], true, LENA);
      log.successor('strom', c2, c3, '2025-08-28', LENA);
      log.reading('strom', mStrom, gap.before, gap.after, LENA);
      for (const s of statements) log.statement(tenancy, s, LENA);
      // die letzte Abrechnung eine Woche nach dem Eingang nachgetragen (Positionen ergänzt)
      log.statementChanged(tenancy, statements.at(-1), addDays(statements.at(-1).received_on, 7), LENA);
      log.dismissed('r4', ['wasser', mKalt.id, '2024-09'], 'Wasser Sept. 2024: statistische Anomalie', '2024-10-08', LENA,
        { note: 'Nach dem Urlaub viel gewaschen' });
      // Termine (wandern mit den Terminen)
      log.reminder(remNka, '2025-06-20', LENA, { due: '2025-06-20', prev: '2024-06-20', next: '2026-06-20' });
      log.reminder(remTarif, '2025-07-01', LENA, { due: '2025-07-01', prev: '2024-07-01', next: '2026-07-01',
        note: 'Angebote verglichen, die Verlängerung bleibt günstiger' });
      log.reminder(remFridge, '2025-10-12', LENA, { due: '2025-10-12', prev: '2025-04-12', next: '2026-04-12' });
      log.reminder(remSmoke, '2025-11-03', LENA, { due: '2025-11-03', prev: '2024-11-03', next: '2026-11-03', note: 'Alle drei Melder geprüft' });
      log.reminder(remFridge, '2026-04-05', LENA, { due: '2026-04-12', prev: '2025-10-12', next: '2026-10-05', at: '18:02',
        undone: { on: '2026-04-05', by: LENA, at: '18:03' } });
      log.reminder(remFridge, '2026-04-12', LENA, { due: '2026-04-12', prev: '2025-10-12', next: '2026-10-12' });
      return log.entries;
    })(),
    utilities: {
      strom: { meters: [mStrom], readings: stromReadings, contracts: [c1, c2, c3], bills },
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
  const spFw2024 = settlementPayment(`${P}|sp|fw-2024`, '2025-02-14', y2024, 'Jahresabrechnung 2024', ceilTo(y2024.cost / 12, 5), '2025-03-01');
  c.special_payments.push(spFw2024);
  const y2025 = settle(c, fwKwh, '2025-01-01', '2026-01-01');
  // Jahresrechnung 2025 erfasst, aber (noch) nicht als Sonderzahlung gebucht — „Buchen“ bleibt zu sehen
  const bill = supplierBill(`${P}|bill|2025`, mFw.id, c, 'annual', '2025-01-01', '2026-01-01', '2026-02-12', y2025,
    { co2: { emissions_kg: round(y2025.kwh * 0.198, 1) }, enteredAfter: 3 });
  // v3.5.0 (N1026) — auch die Jahresrechnung 2024, gebucht wie mit „Buchen“
  const bill2024 = supplierBill(`${P}|bill|2024`, mFw.id, c, 'annual', '2024-01-01', '2025-01-01', '2025-02-14', y2024,
    { sp: spFw2024, co2: { emissions_kg: round(y2024.kwh * 0.198, 1) } });

  // Strom: v3.5.0 (N1026) — 2025 bei Beispiel-Energie, zum 01.01.2026 gekündigt und
  // gewechselt (lückenloser Folgevertrag, unbefristet mit Mindestlaufzeit und
  // Preisgarantie), Schlussrechnung erfasst. v3.6.0 — der Stichtag Strom bleibt
  // der 01.01. (dort enden die Schlussrechnungen; ein Stichtag relativ zu heute
  // passte zu keinem festen Wechseltag): Die erste Jahresabrechnung des neuen
  // Versorgers ist im Januar „erwartet“. Relativ zu heute liegt der Stichtag
  // der Fernwärme (s. demoAlign).
  const stromReadings = readings('strom_wohnung', mStrom, dates, strom, 30562.8, 1);
  const kwhStrom = linearKwh(stromReadings);
  const s1 = contract('c_strom_natur', mStrom.id, 'Grünquell Energie', 'Naturstrom 24', '2023-01-01', '2024-12-31', {
    wp: wp(['2023-01-01', 38.9]), bp: bp(['2023-01-01', 13.9]), ap: ap(['2023-01-01', 92], ['2024-03-01', 86]), notice: 1,
  });
  const st1 = settle(s1, kwhStrom, '2024-01-01', '2025-01-01');
  const spS1 = settlementPayment(`${P}|sp|strom-2024`, '2025-01-23', st1, 'Schlussrechnung Naturstrom 24');
  s1.special_payments.push(spS1);
  const s2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 12', '2025-01-01', '2025-12-31', {
    wp: wp(['2025-01-01', 28.7]), bp: bp(['2025-01-01', 11.5]), ap: ap(['2025-01-01', 70]), notice: 1,
    bonuses: [{ credit_date: '2025-03-15', amount_eur: 75, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const st2 = settle(s2, kwhStrom, '2025-01-01', '2026-01-01');
  const spS2 = settlementPayment(`${P}|sp|strom-2025`, '2026-02-04', st2, 'Schlussrechnung Strom Online 12');
  s2.special_payments.push(spS2);
  const s3 = contract('c_strom_flex', mStrom.id, 'Energie Nordring', 'Nordring Flex', '2026-01-01', null, {
    wp: wp(['2026-01-01', 28.9]), bp: bp(['2026-01-01', 12.4]), ap: ap(['2026-01-01', ceilTo(st2.cost / 12, 1)]), notice: 1, noticeMode: 'month_end',
    minTerm: '2026-12-31', guarantee: '2027-06-30',
    notes: 'Unbefristet, frühestens zum Ende der Mindestlaufzeit kündbar',
  });
  const stromBills = [
    supplierBill(`${P}|bill|strom-2024`, mStrom.id, s1, 'final', '2024-01-01', '2025-01-01', '2025-01-23', st1, { sp: spS1 }),
    supplierBill(`${P}|bill|strom-2025`, mStrom.id, s2, 'final', '2025-01-01', '2026-01-01', '2026-02-04', st2, { sp: spS2 }),
  ];

  const KATRIN = 'Katrin', MEHMET = 'Mehmet';
  const remCal = reminder(`${P}|rem|eichung`, 'Eichung Wärmemengenzähler', 'waermezaehler_eichung', '2026-09-01', 'custom-months', 60, '2021-09-01', 'Eichfrist nach MessEV', KATRIN);
  const remAir = reminder(`${P}|rem|entlueften`, 'Heizkörper entlüften', 'custom', '2026-10-03', 'yearly', null, '2025-10-03', 'Vor Beginn der Heizperiode', MEHMET);
  const remStation = reminder(`${P}|rem|station`, 'Wartung Wohnungsstation', 'custom', '2026-11-18', 'yearly', null, '2025-11-18', 'Übergabestation im Keller, Termin mit dem Versorger', 'Hausmeister Petersen');

  return {
    id: P,
    settings: {
      ...BASE_SETTINGS,
      active_utilities: ['strom', 'fernwaerme'],
      wohnverhaeltnis: 'eigentum', wohnflaeche_m2: 85, gebaeudetyp: 'whg', wasser_personen_anzahl: 2,
      warmwasser_energietraeger: 'fernwaerme',
      setup_persona: P,
    },
    reminders: [remCal, remAir, remStation],
    // v3.5.0 (N1026) — Stichtag relativ zu heute: Abrechnung seit 30 Tagen erwartet.
    // v3.6.0 — bei der Fernwärme (ein Vertrag seit 2021, nur Jahresrechnungen), deren
    // Rechnungen der DemoDataAligner im Rhythmus des Stichtags mitführt
    demoAlign: { billing_anchor_days_ago: { fernwaerme: 30 } },
    activity: (() => {
      const log = activityLog(P);
      log.cancelled('strom', s1, '2024-11-12', KATRIN, { note: 'Per Mail gekündigt, Bestätigung liegt vor' });
      log.successor('strom', s1, s2, '2024-11-12', KATRIN);
      log.bill('strom', s1, stromBills[0], true, MEHMET);
      log.bill('fernwaerme', c, bill2024, false, KATRIN);
      log.dismissed('r6', ['strom', mStrom.id, s2.id], 'Strom: Kündigung bis 30.11.2025 (60 Tage)', '2025-10-01', KATRIN,
        { note: 'Erst Angebote vergleichen', undone: { on: '2025-10-20', by: MEHMET } });
      log.cancelled('strom', s2, '2025-11-10', KATRIN);
      log.successor('strom', s2, s3, '2025-11-10', MEHMET);
      log.bill('strom', s2, stromBills[1], true, KATRIN);
      log.bill('fernwaerme', c, bill, false, MEHMET);
      log.reminder(remAir, '2025-10-03', MEHMET, { due: '2025-10-03', prev: '2024-10-03', next: '2026-10-03' });
      log.reminder(remStation, '2025-11-18', KATRIN, { due: '2025-11-18', prev: '2024-11-18', next: '2026-11-18',
        note: 'Filter gereinigt, Druck nachgefüllt' });
      return log.entries;
    })(),
    utilities: {
      strom: { meters: [mStrom], readings: stromReadings, contracts: [s1, s2, s3], bills: stromBills },
      fernwaerme: { meters: [mFw], readings: fwReadings, contracts: [c], bills: [bill2024, bill] },
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
  // v3.5.0 (N1026) — nach dem Sommerurlaub 2025 erst im Oktober wieder abgelesen
  const gap = withoutReading(readingDates(r('dates')), '2025-09-01');
  const dates = gap.dates;
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

  const gasReadings = readings('gas_haus', mGas, dates, gasM3, 18437.6, 1);
  const stromReadings = readings('strom_haus', mStrom, dates, strom, 61208.4, 1);
  const hauptReadings = readings('wasser_haupt', mHaupt, dates, haupt, 512.37, 2);
  const gartenReadings = readings('wasser_garten', mGarten, dates, garten, 61.84, 2);

  // Gas: v3.5.0 (N1026) — gekündigt und gewechselt (Schlussrechnung erfasst), der
  // Festpreisvertrag lief am 30.06.2026 aus und läuft weiter. Den Stichtag legt
  // die Demo relativ zu heute (demo_align): Die letzte Jahresabrechnung ist
  // immer seit 56 Tagen fällig und fehlt („Abrechnung überfällig“, Frist sechs
  // Wochen); die Jahresabrechnungen davor sind erfasst.
  const kwhGas = gasLinearKwh(gasReadings, factors);
  const g1 = contract('c_gas_klassik', mGas.id, 'Stadtwerke Musterstadt', 'Gas Basis 24', '2022-07-01', '2024-06-30', {
    wp: wp(['2022-07-01', 13.9], ['2023-01-01', 15.2], ['2024-01-01', 11.4]), bp: bp(['2022-07-01', 14.5], ['2024-01-01', 15.9]),
    ap: ap(['2022-07-01', 210], ['2023-03-01', 260]), notice: 3,
  });
  const gs1 = settle(g1, kwhGas, '2023-07-01', '2024-07-01');
  const spG1 = settlementPayment(`${P}|sp|gas-2024`, '2024-08-06', gs1, 'Schlussrechnung Gas Basis 24');
  g1.special_payments.push(spG1);
  const g2 = contract('c_gas_fix', mGas.id, 'Energie Nordring', 'Gas Fix 24', '2024-07-01', '2026-06-30', {
    notes: 'Wechsel nach Preisvergleich, Preisgarantie über die ganze Laufzeit',
    wp: wp(['2024-07-01', 9.4]), bp: bp(['2024-07-01', 12.9]), ap: ap(['2024-07-01', 150]), notice: 1, guarantee: '2026-06-30', renews: true,
    bonuses: [{ credit_date: '2024-10-15', amount_eur: 80, type: 'wechsel', label: 'Wechselbonus' }],
  });
  const gy = settle(g2, kwhGas, '2024-07-01', '2025-07-01');
  const spG2 = settlementPayment(`${P}|sp|gas-2025`, '2025-07-24', gy, 'Jahresabrechnung Gas', ceilTo(gy.cost / 12, 5), '2025-09-01');
  g2.special_payments.push(spG2);

  const kwhStrom = linearKwh(stromReadings);
  const s1 = contract('c_strom_klassik', mStrom.id, 'Stadtwerke Musterstadt', 'Strom Klassik', '2023-01-01', '2024-12-31', {
    wp: wp(['2023-01-01', 40.2], ['2024-01-01', 33.8]), bp: bp(['2023-01-01', 13.5]), ap: ap(['2023-01-01', 145], ['2024-03-01', 120]), notice: 1,
  });
  const ss1 = settle(s1, kwhStrom, '2024-01-01', '2025-01-01');
  const spS1 = settlementPayment(`${P}|sp|strom-2024`, '2025-01-20', ss1, 'Schlussrechnung Strom Klassik');
  s1.special_payments.push(spS1);
  const s2 = contract('c_strom_online', mStrom.id, 'Beispiel-Energie', 'Strom Online 24', '2025-01-01', '2026-12-31', {
    wp: wp(['2025-01-01', 29.4]), bp: bp(['2025-01-01', 12.9]), ap: ap(['2025-01-01', 110]), notice: 1, guarantee: '2026-12-31',
    bonuses: [{ credit_date: '2025-04-15', amount_eur: 120, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const sy = settle(s2, kwhStrom, '2025-01-01', '2026-01-01');
  const spS2 = settlementPayment(`${P}|sp|strom-2025`, '2026-01-22', sy, 'Jahresabrechnung Strom', ceilTo((sy.cost + sy.bonus) / 12, 5), '2026-03-01');
  s2.special_payments.push(spS2);

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
  const waterContracts = [
    water('c_wasser_2023', 2023, 'Trink-, Schmutz- und Niederschlagswasser 2023', '2023-12-31', 'Standard-Haushaltstarif', [['2023-01-01', 228]], 7.9, [['2023-01-01', 268]], 1.35, 78),
    water('c_wasser_2024', 2024, 'Trink-, Schmutz- und Niederschlagswasser 2024', '2024-12-31', 'Tarifanpassung 2024', [['2024-01-01', 241]], 8.4, [['2024-01-01', 282]], 1.42, 82),
    water('c_wasser_2025', 2025, 'Trink-, Schmutz- und Niederschlagswasser 2025–2026', '2026-12-31', '', [['2025-01-01', 252], ['2026-01-01', 263]], 8.9, [['2025-01-01', 297], ['2026-01-01', 309]], 1.48, 86),
  ];
  // v3.6.0 — Sonderzahlung am Wasservertrag (GitHub #34): Nachzahlung aus der Abrechnung 2024
  waterContracts[1].special_payments = [specialPayment(`${P}|sp|wasser-2024`, '2025-02-20', 'nachzahlung_ohne', 38.4,
    'Wasserabrechnung 2024 — Nachzahlung')];
  // v3.5.0 (N1026) — Jahresrechnung Wasser 2025; v3.6.0 — Schmutzwasser (abzüglich
  // Gartenwasser) rechnet die App jetzt selbst nach, kein Posten mehr
  const ws = waterSettle(waterContracts[2], linearKwh(hauptReadings), linearKwh(gartenReadings), '2025-01-01', '2026-01-01');
  const waterBill = supplierBill(`${P}|bill|wasser-2025`, mHaupt.id, waterContracts[2], 'annual', '2025-01-01', '2026-01-01', '2026-02-18', ws,
    { water: true });
  const bills = {
    gas: [supplierBill(`${P}|bill|gas-2024`, mGas.id, g1, 'final', '2023-07-01', '2024-07-01', '2024-08-06', gs1, { sp: spG1 }),
          supplierBill(`${P}|bill|gas-2025`, mGas.id, g2, 'annual', '2024-07-01', '2025-07-01', '2025-07-24', gy, { sp: spG2 })],
    strom: [supplierBill(`${P}|bill|strom-2024`, mStrom.id, s1, 'final', '2024-01-01', '2025-01-01', '2025-01-20', ss1, { sp: spS1 }),
            supplierBill(`${P}|bill|strom-2025`, mStrom.id, s2, 'annual', '2025-01-01', '2026-01-01', '2026-01-22', sy, { sp: spS2 })],
  };

  // Termine: drei mit Zuständigkeit (F1028)
  const THOMAS = 'Thomas', SABINE = 'Sabine', JONAS = 'Jonas';
  const remService = reminder(`${P}|rem|wartung`, 'Heizungswartung', 'heizung_wartung', '2026-09-18', 'yearly', null, '2025-09-18', 'Jahreswartung durch den Installateur', THOMAS);
  const remChimney = reminder(`${P}|rem|schornstein`, 'Schornsteinfeger', 'schornsteinfeger', '2026-11-05', 'yearly', null, '2025-11-05', 'Kehrung und Abgasmessung', SABINE);
  const remGarden = reminder(`${P}|rem|garten`, 'Gartenwasser abstellen', 'custom', '2026-10-30', 'yearly', null, '2025-10-30', 'Außenhahn entleeren, bevor Frost kommt', JONAS);

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
      remService, remChimney,
      reminder(`${P}|rem|wasser`, 'Eichung Wasserzähler', 'wasserzaehler_eichung', '2027-03-31', 'custom-months', 72, '2021-03-31', 'Eichfrist nach MessEV — Austausch durch den Versorger'),
      reminder(`${P}|rem|gas`, 'Eichung Gaszähler', 'gaszaehler_eichung', '2027-04-10', 'custom-months', 96, '2019-04-10', 'Eichfrist nach MessEV'),
      remGarden,
    ],
    // v3.5.0 (N1026) — Stichtag Gas relativ zu heute: Jahresabrechnung seit 56 Tagen überfällig
    demoAlign: { billing_anchor_days_ago: { gas: 56 } },
    activity: (() => {
      const log = activityLog(P);
      log.cancelled('gas', g1, '2024-03-20', THOMAS, { note: 'Kündigung per Einschreiben' });
      log.successor('gas', g1, g2, '2024-03-20', THOMAS);
      log.bill('gas', g1, bills.gas[0], true, SABINE);
      log.cancelled('strom', s1, '2024-11-15', SABINE);
      log.successor('strom', s1, s2, '2024-11-15', SABINE);
      log.bill('strom', s1, bills.strom[0], true, SABINE);
      log.dismissed('r4', ['wasser', mGarten.id, '2024-05'], 'Wasser Mai 2024: statistische Anomalie', '2024-06-11', SABINE,
        { note: 'Rasen neu eingesät' });
      log.bill('gas', g2, bills.gas[1], false, THOMAS);
      for (const [u, m] of [['gas', mGas], ['strom', mStrom], ['wasser', mHaupt], ['wasser', mGarten]]) log.reading(u, m, gap.before, gap.after, THOMAS);
      log.bill('strom', s2, bills.strom[1], false, THOMAS);
      log.bill('wasser', waterContracts[2], waterBill, false, SABINE);
      log.reminder(remService, '2025-09-18', THOMAS, { due: '2025-09-18', prev: '2024-09-18', next: '2026-09-18',
        note: 'Brenner gereinigt, Abgaswerte in Ordnung' });
      log.reminder(remGarden, '2025-10-24', JONAS, { due: '2025-10-30', prev: '2024-10-30', next: '2026-10-24', at: '17:40',
        undone: { on: '2025-10-24', by: SABINE, at: '20:15' } });
      log.reminder(remGarden, '2025-10-30', JONAS, { due: '2025-10-30', prev: '2024-10-30', next: '2026-10-30' });
      log.reminder(remChimney, '2025-11-05', SABINE, { due: '2025-11-05', prev: '2024-11-05', next: '2026-11-05' });
      return log.entries;
    })(),
    utilities: {
      gas: { meters: [mGas], readings: gasReadings, contracts: [g1, g2], bills: bills.gas },
      strom: { meters: [mStrom], readings: stromReadings, contracts: [s1, s2], bills: bills.strom },
      wasser: {
        meters: [mHaupt, mGarten],
        readings: [...hauptReadings, ...gartenReadings],
        contracts: waterContracts,
        bills: [waterBill],
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

/**
 * v3.6.0 — Steckzeiten der Ladevorgänge. evcc führt einen Ladevorgang vom
 * Anstecken („created“) bis zum Abstecken („finished“); geladen wird darin,
 * hier gleich nach dem Anstecken — die Viertelstunden der Wallbox entstehen
 * vorher aus Beginn und Dauer der Ladung (`sessions` tragen bis hierher das
 * Ende der Ladung). Abends angesteckt bleibt der Dienstwagen bis zum nächsten
 * Morgen (Abstecken 06:30–07:45 Uhr), mittags angesteckt bis zum späten
 * Nachmittag (16:30–18:30 Uhr, frühestens 30 Minuten nach der Ladung). `date`
 * ist wie beim Import aus evcc der Tag des Absteckens (EvccService::apply).
 * Die Flexibilität verschiebt das Laden nur innerhalb dieser Fenster.
 */
function evPlugTimes(sessions, rand) {
  const minutes = (iso) => +iso.slice(11, 13) * 60 + +iso.slice(14, 16);
  const hm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}:00`;
  return sessions.map(s => {
    const day = s.created.slice(0, 10);
    const evening = minutes(s.created) >= 15 * 60;
    const out = evening ? addDays(day, 1) : day;
    const outMin = evening
      ? 6 * 60 + 30 + Math.floor(between(rand, 0, 75))
      : Math.max(minutes(s.finished) + 30, 16 * 60 + 30 + Math.floor(between(rand, 0, 120)));
    return { ...s, finished: `${out}T${hm(outMin)}${berlinOffset(out)}`, date: out };
  });
}

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
    evDay.set(d, m >= 4 && m <= 9 ? 0.35 : 0.1);   // Anteil, der mittags lädt (Dienstwagen: werktags unterwegs)
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

  // Tagesbilanz: direkt verbraucht, Speicher laden/entladen, Rest ins Netz.
  // v3.4.0 (F1025) — Der Speicher versorgt nur den Haushalt, nicht das Auto
  // (wie die übliche Einstellung „Hausbatterie nicht fürs Fahrzeug“). Vorher
  // entlud er nachts bis zu 20 kWh je Ladetag in die Wallbox: Der Ladestrom kam
  // dann zum größten Teil hinter dem Bezugszähler aus Sonne und Speicher, und
  // die Wallbox (Subzähler) maß im Jahr mehr als ihr Elternzähler.
  const charge = new Map(), discharge = new Map(), feedIn = new Map(), importHh = new Map(), directDay = new Map();
  for (const d of days(SIM_START, END_EXCL)) {
    const g = gen.get(d), h = household.get(d), e = ev.get(d);
    const loadDay = h * 0.45 + e * evDay.get(d);
    const direct = Math.min(g, loadDay) * 0.9;
    directDay.set(d, direct);
    const surplus = g - direct;
    const c = Math.min(surplus, USABLE, h * 0.55 / ROUND_TRIP);
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
    wp: wp(['2024-01-01', 32.9]), bp: bp(['2024-01-01', 13.9]), ap: ap(['2024-01-01', 82]), notice: 1,
  });
  const hh3 = contract('c_strom_online', mHaus.id, 'Beispiel-Energie', 'Strom Online 24', '2025-01-01', '2026-12-31', {
    wp: wp(['2025-01-01', 29.8]), bp: bp(['2025-01-01', 12.9]), ap: ap(['2025-01-01', 76]), notice: 1, guarantee: '2026-12-31',
    bonuses: [{ credit_date: '2025-03-15', amount_eur: 100, type: 'neukunde', label: 'Neukundenbonus' }],
  });
  const dyn = contract('c_strom_dynamisch', mHaus.id, 'Beispiel-Energie', 'Strom Dynamisch', '2027-01-01', null, {
    notes: 'Angebot mit Börsenstrompreis, zum Vergleich',
    shadow: 'Strom Dynamisch', notice: 1,
    extra: { price_model: 'dynamic', dynamic: { markup_ct_per_kwh: 19.4, base_eur_month: 11.9, vat_pct: 19, weighting: 'flat' } },
  });
  // v3.5.0 (N1026) — zum 30.09.2026 gekündigt, lückenloser Folgevertrag bei einem
  // anderen Versorger (Mindestlaufzeit, Preisgarantie); die Schlussrechnung steht aus
  const wpC = contract('c_strom_waermepumpe', mWp.id, 'Stadtwerke Musterstadt', 'Wärmepumpenstrom', '2022-11-01', '2026-09-30', {
    notes: 'Steuerbare Verbrauchseinrichtung nach § 14a EnWG, Modul 1',
    wp: wp(['2022-11-01', 31.9], ['2023-01-01', 36.4], ['2024-01-01', 27.9], ['2025-01-01', 25.6], ['2026-01-01', 24.9]),
    bp: bp(['2022-11-01', 9.5], ['2024-01-01', 10.9]),
    ap: ap(['2022-11-01', 105], ['2024-03-01', 95]), notice: 1, noticeMode: 'month_end', renews: false,
    // v3.3.0 (F1021) — § 14a Modul 3: zeitvariables Netzentgelt (Zeitfenster des Netzbetreibers)
    extra: { grid_reduction: [{ from: '2024-04-01', eur_per_year: 152.4, module: 1 }], grid_tou: GRID_TOU },
  });
  const wpC2 = contract('c_strom_waermepumpe_2026', mWp.id, 'Energie Nordring', 'Nordring Wärme 24', '2026-10-01', null, {
    notes: 'Steuerbare Verbrauchseinrichtung nach § 14a EnWG, Modul 1',
    wp: wp(['2026-10-01', 23.9]), bp: bp(['2026-10-01', 9.9]), ap: ap(['2026-10-01', 80]), notice: 1, noticeMode: 'month_end',
    minTerm: '2028-09-30', guarantee: '2027-09-30',
    extra: { grid_reduction: [{ from: '2026-10-01', eur_per_year: 152.4, module: 1 }], grid_tou: GRID_TOU },
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
    const midday = evDay.get(d) >= 0.3;
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

  // v3.3.0 (F1021) — Viertelstunden 2025: die Wallbox aus ihren Ladevorgängen,
  // dazu die Börsenpreise je Viertelstunde (SMARD, demo-data/sources/). Die
  // Wärmepumpe ist gesteuert: Sie läuft bevorzugt im Niedrigtarif-Fenster
  // (00–06 Uhr) und meidet 17–20 Uhr.
  // v3.5.0 (N1026) — Netzbezug des Haushalts, Erzeugung, Einspeisung und
  // Speicher je Viertelstunde aus einer Rechnung (dispatchDay), damit jede
  // Viertelstunde für sich aufgeht (Tagesfilm F1027).
  const hpShape = (h) => (h < 6 ? 1.7 : h >= 17 && h < 20 ? 0.35 : 1);
  const evSlots = new Map();
  for (const s of evSessions) {
    const a = Date.parse(s.created) / 1000, b = Date.parse(s.finished) / 1000;
    if (!(b > a)) continue;
    for (let t = Math.floor(a / 900) * 900; t < b; t += 900) {
      const overlap = Math.min(b, t + 900) - Math.max(a, t);
      if (overlap > 0) evSlots.set(t, (evSlots.get(t) ?? 0) + s.charged_kwh * overlap / (b - a));
    }
  }
  const QH_YEAR = 2025;
  const { clock } = yearSlots(QH_YEAR);
  const byDay = new Map();
  clock.forEach((c, i) => { if (!byDay.has(c.day)) byDay.set(c.day, []); byDay.get(c.day).push({ i, ts: c.ts, minute: c.minute }); });
  const flows = Object.fromEntries(['gen', 'feed', 'charge', 'discharge', 'grid'].map(k => [k, new Array(clock.length).fill(0)]));
  const rq = r('viertelstunden');
  const qhStats = { days: 0, scaled: 0, sunShare: 0, direct: 0, charge: 0, discharge: 0 };
  for (const [d, slots] of byDay) {
    const res = dispatchDay(slots, gen.get(d), household.get(d), directDay.get(d), charge.get(d), discharge.get(d),
      (ts) => evSlots.get(ts) ?? 0, rq);
    slots.forEach((s, k) => { for (const f of Object.keys(flows)) flows[f][s.i] = res[f][k]; });
    // Was sich in den Viertelstunden nicht unterbringen ließ (an Sommertagen trifft
    // die Nacht weniger Hausverbrauch, als der Speicher laut Tagesbilanz abgibt),
    // gilt auch für den Tag: Stände und Viertelstunden bleiben deckungsgleich.
    if (res.directShort + res.chargeShort + res.dischargeShort > 0) {
      directDay.set(d, directDay.get(d) - res.directShort);
      charge.set(d, charge.get(d) - res.chargeShort);
      discharge.set(d, discharge.get(d) - res.dischargeShort);
      feedIn.set(d, feedIn.get(d) + res.directShort + res.chargeShort);
      importHh.set(d, importHh.get(d) + res.directShort + res.dischargeShort);
    }
    qhStats.days++;
    if (res.scale < 0.999) qhStats.scaled++;
    qhStats.sunShare += res.a;
    qhStats.direct = Math.max(qhStats.direct, res.directShort);
    qhStats.charge = Math.max(qhStats.charge, res.chargeShort);
    qhStats.discharge = Math.max(qhStats.discharge, res.dischargeShort);
    if (process.env.ET_QH_DEBUG && res.dischargeShort + res.chargeShort + res.directShort > 0.001) {
      console.log('   ', d, { G: round(gen.get(d), 2), H: round(household.get(d), 2), D: round(directDay.get(d), 2), C: round(charge.get(d), 2),
        a: round(res.a, 3), scale: round(res.scale, 3), short: [res.directShort, res.chargeShort, res.dischargeShort].map(x => round(x, 3)) });
    }
  }
  const intervals = intervalSeries(QH_YEAR, [
    { utility: 'strom', meter: mHaus.id, values: flows.grid },
    { utility: 'strom', meter: mWp.id, daily: wpElec, shape: hpShape },
    { utility: 'strom', meter: mEv.id, slots: evSlots },
    { utility: 'pv_erzeugung', meter: mGen.id, values: flows.gen },
    { utility: 'pv_erzeugung', meter: mCharge.id, values: flows.charge },
    { utility: 'pv_erzeugung', meter: mDischarge.id, values: flows.discharge },
    { utility: 'pv_einspeisung', meter: mFeed.id, values: flows.feed },
  ]);
  console.log(`  Viertelstunden ${QH_YEAR}: ${qhStats.days} Tage, sonnengeführter Anteil Ø ${round(qhStats.sunShare / qhStats.days * 100, 1)} %, `
    + `gekürzter Eigenverbrauch an ${qhStats.scaled} Tagen, größter Rest (selbst/laden/entladen) `
    + `${round(qhStats.direct, 4)}/${round(qhStats.charge, 4)}/${round(qhStats.discharge, 4)} kWh`);

  // Stände erst jetzt: die Viertelstunden-Rechnung kann Tageswerte 2025 angepasst haben
  const hausReadings = readings('strom_haushalt', mHaus, dates, importHh, fromCommissioning(importHh), 1);
  const wpReadings = readings('strom_waermepumpe', mWp, dates, wpElec, 3412.6, 1);
  // Abrechnungen wie die App: Mengen linear aus den Ständen (v3.5.0, N1026: mit Versorgerrechnungen)
  const kwhHh = linearKwh(hausReadings), kwhWp = linearKwh(wpReadings);
  const sh2 = settle(hh2, kwhHh, '2024-01-01', '2025-01-01');
  const spH2 = settlementPayment(`${P}|sp|strom-2024`, '2025-01-24', sh2, 'Schlussrechnung Strom Direkt 12');
  hh2.special_payments.push(spH2);
  const sh3 = settle(hh3, kwhHh, '2025-01-01', '2026-01-01');
  const spH3 = settlementPayment(`${P}|sp|strom-2025`, '2026-01-23', sh3, 'Jahresabrechnung Strom');
  hh3.special_payments.push(spH3);
  const sw24 = settle(wpC, kwhWp, '2024-01-01', '2025-01-01');
  const spW24 = settlementPayment(`${P}|sp|wp-2024`, '2025-02-11', sw24, 'Jahresabrechnung Wärmepumpenstrom 2024', 85, '2025-03-01');
  wpC.special_payments.push(spW24);
  const sw25 = settle(wpC, kwhWp, '2025-01-01', '2026-01-01');
  const spW25 = settlementPayment(`${P}|sp|wp-2025`, '2026-02-10', sw25, 'Jahresabrechnung Wärmepumpenstrom 2025', ceilTo(sw25.cost / 12, 5), '2026-03-01');
  wpC.special_payments.push(spW25);
  const bills = [
    supplierBill(`${P}|bill|strom-2024`, mHaus.id, hh2, 'final', '2024-01-01', '2025-01-01', '2025-01-24', sh2, { sp: spH2 }),
    supplierBill(`${P}|bill|wp-2024`, mWp.id, wpC, 'annual', '2024-01-01', '2025-01-01', '2025-02-11', sw24, { sp: spW24 }),
    supplierBill(`${P}|bill|strom-2025`, mHaus.id, hh3, 'annual', '2025-01-01', '2026-01-01', '2026-01-23', sh3, { sp: spH3 }),
    supplierBill(`${P}|bill|wp-2025`, mWp.id, wpC, 'annual', '2025-01-01', '2026-01-01', '2026-02-10', sw25, { sp: spW25 }),
  ];

  const JULIA = 'Julia', BEN = 'Ben';
  const remHp = reminder(`${P}|rem|wartung`, 'Wartung Wärmepumpe', 'heizung_wartung', '2026-10-08', 'yearly', null, '2025-10-08', 'Jährliche Wartung durch den Fachbetrieb', BEN);
  const remPv = reminder(`${P}|rem|pv`, 'PV-Anlage prüfen', 'custom', '2027-04-20', 'yearly', null, '2026-04-20', 'Sichtprüfung der Module, Fehlerspeicher des Wechselrichters', JULIA);
  const remFilter = reminder(`${P}|rem|filter`, 'Filter der Lüftungsanlage wechseln', 'custom', '2026-09-10', 'custom-months', 4, '2026-05-10', 'Zu- und Abluftfilter, Ersatz liegt im Hauswirtschaftsraum', JULIA);

  return {
    id: P,
    // v3.6.0 — mit Steckzeiten (Anstecken bis Abstecken), nachdem die Viertelstunden stehen
    ev_sessions: evPlugTimes(evSessions, r('evcc-plug')),
    intervals,
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
      remHp, remPv,
      reminder(`${P}|rem|eichung`, 'Eichung Stromzähler', 'stromzaehler_eichung', '2030-10-12', 'custom-months', 96, '2022-10-12', 'Eichfrist nach MessEV'),
      remFilter,
    ],
    activity: (() => {
      const log = activityLog(P);
      log.successor('strom', hh, hh2, '2023-11-28', BEN);
      log.dismissed('r4', ['strom', mEv.id, '2023-11'], 'Strom Nov. 2023: statistische Anomalie', '2023-12-12', BEN,
        { note: 'Dienstwagen öfter zu Hause geladen', undone: { on: '2023-12-14', by: JULIA } });
      log.dismissed('r4', ['strom', mHaus.id, '2026-01'], 'Strom Jan. 2026: statistische Anomalie', '2026-02-14', BEN,
        { note: 'Kalte Wochen, viel zu Hause' });
      log.cancelled('strom', hh2, '2024-11-26', JULIA, { note: 'Wechsel nach Tarifvergleich' });
      log.successor('strom', hh2, hh3, '2024-11-26', JULIA);
      log.bill('strom', hh2, bills[0], true, JULIA);
      log.bill('strom', wpC, bills[1], false, BEN);
      log.bill('strom', hh3, bills[2], false, JULIA);
      log.bill('strom', wpC, bills[3], false, BEN);
      log.successor('strom', wpC, wpC2, '2026-05-19', BEN);
      log.reminder(remFilter, '2025-09-10', JULIA, { due: '2025-09-10', prev: '2025-05-10', next: '2026-01-10' });
      log.reminder(remHp, '2025-10-08', BEN, { due: '2025-10-08', prev: '2024-10-08', next: '2026-10-08',
        note: 'Kältekreis dicht, Filter gereinigt' });
      log.reminder(remFilter, '2026-01-10', BEN, { due: '2026-01-10', prev: '2025-09-10', next: '2026-05-10' });
      log.reminder(remPv, '2026-04-20', JULIA, { due: '2026-04-20', prev: '2025-04-20', next: '2027-04-20',
        note: 'Module sauber, Wechselrichter ohne Fehler' });
      log.reminder(remFilter, '2026-05-10', JULIA, { due: '2026-05-10', prev: '2026-01-10', next: '2026-09-10' });
      return log.entries;
    })(),
    utilities: {
      strom: {
        meters: [mHaus, mWp, mEv],
        readings: [
          ...hausReadings,
          ...wpReadings,
          ...readings('strom_wallbox', mEv, dates, ev, fromCommissioning(ev), 1),
        ],
        contracts: [hh, hh2, hh3, dyn, wpC, wpC2],
        bills,
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

// ═════════════════════════════════════════════════════════════════════════
// Schaufenster (Haupt-Demo) — v3.5.0 (N1026): dieselben neuen Anwendungsfälle
// ═════════════════════════════════════════════════════════════════════════
//
// Die Haupt-Demo entsteht außerhalb dieses Repositorys; dieses Skript ergänzt
// nur, was v3.5.0 zeigen soll, und lässt alles andere byte-gleich: Termine mit
// Zuständigkeit, zwei erfasste Versorgerrechnungen (Strom 2023 als
// Schlussrechnung, Fernwärme 2025), den Verlauf „Erledigt“ und einen
// Abrechnungsstichtag Strom relativ zu heute (`demo_align`), so dass die App
// immer eine Jahresabrechnung als „erwartet“ zeigt. Viertelstunden bekommt das
// Schaufenster nicht (s. README).

const DEMO_FILE = join(ROOT, 'demo-data', 'energietracker-demo-backup.json');

function showcase() {
  const P = 'showcase';
  const d = structuredClone(DEMO);
  const ANNA = 'Anna', PAUL = 'Paul';
  const rem = (id) => d.reminders.find(r => r.id === id);
  rem('rem_demo0001').assignee = PAUL;
  rem('rem_demo0002').assignee = ANNA;
  rem('rem_demo0003').assignee = PAUL;

  const u = d.utilities;
  const sorted = (rs, mid) => rs.filter(r => r.meter_id === mid).sort((a, b) => (a.date < b.date ? -1 : 1));
  const contractOf = (k, id) => u[k].contracts.find(c => c.id === id);
  const cStrom1 = contractOf('strom', 'c_strom_001');
  const sStrom = settle(cStrom1, linearKwh(sorted(u.strom.readings, 'm_strom_main')), '2023-01-01', '2024-01-01');
  const billStrom = supplierBill(`${P}|bill|strom-2023`, 'm_strom_main', cStrom1, 'final', '2023-01-01', '2024-01-01', '2024-01-25', sStrom,
    { note: 'Schlussrechnung nach dem Anbieterwechsel' });
  const cFw = contractOf('fernwaerme', 'c_fw_001');
  const sFw = settle(cFw, linearKwh(sorted(u.fernwaerme.readings, 'm_fernwaerme_main')), '2025-01-01', '2026-01-01');
  const billFw = supplierBill(`${P}|bill|fw-2025`, 'm_fernwaerme_main', cFw, 'annual', '2025-01-01', '2026-01-01', '2026-02-17', sFw);
  u.strom.bills = [billStrom];
  u.fernwaerme.bills = [billFw];

  const log = activityLog(P);
  log.successor('strom', cStrom1, contractOf('strom', 'c_strom_002'), '2023-11-20', PAUL);
  log.dismissed('r4', ['wasser', 'm_wasser_garten', '2023-05'], 'Wasser Mai 2023: statistische Anomalie', '2023-06-08', ANNA,
    { note: 'Neue Hecke gepflanzt und gewässert' });
  log.bill('strom', cStrom1, billStrom, true, PAUL);
  log.successor('gas', contractOf('gas', 'c_gas_002'), contractOf('gas', 'c_gas_003'), '2024-11-08', ANNA);
  log.successor('strom', contractOf('strom', 'c_strom_003'), contractOf('strom', 'c_strom_004'), '2025-12-03', PAUL);
  log.successor('gas', contractOf('gas', 'c_gas_003'), contractOf('gas', 'c_gas_004'), '2025-12-05', ANNA);
  log.bill('fernwaerme', cFw, billFw, false, ANNA);
  log.reminder(rem('rem_demo0001'), '2025-07-22', PAUL, { due: '2025-07-22', prev: '2024-07-22', next: '2026-07-22',
    note: 'Brennerdüse getauscht' });
  log.reminder(rem('rem_demo0003'), '2025-09-15', ANNA, { due: '2025-09-15', prev: '2025-03-15', next: '2026-03-15' });
  log.reminder(rem('rem_demo0003'), '2026-03-12', PAUL, { due: '2026-03-15', prev: '2025-09-15', next: '2026-09-12', at: '07:55',
    undone: { on: '2026-03-12', by: PAUL, at: '07:56' } });
  log.reminder(rem('rem_demo0003'), '2026-03-15', PAUL, { due: '2026-03-15', prev: '2025-09-15', next: '2026-09-15' });
  d.activity = log.entries;
  // Stichtag Strom relativ zu heute: die Jahresabrechnung des laufenden Vertrags ist
  // immer seit drei Wochen „erwartet“ (DemoDataAligner, wird nicht eingespielt)
  d.demo_align = { billing_anchor_days_ago: { strom: 21 } };
  return d;
}

// ═════════════════════════════════════════════════════════════════════════
// v3.6.0 — Personenverzeichnis je Haushalt (Topf `people`, PeopleService)
// ═════════════════════════════════════════════════════════════════════════
//
// Bis v3.5.0 standen an den Terminen nur Namen; angelegt war niemand. Jetzt
// hat jeder Haushalt sein Verzeichnis: alle Personen aus Verlauf und
// Zuständigkeit mit fortlaufender Nummer und Rolle (je Haushalt mindestens
// eine, die verwaltet), Termine mit `assignee_id`. Im Einfamilienhaus
// klassisch ist Lukas gelöscht: Die Kündigung des alten Gasvertrags hat er
// erledigt; sein Eintrag im Verlauf zeigt „Lukas (gelöscht)“. Nur Personen
// und Zuständigkeiten — alles andere bleibt, wie die Haushalte es bauen.

/**
 * Personen je Haushalt, in der Reihenfolge ihrer Nummer. `takes`: Einträge,
 * die die gelöschte Person erledigt hat. `REASSIGN`: Zuständige, die keine
 * Person des Haushalts sind (v3.5.0 ein freier Name), übernimmt jemand.
 */
const REASSIGN = { mieterin: { 'Hausverwaltung Krüger': 'Lena' }, 'etw-fernwaerme': { 'Hausmeister Petersen': 'Katrin' } };
const PEOPLE = {
  mieterin: [{ name: 'Lena', role: 'admin' }],
  'etw-fernwaerme': [{ name: 'Katrin', role: 'admin' }, { name: 'Mehmet', role: 'member' }],
  'eigenheim-klassisch': [
    { name: 'Thomas', role: 'admin' }, { name: 'Sabine', role: 'admin' }, { name: 'Jonas', role: 'member' },
    { name: 'Lukas', role: 'member', deleted: { on: '2025-01-15', at: '19:30', by: 'Thomas' },
      takes: (e) => e.ref?.utility === 'gas' && e.done_on === '2024-03-20', count: 2 },
  ],
  'eigenheim-modern': [{ name: 'Julia', role: 'admin' }, { name: 'Ben', role: 'member' }],
  showcase: [{ name: 'Paul', role: 'admin' }, { name: 'Anna', role: 'member' }],
};

/** ID einer erfundenen Person — dieselbe Formel wie activityLog(). */
const personId = (P, name) => 'u_' + hex(`${P}|person|${name}`, 8);

/**
 * Verzeichnis, Zuständigkeit und Löschvermerk eines Haushalts. `h` hat
 * `reminders` und `activity` (Persona oder Schaufenster); gesetzt werden
 * `h.people`, `assignee_id` an den Terminen und `deleted` im Verlauf.
 */
function withPeople(P, h) {
  const spec = PEOPLE[P];
  if (!spec) throw new Error(`Personen fehlen für ${P}`);
  const byName = new Map(spec.map(s => [s.name, s]));
  const people = spec.map((s, i) => ({
    id: personId(P, s.name), no: i + 1, name: s.name, role: s.role, created_at: stamp(START, '09:00'),
    deleted_at: s.deleted ? stamp(s.deleted.on, s.deleted.at) : null,
    deleted_by: s.deleted ? personId(P, s.deleted.by) : null,
  }));
  // die gelöschte Person hat diese Einträge erledigt
  for (const s of spec.filter(x => x.takes)) {
    const taken = h.activity.filter(s.takes);
    if (taken.length !== s.count) throw new Error(`${P}: ${s.name} sollte ${s.count} Einträge haben, gefunden ${taken.length}`);
    for (const e of taken) e.by = { type: 'person', id: personId(P, s.name), name: s.name };
  }
  const gone = new Set(spec.filter(s => s.deleted).map(s => personId(P, s.name)));
  const mark = (p) => (p && gone.has(p.id) ? { ...p, deleted: true } : p);
  for (const e of h.activity) {
    if (e.by?.type === 'person') {
      if (!byName.has(e.by.name) || e.by.id !== personId(P, e.by.name)) throw new Error(`${P}: Person ${e.by.name} nicht im Verzeichnis`);
      e.by = mark(e.by);
    }
    if (e.undone?.by?.type === 'person') e.undone.by = mark(e.undone.by);
    if (e.assignee) e.assignee = mark(e.assignee);
  }
  for (const r of h.reminders) {
    if (!r.assignee) continue;
    r.assignee = REASSIGN[P]?.[r.assignee] ?? r.assignee;
    const s = byName.get(r.assignee);
    if (!s) throw new Error(`${P}: Zuständige ${r.assignee} ist nicht angelegt`);
    if (s.deleted && r.active !== false) throw new Error(`${P}: offener Termin bei gelöschter Person ${s.name}`);
    r.assignee_id = personId(P, s.name);
  }
  h.people = { last_no: people.length, people };
  return h;
}

// ═════════════════════════════════════════════════════════════════════════
// v3.6.0 (Paket abrechnung) — Anwendungsfälle, die noch kein Haushalt zeigte.
// Matrix Anwendungsfall × Haushalt: DemoCoverageTest::matrix(), der Test sichert sie ab.
// Eigene Funktionen, damit sich Änderungen an den Haushalten oben nicht mit
// diesen hier überschneiden.
// ═════════════════════════════════════════════════════════════════════════

/**
 * Abrechnung eines Gruppenvertrags über ganze Monate [from, toExcl) wie die
 * App (ConsumptionService, Gruppenvertrag v3.1.0): jedes Mitglied zu seinem
 * Arbeitspreis (`working_prices_by_meter`, sonst der des Vertrags), feste
 * Kosten und Abschläge einmal.
 */
function groupSettle(c, members, from, toExcl) {
  let kwh = 0, energy = 0, fixed = 0, advances = 0;
  for (const ym of months(from, toExcl)) {
    const a = ym + '-01', b = addMonths(a, 1);
    for (const { id, kwhOf } of members) {
      const list = c.working_prices_by_meter?.[id] ?? c.working_prices;
      const cuts = [...new Set([a, ...list.map(e => e.from).filter(d => d > a && d < b), b])].sort();
      for (let i = 0; i + 1 < cuts.length; i++) {
        const q = kwhOf(cuts[i], cuts[i + 1]);
        kwh += q;
        energy += q * valueOn(list, 'ct_per_kwh', cuts[i]) / 100;
      }
    }
    fixed += fixedPerMonth(c, a);
    advances += advanceOn(c, a) ?? 0;
  }
  const cost = energy + fixed;
  return { kwh, energy, fixed, bonus: 0, cost, advances, result: cost - advances };
}

/**
 * Eigenheim klassisch: Einliegerwohnung mit Nachtspeicherheizung —
 * Doppeltarifzähler (HT/NT) als Zählergruppe mit einem Gruppenvertrag
 * (F1006, F1017), jede Jahresrechnung erfasst und gebucht (die erste nur ab
 * dem Einzug: eine kürzere Periode, F1030); ein stillgelegter Zähler
 * (Gartenhaus, F1014), ein Home-Assistant-Alias (F1009), eine geschätzte
 * Ablesung und ein Posten auf der Wasserrechnung, den die App nicht nachbildet.
 */
function abrechnungKlassisch(p) {
  const P = p.id;
  const r = seeded(`${P}|abrechnung`);
  const strom = p.utilities.strom;
  const dates = strom.readings.filter(x => x.meter_id === 'm_strom_haus').map(x => x.date);
  const gid = 'g_strom_einlieger';
  const total = scaleTo(series(d => hdd(d) * Math.max(0, 1 + 0.15 * gauss(r)) + 0.6), 3200);
  const nt = series(d => total.get(d) * 0.72), ht = series(d => total.get(d) * 0.28);
  const mHt = meter('m_strom_einlieger_ht', 'Einliegerwohnung HT', '⚡', 'Doppeltarifzähler im Flur, Zählwerk Hochtarif',
    device('d_strom_einlieger_ht', 'S-2016-044', '2016-03-01'), { meter_group_id: gid });
  const mNt = meter('m_strom_einlieger_nt', 'Einliegerwohnung NT', '🌙', 'Doppeltarifzähler im Flur, Zählwerk Niedertarif (Nachtspeicher)',
    device('d_strom_einlieger_nt', 'S-2016-045', '2016-03-01'), { meter_group_id: gid });
  const rHt = readings('strom_einlieger_ht', mHt, dates, ht, 21480.3, 1);
  const rNt = readings('strom_einlieger_nt', mNt, dates, nt, 48211.7, 1);
  const c = contract('c_strom_heizstrom', null, 'Stadtwerke Musterstadt', 'Heizstrom HT/NT', START, null, {
    wp: wp([START, 34.6], ['2025-01-01', 31.2]), bp: bp([START, 11.9]), ap: ap([START, 95], ['2024-03-01', 105]), notice: 1,
    extra: { meter_group_id: gid, working_prices_by_meter: { [mNt.id]: wp([START, 27.3], ['2025-01-01', 23.9]) } },
  });
  const members = [{ id: mHt.id, kwhOf: linearKwh(rHt) }, { id: mNt.id, kwhOf: linearKwh(rNt) }];
  const bills = [];
  for (const [from, toExcl, issued] of [[START, '2024-01-01', '2024-01-26'], ['2024-01-01', '2025-01-01', '2025-01-24'], ['2025-01-01', '2026-01-01', '2026-01-23']]) {
    const s = groupSettle(c, members, from, toExcl);
    const sp = settlementPayment(`${P}|sp|heizstrom-${from}`, issued, s, `Jahresabrechnung Heizstrom ${from.slice(0, 4)}`);
    c.special_payments.push(sp);
    bills.push(supplierBill(`${P}|bill|heizstrom-${from}`, mHt.id, c, 'annual', from, toExcl, issued, s, { sp }));
  }
  // Gartenhaus: eigener Zähler bis Oktober 2024, dann abgemeldet (außer Betrieb, ohne Vertrag)
  const shed = scaleTo(series(d => season(d, -0.3) * Math.max(0, 1 + 0.2 * gauss(r))), 420);
  const until = dates.filter(d => d <= '2024-10-05').at(-1);
  const mShed = meter('m_strom_gartenhaus', 'Gartenhaus (stillgelegt)', '🏚️', 'Eigener Zähler bis zur Abmeldung im Herbst 2024',
    device('d_strom_gartenhaus', 'S-2011-310', '2011-05-02'), { active: false });
  const rShed = readings('strom_gartenhaus', mShed, dates.filter(d => d <= until), shed, 3104.2, 1);
  mShed.devices[0] = { ...mShed.devices[0], removed_on: until, final_counter: rShed.at(-1).counter, reason: 'Abgemeldet' };

  strom.meters.push(mHt, mNt, mShed);
  strom.readings.push(...rHt, ...rNt, ...rShed);
  strom.contracts.push(c);
  strom.bills.push(...bills);
  strom.meter_groups = [{ id: gid, name: 'Einliegerwohnung (HT/NT)', created_at: CREATED }];
  // Home Assistant schickt den Hauptzähler unter seinem Sensornamen (F1009, POST /api/ingest)
  strom.meters.find(m => m.id === 'm_strom_haus').external_id = 'sensor.hauptzaehler_strom';
  // die erste Ablesung nach dem Sommerurlaub 2025 ist geschätzt (Karte nicht zur Hand)
  const est = p.utilities.gas.readings.find(x => x.date > '2025-09-01');
  if (est) { est.is_estimated = true; est.note = 'Geschätzt, Ablesekarte lag noch im Urlaubsgepäck'; }
  // Wasserrechnung 2025: Zählermiete für den Gartenzähler — ein Posten, den die App nicht nachbildet
  const wb = p.utilities.wasser.bills[0];
  const fee = { label: 'Zählermiete Gartenzähler', amount_eur: 12.0, kind: 'fee' };
  wb.items = [fee];
  wb.invoice.amount_eur = round(wb.invoice.amount_eur + fee.amount_eur, 2);
  wb.invoice.result_eur = round(wb.invoice.amount_eur - wb.invoice.advances_paid_eur, 2);
  // … und gebucht wie Gas und Strom: der Saldo des Wasservertrags beginnt danach (v3.6.0)
  const wc = p.utilities.wasser.contracts.find(x => x.id === wb.contract_id);
  const due = wb.invoice.result_eur > 0;
  const wsp = specialPayment(`${P}|sp|wasser-2025`, wb.issued_on, due ? 'nachzahlung_ohne' : 'rueckzahlung_ohne', Math.abs(wb.invoice.result_eur),
    `Wasserabrechnung 2025 — ${due ? 'Nachzahlung' : 'Guthaben'}`);
  wc.special_payments = [...(wc.special_payments ?? []), wsp];
  wb.special_payment_id = wsp.id;
}

/**
 * Schaufenster: Fernwärme seit 2023 mit einer erfassten Rechnung 2025 —
 * die Jahresabrechnungen 2023 und 2024 sind als Sonderzahlung gebucht. Ohne
 * sie zählte der Saldo des Vertrags drei Jahre zusammen (seit v3.6.0 beginnt
 * er erst nach einer gebuchten Rechnung; die von 2025 ist bewusst noch offen).
 */
function abrechnungShowcase(d) {
  const c = d.utilities.fernwaerme.contracts.find(x => x.id === 'c_fw_001');
  const rs = d.utilities.fernwaerme.readings.filter(r => r.meter_id === 'm_fernwaerme_main').sort((a, b) => (a.date < b.date ? -1 : 1));
  c.special_payments = (c.special_payments ?? []).filter(s => !String(s.note ?? '').startsWith('Jahresabrechnung Fernwärme 202'));
  for (const [y, issued] of [['2023', '2024-02-14'], ['2024', '2025-02-12']]) {
    const s = settle(c, linearKwh(rs), `${y}-01-01`, `${+y + 1}-01-01`);
    c.special_payments.push(settlementPayment(`showcase|sp|fw-${y}`, issued, s, `Jahresabrechnung Fernwärme ${y}`));
  }
}

/** Wie BackupService schreibt (4 Leerzeichen); Beträge mit „.0“ bleiben so stehen wie im Original. */
function showcaseJson(d) {
  return JSON.stringify(d, null, 4).replace(/("signup_bonus_eur": )(\d+)(\n)/g, '$1$2.0$3') + '\n';
}

// ═════════════════════════════════════════════════════════════════════════
// v3.6.0 (Paket einpers) — eigene Vergleichswerte für die Kachel „Einordnung“
// ═════════════════════════════════════════════════════════════════════════

/**
 * Erfundene Vergleichswerte in der Größenordnung von Strom- und Heizspiegel
 * (keine Tabellenwerte). Wohnfläche und Personen stehen in den Einstellungen
 * des Haushalts — eingetragen, sonst lädt die Kachel nur ein. So zeigt
 * Eigenheim klassisch in Demo und Pages die volle Ampel: Haushaltsstrom rot
 * (der Heizstrom der Einliegerwohnung zählt mit), Heizung gelb, Wasser grün.
 */
const REFERENCE_VALUES = { 'eigenheim-klassisch': { reference_strom_kwh: 6200, reference_heat_kwh_m2: 132 } };

function withReferenceValues(p) {
  const ref = REFERENCE_VALUES[p.id];
  if (!ref) return p;
  if (!(p.settings.wohnflaeche_m2 > 0) || !(p.settings.wasser_personen_anzahl > 0)) {
    throw new Error(`${p.id}: Vergleichswerte ohne eingetragene Wohnfläche und Personen ordnen nichts ein`);
  }
  Object.assign(p.settings, ref);
  return p;
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
    // v3.3.0 (F1021) — Viertelstundenreihen (Index + Reihen wie im Backup)
    intervals: p.intervals?.index ?? { series: [], samples: {} },
    interval_files: p.intervals?.files ?? {},
    // v3.4.0 (F1024) — Verlauf erledigter Hinweise; v3.5.0 (N1026) mit erfundenen Namen
    activity: p.activity ?? [],
    // v3.6.0 — Personenverzeichnis (withPeople)
    people: p.people,
    // v3.5.0 (N1026) — nur für DemoDataAligner (Stichtag relativ zu heute), wird nicht eingespielt
    ...(p.demoAlign ? { demo_align: p.demoAlign } : {}),
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

/**
 * v3.5.0 (N1026) — Texte im Verlauf, die DemoDataTranslator übersetzt: Notiz,
 * Titel ohne Katalogschlüssel (ausgeblendete Empfehlung) und die Bausteine
 * `title`, `label`, `meter` in `params` (Firmennamen bleiben).
 */
function activityTexts(entries, out = new Set()) {
  for (const e of entries) {
    if (e.note) out.add(e.note);
    if (!e.title_key && e.title) out.add(e.title);
    for (const k of ['title', 'label', 'meter']) if (typeof e.params?.[k] === 'string' && e.params[k] !== '') out.add(e.params[k]);
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
  if (p.id === 'eigenheim-klassisch') abrechnungKlassisch(p);   // v3.6.0 (Paket abrechnung)
  withPeople(p.id, p);   // v3.6.0 — Personen und Zuständigkeiten
  withReferenceValues(p);   // v3.6.0 (Paket einpers) — Vergleichswerte der Einordnung
  const payload = backup(p);
  // Werte je Viertelstunde in einer Zeile (35.040 Zahlen je Reihe), der Rest eingerückt
  const compact = {};
  for (const [id, f] of Object.entries(payload.interval_files)) { compact[id] = JSON.stringify(f.values); f.values = `@@VALUES:${id}@@`; }
  const json = JSON.stringify(payload, null, 4).replace(/"@@VALUES:([^@]+)@@"/g, (_, id) => compact[id]) + '\n';
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
  for (const t of activityTexts(payload.activity)) if (!translations[t]) missing.add(t);
  const eck = Object.entries(p.summary).map(([k, s]) => `${k} ${JSON.stringify(yearly(s))}`).join(' · ');
  console.log(`${p.id}: ${START} … ${END} — ${eck}`);
}
{
  // v3.5.0 (N1026) — Schaufenster: nur die Ergänzungen, Rest byte-gleich
  const d = showcase();
  abrechnungShowcase(d);   // v3.6.0 (Paket abrechnung)
  withPeople('showcase', d);   // v3.6.0 — Personen und Zuständigkeiten
  // Backup und Verzeichnisform (cp -R demo-data …) führen dieselben Töpfe (DemoServiceTest)
  const files = [
    [DEMO_FILE, showcaseJson(d)],
    [join(ROOT, 'demo-data', 'reminders.json'), JSON.stringify(d.reminders, null, 4) + '\n'],
    [join(ROOT, 'demo-data', 'activity.json'), JSON.stringify(d.activity, null, 4) + '\n'],
    [join(ROOT, 'demo-data', 'people.json'), JSON.stringify(d.people, null, 4) + '\n'],   // v3.6.0
    ...Object.entries(d.utilities).filter(([, b]) => b.bills?.length)
      .map(([k, b]) => [join(ROOT, 'demo-data', k, 'bills.json'), JSON.stringify(b.bills, null, 4) + '\n']),
  ];
  for (const [file, json] of files) {
    const same = existsSync(file) && readFileSync(file, 'utf8') === json;
    if (CHECK) {
      if (!same) { console.error(`✗ ${file.slice(ROOT.length + 1)}: weicht vom Generator ab`); changed++; }
    } else if (!same) {
      writeFileSync(file, json);
      changed++;
    }
  }
  for (const t of texts({ utilities: d.utilities, reminders: d.reminders })) if (!translations[t]) missing.add(t);
  for (const t of activityTexts(d.activity)) if (!translations[t]) missing.add(t);
  console.log(`showcase: ${d.activity.length} Einträge im Verlauf, Rechnungen ${Object.values(d.utilities).reduce((n, b) => n + (b.bills?.length ?? 0), 0)}`);
}
if (missing.size) {
  console.warn(`\nOhne Übersetzung in demo-data/translations.json (${missing.size}):`);
  for (const t of missing) console.warn(`  ${t}`);
}
if (CHECK && changed) process.exit(1);
console.log(CHECK ? 'Beispielhaushalte und Schaufenster aktuell.' : `${changed} Datei(en) geschrieben (demo-data/personas/ und Ergänzungen des Schaufensters).`);
