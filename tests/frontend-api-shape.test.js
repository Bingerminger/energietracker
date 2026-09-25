// Frontend-API-Shape-Test — prüft, dass die Backend-Endpoints exakt die
// Datenstrukturen liefern, die das Frontend erwartet. Benötigt einen
// laufenden Backend-Server (php -S auf :8899).
// v1.4.4: loadModule-Stub entfernt (war nicht-aufgerufener toter Code).
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const BASE = process.env.ET_TEST_HOST || 'http://127.0.0.1:8899';
const ROOT = require('path').resolve(__dirname, '..');


(async () => {
  const results = [];
  function check(name, cond, info = '') {
    results.push({ name, ok: !!cond, info });
    console.log(`  ${cond ? '✓' : '✗'} ${name}${info ? ' — ' + info : ''}`);
  }

  // Smoke: Backend-Endpoints liefern, was die Views erwarten
  const j = async (p) => {
    const r = await fetch(BASE + p);
    const d = await r.json();
    return d.data !== undefined ? d.data : d;
  };

  // 1. recommendations endpoint shape
  const recs = await j('/api/recommendations');
  check('GET /api/recommendations → Array', Array.isArray(recs), `${recs.length} Einträge`);
  // v2.12.0 — Ausblenden rückgängig machen
  if (recs.length) {
    const id = encodeURIComponent(recs[0].id);
    await fetch(`${BASE}/api/recommendations/${id}/dismiss`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
    const hidden = !(await j('/api/recommendations')).some(r => r.id === recs[0].id);
    const back = await fetch(`${BASE}/api/recommendations/${id}/dismiss`, { method: 'DELETE' }).then(r => r.json()).then(d => d.data);
    const shown = (await j('/api/recommendations')).some(r => r.id === recs[0].id);
    check('DELETE /api/recommendations/{id}/dismiss blendet wieder ein',
      hidden && back?.dismissed === false && shown);
  }
  if (recs.length) {
    const r = recs[0];
    check('Empfehlung hat id/severity/category/title/detail',
      r.id && r.severity && r.category && r.title && r.detail);
  }

  // 2. reminders endpoint shape
  const rem = await j('/api/reminders');
  check('GET /api/reminders → Array', Array.isArray(rem));

  // 3. tariff comparison shape (Gas, erster Zähler)
  const gMeters = await j('/api/utility/gas/meters');
  if (gMeters.length) {
    const tc = await j(`/api/utility/gas/meters/${gMeters[0].id}/tariff-comparison?year=2024`);
    check('Tarifvergleich liefert supported+rows',
      tc.supported === true && Array.isArray(tc.rows), `${tc.rows.length} Tarife`);
    // v2.12.0 — Jahre mit Daten für die Jahresauswahl
    check('Tarifvergleich liefert years[] (Zahlen, neueste zuerst)',
      Array.isArray(tc.years) && tc.years.length > 0 && tc.years.every(Number.isInteger)
        && tc.years.every((y, i, a) => i === 0 || a[i - 1] > y), (tc.years || []).join(','));

    // v2.12.0 — CSV-Import mit Trockenlauf: liest, schreibt nichts
    const before = (await j(`/api/utility/gas/readings?meter_id=${encodeURIComponent(gMeters[0].id)}`)).length;
    const dry = await fetch(`${BASE}/api/utility/gas/meters/${gMeters[0].id}/readings/import-csv?dry_run=1`, {
      method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: 'datum;zählerstand\n01.01.2000;1,5\n',
    }).then(r => r.json()).then(d => d.data);
    const after = (await j(`/api/utility/gas/readings?meter_id=${encodeURIComponent(gMeters[0].id)}`)).length;
    check('Import-Trockenlauf → dry_run, rows[{date,counter}], nichts geschrieben',
      dry?.dry_run === true && dry.rows?.[0]?.date === '2000-01-01' && dry.rows[0].counter === 1.5
        && dry.imported === 0 && before === after, `${before} → ${after} Stände`);
  }

  // 4. efficiency shape
  const eff = await j('/api/benchmarks/efficiency?year=2024');
  check('Effizienz liefert class+kwh_per_m2',
    eff.class !== undefined && eff.kwh_per_m2 !== undefined,
    `${eff.class} / ${eff.kwh_per_m2} kWh/m²`);
  // v2.7.0 — Klasse nur mit Skala des Landes; dashboard.js liest `scale`
  check('Effizienz nennt die Skala (Demo: DE → geg)', eff.scale === 'geg' && eff.scale_note === null,
    `scale=${eff.scale}`);
  // v2.10.0 — zweite Zahl (energieausweis-nah) und Abdeckung je Quelle
  check('Effizienz (v2.10.0): certificate + coverage_days/complete je Quelle',
    eff.certificate && typeof eff.certificate.kwh_per_m2 === 'number' && eff.certificate.area_factor >= 1.2
      && (eff.per_source || []).every(s => Number.isInteger(s.coverage_days) && typeof s.complete === 'boolean'),
    eff.certificate ? `${eff.certificate.kwh_per_m2} kWh/m²·a auf ${eff.certificate.area_m2} m²` : 'kein certificate');
  const du = await j('/api/settings/default-updates');
  check('default-updates (v2.10.0) → Liste (Demo: aktuelle Werte, also leer)', Array.isArray(du) && du.length === 0,
    JSON.stringify(du).slice(0, 80));
  const pvs = await j('/api/pv-summary');
  const pvy = (pvs.yearly || [])[0];
  check('pv-summary (v2.10.0): months_covered, savings_eur, feed_in_revenue_eur',
    !pvy || (Number.isInteger(pvy.months_covered) && 'savings_eur' in pvy && 'feed_in_revenue_eur' in pvy),
    pvy ? `${pvy.year}: ${pvy.months_covered} Monate` : 'keine PV');

  // 4a. v2.7.0 — Länderprofile: settings.js, temperatures.js und app.js lesen
  //     code/languages/currency/timezone/location_name/latitude/longitude
  const countries = await j('/api/countries');
  const c0 = Array.isArray(countries) ? countries[0] : null;
  check('Länderprofile → Liste mit Pflichtfeldern',
    Array.isArray(countries) && countries.length >= 9 && c0 && Array.isArray(c0.languages)
      && ['code', 'currency', 'timezone', 'location_name', 'latitude', 'longitude', 'gas_cv_unit', 'co2_strom_source']
        .every(k => k in c0),
    c0 ? Object.keys(c0).join(',') : String(countries));

  // 4b. delivery utility shape (heizoel) — utility.js erwartet
  //     deliveries[] mit id/date/quantity + stock-history capacity/days
  const hMeters = await j('/api/utility/heizoel/meters');
  if (hMeters.length) {
    const dv = await j(`/api/utility/heizoel/deliveries?meter_id=${hMeters[0].id}`);
    check('Lieferungen → Array mit date/quantity',
      Array.isArray(dv) && (dv.length === 0 || (dv[0].id && dv[0].date && dv[0].quantity != null)),
      `${dv.length} Lieferungen`);
    const sh = await j(`/api/utility/heizoel/meters/${hMeters[0].id}/stock-history`);
    check('Stock-History hat capacity + days[]',
      sh.capacity != null && Array.isArray(sh.days),
      `cap=${sh.capacity} ${sh.capacity_unit || ''}, ${sh.days?.length || 0} Tage`);
    // v2.10.0 — Tankbuch: Stützstellen, Schätzbeginn, Kalibrierung, Warnungen
    check('Stock-History (v2.10.0): anchors/estimated_from/calibration/warnings, days[].estimated',
      Array.isArray(sh.anchors) && sh.anchors.length > 0 && sh.anchors[0].kind === 'start'
        && 'estimated_from' in sh && typeof sh.calibration === 'string' && Array.isArray(sh.warnings)
        && sh.days.length > 0 && typeof sh.days[sh.days.length - 1].estimated === 'boolean',
      `anchors=${(sh.anchors || []).map(a => a.kind).join(',')} cal=${sh.calibration}`);
    const hc = await j(`/api/utility/heizoel/meters/${hMeters[0].id}/consumption`);
    const hRows = hc.monthly || [];
    check('consumption(heizoel, v2.10.0): estimated_days + effektiver Preis je kWh',
      hRows.length > 0 && hRows.every(m => Number.isInteger(m.estimated_days))
        && hRows.some(m => m.working_price_ct > 0),
      `${hRows.length} Monate`);
  } else {
    check('Heizöl-Zähler vorhanden (für Delivery-UI-Test)', true, 'kein Zähler — übersprungen');
  }

  // 4c. F1004 (v1.6.0) — Aggregat-Endpunkt für die zentrale
  //     Zählerstand-Erfassung. Erwartet rows[] mit utility/meter_id/
  //     consumption_unit/last_reading/expected_next_min, ohne
  //     Delivery-Utilities (Heizöl/Pellets).
  const ovw = await j('/api/readings-overview');
  const okShape = ovw && Array.isArray(ovw.rows);
  check('readings-overview liefert rows[]', okShape, `${ovw.rows?.length || 0} Zeilen`);
  if (okShape && ovw.rows.length > 0) {
    const r = ovw.rows[0];
    const fields = ['utility','meter_id','meter_name','unit','consumption_unit','last_reading','expected_next_min'];
    check('overview-Row hat erwartete Felder',
      fields.every(f => f in r),
      fields.filter(f => !(f in r)).join(',') || 'alle vorhanden');
    const utils = [...new Set(ovw.rows.map(x => x.utility))];
    const noDelivery = !utils.includes('heizoel') && !utils.includes('pellets');
    check('overview enthält keine Delivery-Utilities',
      noDelivery,
      `utilities=${utils.join(',')}`);
    // v2.4.2 — GitHub #21: Zwei Einheiten, zwei Bedeutungen. `unit` ist die
    // des ZÄHLERSTANDS (Gas: m³), `consumption_unit` die des VERBRAUCHS
    // (Gas: kWh). Bis v2.4.1 fehlte `unit` in der Antwort, und die
    // Erfassungsmaske beschriftete den Gas-Zählerstand mit kWh.
    const gas = ovw.rows.find(x => x.utility === 'gas');
    if (gas) {
      check('overview: Gas-Zählerstand in m³, Verbrauch in kWh',
        gas.unit === 'm³' && gas.consumption_unit === 'kWh',
        `unit=${gas.unit} consumption_unit=${gas.consumption_unit}`);
    }
    const strom = ovw.rows.find(x => x.utility === 'strom');
    if (strom) {
      check('overview: Strom-Zählerstand und -Verbrauch beide kWh',
        strom.unit === 'kWh' && strom.consumption_unit === 'kWh',
        `unit=${strom.unit} consumption_unit=${strom.consumption_unit}`);
    }
  }

  // 4d. F1006 (v1.2.0) — Zählergruppen-Endpoint liefert ein Array, und der
  //     Consumption-Endpoint trägt meter_groups[] für die Dashboard-
  //     Aufschlüsselung. Meter tragen die Topologie-Felder.
  const sGroups = await j('/api/utility/strom/meter-groups');
  check('GET /api/utility/strom/meter-groups → Array', Array.isArray(sGroups),
    `${sGroups.length} Gruppen`);
  const sMeters = await j('/api/utility/strom/meters');
  if (sMeters.length) {
    const m = sMeters[0];
    check('Strom-Meter trägt parent_meter_id + meter_group_id (F1006)',
      ('parent_meter_id' in m) && ('meter_group_id' in m));
  }
  const sCons = await j('/api/utility/strom/consumption');
  check('Consumption-Antwort trägt meter_groups[] (F1006)',
    Array.isArray(sCons.meter_groups));

  // 4e. F1009 (v1.3.0) — Home-Assistant: Auth-Status-Shape + Meter trägt
  //     external_id; Ingest legt per Alias eine Ablesung an (idempotent).
  const authSt = await j('/api/auth/token');
  check('GET /api/auth/token → {enabled}', typeof authSt.enabled === 'boolean',
    `enabled=${authSt.enabled}`);
  const sMeters2 = await j('/api/utility/strom/meters');
  if (sMeters2.length) {
    check('Strom-Meter trägt external_id-Feld (F1009)', 'external_id' in sMeters2[0]);
    // Ingest gegen die interne ID (offener Modus in der CI, kein Token gesetzt).
    const ingRes = await fetch(BASE + '/api/ingest', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ utility: 'strom', meter: sMeters2[0].id, value: 99123.4 }),
    });
    const ingJson = await ingRes.json();
    check('POST /api/ingest legt Ablesung an/aktualisiert', ingRes.ok && ingJson.data
      && ['created', 'updated'].includes(ingJson.data.status),
      `status=${ingJson.data?.status}`);
  }

  // 5. JSDOM: Sidebar-HTML-Aufbau (reine DOM-Logik, ohne ES-Module-Loader)
  const dom = new JSDOM(`<!DOCTYPE html><body data-app-version="1.3.0">
    <nav id="primary-nav"></nav></body>`, { url: BASE });
  const doc = dom.window.document;
  // Simuliere, was sidebar.js erzeugt: hat die Nav nach manueller Befüllung
  // die erwarteten data-route-Werte?
  const utilities = await j('/api/utilities');
  const settings = await j('/api/settings');

  // v2.5.0 — F1012: Der Gas-Faktor ist eine datierte Liste, kein Skalar.
  // Der alte Schlüssel darf nicht mehr auftauchen — ein Rest-Leser bekäme
  // sonst still 1,0 und rechnete m³ = kWh.
  check('settings: gas_conversion_factors ist eine Liste',
    Array.isArray(settings.gas_conversion_factors) && settings.gas_conversion_factors.length >= 1,
    JSON.stringify(settings.gas_conversion_factors)?.slice(0, 80));
  // v2.10.0 — CO₂ Strom je Jahr als Objekt {Jahr: g/kWh}
  check('settings: co2_strom_years (v2.10.0) als Jahr → Wert',
    settings.co2_strom_years && !Array.isArray(settings.co2_strom_years) && Number(settings.co2_strom_years['2024']) > 0,
    JSON.stringify(settings.co2_strom_years)?.slice(0, 60));
  check('settings: alter Skalar gas_conversion_factor ist weg',
    !('gas_conversion_factor' in settings));
  check('settings: Länderprofil-Schlüssel vorhanden (v2.7.0)',
    ['country', 'currency', 'timezone', 'gas_cv_unit'].every(k => typeof settings[k] === 'string'),
    `${settings.country}/${settings.currency}/${settings.timezone}/${settings.gas_cv_unit}`);
  if (Array.isArray(settings.gas_conversion_factors)) {
    const e0 = settings.gas_conversion_factors[0];
    check('settings: erster Eintrag ist undatiert und trägt kwh_per_m3',
      e0 && e0.from === null && typeof e0.kwh_per_m3 === 'number',
      JSON.stringify(e0));
  }

  // Rechnungsprüfung: Vertrag des neuen Endpunkts (nur Gas)
  const gasMeters = await j('/api/utility/gas/meters');
  if (Array.isArray(gasMeters) && gasMeters.length) {
    const bill = await j(`/api/utility/gas/meters/${gasMeters[0].id}/bill-check?from=2025-01-01&to=2026-01-01`);
    check('bill-check liefert rows[] + totals', Array.isArray(bill.rows) && bill.totals && 'kwh' in bill.totals,
      JSON.stringify(Object.keys(bill)));
    if (bill.rows?.length) {
      const r = bill.rows[0];
      const fields = ['from', 'to', 'to_inclusive', 'days', 'reason', 'm3', 'zustandszahl', 'brennwert', 'kwh_per_m3', 'kwh',
        'counter_from', 'counter_from_kind', 'counter_to', 'counter_to_kind'];
      check('bill-check-Zeile hat erwartete Felder', fields.every(f => f in r),
        fields.filter(f => !(f in r)).join(',') || 'alle vorhanden');
      // v2.5.2 — Ableseart je Grenze: Ablesung, geschätzt oder Ersatzwert (interpoliert)
      const kinds = new Set(bill.rows.flatMap(x => [x.counter_from_kind, x.counter_to_kind]).filter(Boolean));
      check('bill-check: Ablesearten aus {reading, reading_estimated, interpolated}',
        [...kinds].every(k => ['reading', 'reading_estimated', 'interpolated'].includes(k)) && kinds.has('reading') && kinds.has('interpolated'),
        [...kinds].join(','));
      const chained = bill.rows.slice(1).every((x, i) => x.counter_from === bill.rows[i].counter_to);
      check('bill-check: Stand neu einer Zeile = Stand alt der nächsten', chained);
    }
    const bad = await fetch(`${BASE}/api/utility/strom/meters/x/bill-check?from=2025-01-01&to=2026-01-01`);
    check('bill-check für Strom → 400', bad.status === 400, `Status ${bad.status}`);

    // v2.8.0 — C1: Monatszeilen mit den neuen Feldern, Kurven der Regression,
    // Prognose mit Hinweisen und Band, Saldo nach Kalender
    const gm = await j(`/api/utility/gas/meters/${gasMeters[0].id}/consumption`);
    const gRow = (gm.monthly || []).find(m => m.regression_point);
    check('consumption(gas): regression_point, heat_adjusted, weather_delta_pct, hdd_normal',
      !!gRow && ['heat_adjusted', 'weather_delta_pct', 'hdd_normal', 'expected_heat'].every(f => f in gRow),
      gRow ? Object.keys(gRow).filter(k => /heat|weather|normal|regression/.test(k)).join(',') : 'kein Regressionspunkt');
    check('consumption(gas): Regression mit Kurvenpunkten',
      Array.isArray(gm.regressions?.linear?.curve) && gm.regressions.linear.curve.length >= 2);
    const hMeters2 = await j('/api/utility/heizoel/meters');
    if (hMeters2.length) {
      const hm = await j(`/api/utility/heizoel/meters/${hMeters2[0].id}/consumption`);
      check('consumption(heizoel): keine Heizkurve, Hinweis statt Zirkelschluss',
        Object.keys(hm.regressions || {}).length === 0 && hm.regressions_note === 'delivery_modelled'
        && (hm.monthly || []).length > 0 && hm.monthly.every(m => m.regression_point === false));
    }
    const gfc = await j(`/api/utility/gas/meters/${gasMeters[0].id}/forecast`);
    check('forecast(gas): warnings[], hdd_source, annual, band je Monat',
      Array.isArray(gfc.warnings) && 'hdd_source' in gfc && 'annual' in gfc
        && (gfc.forecast || []).every(f => 'band_low' in f && 'band_high' in f && 'contract_assumed' in f),
      `hdd_source=${gfc.hdd_source} warnings=${(gfc.warnings || []).map(w => w.code).join(',')}`);

    // v2.5.1 — Sonderzahlungen in der Vertragstabelle: der Status trägt die
    // Einzelposten (Gas), Wasser hat das Feld nicht.
    const cs = await j(`/api/utility/gas/meters/${gasMeters[0].id}/contract-status`);
    const curC = (cs.contracts || []).find(c => c.is_current);
    check('contract-status(gas): Saldo nach Kalender (balance_as_of, projection_method, cost_to_date)',
      !!curC && curC.projection_method === 'forecast' && typeof curC.balance_as_of === 'string'
        && 'cost_to_date' in curC && 'suggested_advance' in curC && 'measured_until' in curC
        && Math.abs(curC.energy_cost_to_date + curC.base_to_date - curC.bonus_to_date - curC.cost_to_date) < 0.02,
      curC ? `${curC.projection_method} · bezahlt ${curC.advance_paid}` : 'kein laufender Vertrag');
    // v2.9.0 (CALC-10, CALC-11) — Verlängerung, Kündigung, Preiserhöhung
    check('contract-status(gas): renewed, cancel_by, days_to_cancel, switch_date, notice_basis, remind_basis, cancel_missed, price_increase',
      !!curC && ['renewed', 'cancel_by', 'days_to_cancel', 'switch_date', 'notice_basis', 'remind_basis', 'cancel_missed', 'price_increase']
        .every(k => k in curC) && typeof curC.renewed === 'boolean',
      curC ? `basis ${curC.notice_basis}` : 'kein laufender Vertrag');
    const withSp = (cs.contracts || []).find(c => Array.isArray(c.special_payments) && c.special_payments.length);
    check('contract-status(gas): special_payments[] mit Einzelposten', !!withSp,
      withSp ? `${withSp.special_payments.length} Posten` : 'kein Vertrag mit Sonderzahlungen in den Demo-Daten');
    if (withSp) {
      const sp = withSp.special_payments[0];
      check('special_payments-Posten hat date/kind/amount_eur/note',
        ['date', 'kind', 'amount_eur', 'note'].every(f => f in sp) && sp.amount_eur > 0, JSON.stringify(sp));
      check('special_payment_net ist Netto aus Kundensicht (Rückzahlung − Nach-/Abschlagszahlung)',
        Math.abs(withSp.special_payment_net - withSp.special_payments.reduce((s, p) =>
          s + (String(p.kind).startsWith('rueckzahlung') ? p.amount_eur : -p.amount_eur), 0)) < 0.005,
        String(withSp.special_payment_net));
    }
    const wasserMeters = await j('/api/utility/wasser/meters');
    if (Array.isArray(wasserMeters) && wasserMeters.length) {
      const wcs = await j(`/api/utility/wasser/meters/${wasserMeters[0].id}/contract-status`);
      check('contract-status(wasser): kein special_payments-Feld',
        (wcs.contracts || []).every(c => !('special_payments' in c)));
    }
  }

  const active = (settings.active_utilities && settings.active_utilities.length)
    ? settings.active_utilities : utilities.map(u => u.key);
  const activeUtils = utilities.filter(u => active.includes(u.key));
  doc.getElementById('primary-nav').innerHTML =
    activeUtils.map(u => `<a data-route="utility:${u.key}" data-utility="${u.key}">${u.label}</a>`).join('') +
    `<a data-route="tariffs">T</a><a data-route="recommendations">E</a><a data-route="reminders">R</a>`;
  const routes = [...doc.querySelectorAll('#primary-nav a')].map(a => a.getAttribute('data-route'));
  check('Sidebar enthält tariffs/recommendations/reminders',
    routes.includes('tariffs') && routes.includes('recommendations') && routes.includes('reminders'));
  check('Sidebar listet nur aktive Utilities',
    activeUtils.every(u => routes.includes('utility:' + u.key)),
    routes.filter(r => r && r.startsWith('utility:')).join(','));

  // 6. v1.6.1 — Regression Issue #14: Wasser-Monthly hat m3 ≠ 0
  //    (vor v1.6.1 las utility.js fälschlich m.kwh, was bei Wasser 0
  //    ist → KPI „Verbrauch" zeigte 0).
  const wMeters = await j('/api/utility/wasser/meters');
  if (wMeters.length) {
    const cons = await j(`/api/utility/wasser/meters/${wMeters[0].id}/consumption`);
    const monthly = cons.monthly || [];
    const totM3   = monthly.reduce((s, m) => s + (m.m3 || 0), 0);
    const totKwh  = monthly.reduce((s, m) => s + (m.kwh || 0), 0);
    check('Wasser-Monthly hat m3 ≠ 0 (Issue #14)',
      totM3 > 0 && totKwh === 0,
      `m3=${totM3.toFixed(1)} kwh=${totKwh.toFixed(1)}`);
    // 7. v1.6.1 — Regression Issue #13: device_swap-Flag pro Monat
    //    vorhanden (auch wenn in Demo-Daten überall false).
    const hasFlag = monthly.length > 0 && 'device_swap' in monthly[0];
    check('Monatszeilen tragen device_swap-Flag (Issue #13)', hasFlag);
  }

  // 8. v2.6.0 — Formen, auf die sich die neuen Oberflächenteile stützen.
  const sess = await j('/api/session');
  check('GET /api/session liefert mode/authenticated/mode_fixed/password_fixed',
    ['off', 'password', 'proxy'].includes(sess.mode) && typeof sess.authenticated === 'boolean'
      && typeof sess.mode_fixed === 'boolean' && typeof sess.password_fixed === 'boolean',
    `mode=${sess.mode}`);
  const snaps = await j('/api/backup/snapshots');
  check('GET /api/backup/snapshots → Array mit name/size/created_at/reason',
    Array.isArray(snaps) && snaps.every(s => s.name && typeof s.size === 'number' && s.created_at && s.reason),
    `${snaps.length} Snapshots`);
  const keys = await j('/api/auth/keys');
  check('GET /api/auth/keys → Array ohne Schlüssel-Klartext',
    Array.isArray(keys) && keys.every(k => k.id && !('key' in k) && !('hash' in k)));
  const ovw2 = await j('/api/readings-overview');
  const row = (ovw2.rows || []).find(x => x.last_reading);
  check('readings-overview: typical_per_day, suspect_count, last_reading.id/device_id',
    row && 'typical_per_day' in row && typeof row.suspect_count === 'number'
      && typeof row.last_reading.id === 'string' && 'device_id' in row.last_reading);
  // v2.13.0 — Einrichtungs-Checkliste und Leerzustände fragen nach der Zahl der Stände
  check('readings-overview: reading_count (v2.13.0) als Ganzzahl',
    (ovw2.rows || []).length > 0 && ovw2.rows.every(x => Number.isInteger(x.reading_count))
      && row.reading_count >= 1, `${row?.reading_count}`);
  // v2.13.0 — die Oberfläche fragt die Eigenschaften einer Verbrauchsart ab,
  // statt Listen wie ['gas','strom','fernwaerme'] zu pflegen
  check('utilities: has_contracts, has_advance_payment_contracts, accounting_kind (v2.13.0)',
    utilities.every(u => typeof u.has_contracts === 'boolean' && typeof u.has_advance_payment_contracts === 'boolean'
      && ['consumption', 'feed_in', 'generation'].includes(u.accounting_kind))
      && utilities.find(u => u.key === 'pv_erzeugung')?.has_contracts === false
      && utilities.find(u => u.key === 'gas')?.has_advance_payment_contracts === true
      && utilities.find(u => u.key === 'wasser')?.has_advance_payment_contracts === false,
    utilities.map(u => `${u.key}:${u.accounting_kind}`).join(','));
  if (gMeters.length) {
    const gc = await j(`/api/utility/gas/meters/${gMeters[0].id}/consumption`);
    check('Verbrauch je Zähler liefert warnings[]', Array.isArray(gc.warnings), `${gc.warnings?.length} Warnungen`);
  }
  const head = await fetch(BASE + '/api/health', { method: 'HEAD' });
  check('HEAD /api/health → 200', head.status === 200, `HTTP ${head.status}`);
  const bad = await fetch(BASE + '/api/health', { method: 'DELETE' });
  const badBody = await bad.json().catch(() => ({}));
  check('Falsche Methode → 405 mit Allow-Header und Fehlercode',
    bad.status === 405 && /GET/.test(bad.headers.get('allow') || '') && typeof badBody.code === 'string',
    `HTTP ${bad.status}, Allow: ${bad.headers.get('allow')}, code: ${badBody.code}`);

  const failed = results.filter(r => !r.ok);
  console.log(`\n  ${results.length - failed.length}/${results.length} Checks bestanden`);
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error('  FEHLER:', e.message); process.exit(2); });
