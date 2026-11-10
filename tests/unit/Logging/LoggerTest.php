<?php
declare(strict_types=1);

namespace Energietracker\Tests\Logging;

use Energietracker\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * N1010 (v1.7.3) — strukturierter Logger.
 *
 * Geprüft wird über das Datei-Ziel (deterministisch testbar, anders als
 * stderr): JSON-Lines-Format, Level-Schwellwert, Null-Ziel (aus).
 */
final class LoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/et_logger_test_' . uniqid() . '.log';
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function lines(): array
    {
        if (!is_file($this->file)) return [];
        $raw = trim((string)file_get_contents($this->file));
        return $raw === '' ? [] : explode("\n", $raw);
    }

    public function testWritesOneJsonObjectPerLine(): void
    {
        $log = new Logger('info', 'file', $this->file);
        $log->info('erste Meldung', ['a' => 1]);
        $log->error('zweite Meldung');

        $lines = $this->lines();
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $this->assertIsArray($first);
        $this->assertSame('info', $first['level']);
        $this->assertSame('erste Meldung', $first['msg']);
        $this->assertSame(['a' => 1], $first['ctx']);
        $this->assertArrayHasKey('ts', $first);

        $second = json_decode($lines[1], true);
        $this->assertSame('error', $second['level']);
        $this->assertArrayNotHasKey('ctx', $second); // leerer Kontext → kein Feld
    }

    public function testRespectsLevelThreshold(): void
    {
        $log = new Logger('warning', 'file', $this->file);
        $log->debug('verschluckt');
        $log->info('verschluckt');
        $log->warning('sichtbar');
        $log->error('sichtbar');

        $lines = $this->lines();
        $this->assertCount(2, $lines);
        $this->assertSame('warning', json_decode($lines[0], true)['level']);
        $this->assertSame('error', json_decode($lines[1], true)['level']);
    }

    public function testNullDestinationDisablesLogging(): void
    {
        $log = new Logger('debug', 'null', $this->file);
        $log->error('darf nicht geschrieben werden');
        $this->assertSame([], $this->lines());
        $this->assertFileDoesNotExist($this->file);
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        $log = new Logger('quatsch', 'file', $this->file);
        $log->debug('unter info → verschluckt');
        $log->info('ab info → sichtbar');
        $this->assertCount(1, $this->lines());
    }

    /**
     * v3.12.0 — Härtung: keine Zugangsdaten im Protokoll. Das Kalender-Abo trägt
     * seinen Schlüssel im Link (`?token=etk_…`); jede Meldung während eines Abrufs
     * schrieb ihn bisher mit der Anfrage-URI ins Protokoll. Die URI bleibt
     * (Fehlersuche), Werte von Zugangsparametern und alles, was wie ein Schlüssel
     * der App aussieht, werden ersetzt.
     */
    public function testRequestUriInTheLogCarriesNoCredentials(): void
    {
        // erfundene Schlüssel (Format der App: etk_ + 48 Hex, et_ + 48 Hex)
        $calendar = 'etk_' . str_repeat('5a', 24);
        $ingest   = 'et_' . str_repeat('c3', 24);
        $saved = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = "/api.php/api/calendar.ics?person=p_0001&token=$calendar";
            $log = new Logger('info', 'file', $this->file);
            $log->warning('Kalender: Meldung während des Abrufs');
            $_SERVER['REQUEST_URI'] = "/api/summary?Access_Token=geheim-1&api_key=geheim-2&x=$ingest&year=2025";
            $log->warning('zweite Meldung');
        } finally {
            $_SERVER = $saved;
        }
        $raw = (string)file_get_contents($this->file);
        foreach ([$calendar, $ingest, 'geheim-1', 'geheim-2', str_repeat('5a', 24)] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, 'Zugangsdaten im Protokoll');
        }
        [$first, $second] = array_map(fn($l) => json_decode($l, true), $this->lines());
        // Pfad und harmlose Parameter bleiben für die Fehlersuche
        $this->assertSame('/api.php/api/calendar.ics?person=p_0001&token=***', $first['uri']);
        $this->assertSame('/api/summary?Access_Token=***&api_key=***&x=***&year=2025', $second['uri']);
    }

    /** Die Sicherheitsseite (§ 10, DE+EN) nennt Parameter, deren Wert die App ersetzt — jeder davon wird ersetzt. */
    public function testTheDocumentedParametersAreMasked(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/betrieb/sicherheit.md' => 'Keine Zugangsdaten im Protokoll', 'docs/en/betrieb/sicherheit.md' => 'No credentials in the log'] as $rel => $lead) {
            $text = (string)file_get_contents("$root/$rel");
            $pos = strpos($text, $lead);
            $this->assertNotFalse($pos, "$rel: Absatz fehlt");
            // nur dieser Punkt der Liste (bis zum nächsten „- “), ohne die Beispiele `etk_…` und das nginx-Format
            $para = (string)strstr(substr($text, (int)$pos) . "\n- ", "\n- ", true);
            $this->assertGreaterThan(0, preg_match_all('/`([a-z_]+)`/', $para, $m), "$rel: keine Parameter genannt");
            $this->assertGreaterThanOrEqual(4, count($m[1]), "$rel: " . implode(', ', $m[1]));
            foreach ($m[1] as $name) {
                $this->assertSame("/api/x?$name=***", Logger::safeUri("/api/x?$name=wert-1"), "$rel nennt `$name`");
            }
            $this->assertStringContainsString('`***`', $para);
        }
    }

    /** Ein Schlüssel im Pfad (falsch kopierter Link) fällt ebenso heraus. */
    public function testKeyShapedSegmentsAreMaskedAnywhere(): void
    {
        $key = 'etk_' . str_repeat('0f', 24);
        $this->assertSame('/api/calendar.ics/etk_***', Logger::safeUri("/api/calendar.ics/$key"));
        $this->assertSame('/api/health', Logger::safeUri('/api/health'));
        $this->assertSame('/api/readings?meter_id=m_strom_1a2b&from=2025-01-01', Logger::safeUri('/api/readings?meter_id=m_strom_1a2b&from=2025-01-01'));
        $this->assertSame('/api/x?token=***&password=***&secret=***&key=***', Logger::safeUri('/api/x?token=a&password=b&secret=c&key=d'));
        $this->assertSame('/api/x?token', Logger::safeUri('/api/x?token'), 'ohne Wert nichts zu verbergen');
    }
}
