// =====================================================================
// v3.9.0 (ablesung-pruefen) — Zwischenablesung prüfen.
//
// Ein falscher Stand zwischen zwei richtigen verschiebt Verbrauch still von
// einem Abschnitt in den nächsten. Der Server findet solche Stände
// (ReadingCheckService) und meldet sie als Ereignis in „Zu tun“
// (`reading_check`, `reading_duplicate`, `level_shift`) und je Zähler über
// GET …/meters/{id}/reading-checks. Eine Quelle für Texte und Knöpfe, damit
// Übersicht und Liste der Ablesungen dasselbe sagen:
//   Stand ist richtig      PATCH …/readings/{id} {is_verified: true}, Rückgängig
//   Nur für die Abrechnung PATCH …/readings/{id} {is_billing_only: true}, Rückgängig
//   Korrigieren            Bearbeiten-Dialog der Ablesung (#/utility/…&edit_reading=…)
//   Eine löschen           DELETE …/readings/{id}, Rückgängig legt sie neu an
//   Zäsur hier setzen      Dialog (Tag vorbelegt), dann PATCH …/meters/{id} mit baseline_events, Rückgängig
//   Nicht jetzt            POST /api/agenda/done (abhaken), Rückgängig
// Die App korrigiert nie selbst.
// =====================================================================
import { api } from '../api.js';
import { t, tp } from './i18n.js';
import { fmt, escapeHtml } from './format.js';
import { toastErr, toastOk, toastUndo } from '../components/toast.js';
import { openModal } from '../components/modal.js';

const amount = (v, unit) => `${fmt.num(Number(v), 0)} ${unit || ''}`.trim();
const span = (s) => ({ from: fmt.date(s.from), to: fmt.date(s.to) });

/**
 * Zeilen zu einem Befund (`ref` eines `reading_check`-Ereignisses oder ein
 * Eintrag aus `checks`): gemessen gegen erwartet davor und danach, Vermutung.
 */
export function readingCheckLines(c) {
  if (!c?.before || !c?.after) return [];
  const unit = c.unit || '';
  const side = (key, s) => t(key, { ...span(s), measured: amount(s.measured, unit), expected: amount(s.expected, unit) });
  const n = Number(c.count) || (Array.isArray(c.reading_ids) ? c.reading_ids.length : 1);
  return [
    side('readingCheck.before', c.before),
    side('readingCheck.after', c.after),
    tp(c.direction === 'high' ? 'readingCheck.high' : 'readingCheck.low', n, { amount: amount(c.offset, unit) }),
  ];
}

/**
 * Befund je Ablesung aus GET …/reading-checks: `byReading` (ID → Befund „prüfen“),
 * `duplicate` (IDs, die doppelt eingetragen sind und gelöscht werden können).
 */
export function readingCheckIndex(data) {
  const byReading = new Map();
  for (const c of data?.checks || []) for (const id of c.reading_ids || []) byReading.set(id, { ...c, unit: c.unit || data?.unit });
  const duplicate = new Set((data?.duplicates || []).flatMap(d => d.delete_ids || []));
  return { byReading, duplicate };
}

/** Titel eines Befunds: ein Stand oder ein Block. */
export function readingCheckTitle(c) {
  const n = Number(c?.count) || 1;
  return n > 1 ? t('readingCheck.titleBlock', { date: fmt.date(c.date), until: fmt.date(c.until) })
    : t('readingCheck.title', { date: fmt.date(c?.date) });
}

/** Sprung in den Bearbeiten-Dialog des ersten Stands (Korrigieren). */
export function correctHref(utility, meterId, readingId) {
  return `#/utility/${encodeURIComponent(utility)}?meter=${encodeURIComponent(meterId)}&edit_reading=${encodeURIComponent(readingId)}`;
}

async function setFlag(utility, ids, flag, value) {
  for (const id of ids) await api.updateReading(utility, id, { [flag]: value });
}

/**
 * „Stand ist richtig“ bzw. „Nur für die Abrechnung“ an allen Ständen eines Befunds,
 * mit Rückgängig. `flag`: is_verified | is_billing_only.
 */
export async function markReadings({ utility, ids, flag, onChange = () => {} }) {
  const list = (ids || []).filter(Boolean);
  if (!list.length) return false;
  try {
    await setFlag(utility, list, flag, true);
  } catch (e) { toastErr(e.message); return false; }
  toastUndo(t(flag === 'is_billing_only' ? 'readingCheck.toast.billingOnly' : 'readingCheck.toast.verified'), async () => {
    try {
      await setFlag(utility, list, flag, false);
      toastOk(t('readingCheck.toast.undone'));
      await onChange();
    } catch (e) { toastErr(e.message); }
  });
  await onChange();
  return true;
}

/** Doppelte Ablesung löschen; Rückgängig legt sie mit denselben Angaben neu an. */
export async function deleteDuplicates({ utility, meterId, ids, onChange = () => {} }) {
  const list = (ids || []).filter(Boolean);
  if (!list.length) return false;
  let saved = [];
  try {
    const all = await api.readings(utility, meterId);
    saved = (all || []).filter(r => list.includes(r.id));
    for (const id of list) await api.deleteReading(utility, id);
  } catch (e) { toastErr(e.message); return false; }
  toastUndo(t('readingCheck.duplicate.deleted'), async () => {
    try {
      for (const r of saved) {
        await api.createReading(utility, { meter_id: r.meter_id, date: r.date, counter: r.counter, note: r.note || '',
          is_estimated: !!r.is_estimated, price_cents: r.price_cents ?? '', is_billing_only: !!r.is_billing_only });
      }
      toastOk(t('readingCheck.toast.undone'));
      await onChange();
    } catch (e) { toastErr(e.message); }
  });
  await onChange();
  return true;
}

/** Zeilen zum Hinweis auf die Analyse-Zäsur (`ref` eines `level_shift`-Ereignisses). */
export function levelShiftLines(s) {
  if (!s?.date) return [];
  const months = Number(s.months) || 12;
  const text = s.direction === 'higher'
    ? tp('levelShift.textHigher', months, { factor: fmt.num(Number(s.factor), 1) })
    : tp('levelShift.textLower', months, { pct: fmt.pct(Number(s.factor), 0) });
  return [text, t('levelShift.advice')];
}

export function levelShiftTitle(s) {
  return t(s?.direction === 'higher' ? 'levelShift.titleLess' : 'levelShift.titleMore', { date: fmt.date(s?.date) });
}

/**
 * Dialog „Analyse-Zäsur setzen“: Tag (vorbelegt mit dem Ende der auffälligen Zeit, also
 * dem ersten Stand auf heutigem Niveau) und Bezeichnung, mit Bestätigung.
 * @returns {Promise<{date:string,label:string}|null>}
 */
export function baselineDialog({ date, label }) {
  return new Promise(resolve => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    const ctrl = openModal({
      title: t('levelShift.dialog.title'),
      body: `<p class="small">${escapeHtml(t('levelShift.dialog.text'))}</p>
        <div class="field"><label for="ls-date">${escapeHtml(t('levelShift.dialog.date'))}</label>
          <input class="input" id="ls-date" type="date" name="date" value="${escapeHtml(date || '')}" required aria-describedby="ls-msg"></div>
        <div class="field"><label for="ls-label">${escapeHtml(t('levelShift.dialog.label'))}</label>
          <input class="input input--text" id="ls-label" type="text" name="label" maxlength="80" value="${escapeHtml(label || '')}"></div>
        <div class="field-error" id="ls-msg" data-role="msg" role="alert" hidden></div>`,
      footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${escapeHtml(t('common.cancel'))}</button>
        <button type="button" class="btn btn--primary" data-act="ok">${escapeHtml(t('levelShift.dialog.confirm'))}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(null); finish(null); });
        modalEl.querySelector('[data-act="ok"]').addEventListener('click', () => {
          const d = modalEl.querySelector('#ls-date').value;
          const msg = modalEl.querySelector('[data-role="msg"]');
          if (!/^\d{4}-\d{2}-\d{2}$/.test(d || '')) { msg.textContent = t('levelShift.dialog.invalid'); msg.hidden = false; return; }
          finish({ date: d, label: modalEl.querySelector('#ls-label').value.trim() });
          close(true);
        });
      },
    });
    ctrl.closedPromise?.then(() => finish(null));
  });
}

/**
 * „Zäsur hier setzen“: erst der Dialog (Tag vorbelegt), dann die Zäsur am Zähler (die
 * übrigen bleiben), mit Rückgängig.
 */
export async function setBaselineAt({ utility, meterId, date, onChange = () => {} }) {
  const choice = await baselineDialog({ date, label: t('levelShift.label') });
  if (!choice) return false;
  let before;
  try {
    const meter = (await api.meters(utility)).find(m => m.id === meterId);
    if (!meter) throw new Error(t('errors.meter.notFound'));
    before = Array.isArray(meter.baseline_events) ? meter.baseline_events : [];
    const next = [...before.filter(e => e.date !== choice.date), { date: choice.date, label: choice.label }];
    await api.updateMeter(utility, meterId, { baseline_events: next });
  } catch (e) { toastErr(e.message); return false; }
  toastUndo(t('levelShift.toast.set', { date: fmt.date(choice.date) }), async () => {
    try {
      await api.updateMeter(utility, meterId, { baseline_events: before });
      toastOk(t('readingCheck.toast.undone'));
      await onChange();
    } catch (e) { toastErr(e.message); }
  });
  await onChange();
  return true;
}

/** „Nicht jetzt“: den Hinweis abhaken (ohne Dialog), mit Rückgängig. */
export async function dismissLevelShift({ event, onChange = () => {} }) {
  let res;
  try {
    res = await api.agendaDone({ uid: event.uid, occurrence: event.occurrence });
  } catch (e) { toastErr(e.message); return false; }
  toastUndo(t('levelShift.toast.dismissed'), async () => {
    try {
      if (res?.activity_id) await api.undoActivity(res.activity_id);
      toastOk(t('readingCheck.toast.undone'));
      await onChange();
    } catch (e) { toastErr(e.message); }
  });
  await onChange();
  return true;
}
