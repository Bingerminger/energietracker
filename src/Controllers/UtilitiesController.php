<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Config\Utilities;
use Energietracker\Services\I18nService;

/**
 * Static-Config-Endpoint: liefert die Konfiguration aller Verbrauchsarten
 * aus `src/Config/Utilities.php`. Wird vom Frontend einmal beim Start gelesen.
 *
 * N1007 (v2.0.0): das `label` jeder Verbrauchsart wird über den I18nService
 * sprachabhängig ersetzt (Accept-Language). Fehlt ein Katalog-Eintrag, bleibt
 * das deutsche Default-Label aus Utilities.php erhalten.
 */
final class UtilitiesController
{
    public function __construct(private I18nService $i18n) {}

    public function index(Request $req): never
    {
        $all = Utilities::all();
        foreach ($all as &$u) {
            $key = $u['key'] ?? null;
            if ($key === null) continue;
            $catalogKey = 'utilityNames.' . $key;
            $translated = $this->i18n->t($catalogKey);
            if ($translated !== $catalogKey) {
                $u['label'] = $translated;
            }
        }
        unset($u);
        Response::json($all);
    }
}
