<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Http\ConflictException;
use Energietracker\Support\Dates;

/**
 * Backup/Restore — v3.0 format (utility-aware, supports F2 device chains).
 *
 * Backwards-compatible reading:
 *   - v3.0+ → preferred
 *   - v2.x  → recognized, mapped to v3.0 on restore via Migrator path
 *   - v1.x  → recognized as raw v0.9.0 data → not supported here; user should
 *             run the migrator first.
 *
 * v2.6.0 — Das Format bleibt 3.0; neu sind nur zusätzliche Töpfe und Wege:
 *   - `recommendations_dismissed` wird mitgesichert (fehlte, Lektion 18).
 *   - Der Import prüft ALLE Töpfe, bevor er die erste Datei schreibt, kennt
 *     einen Trockenlauf, packt die Hülle `{success, data}` von
 *     `GET /api/backup/export` aus und rollt bei einem Schreibfehler zurück.
 *   - Ohne Sicherungs-Snapshot läuft er nur mit ausdrücklicher Zustimmung.
 *   - Snapshots werden gestreamt (Datei für Datei statt alles dekodieren und
 *     neu kodieren — ein 15-Jahres-Bestand sprengte sonst 128 MB), atomar
 *     geschrieben, rotiert und lassen sich auflisten, laden, einspielen und
 *     löschen.
 */
final class BackupService
{
    public const BACKUP_VERSION = '3.0';

    /** Töpfe auf oberster Ebene: Schlüssel im Backup → Datei. */
    public const TOP_POTS = [
        'temperatures'              => 'temperatures.json',
        'settings'                  => 'settings.json',
        // v2.1.2 — reminders sind top-level Nutzdaten (vorher still ausgelassen)
        'reminders'                 => 'reminders.json',
        // v2.6.0 — ausgeblendete Empfehlungen (vorher still ausgelassen)
        'recommendations_dismissed' => 'recommendations_dismissed.json',
    ];

    /**
     * Töpfe je Verbrauchsart. v2.1.2 — deliveries (Heizöl/Pellets) und
     * meter_groups (F1006) fehlten bis dahin. Neue Datentöpfe gehören HIER
     * ergänzt; BackupCoverageTest prüft das gegen alle Schreibstellen.
     */
    public const UTILITY_POTS = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups'];

    /** Aufbewahrung: manuelle Snapshots (Präfix `backup_`) … */
    private const KEEP_MANUAL = 10;
    /** … automatische (vor Restore, Migration, Demo, v0.9-Import) so lange … */
    private const KEEP_AUTO_DAYS = 30;
    /** … aber je Anlass mindestens die neuesten. */
    private const KEEP_AUTO_MIN = 3;

    /** Gültiger Snapshot-Name (auch als Schutz vor Pfad-Tricks in der API). */
    public const SNAPSHOT_NAME = '/^[a-z0-9][a-z0-9.-]*_?\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?\.json$/';

    public function __construct(private JsonStore $store, private I18nService $i18n) {}

    public function export(): array
    {
        $payload = [
            'backup_version' => self::BACKUP_VERSION,
            'app_version'    => $this->appVersion(),
            'exported_at'    => date('c'),
            'meta'           => $this->store->read('meta.json', []),
        ];
        foreach (self::TOP_POTS as $key => $file) {
            $payload[$key] = $this->store->read($file, []);
        }
        $payload['utilities'] = [];
        foreach (Utilities::keys() as $key) {
            foreach (self::UTILITY_POTS as $pot) {
                $payload['utilities'][$key][$pot] = $this->store->read("$key/$pot.json", []);
            }
        }
        return $payload;
    }

    /**
     * Spielt ein Backup ein.
     *
     * @param bool $dryRun              nur prüfen und berichten, nichts schreiben
     * @param bool $allowWithoutSnapshot auch einspielen, wenn der
     *                                   Sicherungs-Snapshot scheitert
     * @return array<string,mixed> Bericht
     */
    public function import(array $payload, bool $dryRun = false, bool $allowWithoutSnapshot = false): array
    {
        $payload = self::unwrap($payload);
        $ver = (string)($payload['backup_version'] ?? '');
        if ($ver === '') {
            throw new \InvalidArgumentException($this->i18n->t('errors.backup.noVersionField'));
        }
        if (version_compare($ver, '3.0', '<')) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.backup.versionUnsupported', ['ver' => $ver])
            );
        }
        // N1004 — Schema-Version aus dem Backup darf nicht NEUER sein als
        // die App. Ein 1.2.0-Backup in eine 1.1.0-App einzuspielen würde
        // Schreibvorgänge mit unbekannten Feldern bedeuten und beim
        // nächsten Lesen Inkonsistenzen produzieren.
        $backupSchema = (string)($payload['meta']['schema_version'] ?? '');
        if ($backupSchema !== '' && version_compare($backupSchema, Migrator::SCHEMA_VERSION, '>')) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.backup.schemaNewer', [
                    'schema'    => $backupSchema,
                    'appSchema' => Migrator::SCHEMA_VERSION,
                ])
            );
        }

        // v2.6.0 — erst alles prüfen, dann schreiben. Vorher schrieb der
        // Import Topf für Topf ungeprüft; `{"meters":[1,2]}` legte danach
        // Übersicht, Empfehlungen und Effizienz mit HTTP 500 lahm.
        [$writes, $report] = $this->plan($payload);
        if ($report['problems'] !== []) {
            throw new BackupInvalidException(
                $this->i18n->t('errors.backup.invalid', ['count' => count($report['problems'])]),
                $report['problems']
            );
        }
        if ($dryRun) {
            return $report + ['dry_run' => true];
        }

        // N1004 — Automatischer Sicherungs-Snapshot vor dem Restore. v2.6.0:
        // Scheitert er, bricht der Import ab, außer der Nutzer stimmt
        // ausdrücklich zu (bis v2.5.3 lief er ohne Rückweg weiter).
        $autoSnapshot = null;
        try {
            $autoSnapshot = $this->saveSnapshot('pre-restore-');
        } catch (\Throwable $e) {
            if (!$allowWithoutSnapshot) {
                throw new ConflictException($this->i18n->t('errors.backup.snapshotFailed'), 0, $e);
            }
            $autoSnapshot = ['error' => $e->getMessage()];
        }

        // Schreiben mit Rückweg: Scheitert eine Datei mittendrin (Platte voll),
        // werden die schon geschriebenen auf ihren alten Stand zurückgesetzt.
        $done = [];
        try {
            foreach ($writes as $file => $data) {
                $before = $this->store->exists($file) ? @file_get_contents($this->store->path($file)) : null;
                $this->store->write($file, $data);
                $done[$file] = $before;
            }
        } catch (\Throwable $e) {
            foreach ($done as $file => $before) {
                if ($before === null) { $this->store->delete($file); continue; }
                @file_put_contents($this->store->path($file), $before, LOCK_EX);
            }
            throw $e;
        }

        return $report + ['auto_snapshot_before_restore' => $autoSnapshot];
    }

    /**
     * Packt die Antwort-Hülle von `GET /api/backup/export` aus. Eine per
     * `curl …/backup/export > backup.json` gesicherte Datei ließ sich bisher
     * nicht zurückspielen („Kein backup_version-Feld").
     */
    public static function unwrap(array $payload): array
    {
        if (!isset($payload['backup_version']) && isset($payload['data']['backup_version']) && is_array($payload['data'])) {
            return $payload['data'];
        }
        return $payload;
    }

    /**
     * Prüft den Inhalt und stellt die Schreibvorgänge zusammen.
     *
     * Teil-Restore (dokumentiert): Ein Topf, der im Backup fehlt, bleibt
     * unverändert. Der Bericht nennt ihn unter `untouched`.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function plan(array $payload): array
    {
        $problems = [];
        $writes = [];
        $report = ['utilities' => [], 'untouched' => [], 'problems' => []];
        $problem = function (string $pot, ?int $index, string $what) use (&$problems): void {
            if (count($problems) < 50) $problems[] = ['pot' => $pot, 'index' => $index, 'problem' => $what];
        };

        foreach (self::TOP_POTS as $key => $file) {
            if (!array_key_exists($key, $payload)) { $report['untouched'][] = $key; continue; }
            if (!is_array($payload[$key])) { $problem($key, null, 'not_an_object'); continue; }
            if ($key === 'temperatures') {
                foreach ($payload[$key] as $date => $v) {
                    if (!Dates::isIsoDate((string)$date) || !is_array($v)) { $problem($key, null, "entry:$date"); break; }
                }
            }
            if ($key === 'reminders') $this->checkList($payload[$key], $key, ['id'], [], $problem);
            $writes[$file] = $payload[$key];
            $report[$key] = count($payload[$key]);
        }

        $utilities = $payload['utilities'] ?? null;
        if ($utilities !== null && !is_array($utilities)) {
            $problem('utilities', null, 'not_an_object');
            $utilities = [];
        }
        foreach ((array)$utilities as $key => $bucket) {
            if (!Utilities::exists((string)$key)) { $problem((string)$key, null, 'unknown_utility'); continue; }
            if (!is_array($bucket)) { $problem((string)$key, null, 'not_an_object'); continue; }
            $counts = [];
            foreach (self::UTILITY_POTS as $pot) {
                if (!array_key_exists($pot, $bucket)) { $report['untouched'][] = "$key/$pot"; continue; }
                $list = $bucket[$pot];
                [$required, $dates] = match ($pot) {
                    'meters'       => [['id', 'devices'], []],
                    'readings'     => [['id', 'meter_id', 'date', 'counter'], ['date']],
                    'contracts'    => [['id', 'meter_id', 'start'], ['start']],
                    'deliveries'   => [['id', 'date'], ['date']],
                    'meter_groups' => [['id'], []],
                };
                if ($this->checkList($list, "$key/$pot", $required, $dates, $problem)) {
                    $writes["$key/$pot.json"] = $list;
                }
                $counts[$pot] = is_array($list) ? count($list) : 0;
            }
            $report['utilities'][$key] = $counts;
        }

        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $writes['meta.json'] = $payload['meta'];
        }
        $report['problems'] = $problems;
        return [$writes, $report];
    }

    /**
     * Liste von Objekten mit Pflichtfeldern und kalendergültigen Daten.
     *
     * @param callable(string, ?int, string): void $problem
     */
    private function checkList(mixed $list, string $pot, array $required, array $dates, callable $problem): bool
    {
        if (!is_array($list) || !array_is_list($list)) { $problem($pot, null, 'not_a_list'); return false; }
        $ok = true;
        foreach ($list as $i => $row) {
            if (!is_array($row)) { $problem($pot, $i, 'not_an_object'); $ok = false; continue; }
            foreach ($required as $f) {
                if (!array_key_exists($f, $row)) { $problem($pot, $i, "missing:$f"); $ok = false; }
            }
            foreach ($dates as $f) {
                if (isset($row[$f]) && !Dates::isIsoDate($row[$f])) { $problem($pot, $i, "date:$f"); $ok = false; }
            }
            if (isset($row['counter']) && !is_numeric($row['counter'])) { $problem($pot, $i, 'counter'); $ok = false; }
            if (isset($row['devices']) && (!is_array($row['devices']) || !array_is_list($row['devices']))) {
                $problem($pot, $i, 'devices'); $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Legt einen Snapshot unter data/backups/ an und gibt seinen Namen zurück.
     *
     * v2.6.0 — gestreamt: Jede Datei wird roh übernommen (nach einer
     * Gültigkeitsprüfung), statt den ganzen Bestand zu dekodieren und neu zu
     * kodieren. Atomar über eine Temp-Datei, eindeutiger Name, danach Rotation.
     */
    public function saveSnapshot(string $prefix = 'backup_'): string
    {
        $dir = $this->store->path('backups');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Snapshot-Verzeichnis lässt sich nicht anlegen');
        }
        $base = $prefix . date('Y-m-d_His');
        $name = $base . '.json';
        for ($n = 2; is_file("$dir/$name"); $n++) $name = "$base-$n.json";

        $tmp = "$dir/.$name.tmp";
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) throw new \RuntimeException('Snapshot lässt sich nicht schreiben');
        try {
            $w = function (string $s) use ($fp): void {
                if (fwrite($fp, $s) !== strlen($s)) throw new \RuntimeException('Snapshot lässt sich nicht schreiben');
            };
            $enc = fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $w('{"backup_version":' . $enc(self::BACKUP_VERSION)
                . ',"app_version":' . $enc($this->appVersion())
                . ',"exported_at":' . $enc(date('c'))
                . ',"meta":' . $this->rawPot('meta.json', '{}'));
            foreach (self::TOP_POTS as $key => $file) {
                $w(',' . $enc($key) . ':' . $this->rawPot($file, '[]'));
            }
            $w(',"utilities":{');
            $firstU = true;
            foreach (Utilities::keys() as $key) {
                $w(($firstU ? '' : ',') . $enc($key) . ':{');
                $firstU = false;
                foreach (self::UTILITY_POTS as $i => $pot) {
                    $w(($i ? ',' : '') . $enc($pot) . ':' . $this->rawPot("$key/$pot.json", '[]'));
                }
                $w('}');
            }
            $w('}}');
            fflush($fp);
            if (function_exists('fsync')) @fsync($fp);
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($tmp);
            throw $e;
        }
        fclose($fp);
        if (!@rename($tmp, "$dir/$name")) {
            @unlink($tmp);
            throw new \RuntimeException('Snapshot lässt sich nicht ablegen');
        }
        $this->rotate();
        return $name;
    }

    /**
     * Rohinhalt eines Topfs für den Snapshot. Nur gültiges JSON wird
     * übernommen — eine beschädigte Datei bleibt draußen (sie liegt ohnehin
     * als Quarantäne-Kopie daneben, s. JsonStore).
     */
    private function rawPot(string $file, string $empty): string
    {
        if (!$this->store->exists($file)) return $empty;
        $raw = (string)@file_get_contents($this->store->path($file));
        if (trim($raw) === '') return $empty;
        json_decode($raw);
        return json_last_error() === JSON_ERROR_NONE ? $raw : $empty;
    }

    /**
     * v2.6.0 — Snapshots unter data/backups/, neueste zuerst.
     *
     * @return list<array{name:string, size:int, created_at:string, reason:string}>
     */
    public function listSnapshots(): array
    {
        $dir = $this->store->path('backups');
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match(self::SNAPSHOT_NAME, $name)) continue;
            $out[] = [
                'name'       => $name,
                'size'       => (int)@filesize($path),
                'created_at' => date('c', (int)@filemtime($path)),
                'reason'     => self::reasonOf($name),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['created_at'] . $b['name'], $a['created_at'] . $a['name']));
        return $out;
    }

    /** Anlass eines Snapshots aus seinem Namen. */
    public static function reasonOf(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'pre-restore-')   => 'restore',
            str_starts_with($name, 'pre-migration-') => 'migration',
            str_starts_with($name, 'pre-demo-')      => 'demo',
            str_starts_with($name, 'pre-v09-')       => 'v09',
            default                                  => 'manual',
        };
    }

    /** Absoluter Pfad eines Snapshots — nur für gültige, vorhandene Namen. */
    public function snapshotPath(string $name): string
    {
        $path = $this->store->path('backups') . '/' . $name;
        if (!preg_match(self::SNAPSHOT_NAME, $name) || !is_file($path)) {
            throw new NotFoundException($this->i18n->t('errors.backup.snapshotNotFound', ['name' => $name]));
        }
        return $path;
    }

    /** Spielt einen Snapshot ein — über den normalen, prüfenden Import-Weg. */
    public function restoreSnapshot(string $name, bool $allowWithoutSnapshot = false): array
    {
        $raw = (string)file_get_contents($this->snapshotPath($name));
        $payload = json_decode($raw, true);
        unset($raw);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.backup.snapshotUnreadable', ['name' => $name]));
        }
        return $this->import($payload, false, $allowWithoutSnapshot);
    }

    public function deleteSnapshot(string $name): void
    {
        @unlink($this->snapshotPath($name));
    }

    /**
     * Aufbewahrung: die neuesten KEEP_MANUAL manuellen Snapshots; automatische
     * KEEP_AUTO_DAYS Tage, je Anlass aber mindestens die neuesten KEEP_AUTO_MIN.
     * Bis v2.5.3 wuchs data/backups/ unbegrenzt.
     */
    private function rotate(): void
    {
        $byReason = [];
        foreach ($this->listSnapshots() as $s) $byReason[$s['reason']][] = $s;
        $cutoff = time() - self::KEEP_AUTO_DAYS * 86400;
        foreach ($byReason as $reason => $list) {
            foreach ($list as $i => $s) {
                $drop = $reason === 'manual'
                    ? $i >= self::KEEP_MANUAL
                    : ($i >= self::KEEP_AUTO_MIN && strtotime($s['created_at']) < $cutoff);
                if ($drop) @unlink($this->store->path('backups') . '/' . $s['name']);
            }
        }
    }

    private function appVersion(): string
    {
        return trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: '1.2.0');
    }
}
