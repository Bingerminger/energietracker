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
 *   - v1.x  → recognized as raw v0.9.0 data → not supported here. Since
 *             v3.4.0 no version reads them (install v3.3.0, migrate there,
 *             then update).
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
 *
 * v3.1.0 (Paket H2, B1) — Belege: Der Index `attachments` ist ein Topf wie
 * jeder andere; die Dateien stehen base64-kodiert unter `attachment_files`
 * ({id: base64}). Das Format bleibt 3.0 — ältere Versionen ignorieren den
 * unbekannten Schlüssel. Der Import prüft jede Datei gegen `sha256` und
 * Inhaltstyp aus dem Index, bevor er schreibt. Export und Snapshot werden
 * gestreamt, Datei für Datei.
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
        // v3.1.0 (H2) — Index der Belege; die Dateien selbst unter attachment_files
        'attachments'               => 'attachments.json',
        // v3.1.0 (H3, F1008) — Mietverhältnis und Nebenkostenabrechnungen
        'tenancies'                 => 'tenancies.json',
        'tenancy_statements'        => 'tenancy_statements.json',
        // v3.1.0 (H6, B7) — Großhandelspreise je Monat (SMARD bzw. Datei)
        'market_prices'             => 'market_prices.json',
        // v3.2.0 (F1022) — Ladevorgänge aus evcc
        'ev_sessions'               => 'ev_sessions.json',
        // v3.3.0 (F1021) — Index der Viertelstundenreihen; die Reihen unter interval_files
        'intervals'                 => 'intervals.json',
        // v3.4.0 (F1024) — Verlauf erledigter Hinweise (Namen als Momentaufnahme)
        'activity'                  => 'activity.json',
        // v3.6.0 — Personenverzeichnis: Nummer, Name, Rolle, Löschvermerk (ohne Passwörter, s. PeopleService)
        'people'                    => PeopleService::FILE,
    ];

    /**
     * Töpfe je Verbrauchsart. v2.1.2 — deliveries (Heizöl/Pellets) und
     * meter_groups (F1006) fehlten bis dahin. Neue Datentöpfe gehören HIER
     * ergänzt; BackupCoverageTest prüft das gegen alle Schreibstellen.
     */
    public const UTILITY_POTS = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups',
        'periods',    // v3.1.0 (H3, B2) — Verbrauch je Zeitraum
        'bills'];     // v3.1.0 (H5, B3) — Versorgerrechnungen

    /** Aufbewahrung: manuelle Snapshots (Präfix `backup_`) … */
    private const KEEP_MANUAL = 10;
    /** … automatische (vor Restore, Migration, Demo, v0.9-Import) so lange … */
    private const KEEP_AUTO_DAYS = 30;
    /** … aber je Anlass mindestens die neuesten. */
    private const KEEP_AUTO_MIN = 3;

    /** Gültiger Snapshot-Name (auch als Schutz vor Pfad-Tricks in der API). */
    public const SNAPSHOT_NAME = '/^[a-z0-9][a-z0-9.-]*_?\d{4}-\d{2}-\d{2}_\d{6}(-\d+)?\.json$/';

    public function __construct(private JsonStore $store, private I18nService $i18n) {}

    /** @var (callable(): mixed)|null v3.6.0 — vor jeder Sicherung: Personenverzeichnis mit den Zugängen abgleichen */
    private $beforeExport = null;

    /** v3.6.0 — App: PeopleService::all() (Zugänge ohne Nummer bekommen sie, bevor gesichert wird). */
    public function beforeExport(callable $fn): void
    {
        $this->beforeExport = $fn;
    }

    private function prepareExport(): void
    {
        if ($this->beforeExport === null) return;
        try { ($this->beforeExport)(); } catch (\Throwable) { /* Sicherung geht vor */ }
    }

    public function export(bool $withFiles = true): array
    {
        $this->prepareExport();
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
        if ($withFiles) {
            $payload['attachment_files'] = [];
            foreach ($this->attachmentFiles() as $id => $path) {
                $payload['attachment_files'][$id] = base64_encode((string)file_get_contents($path));
            }
        }
        // v3.3.0 (F1021) — Viertelstundenreihen sind Daten, keine Belege: immer dabei
        $payload['interval_files'] = [];
        foreach ($this->intervalIds() as $id) $payload['interval_files'][$id] = $this->store->read(IntervalService::DIR . "/$id.json", []);
        return $payload;
    }

    /** Reihen laut Index mit vorhandener Datei. @return list<string> */
    private function intervalIds(): array
    {
        $idx = $this->store->exists(IntervalService::INDEX) ? $this->store->read(IntervalService::INDEX, []) : [];
        $ids = [];
        foreach ((array)($idx['series'] ?? []) as $s) {
            $id = (string)($s['id'] ?? '');
            if (preg_match(IntervalService::ID, $id) && $this->store->exists(IntervalService::DIR . "/$id.json")) $ids[] = $id;
        }
        return $ids;
    }

    /**
     * v3.1.0 — Export gestreamt (für `GET /api/backup/export`): Mit Belegen
     * wäre das Backup sonst zweimal ganz im Speicher (base64 und JSON). Eine
     * beschädigte Datei bricht wie bisher mit 503 ab — geprüft wird vor dem
     * ersten Byte.
     *
     * @param callable(string): void $w
     */
    public function streamExport(callable $w, bool $withFiles = true): void
    {
        foreach ($this->potFiles() as $file) $this->store->read($file, []);   // wirft bei beschädigter Datei
        $this->writeBackup($w, $withFiles);
    }

    /** Alle Topf-Dateien (ohne meta.json). @return list<string> */
    private function potFiles(): array
    {
        $files = array_values(self::TOP_POTS);
        foreach (Utilities::keys() as $key) {
            foreach (self::UTILITY_POTS as $pot) $files[] = "$key/$pot.json";
        }
        return $files;
    }

    /**
     * Belege mit vorhandener Datei, laut Index.
     *
     * @return array<string,string> id → absoluter Pfad
     */
    private function attachmentFiles(): array
    {
        $out = [];
        $idx = $this->store->exists(AttachmentService::INDEX) ? $this->store->read(AttachmentService::INDEX, []) : [];
        foreach (is_array($idx) ? $idx : [] as $a) {
            $id = (string)($a['id'] ?? '');
            if (!preg_match(AttachmentService::ID, $id)) continue;
            $path = $this->store->path('attachments/' . $id . '.' . AttachmentService::extension((string)($a['mime'] ?? '')));
            if (is_file($path)) $out[$id] = $path;
        }
        return $out;
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
        [$writes, $report, $binaries] = $this->plan($payload);
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
        $newFiles = [];
        try {
            // v3.1.0 — erst die Belege, dann der Index: Ein Index-Eintrag zeigt
            // nie auf eine Datei, die noch fehlt. Gleiche ID = gleicher Inhalt
            // (sha256 geprüft); vorhandene Dateien bleiben unberührt.
            foreach ($binaries as $rel => $b64) {
                if (is_file($this->store->path($rel))) continue;
                $this->store->writeBinary($rel, (string)base64_decode($b64, true));
                $newFiles[] = $rel;
            }
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
            foreach ($newFiles as $rel) @unlink($this->store->path($rel));
            throw $e;
        }

        // v3.3.0 (F1021) — kam ein neuer Index, fallen Reihen ohne Eintrag weg
        // (sonst lägen nach einem Haushaltswechsel Reihen des vorigen herum)
        if (array_key_exists(IntervalService::INDEX, $writes)) {
            $keep = [];
            foreach ((array)($writes[IntervalService::INDEX]['series'] ?? []) as $s) $keep[(string)($s['id'] ?? '')] = true;
            foreach ($this->store->glob(IntervalService::DIR . '/*.json') as $base) {
                if (!isset($keep[substr($base, 0, -5)])) $this->store->delete(IntervalService::DIR . '/' . $base);
            }
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
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,string>}
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
            if ($key === 'ev_sessions') $this->checkList($payload[$key], $key, ['id', 'meter_id', 'date', 'charged_kwh'], ['date'], $problem);
            if ($key === 'tenancies') $this->checkList($payload[$key], $key, ['id', 'start'], ['start'], $problem);
            // v3.4.0 (F1024) — Verlauf: Kennung, Aktion, Gegenstand, Tag
            if ($key === 'activity') {
                $this->checkList($payload[$key], $key, ['id', 'action', 'subject', 'done_on'], ['done_on'], $problem);
                foreach (array_is_list($payload[$key]) ? $payload[$key] : [] as $i => $e) {
                    if (!is_array($e)) continue;
                    if (array_key_exists('action', $e) && !in_array($e['action'], ActivityService::ACTIONS, true)) $problem($key, $i, 'action');
                    if (array_key_exists('subject', $e) && (!is_array($e['subject']) || !is_string($e['subject']['kind'] ?? null)
                        || !is_string($e['subject']['occurrence'] ?? null))) $problem($key, $i, 'subject');
                }
            }
            if ($key === 'tenancy_statements') {
                $this->checkList($payload[$key], $key, ['id', 'tenancy_id', 'period_from', 'period_to'], ['period_from', 'period_to'], $problem);
            }
            // v3.6.0 — Personenverzeichnis: {last_no, people: [{id, name, …}]} oder leer ([] aus completePayload)
            if ($key === 'people') {
                $list = $payload[$key] === [] ? [] : ($payload[$key]['people'] ?? null);
                if (!is_array($list) || !array_is_list($list)) { $problem($key, null, 'not_a_list'); continue; }
                foreach ($list as $i => $p) {
                    if (!is_array($p) || !is_string($p['id'] ?? null) || !preg_match(PeopleService::ID, $p['id'])) $problem($key, $i, 'id');
                    elseif (!is_string($p['name'] ?? null)) $problem($key, $i, 'missing:name');
                }
                // der Zähler geht durch ein älteres Backup nicht zurück
                try { $current = $this->store->read($file, []); } catch (\Throwable) { $current = []; }
                $writes[$file] = PeopleService::mergeForImport($payload[$key], $current);
                $report[$key] = count($list);
                continue;
            }
            if ($key === 'attachments' && $this->checkList($payload[$key], $key, ['id', 'kind', 'mime', 'size', 'sha256'], [], $problem)) {
                foreach ($payload[$key] as $i => $a) {
                    if (!preg_match(AttachmentService::ID, (string)$a['id'])) $problem($key, $i, 'id');
                    elseif (AttachmentService::extension((string)$a['mime']) === 'bin') $problem($key, $i, 'mime');
                }
            }
            $writes[$file] = $payload[$key];
            // der Index der Viertelstundenreihen zählt seine Reihen, nicht seine zwei Schlüssel
            $report[$key] = $key === 'intervals' ? count((array)($payload[$key]['series'] ?? [])) : count($payload[$key]);
        }

        // v3.1.0 — Belege: jede Datei gegen sha256 und Inhaltstyp des Index
        $binaries = [];
        if (isset($payload['attachment_files'])) {
            $files = $payload['attachment_files'];
            $index = [];
            foreach (is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [] as $a) {
                if (is_array($a) && isset($a['id'])) $index[(string)$a['id']] = $a;
            }
            if (!is_array($files)) {
                $problem('attachment_files', null, 'not_an_object');
                $files = [];
            }
            foreach ($files as $id => $b64) {
                $id = (string)$id;
                $a = $index[$id] ?? null;
                if ($a === null || !preg_match(AttachmentService::ID, $id)) { $problem('attachment_files', null, "unknown:$id"); continue; }
                $bytes = is_string($b64) ? base64_decode($b64, true) : false;
                if ($bytes === false || !hash_equals((string)$a['sha256'], hash('sha256', $bytes))) { $problem('attachment_files', null, "sha256:$id"); continue; }
                if (AttachmentService::sniff($bytes) !== $a['mime']) { $problem('attachment_files', null, "mime:$id"); continue; }
                $binaries['attachments/' . $id . '.' . AttachmentService::extension((string)$a['mime'])] = $b64;
            }
            $report['attachment_files'] = count($binaries);
        }

        // v3.3.0 (F1021) — Viertelstundenreihen: nur solche aus dem Index des
        // Backups, mit gültiger ID und einer Werteliste
        if (isset($payload['interval_files'])) {
            $known = [];
            foreach ((array)($payload['intervals']['series'] ?? []) as $s) if (is_array($s)) $known[(string)($s['id'] ?? '')] = true;
            $files = is_array($payload['interval_files']) ? $payload['interval_files'] : null;
            if ($files === null) $problem('interval_files', null, 'not_an_object');
            $n = 0;
            foreach ($files ?? [] as $id => $content) {
                $id = (string)$id;
                if (!preg_match(IntervalService::ID, $id) || !isset($known[$id]) || !is_array($content['values'] ?? null)) {
                    $problem('interval_files', null, "invalid:$id");
                    continue;
                }
                $writes[IntervalService::DIR . "/$id.json"] = $content;
                $n++;
            }
            $report['interval_files'] = $n;
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
                    'periods'      => [['id', 'meter_id', 'from', 'to'], ['from', 'to']],
                    'bills'        => [['id', 'meter_id', 'period_from', 'period_to'], ['period_from', 'period_to']],
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
        return [$writes, $report, $binaries];
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
            // v3.4.0 (N1020) — Kennungen ohne offensichtlich gefährliche Zeichen
            foreach ($row as $f => $v) {
                if (!is_string($f) || ($f !== 'id' && !str_ends_with($f, '_id')) || $v === null || self::safeId($v)) continue;
                $problem($pot, $i, "unsafe_id:$f"); $ok = false;
            }
            foreach (is_array($row['devices'] ?? null) ? $row['devices'] : [] as $d) {
                if (!is_array($d) || !isset($d['id']) || self::safeId($d['id'])) continue;
                $problem($pot, $i, 'unsafe_id:devices.id'); $ok = false;
                break;
            }
        }
        return $ok;
    }

    /**
     * v3.4.0 (N1020) — Kennung aus einem Backup: Text bis 200 Zeichen ohne
     * Steuerzeichen, Anführungszeichen, spitze Klammern und Backslash. Mehr
     * nicht: Bestehende Installationen mit ungewöhnlichen, aber harmlosen
     * Kennungen sollen ihr Backup weiter einspielen können. Neu angelegte
     * Kennungen prüft MeterService::ID enger.
     */
    public static function safeId(mixed $v): bool
    {
        return (is_string($v) || is_int($v)) && strlen((string)$v) <= 200
            && !preg_match('/[\x00-\x1F\x7F<>"\'`\\\\]/', (string)$v);
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
            $this->writeBackup(function (string $s) use ($fp): void {
                if (fwrite($fp, $s) !== strlen($s)) throw new \RuntimeException('Snapshot lässt sich nicht schreiben');
            }, true);
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
     * Schreibt ein Backup als JSON über `$w`, Topf für Topf roh und Beleg für
     * Beleg (base64). Gemeinsamer Weg von Snapshot und Export.
     *
     * @param callable(string): void $w
     */
    private function writeBackup(callable $w, bool $withFiles): void
    {
        $this->prepareExport();
        $enc =fn(mixed $v): string => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
        $w('}');
        if ($withFiles) {
            $w(',"attachment_files":{');
            $first = true;
            foreach ($this->attachmentFiles() as $id => $path) {
                $w(($first ? '' : ',') . $enc($id) . ':"' . base64_encode((string)file_get_contents($path)) . '"');
                $first = false;
            }
            $w('}');
        }
        // v3.3.0 (F1021) — Viertelstundenreihen
        $w(',"interval_files":{');
        foreach ($this->intervalIds() as $i => $id) {
            $w(($i ? ',' : '') . $enc($id) . ':' . $this->rawPot(IntervalService::DIR . "/$id.json", '{}'));
        }
        $w('}');
        $w('}');
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
        // v3.1.0 (H2) — verwaiste Belege (ohne Verweis, älter als 24 h)
        AttachmentService::sweep($this->store);
    }

    private function appVersion(): string
    {
        return trim(@file_get_contents(__DIR__ . '/../../VERSION') ?: '1.2.0');
    }
}
