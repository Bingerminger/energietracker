<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 — „Angaben für Briefe nur Verwalter“ (`letters_admin_only`, ab Werk aus). Mit Anmeldung ändert dann
 * nur ein Verwalter Absender und Lieferanschrift (SettingsService::LETTER_KEYS); ein Mitglied bekommt 403, auch
 * wenn ein erlaubtes Feld daneben steht. Unverändert mitgeschickt ist keine Änderung (die Seite Gebäude schickt
 * alle ihre Felder). Den Schalter selbst stellt nur ein Verwalter. Ohne Anmeldung gibt es keinen Unterschied.
 */
final class LetterSettingsAccessTest extends HttpServerTestCase
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

    public function testOnlyAdminsChangeTheLetterDetailsWhenTheSettingIsOn(): void
    {
        $this->startServer();
        // ab Werk aus, ohne Anmeldung darf jeder
        self::assertFalse($this->json('GET', '/api/settings')['letters_admin_only']);
        self::assertSame('Ida Quellfeld', $this->json('PATCH', '/api/settings', ['letter_sender_name' => 'Ida Quellfeld'])['letter_sender_name']);

        $admin = $this->cookieFrom($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Verwalter-Probe-3'])));
        $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'member'], $admin);
        $member = $this->cookieFrom($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Ben', 'password' => 'bens-passwort'])));

        // aus: auch ein Mitglied trägt Absender und Lieferanschrift ein (wie bis v3.12)
        self::assertSame(200, $this->patch(['letter_sender_address' => "Brombeerpfad 17\n54321 Musterstadt"], $member));
        self::assertSame(200, $this->patch(['letter_supply_address' => 'Brombeerpfad 17'], $member));
        // den Schalter stellt nur ein Verwalter
        self::assertSame(403, $this->patch(['letters_admin_only' => true], $member));
        self::assertTrue($this->json('PATCH', '/api/settings', ['letters_admin_only' => true], $admin)['letters_admin_only']);

        $before = $this->json('GET', '/api/settings', null, $admin);
        foreach ([['letter_sender_name' => 'Ben Quellfeld'], ['letter_sender_address' => 'Lindenweg 3'], ['letter_supply_address' => ''],
                  ['letter_sender_name' => null], ['letter_supply_address' => 'Lindenweg 3', 'wohnflaeche_m2' => 77],
                  ['letter_sender_name' => str_repeat('x', 80)], ['letters_admin_only' => false]] as $body) {
            self::assertSame(403, $this->patch($body, $member), json_encode($body));
        }
        self::assertSame($before, $this->json('GET', '/api/settings', null, $admin), 'nichts gespeichert');

        // unverändert mitgeschickt, auch mit anderem Zeilenende und Leerraum: keine Änderung
        self::assertSame(200, $this->patch(['letter_sender_name' => ' Ida Quellfeld ', 'letter_sender_address' => "Brombeerpfad 17\r\n54321 Musterstadt",
            'letter_supply_address' => 'Brombeerpfad 17', 'letters_admin_only' => true, 'wohnflaeche_m2' => 77], $member));
        self::assertSame(77, $this->json('GET', '/api/settings', null, $admin)['wohnflaeche_m2']);

        // Verwalter: weiter alles
        self::assertSame('Lindenweg 3', $this->json('PATCH', '/api/settings', ['letter_supply_address' => 'Lindenweg 3'], $admin)['letter_supply_address']);
        // wieder aus: das Mitglied darf wieder
        $this->json('PATCH', '/api/settings', ['letters_admin_only' => false], $admin);
        self::assertSame(200, $this->patch(['letter_sender_name' => 'Ben Quellfeld'], $member));
    }

    public function testWithoutSignInTheSettingChangesNothing(): void
    {
        $this->startServer();
        self::assertTrue($this->json('PATCH', '/api/settings', ['letters_admin_only' => true])['letters_admin_only']);
        self::assertSame('Weg 1', $this->json('PATCH', '/api/settings', ['letter_supply_address' => 'Weg 1'])['letter_supply_address']);
        self::assertFalse($this->json('PATCH', '/api/settings', ['letters_admin_only' => false])['letters_admin_only']);
    }
}
