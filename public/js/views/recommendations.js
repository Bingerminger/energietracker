// =====================================================================
// Energietracker v1.3.0 — Empfehlungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';

const SEV_CLS = { urgent: 'danger', warning: 'warning', info: 'info' };
const sevLabel = (k) => t('recommendations.sev.' + k);
const catLabel = (k) => { const v = t('recommendations.cat.' + k); return v === 'recommendations.cat.' + k ? k : v; };

let filterSev = 'all';

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('recommendations.loading')}</div>`;
  let recs;
  try {
    recs = await api.recommendations();
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${t('recommendations.loadError', { msg: esc(e.message || e) })}</div>`;
    return;
  }

  const draw = () => {
    const list = filterSev === 'all' ? recs : recs.filter(r => r.severity === filterSev);
    const counts = recs.reduce((a, r) => (a[r.severity] = (a[r.severity] || 0) + 1, a), {});

    container.innerHTML = `
      <div class="view-head">
        <h1>${t('recommendations.title')}</h1>
        <p class="muted">${t('recommendations.subtitle')}</p>
      </div>

      <div class="seg" role="group" aria-label="${t('recommendations.filterGroupLabel')}" style="margin-bottom:var(--sp-4)">
        ${segBtn('all', t('recommendations.filterAll', { count: recs.length }))}
        ${segBtn('urgent', `${sevLabel('urgent')} (${counts.urgent || 0})`)}
        ${segBtn('warning', `${sevLabel('warning')} (${counts.warning || 0})`)}
        ${segBtn('info', `${sevLabel('info')} (${counts.info || 0})`)}
      </div>

      ${list.length === 0
        ? `<div class="banner banner--info">${filterSev === 'all' ? t('recommendations.emptyAll') : t('recommendations.emptyFiltered', { sev: sevLabel(filterSev) })}</div>`
        : `<div class="rec-list">${list.map(card).join('')}</div>`}
    `;

    container.querySelectorAll('.seg__btn').forEach(b =>
      b.addEventListener('click', () => { filterSev = b.dataset.sev; draw(); }));

    container.querySelectorAll('[data-dismiss]').forEach(b =>
      b.addEventListener('click', async () => {
        const id = b.dataset.dismiss;
        try {
          await api.dismissRecommendation(id);
          recs = recs.filter(r => r.id !== id);
          toastOk(t('recommendations.dismissed'));
          draw();
        } catch (e) { toastErr(t('recommendations.error', { msg: e.message || e })); }
      }));
  };

  draw();
}

function segBtn(sev, label) {
  const active = filterSev === sev;
  return `<button class="seg__btn ${active ? 'active' : ''}" data-sev="${sev}" aria-pressed="${active}">${label}</button>`;
}

function card(r) {
  const sevKey = SEV_CLS[r.severity] ? r.severity : 'info';
  const cls = SEV_CLS[sevKey];
  return `
    <div class="rec-card rec-card--${cls}">
      <div class="rec-card__head">
        <span class="badge badge--${cls}">${sevLabel(sevKey)}</span>
        <span class="rec-card__cat">${esc(catLabel(r.category))}</span>
        <button class="rec-card__x" data-dismiss="${r.id}" title="${t('recommendations.dismiss')}" aria-label="${t('recommendations.dismiss')}"><span aria-hidden="true">✕</span></button>
      </div>
      <div class="rec-card__title">${esc(r.title)}</div>
      <div class="rec-card__detail">${esc(r.detail)}</div>
    </div>`;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
