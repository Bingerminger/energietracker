// =====================================================================
// v3.12.0 (M3) — Einstellungen › Übersicht: Suche, „Für dich“, die Themen für alle, was woanders steht.
//
// Die Suche findet jede Einstellung, auch über der Stufe. Tastatur: Pfeile laufen durch die Treffer,
// Eingabe öffnet den ersten, Escape leert. Treffer sind Links, das gilt auch am iPhone und im Vorleser.
// =====================================================================

import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { icon } from '../lib/icons.js';
import { atLeast, viewLevel } from '../lib/levels.js';
import { buildIndex, searchIndex, countText } from '../lib/settings-index.js';
import { GROUPS } from './settings-fields.js';

function rowHtml({ href, ico, title, meta, tag = '' }) {
  return `<li class="zeile-wrap"><a class="zeile" href="${esc(href)}">
    <span class="zeile__icon" aria-hidden="true">${icon(ico, { size: 20 })}</span>
    <span class="zeile__text"><span class="zeile__titel">${esc(title)}</span><span class="zeile__meta">${esc(meta)}</span></span>
    ${tag ? `<span class="zeile__status"><span class="tag tag--warning">${esc(tag)}</span></span>` : ''}
    <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>`;
}

/** Themen für alle, in der Reihenfolge der Reiter. `gate` ist der Schlüssel der Stufe. */
function topics(settings, defaultUpdates) {
  const r = (k) => settings[k] ?? '';
  const list = [
    { gate: 'settings:region', href: '#/settings/region', ico: 'globus', title: t('settings.page.region.title'),
      meta: [t(`countries.${settings.country || 'DE'}`), r('currency'), r('timezone')].filter(Boolean).join(', ') },
    { gate: 'settings:display', href: '#/settings/display', ico: 'glocke', title: t('settings.page.display.title'),
      meta: t('settings.topic.display', { a: r('contract_remind_days_1'), b: r('contract_remind_days_2'), c: r('contract_remind_days_3'), d: r('reminder_warn_days_before') }) },
    { gate: 'settings:data', href: '#/settings/data', ico: 'diskette', title: t('settings.page.data.title'), meta: t('settings.page.data.subtitle') },
    { gate: 'settings:access', href: '#/settings/access', ico: 'schloss', title: t('settings.page.access.title'), meta: t('settings.page.access.subtitle') },
    { gate: 'settings:calculation', href: '#/settings/calculation', ico: 'kolben', title: t('settings.page.calculation.title'), meta: t('settings.page.calculation.subtitle') },
    { gate: 'settings:system', href: '#/settings/system', ico: 'info', title: t('settings.page.system.title'), meta: t('settings.page.system.subtitle') },
  ];
  // Korrigierte Standardwerte gehen niemanden verloren: Die Karte steht unter Rechnen, der Hinweis hier in jeder Stufe
  if (Array.isArray(defaultUpdates) && defaultUpdates.length) {
    list.unshift({ gate: 'settings:general', href: '#/settings/calculation?field=default_updates', ico: 'neu', title: t('settings.defaultUpdates.title'), meta: t('settings.defaultUpdates.hint'), tag: String(defaultUpdates.length) });
  }
  return list;
}

export function renderRegister({ settings, defaultUpdates, cards }) {
  const rows = topics(settings, defaultUpdates);
  const visible = rows.filter(x => atLeast(viewLevel(x.gate)));
  const hidden = rows.length - visible.length;
  return `
    <div class="such" role="search" data-role="settings-search">
      <span class="such__lupe" aria-hidden="true">${icon('suche', { size: 18 })}</span>
      <input class="input input--text such__feld" id="such-feld" type="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false"
        placeholder="${esc(t('settings.search.placeholder'))}" aria-label="${esc(t('settings.search.label'))}" aria-controls="such-treffer">
      <p class="sr-only" id="such-status" role="status"></p>
      <div class="card zeilen such__treffer" id="such-treffer" hidden></div>
    </div>
    <h2 class="settings-subhead" data-role="for-you">${esc(t('settings.register.forYou'))}</h2>
    ${cards}
    <section class="card zeilen settings-topics" data-role="for-all">
      <div class="zeilen__kopf"><h2 class="card__title">${esc(t('settings.register.forAll'))}</h2></div>
      <ul class="zeilen__liste" data-role="topics">${visible.map(rowHtml).join('')}</ul>
      ${hidden ? `<template data-role="topics-all">${rows.map(rowHtml).join('')}</template>
      <div class="zeilen__fuss"><button type="button" class="btn btn--ghost btn--sm" id="themen-zeigen">${esc(t('settings.register.moreTopics'))}</button></div>` : ''}
    </section>
    <section class="card zeilen settings-topics" data-role="elsewhere">
      <div class="zeilen__kopf"><h2 class="card__title">${esc(t('settings.register.elsewhere'))}</h2></div>
      <ul class="zeilen__liste">
        ${rowHtml({ href: '#/household', ico: 'block', title: t('nav.household'), meta: t('settings.register.householdMeta') })}
        ${rowHtml({ href: '#/connections', ico: 'stecker', title: t('nav.connections'), meta: t('settings.register.connectionsMeta') })}
      </ul>
    </section>`;
}

const hitHtml = (e) => `<li class="zeile-wrap" data-search-key="${esc(e.key)}"><a class="zeile" href="${esc(e.href)}">
    <span class="zeile__text"><span class="zeile__titel">${esc(e.title)}</span><span class="zeile__meta">${esc(e.where)}</span></span>
    ${e.apiOnly ? `<span class="zeile__status"><span class="tag">${esc(t('settings.search.apiOnly'))}</span></span>` : ''}
    <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>`;

export function wireRegister(container, session) {
  const allRows = container.querySelector('#themen-zeigen');
  allRows?.addEventListener('click', () => {
    const list = container.querySelector('[data-role="topics"]');
    list.innerHTML = container.querySelector('template[data-role="topics-all"]').innerHTML;
    allRows.closest('.zeilen__fuss').remove();
    list.querySelector('a')?.focus();
  });

  const input = container.querySelector('#such-feld');
  const box = container.querySelector('#such-treffer');
  const status = container.querySelector('#such-status');
  if (!input || !box) return;
  const who = { admin: (session?.role || 'admin') === 'admin', login: !!(session?.user && session.mode !== 'off') };
  const index = buildIndex(GROUPS);
  let showMore = false;

  const draw = () => {
    const q = input.value.trim();
    if (!q) { box.hidden = true; box.innerHTML = ''; status.textContent = ''; return; }
    const { shown, more } = searchIndex(index, q, who);
    const list = showMore ? [...shown, ...more] : shown;
    box.hidden = false;
    box.innerHTML = `
      ${list.length ? `<ul class="zeilen__liste" data-role="search-hits">${list.map(hitHtml).join('')}</ul>` : `<p class="such__leer">${esc(t(more.length ? 'settings.search.onlyMore' : 'settings.search.none', { query: q }))}</p>`}
      ${more.length && !showMore ? `<div class="zeilen__fuss"><button type="button" class="btn btn--ghost btn--sm" id="such-mehr">${esc(tp('settings.search.more', more.length, { count: more.length }))}</button></div>` : ''}`;
    status.textContent = countText(list.length);
    box.querySelector('#such-mehr')?.addEventListener('click', () => {
      showMore = true;
      draw();
      box.querySelector('[data-search-key] a')?.focus();
    });
  };

  input.addEventListener('input', () => { showMore = false; draw(); });
  const links = () => [...box.querySelectorAll('a.zeile')];
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      links()[0]?.click();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      links()[0]?.focus();
    } else if (e.key === 'Escape' && input.value) {
      e.preventDefault();
      input.value = '';
      draw();
    }
  });
  box.addEventListener('keydown', (e) => {
    const all = links();
    const i = all.indexOf(document.activeElement);
    if (i < 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); all[Math.min(i + 1, all.length - 1)].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (i === 0 ? input : all[i - 1]).focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); input.value = ''; draw(); input.focus(); }
  });
}
