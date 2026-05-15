// =====================================================================
// Energietracker v1.2.0 — API client
// Thin wrapper around fetch. Mirrors the backend route layout from
// src/bootstrap.php. Every method returns parsed JSON `data` or throws.
// =====================================================================

const BASE = 'api.php';

async function request(method, path, body = null, { raw = false } = {}) {
  const opts = { method, headers: {} };
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
  const res = await fetch(url, opts);
  let payload;
  try { payload = await res.json(); }
  catch { throw new Error(`HTTP ${res.status}: ungültige Antwort`); }
  if (!res.ok || payload.success === false) {
    const err = new Error(payload?.error || `HTTP ${res.status}`);
    err.status = res.status;
    err.detail = payload?.detail;
    throw err;
  }
  return payload.data;
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
  syncOpenMeteo: (data)                => request('POST', '/api/temperatures/sync-open-meteo', data),

  // Settings, Backup, Diagnostics
  settings:      ()                    => request('GET',  '/api/settings'),
  updateSettings:(data)                => request('PATCH','/api/settings', data),
  exportBackup:  ()                    => request('GET',  '/api/backup/export'),
  importBackup:  (data)                => request('POST', '/api/backup/import', data),
  snapshotBackup:()                    => request('POST', '/api/backup/snapshot'),

  // ── CSV-Export (F-07) ──
  // These return a file download, not JSON — so they are plain URLs the
  // browser navigates to / anchors to, not request() calls.
  exportMonthlyCsvUrl:      (u) => `${BASE}/api/export/${u}/monthly.csv`,
  exportReadingsCsvUrl:     (u) => `${BASE}/api/export/${u}/readings.csv`,
  exportTemperaturesCsvUrl: ()  => `${BASE}/api/export/temperatures.csv`,

  // ── Migration aus v0.9.0 ──
  migrationV09Preview: (backup)         => request('POST', '/api/migration/v09/preview', { backup }),
  migrationV09Import:  (translated, mode) => request('POST', '/api/migration/v09/import',  { translated, mode }),
  diagnostics:   ()                    => request('GET',  '/api/diagnostics'),
};
