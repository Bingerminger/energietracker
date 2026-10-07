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
  calendar:      ['docs/anleitungen/kalender.md',        'docs/en/anleitungen/kalender.md'],          // v3.1.0
  otherSystems:  ['docs/anleitungen/andere-systeme.md',  'docs/en/anleitungen/andere-systeme.md'],    // v3.1.0
  ocr:           ['docs/anleitungen/texterkennung.md',   'docs/en/anleitungen/texterkennung.md'],     // v3.1.0
  tenant:        ['docs/anleitungen/mieter.md',          'docs/en/anleitungen/mieter.md'],            // v3.1.0 (H3)
  heat:          ['docs/verstehen/15-waerme.md',         'docs/en/verstehen/15-waerme.md'],           // v3.1.0 (H3)
  co2price:      ['docs/verstehen/16-co2-preis.md',      'docs/en/verstehen/16-co2-preis.md'],        // v3.1.0 (H4)
  co2split:      ['docs/anleitungen/co2-aufteilung.md',  'docs/en/anleitungen/co2-aufteilung.md'],    // v3.1.0 (H4)
  evcharging:    ['docs/anleitungen/ladestrom-nachweis.md', 'docs/en/anleitungen/ladestrom-nachweis.md'], // v3.1.0 (H6)
  portals:       ['docs/anleitungen/daten-aus-portalen.md', 'docs/en/anleitungen/daten-aus-portalen.md'], // v3.1.0 (H8)
};

/** Die Repo-Pfade aller verlinkten Seiten — ein Test prüft, dass es sie gibt. */
export const DOC_PATHS = Object.values(PAGES).flat();

/** Adresse einer Doku-Seite (`start`, `faq`, `troubleshoot`, `compendium`, `homeAssistant`, `glossary`, `calendar`, `otherSystems`, `ocr`, `tenant`, `heat`, `co2price`, `co2split`, `evcharging`, `portals`). */
export function docUrl(page) {
  const [de, en] = PAGES[page] || PAGES.compendium;
  return `${REPO_URL}/blob/main/${getLocale() === 'de' ? de : en}`;
}

/** Die Doku gibt es nur auf Deutsch und Englisch. */
export const docsInOtherLanguage = () => !['de', 'en'].includes(getLocale());
