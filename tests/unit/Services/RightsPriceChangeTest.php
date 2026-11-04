<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\RightsService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (F1035, Paket vertrag) — A3 „Preiserhöhung mit Sonderkündigungsrecht und Frist“ und die
 * Befunde im Bestand B1 bis B4 (GitHub #55, Konzept §3 und §8). Alle Daten erfunden, Stichtag fest.
 *
 * Handrechnung der Termine: Kündigung muss vor dem Wirksamwerden zugehen → Termin = Tag davor
 * (01.07. → 30.06., 01.03. → 28.02.), unter „Zu tun“ 14 Tage vorher. Ankündigung im Sondervertrag
 * spätestens einen Monat vorher (01.07. → 01.06.; Schreiben vom 06.06. = 25 Tage: zu spät), in der
 * Grundversorgung sechs Wochen und zum Monatsbeginn (15.08.: kein Monatserster).
 */
#[CoversClass(ContractChecks::class)]
#[CoversClass(ContractService::class)]
final class RightsPriceChangeTest extends ServiceTestCase
{
    private const TODAY = '2026-06-10';
    private RightsService $rights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['country' => 'DE']);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $checks = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills), $this->i18n);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $this->rights->register('special_termination', fn(string $today, string $country): array => $checks->specialTermination($today, $country));
    }

    private function contract(string $u, array $over): array
    {
        return $this->contracts->create($u, $over + ['meter_id' => $this->meters->defaultId($u), 'provider' => 'Stadtwerke Musterstadt',
            'start' => '2026-01-01']);
    }

    /** @return list<array<string,mixed>> */
    private function findings(string $today = self::TODAY): array
    {
        return $this->rights->run('special_termination', $today);
    }

    public function testSpecialContractAnnouncedTooLate(): void
    {
        $c = $this->contract('strom', ['supply_kind' => 'special', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 36.0, 'announced_on' => '2026-06-06']]]);
        $f = $this->findings();
        self::assertCount(1, $f);
        $f = $f[0];
        self::assertSame(['warning', '2026-07-01', '2026-06-30', '2026-06-16', 'special_termination', $c['id']],
            [$f['severity'], $f['date'], $f['deadline'], $f['remind_on'], $f['letter'], $f['contract_id']]);
        self::assertSame(['de.enwg.41.5.s4', 'de.enwg.41.5.s1', 'de.vz.special_termination'], $f['rules']);
        self::assertSame('Strom: Sonderkündigung zum 01.07.2026 möglich', $f['title']);
        self::assertStringContainsString('Arbeitspreis 30,00 → 36,00 ct/kWh', $f['text']);
        self::assertStringContainsString('(§ 41 Abs. 5 EnWG)', $f['text']);
        self::assertStringContainsString('Das Schreiben ist vom 06.06.2026', $f['text']);
        self::assertStringContainsString('mindestens einen Monat', $f['text']);
        self::assertStringNotContainsString('GVV', $f['text']);
        self::assertTrue($f['facts']['announced_late']);
        self::assertSame(25, $f['facts']['days_before']);
    }

    public function testBasicSupplyOnTheFifteenth(): void
    {
        // Gas, Grundversorgung: Grundpreis 10 → 12 € zum 15.08., Schreiben 75 Tage vorher (sechs Wochen gewahrt)
        $this->contract('gas', ['supply_kind' => 'basic', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0], ['from' => '2026-08-15', 'eur_per_month' => 12.0, 'announced_on' => '2026-06-01']]]);
        $f = $this->findings()[0];
        self::assertSame('2026-08-14', $f['deadline']);
        self::assertSame(['de.gasgvv.5.3', 'de.gasgvv.20', 'de.gasgvv.5.2', 'de.vz.special_termination'], $f['rules']);
        self::assertStringContainsString('(§ 5 Abs. 3 GasGVV)', $f['text']);
        self::assertStringContainsString('zwei Wochen Frist (§ 20 GasGVV)', $f['text']);
        self::assertStringContainsString('zum Monatsbeginn', $f['text']);
        self::assertStringNotContainsString('sechs Wochen Vorlauf', $f['text'], 'sechs Wochen sind gewahrt');
        self::assertStringNotContainsString('§ 41', $f['text'], 'B3: Grundversorgung nicht mit § 41 EnWG begründen');
        self::assertStringContainsString('Grundpreis 10,00 € → 12,00 € im Monat', $f['text']);
    }

    public function testUnknownSupplyKindNamesBoth(): void
    {
        $this->contract('strom', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 33.0, 'announced_on' => '2026-05-15']]]);
        $f = $this->findings()[0];
        self::assertSame(['de.enwg.41.5.s4', 'de.stromgvv.5.3', 'de.enwg.40.2.13', 'de.vz.special_termination'], $f['rules']);
        self::assertStringContainsString('§ 41 Abs. 5 EnWG', $f['text']);
        self::assertStringContainsString('§ 5 Abs. 3 StromGVV', $f['text']);
        self::assertStringContainsString('steht auf der Rechnung', $f['text']);
        self::assertFalse($f['facts']['announced_late'], '47 Tage vorher: auch die strengere Frist gewahrt');
    }

    public function testPureVatChangeGivesNoTerminationRight(): void
    {
        // 28,00 ct mit 7 % = 26,168 netto; 31,14 ct mit 19 % = 26,168 netto: nur die Steuer ändert sich (§ 41 Abs. 6 EnWG)
        $c = $this->contract('strom', ['supply_kind' => 'special', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0, 'vat_pct' => 7],
            ['from' => '2026-07-01', 'ct_per_kwh' => 31.14, 'vat_pct' => 19, 'announced_on' => '2026-05-01']]]);
        self::assertSame([], $this->findings());
        $pi = $this->contracts->priceIncrease($c, self::TODAY, 'strom', 'DE');
        self::assertSame([true, null, null, ['vat']], [$pi['vat_only'], $pi['termination'], $pi['deadline'], $pi['notes']]);

        // der alte Satz kommt nur vom Eintrag, nie vermutet: ohne `vat_pct` am alten Preis eine Erhöhung
        $pi = $this->contracts->priceIncrease(['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 31.14, 'vat_pct' => 19]]], self::TODAY, 'strom', 'DE');
        self::assertSame([false, 'unknown'], [$pi['vat_only'], $pi['termination']]);

        // netto ebenfalls höher (31,50 ct = 26,47 netto): eine echte Erhöhung
        $this->contracts->update('strom', $c['id'], ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0, 'vat_pct' => 7],
            ['from' => '2026-07-01', 'ct_per_kwh' => 31.5, 'vat_pct' => 19, 'announced_on' => '2026-05-01']]]);
        self::assertCount(1, $this->findings());
    }

    public function testPriceStepWithoutLetterIsConditional(): void
    {
        $this->contract('strom', ['supply_kind' => 'special', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 32.0]]]);
        $f = $this->findings()[0];
        self::assertSame('info', $f['severity'], 'B4: ohne Datum des Schreibens kann es ein vereinbarter Stufenpreis sein');
        self::assertSame('rights.special_termination.titleMaybe', $f['title_key']);
        self::assertStringContainsString('Stufenpreis', $f['text']);
        self::assertSame('2026-06-30', $f['deadline'], 'der Termin bleibt: verpasst wäre das Recht weg');
    }

    public function testDistrictHeatingNeverSpecialTermination(): void
    {
        // B1: Fernwärme ist keine Energie im Sinne des EnWG (§ 3 Nr. 31); § 24 AVBFernwärmeV kennt kein allgemeines Recht
        $c = $this->contract('fernwaerme', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 12.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 14.0, 'announced_on' => '2026-05-01']]]);
        self::assertSame([], $this->findings());
        $pi = $this->contracts->priceIncrease($c, self::TODAY, 'fernwaerme', 'DE');
        self::assertSame([null, ['heat']], [$pi['termination'], $pi['notes']]);
        $note = implode(' ', array_map(fn($p) => $this->i18n->t($p[0], $p[1]), ContractChecks::noteParts($pi, 'fernwaerme')));
        self::assertStringContainsString('§ 24 Abs. 4 AVBFernwärmeV', $note);
        self::assertStringNotContainsString('§ 41', $note);

        // Vertragsstatus (Saldo-Karte) trägt dieselbe Aussage; die Empfehlung ohne Rechte-Dienst schweigt
        $status = $this->consumption->contractStatus('fernwaerme', $this->meters->get('fernwaerme', $this->meters->defaultId('fernwaerme')), self::TODAY);
        self::assertSame(['heat'], $status['contracts'][0]['price_increase']['notes']);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        self::assertSame([], array_values(array_filter($recs->all(), fn($r) => str_starts_with($r['id'], 'r_price_increase_'))),
            'B1: kein „Sonderkündigung prüfen“ bei Fernwärme');
    }

    public function testRightsFindingReplacesTheOldRecommendation(): void
    {
        $this->contract('strom', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => date('Y-m-d', strtotime('+40 days')), 'ct_per_kwh' => 33.0, 'announced_on' => date('Y-m-d', strtotime('-10 days'))]]]);
        // B1: dieselbe Erhöhung bei Fernwärme bringt auch ohne Rechte-Dienst keine Empfehlung „Sonderkündigung prüfen“
        $this->settings->set(['active_utilities' => ['gas', 'strom', 'fernwaerme']]);
        $this->contract('fernwaerme', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 12.0],
            ['from' => date('Y-m-d', strtotime('+40 days')), 'ct_per_kwh' => 14.0, 'announced_on' => date('Y-m-d', strtotime('-10 days'))]]]);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $old = fn() => array_values(array_filter($recs->all(), fn($r) => str_starts_with($r['id'], 'r_price_increase_')));
        self::assertCount(1, $old(), 'ohne Rechte-Dienst wie bisher, nur Strom');
        self::assertSame('strom', $old()[0]['evidence']['utility']);
        $recs->attachRights($this->rights);
        self::assertSame([], $old(), 'mit A3: der Befund (Kategorie recht) statt „Sonderkündigung prüfen“');
        self::assertCount(1, array_filter($recs->all(), fn($r) => ($r['category'] ?? '') === 'recht' && ($r['evidence']['check'] ?? '') === 'special_termination'));
    }

    public function testDeadlineAtMonthEndAndEarlierDateFromTheLetter(): void
    {
        // Erhöhung zum 01.03.: Termin 28.02. (Lektion 26); das Schreiben verlangt die Kündigung bis 20.02. → früheres Datum (7c)
        $this->contract('gas', ['supply_kind' => 'special', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0],
            ['from' => '2026-03-01', 'ct_per_kwh' => 12.5, 'announced_on' => '2026-01-20', 'special_termination_until' => '2026-02-20']]]);
        $f = $this->findings('2026-02-05')[0];
        self::assertSame(['2026-02-20', '2026-02-28', '2026-02-20'], [$f['deadline'], $f['facts']['legal_deadline'], $f['facts']['special_termination_until']]);
        self::assertStringContainsString('Laut Schreiben soll die Kündigung bis zum 20.02.2026 eingehen; nach dem Gesetz reicht der Zugang bis zum 28.02.2026', $f['text']);
        self::assertFalse($f['facts']['announced_late'], '20.01. liegt vor dem 01.02. (ein Monat vor dem 01.03.)');
        // v3.11.0 (integration) — ist der Tag laut Schreiben vorbei, gilt wieder der gesetzliche (Prüfbericht 21:
        // der Anbieter darf keine eigene Frist setzen), wie im Posteingang (Inbox\PriceChange::assess)
        $f = $this->findings('2026-02-21')[0];
        self::assertSame('2026-02-28', $f['deadline']);
        self::assertStringNotContainsString('Laut Schreiben', $f['text']);

        // ein späteres Datum im Schreiben ändert den Termin nicht
        $c = $this->contracts->list('gas')[0];
        $this->contracts->update('gas', $c['id'], ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0],
            ['from' => '2026-03-01', 'ct_per_kwh' => 12.5, 'announced_on' => '2026-01-20', 'special_termination_until' => '2026-03-15']]]);
        $f = $this->findings('2026-02-05')[0];
        self::assertSame('2026-02-28', $f['deadline']);
        self::assertStringNotContainsString('Laut Schreiben', $f['text']);
    }

    public function testPriceGuaranteeIsMentioned(): void
    {
        $this->contract('strom', ['supply_kind' => 'special', 'price_guarantee_until' => '2026-12-31',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-07-01', 'ct_per_kwh' => 31.0, 'announced_on' => '2026-05-01']]]);
        self::assertStringContainsString('Preisgarantie bis 31.12.2026', $this->findings()[0]['text']);
    }

    public function testSuccessorFromTheDayOfTheChangeAnswersTheQuestion(): void
    {
        $this->contract('strom', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 36.0, 'announced_on' => '2026-05-01']]]);
        self::assertCount(1, $this->findings());
        $this->contract('strom', ['provider' => 'Neuer Versorger', 'start' => '2026-07-01', 'working_prices' => [['from' => '2026-07-01', 'ct_per_kwh' => 29.0]]]);
        self::assertSame([], $this->findings(), 'am Tag der Änderung gilt schon der Folgevertrag');
    }

    public function testOnlyGermanyAndOnlyEnergy(): void
    {
        $c = $this->contract('strom', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 36.0, 'announced_on' => '2026-05-01']]]);
        $this->settings->set(['country' => 'AT']);
        self::assertSame([], $this->findings());
        $pi = $this->contracts->priceIncrease($c, self::TODAY, 'strom', 'AT');
        self::assertSame([null, [], null], [$pi['termination'], $pi['notes'], $pi['deadline']], 'außerhalb Deutschlands kein Rechtshinweis');
        $heat = ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 12.0], ['from' => '2026-07-01', 'ct_per_kwh' => 14.0]]];
        self::assertSame([], $this->contracts->priceIncrease($heat, self::TODAY, 'fernwaerme', 'AT')['notes'], 'auch der Fernwärme-Satz nur in DE');
        self::assertSame(['heat'], $this->contracts->priceIncrease($heat, self::TODAY, 'fernwaerme', 'DE')['notes']);
        // eine Senkung ist keine Erhöhung
        self::assertNull($this->contracts->priceIncrease(['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0],
            ['from' => '2026-07-01', 'ct_per_kwh' => 29.0]]], self::TODAY, 'strom', 'DE'));
    }

    public function testTermWarningByUtility(): void
    {
        // B2: 24 Monate nur bei Strom und Gas; Fernwärme bis zehn Jahre (§ 32 AVBFernwärmeV); Wasser nie
        $long = ['start' => '2026-01-01', 'min_term_end' => '2028-06-30'];
        self::assertSame(['term_over_24_months'], ContractService::warnings($long, 'strom')['warnings']);
        self::assertSame(['term_over_24_months'], ContractService::warnings($long, 'gas')['warnings']);
        self::assertSame([], ContractService::warnings($long, 'fernwaerme'));
        self::assertSame([], ContractService::warnings($long, 'wasser'));
        self::assertSame([], ContractService::warnings(['start' => '2026-01-01', 'min_term_end' => '2027-12-31'], 'strom'), 'genau 24 Monate');
        self::assertSame([], ContractService::warnings(['start' => '2026-01-01', 'min_term_end' => '2035-12-31'], 'fernwaerme'), 'genau zehn Jahre');
        self::assertSame(['term_over_10_years'], ContractService::warnings(['start' => '2026-01-01', 'min_term_end' => '2036-01-01'], 'fernwaerme')['warnings']);
        $saved = $this->contract('fernwaerme', ['min_term_end' => '2036-06-30', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 12.0]]]);
        self::assertSame(['term_over_10_years'], $saved['warnings'], 'der Speichern-Weg nennt die Art');
        self::assertStringContainsString('§ 32 AVBFernwärmeV', $this->i18n->t('contracts.hint.termOver10y'));
    }

    public function testLetterDateOnThePriceIsKeptAndChecked(): void
    {
        $c = $this->contract('gas', ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0],
            ['from' => '2026-09-01', 'ct_per_kwh' => 12.0, 'announced_on' => '2026-06-20', 'special_termination_until' => '2026-08-15']]]);
        self::assertSame('2026-08-15', $this->contracts->get('gas', $c['id'])['working_prices'][1]['special_termination_until']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('15.08.2026');
        $this->contracts->update('gas', $c['id'], ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0],
            ['from' => '2026-09-01', 'ct_per_kwh' => 12.0, 'special_termination_until' => '15.08.2026']]]);
    }
}
