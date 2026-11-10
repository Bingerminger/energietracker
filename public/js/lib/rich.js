// =====================================================================
// v3.12.0 (Designsprache) — Auszeichnung aus den Katalogen, ohne rohes HTML.
//
// `t()` bleibt für Text, `rich()` ist der einzige Weg zu Auszeichnung:
//   "saldo.expected": "**Rund {amount} zurück.** Bei der Abrechnung am {date}."
//   rich('saldo.expected', { amount, date })   // → sicheres HTML
//
// Ablauf: Der Katalogtext wird zuerst maskiert, dann wandeln sich nur **…** zu
// <strong>, *…* zu <em>, `…` zu <code> und [Text](#/route) zu einem internen Link.
// Parameter setzt die Funktion erst danach ein, maskiert: Was ein Nutzer in einen
// Namen tippt (auch ** oder <script>), bleibt Text und wird nie zu einer Marke.
// Tests: tests/rich.test.mjs, tests/poisoned-render.test.mjs.
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';

// Private-Use-Zeichen: In Katalogtexten und maskierten Werten kommen sie nicht vor
const PARAM_OPEN = '';
const PARAM_CLOSE = '';
const CODE_OPEN = '';
const CODE_CLOSE = '';
const STRIP_SENTINELS = /[-]/g;

/** Bereits maskierten Text auszeichnen. */
function marks(escaped) {
  const codes = [];
  let s = escaped.replace(/`([^`\n]+)`/g, (_, code) => { codes.push(code); return `${CODE_OPEN}${codes.length - 1}${CODE_CLOSE}`; });
  // Nur interne Routen: Etwas anderes als #/ bleibt als Text stehen
  s = s.replace(/\[([^\]\n]+)\]\((#\/[\w\-/?=.%]*(?:&amp;[\w\-/?=.%]*)*)\)/g, '<a href="$2">$1</a>');
  s = s.replace(/\*\*(?=\S)([^\n]*?)(?<=\S)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(?<!\*)\*(?=[^\s*])([^*\n]*?)(?<=[^\s*])\*(?!\*)/g, '<em>$1</em>');
  return s.replace(new RegExp(`${CODE_OPEN}(\\d+)${CODE_CLOSE}`, 'g'), (_, i) => `<code>${codes[Number(i)]}</code>`);
}

/**
 * Text mit Marken zu sicherem HTML (für Texte, die schon aus dem Katalog kommen).
 * @param {string} text
 * @returns {string}
 */
export function richText(text) {
  return marks(esc(String(text ?? '').replace(STRIP_SENTINELS, '')));
}

/**
 * Katalogschlüssel mit Marken zu sicherem HTML.
 * @param {string} key
 * @param {Record<string, unknown>} [params]  Werte bleiben Text, auch wenn sie Marken oder HTML enthalten
 * @returns {string}
 */
export function rich(key, params = {}) {
  const names = Object.keys(params || {});
  const slots = Object.fromEntries(names.map((k, i) => [k, `${PARAM_OPEN}${i}${PARAM_CLOSE}`]));
  const html = marks(esc(t(key, slots)));
  return html.replace(new RegExp(`${PARAM_OPEN}(\\d+)${PARAM_CLOSE}`, 'g'), (_, i) => esc(String(params[names[Number(i)]] ?? '').replace(STRIP_SENTINELS, '')));
}
