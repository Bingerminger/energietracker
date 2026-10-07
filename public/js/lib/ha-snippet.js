// =====================================================================
// Home-Assistant-Vorlagen zum Kopieren (v2.5.3).
//
// Dieselben Vorlagen stehen in docs/anleitungen/home-assistant.md und im EN-Spiegel;
// tests/ha-snippet.test.mjs vergleicht sie zeilenweise (ohne Kommentare
// und URL). Bis v2.5.2 gab es zwei Fassungen, und keine war richtig:
//
//  - `!secret` wirkt in Home Assistant nur als ganzer YAML-Wert. Mit
//    „Bearer !secret …" kam der Text wörtlich an, Antwort 401 (DOC-02).
//  - `float` ohne Ersatzwert: Ist der Sensor nicht verfügbar, scheitert der
//    Push. `float(0)` buchte stattdessen einen Zählerstand 0, und die
//    nächste echte Ablesung zählte als Verbrauch eines einzigen Tages
//    (FE-01, DOC-01). Die Automatisierung prüft zusätzlich `has_value`.
//
// Die Kommentare im YAML sind übersetzt, die Struktur ist es nicht.
// =====================================================================
import { t } from './i18n.js';

/** REST-Command für die configuration.yaml. */
export function haRestCommandYaml(baseUrl) {
  return [
    'rest_command:',
    '  energietracker_push:',
    `    url: "${baseUrl}/api.php/api/ingest"`,
    '    method: POST',
    '    headers:',
    `      Authorization: !secret energietracker_auth   # ${t('settings.ha.yaml.secretComment')}`,
    '      Content-Type: "application/json"',
    `    # ${t('settings.ha.yaml.floatComment')}`,
    '    payload: >',
    '      {',
    '        "utility": "{{ utility }}",',
    '        "meter": "{{ meter }}",',
    '        "value": {{ states(sensor_entity) | float }},',
    `        "date": "{{ now().strftime('%Y-%m-%d') }}"`,
    '      }',
  ].join('\n');
}

/**
 * v3.1.0 (Paket H1, API-33) — Werte zurück nach Home Assistant: REST-Sensoren
 * auf `GET /api/summary`, eine Abfrage für alle Sensoren. Je Zähler Saldo,
 * Prognose der nächsten 12 Monate und Tage seit der letzten Ablesung.
 *
 * @param {string} baseUrl
 * @param {{key: string, name: string, unit: string, hasContract: boolean}[]} meters
 */
export function haRestSensorYaml(baseUrl, meters) {
  const list = meters.length ? meters : [{ key: 'strom.m_strom_default', name: 'Strom', unit: 'kWh', hasContract: true }];
  const pick = (key, path) => `{{ value_json.data.meters | selectattr('key', 'eq', '${key}')`
    + ` | map(attribute='${path}', default=none) | first }}`;
  const deviceClass = (unit) => (unit === 'kWh' ? 'energy' : 'water');
  const lines = [
    'rest:',
    `  - resource: "${baseUrl}/api.php/api/summary"`,
    `    scan_interval: 3600   # ${t('settings.ha.yaml.scanComment')}`,
    '    headers:',
    `      Authorization: !secret energietracker_read   # ${t('settings.ha.yaml.readKeyComment')}`,
    '    sensor:',
  ];
  for (const m of list) {
    const id = m.key.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
    if (m.hasContract) {
      lines.push(
        `      - name: "${m.name} – ${t('settings.ha.yaml.sensorBalance')}"`,
        `        unique_id: energietracker_${id}_balance`,
        `        value_template: "${pick(m.key, 'contract.balance')}"`,
        '        device_class: monetary',
        '        unit_of_measurement: "EUR"',
      );
    }
    lines.push(
      `      - name: "${m.name} – ${t('settings.ha.yaml.sensorForecast')}"`,
      `        unique_id: energietracker_${id}_forecast_12m`,
      `        value_template: "${pick(m.key, 'forecast_12m.value')}"`,
      `        device_class: ${deviceClass(m.unit)}`,
      `        unit_of_measurement: "${m.unit}"`,
      `      - name: "${m.name} – ${t('settings.ha.yaml.sensorDaysSince')}"`,
      `        unique_id: energietracker_${id}_days_since_reading`,
      `        value_template: "${pick(m.key, 'days_since_reading')}"`,
      '        device_class: duration',
      '        unit_of_measurement: "d"',
    );
  }
  return lines.join('\n');
}

/** Lese-Schlüssel für die Sensoren (secrets.yaml), nur bei eingeschalteter Anmeldung nötig. */
export function haReadSecretYaml() {
  return 'energietracker_read: "Bearer etk_…"';
}

/** Eintrag für die secrets.yaml — der ganze Header-Wert, samt „Bearer". */
export function haSecretsYaml() {
  return 'energietracker_auth: "Bearer et_…"';
}

/**
 * Automatisierung für den Automations-Editor („In YAML bearbeiten").
 * Je Zähler ein Push, aber nur, wenn der Sensor einen Wert hat.
 *
 * @param {{utility: string, meter: string, sensor: string}[]} entries
 */
export function haAutomationYaml(entries) {
  const list = entries.length ? entries : [
    { utility: 'strom', meter: 'stromzaehler_haus', sensor: 'sensor.stromzaehler_total_kwh' },
  ];
  const lines = [
    `alias: "${t('settings.ha.yaml.automationAlias')}"`,
    'triggers:',
    '  - trigger: time',
    '    at: "23:55:00"',
    'actions:',
    `  # ${t('settings.ha.yaml.hasValueComment')}`,
  ];
  for (const e of list) {
    lines.push(
      '  - if:',
      '      - condition: template',
      `        value_template: "{{ has_value('${e.sensor}') }}"`,
      '    then:',
      '      - action: rest_command.energietracker_push',
      '        data:',
      `          utility: "${e.utility}"`,
      `          meter: "${e.meter}"`,
      `          sensor_entity: "${e.sensor}"`,
    );
  }
  lines.push('mode: single');
  return lines.join('\n');
}
