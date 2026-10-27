<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReadingCheckService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.9.0 (ablesung-pruefen) — Was die Grundlagen über die Prüfung der
 * Zwischenablesungen sagen, hält ein Test (Lektion 31): jede Schwelle in Worten
 * gegen die Konstante, die wirklich greift, DE und EN.
 */
#[CoversNothing]
final class ReadingCheckDocsTest extends TestCase
{
    private static function doc(string $rel): string
    {
        $md = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        // nur der Abschnitt „Ablesung prüfen“ bzw. „Check a reading“
        $start = max((int)strpos($md, '### Ablesung prüfen'), (int)strpos($md, '### Check a reading'));
        self::assertGreaterThan(0, $start, "$rel: Abschnitt fehlt");
        $end = strpos($md, "\n---", $start);
        return substr($md, $start, $end === false ? null : $end - $start);
    }

    /** @return array<string,array{0:string,1:string,2:float|int,3:float}> Satz DE, Satz EN, Konstante, Wert im Satz */
    public static function claims(): array
    {
        return [
            'Verhältnis'      => ['unter 3/4 bzw. über 4/3', 'below 3/4 or above 4/3', ReadingCheckService::RATIO, 4 / 3],
            'Mindestmenge'    => ['mindestens ein Viertel eines typischen Monats', 'at least a quarter of a typical month', ReadingCheckService::MIN_SHARE, 0.25],
            'Streuung'        => ['mindestens drei Streuungen des Modells', 'at least three spreads of the model', ReadingCheckService::SIGMA, 3.0],
            'Summe'           => ['≤ ½ × Versatz', '≤ ½ × offset', ReadingCheckService::SUM_TOLERANCE, 0.5],
            'Blockgröße'      => ['Block von bis zu sechs aufeinanderfolgenden', 'block of up to six consecutive', ReadingCheckService::MAX_BLOCK, 6.0],
            'Blocklänge'      => ['höchstens ein halbes Jahr vom ersten bis zum letzten', 'at most half a year from the first to the last', ReadingCheckService::MAX_BLOCK_DAYS, 183.0],
            'andere Jahre'    => ['mindestens ein Fünftel der Erwartung', 'at least a fifth of the expectation', ReadingCheckService::YEAR_SPREAD_MIN, 0.2],
            'Fenster'         => ['bis ein halbes Jahr davor und danach', 'up to half a year before and after', ReadingCheckService::LOCAL_WINDOW_DAYS, 183.0],
            'Zäsur, Dauer'    => ['mindestens ein Jahr am Stück', 'for at least a year in a row', ReadingCheckService::LEVEL_MIN_DAYS, 365.0],
            'Zäsur, je Abstand' => ['mindestens ein Viertel darüber bzw. darunter', 'at least a quarter above or below', ReadingCheckService::LEVEL_STEP, 1.25],
            'Zäsur, zusammen' => ['zusammen mindestens das Anderthalbfache', 'together at least one and a half times', ReadingCheckService::LEVEL_RATIO, 1.5],
            'Zäsur, danach'   => ['höchstens ein Zehntel der Tage danach', 'at most a tenth of the days afterwards', ReadingCheckService::LEVEL_AFTER_BACK, 0.1],
            'Zäsur, heute'    => ['mindestens einem halben Jahr nach dem Wechsel', 'at least half a year after the change', ReadingCheckService::LEVEL_AFTER_DAYS, 182.0],
        ];
    }

    public function testTheBasicsNameTheThresholdsThatApply(): void
    {
        $de = preg_replace('/\s+/u', ' ', self::doc('docs/verstehen/00-overview.md'));
        $en = preg_replace('/\s+/u', ' ', self::doc('docs/en/verstehen/00-overview.md'));
        foreach (self::claims() as $name => [$deText, $enText, $const, $value]) {
            self::assertEqualsWithDelta($value, (float)$const, 1e-9, "$name: Konstante geändert, Doku nachziehen");
            self::assertStringContainsString(preg_replace('/\s+/u', ' ', $deText), $de, "DE: $name");
            self::assertStringContainsString(preg_replace('/\s+/u', ' ', $enText), $en, "EN: $name");
        }
    }

    /** Die Anleitung nennt die drei Knöpfe und „Eine löschen“ mit den Wörtern der Oberfläche. */
    public function testTheGuideNamesTheButtonsOfTheInterface(): void
    {
        $cat = fn(string $l) => json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/public/locales/$l.json"), true);
        foreach (['de' => 'docs/anleitungen/hinweise-erledigen.md', 'en' => 'docs/en/anleitungen/hinweise-erledigen.md'] as $l => $rel) {
            $md = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
            $c = $cat($l);
            foreach ([$c['readingCheck']['verify']['one'], $c['readingCheck']['billingOnly'], $c['readingCheck']['correct'],
                      $c['readingCheck']['duplicate']['delete'], $c['levelShift']['notNow'],
                      $c['levelShift']['set'], $c['levelShift']['dialog']['confirm'],
                      $c['utility']['readingsTable']['check'], $c['utility']['readingsTable']['billingOnly'],
                      $c['utility']['readingsTable']['verified']] as $label) {
                self::assertStringContainsStringIgnoringCase($label, $md, "$rel: „{$label}“ fehlt");
            }
        }
    }
}
