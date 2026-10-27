// =====================================================================
// Contracts view — F4 implementation:
//   - per-entry-row button "Vertragsbeginn übernehmen" copies start date
//     into the entry's date field
//   - inline validation: each row must have BOTH date+amount or BOTH empty
//   - half-filled rows are highlighted before the user can save
// =====================================================================

import { api } from '../api.js';
import { getUtility, getSettings } from '../state.js';
import { gasEntryOn, gasFactorOf, cvUnit } from '../lib/gas-factor.js';
import { fmt, escapeHtml, todayIso, parseDecimal, formatForInput, normalizeTime } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { t, tp } from '../lib/i18n.js';
import { associateFieldLabels } from '../lib/a11y.js';
import { showFieldError } from '../lib/form.js';
import { renderError } from '../components/error.js';
import { groupContracts, phasePill, yearsOf } from '../lib/contract-order.js';
// v3.7.0 — Zuständig am Vertrag (Person aus dem Verzeichnis)
import { chipHtml, personSelectHtml, personPayload } from '../lib/assignee.js';
import { BONUS_TYPES, bonusTypeLabel, bonusDetailLines } from '../lib/bonus.js';   // v3.7.0 (#35)
import { infoNote } from '../components/info.js';   // v3.7.0 (#35)
import { attachmentNames, attachmentLinksHtml, attachmentIdsOf } from '../lib/attachments.js';   // v3.7.0 — Belege am Vertrag
import { ADVANCE_EVERY, advanceAmountText, zeroAdvanceSpan, zeroAdvanceText } from '../lib/advance-rhythm.js';   // v3.9.0 (F1034) — Abschlag mit Rhythmus; R2-8 Hinweis zur 0-€-Zeile
import { dossierHtml, wireDossiers } from '../components/dossier.js';   // v3.7.0 (Paket betrieb) — Akte je Vertrag
import { subContractHint, deductingSubNames } from '../lib/rest-billing.js';   // v3.9.0 (F1032, #40)

// Titel/Labels werden zur Render-Zeit über t() aufgelöst (nicht beim Modul-
// Laden, da der Sprachkatalog dann ggf. noch nicht steht).
// v2.2.0 — die Betragslabels sind Übersetzungs-Keys statt fester Strings:
// „€/Monat" stand vorher auch in der englischen Oberfläche.
const GROUPS = [
  { key: 'working_prices',   titleKey: 'contracts.group.working', dateKey: 'from', amountKey: 'ct_per_kwh',    amountKey_: 'contracts.unit.ctPerKwh' },
  { key: 'base_prices',      titleKey: 'contracts.group.base',    dateKey: 'from', amountKey: 'eur_per_month', amountKey_: 'contracts.unit.eurPerMonth' },
  { key: 'advance_payments', titleKey: 'contracts.group.advance', dateKey: 'from', amountKey: 'amount_eur',    amountKey_: 'contracts.unit.eurPerMonth' },
];

// v3.1.0 (H5, CALC-31) — Fernwärme: Leistungspreis je kW und Jahr, Messpreis je Jahr
// v3.7.0 (Paket reifegrad) — für alle (bis v3.6 Experte): Beide stehen auf jeder
// Fernwärmerechnung neben Arbeits- und Grundpreis; ohne sie stimmt „was es kostet“
// nicht. Die Kennwerte (CO₂, Primärenergie) bleiben Experten vorbehalten.
const FW_GROUPS = [
  { key: 'capacity_prices', titleKey: 'contracts.fw.capacityPrice', dateKey: 'from', amountKey: 'eur_per_kw_year', amountKey_: 'contracts.unit.eurPerKwYear' },
  { key: 'metering_prices', titleKey: 'contracts.fw.meteringPrice', dateKey: 'from', amountKey: 'eur_per_year',    amountKey_: 'contracts.unit.eurPerYear' },
];
// v3.1.0 (H6, MKT-13) — § 14a EnWG Modul 1: pauschale Reduzierung des Netzentgelts je Jahr (Strom)
const GRID_GROUP = { key: 'grid_reduction', titleKey: 'contracts.gridReduction.title', dateKey: 'from', amountKey: 'eur_per_year', amountKey_: 'contracts.unit.eurPerYear', level: 'expert' };
const groupsFor = (u) => (u?.key === 'fernwaerme' ? [...GROUPS, ...FW_GROUPS] : u?.key === 'strom' ? [...GROUPS, GRID_GROUP] : GROUPS);
// v3.1.0 (H6, #17) — Arbeitspreis je Zähler eines Gruppenvertrags (HT/NT)
const memberGroup = (m) => ({ key: `wpm:${m.id}`, title: m.name || m.id, dateKey: 'from', amountKey: 'ct_per_kwh', amountKey_: 'contracts.unit.ctPerKwh' });
function groupDef(key) {
  if (key.startsWith('wpm:')) return memberGroup({ id: key.slice(4) });
  return [...GROUPS, ...FW_GROUPS, GRID_GROUP].find(x => x.key === key);
}

// F1003 — Sonderzahlungs-Arten. Die *_mit-Arten verändern zusätzlich den
// künftigen Abschlag. Labels über t('contracts.kinds.<value>').
const SPECIAL_PAYMENT_KINDS = [
  { value: 'rueckzahlung_mit',  affectsAdvance: true  },
  { value: 'rueckzahlung_ohne', affectsAdvance: false },
  { value: 'nachzahlung_mit',   affectsAdvance: true  },
  { value: 'nachzahlung_ohne',  affectsAdvance: false },
  { value: 'abschlagszahlung',  affectsAdvance: false },
];
const SPECIAL_KIND_AFFECTS_ADVANCE = new Set(
  SPECIAL_PAYMENT_KINDS.filter(k => k.affectsAdvance).map(k => k.value)
);

// Standard-Abschlagsvertrag (Spiegel von Utilities::hasAdvancePaymentContracts):
// Gas, Strom, Fernwärme — hier nur noch für den Gruppenvertrag.
// v2.13.0 (Review FE-14) — aus der SSOT (`/api/utilities`) statt einer Kopie
// der Regel; die Kopie bot PV-Einspeisung Sonderzahlungen an, die der Server
// still verwarf
function hasAdvancePaymentContracts(u) {
  return u?.has_advance_payment_contracts === true;
}

// F1003-Scope (Spiegel von Utilities::hasSpecialPayments): Gas, Strom,
// Wasser, Fernwärme. v3.5.0 (GitHub #34) — bis v3.4 hing der Abschnitt
// „Sonderzahlungen“ an hasAdvancePaymentContracts, Wasser fehlte.
function hasSpecialPayments(u) {
  return u?.has_special_payments === true;
}

export async function render(container, params) {
  const utilityKey = params[0];
  const u = await getUtility(utilityKey);
  if (!u) {
    container.innerHTML = `<div class="banner banner--error">${t('contracts.unknown', { key: escapeHtml(utilityKey) })}</div>`;
    return;
  }
  container.setAttribute('data-utility', u.key);

  // Heizöl/Pellets haben per Logik keine Verträge — die Tankrechnung
  // (Menge × Preis bzw. Gesamtbetrag der Lieferung) ist die Kostenbasis.
  // Ein Vertragsformular (Arbeits-/Grundpreis, Abschläge, Boni) ergibt
  // hier keinen Sinn; stattdessen zu den Lieferungen leiten.
  if (u.reading_kind === 'delivery') {
    container.innerHTML = `
      <div data-utility="${u.key}">
        <div class="section-head">
          <h1>${u.icon} ${t('contracts.title', { label: escapeHtml(u.label) })}</h1>
          <div class="section-actions">
            <a class="btn btn--ghost" href="#/utility/${u.key}">${t('contracts.toOverview')}</a>
          </div>
        </div>
        <div class="banner banner--info">
          <strong>${t('contracts.delivery.bannerTitle', { label: escapeHtml(u.label) })}</strong>
          ${t('contracts.delivery.bannerText', { unit: escapeHtml(u.volume_unit || ''), label: escapeHtml(u.label) })}
          <div style="margin-top: var(--sp-3)">
            <a class="btn btn--util" href="#/utility/${u.key}">${t('contracts.delivery.toDeliveries')}</a>
          </div>
        </div>
      </div>
    `;
    return;
  }

  await refresh(container, u);
}

async function refresh(container, u) {
  container.innerHTML = `<div class="loading">${t('contracts.loading')}</div>`;
  let meters, contracts, groups;
  try {
    [meters, contracts, groups] = await Promise.all([
      api.meters(u.key),
      api.contracts(u.key),
      api.meterGroups(u.key).catch(() => []),   // v3.1.0 (H6, #17)
    ]);
  } catch (e) {
    // v2.11.0 (Review FE-25) — sonst hing die Ansicht nach dem Speichern bei „Lädt…"
    renderError(container, e, () => refresh(container, u));
    return;
  }

  // v3.4.0 (N1021) — rückwärts durch die Zeit, gruppiert nach Phase:
  // Zukünftig · Aktuell · Beendet (ab drei eingeklappt) · Angebote zum Vergleich.
  // Bis v3.3 nur nach Beginn: Angebote für das nächste Jahr standen als
  // „neuester Vertrag“ oben, ein lange laufender Vertrag ganz unten.
  const grouped = groupContracts(contracts);
  // v3.9.0 (Skeptiker N7) — welche Subzähler mit eigenem Vertrag der Vertrag eines Zählers abzieht
  restCtx = { meters, contracts };
  // v3.7.0 — Dateinamen der Belege (nur, wenn ein Vertrag welche hat)
  const docNames = await attachmentNames(contracts);

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${t('contracts.title', { label: escapeHtml(u.label) })}</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">${t('contracts.toOverview')}</a>
          <button type="button" class="btn btn--util" data-action="new-contract" ${meters.length ? '' : 'disabled'}>${t('contracts.newContract')}</button>
        </div>
      </div>

      ${meters.length === 0 ? `
        <div class="banner banner--warning">${t('contracts.metersWarning')} <a href="#/utility/${u.key}/meters">${t('contracts.manageMeters')}</a></div>
      ` : ''}

      <div id="contracts-list">
        ${contracts.length === 0 ? `<p class="muted">${t('contracts.empty')}</p>${meters.length ? `<button type="button" class="btn btn--util" data-action="new-contract">${t('contracts.emptyCta')}</button>` : ''}` : ''}
        ${grouped.map(g => renderContractGroup(g, meters, u, groups, docNames)).join('')}
      </div>
    </div>
  `;

  wireDossiers(container);   // v3.7.0 (Paket betrieb) — Akte je Vertrag

  container.querySelectorAll('[data-action="new-contract"]').forEach(btn => {
    btn.addEventListener('click', () => {
      dispatchContractModal(u, meters, null, contracts, groups).then(changed => { if (changed) refresh(container, u); });
    });
  });

  container.querySelectorAll('[data-edit-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-contract');
      const c = contracts.find(x => x.id === id);
      const changed = await dispatchContractModal(u, meters, c, contracts, groups);
      if (changed) refresh(container, u);
    });
  });

  // v3.1.0 (H6, MKT-12) — Monatspreise (monat;ct_kwh[;grundpreis]) mit Vorschau
  container.querySelectorAll('[data-import-prices]').forEach(inp => {
    inp.addEventListener('change', async () => {
      const file = inp.files?.[0];
      if (!file) return;
      const id = inp.getAttribute('data-import-prices');
      try {
        const text = await file.text();
        const dry = await api.importContractPrices(u.key, id, text, { dryRun: true });
        const ok = await confirmModal({
          title: t('contracts.priceImport.title'),
          message: t('contracts.priceImport.preview', { n: dry.would_import, from: fmt.date(dry.from), to: fmt.date(dry.to) })
            + (dry.errors?.length ? ' ' + tp('contracts.priceImport.skipped', dry.errors.length) : ''),
          confirmLabel: t('contracts.priceImport.confirm'),
        });
        if (!ok) return;
        await api.importContractPrices(u.key, id, text);
        toastOk(t('contracts.priceImport.done'));
        refresh(container, u);
      } catch (e) { toastErr(e.message); }
      finally { inp.value = ''; }
    });
  });

  container.querySelectorAll('[data-delete-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-delete-contract');
      // v2.12.0 (Review UI-22) — die Rückfrage nennt den Vertrag
      const c = contracts.find(x => x.id === id);
      const name = [c?.provider, c?.tariff_name].filter(Boolean).join(' · ') || t('contracts.card.fallbackProvider');
      const ok = await confirmModal({ title: t('contracts.delete.title'), message: t('contracts.delete.messageNamed', { name }), confirmLabel: t('contracts.delete.confirm'), danger: true });
      if (!ok) return;
      try { await api.deleteContract(u.key, id); toastOk(t('contracts.delete.deleted')); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

function dispatchContractModal(u, meters, existing, contracts = [], groups = []) {
  return u.key === 'wasser'
    ? openWaterContractModal(u, meters, existing, contracts)
    : openContractModal(u, meters, existing, contracts, groups);
}

// v3.1.0 (H6, #17) — Ziel eines Vertrags: Zähler oder Zählergruppe
const targetOf = (c) => c?.meter_group_id || c?.meter_id || '';

// v2.5.3 (UI-04) — Ein neuer Vertrag beginnt am Tag nach dem Ende der
// laufenden Bindung, sonst heute. Vorher stand immer „heute" im Feld: Ein
// versehentliches Speichern löste damit ab sofort den laufenden Vertrag ab,
// denn bei Überlappung gewinnt der spätere Beginn (findActiveForDate).
function suggestStart(contracts, meterId) {
  const today = todayIso();
  const lastEnd = contracts
    .filter(c => !c.is_shadow && c.meter_id === meterId)
    .map(c => c.end || '')
    .filter(end => end >= today)
    .sort()
    .pop();
  if (!lastEnd) return today;
  const [y, m, d] = lastEnd.split('-').map(Number);
  const next = new Date(y, m - 1, d + 1);
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
}

// v2.5.3 (UI-04) — Der Vertrag, den `payload` ab seinem Beginn ablöst: gleicher
// Zähler, früher begonnen, am neuen Beginn noch gültig. Schattenverträge
// zählen nicht, sie rechnen nirgends mit.
function supersededContract(contracts, payload, ownId) {
  return contracts.find(c =>
    c.id !== ownId && !c.is_shadow && targetOf(c) === targetOf(payload)
    && (c.start || '') < payload.start
    && (!c.end || c.end >= payload.start));
}

// Fragt nach, bevor ein Vertrag einen laufenden ablöst. Beim Bearbeiten nur,
// wenn sich Beginn oder Zähler geändert haben — eine bestehende Überlappung
// ist schon entschieden.
async function confirmSupersede(contracts, payload, existing) {
  if (existing && existing.start === payload.start && targetOf(existing) === targetOf(payload)) return true;
  const other = supersededContract(contracts, payload, existing?.id);
  if (!other) return true;
  const name = [other.provider, other.tariff_name].filter(Boolean).join(' · ')
    || t('contracts.card.fallbackProvider');
  return confirmModal({
    title: t('contracts.modal.supersedeTitle'),
    message: t('contracts.modal.supersedeMessage', { date: fmt.date(payload.start), name }),
    confirmLabel: t('contracts.modal.supersedeOk'),
  });
}

// v2.5.3 (UI-04) — Pflichtangaben, die das Backend nicht verlangt: Ein Vertrag
// ohne Namen und ohne Arbeitspreis wurde gespeichert, als „aktiv" geführt und
// kippte Saldo und Prognose. Liefert das erste fehlerhafte Feld oder null.
function checkContractBasics(form, { workingRows, workingMsg, workingLabel, dateOf, amountOf }) {
  const provider = form.querySelector('[name="provider"]');
  const tariff   = form.querySelector('[name="tariff_name"]');
  const nameMsg  = form.querySelector('[data-role="name-msg"]');
  const start    = form.querySelector('[name="start"]');
  const end      = form.querySelector('[name="end"]');
  const startMsg = form.querySelector('[data-role="start-msg"]');

  showFieldError(provider, nameMsg, null);
  tariff?.classList.remove('invalid');
  showFieldError(start, startMsg, null);
  end?.classList.remove('invalid');
  showFieldError(null, workingMsg, null);

  if (!provider?.value.trim() && !tariff?.value.trim()) {
    tariff?.classList.add('invalid');
    showFieldError(provider, nameMsg, t('contracts.modal.nameRequired'));
    return provider;
  }
  if (!start?.value) {
    showFieldError(start, startMsg, t('contracts.water.startRequired'));
    return start;
  }
  if (end?.value && end.value < start.value) {
    end.classList.add('invalid');
    showFieldError(null, startMsg, t('errors.contract.endBeforeStart'));
    end.focus();
    return end;
  }
  const complete = workingRows.some(row => dateOf(row) !== '' && parseDecimal(amountOf(row)) !== null);
  if (!complete) {
    const first = workingRows[0];
    showFieldError(null, workingMsg, t('contracts.modal.workingPriceRequired', { group: workingLabel }));
    first?.querySelectorAll('input').forEach(i => i.classList.add('invalid'));
    first?.querySelector('input')?.focus();
    return first || workingMsg;
  }
  return null;
}

// v3.4.0 (N1021) — Der Status kommt aus der Phase des Servers (phasePill in
// lib/contract-order.js). Bis v3.3 leitete diese Ansicht ihn aus Beginn und
// Ende ab und kannte keine Verlängerung: Ein abgelaufener, weiterlaufender
// Vertrag hieß hier „VERGANGEN“, auf der Seite der Verbrauchsart „VERLÄNGERT“.
// Angebote (Schattenverträge) rechnen nirgends mit und stehen am Ende.

/** Eine Gruppe der Vertragsliste; „Beendet“ zum Aufklappen. */
function renderContractGroup(g, meters, u, groups, docNames = new Map()) {
  const cards = g.items.map(c => renderContractCard(c, meters, u, groups, docNames)).join('');
  if (g.group === 'past') {
    return `<details class="contract-group" data-phase-group="past"${g.collapsed ? '' : ' open'}>
      <summary class="contract-group__title">${escapeHtml(tp('contracts.phaseGroup.past', g.items.length))}</summary>
      ${cards}
    </details>`;
  }
  const intro = g.group === 'shadow'
    ? `<p class="muted small">${escapeHtml(t('contracts.phaseGroup.shadowHint'))} <a href="#/tariffs">${escapeHtml(t('contracts.phaseGroup.toTariffs'))}</a></p>` : '';
  return `<section class="contract-group" data-phase-group="${g.group}">
      <h2 class="contract-group__title">${escapeHtml(t('contracts.phaseGroup.' + g.group))}</h2>
      ${intro}${cards}
    </section>`;
}

// v3.4.0 (N1021) — ct mit zwei Stellen, wenn der Preis so gepflegt ist, sonst vier
const ctValue = (v) => fmt.num(v, Math.abs(v * 100 - Math.round(v * 100)) < 1e-6 ? 2 : 4);

/**
 * v3.4.0 (N1021) — was die Karte nennt: Arbeitspreis, Grundpreis, Abschlag am
 * Stichtag des Servers (`values_on`: heute, beim beendeten Vertrag sein letzter
 * Tag, beim künftigen sein erster). Bis v3.3 standen hier nur Zählungen
 * („1 Arbeitspreis · 1 Grundpreis …“) — die gibt es jetzt ab „Experte“.
 */
function contractValuesHtml(c, u) {
  const v = c.values_on || {};
  const unit = u.key === 'wasser' ? 'm³' : (u.consumption_unit || 'kWh');
  const parts = [
    v.working_price_ct != null ? t(u.accounting_kind === 'feed_in' ? 'contracts.card.feedInValue' : 'contracts.card.workingValue',
      { value: ctValue(v.working_price_ct), unit }) : null,
    v.base_price_eur != null ? t('contracts.card.baseValue', { value: fmt.num(v.base_price_eur, 2) }) : null,
    // v3.9.0 (F1034) — mit Rhythmus („165,00 € alle 2 Monate“), monatlich wie bisher
    v.advance_eur != null ? (Number(v.advance_every_months) > 1
      ? t('contracts.card.advanceValueEvery', { value: advanceAmountText(v.advance_eur, v.advance_every_months) })
      : t('contracts.card.advanceValue', { value: fmt.eur(v.advance_eur) })) : null,
  ].filter(Boolean);
  if (!parts.length) return `<span class="muted">${escapeHtml(t('contracts.card.noValues'))}</span>`;
  const on = v.date && v.date !== todayIso() ? ` <span class="muted">(${escapeHtml(t('contracts.card.valuesOn', { date: fmt.date(v.date) }))})</span>` : '';
  return `<strong>${parts.map(escapeHtml).join(' · ')}</strong>${on}`;
}

/** v3.7.0 (#35) — die Boni des Vertrags mit Art, Datum und Notiz (bis v3.6 nur ihre Zahl ab „Experte“). */
function bonusCardHtml(c) {
  const lines = bonusDetailLines(c.bonuses);
  if (!lines.length) return '';
  return `<p class="muted small contract-card__bonuses" data-role="contract-bonuses" data-min-level="advanced">${escapeHtml(lines.join(' · '))}</p>`;
}

/**
 * v3.7.0 — die Belege am Vertrag (`attachment_ids`, etwa aus dem Posteingang
 * übernommen) mit Dateinamen und Link zum Öffnen. Bis dahin stand ein
 * übernommenes Dokument am Vertrag, ohne dass die Karte es zeigte.
 */
function documentsCardHtml(c, docNames) {
  if (!attachmentIdsOf(c).length) return '';
  return `<p class="muted small contract-card__documents" data-role="contract-documents">${escapeHtml(t('attachments.label'))} ${attachmentLinksHtml(c, docNames)}</p>`;
}

/** v3.9.0 (Skeptiker N7) — Zähler und Verträge der Liste, für den Rest-Hinweis an der Karte. */
let restCtx = { meters: [], contracts: [] };

/** „Rechnet nur den Rest: „Hauszähler“ minus „Wärmepumpe“.“ am Elternvertrag (kein Angebot). */
function restHintHtml(c, u, meter, group) {
  if (c.is_shadow) return '';
  const subs = deductingSubNames({ meter_id: c.meter_id || null, meter_group_id: c.meter_group_id || null }, restCtx.meters, restCtx.contracts, u.key);
  if (!subs.length) return '';
  const parent = group?.name || meter?.name || '';
  return `<p class="muted small" data-role="contract-rest-hint">${escapeHtml(t('concept.contracts.restNote', { parent, sub: subs.join(', ') }))}</p>`;
}

function renderContractCard(c, meters, u, groups = [], docNames = new Map()) {
  const meter = meters.find(m => m.id === c.meter_id);
  const group = c.meter_group_id ? groups.find(g => g.id === c.meter_group_id) : null;
  let summary;
  // v3.5.0 (GitHub #34) — die Zahl der Sonderzahlungen auch bei Wasser
  const special = hasSpecialPayments(u)
    ? [tp('contracts.summary.specialPayments', c.special_payments?.length || 0)]
    : [];
  if (u.key === 'wasser') {
    const tw = c.trinkwasser || {};
    const sw = c.schmutzwasser || {};
    const nw = c.niederschlagswasser || {};
    // v2.12.0 (Review UI-20) — Pluralformen: vorher „1 Arbeitspreise"
    summary = [
      tp('contracts.summary.twPrices', tw.working_prices?.length || 0),
      tp('contracts.summary.swPrices', sw.working_prices?.length || 0),
      tp('contracts.summary.nwDates',  nw.rates?.length || 0),
      tp('contracts.summary.advances', c.advance_payments?.length || 0),
      tp('contracts.summary.bonuses',  c.bonuses?.length || 0),
      ...special,
    ].join(' · ');
  } else {
    summary = [
      tp('contracts.summary.workingPrices', c.working_prices?.length || 0),
      tp('contracts.summary.basePrices',    c.base_prices?.length || 0),
      tp('contracts.summary.advances',      c.advance_payments?.length || 0),
      tp('contracts.summary.bonuses',       c.bonuses?.length || 0),
      ...special,
    ].join(' · ');
  }
  const yrs = yearsOf(c);
  return `
    <div class="card contract-card" style="margin-top: var(--sp-3)" data-utility="${u.key}" data-contract-id="${escapeHtml(c.id)}">
      <div class="section-head">
        <h3 style="margin:0;font-size:var(--fs-lg)">${escapeHtml(c.provider || t('contracts.card.fallbackProvider'))}${c.tariff_name ? ' · ' + escapeHtml(c.tariff_name) : ''}
          ${phasePill(c)}
          <span class="contract-card__years muted">${escapeHtml(yrs.since ? t('contracts.card.since', { year: yrs.since }) : yrs.span)}</span>${chipHtml(c)}</h3>
        <div class="section-actions">
          <button class="btn btn--sm btn--ghost" data-edit-contract="${escapeHtml(c.id)}">${t('contracts.card.edit')}</button>
          ${u.key !== 'wasser' && u.accounting_kind !== 'feed_in' && !c.is_shadow ? `<label class="btn btn--sm btn--ghost" data-min-level="expert">${t('contracts.priceImport.button')}
            <input type="file" accept=".csv,text/csv,text/plain" class="sr-only" data-import-prices="${escapeHtml(c.id)}"></label>` : ''}
          <button class="btn btn--sm btn--danger btn--quiet" data-delete-contract="${escapeHtml(c.id)}" title="${t('contracts.deleteContract')}" aria-label="${t('contracts.deleteContract')}"><span aria-hidden="true">×</span></button>
        </div>
      </div>
      <p class="contract-card__values" style="margin:var(--sp-1) 0">${contractValuesHtml(c, u)}</p>
      <div class="muted" style="font-size: var(--fs-sm)">
        ${fmt.date(c.start)} – ${c.end ? fmt.date(c.end) : t('contracts.card.open')}
        · ${c.meter_group_id
          ? `${t('contracts.card.groupLabel')} <strong>${escapeHtml(group?.name || c.meter_group_id)}</strong>`
          : `${t('contracts.card.meterLabel')} <strong>${escapeHtml(meter?.name || t('contracts.card.noMeter'))}</strong>`}
        <span class="contract-card__counts" data-min-level="expert">· ${summary}</span>
      </div>
      ${restHintHtml(c, u, meter, group)}
      ${bonusCardHtml(c)}
      ${documentsCardHtml(c, docNames)}
      ${c.notes ?`<p style="margin-top:var(--sp-2)">${escapeHtml(c.notes)}</p>` : ''}
      ${c.is_shadow ? '' : dossierHtml({ utility: u.key, contractId: c.id })}
    </div>
  `;
}

// ───── Contract modal (F4) ──────────────────────────────────────────
// v2.7.0 — Gaspreise je m³ (Italien €/Smc, Niederlande €/m³) in den
// Arbeitspreis je kWh umrechnen. Gesetzt, solange ein Vertrag einer Art
// bearbeitet wird, die in m³ misst und in kWh abrechnet (Gas).
let perM3Ctx = null;

async function openContractModal(u, meters, existing, contracts = [], groups = []) {
  perM3Ctx = null;
  if (u?.unit === 'm³' && u?.consumption_unit === 'kWh') {
    const s = await getSettings().catch(() => null);
    perM3Ctx = { factors: s?.gas_conversion_factors || [], cvUnit: s?.gas_cv_unit };
  }
  const people = await api.people().catch(() => []);   // v3.7.0 — Auswahl „Zuständig“
  return new Promise(resolve => {
    const isEdit = !!existing;
    // v2.12.0 (Review UI-16) — die ersten Preiszeilen gelten ab Vertragsbeginn;
    // vorher war ihr Datum leer und musste je Zeile nachgetragen werden
    const start0 = existing ? '' : suggestStart(contracts, meters[0]?.id || '');
    const initial = existing || {
      meter_id: meters[0]?.id || '',
      provider: '', tariff_name: '',
      start: start0, end: '', notes: '',
      working_prices: [{ from: start0, ct_per_kwh: '', __auto: true }],
      base_prices:    [{ from: start0, eur_per_month: '', __auto: true }],
      advance_payments:[{ from: start0, amount_eur: '', __auto: true }],
      bonuses:        [],
      special_payments: [],
    };

    // v3.1.0 (H6, #17) — Gruppen mit Mitgliedern als Vertragsziel (Gas, Strom, Fernwärme)
    const targetGroups = hasAdvancePaymentContracts(u) ? groups.filter(g => meters.some(m => m.meter_group_id === g.id)) : [];
    const body = document.createElement('div');
    // v3.4.0 (N1021) — ein Raster, Gruppen in fester Reihenfolge: Vertrag →
    // Laufzeit & Kündigung → Preise → Netzentgelt § 14a (Modul 1, Modul 3) →
    // Boni → Sonderzahlungen → Erlöse → Notizen. Bis v3.3 standen die
    // Notizen zwischen Kopf und Preisen, fünf Spaltensysteme übereinander.
    const priceGroups = groupsFor(u).filter(g => g.key !== GRID_GROUP.key);
    body.innerHTML = `
      <form id="contract-form">
        <fieldset class="fgroup" data-section="contract">
          <legend>${t('contracts.modal.section.contract')}</legend>
          <div class="fgrid">
            <div class="field field--m">
              <label>${t('contracts.modal.provider')}</label>
              <input class="input input--text" name="provider" value="${escapeHtml(initial.provider || '')}">
              <div class="field-error" data-role="name-msg" role="alert" hidden></div>
            </div>
            <div class="field field--m">
              <label>${t('contracts.modal.tariff')}</label>
              <input class="input input--text" name="tariff_name" value="${escapeHtml(initial.tariff_name || '')}">
            </div>
            <div class="field field--m">
              <label>${t('contracts.modal.meter')}</label>
              <select class="select" name="meter_id">
                ${meters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === initial.meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
                ${targetGroups.length ? `<optgroup label="${escapeHtml(t('contracts.modal.groupTargets'))}">${targetGroups.map(g =>
                  `<option value="g:${escapeHtml(g.id)}" ${g.id === initial.meter_group_id ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}</optgroup>` : ''}
              </select>
            </div>
            <!-- v3.9.0 (F1032, #40) — Vertrag an einem Subzähler: Was sich am Elternzähler ändert -->
            <p class="fgroup__hint field--full" data-role="sub-contract-hint" hidden></p>
            ${personSelectHtml({ id: 'cf-assignee', record: existing, people, hintKey: 'assignee.hintContract' })}
          </div>
        </fieldset>

        <fieldset class="fgroup" data-section="term">
          <legend>${t('contracts.modal.section.term')}</legend>
          <div class="fgrid">
            <div class="field field--s">
              <label>${t('contracts.modal.start')}</label>
              <input class="input" type="date" name="start" required value="${escapeHtml(initial.start || '')}">
              <div class="field-error" data-role="start-msg" role="alert" hidden></div>
            </div>
            <div class="field field--s">
              <label>${t('contracts.modal.end')}</label>
              <input class="input" type="date" name="end" value="${escapeHtml(initial.end || '')}">
            </div>
            <!-- v2.3.1 — Wechselplanung. Diese Felder speisen den Tarifvergleich:
                 Ohne Kündigungsfrist kann er keinen Wechseltermin errechnen und
                 nicht vor ablaufenden Fristen warnen. Alle optional.
                 v3.2.0 (F1019) — ab „Erfahren“; ausgeblendet bleiben die Werte im Formular
                 v3.7.0 (Paket reifegrad) — die Kündigungsfrist für alle: Aus ihr entsteht
                 „Kündigen bis …“ in „Zu tun“, das auch Einsteiger sehen, und der Posteingang
                 trägt sie aus der Vertragsbestätigung ein -->
            <div class="field field--s" data-min-level="advanced">
              <label>${t('contracts.modal.priceGuarantee')}</label>
              <input class="input" type="date" name="price_guarantee_until"
                     value="${escapeHtml(initial.price_guarantee_until || '')}">
              <span class="settings-field__hint">${t('contracts.modal.priceGuaranteeHint')}</span>
            </div>
            <div class="field field--m field--new" data-role="notice">
              <label for="cf-notice">${t('contracts.modal.noticePeriod')}</label>
              <!-- v2.9.0 (CALC-11) — Frist in Monaten, Wochen oder Tagen -->
              <div class="input-pair">
                <input class="input" id="cf-notice" type="text" inputmode="numeric" name="notice_value"
                       value="${escapeHtml(noticeInitial(initial).value)}"
                       placeholder="${escapeHtml(t('contracts.modal.noticePeriodPlaceholder'))}">
                <select class="input" name="notice_unit" aria-label="${escapeHtml(t('contracts.modal.noticeUnitLabel'))}">
                  ${['months', 'weeks', 'days'].map(un => `<option value="${un}" ${noticeInitial(initial).unit === un ? 'selected' : ''}>${t('contracts.modal.noticeUnit.' + un)}</option>`).join('')}
                </select>
              </div>
              <span class="settings-field__hint">${t('contracts.modal.noticePeriodHint')}</span>
              <div class="field-error" data-role="notice-msg" role="alert" hidden></div>
            </div>
            <div class="field field--m" data-min-level="advanced">
              <label>${t('contracts.modal.noticeMode')}</label>
              <select class="input" name="notice_mode">
                ${['', 'term_end', 'month_end', 'any_day'].map(mo => `<option value="${mo}" ${(initial.notice_mode || '') === mo ? 'selected' : ''}>${t('contracts.modal.noticeModeOption.' + (mo || 'auto'))}</option>`).join('')}
              </select>
              <span class="settings-field__hint">${t('contracts.modal.noticeModeHint')}</span>
            </div>
            <div class="field field--s" data-min-level="advanced">
              <label>${t('contracts.modal.minTermEnd')}</label>
              <input class="input" type="date" name="min_term_end"
                     value="${escapeHtml(initial.min_term_end || '')}">
              <span class="settings-field__hint">${t('contracts.modal.minTermEndHint')}</span>
            </div>
            <div class="field field--full" data-min-level="advanced">
              <label class="check">
                <input type="checkbox" name="auto_renews" ${initial.auto_renews === false ? '' : 'checked'}>
                ${t('contracts.modal.autoRenews')}
              </label>
              <span class="settings-field__hint">${t('contracts.modal.autoRenewsHint')}</span>
            </div>
          </div>
        </fieldset>

        ${priceGroups.map(g => renderGroupSection(g, initial[g.key] || [])).join('')}
        <div data-role="member-prices" data-min-level="expert">${memberPricesHtml(initial.meter_group_id, meters, initial.working_prices_by_meter)}</div>
        ${u?.key === 'fernwaerme' ? `
        <!-- v3.7.0 — die Anschlussleistung für alle (sie trägt den Leistungspreis), die Kennwerte ab Experte -->
        <fieldset class="fgroup" data-role="fw-plant"><legend>${t('contracts.fw.title')}</legend>
          <p class="fgroup__hint">${t('contracts.fw.hint')}</p>
          <div class="fgrid">
            <div class="field field--m"><label>${t('contracts.fw.capacityKw')}</label>
              <input class="input" name="capacity_kw" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(initial.capacity_kw))}"></div>
            <div class="field field--m" data-role="fw-factors" data-min-level="expert"><label>${t('contracts.fw.co2Factor')}</label>
              <input class="input" name="co2_g_per_kwh" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(initial.co2_g_per_kwh))}"></div>
            <div class="field field--m" data-min-level="expert"><label>${t('contracts.fw.primaryEnergy')}</label>
              <input class="input" name="primary_energy_factor" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(initial.primary_energy_factor))}"></div>
          </div>
        </fieldset>` : ''}

        ${u?.key === 'strom' ? renderGroupSection(GRID_GROUP, initial[GRID_GROUP.key] || []) + gridTouHtml(initial.grid_tou) : ''}

        ${renderBonusSection(initial.bonuses || [])}

        ${hasSpecialPayments(u) ? renderSpecialPaymentSection(initial.special_payments || []) : ''}
        ${u?.accounting_kind === 'feed_in' ? renderRevenueSection(initial.revenue_statements || []) : ''}

        <div class="fgrid">
          <div class="field field--full">
            <label>${t('contracts.modal.notes')}</label>
            <textarea class="input input--text" name="notes">${escapeHtml(initial.notes || '')}</textarea>
          </div>
        </div>
      </form>
    `;

    openModal({
      title: isEdit ? t('contracts.modal.titleEdit') : t('contracts.modal.titleNew'),
      body,
      size: 'lg',
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--util" data-act="save">${t('contracts.modal.save')}</button>
      `,
      onMount({ modalEl, close }) {
        // Cancel/Save ZUERST binden — selbst wenn die Sub-Handler unten
        // wegen ungewöhnlicher Daten (z.B. migriertes v0.9.0-Format)
        // eine Exception werfen, bleiben die Fußleisten-Buttons funktional.
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => { close(false); resolve(false); });
        const saveBtn = modalEl.querySelector('[data-act="save"]');
        saveBtn?.addEventListener('click', guardSubmit(saveBtn, async () => {
          const f = modalEl.querySelector('#contract-form');
          const workingGroup = f.querySelector('[data-group="working_prices"]');
          const invalid = checkContractBasics(f, {
            workingRows: [...(workingGroup?.querySelectorAll('.entry-row') || [])],
            workingMsg:  workingGroup?.querySelector('[data-role="group-msg"]'),
            workingLabel: t('contracts.group.working'),
            dateOf:   row => row.querySelector('[data-role="date"]')?.value.trim() || '',
            amountOf: row => row.querySelector('[data-role="amount"]')?.value || '',
          });
          if (invalid) return;
          if (!validateAllGroups(modalEl)) {
            toastErr(t('contracts.modal.validationHalfRows'));
            return;
          }
          const noticeEl  = f.querySelector('[name="notice_value"]');
          const noticeRaw = noticeEl?.value.trim() || '';
          const unit      = f.querySelector('[name="notice_unit"]')?.value || 'months';
          const noticeN   = Number(noticeRaw);
          const noticeOk  = noticeRaw === '' || (/^\d{1,3}$/.test(noticeRaw)
            && (unit === 'months' ? noticeN <= 24 : (unit === 'weeks' ? noticeN * 7 : noticeN) <= 730));
          showFieldError(noticeEl, f.querySelector('[data-role="notice-msg"]'),
            noticeOk ? null : t(unit === 'months' ? 'errors.contract.noticeOutOfRange' : 'errors.contract.noticeDaysOutOfRange'));
          if (!noticeOk) return;

          const payload = { ...collectPayload(f, u), ...personPayload(f.querySelector('[name="assignee_id"]'), existing) };   // v3.7.0
          if (!await confirmSupersede(contracts, payload, existing)) return;
          try {
            const saved = isEdit ? await api.updateContract(u.key, existing.id, payload) : await api.createContract(u.key, payload);
            toastOk(t('contracts.modal.saved'));
            // v3.1.0 (H5, MKT-24) — Hinweise des Servers (nicht gespeichert)
            if ((saved?.warnings || []).includes('term_over_24_months')) toastErr(t('contracts.hint.termOver24'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        }));

        // Vorbelegten Beginn beim Zählerwechsel mitziehen, solange niemand
        // das Datum selbst angefasst hat.
        if (!isEdit) {
          const startEl = modalEl.querySelector('[name="start"]');
          // v2.12.0 — vorbelegte Preiszeilen (data-auto) folgen dem Beginn;
          // eine selbst geänderte Zeile bleibt, wie sie ist
          const followStart = () => {
            modalEl.querySelectorAll('.entry-row [data-role="date"][data-auto="1"]').forEach(d => {
              d.value = startEl?.value || '';
            });
          };
          startEl?.addEventListener('input', () => { startEl.dataset.touched = '1'; }, { once: true });
          startEl?.addEventListener('change', followStart);
          modalEl.querySelector('[name="meter_id"]')?.addEventListener('change', (ev) => {
            if (startEl && !startEl.dataset.touched) { startEl.value = suggestStart(contracts, ev.target.value); followStart(); }
          });
        }

        try { bindEntryGroupHandlers(modalEl); }
        catch (e) { console.warn('contract modal: entry-group binding failed:', e); }
        // v3.1.0 (H6, #17) — Gruppe gewählt: Arbeitspreise je Mitglied anbieten
        const targetSel = modalEl.querySelector('[name="meter_id"]');
        // v3.9.0 (F1032, #40) — Subzähler (oder Gruppe aus Subzählern) gewählt: ein Satz zur Rest-Abrechnung
        const subHint = modalEl.querySelector('[data-role="sub-contract-hint"]');
        const showSubHint = () => {
          if (!subHint || !targetSel) return;
          const v = targetSel.value;
          const subs = v.startsWith('g:') ? meters.filter(m => m.meter_group_id === v.slice(2) && m.parent_meter_id)
            : meters.filter(m => m.id === v && m.parent_meter_id);
          const text = subs.map(m => subContractHint(m, meters)).filter(Boolean).join(' ');
          subHint.textContent = text;
          subHint.hidden = !text;
        };
        targetSel?.addEventListener('change', showSubHint);
        showSubHint();
        targetSel?.addEventListener('change', () => {
          const box = modalEl.querySelector('[data-role="member-prices"]');
          if (!box) return;
          box.innerHTML = targetSel.value.startsWith('g:') ? memberPricesHtml(targetSel.value.slice(2), meters, {}) : '';
          bindEntryGroupHandlers(modalEl, box);
          associateFieldLabels(box);
        });
        try { bindPerM3(modalEl); }
        catch (e) { console.warn('contract modal: per-m³ helper failed:', e); }
        try { bindBonusHandlers(modalEl); }
        catch (e) { console.warn('contract modal: bonus binding failed:', e); }
        // v3.1.0 (H7, MKT-16) — Gutschriften des Direktvermarkters
        modalEl.querySelector('[data-action="add-revenue"]')?.addEventListener('click', () => {
          const wrap = document.createElement('div');
          wrap.innerHTML = revenueRowHtml({});
          modalEl.querySelector('[data-role="revenue-rows"]').appendChild(wrap.firstElementChild);
          associateFieldLabels(modalEl.querySelector('[data-role="revenue-rows"]'));
        });
        modalEl.querySelector('[data-role="revenue-rows"]')?.addEventListener('click', ev => {
          ev.target.closest('[data-action="remove-revenue"]')?.closest('.revenue-row')?.remove();
        });
        // v3.3.0 (F1021) — Zeitfenster für § 14a Modul 3
        modalEl.querySelector('[data-action="add-tou"]')?.addEventListener('click', () => {
          const rows = modalEl.querySelector('[data-role="tou-rows"]');
          rows.insertAdjacentHTML('beforeend', gridTouRow({}));
          associateFieldLabels(rows);
        });
        modalEl.querySelector('[data-role="tou-rows"]')?.addEventListener('click', ev => {
          ev.target.closest('[data-action="remove-tou"]')?.closest('.tou-row')?.remove();
        });
        // v3.4.0 (N1021) — Uhrzeit beim Verlassen in HH:MM zeigen („6" → 06:00)
        modalEl.querySelector('[data-role="tou-rows"]')?.addEventListener('focusout', ev => {
          const el = ev.target.closest?.('[data-role="tou-from"], [data-role="tou-to"]');
          if (el) el.value = normalizeTime(el.value);
        });
        try { bindSpecialPaymentHandlers(modalEl); }
        catch (e) { console.warn('contract modal: special-payment binding failed:', e); }
        // A11y: alle Formularfelder mit ihren Labels verknüpfen.
        associateFieldLabels(modalEl);
      }
    });
  });
}

// ───── v3.3.0 (F1021) — § 14a Modul 3: zeitvariables Netzentgelt ─────
// v3.4.0 (N1021) — Zeile im Listenraster: von | bis | ct | Quartale | ✕.
// „von" und „bis" sind beide Textfelder (bis v3.3 war „von" ein
// Uhrzeitfeld, „bis" ein Textfeld, weil 24:00 kein Uhrzeitwert ist).
function gridTouRow(w = {}) {
  const q = new Set(w.quarters || [1, 2, 3, 4]);
  const time = (role, v) => `<input class="input" data-role="${role}" type="text" inputmode="decimal" autocomplete="off" maxlength="5" placeholder="HH:MM" value="${escapeHtml(v || '')}">`;
  return `<div class="rowlist__row tou-row">
    <div class="field"><label>${t('contracts.gridTou.from')}</label>${time('tou-from', w.from)}</div>
    <div class="field"><label>${t('contracts.gridTou.to')}</label>${time('tou-to', w.to)}</div>
    <div class="field"><label>${t('contracts.gridTou.ct')}</label><input class="input" data-role="tou-ct" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(w.ct))}"></div>
    <div class="field"><span class="label">${t('contracts.gridTou.quarters')}</span>
      <span class="tou-quarters">${[1, 2, 3, 4].map(n => `<label class="check"><input type="checkbox" data-q="${n}" ${q.has(n) ? 'checked' : ''}> Q${n}</label>`).join('')}</span></div>
    <button type="button" class="btn btn--danger btn--icon rowlist__del" data-action="remove-tou" title="${escapeHtml(t('contracts.gridTou.removeWindow'))}" aria-label="${escapeHtml(t('contracts.gridTou.removeWindow'))}"><span aria-hidden="true">×</span></button>
  </div>`;
}

function gridTouHtml(tou) {
  return `<fieldset class="fgroup" data-role="grid-tou" data-min-level="expert">
    <legend>${t('contracts.gridTou.title')}</legend>
    <button type="button" class="btn btn--sm btn--ghost fgroup__add" data-action="add-tou">${t('contracts.gridTou.addWindow')}</button>
    <p class="fgroup__hint">${escapeHtml(t('contracts.gridTou.hint'))}</p>
    <div class="fgrid">
      <div class="field field--s"><label>${t('contracts.gridTou.standard')}</label>
        <input class="input" data-role="tou-standard" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(tou?.standard_ct))}"></div>
      <!-- v3.7.0 — Beträge ohne USt (wie auf dem Preisblatt des Netzbetreibers); Standard: wie eingetragen (brutto) -->
      <div class="field field--m">
        <label class="check"><input type="checkbox" data-role="tou-net" ${tou?.net ? 'checked' : ''}> ${t('contracts.gridTou.net')}</label>
        <span class="settings-field__hint">${escapeHtml(t('contracts.gridTou.netHint'))}</span>
      </div>
    </div>
    <div class="rowlist rowlist--tou" data-role="tou-rows">${(tou?.windows || []).map(gridTouRow).join('')}</div>
  </fieldset>`;
}

/** Zeitfenster aus dem Formular; ohne Standardpreis und Fenster: null (= keins). */
function collectGridTou(form) {
  const box = form.querySelector('[data-role="grid-tou"]');
  if (!box) return undefined;
  const standard = box.querySelector('[data-role="tou-standard"]').value.trim();
  const windows = [...box.querySelectorAll('.tou-row')].map(r => ({
    quarters: [...r.querySelectorAll('[data-q]:checked')].map(c => Number(c.dataset.q)),
    from: normalizeTime(r.querySelector('[data-role="tou-from"]').value),
    to: normalizeTime(r.querySelector('[data-role="tou-to"]').value),
    ct: amountValue(r.querySelector('[data-role="tou-ct"]').value.trim()),
  })).filter(w => w.from || w.to || w.ct !== '');
  if (!standard && !windows.length) return null;
  // v3.7.0 — netto: der Server rechnet mit der USt der App auf brutto
  const net = !!box.querySelector('[data-role="tou-net"]')?.checked;
  return { standard_ct: amountValue(standard), windows, ...(net ? { net: true } : {}) };
}

// ───── Group rendering ──────────────────────────────────────────────
// v3.4.0 (N1021) — Preiszeilen wie die Vertragslisten: die neueste oben, „+ Zeile“
// fügt oben ein. Nur die Anzeige; der Server speichert weiter aufsteigend.
const newestFirst = (entries, dateKey) => [...entries].sort((a, b) => String(b?.[dateKey] || '').localeCompare(String(a?.[dateKey] || '')));

// v3.4.0 (N1021) — jede Preisliste ist eine Feldgruppe (Kasten, Legende im
// Kasten, „+ Zeile" rechts im Kopf); `nested` = Liste innerhalb einer Gruppe
// (Preise je Zähler eines Gruppenvertrags) ohne eigenen Kasten.
// v3.7.0 (Qualität) — Die Meldung einer Preisliste („Mindestens ein
// Arbeitspreis …“) hängt per aria-describedby an ihrer Gruppe.
let groupSeq = 0;
function renderGroupSection(g, entries, nested = false) {
  entries = entries.length === 0 ? [{ [g.dateKey]: '', [g.amountKey]: '' }] : newestFirst(entries, g.dateKey);
  const title = g.title ? escapeHtml(g.title) : t(g.titleKey);
  const ids = `grp-${++groupSeq}`;
  // v3.2.0 (F1019) — Preiswechsel ab „Erfahren“; vorhandene Zeilen bleiben sichtbar
  const add = `<button type="button" class="btn btn--sm btn--ghost${nested ? '' : ' fgroup__add'}" data-action="add-row" data-min-level="advanced">${t('contracts.group.addRow')}</button>`;
  const attrs = `data-group="${escapeHtml(g.key)}" data-date-key="${g.dateKey}" data-amount-key="${g.amountKey}"${g.level ? ` data-min-level="${g.level}"` : ''} aria-describedby="${ids}-msg"`;
  const inner = `
      <div class="field-error" id="${ids}-msg" data-role="group-msg" role="alert" hidden></div>
      <div class="entries rowlist${g.key === 'advance_payments' ? ' rowlist--three' : ''}">
        ${entries.map(e => renderEntryRow(g, e, entries)).join('')}
      </div>
      ${g.key === 'working_prices' && perM3Ctx ? gasFactorNoteHtml() + renderPerM3() : ''}`;
  return nested
    ? `<div class="entry-group entry-group--nested" role="group" aria-labelledby="${ids}-title" ${attrs}>
        <div class="entry-group__head"><span class="fgroup__title" id="${ids}-title">${title}</span>${add}</div>${inner}
      </div>`
    : `<fieldset class="fgroup entry-group" ${attrs}>
        <legend>${title}</legend>${add}${inner}
      </fieldset>`;
}

/**
 * v3.7.0 (GitHub #35) — Gas: die heute geltende Umrechnung (Zustandszahl ×
 * Brennwert = kWh/m³, gültig ab) mit Verweis auf die Einstellung. Sie hängt
 * an Entnahmestelle und Netz, nicht am Lieferanten — deshalb steht sie nicht
 * am Vertrag, und das ⓘ sagt warum.
 */
function gasFactorNoteHtml() {
  const e = gasEntryOn(perM3Ctx?.factors || [], todayIso());
  const factor = e ? gasFactorOf(e) : NaN;
  if (!e || !(factor > 0)) return '';
  const z = Number(e.zustandszahl), hs = Number(e.brennwert);
  const unit = cvUnit(perM3Ctx?.cvUnit);
  const value = z > 0 && hs > 0
    ? t('contracts.gasFactor.zhs', { z: fmt.num(z, 4), hs: fmt.num(hs * unit.perKwh, unit.digits), hsUnit: unit.label, factor: fmt.num(factor, 3) })
    : t('contracts.gasFactor.direct', { factor: fmt.num(factor, 3) });
  const since = e.from ? t('contracts.gasFactor.since', { date: fmt.date(e.from) }) : t('contracts.gasFactor.undated');
  return `<p class="muted small gas-factor-note" data-role="gas-factor-note">${escapeHtml(t('contracts.gasFactor.now', { value, since }))}${infoNote(t('contracts.gasFactor.term'), t('contracts.gasFactor.why'))}
    <a href="#/settings/utilities" data-role="gas-factor-link">${escapeHtml(t('contracts.gasFactor.link'))}</a></p>`;
}

function renderPerM3() {
  return `
    <details class="perm3" data-perm3>
      <summary>${t('contracts.perM3.toggleTitle')}</summary>
      <div class="fgrid">
        <div class="field field--s">
          <label for="perm3-price">${t('contracts.perM3.label')}</label>
          <input class="input" id="perm3-price" type="text" inputmode="decimal" autocomplete="off">
        </div>
        <div class="field field--s">
          <label for="perm3-date">${t('contracts.row.validFrom')}</label>
          <input class="input" id="perm3-date" type="date">
        </div>
        <div class="field field--s">
          <button type="button" class="btn btn--ghost" data-action="perm3-apply" disabled>${t('contracts.perM3.apply')}</button>
        </div>
      </div>
      <p class="muted perm3__result" data-perm3-result aria-live="polite"></p>
    </details>`;
}

/**
 * Geteilt wird durch den Brennwert, der am gewählten Tag gilt: Ein Preis je
 * Normkubikmeter (Smc) bezieht sich auf das bereits korrigierte Volumen, die
 * Zustandszahl gehört nicht in den Nenner. Ist nur ein direkter Faktor
 * hinterlegt, teilt die Hilfe durch ihn und sagt das dazu.
 */
function bindPerM3(modalEl) {
  const box = modalEl.querySelector('[data-perm3]');
  if (!box || !perM3Ctx) return;
  const priceEl  = box.querySelector('#perm3-price');
  const dateEl   = box.querySelector('#perm3-date');
  const applyBtn = box.querySelector('[data-action="perm3-apply"]');
  const out      = box.querySelector('[data-perm3-result]');
  let ct = null;
  const update = () => {
    const price = parseDecimal(priceEl.value);
    const date  = dateEl.value || modalEl.querySelector('input[name="start"]')?.value || todayIso();
    const e     = gasEntryOn(perM3Ctx.factors, date);
    const byHs  = Number(e?.brennwert) > 0;
    const div   = byHs ? Number(e.brennwert) : Number(e?.kwh_per_m3);
    ct = price != null && price > 0 && div > 0 ? price * 100 / div : null;
    applyBtn.disabled = ct === null;
    out.textContent = ct === null ? '' : t(byHs ? 'contracts.perM3.result' : 'contracts.perM3.resultFactor', {
      ct: fmt.num(ct, 4), hs: fmt.num(div, 3), date: fmt.date(date),
    });
  };
  priceEl?.addEventListener('input', update);
  dateEl?.addEventListener('input', update);
  applyBtn?.addEventListener('click', () => {
    if (ct === null) return;
    // Ziel: die Zeile mit demselben Datum, sonst die erste ohne Betrag, sonst eine neue
    const group = modalEl.querySelector('[data-group="working_prices"]');
    const rowsOf = () => [...(group?.querySelectorAll('.entry-row') || [])];
    const date = dateEl.value;
    let row = (date && rowsOf().find(r => r.querySelector('[data-role="date"]')?.value === date))
      || rowsOf().find(r => !r.querySelector('[data-role="amount"]')?.value.trim());
    if (!row) {
      group?.querySelector('[data-action="add-row"]')?.click();
      row = rowsOf()[0];   // v3.4.0 — neue Zeilen stehen oben
    }
    if (!row) return;
    const dIn = row.querySelector('[data-role="date"]');
    const aIn = row.querySelector('[data-role="amount"]');
    // v2.12.0 — ein nur vorbelegtes Datum (Vertragsbeginn) weicht dem gewählten
    if (date && dIn && (!dIn.value || dIn.dataset.auto === '1')) { dIn.value = date; delete dIn.dataset.auto; }
    if (aIn) aIn.value = formatForInput(Number(ct.toFixed(4)), 4);
    validateRow(row);
    aIn?.focus();
  });
}

// v2.5.3 — Beträge als Text mit Dezimaltastatur: `type="number"` verwarf
// „32,5" je nach Browser still zu "" (FE-02). Gelesen wird mit parseDecimal.
function amountInput(role, value, digits = 6) {
  return `<input class="input" type="text" inputmode="decimal" autocomplete="off" data-role="${role}" value="${escapeHtml(formatForInput(value, digits))}">`;
}

// v3.4.0 (N1021) — Zeile im Listenraster: Gültig ab | Betrag | „Ab Beginn" | ✕;
// die Beschriftung zeigt nur die erste Zeile (CSS), die übrigen tragen sie
// für Screenreader weiter
function renderEntryRow(g, e, entries = []) {
  // v3.9.0 (F1034) — Abschlag mit Rhythmus: monatlich, alle zwei oder drei Monate; genannte Termine bleiben erhalten
  const advance = g.key === 'advance_payments';
  const every = Number(e.every_months) || 1;
  return `
    <div class="entry-row rowlist__row">
      <div class="field">
        <label>${t('contracts.row.validFrom')}</label>
        <input class="input" type="date" data-role="date" value="${escapeHtml(e[g.dateKey] || '')}"${e.__auto ? ' data-auto="1"' : ''}>
      </div>
      <div class="field">
        <label data-role="amount-label">${escapeHtml(t(advance && every > 1 ? 'contracts.unit.eurPerDue' : g.amountKey_))}</label>
        ${amountInput('amount', e[g.amountKey])}
      </div>
      ${advance ? advanceEveryHtml(every) : ''}
      <button type="button" class="btn btn--sm btn--ghost" data-action="copy-start" title="${t('contracts.row.copyStartTitle')}">${t('contracts.row.copyStart')}</button>
      <button type="button" class="btn btn--sm btn--danger btn--icon rowlist__del" data-action="remove-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button>
      ${advance ? advanceDueHtml(e.due_dates) : ''}
      ${advance ? advanceGapHtml(entries, e) : ''}
    </div>
  `;
}

/** v3.9.0 (F1034) — Auswahl des Rhythmus einer Abschlagszeile. */
function advanceEveryHtml(every) {
  return `<div class="field">
        <label>${escapeHtml(t('contracts.advance.every'))}</label>
        <select class="select" data-role="every">${ADVANCE_EVERY.map(n => `<option value="${n}"${n === every ? ' selected' : ''}>${escapeHtml(tp('advance.rhythm.every', n))}</option>`).join('')}</select>
      </div>`;
}

/** v3.9.0 (F1034) — genannte Termine (etwa aus der Rechnung): bleiben beim Speichern erhalten, sichtbar unter der Zeile. */
function advanceDueHtml(dates) {
  const list = (Array.isArray(dates) ? dates : []).filter(d => /^\d{4}-\d{2}-\d{2}$/.test(String(d)));
  if (!list.length) return '';
  return `<p class="muted small rowlist__sub" data-role="due-note"><input type="hidden" data-role="due" value="${escapeHtml(list.join(','))}">${escapeHtml(t('contracts.advance.dueDates', { dates: list.map(fmt.date).join(', ') }))}</p>`;
}

/** Skeptiker R2-8 — eine Zeile mit 0 €: in diesem Zeitraum ist kein Abschlag eingetragen (sichtbar, nicht still). */
function advanceGapHtml(entries, e) {
  const text = zeroAdvanceText(zeroAdvanceSpan(entries, e));
  return text ? `<p class="muted small rowlist__sub" data-role="gap-note">${escapeHtml(text)}</p>` : '';
}

// v3.1.0 (H6) — `root`: nur einen nachgeladenen Teil binden (Preise je Mitglied).
// Zeilen fügt jetzt jede Liste an, nicht nur die drei Grundlisten (Fernwärme,
// § 14a und Mitgliederpreise kamen sonst ohne Definition an)
function bindEntryGroupHandlers(modalEl, root = modalEl) {
  // Add-row buttons
  root.querySelectorAll('[data-action="add-row"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('[data-group]');
      const gKey  = group.getAttribute('data-group');
      const g = groupDef(gKey);
      if (!g) return;
      const wrap = document.createElement('div');
      wrap.innerHTML = renderEntryRow(g, { [g.dateKey]: '', [g.amountKey]: '' });
      const row = wrap.firstElementChild;
      group.querySelector('.entries').prepend(row);   // v3.4.0 (N1021) — neueste oben
      bindRowHandlers(modalEl, row);
      associateFieldLabels(row);
    });
  });

  // Initial rows
  root.querySelectorAll('.entry-row').forEach(row => bindRowHandlers(modalEl, row));
}

// v3.1.0 (H6, #17) — Arbeitspreis je Zähler der Gruppe (HT/NT), leer = allgemeiner Arbeitspreis
function memberPricesHtml(gid, meters, byMeter = {}) {
  if (!gid) return '';
  const members = meters.filter(m => m.meter_group_id === gid);
  if (!members.length) return '';
  return `<fieldset class="fgroup"><legend>${t('contracts.groupPrices.title')}</legend>
    <p class="fgroup__hint">${t('contracts.groupPrices.hint')}</p>
    ${members.map(m => renderGroupSection(memberGroup(m), (byMeter || {})[m.id] || [], true)).join('')}
  </fieldset>`;
}

function bindRowHandlers(modalEl, row) {
  const dateInput   = row.querySelector('[data-role="date"]');
  const amountInput = row.querySelector('[data-role="amount"]');
  const copyBtn     = row.querySelector('[data-action="copy-start"]');
  const removeBtn   = row.querySelector('[data-action="remove-row"]');

  // Selbst gesetztes Datum: folgt nicht mehr dem Vertragsbeginn und zählt als Eingabe
  dateInput?.addEventListener('input', () => { delete dateInput.dataset.auto; });
  copyBtn?.addEventListener('click', () => {
    const start = modalEl.querySelector('input[name="start"]')?.value;
    if (!start) { toastErr(t('contracts.row.setStartFirst')); return; }
    if (dateInput) dateInput.value = start;
    validateRow(row);
  });

  removeBtn?.addEventListener('click', () => {
    const group = row.closest('[data-group]');
    row.remove();
    // Keep at least one row visible
    if (group && group.querySelectorAll('.entry-row').length === 0) {
      const gKey = group.getAttribute('data-group');
      const g = groupDef(gKey);
      if (!g) return;
      const wrap = document.createElement('div');
      wrap.innerHTML = renderEntryRow(g, { [g.dateKey]: '', [g.amountKey]: '' });
      const newRow = wrap.firstElementChild;
      group.querySelector('.entries')?.appendChild(newRow);
      bindRowHandlers(modalEl, newRow);
      associateFieldLabels(newRow);
    }
  });

  [dateInput, amountInput].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => { validateRow(row); row.querySelector('[data-role="gap-note"]')?.remove(); });
    i.addEventListener('blur',  () => validateRow(row));
  });
  // v3.9.0 (F1034) — der Betrag gilt je Termin, sobald der Abschlag nicht monatlich ist
  const everySel = row.querySelector('[data-role="every"]');
  everySel?.addEventListener('change', () => {
    const label = row.querySelector('[data-role="amount-label"]');
    if (label) label.textContent = t(Number(everySel.value) > 1 ? 'contracts.unit.eurPerDue' : 'contracts.unit.eurPerMonth');
    // ein anderer Rhythmus: die Termine laut Rechnung gehörten zum alten, sie entfallen
    row.querySelector('[data-role="due-note"]')?.remove();
  });
}

function validateRow(row) {
  return validatePair(row.querySelector('[data-role="date"]'), row.querySelector('[data-role="amount"]'));
}

/**
 * Datum und Betrag gehören zusammen: beide leer (Zeile wird übergangen) oder
 * beide gefüllt. v2.5.3 — ein gefüllter, aber unlesbarer Betrag („12,3,4")
 * zählt als Fehler; vorher ging er als NaN → null an das Backend.
 */
function validatePair(dateInput, amountInput) {
  if (!dateInput || !amountInput) return true;
  const amountFilled = amountInput.value.trim() !== '';
  // v2.12.0 — ein nur vorbelegtes Datum ohne Betrag ist eine leere Zeile
  const dateFilled   = dateInput.value.trim() !== '' && !(dateInput.dataset.auto === '1' && !amountFilled);
  const amountBad    = amountFilled && parseDecimal(amountInput.value) === null;
  const halfFilled   = dateFilled !== amountFilled;
  dateInput.classList.toggle('invalid',   halfFilled && !dateFilled);
  amountInput.classList.toggle('invalid', (halfFilled && !amountFilled) || amountBad);
  return !halfFilled && !amountBad;
}

function validateAllGroups(modalEl) {
  let ok = true;
  modalEl.querySelectorAll('.entry-row').forEach(row => {
    if (!validateRow(row)) ok = false;
    // Sonderzahlung: die Abschlagsanpassung ist ein zweites Paar derselben Art.
    if (row.classList.contains('special-row') && !row.querySelector('[data-role="advance-block"]')?.hidden
        && !validatePair(row.querySelector('[data-role="advance-from"]'), row.querySelector('[data-role="new-advance"]'))) {
      ok = false;
    }
  });
  return ok;
}

// ───── Bonus section ────────────────────────────────────────────────
function renderBonusSection(bonuses) {
  if (!bonuses || bonuses.length === 0) bonuses = [];
  return `
    <fieldset class="fgroup entry-group" data-section="bonus" data-min-level="advanced">
      <legend>${t('contracts.bonus.title')}</legend>
      <button type="button" class="btn btn--sm btn--ghost fgroup__add" data-action="add-bonus">${t('contracts.bonus.add')}</button>
      <div class="entries rowlist">
        ${bonuses.map(b => renderBonusRow(b)).join('')}
      </div>
    </fieldset>
  `;
}

// Gutschrift am | Betrag | Art | Notiz | ✕
// v3.7.0 (GitHub #35) — Art „Sonstige Gutschrift“ (`sonstige`) und das Notizfeld
// (`label`). Bis v3.6 schickte das Formular `label: ''` und löschte beim
// Speichern still eine Notiz, die es nicht zeigte (etwa aus den Beispieldaten).
function renderBonusRow(b = {}) {
  const type = b.type || 'sofort';
  const options = BONUS_TYPES.includes(type) ? BONUS_TYPES : [...BONUS_TYPES, type];   // unbekannte Art bleibt erhalten
  return `
    <div class="entry-row bonus-row rowlist__row">
      <div class="field">
        <label>${t('contracts.bonus.creditDate')}</label>
        <input class="input" type="date" data-role="date" value="${escapeHtml(b.credit_date || '')}">
      </div>
      <div class="field">
        <label>${t('contracts.bonus.amount')}</label>
        ${amountInput('amount', b.amount_eur, 2)}
      </div>
      <div class="field">
        <label>${t('contracts.bonus.type')}</label>
        <select class="select" data-role="type">
          ${options.map(o => `<option value="${escapeHtml(o)}" ${o === type ? 'selected' : ''}>${escapeHtml(bonusTypeLabel(o))}</option>`).join('')}
        </select>
      </div>
      <div class="field bonus-row__note">
        <label>${t('contracts.bonus.note')}</label>
        <input class="input input--text" type="text" data-role="note" maxlength="120" value="${escapeHtml(b.label || '')}"
               placeholder="${escapeHtml(t(type === 'sonstige' ? 'contracts.bonus.notePlaceholderOther' : 'contracts.bonus.notePlaceholder'))}">
      </div>
      <button type="button" class="btn btn--sm btn--danger btn--icon rowlist__del" data-action="remove-bonus" title="${t('contracts.bonus.remove')}" aria-label="${t('contracts.bonus.remove')}"><span aria-hidden="true">×</span></button>
    </div>
  `;
}

function bindBonusHandlers(modalEl) {
  const sec = modalEl.querySelector('[data-section="bonus"]');
  if (!sec) return;
  sec.querySelector('[data-action="add-bonus"]')?.addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderBonusRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries')?.appendChild(row);
    bindBonusRow(row);
    associateFieldLabels(row);
  });
  sec.querySelectorAll('.bonus-row').forEach(bindBonusRow);
}

function bindBonusRow(row) {
  row.querySelector('[data-action="remove-bonus"]')?.addEventListener('click', () => row.remove());
  // v3.7.0 (#35) — bei „Sonstige Gutschrift“ sagt die Notiz, wofür
  const typeEl = row.querySelector('[data-role="type"]');
  const noteEl = row.querySelector('[data-role="note"]');
  typeEl?.addEventListener('change', () => {
    if (noteEl) noteEl.placeholder = t(typeEl.value === 'sonstige' ? 'contracts.bonus.notePlaceholderOther' : 'contracts.bonus.notePlaceholder');
  });
  const dateEl   = row.querySelector('[data-role="date"]');
  const amountEl = row.querySelector('[data-role="amount"]');
  [dateEl, amountEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => validatePair(dateEl, amountEl));
  });
}

// ───── F1003 — Sonderzahlungen ──────────────────────────────────────
function renderSpecialPaymentSection(items) {
  if (!Array.isArray(items)) items = [];
  return `
    <fieldset class="fgroup entry-group" data-section="special" data-min-level="advanced">
      <legend>${t('contracts.special.title')}</legend>
      <button type="button" class="btn btn--sm btn--ghost fgroup__add" data-action="add-special">${t('contracts.special.add')}</button>
      <p class="fgroup__hint">${t('contracts.special.hint')}</p>
      <div class="entries rowlist">
        ${items.map(s => renderSpecialPaymentRow(s)).join('')}
      </div>
    </fieldset>
  `;
}

function renderSpecialPaymentRow(s = {}) {
  const kind = s.kind || 'abschlagszahlung';
  const showAdvance = SPECIAL_KIND_AFFECTS_ADVANCE.has(kind);
  // v2.5.3 (UI-11) — Datum | Betrag | Art | ✕, darunter Notiz und
  // Abschlagsanpassung. v3.4.0 (N1021) — im Listenraster wie alle Zeilen;
  // Notiz und Anpassung bleiben in jeder Zeile beschriftet (rowlist__sub).
  return `
    <div class="entry-row special-row rowlist__row" data-kind="${escapeHtml(kind)}">
      <div class="field">
        <label>${t('contracts.special.date')}</label>
        <input class="input" type="date" data-role="date" value="${escapeHtml(s.date || '')}">
      </div>
      <div class="field">
        <label>${t('contracts.special.amount')}</label>
        ${amountInput('amount', s.amount_eur, 2)}
      </div>
      <div class="field special-row__kind">
        <label>${t('contracts.special.kind')}</label>
        <select class="select" data-role="kind">
          ${SPECIAL_PAYMENT_KINDS.map(k =>
            `<option value="${k.value}" ${k.value === kind ? 'selected' : ''}>${t('contracts.kinds.' + k.value)}</option>`
          ).join('')}
        </select>
      </div>
      <div class="field special-row__note rowlist__sub">
        <label>${t('contracts.special.note')}</label>
        <input class="input input--text" data-role="note" value="${escapeHtml(s.note || '')}">
      </div>
      <div class="special-advance rowlist__sub" data-role="advance-block" ${showAdvance ? '' : 'hidden'}>
        <div class="field">
          <label>${t('contracts.special.newAdvance')}</label>
          ${amountInput('new-advance', s.new_advance_eur, 2)}
        </div>
        <div class="field">
          <label>${t('contracts.special.advanceFrom')}</label>
          <input class="input" type="date" data-role="advance-from" value="${escapeHtml(s.advance_from || '')}">
        </div>
      </div>
      <button type="button" class="btn btn--sm btn--danger btn--icon rowlist__del" data-action="remove-special" title="${t('contracts.special.remove')}" aria-label="${t('contracts.special.remove')}"><span aria-hidden="true">×</span></button>
    </div>
  `;
}

function bindSpecialPaymentHandlers(modalEl) {
  const sec = modalEl.querySelector('[data-section="special"]');
  if (!sec) return;
  sec.querySelector('[data-action="add-special"]')?.addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderSpecialPaymentRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries')?.appendChild(row);
    bindSpecialPaymentRow(row);
    associateFieldLabels(row);
  });
  sec.querySelectorAll('.special-row').forEach(bindSpecialPaymentRow);
}

function bindSpecialPaymentRow(row) {
  row.querySelector('[data-action="remove-special"]')?.addEventListener('click', () => row.remove());
  const kindEl    = row.querySelector('[data-role="kind"]');
  const advBlock  = row.querySelector('[data-role="advance-block"]');
  const dateEl    = row.querySelector('[data-role="date"]');
  const amountEl  = row.querySelector('[data-role="amount"]');
  const naEl      = row.querySelector('[data-role="new-advance"]');
  const afEl      = row.querySelector('[data-role="advance-from"]');

  const syncAdvanceVisibility = () => {
    const affects = SPECIAL_KIND_AFFECTS_ADVANCE.has(kindEl?.value);
    if (advBlock) advBlock.hidden = !affects;
    if (!affects) { // beim Wechsel auf "ohne" die Abschlagsfelder leeren
      if (naEl) { naEl.value = ''; naEl.classList.remove('invalid'); }
      if (afEl) { afEl.value = ''; afEl.classList.remove('invalid'); }
    }
    row.dataset.kind = kindEl?.value || '';
  };
  kindEl?.addEventListener('change', syncAdvanceVisibility);

  // Halb-leer-Markierung Datum/Betrag (analog Bonus)
  [dateEl, amountEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => validatePair(dateEl, amountEl));
  });
  // Halb-leer-Markierung der Abschlagsfelder (nur bei *_mit relevant)
  [naEl, afEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => validatePair(afEl, naEl));
  });
}

// Sonderzahlungen aus den Zeilen des Abschnitts — für den Standard- und
// (seit v3.5.0, GitHub #34) den Wasservertrag. Leere Vorlagezeilen fallen weg.
function collectSpecialPayments(root) {
  const entries = [];
  root.querySelectorAll('.special-row').forEach(row => {
    const date = row.querySelector('[data-role="date"]').value.trim();
    const am   = row.querySelector('[data-role="amount"]').value.trim();
    const kind = row.querySelector('[data-role="kind"]').value;
    const note = row.querySelector('[data-role="note"]')?.value.trim() || '';
    if (!date && !am) return; // leere Vorlagezeile überspringen
    const entry = {
      date,
      amount_eur: amountValue(am),
      kind,
      note,
    };
    if (SPECIAL_KIND_AFFECTS_ADVANCE.has(kind)) {
      const na = row.querySelector('[data-role="new-advance"]')?.value.trim() || '';
      const af = row.querySelector('[data-role="advance-from"]')?.value.trim() || '';
      entry.new_advance_eur = amountValue(na);
      entry.advance_from    = af;
    }
    entries.push(entry);
  });
  return entries;
}

// ───── Payload extraction ───────────────────────────────────────────
// Leer bleibt '' (das Backend meldet die halbe Zeile), Text wird zur Zahl.
// Ein unlesbarer Rest geht als Text hinaus — auch den lehnt das Backend mit
// Zeilennummer ab, statt eine 0 zu speichern (Lektion 24).
function amountValue(raw) {
  const s = String(raw ?? '').trim();
  if (s === '') return '';
  return parseDecimal(s) ?? s;
}

// v2.9.0 — gespeicherte Frist zurück in Zahl und Einheit (durch 7 teilbare
// Tage als Wochen)
function noticeInitial(c) {
  const d = c?.notice_period_days;
  if (d !== null && d !== undefined && d !== '') {
    return Number(d) > 0 && Number(d) % 7 === 0 ? { value: String(Number(d) / 7), unit: 'weeks' } : { value: String(d), unit: 'days' };
  }
  const m = c?.notice_period_months;
  return { value: m === null || m === undefined ? '' : String(m), unit: 'months' };
}

// v3.1.0 (H7, MKT-16) — Gutschriften des Direktvermarkters (Einspeisung): Zeitraum und Betrag
function renderRevenueSection(items) {
  return `<fieldset class="fgroup" data-min-level="expert"><legend>${t('contracts.revenue.title')}</legend>
    <button type="button" class="btn btn--sm btn--ghost fgroup__add" data-action="add-revenue">${t('contracts.revenue.add')}</button>
    <p class="fgroup__hint">${t('contracts.revenue.hint')}</p>
    <div class="rowlist rowlist--period" data-role="revenue-rows">${items.map(revenueRowHtml).join('')}</div>
  </fieldset>`;
}

// von | bis | Betrag | ✕
function revenueRowHtml(s) {
  return `<div class="entry-row revenue-row rowlist__row">
    <div class="field"><label>${t('contracts.revenue.from')}</label><input class="input" type="date" data-role="rev-from" value="${escapeHtml(s.from || '')}"></div>
    <div class="field"><label>${t('contracts.revenue.to')}</label><input class="input" type="date" data-role="rev-to" value="${escapeHtml(s.to || '')}"></div>
    <div class="field"><label>${t('contracts.revenue.amount')}</label>${amountInput('rev-amount', s.amount_eur, 2)}</div>
    <button type="button" class="btn btn--sm btn--danger btn--icon rowlist__del" data-action="remove-revenue" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button>
  </div>`;
}

function collectEntries(group, g) {
  const entries = [];
  group?.querySelectorAll('.entry-row').forEach(row => {
    const dateEl = row.querySelector('[data-role="date"]');
    const date   = dateEl.value.trim();
    const amount = row.querySelector('[data-role="amount"]').value.trim();
    // Send all rows including half-filled — backend will reject loudly.
    // But skip fully-empty so blank template rows don't trigger silent drops.
    // v2.12.0 — ein vorbelegtes Datum ohne Betrag gilt als leer
    if (!amount && (!date || dateEl.dataset.auto === '1')) return;
    const entry = { [g.dateKey]: date, [g.amountKey]: amountValue(amount) };
    // v3.9.0 (F1034) — Rhythmus und genannte Termine des Abschlags (monatlich fehlt beides, wie bisher)
    const every = Number(row.querySelector('[data-role="every"]')?.value || 1);
    if (every > 1) entry.every_months = every;
    const due = (row.querySelector('[data-role="due"]')?.value || '').split(',').filter(Boolean);
    if (due.length) entry.due_dates = due;
    entries.push(entry);
  });
  return entries;
}

function collectPayload(form, u = null) {
  // Leeres Feld → null, nicht 0 oder "": Eine Kündigungsfrist von null heißt
  // „nicht gepflegt" und schaltet die Terminrechnung ab; eine von 0 hieße
  // „jederzeit kündbar" und ist eine Aussage.
  const notice = form.notice_value?.value.trim();
  const unit   = form.notice_unit?.value || 'months';
  const n      = notice === '' || notice === undefined ? null : Number(notice);
  const target = form.meter_id.value;   // v3.1.0 (H6) — „g:<id>" = Zählergruppe
  const payload = {
    provider:    form.provider.value,
    tariff_name: form.tariff_name.value,
    meter_id:    target.startsWith('g:') ? null : target,
    start:       form.start.value,
    end:         form.end.value || null,
    notes:       form.notes.value,
    // v2.9.0 (CALC-11) — Monate bleiben im bisherigen Feld; Wochen und Tage
    // stehen in Tagen, und das Monatsfeld ist dann leer
    notice_period_months:  n !== null && unit === 'months' ? n : null,
    notice_period_days:    n !== null && unit !== 'months' ? (unit === 'weeks' ? n * 7 : n) : null,
    notice_mode:           form.notice_mode?.value || null,
    auto_renews:           form.auto_renews ? form.auto_renews.checked : null,
    min_term_end:          form.min_term_end?.value || null,
    price_guarantee_until: form.price_guarantee_until?.value || null,
  };

  for (const g of groupsFor(u)) {
    payload[g.key] = collectEntries(form.querySelector(`[data-group="${g.key}"]`), g);
  }
  // v3.3.0 (F1021) — § 14a Modul 3
  const tou = collectGridTou(form);
  if (tou !== undefined) payload.grid_tou = tou;
  // v3.1.0 (H6, #17) — Gruppenvertrag mit Arbeitspreisen je Mitglied
  if (target.startsWith('g:')) {
    payload.meter_group_id = target.slice(2);
    payload.working_prices_by_meter = {};
    form.querySelectorAll('[data-group^="wpm:"]').forEach(group => {
      const key = group.getAttribute('data-group');
      const entries = collectEntries(group, groupDef(key));
      if (entries.length) payload.working_prices_by_meter[key.slice(4)] = entries;
    });
  }

  const bonusEntries = [];
  form.querySelectorAll('.bonus-row').forEach(row => {
    const cd  = row.querySelector('[data-role="date"]').value.trim();
    const am  = row.querySelector('[data-role="amount"]').value.trim();
    const tp  = row.querySelector('[data-role="type"]').value;
    // v3.7.0 (#35) — die Notiz (`label`) mit; bis v3.6 stand hier immer ''
    const note = row.querySelector('[data-role="note"]')?.value.trim() || '';
    if (!cd && !am) return;
    bonusEntries.push({ credit_date: cd, amount_eur: amountValue(am), type: tp, label: note });
  });
  payload.bonuses = bonusEntries;

  // F1003 — Sonderzahlungen
  payload.special_payments = collectSpecialPayments(form);

  // v3.1.0 (H7, MKT-16) — Gutschriften (nur Einspeisung)
  if (u?.accounting_kind === 'feed_in') {
    payload.revenue_statements = [...form.querySelectorAll('.revenue-row')].map(row => ({
      from: row.querySelector('[data-role="rev-from"]').value,
      to: row.querySelector('[data-role="rev-to"]').value,
      amount_eur: amountValue(row.querySelector('[data-role="rev-amount"]').value.trim()),
    })).filter(s => s.from || s.to || s.amount_eur !== null);
  }
  // v3.1.0 (H5, CALC-31) — Fernwärme: Anschlussleistung, Netzfaktor, Primärenergiefaktor
  if (u?.key === 'fernwaerme') {
    for (const k of ['capacity_kw', 'co2_g_per_kwh', 'primary_energy_factor']) {
      const raw = form[k]?.value.trim() ?? '';
      payload[k] = raw === '' ? null : amountValue(raw);
    }
  }
  return payload;
}

// ───── Water Contract modal (v1.0.3) ────────────────────────────────
// Drei separate Komponenten: Trinkwasser, Schmutzwasser, Niederschlagswasser.
// Eigene Saldo-Logik, eigene Felder.
async function openWaterContractModal(u, meters, existing, contracts = []) {
  const people = await api.people().catch(() => []);   // v3.7.0 — Auswahl „Zuständig“
  return new Promise(resolve => {
    const isEdit = !!existing;
    const initial = existing || {
      meter_id: meters[0]?.id || '',
      provider: '', tariff_name: '',
      start: suggestStart(contracts, meters[0]?.id || ''), end: '', notes: '',
      trinkwasser: {
        working_prices: [{ from: '', ct_per_m3: '' }],
        base_prices:    [{ from: '', eur_per_month: '' }],
      },
      schmutzwasser: {
        basis: 'trinkwasser',
        separater_zaehler_meter_id: null,
        working_prices: [{ from: '', ct_per_m3: '' }],
        base_prices:    [{ from: '', eur_per_month: '' }],   // v3.9.0 (F1034)
      },
      niederschlagswasser: {
        rates: [{ from: '', eur_per_m2_year: '', versiegelte_flaeche_m2: '' }],
      },
      advance_payments:[{ from: '', amount_eur: '' }],
      bonuses: [],
      special_payments: [],
    };

    const body = document.createElement('div');
    body.innerHTML = waterFormHtml(initial, meters, u, people, existing);

    const footer = document.createElement('div');
    footer.innerHTML = `
      <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--primary" data-act="save">${isEdit ? t('contracts.modal.save') : t('contracts.modal.create')}</button>
    `;

    openModal({
      title: isEdit ? t('contracts.water.titleEdit') : t('contracts.water.titleNew'),
      body: body.innerHTML,
      footer: footer.innerHTML,
      onMount({ modalEl, close }) {
        bindWaterEntryHandlers(modalEl);
        // v3.5.0 (GitHub #34) — Sonderzahlungen wie im Standardvertrag
        try { bindSpecialPaymentHandlers(modalEl); }
        catch (e) { console.warn('water contract modal: special-payment binding failed:', e); }
        associateFieldLabels(modalEl);

        // Toggle separater Zähler input
        const basisRadios = modalEl.querySelectorAll('input[name="schmutz-basis"]');
        const basisSep = modalEl.querySelector('#schmutz-separater-row');
        const basisDeduct = modalEl.querySelector('#schmutz-abzug-row');
        basisRadios.forEach(r => r.addEventListener('change', () => {
          const v = modalEl.querySelector('input[name="schmutz-basis"]:checked').value;
          basisSep.style.display = v === 'separater_zaehler' ? '' : 'none';
          if (basisDeduct) basisDeduct.style.display = v === 'trinkwasser_minus_abzug' ? '' : 'none';
        }));

        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(null); resolve(false); });
        const saveBtn = modalEl.querySelector('[data-act="save"]');
        saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
          const form = modalEl.querySelector('#water-contract-form');
          const twGroup = form.querySelector('[data-group="tw-working"]');
          const invalid = checkContractBasics(form, {
            workingRows: [...(twGroup?.querySelectorAll('tbody tr') || [])],
            workingMsg:  twGroup?.querySelector('[data-role="group-msg"]'),
            workingLabel: t('contracts.water.twWorking'),
            dateOf:   tr => tr.querySelector('[data-field="from"]')?.value.trim() || '',
            amountOf: tr => tr.querySelector('[data-field="ct_per_m3"]')?.value || '',
          });
          if (invalid) return;
          // v2.2.0 — halb gefüllte Zeilen vor dem Absenden markieren (analog
          // validateAllGroups im Standard-Formular). Vorher rutschten sie als
          // stille 0 durch, siehe collectWaterForm().
          // v3.5.0 — die Sonderzahlungen sind Zeilen des Standardrasters
          // (.entry-row); validateAllGroups prüft sie samt Abschlagsanpassung
          const waterOk = validateWaterRows(modalEl);
          const specialOk = validateAllGroups(modalEl);
          if (!waterOk || !specialOk) {
            toastErr(t('contracts.modal.validationHalfRows'));
            return;
          }
          let payload;
          try { payload = { ...collectWaterForm(modalEl), ...personPayload(modalEl.querySelector('[name="assignee_id"]'), existing) };   // v3.7.0
          } catch (err) { toastErr(err.message); return; }
          if (!await confirmSupersede(contracts, payload, existing)) return;
          try {
            if (isEdit) await api.updateContract(u.key, existing.id, payload);
            else        await api.createContract(u.key, payload);
            toastOk(isEdit ? t('contracts.water.savedEdit') : t('contracts.water.savedNew'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        }));
      },
    });
  });
}

function waterFormHtml(c, meters, u, people = [], existing = null) {
  const meterOpts = meters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === c.meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');
  const sw = c.schmutzwasser || {};
  const basis = sw.basis || 'trinkwasser';
  const otherMeters = meters.filter(m => m.id !== c.meter_id);
  const sepMeterOpts = otherMeters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === sw.separater_zaehler_meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

  return `
    <form id="water-contract-form">
      <!-- v3.4.0 (N1021) — Kopf im Formularraster wie der Standardvertrag -->
      <div class="fgrid">
        <div class="field"><label>${t('contracts.water.provider')}</label>
          <input class="input input--text" name="provider" value="${escapeHtml(c.provider || '')}">
          <div class="field-error" data-role="name-msg" role="alert" hidden></div></div>
        <div class="field"><label>${t('contracts.water.tariff')}</label>
          <input class="input input--text" name="tariff_name" value="${escapeHtml(c.tariff_name || '')}"></div>
        <div class="field field--s"><label>${t('contracts.water.start')}</label><input class="input" type="date" name="start" value="${escapeHtml(c.start || '')}" required>
          <div class="field-error" data-role="start-msg" role="alert" hidden></div></div>
        <div class="field field--s"><label>${t('contracts.water.end')}</label><input class="input" type="date" name="end" value="${escapeHtml(c.end || '')}"></div>
        <div class="field field--m"><label>${t('contracts.water.meter')}</label><select class="select" name="meter_id" required>${meterOpts}</select></div>
        ${personSelectHtml({ id: 'wf-assignee', record: existing, people, hintKey: 'assignee.hintContract' })}
        <div class="field field--full">
          <label>${t('contracts.water.notes')}</label>
          <input class="input input--text" name="notes" value="${escapeHtml(c.notes || '')}">
        </div>
      </div>

      <!-- Trinkwasser -->
      <details open style="margin-top:var(--sp-4);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.tw')}</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('tw-working', t('contracts.water.twWorking'), c.trinkwasser?.working_prices || [], ['from', 'ct_per_m3'], [t('contracts.water.colDate'), t('contracts.water.colCtM3')])}
          ${renderWaterEntryGroup('tw-base', t('contracts.water.twBase'), c.trinkwasser?.base_prices || [], ['from', 'eur_per_month'], [t('contracts.water.colDate'), t('contracts.water.colEurMonth')])}
        </div>
      </details>

      <!-- Schmutzwasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.sw')}</summary>
        <div style="margin-top:12px">
          <div class="field">
            <label>${t('contracts.water.basis')}</label>
            <label class="check">
              <input type="radio" name="schmutz-basis" value="trinkwasser" ${basis === 'trinkwasser' ? 'checked' : ''}>
              <span>${t('contracts.water.basisTw')}</span>
            </label>
            <label class="check">
              <input type="radio" name="schmutz-basis" value="separater_zaehler" ${basis === 'separater_zaehler' ? 'checked' : ''}>
              <span>${t('contracts.water.basisSep')}</span>
            </label>
            <label class="check">
              <input type="radio" name="schmutz-basis" value="trinkwasser_minus_abzug" ${basis === 'trinkwasser_minus_abzug' ? 'checked' : ''}>
              <span>${t('contracts.water.basisDeduct')}</span>
            </label>
          </div>
          <div class="field" id="schmutz-separater-row" style="${basis === 'separater_zaehler' ? '' : 'display:none'}">
            <label>${t('contracts.water.sepMeter')}</label>
            <select class="select" name="schmutz_separater_zaehler_meter_id">${sepMeterOpts || `<option value="">${t('contracts.water.noMoreMeters')}</option>`}</select>
          </div>
          <!-- v3.1.0 (Review CALC-20) — Abzugszähler, z. B. der Gartenzähler -->
          <fieldset class="field" id="schmutz-abzug-row" style="${basis === 'trinkwasser_minus_abzug' ? '' : 'display:none'};border:0;padding:0;margin:0">
            <legend>${t('contracts.water.deductMeters')}</legend>
            ${otherMeters.length ? otherMeters.map(m => `
            <label class="check">
              <input type="checkbox" name="schmutz_abzug" value="${escapeHtml(m.id)}" ${(sw.abzug_meter_ids || []).includes(m.id) ? 'checked' : ''}>
              <span>${escapeHtml(m.name)}</span>
            </label>`).join('') : `<p class="muted small">${t('contracts.water.noMoreMeters')}</p>`}
            <p class="muted small">${t('contracts.water.deductHint')}</p>
          </fieldset>
          ${renderWaterEntryGroup('sw-working', t('contracts.water.swWorking'), sw.working_prices || [], ['from', 'ct_per_m3'], [t('contracts.water.colDate'), t('contracts.water.colCtM3')])}
          ${/* v3.9.0 (F1034) — Grundpreis auch für Schmutzwasser (Basis- und Bereitstellungspreis) */ ''}
          ${renderWaterEntryGroup('sw-base', t('contracts.water.swBase'), sw.base_prices || [], ['from', 'eur_per_month'], [t('contracts.water.colDate'), t('contracts.water.colEurMonth')])}
        </div>
      </details>

      <!-- Niederschlagswasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.nw')}</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('nw-rates', t('contracts.water.nwRates'), c.niederschlagswasser?.rates || [], ['from', 'eur_per_m2_year', 'versiegelte_flaeche_m2'], [t('contracts.water.colDate'), t('contracts.water.colEurM2Year'), t('contracts.water.colSealedM2')])}
        </div>
      </details>

      <!-- Abschläge -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.advances')}</summary>
        <div style="margin-top:12px">
          ${/* v3.9.0 (F1034) — Abschlagsplan mit Rhythmus: Betrag je Termin */ ''}
          ${renderWaterEntryGroup('ap', t('contracts.water.apPlan'), c.advance_payments || [], ['from', 'amount_eur', 'every_months'], [t('contracts.water.colDate'), t('contracts.water.colEurDue'), t('contracts.advance.every')])}
        </div>
      </details>

      <!-- Bonuses -->
      <details style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.bonuses')}</summary>
        <div style="margin-top:12px">
          ${renderWaterBonusGroup(c.bonuses || [])}
        </div>
      </details>

      <!-- v3.5.0 (GitHub #34) — Sonderzahlungen: derselbe Abschnitt wie im
           Standardvertrag (Raster N1021), nach den Boni -->
      ${hasSpecialPayments(u) ? `<div style="margin-top:var(--sp-3)">${renderSpecialPaymentSection(c.special_payments || [])}</div>` : ''}
    </form>
  `;
}

// v3.7.0 (Qualität) — Felder in den Wasser-Tabellen tragen ihren Namen
// (Spaltenkopf, davor die Gruppe): Im Formularmodus lesen Bildschirmleser den
// Spaltenkopf nicht vor, die Felder hießen nur „Eingabefeld“. Die Gruppe ist
// eine role="group" mit Titel und Fehlermeldung.
const waterCellLabel = (group, column) => (group ? `${group}: ${column}` : column);
function waterGroupLabels(group) {
  const title = group.querySelector('[data-role="group-title"]')?.textContent || '';
  return [...group.querySelectorAll('thead th[scope="col"]')].map(th => waterCellLabel(title, th.textContent.trim()));
}

function renderWaterEntryGroup(groupKey, title, entries, fields, labels) {
  entries = entries.length ? newestFirst(entries, fields[0]) : [Object.fromEntries(fields.map(f => [f, '']))];
  const inputs = fields.map((f, i) => `<th scope="col">${escapeHtml(labels[i])}</th>`).join('');
  const key = escapeHtml(groupKey);
  const rows = entries.map((e, idx) => `
    <tr data-row="${idx}">
      ${fields.map((f, i) => `
        <td>
          ${i === 0
            ? `<input class="input" type="date" data-field="${escapeHtml(f)}" value="${escapeHtml(e[f] ?? '')}" aria-label="${escapeHtml(waterCellLabel(title, labels[i]))}">`
            : f === 'every_months'   // v3.9.0 (F1034) — Rhythmus des Abschlags, die genannten Termine bleiben erhalten
              ? waterEverySelect(Number(e.every_months) || 1, waterCellLabel(title, labels[i])) + waterDueHidden(e.due_dates)
              : waterNumberInput(f, e[f], waterCellLabel(title, labels[i])) + (groupKey === 'ap' && f === 'amount_eur' ? waterGapNote(entries, e) : '')}
        </td>
      `).join('')}
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>
    </tr>
  `).join('');
  return `
    <div class="field" role="group" aria-labelledby="wg-${key}-title" aria-describedby="wg-${key}-msg" data-group="${key}" data-fields='${escapeHtml(JSON.stringify(fields))}'>
      <label id="wg-${key}-title" data-role="group-title">${escapeHtml(title)}</label>
      <div class="field-error" id="wg-${key}-msg" data-role="group-msg" role="alert" hidden></div>
      <table class="table table--compact water-table">
        <thead><tr>${inputs}<th scope="col"><span class="sr-only">${t('common.actions')}</span></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">${t('contracts.water.addRow')}</button>
    </div>
  `;
}

// v3.9.0 (F1034) — Rhythmus einer Abschlagszeile im Wasserformular und ihre genannten Termine
function waterEverySelect(every, label = '') {
  return `<select class="select" data-field="every_months"${label ? ` aria-label="${escapeHtml(label)}"` : ''}>${ADVANCE_EVERY.map(n =>
    `<option value="${n}"${n === every ? ' selected' : ''}>${escapeHtml(tp('advance.rhythm.every', n))}</option>`).join('')}</select>`;
}
// Skeptiker R2-8 — Hinweis zur 0-€-Zeile wie im Standardformular
function waterGapNote(entries, e) {
  const text = zeroAdvanceText(zeroAdvanceSpan(entries, e));
  return text ? `<span class="muted small" data-role="gap-note">${escapeHtml(text)}</span>` : '';
}
function waterDueHidden(dates) {
  const list = (Array.isArray(dates) ? dates : []).filter(d => /^\d{4}-\d{2}-\d{2}$/.test(String(d)));
  return list.length ? `<input type="hidden" data-field="due_dates" value="${escapeHtml(list.join(','))}"><span class="muted small" data-role="due-note">${escapeHtml(t('contracts.advance.dueDates', { dates: list.map(fmt.date).join(', ') }))}</span>` : '';
}

// Zahlenfeld der Wasser-Tabellen — Text mit Dezimaltastatur wie im
// Standardformular (amountInput), gelesen mit parseDecimal.
function waterNumberInput(field, value, label = '') {
  return `<input class="input num" type="text" inputmode="decimal" autocomplete="off" data-field="${escapeHtml(field)}" value="${escapeHtml(formatForInput(value))}"${label ? ` aria-label="${escapeHtml(label)}"` : ''}>`;
}

// v3.7.0 (GitHub #35) — mit der Art (auch „Sonstige Gutschrift“). Bis v3.6
// speicherte das Wasserformular jeden Bonus als „neukunde“, auch einen anderen.
function waterBonusTypeSelect(type = 'neukunde') {
  const options = BONUS_TYPES.includes(type) ? BONUS_TYPES : [...BONUS_TYPES, type];
  return `<select class="select" data-field="type" aria-label="${escapeHtml(t('contracts.bonus.type'))}">${options.map(o =>
    `<option value="${escapeHtml(o)}" ${o === type ? 'selected' : ''}>${escapeHtml(bonusTypeLabel(o))}</option>`).join('')}</select>`;
}

function renderWaterBonusGroup(bonuses) {
  if (!bonuses.length) bonuses = [{ credit_date: '', amount_eur: '', type: 'neukunde', label: '' }];
  const [lDate, lLabel] = [t('contracts.water.bonusCredit'), t('contracts.water.bonusLabel')].map(escapeHtml);
  const rows = bonuses.map((b, idx) => `
    <tr data-row="${idx}">
      <td><input class="input" type="date" data-field="credit_date" value="${escapeHtml(b.credit_date || '')}" aria-label="${lDate}"></td>
      <td>${waterNumberInput('amount_eur', b.amount_eur, t('contracts.water.bonusAmount'))}</td>
      <td>${waterBonusTypeSelect(b.type || 'neukunde')}</td>
      <td><input class="input input--text" data-field="label" value="${escapeHtml(b.label || '')}" aria-label="${lLabel}"></td>
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>
    </tr>
  `).join('');
  return `
    <div class="field" data-group="bonuses" data-fields='["credit_date","amount_eur","type","label"]'>
      <table class="table table--compact water-table">
        <thead><tr><th scope="col">${t('contracts.water.bonusCredit')}</th><th scope="col">${t('contracts.water.bonusAmount')}</th><th scope="col">${t('contracts.bonus.type')}</th><th scope="col">${t('contracts.water.bonusLabel')}</th><th scope="col"><span class="sr-only">${t('common.actions')}</span></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">${t('contracts.water.addRow')}</button>
    </div>
  `;
}

function bindWaterEntryHandlers(modalEl) {
  // v3.9.0 (F1034) — ein anderer Rhythmus: die Termine laut Rechnung gehörten zum alten, sie entfallen
  modalEl.addEventListener('change', (ev) => {
    if (!ev.target.matches?.('[data-field="every_months"]')) return;
    ev.target.closest('td')?.querySelectorAll('[data-field="due_dates"], [data-role="due-note"]').forEach(n => n.remove());
  });
  // Skeptiker R2-8 — ein anderer Betrag oder Tag: der Hinweis zur 0-€-Zeile gilt nicht mehr
  modalEl.addEventListener('input', (ev) => {
    if (ev.target.matches?.('[data-field="amount_eur"], [data-field="from"]')) ev.target.closest('tr')?.querySelector('[data-role="gap-note"]')?.remove();
  });
  modalEl.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) return;
    const group = btn.closest('[data-group]');
    if (!group) return;
    if (btn.getAttribute('data-act') === 'del-row') {
      const row = btn.closest('tr');
      if (row && row.parentElement.querySelectorAll('tr').length > 1) row.remove();
      else row?.querySelectorAll('input').forEach(i => i.value = '');
    } else if (btn.getAttribute('data-act') === 'add-row') {
      const tbody = group.querySelector('tbody');
      const fields = JSON.parse(group.getAttribute('data-fields') || '[]');
      const names = waterGroupLabels(group);
      const tr = document.createElement('tr');
      tr.setAttribute('data-row', tbody.children.length);
      const cells = fields.map((f, i) => {
        const aria = names[i] ? ` aria-label="${escapeHtml(names[i])}"` : '';
        if (f === 'credit_date' || f === 'from') {
          return `<td><input class="input" type="date" data-field="${f}"${aria}></td>`;
        }
        if (f === 'label') {
          return `<td><input class="input input--text" data-field="${f}"${aria}></td>`;
        }
        if (f === 'type') return `<td>${waterBonusTypeSelect()}</td>`;   // v3.7.0 (#35)
        if (f === 'every_months') return `<td>${waterEverySelect(1, names[i] || '')}</td>`;   // v3.9.0 (F1034)
        return `<td>${waterNumberInput(f, '', names[i] || '')}</td>`;
      }).join('');
      tr.innerHTML = `${cells}<td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>`;
      // v3.4.0 (N1021) — Preiszeilen: neueste oben; Boni bleiben in Erfassungsreihenfolge
      if (group.getAttribute('data-group') === 'bonuses') tbody.appendChild(tr);
      else tbody.prepend(tr);
    }
  });
}

// v2.2.0 — Pflichtfelder je Wasser-Gruppe. Beim Bonus ist `label` optional,
// überall sonst müssen alle Spalten einer Zeile gefüllt sein (oder keine).
function requiredWaterFields(groupKey, fields) {
  // v3.7.0 (#35) — die Art ist eine Auswahl und immer gesetzt: kein Pflichtfeld
  // v3.9.0 (F1034) — ebenso der Rhythmus eines Abschlags
  return groupKey === 'bonuses'
    ? fields.filter(f => f !== 'label' && f !== 'type')
    : fields.filter(f => f !== 'every_months');
}

/**
 * Markiert halb gefüllte Zeilen in den Wasser-Tabellen und meldet, ob das
 * Formular abgeschickt werden darf. Gegenstück zu validateAllGroups() im
 * Standard-Vertragsformular.
 */
function validateWaterRows(modalEl) {
  let ok = true;
  modalEl.querySelectorAll('[data-group]').forEach(group => {
    const key = group.getAttribute('data-group');
    let fields;
    try { fields = JSON.parse(group.getAttribute('data-fields') || '[]'); }
    catch { return; }
    const required = requiredWaterFields(key, fields);
    group.querySelectorAll('tbody tr').forEach(tr => {
      const inputs = required
        .map(f => tr.querySelector(`[data-field="${f}"]`))
        .filter(Boolean);
      const filled = inputs.filter(i => i.value.trim() !== '').length;
      const halfFilled = filled > 0 && filled < inputs.length;
      // v2.5.3 — gefüllt, aber keine Zahl („2,5,0"): ebenfalls markieren.
      const unreadable = i => i.getAttribute('inputmode') === 'decimal' && i.value.trim() !== '' && parseDecimal(i.value) === null;
      inputs.forEach(i => i.classList.toggle('invalid', (halfFilled && i.value.trim() === '') || unreadable(i)));
      if (halfFilled || inputs.some(unreadable)) ok = false;
    });
  });
  return ok;
}

function collectWaterForm(modalEl) {
  const form = modalEl.querySelector('#water-contract-form');
  const fd = new FormData(form);
  // v2.2.0 — Leere Zahlenfelder NICHT auf 0 zwingen. `parseFloat(x || 0)` machte
  // aus einem vergessenen Preis einen echten Tarif von 0 ct/m³ ab Stichtag; die
  // Kosten fielen ab da still auf den Grundpreis. Ein leerer String erreicht
  // stattdessen den Backend-Guard (normalizePriceList), der die halb gefüllte
  // Zeile ablehnt, statt sie zu speichern.
  // v2.5.3 — parseDecimal statt parseFloat: „1.250,5" wurde zu 1,25.
  const num = amountValue;
  const collect = (groupKey) => {
    const group = modalEl.querySelector(`[data-group="${groupKey}"]`);
    if (!group) return [];
    const fields = JSON.parse(group.getAttribute('data-fields') || '[]');
    const rows = [...group.querySelectorAll('tbody tr')];
    return rows.map(tr => {
      const obj = {};
      fields.forEach(f => {
        const inp = tr.querySelector(`[data-field="${f}"]`);
        obj[f] = inp ? inp.value : '';
      });
      // Drop fully empty rows — v3.9.0 (F1034): der Rhythmus ist eine Auswahl und zählt dafür nicht
      const allEmpty = Object.entries(obj).every(([k, v]) => k === 'every_months' || v === '' || v === null);
      if (!allEmpty && fields.includes('every_months')) obj.due_dates = (tr.querySelector('[data-field="due_dates"]')?.value || '').split(',').filter(Boolean);
      return allEmpty ? null : obj;
    }).filter(Boolean);
  };

  const payload = {
    provider:    fd.get('provider') || '',
    tariff_name: fd.get('tariff_name') || '',
    start:       fd.get('start') || '',
    end:         fd.get('end') || '',
    notes:       fd.get('notes') || '',
    meter_id:    fd.get('meter_id') || '',
    trinkwasser: {
      working_prices: collect('tw-working').map(r => ({ from: r.from, ct_per_m3: num(r.ct_per_m3) })),
      base_prices:    collect('tw-base').map(r => ({ from: r.from, eur_per_month: num(r.eur_per_month) })),
    },
    schmutzwasser: {
      basis: fd.get('schmutz-basis') || 'trinkwasser',
      separater_zaehler_meter_id: (fd.get('schmutz-basis') === 'separater_zaehler')
        ? (fd.get('schmutz_separater_zaehler_meter_id') || null)
        : null,
      // v3.1.0 (CALC-20) — Abzugszähler (Garten); der Server prüft, dass es welche gibt
      abzug_meter_ids: fd.get('schmutz-basis') === 'trinkwasser_minus_abzug' ? fd.getAll('schmutz_abzug') : [],
      working_prices: collect('sw-working').map(r => ({ from: r.from, ct_per_m3: num(r.ct_per_m3) })),
      base_prices:    collect('sw-base').map(r => ({ from: r.from, eur_per_month: num(r.eur_per_month) })),   // v3.9.0 (F1034)
    },
    niederschlagswasser: {
      rates: collect('nw-rates').map(r => ({
        from: r.from,
        eur_per_m2_year: num(r.eur_per_m2_year),
        versiegelte_flaeche_m2: num(r.versiegelte_flaeche_m2),
      })),
    },
    // v3.9.0 (F1034) — Rhythmus und genannte Termine (monatlich ohne beides, wie bisher)
    advance_payments: collect('ap').map(r => ({ from: r.from, amount_eur: num(r.amount_eur),
      ...(Number(r.every_months) > 1 ? { every_months: Number(r.every_months) } : {}),
      ...(r.due_dates?.length ? { due_dates: r.due_dates } : {}) })),
    // v3.7.0 (#35) — die gewählte Art statt immer „neukunde“; Zeilen nur mit Art zählen als leer
    bonuses: collect('bonuses').filter(r => r.credit_date || r.amount_eur || r.label).map(r => ({
      credit_date: r.credit_date,
      amount_eur: num(r.amount_eur),
      label: r.label || '',
      type: r.type || 'neukunde',
    })),
  };
  // v3.5.0 (GitHub #34) — Sonderzahlungen, sofern der Abschnitt da ist
  if (form.querySelector('[data-section="special"]')) payload.special_payments = collectSpecialPayments(form);

  if (!payload.start) throw new Error(t('contracts.water.startRequired'));
  return payload;
}
