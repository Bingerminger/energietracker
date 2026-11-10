<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Eine JSON-Datei unter `data/mcp/` mit eigener Sperre
 * (Entscheidung 7): Vorschläge, später Zähler der Ratenbegrenzung.
 *
 * Bewusst nicht über JsonStore: Dessen Schreiben holt die globale Schreibsperre,
 * und jeder Lesezugriff einer KI stünde dann hinter allen Schreibern. Hier gilt
 * `flock` auf `.<datei>.lock` nur für diese Datei; geschrieben wird atomar
 * (Temp-Datei, fsync, rename) wie in JsonStore. Die Inhalte sind kurzlebig und
 * nicht im Backup; eine unlesbare Datei gilt als leer (es geht nichts
 * Bleibendes verloren).
 */
final class StateFile
{
    private string $path;
    private string $lockPath;

    /**
     * @param string $dataDir Datenverzeichnis der App; Datei unter `mcp/`
     * @param bool $sync v3.12.0 (Paket protokoll) — false: ohne fsync schreiben (Zähler der
     *                   Ratenbegrenzung bei jeder Anfrage; nach einem Absturz beginnen sie neu)
     */
    public function __construct(string $dataDir, string $name, private bool $sync = true)
    {
        if (!preg_match('/^[a-z0-9_-]{1,40}\.json$/', $name)) throw new \LogicException("mcp: invalid state file '$name'");
        $dir = rtrim($dataDir, '/') . '/mcp';
        $this->path = "$dir/$name";
        $this->lockPath = "$dir/." . substr($name, 0, -5) . '.lock';
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed> Stand ohne Sperre (nur lesen) */
    public function read(): array
    {
        return self::decode(@file_get_contents($this->path));
    }

    /**
     * Lesen, ändern, schreiben unter der Sperre dieser Datei. `$fn` bekommt den
     * Stand und gibt `[neuer Stand, Ergebnis]` zurück; geschrieben wird nur bei
     * einer Änderung. Wirft `$fn`, bleibt die Datei, wie sie war.
     *
     * @template T
     * @param callable(array<string,mixed>): array{0: array<string,mixed>, 1: T} $fn
     * @return T
     */
    public function update(callable $fn): mixed
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Konnte Verzeichnis nicht anlegen: mcp/');
        }
        $lock = @fopen($this->lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('Konnte Sperre nicht anlegen: mcp/');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Konnte Sperre nicht setzen: mcp/');
            $before = self::decode(@file_get_contents($this->path));
            [$after, $result] = $fn($before);
            if ($after !== $before) $this->write($after);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new \RuntimeException('JSON-Kodierung fehlgeschlagen: mcp/');
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) throw new \RuntimeException('Konnte temporäre Datei nicht schreiben: mcp/');
        $ok = false;
        try {
            $ok = fwrite($fp, $json) === strlen($json) && fflush($fp);
            if ($ok && $this->sync && function_exists('fsync')) @fsync($fp);
        } finally {
            fclose($fp);
        }
        if (!$ok || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Konnte Datei nicht ersetzen: mcp/');
        }
        @chmod($this->path, 0644);
    }

    /** @return array<string,mixed> */
    private static function decode(string|false $raw): array
    {
        if ($raw === false || trim($raw) === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
