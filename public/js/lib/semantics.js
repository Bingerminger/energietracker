// =====================================================================
// v2.13.0 (Review FE-06, UI-18, UI-19) — was eine Zahl je Verbrauchsart
// bedeutet, an einer Stelle.
//
// Bis v2.12 behandelten Übersicht, Analyse und Tabellen jede Verbrauchsart
// als Verbrauch: Weniger Einspeisung erschien grün, ein Erlös als „Kosten",
// und ein Guthaben als negative Zahl. Die Arten kommen aus der SSOT
// (`accounting_kind` in `/api/utilities`).
// =====================================================================
import { t } from './i18n.js';
import { fmt, escapeHtml } from './format.js';

export const isFeedIn     = (u) => u?.accounting_kind === 'feed_in';
export const isGeneration = (u) => u?.accounting_kind === 'generation';
export const isPv         = (u) => isFeedIn(u) || isGeneration(u);
/** Mehr ist besser: Einspeisung und Erzeugung. */
export const moreIsBetter = (u) => isPv(u);
/** Gas: m³ werden über Zustandszahl × Brennwert zu kWh (Rechnungsprüfung, m³-Spalte). */
export const usesGasFactors = (u) => u?.unit === 'm³' && u?.consumption_unit === 'kWh';

/** Farbklasse einer Veränderung: mehr Verbrauch rot, mehr Einspeisung grün. */
export function changeTone(delta, u) {
  if (!delta) return '';
  const good = moreIsBetter(u) ? delta > 0 : delta < 0;
  return good ? 'ton-ok' : 'ton-fehler';
}

/**
 * Saldo in Kundensicht (Review UI-18, Entscheidung „saldo"): „Guthaben
 * 449,72 €" grün, „Nachzahlung 62,74 €" rot — ohne Vorzeichen. Intern gilt
 * Kosten − Abschläge, positiv heißt Nachzahlung; bei der Einspeisung ist ein
 * positiver Saldo ein Anspruch an den Netzbetreiber.
 *
 * `signed` ist die Form für dichte Tabellen: Vorzeichen aus Kundensicht,
 * „+" heißt Guthaben.
 *
 * @param {number|null|undefined} v
 * @param {object} u  Verbrauchsart
 * @param {(n: number) => string} [money]  Formatierer, Standard fmt.eur
 */
/**
 * v3.7.0 (Nachlauf vor dem Release) — Spanne einer unsicheren Saldo-Schätzung
 * (`projection_uncertain.projected_range`) als kurzer Text, etwa „ca. 120 € Guthaben
 * bis ca. 80 € Nachzahlung“ — dieselben Wörter wie der Satz der Saldo-Karte. Positiver
 * Saldo = Nachzahlung. Kein Punktwert: Wo die Schätzung unsicher ist, steht nur die Spanne.
 *
 * @param {object|null|undefined} unc
 * @returns {string} leer ohne Spanne
 */
export function uncertainRangeText(unc) {
  const r = Array.isArray(unc?.projected_range) ? unc.projected_range.map(Number) : null;
  if (!r || r.length !== 2 || !r.every(Number.isFinite)) return '';
  const word = (v) => Math.abs(v) < 1 ? t('balanceUncertain.even')
    : t(v > 0 ? 'balanceUncertain.due' : 'balanceUncertain.credit', { amount: t('report.fmt.approx', { amount: fmt.eur0(Math.abs(v)) }) });
  const [a, b] = balanceRangeEnds(r);
  return t('balanceUncertain.short', { low: word(a), high: word(b) });
}

/**
 * v3.9.0 (Paket doku, Befund am Heizgrenze-Hinweis) — die Enden einer Saldo-Spanne
 * (positiv = Nachzahlung) in Lesereihenfolge: Liegen beide im Guthaben, vom kleineren zum
 * größeren Betrag („zwischen ca. 325 € und ca. 431 € Guthaben“; bis dahin „zwischen ca. 431 €
 * Guthaben und ca. 325 € Guthaben“, der Zahl nach). Sonst der Zahl nach: Guthaben vor
 * Nachzahlung, zwei Nachzahlungen vom kleineren zum größeren Betrag. Für Saldo-Karte,
 * Vertragsübersicht, „Wohin geht mein Geld?“ und den Heizgrenze-Hinweis.
 *
 * @param {number[]} r  [ein Ende, anderes Ende]
 * @returns {number[]}  [zuerst, danach]
 */
export function balanceRangeEnds(r) {
  const lo = Math.min(Number(r[0]), Number(r[1])), hi = Math.max(Number(r[0]), Number(r[1]));
  return hi < 0 ? [hi, lo] : [lo, hi];
}

/**
 * v3.10.0 (Skeptiker v3.9.0, R2-3; Wirklichkeitsabgleich) — der erwartete Saldo zur Abrechnung,
 * eine Quelle für Saldo-Karte, Einsteiger-Übersicht und Vertragsübersicht: die Summe einer
 * Abrechnung über mehrere Verträge (`settle_balance`), sonst der Vertrag selbst; dazu die Spanne
 * einer unsicheren Schätzung (`unc`) oder die Breite einer sicheren (`band`, ab 1 €), beide um
 * die übrigen Verträge verschoben (`settle_range`). Positiv = Nachzahlung.
 *
 * @param {object|null|undefined} c  Zeile des Vertragsstatus (oder `contract` der Übersicht)
 * @returns {{value: number, unc: object|null, band: object|null, settled: boolean}|null}
 */
export function expectedBalance(c) {
  if (!c || c.projected_end_balance == null || !Number.isFinite(Number(c.projected_end_balance))) return null;
  const settled = c.settle_balance != null && Number.isFinite(Number(c.settle_balance));
  // v3.10.0 (Skeptiker H3) — die Spanne der Summe aus allen Teilen (`settle_range`); unsicher, wenn ein Teil
  // unsicher ist (`settle_uncertain`). Ältere Server ohne `settle_uncertain`: die Unsicherheit des Vertrags selbst
  const sr = settled && Array.isArray(c.settle_range) && c.settle_range.length === 2 ? c.settle_range.map(Number) : null;
  const own = Array.isArray(c.projection_uncertain?.projected_range) ? c.projection_uncertain : null;
  const unc0 = settled && 'settle_uncertain' in c ? (Array.isArray(c.settle_uncertain?.projected_range) ? c.settle_uncertain : null) : own;
  const unc = unc0 && sr ? { ...unc0, projected_range: sr } : unc0;
  const ownBand = Number(c.projection_band?.half) >= 1 ? c.projection_band : null;
  const band = unc ? null
    : sr && Math.abs(sr[1] - sr[0]) >= 2 ? { level_pct: 80, estimated_days: 0, weather: 0, ...(ownBand || {}), low: sr[0], high: sr[1], half: (sr[1] - sr[0]) / 2 }
    : settled ? null : ownBand;
  return { value: settled ? Number(c.settle_balance) : Number(c.projected_end_balance), unc, band, settled };
}

/**
 * v3.10.0 (R2-3) — die Breite einer sicheren Schätzung als kurzer Zusatz, dieselben Wörter
 * wie die Spanne: „je nach Wetter und Schätzung ca. 480 € bis ca. 550 € Guthaben“; ohne
 * Wetterteil (Strom, Wasser) „je nach Schätzung …“; ohne gemessene Streuung (H2) „zu wenige
 * gemessene Monate, je nach Schätzung …“. Leer ohne Band.
 * @param {object|null|undefined} band  `projection_band` bzw. `expectedBalance().band`
 * @returns {string}
 */
export function bandText(band) {
  const range = band ? uncertainRangeText({ projected_range: [band.low, band.high] }) : '';
  if (!range) return '';
  if (band.few_measured) return t('balanceBand.shortFew', { range });
  return t(Number(band.weather) > 0 ? 'balanceBand.short' : 'balanceBand.shortNoWeather', { range });
}

export function balanceView(v, u, money = fmt.eur) {
  const n = Number(v);
  if (v == null || !Number.isFinite(n)) return null;
  // v3.12.0 (Sichtung S1) — neutral nur bei genau 0, und dann ohne Betrag
  if (Math.abs(n) < 0.005) {
    return { credit: null, amount: 0, word: t('saldo.even'), cls: 'muted', signed: t('saldo.even') };
  }
  const credit = isFeedIn(u) ? n > 0 : n < 0;
  const amount = Math.abs(n);
  return {
    credit,
    amount,
    word: t(credit ? 'saldo.credit' : (isFeedIn(u) ? 'saldo.reclaim' : 'saldo.due')),
    cls: credit ? 'ton-ok' : 'ton-fehler',
    signed: `${credit ? '+' : '−'}${money(amount)}`,
  };
}

/**
 * v3.12.0 (Sichtung S1) — ein Saldo als fertiges HTML: Wort und Betrag in der Farbe seines Vorzeichens, bei genau 0
 * nur das Wort. Die eine Stelle für Karten, Zeilen und Sätze, leer ohne Saldo.
 * @param {number|null|undefined} v  positiv = Nachzahlung (Einspeisung: Anspruch)
 * @param {object} u  Verbrauchsart
 * @param {(n: number) => string} [money]
 * @returns {string}
 */
export function balanceHtml(v, u, money = fmt.eur) {
  const b = balanceView(v, u, money);
  if (!b) return '';
  return b.credit === null
    ? `<span class="${b.cls}" data-saldo="even">${escapeHtml(b.word)}</span>`
    : `<span class="${b.cls}" data-saldo="${b.credit ? 'credit' : 'due'}">${escapeHtml(`${b.word} ${money(b.amount)}`)}</span>`;
}

/**
 * v3.12.0 (Sichtung S1) — der Schlüssel unter `utility.verdict` nach dem Vorzeichen. Der Server meldet `verdict` mit einer
 * Toleranz von 5 € (API, bleibt); die Oberfläche nennt bis auf den Cent, wohin der Saldo zeigt.
 */
export function verdictKey(v, u) {
  const b = balanceView(v, u);
  if (!b || b.credit === null) return 'balanced';
  if (isFeedIn(u)) return b.credit ? 'payout' : 'reclaim';
  return b.credit ? 'refund' : 'surcharge';
}

/**
 * v3.12.0 (Sichtung S1) — Katalogtext mit Teilen, die schon HTML sind: Der Rest wird maskiert, die Teile bleiben.
 * @param {string} key
 * @param {object} params  Parameter als Text
 * @param {Object<string, string>} html  Parameter als fertiges HTML
 * @returns {string}
 */
export function tHtml(key, params, html) {
  const names = Object.keys(html);
  const marks = Object.fromEntries(names.map((k, i) => [k, `\u0001${i}\u0002`]));
  return escapeHtml(t(key, { ...params, ...marks })).replace(/\u0001(\d+)\u0002/g, (_, i) => html[names[Number(i)]]);
}

/**
 * v3.12.0 (Sichtung S1) — die Spanne einer unsicheren Schätzung wie `uncertainRangeText`, jedes Ende in der Farbe seines
 * Vorzeichens: Eine Spanne über die Null hinweg zeigt Grün und Rot, statt neutral zu bleiben.
 * @param {object|null|undefined} unc
 * @returns {string} leer ohne Spanne
 */
export function uncertainRangeHtml(unc) {
  const r = Array.isArray(unc?.projected_range) ? unc.projected_range.map(Number) : null;
  if (!r || r.length !== 2 || !r.every(Number.isFinite)) return '';
  const end = (v) => {
    if (Math.abs(v) < 1) return `<span class="muted" data-saldo="even">${escapeHtml(t('balanceUncertain.even'))}</span>`;
    const text = t(v > 0 ? 'balanceUncertain.due' : 'balanceUncertain.credit', { amount: t('report.fmt.approx', { amount: fmt.eur0(Math.abs(v)) }) });
    return `<span class="${v > 0 ? 'ton-fehler' : 'ton-ok'}" data-saldo="${v > 0 ? 'due' : 'credit'}">${escapeHtml(text)}</span>`;
  };
  const [a, b] = balanceRangeEnds(r);
  return tHtml('balanceUncertain.short', {}, { low: end(a), high: end(b) });
}

/**
 * v3.9.0 (Skeptiker N8) — Kachel „Kosten“: Bei einer Art mit Verträgen ohne Vertrag
 * im Jahr und ohne Kosten heißt es „kein Vertrag“ statt „0,00 €“ (nicht im
 * Abrechnungsjahr, nicht bei Einspeisung und Lieferungen).
 */
export function costWithoutContract({ u, hasContract, cost, billing = false, delivery = false }) {
  return !billing && !hasContract && !isFeedIn(u) && !delivery && u?.has_contracts !== false && Math.abs(Number(cost) || 0) < 0.005;
}
