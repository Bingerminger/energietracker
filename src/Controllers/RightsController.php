<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Config\Utilities;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BillService;
use Energietracker\Services\RightsService;

/**
 * v3.11.0 (F1035, Paket basis) — Rechte als Kunde.
 *   GET /api/rights[?utility=&meter_id=&contract_id=&bill_id=&check=&todo=1]
 *       {enabled, country, today, bills_recorded, findings: [Befund…], failed: [{check, error}]}
 *
 * Ohne Filter alle Befunde, auch die außerhalb des Fensters (`todo: false`, für „Rechnung
 * prüfen“). Die Filter vergleichen genau; ein unbekannter Wert ergibt eine leere Liste.
 * Die Pages-Demo hat nur die Antwort ohne Filter — Ansichten filtern deshalb im Browser.
 *
 * v3.11.0 (Skeptiker M7) — `bills_recorded` (additiv): Zahl der erfassten Versorgerrechnungen
 * über alle Arten (null ohne Rechnungsdienst). Ohne Rechnung haben fünf der sieben Prüfungen
 * nichts zu prüfen; die Empfehlungsseite sagt das, statt einer leeren Liste.
 */
final class RightsController
{
    private const FILTERS = ['utility', 'meter_id', 'contract_id', 'bill_id', 'check'];

    public function __construct(private RightsService $rights, private ?BillService $bills = null) {}

    public function index(Request $req): never
    {
        $today = date('Y-m-d');
        $findings = $this->rights->findings($today);
        foreach (self::FILTERS as $f) {
            $want = $req->queryParam($f);
            if ($want === null || $want === '') continue;
            $findings = array_values(array_filter($findings, fn($x) => ($x[$f] ?? null) === $want));
        }
        if ($req->queryParam('todo') === '1') $findings = array_values(array_filter($findings, fn($x) => $x['todo']));
        Response::json([
            'enabled'  => $this->rights->enabled(),
            'country'  => $this->rights->country(),
            'today'    => $today,
            'bills_recorded' => $this->billCount(),
            // `facts` und `params` sind immer Objekte, auch leer (JSON: {} statt [])
            'findings' => array_map(fn($x) => array_replace($x, ['facts' => (object)$x['facts'], 'params' => (object)$x['params']]), $findings),
            'failed'   => $this->rights->failed(),
        ]);
    }

    private function billCount(): ?int
    {
        if ($this->bills === null) return null;
        $n = 0;
        foreach (Utilities::keys() as $u) if (Utilities::supportsBillCheck($u)) $n += count($this->bills->list($u));
        return $n;
    }
}
