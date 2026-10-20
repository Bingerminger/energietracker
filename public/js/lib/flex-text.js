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
  if (ev.basis !== 'sessions') return t('flex.lever.evDay');
  const all = tp('flex.lever.evSessions', ev.sessions, { count: fmt.int(ev.sessions) });
  return ev.share < 0.995 ? `${all} ${t('flex.lever.evPart', { share: fmt.pct(ev.share, 0) })}` : all;
}
