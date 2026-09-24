<?php
declare(strict_types=1);

namespace Energietracker\Storage;

/**
 * Atomic JSON file storage with shared/exclusive locking.
 *
 * All file I/O in the application goes through this class.
 * Reads use shared locks; writes use exclusive locks + tmp-file-rename.
 */
final class JsonStore
{
    public function __construct(private string $rootDir)
    {
        if (!is_dir($this->rootDir)) {
            @mkdir($this->rootDir, 0755, true);
        }
        // macOS: /tmp ist ein Symlink auf /private/tmp; path() prüft den
        // realpath()-Prefix und würde sonst jeden Lese-/Schreibzugriff
        // mit „Ungültiger Speicherpfad" ablehnen. rootDir einmal beim
        // Konstruktor auflösen, dann sind alle Path-Prüfungen konsistent.
        $resolved = realpath($this->rootDir);
        if ($resolved !== false) {
            $this->rootDir = $resolved;
        }
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function path(string $relative): string
    {
        $path = $this->rootDir . '/' . ltrim($relative, '/');
        // Defense-in-depth: resolve and assert that the resulting path stays
        // inside rootDir. This catches any '../' traversal attempts that slip
        // through the Service-layer whitelist validation.
        $resolved = realpath($path);
        if ($resolved !== false && !str_starts_with($resolved . '/', $this->rootDir . '/')) {
            throw new \InvalidArgumentException('Ungültiger Speicherpfad: ' . $relative);
        }
        return $path;
    }

    /**
     * @return mixed default if file missing (or empty)
     * @throws StorageCorruptedException if the file exists but cannot be read or parsed
     *
     * v2.5.3 — „fehlt" und „kaputt" sind zwei verschiedene Fälle. Bis v2.5.2
     * gab eine unlesbare oder abgeschnittene Datei still den Default zurück,
     * und der nächste Schreibzugriff machte den Verlust endgültig. Jetzt:
     *   - Datei fehlt oder ist leer → Default (es gibt nichts zu verlieren)
     *   - Datei unlesbar oder kein gültiges JSON → Kopie `<datei>.corrupt-<hash>`
     *     daneben, dann StorageCorruptedException (HTTP 503)
     */
    public function read(string $relative, mixed $default = []): mixed
    {
        $path = $this->path($relative);
        if (!is_file($path)) return $default;

        $fp = @fopen($path, 'rb');
        if (!$fp) {
            throw new StorageCorruptedException('Datei ist nicht lesbar: ' . $relative
                . ' — bitte Dateirechte des Datenverzeichnisses prüfen.');
        }
        try {
            if (!flock($fp, LOCK_SH)) {
                throw new StorageCorruptedException('Datei lässt sich nicht sperren: ' . $relative);
            }
            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if (!is_string($contents)) {
            throw new StorageCorruptedException('Datei ist nicht lesbar: ' . $relative);
        }
        if (trim($contents) === '') return $default;

        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $copy = $this->quarantine($relative, $contents);
            throw new StorageCorruptedException(
                'Datei ist beschädigt (kein gültiges JSON): ' . $relative
                . ($copy !== null ? ' — eine Kopie liegt unter ' . $copy : '')
                . '. Bitte aus einem Backup wiederherstellen oder die Datei reparieren;'
                . ' bis dahin wird sie nicht überschrieben.'
            );
        }
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Kopie einer beschädigten Datei neben dem Original. Der Name hängt am
     * Inhalt, damit wiederholte Lesezugriffe nicht jedes Mal eine neue Kopie
     * anlegen. Liefert den relativen Pfad der Kopie oder null.
     */
    private function quarantine(string $relative, string $contents): ?string
    {
        $copyRel = $relative . '.corrupt-' . substr(hash('sha256', $contents), 0, 8);
        $copyAbs = $this->rootDir . '/' . ltrim($copyRel, '/');
        if (is_file($copyAbs)) return $copyRel;
        return @file_put_contents($copyAbs, $contents, LOCK_EX) !== false ? $copyRel : null;
    }

    public function write(string $relative, mixed $data): void
    {
        $path = $this->path($relative);
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // v2.6.0 — JSON_INVALID_UTF8_SUBSTITUTE als letzte Verteidigung: Ein
        // CSV in Windows-1252 brach bisher mitten im Import mit HTTP 500 ab,
        // nachdem die ersten Zeilen schon geschrieben waren.
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new \RuntimeException('JSON-Kodierung fehlgeschlagen für ' . $relative);
        }

        // Write to tmp file in same dir, then atomic rename.
        // v2.6.0 — fflush + fsync vor dem rename: Ohne sie kann die umbenannte
        // Datei nach einem Stromausfall leer sein (je nach Dateisystem).
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Konnte temporäre Datei nicht schreiben: ' . $relative);
        }
        $ok = false;
        try {
            $ok = fwrite($fp, $json) === strlen($json) && fflush($fp);
            if ($ok && function_exists('fsync')) @fsync($fp);
        } finally {
            fclose($fp);
        }
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('Konnte temporäre Datei nicht schreiben: ' . $relative);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Konnte Datei nicht ersetzen: ' . $relative);
        }
        @chmod($path, 0644);
        $this->generation++;
    }

    /**
     * v2.6.0 — Zähler der Schreibvorgänge dieser Instanz. Rechenergebnisse,
     * die innerhalb einer Anfrage zwischengespeichert werden
     * (ConsumptionService::forMeter), hängen ihn an ihren Schlüssel; jede
     * Änderung macht sie damit ungültig.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    private int $generation = 0;

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function delete(string $relative): bool
    {
        $path = $this->path($relative);
        $this->generation++;
        return is_file($path) ? @unlink($path) : true;
    }

    /** @return string[] basenames matching glob */
    public function glob(string $pattern): array
    {
        $matches = glob($this->rootDir . '/' . $pattern) ?: [];
        return array_map('basename', $matches);
    }
}
