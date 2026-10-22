<?php
declare(strict_types=1);

/**
 * v3.7.0 (Posteingang) — nachgebauter Ollama-Dienst für InboxLocalProviderTest:
 * `php -S 127.0.0.1:<port> tests/fixtures/fake-ollama.php`. Beantwortet
 * POST /api/chat wie Ollama (`{"message": {"content": "…"}}`) — erst die Art,
 * dann die Felder — und legt jede Anfrage als JSON-Zeile in FAKE_OLLAMA_LOG ab.
 * Ein unbekanntes Modell bekommt 404 wie bei Ollama.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
$log = getenv('FAKE_OLLAMA_LOG');
if ($log) file_put_contents($log, $body . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($path !== '/api/chat' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo '{"error":"not found"}';
    return true;
}
$req = json_decode($body, true) ?: [];
if (($req['model'] ?? '') !== 'qwen3.8') {
    http_response_code(404);
    echo json_encode(['error' => "model '" . ($req['model'] ?? '') . "' not found"]);
    return true;
}
$system = (string)($req['messages'][0]['content'] ?? '');
if (str_contains($system, 'Decide which kind')) {
    // mit Denkblock und Zaun, wie es vorkommt
    $content = "<think>Jahresabrechnung …</think>\n```json\n{\"type\": \"invoice\", \"confidence\": 0.97}\n```";
} else {
    $content = json_encode(['fields' => [
        'period_from' => ['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum: 01.01.2025 bis 31.12.2025', 'page' => 1],
        'reading_new' => ['value' => '13.580', 'quote' => 'Zählerstand neu am 31.12.2025: 13.580 m³', 'page' => 1],
        'advances_paid' => ['value' => [['amount' => '840,00', 'quote' => '6 × 140,00 € = 840,00 €', 'page' => 2]]],
        'total_amount' => '1.722,70',
    ]], JSON_UNESCAPED_UNICODE);
}
echo json_encode(['model' => $req['model'], 'message' => ['role' => 'assistant', 'content' => $content], 'done' => true], JSON_UNESCAPED_UNICODE);
return true;
