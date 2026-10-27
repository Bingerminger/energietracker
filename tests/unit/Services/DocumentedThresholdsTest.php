<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\HomeAssistant\Todo;
use Energietracker\Services\HomeAssistantNotifyService;
use Energietracker\Services\Inbox\FolderIntake;
use Energietracker\Services\Inbox\InvoicePrices;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\SettingsService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.9.0 (doku) — Zahlen, die die Doku der Pakete v3.9.0 zusagt, an die Konstanten gebunden
 * (Lektion 31: „Was die Doku behauptet, muss ein Test halten“). Beim Gegenlesen hingen diese
 * Sätze an keinem Test: Benachrichtigungen an Home Assistant, Ordner-Takt, Sensor „Zu tun“,
 * Prognose für Monate ohne Vorgeschichte, Abschlagsvorschlag kurz vor der Abrechnung,
 * Energiefluss der PV, Rest-Abrechnung, Rhythmus aus Terminen. Je Satz DE und EN; ändert
 * sich eine Konstante, wird der Test rot, bis die Doku nachgezogen ist.
 */
#[CoversNothing]
final class DocumentedThresholdsTest extends TestCase
{
    private static function flat(string $rel): string
    {
        return (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
    }

    /** @return array<string, mixed> SettingsService::DEFAULTS (privat) */
    private static function defaults(): array
    {
        return (new \ReflectionClassConstant(SettingsService::class, 'DEFAULTS'))->getValue();
    }

    private const WORDS = ['de' => [2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf'],
                           'en' => [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five']];

    /** @return array<string, array{0:string,1:string,2:list<string>,3:list<string>}> Name → Seite DE, Seite EN, Sätze DE, Sätze EN */
    public static function claims(): array
    {
        $per = HomeAssistantNotifyService::MAX_PER_RUN;
        $min = intdiv(FolderIntake::TICK_SECONDS, 60);
        $retryMin = intdiv(HomeAssistantNotifyService::RETRY_S, 60);
        $hour = (int)self::defaults()['ha_notify_hour'];
        $gap = ForecastService::INTERP_GAP_MAX_DAYS;
        $sug = ConsumptionService::SUGGEST_MIN_MONTHS;
        $span = (int)round(PvSummaryService::SAME_SPAN_SHARE * 100);
        $rest = ConsumptionService::REST_EXTRAPOLATE_DAYS;
        $W = self::WORDS;
        return [
            'HA: höchstens fünf je Lauf' => ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md',
                ['Höchstens ' . $W['de'][$per] . ' auf einmal', 'kommen ' . $W['de'][$per - 1] . ' einzeln'],
                ['At most ' . $W['en'][$per] . ' at a time', $W['en'][$per - 1] . ' come one by one']],
            'HA: Wiederholung nach einer halben Stunde' => ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md',
                [$retryMin === 30 ? 'nach einer halben Stunde noch einmal' : "nach $retryMin Minuten"],
                [$retryMin === 30 ? 'tries again after half an hour' : "after $retryMin minutes"]],
            'HA: tägliche Prüfung ab 8 Uhr' => ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md',
                ["(Standard $hour Uhr)"], ["(default $hour o’clock)"]],
            'Ordner: höchstens alle fünf Minuten' => ['docs/anleitungen/posteingang.md', 'docs/en/anleitungen/posteingang.md',
                ['höchstens alle ' . $W['de'][$min] . ' Minuten', 'nach spätestens ' . $W['de'][$min] . ' Minuten im Posteingang'],
                ['at most every ' . $W['en'][$min] . ' minutes', 'within ' . $W['en'][$min] . ' minutes at most']],
            'Ordner in der App für HA: fünf Minuten' => ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md',
                ['nach spätestens ' . $W['de'][$min] . ' Minuten im Posteingang'], ['within ' . $W['en'][$min] . ' minutes at most']],
            'Sensor „Zu tun“: höchstens 50 Punkte' => ['docs/referenz/api.md', 'docs/en/referenz/api.md',
                ['die Punkte, höchstens ' . Todo::MAX_ITEMS . ':'], ['the items, at most ' . Todo::MAX_ITEMS . ':']],
            'Prognose: verteilte Werte nur aus kurzen Abständen' => ['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md',
                ["Verteilte Werte zählen dabei nur aus Abständen bis $gap Tagen"], ["Interpolated values only count from gaps of up to $gap days"]],
            'Prognose: Heizmodell kennt so warme Monate' => ['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md',
                ['mindestens ' . $W['de'][ForecastService::HEAT_SUPPORT_MIN] . ' seiner Punkte mit höchstens 0,5 Gradtagen je Tag mehr'],
                ['at least ' . $W['en'][ForecastService::HEAT_SUPPORT_MIN] . ' of its points with at most 0.5 degree days per day more']],
            'Prognose: Grundlast mindestens die Hälfte des Sommers' => ['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md',
                ['unter der Hälfte der mittleren Tagesrate gemessener Sommermonate'], ['below half the mean daily rate of measured summer months']],
            'Saldo: kein Nachholbetrag unter drei Abschlägen' => ['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md',
                ['Bleiben weniger als ' . $W['de'][$sug] . ' Abschläge bis zur Abrechnung'], ['With fewer than ' . $W['en'][$sug] . ' advance payments left until the bill']],
            'PV: derselbe Zeitraum ab 90 % der Tage' => ['docs/verstehen/12-pv.md', 'docs/en/verstehen/12-pv.md',
                ["weniger als $span % der Tage der längsten Reihe"], ["fewer than $span % of the days of the longest series"]],
            'PV: widersprüchlich ab 0,5 kWh' => ['docs/verstehen/12-pv.md', 'docs/en/verstehen/12-pv.md',
                ['mehr als 0,5 kWh darüber'], ['more than 0.5 kWh above']],
            'Rest-Abrechnung: höchstens 92 Tage fortgeschrieben' => ['docs/verstehen/13-meter-topologie.md', 'docs/en/verstehen/13-meter-topologie.md',
                ["bis zu $rest Tage fort"], ["for up to $rest days"]],
            'Rhythmus: ein Termin bis drei Tage daneben' => ['docs/anleitungen/posteingang.md', 'docs/en/anleitungen/posteingang.md',
                ['ein Termin darf bis zu drei Tage verschoben sein'], ['a date may be shifted by up to three days']],
        ];
    }

    /** @param list<string> $de @param list<string> $en */
    #[DataProvider('claims')]
    public function testTheDocsNameTheNumberTheCodeUses(string $deFile, string $enFile, array $de, array $en): void
    {
        $d = self::flat($deFile);
        $e = self::flat($enFile);
        foreach ($de as $s) self::assertStringContainsString($s, $d, "$deFile: „{$s}“");
        foreach ($en as $s) self::assertStringContainsString($s, $e, "$enFile: “{$s}”");
    }

    /** Die Werte selbst, damit die Sätze oben nicht still mit einer anderen Zahl weiterleben. */
    public function testTheConstantsBehindTheSentences(): void
    {
        self::assertSame(5, HomeAssistantNotifyService::MAX_PER_RUN);
        self::assertSame(1800, HomeAssistantNotifyService::RETRY_S);
        self::assertSame(300, FolderIntake::TICK_SECONDS);
        self::assertSame(50, Todo::MAX_ITEMS);
        self::assertSame(8, self::defaults()['ha_notify_hour']);
        self::assertSame(62, ForecastService::INTERP_GAP_MAX_DAYS);
        self::assertSame(2, ForecastService::HEAT_SUPPORT_MIN);
        self::assertSame(0.5, ForecastService::HEAT_SUPPORT_MARGIN);
        self::assertSame(0.5, ForecastService::HEAT_BASE_MIN_SHARE);
        self::assertSame(3, ConsumptionService::SUGGEST_MIN_MONTHS);
        self::assertSame(0.9, PvSummaryService::SAME_SPAN_SHARE);
        self::assertSame(0.5, PvSummaryService::FEED_ABOVE_GEN_KWH);
        self::assertSame(92, ConsumptionService::REST_EXTRAPOLATE_DAYS);
        // Rhythmus aus Terminen: drei Tage daneben passen noch, vier nicht
        self::assertSame(2, InvoicePrices::rhythmOfDates(['2026-01-15', '2026-03-18', '2026-05-15']));
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-01-15', '2026-03-19', '2026-05-15']));
        // Gesundheitscheck des Images alle 30 Sekunden (Doku Home Assistant und Posteingang)
        $docker = (string)file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
        self::assertSame(2, substr_count($docker, 'HEALTHCHECK --interval=30s'), 'beide Varianten des Images');
        foreach (['docs/anleitungen/home-assistant.md' => 'alle 30 Sekunden', 'docs/en/anleitungen/home-assistant.md' => 'every 30 seconds',
                  'docs/anleitungen/posteingang.md' => 'alle 30 Sekunden', 'docs/en/anleitungen/posteingang.md' => 'every 30 seconds'] as $rel => $s) {
            self::assertStringContainsString($s, self::flat($rel), $rel);
        }
    }
}
