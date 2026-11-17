<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;

/**
 * v3.13.0 — Herkunft folgt der schwächsten Eingabe. Ein Betrag aus angenommenen Preisen (Vertrag läuft ohne Eintrag
 * weiter, Preis nach dem Ende der Preisgarantie) heißt nie „gemessen“ oder „eingetragen“; eine Menge an einer
 * geschätzten Ablesung heißt geschätzt, auch in einer Summe über mehrere Zähler. Geprüft an jedem Werkzeug mit
 * Zahl-Objekten in einem Haushalt, in dem jeder Preis angenommen ist und ein Zähler eine geschätzte Ablesung hat.
 *
 * Haushalt (heute 13.11.2026), Stände am Monatsersten von Januar 2025 bis November 2026:
 *  - Strom „Haus“ 280 kWh je Monat, Vertrag 2024 mit Verlängerung ohne Eintrag (31 ct, 9,50 €/Monat, Abschlag 115 €)
 *  - Strom „Garage“ 70 kWh je Monat, der Stand am 01.04.2026 geschätzt; Vertrag ebenso verlängert (27 ct, 6 €, 35 €)
 *  - Gas 100 m³ = 1 000 kWh je Monat, Vertrag ab 2024 ohne Ende, Preisgarantie bis 31.12.2024 (11,5 ct, 11 €, 125 €)
 * Ab 2025 rechnet also jeder Monat mit angenommenen Preisen.
 */
final class McpWeakestInputTest extends McpHouseholdTestCase
{
    private function assumedEverywhere(): void
    {
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]], 'active_utilities' => ['strom', 'gas']]);
        $this->meters('strom', ['m_haus' => 'Haus', 'm_garage' => 'Garage']);
        $garage = self::monthly('2025-01', '2026-11', 70);
        foreach ($garage as $i => $r) if ($r[0] === '2026-04-01') $garage[$i][2] = true;
        $this->counters('strom', ['m_haus' => self::monthly('2025-01', '2026-11', 280), 'm_garage' => $garage]);
        foreach ([['m_haus', 31.0, 9.5, 115.0], ['m_garage', 27.0, 6.0, 35.0]] as [$m, $wp, $bp, $ap]) {
            $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis',
                'start' => '2024-01-01', 'end' => '2024-12-31', 'auto_renews' => true,
                'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => $wp]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => $bp]],
                'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => $ap]]]);
        }
        $this->meters('gas', ['m_gas' => 'Heizraum']);
        $this->counters('gas', ['m_gas' => self::monthly('2025-01', '2026-11', 100)]);
        $gas = $this->contracts->create('gas', ['meter_id' => 'm_gas', 'provider' => 'Gaswerk Musterstadt', 'tariff_name' => 'Basis', 'start' => '2024-01-01',
            'price_guarantee_until' => '2024-12-31', 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.5]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 11.0]], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 125.0]]]);
        // Rechnung 2025: 12 000 kWh × 0,115 € = 1 380 € + 132 € Grundpreis = 1 512 €, Abschläge 12 × 125 € = 1 500 €
        $this->store->write('gas/bills.json', [['id' => 'b_test2025', 'meter_id' => 'm_gas', 'contract_id' => $gas['id'], 'kind' => 'annual',
            'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-01-20',
            'invoice' => ['energy_kwh' => 12000.0, 'amount_eur' => 1512.0, 'advances_paid_eur' => 1500.0, 'result_eur' => 12.0],
            'items' => [], 'attachment_ids' => [], 'note' => '', 'created_at' => '2026-01-21T10:00:00+01:00']]);
    }

    /**
     * Stellen mit Geld, die stärker heißen als „angenommen“. Ausgenommen sind Beträge, die die Person selbst als
     * Eingabe nennt (Kosten einer Maßnahme im Szenario) und Zählwerte.
     *
     * @return list<string>
     */
    private static function tooStrong(mixed $data, string $path = ''): array
    {
        if (!is_array($data)) return [];
        $out = [];
        $weak = fn(?string $b): bool => $b !== null && Provenance::rank($b) >= Provenance::rank('assumed');
        if (Num::isNum($data)) {
            $money = str_starts_with((string)$data['unit'], 'EUR') || str_starts_with((string)$data['unit'], 'ct/');
            // ausgenommen: was die Person selbst als Betrag einträgt (Kosten einer Maßnahme, Beträge und Posten einer
            // erfassten Rechnung, ein Bonus); diese Beträge kommen aus keinem Preis
            $own = ['/optimization/cost', '/invoice/', '/ours/bonus', '/ours/items_total'];
            if ($money && !$weak($data['basis']) && array_filter($own, fn($p) => str_contains($path, $p)) === []) $out[] = "$path: {$data['basis']}";
            return $out;
        }
        if (Provenance::isBasis($data['basis'] ?? null)) {
            // ohne eigene `money_basis` gilt die Herkunft der Zeile für alle ihre Zahlen (Prognosemonate: geschätzt)
            foreach (['cost', 'revenue', 'advances', 'balance'] as $f) {
                if (isset($data[$f]) && !$weak($data['money_basis'] ?? $data['basis'])) {
                    $out[] = "$path/$f: money_basis " . ($data['money_basis'] ?? '—');
                }
            }
        }
        foreach ($data as $k => $v) array_push($out, ...self::tooStrong($v, "$path/$k"));
        return $out;
    }

    public function testNoAmountFromAssumedPricesIsMeasuredOrEntered(): void
    {
        $this->assumedEverywhere();
        $calls = $this->callsFor();
        $n = 0;
        foreach ($calls as $tool => $list) {
            foreach ($list as $args) {
                $out = $this->tool($tool, $args);
                self::assertSame([], self::tooStrong($out), "$tool " . json_encode($args));
                $n++;
            }
        }
        self::assertGreaterThan(15, $n);
    }

    public function testTheBalancesNameTheAssumedPrices(): void
    {
        $this->assumedEverywhere();
        $c = $this->tool('get_contracts', ['utility' => 'strom', 'meter' => 'm_haus'])['targets'][0]['contracts'][0];
        self::assertSame(['renewed', 'assumed'], [$c['phase'], $c['prices']['working_price']['basis']]);
        // Saldo zur letzten Ablesung: Menge gemessen, Preise angenommen → angenommen (bis v3.12: gemessen)
        self::assertSame('assumed', $c['status']['balance_measured']['basis']);
        // Saldo bis heute: Grundpreis und Abschläge angenommen, nicht eingetragen; die Arbeitskosten aus angenommenen
        // Preisen heißen auch nicht gemessen (jeder Monat seit 2025 rechnet mit angenommenem Preis)
        self::assertArrayHasKey('assumed', $c['status']['balance_today']['parts']);
        self::assertArrayNotHasKey('entered', $c['status']['balance_today']['parts']);
        self::assertArrayNotHasKey('measured', $c['status']['balance_today']['parts']);
        self::assertArrayNotHasKey('measured', $c['status']['balance_measured']['parts'] ?? []);
        // Gas nach der Preisgarantie ebenso
        $g = $this->tool('get_contracts', ['utility' => 'gas'])['targets'][0]['contracts'][0];
        self::assertSame(['assumed', 'assumed'], [$g['prices']['working_price']['basis'], $g['status']['balance_measured']['basis']]);
        // Jahr und Abrechnungsjahr: das Geld angenommen, die Menge bleibt gemessen
        $year = self::row($this->tool('get_consumption', ['utility' => 'strom', 'meter' => 'm_haus', 'granularity' => 'year'])['rows'], '2025');
        self::assertSame(['measured', 'assumed'], [$year['basis'], $year['money_basis']]);
        // Handrechnung 2025: 12 × 280 kWh × 0,31 € = 1 041,60 € + 12 × 9,50 € = 114 € → 1 155,60 €
        self::assertEqualsWithDelta(1155.6, $year['cost'], 0.05);
        $bp = $this->tool('get_consumption', ['utility' => 'strom', 'meter' => 'm_haus', 'granularity' => 'billing_period'])['rows'];
        self::assertSame(['assumed'], array_values(array_unique(array_column($bp, 'money_basis'))));
    }

    public function testASumOfMetersIsEstimatedWhereOneMeterIsEstimated(): void
    {
        $this->assumedEverywhere();
        // Garage allein: März und April verteilt an der geschätzten Ablesung vom 01.04. → geschätzt
        $own = $this->tool('get_consumption', ['utility' => 'strom', 'meter' => 'm_garage'])['rows'];
        self::assertSame(['estimated', 'estimated'], [self::row($own, '2026-03')['basis'], self::row($own, '2026-04')['basis']]);
        // Summe Haus + Garage (350 kWh je Monat, davon 280 gemessen): dieselben Monate geschätzt, nicht „gemessen“
        $sum = $this->tool('get_consumption', ['utility' => 'strom'])['rows'];
        foreach (['2026-03', '2026-04'] as $ym) {
            $r = self::row($sum, $ym);
            self::assertSame('estimated', $r['basis'], $ym);
            self::assertEqualsWithDelta(350.0, $r['value'], 0.5, $ym);
            self::assertSame('estimated', $r['money_basis'], $ym);
        }
        // daneben gemessen (Mengen), das Geld angenommen
        self::assertSame(['measured', 'assumed'], [self::row($sum, '2026-06')['basis'], self::row($sum, '2026-06')['money_basis']]);
        // Zeilen und Summe derselben Antwort passen zusammen: Die Summe ist geschätzt
        self::assertSame('estimated', $this->tool('get_consumption', ['utility' => 'strom'])['total']['quantity']['basis']);
    }

    public function testWithEnteredPricesTheMeasuredBalanceStaysMeasured(): void
    {
        // Gegenprobe: derselbe Haushalt mit eingetragenem Vertrag ohne Ende und ohne Preisgarantie
        $this->settings->set(['active_utilities' => ['strom']]);
        $this->meters('strom', ['m_haus' => 'Haus']);
        $this->counters('strom', ['m_haus' => self::monthly('2025-01', '2026-11', 260)]);
        $this->contracts->create('strom', ['meter_id' => 'm_haus', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 29.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 105.0]]]);
        $c = $this->tool('get_contracts', ['utility' => 'strom'])['targets'][0]['contracts'][0];
        self::assertSame(['entered', 'measured'], [$c['prices']['working_price']['basis'], $c['status']['balance_measured']['basis']]);
        $year = self::row($this->tool('get_consumption', ['utility' => 'strom', 'granularity' => 'year'])['rows'], '2025');
        self::assertSame('entered', $year['money_basis'], 'gemessene Menge × eingetragener Preis');
    }

    /** doc-claim `weakest-input`: „Woher eine Zahl kommt“ verspricht, was die Tests hier halten (DE und EN). */
    public function testTheDocsPromiseWhatTheseTestsHold(): void
    {
        $root = dirname(__DIR__, 4);
        $want = ['de' => ['angenommen', 'Preisgarantie', 'Saldo', 'mehrere Zähler', 'money_basis', 'geschätzt', 'eingetragener Preis schwächt'],
                 'en' => ['assumed', 'price guarantee', 'balance', 'several meters', 'money_basis', 'estimated', 'entered price does not weaken']];
        foreach (['de' => 'docs/verstehen/18-herkunft.md', 'en' => 'docs/en/verstehen/18-herkunft.md'] as $lang => $file) {
            $src = (string)file_get_contents("$root/$file");
            $at = strpos($src, '<!-- doc-claim: weakest-input');
            self::assertNotFalse($at, "$file: Marker fehlt");
            $para = (string)preg_replace('/\s+/u', ' ', substr($src, (int)strrpos(substr($src, 0, $at), "\n\n"), $at - (int)strrpos(substr($src, 0, $at), "\n\n")));
            foreach ($want[$lang] as $w) self::assertStringContainsString($w, $para, "$file: „{$w}“");
        }
    }

    public function testPriceGuaranteeEdges(): void
    {
        // Garantie bis 15.07.: Juni eingetragen, Juli (über den 15. hinaus gedeckt) angenommen, im angebrochenen Monat nach dem letzten gedeckten Tag
        $c = ['price_guarantee_until' => '2026-07-15', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]]];
        self::assertSame('entered', Provenance::monthPrice(['ym' => '2026-06'], $c));
        self::assertSame('assumed', Provenance::monthPrice(['ym' => '2026-07'], $c));
        self::assertSame('entered', Provenance::monthPrice(['ym' => '2026-07', 'days' => 14], $c), 'nur bis 14.07. gedeckt');
        self::assertSame('entered', Provenance::monthPrice(['ym' => '2026-07', 'to' => '2026-07-15'], $c), 'Stück bis zum Garantieende');
        // ein neuer Preis ab 01.08. ist eingetragen
        $c['working_prices'][] = ['from' => '2026-08-01', 'ct_per_kwh' => 33.0];
        self::assertSame('entered', Provenance::monthPrice(['ym' => '2026-08'], $c));
        self::assertSame('assumed', Provenance::monthPrice(['ym' => '2026-07'], $c));
        // fortgeschrieben schlägt alles
        self::assertSame('assumed', Provenance::monthPrice(['ym' => '2026-06', 'contract_assumed' => true], $c));
    }
}
