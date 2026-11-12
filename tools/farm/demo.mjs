#!/usr/bin/env node
// Demo der Test-Farm ohne VM (tools/farm/demo.sh): Energietracker und Home Assistant
// als Container einrichten — Schaufenster (auf heute ausgerichtet), Integration mit
// Dashboard, Benachrichtigungen per Token, KI-Schlüssel für Open WebUI.
// Nur Beispieldaten, nur 127.0.0.1; Zugänge erfunden und nur in der Demo gültig.
import { chmodSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { HaWs, api, bericht, haEinrichten, http, sleep, warten } from './lib.mjs';

const E = process.env;
const CACHE = join(E.HOME || '', '.cache', 'energietracker-farm');
const ET = `http://127.0.0.1:${E.FARM_PORT_ET_DEMO || 19853}`;
const HA = `http://127.0.0.1:${E.FARM_PORT_HA_DEMO || 19854}`;
const ADMIN = { name: 'Farm', username: 'farm', password: 'farm-ha-123', language: 'de' };
const B = bericht();
const { check, abschnitt } = B;

if (process.argv[2] === 'webui') {
    // Eigene Open WebUI der Demo: Werkzeugserver „energietracker“ eingetragen und erreichbar
    const W = `http://127.0.0.1:${E.FARM_PORT_WEBUI || 19845}`;
    await abschnitt('Open WebUI', async () => {
        const s = await http('POST', `${W}/api/v1/auths/signin`, { json: { email: '', password: '' } });
        const h = { Authorization: `Bearer ${s.data?.token ?? ''}` };
        const ts = await http('GET', `${W}/api/v1/configs/tool_servers`, { headers: h });
        const c = (ts.data?.TOOL_SERVER_CONNECTIONS ?? []).find((x) => x?.info?.id === 'energietracker');
        check('Werkzeugserver „energietracker“ (MCP) eingetragen', ts.status === 200 && c?.type === 'mcp', `HTTP ${ts.status}`);
        const v = await http('POST', `${W}/api/v1/configs/tool_servers/verify`, { headers: h, json: c ?? {}, timeout: 60000 });
        check('Open WebUI erreicht die KI-Schnittstelle (Verbindung prüfen)', v.status === 200, `HTTP ${v.status} ${String(v.data?.detail ?? '')}`);
        const m = await http('GET', `${W}/api/models`, { headers: h });
        const n = (m.data?.data ?? []).length;
        check('Modelle aus Ollama des Macs sichtbar', n > 0, `${n} Modelle`);
    });
    process.exit(B.ende());
}
if (process.argv[2] !== 'docker') { console.error('Aufruf: node demo.mjs docker|webui'); process.exit(2); }

await abschnitt('Demo mit Containern einrichten', async () => {
    // DemoDataAligner läuft beim Import: die Daten folgen dem heutigen Datum
    const imp = await api(ET, 'POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 180000 });
    check('Schaufenster auf heute ausgerichtet', imp.status === 200, `HTTP ${imp.status}`);
    await api(ET, 'PATCH', '/settings', { json: { mcp_enabled: true, evcc_endpoint: 'http://evcc:7070' } });
    const ki = await api(ET, 'POST', '/auth/keys', { json: { name: 'Open WebUI (Demo)', scope: 'mcp' } });
    mkdirSync(join(CACHE, 'demo'), { recursive: true });
    writeFileSync(join(CACHE, 'demo', 'mcp-key-docker'), String(ki.d?.key ?? ''), { mode: 0o600 });
    chmodSync(join(CACHE, 'demo', 'mcp-key-docker'), 0o600);
    check('KI-Schlüssel für Open WebUI', ki.status === 201);

    const access = await warten('Home Assistant', 300, async () => haEinrichten(HA, ADMIN), 3000);
    const ws = await HaWs.verbinden(HA, access);
    await ws.call({ type: 'config/core/update', location_name: 'Musterstadt', latitude: 50.0, longitude: 10.0, elevation: 0,
        unit_system: 'metric', time_zone: 'Europe/Berlin', currency: 'EUR', country: 'DE', language: 'de' });
    const auth = { Authorization: `Bearer ${access}` };
    await warten('Home Assistant läuft', 240, async () => (await http('GET', `${HA}/api/config`, { headers: auth })).data?.state === 'RUNNING');
    const flow = await http('POST', `${HA}/api/config/config_entries/flow`, { headers: auth, json: { handler: 'energietracker', show_advanced_options: false } });
    const res = await http('POST', `${HA}/api/config/config_entries/flow/${flow.data?.flow_id}`, { headers: auth, json: { url: 'http://energietracker', token: '', verify_ssl: true } });
    const entry = res.data?.result?.entry_id;
    check('Integration eingerichtet (http://energietracker)', entry, res.data?.type);
    await sleep(10000);
    const ents = (await ws.call({ type: 'config/entity_registry/list' })).filter((x) => x.platform === 'energietracker' && x.config_entry_id === entry);
    const devs = (await ws.call({ type: 'config/device_registry/list' })).filter((d) => (d.config_entries ?? []).includes(entry));
    const cards = devs.map((d) => ({ type: 'entities', title: d.name_by_user ?? d.name,
        entities: ents.filter((e) => e.device_id === d.id).map((e) => e.entity_id) })).filter((c) => c.entities.length > 0);
    await ws.call({ type: 'lovelace/config/save', config: { title: 'Zuhause', views: [{ title: 'Energietracker', path: 'energietracker', cards }] } });
    check(`Dashboard mit ${cards.length} Karten`, cards.length > 0);
    // Benachrichtigungen: Ziel „Adresse und Token“ (home-assistant.md, Schritt 6)
    const lang = await ws.call({ type: 'auth/long_lived_access_token', client_name: 'Energietracker (Demo)', lifespan: 30 });
    const n = await api(ET, 'PATCH', '/homeassistant/notify', { json: { target: 'token', url: 'http://home-assistant:8123', token: lang, enabled: true } });
    const t = await api(ET, 'POST', '/homeassistant/notify/test', { json: {} });
    check('Benachrichtigungen an Home Assistant (Testnachricht)', n.status === 200 && t.d?.ok === true, `ok ${t.d?.ok}, ${t.d?.error ?? ''}`);
    ws.close();
});
process.exit(B.ende());
