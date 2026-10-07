<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H6, B7/MKT-12) — Großhandelspreise (SMARD), Dynamik-Check als
 * Schattenvertrag, Monatspreise aus einer Datei.
 */
#[CoversClass(MarketPriceService::class)]
#[CoversClass(TariffComparisonService::class)]
final class MarketPriceTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function market(?\Closure $fetch = null): MarketPriceService
    {
        return new MarketPriceService($this->store, $this->i18n, $fetch);
    }

    /** SMARD-Download: Stunden- und Viertelstundenwerte, Dezimalkomma, „-" fehlt, Tage mit 23 und 25 Stunden. */
    public function testSmardDownloadIsAveragedPerMonth(): void
    {
        $csv = "\u{FEFF}Datum von;Datum bis;Deutschland/Luxemburg [€/MWh] Originalauflösungen;Österreich [€/MWh] Originalauflösungen\n";
        // 30.03.2025: Umstellung auf Sommerzeit, 23 Stunden — zählt mit seinen Werten
        foreach (range(0, 22) as $h) $csv .= sprintf("30.03.2025 %02d:00;30.03.2025 %02d:00;100,00;1\n", $h, $h + 1);
        $csv .= "31.03.2025 00:00;31.03.2025 01:00;-;1\n";
        // 26.10.2025: 25 Stunden, Viertelstunden
        foreach (range(0, 99) as $q) $csv .= "26.10.2025 00:00;26.10.2025 00:15;" . ($q < 50 ? '80,00' : '120,00') . ";1\n";
        $dry = $this->market()->importCsv($csv, true);
        self::assertSame(2, $dry['would_import']);
        self::assertSame([], $this->market()->get()['months'], 'Trockenlauf schreibt nichts');
        $r = $this->market()->importCsv($csv);
        self::assertSame(['2025-03', '2025-10'], [$r['from'], $r['to']]);
        $m = $this->market()->get()['months'];
        self::assertEquals(10.0, $m['2025-03']['avg_ct'], '100 €/MWh = 10 ct/kWh');
        self::assertEquals(10.0, $m['2025-10']['avg_ct']);
        // einfaches Format; ersetzt nur seine Monate
        $this->market()->importCsv("2025-03;90\n2025-04;-12,5\n");
        $m = $this->market()->get()['months'];
        self::assertEquals([9.0, -1.25, 10.0], [$m['2025-03']['avg_ct'], $m['2025-04']['avg_ct'], $m['2025-10']['avg_ct']]);
        $this->expectException(LocalizedException::class);
        $this->market()->importCsv("Datum von;Datum bis;Preis\n");
    }

    public function testSyncReadsTheMonthlySeries(): void
    {
        $urls = [];
        $fetch = function (string $url) use (&$urls): string {
            $urls[] = $url;
            if (str_ends_with($url, 'index_month.json')) return '{"timestamps":[1735686000000,1767222000000]}';
            if (str_contains($url, '1735686000000')) return '{"series":[[1735686000000,114.14],[1751320800000,87.8]]}';
            return '{"series":[[1767222000000,110.09],[1790805600000,null]]}';
        };
        $r = $this->market($fetch)->syncSmard();
        self::assertSame(3, $r['months']);
        self::assertStringStartsWith('https://www.smard.de/app/chart_data/4169/DE/', $urls[0]);
        $m = $this->market()->get()['months'];
        self::assertSame(['2025-01', '2025-07', '2026-01'], array_keys($m), 'Monatsbeginn deutscher Zeit; null übersprungen');
        self::assertSame(11.414, $m['2025-01']['avg_ct']);
        // künftiger Monat: derselbe Monat im Vorjahr, als Annahme
        self::assertSame(['ct' => 8.78, 'assumed' => true, 'from' => '2025-07'], $this->market()->monthCt('2026-07'));
        self::assertNull($this->market()->monthCt('2026-05'));
    }

    /** Handrechnung: 3.000 kWh, Spot 10 ct, USt 19 %, Aufschlag 15 ct, Grundpreis 10 € → 3.000 × 26,9 ct + 120 € = 927 €. */
    public function testDynamicShadowContractInTheComparison(): void
    {
        $rows = [];
        for ($m = 0; $m <= 12; $m++) {
            $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'A', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]]]);
        $dyn = $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'B', 'start' => '2025-01-01',
            'is_shadow' => true, 'shadow_label' => 'Dynamisch', 'price_model' => 'dynamic',
            'dynamic' => ['markup_ct_per_kwh' => '15', 'base_eur_month' => 10, 'vat_pct' => 19]]);
        self::assertSame(['markup_ct_per_kwh' => 15.0, 'base_eur_month' => 10.0, 'vat_pct' => 19.0, 'weighting' => 'flat'], $dyn['dynamic']);

        $cmp = fn() => (new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n, $this->market()))
            ->compare('strom', $this->meterId, 2025);
        $r = $cmp();
        self::assertTrue($r['dynamic_missing_market'], 'ohne Marktdaten kein Kandidat');
        self::assertCount(1, $r['rows']);

        $this->market()->importCsv(implode("\n", array_map(fn($m) => sprintf('2025-%02d;100', $m), range(1, 12))));
        $r = $cmp();
        $row = array_values(array_filter($r['rows'], fn($x) => $x['contract_id'] === $dyn['id']))[0];
        self::assertSame('dynamic', $row['price_model']);
        self::assertEqualsWithDelta(927.0, $row['total_eur'], 0.05);
        self::assertFalse($r['dynamic_missing_market']);

        // Wechsel: der dynamische Kandidat rechnet künftige Monate nach dem Vorjahr
        $switch = (new TariffSwitchService(new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $this->contracts, $this->meters, $this->i18n, $this->market()))->analyze('strom', $this->meterId, ['today' => '2026-01-15']);
        $cand = array_values(array_filter($switch['candidates'], fn($c) => ($c['price_model'] ?? null) === 'dynamic'));
        self::assertCount(1, $cand);
        self::assertGreaterThan(0, $cand[0]['dynamic_assumed_months']);
    }

    public function testDynamicIsOnlyAShadowForElectricity(): void
    {
        foreach ([['strom', false], ['gas', true]] as [$u, $shadow]) {
            try {
                $this->contracts->create($u, ['provider' => 'X', 'start' => '2025-01-01', 'is_shadow' => $shadow, 'price_model' => 'dynamic']);
                self::fail("$u angenommen");
            } catch (LocalizedException $e) {
                self::assertSame('errors.contract.dynamicShadowOnly', $e->key);
            }
        }
        $this->expectException(LocalizedException::class);
        $this->contracts->create('strom', ['provider' => 'X', 'start' => '2025-01-01', 'price_model' => 'stündlich']);
    }

    public function testMonthlyPricesFromAFile(): void
    {
        $c = $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'A', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]]);
        $csv = "Monat;ct/kWh;Grundpreis\n01.2025;28,5;12\n2025-02;31\nunsinn;1\n";
        $dry = $this->contracts->importMonthlyPrices('strom', $c['id'], $csv, true);
        self::assertSame(2, $dry['would_import']);
        self::assertCount(1, $dry['errors']);
        self::assertArrayNotHasKey('price_model', $this->contracts->get('strom', $c['id']));
        $this->contracts->importMonthlyPrices('strom', $c['id'], $csv);
        $after = $this->contracts->get('strom', $c['id']);
        self::assertSame('monthly', $after['price_model']);
        self::assertEquals([['from' => '2025-01-01', 'ct_per_kwh' => 28.5], ['from' => '2025-02-01', 'ct_per_kwh' => 31.0]], $after['working_prices']);
        self::assertEquals([['from' => '2025-01-01', 'eur_per_month' => 12.0]], $after['base_prices']);
    }
}
