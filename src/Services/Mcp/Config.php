<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Services\SettingsService;

/**
 * v3.12.0 (F1037) — Schalter der KI-Schnittstelle (Entscheidungen 6 und 12).
 *
 * - `mcp_enabled` (Standard aus, nur Verwalter): ohne ihn antwortet /api/mcp mit
 *   403 und sagt, wo man einschaltet.
 * - `ET_MCP=off` sperrt fest, auch gegen den Schalter (für Betreiber, die es nie
 *   wollen; Synology ohne Umgebungsvariablen nutzt den Schalter).
 * - Kein Browser-Zugriff: ein fremder `Origin` bleibt 403, eine Erlaubnisliste
 *   gibt es nicht (Entscheidung 2026-11-03, `mcp_allowed_origins` entfällt).
 * - `mcp_share_identifiers`, `mcp_share_documents`: Kennnummern (Kunden-,
 *   Vertrags-, Rechnungs-, Zählernummer, Markt- und Messlokation) bzw. Original-
 *   PDFs gehen nur mit eigenem Schalter an die KI.
 * - `mcp_apply_in_app` (v3.12.0, Skeptiker M1; Standard an): `apply_proposal` schreibt
 *   nichts, die Person bestätigt in der App. Aus: `apply_proposal` schreibt, geschützt
 *   nur durch die Nachfrage des KI-Programms, die der Server nicht prüfen kann.
 */
final class Config
{
    public const ENV = 'ET_MCP';
    /** Werte von ET_MCP, die sperren */
    public const ENV_OFF = ['off', '0', 'false', 'no'];

    public function __construct(private SettingsService $settings) {}

    public static function lockedByEnv(): bool
    {
        return in_array(strtolower(trim((string)getenv(self::ENV))), self::ENV_OFF, true);
    }

    /** Eingeschaltet und nicht per Umgebung gesperrt. */
    public function enabled(): bool
    {
        return !self::lockedByEnv() && $this->settings->get('mcp_enabled', false) === true;
    }

    public function shareIdentifiers(): bool
    {
        return $this->settings->get('mcp_share_identifiers', false) === true;
    }

    public function shareDocuments(): bool
    {
        return $this->settings->get('mcp_share_documents', false) === true;
    }

    /** Nur ein ausdrückliches Aus schaltet die Bestätigung in der App ab (Standard: an). */
    public function applyInApp(): bool
    {
        return $this->settings->get('mcp_apply_in_app', true) !== false;
    }

    /**
     * Stand für Oberfläche und Diagnose (ohne Geheimnisse).
     *
     * @return array{enabled:bool, setting:bool, locked_by_env:bool, share_identifiers:bool, share_documents:bool, apply_in_app:bool}
     */
    public function state(): array
    {
        return [
            'enabled'           => $this->enabled(),
            'setting'           => $this->settings->get('mcp_enabled', false) === true,
            'locked_by_env'     => self::lockedByEnv(),
            'share_identifiers' => $this->shareIdentifiers(),
            'share_documents'   => $this->shareDocuments(),
            'apply_in_app'      => $this->applyInApp(),
        ];
    }
}
