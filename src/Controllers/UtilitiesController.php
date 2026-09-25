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
 *
 * v2.13.0 (Review FE-14) — jede Verbrauchsart trägt `has_contracts`,
 * `has_advance_payment_contracts` und `accounting_kind` (additiv). Bis v2.12
 * standen die Felder nur, wenn sie in der Konfiguration gesetzt waren, und das
 * Frontend hielt eine eigene Kopie der Regel — sie bot PV-Einspeisung
 * Sonderzahlungen an, die der Server still verwarf.
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
            $u['has_contracts']                 = Utilities::hasContracts($key);
            $u['has_advance_payment_contracts'] = Utilities::hasAdvancePaymentContracts($key);
            $u['accounting_kind']               = Utilities::accountingKind($key);
        }
        unset($u);
        Response::json($all);
    }
}
