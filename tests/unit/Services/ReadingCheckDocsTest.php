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
            // Nachauftrag (Nachprüfung an echten Daten)
            'zweite Stufe'    => ['höchstens bei der Hälfte und der danach mindestens beim Doppelten', 'at most half and the one after at least twice',
                                  ReadingCheckService::STRONG_RATIO, 2.0],
            'Jahreszeit'      => ['mindestens ein Viertel über oder unter den Wintern daneben', 'at least a quarter above or below the winters next to it',
                                  ReadingCheckService::SEASON_RATIO, 1.25],
            'Jahreszeit, Jahre' => ['in mindestens zwei Jahren gleichsinnig', 'in at least two years, the same way round', ReadingCheckService::SEASON_YEARS, 2.0],
            'Zäsur, Modell'   => ['beim Heizmodell ab vier Abständen', 'for the heating model from four intervals on', ReadingCheckService::LEVEL_AFTER_POINTS, 4.0],
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

    /**
     * v3.9.0 (doku) — „Ausgeprägte Jahreszeiten“: Die Grundlagen sagten bis zum Gegenlesen
     * „der schwächste Monat unter der Hälfte des stärksten“. Die Regel vergleicht aber die
     * Mediane der Halbjahre (April bis September gegen Oktober bis März) mit dem Faktor 2.
     * Satz und Regel hier zusammen: Sommer 10 je Tag, Winter 4,9 ist ausgeprägt, 5,1 nicht;
     * ein einzelner sehr schwacher Wintermonat macht noch keine Jahreszeit (Median).
     */
    public function testMarkedSeasonsCompareTheHalfYearMedians(): void
    {
        $seasonal = new \ReflectionMethod(ReadingCheckService::class, 'seasonal');
        $day = fn(string $d) => intdiv((int)strtotime("$d 12:00:00 UTC"), 86400);
        $months = function (float $summer, float $winter, ?float $january = null) use ($day): array {
            $out = [];
            for ($t = gmmktime(0, 0, 0, 1, 1, 2024); $t < gmmktime(0, 0, 0, 1, 1, 2026); $t = gmmktime(0, 0, 0, (int)gmdate('n', $t) + 1, 1, (int)gmdate('Y', $t))) {
                $mo = (int)gmdate('n', $t);
                $d0 = $day(gmdate('Y-m-d', $t));
                $d1 = $day(gmdate('Y-m-d', gmmktime(0, 0, 0, $mo + 1, 1, (int)gmdate('Y', $t))));
                $rate = $mo >= 4 && $mo <= 9 ? $summer : ($mo === 1 && $january !== null ? $january : $winter);
                $out[] = ['d0' => $d0, 'd1' => $d1, 'days' => $d1 - $d0, 'kwh' => $rate * ($d1 - $d0)];
            }
            return $out;
        };
        self::assertTrue($seasonal->invoke(null, $months(10.0, 4.9)), 'mehr als das Doppelte: ausgeprägt');
        self::assertFalse($seasonal->invoke(null, $months(10.0, 5.1)), 'knapp unter dem Doppelten: nicht ausgeprägt');
        self::assertFalse($seasonal->invoke(null, $months(10.0, 6.0, 1.0)), 'ein schwacher Januar allein: nicht ausgeprägt (Median der Halbjahre)');
        $de = (string)preg_replace('/\s+/u', ' ', self::doc('docs/verstehen/00-overview.md'));
        $en = (string)preg_replace('/\s+/u', ' ', self::doc('docs/en/verstehen/00-overview.md'));
        self::assertStringContainsString('die Tagesrate des Sommerhalbjahrs, April bis September, und die des Winterhalbjahrs liegen um mehr als das Doppelte auseinander, je Halbjahr der Median der Monate', $de);
        self::assertStringContainsString('the daily rate of the summer half, April to September, and that of the winter half are more than a factor of two apart, each half taken as the median of its months', $en);
        self::assertStringNotContainsString('der schwächste Monat unter der Hälfte', $de);
        self::assertStringNotContainsString('the weakest month below half', $en);
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
