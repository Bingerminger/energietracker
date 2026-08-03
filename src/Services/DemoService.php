<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;

/**
 * F1007 (v1.7.4) — Demo-Daten-Komfort-Import.
 *
 * Spielt das mitgelieferte Demo-JSON-Backup
 * (demo-data/energietracker-demo-backup.json) über den bestehenden
 * BackupService-Restore-Pfad ein — inklusive Schema-Guard und automatischem
 * Pre-Restore-Snapshot aus N1004. Damit ist der Import auch bei bereits
 * vorhandenen Daten verlustfrei rückholbar.
 *
 * Im Docker-Image ist nur diese eine Demo-Datei enthalten (per
 * .dockerignore-Ausnahme), nicht das ganze demo-data/-Verzeichnis.
 */
final class DemoService
{
    public function __construct(
        private JsonStore $store,
        private BackupService $backups,
        private I18nService $i18n
    ) {}

    /** Pfad zum mitgelieferten Demo-Backup (relativ zur App-Wurzel). */
    public function demoBackupPath(): string
    {
        return dirname(__DIR__, 2) . '/demo-data/energietracker-demo-backup.json';
    }

    public function isAvailable(): bool
    {
        return is_file($this->demoBackupPath());
    }

    /** „Leer" = kein einziger Zähler über alle Verbrauchsarten hinweg. */
    public function isEmpty(): bool
    {
        foreach (Utilities::keys() as $key) {
            $meters = $this->store->read("$key/meters.json", []);
            if (is_array($meters) && $meters !== []) {
                return false;
            }
        }
        return true;
    }

    /** @return array{available:bool,is_empty:bool} */
    public function status(): array
    {
        return [
            'available' => $this->isAvailable(),
            'is_empty'  => $this->isEmpty(),
        ];
    }

    /**
     * Importiert die Demo-Daten. Sind bereits Daten vorhanden, ist der Import
     * nur mit $force = true erlaubt — sonst wirft die Methode, und das
     * Frontend zeigt zuvor eine Warnung und ruft mit force erneut auf.
     */
    public function import(bool $force = false): array
    {
        if (!$this->isAvailable()) {
            throw new NotFoundException($this->i18n->t('errors.demo.backupNotFound'));
        }
        if (!$force && !$this->isEmpty()) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.demo.dataExists')
            );
        }
        $raw = @file_get_contents($this->demoBackupPath());
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            throw new \RuntimeException($this->i18n->t('errors.demo.backupInvalid'));
        }
        $report = $this->backups->import($payload);
        $report['demo_import'] = true;
        return $report;
    }
}
