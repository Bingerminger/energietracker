// =====================================================================
// Home-Assistant-Vorlagen zum Kopieren (v2.5.3).
//
// Dieselben Vorlagen stehen in docs/HOME-ASSISTANT.md und im EN-Spiegel;
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
