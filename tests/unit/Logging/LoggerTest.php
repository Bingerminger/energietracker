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
}
