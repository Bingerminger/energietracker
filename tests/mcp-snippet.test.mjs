// =====================================================================
// Vorlagen für KI-Assistenten (v3.12.0, F1037, Paket oberflaeche).
//
//   node tests/mcp-snippet.test.mjs
//
// Ohne Server. Prüft public/js/lib/mcp-snippet.js gegen die Formate der
// Programme (Stand der Hersteller-Dokumentation 2026-11-03): Claude Code
// (Befehl und .mcp.json mit ${VAR}), VS Code (`servers`, `type: http`, Schlüssel
// über `inputs` mit password), Cursor und LM Studio (`mcpServers`, url, headers,
// ohne type), Open WebUI (Formular). Dazu: Werte aus der Adresse können keine
// Vorlage verändern (JSON- bzw. Shell-Quoting), der Schlüssel steht nur dort, wo
// das Programm keine Umgebung oder Abfrage kennt, die Adresse geht über api.php,
// und die Hinweise im Katalog nennen dieselben Namen wie die Vorlagen.
// =====================================================================

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
  MCP_CLIENTS, MCP_PATH, SERVER_NAME, KEY_ENV, VSCODE_INPUT, KEY_PLACEHOLDER, PORT_PLACEHOLDER,
  mcpBaseUrl, mcpUrl, shellQuote, claudeCodeCommand, claudeCodeJson, vscodeJson, cursorJson, openWebUiFields, mcpBlocks,
} from '../public/js/lib/mcp-snippet.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const catalog = Object.fromEntries(LANGS.map(l => [l, JSON.parse(readFileSync(join(ROOT, `public/locales/${l}.json`), 'utf8'))]));
const get = (obj, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);

let failed = 0, passed = 0;
function ok(cond, label, info = '') {
  if (cond) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}${info ? ' — ' + info : ''}`);
}
const parse = (s) => { try { return JSON.parse(s); } catch { return null; } };

const URL_ = 'http://192.168.178.20:8080/api.php/api/mcp';
const KEY = 'etk_0123456789abcdef0123456789abcdef0123456789abcdef';

// ── Adresse ─────────────────────────────────────────────────────────
{
  ok(MCP_PATH === '/api.php/api/mcp', 'Pfad über api.php (geht mit jedem Webserver)');
  ok(mcpBaseUrl({ origin: 'http://nas.local', pathname: '/energietracker/index.php', hostname: 'nas.local' }) === 'http://nas.local/energietracker',
    'Basis: Unterverzeichnis ohne Dateiname');
  ok(mcpBaseUrl({ origin: 'http://192.168.178.20:8080', pathname: '/', hostname: '192.168.178.20' }) === 'http://192.168.178.20:8080',
    'Basis: Wurzel ohne Schrägstrich am Ende');
  const ingress = mcpBaseUrl({ origin: 'https://ha.local:8123', pathname: '/api/hassio_ingress/abc/', hostname: 'ha.local' }, '1a2b3c4d-energietracker');
  ok(ingress === `http://ha.local:${PORT_PLACEHOLDER}`, 'Ingress: Rechner von Home Assistant mit Port-Platzhalter, nicht der Containername', ingress);
  ok(mcpUrl('http://nas.local/energietracker/') === 'http://nas.local/energietracker/api.php/api/mcp', 'Adresse ohne doppelten Schrägstrich');
}

// ── Claude Code ─────────────────────────────────────────────────────
{
  const cmd = claudeCodeCommand(URL_, KEY);
  ok(cmd === `claude mcp add --transport http --scope user ${SERVER_NAME} '${URL_}' --header 'Authorization: Bearer ${KEY}'`,
    'Claude Code: Befehl mit Transport http, Bereich user, Kopfzeile', cmd);
  ok(claudeCodeCommand(URL_, '').includes(`Bearer ${KEY_PLACEHOLDER}'`), 'Claude Code: ohne Schlüssel der Platzhalter');
  const j = parse(claudeCodeJson(URL_));
  const e = j?.mcpServers?.[SERVER_NAME];
  ok(!!e && e.type === 'http' && e.url === URL_, '.mcp.json: mcpServers, type http, url', JSON.stringify(j));
  ok(e?.headers?.Authorization === `Bearer \${${KEY_ENV}}`, '.mcp.json: Schlüssel aus der Umgebung');
  ok(!/etk_/.test(claudeCodeJson(URL_)), '.mcp.json: kein Schlüssel in der Datei');
  ok(!/^(ANTHROPIC|CLAUDE)_/.test(KEY_ENV), 'Umgebungsvariable ohne reservierten Namen (Claude Code liest solche im Kopf leer)');
}

// ── VS Code ─────────────────────────────────────────────────────────
{
  const j = parse(vscodeJson(URL_, 'API-Schlüssel für den Energietracker'));
  const e = j?.servers?.[SERVER_NAME];
  ok(!!e && !j.mcpServers && e.type === 'http' && e.url === URL_, 'VS Code: servers (nicht mcpServers), type http, url', JSON.stringify(j));
  ok(e?.headers?.Authorization === `Bearer \${input:${VSCODE_INPUT}}`, 'VS Code: Schlüssel über ${input:…}');
  const input = (j?.inputs || []).find(i => i.id === VSCODE_INPUT);
  ok(input?.type === 'promptString' && input.password === true && input.description === 'API-Schlüssel für den Energietracker',
    'VS Code: Eingabe verdeckt, mit Beschreibung', JSON.stringify(input));
  ok(!/etk_/.test(vscodeJson(URL_)), 'VS Code: kein Schlüssel in der Datei');
}

// ── Cursor, LM Studio, Open WebUI ───────────────────────────────────
{
  const j = parse(cursorJson(URL_, KEY));
  const e = j?.mcpServers?.[SERVER_NAME];
  ok(!!e && !('type' in e) && e.url === URL_ && e.headers?.Authorization === `Bearer ${KEY}`,
    'Cursor/LM Studio: mcpServers mit url und headers, ohne type', JSON.stringify(j));
  ok(parse(cursorJson(URL_, ''))?.mcpServers?.[SERVER_NAME]?.headers?.Authorization === `Bearer ${KEY_PLACEHOLDER}`, 'Cursor: Platzhalter ohne Schlüssel');
  const owui = openWebUiFields(URL_, KEY).split('\n');
  ok(owui[0] === 'Type: MCP (Streamable HTTP)' && owui.includes(`URL:  ${URL_}`) && owui.includes('Auth: Bearer') && owui.includes(`Key:  ${KEY}`),
    'Open WebUI: Typ, Adresse, Bearer, Schlüssel', owui.join(' | '));
}

// ── Quoting: eine präparierte Adresse verändert keine Vorlage ───────
{
  const evil = `http://nas.local/x'; rm -rf ~; echo '"}, "evil": {"url": "x`;
  for (const [name, text] of [['.mcp.json', claudeCodeJson(evil)], ['VS Code', vscodeJson(evil, 'p"rompt')], ['Cursor', cursorJson(evil, KEY)]]) {
    const j = parse(text);
    ok(!!j && !JSON.stringify(j).includes('"evil":{') && (j.mcpServers || j.servers)[SERVER_NAME].url === evil, `${name}: JSON bleibt gültig, Adresse unverändert`);
  }
  ok(shellQuote(`a'b`) === `'a'\\''b'`, 'Shell: einfaches Anführungszeichen maskiert');
  // ein Wort der Shell zurücklesen: nur '…'-Abschnitte und \x, sonst ist es kein einzelnes Wort
  const unquote = (s) => {
    let out = '';
    for (let i = 0; i < s.length;) {
      if (s[i] === "'") { const j = s.indexOf("'", i + 1); if (j < 0) return null; out += s.slice(i + 1, j); i = j + 1; }
      else if (s[i] === '\\') { out += s[i + 1]; i += 2; }
      else return null;
    }
    return out;
  };
  ok(unquote(shellQuote(evil)) === evil, 'Shell: präparierte Adresse bleibt ein Wort mit gleichem Inhalt');
  const cmd = claudeCodeCommand(evil, KEY);
  ok(cmd.includes(` ${shellQuote(evil)} --header `) && cmd.endsWith(` --header ${shellQuote(`Authorization: Bearer ${KEY}`)}`),
    'Claude Code: Adresse und Kopfzeile je ein Wort', cmd);
}

// ── Blöcke der Karte ────────────────────────────────────────────────
{
  ok(MCP_CLIENTS.map(c => c.id).join() === 'openwebui,claude-code,vscode,cursor,lmstudio', 'fünf Programme, Open WebUI zuerst');
  ok(MCP_CLIENTS[0].recommended === true && !MCP_CLIENTS[0].experimental, 'Open WebUI ist die empfohlene Vorlage');
  ok(MCP_CLIENTS.slice(1).every(c => c.experimental === true), 'jede andere Vorlage trägt die Kennzeichnung');
  for (const c of MCP_CLIENTS) {
    const withKey = mcpBlocks(c.id, { url: URL_, key: KEY, prompt: 'p' });
    const without = mcpBlocks(c.id, { url: URL_, prompt: 'p' });
    ok(withKey.length > 0 && withKey.every(b => b.text.includes(URL_)), `${c.label}: jede Vorlage enthält die Adresse`);
    ok(withKey.every(b => b.text.includes(KEY) === b.secret), `${c.label}: Schlüssel genau in den Vorlagen mit secret`);
    ok(without.every(b => !/etk_[0-9a-f]/.test(b.text)), `${c.label}: ohne angelegten Schlüssel kein Schlüssel`);
    ok(withKey.filter(b => b.file.endsWith('.json')).every(b => parse(b.text) !== null), `${c.label}: JSON-Dateien sind gültiges JSON`);
  }
  ok(mcpBlocks('unbekannt', { url: URL_ }).length === 0, 'unbekanntes Programm: nichts');
}

// ── Katalog: Hinweise je Programm, gleiche Namen wie die Vorlagen ───
{
  const hintKey = (id) => (id === 'claude-code' ? ['settings.mcp.client.claudeCodeCli', 'settings.mcp.client.claudeCodeJson'] : [`settings.mcp.client.${id}`]);
  for (const l of LANGS) {
    const missing = MCP_CLIENTS.flatMap(c => hintKey(c.id)).filter(k => typeof get(catalog[l], k) !== 'string');
    ok(!missing.length, `${l}: Hinweis je Programm`, missing.join(', '));
    ok(get(catalog[l], 'settings.mcp.client.claudeCodeJson')?.includes(`<code>${KEY_ENV}</code>`), `${l}: .mcp.json-Hinweis nennt ${KEY_ENV}`);
    ok(get(catalog[l], 'settings.mcp.client.vscode')?.includes('<code>.vscode/mcp.json</code>'), `${l}: VS-Code-Hinweis nennt die Datei der Vorlage`);
    ok(get(catalog[l], 'settings.mcp.client.cursor')?.includes('<code>~/.cursor/mcp.json</code>'), `${l}: Cursor-Hinweis nennt die Datei der Vorlage`);
    ok(get(catalog[l], 'settings.mcp.ingressHint')?.includes(PORT_PLACEHOLDER), `${l}: Ingress-Hinweis nennt den Platzhalter ${PORT_PLACEHOLDER}`);
    ok(/MCP \(Streamable HTTP\)/.test(get(catalog[l], 'settings.mcp.client.openwebui') || ''), `${l}: Open-WebUI-Hinweis nennt den Typ wie die Vorlage`);
  }
  const files = Object.fromEntries(MCP_CLIENTS.map(c => [c.id, mcpBlocks(c.id, { url: URL_ }).map(b => b.file)]));
  ok(files.vscode[0] === '.vscode/mcp.json' && files.cursor[0] === '~/.cursor/mcp.json' && files['claude-code'][1] === '.mcp.json',
    'Dateinamen der Vorlagen', JSON.stringify(files));
}

// v3.12.0 (integration) — App und Anleitung zeigen dieselben Vorlagen (wie ha-snippet.test.mjs): Karte und
// docs/anleitungen/ki-assistent.md (DE, EN) hatten zwei Fassungen (anderer Variablenname, anderer Bereich).
// Die Anleitung nennt die Adresse ihrer Tabelle („Docker“), sonst bis aufs Zeichen; JSON als Daten verglichen.
{
  const DOC_URL = 'http://192.168.178.20:8080/api/mcp';
  const fenced = (md, heading, lang) => {
    const at = md.indexOf(`\n### ${heading}\n`);
    ok(at >= 0, `Anleitung: Abschnitt ${heading}`);
    const next = md.indexOf('\n#', at + 5);
    const body = md.slice(at, next < 0 ? undefined : next);
    return [...body.matchAll(new RegExp('```' + lang + '\\n([\\s\\S]*?)\\n```', 'g'))].map(m => m[1]);
  };
  const sameJson = (a, b, label) => ok(JSON.stringify(sortKeys(JSON.parse(a))) === JSON.stringify(sortKeys(JSON.parse(b))), `${label}\n      App:  ${b}\n      Doku: ${a}`);
  const sortKeys = (v) => (Array.isArray(v) ? v.map(sortKeys) : v && typeof v === 'object'
    ? Object.fromEntries(Object.keys(v).sort().map(k => [k, sortKeys(v[k])])) : v);
  for (const [rel, cat, cursorHeading] of [
    ['docs/anleitungen/ki-assistent.md', 'public/locales/de.json', 'Cursor und LM Studio'],
    ['docs/en/anleitungen/ki-assistent.md', 'public/locales/en.json', 'Cursor and LM Studio'],
  ]) {
    const md = readFileSync(join(ROOT, rel), 'utf8');
    const prompt = JSON.parse(readFileSync(join(ROOT, cat), 'utf8')).settings.mcp.vscodePrompt;
    const [cli, project] = mcpBlocks('claude-code', { url: DOC_URL });
    ok(fenced(md, 'Claude Code', 'bash')[0] === cli.text, `${rel}: Befehl Claude Code\n      App:  ${cli.text}\n      Doku: ${fenced(md, 'Claude Code', 'bash')[0]}`);
    sameJson(fenced(md, 'Claude Code', 'json')[0], project.text, `${rel}: .mcp.json`);
    ok(md.includes(`\`${project.file}\``), `${rel}: nennt ${project.file}`);
    ok(md.includes(`\`${KEY_ENV}\``), `${rel}: Umgebungsvariable ${KEY_ENV}`);
    const vs = mcpBlocks('vscode', { url: DOC_URL, prompt })[0];
    sameJson(fenced(md, 'VS Code', 'json')[0], vs.text, `${rel}: VS Code`);
    ok(md.includes(`\`${vs.file}\``), `${rel}: nennt ${vs.file}`);
    const cur = mcpBlocks('cursor', { url: DOC_URL })[0];
    sameJson(fenced(md, cursorHeading, 'json')[0], cur.text, `${rel}: Cursor`);
    sameJson(fenced(md, cursorHeading, 'json')[0], mcpBlocks('lmstudio', { url: DOC_URL })[0].text, `${rel}: LM Studio`);
    ok(md.includes(`\`${cur.file}\``), `${rel}: nennt ${cur.file}`);
    // Open WebUI ist ein Formular: dieselben Namen und Werte wie openWebUiFields()
    const web = md.slice(md.indexOf('\n### Open WebUI\n'), md.indexOf('\n## ', md.indexOf('\n### Open WebUI\n'))).replace(/\s+/g, ' ');
    const fields = mcpBlocks('openwebui', { url: DOC_URL })[0].text;
    ok(fields.includes('Type: MCP (Streamable HTTP)') && web.includes('MCP (Streamable HTTP)'), `${rel}: Open WebUI Typ`);
    ok(fields.includes('Auth: Bearer') && web.includes('**Bearer**'), `${rel}: Open WebUI Bearer`);
    // kein zweiter Name für dieselbe Sache
    ok(!/ET_MCP_KEY|et-key/.test(md), `${rel}: alte Namen`);
    for (const c of MCP_CLIENTS) ok(md.includes(c.label), `${rel}: nennt ${c.label}`);
  }
}

console.log(`mcp-snippet: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
