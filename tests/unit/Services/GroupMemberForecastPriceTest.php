<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Ein Zählwerk, das über einen Gruppenvertrag rechnet: Seine Prognosemonate tragen den Gruppenvertrag
 * (`contract_id`). Hat er für dieses Zählwerk keinen Arbeitspreis, heißen sie „Preis fehlt“ (`price_missing`), in der
 * Prognose des Zählwerks, in `/api/summary` (`forecast_12m.price_missing`) und in der KI, wie Karte, Abrechnungsjahr und
 * Prognose der Gruppe. Bis dahin rechneten die Prognosemonate ohne Vertrag (`contract_id: null`) mit 0 ct, und
 * `forecast_12m` nannte 0,00 € Kosten ohne Kennzeichen.
 *
 * Erfunden: HT/NT-Gruppe, je Zählwerk Ablesung am Monatsersten 01.01.2024 bis 01.10.2026 (HT 5 kWh, NT 3 kWh je Tag),
 * Gruppenvertrag ab 01.01.2024 (Grundpreis 9 €/Monat, Abschlag 95 €), Arbeitspreis nach Zählwerk. Heute 15.10.2026.
 */
#[CoversClass(ForecastService::class)]
#[CoversClass(SummaryService::class)]
final class GroupMemberForecastPriceTest extends McpHouseholdTestCase
{
    private const STAND = '2026-10-15';

    /** @param array<string,mixed> $prices Arbeitspreise des Gruppenvertrags (working_prices, working_prices_by_meter) */
    private function group(array $prices): void
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $name, string $obis) => ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => '2020-01-01',
            'active' => true, 'notes' => '', 'obis' => $obis, 'meter_group_id' => 'g_dt', 'devices' => $dev($id)];
        $this->store->write('strom/meters.json', [$meter('m_ht', 'HT', '1.8.1'), $meter('m_nt', 'NT', '1.8.2')]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_dt', 'name' => 'Doppeltarif', 'created_at' => '2020-01-01']]);
        $by = [];
        foreach (['m_ht' => 5.0, 'm_nt' => 3.0] as $id => $perDay) {
            for ($d = new \DateTimeImmutable('2024-01-01'); $d->format('Y-m-d') <= '2026-10-01'; $d = $d->modify('first day of next month')) {
                $by[$id][] = [$d->format('Y-m-d'), $perDay * (int)(new \DateTimeImmutable('2024-01-01'))->diff($d)->days];
            }
        }
        $this->counters('strom', $by);
        $this->contracts->create('strom', ['meter_id' => null, 'meter_group_id' => 'g_dt', 'provider' => 'Musterwerk', 'start' => '2024-01-01',
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 9.0]], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 95.0]]] + $prices);
    }

    /** @return array<string,mixed> Zählwerk in /api/summary */
    private function summaryMeter(string $id): array
    {
        foreach ($this->svc(SummaryService::class)->build('strom', null, self::STAND)['meters'] as $m) if ($m['meter_id'] === $id) return $m;
        self::fail("Zählwerk $id fehlt");
    }

    public function testARegisterWithoutAPriceInTheGroupContractIsPriceMissingInItsForecast(): void
    {
        $this->group(['working_prices' => [], 'working_prices_by_meter' => ['m_ht' => [['from' => '2024-01-01', 'ct_per_kwh' => 27.0]]]]);
        $cid = (string)$this->store->read('strom/contracts.json', [])[0]['id'];
        $fc = $this->consumption->atDate(self::STAND, fn() => $this->svc(ForecastService::class)->forMeter('strom', $this->meters->get('strom', 'm_nt')));
        $months = array_slice($fc['forecast'], 0, 12);
        self::assertSame([$cid], array_values(array_unique(array_column($months, 'contract_id'))), 'Prognosemonate tragen den Gruppenvertrag');
        self::assertCount(12, $this->consumption->priceMissingIn('strom', $this->meters->get('strom', 'm_nt'), $months), 'jeder Monat ohne Preis');
        // Ansicht gegen Ansicht: Zählwerk, Gruppe und KI sagen dasselbe
        $nt = $this->summaryMeter('m_nt');
        self::assertTrue($nt['forecast_12m']['price_missing'] ?? false, '/api/summary: forecast_12m.price_missing am NT-Zählwerk');
        self::assertTrue($nt['group_forecast_12m']['price_missing'] ?? false, 'die Prognose der Gruppe ebenso');
        self::assertArrayNotHasKey('price_missing', $this->summaryMeter('m_ht')['forecast_12m'], 'HT hat einen Preis');
        $ki = $this->tool('get_forecast', ['utility' => 'strom', 'meter' => 'm_nt'], self::STAND);
        self::assertTrue($ki['total']['price_missing'] ?? false, 'KI: Summe ohne Kostenbetrag');
        self::assertArrayNotHasKey('cost', $ki['total']);
        self::assertSame(['g_dt'], array_values(array_unique([$ki['priced_by_group'] ?? null])), 'die Gruppe trägt die Kosten');
    }

    /**
     * Gegenproben: mit Preis für beide Zählwerke (HT 27 ct, NT 19 ct) oder einem Preis für alle (25 ct) fehlt nichts; die
     * Prognosemonate tragen trotzdem den Gruppenvertrag.
     *
     * @return array<string, array{array<string,mixed>}>
     */
    public static function priced(): array
    {
        return [
            'Preis je Zählwerk' => [['working_prices' => [], 'working_prices_by_meter' => ['m_ht' => [['from' => '2024-01-01', 'ct_per_kwh' => 27.0]],
                'm_nt' => [['from' => '2024-01-01', 'ct_per_kwh' => 19.0]]]]],
            'ein Preis für alle' => [['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 25.0]]]],
        ];
    }

    #[DataProvider('priced')]
    public function testWithAPriceForTheRegisterNothingIsMissing(array $prices): void
    {
        $this->group($prices);
        $nt = $this->summaryMeter('m_nt');
        self::assertArrayNotHasKey('price_missing', $nt['forecast_12m']);
        self::assertGreaterThan(0.0, (float)$nt['forecast_12m']['cost'], 'Kosten zum Preis des Zählwerks');
        $fc = $this->consumption->atDate(self::STAND, fn() => $this->svc(ForecastService::class)->forMeter('strom', $this->meters->get('strom', 'm_nt')));
        self::assertNotContains(null, array_column(array_slice($fc['forecast'], 0, 12), 'contract_id'));
    }
}
