<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.10.0 — Das Image startet php-fpm und nginx ohne supervisord: ein
 * POSIX-sh-Skript (docker/start.sh) ist PID 1, reicht SIGTERM/SIGINT weiter
 * und beendet den Container, sobald einer der beiden Dienste endet.
 *
 * Hier läuft das Skript mit nachgebauten Diensten (kleine sh-Skripte im PATH)
 * unter der Shell des Systems — in der CI ist das dash, also strenges POSIX.
 * Mit busybox im echten Image prüft dasselbe der CI-Job `docker`
 * („Startskript (Stoppsignal, beendeter Dienst)“).
 */
final class ContainerStartTest extends TestCase
{
    private string $dir = '';
    /** @var array<int,mixed> Signal → vorheriger Handler des Testprozesses */
    private array $restore = [];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function file(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** Shell für das Skript; ET_TEST_SH=/bin/dash (oder busybox-sh) prüft eine andere. */
    private static function shell(): string
    {
        return getenv('ET_TEST_SH') ?: '/bin/sh';
    }

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_executable(self::shell())) {
            self::markTestSkipped('braucht ' . self::shell());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->restore as $sig => $handler) pcntl_signal($sig, $handler);
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/{,bin/}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($this->dir . '/bin');
            @rmdir($this->dir);
        }
    }

    public function testImageHasNoSupervisorAndStartsTheScript(): void
    {
        $df = self::file('Dockerfile');
        self::assertDoesNotMatchRegularExpression('/^RUN apk add[^\n]*\b(supervisor|python3?|py3-)/m', $df, 'kein supervisor, kein python im Image');
        self::assertStringNotContainsString('supervisord', preg_replace('/^\s*#.*$/m', '', $df), 'nur noch im Kommentar');
        self::assertFileDoesNotExist(self::root() . '/docker/supervisord.conf');
        self::assertMatchesRegularExpression('#^COPY docker/start\.sh\s+/usr/local/bin/start\.sh$#m', $df);
        self::assertStringContainsString('chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/start.sh', $df);
        self::assertSame(1, preg_match_all('/^CMD .*$/m', $df, $cmd), 'ein CMD in der Stufe base — beide Ziele erben es');
        self::assertSame('CMD ["/usr/local/bin/start.sh"]', $cmd[0][0]);
        self::assertLessThan(strpos($df, 'AS rootless'), strpos($df, 'CMD ['), 'CMD steht vor den Zielen');
        // entrypoint.sh übergibt mit exec — das Startskript wird PID 1 und bekommt die Signale
        self::assertStringEndsWith("exec \"\$@\"\n", self::file('docker/entrypoint.sh'));
    }

    /**
     * Neu startet seit v3.10.0 Docker, nicht mehr supervisord im Container: Jedes Beispiel und
     * jede Vorlage braucht eine restart-Policy, sonst bliebe der Container nach einem Ausfall aus.
     */
    public function testEveryExampleRestartsTheContainer(): void
    {
        $runs = 0;
        // v3.10.0 (doku) — alle Markdown-Seiten statt einer festen Liste: „Erste Schritte“ (DE/EN) fehlte darin
        // und zeigte den Befehl noch ohne Policy
        $root = dirname(__DIR__, 3);
        $files = array_map(fn($p) => basename($p), glob($root . '/*.md') ?: []);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/docs', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'md') $files[] = substr($f->getPathname(), strlen($root) + 1);
        }
        $seen = [];
        foreach ($files as $rel) {
            $md = self::file($rel);
            // ein Befehl = die Zeile mit `docker run -d --name energietracker` samt Fortsetzungszeilen
            preg_match_all('/^docker run -d --name energietracker(?:[^\n]*\\\\\n)*[^\n]*$/m', $md, $m);
            if ($m[0] !== []) $seen[] = $rel;
            foreach ($m[0] as $cmd) {
                self::assertStringContainsString('--restart unless-stopped', $cmd, "$rel: $cmd");
                $runs++;
            }
        }
        foreach (['README.md', 'README.de.md', 'INSTALL.md', 'INSTALL.de.md', 'docs/betrieb/docker.md', 'docs/en/betrieb/docker.md',
                  'docs/einstieg/erste-schritte.md', 'docs/en/einstieg/erste-schritte.md'] as $rel) {
            self::assertContains($rel, $seen, "$rel: kein docker-run-Beispiel");
        }
        self::assertGreaterThanOrEqual(12, $runs);
        foreach (['docker-compose.yml', 'deploy/casaos/docker-compose.yml'] as $rel) {
            self::assertMatchesRegularExpression('/^\s+restart: unless-stopped$/m', self::file($rel), $rel);
        }
        self::assertMatchesRegularExpression('/^\s+restart: (unless-stopped|on-failure|always)$/m', self::file('deploy/umbrel/docker-compose.yml'));
        self::assertStringContainsString('<ExtraParams>--restart unless-stopped</ExtraParams>', self::file('deploy/unraid/energietracker.xml'));
    }

    public function testScriptIsPlainPosixSh(): void
    {
        $sh = self::file('docker/start.sh');
        self::assertStringStartsWith("#!/bin/sh\n", $sh);
        self::assertTrue(is_executable(self::root() . '/docker/start.sh'), 'ausführbar (das Image setzt es zusätzlich)');
        $code = (string)preg_replace('/^\s*#.*$/m', '', $sh);
        foreach (['[[', 'wait -n', 'function ', 'local ', '<<<', 'pipefail', 'source ', '==', 'declare ', '$RANDOM', '${!'] as $bashism) {
            self::assertStringNotContainsString($bashism, $code, "kein POSIX: $bashism");
        }
        self::assertMatchesRegularExpression('/^php-fpm -F &$/m', $code);
        self::assertMatchesRegularExpression("/^nginx -e \\/dev\\/stderr -g 'daemon off;' &$/m", $code);
        self::assertLessThan(strpos($code, "\nnginx "), strpos($code, "\nphp-fpm "), 'php-fpm vor nginx');
        foreach (['TERM', 'INT', 'QUIT'] as $sig) {
            self::assertMatchesRegularExpression("/^trap .+ $sig$/m", $code, "Handler für SIG$sig");
        }
        $syntax = self::command([self::shell(), '-n',self::root() . '/docker/start.sh']);
        self::assertSame(0, $syntax['code'], 'sh -n: ' . $syntax['err']);
    }

    /** Stoppsignal (docker stop, Home Assistant, Strg+C): beide Dienste bekommen TERM, Ende mit 0. */
    #[DataProvider('signals')]
    public function testStopSignalEndsBothServicesQuickly(int $signal, string $name): void
    {
        if ($signal !== 15) {
            // Ein im Hintergrund gestarteter Testlauf erbt SIGINT/SIGQUIT oft als „ignoriert“, und
            // ein so gestartetes sh kann sie nicht mehr abfangen (POSIX). PID 1 im Container
            // startet mit den Vorgaben — hier dasselbe herstellen.
            if (!function_exists('pcntl_signal')) self::markTestSkipped('pcntl fehlt');
            $this->restore[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, SIG_DFL);
        }
        [$proc, $log, $err] = $this->start();
        $t0 = microtime(true);
        proc_terminate($proc, $signal);
        $code = self::waitFor($proc, 10.0);
        $took = microtime(true) - $t0;
        self::assertSame(0, $code, 'sauberes Ende: ' . file_get_contents($err));
        self::assertLessThan(5.0, $took, 'schnell, nicht erst nach der Frist von Docker');
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertContains('php-fpm TERM', $lines);
        self::assertContains('nginx TERM', $lines);
        self::assertStringContainsString('"level":"info"', (string)file_get_contents($err));
        self::assertStringContainsString('"signal":"' . $name . '"', (string)file_get_contents($err));
    }

    /** @return array<string,array{int,string}> */
    public static function signals(): array
    {
        // SIGQUIT schickt `docker stop`: das Basis-Image setzt STOPSIGNAL SIGQUIT
        return ['SIGTERM' => [15, 'TERM'], 'SIGINT' => [2, 'INT'], 'SIGQUIT (docker stop)' => [3, 'QUIT']];
    }

    /** Endet ein Dienst von selbst, endet das Skript mit Fehlercode und beendet den anderen. */
    #[DataProvider('deaths')]
    public function testAServiceThatEndsEndsTheContainer(string $dies, int $exit, int $expected, string $other): void
    {
        [$proc, $log, $err] = $this->start(['DIE' => $dies, 'DIE_CODE' => (string)$exit]);
        $code = self::waitFor($proc, 10.0);
        self::assertSame($expected, $code, 'Fehlercode, damit Docker bzw. Home Assistant neu startet');
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertContains("$dies ends $exit", $lines);
        self::assertContains("$other TERM", $lines, 'der andere Dienst wird beendet, nicht zurückgelassen');
        $e = (string)file_get_contents($err);
        self::assertStringContainsString('"level":"error"', $e);
        self::assertStringContainsString('"service":"' . $dies . '","exit_code":' . $expected, $e);
    }

    /** @return array<string,array{string,int,int,string}> */
    public static function deaths(): array
    {
        return [
            'nginx mit Fehler'   => ['nginx', 3, 3, 'php-fpm'],
            'php-fpm ohne Fehler' => ['php-fpm', 0, 1, 'nginx'],   // Ende mit 0 heißt trotzdem: Dienst fehlt
        ];
    }

    /**
     * Startet docker/start.sh mit nachgebauten Diensten und wartet, bis beide laufen.
     *
     * @param array<string,string> $env
     * @return array{resource,string,string}
     */
    private function start(array $env = []): array
    {
        $this->dir = sys_get_temp_dir() . '/et-start-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0700, true);
        $log = $this->dir . '/services.log';
        $err = $this->dir . '/stderr.log';
        // Ein Dienst: meldet Start und Argumente, TERM beendet ihn; DIE=<Name> endet nach kurzer Zeit von selbst.
        // Spätestens nach 30 s endet jeder von selbst — bleibt nach einem Fehlschlag nichts zurück, hängt
        // auch keine Pipe des Testlaufs an einem verwaisten Prozess.
        $stub = <<<'SH'
#!/bin/sh
name=${0##*/}
echo "$name start $*" >> "$LOG"
trap 'echo "$name TERM" >> "$LOG"; exit 0' TERM
n=0
while [ "$n" -lt 300 ]; do
    sleep 0.1
    n=$((n + 1))
    if [ "${DIE:-}" = "$name" ] && [ "$n" -ge 5 ]; then
        echo "$name ends $DIE_CODE" >> "$LOG"
        exit "$DIE_CODE"
    fi
done

SH;
        foreach (['php-fpm', 'nginx'] as $svc) {
            file_put_contents($this->dir . "/bin/$svc", $stub);
            chmod($this->dir . "/bin/$svc", 0700);
        }
        $vars = ['PATH' => $this->dir . '/bin:/usr/bin:/bin', 'LOG' => $log] + $env;
        $proc = proc_open([self::shell(), self::root() . '/docker/start.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/stdout.log', 'w'], 2 => ['file', $err, 'w']],
            $pipes, $this->dir, $vars);
        self::assertIsResource($proc);
        $until = microtime(true) + 5.0;
        do {
            usleep(50_000);
            $lines = is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
        } while (count(array_filter($lines, fn($l) => str_contains($l, ' start '))) < 2 && microtime(true) < $until);
        self::assertContains('php-fpm start -F', $lines, 'php-fpm im Vordergrund');
        self::assertContains('nginx start -e /dev/stderr -g daemon off;', $lines, 'nginx im Vordergrund, Fehlerprotokoll nach stderr');
        // (Die Reihenfolge im Protokoll ist ein Wettlauf zweier Hintergrundprozesse; die
        // Reihenfolge im Skript prüft testScriptIsPlainPosixSh.)
        return [$proc, $log, $err];
    }

    /** @param resource $proc */
    private static function waitFor($proc, float $seconds): int
    {
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            $s = proc_get_status($proc);
            if (!$s['running']) {
                proc_close($proc);
                return $s['signaled'] ? 128 + $s['termsig'] : $s['exitcode'];
            }
            usleep(50_000);
        }
        proc_terminate($proc, 9);
        proc_close($proc);
        self::fail("Startskript läuft nach {$seconds} s noch");
    }

    /** @param list<string> $cmd @return array{code:int,err:string} */
    private static function command(array $cmd): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'err' => $err . $out];
    }
}
