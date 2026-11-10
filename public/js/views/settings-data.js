// =====================================================================
// Einstellungen › Daten: CSV-Export, Sicherung, Wiederherstellung.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { api } from '../api.js';
import { fmt, escapeHtml, todayIso, intlLocale } from '../lib/format.js';
import { toastOk, toastErr, toastAfterReload } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';
import { t, tp, getLocale, getLanguages } from '../lib/i18n.js';
import { loadDemo } from '../lib/demo.js';
import { icon } from '../lib/icons.js';
import { unlistenAll } from './settings-shared.js';
import { exportActive } from './settings-fields.js';


// ── Seite „Daten": Export, Sicherung, Demo-Daten ───────────────────────────
// v3.1.0 (Review I18N-10) — CSV in der Standardsprache oder als Format 1; die
// Wahl merkt sich der Browser.
const CSV_FORMAT_KEY = 'et-csv-format';

function csvFormat() {
  try { return localStorage.getItem(CSV_FORMAT_KEY) === '1' ? '1' : 'local'; } catch { return 'local'; }
}

function csvUrl(kind, utility, format) {
  if (kind === 'monthly') return api.exportMonthlyCsvUrl(utility, format);
  if (kind === 'billing') return api.exportBillingCsvUrl(utility, format);   // v3.6.0 (F1030)
  if (kind === 'deliveries') return api.exportDeliveriesCsvUrl(utility, format);
  if (kind === 'temperatures') return api.exportTemperaturesCsvUrl(format);
  return api.exportReadingsCsvUrl(utility, format);
}


export function renderExportCard(settings, utilities) {
  const fmt = csvFormat();
  const langName = getLanguages()[settings.language] || getLanguages()[getLocale()] || '';
  return `
    <div class="card settings-card settings-card--wide" id="export-card" data-field="export">
      <h2 class="card__title">${icon('hoch')} ${t('settings.export.title')}</h2>
      <p class="muted" style="margin-bottom: var(--sp-4)">
        ${t('settings.export.hint')}
      </p>
      <div class="field settings-field" style="margin-bottom: var(--sp-4)">
        <label for="csv-format">${t('settings.export.formatLabel')}</label>
        <select class="select" id="csv-format">
          <option value="local" ${fmt === 'local' ? 'selected' : ''}>${escapeHtml(t('settings.export.formatLocal', { lang: langName }))}</option>
          <option value="1" ${fmt === '1' ? 'selected' : ''}>${escapeHtml(t('settings.export.formatV1'))}</option>
        </select>
        <p class="settings-field__hint">${t('settings.export.formatHint')}</p>
      </div>
      <div class="export-grid">
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.monthly')}</div>
          <div class="export-tile__hint">${t('settings.export.monthlyHint')}</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => `<a class="btn btn--sm btn--art" href="${csvUrl('monthly', u.key, fmt)}" data-csv="monthly" data-utility="${u.key}" download>${escapeHtml(u.label)}</a>`)
              .join('') || `<span class="muted" style="font-size:var(--fs-xs)">${t('settings.export.noActive')}</span>`}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">${t('billing.export.label')}</div>
          <div class="export-tile__hint">${t('billing.export.hint')}</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key) && u.supports_bill_check)
              .map(u => `<a class="btn btn--sm btn--art" href="${csvUrl('billing', u.key, fmt)}" data-csv="billing" data-utility="${u.key}" download>${escapeHtml(u.label)}</a>`)
              .join('') || `<span class="muted" style="font-size:var(--fs-xs)">${t('settings.export.noActive')}</span>`}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.readings')}</div>
          <div class="export-tile__hint">${t('settings.export.readingsHint')}</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => {
                const isDelivery = u.reading_kind === 'delivery';
                const kind = isDelivery ? 'deliveries' : 'readings';
                const tag = isDelivery ? t('settings.export.deliveriesTag') : '';
                return `<a class="btn btn--sm btn--art" href="${csvUrl(kind, u.key, fmt)}" data-csv="${kind}" data-utility="${u.key}" download>${escapeHtml(u.label)}${tag}</a>`;
              })
              .join('') || `<span class="muted" style="font-size:var(--fs-xs)">${t('settings.export.noActive')}</span>`}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.temperatures')}</div>
          <div class="export-tile__hint">${t('settings.export.temperaturesHint')}</div>
          <div class="export-tile__actions">
            <a class="btn btn--sm" href="${csvUrl('temperatures', null, fmt)}" data-csv="temperatures" download>${t('settings.export.exportTemperatures')}</a>
          </div>
        </div>
      </div>
    </div>`;
}


export function renderBackupCard(snapshots, attachments = null) {
  return `
    <div class="card settings-card settings-card--wide" id="backup-card" data-field="backup">
      <h2 class="card__title">${icon('diskette')} ${t('settings.backup.title')}</h2>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        ${t('settings.backup.hint')}
      </p>
      ${renderAttachmentUsage(attachments)}
      <div class="section-actions">
        <button class="btn" id="btn-export">${t('settings.backup.download')}</button>
        <button class="btn" id="btn-snapshot">${t('settings.backup.snapshot')}</button>
        <button class="btn btn--ghost" id="btn-import">${t('settings.backup.import')}</button>
        <input type="file" id="import-file" accept=".json,application/json" style="display:none">
        <button class="btn btn--ghost" id="btn-demo">${t('settings.backup.demo')}</button>
      </div>
      <p class="settings-card__hint" style="margin-top:.5rem">
        ${t('settings.backup.demoHint')}
      </p>

      <hr class="settings-rule">

      <h3 class="settings-subhead">${t('settings.backup.snapshotsTitle')}</h3>
      <p class="muted" style="font-size:var(--fs-xs);margin: 0 0 var(--sp-2)">
        ${t('settings.backup.snapshotsHint')}
      </p>
      <div id="snap-list" class="snapshot-list">${renderSnapshots(snapshots)}</div>
    </div>`;
}


// v3.1.0 (H2) — Belege im Backup: Anzahl, Speicher, Warnung ab 80 % der Grenze
function renderAttachmentUsage(a) {
  const u = a?.usage;
  if (!u || !u.count) return '';
  const mb = (b) => fmt.dec(b / 1048576, 1);
  const pct = u.max_bytes > 0 ? u.bytes / u.max_bytes : 0;
  return `<p class="settings-card__hint${pct >= 0.8 ? ' settings-card__hint--warn' : ''}" style="margin: 0 0 var(--sp-3)">
    ${escapeHtml(tp('settings.backup.attachments', u.count, { size: mb(u.bytes), max: mb(u.max_bytes) }))}
    ${pct >= 0.8 ? ' ' + escapeHtml(t('settings.backup.attachmentsFull')) : ''}
  </p>`;
}


export function wireDataPage(container, utilities, rerender) {
  const fmtSel = container.querySelector('#csv-format');
  fmtSel?.addEventListener('change', () => {
    try { localStorage.setItem(CSV_FORMAT_KEY, fmtSel.value); } catch { /* nur für diese Ansicht */ }
    container.querySelectorAll('[data-csv]').forEach(a => { a.href = csvUrl(a.dataset.csv, a.dataset.utility, fmtSel.value); });
  });
  container.querySelector('#btn-export')?.addEventListener('click', async () => {
    try {
      const data = await api.exportBackup();
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = `energietracker-backup-${todayIso()}.json`;
      a.click(); URL.revokeObjectURL(url);
    } catch (e) { toastErr(e.message); }
  });

  // v2.6.0 — Liste der Snapshots neu laden (nach Anlegen/Löschen)
  const refreshSnapshots = async () => {
    const el = container.querySelector('#snap-list');
    if (!el) return;
    try { el.innerHTML = renderSnapshots(await api.snapshots()); } catch { /* alte Liste bleibt */ }
  };

  container.querySelector('#btn-snapshot')?.addEventListener('click', async () => {
    try {
      const r = await api.snapshotBackup();
      toastOk(t('settings.backup.snapshotToast', { file: r.file || r.path || 'ok' }));
      refreshSnapshots();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#snap-list')?.addEventListener('click', async (ev) => {
    const restoreBtn = ev.target.closest('[data-snap-restore]');
    const deleteBtn  = ev.target.closest('[data-snap-delete]');
    if (!restoreBtn && !deleteBtn) return;
    const btn  = restoreBtn || deleteBtn;
    const name = btn.getAttribute(restoreBtn ? 'data-snap-restore' : 'data-snap-delete');
    const date = stampText(btn.getAttribute('data-snap-date'));
    if (restoreBtn) {
      const ok = await confirmModal({
        title: t('settings.backup.snapRestoreTitle'),
        message: t('settings.backup.snapRestoreMsg', { date }),
        confirmLabel: t('settings.backup.snapRestore').replace(/…$/, ''), danger: true,
      });
      if (!ok) return;
      try {
        const report = await withSnapshotGuard(
          () => api.restoreSnapshot(name),
          () => api.restoreSnapshot(name, { allowWithoutSnapshot: true }));
        if (!report) return;
        afterRestore(t('settings.backup.snapRestored'));
      } catch (e) { showBackupError(e); }
      return;
    }
    const ok = await confirmModal({
      title: t('settings.backup.snapDeleteTitle'),
      message: t('settings.backup.snapDeleteMsg', { date }),
      confirmLabel: t('settings.backup.snapDelete'), danger: true,
    });
    if (!ok) return;
    try { await api.deleteSnapshot(name); toastOk(t('settings.backup.snapDeleted')); refreshSnapshots(); }
    catch (e) { toastErr(e.message); }
  });

  // F1007 — Demo-Daten-Komfort-Import (v2.13.0: gemeinsam mit der leeren Übersicht)
  container.querySelector('#btn-demo')?.addEventListener('click', async () => {
    try { await loadDemo({ beforeReload: unlistenAll }); }
    catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-import')?.addEventListener('click',
    () => container.querySelector('#import-file')?.click());
  // v2.6.0 — Import in zwei Schritten: Erst prüft der Server das Backup
  // (dry_run) und die Vorschau zeigt, was ersetzt wird und was unverändert
  // bleibt; eingespielt wird erst nach der Bestätigung. Ein fehlerhaftes
  // Backup ändert nichts und nennt die Fundstellen.
  container.querySelector('#import-file')?.addEventListener('change', async (e) => {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    let data;
    try { data = JSON.parse(await f.text()); }
    catch (err) { toastErr(t('settings.backup.migrateReadError', { msg: err.message })); return; }
    try {
      const preview = await api.importBackup(data, { dryRun: true });
      if (!await confirmImport(preview, utilities)) return;
      const report = await withSnapshotGuard(
        () => api.importBackup(data),
        () => api.importBackup(data, { allowWithoutSnapshot: true }));
      if (!report) return;
      const snap = report.auto_snapshot_before_restore;
      afterRestore(typeof snap === 'string'
        ? t('settings.backup.importedSnap', { snap })
        : t('settings.backup.imported'));
    } catch (err) { showBackupError(err); }
  });
}


// ── v2.6.0 — Snapshots und Import ────────────────────────────────────────

function renderSnapshots(list) {
  if (!Array.isArray(list) || !list.length) return `<p class="muted">${t('settings.backup.snapshotsNone')}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('settings.backup.colDate')}</th>
      <th scope="col">${t('settings.backup.colReason')}</th>
      <th scope="col" class="num snapshot-list__size">${t('settings.backup.colSize')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${list.map(s => `<tr>
      <td title="${escapeHtml(s.name)}">${stampHtml(s.created_at)}</td>
      <td>${escapeHtml(t('settings.backup.reason.' + (s.reason || 'manual')))}</td>
      <td class="num snapshot-list__size">${bytesText(s.size)}</td>
      <td class="snapshot-list__actions">
        <a class="icon-btn" href="${api.snapshotUrl(s.name)}" download="${escapeHtml(s.name)}" title="${t('settings.backup.snapDownload')}" aria-label="${t('settings.backup.snapDownload')}">${icon('herunter')}</a>
        <button type="button" class="icon-btn" data-snap-restore="${escapeHtml(s.name)}" data-snap-date="${escapeHtml(s.created_at)}" title="${t('settings.backup.snapRestore')}" aria-label="${t('settings.backup.snapRestore')}">${icon('zurueck')}</button>
        <button type="button" class="icon-btn" data-snap-delete="${escapeHtml(s.name)}" data-snap-date="${escapeHtml(s.created_at)}" title="${t('settings.backup.snapDelete')}" aria-label="${t('settings.backup.snapDelete')}">${icon('eimer')}</button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}


/** Zeitstempel (ISO mit Uhrzeit) im Format der Oberflächensprache. */
function stampText(iso) {
  const d = iso ? new Date(iso) : null;
  if (!d || Number.isNaN(d.getTime())) return '–';
  return d.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' });
}

export const stampHtml = (iso) => escapeHtml(stampText(iso));


function bytesText(n) {
  const b = Number(n) || 0;
  return b >= 1048576 ? `${fmt.num(b / 1048576, 1)} MB` : `${fmt.int(Math.max(1, Math.round(b / 1024)))} KB`;
}


/**
 * Einspielen mit Rückweg: Scheitert der Sicherungs-Snapshot vorher (409),
 * fragt die Oberfläche nach, statt still ohne Rückweg weiterzumachen.
 * Liefert den Bericht oder null (abgebrochen).
 */
async function withSnapshotGuard(run, runWithoutSnapshot) {
  try {
    return await run();
  } catch (e) {
    if (e.status !== 409 || e.code !== 'errors.backup.snapshotFailed') throw e;
    const ok = await confirmModal({
      title: t('settings.backup.noSnapshotTitle'),
      message: e.message,
      confirmLabel: t('settings.backup.noSnapshotBtn'), danger: true,
    });
    return ok ? runWithoutSnapshot() : null;
  }
}


/** Fehler beim Einspielen: Fundstellen im Dialog, sonst als Hinweis. */
function showBackupError(err) {
  const problems = err?.detail?.problems;
  if (!Array.isArray(problems) || !problems.length) { toastErr(err?.message || String(err)); return; }
  const rows = problems.map(p => {
    const [kind, arg] = String(p.problem || '').split(/:(.*)/s);
    const what = kind === 'missing' || kind === 'date' || kind === 'unsafe_id' ? t('settings.backup.problem.' + kind, { field: arg ?? '' })
      : kind === 'entry' ? t('settings.backup.problem.entry', { what: arg ?? '' })
      : t('settings.backup.problem.' + kind);
    const where = `${p.pot}${Number.isInteger(p.index) ? ' #' + (p.index + 1) : ''}`;
    return `<li><code>${escapeHtml(where)}</code> — ${escapeHtml(what)}</li>`;
  }).join('');
  openModal({
    title: t('settings.backup.problemsTitle'),
    body: `<p>${escapeHtml(err.message)}</p>
      <ul class="problem-list">${rows}</ul>
      ${problems.length >= 50 ? `<p class="muted">${t('settings.backup.problemsMore')}</p>` : ''}`,
    footer: `<button type="button" class="btn btn--primary" data-act="ok">${t('common.close')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', () => close(true));
    },
  });
}


/** Vorschau eines geprüften Backups (dry_run); true = einspielen. */
function confirmImport(report, utilities) {
  const label = (key) => (utilities || []).find(u => u.key === key)?.label || key;
  const items = [];
  for (const key of ['settings', 'temperatures', 'reminders', 'recommendations_dismissed']) {
    if (report[key] == null) continue;
    items.push(key === 'settings'
      ? escapeHtml(t('settings.backup.top.settings'))
      : `${escapeHtml(t('settings.backup.top.' + key))}: ${fmt.int(report[key])}`);
  }
  for (const [key, counts] of Object.entries(report.utilities || {})) {
    // „Verträge: 1" statt „1 Verträge" — ohne Pluralformen in allen Sprachen richtig
    const parts = Object.entries(counts || {})
      .filter(([, n]) => n > 0)
      .map(([pot, n]) => `${escapeHtml(t('settings.backup.pot.' + pot))}: ${fmt.int(n)}`);
    if (parts.length) items.push(`<strong>${escapeHtml(label(key))}</strong> — ${parts.join(', ')}`);
  }
  const untouched = (report.untouched || []).map(p => {
    const [u, pot] = String(p).split('/');
    return pot ? `${label(u)} – ${t('settings.backup.pot.' + pot)}` : t('settings.backup.top.' + u);
  });
  return new Promise(resolve => {
    openModal({
      title: t('settings.backup.previewTitle'),
      body: `<p>${t('settings.backup.previewIntro')}</p>
        <ul class="import-preview">${items.map(i => `<li>${i}</li>`).join('')}</ul>
        ${untouched.length ? `<p class="muted">${escapeHtml(t('settings.backup.previewUntouched', { list: untouched.join(', ') }))}</p>` : ''}
        <div class="infobox infobox--achtung">${escapeHtml(t('settings.backup.importConfirmMsg'))}</div>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--danger" data-act="ok">${t('settings.backup.importConfirmBtn')}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(false));
        modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', () => close(true));
      },
    }).closedPromise.then(v => resolve(v === true));
  });
}


/**
 * Nach Demo-Daten, Import oder Wiederherstellung die App neu starten.
 *
 * v2.11.0 (Review UI-24) — bis v2.10 wurden hier Zwischenspeicher und
 * Seitenleiste einzeln erneuert. Sprache, Land, Theme-Farben und Badges
 * blieben dabei auf dem alten Stand, und nach Demo-Daten zeigte die
 * Seitenleiste weiter drei statt acht Verbrauchsarten. Ein Neustart erneuert
 * alles; die Meldung erscheint danach. Die eigenen Listener (beforeunload)
 * gehen vorher ab, damit kein „ungespeicherte Änderungen" dazwischenfragt.
 */
function afterRestore(message) {
  unlistenAll();
  toastAfterReload(message);
  location.reload();
}
