<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Die API-Referenz zu `contract-status` (`cancel_by`, `notice_basis`, weiterlaufende Verträge) hängt am Code.
 * Bis v3.12 stand dort für weiterlaufende Verträge noch „`cancel_by` heute“ (bis v3.3 gültig) und „höchstens ein Monat
 * Frist“ (bis v3.10 gültig), obwohl die App seit v3.11 für sie keinen Stichtag und keinen Wechseltag rechnet.
 * Je Grundlage ein erfundener Vertrag, heute fest 13.11.2026.
 */
#[CoversClass(ContractService::class)]
final class ContractStatusDocsTest extends ServiceTestCase
{
    private const TODAY = '2026-11-13';
    private const DOCS = ['de' => 'docs/referenz/api.md', 'en' => 'docs/en/referenz/api.md'];

    /** @return array<string, array{0:?string, 1:?string}> Grundlage → [cancel_by, switch_date] laut Code */
    private function bases(): array
    {
        $t = fn(array $c, bool $renewed = false, string $u = 'strom') => $this->contracts->switchTiming($c + ['start' => '2024-01-01'], self::TODAY, $renewed, $u, 'DE');
        $all = [
            $t(['end' => '2027-06-30', 'notice_period_months' => 1]),
            $t(['notice_period_months' => 1]),
            $t(['notice_period_months' => 1, 'notice_mode' => 'any_day', 'min_term_end' => '2027-09-30']),
            $t(['end' => '2025-12-31', 'supply_kind' => 'special'], true),
            $t(['end' => '2022-12-31'], true, 'fernwaerme'),
            $t(['end' => '2025-12-31', 'supply_kind' => 'basic'], true),
            $t([]),
        ];
        $out = [];
        foreach ($all as $x) $out[$x['basis']] = [$x['cancel_by'], $x['switch_date']];
        return $out;
    }

    public function testTheCodeHasTheseBasesAndDates(): void
    {
        $b = $this->bases();
        self::assertEqualsCanonicalizing(['fixed_end', 'open_ended', 'min_term', 'renewed', 'renewal_term', 'basic_supply', 'unknown'], array_keys($b));
        // Stichtag nach der Vorwärtsprobe: Ende 30.06.2027, ein Monat → 31.05.2027
        self::assertSame(['2027-05-31', '2027-07-01'], $b['fixed_end']);
        self::assertSame(self::TODAY, $b['open_ended'][0], 'jederzeit kündbar: heute');
        self::assertSame(self::TODAY, $b['min_term'][0]);
        self::assertSame([null, null], $b['renewed'], 'weiterlaufend: kein Stichtag, kein Wechseltag');
        self::assertSame([null, '2026-11-28'], $b['basic_supply'], 'heute + 14 Tage, Wechsel am Tag danach');
        self::assertSame(['2027-03-31', '2028-01-01'], $b['renewal_term'], 'Verlängerung bis 31.12.2027, Kündigung bis 31.03.2027');
        self::assertSame([null, null], $b['unknown']);
    }

    /** @return array<string, array{string}> */
    public static function langs(): array
    {
        return ['de' => ['de'], 'en' => ['en']];
    }

    private static function row(string $md, string $claim): string
    {
        self::assertSame(1, preg_match('/^\|[^\n]*<!-- doc-claim: ' . $claim . ' -->[^\n]*$/m', $md, $m), $claim);
        return $m[0];
    }

    #[DataProvider('langs')]
    public function testTheReferenceNamesEveryBasisAndWhatCancelByIs(string $lang): void
    {
        $md = (string)file_get_contents(dirname(__DIR__, 3) . '/' . self::DOCS[$lang]);
        $b = $this->bases();
        $basis = self::row($md, 'contract-status-notice-basis');
        preg_match_all('/`([a-z_]+)`/', $basis, $named);
        self::assertEqualsCanonicalizing(array_merge(['notice_basis', 'cancel_by'], array_keys($b)), array_unique($named[1]), "$lang: notice_basis nennt genau die Grundlagen des Codes");

        $cancel = self::row($md, 'contract-status-cancel-by');
        $today = $lang === 'de' ? 'heute' : 'today';
        // jederzeit kündbar: heute; ohne datierten Stichtag: null — je Grundlage so, wie der Code rechnet
        [$todayPart, $nullPart] = explode('`null`', $cancel, 2) + [1 => ''];
        foreach ($b as $name => [$cancelBy]) {
            if ($name === 'fixed_end' || $name === 'renewal_term') continue;
            if ($cancelBy === self::TODAY) self::assertStringContainsString("`$name`", $todayPart, "$lang: $name heißt „{$today}“");
            else self::assertStringContainsString("`$name`", $nullPart, "$lang: $name heißt `null`");
        }
        self::assertStringContainsString($today, $todayPart);
        self::assertStringContainsString('Nr. 52', str_replace('no. 52', 'Nr. 52', $cancel), "$lang: Verweis auf den Kündigungsstichtag");
    }

    #[DataProvider('langs')]
    public function testRenewedContractsAreDescribedAsTheyAreNow(string $lang): void
    {
        $md = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . self::DOCS[$lang]));
        $stale = $lang === 'de'
            ? ['weiterlaufenden Verträgen heute', 'höchstens mit einem Monat Frist kündbar', 'kürzeren von eigener Frist und einem Monat']
            : ['and for renewed ones', 'can be cancelled with at most one month', 'shorter of the contract\'s own notice period and one month'];
        foreach ($stale as $s) self::assertStringNotContainsString($s, $md, "$lang: veraltete Aussage „{$s}“");
        $pos = strpos($md, '<!-- doc-claim: contract-status-renewed -->');
        self::assertNotFalse($pos);
        $part = substr($md, $pos, 700);
        foreach (['`renewed`', '`cancel_by`', '`switch_date`', '`null`', '`basic_supply`', '`renewal_term`', $lang === 'de' ? 'heute + 15 Tage' : 'today + 15 days'] as $w) {
            self::assertStringContainsString($w, $part, $lang);
        }
        // „heute + 15 Tage“ ist der Wechseltag der Grundversorgung (heute + 14 Tage Frist, Wechsel am Tag danach)
        self::assertSame(date('Y-m-d', (int)strtotime(self::TODAY . ' +15 days')), $this->bases()['basic_supply'][1]);
    }
}
