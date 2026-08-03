<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v2.2.3 — Schutz gegen den Fehler, der die Oberfläche nach dem Update auf
 * „Lädt…" stehen ließ.
 *
 * Die ES-Module importieren einander ohne Cache-Buster. Lieferte der Service
 * Worker sie unter `stale-while-revalidate` aus dem alten Cache, traf eine
 * frische `app.js` auf ein veraltetes `sidebar.js` ohne den erwarteten Export
 * — ein SyntaxError, der den gesamten Modulgraphen abbrach. Ein einziger
 * veralteter Baustein legte die ganze Anwendung lahm.
 *
 * Diese Prüfungen halten die beiden Gegenmaßnahmen fest. Sie kosten nichts und
 * hätten den Ausfall verhindert.
 */
final class ModuleCacheSafetyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    /**
     * Anwendungscode darf nicht aus einem veralteten Cache kommen. Sonst kann
     * eine neue Shell auf alte Module treffen.
     */
    public function testServiceWorkerServesApplicationCodeNetworkFirst(): void
    {
        $sw = self::read('sw.js');

        self::assertMatchesRegularExpression(
            '#/public/js/.*\n.*\n?.*networkFirst#s',
            $sw,
            'sw.js muss /public/js/ über networkFirst ausliefern — unter '
            . 'stale-while-revalidate liefert er nach einem Update alte Module aus'
        );
        self::assertStringContainsString('/public/locales/', $sw,
            'Auch die Sprachkataloge gehören in den network-first-Zweig');

        // Der Zweig muss VOR dem allgemeinen Zweig für statische Assets stehen,
        // sonst greift stale-while-revalidate zuerst.
        $posModules = strpos($sw, "/public/js/");
        $posStatic  = strpos($sw, 'isStaticAsset(url)) || !sameOrigin');
        self::assertNotFalse($posModules);
        self::assertNotFalse($posStatic);
        self::assertLessThan($posStatic, $posModules,
            'Der network-first-Zweig für Module muss vor dem allgemeinen '
            . 'stale-while-revalidate-Zweig stehen');
    }

    /**
     * Die Shell bringt eine Selbstheilung mit: Trägt ein Cache eine andere
     * Version, wird er samt Worker abgeräumt und einmal neu geladen.
     */
    public function testShellCarriesTheCacheHealingScript(): void
    {
        $index = self::read('index.php');

        self::assertStringContainsString('et-cache-healed', $index,
            'Die Shell braucht die Selbstheilung für veraltete Caches');
        self::assertStringContainsString('caches.keys()', $index);
        self::assertStringContainsString('location.reload()', $index);

        // Ohne Sperre gäbe es eine Endlosschleife aus Löschen und Neuladen.
        self::assertStringContainsString('sessionStorage', $index,
            'Die Heilung braucht eine Sperre gegen Endlosschleifen');

        // Sie muss vor dem Modul-Einstiegspunkt stehen.
        $posHeal   = strpos($index, 'et-cache-healed');
        $posModule = strpos($index, 'type="module"');
        self::assertNotFalse($posHeal);
        self::assertNotFalse($posModule);
        self::assertLessThan($posModule, $posHeal,
            'Die Selbstheilung muss laufen, bevor die Module geladen werden');
    }

    /**
     * Jeder Import zwischen Modulen muss auf eine Datei zeigen, die es gibt und
     * die den Namen auch exportiert. Genau diese Zusage war gebrochen —
     * allerdings über Versionsgrenzen hinweg, was ein Test im selben Stand
     * nicht sieht. Er fängt aber den einfacheren Fall: einen Import, den es im
     * aktuellen Stand gar nicht gibt.
     */
    public function testEveryNamedImportResolvesToAnExistingExport(): void
    {
        $jsDir = self::root() . '/public/js';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($jsDir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'js') $files[] = $f->getPathname();
        }
        self::assertNotEmpty($files);

        $problems = [];
        foreach ($files as $path) {
            $rel = substr($path, strlen(self::root()) + 1);
            $src = (string)file_get_contents($path);

            // import { a, b as c } from './x.js';
            preg_match_all('/import\s*\{([^}]+)\}\s*from\s*[\'"]([^\'"]+)[\'"]/', $src, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $target = $hit[2];
                if (!str_starts_with($target, '.')) continue;   // keine externen
                $resolved = realpath(dirname($path) . '/' . $target);
                if ($resolved === false) {
                    $problems[] = "$rel → $target (Datei fehlt)";
                    continue;
                }
                $targetSrc = (string)file_get_contents($resolved);
                foreach (explode(',', $hit[1]) as $spec) {
                    $name = trim(explode(' as ', trim($spec))[0]);
                    if ($name === '') continue;
                    $pattern = '/export\s+(?:async\s+)?(?:function|const|let|var|class)\s+'
                             . preg_quote($name, '/') . '\b/';
                    $alt = '/export\s*\{[^}]*\b' . preg_quote($name, '/') . '\b[^}]*\}/';
                    if (!preg_match($pattern, $targetSrc) && !preg_match($alt, $targetSrc)) {
                        $problems[] = "$rel → $target exportiert „$name" . '" nicht';
                    }
                }
            }
        }
        self::assertSame([], $problems,
            "Nicht auflösbare Modul-Importe:\n" . implode("\n", $problems));
    }
}
