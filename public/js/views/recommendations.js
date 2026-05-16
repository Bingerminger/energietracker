// =====================================================================
// Energietracker v1.3.0 — Empfehlungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr } from '../components/toast.js';

const SEV = {
  urgent:  { label: 'Dringend', cls: 'danger'  },
  warning: { label: 'Achtung',  cls: 'warning' },
  info:    { label: 'Hinweis',  cls: 'info'    },
};
const CAT = {
  effizienz: 'Effizienz', vertrag: 'Vertrag', bestand: 'Bestand',
  anomalie: 'Anomalie',  trend: 'Trend',
};

let filterSev = 'all';

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade Empfehlungen…</div>';
  let recs;
  try {
    recs = await api.recommendations();
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">Konnte Empfehlungen nicht laden: ${esc(e.message || e)}</div>`;
    return;
  }

  const draw = () => {
    const list = filterSev === 'all' ? recs : recs.filter(r => r.severity === filterSev);
    const counts = recs.reduce((a, r) => (a[r.severity] = (a[r.severity] || 0) + 1, a), {});

    container.innerHTML = `
      <div class="view-head">
        <h2>Empfehlungen</h2>
        <p class="muted">Statistische Hinweise aus deinen eigenen Daten — keine externen Quellen.</p>
      </div>

      <div class="seg" role="tablist" style="margin-bottom:var(--sp-4)">
        ${segBtn('all', `Alle (${recs.length})`)}
        ${segBtn('urgent', `Dringend (${counts.urgent || 0})`)}
        ${segBtn('warning', `Achtung (${counts.warning || 0})`)}
        ${segBtn('info', `Hinweis (${counts.info || 0})`)}
      </div>

      ${list.length === 0
        ? `<div class="banner banner--info">Keine ${filterSev === 'all' ? '' : SEV[filterSev]?.label + '-'}Empfehlungen — alles im grünen Bereich.</div>`
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
          toastOk('Empfehlung ausgeblendet (30 Tage)');
          draw();
        } catch (e) { toastErr('Fehler: ' + (e.message || e)); }
      }));
  };

  draw();
}

function segBtn(sev, label) {
  return `<button class="seg__btn ${filterSev === sev ? 'active' : ''}" data-sev="${sev}">${label}</button>`;
}

function card(r) {
  const s = SEV[r.severity] || SEV.info;
  return `
    <div class="rec-card rec-card--${s.cls}">
      <div class="rec-card__head">
        <span class="badge badge--${s.cls}">${s.label}</span>
        <span class="rec-card__cat">${CAT[r.category] || r.category}</span>
        <button class="rec-card__x" data-dismiss="${r.id}" title="30 Tage ausblenden">✕</button>
      </div>
      <div class="rec-card__title">${esc(r.title)}</div>
      <div class="rec-card__detail">${esc(r.detail)}</div>
    </div>`;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
