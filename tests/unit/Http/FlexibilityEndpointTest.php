<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.6.0 — `GET /api/flexibility` trägt additiv `insights` (Ampel, Hebel,
 * Zeitfenster, Einladung); die bisherigen Felder bleiben.
 */
final class FlexibilityEndpointTest extends HttpServerTestCase
{
    public function testTheAnalysisCarriesInsightsAdditively(): void
    {
        $this->startServer();
        $r = $this->json('GET', '/api/flexibility?year=2025');
        foreach (['year', 'prices', 'meters', 'battery', 'module3', 'insights'] as $k) self::assertArrayHasKey($k, $r, $k);
        $x = $r['insights'];
        foreach (['signal', 'season_now', 'pattern', 'levers', 'total_eur', 'total_real_eur', 'actions', 'solar', 'achieved', 'missing', 'assumptions'] as $k) {
            self::assertArrayHasKey($k, $x, "insights.$k");
        }
        self::assertSame(['prices', 'intervals', 'devices', 'tariff'], $x['missing'], 'frische Installation: Einladung');
        self::assertSame(0.3, $x['assumptions']['heat_pump_share']);

        $bad = $this->request('GET', '/api/flexibility?year=abc');
        self::assertSame(400, $bad['status']);
        self::assertSame('errors.flexibility.yearInvalid', json_decode($bad['body'], true)['code'] ?? null);
        self::assertSame(['year' => 2025, 'summary' => null], $this->json('GET', '/api/flexibility?year=2025&summary=1'), 'ohne Hebel keine Zahl');
        self::assertSame(400, $this->request('GET', '/api/flexibility?year=abc&summary=1')['status']);
    }

    /**
     * v3.6.0 (Paket flexplus) — `summary=1`: nur die Zahl der Ergebniskarte für
     * die Kachel der Übersicht, dieselbe wie in `insights.summary`.
     */
    public function testTheSummaryIsTheCardsNumberAlone(): void
    {
        $this->startServer();
        $imp = $this->request('POST', '/api/demo/import', ['Content-Type: application/json'], json_encode(['force' => true, 'persona' => 'eigenheim-modern']));
        self::assertSame(200, $imp['status']);
        $s = $this->json('GET', '/api/flexibility?year=2025&summary=1');
        self::assertSame(['year', 'summary'], array_keys($s), 'ohne Nachrechnung je Zähler');
        $full = $this->json('GET', '/api/flexibility?year=2025')['insights'];
        self::assertSame($full['summary']['eur'], $s['summary']['eur']);
        self::assertSame($full['total_real_eur'], $s['summary']['eur'], 'mit dem Vertrag, wie er ist');
        self::assertSame($full['total_eur'], $s['summary']['dynamic_eur']);
        self::assertTrue($s['summary']['measured']);
        self::assertTrue($s['summary']['real']);
        self::assertSame(19, $s['summary']['vat_pct'], 'Satz des dynamischen Angebots');
        self::assertTrue($s['summary']['action']['real']);
        self::assertSame('sessions', $full['ev_availability']['basis'], 'Steckzeiten aus evcc');
    }
}
