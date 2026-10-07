<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H6, B7/MKT-12) — Großhandelspreise Strom: lesen, aus einer
 * Datei übernehmen, auf Knopfdruck von SMARD holen.
 */
final class MarketPriceController
{
    public function __construct(private MarketPriceService $prices, private I18nService $i18n) {}

    public function index(Request $req): never
    {
        Response::json($this->prices->get());
    }

    public function importCsv(Request $req): never
    {
        Response::json($this->prices->importCsv((string)$req->rawBody, $req->queryParam('dry_run') === '1'));
    }

    /**
     * Antwortet SMARD nicht oder unbrauchbar, ist das ein 502 (Fehler auf der
     * anderen Seite) wie bei der Texterkennung — keine falsche Eingabe.
     */
    public function syncSmard(Request $req): never
    {
        try {
            $r = $this->prices->syncSmard();
        } catch (LocalizedException $e) {
            if ($e->key !== 'errors.marketPrices.syncFailed') throw $e;
            Response::error($this->i18n->t($e->key, $e->params), 502, null, $e->key);
        }
        Response::json($r);
    }
}
