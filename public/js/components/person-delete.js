// =====================================================================
// v3.6.0 — „Person löschen“ unter Einstellungen → Zugriff (nur Verwalter).
//
// Der Dialog sagt vorher, was geschieht (GET /api/users/{id}/handover):
// wie viele offene Termine an wen übergehen (die löschende Person) und wie
// viele erledigte Einträge den Namen mit „(gelöscht)“ behalten — oder, mit
// dem Haken „ehemalige Person“, ihn verlieren. Die Nummer der Person wird
// nie wieder vergeben (Personenverzeichnis, PeopleService). Auch Personen
// ohne Zugang (Backup, Beispielhaushalt) lassen sich so löschen; ohne Anmeldung
// übernimmt niemand die offenen Termine.
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { openModal } from './modal.js';
import { toastOk, toastErr } from './toast.js';

/** Sätze des Dialogs aus der Vorschau {to, open, done}; `former` = Haken „ehemalige Person“. */
export function handoverText(preview, former = false) {
  const open = Number(preview?.open) || 0;
  const done = Number(preview?.done) || 0;
  const lines = [];
  if (open === 0) lines.push(t('settings.users.handoverNone'));
  else if (preview?.to?.name) lines.push(tp('settings.users.handover', open, { to: preview.to.name }));
  // ohne löschende Person (Anmeldung aus): niemand übernimmt
  else lines.push(tp('settings.users.handoverNobody', open));
  if (done > 0) lines.push(former ? tp('settings.users.keepDoneFormer', done) : tp('settings.users.keepDone', done));
  return lines;
}

/**
 * Dialog öffnen und nach dem Bestätigen löschen; `onDone` zeichnet neu.
 * Ohne Vorschau (Fehler) bleibt der Dialog beim bisherigen Hinweis.
 */
export async function confirmPersonDelete(userId, onDone) {
  let preview = null;
  try { preview = await api.userHandover(userId); } catch { /* Dialog ohne Zahlen */ }
  const name = preview?.person?.name || '';
  const lines = (former) => handoverText(preview, former).map(s => `<li>${esc(s)}</li>`).join('');
  openModal({
    title: t('settings.users.delete'),
    // v3.6.0 — eine Person ohne Zugang konnte sich nie anmelden
    body: `<p>${esc(preview?.person?.has_account === false ? t('settings.users.deleteConfirmNoAccess') : t('settings.users.deleteConfirm'))}</p>
      ${preview ? `<ul class="small" data-role="handover">${lines(false)}</ul>` : ''}
      <label class="check"><input type="checkbox" id="user-del-anon"> ${esc(t('settings.users.anonymizeActivity'))}</label>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <button type="button" class="btn btn--danger" data-act="ok">${esc(t('settings.users.delete'))}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      const anon = bodyEl.querySelector('#user-del-anon');
      anon?.addEventListener('change', () => {
        const ul = bodyEl.querySelector('[data-role="handover"]');
        if (ul) ul.innerHTML = lines(!!anon.checked);
      });
      modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', async () => {
        try {
          const r = await api.deleteUser(userId, { anonymizeActivity: !!anon?.checked });
          close(true);
          const moved = Number(r?.handed_over) || 0;
          if (name) toastOk(t('settings.users.deletedToast', { name }) + (moved > 0 ? ' ' + tp('settings.users.handedOver', moved) : ''));
          onDone?.();
        } catch (e) { toastErr(e.message); }
      });
    },
  });
}
