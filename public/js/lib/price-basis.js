// =====================================================================
// v3.7.0 — Basis eines Rechnungspreises am Feld: Ein Nettopreis mit gelesenem
// Steuersatz wird umgerechnet; bestätigt gilt der gedruckte Wert, korrigiert
// ein Wert in derselben Basis wie gedruckt. Damit niemand rät, steht am Feld
// die Basis und das Ergebnis („netto laut Rechnung, mit 19 % USt =
// 0,3570 € brutto“). Eine Stelle für Vorschau (views/inbox.js) und Entwurf
// (lib/inbox-draft.js); umgerechnet wird wie auf dem Server (Evidence::grossOf,
// vier Nachkommastellen).
// =====================================================================

import { t } from './i18n.js';
import { fmt } from './format.js';

/** Bruttowert eines Werts in der gedruckten Basis (netto mit Satz). */
export const grossOf = (value, vatPct) => Math.round(Number(value) * (1 + Number(vatPct) / 100) * 10000) / 10000;

/**
 * Text „netto laut Rechnung, mit {vat} % USt = {gross} brutto“.
 * @param {{ vatPct: number, gross: number, kind?: string }} p  kind `price_ct` (ct/kWh), sonst Euro
 * @returns {string} leer ohne Satz oder Wert
 */
export function priceBasisText({ vatPct, gross, kind = 'money' }) {
  if (!Number.isFinite(Number(vatPct)) || !Number.isFinite(Number(gross))) return '';
  const value = kind === 'price_ct' ? `${fmt.dec(Number(gross), 4)} ct/kWh` : `${fmt.dec(Number(gross), 4)} €`;
  return t('inbox.priceBasis.net', { vat: fmt.dec(Number(vatPct), 2), gross: value });
}
