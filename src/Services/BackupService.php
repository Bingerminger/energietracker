<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Config\Utilities;

/**
 * Backup/Restore — v3.0 format (utility-aware, supports F2 device chains).
 *
 * Backwards-compatible reading:
 *   - v3.0+ → preferred
 *   - v2.x  → recognized, mapped to v3.0 on restore via Migrator path
 *   - v1.x  → recognized as raw v0.9.0 data → not supported here; user should
 *             run the migrator first.
 */
final class BackupService
{
    public const BACKUP_VERSION = '3.0';

    public function __construct(private JsonStore $store) {}

    public function export(): array
    {
        $payload = [
            'backup_version' => self::BACKUP_VERSION,
            'app_version'    => trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: '1.2.0'),
            'exported_at'    => date('c'),
            'meta'           => $this->store->read('meta.json', []),
            'temperatures'   => $this->store->read('temperatures.json', []),
            'settings'       => $this->store->read('settings.json', []),
            'utilities'      => [],
        ];
        foreach (Utilities::keys() as $key) {
            $payload['utilities'][$key] = [
                'meters'    => $this->store->read("$key/meters.json", []),
                'readings'  => $this->store->read("$key/readings.json", []),
                'contracts' => $this->store->read("$key/contracts.json", []),
            ];
        }
        return $payload;
    }

    public function import(array $payload): array
    {
        $ver = (string)($payload['backup_version'] ?? '');
        if ($ver === '') {
            throw new \InvalidArgumentException('Kein backup_version-Feld — kein gültiges Backup');
        }
        if (version_compare($ver, '3.0', '<')) {
            throw new \InvalidArgumentException(
                "Backup-Version $ver wird nicht direkt unterstützt. " .
                'Bitte zuerst über die Migration in v1.0.0 einspielen oder ein neueres Backup verwenden.'
            );
        }
        // N1004 — Schema-Version aus dem Backup darf nicht NEUER sein als
        // die App. Ein 1.2.0-Backup in eine 1.1.0-App einzuspielen würde
        // Schreibvorgänge mit unbekannten Feldern bedeuten und beim
        // nächsten Lesen Inkonsistenzen produzieren.
        $backupSchema = (string)($payload['meta']['schema_version'] ?? '');
        if ($backupSchema !== '' && version_compare($backupSchema, Migrator::SCHEMA_VERSION, '>')) {
            throw new \InvalidArgumentException(
                "Backup-Schema $backupSchema ist neuer als die App-Schema "
                . Migrator::SCHEMA_VERSION
                . '. Bitte erst die App aktualisieren, dann das Backup einspielen.'
            );
        }
        // N1004 — Automatischer Sicherungs-Snapshot vor dem Restore. Falls
        // der User mit dem Restore unzufrieden ist, kann er den Snapshot aus
        // data/backups/ wieder einspielen.
        $autoSnapshot = null;
        try {
            $autoSnapshot = $this->saveSnapshot('pre-restore-');
        } catch (\Throwable $e) {
            // Snapshot ist Defense-in-Depth — wenn er scheitert (z.B.
            // backups/ nicht beschreibbar), darf das den Restore nicht
            // blockieren; der User wird aber informiert.
            $autoSnapshot = ['error' => $e->getMessage()];
        }
        $report = ['utilities' => [], 'auto_snapshot_before_restore' => $autoSnapshot];
        if (isset($payload['temperatures']) && is_array($payload['temperatures'])) {
            $this->store->write('temperatures.json', $payload['temperatures']);
            $report['temperatures'] = count($payload['temperatures']);
        }
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $this->store->write('settings.json', $payload['settings']);
            $report['settings'] = count($payload['settings']);
        }
        if (isset($payload['utilities']) && is_array($payload['utilities'])) {
            foreach ($payload['utilities'] as $key => $bucket) {
                if (!Utilities::exists($key) || !is_array($bucket)) continue;
                if (isset($bucket['meters']))    $this->store->write("$key/meters.json", $bucket['meters']);
                if (isset($bucket['readings']))  $this->store->write("$key/readings.json", $bucket['readings']);
                if (isset($bucket['contracts'])) $this->store->write("$key/contracts.json", $bucket['contracts']);
                $report['utilities'][$key] = [
                    'meters'    => count($bucket['meters']    ?? []),
                    'readings'  => count($bucket['readings']  ?? []),
                    'contracts' => count($bucket['contracts'] ?? []),
                ];
            }
        }
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $this->store->write('meta.json', $payload['meta']);
        }
        return $report;
    }

    public function saveSnapshot(string $prefix = 'backup_'): string
    {
        $dir = $this->store->path('backups');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = $prefix . date('Y-m-d_His') . '.json';
        $path = "$dir/$name";
        file_put_contents($path,
            json_encode($this->export(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        return $name;
    }
}
