<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\ErrorHandler;
use Energietracker\Services\AttachmentService;
use Energietracker\Support\FieldRules;
use Energietracker\Support\Input;
use Energietracker\Support\TextLimits;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 — Was die API-Referenz über Eingaben zusagt, hält ein Test (Lektion 31), DE und EN: die eine Regel für Zahlen
 * als Text, Feld und Grenze in `detail`, die Arten eines Belegs und die Wirkungen der Funde im Bestand.
 */
#[CoversNothing]
final class InputRulesDocsTest extends TestCase
{
    private static function claim(string $rel, string $name): string
    {
        $md = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $start = strpos($md, "<!-- doc-claim: $name -->");
        self::assertNotFalse($start, "$rel: doc-claim $name fehlt");
        $end = strpos($md, '<!-- /doc-claim -->', $start);
        self::assertNotFalse($end, "$rel: Ende von $name fehlt");
        return substr($md, $start, $end - $start);
    }

    /** @return array<string, array{0:string}> */
    public static function apiDocs(): array
    {
        return ['de' => ['docs/referenz/api.md'], 'en' => ['docs/en/referenz/api.md']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiDocs')]
    public function testNumbersAsTextFollowTheDocumentedRule(string $rel): void
    {
        $t = self::claim($rel, 'number-text');
        foreach (['12,5', '12.5', '2.405,5', '2,405.5'] as $s) self::assertStringContainsString($s, $t, $rel);
        self::assertSame(12.5, Input::decimal('12,5'));
        self::assertNull(FieldRules::counter('12,5'), 'am Zählerstand ist das Komma 400');
        self::assertSame(12.5, FieldRules::counter('12.5'));
        self::assertNull(Input::decimal('2.405,5'));
        self::assertNull(Input::decimal('2,405.5'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiDocs')]
    public function testErrorDetailIsWhatTheReferenceSays(string $rel): void
    {
        $t = self::claim($rel, 'error-detail');
        self::assertStringContainsString('`detail {field, max, length}`', $t);
        self::assertStringContainsString('`detail {field, value, min, max}`', $t);
        self::assertSame(['field' => 'name', 'max' => 120, 'length' => 121], ErrorHandler::fieldDetail(TextLimits::tooLong('name', 120, 121)));
        $d = ErrorHandler::fieldDetail(FieldRules::error('counter', 'initial_counter', 3e13, ['number', 0, 1e12]));
        self::assertSame(['field', 'value', 'min', 'max'], array_keys((array)$d));
        self::assertSame([0, 1000000000000], [$d['min'], $d['max']]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiDocs')]
    public function testTheReceiptKindsAreTheListOfTheServer(string $rel): void
    {
        $t = self::claim($rel, 'attachment-upload');
        preg_match_all('/`([a-z_]+)`/', explode('errors.common.valueInvalid', $t)[0], $m);
        $kinds = array_values(array_unique(array_intersect($m[1], AttachmentService::KINDS)));
        self::assertSame(AttachmentService::KINDS, $kinds, $rel);
        self::assertStringContainsString('errors.common.valueInvalid', $t);
        self::assertStringContainsString('allowed', $t);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiDocs')]
    public function testTheEffectsOfFindingsAreTheOnesTheStockReports(string $rel): void
    {
        $t = self::claim($rel, 'data-issues-parity');
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Services/DataIssues.php');
        foreach (['excluded', 'field', 'default', 'kept'] as $e) {
            self::assertStringContainsString("`$e`", $t, $rel);
            self::assertStringContainsString("'$e'", $src, "DataIssues kennt $e");
        }
        foreach (['reference:', 'too_long:', 'setting:advance_due_day'] as $k) self::assertStringContainsString($k, $t, $rel);
    }
}
