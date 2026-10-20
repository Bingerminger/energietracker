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
// =====================================================================

import { t, tp } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { sessionUser } from './levels.js';

const FILTER_KEY = 'et-assignee-filter';
const ME_KEY = 'et-assignee-me';

/** Namen vergleichen: getrimmt, Groß-/Kleinschreibung egal. */
export const normName = (s) => String(s ?? '').trim().normalize('NFC').toLowerCase();

/**
 * Zuständigkeit eines Termins oder Ereignisses: {name, id, former, deleted} oder
 * null (niemand). v3.6.0 — `deleted`: Die Person ist gelöscht, ihr Name bleibt
 * („Name (gelöscht)“); sie zählt wie „ehemalige Person“ zu „Ohne Zuständigkeit“.
 */
export function assigneeOf(x) {
  const name = typeof x?.assignee === 'string' && x.assignee.trim() !== '' ? x.assignee.trim() : null;
  if (name) return { name, id: typeof x.assignee_id === 'string' && x.assignee_id ? x.assignee_id : null, former: false, deleted: !!x.assignee_deleted };
  return x?.assignee_former ? { name: null, id: null, former: true, deleted: false } : null;
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

/** Namens-Chip („👤 Anna“, „ehemalige Person“, v3.6.0 „Anna (gelöscht)“); leer ohne Zuständigkeit. */
export function chipHtml(x) {
  const a = assigneeOf(x);
  if (!a) return '';
  const name = a.former ? t('activity.by.former') : a.deleted ? t('people.deletedName', { name: a.name }) : a.name;
  return ` <span class="chip chip--assignee${a.former || a.deleted ? ' chip--former' : ''}" title="${esc(t('assignee.label'))}">`
    + `<span aria-hidden="true">👤</span> <span class="sr-only">${esc(t('assignee.label'))}: </span>${esc(name)}</span>`;
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
