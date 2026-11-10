// =====================================================================
// Energietracker v1.3.0 — Empfehlungen
// v3.12.0 (M2) — die Karten stehen in Zu tun › Offen (Filter Empfehlungen, Rechte); views/todo.js ruft render()
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr, toastUndo } from '../components/toast.js';
import { t } from '../lib/i18n.js';
import { escapeHtml as esc, fmt } from '../lib/format.js';
import { isHeatLimitRec, heatLimitLaterUntil } from '../lib/heat-limit.js';   // v3.9.0 (Skeptiker N5)
import { AREA_LINKS } from '../lib/area-assumed.js';                         // v3.9.0 (Skeptiker N6)
import { letterButton, bindLetterButtons, findingOfRec, rightsFooterHtml } from '../lib/letter.js';   // v3.11.0 (F1035, F1036)
import { docUrl } from '../lib/docs.js';                                       // v3.11.0 (doku)
import { icon } from '../lib/icons.js';

const SEV_CLS = { urgent: 'danger', warning: 'warning', info: 'info' };
const sevLabel = (k) => t('recommendations.sev.' + k);
const catLabel = (k) => { const v = t('recommendations.cat.' + k); return v === 'recommendations.cat.' + k ? k : v; };

let filterSev = 'all';

/** v3.12.0 — wie RecommendationService::all(): Stufe, darin die Rechte-Hinweise zuerst (die Sortierung ist stabil). */
const SEV_RANK = { urgent: 0, warning: 1, info: 2 };
export function sortRecs(list) {
  const key = (r) => (SEV_RANK[r.severity] ?? 3) * 2 + (r.category === 'recht' ? 0 : 1);
  return [...list].sort((a, b) => key(a) - key(b));
}
const isRights = (r) => r.category === 'recht';

export async function render(container, _params = [], ctx = {}) {
  // v3.12.0 — Sprungmarke `#/todo?art=rec&rec=<id>`; eine unbekannte Kennung zeigt einfach die Liste
  const target = ctx?.query?.get?.('rec') || null;
  container.innerHTML = `<div class="loading">${t('recommendations.loading')}</div>`;
  let recs;
  // v2.13.0 (Review UI-27) — ohne Daten hieß es „alles im grünen Bereich";
  // jetzt sagt die Seite, dass es für Empfehlungen noch zu wenig gibt
  let tooLittleData = false;
  try {
    recs = await api.recommendations();
    if (!recs.length) {
      const rows = (await api.readingsOverview().catch(() => null))?.rows || [];
      tooLittleData = rows.length > 0 && !rows.some(r => (r.reading_count || 0) >= 3);
    }
  } catch (e) {
    container.innerHTML = `<div class="infobox infobox--fehler">${t('recommendations.loadError', { msg: esc(e.message || e) })}</div>`;
    return;
  }
  // v3.11.0 (Skeptiker M7) — ohne erfasste Rechnung prüft „Deine Rechte“ fast nichts; das sagt die Seite,
  // statt mit einer leeren Liste „alles in Ordnung“ anzudeuten (nur ohne Rechte-Hinweis, nur im Land mit Regeln)
  let rightsNoBill = false;
  // v3.12.0 — Filter „Rechte“ nur im Land mit Regeln; ohne Befund nennt die Seite den Weg über die Vertragskarte
  let rightsOn = recs.some(isRights);
  const noFinding = !rightsOn;
  if (noFinding) {
    const rr = await api.rights().catch(() => null);
    rightsOn = !!rr?.enabled;
    rightsNoBill = rightsOn && rr?.bills_recorded === 0;
  }
  // Das Sprungziel liegt nie hinter einem Filter
  // v3.12.0 (M2) — „Rechte“ ist ein Filter der Seite (`ctx.only`), die Stufen stehen unter „Empfehlungen“
  const only = ctx?.only || null;
  if ((target && recs.some(r => r.id === target)) || filterSev === 'rights') filterSev = 'all';
  if (only) filterSev = only;

  const draw = () => {
    const list = filterSev === 'all' ? recs : filterSev === 'rights' ? recs.filter(isRights) : recs.filter(r => r.severity === filterSev);
    const counts = recs.reduce((a, r) => (a[r.severity] = (a[r.severity] || 0) + 1, a), {});

    container.innerHTML = `
      <p class="muted small rec-intro">${t('recommendations.subtitle')}</p>
      ${only ? '' : `<div class="seg rec-sev" role="group" aria-label="${t('recommendations.filterGroupLabel')}">
        ${segBtn('all', t('recommendations.filterAll', { count: recs.length }))}
        ${segBtn('urgent', `${sevLabel('urgent')} (${counts.urgent || 0})`)}
        ${segBtn('warning', `${sevLabel('warning')} (${counts.warning || 0})`)}
        ${segBtn('info', `${sevLabel('info')} (${counts.info || 0})`)}
      </div>`}

      ${list.length === 0
        ? `<div class="infobox infobox--info">${tooLittleData ? esc(t('recommendations.emptyNoData')) : filterSev === 'all' ? t('recommendations.emptyAll') : t(`recommendations.emptyFiltered.${filterSev}`)}</div>`
        : `<div class="rec-list">${list.map(r => card(r, r.id === target)).join('')}</div>`}
      ${rightsNoBill ? `<p class="muted small" data-role="rights-no-bill" style="margin-top:var(--sp-4)">${esc(t('recommendations.rightsNoBill'))} <a href="#/bill-check">${esc(t('recommendations.rightsNoBillLink'))}</a></p>` : ''}
      ${rightsOn && noFinding ? `<p class="muted small" data-role="rights-letter-way" style="margin-top:var(--sp-4)">${esc(t('recommendations.rightsLetterWay'))} <a href="#/contracts">${esc(t('nav.contracts'))}</a></p>` : ''}
    `;

    container.querySelectorAll('.seg__btn').forEach(b =>
      b.addEventListener('click', () => { filterSev = b.dataset.sev; draw(); }));
    // v3.11.0 (F1036) — „Brief entwerfen“ an Befunden „Deine Rechte als Kunde“
    bindLetterButtons(container, (id) => findingOfRec(recs.find(r => r.evidence?.finding_id === id)));

    container.querySelectorAll('[data-dismiss]').forEach(b =>
      b.addEventListener('click', async () => {
        const id = b.dataset.dismiss;
        const rec = recs.find(r => r.id === id);
        // v3.9.0 (Skeptiker N5) — der Heizgrenze-Vorschlag: „Nicht jetzt“ wie in „Zu tun“
        const heat = isHeatLimitRec(rec);
        try {
          const res = await api.dismissRecommendation(id, heat ? heatLimitLaterUntil() : null);
          recs = recs.filter(r => r.id !== id);
          // v2.12.0 (Review UI-22) — mit Namen und „Rückgängig"
          // v3.4.0 (F1024) — mit Frist: ausgeblendet bis zum Stichtag (sagt der Server)
          const until = holdsUntil(rec);
          const msg = heat ? t('heatLimit.dismissed')
            : until && res?.until
            ? t('recommendations.dismissedUntilNamed', { title: rec?.title || '', date: fmt.date(res.until) })
            : t('recommendations.dismissedNamed', { title: rec?.title || '' });
          toastUndo(msg, async () => {
            try {
              await api.restoreRecommendation(id);
              if (rec && !recs.some(r => r.id === id)) recs = sortRecs([rec, ...recs]);   // v3.12.0 — an seinen Platz
              toastOk(t('recommendations.restored'));
              window.dispatchEvent(new CustomEvent('et:badges-refresh'));
              ctx?.onChange?.();
              if (container.isConnected) draw();
            } catch (e) { toastErr(t('recommendations.error', { msg: e.message || e })); }
          });
          window.dispatchEvent(new CustomEvent('et:badges-refresh'));
          ctx?.onChange?.();
          draw();
        } catch (e) { toastErr(t('recommendations.error', { msg: e.message || e })); }
      }));
  };

  draw();
  scrollToTarget(container);
}

/**
 * v3.12.0 — zur hervorgehobenen Karte. Erst nach dem Router (setTimeout): Er scrollt nach dem Rendern
 * an den Anfang und legt den Fokus auf den Hauptbereich; danach trägt die Karte den Fokus, damit
 * ein Screenreader bei ihr weiterliest.
 */
function scrollToTarget(container) {
  const el = container.querySelector('[data-rec-target]');
  if (!el) return;
  setTimeout(() => {
    if (!el.isConnected) return;
    el.scrollIntoView?.({ block: 'center' });
    el.focus?.({ preventScroll: true });
  }, 0);
}

function segBtn(sev, label) {
  const active = filterSev === sev;
  return `<button class="seg__btn ${active ? 'active' : ''}" data-sev="${sev}" aria-pressed="${active}">${label}</button>`;
}

/**
 * v3.4.0 (F1024) — Hinweis mit Frist (Kündigung, Vertragsende, Preiserhöhung):
 * Ausblenden hält bis zu diesem Tag, wenn er weiter als 30 Tage weg ist.
 * @returns {string|null} JJJJ-MM-TT
 */
function holdsUntil(r) {
  const d = r?.evidence?.deadline;
  if (!d) return null;
  const in30 = new Date();
  in30.setDate(in30.getDate() + 30);
  const lim = `${in30.getFullYear()}-${String(in30.getMonth() + 1).padStart(2, '0')}-${String(in30.getDate()).padStart(2, '0')}`;
  return d > lim ? d : null;
}

function card(r, isTarget = false) {
  const sevKey = SEV_CLS[r.severity] ? r.severity : 'info';
  const cls = SEV_CLS[sevKey];
  const until = holdsUntil(r);
  const heat = isHeatLimitRec(r);
  const label = heat ? t('heatLimit.notNow') : until ? t('recommendations.dismissUntil', { date: fmt.date(until) }) : t('recommendations.dismiss');
  const aria = heat ? t('heatLimit.notNowNamed', { title: r.title })
    : until ? t('recommendations.dismissUntilNamed', { title: r.title, date: fmt.date(until) }) : t('recommendations.dismissNamed', { title: r.title });
  const rights = r.category === 'recht';   // v3.11.0 (F1035) — Rechte als Kunde
  return `
    <div class="rec-card rec-card--${cls}${isTarget ? ' rec-card--target' : ''}" data-rec-id="${esc(r.id)}"${rights ? ` data-role="rights-card" data-check="${esc(r.evidence?.check || '')}"` : ''}${isTarget ? ` data-rec-target tabindex="-1" role="group" aria-current="true" aria-label="${esc(r.title)}"` : ''}>
      <div class="rec-card__head">
        ${icon(cls === 'danger' ? 'fehler' : cls === 'warning' ? 'achtung' : 'info', { size: 20, cls: `ton-ico--${cls}` })}
        <span class="badge badge--${cls}">${sevLabel(sevKey)}</span>
        <span class="rec-card__cat">${esc(catLabel(r.category))}${rights && r.evidence?.check ? ` · ${esc(checkLabel(r.evidence.check))}` : ''}</span>
        <!-- v2.11.0 (Review UI-26) — sichtbarer Text statt des Kreuzes: Es sah
             nach „löschen" aus und blendete doch nur 30 Tage aus -->
        <button type="button" class="btn btn--ghost btn--sm rec-card__dismiss" data-dismiss="${esc(r.id)}"
          ${heat ? `title="${esc(t('heatLimit.notNowHint'))}"` : ''} aria-label="${esc(aria)}">${esc(label)}</button>
      </div>
      <div class="rec-card__title">${esc(r.title)}</div>
      <div class="rec-card__detail">${esc(r.detail)}${r.evidence?.area_assumed
        ? ` <a href="${AREA_LINKS.household}" data-role="area-assumed-link">${esc(t('area.enterHousehold'))}</a>` : ''}</div>
      ${rights ? rightsExtrasHtml(r) : ''}
    </div>`;
}

/** v3.11.0 (F1035) — Name einer Prüfung („Rechnung kam spät“), sonst der Schlüssel. */
const checkLabel = (k) => { const v = t('rights.check.' + k); return v === 'rights.check.' + k ? k : v; };

/**
 * v3.11.0 (F1035) — unter dem Befund: Fußzeile mit den Normen als Link, darunter „Brief entwerfen“
 * und (Paket doku) „Was die App prüft“: das Handbuch an der Stelle dieser Prüfung
 * (`rights-<prüfung>`, Unterstrich als Bindestrich: der Renderer erlaubt in `<a id>` nur [a-z0-9-]).
 */
function rightsExtrasHtml(r) {
  const letter = letterButton(findingOfRec(r));
  const check = r.evidence?.check;
  const doc = check ? `<a href="${esc(docUrl('rights', 'rights-' + String(check).replaceAll('_', '-')))}" data-role="rights-doc">${esc(t('rights.docLink'))}</a>` : '';
  return `${rightsFooterHtml(r.evidence)}${letter || doc ? `<div class="rec-card__actions">${letter}${doc}</div>` : ''}`;
}

