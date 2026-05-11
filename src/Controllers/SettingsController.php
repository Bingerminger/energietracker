<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\SettingsService;

/**
 * Settings als Read und partielles PATCH. Sensitive Validierungen
 * (z.B. negative Konstanten) werden im SettingsService durchgesetzt.
 */
final class SettingsController
{
    public function __construct(private SettingsService $settings) {}

    public function index(Request $req): never
    {
        Response::json($this->settings->all());
    }

    public function update(Request $req): never
    {
        Response::json($this->settings->set((array)$req->body));
    }
}
