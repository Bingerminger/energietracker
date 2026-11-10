<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — „Lohnt sich“ nennt die Richtung: Ein Angebot mit niedrigerem Arbeitspreis und höherem Grundpreis ist
 * oberhalb der Schnittmenge günstiger („ab“), eins mit höherem Arbeitspreis und niedrigerem Grundpreis nur darunter
 * („bis“). Bisher stand die Menge immer unter „Lohnt ab“; wer mehr verbrauchte, las „lohnt sich“, obwohl das Angebot
 * dann teurer war.
 *
 * Erfundener Haushalt: Strom nach Saisonprofil, laufender Vertrag 30 ct + 12 €/Monat bis 31.12.2026, Wechsel
 * 01.01.2027, verglichen wird Jahr 2 (2028). Von Hand mit x = Jahresmenge:
 *   Vertrag  0,30 x + 144 €
 *   „Fest“   0,26 x + 180 € → gleich bei x = 36 / 0,04 = 900 kWh; darüber günstiger (0,26 < 0,30) → ab
 *   „Klein“  0,34 x + 120 € → gleich bei x = 24 / 0,04 = 600 kWh; darunter günstiger (0,34 > 0,30) → bis
 */
#[CoversClass(TariffSwitchService::class)]
final class TariffSwitchBreakEvenDirectionTest extends ServiceTestCase
{
    private const PROFILE = [
        1 => 700, 2 => 640, 3 => 560, 4 => 420, 5 => 320, 6 => 260,
        7 => 230, 8 => 240, 9 => 300, 10 => 430, 11 => 580, 12 => 690,
    ];

    /** @return array<string,array<string,mixed>> Kandidaten nach Name */
    private function candidates(): array
    {
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $meterId = $this->setMeterDevices('strom', [['id' => 'd_r', 'serial' => null, 'installed_on' => '2023-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $counter = 200.0;
        $cur = new \DateTimeImmutable('2024-01-01');
        $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_r'];
        while ($cur < new \DateTimeImmutable('2026-07-01')) {
            $counter += self::PROFILE[(int)$cur->format('n')];
            $cur = $cur->modify('+1 month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_r'];
        }
        $this->setReadings('strom', $meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2026-01-01', 'end' => '2026-12-31', 'notice_period_months' => 1,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]]]);
        foreach (['Fest' => [26.0, 15.0], 'Klein' => [34.0, 10.0]] as $label => [$ct, $eur]) {
            $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Anbieter', 'tariff_name' => $label,
                'start' => '2026-01-01', 'is_shadow' => true, 'shadow_label' => $label,
                'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => $ct]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => $eur]]]);
        }
        $r = $switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        return array_column($r['candidates'], null, 'label');
    }

    public function testACheaperUnitPricePaysOffAboveTheCrossing(): void
    {
        $c = $this->candidates()['Fest'];
        self::assertEqualsWithDelta(900.0, $c['break_even_consumption'], 5.0);
        self::assertSame('above', $c['break_even_direction']);
    }

    public function testADearerUnitPricePaysOffOnlyBelowTheCrossing(): void
    {
        $c = $this->candidates()['Klein'];
        self::assertEqualsWithDelta(600.0, $c['break_even_consumption'], 5.0);
        self::assertSame('below', $c['break_even_direction']);
        self::assertGreaterThan(0.0, $c['vs_reference_year2_eur'], 'beim erwarteten Verbrauch (weit über 600 kWh) teurer');
    }

    /** Die Doku nennt beim Beispiel der Preisstaffel (34 ct, 10 € gegen 30 ct, 12 €) die Richtung: „bis 600 kWh“. */
    public function testTheDocumentedStepExampleSaysUpTo(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/ansichten.md' => 'bis 600 kWh', 'docs/en/referenz/ansichten.md' => 'up to 600 kWh'] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: tariff-switch-year-two');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            self::assertStringContainsString($want, substr($text, $pos, 1200), $file);
        }
    }
}
