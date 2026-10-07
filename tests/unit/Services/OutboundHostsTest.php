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
        'OcrService.php'     => 'eigener Texterkennungsdienst im Heimnetz',
        'MarketPriceService.php' => 'SMARD (Bundesnetzagentur), nur auf Knopfdruck',   // v3.1.0 (H6)
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
        self::assertStringContainsString('SMARD', $de, 'README.de.md nennt den Abruf der Marktpreise');
        self::assertStringContainsString('SMARD', $en, 'README.md names the market price download');
    }
}
