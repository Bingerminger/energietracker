<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\InvoicePrices;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Tests\Support\WaterBillFixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1034, vertrag-aus-rechnung) — aus einer Wasser-Jahresrechnung mit
 * wenigen Klicks ein richtiger Vertrag samt Abschlagsplan, und die Rechnung
 * bucht sich danach. Das Testdokument ist erfunden und folgt dem Aufbau einer
 * echten Rechnung (WaterBillFixture): gesperrte Ziffern in der Textebene, drei
 * Sparten netto mit eigenem Steuersatz, Basis- und Bereitstellungspreis,
 * Preiswechsel zum 01.10., Niederschlagswasser nach Fläche, Ablesecodes S und I,
 * Abschläge alle zwei Monate zu genannten Terminen.
 */
#[CoversClass(Evidence::class)]
#[CoversClass(InvoicePrices::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(Applier::class)]
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
final class WaterBillContractTest extends ServiceTestCase
{
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        // der Wasserzähler des Haushalts mit der Nummer der Rechnung; sonst nichts für Wasser
        $this->setMeterDevices('wasser', [['id' => 'd_w1', 'serial' => WaterBillFixture::METER, 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
    }

    private function inbox(string|array $variant = ''): InboxService
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $p = InboxFixture::provider('local', ['Rechnung Nr.' => 'invoice'], ['invoice' => is_array($variant) ? $variant : WaterBillFixture::answer($variant)]);
        $s = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, $p),
            new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $s->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        return $s;
    }

    /** @return array{0: InboxService, 1: array<string,mixed>} */
    private function read(bool $layout = true): array
    {
        $s = $this->inbox();
        $e = $s->upload(InboxFixture::pdf('wasser' . ($layout ? 'l' : 'j')), 'Wasserrechnung.pdf');
        $s->extract($e['id'], WaterBillFixture::pages($layout));
        return [$s, $s->preview($e['id'])];
    }

    private static function byKind(array $proposals, string $kind): array
    {
        return array_values(array_filter($proposals, fn($p) => $p['kind'] === $kind));
    }

    // ── 1. Gesperrte Ziffern ─────────────────────────────────────────────

    public function testSpacedDigitsAreJoinedOnlyWhenTheyAreReallySpaced(): void
    {
        foreach (['3 1 5,5 9 €' => '315,59 €', '-8 0 0 , 0 0 €' => '-800,00 €', '1.0 5 0,2 8 €' => '1.050,28 €', '2 , 1 0 € / m³' => '2,10 € / m³',
                  '1 8 3 Tage' => '183 Tage', '2 0 0 , 0 0 m²' => '200,00 m²', 'fällig am 05.05.2026 4 8,3 0 €' => 'fällig am 05.05.2026 48,30 €',
                  '6 3,0 0 € 1 9 %' => '63,00 € 19 %'] as $spaced => $joined) {
            self::assertSame($joined, Evidence::despace($spaced), $spaced);
        }
        // Zahlen in Tabellenspalten bleiben getrennt: mehrstellig, mehrere Leerzeichen, gewöhnliche Zahlen
        foreach (['35 m³   2,10', '1200  1235 S  35', '1200 1235 S 35', '12345678901 01.04.2025 30.09.2025 1200 1235 S 35', '1 1.8.0', '1 2,50',
                  '5,5', '7% 2,10', 'Seite 1, 2 und 3', '1. 3. 2026', 'QN 2,5\Q3/4,0'] as $plain) {
            self::assertSame($plain, Evidence::despace($plain), $plain);
        }
        // ein Bruchstück der Sperrung („8,3“ aus „4 8,3 0“) ist keine Zahl des Zitats mehr
        self::assertNotContains(8.3, Evidence::numbersIn('Rechnungsforderung fällig am 05.05.2026 4 8,3 0 €'));
        self::assertContains(48.3, Evidence::numbersIn('Rechnungsforderung fällig am 05.05.2026 4 8,3 0 €'));
        // die Steuer „1 9 %“ ist 19 %, nicht 9 %
        self::assertSame([19.0], Evidence::percentsIn('Abwassermenge 3 5 m³ 1 , 8 0 € / m³ 6 3,0 0 € 1 9 %'));
        // die Texte der übrigen Testdokumente enthalten keine Sperrung: unverändert
        foreach ([...InboxFixture::invoicePages(), ...InboxFixture::confirmationPages(), ...InboxFixture::priceChangePages(),
                  ...InboxFixture::meterChangePages()] as $page) {
            self::assertSame($page, Evidence::despace($page));
        }
    }

    public function testTheQuotesOfTheSpacedBillFindTheirValuesInBothTextLayers(): void
    {
        foreach ([true, false] as $layout) {
            $f = Evidence::check('invoice', WaterBillFixture::answer(), WaterBillFixture::pages($layout));
            $label = $layout ? 'pdftotext -layout' : 'pdf.js';
            foreach (['total_amount' => 848.3, 'advances_total' => 800.0, 'result_amount' => 48.3, 'consumption' => 72.0, 'advance_new' => 165.0,
                      'reading_old' => 1200.0, 'reading_new' => 1272.0] as $name => $value) {
                self::assertSame([$value, 'ok', []], [(float)$f[$name]['value'], $f[$name]['status'], $f[$name]['reasons']], "$label: $name");
            }
            self::assertSame('ok', $f['advances_paid']['items'][0]['status'], "$label: geleistete Abschläge");
            self::assertSame(['2026-05-15', '2026-07-15', '2026-09-15', '2026-11-15', '2027-01-15'], array_column($f['advance_new_dates']['value'], 'date'));
            // Preise: nur der Vorbehalt „Preis aus der Rechnung“ (zu prüfen, wenn kein Vertrag ihn kennt), netto mit gelesenem Satz
            foreach ($f['water_prices']['items'] as $i => $it) {
                self::assertSame(['price_from_invoice', 'net_price'], $it['reasons'], "$label: Wasserpreis #$i");
            }
            // die Sparte der Zeilen ohne eigenen Namen aus der Überschrift
            self::assertSame('heading', $f['water_prices']['items'][9]['section_from'] ?? null, 'Bereitstellungspreis Schmutzwasser');
            self::assertSame(['vat_pct' => 19.0, 'gross' => 47.6], $f['water_prices']['items'][9]['net'], '40,00 € netto + 19 %');
        }
    }

    /**
     * Die Textebene, wie der Browser sie seit dem Lesen mit Lage liefert (lib/pdf.js: rechtsbündige Zahlen, die
     * das PDF Zeichen für Zeichen von rechts zeichnet, stehen in der richtigen Reihenfolge, ohne Sperrung): Alle
     * Werte sind belegt, und der ganze Weg führt zum Vertrag mit Preisen und Abschlagsplan und zur gebuchten Rechnung.
     */
    public function testTheTextAsTheBrowserReadsItWithPositionsGoesAllTheWayToContractPricesAndPlan(): void
    {
        $pages = WaterBillFixture::pages('browser');
        foreach ($pages as $i => $page) self::assertSame($page, Evidence::despace($page), "Seite $i: keine Sperrung mehr");
        self::assertStringContainsString('Rechnungsbetrag 848,30 €', $pages[0]);
        self::assertStringContainsString('Wasserverbrauch 01.04.2025 - 30.09.2025 35 m³ 2,10 €/m³ 73,50 € 7%', $pages[1]);
        $f = Evidence::check('invoice', WaterBillFixture::answer('browser'), $pages);
        foreach (['total_amount' => 848.3, 'advances_total' => 800.0, 'result_amount' => 48.3, 'consumption' => 72.0, 'advance_new' => 165.0,
                  'reading_old' => 1200.0, 'reading_new' => 1272.0] as $name => $value) {
            self::assertSame([$value, 'ok', []], [(float)$f[$name]['value'], $f[$name]['status'], $f[$name]['reasons']], $name);
        }
        foreach ($f['water_prices']['items'] as $i => $it) self::assertSame(['price_from_invoice', 'net_price'], $it['reasons'], "Wasserpreis #$i");
        self::assertSame(['2026-05-15', '2026-07-15', '2026-09-15', '2026-11-15', '2027-01-15'], array_column($f['advance_new_dates']['value'], 'date'));

        $s = $this->inbox('browser');
        $e = $s->upload(InboxFixture::pdf('wasserb'), 'Wasserrechnung.pdf');
        $s->extract($e['id'], $pages);
        $p = $s->preview($e['id']);
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertSame([true, []], [$c['checked'], $c['open']], 'Vertrag mit Preisen und Abschlag, nichts offen');
        $rows = fn(array $list, string $key) => array_column(array_map(fn($r) => [$r['from'], round((float)$r[$key], 4)], $list), 1, 0);
        self::assertEqualsWithDelta(WaterBillFixture::GROSS['trinkwasser']['working'], $rows($c['data']['trinkwasser']['working_prices'], 'ct_per_m3'), 1e-9);
        self::assertEqualsWithDelta(WaterBillFixture::GROSS['schmutzwasser']['base'], $rows($c['data']['schmutzwasser']['base_prices'], 'eur_per_month'), 1e-4);
        self::assertSame([2, 165.0], [$c['data']['advance_payments'][2]['every_months'] ?? null, (float)$c['data']['advance_payments'][2]['amount_eur']]);
        $res = $s->apply($e['id'], array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $ct = $this->contracts->list('wasser')[0];
        $b = $this->bills->list('wasser')[0];
        self::assertSame([$ct['id'], 2], [$b['contract_id'], $ct['advance_payments'][2]['every_months'] ?? null]);
        self::assertNotEmpty($b['special_payment_id'], 'gebucht');
    }

    // ── Rhythmus des neuen Abschlags: Feld und Termine ───────────────────

    /**
     * Dieselbe Rechnung mit anderen Terminen und einer anderen Antwort zum Rhythmus (erfunden, nach dem Befund
     * am echten Dokument: fünf Termine im Abstand von zwei Monaten, einer um ein Wochenende verschoben).
     *
     * @param list<string> $dates wie gedruckt (TT.MM.JJJJ)
     */
    private function readRhythm(mixed $every, array $dates): array
    {
        $line = implode(', ', $dates);
        $pages = array_map(fn(string $p) => str_replace('15.05.2026, 15.07.2026, 15.09.2026, 15.11.2026, 15.01.2027', $line, $p), WaterBillFixture::pages(false));
        $a = WaterBillFixture::answer();
        $a['advance_new_from'] = ['value' => $dates[0], 'quote' => $line, 'page' => 1];
        $a['advance_new_dates'] = ['value' => array_map(fn($d) => ['date' => $d, 'quote' => $line, 'page' => 1], $dates), 'quote' => null, 'page' => null];
        $a['advance_new_every'] = ['value' => $every, 'quote' => $every === null ? null : $line, 'page' => $every === null ? null : 1];
        $s = $this->inbox($a);
        $e = $s->upload(InboxFixture::pdf('wr' . md5(json_encode([$every, $dates]))), 'Wasserrechnung.pdf');
        $s->extract($e['id'], $pages);
        $p = $s->preview($e['id']);
        return [$p, self::byKind($p['proposals'], 'contract_create')[0]];
    }

    private const SHIFTED = ['16.10.2026', '16.12.2026', '15.02.2027', '16.04.2027', '16.06.2027'];
    private const SHIFTED_ISO = ['2026-10-16', '2026-12-16', '2027-02-15', '2027-04-16', '2027-06-16'];

    public function testTheModelSaysBimonthlyAndADateIsShiftedByAWeekend(): void
    {
        foreach (['bimonthly', 'zweimonatlich', 'alle 2 Monate', 2, '2'] as $every) {
            [$p, $c] = $this->readRhythm($every, self::SHIFTED);
            self::assertSame('bimonthly', $p['entry']['extraction']['fields']['advance_new_every']['value'], json_encode($every) . ': tolerant gelesen');
            self::assertSame(['from' => '2026-10-16', 'amount_eur' => 165.0, 'every_months' => 2, 'due_dates' => self::SHIFTED_ISO], $c['data']['advance_payments'][2], json_encode($every));
            $line = array_values(array_filter($c['params']['lines'], fn($l) => $l['kind'] === 'advance'))[0];
            self::assertSame(2, $line['every'], 'im Vorschlag „alle 2 Monate“, nicht monatlich');
            self::assertSame([true, false], [$c['checked'], $c['check']]);
        }
    }

    public function testWithoutARhythmTheDatesGiveIt(): void
    {
        [, $c] = $this->readRhythm(null, self::SHIFTED);
        self::assertSame([2, self::SHIFTED_ISO, true], [$c['data']['advance_payments'][2]['every_months'] ?? null, $c['data']['advance_payments'][2]['due_dates'], $c['checked']]);
        // drei Monate, Monatsletzte: 31.01., 30.04., 31.07.
        [, $q] = $this->readRhythm(null, ['31.01.2027', '30.04.2027', '31.07.2027']);
        self::assertSame(3, $q['data']['advance_payments'][2]['every_months'] ?? null);
        // ungleichmäßig: nur die Termine, kein Rhythmus geraten
        [, $u] = $this->readRhythm(null, ['16.10.2026', '16.11.2026', '16.02.2027']);
        self::assertArrayNotHasKey('every_months', $u['data']['advance_payments'][2]);
        self::assertTrue($u['checked']);
    }

    public function testMonthlyAgainstDatesTwoMonthsApartTakesTheDatesAndIsNotPreselected(): void
    {
        [$p, $c] = $this->readRhythm('monthly', self::SHIFTED);
        self::assertSame(2, $c['data']['advance_payments'][2]['every_months'] ?? null, 'die Termine gelten');
        self::assertSame([false, true], [$c['checked'], $c['check']], 'Widerspruch: nicht vorbelegt');
        self::assertContains(['field' => 'advance_new_every', 'reason' => 'rhythm_conflict'], $c['hints']);
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertSame($c['id'], $book['requires'] ?? null, 'Buchen nur mit dem Vertrag');
        // ein bekannter Rhythmus ohne Termine bleibt; ein unbekannter wird nicht geraten
        [, $q] = $this->readRhythm('ogni tre mesi', ['16.10.2026']);
        self::assertSame([3, true], [$q['data']['advance_payments'][2]['every_months'] ?? null, $q['checked']]);
        [, $x] = $this->readRhythm('bimensuel', ['16.10.2026']);
        self::assertArrayNotHasKey('every_months', $x['data']['advance_payments'][2]);
    }

    public function testASectionFromAnotherHeadingIsNotTaken(): void
    {
        // dieselbe Zeile, aber als Trinkwasser ausgegeben: Sie steht unter der Überschrift „Schmutzwasser“
        $a = WaterBillFixture::answer();
        $a['water_prices']['value'][9]['section'] = 'trinkwasser';
        $f = Evidence::check('invoice', $a, WaterBillFixture::pages());
        self::assertContains('value_not_in_quote', $f['water_prices']['items'][9]['reasons']);
        self::assertArrayNotHasKey('section_from', $f['water_prices']['items'][9]);
    }

    // ── 2. Ablesecodes je Stand ──────────────────────────────────────────

    public function testOnlyTheReadingWithTheCodeSIsEstimated(): void
    {
        [, $p] = $this->read();
        $f = $p['entry']['extraction']['fields'];
        self::assertFalse($f['reading_old_estimated']['value'], '1200 (Anfang) ist nicht geschätzt, das S gehört zu 1235');
        self::assertFalse($f['reading_new_estimated']['value'], 'I = Internetablesung ist keine Schätzung');
        $mr = $f['meter_readings']['items'][0];
        self::assertEquals([[false, false], [['date' => '2025-09-30', 'counter' => 1235.0, 'estimated' => true]]], [$mr['estimated_ends'], $mr['between']],
            'zusammengefasst: der Stand vom 30.09. mit S, der gleiche Stand am 01.10. ist derselbe');
        $readings = array_column(array_map(fn($r) => [$r['data']['date'], $r], self::byKind($p['proposals'], 'reading')), 1, 0);
        ksort($readings);
        self::assertSame(['2025-04-01', '2025-09-30', '2026-03-31'], array_keys($readings), 'keine zweite Ablesung am 01.10.');
        self::assertSame([false, true, false], array_map(fn($r) => !empty($r['data']['is_estimated']), array_values($readings)));
        self::assertSame(['inbox.proposal.reading', 'inbox.proposal.readingEstimated', 'inbox.proposal.reading'], array_column(array_values($readings), 'label_key'));
    }

    // ── 3. Vertrag aus der Rechnung ──────────────────────────────────────

    public function testTheBillProposesTheWaterContractWithPricesPerSectionAndThePlan(): void
    {
        [, $p] = $this->read();
        $c = self::byKind($p['proposals'], 'contract_create');
        self::assertCount(1, $c);
        $c = $c[0];
        self::assertSame(['new', false, true], [$c['status'], $c['check'], $c['checked']], 'kein Vertrag am Zähler: vorbelegt');
        $d = $c['data'];
        self::assertSame([WaterBillFixture::SUPPLIER, WaterBillFixture::ACCOUNT, '2025-04-01'], [$d['provider'], $d['contract_number'], $d['start']]);
        self::assertArrayNotHasKey('end', $d, 'Jahresrechnung: der Vertrag läuft weiter');
        $g = WaterBillFixture::GROSS;
        $rows = fn(array $list, string $key) => array_column(array_map(fn($r) => [$r['from'], round((float)$r[$key], 4)], $list), 1, 0);
        self::assertEqualsWithDelta($g['trinkwasser']['working'], $rows($d['trinkwasser']['working_prices'], 'ct_per_m3'), 1e-9, 'Trinkwasser je m³ brutto');
        self::assertEqualsWithDelta($g['trinkwasser']['base'], $rows($d['trinkwasser']['base_prices'], 'eur_per_month'), 1e-4, 'Basis + Bereitstellung je Monat');
        self::assertEqualsWithDelta($g['schmutzwasser']['working'], $rows($d['schmutzwasser']['working_prices'], 'ct_per_m3'), 1e-9);
        self::assertEqualsWithDelta($g['schmutzwasser']['base'], $rows($d['schmutzwasser']['base_prices'], 'eur_per_month'), 1e-4, 'Grundpreis auch für Schmutzwasser');
        self::assertSame('trinkwasser', $d['schmutzwasser']['basis']);
        self::assertEqualsWithDelta($g['niederschlagswasser']['rate'], $rows($d['niederschlagswasser']['rates'], 'eur_per_m2_year'), 1e-9);
        self::assertSame([200.0, 200.0], array_column($d['niederschlagswasser']['rates'], 'versiegelte_flaeche_m2'), 'Fläche aus der Rechnung');
        // v3.9.0 (Skeptiker H2) — davor die laut Rechnung gezahlten 800 € des Zeitraums: 800 ÷ 12 Monate je Monat
        // ab 01.04.2025 (die Rechnung nennt nur die Summe), ab 01.04.2026 bis zum neuen Plan kein Abschlag
        self::assertSame([['from' => '2025-04-01', 'amount_eur' => 66.6667], ['from' => '2026-04-01', 'amount_eur' => 0.0],
            ['from' => '2026-05-15', 'amount_eur' => 165.0, 'every_months' => 2,
            'due_dates' => ['2026-05-15', '2026-07-15', '2026-09-15', '2026-11-15', '2027-01-15']]], $d['advance_payments'],
            'der neue Abschlagsplan: je Termin, alle zwei Monate — nicht still in einen Monatsbetrag umgedeutet');
        // Skeptiker R2-8 — die 0-€-Zeile steht als Hinweis unter dem Vorschlag: 01.04.2026 bis zum Vortag des ersten neuen Termins
        $kinds = fn(array $lines) => array_values(array_map(fn($l) => array_intersect_key($l, array_flip(['kind', 'from', 'to'])),
            array_filter($lines, fn($l) => $l['section'] === null && in_array($l['kind'], ['paid', 'gap', 'advance'], true))));
        self::assertSame([['kind' => 'paid', 'from' => '2025-04-01', 'to' => '2026-03-31'], ['kind' => 'gap', 'from' => '2026-04-01', 'to' => '2026-05-14'],
            ['kind' => 'advance', 'from' => '2026-05-15']], $kinds($c['params']['lines']));
        self::assertSame([], $c['open']);
        // Aufschlüsselung für den Vorschlag: der Grundpreis aus Basis- und Bereitstellungspreis
        $base = array_values(array_filter($c['params']['lines'], fn($l) => $l['section'] === 'trinkwasser' && $l['kind'] === 'base' && $l['from'] === '2025-04-01'))[0];
        self::assertSame(['Basispreis', 'Bereitstellungspreis'], array_column($base['parts'], 'label'));
        self::assertSame([50.0, 100.0], array_column($base['parts'], 'price'));
        self::assertSame([53.5, 107.0], array_column($base['parts'], 'gross'));
        // die Buchung hängt am neuen Vertrag und ist vorbelegt
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertSame([true, []], [$book['checked'], $book['open']]);
        self::assertTrue(self::byKind($p['proposals'], 'bill')[0]['data']['contract_new'] ?? false);
    }

    /**
     * Skeptiker R2-8 — nennt die Rechnung keinen neuen Abschlag, steht ab dem Tag nach dem Zeitraum offen kein
     * Abschlag (0 €), und der Vorschlag sagt das; der alte wird nicht geraten weitergeführt.
     */
    public function testWithoutANewAdvanceTheGapAfterThePeriodIsOpenAndNamed(): void
    {
        $a = WaterBillFixture::answer();
        foreach (['advance_new', 'advance_new_from', 'advance_new_every'] as $k) $a[$k] = ['value' => null, 'quote' => null, 'page' => null];
        $a['advance_new_dates'] = ['value' => [], 'quote' => null, 'page' => null];
        $s = $this->inbox($a);
        $e = $s->upload(InboxFixture::pdf('wgap'), 'Wasserrechnung.pdf');
        $s->extract($e['id'], WaterBillFixture::pages());
        $c = self::byKind($s->preview($e['id'])['proposals'], 'contract_create')[0];
        self::assertSame([['from' => '2025-04-01', 'amount_eur' => 66.6667], ['from' => '2026-04-01', 'amount_eur' => 0.0]], $c['data']['advance_payments']);
        self::assertSame([['section' => null, 'kind' => 'gap', 'from' => '2026-04-01', 'to' => null]],
            array_values(array_filter($c['params']['lines'], fn($l) => $l['kind'] === 'gap')));
    }

    /**
     * „Rechnung buchen“ am Vertrag aus dieser Rechnung geht nur mit ihm (`requires`, wie der
     * Entwurf): ohne den Vertrag abgelehnt, die Rechnung selbst wird trotzdem erfasst, ohne Vertrag.
     */
    public function testBookingOnTheNewContractNeedsTheContract(): void
    {
        [$s, $p] = $this->read();
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertSame([$c['id'], true], [$book['requires'] ?? null, $book['checked_with'] ?? null]);
        self::assertArrayNotHasKey('requires', self::byKind($p['proposals'], 'bill')[0], 'erfassen geht auch ohne Vertrag');
        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked'] && $x['kind'] !== 'contract_create'), 'id');
        $res = $s->apply($p['entry']['id'], $ids);
        self::assertSame([['bill_book', 'errors.inboxDraft.needs.contract']], array_map(fn($f) => [$f['kind'], $f['code']], $res['failed']));
        self::assertSame([], $this->contracts->list('wasser'));
        $b = $this->bills->list('wasser');
        self::assertCount(1, $b);
        self::assertTrue(empty($b[0]['contract_id']) && empty($b[0]['special_payment_id']), 'nicht gebucht, an keinem Vertrag');
    }

    /** Erfasst, aber nicht gebucht: Die Rechnung hängt trotzdem am Vertrag aus ihr (im selben Durchgang angelegt). */
    public function testTheBillBelongsToTheNewContractEvenWithoutBooking(): void
    {
        [$s, $p] = $this->read();
        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked'] && $x['kind'] !== 'bill_book'), 'id');
        $res = $s->apply($p['entry']['id'], $ids);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $c = $this->contracts->list('wasser');
        $b = $this->bills->list('wasser');
        self::assertCount(1, $c);
        self::assertSame([$c[0]['id'], null], [$b[0]['contract_id'] ?? null, $b[0]['special_payment_id'] ?? null], 'am neuen Vertrag, nicht gebucht');
    }

    /** Eine Abschlagsänderung nennt nur den Betrag: der Rhythmus des Vertrags bleibt (nicht still monatlich). */
    public function testAnAdvanceChangeKeepsTheRhythmOfTheContract(): void
    {
        $this->contracts->create('wasser', ['meter_id' => 'm_wasser_default', 'provider' => WaterBillFixture::SUPPLIER, 'start' => '2025-04-01',
            'contract_number' => WaterBillFixture::ACCOUNT, 'trinkwasser' => ['working_prices' => [['from' => '2025-04-01', 'ct_per_m3' => 224.7]]],
            'advance_payments' => [['from' => '2026-05-15', 'amount_eur' => 165, 'every_months' => 2]]]);
        $s = $this->inbox();
        $id = \Energietracker\Tests\Support\DraftFixture::add($this->store, fn($b, $n) => $s->upload($b, $n), 'ac1', 'advance_change', [
            'utility' => 'wasser', 'supplier' => WaterBillFixture::SUPPLIER, 'advance_new' => '180,00', 'valid_from' => '15.09.2026',
            'contract_number' => WaterBillFixture::ACCOUNT]);
        $pv = $s->preview($id);
        $a = self::byKind($pv['proposals'], 'contract_advance');
        self::assertCount(1, $a, json_encode(array_column($pv['proposals'], 'kind')));
        self::assertSame(['2026-09-15', 180.0, ['every_months' => 2], 2], [$a[0]['data']['from'], (float)$a[0]['data']['value'], $a[0]['data']['row'] ?? null, $a[0]['params']['every'] ?? null]);
        $s->apply($id, [$a[0]['id']]);
        $plan = $this->contracts->list('wasser')[0]['advance_payments'];
        self::assertEquals([['from' => '2026-05-15', 'amount_eur' => 165, 'every_months' => 2], ['from' => '2026-09-15', 'amount_eur' => 180, 'every_months' => 2]], $plan);
    }

    public function testWithAContractOfAnotherSupplierItIsOnlyAnOffer(): void
    {
        $this->contracts->create('wasser', ['meter_id' => 'm_wasser_default', 'provider' => 'Wasserwerk Beispielstadt', 'start' => '2024-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200]]]]);
        [, $p] = $this->read();
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertSame(['overlap', false], [$c['status'], $c['checked']], 'überschneidet sich: nur ein Angebot');
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertSame([false, [['field' => 'contract', 'reason' => 'missing']]], [$book['checked'], $book['open']]);
    }

    public function testAContractOfTheSupplierThatEndsEarlyGetsAFollowUpOfferNotAChange(): void
    {
        // Vertrag des Versorgers bis 30.11.2025, gekündigt: die Rechnung reicht bis 31.03.2026
        $old = $this->contracts->create('wasser', ['meter_id' => 'm_wasser_default', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2024-04-01',
            'end' => '2025-11-30', 'auto_renews' => false,
            'trinkwasser' => ['working_prices' => [['from' => '2024-04-01', 'ct_per_m3' => 224.7]], 'base_prices' => [['from' => '2024-04-01', 'eur_per_month' => 13.375]]]]);
        [, $p] = $this->read();
        self::assertSame($old['id'], $p['assignment']['contract_id'], 'der Vertrag des Versorgers rechnet den Zeitraum überwiegend ab');
        $c = self::byKind($p['proposals'], 'contract_create');
        self::assertCount(1, $c);
        self::assertSame(['2025-12-01', false, 'inbox.proposal.contractFromInvoice'], [$c[0]['data']['start'], $c[0]['checked'], $c[0]['label_key']],
            'Folgevertrag ab dem ersten Tag ohne Vertrag, nur als Angebot');
        self::assertSame([['from' => '2025-12-01', 'ct_per_m3' => 246.1]], $c[0]['data']['trinkwasser']['working_prices'], 'der Preis, der dann gilt');
        // am bestehenden Vertrag: Preise je Sparte als Zeilen ab ihrem Tag. Wie im Vertrag an dem Tag (hier aus der
        // Zeile vom 01.04.2024): schon vorhanden; ein neuer Preis ist eine neue Zeile zum Prüfen, nichts wird überschrieben
        $wp = self::byKind($p['proposals'], 'contract_water_price');
        $st = fn(string $list) => array_column(array_values(array_filter($wp, fn($x) => $x['data']['list'] === $list)), 'status', 'from');
        $st = fn(string $list) => array_map(fn($x) => [$x['data']['from'], $x['status'], $x['checked']], array_values(array_filter($wp, fn($x) => $x['data']['list'] === $list)));
        self::assertSame([['2025-04-01', 'exists', false], ['2025-10-01', 'new', false]], $st('trinkwasser.working_prices'));
        self::assertSame([['2025-04-01', 'exists', false], ['2025-10-01', 'new', false]], $st('trinkwasser.base_prices'));
        self::assertSame([['2025-04-01', 'new', false], ['2025-10-01', 'new', false]], $st('schmutzwasser.base_prices'), 'der Vertrag kennt keinen');
        self::assertSame([], array_filter($wp, fn($x) => $x['checked']), 'Preise aus der Rechnung am Bestand: nie vorbelegt');
        self::assertSame(['versiegelte_flaeche_m2' => 200.0], array_values(array_filter($wp, fn($x) => $x['data']['list'] === 'niederschlagswasser.rates'))[0]['data']['row']);
        // übernommen: die Zeile ab 01.10.2025 kommt dazu, die vorhandene bleibt
        $s = $this->inbox();
        $pick = array_values(array_filter($wp, fn($x) => $x['data']['list'] === 'trinkwasser.working_prices' && $x['status'] === 'new'))[0];
        $s->apply($p['entry']['id'], [$pick['id']]);
        self::assertSame([['from' => '2024-04-01', 'ct_per_m3' => 224.7], ['from' => '2025-10-01', 'ct_per_m3' => 246.1]],
            $this->contracts->get('wasser', $old['id'])['trinkwasser']['working_prices']);
        self::assertSame([['from' => '2024-04-01', 'eur_per_month' => 13.375]], $this->contracts->get('wasser', $old['id'])['trinkwasser']['base_prices'],
            'der Grundpreis bleibt, wie er war');
    }

    // ── 4. Ende zu Ende: übernehmen, buchen, Saldo ───────────────────────

    public function testTakingOverContractAndBillBooksTheBillAndTheBalanceFollowsThePlan(): void
    {
        $this->settings->set(['billing_cycle_anchor_wasser' => '04-01']);
        [$s, $p] = $this->read();
        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id');
        $res = $s->apply($p['entry']['id'], $ids);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $contracts = $this->contracts->list('wasser');
        self::assertCount(1, $contracts);
        $c = $contracts[0];
        self::assertSame([2, 165.0], [$c['advance_payments'][2]['every_months'] ?? null, (float)$c['advance_payments'][2]['amount_eur']]);
        // Ablesungen: nur der Stand mit S ist geschätzt
        $est = array_column(array_map(fn($r) => [$r['date'], !empty($r['is_estimated'])], $this->readings->list('wasser', 'm_wasser_default')), 1, 0);
        self::assertSame(['2025-04-01' => false, '2025-09-30' => true, '2026-03-31' => false], $est);
        // die Rechnung hängt am neuen Vertrag und ist dort gebucht: Nachzahlung 48,30 €
        $b = $this->bills->list('wasser')[0];
        self::assertSame($c['id'], $b['contract_id']);
        self::assertNotEmpty($b['special_payment_id']);
        $sp = array_values(array_filter($c['special_payments'] ?? [], fn($x) => $x['id'] === $b['special_payment_id']))[0];
        self::assertSame(['nachzahlung_ohne', 48.3, '2026-04-01'], [$sp['kind'], (float)$sp['amount_eur'], $sp['date']]);

        // „Rechnung prüfen“: der Vertrag aus der Rechnung rechnet den Zeitraum wie der Versorger.
        // Mit der Hand: brutto je Sparte (WaterBillFixture) — Trinkwasser 35 × 2,247 + 37 × 2,461 + 6 × 13,375
        // + 6 × 14,6233 = 337,70; Schmutzwasser 35 × 2,142 + 37 × 2,3205 + 6 × 7,9333 + 6 × 8,7267 = 260,78;
        // Niederschlagswasser 6 × 200 × 1,19 ÷ 12 + 6 × 200 × 1,309 ÷ 12 = 249,90; zusammen 848,38 — gegen 848,30
        // der Rechnung (Grundpreise dort nach 183 bzw. 182 von 365 Tagen, hier je Monat; ein Tag am 30.09. zum
        // alten Preis). Abweichung unter 1 %: passt.
        $cmp = $this->bills->compare('wasser', (string)$b['id']);
        self::assertEqualsWithDelta(72.0, (float)$cmp['ours']['m3'], 0.01);
        self::assertEqualsWithDelta(848.3, (float)$cmp['ours']['total'], 0.25);
        self::assertSame('ok', $cmp['verdict']);
        // v3.9.0 (Skeptiker H2) — das Abrechnungsjahr der Rechnung zählt die laut Rechnung gezahlten 800 € (12 × 66,6667),
        // der Saldo dort liegt bei der Nachzahlung der Rechnung (48,30 €; die eigene Rechnung ist gut 8 Cent höher, s. o.)
        $bp = (new \Energietracker\Services\BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills))
            ->forUtility('wasser', false, '2026-07-31');
        $year = array_values(array_filter($bp['periods'], fn($x) => $x['from'] === '2025-04-01'))[0];
        self::assertEqualsWithDelta(800.0, (float)$year['advances'], 0.01, 'nicht 0 €');
        self::assertEqualsWithDelta(48.3, (float)$year['balance'], 0.5);
        self::assertEqualsWithDelta(800.0, (float)$cmp['ours']['advances'], 0.01, '„Rechnung prüfen“ zählt dieselben Abschläge');

        // Saldo am 31.07.2026: ab dem Tag nach der Rechnung (01.04.2026), gebucht ist sie ausgeglichen.
        // Mit der Hand: geschätzt die Tage 01.04.–30.07. mit der Menge derselben Kalendermonate des Vorjahrs
        // (Monatswerte auf 0,1 m³: April 5,8, Mai 6,0, Juni 5,8, Juli 6,0 — 35 m³ auf 182 Tage verteilt; vom Juli
        // 30 von 31 Tagen) zum Preis ab 01.10.2025, Trink- und Schmutzwasser (2,461 + 2,3205) €/m³ = 111,92 €;
        // Grundpreise und Niederschlagswasser je Monat 14,6233 + 8,7267 + 200 × 1,309 ÷ 12 = 45,1667 € für
        // April bis Juni und 30/31 des Juli = 179,21 €; Abschläge die zwei Termine bis heute (15.05., 15.07.) =
        // 330 €. Saldo −38,87 € (Guthaben).
        $energy = (5.8 + 6.0 + 5.8 + 6.0 * 30 / 31) * (2.461 + 2.3205);
        $fixed = 14.6233 + 8.7267 + 200 * 1.309 / 12;
        $base = $fixed * (3 + 30 / 31);
        $row = $this->consumption->contractStatus('wasser', $this->meters->get('wasser', 'm_wasser_default'), '2026-07-31')['contracts'][0];
        self::assertSame('2026-04-01', $row['balance_from']);
        self::assertEqualsWithDelta(330.0, (float)$row['advance_paid'], 0.001, 'zwei Raten bis heute, nicht vier Monatsbeträge');
        self::assertEqualsWithDelta($base, (float)$row['base_to_date'], 0.02);
        self::assertEqualsWithDelta(round($energy + $base - 330, 2), (float)$row['current_balance'], 0.05);
        self::assertSame([2, ['2026-09-15', '2026-11-15', '2027-01-15', '2027-05-15', '2027-07-15']],
            [$row['current_advance_every_months'], $row['next_advance_dates']], 'Termine des nächsten Jahres: nach den genannten jährlich zu denselben Tagen');
        // bis zur nächsten Abrechnung (01.04.2027): drei Raten stehen noch aus — der Vorschlag gilt je Rate
        self::assertEqualsWithDelta(495.0, (float)$row['advance_remaining'], 0.001);
        self::assertNotNull($row['suggested_advance']);
        self::assertEqualsWithDelta(165 + (float)$row['projected_end_balance'] / 3, (float)$row['suggested_advance'], 0.51);
        // der Saldo-Verlauf endet beim erwarteten Saldo (wie bei monatlichen Abschlägen)
        self::assertEqualsWithDelta((float)$row['projected_end_balance'], (float)end($row['balance_path'])['balance'], 0.02);
        // v3.9.0 (Skeptiker M14) — die Sparten auf derselben Grundlage wie „Verbraucht (Stand heute)“, nicht 0,00 €:
        // Trinkwasser Menge × 2,461 + 14,6233 × Monate, Schmutzwasser Menge × 2,3205 + 8,7267 × Monate,
        // Niederschlagswasser 200 × 1,309 ÷ 12 × Monate (Monate = 3 + 30/31, Menge wie oben)
        $vol = 5.8 + 6.0 + 5.8 + 6.0 * 30 / 31;
        $f = 3 + 30 / 31;
        $comp = $row['components'];
        self::assertTrue($comp['to_date'] ?? false);
        self::assertEqualsWithDelta($vol * 2.461 + 14.6233 * $f, (float)$comp['trinkwasser']['total'], 0.05);
        self::assertEqualsWithDelta($vol * 2.3205 + 8.7267 * $f, (float)$comp['schmutzwasser']['total'], 0.05);
        self::assertEqualsWithDelta(200 * 1.309 / 12 * $f, (float)$comp['niederschlagswasser']['total'], 0.05);
        self::assertEqualsWithDelta((float)$row['cost_to_date'], $comp['trinkwasser']['total'] + $comp['schmutzwasser']['total'] + $comp['niederschlagswasser']['total'], 0.03,
            'die Sparten ergeben „Verbraucht (Stand heute)“');
        // v3.9.0 (Skeptiker N9) — nach dem Übernehmen steht die Zuordnung am Vertrag aus dieser Rechnung, festgehalten beim Übernehmen
        $after = $s->preview($p['entry']['id']);
        self::assertSame([$c['id'], false, true], [$after['assignment']['contract_id'], $after['assignment_saved'], $after['assignment_applied']]);
        self::assertSame(0, (int)$after['entry']['extraction']['flags'], 'die Wasserpreise stehen jetzt im Vertrag: keine Marke mehr');
        // v3.9.0 (Skeptiker M13) — „Rechnung prüfen“ des Zeitraums: der Stand vom 31.03. ist der Endstand, keine Lücke
        $bd = $this->consumption->billBreakdown('wasser', $this->meters->get('wasser', 'm_wasser_default'), '2025-04-01', '2026-04-01');
        self::assertSame([0, 72.0], [$bd['totals']['gaps'], (float)$bd['totals']['m3']]);

        // Kernaussage: der neue Abschlag wird je Termin beurteilt. Raten ab 15.05.2026 bis zur nächsten
        // Abrechnung (01.04.2027): fünf (15.05., 15.07., 15.09., 15.11., 15.01.) = 825 € laut Plan; passend
        // wäre (825 € + Endsaldo) ÷ 5 je Termin — nicht je Monat (das wären elf Monate ab Mai)
        $gist = new Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings);
        $pv = $s->preview($p['entry']['id']);
        $v = $gist->advanceVerdict(['entry' => $pv['entry'], 'u' => 'wasser', 'meter' => $this->meters->get('wasser', 'm_wasser_default'),
            'contract' => $c, 'proposals' => [], 'today' => '2026-07-31'], 165.0, '2026-05-15');
        $rec = max(0.0, round((825 + (float)$row['projected_end_balance']) / 5));
        self::assertSame([5, 11, $rec], [$v['rates'] ?? null, $v['months'], $v['recommended']]);
        $g = $gist->describe($pv['entry'], ['utility' => 'wasser', 'meter_id' => 'm_wasser_default', 'contract_id' => $c['id']], [], '2026-07-31');
        $keys = array_column($g['details'] ?? [], 'key');
        self::assertNotEmpty(array_intersect(['inbox.gist.advance.ok', 'inbox.gist.advance.highRate', 'inbox.gist.advance.lowRate'], $keys), json_encode($keys));
        self::assertEmpty(array_intersect(['inbox.gist.advance.high', 'inbox.gist.advance.low'], $keys), 'nicht „im Monat“');
    }
}
