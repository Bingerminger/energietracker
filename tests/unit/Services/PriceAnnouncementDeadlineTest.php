<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\Inbox\PriceChange;
use Energietracker\Services\LetterService;
use Energietracker\Services\RightsService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Ankündigung einer Preisänderung (§ 41 Abs. 5 Satz 2 EnWG: Sondervertrag spätestens einen Monat vorher;
 * § 5 Abs. 2 StromGVV/GasGVV: Grundversorgung mindestens sechs Wochen vorher), gerechnet wie der Kündigungsstichtag
 * (Vorwärtsprobe, §§ 187, 188 BGB): Die Frist beginnt am Tag nach dem Schreiben und muss mit dem letzten Tag des alten
 * Preises abgelaufen sein. Rechtzeitig ist ein Schreiben bis zu diesem Tag. Der eine Tag danach, an dem die bis v3.12
 * gebrauchte Rechnung (ein Kalendermonat bzw. 42 Tage Abstand) noch „rechtzeitig“ sagte, heißt „knapp, prüfen“: Eine
 * Entscheidung gibt es nicht, und maßgeblich ist der Zugang beim Kunden. Danach ist es nach beiden Rechnungen zu spät.
 *
 * Handrechnung (eigene Tage): Änderung zum 01.03.2027, alter Preis bis 28.02.2027 (Monatsletzter) → ein Monat vorwärts
 * ab dem Tag nach dem Schreiben endet spätestens am 28.02., wenn das Schreiben vom 31.01. ist (Frist 01.02. bis 28.02.);
 * ein Schreiben vom 01.02. endet am 01.03. = knapp; vom 02.02. = zu spät. Grundversorgung: 28.02. − 42 = 17.01.
 * rechtzeitig, 18.01. knapp (bis v3.12 rechtzeitig), 19.01. zu spät.
 */
#[CoversClass(Rights::class)]
#[CoversClass(ContractChecks::class)]
#[CoversClass(PriceChange::class)]
final class PriceAnnouncementDeadlineTest extends ServiceTestCase
{
    /** @return array<string, array{0:string, 1:string, 2:string, 3:string, 4:string}> Änderung, Art, letzter rechtzeitiger Tag, knapper Tag, erster später Tag */
    public static function boundaries(): array
    {
        return [
            'Sondervertrag zum 01.03. (Februar 28 Tage)'  => ['2027-03-01', 'special', '2027-01-31', '2027-02-01', '2027-02-02'],
            'Sondervertrag zum 01.03. im Schaltjahr'       => ['2028-03-01', 'special', '2028-01-31', '2028-02-01', '2028-02-02'],
            'Sondervertrag zum 01.04. (März 31 Tage)'      => ['2027-04-01', 'special', '2027-02-28', '2027-03-01', '2027-03-02'],
            'Sondervertrag zum 15.05.'                     => ['2027-05-15', 'special', '2027-04-14', '2027-04-15', '2027-04-16'],
            'Sondervertrag zum 01.01. über den Jahreswechsel' => ['2027-01-01', 'special', '2026-11-30', '2026-12-01', '2026-12-02'],
            'Grundversorgung zum 01.03.'                   => ['2027-03-01', 'basic', '2027-01-17', '2027-01-18', '2027-01-19'],
            'Grundversorgung zum 01.03. im Schaltjahr'     => ['2028-03-01', 'basic', '2028-01-18', '2028-01-19', '2028-01-20'],
            'Grundversorgung zum 01.01.'                   => ['2027-01-01', 'basic', '2026-11-19', '2026-11-20', '2026-11-21'],
        ];
    }

    #[DataProvider('boundaries')]
    public function testInTimeTightAndLateAroundTheBoundary(string $from, string $kind, string $inTime, string $tight, string $late): void
    {
        $at = fn(string $d) => Rights::announcement('DE', $d, $from)[$kind];
        self::assertSame($inTime, $at($inTime)['announce_by'], 'letzter rechtzeitiger Tag');
        self::assertSame([false, false], [$at($inTime)['late'], $at($inTime)['tight']], "$inTime rechtzeitig");
        self::assertSame([false, true], [$at($tight)['late'], $at($tight)['tight']], "$tight knapp");
        self::assertSame([true, false], [$at($late)['late'], $at($late)['tight']], "$late zu spät");
        // dieselbe Funktion wie der Kündigungsstichtag (ein Monat bzw. 42 Tage vor dem letzten Tag des alten Preises)
        $lastOld = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        self::assertSame($kind === 'special' ? ContractService::lastNoticeDay($lastOld, 1)
            : (new \DateTimeImmutable($lastOld))->modify('-42 days')->format('Y-m-d'), $inTime);
    }

    /** Fällt der Tag im Vormonat weg (Änderung zum 31.03.), gibt es keinen knappen Tag: 28.02. rechtzeitig, 01.03. zu spät. */
    public function testWithoutATightDayWhenBothReadingsAgree(): void
    {
        $a = Rights::announcement('DE', '2027-02-28', '2027-03-31')['special'];
        self::assertSame(['2027-02-28', false, false], [$a['announce_by'], $a['late'], $a['tight']]);
        $b = Rights::announcement('DE', '2027-03-01', '2027-03-31')['special'];
        self::assertSame([true, false], [$b['late'], $b['tight']]);
    }

    /** Die Rechtsgrundlagen (DE, EN) nennen die Tage, die der Code rechnet (doc-claim: announcement-forward). */
    public function testTheLegalBasisNamesTheDaysTheCodeCalculates(): void
    {
        $s = Rights::announcement('DE', '2027-02-01', '2027-03-01');
        $b = Rights::announcement('DE', '2027-01-18', '2027-03-01');
        self::assertSame(['2027-01-31', true, '2027-01-17', true], [$s['special']['announce_by'], $s['special']['tight'], $b['basic']['announce_by'], $b['basic']['tight']]);
        foreach (['docs/referenz/rechtsgrundlagen.md', 'docs/en/referenz/rechtsgrundlagen.md'] as $rel) {
            $md = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            $start = strpos($md, '<!-- doc-claim: announcement-forward -->');
            self::assertNotFalse($start, $rel);
            $claim = substr($md, $start, (int)strpos($md, '<!-- /doc-claim -->', $start) - $start);
            foreach (['31.01.2027', '17.01.2027', '01.02.2027', '18.01.2027'] as $d) self::assertStringContainsString($d, $claim, "$rel: $d");
        }
    }

    // ── Ansicht gegen Ansicht: „Deine Rechte“, Posteingang, Brief am knappen Tag ─────────────

    private function rights(): array
    {
        $this->settings->set(['country' => 'DE']);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $checks = new ContractChecks($this->contracts, $this->meters, $this->consumption, $bills,
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills), $this->i18n);
        $rights = new RightsService($this->settings, $this->i18n);
        $rights->register('special_termination', fn(string $today, string $country): array => $checks->specialTermination($today, $country));
        return [$rights, new LetterService($rights, $this->contracts, $this->meters, $bills)];
    }

    /** @return array<string,mixed> der Befund zur Preisänderung */
    private function finding(RightsService $rights, string $u, string $kind, string $announced, string $from, string $today): array
    {
        $this->contracts->create($u, ['meter_id' => $this->meters->defaultId($u), 'provider' => 'Stadtwerke Beispielfeld', 'start' => '2026-01-01',
            'supply_kind' => $kind, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0],
            ['from' => $from, 'ct_per_kwh' => 33.5, 'announced_on' => $announced]]]);
        $f = $rights->run('special_termination', $today);
        self::assertCount(1, $f);
        return $f[0];
    }

    /** @return list<string> Sätze des Posteingangs zum selben Schreiben */
    private function inboxLines(string $u, string $kind, string $announced, string $from, string $today): array
    {
        $fields = [];
        foreach (['valid_from' => $from, 'document_date' => $announced, 'working_price_new' => 33.5] as $k => $v) $fields[$k] = ['value' => $v, 'status' => 'ok'];
        $ct = ['id' => 'c_k4b', 'provider' => 'Stadtwerke Beispielfeld', 'start' => '2026-01-01', 'supply_kind' => $kind,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0]]];
        $a = PriceChange::assess(['type' => 'price_change', 'extraction' => ['fields' => $fields]], $u, $ct, null, 'DE', $this->contracts, $today);
        return array_map(fn($l) => substr($l['key'], strlen('inbox.gist.rights.')), PriceChange::lines((array)$a));
    }

    public function testTheTightDayIsTightInRightsInboxAndLetterAlike(): void
    {
        [$rights, $letters] = $this->rights();
        // Sondervertrag, Schreiben vom 01.02.2027 zum 01.03.2027 (bis v3.12: rechtzeitig)
        $f = $this->finding($rights, 'strom', 'special', '2027-02-01', '2027-03-01', '2027-02-10');
        self::assertSame([false, true], [$f['facts']['announced_late'], $f['facts']['announced_tight']]);
        self::assertStringContainsString('knapp', $f['text']);
        self::assertStringContainsString('ankam', $f['text'], 'der Zugang beim Kunden ist maßgeblich');
        self::assertStringNotContainsString('verlangt dafür mindestens einen Monat Vorlauf', $f['text'], 'keine Behauptung „zu spät“');
        self::assertContains('de.enwg.41.5.s1', $f['rules']);
        self::assertContains('de.bgb.188', $f['rules'], 'die Zählung nach §§ 187, 188 BGB ist genannt');
        $lines = $this->inboxLines('strom', 'special', '2027-02-01', '2027-03-01', '2027-02-10');
        self::assertContains('tightSpecial', $lines);
        self::assertNotContains('lateSpecial', $lines);
        // der Brief zur Sonderkündigung behauptet nichts über eine verspätete Ankündigung
        $text = $letters->render(['finding_id' => $f['id'], 'sender' => ['Erika Beispiel', 'Weg 3', '12345 Beispielfeld'], 'place' => 'Beispielfeld'], '2027-02-10')['model']['text'];
        self::assertStringContainsString('01.02.2027', $text);
        self::assertDoesNotMatchRegularExpression('/zu spät|verspätet|knapp/u', $text);
    }

    public function testOneDayEarlierIsInTimeAndOneDayLaterIsLateInBothViews(): void
    {
        [$rights] = $this->rights();
        $f = $this->finding($rights, 'gas', 'basic', '2027-01-19', '2027-03-01', '2027-02-10');
        self::assertSame([true, false], [$f['facts']['announced_late'], $f['facts']['announced_tight']]);
        self::assertStringContainsString('sechs Wochen Vorlauf', $f['text']);
        self::assertContains('lateBasic', $this->inboxLines('gas', 'basic', '2027-01-19', '2027-03-01', '2027-02-10'));
        $this->store->write('gas/contracts.json', []);
        $g = $this->finding($rights, 'gas', 'basic', '2027-01-17', '2027-03-01', '2027-02-10');
        self::assertSame([false, false], [$g['facts']['announced_late'], $g['facts']['announced_tight']]);
        $lines = $this->inboxLines('gas', 'basic', '2027-01-17', '2027-03-01', '2027-02-10');
        self::assertSame([], array_values(array_intersect(['lateBasic', 'tightBasic'], $lines)));
        // knapp in der Grundversorgung: maßgeblich ist die öffentliche Bekanntgabe
        $this->store->write('gas/contracts.json', []);
        $h = $this->finding($rights, 'gas', 'basic', '2027-01-18', '2027-03-01', '2027-02-10');
        self::assertSame([false, true], [$h['facts']['announced_late'], $h['facts']['announced_tight']]);
        self::assertStringContainsString('Bekanntgabe', $h['text']);
        self::assertContains('tightBasic', $this->inboxLines('gas', 'basic', '2027-01-18', '2027-03-01', '2027-02-10'));
    }
}
