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
 * Limitation: for Wasser with a Schmutzwasser `separater_zaehler` basis the
 * forecast uses the Trinkwasser volume as the Schmutzwasser basis (the
 * separate meter is not itself forecast). The historical view computes the
 * separate volume correctly; only the forward projection simplifies.
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
    public function __construct(
        private ConsumptionService $consumption,
        private RegressionService $regression,
        private SettingsService $settings,
        private ContractService $contracts,
        private I18nService $i18n,
    ) {}

    public function forMeter(string $utility, array $meter, array $opts = []): array
    {
        $u = Utilities::get($utility);
        $monthly = $this->consumption->forMeter($utility, $meter);
        if (count($monthly) < 6) {
            return ['valid' => false, 'reason' => $this->i18n->t('errors.forecast.tooFewMonths')];
        }

        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $fcMonths   = (int)($opts['forecast_months'] ?? $this->settings->get('forecast_months', 12));
        $minDays    = (int)$this->settings->get('min_days_period', 20);
        $blendMax   = (float)$this->settings->get('blend_max', 0.80);
        $tempOffset = (float)($opts['temp_offset'] ?? 0.0);
        $priceFactor= (float)($opts['price_factor'] ?? 1.0);
        $hgt        = !empty($u['hgt_relevant']);
        $hddBase    = (float)$this->settings->get('hdd_base_temp', 15.0);

        // Saisonprofil je Kalendermonat als Tagesrate (Teilmonate zählen
        // nicht, 28 und 31 Tage werden vergleichbar).
        $rates = array_fill(1, 12, []);
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
            $rates[(int)$m['month']][] = $rate;
            $allRates[] = $rate;
            if ($hgt && ($m['hdd'] ?? 0) > 0) {
                $seasonalHdd[(int)$m['month']][] = (float)$m['hdd'];
            }
        }
        $avgRate = array_map(fn($arr) => count($arr) ? array_sum($arr) / count($arr) : null, $rates);
        $overallRate = $allRates ? array_sum($allRates) / count($allRates) : 0.0;
        $avgHdd = array_map(fn($arr) => count($arr) ? array_sum($arr) / count($arr) : null, $seasonalHdd);

        // Regression fit (only for HGT-relevant) — v2.8.0: dieselbe
        // Punktauswahl wie Analyse und Bereinigung (eine Stelle).
        $reg = null;
        if ($hgt) {
            $pts = $this->consumption->regressionPoints($monthly, $utility);
            $model = (string)($opts['model'] ?? $this->settings->get('forecast_model', 'linear'));
            $reg = $this->regression->fit($model, $pts['x'], $pts['y'], $this->settings);
        }
        $heat = $hgt ? $this->consumption->heatModel($utility, $monthly) : null;

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
        $contracts = array_values(array_filter(
            $this->contracts->list($utility, (string)($meter['id'] ?? '')),
            fn($c) => empty($c['is_shadow'])
        ));

        // Unsicherheit (v2.8.0): Gewicht der Wetterstreuung = Verbrauch je
        // Gradtag, dazu das Rauschen des Modells.
        $z = (float)$this->settings->get('confidence_band_sigma', 1.28);
        $slope = $heat['a'] ?? (($reg['model'] ?? '') === 'linear' ? (float)($reg['a'] ?? 0) : null);
        $residSd = $heat['resid_sd'] ?? null;
        if (!$hgt) {
            $sq = [];
            foreach ($monthly as $m) {
                if (!empty($m['pre_baseline']) || (int)($m['days'] ?? 0) < $minDays) continue;
                $mean = $avgRate[(int)$m['month']] ?? null;
                if ($mean === null) continue;
                $sq[] = ((float)($m[$valueField] ?? 0) / (int)$m['days'] - $mean) ** 2;
            }
            $residSd = count($sq) > 1 ? sqrt(array_sum($sq) / (count($sq) - 1)) : null;   // je Tag
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
            if ($seasonalVal === null) $missing[] = $mn;
            $regressionVal = ($reg && ($reg['valid'] ?? false) && $hdd !== null)
                ? $this->regression->predict($reg, $hdd) : null;
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
            } else {
                // Ohne Wetterbezug oder ohne Modell: Tagesmittel aller Monate
                $blended = $overallRate * $daysInMonth;
                $method = 'filled';
            }

            // Band je Monat
            $bandLow = $bandHigh = null;
            $wSd = ($slope !== null && $hddSd !== null) ? $slope * $hddSd : null;
            $nSd = $residSd !== null ? ($hgt ? $residSd : $residSd * $daysInMonth) : null;
            if ($wSd !== null || $nSd !== null) {
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
                $utility, $contracts, $yr, $mn, $blended, $fallbackPrice, $priceFactor
            );
            $runningBalance += $fin['cost'] - ($fin['advance'] ?? 0.0);

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
            ];
            $start->modify('first day of next month');
        }

        // Jahresband: Das Wetter eines Jahres wirkt auf alle Monate zugleich —
        // mit der Jahresstreuung aus dem Klimanormal statt der Monatssumme.
        $annual = null;
        if ($bandMonths === 12 && $bandOk) {
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
        $covered = count(array_filter($avgRate, fn($v) => $v !== null));
        if ($covered < 12) {
            $warnings[] = [
                'code'    => 'history_short',
                'months'  => $covered,
                'missing' => array_values(array_unique($missing)),
            ];
        }
        if ($hgt && $hddSource !== 'climate_normal') {
            $warnings[] = ['code' => 'no_climate_normal', 'hdd_source' => $hddSource];
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
        float $priceFactor
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
        // See class docblock: the separate-meter volume is not forecast;
        // the Trinkwasser volume is used as the Schmutzwasser basis here.
        $swCost = $swWp !== null ? $volume * (float)$swWp * $priceFactor / 100.0 : 0.0;
        $nwMonthly = ($nwRate !== null && $nwArea !== null)
            ? (float)$nwRate * (float)$nwArea / 12.0
            : 0.0;

        $cost = $twWorking + $twBase + $swCost + $nwMonthly - $bonus;
        $ap   = $this->contracts->valueValidOn($advancePlan, 'amount_eur', $asOfYear, $asOfMonth);

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
            $bp = $this->contracts->valueOnDate($c['base_prices'] ?? [], 'eur_per_month', $date);
            $price = $wp !== null ? $wp * $priceFactor : $fallbackPriceCt;
            $cost += $vol * $price / 100.0 + ($bp !== null ? $bp * $seg['days'] / $dim : 0.0);
            $energyCost += $vol * $price; $energyVol += $vol;
            $byContract[$c['id']] ??= ['days' => 0, 'assumed' => false, 'contract' => $c, 'first' => $date];
            $byContract[$c['id']]['days'] += $seg['days'];
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
            $ap = $this->contracts->valueOnDate($this->contracts->effectiveAdvanceSchedule($c), 'amount_eur', $b['first']);
            if ($ap !== null) $advance = ($advance ?? 0.0) + $ap * min(1.0, $b['days'] / $dim);
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
}
