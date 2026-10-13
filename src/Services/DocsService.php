<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.4.0 (N1019) — Hilfe auf dem Server: die Dokumentation dieses Release.
 *
 * Die Doku bleibt Markdown unter docs/ (Deutsch kanonisch, der englische
 * Spiegel unter docs/en/) — je Tag dieselben Dateien wie auf GitHub, eine
 * Datenbasis. Die App liest eine Seite über `GET /api/docs?path=…` und rendert
 * sie selbst (public/js/lib/markdown.js); Bilder kommen über
 * `GET /api/docs/asset?path=…`.
 *
 * Ausgeliefert wird nur, was in der Freigabeliste steht. Sie entsteht aus dem
 * Dateibestand:
 *   • Seiten unter docs/ (`*.md`) — ohne die Weiterleitungen alter Pfade
 *     (`<!-- umgezogen: … -->` bzw. `<!-- moved: … -->` in Zeile 1);
 *   • Bilder: PNG unter docs/ui/screenshots/ (docs/assets/promo/ und alles
 *     andere bleibt außen vor);
 *   • eine feste Liste von Dateien im Wurzelverzeichnis (ROOT_FILES), die die
 *     Doku verlinkt, als `root/<name>`.
 * Eine Anfrage ist ein reiner Nachschlag in dieser Map: Der Pfad aus der
 * Anfrage wird nie mit einem Verzeichnis verkettet — `..`, `%2e%2e` oder ein
 * absoluter Pfad sind schlicht kein Schlüssel. Symbolische Links nimmt die
 * Liste nicht auf, und in verlinkte Verzeichnisse steigt sie nicht hinab.
 */
final class DocsService
{
    /** Schlüsselpräfix der Dateien im Wurzelverzeichnis (`root/CHANGELOG.md`). */
    public const ROOT_PREFIX = 'root/';

    /**
     * Dateien im Wurzelverzeichnis mit ihrer Sprache (`mul`: beide Sprachen in
     * einer Datei). Was davon nicht existiert (CHANGELOG.en.md gibt es erst mit
     * zweisprachigen Release-Notes), steht auch nicht in der Freigabeliste.
     */
    public const ROOT_FILES = [
        'CHANGELOG.md'       => 'de',
        'CHANGELOG.en.md'    => 'en',
        'CONTRIBUTING.md'    => 'en',
        'CONTRIBUTING.de.md' => 'de',
        'SECURITY.md'        => 'mul',
        'CREDITS.md'         => 'mul',
        'TRADEMARKS.md'      => 'mul',
        'LICENSE'            => 'en',
    ];

    /** Bilder: Verzeichnis unter docs/ und erlaubte Typen (Endung → Content-Type). */
    private const ASSET_DIR = 'ui/screenshots/';
    private const ASSET_TYPES = ['png' => 'image/png'];

    /** @var array{pages: array<string,string>, assets: array<string,string>, stubs: array<string,string>}|null */
    private ?array $index = null;

    /** @param string $root Wurzelverzeichnis der Installation (dort liegen docs/ und VERSION) */
    public function __construct(private string $root) {}

    /** @return array<string,string> Seiten: Schlüssel → Datei */
    public function pages(): array
    {
        return $this->index()['pages'];
    }

    /** @return array<string,string> Bilder: Schlüssel → Datei */
    public function assets(): array
    {
        return $this->index()['assets'];
    }

    /** @return list<string> ausgelieferte Dateien des Wurzelverzeichnisses (ohne Präfix) */
    public function rootFiles(): array
    {
        $out = [];
        foreach (array_keys($this->pages()) as $key) {
            if (str_starts_with($key, self::ROOT_PREFIX)) $out[] = substr($key, strlen(self::ROOT_PREFIX));
        }
        return $out;
    }

    /** Version dieses Release (Datei VERSION). */
    public function version(): string
    {
        return trim((string)@file_get_contents($this->root . '/VERSION'));
    }

    /**
     * Eine Seite: `{path, lang, mirror, root_files, markdown}` — oder null,
     * wenn der Pfad nicht freigegeben ist (auch eine Weiterleitung).
     *
     * @return array{path:string, lang:string, mirror:?string, root_files:list<string>, markdown:string}|null
     */
    public function page(string $path): ?array
    {
        $file = $this->pages()[$path] ?? null;
        if ($file === null) return null;
        return [
            'path'       => $path,
            'lang'       => self::lang($path),
            'mirror'     => $this->mirror($path),
            // für Links aus der Doku ins Wurzelverzeichnis: in der App oder auf GitHub
            'root_files' => $this->rootFiles(),
            'markdown'   => (string)file_get_contents($file),
        ];
    }

    /**
     * Ein Bild: Datei und Content-Type — oder null, wenn nicht freigegeben.
     *
     * @return array{file:string, type:string}|null
     */
    public function asset(string $path): ?array
    {
        $file = $this->assets()[$path] ?? null;
        if ($file === null) return null;
        return ['file' => $file, 'type' => self::ASSET_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))]];
    }

    /**
     * Ziel einer Weiterleitung (alter Pfad seit v2.14.0): der erste Link der
     * Weiterleitung auf eine freigegebene Seite. Null für alles andere.
     */
    public function movedTo(string $path): ?string
    {
        $file = $this->index()['stubs'][$path] ?? null;
        if ($file === null) return null;
        preg_match_all('/\]\(([^)\s#]+\.md)(?:#[^)\s]*)?\)/', (string)file_get_contents($file), $m);
        $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/') + 1) : '';
        foreach ($m[1] as $rel) {
            $target = self::normalize($dir . rawurldecode($rel));
            // nur als Schlüssel nachgeschlagen, nie als Dateipfad benutzt
            if ($target !== null && isset($this->pages()[$target])) return $target;
        }
        return null;
    }

    /** Sprache einer Seite: `de`, `en` oder `mul`. */
    public static function lang(string $key): string
    {
        if (str_starts_with($key, self::ROOT_PREFIX)) {
            return self::ROOT_FILES[substr($key, strlen(self::ROOT_PREFIX))] ?? 'mul';
        }
        return str_starts_with($key, 'en/') ? 'en' : 'de';
    }

    /** Dieselbe Seite in der anderen Sprache, falls es sie gibt. */
    private function mirror(string $key): ?string
    {
        $pages = $this->pages();
        if (!str_starts_with($key, self::ROOT_PREFIX)) {
            $other = str_starts_with($key, 'en/') ? substr($key, 3) : 'en/' . $key;
            return isset($pages[$other]) ? $other : null;
        }
        // CONTRIBUTING.md ↔ CONTRIBUTING.de.md, CHANGELOG.md ↔ CHANGELOG.en.md
        $name = substr($key, strlen(self::ROOT_PREFIX));
        $candidates = preg_match('/^(.+)\.(de|en)\.md$/', $name, $m) ? [$m[1] . '.md']
            : (str_ends_with($name, '.md') ? [substr($name, 0, -3) . '.de.md', substr($name, 0, -3) . '.en.md'] : []);
        foreach ($candidates as $c) {
            $other = self::ROOT_PREFIX . $c;
            if (isset($pages[$other]) && self::lang($other) !== self::lang($key)) return $other;
        }
        return null;
    }

    /**
     * Die Freigabeliste, einmal je Anfrage aus dem Dateibestand.
     *
     * @return array{pages: array<string,string>, assets: array<string,string>, stubs: array<string,string>}
     */
    private function index(): array
    {
        if ($this->index !== null) return $this->index;
        $pages = $assets = $stubs = [];
        $base = $this->root . '/docs';
        if (is_dir($base) && !is_link($base)) {
            // ohne FOLLOW_SYMLINKS: verlinkte Verzeichnisse bleiben zu
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if ($f->isLink() || !$f->isFile()) continue;
                $key = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                // `root/` gehört den Dateien im Wurzelverzeichnis
                if (str_starts_with($key, self::ROOT_PREFIX)) continue;
                if (str_ends_with($key, '.md')) {
                    if (self::isStub($f->getPathname())) $stubs[$key] = $f->getPathname();
                    else $pages[$key] = $f->getPathname();
                } elseif (str_starts_with($key, self::ASSET_DIR) && isset(self::ASSET_TYPES[strtolower($f->getExtension())])) {
                    $assets[$key] = $f->getPathname();
                }
            }
        }
        foreach (array_keys(self::ROOT_FILES) as $name) {
            $file = $this->root . '/' . $name;
            if (is_file($file) && !is_link($file)) $pages[self::ROOT_PREFIX . $name] = $file;
        }
        ksort($pages);
        ksort($assets);
        return $this->index = ['pages' => $pages, 'assets' => $assets, 'stubs' => $stubs];
    }

    /** Weiterleitung eines alten Pfads (seit v2.14.0, s. DocsIntegrityTest). */
    private static function isStub(string $file): bool
    {
        $head = (string)file_get_contents($file, false, null, 0, 40);
        return str_starts_with($head, '<!-- umgezogen:') || str_starts_with($head, '<!-- moved:');
    }

    /** `a/b/../c.md` → `a/c.md`; null, wenn der Pfad docs/ verlässt. */
    private static function normalize(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                if ($out === []) return null;
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return $out === [] ? null : implode('/', $out);
    }
}
