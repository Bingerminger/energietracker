<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\App;
use Energietracker\Services\AttachmentService;
use Energietracker\Support\TextLimits;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 — Zwei Zusagen der Doku hält ein Test (Lektion 31), DE und EN:
 *   - Die Importe, deren Trockenlauf ohne Schreibrecht als Vorschau gilt (API-Referenz, Statuscode 503), sind genau die
 *     Liste des Servers (App::DRY_RUN_POST): jede Route der Doku passt zu einem Muster, jedes Muster hat eine Route.
 *   - Der Dateiname eines Belegs bzw. Dokuments im Posteingang wird über 120 Zeichen vor der Endung gekürzt
 *     (Datenmodell, Höchstlängen); die Zahl ist die Grenze der Namen.
 */
#[CoversNothing]
final class UploadAndDryRunDocsTest extends TestCase
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

    /** @return array<string, array{0:string, 1:string}> API-Referenz, Datenmodell */
    public static function docs(): array
    {
        return ['de' => ['docs/referenz/api.md', 'docs/referenz/datenmodell.md'], 'en' => ['docs/en/referenz/api.md', 'docs/en/referenz/datenmodell.md']];
    }

    #[DataProvider('docs')]
    public function testTheDryRunRoutesOfTheDocsAreTheListOfTheServer(string $api, string $model): void
    {
        $t = self::claim($api, 'dry-run-routes');
        preg_match_all('#`((?:…|/api)/[^`]+)`#u', $t, $m);
        $paths = array_map(fn(string $p): string => str_replace('…', '/api/utility/strom/meters/m_probe', $p), $m[1]);
        self::assertNotEmpty($paths, $api);
        foreach ($paths as $p) {
            self::assertNotEmpty(array_filter(App::DRY_RUN_POST, fn(string $re) => preg_match($re, $p) === 1), "$api: $p fehlt in der Liste");
        }
        foreach (App::DRY_RUN_POST as $re) {
            self::assertNotEmpty(array_filter($paths, fn(string $p) => preg_match($re, $p) === 1), "$api: $re fehlt in der Doku");
        }
    }

    #[DataProvider('docs')]
    public function testTheFileNameRuleIsWhatTheDataModelSays(string $api, string $model): void
    {
        $t = self::claim($model, 'file-name');
        self::assertStringContainsString((string)TextLimits::NAME, $t, $model);
        foreach (['`original_name`', '`inbox` › `name`', '.pdf'] as $s) self::assertStringContainsString($s, $t, $model);
        self::assertSame(str_repeat('s', TextLimits::NAME - 4) . '.pdf', AttachmentService::fileName(str_repeat('s', 150) . '.pdf'));
        self::assertSame(str_repeat('s', TextLimits::NAME), AttachmentService::fileName(str_repeat('s', 150)));
    }
}
