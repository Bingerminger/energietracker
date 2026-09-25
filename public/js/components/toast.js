// =====================================================================
// Toast notifications. Stack in #toast-stack.
//
// v2.2.0 — Barrierefreiheit:
//   • Jeder Toast trägt eine eigene Live-Region-Rolle. Fehler melden sich
//     assertiv, Erfolg/Info höflich; vorher hing alles am `aria-live="polite"`
//     des Stacks, sodass Fehlermeldungen erst nach der laufenden Ausgabe kamen.
//   • Schließen-Knopf. Ein Toast verschwand nach 4–6 Sekunden unwiderruflich —
//     wer langsamer liest, verlor die Meldung (WCAG 2.2.1, Timing Adjustable).
//   • Zeigen auf einen Toast pausiert seinen Ablauf.
// =====================================================================
import { escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';

/**
 * @param {string} message
 * @param {'info'|'success'|'warning'|'error'} [variant]
 * @param {number} [timeoutMs]
 * @param {{label: string, onClick: () => unknown}|null} [action]  v2.12.0 — z. B. „Rückgängig"
 */
export function toast(message, variant = 'info', timeoutMs = 4000, action = null) {
  const stack = document.getElementById('toast-stack');
  if (!stack) return;

  const el = document.createElement('div');
  el.className = `toast toast--${variant}`;
  // Fehler unterbrechen, alles andere reiht sich ein.
  el.setAttribute('role', variant === 'error' ? 'alert' : 'status');
  el.setAttribute('aria-live', variant === 'error' ? 'assertive' : 'polite');
  el.innerHTML = `
    <span class="toast__msg">${escapeHtml(message)}</span>
    ${action ? `<button type="button" class="btn btn--sm toast__action">${escapeHtml(action.label)}</button>` : ''}
    <button type="button" class="toast__close" aria-label="${escapeHtml(t('common.close'))}">
      <span aria-hidden="true">×</span>
    </button>`;
  stack.appendChild(el);

  let timer = null;
  const dismiss = () => {
    if (timer) { clearTimeout(timer); timer = null; }
    el.style.transition = 'opacity 200ms';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 220);
  };
  const arm = (ms) => { timer = setTimeout(dismiss, ms); };

  el.querySelector('.toast__close').addEventListener('click', dismiss);
  el.querySelector('.toast__action')?.addEventListener('click', () => {
    dismiss();
    try { action.onClick(); } catch (e) { console.error(e); }
  }, { once: true });
  // Solange der Zeiger auf der Meldung liegt, läuft die Zeit nicht weiter.
  el.addEventListener('mouseenter', () => { if (timer) { clearTimeout(timer); timer = null; } });
  el.addEventListener('mouseleave', () => { if (!timer) arm(timeoutMs); });
  // Gleiches beim Tastaturfokus auf dem Schließen-Knopf.
  el.addEventListener('focusin', () => { if (timer) { clearTimeout(timer); timer = null; } });
  el.addEventListener('focusout', () => { if (!timer) arm(timeoutMs); });

  arm(timeoutMs);
  return dismiss;
}

export const toastOk    = (m) => toast(m, 'success');
export const toastErr   = (m) => toast(m, 'error', 6000);
export const toastWarn  = (m) => toast(m, 'warning');

/** v2.12.0 (Review UI-15, UI-22) — Erfolg mit „Rückgängig", 10 Sekunden lang. */
export const toastUndo  = (m, onUndo) => toast(m, 'success', 10000, { label: t('common.undo'), onClick: onUndo });

// v2.11.0 (Review UI-24) — Meldung über einen Neustart der App hinweg. Nach
// Demo-Daten, Backup-Import oder Wiederherstellung lädt die App komplett neu
// (Seitenleiste, Sprache, Zwischenspeicher); die Erfolgsmeldung kommt danach.
const FLASH_KEY = 'et-flash';

export function toastAfterReload(message, variant = 'success') {
  try { sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, variant })); } catch { /* ohne Speicher keine Meldung */ }
}

export function showPendingToast() {
  let item = null;
  try {
    item = JSON.parse(sessionStorage.getItem(FLASH_KEY) || 'null');
    sessionStorage.removeItem(FLASH_KEY);
  } catch { return; }
  if (item?.message) toast(String(item.message), item.variant === 'error' ? 'error' : 'success', 6000);
}
