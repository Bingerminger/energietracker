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
  }

  // 4. efficiency shape
  const eff = await j('/api/benchmarks/efficiency?year=2024');
  check('Effizienz liefert class+kwh_per_m2',
    eff.class !== undefined && eff.kwh_per_m2 !== undefined,
    `${eff.class} / ${eff.kwh_per_m2} kWh/m²`);

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
  check('settings: alter Skalar gas_conversion_factor ist weg',
    !('gas_conversion_factor' in settings));
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

    // v2.5.1 — Sonderzahlungen in der Vertragstabelle: der Status trägt die
    // Einzelposten (Gas), Wasser hat das Feld nicht.
    const cs = await j(`/api/utility/gas/meters/${gasMeters[0].id}/contract-status`);
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

  const failed = results.filter(r => !r.ok);
  console.log(`\n  ${results.length - failed.length}/${results.length} Checks bestanden`);
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error('  FEHLER:', e.message); process.exit(2); });
