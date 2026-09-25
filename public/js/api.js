// =====================================================================
// Energietracker — API client
// Thin wrapper around fetch. Mirrors the backend route layout from
// src/bootstrap.php. Every method returns parsed JSON `data` or throws.
// =====================================================================

import { getLocale, t } from './lib/i18n.js';

const BASE = 'api.php';

async function request(method, path, body = null, { raw = false } = {}) {
  // N1007 — aktive Sprache mitschicken, damit das Backend (Full-Stack-i18n)
  // Fehlermeldungen/Labels in derselben Sprache liefern kann.
  const opts = { method, headers: { 'Accept-Language': getLocale() } };
  if (body !== null && body !== undefined) {
    if (raw) {
      opts.headers['Content-Type'] = 'text/plain; charset=utf-8';
      opts.body = body;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
  }
  // path always starts with '/', BASE never ends with '/' — concatenate directly.
  // Belt-and-braces: collapse any accidental doubled slashes inside the path part.
  const url = `${BASE}${path.replace(/\/{2,}/g, '/')}`;
  let res;
  try {
    res = await fetch(url, opts);
  } catch {
    // v2.6.0 — statt „Failed to fetch" / „Load failed" (je nach Browser). Bei
    // Schreibzugriffen ausdrücklich: Es wurde nichts gespeichert.
    const err = new Error(t(method === 'GET' ? 'app.networkErrorRead' : 'app.networkError'));
    err.status = 0;
    err.code = 'network';
    throw err;
  }
  // v2.6.0 — Antwort aus dem Offline-Cache des Service Workers? Dann trägt sie
  // den Stand im Header; die Shell zeigt „Offline – Stand vom …".
  if (res.headers.has('X-ET-Offline')) {
    window.dispatchEvent(new CustomEvent('et:offline', { detail: res.headers.get('X-ET-Offline') }));
  } else if (res.ok) {
    window.dispatchEvent(new CustomEvent('et:online'));
  }
  let payload;
  try { payload = await res.json(); }
  // Keine JSON-Antwort — etwa die Anmeldeseite eines vorgeschalteten Proxys.
  catch { throw Object.assign(new Error(t('app.invalidResponse', { status: res.status })), { status: res.status }); }
  if (!res.ok || payload.success === false) {
    const err = new Error(payload?.error || `HTTP ${res.status}`);
    err.status = res.status;
    err.detail = payload?.detail;
    // v2.6.0 — stabiler Fehlercode (Katalogschlüssel), s. Response::error()
    err.code = payload?.code;
    // Anmeldung eingeschaltet und keine Sitzung: die Shell zeigt die Anmeldung.
    if (res.status === 401 && payload?.code === 'errors.auth.required') {
      window.dispatchEvent(new CustomEvent('et:auth-required'));
    }
    throw err;
  }
  return payload.data;
}

/** v2.6.0 — Query-Zeichenkette aus gesetzten Flags (`?dry_run=1&…`). */
function flags(obj) {
  const on = Object.entries(obj).filter(([, v]) => v).map(([k]) => `${k}=1`);
  return on.length ? `?${on.join('&')}` : '';
}

export const api = {
  // Utilities
  listUtilities: ()                    => request('GET',  '/api/utilities'),

  // Meters
  meters:        (u)                   => request('GET',  `/api/utility/${u}/meters`),
  meter:         (u, id)               => request('GET',  `/api/utility/${u}/meters/${id}`),
  createMeter:   (u, data)             => request('POST', `/api/utility/${u}/meters`, data),
  updateMeter:   (u, id, data)         => request('PATCH',`/api/utility/${u}/meters/${id}`, data),
  deleteMeter:   (u, id)               => request('DELETE',`/api/utility/${u}/meters/${id}`),
  replaceDevice: (u, id, data)         => request('POST', `/api/utility/${u}/meters/${id}/replace-device`, data),

  // ── v1.2.0 (F1006) — Zählergruppen / Meter-Topologie ──
  meterGroups:      (u)                => request('GET',   `/api/utility/${u}/meter-groups`),
  createMeterGroup: (u, data)          => request('POST',  `/api/utility/${u}/meter-groups`, data),
  updateMeterGroup: (u, gid, data)     => request('PATCH', `/api/utility/${u}/meter-groups/${gid}`, data),
  deleteMeterGroup: (u, gid)           => request('DELETE',`/api/utility/${u}/meter-groups/${gid}`),
  mergeMeterGroup:  (u, data)          => request('POST',  `/api/utility/${u}/meter-groups/merge`, data),

  // Readings
  readings:      (u, meterId)          => {
    const q = meterId ? `?meter_id=${encodeURIComponent(meterId)}` : '';
    return request('GET', `/api/utility/${u}/readings${q}`);
  },
  createReading: (u, data)             => request('POST', `/api/utility/${u}/readings`, data),
  updateReading: (u, id, data)         => request('PATCH',`/api/utility/${u}/readings/${id}`, data),
  deleteReading: (u, id)               => request('DELETE',`/api/utility/${u}/readings/${id}`),
  // F-06: zähler-gebundener CSV-Bulk-Import (Body: text/plain CSV).
  importReadingCsv: (u, meterId, csvText) =>
    request('POST', `/api/utility/${u}/meters/${meterId}/readings/import-csv`, csvText, { raw: true }),
  // F1004 (v1.6.0): Aggregat für die zentrale Zählerstand-Erfassung
  readingsOverview: ()                  => request('GET', '/api/readings-overview'),

  // Contracts
  contracts:     (u, meterId)          => {
    const q = meterId ? `?meter_id=${encodeURIComponent(meterId)}` : '';
    return request('GET', `/api/utility/${u}/contracts${q}`);
  },
  contract:      (u, id)               => request('GET',  `/api/utility/${u}/contracts/${id}`),
  createContract:(u, data)             => request('POST', `/api/utility/${u}/contracts`, data),
  updateContract:(u, id, data)         => request('PATCH',`/api/utility/${u}/contracts/${id}`, data),
  deleteContract:(u, id)               => request('DELETE',`/api/utility/${u}/contracts/${id}`),

  // Consumption
  consumption:        (u, hddBase)     => {
    const q = hddBase != null ? `?hdd_base=${hddBase}` : '';
    return request('GET', `/api/utility/${u}/consumption${q}`);
  },
  meterConsumption:   (u, id, hddBase) => {
    const q = hddBase != null ? `?hdd_base=${hddBase}` : '';
    return request('GET', `/api/utility/${u}/meters/${id}/consumption${q}`);
  },
  contractStatus:     (u, id)           => request('GET', `/api/utility/${u}/meters/${id}/contract-status`),
  // v2.5.0 — F1012: Rechnungsprüfung (nur Gas)
  billCheck:          (u, id, from, to) => request('GET', `/api/utility/${u}/meters/${id}/bill-check?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`),

  // Forecast
  forecast:      (u, meterId, opts={})=> {
    const params = new URLSearchParams(opts).toString();
    const q = params ? '?' + params : '';
    return request('GET', `/api/utility/${u}/meters/${meterId}/forecast${q}`);
  },

  // Temperatures
  temperatures:  ()                    => request('GET',  '/api/temperatures'),
  upsertTemp:    (data)                => request('POST', '/api/temperatures', data),
  deleteTemp:    (date)                => request('DELETE',`/api/temperatures/${date}`),
  importTempCsv: (csvText)             => request('POST', '/api/temperatures/import-csv', csvText, { raw: true }),
  // v2.8.0 — Optionen als Query (der Server las sie immer dort): reload, auto, start, end
  syncOpenMeteo: (opts = {})           => {
    const params = new URLSearchParams(opts).toString();
    return request('POST', '/api/temperatures/sync-open-meteo' + (params ? '?' + params : ''));
  },

  // F1005 (v1.7.0) — Strom-Saldo (Bezug − PV-Einspeisung) + PV-Summary (Eigenverbrauch + Autarkie)
  stromSaldo:    ()                    => request('GET',  '/api/strom-saldo'),
  pvSummary:     ()                    => request('GET',  '/api/pv-summary'),

  // N1003 (v1.7.0) — Health-Check
  health:        ()                    => request('GET',  '/api/health'),

  // Settings, Backup, Diagnostics
  settings:      ()                    => request('GET',  '/api/settings'),
  updateSettings:(data)                => request('PATCH','/api/settings', data),
  // v2.7.0 — Länderprofile (Voreinstellungen je Land)
  countries:     ()                    => request('GET',  '/api/countries'),
  exportBackup:  ()                    => request('GET',  '/api/backup/export'),
  // v2.6.0 — { dryRun, allowWithoutSnapshot } als Query-Flags
  importBackup:  (data, { dryRun = false, allowWithoutSnapshot = false } = {}) =>
    request('POST', `/api/backup/import${flags({ dry_run: dryRun, allow_without_snapshot: allowWithoutSnapshot })}`, data),
  snapshotBackup:()                    => request('POST', '/api/backup/snapshot'),
  // v2.6.0 — Snapshots verwalten
  snapshots:       ()                  => request('GET',    '/api/backup/snapshots'),
  snapshotUrl:     (name)              => `${BASE}/api/backup/snapshots/${encodeURIComponent(name)}`,
  restoreSnapshot: (name, { allowWithoutSnapshot = false } = {}) =>
    request('POST', `/api/backup/snapshots/${encodeURIComponent(name)}/restore${flags({ allow_without_snapshot: allowWithoutSnapshot })}`),
  deleteSnapshot:  (name)              => request('DELETE', `/api/backup/snapshots/${encodeURIComponent(name)}`),

  // v2.6.0 — Anmeldung (opt-in) und API-Schlüssel
  session:         ()                  => request('GET',    '/api/session'),
  login:           (password)          => request('POST',   '/api/session', { password }),
  logout:          ()                  => request('DELETE', '/api/session'),
  setPassword:     (password, current) => request('POST',   '/api/session/password', { password, current }),
  disableLogin:    (current)           => request('DELETE', '/api/session/password', { current }),
  apiKeys:         ()                  => request('GET',    '/api/auth/keys'),
  createApiKey:    (name, scope)       => request('POST',   '/api/auth/keys', { name, scope }),
  revokeApiKey:    (id)                => request('DELETE', `/api/auth/keys/${encodeURIComponent(id)}`),

  // Demo-Daten-Import (F1007)
  demoStatus:    ()                    => request('GET',  '/api/demo/status'),
  importDemo:    (force = false)       => request('POST', '/api/demo/import', { force }),

  // ── v1.3.0 (F1009) — Home-Assistant-Anbindung: API-Token ──
  authStatus:    ()                    => request('GET',    '/api/auth/token'),
  generateToken: ()                    => request('POST',   '/api/auth/token'),
  revokeToken:   ()                    => request('DELETE', '/api/auth/token'),

  // ── CSV-Export (F-07) ──
  // These return a file download, not JSON — so they are plain URLs the
  // browser navigates to / anchors to, not request() calls.
  exportMonthlyCsvUrl:      (u) => `${BASE}/api/export/${u}/monthly.csv`,
  exportReadingsCsvUrl:     (u) => `${BASE}/api/export/${u}/readings.csv`,
  exportDeliveriesCsvUrl:   (u) => `${BASE}/api/export/${u}/deliveries.csv`,
  exportTemperaturesCsvUrl: ()  => `${BASE}/api/export/temperatures.csv`,

  // ── Migration aus v0.9.0 ──
  migrationV09Preview: (backup)         => request('POST', '/api/migration/v09/preview', { backup }),
  migrationV09Import:  (translated, mode) => request('POST', '/api/migration/v09/import',  { translated, mode }),
  diagnostics:   ()                    => request('GET',  '/api/diagnostics'),

  // ── v1.3.0 — Lieferungen (Heizöl/Pellets) ──
  deliveries:    (u, meterId) => {
    const q = meterId ? `?meter_id=${encodeURIComponent(meterId)}` : '';
    return request('GET', `/api/utility/${u}/deliveries${q}`);
  },
  createDelivery:(u, data)     => request('POST',  `/api/utility/${u}/deliveries`, data),
  updateDelivery:(u, id, data) => request('PATCH', `/api/utility/${u}/deliveries/${id}`, data),
  deleteDelivery:(u, id)       => request('DELETE',`/api/utility/${u}/deliveries/${id}`),
  stockHistory:  (u, meterId)  => request('GET',   `/api/utility/${u}/meters/${meterId}/stock-history`),

  // ── v1.3.0 — Benchmark / Effizienz ──
  efficiency:    (year) => request('GET', `/api/benchmarks/efficiency${year ? `?year=${year}` : ''}`),

  // ── v1.3.0 — Tarifvergleich (Rückblick auf echte Monate) ──
  tariffComparison: (u, meterId, year) =>
    request('GET', `/api/utility/${u}/meters/${meterId}/tariff-comparison${year ? `?year=${year}` : ''}`),

  // ── v2.3.0 — Wechselentscheidung (Prognose ab Wechseltermin) ──
  tariffSwitch: (u, meterId, switchDate) =>
    request('GET', `/api/utility/${u}/meters/${meterId}/tariff-switch${
      switchDate ? `?switch_date=${encodeURIComponent(switchDate)}` : ''}`),

  // ── v1.3.0 — Empfehlungen ──
  recommendations:      (inclDismissed=false) =>
    request('GET', `/api/recommendations${inclDismissed ? '?include_dismissed=1' : ''}`),
  dismissRecommendation:(id, until=null) =>
    request('POST', `/api/recommendations/${id}/dismiss`, until ? { until } : {}),

  // ── v1.3.0 — Termine/Erinnerungen ──
  reminders:     ()           => request('GET',   '/api/reminders'),
  createReminder:(data)       => request('POST',  '/api/reminders', data),
  updateReminder:(id, data)   => request('PATCH', `/api/reminders/${id}`, data),
  deleteReminder:(id)         => request('DELETE',`/api/reminders/${id}`),
  reminderDone:  (id, dt=null)=> request('POST',  `/api/reminders/${id}/done`, dt ? { done_date: dt } : {}),

  // ── v1.3.0 — PDF-Jahresbericht (Datei-Download, kein JSON) ──
  yearlyReportUrl: (year) => `${BASE}/api/reports/yearly.pdf${year ? `?year=${year}` : ''}`,
};
