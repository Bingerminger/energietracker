<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.5.0 (N1025) — Das Standard-Image als Home-Assistant-App: dieselbe
 * Umgebung wie in der config.yaml der App (`ET_AUTH=proxy`,
 * `ET_TRUSTED_PROXIES` — hier 127.0.0.1 statt 172.30.32.2), Anfragen wie
 * vom Ingress-Proxy des Supervisors und wie von anderen Containern im Netz
 * von Home Assistant. Was nur eine echte Installation zeigt, steht im Bericht
 * zu N1025; hier steht, was sich ohne Home Assistant prüfen lässt.
 */
final class HomeAssistantAppTest extends HttpServerTestCase
{
    /** Kopfzeilen, die der Supervisor einer Ingress-Anfrage mitgibt. */
    private const INGRESS = ['X-Ingress-Path: /api/hassio_ingress/abc123', 'X-Remote-User-Id: ha-7f3a',
        'X-Remote-User-Name: anna', 'X-Remote-User-Display-Name: Anna'];

    private function startAsApp(): void
    {
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'proxy', 'ET_TRUSTED_PROXIES' => '127.0.0.1']);
    }

    /**
     * Der Supervisor schreibt vor jedem Start /data/options.json (root, 0600).
     * Der Healthcheck des Images — für Home Assistant die Überwachung der App —
     * bleibt trotzdem gesund, auch ohne Anmeldung (Docker-HEALTHCHECK).
     */
    public function testTheSupervisorsOptionsFileKeepsTheAppHealthy(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('als root ist jede Datei lesbar');
        }
        $this->startAsApp();
        $options = $this->dataDir . '/options.json';
        file_put_contents($options, '{}');
        chmod($options, 0000);
        try {
            $r = $this->request('GET', '/api/health');
            self::assertSame(200, $r['status'], 'sonst unhealthy → Neustart im Kreis');
            self::assertSame('ok', json_decode($r['body'], true)['data']['status'] ?? null);
        } finally {
            chmod($options, 0644);
        }
    }

    /**
     * Die Seitenleiste: Shell ohne eigenen Service Worker, nur relative Pfade
     * (die Ingress-Adresse liegt unter /api/hassio_ingress/<token>/), die
     * interne Adresse für die HA-Vorlagen und die HA-Person angemeldet.
     */
    public function testTheSidebarWorksUnderIngress(): void
    {
        $this->startAsApp();
        $shell = $this->request('GET', '/', self::INGRESS);
        self::assertSame(200, $shell['status']);
        self::assertStringNotContainsString("serviceWorker.register('sw.js')", $shell['body']);
        self::assertMatchesRegularExpression('/<meta name="et-ingress-host" content="[^"]+">/', $shell['body']);
        self::assertSame(0, preg_match_all('#\s(?:src|href|action)="/(?!/)#', $shell['body'], $abs),
            'absolute Pfade zeigten unter Ingress auf Home Assistant: ' . implode(', ', $abs[0] ?? []));

        $session = (array)$this->json('GET', '/api/session', null, self::INGRESS);
        self::assertSame('proxy', $session['mode']);
        self::assertTrue($session['mode_fixed'], 'die Umgebung der App legt die Anmeldung fest');
        self::assertSame(['anna', 'admin'], [$session['user']['name'] ?? null, $session['role']], 'die erste Person verwaltet');

        $diag = (array)$this->json('GET', '/api/diagnostics', null, self::INGRESS);
        self::assertSame('ingress', $diag['security']['proxy_header']['mode']);
        self::assertSame([], $diag['security']['notices'], 'unter Ingress keine Warnung zur Proxy-Kopfzeile');
    }

    /**
     * Andere Container im Netz von Home Assistant (und der direkte Port) kommen
     * nicht vom Supervisor. Mit genau der Umgebung der App (172.30.32.2) ist
     * der Testclient so ein Fremder: Supervisor-Kopfzeilen selbst gesetzt
     * nennen keine Person, der Push braucht seinen Token, auch wenn noch keiner
     * erzeugt ist. Health antwortet allen (Docker-HEALTHCHECK).
     */
    public function testOutsideIngressOnlyTokensCount(): void
    {
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'proxy', 'ET_TRUSTED_PROXIES' => '172.30.32.2']);
        self::assertSame(401, $this->request('GET', '/api/utilities', self::INGRESS)['status'],
            'selbst gesetzte X-Remote-User-* zählen nur vom Supervisor');
        $session = (array)$this->json('GET', '/api/session', null, self::INGRESS);
        self::assertFalse($session['authenticated']);
        self::assertStringNotContainsString('et-ingress-host', $this->request('GET', '/', self::INGRESS)['body']);

        $push = $this->request('POST', '/api/ingest', ['Content-Type: application/json'],
            json_encode(['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 1.0]));
        self::assertSame(401, $push['status'], 'mit Anmeldung ist der Token Pflicht');
        $health = $this->request('GET', '/api/health');
        self::assertSame(200, $health['status']);
        self::assertSame('ok', json_decode($health['body'], true)['data']['status'] ?? null);
    }
}
