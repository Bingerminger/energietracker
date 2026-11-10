// =====================================================================
// v3.11.0 (F1036, Paket basis) — Briefe aus Befunden „Deine Rechte als Kunde“.
//
// Der eine Einstieg für alle Ansichten (Empfehlungen, „Rechnung prüfen“,
// Verträge …): letterButton(finding) liefert den Knopf „Brief entwerfen“, wenn
// der Befund einen Brief anbietet (`finding.letter`, Entscheidung 2a: nur auf
// Knopfdruck, nie automatisch), bindLetterButtons() verbindet ihn mit
// openLetter(). Den Dialog (Vorschau, PDF, Text kopieren, „verschickt“) baut
// das Paket briefe hier ein; der Server bekommt nur `finding_id` und `kind`
// (POST /api/letters/render) und liest den Befund selbst.
//
// Ein Befund (GET /api/rights) oder eine Empfehlung der Kategorie `recht`
// (evidence.finding_id, evidence.letter) — findingOfRec() macht aus der
// Empfehlung die Form, die letterButton() braucht.
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml, fmt } from './format.js';
import { icon } from './icons.js';

/** Vorlagen (wie RightsService::LETTERS). */
export const LETTER_KINDS = ['special_termination', 'advance_adjustment', 'estimate_query', 'complaint', 'objection'];

/** v3.12.0 — Vorlagen an der Vertragskarte, ohne Befund (wie LetterService::CONTRACT_KINDS). */
export const CONTRACT_LETTER_KINDS = ['advance_adjustment', 'termination'];

/** v3.12.0 (m4b) — Verbrauchsarten mit Briefen am Vertrag (wie LetterService::CONTRACT_UTILITIES): dort die Angaben für Briefe. */
export const LETTER_UTILITIES = ['strom', 'gas', 'fernwaerme', 'wasser'];

/** v3.12.0 — Sprungmarke auf einen Hinweis (wie RecommendationService::jumpHref). */
export const recHref = (id) => `#/todo?art=rec&rec=${encodeURIComponent(id)}`;

/** Bietet der Befund einen Brief an? */
export function hasLetter(finding) {
  return !!finding && typeof finding.id === 'string' && finding.id !== '' && LETTER_KINDS.includes(finding.letter);
}

/** Empfehlung der Kategorie `recht` → {id, letter, title} wie ein Befund (sonst null). */
export function findingOfRec(rec) {
  if (rec?.category !== 'recht' || !rec.evidence) return null;
  return { id: rec.evidence.finding_id || rec.id, letter: rec.evidence.letter ?? null, title: rec.title || '' };
}

/** Knopf „Brief entwerfen“ oder '' (kein Brief, unbekannte Vorlage). */
export function letterButton(finding) {
  if (!hasLetter(finding)) return '';
  const title = finding.title ? t('letters.buttonNamed', { title: finding.title }) : t('letters.button');
  return `<button type="button" class="btn btn--ghost btn--sm" data-role="letter-button" data-letter="${escapeHtml(finding.letter)}"`
    + ` data-finding="${escapeHtml(finding.id)}" title="${escapeHtml(t(`letters.kind.${finding.letter}`))}" aria-label="${escapeHtml(title)}">`
    + `${icon('brief')} ${escapeHtml(t('letters.button'))}</button>`;
}

/**
 * v3.12.0 — Knopf „Brief schreiben“ an der Vertragskarte oder '' (`contract.letters` sagt der Server:
 * LetterService::contractKinds). Sonderkündigung, Widerspruch und Beanstandung führt erst das Menü auf,
 * und nur, wenn ein Befund sie anbietet.
 */
export function contractLetterButton(contract) {
  if (!(contract?.letters || []).some(k => CONTRACT_LETTER_KINDS.includes(k))) return '';
  const label = t('letters.write');
  const name = [contract.provider, contract.tariff_name].filter(Boolean).join(' · ');
  return `<button type="button" class="btn btn--sm btn--ghost" data-role="contract-letter" data-contract="${escapeHtml(contract.id)}"`
    + ` aria-label="${escapeHtml(name ? t('letters.writeNamed', { name }) : label)}">${icon('brief')} ${escapeHtml(label)}</button>`;
}

/**
 * v3.12.0 — Knöpfe „Brief schreiben“ unter `root` verbinden: öffnen die Auswahl (lazy geladen wie der Dialog).
 * @param {ParentNode} root
 * @param {string} utility
 * @param {Array<object>} contracts
 */
export function bindContractLetters(root, utility, contracts) {
  root?.querySelectorAll('[data-role="contract-letter"]').forEach(b => {
    b.addEventListener('click', () => {
      const contract = contracts.find(c => c.id === b.dataset.contract);
      if (!contract) return;
      import('../components/letter-dialog.js')
        .then(m => m.openContractLetterMenu({ utility, contract }))
        .catch(() => { /* Dialog nicht ladbar (offline, alte Version im Cache): der Knopf bleibt ohne Wirkung */ });
    });
  });
}

/**
 * Knöpfe unter `root` mit openLetter() verbinden (einmal je Knopf).
 * @param {ParentNode} root
 * @param {(id: string) => object|null} [findById] den ganzen Befund zur ID (sonst nur {id, letter})
 */
export function bindLetterButtons(root, findById = null) {
  root?.querySelectorAll('[data-role="letter-button"]').forEach(b => {
    if (b.dataset.bound === '1') return;
    b.dataset.bound = '1';
    b.addEventListener('click', () => {
      const f = findById?.(b.dataset.finding) || { id: b.dataset.finding, letter: b.dataset.letter };
      openLetter(f);
    });
  });
}

/**
 * v3.11.0 (F1035, Grundsatz 3) — Fußzeile jedes Befunds: „Hinweis, keine Rechtsberatung.
 * Grundlage: <Normen als Link zur Quelle> (Stand …).“ und wo es Hilfe gibt. Für einen Befund
 * (GET /api/rights) wie für `evidence` einer Empfehlung der Kategorie `recht` (beide tragen
 * `norms` und `help`). Stand ist der älteste Abruf der genannten Quellen.
 * @param {{norms?: Array<{norm: string, url: string, checked_on: string}>, help?: string}} f
 */
export function rightsFooterHtml(f) {
  const norms = Array.isArray(f?.norms) ? f.norms : [];
  if (!norms.length) return '';
  const links = norms.map(n => /^https:\/\//.test(n.url || '')
    ? `<a href="${escapeHtml(n.url)}" target="_blank" rel="noopener noreferrer" data-role="rights-norm">${escapeHtml(n.norm)}</a>`
    : escapeHtml(n.norm)).join(', ');
  const first = norms.map(n => n.checked_on).filter(Boolean).sort()[0];
  return `<p class="rights-footer muted small" data-role="rights-footer">${t('rights.footer', { norms: links, date: escapeHtml(first ? fmt.date(first) : '') })}`
    + `${f.help ? ` ${escapeHtml(f.help)}` : ''}</p>`;
}

/**
 * Brief zu einem Befund öffnen: meldet sich als Ereignis `et:letter`
 * ({detail: {id, letter}}) und öffnet den Dialog (v3.11.0, Paket briefe:
 * components/letter-dialog.js, erst beim ersten Brief geladen).
 * @returns {boolean} geöffnet?
 */
export function openLetter(finding) {
  if (!hasLetter(finding)) return false;
  window.dispatchEvent(new CustomEvent('et:letter', { detail: { id: finding.id, letter: finding.letter } }));
  if (typeof document !== 'undefined' && document.getElementById('modal-root')) {
    import('../components/letter-dialog.js')
      .then(m => m.openLetterDialog({ id: finding.id, letter: finding.letter, title: finding.title || '' }))
      .catch(() => { /* Dialog nicht ladbar (offline, alte Version im Cache): der Knopf bleibt ohne Wirkung */ });
  }
  return true;
}
