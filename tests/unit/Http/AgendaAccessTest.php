<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.1.0 (Paket H1) — Zugriff auf Kennzahlen (API-33) und Kalender-Abo
 * (MKT-09) bei eingeschalteter Anmeldung.
 *
 * Home Assistant liest `/api/summary` mit einem Lese-Schlüssel. Kalender-Apps
 * können keine Kopfzeile setzen; der Schlüssel steht deshalb im Link — dafür
 * gibt es einen eigenen Bereich `calendar`, der nur dort und nur so gilt. Ein
 * Lese- oder Admin-Schlüssel im Link wird abgelehnt (er landete sonst in Logs
 * und Kalender-Synchronisationen).
 */
final class AgendaAccessTest extends HttpServerTestCase
{
    private const READ = 'etk_readreadreadreadreadreadreadreadreadreadread0';
    private const CAL  = 'etk_calcalcalcalcalcalcalcalcalcalcalcalcalcal01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'password']);
        $keys = [];
        foreach (['read' => self::READ, 'calendar' => self::CAL] as $scope => $key) {
            $keys[] = ['id' => "k_$scope", 'name' => $scope, 'scope' => $scope, 'hash' => hash('sha256', $key),
                       'created_at' => date('c'), 'last_used_at' => null];
        }
        file_put_contents($this->dataDir . '/auth.json', json_encode(['mode' => 'password', 'api_keys' => $keys]));
    }

    public function testSummaryNeedsAReadKey(): void
    {
        self::assertSame(401, $this->request('GET', '/api/summary')['status']);
        $ok = $this->request('GET', '/api/summary', ['Authorization: Bearer ' . self::READ]);
        self::assertSame(200, $ok['status']);
        self::assertSame('private, max-age=300', $ok['headers']['cache-control'] ?? null);
        $data = json_decode($ok['body'], true)['data'] ?? [];
        self::assertSame(1, $data['summary_version'] ?? null);
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', (string)($data['instance_id'] ?? ''));
    }

    public function testCalendarOnlyWithItsOwnKeyInTheLink(): void
    {
        self::assertSame(401, $this->request('GET', '/api/calendar.ics')['status'], 'ohne Schlüssel');
        $ok = $this->request('GET', '/api/calendar.ics?token=' . self::CAL);
        self::assertSame(200, $ok['status']);
        self::assertStringStartsWith('text/calendar', $ok['headers']['content-type'] ?? '');
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ok['body']);

        self::assertSame(401, $this->request('GET', '/api/calendar.ics?token=' . self::READ)['status'],
            'ein Lese-Schlüssel gehört nicht in einen Link');
        self::assertSame(401, $this->request('GET', '/api/summary', ['Authorization: Bearer ' . self::CAL])['status'],
            'der Kalender-Schlüssel gilt nirgends sonst');
        self::assertSame(401, $this->request('GET', '/api/summary?token=' . self::CAL)['status']);
    }
}
