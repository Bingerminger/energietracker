// =====================================================================
// Energietracker v1.1.0 — Meters view
// F2 (Zählertausch) and F3 (multiple meters per utility).
// One row per meter showing its device chain. Buttons: add meter,
// replace device, CSV import (F-06), edit, delete.
// =====================================================================

import { api } from '../api.js';
import { getUtility } from '../state.js';
import { fmt, escapeHtml, todayIso, parseDecimal, formatForInput } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { t, tp } from '../lib/i18n.js';
import { checkReading, issueText, typicalPerDay, deviceChangedBetween } from '../lib/plausibility.js';
import { associateFieldLabels } from '../lib/a11y.js';
import { exampleReadingsCsv, downloadExample } from '../lib/csv-example.js';
import { renderError } from '../components/error.js';

export async function render(container, params) {
  const utilityKey = params[0];
  const u = await getUtility(utilityKey);
  if (!u) {
    container.innerHTML = `<div class="banner banner--error">${t('meters.unknown', { key: escapeHtml(utilityKey) })}</div>`;
    return;
  }

  container.setAttribute('data-utility', u.key);
  await refresh(container, u);
}

async function refresh(container, u) {
  container.innerHTML = `<div class="loading">${t('meters.loading')}</div>`;
  let meters, groups;
  try {
    [meters, groups] = await Promise.all([
      api.meters(u.key),
      api.meterGroups(u.key),
    ]);
  } catch (e) {
    // v2.11.0 (Review FE-25) — sonst hing die Ansicht nach dem Speichern bei „Lädt…"
    renderError(container, e, () => refresh(container, u));
    return;
  }

  // C — Übersichtszeile aus den bereits geladenen Daten.
  const activeCount = meters.filter(m => m.active !== false).length;
  const subCount    = meters.filter(m => m.parent_meter_id).length;
  // v2.14.0 — Pluralformen („1 meter“ statt „1 meters“, „1 Gruppe“ statt „1 Gruppe(n)“)
  const summaryLine = meters.length ? [
    tp('meters.summary.meters', meters.length),
    tp('meters.summary.active', activeCount),
    ...(subCount ? [tp('meters.summary.sub', subCount)] : []),
    ...(groups.length ? [tp('meters.summary.groups', groups.length)] : []),
  ].join(' · ') : '';

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${t('meters.title', { label: escapeHtml(u.label) })}</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">${t('meters.toOverview')}</a>
          ${meters.length >= 2 ? `<button type="button" class="btn btn--ghost" data-action="merge-meters">${t('meters.merge')}</button>` : ''}
          <button type="button" class="btn btn--util" data-action="new-meter">${t('meters.newMeter')}</button>
        </div>
      </div>

      <div class="banner banner--info">
        <strong>${t('meters.info.title')}</strong> ${t('meters.info.text')}
        <br><strong>${t('meters.info.topologyLabel')}</strong> ${t('meters.info.topologyText')}
      </div>

      ${renderGroupsBar(groups)}

      ${summaryLine ? `<div class="muted" style="margin:8px 0">${summaryLine}</div>` : ''}

      <div id="meters-list">
        ${meters.length === 0 ? `<p class="muted">${t('meters.empty')}</p><button type="button" class="btn btn--util" data-action="new-meter">${t('meters.emptyCta')}</button>` : ''}
        ${renderMeterTree(meters, groups, u)}
      </div>
    </div>
  `;

  container.querySelectorAll('[data-action="new-meter"]').forEach(btn => {
    btn.addEventListener('click', () => {
      openMeterModal(u, null, meters, groups).then(changed => { if (changed) refresh(container, u); });
    });
  });

  container.querySelector('[data-action="merge-meters"]')?.addEventListener('click', () => {
    openMergeModal(u, meters, groups).then(changed => { if (changed) refresh(container, u); });
  });

  container.querySelectorAll('[data-delete-group]').forEach(b => {
    b.addEventListener('click', async () => {
      const gid = b.getAttribute('data-delete-group');
      const g = groups.find(x => x.id === gid);
      const ok = await confirmModal({
        title: t('meters.deleteGroup.title'),
        message: t('meters.deleteGroup.message', { name: g?.name ?? gid }),
        confirmLabel: t('meters.deleteGroup.confirm'), danger: true
      });
      if (!ok) return;
      try { await api.deleteMeterGroup(u.key, gid); toastOk(t('meters.deleteGroup.done')); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });

  container.querySelectorAll('[data-edit-meter]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-meter');
      const m = meters.find(x => x.id === id);
      const changed = await openMeterModal(u, m, meters, groups);
      if (changed) refresh(container, u);
    });
  });

  container.querySelectorAll('[data-replace-device]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-replace-device');
      const m = meters.find(x => x.id === id);
      const changed = await openReplaceDeviceModal(u, m);
      if (changed) refresh(container, u);
    });
  });

  container.querySelectorAll('[data-import-readings]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-import-readings');
      const m = meters.find(x => x.id === id);
      const changed = await openImportReadingsModal(u, m);
      if (changed) refresh(container, u);
    });
  });

  // v3.1.0 (H8, MKT-19) — Zeitreihe aus einem Portal
  container.querySelectorAll('[data-import-series]').forEach(b => {
    b.addEventListener('click', async () => {
      const m = meters.find(x => x.id === b.getAttribute('data-import-series'));
      if (m && await openImportSeriesModal(u, m)) refresh(container, u);
    });
  });

  container.querySelectorAll('[data-delete-meter]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-delete-meter');
      const m = meters.find(x => x.id === id);
      const ok = await confirmModal({
        title: t('meters.deleteMeter.title'),
        message: t('meters.deleteMeter.message', { name: m.name }),
        confirmLabel: t('meters.deleteMeter.confirm'), danger: true
      });
      if (!ok) return;
      try { await api.deleteMeter(u.key, id); toastOk(t('meters.deleteMeter.done')); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

// ───── Gruppen-Übersicht + Topologie-Baum ───────────────────────────

function renderGroupsBar(groups) {
  if (!groups || groups.length === 0) return '';
  return `
    <div class="banner" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
      <strong>${t('meters.groupsBar.label')}</strong>
      ${groups.map(g => `
        <span class="tag tag--util" style="display:inline-flex;align-items:center;gap:6px">
          ${escapeHtml(g.name)}
          <button type="button" class="btn btn--sm btn--ghost" style="padding:0 6px" title="${t('meters.groupsBar.dissolveTitle')}" aria-label="${t('meters.groupsBar.dissolveTitle')}" data-delete-group="${escapeHtml(g.id)}"><span aria-hidden="true">✕</span></button>
        </span>
      `).join('')}
    </div>
  `;
}

// Sortiert: Elternzähler/Top-Level zuerst, ihre Subzähler direkt darunter
// (eingerückt). Subzähler ohne auffindbaren Elternzähler werden als
// Top-Level behandelt (defensiv).
function renderMeterTree(meters, groups, u) {
  if (!meters || meters.length === 0) return '';
  const byParent = {};
  const tops = [];
  const ids = new Set(meters.map(m => m.id));
  for (const m of meters) {
    const p = m.parent_meter_id;
    if (p && ids.has(p)) {
      (byParent[p] ||= []).push(m);
    } else {
      tops.push(m);
    }
  }
  return tops.map(m => {
    const children = byParent[m.id] || [];
    return renderMeterCard(m, u, groups, false)
      + children.map(c => renderMeterCard(c, u, groups, true)).join('');
  }).join('');
}

function groupName(groups, id) {
  return (groups || []).find(g => g.id === id)?.name ?? null;
}

function renderMeterCard(meter, u, groups, isSub) {
  const devices = meter.devices || [];
  const gName = meter.meter_group_id ? groupName(groups, meter.meter_group_id) : null;
  return `
    <div class="meter-card${isSub ? ' meter-card--sub' : ''}" data-utility="${u.key}"${isSub ? ' style="margin-left:32px;border-left:3px solid var(--util,#888)"' : ''}>
      <div class="meter-card__icon">${isSub ? '↳ ' : ''}${escapeHtml(meter.icon || u.icon)}</div>
      <div class="meter-card__main">
        <div class="meter-card__name">
          ${escapeHtml(meter.name)}
          ${meter.active ? '' : `<span class="tag tag--warning">${t('meters.card.inactive')}</span>`}
          ${isSub ? `<span class="tag">${t('meters.card.sub')}</span>` : ''}
          ${gName ? `<span class="tag tag--util">${t('meters.card.group', { name: escapeHtml(gName) })}</span>` : ''}
          ${meter.capture === 'period' ? `<span class="tag">${t('meters.card.periodTag')}</span>` : ''}
          ${roleOf(u, meter) && roleOf(u, meter) !== u.meter_roles[0] ? `<span class="tag">${escapeHtml(t(`meters.roles.${u.key}.${roleOf(u, meter)}`))}</span>` : ''}
        </div>
        <div class="meter-card__meta">
          ${tp('meters.card.devices', devices.length)}
          ${meter.notes ? ' · ' + escapeHtml(meter.notes) : ''}
        </div>
        <ul class="device-list">
          ${devices.map((d, i) => `
            <li class="${d.removed_on ? 'closed' : 'open'}">
              ${t('meters.card.deviceLine', { n: i + 1 })}${d.serial ? ' · ' + t('meters.card.serial', { serial: escapeHtml(d.serial) }) : ''}
              · ${fmt.date(d.installed_on)} ${d.removed_on ? '→ ' + fmt.date(d.removed_on) : t('meters.card.active')}
              · ${t('meters.card.start', { value: fmt.num(d.initial_counter, 2), unit: u.unit })}
              ${d.final_counter != null ? ' · ' + t('meters.card.end', { value: fmt.num(d.final_counter, 2), unit: u.unit }) : ''}
              ${d.digits ? ' · ' + t('meters.card.digits', { n: Number(d.digits) }) : ''}
              ${d.reason ? ' · ' + escapeHtml(d.reason) : ''}
            </li>
          `).join('')}
        </ul>
      </div>
      <div class="row-actions" style="flex-direction:column; gap: 4px">
        ${meter.capture === 'period' ? '' : `<button class="btn btn--sm" data-replace-device="${escapeHtml(meter.id)}">${t('meters.card.replace')}</button>`}
        <button class="btn btn--sm" data-import-readings="${escapeHtml(meter.id)}">${t('meters.card.csvImport')}</button>
        ${u.reading_kind === 'delivery' ? '' : `<button class="btn btn--sm btn--ghost" data-min-level="expert" data-import-series="${escapeHtml(meter.id)}">${t('import.series.button')}</button>`}
        <button class="btn btn--sm btn--ghost" data-edit-meter="${escapeHtml(meter.id)}">${t('meters.card.edit')}</button>
        <button class="btn btn--sm btn--danger btn--quiet" data-delete-meter="${escapeHtml(meter.id)}">${t('meters.card.delete')}</button>
      </div>
    </div>
  `;
}

// v3.1.0 (H3, B9) — Rolle eines Zählers wie Utilities::roleOf (strom: heat_source = heat_pump)
function roleOf(u, meter) {
  const roles = Array.isArray(u.meter_roles) ? u.meter_roles : [];
  if (!roles.length) return null;
  const r = meter?.role ?? (u.key === 'strom' && meter?.heat_source ? 'heat_pump' : null);
  return roles.includes(r) ? r : roles[0];
}

// ───── New / Edit meter ─────────────────────────────────────────────
async function openMeterModal(u, existing, allMeters = [], groups = []) {
  // Mögliche Elternzähler: alle anderen Zähler, die selbst KEIN Subzähler
  // sind (max. 1 Ebene) und nicht der bearbeitete Zähler selbst.
  const parentOptions = (allMeters || []).filter(m =>
    m.id !== existing?.id && !m.parent_meter_id
  );
  const curParent = existing?.parent_meter_id || '';
  const curGroup  = existing?.meter_group_id || '';
  // v2.1.1 — Fix #18: Delivery-Utilities (Heizöl/Pellets) verlangen beim
  // Anlegen eine Tank-Kapazität (> 0) und einen Anfangsbestand — sonst wirft
  // MeterService::create() errors.meter.capacityRequired. Diese Felder fehlten
  // bisher im Formular, daher ließ sich gar kein Tank anlegen (#18).
  const isDelivery = u.reading_kind === 'delivery';
  const volUnit = u.volume_unit || u.unit || '';
  // v2.6.0 — Stellen des Zählwerks am eingebauten Gerät (Überlauf-Erkennung)
  const activeDigits = (existing?.devices || []).find(d => !d.removed_on)?.digits ?? '';
  return new Promise(resolve => {
    const body = `
      <form id="meter-form">
        <div class="form-row">
          <div class="field">
            <label>${t('meters.modal.name')}</label>
            <input class="input input--text" name="name" required value="${escapeHtml(existing?.name || '')}" placeholder="${t('meters.modal.namePlaceholder')}">
          </div>
          <div class="field">
            <label>${t('meters.modal.icon')}</label>
            <input class="input input--text" name="icon" value="${escapeHtml(existing?.icon || u.icon)}">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>${t('meters.modal.parent')}</label>
            <select class="input" name="parent_meter_id">
              <option value="">${t('meters.modal.parentNone')}</option>
              ${parentOptions.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === curParent ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
            </select>
            <small class="muted">${t('meters.modal.parentHint')}</small>
          </div>
          <div class="field" data-min-level="expert">
            <label>${t('meters.modal.group')}</label>
            <select class="input" name="meter_group_id">
              <option value="">${t('meters.modal.groupNone')}</option>
              ${(groups || []).map(g => `<option value="${escapeHtml(g.id)}" ${g.id === curGroup ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        ${existing ? '' : `
          <div class="form-row">
            <div class="field">
              <label>${t('meters.modal.deviceSerial')}</label>
              <input class="input" name="device_serial" type="text">
            </div>
            <div class="field">
              <label>${t('meters.modal.installedOn')}</label>
              <input class="input" name="installed_on" type="date" value="${todayIso()}">
            </div>
            ${isDelivery ? '' : `
            <div class="field">
              <label>${t('meters.modal.initialCounter', { unit: u.unit })}</label>
              <input class="input" name="initial_counter" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(0))}">
            </div>
            `}
          </div>
        `}
        ${isDelivery ? '' : `
          <div class="field">
            <label for="mf-digits">${t('meters.modal.digits')}</label>
            <input class="input" id="mf-digits" name="digits" type="text" inputmode="numeric" autocomplete="off" maxlength="2"
                   style="max-width:8rem" value="${escapeHtml(String(activeDigits))}" aria-describedby="mf-digits-hint">
            <small class="muted" id="mf-digits-hint">${t('meters.modal.digitsHint')}</small>
          </div>
        `}
        ${isDelivery ? `
          <div class="form-row">
            <div class="field">
              <label>${t('meters.modal.capacity', { unit: volUnit })}</label>
              <input class="input" name="capacity" type="text" inputmode="decimal" autocomplete="off" required value="${escapeHtml(formatForInput(existing?.capacity))}">
            </div>
            <div class="field">
              <label>${t('meters.modal.initialStock', { unit: volUnit })}</label>
              <input class="input" name="initial_stock" type="text" inputmode="decimal" autocomplete="off" required value="${escapeHtml(formatForInput(existing?.initial_stock))}">
            </div>
          </div>
          <div class="field">
            <label for="mf-initial-price">${t('meters.modal.initialStockPrice', { unit: volUnit })}</label>
            <input class="input" id="mf-initial-price" name="initial_stock_price_ct" type="text" inputmode="decimal" autocomplete="off"
                   style="max-width:10rem" value="${escapeHtml(formatForInput(existing?.initial_stock_price_ct))}" aria-describedby="mf-initial-price-hint">
            <small class="muted" id="mf-initial-price-hint">${t('meters.modal.initialStockPriceHint')}</small>
          </div>
        ` : ''}
        <div class="field">
          <label>${t('meters.modal.notes')}</label>
          <textarea class="input input--text" name="notes">${escapeHtml(existing?.notes || '')}</textarea>
        </div>
        ${isDelivery ? '' : `
        <!-- v3.1.0 (H5, MKT-24) — für den Lieferantenwechsel -->
        <div class="form-row">
          <div class="field">
            <label for="mf-malo">${t('meters.modal.malo')}</label>
            <input class="input" id="mf-malo" name="malo_id" type="text" inputmode="numeric" autocomplete="off" maxlength="11" value="${escapeHtml(existing?.malo_id || '')}" aria-describedby="mf-malo-hint">
          </div>
          <div class="field">
            <label for="mf-melo">${t('meters.modal.melo')}</label>
            <input class="input" id="mf-melo" name="melo_id" type="text" autocomplete="off" maxlength="33" value="${escapeHtml(existing?.melo_id || '')}">
          </div>
        </div>
        <small class="muted" id="mf-malo-hint">${t('meters.modal.maloHint')}</small>`}
        <div class="field" data-min-level="expert">
          <label>${t('meters.baseline.title')}</label>
          <small class="muted">${t('meters.baseline.hint')}</small>
          <div id="bl-list" style="margin:8px 0"></div>
          <div class="form-row">
            <div class="field">
              <label for="bl-date">${t('meters.baseline.date')}</label>
              <input class="input" id="bl-date" type="date">
            </div>
            <div class="field">
              <label for="bl-label">${t('meters.baseline.label')}</label>
              <input class="input input--text" id="bl-label" type="text"
                     maxlength="80" placeholder="${t('meters.baseline.labelPlaceholder')}">
            </div>
            <div class="field" style="display:flex; align-items:flex-end">
              <button type="button" class="btn btn--ghost" id="bl-add">${t('meters.baseline.add')}</button>
            </div>
          </div>
        </div>
        ${Array.isArray(u.meter_roles) && u.meter_roles.length ? `
          <div class="field">
            <label for="mf-role">${t('meters.modal.role')}</label>
            <select class="input" id="mf-role" name="role" aria-describedby="mf-role-hint">
              ${u.meter_roles.map(r => `<option value="${r}" ${r === roleOf(u, existing) ? 'selected' : ''}>${escapeHtml(t(`meters.roles.${u.key}.${r}`))}</option>`).join('')}
            </select>
            <span class="settings-field__hint" id="mf-role-hint">${t(`meters.modal.roleHint.${u.key}`)}</span>
          </div>
        ` : ''}
        ${u.key === 'pv_erzeugung' ? `
          <!-- v3.1.0 (H7, CALC-29) — Balkonkraftwerk, Amortisation, Speicher -->
          <fieldset class="field" data-min-level="expert"><legend>${t('meters.pv.title')}</legend>
            <label class="settings-field__check"><input type="checkbox" name="plug_in" ${existing?.plug_in ? 'checked' : ''}> ${t('meters.pv.plugIn')}</label>
            <span class="settings-field__hint">${t('meters.pv.plugInHint')}</span>
            <div class="form-row">
              <div class="field"><label for="mf-invest">${t('meters.pv.investment')}</label>
                <input class="input" id="mf-invest" name="investment_eur" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(existing?.investment_eur))}"></div>
              <div class="field"><label for="mf-comm">${t('meters.pv.commissionedOn')}</label>
                <input class="input" id="mf-comm" name="commissioned_on" type="date" value="${escapeHtml(existing?.commissioned_on || '')}"></div>
              <div class="field"><label for="mf-batcap">${t('meters.pv.batteryCapacity')}</label>
                <input class="input" id="mf-batcap" name="battery_capacity_kwh" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(existing?.battery_capacity_kwh))}"></div>
            </div>
            <span class="settings-field__hint">${t('meters.pv.hint')}</span>
          </fieldset>` : ''}
        ${u.key === 'waerme' ? `
          <!-- v3.1.0 (H7, MKT-18) — Stromzähler der Wärmepumpe für die Jahresarbeitszahl -->
          <fieldset class="field" data-role="hp-link" data-min-level="expert"><legend>${t('meters.heatPumpLink.title')}</legend>
            <div data-role="hp-list" class="muted small">${t('meters.heatPumpLink.loading')}</div>
            <span class="settings-field__hint">${t('meters.heatPumpLink.hint')}</span>
          </fieldset>` : ''}
        ${isDelivery ? '' : `
          <div class="field">
            <label for="mf-capture">${t('meters.modal.capture')}</label>
            <select class="input" id="mf-capture" name="capture" aria-describedby="mf-capture-hint">
              <option value="counter" ${existing?.capture === 'period' ? '' : 'selected'}>${t('meters.capture.counter')}</option>
              <option value="period" ${existing?.capture === 'period' ? 'selected' : ''}>${t('meters.capture.period')}</option>
            </select>
            <span class="settings-field__hint" id="mf-capture-hint">${t('meters.modal.captureHint')}</span>
          </div>
        `}
        ${existing ? `
          <div class="field">
            <label><input type="checkbox" name="active" ${existing.active ? 'checked' : ''}> ${t('meters.modal.active')}</label>
            <span class="settings-field__hint">${t('meters.modal.activeHint')}</span>
          </div>
        ` : ''}
      </form>
    `;
    openModal({
      title: existing ? t('meters.modal.titleEdit') : t('meters.modal.titleNew'),
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--util" data-act="save">${t('meters.modal.save')}</button>
      `,
      onMount({ modalEl, close }) {
        associateFieldLabels(modalEl);
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        // ── F1011: Zäsuren verwalten ────────────────────────────────────
        // Die Liste wird lokal gehalten und beim Speichern als Ganzes
        // mitgeschickt — Anlegen, Bearbeiten und Löschen laufen damit über
        // denselben Pfad, und der MeterService validiert einmal zentral.
        let baselineEvents = (existing?.baseline_events || []).map(e => ({
          date: String(e.date || ''), label: String(e.label || ''),
        }));
        const blListEl = modalEl.querySelector('#bl-list');

        function drawBaseline() {
          if (!blListEl) return;
          if (!baselineEvents.length) {
            blListEl.innerHTML = `<p class="muted">${t('meters.baseline.none')}</p>`;
            return;
          }
          const today = todayIso();
          const sorted = [...baselineEvents].sort((a, b) => a.date.localeCompare(b.date));
          // Wirksam ist das späteste Ereignis, das nicht in der Zukunft liegt —
          // dieselbe Regel wie MeterService::activeBaselineEvent().
          const activeDate = sorted.filter(e => e.date <= today).at(-1)?.date;
          blListEl.innerHTML = `<ul style="list-style:none; padding:0; margin:0">${sorted.map(e => `
            <li style="padding:4px 0">
              <strong>${fmt.date(e.date)}</strong>
              ${e.label ? ' · ' + escapeHtml(e.label) : ''}
              ${e.date === activeDate
                ? ` <span class="badge badge--success">${t('meters.baseline.active')}</span>`
                : (e.date > today ? ` <span class="badge">${t('meters.baseline.future')}</span>` : '')}
              <button type="button" class="btn btn--ghost btn--sm" data-bl-del="${escapeHtml(e.date)}">
                ${t('meters.baseline.remove')}
              </button>
            </li>`).join('')}</ul>`;
        }

        blListEl?.addEventListener('click', ev => {
          const d = ev.target.closest('[data-bl-del]')?.getAttribute('data-bl-del');
          if (!d) return;
          baselineEvents = baselineEvents.filter(e => e.date !== d);
          drawBaseline();
        });

        modalEl.querySelector('#bl-add')?.addEventListener('click', () => {
          const d = modalEl.querySelector('#bl-date')?.value || '';
          const l = modalEl.querySelector('#bl-label')?.value || '';
          if (!d) { toastErr(t('meters.baseline.dateRequired')); return; }
          if (baselineEvents.some(e => e.date === d)) {
            toastErr(t('errors.meter.duplicateBaselineDate', { date: d }));
            return;
          }
          baselineEvents.push({ date: d, label: l.trim() });
          modalEl.querySelector('#bl-date').value = '';
          modalEl.querySelector('#bl-label').value = '';
          drawBaseline();
        });

        drawBaseline();

        // v3.1.0 (H7) — Wärmezähler: Stromzähler mit der Rolle Wärmepumpe zur Auswahl
        const hpList = modalEl.querySelector('[data-role="hp-list"]');
        if (hpList) {
          api.meters('strom').then(list => {
            const pumps = list.filter(m => m.role === 'heat_pump' || m.heat_source);
            const linked = new Set(existing?.heat_pump_meter_ids || []);
            hpList.innerHTML = pumps.length
              ? pumps.map(m => `<label class="settings-field__check"><input type="checkbox" data-hp-meter="${escapeHtml(m.id)}" ${linked.has(m.id) ? 'checked' : ''}> ${escapeHtml(m.name)}</label>`).join('')
              : escapeHtml(t('meters.heatPumpLink.none'));
          }).catch(() => { hpList.textContent = t('meters.heatPumpLink.none'); });
        }
        // Felder der PV-Anlage und der Wärmepumpe (nur, wo es sie gibt)
        const energyFields = (f) => {
          const out = {};
          if (u.key === 'pv_erzeugung') {
            out.plug_in = !!f.plug_in?.checked;
            for (const k of ['investment_eur', 'battery_capacity_kwh']) {
              const raw = f[k]?.value.trim() ?? '';
              out[k] = raw === '' ? null : parseDecimal(raw);
              if (raw !== '' && out[k] == null) throw new Error(t('common.invalidNumber', { example: formatForInput(1234.5) }));
            }
            out.commissioned_on = f.commissioned_on?.value || null;
          }
          if (u.key === 'waerme' && hpList) {
            out.heat_pump_meter_ids = [...modalEl.querySelectorAll('[data-hp-meter]:checked')].map(b => b.getAttribute('data-hp-meter'));
          }
          return out;
        };

        const saveBtn = modalEl.querySelector('[data-act="save"]');
        saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
          const f = modalEl.querySelector('#meter-form');
          // D (v2.1.4) — Tank-Kapazität sofort clientseitig prüfen statt erst den
          // Backend-Fehler abzuwarten (gleiche lokalisierte Meldung).
          // v2.5.3 — Zahlen mit Komma oder Punkt; leer ist leer, nicht 0.
          const capacity     = isDelivery ? parseDecimal(f.capacity.value) : null;
          const initialStock = isDelivery ? parseDecimal(f.initial_stock.value) : null;
          if (isDelivery && !(capacity > 0)) {
            toastErr(t('errors.meter.capacityRequired', { label: u.label }));
            return;
          }
          if (isDelivery && (initialStock == null || initialStock < 0)) {
            toastErr(t('common.invalidNumber', { example: formatForInput(1234.5) }));
            return;
          }
          // v2.10.0 — Preis des Anfangsbestands: leer bleibt leer (= Preis der ersten Lieferung)
          const initialPriceRaw = isDelivery ? String(f.initial_stock_price_ct?.value ?? '').trim() : '';
          const initialPrice = initialPriceRaw === '' ? null : parseDecimal(initialPriceRaw);
          if (initialPriceRaw !== '' && (initialPrice == null || initialPrice < 0)) {
            toastErr(t('common.invalidNumber', { example: formatForInput(98.5) }));
            return;
          }
          const initialCounterRaw = !isDelivery && f.initial_counter ? String(f.initial_counter.value).trim() : '';
          const initialCounter = initialCounterRaw === '' ? 0 : parseDecimal(initialCounterRaw);
          if (!isDelivery && f.initial_counter && (initialCounter == null || initialCounter < 0)) {
            toastErr(t('common.invalidNumber', { example: formatForInput(1234.5) }));
            return;
          }
          // v2.6.0 — Stellen des Zählwerks: leer oder 3–12
          const digitsRaw = !isDelivery ? String(f.digits?.value ?? '').trim() : '';
          if (digitsRaw !== '' && !(/^\d{1,2}$/.test(digitsRaw) && +digitsRaw >= 3 && +digitsRaw <= 12)) {
            toastErr(t('errors.meter.digitsInvalid', { value: digitsRaw }));
            return;
          }
          try {
            if (existing) {
              const payload = {
                name:   f.name.value,
                icon:   f.icon.value,
                notes:  f.notes.value,
                active: f.active.checked,
                ...(f.role ? { role: f.role.value } : {}),         // v3.1.0 (B9; strom: heat_pump = Heizstrom)
                ...(f.malo_id ? { malo_id: f.malo_id.value, melo_id: f.melo_id.value } : {}),   // v3.1.0 (MKT-24)
                ...(f.capture ? { capture: f.capture.value } : {}),   // v3.1.0 (B2)
                parent_meter_id: f.parent_meter_id.value || null,
                meter_group_id:  f.meter_group_id.value || null,
                baseline_events: baselineEvents,   // F1011
                ...energyFields(f),                // v3.1.0 (H7)
              };
              // v2.1.1 — Fix #18: Tank-Felder mitsenden (nur Heizöl/Pellets).
              if (isDelivery) {
                payload.capacity      = capacity;
                payload.initial_stock = initialStock;
                payload.initial_stock_price_ct = initialPrice;   // v2.10.0, null entfernt
              } else {
                payload.digits = digitsRaw === '' ? null : Number(digitsRaw);   // leer entfernt
              }
              await api.updateMeter(u.key, existing.id, payload);
            } else {
              const payload = {
                name:            f.name.value,
                icon:            f.icon.value,
                notes:           f.notes.value,
                device_serial:   f.device_serial.value || null,
                installed_on:    f.installed_on.value,
                parent_meter_id: f.parent_meter_id.value || null,
                meter_group_id:  f.meter_group_id.value || null,
                baseline_events: baselineEvents,   // F1011
                ...(f.role ? { role: f.role.value } : {}),            // v3.1.0 (B9)
                ...(f.malo_id?.value ? { malo_id: f.malo_id.value } : {}),
                ...(f.melo_id?.value ? { melo_id: f.melo_id.value } : {}),
                ...(f.capture?.value === 'period' ? { capture: 'period' } : {}),   // v3.1.0 (B2)
                ...energyFields(f),                                                  // v3.1.0 (H7)
              };
              // v2.1.1 — Fix #18: Delivery-Utilities bekommen Tank-Kapazität +
              // Anfangsbestand statt eines kumulativen Anfangsstands.
              if (isDelivery) {
                payload.capacity      = capacity;
                payload.initial_stock = initialStock;
                if (initialPrice != null) payload.initial_stock_price_ct = initialPrice;   // v2.10.0
              } else {
                payload.initial_counter = initialCounter;
                if (digitsRaw !== '') payload.digits = Number(digitsRaw);
              }
              await api.createMeter(u.key, payload);
            }
            toastOk(t('meters.modal.saved'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        }));
      }
    });
  });
}

// ───── Device replacement (F2) ──────────────────────────────────────
async function openReplaceDeviceModal(u, meter) {
  // v2.5.3 — Letzter erfasster Stand als Orientierung und Plausibilitätsgrenze
  // für den Endstand des alten Geräts (UI-03 im Review 2026-09-24).
  let last = null;
  try {
    const rs = await api.readings(u.key, meter.id);
    last = (rs || []).filter(r => !r.is_future).sort((a, b) => String(b.date).localeCompare(String(a.date)))[0] || null;
  } catch { /* ohne Hinweis weiter */ }
  return new Promise(resolve => {
    const body = `
      <p>${t('meters.replace.intro')}</p>
        <form id="replace-form">
        <div class="form-row">
          <div class="field">
            <label>${t('meters.replace.date')}</label>
            <input class="input" name="date" type="date" required value="${todayIso()}">
          </div>
          <div class="field">
            <label>${t('meters.replace.oldFinal', { unit: u.unit })}</label>
            <input class="input" name="old_final_counter" type="text" inputmode="decimal" autocomplete="off" required aria-describedby="rp-old-msg rp-old-hint">
            ${last ? `<small class="muted" id="rp-old-hint">${t('meters.replace.lastKnown', { value: fmt.num(last.counter, 2), unit: u.unit, date: fmt.date(last.date) })}</small>` : ''}
            <div class="field-error" id="rp-old-msg" role="alert" hidden></div>
          </div>
          <div class="field">
            <label>${t('meters.replace.newInitial', { unit: u.unit })}</label>
            <input class="input" name="new_initial_counter" type="text" inputmode="decimal" autocomplete="off" required value="${escapeHtml(formatForInput(0))}" aria-describedby="rp-new-msg">
            <div class="field-error" id="rp-new-msg" role="alert" hidden></div>
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>${t('meters.replace.serial')}</label>
            <input class="input" name="serial" type="text">
          </div>
          <div class="field">
            <label>${t('meters.replace.reason')}</label>
            <input class="input input--text" name="reason" type="text" placeholder="${t('meters.replace.reasonPlaceholder')}">
          </div>
        </div>
      </form>
    `;
    openModal({
      title: t('meters.replace.title', { name: meter.name }),
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--util" data-act="save">${t('meters.replace.submit')}</button>
      `,
      onMount({ modalEl, close }) {
        associateFieldLabels(modalEl);
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        const saveBtn = modalEl.querySelector('[data-act="save"]');
        saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
          const f = modalEl.querySelector('#replace-form');
          // v2.5.3 — Leere Felder sind Fehler, keine 0. Ein Tausch mit
          // Endstand 0 schloss das alte Gerät scheinbar sauber ab und ließ
          // sich in der Oberfläche nicht rückgängig machen (UI-03, Issue #13).
          const oldFinal = parseDecimal(f.old_final_counter.value);
          const newInit  = parseDecimal(f.new_initial_counter.value);
          const invalid  = t('common.invalidNumber', { example: formatForInput(1234.5) });
          if (!f.date.value) { toastErr(t('utility.readingModal.validation')); return; }
          if (oldFinal == null || oldFinal < 0) { showFieldError(f.old_final_counter, modalEl.querySelector('#rp-old-msg'), invalid); return; }
          if (newInit == null || newInit < 0)   { showFieldError(f.new_initial_counter, modalEl.querySelector('#rp-new-msg'), invalid); return; }
          if (last && oldFinal < Number(last.counter)) {
            const okLower = await confirmModal({
              message: t('meters.replace.confirmLower', {
                oldFinal: fmt.num(oldFinal, 2), last: fmt.num(last.counter, 2), unit: u.unit, lastDate: fmt.date(last.date),
              }),
              confirmLabel: t('utility.readingModal.confirmLowerOk'),
            });
            if (!okLower) return;
          }
          const ok = await confirmModal({
            title: t('meters.replace.title', { name: meter.name }),
            message: t('meters.replace.confirmSummary', {
              date: fmt.date(f.date.value), oldFinal: fmt.num(oldFinal, 2), newInitial: fmt.num(newInit, 2), unit: u.unit,
            }),
            confirmLabel: t('meters.replace.submit'),
          });
          if (!ok) return;
          try {
            await api.replaceDevice(u.key, meter.id, {
              date: f.date.value,
              old_final_counter:   oldFinal,
              new_initial_counter: newInit,
              serial: f.serial.value || null,
              reason: f.reason.value || null,
            });
            toastOk(t('meters.replace.done'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        }));
      }
    });
  });
}

// ───── CSV reading import (F-06) ────────────────────────────────────
// Bulk-imports meter readings from a CSV into one specific meter.
// Existing readings on the same date are overwritten and reported.
async function openImportReadingsModal(u, meter) {
  if (meter.capture === 'period') return openImportPeriodsModal(u, meter);
  return new Promise(resolve => {
    const body = `
      <p>${t('meters.import.intro', { name: escapeHtml(meter.name) })}</p>
      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:12px 0;font-size:12px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:6px">${t('meters.import.formatLabel')}</div>
        ${exampleReadingsCsv().trim().split('\n').map((l, i) => `<code class="mono" style="display:block;color:var(${i ? '--text-2' : '--text-1'})">${escapeHtml(l)}</code>`).join('')}
        <div style="margin-top:8px;color:var(--text-2)">
          ${t('meters.import.formatHint')}
        </div>
        <button type="button" class="btn btn--sm btn--ghost" id="dl-example" style="margin-top:10px"><span aria-hidden="true">⬇</span> ${t('meters.import.downloadExample')}</button>
      </div>
      <div class="drop-zone" id="import-drop" role="button" tabindex="0" aria-label="${t('meters.import.dropZoneAria')}">
        <p>${t('meters.import.dropZone')}</p>
        <input type="file" id="import-csv-input" accept=".csv,text/csv,text/plain" style="display:none">
      </div>
      <div id="import-result" style="margin-top:12px"></div>
    `;
    openModal({
      title: t('meters.import.title', { name: meter.name }),
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('meters.import.close')}</button>
      `,
      onMount({ modalEl, close }) {
        let didImport = false;
        const drop   = modalEl.querySelector('#import-drop');
        const input  = modalEl.querySelector('#import-csv-input');
        const result = modalEl.querySelector('#import-result');

        // v2.12.0 (Review UI-23) — zweistufig: erst lesen und zeigen, was
        // sich ändert (Trockenlauf), dann auf Knopfdruck importieren. Bis v2.11
        // schrieb schon die Dateiauswahl; Tippfehler landeten ungeprüft.
        const handleFile = async (file) => {
          if (!file) return;
          result.innerHTML = `<p class="muted">${t('meters.import.reading')}</p>`;
          try {
            const text = await file.text();
            const [preview, existing] = await Promise.all([
              api.importReadingCsv(u.key, meter.id, text, { dryRun: true }),
              api.readings(u.key, meter.id).catch(() => []),
            ]);
            drop.hidden = true;
            result.innerHTML = previewHtml(u, meter, preview, Array.isArray(existing) ? existing : []);
            result.querySelector('[data-act="other-file"]')?.addEventListener('click', () => {
              result.innerHTML = ''; drop.hidden = false; input.value = ''; drop.focus();
            });
            result.querySelector('[data-act="import"]')?.addEventListener('click', () => runImport(text));
          } catch (e) {
            result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`;
          }
        };

        const runImport = async (text) => {
          result.innerHTML = `<p class="muted">${t('meters.import.importing')}</p>`;
          try {
            const res = await api.importReadingCsv(u.key, meter.id, text);
            didImport = didImport || (res.imported > 0 || res.overwritten > 0);
            const errs = res.errors || [];
            result.innerHTML = `
              <div class="banner ${res.skipped || errs.length ? 'banner--warning' : 'banner--success'}" style="font-size:12px">
                <div>${t('meters.import.result', { imported: res.imported, overwritten: res.overwritten, skipped: res.skipped })}</div>
                ${errs.length ? `<div style="margin-top:6px;color:var(--text-2)">
                  ${errs.slice(0, 8).map(e => `· ${escapeHtml(e)}`).join('<br>')}
                  ${errs.length > 8 ? `<br>${t('meters.import.moreErrors', { count: errs.length - 8 })}` : ''}
                </div>` : ''}
              </div>
            `;
            if (res.imported > 0 || res.overwritten > 0) {
              toastOk(tp('meters.import.imported', res.imported + res.overwritten));
            }
            drop.hidden = false; input.value = '';
          } catch (e) {
            result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`;
          }
        };

        // B — Beispiel-CSV als Datei erzeugen und herunterladen.
        modalEl.querySelector('#dl-example')?.addEventListener('click', () => {
          downloadExample(exampleReadingsCsv(), 'exampleReadings');
        });

        drop.addEventListener('click', () => input.click());
        // A11y (N1009): role="button" — Enter/Leertaste lösen die Dateiwahl aus.
        drop.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', (e) => {
          e.preventDefault(); drop.classList.remove('dragover');
          handleFile(e.dataTransfer.files[0]);
        });
        input.addEventListener('change', (e) => handleFile(e.target.files[0]));

        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => {
          close(didImport); resolve(didImport);
        });
      }
    });
  });
}

/**
 * v3.1.0 (H3, B2) — CSV-Import für Zähler mit Verbrauch je Zeitraum:
 * `von;bis;wert[;notiz]` oder `monat;wert`. Erst Trockenlauf, dann Import;
 * überlappende Zeilen bleiben draußen und werden genannt.
 */
async function openImportPeriodsModal(u, meter) {
  const unit = u.consumption_unit || u.unit || '';
  return new Promise(resolve => {
    openModal({
      title: t('meters.import.title', { name: meter.name }),
      body: `
        <p>${t('meters.periodImport.intro', { name: escapeHtml(meter.name) })}</p>
        <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:12px 0;font-size:12px">
          <code class="mono" style="display:block">2026-01;812</code>
          <code class="mono" style="display:block">${escapeHtml(fmt.date('2026-02-01'))};${escapeHtml(fmt.date('2026-02-28'))};745</code>
          <div style="margin-top:8px;color:var(--text-2)">${t('meters.periodImport.formatHint', { unit: escapeHtml(unit) })}</div>
        </div>
        <div class="drop-zone" id="pimport-drop" role="button" tabindex="0" aria-label="${t('meters.import.dropZoneAria')}">
          <p>${t('meters.import.dropZone')}</p>
          <input type="file" id="pimport-input" accept=".csv,text/csv,text/plain" style="display:none">
        </div>
        <div id="pimport-result" style="margin-top:12px"></div>`,
      footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${t('meters.import.close')}</button>`,
      onMount({ modalEl, close }) {
        let done = false;
        const drop = modalEl.querySelector('#pimport-drop');
        const input = modalEl.querySelector('#pimport-input');
        const result = modalEl.querySelector('#pimport-result');
        const errorsHtml = (errs) => errs.length
          ? `<ul class="import-preview__errors">${errs.slice(0, 8).map(e => `<li>${escapeHtml(e)}</li>`).join('')}${errs.length > 8 ? `<li>${t('meters.import.moreErrors', { count: errs.length - 8 })}</li>` : ''}</ul>` : '';
        const handle = async (file) => {
          if (!file) return;
          const text = await file.text();
          result.innerHTML = `<p class="muted">${t('meters.import.reading')}</p>`;
          try {
            const p = await api.importPeriodCsv(u.key, meter.id, text, { dryRun: true });
            const n = p.would_import ?? 0;
            drop.hidden = true;
            result.innerHTML = `
              <div class="import-preview">
                <p class="import-preview__summary">${escapeHtml(tp('meters.periodImport.previewSummary', n))}</p>
                ${errorsHtml(p.errors || [])}
                ${(p.rows || []).length ? `<div class="table-wrap"><table class="data-table import-preview__table"><thead><tr>
                  <th scope="col">${t('utility.periods.colPeriod')}</th><th scope="col" class="num">${t('utility.periods.colValue', { unit: escapeHtml(unit) })}</th>
                </tr></thead><tbody>${p.rows.slice(0, 50).map(r => `<tr><td>${fmt.date(r.from)} – ${fmt.date(r.to)}</td><td class="num">${fmt.dec(r.value, 3)}</td></tr>`).join('')}</tbody></table></div>` : ''}
                <div class="import-preview__actions">
                  <button type="button" class="btn btn--ghost" data-act="other-file">${t('meters.import.otherFile')}</button>
                  ${n ? `<button type="button" class="btn btn--primary" data-act="import">${escapeHtml(tp('meters.periodImport.confirm', n))}</button>` : ''}
                </div>
              </div>`;
            result.querySelector('[data-act="other-file"]')?.addEventListener('click', () => { result.innerHTML = ''; drop.hidden = false; input.value = ''; });
            result.querySelector('[data-act="import"]')?.addEventListener('click', async () => {
              try {
                const res = await api.importPeriodCsv(u.key, meter.id, text);
                done = done || res.imported > 0;
                result.innerHTML = `<div class="banner ${res.skipped ? 'banner--warning' : 'banner--success'}" style="font-size:12px">
                  ${escapeHtml(tp('meters.periodImport.result', res.imported, { skipped: res.skipped }))}${errorsHtml(res.errors || [])}</div>`;
                if (res.imported) toastOk(tp('meters.periodImport.result', res.imported, { skipped: res.skipped }));
                drop.hidden = false; input.value = '';
              } catch (e) { result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`; }
            });
          } catch (e) {
            result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`;
          }
        };
        drop.addEventListener('click', () => input.click());
        drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
        drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('dragover'); handle(e.dataTransfer.files[0]); });
        input.addEventListener('change', (e) => handle(e.target.files[0]));
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(done); resolve(done); });
      },
    });
  });
}

/**
 * v2.12.0 (Review UI-23) — Vorschau eines CSV-Imports. Jede gelesene Zeile
 * mit ihrer Wirkung (neu, ersetzt, unverändert) und den Rückfragen der
 * Erfassung (lib/plausibility.js): Rückgang, Sprung, Größenordnung, Zukunft.
 * Verglichen wird mit dem jeweils vorigen Stand aus Bestand und Datei.
 */
function previewHtml(u, meter, preview, existing) {
  const rows = [...(preview.rows || [])].map((r, i) => ({ ...r, i })).sort((a, b) => a.date.localeCompare(b.date) || a.i - b.i);
  const unit = u.unit || '';
  const today = todayIso();
  const byDate = new Map(existing.map(e => [e.date, e]));
  const typical = typicalPerDay(existing.filter(e => (e.meter_id ?? meter.id) === meter.id));
  const dates = rows.map(r => r.date);
  let counts = { new: 0, overwrite: 0, same: 0, flagged: 0 };
  const items = rows.map((r, idx) => {
    const old = byDate.get(r.date) || null;
    const effect = !old ? 'new' : Number(old.counter) === Number(r.counter) ? 'same' : 'overwrite';
    // voriger Stand: der jüngste aus Bestand und Datei vor diesem Datum
    const prevExisting = existing.filter(e => e.date < r.date && !e.is_future).sort((a, b) => a.date.localeCompare(b.date)).pop() || null;
    const prevFile = rows.slice(0, idx).filter(p => p.date < r.date).pop() || null;
    const prev = [prevExisting, prevFile].filter(Boolean).sort((a, b) => a.date.localeCompare(b.date)).pop() || null;
    const issues = checkReading({
      value: Number(r.counter), date: r.date, today, prev, typical,
      deviceChanged: prev ? deviceChangedBetween(meter, prev.date, r.date) : false,
    });
    const notes = issues.map(i => issueText(i, { unit, date: r.date }));
    if (dates.indexOf(r.date) !== dates.lastIndexOf(r.date)) notes.push(t('meters.import.duplicate'));
    counts[effect]++;
    if (notes.length) counts.flagged++;
    return { r, effect, old, notes };
  });
  const LIMIT = 50;
  const flagged = items.filter(x => x.notes.length);
  const plain = items.filter(x => !x.notes.length);
  const shown = [...flagged, ...plain.slice(0, Math.max(0, LIMIT - flagged.length))]
    .sort((a, b) => a.r.date.localeCompare(b.r.date) || a.r.i - b.r.i);
  const hidden = items.length - shown.length;
  const errs = preview.errors || [];
  const importable = counts.new + counts.overwrite + counts.same;
  const effectText = (x) => x.effect === 'overwrite'
    ? t('meters.import.effect.overwrite', { counter: `${fmt.dec(x.old.counter, 3)} ${unit}` })
    : t('meters.import.effect.' + x.effect);
  return `
    <div class="import-preview">
      <p class="import-preview__summary">${escapeHtml(t('meters.import.previewSummary', { rows: items.length, ...counts }))}</p>
      ${preview.skipped ? `<p class="muted small">${escapeHtml(tp('meters.import.unreadable', preview.skipped))}</p>` : ''}
      ${preview.other_meter_rows ? `<p class="muted small">${escapeHtml(tp('meters.import.otherMeterRows', preview.other_meter_rows))}</p>` : ''}
      ${errs.length ? `<ul class="import-preview__errors">${errs.slice(0, 8).map(e => `<li>${escapeHtml(e)}</li>`).join('')}${errs.length > 8 ? `<li>${t('meters.import.moreErrors', { count: errs.length - 8 })}</li>` : ''}</ul>` : ''}
      ${items.length ? `
      <div class="table-wrap"><table class="data-table import-preview__table">
        <thead><tr>
          <th scope="col">${t('meters.import.col.date')}</th>
          <th scope="col" class="num">${t('meters.import.col.counter')}</th>
          <th scope="col">${t('meters.import.col.effect')}</th>
          <th scope="col">${t('meters.import.col.notes')}</th>
        </tr></thead>
        <tbody>${shown.map(x => `
          <tr class="${x.notes.length ? 'import-preview__row--warn' : ''}">
            <td>${fmt.date(x.r.date)}</td>
            <td class="num">${fmt.dec(x.r.counter, 3)} ${escapeHtml(unit)}</td>
            <td>${escapeHtml(effectText(x))}</td>
            <td>${x.notes.map(n => escapeHtml(n)).join('<br>')}</td>
          </tr>`).join('')}
        </tbody>
      </table></div>
      ${hidden > 0 ? `<p class="muted small">${escapeHtml(tp('meters.import.moreRows', hidden))}</p>` : ''}`
      : `<div class="banner banner--warning" style="font-size:12px">${t('meters.import.nothing')}</div>`}
      <div class="import-preview__actions">
        <button type="button" class="btn btn--ghost" data-act="other-file">${t('meters.import.otherFile')}</button>
        ${importable ? `<button type="button" class="btn btn--primary" data-act="import">${escapeHtml(tp('meters.import.confirm', importable))}</button>` : ''}
      </div>
    </div>`;
}

// ───── Merge-Wizard (F1006) ─────────────────────────────────────────
// Führt mehrere bestehende Zähler zu einer Gruppe zusammen (z. B. NT + HT
// Strom). Entweder neue Gruppe (Name) oder bestehende Gruppe wählen.
async function openMergeModal(u, meters, groups) {
  return new Promise(resolve => {
    const body = `
      <p>${t('meters.mergeModal.intro')}</p>
      <form id="merge-form">
        <div class="field">
          <label>${t('meters.mergeModal.select')}</label>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:200px;overflow:auto;border:1px solid var(--border,#ccc);border-radius:var(--r-md);padding:8px">
            ${meters.map(m => `
              <label style="display:flex;align-items:center;gap:8px;font-weight:normal">
                <input type="checkbox" name="meter_ids" value="${escapeHtml(m.id)}">
                ${escapeHtml(m.name)}
                ${m.meter_group_id ? `<span class="tag tag--util">${t('meters.mergeModal.alreadyInGroup')}</span>` : ''}
              </label>
            `).join('')}
          </div>
          <!-- v2.12.0 (Review UI-22) — Fehler am Feld statt im Toast -->
          <div class="field-error" data-role="select-msg" role="alert" hidden></div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>${t('meters.mergeModal.existingGroup')}</label>
            <select class="input" name="group_id">
              <option value="">${t('meters.mergeModal.newGroupOption')}</option>
              ${(groups || []).map(g => `<option value="${escapeHtml(g.id)}">${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
          <div class="field">
            <label>${t('meters.mergeModal.newGroupName')}</label>
            <input class="input input--text" name="name" placeholder="${t('meters.mergeModal.newGroupPlaceholder')}">
            <div class="field-error" data-role="name-msg" role="alert" hidden></div>
          </div>
        </div>
      </form>
    `;
    openModal({
      title: t('meters.mergeModal.title'),
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--util" data-act="save">${t('meters.mergeModal.submit')}</button>
      `,
      onMount({ modalEl, close }) {
        associateFieldLabels(modalEl);
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#merge-form');
          const ids = Array.from(f.querySelectorAll('input[name="meter_ids"]:checked')).map(c => c.value);
          const selMsg = f.querySelector('[data-role="select-msg"]');
          const nameMsg = f.querySelector('[data-role="name-msg"]');
          showFieldError(null, selMsg, null);
          showFieldError(f.name, nameMsg, null);
          if (ids.length < 2) {
            showFieldError(null, selMsg, t('meters.mergeModal.needTwo'));
            f.querySelector('input[name="meter_ids"]')?.focus();
            return;
          }
          const groupId = f.group_id.value;
          if (!groupId && !f.name.value.trim()) { showFieldError(f.name, nameMsg, t('meters.mergeModal.needName')); return; }
          try {
            const payload = { meter_ids: ids };
            if (groupId) payload.group_id = groupId;
            else payload.name = f.name.value.trim();
            const res = await api.mergeMeterGroup(u.key, payload);
            toastOk(tp('meters.mergeModal.done', res.members, { name: res.group.name }));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      }
    });
  });
}

// v3.1.0 (H8, MKT-19) — Zeitreihe aus einem Portal (Netzbetreiber, Wechselrichter,
// Wärmepumpe): Spalten zuordnen, als Tageswerte prüfen, übernehmen. Die letzte
// Zuordnung je Zähler merkt sich der Browser.
async function openImportSeriesModal(u, meter) {
  const storeKey = `et-series-map-${u.key}-${meter.id}`;
  let saved = {};
  try { saved = JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; } catch { saved = {}; }
  const kwhUnit = (u.unit || '') === 'kWh';
  return new Promise(resolve => {
    let text = '';
    let ok = false;
    const body = `
      <p class="muted">${t('import.series.intro')}</p>
      <div class="field">
        <label class="btn btn--ghost btn--sm">${t('import.series.pickFile')}
          <input type="file" accept=".csv,.txt,text/csv,text/plain" data-role="series-file" class="sr-only"></label>
        <span class="muted small" data-role="series-name"></span>
      </div>
      <pre class="small" data-role="series-head" style="max-height:8rem;overflow:auto;white-space:pre"></pre>
      <div class="form-row">
        <div class="field"><label for="ser-skip">${t('import.series.skipRows')}</label>
          <input class="input" id="ser-skip" type="text" inputmode="numeric" value="${escapeHtml(String(saved.skip_rows ?? 1))}"></div>
        <div class="field"><label for="ser-date">${t('import.series.dateCol')}</label><select class="select" id="ser-date"></select></div>
        <div class="field"><label for="ser-time">${t('import.series.timeCol')}</label><select class="select" id="ser-time"></select></div>
        <div class="field"><label for="ser-value">${t('import.series.valueCol')}</label><select class="select" id="ser-value"></select></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="ser-kind">${t('import.series.kindLabel')}</label>
          <select class="select" id="ser-kind">${['consumption', 'counter'].map(k => `<option value="${k}" ${saved.value_kind === k ? 'selected' : ''}>${t('import.series.kind.' + k)}</option>`).join('')}</select></div>
        <div class="field"><label for="ser-unit">${t('import.series.unit')}</label>
          <select class="select" id="ser-unit">${(kwhUnit ? [['1', 'kWh'], ['0.001', 'Wh'], ['1000', 'MWh']] : [['1', u.unit || '']]).map(([f, l]) => `<option value="${f}" ${String(saved.unit_factor ?? '1') === f ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('')}</select></div>
        <div class="field"><label for="ser-stamp">${t('import.series.stampLabel')}</label>
          <select class="select" id="ser-stamp">${['start', 'end'].map(k => `<option value="${k}" ${saved.interval_stamp === k ? 'selected' : ''}>${t('import.series.stamp.' + k)}</option>`).join('')}</select></div>
        <div class="field"><label for="ser-anchor">${t('import.series.anchor')}</label>
          <input class="input" id="ser-anchor" type="text" inputmode="decimal" autocomplete="off"></div>
      </div>
      <span class="settings-field__hint">${t('import.series.anchorHint')}</span>
      <div data-role="series-preview" style="margin-top:12px"></div>`;
    openModal({
      title: t('import.series.title', { name: meter.name || meter.id }),
      body, size: 'lg',
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--ghost" data-act="preview">${t('import.series.previewButton')}</button>
        <button type="button" class="btn btn--util" data-act="import" disabled>${t('import.series.importButton')}</button>`,
      onMount({ modalEl, close }) {
        associateFieldLabels(modalEl);
        const q = (s) => modalEl.querySelector(s);
        const fillColumns = () => {
          const lines = text.split(/\r\n|\n|\r/).filter(l => l.trim() !== '');
          q('[data-role="series-head"]').textContent = lines.slice(0, 6).join('\n');
          const skip = Math.max(0, parseInt(q('#ser-skip').value, 10) || 0);
          const sample = lines[skip] || lines[0] || '';
          const sep = sample.includes(';') ? ';' : (sample.includes('\t') ? '\t' : ',');
          const head = skip > 0 ? (lines[skip - 1] || '').split(sep) : [];
          const n = sample.split(sep).length;
          const label = (i) => `${i + 1}: ${(head[i] || t('import.series.column', { n: i + 1 })).trim().slice(0, 40)}`;
          const opts = (sel, withNone) => (withNone ? `<option value="">${t('import.series.none')}</option>` : '')
            + Array.from({ length: n }, (_, i) => `<option value="${i}" ${String(sel) === String(i) ? 'selected' : ''}>${escapeHtml(label(i))}</option>`).join('');
          q('#ser-date').innerHTML = opts(saved.date_col ?? 0, false);
          q('#ser-time').innerHTML = opts(saved.time_col ?? '', true);
          q('#ser-value').innerHTML = opts(saved.value_col ?? Math.min(1, n - 1), false);
        };
        const mapping = () => ({
          skip_rows: Math.max(0, parseInt(q('#ser-skip').value, 10) || 0),
          date_col: Number(q('#ser-date').value || 0),
          time_col: q('#ser-time').value === '' ? null : Number(q('#ser-time').value),
          value_col: Number(q('#ser-value').value || 1),
          value_kind: q('#ser-kind').value,
          unit_factor: Number(q('#ser-unit').value),
          interval_stamp: q('#ser-stamp').value,
          start_counter: q('#ser-anchor').value.trim() === '' ? null : parseDecimal(q('#ser-anchor').value),
        });
        q('[data-role="series-file"]').addEventListener('change', async ev => {
          const file = ev.target.files?.[0];
          if (!file) return;
          text = await file.text();
          q('[data-role="series-name"]').textContent = file.name;
          fillColumns();
        });
        q('#ser-skip').addEventListener('change', () => { if (text) fillColumns(); });
        modalEl.querySelectorAll('select, input').forEach(el => el.addEventListener('change', () => { ok = false; q('[data-act="import"]').disabled = true; }));
        q('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        const previewBtn = q('[data-act="preview"]');
        previewBtn.addEventListener('click', guardSubmit(previewBtn, async () => {
          if (!text) { toastErr(t('import.series.noFile')); return; }
          try {
            const r = await api.importSeries(u.key, meter.id, text, mapping(), { dryRun: true });
            q('[data-role="series-preview"]').innerHTML = `
              <p><strong>${escapeHtml(r.total != null
                ? tp('import.series.summary', r.days, { from: fmt.date(r.from), to: fmt.date(r.to), total: fmt.num(r.total, 1), unit: u.consumption_unit || u.unit })
                : tp('import.series.summaryCounter', r.days, { from: fmt.date(r.from), to: fmt.date(r.to) }))}</strong></p>
              ${r.skipped ? `<p class="muted small">${escapeHtml(tp('import.series.skipped', r.skipped))}</p>` : ''}
              <div class="table-wrap"><table class="table table--compact"><tbody>${(r.preview || []).map(p => `<tr><td>${fmt.date(p.date)}</td><td class="num">${fmt.num(p.value, 3)}</td></tr>`).join('')}</tbody></table></div>`;
            ok = true;
            q('[data-act="import"]').disabled = false;
          } catch (e) { toastErr(e.message); }
        }));
        const importBtn = q('[data-act="import"]');
        importBtn.addEventListener('click', guardSubmit(importBtn, async () => {
          if (!ok) return;
          try {
            const m = mapping();
            await api.importSeries(u.key, meter.id, text, m);
            try { localStorage.setItem(storeKey, JSON.stringify({ ...m, start_counter: undefined })); } catch { /* ohne Speicher */ }
            toastOk(t('import.series.done'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        }));
      },
    });
  });
}
