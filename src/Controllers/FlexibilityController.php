<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\I18nService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Support\LocalizedException;

/**
 * v3.3.0 (F1021) — Flexibilität bewerten.
 *
 *   GET    /api/flexibility?year=                       Auswertung eines Jahres
 *   GET    /api/intervals                               Index der Viertelstundenreihen
 *   DELETE /api/intervals/{id}                          eine Reihe löschen
 *   POST   /api/market-prices/sync-smard-intervals?year= Börsenpreise je Viertelstunde (SMARD)
 */
final class FlexibilityController
{
    public function __construct(
        private FlexibilityService $flex,
        private IntervalService $intervals,
        private MarketPriceService $prices,
        private I18nService $i18n,
    ) {}

    public function analyze(Request $req): never
    {
        Response::json($this->flex->analyze($this->year($req)));
    }

    public function list(Request $req): never
    {
        Response::json(['series' => $this->intervals->list()]);
    }

    public function delete(Request $req): never
    {
        try {
            $this->intervals->delete((string)$req->param('id'));
        } catch (LocalizedException $e) {
            Response::error($this->i18n->t($e->key, $e->params), 404, null, $e->key);
        }
        Response::json(['deleted' => true]);
    }

    /** Antwortet SMARD nicht, ist das ein 502 wie beim Monatsabruf. */
    public function syncSmard(Request $req): never
    {
        try {
            $r = $this->prices->syncSmardIntervals($this->year($req), $this->month($req));
        } catch (LocalizedException $e) {
            if ($e->key !== MarketPriceService::QH_FAILED) throw $e;
            Response::error($this->i18n->t($e->key, $e->params), 502, null, $e->key);
        }
        Response::json($r);
    }

    /** v3.5.1 — optionaler Monat (1–12) für den Abruf in Schritten mit Fortschritt. */
    private function month(Request $req): ?int
    {
        $m = (string)($req->queryParam('month') ?? '');
        if ($m === '') return null;
        if (!ctype_digit($m) || (int)$m < 1 || (int)$m > 12) {
            Response::error($this->i18n->t('errors.flexibility.monthInvalid', ['month' => $m]), 400, null, 'errors.flexibility.monthInvalid');
        }
        return (int)$m;
    }

    private function year(Request $req): int
    {
        $y = (string)($req->queryParam('year') ?? '');
        if ($y === '') return (int)date('Y') - 1;
        if (!ctype_digit($y) || (int)$y < 2018 || (int)$y > 2100) {
            Response::error($this->i18n->t('errors.flexibility.yearInvalid', ['year' => $y]), 400, null, 'errors.flexibility.yearInvalid');
        }
        return (int)$y;
    }
}
