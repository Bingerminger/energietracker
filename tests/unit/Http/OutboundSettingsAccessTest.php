<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 — Härtung: Was die App nach außen sprechen lässt, ändern nur
 * Verwalter — dazu gehören jetzt auch der Hinweis auf neue Versionen
 * (`update_check`, fragt GitHub) und der Standort samt Wetterabgleich
 * (`location_name`, `latitude`, `longitude`, `weather_auto_fill`; Open-Meteo
 * bekommt die Koordinaten). Bis v3.11 konnte ein Mitglied damit selbst
 * Verbindungen nach außen einschalten oder umlenken. Unverändert
 * mitgeschickt ist keine Änderung (die Seite schickt alle ihre Felder);
 * ohne Anmeldung gibt es keinen Rollenunterschied.
 */
final class OutboundSettingsAccessTest extends HttpServerTestCase
{
    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    private function patch(array $body, array $cookie): int
    {
        return $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $cookie), json_encode($body))['status'];
    }

    public function testOnlyAdminsTurnOnOrRedirectConnectionsToTheOutside(): void
    {
        $this->startServer();
        // ohne Anmeldung: jeder (wie bisher)
        self::assertTrue($this->json('PATCH', '/api/settings', ['update_check' => true])['update_check']);
        self::assertSame(48.5, $this->json('PATCH', '/api/settings', ['latitude' => 48.5])['latitude']);

        $admin = $this->cookieFrom($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Verwalter-Probe-3'])));
        $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'member'], $admin);
        $member = $this->cookieFrom($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Ben', 'password' => 'bens-passwort'])));
        $before = $this->json('GET', '/api/settings', null, $admin);

        $changes = [
            ['update_check' => false],
            ['latitude' => 52.25], ['longitude' => 10.5], ['location_name' => 'Musterstadt'],
            ['weather_auto_fill' => false],
            ['latitude' => 52.25, 'wohnflaeche_m2' => 77],   // das erlaubte Feld daneben geht mit unter
        ];
        foreach ($changes as $body) self::assertSame(403, $this->patch($body, $member), json_encode($body));
        self::assertSame($before, $this->json('GET', '/api/settings', null, $admin), 'nichts gespeichert');

        // unverändert mitgeschickt (die Seite „Wetterdaten“ schickt Ort und Koordinaten zusammen)
        $same = ['update_check' => $before['update_check'], 'latitude' => $before['latitude'], 'longitude' => $before['longitude'],
                 'location_name' => $before['location_name'], 'weather_auto_fill' => $before['weather_auto_fill'], 'wohnflaeche_m2' => 77];
        self::assertSame(200, $this->patch($same, $member));

        // Verwalter: alle
        foreach (['update_check' => false, 'latitude' => 52.25, 'longitude' => 10.5, 'location_name' => 'Musterstadt', 'weather_auto_fill' => false] as $k => $v) {
            self::assertSame($v, $this->json('PATCH', '/api/settings', [$k => $v], $admin)[$k], $k);
        }
    }

    /**
     * v3.12.0 — `null` ist eine Änderung wie jeder Wert. Bisher verglich die Prüfung als Text, und
     * `(string)null` gleicht `(string)false`: Ein Mitglied schaltete den Wetterabgleich, den ein Verwalter
     * ausgeschaltet hatte, mit `null` wieder ein (gespeichert null, der Abgleich las es als „an“).
     * Wahrheitswerte zählen typgenau: „false“, 0 und "" sind dasselbe wie false, also keine Änderung.
     */
    public function testAMemberCannotSwitchAnAdminSettingWithNull(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Verwalter-Probe-3'])));
        $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'member'], $admin);
        $member = $this->cookieFrom($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Ben', 'password' => 'bens-passwort'])));
        self::assertFalse($this->json('PATCH', '/api/settings', ['weather_auto_fill' => false, 'update_check' => false], $admin)['weather_auto_fill']);

        foreach (['weather_auto_fill', 'update_check', 'latitude', 'location_name', 'timezone', 'mcp_enabled'] as $k) {
            self::assertSame(403, $this->patch([$k => null], $member), "$k: null");
        }
        foreach (['weather_auto_fill' => true, 'mcp_enabled' => 'ja', 'update_check' => '1'] as $k => $v) {
            self::assertSame(403, $this->patch([$k => $v], $member), "$k: einschalten");
        }
        $after = $this->json('GET', '/api/settings', null, $admin);
        self::assertFalse($after['weather_auto_fill'], 'der Wetterabgleich bleibt aus');
        self::assertFalse($after['update_check']);
        // gleich gemeint ist keine Änderung (die Seite schickt alle Felder mit)
        foreach (['false', 0, '', 'nein'] as $same) {
            self::assertSame(200, $this->patch(['weather_auto_fill' => $same], $member), var_export($same, true));
        }
        self::assertFalse($this->json('GET', '/api/settings', null, $admin)['weather_auto_fill']);
        // Verwalter: null ist „aus“ (Support\Input::bool), nicht „an“
        self::assertTrue($this->json('PATCH', '/api/settings', ['weather_auto_fill' => true], $admin)['weather_auto_fill']);
        self::assertFalse($this->json('PATCH', '/api/settings', ['weather_auto_fill' => null], $admin)['weather_auto_fill']);
    }
}
