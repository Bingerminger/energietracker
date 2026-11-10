<?php
declare(strict_types=1);

namespace Energietracker\Tests\Storage;

use Energietracker\Services\AuthService;
use Energietracker\Services\HomeAssistant\NotifyConfig;
use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\StorageCorruptedException;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Härtung: Dateien mit Geheimnissen liest nur der Benutzer des
 * Webservers.
 *
 * `auth.json` trägt das Sitzungsgeheimnis (damit sind Anmelde-Cookies
 * signiert), Passwort-, Token- und Schlüssel-Hashes. Bis v3.11 schrieb JsonStore
 * jede Datei mit 0644; nur Cloud-Schlüssel und Home-Assistant-Geheimnis setzten
 * danach selbst 0600. Jetzt steht die Liste an einer Stelle
 * (JsonStore::SECRET_FILES): 0600 schon für die Temp-Datei vor dem Umbenennen,
 * für Quarantäne-Kopien, und bei bestehenden Installationen beim nächsten
 * Schreiben oder beim Start (protectSecrets).
 */
final class SecretFilesTest extends TestCase
{
    private string $dir;
    private JsonStore $store;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/et-secret-' . bin2hex(random_bytes(5));
        mkdir($tmp, 0755, true);
        $this->dir = realpath($tmp) ?: $tmp;
        $this->store = new JsonStore($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function mode(string $rel): string
    {
        clearstatcache();
        return substr(sprintf('%o', fileperms($this->dir . '/' . $rel)), -4);
    }

    /** Jede Datei, in der ein Dienst Geheimnisse ablegt, steht in der Liste. */
    public function testTheListNamesEveryFileWithSecrets(): void
    {
        foreach ([AuthService::FILE, CloudConfig::KEY_FILE, NotifyConfig::SECRET_FILE] as $file) {
            self::assertContains($file, JsonStore::SECRET_FILES, $file);
            self::assertTrue(JsonStore::isSecret($file), $file);
        }
        foreach (['settings.json', 'readings.json', 'mcp/pending.json', 'auth.json.bak'] as $file) {
            self::assertFalse(JsonStore::isSecret($file), $file);
        }
    }

    public function testSecretsAreWrittenOwnerOnlyEverythingElseStaysReadable(): void
    {
        foreach (JsonStore::SECRET_FILES as $file) {
            $this->store->write($file, ['x' => 1]);
            self::assertSame('0600', $this->mode($file), $file);
        }
        $this->store->write('settings.json', ['language' => 'de']);
        self::assertSame('0644', $this->mode('settings.json'), 'gewöhnliche Daten unverändert');
        $this->store->writeBinary('attachments/att_00000000000000aa.jpg', 'x');
        self::assertSame('0644', $this->mode('attachments/att_00000000000000aa.jpg'));
    }

    /** Die Anmeldung schreibt über AuthService — das Sitzungsgeheimnis entsteht dabei. */
    public function testEnablingTheLoginLeavesAuthJsonOwnerOnly(): void
    {
        $auth = new AuthService($this->store);
        $auth->setPassword('Probe-Passwort-7');
        $auth->issueSession();
        $data = json_decode((string)file_get_contents($this->dir . '/auth.json'), true);
        self::assertNotEmpty($data['session_secret'] ?? null, 'Sitzungsgeheimnis liegt in auth.json');
        self::assertSame('0600', $this->mode('auth.json'));
    }

    /**
     * Eine Temp-Datei mit Geheimnis ist nie für andere lesbar, auch nicht kurz
     * vor dem Umbenennen oder liegen geblieben nach einem Abbruch. Geprüft an der
     * Stelle, die sie anlegt (privat, über Reflection), mit großzügiger umask.
     */
    public function testTheTemporaryFileIsOwnerOnlyBeforeAnyByteIsWritten(): void
    {
        $open = new \ReflectionMethod(JsonStore::class, 'openTemp');
        $old = umask(0);
        try {
            foreach (['auth.json' => '0600', 'ha_notify_secret.json' => '0600', 'settings.json' => '0644'] as $rel => $want) {
                [$path, $fp] = $open->invoke($this->store, $rel, $this->dir . '/' . $rel);
                clearstatcache();
                self::assertSame($want, substr(sprintf('%o', fileperms($path)), -4), "Temp-Datei für $rel");
                self::assertSame(0, (int)filesize($path), 'noch leer');
                fclose($fp);
                unlink($path);
            }
        } finally {
            umask($old);
        }
    }

    /** Bestehende Installation: auth.json mit 0644 aus v3.11 wird beim Start 0600. */
    public function testExistingSecretsAreTightenedOnStart(): void
    {
        foreach (JsonStore::SECRET_FILES as $file) {
            file_put_contents($this->dir . '/' . $file, '{"x":1}');
            // auch nur für die Gruppe lesbar (0640) ist zu weit
            chmod($this->dir . '/' . $file, $file === NotifyConfig::SECRET_FILE ? 0640 : 0644);
        }
        file_put_contents($this->dir . '/settings.json', '{}');
        chmod($this->dir . '/settings.json', 0644);
        self::assertSame(count(JsonStore::SECRET_FILES), $this->store->protectSecrets());
        foreach (JsonStore::SECRET_FILES as $file) self::assertSame('0600', $this->mode($file), $file);
        self::assertSame('0644', $this->mode('settings.json'));
        self::assertSame(0, $this->store->protectSecrets(), 'beim zweiten Start nichts mehr zu tun');
        // fehlende Dateien sind kein Fehler
        unlink($this->dir . '/' . CloudConfig::KEY_FILE);
        self::assertSame(0, $this->store->protectSecrets());
    }

    /** Die Sicherheitsseite (§ 10, DE+EN) nennt jede Datei der Liste und die Rechte. */
    public function testTheSecurityPageNamesEverySecretFile(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/betrieb/sicherheit.md' => 'Dateien mit Geheimnissen liest nur der Webserver',
                  'docs/en/betrieb/sicherheit.md' => 'Only the web server reads files with secrets'] as $rel => $lead) {
            $text = (string)file_get_contents("$root/$rel");
            $pos = strpos($text, $lead);
            self::assertNotFalse($pos, "$rel: Absatz fehlt");
            $para = substr($text, (int)$pos, 900);
            foreach (JsonStore::SECRET_FILES as $file) self::assertStringContainsString("`$file`", $para, "$rel: $file fehlt");
            self::assertStringContainsString('`0600`', $para);
            self::assertStringContainsString('`0644`', $para);
        }
    }

    /** Eine beschädigte auth.json: die Kopie daneben trägt dieselben Geheimnisse. */
    public function testQuarantineCopyOfASecretIsOwnerOnly(): void
    {
        file_put_contents($this->dir . '/auth.json', '{"session_secret": "abgeschnitt');
        chmod($this->dir . '/auth.json', 0600);
        try {
            $this->store->read('auth.json');
            self::fail('keine Ausnahme');
        } catch (StorageCorruptedException $e) {
            $copy = (string)$e->copy;
        }
        self::assertNotSame('', $copy);
        self::assertSame('0600', $this->mode($copy));
    }
}
