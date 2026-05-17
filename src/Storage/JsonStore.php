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

    /** @return mixed default if file missing */
    public function read(string $relative, mixed $default = []): mixed
    {
        $path = $this->path($relative);
        if (!is_file($path)) return $default;

        $fp = @fopen($path, 'rb');
        if (!$fp) return $default;
        try {
            if (!flock($fp, LOCK_SH)) return $default;
            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if (!is_string($contents) || $contents === '') return $default;
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public function write(string $relative, mixed $data): void
    {
        $path = $this->path($relative);
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            throw new \RuntimeException('JSON-Kodierung fehlgeschlagen für ' . $relative);
        }

        // Write to tmp file in same dir, then atomic rename
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $bytes = file_put_contents($tmp, $json, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException('Konnte temporäre Datei nicht schreiben: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
        }
        @chmod($path, 0644);
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function delete(string $relative): bool
    {
        $path = $this->path($relative);
        return is_file($path) ? @unlink($path) : true;
    }

    /** @return string[] basenames matching glob */
    public function glob(string $pattern): array
    {
        $matches = glob($this->rootDir . '/' . $pattern) ?: [];
        return array_map('basename', $matches);
    }
}
