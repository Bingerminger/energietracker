<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Http\CrossSiteGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v2.5.3 — CSRF-Abwehr (API-01 im Review 2026-09-24).
 *
 * Nachgewiesen war: Jede Webseite, die jemand im Heimnetz öffnet, konnte per
 * „simple request" (`text/plain`, Formular) Demo-Daten über den Bestand
 * spielen oder das HA-Token rotieren. Die Prüfung darf dabei Home Assistant,
 * curl und Skripte nicht treffen — die senden weder `Sec-Fetch-Site` noch
 * `Origin`.
 */
#[CoversClass(CrossSiteGuard::class)]
final class CrossSiteGuardTest extends TestCase
{
    /** @return array<string,array{string,array<string,string>,bool}> */
    public static function cases(): array
    {
        return [
            'fremde Seite, moderner Browser'        => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
            'andere Seite derselben Site'           => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-site'], true],
            'eigene Oberfläche'                     => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false],
            'direkt eingegeben'                     => ['POST', ['HTTP_SEC_FETCH_SITE' => 'none'], false],
            'Home Assistant / curl ohne Header'     => ['POST', [], false],
            'lesende Anfrage einer fremden Seite'   => ['GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false],
            'PATCH einer fremden Seite'             => ['PATCH', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
            'älterer Browser, fremder Origin'       => ['POST', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'nas:8005'], true],
            'älterer Browser, eigener Origin'       => ['POST', ['HTTP_ORIGIN' => 'http://nas:8005', 'HTTP_HOST' => 'nas:8005'], false],
            'Standardport im Host-Header'           => ['POST', ['HTTP_ORIGIN' => 'http://nas', 'HTTP_HOST' => 'nas:80'], false],
            'hinter Reverse Proxy'                  => ['POST', ['HTTP_ORIGIN' => 'https://energie.example', 'HTTP_HOST' => 'app:80',
                                                                 'HTTP_X_FORWARDED_HOST' => 'energie.example'], false],
            'Origin null (Sandbox-iframe)'          => ['POST', ['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => 'nas:8005'], true],
            'Sec-Fetch-Site schlägt Origin'         => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site',
                                                                 'HTTP_ORIGIN' => 'http://nas:8005', 'HTTP_HOST' => 'nas:8005'], true],
        ];
    }

    #[DataProvider('cases')]
    public function testClassifiesRequests(string $method, array $server, bool $foreign): void
    {
        self::assertSame($foreign, CrossSiteGuard::isForeignWrite($method, $server));
    }
}
