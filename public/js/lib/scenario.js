// =====================================================================
// v3.6.0 (F1031) — Prognose-Werkstatt: Zustand, Beispielszenarien, Anfrage.
//
// Reine Funktionen ohne DOM und ohne Übersetzung — die Werkstatt
// (views/forecast.js), die Druckansicht (views/scenario-print.js) und der
// Bau der Pages-Demo (tools/build-demo.mjs) benutzen dieselben. Gerechnet
// wird nur auf dem Server (POST /api/forecast/scenario); hier entsteht nur
// die Anfrage — immer gleich aufgebaut, damit die Demo ihre vorgerechneten
// Antworten wiederfindet (demo-key.js: demoBodyKey).
//
// Gespeicherte Szenarien liegen je Gerät im Browser (localStorage) — nichts
// davon verlässt das Gerät ohne Klick.
// =====================================================================

export const MAX_MEASURES = 10;
export const STORE_KEY = 'et-scenarios';
export const CURRENT_KEY = 'et-scenario-current';

/** Maßnahmenarten mit eigener Bezeichnung im Katalog (`scenario.measure.kind.<art>`). Prozente trägt der Nutzer ein. */
export const MEASURE_KINDS = ['roof', 'facade', 'windows', 'basement', 'hydraulic', 'pump', 'thermostat', 'shower', 'other'];

/** Ausgangszustand: keine Änderung. */
export function defaultState(utility = '', meterId = '') {
  return {
    utility, meter_id: meterId, model: 'standard', regression: null,
    winter_pct: 0, room_temp_delta: 0, saving_pct: 0, baseload_pct: 0,
    ev_km: 0, ev_kwh_per_100km: null, jaz_from: null, jaz_to: null,
    measures: [], tariff: { kind: 'current' }, budget_eur: null,
  };
}

/**
 * Beispielszenarien. `needs` sagt, wann eines passt (Antwort des
 * Ausgangsfalls: capabilities, offers); `apply` setzt die Stellschrauben.
 * Die Zahlen sind Beispiele zum Ausprobieren, keine Richtwerte — die
 * Werkstatt sagt das dazu (scenario.preset.*.note).
 */
export const PRESETS = [
  {
    id: 'cold_winter',
    needs: (r) => !!r?.capabilities?.heat_model,
    // jeder zehnte Winter ist kälter (Klimanormal); ohne Klimanormal 10 % als Annahme
    apply: (s, r) => ({ ...s, winter_pct: r?.winter_presets?.cold_pct ?? 10 }),
  },
  {
    id: 'roof',
    needs: (r) => !!r?.capabilities?.heat_model,
    apply: (s) => ({ ...s, measures: [{ id: 'roof', kind: 'roof', label: '', heat_pct: 15, base_pct: 0, cost_eur: null, active: true }] }),
  },
  {
    id: 'cheapest',
    needs: (r) => !!r?.capabilities?.tariff && (r?.offers || []).some(o => o.computable),
    apply: (s) => ({ ...s, tariff: { kind: 'cheapest' } }),
  },
  {
    id: 'save10',
    needs: () => true,
    apply: (s) => ({ ...s, saving_pct: 10 }),
  },
];

/** Beispielszenario auf einen frischen Zustand anwenden (gleicher Zähler). */
export function presetState(id, base, baselineResult) {
  const p = PRESETS.find(x => x.id === id);
  const fresh = defaultState(base.utility, base.meter_id);
  return p ? p.apply(fresh, baselineResult) : fresh;
}

/** Beispielszenarien, die zu diesem Zähler passen. */
export function presetsFor(baselineResult) {
  return PRESETS.filter(p => p.needs(baselineResult)).map(p => p.id);
}

const num = (v) => {
  if (v === '' || v == null) return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};

/**
 * Die Anfrage zu einem Zustand — immer dieselben Felder in derselben
 * Reihenfolge, Leeres mit seinem Ausgangswert. Optionale Felder nur, wenn
 * gesetzt.
 */
export function scenarioBody(s) {
  const tariff = s.tariff || { kind: 'current' };
  const t = { kind: tariff.kind || 'current' };
  if (t.kind === 'contract') t.contract_id = String(tariff.contract_id || '');
  if (t.kind === 'custom') {
    t.working_price_ct = num(tariff.working_price_ct);
    t.base_eur_month = num(tariff.base_eur_month) ?? 0;
  }
  const body = {
    utility: s.utility,
    meter_id: s.meter_id,
    model: s.model || 'standard',
    winter_pct: num(s.winter_pct) ?? 0,
    room_temp_delta: num(s.room_temp_delta) ?? 0,
    saving_pct: num(s.saving_pct) ?? 0,
    baseload_pct: num(s.baseload_pct) ?? 0,
    ev_km: num(s.ev_km) ?? 0,
    measures: (s.measures || []).slice(0, MAX_MEASURES).map(m => ({
      id: String(m.id),
      kind: m.kind || null,
      label: String(m.label || ''),
      heat_pct: num(m.heat_pct) ?? 0,
      base_pct: num(m.base_pct) ?? 0,
      cost_eur: num(m.cost_eur),
      active: m.active !== false,
    })),
    tariff: t,
  };
  if (s.regression) body.regression = s.regression;
  if (num(s.ev_kwh_per_100km) != null) body.ev_kwh_per_100km = num(s.ev_kwh_per_100km);
  if (num(s.jaz_from) != null && num(s.jaz_to) != null) { body.jaz_from = num(s.jaz_from); body.jaz_to = num(s.jaz_to); }
  if (num(s.budget_eur) != null) body.budget_eur = num(s.budget_eur);
  return body;
}

/** Ändert das Szenario etwas gegenüber dem Ausgangsfall? */
export function isBaseline(s) {
  const b = scenarioBody(s);
  return b.winter_pct === 0 && b.room_temp_delta === 0 && b.saving_pct === 0 && b.baseload_pct === 0
    && b.ev_km === 0 && b.tariff.kind === 'current' && !b.measures.some(m => m.active && (m.heat_pct || m.base_pct))
    && b.jaz_from == null;
}

// ── Gespeicherte Szenarien (je Gerät) ─────────────────────────────────

// Im Browser ist globalThis das Fenster; in Tests (JSDOM) zählt dessen Speicher
const local = () => (typeof window !== 'undefined' && window.localStorage) || globalThis.localStorage;
const session = () => (typeof window !== 'undefined' && window.sessionStorage) || globalThis.sessionStorage;

function readStore(storage) {
  try {
    const list = JSON.parse(storage?.getItem(STORE_KEY) || '[]');
    return Array.isArray(list) ? list.filter(x => x && typeof x.name === 'string' && x.state) : [];
  } catch { return []; }
}

/** Gespeicherte Szenarien, neueste zuerst. */
export function savedScenarios(storage = local()) {
  return readStore(storage);
}

/** Speichern unter einem Namen (gleicher Name ersetzt). Höchstens 20. */
export function saveScenario(name, state, storage = local()) {
  const clean = String(name || '').trim().slice(0, 60);
  if (!clean) return false;
  const list = readStore(storage).filter(x => x.name !== clean);
  list.unshift({ name: clean, state: JSON.parse(JSON.stringify(state)), saved_at: new Date().toISOString() });
  try { storage?.setItem(STORE_KEY, JSON.stringify(list.slice(0, 20))); return true; } catch { return false; }
}

export function deleteScenario(name, storage = local()) {
  try { storage?.setItem(STORE_KEY, JSON.stringify(readStore(storage).filter(x => x.name !== name))); } catch { /* gesperrt */ }
}

/** Der Zustand der Werkstatt für die Druckansicht (diese Sitzung). */
export function rememberCurrent(state, name = '', storage = session()) {
  try { storage?.setItem(CURRENT_KEY, JSON.stringify({ name, state })); } catch { /* gesperrt */ }
}

export function currentScenario(storage = session()) {
  try {
    const v = JSON.parse(storage?.getItem(CURRENT_KEY) || 'null');
    return v && v.state ? v : null;
  } catch { return null; }
}

// ── CSV ───────────────────────────────────────────────────────────────

/**
 * Monatswerte als CSV (Semikolon, Punkt als Dezimalzeichen — wie CSV-Format 1
 * der App). `head` sind die übersetzten Spaltenköpfe.
 */
export function scenarioCsv(result, head) {
  const cols = ['ym', 'base_qty', 'scen_qty', 'base_cost', 'scen_cost', 'base_cum_cost', 'scen_cum_cost', 'advance'];
  const cell = (v) => (v == null ? '' : String(v));
  const quote = (s) => /[;"\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  const lines = [cols.map((c, i) => quote(String(head?.[i] ?? c))).join(';')];
  for (const m of result?.months || []) lines.push(cols.map(c => cell(m[c])).join(';'));
  return lines.join('\n') + '\n';
}
