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
//
// v3.4.0 (N1020) — Werte aus den Daten (Zählernamen, Adressen, Aliase) stehen
// JSON-gequotet im YAML (`q()`), Schlüssel in Jinja-Ausdrücken sind auf einen
// sicheren Zeichensatz gefiltert (`key()`). Ein präparierter Zählername konnte
// sonst die Konfiguration ändern, die man in Home Assistant einfügt. Für
// gewöhnliche Werte bleibt die Ausgabe Zeichen für Zeichen gleich.
// =====================================================================
import { t } from './i18n.js';

/** YAML-Wert in doppelten Anführungszeichen (JSON-Zeichenkette ist gültiges YAML). */
const q = (v) => JSON.stringify(String(v ?? ''));
/** Schlüssel für Jinja-Zeichenketten in '…': nur Buchstaben, Ziffern, _ . - */
const key = (v) => String(v ?? '').replace(/[^A-Za-z0-9_.-]/g, '');

/**
 * Hostname des Containers, wenn die Seite unter Home-Assistant-Ingress läuft
 * (der Server nennt ihn nur dem Supervisor, `<meta name="et-ingress-host">`),
 * sonst null.
 */
export function ingressHost() {
  return document.querySelector('meta[name="et-ingress-host"]')?.getAttribute('content') || null;
}

/**
 * Adresse dieser Installation, wie Home Assistant sie aufrufen soll.
 * v3.1.0 (Ökosystem G3) — Unter Ingress zeigt die Adresszeile
 * https://ha…/api/hassio_ingress/<token>/: Das braucht eine HA-Sitzung, und das
 * Token wechselt. Home Assistant erreicht die App dann im internen Netz unter
 * ihrem Hostnamen. v3.5.0 (N1025) — aus settings.js hierher, weil das
 * Kalender-Abo dieselbe Adresse braucht.
 */
export function haBaseUrl() {
  const host = ingressHost();
  if (host) return `http://${host}`;
  return `${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}`.replace(/\/$/, '');
}

/**
 * Eine Adresse dieser Installation (relativ zur Seite oder absolut) so, wie
 * Home Assistant sie aufruft — unter Ingress ohne den Pfad
 * /api/hassio_ingress/<token>/, sonst unverändert.
 */
export function haUrl(path) {
  const abs = new URL(path, location.href);
  const dir = location.pathname.replace(/[^/]*$/, '');
  const rel = abs.pathname.startsWith(dir) ? abs.pathname.slice(dir.length) : abs.pathname.replace(/^\//, '');
  return `${haBaseUrl()}/${rel}${abs.search}`;
}

/** REST-Command für die configuration.yaml. */
export function haRestCommandYaml(baseUrl) {
  return [
    'rest_command:',
    '  energietracker_push:',
    `    url: ${q(`${baseUrl}/api.php/api/ingest`)}`,
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
    `        "date": "{{ now().strftime('%Y-%m-%d') }}",`,
    // v3.3.0 (F1021) — mit Zeitstempel: Verbrauch je Viertelstunde, wenn öfter gesendet wird
    '        "timestamp": "{{ now().isoformat() }}"',
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
  const pick = (k, path) => `{{ value_json.data.meters | selectattr('key', 'eq', '${key(k)}')`
    + ` | map(attribute='${path}', default=none) | first }}`;
  const deviceClass = (unit) => (unit === 'kWh' ? 'energy' : 'water');
  const lines = [
    'rest:',
    `  - resource: ${q(`${baseUrl}/api.php/api/summary`)}`,
    `    scan_interval: 3600   # ${t('settings.ha.yaml.scanComment')}`,
    '    headers:',
    `      Authorization: !secret energietracker_read   # ${t('settings.ha.yaml.readKeyComment')}`,
    '    sensor:',
  ];
  for (const m of list) {
    const id = m.key.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
    if (m.hasContract) {
      lines.push(
        `      - name: ${q(`${m.name} – ${t('settings.ha.yaml.sensorBalance')}`)}`,
        `        unique_id: energietracker_${id}_balance`,
        `        value_template: ${q(pick(m.key, 'contract.balance'))}`,
        '        device_class: monetary',
        '        unit_of_measurement: "EUR"',
      );
    }
    lines.push(
      `      - name: ${q(`${m.name} – ${t('settings.ha.yaml.sensorForecast')}`)}`,
      `        unique_id: energietracker_${id}_forecast_12m`,
      `        value_template: ${q(pick(m.key, 'forecast_12m.value'))}`,
      `        device_class: ${deviceClass(m.unit)}`,
      `        unit_of_measurement: ${q(m.unit)}`,
      `      - name: ${q(`${m.name} – ${t('settings.ha.yaml.sensorDaysSince')}`)}`,
      `        unique_id: energietracker_${id}_days_since_reading`,
      `        value_template: ${q(pick(m.key, 'days_since_reading'))}`,
      '        device_class: duration',
      '        unit_of_measurement: "d"',
    );
  }
  return lines.join('\n');
}

/**
 * v3.9.0 (F1033) — Sensor „Zu tun“ auf `GET /api/todo`: Anzahl (mit den
 * Punkten als Attribut), nächster Punkt und sein Datum. Ohne fälligen Punkt
 * zeigt der zweite „-“, der dritte „unbekannt“.
 *
 * @param {string} baseUrl
 */
export function haTodoSensorYaml(baseUrl) {
  return [
    'rest:',
    `  - resource: ${q(`${baseUrl}/api.php/api/todo`)}`,
    `    scan_interval: 900   # ${t('settings.ha.yaml.todoScanComment')}`,
    '    headers:',
    `      Authorization: !secret energietracker_read   # ${t('settings.ha.yaml.readKeyComment')}`,
    '    sensor:',
    `      - name: ${q(t('settings.ha.yaml.todoName'))}`,
    '        unique_id: energietracker_todo_count',
    '        value_template: "{{ value_json.data.count }}"',
    '        json_attributes_path: "$.data"',
    '        json_attributes:',
    '          - overdue',
    '          - items',
    `      - name: ${q(t('settings.ha.yaml.todoNextName'))}`,
    '        unique_id: energietracker_todo_next',
    `        value_template: "{{ value_json.data.next.title if value_json.data.next else '-' }}"`,
    `      - name: ${q(t('settings.ha.yaml.todoDateName'))}`,
    '        unique_id: energietracker_todo_next_date',
    '        value_template: "{{ value_json.data.next.date if value_json.data.next else none }}"',
    '        device_class: date',
  ].join('\n');
}

/**
 * v3.9.0 (F1033) — Automation für das Ziel „Webhook“: Home Assistant nimmt
 * die Nachricht an und gibt Titel und Text weiter. `mode: queued`, weil
 * mehrere Nachrichten kurz nacheinander kommen können (eine Automation im
 * Modus `single` ließe die übrigen fallen).
 *
 * @param {string} webhookId eine eigene, nicht erratbare ID
 */
export function haWebhookAutomationYaml(webhookId) {
  return [
    `alias: ${q(t('settings.ha.yaml.webhookAlias'))}`,
    'triggers:',
    '  - trigger: webhook',
    `    webhook_id: ${q(key(webhookId) || 'energietracker_…')}   # ${t('settings.ha.yaml.webhookIdComment')}`,
    '    allowed_methods:',
    '      - POST',
    '    local_only: true',
    'actions:',
    `  - action: notify.notify   # ${t('settings.ha.yaml.notifyComment')}`,
    '    data:',
    '      title: "{{ trigger.json.title }}"',
    '      message: "{{ trigger.json.message }}"',
    'mode: queued',
  ].join('\n');
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
    `alias: ${q(t('settings.ha.yaml.automationAlias'))}`,
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
      `        value_template: ${q(`{{ has_value('${key(e.sensor)}') }}`)}`,
      '    then:',
      '      - action: rest_command.energietracker_push',
      '        data:',
      `          utility: ${q(e.utility)}`,
      `          meter: ${q(e.meter)}`,
      `          sensor_entity: ${q(e.sensor)}`,
    );
  }
  lines.push('mode: single');
  return lines.join('\n');
}
