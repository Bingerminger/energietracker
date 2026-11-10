<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Sonderzahlungen behalten ihre Kennung über das Speichern im Vertragsdialog, und eine
 * Rechnungskorrektur („Buchung ersetzen“) ersetzt danach die gebuchte Zahlung, statt eine zweite
 * daneben zu buchen. Der Dialog schickt die Kennung mit (browser-render.test.mjs); der Server
 * ordnet Zeilen ohne Kennung der unveränderten bisherigen zu.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(BillService::class)]
final class SpecialPaymentIdsTest extends ServiceTestCase
{
    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    /** @return array{0: string, 1: string} Vertrag, Rechnung (gebucht: 30 € Nachzahlung) */
    private function bookedBill(): array
    {
        $gas = $this->meters->defaultId('gas');
        $c = $this->contracts->create('gas', ['meter_id' => $gas, 'provider' => 'Werk', 'start' => '2025-01-01', 'end' => '2025-12-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]],
            'special_payments' => [['date' => '2025-06-01', 'kind' => 'abschlagszahlung', 'amount_eur' => 40, 'note' => 'freiwillig']]]);
        $b = $this->bills()->create('gas', ['meter_id' => $gas, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'issued_on' => '2026-01-20', 'invoice' => ['amount_eur' => 930, 'advances_paid_eur' => 900]]);
        $this->bills()->book('gas', $b['id']);
        return [$c['id'], $b['id']];
    }

    /** Der Dialog: alle Zeilen so, wie er sie einsammelt (mit oder ohne Kennung), unverändert speichern. */
    private function saveLikeTheDialog(string $cid, bool $withIds): void
    {
        $rows = array_map(fn($sp) => ($withIds ? ['id' => $sp['id']] : []) + ['date' => $sp['date'], 'amount_eur' => $sp['amount_eur'],
            'kind' => $sp['kind'], 'note' => $sp['note']], $this->contracts->get('gas', $cid)['special_payments']);
        $this->contracts->update('gas', $cid, ['provider' => 'Werk', 'special_payments' => $rows]);
    }

    public function testSavingKeepsTheIdsWithOrWithoutThem(): void
    {
        [$cid] = $this->bookedBill();
        $before = array_column($this->contracts->get('gas', $cid)['special_payments'], 'id');
        self::assertCount(2, $before);
        $this->saveLikeTheDialog($cid, true);
        self::assertSame($before, array_column($this->contracts->get('gas', $cid)['special_payments'], 'id'), 'mitgeschickt: bleibt');
        $this->saveLikeTheDialog($cid, false);
        self::assertSame($before, array_column($this->contracts->get('gas', $cid)['special_payments'], 'id'), 'ohne Kennung: dieselbe zurück');
    }

    public function testACorrectionAfterSavingReplacesTheBookingInsteadOfAddingOne(): void
    {
        foreach ([true, false] as $withIds) {
            $this->store->write('gas/contracts.json', []);
            $this->store->write('gas/bills.json', []);
            [$cid, $bid] = $this->bookedBill();
            $this->saveLikeTheDialog($cid, $withIds);
            // Korrektur: 950 statt 930 €, also 50 € statt 30 € Nachzahlung, „Buchung ersetzen“
            $this->bills()->update('gas', $bid, ['invoice' => ['amount_eur' => 950, 'advances_paid_eur' => 900]]);
            $this->bills()->rebook('gas', $bid);
            $sp = $this->contracts->get('gas', $cid)['special_payments'];
            $bills = array_values(array_filter($sp, fn($x) => $x['kind'] === 'nachzahlung_ohne'));
            self::assertSame([50.0], array_map('floatval', array_column($bills, 'amount_eur')), ($withIds ? 'mit' : 'ohne') . ' Kennung: eine Buchung, die neue');
            self::assertCount(2, $sp, 'die freiwillige Zahlung bleibt daneben stehen');
            self::assertSame($bills[0]['id'], $this->bills()->list('gas')[0]['special_payment_id']);
        }
    }

    /** Derselbe Fehler wie bei der Ablesung: PATCH hing einen Vertrag an einen Zähler, den es nicht gibt. */
    public function testAContractCannotBeMovedToAnUnknownMeter(): void
    {
        [$cid] = $this->bookedBill();
        foreach (['m_gas_gibtsnicht', (string)$this->meters->defaultId('strom')] as $bad) {
            try {
                $this->contracts->update('gas', $cid, ['meter_id' => $bad]);
                self::fail("Vertrag an $bad gehängt");
            } catch (\InvalidArgumentException $e) {
                self::assertSame($this->i18n->t('errors.common.meterNotFound', ['id' => $bad]), $e->getMessage());
            }
        }
        self::assertSame($this->meters->defaultId('gas'), $this->contracts->get('gas', $cid)['meter_id']);
    }

    public function testAChangedRowOrAnUnsafeIdDoesNotTakeAnId(): void
    {
        [$cid] = $this->bookedBill();
        $old = $this->contracts->get('gas', $cid)['special_payments'];
        // eine geänderte Zeile ohne Kennung ist eine neue Zahlung (kein stilles Umschreiben der Buchung)
        $rows = array_map(fn($sp) => ['date' => $sp['date'], 'amount_eur' => $sp['amount_eur'] + 1, 'kind' => $sp['kind'], 'note' => ''], $old);
        $this->contracts->update('gas', $cid, ['special_payments' => $rows]);
        self::assertSame([], array_values(array_intersect(array_column($old, 'id'), array_column($this->contracts->get('gas', $cid)['special_payments'], 'id'))));
        try {
            $this->contracts->update('gas', $cid, ['special_payments' => [['id' => 'x" onfocus="1', 'date' => '2025-06-01', 'kind' => 'abschlagszahlung', 'amount_eur' => 1]]]);
            self::fail('unsichere Kennung angenommen');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
