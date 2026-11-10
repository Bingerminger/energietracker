// =====================================================================
// v3.12.0 (M3) — Sprung zu einem Feld (`?field=wohnflaeche_m2`), aus der Suche, aus Einladungen und alten
// Lesezeichen. Eine Quelle für die Seiten des Einstellungs-Gerüsts, Wetter und Verlauf.
//
// Ein Feld über der eigenen Stufe öffnet sich mit: Die Karte verliert ihre Marke (das CSS blendet über
// `data-min-level` aus), die Stufe bleibt. Nach dem nächsten Zeichnen ist die Karte wieder verborgen.
//
// v3.12.0 — Ein Feld in einem gerade verborgenen Bereich (Lokal | Cloud, Ziel der Benachrichtigung): Der
// Bereich nennt mit `data-shown-by="<name>=<wert> …"` die Wahl, die ihn zeigt. An der Karte steht dann
// „Das Feld steht unter **Cloud**.“ mit „Zeigen“. Der Knopf wählt die Ansicht wie ein Klick auf die Wahl
// und speichert nichts; gespeichert wird erst mit „Speichern“ der Karte.
// =====================================================================

import { openDisclosureOf } from '../components/disclosure.js';
import { t } from './i18n.js';
import { rich } from './rich.js';
import { escapeHtml } from './format.js';

const FOCUSABLE = 'input, select, textarea, button, a[href], [tabindex]';
const NAME = /^[a-z0-9_-]+$/;

/** Die Karte (und was sie umgibt) trotz Stufe zeigen. */
export function revealAboveLevel(el) {
  for (let n = el?.closest?.('[data-min-level]'); n; n = n.parentElement?.closest('[data-min-level]')) {
    n.removeAttribute('data-min-level');
  }
}

/**
 * Die Wahl, die einen verborgenen Bereich zeigt (`data-shown-by`, mehrere durch Leerzeichen): die erste,
 * die sich wählen lässt, sonst die erste vorhandene (ein Mitglied sieht gesperrte Wahlen).
 * @returns {HTMLInputElement|null}
 */
function choiceShowing(pane, scope) {
  const radios = (pane.getAttribute('data-shown-by') || '').split(/\s+/).map(s => s.split('='))
    .filter(([n, v]) => NAME.test(n || '') && NAME.test(v || ''))
    .map(([n, v]) => scope.querySelector(`input[type="radio"][name="${n}"][value="${v}"]`))
    .filter(Boolean);
  return radios.find(r => !r.disabled) || radios[0] || null;
}

/**
 * Ziel ist, was man sieht: bei Tabellen mit JSON die Tabelle; in einem gerade verborgenen Bereich ohne
 * Wahl die Karte, sonst läge der Fokus im Nichts. Setzt die Marke und gibt das Ziel zurück.
 */
function aimOf(el) {
  const paneHidden = el.closest('[hidden]');
  const aim = paneHidden ? (el.closest('.card') || paneHidden.parentElement)
    : el.type === 'hidden' || el.disabled ? (el.closest('.settings-field') || el.parentElement) : el;
  (paneHidden ? aim : el.closest('.settings-field') || aim).setAttribute('data-target', '');
  return aim;
}

/** Fokus nach dem Zeichnen (der Router scrollt danach an den Anfang der Ansicht, deshalb der Zeitgeber). */
function focusAfterRender(aim) {
  if (!aim.matches(FOCUSABLE)) aim.setAttribute('tabindex', '-1');
  const inView = () => { const r = aim.getBoundingClientRect?.(); return !r || (r.bottom > 0 && r.top < (window.innerHeight || 0)); };
  setTimeout(() => {
    if (!aim.isConnected) return;
    aim.scrollIntoView?.({ block: 'center' });
    aim.focus?.({ preventScroll: true });
  }, 0);
  // Was die Seite danach noch nachlädt, darf das Feld nicht wieder aus dem Blick schieben
  setTimeout(() => {
    if (aim.isConnected && document.activeElement === aim && !inView()) aim.scrollIntoView?.({ block: 'center' });
  }, 300);
}

/** Hinweis an der Karte: „Das Feld steht unter **Cloud**.“ und „Zeigen“; der Fokus steht auf dem Knopf. */
function hiddenNote(el, card, choice) {
  card.querySelector('[data-role="jump-hidden"]')?.remove();
  const place = (choice.closest('label')?.textContent || choice.value).replace(/\s+/g, ' ').trim();
  const note = document.createElement('div');
  note.className = 'infobox infobox--info infobox--zeile';
  note.setAttribute('data-role', 'jump-hidden');
  note.setAttribute('role', 'note');
  note.innerHTML = `<p class="infobox__text" id="jump-hidden-text">${rich('settings.jump.hiddenUnder', { place })}</p>
    <span class="infobox__aktion"><button type="button" class="btn btn--sm" data-act="show" aria-describedby="jump-hidden-text">${escapeHtml(t('settings.jump.show'))}</button></span>`;
  (choice.closest('fieldset') || choice.closest('.settings-field') || choice.parentElement).insertAdjacentElement('afterend', note);
  card.setAttribute('data-target', '');
  const btn = note.querySelector('[data-act="show"]');
  btn.addEventListener('click', () => {
    // nur die Ansicht: dieselbe Wahl wie ein Klick, die Karte blendet um; gespeichert wird nichts
    choice.checked = true;
    choice.dispatchEvent(new Event('change', { bubbles: true }));
    note.remove();
    card.removeAttribute('data-target');
    focusAfterRender(aimOf(el));
  });
  focusAfterRender(btn);
}

/**
 * Nach dem Rendern aufrufen.
 * @param {ParentNode} container
 * @param {URLSearchParams} [query]
 * @returns {Element|null} das Feld, wenn es auf dieser Seite steht
 */
export function jumpToField(container, query) {
  const wanted = query?.get?.('field');
  if (!wanted || !/^[a-z0-9_]+$/.test(wanted)) return null;
  const el = container.querySelector(`[data-key="${wanted}"], [data-field="${wanted}"]`);
  if (!el) return null;
  revealAboveLevel(el);
  openDisclosureOf(el);
  const pane = el.closest('[data-shown-by][hidden]');
  const card = pane ? (el.closest('.card') || pane.parentElement) : null;
  const choice = pane ? choiceShowing(pane, card) : null;
  if (choice) hiddenNote(el, card, choice);
  else focusAfterRender(aimOf(el));
  return el;
}
