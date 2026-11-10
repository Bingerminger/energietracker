// =====================================================================
// Die Feldgruppen der Einstellungen: Definition, Darstellung, Einsammeln beim Speichern.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { fmt, escapeHtml, parseDecimal, formatForInput } from '../lib/format.js';
import { toastErr } from '../components/toast.js';
import { t, hasTranslation, getCurrencySymbol } from '../lib/i18n.js';
import { CV_UNITS, cvUnit, gasFactorOf } from '../lib/gas-factor.js';
import { yearMap, yearsText } from '../lib/region.js';
import { disclosureHtml } from '../components/disclosure.js';
import { icon } from '../lib/icons.js';
import { sessionRole } from '../lib/levels.js';


// Each group renders as a settings card. `hint` is an optional explanatory
// line under the card title; each field may carry its own `hint` too.
// Gruppen-/Feld-Titel, -Hints und -Labels werden zur Render-Zeit über t()
// aufgelöst: settings.group.<gkey>.{title,hint} und settings.field.<key>.{label,hint}.
// Einheiten mit deutschen Wörtern liegen als unitKey vor; Symbol-Einheiten
// (kWh/m³, °C, σ …) bleiben literal.
export const GROUPS = [
  // ── Allgemein ──
  // v3.2.0 (F1019) — level: ab welcher Nutzungsstufe die Gruppe erscheint
  { gkey: 'dashboard', page: 'display', icon: 'home', level: 'advanced', fields: [
    { key: 'dashboard_months',         step: '1' },
    { key: 'forecast_months',          unitKey: 'settings.unit.months', step: '1' },
    { key: 'alert_days_since_reading', unitKey: 'settings.unit.daysNoReading', step: '1' },
  ]},
  // v3.6.0 — Hinweis auf neue Versionen (GitHub, einmal am Tag; nur auf Wunsch)
  { gkey: 'updates', page: 'display', icon: 'neu', fields: [
    // v3.12.0 — Härtung: fragt GitHub, also nur Verwalter
    { key: 'update_check', type: 'bool', admin: true },
  ]},
  // v3.10.0 — Danke sagen: Hinweis aufs Unterstützen in Nutzen-Momenten (lib/thanks.js)
  { gkey: 'thanks', page: 'display', icon: 'tasse', fields: [
    { key: 'support_hint', type: 'bool' },
  ]},
  // v3.7.1 — Umgebungsname als Abzeichen neben dem Logo (Test, Haus …); leer = keins
  { gkey: 'environment', page: 'display', icon: 'etikett', fields: [
    { key: 'env_name', type: 'text', maxlength: 12, placeholderKey: 'settings.placeholder.envName' },
  ]},
  { gkey: 'contractReminders', page: 'display', icon: 'glocke', level: 'advanced', fields: [
    { key: 'contract_remind_days_1', unitKey: 'settings.unit.days', step: '1' },
    { key: 'contract_remind_days_2', unitKey: 'settings.unit.days', step: '1' },
    { key: 'contract_remind_days_3', unitKey: 'settings.unit.days', step: '1' },
    { key: 'reminder_warn_days_before', unitKey: 'settings.unit.daysBefore', step: '1' },
    { key: 'reminder_overdue_days',     unitKey: 'settings.unit.days', step: '1' },
  ]},
  // ── Haushalt & Gebäude ──
  { gkey: 'building', page: 'household:building', icon: 'block', fields: [
    { key: 'wohnflaeche_m2', unit: 'm²', step: '1' },
    // v2.10.0 — Klartext statt Kürzel: efh zählt als Ein-/Zweifamilienhaus (GEG § 82)
    { key: 'gebaeudetyp',    type: 'select', options: ['efh', 'rh', 'mfh', 'whg'], optionLabels: 'settings.buildingTypes' },
    // v2.10.0 (CALC-07) — für die energieausweis-nahe Kennzahl
    { key: 'beheizter_keller',     type: 'bool' },
    { key: 'warmwasser_dezentral', type: 'bool' },
  ]},
  // v3.12.0 (m4b) — Angaben für Briefe (freiwillig): Der Brief-Dialog füllt Absender und Lieferanschrift vor.
  // Bleiben in den eigenen Daten und im Backup; KI und Posteingang bekommen sie nie (SettingsService::LETTER_KEYS).
  { gkey: 'letters', page: 'household:building', icon: 'brief', fields: [
    { key: 'letter_sender_name', type: 'text', maxlength: 60, autocomplete: 'name' },
    { key: 'letter_sender_address', type: 'lines', rows: 3, autocomplete: 'street-address' },
    { key: 'letter_supply_address', type: 'text', maxlength: 60, placeholderKey: 'settings.placeholder.supplyAddress' },
  ]},
  // v3.1.0 (H3) — Mieter-Paket (F1008), Heizwärme (B8), Warmwasser (CALC-28)
  { gkey: 'tenancy', page: 'household:building', icon: 'schluessel', fields: [
    { key: 'wohnverhaeltnis', type: 'select', options: ['eigentum', 'miete'], optionLabels: 'settings.tenure' },
    { key: 'waerme_energietraeger', type: 'select', options: ['none', 'gas', 'heizoel', 'pellets', 'fernwaerme', 'strom'], optionLabels: 'settings.heatSource' },
    { key: 'warmwasser_energietraeger', type: 'select', options: ['none', 'gas', 'heizoel', 'pellets', 'fernwaerme', 'strom', 'waerme'], optionLabels: 'settings.heatSource' },
    { key: 'warmwasser_temp_c', unit: '°C', step: '1' },
  ]},
  // v3.1.0 (H8, MKT-11) — Einordnung mit eigenen Vergleichswerten (z. B. aus Strom-/Heizspiegel)
  { gkey: 'reference', page: 'household:building', icon: 'lineal', level: 'advanced', fields: [
    { key: 'reference_strom_kwh',   unit: 'kWh/a', step: '1' },
    { key: 'reference_heat_kwh_m2', unit: 'kWh/m²·a', step: '1' },
    { key: 'reference_source',      type: 'text', placeholderKey: 'settings.placeholder.referenceSource' },
    { key: 'warmwasser_elektrisch', type: 'bool' },
  ]},
  { gkey: 'water', page: 'household:building', icon: 'art-wasser', level: 'advanced', fields: [
    { key: 'wasser_personen_anzahl',   step: '1' },
    { key: 'wasser_personen_referenz', unitKey: 'settings.unit.lPerPersonDay', step: '1' },
    { key: 'wasser_sparindex_gut',     step: '1' },
    { key: 'wasser_sparindex_warnung', step: '1' },
  ]},
  // ── Verbrauchsarten & Abrechnung ──
  // v2.12.0 (Review UI-13) — alle Abrechnungsstichtage an einer Stelle; bis
  // v2.11 standen Fernwärme, Heizöl und Pellets unter „Termine & Empfehlungen".
  { gkey: 'billing', page: 'household:utilities', icon: 'kalender', fields: [
    { key: 'billing_cycle_anchor_gas',        type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_strom',      type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_wasser',     type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_fernwaerme', type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    // v2.13.0 (Review FE-10) — Heizöl und Pellets haben keine Abschläge und
    // damit keinen Stichtag (die Felder wirkten nicht); die Einspeisevergütung
    // rechnet dagegen nach einem, das Feld fehlte
    { key: 'billing_cycle_anchor_pv_einspeisung', type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
  ]},
  { gkey: 'physical', page: 'household:utilities', icon: 'kolben', wide: true, level: 'advanced', fields: [
    // v2.5.0 — F1012: datierte Liste (Zustandszahl × Brennwert je Stichtag)
    // statt eines Skalars. Eigener Feldtyp, siehe renderGasFactors().
    { key: 'gas_conversion_factors', type: 'gasfactors' },
    { key: 'hdd_base_temp', unit: '°C', step: '0.5' },
  ]},
  { gkey: 'delivery', page: 'household:utilities', icon: 'art-heizoel', level: 'advanced', fields: [
    { key: 'heizoel_kwh_per_l', unit: 'kWh/L', step: '0.1' },
    { key: 'pellets_kwh_per_kg', unit: 'kWh/kg', step: '0.1' },
    { key: 'tank_warn_pct', unitKey: 'settings.unit.pctRemaining', step: '1' },
  ]},
  // v3.7.0 — Flexibilität: eigene Werte statt der Beispielwerte (leer = Beispielwert,
  // `example_keys`); die Seite Flexibilität und der Bericht nennen sie dann „eigener Wert“
  { gkey: 'flex', page: 'household:utilities', icon: 'stoppuhr', level: 'advanced', fields: [
    { key: 'flex_heat_pump_share_pct', unit: '%', step: '1' },
    { key: 'flex_washer_cycles_per_week', unitKey: 'settings.unit.perWeek', step: '0.5' },
    { key: 'flex_washer_kwh_per_cycle', unitKey: 'settings.unit.kwhPerCycle', step: '0.1' },
    { key: 'flex_dishwasher_cycles_per_week', unitKey: 'settings.unit.perWeek', step: '0.5' },
    { key: 'flex_dishwasher_kwh_per_cycle', unitKey: 'settings.unit.kwhPerCycle', step: '0.1' },
    { key: 'flex_ev_plugged_from', unitKey: 'settings.unit.oClock', step: '1' },
    { key: 'flex_ev_plugged_until', unitKey: 'settings.unit.oClock', step: '1' },
  ]},
  // v3.6.0 — die lange Liste standardmäßig zu (Kacheln stehen ruhig); derselbe
  // Aufklappbereich wie „Prognose im Detail“ (components/disclosure.js)
  { gkey: 'co2', page: 'calculation', icon: 'co2', level: 'expert', collapse: 'et-settings-co2-open', fields: [
    { key: 'co2_gas',    unit: 'g/kWh', step: '1' },
    { key: 'co2_strom',  unit: 'g/kWh', step: '1' },
    // v2.10.0 (CALC-19) — Strom je Jahr (Umweltbundesamt), eigener Feldtyp
    { key: 'co2_strom_years', type: 'co2years' },
    { key: 'co2_wasser', unit: 'g/m³',  step: '1' },
    { key: 'co2_fernwaerme', unit: 'g/kWh', step: '1' },
    // v2.10.0 — gerechnet wird je kWh; die Beschriftung stand bis v2.9 auf g/L bzw. g/kg
    { key: 'co2_heizoel',    unit: 'g/kWh', step: '1' },
    { key: 'co2_pellets',    unit: 'g/kWh', step: '1' },
    // v3.1.0 (H7, CALC-29) — eigener Vermeidungsfaktor der PV; leer = Strommix
    { key: 'co2_pv_avoided', unit: 'g/kWh', step: '1' },
  ]},
  // v3.1.0 (H7, CALC-29) — Balkonkraftwerk ohne Einspeisezähler
  // v3.7.0 — ab Erfahren (bis v3.6 Experte), wie die PV-Anlage im Zählerdialog
  { gkey: 'pv', page: 'household:utilities', icon: 'art-pv_einspeisung', level: 'advanced', fields: [
    { key: 'pv_assumed_self_consumption_pct', unit: '%', step: '1' },
  ]},
  // ── Zugriff ──
  // v2.6.0 — frame-ancestors der Content-Security-Policy (index.php): Wer die
  // App in eine Home-Assistant-Webseitenkarte einbettet, trägt deren Adresse ein.
  { gkey: 'embedding', page: 'access', icon: 'bild', fields: [
    { key: 'frame_ancestors', type: 'text', placeholderKey: 'settings.placeholder.frameAncestors' },
  ]},
  // v3.4.0 (N1020) — Hostnamen gegen DNS-Rebinding (nur Verwalter, zusammen mit ET_ALLOWED_HOSTS)
  { gkey: 'hosts', page: 'access', icon: 'globus', fields: [
    { key: 'allowed_hosts', type: 'text', placeholderKey: 'settings.placeholder.allowedHosts' },
  ]},
  // ── Experte (eingeklappt) ──
  { gkey: 'regression', page: 'calculation', icon: 'verlaufskurve', fields: [
    { key: 'min_days_period',        step: '1' },
    { key: 'min_hdd_regression',     step: '0.5' },
    { key: 'blend_max',              step: '0.05' },
    // v2.13.0 (Review UI-17) — lesbare Namen statt der Schlüssel
    { key: 'forecast_model',         type: 'select', options: ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'], optionLabels: 'forecast.models' },
    { key: 'segmented_split_mode',   type: 'select', options: ['auto', 'fixed'], optionLabels: 'settings.splitModes' },
    // v2.13.0 — wirkte seit v2.8.0 auf das Prognoseband, stand aber nirgends
    { key: 'confidence_band_sigma',  unit: 'σ', step: '0.01' },
    { key: 'segmented_fixed_split',  unitKey: 'common.hddShort', step: '1' },   // v2.13.0 — übersetzt
    { key: 'anomaly_threshold',      unit: 'σ', step: '0.1' },
  ]},
  { gkey: 'remindersRec', page: 'calculation', icon: 'kolben', fields: [
    { key: 'recommendation_anomaly_sigma', unit: 'σ', step: '0.1' },
    { key: 'recommendation_trend_pct_year', unitKey: 'settings.unit.pctYear', step: '0.5' },
    { key: 'delivery_baseload_share', step: '0.05' },
  ]},
  // v3.1.0 (Paket H4) — CO₂-Preis je Jahr (eigene Werte statt Länderprofil) und Szenario
  // v3.4.0 (N1021) — schmal: zwei Spalten, zwei Zahlen (bis v3.3 über 1130 px)
  { gkey: 'co2price', page: 'calculation', icon: 'etikett', fields: [
    { key: 'co2_price_eur_t_years', type: 'co2years' },
    { key: 'co2_price_scenario_eur_t', unitKey: 'settings.unit.eurPerTonne', step: '1' },
    { key: 'co2_price_scenario_from', step: '1' },
  ]},
  // v3.1.0 (Paket H2) — Belege und Texterkennung im Heimnetz (MKT-08)
  { gkey: 'attachments', page: 'data', icon: 'anhang', wide: true, level: 'expert', fields: [
    { key: 'attachments_max_mb', unit: 'MB', step: '1' },
  ]},
  // v3.7.0 (Karte „Auslesen“) — Adresse und Schnittstelle des eigenen Diensts
  // (`ocr_endpoint`, `ocr_api`) teilt die Texterkennung mit dem Posteingang; sie
  // stehen einmal, in der Karte „Auslesen“ unter „Lokal“. Hier bleibt, was nur
  // die Zählerfotos betrifft (Bildmodell, Zeitlimit). Die frühere Gruppe
  // „Posteingang“ (inbox_provider, inbox_model, inbox_timeout_s) ist in derselben Karte.
  { gkey: 'ocr', page: 'connections:reading', icon: 'suche', fields: [
    { key: 'ocr_model',     type: 'text', placeholderKey: 'settings.placeholder.ocrModel' },
    { key: 'ocr_timeout_s', unitKey: 'settings.unit.seconds', step: '1' },
  ]},
  // v3.2.0 (F1022) — Ladevorgänge aus evcc abrufen, nur im Heimnetz
  { gkey: 'evcc', page: 'connections:evcc', icon: 'auto', fields: [
    { key: 'evcc_endpoint', type: 'text', placeholderKey: 'settings.placeholder.evccEndpoint' },
  ]},
  // v3.10.0 (#36) — HTTPS ins Heimnetz mit eigener PKI (Home Assistant, Texterkennung,
  // Auslesen, evcc): eigenes Stammzertifikat zusätzlich, „nicht prüfen“ nur im Heimnetz
  { gkey: 'homeTls', page: 'connections:homenet', icon: 'schloss', fields: [
    { key: 'home_ca_pem', type: 'textarea', placeholderKey: 'settings.placeholder.homeCaPem' },
    { key: 'home_tls_insecure', type: 'bool', warn: true },
  ]},
  // Der Standort steht seit v2.12.0 nur noch bei den Wetterdaten (temperatures.js).
];


export function renderGroup(g, settings) {
  const fields = `<div class="settings-fields">
        ${g.fields.map(f => renderField(f, settings[f.key], settings)).join('')}
      </div>`;
  // v3.6.0 — `collapse`: Felder in einem Aufklappbereich (zu, bis man ihn öffnet;
  // der Zustand gilt je Gerät)
  return `
    <div class="card settings-card${g.wide ? ' settings-card--wide' : ''}"${g.level ? ` data-min-level="${g.level}"` : ''}${g.collapse ? ` data-collapse="${g.gkey}"` : ''}>
      <h2 class="card__title">${g.icon ? `${icon(g.icon)} ` : ''}${t('settings.group.' + g.gkey + '.title')}</h2>
      <p class="settings-card__hint">${t('settings.group.' + g.gkey + '.hint')}</p>
      ${g.collapse ? disclosureHtml({ storageKey: g.collapse, summary: t('settings.group.' + g.gkey + '.list'), body: fields }) : fields}
    </div>
  `;
}


function renderField(f, value, settings = {}) {
  // A11y (N1009): stabile id pro Feld, damit Label (for) und Control (id)
  // verknüpft sind und der Hinweis per aria-describedby zugeordnet werden kann.
  const fieldId = 'set-' + f.key;
  const hintKey = 'settings.field.' + f.key + '.hint';
  const hintVal = t(hintKey);
  const hasHint = hintVal !== hintKey;
  const hintId  = fieldId + '-hint';
  // v3.10.0 — `warn`: der Hinweis ist eine Warnung (etwa „Zertifikat nicht prüfen“)
  const hint = hasHint ? `<span class="settings-field__hint${f.warn ? ' settings-card__hint--warn' : ''}" id="${hintId}">${hintVal}</span>` : '';
  const describedBy = hasHint ? ` aria-describedby="${hintId}"` : '';
  const unit = f.unitKey ? t(f.unitKey) : f.unit;
  // v2.2.0 — Platzhalter mit deutschem Text („TT-MM", „z. B. 1998") laufen
  // jetzt über den Katalog.
  const placeholder = f.placeholderKey ? t(f.placeholderKey) : f.placeholder;
  const labelHtml = `<label for="${fieldId}">${t('settings.field.' + f.key + '.label')}${unit ? ` <span class="settings-field__unit">${escapeHtml(unit)}</span>` : ''}</label>`;

  if (f.type === 'gasfactors') {
    return renderGasFactors(f, Array.isArray(value) ? value : [], hint, settings);
  }
  if (f.type === 'co2years') {
    return renderCo2Years(f, yearMap(value), hint);
  }
  if (f.type === 'select') {
    // v3.7.0 — `keepValue`: ein gespeicherter Wert, den die Liste nicht kennt (etwa ein
    // Anbieter, der sich angemeldet hat), bleibt wählbar — sonst stellte Speichern ihn still um
    const options = f.keepValue && typeof value === 'string' && value !== '' && !f.options.includes(value) ? [...f.options, value] : f.options;
    const optLabel = (o) => {
      if (!f.optionLabels) return escapeHtml(o);
      const k = `${f.optionLabels}.${o}`;
      const s = t(k);
      return escapeHtml(s === k ? o : s);
    };
    return `<div class="field settings-field">
      ${labelHtml}
      <select class="select" id="${fieldId}" data-key="${f.key}"${describedBy}>
        ${options.map(o => `<option value="${escapeHtml(o)}" ${o === value ? 'selected' : ''}>${optLabel(o)}</option>`).join('')}
      </select>
      ${hint}
    </div>`;
  }
  if (f.type === 'bool') {
    // v3.12.0 — Härtung: `admin` — ändern nur Verwalter (App::ADMIN_SETTINGS); Mitglieder sehen den Stand gesperrt
    const locked = f.admin && sessionRole() !== 'admin';
    return `<div class="field settings-field">
      ${labelHtml}
      <label class="settings-field__check">
        <input type="checkbox" id="${fieldId}" data-key="${f.key}" data-type="bool" ${value ? 'checked' : ''}${describedBy}${locked ? ' disabled' : ''}> ${t('settings.boolActive')}
      </label>
      ${hint}
      ${locked ? `<span class="settings-field__hint" data-role="admin-only">${escapeHtml(t('settings.region.adminOnly'))}</span>` : ''}
    </div>`;
  }
  if (f.type === 'datemd') {
    // Speicherung kanonisch MM-TT (für valide Datumskonstruktion im
    // Backend), Anzeige im deutschen Format TT-MM.
    const disp = mmddToDdmm(value);
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" id="${fieldId}" data-key="${f.key}" data-type="datemd"
             value="${escapeHtml(disp)}" ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${describedBy}>
      <div class="field-error" id="${fieldId}-err" role="alert" hidden></div>
      ${hint}
    </div>`;
  }
  // v3.10.0 (#36) — mehrzeiliger Text (Zertifikat im PEM-Format); ohne Rechtschreibprüfung
  if (f.type === 'textarea') {
    return `<div class="field settings-field">
      ${labelHtml}
      <textarea class="input input--text input--mono" id="${fieldId}" data-key="${f.key}" rows="5" spellcheck="false" autocomplete="off"
                ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${describedBy}>${escapeHtml(value ?? '')}</textarea>
      ${hint}
    </div>`;
  }
  // v3.12.0 (m4b) — Text in Zeilen (eine Anschrift): Textschrift, nicht Mono
  if (f.type === 'lines') {
    return `<div class="field settings-field">
      ${labelHtml}
      <textarea class="input input--text" id="${fieldId}" data-key="${f.key}" rows="${f.rows || 3}"${f.autocomplete ? ` autocomplete="${f.autocomplete}"` : ''}
                ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${describedBy}>${escapeHtml(value ?? '')}</textarea>
      ${hint}
    </div>`;
  }
  if (f.type === 'text') {
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" id="${fieldId}" data-key="${f.key}"
             value="${escapeHtml(value ?? '')}" ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${f.maxlength ? ` maxlength="${f.maxlength}"` : ''}${f.autocomplete ? ` autocomplete="${f.autocomplete}"` : ''}${describedBy}>
      ${hint}
    </div>`;
  }
  // v2.5.3 (FE-02, FE-04) — Text mit Dezimaltastatur statt type="number"
  // („0,5" kam je nach Browser leer an) und escapter Wert (ein als Text
  // gespeicherter Wert brach bisher aus dem Attribut aus). Vorzeichen-Felder
  // ohne inputmode: Die iOS-Zahlentastatur hat kein Minus.
  // v3.6.0 — Wohnfläche und Personen, die niemand eingetragen hat
  // (`assumed_keys`): Feld leer, der Default als Platzhalter und ein Satz dazu.
  // Erst eine Eingabe (auch derselbe Wert) zählt für die Einordnung; leer
  // gespeichert bleibt die Angabe offen (der Server nimmt sie zurück).
  // v3.7.0 — Beispielwerte der Flexibilität (`example_keys`): genauso leer mit Platzhalter, eigener Satz
  const example = (settings.example_keys || []).includes(f.key);
  const assumed = example || (settings.assumed_keys || []).includes(f.key);
  const assumedId = fieldId + '-assumed';
  const assumedText = !assumed || value == null ? ''
    : t(example ? 'settings.exampleValue' : 'settings.assumedValue', { value: formatForInput(value) + (unit ? ' ' + unit : '') });
  const assumedHint = assumedText ? `<span class="settings-field__hint" id="${assumedId}" data-role="${example ? 'example' : 'assumed'}">${escapeHtml(assumedText)}</span>` : '';
  const describedNum = assumedText ? ` aria-describedby="${assumedId}${hasHint ? ' ' + hintId : ''}"` : describedBy;
  return `<div class="field settings-field">
    ${labelHtml}
    <input class="input" type="text" ${f.signed ? '' : 'inputmode="decimal" '}autocomplete="off" id="${fieldId}" data-key="${f.key}" data-type="number"
           value="${assumed ? '' : escapeHtml(formatForInput(value))}"${assumed ? ` placeholder="${escapeHtml(formatForInput(value))}"` : ''}${describedNum}>
    <div class="field-error" id="${fieldId}-err" role="alert" hidden></div>
    ${assumedHint}
    ${hint}
  </div>`;
}


// v2.5.3 (FE-02) — Zahlen- und Stichtagsfelder vor dem Speichern prüfen.
// Ein unlesbarer Stichtag wurde bisher still zu 01-01. Liefert das erste
// ungültige Feld (oder null) und markiert alle ungültigen.
export function firstInvalidSetting(container) {
  let first = null;
  container.querySelectorAll('[data-type="number"], [data-type="datemd"]').forEach(el => {
    const raw = el.value.trim();
    const isNum = el.getAttribute('data-type') === 'number';
    const ok = raw === '' || (isNum ? parseDecimal(raw) !== null : ddmmToMmdd(raw) !== null);
    const msg = ok ? null
      : isNum ? t('common.invalidNumber', { example: formatForInput(1234.5) })
      : t('settings.dayMonthInvalid');
    el.classList.toggle('invalid', !ok);
    if (ok) el.removeAttribute('aria-invalid'); else el.setAttribute('aria-invalid', 'true');
    const msgEl = container.querySelector(`#${el.id}-err`);
    if (msgEl) { msgEl.textContent = msg || ''; msgEl.hidden = ok; }
    if (!ok && !first) first = el;
  });
  return first;
}


// ── v2.10.0 (CALC-19): CO₂ Strom je Jahr ──────────────────────────────
//
// Der Strommix ändert sich jedes Jahr; das Umweltbundesamt veröffentlicht den
// Faktor je Jahr. Für Jahre nach dem letzten Eintrag gilt dessen Wert, für
// Jahre davor der eine Wert „CO₂ Strom". Die Tabelle lebt wie die Gasfaktoren
// in einem versteckten JSON-Feld.

/** Jahreswerte als Objekt {Jahr: g/kWh}; PHP liefert eine leere Liste als []. */
function renderCo2YearRows(map, key = 'co2_strom_years') {
  const years = Object.keys(map).sort();
  // v3.1.0 — der CO₂-Preis fällt ohne eigene Werte auf das Länderprofil zurück, nicht auf „CO₂ Strom"
  if (!years.length) return `<tr><td colspan="3" class="muted">${t(key === 'co2_price_eur_t_years' ? 'settings.co2Years.nonePrice' : 'settings.co2Years.none')}</td></tr>`;
  return years.map(y => `
    <tr>
      <td>${escapeHtml(y)}</td>
      <td class="num">${fmt.num(map[y], 0)}</td>
      <td><button type="button" class="btn btn--ghost btn--sm" data-cy-del="${escapeHtml(y)}" aria-label="${t('settings.gasFactors.remove')}">${t('settings.gasFactors.remove')}</button></td>
    </tr>`).join('');
}


// v3.1.0 — auch für den CO₂-Preis je Jahr (co2_price_eur_t_years, €/t)
const yearUnit = (key) => key === 'co2_price_eur_t_years' ? `${getCurrencySymbol()}/t` : 'g/kWh';

const yearMax = (key) => key === 'co2_price_eur_t_years' ? 1000 : 2000;


// v3.12.0 (M3) — beide Tabellen auf einer Seite: Beschriftung und Feld je Tabelle eigene ids
const cyIds = (key) => (key === 'co2_price_eur_t_years' ? { year: 'cy-price-year', value: 'cy-price-value' } : { year: 'cy-year', value: 'cy-value' });

function renderCo2Years(f, map, hint) {
  const cy = cyIds(f.key);
  return `<div class="field settings-field settings-field--wide" data-co2years data-year-key="${f.key}">
    <label>${t('settings.field.' + f.key + '.label')}</label>
    ${hint}
    <input type="hidden" data-key="${f.key}" data-type="json" value="${escapeHtml(JSON.stringify(map))}">
    <div class="table-wrap" style="margin-top:8px">
      <table class="table table--compact" data-cy-table>
        <thead><tr>
          <th scope="col">${t('settings.co2Years.colYear')}</th>
          <th scope="col" class="num">${escapeHtml(yearUnit(f.key))}</th>
          <th scope="col"></th>
        </tr></thead>
        <tbody>${renderCo2YearRows(map, f.key)}</tbody>
      </table>
    </div>
    <div class="fgrid" style="margin-top:10px">
      <div class="field field--s">
        <label for="${cy.year}">${t('settings.co2Years.colYear')}</label>
        <input class="input" id="${cy.year}" data-cy-year type="text" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="${new Date().getFullYear() - 1}">
      </div>
      <div class="field field--s">
        <label for="${cy.value}">${escapeHtml(yearUnit(f.key))}</label>
        <input class="input" id="${cy.value}" data-cy-value type="text" inputmode="decimal" autocomplete="off" placeholder="${escapeHtml(formatForInput(f.key === 'co2_price_eur_t_years' ? 65 : 344))}">
      </div>
      <div class="field field--s">
        <button type="button" class="btn btn--ghost" data-cy-add>${t('settings.gasFactors.add')}</button>
      </div>
    </div>
  </div>`;
}


export function wireCo2Years(container) {
  container.querySelectorAll('[data-co2years]').forEach(wireCo2YearTable);
}

function wireCo2YearTable(root) {
  const key = root.getAttribute('data-year-key') || 'co2_strom_years';
  const hidden = root.querySelector(`[data-key="${key}"]`);
  const tbody  = root.querySelector('[data-cy-table] tbody');
  const read   = () => { try { return yearMap(JSON.parse(hidden.value || '{}')); } catch { return {}; } };
  const write  = (map) => {
    hidden.value = JSON.stringify(map);
    tbody.innerHTML = renderCo2YearRows(map, key);
    hidden.dispatchEvent(new Event('input', { bubbles: true }));   // Ungespeichert-Marker
  };
  root.addEventListener('click', (ev) => {
    const del = ev.target.closest('[data-cy-del]');
    if (del) {
      const map = read();
      delete map[del.getAttribute('data-cy-del')];
      write(map);
      return;
    }
    if (ev.target.closest('[data-cy-add]')) {
      const yearEl = root.querySelector('[data-cy-year]'), valueEl = root.querySelector('[data-cy-value]');
      const year = (yearEl?.value || '').trim();
      const val  = parseDecimal(valueEl?.value ?? '');
      if (!/^(19[9]\d|20\d\d|2100)$/.test(year) || val == null || val < 0 || val > yearMax(key)) {
        toastErr(key === 'co2_price_eur_t_years' ? t('settings.co2Years.invalidPrice', { unit: yearUnit(key) }) : t('settings.co2Years.invalid'));
        return;
      }
      const map = read();
      map[year] = val;
      write(map);
      yearEl.value = '';
      valueEl.value = '';
    }
  });
}


// ── v2.10.0 — korrigierte Standardwerte anbieten (Lektion 36) ─────────
//
// Die Migration 1.6.0 hat bei Bestandsinstallationen die alten Defaults
// festgeschrieben, damit sich keine Zahl still ändert. Hier steht, was es
// inzwischen Besseres gibt — übernommen wird nur auf Knopfdruck.
function defaultValueText(key, v) {
  if (key === 'co2_strom_years') return yearsText(v);
  if (key === 'wasser_personen_referenz') return fmt.num(v, 0) + ' ' + t('settings.unit.lPerPersonDay');
  return fmt.num(v, 0) + ' g/kWh';
}


export function renderDefaultUpdates(updates) {
  if (!Array.isArray(updates) || !updates.length) return '';
  return `
    <div class="card settings-card settings-card--wide" id="default-updates" data-field="default_updates">
      <h2 class="card__title">${icon('neu')} ${t('settings.defaultUpdates.title')}</h2>
      <p class="settings-card__hint">${t('settings.defaultUpdates.hint')}</p>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
          <th scope="col">${t('settings.region.colSetting')}</th>
          <th scope="col">${t('settings.region.colNow')}</th>
          <th scope="col">${t('settings.defaultUpdates.colNew')}</th>
        </tr></thead>
        <tbody>${updates.map(u => `<tr>
          <td>${escapeHtml((u.key.startsWith('wasser_') ? t('settings.group.water.title') + ': ' : '') + t('settings.field.' + u.key + '.label'))}</td>
          <td>${escapeHtml(defaultValueText(u.key, u.current))}</td>
          <td><strong>${escapeHtml(defaultValueText(u.key, u.recommended))}</strong></td>
        </tr>`).join('')}</tbody>
      </table></div>
      <p class="muted" style="margin-top:8px">${t('settings.defaultUpdates.sources')}</p>
      <div class="form-actions"><button type="button" class="btn btn--primary" id="btn-take-defaults">${t('settings.defaultUpdates.apply')}</button></div>
    </div>`;
}


// ── F1012: datierte Gas-Umrechnungsfaktoren ───────────────────────────
//
// Ein Eintrag je Brennwertperiode, wie die Gasrechnung sie ausweist:
// „Gültig ab", Zustandszahl, Brennwert → Faktor. Der undatierte Eintrag ist
// der migrierte Altwert und gilt für alles vor dem ersten Stichtag. Die Liste
// lebt in einem versteckten JSON-Feld, das collectSettings() wie jedes
// andere Feld einsammelt — Speichern läuft über denselben PATCH.

// v2.7.0 — Brennwert wahlweise in MJ/m³ (Vereinigtes Königreich, Niederlande)
// oder GJ/Smc (Italien). Gespeichert wird immer kWh/m³ (lib/gas-factor.js).
function renderGasFactorRows(list, unit = CV_UNITS.kwh) {
  if (!list.length) return `<tr><td colspan="5" class="muted">${t('settings.gasFactors.none')}</td></tr>`;
  return list.map((e, i) => `
    <tr>
      <td>${e.from ? fmt.date(e.from) : `<span class="muted">${t('settings.gasFactors.undated')}</span>`}</td>
      <td class="num">${e.zustandszahl != null ? fmt.num(e.zustandszahl, 4) : '–'}</td>
      <td class="num">${e.brennwert != null ? fmt.num(e.brennwert * unit.perKwh, unit.digits) : '–'}</td>
      <td class="num"><strong>${fmt.num(gasFactorOf(e), 3)}</strong></td>
      <td><button type="button" class="btn btn--ghost btn--sm" data-gf-del="${i}" aria-label="${t('settings.gasFactors.remove')}">${t('settings.gasFactors.remove')}</button></td>
    </tr>`).join('');
}


/**
 * v2.10.0 (Review CALC-19) — nur der ausgelieferte Standard (undatiert 11,5)?
 * Typische Netze liegen bei 10,3–11,1 kWh/m³ (H-Gas), L-Gas darunter; mit
 * dem Standard wären kWh, Kosten und CO₂ um 5–20 % zu hoch.
 */
function isDefaultGasFactor(list) {
  return list.length === 1 && !list[0].from && list[0].zustandszahl == null
    && Math.abs(Number(list[0].kwh_per_m3) - 11.5) < 1e-9;
}


function renderGasFactors(f, list, hint, settings = {}) {
  const lastZ = [...list].reverse().find(e => e.zustandszahl != null)?.zustandszahl ?? '';
  const unit = cvUnit(settings.gas_cv_unit);
  const hintKey = 'settings.gasFactors.countryHint.' + settings.country;
  const countryHint = hasTranslation(hintKey) ? t(hintKey) : '';
  return `<div class="field settings-field settings-field--wide" data-gasfactors>
    <label>${t('settings.field.gas_conversion_factors.label')}</label>
    ${hint}
    <input type="hidden" data-key="${f.key}" data-type="json" value="${escapeHtml(JSON.stringify(list))}">
    <div class="fgrid" style="margin-top:8px">
      <div class="field field--s">
        <label for="set-gas_cv_unit">${t('settings.gasFactors.cvUnit')}</label>
        <select class="select" id="set-gas_cv_unit" data-key="gas_cv_unit">
          ${Object.entries(CV_UNITS).map(([k, u]) => `<option value="${k}" ${u === unit ? 'selected' : ''}>${u.label}</option>`).join('')}
        </select>
      </div>
    </div>
    <p class="muted" data-gf-mjhint ${unit === CV_UNITS.kwh ? 'hidden' : ''}>${t('settings.gasFactors.mjHint')}</p>
    ${countryHint ? `<p class="muted">${countryHint}</p>` : ''}
    ${isDefaultGasFactor(list) ? `<p class="infobox infobox--neutral balance-note" data-gf-default>${t('settings.gasFactors.defaultHint')}</p>` : ''}
    <div class="table-wrap" style="margin-top:8px">
      <table class="table table--compact" data-gf-table>
        <thead><tr>
          <th scope="col">${t('settings.gasFactors.colFrom')}</th>
          <th scope="col" class="num">${t('settings.gasFactors.colZ')}</th>
          <th scope="col" class="num" data-gf-hs-head>${t(unit.colKey)}</th>
          <th scope="col" class="num">${t('settings.gasFactors.colFactor')}</th>
          <th scope="col"></th>
        </tr></thead>
        <tbody>${renderGasFactorRows(list, unit)}</tbody>
      </table>
    </div>
    <div class="fgrid" style="margin-top:10px">
      <div class="field field--s">
        <label for="gf-from">${t('settings.gasFactors.colFrom')}</label>
        <input class="input" id="gf-from" type="date">
      </div>
      <div class="field field--s">
        <label for="gf-z">${t('settings.gasFactors.colZ')}</label>
        <input class="input" id="gf-z" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(lastZ, 4))}" placeholder="${escapeHtml(formatForInput(0.96))}">
      </div>
      <div class="field field--s">
        <label for="gf-hs" data-gf-hs-label>${t(unit.colKey)}</label>
        <input class="input" id="gf-hs" type="text" inputmode="decimal" autocomplete="off" placeholder="${escapeHtml(formatForInput(unit.sample, unit.digits))}">
      </div>
      <div class="field field--s">
        <label for="gf-f">${t('settings.gasFactors.colFactorDirect')}</label>
        <input class="input" id="gf-f" type="text" inputmode="decimal" autocomplete="off" placeholder="${escapeHtml(formatForInput(10.8))}">
      </div>
      <div class="field field--xs">
        <button type="button" class="btn btn--ghost" data-gf-add>${t('settings.gasFactors.add')}</button>
      </div>
    </div>
    <p class="muted" style="margin-top:6px" data-gf-preview></p>
  </div>`;
}


// Verdrahtet Hinzufügen/Entfernen; hält die Liste im versteckten JSON-Feld.
export function wireGasFactors(container) {
  const root = container.querySelector('[data-gasfactors]');
  if (!root) return;
  const hidden = root.querySelector('[data-key="gas_conversion_factors"]');
  const tbody  = root.querySelector('[data-gf-table] tbody');
  const read   = () => { try { return JSON.parse(hidden.value || '[]'); } catch { return []; } };
  const unitSel = root.querySelector('[data-key="gas_cv_unit"]');
  const unit   = () => cvUnit(unitSel?.value);
  const write  = (list) => {
    hidden.value = JSON.stringify(list);
    tbody.innerHTML = renderGasFactorRows(list, unit());
    hidden.dispatchEvent(new Event('input', { bubbles: true }));   // Ungespeichert-Marker
  };
  // v2.5.3 — parseDecimal: `replace(',', '.')` machte aus „1.050,5" 1,05.
  const num = (id) => parseDecimal(root.querySelector('#' + id)?.value ?? '');
  // v2.7.0 — Brennwert immer als kWh/m³ weiterrechnen, auch bei MJ-Eingabe
  const hsKwh = () => {
    const v = num('gf-hs');
    return v && unit() !== CV_UNITS.kwh ? Number((v / unit().perKwh).toFixed(6)) : v;
  };
  unitSel?.addEventListener('change', () => {
    const u = unit();
    root.querySelector('[data-gf-hs-head]').textContent = t(u.colKey);
    root.querySelector('[data-gf-hs-label]').textContent = t(u.colKey);
    root.querySelector('#gf-hs')?.setAttribute('placeholder', formatForInput(u.sample, u.digits));
    root.querySelector('[data-gf-mjhint]').hidden = u === CV_UNITS.kwh;
    tbody.innerHTML = renderGasFactorRows(read(), u);
    preview();
  });
  const preview = () => {
    const z = num('gf-z'), hs = hsKwh(), f = num('gf-f');
    const el = root.querySelector('[data-gf-preview]');
    if (z && hs) el.textContent = t('settings.gasFactors.preview', { z: fmt.num(z, 4), hs: fmt.num(hs, 3), f: fmt.num(z * hs, 3) });
    else if (f) el.textContent = t('settings.gasFactors.previewDirect', { f: fmt.num(f, 3) });
    else el.textContent = '';
  };
  ['gf-z', 'gf-hs', 'gf-f'].forEach(id => root.querySelector('#' + id)?.addEventListener('input', preview));

  root.addEventListener('click', (ev) => {
    const del = ev.target.closest('[data-gf-del]');
    if (del) {
      const list = read();
      list.splice(Number(del.getAttribute('data-gf-del')), 1);
      write(list);
      return;
    }
    if (ev.target.closest('[data-gf-add]')) {
      const from = root.querySelector('#gf-from')?.value || null;
      const z = num('gf-z'), hs = hsKwh(), f = num('gf-f');
      const list = read();
      if (!from && list.some(e => !e.from)) { toastErr(t('settings.gasFactors.errOneUndated')); return; }
      if (from && list.some(e => e.from === from)) { toastErr(t('settings.gasFactors.errDuplicate')); return; }
      let entry;
      if (z && hs)   entry = { from, zustandszahl: z, brennwert: hs, kwh_per_m3: Number((z * hs).toFixed(5)) };
      else if (f)    entry = { from, zustandszahl: null, brennwert: null, kwh_per_m3: f };
      else { toastErr(t('settings.gasFactors.errNeedValues')); return; }
      list.push(entry);
      list.sort((a, b) => (a.from ?? '') < (b.from ?? '') ? -1 : (a.from ?? '') > (b.from ?? '') ? 1 : 0);
      write(list);
      root.querySelector('#gf-from').value = '';
      root.querySelector('#gf-hs').value = '';
      root.querySelector('#gf-f').value = '';
      preview();
    }
  });
}


export function collectSettings(container) {
  const out = {};
  container.querySelectorAll('[data-key]').forEach(el => {
    const key = el.getAttribute('data-key');
    if (el.tagName === 'SELECT') out[key] = el.value;
    else if (el.getAttribute('data-type') === 'json') {
      try { out[key] = JSON.parse(el.value || '[]'); } catch { out[key] = []; }
    }
    else if (el.getAttribute('data-type') === 'bool') out[key] = el.checked;
    else if (el.getAttribute('data-type') === 'datemd') {
      // Eingabe TT-MM → kanonisch MM-TT; leer ⇒ Default 01-01. Ungültiges
      // hält firstInvalidSetting() vor dem Speichern auf; hier bleibt der
      // Rohtext stehen, damit der Ungespeichert-Marker die Änderung sieht.
      const v = el.value.trim();
      out[key] = v === '' ? '01-01' : (ddmmToMmdd(v) ?? v);
    }
    else if (el.getAttribute('data-type') === 'number') {
      const v = el.value.trim();
      out[key] = v === '' ? null : (parseDecimal(v) ?? v);
    } else out[key] = el.value;
  });
  // v1.3.0 — aktive Verbrauchsarten aus den Checkboxen
  const utilBoxes = container.querySelectorAll('[data-active-util]');
  if (utilBoxes.length) {
    out.active_utilities = [...utilBoxes]
      .filter(b => b.checked)
      .map(b => b.getAttribute('data-active-util'));
  }
  return out;
}


// Ist eine Verbrauchsart für den Export aktiv? (active_utilities leer ⇒ alle)
export function exportActive(settings, key) {
  const a = settings && settings.active_utilities;
  return Array.isArray(a) && a.length ? a.includes(key) : true;
}


// ── Abrechnungs-Stichtag: Datums-Konvertierung (v1.4.2, Bug #4) ──
// Speicherung kanonisch "MM-TT" (damit das Backend YYYY-MM-TT bauen
// kann), Anzeige/Eingabe im deutschen Format "TT-MM".
function mmddToDdmm(v) {
  const m = /^(\d{2})-(\d{2})$/.exec(String(v ?? '').trim());
  if (!m) return '01-01';
  return `${m[2]}-${m[1]}`; // MM-TT → TT-MM
}

// v2.5.3 — ungültig ⇒ null statt still 01-01 (FE-02); „32-13" wurde
// vorher zu 31.12. geklemmt.
function ddmmToMmdd(v) {
  const m = /^(\d{1,2})[-.\/](\d{1,2})\.?$/.exec(String(v ?? '').trim());
  if (!m) return null;
  let d = parseInt(m[1], 10);
  const mo = parseInt(m[2], 10);
  if (d < 1 || d > 31 || mo < 1 || mo > 12) return null;
  // 29.–31. Februar auf 28 begrenzen (Backend klemmt zusätzlich)
  if (mo === 2 && d > 28) d = 28;
  const p = n => String(n).padStart(2, '0');
  return `${p(mo)}-${p(d)}`; // → MM-TT
}
