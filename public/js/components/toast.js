// =====================================================================
// Toast notifications. Simple stack in #toast-stack.
// =====================================================================
import { escapeHtml } from '../lib/format.js';

export function toast(message, variant = 'info', timeoutMs = 4000) {
  const stack = document.getElementById('toast-stack');
  if (!stack) return;
  const el = document.createElement('div');
  el.className = `toast toast--${variant}`;
  el.innerHTML = escapeHtml(message);
  stack.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity 200ms';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 220);
  }, timeoutMs);
}

export const toastOk    = (m) => toast(m, 'success');
export const toastErr   = (m) => toast(m, 'error', 6000);
export const toastWarn  = (m) => toast(m, 'warning');
