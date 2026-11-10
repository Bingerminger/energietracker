// =====================================================================
// v3.12.0 (Paket wegweiser) — Wegweiser in der Hilfe: #/help/setups zeigt die
// Kurzauswahl und die Wege, #/help/setups/<weg> dazu einen Weg mit Schaubild,
// Text und „Im Detail“.
//
// Ohne Auswahl ist bewusst kein Weg vorbelegt: Eine Vorauswahl würde einen Weg
// zum Normalfall machen, und die Wege sind Bausteine ohne Rangfolge. Die Schritte
// stehen in den Anleitungen; der Wegweiser zeigt den Weg und verlinkt dorthin.
// Die Seite braucht keine Daten des Haushalts, und die Antworten der Kurzauswahl
// bleiben im Browserfenster: Gespeichert wird nichts.
// =====================================================================
import { t } from '../lib/i18n.js';
import { rich } from '../lib/rich.js';
import { escapeHtml as esc } from '../lib/format.js';
import { docUrl } from '../lib/docs.js';
import { atLeast } from '../lib/levels.js';
import { SETUP_IDS, SETUPS, sceneSvg, sceneFlows, sceneThumb, setupLabels, anchorFor, questionsHtml, readAnswers, setupsFor } from '../lib/setup-scenes.js';
import { icon } from '../lib/icons.js';


function linkHtml(l) {
  const label = esc(t(l.label));
  if (l.url) return `<a class="guide-link" href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${label} ${icon('extern', { size: 16 })}</a>`;
  if (l.scene) return `<a class="guide-link" href="#/help/setups/${esc(l.scene)}">${label}</a>`;
  if (l.app) return `<a class="guide-link" href="${esc(l.app)}">${label}</a>`;
  return `<a class="guide-link" href="${esc(docUrl(l.doc, anchorFor(l.anchor)))}">${label}</a>`;
}

/** Beide Fassungen des Bilds; die Seite zeigt per Container-Abfrage die passende. */
const figureHtml = (id, detail = false) => `
  <div class="guide-scene">
    <div class="guide-scene__wide">${sceneSvg(id, { variant: 'wide', detail })}</div>
    <div class="guide-scene__narrow">${sceneSvg(id, { variant: 'narrow', detail })}</div>
  </div>`;

function flowsHtml(id) {
  const { inside, across } = sceneFlows(id);
  const row = (f) => `<li>${esc(f.from)} → ${esc(f.to)}: ${esc(f.label)}</li>`;
  return `<div class="guide-flows" data-role="flows">
    <h4 class="guide-flows__title">${esc(t('setups.block.inside'))}</h4>
    <ul>${inside.map(row).join('')}</ul>
    <h4 class="guide-flows__title">${esc(t('setups.block.across'))}</h4>
    <ul>${across.map(row).join('')}</ul>
  </div>`;
}

const nums = (count) => Array.from({ length: count }, (_, i) => i + 1);

function detailHtml(id, labels) {
  const { key, detail } = SETUPS[id];
  const k = `setups.scene.${key}`;
  return `<details class="guide-detail" data-role="detail"${atLeast('expert') ? ' open' : ''}>
    <summary>${esc(t('setups.block.detail'))}</summary>
    <p>${rich(`${k}.detailIntro`, labels)}</p>
    ${figureHtml(id, true)}
    <h4>${esc(t('setups.block.addresses'))}</h4>
    <dl class="guide-rows">
      ${detail.rows.map(r => `<div>
        <dt>${esc(t(`${k}.row.${r.id}.label`))}</dt>
        <dd><code>${esc(r.addr)}</code></dd>
        <dd class="muted small">${rich(`${k}.row.${r.id}.note`, labels)}</dd>
      </div>`).join('')}
    </dl>
    ${detail.code ? `<h4>${esc(t(`${k}.codeTitle`))}</h4>
    <pre class="code-block guide-code"><code>${esc(detail.code)}</code></pre>` : ''}
    <h4>${esc(t('setups.block.notes'))}</h4>
    <ul class="guide-list">${nums(detail.notes).map(i => `<li>${rich(`${k}.note${i}`, labels)}</li>`).join('')}</ul>
    ${detail.errors ? `<h4>${esc(t('setups.block.errors'))}</h4>
    <dl class="guide-rows">
      ${nums(detail.errors).map(i => `<div>
        <dt>${rich(`${k}.err${i}.symptom`, labels)}</dt>
        <dd class="small">${rich(`${k}.err${i}.fix`, labels)}</dd>
      </div>`).join('')}
    </dl>` : ''}
    <p class="guide-sources">${detail.sources.map(linkHtml).join('')}</p>
  </details>`;
}

function scenarioHtml(id) {
  const s = SETUPS[id];
  const k = `setups.scene.${s.key}`;
  const labels = setupLabels();
  const block = (title, body) => `<section class="guide-block"><h3>${esc(t(title))}</h3>${body}</section>`;
  return `<article class="card guide-scenario" id="guide-scenario" data-guide="${esc(id)}" aria-labelledby="guide-title">
    <h2 class="card__title" id="guide-title">${esc(t(`${k}.title`))}</h2>
    <p class="guide-scenario__teaser">${esc(t(`${k}.teaser`))}</p>
    <figure class="guide-figure">
      ${figureHtml(id)}
      <figcaption>${flowsHtml(id)}</figcaption>
    </figure>
    <div class="guide-blocks">
      ${block('setups.block.forWhom', `<p>${rich(`${k}.forWhom`, labels)}</p>`)}
      ${block('setups.block.get', `<ul class="guide-list">${nums(s.get).map(i => `<li>${rich(`${k}.get${i}`, labels)}</li>`).join('')}</ul>
        ${s.getLinks ? `<p class="guide-sources">${s.getLinks.map(linkHtml).join('')}</p>` : ''}`)}
      ${block('setups.block.need', `<p><strong>${esc(t('setups.block.software'))}:</strong> ${rich(`${k}.software`, labels)}</p>
        <p><strong>${esc(t('setups.block.hardware'))}:</strong> ${rich(`${k}.hardware`, labels)}</p>`)}
      ${block('setups.block.effort', `<p>${rich(`${k}.effort`, labels)}</p>`)}
    </div>
    <section class="guide-block guide-block--wide">
      <h3>${esc(t('setups.block.steps'))}</h3>
      <ol class="guide-steps">
        ${s.steps.map((links, i) => `<li><span>${rich(`${k}.step${i + 1}`, labels)}</span> ${links.map(linkHtml).join('')}</li>`).join('')}
      </ol>
    </section>
    <div class="guide-blocks">
      ${block('setups.block.data', `<p>${rich(`${k}.data`, labels)}</p>`)}
      ${block('setups.block.limits', `<ul class="guide-list">${nums(s.limits).map(i => `<li>${rich(`${k}.limit${i}`, labels)}</li>`).join('')}</ul>`)}
    </div>
    ${detailHtml(id, labels)}
  </article>`;
}

/** Was die Antworten ergeben; ohne Antwort nur der Satz, dass keine Frage Pflicht ist. */
function resultHtml(ids) {
  if (!ids.length) return `<p class="muted small">${esc(t('setups.quick.hint'))}</p>`;
  return `<p class="guide-result__line"><strong>${esc(t('setups.quick.result'))}</strong>
    ${ids.map(id => `<a class="guide-link" href="#/help/setups/${esc(id)}">${esc(t(`setups.scene.${SETUPS[id].key}.title`))}</a>`).join('')}</p>
    ${ids.join() === 'by-hand' ? `<p class="muted small">${esc(t('setups.quick.nothing'))}</p>` : ''}`;
}

export async function render(container, params = [], ctx = {}) {
  const current = SETUP_IDS.includes(params[0]) ? params[0] : null;
  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${esc(t('setups.title'))}</h1>
        <p class="view-header__subtitle">${esc(t('setups.subtitle'))}</p>
      </div>
      <div class="view-header__actions">
        <a class="btn btn--ghost btn--sm" href="#/help">${esc(t('help.title'))}</a>
      </div>
    </div>
    <p class="guide-intro">${rich('setups.intro')}</p>
    ${current ? '' : `<section class="card guide-quick" aria-labelledby="guide-quick-title">
      <h2 class="card__title" id="guide-quick-title">${esc(t('setups.quick.title'))}</h2>
      <form class="guide-quick__form" data-role="quick">${questionsHtml()}</form>
      <div class="guide-result" data-role="quick-result" aria-live="polite">${resultHtml([])}</div>
    </section>`}
    <nav class="guide-tiles" aria-label="${esc(t('setups.choose'))}">
      ${SETUP_IDS.map(id => {
        const k = `setups.scene.${SETUPS[id].key}`;
        return `<a class="guide-tile${id === current ? ' guide-tile--on' : ''}" href="#/help/setups/${esc(id)}"${id === current ? ' aria-current="true"' : ''}>
          <span class="guide-tile__art">${sceneThumb(id)}</span>
          <strong>${esc(t(`${k}.title`))}</strong>
          <span class="muted small">${esc(t(`${k}.teaser`))}</span>
        </a>`;
      }).join('')}
    </nav>
    ${current ? scenarioHtml(current) : ''}`;

  const form = container.querySelector('[data-role="quick"]');
  form?.addEventListener('submit', (e) => e.preventDefault());
  form?.addEventListener('change', () => {
    container.querySelector('[data-role="quick-result"]').innerHTML = resultHtml(setupsFor(readAnswers(form)));
  });

  if (current && !ctx.inPlace) container.querySelector('#guide-scenario')?.scrollIntoView?.({ block: 'start' });
}
