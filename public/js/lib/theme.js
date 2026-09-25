// =====================================================================
// Energietracker — Theme
//
// Auf <html> steht `data-theme="light|dark"` als konkreter Wert — das setzt
// bereits das Anti-Flash-Skript in index.php vor dem CSS. Dieses Modul
// übernimmt das Umschalten zur Laufzeit und merkt die Wahl.
//
// v2.11.0 (Review UI-29, UI-25, UI-26) — drei Stufen statt zwei: System
// (Standard, folgt macOS/iOS), Hell, Dunkel. Wer einmal umgeschaltet hatte,
// kam bisher nicht mehr zurück zur Systemeinstellung. Der Knopf sagt, was
// gerade gilt („Darstellung: System"), statt nur „gedrückt". Und
// `theme-color` folgt der Wahl — die Statusleiste der Home-Bildschirm-App
// richtete sich bisher nur nach dem System.
// =====================================================================

import { t, hasTranslation } from './i18n.js';

const STORAGE_KEY = 'et-theme';
const MODES = ['system', 'light', 'dark'];
const ICONS = { system: '🖥️', light: '☀️', dark: '🌙' };
// Hintergrund der Kopfleiste (--bg-1) je Theme — Farbe der Statusleiste
const BAR_COLOR = { light: '#ffffff', dark: '#111827' };

function storedMode() {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    return v === 'light' || v === 'dark' ? v : 'system';
  } catch { return 'system'; }
}

function systemTheme() {
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

function currentTheme() {
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function currentMode() { return storedMode(); }

/** Modus setzen: 'system' | 'light' | 'dark' (ältere Aufrufer: 'light'/'dark'). */
function applyTheme(mode) {
  const m = MODES.includes(mode) ? mode : 'system';
  const theme = m === 'system' ? systemTheme() : m;
  document.documentElement.setAttribute('data-theme', theme);
  try {
    if (m === 'system') localStorage.removeItem(STORAGE_KEY);
    else localStorage.setItem(STORAGE_KEY, m);
  } catch { /* privat: gilt bis zum Neuladen */ }
  // Browser-Steuerelemente (Datepicker, Scrollbars) in passender Tönung
  document.head.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme);
  document.head.querySelectorAll('meta[name="theme-color"]').forEach(el => el.setAttribute('content', BAR_COLOR[theme]));
  document.dispatchEvent(new CustomEvent('et:themechange', { detail: { theme, mode: m } }));
}

function toggleTheme() {
  const next = MODES[(MODES.indexOf(storedMode()) + 1) % MODES.length];
  applyTheme(next);
}

let updateButton = () => {};

/** Beschriftung neu setzen (nach dem Laden der Sprache). */
export function refreshThemeToggle() { updateButton(); }

/**
 * Bindet den Knopf der Kopfleiste. Er steht in index.php, damit er ohne
 * Flackern gerendert wird; hier kommen Symbol, Beschriftung und Klick dazu.
 */
export function mountThemeToggle(btn) {
  if (!btn) return;
  const iconEl = btn.querySelector('.topbar__btn-icon') || btn;
  updateButton = () => {
    const mode = storedMode();
    iconEl.textContent = ICONS[mode];
    // Vor dem Laden der Sprache bleibt die Beschriftung aus index.php stehen
    if (!hasTranslation('app.theme.toggle')) return;
    const label = t('app.theme.toggle', { mode: t('app.theme.' + mode) });
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
    btn.removeAttribute('aria-pressed');
  };
  btn.addEventListener('click', () => { toggleTheme(); updateButton(); });
  // theme-color und color-scheme zum gespeicherten Stand bringen
  applyTheme(storedMode());
  updateButton();

  // Im Modus „System" folgt die App dem Wechsel des Systems (z. B. abends).
  if (window.matchMedia) {
    const mq = window.matchMedia('(prefers-color-scheme: light)');
    const onChange = () => { if (storedMode() === 'system') { applyTheme('system'); updateButton(); } };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange); // Safari < 14
  }
}

export { currentTheme, currentMode, toggleTheme, applyTheme };
