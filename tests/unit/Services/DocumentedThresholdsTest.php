<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\Rights\BillChecks;
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
        $supply = BillingPeriodService::SWITCH_PERIOD_MAX_DAYS;   // v3.9.0 (auswertung, Skeptiker M7)
        $start = \Energietracker\Services\Inbox\Proposals::START_READING_DAYS;   // v3.10.0 (korrekturen)
        $warn = (int)self::defaults()['reminder_warn_days_before'];             // v3.10.0 (doku)
        $calib = self::calibrationNotePct();
        $feedDe = str_replace('.', ',', (string)PvSummaryService::FEED_ABOVE_GEN_KWH);
        $weeks = intdiv(self::monthlyAverageMinDays(), 7);
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
                // v3.10.0 (#38) — dazu „genau drei“: die Grenze ist streng
                ['Bleiben weniger als ' . $W['de'][$sug] . ' Abschläge bis zur Abrechnung', 'Bei genau ' . $W['de'][$sug] . ' Abschlägen verteilt die Karte noch'],
                ['With fewer than ' . $W['en'][$sug] . ' advance payments left until the bill', 'With exactly ' . $W['en'][$sug] . ' payments left the card still spreads']],
            'PV: derselbe Zeitraum ab 90 % der Tage' => ['docs/verstehen/12-pv.md', 'docs/en/verstehen/12-pv.md',
                ["weniger als $span % der Tage der längsten Reihe"], ["fewer than $span % of the days of the longest series"]],
            'PV: widersprüchlich ab 0,5 kWh' => ['docs/verstehen/12-pv.md', 'docs/en/verstehen/12-pv.md',
                ['mehr als 0,5 kWh darüber'], ['more than 0.5 kWh above']],
            'Rest-Abrechnung: höchstens 92 Tage fortgeschrieben' => ['docs/verstehen/13-meter-topologie.md', 'docs/en/verstehen/13-meter-topologie.md',
                ["bis zu $rest Tage fort"], ["for up to $rest days"]],
            'Perioden: Belieferung zwischen zwei Wechseln bis 400 Tage' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                ["dauert sie höchstens $supply Tage"], ["lasts at most $supply days"]],
            'Perioden (API): Belieferung zwischen zwei Wechseln bis 400 Tage' => ['docs/referenz/api.md', 'docs/en/referenz/api.md',
                ["höchstens $supply Tage dauert"], ["lasts at most $supply days"]],
            'Rhythmus: ein Termin bis drei Tage daneben' => ['docs/anleitungen/posteingang.md', 'docs/en/anleitungen/posteingang.md',
                ['ein Termin darf bis zu drei Tage verschoben sein'], ['a date may be shifted by up to three days']],
            // v3.10.0 (korrekturen) — Anfangsstand einer Rechnung = deine Ablesung kurz davor
            'Posteingang: Anfangsstand bis drei Tage davor' => ['docs/anleitungen/posteingang.md', 'docs/en/anleitungen/posteingang.md',
                ['höchstens ' . $W['de'][$start] . ' Tage vor dem Tag der Rechnung', 'höchstens ' . $start . ' von 365 Tagen, unter der Toleranz von 1 %'],
                ['at most ' . $W['en'][$start] . ' days before the bill’s date', 'at most ' . $start . ' of 365 days, below the 1 % tolerance']],
            // v3.10.0 (doku) — Sätze der Pakete v3.10.0, die beim Gegenlesen an keinem Test hingen
            'Sensor „Zu tun“: bald fällig mit dem Vorlauf der App' => ['docs/referenz/api.md', 'docs/en/referenz/api.md',
                ["`reminder_warn_days_before` (Standard $warn), ohne Erledigtes"], ["`reminder_warn_days_before` (default $warn), without what is done"]],
            'Sensor „Zu tun“: Vorlauf in der Anleitung' => ['docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md',
                ["`reminder_warn_days_before`, Standard $warn Tage"], ["`reminder_warn_days_before`, default $warn days"]],
            'Sensor „Zu tun“: nächster Termin bis ein Jahr voraus' => ['docs/referenz/api.md', 'docs/en/referenz/api.md',
                [HomeAssistantNotifyService::HORIZON_DAYS === 365 ? 'im Kalender bis ein Jahr voraus' : 'HORIZON_DAYS geändert'],
                [HomeAssistantNotifyService::HORIZON_DAYS === 365 ? 'in the calendar up to one year ahead' : 'HORIZON_DAYS changed']],
            'Kaskade: Widerspruch erst ab 0,5 kWh unter null' => ['docs/referenz/api.md', 'docs/en/referenz/api.md',
                ["ein Strang lag unter null (mehr als $feedDe kWh)"], ['a branch was below zero (by more than ' . PvSummaryService::FEED_ABOVE_GEN_KWH . ' kWh)']],
            'Werkstatt: Satz zum Kalibrierfaktor ab 2 %' => ['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md',
                ["die Mengen der Karte verschiebt (ab $calib %)"], ["shifts the card’s quantities (from $calib %)"]],
            'Werkstatt-Anleitung: Satz zum Kalibrierfaktor ab 2 %' => ['docs/anleitungen/was-waere-wenn.md', 'docs/en/anleitungen/was-waere-wenn.md',
                ["liegen beide mindestens $calib % auseinander"], ["if the two differ by at least $calib %"]],
            'Kachel „Kosten“: Monatsmittel erst ab vier Wochen' => ['docs/referenz/ansichten.md', 'docs/en/referenz/ansichten.md',
                ['nach seinen Tagen und fehlt unter ' . $W['de'][$weeks] . ' Wochen'], ['by its days and is left out below ' . $W['en'][$weeks] . ' weeks']],
            'Heimnetz: Adressen, für die „nicht prüfen“ wirkt' => ['docs/referenz/einstellungen.md', 'docs/en/referenz/einstellungen.md',
                ['127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, `::1` und `fc00::/7`. Für jede andere Adresse wirkungslos.'],
                ['127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, `::1` and `fc00::/7`. No effect for any other address.']],
            'Trendpfeil: dieselbe Grenze wie „Ablesung prüfen“' => ['docs/referenz/ansichten.md', 'docs/en/referenz/ansichten.md',
                ['Es ist dieselbe Grenze wie die zweite Stufe von „Ablesung prüfen“'], ['It is the same limit as the second stage of “Check the reading”']],
            // v3.11.0 (Paket vertrag, A2, Entscheidung 3b) — Abschlag deutlich zu hoch
            'Rechte: Abschlag mehr als 20 % und 10 € über der Bemessung' => ['docs/referenz/ansichten.md', 'docs/en/referenz/ansichten.md',
                ['Liegt die Rate mehr als ' . self::num(\Energietracker\Config\Rights::ADVANCE_HIGH_PCT) . ' % und mindestens '
                    . self::num(\Energietracker\Config\Rights::ADVANCE_HIGH_MIN_EUR) . ' € darüber',
                 'kurz vor der Abrechnung (weniger als ' . $W['de'][$sug] . ' Raten offen)'],
                ['If the instalment is more than ' . self::num(\Energietracker\Config\Rights::ADVANCE_HIGH_PCT) . '% and at least '
                    . self::num(\Energietracker\Config\Rights::ADVANCE_HIGH_MIN_EUR) . ' € above it',
                 'shortly before the bill (fewer than ' . $W['en'][$sug] . ' instalments left)']],
            // v3.11.0 (Paket rechnung) — App-Schwellen der Prüfungen an Rechnungen (Config\Rights, Rights\BillChecks)
            'Rechte: Rechnung spät erst nach zwei Tagen' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                ['mehr als ' . $W['de'][Rights::BILL_LATE_GRACE_DAYS] . ' Tage nach dem Ende der gesetzlichen Frist'],
                ['more than ' . $W['en'][Rights::BILL_LATE_GRACE_DAYS] . ' days after the end of the statutory deadline']],
            'Rechte: Bonus um den Termin, Toleranz, netto' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                ['bis ' . BillChecks::BONUS_WINDOW_DAYS . ' Tage davor oder danach', 'ab dem Bonus minus ' . self::de(Rights::BONUS_TOLERANCE_EUR) . ' €',
                 '(Betrag × ' . self::de(BillChecks::BONUS_NET_FACTOR) . ')'],
                ['up to ' . BillChecks::BONUS_WINDOW_DAYS . ' days before or after', 'from the bonus minus ' . Rights::BONUS_TOLERANCE_EUR . ' €',
                 '(amount × ' . BillChecks::BONUS_NET_FACTOR . ')']],
            'Rechte: Schätzung, Fenster und Abweichung' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                ['höchstens ' . Rights::ESTIMATE_WINDOW_DAYS . ' Tage davor oder danach', 'um mehr als ' . self::de(Rights::ESTIMATE_DEVIATION_PCT) . ' % der Menge des Zeitraums'],
                ['at most ' . Rights::ESTIMATE_WINDOW_DAYS . ' days before or after', 'by more than ' . Rights::ESTIMATE_DEVIATION_PCT . ' % of the period’s quantity']],
            'Rechte: Messentgelt, Spielraum und Einbau' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                ['mit ' . self::de(Rights::METERING_TOLERANCE_EUR) . ' € Spielraum für Rundungen', 'Mehrbetrag bis ' . self::de(Rights::METERING_ONE_OFF_EUR['DE']) . ' € still',
                 'Zeiträume ab ' . substr(Rights::METERING_CAPS['DE'][0]['from'], 0, 4)],
                ['with ' . Rights::METERING_TOLERANCE_EUR . ' € leeway for rounding', 'an excess up to ' . Rights::METERING_ONE_OFF_EUR['DE'] . ' € stays quiet',
                 'periods from ' . substr(Rights::METERING_CAPS['DE'][0]['from'], 0, 4)]],
            'Rechte: Frist beim Gebührenbescheid' => ['docs/anleitungen/jahresabrechnung.md', 'docs/en/anleitungen/jahresabrechnung.md',
                [$W['de'][Rights::DEADLINES['DE']['notice_postal_days']] . ' Tage nach dem Bescheiddatum', Rights::DEADLINES['DE']['notice_months'] === 1 ? 'dann ein Monat' : 'notice_months geändert'],
                [$W['en'][Rights::DEADLINES['DE']['notice_postal_days']] . ' days after the assessment date', Rights::DEADLINES['DE']['notice_months'] === 1 ? 'then one month' : 'notice_months changed']],
        ];
    }

    /** Zahl ohne Nachkommastellen, wenn ganz (20.0 → „20“). */
    private static function num(float $v): string
    {
        return abs($v - round($v)) < 1e-9 ? (string)(int)round($v) : str_replace('.', ',', (string)$v);
    }

    /** Zahl wie in der deutschen Doku: Komma, ohne überflüssige Nullen (1.0 → „1“, 1.19 → „1,19“). */
    private static function de(float $v): string
    {
        return str_replace('.', ',', (string)$v);
    }

    private static function js(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    /** Schwelle des Satzes unter „Prognose im Detail“ (forecast.js, |Faktor − 1| ≥ …) in Prozent. */
    private static function calibrationNotePct(): int
    {
        preg_match('/Math\.abs\(factor - 1\) >= (0\.\d+)/', self::js('public/js/views/forecast.js'), $m);
        return isset($m[1]) ? (int)round((float)$m[1] * 100) : -1;
    }

    /** Mindestzahl Tage für das Monatsmittel der Kachel „Kosten“ (chart-data.js, perMonthAverage). */
    private static function monthlyAverageMinDays(): int
    {
        preg_match('/return days >= (\d+) && months > 0/', self::js('public/js/lib/chart-data.js'), $m);
        return isset($m[1]) ? (int)$m[1] : -1;
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

    /**
     * Die Einstellungsreferenz nennt die Schlüssel, die nur Verwalter ändern („Vierzehn
     * Schlüssel“ seit v3.9.0 mit `ha_notify_*`): Zahl und Liste wie in `App::ADMIN_SETTINGS`.
     */
    public function testTheAdminOnlySettingsAreTheOnesTheDocsName(): void
    {
        $keys = (new \ReflectionClassConstant(\Energietracker\App::class, 'ADMIN_SETTINGS'))->getValue();
        $n = count($keys);
        $num = ['de' => [14 => 'Vierzehn', 15 => 'Fünfzehn', 16 => 'Sechzehn'], 'en' => [14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen']];
        foreach (['de' => 'docs/referenz/einstellungen.md', 'en' => 'docs/en/referenz/einstellungen.md'] as $lang => $rel) {
            $d = self::flat($rel);
            $start = (int)strpos($d, $num[$lang][$n] ?? "($n)");
            self::assertGreaterThan(0, $start, "$rel: „{$num[$lang][$n]} …“ fehlt (ADMIN_SETTINGS hat $n Schlüssel)");
            $para = substr($d, $start, 900);
            foreach ($keys as $k) self::assertStringContainsString("`$k`", $para, "$rel: `$k` fehlt in der Liste der Verwalter-Schlüssel");
        }
    }

    /** v3.10.0 (doku) — die Arten eines Zertifikatsfehlers, die die Testnachricht als `error` meldet, stehen in der API-Referenz. */
    public function testTheCertificateErrorKindsAreDocumented(): void
    {
        foreach (['docs/referenz/api.md' => 'Die Testnachricht antwortet', 'docs/en/referenz/api.md' => 'The test message answers'] as $rel => $head) {
            $d = self::flat($rel);
            $at = (int)strpos($d, $head);
            self::assertGreaterThan(0, $at, "$rel: Liste der Fehler der Testnachricht fehlt");
            $list = substr($d, $at, 700);
            foreach (array_keys(\Energietracker\Support\HomeTls::CERT_ERRORS) as $kind) {
                self::assertStringContainsString("`$kind`", $list, "$rel: `$kind` fehlt bei der Testnachricht");
            }
        }
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
        // v3.10.0 — Anfangsstand: drei Tage, und so viele Tage einer Jahresrechnung bleiben unter der Toleranz (1 %)
        self::assertSame(3, \Energietracker\Services\Inbox\Proposals::START_READING_DAYS);
        self::assertLessThanOrEqual((new \ReflectionClassConstant(\Energietracker\Services\BillService::class, 'OK_PCT'))->getValue(),
            \Energietracker\Services\Inbox\Proposals::START_READING_DAYS / 365 * 100);
        // Rhythmus aus Terminen: drei Tage daneben passen noch, vier nicht
        self::assertSame(2, InvoicePrices::rhythmOfDates(['2026-01-15', '2026-03-18', '2026-05-15']));
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-01-15', '2026-03-19', '2026-05-15']));
        // v3.10.0 (doku) — die Zahlen hinter den neuen Sätzen
        self::assertSame(14, self::defaults()['reminder_warn_days_before']);
        self::assertSame(365, HomeAssistantNotifyService::HORIZON_DAYS);
        self::assertSame(2, self::calibrationNotePct(), 'forecast.js: Schwelle des Kalibrier-Satzes nicht gefunden');
        self::assertSame(28, self::monthlyAverageMinDays(), 'chart-data.js: Mindesttage des Monatsmittels nicht gefunden');
        // Trendpfeil „Ablesung prüfen“ ab Faktor 2 = zweite Stufe der Ablesungsprüfung
        preg_match('/export const TREND_CHECK_FACTOR = (\d+(?:\.\d+)?);/', self::js('public/js/lib/chart-data.js'), $tf);
        self::assertSame(\Energietracker\Services\ReadingCheckService::STRONG_RATIO, (float)($tf[1] ?? -1),
            'Trendpfeil und Ablesungsprüfung sollen dieselbe Grenze haben (Doku sagt es)');
        // „Nicht prüfen“ wirkt genau für die Netze der Doku: Rand innen, Nachbar außen
        $local = [['127.0.0.1', '127.255.255.255'], ['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'],
                  ['192.168.0.0', '192.168.255.255'], ['100.64.0.0', '100.127.255.255'], ['::1'], ['fc00::1', 'fdff:ffff::1']];
        foreach (array_merge(...$local) as $ip) self::assertTrue(\Energietracker\Services\OcrService::isLocalIp($ip), "$ip gehört zum Heimnetz");
        foreach (['126.255.255.255', '11.0.0.0', '172.15.255.255', '172.32.0.0', '192.167.255.255', '192.169.0.0',
                  '100.63.255.255', '100.128.0.0', '169.254.1.1', '8.8.8.8', 'fe80::1', '2001:db8::1'] as $ip) {
            self::assertFalse(\Energietracker\Services\OcrService::isLocalIp($ip), "$ip gehört nicht zum Heimnetz");
        }
        // Gesundheitscheck des Images alle 30 Sekunden (Doku Home Assistant und Posteingang)
        $docker = (string)file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
        self::assertSame(2, substr_count($docker, 'HEALTHCHECK --interval=30s'), 'beide Varianten des Images');
        foreach (['docs/anleitungen/home-assistant.md' => 'alle 30 Sekunden', 'docs/en/anleitungen/home-assistant.md' => 'every 30 seconds',
                  'docs/anleitungen/posteingang.md' => 'alle 30 Sekunden', 'docs/en/anleitungen/posteingang.md' => 'every 30 seconds'] as $rel => $s) {
            self::assertStringContainsString($s, self::flat($rel), $rel);
        }
    }
}
