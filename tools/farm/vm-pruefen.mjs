#!/usr/bin/env node
// Home Assistant OS in der Parallels-VM der Test-Farm (tools/farm/README.md, „VM“).
// Aufruf nur über tools/farm/vm.sh; spricht Home Assistant und den Supervisor über
// dieselben Wege wie die Oberfläche (REST, Websocket „supervisor/api“, Ingress).
//
//   node vm-pruefen.mjs einrichten <ip>       Einrichtung ohne Browser, Terminal & SSH, App-Repository
//   node vm-pruefen.mjs lauf <ip>             alle Prüfungen der App (Installation, Update, Ingress, Backup …)
//   node vm-pruefen.mjs demo-einrichten <ip>  App aus dem Arbeitsstand mit Schaufenster für den Snapshot „demo“
//   node vm-pruefen.mjs demo <ip>             Daten auf heute ausrichten, Adressen und Zugänge ausgeben
//   node vm-pruefen.mjs herunterfahren <ip>
//   Helfer für vm.sh: snapshot-id, lease, haos-version, haos-asset
//
// Nur Beispieldaten, nur Adressen im Netz „Shared“ von Parallels. Zugänge sind erfunden und gelten nur in der VM.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, writeFileSync, chmodSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HaWs, api, bericht, erwartung, haAnmelden, haEinrichten, http, isObj, sensorAbweichungen, sleep, warten } from './lib.mjs';

const FARM = dirname(fileURLToPath(import.meta.url));
const ROOT = join(FARM, '..', '..');
const E = process.env;
const CACHE = join(E.HOME || '', '.cache', 'energietracker-farm');
const VERSION = readFileSync(join(ROOT, 'VERSION'), 'utf8').trim();
const SCHEMA = (/SCHEMA_VERSION = '([\d.]+)'/.exec(readFileSync(join(ROOT, 'src', 'Storage', 'Migrator.php'), 'utf8')) || [])[1];
const SSH_KEY = E.FARM_VM_SSH_KEY || join(CACHE, 'ssh', 'id_ed25519');
const APP_DIR = E.FARM_VM_APP_DIR || join(CACHE, 'vm-app');
const INTEGRATION = E.FARM_INTEGRATION || join(E.HOME || '', 'Code', 'energietracker-hacs');
const PORT_APP = Number(E.FARM_VM_PORT_APP || 19851);
const HOST_IP = E.FARM_VM_HOST_IP || '10.211.55.2';       // der Mac im Netz „Shared“ von Parallels
const PORT_EVCC = Number(E.FARM_PORT_EVCC_DEMO || 19852);
const APP_REPO = 'https://github.com/Bingerminger/energietracker-ha';
const HOSTNAME = 'et-farm-haos';
const LOKAL = 'local_energietracker';
// Erfundene Zugänge, nur in der VM gültig
const ADMIN = { name: 'Farm', username: 'farm', password: 'farm-ha-123', language: 'de' };
const MITGLIED = { name: 'Mitbewohner', username: 'mitbewohner', password: 'farm-ha-456' };

// ── Helfer für vm.sh ────────────────────────────────────────────────────────
const [befehl, ...argv] = process.argv.slice(2);
if (befehl === 'snapshot-id') {
    let j = {};
    try { j = JSON.parse(readFileSync(0, 'utf8') || '{}'); } catch { /* keine Snapshots */ }
    const liste = Array.isArray(j) ? j.map((x) => [x.id ?? x.uuid, x]) : Object.entries(j);
    const t = liste.find(([, s]) => s?.name === argv[0]);
    if (t) console.log(t[0]);
    process.exit(0);
}
if (befehl === 'lease') {
    // Zeilen: <IP>="<Zeit>,<Dauer>,<mac>,<Kennung>"; die jüngste Zuteilung gewinnt
    const mac = String(argv[0]).toLowerCase();
    let best = null;
    for (const l of readFileSync(argv[1], 'utf8').split('\n')) {
        const m = /^([\d.]+)="(\d+),\d+,([0-9a-f]{12}),/.exec(l.trim());
        if (m && m[3] === mac && (!best || Number(m[2]) > best[1])) best = [m[1], Number(m[2])];
    }
    if (best) console.log(best[0]);
    process.exit(best ? 0 : 1);
}
if (befehl === 'haos-version') {
    const j = JSON.parse(readFileSync(argv[0], 'utf8'));
    console.log(j.hassos?.[argv[1]] ?? '');
    process.exit(0);
}
if (befehl === 'haos-asset') {
    const j = JSON.parse(readFileSync(argv[0], 'utf8'));
    const a = (j.assets ?? []).find((x) => x.name === argv[1]);
    console.log(a ? `${a.browser_download_url} ${a.digest ?? ''}` : '');
    process.exit(0);
}

// ── Gemeinsames ─────────────────────────────────────────────────────────────
const ip = argv[0];
if (!ip) { console.error('Adresse der VM fehlt'); process.exit(2); }
// Node erreicht die VM nicht direkt (macOS, Freigabe „Lokales Netzwerk“); vm.sh startet dafür eine
// Weiterleitung auf 127.0.0.1 (tools/farm/vm/relay.yml). ssh erreicht die VM direkt.
const RELAY_HA = `http://127.0.0.1:${E.FARM_PORT_VM_HA || 19855}`;
const DIREKT = `http://127.0.0.1:${E.FARM_PORT_VM_APP || 19856}`;   // → <VM>:PORT_APP
async function haBasis() {
    try {
        const r = await fetch(`${RELAY_HA}/manifest.json`, { redirect: 'manual', signal: AbortSignal.timeout(5000) });
        return r.status === 200 ? RELAY_HA : null;
    } catch { return null; }
}
let BASE = null;
const B = bericht();
const { check, hinweis, abschnitt } = B;

const sshArgs = ['-i', SSH_KEY, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no', '-o', `UserKnownHostsFile=${join(CACHE, 'ssh', 'known_hosts')}`,
    '-o', 'ConnectTimeout=10', `root@${ip}`];
const ssh = (cmd, input) => execFileSync('ssh', [...sshArgs, cmd], { encoding: input ? undefined : 'utf8', input, stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
// Ordner der lokalen Apps: seit Home Assistant 2026 /local_apps („Apps“ statt „Add-ons“), vorher /addons
let lokalOrdnerCache = null;
function lokalOrdner() {
    lokalOrdnerCache ??= ssh('if [ -d /local_apps ]; then echo /local_apps; else echo /addons; fi').trim();
    return lokalOrdnerCache;
}
const appOrdner = () => `${lokalOrdner()}/energietracker`;
/** Ordner des Macs nach <ziel> in der VM (über Terminal & SSH), vorher leeren. */
function kopieren(quelle, ziel) {
    const tar = execFileSync('tar', ['-c', '-f', '-', '-C', quelle, '.'], { env: { ...E, COPYFILE_DISABLE: '1' }, maxBuffer: 1024 * 1024 * 1024 });
    ssh(`rm -rf '${ziel}' && mkdir -p '${ziel}' && tar -x -f - -C '${ziel}'`, tar);
}

let access = null;
let ws = null;
async function verbinden() {
    access = await haAnmelden(BASE, ADMIN);
    ws?.close();
    ws = await HaWs.verbinden(BASE, access);
    return ws;
}
const auth = () => ({ Authorization: `Bearer ${access}` });
async function haLaeuft(sekunden = 600) {
    await warten('Home Assistant läuft', sekunden, async () => {
        const r = await http('GET', `${BASE}/api/config`, { headers: auth() });
        return r.status === 200 && r.data?.state === 'RUNNING';
    }, 3000);
}

/** App installieren (Store-Weg, älterer Supervisor: /addons/…/install). */
async function installieren(slug) {
    try { await ws.sup(`/store/addons/${slug}/install`, 'post', undefined, null); }
    catch (e) {
        if (!/not found|404/i.test(String(e.message))) throw e;
        await ws.sup(`/addons/${slug}/install`, 'post', undefined, null);
    }
}
const info = (slug) => ws.sup(`/addons/${slug}/info`);

// Ingress: Sitzung für den angemeldeten HA-Benutzer, Anfragen mit dem Cookie wie die Seitenleiste
async function ingressFuer(slug, wsBenutzer = ws) {
    const i = await info(slug);
    const s = await wsBenutzer.sup('/ingress/session', 'post');
    const cookie = `ingress_session=${s.session}`;
    const entry = i.ingress_entry;
    return {
        entry,
        get: (pfad, o = {}) => http('GET', `${BASE}${entry}${pfad}`, { ...o, headers: { ...(o.headers ?? {}), Cookie: cookie } }),
        api: (method, pfad, o = {}) => api(`${BASE}${entry}/api.php`, method, pfad, { ...o, headers: { ...(o.headers ?? {}), Cookie: cookie } }),
    };
}
async function appBereit(slug, sekunden = 300) {
    return warten(`App ${slug} gesund`, sekunden, async () => {
        const i = await info(slug);
        if (i.state !== 'started') return null;
        const ing = await ingressFuer(slug);
        const h = await ing.api('GET', '/health');
        return h.status === 200 ? { ing, health: h.d, info: i } : null;
    }, 3000);
}
const zaehlen = async (ing) => (await ing.api('GET', '/diagnostics')).d?.utilities ?? null;
const summe = (u, f) => Object.values(u ?? {}).reduce((a, x) => a + Number(x?.[f] ?? 0), 0);
const kurz = (u) => `${summe(u, 'meters')} Zähler, ${summe(u, 'readings') + summe(u, 'deliveries')} Ablesungen/Lieferungen, ${summe(u, 'contracts')} Verträge`;
/** Unterschiede zweier Beschreibungen (Supervisor), ohne die genannten Felder. */
function abweichungen(a, b, ohne) {
    const keys = new Set([...Object.keys(a ?? {}), ...Object.keys(b ?? {})]);
    return [...keys].filter((k) => !ohne.includes(k) && JSON.stringify(a?.[k] ?? null) !== JSON.stringify(b?.[k] ?? null));
}
// Felder, die sich zwischen zwei Quellen derselben App zwangsläufig unterscheiden
const HERKUNFT = ['slug', 'repository', 'hostname', 'ip_address', 'ingress_entry', 'ingress_url', 'ingress_panel', 'state', 'version',
    'version_latest', 'update_available', 'installed', 'detached', 'available', 'build', 'image', 'icon', 'logo', 'changelog',
    'documentation', 'webui', 'stats', 'network_description', 'signed', 'system_managed', 'system_managed_config_entry', 'url'];

async function integrationEinrichten(slug, lesekey) {
    // Marketplace verlangt eine Verbindung zu GitHub (Code für github.com/login/device, Doku
    // home-assistant.md „HACS-Integration“); ohne Konto bleibt der Weg über custom_components
    kopieren(join(INTEGRATION, 'custom_components', 'energietracker'), '/config/custom_components/energietracker');
    ssh("find /config/custom_components/energietracker -name __pycache__ -prune -exec rm -rf {} + ; test -f /config/custom_components/energietracker/manifest.json");
    try { await ws.sup('/core/restart', 'post', undefined, 300); } catch { /* Verbindung bricht beim Neustart ab */ }
    await sleep(10000);
    await warten('Home Assistant nach dem Neustart', 600, async () => { await verbinden(); return true; }, 5000);
    await haLaeuft();
    const host = (await info(slug)).hostname;
    const alt = await http('GET', `${BASE}/api/config/config_entries/entry?domain=energietracker`, { headers: auth() });
    for (const e of (Array.isArray(alt.data) ? alt.data : [])) await http('DELETE', `${BASE}/api/config/config_entries/entry/${e.entry_id}`, { headers: auth() });
    const flow = await http('POST', `${BASE}/api/config/config_entries/flow`, { headers: auth(), json: { handler: 'energietracker', show_advanced_options: false } });
    const res = await http('POST', `${BASE}/api/config/config_entries/flow/${flow.data?.flow_id}`,
        { headers: auth(), json: { url: `http://${host}`, token: lesekey, verify_ssl: true } });
    return { host, flow, res, entry: res.data?.result?.entry_id };
}

// ── einrichten (einmalig, danach Snapshot „sauber“) ─────────────────────────
async function einrichten() {
    await abschnitt('Home Assistant OS einrichten', async () => {
        await warten('Home Assistant (erster Start lädt den Kern)', 1800, async () => {
            const r = await http('GET', `${BASE}/api/onboarding`, { timeout: 10000 });
            return (r.status === 200 && Array.isArray(r.data)) || r.status === 404;
        }, 10000);
        access = await haEinrichten(BASE, ADMIN);
        check('Benutzer „farm“ angelegt (Einrichtung per API)', access);
        ws = await HaWs.verbinden(BASE, access);
        await ws.call({ type: 'config/core/update', location_name: 'Musterstadt', latitude: 50.0, longitude: 10.0, elevation: 0,
            unit_system: 'metric', time_zone: 'Europe/Berlin', currency: 'EUR', country: 'DE', language: 'de' });
        check('Ort Musterstadt, Zeitzone Europe/Berlin, EUR', true);
        // Eigener Hostname statt homeassistant.local, damit im Netz nichts verwechselt wird
        await ws.sup('/host/options', 'post', { hostname: HOSTNAME });
        check(`Hostname ${HOSTNAME}`, (await ws.sup('/host/info')).hostname === HOSTNAME);
        const pub = readFileSync(`${SSH_KEY}.pub`, 'utf8').trim();
        const sshInfo = await info('core_ssh').catch(() => ({}));
        if (!sshInfo.version) await installieren('core_ssh');
        await ws.sup('/addons/core_ssh/options', 'post', {
            options: { authorized_keys: [pub], password: '', apks: [], server: { tcp_forwarding: false } },
            network: { '22/tcp': 22 },
        });
        await ws.sup('/addons/core_ssh/restart', 'post', undefined, 300).catch(() => ws.sup('/addons/core_ssh/start', 'post', undefined, 300));
        const ok = await warten('SSH', 120, async () => ssh('test -d /config && { test -d /local_apps || test -d /addons; } && echo ok').trim() === 'ok', 3000).catch(() => false);
        check(`App „Terminal & SSH“ mit Schlüssel aus ~/.cache/energietracker-farm/ssh, Ordner ${ok ? lokalOrdner() : '?'}`, ok);
        const vorhanden = await ws.sup('/store/repositories');
        const hat = (l) => (Array.isArray(l) ? l : l?.repositories ?? []).some((r) => String(r.source ?? r.url ?? '').startsWith(APP_REPO));
        if (!hat(vorhanden)) await ws.sup('/store/repositories', 'post', { repository: APP_REPO }, 120);
        const repos = await ws.sup('/store/repositories');
        check(`App-Repository ${APP_REPO}`, hat(repos));
    });
}

// ── lauf: jedes Release ─────────────────────────────────────────────────────
async function lauf() {
    let ing = null; let showcase = null; let pubSlug = null;
    await abschnitt('Home Assistant OS', async () => {
        await warten('Home Assistant', 600, async () => { await verbinden(); return true; }, 5000);
        await haLaeuft();
        const core = await ws.sup('/core/info');
        const os = await ws.sup('/os/info').catch(() => ({}));
        const sv = await ws.sup('/supervisor/info');
        check(`Home Assistant ${core.version}, OS ${os.version ?? '?'}, Supervisor ${sv.version}`, true);
        await ws.sup('/store/reload', 'post', undefined, 300);
    });

    await abschnitt('Veröffentlichte App aus dem App-Repository', async () => {
        const store = await ws.sup('/store/addons');
        const liste = Array.isArray(store) ? store : store?.addons ?? [];
        const pub = liste.find((a) => /_energietracker$/.test(a.slug) && a.slug !== LOKAL);
        if (!check('App „Energietracker“ im Store (Repository eingetragen)', pub, pub?.slug ?? 'fehlt')) return;
        pubSlug = pub.slug;
        await installieren(pubSlug);
        await ws.sup(`/addons/${pubSlug}/start`, 'post', undefined, 300);
        const b = await appBereit(pubSlug);
        hinweis(`Home Assistant unter ${BASE}`);
        check(`installiert und gestartet, Version ${pub.version_latest ?? pub.version}`, b.health?.version === (pub.version_latest ?? pub.version),
            `App meldet ${b.health?.version}`);
        // Lokale App (veröffentlichte Fassung) daneben legen und vergleichen
        kopieren(join(APP_DIR, 'veroeffentlicht', 'energietracker'), appOrdner());
        await ws.sup('/store/reload', 'post', undefined, 300);
        const lokal = await ws.sup(`/store/addons/${LOKAL}`);
        const veroeff = await ws.sup(`/store/addons/${pubSlug}`);
        const diff = abweichungen(veroeff, lokal, HERKUNFT);
        check('lokale Kopie der App wie die veröffentlichte (Store-Beschreibung)', diff.length === 0, diff.join(', '));
        // Nur stoppen: Deinstallieren entfernte auch das Image, das die lokale App gleich wieder braucht
        await ws.sup(`/addons/${pubSlug}/stop`, 'post', undefined, 120);
    });

    await abschnitt('Lokale App: veröffentlichte Fassung, Beispielhaushalt', async () => {
        await installieren(LOKAL);
        // „In der Seitenleiste anzeigen“ setzt die Oberfläche von Home Assistant; über die API bleibt es aus
        hinweis(`nach der Installation über die API: ingress_panel ${(await info(LOKAL)).ingress_panel}`);
        await ws.sup(`/addons/${LOKAL}/options`, 'post', { ingress_panel: true });
        await ws.sup(`/addons/${LOKAL}/start`, 'post', undefined, 300);
        const b = await appBereit(LOKAL);
        ing = b.ing;
        check(`gestartet aus dem veröffentlichten Image, Version ${b.health?.version}`, b.health?.status === 'ok' || b.health?.status === 'degraded');
        const s = await ing.api('GET', '/session');
        check('Anmeldung über Home Assistant: Person „Farm“ verwaltet (erste Person)', s.d?.mode === 'proxy' && s.d?.role === 'admin',
            `${s.d?.mode}, ${s.d?.role}, ${s.d?.user?.name ?? s.d?.user}`);
        const imp = await ing.api('POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 180000 });
        check('Beispielhaushalt „showcase“ über Ingress geladen', imp.status === 200 && imp.d?.persona === 'showcase', `HTTP ${imp.status}`);
        showcase = await zaehlen(ing);
        hinweis(kurz(showcase));
    });

    await abschnitt('Update auf den Arbeitsstand (Supervisor baut die App in der VM)', async () => {
        const vorher = await info(LOKAL);
        kopieren(join(APP_DIR, 'arbeitsstand', 'energietracker'), appOrdner());
        await ws.sup('/store/reload', 'post', undefined, 300);
        const st = await ws.sup(`/store/addons/${LOKAL}`);
        check(`Update angeboten (${st.version} → ${st.version_latest})`, st.update_available === true, `${st.version} → ${st.version_latest}`);
        const t0 = Date.now();
        await ws.sup(`/store/addons/${LOKAL}/update`, 'post', { backup: false }, null)
            .catch(async (e) => { if (/not found|404/i.test(e.message)) await ws.sup(`/addons/${LOKAL}/update`, 'post', undefined, null); else throw e; });
        hinweis(`Bau und Update in der VM: ${Math.round((Date.now() - t0) / 1000)} s`);
        const nach = await info(LOKAL);
        if (nach.state !== 'started') await ws.sup(`/addons/${LOKAL}/start`, 'post', undefined, 300);
        const b = await appBereit(LOKAL);
        ing = b.ing;
        // Gleiche Nummer wie veröffentlicht möglich: maßgeblich ist das Image, das der Supervisor gebaut hat
        check(`Version ${VERSION} aus dem Arbeitsstand läuft (vom Supervisor in der VM gebaut)`,
            b.health?.version === VERSION && b.info.build === true && b.info.version === `${VERSION}-farm`,
            `App meldet ${b.health?.version}, Supervisor ${b.info.version}, build ${b.info.build}, vorher build ${vorher.build}`);
        const diff = abweichungen(vorher, b.info, HERKUNFT);
        check('Beschreibung der App nach dem Update gleich (außer image und Version)', diff.length === 0, diff.join(', '));
        const h = (await ing.api('GET', '/health')).d ?? {};
        check(`Schema ${SCHEMA}, keine Migration offen`, h.schema_version === SCHEMA && Number(h.migrations_pending) === 0, `Schema ${h.schema_version}`);
        const nachher = await zaehlen(ing);
        check('Daten bleiben: je Art gleich gezählt', showcase && JSON.stringify(nachher) === JSON.stringify(showcase), kurz(nachher));
        const s = await ing.api('GET', '/session');
        check('Person „Farm“ verwaltet weiter', s.d?.role === 'admin', String(s.d?.role));
    });

    await abschnitt('Seitenleiste (Ingress) und zweiter Benutzer', async () => {
        const panels = await ws.call({ type: 'get_panels' });
        const panel = Object.values(panels ?? {}).find((p) => p?.url_path === LOKAL || p?.config?.ingress === LOKAL)
            ?? Object.values(panels ?? {}).find((p) => p?.title === 'Energietracker');
        check('Eintrag „Energietracker“ in der Seitenleiste, für alle Benutzer', panel && panel.require_admin !== true, panel ? panel.url_path : 'fehlt');
        const seite = await ing.get('/');
        check('Oberfläche über Ingress (Import-Map, versionierte Module)', seite.status === 200 && seite.text.includes('importmap'), `HTTP ${seite.status}`);
        const neu = await ws.call({ type: 'config/auth/create', name: MITGLIED.name, group_ids: ['system-users'], local_only: false });
        await ws.call({ type: 'config/auth_provider/homeassistant/create', user_id: neu.user.id, username: MITGLIED.username, password: MITGLIED.password });
        const t2 = await haAnmelden(BASE, MITGLIED);
        const ws2 = await HaWs.verbinden(BASE, t2);
        const ing2 = await ingressFuer(LOKAL, ws2);
        const s2 = await ing2.api('GET', '/session');
        check('zweiter HA-Benutzer ist Mitglied (Doku: wer zuerst öffnet, verwaltet)', s2.d?.role === 'member', `${s2.d?.user?.name ?? s2.d?.user}, ${s2.d?.role}`);
        const k2 = await ing2.api('GET', '/auth/keys');
        check('Mitglied erreicht die Schlüssel nicht (403)', k2.status === 403, `HTTP ${k2.status}`);
        ws2.close();
    });

    await abschnitt('Backup von Home Assistant (backup_exclude) und Rücksicherung', async () => {
        const neu = await ws.sup('/backups/new/partial', 'post', { name: 'Test-Farm', addons: [LOKAL], homeassistant: false }, null);
        const slug = neu?.slug;
        if (!check('Teilsicherung der App erstellt', slug, slug ?? 'fehlt')) return;
        const dl = await http('GET', `${BASE}/api/hassio/backups/${slug}/download`, { headers: auth(), roh: true, timeout: 300000 });
        const dir = join(CACHE, 'vm-backup');
        rmSync(dir, { recursive: true, force: true });
        mkdirSync(dir, { recursive: true });
        writeFileSync(join(dir, 'backup.tar'), dl.buffer);
        const aussen = execFileSync('tar', ['-t', '-f', join(dir, 'backup.tar')], { encoding: 'utf8' }).split('\n');
        const innen = aussen.find((n) => n.includes(LOKAL));
        let liste = [];
        if (innen) {
            execFileSync('tar', ['-x', '-f', join(dir, 'backup.tar'), '-C', dir, innen]);
            liste = execFileSync('tar', ['-t', '-f', join(dir, innen)], { encoding: 'utf8' }).split('\n').filter(Boolean);
        }
        rmSync(dir, { recursive: true, force: true });
        check('Sicherung enthält die Daten der App (meta.json, settings.json)', liste.some((n) => n.endsWith('data/meta.json')) && liste.some((n) => n.endsWith('data/settings.json')),
            `${liste.length} Einträge`);
        check('keine Reste unterbrochener Schreibvorgänge (backup_exclude „*.tmp.*“)', !liste.some((n) => /\.tmp\./.test(n)));
        hinweis('Einen Rest *.tmp.* kann die Farm nicht in /data der App legen; geprüft ist, dass keiner in der Sicherung steht');
        const anders = await ing.api('POST', '/demo/import', { json: { force: true, persona: 'mieterin' }, timeout: 180000 });
        const zwischen = await zaehlen(ing);
        check('Daten danach geändert (Beispielhaushalt „mieterin“)', anders.status === 200 && JSON.stringify(zwischen) !== JSON.stringify(showcase));
        await ws.sup(`/backups/${slug}/restore/partial`, 'post', { addons: [LOKAL], homeassistant: false }, null);
        const b = await appBereit(LOKAL);
        ing = b.ing;
        const wieder = await zaehlen(ing);
        check('Rücksicherung: wieder der Beispielhaushalt „showcase“', JSON.stringify(wieder) === JSON.stringify(showcase), kurz(wieder));
    });

    let lesekey = null;
    await abschnitt('Integration (custom_components, ohne Marketplace-Konto)', async () => {
        const k = await ing.api('POST', '/auth/keys', { json: { name: 'Home Assistant', scope: 'read' } });
        lesekey = String(k.d?.key ?? '');
        check('Lese-Schlüssel in der App angelegt (über Ingress)', k.status === 201 && lesekey !== '', `HTTP ${k.status}`);
        const r = await integrationEinrichten(LOKAL, lesekey);
        if (!check(`Eintrag angelegt (http://${r.host}, Lese-Schlüssel)`, r.res.data?.type === 'create_entry' && r.entry,
            `${r.res.data?.type ?? r.res.status} ${JSON.stringify(r.res.data?.errors ?? '')}`)) return;
        const b = await appBereit(LOKAL);
        ing = b.ing;
        const summary = (await ing.api('GET', '/summary')).d;
        const todo = (await ing.api('GET', '/todo')).d;
        const soll = erwartung(summary);
        const reg = await warten('Sensoren der Integration', 120, async () => {
            const ents = (await ws.call({ type: 'config/entity_registry/list' })).filter((x) => x.platform === 'energietracker' && x.config_entry_id === r.entry);
            return ents.length >= soll.sensoren ? ents : null;
        }).catch(async () => (await ws.call({ type: 'config/entity_registry/list' })).filter((x) => x.platform === 'energietracker' && x.config_entry_id === r.entry));
        const geraete = (await ws.call({ type: 'config/device_registry/list' })).filter((d) => (d.config_entries ?? []).includes(r.entry));
        check(`${soll.geraete} Geräte und ${soll.sensoren} Sensoren wie aus /api/summary erwartet`, geraete.length === soll.geraete && reg.length === soll.sensoren,
            `${geraete.length} und ${reg.length}`);
        const states = await http('GET', `${BASE}/api/states`, { headers: auth() });
        const zustand = new Map((Array.isArray(states.data) ? states.data : []).map((s) => [s.entity_id, s.state]));
        const { falsch, leer } = sensorAbweichungen(reg, zustand, summary, todo);
        check('Werte wie /api/summary und /api/todo, leer nur bei null', falsch.length === 0, falsch.length ? falsch.slice(0, 4).join(', ') : `${leer} leer`);
    });

    await abschnitt('Benachrichtigungen über den Supervisor', async () => {
        const p = await ing.api('PATCH', '/homeassistant/notify', { json: { target: 'supervisor', enabled: true } });
        check('Ziel Supervisor eingeschaltet (SUPERVISOR_TOKEN kommt an)', p.status === 200 && p.d?.supervisor_available === true && p.d?.enabled === true,
            `HTTP ${p.status}, supervisor_available ${p.d?.supervisor_available}`);
        const abo = await ws.abo({ type: 'persistent_notification/subscribe' });
        const t = await ing.api('POST', '/homeassistant/notify/test', { json: {} });
        check('Testnachricht über den Supervisor angenommen', t.d?.ok === true, `ok ${t.d?.ok}, Status ${t.d?.status}, ${t.d?.error ?? ''}`);
        const da = await warten('Benachrichtigung in Home Assistant', 30, async () => {
            const alle = ws.ereignisse(abo).flatMap((ev) => Object.values(ev?.notifications ?? {}));
            return alle.find((n) => /Energietracker/i.test(String(n?.title ?? ''))) ?? null;
        }).catch(() => null);
        check('Benachrichtigung erscheint in Home Assistant (persistent_notification)', da, da ? `„${da.title}“` : 'keine');
    });

    await abschnitt(`KI-Schnittstelle (MCP) über den direkten Port ${PORT_APP}`, async () => {
        await ws.sup(`/addons/${LOKAL}/options`, 'post', { network: { '80/tcp': PORT_APP } });
        await ws.sup(`/addons/${LOKAL}/restart`, 'post', undefined, 300);
        const b = await appBereit(LOKAL);
        ing = b.ing;
        await ing.api('PATCH', '/settings', { json: { mcp_enabled: true } });
        const k = await ing.api('POST', '/auth/keys', { json: { name: 'Farm KI', scope: 'mcp' } });
        const key = String(k.d?.key ?? '');
        const ohne = await api(DIREKT, 'GET', '/utilities');
        check('direkter Port: ohne Person kein Zugriff (401)', ohne.status === 401, `HTTP ${ohne.status}`);
        const rpc = (msg) => http('POST', `${DIREKT}/api/mcp`, { headers: { Accept: 'application/json, text/event-stream', Authorization: `Bearer ${key}` },
            json: { jsonrpc: '2.0', ...msg } });
        const init = await rpc({ id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'et-farm', version: VERSION } } });
        check(`initialize über http://<VM>:${PORT_APP}/api/mcp`, init.status === 200 && typeof init.data?.result?.protocolVersion === 'string', `HTTP ${init.status}`);
        const call = await rpc({ id: 2, method: 'tools/call', params: { name: 'get_overview', arguments: {} } });
        const ov = call.data?.result?.structuredContent;
        const summary = (await ing.api('GET', '/summary')).d;
        check('get_overview: dieselben Zähler wie die Übersicht', isObj(ov) && JSON.stringify((summary?.meters ?? []).map((m) => m.meter_id)) === JSON.stringify((ov.meters ?? []).map((m) => m.id)),
            `${(ov?.meters ?? []).length} Zähler`);
    });
}

// ── Demo ────────────────────────────────────────────────────────────────────
async function demoEinrichten() {
    await abschnitt('Demo: App aus dem Arbeitsstand mit Schaufenster', async () => {
        await warten('Home Assistant', 600, async () => { await verbinden(); return true; }, 5000);
        await haLaeuft();
        kopieren(join(APP_DIR, 'arbeitsstand', 'energietracker'), appOrdner());
        await ws.sup('/store/reload', 'post', undefined, 300);
        await installieren(LOKAL);
        await ws.sup(`/addons/${LOKAL}/options`, 'post', { network: { '80/tcp': PORT_APP }, boot: 'auto', watchdog: true, ingress_panel: true });
        await ws.sup(`/addons/${LOKAL}/start`, 'post', undefined, 300);
        let { ing } = await appBereit(LOKAL, 600);
        check('App gebaut und gestartet', true);
        const imp = await ing.api('POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 180000 });
        check('Schaufenster geladen', imp.status === 200, `HTTP ${imp.status}`);
        await ing.api('PATCH', '/settings', { json: { mcp_enabled: true, evcc_endpoint: `http://${HOST_IP}:${PORT_EVCC}` } });
        const lesen = await ing.api('POST', '/auth/keys', { json: { name: 'Home Assistant', scope: 'read' } });
        const ki = await ing.api('POST', '/auth/keys', { json: { name: 'Open WebUI (Demo)', scope: 'mcp' } });
        const dir = join(CACHE, 'demo');
        mkdirSync(dir, { recursive: true });
        writeFileSync(join(dir, 'mcp-key'), String(ki.d?.key ?? ''), { mode: 0o600 });
        chmodSync(join(dir, 'mcp-key'), 0o600);
        check('Schlüssel für Home Assistant und Open WebUI angelegt', lesen.status === 201 && ki.status === 201);
        const r = await integrationEinrichten(LOKAL, String(lesen.d?.key ?? ''));
        check('Integration eingerichtet', r.entry, r.host);
        ({ ing } = await appBereit(LOKAL));
        const n = await ing.api('PATCH', '/homeassistant/notify', { json: { target: 'supervisor', enabled: true } });
        await ing.api('POST', '/homeassistant/notify/test', { json: {} });
        check('Benachrichtigungen über den Supervisor an', n.d?.enabled === true);
        // Dashboard „Energietracker“ mit den Sensoren der Integration
        await sleep(15000);
        const ents = (await ws.call({ type: 'config/entity_registry/list' })).filter((x) => x.platform === 'energietracker' && x.config_entry_id === r.entry);
        const devs = (await ws.call({ type: 'config/device_registry/list' })).filter((d) => (d.config_entries ?? []).includes(r.entry));
        const cards = devs.map((d) => ({ type: 'entities', title: d.name_by_user ?? d.name,
            entities: ents.filter((e) => e.device_id === d.id).map((e) => e.entity_id) })).filter((c) => c.entities.length > 0);
        await ws.call({ type: 'lovelace/config/save', config: { title: 'Zuhause', views: [{ title: 'Energietracker', path: 'energietracker', cards }] } });
        check(`Dashboard mit ${cards.length} Karten`, cards.length > 0);
    });
}
async function demo() {
    await abschnitt('Demo: Daten auf heute ausrichten', async () => {
        await warten('Home Assistant', 600, async () => { await verbinden(); return true; }, 5000);
        await haLaeuft();
        const { ing } = await appBereit(LOKAL, 600);
        // DemoDataAligner läuft beim Import: Ablesungen, Termine und Rechnungen folgen dem heutigen Datum
        const imp = await ing.api('POST', '/demo/import', { json: { force: true, persona: 'showcase' }, timeout: 180000 });
        check('Schaufenster auf heute ausgerichtet (POST /api/demo/import)', imp.status === 200, `HTTP ${imp.status}`);
        // Der Import bringt die Einstellungen des Schaufensters mit: KI-Schnittstelle, evcc und Benachrichtigungen wieder an
        const st = await ing.api('PATCH', '/settings', { json: { mcp_enabled: true, evcc_endpoint: `http://${HOST_IP}:${PORT_EVCC}` } });
        const nz = await ing.api('PATCH', '/homeassistant/notify', { json: { target: 'supervisor', enabled: true } });
        check('KI-Schnittstelle, evcc und Benachrichtigungen eingeschaltet', st.status === 200 && nz.d?.enabled === true, `HTTP ${st.status}, ${nz.status}`);
        const entries = await http('GET', `${BASE}/api/config/config_entries/entry?domain=energietracker`, { headers: auth() });
        const ids = (Array.isArray(entries.data) ? entries.data : []).map((e) => e.entry_id);
        for (const id of ids) await http('POST', `${BASE}/api/config/config_entries/entry/${id}/reload`, { headers: auth() });
        check('Integration neu geladen (Sensoren mit den Zahlen von heute)', ids.length > 0);

        // Ein Haushalt durchgängig: Saldo Gas in der App, im Sensor und in der Antwort der KI-Schnittstelle
        const summary = (await ing.api('GET', '/summary')).d;
        const gas = (summary?.meters ?? []).find((m) => m.utility === 'gas' && isObj(m.contract) && m.contract.balance != null);
        if (!check('Gaszähler mit Vertrag im Schaufenster', gas)) return;
        const uid = `${summary.instance_id}_${gas.key}_balance`;
        const sensor = await warten('Saldo-Sensor', 60, async () => {
            const e = (await ws.call({ type: 'config/entity_registry/list' })).find((x) => x.platform === 'energietracker' && x.unique_id === uid);
            if (!e) return null;
            const s = await http('GET', `${BASE}/api/states/${e.entity_id}`, { headers: auth() });
            return s.data && Math.abs(Number(s.data.state) - Number(gas.contract.balance)) < 1e-6 ? s.data : null;
        }).catch(() => null);
        const key = readFileSync(join(CACHE, 'demo', 'mcp-key'), 'utf8').trim();
        const rpc = await http('POST', `${DIREKT}/api/mcp`, { headers: { Accept: 'application/json, text/event-stream', Authorization: `Bearer ${key}` },
            json: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_overview', arguments: {} } } });
        const om = (rpc.data?.result?.structuredContent?.meters ?? []).find((m) => m.id === gas.meter_id);
        const ki = om?.contract?.status?.balance_today?.value;
        check(`Saldo Gas überall gleich: App ${gas.contract.balance}, Sensor ${sensor?.state ?? '?'}, KI-Schnittstelle ${ki ?? '?'}`,
            sensor && Math.abs(Number(ki) - Number(gas.contract.balance)) < 1e-6, `MCP HTTP ${rpc.status}`);
    });
}

// ── Ablauf ──────────────────────────────────────────────────────────────────
let rc = 0;
try {
    BASE = await warten('Home Assistant in der VM', befehl === 'einrichten' ? 1800 : 600, haBasis, 5000);
    if (befehl === 'einrichten') await einrichten();
    else if (befehl === 'lauf') await lauf();
    else if (befehl === 'demo-einrichten') await demoEinrichten();
    else if (befehl === 'demo') await demo();
    else if (befehl === 'herunterfahren') {
        await verbinden();
        try { await ws.sup('/host/shutdown', 'post', undefined, 60); } catch { /* Verbindung endet */ }
        process.exit(0);
    } else { console.error(`unbekannt: ${befehl}`); process.exit(2); }
} finally {
    ws?.close();
}
rc = B.ende();
process.exit(rc);
