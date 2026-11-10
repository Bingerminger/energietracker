<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — Vertragseingaben streng wie die übrigen Schreibwege:
 *   - `end: []` (oder `{}`, `0`, `false`) wurde trotz 500 gespeichert und legte danach Übersicht, Zu tun,
 *     Agenda und Jahresbericht lahm; jetzt 400, nichts gespeichert. Offen heißt null oder leer.
 *   - `is_shadow: "false"` machte den laufenden Vertrag zum Schattenvertrag (er fiel aus dem Saldo),
 *     `auto_renews: "nein"` hieß „verlängert sich“; jetzt Support\Input::bool, Unsinn 400.
 *   - Preislisten als Text oder Zahl ergaben 500 (TypeError), ein Objekt wurde still leer; jetzt 400.
 *   - −1 ct/kWh, −1 €/Monat und 10³⁰⁸ wurden angenommen; jetzt 400.
 *   - Kündigungsfrist „1.5“ wurde still 1, 10³⁰⁸ Tage 500; eine Kundennummer als Liste fiel still weg.
 */
#[CoversClass(ContractService::class)]
final class ContractInputStrictTest extends ServiceTestCase
{
    private string $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = (string)$this->meters->list('strom')[0]['id'];
    }

    /** @return array<string,mixed> */
    private function base(array $over = []): array
    {
        return array_merge([
            'meter_id' => $this->meter, 'provider' => 'Probe', 'start' => '2025-01-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80]],
        ], $over);
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function rejected(): array
    {
        return [
            'Ende als leere Liste'      => [['end' => []]],
            'Ende als Objekt'           => [['end' => ['a' => 1]]],
            'Ende 0'                    => [['end' => 0]],
            'Ende false'                => [['end' => false]],
            'Ende "0"'                  => [['end' => '0']],
            'Schatten als Wort'         => [['is_shadow' => 'vielleicht']],
            'Schatten als Liste'        => [['is_shadow' => []]],
            'Verlängert als Liste'      => [['auto_renews' => []]],
            'Verlängert als Wort'       => [['auto_renews' => 'immer']],
            'Arbeitspreise als Text'    => [['working_prices' => 'abc']],
            'Grundpreise als Zahl'      => [['base_prices' => 5]],
            'Abschläge als Wahrheitswert' => [['advance_payments' => true]],
            'Boni als Text'             => [['bonuses' => 'abc']],
            'Sonderzahlungen als Zahl'  => [['special_payments' => 7]],
            'Preisliste als Objekt'     => [['working_prices' => ['a' => 1]]],
            'Preiszeile als Text'       => [['working_prices' => ['abc']]],
            'Datum der Preiszeile als Liste' => [['working_prices' => [['from' => ['2025-01-01'], 'ct_per_kwh' => 30]]]],
            'Arbeitspreis negativ'      => [['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => -1]]]],
            'Grundpreis negativ'        => [['base_prices' => [['from' => '2025-01-01', 'eur_per_month' => -1]]]],
            'Abschlag negativ'          => [['advance_payments' => [['from' => '2025-01-01', 'amount_eur' => -1]]]],
            'Arbeitspreis 10^308'       => [['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 1e308]]]],
            'Bonus 10^308'              => [['signup_bonus_eur' => 1e308]],
            'Frist 1,5 Monate'          => [['notice_period_months' => '1.5']],
            'Frist 10^308 Tage'         => [['notice_period_days' => 1e308]],
            'Frist 10^308 Monate'       => [['notice_period_months' => 1e308]],
            'Frist als Wahrheitswert'   => [['notice_period_months' => true]],
            'Kundennummer als Liste'    => [['customer_number' => ['K1']]],
            'Kundennummer true'         => [['customer_number' => true]],
        ];
    }

    #[DataProvider('rejected')]
    public function testCreateRejectsAndStoresNothing(array $over): void
    {
        $before = $this->contracts->list('strom');
        try {
            $this->contracts->create('strom', $this->base($over));
            self::fail('angenommen: ' . json_encode($over));
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($before, $this->contracts->list('strom'), 'nichts gespeichert');
    }

    #[DataProvider('rejected')]
    public function testPatchRejectsAndKeepsTheContract(array $over): void
    {
        $c = $this->contracts->create('strom', $this->base());
        $before = $this->contracts->list('strom');
        try {
            $this->contracts->update('strom', (string)$c['id'], $over);
            self::fail('angenommen: ' . json_encode($over));
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($before, $this->contracts->list('strom'), 'Vertrag unverändert');
    }

    public function testBooleansReadLikeEverywhereElse(): void
    {
        $c = $this->contracts->create('strom', $this->base(['is_shadow' => 'false', 'auto_renews' => 'nein']));
        self::assertFalse($c['is_shadow'], '„false“ ist kein Schattenvertrag');
        self::assertFalse($c['auto_renews'], '„nein“ verlängert sich nicht');
        $p = $this->contracts->update('strom', (string)$c['id'], ['is_shadow' => 'nein', 'auto_renews' => 'ja']);
        self::assertFalse($p['is_shadow']);
        self::assertTrue($p['auto_renews']);
        $q = $this->contracts->update('strom', (string)$c['id'], ['is_shadow' => 1, 'auto_renews' => '']);
        self::assertTrue($q['is_shadow']);
        self::assertNull($q['auto_renews'], 'leer = nicht angegeben');
        // offen bleibt offen
        self::assertNull($this->contracts->update('strom', (string)$c['id'], ['end' => ''])['end']);
        self::assertNull($this->contracts->update('strom', (string)$c['id'], ['end' => null])['end']);
    }

    /**
     * Ein laufender Vertrag mit `is_shadow: "false"` zählt im Saldo (bisher fiel er heraus). Handrechnung:
     * 2025 ohne Ablesung, Abschläge 12 × 80 € = 960 € bis 31.12.2025; die Prüfung braucht nur, dass der
     * Vertrag als aktueller Vertrag gefunden wird.
     */
    public function testAContractWithFalseAsTextStaysInTheBalance(): void
    {
        $c = $this->contracts->create('strom', $this->base(['is_shadow' => 'false']));
        $active = $this->contracts->findActiveForDate(array_filter($this->contracts->list('strom'), fn($x) => empty($x['is_shadow'])), '2025-06-15');
        self::assertSame($c['id'], $active['id'] ?? null);
    }

    public function testNumbersInRangeStillWork(): void
    {
        $c = $this->contracts->create('strom', $this->base(['notice_period_months' => '3', 'notice_period_days' => 14.0,
            'customer_number' => 4711, 'signup_bonus_eur' => '50']));
        self::assertSame(3, $c['notice_period_months']);
        self::assertSame(14, $c['notice_period_days']);
        self::assertSame('4711', $c['customer_number'], 'eine Zahl ist Text');
        self::assertSame(50.0, $c['signup_bonus_eur']);
    }
}
