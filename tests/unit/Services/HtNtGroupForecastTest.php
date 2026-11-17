<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.13.0 (Paket vertrag, #40) — HT/NT je Vertrag. Ein Doppeltarifzähler steht als zwei Zählwerke in einer Gruppe
 * mit Gruppenvertrag. Die Prognose je Zählwerk kennt den Gruppenvertrag nicht (kein Grundpreis, kein Abschlag);
 * die Gruppe ist das Ziel mit allen Kosten. Schwelle aus dem Paketplan für v3.13 (Zeile HT/NT), vorab festgelegt:
 *
 *   - Energie der Gruppe = HT + NT (± 0,1 kWh)
 *   - Gruppe − Summe der Zählwerke = Grundpreis (± 0,01 €, in ganzen Cent verglichen)
 *   - Jahresbericht „Erwartete Kosten“ = Gruppe (einmal, nicht je Zählwerk)
 *
 * Geprüft am Schaufenster (Hybrid-Wärmepumpe mit Doppeltarifzähler hinter dem Hauptzähler) und an einem von Hand
 * gerechneten Kleinfall; dazu ein Fall, in dem HT und NT über die Jahre verschieden laufen (Median je
 * Kalendermonat): Dort lag die Gruppe bis v3.12 neben HT + NT.
 */
final class HtNtGroupForecastTest extends ServiceTestCase
{
    use ReportFixture;

    private const SHOWCASE_TODAY = '2026-06-15';   // kurz nach dem Stand der Beispieldaten (31.05.2026)

    private function forecasts(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    /** @return array{kwh:float,cents:int,fixed_cents:int,advance:float} Summen der ersten zwölf Prognosemonate */
    private function twelve(array $f): array
    {
        self::assertTrue((bool)($f['valid'] ?? false), 'Prognose gültig');
        $rows = array_slice($f['forecast'], 0, 12);
        $sum = fn(string $k) => array_sum(array_map(fn($r) => (float)($r[$k] ?? 0), $rows));
        // wie Home Assistant und Jahresbericht: die Summe der Monatszeilen, auf den Cent
        return ['kwh' => $sum('kwh'), 'cents' => (int)round($sum('cost_estimated') * 100), 'fixed_cents' => (int)round($sum('fixed_eur') * 100),
                'advance' => $sum('advance_estimated')];
    }

    private function assertThreshold(array $group, array $ht, array $nt, string $label): void
    {
        self::assertEqualsWithDelta($ht['kwh'] + $nt['kwh'], $group['kwh'], 0.1, "$label: Energie der Gruppe = HT + NT");
        self::assertLessThanOrEqual(1, abs($group['cents'] - $ht['cents'] - $nt['cents'] - $group['fixed_cents']),
            sprintf('%s: Gruppe − Zählwerke = Grundpreis (%.2f − %.2f − %.2f, Grundpreis %.2f)', $label, $group['cents'] / 100,
                $ht['cents'] / 100, $nt['cents'] / 100, $group['fixed_cents'] / 100));
    }

    /**
     * Zwei Zählwerke eines Doppeltarifzählers, Ablesung am Monatsersten 2023-01-01 bis 2026-01-01, Tagesrate je
     * Kalenderjahr; Gruppenvertrag ab 2023: HT 30 ct, NT 20 ct, Grundpreis 10 €, Abschlag 60 € im Monat.
     *
     * @param array<int,array{0:float,1:float}> $rates Jahr → [HT, NT] in kWh je Tag
     */
    private function household(array $rates, bool $ntEmptyDecember = false): string
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $name, string $obis) => ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => '2023-01-01',
            'active' => true, 'notes' => '', 'obis' => $obis, 'meter_group_id' => 'g_strom_dt', 'devices' => $dev($id)];
        $this->store->write('strom/meters.json', [$meter('m_strom_ht', 'HT', '1.8.1'), $meter('m_strom_nt', 'NT', '1.8.2')]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_strom_dt', 'name' => 'Doppeltarif', 'created_at' => '2023-01-01']]);
        $rows = [];
        foreach (['m_strom_ht' => 0, 'm_strom_nt' => 1] as $id => $k) {
            $c = 0.0; $i = 0;
            $d = new \DateTimeImmutable('2023-01-01');
            while (true) {
                $rows[] = ['id' => sprintf('r_%s_%03d', $k, $i++), 'meter_id' => $id, 'date' => $d->format('Y-m-d'), 'counter' => round($c, 3),
                    'device_id' => "d_$id", 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                if ($d->format('Y-m-d') === '2026-01-01') break;
                // ein Monat ohne Bezug am Ende (Sommer mit PV, Speicher): NT im Dezember 2025 null
                $empty = $ntEmptyDecember && $k === 1 && $d->format('Y-m') === '2025-12';
                $c += $empty ? 0.0 : $rates[(int)$d->format('Y')][$k] * (int)$d->format('t');
                $d = $d->modify('first day of next month');
            }
        }
        $this->store->write('strom/readings.json', $rows);
        $this->store->write('strom/contracts.json', [[
            'id' => 'c_strom_dt', 'meter_id' => null, 'meter_group_id' => 'g_strom_dt', 'provider' => 'Musterwerk', 'tariff_name' => 'Duo',
            'start' => '2023-01-01', 'end' => null, 'notes' => '',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => ['m_strom_ht' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]],
                                          'm_strom_nt' => [['from' => '2023-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 60.0]],
            'bonuses' => [], 'special_payments' => [], 'is_shadow' => false,
        ]]);
        return 'g_strom_dt';
    }

    /**
     * Von Hand: HT 4 kWh, NT 2 kWh am Tag, Prognose 2026 mit 365 Tagen → HT 1.460 kWh × 30 ct = 438,00 €,
     * NT 730 kWh × 20 ct = 146,00 €. Mischpreis aus den letzten zwölf Monaten (1.460 : 730) = 26,6667 ct;
     * Gruppe 2.190 kWh → 584,00 € Arbeit + 120,00 € Grundpreis = 704,00 €, Abschläge 720,00 €.
     */
    public function testHandCalculatedDoubleTariffMeetsTheThreshold(): void
    {
        $gid = $this->household([2023 => [4.0, 2.0], 2024 => [4.0, 2.0], 2025 => [4.0, 2.0]]);
        $fc = $this->forecasts();
        $this->consumption->atDate('2026-01-15', function () use ($fc, $gid) {
            $ht = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_ht')));
            $nt = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_nt')));
            $group = $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', $gid)));
            self::assertEqualsWithDelta(1460.0, $ht['kwh'], 0.05);
            self::assertEqualsWithDelta(730.0, $nt['kwh'], 0.05);
            self::assertSame([43800, 14600], [$ht['cents'], $nt['cents']], 'Zählwerke: eigener Arbeitspreis, ohne Grundpreis');
            self::assertSame([0.0, 0.0], [$ht['advance'], $nt['advance']], 'Zählwerke ohne Abschlag');
            self::assertEqualsWithDelta(2190.0, $group['kwh'], 0.05);
            self::assertSame(12000, $group['fixed_cents'], 'Grundpreis 12 × 10 €');
            self::assertSame(70400, $group['cents'], 'Gruppe 704,00 €');
            self::assertEqualsWithDelta(720.0, $group['advance'], 0.005, 'Abschläge 12 × 60 €');
            $this->assertThreshold($group, $ht, $nt, 'Kleinfall');
        });
    }

    /**
     * HT und NT laufen über die Jahre gegeneinander (HT 1, 2, 6 und NT 6, 2, 1 kWh am Tag). Strom nimmt ab drei
     * Werten je Kalendermonat den Median: HT 2 und NT 2 kWh am Tag, 2026 also je 730 kWh. Die Gruppe nahm bis
     * v3.12 den Median ihrer Summe (7 kWh am Tag, 2.555 kWh) — 1.095 kWh mehr als ihre Zählwerke.
     */
    public function testGroupEnergyIsTheSumOfItsRegistersWhenTheyDrift(): void
    {
        $gid = $this->household([2023 => [1.0, 6.0], 2024 => [2.0, 2.0], 2025 => [6.0, 1.0]]);
        $fc = $this->forecasts();
        $this->consumption->atDate('2026-01-15', function () use ($fc, $gid) {
            $ht = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_ht')));
            $nt = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_nt')));
            $group = $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', $gid)));
            self::assertEqualsWithDelta(730.0, $ht['kwh'], 0.05);
            self::assertEqualsWithDelta(730.0, $nt['kwh'], 0.05);
            self::assertEqualsWithDelta(1460.0, $group['kwh'], 0.1, 'Gruppe = HT + NT, nicht der Median der Summe');
            self::assertSame(12000, $group['fixed_cents']);
            // v3.13.0 (§4.5 B) — je Zählwerk zu seinem Preis: 730 × 30 ct + 730 × 20 ct + 120 € = 485,00 €. Mit dem
            // Mischpreis der letzten zwölf Monate (2025: HT 6, NT 1 kWh am Tag → 28,5714 ct) waren es 537,14 €
            self::assertSame([21900, 14600], [$ht['cents'], $nt['cents']]);
            self::assertSame(48500, $group['cents'], 'Gruppe 485,00 €');
            $this->assertThreshold($group, $ht, $nt, 'Drift');
        });
    }

    /**
     * Ein Monat ohne Bezug am Ende (NT im Dezember 2025 null, etwa im Sommer hinter PV und Speicher): Die Monatszeile
     * nannte dann den allgemeinen Arbeitspreis des Vertrags (HT, 30 ct) statt des Preises des NT-Zählwerks, und die
     * Prognose des NT-Zählwerks rechnete mit ihm. Am Prüfhaushalt (Gruppe Z2) lag das Zählwerk so bis 40 € zu hoch.
     */
    public function testRegisterWithoutConsumptionKeepsItsOwnPrice(): void
    {
        $gid = $this->household([2023 => [4.0, 2.0], 2024 => [4.0, 2.0], 2025 => [4.0, 2.0]], true);
        $fc = $this->forecasts();
        $this->consumption->atDate('2026-01-15', function () use ($fc, $gid) {
            $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', 'm_strom_nt'));
            $last = end($rows);
            self::assertSame(['2025-12', 0.0, 20.0], [$last['ym'], (float)$last['kwh'], (float)$last['working_price_ct']], 'Zeile ohne Bezug mit NT-Preis');
            $fNt = $fc->forMeter('strom', $this->meters->get('strom', 'm_strom_nt'));
            self::assertSame(20.0, (float)$fNt['forecast'][0]['working_price_ct']);
            $ht = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_ht')));
            $nt = $this->twelve($fNt);
            $group = $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', $gid)));
            $this->assertThreshold($group, $ht, $nt, 'NT ohne Bezug');
        });
    }

    /** Nachgemessen 2026-11-12: HT 460 kWh 126,04 €, NT 333 kWh 71,95 €, Gruppe 793 kWh 316,80 € mit 118,80 € Grundpreis. */
    public function testShowcaseMeetsTheThresholdAndTheReportCountsTheGroupOnce(): void
    {
        $this->importPersona('demo');
        $fc = $this->forecasts();
        [$ht, $nt, $group, $main] = $this->consumption->atDate(self::SHOWCASE_TODAY, fn() => [
            $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_wp_ht'))),
            $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_wp_nt'))),
            $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', 'g_strom_wp'))),
            $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_main'))),
        ]);
        $this->assertThreshold($group, $ht, $nt, 'Schaufenster');
        self::assertSame(11880, $group['fixed_cents'], 'Grundpreis 12 × 9,90 €');
        self::assertEqualsWithDelta(300.0, $group['advance'], 0.005, 'Abschläge 12 × 25 €');

        // Jahresbericht: der Hauptzähler rechnet nur den Rest (Abzug der Zählwerke), dazu die Gruppe einmal. Bis v3.12
        // fehlte die Wärmepumpe ganz: Ihre Zählwerke sind Subzähler und zählten in den erwarteten Kosten nicht.
        $model = $this->reportServices()['model']->build(2026, ['today' => self::SHOWCASE_TODAY, 'utilities' => ['strom']]);
        self::assertEqualsWithDelta(($main['cents'] + $group['cents']) / 100, (float)$model['money']['forecast']['cost'], 0.005,
            'Erwartete Kosten = Hauptzähler (Rest) + Gruppe');
    }

    /** „Eigenheim klassisch“: die Zählwerke der Einliegerwohnung sind keine Subzähler. Der Bericht zählt die Gruppe statt HT und NT. */
    public function testReportCountsTheGroupInsteadOfItsRootRegisters(): void
    {
        $this->importPersona('eigenheim-klassisch');
        $today = '2026-06-15';
        $fc = $this->forecasts();
        $costs = $this->consumption->atDate($today, function () use ($fc) {
            $out = [];
            foreach (['m_strom_haus', 'm_strom_gartenhaus'] as $id) $out[] = $this->twelve($fc->forMeter('strom', $this->meters->get('strom', $id)))['cents'];
            $out[] = $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', 'g_strom_einlieger')))['cents'];
            return $out;
        });
        $model = $this->reportServices()['model']->build(2026, ['today' => $today, 'utilities' => ['strom']]);
        self::assertEqualsWithDelta(array_sum(array_filter($costs, fn($c) => $c > 0)) / 100, (float)$model['money']['forecast']['cost'], 0.005);
    }

    /** Home Assistant: `group_forecast_12m` an jedem Zählwerk der Gruppe, `forecast_12m` bleibt die Prognose des Zählwerks. */
    public function testSummaryCarriesTheGroupForecastAtItsRegisters(): void
    {
        $this->importPersona('demo');
        $summary = (new \Energietracker\Services\SummaryService($this->settings, $this->meters, $this->readings, $this->consumption, $this->forecasts(),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), new \Energietracker\Services\PvSummaryService($this->consumption),
            new \Energietracker\Services\StromSaldoService($this->consumption), $this->agenda(),
            new \Energietracker\Services\InstanceService($this->store)))->build('strom', null, self::SHOWCASE_TODAY);
        $rows = array_column($summary['meters'], null, 'meter_id');
        $fc = $this->forecasts();
        [$ht, $nt, $group] = $this->consumption->atDate(self::SHOWCASE_TODAY, fn() => [
            $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_wp_ht'))),
            $this->twelve($fc->forMeter('strom', $this->meters->get('strom', 'm_strom_wp_nt'))),
            $this->twelve($fc->forMeter('strom', $this->meters->groupTarget('strom', 'g_strom_wp'))),
        ]);
        foreach (['m_strom_wp_ht' => $ht, 'm_strom_wp_nt' => $nt] as $id => $own) {
            $g = $rows[$id]['group_forecast_12m'];
            self::assertSame('g_strom_wp', $g['group_id'], $id);
            self::assertEqualsWithDelta(round($group['kwh'], 1), $g['value'], 0.05, "$id: Menge der Gruppe");
            self::assertEqualsWithDelta($group['cents'] / 100, $g['cost'], 0.005, "$id: Kosten der Gruppe");
            self::assertEqualsWithDelta($own['cents'] / 100, $rows[$id]['forecast_12m']['cost'], 0.005, "$id: forecast_12m unverändert");
        }
        self::assertNull($rows['m_strom_main']['group_forecast_12m']);
    }

    private function agenda(): \Energietracker\Services\AgendaService
    {
        $reminders = new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        return new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption, $reminders, $recs, $this->i18n);
    }

    /** Werkstatt: Die Gruppe der Wärmepumpe (HT/NT) bekommt die Hebel der Wärmepumpe, nicht die des Haushalts. */
    public function testWorkshopGivesTheGroupTheRoleOfItsRegisters(): void
    {
        $this->importPersona('demo');
        $fc = $this->forecasts();
        $switch = new TariffSwitchService($fc, $this->contracts, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $svc = new ScenarioService($fc, $this->consumption, $this->contracts, $this->meters, $this->settings, $switch,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills));
        $r = $svc->compute(['utility' => 'strom', 'meter_id' => 'g_strom_wp', 'jaz_from' => 3.0, 'jaz_to' => 3.5], self::SHOWCASE_TODAY);
        self::assertTrue((bool)$r['valid']);
        self::assertSame('power_heat_pump', $r['profile']['group'] ?? null);
        self::assertNotContains('jaz_unsupported', array_column($r['warnings'] ?? [], 'code'));
        self::assertLessThan($r['totals']['base']['qty'], $r['totals']['scenario']['qty'], 'bessere Arbeitszahl, weniger Strom');
        // der Hauptzähler bleibt Haushalt
        $h = $svc->compute(['utility' => 'strom', 'meter_id' => 'm_strom_main'], self::SHOWCASE_TODAY);
        self::assertSame('power_household', $h['profile']['group'] ?? null);
        // gemischte Rollen (ein Zählwerk Wärmepumpe, eines Haushalt): die Gruppe bleibt Haushalt
        $all = $this->store->read('strom/meters.json', []);
        foreach ($all as &$m) if ($m['id'] === 'm_strom_wp_nt') $m['role'] = 'household';
        unset($m);
        $this->store->write('strom/meters.json', $all);
        $mixed = $svc->compute(['utility' => 'strom', 'meter_id' => 'g_strom_wp', 'jaz_from' => 3.0, 'jaz_to' => 3.5], self::SHOWCASE_TODAY);
        self::assertSame('power_household', $mixed['profile']['group'] ?? null, 'gemischte Gruppe');
        self::assertContains('jaz_unsupported', array_column($mixed['warnings'] ?? [], 'code'));
    }
}
