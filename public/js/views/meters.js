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

export async function render(container, params) {
  const utilityKey = params[0];
  const u = await getUtility(utilityKey);
  if (!u) {
    container.innerHTML = `<div class="banner banner--error">Unbekannte Verbrauchsart: ${escapeHtml(utilityKey)}</div>`;
    return;
  }

  container.setAttribute('data-utility', u.key);
  await refresh(container, u);
}

async function refresh(container, u) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const [meters, groups] = await Promise.all([
    api.meters(u.key),
    api.meterGroups(u.key),
  ]);

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${escapeHtml(u.label)} · Zähler</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">Zur Übersicht</a>
          ${meters.length >= 2 ? '<button type="button" class="btn btn--ghost" data-action="merge-meters">Zähler zusammenführen</button>' : ''}
          <button type="button" class="btn btn--util" data-action="new-meter">+ Neuer Zähler</button>
        </div>
      </div>

      <div class="banner banner--info">
        <strong>Mehrere Zähler pro Verbrauchsart</strong> — z. B. Warmwasser & Heizung (Gas) oder HT/NT & Wallbox (Strom).
        Jeder Zähler hat eine eigene Gerätehistorie (Zählertausch wird verlustfrei berechnet) und kann eigene Verträge tragen.
        <br><strong>Topologie (v1.2.0):</strong> Subzähler werden vom Elternzähler abgezogen; Gruppen fassen mehrere Zähler im Dashboard zusammen.
      </div>

      ${renderGroupsBar(groups)}

      <div id="meters-list">
        ${meters.length === 0 ? '<p class="muted">Noch keine Zähler.</p>' : ''}
        ${renderMeterTree(meters, groups, u)}
      </div>
    </div>
  `;

  container.querySelector('[data-action="new-meter"]').addEventListener('click', () => {
    openMeterModal(u, null, meters, groups).then(changed => { if (changed) refresh(container, u); });
  });

  container.querySelector('[data-action="merge-meters"]')?.addEventListener('click', () => {
    openMergeModal(u, meters, groups).then(changed => { if (changed) refresh(container, u); });
  });

  container.querySelectorAll('[data-delete-group]').forEach(b => {
    b.addEventListener('click', async () => {
      const gid = b.getAttribute('data-delete-group');
      const g = groups.find(x => x.id === gid);
      const ok = await confirmModal({
        title: 'Gruppe auflösen?',
        message: `Gruppe „${g?.name ?? gid}“ auflösen? Die zugeordneten Zähler bleiben erhalten und werden nur aus der Gruppe gelöst.`,
        confirmLabel: 'Auflösen', danger: true
      });
      if (!ok) return;
      try { await api.deleteMeterGroup(u.key, gid); toastOk('Gruppe aufgelöst'); refresh(container, u); }
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
        title: 'Zähler löschen?',
        message: `Zähler „${m.name}“ und seine Gerätehistorie löschen? Ablesungen und Verträge müssen vorher entfernt werden.`,
        confirmLabel: 'Löschen', danger: true
      });
      if (!ok) return;
      try { await api.deleteMeter(u.key, id); toastOk('Zähler gelöscht'); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

// ───── Gruppen-Übersicht + Topologie-Baum ───────────────────────────

function renderGroupsBar(groups) {
  if (!groups || groups.length === 0) return '';
  return `
    <div class="banner" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
      <strong>Zählergruppen:</strong>
      ${groups.map(g => `
        <span class="tag tag--util" style="display:inline-flex;align-items:center;gap:6px">
          ${escapeHtml(g.name)}
          <button type="button" class="btn btn--sm btn--ghost" style="padding:0 6px" title="Gruppe auflösen" data-delete-group="${g.id}">✕</button>
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
          ${meter.active ? '' : '<span class="tag tag--warning">inaktiv</span>'}
          ${isSub ? '<span class="tag">Subzähler</span>' : ''}
          ${gName ? `<span class="tag tag--util">Gruppe: ${escapeHtml(gName)}</span>` : ''}
        </div>
        <div class="meter-card__meta">
          ${devices.length} Gerät${devices.length === 1 ? '' : 'e'}
          ${meter.notes ? ' · ' + escapeHtml(meter.notes) : ''}
        </div>
        <ul class="device-list">
          ${devices.map((d, i) => `
            <li class="${d.removed_on ? 'closed' : 'open'}">
              Gerät ${i + 1}${d.serial ? ' · SN ' + escapeHtml(d.serial) : ''}
              · ${fmt.date(d.installed_on)} ${d.removed_on ? '→ ' + fmt.date(d.removed_on) : '→ aktiv'}
              · Start ${fmt.num(d.initial_counter, 2)} ${u.unit}
              ${d.final_counter != null ? ' · Ende ' + fmt.num(d.final_counter, 2) + ' ' + u.unit : ''}
              ${d.reason ? ' · ' + escapeHtml(d.reason) : ''}
            </li>
          `).join('')}
        </ul>
      </div>
      <div class="row-actions" style="flex-direction:column; gap: 4px">
        <button class="btn btn--sm" data-replace-device="${meter.id}">Zählertausch</button>
        <button class="btn btn--sm" data-import-readings="${meter.id}">CSV-Import</button>
        <button class="btn btn--sm btn--ghost" data-edit-meter="${meter.id}">Bearbeiten</button>
        <button class="btn btn--sm btn--danger" data-delete-meter="${meter.id}">Löschen</button>
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
  return new Promise(resolve => {
    const body = `
      <form id="meter-form">
        <div class="form-row">
          <div class="field">
            <label>Name *</label>
            <input class="input input--text" name="name" required value="${escapeHtml(existing?.name || '')}" placeholder="z. B. Warmwasser, Heizung, HT, Wallbox">
          </div>
          <div class="field">
            <label>Icon</label>
            <input class="input input--text" name="icon" value="${escapeHtml(existing?.icon || u.icon)}">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Elternzähler (Subzähler-Reihenschaltung)</label>
            <select class="input" name="parent_meter_id">
              <option value="">— keiner (eigenständig) —</option>
              ${parentOptions.map(m => `<option value="${m.id}" ${m.id === curParent ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
            </select>
            <small class="muted">Der Verbrauch dieses Zählers wird vom Elternzähler abgezogen.</small>
          </div>
          <div class="field">
            <label>Gruppe (Dashboard-Zusammenfassung)</label>
            <select class="input" name="meter_group_id">
              <option value="">— keine —</option>
              ${(groups || []).map(g => `<option value="${g.id}" ${g.id === curGroup ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        ${existing ? '' : `
          <div class="form-row">
            <div class="field">
              <label>Geräte-Seriennummer (optional)</label>
              <input class="input" name="device_serial" type="text">
            </div>
            <div class="field">
              <label>Einbaudatum</label>
              <input class="input" name="installed_on" type="date" value="${todayIso()}">
            </div>
            <div class="field">
              <label>Anfangsstand (${u.unit})</label>
              <input class="input" name="initial_counter" type="number" step="0.01" value="0">
            </div>
          </div>
        `}
        <div class="field">
          <label>Notizen</label>
          <textarea class="input input--text" name="notes">${escapeHtml(existing?.notes || '')}</textarea>
        </div>
        ${existing ? `
          <div class="field">
            <label><input type="checkbox" name="active" ${existing.active ? 'checked' : ''}> Aktiv</label>
          </div>
        ` : ''}
      </form>
    `;
    openModal({
      title: existing ? 'Zähler bearbeiten' : 'Neuer Zähler',
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
        <button type="button" class="btn btn--util" data-act="save">Speichern</button>
      `,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#meter-form');
          try {
            if (existing) {
              await api.updateMeter(u.key, existing.id, {
                name:   f.name.value,
                icon:   f.icon.value,
                notes:  f.notes.value,
                active: f.active.checked,
                parent_meter_id: f.parent_meter_id.value || null,
                meter_group_id:  f.meter_group_id.value || null,
              });
            } else {
              await api.createMeter(u.key, {
                name:            f.name.value,
                icon:            f.icon.value,
                notes:           f.notes.value,
                device_serial:   f.device_serial.value || null,
                installed_on:    f.installed_on.value,
                initial_counter: Number(f.initial_counter.value),
                parent_meter_id: f.parent_meter_id.value || null,
                meter_group_id:  f.meter_group_id.value || null,
              });
            }
            toastOk('Zähler gespeichert');
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
      <p>Schließt das aktuell offene Gerät mit einem Endstand und legt ein neues Gerät an.
         Der Verbrauch über den Tausch hinweg wird automatisch korrekt verrechnet —
         <em>kein „negativer Verbrauch" mehr nach Tausch</em>.</p>
        <form id="replace-form">
        <div class="form-row">
          <div class="field">
            <label>Tausch-Datum *</label>
            <input class="input" name="date" type="date" required value="${todayIso()}">
          </div>
          <div class="field">
            <label>Endstand alter Zähler (${u.unit}) *</label>
            <input class="input" name="old_final_counter" type="number" step="0.01" required>
          </div>
          <div class="field">
            <label>Anfangsstand neuer Zähler (${u.unit}) *</label>
            <input class="input" name="new_initial_counter" type="number" step="0.01" required value="0">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Seriennummer neu</label>
            <input class="input" name="serial" type="text">
          </div>
          <div class="field">
            <label>Grund</label>
            <input class="input input--text" name="reason" type="text" placeholder="z. B. Eichung, Defekt">
          </div>
        </div>
      </form>
    `;
    openModal({
      title: `Zählertausch: ${meter.name}`,
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
        <button type="button" class="btn btn--util" data-act="save">Tausch durchführen</button>
      `,
      onMount({ modalEl, close }) {
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
            toastOk('Zähler getauscht');
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
      <p>
        Importiert Ablesungen aus einer CSV-Datei direkt in den Zähler
        <strong>${escapeHtml(meter.name)}</strong>. Eine bereits vorhandene
        Ablesung am selben Datum wird <strong>überschrieben</strong> und im
        Ergebnis gemeldet.
      </p>
      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:12px 0;font-size:12px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:6px">Erwartetes Format</div>
        <code class="mono" style="display:block;color:var(--text-1)">datum;zaehlerstand;notiz;geschaetzt</code>
        <code class="mono" style="display:block;color:var(--text-2)">01.02.2026;12345,6;Jahresanfang;false</code>
        <code class="mono" style="display:block;color:var(--text-2)">2026-03-01;12567.8;;ja</code>
        <div style="margin-top:8px;color:var(--text-2)">
          Trenner <code>;</code> oder <code>,</code> · Datum <code>TT.MM.JJJJ</code> oder
          <code>JJJJ-MM-TT</code> · Dezimalkomma erlaubt · Spalten <em>Notiz</em> und
          <em>geschätzt</em> optional · eine Kopfzeile wird automatisch erkannt.
        </div>
      </div>
      <div class="drop-zone" id="import-drop">
        <p>CSV-Datei hier ablegen oder klicken zum Auswählen</p>
        <input type="file" id="import-csv-input" accept=".csv,text/csv,text/plain" style="display:none">
      </div>
      <div id="import-result" style="margin-top:12px"></div>
    `;
    openModal({
      title: `Ablesungen importieren: ${meter.name}`,
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Schließen</button>
      `,
      onMount({ modalEl, close }) {
        let didImport = false;
        const drop   = modalEl.querySelector('#import-drop');
        const input  = modalEl.querySelector('#import-csv-input');
        const result = modalEl.querySelector('#import-result');

        const handleFile = async (file) => {
          if (!file) return;
          result.innerHTML = '<p class="muted">Importiere…</p>';
          try {
            const text = await file.text();
            const res = await api.importReadingCsv(u.key, meter.id, text);
            didImport = didImport || (res.imported > 0 || res.overwritten > 0);
            const errs = res.errors || [];
            result.innerHTML = `
              <div class="banner ${res.skipped || errs.length ? 'banner--warning' : 'banner--success'}" style="font-size:12px">
                <div><strong>${res.imported}</strong> neu importiert ·
                     <strong>${res.overwritten}</strong> überschrieben ·
                     <strong>${res.skipped}</strong> übersprungen</div>
                ${errs.length ? `<div style="margin-top:6px;color:var(--text-2)">
                  ${errs.slice(0, 8).map(e => `· ${escapeHtml(e)}`).join('<br>')}
                  ${errs.length > 8 ? `<br>… und ${errs.length - 8} weitere` : ''}
                </div>` : ''}
              </div>
            `;
            if (res.imported > 0 || res.overwritten > 0) {
              toastOk(`${res.imported + res.overwritten} Ablesung(en) übernommen`);
            }
          } catch (e) {
            result.innerHTML = `<div class="banner banner--error" style="font-size:12px">${escapeHtml(e.message)}</div>`;
          }
        };

        drop.addEventListener('click', () => input.click());
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
      <p>Mehrere Zähler zu einer <strong>Gruppe</strong> zusammenführen — z. B.
         <em>NT + HT Strom</em> oder mehrere Wallboxen. Die Gruppe fasst die
         Verbräuche im Dashboard zusammen; die einzelnen Zähler bleiben
         erhalten.</p>
      <form id="merge-form">
        <div class="field">
          <label>Zähler auswählen (mindestens zwei) *</label>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:200px;overflow:auto;border:1px solid var(--border,#ccc);border-radius:var(--r-md);padding:8px">
            ${meters.map(m => `
              <label style="display:flex;align-items:center;gap:8px;font-weight:normal">
                <input type="checkbox" name="meter_ids" value="${m.id}">
                ${escapeHtml(m.name)}
                ${m.meter_group_id ? '<span class="tag tag--util">bereits in Gruppe</span>' : ''}
              </label>
            `).join('')}
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Bestehende Gruppe</label>
            <select class="input" name="group_id">
              <option value="">— neue Gruppe anlegen —</option>
              ${(groups || []).map(g => `<option value="${g.id}">${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
          <div class="field">
            <label>Name der neuen Gruppe</label>
            <input class="input input--text" name="name" placeholder="z. B. Strom NT+HT">
          </div>
        </div>
      </form>
    `;
    openModal({
      title: 'Zähler zusammenführen',
      body,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
        <button type="button" class="btn btn--util" data-act="save">Zusammenführen</button>
      `,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#merge-form');
          const ids = Array.from(f.querySelectorAll('input[name="meter_ids"]:checked')).map(c => c.value);
          if (ids.length < 2) { toastErr('Bitte mindestens zwei Zähler auswählen'); return; }
          const groupId = f.group_id.value;
          if (!groupId && !f.name.value.trim()) { toastErr('Bitte einen Gruppennamen angeben oder eine bestehende Gruppe wählen'); return; }
          try {
            const payload = { meter_ids: ids };
            if (groupId) payload.group_id = groupId;
            else payload.name = f.name.value.trim();
            const res = await api.mergeMeterGroup(u.key, payload);
            toastOk(`${res.members} Zähler in Gruppe „${res.group.name}“ zusammengeführt`);
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      }
    });
  });
}
