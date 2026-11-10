<?php
declare(strict_types=1);

namespace Energietracker\Tests\Config;

use Energietracker\Config\Countries;
use Energietracker\Config\Rights;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\Inbox\PriceChange;
use Energietracker\Services\Rights\BillChecks;
use Energietracker\Services\RightsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.11.0 (F1035, Paket doku) — Die Rechtsangaben der Doku hängen am Code (Lektion 31, 98, 99).
 *
 * `docs/referenz/rechtsgrundlagen.md` (DE, EN) ist der Prüfbericht als Tabelle: je Regel aus
 * `Config\Rights::RULES` genau eine Zeile `| Nr. <!-- doc-claim: rights-rule <schlüssel> --> |
 * Aussage | [Norm](Quelle) | Stand | Status |` mit derselben Norm, Quelle, demselben Stand und
 * Status — und keine Zeile ohne Regel. G-Aussagen beginnen mit „Zu prüfen:“, U-Aussagen mit
 * „Unsicher:“ (nie als Tatsache), Regeln mit `cite: false` tragen „Nicht in App-Texten“.
 * Dazu die Tabellen der Fristen und Preisobergrenzen und, in `docs/verstehen/17-rechte.md`,
 * die Schwellen der App und die Beispiele (mit dem Code nachgerechnet). Die Erklärseite nennt
 * nur Normen, die im Prüfbericht stehen; eine Norm, die nur G-Regeln tragen, steht dort mit
 * „zu prüfen“, eine U-Norm gar nicht.
 */
#[CoversClass(Rights::class)]
final class RightsSourcesDocTest extends TestCase
{
    private const SOURCES = ['de' => 'docs/referenz/rechtsgrundlagen.md', 'en' => 'docs/en/referenz/rechtsgrundlagen.md'];
    private const EXPLAIN = ['de' => 'docs/verstehen/17-rechte.md', 'en' => 'docs/en/verstehen/17-rechte.md'];
    private const MARK = [
        'de' => ['G' => '*Zu prüfen:*', 'U' => '*Unsicher:*', 'nocite' => 'Nicht in App-Texten', 'check' => 'zu prüfen', 'yes' => 'ja', 'fair' => 'angemessen'],
        'en' => ['G' => '*To be checked:*', 'U' => '*Uncertain:*', 'nocite' => 'Not in app texts', 'check' => 'to be checked', 'yes' => 'yes', 'fair' => 'appropriate'],
    ];
    /** Gesetze, deren Paragrafen die Erklärseite nennen darf (Abkürzung wie in Config\Rights). */
    private const LAWS = 'EnWG|StromGVV|GasGVV|AVBFernwärmeV|AVBWasserV|BGB|MsbG|VwGO|VwVfG|AO';
    /** Normen, die nur etwas benennen (eine Vereinbarung), keine Rechtsaussage. */
    private const NAMES_ONLY = ['14a EnWG'];

    /** @return array<string, array{0:string}> */
    public static function langs(): array
    {
        return ['de' => ['de'], 'en' => ['en']];
    }

    private static function read(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    /** @return array<string, array<string,mixed>> */
    private static function rules(): array
    {
        return Rights::RULES['DE'];
    }

    /** @return array<string, array{report:int, text:string, norm:string, url:string, checked_on:string, status:string}> */
    private static function rows(string $lang): array
    {
        $md = self::read(self::SOURCES[$lang]);
        preg_match_all('/^\| (\d+) <!-- doc-claim: rights-rule ([a-z0-9_.]+) --> \| (.+?) \| \[([^\]]+)\]\(([^)\s]+)\) \| (\d{4}-\d{2}-\d{2}) \| ([WSGU]) \|$/mu',
            $md, $m, PREG_SET_ORDER);
        self::assertSame(substr_count($md, 'doc-claim: rights-rule '), count($m), self::SOURCES[$lang] . ': eine Zeile mit doc-claim hat nicht die Form | Nr. | Aussage | [Norm](Quelle) | Stand | Status |');
        $out = [];
        foreach ($m as $r) {
            self::assertArrayNotHasKey($r[2], $out, self::SOURCES[$lang] . ": {$r[2]} steht zweimal");
            $out[$r[2]] = ['report' => (int)$r[1], 'text' => $r[3], 'norm' => $r[4], 'url' => $r[5], 'checked_on' => $r[6], 'status' => $r[7]];
        }
        return $out;
    }

    #[DataProvider('langs')]
    public function testEveryRuleHasItsRowAndEveryRowItsRule(string $lang): void
    {
        $rows = self::rows($lang);
        $rules = self::rules();
        self::assertSame([], array_values(array_diff(array_keys($rules), array_keys($rows))), "$lang: Regeln ohne Zeile im Prüfbericht");
        self::assertSame([], array_values(array_diff(array_keys($rows), array_keys($rules))), "$lang: Zeilen ohne Regel in Config\\Rights");
        foreach ($rules as $key => $r) {
            $row = $rows[$key];
            self::assertSame($r['norm'], $row['norm'], "$lang/$key: Norm");
            self::assertSame($r['url'], $row['url'], "$lang/$key: Quelle");
            self::assertSame($r['checked_on'], $row['checked_on'], "$lang/$key: Stand");
            self::assertSame($r['status'], $row['status'], "$lang/$key: Status");
            self::assertSame($r['report'], $row['report'], "$lang/$key: Zeile im Prüfbericht");
        }
    }

    #[DataProvider('langs')]
    public function testUncertainAndUncheckedStatementsAreNotStatedAsFact(string $lang): void
    {
        $mark = self::MARK[$lang];
        foreach (self::rows($lang) as $key => $row) {
            $r = self::rules()[$key];
            $starts = fn(string $m): bool => str_starts_with($row['text'], $m);
            if ($r['status'] === 'U') self::assertTrue($starts($mark['U']), "$lang/$key: U-Aussage ohne „{$mark['U']}“");
            elseif ($r['status'] === 'G') self::assertTrue($starts($mark['G']), "$lang/$key: G-Aussage ohne „{$mark['G']}“");
            else self::assertFalse($starts($mark['U']) || $starts($mark['G']), "$lang/$key: {$r['status']}-Aussage trägt eine Marke für G/U");
            if (($r['cite'] ?? true) === false) {
                self::assertStringContainsStringIgnoringCase($mark['nocite'], $row['text'], "$lang/$key: cite:false ohne „{$mark['nocite']}“");
            }
        }
    }

    /** @return list<array{0:string,1:string,2:string}> Bezeichnung, erste Zelle, zweite Zelle je doc-claim */
    private static function claimRows(string $md, string $claim): array
    {
        preg_match_all('/^\| (.+?) <!-- doc-claim: ' . $claim . ' (\w+) --> \|(.*)\|$/mu', $md, $m, PREG_SET_ORDER);
        return array_map(fn($r) => [$r[2], $r[1], array_map('trim', explode(' | ', trim($r[3])))], $m);
    }

    /**
     * @return list<int|float> Zahlen einer Zelle: Tausenderpunkt/-komma (drei Ziffern) entfernt,
     *         Dezimalkomma (DE) oder -punkt (EN) mit ein bis zwei Stellen als Kommazahl
     */
    private static function numbers(string $cell): array
    {
        preg_match_all('/\d{1,3}(?:[.,]\d{3})+(?!\d)|\d+(?:[.,]\d{1,2}(?!\d))?/u', $cell, $m);
        return array_map(function (string $n) {
            if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $n)) return (int)str_replace(['.', ','], '', $n);
            return preg_match('/[.,]/', $n) ? (float)str_replace(',', '.', $n) : (int)$n;
        }, $m[0]);
    }

    #[DataProvider('langs')]
    public function testDeadlinesAreTheOnesTheAppCalculates(string $lang): void
    {
        $bill = array_unique(Countries::BILL_DEADLINE_DAYS['DE']);
        self::assertCount(1, $bill, 'Strom und Gas: dieselbe Abrechnungsfrist (sonst zwei Zeilen)');
        $want = ['bill' => (int)reset($bill)] + Rights::DEADLINES['DE'];
        $seen = [];
        foreach (self::claimRows(self::read(self::SOURCES[$lang]), 'rights-deadline') as [$key, , $cells]) {
            self::assertArrayHasKey($key, $want, "$lang: unbekannte Frist $key");
            self::assertSame((int)$want[$key], self::numbers($cells[0])[0] ?? null, "$lang: Frist $key");
            $seen[] = $key;
        }
        self::assertEqualsCanonicalizing(array_keys($want), $seen, "$lang: jede Frist genau einmal");
    }

    #[DataProvider('langs')]
    public function testMeteringCapTableIsTheOneInTheCode(string $lang): void
    {
        $md = self::read(self::SOURCES[$lang]);
        $tables = Rights::METERING_CAPS['DE'];
        usort($tables, fn($a, $b) => strcmp($b['from'], $a['from']));
        $table = $tables[0];
        $mark = self::MARK[$lang];
        $rows = self::claimRows($md, 'rights-metering-cap');
        self::assertSame(array_column($table['cases'], 'case'), array_column($rows, 0), "$lang: Fälle und Reihenfolge");
        $byCase = array_column($table['cases'], null, 'case');
        foreach ($rows as [$case, , $cells]) {
            $c = $byCase[$case];
            self::assertCount(5, $cells, "$lang/$case: Spalten");
            [$kwh, $kw, $p14a, $cap, $norm] = $cells;
            $exp = fn(string $a, string $b): array => array_values(array_filter([$c[$a] ?? null, $c[$b] ?? null], fn($v) => $v !== null));
            self::assertSame($exp('kwh_above', 'kwh_upto'), self::numbers($kwh), "$lang/$case: Jahresverbrauch");
            self::assertSame($exp('kw_above', 'kw_upto'), self::numbers($kw), "$lang/$case: Leistung");
            self::assertSame(!empty($c['para_14a']), $p14a === $mark['yes'], "$lang/$case: § 14a");
            if ($c['cap_eur'] === null) self::assertStringContainsString($mark['fair'], $cap, "$lang/$case: angemessenes Entgelt");
            else self::assertSame((int)$c['cap_eur'], self::numbers($cap)[0] ?? null, "$lang/$case: Obergrenze");
            $rule = self::rules()[$c['rule']];
            self::assertSame("[{$rule['norm']}]({$rule['url']})", $norm, "$lang/$case: Norm");
        }
        // Der Text über der Tabelle (ab der Überschrift) nennt, ab wann sie gilt
        $pos = (int)strpos($md, '<!-- doc-claim: rights-metering-cap');
        $head = (int)strrpos(substr($md, 0, $pos), "\n## ");
        self::assertStringContainsString($table['from'], substr($md, $head, $pos - $head), "$lang: Beginn der Tabelle ({$table['from']}) im Text");
    }

    /** @return array<string, int|float> die Schwellen der App, die die Erklärseite nennt */
    private static function thresholds(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(Rights::class))->getConstants() as $name => $v) {
            if (is_int($v) || is_float($v)) $out[$name] = $v;
        }
        // dazu Schwellen der Prüfungen an anderer Stelle (Fenster, Abschlagsvorschlag, Bonus, Posteingang)
        return $out + ['WINDOW_DAYS' => RightsService::WINDOW_DAYS, 'SUGGEST_MIN_MONTHS' => ConsumptionService::SUGGEST_MIN_MONTHS,
            'BONUS_WINDOW_DAYS' => BillChecks::BONUS_WINDOW_DAYS, 'BONUS_NET_FACTOR' => BillChecks::BONUS_NET_FACTOR,
            'ADVANCE_EXCESS_PCT' => PriceChange::ADVANCE_EXCESS_PCT];
    }

    #[DataProvider('langs')]
    public function testThresholdsOfTheAppAreDocumentedWithTheirValues(string $lang): void
    {
        $want = self::thresholds();
        $seen = [];
        foreach (self::claimRows(self::read(self::EXPLAIN[$lang]), 'rights-threshold') as [$name, , $cells]) {
            self::assertArrayHasKey($name, $want, "$lang: unbekannte Schwelle $name");
            self::assertEquals($want[$name], self::numbers($cells[0])[0] ?? null, "$lang: Schwelle $name");
            $seen[] = $name;
        }
        self::assertEqualsCanonicalizing(array_keys($want), $seen, "$lang: jede Zahl in Config\\Rights (App-Schwelle) und das Fenster genau einmal");
    }

    private static function plusDays(string $d, int $n): string
    {
        return (new \DateTimeImmutable($d, new \DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    #[DataProvider('langs')]
    public function testExamplesAreWhatTheCodeCalculates(string $lang): void
    {
        $kinds = [];
        foreach (self::claimRows(self::read(self::EXPLAIN[$lang]), 'rights-example') as [$kind, , $cells]) {
            preg_match_all('/\d{4}-\d{2}-\d{2}/', $cells[0], $in);
            preg_match_all('/\d{4}-\d{2}-\d{2}/', $cells[1], $out);
            // v3.12.0 — beim später erhaltenen Bescheid zwei Ausgangsdaten: Bescheid und „Erhalten am“
            self::assertCount($kind === 'fee_notice_received' ? 2 : 1, $in[0], "$lang/$kind: Ausgangsdaten");
            $from = $in[0][0];
            $dates = fn(?array $fd): array => $fd === null ? [] : [$fd['known_on'], $fd['nominal_end'], $fd['deadline']];
            $expected = match ($kind) {
                'bill_late' => (function () use ($from) {
                    $deadline = (string)Rights::billDeadline('DE', 'strom', $from);
                    $lastOk = self::plusDays($deadline, Rights::BILL_LATE_GRACE_DAYS);
                    $firstLate = self::plusDays($lastOk, 1);
                    foreach (['strom', 'gas'] as $u) {
                        self::assertNull(Rights::billDaysLate('DE', $u, $from, 'annual', $lastOk));
                        self::assertSame(Rights::BILL_LATE_GRACE_DAYS + 1, Rights::billDaysLate('DE', $u, $from, 'annual', $firstLate));
                    }
                    return [$deadline, $lastOk, $firstLate];
                })(),
                'special_termination' => (function () use ($from) {
                    $deadline = Rights::specialTerminationDeadline($from);
                    $a = (array)Rights::announcement('DE', $from, $from);
                    return [$deadline, Rights::remindOn($deadline), $a['special']['announce_by'], $a['basic']['announce_by']];
                })(),
                // v3.12.0 — dazu das Ende der Weichzone (möglicherweise abgelaufen bis …)
                'fee_notice' => (function () use ($from, $dates) {
                    $fd = Rights::feeNoticeDeadline('DE', $from);
                    return [...$dates($fd), $fd['soft_until'] ?? null];
                })(),
                // v3.12.0 — später erhalten (§ 41 Abs. 2 Satz 3 VwVfG): frühestens ab dem Zugang, möglicherweise
                // abgelaufen bis zum Monat ab dem vierten Tag nach dem Zugang (zur Post spätestens am Zugangstag)
                'fee_notice_received' => (function () use ($from, $in, $dates) {
                    $fd = Rights::feeNoticeDeadline('DE', $from, null, $in[0][1]);
                    self::assertSame('received', $fd['basis']);
                    return [...$dates($fd), $fd['soft_until']];
                })(),
                default => self::fail("$lang: unbekanntes Beispiel $kind"),
            };
            self::assertSame($expected, $out[0], "$lang/$kind ab $from");
            $kinds[$kind] = true;
        }
        self::assertSame(['bill_late', 'special_termination', 'fee_notice', 'fee_notice_received'], array_keys($kinds), "$lang: Beispiele");
    }

    /** @return list<string> „<Paragraf> <Gesetz>“ je genannter Norm, etwa „40c EnWG“ */
    private static function norms(string $text): array
    {
        $text = (string)preg_replace('/\s+/u', ' ', $text);   // Zeilenumbruch mitten in der Norm
        preg_match_all('/§§?\s*(\d+[a-z]?)[^§|]{0,80}?\b(' . self::LAWS . ')\b/u', $text, $m, PREG_SET_ORDER);
        return array_map(fn($x) => "{$x[1]} {$x[2]}", $m);
    }

    /** @return list<string> Absätze und Tabellenzeilen */
    private static function blocks(string $md): array
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', $md) ?: [] as $b) {
            $lines = explode("\n", $b);
            $table = array_filter($lines, fn($l) => str_starts_with($l, '|'));
            $out = array_merge($out, $table ? array_values($table) : [$b]);
        }
        return $out;
    }

    #[DataProvider('langs')]
    public function testTheExplanationCitesOnlyWhatTheReportHolds(string $lang): void
    {
        $md = self::read(self::EXPLAIN[$lang]);
        $byStatus = [];
        foreach (self::rules() as $r) {
            foreach (self::norms($r['norm']) as $n) $byStatus[$r['status']][$n] = true;
            if ($r['status'] === 'U' && str_contains($r['norm'], '§')) {
                self::assertStringNotContainsString($r['norm'], (string)preg_replace('/\s+/u', ' ', $md), "$lang: unsichere Norm „{$r['norm']}“ als Tatsache genannt");
            }
        }
        $known = array_merge(...array_map('array_keys', array_values($byStatus)));
        $sure = array_keys(($byStatus['W'] ?? []) + ($byStatus['S'] ?? []));
        $onlyG = array_diff(array_keys($byStatus['G'] ?? []), $sure);
        foreach (self::blocks($md) as $block) {
            foreach (self::norms($block) as $n) {
                if (in_array($n, self::NAMES_ONLY, true)) continue;
                self::assertContains($n, $known, "$lang: § $n steht nicht im Prüfbericht (Config\\Rights): " . mb_substr($block, 0, 120));
                if (in_array($n, $onlyG, true)) {
                    self::assertStringContainsStringIgnoringCase(self::MARK[$lang]['check'], $block, "$lang: § $n ist nur „G“, der Satz sagt nicht „" . self::MARK[$lang]['check'] . '“');
                }
            }
        }
    }

    #[DataProvider('langs')]
    public function testEveryCheckHasALanguageNeutralAnchor(string $lang): void
    {
        $md = self::read(self::EXPLAIN[$lang]);
        // Der Renderer der App erlaubt in `<a id>` nur [a-z0-9-]: Unterstrich → Bindestrich (recommendations.js)
        foreach (array_keys(RightsService::CHECKS) as $check) {
            $id = 'rights-' . str_replace('_', '-', $check);
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $id);
            self::assertSame(1, substr_count($md, "<a id=\"$id\"></a>"), "$lang: Sprungmarke $id (Empfehlungskarte „Was die App prüft“)");
        }
    }
}
