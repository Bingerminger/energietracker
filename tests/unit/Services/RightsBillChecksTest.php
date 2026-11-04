<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\Rights\BillChecks;
use Energietracker\Services\RightsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (F1035, Paket rechnung) — die Prüfungen an erfassten Versorgerrechnungen (Konzept §3):
 * A1 Rechnung zu spät, A4 Bonus nicht gutgeschrieben, A5 Schätzung trotz eigener Ablesung,
 * A6 Messentgelt über der Obergrenze, A7 Frist beim Gebührenbescheid. Erwartungen von Hand
 * gerechnet (Fristen in Kalendertagen, Beträge im Kommentar), feste Stichtage statt „heute“
 * (Lektion 86), dazu der unordentliche Testhaushalt (Lektion 85): ehrliche Rechnungen melden
 * nichts, eine geschätzte genau einen Befund.
 *
 * Erfundener Haushalt: Strom, Zähler „S-1“ seit 2024, Vertrag „Stadtwerke Musterstadt“, Tarif
 * „Basis“ seit 01.01.2025 (30 ct/kWh, 10 € im Monat).
 */
#[CoversClass(BillChecks::class)]
#[CoversClass(BillService::class)]
final class RightsBillChecksTest extends ServiceTestCase
{
    private const TODAY = '2026-06-01';
    private const MESSY = __DIR__ . '/../../fixtures/messy-household/';
    private const ROOT = __DIR__ . '/../../../';

    private BillService $bills;
    private RightsService $rights;
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->rights = new RightsService($this->settings, $this->i18n);
        // verdrahtet wie in src/bootstrap.php
        $checks = new BillChecks($this->bills, $this->contracts, $this->meters, $this->readings, $this->consumption);
        $this->rights->register('bill_late', fn(string $t, string $c): array => $checks->late($t, $c));
        $this->rights->register('bonus_missing', fn(string $t, string $c): array => $checks->bonus($t, $c));
        $this->rights->register('estimate_despite_reading', fn(string $t, string $c): array => $checks->estimate($t, $c));
        $this->rights->register('metering_cap', fn(string $t, string $c): array => $checks->metering($t, $c));
        $this->rights->register('fee_notice_deadline', fn(string $t, string $c): array => $checks->feeNotice($t, $c));
        $this->meterId = $this->setMeterDevices('strom', [self::device('d1', '2024-01-01')]);
    }

    private static function device(string $id, string $installed, ?string $removed = null, float $initial = 10000.0): array
    {
        return ['id' => $id, 'serial' => 'S-' . $id, 'installed_on' => $installed, 'initial_counter' => $initial,
                'removed_on' => $removed, 'final_counter' => null, 'reason' => null];
    }

    private function contract(array $more = []): string
    {
        return (string)$this->contracts->create('strom', $more + [
            'meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
        ])['id'];
    }

    private function bill(array $in, string $u = 'strom'): array
    {
        return $this->bills->create($u, $in + ['meter_id' => $u === 'strom' ? $this->meterId : $this->meters->defaultId($u),
            'period_from' => '2025-01-01', 'period_to' => '2025-12-31']);
    }

    /** @return list<array<string,mixed>> */
    private function check(string $check, string $today = self::TODAY): array
    {
        return $this->rights->run($check, $today);
    }

    // ── A1 Rechnung zu spät ──────────────────────────────────────────────

    /**
     * Ende des Zeitraums + 42 Tage = Fristende; Befund erst mehr als zwei Tage danach.
     *   31.03.2026 + 42 = 12.05.2026 (April 30 Tage: 30.04. ist +30, dann +12)
     *   04.04.2026 + 42 = 16.05.2026, ein Samstag: Montag (+2) still, Dienstag (+3) Befund
     *   31.01.2028 + 42 = 13.03.2028 (Februar mit 29 Tagen), 31.01.2027 + 42 = 14.03.2027
     *   Schlussrechnung 15.06.2026 + 42 = 27.07.2026, Rechnung 01.08.2026: 5 Tage
     *   Zugang 20.05.2026 statt Rechnungsdatum 10.05.2026: 8 Tage nach dem 12.05.
     *
     * @return array<string, array{0:string, 1:string, 2:string, 3:?string, 4:?int, 5:?string}>
     */
    public static function lateCases(): array
    {
        return [
            'am Fristende'                  => ['2026-03-31', 'annual', '2026-05-12', null, null, '2026-05-12'],
            'zwei Tage danach: Kulanz'      => ['2026-03-31', 'annual', '2026-05-14', null, null, '2026-05-12'],
            'drei Tage danach'              => ['2026-03-31', 'annual', '2026-05-15', null, 3, '2026-05-12'],
            'Fristende Samstag, Montag'     => ['2026-04-04', 'annual', '2026-05-18', null, null, '2026-05-16'],
            'Fristende Samstag, Dienstag'   => ['2026-04-04', 'annual', '2026-05-19', null, 3, '2026-05-16'],
            'Schaltjahr'                    => ['2028-01-31', 'annual', '2028-03-16', null, 3, '2028-03-13'],
            'kein Schaltjahr'               => ['2027-01-31', 'annual', '2027-03-17', null, 3, '2027-03-14'],
            'Schlussrechnung'               => ['2026-06-15', 'final', '2026-08-01', null, 5, '2026-07-27'],
            'Zugang zählt vor dem Datum'    => ['2026-03-31', 'annual', '2026-05-10', '2026-05-20', 8, '2026-05-12'],
            'Zwischenrechnung: keine Frist' => ['2026-03-31', 'interim', '2026-09-01', null, null, null],
        ];
    }

    #[DataProvider('lateCases')]
    public function testLateBillAgainstTheStatutoryDeadline(string $to, string $kind, string $issued, ?string $received, ?int $days, ?string $deadline): void
    {
        $cid = $this->contract();
        $b = $this->bill(['period_from' => '2025-04-01', 'period_to' => $to, 'kind' => $kind, 'issued_on' => $issued] + ($received ? ['received_on' => $received] : []));
        self::assertSame($deadline, Rights::billDeadline('DE', 'strom', $to, $kind));
        $f = $this->check('bill_late');
        if ($days === null) { self::assertSame([], $f); return; }
        self::assertCount(1, $f);
        $f = $f[0];
        $at = $received ?? $issued;
        self::assertSame(['info', 'strom', $this->meterId, $cid, (string)$b['id'], $at, null, 'complaint'],
            [$f['severity'], $f['utility'], $f['meter_id'], $f['contract_id'], $f['bill_id'], $f['date'], $f['deadline'], $f['letter']]);
        self::assertSame([$days, $deadline, $at], [$f['facts']['days_late'], $f['facts']['deadline_on'], $f['facts']['bill_date']]);
        // Fakten unter den Namen, die der Brief „Beanstandung“ liest (Paket briefe)
        self::assertSame([$issued, $received, $kind, '2025-04-01', $to, null], [$f['facts']['issued_on'], $f['facts']['received_on'],
            $f['facts']['bill_kind'], $f['facts']['period_from'], $f['facts']['period_to'], $f['facts']['invoice_number']]);
        self::assertSame(['de.enwg.40c.2', 'de.enwg.111a'], $f['rules']);
        // v3.11.0 (Skeptiker N1) — mit Verbrauchsart und Ende des Zeitraums: welche Rechnung
        self::assertSame('Strom: Rechnung bis ' . $this->i18n->date($to) . " kam $days Tage nach der Frist", $f['title']);
        self::assertStringContainsString('Ablauf der gesetzlichen Frist am ' . $this->i18n->date($deadline), $f['text']);
        // v3.11.0 (Skeptiker N5) — ohne Zugang zählt das Rechnungsdatum: „ist vom …“, mit Zugang „lag am … vor“
        self::assertSame($received !== null ? 'rights.late.text' : 'rights.late.textIssued', $f['text_key']);
        self::assertStringContainsString($received !== null ? 'lag am ' . $this->i18n->date($at) . ' vor' : 'ist vom ' . $this->i18n->date($at), $f['text']);
        self::assertStringContainsString('Eine Folge für den Rechnungsbetrag nennt das Gesetz nicht.', $f['text']);
        self::assertStringStartsWith('Hinweis, keine Rechtsberatung. Grundlage: § 40c Abs. 2 EnWG, § 111a EnWG', $f['footer']);
    }

    public function testLateNeedsADateAndAnEnergyBillAndGermany(): void
    {
        $this->contract();
        $this->bill(['period_to' => '2025-12-31']);   // ohne Datum: keine Prüfung
        $this->bill(['period_to' => '2025-12-31', 'issued_on' => '2026-04-01'], 'wasser');
        $this->bill(['period_to' => '2025-12-31', 'issued_on' => '2026-04-01'], 'fernwaerme');
        self::assertSame([], $this->check('bill_late'), 'Wasser und Fernwärme kennen keine gesetzliche Frist; ohne Datum nichts');
        $this->bill(['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'issued_on' => '2025-03-01']);   // 11.02. + 18
        self::assertCount(1, $this->check('bill_late'));
        $this->settings->set(['country' => 'AT']);
        self::assertSame([], $this->check('bill_late'), 'nur Deutschland');
    }

    public function testLateIsOnlyInToDoWithinTheWindow(): void
    {
        $this->contract();
        $this->bill(['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'issued_on' => '2025-03-01']);
        // Anlass 01.03.2025: 120 Tage später (29.06.2025) noch unter „Zu tun“, am Tag danach nicht mehr
        self::assertTrue($this->rights->findings('2025-06-29')[0]['todo']);
        self::assertFalse($this->rights->findings('2025-06-30')[0]['todo']);
    }

    // ── A4 Bonus nicht gutgeschrieben ────────────────────────────────────

    /**
     * Neukundenbonus 100 € (Vertrag seit 01.01.2025), Jahresrechnung 2025 vom 20.01.2026. Gutschrift
     * zählt ab 99 € (Toleranz 1 €); ohne Angabe netto/brutto auch netto (× 1,19): 83,20 × 1,19 = 99,01.
     *
     * @return array<string, array{0: list<array<string,mixed>>, 1: bool}>
     */
    public static function bonusCredits(): array
    {
        return [
            'keine Gutschrift'                   => [[], true],
            'Gutschrift 100 €'                   => [[['label' => 'Neukundenbonus', 'amount_eur' => -100, 'kind' => 'credit']], false],
            'Gutschrift unter fremdem Namen'     => [[['label' => 'Treueprämie Q1', 'amount_eur' => -100, 'kind' => 'credit']], false],
            'brutto 99 € reicht'                 => [[['label' => 'x', 'amount_eur' => -99, 'kind' => 'credit', 'basis' => 'gross']], false],
            'brutto 98,99 € reicht nicht'        => [[['label' => 'x', 'amount_eur' => -98.99, 'kind' => 'credit', 'basis' => 'gross']], true],
            'netto 84,04 € mit 19 % reicht'      => [[['label' => 'x', 'amount_eur' => -84.04, 'kind' => 'credit', 'basis' => 'net', 'vat_pct' => 19]], false],
            'netto 84,04 € mit 7 % reicht nicht' => [[['label' => 'x', 'amount_eur' => -84.04, 'kind' => 'credit', 'basis' => 'net', 'vat_pct' => 7]], true],
            'ohne Angabe 83,20 € (netto?)'       => [[['label' => 'x', 'amount_eur' => -83.2, 'kind' => 'credit']], false],
            'ohne Angabe 83 € zu wenig'          => [[['label' => 'x', 'amount_eur' => -83, 'kind' => 'credit']], true],
            'Betrag als Umlage, nicht Gutschrift' => [[['label' => 'Bonus', 'amount_eur' => -100, 'kind' => 'levy']], true],
        ];
    }

    #[DataProvider('bonusCredits')]
    public function testSignupBonusAgainstTheCreditsOfTheBill(array $items, bool $finding): void
    {
        $cid = $this->contract(['signup_bonus_eur' => 100.0]);
        $b = $this->bill(['issued_on' => '2026-01-20', 'contract_id' => $cid, 'items' => $items]);
        $f = $this->check('bonus_missing');
        if (!$finding) { self::assertSame([], $f, json_encode($items)); return; }
        self::assertCount(1, $f);
        self::assertSame(['warning', $cid, (string)$b['id'], '2026-01-20', 100.0, 'complaint', 'rights.bonus.text'],
            [$f[0]['severity'], $f[0]['contract_id'], $f[0]['bill_id'], $f[0]['date'], $f[0]['amount_eur'], $f[0]['letter'], $f[0]['text_key']]);
        self::assertSame(['de.enwg.41.4', 'de.vz.bonus'], $f[0]['rules']);
        // Fakten wie im Brief „Beanstandung“ (Paket briefe)
        self::assertSame([100.0, 'Basis', '2026-01-20', '2025-01-01', '2025-12-31'], [$f[0]['facts']['bonus_eur'], $f[0]['facts']['tariff'],
            $f[0]['facts']['issued_on'], $f[0]['facts']['period_from'], $f[0]['facts']['period_to']]);
        $eur = $this->i18n->money(100.0);
        self::assertSame("Strom: Bonus von $eur nicht gefunden", $f[0]['title']);   // v3.11.0 (Skeptiker N1)
        self::assertStringContainsString("Laut Vertrag „Basis“ steht dir ein Bonus von $eur zu.", $f[0]['text']);
    }

    public function testTheSignupBonusWaitsForTwelveMonthsOrTheFinalBill(): void
    {
        $cid = $this->contract(['signup_bonus_eur' => 100.0]);
        // erste Rechnung nach einem halben Jahr: zu früh für den Bonus, kein Befund
        $first = $this->bill(['period_to' => '2025-06-30', 'issued_on' => '2025-07-20', 'contract_id' => $cid]);
        self::assertSame([], $this->check('bonus_missing'));
        // die zweite endet zwölf Monate nach Beginn (31.12.2025): jetzt erwartet; gesucht in beiden
        $second = $this->bill(['period_from' => '2025-07-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-01-20', 'contract_id' => $cid]);
        $f = $this->check('bonus_missing');
        self::assertSame([(string)$second['id']], array_column($f, 'bill_id'));
        $this->bills->update('strom', (string)$first['id'], ['items' => [['label' => 'Bonus vorab', 'amount_eur' => -100, 'kind' => 'credit']]]);
        self::assertSame([], $this->check('bonus_missing'), 'die Gutschrift der ersten Rechnung deckt den Bonus');
        // Schlussrechnung nach acht Monaten: erwartet (Text neutral: Bedingungen prüfen)
        $this->store->write('strom/bills.json', []);
        $final = $this->bill(['period_to' => '2025-08-31', 'kind' => 'final', 'issued_on' => '2025-09-20', 'contract_id' => $cid]);
        self::assertSame([(string)$final['id']], array_column($this->check('bonus_missing'), 'bill_id'));
    }

    public function testDatedBonusesSofortAndPortalAndOneCreditPerBonus(): void
    {
        $cid = $this->contract(['signup_bonus_eur' => 100.0, 'bonuses' => [
            ['credit_date' => '2025-03-01', 'amount_eur' => 50, 'type' => 'wechsel'],
            ['credit_date' => '2025-02-01', 'amount_eur' => 30, 'type' => 'sofort'],      // per Überweisung: keine Prüfung
            ['credit_date' => '2026-01-25', 'amount_eur' => 20, 'type' => 'sonstige'],   // Vergleichsportal: Hinweis ohne Brief
        ]]);
        // eine Gutschrift von 100 € deckt den Neukundenbonus (größter zuerst), nicht auch den Wechselbonus
        $b = $this->bill(['issued_on' => '2026-01-20', 'contract_id' => $cid, 'items' => [['label' => 'Bonus', 'amount_eur' => -100, 'kind' => 'credit']]]);
        $f = $this->check('bonus_missing');
        $by = array_column(array_map(fn($x) => [$x['facts']['bonus_type'], [$x['amount_eur'], $x['letter'], $x['text_key']]], $f), 1, 0);
        ksort($by);
        self::assertSame(['sonstige' => [20.0, null, 'rights.bonus.textPortal'], 'wechsel' => [50.0, 'complaint', 'rights.bonus.text']], $by);
        self::assertCount(2, array_unique(array_column($f, 'id')), 'zwei Boni an derselben Rechnung: zwei IDs');
        self::assertSame([(string)$b['id']], array_values(array_unique(array_column($f, 'bill_id'))));
    }

    public function testABookedRepaymentCountsButNotTheResultOfABill(): void
    {
        $cid = $this->contract(['signup_bonus_eur' => 100.0]);
        $b = $this->bill(['issued_on' => '2026-01-20', 'contract_id' => $cid]);
        $this->contracts->update('strom', $cid, ['special_payments' => [['id' => 'sp_erg', 'date' => '2026-01-25', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 120.0, 'note' => '']]]);
        $this->bills->update('strom', (string)$b['id'], ['special_payment_id' => 'sp_erg']);
        self::assertCount(1, $this->check('bonus_missing'), 'das Ergebnis der Rechnung ist keine Bonuszahlung');
        $this->contracts->update('strom', $cid, ['special_payments' => [
            ['id' => 'sp_erg', 'date' => '2026-01-25', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 120.0, 'note' => ''],
            ['id' => 'sp_bonus', 'date' => '2026-02-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 100.0, 'note' => 'Bonus überwiesen'],
        ]]);
        self::assertSame([], $this->check('bonus_missing'));
    }

    public function testBonusOnlyForEnergyAndNotAfterTheLimitationPeriod(): void
    {
        $cid = $this->contract(['signup_bonus_eur' => 100.0]);
        $this->bill(['issued_on' => '2026-01-20', 'contract_id' => $cid]);
        // §§ 195, 199 BGB: drei Jahre ab Ende des Jahres 2026 → bis 31.12.2029
        self::assertCount(1, $this->check('bonus_missing', '2029-12-31'));
        self::assertSame([], $this->check('bonus_missing', '2030-01-01'));
        $this->store->write('strom/bills.json', []);
        $w = (string)$this->contracts->create('wasser', ['meter_id' => $this->meters->defaultId('wasser'), 'provider' => 'Wasserwerk', 'start' => '2025-01-01',
            'signup_bonus_eur' => 100.0])['id'];
        $this->bill(['issued_on' => '2026-01-20', 'contract_id' => $w], 'wasser');
        self::assertSame([], $this->check('bonus_missing'), 'Wasser: keine Prüfung (§ 40c EnWG gilt für Strom und Gas)');
    }

    // ── A5 Schätzung trotz eigener Ablesung ──────────────────────────────

    /** Rechnung 2025 (365 Tage) mit geschätztem Endstand; Ablesungen des Geräts d1 (eigene, nicht geschätzt). */
    private function estimated(float $billCounter, array $own, array $bill = []): array
    {
        $rows = array_map(fn($r) => $r + ['device_id' => 'd1'], $own);
        $rows[] = ['date' => '2025-12-31', 'counter' => $billCounter, 'device_id' => $bill['device'] ?? 'd1', 'is_estimated' => true];
        $this->setReadings('strom', $this->meterId, $rows);
        unset($bill['device']);
        return $this->bill($bill + ['issued_on' => '2026-01-15', 'reading_estimated' => ['start' => false, 'end' => true]]);
    }

    /**
     * Eigene Stände 20.12.2025: 14.000 und 10.01.2026: 14.200 (21 Tage, 9,5238 kWh/Tag).
     * Fortschreibung auf den 31.12.: 14.000 + 11 × 9,5238 = 14.104,76. Menge des Zeitraums
     * 9,5238 × 365 = 3.476,19 kWh, 2 % = 69,52 kWh.
     *   14.170: Abweichung 65,24 → kein Befund (Hochrechnung)
     *   14.190: + 85,24 kWh → Befund, × 0,30 €/kWh = 25,57 €
     *   14.010: − 94,76 kWh (zu deinen Gunsten) → Befund, 28,43 €
     *
     * @return array<string, array{0: float, 1: ?float, 2: ?float}>
     */
    public static function estimateCases(): array
    {
        return [
            'Hochrechnung deines Stands' => [14170.0, null, null],
            'Schätzung höher'            => [14190.0, 85.2, 25.57],
            'Schätzung niedriger'        => [14010.0, -94.8, 28.43],
        ];
    }

    #[DataProvider('estimateCases')]
    public function testEstimateAgainstTheProjectionOfYourOwnReadings(float $billCounter, ?float $delta, ?float $eur): void
    {
        $cid = $this->contract();
        $b = $this->estimated($billCounter, [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0],
            ['date' => '2026-01-10', 'counter' => 14200.0]]);
        $f = $this->check('estimate_despite_reading');
        if ($delta === null) { self::assertSame([], $f); return; }
        self::assertCount(1, $f);
        $x = $f[0];
        self::assertSame(['warning', $this->meterId, $cid, (string)$b['id'], '2026-01-15', 'estimate_query', 'rights.estimate.text', 'rights.estimate.titleEur'],
            [$x['severity'], $x['meter_id'], $x['contract_id'], $x['bill_id'], $x['date'], $x['letter'], $x['text_key'], $x['title_key']]);
        // genannt wird die nähere eigene Ablesung: 10.01. (10 Tage danach) vor 20.12. (11 Tage davor)
        self::assertSame([14104.8, '2026-01-10', 14200.0, $delta], [$x['facts']['own_projected'], $x['facts']['own_reading_on'], $x['facts']['own_reading'], $x['facts']['delta_counter']]);
        // Fakten unter den Namen, die der Brief „Rückfrage zur Schätzung“ liest (Paket briefe)
        self::assertSame(['2026-01-15', '2025-12-31', $billCounter, 'kWh', false, null], [$x['facts']['issued_on'], $x['facts']['period_to'],
            $x['facts']['bill_reading'], $x['facts']['unit'], $x['facts']['photo'], $x['facts']['reported_on']]);
        self::assertEqualsWithDelta(3476.2, $x['facts']['period_qty'], 0.1);
        self::assertEqualsWithDelta($eur, $x['amount_eur'], 0.05);
        self::assertSame(['de.enwg.40a.2.s1', 'de.enwg.40a.2.s3', 'de.stromgvv.11'], $x['rules']);
        self::assertStringContainsString('Hast du den Stand gemeldet?', $x['text']);
        self::assertStringContainsString('Der Unterschied: ' . $this->i18n->number(abs($delta), 2) . ' kWh.', $x['text']);
    }

    /**
     * v3.11.0 (integration) — „eine Berichtigung mit deinem Stand verlangen“ ist nur für die Grundversorgung
     * belegt (§ 18 StromGVV, Prüfbericht 28). Sondervertrag und unbekannte Art: eine Bitte ohne Anspruch.
     * v3.11.0 (Skeptiker M3) — und nur mit gemeldetem Stand: Ohne Meldung war die Schätzung womöglich
     * zulässig (Prüfbericht 8), dann auch in der Grundversorgung die Bitte. N6: Wortlaut nach Prüfbericht 8.
     */
    public function testEstimateClaimsACorrectionOnlyInBasicSupply(): void
    {
        $own = [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0], ['date' => '2026-01-10', 'counter' => 14200.0]];
        $ask = 'Du kannst ihn außerdem bitten, mit deinem Stand neu zu rechnen.';
        foreach (['', 'special', 'basic'] as $kind) {
            $this->store->write('strom/contracts.json', []);
            $this->store->write('strom/bills.json', []);
            $this->contract($kind !== '' ? ['supply_kind' => $kind] : []);
            $this->estimated(14190.0, $own);
            $x = $this->check('estimate_despite_reading')[0];
            self::assertSame('rights.estimate.text', $x['text_key'], "Art $kind");
            self::assertStringContainsString($ask, $x['text'], "Art $kind");
            self::assertStringNotContainsString('Berichtigung', $x['text'], "Art $kind");
            self::assertNotContains('de.stromgvv.18', $x['rules']);
            self::assertStringContainsString('nur, wenn trotz entsprechender Pflicht keine Ablesedaten übermittelt wurden', $x['text']);
        }
        // gemeldet vor der Rechnung: in der Grundversorgung der Anspruch auf Berichtigung (§ 18 StromGVV)
        $this->store->write('strom/contracts.json', []);
        $this->store->write('strom/bills.json', []);
        $this->contract(['supply_kind' => 'basic']);
        $this->estimated(14190.0, [$own[0], $own[1] + ['reported_on' => '2025-12-21'], $own[2]]);
        $x = $this->check('estimate_despite_reading')[0];
        self::assertSame('rights.estimate.textReportedBasic', $x['text_key']);
        self::assertStringContainsString('und eine Berichtigung mit deinem Stand.', $x['text']);
        self::assertStringNotContainsString($ask, $x['text']);
        self::assertSame(['de.enwg.40a.2.s1', 'de.enwg.40a.2.s3', 'de.stromgvv.11', 'de.stromgvv.18'], $x['rules']);
        $this->store->write('strom/contracts.json', []);
        $this->contract(['supply_kind' => 'special']);
        $x = $this->check('estimate_despite_reading')[0];
        self::assertSame('rights.estimate.textReported', $x['text_key']);
        self::assertStringContainsString($ask, $x['text']);
    }

    public function testEstimateWithOnlyAReadingBeforeOrAfterAndTheWindow(): void
    {
        $this->contract();
        // nur davor: 01.01.→20.12.2025 (353 Tage, 2.000 kWh) = 5,6657 kWh/Tag; 14.000 + 11 × 5,6657 = 14.062,32;
        // 2 % von 5,6657 × 365 = 41,36 kWh. 14.150 → + 87,68 Befund; 14.100 → + 37,68 nicht
        $this->estimated(14150.0, [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0]]);
        $f = $this->check('estimate_despite_reading');
        self::assertSame([14062.3, 87.7], [$f[0]['facts']['own_projected'], $f[0]['facts']['delta_counter']]);
        $this->store->write('strom/bills.json', []);
        $this->estimated(14100.0, [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0]]);
        self::assertSame([], $this->check('estimate_despite_reading'));
        // nur danach: 10.01.→09.02.2026 (30 Tage, 300 kWh) = 10 kWh/Tag; 14.200 − 10 × 10 = 14.100; 2 % von 3.650 = 73
        $this->store->write('strom/bills.json', []);
        $this->estimated(14000.0, [['date' => '2026-01-10', 'counter' => 14200.0], ['date' => '2026-02-09', 'counter' => 14500.0]]);
        $f = $this->check('estimate_despite_reading');
        self::assertSame([14100.0, -100.0, '2026-01-10'], [$f[0]['facts']['own_projected'], $f[0]['facts']['delta_counter'], $f[0]['facts']['own_reading_on']]);
        // Fenster: 14 Tage vor dem Ende zählt (17.12.), 15 Tage nicht (16.12.)
        foreach (['2025-12-17' => 1, '2025-12-16' => 0] as $day => $n) {
            $this->store->write('strom/bills.json', []);
            $this->estimated(14400.0, [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => $day, 'counter' => 13950.0]]);
            self::assertCount($n, $this->check('estimate_despite_reading'), $day);
        }
    }

    public function testEstimateReportedReadingDeviceAndMarks(): void
    {
        $this->contract(['supply_kind' => 'special']);
        $own = [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0, 'reported_on' => '2025-12-21'],
                ['date' => '2026-01-10', 'counter' => 14200.0]];
        $this->estimated(14190.0, $own);
        $f = $this->check('estimate_despite_reading')[0];
        // gemeldet vor der Rechnung (15.01.2026): die deutliche Fassung, mit der gemeldeten Ablesung (auch wenn
        // die vom 10.01. näher liegt); Sondervertrag: ohne § 11 GVV
        self::assertSame(['rights.estimate.textReported', '2025-12-21', true, '2025-12-20'],
            [$f['text_key'], $f['facts']['reported_on'], $f['facts']['reported'], $f['facts']['own_reading_on']]);
        self::assertSame(['de.enwg.40a.2.s1', 'de.enwg.40a.2.s3'], $f['rules']);
        self::assertStringContainsString('und den Stand am 21.12.2025 gemeldet', $f['text']);
        // gemeldet erst nach der Rechnung: die Frage, und genannt wird wieder die nähere Ablesung (10.01.)
        $own[1]['reported_on'] = '2026-01-20';
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $own);
        $f = $this->check('estimate_despite_reading')[0];
        self::assertSame(['rights.estimate.text', '2026-01-10'], [$f['text_key'], $f['facts']['own_reading_on']]);
        // die nähere Ablesung selbst erst nach der Rechnung gemeldet: genannt, aber kein Beleg
        $late = $own;
        unset($late[1]['reported_on']);
        $late[2]['reported_on'] = '2026-01-20';
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $late);
        $f = $this->check('estimate_despite_reading')[0];
        self::assertSame(['rights.estimate.text', '2026-01-10', '2026-01-20', false],
            [$f['text_key'], $f['facts']['own_reading_on'], $f['facts']['reported_on'], $f['facts']['reported']]);
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $own);
        // intelligentes Messsystem: Werte des Messstellenbetreibers gehen vor (§ 40a Abs. 1 Satz 4 EnWG)
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'imsys']);
        self::assertContains('de.enwg.40a.1', $this->check('estimate_despite_reading')[0]['rules']);
        // Stand der Rechnung an einem anderen Gerät (Zählertausch): keine eigene Ablesung desselben Geräts
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $own, ['device' => 'd2']);
        self::assertSame([], $this->check('estimate_despite_reading'));
        // nur der Anfangsstand geschätzt, oder gar keiner: keine Prüfung
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $own, ['reading_estimated' => ['start' => true, 'end' => false]]);
        self::assertSame([], $this->check('estimate_despite_reading'));
    }

    public function testEstimateForWaterNamesTheOwnRule(): void
    {
        $wm = $this->setMeterDevices('wasser', [self::device('w1', '2024-01-01', null, 100.0)]);
        $this->setReadings('wasser', $wm, [
            ['date' => '2025-01-01', 'counter' => 200.0, 'device_id' => 'w1'], ['date' => '2025-12-21', 'counter' => 290.0, 'device_id' => 'w1'],
            ['date' => '2025-12-31', 'counter' => 300.0, 'device_id' => 'w1', 'is_estimated' => true]]);
        // 90 m³ in 354 Tagen = 0,25424 m³/Tag; 290 + 10 × 0,25424 = 292,54; 2 % von 92,80 = 1,86 m³; 300 → + 7,46
        $this->bills->create('wasser', ['meter_id' => $wm, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-01-15',
            'reading_estimated' => ['end' => true]]);
        $f = $this->check('estimate_despite_reading');
        self::assertCount(1, $f);
        self::assertSame(['rights.estimate.textHeat', ['de.avbwasserv.20.2'], 292.5, 7.5, null],
            [$f[0]['text_key'], $f[0]['rules'], $f[0]['facts']['own_projected'], $f[0]['facts']['delta_counter'], $f[0]['amount_eur']]);
        self::assertSame('rights.estimate.title', $f[0]['title_key'], 'ohne Preise kein Betrag im Titel');
        self::assertStringContainsString('solange sein Beauftragter die Räume zur Ablesung nicht betreten kann', $f[0]['text']);
    }

    /**
     * v3.11.0 (Skeptiker M1) — die 2 % gelten von der Menge laut Rechnung, dazu mindestens 10 € Wirkung.
     * Eigene Stände wie oben: fortgeschrieben 14.104,76, Schätzung 14.190 → + 85,24 kWh, × 0,30 € = 25,57 €.
     *   Rechnung 5.000 kWh: 2 % = 100 kWh > 85,24 → kein Befund (mit Tag × 365 = 3.476 kWh wäre es einer)
     *   Rechnung 3.000 kWh: 2 % = 60 kWh < 85,24 → Befund, Grundlage `bill`
     *   Arbeitspreis 10 ct: 85,24 × 0,10 = 8,52 € < 10 € → kein Befund
     */
    public function testEstimateMeasuresAgainstTheQuantityOfTheBillAndAMinimumInEuro(): void
    {
        $own = [['date' => '2025-01-01', 'counter' => 12000.0], ['date' => '2025-12-20', 'counter' => 14000.0], ['date' => '2026-01-10', 'counter' => 14200.0]];
        $this->contract();
        $this->estimated(14190.0, $own, ['invoice' => ['energy_kwh' => 5000]]);
        self::assertSame([], $this->check('estimate_despite_reading'), '2 % von 5.000 kWh = 100 kWh');
        $this->store->write('strom/bills.json', []);
        $this->estimated(14190.0, $own, ['invoice' => ['energy_kwh' => 3000]]);
        $f = $this->check('estimate_despite_reading');
        self::assertCount(1, $f);
        self::assertSame([3000.0, 'bill', 85.2], [$f[0]['facts']['period_qty'], $f[0]['facts']['qty_basis'], $f[0]['facts']['delta_counter']]);
        self::assertEqualsWithDelta(25.57, $f[0]['amount_eur'], 0.05);
        self::assertSame(10.0, Rights::ESTIMATE_MIN_EUR);
        // derselbe Unterschied bei 10 ct/kWh: 8,52 € unter der Mindestwirkung
        $this->store->write('strom/contracts.json', []);
        $this->contract(['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]]]);
        self::assertSame([], $this->check('estimate_despite_reading'), '8,52 € < 10 €');
    }

    /**
     * v3.11.0 (Skeptiker M5) — der geschätzte Endstand steht an der Rechnung, nicht als Ablesung: A5 prüft
     * ihn wie bisher, die Ablesungen (und damit der eigene Verbrauch) bleiben unberührt. Liegt er über einer
     * späteren oder unter einer früheren eigenen Ablesung, warnt das Speichern (Zähler liefe rückwärts).
     */
    public function testTheEstimatedEndReadingOnTheBillIsNoReading(): void
    {
        $this->contract();
        $rows = [['date' => '2025-01-01', 'counter' => 12000.0, 'device_id' => 'd1'], ['date' => '2025-12-20', 'counter' => 14000.0, 'device_id' => 'd1'],
                 ['date' => '2026-01-10', 'counter' => 14200.0, 'device_id' => 'd1']];
        $this->setReadings('strom', $this->meterId, $rows);
        $b = $this->bill(['issued_on' => '2026-01-15', 'estimated_end_reading' => ['value' => '14190', 'on' => '']]);
        self::assertSame(['value' => 14190.0, 'on' => '2025-12-31'], $b['estimated_end_reading'], 'ohne Tag: Ende des Zeitraums');
        self::assertCount(3, $this->readings->list('strom', $this->meterId), 'keine Ablesung angelegt');
        $f = $this->check('estimate_despite_reading');
        self::assertCount(1, $f, 'auch ohne Haken „Endstand geschätzt“');
        self::assertSame([14190.0, 85.2, true], [$f[0]['facts']['bill_reading'], $f[0]['facts']['delta_counter'], $f[0]['facts']['on_bill']]);
        self::assertEqualsWithDelta(25.57, $f[0]['amount_eur'], 0.05);
        self::assertNull(BillService::backwardsNeighbor('strom', $b, $this->readings, $this->meters), '14.190 liegt zwischen 14.000 und 14.200');
        // über der Ablesung vom 10.01.2026 (14.200): Zähler liefe rückwärts
        $up = $this->bills->update('strom', (string)$b['id'], ['estimated_end_reading' => ['value' => 14250, 'on' => '2025-12-31']]);
        $w = $this->bills->withEstimateWarnings('strom', $up, $this->readings);
        self::assertSame(['estimated_reading_backwards'], $w['warnings']);
        self::assertSame(['date' => '2026-01-10', 'counter' => 14200.0], $w['estimated_reading_neighbor']);
        // unter der Ablesung vom 20.12.2025 (14.000)
        $down = $this->bills->update('strom', (string)$b['id'], ['estimated_end_reading' => ['value' => 13900, 'on' => '2025-12-31']]);
        self::assertSame(['date' => '2025-12-20', 'counter' => 14000.0], BillService::backwardsNeighbor('strom', $down, $this->readings, $this->meters));
        // leer entfernt
        self::assertNull($this->bills->update('strom', (string)$b['id'], ['estimated_end_reading' => null])['estimated_end_reading']);
    }

    /**
     * v3.11.0 (Skeptiker M2) — Wasser per Gebührenbescheid: keine AVBWasserV, kein Rückfrage-Brief, sondern der
     * Rechtsbehelf und seine Frist. Bescheid vom 15.01.2026 → bekannt 19.01. → Frist 19.02.2026.
     */
    public function testEstimateOnAFeeNoticePointsToTheRemedyInsteadOfAWaterRule(): void
    {
        $wm = $this->setMeterDevices('wasser', [self::device('w1', '2024-01-01', null, 100.0)]);
        $this->setReadings('wasser', $wm, [
            ['date' => '2025-01-01', 'counter' => 200.0, 'device_id' => 'w1'], ['date' => '2025-12-21', 'counter' => 290.0, 'device_id' => 'w1']]);
        $this->bills->create('wasser', ['meter_id' => $wm, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-01-15',
            'estimated_end_reading' => ['value' => 300, 'on' => '2025-12-31'],
            'notice' => ['public_law' => true, 'dated_on' => '2026-01-15', 'remedy' => 'objection']]);
        $f = $this->check('estimate_despite_reading', '2026-01-20');
        self::assertCount(1, $f);
        self::assertSame(['rights.estimate.textNotice', null, ['de.vwgo.70.1', 'de.vwvfg.41.2'], '2026-02-19'],
            [$f[0]['text_key'], $f[0]['letter'], $f[0]['rules'], $f[0]['params']['deadline']]);
        self::assertStringContainsString('eine formlose Rückfrage wahrt sie nicht', $f[0]['text']);
        self::assertStringNotContainsString('Beauftragter', $f[0]['text']);
        self::assertSame('rights.estimate.textNoticeExpired', $this->check('estimate_despite_reading', '2026-02-20')[0]['text_key']);
    }

    // ── A6 Messentgelt über der Obergrenze ───────────────────────────────

    private function meteringBill(array $items, array $more = []): array
    {
        return $this->bill($more + ['period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'issued_on' => '2027-01-20', 'items' => $items]);
    }

    /**
     * Moderne Messeinrichtung, grundzuständig: Obergrenze 25 € im Jahr (§ 32 Abs. 1 MsbG), Befund
     * über 26 € (Toleranz 1 €). Zeitraum 2026 (365 Tage): Jahresbetrag = Summe brutto.
     *
     * @return array<string, array{0: list<array<string,mixed>>, 1: ?float}>
     */
    public static function meteringCases(): array
    {
        $m = fn(float $a, array $x = []) => ['label' => 'Messstellenbetrieb', 'amount_eur' => $a, 'kind' => 'metering'] + $x;
        return [
            '25 € brutto'                       => [[$m(25.0)], null],
            '26 € an der Grenze'                => [[$m(26.0)], null],
            '26,01 € darüber'                   => [[$m(26.01)], 1.01],
            '30 € brutto'                       => [[$m(30.0)], 5.0],
            'netto 21,18 € + 19 % = 25,20 €'    => [[$m(21.18, ['basis' => 'net', 'vat_pct' => 19])], null],
            'netto 22 € + 19 % = 26,18 €'       => [[$m(22.0, ['basis' => 'net', 'vat_pct' => 19])], 1.18],
            'netto ohne Satz: nicht vergleichbar' => [[$m(30.0, ['basis' => 'net'])], null],
            'zwei Posten 15 € + 15 €'           => [[$m(15.0), $m(15.0)], 5.0],
            'Posten anderer Art'                => [[['label' => 'Messung', 'amount_eur' => 40.0, 'kind' => 'fee']], null],
        ];
    }

    #[DataProvider('meteringCases')]
    public function testMeteringChargeOfAModernDevice(array $items, ?float $over): void
    {
        $cid = $this->contract();
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'mme', 'msb_basic' => true]);
        $b = $this->meteringBill($items);
        $f = $this->check('metering_cap');
        if ($over === null) { self::assertSame([], $f); return; }
        self::assertCount(1, $f);
        self::assertSame(['warning', $cid, (string)$b['id'], '2027-01-20', $over, 'mme', 25.0, 'complaint', 'rights.metering.textMme'],
            [$f[0]['severity'], $f[0]['contract_id'], $f[0]['bill_id'], $f[0]['date'], $f[0]['amount_eur'], $f[0]['facts']['case'],
             $f[0]['facts']['cap_eur'], $f[0]['letter'], $f[0]['text_key']]);
        self::assertSame(['de.msbg.32.1', 'de.bnetza.msb_costs', 'de.msbg.35.1', 'de.enwg.40.3.4'], $f[0]['rules']);
        // Fakten wie im Brief „Beanstandung“ (Paket briefe): Jahresbetrag, Grenze, Regel der Fallgruppe, Gerät
        self::assertSame([25.0 + $over, 25.0, 'de.msbg.32.1', 'mme', '2027-01-20'], [$f[0]['facts']['annual_eur'], $f[0]['facts']['cap_eur'],
            $f[0]['facts']['cap_rule'], $f[0]['facts']['device'], $f[0]['facts']['issued_on']]);
        self::assertStringContainsString('eine Obergrenze von ' . $this->i18n->money(25.0) . ' im Jahr', $f[0]['text']);
    }

    public function testMeteringNeedsTheDeviceTheDefaultOperatorAndTheTableFrom2026(): void
    {
        $this->contract();
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 30.0, 'kind' => 'metering']]);
        self::assertSame([], $this->check('metering_cap'), 'ohne Messeinrichtung am Zähler keine Prüfung');
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'conventional']);
        self::assertSame([], $this->check('metering_cap'), 'konventioneller Zähler: keine Obergrenze');
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'mme', 'msb_basic' => false]);
        self::assertSame([], $this->check('metering_cap'), 'frei gewählter Messstellenbetreiber');
        $this->meters->update('strom', $this->meterId, ['msb_basic' => null]);
        self::assertSame([null], array_map(fn($x) => $x['facts']['msb_basic'], $this->check('metering_cap')), 'unbekannt: Befund, der Text nennt die Bedingung');
        // Zeitraum vor der Tabelle (2025): keine Prüfung (Entscheidung 4a)
        $this->store->write('strom/bills.json', []);
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 30.0, 'kind' => 'metering']], ['period_from' => '2025-01-01', 'period_to' => '2025-12-31']);
        self::assertSame([], $this->check('metering_cap'));
        // Zeitraum über den Beginn (01.07.2025–30.06.2026, 365 Tage): geprüft die 181 Tage ab 2026, Jahresbetrag 30 €
        $this->store->write('strom/bills.json', []);
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 30.0, 'kind' => 'metering']], ['period_from' => '2025-07-01', 'period_to' => '2026-06-30']);
        $f = $this->check('metering_cap');
        self::assertSame([365, 181, 30.0, 5.0], [$f[0]['facts']['days'], $f[0]['facts']['checked_days'], $f[0]['facts']['annual_eur'], $f[0]['amount_eur']]);
        // Teiljahr 01.01.–30.06.2026 (181 Tage): 15 € → 15 ÷ 181 × 365 = 30,25 € im Jahr; 12 € → 24,20 €
        foreach ([15.0 => 30.25, 12.0 => null] as $amount => $year) {
            $this->store->write('strom/bills.json', []);
            $this->meteringBill([['label' => 'MSB', 'amount_eur' => $amount, 'kind' => 'metering']], ['period_to' => '2026-06-30']);
            self::assertSame($year, $this->check('metering_cap')[0]['facts']['annual_eur'] ?? null, (string)$amount);
        }
    }

    public function testMeteringSmartSystemByConsumptionControlAndPlant(): void
    {
        // volle Jahre 2023 bis 2025: 5.000, 7.000, 9.000 kWh → Mittel 7.000 → über 6.000 bis 10.000: 40 € (§ 30 Abs. 1 Nr. 5)
        $this->setMeterDevices('strom', [self::device('d1', '2022-01-01')]);
        $this->setReadings('strom', $this->meterId, [
            ['date' => '2023-01-01', 'counter' => 10000.0, 'device_id' => 'd1'], ['date' => '2024-01-01', 'counter' => 15000.0, 'device_id' => 'd1'],
            ['date' => '2025-01-01', 'counter' => 22000.0, 'device_id' => 'd1'], ['date' => '2026-01-01', 'counter' => 31000.0, 'device_id' => 'd1']]);
        $cid = $this->contract();
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'imsys', 'msb_basic' => true]);
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 45.0, 'kind' => 'metering']]);
        $f = $this->check('metering_cap');
        self::assertCount(1, $f);
        self::assertSame(['imsys_6000', 40.0, 5.0, 'years', 'rights.metering.textImsys'],
            [$f[0]['facts']['case'], $f[0]['facts']['cap_eur'], $f[0]['amount_eur'], $f[0]['facts']['kwh_basis'], $f[0]['text_key']]);
        self::assertEqualsWithDelta(7000, $f[0]['facts']['kwh_year'], 1);
        self::assertSame(['de.msbg.30', 'de.msbg.30.4', 'de.bnetza.msb_costs', 'de.msbg.35.1', 'de.enwg.40.3.4'], $f[0]['rules']);
        // Steuerung nach § 14a EnWG (Netzentgelt-Rabatt am Vertrag): Gruppe Nr. 4, 50 € — 45 € passt
        $this->contracts->update('strom', $cid, ['grid_reduction' => [['from' => '2026-01-01', 'eur_per_year' => 110.0]]]);
        self::assertSame([], $this->check('metering_cap'));
        // ohne Rabatt, aber PV-Anlage 10 kW am Einspeisezähler: über 7 bis 15 kW, ebenfalls 50 €
        $this->contracts->update('strom', $cid, ['grid_reduction' => []]);
        self::assertCount(1, $this->check('metering_cap'));
        $this->meters->update('pv_einspeisung', $this->meters->defaultId('pv_einspeisung'), ['pv_kw' => 10]);
        self::assertSame([], $this->check('metering_cap'));
    }

    public function testMeteringWithoutYearsUsesTheBillAndWithoutAnyQuantityStaysQuiet(): void
    {
        $this->contract();
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'imsys', 'msb_basic' => true]);
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 45.0, 'kind' => 'metering']]);
        self::assertSame([], $this->check('metering_cap'), 'Verbrauch unbekannt: keine Fallgruppe, keine Prüfung');
        $this->store->write('strom/bills.json', []);
        // 3.500 kWh laut Rechnung (365 Tage): optionaler Einbau, 30 € (§ 30 Abs. 3)
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 45.0, 'kind' => 'metering']], ['invoice' => ['energy_kwh' => 3500]]);
        $f = $this->check('metering_cap');
        self::assertSame(['imsys_optional', 30.0, 15.0, 'bill', 3500.0], [$f[0]['facts']['case'], $f[0]['facts']['cap_eur'], $f[0]['amount_eur'],
            $f[0]['facts']['kwh_basis'], (float)$f[0]['facts']['kwh_year']]);
    }

    public function testAnInstallationInThePeriodMayCostUpTo100EurOnce(): void
    {
        $this->setMeterDevices('strom', [self::device('d1', '2024-01-01', '2026-03-01'), self::device('d2', '2026-03-01', null, 0.0)]);
        $this->contract();
        $this->meters->update('strom', $this->meterId, ['metering_device' => 'mme', 'msb_basic' => true]);
        // 120 € im Jahr des Tauschs: 120 − 25 = 95 € darüber, bis 100 € still; 130 € → 105 € → Befund
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 120.0, 'kind' => 'metering']]);
        self::assertSame([], $this->check('metering_cap'));
        $this->store->write('strom/bills.json', []);
        $this->meteringBill([['label' => 'MSB', 'amount_eur' => 130.0, 'kind' => 'metering']]);
        self::assertSame([105.0], array_column($this->check('metering_cap'), 'amount_eur'));
    }

    // ── A7 Frist beim Gebührenbescheid ───────────────────────────────────

    /**
     * Bekanntgabe am vierten Tag nach dem Bescheiddatum, dann ein Monat (§§ 187, 188 BGB); v3.11.0 (Nachprüfung NP1)
     * am Samstag, Sonntag oder bundesweiten Feiertag der nächste Werktag (Prüfbericht 60):
     *   27.01.2026 → 31.01. → 28.02.2026 (Februar ohne 31.: Monatsletzter; Samstag) → Montag 02.03.2026
     *   28.01.2026 → 01.02. → 01.03.2026 (Sonntag) → Montag 02.03.2026
     *   29.12.2026 → 02.01.2027 → 02.02.2027 (Dienstag)
     *   zugestellt am 10.02.2026 → 10.03.2026 (Dienstag)
     *
     * @return array<string, array{0:string, 1:?string, 2:string, 3:string}>
     */
    public static function noticeCases(): array
    {
        return [
            'Monatsende geklemmt' => ['2026-01-27', null, '2026-03-02', 'rights.feeNotice.text'],
            'Monatswechsel'       => ['2026-01-28', null, '2026-03-02', 'rights.feeNotice.text'],
            'Jahreswechsel'       => ['2026-12-29', null, '2027-02-02', 'rights.feeNotice.text'],
            'Zustellungsurkunde'  => ['2026-01-27', '2026-02-10', '2026-03-10', 'rights.feeNotice.textServed'],
        ];
    }

    #[DataProvider('noticeCases')]
    public function testFeeNoticeDeadline(string $dated, ?string $served, string $deadline, string $text): void
    {
        $b = $this->bill(['notice' => ['public_law' => true, 'dated_on' => $dated, 'served_on' => $served, 'remedy' => 'objection', 'file_number' => 'AZ 1']], 'wasser');
        $f = $this->check('fee_notice_deadline', $dated);   // am Tag des Bescheids: die Frist läuft
        self::assertCount(1, $f);
        self::assertSame(['warning', 'wasser', (string)$b['id'], $dated, $deadline, $text, 'objection'],
            [$f[0]['severity'], $f[0]['utility'], $f[0]['bill_id'], $f[0]['date'], $f[0]['deadline'], $f[0]['text_key'], $f[0]['letter']]);
        self::assertSame(Rights::remindOn($deadline), $f[0]['remind_on']);
        self::assertSame([$dated, 'AZ 1', $deadline], [$f[0]['facts']['dated_on'], $f[0]['facts']['file_number'], $f[0]['facts']['deadline_on']]);
        self::assertSame('Wasser: Gebührenbescheid vom ' . $this->i18n->date($dated) . ', Rechtsbehelf bis ' . $this->i18n->date($deadline), $f[0]['title']);
        self::assertSame(['de.vwgo.70.1', 'de.vwgo.68.1', 'de.vwvfg.41.2', 'de.ao.122.2', 'de.bgb.188', 'de.vwgo.57.2', 'de.vwvfg.31.3', 'de.ao.108.3', 'de.vwgo.80'], $f[0]['rules']);
        self::assertStringContainsString('Der Rechtsbehelf schiebt die Zahlung nicht auf.', $f[0]['text']);
    }

    public function testFeeNoticeLetterOnlyForAnObjectionAndOnlyWhileTheDeadlineRuns(): void
    {
        foreach (['objection' => 'objection', 'action' => null, '' => null] as $remedy => $letter) {
            $this->store->write('wasser/bills.json', []);
            $this->bill(['notice' => ['public_law' => true, 'dated_on' => '2026-01-27', 'remedy' => $remedy]], 'wasser');
            self::assertSame($letter, $this->check('fee_notice_deadline', '2026-02-10')[0]['letter'], "remedy $remedy");
        }
        // Frist rechnerisch Samstag 28.02.2026, verschoben auf Montag 02.03.: bis dahin „Zu tun“, danach nicht mehr
        self::assertTrue($this->rights->findings('2026-03-01')[0]['todo']);
        self::assertTrue($this->rights->findings('2026-03-02')[0]['todo']);
        self::assertFalse($this->rights->findings('2026-03-03')[0]['todo']);
        // privatrechtliches Entgelt (kein Bescheid), auch mit Datum: keine Frist
        $this->store->write('wasser/bills.json', []);
        $this->bill(['notice' => ['public_law' => false, 'dated_on' => '2026-01-27']], 'wasser');
        self::assertSame([], $this->check('fee_notice_deadline'));
    }

    /**
     * v3.11.0 (Skeptiker H2) — nach dem Fristende kein „ist ein Rechtsbehelf möglich“ und kein Widerspruch:
     * Bescheid vom 27.01.2026, bekannt 31.01., rechnerisch Samstag 28.02.2026, Frist bis Montag 02.03.2026 (NP1).
     *   27.02. bis 02.03.: läuft (Text wie bisher, Brief)
     *   03.03.: abgelaufen, mit dem Jahr bei fehlender oder unrichtiger Belehrung (Prüfbericht 48)
     *   31.01.2027 (Bekanntgabe + 12 Monate): noch mit dem Jahr; 01.02.2027: ohne
     */
    public function testFeeNoticeAfterTheDeadlineSaysSoAndOffersNoLetter(): void
    {
        $this->bill(['notice' => ['public_law' => true, 'dated_on' => '2026-01-27', 'remedy' => 'objection']], 'wasser');
        foreach (['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02'] as $day) {
            $f = $this->check('fee_notice_deadline', $day)[0];
            self::assertSame(['warning', 'rights.feeNotice.text', 'objection', false], [$f['severity'], $f['text_key'], $f['letter'], $f['facts']['expired']], $day);
        }
        $f = $this->check('fee_notice_deadline', '2026-03-03')[0];
        self::assertSame(['info', 'rights.feeNotice.titleExpired', 'rights.feeNotice.textExpired', null, true, false],
            [$f['severity'], $f['title_key'], $f['text_key'], $f['letter'], $f['facts']['expired'], $f['todo']]);
        self::assertSame(['de.vwgo.70.1', 'de.vwgo.58.2', 'de.vwvfg.41.2', 'de.ao.122.2', 'de.bgb.188', 'de.vwgo.57.2', 'de.vwvfg.31.3', 'de.ao.108.3'], $f['rules']);
        self::assertSame('Wasser: Gebührenbescheid vom 27.01.2026, Frist am 02.03.2026 abgelaufen', $f['title']);
        self::assertStringContainsString('am 02.03.2026 abgelaufen', $f['text']);
        self::assertStringContainsString('Feiertag nur in deinem Bundesland', $f['text']);
        self::assertStringContainsString('gilt eine Frist von einem Jahr', $f['text']);
        self::assertStringNotContainsString('ist ein Rechtsbehelf möglich', $f['text']);
        self::assertSame('rights.feeNotice.textExpired', $this->check('fee_notice_deadline', '2027-01-31')[0]['text_key']);
        $late = $this->check('fee_notice_deadline', '2027-02-01')[0];
        self::assertSame('rights.feeNotice.textExpiredLong', $late['text_key']);
        self::assertStringNotContainsString('einem Jahr', $late['text']);
    }

    /**
     * v3.11.0 (Nachprüfung NP1) — Fristende am Samstag, Sonntag, Ostermontag oder 3. Oktober: Die Frist endet am
     * nächsten Werktag (§ 57 Abs. 2 VwGO, § 222 Abs. 2 ZPO; § 31 Abs. 3 VwVfG; Prüfbericht 60, 61). Zustellung
     * mit Urkunde, von Hand gezählt:
     *   zugestellt 28.01.2026 → Samstag 28.02.2026 → Montag 02.03.
     *   zugestellt 01.10.2026 → Sonntag 01.11.2026 → Montag 02.11. (der Fall der Nachprüfung)
     *   zugestellt 06.03.2026 → Ostermontag 06.04.2026 → Dienstag 07.04.
     *   zugestellt 03.09.2025 → Freitag 03.10.2025 (Tag der Deutschen Einheit), Sa, So → Montag 06.10.
     * Am rechnerischen Ende und bis zum verschobenen läuft die Frist (Warnung, Brief, Termin = verschobenes Ende),
     * am Tag danach ist sie abgelaufen (kein Brief, der Server lehnt ab).
     *
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function shiftedEnds(): array
    {
        return [
            'Samstag'     => ['2026-01-28', '2026-02-28', '2026-03-02'],
            'Sonntag'     => ['2026-10-01', '2026-11-01', '2026-11-02'],
            'Ostermontag' => ['2026-03-06', '2026-04-06', '2026-04-07'],
            '3. Oktober'  => ['2025-09-03', '2025-10-03', '2025-10-06'],
        ];
    }

    #[DataProvider('shiftedEnds')]
    public function testAnEndOnAWeekendOrHolidayMovesToTheNextWorkingDay(string $served, string $nominal, string $deadline): void
    {
        $dated = date('Y-m-d', (int)strtotime($served . ' -3 days'));
        $this->bill(['notice' => ['public_law' => true, 'dated_on' => $dated, 'served_on' => $served, 'remedy' => 'objection']], 'wasser');
        $after = date('Y-m-d', (int)strtotime($nominal . ' +1 day'));
        foreach (array_unique([$nominal, $after, $deadline]) as $day) {
            $f = $this->check('fee_notice_deadline', $day)[0];
            self::assertSame(['warning', 'objection', false, $deadline, $nominal, true], [$f['severity'], $f['letter'], $f['facts']['expired'],
                $f['deadline'], $f['facts']['nominal_end'], $f['facts']['deadline_shifted']], $day);
            self::assertSame(Rights::remindOn($deadline), $f['remind_on'], $day);
            self::assertStringContainsString('endet am ' . $this->i18n->date($deadline), $f['text'], $day);
            self::assertStringContainsString('nur in deinem Bundesland', $f['text'], $day);
        }
        $next = date('Y-m-d', (int)strtotime($deadline . ' +1 day'));
        $f = $this->check('fee_notice_deadline', $next)[0];
        self::assertSame(['info', null, true], [$f['severity'], $f['letter'], $f['facts']['expired']], $next);
        self::assertStringContainsString('Frist am ' . $this->i18n->date($deadline) . ' abgelaufen', $f['title']);
    }

    /** Ein Werktag als rechnerisches Ende bleibt (keine Verschiebung, `deadline_shifted` false). */
    public function testAWorkingDayEndStays(): void
    {
        $this->bill(['notice' => ['public_law' => true, 'dated_on' => '2026-02-07', 'served_on' => '2026-02-10', 'remedy' => 'objection']], 'wasser');
        $f = $this->check('fee_notice_deadline', '2026-03-10')[0];
        self::assertSame(['2026-03-10', '2026-03-10', false, false], [$f['deadline'], $f['facts']['nominal_end'], $f['facts']['deadline_shifted'], $f['facts']['expired']]);
        self::assertTrue($this->check('fee_notice_deadline', '2026-03-11')[0]['facts']['expired']);
    }

    // ── Rechnung prüfen: Gutschrift und Bonus des Vertrags nicht doppelt ─

    public function testCompareDoesNotCountACreditTwiceWhenTheContractCarriesTheBonus(): void
    {
        $this->setReadings('strom', $this->meterId, [['date' => '2025-01-01', 'counter' => 12000.0, 'device_id' => 'd1'],
            ['date' => '2026-01-01', 'counter' => 14000.0, 'device_id' => 'd1']]);
        // 2.000 kWh × 0,30 + 12 × 10 = 720 €, Bonus des Vertrags (datiert) 50 € → 670 €
        $this->contract(['bonuses' => [['credit_date' => '2025-06-01', 'amount_eur' => 50, 'type' => 'wechsel']]]);
        $b = $this->bill(['invoice' => ['energy_kwh' => 2000, 'amount_eur' => 670],
            'items' => [['label' => 'Wechselbonus', 'amount_eur' => -50, 'kind' => 'credit'], ['label' => 'Umlage', 'amount_eur' => 5, 'kind' => 'levy']]]);
        $c = $this->bills->compare('strom', (string)$b['id']);
        // die Gutschrift steckt schon im Bonus: nur die Umlage kommt dazu → 675 €
        self::assertSame([50.0, 5.0, 675.0], [$c['ours']['bonus'], $c['ours']['items_total'], $c['ours']['total']]);
    }

    // ── unordentlicher Testhaushalt und Beispielhaushalte ────────────────

    /** Haushalt laden wie ReadingCheckTest: Fixture-Datei oder Verzeichnis demo-data. */
    private function loadHousehold(string $src): void
    {
        $pots = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups', 'periods', 'bills'];
        foreach (Utilities::keys() as $u) foreach ($pots as $p) $this->store->write("$u/$p.json", []);
        if (is_dir($src)) {
            $this->store->write('settings.json', json_decode((string)file_get_contents("$src/settings.json"), true));
            foreach (Utilities::keys() as $u) foreach ($pots as $p) {
                if (is_file("$src/$u/$p.json")) $this->store->write("$u/$p.json", json_decode((string)file_get_contents("$src/$u/$p.json"), true));
            }
            return;
        }
        $h = json_decode((string)file_get_contents($src), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings'] ?? []);
        $this->store->write('temperatures.json', $h['temperatures'] ?? []);
        foreach ((array)($h['utilities'] ?? []) as $u => $byPot) foreach ((array)$byPot as $p => $rows) {
            if (in_array($p, $pots, true)) $this->store->write("$u/$p.json", $rows);
        }
    }

    /**
     * Der unordentliche Haushalt (eigene Physik, Strom nur einmal im Jahr abgelesen, Gaszähler
     * getauscht): ehrliche, pünktliche Rechnungen melden nichts. Schätzt die Stromrechnung 2025
     * den Endstand, rechnet die Prüfung mit den echten Ablesungen des Haushalts:
     *   10.01.2025: 26.845,5 und 12.01.2026: 29.310,2 (367 Tage) = 6,71580 kWh/Tag
     *   Fortschreibung auf den 31.12.2025 (355 Tage): 26.845,5 + 2.384,11 = 29.229,61
     *   2 % von 6,71580 × 365 = 49,03 kWh
     *   geschätzt 29.300 → + 70,39 kWh → Befund, × 0,34 €/kWh = 23,93 €; geschätzt 29.260 → + 30,39 → nicht
     */
    public function testTheMessyHouseholdHasNoFalseAlarmsAndOneRealEstimate(): void
    {
        $this->loadHousehold(self::MESSY . 'household.json');
        $this->bills->create('strom', ['meter_id' => 'm_strom_messy', 'contract_id' => 'c_strom_messy', 'period_from' => '2025-01-01',
            'period_to' => '2025-12-31', 'issued_on' => '2026-01-28', 'invoice' => ['energy_kwh' => 2451, 'amount_eur' => 965.34]]);
        $this->bills->create('gas', ['meter_id' => 'm_gas_messy', 'contract_id' => 'c_gas_messy', 'period_from' => '2024-11-01',
            'period_to' => '2025-10-31', 'issued_on' => '2025-12-01', 'invoice' => ['energy_kwh' => 13000]]);
        self::assertSame([], $this->rights->findings('2026-02-01'), json_encode($this->rights->failed()));
        self::assertSame([], $this->rights->failed());

        foreach ([29260.0 => null, 29300.0 => [70.4, 23.93]] as $counter => $expect) {
            $rows = array_values(array_filter($this->readings->list('strom', 'm_strom_messy'), fn($r) => empty($r['is_estimated'])));
            $rows[] = ['id' => 'r_est', 'meter_id' => 'm_strom_messy', 'device_id' => 'd_strom', 'date' => '2025-12-31', 'counter' => $counter,
                       'is_estimated' => true, 'is_future' => false, 'note' => '', 'price_cents' => null];
            $this->store->write('strom/readings.json', $rows);
            $bill = $this->bills->list('strom')[0];
            $this->bills->update('strom', (string)$bill['id'], ['reading_estimated' => ['start' => false, 'end' => true]]);
            $f = $this->rights->findings('2026-02-01');
            if ($expect === null) { self::assertSame([], $f, (string)$counter); continue; }
            self::assertSame(['estimate_despite_reading'], array_column($f, 'check'));
            self::assertSame([29229.6, '2026-01-12', $expect[0]], [$f[0]['facts']['own_projected'], $f[0]['facts']['own_reading_on'], $f[0]['facts']['delta_counter']]);
            self::assertEqualsWithDelta($expect[1], $f[0]['amount_eur'], 0.05);
            self::assertTrue($f[0]['todo']);
        }
    }

    /** Die Beispielhaushalte (Personas, Schaufenster): keine Prüfung scheitert an ihren Daten. */
    public function testTheExampleHouseholdsRunWithoutFailures(): void
    {
        $sources = ['demo' => self::ROOT . 'demo-data'];
        foreach (glob(self::ROOT . 'demo-data/personas/*.json') ?: [] as $f) $sources[basename($f, '.json')] = $f;
        foreach ($sources as $name => $src) {
            $this->loadHousehold($src);
            $this->settings->set(['country' => 'DE']);
            foreach (RightsService::CHECKS as $check => $_) {
                if (!in_array($check, $this->rights->registered(), true)) continue;
                $this->rights->run($check, self::TODAY);   // wirft bei einem Formfehler
            }
            $this->rights->findings(self::TODAY);
            self::assertSame([], $this->rights->failed(), $name);
        }
    }
}
