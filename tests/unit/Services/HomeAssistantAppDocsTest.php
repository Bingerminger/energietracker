<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * v3.5.0 (N1025) — Die Anleitung zeigt, was die Home-Assistant-App einstellt
 * (Auszug aus ihrer config.yaml, DE und EN). Die App liegt in einem eigenen
 * Repo und nutzt das Standard-Image unverändert; was die Doku über Port,
 * Datenpfad, Image, Plattformen und Umgebung sagt, muss zu diesem Image
 * passen — sonst stimmt die App nach der nächsten Image-Änderung nicht mehr.
 */
final class HomeAssistantAppDocsTest extends TestCase
{
    private const PAGES = [
        'docs/anleitungen/home-assistant.md'    => '## Als App in Home Assistant OS',
        'docs/en/anleitungen/home-assistant.md' => '## As an app in Home Assistant OS',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    /** Der YAML-Auszug im App-Abschnitt, ohne Kommentare. */
    private static function excerpt(string $rel, string $heading): string
    {
        $md = self::read($rel);
        $start = strpos($md, "\n$heading\n");
        self::assertNotFalse($start, "$rel: Abschnitt „{$heading}“ fehlt");
        $section = substr($md, $start, (int)(strpos($md, "\n## ", $start + 1) ?: strlen($md)) - $start);
        self::assertSame(1, preg_match('/```yaml\n(.*?)```/s', $section, $m), "$rel: Auszug der config.yaml fehlt");
        return (string)preg_replace('/\s+#.*$/m', '', $m[1]);
    }

    /** @return array<string,string> flache Schlüssel → Wert (eine Ebene Einrückung) */
    private static function flat(string $yaml): array
    {
        $out = [];
        $parent = '';
        foreach (preg_split('/\R/', trim($yaml)) ?: [] as $line) {
            if (!preg_match('/^( *)([^:\s][^:]*):\s*(.*)$/', $line, $m)) continue;
            if ($m[1] === '') $parent = '';
            $key = ($m[1] === '' ? '' : $parent . '.') . $m[2];
            if ($m[3] === '') { $parent = $m[2]; continue; }
            $out[$key] = trim($m[3], '"');
        }
        return $out;
    }

    public function testBothLanguagesShowTheSameSettings(): void
    {
        $blocks = [];
        foreach (self::PAGES as $rel => $heading) $blocks[$rel] = self::flat(self::excerpt($rel, $heading));
        [$de, $en] = array_values($blocks);
        self::assertSame($de, $en, 'DE und EN zeigen dieselbe config.yaml');
        foreach (['image', 'arch', 'init', 'ingress', 'ingress_port', 'ingress_stream', 'ports.80/tcp',
                  'environment.ET_DATA_DIR', 'environment.ET_AUTH', 'environment.ET_TRUSTED_PROXIES'] as $k) {
            self::assertArrayHasKey($k, $de, "Auszug ohne $k");
        }
    }

    public function testTheSettingsMatchTheStandardImage(): void
    {
        $cfg = self::flat(self::excerpt('docs/anleitungen/home-assistant.md', self::PAGES['docs/anleitungen/home-assistant.md']));
        $dockerfile = self::read('Dockerfile');
        $nginx = self::read('docker/nginx.conf');
        $publish = self::read('.github/workflows/docker-publish.yml');

        // Port: Ingress spricht nginx im Image direkt an
        self::assertMatchesRegularExpression('/^EXPOSE\s+' . $cfg['ingress_port'] . '\s*$/m', $dockerfile);
        self::assertMatchesRegularExpression('/listen\s+' . $cfg['ingress_port'] . '\b/', $nginx);
        self::assertSame('null', $cfg['ports.80/tcp'], 'direkter Port standardmäßig aus');
        self::assertArrayHasKey('ports.' . $cfg['ingress_port'] . '/tcp', $cfg, 'der Port im Auszug ist der des Images');

        // Datenpfad: das Verzeichnis, das der Supervisor einbindet und sichert, = Standard des Images
        self::assertSame('/data', $cfg['environment.ET_DATA_DIR']);
        self::assertMatchesRegularExpression('#ENV\s+ET_DATA_DIR=/data\b#', $dockerfile);
        self::assertMatchesRegularExpression('#VOLUME\s+\["/data"\]#', $dockerfile);

        // Image und Plattformen: das veröffentlichte Multi-Arch-Image
        self::assertMatchesRegularExpression('/^\s*images:\s*' . preg_quote($cfg['image'], '/') . '\s*$/m', $publish);
        preg_match('/platforms:\s*(\S+)/', $publish, $p);
        $haArch = ['linux/amd64' => 'amd64', 'linux/arm64' => 'aarch64'];
        $published = array_map(fn($pl) => $haArch[$pl] ?? $pl, explode(',', $p[1] ?? ''));
        $documented = array_map('trim', explode(',', trim($cfg['arch'], '[] ')));
        sort($published);
        sort($documented);
        self::assertSame($published, $documented, 'arch der App = Plattformen des Images');

        // init: false, weil das Image seinen eigenen Prozesswächter mitbringt
        self::assertSame('false', $cfg['init']);
        self::assertMatchesRegularExpression('/^CMD \["supervisord"/m', $dockerfile);
        self::assertStringContainsString('exec "$@"', self::read('docker/entrypoint.sh'), 'supervisord wird PID 1');

        // Überwachung: Der Supervisor beobachtet den HEALTHCHECK des Images (watchdog ist überholt)
        self::assertMatchesRegularExpression('#HEALTHCHECK[\s\S]*?/api/health#', $dockerfile);
    }

    /**
     * v3.9.0 (F1033) — Benachrichtigungen über den Supervisor und der
     * Posteingang-Ordner in /share: Die App braucht dafür genau diese
     * Freigaben, der Ordner liegt in der eingebundenen Freigabe.
     */
    public function testTheAppMapsTheShareAndMayCallHomeAssistant(): void
    {
        $cfg = self::flat(self::excerpt('docs/anleitungen/home-assistant.md', self::PAGES['docs/anleitungen/home-assistant.md']));
        self::assertSame('true', $cfg['homeassistant_api'] ?? null, 'Supervisor-API für die Benachrichtigungen');
        self::assertSame('share', $cfg['map.- type'] ?? null, 'Freigabe /share eingebunden');
        self::assertSame('false', $cfg['map.read_only'] ?? null, 'schreibbar: die App verschiebt eingelesene Dateien');
        self::assertStringStartsWith('/share/', $cfg['environment.ET_INBOX_DIR'] ?? '', 'der Ordner liegt in der Freigabe');
        self::assertSame(\Energietracker\Services\HomeAssistant\NotifyConfig::SUPERVISOR_API, 'http://supervisor/core/api');
        // „die App legt ihn beim Start an“: das Standard-Image (root) legt den Ordner an und gibt ihn www-data
        $entry = self::read('docker/entrypoint.sh');
        self::assertStringContainsString('mkdir -p "$ET_INBOX_DIR"', $entry);
        self::assertStringContainsString('chown www-data:www-data "$ET_INBOX_DIR"', $entry, 'nur der Ordner, nicht rekursiv');
        self::assertStringNotContainsString('chown -R www-data:www-data "$ET_INBOX_DIR"', $entry);
        // „der Gesundheitscheck fragt alle 30 Sekunden“ (Doku: tägliche Prüfung, Ordner nach spätestens fünf Minuten)
        self::assertMatchesRegularExpression('#HEALTHCHECK --interval=30s[\s\S]*?/api/health#', self::read('Dockerfile'));
    }

    public function testTheEnvironmentIsReadByTheApp(): void
    {
        $cfg = self::flat(self::excerpt('docs/en/anleitungen/home-assistant.md', self::PAGES['docs/en/anleitungen/home-assistant.md']));
        $code = '';
        // v3.9.0 (F1033) — ET_INBOX_DIR liest der überwachte Ordner
        foreach (['src/bootstrap.php', 'src/Services/AuthService.php', 'index.php', 'api.php', 'src/Services/Inbox/FolderIntake.php'] as $f) $code .= self::read($f);
        foreach ($cfg as $k => $v) {
            if (!str_starts_with($k, 'environment.')) continue;
            $name = substr($k, strlen('environment.'));
            self::assertStringContainsString("getenv('$name')", $code, "$name liest die App nicht");
        }
        self::assertContains($cfg['environment.ET_AUTH'], AuthService::MODES);
        // die Adresse, von der Ingress-Anfragen kommen — dieselbe in Sicherheit & Netzbetrieb
        self::assertSame('172.30.32.2', $cfg['environment.ET_TRUSTED_PROXIES']);
        self::assertStringContainsString('ET_TRUSTED_PROXIES=172.30.32.2', self::read('docs/betrieb/sicherheit.md'));
        self::assertStringContainsString('ET_TRUSTED_PROXIES=172.30.32.2', self::read('docs/en/betrieb/sicherheit.md'));
    }
}
