<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\I18nService;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-12) — Was das PDF setzen kann.
 *
 * Die eingebauten PDF-Schriften kennen nur CP1252. Bis v3.0 warf der Writer
 * alles andere weg: „CO₂" wurde „CO", „kWh/m²·a" wurde „kWh/m²-a", Umlaute
 * verschoben rechtsbündige Spalten (gemessen wurden UTF-8-Bytes). Jetzt gilt:
 * Jeder Berichtstext einer Sprache mit `format.pdfCharset = cp1252` kommt
 * verlustfrei an; andere Sprachen gehen über die Druckansicht.
 */
final class PdfCharsetTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> */
    private static function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            $key = $prefix === '' ? (string)$k : "$prefix.$k";
            if (is_array($v)) $out += self::flatten($v, $key);
            else $out[$key] = (string)$v;
        }
        return $out;
    }

    public function testReportTextsSurviveCp1252InEveryPdfLanguage(): void
    {
        $prefixes = ['report.', 'recommendations.', 'utilityNames.', 'meterNames.', 'countries.', 'format.monthsShort',
            'co2split.',  // v3.1.0 (H4) — Anschreiben zur CO₂-Kostenaufteilung
            'evReport.']; // v3.1.0 (H6) — Ladestrom-Nachweis
        $lost = [];
        foreach (glob(self::root() . '/public/locales/*.json') ?: [] as $file) {
            if (basename($file) === 'languages.json') continue;
            $cat = self::flatten((array)json_decode((string)file_get_contents($file), true));
            if (($cat['format.pdfCharset'] ?? 'cp1252') !== 'cp1252') continue;
            $lang = basename($file, '.json');
            foreach ($cat as $key => $value) {
                if (!array_filter($prefixes, fn($p) => str_starts_with($key, $p))) continue;
                $back = iconv('CP1252', 'UTF-8', PdfWriter::toCp1252($value));
                if ($back !== PdfWriter::normalize($value)) $lost[] = "$lang $key: „{$value}“ → „{$back}“";
            }
        }
        self::assertSame([], $lost, "Im PDF verlorene Zeichen:\n" . implode("\n", $lost));
    }

    public function testCharactersAreMappedNotDropped(): void
    {
        $back = fn(string $s) => iconv('CP1252', 'UTF-8', PdfWriter::toCp1252($s));
        self::assertSame('CO2 (kg)', $back('CO₂ (kg)'));
        self::assertSame('kWh/m²·a', $back('kWh/m²·a'), 'Mittelpunkt existiert in CP1252');
        self::assertSame("Total\u{00A0}: 12\u{00A0}%", $back("Total\u{202F}: 12\u{202F}%"), 'schmales Leerzeichen → geschütztes');
        $w = new PdfWriter(false);
        self::assertEqualsWithDelta(7 * 10 * 0.52, $w->textWidth('Énergie', 10), 0.001, 'Zeichen zählen, nicht UTF-8-Bytes');
    }

    public function testLanguageWithoutCp1252IsNotSupported(): void
    {
        $dir = sys_get_temp_dir() . '/et-pdfcs-' . bin2hex(random_bytes(5));
        mkdir("$dir/locales", 0755, true);
        mkdir("$dir/data", 0755, true);
        file_put_contents("$dir/locales/languages.json", json_encode(['de' => 'Deutsch', 'xx' => 'Test']));
        file_put_contents("$dir/locales/de.json", json_encode(['format' => ['pdfCharset' => 'cp1252']]));
        file_put_contents("$dir/locales/xx.json", json_encode(['format' => ['pdfCharset' => 'none']]));
        try {
            $i18n = new I18nService("$dir/locales", new SettingsService(new JsonStore("$dir/data")));
            self::assertTrue($i18n->pdfSupported());
            $i18n->setLocale('xx');
            self::assertFalse($i18n->pdfSupported(), 'format.pdfCharset = none → Druckansicht statt PDF');
        } finally {
            foreach (["$dir/locales/languages.json", "$dir/locales/de.json", "$dir/locales/xx.json"] as $f) @unlink($f);
            foreach (glob("$dir/data/*") ?: [] as $f) @unlink($f);
            @rmdir("$dir/locales"); @rmdir("$dir/data"); @rmdir($dir);
        }
    }

    public function testEveryLanguageDeclaresItsPdfCharset(): void
    {
        foreach (glob(self::root() . '/public/locales/*.json') ?: [] as $file) {
            if (basename($file) === 'languages.json') continue;
            $cat = self::flatten((array)json_decode((string)file_get_contents($file), true));
            self::assertContains($cat['format.pdfCharset'] ?? null, ['cp1252', 'none'], basename($file));
        }
    }
}
