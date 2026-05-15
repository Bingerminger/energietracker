// =====================================================================
// Energietracker v1.2.0 — Theme system
// 2-state toggle (light / dark). Auf <html> wird `data-theme` als
// konkreter Wert gesetzt — das passiert bereits vor dem CSS-Laden im
// Anti-Flash-Skript in index.php. Dieses Modul übernimmt anschließend
// nur das Umschalten zur Laufzeit und persistiert die Wahl.
// =====================================================================

const STORAGE_KEY = 'et-theme';

const ICONS = {
  light: '☀️',  // aktuell hell → Klick wechselt zu dunkel
  dark:  '🌙',  // aktuell dunkel → Klick wechselt zu hell
};

function currentTheme() {
  const t = document.documentElement.getAttribute('data-theme');
  return t === 'light' ? 'light' : 'dark';
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem(STORAGE_KEY, theme); } catch {}
  // <meta name="color-scheme"> mitziehen, damit Browser-Form-Controls
  // (Datepicker, Scrollbars) die richtige Tönung wählen.
  document.head.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme);
  document.dispatchEvent(new CustomEvent('et:themechange', { detail: { theme } }));
}

function toggleTheme() {
  applyTheme(currentTheme() === 'light' ? 'dark' : 'light');
}

/**
 * Mounts the topbar toggle button. The button itself lives in index.php
 * so its initial render is server-rendered (no flash); we just attach
 * the icon and click handler here.
 */
export function mountThemeToggle(btn) {
  if (!btn) return;
  const iconEl = btn.querySelector('.topbar__btn-icon') || btn;
  const update = () => {
    const t = currentTheme();
    iconEl.textContent = ICONS[t] || ICONS.dark;
    btn.setAttribute('aria-pressed', t === 'light' ? 'true' : 'false');
    btn.setAttribute('title', t === 'light' ? 'Zu Dunkelmodus wechseln' : 'Zu Hellmodus wechseln');
    btn.setAttribute('aria-label', btn.getAttribute('title'));
  };
  btn.addEventListener('click', () => { toggleTheme(); update(); });
  update();

  // Wenn der User keine explizite Wahl getroffen hat und das System-Theme
  // wechselt (z.B. macOS schaltet abends auf dunkel), folgen wir dem.
  // Sobald einmal geklickt wurde, hat die User-Wahl Vorrang.
  if (window.matchMedia) {
    const mq = window.matchMedia('(prefers-color-scheme: light)');
    const onChange = (e) => {
      let saved;
      try { saved = localStorage.getItem(STORAGE_KEY); } catch {}
      if (saved !== 'light' && saved !== 'dark') {
        applyTheme(e.matches ? 'light' : 'dark');
        update();
      }
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange); // Safari < 14
  }
}

export { currentTheme, toggleTheme, applyTheme };
