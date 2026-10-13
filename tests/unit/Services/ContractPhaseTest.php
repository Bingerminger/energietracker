<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ContractService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.4.0 (N1021) — Phase eines Vertrags, eine Quelle für beide Listen.
 *
 * Bis v3.3 leitete „Kosten → Verträge“ den Status aus Beginn und Ende ab,
 * „Verträge & Abschläge“ aus dem Saldo-Endpunkt: Derselbe abgelaufene,
 * weiterlaufende Vertrag hieß dort „VERGANGEN“, hier „VERLÄNGERT“. Die Phase
 * folgt jetzt {@see ContractService::resolveForDate()} — wer heute rechnet,
 * ist `current` oder `renewed`, wer abgelöst ist, `past`.
 */
#[CoversClass(ContractService::class)]
final class ContractPhaseTest extends ServiceTestCase
{
    private const TODAY = '2026-10-13';

    /** @param array<int,array<string,mixed>> $all */
    private function phases(array $all): array
    {
        $out = [];
        foreach ($all as $c) $out[$c['id']] = $this->contracts->phase($c, $all, self::TODAY);
        return $out;
    }

    public function testFiveCases(): void
    {
        $all = [
            ['id' => 'alt',     'meter_id' => 'm1', 'start' => '2023-01-01', 'end' => '2023-12-31'],
            ['id' => 'weiter',  'meter_id' => 'm2', 'start' => '2024-01-01', 'end' => '2025-12-31'],
            ['id' => 'laeuft',  'meter_id' => 'm1', 'start' => '2024-01-01', 'end' => null],
            ['id' => 'kommt',   'meter_id' => 'm1', 'start' => '2027-01-01', 'end' => null],
            ['id' => 'angebot', 'meter_id' => 'm1', 'start' => '2026-01-01', 'end' => null, 'is_shadow' => true],
        ];
        self::assertSame([
            'alt' => 'past', 'weiter' => 'renewed', 'laeuft' => 'current', 'kommt' => 'future', 'angebot' => 'shadow',
        ], $this->phases($all));
        self::assertSame(['future', 'current', 'renewed', 'past', 'shadow'], ContractService::PHASES);
    }

    /** Abgelaufen ohne Nachfolger: nur mit `auto_renews` (fehlend = ja) läuft er weiter. */
    public function testEndedContractRenewsOnlyWithoutCancellation(): void
    {
        $renews = [['id' => 'a', 'meter_id' => 'm1', 'start' => '2024-01-01', 'end' => '2025-12-31']];
        self::assertSame(['a' => 'renewed'], $this->phases($renews));
        $renews[0]['auto_renews'] = true;
        self::assertSame(['a' => 'renewed'], $this->phases($renews));
        $renews[0]['auto_renews'] = false;
        self::assertSame(['a' => 'past'], $this->phases($renews), 'gekündigt');
    }

    /** Nur der zuletzt beendete Vertrag läuft weiter; ein künftiger Nachfolger ändert daran bis zu seinem Beginn nichts. */
    public function testOnlyTheLastEndedContractRenews(): void
    {
        $all = [
            ['id' => 'erster',   'meter_id' => 'm1', 'start' => '2023-01-01', 'end' => '2024-12-31'],
            ['id' => 'zweiter',  'meter_id' => 'm1', 'start' => '2025-01-01', 'end' => '2026-06-30'],
            ['id' => 'naechster', 'meter_id' => 'm1', 'start' => '2026-11-01', 'end' => null],
        ];
        self::assertSame(['erster' => 'past', 'zweiter' => 'renewed', 'naechster' => 'future'], $this->phases($all));
    }

    /** Ein später begonnener Vertrag löst den laufenden ab (Überlappung, späterer Beginn gewinnt). */
    public function testSupersededContractIsPast(): void
    {
        $all = [
            ['id' => 'unbefristet', 'meter_id' => 'm1', 'start' => '2022-01-01', 'end' => null],
            ['id' => 'neu',         'meter_id' => 'm1', 'start' => '2026-06-01', 'end' => null],
        ];
        self::assertSame(['unbefristet' => 'past', 'neu' => 'current'], $this->phases($all));
    }

    /** Je Ziel getrennt: Der Vertrag der Wärmepumpe bleibt aktuell, auch wenn am Haushaltszähler gewechselt wurde. */
    public function testPhaseIsPerTarget(): void
    {
        $all = [
            ['id' => 'wp',       'meter_id' => 'wp',  'start' => '2022-01-01', 'end' => null],
            ['id' => 'haushalt', 'meter_id' => 'hh',  'start' => '2026-01-01', 'end' => null],
            ['id' => 'gruppe',   'meter_id' => null, 'meter_group_id' => 'g1', 'start' => '2025-01-01', 'end' => null],
        ];
        self::assertSame(['wp' => 'current', 'haushalt' => 'current', 'gruppe' => 'current'], $this->phases($all));
    }

    /** Ein Angebot rechnet nirgends mit — es löst auch keinen Vertrag ab. */
    public function testShadowNeverSupersedes(): void
    {
        $all = [
            ['id' => 'laeuft',  'meter_id' => 'm1', 'start' => '2024-01-01', 'end' => null],
            ['id' => 'angebot', 'meter_id' => 'm1', 'start' => '2026-02-01', 'end' => null, 'is_shadow' => true],
        ];
        self::assertSame(['laeuft' => 'current', 'angebot' => 'shadow'], $this->phases($all));
    }

    /** Werte am Stichtag: heute, beim beendeten Vertrag sein letzter Tag, beim künftigen sein erster. */
    public function testValuesOnUseTheContractsOwnDay(): void
    {
        $c = [
            'id' => 'c', 'meter_id' => 'm1', 'start' => '2025-01-01', 'end' => '2025-12-31',
            'working_prices'   => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0], ['from' => '2025-07-01', 'ct_per_kwh' => 12.5]],
            'base_prices'      => [['from' => '2025-01-01', 'eur_per_month' => 9.9]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 100.0]],
            'special_payments' => [['date' => '2025-03-01', 'amount_eur' => 50, 'kind' => 'nachzahlung_mit',
                                    'new_advance_eur' => 120.0, 'advance_from' => '2025-04-01']],
        ];
        self::assertSame(['date' => '2025-12-31', 'working_price_ct' => 12.5, 'base_price_eur' => 9.9, 'advance_eur' => 120.0],
            $this->contracts->valuesOn($c, self::TODAY), 'beendet: letzter Tag, Abschlag nach der Sonderzahlung');
        self::assertSame('2025-01-01', $this->contracts->valuesOn($c, '2024-06-01')['date'], 'künftig: erster Tag');
        self::assertSame(10.0, $this->contracts->valuesOn($c, '2025-03-15')['working_price_ct'], 'laufend: heute');

        $water = ['id' => 'w', 'meter_id' => 'w1', 'start' => '2024-01-01', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 210.0]],
                              'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 6.5]]],
            'advance_payments' => []];
        self::assertSame(['date' => self::TODAY, 'working_price_ct' => 210.0, 'base_price_eur' => 6.5, 'advance_eur' => null],
            $this->contracts->valuesOn($water, self::TODAY));
    }

    /** Die Antwort trägt beides additiv; gespeichert wird es nicht. */
    public function testWithPhaseIsAdditiveAndNotStored(): void
    {
        $meterId = $this->meters->list('gas')[0]['id'];
        $c = $this->contracts->create('gas', ['meter_id' => $meterId, 'provider' => 'Versorger', 'start' => '2020-01-01',
            'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 9]]]);
        $all = $this->contracts->list('gas');
        $out = $this->contracts->withPhase($all, $all);
        self::assertSame('current', $out[0]['phase']);
        self::assertSame(9.0, $out[0]['values_on']['working_price_ct']);
        self::assertSame($c['provider'], $out[0]['provider']);

        $this->contracts->update('gas', $c['id'], $out[0]);
        $stored = $this->contracts->get('gas', $c['id']);
        self::assertArrayNotHasKey('phase', $stored);
        self::assertArrayNotHasKey('values_on', $stored);
    }
}
