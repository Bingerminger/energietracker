// =====================================================================
// v2.13.0 (Review DOC-10, UI-17, UI-32, I18N-25, MKT-23) — Hilfe.
//
// Erste Schritte (dieselbe Liste wie auf der leeren Übersicht), die Begriffe
// aus dem Glossar-Katalog mit Suche und Sprungmarken, Dokumentation in der
// passenden Sprache, der Weg zu Fragen und Fehlermeldungen und was mit den
// Daten geschieht. Für Französisch, Italienisch, Spanisch, Portugiesisch und
// Niederländisch gibt es keine Doku — dort ist diese Seite die Hilfe.
// =====================================================================
import { t } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';
import { GLOSSARY, glossaryTerm, glossaryText } from '../components/info.js';
import { setupSteps, setupListHtml, openSteps } from '../lib/onboarding.js';
import { docUrl, docsInOtherLanguage, ISSUES_URL } from '../lib/docs.js';

export async function render(container, _params = [], ctx = {}) {
  const terms = GLOSSARY
    .map(id => ({ id, term: glossaryTerm(id), text: glossaryText(id) }))
    .sort((a, b) => a.term.localeCompare(b.term));
  const doc = (page, key) => `<li><a href="${escapeHtml(docUrl(page))}" target="_blank" rel="noopener">${escapeHtml(t(key))}</a></li>`;

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('help.title'))}</h1>
        <p class="view-header__subtitle">${escapeHtml(t('help.subtitle'))}</p>
      </div>
    </div>

    <div class="help-grid">
      <section class="card" aria-labelledby="help-setup">
        <h2 class="card__title" id="help-setup">${escapeHtml(t('help.setup.title'))}</h2>
        <div data-role="setup"><p class="muted">${escapeHtml(t('common.loading'))}</p></div>
      </section>

      <section class="card" aria-labelledby="help-docs">
        <h2 class="card__title" id="help-docs">${escapeHtml(t('help.docs.title'))}</h2>
        <ul class="help-links">
          ${doc('start', 'help.docs.start')}
          ${doc('compendium', 'help.docs.compendium')}
          ${doc('glossary', 'help.docs.glossary')}
          ${doc('homeAssistant', 'help.docs.homeAssistant')}
        </ul>
        ${docsInOtherLanguage() ? `<p class="muted small">${escapeHtml(t('help.docs.englishOnly'))}</p>` : ''}

        <h2 class="card__title help-subtitle" id="help-support">${escapeHtml(t('help.support.title'))}</h2>
        <p class="small">${escapeHtml(t('help.support.text'))}</p>
        <p class="help-actions">
          <a class="btn btn--ghost btn--sm" href="${ISSUES_URL}" target="_blank" rel="noopener">${escapeHtml(t('help.support.issues'))}</a>
          <a class="btn btn--ghost btn--sm" href="#/settings/system">${escapeHtml(t('help.support.diagnostics'))}</a>
        </p>

        <h2 class="card__title help-subtitle" id="help-privacy">${escapeHtml(t('help.privacy.title'))}</h2>
        <p class="small">${escapeHtml(t('help.privacy.text'))}</p>
      </section>
    </div>

    <section class="card help-glossary" aria-labelledby="help-glossary">
      <div class="card__head">
        <h2 class="card__title" id="help-glossary">${escapeHtml(t('help.glossary.title'))}</h2>
        <input class="input input--text help-glossary__search" type="search" data-role="search"
          placeholder="${escapeHtml(t('help.glossary.search'))}" aria-label="${escapeHtml(t('help.glossary.search'))}">
      </div>
      <dl class="glossary-list" data-role="glossary">
        ${terms.map(x => `
        <div class="glossary-list__item" id="g-${escapeHtml(x.id)}" data-term="${escapeHtml(x.id)}">
          <dt>${escapeHtml(x.term)}</dt>
          <dd>${escapeHtml(x.text)}</dd>
        </div>`).join('')}
      </dl>
      <p class="muted" data-role="none" hidden>${escapeHtml(t('help.glossary.none'))}</p>
    </section>`;

  // Suche über Begriff und Erklärung
  const search = container.querySelector('[data-role="search"]');
  const items = [...container.querySelectorAll('.glossary-list__item')];
  const none = container.querySelector('[data-role="none"]');
  search?.addEventListener('input', () => {
    const q = search.value.trim().toLocaleLowerCase();
    let shown = 0;
    for (const el of items) {
      const hit = !q || el.textContent.toLocaleLowerCase().includes(q);
      el.hidden = !hit;
      if (hit) shown++;
    }
    none.hidden = shown > 0;
  });

  // Sprung aus einem ⓘ-Popover: #/help?term=hdd
  const wanted = ctx.query?.get('term');
  const target = wanted ? items.find(el => el.dataset.term === wanted) : null;
  if (target) {
    target.classList.add('glossary-list__item--focus');
    target.scrollIntoView?.({ block: 'center' });
  }

  const steps = await setupSteps().catch(() => []);
  const box = container.querySelector('[data-role="setup"]');
  if (box && box.isConnected) {
    box.innerHTML = `${openSteps(steps).length === 0 ? `<p class="banner banner--success">${escapeHtml(t('help.setup.allDone'))}</p>` : ''}${setupListHtml(steps)}`;
  }
}
