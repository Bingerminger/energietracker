// =====================================================================
// v3.6.0 — Sätze der Flexibilität aus den Daten des Servers
// (FlexibilityInsightService): Zeitfenster, Gerät, Handlungssatz und die
// Grundlage der Ladefenster des Autos. Eine Quelle für die Seite
// „Flexibilität“ und die Kachel „Dein Hebel“ der Übersicht; der Jahresbericht
// bildet dieselben Sätze in PHP (ReportModelService::flexPage).
// =====================================================================
import { fmt } from './format.js';
import { t, tp } from './i18n.js';

const hh = (h) => `${String(h).padStart(2, '0')}:00`;
const list = (key) => t(key).split(',').map(s => s.trim());

/** Zeitfenster als Text: „sonntags 12:00–15:00 Uhr“ oder „02:00–05:00 Uhr“. */
export function windowText(w) {
  if (!w) return '';
  const span = { from: hh(w.from), to: hh(w.to) };
  return w.weekday ? t('flex.window.week', { day: list('flex.weekdays.often')[w.weekday - 1] || '', ...span }) : t('flex.window.day', span);
}

/** Bezeichnung eines Hebels: „E-Auto (Wallbox)“, „Waschmaschine“ … — „Wärmepumpe (Wärmepumpe)“ wird „Wärmepumpe“. */
export function leverLabel(l) {
  const name = String(l.name || '').trim();
  if (l.kind !== 'ev' && l.kind !== 'heat_pump') return t(`flex.lever.kind.${l.kind}`, { name });
  const plain = t(`flex.lever.plain.${l.kind}`);
  return !name || name.toLowerCase() === plain.toLowerCase() ? plain : t(`flex.lever.kind.${l.kind}`, { name });
}

/** Handlungssatz: „Lade das Auto 02:00–05:00 Uhr: bis zu 140 € im Jahr.“ */
export function actionText(a) {
  return t(`flex.actions.${a.kind}.${a.window?.what || 'cheap'}`, { window: windowText(a.window), eur: fmt.eur0(a.eur) });
}

/**
 * Grundlage der Ladefenster des Autos (`insights.ev_availability`): Steckzeiten
 * aus evcc — für einen Teil des Ladestroms mit dem Rest als Beispielwert — oder
 * die Annahme „am Ladetag den ganzen Tag bereit“. Leer ohne Wallbox.
 */
export function evBasisText(ev) {
  if (!ev) return '';
  // v3.7.0 — eigene Steckzeit aus den Einstellungen (ohne evcc)
  if (ev.basis === 'window' && ev.window) return t('flex.lever.evWindow', { from: hh(ev.window.from), until: hh(ev.window.until) });
  if (ev.basis !== 'sessions') return t('flex.lever.evDay');
  const all = tp('flex.lever.evSessions', ev.sessions, { count: fmt.int(ev.sessions) });
  return ev.share < 0.995 ? `${all} ${t('flex.lever.evPart', { share: fmt.pct(ev.share, 0) })}` : all;
}

/** v3.7.0 — Schild am Auto: Steckzeiten aus evcc, eigene Steckzeit oder „ganzer Ladetag (Beispielwert)“. */
export function availabilityTagText(ev) {
  if (!ev) return '';
  if (ev.basis === 'sessions') return t('flex.availability.sessions');
  if (ev.basis === 'window' && ev.window) return t('flex.availability.window', { from: hh(ev.window.from), until: hh(ev.window.until) });
  return t('flex.availability.day');
}

/**
 * v3.7.0 — die Annahmen der Hebel als Satz, je Teil „(Beispielwert)“ oder
 * „(eigener Wert)“ — eigen, sobald in den Einstellungen gespeichert
 * (`assumptions.own`, FlexibilityInsightService::assumptions()). Bis v3.6
 * hießen alle „Beispielwerte“.
 */
export function assumptionsText(a = {}) {
  const own = a.own || {};
  const ap = a.appliances || {};
  const mark = (on, part) => t(on ? 'flex.assume.own' : 'flex.assume.example', { part });
  const parts = [
    mark(own.heat_pump_share, t('flex.assume.hp', { hp: fmt.pct(a.heat_pump_share ?? 0.3, 0) })),
    ...['washer', 'dishwasher'].filter(k => ap[k]).map(k => mark(own[k],
      t(`flex.assume.${k}`, { kwh: fmt.dec(ap[k].kwh_per_cycle, 1), n: fmt.dec(ap[k].cycles_per_week, 1) }))),
    t('flex.assume.hours', { h: fmt.int(ap.washer?.hours ?? 2) }),
  ];
  return t('flex.assume.lead', { parts: parts.join('; ') });
}
