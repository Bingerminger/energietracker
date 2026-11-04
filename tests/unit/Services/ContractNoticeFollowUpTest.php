<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (Nachprüfung NP3, NP4, Grundversorgung) — Kündigung und Wechsel, wie Prüfbericht Nr. 26 und 30 sie
 * belegen. Erfundene Verträge („Wärme Musterstadt“), Daten von Hand gezählt (Monate am Monatsende geklemmt):
 *
 *   NP3 Fernwärme, Ende 31.12.2026, verlängert sich, keine Frist eingetragen (§ 32 Abs. 1 AVBFernwärmeV):
 *       Kündigungstag 31.12.2026 − 9 Monate = 31.03.2026; ohne Kündigung bis 31.12.2026 + 60 Monate = 31.12.2031,
 *       deren Kündigungstag 31.03.2031.
 *       heute 15.01.2026: kündigen bis 31.03.2026 (75 Tage), Wechsel 01.01.2027
 *       heute 04.11.2026 (der Fall der Nachprüfung): Tag verpasst, Wechsel erst 01.01.2032, keine Erinnerung
 *         „Vertrag endet in 58 Tagen … jetzt wechseln“, kein Termin „Vertragsende“
 *       eingetragene Frist (3 Monate) geht vor: 30.09.2026; ohne Verlängerung (`auto_renews: false`) und in
 *       Österreich kein Kündigungstag aus § 32
 *   NP4 Fernwärme, Ende 30.06.2026, verlängert, Folgevertrag ab 01.04.2027: verlängert bis 31.03.2027, Wechsel
 *       zum 01.04.2027, kein „läuft bis 30.06.2031 / Kündigung bis 30.09.2030“
 *   Grundversorgung (Gas, Strom), Ende 31.12.2025, verlängert, heute 03.11.2026: zwei Wochen Frist (§ 20 GVV),
 *       heute gekündigt endet er am 17.11.2026, Wechsel 18.11.2026; im Sondervertrag und ohne Angabe wie bisher
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(AgendaService::class)]
#[CoversClass(TariffSwitchService::class)]
final class ContractNoticeFollowUpTest extends ServiceTestCase
{
    private function heat(array $extra = []): array
    {
        return $extra + ['id' => 'c-heat', 'provider' => 'Wärme Musterstadt', 'start' => '2017-01-01', 'end' => '2026-12-31'];
    }

    public function testDistrictHeatingWithoutNoticeUsesNineMonthsBeforeTheEnd(): void
    {
        $t = $this->contracts->switchTiming($this->heat(), '2026-01-15', false, 'fernwaerme', 'DE');
        self::assertSame(['2027-01-01', '2026-03-31', 75, 'fixed_end'], [$t['switch_date'], $t['cancel_by'], $t['days_to_cancel'], $t['basis']]);
        self::assertSame(['until' => '2031-12-31', 'cancel_by' => '2031-03-31'], $t['renewal_term']);

        // der Fall der Nachprüfung: 58 Tage vor dem Ende, der Kündigungstag ist vorbei → kein Wechsel zum Laufzeitende
        $m = $this->contracts->switchTiming($this->heat(), '2026-11-04', false, 'fernwaerme', 'DE');
        self::assertSame(['2032-01-01', '2026-03-31', -218, 'fixed_end'], [$m['switch_date'], $m['cancel_by'], $m['days_to_cancel'], $m['basis']]);
        self::assertSame(['until' => '2031-12-31', 'cancel_by' => '2031-03-31'], $m['renewal_term']);
    }

    public function testAnEnteredNoticeOrNoRenewalOrAnotherCountryKeepsTheOldRule(): void
    {
        $own = $this->contracts->switchTiming($this->heat(['notice_period_months' => 3]), '2026-11-04', false, 'fernwaerme', 'DE');
        self::assertSame(['2027-01-01', '2026-09-30', false], [$own['switch_date'], $own['cancel_by'], isset($own['renewal_term'])]);
        $off = $this->contracts->switchTiming($this->heat(['auto_renews' => false]), '2026-11-04', false, 'fernwaerme', 'DE');
        self::assertSame(['2027-01-01', null, false], [$off['switch_date'], $off['cancel_by'], isset($off['renewal_term'])]);
        $at = $this->contracts->switchTiming($this->heat(), '2026-11-04', false, 'fernwaerme', 'AT');
        self::assertSame(['2027-01-01', null, false], [$at['switch_date'], $at['cancel_by'], isset($at['renewal_term'])]);
        $power = $this->contracts->switchTiming($this->heat(), '2026-11-04', false, 'strom', 'DE');
        self::assertSame(['2027-01-01', null, false], [$power['switch_date'], $power['cancel_by'], isset($power['renewal_term'])]);
    }

    /** Vertragsstatus, Erinnerung und Agenda am 04.11.2026: verpasst, verlängert sich, kein „Vertragsende“. */
    public function testTheMissedHeatDeadlineRenewsInsteadOfASwitchAtTheEnd(): void
    {
        $this->settings->set(['active_utilities' => ['strom', 'fernwaerme']]);   // die Agenda sieht nur aktive Arten
        $meterId = $this->setMeterDevices('fernwaerme', [['id' => 'd_h', 'serial' => null, 'installed_on' => '2017-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('fernwaerme', $meterId, [['date' => '2026-01-01', 'device_id' => 'd_h', 'counter' => 1000.0],
            ['date' => '2026-11-01', 'device_id' => 'd_h', 'counter' => 9000.0]]);
        $c = $this->contracts->create('fernwaerme', ['meter_id' => $meterId, 'provider' => 'Wärme Musterstadt', 'start' => '2017-01-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2017-01-01', 'ct_per_kwh' => 12.0]]]);
        $row = array_column($this->consumption->contractStatus('fernwaerme', $this->meters->get('fernwaerme', $meterId), '2026-11-04')['contracts'], null, 'contract_id')[$c['id']];
        self::assertSame([true, false, 0, 'cancel_by', '2026-03-31', '2032-01-01'],
            [$row['cancel_missed'], $row['should_remind'], $row['remind_stage'], $row['remind_basis'], $row['cancel_by'], $row['switch_date']]);
        self::assertSame(['until' => '2031-12-31', 'cancel_by' => '2031-03-31'], $row['renewal_term']);

        $recs = (new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->atDate('2026-11-04');
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $kinds = array_column(array_filter($agenda->events(90, '2026-11-04'), fn($e) => ($e['ref']['contract_id'] ?? null) === $c['id']), 'kind');
        self::assertNotContains('term_end', $kinds, 'bis dahin „Vertragsende 31.12.2026“, obwohl er sich verlängert');
        self::assertNotContains('cancel_by', $kinds);
        // Gegenstück: derselbe Vertrag mit eingetragener Frist (3 Monate, Stichtag 30.09. verpasst) zeigt das Vertragsende weiter
        $this->contracts->update('fernwaerme', (string)$c['id'], ['notice_period_months' => 3]);
        $kinds = array_column(array_filter($agenda->events(90, '2026-11-04'), fn($e) => ($e['ref']['contract_id'] ?? null) === $c['id']), 'kind');
        self::assertContains('term_end', $kinds, 'die Agenda sieht die Fernwärme (sonst prüfte der Test nichts)');
        $this->contracts->update('fernwaerme', (string)$c['id'], ['notice_period_months' => null]);

        // vor dem Kündigungstag: Erinnerung und Termin wie mit eingetragener Frist
        $early = array_column($this->consumption->contractStatus('fernwaerme', $this->meters->get('fernwaerme', $meterId), '2026-02-15')['contracts'], null, 'contract_id')[$c['id']];
        self::assertSame([false, true, 'cancel_by', 44], [$early['cancel_missed'], $early['should_remind'], $early['remind_basis'], $early['days_to_cancel']]);
    }

    /** NP4 — verlängerte Fernwärme mit eingetragenem Folgevertrag: dieser gilt. */
    public function testARenewedContractWithASuccessorEndsWhenTheSuccessorStarts(): void
    {
        $c = $this->heat(['end' => '2026-06-30']);
        $renewed = $this->contracts->switchTiming($c, '2026-11-04', true, 'fernwaerme', 'DE');
        self::assertSame(['2031-07-01', '2030-09-30', 'renewal_term'], [$renewed['switch_date'], $renewed['cancel_by'], $renewed['basis']]);
        $succ = $this->contracts->switchTiming($c, '2026-11-04', true, 'fernwaerme', 'DE', '2027-03-31');
        self::assertSame(['2027-04-01', null, null, 'renewed'], [$succ['switch_date'], $succ['cancel_by'], $succ['days_to_cancel'], $succ['basis']]);

        $meterId = $this->setMeterDevices('fernwaerme', [['id' => 'd_h', 'serial' => null, 'installed_on' => '2017-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $old = $this->contracts->create('fernwaerme', ['meter_id' => $meterId, 'provider' => 'Wärme Musterstadt', 'start' => '2017-01-01', 'end' => '2026-06-30',
            'working_prices' => [['from' => '2017-01-01', 'ct_per_kwh' => 12.0]]]);
        $this->contracts->create('fernwaerme', ['meter_id' => $meterId, 'provider' => 'Wärme Musterstadt', 'tariff_name' => 'Neu', 'start' => '2027-04-01',
            'working_prices' => [['from' => '2027-04-01', 'ct_per_kwh' => 13.0]]]);
        $row = array_column($this->consumption->contractStatus('fernwaerme', $this->meters->get('fernwaerme', $meterId), '2026-11-04')['contracts'], null, 'contract_id')[$old['id']];
        self::assertSame([true, 'renewed', '2027-04-01', null, null], [$row['renewed'], $row['notice_basis'], $row['switch_date'], $row['cancel_by'], $row['renewal_term']]);
        self::assertSame('2027-04-01', $row['successor']['start']);
    }

    /** Grundversorgung nach dem Ende: zwei Wochen Frist (Prüfbericht 26), nicht „steht im Vertrag“. */
    public function testRenewedBasicSupplyCanBeCancelledWithTwoWeeksNotice(): void
    {
        foreach (['gas', 'strom'] as $u) {
            $c = ['id' => "c-$u", 'start' => '2024-01-01', 'end' => '2025-12-31', 'supply_kind' => 'basic', 'notice_period_months' => 1];
            $t = $this->contracts->switchTiming($c, '2026-11-03', true, $u, 'DE');
            self::assertSame(['2026-11-18', null, null, 'basic_supply'], [$t['switch_date'], $t['cancel_by'], $t['days_to_cancel'], $t['basis']], $u);
            foreach (['special', null] as $kind) {
                $o = $this->contracts->switchTiming(['supply_kind' => $kind] + $c, '2026-11-03', true, $u, 'DE');
                self::assertSame([null, 'renewed'], [$o['switch_date'], $o['basis']], "$u/" . ($kind ?? 'unbekannt'));
            }
            self::assertSame('renewed', $this->contracts->switchTiming($c, '2026-11-03', true, $u, 'AT')['basis'], "$u in AT");
        }
        // vor dem Ende bleibt es bei der eingetragenen Frist (der Auftrag betrifft nur die Zeit danach)
        $before = $this->contracts->switchTiming(['start' => '2026-01-01', 'end' => '2026-12-31', 'supply_kind' => 'basic', 'notice_period_months' => 1], '2026-11-03', false, 'gas', 'DE');
        self::assertSame(['2027-01-01', '2026-11-30', 'fixed_end'], [$before['switch_date'], $before['cancel_by'], $before['basis']]);
    }

    /** „Wechsel prüfen“ rechnet mit dem Land der Einstellungen (Grundversorgung, Fernwärme). */
    public function testTheTariffSwitchUsesTheCountry(): void
    {
        $meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($i = 0, $t = strtotime('2024-01-01'); date('Y-m', $t) <= '2025-07'; $t = strtotime('+1 month', $t), $i++) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => 100.0 * $i, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Strom Musterstadt', 'tariff_name' => 'Grundversorgung', 'start' => '2024-01-01',
            'end' => '2024-12-31', 'supply_kind' => 'basic', 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]);
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $with = (new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n, null, $this->settings))->analyze('strom', $meterId, ['today' => '2025-07-10']);
        // 10.07. + 14 Tage = 24.07. (letzter Tag), Wechsel 25.07.2025
        self::assertSame(['basic_supply', '2025-07-25', 'contract'], [$with['current']['basis'], $with['switch_date'], $with['switch_date_source']]);
        $without = (new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n))->analyze('strom', $meterId, ['today' => '2025-07-10']);
        self::assertSame(['renewed', '2025-08-01', 'default'], [$without['current']['basis'], $without['switch_date'], $without['switch_date_source']]);
    }
}
