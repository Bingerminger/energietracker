<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;

/**
 * Wechselentscheidung — v2.3.0.
 *
 * Beantwortet die Frage, um die es im Energietracker eigentlich geht:
 * **Soll ich den Anbieter wechseln?**
 *
 * ── Abgrenzung zum TariffComparisonService ──────────────────────────────
 *
 * `TariffComparisonService` schaut zurück: Es legt einen Tarif über die
 * tatsächlich gemessenen Monate und rechnet aus, was man bezahlt hätte. Das
 * ist der Vertrauensanker — echte Zahlen, keine Annahmen.
 *
 * Dieser Service schaut nach vorn: Er nimmt die Verbrauchsprognose, setzt sie
 * in ein Vergleichsfenster ab dem frühestmöglichen Wechseltermin und stellt
 * die Kandidaten gegenüber. Beide Sichten gehören in dieselbe Oberfläche —
 * die Vergangenheit belegt, dass die Prognose trägt.
 *
 * ── Der Ablauf, den das Modul stützt ────────────────────────────────────
 *
 *  1. Die Prognose liefert den erwarteten Jahresverbrauch. Genau diese Zahl
 *     verlangen CHECK24 und Verivox als Eingabe.
 *  2. Der Nutzer sucht dort selbst. Es gibt bewusst keine API-Anbindung.
 *  3. Er trägt das gefundene Angebot als Schattenvertrag ein.
 *  4. Hier wird gerechnet: was der Bestandsvertrag kostet, was das Angebot
 *     kostet, und ab welchem Verbrauch die Rangfolge kippt.
 *
 * ── Vier Festlegungen, die das Ergebnis prägen ──────────────────────────
 *
 * **Fenster = 12 Monate ab Wechseltermin.** Dieselbe Bezugsgröße, die die
 * Portale anzeigen — nur so sind die Zahlen gegenprüfbar. Der Verbrauch wird
 * saisonal verteilt, nicht linear: Ein Gaswechsel zum 1. Juli deckt trotzdem
 * einen vollen Winter ab, und genau daran scheitert eine Zwölftelrechnung.
 *
 * **Bonus zählt nur im ersten Jahr.** Die Rangfolge richtet sich nach dem
 * dauerhaften Preis (Jahr 2), sonst gewinnt jedes Lockangebot. Die Jahr-1-Zahl
 * steht daneben, damit sie sich mit der Portalanzeige abgleichen lässt.
 *
 * **Referenz ist der fortgeschriebene Bestandsvertrag** mit seinen heutigen
 * Preisen. Konservativ und nachvollziehbar; ab Vertragsende ist es eine
 * Annahme und wird als solche gekennzeichnet.
 *
 * **Ein Schattenvertrag ist ein Tarif, kein Zeitraum.** Seine Preise werden
 * auf das Vergleichsfenster angewandt, unabhängig vom eingetragenen Start.
 * Andernfalls müsste der Nutzer das Startdatum exakt auf den Wechseltermin
 * setzen, den die App ihm gerade erst ausgerechnet hat.
 *
 * Wasser (Drei-Komponenten-Tarif) und die lieferbasierten Arten bleiben außen
 * vor — dort ist die Fragestellung eine andere.
 */
final class TariffSwitchService
{
    /** Vergleichsfenster in Monaten. Entspricht der Portal-Anzeige. */
    private const WINDOW_MONTHS = 12;

    /** Verbrauchsabweichung für die Empfindlichkeitsangabe. */
    private const SENSITIVITY = 0.10;

    public function __construct(
        private ForecastService $forecast,
        private ContractService $contracts,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /**
     * @param array{switch_date?: ?string, today?: ?string} $opts
     * @return array<string,mixed>
     */
    public function analyze(string $utility, string $meterId, array $opts = []): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.common.unknownUtility', ['utility' => $utility])
            );
        }
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }

        $u     = Utilities::get($utility);
        $unit  = (string)($u['consumption_unit'] ?? 'kWh');
        $today = (string)($opts['today'] ?? date('Y-m-d'));

        if ($utility === 'wasser') {
            return $this->unsupported($utility, $meterId, $unit, $today,
                $this->i18n->t('errors.tariff.waterUnsupported'));
        }
        if (Utilities::isDelivery($utility) || !Utilities::hasContracts($utility)) {
            return $this->unsupported($utility, $meterId, $unit, $today,
                $this->i18n->t('errors.tariff.deliveryUnsupported', ['label' => $u['label']]));
        }

        $all    = $this->contracts->list($utility, $meterId);
        $real   = array_values(array_filter($all, fn($c) => empty($c['is_shadow'])));
        $shadow = array_values(array_filter($all, fn($c) => !empty($c['is_shadow'])));

        // ── Wechseltermin ───────────────────────────────────────────────
        $current = $this->contracts->findActiveForDate($real, $today);
        $timing  = $current ? $this->contracts->switchTiming($current, $today) : null;

        $override = trim((string)($opts['switch_date'] ?? ''));
        if ($override !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $override)) {
            $switchDate = $override;
            $source     = 'override';
        } elseif ($timing && $timing['switch_date'] !== null) {
            $switchDate = $timing['switch_date'];
            $source     = 'contract';
        } else {
            // Ohne gepflegte Frist keinen Termin erfinden: Der nächste
            // Monatserste ist als Platzhalter erkennbar und rechnet trotzdem.
            $switchDate = date('Y-m-01', strtotime($today . ' +1 month'));
            $source     = 'default';
        }

        // ── Erwarteter Verbrauch je Kalendermonat ───────────────────────
        $seasonal = $this->seasonalExpectation($utility, $meter);
        if ($seasonal === null) {
            return $this->unsupported($utility, $meterId, $unit, $today,
                $this->i18n->t('errors.forecast.tooFewMonths'));
        }

        $window = $this->buildWindow($switchDate, $seasonal['by_month']);
        $expectedConsumption = array_sum(array_column($window, 'consumption'));

        // ── Kandidaten ──────────────────────────────────────────────────
        $candidates = [];
        if ($current) {
            $candidates[] = $this->evaluate($current, $window, $unit, true);
        }
        foreach ($shadow as $s) {
            $candidates[] = $this->evaluate($s, $window, $unit, false);
        }

        $reference = null;
        foreach ($candidates as $c) {
            if ($c['is_reference']) { $reference = $c; break; }
        }

        foreach ($candidates as &$c) {
            $c = $this->compareToReference($c, $reference, $window, $expectedConsumption);
        }
        unset($c);

        // Referenz bleibt oben; die Angebote dahinter nach dem DAUERHAFTEN
        // Preis sortiert. Nach Jahr 1 zu sortieren würde Lockangebote
        // belohnen — genau die Verzerrung, die das Modul auflösen soll.
        usort($candidates, function ($a, $b) {
            if ($a['is_reference'] !== $b['is_reference']) return $b['is_reference'] <=> $a['is_reference'];
            return ($a['year2_eur'] ?? PHP_FLOAT_MAX) <=> ($b['year2_eur'] ?? PHP_FLOAT_MAX);
        });

        return [
            'utility'   => $utility,
            'meter_id'  => $meterId,
            'unit'      => $unit,
            'supported' => true,
            'note'      => $candidates ? null : $this->i18n->t('errors.tariff.noContracts'),
            'today'     => $today,
            'current'   => $current ? [
                'contract_id'          => (string)$current['id'],
                'label'                => $this->labelFor($current),
                'provider'             => (string)($current['provider'] ?? ''),
                'tariff_name'          => (string)($current['tariff_name'] ?? ''),
                'end'                  => $current['end'] ?? null,
                'notice_period_months' => $current['notice_period_months'] ?? null,
                'switch_date'          => $timing['switch_date'] ?? null,
                'cancel_by'            => $timing['cancel_by'] ?? null,
                'days_to_cancel'       => $timing['days_to_cancel'] ?? null,
                'basis'                => $timing['basis'] ?? 'unknown',
            ] : null,
            'switch_date'        => $switchDate,
            'switch_date_source' => $source,
            'window' => [
                'from'   => $window[0]['ym'],
                'to'     => $window[count($window) - 1]['ym'],
                'months' => count($window),
            ],
            'expected_consumption' => round($expectedConsumption, 1),
            'forecast'             => $seasonal['quality'],
            'candidates'           => array_values($candidates),
        ];
    }

    /**
     * Erwarteter Verbrauch je Kalendermonat (1–12) aus der Prognose.
     *
     * Die Prognose läuft ab dem Monat nach der letzten Ablesung. Daraus wird
     * eine Saisontabelle gebaut, statt die Prognosemonate direkt zu verwenden:
     * So lässt sich das Vergleichsfenster beliebig platzieren — auch weit in
     * der Zukunft, wenn die Kündigungsfrist lang ist — und das zweite Jahr
     * fällt ohne zusätzlichen Prognosehorizont mit ab.
     *
     * Dass Jahr 2 dieselben Mengen ansetzt wie Jahr 1, ist Absicht: Alles
     * andere wäre eine Trendextrapolation über zwei Jahre, für die die
     * Datenbasis nicht reicht.
     *
     * @return array{by_month: array<int,float>, quality: array<string,mixed>}|null
     */
    private function seasonalExpectation(string $utility, array $meter): ?array
    {
        $fc = $this->forecast->forMeter($utility, $meter, ['forecast_months' => self::WINDOW_MONTHS]);
        if (empty($fc['valid']) || empty($fc['forecast'])) {
            return null;
        }

        $u          = Utilities::get($utility);
        $valueField = ($u['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';

        $byMonth = [];
        foreach ($fc['forecast'] as $m) {
            $mn = (int)($m['month'] ?? 0);
            if ($mn < 1 || $mn > 12) continue;
            // Erster Treffer gewinnt — bei einem 12-Monats-Horizont kommt
            // ohnehin jeder Kalendermonat genau einmal vor.
            if (!isset($byMonth[$mn])) $byMonth[$mn] = (float)($m[$valueField] ?? 0);
        }
        if (count($byMonth) < 12) {
            // Lücken mit dem Mittel der vorhandenen Monate füllen, damit ein
            // kurzer Horizont das Fenster nicht künstlich billig macht.
            $avg = $byMonth ? array_sum($byMonth) / count($byMonth) : 0.0;
            for ($i = 1; $i <= 12; $i++) {
                if (!isset($byMonth[$i])) $byMonth[$i] = $avg;
            }
        }
        ksort($byMonth);

        return [
            'by_month' => $byMonth,
            'quality'  => [
                'blend_weight'      => $fc['blend_weight'] ?? null,
                'r2'                => $fc['regression']['r2'] ?? null,
                'months_of_history' => count($fc['historical'] ?? []),
                'annual_total'      => round(array_sum($byMonth), 1),
            ],
        ];
    }

    /**
     * Das Vergleichsfenster: WINDOW_MONTHS Monate ab dem Monat des
     * Wechseltermins, jeder mit seinem saisonal erwarteten Verbrauch.
     *
     * @param array<int,float> $byMonth
     * @return array<int,array{ym:string,year:int,month:int,consumption:float}>
     */
    private function buildWindow(string $switchDate, array $byMonth): array
    {
        $cursor = new \DateTimeImmutable(substr($switchDate, 0, 7) . '-01');
        $window = [];
        for ($i = 0; $i < self::WINDOW_MONTHS; $i++) {
            $mn = (int)$cursor->format('n');
            $window[] = [
                'ym'          => $cursor->format('Y-m'),
                'year'        => (int)$cursor->format('Y'),
                'month'       => $mn,
                'consumption' => (float)($byMonth[$mn] ?? 0.0),
            ];
            $cursor = $cursor->add(new \DateInterval('P1M'));
        }
        return $window;
    }

    /**
     * Kosten eines Kandidaten über das Fenster — einmal für das erste Jahr
     * (mit Neukundenbonus) und einmal dauerhaft (ohne).
     *
     * @param array<int,array<string,mixed>> $window
     * @return array<string,mixed>
     */
    private function evaluate(array $c, array $window, string $unit, bool $isReference): array
    {
        $guaranteeUntil = $c['price_guarantee_until'] ?? null;
        $monthly        = [];
        $total          = 0.0;
        $consumption    = 0.0;
        $beyondAny      = false;
        $computable     = true;

        foreach ($window as $m) {
            $wp = $this->priceForWindow($c, 'working_prices', 'ct_per_kwh', $m['year'], $m['month']);
            if ($wp === null) { $computable = false; break; }
            $bp = $this->priceForWindow($c, 'base_prices', 'eur_per_month', $m['year'], $m['month']) ?? 0.0;

            $cost   = $m['consumption'] * $wp / 100.0 + $bp;
            $total += $cost;
            $consumption += $m['consumption'];

            // Nach Ablauf der Preisgarantie ist der Preis eine Annahme. Der
            // Wert wird trotzdem fortgeschrieben — er wird nur markiert, damit
            // die Oberfläche ihn als unsicher darstellen kann.
            $beyond = $guaranteeUntil !== null && ($m['ym'] . '-01') > $guaranteeUntil;
            if ($beyond) $beyondAny = true;

            $monthly[] = [
                'ym'               => $m['ym'],
                'consumption'      => round($m['consumption'], 1),
                'cost'             => round($cost, 2),
                'working_price_ct' => round($wp, 4),
                'beyond_guarantee' => $beyond,
            ];
        }

        if (!$computable) {
            return [
                'contract_id'      => (string)$c['id'],
                'label'            => $this->labelFor($c),
                'is_shadow'        => !empty($c['is_shadow']),
                'is_reference'     => $isReference,
                'computable'       => false,
                'note'             => $this->i18n->t('errors.tariff.noWorkingPrice'),
                'year1_eur'        => null,
                'year2_eur'        => null,
                'signup_bonus_eur' => null,
                'monthly'          => [],
            ];
        }

        $bonus = (float)($c['signup_bonus_eur'] ?? 0.0);

        return [
            'contract_id'  => (string)$c['id'],
            'label'        => $this->labelFor($c),
            'provider'     => (string)($c['provider'] ?? ''),
            'tariff_name'  => (string)($c['tariff_name'] ?? ''),
            'is_shadow'    => !empty($c['is_shadow']),
            'is_reference' => $isReference,
            'computable'   => true,
            'note'         => null,

            // Jahr 1 mit Bonus, Jahr 2 ohne — beide über dieselbe Menge.
            'year1_eur'        => round($total - $bonus, 2),
            'year2_eur'        => round($total, 2),
            'signup_bonus_eur' => $bonus > 0 ? round($bonus, 2) : null,

            'consumption'        => round($consumption, 1),
            'unit_cost_ct_year1' => $consumption > 0 ? round(($total - $bonus) * 100.0 / $consumption, 3) : null,
            'unit_cost_ct_year2' => $consumption > 0 ? round($total * 100.0 / $consumption, 3) : null,

            'guarantee_until'          => $guaranteeUntil,
            'guarantee_ends_in_window' => $beyondAny,

            'monthly' => $monthly,
        ];
    }

    /**
     * Arbeits- bzw. Grundpreis eines Kandidaten für einen Fenstermonat.
     *
     * Gilt für den Monat ein gepflegter Preis, wird er genommen. Sonst greift
     * der früheste Eintrag: Ein eingetragenes Angebot beschreibt einen Tarif,
     * der ab dem Wechsel gelten soll — sein Startdatum liegt beim Anlegen
     * naturgemäß noch nicht auf dem Wechseltermin.
     */
    private function priceForWindow(array $c, string $group, string $field, int $year, int $month): ?float
    {
        $entries = $c[$group] ?? [];
        if (!is_array($entries) || !$entries) return null;

        $v = $this->contracts->valueValidOn($entries, $field, $year, $month);
        if ($v !== null) return $v;

        $sorted = $entries;
        usort($sorted, fn($a, $b) => strcmp((string)($a['from'] ?? ''), (string)($b['from'] ?? '')));
        foreach ($sorted as $e) {
            if (isset($e[$field]) && is_numeric($e[$field])) return (float)$e[$field];
        }
        return null;
    }

    /**
     * Differenz zur Referenz, Break-even-Verbrauch und Empfindlichkeit.
     *
     * Der Break-even ist die ehrlichste Antwort auf eine unsichere Prognose:
     * Statt eine Ersparnis auf den Euro genau zu behaupten, nennt er die
     * Verbrauchsmenge, ab der die Rangfolge kippt. Liegt die weit weg vom
     * erwarteten Verbrauch, ist die Entscheidung robust; liegt sie knapp
     * daneben, ist sie es nicht — und das sieht man sofort.
     *
     * Gerechnet wird über die proportionale Skalierung des Fensters: Die
     * Monatsverteilung bleibt, nur die Gesamtmenge wandert. Damit bleiben
     * saisonale Preisstaffeln korrekt gewichtet.
     *
     * @param array<int,array<string,mixed>> $window
     * @return array<string,mixed>
     */
    private function compareToReference(array $cand, ?array $reference, array $window, float $expected): array
    {
        $cand['vs_reference_year1_eur'] = null;
        $cand['vs_reference_year2_eur'] = null;
        $cand['vs_reference_year2_pct'] = null;
        $cand['break_even_consumption'] = null;
        $cand['break_even_delta_pct']   = null;
        $cand['sensitivity']            = null;

        if (empty($cand['computable'])) return $cand;

        // Empfindlichkeit gilt auch ohne Referenz — sie beschreibt den
        // Kandidaten selbst.
        $cand['sensitivity'] = [
            'low'  => round($this->costAtScale($cand, $window, 1.0 - self::SENSITIVITY), 2),
            'high' => round($this->costAtScale($cand, $window, 1.0 + self::SENSITIVITY), 2),
            'pct'  => self::SENSITIVITY,
        ];

        if ($reference === null || empty($reference['computable']) || $cand['is_reference']) {
            return $cand;
        }

        $cand['vs_reference_year1_eur'] = round($cand['year1_eur'] - $reference['year1_eur'], 2);
        $cand['vs_reference_year2_eur'] = round($cand['year2_eur'] - $reference['year2_eur'], 2);
        if ($reference['year2_eur'] != 0.0) {
            $cand['vs_reference_year2_pct'] =
                round(($cand['year2_eur'] - $reference['year2_eur']) / $reference['year2_eur'] * 100.0, 1);
        }

        // Kosten(s) sind in der Skalierung s linear: Grundpreis bleibt, der
        // Arbeitspreisanteil wächst proportional. Zwei Stützstellen genügen,
        // um den Schnittpunkt exakt zu bestimmen.
        $c0 = $this->costAtScale($cand, $window, 0.0);
        $c1 = $this->costAtScale($cand, $window, 1.0);
        $r0 = $this->costAtScale($reference, $window, 0.0);
        $r1 = $this->costAtScale($reference, $window, 1.0);

        $slopeC = $c1 - $c0;
        $slopeR = $r1 - $r0;
        if (abs($slopeC - $slopeR) > 1e-9 && $expected > 0) {
            $s = ($r0 - $c0) / ($slopeC - $slopeR);
            if ($s > 0 && $s < 10) {
                $cand['break_even_consumption'] = round($s * $expected, 0);
                $cand['break_even_delta_pct']   = round(($s - 1.0) * 100.0, 1);
            }
        }

        return $cand;
    }

    /**
     * Kosten des Kandidaten, wenn der Verbrauch jedes Monats mit `$scale`
     * skaliert wird. Bonus bleibt außen vor — verglichen wird der dauerhafte
     * Preis (Jahr 2).
     *
     * @param array<int,array<string,mixed>> $window
     */
    private function costAtScale(array $cand, array $window, float $scale): float
    {
        $sum = 0.0;
        foreach ($cand['monthly'] as $i => $m) {
            $base = $window[$i] ?? null;
            if ($base === null) continue;
            // cost = consumption * wp/100 + bp  →  der Grundpreisanteil ist
            // die Differenz, die bei Menge 0 übrig bleibt.
            $variable = (float)$m['consumption'] * (float)$m['working_price_ct'] / 100.0;
            $fixed    = (float)$m['cost'] - $variable;
            $sum     += $variable * $scale + $fixed;
        }
        return $sum;
    }

    private function labelFor(array $c): string
    {
        if (!empty($c['is_shadow'])) {
            $l = (string)($c['shadow_label'] ?: $c['tariff_name'] ?: '');
            return $l !== '' ? $l : $this->i18n->t('tariff.shadowFallbackLabel');
        }
        $l = trim(((string)($c['provider'] ?? '')) . ' ' . ((string)($c['tariff_name'] ?? '')));
        return $l !== '' ? $l : (string)$c['id'];
    }

    /** @return array<string,mixed> */
    private function unsupported(string $utility, string $meterId, string $unit, string $today, string $note): array
    {
        return [
            'utility'              => $utility,
            'meter_id'             => $meterId,
            'unit'                 => $unit,
            'supported'            => false,
            'note'                 => $note,
            'today'                => $today,
            'current'              => null,
            'switch_date'          => null,
            'switch_date_source'   => null,
            'window'               => ['from' => null, 'to' => null, 'months' => 0],
            'expected_consumption' => null,
            'forecast'             => null,
            'candidates'           => [],
        ];
    }
}
