<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Services\RightsService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\PriceChange;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (F1035, Paket posteingang, Konzept §5 „C“) — Preisänderungsschreiben im Posteingang:
 * Herkunft am Preis (`announced_on`, `vat_pct`), Termin der Sonderkündigung (Entscheidung 7c),
 * Ankündigungsfrist, reine Umsatzsteueränderung, Zweitarif (HT/NT), Abschlag im Schreiben,
 * Wirkung als Vorschau. Erfundene Schreiben (Musterstadt), Fristen von Hand gerechnet, feste Tage.
 */
#[CoversClass(PriceChange::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(Gist::class)]
final class InboxPriceChangeTest extends ServiceTestCase
{
    private const PROVIDER = 'Stadtwerke Musterstadt';

    // ── Bewertung (rein, von Hand gerechnet) ─────────────────────────────

    /** @param list<string> $flagged */
    private static function letter(array $values, array $flagged = []): array
    {
        $fields = [];
        foreach ($values as $k => $v) $fields[$k] = ['value' => $v, 'status' => in_array($k, $flagged, true) ? 'check' : 'ok'];
        return ['type' => 'price_change', 'extraction' => ['fields' => $fields]];
    }

    private static function contract(array $extra = []): array
    {
        return $extra + ['id' => 'c_test', 'provider' => self::PROVIDER, 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5]]];
    }

    private function assess(array $letter, ?array $ct = null, string $today = '2026-12-08', string $u = 'gas', string $country = 'DE'): array
    {
        $a = PriceChange::assess(self::letter($letter), $u, $ct ?? self::contract(), null, $country, $this->contracts, $today);
        self::assertNotNull($a);
        return $a;
    }

    /** @return list<string> */
    private static function keys(array $a, ?array $advance = null): array
    {
        return array_map(fn($l) => substr($l['key'], strlen('inbox.gist.rights.')), PriceChange::lines($a, $advance));
    }

    public function testSpecialContractAnnouncedTwentyFiveDaysAheadIsLateAndTheTerminIsTheDayBefore(): void
    {
        // 07.12.2026 → 01.01.2027: 24 Tage im Dezember + 1 = 25; ein Monat vorher wäre der 01.12.2026
        $a = $this->assess(['valid_from' => '2027-01-01', 'document_date' => '2026-12-07', 'working_price_new' => 12.8],
            self::contract(['supply_kind' => 'special']));
        self::assertSame('up', $a['direction']);
        self::assertSame('2026-12-31', $a['legal_deadline']);
        self::assertSame('2026-12-31', $a['termin']);
        self::assertSame(['days_before' => 25, 'announce_by' => '2026-12-01', 'late' => true, 'required' => 'month', 'month_start' => null], $a['announcement']);
        $lines = PriceChange::lines($a);
        self::assertSame(['terminationSpecial', 'lateSpecial'], self::keys($a));
        self::assertSame(['date' => '2027-01-01', 'deadline' => '2026-12-31'], $lines[0]['params']);
        self::assertSame(['days' => 25], $lines[1]['params']);
        $norms = array_column(PriceChange::norms($lines, 'DE'), 'norm');
        self::assertSame(['§ 41 Abs. 5 Satz 4 EnWG', Rights::RULES['DE']['de.vz.special_termination']['norm'], '§ 41 Abs. 5 Satz 1 bis 3 EnWG'], $norms);
        foreach (PriceChange::norms($lines, 'DE') as $n) self::assertStringStartsWith('https://', $n['url']);
    }

    public function testExactlyOneMonthAheadIsInTimeAlsoAtTheEndOfAMonth(): void
    {
        $ct = self::contract(['supply_kind' => 'special']);
        self::assertFalse($this->assess(['valid_from' => '2027-01-01', 'document_date' => '2026-12-01', 'working_price_new' => 12.8], $ct)['announcement']['late']);
        // Lektion 26: 31.03. − 1 Monat = 28.02. (nicht 03.03.); Termin 30.03.
        $in = $this->assess(['valid_from' => '2027-03-31', 'document_date' => '2027-02-28', 'working_price_new' => 12.8], $ct, '2027-03-01');
        self::assertSame(['2027-02-28', false, '2027-03-30'], [$in['announcement']['announce_by'], $in['announcement']['late'], $in['legal_deadline']]);
        self::assertTrue($this->assess(['valid_from' => '2027-03-31', 'document_date' => '2027-03-01', 'working_price_new' => 12.8], $ct, '2027-03-01')['announcement']['late']);
    }

    public function testBasicSupplyOnTheFifteenthNeedsTheStartOfAMonthAndSixWeeks(): void
    {
        // Grundversorgung laut Schreiben; 15.01.2027 − 42 Tage = 04.12.2026
        $a = $this->assess(['valid_from' => '2027-01-15', 'document_date' => '2026-11-20', 'working_price_new' => 12.8, 'basic_supply' => true], null, '2026-11-25');
        self::assertSame('basic', $a['kind']);
        self::assertSame(['2026-12-04', false, false], [$a['announcement']['announce_by'], $a['announcement']['late'], $a['announcement']['month_start']]);
        self::assertSame('2027-01-14', $a['legal_deadline']);
        self::assertSame(['terminationBasic', 'monthStart'], self::keys($a));
        self::assertSame(['de.gasgvv.5.3', 'de.gasgvv.20', 'de.vz.special_termination'], PriceChange::lines($a)[0]['rules']);
        // 10.12.2026 → 15.01.2027: 21 + 15 = 36 Tage, zu spät
        $late = $this->assess(['valid_from' => '2027-01-15', 'document_date' => '2026-12-10', 'working_price_new' => 12.8, 'basic_supply' => true], null, '2026-12-11');
        self::assertSame(['terminationBasic', 'lateBasic', 'monthStart'], self::keys($late));
        self::assertSame(['days' => 36], PriceChange::lines($late)[1]['params']);
        // Strom: StromGVV
        $strom = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 32.0, 'basic_supply' => true], self::contract(['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]), '2026-12-01', 'strom');
        self::assertSame(['de.stromgvv.5.3', 'de.stromgvv.20', 'de.vz.special_termination'], PriceChange::lines($strom)[0]['rules']);
    }

    public function testUnknownKindNamesBothRulesAndOnlyTheMilderDeadline(): void
    {
        // 25.11.2026 → 01.01.2027: 37 Tage — der Monat (01.12.) ist eingehalten, die sechs Wochen (20.11.) nicht
        $a = $this->assess(['valid_from' => '2027-01-01', 'document_date' => '2026-11-25', 'working_price_new' => 12.8], null, '2026-11-26');
        self::assertNull($a['kind']);
        self::assertSame([37, false], [$a['announcement']['days_before'], $a['announcement']['late']], 'unbekannt: nur die mildere Frist (ein Monat)');
        self::assertSame(['terminationUnknown'], self::keys($a));
        self::assertSame(['de.enwg.41.5.s4', 'de.gasgvv.5.3', 'de.gasgvv.20', 'de.enwg.40.2.13', 'de.vz.special_termination'], PriceChange::lines($a)[0]['rules']);
    }

    public function testTheContractKindWinsAndFlaggedLetterFieldsAreNotTrusted(): void
    {
        self::assertSame('special', $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8, 'basic_supply' => true],
            self::contract(['supply_kind' => 'special']))['kind']);
        $a = PriceChange::assess(self::letter(['valid_from' => '2027-01-01', 'document_date' => '2026-12-07', 'working_price_new' => 12.8, 'basic_supply' => true],
            ['document_date', 'basic_supply']), 'gas', self::contract(), null, 'DE', $this->contracts, '2026-12-08');
        self::assertNull($a['announced_on']);
        self::assertNull($a['announcement']);
        self::assertNull($a['kind']);
    }

    public function testAPureVatChangeGivesNoTerminationRight(): void
    {
        // 11,90 ct inkl. 7 % → netto 11,1215; 13,23 inkl. 19 % → 11,1176. 12,50 € → 11,6822; 13,90 → 11,6807.
        $ct = self::contract(['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9, 'vat_pct' => 7.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5, 'vat_pct' => 7.0]]]);
        $vat = ['valid_from' => '2027-01-01', 'document_date' => '2026-12-20', 'working_price_new' => 13.23, 'base_price_new' => 13.9, 'vat_pct' => 19];
        $a = $this->assess($vat, $ct);
        self::assertSame(['up', true, 7.0, 19.0], [$a['direction'], $a['vat_only'], $a['vat_old'], $a['vat_new']]);
        self::assertNull($a['legal_deadline']);
        self::assertNull($a['termin']);
        self::assertNull($a['announcement'], 'keine Unterrichtungspflicht geprüft');
        self::assertSame(['vatOnly'], self::keys($a));
        self::assertSame(['de.enwg.41.6'], PriceChange::lines($a)[0]['rules']);
        self::assertSame(['vatOnlyBasic'], self::keys($this->assess($vat, $ct + ['supply_kind' => 'basic'])));
        // netto teurer: eine echte Erhöhung
        $more = $this->assess(['working_price_new' => 13.4] + $vat, $ct);
        self::assertFalse($more['vat_only']);
        self::assertSame('2026-12-31', $more['legal_deadline']);
        // ohne Steuersatz am alten Preis: nie vermutet, also Erhöhung
        self::assertFalse($this->assess($vat)['vat_only']);
    }

    public function testDirectionOfTheChange(): void
    {
        $down = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 11.5]);
        self::assertSame(['down', null, []], [$down['direction'], $down['legal_deadline'], self::keys($down)]);
        // Arbeitspreis hoch, Grundpreis runter: bleibt eine Erhöhung (Konzept A3, Grenzfälle)
        self::assertSame('up', $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8, 'base_price_new' => 11.0])['direction']);
        // Grundpreis je Jahr: 150 € ÷ 12 = 12,50 € — gleich
        self::assertSame('same', $this->assess(['valid_from' => '2027-01-01', 'base_price_new' => 150.0, 'base_price_period' => 'year'])['direction']);
        // ohne alten Preis (kein Vertrag, nichts im Schreiben): unbekannt, das Recht wird genannt
        $unknown = PriceChange::assess(self::letter(['valid_from' => '2027-01-01', 'working_price_new' => 12.8]), 'gas', null, null, 'DE', $this->contracts, '2026-12-01');
        self::assertSame(['unknown', '2026-12-31'], [$unknown['direction'], $unknown['legal_deadline']]);
    }

    public function testAnEarlierDateInTheLetterIsTheTerminUntilItHasPassed(): void
    {
        $letter = ['valid_from' => '2027-01-01', 'working_price_new' => 12.8, 'special_termination_until' => '2026-12-15'];
        $a = $this->assess($letter, null, '2026-12-01');
        self::assertSame(['2026-12-15', '2026-12-31', false], [$a['termin'], $a['legal_deadline'], $a['termin_passed']]);
        self::assertSame(['terminationUnknown', 'letterEarlier'], self::keys($a));
        self::assertSame(['letter' => '2026-12-15', 'deadline' => '2026-12-31'], PriceChange::lines($a)[1]['params']);
        // die Frist des Anbieters ist vorbei, die gesetzliche nicht: dann zählt sie
        self::assertSame(['2026-12-31', false], [($b = $this->assess($letter, null, '2026-12-20'))['termin'], $b['termin_passed']]);
        $c = $this->assess($letter, null, '2027-01-02');
        self::assertTrue($c['termin_passed']);
        self::assertSame(['terminationPassed', 'letterEarlier'], self::keys($c));
        // ein späteres Datum im Schreiben: Termin bleibt der gesetzliche
        $later = $this->assess(['special_termination_until' => '2027-01-15'] + $letter, null, '2026-12-01');
        self::assertSame(['2026-12-31', ['terminationUnknown', 'letterLater']], [$later['termin'], self::keys($later)]);
    }

    public function testDistrictHeatingWaterAndOtherCountriesGetNoTermin(): void
    {
        $heat = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8, 'special_termination_until' => '2026-12-31'], null, '2026-12-01', 'fernwaerme');
        self::assertNull($heat['legal_deadline']);
        self::assertSame(['heat'], self::keys($heat), 'nie „Sonderkündigung“ bei Fernwärme (Regression B1)');
        self::assertSame(['de.avbfernwaermev.24.4'], PriceChange::lines($heat)[0]['rules']);
        $at = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8], null, '2026-12-01', 'gas', 'AT');
        self::assertSame([false, null, []], [$at['rights'], $at['legal_deadline'], self::keys($at)]);
        // ein Satz nennt nur Regeln, die im Land nennbar sind (die Schlüssel sind deutsche)
        $de = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8], null, '2026-12-01');
        self::assertSame([], PriceChange::lines(['country' => 'AT'] + $de));
    }

    public function testADifferentAdvanceMakesItAnotherLetter(): void
    {
        $a = ['type' => 'price_change', 'extraction' => ['fields' => ['valid_from' => ['value' => '2027-01-01'], 'working_price_new' => ['value' => 12.8],
            'contract_number' => ['value' => 'VK-778899'], 'advance_new' => ['value' => 150.0]]]];
        $b = $a;
        self::assertTrue(InboxService::sameContent($a, $b));
        $b['extraction']['fields']['advance_new']['value'] = 165.0;
        self::assertFalse(InboxService::sameContent($a, $b), 'anderer Abschlag, anderes Schreiben');
    }

    public function testAnAdvanceRisingFasterThanTheCostsCitesTheRuleForItsKind(): void
    {
        $a = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8], self::contract(['supply_kind' => 'special']));
        $lines = PriceChange::lines($a, ['advance_pct' => 20.4, 'cost_pct' => 7.2]);
        self::assertSame(['advance_pct' => 20, 'cost_pct' => 7], end($lines)['params']);
        self::assertSame(['de.enwg.41b.3'], end($lines)['rules']);
        // 5 Prozentpunkte Abstand sind noch Rundung (App-Schwelle)
        self::assertSame(['terminationSpecial'], self::keys($a, ['advance_pct' => 7.2 + PriceChange::ADVANCE_EXCESS_PCT, 'cost_pct' => 7.2]));
        $basic = $this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8], self::contract(['supply_kind' => 'basic']));
        self::assertSame(['de.gasgvv.13'], array_values(array_filter(PriceChange::lines($basic, ['advance_pct' => 20.0, 'cost_pct' => 7.0]), fn($l) => str_ends_with($l['key'], 'advanceBasic')))[0]['rules']);
        self::assertSame(['terminationUnknown', 'advanceUnknown'], self::keys($this->assess(['valid_from' => '2027-01-01', 'working_price_new' => 12.8]), ['advance_pct' => 20.0, 'cost_pct' => 7.0]));
    }

    public function testEveryRuleOfTheLinesIsCitableAndGuaranteeIsNamed(): void
    {
        $a = $this->assess(['valid_from' => '2027-01-01', 'document_date' => '2026-12-20', 'working_price_new' => 12.8, 'special_termination_until' => '2026-12-24'],
            self::contract(['price_guarantee_until' => '2027-06-30']), '2026-12-21');
        // 20.12. → 01.01.: 12 Tage; Schreiben bis 24.12. vor dem Gesetz (31.12.); Preisgarantie über den Stichtag hinaus
        self::assertSame(['terminationUnknown', 'letterEarlier', 'lateSpecial', 'guarantee', 'advanceUnknown'], self::keys($a, ['advance_pct' => 30.0, 'cost_pct' => 7.0]));
        self::assertSame(['2026-12-24', 12], [$a['termin'], $a['announcement']['days_before']]);
        foreach (PriceChange::lines($a, ['advance_pct' => 30.0, 'cost_pct' => 7.0]) as $l) {
            foreach ($l['rules'] as $r) self::assertTrue(Rights::citable('DE', $r), $r);
        }
    }

    // ── Posteingang: Vorschläge, Übernehmen, Kernaussage ─────────────────

    private AttachmentService $att;
    private ReminderService $reminders;
    private Proposals $proposals;
    private Gist $gist;

    private function inbox(array $answer, array $pages, string $today, ?\Closure $rights = null): InboxService
    {
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->proposals = new Proposals($this->meters, $this->contracts, $this->readings, $bills, $this->reminders, $this->settings);
        $this->proposals->setToday($today);
        if ($rights !== null) $this->proposals->attachRights($rights);
        $s = new InboxService($this->store, $this->i18n, $this->att,
            new ProviderRegistry($this->settings, InboxFixture::provider('local', ['Preisanpassung' => 'price_change'], ['price_change' => $answer])),
            $this->proposals, new Applier($this->meters, $this->contracts, $this->readings, $bills, $this->reminders, $this->settings, $this->i18n));
        $s->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $this->gist = new Gist($this->meters, $this->contracts, $this->consumption, $bills, $this->readings,
            new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n), null, $this->proposals);
        $s->attachGist($this->gist);
        return $s;
    }

    /**
     * Erfundenes Schreiben (Musterstadt) mit Textebene und Antwort des Modells; jedes Zitat steht im Text.
     * Optionen: from, date, wp_old, wp_new, bp_old, bp_new, vat, until, basic (bool), adv, adv_from (Tage TT.MM.JJJJ).
     *
     * @return array{0: list<string>, 1: array<string,mixed>}
     */
    private static function document(array $o): array
    {
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        $lines = ['Stadtwerke Musterstadt GmbH', "Preisanpassung Erdgas zum {$o['from']}", 'Vertragskonto: VK-778899'];
        $a = ['utility' => $f('gas', "Preisanpassung Erdgas zum {$o['from']}"), 'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'valid_from' => $f($o['from'], "Preisanpassung Erdgas zum {$o['from']}"), 'contract_number' => $f('VK-778899', 'Vertragskonto: VK-778899')];
        if (isset($o['date'])) { $lines[] = $q = "Musterstadt, den {$o['date']}"; $a['document_date'] = $f($o['date'], $q); }
        if (isset($o['wp_new'])) {
            $lines[] = $q = isset($o['wp_old']) ? "Ihr Arbeitspreis steigt von {$o['wp_old']} ct/kWh auf {$o['wp_new']} ct/kWh." : "Ihr neuer Arbeitspreis: {$o['wp_new']} ct/kWh.";
            $a['working_price_new'] = $f($o['wp_new'], $q);
            if (isset($o['wp_old'])) $a['working_price_old'] = $f($o['wp_old'], $q);
        }
        if (isset($o['bp_new'])) {
            $lines[] = $q = "Der Grundpreis beträgt künftig {$o['bp_new']} € pro Monat.";
            $a['base_price_new'] = $f($o['bp_new'], $q);
            $a['base_price_period'] = $f('month', $q);
        }
        if (isset($o['vat'])) { $lines[] = $q = "Alle Preise inklusive {$o['vat']} % Umsatzsteuer."; $a['vat_pct'] = $f($o['vat'], $q); }
        if (isset($o['until'])) { $lines[] = $q = "Sie haben ein Sonderkündigungsrecht bis zum {$o['until']}."; $a['special_termination_until'] = $f($o['until'], $q); }
        if (isset($o['adv'])) {
            $lines[] = $q = isset($o['adv_from']) ? "Ihr neuer Abschlag ab {$o['adv_from']}: {$o['adv']} € monatlich" : "Ihr neuer Abschlag: {$o['adv']} € monatlich";
            $a['advance_new'] = $f($o['adv'], $q);
            if (isset($o['adv_from'])) $a['advance_new_from'] = $f($o['adv_from'], $q);
        }
        return [[implode("\n", $lines)], $a];
    }

    /** Gas-Zähler mit Nummer, laufender Vertrag ab 2024 (11,90 ct, 12,50 €, Abschlag 140 €). */
    private function gasHousehold(array $contract = []): array
    {
        $mid = $this->setMeterDevices('gas', [['id' => 'd_gas_1', 'serial' => InboxFixture::METER, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $c = $this->contracts->create('gas', $contract + ['meter_id' => $mid, 'provider' => self::PROVIDER, 'start' => '2024-01-01',
            'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]]);
        return ['meter_id' => $mid, 'contract' => $c];
    }

    /** Ablegen, erkennen, auslesen; Vorschau zurück. */
    private function read(InboxService $s, array $pages, string $tag = 'pc'): array
    {
        $e = $s->upload(InboxFixture::pdf($tag), "$tag.pdf");
        $s->classify($e['id'], $pages);
        $s->extract($e['id'], $pages);
        return $s->preview($e['id']);
    }

    private static function kind(array $proposals, string $kind): array
    {
        return array_values(array_filter($proposals, fn($p) => $p['kind'] === $kind));
    }

    private function gistOf(array $pv, string $today): array
    {
        return $this->gist->describe($pv['entry'], $pv['assignment'], $pv['proposals'], $today);
    }

    public function testTakenOverPricesCarryTheDateOfTheLetterAndTheRateAndTheTerminIsTheDayBefore(): void
    {
        $h = $this->gasHousehold();
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '07.12.2026', 'wp_old' => '11,90', 'wp_new' => '12,80', 'bp_new' => '13,10', 'vat' => '19']);
        $s = $this->inbox($answer, $pages, '2026-12-08');
        $pv = $this->read($s, $pages);
        $wp = self::kind($pv['proposals'], 'contract_price')[0];
        self::assertSame(['announced_on' => '2026-12-07', 'vat_pct' => 19.0], $wp['data']['row']);
        self::assertSame(['announced_on' => '2026-12-07', 'vat_pct' => 19.0], self::kind($pv['proposals'], 'contract_base_price')[0]['data']['row']);
        $rem = self::kind($pv['proposals'], 'reminder');
        self::assertCount(1, $rem, 'ohne laufende Prüfung A3 legt der Posteingang den gesetzlichen Termin an');
        self::assertSame(['specialTerminationLegal', '2026-12-31'], [$rem[0]['data']['label'], $rem[0]['data']['next_due']]);
        self::assertTrue($rem[0]['checked']);
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        $ct = $this->contracts->get('gas', (string)$h['contract']['id']);
        // JSON schreibt 19.0 als 19 (Lektion 90c): Werte gleich, Typ egal
        self::assertEquals(['from' => '2027-01-01', 'ct_per_kwh' => 12.8, 'announced_on' => '2026-12-07', 'vat_pct' => 19], $ct['working_prices'][1]);
        self::assertSame('2026-12-07', $ct['base_prices'][1]['announced_on']);
        $r = array_values(array_filter($this->reminders->list(), fn($x) => $x['next_due'] === '2026-12-31'));
        self::assertCount(1, $r);
        self::assertSame('Sonderkündigung ' . self::PROVIDER . ' möglich, Zugang bis 31.12.2026', $r[0]['title']);
        foreach ($s->preview($pv['entry']['id'])['proposals'] as $x) self::assertSame('exists', $x['status'], $x['kind'] . ': nichts doppelt');
        // Kernaussage: Frist und zu späte Ankündigung (25 Tage, Sondervertrag unbekannt → ein Monat), mit Quellen
        $g = $this->gistOf($s->preview($pv['entry']['id']), '2026-12-08');
        self::assertSame(['inbox.gist.rights.terminationUnknown', 'inbox.gist.rights.lateSpecial'], array_column($g['rights']['lines'], 'key'));
        self::assertSame(['days' => 25], $g['rights']['lines'][1]['params']);
        self::assertTrue($g['rights']['energy']);
        self::assertContains('§ 41 Abs. 5 Satz 1 bis 3 EnWG', array_column($g['rights']['norms'], 'norm'));
        // dasselbe Schreiben erst nach der Frist gelesen: kein vergangener Termin
        $late = $this->read($this->inbox($answer, $pages, '2027-01-05'), $pages, 'pc-late');
        self::assertSame([], self::kind($late['proposals'], 'reminder'));
    }

    public function testWithoutTheRightsCheckTheEarlierDateOfTheLetterIsTheTermin(): void
    {
        $this->gasHousehold();
        // das Schreiben nennt den 15.12.: früher als das Gesetz (31.12.) — ohne A3 legt der Posteingang den Termin an
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80', 'until' => '15.12.2026']);
        $s = $this->inbox($answer, $pages, '2026-11-21');
        $pv = $this->read($s, $pages, 'pc-early');
        $rem = self::kind($pv['proposals'], 'reminder');
        self::assertSame(['specialTerminationLetter', '2026-12-15'], [$rem[0]['data']['label'], $rem[0]['data']['next_due']]);
        self::assertSame(['provider' => self::PROVIDER, 'legal' => '2026-12-31', 'letter' => '2026-12-15', 'date' => '2026-12-15'], $rem[0]['data']['params']);
        $g = $this->gistOf($pv, '2026-11-21');
        self::assertSame(['inbox.gist.rights.terminationUnknown', 'inbox.gist.rights.letterEarlier'], array_column($g['rights']['lines'], 'key'));
        self::assertNotContains('inbox.gist.detail.termination', array_column($g['details'], 'key'), 'die Frist nennen dann die Rechte');
        $s->apply($pv['entry']['id'], [$rem[0]['id']]);
        self::assertSame('Sonderkündigung ' . self::PROVIDER . ' möglich, Zugang bis 15.12.2026 (laut Schreiben 15.12.2026, gesetzlich 31.12.2026)',
            array_values(array_filter($this->reminders->list(), fn($x) => $x['next_due'] === '2026-12-15'))[0]['title']);
        // vorbei: kein Termin mehr
        $late = $this->read($this->inbox($answer, $pages, '2027-01-02'), $pages, 'pc-late');
        self::assertSame([], self::kind($late['proposals'], 'reminder'));
    }

    /**
     * v3.11.0 (integration) — läuft A3, gibt es den Kündigungstermin nur einmal: Der Posteingang schreibt
     * Datum des Schreibens und „Kündigung bis …“ an die Preise und legt keinen eigenen Termin an; A3
     * bildet daraus den einen Termin (15.12. laut Schreiben, gesetzlich 31.12.), mit beiden Tagen im Text.
     */
    public function testWithTheRightsCheckRunningThereIsExactlyOneTermin(): void
    {
        $this->gasHousehold();
        [$rights, $agenda, $a3] = $this->a3('2026-11-21');
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80']);
        $pv = $this->read($this->inbox($answer, $pages, '2026-11-21', $a3), $pages);
        self::assertSame([], self::kind($pv['proposals'], 'reminder'), 'den gesetzlichen Termin legt A3 an (aus announced_on)');
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80', 'until' => '15.12.2026']);
        $s = $this->inbox($answer, $pages, '2026-11-21', $a3);
        $pv = $this->read($s, $pages, 'pc-early');
        self::assertSame([], self::kind($pv['proposals'], 'reminder'), 'auch den früheren Tag laut Schreiben legt A3 an');
        self::assertSame(['announced_on' => '2026-11-20', 'special_termination_until' => '2026-12-15'],
            self::kind($pv['proposals'], 'contract_price')[0]['data']['row']);
        $g = $this->gistOf($pv, '2026-11-21');
        self::assertSame(['inbox.gist.rights.terminationUnknown', 'inbox.gist.rights.letterEarlier'], array_column($g['rights']['lines'], 'key'));
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        // genau ein Termin der Sonderkündigung unter „Zu tun“ und im Kalender: der Befund A3
        $terms = array_values(array_filter($agenda->events(90, '2026-11-21'), fn($e) => $e['kind'] === 'reminder'
            || ($e['kind'] === 'rights' && ($e['ref']['check'] ?? null) === 'special_termination')));
        self::assertCount(1, $terms);
        self::assertSame(['rights', '2026-12-15', '2026-12-15'], [$terms[0]['kind'], $terms[0]['date'], $terms[0]['ref']['deadline']]);
        $f = $rights->findings('2026-11-21')[0];
        self::assertSame(['2026-12-15', '2026-12-31'], [$f['facts']['special_termination_until'], $f['facts']['legal_deadline']]);
        self::assertStringContainsString('Laut Schreiben soll die Kündigung bis zum 15.12.2026 eingehen; nach dem Gesetz reicht der Zugang bis zum 31.12.2026', $f['text']);
        // nach dem Tag laut Schreiben: wieder der gesetzliche (Prüfbericht 21), weiterhin ein Termin
        self::assertSame('2026-12-31', $rights->findings('2026-12-16')[0]['deadline']);
        $late = $this->read($this->inbox($answer, $pages, '2027-01-02', $a3), $pages, 'pc-late');
        self::assertSame([], self::kind($late['proposals'], 'reminder'));
    }

    /**
     * v3.11.0 (Skeptiker H1) — ein Vertrag, der nach seinem Ende ohne Kündigung weiterläuft (Ende 30.06.2026,
     * heute 21.11.2026): A3 sieht ihn und legt den einen Termin an, der Posteingang keinen eigenen. Bis dahin
     * verzichtete der Posteingang (A3 angemeldet), und A3 sah den Vertrag nicht: kein Termin.
     */
    public function testARenewedContractGetsExactlyOneTerminToo(): void
    {
        $this->gasHousehold(['end' => '2026-06-30']);
        [$rights, $agenda, $a3] = $this->a3('2026-11-21');
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80']);
        $s = $this->inbox($answer, $pages, '2026-11-21', $a3);
        $pv = $this->read($s, $pages);
        self::assertSame([], self::kind($pv['proposals'], 'reminder'), 'den Termin legt A3 an');
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        $terms = array_values(array_filter($agenda->events(90, '2026-11-21'), fn($e) => $e['kind'] === 'reminder'
            || ($e['kind'] === 'rights' && ($e['ref']['check'] ?? null) === 'special_termination')));
        self::assertCount(1, $terms);
        self::assertSame(['rights', '2026-12-31'], [$terms[0]['kind'], $terms[0]['ref']['deadline']]);
    }

    /**
     * v3.11.0 (Skeptiker H1) — liefert A3 für den Vertrag keinen Befund (gekündigt zum 15.12.2026, die Erhöhung
     * kommt erst zum 01.01.2027), legt der Posteingang seinen Termin an, obwohl A3 angemeldet ist.
     */
    public function testWithoutAFindingOfA3TheInboxKeepsItsTermin(): void
    {
        $this->gasHousehold(['end' => '2026-12-15', 'auto_renews' => false]);
        [, , $a3] = $this->a3('2026-11-21');
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80']);
        $pv = $this->read($this->inbox($answer, $pages, '2026-11-21', $a3), $pages);
        self::assertCount(1, self::kind($pv['proposals'], 'reminder'));
    }

    /** A3 verdrahtet wie in src/bootstrap.php, dazu Empfehlungen und „Zu tun“. @return array{0: RightsService, 1: AgendaService, 2: \Closure} */
    private function a3(string $today): array
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $checks = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills), $this->i18n);
        $rights = new RightsService($this->settings, $this->i18n);
        $rights->register('special_termination', fn(string $t, string $c): array => $checks->specialTermination($t, $c));
        $recs = (new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->atDate($today);
        $recs->attachRights($rights);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $agenda->attachRights($rights);
        // v3.11.0 (Skeptiker H1) — der Posteingang fragt A3 für den Vertrag (wie src/bootstrap.php)
        $covers = fn(string $u, array $c, string $from, string $t): bool => $checks->coversPriceChange($u, $c, $from, $t, $rights->country());
        return [$rights, $agenda, $covers];
    }

    public function testTheSamePriceWithoutItsOriginGetsItAndKeepsTheRateItHad(): void
    {
        $h = $this->gasHousehold(['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9], ['from' => '2027-01-01', 'ct_per_kwh' => 12.8, 'vat_pct' => 19.0]]]);
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80']);
        $s = $this->inbox($answer, $pages, '2026-11-21');
        $pv = $this->read($s, $pages);
        $wp = self::kind($pv['proposals'], 'contract_price')[0];
        self::assertSame('new', $wp['status'], 'der Preis steht schon da, die Herkunft fehlt');
        self::assertEquals(['announced_on' => '2026-11-20', 'vat_pct' => 19], $wp['data']['row']);
        $s->apply($pv['entry']['id'], [$wp['id']]);
        self::assertEquals(['from' => '2027-01-01', 'ct_per_kwh' => 12.8, 'announced_on' => '2026-11-20', 'vat_pct' => 19],
            $this->contracts->get('gas', (string)$h['contract']['id'])['working_prices'][1]);
        self::assertSame('exists', self::kind($s->preview($pv['entry']['id'])['proposals'], 'contract_price')[0]['status']);
    }

    public function testAPureVatChangeHasNoTerminAndSaysWhy(): void
    {
        $this->gasHousehold(['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9, 'vat_pct' => 7.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5, 'vat_pct' => 7.0]]]);
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.12.2026', 'wp_new' => '13,23', 'bp_new' => '13,90', 'vat' => '19']);
        $s = $this->inbox($answer, $pages, '2026-12-21');
        $pv = $this->read($s, $pages);
        self::assertSame([], self::kind($pv['proposals'], 'reminder'));
        self::assertSame(19.0, self::kind($pv['proposals'], 'contract_price')[0]['data']['row']['vat_pct']);
        $g = $this->gistOf($pv, '2026-12-21');
        self::assertSame(['inbox.gist.rights.vatOnly'], array_column($g['rights']['lines'], 'key'));
        self::assertSame(['§ 41 Abs. 6 EnWG'], array_column($g['rights']['norms'], 'norm'));
    }

    public function testATwoRateLetterGoesToTheListOfTheAssignedMeterAndIsToBeChecked(): void
    {
        // HT/NT: zwei Zähler in einer Gruppe, HT rechnet mit der gemeinsamen Liste (30 ct), NT mit eigener (22 ct)
        $ht = (string)$this->meters->create('strom', ['name' => 'HT', 'installed_on' => '2024-01-01'])['id'];
        $nt = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => '2024-01-01'])['id'];
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        foreach ([$ht, $nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $gid]);
        $c = $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => self::PROVIDER, 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => [$nt => [['from' => '2024-01-01', 'ct_per_kwh' => 22.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '24,00']);
        $answer['utility']['value'] = 'strom';
        $s = $this->inbox($answer, $pages, '2026-11-21');
        $e = $s->upload(InboxFixture::pdf('htnt'), 'htnt.pdf');
        $s->classify($e['id'], $pages);
        $s->extract($e['id'], $pages);
        $s->update($e['id'], ['assignment' => ['utility' => 'strom', 'meter_id' => $nt, 'contract_id' => (string)$c['id']]]);
        $pv = $s->preview($e['id']);
        $wp = self::kind($pv['proposals'], 'contract_price')[0];
        self::assertSame("working_prices_by_meter.$nt", $wp['data']['list']);
        self::assertSame([true, false], [$wp['check'], $wp['checked']], 'welches Zählwerk, sagt das Schreiben nicht sicher');
        self::assertContains(['field' => 'working_price_new', 'reason' => 'per_meter'], $wp['hints']);
        // die Kernaussage rechnet mit dem alten Preis des NT-Zählers (22 ct), nicht mit der gemeinsamen Liste
        $g = $this->gistOf($pv, '2026-11-21');
        self::assertSame(['old' => 22.0, 'new' => 24.0], array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.workingPrice'))[0]['params']);
        $s->apply($e['id'], [$wp['id']]);
        $after = $this->contracts->get('strom', (string)$c['id']);
        self::assertSame([22.0, 24.0], array_map('floatval', array_column($after['working_prices_by_meter'][$nt], 'ct_per_kwh')));
        self::assertSame([30.0], array_map('floatval', array_column($after['working_prices'], 'ct_per_kwh')), 'die gemeinsame Liste (HT) bleibt');
        // dem HT-Zähler zugeordnet: die gemeinsame Liste, ebenfalls zu prüfen
        $s->update($e['id'], ['assignment' => ['utility' => 'strom', 'meter_id' => $ht, 'contract_id' => (string)$c['id']]]);
        $wpHt = self::kind($s->preview($e['id'])['proposals'], 'contract_price')[0];
        self::assertSame(['working_prices', true], [$wpHt['data']['list'], $wpHt['check']]);
    }

    public function testTheAdvanceInTheLetterIsProposedFromItsDayOrFromTheChange(): void
    {
        $h = $this->gasHousehold();
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80', 'adv' => '150,00', 'adv_from' => '01.02.2027']);
        $s = $this->inbox($answer, $pages, '2026-11-21');
        $pv = $this->read($s, $pages);
        $adv = self::kind($pv['proposals'], 'contract_advance')[0];
        self::assertSame(['from' => '2027-02-01', 'value' => 150.0], array_intersect_key($adv['data'], ['from' => 1, 'value' => 1]));
        self::assertTrue($adv['checked']);
        $s->apply($pv['entry']['id'], [$adv['id']]);
        self::assertSame(150.0, (float)$this->contracts->get('gas', (string)$h['contract']['id'])['advance_payments'][1]['amount_eur']);
        // ohne eigenen Tag: ab der Preisänderung, mit Hinweis
        [$pages, $answer] = self::document(['from' => '01.01.2027', 'date' => '20.11.2026', 'wp_new' => '12,80', 'adv' => '150,00']);
        $adv = self::kind($this->read($this->inbox($answer, $pages, '2026-11-21'), $pages, 'pc2')['proposals'], 'contract_advance')[0];
        self::assertSame('2027-01-01', $adv['data']['from']);
        self::assertContains(['field' => 'advance_new_from', 'reason' => 'from_price_change'], $adv['hints']);
    }

    // ── Vorschau gleich der späteren Vertragsrechnung (Lektion 92) ───────

    private const AVG_TEMP = [1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
        7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0];

    /** Drei Jahre Monatsablesungen bis 01.11.2026 (feste Tage), Gas mit Umrechnung 1. */
    private function forecastHousehold(): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
        $temps = [];
        for ($d = new \DateTimeImmutable('2023-01-01'); $d <= new \DateTimeImmutable('2027-12-31'); $d = $d->modify('+1 day')) {
            $avg = self::AVG_TEMP[(int)$d->format('n')];
            $temps[$d->format('Y-m-d')] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $mid = $this->setMeterDevices('gas', [['id' => 'd_gas_1', 'serial' => InboxFixture::METER, 'installed_on' => '2023-11-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [['date' => '2023-11-01', 'counter' => 0.0, 'device_id' => 'd_gas_1']];
        $counter = 0.0;
        for ($cur = new \DateTimeImmutable('2023-11-01'); $cur < new \DateTimeImmutable('2026-11-01'); ) {
            $m = (int)$cur->format('n'); $days = (int)$cur->format('t');
            $counter += 4.0 * $days * max(0.0, 15.0 - self::AVG_TEMP[$m]) + 20.0 * $days;
            $cur = $cur->modify('first day of next month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => round($counter, 3), 'device_id' => 'd_gas_1'];
        }
        $this->setReadings('gas', $mid, $rows);
        $c = $this->contracts->create('gas', ['meter_id' => $mid, 'provider' => self::PROVIDER, 'start' => '2023-11-01', 'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2023-11-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2023-11-01', 'eur_per_month' => 12.5]],
            'advance_payments' => [['from' => '2023-11-01', 'amount_eur' => 140.0]]]);
        return ['meter_id' => $mid, 'contract' => $c];
    }

    public function testThePreviewEqualsTheContractCostsAfterTakingOver(): void
    {
        $today = '2026-11-10';
        $h = $this->forecastHousehold();
        [$pages, $answer] = self::document(['from' => '01.11.2026', 'date' => '15.09.2026', 'wp_old' => '11,90', 'wp_new' => '15,90', 'bp_new' => '13,10', 'adv' => '230,00']);
        $s = $this->inbox($answer, $pages, $today);
        $pv = $this->read($s, $pages);
        $g = $this->gistOf($pv, $today);
        $switch = new TariffSwitchService(new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $this->contracts, $this->meters, $this->i18n);
        $ref = fn(): array => array_values(array_filter($switch->analyze('gas', $h['meter_id'], ['today' => $today])['candidates'], fn($c) => !empty($c['is_reference'])))[0];
        $before = $ref();
        self::assertGreaterThanOrEqual('2026-11', $before['monthly'][0]['ym'], 'Fenster ganz nach dem Stichtag');
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['checked'] && $p['kind'] !== 'contract_advance'), 'id'));
        $after = $ref();
        // Mehrkosten der Kernaussage = Unterschied der Vertragsrechnung über dieselben zwölf Monate
        self::assertEqualsWithDelta($after['year2_eur'] - $before['year2_eur'], $g['figure']['value'], 0.02);
        // ein Jahr zu den neuen Preisen = Vertragsrechnung danach; je Monat ein Zwölftel
        $year = array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.yearAfter'))[0]['params'];
        self::assertEqualsWithDelta($after['year2_eur'], $year['amount'], 0.02);
        self::assertEquals(round($after['year2_eur'] / 12), $year['recommended']);
        // Spanne um die Mehrkosten, wenn die Prognose ein Jahresband hat
        $range = array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.range'));
        self::assertCount(1, $range, 'drei Jahre Heizmodell: die Prognose hat ein Jahresband');
        self::assertLessThan($g['figure']['value'], $range[0]['params']['low']);
        self::assertGreaterThan($g['figure']['value'], $range[0]['params']['high']);
        // Abschlag laut Schreiben (230 € statt 140 €, +64 %) steigt stärker als die Kosten (gegen das
        // Jahr zu alten Preisen, wie der Tarifvergleich vorher): Satz mit der Regel
        $adv = array_values(array_filter($g['rights']['lines'] ?? [], fn($l) => $l['key'] === 'inbox.gist.rights.advanceUnknown'));
        $costPct = $g['figure']['value'] / ($before['year2_eur']) * 100;
        self::assertGreaterThan(25, $costPct);
        self::assertSame(['advance_pct' => 64, 'cost_pct' => (int)round($costPct)], $adv[0]['params'] ?? null);
        self::assertSame(['amount' => 230.0, 'date' => '2026-11-01'], array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.newAdvance'))[0]['params']);
    }

    /** Nicht nur am eigenen Modell: der unordentliche Testhaushalt (Lücken, Schätzung, Zählertausch). */
    public function testOnTheMessyHouseholdThePreviewStillEqualsTheContractCosts(): void
    {
        $h = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
        $today = '2026-01-20';
        [$pages, $answer] = self::document(['from' => '01.01.2026', 'date' => '25.11.2025', 'wp_new' => '12,40', 'bp_new' => '14,00']);
        $s = $this->inbox($answer, $pages, $today);
        $e = $s->upload(InboxFixture::pdf('messy'), 'messy.pdf');
        $s->classify($e['id'], $pages);
        $s->extract($e['id'], $pages);
        $s->update($e['id'], ['assignment' => ['utility' => 'gas', 'meter_id' => 'm_gas_messy', 'contract_id' => 'c_gas_messy']]);
        $pv = $s->preview($e['id']);
        $g = $this->gistOf($pv, $today);
        self::assertSame('inbox.gist.priceChange.more', $g['key']);
        $switch = new TariffSwitchService(new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $this->contracts, $this->meters, $this->i18n);
        $ref = fn(): array => array_values(array_filter($switch->analyze('gas', 'm_gas_messy', ['today' => $today])['candidates'], fn($c) => !empty($c['is_reference'])))[0];
        $before = $ref();
        $s->apply($e['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        $after = $ref();
        self::assertEqualsWithDelta($after['year2_eur'] - $before['year2_eur'], $g['figure']['value'], 0.02);
        $year = array_values(array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.yearAfter'))[0]['params'];
        self::assertEqualsWithDelta($after['year2_eur'], $year['amount'], 0.02);
        foreach (array_filter($g['details'], fn($d) => $d['key'] === 'inbox.gist.detail.range') as $r) {
            self::assertLessThanOrEqual($g['figure']['value'], $r['params']['low']);
            self::assertGreaterThanOrEqual($g['figure']['value'], $r['params']['high']);
        }
        // 25.11.2025 → 01.01.2026: 37 Tage, rechtzeitig für einen Sondervertrag; Frist am 31.12.2025 vorbei
        self::assertSame(['inbox.gist.rights.terminationPassed'], array_column($g['rights']['lines'], 'key'));
    }

    // ── Doku-Zusage ──────────────────────────────────────────────────────

    public function testTheGuideNamesTheThresholdOfTheAdvanceAndTheAnnouncementPeriods(): void
    {
        $pts = (int)PriceChange::ADVANCE_EXCESS_PCT;
        $d = Rights::DEADLINES['DE'];
        self::assertSame([1, 42], [$d['announce_special_months'], $d['announce_basic_days']], 'ein Monat, sechs Wochen');
        $claims = ['docs/anleitungen/posteingang.md' => ["mehr als $pts Prozentpunkte", 'mindestens einen Monat', 'mindestens sechs Wochen und nur zum Monatsbeginn'],
            'docs/en/anleitungen/posteingang.md' => ["more than $pts percentage points", 'at least one month', 'at least six weeks and only at the start of a month']];
        foreach ($claims as $file => $list) {
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file));
            foreach ($list as $claim) self::assertStringContainsString($claim, $text, $file);
        }
    }
}
