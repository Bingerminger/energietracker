<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\BackupService;
use Energietracker\Services\ContractService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.3.0 — Regression: Die Wechselfelder müssen einen Backup-Roundtrip
 * überstehen.
 *
 * `BackupService` führt eine hartkodierte Liste der Datentöpfe. In v2.1.2 sind
 * dadurch `deliveries`, `meter_groups` und `reminders` bei jedem Backup
 * lautlos verschwunden — der Fehler fiel erst Monate später auf, weil ein
 * Backup ja „erfolgreich" meldet.
 *
 * Verträge werden als Ganzes kopiert, die neuen Felder sollten also
 * mitkommen. „Sollte" reicht hier nicht: Ein verlorener Kündigungstermin
 * wird erst bemerkt, wenn die Frist verstrichen ist.
 */
#[CoversClass(BackupService::class)]
#[CoversClass(ContractService::class)]
final class SwitchFieldsBackupTest extends ServiceTestCase
{
    public function testSwitchFieldsSurviveExportAndImport(): void
    {
        $meterId = $this->meters->defaultId('strom');

        $this->contracts->create('strom', [
            'meter_id'              => $meterId,
            'provider'              => 'Stadtwerke',
            'tariff_name'           => 'Basis',
            'start'                 => '2026-01-01',
            'end'                   => '2026-12-31',
            'notice_period_months'  => 3,
            'min_term_end'          => '2026-06-30',
            'price_guarantee_until' => '2026-09-30',
            'working_prices'        => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_id'         => $meterId,
            'tariff_name'      => 'Angebot',
            'start'            => '2027-01-01',
            'is_shadow'        => true,
            'shadow_label'     => 'Angebot',
            'signup_bonus_eur' => 150.0,
            'working_prices'   => [['from' => '2027-01-01', 'ct_per_kwh' => 26.0]],
        ]);

        $backups = new BackupService($this->store, $this->i18n);
        $dump    = $backups->export();

        // Verträge löschen und aus dem Backup zurückholen.
        $this->store->write('strom/contracts.json', []);
        self::assertSame([], $this->contracts->list('strom', $meterId), 'Vorbedingung');

        $backups->import($dump);
        $restored = $this->contracts->list('strom', $meterId);
        self::assertCount(2, $restored);

        $real   = null;
        $shadow = null;
        foreach ($restored as $c) {
            if (empty($c['is_shadow'])) $real = $c; else $shadow = $c;
        }

        self::assertNotNull($real);
        self::assertSame(3, $real['notice_period_months'], 'Kündigungsfrist verloren');
        self::assertSame('2026-06-30', $real['min_term_end'], 'Mindestlaufzeit verloren');
        self::assertSame('2026-09-30', $real['price_guarantee_until'], 'Preisgarantie verloren');

        self::assertNotNull($shadow);
        // assertEquals, nicht assertSame: JSON unterscheidet 150.0 nicht von
        // 150, ein gespeicherter Float kommt als Int zurück. Für die Rechnung
        // belanglos (überall wird auf float gecastet) — aber der Test darf
        // keinen Typ behaupten, den der Flat-File-Speicher nicht garantiert.
        self::assertEquals(150.0, $shadow['signup_bonus_eur'], 'Neukundenbonus verloren');
        self::assertIsNumeric($shadow['signup_bonus_eur']);
    }

    /**
     * Ein Backup von vor v2.3.0 kennt die Felder nicht. Der Import darf daran
     * nicht scheitern, und die Verträge müssen danach normal bearbeitbar sein.
     */
    public function testOlderBackupsWithoutTheFieldsStillImport(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $backups = new BackupService($this->store, $this->i18n);

        $this->store->write('strom/contracts.json', [[
            'id'             => 'c_alt',
            'meter_id'       => $meterId,
            'provider'       => 'Alt',
            'tariff_name'    => 'Vor v2.3.0',
            'start'          => '2025-01-01',
            'end'            => null,
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 28.0]],
            'base_prices'    => [],
            // notice_period_months, min_term_end, price_guarantee_until,
            // signup_bonus_eur fehlen — so sieht ein altes Backup aus.
        ]]);

        $dump = $backups->export();
        $this->store->write('strom/contracts.json', []);
        $backups->import($dump);

        $c = $this->contracts->list('strom', $meterId)[0] ?? null;
        self::assertNotNull($c);
        self::assertNull($c['notice_period_months'] ?? null);

        // Und ein Update darauf muss die Felder sauber ergänzen.
        $updated = $this->contracts->update('strom', 'c_alt', ['notice_period_months' => 2]);
        self::assertSame(2, $updated['notice_period_months']);
        self::assertNull($updated['min_term_end']);
        self::assertNull($updated['signup_bonus_eur']);
    }
}
