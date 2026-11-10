<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReadingService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Eingaben an der Ablesung (und mit derselben Prüfung an der Lieferung):
 * Datumsbereich, Preis mit Dezimalkomma, Typen, „geschätzt“, Umhängen an einen anderen
 * Zähler. Feste Daten, nie heute.
 */
#[CoversClass(ReadingService::class)]
final class ReadingInputTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    /** @param callable(): mixed $call */
    private function code(callable $call): string
    {
        try {
            $call();
        } catch (LocalizedException $e) {
            return $e->key;
        } catch (\InvalidArgumentException $e) {
            return 'msg:' . $e->getMessage();
        }
        return 'ok';
    }

    // ── Jahr 0001 ──────────────────────────────────────────────────────

    public function testAReadingInTheYear0001IsRejectedAndTheDeviceStaysWhereItWas(): void
    {
        foreach (['0001-01-01', '0202-05-01', '1899-12-31', '2101-01-01'] as $d) {
            self::assertSame('errors.common.dateOutOfRange', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => $d, 'counter' => 5])), $d);
        }
        // nichts gespeichert, und der Einbau des ersten Geräts wurde nicht vorverlegt
        self::assertSame([], $this->readings->list('strom', $this->meterId));
        self::assertSame('2024-01-01', $this->meters->get('strom', $this->meterId)['devices'][0]['installed_on']);
        // auch direkt (Import, andere Wege): nie vor 1900 vorverlegen
        self::assertNull($this->meters->backdateFirstDevice('strom', $this->meterId, '0001-01-01'));
        self::assertSame('2024-01-01', $this->meters->get('strom', $this->meterId)['devices'][0]['installed_on']);
        // die Grenzen selbst gehen: 1900 verlegt den Einbau vor (wie jede ältere Ablesung), 2100 ist vorgemerkt
        self::assertSame('ok', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '1900-01-01', 'counter' => 1])));
        self::assertSame('1900-01-01', $this->meters->get('strom', $this->meterId)['devices'][0]['installed_on']);
        self::assertTrue($this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2100-12-31', 'counter' => 9])['is_future']);
    }

    public function testChangingTheDateTo0001IsRejectedToo(): void
    {
        $r = $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-01', 'counter' => 10]);
        self::assertSame('errors.common.dateOutOfRange', $this->code(fn() => $this->readings->update('strom', $r['id'], ['date' => '0001-01-01'])));
        self::assertSame('2025-02-01', $this->readings->list('strom', $this->meterId)[0]['date']);
    }

    /**
     * Altbestand: ein schon gespeicherter Stand im Jahr 0001 (und ein Gerät „seit 0001“, das die
     * alte Vorverlegung hinterließ) bleibt aus der Rechnung, mit Warnung. Vorher legte die
     * Monatsrechnung Zeilen für zweitausend Jahre an.
     */
    public function testAStoredReadingIn0001StaysOutOfTheCalculationWithAWarning(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '0001-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['id' => 'r_alt', 'date' => '0001-01-01', 'counter' => 3.0, 'device_id' => 'd1'],
            ['date' => '2025-01-01', 'counter' => 100.0, 'device_id' => 'd1'],
            ['date' => '2025-03-01', 'counter' => 690.0, 'device_id' => 'd1'],
        ]);
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $meterId));
        // von Hand: 590 kWh über 59 Tage (01.01.–01.03.2025) = 10 kWh/Tag → Januar 310, Februar 280
        self::assertSame(['2025-01', '2025-02'], array_column($rows, 'ym'));
        self::assertEqualsWithDelta(310.0, $rows[0]['kwh'], 0.001);
        self::assertEqualsWithDelta(280.0, $rows[1]['kwh'], 0.001);
        $w = $this->consumption->readingWarnings('strom', $this->meters->get('strom', $meterId));
        self::assertSame([['type' => 'out_of_range', 'reading_id' => 'r_alt', 'date' => '0001-01-01', 'counter' => 3.0, 'min' => 1900, 'max' => 2100]], $w);
    }

    // ── Preis je Ablesung ──────────────────────────────────────────────

    public function testAPriceWithADecimalCommaCountsAsTheSamePrice(): void
    {
        $a = $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-01-01', 'counter' => 0, 'price_cents' => '12,5']);
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-01', 'counter' => 100]);
        self::assertSame(12.5, $a['price_cents'], '„12,5“ ct ist 12,5 ct, nicht 12');
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId));
        // von Hand: 100 kWh im Januar × 12,5 ct = 12,50 € (mit dem abgeschnittenen Preis waren es 12,00 €)
        self::assertSame('2025-01', $rows[0]['ym']);
        self::assertEqualsWithDelta(100.0, $rows[0]['kwh'], 0.001);
        self::assertEqualsWithDelta(12.50, $rows[0]['cost'], 0.001);
    }

    public function testAPriceThatIsNoNumberIsRejectedNotTruncated(): void
    {
        foreach (['abc', '1.234,5', -99, '-0,5', [1], ['a' => 1], true, 1e9] as $bad) {
            self::assertSame('errors.reading.priceInvalid',
                $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-01-01', 'counter' => 0, 'price_cents' => $bad])),
                var_export($bad, true));
        }
        self::assertSame([], $this->readings->list('strom', $this->meterId), 'nichts gespeichert');
        // leer bleibt „kein Preis“
        self::assertNull($this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-01-01', 'counter' => 0, 'price_cents' => ''])['price_cents']);
        $r = $this->readings->list('strom', $this->meterId)[0];
        self::assertSame('errors.reading.priceInvalid', $this->code(fn() => $this->readings->update('strom', $r['id'], ['price_cents' => '9,9x'])));
        self::assertSame(9.9, $this->readings->update('strom', $r['id'], ['price_cents' => '9,9'])['price_cents']);
    }

    // ── Typen ──────────────────────────────────────────────────────────

    public function testListsOrObjectsInsteadOfTextAre400NotAWarning(): void
    {
        $base = ['meter_id' => $this->meterId, 'date' => '2025-02-14', 'counter' => 1];
        foreach (['date', 'note', 'meter_id', 'client_ref', 'reported_on', 'attachment_id'] as $f) {
            foreach ([['x'], ['a' => 1]] as $v) {
                self::assertSame('errors.common.textExpected', $this->code(fn() => $this->readings->create('strom', [$f => $v] + $base)), "$f als " . json_encode($v));
            }
        }
        $r = $this->readings->create('strom', $base);
        foreach (['note', 'date', 'meter_id', 'reported_on', 'attachment_id'] as $f) {
            self::assertSame('errors.common.textExpected', $this->code(fn() => $this->readings->update('strom', $r['id'], [$f => ['a' => 1]])), "PATCH $f");
        }
        self::assertSame('', $this->readings->list('strom', $this->meterId)[0]['note'], 'die Notiz wurde nicht als Objekt gespeichert');
    }

    public function testAnUnrepresentableCounterOrALineBreakInTheDateIsRejected(): void
    {
        // 1e308 machte Verbrauch und Kosten unendlich (die Antwort blieb leer)
        self::assertStringStartsWith('msg:', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-14', 'counter' => 1e308])));
        self::assertSame('ok', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-15', 'counter' => 999999999999])));
        self::assertStringStartsWith('msg:', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => "2025-02-16\n", 'counter' => 5])));
    }

    public function testEstimatedIsAYesOrNoNotAnyText(): void
    {
        self::assertFalse($this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-01', 'counter' => 1, 'is_estimated' => 'nein'])['is_estimated']);
        self::assertTrue($this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-03-01', 'counter' => 2, 'is_estimated' => 'true'])['is_estimated']);
        self::assertSame('errors.common.boolExpected', $this->code(fn() => $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-04-01', 'counter' => 3, 'is_estimated' => 'vielleicht'])));
        $r = $this->readings->list('strom', $this->meterId)[1];
        self::assertFalse($this->readings->update('strom', $r['id'], ['is_estimated' => 'false'])['is_estimated'], '„false“ als Text war bisher wahr');
    }

    // ── Lieferung: dieselbe Prüfung ────────────────────────────────────

    public function testADeliveryTakesADecimalCommaAndRejectsText(): void
    {
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $tank = (string)$this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000, 'initial_stock' => 1000, 'installed_on' => '2024-01-01'])['id'];
        // „1250,5“ L waren bisher still 1250 L
        $d = $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-03-01', 'quantity' => '1250,5', 'unit_price_cents' => '98,4']);
        self::assertSame([1250.5, 98.4], [$d['quantity'], $d['unit_price_cents']]);
        self::assertSame('errors.common.numberExpected', $this->code(fn() => $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '2025-04-01', 'quantity' => 'viel'])));
        self::assertSame('errors.common.dateOutOfRange', $this->code(fn() => $deliveries->create('heizoel', ['meter_id' => $tank, 'date' => '0202-04-01', 'quantity' => 100])));
        self::assertSame('errors.common.textExpected', $this->code(fn() => $deliveries->update('heizoel', $d['id'], ['note' => ['a' => 1]])));
        // „false“ als Text war bisher „geplant“
        self::assertFalse($deliveries->update('heizoel', $d['id'], ['is_planned' => 'false'])['is_planned']);
    }

    // ── an einen fremden Zähler ────────────────────────────────────────

    public function testPatchMovesAReadingOnlyToAKnownCounterMeterOfThisUtility(): void
    {
        $r = $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-01', 'counter' => 50]);
        $gasMeter = $this->meters->defaultId('gas');
        self::assertSame('msg:' . $this->i18n->t('errors.common.meterNotFound', ['id' => 'm_gibt_es_nicht']),
            $this->code(fn() => $this->readings->update('strom', $r['id'], ['meter_id' => 'm_gibt_es_nicht'])));
        self::assertSame('msg:' . $this->i18n->t('errors.common.meterNotFound', ['id' => $gasMeter]),
            $this->code(fn() => $this->readings->update('strom', $r['id'], ['meter_id' => $gasMeter])), 'ein Gaszähler ist kein Stromzähler');
        self::assertSame($this->meterId, $this->readings->list('strom')[0]['meter_id'], 'die Ablesung hängt weiter am eigenen Zähler');

        // ein zweiter Stromzähler derselben Art: Umhängen bleibt möglich (Ablesung am falschen Zähler erfasst)
        $other = $this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-01-01']);
        self::assertSame($other['id'], $this->readings->update('strom', $r['id'], ['meter_id' => $other['id']])['meter_id']);
    }
}
