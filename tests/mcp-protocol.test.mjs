// =====================================================================
// MCP-Protokoll gegen den echten Server (v3.12.0, F1037).
//
//   node tests/mcp-protocol.test.mjs
//
// Startet `php -S` mit router.php auf einem freien Port (ET_MCP_PORT) und
// einem leeren Datenverzeichnis, schaltet MCP ein, legt Schlüssel an und
// spricht /api/mcp mit rohem fetch an (ohne Zusatzpaket):
//   - ältere Fassung: initialize → notifications/initialized (202) →
//     tools/list → tools/call list_meters
//   - 2026-07-28: server/discover und tools/call mit _meta und Kopfzeilen
//   - Fehler: Fassung 1900-01-01 (400, -32022, supported), Kopfzeile passt
//     nicht (400, -32020), unbekannte Methode (404, -32601), GET (405),
//     Stapel (400), ohne Schlüssel (401, WWW-Authenticate), Lese-Schlüssel
//     der übrigen API (403), fremder Ursprung (403), ausgeschaltet (403)
//   - Content-Type application/json auf jeder Antwort mit Körper
//   - Paket protokoll: Pflicht-Kopfzeilen 2026-07-28 (MCP-Protocol-Version,
//     Mcp-Method, Mcp-Name auch als =?base64?…?=), ping modern 404, ttlMs
//     und cacheScope, unbekannter Schlüssel 401 mit error="invalid_token",
//     429 mit Retry-After nach 120 Anfragen eines Schlüssels, Zugriffs-
//     protokoll ohne Schlüssel, zuletzt die Sperre nach 20 falschen Schlüsseln
// =====================================================================
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

const dataDir = mkdtempSync(join(tmpdir(), 'et-mcp-'));
writeFileSync(join(dataDir, 'settings.json'), JSON.stringify({ language: 'de' }));
const port = Number(process.env.ET_MCP_PORT) || await freePort();
const env = { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' };
for (const k of ['ET_AUTH', 'ET_MCP', 'ET_ALLOWED_HOSTS', 'ET_DEBUG']) delete env[k];
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], { cwd: ROOT, env, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}`;
const MODERN = '2026-07-28';

const api = async (method, path, body) => {
  const r = await fetch(BASE + path, { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => null);
  return j?.data ?? j;
};
// eine Nachricht an /api/mcp; `modern` setzt _meta und die Kopfzeilen der Fassung 2026-07-28
const mcp = async (msg, { key, modern = false, headers = {}, raw, omit = [] } = {}) => {
  const h = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers };
  if (key) h.Authorization = `Bearer ${key}`;
  if (modern) {
    msg = { ...msg, params: { ...(msg.params || {}), _meta: { 'io.modelcontextprotocol/protocolVersion': MODERN,
      'io.modelcontextprotocol/clientInfo': { name: 'node-test', version: '1' }, 'io.modelcontextprotocol/clientCapabilities': {}, ...(msg.params?._meta || {}) } } };
    h['MCP-Protocol-Version'] ??= MODERN;
    h['Mcp-Method'] ??= msg.method;
    if (msg.params?.name) h['Mcp-Name'] ??= msg.params.name;
  }
  for (const k of omit) delete h[k];
  const r = await fetch(`${BASE}/api/mcp`, { method: 'POST', headers: h, body: raw ?? JSON.stringify(msg) });
  const text = await r.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch { /* kein JSON */ }
  return { status: r.status, headers: r.headers, text, json };
};
const jsonType = (r) => r.headers.get('content-type')?.startsWith('application/json');

try {
  let up = false;
  for (let i = 0; i < 100 && !up; i++) {
    try { up = (await fetch(`${BASE}/api/health`)).ok; } catch { await sleep(100); }
  }
  t('Testserver läuft', up);

  const key = (await api('POST', '/api/auth/keys', { name: 'KI lesen', scope: 'mcp' })).key;
  const readKey = (await api('POST', '/api/auth/keys', { name: 'Anzeige', scope: 'read' })).key;
  t('Schlüssel angelegt (mcp, read)', /^etk_[0-9a-f]{48}$/.test(key) && /^etk_/.test(readKey));

  // ── ab Werk aus ──
  const off = await mcp({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {} } }, { key });
  t('ab Werk aus: 403 mit Hinweis', off.status === 403 && off.json?.error?.code === -31001 && off.json?.error?.data?.code === 'errors.mcp.disabled' && jsonType(off),
    `${off.status} ${off.text}`);
  await api('PATCH', '/api/settings', { mcp_enabled: true });

  // ── ältere Fassung (initialize) ──
  const init = await mcp({ jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'node-test', version: '1' } } }, { key });
  t('initialize: Fassung des Clients, Fähigkeiten, Server, Anweisungen', init.status === 200 && jsonType(init)
    && init.json?.result?.protocolVersion === '2025-06-18' && init.json.result.serverInfo?.name === 'energietracker'
    && init.json.result.capabilities?.tools?.listChanged === false && typeof init.json.result.instructions === 'string'
    && init.json.result.resultType === undefined, init.text.slice(0, 300));
  t('initialize: keine Sitzung (kein Mcp-Session-Id)', init.headers.get('mcp-session-id') === null);
  const note = await mcp({ jsonrpc: '2.0', method: 'notifications/initialized' }, { key, headers: { 'MCP-Protocol-Version': '2025-06-18' } });
  t('notifications/initialized: 202 ohne Körper', note.status === 202 && note.text === '', `${note.status} ${note.text}`);
  const list = await mcp({ jsonrpc: '2.0', id: 2, method: 'tools/list' }, { key, headers: { 'MCP-Protocol-Version': '2025-06-18' } });
  const tools = list.json?.result?.tools || [];
  const lm = tools.find(x => x.name === 'list_meters');
  t('tools/list: list_meters mit Titel, Schemas und Hinweisen', list.status === 200 && lm?.title === 'Zähler und Gruppen'
    && lm.inputSchema?.type === 'object' && lm.inputSchema.additionalProperties === false && lm.outputSchema?.type === 'object'
    && lm.annotations?.readOnlyHint === true, list.text.slice(0, 300));
  const call = await mcp({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'list_meters', arguments: {} } }, { key, headers: { 'MCP-Protocol-Version': '2025-06-18' } });
  const sc = call.json?.result?.structuredContent;
  t('tools/call list_meters: Zähler, Text = JSON, Namen unter untrusted', call.status === 200 && call.json.result.isError === false
    && sc?.meters?.[0]?.id === 'm_gas_default' && typeof sc.meters[0].untrusted?.name === 'string'
    && JSON.stringify(JSON.parse(call.json.result.content[0].text)) === JSON.stringify(sc), call.text.slice(0, 300));
  const badArg = await mcp({ jsonrpc: '2.0', id: 4, method: 'tools/call', params: { name: 'list_meters', arguments: { utility: 'kohle' } } }, { key });
  t('falsches Argument: Werkzeugfehler (isError) mit Code, damit das Modell korrigiert', badArg.status === 200
    && badArg.json?.result?.isError === true && badArg.json.result.structuredContent?.error?.code === 'errors.mcp.invalidArguments', badArg.text.slice(0, 300));

  // ── 2026-07-28 (zustandslos) ──
  const disc = await mcp({ jsonrpc: '2.0', id: 'd1', method: 'server/discover' }, { key, modern: true });
  t('server/discover: Fassungen, Fähigkeiten, serverInfo, resultType', disc.status === 200 && disc.json?.result?.resultType === 'complete'
    && JSON.stringify(disc.json.result.supportedVersions) === JSON.stringify(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'])
    && disc.json.result._meta?.['io.modelcontextprotocol/serverInfo']?.name === 'energietracker' && disc.json.result.capabilities?.tools, disc.text.slice(0, 300));
  const mcall = await mcp({ jsonrpc: '2.0', id: 5, method: 'tools/call', params: { name: 'list_meters', arguments: { utility: 'gas' } } }, { key, modern: true });
  t('tools/call modern: resultType complete', mcall.status === 200 && mcall.json?.result?.resultType === 'complete'
    && mcall.json.result.structuredContent?.meters?.every(m => m.utility === 'gas'), mcall.text.slice(0, 300));

  // ── Fehlerfälle ──
  const vers = await mcp({ jsonrpc: '2.0', id: 6, method: 'tools/list', params: { _meta: { 'io.modelcontextprotocol/protocolVersion': '1900-01-01' } } },
    { key, headers: { 'MCP-Protocol-Version': '1900-01-01' } });
  t('Fassung 1900-01-01: 400, -32022, Liste der Fassungen', vers.status === 400 && vers.json?.error?.code === -32022
    && vers.json.error.data?.supported?.includes('2026-07-28') && vers.json.error.data.requested === '1900-01-01', vers.text);
  const mism = await mcp({ jsonrpc: '2.0', id: 7, method: 'tools/list' }, { key, modern: true, headers: { 'MCP-Protocol-Version': '2025-06-18' } });
  t('Kopfzeile passt nicht zu _meta: 400, -32020', mism.status === 400 && mism.json?.error?.code === -32020, mism.text);
  const unk = await mcp({ jsonrpc: '2.0', id: 8, method: 'subscriptions/listen' }, { key, modern: true });
  t('unbekannte Methode (modern): 404, -32601', unk.status === 404 && unk.json?.error?.code === -32601 && jsonType(unk), unk.text);
  const batch = await mcp(null, { key, raw: '[{"jsonrpc":"2.0","id":1,"method":"ping"}]' });
  t('Stapel: 400, -32600', batch.status === 400 && batch.json?.error?.code === -32600, batch.text);
  const parse = await mcp(null, { key, raw: '{kein json' });
  t('kein JSON: 400, -32700', parse.status === 400 && parse.json?.error?.code === -32700, parse.text);
  const get = await fetch(`${BASE}/api/mcp`, { headers: { Authorization: `Bearer ${key}` } });
  t('GET: 405 mit Allow (kein Server-Stream)', get.status === 405 && get.headers.get('allow') === 'POST, OPTIONS', `${get.status} ${get.headers.get('allow')}`);
  const nokey = await mcp({ jsonrpc: '2.0', id: 9, method: 'ping' });
  t('ohne Schlüssel: 401 mit WWW-Authenticate', nokey.status === 401 && nokey.headers.get('www-authenticate') === 'Bearer realm="energietracker"'
    && nokey.json?.error?.data?.code === 'errors.mcp.keyRequired', `${nokey.status} ${nokey.text}`);
  const rk = await mcp({ jsonrpc: '2.0', id: 10, method: 'ping' }, { key: readKey });
  t('Lese-Schlüssel der übrigen API: 403 insufficient_scope', rk.status === 403 && /insufficient_scope/.test(rk.headers.get('www-authenticate') || '')
    && rk.json?.error?.data?.code === 'errors.mcp.keyScope', `${rk.status} ${rk.text}`);
  const foreign = await mcp({ jsonrpc: '2.0', id: 11, method: 'ping' }, { key, headers: { Origin: 'http://evil.example' } });
  t('fremder Ursprung: 403', foreign.status === 403, `${foreign.status}`);
  const all = [init, list, call, disc, mcall, vers, nokey, rk].map(r => r.text).join('');
  t('kein Schlüssel steht in einer Antwort', !all.includes(key) && !all.includes(readKey) && !/etk_[0-9a-f]{8}/.test(all));

  // ── Paket protokoll: Pflicht-Kopfzeilen 2026-07-28 ──
  const callMsg = { jsonrpc: '2.0', id: 20, method: 'tools/call', params: { name: 'list_meters', arguments: {} } };
  const noVer = await mcp(callMsg, { key, modern: true, omit: ['MCP-Protocol-Version'] });
  t('modern ohne MCP-Protocol-Version: 400, -32020', noVer.status === 400 && noVer.json?.error?.code === -32020
    && noVer.json.error.data?.code === 'errors.mcp.headerMissing' && noVer.json.error.data.header === 'MCP-Protocol-Version', noVer.text);
  const noMethod = await mcp(callMsg, { key, modern: true, omit: ['Mcp-Method'] });
  t('modern ohne Mcp-Method: 400, -32020', noMethod.status === 400 && noMethod.json?.error?.data?.header === 'Mcp-Method', noMethod.text);
  const noName = await mcp(callMsg, { key, modern: true, omit: ['Mcp-Name'] });
  t('tools/call ohne Mcp-Name: 400, -32020', noName.status === 400 && noName.json?.error?.data?.header === 'Mcp-Name', noName.text);
  const otherName = await mcp(callMsg, { key, modern: true, headers: { 'Mcp-Name': 'list_readings' } });
  t('Mcp-Name passt nicht: 400 headerMismatch', otherName.status === 400 && otherName.json?.error?.data?.code === 'errors.mcp.headerMismatch', otherName.text);
  const b64 = await mcp(callMsg, { key, modern: true, headers: { 'Mcp-Name': `=?base64?${Buffer.from('list_meters').toString('base64')}?=` } });
  t('Mcp-Name als =?base64?…?=: angenommen', b64.status === 200 && b64.json?.result?.isError === false, b64.text.slice(0, 200));
  const mping = await mcp({ jsonrpc: '2.0', id: 21, method: 'ping' }, { key, modern: true });
  t('ping gibt es in 2026-07-28 nicht: 404, -32601', mping.status === 404 && mping.json?.error?.code === -32601, mping.text);
  const mlist = await mcp({ jsonrpc: '2.0', id: 22, method: 'tools/list' }, { key, modern: true });
  t('tools/list und server/discover: ttlMs und cacheScope private', mlist.json?.result?.ttlMs === 300000 && mlist.json.result.cacheScope === 'private'
    && disc.json?.result?.ttlMs === 300000 && disc.json.result.cacheScope === 'private' && list.json?.result?.ttlMs === undefined, mlist.text.slice(0, 200));
  const badKey = await mcp({ jsonrpc: '2.0', id: 23, method: 'ping' }, { key: 'etk_' + '0'.repeat(48) });
  t('unbekannter Schlüssel: 401 mit error="invalid_token"', badKey.status === 401
    && badKey.headers.get('www-authenticate') === 'Bearer realm="energietracker", error="invalid_token"', `${badKey.status} ${badKey.headers.get('www-authenticate')}`);

  // ── Ratenbegrenzung: 120 Anfragen je Minute und Schlüssel ──
  const busy = (await api('POST', '/api/auth/keys', { name: 'KI viel', scope: 'mcp' })).key;
  let allOk = true;
  for (let i = 0; i < 120; i++) {
    const r = await mcp({ jsonrpc: '2.0', id: 100 + i, method: 'ping' }, { key: busy });
    if (r.status !== 200) { allOk = false; break; }
  }
  const over = await mcp({ jsonrpc: '2.0', id: 300, method: 'ping' }, { key: busy });
  const retry = Number(over.headers.get('retry-after'));
  t('120 Anfragen gehen, die 121. bekommt 429 mit Retry-After', allOk && over.status === 429 && over.json?.error?.code === -31005
    && over.json.error.data?.code === 'errors.mcp.rateLimited' && retry >= 1 && retry <= 60 && over.json.error.data.retry_after === retry && jsonType(over),
    `${over.status} ${over.headers.get('retry-after')} ${over.text}`);
  const other = await mcp({ jsonrpc: '2.0', id: 301, method: 'ping' }, { key });
  t('ein anderer Schlüssel ist nicht betroffen', other.status === 200, other.text);

  // ── Zugriffsprotokoll ──
  const journal = await api('GET', '/api/mcp/journal?limit=500');
  const jtext = JSON.stringify(journal);
  t('Zugriffsprotokoll: neueste zuerst, mit Ergebnis und Code, ohne Schlüssel', Array.isArray(journal) && journal.length >= 130
    && journal[0].result === 'ok' && journal[1].result === 'rate_limited' && journal[1].code === 'errors.mcp.rateLimited'
    && journal.some(e => e.code === 'errors.mcp.headerMissing') && !jtext.includes(key) && !jtext.includes(busy) && !/etk_/.test(jtext),
    jtext.slice(0, 300));

  await api('PATCH', '/api/settings', { mcp_enabled: false });
  const offAgain = await mcp({ jsonrpc: '2.0', id: 12, method: 'ping' }, { key });
  t('wieder ausgeschaltet: 403', offAgain.status === 403 && offAgain.json?.error?.data?.code === 'errors.mcp.disabled', offAgain.text);

  // ── zuletzt: Sperre nach 20 falschen Schlüsseln (sperrt 127.0.0.1 für den Rest des Laufs) ──
  await api('PATCH', '/api/settings', { mcp_enabled: true });
  let fails = 0;
  for (let i = 0; i < 19; i++) if ((await mcp({ jsonrpc: '2.0', id: 400 + i, method: 'ping' }, { key: 'etk_falsch' })).status === 401) fails++;
  const locking = await mcp({ jsonrpc: '2.0', id: 420, method: 'ping' }, { key: 'etk_falsch' });
  const lockedValid = await mcp({ jsonrpc: '2.0', id: 421, method: 'ping' }, { key });
  t('19 × 401, der 20. falsche Schlüssel sperrt: 429 auch für gültige', fails === 19 && locking.status === 429
    && locking.json?.error?.data?.code === 'errors.mcp.keyLocked' && locking.headers.get('retry-after') === '300'
    && lockedValid.status === 429 && lockedValid.json?.error?.data?.code === 'errors.mcp.keyLocked', `${fails} ${locking.status} ${lockedValid.status}`);
} catch (e) {
  t('MCP-Protokoll', false, e.stack || e.message);
} finally {
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
  console.log(`\nmcp-protocol: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
}
