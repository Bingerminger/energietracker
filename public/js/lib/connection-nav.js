// =====================================================================
// v3.12.0 (M2) — Verbindungen: Rücksprung und Reiter der Unterseiten.
// Eine Quelle für die Seiten, die das Einstellungs-Gerüst zeichnet (views/connections.js),
// und für die Wetterdaten (views/temperatures.js).
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { icon } from './icons.js';

/** Zurück zur Liste der Verbindungen */
export const connectionCrumbHtml = () => `<p class="view-crumb" data-role="connection-crumb"><a href="#/connections">${icon('links', { size: 16 })} ${esc(t('nav.connections'))}</a></p>`;

/** Reiter einer Unterseite: [{ href, label, active, badge? }] */
export function connectionTabsHtml(tabs) {
  return `<nav class="section-tabs" aria-label="${esc(t('nav.sectionNav'))}">${tabs.map(x => `<a class="section-tabs__tab${x.active ? ' active' : ''}" href="${x.href}"${x.active ? ' aria-current="page"' : ''}>${esc(x.label)}${x.badge ? ` <span class="sidebar__badge">${esc(String(x.badge))}</span>` : ''}</a>`).join('')}</nav>`;
}
