<?php
declare(strict_types=1);

/**
 * v3.7.0 (Paket cloud) — Nachgebauter Cloud-Dienst für die Tests (Router für
 * `php -S`): die Messages-Schnittstelle (`POST /v1/messages`) und eine
 * OpenAI-kompatible (`POST /v1/chat/completions`). Kein echter Dienst wird
 * je angesprochen.
 *
 * Das Verhalten wählt der API-Schlüssel (`…-ok`, `…-401`, `…-429`, `…-529`,
 * `…-refusal`, `…-maxtokens`, `…-maxonce`, `…-ctxwindow`, `…-slow`, `…-redirect`, `…-nobeta`, `…-echo`,
 * `…-length`, `…-filter`). Die Form der Anfrage wird wie beim echten Dienst
 * geprüft; eine falsche Form ergibt 400. Die letzte Anfrage steht in der Datei
 * aus FAKE_CLOUD_LOG (Kopfzeilen und Körper), für Aussagen im Test.
 *
 * Alle Inhalte sind erfunden.
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
}
if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
$raw = (string)file_get_contents('php://input');
$log = getenv('FAKE_CLOUD_LOG');
if (is_string($log) && $log !== '' && $path !== '/elsewhere') {
    file_put_contents($log, json_encode(['path' => $path, 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'headers' => $headers, 'body' => $raw]));
}

function answer(int $status, array $body, array $extra = []): bool
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($extra as $k => $v) header("$k: $v");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

function apiError(int $status, string $type, string $message, array $extra = []): bool
{
    return answer($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], $extra);
}

if ($path === '/elsewhere') {
    // Ziel einer Weiterleitung: antwortet „gut“ — käme ein Test hier an, wäre ihr gefolgt worden
    return answer(200, ['type' => 'message', 'content' => [['type' => 'text', 'text' => 'type: invoice']], 'stop_reason' => 'end_turn']);
}

$req = json_decode($raw, true);
$scenario = static function (string $key): string {
    return preg_match('/-(ok|401|429|529|refusal|maxtokens|maxonce|ctxwindow|slow|redirect|nobeta|echo|length|filter|empty)$/', $key, $m) ? $m[1] : 'unknown';
};

// ── v3.7.0 (Karte „Auslesen“) — Modelllisten: GET …/models ──────────────────
// Claude (`x-api-key`), OpenAI-kompatibel (Bearer, jeder Pfad auf /models, auch
// /v1beta/openai/models), ohne Schlüssel der eigene Dienst (Ollama /api/tags,
// LM Studio /v1/models). Erfundene Modellnamen.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (str_ends_with($path, '/models') || $path === '/api/tags')) {
    if (isset($headers['x-api-key'])) {
        $s = $scenario($headers['x-api-key']);
        if ($s === 'unknown' || $s === '401') return apiError(401, 'authentication_error', 'invalid x-api-key');
        if (($headers['anthropic-version'] ?? '') !== '2023-06-01') return apiError(400, 'invalid_request_error', 'anthropic-version: missing or unknown');
        if ($path !== '/v1/models' || ($_GET['limit'] ?? '') !== '1000') return apiError(400, 'invalid_request_error', 'GET /v1/models?limit=1000 expected');
        return answer(200, ['data' => [
            ['type' => 'model', 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-09-21T00:00:00Z'],
            ['type' => 'model', 'id' => 'claude-test-small', 'display_name' => 'Claude Test Small', 'created_at' => '2026-01-01T00:00:00Z'],
        ], 'has_more' => false, 'first_id' => 'claude-opus-5-5', 'last_id' => 'claude-test-small']);
    }
    $auth = $headers['authorization'] ?? '';
    if ($auth !== '') {
        $key = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $s = $scenario($key);
        if ($s === 'unknown' || $s === '401') return answer(401, ['error' => ['message' => 'Incorrect API key provided: ' . $key]]);
        if ($s === 'echo') return answer(400, ['error' => ['message' => 'key ' . $key . ' may not list models']]);
        if ($s === 'redirect') { header('Location: /elsewhere', true, 302); return true; }
        if ($s === 'empty') return answer(200, ['object' => 'list', 'data' => []]);
        return answer(200, ['object' => 'list', 'data' => [
            ['id' => 'test-model-a', 'object' => 'model', 'owned_by' => 'example'],
            ['id' => 'vendor/test-model-b:free', 'object' => 'model', 'name' => 'Test Model B'],
            ['id' => 'kein gültiger name', 'object' => 'model'],
            ['object' => 'model'],
        ]]);
    }
    if ($path === '/api/tags') return answer(200, ['models' => [['name' => 'qwen3.8:latest', 'size' => 1], ['name' => 'qwen2.5vl:7b', 'size' => 1]]]);
    return answer(200, ['object' => 'list', 'data' => [['id' => 'qwen3.8', 'object' => 'model']]]);
}

$cite = static fn(string $text, int $page): array => [
    'type' => 'page_location', 'cited_text' => $text, 'document_index' => 0, 'document_title' => null,
    'start_page_number' => $page, 'end_page_number' => $page + 1,
];

// ── Claude: POST /v1/messages ────────────────────────────────────────────────
if ($path === '/v1/messages') {
    $key = $headers['x-api-key'] ?? '';
    $s = $scenario($key);
    if ($key === '' || $s === 'unknown' || $s === '401') return apiError(401, 'authentication_error', 'invalid x-api-key');
    if (($headers['anthropic-version'] ?? '') !== '2023-06-01') return apiError(400, 'invalid_request_error', 'anthropic-version: missing or unknown');
    if (!str_starts_with($headers['content-type'] ?? '', 'application/json')) return apiError(400, 'invalid_request_error', 'content-type');
    if (!is_array($req)) return apiError(400, 'invalid_request_error', 'body is not JSON');
    $content = $req['messages'][0]['content'] ?? null;
    $doc = is_array($content) ? ($content[0] ?? null) : null;
    $problems = [];
    if (!is_string($req['model'] ?? null) || $req['model'] === '') $problems[] = 'model';
    if (!is_int($req['max_tokens'] ?? null) || $req['max_tokens'] < 1) $problems[] = 'max_tokens';
    if (array_key_exists('temperature', $req)) $problems[] = 'temperature is not supported for this model';
    if (isset($req['output_config']['format'])) $problems[] = 'citations and output_config.format cannot be combined';
    if (!is_array($doc) || ($doc['type'] ?? '') !== 'document' || ($doc['citations']['enabled'] ?? false) !== true) $problems[] = 'document block with citations first';
    $src = is_array($doc) ? ($doc['source'] ?? []) : [];
    if (($src['type'] ?? '') === 'base64') {
        $pdf = base64_decode((string)($src['data'] ?? ''), true);
        if (($src['media_type'] ?? '') !== 'application/pdf' || $pdf === false || !str_starts_with($pdf, '%PDF-')) $problems[] = 'document.source: base64 pdf';
    } elseif (($src['type'] ?? '') !== 'text' || ($src['media_type'] ?? '') !== 'text/plain') {
        $problems[] = 'document.source';
    }
    if (!is_array($content) || ($content[1]['type'] ?? '') !== 'text') $problems[] = 'text block after the document';
    if (isset($req['fallbacks'])) {
        if ($s === 'nobeta') return apiError(400, 'invalid_request_error', 'Unexpected value(s) `server-side-fallback-2026-07-01` for the `anthropic-beta` header.');
        if (($headers['anthropic-beta'] ?? '') !== 'server-side-fallback-2026-07-01' || $req['fallbacks'] !== 'default') $problems[] = 'fallbacks need their beta header';
    }
    if ($problems !== []) return apiError(400, 'invalid_request_error', implode('; ', $problems));

    if ($s === '429') return apiError(429, 'rate_limit_error', 'rate limited', ['retry-after' => '17']);
    if ($s === '529') return apiError(529, 'overloaded_error', 'Overloaded');
    if ($s === 'echo') return apiError(400, 'invalid_request_error', 'key ' . $key . ' is not allowed for this model');
    if ($s === 'redirect') { header('Location: /elsewhere', true, 307); return true; }
    if ($s === 'slow') sleep(3);
    if ($s === 'refusal') {
        return answer(200, ['type' => 'message', 'model' => $req['model'], 'content' => [], 'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]]);
    }
    $prompt = (string)($content[1]['text'] ?? '');
    $isClassify = str_contains($prompt, 'Which kind of document');
    // v3.9.0 — `…-maxonce`: abgeschnitten nur unter 32 000 Tokens Budget (die Wiederholung reicht);
    // `…-ctxwindow`: am Kontextfenster, da hilft kein größeres Budget
    if ($s === 'maxtokens' || ($s === 'maxonce' && $req['max_tokens'] < 32000) || $s === 'ctxwindow') {
        return answer(200, ['type' => 'message', 'model' => $req['model'], 'stop_reason' => $s === 'ctxwindow' ? 'model_context_window_exceeded' : 'max_tokens',
            'content' => [['type' => 'text', 'text' => 'period_from: 01.01.2025']]]);
    }
    $blocks = $isClassify
        ? [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => "type: invoice\nconfidence: 0.93"]]
        : [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'period_from: '],
            ['type' => 'text', 'text' => '01.01.2025', 'citations' => [$cite('Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 1)]],
            ['type' => 'text', 'text' => "\nperiod_to: "],
            ['type' => 'text', 'text' => '31.12.2025', 'citations' => [$cite('Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 1)]],
            ['type' => 'text', 'text' => "\ntotal_amount: "],
            // zwei Belege am Block: der mit dem Wert gewinnt
            ['type' => 'text', 'text' => '1.234,56 €', 'citations' => [$cite('Ihr Abrechnungsergebnis', 2), $cite('Rechnungsbetrag 1.234,56 €', 2)]],
            ['type' => 'text', 'text' => "\npaid_advances: "],
            ['type' => 'text', 'text' => '85,00 €', 'citations' => [$cite('Abschlag Januar 85,00 €', 3)]],
            ['type' => 'text', 'text' => "\npaid_advances: "],
            ['type' => 'text', 'text' => '90,00 €', 'citations' => [$cite('Abschlag Februar 90,00 €', 3)]],
            // Feld mit Teilen (Schema des Posteingangs): eine Zeile je Vorkommen
            ['type' => 'text', 'text' => "\nadvances_paid: "],
            ['type' => 'text', 'text' => 'amount = 85,00 € | date = none', 'citations' => [$cite('Abschlag Januar 85,00 €', 3)]],
            ['type' => 'text', 'text' => "\nadvances_paid: "],
            ['type' => 'text', 'text' => 'amount = 90,00 €', 'citations' => [$cite('Abschlag Februar 90,00 €', 3)]],
            ['type' => 'text', 'text' => "\nmeter_number: none\nnot_in_schema: 42"],
        ];
    return answer(200, ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'],
        'content' => $blocks, 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
}

// ── OpenAI-kompatibel: POST /v1/chat/completions ─────────────────────────────
if ($path === '/v1/chat/completions') {
    $auth = $headers['authorization'] ?? '';
    $key = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    $s = $scenario($key);
    if ($key === '' || $s === 'unknown' || $s === '401') return answer(401, ['error' => ['message' => 'Incorrect API key provided: ' . $key, 'type' => 'invalid_request_error']]);
    if (!is_array($req)) return answer(400, ['error' => ['message' => 'body is not JSON']]);
    $problems = [];
    if (($req['temperature'] ?? null) !== 0) $problems[] = 'temperature must be 0';
    if (($req['response_format']['type'] ?? '') !== 'json_object') $problems[] = 'response_format';
    if (($req['messages'][0]['role'] ?? '') !== 'system' || ($req['messages'][1]['role'] ?? '') !== 'user') $problems[] = 'messages';
    if ($problems !== []) return answer(400, ['error' => ['message' => implode('; ', $problems)]]);
    if ($s === '429') return answer(429, ['error' => ['message' => 'rate limited']], ['retry-after' => '9']);
    if ($s === 'redirect') { header('Location: /elsewhere', true, 302); return true; }
    if ($s === 'slow') sleep(3);
    $choice = static fn(string $content, string $finish = 'stop'): bool => answer(200, ['id' => 'cmpl', 'model' => $req['model'],
        'choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]]]);
    if ($s === 'length') return $choice('{"fields": {"period_from": [{"value": "01.0', 'length');
    if ($s === 'filter') return $choice('', 'content_filter');
    $user = (string)($req['messages'][1]['content'] ?? '');
    if (str_contains($user, 'Which kind of document')) return $choice('{"type": "price_change", "confidence": 88}');
    return $choice("```json\n" . json_encode(['fields' => [
        'period_from'   => [['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 'page' => 1]],
        'total_amount'  => ['value' => '1.234,56 €', 'quote' => 'Rechnungsbetrag 1.234,56 €', 'page' => null],
        'paid_advances' => [['value' => '85,00 €', 'quote' => 'Abschlag Januar 85,00 €'], ['value' => '90,00 €', 'quote' => 'Abschlag Februar 90,00 €']],
        'advances_paid' => [['amount' => '85,00 €', 'date' => null, 'quote' => 'Abschlag Januar 85,00 €', 'page' => 3],
                            ['value' => ['amount' => '90,00 €'], 'quote' => 'Abschlag Februar 90,00 €', 'page' => 3]],
        'meter_number'  => [['value' => 'null', 'quote' => null]],
    ]], JSON_UNESCAPED_UNICODE) . "\n```");
}

return answer(404, ['error' => ['message' => 'not here']]);
