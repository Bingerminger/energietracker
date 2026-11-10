<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 (F1037, Paket oberflaeche) — die Routen der Karte „KI-Assistenten“:
 * `GET /api/mcp/status` (Schalter, feste Sperre per ET_MCP, Freigaben; ohne
 * `mcp_allowed_origins`, das entfällt) und `DELETE /api/mcp/proposals/{id}`
 * (Vorschlag in der App ablehnen). Beides nur für Verwalter, nie mit KI-Schlüssel.
 */
final class McpUiEndpointTest extends HttpServerTestCase
{
    private const SUMMARY = 'Strom, Zähler Flur: Stand 4 321 kWh am 01.11.2026 eintragen (geschätzt: nein).';

    private static function cookie(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    public function testStatusShowsSwitchLockAndSharesWithoutSecrets(): void
    {
        $this->startServer(['language' => 'de']);
        self::assertSame(
            ['enabled' => false, 'setting' => false, 'locked_by_env' => false, 'share_identifiers' => false, 'share_documents' => false, 'apply_in_app' => true, 'login_active' => false],
            $this->json('GET', '/api/mcp/status'), 'ab Werk alles aus, nur die Bestätigung in der App an');

        $this->json('PATCH', '/api/settings', ['mcp_enabled' => true, 'mcp_share_documents' => true]);
        $on = $this->json('GET', '/api/mcp/status');
        self::assertSame([true, true, false, true], [$on['enabled'], $on['setting'], $on['share_identifiers'], $on['share_documents']]);
        self::assertArrayNotHasKey('allowed_origins', $on, 'die Ursprungsliste zeigt die Oberfläche nicht');
    }

    public function testEnvironmentLockIsVisibleEvenWhenSwitchedOn(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true], ['ET_MCP' => 'off']);
        $s = $this->json('GET', '/api/mcp/status');
        self::assertSame([false, true, true], [$s['enabled'], $s['setting'], $s['locked_by_env']]);
    }

    public function testRejectProposalOnlyForAdminsAndOnlyWhileOpen(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true]);
        $admin = self::cookie($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $created = $this->json('POST', '/api/auth/keys', ['name' => 'Laptop', 'scope' => 'mcp_write'], $admin);
        $pending = new PendingChanges($this->dataDir);
        $ctx = new McpContext((string)$created['id'], 'Laptop', 'mcp_write', 'de');
        $p = $pending->create($ctx, 'reading', ['utility' => 'strom', 'counter' => 4321], self::SUMMARY);
        $keep = $pending->create($ctx, 'reading', ['utility' => 'strom', 'counter' => 4322], self::SUMMARY);

        $ids = array_column($this->json('GET', '/api/mcp/proposals', null, $admin), 'id');
        sort($ids);
        $both = [$p['id'], $keep['id']];
        sort($both);
        self::assertSame($both, $ids);
        self::assertSame('Laptop', $this->json('GET', '/api/mcp/proposals', null, $admin)[0]['key_name']);

        // der KI-Schlüssel selbst darf weder sehen noch ablehnen (nur /api/mcp)
        self::assertSame(403, $this->request('DELETE', '/api/mcp/proposals/' . $p['id'], ['Authorization: Bearer ' . $created['key']])['status']);
        self::assertSame(403, $this->request('GET', '/api/mcp/status', ['Authorization: Bearer ' . $created['key']])['status']);

        // Mitglieder: nur Verwalter
        $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $member = self::cookie($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Anna', 'password' => 'annas-passwort'])));
        self::assertSame('errors.auth.adminOnly', json_decode($this->request('DELETE', '/api/mcp/proposals/' . $p['id'], [...$member, $this->ownPage()])['body'], true)['code'] ?? null);
        self::assertSame(403, $this->request('GET', '/api/mcp/status', $member)['status']);

        $r = $this->request('DELETE', '/api/mcp/proposals/' . $p['id'], [...$admin, $this->ownPage()]);
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(['rejected' => true], json_decode($r['body'], true)['data']);
        self::assertSame([$keep['id']], array_column($this->json('GET', '/api/mcp/proposals', null, $admin), 'id'));

        $again = $this->request('DELETE', '/api/mcp/proposals/' . $p['id'], [...$admin, $this->ownPage()]);
        self::assertSame([404, 'errors.mcp.proposal.gone'], [$again['status'], json_decode($again['body'], true)['code'] ?? null]);
        self::assertStringContainsString('nicht mehr', json_decode($again['body'], true)['error'] ?? '');
        self::assertSame(404, $this->request('DELETE', '/api/mcp/proposals/unbekannt', [...$admin, $this->ownPage()])['status']);
    }

    /**
     * v3.12.0 (Skeptiker M1) — Übernehmen in der App: nur Verwalter, nie mit dem KI-Schlüssel selbst, CSRF wie die
     * Oberfläche, dieselbe Fassung wie apply_proposal; widerrufener Schlüssel und ausgeschaltete Schnittstelle gelten nicht.
     */
    public function testApplyInTheAppOnlyForAdminsAndWritesOnce(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true]);
        $admin = self::cookie($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $created = $this->json('POST', '/api/auth/keys', ['name' => 'Laptop', 'scope' => 'mcp_write'], $admin);
        $ctx = new McpContext((string)$created['id'], 'Laptop', 'mcp_write', 'de');
        $m = $this->json('GET', '/api/utility/strom/meters', null, $admin)[0];
        $meter = $m['id'];
        $day = (string)$m['devices'][0]['installed_on'];   // der Tag, ab dem das Gerät misst: unabhängig vom heutigen Datum
        $payload = ['utility' => 'strom', 'meter_id' => $meter, 'date' => $day, 'counter' => 4321.0, 'is_estimated' => false, 'note' => '', 'issues' => []];
        $p = (new PendingChanges($this->dataDir))->create($ctx, 'reading', $payload, self::SUMMARY, PendingChanges::TTL_APP_SECONDS, ['name' => 'Flur']);
        $url = '/api/mcp/proposals/' . $p['id'] . '/apply';

        self::assertSame(['name' => 'Flur'], $this->json('GET', '/api/mcp/proposals', null, $admin)[0]['untrusted'], 'die Karte zeigt den Namen neben dem Satz');
        self::assertSame(403, $this->request('POST', $url, ['Authorization: Bearer ' . $created['key']])['status'], 'der KI-Schlüssel bestätigt sich nicht selbst');
        $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $member = self::cookie($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Anna', 'password' => 'annas-passwort'])));
        self::assertSame('errors.auth.adminOnly', json_decode($this->request('POST', $url, [...$member, $this->ownPage()])['body'], true)['code'] ?? null, 'auch aus der Oberfläche nur Verwalter');

        $r = $this->request('POST', $url, [...$admin, $this->ownPage()]);
        self::assertSame(200, $r['status'], $r['body']);
        $data = json_decode($r['body'], true)['data'];
        self::assertSame(['applied', 'reading'], [$data['status'], $data['kind']]);
        $readings = $this->json('GET', "/api/utility/strom/readings", null, $admin);
        $row = array_values(array_filter($readings, fn($x) => $x['date'] === $day));
        self::assertCount(1, $row);
        self::assertSame('mcp', $row[0]['source']);
        $activity = array_values(array_filter($this->json('GET', '/api/activity', null, $admin), fn($e) => ($e['title_key'] ?? '') === 'activity.mcp.reading'))[0];
        self::assertSame('Laptop', $activity['by']['name']);
        self::assertSame('person', $activity['by']['confirmed_by']['type']);
        self::assertSame(self::SUMMARY, $activity['changes']);
        // ein zweites Mal: weg
        $again = $this->request('POST', $url, [...$admin, $this->ownPage()]);
        self::assertSame([404, 'errors.mcp.proposal.gone'], [$again['status'], json_decode($again['body'], true)['code'] ?? null]);

        // Skeptiker N8: Vorschlag eines widerrufenen Schlüssels: nicht mehr in der Liste, nicht mehr zu übernehmen
        $q = (new PendingChanges($this->dataDir))->create($ctx, 'reading', $payload, self::SUMMARY);
        self::assertSame(200, $this->request('DELETE', '/api/auth/keys/' . $created['id'], $admin)['status']);
        self::assertSame([], $this->json('GET', '/api/mcp/proposals', null, $admin));
        self::assertSame(404, $this->request('POST', '/api/mcp/proposals/' . $q['id'] . '/apply', [...$admin, $this->ownPage()])['status']);

        // ausgeschaltet schreibt die Schnittstelle nichts
        $this->json('PATCH', '/api/settings', ['mcp_enabled' => false], $admin);
        self::assertSame(403, $this->request('POST', '/api/mcp/proposals/' . $q['id'] . '/apply', [...$admin, $this->ownPage()])['status']);
    }

    /** Die eigene Oberfläche nennt ihren Ursprung (so wie jeder Browser bei POST und DELETE). */
    private function ownPage(): string
    {
        return 'Origin: ' . $this->base;
    }

    /**
     * v3.12.0 (Nachprüfung A1) — ab Werk (ohne Anmeldung): Bis dahin schrieb `POST …/apply` mit dem KI-Schlüssel und ganz
     * ohne Schlüssel (200, „in der App bestätigt“). Jetzt nie mit einem Schlüssel, und ohne Ursprung der eigenen Seite auch nicht.
     */
    public function testWithoutSignInAKeyAndAPlainScriptCannotApply(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true]);
        $created = $this->json('POST', '/api/auth/keys', ['name' => 'Laptop', 'scope' => 'mcp_write']);
        $ctx = new McpContext((string)$created['id'], 'Laptop', 'mcp_write', 'de');
        $m = $this->json('GET', '/api/utility/strom/meters')[0];
        $day = (string)$m['devices'][0]['installed_on'];
        $payload = ['utility' => 'strom', 'meter_id' => $m['id'], 'date' => $day, 'counter' => 4321.0, 'is_estimated' => false, 'note' => '', 'issues' => []];
        $pending = new PendingChanges($this->dataDir);
        $p = $pending->create($ctx, 'reading', $payload, self::SUMMARY, PendingChanges::TTL_APP_SECONDS);
        $url = '/api/mcp/proposals/' . $p['id'];
        $bearer = 'Authorization: Bearer ' . $created['key'];
        $code = fn(array $r) => [$r['status'], json_decode($r['body'], true)['code'] ?? null];

        // mit dem KI-Schlüssel, auch mit dem Ursprung der eigenen Seite: nie
        self::assertSame([403, 'errors.mcp.proposal.noKey'], $code($this->request('POST', "$url/apply", [$bearer])));
        self::assertSame([403, 'errors.mcp.proposal.noKey'], $code($this->request('POST', "$url/apply", [$bearer, $this->ownPage()])));
        self::assertSame([403, 'errors.mcp.proposal.noKey'], $code($this->request('DELETE', $url, [$bearer, $this->ownPage()])));
        // die übrigen Routen unter /api/mcp/ auch, jeder Schlüssel, auch ein unbekannter
        foreach (['journal', 'proposals', 'status'] as $r) {
            self::assertSame([403, 'errors.mcp.proposal.noKey'], $code($this->request('GET', "/api/mcp/$r", [$bearer])), $r);
        }
        self::assertSame(403, $this->request('POST', "$url/apply", ['Authorization: Bearer erfunden'])['status']);
        // ein schlichter Aufruf ohne Schlüssel (curl, Skript): nicht aus der Oberfläche
        self::assertSame([403, 'errors.mcp.proposal.appOnly'], $code($this->request('POST', "$url/apply")));
        self::assertSame([403, 'errors.mcp.proposal.appOnly'], $code($this->request('DELETE', $url)));
        self::assertSame([], array_values(array_filter($this->json('GET', '/api/utility/strom/readings'), fn($x) => (float)$x['counter'] === 4321.0)), 'nichts geschrieben');

        // auch an den übrigen Routen hilft der KI-Schlüssel nicht, etwa um den Schalter auszustellen; ohne Schlüssel bleibt die App offen (Bestand)
        self::assertSame(401, $this->request('PATCH', '/api/settings', ['Content-Type: application/json', $bearer], '{"mcp_apply_in_app":false}')['status']);
        self::assertTrue($this->json('GET', '/api/mcp/status')['apply_in_app']);
        self::assertSame(401, $this->request('GET', '/api/summary', [$bearer])['status']);
        self::assertSame(200, $this->request('GET', '/api/summary')['status']);
        // die Kennung für die Karte: keine Anmeldung aktiv
        self::assertFalse($this->json('GET', '/api/mcp/status')['login_active']);

        // aus der Oberfläche: Der Klick geht durch (Browser mit Origin oder Sec-Fetch-Site), fremde Herkunft nicht
        self::assertSame(403, $this->request('POST', "$url/apply", ['Origin: http://evil.example'])['status']);
        self::assertSame(403, $this->request('POST', "$url/apply", ['Sec-Fetch-Site: same-site'])['status']);
        self::assertSame([403, 'errors.mcp.proposal.appOnly'], $code($this->request('POST', "$url/apply", ['Sec-Fetch-Site: none'])), 'direkt eingegeben ist kein Klick aus der Oberfläche');
        $ok = $this->request('POST', "$url/apply", [$this->ownPage()]);
        self::assertSame(200, $ok['status'], $ok['body']);
        $q = $pending->create($ctx, 'reading', $payload, self::SUMMARY, PendingChanges::TTL_APP_SECONDS);
        self::assertSame(200, $this->request('DELETE', '/api/mcp/proposals/' . $q['id'], ['Sec-Fetch-Site: same-origin'])['status']);
    }

    /** v3.12.0 (Nachprüfung A1) — die Texte sagen, was der Schalter schützt und was nicht, in allen Sprachen und in der Anleitung. */
    public function testTheTextsSayWhatTheSwitchDoesNotProtect(): void
    {
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $l) {
            $cat = json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/public/locales/$l.json"), true);
            self::assertArrayHasKey('applyInAppNoLogin', $cat['settings']['mcp'], $l);
            self::assertDoesNotMatchRegularExpression('/s[ck]ript/i', $cat['settings']['mcp']['applyInAppHint'], "$l: keine Zusage für Skripte");
            self::assertNotSame('', $cat['errors']['mcp']['proposal']['noKey'] ?? '', $l);
            self::assertNotSame('', $cat['errors']['mcp']['proposal']['appOnly'] ?? '', $l);
        }
        $de = (string)preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/docs/anleitungen/ki-assistent.md'));
        $en = (string)preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/docs/en/anleitungen/ki-assistent.md'));
        self::assertStringNotContainsString('kein Skript', $de);
        self::assertStringNotContainsString('no script', $en);
        self::assertStringContainsString('Dagegen hilft nur die Anmeldung', $de);
        self::assertStringContainsString('Only sign-in helps against this', $en);

        // v3.12.0 (Nachprüfung C2) — die Referenz: Keine Kennung im Satz, zuerst die Frist ab Werk
        $api = fn(string $p, string $from) => (string)preg_replace('/\s+/', ' ', substr($s = (string)file_get_contents(dirname(__DIR__, 3) . "/$p"), (int)strpos($s, $from), 1400));
        $apiDe = $api('docs/referenz/api.md', '#### Ändern: Vorschlag und Übernahme');
        $apiEn = $api('docs/en/referenz/api.md', '#### Changing data: proposal and apply');
        self::assertStringNotContainsString('Zähler-ID', $apiDe);
        self::assertStringNotContainsString('meter ID', $apiEn);
        self::assertLessThan((int)strpos($apiDe, '**15 Minuten**'), (int)strpos($apiDe, '**24 Stunden**'));
        self::assertLessThan((int)strpos($apiEn, '**15 minutes**'), (int)strpos($apiEn, '**24 hours**'));
    }

    /** v3.12.0 (Skeptiker M8) — Ablage im Posteingang mit dem KI-Schlüssel: nur eingeschaltet, Protokollzeile, Herkunft, eigener Topf. */
    public function testFilingWithAnAiKeyIsLoggedAndLimited(): void
    {
        $this->startServer(['language' => 'de']);
        $admin = self::cookie($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $created = $this->json('POST', '/api/auth/keys', ['name' => 'Laptop', 'scope' => 'mcp_write'], $admin);
        $head = ['Authorization: Bearer ' . $created['key'], 'Content-Type: application/pdf'];
        $pdf = fn(string $t) => "%PDF-1.4\n1 0 obj<<>>endobj\n% $t\n%%EOF";
        $off = $this->request('POST', '/api/inbox?name=a.pdf', $head, $pdf('aus'));
        self::assertSame([403, 'errors.mcp.disabled'], [$off['status'], json_decode($off['body'], true)['code'] ?? null], 'ausgeschaltet: keine KI');

        $this->json('PATCH', '/api/settings', ['mcp_enabled' => true], $admin);
        $ok = $this->request('POST', '/api/inbox?name=a.pdf', $head, $pdf('eins'));
        self::assertSame(201, $ok['status'], $ok['body']);
        self::assertSame('mcp', json_decode($ok['body'], true)['data']['source']);
        $line = array_values(array_filter($this->json('GET', '/api/mcp/journal', null, $admin), fn($e) => ($e['method'] ?? '') === 'inbox/upload'));
        self::assertCount(1, $line);
        self::assertSame(['Laptop', 'ok', 201, 'POST /api/inbox'], [$line[0]['key_name'], $line[0]['result'], $line[0]['status'], $line[0]['name']]);
        self::assertStringNotContainsString('a.pdf', json_encode($line[0]), 'ohne Inhalte');

        // v3.12.0 (Nachprüfung A2) — ein Doppel nennt der KI nur die Kennung: Der Dateiname kann eine Nummer tragen. Der Oberfläche bleibt er.
        $this->json('PATCH', '/api/settings', ['mcp_enabled' => true], $admin);
        $this->request('POST', '/api/inbox?name=Rechnung_K-DOPPEL-MARKE.pdf', [...$admin, 'Content-Type: application/pdf'], $pdf('doppel'));
        $dup = $this->request('POST', '/api/inbox?name=andere.pdf', $head, $pdf('doppel'));
        self::assertSame(409, $dup['status'], $dup['body']);
        self::assertStringNotContainsString('K-DOPPEL-MARKE', $dup['body'], 'KI: kein Dateiname');
        self::assertNotSame('', json_decode($dup['body'], true)['detail']['existing']['id'] ?? '', 'die Kennung bleibt');
        self::assertSame(['id', 'created_at'], array_keys(json_decode($dup['body'], true)['detail']['existing']));
        self::assertStringContainsString('K-DOPPEL-MARKE', $this->request('POST', '/api/inbox?name=andere.pdf', [...$admin, 'Content-Type: application/pdf'], $pdf('doppel'))['body'], 'Oberfläche: mit Name');

        $bad = $this->request('POST', '/api/inbox?name=b.pdf', $head, 'kein pdf');
        self::assertSame(400, $bad['status']);
        self::assertSame('error', $this->json('GET', '/api/mcp/journal', null, $admin)[0]['result'], 'auch das Scheitern steht im Protokoll');
        // größer als die Grenze für diesen Bereich
        $big = $this->request('POST', '/api/inbox?name=c.pdf', $head, '%PDF-1.4' . str_repeat('x', 15 * 1048576));
        self::assertSame(413, $big['status']);

        // Topf: drei gezählt (die 413 kommt vor der Route), noch 27 frei, dann 429 mit Retry-After
        for ($i = 0; $i < 27; $i++) $this->request('POST', '/api/inbox?name=x.pdf', $head, 'kein pdf');
        $full = $this->request('POST', '/api/inbox?name=d.pdf', $head, $pdf('voll'));
        self::assertSame([429, 'errors.mcp.rateLimited'], [$full['status'], json_decode($full['body'], true)['code'] ?? null]);
        self::assertArrayHasKey('retry-after', $full['headers']);
    }
}
