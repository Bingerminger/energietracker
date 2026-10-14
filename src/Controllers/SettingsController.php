<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\HostList;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Support\LocalizedException;
use Energietracker\Config\Countries;
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
        // v3.4.0 (N1020) — Hostliste: Der Name, unter dem die App gerade
        // aufgerufen wird, muss danach noch gelten, sonst sperrte man sich
        // selbst aus. Über eine IP-Adresse aufgerufen gilt sie immer.
        if (array_key_exists('allowed_hosts', $patch) && is_string($patch['allowed_hosts'])) {
            $list = HostList::parse($patch['allowed_hosts']);
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($list !== [] && !HostList::allows($host, array_merge(HostList::fromEnv(), $list))) {
                throw new LocalizedException('errors.settings.hostNotListed', ['host' => HostList::hostOf($host)],
                    'allowed_hosts would lock out the current host');
            }
        }
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

    /**
     * v2.7.0 — Länderprofile (GET /api/countries): Voreinstellungen je Land,
     * damit die Einstellungen vor dem Übernehmen zeigen können, was sich
     * ändert. Quelle der Wahrheit: Config\Countries.
     */
    public function countries(Request $req): never
    {
        Response::json(Countries::all());
    }

    /**
     * v3.5.0 (N1022) — Land wechseln (POST /api/settings/country):
     * `{"country": "AT", "profile": true}` übernimmt das Länderprofil, mit
     * `"profile": false` nur das Land. Festgeschrieben wird nur, was vom
     * Default abweicht (SettingsService::applyCountry) — dieselbe Regel wie
     * beim Erststart. Antwort: alle Einstellungen wie bei PATCH.
     */
    public function country(Request $req): never
    {
        $body = (array)$req->body;
        $code = is_string($body['country'] ?? null) ? $body['country'] : '';
        // ein unbekanntes Land meldet applyCountry() als 400 (errors.settings.valueInvalid)
        Response::json($this->settings->applyCountry($code, ($body['profile'] ?? true) !== false));
    }

    /**
     * v2.10.0 — Korrigierte Standardwerte, die diese Installation noch nicht
     * nutzt (Migration 1.6.0 hat die alten festgeschrieben, Lektion 36).
     * Übernommen wird per PATCH /api/settings.
     */
    public function defaultUpdates(Request $req): never
    {
        Response::json($this->settings->defaultUpdates());
    }
}
