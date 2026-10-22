// =====================================================================
// v3.7.0 — Belege an Vertrag und Versorgerrechnung (`attachment_ids`,
// etwa aus dem Posteingang übernommen) zum Öffnen. Eine Quelle für die
// Vertragskarte und die Rechnungsliste.
// =====================================================================

import { api } from '../api.js';
import { escapeHtml } from './format.js';
import { t } from './i18n.js';

/** Die Beleg-IDs eines Datensatzes (Vertrag, Rechnung), leere entfernt. */
export const attachmentIdsOf = (record) => (Array.isArray(record?.attachment_ids) ? record.attachment_ids : [])
  .filter(id => typeof id === 'string' && id !== '');

/**
 * Dateinamen der Belege je ID (`original_name` aus `/api/attachments`). Fragt
 * nur, wenn einer der Datensätze Belege hat; ohne Antwort eine leere Map (die
 * Links heißen dann „Beleg 1“, „Beleg 2“ …).
 *
 * @returns {Promise<Map<string,string>>}
 */
export async function attachmentNames(records) {
  if (!(records || []).some(r => attachmentIdsOf(r).length)) return new Map();
  try {
    const res = await api.attachments();
    return new Map((res?.attachments || []).map(a => [a.id, a.original_name || '']));
  } catch {
    return new Map();
  }
}

/** Name eines Belegs: der Dateiname, sonst „Beleg {n}“ (n ab 1). */
export const attachmentName = (id, i, names) => names?.get(id) || t('attachments.fallback', { n: i + 1 });

/**
 * Links auf die Belege eines Datensatzes; öffnen in einem neuen Tab.
 * `icons: true` — nur 📄 je Beleg (Tabellenzeile), der Name im aria-label.
 */
export function attachmentLinksHtml(record, names = new Map(), { icons = false } = {}) {
  return attachmentIdsOf(record).map((id, i) => {
    const name = attachmentName(id, i, names);
    const href = escapeHtml(api.attachmentUrl(id));
    const label = escapeHtml(t('attachments.open', { name }));
    return icons
      ? `<a class="icon-btn" href="${href}" target="_blank" rel="noopener" data-attachment="${escapeHtml(id)}" aria-label="${label}" title="${label}"><span aria-hidden="true">📄</span></a>`
      : `<a href="${href}" target="_blank" rel="noopener" data-attachment="${escapeHtml(id)}" title="${label}"><span aria-hidden="true">📄</span> ${escapeHtml(name)}</a>`;
  }).join(icons ? '' : ' · ');
}
