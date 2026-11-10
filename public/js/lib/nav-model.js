// =====================================================================
// Energietracker v2.11.0 — Navigationsmodell (Review UI-12, UI-05)
//
// Eine Quelle für Seitenleiste (Mac), Tab-Leiste und „Mehr" (iPhone) und
// die Bereichs-Tabs über den Ansichten. Bis v2.10 stand die Navigation als
// 17 flache Einträge in sidebar.js, geordnet nach Datentöpfen; Verträge,
// Rechnungsprüfung und Jahresbericht hatten keinen eigenen Platz, und
// Menüname und Seitentitel wichen voneinander ab („Dashboard" / „Übersicht").
//
// v3.12.0 — zwei Gruppen:
//   Alltag: Übersicht · Zu tun · Zählerstände · Posteingang · Verbrauch (je Art) ·
//           Kosten & Verträge · Auswertungen
//   Einrichtung: Haushalt · Verbindungen · Einstellungen
// Das Menü ist in jeder Stufe gleich, Stufen wirken auf Reiter und Karten.
// Ein Bereich mit mehreren Seiten zeigt sie als Tabs über der Ansicht; in
// der Seitenleiste steht er als ein Eintrag. Alte Adressen bleiben gültig.
// =====================================================================

import { t } from './i18n.js';
// v3.2.0 (F1019) — Seiten über der Nutzungsstufe stehen nicht in Navigation und Tabs
import { viewAllowed } from './levels.js';

// ── v3.5.0 (N1023) — eine Schwelle für Seitenleiste und Tab-Leiste ─────
// Bis v3.4 kam die Seitenleiste ab 801 px — das iPad hochkant (744–834 px)
// zeigte sie und ließ dem Inhalt zwei Drittel der Breite. Jetzt erst ab
// 900 px Fensterbreite; darunter die Tab-Leiste mit „Mehr“ wie am iPhone.
// Querformat (ab 1024 px) und der Mac behalten die Seitenleiste. CSS
// (app.css, components.css, readings-entry.css) schreibt dieselben Abfragen;
// tests/nav-threshold.test.mjs vergleicht jede mit diesen Konstanten.
// Der Bruchteil (899.98) schließt die Lücke zwischen 899 und 900 px bei
// gebrochenen Fensterbreiten (Zoom, Split View).
export const NAV_SIDEBAR_MIN_PX = 900;
export const NAV_SIDEBAR_QUERY = `(min-width: ${NAV_SIDEBAR_MIN_PX}px)`;
export const NAV_TABBAR_QUERY = `(max-width: ${NAV_SIDEBAR_MIN_PX - 0.02}px)`;

/** Welche Navigation bei dieser Fensterbreite gilt (CSS-Pixel). */
export function navMode(width) {
  return Number(width) >= NAV_SIDEBAR_MIN_PX ? 'sidebar' : 'tabbar';
}

/**
 * Die Seiten eines Bereichs, in Tab-Reihenfolge. `view` ist der Schlüssel
 * aus der Routentabelle (router.js).
 *
 * @param {string} section
 * @param {{utilities?: Array<{key: string, label: string, icon?: string}>, tenant?: boolean}} ctx
 *        tenant (v3.1.0) — „Ich wohne zur Miete": Seite „Mietverhältnis" unter Kosten
 * @returns {Array<{view: string, href: string, label: string, badge?: string, utility?: string}>}
 */
export function sectionPages(section, ctx = {}) {
  return allSectionPages(section, ctx).filter(p => viewAllowed(p.view));
}

function allSectionPages(section, { utilities = [], tenant = false } = {}) {
  switch (section) {
    case 'consumption':
      return utilities.map(u => ({ view: 'utility:' + u.key, href: `#/utility/${u.key}`, label: u.label, utility: u.key }));
    case 'costs': {
      const pages = [
        { view: 'contracts-overview', href: '#/contracts', label: t('nav.contracts') },
        // v3.12.0 (M4) — beendete und abgelöste Verträge, eigene Ansicht statt Tabelle je Art
        { view: 'contracts-past', href: '#/contracts/past', label: t('nav.contractsPast') },
        { view: 'tariffs', href: '#/tariffs', label: t('nav.tariffs') },
      ];
      // v3.1.0 (H5, UI-35) — für alle Arten mit Zählerständen und Verträgen (bis
      // v3.0 nur Gas: Zustandszahl × Brennwert, F1012)
      if (utilities.some(u => u.supports_bill_check || u.key === 'gas')) {
        pages.push({ view: 'bill-check', href: '#/bill-check', label: t('nav.billCheck') });
      }
      // v3.1.0 (H3, F1008) — Mieter: Vorauszahlung, Budget, Nebenkostenabrechnungen
      if (tenant) pages.push({ view: 'tenancy', href: '#/tenancy', label: t('nav.tenancy') });
      return pages;
    }
    case 'analysis':
      return [
        // v3.12.0 (M4) — Kennzahlen zuerst: die Karten der alten Übersicht im festen Raster
        { view: 'figures', href: '#/figures', label: t('nav.figures') },
        { view: 'analysis', href: '#/analysis', label: t('nav.analysis') },
        { view: 'forecast', href: '#/forecast', label: t('nav.forecast') },
        // v3.3.0 (F1021) — Flexibilität bewerten (Viertelstunden)
        { view: 'flexibility', href: '#/flexibility', label: t('nav.flexibility') },
        { view: 'report', href: '#/report', label: t('nav.report') },
      ];
    case 'todo':
      return [
        { view: 'todo', href: '#/todo', label: t('nav.todoOpen'), badge: 'hints' },
        { view: 'reminders', href: '#/todo/dates', label: t('nav.reminders'), badge: 'reminders' },
        // v3.4.0 (F1024) — was erledigt wurde, von wem und wann
        { view: 'activity', href: '#/todo/history', label: t('nav.activity') },
      ];
    case 'settings':
      // v2.12.0 (Review UI-13) — Unterseiten statt einer langen Seite;
      // v3.12.0 — Wetter und Integrationen stehen unter Verbindungen, Haushalt ist ein eigener
      // Bereich (M3); die Seiten sind Themen, die erste trägt Suche und „Für dich“
      return [
        { view: 'settings:general', href: '#/settings', label: t('settings.page.general.title') },
        { view: 'settings:region', href: '#/settings/region', label: t('settings.page.region.title') },
        { view: 'settings:display', href: '#/settings/display', label: t('settings.page.display.title') },
        { view: 'settings:data', href: '#/settings/data', label: t('settings.page.data.title') },
        { view: 'settings:access', href: '#/settings/access', label: t('settings.page.access.title') },
        { view: 'settings:calculation', href: '#/settings/calculation', label: t('settings.page.calculation.title') },
        { view: 'settings:system', href: '#/settings/system', label: t('settings.page.system.title') },
      ];
    case 'household':
      // v3.12.0 (M3) — Zähler stehen an einem Ort (Filter je Art); keine Seite hängt an einer Stufe
      return [
        { view: 'household:meters', href: '#/household/meters', label: t('household.meters.tab') },
        { view: 'household:people', href: '#/household/people', label: t('household.people.tab') },
        { view: 'household:building', href: '#/household/building', label: t('household.building.tab') },
        { view: 'household:utilities', href: '#/household/utilities', label: t('household.utilities.tab') },
      ];
    default:
      return [];
  }
}

/**
 * Die Einträge der Seitenleiste. `section` verbindet einen Eintrag mit den
 * Ansichten, bei denen er als aktiv gilt.
 *
 * @param {Array<{key: string, label: string}>} utilities  aktive Verbrauchsarten
 */
export function sidebarModel(utilities) {
  const daily = [
    { key: 'dashboard', section: 'overview', href: '#/dashboard', icon: 'home', label: t('nav.dashboard') },
    { key: 'todo', section: 'todo', href: '#/todo', icon: 'aufgaben', label: t('nav.todo'), badge: 'hints' },
    { key: 'readings-entry', section: 'capture', href: '#/zaehlerstaende', icon: 'klemmbrett', label: t('nav.readings'), badge: 'outbox' },
    // v3.7.0 — Posteingang für Versorgerpost, in jeder Stufe: Ablegen und „Von Hand
    // erfassen“ gehen ohne KI, und das Auslesen nimmt gerade Einsteigern die Formulare ab
    { key: 'inbox', section: 'inbox', href: '#/inbox', icon: 'eingang', label: t('nav.inbox'), badge: 'inbox' },
    {
      key: 'consumption', section: 'consumption', group: t('nav.group.consumption'),
      children: utilities.map(u => ({
        key: 'utility:' + u.key, section: 'consumption', href: `#/utility/${u.key}`,
        label: u.label, utility: u.key, glyph: u.glyph,
      })),
    },
    { key: 'costs', section: 'costs', href: '#/contracts', icon: 'geld', label: t('nav.group.costs') },
    // v3.2.0 — Einsteiger sahen hier nur den Jahresbericht; v3.6.0 (F1031) — zuerst die Prognose-Werkstatt
    { key: 'analysis', section: 'analysis', href: sectionPages('analysis')[0]?.href || '#/report', icon: 'saeulen', label: t('nav.group.analysis') },
  ];
  return [
    { key: 'daily', group: t('nav.group.daily'), children: daily },
    { key: 'setup', group: t('nav.group.setup'), children: [
      { key: 'household', section: 'household', href: '#/household', icon: 'block', label: t('nav.household') },
      { key: 'connections', section: 'connections', href: '#/connections', icon: 'stecker', label: t('nav.connections') },
      { key: 'settings', section: 'settings', href: '#/settings', icon: 'zahnrad', label: t('nav.settings') },
    ] },
  ];
}

/** Die fünf Ziele der Tab-Leiste (iPhone); `capture` öffnet das Erfassen-Blatt. */
export function tabbarModel(firstUtility) {
  return [
    { key: 'overview', section: 'overview', href: '#/dashboard', icon: 'home', label: t('nav.tab.overview') },
    { key: 'todo', section: 'todo', href: '#/todo', icon: 'aufgaben', label: t('nav.tab.todo'), badge: 'hints' },
    { key: 'capture', section: 'capture', action: 'capture', icon: 'plus', label: t('nav.tab.capture'), badge: 'outbox' },
    { key: 'consumption', section: 'consumption', href: firstUtility ? `#/utility/${firstUtility}` : '#/dashboard', icon: 'verlaufskurve', label: t('nav.tab.consumption') },
    { key: 'more', action: 'more', icon: 'menue', label: t('nav.tab.more'), badge: 'inbox' },
  ];
}
