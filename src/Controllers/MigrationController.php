<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\MigrationService;

/**
 * Migration endpoints. Two-step flow:
 *
 *   1. POST /api/migration/v09/preview
 *      Body: { backup: <v0.9.0 backup JSON> }
 *      Returns:
 *        {
 *          ok: bool,
 *          legacy_version: string,
 *          translated: { ... } ← echo for the apply step
 *          report: { readings, contracts, temperatures, settings, warnings,
 *                    device_replacement_candidates }
 *        }
 *
 *   2. POST /api/migration/v09/import
 *      Body: { translated: <preview-output.translated>, mode: 'replace'|'merge' }
 *      Returns:
 *        {
 *          mode: string,
 *          snapshot: string  ← filename of the safety snapshot
 *          written: { gas: { meters,readings,contracts }, strom: ..., wasser: ... }
 *        }
 */
final class MigrationController
{
    public function __construct(private MigrationService $migration) {}

    /** POST /api/migration/v09/preview */
    public function preview(Request $req): never
    {
        $backup = $req->input('backup');
        if (!is_array($backup)) {
            Response::error('Feld "backup" fehlt oder ist kein Objekt', 400);
        }
        try {
            Response::json($this->migration->preview($backup));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /** POST /api/migration/v09/import */
    public function import(Request $req): never
    {
        $translated = $req->input('translated');
        $mode       = (string)$req->input('mode', '');
        if (!is_array($translated)) {
            Response::error('Feld "translated" fehlt oder ist kein Objekt', 400);
        }
        try {
            Response::json($this->migration->apply($translated, $mode));
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
