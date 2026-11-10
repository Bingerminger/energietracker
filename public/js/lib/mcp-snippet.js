// =====================================================================
// v3.12.0 (F1037, Paket oberflaeche) — Vorlagen für KI-Assistenten (MCP).
//
// Fertige Einträge für verbreitete Programme, die über Streamable HTTP mit
// festem Schlüssel im Kopf `Authorization: Bearer etk_…` verbinden. Formate nach
// den Dokumentationen der Hersteller (abgerufen 2026-11-03):
//  - Claude Code: `claude mcp add --transport http … --header "…"`, oder als
//    `.mcp.json` mit `mcpServers`, `type: "http"` und `${VAR}` im Kopf
//  - VS Code: `.vscode/mcp.json` mit `servers` (nicht `mcpServers`), `type:
//    "http"`; der Schlüssel kommt über `inputs` (promptString, password) und
//    `${input:id}`, VS Code fragt einmal und speichert ihn sicher
//  - Cursor: `~/.cursor/mcp.json`, `mcpServers` mit `url` und `headers`, ohne type
//  - LM Studio (ab 0.3.17): mcp.json in der Schreibweise von Cursor
//  - Open WebUI (ab 0.6.31): kein Dateieintrag, ein Formular „MCP (Streamable
//    HTTP)“ mit Auth „Bearer“ und dem Schlüssel im Feld „Key“
//
// Lektion 38: Eine Vorlage, die Nutzer kopieren, ist Code. Werte stehen deshalb
// JSON-gequotet (JSON.stringify) bzw. in der Shell in einfachen Anführungszeichen;
// eine präparierte Adresse ändert die Vorlage nicht (wie lib/ha-snippet.js, N1020).
// Geprüft von tests/mcp-snippet.test.mjs.
// =====================================================================

/** Name des Eintrags in jedem Programm */
export const SERVER_NAME = 'energietracker';
/** Umgebungsvariable für den Schlüssel (Claude Code, `.mcp.json`) */
export const KEY_ENV = 'ENERGIETRACKER_KEY';
/** Kennung der Eingabe in VS Code (`${input:…}`) */
export const VSCODE_INPUT = 'energietracker-key';
/** Platzhalter, solange in dieser Ansicht kein Schlüssel angelegt wurde */
export const KEY_PLACEHOLDER = 'etk_…';
/** Platzhalter für den Port, den man in der App für Home Assistant freigibt */
export const PORT_PLACEHOLDER = 'PORT';
/**
 * Pfad der Schnittstelle. Mit api.php geht er auf jedem Webserver (nginx im
 * Docker-Image, Apache mit der mitgelieferten .htaccess, `php -S` mit router.php);
 * `/api/mcp` ohne api.php nur, wo der Webserver /api/ umschreibt (nginx, router.php).
 */
export const MCP_PATH = '/api.php/api/mcp';

/**
 * Programme in der Reihenfolge der Karte; `file` = wohin der Eintrag gehört (leer: Befehl/Formular).
 * v3.12.0 (Entscheidung 2026-11-04) — geprüft wird nur Open WebUI mit lokalem Ollama: zuerst und empfohlen.
 * `experimental`: Die Vorlage folgt der Anleitung des Herstellers, geprüft ist sie nicht (die Karte sagt es).
 */
export const MCP_CLIENTS = [
  { id: 'openwebui', label: 'Open WebUI', recommended: true },
  { id: 'claude-code', label: 'Claude Code', experimental: true },
  { id: 'vscode', label: 'VS Code', experimental: true },
  { id: 'cursor', label: 'Cursor', experimental: true },
  { id: 'lmstudio', label: 'LM Studio', experimental: true },
];

/**
 * Basisadresse dieser Installation, wie ein Assistent im Heimnetz sie aufruft.
 * Unter Home-Assistant-Ingress (`ingress` = Hostname des Containers) taugt
 * weder die Adresszeile (braucht eine HA-Sitzung) noch der Containername (nur
 * im Netz von Home Assistant): Dann der Rechner von Home Assistant mit dem Port,
 * den man in der App für 80/tcp freigibt, als Platzhalter.
 */
export function mcpBaseUrl(loc = globalThis.location, ingress = null) {
  if (ingress) return `http://${loc.hostname}:${PORT_PLACEHOLDER}`;
  return `${loc.origin}${loc.pathname.replace(/\/[^/]*$/, '')}`.replace(/\/$/, '');
}

/** Adresse der Schnittstelle zu einer Basisadresse. */
export function mcpUrl(base) {
  return `${String(base || '').replace(/\/$/, '')}${MCP_PATH}`;
}

/** Wert in einfachen Anführungszeichen für sh/bash/zsh (`'` → `'\''`). */
export function shellQuote(v) {
  return `'${String(v ?? '').replace(/'/g, `'\\''`)}'`;
}

const json = (o) => JSON.stringify(o, null, 2);
const bearer = (key) => `Bearer ${key || KEY_PLACEHOLDER}`;

/** Claude Code: Befehl im Terminal, Bereich „user“ (gilt in allen Projekten). */
export function claudeCodeCommand(url, key) {
  return `claude mcp add --transport http --scope user ${SERVER_NAME} ${shellQuote(url)} --header ${shellQuote(`Authorization: ${bearer(key)}`)}`;
}

/** Claude Code: `.mcp.json` fürs Projekt; der Schlüssel kommt aus der Umgebung, nie aus der Datei. */
export function claudeCodeJson(url) {
  return json({ mcpServers: { [SERVER_NAME]: { type: 'http', url, headers: { Authorization: `Bearer \${${KEY_ENV}}` } } } });
}

/** VS Code: `.vscode/mcp.json`; `prompt` ist der Text, mit dem VS Code nach dem Schlüssel fragt. */
export function vscodeJson(url, prompt = '') {
  return json({
    servers: { [SERVER_NAME]: { type: 'http', url, headers: { Authorization: `Bearer \${input:${VSCODE_INPUT}}` } } },
    inputs: [{ type: 'promptString', id: VSCODE_INPUT, description: String(prompt || SERVER_NAME), password: true }],
  });
}

/** Cursor und LM Studio: `mcpServers` mit `url` und `headers` (ohne `type`). */
export function cursorJson(url, key) {
  return json({ mcpServers: { [SERVER_NAME]: { url, headers: { Authorization: bearer(key) } } } });
}

/** Open WebUI: die Werte für das Formular, mit den Feldnamen der Oberfläche von Open WebUI. */
export function openWebUiFields(url, key) {
  return [
    'Type: MCP (Streamable HTTP)',
    `URL:  ${url}`,
    'Auth: Bearer',
    `Key:  ${key || KEY_PLACEHOLDER}`,
  ].join('\n');
}

/**
 * Blöcke je Programm für die Karte: `[{id, file, text, secret}]`. `file` nennt
 * die Datei (leer bei Befehl oder Formular), `secret`, ob der Schlüssel im Text
 * steht (dann füllt die Karte ihn nach dem Anlegen ein).
 * @param {string} clientId  aus MCP_CLIENTS
 * @param {{url: string, key?: string, prompt?: string}} o
 */
export function mcpBlocks(clientId, { url, key = '', prompt = '' }) {
  switch (clientId) {
    case 'claude-code': return [
      { id: 'claude-code-cli', file: '', text: claudeCodeCommand(url, key), secret: true },
      { id: 'claude-code-json', file: '.mcp.json', text: claudeCodeJson(url), secret: false },
    ];
    case 'vscode': return [{ id: 'vscode', file: '.vscode/mcp.json', text: vscodeJson(url, prompt), secret: false }];
    case 'cursor': return [{ id: 'cursor', file: '~/.cursor/mcp.json', text: cursorJson(url, key), secret: true }];
    case 'lmstudio': return [{ id: 'lmstudio', file: 'mcp.json', text: cursorJson(url, key), secret: true }];
    case 'openwebui': return [{ id: 'openwebui', file: '', text: openWebUiFields(url, key), secret: true }];
    default: return [];
  }
}
