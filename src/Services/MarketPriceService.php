<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\Outbound;

/**
 * v3.1.0 (Paket H6, B7/MKT-12) — Großhandelspreise Strom (Day-Ahead DE-LU)
 * als Monatsmittel, Grundlage des Dynamik-Checks.
 *
 * Topf `market_prices.json`: {source smard|csv, area 'DE-LU', unit 'ct/kWh',
 * months {JJJJ-MM: {avg_ct}}, imported_at}. Gefüllt per Datei (SMARD-Download,
 * Stunden- oder Viertelstundenwerte, oder `JJJJ-MM;€/MWh`) oder auf
 * ausdrücklichen Knopfdruck von SMARD (Filter 4169, Monatswerte). Keine
 * Abfrage im Hintergrund. Namensnennung: „Bundesnetzagentur | SMARD.de"
 * (CC BY 4.0).
 *
 * Ein dynamischer Schattenvertrag (`price_model: 'dynamic'`) wird für Vergleich
 * und Wechsel in Monatspreise übersetzt ({@see expand()}): je Monat Spotmittel
 * × (1 + USt) + Aufschlag, dazu ein Grundpreis. Lastprofile (BDEW H25) sind
 * nicht dabei — sie sind ohne Lizenz veröffentlicht; gerechnet wird mit dem
 * Monatsmittel, also als verteile sich der Verbrauch gleichmäßig über den Tag.
 */
final class MarketPriceService
{
    public const FILE = 'market_prices.json';
    private const SMARD = 'https://www.smard.de/app/chart_data/4169/DE/';
    /**
     * Umsatzsteuer auf den Börsenpreis, wenn das dynamische Angebot keinen
     * Satz nennt (Regelsatz, § 12 Abs. 1 UStG). v3.6.0 — auch für die Hebel
     * der Flexibilität (FlexibilityInsightService).
     */
    public const DEFAULT_VAT_PCT = 19.0;

    /** @param (\Closure(string):?string)|null $fetch Abruf einer URL (Tests) */
    public function __construct(
        private JsonStore $store,
        private I18nService $i18n,
        private ?\Closure $fetch = null,
        private ?IntervalService $intervals = null,   // v3.3.0 (F1021)
    ) {}

    /**
     * v3.3.0 (F1021) — Börsenpreise je Viertelstunde eines Jahres von SMARD
     * (Day-Ahead DE-LU, €/MWh → ct/kWh). SMARD liefert Wochenpakete; vor dem
     * 1. Oktober 2025 (Stundenprodukte) steht derselbe Wert in allen vier
     * Viertelstunden einer Stunde. Nur auf Knopfdruck; nichts geht hinaus.
     *
     * v3.5.1 — optional nur ein Monat (`$month` 1–12): Die Oberfläche lädt ein
     * Jahr Monat für Monat und zeigt den Fortschritt; ohne Monat wie bisher das
     * ganze Jahr in einer Anfrage (rund 53 Wochenpakete).
     *
     * @return array{year:int, slots:int, from:?string, to:?string}
     */
    public function syncSmardIntervals(int $year, ?int $month = null): array
    {
        if ($this->intervals === null) throw new LocalizedException(self::QH_FAILED, [], 'no interval store');
        try {
            return $this->fetchSmardIntervals($year, $month);
        } catch (LocalizedException $e) {
            // eigene Meldung: für Viertelstundenpreise gibt es keinen Dateiimport als Ausweg
            if ($e->key !== 'errors.marketPrices.syncFailed') throw $e;
            throw new LocalizedException(self::QH_FAILED, [], $e->getMessage());
        }
    }

    /** Fehlercode des Viertelstundenabrufs (v3.3.0) — der Monatsabruf behält `syncFailed`. */
    public const QH_FAILED = 'errors.marketPrices.syncQuarterHoursFailed';

    /** @return array{year:int, slots:int, from:?string, to:?string} */
    private function fetchSmardIntervals(int $year, ?int $month = null): array
    {
        $index = json_decode((string)$this->fetch(self::SMARD . 'index_quarterhour.json'), true);
        $stamps = is_array($index['timestamps'] ?? null) ? $index['timestamps'] : [];
        if ($month === null) {
            $start = $this->intervals->yearStart($year);
            $end = $this->intervals->yearStart($year + 1);
        } else {
            // Monatsgrenzen in der Zeitzone der Installation (wie yearStart)
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $this->intervals->tz());
            $start = $first->getTimestamp();
            $end = $first->modify('+1 month')->getTimestamp();
        }
        // ein Wochenpaket beginnt bis zu sieben Tage vor dem Zeitraum
        $from = ($start - 7 * 86400) * 1000;
        $to = $end * 1000;
        $slots = [];
        foreach ($stamps as $ts) {
            if (!is_numeric($ts) || $ts < $from || $ts >= $to) continue;
            $chunk = json_decode((string)$this->fetch(self::SMARD . '4169_DE_quarterhour_' . (int)$ts . '.json'), true);
            foreach ((array)($chunk['series'] ?? []) as $pt) {
                if (!is_array($pt) || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)) continue;
                $t = intdiv((int)$pt[0], 1000);
                if ($t < $start || $t * 1000 >= $to) continue;
                $slots[$t] = round((float)$pt[1] / 10, 4);
            }
        }
        if ($slots === []) throw new LocalizedException(self::QH_FAILED, [], 'smard quarterhour empty');
        ksort($slots);
        $r = $this->intervals->mergePrices($slots, 'smard');
        $iso = fn(int $t) => (new \DateTimeImmutable('@' . $t))->setTimezone($this->intervals->tz())->format('Y-m-d');
        return ['year' => $year, 'slots' => $r['slots'], 'from' => $iso((int)array_key_first($slots)), 'to' => $iso((int)array_key_last($slots))];
    }

    /** @return array{source:?string, area:string, unit:string, months:array<string,array{avg_ct:float}>, imported_at:?string} */
    public function get(): array
    {
        $d = $this->store->read(self::FILE, []);
        $d = is_array($d) ? $d : [];
        $months = is_array($d['months'] ?? null) ? $d['months'] : [];
        ksort($months);
        return ['source' => $d['source'] ?? null, 'area' => 'DE-LU', 'unit' => 'ct/kWh', 'months' => $months,
                'imported_at' => $d['imported_at'] ?? null,
                'attribution' => 'Bundesnetzagentur | SMARD.de (CC BY 4.0)'];
    }

    /**
     * Monatsmittel in ct/kWh (netto, Großhandel). Fehlt der Monat (Zukunft),
     * gilt derselbe Monat des Vorjahres (bis drei Jahre zurück) als Annahme.
     *
     * @return array{ct: float, assumed: bool, from: string}|null
     */
    public function monthCt(string $ym): ?array
    {
        $months = $this->get()['months'];
        for ($back = 0; $back <= 3; $back++) {
            $key = sprintf('%04d-%s', (int)substr($ym, 0, 4) - $back, substr($ym, 5, 2));
            if (isset($months[$key]['avg_ct']) && is_numeric($months[$key]['avg_ct'])) {
                return ['ct' => (float)$months[$key]['avg_ct'], 'assumed' => $back > 0, 'from' => $key];
            }
        }
        return null;
    }

    /**
     * Datei-Import. Erkennt den SMARD-Download (Kopf mit „Datum von" und einer
     * Spalte „Deutschland/Luxemburg [€/MWh]", Werte je Stunde oder Viertelstunde,
     * deutsches Dezimalkomma, „-" für fehlend) und das einfache Format
     * `JJJJ-MM;€/MWh`. Mittelt je Monat (Tage mit 23 oder 25 Stunden zählen mit
     * ihren Werten) und ersetzt diese Monate.
     *
     * @return array<string,mixed>
     */
    public function importCsv(string $text, bool $dryRun = false): array
    {
        $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\n|\r/', trim($text)) ?: [];
        $col = null; $sum = []; $n = []; $rows = 0; $skipped = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, ';', '"', '');
            if ($col === null && preg_match('/datum|date/i', (string)($cells[0] ?? ''))) {
                foreach ($cells as $i => $h) {
                    if (preg_match('/(Deutschland\/Luxemburg|DE-LU|Germany\/Luxembourg).*(€|EUR)\/MWh/iu', (string)$h)) { $col = $i; break; }
                }
                $col ??= count($cells) > 2 ? 2 : 1;
                continue;
            }
            [$ym, $value] = $this->parseRow($cells, $col);
            if ($ym === null) { $skipped++; continue; }
            if ($value === null) continue;   // „-": fehlender Wert
            $sum[$ym] = ($sum[$ym] ?? 0.0) + $value;
            $n[$ym] = ($n[$ym] ?? 0) + 1;
            $rows++;
        }
        if ($sum === []) throw new LocalizedException('errors.marketPrices.noRows', [], 'market csv empty');
        $months = [];
        foreach ($sum as $ym => $s) $months[$ym] = ['avg_ct' => round($s / $n[$ym] / 10, 3)];
        ksort($months);
        $result = ['months' => count($months), 'from' => array_key_first($months), 'to' => array_key_last($months),
                   'rows' => $rows, 'skipped' => $skipped];
        if ($dryRun) return $result + ['would_import' => count($months), 'preview' => $months];
        $this->merge($months, 'csv');
        return $result;
    }

    /**
     * Auf Knopfdruck: Monatswerte von SMARD (Filter 4169 Großhandelspreis
     * DE/LU, €/MWh) holen und eintragen. Nur dieser Abruf geht nach außen.
     *
     * @return array<string,mixed>
     */
    public function syncSmard(): array
    {
        $index = json_decode((string)$this->fetch(self::SMARD . 'index_month.json'), true);
        $stamps = is_array($index['timestamps'] ?? null) ? $index['timestamps'] : [];
        if ($stamps === []) throw new LocalizedException('errors.marketPrices.syncFailed', [], 'smard index');
        $months = [];
        foreach (array_slice($stamps, -4) as $ts) {   // die letzten vier Jahre reichen für Vergleich und Annahme
            $chunk = json_decode((string)$this->fetch(self::SMARD . '4169_DE_month_' . (int)$ts . '.json'), true);
            foreach ((array)($chunk['series'] ?? []) as $pt) {
                if (!is_array($pt) || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)) continue;
                // Zeitstempel = Monatsbeginn deutscher Zeit (UTC 22:00/23:00 am Vortag)
                $ym = (new \DateTimeImmutable('@' . intdiv((int)$pt[0], 1000)))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m');
                $months[$ym] = ['avg_ct' => round((float)$pt[1] / 10, 3)];
            }
        }
        if ($months === []) throw new LocalizedException('errors.marketPrices.syncFailed', [], 'smard series');
        ksort($months);
        $this->merge($months, 'smard');
        return ['months' => count($months), 'from' => array_key_first($months), 'to' => array_key_last($months)];
    }

    /**
     * Ein dynamischer Schattenvertrag als Monatspreise für Vergleich und
     * Wechsel: Arbeitspreis je Monat = Spotmittel × (1 + USt) + Aufschlag,
     * Grundpreis fest. Monate ohne Marktwert nehmen den Vorjahresmonat
     * (`dynamic_assumed`). Andere Verträge bleiben, wie sie sind.
     *
     * v3.3.0 (F1021) — `$weighted` (JJJJ-MM → ct, FlexibilityService::weightedSpot):
     * Monate mit eigenem Lastgang rechnen mit dem nach Verbrauch gewichteten
     * Börsenpreis statt dem Monatsmittel (`dynamic_profile`).
     *
     * @param array<string,float> $weighted
     * @return array<string,mixed>
     */
    public function expand(array $c, string $fromYm, string $toYm, array $weighted = []): array
    {
        if (($c['price_model'] ?? 'fixed') !== 'dynamic' || !is_array($c['dynamic'] ?? null)) return $c;
        $d = $c['dynamic'];
        $vat = (float)($d['vat_pct'] ?? self::DEFAULT_VAT_PCT) / 100;
        $markup = (float)($d['markup_ct_per_kwh'] ?? 0);
        // v3.2.0 — dynamisch gibt es nur als Schattenvertrag, und der ist ein
        // Preisblatt für den ganzen Zeitraum (CALC-21): Ein Angebot, das erst
        // nächstes Jahr beginnen würde, rechnet trotzdem mit den Preisen jedes
        // Monats. Bis v3.1 begann die Reihe beim Vertragsbeginn — ein künftiges
        // Angebot meldete „keine Marktdaten“.
        $prices = []; $assumed = []; $missing = []; $profile = [];
        for ($t = strtotime($fromYm . '-01'); $t !== false && date('Y-m', $t) <= $toYm; $t = strtotime('+1 month', $t)) {
            $ym = date('Y-m', $t);
            if (isset($weighted[$ym])) {
                $profile[] = $ym;
                $prices[] = ['from' => $ym . '-01', 'ct_per_kwh' => round((float)$weighted[$ym] * (1 + $vat) + $markup, 4)];
                continue;
            }
            $spot = $this->monthCt($ym);
            if ($spot === null) { $missing[] = $ym; continue; }
            if ($spot['assumed']) $assumed[] = $ym;
            $prices[] = ['from' => $ym . '-01', 'ct_per_kwh' => round($spot['ct'] * (1 + $vat) + $markup, 4)];
        }
        $c['working_prices'] = $prices;
        $c['base_prices'] = isset($d['base_eur_month']) && is_numeric($d['base_eur_month'])
            ? [['from' => $fromYm . '-01', 'eur_per_month' => (float)$d['base_eur_month']]] : [];
        $c['dynamic_assumed'] = $assumed;
        $c['dynamic_missing'] = $missing;
        $c['dynamic_profile'] = $profile;
        return $c;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @param array<string,array{avg_ct:float}> $months */
    private function merge(array $months, string $source): void
    {
        // v3.4.0 (N1020) — die Schreibsperre erst nach dem Abruf (s. App::LOCK_LATE)
        $this->store->lock();
        $cur = $this->get()['months'];
        foreach ($months as $ym => $v) $cur[$ym] = $v;
        ksort($cur);
        $this->store->write(self::FILE, ['source' => $source, 'area' => 'DE-LU', 'unit' => 'ct/kWh', 'months' => $cur, 'imported_at' => date('c')]);
    }

    /** @return array{0: ?string, 1: ?float} Monat und Wert in €/MWh */
    private function parseRow(array $cells, ?int $col): array
    {
        $first = trim((string)($cells[0] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})$/', $first, $m)) {
            $ym = "$m[1]-$m[2]";
            $raw = (string)($cells[1] ?? '');
        } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $first, $m)) {
            $ym = "$m[3]-$m[2]";
            $raw = (string)($cells[$col ?? 2] ?? '');
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $first, $m)) {
            $ym = "$m[1]-$m[2]";
            $raw = (string)($cells[$col ?? 2] ?? '');
        } else {
            return [null, null];
        }
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') return [$ym, null];
        $v = ReadingImportService::parseNum($raw);
        return [$ym, $v];
    }

    private function fetch(string $url): ?string
    {
        if ($this->fetch !== null) return ($this->fetch)($url);
        if (!function_exists('curl_init')) throw new LocalizedException('errors.marketPrices.syncFailed', [], 'no curl');
        $ch = curl_init($url);
        // v3.4.0 (N1020) — nur HTTPS, höchstens zwei Weiterleitungen, Antwort höchstens 20 MB
        $body = '';
        curl_setopt_array($ch, [CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_USERAGENT => 'Energietracker (PHP)'] + Outbound::curlOptions(true, true, $body));
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false || $code !== 200) throw new LocalizedException('errors.marketPrices.syncFailed', [], "smard http $code errno " . curl_errno($ch));
        return $body;
    }
}
