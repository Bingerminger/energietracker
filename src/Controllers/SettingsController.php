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
        $patch = (array)$req->body;
        $result = $this->settings->set($patch);
        // v2.6.0 — unbekannte Schlüssel wurden still verworfen; ein Skript mit
        // dem Altschlüssel `gas_conversion_factor` (bis v2.4) bekam „Erfolg"
        // ohne Wirkung. Jetzt stehen sie in der Antwort (nur wenn es welche
        // gibt, sonst bleibt die Antwort wie bisher).
        $ignored = array_values(array_diff(array_keys($patch), $this->settings->knownKeys()));
        if ($ignored !== []) {
            if (!headers_sent()) header('X-Ignored-Keys: ' . implode(', ', $ignored));
            $result['ignored_keys'] = $ignored;
            if (in_array('gas_conversion_factor', $ignored, true)) {
                $result['ignored_hint'] = 'gas_conversion_factor → gas_conversion_factors (v2.5.0)';
            }
        }
        Response::json($result);
    }
}
