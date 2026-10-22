<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 (GitHub #34) — Sonderzahlungen bei Wasserverträgen.
 *
 * Wasser hat Abschläge und eine Jahresabrechnung mit Nach- oder Rückzahlung,
 * bis v3.4 aber keine Sonderzahlungen: Der Scope hing an
 * Utilities::hasAdvancePaymentContracts(), das Wasser ausschließt. Der Saldo
 * auf der Übersicht stimmte damit nach jeder Wasserabrechnung nicht, und eine
 * gebuchte Wasserrechnung kam nie im Vertrag an. Jetzt gilt
 * Utilities::hasSpecialPayments() — Gas, Strom, Wasser, Fernwärme.
 *
 * Testdaten: 8 m³ je Monat, Trinkwasser 200 ct/m³ + 5 €/Monat, Schmutzwasser
 * 250 ct/m³ → 41 € je Monat; Abschlag 40 €.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(SummaryService::class)]
#[CoversClass(BackupService::class)]
final class WaterSpecialPaymentsTest extends ServiceTestCase
{
    private const DEVICE = [
        'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
        'removed_on' => null, 'final_counter' => null, 'reason' => null,
    ];

    // ── Scope ────────────────────────────────────────────────────────────

    public function testSpecialPaymentsExistForGasElectricityWaterAndDistrictHeatingOnly(): void
    {
        $with = array_values(array_filter(Utilities::keys(), fn($k) => Utilities::hasSpecialPayments($k)));
        sort($with);
        self::assertSame(['fernwaerme', 'gas', 'strom', 'wasser'], $with);
        foreach (Utilities::keys() as $k) {
            if (Utilities::hasAdvancePaymentContracts($k)) {
                self::assertTrue(Utilities::hasSpecialPayments($k), "$k: jeder Abschlagsvertrag kennt Sonderzahlungen");
            }
            // BillService::book() verlässt sich darauf: jede Rechnung lässt sich buchen
            self::assertSame(Utilities::supportsBillCheck($k), Utilities::hasSpecialPayments($k), "$k: Rechnungsprüfung = Sonderzahlungen");
        }
        // v3.7.0 (#34-Nachzug) — Saldo nach Kalender und Abschlags-Rat hat Wasser mit Abschlägen
        // jetzt über ConsumptionService::waterWithAdvances(); Gruppenvertrag und Wechsel weiter nicht
        self::assertFalse(Utilities::hasAdvancePaymentContracts('wasser'), 'Wasser bleibt ohne Gruppenvertrag und Wechselentscheidung');
    }

    // ── Speichern und Prüfen ─────────────────────────────────────────────

    public function testWaterContractStoresSpecialPaymentsOnCreateAndUpdate(): void
    {
        $meterId = $this->setMeterDevices('wasser', [self::DEVICE]);
        $c = $this->contracts->create('wasser', $this->waterInput($meterId, '2025-01-01', null, [
            ['date' => '2025-09-01', 'kind' => 'nachzahlung_ohne', 'amount_eur' => -20.0],
            ['date' => '2025-03-15', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 50.0, 'note' => 'Abrechnung 2024',
             'new_advance_eur' => 35.0, 'advance_from' => '2025-04-01'],
        ]));
        $sp = $this->contracts->get('wasser', $c['id'])['special_payments'];
        self::assertCount(2, $sp);
        self::assertSame(['2025-03-15', '2025-09-01'], array_column($sp, 'date'), 'nach Datum sortiert');
        // assertEquals: JSON macht aus 50.0 eine 50 (SwitchFieldsBackupTest)
        self::assertEquals([50.0, 20.0], array_column($sp, 'amount_eur'), 'Beträge positiv, die Richtung steckt in der Art');
        self::assertEquals([35.0, '2025-04-01'], [$sp[0]['new_advance_eur'], $sp[0]['advance_from']]);
        self::assertSame([null, null], [$sp[1]['new_advance_eur'], $sp[1]['advance_from']]);
        self::assertStringStartsWith('sp_', $sp[0]['id']);

        $this->contracts->update('wasser', $c['id'], ['special_payments' => [
            ['date' => '2026-02-01', 'kind' => 'abschlagszahlung', 'amount_eur' => 15.0],
        ]]);
        $sp = $this->contracts->get('wasser', $c['id'])['special_payments'];
        self::assertEquals([['2026-02-01', 'abschlagszahlung', 15.0]], array_map(fn($x) => [$x['date'], $x['kind'], $x['amount_eur']], $sp),
            'update() übernimmt die Liste (bis v3.4 still verworfen)');
        $this->contracts->update('wasser', $c['id'], ['notes' => 'nur die Notiz']);
        self::assertCount(1, $this->contracts->get('wasser', $c['id'])['special_payments'], 'ohne das Feld bleibt die Liste');
    }

    public function testWaterSpecialPaymentsAreValidatedLikeGas(): void
    {
        $waterMeter = $this->setMeterDevices('wasser', [self::DEVICE]);
        $gasMeter = $this->meters->defaultId('gas');
        $cases = [
            'Betrag fehlt'       => [['date' => '2025-03-01', 'kind' => 'nachzahlung_ohne']],
            'Datum fehlt'        => [['amount_eur' => 10.0, 'kind' => 'nachzahlung_ohne']],
            'unbekannte Art'     => [['date' => '2025-03-01', 'kind' => 'refund', 'amount_eur' => 10.0]],
            'Stichtag fehlt'     => [['date' => '2025-03-01', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 10.0, 'new_advance_eur' => 30.0]],
            'Abschlag fehlt'     => [['date' => '2025-03-01', 'kind' => 'nachzahlung_mit', 'amount_eur' => 10.0, 'advance_from' => '2025-04-01']],
            'Datum ungültig'     => [['date' => '2025-02-30', 'kind' => 'nachzahlung_ohne', 'amount_eur' => 10.0]],
        ];
        foreach ($cases as $label => $sp) {
            $gas = $this->errorOf(fn() => $this->contracts->create('gas', [
                'meter_id' => $gasMeter, 'provider' => 'Werk', 'start' => '2025-01-01',
                'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]], 'special_payments' => $sp,
            ]));
            $water = $this->errorOf(fn() => $this->contracts->create('wasser', $this->waterInput($waterMeter, '2025-01-01', null, $sp)));
            self::assertNotNull($gas, "$label: Gas lehnt ab");
            self::assertSame($gas, $water, "$label: Wasser lehnt genauso ab");
        }
        // Eine leere Vorlagezeile fällt still weg — wie bei Gas
        $c = $this->contracts->create('wasser', $this->waterInput($waterMeter, '2025-01-01', null, [['date' => '', 'amount_eur' => '', 'kind' => 'abschlagszahlung']]));
        self::assertSame([], $c['special_payments']);
    }

    public function testExistingWaterContractWithoutTheFieldKeepsWorking(): void
    {
        [$meter] = $this->seedYear2025(withContract: false);
        $raw = $this->waterInput((string)$meter['id'], '2025-01-01', '2025-12-31', null);
        $raw['id'] = 'c_wasser_alt';
        unset($raw['special_payments']);
        $this->store->write('wasser/contracts.json', [$raw]);

        $st = $this->waterStatus($meter);
        self::assertSame([], $st['special_payments'], 'Liste leer, Spalte trotzdem da');
        self::assertSame(0, $st['special_payments_count']);
        self::assertEqualsWithDelta(0.0, $st['special_payment_net'], 1e-9);
        self::assertEqualsWithDelta(12 * 41.0 - 12 * 40.0, $st['current_balance'], 0.01, 'Saldo wie bisher');

        $this->contracts->update('wasser', 'c_wasser_alt', ['notes' => 'bearbeitet']);
        self::assertArrayNotHasKey('special_payments', $this->contracts->get('wasser', 'c_wasser_alt'),
            'andere Felder speichern lässt den Datensatz sonst, wie er ist (Beispieldaten)');
        $this->contracts->update('wasser', 'c_wasser_alt', ['special_payments' => [
            ['date' => '2026-01-20', 'kind' => 'nachzahlung_ohne', 'amount_eur' => 12.0],
        ]]);
        self::assertEqualsWithDelta(0.0, $this->waterStatus($meter)['current_balance'], 0.01, 'die Nachzahlung gleicht aus');
    }

    // ── Saldo, Hochrechnung, Übersicht, Prognose ─────────────────────────

    public function testRefundSurchargeAndExtraPaymentMoveTheWaterBalance(): void
    {
        [$meter, $c, $start] = $this->seedCurrentWater();
        $before = $this->waterStatus($meter);
        self::assertTrue($before['is_current']);
        self::assertSame([], $before['special_payments']);
        // v3.7.0 (#34-Nachzug) — Wasser mit Abschlägen rechnet den Saldo nach Kalender (bis v3.6 Monatsdurchschnitt)
        self::assertSame('forecast', $before['projection_method'], 'Wasser mit Abschlägen: Saldo nach Kalender (Doku 03-wasser)');

        $this->contracts->update('wasser', $c['id'], ['special_payments' => [
            ['date' => $this->month($start, 2), 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 50.0, 'note' => 'Abrechnung'],
            ['date' => $this->month($start, 3), 'kind' => 'nachzahlung_ohne', 'amount_eur' => 20.0],
            ['date' => $this->month($start, 4), 'kind' => 'abschlagszahlung', 'amount_eur' => 10.0],
        ]]);
        $after = $this->waterStatus($meter);

        // Netto aus Kundensicht: 50 erhalten − 20 − 10 gezahlt = +20
        self::assertEqualsWithDelta(20.0, $after['special_payment_net'], 1e-9);
        self::assertSame([50.0, 20.0, 10.0, 3], [$after['special_refund_total'], $after['special_surcharge_total'],
            $after['special_advance_total'], $after['special_payments_count']]);
        self::assertSame(['rueckzahlung_ohne', 'nachzahlung_ohne', 'abschlagszahlung'], array_column($after['special_payments'], 'kind'));
        self::assertEqualsWithDelta(20.0, $after['current_balance'] - $before['current_balance'], 0.001, 'Saldo heute');
        self::assertEqualsWithDelta(20.0, $after['projected_end_balance'] - $before['projected_end_balance'], 0.001, 'Hochrechnung bis Vertragsende');
    }

    public function testRefundWithImpactSetsTheWaterAdvanceFromItsDate(): void
    {
        [$meter, $c, $start] = $this->seedCurrentWater();
        $before = $this->waterStatus($meter);
        $from = $this->month($start, 6);
        $this->contracts->update('wasser', $c['id'], ['special_payments' => [
            ['date' => $this->month($start, 5), 'kind' => 'rueckzahlung_mit', 'amount_eur' => 30.0,
             'new_advance_eur' => 35.0, 'advance_from' => $from],
        ]]);

        $byYm = array_column($this->consumption->forMeter('wasser', $meter), null, 'ym');
        self::assertEqualsWithDelta(40.0, $byYm[substr($this->month($start, 5), 0, 7)]['advance_eur'], 1e-9, 'vor dem Stichtag');
        self::assertEqualsWithDelta(35.0, $byYm[substr($from, 0, 7)]['advance_eur'], 1e-9, 'ab dem Stichtag');
        self::assertEqualsWithDelta(35.0, $byYm[substr($this->month($start, 11), 0, 7)]['advance_eur'], 1e-9);

        $after = $this->waterStatus($meter);
        self::assertEqualsWithDelta(35.0, $after['current_advance_amount'], 1e-9);
        // v3.7.0 — Saldo nach Kalender: +30 Rückzahlung, +7 × 5 € weniger Abschlag ab dem
        // Stichtag bis heute (der laufende Monat zählt ganz, fällig am Monatsanfang; bis v3.6
        // nur die 6 gemessenen Monate)
        self::assertEqualsWithDelta(30.0 + 7 * 5.0, $after['current_balance'] - $before['current_balance'], 0.01);
        // Hochrechnung bis Vertragsende: jeder Monat ab dem Stichtag mit dem neuen Abschlag (18 Monate)
        self::assertEqualsWithDelta(30.0 + 18 * 5.0, $after['projected_end_balance'] - $before['projected_end_balance'], 0.05);
    }

    public function testDashboardSummaryCarriesTheWaterSpecialPayments(): void
    {
        [$meter, $c, $start] = $this->seedCurrentWater();
        $before = $this->summaryContract((string)$meter['id']);
        $this->contracts->update('wasser', $c['id'], ['special_payments' => [
            ['date' => $this->month($start, 2), 'kind' => 'nachzahlung_ohne', 'amount_eur' => 75.0],
        ]]);
        $after = $this->summaryContract((string)$meter['id']);
        self::assertEqualsWithDelta(-75.0, $after['balance'] - $before['balance'], 0.001, 'Übersicht: Saldo');
        self::assertEqualsWithDelta(-75.0, $after['projected_end_balance'] - $before['projected_end_balance'], 0.001, 'Übersicht: erwartet');
    }

    public function testForecastTakesTheNewWaterAdvanceFromItsDate(): void
    {
        [$meter, $c] = $this->seedCurrentWater();
        $from = date('Y-m-d', (int)strtotime('first day of +3 months'));
        $this->contracts->update('wasser', $c['id'], ['special_payments' => [
            ['date' => date('Y-m-d'), 'kind' => 'nachzahlung_mit', 'amount_eur' => 25.0, 'new_advance_eur' => 45.0, 'advance_from' => $from],
        ]]);
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('wasser', $this->meters->get('wasser', (string)$meter['id']));
        self::assertTrue($fc['valid']);
        $adv = array_column($fc['forecast'], 'advance_estimated', 'ym');
        $fromYm = substr($from, 0, 7);
        $prevYm = date('Y-m', (int)strtotime("$from -1 month"));
        self::assertEqualsWithDelta(40.0, $adv[$prevYm], 1e-9, 'vor dem Stichtag');
        self::assertEqualsWithDelta(45.0, $adv[$fromYm], 1e-9, 'Prognose: neuer Abschlag ab dem Stichtag');
    }

    // ── Versorgerrechnung ────────────────────────────────────────────────

    public function testWaterBillIsCheckedAndBookedAsSpecialPayment(): void
    {
        [$meter, $c] = $this->seedYear2025();
        // Rechnung: 96 m³, 492 €, 480 € Abschläge → 12 € Nachzahlung
        $b = $this->bills()->create('wasser', ['meter_id' => $meter['id'], 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'issued_on' => '2026-01-20', 'invoice' => ['volume_m3' => 96, 'amount_eur' => 492, 'advances_paid_eur' => 480]]);
        $cmp = $this->bills()->compare('wasser', $b['id']);
        self::assertEqualsWithDelta(96.0, $cmp['ours']['m3'], 0.01);
        self::assertEqualsWithDelta(492.0, $cmp['ours']['total'], 0.01);
        self::assertEqualsWithDelta(480.0, $cmp['ours']['advances'], 0.01);
        self::assertSame('ok', $cmp['verdict']);
        self::assertEqualsWithDelta(12.0, $this->waterStatus($meter)['current_balance'], 0.01, 'vor dem Buchen: 12 € offen');

        $booked = $this->bills()->book('wasser', $b['id']);
        $sp = $this->contracts->get('wasser', $c['id'])['special_payments'];
        self::assertCount(1, $sp, 'die Buchung kommt im Wasservertrag an');
        self::assertEquals([$booked['special_payment_id'], '2026-01-20', 'nachzahlung_ohne', 12.0], [$sp[0]['id'], $sp[0]['date'], $sp[0]['kind'], $sp[0]['amount_eur']]);
        self::assertEqualsWithDelta(0.0, $this->waterStatus($meter)['current_balance'], 0.01, 'nach dem Buchen ausgeglichen');
        self::assertSame($booked['special_payment_id'], $this->bills()->book('wasser', $b['id'])['special_payment_id'], 'einmal je Rechnung');
        self::assertCount(1, $this->contracts->get('wasser', $c['id'])['special_payments']);
    }

    public function testWaterBillBookedBeforeTheFixCanBeBookedNow(): void
    {
        [$meter] = $this->seedYear2025(withContract: false);
        $raw = $this->waterInput((string)$meter['id'], '2025-01-01', '2025-12-31', null);
        $raw['id'] = 'c_wasser_alt';
        unset($raw['special_payments']);
        $this->store->write('wasser/contracts.json', [$raw]);
        // so hinterließ v3.1–v3.4 eine gebuchte Wasserrechnung: ID gesetzt, im Vertrag nichts
        $this->store->write('wasser/bills.json', [[
            'id' => 'b_alt', 'meter_id' => $meter['id'], 'contract_id' => 'c_wasser_alt', 'kind' => 'annual',
            'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-01-20',
            'invoice' => ['amount_eur' => 492.0, 'advances_paid_eur' => 480.0, 'result_eur' => 12.0],
            'items' => [], 'co2' => null, 'attachment_ids' => [], 'special_payment_id' => 'sp_verloren', 'note' => '',
        ]]);

        self::assertNull($this->bills()->list('wasser')[0]['special_payment_id'], 'nicht gebucht, der Knopf erscheint wieder');
        $booked = $this->bills()->book('wasser', 'b_alt');
        self::assertNotSame('sp_verloren', $booked['special_payment_id']);
        self::assertSame([$booked['special_payment_id']], array_column($this->contracts->get('wasser', 'c_wasser_alt')['special_payments'], 'id'));
        self::assertSame($booked['special_payment_id'], $this->bills()->list('wasser')[0]['special_payment_id']);
    }

    public function testBookedGasBillStaysBookedAfterTheContractIsSavedAgain(): void
    {
        // Der Vertragsdialog schickt die Sonderzahlungen ohne ID; sie bekommen
        // beim Speichern neue. Die Rechnung darf dadurch nicht „ungebucht“ wirken.
        $gasMeter = $this->meters->defaultId('gas');
        $c = $this->contracts->create('gas', ['meter_id' => $gasMeter, 'provider' => 'Werk', 'start' => '2025-01-01', 'end' => '2025-12-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]]]);
        $b = $this->bills()->create('gas', ['meter_id' => $gasMeter, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'invoice' => ['amount_eur' => 900, 'result_eur' => 30]]);
        $id = $this->bills()->book('gas', $b['id'])['special_payment_id'];
        $sp = $this->contracts->get('gas', $c['id'])['special_payments'];
        $this->contracts->update('gas', $c['id'], ['special_payments' => array_map(fn($x) => array_diff_key($x, ['id' => true]), $sp)]);
        self::assertNotSame($id, $this->contracts->get('gas', $c['id'])['special_payments'][0]['id'], 'Vorbedingung: neue ID');
        self::assertSame($id, $this->bills()->list('gas')[0]['special_payment_id'], 'bleibt gebucht');
    }

    // ── Backup ──────────────────────────────────────────────────────────

    public function testWaterSpecialPaymentsSurviveBackupAndRestore(): void
    {
        $meterId = $this->setMeterDevices('wasser', [self::DEVICE]);
        $c = $this->contracts->create('wasser', $this->waterInput($meterId, '2025-01-01', null, [
            ['date' => '2025-03-15', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 50.0, 'note' => 'Abrechnung',
             'new_advance_eur' => 35.0, 'advance_from' => '2025-04-01'],
        ]));
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export();
        $this->store->write('wasser/contracts.json', []);
        $backups->import($dump);
        $sp = $this->contracts->get('wasser', $c['id'])['special_payments'] ?? null;
        self::assertIsArray($sp, 'Sonderzahlungen verloren');
        self::assertSame(['2025-03-15', 'rueckzahlung_mit', 'Abrechnung', '2025-04-01'],
            [$sp[0]['date'], $sp[0]['kind'], $sp[0]['note'], $sp[0]['advance_from']]);
        self::assertEquals([50.0, 35.0], [$sp[0]['amount_eur'], $sp[0]['new_advance_eur']]);
    }

    // ── Ohne Sonderzahlungen: Einspeisung und Heizöl ────────────────────

    public function testFeedInAndHeatingOilStayWithoutSpecialPayments(): void
    {
        $sp = [['date' => '2025-06-01', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 30.0]];

        $pv = $this->meters->create('pv_einspeisung', ['name' => 'Einspeisung', 'installed_on' => '2025-01-01', 'initial_counter' => 0.0]);
        $pc = $this->contracts->create('pv_einspeisung', ['meter_id' => $pv['id'], 'provider' => 'Netz', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 8.0]], 'special_payments' => $sp]);
        self::assertSame([], $pc['special_payments']);
        $this->contracts->update('pv_einspeisung', $pc['id'], ['special_payments' => $sp]);
        self::assertSame([], $this->contracts->get('pv_einspeisung', $pc['id'])['special_payments'] ?? [], 'Einspeisung: update() verwirft');
        self::assertArrayNotHasKey('special_payments', $this->consumption->contractStatus('pv_einspeisung', $pv)['contracts'][0]);

        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000, 'initial_stock' => 1000, 'installed_on' => '2025-01-01']);
        $hc = $this->contracts->create('heizoel', ['meter_id' => $tank['id'], 'provider' => 'Händler', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]], 'special_payments' => $sp]);
        self::assertSame([], $hc['special_payments']);
        $this->contracts->update('heizoel', $hc['id'], ['special_payments' => $sp]);
        self::assertSame([], $this->contracts->get('heizoel', $hc['id'])['special_payments'], 'Heizöl: update() verwirft (bis v3.4 gespeichert)');

        // Ein Feld, das dort nie sein sollte (Backup, Hand), zählt im Saldo nicht
        $raw = $this->store->read('heizoel/contracts.json', []);
        $raw[0]['special_payments'] = [['id' => 'sp_x', 'date' => '2025-06-01', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 30.0,
            'note' => '', 'new_advance_eur' => null, 'advance_from' => null]];
        $this->store->write('heizoel/contracts.json', $raw);
        $st = $this->consumption->contractStatus('heizoel', $this->meters->get('heizoel', (string)$tank['id']))['contracts'][0];
        self::assertArrayNotHasKey('special_payments', $st);
        self::assertSame([0.0, 0], [$st['special_payment_net'], $st['special_payments_count']]);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private function waterInput(string $meterId, string $start, ?string $end, ?array $special): array
    {
        $in = [
            'meter_id' => $meterId, 'provider' => 'Stadtwerk', 'tariff_name' => 'Wasser', 'start' => $start, 'end' => $end,
            // v3.7.0 — ein beendeter Vertrag läuft hier nicht weiter: Seit dem Saldo nach Kalender
            // zählte ein weiterlaufender auch die geschätzten Monate bis heute
            'auto_renews' => $end === null ? null : false,
            'trinkwasser' => [
                'working_prices' => [['from' => $start, 'ct_per_m3' => 200.0]],
                'base_prices'    => [['from' => $start, 'eur_per_month' => 5.0]],
            ],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => $start, 'ct_per_m3' => 250.0]]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 40.0]],
        ];
        if ($special !== null) $in['special_payments'] = $special;
        return $in;
    }

    /** Laufender Vertrag: Beginn vor zwölf Monaten, Ende in gut einem Jahr, Ablesung je Monatserstem. */
    private function seedCurrentWater(): array
    {
        $start = date('Y-m-d', (int)strtotime('first day of -12 months'));
        $meterId = $this->setMeterDevices('wasser', [['installed_on' => $start] + self::DEVICE]);
        $rows = [];
        for ($i = 0; $i <= 12; $i++) $rows[] = ['date' => $this->month($start, $i), 'counter' => 8.0 * $i, 'device_id' => 'd1'];
        $this->setReadings('wasser', $meterId, $rows);
        $c = $this->contracts->create('wasser', $this->waterInput($meterId, $start, date('Y-m-d', (int)strtotime("$start +2 years -1 day")), null));
        return [$this->meters->get('wasser', $meterId), $c, $start];
    }

    /** Abgeschlossenes Jahr 2025 mit Ablesung je Monatserstem. */
    private function seedYear2025(bool $withContract = true): array
    {
        $meterId = $this->setMeterDevices('wasser', [self::DEVICE]);
        $rows = [];
        for ($i = 0; $i <= 12; $i++) $rows[] = ['date' => $this->month('2025-01-01', $i), 'counter' => 8.0 * $i, 'device_id' => 'd1'];
        $this->setReadings('wasser', $meterId, $rows);
        $c = $withContract ? $this->contracts->create('wasser', $this->waterInput($meterId, '2025-01-01', '2025-12-31', null)) : null;
        return [$this->meters->get('wasser', $meterId), $c];
    }

    private function month(string $first, int $n): string
    {
        return date('Y-m-d', (int)strtotime("$first +$n months"));
    }

    private function waterStatus(array $meter): array
    {
        return $this->consumption->contractStatus('wasser', $meter)['contracts'][0];
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function errorOf(callable $fn): ?string
    {
        try {
            $fn();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function summaryContract(string $meterId): array
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        $summary = new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $agenda,
            new InstanceService($this->store));
        foreach ($summary->build('wasser', null, date('Y-m-d'))['meters'] as $m) {
            if ($m['meter_id'] === $meterId) {
                self::assertIsArray($m['contract'], 'laufender Wasservertrag in der Übersicht');
                return $m['contract'];
            }
        }
        self::fail('Wasserzähler fehlt in der Übersicht');
    }
}
