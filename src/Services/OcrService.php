<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H2, MKT-08 Stufe 2) — Texterkennung für Zählerfotos über einen
 * eigenen Dienst im Heimnetz (Ollama oder ein OpenAI-kompatibler Server wie
 * LM Studio). Kein Cloud-Dienst: Die Adresse muss auf ein lokales Netz zeigen
 * (Loopback, RFC 1918, Link-local, IPv6-ULA, 100.64.0.0/10 für Tailscale).
 * Geprüft wird bei jedem Aufruf nach der Namensauflösung, und die Verbindung
 * geht an genau die geprüfte Adresse (DNS-Rebinding). Weiterleitungen werden
 * nicht verfolgt.
 *
 * Das Ergebnis füllt nur das Eingabefeld vor — gespeichert wird nie
 * automatisch.
 */
final class OcrService
{
    public const APIS = ['ollama', 'openai'];

    public const PROMPT = 'This photo shows a utility meter (electricity, gas, water or heat). '
        . 'Read the number on its register (the counter digits). Keep the decimal places '
        . 'shown on the register (often red digits or digits after a comma) and use "." as decimal point. '
        . 'Answer only with JSON: {"value": <number>, "confidence": <0..1>}. '
        . 'If you cannot read it, answer {"value": null, "confidence": 0}.';

    /** @var callable(string, string, int, ?string): array{ok:bool, body:?string, http_code:?int, error_code:?string} */
    private $transport;
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(
        private SettingsService $settings,
        private AttachmentService $attachments,
        ?callable $transport = null,
        ?callable $resolver = null,
    ) {
        $this->transport = $transport ?? self::curlPost(...);
        $this->resolver = $resolver ?? self::resolve(...);
    }

    public function enabled(): bool
    {
        return trim((string)$this->settings->get('ocr_endpoint', '')) !== '';
    }

    /**
     * Liest den Zählerstand aus einem Foto-Beleg.
     *
     * @return array{value: ?float, confidence: ?float, raw: string, model: string, duration_ms: int}
     */
    public function read(string $attachmentId): array
    {
        $endpoint = trim((string)$this->settings->get('ocr_endpoint', ''));
        if ($endpoint === '') throw new LocalizedException('errors.ocr.off', [], 'OCR endpoint not configured');
        $a = $this->attachments->get($attachmentId)
            ?? throw new LocalizedException('errors.attachment.notFound', [], "Attachment $attachmentId not found");
        if (($a['kind'] ?? '') !== 'reading_photo' || !str_starts_with((string)$a['mime'], 'image/')) {
            throw new LocalizedException('errors.attachment.wrongKind', ['kind' => 'reading_photo'], 'OCR needs a reading photo');
        }

        $api = in_array($this->settings->get('ocr_api', 'ollama'), self::APIS, true) ? (string)$this->settings->get('ocr_api') : 'ollama';
        $model = trim((string)$this->settings->get('ocr_model', ''));
        $timeout = max(5, min(300, (int)$this->settings->get('ocr_timeout_s', 30)));
        $url = self::requestUrl($endpoint, $api);
        $ip = $this->checkLocal($url);

        $image = base64_encode((string)file_get_contents($this->attachments->path($attachmentId)));
        $body = $api === 'openai'
            ? ['model' => $model, 'temperature' => 0, 'max_tokens' => 100, 'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::PROMPT],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $a['mime'] . ';base64,' . $image]],
                ],
            ]]]
            : ['model' => $model, 'stream' => false, 'format' => 'json', 'options' => ['temperature' => 0], 'messages' => [[
                'role' => 'user', 'content' => self::PROMPT, 'images' => [$image],
            ]]];

        $t0 = hrtime(true);
        $resp = ($this->transport)($url, (string)json_encode($body, JSON_UNESCAPED_SLASHES), $timeout, $ip);
        $ms = (int)round((hrtime(true) - $t0) / 1e6);
        if (!$resp['ok']) {
            $key = ($resp['error_code'] ?? '') === 'timeout' ? 'errors.ocr.timeout' : 'errors.ocr.unreachable';
            throw new LocalizedException($key, ['seconds' => $timeout, 'code' => (string)($resp['http_code'] ?? '')], 'OCR request failed');
        }
        $json = json_decode((string)$resp['body'], true);
        $content = is_array($json)
            ? (string)($api === 'openai' ? ($json['choices'][0]['message']['content'] ?? '') : ($json['message']['content'] ?? ''))
            : '';
        if ($content === '') throw new LocalizedException('errors.ocr.badAnswer', [], 'OCR answer without content');
        $parsed = self::parseAnswer($content);
        return $parsed + ['raw' => mb_substr($content, 0, 500), 'model' => $model, 'duration_ms' => $ms];
    }

    /** Vollständige Adresse je Schnittstelle; eine schon vollständige bleibt. */
    public static function requestUrl(string $endpoint, string $api): string
    {
        $base = rtrim(trim($endpoint), '/');
        if ($api === 'openai') {
            if (str_ends_with($base, '/chat/completions')) return $base;
            return str_ends_with($base, '/v1') ? $base . '/chat/completions' : $base . '/v1/chat/completions';
        }
        return str_ends_with($base, '/api/chat') ? $base : $base . '/api/chat';
    }

    /**
     * Löst den Host auf und verlangt, dass jede Adresse lokal ist.
     *
     * @return string die Adresse, mit der verbunden wird
     */
    public function checkLocal(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) throw new LocalizedException('errors.ocr.unreachable', ['seconds' => 0, 'code' => ''], "OCR host $host does not resolve");
        foreach ($ips as $ip) {
            if (!self::isLocalIp($ip)) throw new LocalizedException('errors.ocr.notLocal', ['host' => $host], "OCR host $host is not local ($ip)");
        }
        return $ips[0];
    }

    /** Loopback, RFC 1918, Link-local, 100.64.0.0/10, IPv6 ::1, fc00::/7, fe80::/10. */
    public static function isLocalIp(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) $ip = $m[1];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach ([['127.0.0.0', 8], ['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['169.254.0.0', 16], ['100.64.0.0', 10]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) return true;
            }
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) return false;
            if ($bin === inet_pton('::1')) return true;
            $first = ord($bin[0]);
            return ($first & 0xFE) === 0xFC                                   // fc00::/7 (ULA)
                || ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80);      // fe80::/10
        }
        return false;
    }

    /**
     * Antwort des Modells → Zahl. Nimmt JSON (auch in ```-Zäunen oder mitten
     * im Text) oder die erste Zahl im Text; „12345,6" und „12.345,6" werden
     * zu 12345.6.
     *
     * @return array{value: ?float, confidence: ?float}
     */
    public static function parseAnswer(string $content): array
    {
        $value = null; $confidence = null;
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) {
                $value = self::number($j['value'] ?? null);
                $c = $j['confidence'] ?? null;
                if (is_numeric($c)) {
                    $c = (float)$c;
                    if ($c > 1 && $c <= 100) $c /= 100;
                    $confidence = $c >= 0 && $c <= 1 ? round($c, 2) : null;
                }
                return ['value' => $value, 'confidence' => $value === null ? null : $confidence];
            }
        }
        if (preg_match('/\d[\d.,\' ]*\d|\d/', $content, $m)) $value = self::number($m[0]);
        return ['value' => $value, 'confidence' => null];
    }

    private static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return is_finite((float)$v) && $v >= 0 ? (float)$v : null;
        if (!is_string($v)) return null;
        $s = preg_replace("/[\\s'\u{00A0}\u{202F}]/u", '', $v) ?? '';
        if (!preg_match('/^\d[\d.,]*$/', $s)) return null;
        $dots = substr_count($s, '.'); $commas = substr_count($s, ',');
        if ($dots && $commas) {
            // das letzte Zeichen ist das Dezimaltrennzeichen, das andere gruppiert
            $dec = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $s = str_replace($dec === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($dots + $commas > 1) {
            $s = str_replace(['.', ','], '', $s);    // 1.234.567 → Gruppen
        } else {
            $s = str_replace(',', '.', $s);          // ein Trennzeichen = Dezimal (Gaszähler: 3 Stellen)
        }
        return is_numeric($s) ? (float)$s : null;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /**
     * POST über curl an die geprüfte Adresse (CURLOPT_RESOLVE), ohne
     * Weiterleitungen.
     *
     * @return array{ok:bool, body:?string, http_code:?int, error_code:?string}
     */
    private static function curlPost(string $url, string $json, int $timeout, ?string $ip): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'noTransport'];
        $p = parse_url($url);
        $port = (int)($p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $host = trim((string)($p['host'] ?? ''), '[]');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Energietracker (PHP)',
        ];
        if ($ip !== null && !filter_var($host, FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            return ['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network'];
        }
        if ($code < 200 || $code >= 300) return ['ok' => false, 'body' => is_string($body) ? $body : null, 'http_code' => $code, 'error_code' => 'http'];
        return ['ok' => true, 'body' => (string)$body, 'http_code' => $code, 'error_code' => null];
    }
}
