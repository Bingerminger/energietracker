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
  const meters = await api.meters(u.key);

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${escapeHtml(u.label)} · Zähler</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">Zur Übersicht</a>
          <button type="button" class="btn btn--util" data-action="new-meter">+ Neuer Zähler</button>
        </div>
      </div>

      <div class="banner banner--info">
        <strong>Mehrere Zähler pro Verbrauchsart</strong> — z. B. Warmwasser & Heizung (Gas) oder HT/NT & Wallbox (Strom).
        Jeder Zähler hat eine eigene Gerätehistorie (Zählertausch wird verlustfrei berechnet) und kann eigene Verträge tragen.
      </div>

      <div id="meters-list">
        ${meters.length === 0 ? '<p class="muted">Noch keine Zähler.</p>' : ''}
        ${meters.map(m => renderMeterCard(m, u)).join('')}
      </div>
    </div>
  `;

  container.querySelector('[data-action="new-meter"]').addEventListener('click', () => {
    openMeterModal(u, null).then(changed => { if (changed) refresh(container, u); });
  });

  container.querySelectorAll('[data-edit-meter]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-meter');
      const m = meters.find(x => x.id === id);
      const changed = await openMeterModal(u, m);
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

function renderMeterCard(meter, u) {
  const devices = meter.devices || [];
  return `
    <div class="meter-card" data-utility="${u.key}">
      <div class="meter-card__icon">${escapeHtml(meter.icon || u.icon)}</div>
      <div class="meter-card__main">
        <div class="meter-card__name">
          ${escapeHtml(meter.name)}
          ${meter.active ? '' : '<span class="tag tag--warning">inaktiv</span>'}
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
async function openMeterModal(u, existing) {
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
              });
            } else {
              await api.createMeter(u.key, {
                name:            f.name.value,
                icon:            f.icon.value,
                notes:           f.notes.value,
                device_serial:   f.device_serial.value || null,
                installed_on:    f.installed_on.value,
                initial_counter: Number(f.initial_counter.value),
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
