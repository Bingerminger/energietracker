<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\Inbox\FolderIntake;
use Energietracker\Tests\Support\HttpServerTestCase;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.7.0 (Paket betrieb) — Eingangskanäle über HTTP:
 *   - Kurzbefehl „Teilen → Energietracker“: roher PDF-Körper mit API-Schlüssel;
 *     der Schlüssel „Posteingang“ darf genau das und sonst nichts
 *   - Home Assistant (IMAP): JSON mit `pdf_base64`, Zeilenumbrüche wie aus
 *     imap.fetch_part
 *   - überwachter Ordner: nur Verwalter
 *   - Akte und Termin zur Mahnung als Routen; „Zählerstand melden“ führt in der
 *     Agenda direkt in die Erfassung
 */
final class InboxChannelsEndpointTest extends HttpServerTestCase
{
    private function login(): array
    {
        $r = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123']));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    private function key(array $admin, string $scope): string
    {
        return (string)$this->json('POST', '/api/auth/keys', ['name' => "Test $scope", 'scope' => $scope], $admin)['key'];
    }

    public function testShortcutUploadsWithAnInboxKeyThatCanDoNothingElse(): void
    {
        $this->startServer();
        $admin = $this->login();
        $inbox = $this->key($admin, 'inbox');
        $read = $this->key($admin, 'read');
        $write = $this->key($admin, 'admin');
        $pdf = fn(string $k, string $tag, string $q = '?name=Rechnung%20Gas.pdf') => $this->request('POST', '/api/inbox' . $q,
            ['Content-Type: application/pdf', "Authorization: Bearer $k"], InboxFixture::pdf($tag));

        $up = $pdf($inbox, 's1');
        self::assertSame(201, $up['status'], $up['body']);
        self::assertSame('Rechnung Gas.pdf', json_decode($up['body'], true)['data']['name']);
        self::assertSame(401, $this->request('GET', '/api/inbox', ["Authorization: Bearer $inbox"])['status'], 'lesen darf der Schlüssel nicht');
        self::assertSame(401, $this->request('DELETE', '/api/inbox/' . json_decode($up['body'], true)['data']['id'], ["Authorization: Bearer $inbox"])['status']);
        self::assertSame(401, $this->request('POST', '/api/inbox/folder', ["Authorization: Bearer $inbox"])['status']);
        self::assertSame(403, $pdf($read, 's2')['status'], 'ein Lese-Schlüssel legt nichts ab');
        self::assertSame(201, $pdf($write, 's3')['status'], 'ein Schreib-Schlüssel kann es auch');
        // ohne Namen ein erkennbarer statt eines leeren
        $named = json_decode($pdf($inbox, 's4', '')['body'], true)['data']['name'];
        self::assertMatchesRegularExpression('/^Dokument \d{4}-\d{2}-\d{2} \d{2}\.\d{2}\.pdf$/', $named);
        $scopes = array_values(array_unique(array_column($this->json('GET', '/api/auth/keys', null, $admin), 'scope')));
        sort($scopes);
        self::assertSame(['admin', 'inbox', 'read'], $scopes, 'der Bereich steht in der Liste der Schlüssel');
        self::assertSame(400, $this->request('POST', '/api/auth/keys', array_merge(['Content-Type: application/json'], $admin),
            json_encode(['scope' => 'alles']))['status']);
    }

    public function testHomeAssistantSendsTheAttachmentAsBase64(): void
    {
        $this->startServer();
        $b64 = chunk_split(base64_encode(InboxFixture::pdf('ha')), 76, "\r\n");   // wie imap.fetch_part
        $r = $this->request('POST', '/api/inbox', ['Content-Type: application/json'],
            json_encode(['name' => 'Abschlag.pdf', 'pdf_base64' => $b64]));
        self::assertSame(201, $r['status'], $r['body']);
        self::assertSame('Abschlag.pdf', json_decode($r['body'], true)['data']['name']);
        $bad = $this->request('POST', '/api/inbox', ['Content-Type: application/json'], json_encode(['pdf_base64' => '%%% kein base64 %%%']));
        self::assertSame(400, $bad['status']);
        self::assertSame('errors.inbox.base64Invalid', json_decode($bad['body'], true)['code']);
        $notPdf = $this->request('POST', '/api/inbox', ['Content-Type: application/json'], json_encode(['pdf_base64' => base64_encode("\xFF\xD8\xFF foto")]));
        self::assertSame('errors.inbox.pdfOnly', json_decode($notPdf['body'], true)['code']);
        $dup = $this->request('POST', '/api/inbox', ['Content-Type: application/json'], json_encode(['pdf_base64' => $b64]));
        self::assertSame(409, $dup['status'], 'dieselbe Mail zweimal: ein Dokument');
    }

    public function testOnlyAdminsReadTheWatchedFolder(): void
    {
        $this->startServer();
        mkdir($this->dataDir . '/' . FolderIntake::DIR);
        file_put_contents($this->dataDir . '/' . FolderIntake::DIR . '/brief.pdf', InboxFixture::pdf('folder'));
        touch($this->dataDir . '/' . FolderIntake::DIR . '/brief.pdf', time() - 60);
        $admin = $this->login();
        $this->json('POST', '/api/users', ['name' => 'Mia', 'password' => 'mias-passwort', 'role' => 'member'], $admin);
        $r = $this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Mia', 'password' => 'mias-passwort']));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        $member = ['Cookie: et_session=' . ($m[1] ?? '')];
        self::assertSame(403, $this->request('POST', '/api/inbox/folder', $member)['status']);
        self::assertSame(['dir' => 'posteingang', 'present' => true, 'pending' => 1], $this->json('GET', '/api/inbox', null, $member)['folder']);
        $scan = $this->json('POST', '/api/inbox/folder', null, $admin);
        self::assertSame(['brief.pdf'], array_column($scan['imported'], 'name'));
        self::assertFileExists($this->dataDir . '/posteingang/übernommen/brief.pdf');
    }

    public function testRemindDossierAndReadingReminderRoutes(): void
    {
        $this->startServer();
        $up = $this->request('POST', '/api/inbox?name=mahnung.pdf', ['Content-Type: application/pdf'], InboxFixture::pdf('dun'));
        $id = json_decode($up['body'], true)['data']['id'];
        $this->json('PATCH', "/api/inbox/$id", ['type' => 'dunning', 'assignment' => ['utility' => 'gas', 'meter_id' => 'm_gas_default', 'contract_id' => null]]);
        $bad = $this->request('POST', "/api/inbox/$id/remind", ['Content-Type: application/json'], json_encode(['date' => 'morgen']));
        self::assertSame(400, $bad['status']);
        $pv = $this->json('POST', "/api/inbox/$id/remind", ['date' => '2031-01-15']);
        self::assertSame('filed', $pv['entry']['status']);
        self::assertSame('inbox.gist.dunning.set', $pv['gist']['key']);

        $dossier = $this->json('GET', '/api/utility/gas/dossier?meter_id=m_gas_default');
        self::assertSame([$id], array_column(array_filter($dossier['items'], fn($i) => $i['kind'] === 'inbox'), 'inbox_id'));
        self::assertSame('errors.dossier.scope', json_decode($this->request('GET', '/api/utility/gas/dossier')['body'], true)['code']);
        self::assertSame(404, $this->request('GET', '/api/utility/gas/dossier?meter_id=m_gas_fehlt')['status']);

        $due = date('Y-m-d', strtotime('+3 days'));
        $this->json('POST', '/api/reminders', ['title' => 'Zählerstand melden', 'next_due' => $due, 'category' => 'zaehlerstand_melden',
            'utility' => 'gas', 'meter_id' => 'm_gas_default']);
        $ev = array_values(array_filter($this->json('GET', '/api/agenda')['events'], fn($e) => $e['kind'] === 'reminder' && $e['date'] === $due));
        self::assertSame('#/zaehlerstaende?meter=m_gas_default', $ev[0]['href'] ?? null);
    }
}
