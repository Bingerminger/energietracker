// =====================================================================
// v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“ ohne Oberfläche:
// welche Aufbauten zu den Antworten passen, die Schaltbilder (Inline-SVG auf
// lib/illustrations.js), die Kürzel Z1, Z2 … je Stelle, die Vorauswahl
// vorhandener Zähler und der Körper für POST /api/metering-concept.
//
// Aufbauten (wie MeteringConceptService):
//   single        ein Zähler für alles; mit PV ein Zweirichtungszähler
//   side_by_side  Gerät mit eigenem Zähler neben dem Hauszähler
//   behind        Gerät als Subzähler hinter dem Hauszähler
//   cascade       vorn misst alles, dahinter der Haushalt; das Gerät ist der Rest
//
// Stellen: main, house (Kaskade), dev, feed, gen, bat_in, bat_out. Die
// Schaltbilder zeichnen Leitungen von links (Netz) nach rechts (Verbraucher),
// Abzweige als Knoten, unter jedem Zähler seine Zählwerke. Farben nur über die
// Klassen .ill-* (hell und dunkel), Beschriftung aus den Katalogen.
// =====================================================================

import { t } from './i18n.js';
import { svg, shapes } from './illustrations.js';

export const CONCEPTS = ['single', 'side_by_side', 'behind', 'cascade'];
export const DEVICES = ['heat_pump', 'ev_charger'];

/** Antworten vereinheitlichen: Speicher nur mit PV, HT/NT am Gerät nur mit Gerät. */
export function normalizeSetup(s = {}) {
  const device = DEVICES.includes(s.device) ? s.device : null;
  const pv = !!s.pv;
  let tou = ['none', 'all', 'device'].includes(s.tou) ? s.tou : 'none';
  if (tou === 'device' && !device) tou = 'none';
  return { device, pv, battery: pv && !!s.battery, tou };
}

/** Aufbauten, die zu den Antworten passen: ohne Gerät mit eigenem Tarif nur einer. */
export function conceptsFor(setup) {
  return normalizeSetup(setup).device ? ['side_by_side', 'behind', 'cascade'] : ['single'];
}

/** Geht dieser Aufbau mit dieser HT/NT-Antwort? (Kaskade: HT/NT an beiden oder keinem) */
export function conceptAllowed(concept, setup) {
  const s = normalizeSetup(setup);
  if (!conceptsFor(s).includes(concept)) return false;
  return !(concept === 'cascade' && s.tou === 'device');
}

/** Titel und Satz eines Aufbaus (Katalog). */
export function layoutTitle(concept) { return t(`concept.layout.${concept}.title`); }
export function layoutText(concept, setup) {
  const s = normalizeSetup(setup);
  if (concept === 'single') return t(s.pv ? 'concept.layout.single.textPv' : 'concept.layout.single.text');
  return t(`concept.layout.${concept}.text`, { deviceThe: t(`concept.deviceThe.${s.device || 'heat_pump'}`) });
}

const REG = { one: '1.8.0', ht: '1.8.1', nt: '1.8.2' };
/**
 * Zählwerke je Stelle, wie der Server sie vorbelegt (nur fürs Bild, bevor die
 * Vorschau die echten liefert): Schlüssel → OBIS-Kennzahl.
 */
export function defaultRegisters(concept, setup) {
  const s = normalizeSetup(setup);
  const tou = (on) => (on ? { ht: REG.ht, nt: REG.nt } : { one: REG.one });
  const all = s.tou === 'all', dev = s.tou === 'device';
  const out = {};
  if (concept === 'cascade') { out.main = tou(all || dev); out.house = tou(all); }
  else out.main = tou(all);
  if (concept === 'side_by_side' || concept === 'behind') out.dev = tou(all || dev);
  if (s.pv) {
    out.feed = { one: '2.8.0' };
    out.gen = { one: '' };
    if (s.battery) { out.bat_in = { one: '' }; out.bat_out = { one: '' }; }
  }
  return out;
}

/**
 * Kürzel je Stelle wie im Issue: Z1 vorn bzw. Hauszähler, Z2 der zweite
 * Bezugszähler (Haushalt in der Kaskade, sonst das Gerät), Z3 die PV-Erzeugung,
 * Z4 der Speicher. Die Einspeisung ist das zweite Zählwerk von Z1
 * (Zweirichtungszähler) und trägt dessen Kürzel.
 */
export function slotTags(concept, setup) {
  const s = normalizeSetup(setup);
  const tags = { main: 'Z1' };
  let n = 2;
  if (concept === 'cascade') tags.house = `Z${n++}`;
  if (concept === 'side_by_side' || concept === 'behind') tags.dev = `Z${n++}`;
  if (s.pv) {
    tags.feed = tags.main;
    tags.gen = `Z${n++}`;
    if (s.battery) { tags.bat_in = tags.bat_out = `Z${n++}`; }
  }
  return tags;
}

/** Kennzahl kurz fürs Schild: `1-0:1.8.1*255` → `1.8.1`. */
export function shortObis(obis) {
  const m = String(obis || '').match(/(\d{1,3})\.(\d{1,3})\.(\d{1,3})/);
  return m ? `${+m[1]}.${+m[2]}.${+m[3]}` : '';
}

const ROW = 64, TOP = 30, BUS = 104, M2 = 122, LOAD = 196, MW = 34;

/**
 * Schaltbild eines Aufbaus als SVG (role="img" mit Titel aus dem Katalog).
 *
 * @param {string} concept
 * @param {object} setup  {device, pv, battery, tou}
 * @param {object} [o]
 * @param {object} [o.registers]  Stelle → {zählwerk: obis} (sonst die Vorbelegung)
 * @param {string} [o.title]      Name des Bilds (sonst „Schaltbild: <Aufbau>“)
 */
export function schematic(concept, setup, { registers = null, title = '' } = {}) {
  const s = normalizeSetup(setup);
  const regs = registers || defaultRegisters(concept, s);
  const tags = slotTags(concept, s);
  const chips = (key) => Object.values(regs[key] || {}).map(shortObis).filter(Boolean);
  // die Zählwerke von Z1 samt Einspeisung (gleicher Zähler)
  const mainChips = [...chips('main'), ...(s.pv ? chips('feed') : [])];
  const device = s.device === 'ev_charger' ? 'wallbox' : 'heatpump';
  const side = concept === 'side_by_side';

  // Abzweige hinter dem Hauszähler: Haushalt, PV, Speicher; das Gerät (außer bei single) zuletzt
  const taps = [{ kind: 'house', meter: concept === 'cascade' ? 'house' : null }];
  if (s.pv) taps.push({ kind: 'pv', meter: 'gen' });
  if (s.battery) taps.push({ kind: 'battery', meter: 'bat_in' });
  if (concept !== 'single') taps.push({ kind: 'device', meter: concept === 'cascade' ? null : 'dev' });
  const ys = taps.map((_, i) => TOP + i * ROW);
  const last = ys[ys.length - 1];
  const yMain = ys.length > 1 ? (ys[0] + last) / 2 : ys[0];

  let body = '';
  const meter = (x, y, key, list) => shapes.meter(x, y - MW / 2, { w: MW, h: MW, label: tags[key] || '', util: key === 'gen' || key === 'bat_in' ? 'pv' : 'strom' })
    + shapes.registers(x + 2, y + MW / 2 + 3, list);
  const load = (kind, y) => {
    if (kind === 'house') return shapes.house(LOAD, y - 30, 48, 44);
    if (kind === 'pv') return shapes.panel(LOAD + 4, y + 8, 30) + shapes.sun(LOAD + 50, y - 16, 4.5);
    if (kind === 'battery') return shapes.battery(LOAD + 2, y - 13, { w: 16, h: 26 });
    return device === 'wallbox' ? shapes.wallbox(LOAD + 4, y - 13, { w: 18, h: 26 }) : shapes.heatpump(LOAD, y - 14, { w: 40, h: 28 });
  };
  const loadX = (kind) => (kind === 'pv' ? LOAD + 8 : kind === 'house' ? LOAD + 4 : kind === 'battery' ? LOAD + 2 : kind === 'device' && device === 'wallbox' ? LOAD + 4 : LOAD);
  /** Abzweig von der Sammelleitung bei x0 zur Last, mit Zähler oder ohne. */
  const tap = (x0, tp, y) => {
    let out = '';
    if (tp.meter) {
      out += shapes.wire([[x0, y], [M2, y]]) + meter(M2, y, tp.meter, tp.meter === 'gen' || tp.meter === 'bat_in' ? [] : chips(tp.meter));
      out += shapes.wire([[M2 + MW, y], [loadX(tp.kind), y]]);
    } else {
      out += shapes.wire([[x0, y], [loadX(tp.kind), y]]);
    }
    out += load(tp.kind, y);
    // Kaskade: das Gerät ist der Rest, vorn minus Haushalt
    if (concept === 'cascade' && tp.kind === 'device') out += shapes.caption(LOAD + 20, y + 24, `${tags.main} − ${tags.house}`);
    return out;
  };
  /**
   * Sammelleitung bei x über die Zeilen der Abzweige. Ein Knoten steht, wo
   * drei Leitungen sich treffen: an jedem inneren Abzweig und dort, wo die
   * Zuleitung ankommt (an den Enden ist es sonst nur eine Ecke).
   */
  const bus = (x, rows, feedY) => {
    if (rows.length < 2) return '';
    const top = rows[0], bottom = rows[rows.length - 1];
    const nodes = new Set(rows.slice(1, -1));
    if (feedY >= top && feedY <= bottom) nodes.add(feedY);
    return shapes.bus(x, top, bottom, { nodes: [...nodes] });
  };

  if (!side) {
    // Netz → Z1 → Sammelleitung → Abzweige
    body += shapes.grid(14, yMain + 17) + shapes.wire([[22, yMain], [40, yMain]], { strong: true });
    body += meter(40, yMain, 'main', mainChips);
    body += shapes.wire([[40 + MW, yMain], [BUS, yMain]], { strong: true });
    if (s.pv) body += shapes.arrow(BUS - 6, yMain - 8, 40 + MW + 6, yMain - 8);   // Einspeisung zurück ins Netz
    if (taps.length === 1) body += shapes.wire([[BUS, yMain], [loadX(taps[0].kind), yMain]]) + load(taps[0].kind, yMain);
    else {
      body += bus(BUS, ys, yMain);
      taps.forEach((tp, i) => { body += tap(BUS, tp, ys[i]); });
    }
  } else {
    // Netz → Abzweig: oben Hauszähler (Z1) mit Haushalt, PV, Speicher; unten Zähler des Geräts (Z2)
    const J = 32;
    body += shapes.grid(14, yMain + 17) + shapes.wire([[22, yMain], [J, yMain]], { strong: true });
    body += shapes.bus(J, ys[0], last, { nodes: yMain > ys[0] && yMain < last ? [yMain] : [] });
    const house = taps.filter(tp => tp.kind !== 'device');
    const hy = ys.slice(0, house.length);
    body += shapes.wire([[J, ys[0]], [50, ys[0]]], { strong: true }) + meter(50, ys[0], 'main', mainChips);
    body += shapes.wire([[50 + MW, ys[0]], [BUS, ys[0]]]);
    if (s.pv) body += shapes.arrow(BUS - 4, ys[0] - 8, 50 + MW + 4, ys[0] - 8);
    if (house.length === 1) body += shapes.wire([[BUS, ys[0]], [loadX('house'), ys[0]]]) + load('house', ys[0]);
    else {
      body += bus(BUS, hy, ys[0]);
      house.forEach((tp, i) => { body += tap(BUS, tp, hy[i]); });
    }
    body += shapes.wire([[J, last], [50, last]], { strong: true }) + meter(50, last, 'dev', chips('dev'));
    body += shapes.wire([[50 + MW, last], [loadX('device'), last]]) + load('device', last);
  }
  const chipsBelow = (y, list) => y + MW / 2 + 3 + list.length * 12;
  const height = Math.ceil(Math.max(last + 30, chipsBelow(side ? ys[0] : yMain, mainChips), chipsBelow(last, chips('dev'))) + 6);
  return svg(body, {
    viewBox: `0 0 262 ${height}`,
    title: title || t('concept.art.title', { name: layoutTitle(concept) }),
    className: 'ill--concept',
  });
}

/** Bezeichnung eines Zählwerks: Bezug, Bezug HT, Einspeisung, Erzeugung … */
export function registerLabel(slot, r) {
  if (slot?.utility === 'pv_einspeisung') return t('meters.obis.export');
  if (slot?.utility === 'pv_erzeugung') return t(`meters.roles.pv_erzeugung.${slot.role || 'generation'}`);
  return t(r === 'ht' ? 'meters.obis.importHt' : r === 'nt' ? 'meters.obis.importNt' : 'meters.obis.import');
}

/**
 * Vorhandene Zähler den Stellen zuordnen, wo es eindeutig ist, damit auf einem
 * bestehenden Haushalt nichts doppelt entsteht (der Standardzähler einer
 * Neuinstallation wird Z1). Eindeutig heißt: genau ein passender Zähler.
 *   • Hauptstelle (ohne Elternstelle): der einzige Zähler der Art ohne Elternzähler
 *     (bei HT/NT je Zählwerk der mit diesem Zählwerk, Kennzahl oder Name)
 *   • Stelle hinter einer anderen: der einzige Subzähler des dort gewählten Zählers
 *     mit passender Rolle (bzw. ohne Rolle)
 *   • Gerät neben dem Hauszähler: der einzige Zähler mit der Rolle des Geräts
 *   • Einspeisung, Erzeugung, Speicher: der einzige Zähler der Art mit dieser Rolle
 *
 * @param {object} slots   aus der Vorschau: Stelle → {utility, role, parent, registers}
 * @param {object} meters  Verbrauchsart → Zählerliste
 * @returns {object}  Stelle → {meter_id} bzw. {meter_ids: {ht, nt}}
 */
export function suggestAssignments(slots, meters) {
  const out = {};
  const used = new Set();
  const reg = (m) => shortObis(m.obis) || (/\b(HT|Hochtarif|Tag)\b/i.test(m.name || '') ? REG.ht : /\b(NT|Niedertarif|Nacht)\b/i.test(m.name || '') ? REG.nt : '');
  const pick = (list) => { const free = list.filter(m => !used.has(m.id)); return free.length === 1 ? free[0] : null; };
  const assign = (key, slot, candidates) => {
    const keys = Object.keys(slot.registers || {});
    if (keys.length === 1) {
      const m = pick(candidates);
      if (m) { out[key] = { meter_id: m.id }; used.add(m.id); }
      return;
    }
    const ids = {};
    for (const r of keys) {
      const m = pick(candidates.filter(c => reg(c) === shortObis(slot.registers[r])));
      if (m) { ids[r] = m.id; used.add(m.id); }
    }
    if (Object.keys(ids).length) out[key] = { meter_ids: ids };
  };
  const active = (u) => (meters[u] || []).filter(m => m.active !== false);
  const roleOf = (m) => m.role || null;
  const order = Object.keys(slots).sort((a, b) => (slots[a].parent ? 1 : 0) - (slots[b].parent ? 1 : 0));
  for (const key of order) {
    const slot = slots[key];
    const list = active(slot.utility);
    if (slot.utility === 'strom' && !slot.parent) {
      // Gerät neben dem Hauszähler: nach Rolle; sonst der Zähler ohne Elternzähler
      const byRole = slot.role && slot.role !== 'household' ? list.filter(m => roleOf(m) === slot.role && !m.parent_meter_id) : [];
      if (key === 'dev') { assign(key, slot, byRole); continue; }
      assign(key, slot, list.filter(m => !m.parent_meter_id && (!roleOf(m) || roleOf(m) === slot.role || roleOf(m) === 'household')));
      continue;
    }
    if (slot.utility === 'strom') {
      const parent = out[slot.parent];
      const parentIds = parent ? (parent.meter_id ? [parent.meter_id] : Object.values(parent.meter_ids || {})) : [];
      if (!parentIds.length) continue;
      assign(key, slot, list.filter(m => parentIds.includes(m.parent_meter_id)
        && (!roleOf(m) || roleOf(m) === slot.role || (slot.role === 'household' && roleOf(m) === 'household'))));
      continue;
    }
    // Einspeisung, Erzeugung, Speicher: nach Rolle (Erzeugung: Rolle „generation“ oder keine)
    assign(key, slot, list.filter(m => (slot.role ? roleOf(m) === slot.role : !roleOf(m) || roleOf(m) === 'generation')));
  }
  return out;
}

/**
 * Kennung der Stellen einer Vorschau: Art, Rolle, Elternstelle und Zählwerke je
 * Stelle. Ändert sie sich (anderes Gerät, HT/NT, anderer Aufbau), belegt der
 * Assistent Namen, Zuordnung und Zählwerke neu, statt alte Eingaben zu behalten.
 */
export function slotSignature(slots) {
  return JSON.stringify(Object.entries(slots || {}).map(([k, s]) => [k, s.utility, s.role || null, s.parent || null, Object.keys(s.registers || {})]));
}

/**
 * Körper für Vorschau und Anlegen aus den Eingaben des Assistenten. Leere
 * Felder fallen weg; die Einspeisung bekommt ohne eigene die Nummer von Z1.
 */
export function conceptPayload(concept, setup, slotsInput = {}, contractsInput = {}) {
  const s = normalizeSetup(setup);
  const slots = {};
  for (const [key, v] of Object.entries(slotsInput)) {
    const o = {};
    if (v.name?.trim()) o.name = v.name.trim();
    if (v.serial?.trim()) o.serial = v.serial.trim();
    if (v.meter_id) o.meter_id = v.meter_id;
    const ids = Object.fromEntries(Object.entries(v.meter_ids || {}).filter(([, id]) => id));
    if (Object.keys(ids).length) o.meter_ids = ids;
    const obis = Object.fromEntries(Object.entries(v.obis || {}).map(([r, x]) => [r, String(x || '').trim()]).filter(([, x]) => x));
    if (Object.keys(obis).length) o.obis = obis;
    slots[key] = o;
  }
  if (slots.feed && !slots.feed.serial && slots.main?.serial) slots.feed.serial = slots.main.serial;
  const num = (x) => (x === '' || x == null ? null : Number(String(x).replace(',', '.')));
  const contracts = {};
  for (const [key, c] of Object.entries(contractsInput)) {
    const o = { provider: (c.provider || '').trim(), start: c.start || '' };
    for (const f of ['ct', 'ct_nt', 'base', 'advance']) { const v = num(c[f]); if (v !== null && !Number.isNaN(v)) o[f] = v; }
    if (!o.provider && o.ct === undefined) continue;   // nichts eingetragen: später
    contracts[key] = o;
  }
  return { concept, ...s, slots, contracts };
}
