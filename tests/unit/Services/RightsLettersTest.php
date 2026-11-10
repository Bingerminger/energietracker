<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\LetterService;
use Energietracker\Services\Rights\BillChecks;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Services\RightsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (integration) — die Briefe an echten Befunden: Jede Prüfung (A1 bis A7) erzeugt aus
 * erfundenen Daten (Musterstadt) einen Befund über die Prüfungen der Pakete rechnung und vertrag,
 * verdrahtet wie in src/bootstrap.php; LetterService rendert damit den angebotenen Brief. Jede
 * Angabe, die der Brief liest, kommt aus dem Befund, dem Vertrag, dem Zähler oder der Rechnung —
 * eine Lücke „[…]“ ist nur dort erlaubt, wo die App die Angabe nicht kennen kann: die Abgabe im
 * Widerspruch (A7, `levy`, Feld im Dialog). Absender und (bei der Behörde) Empfänger gibt der
 * Dialog an, wie hier.
 */
#[CoversClass(LetterService::class)]
#[CoversClass(BillChecks::class)]
#[CoversClass(ContractChecks::class)]
final class RightsLettersTest extends ServiceTestCase
{
    private BillService $bills;
    private RightsService $rights;
    private LetterService $letters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['country' => 'DE', 'billing_cycle_anchor_strom' => '01-01']);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills);
        $this->rights = new RightsService($this->settings, $this->i18n);
        // verdrahtet wie in src/bootstrap.php (Pakete rechnung und vertrag)
        $bc = new BillChecks($this->bills, $this->contracts, $this->meters, $this->readings, $this->consumption);
        $this->rights->register('bill_late', fn(string $t, string $c): array => $bc->late($t, $c));
        $this->rights->register('bonus_missing', fn(string $t, string $c): array => $bc->bonus($t, $c));
        $this->rights->register('estimate_despite_reading', fn(string $t, string $c): array => $bc->estimate($t, $c));
        $this->rights->register('metering_cap', fn(string $t, string $c): array => $bc->metering($t, $c));
        $this->rights->register('fee_notice_deadline', fn(string $t, string $c): array => $bc->feeNotice($t, $c));
        $cc = new ContractChecks($this->contracts, $this->meters, $this->consumption, $this->bills, $periods, $this->i18n);
        $this->rights->register('advance_high', fn(string $t, string $c): array => $cc->advanceHigh($t, $c));
        $this->rights->register('special_termination', fn(string $t, string $c): array => $cc->specialTermination($t, $c));
        $this->letters = new LetterService($this->rights, $this->contracts, $this->meters, $this->bills);
    }

    /**
     * Prüfung, Brief, erlaubte Lücken, Angaben, die der Brief nennen muss (von Hand aus den Daten unten).
     *
     * @return array<string, array{0:string, 1:string, 2:list<string>, 3:list<string>}>
     */
    public static function checks(): array
    {
        return [
            'A1 Rechnung zu spät'         => ['bill_late', 'complaint', [], ['01.01.2025', '31.12.2025', '01.03.2026', '11.02.2026']],
            'A2 Abschlag zu hoch'         => ['advance_high', 'advance_adjustment', [], ['3.000 kWh', '87,00 €', '01.07.2026', '30,00 ct/kWh']],
            'A3 Sonderkündigung'          => ['special_termination', 'special_termination', [], ['20.05.2026', '01.07.2026', '§ 41 Abs. 5 Satz 4 EnWG']],
            'A4 Bonus fehlt'              => ['bonus_missing', 'complaint', [], ['100,00 €', '20.01.2026', 'Rechnungsnummer: R-2026-0001']],
            'A5 Schätzung trotz Ablesung' => ['estimate_despite_reading', 'estimate_query', [], ['14.190', '14.200', '10.01.2026', 'Zählernummer: Z-1234567']],
            'A6 Messentgelt'              => ['metering_cap', 'complaint', [], ['30,00 €', '25,00 €', '§ 32 Abs. 1 MsbG']],
            'A7 Gebührenbescheid'         => ['fee_notice_deadline', 'objection', ['levy'], ['27.01.2026', 'AZ 12/345']],
        ];
    }

    #[DataProvider('checks')]
    public function testEachCheckGivesALetterWithoutGaps(string $check, string $letter, array $allowed, array $named): void
    {
        $today = $this->scenario($check);
        $found = array_values(array_filter($this->rights->findings($today), fn($f) => $f['check'] === $check));
        self::assertSame([], $this->rights->failed());
        self::assertCount(1, $found, "Befund $check");
        $f = $found[0];
        self::assertSame($letter, $f['letter']);
        $body = ['finding_id' => $f['id'], 'sender' => ['Erika Mustermann', 'Musterweg 1', '12345 Musterstadt'], 'place' => 'Musterstadt'];
        // Messstellenbetreiber und Behörde kennt die App nicht (Konzept §4): Empfänger aus dem Dialog
        if (in_array($check, ['metering_cap', 'fee_notice_deadline'], true)) $body['recipient'] = ['Musterstadt Messwesen', 'Rathausplatz 1', '12345 Musterstadt'];
        if ($check === 'fee_notice_deadline') $body['extra'] = ['confirm_objection' => true];   // Widerspruch bestätigt (Entscheidung 5b)
        $m = $this->letters->render($body, $today)['model'];
        self::assertSame($allowed, $m['gaps'], "Lücken im Brief zu $check:\n" . $m['text']);
        self::assertSame(count($allowed), substr_count($m['text'], '[…]'), $m['text']);
        // die Angaben kommen an (A4: die Rechnungsnummer der Rechnung im Bezug, seit v3.11.0 gespeichert)
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $m['text']);   // Geld mit geschütztem Leerzeichen
        foreach ($named as $s) self::assertStringContainsString($s, $text, "$check nennt „{$s}“");
    }

    /**
     * v3.11.0 (Skeptiker H2) — nach dem Fristende kein Widerspruch: kein Brief am Befund, der Server lehnt ab.
     * v3.11.0 (Nachprüfung NP1) — Bescheid vom 27.01.2026, bekannt 31.01., rechnerisch Samstag 28.02.2026: Die Frist
     * endet am Montag 02.03.2026 (Prüfbericht 60). Am Sonntag 01.03. und am Montag geht der Brief.
     * v3.12.0 — das Ende vom Bescheiddatum ist nur das früheste: bis 09.03. (+ 7 Tage) geht der Brief weiter, der
     * Dialog sagt „möglicherweise abgelaufen“ (`finding.maybe_expired`); ab 10.03. lehnt der Server ab. Umbenannt
     * aus testNoObjectionAfterTheDeadline (lehnte schon am 03.03. ab).
     */
    public function testObjectionInTheSoftZoneAndNoneAfterIt(): void
    {
        $this->scenario('fee_notice_deadline');
        $f = $this->rights->findings('2026-03-10')[0];
        self::assertSame([null, true, '2026-03-02'], [$f['letter'], $f['facts']['expired'], $f['deadline']]);
        try {
            $this->letters->render(['finding_id' => $f['id'], 'kind' => 'objection', 'extra' => ['confirm_objection' => true]], '2026-03-10');
            self::fail('Widerspruch nach der Weichzone');
        } catch (\Energietracker\Http\HttpStatusException $e) {
            self::assertSame([422, 'errors.letter.deadlinePassed'], [$e->status, $e->key]);
        }
        // am rechnerischen Ende (Samstag), am Sonntag und am verschobenen Ende (Montag) geht er noch
        foreach (['2026-02-28', '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-09'] as $day) {
            $on = $this->rights->findings($day)[0];
            self::assertSame(['objection', false], [$on['letter'], $on['facts']['expired']], $day);
            $m = $this->letters->render(['finding_id' => $on['id'], 'extra' => ['confirm_objection' => true]], $day)['model'];
            self::assertSame(['objection', $day > '2026-03-02'], [$m['kind'], $m['finding']['maybe_expired'] ?? null], $day);
        }
    }

    /**
     * v3.11.0 (Skeptiker N2, N3, N12, M6) — der Empfänger an Wortgrenzen auf bis zu drei Zeilen; der Brief zum
     * Messentgelt ohne Kunden- und Vertragsnummer des Lieferanten, mit der Zählernummer; der Dateiname mit dem
     * Namen der Vorlage; „mit den Preisen ab dem …“, wenn sie sich bis zur Rate ändern.
     */
    public function testRecipientRefsFileNameAndPriceBasis(): void
    {
        $long = 'Energievertrieb und Netzservice Musterstadt-Nord Gesellschaft mit beschränkter Haftung';
        self::assertSame(['Energievertrieb und Netzservice Musterstadt-Nord', 'Gesellschaft mit beschränkter Haftung'], LetterService::wrap($long));
        foreach (LetterService::wrap(str_repeat('Ä', 130)) as $l) self::assertLessThanOrEqual(LetterService::LINE_MAX, mb_strlen($l));
        self::assertSame('brief-sonderkuendigung-2026-11-03.pdf', LetterService::fileName('special_termination', '2026-11-03'));

        $today = $this->scenario('metering_cap');
        $this->store->write('strom/contracts.json', array_map(fn($c) => ['provider' => $long] + $c, $this->store->read('strom/contracts.json', [])));
        $f = array_values(array_filter($this->rights->findings($today), fn($x) => $x['check'] === 'metering_cap'))[0];
        $m = $this->letters->render(['finding_id' => $f['id'], 'sender' => ['Erika Mustermann'], 'recipient' => ['Messwesen Musterstadt']], $today)['model'];
        self::assertSame(['meter_serial'], array_column($m['refs'], 'key'), 'nur die Zählernummer: die Nummern des Lieferanten gehören nicht an den Messstellenbetreiber');
        self::assertStringContainsString('weist die Rechnung vom 20.01.2027 umgerechnet', $m['text']);
        self::assertStringNotContainsString('Ihre Rechnung', $m['text']);
        self::assertStringContainsString('§ 111a EnWG', $m['text'], 'Prüfbericht 54 nennt den Messstellenbetreiber');
        self::assertSame('brief-beanstandung-' . $today . '.pdf', $m['file_name']);
    }

    /**
     * v3.11.0 (Skeptiker M6, N2) — eine Erhöhung zum nächsten Fälligkeitstag (01.07.2026, 31 ct): Bemessung
     * (3.000 × 0,31 + 144) ÷ 12 = 89,50 €, 110 € liegen 23 % darüber. Hinweis und Brief sagen „mit den Preisen ab
     * dem 01.07.2026“, nicht „mit den aktuellen Preisen“. Ein langer Versorgername steht im Empfänger umbrochen.
     */
    public function testTheAdvanceLetterNamesThePricesOfTheDueDay(): void
    {
        $today = $this->scenario('advance_high');
        $c = array_values(array_filter($this->contracts->list('strom'), fn($x) => $x['start'] === '2026-01-01'))[0];
        $this->contracts->update('strom', (string)$c['id'], ['provider' => 'Energievertrieb und Netzservice Musterstadt-Nord Gesellschaft mit beschränkter Haftung',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-07-01', 'ct_per_kwh' => 31.0]]]);
        $f = array_values(array_filter($this->rights->findings($today), fn($x) => $x['check'] === 'advance_high'))[0];
        self::assertSame(['rights.advance_high.textFrom', true, 89.5], [$f['text_key'], $f['facts']['prices_change'], $f['facts']['measure_eur']]);
        self::assertStringContainsString('und den Preisen ab dem 01.07.2026 ergibt', $f['text']);
        $m = $this->letters->render(['finding_id' => $f['id'], 'sender' => ['Erika Mustermann']], $today)['model'];
        self::assertStringContainsString('Mit den Preisen ab dem 01.07.2026 (Arbeitspreis 31,00 ct/kWh', $m['text']);
        self::assertSame(['Energievertrieb und Netzservice Musterstadt-Nord', 'Gesellschaft mit beschränkter Haftung'], $m['defaults']['recipient'],
            'an Wortgrenzen statt mitten im Wort auf 60 Zeichen gekürzt');
    }

    /**
     * v3.11.0 (Nachprüfung, klein) — ein neuer Abschlag ab einem späteren Tag (wie nach der Übernahme eines
     * Preisänderungsschreibens): 125 € ab 01.07.2026, heute 10.06. Der Hinweis sagt „Dein Abschlag von 125,00 € ab
     * dem 01.07.2026 liegt 44 % über …“ (125 ÷ 87 − 1 = 43,7 %); bis dahin fehlte der Tag. Ohne späteren Abschlag
     * bleibt es bei „Dein Abschlag von 110,00 € liegt …“.
     */
    public function testTheAdvanceTextNamesTheDayOfALaterRate(): void
    {
        $today = $this->scenario('advance_high');
        $plain = fn(string $s): string => (string)preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $s);   // geschützte Leerzeichen der Beträge
        $f = array_values(array_filter($this->rights->findings($today), fn($x) => $x['check'] === 'advance_high'))[0];
        self::assertStringStartsWith('Dein Abschlag von 110,00 € liegt 26 % über', $plain($f['text']));
        $c = array_values(array_filter($this->contracts->list('strom'), fn($x) => $x['start'] === '2026-01-01'))[0];
        $this->contracts->update('strom', (string)$c['id'], ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 110], ['from' => '2026-07-01', 'amount_eur' => 125]]]);
        $f = array_values(array_filter($this->rights->findings($today), fn($x) => $x['check'] === 'advance_high'))[0];
        self::assertStringStartsWith('Dein Abschlag von 125,00 € ab dem 01.07.2026 liegt 44 % über', $plain($f['text']));
        self::assertSame([125.0, '2026-07-01'], [(float)$f['facts']['rate_eur'], $f['facts']['rate_from']]);
    }

    /** Erfundene Daten, die genau diese Prüfung auslösen; zurück: der Stichtag. */
    private function scenario(string $check): string
    {
        $strom = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => 'Z-1234567', 'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $contract = fn(string $u, array $more) => (string)$this->contracts->create($u, $more + ['meter_id' => $this->meters->defaultId($u),
            'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2025-01-01', 'customer_number' => 'K-4711',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]]])['id'];
        $bill = fn(string $u, array $in) => $this->bills->create($u, $in + ['meter_id' => $this->meters->defaultId($u),
            'period_from' => '2025-01-01', 'period_to' => '2025-12-31']);
        switch ($check) {
            case 'bill_late':
                // Ende 31.12.2025 + 42 Tage = 11.02.2026; Rechnung vom 01.03.2026: 18 Tage danach
                $contract('strom', []);
                $bill('strom', ['issued_on' => '2026-03-01', 'invoice' => ['energy_kwh' => 3000, 'amount_eur' => 1000]]);
                return '2026-03-10';
            case 'advance_high':
                // 3.000 kWh im Jahr × 0,30 € + 12 × 12 € = 1.044 € ÷ 12 = 87 €; Abschlag 110 € (+ 26 %)
                $rows = [];
                for ($d = '2024-12-01'; $d <= '2026-06-10'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) $rows[] = $d;
                $rows[] = '2026-06-10';
                $this->setReadings('strom', $strom, array_map(fn($d) => ['date' => $d, 'device_id' => 'd1',
                    'counter' => 10000.0 + round(3000.0 / 365 * (strtotime($d) - strtotime('2024-12-01')) / 86400, 3)], array_values(array_unique($rows))));
                $bill('strom', ['kind' => 'annual', 'issued_on' => '2026-02-20', 'invoice' => ['energy_kwh' => 3000.0, 'amount_eur' => 1044.0]]);
                $contract('strom', ['start' => '2026-01-01', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
                    'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 110]]]);
                return '2026-06-10';
            case 'special_termination':
                // Erhöhung zum 01.07.2026, Schreiben vom 20.05.2026: Zugang bis 30.06.2026
                $contract('gas', ['supply_kind' => 'special', 'start' => '2026-01-01', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0],
                    ['from' => '2026-07-01', 'ct_per_kwh' => 12.5, 'announced_on' => '2026-05-20']]]);
                return '2026-06-10';
            case 'bonus_missing':
                $cid = $contract('strom', ['signup_bonus_eur' => 100.0]);
                $bill('strom', ['contract_id' => $cid, 'issued_on' => '2026-01-20', 'invoice_number' => 'R-2026-0001',
                    'invoice' => ['energy_kwh' => 3000, 'amount_eur' => 1000]]);
                return '2026-02-01';
            case 'estimate_despite_reading':
                // eigene Stände 20.12.2025 und 10.01.2026; Schätzung 14.190 statt fortgeschrieben 14.104,8
                $contract('strom', []);
                $this->setReadings('strom', $strom, [['date' => '2025-01-01', 'counter' => 12000.0, 'device_id' => 'd1'],
                    ['date' => '2025-12-20', 'counter' => 14000.0, 'device_id' => 'd1'], ['date' => '2026-01-10', 'counter' => 14200.0, 'device_id' => 'd1'],
                    ['date' => '2025-12-31', 'counter' => 14190.0, 'device_id' => 'd1', 'is_estimated' => true]]);
                $bill('strom', ['issued_on' => '2026-01-15', 'reading_estimated' => ['start' => false, 'end' => true]]);
                return '2026-02-01';
            case 'metering_cap':
                // moderne Messeinrichtung, grundzuständig: 30 € im Jahr gegen 25 € (§ 32 Abs. 1 MsbG)
                $contract('strom', []);
                $this->meters->update('strom', $strom, ['metering_device' => 'mme', 'msb_basic' => true]);
                $bill('strom', ['period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'issued_on' => '2027-01-20',
                    'items' => [['label' => 'Messstellenbetrieb', 'amount_eur' => 30.0, 'kind' => 'metering']]]);
                return '2027-02-01';
            case 'fee_notice_deadline':
                // Bescheid vom 27.01.2026: Frist 28.02.2026
                $bill('wasser', ['notice' => ['public_law' => true, 'dated_on' => '2026-01-27', 'remedy' => 'objection', 'file_number' => 'AZ 12/345']]);
                return '2026-02-10';
        }
        self::fail("kein Szenario für $check");
    }
}
