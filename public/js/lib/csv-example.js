// =====================================================================
// Beispiel-CSV für die Importe (v3.1.0, Review I18N-11).
//
// Bis v3.0 lag fest „datum;zaehlerstand;notiz;geschaetzt" mit deutschem Datum
// in jeder Sprache vor. Jetzt in der Sprache der Oberfläche: Kopfzeile aus
// csvLocal.*, Datum und Dezimalzeichen wie in den eigenen Exporten im Format
// „local" — der Import liest beides wieder ein.
// =====================================================================

import { t } from './i18n.js';
import { fmt, formatForInput } from './format.js';

function build(head, rows) {
  const sep = formatForInput(1.5).includes(',') ? ';' : ',';
  const cell = v => (String(v).includes(sep) || String(v).includes('"') ? `"${String(v).replace(/"/g, '""')}"` : String(v));
  return [head, ...rows].map(r => r.map(cell).join(sep)).join('\n') + '\n';
}

/** Ablesungen: Datum, Stand, Notiz, geschätzt. */
export function exampleReadingsCsv() {
  return build(
    ['date', 'reading', 'note', 'estimated'].map(k => t(`csvLocal.readings.${k}`)),
    [
      [fmt.date('2026-02-01'), formatForInput(12345.6), t('meters.import.exampleNote'), t('common.no')],
      [fmt.date('2026-03-01'), formatForInput(12567.8), '', t('common.yes')],
    ],
  );
}

/** Temperaturen: Datum, Mittel, Min, Max (°C). */
export function exampleTemperaturesCsv() {
  return build(
    ['date', 'avg', 'min', 'max'].map(k => t(`csvLocal.temperatures.${k}`)),
    [
      [fmt.date('2024-01-15'), formatForInput(4.2), formatForInput(-1), formatForInput(7.1)],
      [fmt.date('2024-01-16'), formatForInput(3.8), formatForInput(-2), formatForInput(6.5)],
    ],
  );
}

/** Lädt eine Beispiel-Datei herunter; Name aus csvLocal.file.* */
export function downloadExample(csv, fileKey) {
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${t(`csvLocal.file.${fileKey}`)}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}
