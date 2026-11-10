// =====================================================================
// v3.12.0 (M2) — Zu tun › Verlauf › Zugriffe der KI: Rückblick, deshalb im Verlauf.
// Nur für Verwalter (GET /api/mcp/journal ist adminOnly); Mitglieder sehen keine KI-Zeilen.
// =====================================================================

import { t } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { sessionRole } from '../lib/levels.js';
import { loadMcp, renderJournalCard, wireJournalCard } from '../components/mcp-card.js';
import { historyPillsHtml } from './activity.js';

export async function render(container) {
  container.innerHTML = `<div class="loading" role="status">${esc(t('common.loading'))}</div>`;
  const head = `${historyPillsHtml('ai')}
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${esc(t('todo.history.ai'))}</h1>
        <div class="view-header__subtitle">${esc(t('settings.mcp.journalHint'))}</div>
      </div>
    </div>`;
  const mcp = sessionRole() === 'admin' ? await loadMcp() : null;
  if (!mcp) {
    container.innerHTML = `${head}<div class="infobox infobox--info" data-role="mcp-admin-only">${esc(t('todo.history.adminOnly'))}</div>`;
    return;
  }
  container.innerHTML = `${head}<div class="settings-grid">${renderJournalCard(mcp)}</div>`;
  wireJournalCard(container, mcp);
}
