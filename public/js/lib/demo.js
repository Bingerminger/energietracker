// =====================================================================
// v2.13.0 (Review UI-32) — Demo-Daten laden, von den Einstellungen und
// von der leeren Übersicht aus. Vor dem Import legt der Server immer einen
// Sicherheits-Snapshot an; über Einstellungen → Daten führt er zurück.
// =====================================================================
import { api } from '../api.js';
import { t } from './i18n.js';
import { confirmModal } from '../components/modal.js';
import { toastErr, toastAfterReload } from '../components/toast.js';

/**
 * Lädt die Demo-Daten und startet die App neu (Seitenleiste, Sprache und
 * Zwischenspeicher passen dann zum neuen Stand).
 *
 * @param {{beforeReload?: () => void}} [opts]
 * @returns {Promise<boolean>} false, wenn abgebrochen oder nicht verfügbar
 */
export async function loadDemo({ beforeReload } = {}) {
  const status = await api.demoStatus();
  if (!status.available) {
    toastErr(t('settings.backup.demoUnavailable'));
    return false;
  }
  if (!status.is_empty) {
    const ok = await confirmModal({
      title: t('settings.backup.demoConfirmTitle'),
      message: t('settings.backup.demoConfirmMsg'),
      confirmLabel: t('settings.backup.demoConfirmBtn'), danger: true,
    });
    if (!ok) return false;
  }
  const report = await api.importDemo(!status.is_empty);
  const snap = report?.auto_snapshot_before_restore;
  beforeReload?.();
  toastAfterReload(typeof snap === 'string'
    ? t('settings.backup.demoLoadedSnap', { snap })
    : t('settings.backup.demoLoaded'));
  location.reload();
  return true;
}
