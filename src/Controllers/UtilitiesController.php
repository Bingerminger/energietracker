<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Config\Utilities;

/**
 * Static-Config-Endpoint: liefert die Konfiguration der drei
 * Verbrauchsarten (Gas, Strom, Wasser) aus `src/Config/Utilities.php`.
 * Wird vom Frontend einmal beim Start gelesen.
 */
final class UtilitiesController
{
    public function index(Request $req): never
    {
        Response::json(Utilities::all());
    }
}
