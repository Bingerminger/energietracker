<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.13.0 — Der Saldo zur Abrechnung in `run_scenario` steht in Worten wie jeder andere Saldo: `meaning` und
 * `text` neben `sign`, dazu eine Zeile im zweiten Textblock. Bis dahin trug er nur `sign`, und ein Modell musste
 * das Vorzeichen selbst deuten.
 */
final class McpScenarioBalanceWordsTest extends McpHouseholdTestCase
{
    /** @return array{0:array<string,mixed>,1:list<string>} billing und Zeilen des zweiten Textblocks */
    private function scenario(string $u, string $meter): array
    {
        $r = $this->rpc('tools/call', ['name' => 'run_scenario', 'arguments' => (object)['utility' => $u, 'meter' => $meter, 'winter_pct' => 10]],
            $this->context('mcp', self::DAY));
        $res = $r['body']['result'];
        self::assertFalse($res['isError']);
        $out = json_decode((string)$res['content'][0]['text'], true);
        self::assertTrue($out['valid'], 'Szenario gerechnet');
        return [(array)($out['billing'] ?? []), explode("\n", (string)($res['content'][1]['text'] ?? ''))];
    }

    public function testCreditInWordsWithElectricity(): void
    {
        // 320 kWh je Monat × 33 ct = 105,60 € + 11 € Grundpreis = 116,60 € gegen 140 € Abschlag: jeden Monat 23,40 € Guthaben
        $this->meters('strom', ['m_strom' => 'Haus']);
        $this->counters('strom', ['m_strom' => self::monthly('2026-01', '2026-11', 320)]);
        $this->contracts->create('strom', ['meter_id' => 'm_strom', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 33.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 11.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 140.0]]]);
        [$b, $lines] = $this->scenario('strom', 'm_strom');
        foreach (['base_end_balance', 'scen_end_balance'] as $k) {
            self::assertLessThan(0, $b[$k]['value'], "$k: Guthaben");
            self::assertSame(['positive_is_back_payment', 'credit', 'estimated'], [$b[$k]['sign'], $b[$k]['meaning'], $b[$k]['basis']], $k);
            self::assertStringStartsWith('Geschätzt: Guthaben von ', $b[$k]['text']);
            self::assertContains("$k: {$b[$k]['text']}", $lines, 'zweiter Textblock');
        }
    }

    public function testBackPaymentInWordsWithGas(): void
    {
        // 1 100 kWh je Monat (110 m³ × 10 kWh/m³) × 13 ct = 143 € + 9 € gegen 70 € Abschlag: Nachzahlung
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]]]);
        $this->meters('gas', ['m_gas' => 'Heizraum']);
        $this->counters('gas', ['m_gas' => self::monthly('2026-01', '2026-11', 110)]);
        $this->contracts->create('gas', ['meter_id' => 'm_gas', 'provider' => 'Gaswerk Musterstadt', 'tariff_name' => 'Basis', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 13.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 9.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 70.0]]]);
        [$b] = $this->scenario('gas', 'm_gas');
        self::assertGreaterThan(0, $b['base_end_balance']['value']);
        self::assertSame('back_payment', $b['base_end_balance']['meaning']);
        self::assertStringStartsWith('Geschätzt: Nachzahlung von ', $b['base_end_balance']['text']);
    }

    public function testWithoutWorkingPriceItIsNoBalance(): void
    {
        // Vertrag nur mit Abschlag: Der Betrag sind die Abschläge, kein Saldo
        $this->meters('strom', ['m_strom' => 'Haus']);
        $this->counters('strom', ['m_strom' => self::monthly('2026-01', '2026-11', 320)]);
        $this->contracts->create('strom', ['meter_id' => 'm_strom', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2026-01-01',
            'working_prices' => [], 'base_prices' => [], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 95.0]]]);
        [$b] = $this->scenario('strom', 'm_strom');
        self::assertSame('no_price', $b['base_end_balance']['meaning']);
        self::assertContains('price_missing', $b['base_end_balance']['uncertain']);
        self::assertStringStartsWith('Kein Saldo: Der Arbeitspreis fehlt', $b['base_end_balance']['text']);
    }
}
