// =====================================================================
// Energietracker v1.1.0 — Meters view
// F2 (Zählertausch) and F3 (multiple meters per utility).
// One row per meter showing its device chain. Buttons: add meter,
// replace device, CSV import (F-06), edit, delete.
// =====================================================================

import { api } from '../api.js';
import { getUtility } from '../state.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';
import { t } from '../lib/i18n.js';
import { associateFieldLabels } from '../lib/a11y.js';

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
  const [meters, groups] = await Promise.all([
    api.meters(u.key),
    api.meterGroups(u.key),
  ]);

  // C — Übersichtszeile aus den bereits geladenen Daten.
  const activeCount = meters.filter(m => m.active !== false).length;
  const subCount    = meters.filter(m => m.parent_meter_id).length;
  const summaryLine = meters.length ? [
    t('meters.summary.meters', { count: meters.length }),
    t('meters.summary.active', { count: activeCount }),
    ...(subCount ? [t('meters.summary.sub', { count: subCount })] : []),
    ...(groups.length ? [t('meters.summary.groups', { count: groups.length })] : []),
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
          <button type="button" class="btn btn--sm btn--ghost" style="padding:0 6px" title="${t('meters.groupsBar.dissolveTitle')}" aria-label="${t('meters.groupsBar.dissolveTitle')}" data-delete-group="${g.id}"><span aria-hidden="true">✕</span></button>
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
        </div>
        <div class="meter-card__meta">
          ${devices.length === 1 ? t('meters.card.devicesOne', { count: devices.length }) : t('meters.card.devices', { count: devices.length })}
          ${meter.notes ? ' · ' + escapeHtml(meter.notes) : ''}
        </div>
        <ul class="device-list">
          ${devices.map((d, i) => `
            <li class="${d.removed_on ? 'closed' : 'open'}">
              ${t('meters.card.deviceLine', { n: i + 1 })}${d.serial ? ' · ' + t('meters.card.serial', { serial: escapeHtml(d.serial) }) : ''}
              · ${fmt.date(d.installed_on)} ${d.removed_on ? '→ ' + fmt.date(d.removed_on) : t('meters.card.active')}
              · ${t('meters.card.start', { value: fmt.num(d.initial_counter, 2), unit: u.unit })}
              ${d.final_counter != null ? ' · ' + t('meters.card.end', { value: fmt.num(d.final_counter, 2), unit: u.unit }) : ''}
              ${d.reason ? ' · ' + escapeHtml(d.reason) : ''}
            </li>
          `).join('')}
        </ul>
      </div>
      <div class="row-actions" style="flex-direction:column; gap: 4px">
        <button class="btn btn--sm" data-replace-device="${meter.id}">${t('meters.card.replace')}</button>
        <button class="btn btn--sm" data-import-readings="${meter.id}">${t('meters.card.csvImport')}</button>
        <button class="btn btn--sm btn--ghost" data-edit-meter="${meter.id}">${t('meters.card.edit')}</button>
        <button class="btn btn--sm btn--danger" data-delete-meter="${meter.id}">${t('meters.card.delete')}</button>
      </div>
    </div>
  `;
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
              ${parentOptions.map(m => `<option value="${m.id}" ${m.id === curParent ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
            </select>
            <small class="muted">${t('meters.modal.parentHint')}</small>
          </div>
          <div class="field">
            <label>${t('meters.modal.group')}</label>
            <select class="input" name="meter_group_id">
              <option value="">${t('meters.modal.groupNone')}</option>
              ${(groups || []).map(g => `<option value="${g.id}" ${g.id === curGroup ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}
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
              <input class="input" name="initial_counter" type="number" step="0.01" value="0">
            </div>
            `}
          </div>
        `}
        ${isDelivery ? `
          <div class="form-row">
            <div class="field">
              <label>${t('meters.modal.capacity', { unit: volUnit })}</label>
              <input class="input" name="capacity" type="number" step="0.01" min="0.01" required value="${existing?.capacity ?? ''}">
            </div>
            <div class="field">
              <label>${t('meters.modal.initialStock', { unit: volUnit })}</label>
              <input class="input" name="initial_stock" type="number" step="0.01" min="0" required value="${existing?.initial_stock ?? ''}">
            </div>
          </div>
        ` : ''}
        <div class="field">
          <label>${t('meters.modal.notes')}</label>
          <textarea class="input input--text" name="notes">${escapeHtml(existing?.notes || '')}</textarea>
        </div>
        <div class="field">
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
        ${existing ? `
          <div class="field">
            <label><input type="checkbox" name="active" ${existing.active ? 'checked' : ''}> ${t('meters.modal.active')}</label>
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
              <strong>${escapeHtml(e.date)}</strong>
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

        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#meter-form');
          // D (v2.1.4) — Tank-Kapazität sofort clientseitig prüfen statt erst den
          // Backend-Fehler abzuwarten (gleiche lokalisierte Meldung).
          if (isDelivery && !(Number(f.capacity.value) > 0)) {
            toastErr(t('errors.meter.capacityRequired', { label: u.label }));
            return;
          }
          try {
            if (existing) {
              const payload = {
                name:   f.name.value,
                icon:   f.icon.value,
                notes:  f.notes.value,
                active: f.active.checked,
                parent_meter_id: f.parent_meter_id.value || null,
                meter_group_id:  f.meter_group_id.value || null,
                baseline_events: baselineEvents,   // F1011
              };
              // v2.1.1 — Fix #18: Tank-Felder mitsenden (nur Heizöl/Pellets).
              if (isDelivery) {
                payload.capacity      = Number(f.capacity.value);
                payload.initial_stock = Number(f.initial_stock.value);
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
              };
              // v2.1.1 — Fix #18: Delivery-Utilities bekommen Tank-Kapazität +
              // Anfangsbestand statt eines kumulativen Anfangsstands.
              if (isDelivery) {
                payload.capacity      = Number(f.capacity.value);
                payload.initial_stock = Number(f.initial_stock.value);
              } else {
                payload.initial_counter = Number(f.initial_counter.value);
              }
              await api.createMeter(u.key, payload);
            }
            toastOk(t('meters.modal.saved'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      }
    });
  });
}

// ───── Device replacement (F2) ──────────────────────────────────────
async function openReplaceDeviceModal(u, meter) {
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
            <input class="input" name="old_final_counter" type="number" step="0.01" required>
          </div>
          <div class="field">
            <label>${t('meters.replace.newInitial', { unit: u.unit })}</label>
            <input class="input" name="new_initial_counter" type="number" step="0.01" required value="0">
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
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#replace-form');
          try {
            await api.replaceDevice(u.key, meter.id, {
              date: f.date.value,
              old_final_counter:   Number(f.old_final_counter.value),
              new_initial_counter: Number(f.new_initial_counter.value),
              serial: f.serial.value || null,
              reason: f.reason.value || null,
            });
            toastOk(t('meters.replace.done'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      }
    });
  });
}

// ───── CSV reading import (F-06) ────────────────────────────────────
// Bulk-imports meter readings from a CSV into one specific meter.
// Existing readings on the same date are overwritten and reported.
async function openImportReadingsModal(u, meter) {
  return new Promise(resolve => {
    const body = `
      <p>${t('meters.import.intro', { name: escapeHtml(meter.name) })}</p>
      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:12px 0;font-size:12px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:6px">${t('meters.import.formatLabel')}</div>
        <code class="mono" style="display:block;color:var(--text-1)">datum;zaehlerstand;notiz;geschaetzt</code>
        <code class="mono" style="display:block;color:var(--text-2)">01.02.2026;12345,6;Jahresanfang;false</code>
        <code class="mono" style="display:block;color:var(--text-2)">2026-03-01;12567.8;;ja</code>
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

        const handleFile = async (file) => {
          if (!file) return;
          result.innerHTML = `<p class="muted">${t('meters.import.importing')}</p>`;
          try {
            const text = await file.text();
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
              toastOk(t('meters.import.toast', { count: res.imported + res.overwritten }));
            }
          } catch (e) {
            result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`;
          }
        };

        // B — Beispiel-CSV als Datei erzeugen und herunterladen.
        modalEl.querySelector('#dl-example')?.addEventListener('click', () => {
          const csv = 'datum;zaehlerstand;notiz;geschaetzt\n01.02.2026;12345,6;Jahresanfang;false\n2026-03-01;12567.8;;ja\n';
          const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = 'beispiel-ablesungen.csv';
          a.click();
          URL.revokeObjectURL(a.href);
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
                <input type="checkbox" name="meter_ids" value="${m.id}">
                ${escapeHtml(m.name)}
                ${m.meter_group_id ? `<span class="tag tag--util">${t('meters.mergeModal.alreadyInGroup')}</span>` : ''}
              </label>
            `).join('')}
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>${t('meters.mergeModal.existingGroup')}</label>
            <select class="input" name="group_id">
              <option value="">${t('meters.mergeModal.newGroupOption')}</option>
              ${(groups || []).map(g => `<option value="${g.id}">${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
          <div class="field">
            <label>${t('meters.mergeModal.newGroupName')}</label>
            <input class="input input--text" name="name" placeholder="${t('meters.mergeModal.newGroupPlaceholder')}">
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
          if (ids.length < 2) { toastErr(t('meters.mergeModal.needTwo')); return; }
          const groupId = f.group_id.value;
          if (!groupId && !f.name.value.trim()) { toastErr(t('meters.mergeModal.needName')); return; }
          try {
            const payload = { meter_ids: ids };
            if (groupId) payload.group_id = groupId;
            else payload.name = f.name.value.trim();
            const res = await api.mergeMeterGroup(u.key, payload);
            toastOk(t('meters.mergeModal.done', { count: res.members, name: res.group.name }));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      }
    });
  });
}
