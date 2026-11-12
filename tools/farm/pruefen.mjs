#!/usr/bin/env node
// Prüfungen der lokalen Test-Farm (tools/farm/README.md). Aufruf über
// `bash tools/farm/farm.sh pruefen`, nachdem `farm.sh up` die Farm gestartet hat.
//
// Nur Beispieldaten (Beispielhaushalt „showcase“), nur Adressen auf 127.0.0.1.
// Node 22 oder neuer: fetch und WebSocket sind eingebaut, keine Pakete.
// Ausgabe: ein Bericht mit ✓/✗ je Prüfung; Exit-Code 1, sobald eine fehlschlägt.

import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const E = process.env;
const local = (name, fallback) => `http://127.0.0.1:${E[name] || fallback}`;
const ET = local('FARM_PORT_ET', '19840');
const ET_ROOTLESS = local('FARM_PORT_ET_ROOTLESS', '19841');
const HA_NEU = local('FARM_PORT_HA_NEU', '19842');
const HA_ALT = local('FARM_PORT_HA_ALT', '19843');
const EVCC = local('FARM_PORT_EVCC', '19844');
// Adressen im Netz der Farm: die Dienstnamen aus compose.yml
const ET_INTERN = 'http://energietracker';
const EVCC_INTERN = 'http://evcc:7070';
const PROJECT = E.FARM_PROJECT || 'et-farm';
const COMPOSE_FILE = E.FARM_COMPOSE_FILE || join(ROOT, 'tools', 'farm', 'compose.yml');
const INTEGRATION = E.FARM_INTEGRATION || join(E.HOME || '', 'Code', 'energietracker-hacs');

const VERSION = readFileSync(join(ROOT, 'VERSION'), 'utf8').trim();
const SCHEMA = (/SCHEMA_VERSION = '([\d.]+)'/.exec(readFileSync(join(ROOT, 'src', 'Storage', 'Migrator.php'), 'utf8')) || [])[1];
// Zugangsdaten gelten nur in dieser Farm (Ports nur auf 127.0.0.1, danach abgebaut)
const PASSWORT = 'farm-anmeldung-123';
const HA_BENUTZER = { name: 'Farm', username: 'farm', password: 'farm-ha-123', language: 'de' };

// ── Bericht ─────────────────────────────────────────────────────────────────
let ok = 0;
const fehler = [];
function check(name, cond, info = '') {
    const pass = Boolean(cond);
    console.log(`  ${pass ? '✓' : '✗'} ${name}${info !== '' ? ` (${info})` : ''}`);
    if (pass) ok++; else fehler.push(name);
    return pass;
}
async function abschnitt(titel, fn) {
    console.log(`\n== ${titel}`);
    try {
        await fn();
    } catch (e) {
        check(`${titel}: ohne Abbruch`, false, String(e?.message ?? e));
    }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function warten(was, sekunden, fn) {
    const bis = Date.now() + sekunden * 1000;
    for (;;) {
        try {
            const r = await fn();
            if (r) return r;
        } catch { /* weiter warten */ }
        if (Date.now() > bis) throw new Error(`${was}: nach ${sekunden} s nicht erreicht`);
        await sleep(2000);
    }
}

// ── HTTP ────────────────────────────────────────────────────────────────────
async function http(method, url, { headers = {}, json, form, timeout = 60000 } = {}) {
    const h = { Accept: 'application/json', ...headers };
    let body;
    if (json !== undefined) { h['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (form !== undefined) { h['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
    const r = await fetch(url, { method, headers: h, body, redirect: 'manual', signal: AbortSignal.timeout(timeout) });
    const text = await r.text();
    let data = null;
    try { data = JSON.parse(text); } catch { /* keine JSON-Antwort */ }
    return { status: r.status, headers: r.headers, text, data };
}
/** Energietracker: Antwort in der Hülle {success, data}; `d` ist der Inhalt. */
async function api(base, method, path, opts = {}) {
    const r = await http(method, `${base}/api${path}`, opts);
    const d = r.data && typeof r.data === 'object' && 'data' in r.data ? r.data.data : r.data;
    return { ...r, d };
}
const isObj = (x) => x !== null && typeof x === 'object' && !Array.isArray(x);

// ── Docker ──────────────────────────────────────────────────────────────────
function compose(...args) {
    return execFileSync('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, ...args],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}
function gesundheit(dienst) {
    const id = compose('ps', '-q', dienst).trim();
    if (id === '') return 'läuft nicht';
    return execFileSync('docker', ['inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}ohne{{end}}', id],
        { encoding: 'utf8' }).trim();
}
function logZeilen(dienst, muster, n = 8) {
    try {
        return compose('logs', '--no-color', '--tail', '800', dienst).split('\n').filter((l) => muster.test(l)).slice(-n);
    } catch { return []; }
}

// ── Gemeinsame Daten zwischen den Abschnitten ───────────────────────────────
const stand = { summary: null, todo: null };

// ── 1. Energietracker: Gesundheit und Version ───────────────────────────────
async function gesundheitUndVersion() {
    const h = await warten('Energietracker', 60, async () => {
        const r = await api(ET, 'GET', '/health');
        return r.status === 200 ? r : null;
    });
    const d = h.d || {};
    check(`health 200 und Version ${VERSION}`, d.version === VERSION, `Version ${d.version}, Status ${d.status}`);
    check('health ohne Fehler', d.status === 'ok' || d.status === 'degraded', String(d.status));
    check('Datenverzeichnis beschreibbar', d.data_dir_writable === true);
    const g = await warten('HEALTHCHECK', 90, async () => (gesundheit('energietracker') === 'healthy' ? 'healthy' : null))
        .catch(() => gesundheit('energietracker'));
    check('Container meldet healthy (HEALTHCHECK des Images)', g === 'healthy', g);
}

// ── 2. Beispielhaushalt ─────────────────────────────────────────────────────
async function beispielhaushalt() {
    const imp = await api(ET, 'POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 120000 });
    check('Beispielhaushalt „showcase“ geladen (POST /api/demo/import)', imp.status === 200 && imp.d?.persona === 'showcase',
        `HTTP ${imp.status}`);
    const s = await api(ET, 'GET', '/summary');
    const meters = Array.isArray(s.d?.meters) ? s.d.meters : [];
    const arten = new Set(meters.map((m) => m.utility));
    check('Übersicht mit Zählern aller acht Verbrauchsarten', s.status === 200 && arten.size === 8,
        `${meters.length} Zähler, ${arten.size} Arten`);
    stand.summary = s.d;
    // Das Schema steht erst nach dem ersten gewöhnlichen Aufruf in meta.json (ein frisches
    // Datenverzeichnis meldet bis dahin „?“), deshalb erst hier
    const h = (await api(ET, 'GET', '/health')).d || {};
    check(`Schema ${SCHEMA}, keine Migration offen`, h.schema_version === SCHEMA && Number(h.migrations_pending) === 0,
        `Schema ${h.schema_version}`);
}

// ── 3. Lesende Probe (Idee der ACC-Probe: nur GET, nur Struktur) ────────────
function dateienUnter(dir) {
    const out = [];
    for (const e of readdirSync(dir, { withFileTypes: true })) {
        if (e.name.startsWith('.')) continue;
        const p = join(dir, e.name);
        if (e.isDirectory()) out.push(...dateienUnter(p)); else out.push(p);
    }
    return out;
}
async function lesendeProbe(base, headers = {}, { mitDateien = true } = {}) {
    const mitSchluessel = 'Authorization' in headers;
    const get = (path) => api(base, 'GET', path, { headers });

    const sw = await http('GET', `${base}/sw.js`);
    check(`sw.js mit VERSION v${VERSION}`, sw.status === 200 && sw.text.includes(`const VERSION = 'v${VERSION}';`), `HTTP ${sw.status}`);
    const st = await get('/settings');
    check('Einstellungen lesbar', st.status === 200 && isObj(st.d), `HTTP ${st.status}`);

    const s = await get('/summary');
    const meters = Array.isArray(s.d?.meters) ? s.d.meters : [];
    const mitVertrag = meters.filter((m) => isObj(m.contract));
    check('summary antwortet', s.status === 200 && meters.length > 0, `${meters.length} Zähler`);
    if (check('summary: Zähler mit Vertrag vorhanden', mitVertrag.length > 0, `${mitVertrag.length}`)) {
        const k = mitVertrag[0].contract;
        check('summary: projected_end_balance_total, balance_measured, projection_band',
            'projected_end_balance_total' in k && 'balance_measured' in k && 'projection_band' in k);
    }
    const t = await get('/todo');
    check('todo: count, soon und next_upcoming', t.status === 200 && isObj(t.d) && 'count' in t.d && 'soon' in t.d && 'next_upcoming' in t.d,
        `HTTP ${t.status}`);

    // Vertragsstatus, Vertragsliste und Abrechnungsjahr: erster Zähler je Art mit Vertrag
    const gesehen = new Set();
    for (const m of mitVertrag) {
        const u = String(m.utility ?? ''); const id = String(m.meter_id ?? '');
        // Einspeisung: kein Abrechnungsjahr und keine Trennung gemessen/geschätzt (gewollt)
        if (u === '' || id === '' || gesehen.has(u) || u === 'pv_einspeisung') continue;
        gesehen.add(u);
        const cs = await get(`/utility/${u}/meters/${encodeURIComponent(id)}/contract-status`);
        const cur = (cs.d?.contracts ?? []).filter((r) => r && r.is_current);
        check(`contract-status ${u}: balance_measured und renewal_term am laufenden Vertrag`,
            cs.status === 200 && cur.length > 0 && 'balance_measured' in cur[0] && 'renewal_term' in cur[0], `HTTP ${cs.status}`);
        const cl = await get(`/utility/${u}/contracts`);
        const list = (Array.isArray(cl.d) ? cl.d : []).filter(isObj);
        check(`contracts ${u}: coverage`, cl.status === 200 && list.length > 0 && 'coverage' in list[0], `HTTP ${cl.status}`);
        const bp = await get(`/utility/${u}/meters/${encodeURIComponent(id)}/billing-periods`);
        const ps = Array.isArray(bp.d?.periods) ? bp.d.periods : [];
        const span = ps.map((p) => p?.prev_same_span).find(isObj);
        check(`billing-periods ${u}: periods[].assumed${span ? ', Vergleich period|calendar' : ''}`,
            bp.status === 200 && (ps.length === 0 || 'assumed' in ps[0]) && (!span || ['period', 'calendar'].includes(span.basis)),
            `HTTP ${bp.status}, ${ps.length} Perioden`);
    }

    const r = await get('/rights');
    const ri = r.d || {};
    check('rights: enabled, country, findings, bills_recorded', r.status === 200 && typeof ri.enabled === 'boolean'
        && typeof ri.country === 'string' && Array.isArray(ri.findings) && Number.isInteger(ri.bills_recorded),
        `${(ri.findings ?? []).length} Befunde`);

    const ms = await get('/mcp/status');
    if (mitSchluessel) {
        // Die Routen der Oberfläche unter /api/mcp/ nehmen nie einen Schlüssel an
        check('mcp/status mit Schlüssel abgewiesen (403)', ms.status === 403 && ms.data?.code === 'errors.mcp.proposal.noKey', `HTTP ${ms.status}`);
    } else {
        const x = ms.d || {};
        check('mcp/status: enabled, setting, locked_by_env, share_*, apply_in_app, login_active', ms.status === 200
            && ['enabled', 'setting', 'locked_by_env', 'share_identifiers', 'share_documents', 'apply_in_app', 'login_active']
                .every((f) => typeof x[f] === 'boolean'), `HTTP ${ms.status}`);
    }

    // Sperren: Daten, Quelltext, Werkzeuge und Git nie über HTTP
    const gesperrt = ['/data/settings.json', '/data/meta.json', '/src/bootstrap.php', '/.git/HEAD', '/composer.json',
        '/VERSION', '/Dockerfile', '/docs/README.md', '/demo-data/energietracker-demo-backup.json', '/tools/farm/farm.sh'];
    const offen = [];
    for (const p of gesperrt) {
        const x = await http('GET', `${base}${p}`, { headers });
        if (![403, 404].includes(x.status) || x.text.includes('"schema_version"')) offen.push(`${p} ${x.status}`);
    }
    check(`${gesperrt.length} gesperrte Pfade antworten 403/404`, offen.length === 0, offen.join(', '));

    if (mitDateien) {
        // Jede Datei unter public/ des Arbeitsstands kommt aus dem Image
        const files = dateienUnter(join(ROOT, 'public')).map((f) => '/' + relative(ROOT, f).split('\\').join('/'));
        const fehlt = [];
        for (const f of files) {
            const x = await fetch(`${base}${f.split('/').map(encodeURIComponent).join('/')}`, { signal: AbortSignal.timeout(30000) });
            await x.arrayBuffer();
            if (x.status !== 200) fehlt.push(`${f} ${x.status}`);
        }
        check(`alle ${files.length} Dateien unter public/ ausgeliefert`, fehlt.length === 0, fehlt.slice(0, 5).join(', '));
    }
}

// ── 4. MCP: JSON-RPC direkt gegen POST /api/mcp ─────────────────────────────
async function mcp() {
    const en = await api(ET, 'PATCH', '/settings', { json: { mcp_enabled: true } });
    check('MCP eingeschaltet (mcp_enabled)', en.status === 200, `HTTP ${en.status}`);
    const k = await api(ET, 'POST', '/auth/keys', { json: { name: 'Farm KI', scope: 'mcp' } });
    const key = String(k.d?.key ?? '');
    check('Schlüssel mit Bereich mcp angelegt', k.status === 201 && key !== '', `HTTP ${k.status}`);
    const rpc = (msg, schluessel = key) => http('POST', `${ET}/api/mcp`, {
        headers: { Accept: 'application/json, text/event-stream', Authorization: `Bearer ${schluessel}` },
        json: { jsonrpc: '2.0', ...msg },
    });

    const init = await rpc({ id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {},
        clientInfo: { name: 'et-farm', version: VERSION } } });
    check('initialize', init.status === 200 && typeof init.data?.result?.protocolVersion === 'string',
        `HTTP ${init.status}, Protokoll ${init.data?.result?.protocolVersion}`);
    const note = await rpc({ method: 'notifications/initialized' });
    check('notifications/initialized: 202', note.status === 202, `HTTP ${note.status}`);
    const tl = await rpc({ id: 2, method: 'tools/list' });
    const tools = (tl.data?.result?.tools ?? []).map((x) => x.name);
    check('tools/list nennt get_overview', tl.status === 200 && tools.includes('get_overview'), `${tools.length} Werkzeuge`);

    const summary = (await api(ET, 'GET', '/summary')).d;
    const call = await rpc({ id: 3, method: 'tools/call', params: { name: 'get_overview', arguments: {} } });
    const ov = call.data?.result?.structuredContent;
    check('tools/call get_overview ohne Fehler', call.status === 200 && call.data?.result?.isError === false && isObj(ov),
        `HTTP ${call.status}`);
    // Gleiche Zahl wie die Oberfläche (tests/unit/Http/McpReadEndpointTest.php)
    const f = (x) => (x === null || x === undefined ? null : Number(x));
    const v = (n) => (n === null || n === undefined ? null : Number(n.value));
    const sm = summary?.meters ?? []; const om = ov?.meters ?? [];
    check('get_overview: dieselben Zähler wie GET /api/summary',
        JSON.stringify(sm.map((m) => m.meter_id)) === JSON.stringify(om.map((m) => m.id)), `${sm.length} gegen ${om.length}`);
    const abweichend = [];
    sm.forEach((s, i) => {
        const m = om[i] ?? {};
        const a = [f(s.last_counter), f(s.year_to_date?.value), f(s.contract?.balance), f(s.contract?.projected_end_balance)];
        const b = [v(m.last_reading?.counter), v(m.year_to_date), v(m.contract?.status?.balance_today), v(m.contract?.status?.expected_balance)];
        if (JSON.stringify(a) !== JSON.stringify(b)) abweichend.push(s.key);
    });
    check('get_overview: Stand, Verbrauch seit Jahresbeginn, Saldo und erwarteter Saldo wie die Oberfläche',
        sm.length > 0 && abweichend.length === 0, abweichend.slice(0, 4).join(', '));
    const alles = init.text + tl.text + call.text;
    check('kein Schlüssel in den Antworten', !alles.includes(key) && !alles.includes('etk_'));
    const rk = await api(ET, 'POST', '/auth/keys', { json: { name: 'Farm lesen', scope: 'read' } });
    const fremd = await rpc({ id: 4, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {},
        clientInfo: { name: 'et-farm', version: VERSION } } }, String(rk.d?.key ?? ''));
    check('Lese-Schlüssel an /api/mcp abgewiesen (403, Bereich mcp verlangt)', fremd.status === 403, `HTTP ${fremd.status}`);
}

// ── 5. evcc im Demo-Modus ───────────────────────────────────────────────────
async function evcc() {
    const st = await warten('evcc', 60, async () => {
        const r = await http('GET', `${EVCC}/api/state`);
        return r.status === 200 ? r : null;
    });
    const state = isObj(st.data?.result) ? st.data.result : st.data;
    check('evcc erreichbar, API bereit (GET /api/state)', isObj(state) && state.apiReady !== false, `evcc ${state?.version ?? '?'}`);
    const ses = await http('GET', `${EVCC}/api/sessions`);
    const list = Array.isArray(ses.data) ? ses.data : (Array.isArray(ses.data?.result) ? ses.data.result : null);
    check('evcc: GET /api/sessions liefert eine Liste (den Endpunkt liest der Energietracker)', ses.status === 200 && list !== null,
        `${list?.length ?? '?'} Ladevorgänge`);
    const p = await api(ET, 'PATCH', '/settings', { json: { evcc_endpoint: EVCC_INTERN } });
    check(`Energietracker: evcc-Adresse ${EVCC_INTERN} gespeichert`, p.status === 200, `HTTP ${p.status}`);
    const strom = (stand.summary?.meters ?? []).find((m) => m.utility === 'strom' && m.meter_id);
    if (!check('Stromzähler für den Abruf vorhanden', strom)) return;
    const sync = await api(ET, 'POST', `/utility/strom/meters/${encodeURIComponent(strom.meter_id)}/sync-evcc?dry_run=1`, { json: {} });
    if (list && list.length > 0) {
        check('Abruf über den Energietracker (Probelauf, nichts gespeichert)', sync.status === 200 && Number(sync.d?.sessions) > 0,
            `HTTP ${sync.status}, ${sync.d?.sessions} Vorgänge`);
    } else {
        // Die Demo von evcc hat keine abgeschlossenen Ladevorgänge: geprüft ist der Weg bis zur
        // Auswertung (Heimnetz-Prüfung, Abruf, Antwort als Liste gelesen), nicht die Übernahme.
        check('Abruf über den Energietracker erreicht evcc und liest die Antwort', sync.data?.code === 'errors.evcc.noSessions',
            `HTTP ${sync.status}, ${sync.data?.code ?? '?'}; Demo ohne Ladevorgänge, Übernahme nicht geprüft`);
    }
}

// ── 6. Variante ohne root ───────────────────────────────────────────────────
async function ohneRoot() {
    const h = await warten('Energietracker ohne root', 60, async () => {
        const r = await api(ET_ROOTLESS, 'GET', '/health');
        return r.status === 200 ? r : null;
    });
    check(`health 200 und Version ${VERSION}`, h.d?.version === VERSION, `Version ${h.d?.version}`);
    check('Datenverzeichnis beschreibbar (nur lesbares Dateisystem, /data als Volume)', h.d?.data_dir_writable === true);
    const uid = compose('exec', '-T', 'energietracker-rootless', 'id', '-u').trim();
    check('läuft als UID 10001', uid === '10001', `UID ${uid}`);
    const uids = compose('exec', '-T', 'energietracker-rootless', 'sh', '-c',
        'for f in /proc/[0-9]*/status; do grep "^Uid:" "$f" 2>/dev/null; done').trim().split('\n')
        .flatMap((l) => l.split(/\s+/).slice(1));
    check('kein Prozess mit UID 0', uids.length > 0 && !uids.includes('0'), `${uids.length / 4} Prozesse`);
    const imp = await api(ET_ROOTLESS, 'POST', '/demo/import', { json: { force: true, persona: 'mieterin' }, timeout: 120000 });
    check('schreibt ins Volume (Beispielhaushalt „mieterin“)', imp.status === 200 && imp.d?.persona === 'mieterin', `HTTP ${imp.status}`);
    const g = await warten('HEALTHCHECK', 90, async () => (gesundheit('energietracker-rootless') === 'healthy' ? 'healthy' : null))
        .catch(() => gesundheit('energietracker-rootless'));
    check('Container meldet healthy', g === 'healthy', g);
}

// ── 7. Home Assistant mit der Integration ───────────────────────────────────
/** Was die Integration aus der Übersicht macht (logic.py: all_meters, meter_sensor_keys, meter_value). */
function erwartung(summary) {
    const meters = (summary?.meters ?? []).filter((m) => isObj(m) && m.key);
    const gruppen = []; const seen = new Set();
    for (const m of summary?.meters ?? []) {
        const gc = isObj(m) ? m.group_contract : null;
        if (!isObj(gc) || !gc.group_id || !isObj(gc.contract)) continue;
        const key = `${m.utility}.group.${gc.group_id}`;
        if (seen.has(key)) continue;
        seen.add(key);
        gruppen.push({ key, contract: gc.contract, group: true });
    }
    const werte = new Map();
    for (const m of [...meters, ...gruppen]) {
        const keys = m.group ? ['balance'] : ['balance', 'year_to_date', 'forecast_12m', 'days_since_reading']
            .filter((k) => !(k === 'balance' && m.contract == null) && !(k === 'forecast_12m' && m.tank != null));
        for (const k of keys) {
            const wert = k === 'balance' ? m.contract?.balance
                : k === 'days_since_reading' ? m.days_since_reading : m[k]?.value;
            werte.set(`${m.key}_${k}`, wert ?? null);
        }
    }
    return { geraete: 1 + meters.length + gruppen.length, sensoren: 5 + werte.size, werte };
}

function haWebsocket(base, token, typen) {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(`${base.replace(/^http/, 'ws')}/api/websocket`);
        const out = {};
        const timer = setTimeout(() => { ws.close(); reject(new Error('Websocket: keine Antwort in 30 s')); }, 30000);
        ws.onmessage = (ev) => {
            const m = JSON.parse(String(ev.data));
            if (m.type === 'auth_required') ws.send(JSON.stringify({ type: 'auth', access_token: token }));
            else if (m.type === 'auth_ok') typen.forEach((type, i) => ws.send(JSON.stringify({ id: i + 1, type })));
            else if (m.type === 'auth_invalid') { clearTimeout(timer); ws.close(); reject(new Error('Websocket: Anmeldung abgelehnt')); }
            else if (m.type === 'result') {
                out[typen[m.id - 1]] = m.success ? m.result : null;
                if (Object.keys(out).length === typen.length) { clearTimeout(timer); ws.close(); resolve(out); }
            }
        };
        ws.onerror = () => { clearTimeout(timer); reject(new Error('Websocket-Fehler')); };
    });
}

/** Home Assistant ohne Browser einrichten, Integration anlegen, Geräte und Sensoren zählen. */
async function homeAssistant(name, dienst, base, { schluessel = null } = {}) {
    const schritte = await warten(name, 300, async () => {
        const r = await http('GET', `${base}/api/onboarding`);
        return r.status === 200 && Array.isArray(r.data) ? r.data : null;
    });
    const clientId = `${base}/`;
    let code;
    if (!schritte.find((s) => s.step === 'user')?.done) {
        // Erster Lauf: Einrichtung ohne Browser
        const u = await http('POST', `${base}/api/onboarding/users`, { json: { client_id: clientId, ...HA_BENUTZER } });
        if (!check(`${name}: Benutzer angelegt (Einrichtung per API)`, u.status === 200 && u.data?.auth_code, `HTTP ${u.status}`)) return;
        code = u.data.auth_code;
    } else {
        // Zweiter Lauf von `pruefen` gegen dieselbe Farm: anmelden statt einrichten
        const lf = await http('POST', `${base}/auth/login_flow`,
            { json: { client_id: clientId, handler: ['homeassistant', null], redirect_uri: clientId } });
        const r = await http('POST', `${base}/auth/login_flow/${lf.data?.flow_id}`,
            { json: { username: HA_BENUTZER.username, password: HA_BENUTZER.password, client_id: clientId } });
        if (!check(`${name}: angemeldet (Farm schon eingerichtet)`, r.data?.type === 'create_entry' && r.data?.result, `HTTP ${r.status}`)) return;
        code = r.data.result;
    }
    const tok = await http('POST', `${base}/auth/token`,
        { form: { grant_type: 'authorization_code', code, client_id: clientId } });
    const access = tok.data?.access_token;
    if (!check(`${name}: Zugangstoken`, tok.status === 200 && access, `HTTP ${tok.status}`)) return;
    const auth = { Authorization: `Bearer ${access}` };
    for (const s of schritte.filter((x) => !x.done && x.step !== 'user')) {
        const body = s.step === 'integration' ? { client_id: clientId, redirect_uri: `${base}/?auth_callback=1` } : {};
        await http('POST', `${base}/api/onboarding/${s.step}`, { headers: auth, json: body });
    }
    const cfg = await warten(`${name} läuft`, 240, async () => {
        const r = await http('GET', `${base}/api/config`, { headers: auth });
        return r.status === 200 && r.data?.state === 'RUNNING' ? r.data : null;
    });
    check(`${name}: Home Assistant ${cfg.version} läuft`, true);
    // Eintrag aus einem früheren Lauf entfernen, sonst bricht der Ablauf mit already_configured ab
    const alt = await http('GET', `${base}/api/config/config_entries/entry?domain=energietracker`, { headers: auth });
    for (const e of (Array.isArray(alt.data) ? alt.data : []).filter((x) => x.domain === 'energietracker')) {
        await http('DELETE', `${base}/api/config/config_entries/entry/${e.entry_id}`, { headers: auth });
    }

    const flow = await http('POST', `${base}/api/config/config_entries/flow`,
        { headers: auth, json: { handler: 'energietracker', show_advanced_options: false } });
    if (!check(`${name}: Konfigurationsablauf energietracker startet (Integration geladen)`, flow.status === 200 && flow.data?.type === 'form',
        `HTTP ${flow.status}, ${flow.data?.message ?? flow.data?.type ?? ''}`)) {
        for (const l of logZeilen(dienst, /energietracker/i)) console.log(`      ${l}`);
        return;
    }
    const flowUrl = `${base}/api/config/config_entries/flow/${flow.data.flow_id}`;
    let res;
    if (schluessel) {
        // Anmeldung an: ohne Schlüssel verlangt der Ablauf einen (invalid_auth am Feld token)
        res = await http('POST', flowUrl, { headers: auth, json: { url: ET_INTERN, token: '', verify_ssl: true } });
        check(`${name}: ohne Lese-Schlüssel abgewiesen (invalid_auth)`, res.data?.type === 'form' && res.data?.errors?.token === 'invalid_auth',
            JSON.stringify(res.data?.errors ?? {}));
    }
    res = await http('POST', flowUrl, { headers: auth, json: { url: ET_INTERN, token: schluessel ?? '', verify_ssl: true } });
    const entry = res.data?.result?.entry_id;
    if (!check(`${name}: Eintrag angelegt (${ET_INTERN}${schluessel ? ', mit Lese-Schlüssel' : ', ohne Anmeldung'})`,
        res.data?.type === 'create_entry' && entry, `${res.data?.type ?? res.status} ${JSON.stringify(res.data?.errors ?? '')}`)) {
        for (const l of logZeilen(dienst, /energietracker/i)) console.log(`      ${l}`);
        return;
    }

    const summary = (await api(ET, 'GET', '/summary', { headers: schluessel ? { Authorization: `Bearer ${schluessel}` } : {} })).d;
    const todo = (await api(ET, 'GET', '/todo', { headers: schluessel ? { Authorization: `Bearer ${schluessel}` } : {} })).d;
    const soll = erwartung(summary);
    let reg;
    try {
        reg = await warten(`${name}: Sensoren`, 90, async () => {
            const r = await haWebsocket(base, access, ['config/entity_registry/list', 'config/device_registry/list']);
            const sensoren = (r['config/entity_registry/list'] ?? []).filter((x) => x.platform === 'energietracker' && x.config_entry_id === entry);
            return sensoren.length >= soll.sensoren ? { ...r, sensoren } : null;
        });
    } catch {
        const r = await haWebsocket(base, access, ['config/entity_registry/list', 'config/device_registry/list']);
        reg = { ...r, sensoren: (r['config/entity_registry/list'] ?? []).filter((x) => x.platform === 'energietracker' && x.config_entry_id === entry) };
    }
    const geraete = (reg['config/device_registry/list'] ?? []).filter((d) => (d.config_entries ?? []).includes(entry));
    check(`${name}: ${soll.geraete} Geräte wie aus /api/summary erwartet`, geraete.length === soll.geraete, `${geraete.length} gefunden`);
    check(`${name}: ${soll.sensoren} Sensoren wie aus /api/summary erwartet`, reg.sensoren.length === soll.sensoren, `${reg.sensoren.length} gefunden`);
    const anlage = geraete.find((d) => d.name === 'Energietracker' && (d.identifiers ?? []).some(([dom, id]) => dom === 'energietracker' && id === summary?.instance_id));
    check(`${name}: Gerät „Energietracker“ mit Version ${VERSION}`, anlage?.sw_version === VERSION, String(anlage?.sw_version ?? 'fehlt'));

    // Werte: jeder Sensor zeigt die Zahl der API, leer („unknown“) nur, wo die API null liefert
    const states = await http('GET', `${base}/api/states`, { headers: auth });
    const zustand = new Map((Array.isArray(states.data) ? states.data : []).map((s) => [s.entity_id, s.state]));
    const prefix = `${summary?.instance_id}_`;
    const falsch = []; let leer = 0;
    for (const e of reg.sensoren) {
        const uid = String(e.unique_id ?? '');
        const sw = uid.startsWith(prefix) ? uid.slice(prefix.length) : uid;
        const ist = zustand.get(e.entity_id);
        if (sw === 'todo_count') {
            if (Number(ist) !== Number(todo?.count)) falsch.push(`${sw}=${ist}`);
            continue;
        }
        if (!soll.werte.has(sw)) continue;   // die übrigen Sensoren von „Zu tun“
        const wert = soll.werte.get(sw);
        if (wert === null) {
            leer++;
            if (ist !== 'unknown') falsch.push(`${sw}=${ist} statt leer`);
        } else if (!(Math.abs(Number(ist) - Number(wert)) <= 1e-6 * Math.max(1, Math.abs(Number(wert))))) {
            falsch.push(`${sw}=${ist} statt ${wert}`);
        }
    }
    check(`${name}: Werte wie /api/summary und /api/todo, leer nur bei null`, falsch.length === 0,
        falsch.length ? falsch.slice(0, 4).join(', ') : `${leer} leer`);
    const fehlerLog = logZeilen(dienst, /(ERROR|Traceback).*energietracker|custom_components\/energietracker/i, 4);
    check(`${name}: keine Fehler der Integration im Protokoll`, fehlerLog.length === 0, fehlerLog.join(' | ').slice(0, 300));
}

// ── 8. Anmeldung an und aus ─────────────────────────────────────────────────
async function anmeldung() {
    const on = await api(ET, 'POST', '/session/password', { json: { password: PASSWORT } });
    const cookie = (on.headers.getSetCookie?.() ?? []).map((c) => c.split(';')[0]).find((c) => c.startsWith('et_session=')) ?? '';
    check('Anmeldung eingeschaltet (Passwort, Sitzungs-Cookie)', on.status === 200 && on.d?.mode === 'password' && cookie !== '',
        `HTTP ${on.status}`);
    const zu = await api(ET, 'GET', '/utilities');
    check('ohne Anmeldung: 401 errors.auth.required', zu.status === 401 && zu.data?.code === 'errors.auth.required', `HTTP ${zu.status}`);
    const mit = await api(ET, 'GET', '/utilities', { headers: { Cookie: cookie } });
    check('mit Sitzungs-Cookie: 200', mit.status === 200, `HTTP ${mit.status}`);
    const hmin = await api(ET, 'GET', '/health');
    check('health ohne Anmeldung nur mit Status und Version', hmin.status === 200 && Object.keys(hmin.d ?? {}).sort().join(',') === 'status,version');
    const rk = await api(ET, 'POST', '/auth/keys', { headers: { Cookie: cookie }, json: { name: 'Farm Home Assistant', scope: 'read' } });
    const key = String(rk.d?.key ?? '');
    check('Lese-Schlüssel angelegt', rk.status === 201 && key !== '', `HTTP ${rk.status}`);
    const bearer = { Authorization: `Bearer ${key}` };
    const w = await api(ET, 'PATCH', '/settings', { headers: bearer, json: { language: 'de' } });
    check('Lese-Schlüssel schreibt nicht (403 errors.auth.readOnlyKey)', w.status === 403 && w.data?.code === 'errors.auth.readOnlyKey', `HTTP ${w.status}`);
    return { cookie, key };
}
async function abmelden(cookie) {
    const off = await api(ET, 'DELETE', '/session/password', { headers: { Cookie: cookie }, json: { current: PASSWORT } });
    check('Anmeldung ausgeschaltet (mit dem Passwort)', off.status === 200, `HTTP ${off.status}`);
    const offen = await api(ET, 'GET', '/utilities');
    check('wieder offen ohne Anmeldung', offen.status === 200, `HTTP ${offen.status}`);
}

// ── Ablauf ──────────────────────────────────────────────────────────────────
const start = Date.now();
let integration = '?';
try { integration = JSON.parse(readFileSync(join(INTEGRATION, 'custom_components', 'energietracker', 'manifest.json'), 'utf8')).version; } catch { /* unbekannt */ }
console.log(`Test-Farm: Energietracker ${VERSION} aus dem Arbeitsstand, Integration ${integration}, Home Assistant ${E.FARM_HA_NEU || 'stable'} und ${E.FARM_HA_ALT || '?'}`);

await abschnitt('Energietracker: Gesundheit und Version', gesundheitUndVersion);
await abschnitt('Beispielhaushalt', beispielhaushalt);
await abschnitt('Lesende Probe ohne Anmeldung', () => lesendeProbe(ET));
await abschnitt('MCP (POST /api/mcp)', mcp);
await abschnitt('evcc (Demo-Modus)', evcc);
await abschnitt('Energietracker ohne root', ohneRoot);
await abschnitt(`Home Assistant alt (${E.FARM_HA_ALT || '?'}), Anmeldung aus`, () => homeAssistant('HA alt', 'ha-alt', HA_ALT));
let sitzung = null;
await abschnitt('Anmeldung an', async () => { sitzung = await anmeldung(); });
if (sitzung?.key) {
    await abschnitt('Lesende Probe mit Lese-Schlüssel', () => lesendeProbe(ET, { Authorization: `Bearer ${sitzung.key}` }, { mitDateien: false }));
    await abschnitt(`Home Assistant neu (${E.FARM_HA_NEU || 'stable'}), Anmeldung an`,
        () => homeAssistant('HA neu', 'ha-neu', HA_NEU, { schluessel: sitzung.key }));
}
if (sitzung?.cookie) await abschnitt('Anmeldung aus', () => abmelden(sitzung.cookie));

const dauer = Math.round((Date.now() - start) / 1000);
console.log(`\n${fehler.length === 0 ? 'GRÜN' : 'ROT'}: ${ok} ok, ${fehler.length} Fehler, Prüfungen ${dauer} s`);
for (const f of fehler) console.log(`  ✗ ${f}`);
process.exit(fehler.length === 0 ? 0 : 1);
