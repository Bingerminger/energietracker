// Frontend-API-Shape-Test — prüft, dass die Backend-Endpoints exakt die
// Datenstrukturen liefern, die das Frontend erwartet. Benötigt einen
// laufenden Backend-Server (php -S auf :8899).
// v1.4.4: loadModule-Stub entfernt (war nicht-aufgerufener toter Code).
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');

const BASE = process.env.ET_TEST_HOST || 'http://127.0.0.1:8899';
const ROOT = require('path').resolve(__dirname, '..');

// v3.1.0 — jede Antwort sofort zu Ende lesen. Viele Prüfungen sehen nur Status
// und Kopf an; bleibt der Körper ungelesen und schließt der PHP-Server die
// Verbindung, bricht undici in der CI mit `assert(!this.paused)` ab (lokal nicht).
const rawFetch = globalThis.fetch;
globalThis.fetch = async (url, init) => {
  const r = await rawFetch(url, init);
  const buf = await r.arrayBuffer();
  const empty = [101, 204, 205, 304].includes(r.status) || (init?.method || 'GET').toUpperCase() === 'HEAD';
  return new Response(empty ? null : buf, { status: r.status, statusText: r.statusText, headers: r.headers });
};


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

  // v3.1.0 (I18N-10) — CSV „local" neben Format 1; unbekanntes Format → 400 mit Code
  const loc = await fetch(`${BASE}/api/export/temperatures.csv?format=local`);
  check('CSV ?format=local → text/csv mit Dateiname aus dem Katalog',
    loc.status === 200 && (loc.headers.get('content-type') || '').startsWith('text/csv')
      && /filename="?energietracker-temperaturen-/.test(loc.headers.get('content-disposition') || ''),
    loc.headers.get('content-disposition') || `HTTP ${loc.status}`);
  const badFmt = await fetch(`${BASE}/api/export/temperatures.csv?format=xlsx`);
  const fmtBody = await badFmt.json().catch(() => ({}));
  check('CSV ?format=xlsx → 400, code errors.export.formatInvalid',
    badFmt.status === 400 && fmtBody.code === 'errors.export.formatInvalid', `${badFmt.status} ${fmtBody.code}`);

  // v3.1.0 (H1) — Agenda, Kennzahlen für Home Assistant, Kalender-Abo
  const ag = await j('/api/agenda?days=90');
  const ev = (ag.events || [])[0];
  check('GET /api/agenda → events[{uid,kind,date,title,severity,due_now,href,ref}]',
    Array.isArray(ag.events) && ag.events.length > 0 && ev && typeof ev.uid === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(ev.date)
      && typeof ev.due_now === 'boolean' && ['overdue', 'urgent', 'due', 'upcoming'].includes(ev.severity),
    `${(ag.events || []).length} Ereignisse, ${(ag.events || []).filter(e => e.due_now).length} jetzt`);
  // v3.4.0 (F1024) — Erledigt mit Verlauf: Vorkommen, abhakbar, erledigt; Verlauf; 422 für Ablesung
  check('GET /api/agenda → occurrence (uid@anker), can_mark_done, done',
    (ag.events || []).every(e => typeof e.occurrence === 'string' && e.occurrence.startsWith(e.uid + '@')
      && typeof e.can_mark_done === 'boolean' && 'done' in e && typeof e.title_key === 'string'));
  check('… Ablesung fällig ist nicht abhakbar, Termine schon',
    (ag.events || []).filter(e => e.kind === 'reading_due').every(e => e.can_mark_done === false)
      && (ag.events || []).filter(e => e.kind === 'reminder' && !e.done).every(e => e.can_mark_done === true));
  // v3.5.0 (F1028) — Zuständigkeit an jedem Ereignis und jedem Termin (additiv)
  const trio = (x) => 'assignee' in x && 'assignee_id' in x && typeof x.assignee_former === 'boolean';
  check('GET /api/agenda und /api/reminders → assignee, assignee_id, assignee_former',
    (ag.events || []).every(trio) && rem.every(trio), `${rem.filter(r => r.assignee).length} Termine mit Zuständigkeit`);
  // v3.5.0 (F1029) — Abrechnung erwartet: Zeitraum, Frist, Sprung zum Erfassen
  const be = (ag.events || []).filter(e => e.kind === 'bill_expected');
  check('GET /api/agenda → bill_expected mit period_from/until, deadline, href zum Erfassen, abhakbar',
    be.every(e => /^\d{4}-\d{2}-\d{2}$/.test(e.ref.period_until) && 'deadline' in e.ref && typeof e.ref.final === 'boolean'
      && e.href.startsWith('#/bill-check?') && e.href.includes('capture=1') && (e.done || e.can_mark_done)),
    `${be.length} erwartet`);
  // v3.6.0 — Personenverzeichnis: auch ohne Anmeldung (frische Daten: leer, Demo-Daten: deren Personen)
  const people = await j('/api/people');
  check('GET /api/people → [{id, name}] aus dem Personenverzeichnis', Array.isArray(people)
    && people.every(p => Object.keys(p).join() === 'id,name' && /^u_[0-9a-z]{1,32}$/.test(p.id)), `${people.length} Personen`);
  check('GET /api/agenda und /api/reminders → assignee_deleted (v3.6.0)',
    (ag.events || []).every(e => typeof e.assignee_deleted === 'boolean') && rem.every(r => typeof r.assignee_deleted === 'boolean')
      && rem.filter(r => r.assignee_id).every(r => r.assignee_deleted || people.some(p => p.id === r.assignee_id)));
  // v3.7.0 (Paket zustaendig) — Zuständig an Zählern und Verträgen; Fristen mit Herkunft
  const four = (x) => 'assignee' in x && 'assignee_id' in x && typeof x.assignee_former === 'boolean' && typeof x.assignee_deleted === 'boolean';
  const mStrom = await j('/api/utility/strom/meters');
  const cStrom = await j('/api/utility/strom/contracts');
  check('GET …/meters und …/contracts → assignee, assignee_id, assignee_former, assignee_deleted (v3.7.0)',
    mStrom.every(four) && cStrom.every(four), `${mStrom.length} Zähler, ${cStrom.length} Verträge`);
  check('GET /api/agenda → assignee_source (own | contract | meter | null), GET /api/reminders → assignee_inherited',
    (ag.events || []).every(e => [null, 'own', 'contract', 'meter'].includes(e.assignee_source)
      && (e.assignee_source === null) === (e.assignee === null && !e.assignee_former))
      && rem.every(r => 'assignee_inherited' in r));
  const act = await j('/api/activity');
  check('GET /api/activity → Array', Array.isArray(act), `${act.length} Einträge`);
  const actCsv = await fetch(`${BASE}/api/activity.csv?format=local`);
  const actCsvBody = await actCsv.text();
  check('GET /api/activity.csv → text/csv mit Kopfzeile',
    actCsv.status === 200 && (actCsv.headers.get('content-type') || '').startsWith('text/csv') && actCsvBody.replace(/^\uFEFF/, '').startsWith('Datum;Aktion;Titel;Wer;Grund;Notiz\r\n'),
    actCsv.headers.get('content-disposition') || `HTTP ${actCsv.status}`);
  const rd = (ag.events || []).find(e => e.kind === 'reading_due');
  if (rd) {
    const r422 = await fetch(`${BASE}/api/agenda/done`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ uid: rd.uid }) });
    const b422 = await r422.json().catch(() => ({}));
    check('POST /api/agenda/done für reading_due → 422 errors.activity.notManual', r422.status === 422 && b422.code === 'errors.activity.notManual', `${r422.status} ${b422.code}`);
  }
  const sum = await j('/api/summary');
  const sm = (sum.meters || [])[0];
  check('GET /api/summary → summary_version 1, meters[].contract/forecast_12m, agenda',
    sum.summary_version === 1 && /^et_[0-9a-f]{16}$/.test(sum.instance_id || '') && sm && 'contract' in sm
      && 'forecast_12m' in sm && 'days_since_reading' in sm && typeof sum.agenda?.due === 'number',
    `${(sum.meters || []).length} Zähler`);
  const ics = await fetch(`${BASE}/api/calendar.ics`);
  const icsBody = await ics.text();
  check('GET /api/calendar.ics → text/calendar, BEGIN:VCALENDAR',
    ics.status === 200 && (ics.headers.get('content-type') || '').startsWith('text/calendar') && icsBody.startsWith('BEGIN:VCALENDAR\r\n'),
    `${(icsBody.match(/BEGIN:VEVENT/g) || []).length} Ereignisse`);

  // v3.4.0 (N1019) — Hilfe auf dem Server: eine Seite der Doku und ihre Bilder
  {
    const page = await fetch(`${BASE}/api/docs?path=${encodeURIComponent('en/README.md')}`);
    const d = (await page.json()).data || {};
    check('GET /api/docs?path=en/README.md → {path, lang, mirror, root_files, markdown}',
      page.status === 200 && d.path === 'en/README.md' && d.lang === 'en' && d.mirror === 'README.md'
        && Array.isArray(d.root_files) && d.root_files.includes('CHANGELOG.md') && /^# /.test(d.markdown || ''),
      `${(d.markdown || '').length} Zeichen`);
    const bad = await fetch(`${BASE}/api/docs?path=${encodeURIComponent('../VERSION')}`);
    check('GET /api/docs?path=../VERSION → 404 errors.docs.notFound', bad.status === 404 && (await bad.json()).code === 'errors.docs.notFound');
    const png = await fetch(`${BASE}/api/docs/asset?path=${encodeURIComponent('ui/screenshots/dashboard.png')}`);
    const head = Buffer.from(await png.arrayBuffer()).subarray(1, 4).toString();
    check('GET /api/docs/asset → image/png, nosniff', png.status === 200 && png.headers.get('content-type') === 'image/png'
      && png.headers.get('x-content-type-options') === 'nosniff' && head === 'PNG');
  }

  // v3.7.0 — Posteingang: was views/inbox.js und lib/inbox-queue.js lesen
  {
    const box = await j('/api/inbox');
    check('GET /api/inbox → entries[], queue{counts,queued,next}, provider{id,label,local,available,model}',
      Array.isArray(box.entries) && Number.isInteger(box.queue?.queued) && typeof box.queue?.counts === 'object'
        && box.provider?.id === 'local' && typeof box.provider.label === 'string' && box.provider.local === true
        && typeof box.provider.available === 'boolean' && typeof box.provider.model === 'string', `${box.entries?.length} Dokumente`);
    const e0 = box.entries?.[0];
    check('… Eintrag: id, attachment_id, name, status, type, flags, queued_at, created_at',
      !!e0 && /^inb_[0-9a-f]{12}$/.test(e0.id) && /^att_/.test(e0.attachment_id) && 'flags' in e0 && 'queued_at' in e0 && !!e0.status && 'type' in e0);
    if (e0) {
      const pv = await j(`/api/inbox/${e0.id}`);
      const f = pv.entry?.extraction?.fields?.consumption;
      check('GET /api/inbox/{id} → entry.extraction.fields{value,quote,page,status,reasons}, ohne Zwischenspeicher',
        !!f && 'value' in f && 'quote' in f && 'page' in f && ['ok', 'check', 'empty'].includes(f.status) && Array.isArray(f.reasons) && !('cache' in pv.entry));
      const p0 = pv.proposals?.[0];
      check('… assignment{utility,meter_id,contract_id}, suggested.by[], proposals[{id,kind,label_key,params,status,check,checked,sources}], schema',
        pv.assignment && 'utility' in pv.assignment && 'meter_id' in pv.assignment && 'contract_id' in pv.assignment && Array.isArray(pv.suggested?.by)
          && !!p0 && /^p_[0-9a-f]{12}$/.test(p0.id) && /^inbox\.proposal\./.test(p0.label_key) && typeof p0.params === 'object'
          && ['new', 'exists', 'conflict', 'overlap'].includes(p0.status) && typeof p0.check === 'boolean' && typeof p0.checked === 'boolean'
          && Array.isArray(p0.sources) && pv.schema?.period_from?.kind === 'date', `${pv.proposals?.length} Vorschläge`);
      const rev = await j('/api/inbox/review');
      check('GET /api/inbox/review → [{entry_id, field, value, quote, page, reasons}]', Array.isArray(rev) && rev.every(r => r.entry_id && r.field && Array.isArray(r.reasons)), `${rev.length}`);
      // v3.7.0 (Paket betrieb) — Kernaussage (lib/inbox.js gistText/gistFigure, views/inbox.js), Ordner, Akte (components/dossier.js)
      const g = pv.gist;
      check('… gist{type,key,params,figure{kind,value,tone},action{kind,key},details[]} — auch in der Liste',
        !!g && /^inbox\.gist\./.test(g.key) && typeof g.params === 'object' && ['money_year', 'money_month', 'money', 'result', 'date', 'price_ct'].includes(g.figure?.kind)
          && ['good', 'warn', 'bad', 'neutral'].includes(g.figure.tone) && ['apply', 'link', 'remind', 'file'].includes(g.action?.kind) && Array.isArray(g.details)
          && e0.gist?.key === g.key, g ? `${g.key} · ${g.action?.kind}` : 'kein gist');
      check('GET /api/inbox → folder{dir,present,pending}', box.folder?.dir === 'posteingang' && typeof box.folder.present === 'boolean' && Number.isInteger(box.folder.pending));
      const dos = await j(`/api/utility/gas/dossier?meter_id=${encodeURIComponent(pv.assignment.meter_id || 'm_gas_default')}`);
      check('GET /api/utility/{u}/dossier → items[{kind,type,date,title,attachment_id}]', Array.isArray(dos.items)
        && dos.items.every(i => ['inbox', 'bill', 'receipt', 'photo'].includes(i.kind) && typeof i.date === 'string' && 'attachment_id' in i && 'title' in i), `${dos.items?.length}`);
    }
    const pdf = Buffer.from('%PDF-1.4\n% shape test\n%%EOF\n');
    const upPdf = await fetch(`${BASE}/api/inbox?name=shape.pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf' }, body: pdf })
      .then(async r => ({ status: r.status, data: (await r.json()).data }));
    check('POST /api/inbox (PDF, roher Body) → 201, Eintrag „neu“', upPdf.status === 201 && upPdf.data?.status === 'new' && upPdf.data.name === 'shape.pdf', `HTTP ${upPdf.status}`);
    const dup = await fetch(`${BASE}/api/inbox?name=nochmal.pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf' }, body: pdf });
    const dupJ = await dup.json();
    check('… dasselbe noch einmal → 409 errors.inbox.duplicate mit detail.existing', dup.status === 409 && dupJ.code === 'errors.inbox.duplicate'
      && dupJ.detail?.existing?.id === upPdf.data?.id);
    if (upPdf.data?.id) await fetch(`${BASE}/api/inbox/${upPdf.data.id}`, { method: 'DELETE' });
  }

  // v3.1.0 (H2) — Beleg hochladen (roher Body), ausliefern, an eine Ablesung
  // hängen; doppeltes Senden mit derselben client_ref legt keinen zweiten Stand an
  const jpeg = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=', 'base64');
  const up = await fetch(`${BASE}/api/attachments?kind=reading_photo`, { method: 'POST', headers: { 'Content-Type': 'image/jpeg' }, body: jpeg })
    .then(async r => ({ status: r.status, data: (await r.json()).data }));
  check('POST /api/attachments (JPEG, roher Body) → 201, att_…, image/jpeg, sha256',
    up.status === 201 && /^att_[0-9a-f]{16}$/.test(up.data?.id || '') && up.data.mime === 'image/jpeg' && /^[0-9a-f]{64}$/.test(up.data.sha256 || ''),
    `HTTP ${up.status}`);
  const svg = await fetch(`${BASE}/api/attachments?kind=reading_photo`, { method: 'POST', headers: { 'Content-Type': 'image/jpeg' }, body: '<svg xmlns="http://www.w3.org/2000/svg"/>' });
  check('POST /api/attachments mit SVG-Inhalt → 400 errors.attachment.type',
    svg.status === 400 && (await svg.json()).code === 'errors.attachment.type');
  if (up.data?.id) {
    const dl = await fetch(`${BASE}/api/attachments/${up.data.id}`);
    const bytes = Buffer.from(await dl.arrayBuffer());
    check('GET /api/attachments/{id} → image/jpeg, immutable, gleiche Bytes',
      dl.status === 200 && dl.headers.get('content-type') === 'image/jpeg'
        && /immutable/.test(dl.headers.get('cache-control') || '') && bytes.equals(jpeg));
    const list = await j('/api/attachments');
    check('GET /api/attachments → attachments[], usage{count,bytes,max_bytes}',
      Array.isArray(list.attachments) && list.attachments.some(a => a.id === up.data.id)
        && Number.isInteger(list.usage?.bytes) && list.usage.max_bytes > 0);
    const gm = (await j('/api/utility/gas/meters'))[0];
    if (gm) {
      const body = JSON.stringify({ meter_id: gm.id, date: '2001-02-03', counter: 1, client_ref: 'shape-test-0001', attachment_id: up.data.id });
      const post = () => fetch(`${BASE}/api/utility/gas/readings`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body })
        .then(async r => ({ status: r.status, data: (await r.json()).data }));
      const first = await post();
      const second = await post();
      check('POST readings mit client_ref: 201, dann 200 duplicate:true, dieselbe ID, attachment_id',
        first.status === 201 && second.status === 200 && second.data?.duplicate === true
          && second.data.id === first.data?.id && first.data?.attachment_id === up.data.id,
        `${first.status}/${second.status}`);
      if (first.data?.id) await fetch(`${BASE}/api/utility/gas/readings/${first.data.id}`, { method: 'DELETE' });
    }
    await fetch(`${BASE}/api/attachments/${up.data.id}`, { method: 'DELETE' });
  }
  // v3.1.0 (H3) — Verbrauch je Zeitraum, Mietverhältnis, Budget
  {
    const JSONH = { 'Content-Type': 'application/json' };
    const post = (p, b, m = 'POST') => fetch(`${BASE}${p}`, { method: m, headers: JSONH, body: JSON.stringify(b) })
      .then(async r => ({ status: r.status, data: (await r.json()).data }));
    const pm = (await post('/api/utility/waerme/meters', { name: 'UVI', capture: 'period', installed_on: '2020-01-01' })).data;
    check('POST meters mit capture=period', pm?.capture === 'period');
    const per = await post('/api/utility/waerme/periods', { meter_id: pm.id, month: '2025-01', value: 750 });
    check('POST periods (Monat) → 201, from/to/value/value_unit',
      per.status === 201 && per.data.from === '2025-01-01' && per.data.to === '2025-01-31' && per.data.value === 750 && per.data.value_unit === 'consumption');
    const plist = await j(`/api/utility/waerme/periods?meter_id=${encodeURIComponent(pm.id)}`);
    check('GET periods?meter_id → Liste', Array.isArray(plist) && plist.length === 1);
    const ovl = await post('/api/utility/waerme/periods', { meter_id: pm.id, from: '2025-01-20', to: '2025-02-10', value: 1 });
    check('POST periods überlappend → 400 errors.period.overlap', ovl.status === 400);
    const ten = (await post('/api/tenancies', {
      start: '2024-01-01', billing_anchor: '01-01',
      prepayments: [{ from: '2024-01-01', heating_eur_month: 120, operating_eur_month: 0 }],
      prices: [{ from: '2024-01-01', heat_eur_per_kwh: 0.15 }], meter_ids: { heat: [pm.id] },
    })).data;
    const bud = await j(`/api/tenancies/${ten.id}/budget`);
    check('GET tenancies/{id}/budget → months[], expected/prepaid/projected_result_eur, risk, assumptions[]',
      Array.isArray(bud.months) && bud.months.length >= 1 && typeof bud.expected_eur === 'number'
        && typeof bud.projected_result_eur === 'number' && Array.isArray(bud.assumptions) && /^\d{4}-\d{2}-\d{2}$/.test(bud.period_from),
      `${bud.months?.length} Monate`);
    const st = await post(`/api/tenancies/${ten.id}/statements`, { period_from: '2024-01-01', period_to: '2024-12-31', total_cost_eur: 1500, prepaid_eur: 1440 });
    check('POST statements → 201, result_eur = Kosten − Vorauszahlung', st.status === 201 && st.data.result_eur === 60);
    const sts = await j(`/api/tenancies/${ten.id}/statements`);
    check('GET statements → Liste', Array.isArray(sts) && sts[0]?.id === st.data.id);
    const csv = await fetch(`${BASE}/api/export/waerme/periods.csv`);
    check('GET export/{u}/periods.csv → text/csv', csv.status === 200 && (csv.headers.get('content-type') || '').startsWith('text/csv')
      && (await csv.text()).includes('Von;Bis;Wert'));
    await fetch(`${BASE}/api/tenancies/${ten.id}`, { method: 'DELETE' });
    await fetch(`${BASE}/api/utility/waerme/periods/${per.data.id}`, { method: 'DELETE' });
    await fetch(`${BASE}/api/utility/waerme/meters/${pm.id}`, { method: 'DELETE' });
  }

  // v3.1.0 (H4) — CO₂-Preis, Aufteilung, Szenario
  {
    const y = new Date().getFullYear() - 1;
    const cc = await j(`/api/co2-costs?year=${y}`);
    const gasRow = (cc.rows || []).find(r => r.utility === 'gas');
    check('GET /api/co2-costs → supported, rows[{utility,kwh,emissions_kg,price_eur_t,cost_eur_net,cost_eur_gross,ct_per_kwh,source}]',
      cc.supported === true && Array.isArray(cc.rows) && (!gasRow || (typeof gasRow.ct_per_kwh === 'number' && gasRow.source === 'computed')),
      gasRow ? `Gas ${gasRow.cost_eur_gross} €` : 'ohne Gasjahr');
    const bad = await fetch(`${BASE}/api/co2-costs?year=1990`);
    check('GET /api/co2-costs?year=1990 → 400 errors.co2.yearInvalid', bad.status === 400 && (await bad.json()).code === 'errors.co2.yearInvalid');
    const cs = await j(`/api/co2-split?year=${y}`);
    check('GET /api/co2-split (Eigentum) → supported:false mit Hinweis', cs.supported === false && typeof cs.note === 'string');
    const pdf = await fetch(`${BASE}/api/reports/co2-split.pdf?year=${y}`);
    check('GET /api/reports/co2-split.pdf → application/pdf', pdf.status === 200 && (pdf.headers.get('content-type') || '').startsWith('application/pdf'));
    const gm = (await j('/api/utility/gas/meters'))[0];
    if (gm) {
      const fc = await j(`/api/utility/gas/meters/${gm.id}/forecast?co2_scenario_eur_t=150&co2_scenario_from=2021`);
      check('GET forecast?co2_scenario_eur_t → co2_scenario{eur_t,from,delta_ct_per_kwh,delta_cost_12m_eur}, co2_delta_eur je Monat',
        !fc.valid || (fc.co2_scenario?.eur_t === 150 && typeof fc.co2_scenario.delta_cost_12m_eur === 'number' && 'co2_delta_eur' in (fc.forecast?.[0] || {})));
    }
  }

  // v3.1.0 (H5) — Rechnungsprüfung für Strom, Versorgerrechnung erfassen, prüfen, buchen
  {
    const JSONH = { 'Content-Type': 'application/json' };
    const send = (p, b, m = 'POST') => fetch(`${BASE}${p}`, { method: m, headers: JSONH, body: b === undefined ? undefined : JSON.stringify(b) })
      .then(async r => { const x = await r.json(); return { status: r.status, data: x.data, code: x.code }; });
    const sm = (await j('/api/utility/strom/meters'))[0];
    const y = new Date().getFullYear() - 1;
    const bd = await j(`/api/utility/strom/meters/${sm.id}/bill-check?from=${y}-01-01&to=${y + 1}-01-01`);
    check('bill-check(strom) → rows[{from,to,kwh,energy_cost,fixed_cost}], totals{kwh,energy_cost,fixed_cost,bonus,total,price_missing}',
      Array.isArray(bd.rows) && bd.rows.length > 0 && ['energy_cost', 'fixed_cost', 'kwh'].every(k => k in bd.rows[0])
        && ['kwh', 'energy_cost', 'fixed_cost', 'bonus', 'total', 'price_missing'].every(k => k in (bd.totals || {})),
      `${bd.rows?.length} Zeilen, ${bd.totals?.total} €`);
    const b = await send('/api/utility/strom/bills', { meter_id: sm.id, period_from: `${y}-01-01`, period_to: `${y}-12-31`,
      issued_on: `${y + 1}-02-01`, invoice: { energy_kwh: bd.totals.kwh, amount_eur: bd.totals.total + 1, advances_paid_eur: bd.totals.total - 49 } });
    check('POST bills → 201, result_eur = Betrag − Abschläge', b.status === 201 && b.data.invoice.result_eur === 50, `Status ${b.status}`);
    const cmp = await j(`/api/utility/strom/bills/${b.data.id}/check`);
    check('GET bills/{id}/check → ours/invoice/delta/verdict/reasons', cmp.verdict === 'ok' && typeof cmp.ours?.total === 'number'
      && typeof cmp.delta?.eur === 'number' && Array.isArray(cmp.reasons), `${cmp.verdict} Δ ${cmp.delta?.eur}`);
    const booked = await send(`/api/utility/strom/bills/${b.data.id}/book`, {});
    const again = await send(`/api/utility/strom/bills/${b.data.id}/book`, {});
    check('POST bills/{id}/book → special_payment_id, zweites Buchen ändert nichts',
      booked.status === 200 && !!booked.data.special_payment_id && again.data.special_payment_id === booked.data.special_payment_id);
    // aufräumen: Sonderzahlung aus dem Vertrag, Rechnung löschen
    const contracts = await j(`/api/utility/strom/contracts?meter_id=${encodeURIComponent(sm.id)}`);
    const ct = contracts.find(c => c.id === booked.data.contract_id);
    if (ct) await send(`/api/utility/strom/contracts/${ct.id}`, { special_payments: (ct.special_payments || []).filter(p => p.id !== booked.data.special_payment_id) }, 'PATCH');
    const del = await fetch(`${BASE}/api/utility/strom/bills/${b.data.id}`, { method: 'DELETE' });
    check('DELETE bills/{id} → 200', del.status === 200);
    // v3.5.0 (GitHub #34) — Wasser: die Buchung kommt im Vertrag an (bis v3.4
    // meldete die API „gebucht“, der Vertrag verwarf die Sonderzahlung still)
    const wc0 = (await j('/api/utility/wasser/contracts')).find(c => !c.is_shadow && c.meter_id);
    if (!wc0) check('wasser: Vertrag in den Demo-Daten für die Buchung', false);
    else {
      const wb = await send('/api/utility/wasser/bills', { meter_id: wc0.meter_id, period_from: wc0.start, period_to: wc0.start,
        invoice: { amount_eur: 300, advances_paid_eur: 280 } });
      const wBooked = await send(`/api/utility/wasser/bills/${wb.data?.id}/book`, {});
      const wc = (await j('/api/utility/wasser/contracts')).find(c => c.id === wBooked.data?.contract_id);
      const stored = (wc?.special_payments || []).find(p => p.id === wBooked.data?.special_payment_id);
      const wst = await j(`/api/utility/wasser/meters/${wc0.meter_id}/contract-status`);
      const wrow = (wst.contracts || []).find(c => c.contract_id === wc?.id);
      check('wasser: bills/{id}/book → Sonderzahlung im Wasservertrag, contract-status mit special_payments[] (v3.5.0, #34)',
        wBooked.status === 200 && stored?.kind === 'nachzahlung_ohne' && stored?.amount_eur === 20
          && Array.isArray(wrow?.special_payments) && wrow.special_payments.some(p => p.kind === 'nachzahlung_ohne' && p.amount_eur === 20),
        `${wBooked.status} ${wBooked.code || ''} ${stored ? 'gespeichert' : 'nicht im Vertrag'}`);
      if (wc) await send(`/api/utility/wasser/contracts/${wc.id}`, { special_payments: (wc.special_payments || []).filter(p => p.id !== wBooked.data.special_payment_id) }, 'PATCH');
      if (wb.data?.id) await fetch(`${BASE}/api/utility/wasser/bills/${wb.data.id}`, { method: 'DELETE' });
    }
    const oil = await send('/api/utility/heizoel/bills', { period_from: `${y}-01-01`, period_to: `${y}-12-31` });
    check('POST heizoel/bills → 400 errors.billCheck.unsupportedUtility', oil.status === 400 && oil.code === 'errors.billCheck.unsupportedUtility', `${oil.status} ${oil.code}`);
    const mal = await send(`/api/utility/strom/meters/${sm.id}`, { malo_id: '51234567894' }, 'PATCH');
    check('PATCH meter malo_id mit falscher Prüfziffer → 400 errors.meter.maloInvalid', mal.status === 400 && mal.code === 'errors.meter.maloInvalid');
  }

  // v3.1.0 (H6, #17) — Gruppenvertrag HT/NT: ein Grundpreis, Auswertungen auf der Gruppe
  {
    const JSONH = { 'Content-Type': 'application/json' };
    const send = (p, b, m = 'POST') => fetch(`${BASE}${p}`, { method: m, headers: JSONH, body: b === undefined ? undefined : JSON.stringify(b) })
      .then(async r => { const x = await r.json(); return { status: r.status, data: x.data, code: x.code }; });
    const y = new Date().getFullYear() - 1;
    const ht = (await send('/api/utility/strom/meters', { name: 'HT-Test', installed_on: `${y}-01-01` })).data;
    const nt = (await send('/api/utility/strom/meters', { name: 'NT-Test', installed_on: `${y}-01-01` })).data;
    const grp = (await send('/api/utility/strom/meter-groups', { name: 'Doppeltarif-Test' })).data;
    for (const m of [ht, nt]) await send(`/api/utility/strom/meters/${m.id}`, { meter_group_id: grp.id }, 'PATCH');
    const rIds = [];
    for (const [m, kwh] of [[ht, 2000], [nt, 1000]]) {
      for (const [d, v] of [[`${y}-01-01`, 0], [`${y + 1}-01-01`, kwh]]) {
        const r = await send('/api/utility/strom/readings', { meter_id: m.id, date: d, counter: v });
        if (r.data?.id) rIds.push(r.data.id);
      }
    }
    const gc = await send('/api/utility/strom/contracts', { meter_group_id: grp.id, provider: 'Test', tariff_name: 'HT/NT', start: `${y}-01-01`,
      working_prices: [{ from: `${y}-01-01`, ct_per_kwh: 30 }], working_prices_by_meter: { [nt.id]: [{ from: `${y}-01-01`, ct_per_kwh: 22 }] },
      base_prices: [{ from: `${y}-01-01`, eur_per_month: 12 }] });
    check('POST contracts mit meter_group_id → 201, meter_id null', gc.status === 201 && gc.data.meter_id === null && gc.data.meter_group_id === grp.id, `${gc.status} ${gc.code || ''}`);
    const gcons = await j(`/api/utility/strom/meter-groups/${grp.id}/consumption`);
    const yearCost = (gcons.monthly || []).filter(m => String(m.ym).startsWith(`${y}-`)).reduce((s, m) => s + (m.cost || 0), 0);
    check('GET meter-groups/{id}/consumption → Summe der Mitglieder, Grundpreis einmal (964 €)', Math.abs(yearCost - 964) < 0.5, yearCost.toFixed(2));
    const gst = await j(`/api/utility/strom/meter-groups/${grp.id}/contract-status`);
    check('GET meter-groups/{id}/contract-status → Gruppenvertrag', (gst.contracts || [])[0]?.contract_id === gc.data.id);
    const mst = await j(`/api/utility/strom/meters/${nt.id}/contract-status`);
    check('contract-status eines Mitglieds → group_contract {group_id, contract_id}', mst.group_contract?.group_id === grp.id && mst.group_contract?.contract_id === gc.data.id);
    for (const r of ['forecast', 'tariff-switch', `bill-check?from=${y}-01-01&to=${y + 1}-01-01`]) {
      const res = await fetch(`${BASE}/api/utility/strom/meter-groups/${grp.id}/${r}`);
      check(`GET meter-groups/{id}/${r.split('?')[0]} → 200`, res.status === 200, `Status ${res.status}`);
    }
    const ovl = await send('/api/utility/strom/contracts', { meter_id: ht.id, provider: 'X', start: `${y}-06-01`, working_prices: [{ from: `${y}-06-01`, ct_per_kwh: 25 }] });
    check('eigener Vertrag eines Mitglieds im Gruppenzeitraum → 400 errors.contract.groupMemberOverlap', ovl.status === 400 && ovl.code === 'errors.contract.groupMemberOverlap');
    // v3.1.0 (H6, MKT-12/MKT-14) — Marktpreise und Ladestrom-Nachweis
    const mp = await j('/api/market-prices');
    check('GET /api/market-prices → months{}, unit ct/kWh, attribution SMARD', typeof mp.months === 'object' && mp.unit === 'ct/kWh' && /SMARD/.test(mp.attribution || ''));
    const mpDry = await fetch(`${BASE}/api/market-prices/import-csv?dry_run=1`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: `${y}-01;100\n${y}-02;80,5\n` })
      .then(r => r.json()).then(x => x.data);
    check('POST market-prices/import-csv?dry_run=1 → would_import, nichts geschrieben', mpDry?.would_import === 2);
    const ev = await j(`/api/reports/ev-charging?meter_id=${encodeURIComponent(nt.id)}&year=${y}&method=flat&flat_ct=34`);
    check('GET /api/reports/ev-charging (Pauschale) → rows[{ym,kwh,price_ct,base_share_eur,amount_eur}], total', Array.isArray(ev.rows) && ev.rows.length > 0
      && ['ym', 'kwh', 'price_ct', 'base_share_eur', 'amount_eur'].every(k => k in ev.rows[0]) && Math.abs(ev.total.amount_eur - ev.total.kwh * 0.34) < 0.05,
      `${ev.total?.kwh} kWh → ${ev.total?.amount_eur} €`);
    const evCsv = await fetch(`${BASE}/api/reports/ev-charging.csv?meter_id=${encodeURIComponent(nt.id)}&year=${y}&method=flat&flat_ct=34`);
    check('GET ev-charging.csv → text/csv, Kopf Format 1', evCsv.status === 200 && (await evCsv.text()).includes('Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode'));
    const evPdf = await fetch(`${BASE}/api/reports/ev-charging.pdf?meter_id=${encodeURIComponent(nt.id)}&year=${y}&method=flat&flat_ct=34`);
    check('GET ev-charging.pdf → application/pdf', evPdf.status === 200 && (evPdf.headers.get('content-type') || '').startsWith('application/pdf'));
    // v3.1.0 (H7) — Wärmepumpe, PV-Bilanz additiv
    const hp = await j(`/api/heat-pump?year=${y}`);
    check('GET /api/heat-pump → year, pumps[], reference{air_water, ground_water}', hp.year === y && Array.isArray(hp.pumps) && hp.reference?.air_water === 3.4);
    const pvs = await j('/api/pv-summary');
    check('GET /api/pv-summary → additiv payback (null ohne Investition), hints[], yearly[].battery',
      'payback' in pvs && Array.isArray(pvs.hints) && (pvs.yearly || []).every(r => 'battery' in r));
    // v3.1.0 (H8) — Einordnung, Zeitreihe (Trockenlauf)
    const cmp = await j(`/api/benchmarks/comparison?year=${y}`);
    check('GET /api/benchmarks/comparison → year, strom{kwh,own_value,…}|null, heating[], links{}', cmp.year === y && Array.isArray(cmp.heating) && typeof cmp.links === 'object');
    // v3.6.0 — Ampel und Einladung (additiv): rating je Zeile, water{…}|null, missing[]
    check('GET /api/benchmarks/comparison → rating, water|null, missing[] (v3.6.0)', Array.isArray(cmp.missing) && 'water' in cmp
      && (cmp.strom === null || 'rating' in cmp.strom) && cmp.heating.every(h => 'rating' in h)
      && (cmp.water === null || ['m3', 'persons', 'reference_l', 'liters_per_person_day', 'rating'].every(k => k in cmp.water)));
    const ser = await send(`/api/utility/strom/meters/${ht.id}/import-series?dry_run=1`, {
      csv: `Zeit;Wh\n01.02.${y + 1} 00:00;500\n01.02.${y + 1} 00:15;500\n02.02.${y + 1} 00:00;250\n`,
      mapping: { skip_rows: 1, date_col: 0, value_col: 1, value_kind: 'consumption', unit_factor: 0.001, start_counter: 10 } });
    check('POST meters/{id}/import-series?dry_run=1 → days, total, preview[]', ser.status === 200 && ser.data.days === 2 && Math.abs(ser.data.total - 1.25) < 1e-9 && ser.data.dry_run === true,
      `${ser.status} ${ser.code || ''}`);
    // aufräumen
    await fetch(`${BASE}/api/utility/strom/contracts/${gc.data.id}`, { method: 'DELETE' });
    for (const id of rIds) await fetch(`${BASE}/api/utility/strom/readings/${id}`, { method: 'DELETE' });
    await fetch(`${BASE}/api/utility/strom/meter-groups/${grp.id}`, { method: 'DELETE' });
    for (const m of [ht, nt]) await fetch(`${BASE}/api/utility/strom/meters/${m.id}`, { method: 'DELETE' });
  }

  const ocr = await fetch(`${BASE}/api/ocr/reading`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"attachment_id":"att_0000000000000000"}' });
  check('POST /api/ocr/reading ohne eingetragenen Dienst → 400 errors.ocr.off',
    ocr.status === 400 && (await ocr.json()).code === 'errors.ocr.off');

  // v3.1.0 (I18N-12) — Jahresbericht als Daten für die Druckansicht
  const rep = await j(`/api/reports/yearly?year=${new Date().getFullYear() - 1}`);
  const rm = (rep.meters || [])[0];
  check('GET /api/reports/yearly → utilities[], meters[].months/kpis, recommendations[]',
    Number.isInteger(rep.year) && Array.isArray(rep.utilities) && rep.utilities.length > 0
      && rm && Array.isArray(rm.months) && typeof rm.kpis?.sum === 'number' && typeof rm.unit === 'string'
      && Array.isArray(rep.recommendations) && rep.efficiency && typeof rep.has_generation === 'boolean',
    `${(rep.meters || []).length} Zähler`);
  // v3.4.0 (F1025) — additiv: das Datenmodell des Berichts (PDF und Druckansicht)
  const md = rep.model || {};
  check('GET /api/reports/yearly → model (Kacheln, vier Ampeln, Aufgaben, Seiten je Art, Themen, Wörterbuch)',
    md.version === 1 && md.year === rep.year && Array.isArray(md.headline?.tiles) && md.headline?.lights?.length === 4
      && Array.isArray(md.tasks) && Array.isArray(md.successes) && Array.isArray(md.pages) && md.pages.length > 0
      && typeof md.topics === 'object' && Array.isArray(md.glossary) && Array.isArray(md.errors) && md.errors.length === 0
      && md.utilities.every(u => /^#[0-9a-f]{6}$/i.test(u.print_color)),
    `${(md.pages || []).length} Seiten, Fehler: ${(md.errors || []).join(',')}`);
  const repPart = await j(`/api/reports/yearly?year=${rep.year}&utilities=wasser&lang=en&detail=1`);
  check('GET /api/reports/yearly?utilities=wasser&lang=en&detail=1 → Teilbericht englisch mit Anhang',
    repPart.model?.lang === 'en' && repPart.model.pages.every(p => p.utility === 'wasser')
      && repPart.utilities.every(u => u.utility === 'wasser') && Array.isArray(repPart.model.appendix),
    `${repPart.model?.lang} ${(repPart.model?.pages || []).map(p => p.utility).join(',')}`);
  const repUnknown = await fetch(`${BASE}/api/reports/yearly?utilities=gas,xyz`);
  const repUnknownBody = await repUnknown.json().catch(() => ({}));
  check('GET /api/reports/yearly?utilities=gas,xyz → 400 errors.common.unknownUtility',
    repUnknown.status === 400 && repUnknownBody.code === 'errors.common.unknownUtility', `${repUnknown.status} ${repUnknownBody.code}`);
  const repPdf = await fetch(`${BASE}/api/reports/yearly.pdf?year=${rep.year}&lang=fr&utilities=gas,wasser`);
  const repPdfBody = Buffer.from(await repPdf.arrayBuffer()).toString('latin1');
  check('GET /api/reports/yearly.pdf?lang=fr&utilities=gas,wasser → PDF französisch, Dateiname mit Arten',
    repPdf.status === 200 && repPdfBody.startsWith('%PDF') && repPdfBody.includes('/Lang (fr)')
      && /energietracker-\d{4}-gas-wasser\.pdf/.test(repPdf.headers.get('content-disposition') || ''),
    `${repPdf.status} ${repPdf.headers.get('content-disposition')}`);
  const repBad = await fetch(`${BASE}/api/reports/yearly?year=1999`);
  const repBadBody = await repBad.json().catch(() => ({}));
  check('GET /api/reports/yearly?year=1999 → 400 errors.report.yearRange',
    repBad.status === 400 && repBadBody.code === 'errors.report.yearRange', `${repBad.status} ${repBadBody.code}`);

  // 4. efficiency shape
  const eff = await j('/api/benchmarks/efficiency?year=2024');
  check('Effizienz liefert class+kwh_per_m2',
    eff.class !== undefined && eff.kwh_per_m2 !== undefined,
    `${eff.class} / ${eff.kwh_per_m2} kWh/m²`);
  // v3.6.0 (Paket einpers) — angenommene Fläche (Schaufenster: keine Wohnfläche eingetragen)
  check('Effizienz (v3.6.0): area_assumed als Wahrheitswert', typeof eff.area_assumed === 'boolean', String(eff.area_assumed));
  // v2.7.0 — Klasse nur mit Skala des Landes; dashboard.js liest `scale`
  check('Effizienz nennt die Skala (Demo: DE → geg)', eff.scale === 'geg' && eff.scale_note === null,
    `scale=${eff.scale}`);
  // v2.10.0 — zweite Zahl (energieausweis-nah) und Abdeckung je Quelle
  check('Effizienz (v2.10.0): certificate + coverage_days/complete je Quelle',
    eff.certificate && typeof eff.certificate.kwh_per_m2 === 'number' && eff.certificate.area_factor >= 1.2
      && (eff.per_source || []).every(s => Number.isInteger(s.coverage_days) && typeof s.complete === 'boolean'),
    eff.certificate ? `${eff.certificate.kwh_per_m2} kWh/m²·a auf ${eff.certificate.area_m2} m²` : 'kein certificate');
  // v3.5.0 — Verfahren des Rechentags (GModG ab 2027), additiv; months_36 bleibt
  const ce = eff.certificate || {};
  const ruleOk = (x) => ['geg2024', 'gmodg2027'].includes(x.rules) && ['hi', 'hs'].includes(x.fuel_basis)
    && Array.isArray(x.fuel_basis_approx) && [36, 24].includes(x.months_required) && Number.isInteger(x.months_in_window)
    && typeof x.dhw_rule === 'number';
  check('Effizienz (v3.5.0): certificate.rules/fuel_basis/months_required/months_in_window, months_36 bleibt',
    ruleOk(ce) && Number.isInteger(ce.months_36) && ce.months_in_window <= ce.months_36
      && (ce.upcoming === null || (ruleOk(ce.upcoming) && /^\d{4}-\d{2}-\d{2}$/.test(ce.upcoming.from) && typeof ce.upcoming.kwh_per_m2 === 'number')),
    `${ce.rules} · ${ce.months_in_window}/${ce.months_required} · upcoming ${ce.upcoming ? ce.upcoming.rules : 'null'}`);
  const du = await j('/api/settings/default-updates');
  check('default-updates (v2.10.0) → Liste (Demo: aktuelle Werte, also leer)', Array.isArray(du) && du.length === 0,
    JSON.stringify(du).slice(0, 80));
  const pvs = await j('/api/pv-summary');
  const pvy = (pvs.yearly || [])[0];
  check('pv-summary (v2.10.0): months_covered, savings_eur, feed_in_revenue_eur',
    !pvy || (Number.isInteger(pvy.months_covered) && 'savings_eur' in pvy && 'feed_in_revenue_eur' in pvy),
    pvy ? `${pvy.year}: ${pvy.months_covered} Monate` : 'keine PV');
  // v3.4.0 (F1026) — Energiefluss: Monatszeilen mit Speicher und Verbrauchern,
  // Fluss je abgedecktem Monat und Jahr; Autarkie = Kennzahl des Flusses
  const pvm = (pvs.monthly || []).find(m => m.covered);
  const flowKeys = ['pv_house', 'pv_battery', 'pv_grid', 'grid_house', 'grid_battery', 'battery_house', 'house', 'grid_in_house', 'heat_pump', 'ev_charger', 'autarky'];
  check('pv-summary (v3.4.0): monthly[].battery/heat_pump_kwh/ev_charger_kwh/flow, yearly[].flow mit months, autarkiequote = flow.autarky',
    (pvs.monthly || []).every(m => ['battery', 'heat_pump_kwh', 'ev_charger_kwh', 'flow'].every(k => k in m))
      && (!pvm || (flowKeys.every(k => k in pvm.flow) && pvm.autarkiequote === pvm.flow.autarky))
      && (pvs.yearly || []).every(y => 'flow' in y && 'heat_pump_kwh' in y && 'ev_charger_kwh' in y
        && (y.flow === null || (Number.isInteger(y.flow.months) && y.autarkiequote === y.flow.autarky))),
    pvm ? `${pvm.ym}: Haus ${pvm.flow.house} kWh, Autarkie ${pvm.flow.autarky}` : 'keine PV');
  // v3.7.0 (#39) — verteilte Monate: Kennung je Monat und Jahr, Hinweis mit Zahl der Ablesungen
  check('pv-summary (v3.7.0): monthly[].interpolated, interpolated_series[], yearly[].months_interpolated, interpolation null oder {utility, readings, from, to}',
    (pvs.monthly || []).every(m => typeof m.interpolated === 'boolean' && Array.isArray(m.interpolated_series))
      && (pvs.yearly || []).every(y => Number.isInteger(y.months_interpolated))
      && (pvs.interpolation === null || (Number.isInteger(pvs.interpolation.readings) && typeof pvs.interpolation.utility === 'string'))
      && ((pvs.interpolation !== null) === (pvs.hints || []).includes('interpolated')),
    JSON.stringify(pvs.interpolation));

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
  // v3.6.0 (Paket einpers) — Angaben, die nur ihren Default tragen (Einordnung lädt dann ein)
  check('settings: assumed_keys (v3.6.0) als Liste aus Wohnfläche/Personen',
    Array.isArray(settings.assumed_keys) && settings.assumed_keys.every(k => ['wohnflaeche_m2', 'wasser_personen_anzahl'].includes(k)),
    JSON.stringify(settings.assumed_keys));
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
    // v3.1.0 (H5) — Strom, Wasser, Fernwärme haben die Rechnungsprüfung; Heizöl nicht
    const bad = await fetch(`${BASE}/api/utility/heizoel/meters/x/bill-check?from=2025-01-01&to=2026-01-01`);
    const badBody = await bad.json();
    check('bill-check für Heizöl → 400 errors.billCheck.unsupportedUtility', bad.status === 400 && badBody.code === 'errors.billCheck.unsupportedUtility', `Status ${bad.status}`);

    // v2.8.0 — C1: Monatszeilen mit den neuen Feldern, Kurven der Regression,
    // Prognose mit Hinweisen und Band, Saldo nach Kalender
    const gm = await j(`/api/utility/gas/meters/${gasMeters[0].id}/consumption`);
    const gRow = (gm.monthly || []).find(m => m.regression_point);
    check('consumption(gas): regression_point, heat_adjusted, weather_delta_pct, hdd_normal',
      !!gRow && ['heat_adjusted', 'weather_delta_pct', 'hdd_normal', 'expected_heat'].every(f => f in gRow),
      gRow ? Object.keys(gRow).filter(k => /heat|weather|normal|regression/.test(k)).join(',') : 'kein Regressionspunkt');
    check('consumption(gas): Regression mit Kurvenpunkten',
      Array.isArray(gm.regressions?.linear?.curve) && gm.regressions.linear.curve.length >= 2);
    // v3.7.0 (#37, #38) — Kennung je Monat und an der Antwort, additiv
    check('consumption(gas): je Monat interpolated (bool), measured_share (0…1), reading_gap_days',
      (gm.monthly || []).length > 0 && gm.monthly.every(m => typeof m.interpolated === 'boolean'
        && typeof m.measured_share === 'number' && m.measured_share >= 0 && m.measured_share <= 1 && 'reading_gap_days' in m));
    check('consumption(gas): interpolation {max_gap_days, months, interpolated_months, interpolated[], readings, readings_by_year, from, to, heat_basis}',
      gm.interpolation && gm.interpolation.max_gap_days === 45 && Array.isArray(gm.interpolation.interpolated)
        && Number.isInteger(gm.interpolation.readings) && typeof gm.interpolation.readings_by_year === 'object'
        && /^\d{4}-\d{2}$/.test(gm.interpolation.from) && ['months', 'intervals', null].includes(gm.interpolation.heat_basis),
      JSON.stringify(gm.interpolation || null).slice(0, 160));
    check('consumption(gas): anomaly_quality {model_quality good|poor, ratio, detect_floor, unit}',
      gm.anomaly_quality === null || (['good', 'poor'].includes(gm.anomaly_quality.model_quality)
        && typeof gm.anomaly_quality.detect_floor === 'number' && gm.anomaly_quality.unit === 'kWh'),
      JSON.stringify(gm.anomaly_quality));
    const hMeters2 = await j('/api/utility/heizoel/meters');
    if (hMeters2.length) {
      const hm = await j(`/api/utility/heizoel/meters/${hMeters2[0].id}/consumption`);
      check('consumption(heizoel): keine Heizkurve, Hinweis statt Zirkelschluss',
        Object.keys(hm.regressions || {}).length === 0 && hm.regressions_note === 'delivery_modelled'
        && (hm.monthly || []).length > 0 && hm.monthly.every(m => m.regression_point === false));
      // v3.7.0 — Lieferarten: Kennung gilt nicht (null), keine Antwort-Kennung
      check('consumption(heizoel): interpolated null, interpolation null, anomaly_quality null',
        hm.monthly.every(m => m.interpolated === null && m.measured_share === null) && hm.interpolation === null && hm.anomaly_quality === null);
    }
    const gfc = await j(`/api/utility/gas/meters/${gasMeters[0].id}/forecast`);
    check('forecast(gas): warnings[], hdd_source, annual, band je Monat',
      Array.isArray(gfc.warnings) && 'hdd_source' in gfc && 'annual' in gfc
        && (gfc.forecast || []).every(f => 'band_low' in f && 'band_high' in f && 'contract_assumed' in f),
      `hdd_source=${gfc.hdd_source} warnings=${(gfc.warnings || []).map(w => w.code).join(',')}`);
    // v3.7.0 (wirklichkeit-2) — additiv: Gütesperre der Prognose, Grund je Monat, Heizgrenze nur auf Anfrage
    check('forecast(gas): fit_quality {model_quality, ratio, reasons}',
      gfc.fit_quality === null || (['good', 'poor'].includes(gfc.fit_quality.model_quality) && Array.isArray(gfc.fit_quality.reasons)),
      JSON.stringify(gfc.fit_quality));
    check('consumption(gas): heat_adjusted_basis je Monat (model|summer|base_load|normal_is_actual|null), heat_limit nur mit ?heat_limit=1',
      (gm.monthly || []).every(m => [null, 'model', 'summer', 'base_load', 'normal_is_actual'].includes(m.heat_adjusted_basis)) && gm.heat_limit === null);
    // v3.9.0 (#38) — eigener Endpunkt: derselbe Vorschlag, mit Vorschlag dazu `effect`
    const hle = await j(`/api/utility/gas/meters/${gasMeters[0].id}/heat-limit`);
    check('heat-limit(gas): {heat_limit: null | {current, best, suggest, range, before, grid[], effect}}',
      hle && 'heat_limit' in hle && (hle.heat_limit === null || (typeof hle.heat_limit.suggest === 'boolean' && Array.isArray(hle.heat_limit.grid)
        && 'range' in hle.heat_limit && 'before' in hle.heat_limit && 'effect' in hle.heat_limit
        && (hle.heat_limit.suggest || hle.heat_limit.effect === null))),
      JSON.stringify(hle?.heat_limit ? { ...hle.heat_limit, grid: hle.heat_limit.grid.length } : hle));
    // v3.9.0 (ablesung-pruefen) — Zwischenablesung prüfen: Befund eines Zählers
    const rck = await j(`/api/utility/gas/meters/${gasMeters[0].id}/reading-checks`);
    check('reading-checks(gas): {checks[], duplicates[], level_shift, basis, unit, billing_only[]}',
      rck && Array.isArray(rck.checks) && Array.isArray(rck.duplicates) && 'level_shift' in rck && 'basis' in rck
        && rck.unit === 'm³' && Array.isArray(rck.billing_only)
        && rck.checks.every(c => typeof c.uid === 'string' && Array.isArray(c.reading_ids) && ['low', 'high'].includes(c.direction) && c.before && c.after),
      JSON.stringify(rck)?.slice(0, 200));
    const ghl = await j(`/api/utility/gas/meters/${gasMeters[0].id}/consumption?heat_limit=1`);
    check('consumption(gas)?heat_limit=1: {current, best, suggest, grid[]} oder null',
      ghl.heat_limit === null || (typeof ghl.heat_limit.suggest === 'boolean' && typeof ghl.heat_limit.current === 'number' && Array.isArray(ghl.heat_limit.grid)),
      JSON.stringify(ghl.heat_limit ? { ...ghl.heat_limit, grid: ghl.heat_limit.grid.length } : null));

    // v2.5.1 — Sonderzahlungen in der Vertragstabelle: der Status trägt die
    // Einzelposten (Gas), Wasser hat das Feld nicht.
    const cs = await j(`/api/utility/gas/meters/${gasMeters[0].id}/contract-status`);
    const curC = (cs.contracts || []).find(c => c.is_current);
    check('contract-status(gas): Saldo nach Kalender (balance_as_of, projection_method, cost_to_date)',
      !!curC && curC.projection_method === 'forecast' && typeof curC.balance_as_of === 'string'
        && 'cost_to_date' in curC && 'suggested_advance' in curC && 'measured_until' in curC
        && Math.abs(curC.energy_cost_to_date + curC.base_to_date - curC.bonus_to_date - curC.cost_to_date) < 0.02,
      curC ? `${curC.projection_method} · bezahlt ${curC.advance_paid}` : 'kein laufender Vertrag');
    // v2.16.0 (Review FE-31) — Saldo-Verlauf: Monatsreihe am laufenden Vertrag,
    // der letzte Punkt ist der erwartete Endsaldo; andere Verträge ohne Reihe
    const path = curC?.balance_path;
    const lastP = Array.isArray(path) ? path[path.length - 1] : null;
    check('contract-status(gas): balance_path endet beim erwarteten Endsaldo',
      Array.isArray(path) && path.length >= 2
        && path.every(p => ['ym', 'cost', 'paid', 'balance', 'estimated', 'future'].every(k => k in p))
        && Math.abs(lastP.balance - curC.projected_end_balance) < 0.03
        && (cs.contracts || []).filter(c => !c.is_current).every(c => c.balance_path == null),
      lastP ? `${path.length} Monate · Ende ${lastP.balance} · erwartet ${curC.projected_end_balance}` : 'keine Reihe');
    // v2.9.0 (CALC-10, CALC-11) — Verlängerung, Kündigung, Preiserhöhung
    check('contract-status(gas): renewed, cancel_by, days_to_cancel, switch_date, notice_basis, remind_basis, cancel_missed, price_increase',
      !!curC && ['renewed', 'cancel_by', 'days_to_cancel', 'switch_date', 'notice_basis', 'remind_basis', 'cancel_missed', 'price_increase']
        .every(k => k in curC) && typeof curC.renewed === 'boolean',
      curC ? `basis ${curC.notice_basis}` : 'kein laufender Vertrag');
    // v3.7.0 (wirklichkeit-2) — unsichere Schätzung, abgelöste Verträge, Preis fehlt (additiv)
    check('contract-status(gas): projection_uncertain (null oder {reasons, projected_range}), superseded_on, price_missing',
      (cs.contracts || []).every(c => 'superseded_on' in c && typeof c.price_missing === 'boolean')
        && !!curC && (curC.projection_uncertain === null
          || (Array.isArray(curC.projection_uncertain.reasons) && curC.projection_uncertain.projected_range?.length === 2 && curC.suggested_advance === null)),
      JSON.stringify(curC?.projection_uncertain ?? null));
    // v3.4.0 (F1026) — Zeitstrahl: Mindestlaufzeit, Preisgarantie, Verlängerung
    check('contract-status(gas): min_term_end, price_guarantee_until, auto_renews (bool)',
      (cs.contracts || []).every(c => 'min_term_end' in c && 'price_guarantee_until' in c && typeof c.auto_renews === 'boolean'),
      curC ? `auto_renews ${curC.auto_renews}` : 'kein laufender Vertrag');
    // v3.4.0 (F1024) — Folgevertrag: null oder {contract_id, provider, start, gap_days}
    check('contract-status(gas): successor null oder {contract_id, provider, start, gap_days}',
      (cs.contracts || []).every(c => 'successor' in c && (c.successor === null
        || (typeof c.successor.contract_id === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(c.successor.start)
          && Number.isInteger(c.successor.gap_days) && c.successor.gap_days >= 0 && 'provider' in c.successor))),
      (cs.contracts || []).map(c => c.successor ? `→ ${c.successor.start} (+${c.successor.gap_days})` : '–').join(' '));
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
    // v3.4.0 (N1021) — Phase je Vertrag (eine Quelle für beide Vertragslisten)
    // und die Werte, die eine Vertragskarte nennt
    const PHASES = ['future', 'current', 'renewed', 'past', 'shadow'];
    check('contract-status(gas): phase je Vertrag (future|current|renewed|past)',
      (cs.contracts || []).length > 0 && (cs.contracts || []).every(c => PHASES.includes(c.phase) && c.phase !== 'shadow')
        && (cs.contracts || []).filter(c => c.phase === 'current' || c.phase === 'renewed').length >= 1,
      (cs.contracts || []).map(c => c.phase).join(','));
    const gasContracts = await j('/api/utility/gas/contracts');
    check('GET contracts(gas): phase und values_on {date, working_price_ct, base_price_eur, advance_eur}',
      Array.isArray(gasContracts) && gasContracts.length > 0 && gasContracts.every(c => PHASES.includes(c.phase)
        && c.values_on && ['date', 'working_price_ct', 'base_price_eur', 'advance_eur'].every(k => k in c.values_on)),
      (gasContracts || []).map(c => c.phase).join(','));
    check('contract-status und contracts: dieselbe Phase',
      (cs.contracts || []).every(c => gasContracts.find(x => x.id === c.contract_id)?.phase === c.phase));
    const wasserMeters = await j('/api/utility/wasser/meters');
    if (Array.isArray(wasserMeters) && wasserMeters.length) {
      const wcs = await j(`/api/utility/wasser/meters/${wasserMeters[0].id}/contract-status`);
      // v3.5.0 (GitHub #34) — bis v3.4 „kein special_payments-Feld“: genau der Fehler
      check('contract-status(wasser): special_payments[] wie bei Gas (v3.5.0, #34)',
        (wcs.contracts || []).length > 0 && (wcs.contracts || []).every(c => Array.isArray(c.special_payments)));
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
  // v3.5.0 (GitHub #34) — Sonderzahlungen auch bei Wasser; die Oberfläche fragt
  // has_special_payments statt has_advance_payment_contracts
  check('utilities: has_special_payments (v3.5.0) — Gas, Strom, Wasser, Fernwärme',
    utilities.every(u => typeof u.has_special_payments === 'boolean')
      && utilities.filter(u => u.has_special_payments).map(u => u.key).sort().join(',') === 'fernwaerme,gas,strom,wasser',
    utilities.filter(u => u.has_special_payments).map(u => u.key).join(','));
  if (gMeters.length) {
    const gc = await j(`/api/utility/gas/meters/${gMeters[0].id}/consumption`);
    check('Verbrauch je Zähler liefert warnings[]', Array.isArray(gc.warnings), `${gc.warnings?.length} Warnungen`);
  }
  // v3.5.0 (F1027) — Tagesfilm: Felder, die energy-flow.js liest
  const efDays = await j('/api/energy-flow/days');
  check('energy-flow/days: available, days[], complete_days[], months[], Vorbelegung',
    typeof efDays.available === 'boolean' && Array.isArray(efDays.days) && Array.isArray(efDays.complete_days)
      && Array.isArray(efDays.months) && 'default_day' in efDays && 'default_month' in efDays
      && efDays.available === efDays.days.length > 0, `${efDays.days?.length} Tage`);
  const efDay = await j('/api/energy-flow/day?date=2025-06-14');
  const slotKeys = ['t', 'gen', 'feed', 'grid', 'charged', 'discharged', 'house', 'price', 'flow'];
  check('energy-flow/day: 96 Viertelstunden mit t, Mengen, house, price, flow; Summen, Vollständigkeit, Arten',
    efDay.mode === 'day' && efDay.step_minutes === 15 && efDay.slots?.length === 96
      && efDay.slots.every(s => slotKeys.every(k => k in s)) && efDay.slots[0].t === '00:00' && efDay.slots[95].t === '23:45'
      && 'flow' in efDay.totals && Number.isInteger(efDay.completeness?.complete)
      && ['gen', 'feed', 'battery', 'price'].every(k => typeof efDay.kinds?.[k] === 'boolean') && Array.isArray(efDay.missing),
    `${efDay.slots?.length} Plätze, ${efDay.completeness?.complete} vollständig`);
  const efFall = await j('/api/energy-flow/day?date=2025-10-26');
  check('energy-flow/day: Zeitumstellung Oktober = 100 Viertelstunden, zweite 2:00 mit repeat',
    efFall.slots?.length === 100 && efFall.slots.filter(s => s.repeat).length === 4 && efFall.slots[12].t === '02:00' && efFall.slots[12].repeat === true);
  const efTyp = await j('/api/energy-flow/typical-day?month=2025-06');
  check('energy-flow/typical-day: 96 Viertelstunden mit n, Zahl der Tage',
    efTyp.mode === 'typical' && efTyp.slots?.length === 96 && efTyp.slots.every(s => Number.isInteger(s.n)) && Number.isInteger(efTyp.days));
  const efBad = await fetch(BASE + '/api/energy-flow/day?date=2025-02-30');
  const efBadBody = await efBad.json().catch(() => ({}));
  check('energy-flow/day: kein Kalendertag → 400 errors.energyFlow.dateInvalid', efBad.status === 400 && efBadBody.code === 'errors.energyFlow.dateInvalid', `HTTP ${efBad.status} ${efBadBody.code}`);
  const efBadM = await fetch(BASE + '/api/energy-flow/typical-day?month=2025-13');
  const efBadMBody = await efBadM.json().catch(() => ({}));
  check('energy-flow/typical-day: Monat 13 → 400 errors.energyFlow.monthInvalid', efBadM.status === 400 && efBadMBody.code === 'errors.energyFlow.monthInvalid', `HTTP ${efBadM.status} ${efBadMBody.code}`);

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
