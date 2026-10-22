// =====================================================================
// v3.5.0 (F1028) — Zuständig an Terminen: Chip, Filter und „Ich bin …“.
//
// Ein Termin (GET /api/reminders) und jedes Agenda-Ereignis tragen
// `assignee` (Anzeigename), `assignee_id` (Person aus der Anmeldung) und
// `assignee_former` (Person gelöscht, Name ersetzt). Der Filter „Alle ·
// Meine Aufgaben · Ohne Zuständigkeit · <Namen>“ gilt in Terminen und unter
// „Zu tun“; die Wahl merkt sich das Gerät. „Meine Aufgaben“ ist die
// angemeldete Person (ID oder gleicher Name, Groß-/Kleinschreibung egal),
// ohne Anmeldung die Auswahl „Ich bin …“ dieses Geräts. Benachrichtigt wird
// niemand.
//
// v3.7.0 — Zuständig auch an Zählern und Verträgen (`assignee_id`, nur
// Personen aus dem Verzeichnis). Fristen erben: eigene am Termin → Vertrag →
// Zähler. Agenda-Ereignisse tragen die geerbte Zuständigkeit samt
// `assignee_source` (contract | meter), Termine am Zähler `assignee_inherited`;
// der Chip sagt „über den Vertrag“ bzw. „über den Zähler“, Filter und
// „Meine Aufgaben“ zählen sie mit.
// =====================================================================

import { t, tp } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { sessionUser } from './levels.js';

const FILTER_KEY = 'et-assignee-filter';
const ME_KEY = 'et-assignee-me';
/** v3.7.0 — woher eine geerbte Zuständigkeit kommt (Katalogschlüssel wörtlich, nicht zusammengesetzt). */
const VIA_KEY = { contract: 'assignee.via.contract', meter: 'assignee.via.meter' };

/** Namen vergleichen: getrimmt, Groß-/Kleinschreibung egal. */
export const normName = (s) => String(s ?? '').trim().normalize('NFC').toLowerCase();

/**
 * Die eigene Zuständigkeit eines Termins (oder die Felder eines Zählers,
 * Vertrags, Ereignisses): {name, id, former, deleted} oder null (niemand).
 * v3.6.0 — `deleted`: Die Person ist gelöscht, ihr Name bleibt („Name
 * (gelöscht)“); sie zählt wie „ehemalige Person“ zu „Ohne Zuständigkeit“.
 */
export function ownAssigneeOf(x) {
  const name = typeof x?.assignee === 'string' && x.assignee.trim() !== '' ? x.assignee.trim() : null;
  if (name) return { name, id: typeof x.assignee_id === 'string' && x.assignee_id ? x.assignee_id : null, former: false, deleted: !!x.assignee_deleted };
  return x?.assignee_former ? { name: null, id: null, former: true, deleted: false } : null;
}

/**
 * Wer zuständig ist, wie Liste, Filter und Chip es zeigen: die eigene
 * Zuständigkeit, sonst (v3.7.0) die geerbte — `via` = 'contract' | 'meter'
 * bzw. null, wenn sie am Termin selbst steht.
 */
export function assigneeOf(x) {
  const own = ownAssigneeOf(x);
  if (own) return { ...own, via: VIA_KEY[x?.assignee_source] ? x.assignee_source : null };
  const inh = x?.assignee_inherited;
  const a = inh && typeof inh === 'object' ? ownAssigneeOf(inh) : null;
  return a ? { ...a, via: VIA_KEY[inh.assignee_source] ? inh.assignee_source : 'meter' } : null;
}

/** Niemand mehr zuständig: keine Angabe, „ehemalige Person“ oder gelöschte Person. */
const nobody = (a) => !a || a.former || a.deleted;

function read(key) {
  try { return localStorage.getItem(key) || ''; } catch { return ''; }
}
function write(key, value) {
  try {
    if (value) localStorage.setItem(key, value); else localStorage.removeItem(key);
  } catch { /* privates Fenster: gilt bis zum Neuladen nicht */ }
}

/** Gemerkter Filter: all | mine | none | name:<name klein>. */
export function storedFilter() {
  const v = read(FILTER_KEY);
  return v === 'mine' || v === 'none' || v.startsWith('name:') ? v : 'all';
}
export function setStoredFilter(v) { write(FILTER_KEY, v === 'all' ? '' : v); }

/** „Ich bin …“ ohne Anmeldung (Name, je Gerät). */
export function storedMe() { return read(ME_KEY); }
export function setStoredMe(name) { write(ME_KEY, String(name || '').trim()); }

/** Wer „ich“ bin: die angemeldete Person, sonst die Auswahl dieses Geräts; null = unbekannt. */
export function currentMe() {
  const u = sessionUser();
  if (u?.id) return { id: String(u.id), name: String(u.name || ''), loggedIn: true };
  const name = storedMe();
  return name ? { id: null, name, loggedIn: false } : null;
}

/** Gehört der Termin mir? Person per ID oder gleicher Name. */
export function isMine(x, me = currentMe()) {
  const a = assigneeOf(x);
  if (nobody(a) || !me) return false;
  if (me.id && a.id && a.id === me.id) return true;
  return !!me.name && normName(a.name) === normName(me.name);
}

/** Passt ein Termin oder Ereignis zum Filter? */
export function matchesAssignee(x, filter = 'all', me = currentMe()) {
  const a = assigneeOf(x);
  switch (true) {
    case filter === 'mine': return isMine(x, me);
    case filter === 'none': return nobody(a);   // eine ehemalige oder gelöschte Person ist niemand mehr
    case filter.startsWith('name:'): return !nobody(a) && normName(a.name) === filter.slice(5);
    default: return true;
  }
}

/** Namen für Filter und Vorschläge: je Name einmal (erste Schreibweise), sortiert; v3.6.0 — ohne gelöschte Personen. */
export function assigneeNames(items, extra = []) {
  const seen = new Map();
  for (const n of [...(items || []).map(x => { const a = assigneeOf(x); return nobody(a) ? null : a.name; }), ...extra]) {
    if (n && !seen.has(normName(n))) seen.set(normName(n), String(n).trim());
  }
  return [...seen.values()].sort((a, b) => a.localeCompare(b));
}

/** Gibt es überhaupt Zuständigkeiten (sonst bleibt der Filter weg)? */
export function anyAssigned(items) {
  return (items || []).some(x => assigneeOf(x) !== null);
}

/** Anzeigename: „ehemalige Person“, „Anna (gelöscht)“ oder der Name. */
export function displayName(a) {
  if (!a) return '';
  return a.former ? t('activity.by.former') : a.deleted ? t('people.deletedName', { name: a.name }) : a.name;
}

/**
 * Namens-Chip („👤 Anna“, „ehemalige Person“, v3.6.0 „Anna (gelöscht)“); leer
 * ohne Zuständigkeit. v3.7.0 — geerbt mit dem Zusatz „über den Vertrag“ bzw.
 * „über den Zähler“.
 */
export function chipHtml(x) {
  const a = assigneeOf(x);
  if (!a) return '';
  const via = a.via ? t(VIA_KEY[a.via]) : '';
  return ` <span class="chip chip--assignee${a.former || a.deleted ? ' chip--former' : ''}${via ? ' chip--via' : ''}" title="${esc(t('assignee.label') + (via ? ` (${via})` : ''))}">`
    + `<span aria-hidden="true">👤</span> <span class="sr-only">${esc(t('assignee.label'))}: </span>${esc(displayName(a))}`
    + `${via ? ` <span class="chip__via">${esc(via)}</span>` : ''}</span>`;
}

/** v3.7.0 — Auswahlwert für „ehemalige Person“ (bleibt beim Speichern stehen). */
const FORMER = '__former';

/**
 * v3.7.0 — Auswahl „Zuständig“ an Zähler und Vertrag: „– niemand –“ und die
 * Personen aus dem Verzeichnis (GET /api/people). Eine gelöschte Person oder
 * „ehemalige Person“, die der Datensatz noch trägt, steht als eigene Option
 * da — unverändert gespeichert bleibt sie stehen. `hintKey`: Satz darunter.
 */
export function personSelectHtml({ id, record = null, people = [], hintKey = '' } = {}) {
  const cur = ownAssigneeOf(record);
  const value = cur?.former ? FORMER : (cur?.id || '');
  const opts = [`<option value="">${esc(t('assignee.none'))}</option>`];
  for (const p of people || []) {
    if (!p?.id) continue;
    opts.push(`<option value="${esc(p.id)}"${p.id === value ? ' selected' : ''}>${esc(p.name)}</option>`);
  }
  // gelöscht oder unbekannt: als eigene Option, damit das Formular sie nicht still verliert
  if (cur && (cur.former || !(people || []).some(p => p.id === cur.id))) {
    opts.push(`<option value="${esc(value)}" selected>${esc(displayName(cur))}</option>`);
  }
  const hint = [hintKey ? t(hintKey) : '', (people || []).length ? '' : t('assignee.noPeople')].filter(Boolean).join(' ');
  return `<div class="field field--m" data-role="assignee-field">
      <label for="${esc(id)}">${esc(t('assignee.label'))}</label>
      <select class="select" id="${esc(id)}" name="assignee_id" aria-describedby="${esc(id)}-hint">${opts.join('')}</select>
      <small class="settings-field__hint" id="${esc(id)}-hint">${esc(hint)}</small>
    </div>`;
}

/**
 * v3.7.0 — was an den Server geht: nichts, solange die Auswahl unverändert
 * ist (eine gelöschte Person bleibt so stehen), sonst `assignee_id` (null =
 * niemand).
 */
export function personPayload(selectEl, record = null) {
  if (!selectEl) return {};
  const cur = ownAssigneeOf(record);
  const before = cur?.former ? FORMER : (cur?.id || '');
  const v = String(selectEl.value || '');
  if (v === before) return {};
  return { assignee_id: v === '' || v === FORMER ? null : v };
}

/**
 * Filterleiste. `names` = Namen der Zuständigen; „Ich bin …“ erscheint ohne
 * Anmeldung, sobald „Meine Aufgaben“ gewählt ist.
 */
export function filterHtml({ id, value = storedFilter(), names = [], me = currentMe() } = {}) {
  const opt = (v, label) => `<option value="${esc(v)}"${value === v ? ' selected' : ''}>${esc(label)}</option>`;
  const nameOpts = names.map(n => opt('name:' + normName(n), n)).join('');
  const askMe = value === 'mine' && !me?.loggedIn;
  return `<div class="assignee-filter" role="group" aria-label="${esc(t('assignee.filter.label'))}">
    <label>${esc(t('assignee.label'))}
      <select class="select" id="${esc(id)}" data-assignee-filter>
        ${opt('all', t('assignee.filter.all'))}${opt('mine', t('assignee.filter.mine'))}${opt('none', t('assignee.filter.none'))}
        ${nameOpts ? `<optgroup label="${esc(t('assignee.filter.names'))}">${nameOpts}</optgroup>` : ''}
      </select></label>
    ${askMe ? `<label>${esc(t('assignee.iAm'))}
      <select class="select" id="${esc(id)}-me" data-assignee-me>
        <option value="">${esc(t('assignee.iAmPick'))}</option>
        ${names.map(n => `<option value="${esc(n)}"${normName(n) === normName(me?.name) ? ' selected' : ''}>${esc(n)}</option>`).join('')}
      </select></label>` : ''}
  </div>`;
}

/** Filter und „Ich bin …“ binden; `onChange` zeichnet neu. */
export function bindFilter(root, onChange) {
  root?.querySelector('[data-assignee-filter]')?.addEventListener('change', (e) => {
    setStoredFilter(e.target.value);
    onChange();
  });
  root?.querySelector('[data-assignee-me]')?.addEventListener('change', (e) => {
    setStoredMe(e.target.value);
    onChange();
  });
}

/** Hinweis unter einer gefilterten Liste: was fehlt und warum. */
export function filterNoteHtml(hidden, filter = storedFilter(), me = currentMe()) {
  if (filter === 'mine' && !me) return `<p class="muted small">${esc(t('assignee.pickMe'))}</p>`;
  return hidden > 0 ? `<p class="muted small" data-role="assignee-hidden">${esc(tp('assignee.hidden', hidden))}</p>` : '';
}
