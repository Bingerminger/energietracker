<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Tools\ReadSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — KI-Schnittstelle, Saldo ohne Arbeitspreis (`meaning: no_price`): Der Satz behauptet nicht, der Betrag seien
 * „nur die Abschläge“. Er stimmte nicht, sobald Grundpreis oder Monate mit Preis im Betrag steckten; eine KI, die ihn
 * weitergab, nannte eine falsche Summe der Abschläge. Der Satz nennt jetzt keinen Betrag, die Zahl steht in `value`.
 *
 * Erfunden: Strom ab 01.05.2026, Grundpreis 11 €/Monat, Abschlag 65 €/Monat, 160 kWh je Monat, heute 03.11.2026.
 * Von Hand (ohne Arbeitspreis): Saldo zum 31.12. = 8 × 11 € − 8 × 65 € = −432,00 €; die Abschläge allein sind 520,00 €.
 */
#[CoversClass(ReadSupport::class)]
final class McpNoPriceSentenceTest extends McpHouseholdTestCase
{
    private const STAND = '2026-11-03';

    /** @return array<string, array{array<int,array<string,mixed>>}> */
    public static function prices(): array
    {
        return [
            'ohne Arbeitspreis, mit Grundpreis' => [[]],
            'Arbeitspreis erst ab 15.08.'       => [[['from' => '2026-08-15', 'ct_per_kwh' => 29.0]]],
        ];
    }

    #[DataProvider('prices')]
    public function testTheSentenceMatchesTheAmount(array $wp): void
    {
        $this->meters('strom', ['m_s' => 'Wohnung']);
        $this->counters('strom', ['m_s' => self::monthly('2026-05', '2026-11', 160.0, 2000.0)]);
        $this->contracts->create('strom', ['meter_id' => 'm_s', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-05-01', 'working_prices' => $wp,
            'base_prices' => [['from' => '2026-05-01', 'eur_per_month' => 11.0]], 'advance_payments' => [['from' => '2026-05-01', 'amount_eur' => 65.0]]]);
        $status = $this->tool('get_contracts', ['utility' => 'strom'], self::STAND)['targets'][0]['contracts'][0]['status'];
        foreach (['balance_today', 'balance_measured', 'expected_balance'] as $k) {
            if (!isset($status[$k])) continue;
            self::assertSame('no_price', $status[$k]['meaning'], $k);
            $text = (string)$status[$k]['text'];
            self::assertStringNotContainsString('nur die Abschläge', $text, "$k: keine falsche Zuordnung");
            self::assertDoesNotMatchRegularExpression('/\d+,\d\d\s€/', $text, "$k: kein Betrag im Satz, der nicht die Abschläge wäre");
            self::assertStringContainsString('Arbeitspreis fehlt', $text);
        }
        if ($wp === []) self::assertEqualsWithDelta(-432.0, (float)$status['expected_balance']['value'], 0.005, 'die Zahl bleibt in value');
    }

    /** Die Anweisung an die KI beschreibt `no_price` ebenso (kein „nur Abschläge“). */
    public function testTheInstructionsSayTheSame(): void
    {
        $i = (string)$this->i18n->t('mcp.instructions');
        self::assertStringContainsString('no_price: Arbeitspreis fehlt, Kosten unvollständig, nicht bewerten', $i);
        self::assertStringNotContainsString('nur Abschläge', $i);
    }
}
