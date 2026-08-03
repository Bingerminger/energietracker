<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ReminderService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.1.5 — Reminder-Datumslogik: Monatsende-Überlauf beim Fortschreiben von
 * next_due und Robustheit gegen ein kaputtes next_due (unvalidierte Daten).
 */
#[CoversClass(ReminderService::class)]
final class ReminderServiceTest extends ServiceTestCase
{
    private function svc(): ReminderService
    {
        return new ReminderService($this->store, $this->settings, $this->i18n);
    }

    public function testMarkDoneClampsMonthEndOverflow(): void
    {
        $svc = $this->svc();
        $rem = $svc->create([
            'title'      => 'Schornsteinfeger',
            'category'   => 'schornsteinfeger',
            'next_due'   => '2024-08-31',
            'recurrence' => 'semi-yearly',
        ]);
        // 31.08.2024 + 6 Monate → Februar 2025 (28 Tage) → auf den 28. geclamped,
        // NICHT der PHP-`+months`-Überlauf 03.03.2025.
        $done = $svc->markDone($rem['id'], '2024-08-31');
        self::assertSame('2025-02-28', $done['next_due']);
    }

    public function testMarkDoneYearlyKeepsTheDay(): void
    {
        $svc = $this->svc();
        $rem = $svc->create([
            'title'      => 'Heizungswartung',
            'category'   => 'heizung_wartung',
            'next_due'   => '2024-03-15',
            'recurrence' => 'yearly',
        ]);
        $done = $svc->markDone($rem['id'], '2024-03-15');
        self::assertSame('2025-03-15', $done['next_due']);
    }

    public function testListWithStatusToleratesMalformedNextDue(): void
    {
        // Unvalidierte Daten (Import/Legacy): ein kaputtes next_due darf
        // listWithStatus nicht mit einer DateTime-Exception sprengen.
        $this->store->write('reminders.json', [[
            'id'         => 'rem_bad',
            'title'      => 'Kaputt',
            'category'   => 'custom',
            'next_due'   => 'nicht-ein-datum',
            'recurrence' => 'none',
            'active'     => true,
        ]]);
        $out = $this->svc()->listWithStatus();
        self::assertCount(1, $out);
        self::assertSame('ok', $out[0]['status']);
        self::assertNull($out[0]['days_until']);
    }
}
