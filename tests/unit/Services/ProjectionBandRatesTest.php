<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (Skeptiker v3.9.0, R2-3) — die Breite der Saldo-Schätzung auch ohne Heizmodell (Strom,
 * Wasser): Streuung der Monatsmengen je Kalendermonat um den Wert, mit dem geschätzt wird.
 *
 * Erfundener Haushalt: Strom, 2023 genau 8 kWh am Tag, 2024 14, 2025 11 (Ablesung am Monatsersten
 * bis 01.01.2026); Vertrag ab 01.01.2026, 10 ct/kWh, Abschlag 40 €; heute 15.03.2026, Abrechnung
 * 31.12.2026 — das ganze Jahr 2026 ist geschätzt.
 *
 * Handrechnung: Je Kalendermonat die Werte 8, 14, 11 kWh/Tag, geschätzt mit dem Median 11
 * (ohne Wetterbezug ab drei Jahren). Abweichungen −3, +3, 0 kWh/Tag × Tage des Monats; je Monat
 * zwei Freiheitsgrade, zusammen 24. Σ Tage² eines Jahres = 7 × 31² + 4 × 30² + 28² = 11.111.
 *   σ_Monat = √(2 × 9 × 11.111 ÷ 24) kWh = 91,29 kWh je ganzem Monat (über der Untergrenze 10 %, rund 34 kWh)
 *   halbe Breite = 1,28 × √(12 × σ²) × 0,10 €/kWh = 1,28 × 3 × √11.111 × 0,10 € = 40,48 €
 * Kein Wetterteil. Die jüngsten Monate liegen auf dem Median, der Kalibrierfaktor ist 1.
 *
 * v3.10.0 (Skeptiker H2) — die Breite folgt nicht der Ablesedichte: Untergrenze 10 % der geschätzten
 * Menge je Monat (wie das Band der Prognose); verteilte Monate streuen nicht.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ReportAdviceService::class)]
final class ProjectionBandRatesTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-03-15';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->household(['2023' => 8.0, '2024' => 14.0, '2025' => 11.0], 1);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 40]]]);
    }

    /** Stände am Monatsersten 2023-01-01 … 2026-01-01, abgelesen alle $every Monate. */
    private function household(array $perDay, int $every): void
    {
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 0.0; $n = 0;
        for ($t = strtotime('2023-01-01'); $t <= strtotime('2026-01-01'); $t += 86400) {
            $d = date('Y-m-d', $t);
            if (substr($d, 8) === '01' && $n++ % $every === 0) $rows[] = ['date' => $d, 'counter' => round($c, 1), 'device_id' => 'd_a'];
            $c += $perDay[substr($d, 0, 4)] ?? 0.0;
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function card(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    public function testElectricityGetsABandWithoutWeather(): void
    {
        $c = $this->card();
        self::assertNull($c['projection_uncertain']);
        $b = $c['projection_band'];
        self::assertNotNull($b, 'bis v3.9 ohne Breite (nur Heizarten)');
        $half = round(1.28 * 3 * sqrt(11111) * 0.10, 2);
        self::assertSame(40.48, $half, 'Handrechnung');
        self::assertEqualsWithDelta($half, $b['half'], 0.01);
        self::assertSame(0.0, $b['weather'], 'Strom hängt nicht am Wetter');
        self::assertFalse($b['few_measured']);
        self::assertSame(365, $b['estimated_days']);
        // 365 Tage × 11 kWh × 0,10 € − 12 × 40 € = 401,50 − 480 = −78,50 €
        self::assertEqualsWithDelta(-78.5, $c['projected_end_balance'], 0.01);
        self::assertEqualsWithDelta(-78.5 - $half, $b['low'], 0.011);
    }

    /**
     * Skeptiker H2 — dieselbe Physik (10,9 / 11,1 / 11,0 kWh am Tag), einmal monatlich, einmal jährlich
     * abgelesen. Geschätzt wird beide Male mit 11 kWh am Tag, das ganze Jahr 2026. Die Streuung der
     * Kalendermonate (√(0,02 × 11.111 ÷ 24) ≈ 3,0 kWh) liegt unter der Untergrenze 10 % × 11 kWh × Tage:
     *   halbe Breite = 1,28 × 1,1 × √11.111 × 0,10 € = 14,84 € — bei beiden.
     * Bis dahin rechnete das jährliche Ablesen aus verteilten Monaten 1,28 × √(12 × 9,26) × 0,10 € = 1,35 €.
     */
    public function testRareReadingDoesNotNarrowTheBand(): void
    {
        $half = round(1.28 * 1.1 * sqrt(11111) * 0.10, 2);
        self::assertSame(14.84, $half, 'Handrechnung');
        $bands = [];
        foreach (['monatlich' => 1, 'jährlich' => 12] as $name => $every) {
            $this->household(['2023' => 10.9, '2024' => 11.1, '2025' => 11.0], $every);
            $c = $this->card();
            self::assertEqualsWithDelta(-78.5, $c['projected_end_balance'], 0.01, $name);
            $bands[$name] = $c['projection_band'];
            self::assertNotNull($bands[$name], $name);
            self::assertEqualsWithDelta($half, $bands[$name]['half'], 0.01, $name);
        }
        self::assertFalse($bands['monatlich']['few_measured']);
        self::assertTrue($bands['jährlich']['few_measured'], 'nur verteilte Monate: „zu wenige gemessene Monate“');
    }

    /** Lektion 99 — die Beispiele der Grundlagen (DE und EN) sind die Breiten, die die App rechnet. */
    public function testTheDocsExamplesAreWhatTheAppComputes(): void
    {
        $wide = $this->card()['projection_band']['half'];
        $this->household(['2023' => 10.9, '2024' => 11.1, '2025' => 11.0], 12);
        $floor = $this->card()['projection_band']['half'];
        $want = ['de' => [number_format($wide, 2, ',', '.') . ' €', number_format($floor, 2, ',', '.') . ' €'],
                 'en' => ['€' . number_format($wide, 2), '€' . number_format($floor, 2)]];
        foreach (['de' => 'docs/verstehen/00-overview.md', 'en' => 'docs/en/verstehen/00-overview.md'] as $lang => $file) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pos = strpos($text, '<!-- doc-claim: rates-band-example');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, (int)strpos($text, "```\n", (int)strpos($text, '```text', $pos) + 7) - $pos);
            foreach ($want[$lang] as $v) self::assertStringContainsString($v, $block, "$file: $v");
        }
    }

    public function testOneYearOfDataGivesOnlyTheFloor(): void
    {
        // nur 2025 (11 kWh am Tag): je Kalendermonat ein Wert, keine Streuung messbar → nur die Untergrenze
        $this->setReadings('strom', $this->meterId, array_values(array_filter($this->store->read('strom/readings.json', []),
            fn($r) => $r['date'] >= '2025-01-01')));
        $b = $this->card()['projection_band'];
        self::assertTrue($b['few_measured']);
        self::assertEqualsWithDelta(round(1.28 * 1.1 * sqrt(11111) * 0.10, 2), $b['half'], 0.01);
    }

    public function testTheAnnualReportShowsTheSameBand(): void
    {
        // Jahresbericht 2026: derselbe Satz wie die Karte, „Wohin geht mein Geld?“ bekommt die Breite (eine Quelle).
        // Eine Ablesung am 01.03.2026 (11 kWh am Tag), damit das Jahr Kosten hat
        $rows = $this->store->read('strom/readings.json', []);
        $rows[] = ['id' => 'r_mar', 'meter_id' => $this->meterId, 'device_id' => 'd_a', 'date' => '2026-03-01',
            'counter' => (float)end($rows)['counter'] + 59 * 11.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('strom/readings.json', $rows);
        $card = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
        $band = $card['projection_band'];
        self::assertNotNull($band);
        $m = $this->reportServices()['model']->build(2026, ['today' => self::TODAY]);
        $strom = array_values(array_filter($m['money']['balances'] ?? [], fn($b) => $b['utility'] === 'strom'))[0] ?? null;
        self::assertNotNull($strom);
        $advice = new ReportAdviceService($this->i18n);
        self::assertStringContainsString($advice->bandSentence($band), $strom['text']);
        self::assertStringStartsWith('Je nach Schätzung zwischen ca. ', $advice->bandSentence($band));
        self::assertSame($band['half'], $strom['band']['half']);
        self::assertSame($band['half'], $strom['explainer']['projection_band']['half']);
        // v3.10.0 (Skeptiker N12) — „Auf einen Blick“ nennt dieselbe Breite, kein Punktwert
        $light = array_values(array_filter($m['headline']['lights'], fn($l) => $l['key'] === 'billing'))[0] ?? null;
        [$lo, $hi] = ReportAdviceService::balanceRangeEnds([$band['low'], $band['high']]);
        self::assertSame(sprintf('Strom: zur Abrechnung voraussichtlich zwischen ca. %d € Guthaben und ca. %d € Guthaben.', round(-$lo), round(-$hi)),
            $light['detail'] ?? null);
    }

    public function testTheReportSaysItInWords(): void
    {
        $advice = new ReportAdviceService($this->i18n);
        self::assertSame('Je nach Schätzung zwischen ca. 65 € Guthaben und ca. 92 € Guthaben.',
            $advice->bandSentence(['low' => -91.99, 'high' => -65.01, 'weather' => 0.0]));
        self::assertSame('Je nach Wetter und Schätzung zwischen ca. 20 € Guthaben und ca. 30 € Nachzahlung.',
            $advice->bandSentence(['low' => -20.0, 'high' => 30.0, 'weather' => 4.0]));
        // v3.10.0 (Skeptiker H2) — nur die Untergrenze: der Satz sagt es
        self::assertSame('Zu wenige gemessene Monate, je nach Schätzung zwischen ca. 65 € Guthaben und ca. 92 € Guthaben.',
            $advice->bandSentence(['low' => -91.99, 'high' => -65.01, 'weather' => 0.0, 'few_measured' => true]));
    }
}
