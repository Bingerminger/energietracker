// =====================================================================
// v3.2.0 (F1019) — Umschalter der Nutzungsstufe in der Kopfleiste, neben
// Tag/Nacht. Eine Auswahlliste: am Mac ein Menü, am iPhone das Rad des
// Systems — beides ohne eigene Tastatur- und Fokuslogik.
//
// Nach dem Umschalten bauen sich Seitenleiste, Tabs und die offene Ansicht
// neu auf (Ereignis `et:levelchange`).
// =====================================================================

import { t, hasTranslation } from './i18n.js';
import { escapeHtml } from './format.js';
import { LEVELS, LEVEL_ICONS, currentLevel, saveLevel } from './levels.js';
import { toastErr } from '../components/toast.js';

let select = null;

function optionsHtml() {
  return LEVELS.map(l => `<option value="${l}"${l === currentLevel() ? ' selected' : ''}>${LEVEL_ICONS[l]} ${escapeHtml(t('level.' + l))}</option>`).join('');
}

/** Beschriftung nach dem Laden der Sprache. */
export function refreshLevelSwitch() {
  if (!select || !hasTranslation('level.switch')) return;
  select.innerHTML = optionsHtml();
  select.setAttribute('aria-label', t('level.switch'));
  select.setAttribute('title', t('level.switch'));
}

/** Setzt die Auswahl vor den Tag/Nacht-Knopf. */
export function mountLevelSwitch(actions) {
  if (!actions || document.getElementById('level-switch')) return;
  select = document.createElement('select');
  select.id = 'level-switch';
  select.className = 'topbar__select';
  select.innerHTML = optionsHtml();
  const theme = document.getElementById('theme-toggle');
  actions.insertBefore(select, theme || null);
  refreshLevelSwitch();
  select.addEventListener('change', async () => {
    const before = currentLevel();
    try {
      await saveLevel(select.value);
    } catch (e) {
      select.value = before;
      toastErr(e.message);
    }
  });
  window.addEventListener('et:levelchange', () => { if (select.value !== currentLevel()) select.value = currentLevel(); });
}
