<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\App;
use Energietracker\Http\Request;
use Energietracker\Tests\Support\HttpServerTestCase;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.7.0 (Posteingang) — die Routen über HTTP: ablegen (roher Körper, groß),
 * Doppel mit 409 und dem vorhandenen Eintrag, Vorschau, Zuordnung, Auslesen ohne
 * Dienst, Warteschlange, Löschen; Auslesen sperrt erst nach der Antwort; den
 * Anbieter wechselt nur ein Verwalter.
 */
final class InboxEndpointTest extends HttpServerTestCase
{
    private function upload(string $pdf, string $name = 'rechnung.pdf'): array
    {
        $r = $this->request('POST', '/api/inbox?name=' . rawurlencode($name), ['Content-Type: application/pdf'], $pdf);
        return ['status' => $r['status'], 'body' => json_decode($r['body'], true)];
    }

    public function testTheInboxOverHttp(): void
    {
        $this->startServer();
        $a = $this->upload(InboxFixture::pdf('h1'));
        self::assertSame(201, $a['status']);
        $id = $a['body']['data']['id'];
        self::assertMatchesRegularExpression('/^inb_[0-9a-f]{12}$/', $id);

        $dup = $this->upload(InboxFixture::pdf('h1'), 'nochmal.pdf');
        self::assertSame(409, $dup['status']);
        self::assertSame('errors.inbox.duplicate', $dup['body']['code']);
        self::assertSame($id, $dup['body']['detail']['existing']['id']);
        self::assertStringContainsString('rechnung.pdf', $dup['body']['error'], 'nennt das vorhandene Dokument');

        self::assertSame('errors.inbox.pdfOnly', $this->upload("\xFF\xD8\xFF\xE0 foto")['body']['code']);

        $list = $this->json('GET', '/api/inbox');
        self::assertCount(1, $list['entries']);
        self::assertSame('local', $list['provider']['id']);
        self::assertFalse($list['provider']['available'], 'ohne Adresse der Texterkennung kein Auslesen');
        self::assertSame('qwen3.8', $list['provider']['model']);

        $p = $this->json('GET', "/api/inbox/$id");
        self::assertSame($id, $p['entry']['id']);
        self::assertSame([], $p['proposals']);
        self::assertArrayNotHasKey('cache', $p['entry']);

        $q = $this->json('PATCH', "/api/inbox/$id", ['assignment' => ['utility' => 'gas', 'meter_id' => 'm_gas_default', 'contract_id' => null]]);
        self::assertSame('gas', $q['assignment']['utility']);
        self::assertTrue($q['assignment_saved']);

        $r = $this->request('POST', "/api/inbox/$id/classify", ['Content-Type: application/json'], json_encode(['pages' => InboxFixture::invoicePages()]));
        self::assertSame(400, $r['status']);
        self::assertSame('errors.inbox.off', json_decode($r['body'], true)['code']);
        $r = $this->request('POST', "/api/inbox/$id/extract", ['Content-Type: application/json'], json_encode(['pages' => 'kein Array']));
        self::assertSame('errors.inbox.pagesInvalid', json_decode($r['body'], true)['code']);

        self::assertSame(1, $this->json('POST', '/api/inbox/queue', [])['added']);
        self::assertSame(1, $this->json('DELETE', '/api/inbox/queue')['cancelled']);
        self::assertSame([], $this->json('GET', '/api/inbox/review'));
        self::assertSame(404, $this->request('GET', '/api/inbox/inb_kaputt')['status']);

        $file = $this->request('GET', '/api/attachments/' . $a['body']['data']['attachment_id']);
        self::assertSame(200, $file['status']);
        self::assertSame('application/pdf', $file['headers']['content-type']);

        self::assertTrue($this->json('DELETE', "/api/inbox/$id")['deleted']);
        self::assertSame(404, $this->request('GET', '/api/attachments/' . $a['body']['data']['attachment_id'])['status'], 'das PDF ist mit weg');
    }

    public function testReadingLocksLateAndUploadsMayBeLarge(): void
    {
        $req = fn(string $m, string $p) => (function () use ($m, $p) {
            $r = (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
            foreach (['method' => $m, 'path' => $p] as $k => $v) (new \ReflectionProperty(Request::class, $k))->setValue($r, $v);
            return $r;
        })();
        self::assertTrue(App::locksLate($req('POST', '/api/inbox/inb_0123456789ab/classify')), 'die Schreibsperre wartet nicht auf die KI');
        self::assertTrue(App::locksLate($req('POST', '/api/inbox/inb_0123456789ab/extract')));
        self::assertFalse(App::locksLate($req('POST', '/api/inbox/inb_0123456789ab/apply')), 'Übernehmen sperrt wie jedes Schreiben');
        self::assertSame(App::BODY_LARGE, App::bodyLimit($req('POST', '/api/inbox')));
        self::assertSame(App::BODY_DEFAULT, App::bodyLimit($req('POST', '/api/inbox/inb_0123456789ab/extract')));
    }

    public function testOnlyAdminsChooseWhereDocumentsGo(): void
    {
        $this->startServer();
        $admin = $this->cookie($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $this->json('POST', '/api/users', ['name' => 'Mia', 'password' => 'mias-passwort', 'role' => 'member'], $admin);
        $member = $this->cookie($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Mia', 'password' => 'mias-passwort'])));
        $h = array_merge(['Content-Type: application/json'], $member);
        $r = $this->request('PATCH', '/api/settings', $h, json_encode(['inbox_provider' => 'anthropic']));
        self::assertSame(403, $r['status']);
        self::assertSame('errors.auth.adminOnly', json_decode($r['body'], true)['code']);
        self::assertSame(200, $this->request('PATCH', '/api/settings', $h, json_encode(['inbox_provider' => 'local', 'inbox_model' => 'qwen3.8']))['status'],
            'unverändert mitgeschickt sperrt nicht');
        $ah = array_merge(['Content-Type: application/json'], $admin);
        // v3.7.0 (cloud) — auch ein Verwalter wählt die Cloud erst, wenn sie eingeschaltet ist und ein Schlüssel da ist
        self::assertSame(400, $this->request('PATCH', '/api/settings', $ah, json_encode(['inbox_provider' => 'anthropic']))['status'], 'Cloud aus');
        self::assertSame(200, $this->request('PATCH', '/api/inbox/cloud', $ah, json_encode(['enabled' => true, 'provider' => 'anthropic', 'api_key' => 'sk-ant-test-only-0000']))['status']);
        $p = $this->json('GET', '/api/inbox', null, $admin)['provider'];
        // an der Registry des Posteingangs angemeldet (bootstrap): bekannt, bereit, mit Ziel und Hinweis für die Zustimmung
        self::assertSame(['anthropic', true, true, false, 'api.anthropic.com'], [$p['id'], $p['known'], $p['available'], $p['local'], $p['destination']],
            'Einschalten wählt den Dienst');
        self::assertStringContainsString('api.anthropic.com', (string)$p['notice']);
        self::assertSame(200, $this->request('PATCH', '/api/settings', $ah, json_encode(['inbox_provider' => 'local']))['status']);
        self::assertSame(200, $this->request('PATCH', '/api/settings', $ah, json_encode(['inbox_provider' => 'anthropic']))['status']);
        self::assertSame(400, $this->request('PATCH', '/api/settings', $ah, json_encode(['inbox_provider' => 'openai']))['status'], 'nur der eingeschaltete Dienst');
        self::assertSame(400, $this->request('PATCH', '/api/settings', $ah, json_encode(['inbox_provider' => 'Kein Name!']))['status']);
        // Mitglieder legen ab und lesen aus wie jeder
        $up = $this->request('POST', '/api/inbox?name=a.pdf', array_merge(['Content-Type: application/pdf'], $member), InboxFixture::pdf('m'));
        self::assertSame(201, $up['status']);
    }

    private function cookie(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }
}
