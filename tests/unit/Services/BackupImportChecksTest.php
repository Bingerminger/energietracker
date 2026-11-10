<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\HealthCheckService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — Der Backup-Import meldet als Problem, was die Schreibwege abweisen, statt es einzuspielen:
 * Ein Vertrag mit `end: []`, eine Einstellung `forecast_model: []`, ein Stand 10³⁰⁸ oder ein Datum im
 * Jahr 0001 gingen bisher mit 200 und `problems: []` durch und legten danach Übersicht, Zu tun und
 * Jahresbericht lahm. Und ein Einspielen, das mittendrin endet, hinterlässt einen Merker, den der
 * Gesundheitscheck meldet (bisher ein gemischter Haushalt ohne Hinweis).
 */
#[CoversClass(BackupService::class)]
#[CoversClass(HealthCheckService::class)]
final class BackupImportChecksTest extends ServiceTestCase
{
    private BackupService $backup;
    private string $meter;
    private string $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = new BackupService($this->store, $this->i18n);
        $this->meter = (string)$this->meters->list('strom')[0]['id'];
        $this->readings->create('strom', ['meter_id' => $this->meter, 'date' => '2025-01-01', 'counter' => 1000]);
        $this->readings->create('strom', ['meter_id' => $this->meter, 'date' => '2025-02-01', 'counter' => 1250]);
        $this->contract = (string)$this->contracts->create('strom', ['meter_id' => $this->meter, 'provider' => 'Probe', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30]], 'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80]]])['id'];
    }

    /** @return array<string, array{0: callable(array): array, 1: string}> */
    public static function poisoned(): array
    {
        $contract = fn(array $patch) => function (array $d) use ($patch): array {
            $d['utilities']['strom']['contracts'][0] = array_merge($d['utilities']['strom']['contracts'][0], $patch);
            return $d;
        };
        $reading = fn(array $patch) => function (array $d) use ($patch): array {
            $d['utilities']['strom']['readings'][0] = array_merge($d['utilities']['strom']['readings'][0], $patch);
            return $d;
        };
        return [
            'Vertragsende leere Liste'  => [$contract(['end' => []]), 'date:end'],
            'Vertragsende 0'            => [$contract(['end' => 0]), 'date:end'],
            'Schatten als Wort'         => [$contract(['is_shadow' => 'vielleicht']), 'bool:is_shadow'],
            'Preisliste als Text'       => [$contract(['working_prices' => 'abc']), 'list:working_prices'],
            'Arbeitspreis 10^308'       => [$contract(['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 1e308]]]), 'amount:working_prices'],
            'Abschlag negativ'          => [$contract(['advance_payments' => [['from' => '2025-01-01', 'amount_eur' => -5]]]), 'amount:advance_payments'],
            'Frist 10^308 Tage'         => [$contract(['notice_period_days' => 1e308]), 'number:notice_period_days'],
            'Vertragsbeginn Jahr 0001'  => [$contract(['start' => '0001-01-01']), 'date_range:start'],
            'Stand 10^308'              => [$reading(['counter' => 1e308]), 'counter'],
            'Stand negativ'             => [$reading(['counter' => -1]), 'counter'],
            'Ablesung Jahr 0001'        => [$reading(['date' => '0001-01-01']), 'date_range:date'],
            'Ablesung verdächtig als Wort' => [$reading(['is_suspect' => 'vielleicht']), 'bool:is_suspect'],
            'Einstellung Modell als Liste' => [fn(array $d) => array_replace_recursive($d, ['settings' => ['forecast_model' => []]]), 'setting:forecast_model'],
            'Einstellung Heizgrenze 10^308' => [fn(array $d) => array_replace_recursive($d, ['settings' => ['hdd_base_temp' => 1e308]]), 'setting:hdd_base_temp'],
            'Einstellung Arten als Text'  => [function (array $d): array { $d['settings']['active_utilities'] = 'ja'; return $d; }, 'setting:active_utilities'],
            'Termin Jahr 0001'          => [function (array $d): array { $d['reminders'] = [['id' => 'rem_1', 'title' => 'T', 'next_due' => '0001-01-01']]; return $d; }, 'date:next_due'],
            'Termin Rhythmus unbekannt' => [function (array $d): array { $d['reminders'] = [['id' => 'rem_1', 'title' => 'T', 'next_due' => '2026-01-01', 'recurrence' => 'täglich']]; return $d; }, 'recurrence'],
            'Rechnung Ausstellung 0001' => [function (array $d): array { $d['utilities']['strom']['bills'] = [['id' => 'b_1', 'meter_id' => 'x', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '0001-01-01']]; return $d; }, 'date:issued_on'],
            'Temperatur 10^308'         => [function (array $d): array { $d['temperatures'] = ['2025-01-01' => ['avg' => 1e308, 'min' => 0, 'max' => 1]]; return $d; }, 'value:2025-01-01.avg'],
        ];
    }

    #[DataProvider('poisoned')]
    public function testWhatTheWriteRoutesRejectIsAProblemAndNothingIsImported(callable $poison, string $expected): void
    {
        $dump = $poison($this->backup->export(false));
        $before = $this->store->read('strom/contracts.json', []);
        try {
            $this->backup->import($dump);
            self::fail("eingespielt trotz $expected");
        } catch (BackupInvalidException $e) {
            $found = array_column($e->problems, 'problem');
            self::assertTrue(in_array($expected, $found, true) || in_array(true, array_map(fn($p) => str_starts_with($p, $expected), $found), true),
                "$expected erwartet, gemeldet: " . json_encode($found));
        }
        self::assertSame($before, $this->store->read('strom/contracts.json', []), 'nichts eingespielt');
        self::assertFalse($this->store->exists(BackupService::RESTORE_MARKER));
    }

    public function testTheOwnExportStillImportsCleanly(): void
    {
        $dump = $this->backup->export(false);
        $r = $this->backup->import($dump, true);
        self::assertSame([], $r['problems']);
        $this->backup->import($dump);
        self::assertSame($this->contract, $this->contracts->list('strom')[0]['id']);
    }

    /** Der Merker steht während jedes Schreibens der Daten und ist danach weg. */
    public function testTheMarkerCoversEveryWriteAndIsRemovedAfterwards(): void
    {
        $dump = $this->backup->export(false);
        $seen = [];
        $this->store->setWriteGuard(function () use (&$seen): void {
            $seen[] = $this->store->exists(BackupService::RESTORE_MARKER);
        });
        $this->backup->import($dump);
        $this->store->setWriteGuard(null);
        self::assertGreaterThan(5, count($seen));
        // die ersten Schreibvorgänge: Sicherung vorher (eigene Datei) und der Merker selbst; danach stets mit Merker
        self::assertNotContains(false, array_slice($seen, (int)array_search(true, $seen, true)), 'kein Schreiben der Daten ohne Merker');
        self::assertFalse($this->store->exists(BackupService::RESTORE_MARKER), 'nach dem Einspielen weg');
        self::assertSame('ok', (new HealthCheckService($this->store))->run()['checks']['restore']['level']);
    }

    /** Ein Einspielen, das endete, ohne fertig zu werden (der Merker steht noch): degraded mit der Sicherung. */
    public function testAnInterruptedRestoreIsReportedByTheHealthCheck(): void
    {
        $this->store->write(BackupService::RESTORE_MARKER, ['started_at' => '2026-03-04T05:06:07+01:00', 'snapshot' => 'pre-restore-2026-03-04_050607.json']);
        $h = (new HealthCheckService($this->store))->run();
        self::assertSame('degraded', $h['status']);
        self::assertSame('degraded', $h['checks']['restore']['level']);
        self::assertSame('pre-restore-2026-03-04_050607.json', $h['checks']['restore']['snapshot']);
        // ein vollständiges Einspielen danach räumt ihn ab
        $this->backup->import($this->backup->export(false));
        self::assertSame('ok', (new HealthCheckService($this->store))->run()['checks']['restore']['level']);
    }
}
