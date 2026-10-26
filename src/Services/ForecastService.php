<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * 12-month forecast per meter.
 *
 * For HGT-relevant utilities (gas): blend of HGT regression (using future
 * seasonal HGT means) and seasonal kWh profile, weighted by regression R².
 *
 * For non-HGT utilities (strom, wasser): pure seasonal profile —
 * the regression step is skipped.
 *
 * Cost projection (F-02, v1.1.0)
 * ------------------------------
 * The monthly cost forecast is no longer "last working price × volume".
 * For every forecast month the contract active in that month is resolved,
 * and the working price / base price / advance payment **valid for that
 * month** are looked up from the contract's price history. A price change
 * pflegt in a contract for a future month is therefore reflected in
 * `cost_estimated`. The projection is buchhalterisch vollständig:
 *
 *   cost_estimated    = Arbeitspreis × Menge + Grundpreis − bekannte Boni
 *   advance_estimated = der für den Monat gültige Abschlag (oder null)
 *   balance_running   = kumulierte (cost_estimated − advance_estimated)
 *
 * Future bonuses are NOT extrapolated — only bonuses explicitly pflegt in
 * the contract with a credit_date in the forecast window count.
 *
 * Wasser: The Schmutzwasser volume of a forecast month is the Trinkwasser
 * volume times the share of the same calendar month in the meter's history
 * (v3.1.0, CALC-20 — garden water in summer, separate meter). Without
 * history the share is 1. Until v3.0 the full Trinkwasser volume was used.
 *
 * v2.8.0 (Review CALC-03, CALC-12, CALC-13)
 * -----------------------------------------
 *   - Heizgradtage der Prognosemonate aus dem Klimanormal (sonst aus der
 *     eigenen Temperaturhistorie), nicht aus den Verbrauchsmonaten. Ein
 *     Kalendermonat ohne eigene Historie hatte bisher 0 HGT und das
 *     Saisonmittel 0 — wer im Mai begann, bekam für Januar 151 statt
 *     2.068 kWh (−54 % im Jahr), und die Wechselentscheidung übernahm das.
 *     Fehlende Monate kommen jetzt aus dem Modell; bei Arten ohne Wetterbezug
 *     aus dem Tagesmittel. `warnings` sagt, wenn die Historie kurz ist.
 *   - Temperaturversatz: ein um δ wärmeres Jahr hat die Heizgradtage der
 *     Heizgrenze − δ (mit dem Klimanormal exakt; vorher linear über alle
 *     Monatstage, auch die ohne Heizbedarf).
 *   - Unsicherheit: Band je Monat und fürs Jahr aus der Streuung der Winter
 *     (Klimanormal) mal Verbrauch je Gradtag, plus Modellrauschen. Breite
 *     über `confidence_band_sigma` (Default 1,28 σ ≈ 80 %).
 *   - Abschläge aus dem effektiven Plan (Sonderzahlungen „mit Auswirkung"),
 *     und nach Vertragsende läuft der letzte Vertrag weiter — Verträge
 *     verlängern sich (§ 309 Nr. 9 BGB), die Monate sind als Annahme markiert.
 */
final class ForecastService
{
    /**
     * v3.7.0 (wirklichkeit-2, Schritt 3, N1) — Mindestzahl an Freiheitsgraden der
     * gepoolten Streuung (Σ(n − 1) über die Kalendermonate) für ein Band ohne
     * Heizmodell. Drei: Drei Kalendermonate mit zwei Jahren Werten sind das
     * Wenigste, bei dem eine Streuung nicht an einem einzigen Monatspaar hängt.
     * Darunter, etwa mit einem Wert je Monat, zeigt die Prognose kein Band.
     */
    public const BAND_MIN_DOF = 3;

    public function __construct(
        private ConsumptionService $consumption,
        private RegressionService $regression,
        private SettingsService $settings,
        private ContractService $contracts,
        private I18nService $i18n,
    ) {}

    /** v3.1.0 (H6) — für den Wechsel: Gruppenvertrag mit HT/NT als Mischpreis. */
    public function contractView(string $utility, array $meter, array $c): array
    {
        return $this->consumption->contractView($utility, $meter, $c);
    }

    public function forMeter(string $utility, array $meter, array $opts = []): array
    {
        $u = Utilities::get($utility);
        $monthly = $this->consumption->forMeter($utility, $meter);
        if (count($monthly) < 6) {
            return ['valid' => false, 'reason' => $this->i18n->t('errors.forecast.tooFewMonths')];
        }

        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        // v3.1.0 (Review CALC-20) — Schmutzwasser-Anteil je Kalendermonat aus der
        // eigenen Historie (Gartenwasser im Sommer, separater Zähler); ohne Daten 1.
        $sewageShare = $utility === 'wasser' ? self::sewageShareByMonth($monthly) : [];
        $fcMonths   = (int)($opts['forecast_months'] ?? $this->settings->get('forecast_months', 12));
        $minDays    = (int)$this->settings->get('min_days_period', 20);
        $blendMax   = (float)$this->settings->get('blend_max', 0.80);
        $tempOffset = (float)($opts['temp_offset'] ?? 0.0);
        $priceFactor= (float)($opts['price_factor'] ?? 1.0);
        $hgt        = !empty($u['hgt_relevant']);
        $hddBase    = $this->consumption->hddBaseFor($meter);   // v3.7.0 — die Heizgrenze des Zählers

        // Saisonprofil je Kalendermonat als Tagesrate (Teilmonate zählen
        // nicht, 28 und 31 Tage werden vergleichbar).
        $rates = array_fill(1, 12, []);
        $interpRates = array_fill(1, 12, []);   // v3.7.0 (#37)
        $seasonalHdd = array_fill(1, 12, []);
        $allRates = [];
        foreach ($monthly as $m) {
            // v1.4.0 — F1011: Monate vor der Zäsur beschreiben ein anderes
            // Gebäude. Sie dürfen weder das Saisonmittel noch die Regression
            // prägen, sonst prognostiziert der Tracker den Zustand vor der
            // Maßnahme weiter.
            if (!empty($m['pre_baseline'])) continue;
            $days = (int)($m['days'] ?? 0);
            if ($days < $minDays) continue;
            $rate = (float)($m[$valueField] ?? 0) / $days;
            $allRates[] = $rate;
            if ($hgt && ($m['hdd'] ?? 0) > 0) {
                $seasonalHdd[(int)$m['month']][] = (float)$m['hdd'];
            }
            // v3.7.0 (#37) — das Saisonprofil aus gemessenen Monaten: Ein verteilter
            // Januar hat die Tagesrate eines ganzen Winters. Nur wo ein Kalendermonat
            // nie gemessen wurde und kein Modell hilft, zählt die Verteilung — sie
            // ist dann immer noch besser als das Mittel des ganzen Jahres (ein
            // Gartenzähler, der im Winter nicht abgelesen wird). Das Tagesmittel
            // aller Monate stimmt auch für verteilte.
            if (!empty($m['interpolated'])) { $interpRates[(int)$m['month']][] = $rate; continue; }
            $rates[(int)$m['month']][] = $rate;
        }
        // v3.7.0 (wirklichkeit-2, Schritt 3, N1) — ohne Wetterbezug ab drei Werten der
        // Median (ConsumptionService::seasonalRate); bei Heizarten bleibt es das Mittel
        $avgRate = array_map(fn($arr) => ConsumptionService::seasonalRate($arr, !$hgt), $rates);
        $avgInterp = array_map(fn($arr) => count($arr) ? array_sum($arr) / count($arr) : null, $interpRates);
        $overallRate = $allRates ? array_sum($allRates) / count($allRates) : 0.0;
        $avgHdd = array_map(fn($arr) => count($arr) ? array_sum($arr) / count($arr) : null, $seasonalHdd);

        // Regression fit (only for HGT-relevant) — v2.8.0: dieselbe
        // Punktauswahl wie Analyse und Bereinigung (eine Stelle).
        $reg = null;
        $regXMin = null;
        if ($hgt) {
            $pts = $this->consumption->regressionPoints($monthly, $utility);
            $model = (string)($opts['model'] ?? $this->settings->get('forecast_model', 'linear'));
            $reg = $this->regression->fit($model, $pts['x'], $pts['y'], $this->settings);
            // v3.7.0 (wirklichkeit-2) — die Heizkurve kennt nur Monate mit Heizgradtagen
            // über min_hdd_regression; darunter (Sommer) ist sie geraten
            $regXMin = $pts['x'] !== [] ? (float)min($pts['x']) : null;
        }
        // v3.7.0 (#37) — mit dem Zähler: zu wenig gemessene Monate → über die Ablesungsabstände
        $heat = $hgt ? $this->consumption->heatModel($utility, $monthly, $meter) : null;
        // v3.7.0 (wirklichkeit-2) — Band und Heizanteil nur bei bestandener Gütesperre:
        // Bei echten Daten traf das 80-%-Band in keinem von fünf Rückblicken, weil das
        // Modell selbst danebenlag; ein Band um ein schlechtes Modell täuscht Sicherheit vor
        $quality = $hgt ? $this->consumption->anomalyQuality($utility, $monthly) : null;
        $poorFit = ($quality['model_quality'] ?? null) === 'poor';
        // v3.7.0 (wirklichkeit-2, Schritt 3, N3) — das Heizmodell steht nur auf den
        // Ablesungsabständen (oder fehlt), oder die Gütesperre kann es nicht prüfen:
        // Ein Band behauptete dann eine Sicherheit, die niemand geprüft hat
        $fewMeasured = $hgt && !Utilities::isDelivery($utility) && (($heat['basis'] ?? null) !== 'months' || $quality === null);

        // Fallback working price — the last month that carries one. Used only
        // when the active contract has no working price pflegt for a forecast
        // month (or there is no contract at all).
        $fallbackPrice = null;
        for ($i = count($monthly) - 1; $i >= 0; $i--) {
            if (!empty($monthly[$i]['working_price_ct'])) {
                $fallbackPrice = (float)$monthly[$i]['working_price_ct'];
                break;
            }
            if (!empty($monthly[$i]['price_cents'])) {
                $fallbackPrice = (float)$monthly[$i]['price_cents'];
                break;
            }
        }
        if ($fallbackPrice === null) {
            foreach ($monthly as $m) {
                $costPerUnit = ($m[$valueField] ?? 0) > 0
                    ? ($m['kwh_cost'] ?? $m['cost'] ?? 0) / $m[$valueField] * 100
                    : null;
                if ($costPerUnit !== null) $fallbackPrice = $costPerUnit;
            }
        }
        $fallbackPrice = ($fallbackPrice ?? 0.0) * $priceFactor;

        // Contracts of this meter — for the per-month price/advance lookup.
        // v2.2.0 — Schattenverträge sind reine Was-wäre-wenn-Hypothesen und
        // gehören ausschließlich in den Tarifvergleich (gleiche Filterung wie
        // ConsumptionService::applyContracts/contractStatus). Ohne diesen Filter
        // übernahm ein Schattenvertrag die Preis-/Abschlagsprojektion, sobald der
        // letzte echte Vertrag vor dem Prognosehorizont endete — die Prognose
        // rechnete dann still mit einem Tarif, den es nicht gibt.
        // v3.1.0 (H6) — HT/NT einer Gruppe als Mischpreis
        $contracts = array_values(array_map(fn($c) => $this->consumption->contractView($utility, $meter, $c), array_filter(
            $this->contracts->list($utility, (string)($meter['id'] ?? '')),
            fn($c) => empty($c['is_shadow'])
        )));

        // Unsicherheit (v2.8.0): Gewicht der Wetterstreuung = Verbrauch je
        // Gradtag, dazu das Rauschen des Modells.
        $z = (float)$this->settings->get('confidence_band_sigma', 1.28);
        $slope = $heat['a'] ?? (($reg['model'] ?? '') === 'linear' ? (float)($reg['a'] ?? 0) : null);
        $residSd = $heat['resid_sd'] ?? null;
        $fewYears = false;
        $ownSd = [];
        if (!$hgt) {
            // v3.7.0 (wirklichkeit-2, Schritt 3, N1) — gepoolte Streuung je Kalendermonat
            // über die Jahre: Freiheitsgrade Σ(n − 1), nicht (Anzahl − 1). Mit einem Wert
            // je Monat ist jede Abweichung null — das Band hatte bei Wasser die Breite null,
            // bei Strom mit zwei Jahren ±1 bis 2 %. Unter BAND_MIN_DOF kein Band (`few_years`).
            // Dazu die eigene Streuung jedes Kalendermonats: Ein Monat, dessen Jahre sich
            // widersprechen (Urlaub, halber Wert), trägt diesen Widerspruch in sein Band —
            // gepoolt verschwände ein einzelner Ausreißer in den anderen elf Monaten.
            $sq = 0.0; $dof = 0;
            foreach ($rates as $mo => $list) {
                $n = count($list);
                if ($n < 2) continue;
                $mean = array_sum($list) / $n;
                $own = 0.0;
                foreach ($list as $r) $own += ($r - $mean) ** 2;
                $sq += $own;
                $dof += $n - 1;
                $ownSd[$mo] = sqrt($own / ($n - 1));
            }
            $fewYears = $dof < self::BAND_MIN_DOF;
            $residSd = $fewYears ? null : sqrt($sq / $dof);   // je Tag
        }

        // Build forecast months
        $start = new \DateTime((end($monthly)['ym'] ?? date('Y-m')) . '-01');
        $start->modify('first day of next month');
        $blendW = $reg && ($reg['valid'] ?? false) ? min($blendMax, (float)$reg['r2']) : 0.0;

        $forecast = [];
        $runningBalance = 0.0;
        $hddSource = null;
        $missing = [];
        $weatherVar = 0.0; $noiseVar = 0.0; $annualValue = 0.0; $bandMonths = 0; $bandOk = true;
        for ($i = 0; $i < $fcMonths; $i++) {
            $ym = $start->format('Y-m');
            $mn = (int)$start->format('n');
            $yr = (int)$start->format('Y');
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $mn, 1, $yr));

            // Heizgradtage des Prognosemonats
            $hdd = null; $hddSd = null;
            if ($hgt) {
                $normal = $this->consumption->hddNormal($mn, $hddBase - $tempOffset);
                if ($normal !== null) {
                    $hdd = $normal['mean'];
                    $hddSd = $normal['sd'];
                    $hddSource ??= $normal['source'];
                } elseif ($avgHdd[$mn] !== null) {
                    // alter Weg: Mittel der Verbrauchsmonate, Versatz linear
                    $hdd = max(0.0, $avgHdd[$mn] - $tempOffset * $daysInMonth);
                    $hddSource ??= 'consumption_months';
                }
            }

            $seasonalVal = $avgRate[$mn] !== null ? $avgRate[$mn] * $daysInMonth : null;
            // v3.7.0 (#37) — „fehlt“ heißt: kein gemessener Wert (verteilte füllen nur auf)
            if ($seasonalVal === null) $missing[] = $mn;
            $regressionVal = ($reg && ($reg['valid'] ?? false) && $hdd !== null)
                ? $this->regression->predict($reg, $hdd) : null;
            // v3.7.0 (wirklichkeit-2, Punkt 8) — mit gemessenem Saisonwert keine Heizkurve
            //   unter ihren Punkten: Im Sommer lag die Gerade bei einer konvexen
            //     Heizkurve auf null (Achsenabschnitt negativ), mit Gewicht 0,8 fehlte so
            //     fast das ganze Warmwasser — bei echten Daten rund 23 % im Jahr, auch
            //     in der Menge fürs Vergleichsportal
            //   bei nicht bestandener Gütesperre: Der gemessene Monat ist näher als das Modell
            if ($seasonalVal !== null && $regressionVal !== null && ($poorFit || ($regXMin !== null && $hdd < $regXMin))) {
                $regressionVal = null;
            }
            $heatVal = ($heat !== null && $hdd !== null)
                ? $heat['a'] * $hdd + $heat['c'] * $daysInMonth : null;

            if ($seasonalVal !== null && $regressionVal !== null) {
                $blended = $blendW * $regressionVal + (1 - $blendW) * $seasonalVal;
                $method = sprintf('blend(reg=%.2f, seasonal=%.2f)', $blendW, 1 - $blendW);
            } elseif ($seasonalVal !== null) {
                $blended = $seasonalVal;
                $method = 'seasonal_only';
            } elseif ($regressionVal !== null) {
                // Kalendermonat ohne eigene Historie: das Modell allein
                $blended = $regressionVal;
                $method = 'regression_only';
            } elseif ($heatVal !== null) {
                $blended = $heatVal;
                $method = 'heat_model';
            } elseif ($avgInterp[$mn] !== null) {
                // v3.7.0 (#37) — nur verteilte Werte für diesen Kalendermonat
                $blended = $avgInterp[$mn] * $daysInMonth;
                $method = 'seasonal_interpolated';
            } else {
                // Ohne Wetterbezug oder ohne Modell: Tagesmittel aller Monate
                $blended = $overallRate * $daysInMonth;
                $method = 'filled';
            }

            // Band je Monat
            $bandLow = $bandHigh = null;
            $wSd = ($slope !== null && $hddSd !== null) ? $slope * $hddSd : null;
            // v3.7.0 (Schritt 3, N1) — ohne Heizmodell mindestens die Streuung, unter der auch
            // die Anomalie-Erkennung nichts meldet (10 % des Monats, AnomalyService::noiseFloor):
            // Ein Monat schwankt im Alltag um einige Prozent, auch wenn zwei Jahre gleich waren
            $nSd = $residSd !== null ? ($hgt ? $residSd
                : max(max($residSd, $ownSd[$mn] ?? 0.0) * $daysInMonth, AnomalyService::noiseFloor($blended, 0.0))) : null;
            if (!$poorFit && !$fewMeasured && ($wSd !== null || $nSd !== null)) {
                $half = $z * sqrt(($wSd ?? 0.0) ** 2 + ($nSd ?? 0.0) ** 2);
                $bandLow = round(max(0.0, $blended - $half), 1);
                $bandHigh = round($blended + $half, 1);
            }
            if ($i < 12) {
                $annualValue += $blended;
                $bandMonths++;
                if ($wSd === null && $nSd === null) $bandOk = false;
                $weatherVar += ($wSd ?? 0.0) ** 2;
                $noiseVar += ($nSd ?? 0.0) ** 2;
            }

            // F-02: full contract-aware monthly finance projection.
            $fin = $this->projectMonthFinances(
                $utility, $contracts, $yr, $mn, $blended, $fallbackPrice, $priceFactor, $sewageShare[$mn] ?? 1.0
            );
            $runningBalance += $fin['cost'] - ($fin['advance'] ?? 0.0);
            // v3.6.0 (F1031) — die Kosten eines Monats sind linear in der Menge
            // (Arbeitspreis × Menge + Grundpreis − Boni, tagesgenau). Fester Teil
            // und Preis je Einheit lassen die Prognose-Werkstatt andere Mengen
            // mit genau dieser Rechnung bewerten, ohne zweite Formel.
            $fixed = $this->projectMonthFinances($utility, $contracts, $yr, $mn, 0.0, $fallbackPrice, $priceFactor, $sewageShare[$mn] ?? 1.0)['cost'];
            $unit  = $this->projectMonthFinances($utility, $contracts, $yr, $mn, 1.0, $fallbackPrice, $priceFactor, $sewageShare[$mn] ?? 1.0)['cost'] - $fixed;
            // Heizanteil des Monats aus dem Heizmodell (a × HGT gegen Grundlast c × Tage)
            $heatShare = ($heat !== null && $hdd !== null && $heatVal > 0) ? $heat['a'] * $hdd / $heatVal : null;

            $forecast[] = [
                'ym'                => $ym,
                'year'              => $yr,
                'month'             => $mn,
                $valueField         => round($blended, 1),
                'hdd_estimated'     => $hdd !== null ? round($hdd, 1) : 0.0,
                'band_low'          => $bandLow,
                'band_high'         => $bandHigh,
                'cost_estimated'    => round($fin['cost'], 2),
                'advance_estimated' => $fin['advance'] !== null ? round($fin['advance'], 2) : null,
                'balance_running'   => round($runningBalance, 2),
                'working_price_ct'  => $fin['working_price_ct'] !== null
                                       ? round($fin['working_price_ct'], 4) : null,
                'contract_id'       => $fin['contract_id'],
                'contract_assumed'  => $fin['assumed'],
                'method'            => $method,
                'heat_share'        => $heatShare !== null ? round($heatShare, 4) : null,   // v3.6.0 (F1031)
                'fixed_eur'         => round($fixed, 4),                                       // v3.6.0 (F1031)
                'unit_eur'          => round($unit, 6),                                        // v3.6.0 (F1031)
            ];
            $start->modify('first day of next month');
        }

        // Jahresband: Das Wetter eines Jahres wirkt auf alle Monate zugleich —
        // mit der Jahresstreuung aus dem Klimanormal statt der Monatssumme.
        $annual = null;
        // das Jahr ohne Band (v3.7.0, wirklichkeit-2): Gütesperre, zu wenig gemessen (Schritt 3,
        // N3) oder ohne Heizmodell keine Streuung über die Jahre (N1)
        $withheld = $poorFit ? 'poor_fit' : ($fewMeasured ? 'few_measured' : ($fewYears ? 'few_years' : null));
        if ($bandMonths === 12 && $withheld !== null) {
            $annual = ['value' => round($annualValue, 1), 'low' => null, 'high' => null, 'sigma' => $z, 'level_pct' => null, 'withheld' => $withheld];
        } elseif ($bandMonths === 12 && $bandOk) {
            $yearSd = ($hgt && $slope !== null) ? $this->consumption->hddNormalYearSd($hddBase - $tempOffset) : null;
            $weather = $yearSd !== null ? ($slope * $yearSd) ** 2 : $weatherVar;
            $half = $z * sqrt($weather + $noiseVar);
            $annual = [
                'value'     => round($annualValue, 1),
                'low'       => round(max(0.0, $annualValue - $half), 1),
                'high'      => round($annualValue + $half, 1),
                'sigma'     => $z,
                'level_pct' => (int)round((2 * self::normalCdf($z) - 1) * 100),
            ];
        }

        // Hinweise (Lektion 33: nicht stumm)
        $warnings = [];
        // v3.7.0 (#37) — „Verbrauch für N Kalendermonate“ zählt gemessene Kalendermonate:
        // Ohne sie lag die Prognose bei echten Daten ohne Warnung weit daneben
        $covered = count(array_filter($avgRate, fn($v) => $v !== null));
        if ($covered < 12) {
            $warnings[] = [
                'code'    => 'history_short',
                'months'  => $covered,
                'missing' => array_values(array_unique($missing)),
            ];
        }
        if ($hgt && $hddSource !== 'climate_normal') {
            // v3.7.0 (wirklichkeit-2) — es gibt ein Normal, aber nicht für diese Heizgrenze
            // (gespeichert vor dem Raster ab 6 °C, oder außerhalb des Rasters): sagen, statt
            // still mit der nächsten Rastergrenze zu rechnen
            $climate = $this->consumption->climate();
            $warnings[] = $climate->summary() !== null && !$climate->covers($hddBase - $tempOffset)
                ? ['code' => 'climate_normal_grid', 'base' => round($hddBase - $tempOffset, 1), 'min' => ClimateNormalService::BASE_MIN,
                   'max' => ClimateNormalService::BASE_MAX, 'hdd_source' => $hddSource]
                : ['code' => 'no_climate_normal', 'hdd_source' => $hddSource];
        }
        // v3.7.0 (#37) — verteilte Monate prägen weder Saisonprofil noch Heizkurve;
        // die Prognose sagt, worauf sie stattdessen beruht (Lektion 33: nicht stumm)
        $interp = count(array_filter($monthly, fn($m) => !empty($m['interpolated']) && empty($m['pre_baseline'])));
        if ($interp > 0) {
            $info = $this->consumption->interpolationInfo($utility, $meter, $monthly);
            $warnings[] = ['code' => 'interpolated', 'months' => $interp, 'readings' => $info['readings'] ?? null,
                'heat_basis' => $heat['basis'] ?? null];
        }
        // v3.7.0 (wirklichkeit-2) — Gütesperre: kein Band, Heizanteil unsicher
        if ($poorFit) {
            $warnings[] = ['code' => 'poor_fit', 'ratio' => $quality['ratio'], 'reasons' => $quality['reasons']];
        }
        // v3.7.0 (Schritt 3, N3) — zu wenig gemessen, um das Heizmodell zu prüfen: kein Band
        if ($fewMeasured) {
            $warnings[] = ['code' => 'few_measured', 'heat_basis' => $heat['basis'] ?? null];
        }
        // v3.7.0 (wirklichkeit-2) — die gewählte Heizkurve passt nicht (zu wenig Punkte,
        // R² zu klein, entartet): Die Prognose rechnet dann ohne sie weiter und sagt es
        if ($reg !== null && empty($reg['valid'])) {
            $warnings[] = ['code' => 'regression_invalid', 'model' => (string)($reg['model'] ?? ''), 'reason' => (string)($reg['reason'] ?? 'too_few_points'),
                'n' => (int)($reg['n'] ?? 0), 'r2' => isset($reg['r2']) ? (float)$reg['r2'] : null];
        }

        // v3.1.0 (H4, MKT-26) — CO₂-Preis-Szenario: Mehrkosten ab einem Jahr, wenn der
        // Preis höher läge. Nur Ausweis — cost_estimated bleibt unverändert.
        $co2Scenario = null;
        $scenario = $opts['co2_scenario_eur_t'] ?? $this->settings->get('co2_price_scenario_eur_t');
        if ($scenario !== null && $scenario !== '' && is_numeric($scenario)) {
            $from = (int)($opts['co2_scenario_from'] ?? $this->settings->get('co2_price_scenario_from', 2028));
            $sum12 = 0.0; $deltaCt = null;
            foreach ($forecast as $i => &$f) {
                if ((int)$f['year'] < $from) continue;
                $ct = Co2CostService::scenarioDeltaCtPerKwh($this->settings, $utility, (float)$scenario, (int)$f['year']);
                if ($ct === null) break;
                $deltaCt ??= $ct;
                $f['co2_delta_eur'] = round((float)($f['kwh'] ?? 0) * $ct / 100, 2);
                if ($i < 12) $sum12 += $f['co2_delta_eur'];
            }
            unset($f);
            if ($deltaCt !== null || Co2CostService::factorFor($this->settings, $utility) !== null) {
                $co2Scenario = ['eur_t' => (float)$scenario, 'from' => $from,
                    'delta_ct_per_kwh' => $deltaCt !== null ? round($deltaCt, 3) : null, 'delta_cost_12m_eur' => round($sum12, 2)];
            }
        }

        return [
            'valid'        => true,
            'utility'      => $u['key'],
            'meter_id'     => $meter['id'],
            'historical'   => $monthly,
            'forecast'     => $forecast,
            'regression'   => $reg,
            'blend_weight' => round($blendW, 4),
            'last_price_ct'=> round($fallbackPrice, 4),
            'hdd_source'   => $hddSource,
            'annual'       => $annual,
            'warnings'     => $warnings,
            'climate_normal' => $this->consumption->climate()->summary(),
            'co2_scenario' => $co2Scenario,
            // v3.6.0 (F1031) — Heizmodell Verbrauch = a × HGT + c × Tage (oder null)
            // v3.7.0 (#37) — `basis`: months | intervals (Ablesungsabstände)
            'heat_model'   => $heat !== null ? ['a' => round($heat['a'], 6), 'c' => round($heat['c'], 6), 'n' => $heat['n'], 'basis' => $heat['basis'] ?? 'months'] : null,
            'hdd_base'     => $hgt ? $hddBase : null,
            // v3.7.0 (wirklichkeit-2) — Gütesperre des Heizmodells (additiv)
            'fit_quality'  => $quality !== null ? ['model_quality' => $quality['model_quality'], 'ratio' => $quality['ratio'], 'reasons' => $quality['reasons']] : null,
            'options'      => [
                'temp_offset'   => $tempOffset,
                'price_factor'  => $priceFactor,
                'forecast_months' => $fcMonths,
            ],
        ];
    }

    /**
     * Project the full monthly finances for one forecast month.
     *
     * Resolves the contract active in (year, month) and looks up the price /
     * base / advance valid for that month. Returns:
     *   cost             — Arbeitspreis × Menge + Grundpreis − bekannte Boni
     *   advance          — der gültige Abschlag, oder null wenn keiner pflegt
     *   working_price_ct — der angesetzte Arbeitspreis (Headline-Tarif)
     *   contract_id      — id des aktiven Vertrags, oder null
     *   assumed          — v2.8.0: kein Vertrag für den Monat, der letzte
     *                      läuft als Annahme weiter
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{cost:float,advance:?float,working_price_ct:?float,contract_id:?string,assumed:bool}
     */
    private function projectMonthFinances(
        string $utility,
        array $contracts,
        int $year,
        int $month,
        float $volume,
        float $fallbackPriceCt,
        float $priceFactor,
        float $sewageShare = 1.0
    ): array {
        $first = sprintf('%04d-%02d-01', $year, $month);
        if ($utility !== 'wasser') {
            return $this->projectStandardMonth($contracts, $year, $month, $volume, $fallbackPriceCt, $priceFactor);
        }
        // v2.8.0 (CALC-12) — ohne Folgevertrag läuft der letzte weiter, zu
        // seinen letzten Preisen. Bisher fielen Grundpreis und Abschlag weg.
        // v2.9.0 — nur, wenn er sich verlängert (`auto_renews`).
        $r = $this->contracts->resolveForDate($contracts, $first);
        $c = $r['contract'] ?? null;
        $assumed = $r['assumed'] ?? false;
        $asOfYear = $year; $asOfMonth = $month;
        if ($assumed) {
            $asOfYear = (int)substr((string)$c['end'], 0, 4);
            $asOfMonth = (int)substr((string)$c['end'], 5, 2);
        }

        if (!$c) {
            // No contract — fall back to the last known unit price.
            return [
                'cost'             => $volume * $fallbackPriceCt / 100.0,
                'advance'          => null,
                'working_price_ct' => $fallbackPriceCt,
                'contract_id'      => null,
                'assumed'          => false,
            ];
        }

        $bonus = $assumed ? 0.0 : $this->contracts->bonusForMonth($c, $year, $month);
        // v2.8.0 (CALC-12) — der effektive Abschlagsplan: Eine Sonderzahlung
        // „mit Auswirkung" ändert den Abschlag ab ihrem Datum.
        $advancePlan = $this->contracts->effectiveAdvanceSchedule($c);

        // Wasser: monatlich (Preise der Stadtwerke wechseln zum Jahresbeginn)
        $tw = $c['trinkwasser']         ?? [];
        $sw = $c['schmutzwasser']       ?? [];
        $nw = $c['niederschlagswasser'] ?? [];

        $twWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $asOfYear, $asOfMonth);
        $twBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $asOfYear, $asOfMonth);
        $swWp = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $asOfYear, $asOfMonth);
        // Niederschlagswasser rates carry two fields per entry; valueValidOn
        // walks the same sorted list for each, so both resolve to the same
        // stichtag entry.
        $nwRate = $this->contracts->valueValidOn($nw['rates'] ?? [], 'eur_per_m2_year', $asOfYear, $asOfMonth);
        $nwArea = $this->contracts->valueValidOn($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $asOfYear, $asOfMonth);

        $twPrice   = $twWp !== null ? (float)$twWp * $priceFactor : $fallbackPriceCt;
        $twWorking = $volume * $twPrice / 100.0;
        $twBase    = $twBp !== null ? (float)$twBp : 0.0;
        // v3.1.0 — Schmutzwasser = Trinkwasser × Anteil desselben Kalendermonats
        // in der Historie (s. sewageShareByMonth); bis v3.0 das volle Volumen.
        $swCost = $swWp !== null ? $volume * $sewageShare * (float)$swWp * $priceFactor / 100.0 : 0.0;
        // v3.9.0 (F1034) — Grundpreis Schmutzwasser, wie der des Trinkwassers je Monat
        $swBp = $this->contracts->valueValidOn($sw['base_prices'] ?? [], 'eur_per_month', $asOfYear, $asOfMonth);
        if ($swBp !== null) $swCost += (float)$swBp;
        $nwMonthly = ($nwRate !== null && $nwArea !== null)
            ? (float)$nwRate * (float)$nwArea / 12.0
            : 0.0;

        $cost = $twWorking + $twBase + $swCost + $nwMonthly - $bonus;
        // v3.9.0 (F1034) — mit Rhythmus: die Raten, die in diesem Monat fällig sind; monatlich wie
        // bisher der Betrag des Monats (beim weiterlaufenden Vertrag der seines letzten Monats)
        $next = date('Y-m-d', (int)strtotime($first . ' +1 month'));
        $ap   = ContractService::advanceIn($advancePlan, sprintf('%04d-%02d-01', $asOfYear, $asOfMonth), $first, $next, 1.0,
            (int)date('t', (int)strtotime($first)));

        return [
            'cost'             => $cost,
            'advance'          => $ap !== null ? (float)$ap : null,
            'working_price_ct' => $twWp !== null ? $twPrice : null,
            'contract_id'      => $c['id'] ?? null,
            'assumed'          => $assumed,
        ];
    }

    /**
     * v2.9.0 (Review CALC-10) — Finanzen eines Prognosemonats für Gas, Strom,
     * Fernwärme und Einspeisung, tagesgenau wie die Rechnung: Der Monat wird an
     * Vertrags- und Preisstichtagen geteilt, die Menge verteilt sich
     * gleichmäßig auf die Tage, Grundpreis tagesanteilig, Abschlag als
     * Monatsbetrag anteilig nach Vertragstagen. Ein beendeter Vertrag ohne
     * Nachfolger läuft als Annahme weiter, wenn er sich verlängert.
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{cost:float,advance:?float,working_price_ct:?float,contract_id:?string,assumed:bool}
     */
    private function projectStandardMonth(array $contracts, int $year, int $month, float $volume, float $fallbackPriceCt, float $priceFactor): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $nextMonth  = date('Y-m-d', strtotime($monthStart . ' +1 month'));
        $dim        = (int)date('t', strtotime($monthStart));

        $cost = 0.0; $advance = null; $energyCost = 0.0; $energyVol = 0.0;
        $byContract = [];   // id → [days, assumed, contract, first]
        foreach ($this->contracts->segmentsBetween($contracts, $monthStart, $nextMonth) as $seg) {
            $vol = $volume * $seg['days'] / $dim;
            $c = $seg['contract'];
            if ($c === null) {
                $cost += $vol * $fallbackPriceCt / 100.0;
                continue;
            }
            $date = ContractService::priceDate($seg);
            $wp = $this->contracts->valueOnDate($c['working_prices'] ?? [], 'ct_per_kwh', $date);
            $bp = $this->contracts->fixedPerMonthOn($c, $date);
            $price = $wp !== null ? $wp * $priceFactor : $fallbackPriceCt;
            $cost += $vol * $price / 100.0 + ($bp !== null ? $bp * $seg['days'] / $dim : 0.0);
            $energyCost += $vol * $price; $energyVol += $vol;
            $byContract[$c['id']] ??= ['days' => 0, 'assumed' => false, 'contract' => $c, 'first' => $date, 'from' => $seg['from']];
            $byContract[$c['id']]['days'] += $seg['days'];
            $byContract[$c['id']]['to'] = $seg['to'];   // v3.9.0 (F1034) — Vertragstage im Monat, für die Raten
            $byContract[$c['id']]['assumed'] = $byContract[$c['id']]['assumed'] || $seg['assumed'];
        }
        if ($byContract === []) {
            // Kein Vertrag — letzter bekannter Einheitspreis
            return ['cost' => $cost, 'advance' => null, 'working_price_ct' => $fallbackPriceCt,
                    'contract_id' => null, 'assumed' => false];
        }
        foreach ($byContract as $b) {
            $c = $b['contract'];
            // Boni nur in der eigentlichen Laufzeit; der effektive
            // Abschlagsplan berücksichtigt „mit Auswirkung" (v2.8.0)
            if (!$b['assumed']) $cost -= $this->contracts->bonusForMonth($c, $year, $month);
            // v3.9.0 (F1034) — mit Rhythmus die Raten, die in den Vertragstagen des Monats fällig sind
            $ap = ContractService::advanceIn($this->contracts->effectiveAdvanceSchedule($c), $b['first'], $b['from'], $b['to'], min(1.0, $b['days'] / $dim), $dim);
            if ($ap !== null) $advance = ($advance ?? 0.0) + $ap;
        }
        uasort($byContract, fn($x, $z) => [$z['days'], (string)($z['contract']['start'] ?? '')] <=> [$x['days'], (string)($x['contract']['start'] ?? '')]);
        $main = $byContract[array_key_first($byContract)];
        return [
            'cost'             => $cost,
            'advance'          => $advance,
            'working_price_ct' => $energyVol > 0 ? $energyCost / $energyVol : $fallbackPriceCt,
            'contract_id'      => (string)array_key_first($byContract),
            'assumed'          => $main['assumed'],
        ];
    }

    /** Standardnormalverteilung (Abramowitz/Stegun 26.2.17, Fehler < 7,5·10⁻⁸). */
    private static function normalCdf(float $x): float
    {
        $t = 1.0 / (1.0 + 0.2316419 * abs($x));
        $d = 0.3989422804014327 * exp(-$x * $x / 2);
        $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
        return $x >= 0 ? 1.0 - $p : $p;
    }

    /**
     * v3.1.0 (Review CALC-20) — Anteil des Schmutzwassers am Trinkwasser je
     * Kalendermonat, aus den gerechneten Monaten mit Vertrag: Σ Schmutzwasser /
     * Σ Trinkwasser. Monate ohne Daten fehlen (Aufrufer nimmt dann 1).
     *
     * @param array<int,array<string,mixed>> $monthly Zeilen aus ConsumptionService::forMeter
     * @return array<int,float> Monat (1–12) → Anteil 0…1
     */
    public static function sewageShareByMonth(array $monthly): array
    {
        $tw = $sw = [];
        foreach ($monthly as $m) {
            $t = $m['trinkwasser']['m3'] ?? null;
            $s = $m['schmutzwasser']['m3'] ?? null;
            if ($t === null || $s === null || (float)$t <= 0) continue;
            $mn = (int)$m['month'];
            $tw[$mn] = ($tw[$mn] ?? 0.0) + (float)$t;
            $sw[$mn] = ($sw[$mn] ?? 0.0) + (float)$s;
        }
        $out = [];
        foreach ($tw as $mn => $sum) {
            $out[$mn] = round(min(1.0, max(0.0, $sw[$mn] / $sum)), 4);
        }
        return $out;
    }
}
