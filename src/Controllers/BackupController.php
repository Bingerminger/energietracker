<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;

/**
 * Backup-Endpoints: Export als JSON, Import (nur Format 3.0+),
 * Snapshot-Erzeugung im Datenverzeichnis. Für v0.9.0-Backups
 * stattdessen den MigrationController benutzen.
 *
 * v2.6.0 — Import mit `?dry_run=1` (nur prüfen) und
 * `?allow_without_snapshot=1` (auch wenn der Sicherungs-Snapshot scheitert,
 * sonst 409). Snapshots: auflisten, laden, einspielen, löschen.
 */
final class BackupController
{
    public function __construct(private BackupService $backups) {}

    public function export(Request $req): never
    {
        Response::json($this->backups->export());
    }

    public function import(Request $req): never
    {
        try {
            $report = $this->backups->import(
                (array)$req->body,
                self::flag($req, 'dry_run'),
                self::flag($req, 'allow_without_snapshot'),
            );
        } catch (BackupInvalidException $e) {
            // Die Problemliste ist fachlicher Inhalt, kein Debug-Detail.
            Response::error($e->getMessage(), 400, ['problems' => $e->problems], null, true);
        }
        Response::json($report);
    }

    public function snapshot(Request $req): never
    {
        Response::json(['file' => $this->backups->saveSnapshot()]);
    }

    public function listSnapshots(Request $req): never
    {
        Response::json($this->backups->listSnapshots());
    }

    /** Download als Datei — gestreamt, ohne den Inhalt zu dekodieren. */
    public function downloadSnapshot(Request $req): never
    {
        $name = (string)$req->param('name');
        $path = $this->backups->snapshotPath($name);
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: no-store');
        }
        readfile($path);
        exit;
    }

    public function restoreSnapshot(Request $req): never
    {
        try {
            $report = $this->backups->restoreSnapshot((string)$req->param('name'), self::flag($req, 'allow_without_snapshot'));
        } catch (BackupInvalidException $e) {
            Response::error($e->getMessage(), 400, ['problems' => $e->problems], null, true);
        }
        Response::json($report);
    }

    public function deleteSnapshot(Request $req): never
    {
        $this->backups->deleteSnapshot((string)$req->param('name'));
        Response::json(['deleted' => true]);
    }

    private static function flag(Request $req, string $name): bool
    {
        return in_array(strtolower((string)$req->queryParam($name, '')), ['1', 'true', 'yes'], true);
    }
}
