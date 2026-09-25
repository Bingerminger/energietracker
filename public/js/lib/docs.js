// =====================================================================
// v2.13.0 (Review I18N-25) — Links in die Dokumentation, in der passenden
// Sprache. Deutsch ist kanonisch und hat die englische Fassung als Spiegel;
// alle anderen Oberflächensprachen bekommen die englische.
// =====================================================================
import { getLocale } from './i18n.js';

export const REPO_URL = 'https://github.com/Bingerminger/energietracker';
export const ISSUES_URL = `${REPO_URL}/issues`;

const PAGES = {
  start:         ['docs/ERSTE-SCHRITTE.md',          'docs/en/getting-started.md'],
  compendium:    ['docs/README.md',                  'docs/en/README.md'],
  homeAssistant: ['docs/HOME-ASSISTANT.md',          'docs/en/HOME-ASSISTANT.md'],
  glossary:      ['docs/functional/09-glossar.md',   'docs/en/functional/09-glossar.md'],
};

/** Adresse einer Doku-Seite (`start`, `compendium`, `homeAssistant`, `glossary`). */
export function docUrl(page) {
  const [de, en] = PAGES[page] || PAGES.compendium;
  return `${REPO_URL}/blob/main/${getLocale() === 'de' ? de : en}`;
}

/** Die Doku gibt es nur auf Deutsch und Englisch. */
export const docsInOtherLanguage = () => !['de', 'en'].includes(getLocale());
