<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\LetterService;
use Energietracker\Tests\Support\LetterFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — Das Schaufenster zeigt die Kündigung ohne Lücken (Absender, Empfänger, Nummern, Termin). Am unbefristeten
 * Fernwärmevertrag fehlte die Kündigungsfrist: Der Termin stand als „[…]“ im Brief. Stichtag fest: der Stand der
 * Beispieldaten (bis Ende Mai 2026), wie in den Tests der KI-Schnittstelle.
 */
#[CoversClass(LetterService::class)]
final class LetterShowcaseTest extends ServiceTestCase
{
    use LetterFixture;

    private const SHOWCASE_DAY = '2026-06-15';

    /** @return array<string, array{string, string}> Art, Vertrag */
    public static function runningContracts(): array
    {
        return ['Gas' => ['gas', 'c_gas_004'], 'Strom' => ['strom', 'c_strom_004'], 'Fernwärme' => ['fernwaerme', 'c_fw_001']];
    }

    #[DataProvider('runningContracts')]
    public function testTheShowcaseTerminationHasNoGaps(string $utility, string $contract): void
    {
        $this->letterSetUp();
        $file = dirname(__DIR__, 3) . '/demo-data/energietracker-demo-backup.json';
        (new BackupService($this->store, $this->i18n))->import(json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $letters = new LetterService($this->rights, $this->contracts, $this->meters, $bills, consumption: $this->consumption, settings: $this->settings);
        $m = $letters->render(['utility' => $utility, 'contract_id' => $contract, 'kind' => 'termination'], self::SHOWCASE_DAY)['model'];
        self::assertSame([], $m['gaps'], "$utility: " . implode(', ', $m['gaps']));
        self::assertNull($m['term_gap']);
        self::assertStringNotContainsString('[…]', $m['text']);
    }
}
