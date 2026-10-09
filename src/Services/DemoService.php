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
 * Im Docker-Image sind diese Demo-Datei, die Übersetzungen und seit v3.2.0
 * die Beispielhaushalte (demo-data/personas/) enthalten (per
 * .dockerignore-Ausnahme), nicht das ganze demo-data/-Verzeichnis.
 */
final class DemoService
{
    public function __construct(
        private JsonStore $store,
        private BackupService $backups,
        private I18nService $i18n
    ) {}

    /**
     * Pfad zum mitgelieferten Demo-Backup (relativ zur App-Wurzel).
     * v3.2.0 (F1018) — je Persona ein eigener Beispielhaushalt
     * (demo-data/personas/<id>.json); ohne Persona oder „showcase" der
     * bisherige Haushalt mit allen Verbrauchsarten.
     */
    public function demoBackupPath(?string $persona = null): string
    {
        $root = dirname(__DIR__, 2) . '/demo-data';
        if ($persona === null || $persona === 'showcase') return $root . '/energietracker-demo-backup.json';
        if (!in_array($persona, SettingsService::PERSONAS, true)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.demo.personaUnknown', ['persona' => $persona]));
        }
        return $root . '/personas/' . $persona . '.json';
    }

    public function isAvailable(?string $persona = null): bool
    {
        return is_file($this->demoBackupPath($persona));
    }

    /**
     * „Leer" = noch nichts erfasst: keine Ablesung, Lieferung, kein Zeitraum
     * und kein Vertrag. v3.2.0 — bis v3.1 zählten schon die Standardzähler
     * einer Neuinstallation als Daten; der Einrichtungsassistent hätte dann
     * vor dem Überschreiben gewarnt, obwohl es nichts zu verlieren gab.
     */
    public function isEmpty(): bool
    {
        foreach (Utilities::keys() as $key) {
            foreach (['readings', 'deliveries', 'periods', 'contracts'] as $pot) {
                $rows = $this->store->read("$key/$pot.json", []);
                if (is_array($rows) && $rows !== []) return false;
            }
        }
        return true;
    }

    /** @return array{available:bool,is_empty:bool,personas:list<string>} */
    public function status(): array
    {
        return [
            'available' => $this->isAvailable(),
            'is_empty'  => $this->isEmpty(),
            // v3.2.0 (F1018) — vorhandene Beispielhaushalte je Persona
            'personas'  => array_values(array_filter(SettingsService::PERSONAS,
                fn($p) => $p === 'showcase' || is_file($this->demoBackupPath($p)))),
        ];
    }

    /**
     * v3.2.0 (F1018) — Ein Beispielhaushalt ersetzt den ganzen Haushalt.
     * Töpfe, die ein Demo-Backup nicht kennt (die Haupt-Demo stammt aus der
     * Zeit vor Heizwärme, Zeiträumen, Rechnungen und Mietverhältnis), werden
     * leer eingespielt — sonst blieben nach einem Wechsel des Haushalts Reste
     * des vorigen stehen.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function completePayload(array $payload): array
    {
        // alle Töpfe aus der Liste des Backups (ein neuer Topf ist so von selbst dabei)
        foreach (array_keys(BackupService::TOP_POTS) as $k) {
            if (!array_key_exists($k, $payload) && !in_array($k, ['settings', 'temperatures'], true)) $payload[$k] = [];
        }
        foreach (Utilities::keys() as $u) {
            foreach (BackupService::UTILITY_POTS as $pot) {
                if (!isset($payload['utilities'][$u][$pot])) $payload['utilities'][$u][$pot] = [];
            }
        }
        return $payload;
    }

    /**
     * Importiert die Demo-Daten. Sind bereits Daten vorhanden, ist der Import
     * nur mit $force = true erlaubt — sonst wirft die Methode, und das
     * Frontend zeigt zuvor eine Warnung und ruft mit force erneut auf.
     *
     * v3.0.0 — Die Daten werden vorher bis heute fortgeschrieben
     * (`DemoDataAligner`); `$today` nur für Tests.
     */
    public function import(bool $force = false, ?string $today = null, ?string $persona = null): array
    {
        if (!$this->isAvailable($persona)) {
            throw new NotFoundException($this->i18n->t('errors.demo.backupNotFound'));
        }
        if (!$force && !$this->isEmpty()) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.demo.dataExists')
            );
        }
        $raw = @file_get_contents($this->demoBackupPath($persona));
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            throw new \RuntimeException($this->i18n->t('errors.demo.backupInvalid'));
        }
        $payload = self::completePayload(DemoDataAligner::align($payload, $today ?? date('Y-m-d')));
        // v3.1.0 (I18N-24) — Namen, Notizen und Termine in der Sprache der Anfrage
        $payload = DemoDataTranslator::translate($payload, $this->i18n->locale(),
            DemoDataTranslator::load(dirname($this->demoBackupPath()) . '/translations.json'));
        // v3.2.0 (F1019) — die eigene Nutzungsstufe bleibt; das Backup bringt seine mit
        $before = $this->store->read('settings.json', []);
        $report = $this->backups->import($payload);
        $after = $this->store->read('settings.json', []);
        if (is_array($after)) {
            if (is_array($before) && isset($before['ui_level'])) $after['ui_level'] = $before['ui_level'];
            $after['setup_pending'] = false;
            $after['setup_persona'] = $persona ?? 'showcase';
            $this->store->write('settings.json', $after);
        }
        $report['demo_import'] = true;
        $report['persona'] = $persona ?? 'showcase';
        return $report;
    }
}
