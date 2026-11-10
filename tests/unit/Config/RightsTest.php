<?php
declare(strict_types=1);

namespace Energietracker\Tests\Config;

use Energietracker\Config\Countries;
use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.11.0 (F1035, Paket basis) — Rechtsregeln an einer Stelle (Config\Rights): jede Regel mit
 * Norm, Quelle, Stand und Zeile im Prüfbericht; Fristen der Prüfungen A1, A3, A7 mit von Hand
 * gerechneten Erwartungen (Konzept §3, Lektion 26: Monatsende); Preisobergrenzen des
 * Messstellenbetriebs an beiden Grenzen jeder Fallgruppe.
 */
#[CoversClass(Rights::class)]
final class RightsTest extends TestCase
{
    // ── Regeln ──────────────────────────────────────────────────────────

    public function testEveryRuleNamesNormSourceStateAndReportRow(): void
    {
        $rows = [];
        foreach (Rights::RULES as $country => $rules) {
            self::assertSame(1, preg_match('/^[A-Z]{2}$/', $country), $country);
            foreach ($rules as $key => $r) {
                self::assertStringStartsWith(strtolower($country) . '.', $key, $key);
                self::assertNotSame('', $r['norm'], $key);
                self::assertStringStartsWith('https://', $r['url'], "$key: Quelle als https-Link");
                self::assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['checked_on']), "$key: Stand");
                self::assertContains($r['status'], Rights::STATUSES, $key);
                self::assertIsInt($r['report'], $key);
                foreach ($r['utilities'] ?? [] as $u) self::assertTrue(Utilities::exists($u), "$key: Art $u");
                foreach ($r['supply_kinds'] ?? [] as $k) self::assertContains($k, Rights::SUPPLY_KINDS, $key);
                $rows[$r['report']] = true;
            }
        }
        // jede Zeile des Prüfberichts (Konzept §12, 58 Zeilen; v3.11.0 Skeptiker H3: Nr. 59; Nachprüfung NP1: Nr. 60, 61)
        // hat ihre Regel — und keine Regel ohne Zeile
        ksort($rows);
        self::assertSame(range(1, 61), array_keys($rows));
    }

    public function testOnlyGermanyHasRulesForNow(): void
    {
        self::assertTrue(Rights::supports('DE'));
        foreach (['AT', 'CH', 'FR', 'IT', 'ES', 'PT', 'NL', 'GB', ''] as $c) self::assertFalse(Rights::supports($c), $c);
        self::assertNull(Rights::rule('AT', 'de.enwg.40c.2'));
    }

    /** Status U und „nicht in App-Texte“ (Prüfbericht 53, 56, 58) dürfen in keinem Hinweis stehen. */
    public function testUnsureRulesAreNotCitable(): void
    {
        self::assertTrue(Rights::citable('DE', 'de.enwg.40c.2'));
        self::assertTrue(Rights::citable('DE', 'de.bgb.188'), 'G: gegenlesen, aber nennbar');
        foreach (['de.avbfernwaermev.24.1', 'de.land.vorverfahren', 'de.enwg.2026', 'de.bgh.i_zr_113_20', 'de.unknown'] as $k) {
            self::assertFalse(Rights::citable('DE', $k), $k);
        }
    }

    public function testApplicableFiltersByUtilityAndSupplyKind(): void
    {
        $keys = ['de.stromgvv.13', 'de.gasgvv.13', 'de.enwg.41b.3', 'de.avbfernwaermev.25', 'de.avbwasserv.25'];
        self::assertSame(['de.stromgvv.13'], Rights::applicable('DE', $keys, 'strom', 'basic'));
        self::assertSame(['de.enwg.41b.3'], Rights::applicable('DE', $keys, 'gas', 'special'));
        self::assertSame(['de.gasgvv.13', 'de.enwg.41b.3'], Rights::applicable('DE', $keys, 'gas', null), 'Art unbekannt: beide');
        self::assertSame(['de.avbfernwaermev.25'], Rights::applicable('DE', $keys, 'fernwaerme', null));
        self::assertSame([], Rights::applicable('AT', $keys, 'strom', null));
        self::assertTrue(Rights::isEnergy('DE', 'gas'));
        self::assertFalse(Rights::isEnergy('DE', 'fernwaerme'), 'Fernwärme ist keine Energie im Sinne des § 3 Nr. 31 EnWG');
    }

    // ── A1 Rechnung zu spät ─────────────────────────────────────────────

    public function testBillDeadlineIsSixWeeksAfterThePeriod(): void
    {
        // 31.03. + 42 Tage = 12.05. (Konzept §3 A1)
        self::assertSame('2026-05-12', Rights::billDeadline('DE', 'strom', '2026-03-31'));
        self::assertSame('2026-05-12', Rights::billDeadline('DE', 'gas', '2026-03-31', 'final'));
        self::assertSame(Countries::billDeadlineDays('DE', 'strom'), Rights::daysBetween('2026-03-31', '2026-05-12'), 'eine Quelle: Countries');
        // Schaltjahr: 15.02.2028 + 42 = 28.03.2028
        self::assertSame('2028-03-28', Rights::billDeadline('DE', 'strom', '2028-02-15'));
        foreach ([['DE', 'wasser'], ['DE', 'fernwaerme'], ['AT', 'strom']] as [$c, $u]) {
            self::assertNull(Rights::billDeadline($c, $u, '2026-03-31'), "$c $u");
        }
        self::assertNull(Rights::billDeadline('DE', 'strom', '2026-03-31', 'interim'), 'monatlich: nicht modelliert');
    }

    public function testABillIsLateOnlyBeyondTheGraceDays(): void
    {
        self::assertSame(2, Rights::BILL_LATE_GRACE_DAYS);
        self::assertNull(Rights::billDaysLate('DE', 'strom', '2026-03-31', 'annual', '2026-05-12'), 'am Fristende');
        self::assertNull(Rights::billDaysLate('DE', 'strom', '2026-03-31', 'annual', '2026-05-14'), 'zwei Tage: Wochenende');
        self::assertSame(3, Rights::billDaysLate('DE', 'strom', '2026-03-31', 'annual', '2026-05-15'));
        self::assertNull(Rights::billDaysLate('DE', 'wasser', '2026-03-31', 'annual', '2026-09-01'));
    }

    // ── A3 Preisänderung ───────────────────────────────────────────────

    public function testTheTerminationMustArriveTheDayBeforeTheChange(): void
    {
        self::assertSame('2026-11-30', Rights::specialTerminationDeadline('2026-12-01'));
        self::assertSame('2026-12-31', Rights::specialTerminationDeadline('2027-01-01'));
        self::assertSame('2028-02-29', Rights::specialTerminationDeadline('2028-03-01'));
        self::assertSame('2026-11-16', Rights::remindOn('2026-11-30'), '14 Tage vorher unter „Zu tun“');
    }

    /** @return array<string, array{0:string, 1:string, 2:array<string,mixed>}> */
    public static function announcements(): array
    {
        return [
            // Sondervertrag: spätestens einen Monat vorher; 25 Tage sind zu spät (Konzept §3 A3)
            '25 Tage vor dem 01.12.' => ['2026-11-06', '2026-12-01', ['days_before' => 25, 'special' => ['announce_by' => '2026-11-01', 'late' => true],
                'basic' => ['announce_by' => '2026-10-20', 'late' => true, 'month_start' => true]]],
            'genau ein Monat'        => ['2026-11-01', '2026-12-01', ['days_before' => 30, 'special' => ['announce_by' => '2026-11-01', 'late' => false],
                'basic' => ['announce_by' => '2026-10-20', 'late' => true, 'month_start' => true]]],
            'sechs Wochen'           => ['2026-10-20', '2026-12-01', ['days_before' => 42, 'special' => ['announce_by' => '2026-11-01', 'late' => false],
                'basic' => ['announce_by' => '2026-10-20', 'late' => false, 'month_start' => true]]],
            // Monatsende geklemmt (Lektion 26): 31.03. − 1 Monat = 28.02.
            'zum 31.03.'             => ['2026-02-28', '2026-03-31', ['days_before' => 31, 'special' => ['announce_by' => '2026-02-28', 'late' => false],
                'basic' => ['announce_by' => '2026-02-17', 'late' => true, 'month_start' => false]]],
            'Grundversorgung zum 15.' => ['2026-09-01', '2026-11-15', ['days_before' => 75, 'special' => ['announce_by' => '2026-10-15', 'late' => false],
                'basic' => ['announce_by' => '2026-10-04', 'late' => false, 'month_start' => false]]],
        ];
    }

    /** @param array<string,mixed> $expected */
    #[DataProvider('announcements')]
    public function testTheAnnouncementPeriodOfAPriceChange(string $announced, string $validFrom, array $expected): void
    {
        self::assertSame($expected, Rights::announcement('DE', $announced, $validFrom));
    }

    public function testNoAnnouncementCheckWithoutRules(): void
    {
        self::assertNull(Rights::announcement('AT', '2026-11-06', '2026-12-01'));
        self::assertNull(Rights::announcement('DE', '06.11.2026', '2026-12-01'));
    }

    // ── A7 Gebührenbescheid ────────────────────────────────────────────

    /**
     * Konzept §3 A7: Bekanntgabe am vierten Tag, dann ein Monat; fehlt der Tag, der letzte (= rechnerisches Ende).
     * v3.11.0 (Nachprüfung NP1) — fällt dieses auf Samstag, Sonntag oder einen bundesweiten Feiertag, endet die
     * Frist am nächsten Werktag (Prüfbericht 60, 61). Von Hand mit dem Kalender:
     *   28.02.2026 Samstag → Montag 02.03.;  01.03.2026 Sonntag → 02.03.;  02.02.2027 Dienstag bleibt
     *   06.04.2026 Ostermontag (Ostern 05.04.) → Dienstag 07.04.;  03.04.2026 Karfreitag → Sa, So, Ostermontag → 07.04.
     *   03.10.2025 Freitag (Tag der Deutschen Einheit) → Sa, So → Montag 06.10.;  03.10.2028 Dienstag → 04.10.
     *   14.05.2026 Christi Himmelfahrt (Donnerstag) → 15.05.;  25.05.2026 Pfingstmontag → 26.05.
     *   25.12.2026 Freitag → 26. Sa, 27. So → Montag 28.12.;  01.01.2028 Samstag → Montag 03.01.
     *   01.05.2027 Samstag → Montag 03.05.;  01.11.2026 Sonntag (Allerheiligen gilt nicht bundesweit) → Montag 02.11.
     *
     * @return array<string, array{0:string, 1:?string, 2:string, 3:string, 4:string}>
     */
    public static function feeNotices(): array
    {
        return [
            '27.01. → 31.01. → Sa 28.02. → Mo 02.03.'   => ['2026-01-27', null, '2026-01-31', '2026-02-28', '2026-03-02'],
            '28.01. → 01.02. → So 01.03. → Mo 02.03.'   => ['2026-01-28', null, '2026-02-01', '2026-03-01', '2026-03-02'],
            'Jahreswechsel, Dienstag bleibt'            => ['2026-12-29', null, '2027-01-02', '2027-02-02', '2027-02-02'],
            'Zustellungsurkunde zählt, Freitag bleibt'  => ['2026-03-02', '2026-03-10', '2026-03-10', '2026-04-10', '2026-04-10'],
            'Schaltjahr: 27.01.2028 → Di 29.02.'        => ['2028-01-27', null, '2028-01-31', '2028-02-29', '2028-02-29'],
            'Ostermontag'                               => ['2026-03-01', '2026-03-06', '2026-03-06', '2026-04-06', '2026-04-07'],
            'Karfreitag bis Ostermontag'                => ['2026-03-01', '2026-03-03', '2026-03-03', '2026-04-03', '2026-04-07'],
            '3. Oktober am Freitag'                     => ['2025-09-01', '2025-09-03', '2025-09-03', '2025-10-03', '2025-10-06'],
            '3. Oktober am Dienstag'                    => ['2028-09-01', '2028-09-03', '2028-09-03', '2028-10-03', '2028-10-04'],
            'Christi Himmelfahrt'                       => ['2026-04-10', '2026-04-14', '2026-04-14', '2026-05-14', '2026-05-15'],
            'Pfingstmontag'                             => ['2026-04-20', '2026-04-25', '2026-04-25', '2026-05-25', '2026-05-26'],
            'Weihnachten am Freitag'                    => ['2026-11-20', '2026-11-25', '2026-11-25', '2026-12-25', '2026-12-28'],
            'Neujahr am Samstag'                        => ['2027-11-28', '2027-12-01', '2027-12-01', '2028-01-01', '2028-01-03'],
            '1. Mai am Samstag'                         => ['2027-03-28', '2027-04-01', '2027-04-01', '2027-05-01', '2027-05-03'],
            'Sonntag 01.11. (kein bundesweiter Feiertag)' => ['2026-09-26', '2026-10-01', '2026-10-01', '2026-11-01', '2026-11-02'],
        ];
    }

    #[DataProvider('feeNotices')]
    public function testTheDeadlineOfAFeeNotice(string $dated, ?string $served, string $known, string $nominal, string $deadline): void
    {
        $fd = (array)Rights::feeNoticeDeadline('DE', $dated, $served);
        self::assertSame(['known_on' => $known, 'nominal_end' => $nominal, 'deadline' => $deadline],
            array_intersect_key($fd, array_flip(['known_on', 'nominal_end', 'deadline'])));
        // v3.12.0 — Grundlage der Bekanntgabe; nur vom Bescheiddatum gerechnet sieben Tage Weichzone
        self::assertSame($served === null ? 'post' : 'served', $fd['basis']);
        self::assertSame($served === null ? date('Y-m-d', (int)strtotime("$deadline +7 days")) : $deadline, $fd['soft_until']);
    }

    /** Ostersonntag, gegen den Kalender: die beweglichen Feiertage hängen daran. */
    public function testEasterSunday(): void
    {
        foreach (['2008' => '2008-03-23', '2011' => '2011-04-24', '2019' => '2019-04-21', '2024' => '2024-03-31', '2025' => '2025-04-20',
                  '2026' => '2026-04-05', '2027' => '2027-03-28', '2038' => '2038-04-25'] as $y => $want) {
            self::assertSame($want, Rights::easterSunday((int)$y), (string)$y);
        }
    }

    /** Werktag: kein Samstag, Sonntag, bundesweiter Feiertag; Landesfeiertage zählen nicht (die App kennt sie nicht). */
    public function testWorkingDays(): void
    {
        foreach (['2026-01-06', '2026-06-04', '2025-08-15', '2025-10-31', '2026-11-18', '2026-11-03'] as $d) {
            self::assertFalse(Rights::isNonWorkingDay('DE', $d), "$d: Landesfeiertag oder Werktag");   // Hl. Drei Könige, Fronleichnam, Mariä Himmelfahrt, Reformationstag, Buß- und Bettag
        }
        foreach (['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14', '2026-05-25', '2026-10-03', '2026-12-25', '2026-12-26', '2026-11-07', '2026-11-08'] as $d) {
            self::assertTrue(Rights::isNonWorkingDay('DE', $d), $d);
        }
        self::assertSame('2026-11-03', Rights::nextWorkingDay('DE', '2026-11-03'));
        self::assertSame('2026-11-09', Rights::nextWorkingDay('DE', '2026-11-07'));
        // ein Land ohne Feiertagsliste kennt nur das Wochenende
        self::assertFalse(Rights::isNonWorkingDay('AT', '2026-12-25'));
        self::assertTrue(Rights::isNonWorkingDay('AT', '2026-12-26'));
    }

    public function testNoFeeNoticeDeadlineWithoutRules(): void
    {
        self::assertNull(Rights::feeNoticeDeadline('AT', '2026-01-27'));
    }

    // ── A6 Messstellenbetrieb ──────────────────────────────────────────

    /** @return array<string, array{0:string, 1:?float, 2:?float, 3:bool, 4:?string, 5:?float}> */
    public static function meteringCases(): array
    {
        return [
            'moderne Messeinrichtung'          => ['mme', 3000.0, null, false, 'mme', 25.0],
            'iMSys bis 6.000 kWh: optional'    => ['imsys', 6000.0, null, false, 'imsys_optional', 30.0],
            'über 6.000 kWh'                   => ['imsys', 6000.5, null, false, 'imsys_6000', 40.0],
            'bis 10.000 kWh'                   => ['imsys', 10000.0, null, false, 'imsys_6000', 40.0],
            'über 10.000 kWh'                  => ['imsys', 10000.5, null, false, 'imsys_10000', 50.0],
            '§ 14a bei wenig Verbrauch'        => ['imsys', 3000.0, null, true, 'imsys_10000', 50.0],
            'Anlage 7 kW: optional'            => ['imsys', 3000.0, 7.0, false, 'imsys_optional', 30.0],
            'Anlage über 7 kW'                 => ['imsys', 3000.0, 7.5, false, 'imsys_10000', 50.0],
            'höchste Grenze: 12.000 kWh, 20 kW' => ['imsys', 12000.0, 20.0, false, 'imsys_20000', 110.0],
            'bis 100.000 kWh'                  => ['imsys', 100000.0, null, false, 'imsys_50000', 140.0],
            'über 100.000 kWh: angemessen'     => ['imsys', 150000.0, null, false, 'imsys_100000', null],
            'Verbrauch unbekannt'              => ['imsys', null, null, false, 'imsys_optional', 30.0],
            // v3.12.0 — Rundungsrest einer Rechnung ist kein Verbrauch über der Grenze: 6000 ÷ 365 × 365 ergibt im
            // Gleitkomma 6000,000000000001, zwölf Monatswerte zu 10.000 kWh ebenso knapp darüber
            'Gleitkomma an 6.000 kWh'          => ['imsys', 6000 / 365 * 365, null, false, 'imsys_optional', 30.0],
            'Gleitkomma an 10.000 kWh'         => ['imsys', 10000.000000000002, null, false, 'imsys_6000', 40.0],
            'Gleitkomma an 7 kW'               => ['imsys', 3000.0, 7.000000000000001, false, 'imsys_optional', 30.0],
            'über 6.000 kWh um 1 Wh'           => ['imsys', 6000.001, null, false, 'imsys_6000', 40.0],
        ];
    }

    #[DataProvider('meteringCases')]
    public function testTheMeteringCapPerCase(string $device, ?float $kwh, ?float $kw, bool $p14a, ?string $case, ?float $cap): void
    {
        $r = Rights::meteringCap('DE', '2026-06-30', $device, $kwh, $kw, $p14a);
        self::assertSame($case, $r['case'] ?? null);
        self::assertSame($cap, $r['cap_eur'] ?? null);
        self::assertSame('2026-01-01', $r['from'] ?? null);
        self::assertTrue(Rights::citable('DE', (string)$r['rule']), 'Norm der Fallgruppe nennbar');
    }

    public function testNoMeteringCapBeforeTheTableOrForOtherMeters(): void
    {
        self::assertNull(Rights::meteringCap('DE', '2025-12-31', 'mme', 3000.0), 'Entscheidung 4a: erst ab 2026');
        self::assertNull(Rights::meteringCap('DE', '2026-06-30', 'conventional', 3000.0));
        self::assertNull(Rights::meteringCap('AT', '2026-06-30', 'mme', 3000.0));
        self::assertSame(['conventional', 'mme', 'imsys'], Rights::METERING_DEVICES);
    }
}
