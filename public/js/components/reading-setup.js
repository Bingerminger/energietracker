// =====================================================================
// v3.7.0 (Haushalt aus Dokumenten) — „Auslesen einrichten“ als Dialog.
//
// Die Karte „Auslesen“ (Lokal | Cloud, components/inbox-cloud.js) steht unter
// Einstellungen → Experte. Wer „aus Dokumenten“ beginnt, ist meist Einsteiger
// und sollte dafür nicht in die Experten-Seite springen müssen. Derselbe
// Baustein öffnet deshalb hier in einem Dialog — im Posteingang (solange nichts
// eingerichtet ist und auf Wunsch auch danach) und im Einrichtungsassistenten
// beim Weg „Aus Dokumenten“. Die Karte in den Einstellungen bleibt, wo sie ist.
//
// Rechte wie in der Karte: Verwalter richten ein (der Server prüft es
// ebenfalls); Mitglieder sehen, was eingestellt ist, und einen Satz, dass ein
// Verwalter das einrichtet.
// =====================================================================

import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';
import { openModal } from './modal.js';
import { getSettings, invalidateSettings } from '../state.js';
import { sessionRole } from '../lib/levels.js';
import { DEMO } from '../lib/demo-mode.js';
import { renderReadingCard, wireReadingCard } from './inbox-cloud.js';

/** Der Knopf: hervorgehoben, solange niemand ausliest; danach zurückhaltend (in der Demo keiner). */
export function readingSetupButtonHtml(provider) {
  if (DEMO) return '';
  const ready = !!provider?.available;
  return `<p class="reading-setup-entry"><button type="button" class="btn ${ready ? 'btn--ghost btn--sm' : 'btn--primary'}" data-act="reading-setup">${escapeHtml(t('inboxDraft.setup.button'))}</button></p>`;
}

/**
 * Inhalt des Dialogs: ohne Verwaltungsrecht zuerst der Satz, wer einrichtet,
 * nach dem Speichern der Stand („liest jetzt aus mit …“), dann die Karte.
 */
export function readingSetupBodyHtml(status, settings, isAdmin, provider = null) {
  const head = isAdmin ? '' : `<p class="banner banner--info" data-role="reading-setup-member">${escapeHtml(t('inboxDraft.setup.memberHint'))}</p>`;
  const ready = provider?.available
    ? `<p class="banner banner--success" data-role="reading-setup-ready" role="status">${escapeHtml(t('inboxDraft.setup.ready', { service: provider.label || provider.id, model: provider.model || '–' }))}</p>` : '';
  const card = renderReadingCard(status, settings, isAdmin);
  return `${head}${ready}${card || `<p class="banner banner--warning">${escapeHtml(t('inboxDraft.setup.unavailable'))}</p>`}`;
}

/**
 * Dialog öffnen. Erfüllt sich beim Schließen mit dem Stand des Anbieters
 * (wie `provider` aus GET /api/inbox), damit der Aufrufer neu zeichnen kann.
 * @returns {Promise<object|null>}
 */
export async function openReadingSetup() {
  const isAdmin = sessionRole() === 'admin';
  let provider = null;
  const load = async () => {
    invalidateSettings();
    const [status, settings, inbox] = await Promise.all([api.inboxCloud().catch(() => null), getSettings().catch(() => null),
      api.inbox().catch(() => null)]);
    provider = inbox?.provider || null;
    return { status, settings };
  };
  const first = await load();
  const before = !!provider?.available;
  const ctrl = openModal({
    title: t('inboxDraft.setup.title'),
    size: 'lg',
    body: '<div class="reading-setup" data-role="reading-setup-body"></div>',
    footer: `<button type="button" class="btn btn--primary" data-act="reading-setup-done">${escapeHtml(t('inboxDraft.setup.done'))}</button>`,
    onMount({ modalEl, close }) {
      const host = modalEl.querySelector('[data-role="reading-setup-body"]');
      modalEl.querySelector('[data-act="reading-setup-done"]')?.addEventListener('click', () => close(true));
      const draw = ({ status, settings }) => {
        host.innerHTML = readingSetupBodyHtml(status, settings, isAdmin, provider?.available && !before ? provider : null);
        wireReadingCard(host, status, settings, isAdmin, async () => {
          if (!host.isConnected) return;
          draw(await load());
          host.querySelector('[data-role="reading-setup-ready"]')?.focus?.();
        });
      };
      draw(first);
    },
  });
  await ctrl.closedPromise;
  return provider;
}
