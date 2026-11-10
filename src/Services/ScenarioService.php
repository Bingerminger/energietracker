<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\Simplex;

/**
 * v3.6.0 (F1031) — Prognose-Werkstatt: „Was wäre, wenn …“ für einen Zähler.
 *
 * Rechnet zu einem Szenario den Ausgangsfall und das Szenario Monat für Monat
 * (Menge, Kosten, CO₂), die Jahressummen und die Differenz, den empfohlenen
 * Abschlag, den Saldo zur nächsten Abrechnung (F1030), die Rangliste der
 * Stellschrauben und — mit Budget — die beste Maßnahmenkombination. Liest
 * nur, schreibt nichts.
 *
 * ── Eine Quelle ─────────────────────────────────────────────────────────
 * Der Ausgangsfall ist die Prognose (ForecastService) mit allen Regeln, die
 * dort gelten: Klimanormal, Heizmodell, Zäsur, Verträge tagesgenau. Je
 * Prognosemonat liefert sie den Heizanteil (a × HGT gegen die Grundlast) und
 * die Kosten als lineare Funktion der Menge (fester Teil + Preis je Einheit).
 * Das Szenario ändert nur Mengen und — beim Tarif — Preise; die Kosten kommen
 * aus derselben Linearform. Der Browser rechnet nichts nach.
 *
 * ── Stellschrauben und was sie bewegen ──────────────────────────────────
 *   Winter (Gradtage ± %)  den heizabhängigen Teil, proportional (Heizmodell:
 *                          Verbrauch = a × HGT + c × Tage)
 *   Raumtemperatur ± K     die Heizgrenze um denselben Betrag — Heizgradtage
 *                          aus Klimanormal bzw. Temperaturhistorie bei der
 *                          verschobenen Grenze, mal a. Keine Faustregel: das
 *                          eigene Modell (wie der Temperaturversatz der
 *                          Prognose seit v2.8.0)
 *   Maßnahmen (% Heizung,  den heizabhängigen bzw. den Grundlast-Teil, je
 *   % Grundlast, Kosten)   Maßnahme multiplikativ — zwei Dämmungen sparen
 *                          zusammen weniger als die Summe
 *   sparen %               alles (Verhalten, Einsteiger-Regler)
 *   Grundlast ± %          Warmwasser, Kochen, Geräte
 *   E-Auto km              Strom: km × kWh/100 km, gleichmäßig je Tag
 *   JAZ alt → neu          Strom einer Wärmepumpe: Menge × JAZ alt / JAZ neu
 *   Tarif                  laufender Vertrag (Prognose), ein Angebot bzw.
 *                          Vertrag (Preisregel der Wechselentscheidung), ein
 *                          eigener Preis oder das günstigste Angebot
 *   Modell                 standard (Prognose, witterungsbereinigt mit
 *                          Klimanormal), last_year (die gemessenen Monate des
 *                          Vorjahrs), trend (Vorjahr × Veränderung zum Jahr
 *                          davor, höchstens ± 25 %)
 *
 * ── Optimierung ─────────────────────────────────────────────────────────
 * „Beste Kombination unter einem Budget“ ist ein Rucksackproblem mit
 * Wechselwirkungen (multiplikative Ersparnis). Bei höchstens zehn Maßnahmen
 * (≤ 1 024 Kombinationen) wird jede Kombination mit dem echten Modell
 * gerechnet — das Ergebnis ist exakt. Der Simplex-Löser rechnet daneben die
 * LP-Relaxierung über die Einzelersparnisse: eine obere Schranke und der
 * Schattenpreis des Budgets („jeder weitere Euro bringt … € im Jahr“).
 */
final class ScenarioService
{
    public const MODELS = ['standard', 'last_year', 'trend'];
    public const TARIFF_KINDS = ['current', 'contract', 'custom', 'cheapest'];
    public const REGRESSION_MODELS = ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'];
    public const MAX_MEASURES = 10;
    /** Trend höchstens ± 25 % — mehr ist keine Fortschreibung, sondern ein Ereignis. */
    public const TREND_CAP = 0.25;
    /** „Kalter Winter“: jeder zehnte Winter ist kälter (einseitig 1,28 σ). */
    public const COLD_Z = 1.2816;

    public function __construct(
        private ForecastService $forecast,
        private ConsumptionService $consumption,
        private ContractService $contracts,
        private MeterService $meters,
        private SettingsService $settings,
        private TariffSwitchService $tariffSwitch,
        private ?BillingPeriodService $billing = null,
        // v3.12.0 (F1040, Phase 2) — PV im Haushalt: Monatswerte (PvSummaryService) und, wenn es sie
        // gibt, Viertelstunden (IntervalService). Ohne die zweite rechnet die PV-Werkstatt als Spanne.
        private ?PvSummaryService $pvSummary = null,
        private ?IntervalService $intervals = null,
    ) {}

    /** Arten, für die die Werkstatt rechnet: mit Zählerständen und Verträgen, Lieferungen (v3.12.0) und PV (v3.12.0). */
    public static function supports(string $utility): bool
    {
        return self::calcOf($utility) !== null;
    }

    /**
     * v3.12.0 (F1040, Phase 2) — welche Rechnung eine Art bekommt:
     *   consumption  Zählerstände und Vertrag (Gas, Strom, Wasser, Fernwärme), wie bisher
     *   delivery     Lieferungen (Heizöl, Pellets): Prognose, Heizanteil aus dem Tankbuch, Lieferkosten
     *   pv           Einspeisung und Erzeugung: ein Modell des Haushalts über Bezug und Einspeisung
     * Null für Arten ohne Werkstatt (Heizwärme).
     */
    public static function calcOf(string $utility): ?string
    {
        if (!Utilities::exists($utility)) return null;
        if (Utilities::isDelivery($utility)) return 'delivery';
        if (Utilities::isFeedIn($utility) || Utilities::isGenerationOnly($utility)) return 'pv';
        return Utilities::hasContracts($utility) ? 'consumption' : null;
    }

    // ══ Eingaben ═══════════════════════════════════════════════════════════

    /**
     * Eingaben prüfen und mit Voreinstellungen auffüllen. Ungültiges → 400
     * (errors.forecast.paramInvalid mit Feld und erlaubtem Bereich).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function normalize(array $in): array
    {
        $bad = static function (string $field, mixed $value, string $range): never {
            $v = is_scalar($value) ? (string)$value : (is_array($value) ? '[…]' : '');
            throw new LocalizedException('errors.forecast.paramInvalid',
                ['param' => $field, 'value' => mb_substr($v, 0, 40), 'range' => $range], "scenario $field");
        };
        $num = static function (array $src, string $key, float $min, float $max, ?float $default, string $field) use ($bad): ?float {
            if (!array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') return $default;
            $v = $src[$key];
            if (is_bool($v) || !is_numeric($v)) $bad($field, $v, self::range($min, $max));
            $f = (float)$v;
            if (!is_finite($f) || $f < $min || $f > $max) $bad($field, $v, self::range($min, $max));
            return $f;
        };

        $utility = $in['utility'] ?? null;
        if (!is_string($utility) || !Utilities::exists($utility)) $bad('utility', $utility, implode(', ', Utilities::keys()));
        $meterId = $in['meter_id'] ?? null;
        if (!is_string($meterId) || $meterId === '' || strlen($meterId) > 80) $bad('meter_id', $meterId, 'id');
        $model = $in['model'] ?? 'standard';
        if (!is_string($model) || !in_array($model, self::MODELS, true)) $bad('model', $model, implode(', ', self::MODELS));
        $regression = $in['regression'] ?? null;
        if ($regression !== null && (!is_string($regression) || !in_array($regression, self::REGRESSION_MODELS, true))) {
            $bad('regression', $regression, implode(', ', self::REGRESSION_MODELS));
        }

        $out = [
            'utility'          => $utility,
            'meter_id'         => $meterId,
            'model'            => $model,
            'regression'       => $regression,
            'winter_pct'       => $num($in, 'winter_pct', -50, 50, 0.0, 'winter_pct'),
            'room_temp_delta'  => $num($in, 'room_temp_delta', -5, 5, 0.0, 'room_temp_delta'),
            'saving_pct'       => $num($in, 'saving_pct', 0, 80, 0.0, 'saving_pct'),
            'baseload_pct'     => $num($in, 'baseload_pct', -80, 100, 0.0, 'baseload_pct'),
            'ev_km'            => $num($in, 'ev_km', 0, 100000, 0.0, 'ev_km'),
            'ev_kwh_per_100km' => $num($in, 'ev_kwh_per_100km', 5, 60, null, 'ev_kwh_per_100km'),
            'jaz_from'         => $num($in, 'jaz_from', 1, 8, null, 'jaz_from'),
            'jaz_to'           => $num($in, 'jaz_to', 1, 8, null, 'jaz_to'),
            'budget_eur'       => $num($in, 'budget_eur', 0, 10000000, null, 'budget_eur'),
            // v3.12.0 (F1040, Phase 2) — Lieferungen: Preis je Liter bzw. kg ± %
            'price_pct'        => $num($in, 'price_pct', -50, 100, 0.0, 'price_pct'),
            // v3.12.0 (F1040, Phase 2) — PV: Anlage größer, Speicher dazu, Eigenverbrauch, Vergütung
            'pv_size_pct'      => $num($in, 'pv_size_pct', 0, 300, 0.0, 'pv_size_pct'),
            'pv_kwp_now'       => $num($in, 'pv_kwp_now', 0.1, 1000, null, 'pv_kwp_now'),
            'battery_kwh'      => $num($in, 'battery_kwh', 0, 100, 0.0, 'battery_kwh'),
            'self_use_pct'     => $num($in, 'self_use_pct', -50, 50, 0.0, 'self_use_pct'),
            'feed_in_ct'       => $num($in, 'feed_in_ct', 0, 100, null, 'feed_in_ct'),
        ];
        if ($out['ev_km'] > 0 && $out['ev_kwh_per_100km'] === null) $bad('ev_kwh_per_100km', '', self::range(5, 60));
        if (($out['jaz_from'] === null) !== ($out['jaz_to'] === null)) $bad($out['jaz_from'] === null ? 'jaz_from' : 'jaz_to', '', self::range(1, 8));

        // Maßnahmen
        $measures = $in['measures'] ?? [];
        if (!is_array($measures) || !array_is_list($measures) || count($measures) > self::MAX_MEASURES) {
            $bad('measures', $measures, '0–' . self::MAX_MEASURES);
        }
        $out['measures'] = [];
        $ids = [];
        foreach ($measures as $i => $m) {
            if (!is_array($m)) $bad("measures[$i]", $m, 'object');
            $id = $m['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || isset($ids[$id])) $bad("measures[$i].id", $id, 'A–Z a–z 0–9 _ -');
            $ids[$id] = true;
            $label = $m['label'] ?? '';
            if (!is_string($label) || mb_strlen($label) > 80) $bad("measures[$i].label", $label, '0–80');
            $kind = $m['kind'] ?? null;
            if ($kind !== null && (!is_string($kind) || !preg_match('/^[a-z_]{1,30}$/', $kind))) $bad("measures[$i].kind", $kind, 'a–z _');
            $active = $m['active'] ?? true;
            if (!is_bool($active)) $bad("measures[$i].active", $active, 'true, false');
            $out['measures'][] = [
                'id'       => $id,
                'kind'     => $kind,
                'label'    => trim($label),
                'heat_pct' => $num($m, 'heat_pct', 0, 90, 0.0, "measures[$i].heat_pct"),
                'base_pct' => $num($m, 'base_pct', 0, 90, 0.0, "measures[$i].base_pct"),
                'cost_eur' => $num($m, 'cost_eur', 0, 10000000, null, "measures[$i].cost_eur"),
                'active'   => $active,
            ];
        }

        // Tarif
        $tariff = $in['tariff'] ?? ['kind' => 'current'];
        if (!is_array($tariff)) $bad('tariff', $tariff, 'object');
        $kind = $tariff['kind'] ?? 'current';
        if (!is_string($kind) || !in_array($kind, self::TARIFF_KINDS, true)) $bad('tariff.kind', $kind, implode(', ', self::TARIFF_KINDS));
        $t = ['kind' => $kind];
        if ($kind === 'contract') {
            $cid = $tariff['contract_id'] ?? null;
            if (!is_string($cid) || $cid === '' || strlen($cid) > 80) $bad('tariff.contract_id', $cid, 'id');
            $t['contract_id'] = $cid;
        } elseif ($kind === 'custom') {
            $wp = $num($tariff, 'working_price_ct', 0, 500, null, 'tariff.working_price_ct');
            if ($wp === null) $bad('tariff.working_price_ct', '', self::range(0, 500));
            $t['working_price_ct'] = $wp;
            $t['base_eur_month'] = $num($tariff, 'base_eur_month', 0, 1000, 0.0, 'tariff.base_eur_month');
        }
        $out['tariff'] = $t;

        // v3.12.0 (F1040, Phase 2) — Heizungstausch bei Lieferungen: Wärmepumpe oder Pellets. Leere
        // Annahmen füllt die Rechnung mit genannten Beispielwerten (ScenarioDelivery::ASSUMED).
        $rep = $in['replace'] ?? null;
        $out['replace'] = null;
        if ($rep !== null) {
            if (!is_array($rep)) $bad('replace', $rep, 'object');
            $to = $rep['to'] ?? null;
            if (!is_string($to) || !in_array($to, ScenarioDelivery::TARGETS, true)) $bad('replace.to', $to, implode(', ', ScenarioDelivery::TARGETS));
            $out['replace'] = [
                'to'             => $to,
                'efficiency_old' => $num($rep, 'efficiency_old', 0.5, 1.0, null, 'replace.efficiency_old'),
                'efficiency_new' => $num($rep, 'efficiency_new', 0.5, 1.0, null, 'replace.efficiency_new'),
                'jaz'            => $num($rep, 'jaz', 1, 8, null, 'replace.jaz'),
                'power_ct'       => $num($rep, 'power_ct', 0, 200, null, 'replace.power_ct'),
                'pellet_ct'      => $num($rep, 'pellet_ct', 0, 200, null, 'replace.pellet_ct'),
                'invest_eur'     => $num($rep, 'invest_eur', 0, 10000000, null, 'replace.invest_eur'),
            ];
        }
        return $out;
    }

    /** Neue Eingaben aus Phase 2, die nur Lieferungen und PV kennen (bei den übrigen Arten nicht im Echo). */
    private const PHASE2_INPUTS = ['price_pct', 'pv_size_pct', 'pv_kwp_now', 'battery_kwh', 'self_use_pct', 'feed_in_ct', 'replace'];

    private static function range(float $min, float $max): string
    {
        $f = static fn(float $v): string => str_replace('-', '−', $v == (int)$v ? (string)(int)$v : (string)$v);
        return $f($min) . '…' . $f($max);
    }

    // ══ Rechnung ═══════════════════════════════════════════════════════════

    /**
     * @param array<string,mixed> $in  Rohdaten der Anfrage (werden geprüft)
     * @return array<string,mixed>
     */
    public function compute(array $in, ?string $today = null): array
    {
        // v3.12.0 — mit Stichtag rechnen Prognose, Monatszeilen und Vertragsstatus an ihm (bis v3.11 die
        // Prognose am echten Datum, der Vertragsstatus am Stichtag)
        return $this->consumption->atDate($today, fn() => $this->computeOn($in, $today));
    }

    /** @return array<string,mixed> */
    private function computeOn(array $in, ?string $today): array
    {
        $s = self::normalize($in);
        $utility = $s['utility'];
        $u = Utilities::get($utility);
        $unit = (string)($u['consumption_unit'] ?? 'kWh');
        $calc = self::calcOf($utility);
        // v3.12.0 (F1040, Phase 2) — die übrigen Arten sehen ihre Eingaben wie bisher (Antwort unverändert)
        $head = ['utility' => $utility, 'meter_id' => $s['meter_id'], 'unit' => $unit,
                 'inputs' => $calc === 'consumption' || $calc === null ? array_diff_key($s, array_flip(self::PHASE2_INPUTS)) : $s];
        if ($calc === null) {
            return $head + ['supported' => false, 'valid' => false, 'reason_code' => 'utility_unsupported', 'profile' => null];
        }
        $meter = $this->meters->target($utility, $s['meter_id']);
        if (!$meter) throw new NotFoundException('meter not found');   // der Controller meldet es übersetzt
        if ($calc === 'pv') {
            $pv = new ScenarioPv($this->pvSummary ?? new PvSummaryService($this->consumption, $this->settings), $this->intervals, $this->meters, $this->settings);
            return $head + $pv->compute($s, $utility, $meter, $today ?? date('Y-m-d'));
        }
        if ($calc === 'delivery') return $this->computeDelivery($s, $head, $meter, $today);

        $fc = $this->forecast->forMeter($utility, $meter, array_filter([
            'forecast_months' => 12, 'model' => $s['regression'],
        ], fn($v) => $v !== null));
        if (empty($fc['valid'])) {
            return $head + ['supported' => true, 'valid' => false, 'reason_code' => 'too_few_months', 'profile' => ScenarioProfile::build($utility, $meter)];
        }
        $warnings = [];
        // v3.7.0 (#37) — verteilte Monate prägen weder „wie letztes Jahr“ noch das
        // Saisonprofil; die Werkstatt sagt es wie die Prognose
        foreach ($fc['warnings'] ?? [] as $w) {
            if (($w['code'] ?? '') === 'interpolated') $warnings[] = ['code' => 'interpolated', 'months' => (int)($w['months'] ?? 0)];
        }
        // v3.7.0 (wirklichkeit-2) — Gütesperre: Heizanteil, Band und Abschlag sind dann
        // unsicher. Bei echten Daten lag der Heizanteil bei etwa der Hälfte des
        // Gemessenen; Dämmung und Raumtemperatur wirkten nur halb.
        $fitRatio = ($fc['fit_quality']['model_quality'] ?? null) === 'poor' ? (float)$fc['fit_quality']['ratio'] : null;
        if ($fitRatio !== null) $warnings[] = ['code' => 'poor_fit', 'ratio' => $fitRatio];
        // v3.7.0 (Schritt 3, N3) — zu wenig gemessen, um das Heizmodell zu prüfen: wie die
        // Saldo-Schätzung die ganze Kalibrierspanne (ConsumptionService::PROJECTION_FACTOR_*)
        $fewMeasured = in_array('few_measured', array_column($fc['warnings'] ?? [], 'code'), true);
        if ($fewMeasured) $warnings[] = ['code' => 'few_measured'];
        $uncReasons = array_keys(array_filter(['few_measured' => $fewMeasured, 'poor_fit' => $fitRatio !== null]));
        $uncRatio = $fewMeasured ? ConsumptionService::PROJECTION_FACTOR_MAX * (1 + ($fitRatio ?? 0.0)) - 1 : $fitRatio;
        // v3.7.0 (Nachlauf vor dem Release) — eine Quelle: die Gründe des laufenden Vertrags
        // (Vertragsstatus, Saldo-Karte, Übersicht, Jahresbericht) gelten auch hier, samt
        // klemmendem Faktor; dessen Breite ist der Abstand zwischen ungeklemmt und geklemmt
        $csUnc = self::currentUncertainty($this->consumption, $utility, $meter, $today);
        if ($csUnc !== null) {
            $uncReasons = array_values(array_unique(array_merge((array)$csUnc['reasons'], $uncReasons)));
            if (in_array('factor_clamped', $uncReasons, true) && (float)($csUnc['factor'] ?? 0) > 0) {
                $uncRatio = max($uncRatio ?? 0.0, abs((float)($csUnc['factor_raw'] ?? $csUnc['factor']) / (float)$csUnc['factor'] - 1.0));
            }
            $uncRatio ??= 0.0;
        }
        $vf = $unit === 'kWh' ? 'kwh' : 'm3';
        $rows = $fc['forecast'];
        $heatA = (float)($fc['heat_model']['a'] ?? 0.0);
        $hasHeat = $fc['heat_model'] !== null && array_filter($rows, fn($r) => $r['heat_share'] !== null) !== [];

        // ── Grunddaten je Monat ─────────────────────────────────────────
        $months = [];
        foreach ($rows as $r) {
            $ym = (string)$r['ym'];
            $months[] = [
                'ym' => $ym, 'month' => (int)$r['month'], 'year' => (int)$r['year'],
                'days' => (int)date('t', strtotime($ym . '-01')),
                'qty' => (float)($r[$vf] ?? 0.0), 'heat_share' => $r['heat_share'],
                'fixed' => (float)$r['fixed_eur'], 'unit' => (float)$r['unit_eur'],
                'advance' => $r['advance_estimated'] !== null ? (float)$r['advance_estimated'] : null,
                'band_low' => $r['band_low'], 'band_high' => $r['band_high'], 'hdd_delta' => 0.0,
                // v3.9.0 (F1032) — Subzähler mit eigenem Vertrag: diese Menge rechnet der Vertrag nicht
                'deduct' => (float)($r['deducted_kwh'] ?? 0.0),
            ];
        }

        // v3.10.0 (Skeptiker v3.9.0, M4-Rest) — eine Rechnung für die Monatskosten: Der Ausgangsfall
        // schätzt je Monat wie die Saldo-Karte (Tagesschätzung mit Kalibrierfaktor) statt mit den
        // Monatsmengen der Prognose; das Band der Prognose wandert relativ mit
        $cardQty = $this->consumption->estimatedMonths($utility, $meter, array_column($months, 'ym'));
        if ($cardQty !== null) {
            foreach ($months as &$m) {
                $q = $cardQty[$m['ym']] ?? null;
                if ($q === null) continue;
                $ratio = $m['qty'] > 0 ? $q / $m['qty'] : null;
                $m['qty'] = $q;
                foreach (['band_low', 'band_high'] as $k) {
                    if ($m[$k] !== null) $m[$k] = $ratio !== null ? (float)$m[$k] * $ratio : null;
                }
            }
            unset($m);
        }

        // Raumtemperatur: Heizgradtage bei verschobener Heizgrenze (eigenes Modell)
        $hddBase = (float)($fc['hdd_base'] ?? $this->settings->get('hdd_base_temp', 15.0));
        $roomOk = $hasHeat && count($this->consumption->hddNormals($hddBase)) === 12;
        if ($s['room_temp_delta'] != 0.0) {
            if ($roomOk) {
                foreach ($months as &$m) {
                    $now = $this->consumption->hddNormal($m['month'], $hddBase)['mean'] ?? null;
                    $new = $this->consumption->hddNormal($m['month'], $hddBase + $s['room_temp_delta'])['mean'] ?? null;
                    $m['hdd_delta'] = ($now !== null && $new !== null) ? (float)$new - (float)$now : 0.0;
                }
                unset($m);
            } else {
                $warnings[] = ['code' => 'room_temp_unavailable'];
            }
        }

        // ── Modelle ─────────────────────────────────────────────────────
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $models = self::modelQuantities($months, $fc['historical'] ?? [], $vf, $minDays);
        $modelKey = $s['model'];
        if ($models[$modelKey] === null) {
            $warnings[] = ['code' => $modelKey . '_unavailable'];
            $modelKey = 'standard';
        } elseif (($models['filled'][$modelKey] ?? 0) > 0) {
            $warnings[] = ['code' => 'model_filled', 'model' => $modelKey, 'months' => $models['filled'][$modelKey]];
        }
        $baseQty = $models[$modelKey];

        // ── Stellschrauben ──────────────────────────────────────────────
        $isHeatPump = $utility === 'strom' && Utilities::roleOf('strom', $meter) === 'heat_pump';
        if ($s['ev_km'] > 0 && $utility !== 'strom') $warnings[] = ['code' => 'ev_unsupported'];
        if ($s['jaz_from'] !== null && !$isHeatPump) $warnings[] = ['code' => 'jaz_unsupported'];
        $levers = [
            'winter_pct'   => $hasHeat ? $s['winter_pct'] : 0.0,
            'saving_pct'   => $s['saving_pct'],
            'baseload_pct' => $s['baseload_pct'],
            'heat_a'       => $roomOk ? $heatA : 0.0,
            'ev_kwh_year'  => $utility === 'strom' ? $s['ev_km'] * (float)($s['ev_kwh_per_100km'] ?? 0) / 100.0 : 0.0,
            'jaz_ratio'    => ($isHeatPump && $s['jaz_from'] !== null) ? $s['jaz_from'] / $s['jaz_to'] : 1.0,
            'measures'     => array_values(array_filter($s['measures'], fn($m) => $m['active'])),
        ];
        if (!$hasHeat && ($s['winter_pct'] != 0.0 || array_filter($s['measures'], fn($m) => $m['heat_pct'] > 0))) {
            $warnings[] = ['code' => 'no_heat_model'];
        }

        // ── Tarif ───────────────────────────────────────────────────────
        $offers = $this->offers($utility, $meter, $months);
        $tariffOk = $utility !== 'wasser';
        $tariff = $s['tariff'];
        if (!$tariffOk && $tariff['kind'] !== 'current') {
            $warnings[] = ['code' => 'tariff_unsupported'];
            $tariff = ['kind' => 'current'];
        }
        if ($tariff['kind'] === 'contract') {
            $c = $this->contractById($utility, $meter, $tariff['contract_id']);
            if ($c === null) {
                throw new LocalizedException('errors.forecast.paramInvalid',
                    ['param' => 'tariff.contract_id', 'value' => mb_substr($tariff['contract_id'], 0, 40), 'range' => 'id'], 'scenario contract');
            }
            $prices = $this->priceRows($c, $months);
            if ($prices === null) { $warnings[] = ['code' => 'tariff_no_price']; $tariff = ['kind' => 'current']; }
        }

        $scenQty = self::quantities($months, $baseQty, $levers + ['hdd' => true]);
        $chosenOffer = null;
        if ($tariff['kind'] === 'cheapest') {
            $best = null;
            foreach ($offers as $o) {
                if ($o['prices'] === null) continue;
                $cost = array_sum(self::costs($months, $scenQty, $o['prices']));
                if ($best === null || $cost < $best[1]) $best = [$o, $cost];
            }
            if ($best === null) { $warnings[] = ['code' => 'no_offers']; $tariff = ['kind' => 'current']; }
            else $chosenOffer = $best[0];
        }
        $priceRows = match ($tariff['kind']) {
            'contract' => $prices ?? null,
            'custom'   => array_map(fn() => ['wp' => $tariff['working_price_ct'], 'bp' => $tariff['base_eur_month']], $months),
            'cheapest' => $chosenOffer['prices'] ?? null,
            default    => null,
        };

        // ── Kosten, CO₂, Band ───────────────────────────────────────────
        $baseCost = self::costs($months, $baseQty, null);
        $scenCost = self::costs($months, $scenQty, $priceRows);
        $co2f = self::co2Factor($fc['historical'] ?? [], $vf);
        $bandOk = $modelKey === 'standard' && array_filter($months, fn($m) => $m['band_low'] !== null) !== [];

        $out = [];
        $cb = 0.0; $cs = 0.0;
        foreach ($months as $i => $m) {
            $cb += $baseCost[$i]; $cs += $scenCost[$i];
            $rel = static fn($v) => ($v !== null && $m['qty'] > 0) ? (float)$v / $m['qty'] : null;
            $lo = $bandOk ? $rel($m['band_low']) : null;
            $hi = $bandOk ? $rel($m['band_high']) : null;
            $out[] = [
                'ym'            => $m['ym'],
                'base_qty'      => round($baseQty[$i], 1),
                'scen_qty'      => round($scenQty[$i], 1),
                'base_cost'     => round($baseCost[$i], 2),
                'scen_cost'     => round($scenCost[$i], 2),
                'base_cum_cost' => round($cb, 2),
                'scen_cum_cost' => round($cs, 2),
                'advance'       => $m['advance'] !== null ? round($m['advance'], 2) : null,
                'heat_share'    => $m['heat_share'],
                'band_low'      => $lo !== null ? round($baseQty[$i] * $lo, 1) : null,
                'band_high'     => $hi !== null ? round($baseQty[$i] * $hi, 1) : null,
                'scen_band_low' => $lo !== null ? round($scenQty[$i] * $lo, 1) : null,
                'scen_band_high'=> $hi !== null ? round($scenQty[$i] * $hi, 1) : null,
            ];
        }
        $tot = static fn(array $q, array $c) => [
            'qty' => round(array_sum($q), 1), 'cost' => round(array_sum($c), 2),
            'co2_kg' => $co2f !== null ? round(array_sum($q) * $co2f, 1) : null,
        ];
        $tb = $tot($baseQty, $baseCost);
        $ts = $tot($scenQty, $scenCost);
        $totals = ['base' => $tb, 'scenario' => $ts, 'delta' => [
            'qty'      => round($ts['qty'] - $tb['qty'], 1),
            'cost'     => round($ts['cost'] - $tb['cost'], 2),
            'co2_kg'   => $co2f !== null ? round($ts['co2_kg'] - $tb['co2_kg'], 1) : null,
            'qty_pct'  => $tb['qty'] > 0 ? round(($ts['qty'] - $tb['qty']) / $tb['qty'] * 100, 1) : null,
            'cost_pct' => $tb['cost'] > 0 ? round(($ts['cost'] - $tb['cost']) / $tb['cost'] * 100, 1) : null,
        ]];

        $band = null;
        if ($bandOk && !empty($fc['annual']) && (float)$fc['annual']['value'] > 0) {
            $a = $fc['annual'];
            $lo = (float)$a['low'] / (float)$a['value'];
            $hi = (float)$a['high'] / (float)$a['value'];
            $band = ['level_pct' => $a['level_pct'],
                     'base_low' => round($tb['qty'] * $lo, 1), 'base_high' => round($tb['qty'] * $hi, 1),
                     'scen_low' => round($ts['qty'] * $lo, 1), 'scen_high' => round($ts['qty'] * $hi, 1)];
        }

        // ── Modellvergleich (Experte) ───────────────────────────────────
        $compare = [];
        foreach (self::MODELS as $key) {
            if ($models[$key] === null) { $compare[$key] = null; continue; }
            $q = self::quantities($months, $models[$key], $levers + ['hdd' => true]);
            $compare[$key] = [
                'base_qty'  => round(array_sum($models[$key]), 1),
                'base_cost' => round(array_sum(self::costs($months, $models[$key], null)), 2),
                'scen_qty'  => round(array_sum($q), 1),
                'scen_cost' => round(array_sum(self::costs($months, $q, $priceRows)), 2),
            ];
        }
        if ($compare['trend'] !== null) $compare['trend']['growth_pct'] = round($models['growth'] * 100, 1);

        // ── Abschlag und Saldo zur nächsten Abrechnung ──────────────────
        $billing = $this->billing($utility, $meter, $months, $baseCost, $scenCost, $today, $fc['historical'] ?? []);
        $advance = self::advance($months, $baseCost, $scenCost, $billing);
        if ($uncRatio !== null) $advance = self::uncertainAdvance($advance, $months, $baseQty, $scenQty, $uncRatio, $uncReasons, $fitRatio);
        unset($advance['status_base_range'], $advance['status_scen_range']);

        // ── Rangliste und Optimierung (gegen den Ausgangsfall) ──────────
        $zero = ['winter_pct' => 0.0, 'saving_pct' => 0.0, 'baseload_pct' => 0.0, 'heat_a' => $levers['heat_a'],
                 'ev_kwh_year' => 0.0, 'jaz_ratio' => 1.0, 'measures' => [], 'hdd' => false];
        $evalCost = function (array $lv, ?array $prices = null) use ($months, $baseQty): array {
            $q = self::quantities($months, $baseQty, $lv);
            return [array_sum($q), array_sum(self::costs($months, $q, $prices))];
        };
        [$bq, $bc] = $evalCost($zero);
        $ranking = [];
        $add = function (string $kind, array $lv, ?array $prices = null, array $extra = []) use (&$ranking, $evalCost, $bq, $bc): void {
            [$q, $c] = $evalCost($lv, $prices);
            $saving = $bc - $c;
            $cost = $extra['cost_eur'] ?? null;
            $ranking[] = ['kind' => $kind, 'saving_eur' => round($saving, 2), 'saving_qty' => round($bq - $q, 1),
                'payback_years' => ($cost !== null && $saving > 0.005) ? round($cost / $saving, 1) : null] + $extra;
        };
        if ($s['room_temp_delta'] != 0.0 && $roomOk) $add('room_temp', ['hdd' => true] + $zero, null, ['value' => $s['room_temp_delta']]);
        if ($s['saving_pct'] > 0) $add('saving', ['saving_pct' => $s['saving_pct']] + $zero, null, ['value' => $s['saving_pct']]);
        if ($s['baseload_pct'] != 0.0) $add('baseload', ['baseload_pct' => $s['baseload_pct']] + $zero, null, ['value' => $s['baseload_pct']]);
        if ($levers['ev_kwh_year'] > 0) $add('ev', ['ev_kwh_year' => $levers['ev_kwh_year']] + $zero, null, ['value' => $s['ev_km']]);
        if ($levers['jaz_ratio'] != 1.0) $add('jaz', ['jaz_ratio' => $levers['jaz_ratio']] + $zero, null, ['value' => $s['jaz_to']]);
        if ($priceRows !== null) $add('tariff', $zero, $priceRows, ['tariff_kind' => $tariff['kind']]);
        $single = [];
        foreach ($s['measures'] as $m) {
            if (!$hasHeat && $m['base_pct'] == 0.0) {
                $ranking[] = ['kind' => 'measure', 'id' => $m['id'], 'saving_eur' => 0.0, 'saving_qty' => 0.0,
                              'payback_years' => null, 'cost_eur' => $m['cost_eur']];
                $single[$m['id']] = 0.0;
                continue;
            }
            $add('measure', ['measures' => [$m]] + $zero, null, ['id' => $m['id'], 'cost_eur' => $m['cost_eur']]);
            $single[$m['id']] = $ranking[count($ranking) - 1]['saving_eur'];
        }
        usort($ranking, fn($x, $y) => $y['saving_eur'] <=> $x['saving_eur']);

        $optimization = null;
        if ($s['budget_eur'] !== null) {
            $cands = array_values(array_filter($s['measures'], fn($m) => $m['cost_eur'] !== null));
            $optimization = self::bestUnderBudget($cands, (float)$s['budget_eur'], $single,
                function (array $subset) use ($evalCost, $zero, $bc): float {
                    return $bc - $evalCost(['measures' => $subset] + $zero)[1];
                });
        }

        $offerOut = array_map(fn($o) => [
            'contract_id' => $o['id'], 'computable' => $o['prices'] !== null,
            'base_cost' => $o['prices'] !== null ? round(array_sum(self::costs($months, $baseQty, $o['prices'])), 2) : null,
            'scen_cost' => $o['prices'] !== null ? round(array_sum(self::costs($months, $scenQty, $o['prices'])), 2) : null,
        ], $offers);

        $winter = $hasHeat ? $this->winterPresets($hddBase) : null;
        return $head + [
            'supported'      => true,
            'valid'          => true,
            'model'          => $modelKey,
            'from'           => $months[0]['ym'],
            'to'             => $months[count($months) - 1]['ym'],
            'months'         => $out,
            'totals'         => $totals,
            'band'           => $band,
            'models'         => $compare,
            'advance'        => $advance,
            'billing'        => $billing,
            'tariff'         => ['kind' => $tariff['kind'], 'contract_id' => $tariff['contract_id'] ?? ($chosenOffer['id'] ?? null)],
            'offers'         => $offerOut,
            'ranking'        => $ranking,
            'optimization'   => $optimization,
            'winter_presets' => $winter,
            // v3.12.0 (F1040) — Beispiele, Maßnahmen und Worte dieser Fachgruppe (rechnet nichts)
            'profile'        => ScenarioProfile::build($utility, $meter, [
                'computable' => true, 'heat' => $hasHeat, 'room' => $roomOk, 'tariff' => $tariffOk, 'winter' => $winter, 'months' => $months,
                'has_offers' => array_filter($offers, fn($o) => $o['prices'] !== null) !== [],
            ]),
            'capabilities'   => [
                'heat_model' => $hasHeat, 'room_temp' => $roomOk, 'tariff' => $tariffOk,
                'ev' => $utility === 'strom', 'jaz' => $isHeatPump, 'billing' => $billing !== null,
                'co2' => $co2f !== null, 'band' => $band !== null,
                'models' => array_map(fn($v) => $v !== null, $compare),
                // v3.12.0 (F1040, Phase 2) — die Wahl der Heizkurve wirkt nur mit Heizmodell (additiv)
                'regression' => $hasHeat,
            ],
            'basis'          => [
                'history_months' => count($fc['historical'] ?? []),
                'last_measured'  => (string)(end($fc['historical'])['ym'] ?? ''),
                'hdd_source'     => $fc['hdd_source'] ?? null,
                // v3.7.0 (wirklichkeit-2) — nur bei bestandener Gütesperre
                'heat_share_year'=> $hasHeat && $tb['qty'] > 0 && $uncRatio === null
                    ? round(array_sum(array_map(fn($m, $q) => $q * (float)($m['heat_share'] ?? 0), $months, $baseQty)) / array_sum($baseQty), 3) : null,
                'co2_kg_per_unit'=> $co2f !== null ? round($co2f, 4) : null,
            ],
            'warnings'       => $warnings,
        ];
    }

    /**
     * v3.12.0 (F1040, Phase 2) — Werkstatt für Heizöl und Pellets.
     *
     * Ausgangsfall ist die Prognose der Lieferart (ForecastService, unverändert): Menge je
     * Monat in kWh, Kosten zum gleitenden Durchschnittspreis des Tanks (`unit_eur`, kein
     * Vertrag). Den Heizanteil je Monat liefert das Tankbuch, mit dem die App die Lieferungen
     * auf die Tage verteilt: Entnahme = je Heizgradtag × HGT + je Tag × Tage
     * (`calibration.per_hdd`, `base_per_day`), also
     *
     *   Heizanteil = per_hdd × HGT ÷ (per_hdd × HGT + base_per_day × Tage)
     *
     * mit den Heizgradtagen des Prognosemonats. Winter, Raumtemperatur, Dämmung, Warmwasser
     * und Sparen rechnen damit wie beim Gas (quantities()), die Raumtemperatur mit
     * a = per_hdd × Heizwert (kWh je Heizgradtag). Dazu:
     *
     *   Preis ± %       Kosten = Menge × Preis × (1 + %)
     *   Tank            Bestand heute, Tag für Tag mit der Menge des Szenarios (tankOutlook)
     *   Heizungstausch  eine zweite Rechnung über dieselbe Wärme (ScenarioDelivery::replacement):
     *                   Das Szenario ist dann die neue Heizung, Ausgangsfall bleibt der Brennstoff
     *
     * Abschlag und Abrechnung gibt es bei Lieferungen nicht (`advance`, `billing` null).
     * Prognose und Saldo bleiben unberührt, die Werkstatt liest nur.
     *
     * @param array<string,mixed> $s    geprüfte Eingaben (normalize)
     * @param array<string,mixed> $head Kopf der Antwort
     * @return array<string,mixed>
     */
    private function computeDelivery(array $s, array $head, array $meter, ?string $today): array
    {
        $utility = $s['utility'];
        $fc = $this->forecast->forMeter($utility, $meter, ['forecast_months' => 12]);
        if (empty($fc['valid'])) {
            return $head + ['supported' => true, 'valid' => false, 'reason_code' => 'too_few_months', 'calc' => 'delivery',
                'profile' => ScenarioProfile::build($utility, $meter)];
        }
        $warnings = [];
        $tank = $this->consumption->tankModel($utility, $meter);
        $perUnit = ScenarioDelivery::kwhPerUnit($this->settings, $utility);
        $cal = $tank['calibration'] ?? [];
        $perHdd = (float)($cal['per_hdd'] ?? 0.0);
        $perDay = (float)($cal['base_per_day'] ?? 0.0);
        $hasHeat = empty($cal['flat']) && ($cal['source'] ?? 'none') !== 'none' && $perHdd > 0;

        $months = [];
        foreach ($fc['forecast'] as $r) {
            $ym = (string)$r['ym'];
            $days = (int)date('t', (int)strtotime($ym . '-01'));
            $heat = $perHdd * (float)($r['hdd_estimated'] ?? 0.0);
            $base = $perDay * $days;
            $months[] = [
                'ym' => $ym, 'month' => (int)$r['month'], 'year' => (int)$r['year'], 'days' => $days,
                'qty' => (float)($r['kwh'] ?? 0.0),
                'heat_share' => $hasHeat && $heat + $base > 0 ? round($heat / ($heat + $base), 4) : null,
                'fixed' => (float)$r['fixed_eur'], 'unit' => (float)$r['unit_eur'], 'advance' => null,
                'band_low' => $r['band_low'], 'band_high' => $r['band_high'], 'hdd_delta' => 0.0, 'deduct' => 0.0,
            ];
        }
        $hasPrice = array_filter($months, fn($m) => $m['unit'] > 0) !== [];
        if (!$hasPrice) $warnings[] = ['code' => 'no_price'];

        // Raumtemperatur: Heizgradtage bei verschobener Heizgrenze, a aus dem Tankbuch
        $hddBase = (float)($fc['hdd_base'] ?? $this->consumption->hddBaseFor($meter));
        $roomOk = $hasHeat && count($this->consumption->hddNormals($hddBase)) === 12;
        if ($s['room_temp_delta'] != 0.0) {
            if ($roomOk) {
                foreach ($months as &$m) {
                    $now = $this->consumption->hddNormal($m['month'], $hddBase)['mean'] ?? null;
                    $new = $this->consumption->hddNormal($m['month'], $hddBase + $s['room_temp_delta'])['mean'] ?? null;
                    $m['hdd_delta'] = ($now !== null && $new !== null) ? (float)$new - (float)$now : 0.0;
                }
                unset($m);
            } else {
                $warnings[] = ['code' => 'room_temp_unavailable'];
            }
        }

        $minDays = (int)$this->settings->get('min_days_period', 20);
        $models = self::modelQuantities($months, $fc['historical'] ?? [], 'kwh', $minDays);
        $modelKey = $s['model'];
        if ($models[$modelKey] === null) {
            $warnings[] = ['code' => $modelKey . '_unavailable'];
            $modelKey = 'standard';
        } elseif (($models['filled'][$modelKey] ?? 0) > 0) {
            $warnings[] = ['code' => 'model_filled', 'model' => $modelKey, 'months' => $models['filled'][$modelKey]];
        }
        $baseQty = $models[$modelKey];

        if ($s['ev_km'] > 0) $warnings[] = ['code' => 'ev_unsupported'];
        if ($s['jaz_from'] !== null) $warnings[] = ['code' => 'jaz_unsupported'];
        if ($s['tariff']['kind'] !== 'current') $warnings[] = ['code' => 'tariff_delivery'];
        $levers = [
            'winter_pct' => $hasHeat ? $s['winter_pct'] : 0.0, 'saving_pct' => $s['saving_pct'], 'baseload_pct' => $s['baseload_pct'],
            'heat_a' => $roomOk ? $perHdd * $perUnit : 0.0, 'ev_kwh_year' => 0.0, 'jaz_ratio' => 1.0,
            'measures' => array_values(array_filter($s['measures'], fn($m) => $m['active'])),
        ];
        if (!$hasHeat && ($s['winter_pct'] != 0.0 || array_filter($s['measures'], fn($m) => $m['heat_pct'] > 0))) {
            $warnings[] = ['code' => 'no_heat_model'];
        }

        // ── Menge, Preis, Heizungstausch ────────────────────────────────
        $priceF = 1.0 + $s['price_pct'] / 100.0;
        $priced = static fn(array $ms, float $f): array => array_map(fn($m) => ['unit' => $m['unit'] * $f, 'fixed' => $m['fixed'] * $f] + $m, $ms);
        $fuelQty = self::quantities($months, $baseQty, $levers + ['hdd' => true]);
        $baseCost = self::costs($months, $baseQty, null);
        $fuelCost = self::costs($priced($months, $priceF), $fuelQty, null);
        $pelletPerKg = (float)$this->settings->get('pellets_kwh_per_kg', 4.8);
        $rep = $s['replace'];
        if ($rep !== null && $rep['to'] === $utility) { $warnings[] = ['code' => 'replace_same']; $rep = null; }
        $replaced = $rep !== null ? ScenarioDelivery::replacement($fuelQty, $rep, $pelletPerKg) : null;
        $scenQty = $replaced['energy'] ?? $fuelQty;
        $scenCost = $replaced['cost'] ?? $fuelCost;

        // CO₂: der Brennstoff wie in der Verbrauchsansicht, die neue Heizung mit ihrem Faktor (g je kWh)
        $co2f = self::co2Factor($fc['historical'] ?? [], 'kwh');
        $year = (int)($months[0]['year'] ?? date('Y'));
        $repF = $replaced === null ? null
            : $this->settings->co2Factor($replaced['to'] === 'heat_pump' ? 'co2_strom' : 'co2_pellets', $year) / 1000.0;
        $co2Base = $co2f !== null ? array_sum($baseQty) * $co2f : null;
        $co2Scen = $replaced !== null ? array_sum($scenQty) * $repF : ($co2f !== null ? array_sum($scenQty) * $co2f : null);

        $bandOk = $modelKey === 'standard' && $replaced === null && array_filter($months, fn($m) => $m['band_low'] !== null) !== [];
        $out = [];
        $cb = 0.0; $cs = 0.0;
        foreach ($months as $i => $m) {
            $cb += $baseCost[$i]; $cs += $scenCost[$i];
            $rel = static fn($v) => ($v !== null && $m['qty'] > 0) ? (float)$v / $m['qty'] : null;
            $lo = $bandOk ? $rel($m['band_low']) : null;
            $hi = $bandOk ? $rel($m['band_high']) : null;
            $out[] = [
                'ym' => $m['ym'], 'base_qty' => round($baseQty[$i], 1), 'scen_qty' => round($scenQty[$i], 1),
                'base_cost' => round($baseCost[$i], 2), 'scen_cost' => round($scenCost[$i], 2),
                'base_cum_cost' => round($cb, 2), 'scen_cum_cost' => round($cs, 2), 'advance' => null,
                'heat_share' => $m['heat_share'],
                'band_low' => $lo !== null ? round($baseQty[$i] * $lo, 1) : null, 'band_high' => $hi !== null ? round($baseQty[$i] * $hi, 1) : null,
                'scen_band_low' => $lo !== null ? round($scenQty[$i] * $lo, 1) : null, 'scen_band_high' => $hi !== null ? round($scenQty[$i] * $hi, 1) : null,
            ];
        }
        $tb = ['qty' => round(array_sum($baseQty), 1), 'cost' => round(array_sum($baseCost), 2), 'co2_kg' => $co2Base !== null ? round($co2Base, 1) : null];
        $ts = ['qty' => round(array_sum($scenQty), 1), 'cost' => round(array_sum($scenCost), 2), 'co2_kg' => $co2Scen !== null ? round($co2Scen, 1) : null];
        $totals = ['base' => $tb, 'scenario' => $ts, 'delta' => [
            'qty' => round($ts['qty'] - $tb['qty'], 1), 'cost' => round($ts['cost'] - $tb['cost'], 2),
            'co2_kg' => $tb['co2_kg'] !== null && $ts['co2_kg'] !== null ? round($ts['co2_kg'] - $tb['co2_kg'], 1) : null,
            'qty_pct' => $tb['qty'] > 0 ? round(($ts['qty'] - $tb['qty']) / $tb['qty'] * 100, 1) : null,
            'cost_pct' => $tb['cost'] > 0 ? round(($ts['cost'] - $tb['cost']) / $tb['cost'] * 100, 1) : null,
        ]];
        $band = null;
        if ($bandOk && !empty($fc['annual']) && (float)$fc['annual']['value'] > 0 && $fc['annual']['low'] !== null) {
            $a = $fc['annual'];
            $lo = (float)$a['low'] / (float)$a['value']; $hi = (float)$a['high'] / (float)$a['value'];
            $band = ['level_pct' => $a['level_pct'], 'base_low' => round($tb['qty'] * $lo, 1), 'base_high' => round($tb['qty'] * $hi, 1),
                     'scen_low' => round($ts['qty'] * $lo, 1), 'scen_high' => round($ts['qty'] * $hi, 1)];
        }

        $compare = [];
        foreach (self::MODELS as $key) {
            if ($models[$key] === null) { $compare[$key] = null; continue; }
            $q = self::quantities($months, $models[$key], $levers + ['hdd' => true]);
            $compare[$key] = [
                'base_qty' => round(array_sum($models[$key]), 1), 'base_cost' => round(array_sum(self::costs($months, $models[$key], null)), 2),
                'scen_qty' => round(array_sum($q), 1), 'scen_cost' => round(array_sum(self::costs($priced($months, $priceF), $q, null)), 2),
            ];
        }
        if ($compare['trend'] !== null) $compare['trend']['growth_pct'] = round($models['growth'] * 100, 1);

        // ── Rangliste und Optimierung (gegen den Ausgangsfall) ──────────
        $zero = ['winter_pct' => 0.0, 'saving_pct' => 0.0, 'baseload_pct' => 0.0, 'heat_a' => $levers['heat_a'],
                 'ev_kwh_year' => 0.0, 'jaz_ratio' => 1.0, 'measures' => [], 'hdd' => false];
        $evalCost = function (array $lv, float $f = 1.0) use ($months, $baseQty, $priced): array {
            $q = self::quantities($months, $baseQty, $lv);
            return [array_sum($q), array_sum(self::costs($priced($months, $f), $q, null))];
        };
        [$bq, $bc] = $evalCost($zero);
        $ranking = [];
        $add = function (string $kind, array $lv, float $f = 1.0, array $extra = []) use (&$ranking, $evalCost, $bq, $bc): void {
            [$q, $c] = $evalCost($lv, $f);
            $saving = $bc - $c;
            $cost = $extra['cost_eur'] ?? null;
            $ranking[] = ['kind' => $kind, 'saving_eur' => round($saving, 2), 'saving_qty' => round($bq - $q, 1),
                'payback_years' => ($cost !== null && $saving > 0.005) ? round($cost / $saving, 1) : null] + $extra;
        };
        if ($s['room_temp_delta'] != 0.0 && $roomOk) $add('room_temp', ['hdd' => true] + $zero, 1.0, ['value' => $s['room_temp_delta']]);
        if ($s['saving_pct'] > 0) $add('saving', ['saving_pct' => $s['saving_pct']] + $zero, 1.0, ['value' => $s['saving_pct']]);
        if ($s['baseload_pct'] != 0.0) $add('baseload', ['baseload_pct' => $s['baseload_pct']] + $zero, 1.0, ['value' => $s['baseload_pct']]);
        if ($s['price_pct'] != 0.0) $add('price', $zero, $priceF, ['value' => $s['price_pct']]);
        $single = [];
        foreach ($s['measures'] as $m) {
            if (!$hasHeat && $m['base_pct'] == 0.0) {
                $ranking[] = ['kind' => 'measure', 'id' => $m['id'], 'saving_eur' => 0.0, 'saving_qty' => 0.0, 'payback_years' => null, 'cost_eur' => $m['cost_eur']];
                $single[$m['id']] = 0.0;
                continue;
            }
            $add('measure', ['measures' => [$m]] + $zero, 1.0, ['id' => $m['id'], 'cost_eur' => $m['cost_eur']]);
            $single[$m['id']] = $ranking[count($ranking) - 1]['saving_eur'];
        }
        $repOut = null;
        if ($replaced !== null) {
            // allein gegen den Ausgangsfall: dieselbe Wärme, die neue Heizung statt des Brennstoffs
            $alone = ScenarioDelivery::replacement($baseQty, $rep, $pelletPerKg);
            $saving = $bc - array_sum($alone['cost']);
            $ranking[] = ['kind' => 'replacement', 'saving_eur' => round($saving, 2), 'saving_qty' => round($bq - array_sum($alone['energy']), 1),
                'payback_years' => $rep['invest_eur'] !== null && $saving > 0.005 ? round($rep['invest_eur'] / $saving, 1) : null,
                'cost_eur' => $rep['invest_eur'], 'to' => $replaced['to']];
            $fuelSum = array_sum($fuelCost);
            $repSum = array_sum($replaced['cost']);
            $repOut = [
                'to'            => $replaced['to'],
                'assumed'       => array_map(fn($v) => round((float)$v, 4), $replaced['assumed']),
                'heat_kwh'      => round(array_sum($replaced['heat']), 1),
                'energy_kwh'    => round(array_sum($replaced['energy']), 1),
                'energy_units'  => $replaced['units'] !== null ? round(array_sum($replaced['units']), 1) : null,
                'units_label'   => $replaced['to'] === 'pellets' ? 'kg' : null,
                'cost'          => round($repSum, 2),
                'fuel_cost'     => round($fuelSum, 2),
                'saving_eur'    => round($fuelSum - $repSum, 2),
                'co2_kg'        => $co2Scen !== null ? round($co2Scen, 1) : null,
                'fuel_co2_kg'   => $co2f !== null ? round(array_sum($fuelQty) * $co2f, 1) : null,
                'invest_eur'    => $rep['invest_eur'],
                'payback_years' => $rep['invest_eur'] !== null && $fuelSum - $repSum > 0.005 ? round($rep['invest_eur'] / ($fuelSum - $repSum), 1) : null,
            ];
        }
        usort($ranking, fn($x, $y) => $y['saving_eur'] <=> $x['saving_eur']);
        $optimization = null;
        if ($s['budget_eur'] !== null) {
            $cands = array_values(array_filter($s['measures'], fn($m) => $m['cost_eur'] !== null));
            $optimization = self::bestUnderBudget($cands, (float)$s['budget_eur'], $single,
                fn(array $subset): float => $bc - $evalCost(['measures' => $subset] + $zero)[1]);
        }

        // ── Tank: Bestand heute, leer am, nachfüllen am ─────────────────
        $tankOut = null;
        $days = $tank['days'] ?? [];
        if ($days !== [] && ($cal['source'] ?? 'none') !== 'none') {
            $asOf = (string)array_key_last($days);
            $cur = array_filter($days, fn($d) => str_starts_with((string)$d, substr($asOf, 0, 7)), ARRAY_FILTER_USE_KEY);
            $rest = $cur !== [] ? array_sum(array_column($cur, 'draw')) / count($cur) : 0.0;
            $capacity = (float)($meter['capacity'] ?? 0.0);
            $warnPct = (float)$this->settings->get('tank_warn_pct', 15);
            $level = $capacity > 0 ? $capacity * $warnPct / 100.0 : null;
            $stock = (float)$days[$asOf]['stock'];
            $toUnits = fn(array $q) => array_map(fn($v) => $v / $perUnit, $q);
            $outlook = fn(array $q, float $ratio) => ScenarioDelivery::tankOutlook($stock, $asOf, $rest * $ratio, $months, $toUnits($q), $level);
            $ratio0 = ($baseQty[0] ?? 0.0) > 0 ? $fuelQty[0] / $baseQty[0] : 1.0;
            $tankOut = [
                'unit' => (string)($meter['capacity_unit'] ?? (Utilities::get($utility)['unit'] ?? '')),
                'capacity' => $capacity > 0 ? round($capacity, 1) : null, 'stock' => round($stock, 1), 'as_of' => $asOf,
                'estimated' => !empty($days[$asOf]['estimated']), 'refill_pct' => $warnPct, 'refill_level' => $level !== null ? round($level, 1) : null,
                'base' => $outlook($baseQty, 1.0),
                // nach einem Heizungstausch gibt es diesen Tank im Szenario nicht mehr
                'scenario' => $replaced === null ? $outlook($fuelQty, $ratio0) : null,
            ];
        }

        $winter = $hasHeat ? $this->winterPresets($hddBase) : null;
        $basePrice = $months[0]['unit'] ?? 0.0;
        $unitLabel = (string)(Utilities::get($utility)['unit'] ?? '');
        return $head + [
            'supported'      => true,
            'valid'          => true,
            'calc'           => 'delivery',
            'model'          => $modelKey,
            'from'           => $months[0]['ym'],
            'to'             => $months[count($months) - 1]['ym'],
            'months'         => $out,
            'totals'         => $totals,
            'band'           => $band,
            'models'         => $compare,
            'advance'        => null,
            'billing'        => null,
            'tariff'         => ['kind' => 'current', 'contract_id' => null],
            'offers'         => [],
            'ranking'        => $ranking,
            'optimization'   => $optimization,
            'winter_presets' => $winter,
            'profile'        => ScenarioProfile::build($utility, $meter, [
                'computable' => true, 'heat' => $hasHeat, 'room' => $roomOk, 'tariff' => false, 'winter' => $winter, 'months' => $months,
                'has_offers' => false, 'price' => $hasPrice,
                // „Heizungstausch: Wärmepumpe“ nur mit Preis; die Richtung aus Wärmepreis alt gegen neu
                'heat_pump_less' => $hasPrice ? (ScenarioDelivery::ASSUMED['power_ct'] / ScenarioDelivery::ASSUMED['jaz']
                    < $basePrice * 100.0 / ScenarioDelivery::ASSUMED['efficiency_old']) : null,
            ]),
            'capabilities'   => [
                'heat_model' => $hasHeat, 'room_temp' => $roomOk, 'tariff' => false, 'ev' => false, 'jaz' => false, 'billing' => false,
                'co2' => $co2f !== null, 'band' => $band !== null, 'models' => array_map(fn($v) => $v !== null, $compare),
                'regression' => false, 'price' => $hasPrice, 'replacement' => $hasPrice,
            ],
            'basis'          => [
                'history_months'  => count($fc['historical'] ?? []),
                'last_measured'   => (string)(end($fc['historical'])['ym'] ?? ''),
                'hdd_source'      => $fc['hdd_source'] ?? null,
                'heat_share_year' => $hasHeat && $tb['qty'] > 0
                    ? round(array_sum(array_map(fn($m, $q) => $q * (float)($m['heat_share'] ?? 0), $months, $baseQty)) / array_sum($baseQty), 3) : null,
                'co2_kg_per_unit' => $co2f !== null ? round($co2f, 4) : null,
                'tank_calibration'=> (string)($cal['source'] ?? 'none'),
            ],
            'warnings'       => $warnings,
            // v3.12.0 (F1040, Phase 2) — Lieferungen: Preis je Liter bzw. kg, Mengen in L/kg, Tank, Heizungstausch
            'delivery'       => [
                'unit' => $unitLabel, 'kwh_per_unit' => round($perUnit, 4),
                'base_units' => round(array_sum($baseQty) / $perUnit, 1),
                'scen_units' => $replaced === null ? round(array_sum($fuelQty) / $perUnit, 1) : null,
                'base_ct_per_unit' => round($basePrice * 100.0 * $perUnit, 2),
                'scen_ct_per_unit' => round($basePrice * 100.0 * $perUnit * $priceF, 2),
                // die genannten Beispielwerte des Heizungstauschs, wenn ein Feld leer bleibt
                'assumed_replace' => ScenarioDelivery::ASSUMED,
            ],
            'tank'           => $tankOut,
            'replacement'    => $repOut,
        ];
    }

    // ══ Reine Bausteine (testbar ohne Daten) ═══════════════════════════════

    /**
     * Szenario-Mengen je Monat.
     *
     * @param list<array{qty:float,heat_share:?float,hdd_delta:float,days:int}> $months
     * @param list<float> $base   Ausgangsmengen je Monat (des gewählten Modells)
     * @param array<string,mixed> $lv  Stellschrauben (s. compute)
     * @return list<float>
     */
    public static function quantities(array $months, array $base, array $lv): array
    {
        $heatF = 1.0 + (float)($lv['winter_pct'] ?? 0) / 100.0;
        $baseF = 1.0 + (float)($lv['baseload_pct'] ?? 0) / 100.0;
        foreach ($lv['measures'] ?? [] as $m) {
            $heatF *= 1.0 - (float)($m['heat_pct'] ?? 0) / 100.0;
            $baseF *= 1.0 - (float)($m['base_pct'] ?? 0) / 100.0;
        }
        $save = 1.0 - (float)($lv['saving_pct'] ?? 0) / 100.0;
        $jaz = (float)($lv['jaz_ratio'] ?? 1.0);
        $evDay = (float)($lv['ev_kwh_year'] ?? 0) / 365.0;
        $a = !empty($lv['hdd']) ? (float)($lv['heat_a'] ?? 0) : 0.0;
        $out = [];
        foreach ($months as $i => $m) {
            $q = (float)$base[$i];
            $heat = $q * (float)($m['heat_share'] ?? 0.0);
            $rest = $q - $heat;
            $heat = max(0.0, $heat + $a * (float)($m['hdd_delta'] ?? 0.0));
            $out[] = max(0.0, ($heat * $heatF + $rest * $baseF) * $save * $jaz + $evDay * (int)$m['days']);
        }
        return $out;
    }

    /**
     * Kosten je Monat: laufender Vertrag (fester Teil + Preis je Einheit aus der
     * Prognose) oder fremde Preise (Arbeitspreis ct, Grundpreis € je Monat).
     *
     * @param list<array{fixed:float,unit:float}> $months
     * @param list<float> $qty
     * @param list<array{wp:float,bp:float}>|null $prices
     * @return list<float>
     */
    public static function costs(array $months, array $qty, ?array $prices): array
    {
        $out = [];
        foreach ($months as $i => $m) {
            // v3.9.0 (F1032) — Rest-Abrechnung: bezahlt wird die Menge ohne die Subzähler mit eigenem Vertrag
            $q = max(0.0, (float)$qty[$i] - (float)($m['deduct'] ?? 0.0));
            $out[] = $prices === null
                ? (float)$m['fixed'] + (float)$m['unit'] * $q
                : $q * (float)$prices[$i]['wp'] / 100.0 + (float)$prices[$i]['bp'];
        }
        return $out;
    }

    /**
     * Ausgangsmengen je Modell. `standard` = Prognose; `last_year` = derselbe
     * Kalendermonat ein Jahr früher (Tagesrate × Tage); `trend` = Vorjahr ×
     * (Vorjahr / Jahr davor), höchstens ± 25 %. Fehlt einem Modell ein Monat,
     * füllt die Prognose ihn (gezählt in `filled`); ohne zwei volle Jahre gibt
     * es keinen Trend (null).
     *
     * @param list<array<string,mixed>> $months
     * @param list<array<string,mixed>> $history  Monatszeilen (ConsumptionService::forMeter)
     * @return array{standard:list<float>,last_year:?list<float>,trend:?list<float>,growth:?float,filled:array<string,int>}
     */
    public static function modelQuantities(array $months, array $history, string $vf, int $minDays): array
    {
        $byYm = [];
        foreach ($history as $h) {
            if (!empty($h['pre_baseline']) || (int)($h['days'] ?? 0) < $minDays) continue;
            // v3.7.0 (#37) — „wie letztes Jahr“ nur aus gemessenen Monaten; ein
            // verteilter Vorjahresmonat kommt aus der Prognose (zählt in `filled`)
            if (!empty($h['interpolated'])) continue;
            $byYm[(string)$h['ym']] = (float)($h[$vf] ?? 0) / (int)$h['days'];
        }
        $shift = static fn(string $ym, int $years) => sprintf('%04d-%s', (int)substr($ym, 0, 4) - $years, substr($ym, 5, 2));
        $standard = array_map(fn($m) => (float)$m['qty'], $months);
        $last = []; $filled = 0; $s1 = 0.0; $s0 = 0.0; $full = true;
        foreach ($months as $i => $m) {
            $r1 = $byYm[$shift($m['ym'], 1)] ?? null;
            $r0 = $byYm[$shift($m['ym'], 2)] ?? null;
            if ($r1 === null) { $last[] = $standard[$i]; $filled++; $full = false; continue; }
            $last[] = $r1 * (int)$m['days'];
            if ($r0 === null) { $full = false; continue; }
            $s1 += $r1 * (int)$m['days'];
            $s0 += $r0 * (int)$m['days'];
        }
        $lastOk = $filled < count($months);
        $growth = null; $trend = null;
        if ($full && $s0 > 0) {
            $growth = max(-self::TREND_CAP, min(self::TREND_CAP, $s1 / $s0 - 1.0));
            $trend = array_map(fn($v) => $v * (1.0 + $growth), $last);
        }
        return ['standard' => $standard, 'last_year' => $lastOk ? $last : null, 'trend' => $trend,
                'growth' => $growth, 'filled' => ['last_year' => $lastOk ? $filled : 0, 'trend' => 0, 'standard' => 0]];
    }

    /**
     * Wirksamer CO₂-Faktor je Einheit aus den letzten zwölf gerechneten Monaten
     * (dieselben Faktoren wie in der Verbrauchsansicht). Null ohne Angabe.
     *
     * @param list<array<string,mixed>> $history
     */
    public static function co2Factor(array $history, string $vf): ?float
    {
        $kg = 0.0; $q = 0.0;
        foreach (array_slice($history, -12) as $h) {
            if (!isset($h['co2_kg']) || $h['co2_kg'] === null || (float)($h[$vf] ?? 0) <= 0) continue;
            $kg += (float)$h['co2_kg']; $q += (float)$h[$vf];
        }
        return $q > 0 && $kg > 0 ? $kg / $q : null;
    }

    /**
     * Beste Maßnahmenkombination unter einem Budget.
     *
     * Exakt durch Aufzählung aller Kombinationen (höchstens 2¹⁰) mit dem echten
     * Modell `$comboSaving` — Wechselwirkungen eingeschlossen. Dazu die
     * LP-Relaxierung mit dem Simplex-Löser über die Einzelersparnisse
     * (maximiere Σ sᵢxᵢ, Σ kᵢxᵢ ≤ Budget, 0 ≤ xᵢ ≤ 1): obere Schranke und
     * Schattenpreis des Budgets. Bei Gleichstand gewinnt die billigere, dann
     * die kleinere Kombination.
     *
     * @param list<array{id:string,cost_eur:?float}> $measures
     * @param array<string,float> $single  Einzelersparnis je id (€/Jahr)
     * @param callable(list<array<string,mixed>>):float $comboSaving
     * @return array<string,mixed>
     */
    public static function bestUnderBudget(array $measures, float $budget, array $single, callable $comboSaving): array
    {
        $n = count($measures);
        $best = ['mask' => 0, 'saving' => 0.0, 'cost' => 0.0, 'size' => 0];
        $evaluated = 0;
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $cost = 0.0; $subset = []; $size = 0;
            for ($i = 0; $i < $n; $i++) {
                if (!($mask & (1 << $i))) continue;
                $cost += (float)$measures[$i]['cost_eur'];
                $subset[] = $measures[$i];
                $size++;
            }
            if ($cost > $budget + 1e-9) continue;
            $evaluated++;
            $saving = $comboSaving($subset);
            $better = $saving > $best['saving'] + 0.005
                || (abs($saving - $best['saving']) <= 0.005 && $saving > 0.005
                    && ($cost < $best['cost'] - 1e-9 || (abs($cost - $best['cost']) <= 1e-9 && $size < $best['size'])));
            if ($better) $best = ['mask' => $mask, 'saving' => $saving, 'cost' => $cost, 'size' => $size];
        }
        $chosen = [];
        for ($i = 0; $i < $n; $i++) if ($best['mask'] & (1 << $i)) $chosen[] = $measures[$i]['id'];

        // LP-Relaxierung: Budgetzeile + je Maßnahme x ≤ 1
        $lp = null;
        if ($n > 0) {
            $c = array_map(fn($m) => max(0.0, (float)($single[$m['id']] ?? 0.0)), $measures);
            $a = [array_map(fn($m) => (float)$m['cost_eur'], $measures)];
            $b = [$budget];
            for ($i = 0; $i < $n; $i++) {
                $row = array_fill(0, $n, 0.0);
                $row[$i] = 1.0;
                $a[] = $row;
                $b[] = 1.0;
            }
            $res = Simplex::maximize($c, $a, $b);
            if ($res['status'] === 'optimal') {
                $lp = [
                    'bound_eur'           => round($res['value'], 2),
                    'budget_value_per_eur'=> round($res['duals'][0], 4),
                    'x'                   => array_combine(array_map(fn($m) => $m['id'], $measures), array_map(fn($v) => round($v, 4), $res['x'])),
                ];
            }
        }
        return [
            'budget_eur'     => round($budget, 2),
            'chosen'         => $chosen,
            'saving_eur'     => round($best['saving'], 2),
            'cost_eur'       => round($best['cost'], 2),
            'payback_years'  => $best['saving'] > 0.005 && $best['cost'] > 0 ? round($best['cost'] / $best['saving'], 1) : null,
            'candidates'     => $n,
            'evaluated'      => $evaluated,
            'method'         => 'enumeration',
            'lp'             => $lp,
        ];
    }

    /**
     * Empfohlener Abschlag: mit laufender Abrechnungsperiode wie der Vorschlag
     * im Vertragsstatus (Abschlag + Endsaldo / verbleibende Monate), sonst ein
     * Zwölftel der Jahreskosten.
     *
     * @param list<array<string,mixed>> $months
     * @param list<float> $baseCost
     * @param list<float> $scenCost
     * @param array<string,mixed>|null $billing
     * @return array<string,mixed>
     */
    public static function advance(array $months, array $baseCost, array $scenCost, ?array $billing): array
    {
        $current = $months[0]['advance'] ?? null;
        // v3.9.0 (V1, L12) — aus dem Vertragsstatus: der Ausgangsfall ist der Vorschlag der
        // Karte, das Szenario dieselbe Formel (Abschlag + Endsaldo ÷ Abschläge bis zur
        // Abrechnung), beide erst ab SUGGEST_MIN_MONTHS; darunter der laufende Bedarf
        if ($billing !== null && ($billing['source'] ?? null) === 'contract_status') {
            $current = $billing['current_advance'] ?? $current;
            $left = (int)$billing['months_left'];
            $short = $left < ConsumptionService::SUGGEST_MIN_MONTHS;
            $ongoing = $billing['advance_ongoing'] ?? null;
            $every = max(1, (int)($billing['advance_every_months'] ?? 1));
            $n = min(12, count($months));
            // je Rate: die Monatskosten des Szenarios mal den Monaten je Rate (H1)
            $shift = $n > 0 ? (array_sum(array_slice($scenCost, 0, $n)) - array_sum(array_slice($baseCost, 0, $n))) / $n * $every : 0.0;
            $scenRec = $current !== null && $left >= 1 && !$short
                ? max(0.0, round((float)$current + (float)$billing['scen_end_balance'] / $left)) : null;
            $out = ['current' => $current !== null ? round((float)$current, 2) : null, 'method' => 'billing_period', 'months_left' => $left,
                'base_recommended' => $billing['suggested_advance'] !== null ? (float)$billing['suggested_advance'] : null,
                'scen_recommended' => $billing['suggested_advance'] !== null ? $scenRec : null,
                // laufend angemessen nach der Abrechnung (Karte: advance_ongoing), im Szenario um die Monatskosten verschoben
                'base_ongoing' => $ongoing !== null ? (float)$ongoing : null,
                'scen_ongoing' => $ongoing !== null ? max(0.0, round((float)$ongoing + $shift)) : null,
                'withheld' => $billing['suggestion_withheld'] ?? null,
                'every_months' => $every];   // v3.9.0 (Skeptiker H1, additiv) — alle Beträge je Rate
            // die Spanne der Karte, wenn ihre Schätzung unsicher ist; das Szenario verschiebt sie
            if (is_array($billing['suggested_range'] ?? null) && $left >= 1) {
                $d = ((float)$billing['scen_end_balance'] - (float)$billing['base_end_balance']) / $left;
                $out['status_base_range'] = array_map('floatval', $billing['suggested_range']);
                $out['status_scen_range'] = [max(0.0, round($billing['suggested_range'][0] + $d)), max(0.0, round($billing['suggested_range'][1] + $d))];
            }
            return $out;
        }
        if ($billing !== null && $current !== null && (int)$billing['months_left'] >= 1) {
            $left = (int)$billing['months_left'];
            return ['current' => round((float)$current, 2), 'method' => 'billing_period', 'months_left' => $left,
                'base_recommended' => max(0.0, round((float)$current + $billing['base_end_balance'] / $left)),
                'scen_recommended' => max(0.0, round((float)$current + $billing['scen_end_balance'] / $left))];
        }
        return ['current' => $current !== null ? round((float)$current, 2) : null, 'method' => 'year_twelfth', 'months_left' => null,
            'base_recommended' => max(0.0, (float)ceil(array_sum($baseCost) / 12)),
            'scen_recommended' => max(0.0, (float)ceil(array_sum($scenCost) / 12))];
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Abschlag, wenn das Heizmodell die Gütesperre
     * nicht besteht: statt eines Betrags die Spanne. Die Arbeitskosten der
     * Monate, die in den Abschlag eingehen (bis zur Abrechnung bzw. das Jahr),
     * schwanken um ± das Verhältnis der Gütesperre (systematischer Fehler einer
     * gekrümmten Heizkurve, er mittelt sich nicht heraus); der Grundpreis nicht.
     * Wie im Vertragsstatus (ConsumptionService::projectionUncertainty) gibt es
     * dann keinen einzelnen Vorschlag.
     *
     * v3.7.0 (Schritt 3, N3) — auch, wenn zu wenig gemessen ist, um das
     * Heizmodell zu prüfen (`few_measured`): dann ± die halbe Arbeit (die
     * Kalibrierspanne 0,5 … 1,5 der Saldo-Schätzung), mit schlechtem Modell
     * zusätzlich mal (1 + Verhältnis).
     *
     * @param array<string,mixed> $advance aus advance()
     * @param list<array<string,mixed>> $months
     * @param list<float> $baseQty
     * @param list<float> $scenQty
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    /** v3.7.0 (Nachlauf) — `projection_uncertain` des laufenden Vertrags (dieselbe Quelle wie Karte und Übersicht), sonst null. */
    public static function currentUncertainty(ConsumptionService $consumption, string $utility, array $meter, ?string $today = null): ?array
    {
        try {
            foreach ($consumption->contractStatus($utility, $meter, $today)['contracts'] ?? [] as $c) {
                if (empty($c['is_current'])) continue;
                // v3.10.0 (Skeptiker H3) — eine Abrechnung über mehrere Verträge ist unsicher, wenn ein Teil es ist
                if (($c['settle_balance'] ?? null) !== null && array_key_exists('settle_uncertain', $c)) return is_array($c['settle_uncertain']) ? $c['settle_uncertain'] : null;
                return is_array($c['projection_uncertain'] ?? null) ? $c['projection_uncertain'] : null;
            }
        } catch (\Throwable) { /* ohne Vertragsstatus: nur die Gründe der Prognose */ }
        return null;
    }

    public static function uncertainAdvance(array $advance, array $months, array $baseQty, array $scenQty, float $ratio, array $reasons = ['poor_fit'], ?float $fitRatio = null): array
    {
        if ($reasons === ['poor_fit']) $fitRatio ??= $ratio;
        $billing = $advance['method'] === 'billing_period';
        $n = $billing ? (int)$advance['months_left'] : count($months);
        $div = $billing ? max(1, $n) : 12;
        $eb = $es = 0.0;
        foreach (array_slice($months, 0, $n, true) as $i => $m) {
            $eb += (float)$m['unit'] * (float)$baseQty[$i];
            $es += (float)$m['unit'] * (float)$scenQty[$i];
        }
        $range = function (?float $rec, float $energy) use ($ratio, $div): ?array {
            if ($rec === null) return null;
            $d = $ratio * $energy / $div;
            $r = [max(0.0, (float)round($rec - $d)), (float)round($rec + $d)];
            return $r[0] === $r[1] ? null : $r;   // v3.7.0 (Schritt 3) — keine Spanne der Breite null
        };
        // v3.9.0 (V1) — aus dem Vertragsstatus: die Spanne der Karte (und für das Szenario verschoben)
        $baseRange = $advance['status_base_range'] ?? $range($advance['base_recommended'], $eb);
        $scenRange = $advance['status_scen_range'] ?? $range($advance['scen_recommended'], $es);
        if ($baseRange !== null && $baseRange[0] === $baseRange[1]) $baseRange = null;
        if ($scenRange !== null && $scenRange[0] === $scenRange[1]) $scenRange = null;
        unset($advance['status_base_range'], $advance['status_scen_range']);
        return array_merge($advance, [
            // `fit_ratio` ist die Streuung der Gütesperre (null ohne sie), `spread` die ganze Breite
            'uncertain'        => ['reasons' => $reasons, 'fit_ratio' => $fitRatio !== null ? round($fitRatio, 3) : null, 'spread' => round($ratio, 3),
                                   'base_range' => $baseRange, 'scen_range' => $scenRange],
            // unsicher: auch kein laufender Betrag
            'base_ongoing'     => null,
            'scen_ongoing'     => null,
            'base_recommended' => null,
            'scen_recommended' => null,
        ]);
    }

    /**
     * Saldo am Ende der laufenden Abrechnungsperiode (F1030): bisher gemessen
     * plus die Prognosemonate bis zum Periodenende (Kosten − Abschlag), der
     * letzte Monat anteilig. Positiv = Nachzahlung (wie `balance` der Periode).
     *
     * v3.6.0 (Paket abrechnung) — `$rest`: der Rest des Monats nach dem Stand
     * der Periode (`as_of`), für den es weder Messung noch Prognosemonat gibt
     * (die Prognose beginnt im Monat nach der letzten Ablesung). Ohne ihn
     * fehlten bis zu 30 Tage Kosten und Abschlag im erwarteten Endsaldo
     * (Lektion 82, derselbe Fehler wie beim Saldo-Verlauf).
     *
     * @param array<string,mixed> $running  laufende Periode aus BillingPeriodService
     * @param list<array<string,mixed>> $months
     * @param array{days:int,cost:float,advance:float}|null $rest  Rest des angebrochenen Monats (restOfMonth)
     * @return array<string,mixed>|null
     */
    public static function billingBalance(array $running, array $months, array $baseCost, array $scenCost, ?array $rest = null): ?array
    {
        $until = (string)($running['until'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) return null;
        $untilYm = substr($until, 0, 7);
        $asOfYm = substr((string)($running['as_of'] ?? ''), 0, 7);
        $base = $scen = (float)($running['balance'] ?? 0.0);
        $left = 0;
        // der angebrochene Monat: in Ausgangsfall und Szenario gleich (er läuft schon)
        if ($rest !== null && $rest['days'] > 0) {
            $base += $rest['cost'] - $rest['advance'];
            $scen += $rest['cost'] - $rest['advance'];
        }
        foreach ($months as $i => $m) {
            if ($m['ym'] > $untilYm) break;
            if ($asOfYm !== '' && $m['ym'] <= $asOfYm) continue;
            $frac = $m['ym'] === $untilYm ? min(1.0, (int)substr($until, 8, 2) / (int)$m['days']) : 1.0;
            $adv = (float)($m['advance'] ?? 0.0);
            $base += ($baseCost[$i] - $adv) * $frac;
            $scen += ($scenCost[$i] - $adv) * $frac;
            $left++;
        }
        $last = end($months);
        return [
            'from' => $running['from'] ?? null, 'until' => $until, 'as_of' => $running['as_of'] ?? null,
            'balance_so_far'   => round((float)($running['balance'] ?? 0.0), 2),
            // v3.6.0 — Tage nach dem Stand bis Monatsende, geschätzt mit der Tagesrate des gemessenen Teils (additiv)
            'rest_days'        => (int)($rest['days'] ?? 0),
            'base_end_balance' => round($base, 2),
            'scen_end_balance' => round($scen, 2),
            'months_left'      => $left,
            'beyond_horizon'   => $last !== false && $untilYm > $last['ym'],
        ];
    }

    // ══ Intern ════════════════════════════════════════════════════════════

    /**
     * v3.9.0 (V1) — Saldo zur Abrechnung aus derselben Projektion wie die
     * Saldo-Karte: Der Ausgangsfall ist der erwartete Endsaldo der Verträge in
     * der laufenden Abrechnungsperiode (ConsumptionService::contractStatus,
     * Summe ihrer Teile), das Szenario ändert davon nur, was ab heute noch
     * kommt: die Kosten der Prognosemonate, anteilig nach Tagen in [heute,
     * Abrechnungstag]. Bis v3.7 rechnete die Werkstatt eigenständig ab der
     * letzten Ablesung (im Testhaushalt −356,55 € gegen die Karte −1.163,40 €,
     * dazu ein anderer Abrechnungstag und andere Abschlagsspannen).
     *
     * @param list<array<string,mixed>> $rows  Zeilen des Vertragsstatus mit `billing_period`
     * @param list<array<string,mixed>> $months Prognosemonate (ym, days)
     * @param list<float> $baseCost
     * @param list<float> $scenCost
     * @return array<string,mixed>|null
     */
    public static function billingFromStatus(array $rows, array $months, array $baseCost, array $scenCost, string $today): ?array
    {
        $rows = array_values(array_filter($rows, fn($r) => is_array($r['billing_period'] ?? null)));
        if ($rows === []) return null;
        $cur = array_values(array_filter($rows, fn($r) => !empty($r['is_current'])))[0] ?? $rows[count($rows) - 1];
        $p = $cur['billing_period'];
        $settle = max(array_map(fn($r) => (string)($r['settle_on'] ?? $p['until']), $rows));
        $base = 0.0; $soFar = 0.0;
        foreach ($rows as $r) { $base += (float)($r['projected_end_balance'] ?? 0.0); $soFar += (float)($r['current_balance'] ?? 0.0); }
        $delta = 0.0;
        $day = static fn(string $d): int => (int)floor((new \DateTimeImmutable($d . ' 12:00'))->getTimestamp() / 86400);
        foreach ($months as $i => $m) {
            $ms = $m['ym'] . '-01';
            $me = date('Y-m-t', (int)strtotime($ms));
            $a = max($ms, $today); $b = min($me, $settle);
            if ($a > $b) continue;
            $frac = ($day($b) - $day($a) + 1) / max(1, (int)$m['days']);
            $delta += ((float)$scenCost[$i] - (float)$baseCost[$i]) * $frac;
        }
        $last = end($months);
        // v3.10.0 (Skeptiker H1/H3) — rechnet die Abrechnung mehrere Verträge ab, die Unsicherheit der Summe
        // (`settle_uncertain`, Spanne aus allen Teilen) wie die Karte
        $settled = ($cur['settle_balance'] ?? null) !== null && array_key_exists('settle_uncertain', $cur);
        $unc = $settled ? (is_array($cur['settle_uncertain']) ? $cur['settle_uncertain'] : null)
            : (is_array($cur['projection_uncertain'] ?? null) ? $cur['projection_uncertain'] : null);
        // v3.9.0 (Skeptiker H3) — dieselbe Unsicherheit wie die Karte: deren Spanne um den Saldo
        // der übrigen Zeilen ergänzt, das Szenario verschiebt sie wie den Punktwert
        $range = null;
        if ($unc !== null && is_array($unc['projected_range'] ?? null) && count($unc['projected_range']) === 2) {
            $mid = (float)($settled ? $cur['settle_balance'] : ($cur['projected_end_balance'] ?? 0.0));
            $r = array_map('floatval', $unc['projected_range']);
            $range = [round($base + min($r) - $mid, 2), round($base + max($r) - $mid, 2)];
        }
        return [
            'from' => $p['from'], 'until' => $p['until'],
            // der letzte Tag, zu dem abgerechnet wird (wie `settle_on` der Karte)
            'settle_on' => $settle,
            'as_of' => $today,
            'balance_so_far'   => round($soFar, 2),
            'rest_days'        => 0,
            'base_end_balance' => round($base, 2),
            'scen_end_balance' => round($base + $delta, 2),
            // v3.9.0 (Skeptiker H3, additiv) — Spanne der Karte (null, wenn sie sicher ist)
            'base_end_range'   => $range,
            'scen_end_range'   => $range !== null ? [round($range[0] + $delta, 2), round($range[1] + $delta, 2)] : null,
            'months_left'      => (int)($cur['months_left'] ?? 0),
            'beyond_horizon'   => $last !== false && substr($settle, 0, 7) > $last['ym'],
            'source'           => 'contract_status',
            // die Abschläge der Karte (eine Quelle für Vorschlag, Spanne und laufenden Bedarf)
            'current_advance'     => $cur['current_advance_amount'] ?? null,
            // v3.9.0 (Skeptiker H1) — Rhythmus des Plans: Beträge gelten je Rate (alle N Monate)
            'advance_every_months' => max(1, (int)($cur['current_advance_every_months'] ?? 1)),
            'suggested_advance'   => $cur['suggested_advance'] ?? null,
            'suggested_range'     => $unc['suggested_range'] ?? null,
            'advance_ongoing'     => $cur['advance_ongoing'] ?? null,
            'suggestion_withheld' => $cur['suggestion_withheld'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function billing(string $utility, array $meter, array $months, array $baseCost, array $scenCost, ?string $today, array $history = []): ?array
    {
        if (!Utilities::supportsBillCheck($utility) || ($meter['capture'] ?? null) === 'period') return null;
        // v3.9.0 (V1) — zuerst die Projektion des Vertragsstatus (Saldo-Karte); er kennt
        // die laufende Periode selbst (ConsumptionService::runningPeriod)
        try {
            $status = $this->consumption->contractStatus($utility, $meter, $today ?? date('Y-m-d'))['contracts'] ?? [];
        } catch (\Throwable) {
            $status = [];
        }
        $fromStatus = self::billingFromStatus($status, $months, $baseCost, $scenCost, $today ?? date('Y-m-d'));
        if ($fromStatus !== null) return $fromStatus;
        // ohne Vertrag in der Periode: wie bisher aus dem Abrechnungsjahr und der Prognose
        if ($this->billing === null) return null;
        try {
            $bp = $this->billing->forTarget($utility, $meter, false, $today);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $running = null;
        foreach ($bp['periods'] ?? [] as $p) if (!empty($p['running'])) $running = $p;
        return $running !== null
            ? self::billingBalance($running, $months, $baseCost, $scenCost, self::restOfMonth($running, $months, $history))
            : null;
    }

    /**
     * v3.6.0 (Paket abrechnung) — Rest des Monats nach dem Stand der laufenden
     * Periode bis Monatsende (höchstens bis Periodenende), wenn kein
     * Prognosemonat ihn abdeckt: Arbeitspreis mit der Tagesrate des gemessenen
     * Teils dieses Monats, Grundpreis und Abschlag tagesanteilig (wie die
     * Periode selbst, BillingPeriodService). Null, wenn nichts fehlt.
     *
     * @param array<string,mixed> $running
     * @param list<array<string,mixed>> $months   Prognosemonate
     * @param list<array<string,mixed>> $history  Monatszeilen (ConsumptionService::forMeter)
     * @return array{days:int,cost:float,advance:float}|null
     */
    public static function restOfMonth(array $running, array $months, array $history): ?array
    {
        $asOf = (string)($running['as_of'] ?? '');
        $until = (string)($running['until'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $asOf >= $until) return null;
        $ym = substr($asOf, 0, 7);
        if (($months[0]['ym'] ?? '') <= $ym) return null;   // die Prognose deckt den Monat
        $row = null;
        foreach ($history as $h) if ((string)($h['ym'] ?? '') === $ym) $row = $h;
        $end = min(date('Y-m-t', (int)strtotime($asOf)), $until);
        $days = (int)round((strtotime($end . ' 12:00') - strtotime($asOf . ' 12:00')) / 86400);
        if ($row === null || $days <= 0 || (int)($row['days'] ?? 0) <= 0) return null;
        $dim = (int)date('t', (int)strtotime($asOf));
        $energy = (float)($row['kwh_cost'] ?? 0.0) / (int)$row['days'] * $days;
        $base = (float)($row['base_price_eur'] ?? 0.0) * $days / $dim;
        // v3.9.0 (auswertung, Nachtrag) — tagesanteilig, Raten mit Rhythmus aber an ihrem Tag (ConsumptionService::advanceBetween)
        $adv = ConsumptionService::advanceBetween($row, date('Y-m-d', (int)strtotime($asOf . ' +1 day')), date('Y-m-d', (int)strtotime($end . ' +1 day')), []);
        return ['days' => $days, 'cost' => round($energy + $base, 2), 'advance' => round($adv, 2)];
    }

    /**
     * Angebote des Zählers: Schattenverträge (ohne dynamische Preise) mit ihren
     * Monatspreisen nach der Regel der Wechselentscheidung.
     *
     * @return list<array{id:string,prices:?list<array{wp:float,bp:float}>}>
     */
    private function offers(string $utility, array $meter, array $months): array
    {
        if ($utility === 'wasser') return [];
        $out = [];
        foreach ($this->contracts->list($utility, (string)$meter['id']) as $c) {
            if (empty($c['is_shadow']) || ($c['price_model'] ?? null) === 'dynamic') continue;
            $c = $this->forecast->contractView($utility, $meter, $c);
            $out[] = ['id' => (string)$c['id'], 'prices' => $this->priceRows($c, $months)];
        }
        return $out;
    }

    /** Vertrag des Zählers nach id (echt oder Schatten), in der Sicht der Prognose. */
    private function contractById(string $utility, array $meter, string $id): ?array
    {
        foreach ($this->contracts->list($utility, (string)$meter['id']) as $c) {
            if ((string)($c['id'] ?? '') === $id) return $this->forecast->contractView($utility, $meter, $c);
        }
        return null;
    }

    /**
     * Monatspreise eines Vertrags (Regel der Wechselentscheidung). Null, wenn
     * einem Monat der Arbeitspreis fehlt.
     *
     * @return list<array{wp:float,bp:float}>|null
     */
    private function priceRows(array $c, array $months): ?array
    {
        $out = [];
        foreach ($months as $m) {
            $wp = $this->tariffSwitch->offerPrice($c, 'working_prices', 'ct_per_kwh', $m['year'], $m['month']);
            if ($wp === null) return null;
            $bp = $this->tariffSwitch->offerPrice($c, 'base_prices', 'eur_per_month', $m['year'], $m['month']) ?? 0.0;
            $out[] = ['wp' => $wp, 'bp' => $bp];
        }
        return $out;
    }

    /**
     * Voreinstellungen für den Winter-Regler: „kalt“ = jeder zehnte Winter ist
     * kälter, „mild“ spiegelbildlich — aus der Streuung der Jahres-Heizgradtage
     * im Klimanormal (30 Jahre). Ohne Klimanormal null: Wenige Jahre eigener
     * Temperaturen tragen keine Aussage über seltene Winter.
     *
     * @return array{cold_pct:float,mild_pct:float,source:string}|null
     */
    private function winterPresets(float $base): ?array
    {
        $sd = $this->consumption->hddNormalYearSd($base);
        if ($sd === null) return null;
        $normals = $this->consumption->hddNormals($base);
        if (count($normals) < 12) return null;
        $mean = array_sum(array_map(fn($n) => (float)$n['mean'], $normals));
        if ($mean <= 0) return null;
        $pct = round(self::COLD_Z * $sd / $mean * 100);
        if ($pct <= 0) return null;
        return ['cold_pct' => (float)$pct, 'mild_pct' => -(float)$pct, 'source' => 'climate_normal'];
    }
}
