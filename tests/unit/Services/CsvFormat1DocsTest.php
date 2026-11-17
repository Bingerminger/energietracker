<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 — Was die Doku über das CSV-Format 1 sagt, ist das, was gilt (Lektion 31), DE und EN:
 *   - Spalten kommen nur hinten hinzu (zuletzt in v3.13.0 drei an `readings.csv`); bestehende Spalten, Trenner, Zahlen-
 *     und Datumsform bleiben Byte für Byte (CsvFormatV1Test gegen tests/fixtures/csv-format-1/).
 *   - Die Doku sagte bisher, jede Änderung wäre ein neues Format, „kein Format 1 mehr“, und die FAQ, es bleibe Byte für
 *     Byte, wie es ist. Beides stimmt seit den drei neuen Spalten nicht mehr.
 *   - Die Vergleichsdatei `readings-strom.csv` beginnt mit den neun Spalten von v3.12.0: angehängt, nichts umgestellt.
 */
#[CoversNothing]
final class CsvFormat1DocsTest extends TestCase
{
    /** Kopfzeile von `readings.csv` bis v3.12.0 (neun Spalten). */
    private const HEADER_V312 = 'Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft';

    private static function read(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function claim(string $rel, string $name): string
    {
        $md = self::read($rel);
        $start = strpos($md, "<!-- doc-claim: $name -->");
        self::assertNotFalse($start, "$rel: doc-claim $name fehlt");
        $end = strpos($md, '<!-- /doc-claim -->', $start);
        self::assertNotFalse($end, "$rel: Ende von $name fehlt");
        return substr($md, $start, $end - $start);
    }

    /** @return array<string, array{0:string, 1:string, 2:list<string>, 3:list<string>}> Referenz, FAQ, Wörter für „hinten“, alte Sätze */
    public static function docs(): array
    {
        return [
            'de' => ['docs/referenz/api.md', 'docs/einstieg/faq.md', ['nur hinten'], ['kein Format 1 mehr', 'Byte für Byte, wie es ist']],
            'en' => ['docs/en/referenz/api.md', 'docs/en/einstieg/faq.md', ['only at the end'], ['no longer format 1', 'byte for byte as it is']],
        ];
    }

    #[DataProvider('docs')]
    public function testFormat1GrowsOnlyAtTheEndAndTheDocsSaySo(string $api, string $faq, array $end, array $old): void
    {
        $t = self::claim($api, 'csv-format-1');
        foreach ($end as $w) self::assertStringContainsString($w, $t, $api);
        self::assertStringContainsString('v3.13.0', $t, $api);
        self::assertStringContainsString('CsvFormatV1Test', $t, $api);
        foreach ([$api, $faq] as $rel) {
            foreach ($old as $s) self::assertStringNotContainsString($s, self::read($rel), "$rel: alter Satz");
        }
        $f = self::claim($faq, 'csv-format-1');
        foreach ($end as $w) self::assertStringContainsString($w, $f, $faq);

        // die Kopfzeile, die die Referenz nennt, ist die der Vergleichsdatei, und sie beginnt mit der von v3.12.0
        $golden = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/csv-format-1/readings-strom.csv');
        $head = explode("\r\n", ltrim($golden, "\u{FEFF}"), 2)[0];
        self::assertStringStartsWith(self::HEADER_V312 . ';', $head, 'nur angehängt');
        self::assertStringContainsString("`$head`", self::read($api), "$api: Kopfzeile von readings.csv");
    }
}
