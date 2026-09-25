// =====================================================================
// Energietracker v2.11.0 — Navigationsmodell (Review UI-12, UI-05)
//
// Eine Quelle für Seitenleiste (Mac), Tab-Leiste und „Mehr" (iPhone) und
// die Bereichs-Tabs über den Ansichten. Bis v2.10 stand die Navigation als
// 17 flache Einträge in sidebar.js, geordnet nach Datentöpfen; Verträge,
// Rechnungsprüfung und Jahresbericht hatten keinen eigenen Platz, und
// Menüname und Seitentitel wichen voneinander ab („Dashboard" / „Übersicht").
//
// Jetzt sieben Bereiche nach den Fragen der Nutzer:
//   Übersicht · Zählerstände · Verbrauch (je Verbrauchsart) ·
//   Kosten & Verträge · Auswertungen · Hinweise · Einstellungen
// Ein Bereich mit mehreren Seiten zeigt sie als Tabs über der Ansicht; in
// der Seitenleiste steht er als ein Eintrag. Alte Adressen bleiben gültig.
// =====================================================================

import { t } from './i18n.js';

/**
 * Die Seiten eines Bereichs, in Tab-Reihenfolge. `view` ist der Schlüssel
 * aus der Routentabelle (router.js).
 *
 * @param {string} section
 * @param {{utilities?: Array<{key: string, label: string, icon?: string}>}} ctx
 * @returns {Array<{view: string, href: string, label: string, badge?: string, utility?: string}>}
 */
export function sectionPages(section, { utilities = [] } = {}) {
  switch (section) {
    case 'consumption':
      return utilities.map(u => ({ view: 'utility:' + u.key, href: `#/utility/${u.key}`, label: u.label, icon: u.icon, utility: u.key }));
    case 'costs': {
      const pages = [
        { view: 'contracts-overview', href: '#/contracts', label: t('nav.contracts') },
        { view: 'tariffs', href: '#/tariffs', label: t('nav.tariffs') },
      ];
      // Die Rechnungsprüfung rechnet Zustandszahl × Brennwert — nur Gas (F1012)
      if (utilities.some(u => u.key === 'gas')) {
        pages.push({ view: 'bill-check', href: '#/bill-check', label: t('nav.billCheck') });
      }
      return pages;
    }
    case 'analysis':
      return [
        { view: 'analysis', href: '#/analysis', label: t('nav.analysis') },
        { view: 'forecast', href: '#/forecast', label: t('nav.forecast') },
        { view: 'report', href: '#/report', label: t('nav.report') },
      ];
    case 'hints':
      return [
        { view: 'reminders', href: '#/reminders', label: t('nav.reminders'), badge: 'reminders' },
        { view: 'recommendations', href: '#/recommendations', label: t('nav.recommendations'), badge: 'recommendations' },
      ];
    case 'settings':
      return [
        { view: 'settings', href: '#/settings', label: t('nav.general') },
        { view: 'temperatures', href: '#/temperatures', label: t('nav.weather') },
      ];
    default:
      return [];
  }
}

/**
 * Die Einträge der Seitenleiste. `section` verbindet einen Eintrag mit den
 * Ansichten, bei denen er als aktiv gilt.
 *
 * @param {Array<{key: string, label: string, icon?: string}>} utilities  aktive Verbrauchsarten
 */
export function sidebarModel(utilities) {
  return [
    { key: 'dashboard', section: 'overview', href: '#/dashboard', icon: '🏠', label: t('nav.dashboard') },
    { key: 'readings-entry', section: 'capture', href: '#/zaehlerstaende', icon: '📋', label: t('nav.readings') },
    {
      key: 'consumption', section: 'consumption', group: t('nav.group.consumption'),
      children: utilities.map(u => ({
        key: 'utility:' + u.key, section: 'consumption', href: `#/utility/${u.key}`,
        icon: u.icon || '•', label: u.label, utility: u.key,
      })),
    },
    { key: 'costs', section: 'costs', href: '#/contracts', icon: '💶', label: t('nav.group.costs') },
    { key: 'analysis', section: 'analysis', href: '#/analysis', icon: '📊', label: t('nav.group.analysis') },
    { key: 'hints', section: 'hints', href: '#/reminders', icon: '📌', label: t('nav.group.hints'), badge: 'hints' },
    { key: 'settings', section: 'settings', href: '#/settings', icon: '⚙️', label: t('nav.settings') },
  ];
}

/** Die fünf Ziele der Tab-Leiste (iPhone); `capture` öffnet das Erfassen-Blatt. */
export function tabbarModel(firstUtility) {
  return [
    { key: 'overview', section: 'overview', href: '#/dashboard', icon: '🏠', label: t('nav.tab.overview') },
    { key: 'consumption', section: 'consumption', href: firstUtility ? `#/utility/${firstUtility}` : '#/dashboard', icon: '📈', label: t('nav.tab.consumption') },
    { key: 'capture', section: 'capture', action: 'capture', icon: '＋', label: t('nav.tab.capture') },
    { key: 'costs', section: 'costs', href: '#/contracts', icon: '💶', label: t('nav.tab.costs') },
    { key: 'more', action: 'more', icon: '☰', label: t('nav.tab.more'), badge: 'hints' },
  ];
}
