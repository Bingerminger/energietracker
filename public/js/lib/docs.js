// =====================================================================
// v2.13.0 (Review I18N-25) — Links in die Dokumentation, in der passenden
// Sprache. Deutsch ist kanonisch und hat die englische Fassung als Spiegel;
// alle anderen Oberflächensprachen bekommen die englische.
// =====================================================================
import { getLocale } from './i18n.js';

export const REPO_URL = 'https://github.com/Bingerminger/energietracker';
export const ISSUES_URL = `${REPO_URL}/issues`;

// v2.14.0 — Doku nach Zielgruppen; die alten Pfade leiten weiter, damit
// ältere Installationen (sie verlinken auf main) nicht ins Leere zeigen
const PAGES = {
  start:         ['docs/einstieg/erste-schritte.md',     'docs/en/einstieg/erste-schritte.md'],
  faq:           ['docs/einstieg/faq.md',                'docs/en/einstieg/faq.md'],
  troubleshoot:  ['docs/betrieb/fehlersuche.md',         'docs/en/betrieb/fehlersuche.md'],
  compendium:    ['docs/README.md',                      'docs/en/README.md'],
  homeAssistant: ['docs/anleitungen/home-assistant.md',  'docs/en/anleitungen/home-assistant.md'],
  glossary:      ['docs/verstehen/09-glossar.md',        'docs/en/verstehen/09-glossar.md'],
};

/** Die Repo-Pfade aller verlinkten Seiten — ein Test prüft, dass es sie gibt. */
export const DOC_PATHS = Object.values(PAGES).flat();

/** Adresse einer Doku-Seite (`start`, `faq`, `troubleshoot`, `compendium`, `homeAssistant`, `glossary`). */
export function docUrl(page) {
  const [de, en] = PAGES[page] || PAGES.compendium;
  return `${REPO_URL}/blob/main/${getLocale() === 'de' ? de : en}`;
}

/** Die Doku gibt es nur auf Deutsch und Englisch. */
export const docsInOtherLanguage = () => !['de', 'en'].includes(getLocale());
