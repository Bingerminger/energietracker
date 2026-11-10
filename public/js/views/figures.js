// =====================================================================
// v3.12.0 (M4) — Auswertungen › Kennzahlen: Wie der Haushalt dasteht.
//
// Die ausführlichen Karten der alten Übersicht im festen Raster: drei gleich hohe Karten
// (Effizienz, Einordnung, Tanks), die Reihe Strom-Saldo, darunter die Schaubilder; der
// Energiefluss steht in eigener Reihe. Gerechnet wird nichts Neues, die Übersicht zeigt
// davon die Kurzfassung als Zahlen und verweist hierher.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';
import { rich } from '../lib/rich.js';
import { icon } from '../lib/icons.js';
import { emptyHtml } from '../lib/empty.js';
import { comparisonCardHtml, efficiencyCardHtml, loadExplainers, loadTanks, pickYearRow, stromSaldoHtml, tanksCardHtml } from './dashboard.js';

export async function render(container) {
  const header = `<div class="view-header"><div>
      <h1 class="view-header__title">${escapeHtml(t('figures.title'))}</h1>
      <p class="view-header__subtitle">${rich('figures.subtitle')}</p>
    </div></div>`;
  container.innerHTML = `${header}<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;
  const [allUtilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length ? settings.active_utilities : allUtilities.map(u => u.key);
  const utilities = allUtilities.filter(u => active.includes(u.key));
  const [eff, cmp, stromSaldo, pvSummary, tanks] = await Promise.all([
    api.efficiency().catch(() => null),
    api.comparison().catch(() => null),
    api.stromSaldo().catch(() => null),
    api.pvSummary().catch(() => null),
    loadTanks(utilities),
  ]);
  // F1005 — Strom-Saldo nur, wenn wirklich PV-Einspeisung erfasst ist
  const pvActive = !!(stromSaldo && Array.isArray(stromSaldo.monthly) && stromSaldo.monthly.some(m => (m.einspeisung_kwh ?? 0) > 0));
  const saldoYear = pvActive ? pickYearRow(stromSaldo.yearly) : null;
  const pvYear = pvActive && pvSummary ? pickYearRow(pvSummary.yearly) : null;
  // v3.12.0 (m4b) — Die Effizienz ist die längste Karte: eigene Reihe über die ganze Breite (Zeilen zweispaltig);
  // Einordnung und Tanks stehen als Paar darunter. Zu dritt in einer Reihe blieb unter den beiden kürzeren mehr
  // als die Hälfte leer (Regel R5: keine leere Fläche über ein Drittel).
  const effCard = efficiencyCardHtml(eff, utilities);
  const pair = [comparisonCardHtml(cmp, utilities), tanksCardHtml(tanks, settings)].filter(Boolean);
  const cards = [effCard, ...pair].filter(Boolean);
  container.innerHTML = `${header}
    ${effCard ? `<div class="raster kennzahl-raster kennzahl-raster--eff" data-spalten="1">${effCard}</div>` : ''}
    ${pair.length ? `<div class="raster kennzahl-raster" data-spalten="${pair.length}" data-zellen="breit">${pair.join('')}</div>` : ''}
    ${stromSaldoHtml(saldoYear, pvYear)}
    <section class="card dash-explainers kennzahl-schaubilder" aria-labelledby="dash-ex-title">
      <h2 class="card__title" id="dash-ex-title">${icon('schaubild')} ${escapeHtml(t('dashboard.explainers.title'))}</h2>
      <div class="explainer-grid" data-role="explainers"><div class="loading">${escapeHtml(t('common.loading'))}</div></div>
    </section>
    <div data-role="figures-empty"></div>`;
  const box = container.querySelector('[data-role="explainers"]');
  await loadExplainers(utilities, box).catch(() => { box.closest('section')?.remove(); });
  // Noch nichts zum Zeigen: sagen, warum, statt einer leeren Seite
  if (!cards.length && !saldoYear && !container.querySelector('.dash-explainers')) {
    const empty = container.querySelector('[data-role="figures-empty"]');
    if (empty) empty.innerHTML = emptyHtml({ icon: 'saeulen', title: t('figures.empty.title'), text: t('figures.empty.text') });
  }
}
