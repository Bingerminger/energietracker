<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.13.0 (Paket K1, Entscheidung 11 b, #65) — Der Vorschlag einer KI prüft bei Heizarten nach dem Wetter, wie Dialog
 * und Seite „Zählerstände“: `propose_reading`, Stände aus einem Dokument (`propose_document`) und noch einmal beim
 * Übernehmen. Bis dahin fragte die KI bei jedem Heizbeginn nach einem Tippfehler.
 *
 * Erfundener Gaszähler, von Hand samstags abgelesen ab 03.05.2025, 0,4 m³ am Tag (Kochen, Warmwasser), ab November
 * geheizt; Temperatur je Kalendermonat fest (Okt 9, Nov 4, Dez 1 °C), Heizgrenze 15 °C. Alle Tage in der Vergangenheit.
 */
final class McpReadingWeatherTest extends McpInboxTestCase
{
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    /** Samstage von 03.05.2025 bis `$until`, je 7 × 0,4 m³; Rückgabe: der letzte Stand. */
    private function summer(string $until, float $last): float
    {
        $this->gasHousehold();
        $this->settings->set(['hdd_base_temp' => 15.0, 'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.5]]]);
        $temps = [];
        for ($t = strtotime('2025-04-01 12:00'); date('Y-m-d', $t) <= '2026-01-31'; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $dates = [];
        for ($t = strtotime("$until 12:00"); date('Y-m-d', $t) >= '2025-05-03'; $t -= 7 * 86400) $dates[] = date('Y-m-d', $t);
        $rows = []; $c = $last;
        foreach ($dates as $d) { $rows[] = ['date' => $d, 'counter' => round($c, 3), 'device_id' => 'd_gas_1']; $c -= 2.8; }
        $this->setReadings('gas', 'm_gas_default', array_reverse($rows));
        return $last;
    }

    private function propose(string $date, float $counter): array
    {
        return $this->callTool('propose_reading', ['utility' => 'gas', 'meter' => 'm_gas_default', 'date' => $date, 'counter' => $counter], $this->writer());
    }

    /** Erste Heizwoche nach dem Sommer (4 m³ am Tag statt 0,4): keine Rückfrage, übernehmbar ohne „veraltet“. */
    public function testTheFirstHeatingWeekIsNoTypoForTheAi(): void
    {
        $last = $this->summer('2025-11-01', 13100.0);
        $p = $this->propose('2025-11-08', $last + 28.0);
        self::assertSame([], $p['checks']['issues'], 'nach dem Wetter keine Rückfrage (bis v3.12: Sprung)');
        self::assertStringEndsWith('(geschätzt: nein).', $p['summary']);
        $r = $this->callTool('apply_proposal', ['proposal_id' => $p['proposal_id'], 'summary' => $p['summary']], $this->writer());
        self::assertCount(1, $r['applied'], 'beim Übernehmen dieselbe Prüfung, keine neue Rückfrage');
        // die Woche danach mit vergessenem Komma fragt weiter (immer noch kälter als fast alles bisher)
        $q = $this->propose('2025-11-15', ($last + 28.0) * 10);
        self::assertSame(['magnitude'], array_column($q['checks']['issues'], 'type'));
    }

    /** Ein Zahlendreher im Sommer nennt die Erwartung nach dem Wetter, wie in der Erfassung. */
    public function testAJumpByTheWeatherNamesTheExpectation(): void
    {
        $this->summer('2025-08-02', 13100.0);
        $p = $this->propose('2025-08-09', 13100.0 + 7 * 0.4 + 30.0);
        self::assertSame(['jump'], array_column($p['checks']['issues'], 'type'));
        // 7 Tage × 0,4 m³ = 2,8 m³ erwartet (Heizmodell aus dem Sommer), 32,8 m³ seit dem vorigen Stand
        self::assertMatchesRegularExpression('/^Tippfehler\? Seit der letzten Ablesung wären das 32,8 m³, nach dem Wetter erwartet etwa 2,8 m³\.$/u',
            $p['checks']['issues'][0]['message']);
        self::assertStringEndsWith('Achtung: ungewöhnlich großer Zuwachs seit dem vorigen Stand.', $p['summary']);
    }

    /** Ein Stand aus einem Dokument (Rechnung: 13.580 m³ am 31.12.2025) nach einem Herbst ohne Heizung: keine Rückfrage. */
    public function testAReadingFromADocumentIsCheckedByTheWeather(): void
    {
        // bis 01.12. nur Kochen und Warmwasser (der November ungeheizt), dann im Dezember geheizt: 13.580 − 30 × 4 m³
        $this->summer('2025-11-29', 13580.0 - 32 * 4.0);
        $id = (string)$this->inbox->upload(InboxFixture::pdf('wetter'), 'Jahresabrechnung.pdf')['id'];
        $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => InboxFixture::invoicePages(),
            'fields' => InboxFixture::invoiceAnswer()], $this->writer());
        $prop = $this->callTool('propose_document', ['document_id' => $id], $this->writer());
        $reading = array_values(array_filter($prop['items'], fn($i) => $i['kind'] === 'reading' && str_contains($i['text'], '31.12.2025')));
        self::assertCount(1, $reading, (string)json_encode($prop['items']));
        $reasons = array_column(array_filter($prop['warnings'], fn($w) => $w['proposal'] === $reading[0]['id']), 'reason');
        self::assertNotContains('jump', $reasons, 'nach dem Wetter kein Sprung (alt: Sprung)');
    }
}
