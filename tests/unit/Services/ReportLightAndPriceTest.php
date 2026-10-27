<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0, Runde 2: R2-2, R2-4, R2-7) — Ampel „Abrechnung“ nur aus
 * laufenden Verträgen und nicht „passen“ neben „Abschläge über den Kosten“; Spannen zweier
 * Guthaben vom kleineren zum größeren Betrag; Ø Arbeitspreis nur über Monate mit Preis.
 * Erfundene Haushalte, monatlich am Monatsersten abgelesen.
 */
#[CoversClass(ReportAdviceService::class)]
#[CoversClass(ReportModelService::class)]
final class ReportLightAndPriceTest extends ServiceTestCase
{
    use ReportFixture;

    private function advice(): ReportAdviceService
    {
        $this->i18n->setLocale('de');
        return new ReportAdviceService($this->i18n);
    }

    private function empty(): void
    {
        foreach (['gas', 'strom', 'wasser', 'fernwaerme'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
    }

    /** Ein Zähler mit monatlichen Ständen ab $from bis $until, je Monat $per. */
    private function meterWithReadings(string $u, string $id, string $from, string $until, float $per): void
    {
        $this->store->write("$u/meters.json", [['id' => $id, 'name' => ucfirst($u), 'icon' => '', 'created_at' => $from, 'active' => true,
            'notes' => '', 'parent_meter_id' => null, 'meter_group_id' => null, 'external_id' => null, 'baseline_events' => [],
            'devices' => [['id' => "d_$id", 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0, 'removed_on' => null,
                'final_counter' => null, 'reason' => null]]]]);
        $rows = []; $c = 0.0;
        for ($t = strtotime($from); date('Y-m-d', $t) <= $until; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $c, 'device_id' => "d_$id"];
            $c += $per;
        }
        $this->setReadings($u, $id, $rows);
    }

    private static function facts(array $contracts): array
    {
        return ['year' => 2025, 'today' => '2026-10-15', 'country' => 'DE', 'contracts' => $contracts, 'heating' => [], 'anomalies' => [],
            'efficiency' => [], 'heat_pumps' => [], 'pv' => null, 'flex' => [], 'module3_eur' => 0.0, 'tenancy' => null,
            'co2_claim' => null, 'agenda' => [], 'bills' => [], 'baselines' => [], 'ev' => null, 'totals' => [], 'dismissed' => []];
    }

    private static function contract(string $label, float $balance, float $advance, array $over = []): array
    {
        return $over + ['key' => strtolower($label) . '|m|c', 'utility' => strtolower($label), 'label' => $label, 'provider' => 'Stadtwerke Musterstadt',
            'unit' => 'kWh', 'switchable' => true, 'has_advance' => true, 'is_current' => true, 'is_future' => false,
            'projected_end_balance' => $balance, 'current_advance' => $advance, 'suggested_advance' => null,
            'settle_date' => '2026-12-31', 'cancel_by' => null, 'price_increase' => null, 'projection_uncertain' => null];
    }

    private static function plain(string $s): string
    {
        return (string)preg_replace('/\h/u', ' ', $s);
    }

    /**
     * R2-2 — Strom läuft bis 31.12.2026 (30 ct/kWh, Grundpreis 10 €, Abschlag 90 €), ein
     * Folgevertrag bei einem anderen Versorger ab 01.01.2027 mit 10 € Abschlag. Heute 15.10.2026,
     * je Monat 250 kWh: 250 × 0,30 + 10 = 85 € Kosten im Monat.
     *   laufend:  12 × (90 − 85) = 60 € Guthaben → unter einem Abschlag, „Abschläge passen“
     *   Folge:    rechnet erst später ab; sein Saldo ist eine Nachzahlung
     * Bis dahin nahm die Ampel den Vertrag mit dem höchsten Verhältnis Saldo ÷ Abschlag, hier
     * den Folgevertrag („Strom: … ca. 2 € Nachzahlung“ statt der 60 € Guthaben des laufenden).
     */
    public function testTheBillingLightTakesOnlyTheRunningContract(): void
    {
        $this->empty();
        $this->settings->set(['active_utilities' => ['strom']]);
        $this->meterWithReadings('strom', 'm_s', '2025-01-01', '2026-10-01', 250.0);
        foreach ([['Stadtwerke Musterstadt', '2026-01-01', '2026-12-31', 90.0], ['Energie Beispiel', '2027-01-01', null, 10.0]] as [$p, $s, $e, $a]) {
            $this->contracts->create('strom', ['meter_id' => 'm_s', 'provider' => $p, 'start' => $s, 'end' => $e,
                'working_prices' => [['from' => $s, 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => $s, 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => $s, 'amount_eur' => $a]]]);
        }
        $status = $this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_s'), '2026-10-15')['contracts'];
        $cur = array_values(array_filter($status, fn($c) => !empty($c['is_current'])))[0];
        $next = array_values(array_filter($status, fn($c) => !empty($c['is_future'])))[0];
        self::assertEqualsWithDelta(-60.0, (float)$cur['projected_end_balance'], 1.0, 'Handrechnung laufender Vertrag');
        // der Folgevertrag trägt einen eigenen Saldo (hier ein Tag Kosten ohne Abschlag, Nachzahlung);
        // mit 10 € Abschlag ist sein Verhältnis höher als das des laufenden Vertrags
        self::assertGreaterThan(0.0, (float)$next['projected_end_balance']);

        $m = $this->reportServices()['model']->build(2025, ['today' => '2026-10-15']);
        $light = array_column($m['headline']['lights'], null, 'key')['billing'];
        self::assertSame(['ok', $this->i18n->t('report.light.billing.ok')], [$light['level'], $light['word']]);
        self::assertSame($this->i18n->t('report.light.billingCredit', ['label' => 'Strom', 'amount' => $this->advice()->approx(abs((float)$cur['projected_end_balance']))]),
            $light['detail'], 'der Saldo des laufenden Vertrags, nicht der des Folgevertrags');
    }

    /**
     * R2-2 — „Abschläge passen“ nicht neben „Abschläge über den Kosten“: Gas erwartet 600 €
     * Guthaben bei 100 € Abschlag (ab max(50 €, 100 €) eine Aufgabe), Strom 50 € bei 80 €.
     * Bis dahin urteilte die Ampel nach dem höchsten Verhältnis (Strom −0,6 Abschläge) „✔ passen“.
     */
    public function testACreditThatGivesAnAdvanceTaskIsNotCalledFitting(): void
    {
        $f = self::facts([self::contract('Gas', -600.0, 100.0), self::contract('Strom', -50.0, 80.0)]);
        $r = $this->advice()->evaluate($f);
        $task = array_values(array_filter($r['tasks'], fn($t) => $t['rule'] === 'advance'));
        self::assertCount(1, $task);
        self::assertSame('gas', $task[0]['utility'], 'Aufgabe 1: Abschläge über den Kosten beim Gas');
        $light = array_column($r['lights'], null, 'key')['billing'];
        self::assertSame(['warn', $this->i18n->t('report.light.billing.credit')], [$light['level'], $light['word']]);
        self::assertSame('Gas: zur Abrechnung voraussichtlich ca. 600 € Guthaben.', self::plain($light['detail']));
        // genau an der Schwelle (ein Abschlag, 100 €) gilt die Aufgabe und damit die Ampel; knapp darunter „passen“
        $at = array_column($this->advice()->evaluate(self::facts([self::contract('Gas', -100.0, 100.0)]))['lights'], null, 'key')['billing'];
        self::assertSame('warn', $at['level']);
        $below = array_column($this->advice()->evaluate(self::facts([self::contract('Gas', -99.0, 100.0)]))['lights'], null, 'key')['billing'];
        self::assertSame(['ok', $this->i18n->t('report.light.billing.ok')], [$below['level'], $below['word']]);
        // ein künftiger Vertrag zählt nicht, auch nicht mit großem Guthaben
        $fut = array_column($this->advice()->evaluate(self::facts([self::contract('Strom', -20.0, 80.0),
            self::contract('Strom', -900.0, 100.0, ['is_current' => false, 'is_future' => true])]))['lights'], null, 'key')['billing'];
        self::assertSame('ok', $fut['level']);
        self::assertSame('Strom: zur Abrechnung voraussichtlich ca. 20 € Guthaben.', self::plain($fut['detail']));
    }

    /**
     * R2-4 — eine Spanne aus zwei Guthaben vom kleineren zum größeren Betrag, wie Saldo-Karte und
     * Heizgrenze (balanceRangeEnds in semantics.js); gemischt und zwei Nachzahlungen wie bisher.
     */
    public function testARangeOfTwoCreditsReadsFromTheSmallerAmount(): void
    {
        self::assertSame([[-325.0, -431.0], [-155.0, 8.0], [100.0, 300.0], [-325.0, -431.0]], array_map(
            fn($r) => ReportAdviceService::balanceRangeEnds($r), [[-431, -325], [-155, 8], [100, 300], [-325, -431]]),
            'dieselbe Tabelle wie der Test der JS-Hilfe');
        $unc = ['projected_range' => [-1700.0, -150.0], 'reasons' => ['poor_fit'], 'fit_ratio' => 0.3];
        $light = array_column($this->advice()->evaluate(self::facts([self::contract('Gas', -900.0, 100.0, ['projection_uncertain' => $unc])]))['lights'], null, 'key')['billing'];
        self::assertSame('Gas: unsicher, zwischen ca. 150 € Guthaben und ca. 1.700 € Guthaben', self::plain($light['detail']));
        [$text] = $this->advice()->uncertainBalance($unc, '31.12.2026');
        self::assertStringContainsString('zwischen ca. 150 € Guthaben und ca. 1.700 € Guthaben', self::plain($text));
        [$mixed] = $this->advice()->uncertainBalance(['projected_range' => [-150.0, 300.0], 'reasons' => ['poor_fit'], 'fit_ratio' => 0.3], '31.12.2026');
        self::assertStringContainsString('zwischen ca. 150 € Guthaben und ca. 300 € Nachzahlung', self::plain($mixed));
    }

    /**
     * R2-7 — Wasser 2025 je 10 m³ im Monat, Vertrag erst ab 01.07.2025: Trinkwasser 200 ct/m³,
     * Schmutzwasser 250 ct/m³ (nach Trinkwasser). Mengenabhängig: 6 × 10 × 4,50 € = 270 € für
     * 60 m³ → 450 ct/m³. Bis dahin ÷ 120 m³ (auch Januar bis Juni ohne Vertrag) → 225 ct/m³.
     */
    public function testTheAverageWorkingPriceCountsOnlyMonthsWithAContract(): void
    {
        $this->empty();
        $this->settings->set(['active_utilities' => ['wasser'], 'wasser_personen_anzahl' => 2]);
        $this->meterWithReadings('wasser', 'm_w', '2024-01-01', '2026-01-01', 10.0);
        $this->contracts->create('wasser', ['meter_id' => 'm_w', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-07-01',
            'trinkwasser' => ['working_prices' => [['from' => '2025-07-01', 'ct_per_m3' => 200.0]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2025-07-01', 'ct_per_m3' => 250.0]]]]);
        $page = array_column($this->reportServices()['model']->build(2025, ['today' => '2026-02-15'])['pages'], null, 'utility')['wasser'];
        self::assertSame('contract', $page['cost']['basis']);
        self::assertEqualsWithDelta(270.0, $page['cost']['value'], 0.01, '6 × 10 m³ × 4,50 €');
        self::assertStringContainsString('Arbeitspreis im Mittel 450 ct/m³', self::plain($page['cost']['text']));
    }
}
