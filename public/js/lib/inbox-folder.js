// =====================================================================
// v3.7.0 (Paket betrieb) — überwachter Ordner `posteingang/` im
// Datenverzeichnis: Die App liest ihn beim Start und beim Öffnen des
// Posteingangs ein (`POST /api/inbox/folder`, nur Verwalter; ohne Anmeldung
// ist jeder einer). Einen eigenen Zeitplan hat sie nicht.
// =====================================================================

import { api } from '../api.js';
import { tp } from './i18n.js';
import { toastOk, toastWarn } from '../components/toast.js';
import { sessionRole } from './levels.js';
import { DEMO } from './demo-mode.js';

/**
 * Ordner einlesen; meldet, was kam und was abgelehnt wurde. Nie ein Fehler,
 * der die Seite aufhält.
 * @returns {Promise<object|null>} Ergebnis des Servers oder null
 */
export async function scanFolder({ quiet = false } = {}) {
  if (DEMO || sessionRole() !== 'admin') return null;
  let r;
  try { r = await api.inboxFolderScan(); } catch { return null; }
  const n = r?.imported?.length || 0;
  if (n) toastOk(tp('inbox.folder.imported', n, { dir: r.dir }));
  if (r?.failed?.length && !quiet) toastWarn(tp('inbox.folder.failed', r.failed.length, { dir: `${r.dir}/fehler` }));
  if (n) window.dispatchEvent(new CustomEvent('et:badges-refresh'));
  return r;
}
