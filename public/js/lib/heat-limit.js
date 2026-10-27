// =====================================================================
// v3.9.0 (GitHub #38) — Heizgrenze-Vorschlag dort, wo seine Folgen sichtbar
// sind: „Zu tun“ auf der Übersicht, Saldo-Karte, Prognose und Werkstatt.
//
// Bis v3.7 stand der Vorschlag nur in der Analyse. Auf echten Daten stimmte
// die Gas-Prognose erst halbwegs, nachdem der Maintainer ihn dort gefunden und
// übernommen hatte: Prognose, Saldo und Abschlag hängen am Heizmodell. Eine
// Quelle für Texte und Knöpfe, damit alle Stellen dasselbe sagen:
//   GET …/heat-limit (ConsumptionService::heatLimitSuggestion + heatLimitEffect)
//   PATCH …/meters/{id} bzw. …/meter-groups/{id} mit `hdd_base_temp`,
//   danach „Rückgängig“ (vorheriger Wert oder leer = Standard).
// =====================================================================
import { api } from '../api.js';
import { t } from './i18n.js';
import { fmt, escapeHtml } from './format.js';
import { toastErr, toastOk, toastUndo } from '../components/toast.js';

/** Eine Gruppe (Gruppenvertrag) statt eines Zählers? IDs nach MeterService::createGroup. */
export const isGroupId = (id) => String(id || '').startsWith('g_');

/**
 * v3.9.0 (Skeptiker N5) — der Heizgrenze-Vorschlag kennt eine Art Aufschub: „Nicht jetzt“ bis neue
 * Monate gemessen sind (die Empfehlung trägt den Datenstand in ihrer ID), höchstens ein Jahr. So
 * in „Zu tun“ und in den Empfehlungen; dort stand bis dahin daneben „30 Tage ausblenden“.
 */
export const isHeatLimitRec = (r) => String(r?.id || '').startsWith('r_heat_limit_');
export const HEAT_LIMIT_LATER_DAYS = 365;
export function heatLimitLaterUntil(now = Date.now()) {
  return new Date(now + HEAT_LIMIT_LATER_DAYS * 86400000).toISOString().slice(0, 10);
}

const memo = new Map();

/**
 * Vorschlag eines Zählers oder einer Gruppe (`heat_limit` oder null). Nur
 * gleichzeitige Anfragen teilen sich eine Antwort (Prognose und Werkstatt fragen
 * beide); nach wenigen Sekunden fragt die nächste Ansicht neu, damit nach dem
 * Speichern eines Zählers kein alter Stand stehen bleibt. `fresh` erzwingt es.
 */
export function loadHeatLimit(utility, id, { fresh = false } = {}) {
  const key = `${utility}|${id}`;
  if (fresh) memo.delete(key);
  if (!memo.has(key)) {
    memo.set(key, api.heatLimit(utility, id, isGroupId(id)).then(r => r?.heat_limit ?? null).catch(() => null));
    setTimeout(() => memo.delete(key), 3000);
  }
  return memo.get(key);
}

const deg = (v) => fmt.num(Number(v), 1);
const pct = (v) => v == null ? '–' : fmt.pct(Number(v), 0);

/** Vorher und nachher: Streuung je Monat und Grundlast im Sommer (je Tag). */
export function heatLimitFacts(h, unit = 'kWh') {
  if (!h?.before || !h?.after) return [];
  const out = [t('heatLimit.fit', { best: deg(h.best), now: pct(h.before.ratio), then: pct(h.after.ratio) })];
  const b = h.before, a = h.after;
  const now = b.base_per_day ?? b.model_per_day;
  const then = a.base_per_day ?? a.model_per_day;
  if (now != null && then != null && b.measured_per_day != null) {
    out.push(t('heatLimit.baseLoad', { best: deg(h.best), unit, now: fmt.num(now, 1), then: fmt.num(then, 1), measured: fmt.num(b.measured_per_day, 1) }));
  }
  if (Array.isArray(h.range) && h.range[1] - h.range[0] > 0.01) {
    out.push(t('heatLimit.range', { from: deg(h.range[0]), to: deg(h.range[1]) }));
  }
  return out;
}

// Saldo in Worten wie die Saldo-Karte (positiv = Nachzahlung); eine Spanne, wo die Schätzung unsicher ist
function balanceWords(side) {
  const word = (v) => Math.abs(v) < 1 ? t('balanceUncertain.even')
    : t(v > 0 ? 'balanceUncertain.due' : 'balanceUncertain.credit', { amount: t('report.fmt.approx', { amount: fmt.eur0(Math.abs(v)) }) });
  const r = Array.isArray(side?.balance_range) ? side.balance_range.map(Number) : null;
  if (r && r.length === 2 && r.every(Number.isFinite)) {
    return t('heatLimit.effect.between', { low: word(Math.min(...r)), high: word(Math.max(...r)) });
  }
  return side?.balance != null ? word(Number(side.balance)) : '–';
}

function advanceWords(side) {
  const r = side?.advance_range;
  if (Array.isArray(r) && r.length === 2) return t('heatLimit.effect.advanceRange', { low: fmt.eur0(r[0]), high: fmt.eur0(r[1]) });
  if (side?.advance != null) return t('heatLimit.effect.advanceValue', { amount: fmt.eur0(side.advance) });
  return t('heatLimit.effect.noAdvance');
}

/** Wirkung auf erwarteten Saldo und Abschlag des laufenden Vertrags (oder leer). */
export function heatLimitEffect(h) {
  const e = h?.effect;
  if (!e?.before || !e?.after) return [];
  const out = [t('heatLimit.effect.balance', { date: e.until ? fmt.date(e.until) : '–', best: deg(h.best),
    before: balanceWords(e.before), after: balanceWords(e.after) })];
  const changed = advanceWords(e.before) !== advanceWords(e.after);
  if (changed) out.push(t('heatLimit.effect.advance', { best: deg(h.best), before: advanceWords(e.before), after: advanceWords(e.after),
    current: e.current_advance != null ? fmt.eur0(e.current_advance) : '–' }));
  return out;
}

/**
 * v3.9.0 (Skeptiker N4) — für Einsteiger: ein Satz ohne Fachwörter, dazu die Wirkung auf den
 * erwarteten Saldo und (wenn er sich ändert) den passenden Abschlag. Die Fachzeilen
 * (heatLimitFacts, heatLimitEffect) stehen aufklappbar darunter.
 */
export function heatLimitBeginnerLines(h) {
  const out = [t('heatLimit.beginner.sentence', { best: deg(h.best) })];
  const e = h?.effect;
  if (!e?.before || !e?.after) return out;
  out.push(t('heatLimit.beginner.balance', { date: e.until ? fmt.date(e.until) : '–', before: balanceWords(e.before), after: balanceWords(e.after) }));
  const has = (side) => side?.advance != null || Array.isArray(side?.advance_range);
  const before = advanceWords(e.before), after = advanceWords(e.after);
  if (has(e.after) && before !== after) {
    out.push(t(has(e.before) ? 'heatLimit.beginner.advance' : 'heatLimit.beginner.advanceOnly', { before, after }));
  }
  return out;
}

/**
 * Hinweis mit Knopf für Saldo-Karte, Prognose und Werkstatt (`context`: balance,
 * forecast, workshop). Leer ohne Vorschlag.
 */
export function heatLimitHintHtml(h, { context = 'balance', unit = 'kWh', id = '' } = {}) {
  if (!h?.suggest || h.best == null) return '';
  const group = isGroupId(id);
  const lines = [...heatLimitFacts(h, unit), ...(context === 'balance' || context === 'workshop' ? heatLimitEffect(h) : []),
    t(group ? 'heatLimit.scopeGroup' : 'heatLimit.scope')];
  return `<div class="banner banner--info heat-limit-hint" data-role="heat-limit-hint" data-context="${escapeHtml(context)}">
      <p>${escapeHtml(t(`heatLimit.hint.${context}`))} <strong>${escapeHtml(t('heatLimit.title', { best: deg(h.best) }))}.</strong></p>
      <p class="small">${lines.map(escapeHtml).join(' ')}</p>
      <button type="button" class="btn btn--sm btn--util" data-role="heat-limit-hint-apply" data-value="${escapeHtml(String(h.best))}">${escapeHtml(t(group ? 'heatLimit.applyGroup' : 'heatLimit.apply'))}</button>
    </div>`;
}

/**
 * Heizgrenze übernehmen (nur dieser Zähler bzw. diese Gruppe), mit
 * „Rückgängig“: zurück auf die eigene Grenze von vorher oder auf den Standard.
 * `previous`: die eigene Grenze vorher (null = keine).
 */
export async function applyHeatLimit({ utility, id, value, previous = null, onChange = () => {} }) {
  const group = isGroupId(id);
  const patch = (v) => group ? api.updateMeterGroup(utility, id, { hdd_base_temp: v }) : api.updateMeter(utility, id, { hdd_base_temp: v });
  try {
    await patch(Number(value));
  } catch (e) { toastErr(e.message); return false; }
  memo.delete(`${utility}|${id}`);
  toastUndo(t(group ? 'heatLimit.appliedGroup' : 'heatLimit.applied', { value: deg(value) }), async () => {
    try {
      await patch(previous == null ? null : Number(previous));
      memo.delete(`${utility}|${id}`);
      toastOk(t('heatLimit.undone'));
      await onChange();
    } catch (e) { toastErr(e.message); }
  });
  await onChange();
  return true;
}

/**
 * Einen Platzhalter füllen: laden, Hinweis zeichnen, Knopf binden. Ohne Vorschlag
 * bleibt er leer. `utility`: Schlüssel der Verbrauchsart.
 */
export async function fillHeatLimitSlot(slot, { utility, id, context, unit, onChange }) {
  if (!slot || !utility || !id) return;
  const h = await loadHeatLimit(utility, id);
  if (!slot.isConnected) return;
  slot.innerHTML = heatLimitHintHtml(h, { context, unit, id });
  slot.querySelector('[data-role="heat-limit-hint-apply"]')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    const ok = await applyHeatLimit({ utility, id, value: btn.dataset.value, previous: h?.own ? h.current : null, onChange });
    if (!ok) btn.disabled = false;
  });
}

/** Braucht diese unsichere Schätzung den Hinweis? (schlecht passendes Heizmodell) */
export const poorFitIn = (reasons) => Array.isArray(reasons) && reasons.includes('poor_fit');
