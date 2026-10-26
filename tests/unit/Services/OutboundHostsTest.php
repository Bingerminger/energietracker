<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Paket H2) — „Nach außen spricht die App nur mit …": Doku-Behauptung
 * mit Test. Ausgehende HTTP-Verbindungen gibt es nur in den hier genannten
 * Diensten; wer einen neuen dazunimmt, ergänzt die Liste UND den Absatz
 * „Deine Daten" in README.md/README.de.md.
 */
final class OutboundHostsTest extends TestCase
{
    /** Dienst → wohin er spricht */
    private const ALLOWED = [
        'WeatherService.php' => 'Open-Meteo',
        // v3.9.0 (F1033) — Benachrichtigungen an das eigene Home Assistant, nur eingeschaltet
        'NotifyTransport.php' => 'eigenes Home Assistant (Supervisor, Webhook oder Token), nur mit Opt-in',
        'OcrService.php'     => 'eigener Texterkennungsdienst im Heimnetz',
        'MarketPriceService.php' => 'SMARD (Bundesnetzagentur), nur auf Knopfdruck',   // v3.1.0 (H6)
        'EvccService.php'    => 'eigenes evcc im Heimnetz, nur auf Knopfdruck',         // v3.2.0 (F1022)
        'UpdateCheckService.php' => 'GitHub (neueste Version), nur eingeschaltet, einmal am Tag', // v3.6.0
        // v3.7.0 (Paket cloud) — Auslesen im Posteingang, nur mit Opt-in: api.anthropic.com
        // (AnthropicProvider::HOST) oder eine eigene OpenAI-kompatible Adresse
        'CloudHttp.php'      => 'Cloud-Dienst fürs Auslesen im Posteingang, nur mit Opt-in',
    ];

    public function testOnlyTheListedServicesOpenConnections(): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $code = (string)file_get_contents($file->getPathname());
            if (preg_match('/\bcurl_init\s*\(|\bfsockopen\s*\(|\bstream_socket_client\s*\(|file_get_contents\(\s*[\'"]https?:|\bfopen\(\s*[\'"]https?:/', $code)) {
                $found[] = $file->getFilename();
            }
        }
        sort($found);
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);
        self::assertSame($allowed, $found);
    }

    public function testTheReadmeNamesThem(): void
    {
        $root = dirname(__DIR__, 3);
        $de = (string)file_get_contents("$root/README.de.md");
        $en = (string)file_get_contents("$root/README.md");
        self::assertStringContainsString('Open-Meteo', $de);
        self::assertStringContainsString('Texterkennungsdienst', $de, 'README.de.md nennt die Texterkennung im Heimnetz');
        self::assertStringContainsString('text recognition', $en, 'README.md names the text recognition in the home network');
        // v3.9.0 (F1033) — Benachrichtigungen an das eigene Home Assistant
        self::assertStringContainsString('dein Home Assistant, wenn du Benachrichtigungen dorthin einschaltest', (string)preg_replace('/\s+/u', ' ', $de),
            'README.de.md nennt die Benachrichtigungen an Home Assistant');
        self::assertStringContainsString('your Home Assistant, when you switch on notifications to it', (string)preg_replace('/\s+/u', ' ', $en),
            'README.md names the notifications to Home Assistant');
        self::assertStringContainsString('SMARD', $de, 'README.de.md nennt den Abruf der Marktpreise');
        self::assertStringContainsString('SMARD', $en, 'README.md names the market price download');
        self::assertStringContainsString('evcc im', $de, 'README.de.md nennt den Abruf aus evcc');
        self::assertStringContainsString('evcc in your home network', $en, 'README.md names the evcc download');
        self::assertStringContainsString('neue Versionen', $de, 'README.de.md nennt den Hinweis auf neue Versionen');
        self::assertStringContainsString('new versions', $en, 'README.md names the note about new versions');
        // v3.7.0 (Paket cloud) — der feste Host aus dem Anschluss, die eigene Adresse nur mit Opt-in
        $host = \Energietracker\Services\Inbox\AnthropicProvider::HOST;
        self::assertSame('api.anthropic.com', $host);
        self::assertStringContainsString('`' . $host . '`', $de, 'README.de.md nennt den Host für Claude');
        self::assertStringContainsString('`' . $host . '`', $en, 'README.md names the host for Claude');
        $flat = static fn(string $s): string => (string)preg_replace('/\s+/u', ' ', $s);
        self::assertStringContainsString('eigene OpenAI-kompatible Adresse, die nur mit diesem Opt-in angesprochen wird', $flat($de),
            'README.de.md: die eigene OpenAI-kompatible Adresse nur mit Opt-in');
        self::assertStringContainsString('your own OpenAI-compatible address, which is only contacted with this opt-in', $flat($en),
            'README.md: your own OpenAI-compatible address only with the opt-in');
        self::assertStringStartsWith('https://' . $host . '/', \Energietracker\Services\Inbox\AnthropicProvider::URL);
        self::assertStringStartsWith('https://' . $host . '/', \Energietracker\Services\Inbox\AnthropicProvider::MODELS_URL);
        // v3.7.0 (Karte „Auslesen“) — jede Voreinstellung mit ihrem Host, nur mit Opt-in
        foreach (\Energietracker\Services\Inbox\CloudConfig::PRESETS as $id => $p) {
            if ($p['endpoint'] === null) continue;
            $h = \Energietracker\Services\Inbox\CloudConfig::hostOf($p['endpoint']);
            self::assertStringStartsWith('https://', $p['endpoint'], $id);
            self::assertStringContainsString('`' . $h . '`', $de, "README.de.md nennt den Host der Voreinstellung $id");
            self::assertStringContainsString('`' . $h . '`', $en, "README.md names the host of the preset $id");
        }
        self::assertStringContainsString('Jeden dieser Hosts spricht die App nur mit Opt-in an', $flat($de));
        self::assertStringContainsString('The app contacts each of these hosts only with the opt-in', $flat($en));
    }
}
