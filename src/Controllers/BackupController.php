<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BackupService;

/**
 * Backup-Endpoints: Export als JSON, Import (nur Format 3.0+),
 * Snapshot-Erzeugung im Datenverzeichnis. Für v0.9.0-Backups
 * stattdessen den MigrationController benutzen.
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
        $report = $this->backups->import((array)$req->body);
        Response::json($report);
    }

    public function snapshot(Request $req): never
    {
        Response::json(['file' => $this->backups->saveSnapshot()]);
    }
}
