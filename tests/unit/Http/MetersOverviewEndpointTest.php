<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Config\Utilities;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 — GET /api/meters-overview: Haushalt › Zähler „Alle“ mit einer Anfrage statt zwei je Verbrauchsart.
 * Je Art genau dieselben Zähler und Gruppen wie die Einzelabfragen, in der verlangten Reihenfolge; eine
 * unbekannte Art ist ein Fehler wie bei `…/meters`.
 */
final class MetersOverviewEndpointTest extends HttpServerTestCase
{
    public function testEveryUtilityWithTheSameMetersAndGroupsAsTheSingleCalls(): void
    {
        $this->startServer();
        $sub = $this->json('POST', '/api/utility/strom/meters', ['name' => 'Wallbox', 'parent_meter_id' => 'm_strom_default']);
        $this->json('POST', '/api/utility/strom/meter-groups', ['name' => 'Zweitarif', 'member_ids' => ['m_strom_default', $sub['id']]]);

        $all = $this->json('GET', '/api/meters-overview')['utilities'];
        self::assertSame(Utilities::keys(), array_column($all, 'utility'), 'ohne Angabe alle Arten der SSOT');
        foreach ($all as $row) {
            self::assertSame($this->json('GET', "/api/utility/{$row['utility']}/meters"), $row['meters'], $row['utility']);
            self::assertSame($this->json('GET', "/api/utility/{$row['utility']}/meter-groups"), $row['groups'], $row['utility']);
        }
        $strom = $all[array_search('strom', array_column($all, 'utility'), true)];
        self::assertCount(2, $strom['meters']);
        self::assertSame('Zweitarif', $strom['groups'][0]['name'] ?? null);

        $two = $this->json('GET', '/api/meters-overview?utilities=wasser,gas,wasser')['utilities'];
        self::assertSame(['wasser', 'gas'], array_column($two, 'utility'), 'Reihenfolge wie verlangt, doppelt einmal');
    }

    public function testAnUnknownUtilityIsAnError(): void
    {
        $this->startServer();
        $r = $this->request('GET', '/api/meters-overview?utilities=gas,kohle');
        self::assertSame(400, $r['status']);
        self::assertStringContainsString('kohle', $r['body']);
    }
}
