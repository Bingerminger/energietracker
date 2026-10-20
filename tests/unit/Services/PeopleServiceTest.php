<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AuthService;
use Energietracker\Services\BackupService;
use Energietracker\Services\PeopleService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Personenverzeichnis (`people.json`, Topf `people` im Backup):
 * fortlaufende Nummer, die nie wieder vergeben wird; Bestand bekommt beim
 * ersten Lesen Nummern; gelöschte Personen bleiben vermerkt; ein neuer Zugang
 * übernimmt die ID einer Person ohne Zugang mit gleichem Namen.
 */
#[CoversClass(PeopleService::class)]
final class PeopleServiceTest extends ServiceTestCase
{
    private AuthService $auth;
    private PeopleService $people;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->store);
        $this->people = new PeopleService($this->store);
        $this->people->attachAccounts(fn(): array => $this->auth->accounts());
        $this->auth->attachIdAdoption(fn(string $name): ?string => $this->people->adoptableId($name));
    }

    /** Zugang anlegen und gleich ins Verzeichnis (wie SessionController::createUser). */
    private function create(string $name, string $role = 'member'): array
    {
        $u = $this->auth->createUser($name, 'passwort-' . strtolower($name), $role);
        $this->people->all();
        return $u;
    }

    /** @return array<string,int> Name → Nummer */
    private function numbers(): array
    {
        $out = [];
        foreach ($this->people->all()['people'] as $p) $out[$p['name']] = $p['no'];
        return $out;
    }

    public function testExistingAccountsGetNumbersOnFirstReadInTheOrderTheyWereCreated(): void
    {
        // Bestand wie bis v3.5: Zugänge in auth.json, kein Verzeichnis
        $this->store->write('auth.json', ['mode' => 'password', 'users' => [
            ['id' => 'u_bbbb0002', 'name' => 'Ben', 'role' => 'member', 'source' => 'password', 'created_at' => '2026-03-02T10:00:00+01:00'],
            ['id' => 'u_aaaa0001', 'name' => 'Anna', 'role' => 'admin', 'source' => 'password', 'created_at' => '2026-01-15T10:00:00+01:00'],
            ['id' => 'u_cccc0003', 'name' => 'Carla', 'role' => 'member', 'source' => 'proxy', 'created_at' => '2026-03-02T10:00:00+01:00'],
        ]]);
        self::assertFalse($this->store->exists(PeopleService::FILE));
        self::assertSame(['Anna' => 1, 'Ben' => 2, 'Carla' => 3], $this->numbers(), 'nach Anlegedatum, dann ID');
        $file = $this->store->read(PeopleService::FILE, []);
        self::assertSame(3, $file['last_no'], 'beim ersten Lesen geschrieben');
        self::assertSame('admin', $file['people'][0]['role']);
        // zweites Lesen ändert nichts (keine neuen Nummern)
        self::assertSame(['Anna' => 1, 'Ben' => 2, 'Carla' => 3], $this->numbers());
        self::assertSame(['Anna', 'Ben', 'Carla'], array_column($this->people->active(), 'name'));
    }

    public function testTheNumberKeepsCountingAfterADeletionAndIsNeverReused(): void
    {
        $this->auth->setPassword('geheim-123');
        $anna = $this->create('Anna');   // materialisiert „admin“ (Nr. 1)
        $ben = $this->create('Ben');
        self::assertSame(['admin' => 1, 'Anna' => 2, 'Ben' => 3], $this->numbers());

        $this->auth->deleteUser($ben['id']);
        $this->people->markDeleted($ben['id'], 'u_admin', 'Ben');
        $carla = $this->create('Carla');
        self::assertSame(4, $this->people->find($carla['id'])['no'], 'Nr. 3 bleibt Ben');
        $gone = $this->people->find($ben['id']);
        self::assertSame(['no' => 3, 'name' => 'Ben', 'deleted' => true], ['no' => $gone['no'], 'name' => $gone['name'], 'deleted' => $gone['deleted']]);
        self::assertSame('u_admin', $this->store->read(PeopleService::FILE, [])['people'][2]['deleted_by']);
        self::assertSame(4, $this->store->read(PeopleService::FILE, [])['last_no']);
        self::assertNotContains('Ben', array_column($this->people->active(), 'name'), 'gelöschte stehen nicht zur Wahl');

        // eine neue Person mit dem Namen einer gelöschten ist eine neue Person
        $ben2 = $this->create('Ben');
        self::assertNotSame($ben['id'], $ben2['id']);
        self::assertSame(5, $this->people->find($ben2['id'])['no']);
        self::assertSame($anna['id'], $this->people->find($anna['id'])['id']);
    }

    public function testNameAndRoleFollowTheAccount(): void
    {
        $this->auth->setPassword('geheim-123');
        $anna = $this->create('Anna');
        $this->auth->updateUser($anna['id'], ['name' => 'Anna Maria', 'role' => 'admin']);
        $p = $this->people->find($anna['id']);
        self::assertSame(['Anna Maria', 'admin', 2], [$p['name'], $p['role'], $p['no']], 'Nummer bleibt');
    }

    public function testANewAccountAdoptsThePersonWithoutLoginOfTheSameName(): void
    {
        // nach einem Umzug per Backup: Personen im Verzeichnis, noch keine Zugänge
        $this->store->write(PeopleService::FILE, ['last_no' => 7, 'people' => [
            ['id' => 'u_1a2b3c4d', 'no' => 2, 'name' => 'Anna', 'role' => 'admin', 'created_at' => '2025-01-01'],
            ['id' => 'u_5e6f7a8b', 'no' => 5, 'name' => 'Ben', 'role' => 'member', 'created_at' => '2025-02-01',
             'deleted_at' => '2025-06-01T10:00:00+02:00', 'deleted_by' => 'u_1a2b3c4d'],
        ]]);
        $this->auth->setPassword('geheim-123');
        $anna = $this->auth->createUser('anna', 'annas-passwort', 'admin');
        self::assertSame('u_1a2b3c4d', $anna['id'], 'dieselbe Person');
        self::assertSame(2, $this->people->find($anna['id'])['no']);
        $ben = $this->auth->createUser('Ben', 'bens-passwort', 'member');
        self::assertNotSame('u_5e6f7a8b', $ben['id'], 'eine gelöschte Person übernimmt niemand');
        self::assertSame(['u_1a2b3c4d' => false, 'u_5e6f7a8b' => true],
            [$anna['id'] => $this->people->find($anna['id'])['deleted'], 'u_5e6f7a8b' => $this->people->find('u_5e6f7a8b')['deleted']]);
        // Nummern danach: admin (materialisiert) und Ben nach dem höchsten Stand
        self::assertGreaterThan(7, $this->people->find($ben['id'])['no']);
        // Proxy-Betrieb: dieselbe Regel für eine Person, die zuerst über den Proxy kommt
        $this->store->write(PeopleService::FILE, ['last_no' => 9, 'people' => [
            ...$this->store->read(PeopleService::FILE, [])['people'],
            ['id' => 'u_99990009', 'no' => 9, 'name' => 'Dora', 'role' => 'member', 'created_at' => ''],
        ]]);
        self::assertSame('u_99990009', $this->auth->proxyUserRecord('Dora')['id']);
    }

    public function testNormalizeRepairsAndTheImportNeverLowersTheCounter(): void
    {
        $n = PeopleService::normalize(['last_no' => 2, 'people' => [
            ['id' => 'u_aa', 'no' => 2, 'name' => ' Anna ', 'role' => 'admin'],
            ['id' => 'u_bb', 'no' => 2, 'name' => 'Ben'],            // doppelte Nummer → nächste
            ['id' => 'u_aa', 'no' => 9, 'name' => 'Doppelt'],        // doppelte ID → fällt weg
            ['id' => 'x', 'name' => 'Ungültig'],
            'Müll',
            ['id' => 'u_cc', 'name' => "Car\nla", 'role' => 'chef'],  // ohne Nummer, unbekannte Rolle
        ]]);
        self::assertSame([['u_aa', 2, 'Anna', 'admin'], ['u_bb', 3, 'Ben', 'member'], ['u_cc', 4, 'Car la', 'member']],
            array_map(fn($p) => [$p['id'], $p['no'], $p['name'], $p['role']], $n['people']));
        self::assertSame(4, $n['last_no']);
        self::assertSame(['last_no' => 0, 'people' => []], PeopleService::normalize([]), 'leerer Topf (completePayload)');

        $older = ['last_no' => 3, 'people' => [['id' => 'u_aa', 'no' => 1, 'name' => 'Anna']]];
        self::assertSame(12, PeopleService::mergeForImport($older, ['last_no' => 12, 'people' => []])['last_no'], 'Zähler geht nicht zurück');
        self::assertSame(3, PeopleService::mergeForImport($older, [])['last_no']);
    }

    /** BackupService-Feldliste: das Verzeichnis geht durch Export und Import (Lektion 18). */
    public function testBackupRoundtripKeepsTheRegisterWithDeletedPeople(): void
    {
        $this->auth->setPassword('geheim-123');
        $ben = $this->create('Ben');
        $this->create('Carla');
        $this->auth->deleteUser($ben['id']);
        $this->people->markDeleted($ben['id'], 'u_admin', 'Ben');
        self::assertContains(PeopleService::FILE, BackupService::TOP_POTS);

        $backups = new BackupService($this->store, $this->i18n);
        $backups->beforeExport(fn() => $this->people->all());
        $export = $backups->export(false);
        self::assertSame($this->store->read(PeopleService::FILE, []), $export['people']);
        self::assertSame(3, $export['people']['last_no']);

        // in eine andere Installation (eigener, höherer Zähler): Personen wie im Backup, Zähler bleibt hoch
        $this->store->write(PeopleService::FILE, ['last_no' => 20, 'people' => []]);
        $report = $backups->import($export, false, true);
        self::assertSame(3, $report['people']);
        $after = $this->store->read(PeopleService::FILE, []);
        self::assertSame(array_column($export['people']['people'], 'id'), array_column($after['people'], 'id'));
        self::assertNotNull($after['people'][1]['deleted_at'], 'Löschvermerk bleibt');
        self::assertSame(20, $after['last_no']);

        // ungültiges Verzeichnis: Import bricht vor dem ersten Schreiben ab
        $bad = $export;
        $bad['people'] = ['people' => [['id' => '../x', 'name' => 'Böse']]];
        $dry = null;
        try { $backups->import($bad, true); } catch (\Energietracker\Services\BackupInvalidException $e) { $dry = $e; }
        self::assertNotNull($dry, 'ungültige ID abgelehnt');
    }
}
