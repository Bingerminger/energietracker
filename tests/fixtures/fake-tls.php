<?php
declare(strict_types=1);

/**
 * v3.10.0 (#36) — ein HTTPS-Server mit eigener PKI für HomeTlsTest: ein
 * Dienst im Heimnetz (Home Assistant, Texterkennung, evcc), dessen Zertifikat
 * eine selbst erzeugte Stammzertifizierungsstelle ausgestellt hat.
 *
 * Umgebung: FAKE_TLS_PORT (Port auf 127.0.0.1), FAKE_TLS_PEM (Zertifikat und
 * Schlüssel des Servers in einer Datei). Jede Anfrage bekommt 200 mit einer
 * Modellliste in der Form von Ollama (`GET /api/tags`); für Home Assistant
 * zählt nur der Status.
 */
$port = (int)getenv('FAKE_TLS_PORT');
$ctx = stream_context_create(['ssl' => ['local_cert' => (string)getenv('FAKE_TLS_PEM'), 'verify_peer' => false]]);
$server = stream_socket_server("ssl://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "fake-tls: $errstr\n");
    exit(1);
}
while (true) {
    // Lehnt der Client das Zertifikat ab, scheitert schon der Handshake: dann die nächste Verbindung
    $c = @stream_socket_accept($server, 60);
    if ($c === false) continue;
    stream_set_timeout($c, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = @fread($c, 8192);
        if ($chunk === false || $chunk === '') break;
        $request .= $chunk;
    }
    // den Körper ganz lesen (sonst schließt die Verbindung mit ungelesenen Daten hart)
    $length = preg_match('/^content-length:\s*(\d+)/im', $request, $m) ? (int)$m[1] : 0;
    $have = strlen($request) - (int)strpos($request, "\r\n\r\n") - 4;
    while ($have < $length) {
        $chunk = @fread($c, 8192);
        if ($chunk === false || $chunk === '') break;
        $have += strlen($chunk);
    }
    $body = '{"models":[{"name":"test-model"}]}';
    @fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    @fclose($c);
}
