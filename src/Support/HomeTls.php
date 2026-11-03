<?php
declare(strict_types=1);

namespace Energietracker\Support;

use Energietracker\Services\OcrService;

/**
 * v3.10.0 (GitHub #36) — HTTPS für Verbindungen ins Heimnetz: Home Assistant
 * (NotifyTransport), Texterkennung und Auslesen im Heimnetz (OcrService,
 * Inbox\LocalProvider) und evcc (EvccService). Die Cloud-Anschlüsse
 * (Inbox\CloudHttp) und die öffentlichen Dienste (Wetter, Börsenpreise,
 * Update-Hinweis) bleiben beim Standard des Systems.
 *
 * Zwei Wege für einen Server mit eigener PKI:
 *   1. Eigenes Stammzertifikat (empfohlen): aus der Datei `ET_CA_FILE` und
 *      der Einstellung `home_ca_pem`. Es gilt ZUSÄTZLICH zu den
 *      Systemzertifikaten: CURLOPT_CAINFO(_BLOB) ersetzt die Liste, die curl
 *      sonst nimmt, deshalb bekommt curl ein Bündel aus der Liste des
 *      Systems und den eigenen. Die Prüfung bleibt an.
 *   2. „Zertifikat im Heimnetz nicht prüfen“ (`home_tls_insecure`, Standard
 *      aus): nur, wenn die Adresse, mit der curl verbindet, im Heimnetz liegt
 *      (OcrService::isLocalIp). Entschieden wird an der aufgelösten Adresse,
 *      und die Anschlüsse verbinden mit genau dieser (CURLOPT_RESOLVE); ein
 *      Name wie `ha.local`, der auf eine öffentliche Adresse zeigt, zählt
 *      nicht. Für eine öffentliche Adresse wirkungslos.
 *
 * Konfiguriert vom App-Container (configure); ohne Konfiguration (CLI,
 * Tests) ändert sich nichts an den Verbindungen.
 */
final class HomeTls
{
    /** Höchstgröße des eingefügten Texts (eine Kette mit mehreren Zertifikaten passt). */
    public const MAX_PEM_BYTES = 65536;

    /**
     * Wo Systeme die Liste der Stammzertifikate ablegen (curl/OpenSSL). Das
     * Docker-Image (Alpine) nutzt die erste.
     */
    private const SYSTEM_BUNDLES = [
        '/etc/ssl/certs/ca-certificates.crt',                 // Alpine, Debian, Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',                   // Fedora, RHEL
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
        '/etc/ssl/ca-bundle.pem',                             // openSUSE
        '/etc/ssl/cert.pem',                                  // macOS, BSD
        '/usr/local/share/certs/ca-root-nss.crt',             // FreeBSD
    ];

    /** @var (\Closure(): array{pem?:string, insecure?:bool})|null */
    private static ?\Closure $settings = null;
    private static ?string $caFile = null;
    private static ?bool $blob = null;

    /**
     * @param callable(): array{pem?:string, insecure?:bool} $settings liest die Einstellungen (bei jedem Aufruf)
     * @param ?string $caFile Pfad aus `ET_CA_FILE` (leer = keiner)
     * @param ?bool $blob CURLOPT_CAINFO_BLOB nutzen; null = wenn es ihn gibt (libcurl ab 7.77). Tests setzen false.
     */
    public static function configure(callable $settings, ?string $caFile, ?bool $blob = null): void
    {
        self::$settings = \Closure::fromCallable($settings);
        self::$caFile = $caFile !== null && trim($caFile) !== '' ? trim($caFile) : null;
        self::$blob = $blob;
    }

    public static function reset(): void
    {
        self::$settings = null;
        self::$caFile = null;
        self::$blob = null;
    }

    /**
     * curl-Optionen für eine Verbindung ins Heimnetz.
     *
     * @param ?string $ip die Adresse, mit der curl verbindet (CURLOPT_RESOLVE); null = der Host, wenn er eine IP ist
     * @return array<int,mixed>
     */
    public static function curlOptions(?string $ip, string $host = ''): array
    {
        if (self::$settings === null) return [];
        $cfg = (self::$settings)();
        $host = trim($host, '[]');
        $target = $ip ?? (filter_var($host, FILTER_VALIDATE_IP) ? $host : null);
        $local = $target !== null && OcrService::isLocalIp($target);
        $opts = [];
        $own = array_values(array_unique([...self::fileCertificates()['certs'], ...self::certificates((string)($cfg['pem'] ?? ''))]));
        if ($own !== []) {
            $system = self::systemBundle();
            // Ohne Liste des Systems nur im Heimnetz: Eine öffentliche Adresse (Fernzugang) behält den Standard
            if ($system !== '' || $local) $opts += self::caOption(($system !== '' ? rtrim($system) . "\n" : '') . implode("\n", $own) . "\n");
        }
        if (!empty($cfg['insecure']) && $local) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        return $opts;
    }

    /**
     * Das Bündel an curl: im Speicher (CURLOPT_CAINFO_BLOB), sonst (libcurl
     * vor 7.77, etwa Debian 11) als eigene Datei dieser Anfrage — tempnam legt
     * sie neu an, nur für uns lesbar; am Ende der Anfrage gelöscht.
     *
     * @return array<int,mixed>
     */
    private static function caOption(string $bundle): array
    {
        if (self::$blob ?? defined('CURLOPT_CAINFO_BLOB')) return [constant('CURLOPT_CAINFO_BLOB') => $bundle];
        $file = @tempnam(sys_get_temp_dir(), 'et-ca-');
        if ($file === false || @file_put_contents($file, $bundle) === false) return [];
        register_shutdown_function(static function () use ($file): void { @unlink($file); });
        return [CURLOPT_CAINFO => $file];
    }

    /**
     * Zertifikate aus PEM-Text, je eines in einheitlicher Form. Text um die
     * Blöcke herum (etwa „subject=…“ aus openssl) zählt nicht. Ein Block, der
     * sich nicht lesen lässt, fällt heraus — beim Speichern lehnt
     * normalizeSetting() ihn ab.
     *
     * @return list<string>
     */
    public static function certificates(string $text): array
    {
        return self::parse($text)['certs'];
    }

    /** @return array{certs:list<string>, unreadable:int} */
    private static function parse(string $text): array
    {
        $out = [];
        $bad = 0;
        if ($text !== '' && preg_match_all('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $text, $m)) {
            foreach ($m[1] as $b64) {
                $der = base64_decode((string)preg_replace('/\s+/', '', $b64), true);
                $pem = $der === false || $der === '' ? ''
                    : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END CERTIFICATE-----';
                if ($pem === '' || (function_exists('openssl_x509_read') && @openssl_x509_read($pem) === false)) { $bad++; continue; }
                $out[$pem] = true;   // doppelt eingefügt zählt einmal
            }
        }
        return ['certs' => array_keys($out), 'unreadable' => $bad];
    }

    /**
     * Einstellung `home_ca_pem` beim Speichern: leer = keins; sonst nur
     * Zertifikate, jedes lesbar, und nie ein privater Schlüssel. Gespeichert
     * wird die einheitliche Form (ohne Text um die Blöcke).
     */
    public static function normalizeSetting(mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return '';
        if (!is_string($value) || strlen($value) > self::MAX_PEM_BYTES) {
            throw new LocalizedException('errors.settings.caInvalid', [], 'home_ca_pem is not PEM text');
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value)) {
            throw new LocalizedException('errors.settings.caPrivateKey', [], 'home_ca_pem contains a private key');
        }
        $p = self::parse($value);
        if ($p['certs'] === [] || $p['unreadable'] > 0) {
            throw new LocalizedException('errors.settings.caInvalid', [], 'home_ca_pem has no or an unreadable certificate');
        }
        return implode("\n", $p['certs']) . "\n";
    }

    /**
     * Zertifikate aus `ET_CA_FILE`.
     *
     * @return array{state:string, certs:list<string>} state: off | ok | unreadable | invalid
     */
    private static function fileCertificates(): array
    {
        if (self::$caFile === null) return ['state' => 'off', 'certs' => []];
        $text = @is_file(self::$caFile) ? @file_get_contents(self::$caFile, false, null, 0, 4 * self::MAX_PEM_BYTES) : false;
        if (!is_string($text)) return ['state' => 'unreadable', 'certs' => []];
        $certs = self::certificates($text);
        return ['state' => $certs === [] ? 'invalid' : 'ok', 'certs' => $certs];
    }

    /** Inhalt der Liste des Systems, '' wenn keine zu finden ist. */
    private static function systemBundle(): string
    {
        $candidates = [(string)ini_get('curl.cainfo'), (string)getenv('SSL_CERT_FILE'), ...self::SYSTEM_BUNDLES, (string)ini_get('openssl.cafile')];
        if (function_exists('openssl_get_cert_locations')) $candidates[] = (string)(openssl_get_cert_locations()['default_cert_file'] ?? '');
        foreach ($candidates as $path) {
            if ($path === '' || !@is_file($path) || !@is_readable($path)) continue;
            $text = @file_get_contents($path);
            if (is_string($text) && str_contains($text, '-----BEGIN CERTIFICATE-----')) return $text;
        }
        return '';
    }

    /** Fehlercodes der Anschlüsse je Art (`error_code`) → Meldung (Katalogschlüssel, Parameter `host`). */
    public const CERT_ERRORS = [
        'tlsIssuer'  => 'errors.homeTls.issuer',    // Aussteller unbekannt: eigenes Stammzertifikat hilft
        'tlsName'    => 'errors.homeTls.name',      // Zertifikat passt nicht zur Adresse: Adresse anpassen
        'tlsExpired' => 'errors.homeTls.expired',   // abgelaufen oder noch nicht gültig
        'tlsFile'    => 'errors.homeTls.file',      // Stammzertifikate nicht lesbar (ET_CA_FILE, eingehängte Liste)
        'tlsOther'   => 'errors.homeTls.other',
    ];

    /**
     * v3.10.0 (Skeptiker N8) — Art eines Zertifikatsfehlers, aus dem
     * Fehlercode von curl und dem Prüfergebnis von OpenSSL
     * (CURLINFO_SSL_VERIFYRESULT), nie aus dem Meldungstext; null = kein
     * Zertifikatsfehler. Bis dahin hieß jeder Fall „eigenes Stammzertifikat
     * eintragen“, auch wenn nur die Adresse nicht zum Zertifikat passte.
     *
     *   77            die Liste der Stammzertifikate ist nicht lesbar
     *   51            ältere curl: der Name passt nicht
     *   60, Ergebnis 0/1  die Kette ist in Ordnung, curl prüft danach selbst
     *                 den Namen bzw. die IP-Adresse (curl 8 meldet dann 1,
     *                 X509_V_ERR_UNSPECIFIED): er passt nicht. Nur mit OpenSSL
     *                 sicher (Sonde 2026-11-03), sonst „other“
     *   60, 62/64     Name bzw. IP-Adresse passt nicht (OpenSSL)
     *   60, 9/10      noch nicht gültig bzw. abgelaufen
     *   60, 2/18/19/20/21, 83  Aussteller unbekannt; ist ET_CA_FILE gesetzt,
     *                 aber nicht lesbar, liegt es an der Datei
     */
    public static function certError(int $errno, int $verifyResult = 0): ?string
    {
        if ($errno === 77) return 'tlsFile';
        if ($errno === 51) return 'tlsName';
        if ($errno !== 60 && $errno !== 83) return null;
        $issuer = $errno === 83 || in_array($verifyResult, [2, 18, 19, 20, 21], true);
        return match (true) {
            $issuer                                       => in_array(self::fileCertificates()['state'], ['unreadable', 'invalid'], true) ? 'tlsFile' : 'tlsIssuer',
            in_array($verifyResult, [62, 64], true)       => 'tlsName',
            in_array($verifyResult, [9, 10], true)        => 'tlsExpired',
            $verifyResult <= 1 && self::opensslBackend()  => 'tlsName',
            default                                       => 'tlsOther',
        };
    }

    /** Spricht curl über OpenSSL (oder einen Ableger)? Nur dann trägt das Prüfergebnis. */
    private static function opensslBackend(): bool
    {
        $ssl = function_exists('curl_version') ? (string)(curl_version()['ssl_version'] ?? '') : '';
        return (bool)preg_match('/^(OpenSSL|LibreSSL|BoringSSL|quictls|AWS-LC)\b/i', $ssl);
    }

    /**
     * Antwort eines Anschlusses mit einem Zertifikatsfehler (`error_code`
     * aus CERT_ERRORS): die Meldung mit dem passenden Rat.
     *
     * @param array{error_code?:?string} $resp
     */
    public static function throwIfCertificate(array $resp, string $url): void
    {
        $key = self::CERT_ERRORS[(string)($resp['error_code'] ?? '')] ?? null;
        if ($key === null) return;
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        throw new LocalizedException($key, ['host' => $host], "certificate of $host: " . (string)$resp['error_code']);
    }

    /**
     * v3.10.0 — für die Diagnose: woher eigene Stammzertifikate kommen, ob
     * die Liste des Systems gefunden ist und ob die Prüfung im Heimnetz aus ist.
     * Ohne Pfade und ohne Inhalt.
     *
     * @return array{ca_file:string, ca_file_certs:int, ca_settings_certs:int, system_bundle:bool, insecure_local:bool}
     */
    public static function status(): array
    {
        $cfg = self::$settings !== null ? (self::$settings)() : [];
        $file = self::fileCertificates();
        return [
            'ca_file'           => $file['state'],
            'ca_file_certs'     => count($file['certs']),
            'ca_settings_certs' => count(self::certificates((string)($cfg['pem'] ?? ''))),
            'system_bundle'     => self::systemBundle() !== '',
            'insecure_local'    => !empty($cfg['insecure']),
        ];
    }
}
