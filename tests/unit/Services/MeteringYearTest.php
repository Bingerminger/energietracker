<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\Rights\BillChecks;
use Energietracker\Services\RightsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Messentgelt (§ 30 MsbG): ein Jahr bleibt ein Jahr, und der Jahreswert aus Ablesungen ist ungerundet.
 *
 * 1. Zeitraum von genau einem Jahr (Stichtag zu Stichtag, 365 oder 366 Tage) wird nicht umgerechnet, nur kürzere oder
 *    längere; Menge und Betrag nach derselben Regel. Bis v3.12 wurde jede Rechnung mit × 365 ÷ Tage umgerechnet, auch
 *    ein Schaltjahr; dasselbe Jahr aus Ablesungen nicht. Von Hand, Rechnung 2028 (366 Tage):
 *      6.012 kWh: bisher 6.012 × 365 ÷ 366 = 5.995,6 → „bis 6.000“, Grenze 30 €; jetzt 6.012 → „über 6.000“, 40 €
 *      50 € Messentgelt: bisher 50 × 365 ÷ 366 = 49,86 € im Jahr; jetzt 50,00 €
 *    01.01.2027–01.01.2028 sind 366 Tage, aber ein Jahr und ein Tag: umgerechnet (× 365/366).
 * 2. Den Jahreswert aus Ablesungen summierte die App aus den auf 0,1 kWh gerundeten Monatszeilen. Genau 6.000 kWh im
 *    Jahr 2027 mit einem Stand zur Jahresmitte (2.000 am 01.01.2027, 5.700 am 01.07.2027, 8.000 am 01.01.2028):
 *    Winterhalbjahr 3.700 kWh in 181 Tagen (20,442 kWh je Tag): Januar, März, Mai 633,70 → 633,7, April und Juni
 *    613,26 → 613,3, Februar 572,38 → 572,4, zusammen 3.700,1; Sommerhalbjahr 2.300 kWh in 184 Tagen (12,5 kWh je Tag)
 *    ohne Rest. Summe der Zeilen 6.000,1 → „über 6.000“. Jetzt die ungerundete Menge des Jahres: 6.000,0 → „bis 6.000“.
 * Erfundene Zähler und Rechnungen, Prüftag fest 01.02.2029.
 */
#[CoversClass(BillChecks::class)]
#[CoversClass(ConsumptionService::class)]
final class MeteringYearTest extends ServiceTestCase
{
    private const TODAY = '2029-02-01';

    private BillService $bills;
    private RightsService $rights;
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['country' => 'DE']);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $checks = new BillChecks($this->bills, $this->contracts, $this->meters, $this->readings, $this->consumption);
        $this->rights->register('metering_cap', fn(string $t, string $c): array => $checks->metering($t, $c));
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => 'S-1', 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'imsys', 'msb_basic' => true]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2020-01-01',
            'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 30.0]]]);
    }

    /** @param array<string,mixed> $more */
    private function bill(string $from, string $to, float $eur, array $more = []): void
    {
        $this->bills->create('strom', $more + ['meter_id' => $this->meterId, 'kind' => 'annual', 'period_from' => $from, 'period_to' => $to,
            'issued_on' => date('Y-m-d', (int)strtotime("$to +20 days")),
            'items' => [['label' => 'Messstellenbetrieb', 'amount_eur' => $eur, 'kind' => 'metering', 'basis' => 'gross']]]);
    }

    /** @param array<string,float> $counters Datum → Stand */
    private function readings(array $counters): void
    {
        $rows = [];
        foreach ($counters as $d => $v) $rows[] = ['date' => $d, 'counter' => $v, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function findings(): array
    {
        return $this->rights->run('metering_cap', self::TODAY);
    }

    // ── 1. ein Jahr bleibt ein Jahr ──

    /** @return array<string, array{string, string, float, float}> von, bis, Betrag, erwarteter Faktor */
    public static function periods(): array
    {
        return [
            'Kalenderjahr 2028 (366 Tage)'               => ['2028-01-01', '2028-12-31', 366.0, 1.0],
            'Kalenderjahr 2027 (365 Tage)'               => ['2027-01-01', '2027-12-31', 365.0, 1.0],
            'Stichtag 01.03.: 01.03.2027–29.02.2028'     => ['2027-03-01', '2028-02-29', 366.0, 1.0],
            'Stichtag 01.07.: 01.07.2027–30.06.2028'     => ['2027-07-01', '2028-06-30', 366.0, 1.0],
            'ab dem Schalttag: 29.02.2028–28.02.2029'    => ['2028-02-29', '2029-02-28', 366.0, 1.0],
            'ein Jahr und ein Tag (366 Tage)'            => ['2027-01-01', '2028-01-01', 366.0, 365 / 366],
            'ein Jahr weniger ein Tag (364 Tage)'        => ['2027-01-01', '2027-12-30', 364.0, 365 / 364],
            'Halbjahr 01.01.–30.06.2028 (182 Tage)'      => ['2028-01-01', '2028-06-30', 182.0, 365 / 182],
        ];
    }

    #[DataProvider('periods')]
    public function testExactlyOneYearIsNotConverted(string $from, string $to, float $amount, float $factor): void
    {
        self::assertEqualsWithDelta($amount * $factor, BillChecks::perYear($amount, $from, $to), 1e-9);
    }

    /** Rechnung 2028 über 6.012 kWh: über 6.000, Grenze 40 €; 35 € passt, 50 € sind 10 € darüber (nicht 9,86 €). */
    public function testALeapYearBillKeepsItsQuantityAndAmount(): void
    {
        $this->bill('2028-01-01', '2028-12-31', 35.0, ['invoice' => ['energy_kwh' => 6012]]);
        self::assertSame([], $this->findings(), '35 € unter 40 €; bis v3.12 Hinweis gegen 30 €');
        $this->store->write('strom/bills.json', []);
        $this->bill('2028-01-01', '2028-12-31', 50.0, ['invoice' => ['energy_kwh' => 6012]]);
        $f = $this->findings();
        self::assertCount(1, $f);
        self::assertSame(['imsys_6000', 40.0, 50.0, 10.0, 6012.0, 'bill'], [$f[0]['facts']['case'], $f[0]['facts']['cap_eur'], $f[0]['facts']['annual_eur'],
            $f[0]['amount_eur'], (float)$f[0]['facts']['kwh_year'], $f[0]['facts']['kwh_basis']]);
    }

    /** Ein Jahr und ein Tag wird weiter umgerechnet: 6.016 kWh in 366 Tagen = 5.999,6 kWh im Jahr, bis 6.000. */
    public function testAYearAndADayIsConverted(): void
    {
        $this->bill('2027-01-01', '2028-01-01', 50.0, ['invoice' => ['energy_kwh' => 6016]]);
        $f = $this->findings();
        self::assertSame(['imsys_optional', 30.0, 49.86], [$f[0]['facts']['case'], $f[0]['facts']['cap_eur'], $f[0]['facts']['annual_eur']]);
        self::assertSame(6000.0, (float)$f[0]['facts']['kwh_year'], '6.016 × 365 ÷ 366 = 5.999,56, gerundet angezeigt');
    }

    /** Ansicht gegen Ansicht: dasselbe Jahr 2028 aus Ablesungen und aus der Rechnung landet in derselben Gruppe. */
    public function testReadingsAndTheBillPutTheSameYearIntoTheSameGroup(): void
    {
        $this->bill('2028-01-01', '2028-12-31', 60.0, ['invoice' => ['energy_kwh' => 6012]]);
        $byBill = $this->findings()[0]['facts'];
        $this->readings(['2028-01-01' => 1000.0, '2029-01-01' => 7012.0]);
        $byYears = $this->findings()[0]['facts'];
        self::assertSame(['bill', 'years'], [$byBill['kwh_basis'], $byYears['kwh_basis']]);
        foreach (['case', 'cap_eur', 'annual_eur', 'over_eur', 'kwh_year'] as $k) self::assertEquals($byBill[$k], $byYears[$k], $k);
        self::assertSame('imsys_6000', $byYears['case']);
    }

    // ── 2. Jahreswert aus Ablesungen ungerundet ──

    /**
     * Grenzen 6.000, 10.000 und 20.000 kWh je knapp darunter, genau und knapp darüber, Jahr 2027 (365 Tage).
     * Messentgelt 6 € über der Grenze der unteren Gruppe: bis zur Grenze ein Hinweis, darüber passt der Betrag.
     *
     * @return array<string, array{float, float, ?float}> Menge, Messentgelt, erwartete Grenze (null = kein Hinweis)
     */
    public static function boundaries(): array
    {
        return [
            '5.999 kWh'  => [5999.0, 36.0, 30.0], '6.000 kWh'  => [6000.0, 36.0, 30.0], '6.001 kWh'  => [6001.0, 36.0, null],
            '9.999 kWh'  => [9999.0, 46.0, 40.0], '10.000 kWh' => [10000.0, 46.0, 40.0], '10.001 kWh' => [10001.0, 46.0, null],
            '19.999 kWh' => [19999.0, 56.0, 50.0], '20.000 kWh' => [20000.0, 56.0, 50.0], '20.001 kWh' => [20001.0, 56.0, null],
            'Zehntel über 6.000: 6.000,2 kWh' => [6000.2, 36.0, null],
        ];
    }

    #[DataProvider('boundaries')]
    public function testTheYearFromReadingsIsNotASumOfRoundedMonths(float $kwh, float $eur, ?float $cap): void
    {
        $this->readings(['2027-01-01' => 2000.0, '2028-01-01' => 2000.0 + $kwh]);
        $this->bill('2027-01-01', '2027-12-31', $eur);
        $f = $this->findings();
        if ($cap === null) { self::assertSame([], $f, "$kwh kWh: obere Gruppe, der Betrag passt"); return; }
        self::assertCount(1, $f, "$kwh kWh: Hinweis gegen die Grenze der unteren Gruppe");
        self::assertSame([$cap, 6.0, 'years'], [$f[0]['facts']['cap_eur'], $f[0]['amount_eur'], $f[0]['facts']['kwh_basis']]);
        self::assertSame(round($kwh), (float)$f[0]['facts']['kwh_year']);
    }

    /** Das Mittel dreier voller Jahre (§ 30 Abs. 4 MsbG): 5.999 + 6.000 + 6.001 = 6.000 im Mittel, bis 6.000. */
    public function testTheMeanOfThreeYearsUsesTheUnroundedYears(): void
    {
        $this->readings(['2025-01-01' => 0.0, '2026-01-01' => 5999.0, '2027-01-01' => 11999.0, '2028-01-01' => 18000.0]);
        $this->bill('2027-01-01', '2027-12-31', 36.0);
        $f = $this->findings();
        self::assertCount(1, $f, 'bis v3.12: Mittel der gerundeten Monate 6.000,13, über 6.000');
        self::assertSame([30.0, 'years', 6000.0], [$f[0]['facts']['cap_eur'], $f[0]['facts']['kwh_basis'], (float)$f[0]['facts']['kwh_year']]);
    }

    /** Die Menge eines Teils des Jahres ist dieselbe Verteilung wie die Monatszeilen, nur ungerundet. */
    public function testAmountBetweenIsTheUnroundedSumOfTheMonths(): void
    {
        $this->readings(['2027-01-01' => 2000.0, '2027-07-01' => 5700.0, '2028-01-01' => 8000.0]);
        $meter = $this->meters->get('strom', $this->meterId);
        $year = $this->consumption->amountBetween('strom', $meter, '2027-01-01', '2028-01-01', self::TODAY);
        self::assertEqualsWithDelta(6000.0, $year['kwh'], 1e-6);
        self::assertSame(0, $year['open']);
        $rows = array_filter($this->consumption->forMeter('strom', $meter, null, self::TODAY), fn($r) => str_starts_with((string)$r['ym'], '2027'));
        self::assertEqualsWithDelta(6000.1, array_sum(array_column($rows, 'kwh')), 1e-6, 'die gerundeten Monatszeilen ergeben 6.000,1');
        // März allein: 31 Tage × 3.700 ÷ 181 = 633,70…, die Zeile zeigt 633,7
        self::assertEqualsWithDelta(3700 * 31 / 181, $this->consumption->amountBetween('strom', $meter, '2027-03-01', '2027-04-01', self::TODAY)['kwh'], 1e-6);
        // ohne Ablesung danach: nichts fortgeschrieben
        self::assertSame(['kwh' => 0.0, 'open' => 31], $this->consumption->amountBetween('strom', $meter, '2028-03-01', '2028-04-01', self::TODAY));
    }

    /** Doku (Deine Rechte → Messentgelt, Rechtsgrundlagen): dieselbe Regel und dasselbe Beispiel wie die App. */
    public function testTheDocumentedRuleHolds(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertEqualsWithDelta(365 / 366, BillChecks::perYear(1.0, '2027-01-01', '2028-01-01'), 1e-12, '01.01. bis 01.01.: mal 365/366');
        self::assertSame(1.0, BillChecks::perYear(1.0, '2028-01-01', '2028-12-31'));
        foreach (['docs/verstehen/17-rechte.md' => ['genau ein Jahr', '365 oder 366', 'mal 365/366', 'ungerundeten Tagesmengen'],
                  'docs/en/verstehen/17-rechte.md' => ['exactly one year', '365 or 366', 'times 365/366', 'unrounded daily'],
                  'docs/referenz/rechtsgrundlagen.md' => ['genau ein Jahr', '365 oder 366', '6.012 kWh in 366 Tagen galten als 5.996 kWh'],
                  'docs/en/referenz/rechtsgrundlagen.md' => ['exactly one year', '365 or 366', '6,012 kWh in 366 days counted as 5,996 kWh']] as $file => $want) {
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$file"));
            foreach ($want as $w) self::assertStringContainsString($w, $text, "$file: $w");
        }
        // das Beispiel der Rechtsgrundlagen: so rechnete v3.12 (× 365 ÷ 366), gerundet auf ganze kWh
        self::assertSame(5996.0, round(6012 * 365 / 366));
    }
}
