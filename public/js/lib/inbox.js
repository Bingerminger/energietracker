// =====================================================================
// v3.7.0 (Posteingang) — Texte und Werte des Posteingangs, ohne Server.
//
// Der Server liefert Schlüssel und rohe Werte (Art, Status, Felder mit
// Belegstelle, Vorschläge mit Katalogschlüssel und Parametern); hier entstehen
// daraus die Texte in der Sprache der Oberfläche. Eine Stelle für Ansicht,
// Prüfliste und Test (tests/inbox.test.mjs).
// =====================================================================

import { t, tp, getCurrency } from './i18n.js';
import { fmt, intlLocale } from './format.js';
import { advanceRhythm } from './advance-rhythm.js';   // v3.9.0 (F1034)

/** Was noch Arbeit macht (Badge in der Navigation). */
export const OPEN_STATUSES = ['new', 'queued', 'read', 'failed'];
export const openCount = (entries) => (entries || []).filter(e => OPEN_STATUSES.includes(e?.status)).length;

/** Die Arten, wie der Server sie kennt (InboxSchema::TYPES). */
export const TYPES = ['invoice', 'contract_confirmation', 'price_change', 'cancellation', 'supply_start',
  'meter_change', 'reading_request', 'advance_change', 'dunning', 'withdrawal', 'other'];

export const typeLabel = (type) => t(type ? `inbox.type.${type}` : 'inbox.type.unknown');
export const statusLabel = (status) => t(`inbox.status.${status || 'new'}`);
export const fieldLabel = (name) => t(`inbox.field.${name}`);
export const reasonText = (reasons) => (reasons || []).map(r => t(`inbox.reason.${r}`)).join(' · ');

/** Ein Wert nach der Art seines Felds (Datum, Betrag, Preis, Zahl, Auswahl, Text). */
export function valueText(kind, v) {
  if (v === null || v === undefined || v === '') return '–';
  switch (kind) {
    case 'date': return fmt.date(v);
    case 'money': return fmt.money(v);
    case 'price_ct': return `${fmt.dec(v, 4)} ct/kWh`;
    case 'number': return fmt.dec(v, 4);
    case 'enum': return t(`inbox.option.${v}`);
    case 'bool': return t(v ? 'common.yes' : 'common.no');   // v3.7.0 (treffsicher, Nachbesserung 4) — Ablese-Art
    default: return String(v);
  }
}

/**
 * Ein Eintrag eines Listenfelds (Abschläge, Gas-Umrechnung, Arbeitspreise).
 * v3.7.0 (treffsicher, Nachbesserung 2) — `entry` ist der ganze Eintrag: Hat der
 * Server einen Nettopreis mit gelesenem Steuersatz erkannt (`entry.net`), steht
 * der Bruttopreis daneben, mit dem der Vertrag rechnet.
 */
export function itemText(field, item, entry = null) {
  const v = item || {};
  switch (field) {
    case 'advances_paid': return v.date ? `${fmt.money(v.amount)} (${fmt.date(v.date)})` : fmt.money(v.amount);
    case 'gas_factors': return t('inbox.item.gasFactor', { from: fmt.date(v.from), to: fmt.date(v.to), z: fmt.dec(v.z, 4), hs: fmt.dec(v.hs, 3) });
    case 'working_prices': return entry?.net
      ? t('inbox.item.priceNet', { from: fmt.date(v.from), value: `${fmt.dec(v.ct, 4)} ct/kWh`, vat: fmt.dec(entry.net.vat_pct, 2), gross: `${fmt.dec(entry.net.gross, 4)} ct/kWh` })
      : t('inbox.item.price', { from: fmt.date(v.from), value: `${fmt.dec(v.ct, 4)} ct/kWh` });
    // v3.7.0 (treffsicher, Nachbesserung 4) — Stände je Zähler
    case 'meter_readings': {
      const st = (n, d) => (n === null || n === undefined ? '–' : `${fmt.dec(n, 3)}${d ? ` (${fmt.date(d)})` : ''}`);
      const text = t('inbox.item.meterReading', { meter: v.meter_number || '–', from: st(v.reading_old, v.reading_old_date), to: st(v.reading_new, v.reading_new_date) });
      return v.estimated === true ? `${text} · ${t('inbox.item.estimated')}` : text;
    }
    // v3.9.0 (F1034) — Wasserpreise je Sparte und Abschnitt, netto mit Satz und brutto
    case 'water_prices': {
      const price = waterPrice(v.price, v.unit);
      const p = { section: sectionName(v.section), label: v.label || '–', from: fmt.date(v.from), to: fmt.date(v.to),
        price: v.unit === 'm2_year' && v.area_m2 ? `${price} × ${fmt.dec(v.area_m2, 2)} m²` : price };
      return entry?.net
        ? t('inbox.item.waterPriceNet', { ...p, vat: fmt.dec(entry.net.vat_pct, 2), gross: waterPrice(entry.net.gross, v.unit) })
        : t('inbox.item.waterPrice', p);
    }
    case 'advance_new_dates': return fmt.date(v.date);
    default: return Object.values(v).filter(x => x !== null && x !== undefined).join(' · ');
  }
}

/** v3.9.0 (F1034) — Name einer Sparte der Wasserrechnung (ohne Zeichen davor). */
export const sectionName = (s) => (s ? t(`inbox.option.${s}`) : '–');

/** v3.9.0 (F1034) — Wasserpreis wie gedruckt: € je m³, Jahr, Monat oder m² und Jahr. */
function waterPrice(v, unit) {
  if (v === null || v === undefined || v === '') return '–';
  const per = { m3: t('inbox.unit.perM3'), year: t('inbox.unit.perYear'), month: t('inbox.unit.perMonth'), m2_year: t('inbox.unit.perM2Year') }[unit] || '';
  return `${priceMoney(v)}${per ? ` ${per}` : ''}`;
}

/** v3.9.0 (F1034) — Preis mit bis zu vier Stellen („2,142 €“, „13,375 €“); Beträge bleiben bei fmt.money. */
function priceMoney(v) {
  return new Intl.NumberFormat(intlLocale(), { style: 'currency', currency: getCurrency(), minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(v));
}

export { advanceRhythm };

/** Nachzahlung (positiv) oder Guthaben (negativ) als Satzteil. */
export function resultText(result) {
  const n = Number(result);
  if (result === null || result === undefined || !Number.isFinite(n)) return '';
  return t(n > 0 ? 'inbox.result.due' : 'inbox.result.credit', { amount: fmt.money(Math.abs(n)) });
}

/** Wert einer Preiszeile je Art des Vorschlags (v3.9.0: Wasser je m³ in ct). */
function rowValue(kind, v, key = '') {
  if (v === null || v === undefined || v === '') return '–';
  if (kind === 'contract_price') return `${fmt.dec(v, 4)} ct/kWh`;
  if (kind === 'contract_water_price') return key === 'ct_per_m3' ? `${fmt.dec(v, 4)} ct/m³` : priceMoney(v);
  return fmt.money(v);
}

/** v3.9.0 (F1034) — ein Bestandteil eines Preises: „Basispreis 50,00 € je Jahr netto + 7 % USt“. */
function partText(part) {
  const price = part.unit === 'kwh' ? `${fmt.dec(part.price, 4)} ct/kWh` : waterPrice(part.price, part.unit);
  const text = part.label ? `${part.label} ${price}` : price;
  return part.net && part.vat_pct !== null && part.vat_pct !== undefined ? t('inbox.line.net', { value: text, vat: fmt.dec(part.vat_pct, 2) }) : text;
}

/**
 * v3.9.0 (F1034) — Aufschlüsselung eines Vorschlags in Zeilen: beim Vertrag aus
 * der Rechnung je Sparte und Abschnitt der Preis und woraus er entsteht (Basis-
 * und Bereitstellungspreis, netto mit Satz), dazu der Abschlagsplan; bei einer
 * Preiszeile aus mehreren Bestandteilen (Energiesteuer, CO₂-Preis) diese.
 * @returns {string[]}
 */
export function proposalLines(p) {
  const parts = (list) => (list || []).map(partText);
  const from = (list) => {
    const xs = parts(list);
    if (!xs.length) return '';
    return t('inbox.line.parts', { parts: xs.join(' + ') });
  };
  if (p.kind === 'contract_create') {
    return (p.params?.lines || []).map((l) => {
      const what = l.section ? sectionName(l.section) : t('inbox.line.energy');
      const base = { what, from: fmt.date(l.from) };
      let text;
      if (l.kind === 'working') text = t('inbox.line.working', { ...base, value: l.section ? `${fmt.dec(l.value, 4)} ct/m³` : `${fmt.dec(l.value, 4)} ct/kWh` });
      else if (l.kind === 'base') text = t(l.section ? 'inbox.line.base' : 'inbox.line.baseOnly', { ...base, value: priceMoney(l.value) });
      else if (l.kind === 'rate') text = t('inbox.line.rate', { ...base, value: priceMoney(l.value), area: fmt.dec(l.area, 2) });
      else if (l.kind === 'advance') {
        const rhythm = advanceRhythm(l.every, l.dates);
        return t('inbox.line.advance', { from: fmt.date(l.from), value: fmt.money(l.value) }) + (rhythm ? `, ${rhythm}` : '');
      } else return '';
      const how = from(l.parts);
      return how ? `${text} (${how})` : text;
    }).filter(Boolean);
  }
  if ((p.kind === 'contract_price' || p.kind === 'contract_water_price') && (p.params?.parts || []).length > 1) return [from(p.params.parts)];
  if (p.kind === 'contract_water_price' && (p.params?.parts || []).some(x => x.net)) return [from(p.params.parts)];
  return [];
}

/**
 * Text eines Vorschlags („Ablesung am 31.12.2025: 1.235,6 m³“), aus
 * Katalogschlüssel und Parametern des Servers.
 * @param {{kind: string, label_key: string, params?: object}} p
 * @param {{unit?: string}} [ctx]
 */
export function proposalText(p, { unit = '' } = {}) {
  const a = { ...(p.params || {}) };
  for (const k of ['date', 'from', 'to', 'old_date']) if (typeof a[k] === 'string') a[k] = fmt.date(a[k]);
  if (p.kind === 'reading') a.counter = `${fmt.dec(a.counter, 3)}${unit ? ` ${unit}` : ''}`;
  // v3.7.0 (treffsicher, Nachbesserung 4) — Zählertausch aus der Rechnung mit beiden Ständen
  if (p.kind === 'meter_change') for (const k of ['old_final', 'new_initial']) if (typeof a[k] === 'number') a[k] = `${fmt.dec(a[k], 3)}${unit ? ` ${unit}` : ''}`;
  if (['contract_price', 'contract_base_price', 'contract_advance', 'contract_water_price'].includes(p.kind)) {
    a.value = rowValue(p.kind, p.params?.value, p.data?.key);
    if (p.params?.old !== undefined && p.params?.old !== null) a.old = rowValue(p.kind, p.params.old, p.data?.key);
  }
  // v3.9.0 (F1034) — Wasser: Sparte und Fläche; Abschlag mit Rhythmus („alle 2 Monate, am …“)
  if (p.kind === 'contract_water_price') {
    a.section = sectionName(p.params?.section);
    if (p.params?.area !== undefined && p.params?.area !== null) a.area = fmt.dec(p.params.area, 2);
  }
  if (p.kind === 'contract_advance') {
    const rhythm = advanceRhythm(p.params?.every, p.params?.dates);
    if (rhythm) return `${t(p.label_key, a)}, ${rhythm}`;
  }
  if (p.kind === 'bill' || p.kind === 'bill_book') a.result = resultText(p.params?.result) || '–';
  if (p.kind === 'gas_factor') { a.z = fmt.dec(a.z, 4); a.hs = fmt.dec(a.hs, 3); a.factor = fmt.dec(a.factor, 4); }
  if (p.kind === 'contract_start' && typeof p.params?.old === 'string') a.old = fmt.date(p.params.old);
  if (p.kind === 'contract_ids') a.ids = ['contract_number', 'customer_number'].filter(k => a[k]).map(k => `${fieldLabel(k)} ${a[k]}`).join(', ');
  return t(p.label_key, a);
}

/** Was im Bestand schon steht (bei Widerspruch, Überschneidung, vorhanden). */
export function detailText(p, { unit = '' } = {}) {
  const d = p.detail;
  if (!d) return '';
  switch (p.kind) {
    case 'reading': return d.counter !== undefined ? t('inbox.detail.reading', { counter: `${fmt.dec(d.counter, 3)}${unit ? ` ${unit}` : ''}` }) : '';
    case 'contract_price': case 'contract_base_price': case 'contract_advance': case 'contract_water_price':
      return d.value !== undefined ? t('inbox.detail.row', { value: rowValue(p.kind, d.value, p.data?.key) }) : '';
    case 'bill': return d.period_from ? t('inbox.detail.billOverlap', { from: fmt.date(d.period_from), to: fmt.date(d.period_to) })
      : t('inbox.detail.bill', { amount: fmt.money(d.invoice?.amount_eur) });
    case 'contract_create': return d.start ? t('inbox.detail.contract', { provider: d.provider || '–', from: fmt.date(d.start), to: d.end ? fmt.date(d.end) : '…' }) : '';
    case 'contract_end': return d.end ? t('inbox.detail.end', { date: fmt.date(d.end) }) : '';
    case 'contract_start': return d.start ? t('inbox.detail.start', { date: fmt.date(d.start) }) : '';
    case 'gas_factor': return d.kwh_per_m3 ? t('inbox.detail.factor', { factor: fmt.dec(d.kwh_per_m3, 4) }) : '';
    case 'meter_ids': return d.malo_id ? t('inbox.detail.malo', { malo: d.malo_id }) : '';
    case 'meter_change': return d.serial ? t('inbox.detail.serial', { serial: d.serial }) : '';
    default: return '';
  }
}

/** Liste der Felder für die Anzeige: Kernfelder und gefüllte zuerst, leere Nebenfelder ans Ende. */
export function orderedFields(fields, schema) {
  const names = Object.keys(schema || fields || {});
  const rank = (n) => {
    const f = fields?.[n];
    if (!f || f.status === 'empty') return schema?.[n]?.core ? 1 : 3;
    return f.status === 'check' ? 0 : 2;
  };
  return names.map((n, i) => ({ name: n, i, r: rank(n) })).sort((a, b) => a.r - b.r || a.i - b.i).map(x => x.name);
}

// ── v3.7.0 (Paket betrieb) — Kernaussage und nächster Schritt ─────────────

const ct = (v) => (v === null || v === undefined ? '–' : `${fmt.dec(v, 2)} ct/kWh`);

/**
 * Parameter einer Kernaussage in Text: Tage, Beträge, Preise, Zählerstände.
 * Welche Bedeutung `old`/`new` haben, sagt der Schlüssel (Preis, Grundpreis,
 * Zählerstand) — der Server liefert die rohen Werte.
 */
export function gistParams(key, params = {}) {
  const a = { ...params };
  for (const k of ['date', 'start', 'last', 'from', 'to']) if (k in a) a[k] = typeof a[k] === 'string' && a[k] ? fmt.date(a[k]) : '–';
  for (const k of ['amount', 'diff', 'recommended', 'theirs', 'ours']) if (k in a) a[k] = typeof a[k] === 'number' ? fmt.money(a[k]) : '–';
  if ('balance' in a) a.balance = typeof a.balance === 'number' ? (resultText(a.balance) || fmt.money(0)) : '–';
  if ('result' in a) a.result = typeof a.result === 'number' ? (resultText(a.result) || fmt.money(0)) : '–';
  if (String(key).endsWith('.workingPrice')) { a.old = ct(params.old); a.new = ct(params.new); }
  else if (String(key).endsWith('.basePrice')) {
    a.old = params.old === null || params.old === undefined ? '–' : fmt.money(params.old);
    a.new = fmt.money(params.new);
  } else if (String(key).includes('.meterChange.')) {
    a.old = params.old === null || params.old === undefined ? '–' : fmt.dec(params.old, 3);
    a.new = params.new === null || params.new === undefined ? '–' : fmt.dec(params.new, 3);
  }
  // v3.7.0 (treffsicher) — offene Punkte: Feldnamen statt Schlüssel
  if (Array.isArray(a.fields)) a.fields = a.fields.map(fieldLabel).join(', ');
  if (typeof a.kwh === 'number') a.kwh = fmt.dec(a.kwh, 0);
  if (typeof a.pct === 'number') a.pct = fmt.dec(a.pct, 1);
  for (const k of ['person', 'provider', 'meter', 'serial', 'offer']) if (a[k] === null || a[k] === undefined || a[k] === '') a[k] = '–';
  return a;
}

/** Ein Satz der Kernaussage (oder eines Details); Zählungen über tp(). */
export function gistLine(key, params = {}) {
  if (!key) return '';
  if (key === 'inbox.gist.detail.gap') return tp('inbox.gist.detail.gap', Number(params.days) || 0, gistParams(key, params));
  return t(key, gistParams(key, params));
}

/** Der Satz der Kernaussage. */
export const gistText = (g) => (g?.key ? gistLine(g.key, g.params) : '');

/** Die Zahl der Kernaussage („+48,30 € im Jahr“, „Nachzahlung 42,70 €“, „31.12.2026“). */
export function gistFigure(f) {
  if (!f || f.value === null || f.value === undefined) return '';
  const v = Number(f.value);
  const signed = (x) => (f.signed && x > 0 ? '+' : f.signed && x < 0 ? '−' : '') + fmt.money(f.signed ? Math.abs(x) : x);
  switch (f.kind) {
    case 'money_year': return t('inbox.gist.figure.perYear', { amount: signed(v) });
    case 'money_month': return t('inbox.gist.figure.perMonth', { amount: signed(v) });
    case 'money_rate': return t('inbox.gist.figure.perRate', { amount: signed(v) });   // v3.9.0 (F1034) — Abschlag mit Rhythmus
    case 'money': return fmt.money(v);
    case 'result': return resultText(v);
    case 'date': return fmt.date(f.value);
    case 'price_ct': return ct(v);
    default: return String(f.value);
  }
}

/** Beschriftung des Knopfs der Kernaussage. */
export const gistActionLabel = (a) => (a?.key ? t(a.key, gistParams(a.key, a.params || {})) : '');

/** Die aufklappbaren Details als Sätze. */
export const gistDetails = (g) => (g?.details || []).map(d => gistLine(d.key, d.params || {})).filter(Boolean);

/**
 * Akte: Satz zu einem Eintrag — die Kernaussage eines Posteingang-Dokuments,
 * sonst Ergebnis der Rechnung, Zählerstand des Fotos, Beleg am Vertrag.
 */
export function dossierText(item) {
  if (item.kind === 'inbox') {
    const text = gistText(item.gist) || typeLabel(item.type);
    // v3.7.0 (treffsicher) — dasselbe Schreiben in einer zweiten Datei
    return item.duplicate_of ? `${text} · ${t('inbox.duplicate.short')}` : text;
  }
  if (item.kind === 'bill') {
    const period = t('inbox.dossier.period', { from: fmt.date(item.period_from), to: fmt.date(item.period_to) });
    return item.result_eur ? `${period} · ${resultText(item.result_eur)}` : period;
  }
  if (item.kind === 'photo') return t('inbox.dossier.photo', { counter: item.counter === null || item.counter === undefined ? '–' : fmt.dec(item.counter, 3) });
  return t('inbox.dossier.receipt', { provider: item.provider || '–' });
}

/** Art eines Eintrags der Akte für Spalte und Filter (Dokumentart, Rechnung, Beleg, Foto). */
export const dossierKind = (item) => (item.kind === 'inbox' ? `inbox:${item.type}` : item.kind);
export const dossierKindLabel = (k) => (k.startsWith('inbox:') ? typeLabel(k.slice(6)) : t(`inbox.dossier.kind.${k}`));

/**
 * Wohin „Von Hand erfassen“ führt — je Art das passende Formular zuerst.
 * @returns {Array<{key: string, href: string}>}
 */
export function manualTargets(type, { utility = '', meterId = '', from = '', until = '' } = {}) {
  const u = encodeURIComponent(utility || 'gas');
  const q = new URLSearchParams({ utility: utility || 'gas', capture: '1', ...(meterId ? { meter: meterId } : {}),
    ...(from && until ? { from, until } : {}) });
  const all = {
    bill: `#/bill-check?${q}`,
    contract: `#/utility/${u}/contracts`,
    reading: `#/zaehlerstaende${meterId ? `?meter=${encodeURIComponent(meterId)}` : ''}`,
    meter: `#/utility/${u}/meters`,
    reminder: '#/reminders?add=1',
  };
  const first = {
    invoice: 'bill', contract_confirmation: 'contract', price_change: 'contract', cancellation: 'contract', advance_change: 'contract',
    supply_start: 'reading', reading_request: 'reading', meter_change: 'meter',
  }[type] || 'contract';
  return [first, ...Object.keys(all).filter(k => k !== first)].map(key => ({ key, href: all[key] }));
}
