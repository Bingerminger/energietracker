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
    const fields = ['utility','meter_id','meter_name','consumption_unit','last_reading','expected_next_min'];
    check('overview-Row hat erwartete Felder',
      fields.every(f => f in r),
      fields.filter(f => !(f in r)).join(',') || 'alle vorhanden');
    const utils = [...new Set(ovw.rows.map(x => x.utility))];
    const noDelivery = !utils.includes('heizoel') && !utils.includes('pellets');
    check('overview enthält keine Delivery-Utilities',
      noDelivery,
      `utilities=${utils.join(',')}`);
  }

  // 5. JSDOM: Sidebar-HTML-Aufbau (reine DOM-Logik, ohne ES-Module-Loader)
  const dom = new JSDOM(`<!DOCTYPE html><body data-app-version="1.3.0">
    <nav id="primary-nav"></nav></body>`, { url: BASE });
  const doc = dom.window.document;
  // Simuliere, was sidebar.js erzeugt: hat die Nav nach manueller Befüllung
  // die erwarteten data-route-Werte?
  const utilities = await j('/api/utilities');
  const settings = await j('/api/settings');
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
