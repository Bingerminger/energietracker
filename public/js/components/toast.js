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

export function toast(message, variant = 'info', timeoutMs = 4000) {
  const stack = document.getElementById('toast-stack');
  if (!stack) return;

  const el = document.createElement('div');
  el.className = `toast toast--${variant}`;
  // Fehler unterbrechen, alles andere reiht sich ein.
  el.setAttribute('role', variant === 'error' ? 'alert' : 'status');
  el.setAttribute('aria-live', variant === 'error' ? 'assertive' : 'polite');
  el.innerHTML = `
    <span class="toast__msg">${escapeHtml(message)}</span>
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
