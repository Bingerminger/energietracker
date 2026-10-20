// =====================================================================
// v3.6.0 — Hinweis „angenommene Fläche“: Effizienzklasse und CO₂-Aufteilung
// rechnen ohne eingetragene Wohnfläche weiter mit dem Default (100 m²), sagen
// es aber deutlich und führen mit einem Link direkt zum Feld — Einstellungen →
// Haushalt (`?field=wohnflaeche_m2`) bzw. die Fläche im Mietverhältnis
// (`#/tenancy?field=wohnflaeche_m2`). Der Server meldet `area_assumed`.
// =====================================================================
import { fmt, escapeHtml } from './format.js';
import { t } from './i18n.js';

export const AREA_LINKS = {
  household: '#/settings/household?field=wohnflaeche_m2',
  tenancy:   '#/tenancy?field=wohnflaeche_m2',
};

/**
 * @param {number} area   die angenommene Fläche (m²)
 * @param {'household'|'tenancy'} where  wohin der Link führt
 */
export function areaAssumedHtml(area, where = 'household') {
  return `<p class="banner banner--warning" data-role="area-assumed">${escapeHtml(t('area.assumed', { area: fmt.num(area, 0) }))}
    <a href="${AREA_LINKS[where] || AREA_LINKS.household}">${escapeHtml(t(where === 'tenancy' ? 'area.enterTenancy' : 'area.enterHousehold'))}</a></p>`;
}
