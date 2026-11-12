#!/usr/bin/env node
// Prüfungen der lokalen Test-Farm (tools/farm/README.md). Aufruf über
// `bash tools/farm/farm.sh pruefen`, nachdem `farm.sh up` die Farm gestartet hat.
//
// Nur Beispieldaten (Beispielhaushalt „showcase“), nur Adressen auf 127.0.0.1.
// Node 22 oder neuer: fetch und WebSocket sind eingebaut, keine Pakete.
// Ausgabe: ein Bericht mit ✓/✗ je Prüfung; Exit-Code 1, sobald eine fehlschlägt.

import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { request as httpsRequest } from 'node:https';
import { randomBytes } from 'node:crypto';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { erwartung } from './lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const E = process.env;
const local = (name, fallback) => `http://127.0.0.1:${E[name] || fallback}`;
const ET = local('FARM_PORT_ET', '19840');
const ET_ROOTLESS = local('FARM_PORT_ET_ROOTLESS', '19841');
const HA_NEU = local('FARM_PORT_HA_NEU', '19842');
const HA_ALT = local('FARM_PORT_HA_ALT', '19843');
const EVCC = local('FARM_PORT_EVCC', '19844');
const APACHE84 = local('FARM_PORT_APACHE84', '19846');
const APACHE85 = local('FARM_PORT_APACHE85', '19847');
const CADDY_PORT = Number(E.FARM_PORT_CADDY || 19848);
const ET_PROXY = local('FARM_PORT_ET_PROXY', '19849');
const ET_UPDATE = local('FARM_PORT_UPDATE', '19850');
const UPDATE_VON = E.FARM_UPDATE_VON || '';
const IMAGE = E.FARM_IMAGE || 'energietracker:farm';
// Adressen im Netz der Farm: die Dienstnamen aus compose.yml
const ET_INTERN = 'http://energietracker';
const EVCC_INTERN = 'http://evcc:7070';
const HA_NEU_INTERN = 'http://ha-neu:8123';
const PROJECT = E.FARM_PROJECT || 'et-farm';
const COMPOSE_FILE = E.FARM_COMPOSE_FILE || join(ROOT, 'tools', 'farm', 'compose.yml');
const INTEGRATION = E.FARM_INTEGRATION || join(E.HOME || '', 'Code', 'energietracker-hacs');
const PROXY_PASSWORT = 'farm-proxy-123';   // Caddyfile in compose.yml

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
/** Beobachtung ohne Urteil (zählt nicht als Prüfung), etwa eine Grenze der Doku. */
function hinweis(text) { console.log(`  · ${text}`); }
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
    return composeMit({}, ...args);
}
/** docker compose mit zusätzlichen Variablen (etwa ein anderes Image für denselben Dienst). */
function composeMit(env, ...args) {
    return execFileSync('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, ...args],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], env: { ...E, ...env } });
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
/** Zugangstoken je Home Assistant (aus homeAssistant(), für die Vorlagen). */
const haZugang = {};

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
async function lesendeProbe(base, headers = {}, { mitDateien = true, apiBase = base } = {}) {
    const mitSchluessel = 'Authorization' in headers;
    // apiBase: auf Apache ohne Umschreiben `<base>/api.php` (die Oberfläche ruft api.php/api/… auf)
    const get = (path) => api(apiBase, 'GET', path, { headers });

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

// ── 3a. Eigener Webserver: Apache mit .htaccess (wie Synology Web Station) ──
/** Ein Zähler je Art mit Stand, für den Push. */
function zaehlerMitStand(summary, utility) {
    return (summary?.meters ?? []).find((m) => m.utility === utility && m.meter_id && m.last_counter !== null && m.last_counter !== undefined);
}
async function webserverApache(name, base, php) {
    const apiB = `${base}/api.php`;
    const h = await warten(name, 60, async () => {
        const r = await api(apiB, 'GET', '/health');
        return r.status === 200 ? r : null;
    });
    check(`health über api.php, Version ${VERSION}`, h.d?.version === VERSION && ['ok', 'degraded'].includes(h.d?.status),
        `Version ${h.d?.version}, Status ${h.d?.status}`);
    check('data/ im Webroot beschreibbar (www-data, INSTALL.md)', h.d?.data_dir_writable === true);
    const dg = await api(apiB, 'GET', '/diagnostics');
    check(`PHP ${php} (mod_php aus php:${php}-apache)`, String(dg.d?.php_version ?? '').startsWith(`${php}.`), String(dg.d?.php_version));
    const s0 = await api(apiB, 'GET', '/summary');
    const arten0 = new Set((s0.d?.meters ?? []).map((m) => m.utility));
    check('Erststart: Standardzähler Strom, Gas, Wasser', ['strom', 'gas', 'wasser'].every((u) => arten0.has(u)), [...arten0].join(', '));
    // Doku (home-assistant.md, Schritt 3): „Wenn dein Webserver eine Rewrite-Regel hat
    // (Apache .htaccess / nginx), geht auch …/api/ingest.“ Die .htaccess leitet /api/ nicht um.
    const ohne = await http('GET', `${base}/api/health`);
    hinweis(`ohne api.php: GET /api/health → HTTP ${ohne.status} (die mitgelieferte .htaccess schreibt /api/ nicht auf api.php um)`);

    const imp = await api(apiB, 'POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 120000 });
    check('Beispielhaushalt „showcase“ geladen', imp.status === 200 && imp.d?.persona === 'showcase', `HTTP ${imp.status}`);

    // JS-Module: MIME-Typ, Revalidieren und nosniff (.htaccess, mod_headers)
    const js = await http('GET', `${base}/public/js/app.js`);
    const ct = js.headers.get('content-type') ?? '';
    check('JS-Module mit MIME-Typ JavaScript', js.status === 200 && /javascript/i.test(ct), `HTTP ${js.status}, ${ct}`);
    check('JS: Cache-Control no-cache, must-revalidate und nosniff (.htaccess)',
        /no-cache/.test(js.headers.get('cache-control') ?? '') && js.headers.get('x-content-type-options') === 'nosniff',
        `${js.headers.get('cache-control')}, ${js.headers.get('x-content-type-options')}`);
    const map = /<script type="importmap"[^>]*>([\s\S]*?)<\/script>/.exec((await http('GET', `${base}/`)).text);
    let modul = null;
    try { modul = Object.values(JSON.parse(map?.[1] ?? '{}').imports ?? {})[0] ?? null; } catch { /* keine Import-Map */ }
    const mx = modul ? await http('GET', new URL(modul, `${base}/`).href) : null;
    check('Modul aus der Import-Map (versionierter Pfad) als JavaScript', mx?.status === 200 && /javascript/i.test(mx.headers.get('content-type') ?? ''),
        `${modul ?? 'keine Import-Map'} → HTTP ${mx?.status}`);

    // Authorization-Kopfzeile kommt bei PHP an (SetEnvIf in der .htaccess), sonst scheitert der Push mit 401
    const tok = await api(apiB, 'POST', '/auth/token', { json: {} });
    const token = String(tok.d?.token ?? '');
    const s = (await api(apiB, 'GET', '/summary')).d;
    const strom = zaehlerMitStand(s, 'strom');
    const wert = Number(strom?.last_counter ?? 0) + 1.5;
    const push = (hdr) => api(apiB, 'POST', '/ingest', { headers: hdr, json: { utility: 'strom', meter: strom?.meter_id, value: wert } });
    const ohneTok = await push({});
    const mitTok = await push({ Authorization: `Bearer ${token}` });
    check('Push nach api.php/api/ingest: ohne Token 401, mit Token angenommen', token !== '' && ohneTok.status === 401 && [200, 201].includes(mitTok.status),
        `ohne ${ohneTok.status}, mit ${mitTok.status} ${mitTok.data?.code ?? ''}`);

    // MCP mit api.php im Pfad (ki-assistent.md, Schritt 3)
    await api(apiB, 'PATCH', '/settings', { json: { mcp_enabled: true } });
    const k = await api(apiB, 'POST', '/auth/keys', { json: { name: 'Farm KI', scope: 'mcp' } });
    const init = await http('POST', `${apiB}/api/mcp`, {
        headers: { Accept: 'application/json, text/event-stream', Authorization: `Bearer ${k.d?.key ?? ''}` },
        json: { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'et-farm', version: VERSION } } },
    });
    check('MCP über api.php/api/mcp: initialize', init.status === 200 && typeof init.data?.result?.protocolVersion === 'string', `HTTP ${init.status}`);

    // Lesende Probe samt gesperrter Pfade (data/ mit echter settings.json, .git/HEAD liegt im Webroot)
    await lesendeProbe(base, {}, { apiBase: apiB });
}

// ── 3b. Reverse-Proxy mit HTTPS und Proxy-Anmeldung (Caddy) ─────────────────
function https(method, path, { headers = {}, ca, json } = {}) {
    return new Promise((resolve, reject) => {
        const body = json !== undefined ? JSON.stringify(json) : undefined;
        const req = httpsRequest({
            host: '127.0.0.1', port: CADDY_PORT, servername: 'localhost', ca, method, path, timeout: 30000,
            headers: { Host: 'localhost', Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers },
        }, (res) => {
            const authorized = res.socket?.authorized === true;
            let text = '';
            res.setEncoding('utf8');
            res.on('data', (c) => { text += c; });
            res.on('end', () => {
                let data = null;
                try { data = JSON.parse(text); } catch { /* keine JSON-Antwort */ }
                const d = data && typeof data === 'object' && 'data' in data ? data.data : data;
                resolve({ status: res.statusCode, headers: res.headers, text, data, d, authorized });
            });
        });
        req.on('timeout', () => req.destroy(new Error('Zeitüberschreitung')));
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}
const basic = (name) => ({ Authorization: `Basic ${Buffer.from(`${name}:${PROXY_PASSWORT}`).toString('base64')}` });
const nameVon = (u) => (u && typeof u === 'object' ? u.name : u) ?? null;

async function reverseProxy() {
    // Stammzertifikat der eigenen Stelle von Caddy (tls internal); damit prüft Node das Zertifikat wirklich
    const ca = await warten('Stammzertifikat von Caddy', 60, async () => {
        const pem = compose('exec', '-T', 'caddy', 'cat', '/data/caddy/pki/authorities/local/root.crt');
        return pem.includes('BEGIN CERTIFICATE') ? pem : null;
    });
    const zu = await warten('Caddy', 60, async () => https('GET', '/api/session', { ca }));
    check('HTTPS über Caddy, Zertifikat gegen die eigene Stelle geprüft', zu.authorized, `HTTP ${zu.status}`);
    check('ohne Name und Passwort: 401 vom Proxy', zu.status === 401 && /basic/i.test(String(zu.headers['www-authenticate'] ?? '')), `HTTP ${zu.status}`);

    const farm = await https('GET', '/api/session', { ca, headers: basic('farm') });
    check('angemeldet über den Proxy: Modus proxy, Person „farm“', farm.status === 200 && farm.d?.mode === 'proxy'
        && farm.d?.authenticated === true && nameVon(farm.d?.user) === 'farm', `HTTP ${farm.status}, ${nameVon(farm.d?.user)}`);
    check('erste Person verwaltet (Rolle admin)', farm.d?.role === 'admin', String(farm.d?.role));
    const gefaelscht = await https('GET', '/api/session', { ca, headers: { ...basic('farm'), 'X-Forwarded-User': 'mitbewohner' } });
    check('eigene X-Forwarded-User vom Gerät überschreibt der Proxy', nameVon(gefaelscht.d?.user) === 'farm', String(nameVon(gefaelscht.d?.user)));
    const mit = await https('GET', '/api/session', { ca, headers: basic('mitbewohner') });
    check('zweite Person ist Mitglied (Rolle member)', nameVon(mit.d?.user) === 'mitbewohner' && mit.d?.role === 'member',
        `${nameVon(mit.d?.user)}, ${mit.d?.role}`);
    const keys = await https('GET', '/api/auth/keys', { ca, headers: basic('mitbewohner') });
    check('Mitglied erreicht die Schlüssel nicht (403 errors.auth.adminOnly)', keys.status === 403 && keys.data?.code === 'errors.auth.adminOnly',
        `HTTP ${keys.status}`);
    const dg = await https('GET', '/api/diagnostics', { ca, headers: basic('farm') });
    const ph = dg.d?.security?.proxy_header ?? {};
    check('Sicherheits-Check: Kopfzeile fest eingestellt (ET_PROXY_USER_HEADER), keine Warnung',
        ph.mode === 'configured' && ph.header === 'X-Forwarded-User'
        && !(dg.d?.security?.notices ?? []).some((n) => n?.code === 'proxy_header'), `${ph.mode}, ${ph.header}`);

    // Dieselbe Kopfzeile direkt am Container: gilt nicht (nicht von ET_TRUSTED_PROXIES)
    const direkt = await api(ET_PROXY, 'GET', '/utilities', { headers: { 'X-Forwarded-User': 'farm' } });
    check('direkt am Container: X-Forwarded-User ignoriert (401 errors.auth.required)',
        direkt.status === 401 && direkt.data?.code === 'errors.auth.required', `HTTP ${direkt.status}`);
    const ds = await api(ET_PROXY, 'GET', '/session', { headers: { 'X-Forwarded-User': 'farm', 'X-Remote-User-Id': 'x', 'X-Ingress-Path': '/x' } });
    check('direkt am Container auch mit Ingress-Kopfzeilen nicht angemeldet', ds.d?.authenticated === false, String(ds.d?.authenticated));
}

// ── 3c. Update-Weg: veröffentlichte Version → Arbeitsstand auf demselben Volume ──
function containerImage(dienst) {
    const id = compose('ps', '-q', dienst).trim();
    return id === '' ? '' : execFileSync('docker', ['inspect', '--format', '{{.Image}}', id], { encoding: 'utf8' }).trim();
}
async function updateWeg() {
    const altImage = E.FARM_IMAGE_UPDATE || `ghcr.io/bingerminger/energietracker:${UPDATE_VON}`;
    // Jeder Lauf fängt frisch an: Dienst und Volume neu, mit der alten Version
    composeMit({ FARM_IMAGE_UPDATE: altImage }, 'rm', '-sf', 'et-update');
    try { execFileSync('docker', ['volume', 'rm', '-f', `${PROJECT}_et-daten-update`], { stdio: 'ignore' }); } catch { /* fehlt schon */ }
    composeMit({ FARM_IMAGE_UPDATE: altImage }, 'up', '-d', '--no-deps', '--quiet-pull', 'et-update');
    const gesund = async (wer) => warten(wer, 90, async () => {
        const r = await api(ET_UPDATE, 'GET', '/health');
        return r.status === 200 ? r : null;
    });
    const alt = await gesund(`Energietracker ${UPDATE_VON}`);
    check(`veröffentlichte Version ${UPDATE_VON} läuft (${altImage})`, alt.d?.version === UPDATE_VON, String(alt.d?.version));
    const imp = await api(ET_UPDATE, 'POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 120000 });
    check(`Beispielhaushalt in ${UPDATE_VON} geladen`, imp.status === 200, `HTTP ${imp.status}`);
    const zaehlen = async () => (await api(ET_UPDATE, 'GET', '/diagnostics')).d?.utilities ?? null;
    const vorher = await zaehlen();
    const summe = (u, f) => Object.values(u ?? {}).reduce((a, x) => a + Number(x?.[f] ?? 0), 0);
    const kurz = (u) => `${summe(u, 'meters')} Zähler, ${summe(u, 'readings') + summe(u, 'deliveries')} Ablesungen/Lieferungen, ${summe(u, 'contracts')} Verträge`;
    const bk = await http('GET', `${ET_UPDATE}/api/backup/export`, { timeout: 120000 });
    const backup = bk.data?.data;
    check(`Backup aus ${UPDATE_VON} exportiert`, bk.status === 200 && isObj(backup), `HTTP ${bk.status}`);

    // Dasselbe Volume, Image aus dem Arbeitsstand (wie ein Image-Tag-Wechsel beim Nutzer)
    composeMit({ FARM_IMAGE_UPDATE: IMAGE }, 'up', '-d', '--no-deps', 'et-update');
    const neu = await gesund('Energietracker aus dem Arbeitsstand');
    let neuId = '';
    try { neuId = execFileSync('docker', ['image', 'inspect', '--format', '{{.Id}}', IMAGE], { encoding: 'utf8' }).trim(); } catch { /* fehlt */ }
    check(`derselbe Dienst läuft jetzt mit ${IMAGE}, Version ${VERSION}`, neuId !== '' && containerImage('et-update') === neuId && neu.d?.version === VERSION,
        `Version ${neu.d?.version}${UPDATE_VON === VERSION ? ', gleiche Nummer wie veröffentlicht' : ''}`);
    const nachher = await zaehlen();
    const h2 = (await api(ET_UPDATE, 'GET', '/health')).d || {};
    check(`Schema ${SCHEMA} nach dem Start, keine Migration offen, Status ok`, h2.schema_version === SCHEMA && Number(h2.migrations_pending) === 0
        && ['ok', 'degraded'].includes(h2.status), `Schema ${h2.schema_version}, ${h2.status}`);
    check('Daten vollständig: Zähler, Ablesungen, Lieferungen und Verträge je Art gleich gezählt',
        vorher !== null && JSON.stringify(vorher) === JSON.stringify(nachher), kurz(vorher));
    const g = await warten('HEALTHCHECK', 90, async () => (gesundheit('et-update') === 'healthy' ? 'healthy' : null))
        .catch(() => gesundheit('et-update'));
    check('Container meldet healthy', g === 'healthy', g);
    if (isObj(backup)) {
        const ein = await api(ET_UPDATE, 'POST', '/backup/import', { json: backup, timeout: 120000 });
        const wieder = await zaehlen();
        check(`Backup aus ${UPDATE_VON} lässt sich einspielen, danach gleich gezählt`, ein.status === 200 && JSON.stringify(wieder) === JSON.stringify(vorher),
            `HTTP ${ein.status} ${ein.data?.code ?? ''}`);
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
// erwartung(): was die Integration aus der Übersicht macht (lib.mjs, auch für die VM)

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
    // Nach einem Neustart mit fertiger Einrichtung meldet /api/onboarding 404 (dann: anmelden)
    const schritte = await warten(name, 300, async () => {
        const r = await http('GET', `${base}/api/onboarding`);
        if (r.status === 404 && (await http('GET', `${base}/manifest.json`)).status === 200) return [{ step: 'user', done: true }];
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
    haZugang[name] = access;
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

// ── 7b. Vorlagen aus der Doku in Home Assistant (ha-neu, Anmeldung an) ──────
// Die YAML-Blöcke kommen wörtlich aus docs/anleitungen/home-assistant.md. Ersetzt
// wird nur, was die Doku selbst zum Anpassen nennt: Adresse, Token, Schlüssel,
// Webhook-ID und der Zählerschlüssel aus den Einstellungen. Ein Fehler in einer
// Vorlage fällt so hier auf und nicht erst bei einem Nutzer.
const HA_DOKU = join(ROOT, 'docs', 'anleitungen', 'home-assistant.md');
function yamlNach(md, kopf, n = 0) {
    const i = md.indexOf(`\n${kopf}`);
    if (i < 0) throw new Error(`Doku: Abschnitt „${kopf}“ fehlt`);
    const re = /```yaml\n([\s\S]*?)```/g;
    re.lastIndex = i;
    let m = null;
    for (let k = 0; k <= n; k++) {
        m = re.exec(md);
        if (!m) throw new Error(`Doku: YAML-Block ${n + 1} nach „${kopf}“ fehlt`);
    }
    return m[1];
}
function ersetze(text, alt, neu, was) {
    if (!text.includes(alt)) throw new Error(`Doku: „${alt}“ (${was}) steht nicht mehr in der Vorlage`);
    return text.split(alt).join(neu);
}
/** Eine Automation aus der Doku als Eintrag in automations.yaml (wie „In YAML bearbeiten“ mit id). */
function alsAutomation(id, block) {
    return [`- id: ${id}`, ...block.trimEnd().split('\n').map((l) => (l === '' ? '' : `  ${l}`))].join('\n');
}
/** Websocket von Home Assistant: Nachrichten der Reihe nach, Ergebnisse als Liste. */
function haWs(base, token, nachrichten) {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(`${base.replace(/^http/, 'ws')}/api/websocket`);
        const out = [];
        const timer = setTimeout(() => { ws.close(); reject(new Error('Websocket: keine Antwort in 30 s')); }, 30000);
        ws.onmessage = (ev) => {
            const m = JSON.parse(String(ev.data));
            if (m.type === 'auth_required') ws.send(JSON.stringify({ type: 'auth', access_token: token }));
            else if (m.type === 'auth_ok') nachrichten.forEach((x, i) => ws.send(JSON.stringify({ id: i + 1, ...x })));
            else if (m.type === 'auth_invalid') { clearTimeout(timer); ws.close(); reject(new Error('Websocket: Anmeldung abgelehnt')); }
            else if (m.type === 'result') {
                out[m.id - 1] = m.success ? m.result : null;
                if (out.filter((x) => x !== undefined).length === nachrichten.length) { clearTimeout(timer); ws.close(); resolve(out); }
            }
        };
        ws.onerror = () => { clearTimeout(timer); reject(new Error('Websocket-Fehler')); };
    });
}
function composeEingabe(input, ...args) {
    return execFileSync('docker', ['compose', '-p', PROJECT, '-f', COMPOSE_FILE, ...args],
        { encoding: 'utf8', input, stdio: ['pipe', 'pipe', 'pipe'] });
}
const nahe = (a, b) => a !== null && b !== null && Math.abs(Number(a) - Number(b)) <= 1e-6 * Math.max(1, Math.abs(Number(b)));

async function haVorlagen({ cookie, key }) {
    const access = haZugang['HA neu'];
    if (!check('HA neu eingerichtet (Zugang aus dem vorigen Abschnitt)', access)) return;
    const auth = { Authorization: `Bearer ${access}` };
    const admin = { Cookie: cookie };
    const lesen = { Authorization: `Bearer ${key}` };
    const md = readFileSync(HA_DOKU, 'utf8');

    // Schritt 1 und 2: Token erzeugen, Aliase der Vorlage an Zähler des Beispielhaushalts
    const tok = await api(ET, 'POST', '/auth/token', { headers: admin, json: {} });
    const token = String(tok.d?.token ?? '');
    check('Schritt 1: Token erzeugt', tok.status === 201 && token !== '', `HTTP ${tok.status}`);
    const summary0 = (await api(ET, 'GET', '/summary', { headers: lesen })).d;
    const strom = (summary0?.meters ?? []).find((m) => m.utility === 'strom' && isObj(m.contract) && m.last_counter != null);
    const gas = zaehlerMitStand(summary0, 'gas');
    if (!check('Beispielhaushalt hat Strom mit Vertrag und Gas mit Stand', strom && gas)) return;
    for (const [u, m, alias] of [['strom', strom, 'stromzaehler_haus'], ['gas', gas, 'gaszaehler_haus']]) {
        const p = await api(ET, 'PATCH', `/utility/${u}/meters/${encodeURIComponent(m.meter_id)}`, { headers: admin, json: { external_id: alias } });
        check(`Schritt 2: Alias ${alias} an ${m.key}`, p.status === 200, `HTTP ${p.status} ${p.data?.code ?? ''}`);
    }

    // Vorlagen aus der Doku: Schritt 3 (rest_command + secrets), 4 (Automation), 5 (Sensoren + secrets), Webhook
    const webhookId = `farm_${randomBytes(12).toString('hex')}`;
    let rest3; let auth3; let auto4; let rest5; let read5; let hook;
    try {
        rest3 = ersetze(yamlNach(md, '## Schritt 3', 0), 'DEINE-ENERGIETRACKER-IP:8080', 'energietracker', 'Adresse');
        auth3 = ersetze(yamlNach(md, '## Schritt 3', 1), 'et_dein_kopierter_token', token, 'Token');
        auto4 = yamlNach(md, '## Schritt 4', 0);
        rest5 = ersetze(ersetze(yamlNach(md, '## Schritt 5', 0), 'DEINE-ENERGIETRACKER-IP:8080', 'energietracker', 'Adresse'),
            "'strom.m_strom_default'", `'${strom.key}'`, 'Zählerschlüssel in value_template und json_attributes_path');
        read5 = ersetze(yamlNach(md, '## Schritt 5', 1), 'etk_…', key, 'Lese-Schlüssel');
        hook = ersetze(yamlNach(md, '### Webhook', 0), 'energietracker_deine_eigene_id', webhookId, 'Webhook-ID');
    } catch (e) {
        check('Vorlagen aus der Doku gefunden', false, String(e.message));
        return;
    }
    check('Vorlagen aus der Doku gelesen (Schritt 3, 4, 5, Webhook)', true);
    const MARKE = '# ── Test-Farm: Vorlagen aus docs/anleitungen/home-assistant.md ──';
    const conf = compose('exec', '-T', 'ha-neu', 'cat', '/config/configuration.yaml');
    const basis = conf.includes(MARKE) ? conf.slice(0, conf.indexOf(MARKE)) : conf;
    composeEingabe(`${basis.trimEnd()}\n\n${MARKE}\n${rest3}\n${rest5}`, 'exec', '-T', 'ha-neu', 'sh', '-c', 'cat > /config/configuration.yaml');
    composeEingabe(`${auth3}${read5}`, 'exec', '-T', 'ha-neu', 'sh', '-c', 'cat > /config/secrets.yaml');
    composeEingabe(`${alsAutomation('farm_push_taeglich', auto4)}\n${alsAutomation('farm_webhook', hook)}\n`,
        'exec', '-T', 'ha-neu', 'sh', '-c', 'cat > /config/automations.yaml');
    const pruef = await http('POST', `${HA_NEU}/api/config/core/check_config`, { headers: auth, timeout: 120000 });
    check('Home Assistant: Konfiguration mit den Vorlagen gültig (check_config)', pruef.data?.result === 'valid',
        `${pruef.data?.result ?? pruef.status} ${String(pruef.data?.errors ?? '').slice(0, 200)}`);
    compose('restart', '--timeout', '30', 'ha-neu');
    await warten('HA neu nach dem Neustart', 240, async () => {
        const r = await http('GET', `${HA_NEU}/api/config`, { headers: auth });
        return r.status === 200 && r.data?.state === 'RUNNING' ? r.data : null;
    });
    const zustaende = async () => {
        const r = await http('GET', `${HA_NEU}/api/states`, { headers: auth });
        return Array.isArray(r.data) ? r.data : [];
    };
    const nachName = (liste, name) => liste.find((s) => s.attributes?.friendly_name === name);

    // Schritt 5: Sensoren aus GET /api/summary, Werte und Attribute wie die API (vor dem Push lesen)
    const summary = (await api(ET, 'GET', '/summary', { headers: lesen })).d;
    const m5 = (summary?.meters ?? []).find((m) => m.key === strom.key) ?? {};
    const soll5 = [['Strom – Saldo', m5.contract?.balance], ['Strom – Prognose 12 Monate', m5.forecast_12m?.value],
        ['Strom – Tage seit Ablesung', m5.days_since_reading]];
    const s5 = await warten('Sensoren aus Schritt 5', 90, async () => {
        const l = await zustaende();
        return soll5.every(([n]) => { const s = nachName(l, n); return s && s.state !== 'unknown' && s.state !== 'unavailable'; }) ? l : null;
    }).catch(() => zustaende());
    const falsch5 = soll5.filter(([n, w]) => !nahe(nachName(s5, n)?.state ?? null, w ?? null)).map(([n, w]) => `${n}=${nachName(s5, n)?.state} statt ${w}`);
    check('Schritt 5: Saldo, Prognose und Tage seit Ablesung wie /api/summary', falsch5.length === 0, falsch5.join(', '));
    const attr = nachName(s5, 'Strom – Saldo')?.attributes ?? {};
    const fA = ['projected_end_balance', 'projected_end_balance_total', 'projected_end_range_total', 'measured_balance', 'measured_until']
        .filter((f) => JSON.stringify(attr[f] ?? null) !== JSON.stringify(m5.contract?.[f] ?? null));
    check('Schritt 5: Attribute aus json_attributes_path wie der Vertrag in /api/summary', fA.length === 0,
        fA.map((f) => `${f}=${JSON.stringify(attr[f])} statt ${JSON.stringify(m5.contract?.[f])}`).join(', ').slice(0, 300));

    // Schritt 3 und 4: Quellsensoren setzen, Automation auslösen, Stände kommen über rest_command an
    const werte = { strom: Math.round((Number(strom.last_counter) + 2.25) * 1000) / 1000, gas: Math.round((Number(gas.last_counter) + 0.75) * 1000) / 1000 };
    for (const [ent, w, unit] of [['sensor.stromzaehler_total_kwh', werte.strom, 'kWh'], ['sensor.gaszaehler_total_m3', werte.gas, 'm³']]) {
        await http('POST', `${HA_NEU}/api/states/${ent}`, { headers: auth, json: { state: String(w), attributes: { unit_of_measurement: unit } } });
    }
    const autoPush = (await zustaende()).find((s) => s.entity_id.startsWith('automation.') && s.attributes?.id === 'farm_push_taeglich');
    if (!check('Schritt 4: Automation aus der Doku geladen', autoPush, autoPush?.entity_id ?? 'fehlt')) return;
    const tr = await http('POST', `${HA_NEU}/api/services/automation/trigger`, { headers: auth, json: { entity_id: autoPush.entity_id, skip_condition: false } });
    check('Schritt 4: Automation ausgelöst', tr.status === 200, `HTTP ${tr.status}`);
    for (const [u, m] of [['strom', strom], ['gas', gas]]) {
        const da = await warten(`Stand ${u}`, 30, async () => {
            const r = await api(ET, 'GET', `/utility/${u}/readings`, { headers: lesen });
            const liste = Array.isArray(r.d) ? r.d : (Array.isArray(r.d?.readings) ? r.d.readings : []);
            return liste.find((x) => (x.meter_id ?? m.meter_id) === m.meter_id && nahe(x.counter ?? x.value ?? null, werte[u])) ?? null;
        }).catch(() => null);
        check(`Schritt 3: Stand ${werte[u]} für ${u} über rest_command angekommen (Token aus secrets.yaml)`, da, da ? `am ${da.date}` : 'nicht angekommen');
    }

    // Schritt 6, Webhook: Ziel einrichten, Testnachricht, die Automation aus der Doku läuft
    const nz = await api(ET, 'PATCH', '/homeassistant/notify', { headers: admin, json: { target: 'webhook', url: HA_NEU_INTERN, webhook_id: webhookId, enabled: true } });
    check('Schritt 6: Webhook als Ziel gespeichert und eingeschaltet', nz.status === 200 && nz.d?.has_webhook === true && nz.d?.enabled === true,
        `HTTP ${nz.status} ${nz.data?.code ?? ''}`);
    const t = await api(ET, 'POST', '/homeassistant/notify/test', { headers: admin, json: {} });
    check('Testnachricht: Home Assistant nimmt an (200)', t.status === 200 && t.d?.ok === true && t.d?.status === 200,
        `ok ${t.d?.ok}, Status ${t.d?.status}, ${t.d?.error ?? ''}`);
    const lauf = await warten('Ablauf der Webhook-Automation', 30, async () => {
        const [liste] = await haWs(HA_NEU, access, [{ type: 'trace/list', domain: 'automation', item_id: 'farm_webhook' }]);
        return Array.isArray(liste) && liste.length > 0 ? liste[liste.length - 1] : null;
    }).catch(() => null);
    let spur = null;
    if (lauf) [spur] = await haWs(HA_NEU, access, [{ type: 'trace/get', domain: 'automation', item_id: 'farm_webhook', run_id: lauf.run_id }]);
    // Die Aktion der Vorlage bekommt title und message aus trigger.json
    const aktion = spur?.trace?.['action/0']?.[0] ?? null;
    const daten = aktion?.result?.params?.service_data ?? {};
    check('Webhook löst die Automation aus der Doku aus, Aktion mit title und message vom Energietracker',
        lauf && typeof daten.title === 'string' && daten.title !== '' && typeof daten.message === 'string' && daten.message !== '' && !aktion?.error,
        lauf ? `${aktion?.result?.params?.domain}.${aktion?.result?.params?.service}: „${daten.title ?? ''}“${aktion?.error ? `, Fehler ${aktion.error}` : ''}` : 'kein Ablauf');
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
await abschnitt('Eigener Webserver: Apache mit .htaccess, PHP 8.4 (Mindestversion)', () => webserverApache('Apache PHP 8.4', APACHE84, '8.4'));
await abschnitt('Eigener Webserver: Apache mit .htaccess, PHP 8.5', () => webserverApache('Apache PHP 8.5', APACHE85, '8.5'));
await abschnitt('Reverse-Proxy mit HTTPS und Proxy-Anmeldung (Caddy)', reverseProxy);
await abschnitt(`Update von ${UPDATE_VON} auf den Arbeitsstand (dasselbe Volume)`, updateWeg);
await abschnitt(`Home Assistant alt (${E.FARM_HA_ALT || '?'}), Anmeldung aus`, () => homeAssistant('HA alt', 'ha-alt', HA_ALT));
let sitzung = null;
await abschnitt('Anmeldung an', async () => { sitzung = await anmeldung(); });
if (sitzung?.key) {
    await abschnitt('Lesende Probe mit Lese-Schlüssel', () => lesendeProbe(ET, { Authorization: `Bearer ${sitzung.key}` }, { mitDateien: false }));
    await abschnitt(`Home Assistant neu (${E.FARM_HA_NEU || 'stable'}), Anmeldung an`,
        () => homeAssistant('HA neu', 'ha-neu', HA_NEU, { schluessel: sitzung.key }));
    await abschnitt('Home Assistant neu: Vorlagen aus der Doku (Push, Sensoren, Webhook)', () => haVorlagen(sitzung));
}
if (sitzung?.cookie) await abschnitt('Anmeldung aus', () => abmelden(sitzung.cookie));

const dauer = Math.round((Date.now() - start) / 1000);
console.log(`\n${fehler.length === 0 ? 'GRÜN' : 'ROT'}: ${ok} ok, ${fehler.length} Fehler, Prüfungen ${dauer} s`);
for (const f of fehler) console.log(`  ✗ ${f}`);
process.exit(fehler.length === 0 ? 0 : 1);
