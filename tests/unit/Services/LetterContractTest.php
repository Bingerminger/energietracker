<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\BillService;
use Energietracker\Services\LetterService;
use Energietracker\Tests\Support\LetterFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — „Brief schreiben“ an der Vertragskarte, ohne Befund: Antrag auf Abschlagsanpassung und
 * Kündigung zum nächstmöglichen Termin. Sonderkündigung, Widerspruch und Beanstandung gibt es so nie,
 * denn sie behaupten etwas (Recht, Fehler). Die Kündigung nennt keine Norm und keine Rechtsaussage.
 * Termine von Hand gerechnet, Stichtag fest (LetterFixture::TODAY = 2026-11-03), Daten erfunden.
 */
#[CoversClass(LetterService::class)]
final class LetterContractTest extends ServiceTestCase
{
    use LetterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->letterSetUp();
    }

    /** @return array<string,mixed> Modell */
    private function contractLetter(string $utility, string $contractId, string $kind, array $body = [], string $today = self::TODAY): array
    {
        return $this->letters->render(['utility' => $utility, 'contract_id' => $contractId, 'kind' => $kind] + $body + $this->person(), $today)['model'];
    }

    private function createContract(string $utility, array $fields): string
    {
        return (string)$this->contracts->create($utility, $fields + [
            'meter_id' => $this->meters->defaultId($utility), 'provider' => 'Stadtwerke Musterstadt',
        ])['id'];
    }

    /** Status und Katalogschlüssel eines abgelehnten Briefs. */
    private function refused(array $body): array
    {
        try {
            $this->letters->render($body + $this->person(), self::TODAY);
        } catch (HttpStatusException $e) {
            return [$e->status, $e->key];
        }
        self::fail('Fehler erwartet: ' . json_encode($body));
    }

    public function testTerminationCarriesTheReferencesAndNoLegalStatement(): void
    {
        $m = $this->contractLetter('strom', $this->stromContract, 'termination', ['extra' => ['reading_value' => 12345.6, 'reading_date' => '2026-11-02']]);
        foreach (['Kündigung zum nächstmöglichen Termin', 'Kundennummer: K-4711', 'Vertragsnummer: V-0815', 'Zählernummer: Z-1234567',
                  'Stadtwerke Musterstadt', 'hiermit kündige ich meinen Vertrag „Basis“ über die Lieferung von Strom ordentlich zum nächstmöglichen Termin.',
                  'Mein Zählerstand am 02.11.2026: 12.345,6 kWh.', 'Bitte bestätigen Sie mir die Kündigung und das Vertragsende in Textform.'] as $expected) {
            self::assertStringContainsString($expected, $m['text']);
        }
        self::assertSame('termination', $m['kind']);
        self::assertNull($m['finding_id']);
        self::assertSame($this->stromContract, $m['contract_id']);
        self::assertSame([], $m['norms'], 'keine Norm');
        self::assertStringNotContainsString('§', $m['text'], 'keine Rechtsaussage');
        self::assertDoesNotMatchRegularExpression('/Sonderkündigung|unwirksam|rechtswidrig|Preisänderung|Recht auf/iu', $m['text']);
        self::assertSame([], (array)$this->letters->publicModel($m)['finding'], 'ohne Norm keine Fußzeile im Dialog');
        self::assertSame('text', $m['form']);
        self::assertFalse($m['signature_line']);
        self::assertSame('brief-kuendigung-2026-11-03.pdf', $m['file_name']);
    }

    public function testMissingDetailsAreVisibleGaps(): void
    {
        // Gas: keine Vertragsnummer, kein Zähler mit Nummer, keine Frist eingetragen, kein Ende
        $m = $this->contractLetter('gas', $this->gasContract, 'termination');
        self::assertContains('contract_number', $m['gaps']);
        self::assertContains('meter_serial', $m['gaps']);
        self::assertContains('term_date', $m['gaps']);
        self::assertStringContainsString('Vertragsnummer: […]', $m['text']);
        self::assertStringContainsString('Zählernummer: […]', $m['text']);
        self::assertStringContainsString('Nach meinen Unterlagen ist das der […].', $m['text']);
        // im Dialog eingetragen: keine Lücke mehr
        $m = $this->contractLetter('gas', $this->gasContract, 'termination', ['extra' => ['term_date' => '2027-01-31'], 'refs' => ['contract_number' => 'V-1', 'meter_serial' => 'Z-2']]);
        self::assertSame([], $m['gaps']);
        self::assertStringContainsString('Nach meinen Unterlagen ist das der 31.01.2027.', $m['text']);
    }

    /** @return array<string, array{array<string,mixed>, string, ?string}> Felder, heute, Termin von Hand */
    public static function terms(): array
    {
        $base = ['start' => '2025-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]];
        return [
            'zum Laufzeitende, Frist läuft noch' => [$base + ['end' => '2026-12-31', 'notice_period_months' => 1], '2026-11-03', '2026-12-31'],
            'zum Laufzeitende, Frist verstrichen: Termin unbekannt' => [$base + ['end' => '2026-12-31', 'notice_period_months' => 3], '2026-11-03', null],
            'unbefristet zum Monatsende: 03.11. + 1 Monat = 03.12., Monatsende' => [$base + ['notice_period_months' => 1], '2026-11-03', '2026-12-31'],
            'unbefristet jederzeit: 03.11. + 1 Monat' => [$base + ['notice_period_months' => 1, 'notice_mode' => 'any_day'], '2026-11-03', '2026-12-03'],
            'Mindestlaufzeit später als die Frist' => [$base + ['notice_period_months' => 1, 'notice_mode' => 'any_day', 'min_term_end' => '2027-03-31'], '2026-11-03', '2027-03-31'],
            'ohne Frist und ohne Ende' => [$base, '2026-11-03', null],
            'verlängert, Sondervertrag: Termin steht im Vertrag' => [$base + ['end' => '2025-12-31', 'supply_kind' => 'special'], '2026-11-03', null],
            'verlängert, Grundversorgung: zwei Wochen ab heute' => [$base + ['end' => '2025-12-31', 'supply_kind' => 'basic'], '2026-11-03', '2026-11-17'],
        ];
    }

    #[DataProvider('terms')]
    public function testTheDateComesFromTheContract(array $fields, string $today, ?string $expected): void
    {
        $this->contracts->delete('strom', $this->stromContract);
        $id = $this->createContract('strom', $fields);
        $m = $this->contractLetter('strom', $id, 'termination', [], $today);
        self::assertSame($expected, $m['defaults']['extra']['term_date'] ?? null);
        self::assertSame($expected === null, in_array('term_date', $m['gaps'], true));
    }

    /**
     * v3.12.0 — Bleibt der Termin eine Lücke, sagt der Dialog warum (`term_gap`). Von Hand, heute 03.11.2026:
     *   Ende 31.12.2026 mit drei Monaten Frist: Stichtag 30.09.2026 verpasst (missed);
     *   ohne Frist und ohne Ende: nichts eingetragen (no_notice);
     *   Sondervertrag nach dem Ende 31.12.2025: läuft weiter (renewed).
     * Einen Termin danach rechnet die App bewusst nicht (Rechtsgrundlagen Nr. 59).
     *
     * @return array<string, array{array<string,mixed>, ?array<string,?string>}>
     */
    public static function termGaps(): array
    {
        $base = ['start' => '2025-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]];
        return [
            'Stichtag verpasst'      => [$base + ['end' => '2026-12-31', 'notice_period_months' => 3], ['reason' => 'missed', 'cancel_by' => '2026-09-30', 'end' => '2026-12-31']],
            'nichts eingetragen'     => [$base, ['reason' => 'no_notice', 'cancel_by' => null, 'end' => null]],
            'läuft nach dem Ende'    => [$base + ['end' => '2025-12-31', 'supply_kind' => 'special'], ['reason' => 'renewed', 'cancel_by' => null, 'end' => '2025-12-31']],
            'Termin bekannt: nichts' => [$base + ['end' => '2026-12-31', 'notice_period_months' => 1], null],
        ];
    }

    #[DataProvider('termGaps')]
    public function testAGapInTheDateSaysWhy(array $fields, ?array $expected): void
    {
        $this->contracts->delete('strom', $this->stromContract);
        $id = $this->createContract('strom', $fields);
        $m = $this->contractLetter('strom', $id, 'termination', [], '2026-11-03');
        self::assertSame($expected, $m['term_gap']);
        // im Dialog eingetragen: keine Lücke, kein Satz
        self::assertNull($this->contractLetter('strom', $id, 'termination', ['extra' => ['term_date' => '2027-02-28']], '2026-11-03')['term_gap']);
    }

    public function testDistrictHeatingIsTerminatedInWriting(): void
    {
        // Prüfbericht 30: Laufzeit und „gekündigt wird schriftlich“; neun Monate vor dem Ende = 31.03.2027 liegt noch vorn
        $id = $this->createContract('fernwaerme', ['start' => '2018-01-01', 'end' => '2027-12-31',
            'working_prices' => [['from' => '2018-01-01', 'ct_per_kwh' => 12.0]]]);
        $m = $this->contractLetter('fernwaerme', $id, 'termination');
        self::assertSame('written', $m['form']);
        self::assertTrue($m['signature_line']);
        self::assertStringContainsString('über die Lieferung von Fernwärme', $m['text']);
        self::assertSame('2027-12-31', $m['defaults']['extra']['term_date']);
        self::assertSame([], $m['norms'], 'der Brief selbst nennt keine Norm');
    }

    /**
     * v3.12.0 (Skeptiker M11) — keine Frist eingetragen, die neun Monate vor dem Ende (§ 32 AVBFernwärmeV) sind verstrichen: Das Ende
     * in fünf Jahren wäre nur eine Annahme. Der Brief lässt den Termin offen, der Dialog sagt warum (`term_assumed`).
     * Von Hand: Ende 31.12.2026, neun Monate davor 31.03.2026, heute 03.11.2026; Verlängerung bis 31.12.2031.
     */
    public function testAnAssumedHeatTermIsNoDateFromMyDocuments(): void
    {
        $id = $this->createContract('fernwaerme', ['start' => '2018-01-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2018-01-01', 'ct_per_kwh' => 12.0]]]);
        $m = $this->contractLetter('fernwaerme', $id, 'termination', [], '2026-11-03');
        self::assertNull($m['defaults']['extra']['term_date'] ?? null);
        self::assertContains('term_date', $m['gaps']);
        self::assertSame(['until' => '2031-12-31', 'cancel_by' => '2026-03-31'], $m['term_assumed']);
        self::assertNull($m['term_gap'], 'die Annahme hat ihren eigenen Satz');
        self::assertStringNotContainsString('31.12.2031', $m['text'], 'nicht „nach meinen Unterlagen“');
    }

    /** v3.12.0 (Skeptiker M11) — Grundversorgung mit Folgevertrag: zwei Wochen Frist, nicht der Tag vor dem Folgevertrag. */
    public function testBasicSupplyWithASuccessorKeepsTheTwoWeeks(): void
    {
        $this->contracts->delete('strom', $this->stromContract);
        $base = ['start' => '2025-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]];
        $id = $this->createContract('strom', $base + ['end' => '2025-12-31', 'supply_kind' => 'basic']);
        $this->createContract('strom', ['start' => '2027-03-01', 'working_prices' => [['from' => '2027-03-01', 'ct_per_kwh' => 30.0]]]);
        $m = $this->contractLetter('strom', $id, 'termination', [], '2026-11-03');
        self::assertSame('2026-11-17', $m['defaults']['extra']['term_date'] ?? null, 'bis dahin 28.02.2027');
    }

    /** @return array<string, array{string}> */
    public static function neverWithoutFinding(): array
    {
        return ['Sonderkündigung' => ['special_termination'], 'Beanstandung' => ['complaint'], 'Widerspruch' => ['objection'], 'Schätzung' => ['estimate_query']];
    }

    #[DataProvider('neverWithoutFinding')]
    public function testLettersThatClaimSomethingNeedAFinding(string $kind): void
    {
        self::assertSame([422, 'errors.letter.notOfferedContract'], $this->refused(['utility' => 'strom', 'contract_id' => $this->stromContract, 'kind' => $kind]));
        self::assertNotContains($kind, LetterService::CONTRACT_KINDS);
        self::assertNotContains($kind, $this->letters->contractKinds('strom', $this->contracts->get('strom', $this->stromContract), self::TODAY));
    }

    public function testInvalidRequests(): void
    {
        self::assertSame([404, 'errors.letter.contractNotFound'], $this->refused(['utility' => 'strom', 'contract_id' => 'c_gibt_es_nicht', 'kind' => 'termination']));
        self::assertSame([404, 'errors.letter.contractNotFound'], $this->refused(['utility' => 'kein_typ', 'contract_id' => $this->stromContract, 'kind' => 'termination']));
        self::assertSame([404, 'errors.letter.contractNotFound'], $this->refused(['contract_id' => $this->stromContract, 'kind' => 'termination']));
        self::assertSame([404, 'errors.letter.contractNotFound'], $this->refused(['utility' => 'strom', 'contract_id' => ['x'], 'kind' => 'termination']));
        self::assertSame([400, 'errors.letter.kindInvalid'], $this->refused(['utility' => 'strom', 'contract_id' => $this->stromContract, 'kind' => 'mahnung']));
        self::assertSame([400, 'errors.letter.kindInvalid'], $this->refused(['utility' => 'strom', 'contract_id' => $this->stromContract]));
        self::assertSame([400, 'errors.letter.fieldInvalid'], $this->refused(['utility' => 'strom', 'contract_id' => $this->stromContract, 'kind' => 'termination', 'extra' => ['term_date' => 'bald']]));
        // die Kündigung gibt es nicht aus einem Befund
        self::assertSame([422, 'errors.letter.notOffered'], $this->refused(['finding_id' => $this->ids['special_termination'], 'kind' => 'termination']));
    }

    public function testWhatTheCardOffers(): void
    {
        $kinds = fn(string $u, string $id) => $this->letters->contractKinds($u, $this->contracts->get($u, $id), self::TODAY);
        self::assertSame(['advance_adjustment', 'termination'], $kinds('strom', $this->stromContract));
        self::assertSame(['advance_adjustment', 'termination'], $kinds('gas', $this->gasContract));

        // Angebot zum Vergleich: nichts
        $offer = $this->createContract('strom', ['start' => '2026-01-01', 'is_shadow' => true, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 25.0]]]);
        self::assertSame([], $kinds('strom', $offer));
        self::assertSame([422, 'errors.letter.notOfferedContract'], $this->refused(['utility' => 'strom', 'contract_id' => $offer, 'kind' => 'termination']));
        // beendet, mit Folgevertrag: nichts mehr zu kündigen
        $gas = $this->meters->defaultId('gas');
        $past = (string)$this->contracts->create('gas', ['meter_id' => $gas, 'provider' => 'Alt', 'start' => '2020-01-01', 'end' => '2023-12-31', 'auto_renews' => false,
            'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 8.0]]])['id'];
        self::assertSame([], $kinds('gas', $past));
        // beginnt erst: kündbar, aber noch kein Abschlag zum Anpassen
        $future = $this->createContract('strom', ['start' => '2027-02-01', 'working_prices' => [['from' => '2027-02-01', 'ct_per_kwh' => 28.0]]]);
        self::assertSame(['termination'], $kinds('strom', $future));
        // Wasser nach Regel: Abschlag ja, Kündigung nein (keine Regel, oft kommunal)
        $water = $this->createContract('wasser', ['start' => '2025-01-01']);
        self::assertSame(['advance_adjustment'], $kinds('wasser', $water));
        self::assertSame([422, 'errors.letter.notOfferedContract'], $this->refused(['utility' => 'wasser', 'contract_id' => $water, 'kind' => 'termination']));
        // andere Arten: nichts
        self::assertSame([], $this->letters->contractKinds('pv_einspeisung', ['id' => 'x', 'phase' => 'current'], self::TODAY));
        self::assertSame([], $this->letters->contractKinds('heizoel', ['id' => 'x', 'phase' => 'current'], self::TODAY));
    }

    public function testAdvanceRequestFromTheContract(): void
    {
        // Gas in der Grundversorgung (§ 13 GasGVV), Abschlag 120 € monatlich, Rechnung mit Menge
        $this->contracts->update('gas', $this->gasContract, ['advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 120.0]]]);
        $meter = $this->meters->defaultId('gas');
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bills->create('gas', ['meter_id' => $meter, 'contract_id' => $this->gasContract, 'kind' => 'annual', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'issued_on' => '2026-01-20', 'invoice' => ['total_gross_eur' => 1500.0, 'energy_kwh' => 12000.0]]);

        $m = $this->contractLetter('gas', $this->gasContract, 'advance_adjustment');
        foreach (['Antrag auf Anpassung des Abschlags', 'ich bitte Sie, den Abschlag für meinen Vertrag ab dem 01.12.2026 auf […] je Monat festzusetzen',
                  "Bisher zahle ich 120,00\u{00A0}€ je Monat.", 'Mein Verbrauch im Abrechnungszeitraum vom 01.01.2025 bis 31.12.2025 betrug 12.000 kWh.',
                  'Nach § 13 GasGVV richtet sich der Abschlag nach dem Verbrauch im zuletzt abgerechneten Zeitraum.',
                  'Kundennummer: G-0042', 'Gaswerk Musterstadt'] as $expected) {
            self::assertStringContainsString($expected, $m['text']);
        }
        self::assertSame(['amount_eur'], $m['gaps'], 'der Betrag als Lücke, der Absender steht');
        self::assertSame(['de.gasgvv.13'], array_column($m['norms'], 'key'));
        self::assertSame(['de.gasgvv.13'], array_column($m['finding']['norms'], 'key'), 'Fußzeile im Dialog');
        self::assertNull($m['finding_id']);

        $m = $this->contractLetter('gas', $this->gasContract, 'advance_adjustment', ['extra' => ['amount_eur' => 99, 'reasons' => 'Eine Person weniger im Haushalt.']]);
        self::assertStringContainsString("ab dem 01.12.2026 auf 99,00\u{00A0}€ je Monat festzusetzen", $m['text']);
        self::assertStringContainsString('Eine Person weniger im Haushalt.', $m['text']);
        self::assertSame([], $m['gaps']);
    }

    /**
     * Der Betrag aus dem Vorschlag der Saldo-Karte, nur wenn die Karte ihn auch zeigt (spürbar anders als heute).
     * Von Hand wie OneProjectionTest: 100 kWh × 30 ct + 10 € = 40 € im Monat, Abschlag 50 €, Abrechnung 31.01.2027,
     * bis dahin drei Abschläge: 12 × (40 € − 50 €) = −120 €, also 50 € − 120 € ÷ 3 = 10 €.
     */
    public function testTheAmountIsTheSuggestionOfTheBalanceCard(): void
    {
        $today = '2026-10-26';
        $this->settings->set(['billing_cycle_anchor_strom' => '02-01']);
        $meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => 'Z-1', 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($i = 0; $i <= 21; $i++) $rows[] = ['date' => date('Y-m-d', strtotime("2025-01-01 +$i months")), 'counter' => 100.0 * $i, 'device_id' => 'd1'];
        $this->setReadings('strom', $meterId, $rows);
        $this->contracts->delete('strom', $this->stromContract);
        $id = (string)$this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Werk', 'start' => '2025-01-01', 'customer_number' => 'K-1',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 50.0]]])['id'];
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $letters = new LetterService($this->rights, $this->contracts, $this->meters, $bills, consumption: $this->consumption);
        $body = ['utility' => 'strom', 'contract_id' => $id, 'kind' => 'advance_adjustment'] + $this->person();

        $m = $letters->render($body, $today)['model'];
        self::assertSame(10.0, $m['defaults']['extra']['amount_eur'], 'wie die Saldo-Karte');
        self::assertStringContainsString("auf 10,00\u{00A0}€ je Monat festzusetzen", $m['text']);
        self::assertSame([], $m['gaps']);
        // eine eigene Eingabe gilt
        $m = $letters->render($body + ['extra' => ['amount_eur' => 25]], $today)['model'];
        self::assertStringContainsString("auf 25,00\u{00A0}€ je Monat festzusetzen", $m['text']);

        // kaum anders als heute (unter 5 €): Die Karte schweigt, der Brief lässt die Lücke
        $this->contracts->update('strom', $id, ['advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 40.0]]]);
        $m = $letters->render($body, $today)['model'];
        self::assertArrayNotHasKey('amount_eur', $m['defaults']['extra']);
        self::assertContains('amount_eur', $m['gaps']);
    }

    public function testAdvanceRequestWithoutAnyDataStaysShort(): void
    {
        // Strom: Sondervertrag, kein Abschlagsplan, keine Rechnung: nur die Bitte, zwei Lücken, die Norm des Sondervertrags
        $m = $this->contractLetter('strom', $this->stromContract, 'advance_adjustment');
        self::assertStringContainsString('ab dem […] auf […] je Monat festzusetzen', $m['text']);
        self::assertStringNotContainsString('Bisher zahle', $m['text']);
        self::assertStringNotContainsString('Mein Verbrauch', $m['text']);
        self::assertSame(['next_due', 'amount_eur'], $m['gaps']);
        self::assertSame(['de.enwg.41b.3'], array_column($m['norms'], 'key'));
    }

    public function testWaterNamesNoNormAndHeatItsOwn(): void
    {
        // Wasser: privatrechtlich oder Gebührensatzung, das weiß die App nicht (Entscheidung 5 von v3.11.0)
        $m = $this->contractLetter('wasser', $this->createContract('wasser', ['start' => '2025-01-01']), 'advance_adjustment');
        self::assertSame([], $m['norms']);
        self::assertStringNotContainsString('§', $m['text']);
        self::assertSame([], $m['finding']);
        // Fernwärme: § 25 AVBFernwärmeV (Prüfbericht 14), kein EnWG
        $id = $this->createContract('fernwaerme', ['start' => '2020-01-01', 'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 12.0]]]);
        $m = $this->contractLetter('fernwaerme', $id, 'advance_adjustment');
        self::assertSame(['de.avbfernwaermev.25'], array_column($m['norms'], 'key'));
    }

    public function testACountryWithoutRulesKeepsTheNeutralTermination(): void
    {
        $this->settings->set(['country' => 'AT']);
        $contract = $this->contracts->get('strom', $this->stromContract);
        self::assertSame(['termination'], $this->letters->contractKinds('strom', $contract, self::TODAY));
        $m = $this->contractLetter('strom', $this->stromContract, 'termination');
        self::assertFalse($m['rules'], 'der Dialog sagt dann: nur eine Vorlage');
        self::assertSame([], $m['norms']);
        self::assertStringNotContainsString('§', $m['text']);
        self::assertStringContainsString('ordentlich zum nächstmöglichen Termin', $m['text']);
        self::assertSame([422, 'errors.letter.notOfferedContract'], $this->refused(['utility' => 'strom', 'contract_id' => $this->stromContract, 'kind' => 'advance_adjustment']));
        // Fernwärme: die Schriftform steht im deutschen Prüfbericht, nicht für andere Länder
        $id = $this->createContract('fernwaerme', ['start' => '2020-01-01', 'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 12.0]]]);
        self::assertSame('text', $this->contractLetter('fernwaerme', $id, 'termination')['form']);
    }

    public function testAGroupContractNamesAllItsMeters(): void
    {
        $a = (string)$this->meters->create('strom', ['name' => 'HT'])['id'];
        $b = (string)$this->meters->create('strom', ['name' => 'NT'])['id'];
        $g = $this->meters->createGroup('strom', ['name' => 'Wärmepumpe']);
        foreach ([$a, $b] as $id) $this->meters->update('strom', $id, ['meter_group_id' => $g['id']]);
        // setMeterDevices() trifft nur den ersten Zähler der Datei
        $all = $this->store->read('strom/meters.json', []);
        foreach ($all as &$row) {
            $serial = ['HT' => 'HT-1', 'NT' => 'NT-2'][$row['name']] ?? null;
            if ($serial !== null) $row['devices'] = [['id' => 'd_' . $serial, 'serial' => $serial, 'installed_on' => '2020-01-01', 'initial_counter' => 0]];
        }
        unset($row);
        $this->store->write('strom/meters.json', $all);
        $id = (string)$this->contracts->create('strom', ['meter_group_id' => $g['id'], 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-01-01',
            'customer_number' => 'K-1', 'contract_number' => 'V-1', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 20.0]]])['id'];
        $m = $this->contractLetter('strom', $id, 'termination');
        self::assertStringContainsString('Zählernummer: HT-1, NT-2', $m['text']);
    }

    public function testRenderingStoresNothing(): void
    {
        $hash = fn() => hash('sha256', json_encode(array_map(fn($f) => [$f, md5_file($f)], glob($this->dataDir . '/*.json') ?: []) ?: []));
        $before = $hash();
        $this->contractLetter('strom', $this->stromContract, 'termination');
        $this->contractLetter('strom', $this->stromContract, 'advance_adjustment', ['format' => 'pdf']);
        self::assertSame($before, $hash());
    }

    /**
     * Die Anleitung beginnt mit den drei Wegen und nennt die Bezeichnungen, die die App in dieser Sprache wirklich
     * zeigt (aus dem Katalog gelesen, nicht abgeschrieben): Der Maintainer suchte „Brief entwerfen“ vergeblich.
     */
    public function testTheGuideNamesWhatTheAppShows(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['de' => 'docs/anleitungen/brief-schreiben.md', 'en' => 'docs/en/anleitungen/brief-schreiben.md'] as $lang => $file) {
            $cat = json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true, 512, JSON_THROW_ON_ERROR);
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$file"));
            $firstHeading = strpos($text, '## 1.');
            self::assertNotFalse($firstHeading);
            $ways = substr($text, 0, $firstHeading);
            foreach ([$cat['nav']['todo'], $cat['todo']['filter']['rec'], $cat['recommendations']['cat']['recht'], $cat['letters']['button'],
                      $cat['nav']['group']['costs'], $cat['nav']['billCheck'], $cat['level']['advanced'],
                      $cat['nav']['contracts'], $cat['contractsOverview']['manage'], $cat['letters']['write']] as $label) {
                self::assertStringContainsString((string)$label, $ways, "$file: Bezeichnung „{$label}“ aus dem Katalog");
            }
            foreach (['letters.kind.termination', 'letters.kind.advance_adjustment'] as $key) {
                $parts = explode('.', $key);
                self::assertStringContainsString((string)$cat[$parts[0]][$parts[1]][$parts[2]], $text, $file);
            }
        }
    }

    public function testThePdfFitsTheWindowEnvelopeToo(): void
    {
        $m = $this->contractLetter('fernwaerme', $this->createContract('fernwaerme', ['start' => '2018-01-01', 'end' => '2027-12-31',
            'working_prices' => [['from' => '2018-01-01', 'ct_per_kwh' => 12.0]]]), 'termination');
        self::assertStringStartsWith('%PDF-', $this->letters->pdf($m));
    }
}
