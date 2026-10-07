<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\AuthService;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.1.0 (Ökosystem G1–G3) — Betrieb unter Home-Assistant-Ingress und neben
 * einer zweiten Installation auf demselben Ursprung.
 *
 * G1: Bis v3.0 löschte der Service Worker beim Aktivieren jeden fremden Cache,
 * und die Selbstheilung in index.php meldete jeden Worker des Ursprungs ab —
 * unter Ingress die von Home Assistant, auf der NAS die der zweiten
 * Installation. G2: HA schickt den Benutzer als X-Remote-User-Name. G3: Die
 * HA-Vorlage nannte unter Ingress eine Adresse, die HA nicht abrufen kann.
 */
final class IngressTest extends HttpServerTestCase
{
    public function testUnderIngressNoServiceWorkerAndAnInternalAddress(): void
    {
        $this->startServer();
        $plain = $this->request('GET', '/');
        self::assertStringContainsString("serviceWorker.register('sw.js')", $plain['body']);
        self::assertStringNotContainsString('et-ingress-host', $plain['body']);

        $ingress = $this->request('GET', '/', ['X-Ingress-Path: /api/hassio_ingress/abc123']);
        self::assertSame(200, $ingress['status']);
        self::assertStringNotContainsString("serviceWorker.register('sw.js')", $ingress['body'], 'kein eigener Worker unter Ingress');
        self::assertMatchesRegularExpression('/<meta name="et-ingress-host" content="[^"]+">/', $ingress['body']);
    }

    public function testCachesAndWorkersAreScopedToThisInstallation(): void
    {
        $root = dirname(__DIR__, 3);
        $sw = (string)file_get_contents("$root/sw.js");
        self::assertMatchesRegularExpression('/const PREFIX = `et:\$\{SCOPE\}:`/', $sw);
        self::assertMatchesRegularExpression('/keys\.filter\(k => \(k\.startsWith\(PREFIX\) \|\| isLegacy\(k\)\)/', $sw,
            'activate löscht nur eigene (und alte) Caches');
        $index = (string)file_get_contents("$root/index.php");
        self::assertStringContainsString('keys = keys.filter(mine);', $index, 'Selbstheilung sieht nur eigene Caches');
        self::assertMatchesRegularExpression('/rs\.filter\(function \(r\) \{\s*return new URL\(r\.scope\)\.pathname === SCOPE;/', $index,
            'Selbstheilung meldet nur den eigenen Worker ab');
    }

    public function testHomeAssistantUserHeadersAreAccepted(): void
    {
        $prev = getenv('ET_TRUSTED_PROXIES');
        putenv('ET_TRUSTED_PROXIES=172.30.32.2');
        try {
            $auth = (new \ReflectionClass(AuthService::class))->newInstanceWithoutConstructor();
            self::assertSame('anna', $auth->proxyUser(['REMOTE_ADDR' => '172.30.32.2', 'HTTP_X_REMOTE_USER_NAME' => 'anna']));
            self::assertSame('u-42', $auth->proxyUser(['REMOTE_ADDR' => '172.30.32.2', 'HTTP_X_REMOTE_USER_ID' => 'u-42']));
            self::assertNull($auth->proxyUser(['REMOTE_ADDR' => '172.30.32.9', 'HTTP_X_REMOTE_USER_NAME' => 'anna']), 'nur vom Supervisor');
        } finally {
            putenv($prev === false ? 'ET_TRUSTED_PROXIES' : "ET_TRUSTED_PROXIES=$prev");
        }
    }
}
