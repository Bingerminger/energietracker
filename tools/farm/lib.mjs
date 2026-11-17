// Gemeinsame Bausteine der Test-Farm (tools/farm/README.md): HTTP, Bericht,
// Websocket von Home Assistant, Erwartung der Integration aus /api/summary.
// Node 22 oder neuer: fetch und WebSocket sind eingebaut, keine Pakete.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const isObj = (x) => x !== null && typeof x === 'object' && !Array.isArray(x);

// ── Bericht ─────────────────────────────────────────────────────────────────
export function bericht() {
    let ok = 0;
    const fehler = [];
    const start = Date.now();
    const check = (name, cond, info = '') => {
        const pass = Boolean(cond);
        console.log(`  ${pass ? '✓' : '✗'} ${name}${info !== '' ? ` (${info})` : ''}`);
        if (pass) ok++; else fehler.push(name);
        return pass;
    };
    /** Beobachtung ohne Urteil (zählt nicht als Prüfung). */
    const hinweis = (text) => console.log(`  · ${text}`);
    const abschnitt = async (titel, fn) => {
        console.log(`\n== ${titel}`);
        try {
            return await fn();
        } catch (e) {
            check(`${titel}: ohne Abbruch`, false, String(e?.message ?? e));
            return undefined;
        }
    };
    const ende = () => {
        const dauer = Math.round((Date.now() - start) / 1000);
        console.log(`\n${fehler.length === 0 ? 'GRÜN' : 'ROT'}: ${ok} ok, ${fehler.length} Fehler, Prüfungen ${dauer} s`);
        for (const f of fehler) console.log(`  ✗ ${f}`);
        return fehler.length === 0 ? 0 : 1;
    };
    return { check, hinweis, abschnitt, ende, fehler: () => fehler.length };
}

export async function warten(was, sekunden, fn, pauseMs = 2000) {
    const bis = Date.now() + sekunden * 1000;
    let letzter = '';
    for (;;) {
        try {
            const r = await fn();
            if (r) return r;
        } catch (e) { letzter = String(e?.message ?? e); }
        if (Date.now() > bis) throw new Error(`${was}: nach ${sekunden} s nicht erreicht${letzter ? ` (${letzter})` : ''}`);
        await sleep(pauseMs);
    }
}

// ── HTTP ────────────────────────────────────────────────────────────────────
export async function http(method, url, { headers = {}, json, form, timeout = 60000, roh = false } = {}) {
    const h = { Accept: 'application/json', ...headers };
    let body;
    if (json !== undefined) { h['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (form !== undefined) { h['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
    const r = await fetch(url, { method, headers: h, body, redirect: 'manual', signal: AbortSignal.timeout(timeout) });
    if (roh) return { status: r.status, headers: r.headers, buffer: Buffer.from(await r.arrayBuffer()) };
    const text = await r.text();
    let data = null;
    try { data = JSON.parse(text); } catch { /* keine JSON-Antwort */ }
    return { status: r.status, headers: r.headers, text, data };
}
/** Energietracker: Antwort in der Hülle {success, data}; `d` ist der Inhalt. `base` endet vor `/api`. */
export async function api(base, method, path, opts = {}) {
    const r = await http(method, `${base}/api${path}`, opts);
    const d = r.data && typeof r.data === 'object' && 'data' in r.data ? r.data.data : r.data;
    return { ...r, d };
}

// ── Home Assistant ──────────────────────────────────────────────────────────
/** Websocket mit Anfrage/Antwort und Ereignissen je Abo. */
export class HaWs {
    static verbinden(base, token) {
        return new Promise((resolve, reject) => {
            const ws = new WebSocket(`${base.replace(/^http/, 'ws')}/api/websocket`);
            const c = new HaWs(ws);
            const timer = setTimeout(() => { ws.close(); reject(new Error('Websocket: keine Anmeldung in 30 s')); }, 30000);
            ws.onmessage = (ev) => {
                const m = JSON.parse(String(ev.data));
                if (m.type === 'auth_required') ws.send(JSON.stringify({ type: 'auth', access_token: token }));
                else if (m.type === 'auth_ok') { clearTimeout(timer); resolve(c); }
                else if (m.type === 'auth_invalid') { clearTimeout(timer); ws.close(); reject(new Error('Websocket: Anmeldung abgelehnt')); }
                else c.#an(m);
            };
            ws.onerror = () => { clearTimeout(timer); reject(new Error('Websocket-Fehler')); };
            ws.onclose = () => c.#zu();
        });
    }
    #ws; #id = 0; #offen = new Map(); #ereignisse = new Map();
    constructor(ws) { this.#ws = ws; }
    #an(m) {
        if (m.type === 'result' && this.#offen.has(m.id)) {
            const { resolve, reject, timer } = this.#offen.get(m.id);
            clearTimeout(timer);
            this.#offen.delete(m.id);
            if (m.success) resolve(m.result); else reject(new Error(`${m.error?.code ?? 'Fehler'}: ${m.error?.message ?? ''}`));
        } else if (m.type === 'event') {
            const l = this.#ereignisse.get(m.id) ?? [];
            l.push(m.event);
            this.#ereignisse.set(m.id, l);
        }
    }
    #zu() { for (const { reject, timer } of this.#offen.values()) { clearTimeout(timer); reject(new Error('Websocket geschlossen')); } this.#offen.clear(); }
    /** Eine Nachricht; `sekunden` null = ohne eigene Frist (Supervisor-Aufrufe mit timeout: null). */
    call(msg, sekunden = 60) {
        const id = ++this.#id;
        return new Promise((resolve, reject) => {
            const timer = sekunden === null ? null : setTimeout(() => { this.#offen.delete(id); reject(new Error(`${msg.type} ${msg.endpoint ?? ''}: keine Antwort in ${sekunden} s`)); }, sekunden * 1000);
            this.#offen.set(id, { resolve, reject, timer });
            this.#ws.send(JSON.stringify({ id, ...msg }));
        });
    }
    /** Abo starten; liefert die ID, Ereignisse über ereignisse(id). */
    async abo(msg) { const id = this.#id + 1; await this.call(msg); return id; }
    ereignisse(id) { return this.#ereignisse.get(id) ?? []; }
    /** Supervisor über Home Assistant (wie die Oberfläche): supervisor/api. */
    sup(endpoint, method = 'get', data = undefined, sekunden = 60) {
        const msg = { type: 'supervisor/api', endpoint, method, timeout: sekunden === null ? null : sekunden };
        if (data !== undefined) msg.data = data;
        return this.call(msg, sekunden === null ? null : sekunden + 10);
    }
    close() { try { this.#ws.close(); } catch { /* schon zu */ } }
}

/** Anmelden mit Benutzername und Passwort (login_flow), liefert das Zugangstoken. */
export async function haAnmelden(base, { username, password }) {
    const clientId = `${base}/`;
    const lf = await http('POST', `${base}/auth/login_flow`, { json: { client_id: clientId, handler: ['homeassistant', null], redirect_uri: clientId } });
    const r = await http('POST', `${base}/auth/login_flow/${lf.data?.flow_id}`, { json: { username, password, client_id: clientId } });
    if (r.data?.type !== 'create_entry') throw new Error(`Anmeldung ${username} abgelehnt (${JSON.stringify(r.data?.errors ?? r.status)})`);
    const tok = await http('POST', `${base}/auth/token`, { form: { grant_type: 'authorization_code', code: r.data.result, client_id: clientId } });
    if (!tok.data?.access_token) throw new Error(`kein Zugangstoken für ${username} (HTTP ${tok.status})`);
    return tok.data.access_token;
}

/** Einrichtung ohne Browser; ist sie schon erledigt, anmelden. Liefert das Zugangstoken. */
export async function haEinrichten(base, benutzer) {
    const clientId = `${base}/`;
    const on = await http('GET', `${base}/api/onboarding`);
    const schritte = on.status === 200 && Array.isArray(on.data) ? on.data : [{ step: 'user', done: true }];
    let access;
    if (!schritte.find((s) => s.step === 'user')?.done) {
        const u = await http('POST', `${base}/api/onboarding/users`, { json: { client_id: clientId, ...benutzer } });
        if (!u.data?.auth_code) throw new Error(`Einrichtung: Benutzer nicht angelegt (HTTP ${u.status})`);
        const tok = await http('POST', `${base}/auth/token`, { form: { grant_type: 'authorization_code', code: u.data.auth_code, client_id: clientId } });
        access = tok.data?.access_token;
        if (!access) throw new Error('Einrichtung: kein Zugangstoken');
    } else {
        access = await haAnmelden(base, benutzer);
    }
    const auth = { Authorization: `Bearer ${access}` };
    for (const s of schritte.filter((x) => !x.done && x.step !== 'user')) {
        const body = s.step === 'integration' ? { client_id: clientId, redirect_uri: `${base}/?auth_callback=1` } : {};
        await http('POST', `${base}/api/onboarding/${s.step}`, { headers: auth, json: body });
    }
    return access;
}

// ── Integration ─────────────────────────────────────────────────────────────
/** Version der Integration aus ihrem manifest.json (`null`, wenn unlesbar). */
export function integrationVersion(dir) {
    try {
        return String(JSON.parse(readFileSync(join(dir, 'custom_components', 'energietracker', 'manifest.json'), 'utf8')).version ?? '') || null;
    } catch { return null; }
}
/** `a` mindestens `b` (SemVer ohne Zusatz); unlesbar zählt als alt. */
export function versionMindestens(a, b) {
    const p = (v) => String(v ?? '').split('.').map((n) => parseInt(n, 10));
    const x = p(a); const y = p(b);
    if (x.length < 3 || x.some(Number.isNaN)) return false;
    for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] > y[i];
    return true;
}
/** Sensoren von „Zu tun“ am Gerät „Energietracker“ ohne Wert aus der Übersicht (todo_count prüft der Vergleich selbst). */
const TODO_OHNE_WERT = new Set(['todo_next', 'todo_next_date', 'todo_upcoming', 'todo_upcoming_date']);

/**
 * Was die Integration aus der Übersicht macht (logic.py: all_meters, meter_sensor_keys, meter_value, meter_kind).
 * Ab Integration 1.1.0 zusätzlich: Bestand und Füllstand je Tank (`tank`), Prognose und Kosten der Gruppe
 * (`group_forecast_12m`, nur wenn die App den Schlüssel schickt), Auszahlungen der Einspeisung
 * (`contract.payouts_*`, wenn `payouts_received` nicht null ist). `version` = Version der Integration, sonst
 * gilt der Stand 1.0 (so bleibt die Farm mit der veröffentlichten 1.0.x grün).
 *
 * `werte`: Suffix der unique_id → Wert; `einheiten`: Suffix → erwartete Einheit (nur die Sensoren ab 1.1.0);
 * `neu`: Zahl der neuen Sensoren je Art.
 */
export function erwartung(summary, version = null) {
    const ab11 = versionMindestens(version, '1.1.0');
    const waehrung = String(summary?.currency || 'EUR');
    const meters = (summary?.meters ?? []).filter((m) => isObj(m) && m.key);
    const gruppen = []; const seen = new Set();
    for (const m of summary?.meters ?? []) {
        const gc = isObj(m) ? m.group_contract : null;
        if (!isObj(gc) || !gc.group_id || !isObj(gc.contract)) continue;
        const key = `${m.utility}.group.${gc.group_id}`;
        if (seen.has(key)) continue;
        seen.add(key);
        const g = { key, contract: gc.contract, group: true, consumption_unit: m.consumption_unit };
        if (ab11 && 'group_forecast_12m' in m) g.forecast_12m = m.group_forecast_12m;
        gruppen.push(g);
    }
    const werte = new Map();
    const einheiten = new Map();
    const neu = { tank: 0, gruppenPrognose: 0, auszahlungen: 0 };
    for (const m of [...meters, ...gruppen]) {
        let keys;
        if (m.group) {
            keys = ['balance', ...('forecast_12m' in m ? ['forecast_12m', 'forecast_12m_cost'] : [])];
        } else {
            keys = ['balance', 'year_to_date', 'forecast_12m', 'days_since_reading']
                .filter((k) => !(k === 'balance' && m.contract == null) && !(k === 'forecast_12m' && m.tank != null));
            if (ab11 && isObj(m.tank)) keys.push('tank_stock', 'tank_level');
            if (ab11 && isObj(m.contract) && m.contract.payouts_received != null) {
                keys.push('payouts_received', 'payouts_open', 'payouts_open_at_settle');
            }
        }
        for (const k of keys) {
            const wert = k === 'balance' ? m.contract?.balance
                : k === 'days_since_reading' ? m.days_since_reading
                : k === 'forecast_12m_cost' ? m.forecast_12m?.cost
                : k === 'tank_stock' ? m.tank?.stock
                : k === 'tank_level' ? m.tank?.percent
                : k.startsWith('payouts_') ? m.contract?.[k]
                : m[k]?.value;
            const sw = `${m.key}_${k}`;
            werte.set(sw, wert ?? null);
            if (k === 'tank_stock') { einheiten.set(sw, m.tank?.unit || null); neu.tank++; }
            else if (k === 'tank_level') { einheiten.set(sw, '%'); neu.tank++; }
            else if (k.startsWith('payouts_')) { einheiten.set(sw, waehrung); neu.auszahlungen++; }
            else if (m.group && k === 'forecast_12m') { einheiten.set(sw, m.consumption_unit || null); neu.gruppenPrognose++; }
            else if (m.group && k === 'forecast_12m_cost') { einheiten.set(sw, waehrung); neu.gruppenPrognose++; }
        }
    }
    return { geraete: 1 + meters.length + gruppen.length, sensoren: 5 + werte.size, werte, einheiten, neu, ab11 };
}

/**
 * Sensoren der Integration gegen die API: falsche Werte (leer nur bei null), ab 1.1.0 die Einheiten der neuen
 * Sensoren. Ein Sensor, den die Erwartung nicht kennt, ist ein Fehler (sonst bliebe ein falscher Name grün, ohne
 * dass etwas verglichen wurde). `zustand`: entity_id → Zustand aus /api/states (Text oder ganzes Objekt).
 */
export function sensorAbweichungen(sensoren, zustand, summary, todo, version = null) {
    const soll = erwartung(summary, version);
    const prefix = `${summary?.instance_id}_`;
    const falsch = []; let leer = 0; let einheitenGeprueft = 0;
    for (const e of sensoren) {
        const uid = String(e.unique_id ?? '');
        const sw = uid.startsWith(prefix) ? uid.slice(prefix.length) : uid;
        const roh = zustand.get(e.entity_id);
        const ist = isObj(roh) ? roh.state : roh;
        if (sw === 'todo_count') {
            if (Number(ist) !== Number(todo?.count)) falsch.push(`${sw}=${ist}`);
            continue;
        }
        if (TODO_OHNE_WERT.has(sw)) continue;
        if (!soll.werte.has(sw)) { falsch.push(`${sw} unerwartet`); continue; }
        const wert = soll.werte.get(sw);
        if (wert === null) {
            leer++;
            if (ist !== 'unknown') falsch.push(`${sw}=${ist} statt leer`);
        } else if (!(Math.abs(Number(ist) - Number(wert)) <= 1e-6 * Math.max(1, Math.abs(Number(wert))))) {
            falsch.push(`${sw}=${ist} statt ${wert}`);
        }
        if (soll.einheiten.has(sw) && isObj(roh)) {
            einheitenGeprueft++;
            const einheit = roh.attributes?.unit_of_measurement ?? null;
            if (einheit !== soll.einheiten.get(sw)) falsch.push(`${sw} in ${einheit} statt ${soll.einheiten.get(sw)}`);
        }
    }
    return { falsch, leer, einheitenGeprueft };
}
