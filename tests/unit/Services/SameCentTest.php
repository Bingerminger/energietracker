<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (Paket K2a) — Derselbe Cent für denselben Vertrag in „Frühere Verträge“ (`current_balance`), Saldo-Karte
 * (`balance_measured`) und Abrechnungsjahr (Periode des Vertrags nach einem Lieferantenwechsel). Eine Rundung je Größe:
 *   Arbeitspreis  je Monat und Vertrag gerundet (wie `kwh_cost` der Monatszeile), summiert
 *   Grundpreis    über die Tage, einmal gerundet; Abschläge ebenso
 *   Saldo         aus den gerundeten Beträgen (Kosten − Abschläge + Sonderzahlungen)
 * Bis v3.12 summierte die Vertragszeile die ungerundeten Teile, und „Rechnung prüfen“ samt Abrechnungsjahr rundeten je
 * Abschnitt (eine Preisänderung am 15. teilte den Monat): je einen Cent auseinander.
 *
 * Handbeispiel (Strom, Wechsel zum 15.10.2024, neuer Vertrag 33,2 ct/kWh, 11,13 €/Monat, Abschlag 77 €, 9,7 kWh je Tag):
 *   Arbeitspreis  Oktober 17 Tage 164,9 kWh = 54,75 + November 291 kWh = 96,61 + Dezember 300,7 kWh = 99,83 = 251,19 €
 *   Grundpreis    11,13 € × 17/31 + 2 × 11,13 € = 28,3635 → 28,36 €
 *   Abschläge     77 € × 17/31 + 2 × 77 € = 196,2258 → 196,23 €
 *   Saldo         251,19 + 28,36 − 196,23 = 83,32 € (bis v3.12 in „Frühere Verträge“ aus den ungerundeten Teilen 83,33 €)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class SameCentTest extends ServiceTestCase
{
    private const TODAY = '2026-03-10';

    /** @return array<string,array{0:string,1:bool,2:int,3:string,4:float,5:float,6:float}> */
    public static function grid(): array
    {
        $out = [];
        foreach ([1, 13] as $day) {
            foreach ([['strom', false], ['wasser', false], ['wasser', true]] as [$u, $garden]) {
                foreach (['2024-10-15', '2024-04-10', '2024-07-21'] as $switch) {
                    foreach ([[27.3, 7.77, 41.0], [31.9, 11.13, 77.0], [24.45, 9.99, 53.0]] as [$wp, $bp, $adv]) {
                        $out[sprintf('%s%s, Stand am %d., Wechsel %s, %s ct', $u, $garden ? ' mit Garten' : '', $day, $switch, $wp)] = [$u, $garden, $day, $switch, $wp, $bp, $adv];
                    }
                }
            }
        }
        return $out;
    }

    /** Stände an `$day` jedes Monats (dazu 01.01. und 01.01. des Folgejahres), alter Vertrag mit Preisänderung am 15. */
    private function household(string $u, bool $garden, int $day, string $switch, float $wp, float $bp, float $adv): array
    {
        $this->settings->set(["billing_cycle_anchor_$u" => '01-01']);
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2023-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write("$u/meters.json", [
            ['id' => 'm_h', 'name' => 'Haus', 'icon' => '', 'created_at' => '2023-12-01', 'active' => true, 'notes' => '', 'devices' => $dev('d_h')],
            ['id' => 'm_g', 'name' => 'Garten', 'icon' => '', 'created_at' => '2023-12-01', 'active' => true, 'notes' => '', 'devices' => $dev('d_g'),
             'parent_meter_id' => 'm_h', 'role' => 'garden'],
        ]);
        $dates = $day === 1 ? array_map(fn($i) => date('Y-m-d', (int)strtotime("2024-01-01 +$i months")), range(0, 12))
            : ['2024-01-01', ...array_map(fn($i) => sprintf('2024-%02d-%02d', $i, $day), range(1, 12)), '2025-01-01'];
        $rows = [];
        foreach ($dates as $i => $d) {
            $n = (int)(new \DateTimeImmutable('2024-01-01'))->diff(new \DateTimeImmutable($d))->days;
            $rows[] = ['id' => "r$i", 'meter_id' => 'm_h', 'device_id' => 'd_h', 'date' => $d, 'counter' => round(($u === 'wasser' ? 0.37 : 9.7) * $n, 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            if ($garden) $rows[] = ['id' => "g$i", 'meter_id' => 'm_g', 'device_id' => 'd_g', 'date' => $d, 'counter' => round(0.021 * $n, 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write("$u/readings.json", $rows);
        $change = date('Y-m-d', (int)strtotime(substr($switch, 0, 8) . '15 -2 months'));
        $make = function (string $provider, string $from, string $to, float $price, bool $withChange) use ($u, $garden, $bp, $adv, $change): array {
            $wpl = [['from' => $from, 'ct_per_kwh' => $price]];
            $tw = [['from' => $from, 'ct_per_m3' => $price * 7]];
            if ($withChange) { $wpl[] = ['from' => $change, 'ct_per_kwh' => $price + 2.1]; $tw[] = ['from' => $change, 'ct_per_m3' => $price * 7 + 50]; }
            $c = ['meter_id' => 'm_h', 'provider' => $provider, 'start' => $from, 'end' => $to, 'auto_renews' => false,
                  'advance_payments' => [['from' => $from, 'amount_eur' => $adv]]];
            return $this->contracts->create($u, $c + ($u === 'wasser'
                ? ['trinkwasser' => ['working_prices' => $tw, 'base_prices' => [['from' => $from, 'eur_per_month' => $bp]]],
                   'schmutzwasser' => ($garden ? ['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => ['m_g']] : ['basis' => 'trinkwasser'])
                       + ['working_prices' => [['from' => $from, 'ct_per_m3' => $price * 9]]]]
                : ['working_prices' => $wpl, 'base_prices' => [['from' => $from, 'eur_per_month' => $bp]]]));
        };
        $old = $make('Altwerk Musterstadt', '2024-01-01', date('Y-m-d', (int)strtotime("$switch -1 day")), $wp, true);
        $new = $make('Neuwerk Musterland', $switch, '2024-12-31', $wp + 1.3, false);
        return [[$old, '2024-01-01'], [$new, $switch]];
    }

    /** @return list<array{0:float,1:float,2:float,3:array,4:array}> je Vertrag: „Frühere Verträge“, Karte, Abrechnungsjahr, Teile Karte, Teile Jahr */
    private function views(string $u, array $pairs): array
    {
        $meter = $this->meters->get($u, 'm_h');
        $status = array_column($this->consumption->contractStatus($u, $meter, self::TODAY)['contracts'], null, 'contract_id');
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = array_column((new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget($u, $meter, false, self::TODAY)['periods'], null, 'from');
        return array_map(function ($p) use ($status, $periods) {
            $s = $status[$p[0]['id']]; $y = $periods[$p[1]];
            return [$s['current_balance'], $s['balance_measured']['balance'], $y['balance'],
                    [$s['balance_measured']['base'], $s['balance_measured']['advance']], [$y['fixed_cost'], $y['advances']]];
        }, $pairs);
    }

    /**
     * Für jeden Fall des Rasters: „Frühere Verträge“ = Karte, Grundpreis und Abschläge im Abrechnungsjahr = Karte. Den Saldo
     * des Abrechnungsjahres prüft das Raster beim neuen Vertrag mit Ständen am Monatsersten (ohne Preisänderung im Monat):
     * Der Arbeitspreis rundet dort wie eine Zeile der Rechnung je Abschnitt, die Monatszeile je Monat (Bekannte Grenze).
     */
    #[DataProvider('grid')]
    public function testTheSameCentInAllThreeViews(string $u, bool $garden, int $day, string $switch, float $wp, float $bp, float $adv): void
    {
        foreach ($this->views($u, $this->household($u, $garden, $day, $switch, $wp, $bp, $adv)) as $i => [$past, $card, $year, $cardParts, $yearParts]) {
            self::assertSame($card, $past, "Vertrag $i: „Frühere Verträge“ = Karte");
            self::assertSame($cardParts, $yearParts, "Vertrag $i: Grundpreis und Abschläge im Abrechnungsjahr = Karte");
            if ($i === 1 && $day === 1) self::assertSame($card, $year, 'neuer Vertrag: Abrechnungsjahr = Karte');
        }
    }

    public function testTheHandExample(): void
    {
        $pairs = $this->household('strom', false, 1, '2024-10-15', 31.9, 11.13, 77.0);
        $status = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_h'), self::TODAY)['contracts'], null, 'contract_id');
        $c = $status[$pairs[1][0]['id']];
        self::assertSame([251.19, 28.36, 196.23, 83.32], [$c['energy_cost_to_date'], $c['base_to_date'], $c['advance_paid'], $c['current_balance']]);
        self::assertSame([83.32, 83.32, 83.32], array_slice($this->views('strom', $pairs)[1], 0, 3));
    }
}
