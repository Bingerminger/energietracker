// =====================================================================
// v2.13.0 (Review I18N-25) — Links in die Dokumentation, in der passenden
// Sprache. Deutsch ist kanonisch und hat die englische Fassung als Spiegel;
// alle anderen Oberflächensprachen bekommen die englische.
//
// v3.4.0 (N1019) — Hilfe auf dem Server: Die Doku kommt vom eigenen Server,
// in der Fassung dieses Release (GET /api/docs), und öffnet in der App
// (`#/help/doc/<pfad>`, views/help-doc.js). Bis v3.3 führte jeder Link nach
// GitHub, und zwar auf `main` — eine ältere Installation las dort die Doku
// einer neueren Version, ohne Internet gar keine. Nach GitHub führen nur noch
// die Fehlermeldung (Issues), die Projektseite und der Quellcode der Version.
// =====================================================================
import { getLocale } from './i18n.js';

export const REPO_URL = 'https://github.com/Bingerminger/energietracker';
export const ISSUES_URL = `${REPO_URL}/issues`;

/** v3.4.0 — Quellcode genau dieser Version (Tag), optional eine Datei oder ein Ordner darin. */
export const sourceUrl = (version, path = '') => `${REPO_URL}/tree/v${version}${path ? '/' + encodeURI(path) : ''}`;

/** Schlüsselpräfix der Dateien im Wurzelverzeichnis (wie DocsService::ROOT_PREFIX). */
const ROOT = 'root/';

// v2.14.0 — Doku nach Zielgruppen; die alten Pfade leiten weiter.
// v3.4.0 — nur Seiten, auf die die App verlinkt (DocsIntegrityTest prüft jeden Pfad)
const PAGES = {
  compendium:    ['docs/README.md',                      'docs/en/README.md'],
  start:         ['docs/einstieg/erste-schritte.md',     'docs/en/einstieg/erste-schritte.md'],
  faq:           ['docs/einstieg/faq.md',                'docs/en/einstieg/faq.md'],
  troubleshoot:  ['docs/betrieb/fehlersuche.md',         'docs/en/betrieb/fehlersuche.md'],
  homeAssistant: ['docs/anleitungen/home-assistant.md',  'docs/en/anleitungen/home-assistant.md'],
  glossary:      ['docs/verstehen/09-glossar.md',        'docs/en/verstehen/09-glossar.md'],
  setup:         ['docs/einstieg/einrichtung.md',        'docs/en/einstieg/einrichtung.md'],          // v3.2.0 (F1018, F1019)
  users:         ['docs/anleitungen/benutzer.md',        'docs/en/anleitungen/benutzer.md'],          // v3.2.0 (F1023)
  evcc:          ['docs/anleitungen/evcc.md',            'docs/en/anleitungen/evcc.md'],              // v3.2.0 (F1022)
  useCases:      ['docs/anleitungen/anwendungsfaelle.md', 'docs/en/anleitungen/anwendungsfaelle.md'], // v3.2.0
  flexibility:   ['docs/anleitungen/flexibilitaet.md',   'docs/en/anleitungen/flexibilitaet.md'],     // v3.3.0 (F1021)
  report:        ['docs/anleitungen/jahresbericht-lesen.md', 'docs/en/anleitungen/jahresbericht-lesen.md'], // v3.4.0 (F1025)
  done:          ['docs/anleitungen/hinweise-erledigen.md', 'docs/en/anleitungen/hinweise-erledigen.md'], // v3.4.0 (F1024)
  whatIf:        ['docs/anleitungen/was-waere-wenn.md',  'docs/en/anleitungen/was-waere-wenn.md'],    // v3.6.0 (F1031)
  inbox:         ['docs/anleitungen/posteingang.md',     'docs/en/anleitungen/posteingang.md'],       // v3.7.0
  inboxDraft:    ['docs/anleitungen/haushalt-aus-dokumenten.md', 'docs/en/anleitungen/haushalt-aus-dokumenten.md'], // v3.7.0
  levels:        ['docs/referenz/stufen.md',             'docs/en/referenz/stufen.md'],               // v3.7.0 (Paket reifegrad)
};

/** Die Repo-Pfade aller verlinkten Seiten — ein Test prüft, dass es sie gibt. */
export const DOC_PATHS = Object.values(PAGES).flat();

/**
 * v3.4.0 — Anwendungsfälle der Hilfe: Kurztext in jeder Sprache im Katalog
 * (`help.cases.<id>.title`/`.text`), der ganze Abschnitt im Handbuch hinter
 * einem sprachneutralen Anker `<a id="case-…">` — in der deutschen und der
 * englischen Seite derselbe. Eine Quelle je Inhalt; DocsIntegrityTest hält
 * Katalog und Doku deckungsgleich (jeder Fall A–G hat einen Kurztext, jeder
 * Kurztext seinen Anker).
 */
export const CASES = [
  { id: 'sharedMeters',  page: 'useCases', anchor: 'case-shared-meters' },   // A
  { id: 'homeAssistant', page: 'useCases', anchor: 'case-home-assistant' },  // B
  { id: 'pvHeatPump',    page: 'useCases', anchor: 'case-pv-heat-pump' },    // C
  { id: 'landlord',      page: 'useCases', anchor: 'case-landlord' },        // D
  { id: 'wallbox',       page: 'useCases', anchor: 'case-wallbox' },         // E
  { id: 'heat',          page: 'useCases', anchor: 'case-heat' },            // F
  { id: 'control',       page: 'useCases', anchor: 'case-control' },         // G
  { id: 'levels',        page: 'setup',    anchor: 'case-levels' },          // Nutzungsstufen
];

/** Schlüssel einer Seite für /api/docs: `einstieg/faq.md`, außer Deutsch `en/einstieg/faq.md`. */
export function docKey(page) {
  const [de, en] = PAGES[page] || PAGES.compendium;
  return (getLocale() === 'de' ? de : en).slice('docs/'.length);
}

/** In-App-Adresse eines Schlüssels, optional mit Sprungmarke (`?h=`). */
export function docRoute(key, anchor = '') {
  const path = String(key).split('/').map(encodeURIComponent).join('/');
  return `#/help/doc/${path}${anchor ? `?h=${encodeURIComponent(anchor)}` : ''}`;
}

/** Adresse einer Doku-Seite in der App (`start`, `faq`, `troubleshoot`, `compendium`, `homeAssistant`, `glossary`, `setup`, `users`, `evcc`, `useCases`, `flexibility`). */
export function docUrl(page, anchor = '') {
  return docRoute(docKey(page), anchor);
}

/** Die Doku gibt es nur auf Deutsch und Englisch. */
export const docsInOtherLanguage = () => !['de', 'en'].includes(getLocale());

function safeDecode(s) {
  try { return decodeURIComponent(s); } catch { return s; }
}

/** `docs/a/../b.md` → `docs/b.md`; null, wenn der Pfad das Repository verlässt. */
function normalize(path) {
  const out = [];
  for (const seg of path.split('/')) {
    if (seg === '' || seg === '.') continue;
    if (seg === '..') {
      if (!out.length) return null;
      out.pop();
      continue;
    }
    out.push(seg);
  }
  return out.length ? out.join('/') : null;
}

/**
 * v3.4.0 — Links und Bilder einer Doku-Seite für lib/markdown.js auflösen.
 *
 *   • `#anker` und relative Links auf Seiten unter docs/ → In-App-Route;
 *   • Dateien im Wurzelverzeichnis, die der Server ausliefert (`rootFiles`
 *     aus der Antwort von /api/docs) → In-App-Route `root/<name>`;
 *   • alles andere im Repository (roadmap.md, deploy/, demo-data/) → GitHub,
 *     im Tag dieser Version;
 *   • http(s) und mailto bleiben; jedes andere Schema wird zu Text;
 *   • Bilder nur unter docs/ (über `assetUrl`), fremde fallen weg.
 *
 * @param {string} key  Schlüssel der Seite (`anleitungen/kalender.md`, `root/CHANGELOG.md`)
 * @param {{rootFiles?: string[], version?: string, assetUrl: (key: string) => string}} ctx
 */
export function docLinks(key, { rootFiles = [], version = '', assetUrl } = {}) {
  const repoPath = key.startsWith(ROOT) ? key.slice(ROOT.length) : `docs/${key}`;
  const dir = repoPath.includes('/') ? repoPath.slice(0, repoPath.lastIndexOf('/') + 1) : '';
  const scheme = /^[a-z][a-z0-9+.-]*:/i;
  return {
    link(href) {
      if (/^(?:https?:|mailto:)/i.test(href)) return { href };
      if (scheme.test(href) || href.startsWith('//') || href.includes('\\')) return null;   // javascript:, data: … → nur Text
      const hash = href.indexOf('#');
      const file = hash < 0 ? href : href.slice(0, hash);
      const anchor = hash < 0 ? '' : safeDecode(href.slice(hash + 1));
      if (!file) return { href: docRoute(key, anchor) };
      const path = normalize(dir + safeDecode(file));
      if (!path) return null;
      if (path.startsWith('docs/') && path.endsWith('.md')) return { href: docRoute(path.slice('docs/'.length), anchor) };
      if (rootFiles.includes(path)) return { href: docRoute(ROOT + path, anchor) };
      return version ? { href: sourceUrl(version, path) + (anchor ? '#' + encodeURIComponent(anchor) : '') } : null;
    },
    image(src) {
      if (scheme.test(src) || src.startsWith('//') || src.includes('\\')) return null;   // nur Bilder des eigenen Servers
      const path = normalize(dir + safeDecode(src));
      return path && path.startsWith('docs/') && typeof assetUrl === 'function' ? assetUrl(path.slice('docs/'.length)) : null;
    },
  };
}
