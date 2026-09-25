<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;

/**
 * Monthly consumption aggregation.
 *
 * F2 (Zählertausch) handling:
 *   When two consecutive readings sit on DIFFERENT devices of the same meter,
 *   the consumption between them is split:
 *     part_a = old_device.final_counter - prev_reading.counter
 *     part_b = curr_reading.counter   - new_device.initial_counter
 *     total  = (part_a + part_b) × conversion_factor
 *   This is distributed across days as if it were one continuous interval.
 *
 * F3 (multiple meters) handling:
 *   Each meter is aggregated INDEPENDENTLY. The "utility total" sums across
 *   active meters of the same utility. Each meter can have its own contract.
 *
 * Output is per-month, per-meter consumption plus a utility-aggregated view.
 */
final class ConsumptionService
{
    /**
     * Untergrenzen der Wetterbereinigung. Öffentlich, damit der F1011-Hinweis
     * dieselben Zahlen nennt, die hier wirklich greifen — bis v2.3.5 standen
     * beide als nackte Literale im Rechenweg und schlugen wortlos zu.
     */
    public const MIN_MONTHS_WEATHER     = 12;
    public const MIN_POINTS_REGRESSION  = 8;

    /**
     * Meter ids currently being computed — recursion guard for the
     * Schmutzwasser `separater_zaehler` lookup (a meter referencing another
     * meter as its waste-water measurement basis). Prevents infinite
     * recursion if two meters reference each other.
     *
     * @var string[]
     */
    private array $meterComputeStack = [];

    /**
     * v2.6.0 — Warnungen der Plausibilitätsprüfung je Zähler dieser Anfrage
     * („utility|meterId" → Liste), gefüllt von computeForMeter().
     *
     * @var array<string, list<array<string,mixed>>>
     */
    private array $readingWarnings = [];

    /** @var array<string,int> Schreibgeneration, zu der die Warnungen galten */
    private array $readingWarningsGen = [];

    /**
     * v2.6.0 — Ergebnis von forMeter() je Zähler und Anfrage. Empfehlungen,
     * PDF und Effizienz rechneten denselben Zähler bisher mehrfach; bei zehn
     * Jahren Tagesdaten waren das Sekunden. Nur innerhalb einer Anfrage —
     * jede Änderung ist eine eigene Anfrage.
     *
     * @var array<string, array<int,array<string,mixed>>>
     */
    private array $forMeterMemo = [];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ReadingService $readings,
        private ContractService $contracts,
        private SettingsService $settings,
        private I18nService $i18n,
        private ?RegressionService $regression = null,
        private ?DeliveryConsumptionService $deliveryConsumption = null,
        private ?ConversionFactorService $factors = null,
        private ?ClimateNormalService $climate = null,
    ) {}

    /**
     * v2.5.0 — F1012: Lazy-Getter für die datierten Umrechnungsfaktoren.
     * Optional injiziert, damit die Test-Basisklasse unverändert bleibt.
     */
    private function factors(): ConversionFactorService
    {
        return $this->factors ??= new ConversionFactorService($this->settings, $this->i18n);
    }

    /**
     * Compute monthly aggregates for all meters of a utility.
     *
     * @return array{
     *   meters: array<int,array{meter:array,monthly:array<int,array>}>,
     *   monthly_total: array<int,array>
     * }
     */
    public function forUtility(string $utility, ?float $hddBaseOverride = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        $u = Utilities::get($utility);
        $perMeter = [];

        foreach ($this->meters->list($utility) as $meter) {
            $monthly = $this->forMeter($utility, $meter, $hddBaseOverride);
            $perMeter[] = ['meter' => $meter, 'monthly' => $monthly];
        }

        // v1.2.0 — F1006 Meter-Topologie: Bei Reihenschaltung misst der
        // Elternzähler den Brutto-Verbrauch INKLUSIVE seiner Subzähler. Der
        // Subzähler ist nur eine Aufschlüsselung eines Teils davon — er darf
        // deshalb NICHT zusätzlich in die Utility-Gesamtsumme einfließen,
        // sonst wird der Subzähler-Anteil doppelt gezählt. In `monthly_total`
        // fließen also nur Zähler OHNE `parent_meter_id` ein.
        $totals = [];
        foreach ($perMeter as $entry) {
            if (($entry['meter']['parent_meter_id'] ?? null) !== null) {
                continue; // Subzähler: bereits im Elternzähler enthalten
            }
            foreach ($entry['monthly'] as $m) {
                $ym = $m['ym'];
                if (!isset($totals[$ym])) {
                    $totals[$ym] = [
                        'ym' => $ym, 'year' => $m['year'], 'month' => $m['month'],
                        'kwh' => 0.0, 'm3' => 0.0, 'cost' => 0.0, 'days' => 0,
                        'avg_temp' => $m['avg_temp'], 'min_temp' => $m['min_temp'],
                        'max_temp' => $m['max_temp'], 'hdd' => $m['hdd'],
                        // v2.5.3 — CALC-16: Der CSV-Monatsexport las diese
                        // Felder aus der Summe, sie fehlten dort aber — die
                        // Spalten Abschlag, Saldo und CO₂ blieben immer leer.
                        'advance_eur' => null, 'monthly_balance' => null,
                        'cumulative_balance' => null, 'co2_kg' => null,
                    ];
                }
                $totals[$ym]['kwh']  += (float)($m['kwh']  ?? 0);
                $totals[$ym]['m3']   += (float)($m['m3']   ?? 0);
                $totals[$ym]['cost'] += (float)($m['cost'] ?? 0);
                $totals[$ym]['days'] = max($totals[$ym]['days'], (int)($m['days'] ?? 0));
                foreach (['advance_eur', 'monthly_balance', 'cumulative_balance', 'co2_kg'] as $f) {
                    if (($m[$f] ?? null) !== null) {
                        $totals[$ym][$f] = round((float)($totals[$ym][$f] ?? 0.0) + (float)$m[$f], 2);
                    }
                }
            }
        }
        ksort($totals);
        $totalsList = array_values($totals);

        // Add moving averages to the totals
        $totalsList = $this->addMovingAverages($totalsList, $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3');

        return [
            'utility'      => $u,
            'meters'       => $perMeter,
            'monthly_total'=> $totalsList,
            // v1.2.0 — F1006: Gruppen-Stammdaten für die aufklappbare
            // Darstellung im Frontend (Mitgliedschaft steckt an den Metern).
            'meter_groups' => $this->meters->listGroups($utility),
        ];
    }

    /**
     * Per-contract aggregation for the Saldo / Vertragstabelle UI.
     *
     * For every contract of the given meter, compute:
     *   - actual_kwh, actual_kwh_cost, actual_base_total, actual_bonus_total, actual_cost
     *   - advance_paid (monthly advances summed across actual months)
     *   - current_balance  = actual_cost − advance_paid  (positive = Nachzahlung)
     *   - projected_end_balance = balance extrapolated until contract end
     *   - verdict ∈ { surcharge | refund | balanced } — Einspeisung: { payout | reclaim | balanced }
     *     (Sprach-Keys seit v2.0.0, s. unten)
     *   - current_working_price_ct / current_base_price_eur / current_advance_amount
     *     (the values valid today, for the active contract)
     *   - is_current / is_past / is_future / is_open_ended / effective_end
     *
     * Future months past `today` are projected using the average kwh/HGT of the
     * actual months in this contract (linear with seasonality from HDD).
     */
    public function contractStatus(string $utility, array $meter): array
    {
        $contracts = $this->contracts->list($utility, $meter['id']);
        // v1.3.0 — Schattenverträge tauchen im Vertragsstatus nicht auf
        // (rein hypothetisch; gehören in den Tarifvergleich).
        $contracts = array_values(array_filter(
            $contracts, fn($c) => empty($c['is_shadow'])
        ));
        $monthly   = $this->forMeter($utility, $meter);
        $today     = date('Y-m-d');
        // F1005 / P-PV-01 — PV-Einspeisung: der „Saldo" ist ein Vergütungs-
        // Erlös (Gutschrift), keine Kosten. Verdict-Achse und Projektions-
        // Horizont werden weiter unten entsprechend gedreht/begrenzt.
        $isFeedIn  = Utilities::isFeedIn($utility);

        // Index monthly by (contract_id, ym)
        $byContract = [];
        foreach ($monthly as $m) {
            $cid = $m['contract_id'] ?? null;
            if (!$cid) continue;
            $byContract[$cid][] = $m;
        }

        $out = [];
        foreach ($contracts as $c) {
            $cid     = $c['id'];
            $mList   = $byContract[$cid] ?? [];
            $isCur   = ($c['start'] ?? '9999') <= $today && (empty($c['end']) || $c['end'] >= $today);
            $isPast  = !empty($c['end']) && $c['end'] < $today;
            $isFut   = ($c['start'] ?? '9999') > $today;
            $isOpen  = empty($c['end']);
            // F-03: a contract with a pflegtem Ende ends on that date. An open
            // contract (end = null) is projected to the next billing-cycle
            // anchor of its utility (Settings: billing_cycle_anchor_<utility>),
            // not the arbitrary "today + 12 months" of v1.0.x.
            // P-PV-01 — feed_in: immer bis zur nächsten Jahresabrechnung
            // projizieren, nie bis Vertragsende. Ein EEG-Vertrag läuft 20
            // Jahre (z.B. bis 2043); die alte Logik hätte die Vergütung über
            // die gesamte Restlaufzeit hochgerechnet (z.B. „+10.756 €
            // erwartet"), was als „nächste Abrechnung" grob irreführend ist.
            $effEnd  = $isFeedIn
                ? min(
                    !empty($c['end']) ? $c['end'] : '9999-12-31',
                    $this->nextBillingAnchor($utility, $today)
                  )
                : (!empty($c['end'])
                    ? $c['end']
                    : $this->nextBillingAnchor($utility, $today));

            // Aggregate over the months we actually have for this contract
            $actualKwh   = 0.0; $actualCost = 0.0; $actualKwhCost = 0.0;
            $actualBase  = 0.0; $actualBonus = 0.0; $advancePaid = 0.0;
            // Water component aggregates (v1.0.3)
            $twWorking = 0.0; $twBase = 0.0; $twTotal = 0.0;
            $swTotal   = 0.0;
            $nwTotal   = 0.0;
            $actualM3  = 0.0;
            foreach ($mList as $m) {
                $actualKwh     += (float)($m['kwh'] ?? 0);
                $actualM3      += (float)($m['m3']  ?? 0);
                $actualCost    += (float)($m['cost'] ?? 0);
                $actualKwhCost += (float)($m['kwh_cost'] ?? 0);
                $actualBase    += (float)($m['base_price_eur'] ?? 0);
                $actualBonus   += (float)($m['bonus_eur'] ?? 0);
                $advancePaid   += (float)($m['advance_eur'] ?? 0);
                if ($utility === 'wasser') {
                    $tw = $m['trinkwasser']         ?? null;
                    $sw = $m['schmutzwasser']       ?? null;
                    $nw = $m['niederschlagswasser'] ?? null;
                    if (is_array($tw)) {
                        $twWorking += (float)($tw['working_cost'] ?? 0);
                        $twBase    += (float)($tw['base_cost']    ?? 0);
                        $twTotal   += (float)($tw['total']        ?? 0);
                    }
                    if (is_array($sw)) $swTotal += (float)($sw['total'] ?? 0);
                    if (is_array($nw)) $nwTotal += (float)($nw['total'] ?? 0);
                }
            }
            $monthsActual = count($mList);
            // F1003 — Sonderzahlungen verschieben den Saldo:
            //   current_balance = Kosten − gezahlte Abschläge + special_net
            //   special_net = Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung
            // Wasser hat keine special_payments → summary.net = 0 (No-op).
            $spSummary = $this->contracts->specialPaymentSummary($c);
            $currentBalance = round($actualCost - $advancePaid + $spSummary['net'], 2);

            // Current tariff values (those valid today, for the active contract).
            [$y, $mn] = [(int)date('Y'), (int)date('n')];
            if ($utility === 'wasser') {
                $tw = $c['trinkwasser']         ?? [];
                $sw = $c['schmutzwasser']       ?? [];
                $nw = $c['niederschlagswasser'] ?? [];
                $curWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curNwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year',        $y, $mn);
                $curNwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            } else {
                $curWp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
                $curBp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $curNwRate = $curNwArea = null;
            }
            // F1003 — effektiver Abschlagsplan (Wasser hat keine
            // special_payments → effectiveAdvanceSchedule ist dort ein
            // No-op und liefert advance_payments unverändert zurück).
            $curAp = $this->contracts->valueValidOn(
                $this->contracts->effectiveAdvanceSchedule($c), 'amount_eur', $y, $mn
            );

            // Project balance to contract end:
            //   project remaining months at the current avg cost / monthly advance.
            //   for past contracts: projected = current.
            $projected = $currentBalance;
            $projection = null;
            if (Utilities::hasAdvancePaymentContracts($utility)) {
                // v2.8.0 (Review CALC-02) — Saldo nach Kalender statt nach
                // Ablesemonaten, Hochrechnung mit Wetter statt Durchschnitt.
                $projection = $this->balanceProjection($utility, $meter, $c, $monthly, $today, $effEnd, $spSummary['net']);
                $currentBalance = $projection['current_balance'];
                $projected      = $projection['projected_end_balance'];
                $advancePaid    = $projection['advance_paid'];
            } elseif ($isCur || $isFut) {
                $monthsToEnd = max(0, $this->monthsBetween($today, $effEnd));
                $avgMonthlyCost = $monthsActual > 0 ? $actualCost / $monthsActual : 0.0;
                $monthlyAdvance = (float)($curAp ?? 0);
                $delta = ($avgMonthlyCost - $monthlyAdvance) * $monthsToEnd;
                $projected = round($currentBalance + $delta, 2);
            }

            // P-PV-01 — Verdict-Achse für feed_in umgedreht: positiver Saldo
            // ist eine Auszahlung des Netzbetreibers (gut), keine Nachzahlung.
            // N1007 (v2.0.0): verdict als stabiler Sprach-Key statt deutschem
            // String. Das Frontend nutzt den Key für Logik (Farbe/Pfeil) und
            // zeigt das lokalisierte Label via t('utility.verdict.<key>').
            if ($isFeedIn) {
                $verdict = $projected > 5 ? 'payout'
                         : ($projected < -5 ? 'reclaim' : 'balanced');
            } else {
                $verdict = $projected > 5 ? 'surcharge'
                         : ($projected < -5 ? 'refund' : 'balanced');
            }

            // F-05: contract-end reminder. Only meaningful for contracts with a
            // pflegtem Ende that is still in the future — an open contract has
            // no real end to remind about. days_until_end is signed (negative
            // = end already passed). remind_stage ∈ {0,1,2,3}: 0 = no reminder,
            // 1 = within the earliest threshold, 3 = within the last.
            $daysUntilEnd = null;
            $shouldRemind = false;
            $remindStage  = 0;
            if (!empty($c['end'])) {
                try {
                    $diff = (new \DateTime($today))->diff(new \DateTime($c['end']));
                    $daysUntilEnd = (int)$diff->days * ($c['end'] < $today ? -1 : 1);
                } catch (\Exception) {
                    $daysUntilEnd = null;
                }
                if ($daysUntilEnd !== null && $daysUntilEnd >= 0) {
                    $r1 = (int)$this->settings->get('contract_remind_days_1', 90);
                    $r2 = (int)$this->settings->get('contract_remind_days_2', 30);
                    $r3 = (int)$this->settings->get('contract_remind_days_3', 1);
                    if ($daysUntilEnd <= $r3)      { $remindStage = 3; }
                    elseif ($daysUntilEnd <= $r2)  { $remindStage = 2; }
                    elseif ($daysUntilEnd <= $r1)  { $remindStage = 1; }
                    $shouldRemind = $remindStage > 0;
                }
            }

            $row = [
                'contract_id'                 => $cid,
                'provider'                    => $c['provider']    ?? '',
                'tariff_name'                 => $c['tariff_name'] ?? '',
                'start'                       => $c['start']       ?? null,
                'end'                         => $c['end']         ?? null,
                'effective_end'               => $effEnd,
                'is_current'                  => $isCur,
                'is_past'                     => $isPast,
                'is_future'                   => $isFut,
                'is_open_ended'               => $isOpen,
                'current_working_price_ct'    => $curWp,
                'current_base_price_eur'      => $curBp,
                'current_advance_amount'      => $curAp,
                'months_actual'               => $monthsActual,
                'actual_kwh'                  => round($actualKwh, 1),
                'actual_kwh_cost'             => round($actualKwhCost, 2),
                'actual_base_total'           => round($actualBase, 2),
                'actual_bonus_total'          => round($actualBonus, 2),
                'actual_cost'                 => round($actualCost, 2),
                'advance_paid'                => round($advancePaid, 2),
                'current_balance'             => $currentBalance,
                'projected_end_balance'       => $projected,
                'verdict'                     => $verdict,
                // F1003 — Sonderzahlungen (Aufschlüsselung für die UI)
                'special_refund_total'        => $spSummary['refund_total'],
                'special_surcharge_total'     => $spSummary['surcharge_total'],
                'special_advance_total'       => $spSummary['advance_total'],
                'special_payment_net'         => $spSummary['net'],
                'special_payments_count'      => $spSummary['count'],
                // F-05 — contract-end reminder
                'days_until_end'              => $daysUntilEnd,
                'should_remind'               => $shouldRemind,
                'remind_stage'                => $remindStage,
                // v2.8.0 — wie gerechnet wurde (additiv)
                'balance_as_of'               => $projection['as_of'] ?? $today,
                'projection_method'           => $projection !== null ? $projection['method'] : 'flat_average',
            ];
            if ($projection !== null) {
                $row += [
                    'measured_until'           => $projection['measured_until'],
                    'cost_to_date'             => $projection['cost_to_date'],
                    'energy_cost_to_date'      => $projection['energy_cost_to_date'],
                    'base_to_date'             => $projection['base_to_date'],
                    'bonus_to_date'            => $projection['bonus_to_date'],
                    'estimated_cost_to_date'   => $projection['estimated_cost_to_date'],
                    'estimated_cost_remaining' => $projection['estimated_cost_remaining'],
                    'advance_remaining'        => $projection['advance_remaining'],
                    'suggested_advance'        => $projection['suggested_advance'],
                    'projection_factor'        => $projection['factor'],
                ];
            }
            // v2.5.1 — die Einzelposten für die Tabelle „Verträge & Abschläge"
            // (Spalte Sonderzahlungen mit Tooltip). Nur dort, wo es
            // Sonderzahlungen überhaupt gibt (Gas/Strom/Fernwärme, nicht
            // Wasser, nicht Einspeisung) — das Fehlen des Felds ist für die
            // UI das Signal, die Spalte gar nicht erst zu zeigen.
            if (Utilities::hasAdvancePaymentContracts($utility)) {
                $row['special_payments'] = array_values(array_map(fn($sp) => [
                    'date'       => (string)($sp['date'] ?? ''),
                    'kind'       => (string)($sp['kind'] ?? ''),
                    'amount_eur' => round((float)($sp['amount_eur'] ?? 0), 2),  // Vorzeichen steckt in kind; positiv seit dem Speichern
                    'note'       => (string)($sp['note'] ?? ''),
                ], $c['special_payments'] ?? []));
            }
            if ($utility === 'wasser') {
                $row['actual_m3']    = round($actualM3, 1);
                $row['components']   = [
                    'trinkwasser' => [
                        'working_cost'     => round($twWorking, 2),
                        'base_cost'        => round($twBase, 2),
                        'total'            => round($twTotal, 2),
                        'current_ct_per_m3'   => $curWp,
                        'current_eur_per_month' => $curBp,
                    ],
                    'schmutzwasser' => [
                        'total'                => round($swTotal, 2),
                        'current_ct_per_m3'    => $curSwWp,
                        'basis'                => $c['schmutzwasser']['basis'] ?? 'trinkwasser',
                    ],
                    'niederschlagswasser' => [
                        'total'                       => round($nwTotal, 2),
                        'current_eur_per_m2_year'     => $curNwRate,
                        'current_versiegelte_m2'      => $curNwArea,
                        'current_monthly'             => ($curNwRate !== null && $curNwArea !== null)
                            ? round((float)$curNwRate * (float)$curNwArea / 12.0, 2) : null,
                    ],
                ];
            }
            $out[] = $row;
        }

        // Sort by start ascending so the table reads chronologically
        usort($out, fn($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));

        return ['contracts' => $out];
    }

    /**
     * v2.8.0 — Saldo eines Vertrags nach Kalender (Review CALC-02, UI-36).
     *
     * Bis v2.7 zählte der Saldo Kosten **und Abschläge** nur über Monate mit
     * Ablesung; die Zeit zwischen letzter Ablesung und heute fehlte ganz, und
     * der Rest bis zur Abrechnung wurde mit dem flachen Monatsmittel
     * hochgerechnet — ein Sommermittel auf den Winter. Bei seltenem Ablesen lag
     * die „erwartete Endabrechnung" hunderte Euro daneben (+654 € in einem
     * Testfall mit bekannter Wahrheit).
     *
     * Jetzt, je Vertrag über [Beginn, Ende):
     *   Arbeitspreis  gemessen bis zur letzten Ablesung, danach geschätzt:
     *                 Heizarten mit dem Heizmodell (a × HGT + c × Tage; HGT aus
     *                 den Tagestemperaturen, in der Zukunft aus dem Normal),
     *                 sonst mit der Tagesrate desselben Kalendermonats
     *   Grundpreis    tagesanteilig — kein voller Monat für 14 Tage
     *   Abschläge     nach Zahlungsplan bis heute (effektiver Plan mit
     *                 Sonderzahlungen), der laufende Monat ganz, angebrochene
     *                 Vertragsmonate anteilig
     *   Boni          nach Gutschriftmonat
     * `current_balance` ist der Stand heute, `projected_end_balance` der am
     * Vertragsende bzw. zur nächsten Abrechnung. `suggested_advance`: der
     * Monatsabschlag, mit dem die Endabrechnung bei null läge.
     *
     * @return array<string,mixed>
     */
    private function balanceProjection(string $utility, array $meter, array $c, array $monthly, string $today, string $effEnd, float $specialNet): array
    {
        $start = (string)($c['start'] ?? $today);
        // Ende exklusiv: ein gepflegtes Vertragsende gehört noch dazu, der
        // Abrechnungstag eines offenen Vertrags nicht mehr.
        $endExcl = !empty($c['end']) ? date('Y-m-d', strtotime($c['end'] . ' +1 day')) : $effEnd;
        if ($endExcl <= $start) $endExcl = date('Y-m-d', strtotime($start . ' +1 day'));
        $asOf = min(max($today, $start), $endExcl);   // heute, eingeklemmt in den Vertrag

        // Gemessen: Arbeitspreis der Monatszeilen dieses Vertrags
        $measuredEnergy = 0.0;
        foreach ($monthly as $m) {
            if (($m['contract_id'] ?? null) === $c['id']) $measuredEnergy += (float)($m['kwh_cost'] ?? 0);
        }
        $measuredUntil = $this->lastReadingDate($utility, $meter);
        $gapFrom = max($start, $measuredUntil ?? $start);

        $u   = Utilities::get($utility);
        $vf  = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $est = $this->volumeEstimator($utility, $monthly, $vf);
        $fallbackWp = null;
        foreach ($monthly as $m) if (!empty($m['working_price_ct'])) $fallbackWp = (float)$m['working_price_ct'];

        $energy = function (string $from, string $to) use ($c, $est, $fallbackWp): float {
            // v2.8.1 — ohne verwertbare Monate (noch keine zwei Ablesungen,
            // oder alle vor einer Zäsur) gibt es nichts hochzurechnen. In
            // v2.8.0 rief der Saldo hier null auf und brach mit HTTP 500 ab.
            if ($est === null) return 0.0;
            $sum = 0.0;
            foreach ($est($from, $to) as $ym => $vol) {
                [$y, $mo] = array_map('intval', explode('-', $ym));
                $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mo) ?? $fallbackWp ?? 0.0;
                $sum += $vol * (float)$wp / 100.0;
            }
            return $sum;
        };
        $gapEnergy       = $gapFrom < $asOf ? $energy($gapFrom, $asOf) : 0.0;
        $remainingEnergy = $energy(max($asOf, $gapFrom), $endExcl);

        // Grundpreis tagesanteilig, Boni nach Monat, Abschläge nach Plan
        $plan = $this->contracts->effectiveAdvanceSchedule($c);
        $sums = $this->calendarSums($c, $plan, $start, $asOf, $endExcl, $today >= $start);
        ['base' => $baseToDate, 'bonus' => $bonusToDate, 'advance' => $advToDate] = $sums['to_date'];
        ['base' => $baseRest, 'bonus' => $bonusRest, 'advance' => $advRest] = $sums['rest'];

        $costToDate = $measuredEnergy + $gapEnergy + $baseToDate - $bonusToDate;
        $current    = round($costToDate - $advToDate + $specialNet, 2);
        $restCost   = $remainingEnergy + $baseRest - $bonusRest;
        $projected  = round($current + $restCost - $advRest, 2);

        // Abschlagsvorschlag: Rest gleichmäßig auf die verbleibenden Monate
        $suggested = null;
        $monthsLeft = 0;
        for ($t = strtotime(substr($asOf, 0, 7) . '-01 +1 month'); $t !== false && date('Y-m-d', $t) < $endExcl; $t = strtotime('+1 month', $t)) {
            $monthsLeft++;
        }
        $curAp = $this->contracts->valueValidOn($plan, 'amount_eur', (int)substr($today, 0, 4), (int)substr($today, 5, 2));
        if ($monthsLeft >= 1 && $curAp !== null && $asOf === $today) {
            $suggested = max(0.0, round((float)$curAp + $projected / $monthsLeft));
        }

        return [
            'as_of'                    => $asOf,
            'method'                   => $est === null ? 'flat_average' : 'forecast',
            'factor'                   => $est === null ? null : round($this->lastProjectionFactor, 2),
            'measured_until'           => $measuredUntil,
            'cost_to_date'             => round($costToDate, 2),
            // die Teile von cost_to_date — die Oberfläche schlüsselt sie auf
            'energy_cost_to_date'      => round($measuredEnergy + $gapEnergy, 2),
            'base_to_date'             => round($baseToDate, 2),
            'bonus_to_date'            => round($bonusToDate, 2),
            'estimated_cost_to_date'   => round($gapEnergy, 2),
            'estimated_cost_remaining' => round($restCost, 2),
            'advance_paid'             => round($advToDate, 2),
            'advance_remaining'        => round($advRest, 2),
            'current_balance'          => $current,
            'projected_end_balance'    => $projected,
            'suggested_advance'        => $suggested,
        ];
    }

    /**
     * Grundpreis, Boni und Abschläge eines Vertrags, aufgeteilt in „bis heute"
     * und „Rest".
     *
     *   Grundpreis  tagesanteilig: bis heute über [Beginn, heute), Rest über
     *               [heute, Ende)
     *   Boni        nach Gutschriftmonat (bonusForMonth): bis heute die Monate
     *               bis einschließlich des laufenden, Rest die danach
     *   Abschläge   nach Zahlungsplan, dieselbe Monatsaufteilung; der laufende
     *               Monat zählt ganz (fällig am Monatsanfang), angebrochene
     *               Vertragsmonate (Beginn/Ende mitten im Monat) anteilig
     *
     * @return array{to_date:array{base:float,bonus:float,advance:float},rest:array{base:float,bonus:float,advance:float}}
     */
    private function calendarSums(array $c, array $plan, string $start, string $asOf, string $endExcl, bool $started): array
    {
        $out = ['to_date' => ['base' => 0.0, 'bonus' => 0.0, 'advance' => 0.0],
                'rest'    => ['base' => 0.0, 'bonus' => 0.0, 'advance' => 0.0]];
        $asOfMonth = substr($asOf, 0, 7);
        for ($t = strtotime(substr($start, 0, 7) . '-01'); $t !== false && date('Y-m-d', $t) < $endExcl; $t = strtotime('+1 month', $t)) {
            $y = (int)date('Y', $t); $mo = (int)date('n', $t);
            $dim = (int)date('t', $t);
            $mStart = date('Y-m-d', $t);
            $mEnd   = date('Y-m-d', strtotime('+1 month', $t));
            $cFrom  = max($mStart, $start);
            $cTo    = min($mEnd, $endExcl);
            $cDays  = max(0, (int)round((strtotime($cTo) - strtotime($cFrom)) / 86400));
            if ($cDays === 0) continue;
            // Monat „bis heute" oder „Rest"? (ein künftiger Vertrag hat kein „bis heute")
            $bucket = ($started && date('Y-m', $t) <= $asOfMonth) ? 'to_date' : 'rest';

            // Grundpreis: Tage vor heute nach „bis heute", ab heute nach „Rest"
            $bp = $this->contracts->valueValidOn($c['base_prices'] ?? [], 'eur_per_month', $y, $mo);
            if ($bp !== null) {
                $splitAt = min(max($asOf, $cFrom), $cTo);
                $before  = $started ? max(0, (int)round((strtotime($splitAt) - strtotime($cFrom)) / 86400)) : 0;
                $out['to_date']['base'] += (float)$bp * $before / $dim;
                $out['rest']['base']    += (float)$bp * ($cDays - $before) / $dim;
            }
            $out[$bucket]['bonus'] += $this->contracts->bonusForMonth($c, $y, $mo);
            $ap = $this->contracts->valueValidOn($plan, 'amount_eur', $y, $mo);
            if ($ap !== null) $out[$bucket]['advance'] += (float)$ap * min(1.0, $cDays / $dim);
        }
        return $out;
    }

    /**
     * Schätzt den Verbrauch je Monat für [von, bis) — für Lücke und Rest des
     * Saldos. Heizarten mit Heizmodell: je Tag a × HGT + c (HGT aus der
     * Tagestemperatur, für Tage ohne Wert aus dem Normal des Monats); sonst die
     * Tagesrate desselben Kalendermonats aus vollen Monaten.
     *
     * @return (callable(string,string):array<string,float>)|null  ym → Menge
     */
    private function volumeEstimator(string $utility, array $monthly, string $vf): ?callable
    {
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $heat = $this->heatModel($utility, $monthly);
        $rates = []; $all = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline']) || (int)($m['days'] ?? 0) < $minDays) continue;
            $r = (float)($m[$vf] ?? 0) / (int)$m['days'];
            $rates[(int)$m['month']][] = $r;
            $all[] = $r;
        }
        if ($heat === null && $all === []) return null;
        $overall = $all ? array_sum($all) / count($all) : 0.0;
        $temps = $this->store->read('temperatures.json', []);
        $base = (float)$this->settings->get('hdd_base_temp', 15.0);
        $normals = $this->hddNormals();

        // Kalibrierung am jüngsten Niveau: Liegen die letzten gemessenen
        // Monate (bis zu sechs volle aus den letzten zwölf) deutlich unter oder
        // über dem Modell — neue Heizung ohne Zäsur, anderes Verhalten —, folgt
        // die Schätzung diesem Niveau. Begrenzt auf 0,5 bis 1,5, damit ein
        // einzelner Ausnahmemonat die Hochrechnung nicht kippt.
        $factor = 1.0;
        $recent = array_slice(array_values(array_filter($monthly, fn($m) =>
            empty($m['pre_baseline']) && (int)($m['days'] ?? 0) >= $minDays
            && (string)($m['ym'] ?? '') >= date('Y-m', strtotime(date('Y-m-01') . ' -12 months')))), -6);
        $act = $exp = 0.0;
        foreach ($recent as $m) {
            $e = $heat !== null
                ? ($m['expected_heat'] ?? null)
                : (!empty($rates[(int)$m['month']]) ? array_sum($rates[(int)$m['month']]) / count($rates[(int)$m['month']]) * (int)$m['days'] : null);
            if ($e === null || $e <= 0) continue;
            $act += (float)($m[$vf] ?? 0);
            $exp += (float)$e;
        }
        if (count($recent) >= 2 && $exp > 0) $factor = max(0.5, min(1.5, $act / $exp));
        $this->lastProjectionFactor = $factor;

        return function (string $from, string $to) use ($heat, $rates, $overall, $temps, $base, $normals, $factor): array {
            $out = [];
            for ($t = strtotime($from . ' 12:00:00'); $t !== false && date('Y-m-d', $t) < $to; $t += 86400) {
                $d = date('Y-m-d', $t);
                $ym = substr($d, 0, 7);
                $mo = (int)date('n', $t);
                if ($heat !== null) {
                    $hdd = isset($temps[$d]['avg'])
                        ? max(0.0, $base - (float)$temps[$d]['avg'])
                        : (($normals[$mo]['mean'] ?? null) !== null ? $normals[$mo]['mean'] / (int)date('t', $t) : 0.0);
                    $vol = $heat['a'] * $hdd + $heat['c'];
                } else {
                    $vol = !empty($rates[$mo]) ? array_sum($rates[$mo]) / count($rates[$mo]) : $overall;
                }
                $out[$ym] = ($out[$ym] ?? 0.0) + $vol * $factor;
            }
            return $out;
        };
    }

    /** Kalibrierfaktor der letzten Saldo-Schätzung (für die Antwort). */
    private float $lastProjectionFactor = 1.0;

    /** Datum der letzten gültigen Ablesung (Plausibilitätsprüfung wie die Rechnung). */
    private function lastReadingDate(string $utility, array $meter): ?string
    {
        if (!Utilities::isCumulative($utility)) return null;
        [$kept] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
        return $kept ? (string)end($kept)['date'] : null;
    }

    private function monthsBetween(string $a, string $b): int
    {
        try {
            $da = new \DateTime($a); $db = new \DateTime($b);
            $d = $da->diff($db);
            $months = $d->y * 12 + $d->m + ($d->d > 15 ? 1 : 0);
            return $db < $da ? 0 : $months;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * F-03: the next billing-cycle anchor date (>= today) for a utility.
     *
     * The anchor is a Settings value `billing_cycle_anchor_<utility>` in
     * 'MM-TT' form (default '01-01' = Kalenderjahr). Used to project the
     * Saldo of open-ended contracts up to the next Jahresabrechnung instead
     * of the arbitrary "today + 12 months" of v1.0.x.
     */
    private function nextBillingAnchor(string $utility, string $today): string
    {
        $anchor = (string)$this->settings->get('billing_cycle_anchor_' . $utility, '01-01');
        if (!preg_match('/^(\d{2})-(\d{2})$/', $anchor, $mm)) {
            $anchor = '01-01';
            $mm = [null, '01', '01'];
        }
        // Clamp 29.–31. Feb. to 28 to keep the resulting date constructible
        // in every (also non-leap) year.
        if ($mm[1] === '02' && (int)$mm[2] > 28) {
            $anchor = '02-28';
        }
        $year = (int)substr($today, 0, 4);
        $candidate = sprintf('%04d-%s', $year, $anchor);
        if ($candidate < $today) {
            $candidate = sprintf('%04d-%s', $year + 1, $anchor);
        }
        return $candidate;
    }

    /**
     * Compute monthly aggregates for a single meter, honouring device chain.
     *
     * Wraps {@see computeForMeter()} in a recursion guard so a Schmutzwasser
     * `separater_zaehler` reference (see {@see applyWaterContracts()}) cannot
     * trigger infinite recursion.
     */
    public function forMeter(string $utility, array $meter, ?float $hddBaseOverride = null): array
    {
        $meterId = (string)($meter['id'] ?? '');
        if ($meterId !== '' && in_array($meterId, $this->meterComputeStack, true)) {
            return []; // cycle detected — break it
        }
        // v2.6.0 — je Anfrage einmal rechnen (s. $forMeterMemo). Der Schlüssel
        // trägt den ganzen Zähler, damit ein geänderter Datensatz (Tests,
        // Zäsur-Vorschau) nie ein altes Ergebnis bekommt.
        $memoKey = $utility . '|' . md5(serialize($meter)) . '|' . ($hddBaseOverride ?? '')
            . '|' . $this->store->generation();
        if (isset($this->forMeterMemo[$memoKey])) return $this->forMeterMemo[$memoKey];
        $this->meterComputeStack[] = $meterId;
        try {
            // v1.3.0 — bei Delivery-Utilities (Heizöl, Pellets) Lieferungs-basierten Pfad
            return $this->forMeterMemo[$memoKey] = Utilities::isDelivery($utility)
                ? $this->computeForDeliveryMeter($utility, $meter, $hddBaseOverride)
                : $this->computeForMeter($utility, $meter, $hddBaseOverride);
        } finally {
            array_pop($this->meterComputeStack);
        }
    }

    /**
     * v2.6.0 — Welche Ablesungen die Rechnung übergangen hat und warum.
     *
     * Typen: `suspect` (als verdächtig markiert, meist Home-Assistant-Push
     * mit fallendem Stand), `outlier` (eingeklemmter Ausreißer, `kind` dip
     * oder spike), `decrease` (Stand fällt, ohne dass sich ein Ausreißer
     * bestimmen lässt — Zählertausch oder Überlauf nicht erfasst?).
     *
     * @return list<array<string,mixed>>
     */
    public function readingWarnings(string $utility, array $meter): array
    {
        if (!Utilities::isCumulative($utility)) return [];
        $key = $utility . '|' . ($meter['id'] ?? '');
        if (($this->readingWarningsGen[$key] ?? null) !== $this->store->generation()) {
            unset($this->readingWarnings[$key]);
            $this->forMeter($utility, $meter);
        }
        return $this->readingWarnings[$key] ?? [];
    }

    /**
     * v2.6.0 — Ablesungen, die in die Rechnung eingehen, und die Warnungen zu
     * denen, die nicht eingehen (Lektion 33: keine stumme Untergrenze).
     *
     * 1. Ungültiges Datum, Zukunft, geplant: still heraus wie bisher.
     * 2. `is_suspect`: heraus, mit Warnung — bis der Stand bestätigt ist.
     * 3. Eingeklemmte Ausreißer desselben Geräts. Fällt der Stand zwischen
     *    zwei Ablesungen (b > c), ist entweder b eine Spitze (Vorgänger a ≤ c)
     *    oder c eine Delle (Nachfolger d ≥ b). Passt eine Deutung, fällt dieser
     *    Stand heraus; passen beide, entscheidet der gleichmäßigere
     *    Tagesverbrauch. Bis v2.5.3 verwarf die Rechnung nur das negative
     *    Intervall und zählte das folgende ab dem falschen Stand voll — ein
     *    einziger Wert 0 aus Home Assistant machte aus 190 kWh im März
     *    50.270 kWh.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>}
     */
    private function plausibleReadings(array $readings, array $meter): array
    {
        $today = date('Y-m-d');
        $actual = array_values(array_filter(
            $readings,
            fn($r) => Dates::isIsoDate($r['date'] ?? null) && $r['date'] <= $today && empty($r['is_future'])
        ));
        usort($actual, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

        $warnings = [];
        $warn = fn(string $type, array $r, array $extra = []) => [
            'type'       => $type,
            'reading_id' => $r['id'] ?? null,
            'date'       => (string)$r['date'],
            'counter'    => (float)($r['counter'] ?? 0),
        ] + $extra;

        $kept = [];
        foreach ($actual as $r) {
            if (!empty($r['is_suspect'])) { $warnings[] = $warn('suspect', $r); continue; }
            $kept[] = $r;
        }

        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) $devicesById[$d['id'] ?? ''] = $d;
        $dev = fn(array $r): ?string => $r['device_id'] ?? $this->deviceIdOnDate($meter, (string)$r['date']);
        $days = fn(array $x, array $y): int => max(1, (int)(new \DateTime($x['date']))->diff(new \DateTime($y['date']))->days);
        $rate = fn(array $x, array $y): float => max(0.0, (float)$y['counter'] - (float)$x['counter']) / $days($x, $y);
        $spread = fn(float $r1, float $r2): float => abs(log(($r1 + 1e-6) / ($r2 + 1e-6)));

        for ($guard = 0; $guard < 100; $guard++) {
            $remove = null;
            $n = count($kept);
            for ($i = 0; $i + 1 < $n; $i++) {
                $b = $kept[$i];
                $c = $kept[$i + 1];
                $devB = $dev($b);
                if ($devB !== $dev($c)) continue;
                $vb = (float)$b['counter'];
                $vc = (float)$c['counter'];
                if ($vc >= $vb || $this->rolloverAmount($vb, $vc, $devicesById[$devB] ?? null) !== null) continue;
                $a = ($i > 0 && $dev($kept[$i - 1]) === $devB) ? $kept[$i - 1] : null;
                $d = ($i + 2 < $n && $dev($kept[$i + 2]) === $devB) ? $kept[$i + 2] : null;
                $bSpike = $a !== null && $vc >= (float)$a['counter'];
                $cDip   = $d !== null && (float)$d['counter'] >= $vb;
                if ($bSpike && $cDip) {
                    $pick = $spread($rate($a, $c), $rate($c, $d)) <= $spread($rate($a, $b), $rate($b, $d)) ? 'b' : 'c';
                } elseif ($bSpike) {
                    $pick = 'b';
                } elseif ($cDip) {
                    $pick = 'c';
                } else {
                    continue;
                }
                $remove = $pick === 'b' ? $i : $i + 1;
                $warnings[] = $warn('outlier', $kept[$remove], ['kind' => $pick === 'b' ? 'spike' : 'dip']);
                break;
            }
            if ($remove === null) break;
            array_splice($kept, $remove, 1);
        }
        return [$kept, $warnings];
    }

    /**
     * v2.6.0 — Überlauf des Zählwerks. Kennt das Gerät seine Stellenzahl
     * (`digits`) und springt der Stand von kurz vor 10^digits auf kurz danach,
     * ist das kein Fehler, sondern ein Überlauf: Verbrauch = neu + 10^digits −
     * alt. „Kurz" heißt: höchstens ein Zehntel des Zählbereichs.
     */
    public static function rolloverAmount(float $prev, float $curr, ?array $device): ?float
    {
        $digits = (int)($device['digits'] ?? 0);
        if ($digits < 3 || $digits > 12 || $curr >= $prev) return null;
        $span = 10 ** $digits;
        $amount = $curr + $span - $prev;
        return ($amount > 0 && $amount <= $span / 10) ? (float)$amount : null;
    }

    /** @internal actual implementation, wrapped by forMeter()'s recursion guard */
    private function computeForMeter(string $utility, array $meter, ?float $hddBaseOverride = null): array
    {
        $u = Utilities::get($utility);
        $readings = $this->readings->list($utility, $meter['id']);
        $temps    = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $today    = date('Y-m-d');
        $hddBase  = $hddBaseOverride ?? (float)$this->settings->get('hdd_base_temp', 15.0);
        // v2.5.0 — F1012: Der Faktor ist nicht mehr eine Zahl je Zähler,
        // sondern eine Funktion des Tages. Für Gas kommt er aus der datierten
        // Liste (Zustandszahl × Brennwert je Stichtag), für alle anderen Arten
        // bleibt es der Skalar von früher — oder 1,0, wenn die Art gar nicht
        // umrechnet. Die Verteilung unten teilt jedes Ableseintervall an den
        // Stichtagen, sodass jeder Tag mit seinem Faktor rechnet.
        $factorOn = !empty($u['unit_to_kwh'])
            ? fn(string $date): float => $this->factors()->factorOn($utility, $date)
            : static fn(string $date): float => 1.0;
        $factorBoundaries = $utility === 'gas'
            ? fn(string $a, string $b): array => $this->factors()->boundariesBetween($a, $b)
            : static fn(string $a, string $b): array => [];

        // Filter actual (non-future, non-flagged) readings.
        // v2.5.3 — Ablesungen mit ungültigem Datum überspringen (Lektion 20):
        // Der Schreibpfad prüft seit diesem Release, ältere Daten und Restores
        // erreichen die Rechnung aber ungeprüft. Vorher brach `new \DateTime()`
        // hier Verbrauch, Prognose, Empfehlungen, CSV und PDF mit HTTP 500 ab.
        // v2.6.0 — dazu verdächtige Stände und eingeklemmte Ausreißer, s.
        // plausibleReadings(); was übergangen wird, steht in den Warnungen.
        $warnKey = $utility . '|' . ($meter['id'] ?? '');
        [$actual, $warnings] = $this->plausibleReadings($readings, $meter);
        $this->readingWarnings[$warnKey] = $warnings;
        $this->readingWarningsGen[$warnKey] = $this->store->generation();
        if (count($actual) < 2) return [];

        // Forward-fill prices
        $prices = [];
        $last = null;
        foreach ($actual as $r) {
            if (isset($r['price_cents']) && $r['price_cents'] !== null) {
                $last = (float)$r['price_cents'];
            }
            $prices[$r['date']] = $last;
        }

        // Devices index by id
        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) {
            $devicesById[$d['id']] = $d;
        }

        $monthly = [];
        $coverage = [];   // v2.8.0 — ym → abgedeckte Tagesspannen
        for ($i = 1; $i < count($actual); $i++) {
            $prev = $actual[$i - 1];
            $curr = $actual[$i];
            $days = (int)(new \DateTime($prev['date']))->diff(new \DateTime($curr['date']))->days;
            if ($days <= 0) continue;

            // ── F2: consumption across device replacements ──
            $consumptionRaw = $this->consumptionBetween(
                $prev, $curr, $devicesById, $meter
            );
            if ($consumptionRaw === null || $consumptionRaw < 0) {
                // v2.6.0 — nicht mehr still: Ein fallender Stand ohne
                // bestimmbaren Ausreißer ist fast immer ein nicht erfasster
                // Zählertausch oder Überlauf.
                if ($consumptionRaw !== null && ($prev['device_id'] ?? null) === ($curr['device_id'] ?? null)) {
                    $this->readingWarnings[$warnKey][] = [
                        'type'       => 'decrease',
                        'reading_id' => $curr['id'] ?? null,
                        'date'       => (string)$curr['date'],
                        'counter'    => (float)($curr['counter'] ?? 0),
                        'previous'   => ['date' => (string)$prev['date'], 'counter' => (float)($prev['counter'] ?? 0)],
                    ];
                }
                continue;
            }

            // Verteilt wird die ROHE Größe je Tag (m³ bei Gas); die Umrechnung
            // in kWh passiert je Segment mit dem dort gültigen Faktor.
            $rawPerDay    = $consumptionRaw / $days;
            $pricePerUnit = (float)($prices[$prev['date']] ?? $prices[$curr['date']] ?? 0);

            foreach ($this->distributeToMonths(
                $prev['date'], $curr['date'], $rawPerDay, $pricePerUnit,
                $factorOn, $factorBoundaries($prev['date'], $curr['date'])
            ) as $ym => $v) {
                if (!isset($monthly[$ym])) {
                    $monthly[$ym] = ['kwh' => 0.0, 'raw' => 0.0, 'days' => 0, 'cost' => 0.0];
                }
                $monthly[$ym]['kwh']  += $v['kwh'];
                $monthly[$ym]['raw']  += $v['raw'];
                $monthly[$ym]['days'] += $v['days'];
                $monthly[$ym]['cost'] += $v['cost'];
                foreach ($v['spans'] as $span) $coverage[$ym][] = $span;
            }
        }

        $monthly = $this->enrichWithWeather($monthly, $temps, $hddBase, $coverage);
        $monthly = $this->applyUtilityFields($monthly, $utility);
        $monthly = $this->applyContracts($monthly, $utility, $meter['id']);
        ksort($monthly);
        $monthly = array_values($monthly);
        // v1.6.1 — Issue #13: Wechsel-Monate flaggen
        $monthly = $this->markSwapMonths($monthly, $meter);
        // v1.4.0 — F1011: Monate vor der Zäsur flaggen. Muss VOR der
        // Wetterbereinigung laufen — die liest die Markierung.
        $monthly = $this->markBaselineMonths($monthly, $meter);
        // v2.8.0 — welche Monate ins Heizkurven-Modell gehen, steht einmal
        // am Monat (`regression_point`), statt in jeder Ansicht neu gefiltert.
        $monthly = $this->markRegressionPoints($monthly, $utility);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $monthly = $this->applyWeatherAdjustment($monthly, $utility, $valueField);
        return $this->addMovingAverages($monthly, $valueField);
    }

    /**
     * v1.6.1 — Issue #13: Markiert alle Monate, in denen ein Geräte-
     * tausch stattfand, mit `device_swap = true`. Das erste Gerät
     * eines Zählers zählt nicht als Tausch (initiale Inbetriebnahme).
     * Genutzt vom AnomalyService, um solche Monate aus der z-Score-
     * Erkennung auszuschließen — ein Tausch ist ein erklärlicher
     * Sondereffekt, keine fachliche Anomalie.
     */
    /**
     * v1.4.0 — F1011: Markiert jeden Monat vor der wirksamen Zäsur mit
     * `pre_baseline = true`.
     *
     * **Diese eine Markierung ist der ganze Durchreichmechanismus.** Jeder
     * Verbraucher — Wetterbereinigung, Regressionen im Analyse-Chart,
     * Prognose, Anomalie-Erkennung, Empfehlungen — überspringt markierte
     * Zeilen. Damit ist die Bereichsregel per `grep pre_baseline` prüfbar,
     * statt in fünf Aggregatoren einzeln nachgebaut zu werden. Genau das
     * fehlte bei der Subzähler-Regel aus F1006, die in `forUtility()` stand
     * und in drei weiteren Auswertungen nicht (behoben in v2.1.3).
     *
     * **Der Übergangsmonat zählt als „davor".** Fällt die Zäsur nicht auf
     * den Monatsersten, mischt dieser Monat altes und neues Gebäude — er
     * bleibt sichtbar, geht aber nicht ins Modell ein. Liegt die Zäsur auf
     * dem Ersten, ist der Monat vollständig „danach".
     *
     * Ohne Zäsur wird jede Zeile mit `false` markiert; alle Auswertungen
     * verhalten sich dann exakt wie vor v2.4.0.
     */
    private function markBaselineMonths(array $monthly, array $meter): array
    {
        $baseline = MeterService::activeBaselineDate($meter);
        $firstPostYm = null;

        if ($baseline !== null) {
            $ym  = substr($baseline, 0, 7);
            $day = (int)substr($baseline, 8, 2);
            if ($day > 1) {
                // Übergangsmonat überspringen: erster voller Monat danach.
                $ts = strtotime($ym . '-01 +1 month');
                $ym = $ts === false ? $ym : date('Y-m', $ts);
            }
            $firstPostYm = $ym;
        }

        foreach ($monthly as &$row) {
            $row['pre_baseline'] = $firstPostYm !== null
                && (string)($row['ym'] ?? '') < $firstPostYm;
        }
        unset($row);
        return $monthly;
    }

    /**
     * v2.5.0 — F1012: Rechnungsprüfung — die Gasrechnung Zeile für Zeile.
     *
     * Baut aus den eigenen Ablesungen und den datierten Faktoren genau die
     * Tabelle, die eine Versorgerrechnung zeigt: Zeitraum, m³, Zustandszahl,
     * Brennwert, kWh. Ein neuer Abschnitt beginnt an jeder Ablesung und an
     * jedem Faktorwechsel innerhalb des gewählten Zeitraums — dieselben
     * Grenzen, an denen der Versorger seine Zeilen schneidet (dort mit
     * geschätzten Zwischenständen, hier tagesgenau aus dem Intervall).
     *
     * Zeiträume sind intern halboffen [von, bis); für die Anzeige wird das
     * inklusive Ende (`to_inclusive` = bis − 1 Tag) mitgeliefert, damit die
     * Zeilen wie auf der Rechnung lauten: „31.08.–25.09.", „26.09.–14.10.".
     *
     * Für Tage ohne umschließendes Ableseintervall (vor der ersten, nach der
     * letzten Ablesung) bleibt `m3` null — die Zeile erscheint, damit die
     * Lücke sichtbar ist, statt still zu fehlen.
     *
     * @return array{from:string,to:string,rows:array<int,array<string,mixed>>,totals:array<string,mixed>}
     */
    public function gasBillBreakdown(array $meter, string $from, string $to): array
    {
        $utility = 'gas';
        $readings = $this->readings->list($utility, (string)($meter['id'] ?? ''));
        // v2.6.0 — dieselbe Plausibilitätsprüfung wie die Verbrauchsrechnung.
        [$actual, $warnings] = $this->plausibleReadings($readings, $meter);

        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) {
            $devicesById[$d['id']] = $d;
        }

        // Intervalle mit Tagesrate (roh) vorbereiten
        $intervals = [];
        for ($i = 1; $i < count($actual); $i++) {
            $prev = $actual[$i - 1];
            $curr = $actual[$i];
            $days = (int)(new \DateTime($prev['date']))->diff(new \DateTime($curr['date']))->days;
            if ($days <= 0) continue;
            $raw = $this->consumptionBetween($prev, $curr, $devicesById, $meter);
            if ($raw === null || $raw < 0) continue;
            $intervals[] = [
                'start' => (string)$prev['date'],
                'end'   => (string)$curr['date'],
                'rate'  => $raw / $days,
                'estimated_end' => !empty($curr['is_estimated']),
                // v2.5.2 — für den Ersatzwert an einer Grenze innerhalb des
                // Intervalls: nur auf demselben Gerät ist ein Stand
                // interpolierbar; über einen Zählertausch hinweg gibt es
                // keinen fortlaufenden Zählerstand.
                'start_counter' => (float)($prev['counter'] ?? 0),
                'same_device'   => ($prev['device_id'] ?? null) === ($curr['device_id'] ?? null),
            ];
        }

        // v2.5.2 — Zählerstand an einer Grenze samt Ableseart, wie die
        // Rechnung ihn ausweist: eine echte Ablesung (`reading`), eine als
        // geschätzt markierte (`reading_estimated`) oder ein Ersatzwert
        // (`interpolated`): kein Zählerstand an diesem Tag, tagesgenau
        // zwischen den umschließenden Ablesungen interpoliert — dort, wo der
        // Versorger an Brennwert- und Zeitraumgrenzen schätzt.
        $readingByDate = [];
        foreach ($actual as $r) {
            $readingByDate[(string)$r['date']] = $r;
        }
        $counterAt = function (string $date) use ($readingByDate, $intervals): array {
            if (isset($readingByDate[$date])) {
                $r = $readingByDate[$date];
                return [
                    'value' => round((float)($r['counter'] ?? 0), 1),
                    'kind'  => !empty($r['is_estimated']) ? 'reading_estimated' : 'reading',
                ];
            }
            foreach ($intervals as $iv) {
                if ($iv['start'] < $date && $date < $iv['end']) {
                    if (!$iv['same_device']) return ['value' => null, 'kind' => 'interpolated'];
                    $days = (int)(new \DateTime($iv['start']))->diff(new \DateTime($date))->days;
                    return ['value' => round($iv['start_counter'] + $iv['rate'] * $days, 1), 'kind' => 'interpolated'];
                }
            }
            return ['value' => null, 'kind' => null];
        };

        // Grenzen: Anfang, Ende, Ablesungen und Faktorwechsel dazwischen
        $bounds = [$from => 'start', $to => 'end'];
        foreach ($actual as $r) {
            $d = (string)$r['date'];
            if ($d > $from && $d < $to) {
                $bounds[$d] = !empty($r['is_estimated']) ? 'reading_estimated' : 'reading';
            }
        }
        foreach ($this->factors()->boundariesBetween($from, $to) as $d) {
            $bounds[$d] = isset($bounds[$d]) ? $bounds[$d] . '+factor' : 'factor';
        }
        ksort($bounds);
        $dates = array_keys($bounds);

        $rows = [];
        $totM3 = 0.0; $totKwh = 0.0; $totDays = 0; $gaps = 0;
        for ($i = 0; $i < count($dates) - 1; $i++) {
            $a = $dates[$i];
            $b = $dates[$i + 1];
            $days = (int)(new \DateTime($a))->diff(new \DateTime($b))->days;
            if ($days <= 0) continue;

            // Ableseintervall, das $a umschließt (halboffen)
            $rate = null;
            foreach ($intervals as $iv) {
                if ($iv['start'] <= $a && $a < $iv['end']) { $rate = $iv['rate']; break; }
            }
            $entry = $this->factors()->entryOn($a);
            $m3    = $rate !== null ? $rate * $days : null;
            $kwh   = $m3 !== null ? $m3 * $entry['kwh_per_m3'] : null;
            if ($m3 === null) $gaps++;

            $cFrom = $counterAt($a);
            $cTo   = $counterAt($b);
            $rows[] = [
                'from'         => $a,
                'to'           => $b,
                'to_inclusive' => (new \DateTime($b))->modify('-1 day')->format('Y-m-d'),
                'days'         => $days,
                'reason'       => $bounds[$a],
                'm3'           => $m3 !== null ? round($m3, 1) : null,
                'zustandszahl' => $entry['zustandszahl'],
                'brennwert'    => $entry['brennwert'],
                'kwh_per_m3'   => $entry['kwh_per_m3'],
                'kwh'          => $kwh !== null ? round($kwh, 1) : null,
                // v2.5.2 — Stand alt/neu je Abschnitt mit Ableseart
                'counter_from'      => $cFrom['value'],
                'counter_from_kind' => $cFrom['kind'],
                'counter_to'        => $cTo['value'],
                'counter_to_kind'   => $cTo['kind'],
            ];
            $totM3   += $m3  ?? 0.0;
            $totKwh  += $kwh ?? 0.0;
            $totDays += $days;
        }

        return [
            'from'   => $from,
            'to'     => $to,
            'rows'   => $rows,
            'totals' => [
                'days' => $totDays,
                'm3'   => round($totM3, 1),
                'kwh'  => round($totKwh, 1),
                'gaps' => $gaps,
            ],
            // v2.6.0 — übergangene Ablesungen, s. plausibleReadings()
            'warnings' => $warnings,
        ];
    }

    /**
     * v1.4.0 — F1011: Die Punkte, die in eine Heizkurven-Regression gehören.
     *
     * Eine Stelle entscheidet, was ein Regressionspunkt ist — der Chart im
     * ConsumptionController, `baselineInfo()` und der Vorher/Nachher-Vergleich
     * fragen alle hier. Bis v2.3.5 stand dieselbe Bedingung an drei Orten
     * leicht verschieden im Code; genau so entstand die Subzähler-Lücke aus
     * v2.1.3.
     *
     * `$minHdd` bleibt bewusst einstellbar: Der Analyse-Chart nimmt seit jeher
     * jeden Monat mit HGT > 0, die Wetterbereinigung erst ab
     * `min_hdd_regression`. Diese beiden Schwellen zu vereinheitlichen wäre
     * eine Verhaltensänderung, die nichts mit F1011 zu tun hat — hier wird nur
     * die Zäsur-Regel zusammengeführt.
     *
     * @return array{x:array<int,float>,y:array<int,float>,n:int}
     */
    public function regressionPoints(
        array $monthly,
        string $utility,
        bool $preBaseline = false,
        ?float $minHdd = null,
    ): array {
        // v2.8.0 — ohne Angabe die Einstellung, wie überall (vorher 0 im
        // Analyse-Chart gegen 5 in Prognose und Bereinigung)
        $minHdd   ??= (float)$this->settings->get('min_hdd_regression', 5.0);
        $u          = Utilities::get($utility);
        $valueField = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $minDays    = (int)$this->settings->get('min_days_period', 20);
        $x = $y = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline']) !== $preBaseline) continue;
            if (!self::isRegressionCandidate($m, $valueField, $minHdd, $minDays)) continue;
            $x[] = (float)$m['hdd'];
            $y[] = (float)$m[$valueField];
        }
        return ['x' => $x, 'y' => $y, 'n' => count($x)];
    }

    /**
     * v2.8.0 — Was ein Punkt der Heizkurve ist, steht hier und nur hier
     * (Lektion 32; Review CALC-14, FE-18). Bis v2.7 wählten Analyse-Chart,
     * Wetterbereinigung, Prognose und Anomalie-Erkennung ihre Punkte je leicht
     * anders; derselbe Zähler zeigte in der Analyse R² 0,42 (n = 16), in der
     * Prognose 0,56 (n = 15). Ein Monat zählt, wenn er genug Tage Verbrauch hat
     * (`min_days_period`) — ein 14-Tage-Teilmonat verzerrt die Kurve —, genug
     * Heizgradtage und überhaupt Verbrauch.
     */
    public static function isRegressionCandidate(array $m, string $valueField, float $minHdd, int $minDays): bool
    {
        return (int)($m['days'] ?? 0) >= $minDays
            && self::hasTemperatureCoverage($m)
            && (float)($m['hdd'] ?? 0) > $minHdd
            && (float)($m[$valueField] ?? 0) > 0;
    }

    /**
     * v2.8.0 — Liegen für (fast) alle Verbrauchstage Temperaturen vor? Ohne
     * sie ist `hdd` zu klein: Ein Wintermonat mit zehn Temperaturtagen sah
     * aus wie ein Übergangsmonat und bog die Heizkurve.
     */
    public static function hasTemperatureCoverage(array $m): bool
    {
        $days = (int)($m['days'] ?? 0);
        return $days > 0 && (int)($m['temp_days'] ?? 0) >= (int)floor($days * 0.9);
    }

    /**
     * v2.8.0 — Heizmodell eines Zählers (Review CALC-05, CALC-06, CALC-02):
     *
     *   Verbrauch = a × HGT + c × Tage
     *
     * a ist der Verbrauch je Gradtag (Heizanteil), c die Grundlast je Tag
     * (Warmwasser, Kochen). Ohne Achsenabschnitt gefittet: Ein Teilmonat
     * bekommt so seine anteilige Grundlast statt der eines vollen Monats, und
     * Sommermonate bestimmen die Grundlast mit. Punkte: Monate ab der Zäsur
     * mit genug Tagen (`min_days_period`), Temperaturen und Verbrauch — der
     * Sommer ausdrücklich eingeschlossen. Nur für heizrelevante Arten mit
     * Ablesungen; bei Lieferarten ist die Monatsverteilung selbst nach
     * Gradtagen gemacht, ein Fit wäre ein Zirkelschluss.
     *
     * @return array{a:float,c:float,n:int,resid_sd:float}|null
     */
    public function heatModel(string $utility, array $monthly): ?array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return null;
        $u = Utilities::get($utility);
        $vf = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $pts = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline']) || !empty($m['device_swap'])) continue;
            $days = (int)($m['days'] ?? 0);
            $y = (float)($m[$vf] ?? 0);
            if ($days < $minDays || $y <= 0 || !self::hasTemperatureCoverage($m)) continue;
            $pts[] = [(float)($m['hdd'] ?? 0), (float)$days, $y];
        }
        if (count($pts) < self::MIN_POINTS_REGRESSION) return null;
        [$a, $c] = self::fitHeatModel($pts);
        // Ein Robustheitsschritt: Ein einzelner Ausreißer (etwa ein Sommer mit
        // defekter Therme) zöge sonst die Grundlast c mit hoch — und damit
        // genau die Erwartung, an der er gemessen wird. Punkte jenseits von
        // 3,5 robusten Streuungen fallen einmal heraus, dann wird neu gefittet.
        $res = [];
        foreach ($pts as $i => [$h, $d, $y]) $res[$i] = $y - ($a * $h + $c * $d);
        [$center, $scale] = AnomalyService::robustScale(array_values($res));
        if ($scale > 0) {
            $kept = array_values(array_filter($pts, fn($p, $i) => abs($res[$i] - $center) <= 3.5 * $scale, ARRAY_FILTER_USE_BOTH));
            if (count($kept) < count($pts) && count($kept) >= self::MIN_POINTS_REGRESSION) {
                $pts = $kept;
                [$a, $c] = self::fitHeatModel($pts);
            }
        }
        $res = [];
        foreach ($pts as [$h, $d, $y]) $res[] = $y - ($a * $h + $c * $d);
        $sd = count($res) > 2 ? sqrt(array_sum(array_map(fn($r) => $r * $r, $res)) / (count($res) - 2)) : 0.0;
        return ['a' => $a, 'c' => $c, 'n' => count($pts), 'resid_sd' => $sd];
    }

    /**
     * Kleinste Quadrate für y = a·h + c·d ohne Achsenabschnitt; a, c ≥ 0.
     *
     * @param list<array{0:float,1:float,2:float}> $pts  [HGT, Tage, Verbrauch]
     * @return array{0:float,1:float}
     */
    private static function fitHeatModel(array $pts): array
    {
        $shh = $shd = $sdd = $shy = $sdy = 0.0;
        foreach ($pts as [$h, $d, $y]) {
            $shh += $h * $h; $shd += $h * $d; $sdd += $d * $d; $shy += $h * $y; $sdy += $d * $y;
        }
        $det = $shh * $sdd - $shd * $shd;
        $a = $c = null;
        if (abs($det) > 1e-9) {
            $a = ($shy * $sdd - $sdy * $shd) / $det;
            $c = ($shh * $sdy - $shd * $shy) / $det;
        }
        // Randfälle: kein Heizsignal (a ≤ 0) oder keine Grundlast (c < 0)
        if ($a === null || $a <= 0) {
            return [0.0, $sdd > 0 ? $sdy / $sdd : 0.0];
        }
        if ($c < 0) {
            return [$shh > 0 ? $shy / $shh : 0.0, 0.0];
        }
        return [$a, $c];
    }

    /**
     * v2.8.0 — Normale Heizgradtage eines Kalendermonats für die eingestellte
     * Heizgrenze: aus dem Klimanormal, sonst aus der eigenen
     * Temperaturhistorie (Monate mit vollständigen Tageswerten).
     *
     * @return array{mean:float,sd:?float,source:string}|null
     */
    public function hddNormal(int $month, ?float $base = null): ?array
    {
        return $this->hddNormals($base)[$month] ?? null;
    }

    /** @var array<string, array<int,array{mean:float,sd:?float,source:string}>> */
    private array $hddNormalsMemo = [];

    /**
     * @param float|null $base  Heizgrenze; ohne Angabe die Einstellung. Die
     *                          Prognose fragt mit verschobener Grenze: Ein um
     *                          δ wärmeres Jahr hat genau die HGT der Grenze − δ.
     * @return array<int,array{mean:float,sd:?float,source:string}>
     */
    public function hddNormals(?float $base = null): array
    {
        $base ??= (float)$this->settings->get('hdd_base_temp', 15.0);
        $key  = $base . '|' . $this->store->generation();
        if (isset($this->hddNormalsMemo[$key])) return $this->hddNormalsMemo[$key];

        $out = [];
        $climate = $this->climate();
        for ($m = 1; $m <= 12; $m++) {
            $n = $climate->hddForMonth($m, $base);
            if ($n !== null) $out[$m] = ['mean' => $n['mean'], 'sd' => $n['sd'], 'source' => 'climate_normal'];
        }
        if (count($out) < 12) {
            // Eigene Temperaturhistorie: HGT je vollständigem Monat, gemittelt
            $temps = $this->store->read('temperatures.json', []);
            $sums = []; $cnt = [];
            foreach (is_array($temps) ? $temps : [] as $date => $t) {
                if (!is_array($t) || !isset($t['avg'])) continue;
                $ym = substr((string)$date, 0, 7);
                $sums[$ym] = ($sums[$ym] ?? 0.0) + max(0.0, $base - (float)$t['avg']);
                $cnt[$ym]  = ($cnt[$ym] ?? 0) + 1;
            }
            $byMonth = [];
            foreach ($sums as $ym => $s) {
                [$y, $mo] = array_map('intval', explode('-', $ym));
                if ($cnt[$ym] < (int)date('t', mktime(0, 0, 0, $mo, 1, $y)) - 1) continue;
                $byMonth[$mo][] = $s;
            }
            for ($m = 1; $m <= 12; $m++) {
                if (isset($out[$m]) || empty($byMonth[$m])) continue;
                $v = $byMonth[$m];
                $mean = array_sum($v) / count($v);
                $sd = count($v) > 2
                    ? sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $v)) / (count($v) - 1))
                    : null;
                $out[$m] = ['mean' => $mean, 'sd' => $sd, 'source' => 'temperature_history'];
            }
        }
        return $this->hddNormalsMemo[$key] = $out;
    }

    /** Streuung der Jahressumme der Heizgradtage (nur aus dem Klimanormal). */
    public function hddNormalYearSd(?float $base = null): ?float
    {
        return $this->climate()->yearSd($base ?? (float)$this->settings->get('hdd_base_temp', 15.0));
    }

    public function climate(): ClimateNormalService
    {
        return $this->climate ??= new ClimateNormalService($this->store, $this->settings);
    }

    /**
     * v2.8.0 — Felder aus dem Heizmodell, additiv (Review CALC-05):
     *
     *   expected_heat      Erwartung für HGT und Tage dieses Monats
     *   weather_delta_pct  (Ist − Erwartung) / Erwartung: Mehr- oder
     *                      Minderverbrauch bei gegebenem Wetter. Ersetzt
     *                      `delta_pct`, das die Jahreszeit maß (Januar +58 %).
     *   hdd_normal         Heizgradtage eines Normaljahrs für dieselben Tage
     *   heat_adjusted      witterungsbereinigt: Ist + a × (HGT_normal − HGT_ist),
     *                      mindestens die Grundlast — umgerechnet wird nur
     *                      der Wettereinfluss laut Modell. Ersetzt
     *                      `weather_adjusted`, das auch das Warmwasser
     *                      skalierte (September ×1,54).
     */
    private function applyHeatModel(array $monthly, string $utility, string $valueField): array
    {
        $model   = $this->heatModel($utility, $monthly);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $minHdd  = (float)$this->settings->get('min_hdd_regression', 5.0);
        $normals = $this->hddNormals();
        foreach ($monthly as &$m) {
            $m['expected_heat'] = null;
            $m['weather_delta_pct'] = null;
            $m['heat_adjusted'] = null;
            $m['hdd_normal'] = null;
            $days = (int)($m['days'] ?? 0);
            $dim  = (int)date('t', mktime(0, 0, 0, (int)($m['month'] ?? 1), 1, (int)($m['year'] ?? 2000)));
            $norm = $normals[(int)($m['month'] ?? 0)]['mean'] ?? null;
            if ($norm !== null && $dim > 0) $m['hdd_normal'] = round($norm * min($days, $dim) / $dim, 1);
            if ($model === null || !empty($m['pre_baseline']) || !self::hasTemperatureCoverage($m)) continue;
            $hdd = (float)($m['hdd'] ?? 0);
            $val = (float)($m[$valueField] ?? 0);
            $exp = $model['a'] * $hdd + $model['c'] * $days;
            $m['expected_heat'] = round($exp, 1);
            if ($days >= $minDays && $exp > 0) {
                $m['weather_delta_pct'] = round(($val - $exp) / $exp * 100, 1);
            }
            if ($m['hdd_normal'] !== null) {
                // Umgerechnet wird nur der Wettereinfluss laut Modell; die
                // eigene Abweichung des Monats bleibt. Den „Heizanteil"
                // (Ist − c·Tage) mit HGT_normal / HGT_ist zu skalieren, blähte
                // Übergangsmonate auf: Bei 11 statt 30 Gradtagen wurde Rauschen
                // ×2,7 zu Heizung. Unter der Grundlast (Urlaub, Leerstand) gibt
                // es nichts umzurechnen, und die Grundlast ist die Untergrenze.
                $base = $model['c'] * $days;
                $m['heat_adjusted'] = $hdd > $minHdd && $val > $base
                    ? round(max($base, $val + $model['a'] * ($m['hdd_normal'] - $hdd)), 1)
                    : round($val, 1);
            }
        }
        unset($m);
        return $monthly;
    }

    /**
     * v2.8.0 — Markiert die Monate, die in die Heizkurve eingehen
     * (`regression_point`). Die Analyse zeichnet alle übrigen Punkte blass —
     * das Chart zeigt damit genau die Punkte, die im Fit stecken.
     */
    private function markRegressionPoints(array $monthly, string $utility): array
    {
        $hgt        = Utilities::isHgtRelevant($utility);
        $u          = Utilities::get($utility);
        $valueField = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $minHdd     = (float)$this->settings->get('min_hdd_regression', 5.0);
        $minDays    = (int)$this->settings->get('min_days_period', 20);
        foreach ($monthly as &$m) {
            $m['regression_point'] = $hgt && empty($m['pre_baseline'])
                && self::isRegressionCandidate($m, $valueField, $minHdd, $minDays);
        }
        unset($m);
        return $monthly;
    }

    /**
     * v1.4.0 — F1011: Zustandsbericht zur Zäsur eines Zählers.
     *
     * Liefert die wirksame Zäsur, alle gespeicherten Ereignisse (auch künftig
     * datierte) und für jede der drei Untergrenzen, ob sie erfüllt ist. Die
     * Oberfläche macht daraus einen Klartext-Hinweis, statt eine Auswertung
     * wortlos verschwinden zu lassen.
     *
     * Bewusst **auch ohne Zäsur** befüllt: Wer schlicht erst sieben Monate
     * Daten hat, bekommt dieselbe Erklärung. Die drei Grenzen greifen heute
     * schon, nur eben stumm.
     */
    public function baselineInfo(string $utility, array $meter, array $monthly): array
    {
        $u       = Utilities::get($utility);
        $minHdd  = (float)$this->settings->get('min_hdd_regression', 5.0);
        $minDays = (int)$this->settings->get('min_days_period', 20);

        $event      = MeterService::activeBaselineEvent($meter);
        $firstMonth = null;
        $monthsAfter = $anomalyMonths = 0;

        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            $monthsAfter++;
            if ($firstMonth === null) $firstMonth = (string)($m['ym'] ?? '');
            if (($m['days'] ?? 0) >= $minDays && empty($m['device_swap'])) $anomalyMonths++;
        }
        // Dieselbe Punktauswahl, die die Wetterbereinigung wirklich verwendet.
        $pointsAfter = $this->regressionPoints($monthly, $utility, false, $minHdd)['n'];

        $hgt = !empty($u['hgt_relevant']);

        // Die Zahlen stammen aus den Konstanten, die tatsächlich greifen —
        // nicht aus einer zweiten, freihändig gepflegten Liste.
        $limits = [];
        if ($hgt) {
            $limits[] = [
                'key'  => 'weather_adjustment',
                'need' => self::MIN_MONTHS_WEATHER,
                'have' => count($monthly),
                'ok'   => count($monthly) >= self::MIN_MONTHS_WEATHER,
            ];
            $limits[] = [
                'key'  => 'regression',
                'need' => self::MIN_POINTS_REGRESSION,
                'have' => $pointsAfter,
                'ok'   => $pointsAfter >= self::MIN_POINTS_REGRESSION,
            ];
        }
        $limits[] = [
            'key'  => 'anomalies',
            'need' => AnomalyService::MIN_MONTHS,
            'have' => $anomalyMonths,
            'ok'   => $anomalyMonths >= AnomalyService::MIN_MONTHS,
        ];

        return [
            'active_from'  => $event['date']  ?? null,
            'active_label' => $event['label'] ?? null,
            'first_month'  => $firstMonth,
            'events'       => array_values($meter['baseline_events'] ?? []),
            'months_total' => count($monthly),
            'months_after' => $monthsAfter,
            'points_after' => $pointsAfter,
            'limits'       => $limits,
        ];
    }

    /**
     * v1.4.0 — F1011: Vorher/Nachher einer baulichen Maßnahme.
     *
     * Fittet beide Epochen getrennt und gibt die Steigung je Epoche zurück —
     * Verbrauch je Gradtag. Die Steigung **ist** die Witterungsbereinigung:
     * Sie sagt, wie viel das Gebäude pro Kältegrad braucht, unabhängig davon,
     * wie kalt der Winter war. Ihre Differenz ist damit die Wirkung der
     * Maßnahme, nicht die des Wetters.
     *
     * Bewusst immer `linear` — nicht das eingestellte Prognosemodell. Der
     * Vergleich lebt von einer Zahl, die beide Seiten gleich beschreibt; ein
     * Polynom oder eine Sigmoide haben keine vergleichbare Steigung.
     *
     * `null`, wenn keine Zäsur greift, die Verbrauchsart nicht HGT-relevant
     * ist oder eine der beiden Epochen zu dünn besetzt ist.
     */
    public function baselineComparison(string $utility, array $meter, array $monthly): ?array
    {
        if ($this->regression === null) return null;
        if (!Utilities::isHgtRelevant($utility)) return null;
        if (MeterService::activeBaselineDate($meter) === null) return null;

        $minHdd = (float)$this->settings->get('min_hdd_regression', 5.0);
        $seg = [
            'before' => $this->regressionPoints($monthly, $utility, true,  $minHdd),
            'after'  => $this->regressionPoints($monthly, $utility, false, $minHdd),
        ];

        $out = [];
        foreach ($seg as $k => $d) {
            if ($d['n'] < self::MIN_POINTS_REGRESSION) return null;
            $reg = $this->regression->linear($d['x'], $d['y']);
            if (!($reg['valid'] ?? false)) return null;
            $slope = (float)($reg['a'] ?? 0.0);   // `a` = Verbrauch je Gradtag
            if ($slope <= 0) return null;         // ohne Heizkurve kein Vergleich
            $out[$k] = [
                'slope'  => round($slope, 4),
                'base'   => round((float)($reg['b'] ?? 0.0), 1),
                'r2'     => (float)($reg['r2'] ?? 0.0),
                'points' => count($d['x']),
                'se'     => $reg['se_a'] ?? null,
            ];
        }

        $before = $out['before']['slope'];
        $after  = $out['after']['slope'];
        $out['delta_pct'] = round(($after - $before) / $before * 100.0, 1);
        $out['unit']      = (string)(Utilities::get($utility)['consumption_unit'] ?? '');
        // v2.8.0 (Review CALC-13) — ist der Unterschied belegt? Die Demo meldete
        // „−38 %", das 95-%-Intervall der Nachher-Steigung umfasste aber die
        // Vorher-Steigung. Test der Steigungsdifferenz (z ≥ 1,96) und ein
        // 95-%-Intervall für die Änderung (Delta-Methode).
        $seB = (float)($out['before']['se'] ?? 0.0);
        $seA = (float)($out['after']['se'] ?? 0.0);
        $seDiff = sqrt($seB ** 2 + $seA ** 2);
        $seRatio = sqrt($seA ** 2 + ($after / $before) ** 2 * $seB ** 2) / $before;
        // Streuung 0 (exakte Daten): jeder Unterschied ist belegt
        $out['significant'] = $seDiff > 0
            ? abs($after - $before) / $seDiff >= 1.96
            : abs($after - $before) > 1e-9;
        $out['delta_pct_ci95'] = [round($out['delta_pct'] - 196.0 * $seRatio, 1), round($out['delta_pct'] + 196.0 * $seRatio, 1)];
        return $out;
    }

    private function markSwapMonths(array $monthly, array $meter): array
    {
        $swap = [];
        $devices = $meter['devices'] ?? [];
        foreach ($devices as $i => $d) {
            // installed_on des ERSTEN Geräts = initiale Inbetriebnahme,
            // KEIN Tausch. installed_on aller späteren Geräte = Tausch.
            if ($i > 0 && !empty($d['installed_on'])
                && preg_match('/^(\d{4}-\d{2})/', (string)$d['installed_on'], $m)) {
                $swap[$m[1]] = true;
            }
            // removed_on jedes Geräts (sofern gesetzt) = Tausch.
            if (!empty($d['removed_on'])
                && preg_match('/^(\d{4}-\d{2})/', (string)$d['removed_on'], $m)) {
                $swap[$m[1]] = true;
            }
        }
        foreach ($monthly as &$row) {
            $row['device_swap'] = isset($swap[$row['ym'] ?? '']);
        }
        unset($row);
        return $monthly;
    }
    private function consumptionBetween(array $prev, array $curr, array $devicesById, array $meter): ?float
    {
        $prevDev = $prev['device_id'] ?? null;
        $currDev = $curr['device_id'] ?? null;

        // If reading lacks device_id (legacy or fresh import), re-resolve by date
        if (!$prevDev) $prevDev = $this->deviceIdOnDate($meter, $prev['date']);
        if (!$currDev) $currDev = $this->deviceIdOnDate($meter, $curr['date']);

        if ($prevDev === $currDev) {
            $diff = ((float)$curr['counter']) - ((float)$prev['counter']);
            // v2.6.0 — Überlauf des Zählwerks (Gerätefeld `digits`)
            if ($diff < 0) {
                $roll = $this->rolloverAmount((float)$prev['counter'], (float)$curr['counter'], $devicesById[$prevDev] ?? null);
                if ($roll !== null) return $roll;
            }
            return $diff;
        }

        // Different devices → bridge via final_counter / initial_counter
        $oldDev = $devicesById[$prevDev] ?? null;
        $newDev = $devicesById[$currDev] ?? null;
        if (!$oldDev || !$newDev) return null;

        $finalOld = $oldDev['final_counter'];
        $initNew  = $newDev['initial_counter'];

        // v1.6.1 — Issue #13: das alte Gerät muss SAUBER geschlossen
        // sein (final_counter gesetzt UND ≥ letzter Ablesung am alten
        // Gerät). Sonst kann kein konsistenter Übergang berechnet
        // werden — Ausschläge im Monat des Wechsels waren genau die
        // Folge davon (final_counter=0 als Default bei nicht-
        // gepflegtem Tausch). Lieber Intervall verwerfen als falschen
        // Riesensprung zeigen.
        if ($finalOld === null || $finalOld === '') return null;
        $finalOldF = (float)$finalOld;
        $prevCtr   = (float)$prev['counter'];

        // v1.6.1 — Issue #13 (Hauptbefund aus viktor-sc's Daten):
        // Wenn die Ablesung am Tausch-Tag fälschlich device_id=alt
        // bekommen hat (Off-by-one in deviceOnDate zur Anlegezeit),
        // ist `prev.counter` der frische Stand des NEUEN Zählers
        // (z.B. 0.1 m³), nicht ein Stand des alten. Folge: partA =
        // finalOld − prev wird gigantisch (z.B. 17549.38 − 0.1).
        //
        // Plausibilitätsregel: ein Reading auf dem alten Gerät muss
        // im Bereich [initial_counter_alt, final_counter_alt] liegen.
        // Wenn nicht → device_id ist falsch zugewiesen → Intervall
        // verwerfen (statt einen 200-fach übertriebenen Verbrauch
        // anzuzeigen).
        $initOld = $oldDev['initial_counter'] ?? null;
        if ($initOld !== null && $initOld !== '' && $prevCtr < (float)$initOld) {
            return null;
        }
        if ($finalOldF < $prevCtr) {
            return null;
        }

        if ($initNew === null || $initNew === '') $initNew = 0.0;

        $partA = $finalOldF - $prevCtr;
        $partB = (float)$curr['counter'] - (float)$initNew;
        $total = $partA + $partB;

        if ($total < 0) return null;

        return $total;
    }

    private function deviceIdOnDate(array $meter, string $date): ?string
    {
        foreach ($meter['devices'] ?? [] as $d) {
            if ($date < ($d['installed_on'] ?? '9999')) continue;
            // v1.6.1 — Issue #13: am Tausch-Tag (removed_on) ist das
            // alte Gerät schon ausgebaut; eine Ablesung an diesem Tag
            // gehört zum neuen. Vorher: `$date > removed_on` ließ den
            // Tausch-Tag noch auf dem alten Gerät → die erste Ablesung
            // des neuen Zählers wurde fälschlich als Same-Device-Diff
            // gegen den alten Stand gerechnet.
            if (!empty($d['removed_on']) && $date >= $d['removed_on']) continue;
            return $d['id'];
        }
        return null;
    }

    /** Linear distribution of total kWh/day across month boundaries. */
    /**
     * Verteilt ein Ableseintervall tagesgenau auf Monate.
     *
     * v2.5.0 — F1012: Verteilt wird die ROHE Tagesgröße (`$rawPerDay`, bei Gas
     * m³/Tag); die Umrechnung in kWh passiert je Segment über `$factorOn`.
     * Ein Segment endet am nächsten Monatsersten ODER am nächsten Stichtag
     * aus `$boundaries` — je nachdem, was zuerst kommt. So bekommt jeder Tag
     * exakt den an ihm gültigen Faktor, ohne dass ein Zwischenstand geschätzt
     * werden muss (der Versorger tut genau das, Ableseart „S").
     *
     * Für Verbrauchsarten ohne Umrechnung ist `$factorOn` konstant 1,0 und
     * `$boundaries` leer — dann ist das Ergebnis bit-identisch zu v2.4.x.
     *
     * @param  callable(string):float $factorOn     Faktor für einen ISO-Tag
     * @param  string[]               $boundaries   Stichtage echt innerhalb (start, end)
     * v2.8.0 — liefert je Monat auch die abgedeckten Tagesspannen
     * (`spans`: [[von, bis), …]), damit die Heizgradtage eines Monats nur
     * über die Tage mit Verbrauch summiert werden (CALC-14).
     *
     * @return array<string,array{kwh:float,raw:float,days:int,cost:float,spans:list<array{0:string,1:string}>}>
     */
    private function distributeToMonths(
        string $start,
        string $end,
        float $rawPerDay,
        float $priceCents,
        callable $factorOn,
        array $boundaries = [],
    ): array {
        $cur = new \DateTime($start);
        $fin = new \DateTime($end);
        $cuts = [];
        foreach ($boundaries as $b) {
            $cuts[] = new \DateTime($b);
        }
        usort($cuts, static fn(\DateTime $a, \DateTime $b) => $a <=> $b);

        $out = [];
        while ($cur < $fin) {
            $ym = $cur->format('Y-m');
            $nextMonth = (clone $cur)->modify('first day of next month');
            $seg = $fin < $nextMonth ? $fin : $nextMonth;
            // Nächster Stichtag nach $cur, der vor dem Segmentende liegt?
            foreach ($cuts as $c) {
                if ($c > $cur && $c < $seg) { $seg = $c; break; }
            }
            $d = (int)$cur->diff($seg)->days;
            if ($d > 0) {
                if (!isset($out[$ym])) {
                    $out[$ym] = ['kwh' => 0.0, 'raw' => 0.0, 'days' => 0, 'cost' => 0.0, 'spans' => []];
                }
                $raw = $rawPerDay * $d;
                $kwh = $raw * $factorOn($cur->format('Y-m-d'));
                $out[$ym]['kwh']  += $kwh;
                $out[$ym]['raw']  += $raw;
                $out[$ym]['days'] += $d;
                $out[$ym]['cost'] += $kwh * $priceCents / 100.0;
                $out[$ym]['spans'][] = [$cur->format('Y-m-d'), $seg->format('Y-m-d')];
            }
            $cur = $seg;
        }
        return $out;
    }

    /**
     * v2.8.0 — Mit `$coverage` zählen nur die Tage, für die ein Verbrauch
     * vorliegt (CALC-14, CALC-08): Endete die letzte Ablesung am 14., summierte
     * der Monat bisher die Heizgradtage aller 31 Tage — samt Vorhersagetagen in
     * der Zukunft — gegen 14 Tage Verbrauch. Ohne `$coverage` (Heizöl/Pellets)
     * wie bisher der ganze Monat.
     *
     * @param array<string,list<array{0:string,1:string}>> $coverage
     */
    private function enrichWithWeather(array $monthly, array $temps, float $hddBase, array $coverage = []): array
    {
        foreach ($monthly as $ym => &$data) {
            [$yr, $mn] = array_map('intval', explode('-', $ym));
            $dim = (int)date('t', mktime(0, 0, 0, $mn, 1, $yr));
            $sum = 0.0; $min = PHP_FLOAT_MAX; $max = -PHP_FLOAT_MAX; $cnt = 0; $hdd = 0.0;
            $covered = null;
            if (isset($coverage[$ym])) {
                $covered = [];
                foreach ($coverage[$ym] as [$a, $b]) {
                    // ab Mittag gezählt: ±1 Stunde Sommerzeit kippt nie in einen anderen Tag
                    for ($t = strtotime($a . ' 12:00:00'), $e = strtotime($b . ' 00:00:00'); $t < $e; $t += 86400) {
                        $covered[(int)date('j', $t)] = true;
                    }
                }
            }
            for ($d = 1; $d <= $dim; $d++) {
                if ($covered !== null && !isset($covered[$d])) continue;
                $k = sprintf('%04d-%02d-%02d', $yr, $mn, $d);
                if (!isset($temps[$k])) continue;
                $avg = (float)$temps[$k]['avg'];
                $sum += $avg; $cnt++;
                $min = min($min, (float)$temps[$k]['min']);
                $max = max($max, (float)$temps[$k]['max']);
                $hdd += max(0.0, $hddBase - $avg);
            }
            $data['ym']        = $ym;
            $data['year']      = $yr;
            $data['month']     = $mn;
            $data['kwh']       = round($data['kwh'], 1);
            $data['cost']      = round($data['cost'], 2);
            $data['kwh_per_day'] = $data['days'] > 0 ? round($data['kwh'] / $data['days'], 2) : 0.0;
            $data['avg_temp']  = $cnt > 0 ? round($sum / $cnt, 1) : null;
            $data['min_temp']  = $cnt > 0 ? round($min, 1) : null;
            $data['max_temp']  = $cnt > 0 ? round($max, 1) : null;
            $data['hdd']       = round($hdd, 1);
            $data['temp_days'] = $cnt;
            $data['kwh_per_hdd'] = $data['hdd'] > 5 ? round($data['kwh'] / $data['hdd'], 2) : null;
        }
        unset($data);
        return $monthly;
    }

    private function applyUtilityFields(array $monthly, string $utility): array
    {
        $u = Utilities::get($utility);
        $co2Factor = (float)$this->settings->get($u['co2_setting'], 0.0);

        foreach ($monthly as &$m) {
            if ($u['consumption_unit'] === 'kWh') {
                $m['co2_kg'] = round($m['kwh'] * $co2Factor / 1000.0, 1);
            } else {
                // For m³-native (wasser): kwh field actually holds m³
                $m['m3']     = $m['kwh'];
                $m['kwh']    = 0.0;
                $m['co2_kg'] = round($m['m3'] * $co2Factor / 1000.0, 1);
            }
            // v2.5.0 — F1012: Bei Gas kommen die m³ aus der Verteilung selbst
            // (`raw`), nicht mehr aus `kwh / Faktor` — mit einem je Tag
            // wechselnden Faktor gäbe es keinen einzelnen Divisor mehr.
            if ($utility === 'gas') {
                $m['m3'] = round((float)($m['raw'] ?? 0.0), 1);
            }
            unset($m['raw']);
        }
        unset($m);
        return $monthly;
    }

    private function applyContracts(array $monthly, string $utility, string $meterId): array
    {
        $contracts = $this->contracts->list($utility, $meterId);
        // v1.3.0 — Schattenverträge fließen NICHT in den Saldo ein.
        $contracts = array_values(array_filter(
            $contracts, fn($c) => empty($c['is_shadow'])
        ));
        if (empty($contracts)) {
            return $this->applyEmptyContractFields($monthly, $utility);
        }
        return $utility === 'wasser'
            ? $this->applyWaterContracts($monthly, $contracts, $utility)
            : $this->applyStandardContracts($monthly, $contracts);
    }

    private function applyEmptyContractFields(array $monthly, string $utility): array
    {
        foreach ($monthly as &$m) {
            $m['contract_id']        = null;
            $m['advance_eur']        = null;
            $m['bonus_eur']          = null;
            $m['monthly_balance']    = null;
            $m['cumulative_balance'] = null;
            if ($utility === 'wasser') {
                $m['trinkwasser']         = null;
                $m['schmutzwasser']       = null;
                $m['niederschlagswasser'] = null;
            } else {
                $m['base_price_eur']   = null;
                $m['working_price_ct'] = null;
                $m['kwh_cost']         = $m['cost'] ?? null;
            }
        }
        unset($m);
        return $monthly;
    }

    /** Gas / Strom — original flat shape with working_prices / base_prices. */
    private function applyStandardContracts(array $monthly, array $contracts): array
    {
        $running = [];
        foreach ($monthly as &$m) {
            $first = $m['ym'] . '-01';
            $c = $this->contracts->findActiveForDate($contracts, $first);
            if (!$c) {
                $m['contract_id'] = null; $m['advance_eur'] = null;
                $m['base_price_eur'] = null; $m['working_price_ct'] = null;
                $m['bonus_eur'] = null;
                $m['kwh_cost'] = $m['cost'] ?? null;
                $m['monthly_balance'] = null; $m['cumulative_balance'] = null;
                continue;
            }
            $y = (int)$m['year']; $mn = (int)$m['month'];
            $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
            $bp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
            // F1003 — effektiver Abschlagsplan inkl. "mit Auswirkung"-Sonderzahlungen
            $ap = $this->contracts->valueValidOn($this->contracts->effectiveAdvanceSchedule($c), 'amount_eur', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $value = (float)($m['kwh'] ?? 0);
            $kwhCost = (float)($m['cost'] ?? 0);
            if ($wp !== null && $value > 0) {
                $kwhCost = round($value * $wp / 100.0, 2);
            }
            $combined = round($kwhCost + (float)($bp ?? 0) - $bn, 2);

            $m['contract_id']      = $c['id'];
            $m['advance_eur']      = $ap;
            $m['base_price_eur']   = $bp;
            $m['working_price_ct'] = $wp;
            $m['bonus_eur']        = round($bn, 2);
            $m['kwh_cost']         = $kwhCost;
            $m['cost']             = $combined;

            if ($ap !== null) {
                $delta = $combined - (float)$ap;
                $m['monthly_balance'] = round($delta, 2);
                $running[$c['id']] = ($running[$c['id']] ?? 0.0) + $delta;
                $m['cumulative_balance'] = round($running[$c['id']], 2);
            } else {
                $m['monthly_balance'] = null;
                $m['cumulative_balance'] = null;
            }
        }
        unset($m);
        return $monthly;
    }

    /**
     * Water — three priced components per contract (v1.0.3).
     *
     * Per month, computes:
     *   trinkwasser   = m³_trinkwasser × ct/m³ + Grundpreis
     *   schmutzwasser = m³_schmutzwasser × ct/m³
     *                 (m³_schmutzwasser = m³_trinkwasser when basis = 'trinkwasser';
     *                  when basis = 'separater_zaehler' the volume is read from
     *                  the referenced meter — F-01 fix, v1.1.0)
     *   niederschlagswasser = versiegelte_m² × eur/m²/Jahr / 12
     *
     * Result fields per month:
     *   m.trinkwasser         = { m3, ct_per_m3, base_price_eur, working_cost, base_cost, total }
     *   m.schmutzwasser       = { basis, m3, ct_per_m3, total }
     *   m.niederschlagswasser = { eur_per_m2_year, versiegelte_flaeche_m2, total }
     *   m.cost                = trinkwasser.total + schmutzwasser.total + niederschlagswasser.total − bonus
     */
    private function applyWaterContracts(array $monthly, array $contracts, string $utility): array
    {
        $running = [];
        // Lazily-filled cache: separate-meter id → (ym → m³). Computed on first
        // use so a contract without a separater_zaehler reference costs nothing,
        // and so two contracts referencing the same meter share one computation.
        $sepMeterM3Cache = [];

        foreach ($monthly as &$m) {
            $first = $m['ym'] . '-01';
            $c = $this->contracts->findActiveForDate($contracts, $first);
            if (!$c) {
                $m['contract_id'] = null; $m['advance_eur'] = null;
                $m['trinkwasser'] = null; $m['schmutzwasser'] = null; $m['niederschlagswasser'] = null;
                $m['bonus_eur'] = null;
                $m['monthly_balance'] = null; $m['cumulative_balance'] = null;
                continue;
            }
            $y = (int)$m['year']; $mn = (int)$m['month'];

            $m3 = (float)($m['m3'] ?? 0);
            $tw = $c['trinkwasser']         ?? [];
            $sw = $c['schmutzwasser']       ?? [];
            $nw = $c['niederschlagswasser'] ?? [];

            // ── Trinkwasser ─────────────────────────────────────────
            $twWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
            $twBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $y, $mn);
            $twWorking = ($twWp !== null && $m3 > 0) ? round($m3 * $twWp / 100.0, 2) : 0.0;
            $twBase    = $twBp !== null ? (float)$twBp : 0.0;
            $twTotal   = round($twWorking + $twBase, 2);

            // ── Schmutzwasser ──────────────────────────────────────
            // basis = 'trinkwasser'       → identical volume as Trinkwasser
            // basis = 'separater_zaehler' → volume read from the referenced
            //   meter's monthly m³ (F-01 fix). If the reference is missing or
            //   produces no data for the month, the volume falls back to 0 so
            //   the cost is not silently wrong-by-trinkwasser-volume.
            $swWp    = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
            $swBasis = (string)($sw['basis'] ?? 'trinkwasser');
            $swMeterId = $sw['separater_zaehler_meter_id'] ?? null;
            if ($swBasis === 'separater_zaehler') {
                // v2.1.4 — basis 'separater_zaehler' MIT Referenz: Volumen aus dem
                // referenzierten Zähler (0, wenn für den Monat keine Daten). OHNE
                // Referenz bewusst swM3 = 0 statt Trinkwasser-Volumen — sonst kippt
                // die Schmutzwasser-Menge still aufs Trinkwasser-Volumen (genau der
                // „silently wrong" Fall, den der Kommentar oben verhindern will).
                // Der Speicherpfad (ContractService) erzwingt zwar eine Referenz;
                // unvalidierte Daten (Backup-Import/Legacy) können sie aber missen.
                if ($swMeterId) {
                    if (!array_key_exists($swMeterId, $sepMeterM3Cache)) {
                        $sepMeterM3Cache[$swMeterId] = $this->monthlyM3ForMeterId($utility, (string)$swMeterId);
                    }
                    $swM3 = (float)($sepMeterM3Cache[$swMeterId][$m['ym']] ?? 0.0);
                } else {
                    $swM3 = 0.0;
                }
            } else {
                $swM3 = $m3;
            }
            $swTotal = ($swWp !== null && $swM3 > 0) ? round($swM3 * $swWp / 100.0, 2) : 0.0;

            // ── Niederschlagswasser ────────────────────────────────
            $nwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year', $y, $mn);
            $nwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            $nwTotal = ($nwRate !== null && $nwArea !== null)
                ? round(((float)$nwArea * (float)$nwRate) / 12.0, 2)
                : 0.0;

            $ap = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $combined = round($twTotal + $swTotal + $nwTotal - $bn, 2);

            $m['contract_id']  = $c['id'];
            $m['advance_eur']  = $ap;
            $m['bonus_eur']    = round($bn, 2);
            $m['trinkwasser']  = [
                'm3'             => $m3,
                'ct_per_m3'      => $twWp,
                'base_price_eur' => $twBp,
                'working_cost'   => $twWorking,
                'base_cost'      => $twBase,
                'total'          => $twTotal,
            ];
            $m['schmutzwasser'] = [
                'basis'                       => $swBasis,
                'separater_zaehler_meter_id'  => $swMeterId,
                'm3'                          => $swM3,
                'ct_per_m3'                   => $swWp,
                'total'                       => $swTotal,
            ];
            $m['niederschlagswasser'] = [
                'eur_per_m2_year'        => $nwRate,
                'versiegelte_flaeche_m2' => $nwArea,
                'total'                  => $nwTotal,
            ];
            $m['cost'] = $combined;

            // For the legacy/standard fields the UI's monthly table reads,
            // we keep working_price_ct = trinkwasser ct/m³ (the headline
            // tariff) and base_price_eur = trinkwasser base + niederschlag/month.
            $m['working_price_ct'] = $twWp;
            $m['base_price_eur']   = round($twBase + $nwTotal, 2);
            $m['kwh_cost']         = round($twWorking + $swTotal, 2); // Verbrauchsabhängig

            if ($ap !== null) {
                $delta = $combined - (float)$ap;
                $m['monthly_balance'] = round($delta, 2);
                $running[$c['id']] = ($running[$c['id']] ?? 0.0) + $delta;
                $m['cumulative_balance'] = round($running[$c['id']], 2);
            } else {
                $m['monthly_balance'] = null;
                $m['cumulative_balance'] = null;
            }
        }
        unset($m);
        return $monthly;
    }

    /**
     * Monthly m³ map (ym → m³) for a meter referenced by id.
     *
     * Used by {@see applyWaterContracts()} to resolve a Schmutzwasser
     * `separater_zaehler` reference. Goes through {@see forMeter()} so the
     * recursion guard applies — a self- or mutually-referential configuration
     * yields an empty map rather than an infinite loop.
     *
     * @return array<string,float>
     */
    private function monthlyM3ForMeterId(string $utility, string $meterId): array
    {
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) return [];
        $out = [];
        foreach ($this->forMeter($utility, $meter) as $m) {
            if (isset($m['ym'])) {
                $out[$m['ym']] = (float)($m['m3'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Generic stichtag-lookup for entries with an arbitrary numeric field
     * (used by the Niederschlagswasser rates which have two fields per entry).
     */
    private function valueValidOnGeneric(array $entries, string $field, int $year, int $month): ?float
    {
        if (empty($entries)) return null;
        $first = sprintf('%04d-%02d-01', $year, $month);
        usort($entries, fn($a, $b) => strcmp($a['from'] ?? '', $b['from'] ?? ''));
        $val = null;
        foreach ($entries as $e) {
            if (($e['from'] ?? '9999') <= $first) {
                if (isset($e[$field]) && is_numeric($e[$field])) $val = (float)$e[$field];
            } else break;
        }
        return $val;
    }

    /**
     * v1.3.0 — Wetterbereinigung (Witterungsbereinigung nach VDI 3807 +
     * Regressions-Erwartung).
     *
     * Ergänzt jeden Monat um drei Felder (nur HGT-relevante Utilities):
     *
     *   expected_hgt      Regressions-Prediction für die HGT dieses Monats
     *                     (welcher Verbrauch wäre laut Modell „normal"?)
     *   weather_adjusted  Verbrauch normiert auf das langjährige HGT-Mittel
     *                     desselben Kalendermonats:
     *                       adj = kwh × (hgt_ref / hgt_actual)
     *                     beantwortet „mehr verbraucht oder nur kälter?"
     *   delta_pct         Abweichung des wetterbereinigten Werts vom
     *                     Mittel aller wetterbereinigten Monate, in %
     *
     * Sommer-/Schwachlastmonate (hdd ≤ min_hdd_regression) bekommen
     * v1.4.0 — F1011: Monate mit `pre_baseline` gehen weder in die Regression
     * noch in den Vergleichsmittelwert ein; `expected_hgt` und `delta_pct`
     * bleiben für sie `null`. `weather_adjusted` wird für sie weiter berechnet
     * — der Wert ist gebäudeunabhängig und trägt den Vorher/Nachher-Vergleich.
     *
     * weather_adjusted = null und delta_pct = null — die Normierung ist
     * dort sinnlos (Division durch ~0, reiner Warmwasser-/Grundlastbedarf).
     *
     * Voraussetzung: ≥ 12 Monate Historie. Bei weniger wird nichts
     * gerechnet (Felder bleiben weg, Frontend zeigt „—").
     */
    private function applyWeatherAdjustment(array $monthly, string $utility, string $valueField): array
    {
        if (!Utilities::isHgtRelevant($utility)) return $monthly;
        // v2.8.0 — die neuen Felder brauchen keine zwölf eigenen Monate: Die
        // Referenz kommt aus dem Klimanormal, die Erwartung aus dem Heizmodell.
        $monthly = $this->applyHeatModel($monthly, $utility, $valueField);
        $n = count($monthly);
        // Ab hier die bisherigen Felder `weather_adjusted`/`delta_pct` —
        // veraltet seit v2.8.0, für bestehende API-Nutzer unverändert berechnet.
        if ($n < self::MIN_MONTHS_WEATHER) return $monthly;

        $minHdd = (float)$this->settings->get('min_hdd_regression', 5.0);

        // Referenz-HGT je Kalendermonat = Mittel der HGT dieses Monats
        // über alle vorhandenen Jahre (langjähriges Witterungsmittel).
        $hddByCalMonth = [];
        foreach ($monthly as $m) {
            $cm = (int)($m['month'] ?? 0);
            $hdd = (float)($m['hdd'] ?? 0);
            $hddByCalMonth[$cm][] = $hdd;
        }
        $hddRef = [];
        foreach ($hddByCalMonth as $cm => $vals) {
            $hddRef[$cm] = count($vals) > 0 ? array_sum($vals) / count($vals) : 0.0;
        }

        // v1.4.0 — F1011: Das Witterungsmittel `hddRef` bleibt bewusst über
        // ALLE Jahre stehen, auch über die vor einer Zäsur. Es beschreibt das
        // Klima am Standort, nicht das Gebäude — eine Dämmung ändert daran
        // nichts, und je mehr Jahre einfließen, desto stabiler ist es.
        // Ausgeschlossen wird nur, was das GEBÄUDE beschreibt: die Regression
        // und der Vergleichsmittelwert.

        // Regression über (hdd, value) der heizrelevanten Monate
        $reg = null;
        if ($this->regression !== null) {
            // v2.8.0 — dieselbe Punktauswahl wie überall (F1011 eingeschlossen)
            $pts = $this->regressionPoints($monthly, $utility, false, $minHdd);
            $rx = $pts['x']; $ry = $pts['y'];
            if (count($rx) >= self::MIN_POINTS_REGRESSION) {
                $model = (string)$this->settings->get('forecast_model', 'linear');
                $reg = $this->regression->fit($model, $rx, $ry, $this->settings);
                if (!($reg['valid'] ?? false)) {
                    $reg = $this->regression->fit('linear', $rx, $ry, $this->settings);
                }
            }
        }

        // Pass 1: weather_adjusted + expected_hgt berechnen
        $adjVals = [];
        foreach ($monthly as &$m) {
            $cm  = (int)($m['month'] ?? 0);
            $hdd = (float)($m['hdd'] ?? 0);
            $val = (float)($m[$valueField] ?? 0);
            $pre = !empty($m['pre_baseline']);

            // F1011: Vor der Zäsur gibt es keinen Erwartungswert — das Modell
            // beschreibt das Gebäude von danach und sagt über vorher nichts.
            $m['expected_hgt'] = (!$pre && $reg && ($reg['valid'] ?? false))
                ? round($this->regression->predict($reg, $hdd), 1)
                : null;

            // `weather_adjusted` wird auch VOR der Zäsur berechnet: Die
            // Normierung auf das Witterungsmittel ist gebäudeunabhängig, und
            // genau diese Werte braucht der Vorher/Nachher-Vergleich.
            if ($hdd > $minHdd && isset($hddRef[$cm]) && $hddRef[$cm] > 0) {
                $adj = $val * ($hddRef[$cm] / $hdd);
                $m['weather_adjusted'] = round($adj, 1);
                if (!$pre) $adjVals[] = $adj;   // F1011: Mittel nur über danach
            } else {
                $m['weather_adjusted'] = null;
            }
        }
        unset($m);

        // Pass 2: delta_pct gegen das Mittel der wetterbereinigten Werte —
        // F1011: nur über die Monate ab der Zäsur, und nur für sie ausgewiesen.
        // Ein Monat von vor der Sanierung gegen den Schnitt von danach zu
        // messen ergäbe eine Abweichung, die nur den Umbau abbildet.
        $adjMean = count($adjVals) > 0 ? array_sum($adjVals) / count($adjVals) : 0.0;
        foreach ($monthly as &$m) {
            if (empty($m['pre_baseline']) && $m['weather_adjusted'] !== null && $adjMean > 0) {
                $m['delta_pct'] = round(
                    ($m['weather_adjusted'] - $adjMean) / $adjMean * 100.0, 1
                );
            } else {
                $m['delta_pct'] = null;
            }
        }
        unset($m);

        return $monthly;
    }

    private function addMovingAverages(array $monthly, string $field): array
    {
        $n = count($monthly);
        if ($n === 0) return $monthly;
        $vals = array_map(fn($m) => (float)($m[$field] ?? 0), $monthly);
        foreach ([3, 6, 12] as $w) {
            for ($i = 0; $i < $n; $i++) {
                if ($i + 1 < $w) {
                    $monthly[$i]['ma' . $w] = null;
                    continue;
                }
                $sum = 0.0;
                for ($j = $i - $w + 1; $j <= $i; $j++) $sum += $vals[$j];
                $monthly[$i]['ma' . $w] = round($sum / $w, 1);
            }
        }
        return $monthly;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  v1.3.0 — Lieferungs-basierte Verbrauchsverteilung (Heizöl/Pellets)
    //  v1.4.4 — Delegate to DeliveryConsumptionService (extracted)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Delegiert an DeliveryConsumptionService.
     * @return array<string,float>  date(YYYY-MM-DD) → verbrauch_kwh
     */
    public function dailyDeliveryConsumption(string $utility, array $meter): array
    {
        return $this->getDeliveryConsumption()->dailyDeliveryConsumption($utility, $meter);
    }

    /**
     * Delegiert an DeliveryConsumptionService.
     * @return array<string,float> date → Abzug in Mengeneinheiten/Tag
     */
    public function dailyDeliveryStockDraw(string $utility, array $meter): array
    {
        return $this->getDeliveryConsumption()->dailyDeliveryStockDraw($utility, $meter);
    }

    /**
     * Monatsaggregation für einen Delivery-Meter.
     *
     * Im Gegensatz zur kumulativen Berechnung gibt es keine „echten" Reading-
     * Intervalle. Die Tages-Verbrauchsverteilung (siehe
     * DeliveryConsumptionService::dailyDeliveryConsumption) wird auf Monate
     * aggregiert; davon abgeleitet werden HGT/Temp pro Monat (enrichWithWeather),
     * Kosten/Preis aus den Lieferpreisen pro Monat (gewichtet) sowie
     * Vertragskosten via applyContracts() — exakt analog zur kumulativen
     * Variante. So bleibt das Output-Schema austauschbar und
     * Forecast/Analyse-Code funktioniert ohne Sonderfall.
     */
    private function computeForDeliveryMeter(string $utility, array $meter, ?float $hddBaseOverride = null): array
    {
        $u       = Utilities::get($utility);
        $temps   = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $hddBase = $hddBaseOverride ?? (float)$this->settings->get('hdd_base_temp', 15.0);

        $daily = $this->getDeliveryConsumption()->dailyDeliveryConsumption($utility, $meter);
        if (empty($daily)) return [];

        // Lieferungen für Preis-pro-Monat-Aggregation
        $all = $this->store->read("$utility/deliveries.json", []);
        if (!is_array($all)) $all = [];
        $deliveries = array_values(array_filter(
            $all,
            fn($d) => is_array($d)
                  && ($d['meter_id'] ?? null) === ($meter['id'] ?? null)
                  && empty($d['is_planned'])
        ));
        usort($deliveries, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

        // Forward-fill effektiver Stückpreis (ct pro volume_unit)
        // Konvertierung in ct/kWh erfolgt unten, weil die Tagesreihe kWh ist.
        $convSetting = (string)($u['conversion_setting'] ?? '');
        $kwhPerUnit  = $convSetting !== ''
            ? (float)$this->settings->get($convSetting, 1.0)
            : 1.0;

        $priceCtPerKwhByDate = [];
        $lastPriceCtPerUnit = null;
        foreach ($deliveries as $d) {
            // v1.4.2 — Gesamtbetrag der Tankrechnung hat Vorrang: er ist
            // die tatsächlich bezahlte Größe (inkl. Liefergebühr/Rabatt).
            // Daraus effektiver Stückpreis = total_eur·100 / Menge.
            // Fällt zurück auf unit_price_cents, wenn kein Gesamtbetrag.
            $qty = (float)($d['quantity'] ?? 0);
            $totalEur = isset($d['total_eur']) && $d['total_eur'] !== null
                ? (float)$d['total_eur'] : null;
            $unitCt = isset($d['unit_price_cents']) && $d['unit_price_cents'] !== null
                ? (float)$d['unit_price_cents'] : null;
            if ($totalEur !== null && $qty > 0) {
                $lastPriceCtPerUnit = $totalEur * 100.0 / $qty;
            } elseif ($unitCt !== null) {
                $lastPriceCtPerUnit = $unitCt;
            }
            // ct pro Einheit → ct pro kWh
            $priceCtPerKwhByDate[$d['date']] =
                $lastPriceCtPerUnit !== null && $kwhPerUnit > 0
                    ? $lastPriceCtPerUnit / $kwhPerUnit
                    : null;
        }

        // Forward-fill auf alle Tage
        $sortedDates = array_keys($daily);
        sort($sortedDates);
        $currentPrice = null;
        $pricePerDay = [];
        $deliveryDates = array_keys($priceCtPerKwhByDate);
        sort($deliveryDates);
        $dIdx = 0;
        foreach ($sortedDates as $d) {
            while ($dIdx < count($deliveryDates) && $deliveryDates[$dIdx] <= $d) {
                $currentPrice = $priceCtPerKwhByDate[$deliveryDates[$dIdx]];
                $dIdx++;
            }
            $pricePerDay[$d] = $currentPrice;
        }

        // Monatsaggregation
        $monthly = [];
        foreach ($daily as $d => $kwh) {
            $ym = substr($d, 0, 7);
            if (!isset($monthly[$ym])) {
                $monthly[$ym] = ['kwh' => 0.0, 'days' => 0, 'cost' => 0.0, '_priceSum' => 0.0, '_priceN' => 0];
            }
            $monthly[$ym]['kwh']  += $kwh;
            $monthly[$ym]['days'] += 1;
            $p = $pricePerDay[$d];
            if ($p !== null) {
                $monthly[$ym]['cost'] += $kwh * $p / 100.0;
                $monthly[$ym]['_priceSum'] += $p;
                $monthly[$ym]['_priceN']++;
            }
        }
        // _priceSum/_priceN aufräumen (interne Felder)
        foreach ($monthly as &$m) {
            unset($m['_priceSum'], $m['_priceN']);
        }
        unset($m);

        $monthly = $this->enrichWithWeather($monthly, $temps, $hddBase);
        $monthly = $this->applyUtilityFields($monthly, $utility);
        $monthly = $this->applyContracts($monthly, $utility, $meter['id']);
        ksort($monthly);
        $monthly = array_values($monthly);
        // v1.6.1 — Issue #13: Wechsel-Monate auch im Wasser-Pfad flaggen
        $monthly = $this->markSwapMonths($monthly, $meter);
        // v1.4.0 — F1011: Monate vor der Zäsur flaggen. Muss VOR der
        // Wetterbereinigung laufen — die liest die Markierung.
        $monthly = $this->markBaselineMonths($monthly, $meter);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $monthly = $this->applyWeatherAdjustment($monthly, $utility, $valueField);
        // v2.8.0 — Lieferarten gehen in keine Heizkurve ein (ihre Monatswerte
        // sind nach Gradtagen verteilt); das Feld steht trotzdem in jeder Zeile.
        foreach ($monthly as &$m) $m['regression_point'] = false;
        unset($m);
        return $this->addMovingAverages($monthly, $valueField);
    }

    /**
     * Lazy-getter: liefert DeliveryConsumptionService — entweder injiziert
     * oder on-demand mit den vorhandenen Abhängigkeiten erzeugt.
     * Vermeidet Breaking Change an existierenden Aufrufen, die
     * ConsumptionService ohne das neue Argument instanziieren.
     */
    private function getDeliveryConsumption(): DeliveryConsumptionService
    {
        return $this->deliveryConsumption
            ??= new DeliveryConsumptionService($this->store, $this->settings);
    }
}
