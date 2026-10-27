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
     * v3.7.0 (GitHub #37, #39) — gemessen oder verteilt. Zwischen zwei
     * Ablesungen verteilt die Rechnung den Verbrauch gleichmäßig auf die Tage.
     * Maßgeblich ist die Länge des Ablesungsabstands, aus dem ein Monat stammt —
     * nicht, ob im Monat abgelesen wurde: Ablesungen am 31. und am Ersten des
     * übernächsten Monats messen den Monat dazwischen gut.
     *
     * Grenze 45 Tage: Ein solcher Abstand reicht über einen vollen Monat um
     * höchstens 14 Tage hinaus (Februar 17), die Verteilung verschiebt damit
     * höchstens gut ein Drittel des Abstands über die Monatsgrenze. Selbst wenn
     * die Tagesrate dort um ein Drittel anders liegt (Gas im Herbst), bleibt der
     * Fehler des Monats bei rund 10 % — unter den 20 % eines typischen Monats,
     * ab denen die Abweichungserkennung überhaupt meldet (2σ mit der
     * Rauschuntergrenze von 10 %, AnomalyService::RELATIVE_NOISE_FLOOR). Drei
     * Monate zwischen zwei Ablesungen sind dagegen keine Messung eines Monats.
     *
     * Geschätzte Ablesungen (`is_estimated`) zählen nicht als Messung: Der
     * Abstand reicht dann von der echten Ablesung davor bis zur echten danach.
     */
    public const MEASURED_GAP_MAX_DAYS = 45;

    /**
     * Ein Monat gilt als verteilt (`interpolated`), wenn weniger als die Hälfte
     * seines Verbrauchs aus Abständen bis MEASURED_GAP_MAX_DAYS stammt — dann
     * bestimmt die Verteilung den Wert, nicht die Messung.
     */
    public const MEASURED_SHARE_MIN = 0.5;

    /** Tage eines mittleren Monats — Gewicht eines Ablesungsabstands im Heizmodell. */
    private const MONTH_DAYS = 365.25 / 12;

    /**
     * v3.9.0 (L12) — Ein Abschlagsvorschlag, der den erwarteten Rest bis zur
     * Abrechnung nachholt, erst ab so vielen verbleibenden Abschlägen. Darunter
     * vervielfacht er den Abschlag für ein, zwei Monate (Testhaushalt: 208 €
     * statt 75 €), obwohl die Abrechnung den Rest ohnehin ausgleicht und danach
     * wieder der laufende Bedarf gilt; die Karte nennt dann diesen.
     */
    public const SUGGEST_MIN_MONTHS = 3;

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

    /** v3.4.0 (F1025) — der Vertragsdienst dieser Rechnung (für Dienste ohne eigene Verdrahtung). */
    public function contractService(): ContractService
    {
        return $this->contracts;
    }

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
        $markers = [];   // v3.7.0 (#39) — gemessen oder verteilt, gewichtet nach Verbrauch
        $vfT = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        foreach ($perMeter as $entry) {
            if (!MeterService::countsInTotals($entry['meter'])) {
                continue; // Subzähler: bereits im Elternzähler enthalten
            }
            foreach ($entry['monthly'] as $m) {
                $ym = $m['ym'];
                $markers[$ym] = self::accumulateMarker($markers[$ym] ?? null, $m, $vfT);
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
                // v3.7.0 (#33) — Boni je Monat für die Kachel „Kosten“ der Übersicht (additiv)
                if ((float)($m['bonus_eur'] ?? 0) != 0.0) {
                    $totals[$ym]['bonus_eur'] = round((float)($totals[$ym]['bonus_eur'] ?? 0.0) + (float)$m['bonus_eur'], 2);
                    $totals[$ym]['bonus_items'] = array_merge($totals[$ym]['bonus_items'] ?? [], (array)($m['bonus_items'] ?? []));
                }
            }
        }
        // v3.9.0 (F1032, #40) — Subzähler mit eigenem Vertrag: Ihre Menge steckt im Elternzähler,
        // ihr Geld nicht mehr (der Elternvertrag rechnet nur den Rest). Kosten = Rest × Elternpreis
        // + Subzähler × eigener Preis; die Menge bleibt die Messung des Elternzählers.
        foreach ($perMeter as $entry) {
            if (MeterService::countsInTotals($entry['meter']) || !$this->isDeductingSubmeter($utility, $entry['meter'])) continue;
            self::addSubmeterMoney($totals, $entry['monthly']);
        }
        foreach ($totals as $ym => &$t) $t = array_merge($t, self::sharedMarker($markers[$ym]));
        unset($t);
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
     * v3.9.0 (F1032) — Das Geld eines Subzählers mit eigenem Vertrag in Monatssummen
     * (nur Monate mit Vertrag, nur Geldfelder; die Menge steckt im Elternzähler).
     *
     * @param array<string,array<string,mixed>> $totals ym → Summe (wird ergänzt)
     * @param list<array<string,mixed>> $rows Monatszeilen des Subzählers
     */
    public static function addSubmeterMoney(array &$totals, array $rows): void
    {
        foreach ($rows as $m) {
            $ym = (string)($m['ym'] ?? '');
            if (!isset($totals[$ym]) || empty($m['contract_id'])) continue;
            // Tage ohne eigenen Vertrag rechnet der Elternzähler brutto — ihr Ablesepreis zählt dort
            $own = (float)($m['cost'] ?? 0.0) - (float)($m['uncontracted_cost'] ?? 0.0);
            $totals[$ym]['cost'] = round((float)($totals[$ym]['cost'] ?? 0.0) + $own, 2);
            foreach (['advance_eur', 'monthly_balance', 'cumulative_balance'] as $f) {
                if (($m[$f] ?? null) !== null) $totals[$ym][$f] = round((float)($totals[$ym][$f] ?? 0.0) + (float)$m[$f], 2);
            }
            if ((float)($m['bonus_eur'] ?? 0) != 0.0) {
                $totals[$ym]['bonus_eur'] = round((float)($totals[$ym]['bonus_eur'] ?? 0.0) + (float)$m['bonus_eur'], 2);
                $totals[$ym]['bonus_items'] = array_merge($totals[$ym]['bonus_items'] ?? [], (array)($m['bonus_items'] ?? []));
            }
        }
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
    public function contractStatus(string $utility, array $meter, ?string $today = null): array
    {
        $contracts = $this->contracts->list($utility, $meter['id']);
        // v1.3.0 — Schattenverträge tauchen im Vertragsstatus nicht auf
        // (rein hypothetisch; gehören in den Tarifvergleich).
        $contracts = array_values(array_filter(
            $contracts, fn($c) => empty($c['is_shadow'])
        ));
        $monthly   = $this->forMeter($utility, $meter);
        // v3.9.0 (V1) — `$today` von außen (Werkstatt, Bericht, Tests mit festem Tag), sonst heute
        $today   ??= date('Y-m-d');
        // v3.9.0 (V1) — die laufende Abrechnungsperiode der Abrechnungsjahre
        // (BillingPeriodService): Saldo, Abrechnungstag und Abschlagsvorschlag
        // jedes Vertrags, der in sie fällt, rechnen über genau diese Periode —
        // auch ohne erfasste Rechnung. Bis v3.7 zählte die Karte ohne Rechnung
        // ab Vertragsbeginn (im Testhaushalt drei Jahre Guthaben seit 11/2022),
        // die Werkstatt das laufende Abrechnungsjahr.
        $running = Utilities::isFeedIn($utility) ? null : $this->runningPeriod($utility, $meter, $today);
        // v3.6.0 (F1030) — Tag nach der letzten erfassten Versorgerrechnung
        // dieses Zählers (bzw. der Mitglieder einer Gruppe) und die
        // Sonderzahlungen, die Rechnungen ausgleichen
        [$settledFrom, $billPayments] = $this->billWindow($utility, $meter, $today, $contracts);
        // F1005 / P-PV-01 — PV-Einspeisung: der „Saldo" ist ein Vergütungs-
        // Erlös (Gutschrift), keine Kosten. Verdict-Achse und Projektions-
        // Horizont werden weiter unten entsprechend gedreht/begrenzt.
        $isFeedIn  = Utilities::isFeedIn($utility);

        // Index monthly by (contract_id, ym). v2.9.0 — ein Monat mit zwei
        // Verträgen zählt je Vertrag nur seinen Teil (CALC-10).
        $byContract = [];
        foreach ($monthly as $m) {
            if (!empty($m['contract_parts'])) {
                foreach ($m['contract_parts'] as $p) {
                    $byContract[$p['contract_id']][] = [
                        'ym' => $m['ym'], 'kwh' => $p['kwh'], 'cost' => $p['cost'], 'kwh_cost' => $p['kwh_cost'],
                        'base_price_eur' => $p['base_price_eur'], 'bonus_eur' => $p['bonus_eur'],
                        'advance_eur' => $p['advance_eur'],
                    ];
                }
                continue;
            }
            $cid = $m['contract_id'] ?? null;
            if (!$cid) continue;
            $byContract[$cid][] = $m;
        }

        $out = [];
        foreach ($contracts as $c) {
            $cid     = $c['id'];
            $mList   = $byContract[$cid] ?? [];
            // v3.7.0 (wirklichkeit-2) — abgelöst: Ein Folgevertrag beginnt, während
            // dieser noch läuft (offen oder mit Ende danach). Ab dessen Beginn gilt
            // der Folgevertrag (spätester Beginn gewinnt, Lektion 28); Abschläge,
            // Grundpreis und Saldo dieses Vertrags enden am Tag davor. Bis v3.6
            // zählten seine Abschläge im Saldo bis heute weiter — der Saldo lag um
            // etwa einen Jahresbetrag zu günstig. Gespeichert bleibt das Ende, wie es ist.
            $storedEnd = $c['end'] ?? null;
            $supersededOn = self::supersededOn($c, $contracts);
            if ($supersededOn !== null) $c['end'] = $supersededOn;
            $isCur   = ($c['start'] ?? '9999') <= $today && (empty($c['end']) || $c['end'] >= $today);
            $isPast  = !empty($c['end']) && $c['end'] < $today;
            $isFut   = ($c['start'] ?? '9999') > $today;
            $isOpen  = empty($storedEnd);
            // v2.9.0 (Review CALC-10) — abgelaufen, aber ohne Nachfolger und
            // nicht gekündigt: Der Vertrag läuft weiter (§ 309 Nr. 9 BGB).
            // Er zählt dann als laufend, bis zur nächsten Abrechnung.
            $renewed = false;
            if ($isPast && ContractService::renews($c)) {
                $r = $this->contracts->resolveForDate($contracts, $today);
                $renewed = $r !== null && $r['assumed'] && ($r['contract']['id'] ?? null) === $cid;
            }
            if ($renewed) {
                $isCur = true;
                $isPast = false;
            }
            // F-03: a contract with a pflegtem Ende ends on that date. An open
            // contract (end = null) is projected to the next billing-cycle
            // anchor of its utility (Settings: billing_cycle_anchor_<utility>),
            // not the arbitrary "today + 12 months" of v1.0.x.
            // P-PV-01 — feed_in: immer bis zur nächsten Jahresabrechnung
            // projizieren, nie bis Vertragsende. Ein EEG-Vertrag läuft 20
            // Jahre (z.B. bis 2043); die alte Logik hätte die Vergütung über
            // die gesamte Restlaufzeit hochgerechnet (z.B. „+10.756 €
            // erwartet"), was als „nächste Abrechnung" grob irreführend ist.
            $hasEnd  = !empty($c['end']) && !$renewed;
            $effEnd  = $isFeedIn
                ? min(
                    $hasEnd ? $c['end'] : '9999-12-31',
                    $this->nextBillingAnchor($utility, $today)
                  )
                : ($hasEnd
                    ? $c['end']
                    : $this->nextBillingAnchor($utility, $today));

            // v3.6.0 (F1030) — Saldo bis zur nächsten Abrechnung: Was eine
            // erfasste und gebuchte Rechnung schon abgerechnet hat, zählt im
            // laufenden Vertrag nicht mehr mit. Läuft er über diese Rechnung
            // hinaus (mehrjährig, weiterlaufend, unbefristet), beginnt sein
            // Saldo am Tag nach ihr. Bis v3.5 begann er immer am
            // Vertragsbeginn; jedes Jahr ohne gebuchten Ausgleich blieb im Saldo
            // stehen — im Beispielhaushalt meldete ein Vertrag seit 2021 so
            // „Rückzahlung 2.200 €“, wo die Abrechnungsperiode bei 60 € stand.
            // Die Buchungen der Rechnungen gleichen deren Zeitraum aus und
            // zählen dann nicht mehr (wie im Abrechnungsjahr,
            // BillingPeriodService). Ohne gebuchte Rechnung nach Vertragsbeginn
            // bleibt alles wie bisher.
            $balanceFrom = (string)($c['start'] ?? '');
            // v3.9.0 (V1) — der Teil des Vertrags in der laufenden Abrechnungsperiode:
            // [max(Beginn, Periodenbeginn), min(Ende, Periodenende)]. Ein weiterlaufender
            // Vertrag hat kein Ende mehr; ein abgelöster endet am Tag vor dem Folgevertrag.
            $period = null; $settleOn = null; $ownEnd = null;
            $cEnd = $renewed ? null : ((string)($c['end'] ?? '') ?: null);
            // offen seit (BillingPeriodService::runningPeriodFor): Periodenbeginn, oder der Tag
            // nach der letzten Rechnung, wenn der Stichtag nicht zu ihr passt
            $openFrom = $running !== null ? (string)($running['open_from'] ?? $running['from']) : null;
            if ($running !== null && $balanceFrom !== '' && $balanceFrom <= $running['until'] && ($cEnd === null || $cEnd >= $openFrom)) {
                $period = $running;
                // eigener Teil bis zum Ende des Vertrags, höchstens bis zum Ende der Periode
                $ownEnd = $cEnd !== null && $cEnd < $running['until'] ? $cEnd : $running['until'];
                // Abrechnungstag: das Periodenende; endet der Vertrag vorher ohne Folgevertrag
                // desselben Versorgers in der Periode, sein Ende (Schlussrechnung). Ein
                // Folgevertrag eines anderen Versorgers teilt die Periode ohnehin dort.
                $settleOn = $running['until'];
                if ($ownEnd === $cEnd) {
                    $succ = ContractService::successorOf($c, $contracts, $renewed);
                    $succC = $succ !== null ? (array_values(array_filter($contracts, fn($o) => ($o['id'] ?? null) === $succ['contract_id']))[0] ?? null) : null;
                    $continues = $succC !== null && (string)$succC['start'] <= $running['until'] && ContractService::sameSupplier($c, $succC);
                    if (!$continues) $settleOn = $cEnd;
                }
                // Abrechnungstag exklusiv wie bisher (`effective_end`): das gepflegte Ende selbst, sonst der Tag nach der Periode
                $effEnd = $settleOn === $cEnd ? $cEnd : (new \DateTimeImmutable($settleOn))->modify('+1 day')->format('Y-m-d');
            }
            $windowed = $period !== null
                ? $openFrom > $balanceFrom
                : $isCur && $settledFrom !== null && $settledFrom > $balanceFrom && $settledFrom <= $today;
            if ($windowed) {
                $balanceFrom = $period !== null ? $openFrom : $settledFrom;
                $mList = self::rowsFrom($mList, $balanceFrom);
            }
            // v3.9.0 (V1) — in einer angeschnittenen Abrechnungsperiode zählt von den
            // Sonderzahlungen nur die zusätzliche Abschlagszahlung: Rück- und
            // Nachzahlungen gleichen eine frühere Abrechnung aus (wie im
            // Abrechnungsjahr, BillingPeriodService::specialPayments)
            $cBalance = $windowed
                ? ['special_payments' => $period !== null
                    ? self::periodPayments($c['special_payments'] ?? [], $balanceFrom, $ownEnd, $billPayments)
                    : self::paymentsFrom($c['special_payments'] ?? [], $balanceFrom, $billPayments)] + $c
                : $c;

            // Aggregate over the months we actually have for this contract
            $actualKwh   = 0.0; $actualCost = 0.0; $actualKwhCost = 0.0;
            $actualBase  = 0.0; $actualBonus = 0.0; $advancePaid = 0.0;
            // Water component aggregates (v1.0.3)
            $twWorking = 0.0; $twBase = 0.0; $twTotal = 0.0;
            $swTotal   = 0.0; $swBase = 0.0;   // v3.9.0 (F1034) — Grundpreis Schmutzwasser
            $nwTotal   = 0.0;
            $actualM3  = 0.0;
            foreach ($mList as $m) {
                // v3.9.0 (F1032) — bei Rest-Abrechnung die Menge des Vertrags, nicht die Messung
                $actualKwh     += self::billedQuantity($m);
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
                    if (is_array($sw)) { $swTotal += (float)($sw['total'] ?? 0); $swBase += (float)($sw['base_cost'] ?? 0); }
                    if (is_array($nw)) $nwTotal += (float)($nw['total'] ?? 0);
                }
            }
            $monthsActual = count($mList);
            // F1003 — Sonderzahlungen verschieben den Saldo:
            //   current_balance = Kosten − gezahlte Abschläge + special_net
            //   special_net = Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung
            // v3.5.0 (GitHub #34) — auch Wasser; nur Arten mit Sonderzahlungen
            // (Utilities::hasSpecialPayments) — bei Heizöl, Pellets und
            // Einspeisung zählt ein Feld, das dort nie sein sollte, nicht mit.
            $spSummary = $this->contracts->specialPaymentSummary(
                Utilities::hasSpecialPayments($utility) ? $cBalance : []
            );
            $currentBalance = round($actualCost - $advancePaid + $spSummary['net'], 2);

            // Current tariff values (those valid today, for the active contract).
            // v3.9.0 (L15) — ein künftiger Vertrag zeigt die Preise und den Abschlag seines
            // Beginns (bis v3.8 „–“, weil heute noch keiner gilt)
            $priceDay = $isFut && Dates::isIsoDate($c['start'] ?? null) ? (string)$c['start'] : $today;
            [$y, $mn] = [(int)substr($priceDay, 0, 4), (int)substr($priceDay, 5, 2)];
            if ($utility === 'wasser') {
                $tw = $c['trinkwasser']         ?? [];
                $sw = $c['schmutzwasser']       ?? [];
                $nw = $c['niederschlagswasser'] ?? [];
                $curWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curSwBp = $this->contracts->valueValidOn($sw['base_prices'] ?? [], 'eur_per_month', $y, $mn);   // v3.9.0 (F1034)
                $curNwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year',        $y, $mn);
                $curNwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            } else {
                $curWp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
                $curBp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $curSwBp = $curNwRate = $curNwArea = null;
            }
            // F1003 — effektiver Abschlagsplan: „mit Auswirkung“ setzt den
            // Abschlag ab Stichtag (seit v3.5.0 auch bei Wasser); ohne
            // Sonderzahlungen ist er advance_payments.
            $curAp = $this->contracts->valueValidOn(
                $this->contracts->effectiveAdvanceSchedule($c), 'amount_eur', $y, $mn
            );
            // v3.9.0 (F1034) — Rhythmus des laufenden Abschlags und seine nächsten Termine (Anzeige)
            $plan = $this->contracts->effectiveAdvanceSchedule($c);
            $apRow = ContractService::planRowOn($plan, $today);
            $rhythm = $apRow !== null && ContractService::hasRhythm($apRow)
                ? ['current_advance_every_months' => ContractService::everyMonths($apRow),
                   'next_advance_dates' => array_column(array_slice(ContractService::installments($plan, $today,
                       ContractService::addMonthsClamped($today, 12)), 0, 6), 'date')]
                : [];

            // Project balance to contract end:
            //   project remaining months at the current avg cost / monthly advance.
            //   for past contracts: projected = current.
            $projected = $currentBalance;
            $projection = null;
            // v3.7.0 (#34-Nachzug) — auch Wasser mit Abschlägen: Saldo nach Kalender mit
            // Abschlagsvorschlag und Saldo-Verlauf (Jahresbericht: Saldo und Abschlags-Rat)
            if (Utilities::hasAdvancePaymentContracts($utility) || self::waterWithAdvances($utility, $c)) {
                // v2.8.0 (Review CALC-02) — Saldo nach Kalender statt nach
                // Ablesemonaten, Hochrechnung mit Wetter statt Durchschnitt.
                $projection = $this->balanceProjection($utility, $meter, $c, $monthly, $today, $effEnd, $spSummary['net'], $renewed, $isCur,
                    $windowed ? $balanceFrom : null, $windowed ? $cBalance['special_payments'] : null,
                    $ownEnd !== null ? (new \DateTimeImmutable($ownEnd))->modify('+1 day')->format('Y-m-d') : null);
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
            }
            // v2.9.0 (Review CALC-11) — erinnert wird zum Kündigungsstichtag,
            // nicht zum Vertragsende: Mit einem Monat Frist kamen zwei der drei
            // Erinnerungen nach dem Stichtag, mit drei Monaten alle drei. Ohne
            // gepflegte Frist bleibt es beim Vertragsende.
            $timing = $this->contracts->switchTiming($c, $today, $renewed);
            $remindBasis = null;
            $ref = null;
            $cancelMissed = false;
            // v3.4.0 (F1024) — nur ein datierter Stichtag trägt die Erinnerung;
            // ein jederzeit kündbarer befristeter Vertrag erinnert ans Ende
            // (sonst „Kündigung bis heute" an jedem Tag)
            if ($timing['basis'] === 'fixed_end' && $timing['cancel_by'] !== null && !$isPast && !$renewed
                && ContractService::hasFixedCancelBy($c)) {
                $remindBasis = 'cancel_by';
                $ref = $timing['days_to_cancel'];
                $cancelMissed = $ref < 0 && $daysUntilEnd !== null && $daysUntilEnd >= 0;
            } elseif (!empty($c['end']) && !$renewed) {
                $remindBasis = 'end';
                $ref = $daysUntilEnd;
            }
            // v3.4.0 (F1024) — Folgevertrag desselben Zählers bzw. derselben
            // Gruppe (kein Schattenvertrag, s. oben). Schließt er lückenlos an
            // (Beginn ≤ Ende + 1), ist nichts mehr zu kündigen oder zu
            // erinnern: R6, die Banner der Auswertung und „Kündigen bis"
            // entfallen. Mit Lücke bleibt die Erinnerung, der Text nennt sie.
            $successor = ContractService::successorOf($c, $contracts, $renewed);
            $gapless = $successor !== null && $successor['gap_days'] === 0;
            if ($gapless) $cancelMissed = false;
            if ($ref !== null && $ref >= 0 && !$gapless) {
                $r1 = (int)$this->settings->get('contract_remind_days_1', 90);
                $r2 = (int)$this->settings->get('contract_remind_days_2', 30);
                $r3 = (int)$this->settings->get('contract_remind_days_3', 1);
                if ($ref <= $r3)      { $remindStage = 3; }
                elseif ($ref <= $r2)  { $remindStage = 2; }
                elseif ($ref <= $r1)  { $remindStage = 1; }
                $shouldRemind = $remindStage > 0;
            }

            $row = [
                'contract_id'                 => $cid,
                'provider'                    => $c['provider']    ?? '',
                'tariff_name'                 => $c['tariff_name'] ?? '',
                'start'                       => $c['start']       ?? null,
                'end'                         => $storedEnd,
                // v3.7.0 (wirklichkeit-2) — letzter Tag vor dem Beginn des Folgevertrags,
                // wenn dieser den Vertrag ablöst (sonst null, additiv)
                'superseded_on'               => $supersededOn,
                // v3.7.0 (wirklichkeit-2) — kein Arbeitspreis: Kosten ohne Verbrauch (additiv)
                'price_missing'               => self::lacksWorkingPrice($utility, $c),
                'effective_end'               => $effEnd,
                // v3.9.0 (V1, L1) — eine Regel für alle Ansichten: der letzte Tag des
                // Abrechnungszeitraums (einschließlich), dazu die Periode, über die der
                // Saldo rechnet (laufende Abrechnungsperiode; null außerhalb von ihr)
                'settle_on'                   => $settleOn ?? self::lastDayOf($effEnd, $hasEnd ? (string)$c['end'] : null),
                'billing_period'              => $period !== null
                    ? ['from' => $period['from'], 'until' => $period['until'], 'open_from' => $openFrom, 'source' => $period['source'], 'bill_id' => $period['bill_id']] : null,
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
                // v2.9.0 (CALC-10, CALC-11) — Verlängerung, Kündigung, Preise
                'renewed'                     => $renewed,
                'cancel_by'                   => $timing['cancel_by'],
                'days_to_cancel'              => $timing['days_to_cancel'],
                'switch_date'                 => $timing['switch_date'],
                'notice_basis'                => $timing['basis'],
                'remind_basis'                => $remindBasis,
                'cancel_missed'               => $cancelMissed,
                'price_increase'              => $isCur && !$isFeedIn && $utility !== 'wasser'
                    ? $this->upcomingPriceIncrease($c, $today) : null,
                // v3.4.0 (F1026) — für den Zeitstrahl „Vertrag auf einen Blick“
                // (additiv): Mindestlaufzeit, Preisgarantie und ob er sich ohne
                // Kündigung verlängert (wirksamer Wert: fehlt = ja)
                'min_term_end'                => $c['min_term_end'] ?? null,
                'price_guarantee_until'       => $c['price_guarantee_until'] ?? null,
                'auto_renews'                 => ContractService::renews($c),
                // v3.4.0 (F1024) — {contract_id, provider, start, gap_days} oder null (additiv)
                'successor'                   => $successor,
                // v3.9.0 (L14) — der Folgevertrag ist bei einem anderen Versorger: Der Wechsel ist
                // eingetragen, offen ist nur, ob die Kündigung bestätigt ist (additiv)
                'successor_switch'            => $successor !== null && ($sc = array_values(array_filter($contracts, fn($o) => ($o['id'] ?? null) === $successor['contract_id']))[0] ?? null) !== null
                    && !ContractService::sameSupplier($c, $sc),
                // v3.6.0 (F1030) — ab wann Saldo, Kosten und Abschläge zählen:
                // Vertragsbeginn oder Tag nach der letzten erfassten Rechnung (additiv)
                'balance_from'                => $balanceFrom !== '' ? $balanceFrom : null,
                // v2.8.0 — wie gerechnet wurde (additiv)
                'balance_as_of'               => $projection['as_of'] ?? $today,
                'projection_method'           => $projection !== null ? $projection['method'] : 'flat_average',
            ] + $rhythm;   // v3.9.0 (F1034) — {current_advance_every_months, next_advance_dates}, nur mit Rhythmus (additiv)
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
                    // v3.9.0 (L12) — additiv: Abschläge bis zur Abrechnung, laufend angemessener
                    // Abschlag (12 Monate danach ÷ 12), `short` = kein Nachholbetrag kurz vorher
                    'months_left'              => $projection['months_left'],
                    'advance_ongoing'          => $projection['advance_ongoing'],
                    'suggestion_withheld'      => $projection['suggestion_withheld'],
                    // v3.7.0 (wirklichkeit-2) — {reasons, factor, factor_raw, factor_part, factor_limits,
                    // fit_ratio, base_load_per_day, projected_range, suggested_range} oder null (additiv)
                    'projection_uncertain'     => $projection['uncertain'],
                    // v3.7.0 (Schritt 3) — wirksamer Faktor; getrennt für Grundlast und Heizanteil (additiv)
                    'projection_factor'        => $projection['factor'],
                    'projection_factor_base'   => $projection['factor_base'],
                    'projection_factor_heat'   => $projection['factor_heat'],
                    // v2.16.0 (Review FE-31) — Monatsreihe für den Saldo-Verlauf,
                    // nur beim laufenden Vertrag (additiv)
                    'balance_path'             => $projection['path'],
                ];
            }
            // v3.9.0 (F1032, #40) — wie gerechnet wird, wenn ein Subzähler einen eigenen Vertrag hat:
            // gemessen − Subzähler = Rest, über den Zeitraum, den dieser Saldo zählt (additiv)
            $restOf = $this->restBreakdown($utility, $meter, $balanceFrom,
                $hasEnd ? date('Y-m-d', (int)strtotime($c['end'] . ' +1 day')) : '9999-12-31');
            if ($restOf !== null) $row['rest_of'] = $restOf;
            // v2.5.1 — die Einzelposten für die Tabelle „Verträge & Abschläge"
            // (Spalte Sonderzahlungen mit Tooltip). Nur dort, wo es
            // Sonderzahlungen überhaupt gibt (Gas/Strom/Fernwärme, seit v3.5.0
            // auch Wasser; nicht Einspeisung) — das Fehlen des Felds ist für
            // die UI das Signal, die Spalte gar nicht erst zu zeigen.
            if (Utilities::hasSpecialPayments($utility)) {
                $row['special_payments'] = array_values(array_map(fn($sp) => [
                    'date'       => (string)($sp['date'] ?? ''),
                    'kind'       => (string)($sp['kind'] ?? ''),
                    'amount_eur' => round((float)($sp['amount_eur'] ?? 0), 2),  // Vorzeichen steckt in kind; positiv seit dem Speichern
                    'note'       => (string)($sp['note'] ?? ''),
                // v3.6.0 (F1030) — die, die im Saldo zählen (ab balance_from)
                ], $cBalance['special_payments'] ?? []));
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
                    ] + (!empty($c['schmutzwasser']['base_prices'])   // v3.9.0 (F1034) — additiv, nur mit Grundpreis
                        ? ['base_cost' => round($swBase, 2), 'current_eur_per_month' => $curSwBp] : []),
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

        // v3.1.0 (H6, #17) — Mitglied einer Gruppe mit Gruppenvertrag: Verweis darauf (additiv)
        $res = ['contracts' => $out];
        if (empty($meter['is_group']) && !empty($meter['meter_group_id'])) {
            [, $ctx] = $this->contractScope($utility, $meter);
            if ($ctx !== null) {
                $gid = (string)$meter['meter_group_id'];
                $gc = $this->contracts->resolveForDate(array_values(array_filter($this->contracts->list($utility, $gid),
                    fn($c) => empty($c['is_shadow']) && ($c['meter_group_id'] ?? null) === $gid)), $today);
                $res['group_contract'] = ['group_id' => $gid, 'contract_id' => $gc['contract']['id'] ?? null];
            }
        }
        return $res;
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
    /**
     * v2.9.0 (Review CALC-11) — Die nächste angekündigte Preiserhöhung eines
     * Vertrags: der erste Stichtag nach heute, an dem Arbeits- oder Grundpreis
     * steigt. In Deutschland besteht dann ein Sonderkündigungsrecht zum
     * Zeitpunkt der Änderung (§ 41 Abs. 5 EnWG); die Oberfläche sagt das dazu.
     *
     * @return array{from:string,working_price_ct:?array{0:float,1:float},base_price_eur:?array{0:float,1:float}}|null
     */
    private function upcomingPriceIncrease(array $c, string $today): ?array
    {
        $dates = [];
        foreach (['working_prices', 'base_prices'] as $group) {
            foreach ($c[$group] ?? [] as $e) {
                $d = (string)($e['from'] ?? '');
                if ($d > $today && (empty($c['end']) || $d <= $c['end'])) $dates[$d] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        foreach ($dates as $d) {
            $out = ['from' => $d, 'working_price_ct' => null, 'base_price_eur' => null];
            foreach (['working_prices' => ['ct_per_kwh', 'working_price_ct'], 'base_prices' => ['eur_per_month', 'base_price_eur']] as $group => [$field, $key]) {
                $now = $this->contracts->valueOnDate($c[$group] ?? [], $field, $today);
                $new = $this->contracts->valueOnDate($c[$group] ?? [], $field, $d);
                if ($now !== null && $new !== null && $new > $now + 1e-9) $out[$key] = [$now, $new];
            }
            if ($out['working_price_ct'] !== null || $out['base_price_eur'] !== null) return $out;
        }
        return null;
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Vertrag ohne Arbeitspreis: Kosten und Saldo
     * enthalten dann nur Grundpreis und Abschläge. Vertragsstatus und
     * Abrechnungsjahr sagen es beide (`price_missing`), statt zwei verschiedene,
     * gleich falsche Salden zu zeigen. Einspeisung und dynamische Tarife
     * (Preise aus den Marktdaten) ausgenommen.
     */
    public static function lacksWorkingPrice(string $utility, array $c): bool
    {
        if (Utilities::isFeedIn($utility) || ($c['price_model'] ?? null) === 'dynamic') return false;
        [$list, $field] = $utility === 'wasser'
            ? [(array)($c['trinkwasser']['working_prices'] ?? []), 'ct_per_m3']
            : [(array)($c['working_prices'] ?? []), 'ct_per_kwh'];
        foreach ($list as $e) if (is_array($e) && is_numeric($e[$field] ?? null)) return false;
        return true;
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Der Tag, an dem ein Vertrag durch einen später
     * beginnenden Folgevertrag desselben Zählers abgelöst ist (Tag vor dessen
     * Beginn), wenn er dann noch liefe: offen, oder sein Ende liegt am oder nach
     * dem Beginn des Folgevertrags. Sonst null — ein Vertrag ohne Nachfolger und
     * eine lückenlose Kette (Ende = Tag vor dem Folgebeginn) bleiben, wie sie sind.
     */
    public static function supersededOn(array $c, array $contracts): ?string
    {
        // v3.7.0 (Schritt 3) — eine Regel mit der Zuordnung der Monate (ContractService::findActiveForDate),
        // auch für einen Folgevertrag ganz innerhalb des alten
        return ContractService::supersededOn($c, $contracts);
    }

    /**
     * v3.7.0 (#34-Nachzug) — Wasservertrag mit Abschlagsplan: Er bekommt den
     * Saldo nach Kalender (balanceProjection) wie Gas und Strom. Ohne Abschläge
     * bleibt es beim Saldo über die Monate mit Ablesung.
     */
    public static function waterWithAdvances(string $utility, array $c): bool
    {
        return $utility === 'wasser' && array_filter((array)($c['advance_payments'] ?? []), fn($a) => is_array($a) && is_numeric($a['amount_eur'] ?? null)) !== [];
    }

    /**
     * v3.7.0 (#34-Nachzug) — Ein Wasservertrag, wie ihn der Saldo nach Kalender
     * liest: Arbeitspreis je m³ = Trinkwasser + Schmutzwasser (bei eigenem bzw.
     * Garten-Abzugszähler mit dem gemessenen Anteil Schmutzwasser an der
     * Trinkwassermenge), feste Kosten je Monat = Grundpreis Trinkwasser +
     * (v3.9.0) Grundpreis Schmutzwasser + Niederschlagswasser (Fläche × €/m²·a
     * ÷ 12), je Stichtag einer der Listen.
     * Dieselben Bausteine wie applyWaterContracts().
     */
    private static function waterBalanceView(array $c, array $monthly): array
    {
        $tw = (array)($c['trinkwasser'] ?? []); $sw = (array)($c['schmutzwasser'] ?? []); $nw = (array)($c['niederschlagswasser'] ?? []);
        $share = 1.0;
        if (($sw['basis'] ?? 'trinkwasser') !== 'trinkwasser') {
            $a = 0.0; $b = 0.0;
            foreach ($monthly as $m) {
                if (($m['contract_id'] ?? null) !== ($c['id'] ?? null) || !is_array($m['schmutzwasser'] ?? null)) continue;
                $a += (float)($m['trinkwasser']['m3'] ?? $m['m3'] ?? 0); $b += (float)($m['schmutzwasser']['m3'] ?? 0);
            }
            $share = $a > 0 ? $b / $a : 1.0;
        }
        $at = function (array $list, string $field, string $date): ?float {
            $v = null; $best = '';
            foreach ($list as $e) {
                $f = (string)($e['from'] ?? '');
                if ($f === '' || $f > $date || !is_numeric($e[$field] ?? null) || $f < $best) continue;
                $best = $f; $v = (float)$e[$field];
            }
            return $v;
        };
        $dates = fn(array ...$lists) => array_values(array_unique(array_filter(array_merge(...array_map(fn($l) => array_column($l, 'from'), $lists)))));
        $wp = [];
        $wd = $dates((array)($tw['working_prices'] ?? []), (array)($sw['working_prices'] ?? []));
        sort($wd);
        foreach ($wd as $d) {
            $t = $at((array)($tw['working_prices'] ?? []), 'ct_per_m3', $d); $s = $at((array)($sw['working_prices'] ?? []), 'ct_per_m3', $d);
            if ($t !== null || $s !== null) $wp[] = ['from' => $d, 'ct_per_kwh' => round(($t ?? 0.0) + $share * ($s ?? 0.0), 4)];
        }
        $bp = [];
        // v3.9.0 (F1034) — dazu der Grundpreis des Schmutzwassers (fehlt er, wie bisher)
        $bd = $dates((array)($tw['base_prices'] ?? []), (array)($nw['rates'] ?? []), (array)($sw['base_prices'] ?? []));
        sort($bd);
        foreach ($bd as $d) {
            $base = $at((array)($tw['base_prices'] ?? []), 'eur_per_month', $d);
            $swBase = $at((array)($sw['base_prices'] ?? []), 'eur_per_month', $d);
            if ($swBase !== null) $base = ($base ?? 0.0) + $swBase;
            $rate = $at((array)($nw['rates'] ?? []), 'eur_per_m2_year', $d); $area = $at((array)($nw['rates'] ?? []), 'versiegelte_flaeche_m2', $d);
            if ($base !== null || ($rate !== null && $area !== null)) $bp[] = ['from' => $d, 'eur_per_month' => round(($base ?? 0.0) + ($rate !== null && $area !== null ? $rate * $area / 12 : 0.0), 4)];
        }
        return ['working_prices' => $wp, 'base_prices' => $bp] + $c;
    }

    private function balanceProjection(string $utility, array $meter, array $c, array $monthly, string $today, string $effEnd, float $specialNet, bool $renewed = false, bool $withPath = false,
        ?string $from = null, ?array $payments = null, ?string $endExclOverride = null): array
    {
        if ($utility === 'wasser') $c = self::waterBalanceView($c, $monthly);   // v3.7.0 (#34-Nachzug)
        // v3.6.0 (F1030) — `$from`: Saldo ab dem Tag nach der letzten Rechnung
        // (sonst Vertragsbeginn); `$payments`: die Sonderzahlungen, die dann zählen
        $start = $from ?? (string)($c['start'] ?? $today);
        $payments ??= $c['special_payments'] ?? [];
        // Ende exklusiv: ein gepflegtes Vertragsende gehört noch dazu, der
        // Abrechnungstag eines offenen oder weiterlaufenden Vertrags nicht mehr.
        // v3.9.0 (V1) — in der laufenden Abrechnungsperiode der Tag nach ihrem
        // letzten Tag (bzw. nach dem Vertragsende, wenn es vorher kommt)
        $endExcl = $endExclOverride
            ?? (!empty($c['end']) && !$renewed ? date('Y-m-d', strtotime($c['end'] . ' +1 day')) : $effEnd);
        if ($endExcl <= $start) $endExcl = date('Y-m-d', strtotime($start . ' +1 day'));
        $asOf = min(max($today, $start), $endExcl);   // heute, eingeklemmt in den Vertrag

        // Gemessen: Arbeitspreis der Monatszeilen dieses Vertrags — bei zwei
        // Verträgen in einem Monat nur sein Teil (v2.9.0)
        $measuredEnergy = 0.0;
        foreach ($monthly as $m) {
            $share = $from === null ? 1.0 : self::shareFrom((string)($m['ym'] ?? ''), $from);
            if ($share <= 0.0) continue;
            if (!empty($m['contract_parts'])) {
                foreach ($m['contract_parts'] as $p) {
                    if ($p['contract_id'] === $c['id']) $measuredEnergy += (float)$p['kwh_cost'] * $share;
                }
            } elseif (($m['contract_id'] ?? null) === $c['id']) {
                $measuredEnergy += (float)($m['kwh_cost'] ?? 0) * $share;
            }
        }
        $measuredUntil = $this->lastReadingDate($utility, $meter);
        $gapFrom = max($start, $measuredUntil ?? $start);

        $u   = Utilities::get($utility);
        $vf  = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $est = $this->volumeEstimator($utility, $monthly, $vf, $meter);
        // v3.9.0 (F1032) — Rest-Abrechnung: geschätzt wird die Restmenge (Zähler minus Subzähler mit eigenem Vertrag)
        $est = $this->restEstimator($utility, $meter, $est, $vf);
        $fallbackWp = null;
        foreach ($monthly as $m) if (!empty($m['working_price_ct'])) $fallbackWp = (float)$m['working_price_ct'];

        // v2.9.0 — je Tag zum an diesem Tag gültigen Arbeitspreis (CALC-10);
        // nach dem Ende eines weiterlaufenden Vertrags zu seinem letzten Preis
        $c = $this->contractView($utility, $meter, $c);   // v3.1.0 (H6) — HT/NT der Gruppe: Mischpreis
        $end = (string)($c['end'] ?? '');
        $energy = function (string $from, string $to, ?array $opt = null) use ($c, $est, $fallbackWp, $end): float {
            // v2.8.1 — ohne verwertbare Monate (noch keine zwei Ablesungen,
            // oder alle vor einer Zäsur) gibt es nichts hochzurechnen. In
            // v2.8.0 rief der Saldo hier null auf und brach mit HTTP 500 ab.
            if ($est === null) return 0.0;
            $sum = 0.0;
            foreach ($est($from, $to, $opt) as $date => $vol) {
                $priceDay = $end !== '' && $date > $end ? $end : $date;
                $wp = $this->contracts->valueOnDate($c['working_prices'] ?? [], 'ct_per_kwh', $priceDay) ?? $fallbackWp ?? 0.0;
                $sum += $vol * (float)$wp / 100.0;
            }
            return $sum;
        };
        $gapEnergy       = $gapFrom < $asOf ? $energy($gapFrom, $asOf) : 0.0;
        $remainingEnergy = $energy(max($asOf, $gapFrom), $endExcl);
        // v3.7.0 (Schritt 3) — dieselbe Energie mit anderen Annahmen (Spanne einer unsicheren Schätzung)
        $energyWith = fn(?array $opt): float => ($gapFrom < $asOf ? $energy($gapFrom, $asOf, $opt) : 0.0) + $energy(max($asOf, $gapFrom), $endExcl, $opt);
        $uncalibrated = $est !== null ? $energyWith(['base' => 1.0, 'heat' => 1.0]) : 0.0;
        $effFactor = $uncalibrated > 0 ? ($gapEnergy + $remainingEnergy) / $uncalibrated : 1.0;

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
        // v3.9.0 (F1034) — Plan mit Rhythmus: der Rest verteilt sich auf die Raten, die noch
        // fällig werden, und der Vorschlag gilt je Rate (nicht je Monat)
        $nowRow = ContractService::planRowOn($plan, $today);
        if ($nowRow !== null && ContractService::hasRhythm($nowRow)) {
            $monthsLeft = count(ContractService::installments($plan, date('Y-m-d', strtotime($asOf . ' +1 day')), $endExcl));
            $curAp = (float)$nowRow['amount_eur'];
        }
        if ($monthsLeft >= 1 && $curAp !== null && $asOf === $today) {
            $suggested = max(0.0, round((float)$curAp + $projected / $monthsLeft));
        }
        // v3.7.0 (wirklichkeit-2) — unsicher (Faktor klemmt, Heizmodell schlecht):
        // kein einzelner Vorschlag, sondern die Spanne. `suggested_advance` bleibt
        // dann null, damit kein Verbraucher einen falschen sicheren Wert zeigt.
        $estVolume = 0.0;   // geschätzte Menge (Lücke und Rest), unabhängig vom Preis
        if ($est !== null) {
            if ($gapFrom < $asOf) $estVolume += array_sum($est($gapFrom, $asOf));
            if (max($asOf, $gapFrom) < $endExcl) $estVolume += array_sum($est(max($asOf, $gapFrom), $endExcl));
        }
        $uncertain = $est !== null
            ? $this->projectionUncertainty($utility, $monthly, $meter, $gapEnergy + $remainingEnergy, $estVolume, $energyWith, $projected,
                $curAp !== null ? (float)$curAp : null, $monthsLeft, $asOf === $today)
            : null;
        if ($uncertain !== null) $suggested = null;
        // v3.9.0 (L12) — kurz vor der Abrechnung (weniger als SUGGEST_MIN_MONTHS
        // Abschläge bis dahin) kein Nachholbetrag: Den Fehlbetrag auf ein, zwei
        // Abschläge zu legen, vervielfacht sie („208 € statt 75 €“), und nach der
        // Abrechnung gilt wieder der laufende Bedarf. Den Rest gleicht die
        // Abrechnung aus; dazu der laufend angemessene Abschlag.
        $withheld = null;
        if ($monthsLeft >= 1 && $monthsLeft < self::SUGGEST_MIN_MONTHS && $asOf === $today && $curAp !== null) {
            if ($suggested !== null) $withheld = 'short';
            $suggested = null;
            if ($uncertain !== null) $uncertain['suggested_range'] = null;
        }
        // v3.9.0 (L12) — laufend angemessener Abschlag: geschätzte Kosten der zwölf
        // Monate nach diesem Abrechnungszeitraum (Arbeitspreis wie die Schätzung,
        // Grundpreis zum letzten Preis) ÷ 12, auf ganze Euro. Nur, wenn die
        // Schätzung sicher ist.
        $ongoing = null;
        if ($est !== null && $uncertain === null && $curAp !== null && $asOf === $today) {
            $next = date('Y-m-d', strtotime($endExcl . ' +1 year'));
            $priceDay = $end !== '' && $endExcl > $end ? $end : $endExcl;
            $fixed = (float)($this->contracts->fixedPerMonthOn($c, $priceDay) ?? 0.0);
            $yearCost = $energy($endExcl, $next) + 12 * $fixed;
            if ($yearCost > 0) $ongoing = (float)round($yearCost / 12);
        }

        // v2.16.0 (Review FE-31) — dieselbe Rechnung als Monatsreihe für den
        // Saldo-Verlauf: je Monat die Kosten (gemessen, geschätzt, Grundpreis,
        // Boni) und das Bezahlte (Abschläge, Sonderzahlungen), aufsummiert.
        // Der letzte Punkt ist der erwartete Endsaldo (Test hält es fest).
        $path = null;
        if ($withPath) {
            $startMonth = substr($start, 0, 7);
            $asOfMonth  = substr($asOf, 0, 7);
            // Sonderzahlungen in ihrem Monat — wie im Stand heute zählt jede
            // spätestens ab heute, eine vor dem Beginn ab dem ersten Monat
            $spByMonth = [];
            foreach ($payments as $sp) {
                $amt  = abs((float)($sp['amount_eur'] ?? 0));
                $kind = (string)($sp['kind'] ?? '');
                $net  = in_array($kind, ['rueckzahlung_mit', 'rueckzahlung_ohne'], true) ? $amt
                    : (in_array($kind, ['nachzahlung_mit', 'nachzahlung_ohne', 'abschlagszahlung'], true) ? -$amt : 0.0);
                if ($net === 0.0) continue;
                $ym = substr((string)($sp['date'] ?? ''), 0, 7);
                if (!preg_match('/^\d{4}-\d{2}$/', $ym) || $ym < $startMonth) $ym = $startMonth;
                if ($ym > $asOfMonth) $ym = $asOfMonth;
                $spByMonth[$ym] = ($spByMonth[$ym] ?? 0.0) + $net;
            }
            $measuredByMonth = [];
            foreach ($monthly as $m) {
                $ym = (string)($m['ym'] ?? '');
                $share = $from === null ? 1.0 : self::shareFrom($ym, $from);   // v3.6.0 — wie oben
                if ($share <= 0.0) continue;
                if (!empty($m['contract_parts'])) {
                    foreach ($m['contract_parts'] as $p) {
                        if ($p['contract_id'] === $c['id']) $measuredByMonth[$ym] = ($measuredByMonth[$ym] ?? 0.0) + (float)$p['kwh_cost'] * $share;
                    }
                } elseif (($m['contract_id'] ?? null) === $c['id']) {
                    $measuredByMonth[$ym] = ($measuredByMonth[$ym] ?? 0.0) + (float)($m['kwh_cost'] ?? 0) * $share;
                }
            }
            $cumCost = 0.0; $cumPaid = 0.0; $path = [];
            for ($t = strtotime($startMonth . '-01'); $t !== false && date('Y-m-d', $t) < $endExcl; $t = strtotime('+1 month', $t)) {
                $ym    = date('Y-m', $t);
                $dim   = (int)date('t', $t);
                $cFrom = max(date('Y-m-d', $t), $start);
                $cTo   = min(date('Y-m-d', strtotime('+1 month', $t)), $endExcl);
                $cDays = max(0, (int)round((strtotime($cTo) - strtotime($cFrom)) / 86400));
                if ($cDays === 0) continue;
                $estimated = $cTo > $gapFrom ? $energy(max($cFrom, $gapFrom), $cTo) : 0.0;
                $base = 0.0;
                $segments = $this->contracts->segmentsBetween([$c], $cFrom, $cTo);
                foreach ($segments as $seg) {
                    if ($seg['contract'] === null) continue;
                    $bp = $this->contracts->fixedPerMonthOn($c, ContractService::priceDate($seg));
                    if ($bp !== null) $base += $bp * $seg['days'] / $dim;
                }
                $bonus  = $this->contracts->bonusForMonth($c, (int)date('Y', $t), (int)date('n', $t));
                $apDate = $segments !== [] ? ContractService::priceDate($segments[0]) : $cFrom;
                // v3.9.0 (F1034) — dieselben Abschläge wie calendarSums (Raten mit Rhythmus in ihrem Monat)
                $ap     = ContractService::advanceIn($plan, $apDate, $cFrom, $cTo, min(1.0, $cDays / $dim), $dim);
                $cumCost += ($measuredByMonth[$ym] ?? 0.0) + $estimated + $base - $bonus;
                $cumPaid += ($ap ?? 0.0) - ($spByMonth[$ym] ?? 0.0);
                $path[] = [
                    'ym'        => $ym,
                    'cost'      => round($cumCost, 2),
                    'paid'      => round($cumPaid, 2),
                    'balance'   => round($cumCost - $cumPaid, 2),
                    'estimated' => $cTo > $gapFrom,   // enthält geschätzte Tage
                    'future'    => $ym > $asOfMonth,
                ];
            }
        }

        return [
            'as_of'                    => $asOf,
            'method'                   => $est === null ? 'flat_average' : 'forecast',
            // v3.7.0 (Schritt 3) — wirksamer Faktor über Grundlast und Heizanteil (geschätzt ÷ unkalibriert)
            'factor'                   => $est === null ? null : round($effFactor, 2),
            'factor_base'              => $est === null ? null : round($this->lastProjection['base'], 2),
            'factor_heat'              => $est === null || $this->lastProjection['model'] === null ? null : round($this->lastProjection['heat'], 2),
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
            // v3.9.0 (L12) — Abschläge bis zur Abrechnung, laufend angemessener Abschlag,
            // warum kein Vorschlag (`short`: weniger als SUGGEST_MIN_MONTHS Monate)
            'months_left'              => $monthsLeft,
            'advance_ongoing'          => $ongoing,
            'suggestion_withheld'      => $withheld,
            'uncertain'                => $uncertain,
            'path'                     => $path,
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

            // Grundpreis: Tage vor heute nach „bis heute", ab heute nach
            // „Rest" — v2.9.0 je Preisabschnitt tagesgenau (CALC-10)
            $splitAt  = min(max($asOf, $cFrom), $cTo);
            $segments = $this->contracts->segmentsBetween([$c], $cFrom, $cTo);
            foreach ($segments as $seg) {
                if ($seg['contract'] === null) continue;
                $bp = $this->contracts->fixedPerMonthOn($c, ContractService::priceDate($seg));
                if ($bp === null) continue;
                $before = $started
                    ? max(0, min($seg['days'], (int)round((strtotime($splitAt) - strtotime($seg['from'])) / 86400)))
                    : 0;
                $out['to_date']['base'] += $bp * $before / $dim;
                $out['rest']['base']    += $bp * ($seg['days'] - $before) / $dim;
            }
            // Boni nur in der eigentlichen Laufzeit (Gutschrift nach dem Ende
            // zählt im letzten Vertragsmonat, s. bonusForMonth)
            $out[$bucket]['bonus'] += $this->contracts->bonusForMonth($c, $y, $mo);
            // Abschlag: der Betrag am ersten Vertragstag des Monats, anteilig
            $apDate = $segments !== [] ? ContractService::priceDate($segments[0]) : $cFrom;
            // v3.9.0 (F1034) — Raten eines Plans mit Rhythmus ganz an ihrem Tag: bis heute, wenn bis heute fällig
            $adv = ContractService::advanceParts($plan, $apDate, $cFrom, $cTo, min(1.0, $cDays / $dim), $dim);
            if ($adv['monthly'] !== null) $out[$bucket]['advance'] += $adv['monthly'];
            foreach ($adv['rates'] as $r) $out[$started && $r['date'] <= $asOf ? 'to_date' : 'rest']['advance'] += $r['amount_eur'];
        }
        return $out;
    }

    /**
     * Schätzt den Verbrauch je Tag für [von, bis) — für Lücke und Rest des
     * Saldos. Heizarten mit Heizmodell: je Tag a × HGT + c (HGT aus der
     * Tagestemperatur, für Tage ohne Wert aus dem Normal des Monats); sonst die
     * Tagesrate desselben Kalendermonats aus vollen Monaten.
     *
     * @internal v3.7.0 — öffentlich nur über contractStatus(); Tests rufen per Reflection
     * @return (callable(string,string,?array):array<string,float>)|null  Tag → Menge
     */
    private function volumeEstimator(string $utility, array $monthly, string $vf, ?array $meter = null): ?callable
    {
        $minDays = (int)$this->settings->get('min_days_period', 20);
        // v3.7.0 (#37) — dasselbe Heizmodell wie Analyse und Prognose (verteilte
        // Monate nicht im Fit, sonst über die Ablesungsabstände)
        $heat = $this->heatModel($utility, $monthly, $meter);
        // v3.7.0 (Nachlauf vor dem Release) — die Aufteilung der Kalibrierung auf Grundlast
        // und Heizanteil (Schritt 3, N5) setzt voraus, dass das Modell beide richtig trennt.
        // Besteht es die Gütesperre nicht, stimmt genau diese Trennung nicht (bei echten Daten
        // Grundlast etwa ein Zehntel des gemessenen Sommers): Der Grundlast-Faktor klemmte an
        // 1,5, der Heizanteil kam aus Übergangsmonaten und drückte den Winter. Dann ein Faktor
        // für das Ganze wie bis Schritt 2 — die Schätzung ist ohnehin als unsicher gekennzeichnet.
        $splitOk = $heat !== null && ($this->anomalyQuality($utility, $monthly)['model_quality'] ?? null) !== 'poor';
        // v3.7.0 (#37) — Tagesrate je Kalendermonat aus gemessenen Monaten; nur wo
        // ein Kalendermonat nie gemessen wurde, aus den verteilten (wie die Prognose).
        // Das Mittel aller Monate stimmt auch für verteilte.
        $rates = []; $interpRates = []; $all = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline']) || (int)($m['days'] ?? 0) < $minDays) continue;
            $r = (float)($m[$vf] ?? 0) / (int)$m['days'];
            if (!empty($m['interpolated'])) $interpRates[(int)$m['month']][] = $r;
            else $rates[(int)$m['month']][] = $r;
            $all[] = $r;
        }
        foreach ($interpRates as $mo => $list) $rates[$mo] ??= $list;
        // v3.7.0 (Schritt 3, N1) — je Kalendermonat wie die Prognose (ohne Wetterbezug ab drei Werten der Median)
        $hgtUtility = Utilities::isHgtRelevant($utility);
        $rates = array_map(fn(array $list): float => (float)self::seasonalRate($list, !$hgtUtility), $rates);
        if ($heat === null && $all === []) return null;
        $overall = $all ? array_sum($all) / count($all) : 0.0;
        $temps = $this->temperatures();
        $base = $this->hddBaseFor($meter);   // v3.7.0 — die Heizgrenze des Zählers
        $normals = $this->hddNormals($base);

        // Kalibrierung am jüngsten Niveau: Liegen die letzten gemessenen
        // Monate (bis zu sechs volle aus den letzten zwölf) deutlich unter oder
        // über dem Modell — neue Heizung ohne Zäsur, anderes Verhalten —, folgt
        // die Schätzung diesem Niveau. Begrenzt auf 0,5 bis 1,5, damit ein
        // einzelner Ausnahmemonat die Hochrechnung nicht kippt.
        // v3.7.0 (#37) — nur gemessene Monate: Ein verteilter Monat trägt die
        // Tagesrate eines längeren Abstands, sein „Niveau“ ist ein Artefakt; ohne
        // zwei gemessene Monate im letzten Jahr bleibt der Faktor 1
        $recent = array_slice(array_values(array_filter($monthly, fn($m) =>
            empty($m['pre_baseline']) && empty($m['interpolated']) && (int)($m['days'] ?? 0) >= $minDays
            && (string)($m['ym'] ?? '') >= date('Y-m', strtotime(date('Y-m-01') . ' -12 months')))), -6);
        $clamp = fn(?float $r): float => $r === null ? 1.0 : max(self::PROJECTION_FACTOR_MIN, min(self::PROJECTION_FACTOR_MAX, $r));
        if ($heat !== null && !$splitOk) {
            // ein Faktor über Grundlast und Heizanteil (Nachlauf, s. o.), gerechnet wie bis Schritt 2
            $act = $exp = 0.0;
            foreach ($recent as $m) {
                $e = $m['expected_heat'] ?? null;
                if ($e === null || $e <= 0) continue;
                $act += (float)($m[$vf] ?? 0); $exp += (float)$e;
            }
            $rawBase = $rawHeat = count($recent) >= 2 && $exp > 0 ? $act / $exp : null;
            $fBase = $fHeat = $clamp($rawBase);
        } elseif ($heat !== null) {
            // v3.7.0 (wirklichkeit-2, Schritt 3) — der Faktor wirkt nur auf den Teil, aus dem
            // er stammt: Monate fast ohne Heizung (Heizanteil der Erwartung unter
            // CALIBRATION_HEAT_SHARE) kalibrieren die Grundlast, Heizmonate den Heizanteil.
            // Bei echten Daten kam der Faktor aus Sommer- und Übergangsmonaten und skalierte
            // die ganze Winterschätzung mit.
            $base0 = $baseE = $heatAct = $heatE = $heatBaseE = 0.0; $nBase = $nHeat = 0;
            foreach ($recent as $m) {
                $days = (int)($m['days'] ?? 0);
                $eb = $heat['c'] * $days;
                $eh = $heat['a'] * (float)($m['hdd'] ?? 0);
                if ($eb + $eh <= 0 || ($m['expected_heat'] ?? null) === null) continue;
                $act = (float)($m[$vf] ?? 0);
                if ($eh / ($eb + $eh) < self::CALIBRATION_HEAT_SHARE) { $base0 += $act; $baseE += $eb + $eh; $nBase++; }
                else { $heatAct += $act; $heatE += $eh; $heatBaseE += $eb; $nHeat++; }
            }
            $rawBase = $nBase >= 2 && $baseE > 0 ? $base0 / $baseE : null;
            $fBase = $clamp($rawBase);
            $rawHeat = $nHeat >= 2 && $heatE > 0 ? ($heatAct - $fBase * $heatBaseE) / $heatE : null;
            $fHeat = $clamp($rawHeat);
        } else {
            $act = $exp = 0.0;
            foreach ($recent as $m) {
                $e = isset($rates[(int)$m['month']]) ? $rates[(int)$m['month']] * (int)$m['days'] : null;
                if ($e === null || $e <= 0) continue;
                $act += (float)($m[$vf] ?? 0);
                $exp += (float)$e;
            }
            $rawBase = $rawHeat = count($recent) >= 2 && $exp > 0 ? $act / $exp : null;
            $fBase = $fHeat = $clamp($rawBase);
        }
        $this->lastProjection = ['base' => $fBase, 'heat' => $fHeat, 'raw_base' => $rawBase, 'raw_heat' => $rawHeat, 'model' => $heat,
            'split' => $splitOk];

        // `$opt` (v3.7.0) — für die Spanne einer unsicheren Schätzung: andere Faktoren
        // (`base`, `heat`) oder eine andere Grundlast je Tag (`c`, etwa die gemessene)
        return function (string $from, string $to, ?array $opt = null) use ($heat, $rates, $overall, $temps, $base, $normals, $fBase, $fHeat): array {
            $fb = (float)($opt['base'] ?? $fBase);
            $fh = (float)($opt['heat'] ?? $fHeat);
            $c = $heat !== null ? (float)($opt['c'] ?? $heat['c']) : 0.0;
            $out = [];
            for ($t = strtotime($from . ' 12:00:00'); $t !== false && date('Y-m-d', $t) < $to; $t += 86400) {
                $d = date('Y-m-d', $t);
                $mo = (int)date('n', $t);
                if ($heat !== null) {
                    $hdd = isset($temps[$d]['avg'])
                        ? max(0.0, $base - (float)$temps[$d]['avg'])
                        : (($normals[$mo]['mean'] ?? null) !== null ? $normals[$mo]['mean'] / (int)date('t', $t) : 0.0);
                    $vol = $heat['a'] * $hdd * $fh + $c * $fb;
                } else {
                    $vol = ($rates[$mo] ?? $overall) * $fb;
                }
                // v2.9.0 — je Tag: der Arbeitspreis gilt tagesgenau (CALC-10)
                $out[$d] = $vol;
            }
            return $out;
        };
    }

    /**
     * v3.7.0 (Schritt 3) — Ab diesem Heizanteil der Erwartung zählt ein Monat bei der
     * Kalibrierung als Heizmonat; darunter kalibriert er die Grundlast. 0,3: ein Monat,
     * dessen Erwartung zu weniger als knapp einem Drittel aus Heizung besteht, sagt über
     * den Heizanteil zu wenig, sein Rest wäre fast nur Rauschen der Grundlast.
     */
    public const CALIBRATION_HEAT_SHARE = 0.3;

    /**
     * v3.7.0 (wirklichkeit-2, Schritt 3, N1) — Tagesrate eines Kalendermonats aus
     * seinen Jahren: ab drei Werten der Median, sonst das Mittel (null ohne Wert).
     * Ein einzelner Ausnahmemonat (Urlaub, halber Wert) verschob das Mittel und
     * damit jede folgende Prognose und Schätzung dieses Monats; der Median von
     * drei Jahren lässt ihn liegen. Bei zwei Werten gibt es keine Mitte — dort
     * zeigt die Streuung des Prognosebands die Unsicherheit.
     *
     * `$median` false: das Mittel (Heizarten). Dort ist der Wert eines Kalendermonats
     * vor allem das Wetter seines Winters — der Median wählte einen Winter, statt sie
     * zu mitteln, und die Prognose mischt ohnehin mit dem Heizmodell.
     *
     * @param list<float> $rates
     */
    public static function seasonalRate(array $rates, bool $median = true): ?float
    {
        $n = count($rates);
        if ($n === 0) return null;
        if ($n < 3 || !$median) return array_sum($rates) / $n;
        sort($rates);
        return $n % 2 ? (float)$rates[intdiv($n, 2)] : ($rates[$n / 2 - 1] + $rates[$n / 2]) / 2;
    }

    /**
     * Kalibrierung der letzten Saldo-Schätzung: Faktoren für Grundlast und Heizanteil
     * (begrenzt), dieselben vor dem Begrenzen (null ohne Kalibrierung) und das Heizmodell.
     *
     * `split`: getrennt kalibriert (Gütesperre bestanden) oder ein Faktor für das Ganze.
     *
     * @var array{base:float,heat:float,raw_base:?float,raw_heat:?float,model:?array,split:bool}
     */
    private array $lastProjection = ['base' => 1.0, 'heat' => 1.0, 'raw_base' => null, 'raw_heat' => null, 'model' => null, 'split' => false];

    /**
     * Grenzen des Kalibrierfaktors der Saldo-Schätzung: Ein einzelner
     * Ausnahmemonat soll die Hochrechnung nicht kippen. Klemmt der Faktor an
     * einer Grenze, folgt die Schätzung dem jüngsten Niveau nur zum Teil — und
     * ob das Niveau echt ist oder ein Fehler des Heizmodells, weiß die App
     * nicht (v3.7.0: dann ist der Abschlagsvorschlag unsicher).
     */
    public const PROJECTION_FACTOR_MIN = 0.5;
    public const PROJECTION_FACTOR_MAX = 1.5;

    /**
     * v3.7.0 (wirklichkeit-2) — Gütesperre des Heizmodells für einen Zähler
     * (AnomalyService::quality), hier für Saldo-Schätzung und Prognose.
     *
     * @return array<string,mixed>|null
     */
    public function anomalyQuality(string $utility, array $monthly): ?array
    {
        return (new AnomalyService($this->regression ?? new RegressionService(), $this->settings))->quality($utility, $monthly);
    }

    /**
     * v3.7.0 (wirklichkeit-2, GitHub #38) — Heizgrenze eines Zählers: seine
     * eigene (`hdd_base_temp` am Zähler, etwa aus dem Heizgrenze-Vorschlag),
     * sonst die Einstellung. Die beste Grenze ist je Haus verschieden; eine
     * Gruppe ohne eigene nimmt die Einstellung, ihre Mitglieder rechnen je mit
     * ihrer.
     */
    public function hddBaseFor(?array $meter): float
    {
        $own = $meter['hdd_base_temp'] ?? null;
        return is_numeric($own) ? (float)$own : (float)$this->settings->get('hdd_base_temp', 15.0);
    }

    /** v3.7.0 (wirklichkeit-2) — Raster des Heizgrenze-Vorschlags in °C: von, bis, Schritt. */
    public const HEAT_LIMIT_GRID = [6.0, 18.0, 0.5];
    /**
     * v3.7.1 (#38) — Obergrenze Erwartung ÷ gemessener Sommer für einen Vorschlag: Eine
     * Grenze, deren Modell die gemessenen Sommermonate um mehr als 10 % überschätzt, wird
     * nicht angeboten. Ein einzelner Sommermonat schwankt im Alltag um 10 bis 20 %, das Mittel
     * über die Sommer mehrerer Jahre deutlich weniger; beim Melder lag das Modell der
     * vorgeschlagenen 9,5 °C im Juli und August 24 bis 25 % darüber, das zu 12 °C 1 %.
     * Nach unten gilt die Grenze der Gütesperre (AnomalyService::BASE_LOAD_MIN).
     */
    public const HEAT_LIMIT_SUMMER_MAX = 1.10;
    /** Mindestens so viele gemessene, geprüfte Monate (ein ganzer Jahreslauf mit Übergangszeiten). */
    public const HEAT_LIMIT_MIN_MONTHS = 12;
    /**
     * „Deutlich besser“: höchstens zwei Drittel der heutigen Streuung und
     * mindestens 3 Prozentpunkte weniger. Die robuste Streuung (MAD) schwankt
     * bei zwei Jahren Monatswerten um rund ein Fünftel ihres Werts; ein Drittel
     * weniger liegt deutlich darüber. Die Punkte verhindern Vorschläge zwischen
     * zwei Modellen, die beide gut passen (4 % statt 6 %).
     */
    public const HEAT_LIMIT_GAIN = 2 / 3;
    public const HEAT_LIMIT_MIN_DROP = 0.03;
    /**
     * v3.9.0 (#38) — Glättung des Rasters: Gewählt wird nach dem Mittel der Streuung
     * aller Grenzen bis 1 °C daneben (fünf Rasterpunkte), nicht nach dem einzelnen
     * Punkt. Bei zwei Jahren Monatswerten springt die Streuung von Punkt zu Punkt um ein
     * Viertel und mehr (beim Maintainer 12,6 / 4,9 / 8,7 % bei 8 / 9 / 10 °C); ein
     * einzelner tiefer Punkt ist dann eher Zufall als die richtige Grenze.
     */
    public const HEAT_LIMIT_SMOOTH_K = 1.0;
    /**
     * v3.9.0 (#38) — Höchstens jeder zehnte Monat darf beim Fit als Ausreißer herausfallen
     * (mindestens einer: Urlaub, defekte Therme). Fallen mehr heraus, beschreibt das Modell
     * einen Teil der Monate gar nicht; seine kleine Streuung über den Rest ist kein Gewinn.
     * Beim Maintainer fielen bei 9 °C 6 von 22 Monaten heraus, bei den Nachbarn 0 bis 2.
     */
    public const HEAT_LIMIT_MAX_DROPPED = 0.10;
    /**
     * v3.9.0 (#38) — Plateau: Grenzen, deren geglättete Streuung höchstens 15 % über der
     * besten liegt und die lückenlos an sie anschließen, passen gleich gut (das Rauschen
     * der robusten Streuung liegt bei rund einem Fünftel). Vorgeschlagen wird daraus die
     * Grenze, die der heutigen am nächsten liegt: so wenig ändern wie nötig. Die Spanne
     * steht im Vorschlag (`range`).
     */
    public const HEAT_LIMIT_PLATEAU = 0.15;

    /**
     * v3.7.0 (wirklichkeit-2) — Heizgrenze-Vorschlag: Mit welcher Heizgrenze
     * streut das Heizmodell über die gemessenen Monate am wenigsten? Gerechnet
     * wird wie die Gütesperre (robuste Streuung ÷ mittlerer gemessener Monat,
     * AnomalyService::quality) für jede Grenze des Rasters; Grenzen, deren Modell
     * die Grundlast-Prüfung nicht besteht, scheiden aus. Vorgeschlagen wird nur,
     * was deutlich besser ist (HEAT_LIMIT_GAIN, HEAT_LIMIT_MIN_DROP) — nie
     * automatisch übernommen. Unter HEAT_LIMIT_MIN_MONTHS gemessenen Monaten kein
     * Vorschlag. Bei echten Daten lag das Optimum weit unter 15 °C.
     *
     * v3.9.0 (#38) — je Rasterpunkt dazu: Fit-Punkte und verworfene Monate (`fit_n`,
     * `fit_dropped`, `fit_ok`), die warmen Monate Mai bis September (`warm_ratio`,
     * `warm_ok`) und die geglättete Streuung (`smoothed`, pickHeatLimit). Vorher und
     * nachher in Zahlen (`before`, `after`: Streuung, Grundlast je Tag laut Modell und
     * gemessen). Auch für Zählergruppen (deren Monatsreihe hat ein eigenes Heizmodell).
     *
     * @return array<string,mixed>|null
     */
    public function heatLimitSuggestion(string $utility, array $meter): ?array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return null;
        $current = $this->hddBaseFor($meter);
        $nowRows = $this->forMeter($utility, $meter);
        $now = $this->anomalyQuality($utility, $nowRows);
        if ($now === null || (int)$now['months'] < self::HEAT_LIMIT_MIN_MONTHS) return null;
        [$from, $to, $step] = self::HEAT_LIMIT_GRID;
        $grid = []; $qs = [];
        // v3.9.0 (#38) — die Gradtage jeder Grenze aus demselben Durchlauf (forMeter merkt sie sich)
        $hddGrid = $this->hddGridMemo[$utility . '|' . md5(serialize($meter)) . '||' . $this->store->generation()] ?? null;
        for ($b = $from; $b <= $to + 1e-9; $b += $step) {
            $b = round($b, 1);
            $rows = abs($b - $current) < 1e-9 ? $nowRows : ($this->rowsAtBase($utility, $meter, $nowRows, $hddGrid, $b) ?? $this->forMeter($utility, $meter, $b));
            $q = abs($b - $current) < 1e-9 ? $now : $this->anomalyQuality($utility, $rows);
            if ($q === null) continue;
            // v3.7.1 (#38) — dazu: überschätzt das Modell die gemessenen Sommer (dieselben
            // Monate für jede Grenze, AnomalyService::summerReference)?
            $summer = isset($q['base_load']['ratio']) ? (float)$q['base_load']['ratio'] : null;
            // v3.9.0 (#38) — wie viele Monate der Fit verworfen hat, und die warmen Monate
            $model = $this->heatModel($utility, $rows, $meter, $b);
            $fitN = (int)($model['n'] ?? 0);
            // verworfen und mehr als das Alltagsrauschen daneben (10 % eines typischen Monats,
            // AnomalyService::noiseFloor): Fast rauschfreie Monate fallen bei winziger Streuung
            // ebenfalls heraus, beschreiben das Modell aber richtig
            $all = (array)($model['dropped'] ?? []);
            $dropped = count(array_filter($all, fn($p) => abs($p[1] - $p[0]) > AnomalyService::noiseFloor((float)$p[0], (float)$q['typical'])));
            $warm = isset($q['warm']['ratio']) ? (float)$q['warm']['ratio'] : null;
            $grid[] = ['base' => $b, 'ratio' => (float)$q['ratio'], 'base_load_ok' => !in_array('base_load', (array)$q['reasons'], true),
                'summer_ratio' => $summer, 'summer_ok' => $summer !== null && $summer <= self::HEAT_LIMIT_SUMMER_MAX,
                'fit_n' => $fitN, 'fit_dropped' => $dropped, 'fit_ok' => self::fitKeepsMonths($fitN + count($all), $dropped),
                'warm_ratio' => $warm, 'warm_ok' => $warm === null || $warm <= self::HEAT_LIMIT_SUMMER_MAX];
            $qs[sprintf('%.1f', $b)] = $q;
        }
        $pick = self::pickHeatLimit($grid, $current, (float)$now['ratio']);
        // die geglättete Streuung je Punkt in die Antwort (dieselbe Rechnung wie die Wahl)
        $smooth = self::smoothGrid($grid);
        foreach ($grid as $i => &$g) $g['smoothed'] = round($smooth[$i], 3);
        unset($g);
        $facts = fn(?array $q) => $q === null ? null : [
            'ratio'            => (float)$q['ratio'],
            'model_quality'    => (string)$q['model_quality'],
            'base_per_day'     => $q['base_load']['base_per_day'] ?? null,
            'model_per_day'    => $q['base_load']['model_per_day'] ?? null,
            'measured_per_day' => $q['base_load']['measured_per_day'] ?? null,
        ];
        return $pick + [
            // die Grenze gilt je Zähler; `default` ist die Einstellung, `own` ob er eine eigene hat
            'default'    => (float)$this->settings->get('hdd_base_temp', 15.0),
            'own'        => is_numeric($meter['hdd_base_temp'] ?? null),
            'current_ok' => $now['model_quality'] === 'good',
            'months'     => (int)$now['months'],
            // v3.9.0 (#38) — vorher und nachher, für „Zu tun“ und die Hinweise
            'before'     => $facts($now),
            'after'      => $pick['best'] !== null ? $facts($qs[sprintf('%.1f', $pick['best'])] ?? null) : null,
            'grid'       => $grid,
        ];
    }

    /**
     * v3.9.0 (#38) — Die Monatszeilen eines Zählers mit einer anderen Heizgrenze, ohne den
     * Zähler neu zu rechnen: Verbrauch, Tage und Abdeckung hängen nicht an der Grenze, nur
     * die Gradtage (aus dem Raster von enrichWithWeather, gerundet wie dort) und was das
     * Heizmodell daraus macht (applyHeatModel mit derselben Grenze). Dasselbe Ergebnis wie
     * forMeter($utility, $meter, $base) für alles, was Gütesperre und Vorschlag lesen.
     * Null, wenn eine Zeile ihr Raster nicht hat (Gruppe, Liefertank): dann der lange Weg.
     *
     * @param array<string,array<string,float>>|null $hddGrid Monat → Grenze → Gradtage
     */
    private function rowsAtBase(string $utility, array $meter, array $rows, ?array $hddGrid, float $base): ?array
    {
        if ($hddGrid === null || $rows === []) return null;
        $k = sprintf('%.1f', $base);
        foreach ($rows as &$r) {
            $h = $hddGrid[(string)($r['ym'] ?? '')][$k] ?? null;
            if ($h === null) return null;
            $r['hdd'] = $h;
        }
        unset($r);
        $vf = (Utilities::get($utility)['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';
        return $this->applyHeatModel($rows, $utility, $vf, $meter, $base);
    }

    /**
     * v3.9.0 (#38) — Was eine andere Heizgrenze am laufenden Vertrag ändert: erwarteter
     * Saldo und Abschlagsvorschlag heute und mit `$base` (dieselbe Rechnung wie der
     * Vertragsstatus, der Zähler nur mit der anderen Grenze). Ist eine Schätzung
     * unsicher, steht ihre Spanne statt des Betrags da (`balance_range`,
     * `advance_range`). Null ohne laufenden Vertrag (oder für Einspeisung).
     *
     * @return array{contract_id:string,until:?string,current_advance:?float,before:array,after:array}|null
     */
    public function heatLimitEffect(string $utility, array $meter, float $base): ?array
    {
        if (!Utilities::hasContracts($utility) || Utilities::isFeedIn($utility)) return null;
        $cur = function (array $m) use ($utility): ?array {
            foreach ($this->contractStatus($utility, $m)['contracts'] ?? [] as $c) if (!empty($c['is_current'])) return $c;
            return null;
        };
        $other = $meter;
        $other['hdd_base_temp'] = round($base, 1);
        $now = $cur($meter);
        $then = $cur($other);
        if ($now === null || $then === null) return null;
        $facts = fn(array $c) => [
            'balance'       => isset($c['projected_end_balance']) ? round((float)$c['projected_end_balance'], 2) : null,
            'balance_range' => $c['projection_uncertain']['projected_range'] ?? null,
            'advance'       => isset($c['suggested_advance']) ? (float)$c['suggested_advance'] : null,
            'advance_range' => $c['projection_uncertain']['suggested_range'] ?? null,
            'uncertain'     => isset($c['projection_uncertain']) ? (array)($c['projection_uncertain']['reasons'] ?? []) : [],
        ];
        return [
            'contract_id'     => (string)$now['contract_id'],
            'until'           => $now['effective_end'] ?? null,
            'current_advance' => isset($now['current_advance_amount']) ? (float)$now['current_advance_amount'] : null,
            'before'          => $facts($now),
            'after'           => $facts($then),
        ];
    }

    /**
     * v3.9.0 (#38) — Verwirft der Fit höchstens HEAT_LIMIT_MAX_DROPPED der Monate (mindestens
     * einen) als echte Ausreißer? `$points`: alle Punkte vor dem Robustheitsschritt;
     * `$misfit`: verworfene, die mehr als das Alltagsrauschen danebenliegen.
     */
    public static function fitKeepsMonths(int $points, int $misfit): bool
    {
        return $misfit <= max(1, (int)floor(self::HEAT_LIMIT_MAX_DROPPED * $points + 1e-9));
    }

    /**
     * v3.9.0 (#38) — geglättete Streuung je Rasterpunkt: Mittel aller Punkte bis
     * HEAT_LIMIT_SMOOTH_K daneben (alle berechneten, auch ausgeschiedene: Ihre Streuung
     * ist gemessen, nur ihr Modell taugt nicht zum Vorschlag).
     *
     * @param list<array{base:float,ratio:float}> $grid
     * @return array<int,float>
     */
    public static function smoothGrid(array $grid): array
    {
        $out = [];
        foreach ($grid as $i => $g) {
            $sum = 0.0; $n = 0;
            foreach ($grid as $h) {
                if (abs((float)$h['base'] - (float)$g['base']) > self::HEAT_LIMIT_SMOOTH_K + 1e-9) continue;
                $sum += (float)$h['ratio']; $n++;
            }
            $out[$i] = $n > 0 ? $sum / $n : (float)$g['ratio'];
        }
        return $out;
    }

    /**
     * Die Wahl des Heizgrenze-Vorschlags aus dem Raster (rein, testbar): die
     * Grenze mit der kleinsten Streuung unter denen, deren Modell die
     * Grundlast-Prüfung besteht und (v3.7.1, #38) die gemessenen Sommer nicht
     * überschätzt (`summer_ok`); vorgeschlagen nur, wenn sie deutlich besser ist
     * als heute (HEAT_LIMIT_GAIN, HEAT_LIMIT_MIN_DROP).
     *
     * v3.9.0 (#38) — geglättet (smoothGrid) statt Punkt für Punkt; ausgeschieden ist
     * auch, wessen Fit zu viele Monate verwirft (`fit_ok`) oder wessen Modell die
     * warmen Monate überschätzt (`warm_ok`). Aus dem Plateau gleich guter Grenzen
     * (HEAT_LIMIT_PLATEAU) die, die der heutigen am nächsten liegt; `range` ist das
     * Plateau. „Deutlich besser“ misst die geglättete Streuung, `best_ratio` ist die
     * Streuung, die die Gütesperre mit der Grenze zeigen wird.
     *
     * @param list<array{base:float,ratio:float,base_load_ok:bool,summer_ok?:bool,fit_ok?:bool,warm_ok?:bool}> $grid
     * @return array{current:float,current_ratio:float,best:?float,best_ratio:?float,best_smoothed:?float,range:?array{0:float,1:float},suggest:bool}
     */
    public static function pickHeatLimit(array $grid, float $current, float $ratioNow): array
    {
        usort($grid, fn($a, $b) => $a['base'] <=> $b['base']);
        $grid = array_values($grid);
        $smooth = self::smoothGrid($grid);
        $ok = fn(array $g): bool => $g['base_load_ok'] && ($g['summer_ok'] ?? true) && ($g['fit_ok'] ?? true) && ($g['warm_ok'] ?? true);
        $bi = null;
        foreach ($grid as $i => $g) {
            if (!$ok($g)) continue;
            if ($bi === null || $smooth[$i] < $smooth[$bi] - 1e-9) $bi = $i;
        }
        $range = null;
        if ($bi !== null) {
            // Plateau: lückenlos anschließende, gleich gute Grenzen
            $limit = $smooth[$bi] * (1 + self::HEAT_LIMIT_PLATEAU) + 1e-9;
            [$lo, $hi] = [$bi, $bi];
            while ($lo > 0 && $ok($grid[$lo - 1]) && $smooth[$lo - 1] <= $limit) $lo--;
            while ($hi < count($grid) - 1 && $ok($grid[$hi + 1]) && $smooth[$hi + 1] <= $limit) $hi++;
            $range = [(float)$grid[$lo]['base'], (float)$grid[$hi]['base']];
            // so wenig ändern wie nötig: die Grenze des Plateaus, die der heutigen am nächsten liegt
            for ($i = $lo; $i <= $hi; $i++) {
                if (abs($grid[$i]['base'] - $current) < abs($grid[$bi]['base'] - $current) - 1e-9) $bi = $i;
            }
        }
        $best = $bi !== null ? $grid[$bi] : null;
        $bestSmooth = $bi !== null ? $smooth[$bi] : null;
        // (die heutige Grenze kann so nie vorgeschlagen werden: Sie ist nicht deutlich besser als sie selbst)
        $suggest = $best !== null && abs($best['base'] - $current) > 1e-9
            && $bestSmooth <= $ratioNow * self::HEAT_LIMIT_GAIN && $ratioNow - $bestSmooth >= self::HEAT_LIMIT_MIN_DROP;
        return [
            'current'       => $current,
            'current_ratio' => round($ratioNow, 3),
            'best'          => $best['base'] ?? null,
            'best_ratio'    => $best !== null ? round($best['ratio'], 3) : null,
            'best_smoothed' => $bestSmooth !== null ? round($bestSmooth, 3) : null,
            'range'         => $range,
            'suggest'       => $suggest,
        ];
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Ist die Schätzung von Lücke und Rest des Saldos
     * unsicher? Dann gibt es keinen einzelnen Abschlagsvorschlag, sondern eine
     * Spanne über dieselbe Energie mit anderen Annahmen (`$energyWith`):
     *
     *   factor_clamped  ein Kalibrierfaktor (Grundlast oder Heizanteil) klemmt
     *                   an einer Grenze: das jüngste Niveau ganz oder gar nicht
     *   few_measured    (Schritt 3, N3) das Heizmodell steht nur auf den
     *                   Ablesungsabständen, oder die Gütesperre kann es nicht
     *                   prüfen: die ganze Kalibrierspanne (0,5 … 1,5)
     *   poor_fit        das Heizmodell besteht die Gütesperre nicht: jede Annahme
     *                   zusätzlich ± Streuung je Monat (Verhältnis der Gütesperre);
     *                   der Fehler einer konvexen Heizkurve ist systematisch, er
     *                   mittelt sich über die Monate nicht heraus. Fällt die
     *                   Grundlast-Prüfung, reicht die Spanne bis zur gemessenen
     *                   Sommer-Grundlast (N4).
     *
     * Ohne Heizmodell (Wasser, Strom) gibt es keine Gütesperre und keinen
     * Heizanteil, der über das Jahr fortgeschrieben wird: Ein nie gemessener
     * Kalendermonat nimmt die Tagesrate seines Ablesungsabstands, die sich vom
     * Monat nur um den kleinen Jahresgang unterscheidet — dort kein Grund.
     *
     * Bei echten Daten lag der Vorschlag mit klemmendem Faktor rund ein Drittel
     * zu niedrig; ein sicher wirkender Betrag ist dann schlimmer als keiner.
     *
     * @return array<string,mixed>|null null = sicher genug (oder nichts mehr zu schätzen)
     */
    private function projectionUncertainty(string $utility, array $monthly, array $meter, float $estimatedEnergy, float $estimatedVolume,
        callable $energyWith, float $projected, ?float $curAp, int $monthsLeft, bool $suggestable): ?array
    {
        $p = $this->lastProjection;
        $out = fn(?float $r): bool => $r !== null && ($r < self::PROJECTION_FACTOR_MIN || $r > self::PROJECTION_FACTOR_MAX);
        $reasons = [];
        $energies = [$estimatedEnergy];
        $baseLoad = null;
        // Faktor klemmt (Grundlast oder Heizanteil, jeder Teilfaktor zählt): das jüngste Niveau ganz oder gar nicht
        $clamped = null;
        if ($out($p['raw_heat'])) $clamped = [$out($p['raw_base']) ? 'both' : 'heat', $p['raw_heat'], $p['heat']];
        elseif ($out($p['raw_base'])) $clamped = ['base', $p['raw_base'], $p['base']];
        if ($clamped !== null) {
            $reasons[] = 'factor_clamped';
            $energies[] = $energyWith(['base' => max(0.0, $p['raw_base'] ?? $p['base']), 'heat' => max(0.0, $p['raw_heat'] ?? $p['heat'])]);
            $energies[] = $energyWith(['base' => 1.0, 'heat' => 1.0]);
        }
        // v3.7.0 (Schritt 3, N3) — zu wenig gemessen: Das Heizmodell steht nur auf den
        // Ablesungsabständen (oder fehlt), oder die Gütesperre kann es nicht prüfen. Dann
        // ist die ganze Kalibrierspanne offen (0,5 … 1,5 der Schätzung) — dieselben
        // Grenzen, in denen die App einem jüngsten Niveau überhaupt folgt.
        $hgt = Utilities::isHgtRelevant($utility) && !Utilities::isDelivery($utility);
        $quality = $hgt ? $this->anomalyQuality($utility, $monthly) : null;
        if ($hgt && (($p['model']['basis'] ?? null) !== 'months' || $quality === null)) {
            $reasons[] = 'few_measured';
            $energies[] = $estimatedEnergy * self::PROJECTION_FACTOR_MIN;
            $energies[] = $estimatedEnergy * self::PROJECTION_FACTOR_MAX;
        }
        $ratio = null;
        if (($quality['model_quality'] ?? null) === 'poor') {
            $reasons[] = 'poor_fit';
            $ratio = (float)$quality['ratio'];
            // v3.7.0 (Schritt 3, N4) — fällt die Grundlast-Prüfung, schätzt das Modell die
            // Sommertage mit fast null: die Spanne reicht dann bis zur gemessenen Grundlast
            if (in_array('base_load', (array)$quality['reasons'], true) && is_numeric($quality['base_load']['measured_per_day'] ?? null)) {
                $baseLoad = ['model' => (float)$quality['base_load']['model_per_day'], 'measured' => (float)$quality['base_load']['measured_per_day']];
                $energies[] = $energyWith(['c' => $baseLoad['measured'], 'base' => 1.0]);   // gemessen: nicht noch einmal kalibrieren
            }
            $energies = array_merge(...array_map(fn($e) => [$e * max(0.0, 1.0 - $ratio), $e * (1.0 + $ratio)], $energies));
        }
        // v3.7.0 (Schritt 3) — nichts mehr zu schätzen (abgerechnet, beendet): keine Spanne der
        // Breite null. Nachlauf: entschieden über die geschätzte Menge, nicht über Euro — sonst
        // fiel ein unsicherer Vertrag ohne Arbeitspreis für die geschätzten Tage still heraus
        if ($reasons === [] || $estimatedVolume <= 0.0) return null;
        $range = [round($projected + min($energies) - $estimatedEnergy, 2), round($projected + max($energies) - $estimatedEnergy, 2)];
        $sugg = null;
        if ($suggestable && $curAp !== null && $monthsLeft >= 1) {
            $sugg = [max(0.0, round($curAp + $range[0] / $monthsLeft)), max(0.0, round($curAp + $range[1] / $monthsLeft))];
            // keine Spanne der Breite null („zwischen 0 € und 0 €“, wenn beide Enden ins Guthaben fallen)
            if ($sugg[0] === $sugg[1]) $sugg = null;
        }
        return [
            'reasons'          => $reasons,
            'factor'           => $clamped !== null ? round($clamped[2], 2) : round($p['heat'], 2),
            'factor_raw'       => $clamped !== null ? round($clamped[1], 2) : null,
            'factor_part'      => $clamped[0] ?? null,
            'factor_limits'    => [self::PROJECTION_FACTOR_MIN, self::PROJECTION_FACTOR_MAX],
            'fit_ratio'        => $ratio !== null ? round($ratio, 3) : null,
            // N4: Grundlast je Tag laut Modell und gemessen, wenn die Spanne die gemessene nimmt
            'base_load_per_day' => $baseLoad,
            'projected_range'  => $range,
            'suggested_range'  => $sugg,
        ];
    }

    /** Datum der letzten gültigen Ablesung (Plausibilitätsprüfung wie die Rechnung). */
    private function lastReadingDate(string $utility, array $meter): ?string
    {
        if (!Utilities::isCumulative($utility)) return null;
        // v3.7.0 (Qualität) — je Anfrage einmal je Zähler (wie forMeter): Der
        // Vertragsstatus fragt es für jeden Vertrag und las dafür jedes Mal
        // alle Ablesungen — bei zehn Jahren Tageswerten mit einem Vertrag je
        // Jahr drei Viertel seiner Antwortzeit.
        $key = $utility . '|' . md5(serialize($meter)) . '|' . $this->store->generation();
        if (array_key_exists($key, $this->lastReadingMemo)) return $this->lastReadingMemo[$key];
        // v3.1.0 (H6) — Gruppe: gemessen bis zum frühesten letzten Stand der Mitglieder
        if (!empty($meter['is_group'])) {
            $dates = array_filter(array_map(fn($m) => $this->lastReadingDate($utility, $m), $this->meters->groupMembers($utility, (string)$meter['id'])));
            return $this->lastReadingMemo[$key] = $dates ? min($dates) : null;
        }
        [$kept] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
        return $this->lastReadingMemo[$key] = $kept ? (string)end($kept)['date'] : null;
    }

    /** @var array<string,?string> v3.7.0 — lastReadingDate() je Anfrage */
    private array $lastReadingMemo = [];

    /** @var array{0:int,1:array<string,mixed>}|null v3.7.0 — temperatures.json je Anfrage [Generation, Daten] */
    private ?array $tempsMemo = null;

    /**
     * v3.7.0 (Qualität) — Tagestemperaturen, je Anfrage einmal gelesen (bis
     * zum nächsten Schreiben über den Store). Bisher las jede Monatsrechnung
     * und jede Saldo-Schätzung je Vertrag die ganze Datei neu.
     *
     * @return array<string,mixed>
     */
    private function temperatures(): array
    {
        $gen = $this->store->generation();
        if ($this->tempsMemo === null || $this->tempsMemo[0] !== $gen) {
            $t = $this->store->read('temperatures.json', []);
            $this->tempsMemo = [$gen, is_array($t) ? $t : []];
        }
        return $this->tempsMemo[1];
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
     * v3.6.0 (F1030) — Bis wohin ist abgerechnet? Der Tag nach dem spätesten
     * Ende einer erfassten Versorgerrechnung vor heute, deren Ergebnis im
     * Vertrag gebucht ist (Rück- oder Nachzahlung, BillService::book) — für
     * den Zähler bzw. die Mitglieder einer Gruppe, nur Arten mit
     * Rechnungsprüfung. Eine Rechnung ohne Buchung lässt ihr Ergebnis offen;
     * es bleibt im Saldo, bis es gebucht ist. Dazu die IDs dieser Buchungen.
     *
     * @param list<array<string,mixed>> $contracts  die Verträge des Zählers (Sonderzahlungen)
     * @return array{0:?string,1:array<string,true>}
     */
    private function billWindow(string $utility, array $meter, string $today, array $contracts = []): array
    {
        if (!Utilities::supportsBillCheck($utility)) return [null, []];
        $ids = !empty($meter['is_group'])
            ? array_map(fn($m) => (string)($m['id'] ?? ''), $this->meters->groupMembers($utility, (string)$meter['id']))
            : [(string)($meter['id'] ?? '')];
        $known = [];
        foreach ($contracts as $c) foreach ((array)($c['special_payments'] ?? []) as $sp) {
            if (!empty($sp['id'])) $known[(string)$sp['id']] = true;
        }
        $bills = $this->store->read("$utility/bills.json", []);
        $last = null; $payments = [];
        foreach (is_array($bills) ? $bills : [] as $b) {
            if (!is_array($b) || !in_array((string)($b['meter_id'] ?? ''), $ids, true)) continue;
            $sp = (string)($b['special_payment_id'] ?? '');
            if ($sp === '' || !isset($known[$sp])) continue;   // nicht (mehr) gebucht
            $f = (string)($b['period_from'] ?? ''); $u = (string)($b['period_to'] ?? '');
            if (!Dates::isIsoDate($f) || !Dates::isIsoDate($u) || $f > $u || $u >= $today) continue;
            $payments[$sp] = true;
            if ($last === null || $u > $last) $last = $u;
        }
        return [$last !== null ? date('Y-m-d', (int)strtotime("$last +1 day")) : null, $payments];
    }

    /**
     * v3.6.0 (F1030) — Anteil eines Monats ab einem Tag (nach Kalendertagen):
     * 1 nach dem Monat des Tags, 0 davor, im Monat selbst die Tage ab ihm.
     */
    private static function shareFrom(string $ym, string $from): float
    {
        $fym = substr($from, 0, 7);
        if ($ym > $fym) return 1.0;
        if ($ym < $fym) return 0.0;
        $dim = (int)date('t', (int)strtotime($fym . '-01'));
        return ($dim - (int)substr($from, 8, 2) + 1) / $dim;
    }

    /**
     * v3.6.0 (F1030) — Monatszeilen eines Vertrags ab einem Tag: davor weg,
     * der Monat des Tags anteilig nach Kalendertagen (Menge, Kosten,
     * Abschlag, Wasser je Baustein).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function rowsFrom(array $rows, string $from): array
    {
        $out = [];
        foreach ($rows as $r) {
            $s = self::shareFrom((string)($r['ym'] ?? ''), $from);
            if ($s <= 0.0) continue;
            if ($s < 1.0) {
                foreach (['kwh', 'billed_kwh', 'm3', 'cost', 'kwh_cost', 'base_price_eur', 'bonus_eur', 'advance_eur'] as $k) {
                    if (isset($r[$k]) && is_numeric($r[$k])) $r[$k] = (float)$r[$k] * $s;
                }
                foreach (['trinkwasser' => ['working_cost', 'base_cost', 'total'], 'schmutzwasser' => ['working_cost', 'base_cost', 'total'], 'niederschlagswasser' => ['total']] as $part => $keys) {
                    if (!is_array($r[$part] ?? null)) continue;
                    foreach ($keys as $k) if (isset($r[$part][$k]) && is_numeric($r[$part][$k])) $r[$part][$k] = (float)$r[$part][$k] * $s;
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * v3.9.0 (V1) — Die laufende Abrechnungsperiode eines Zählers bzw. einer
     * Gruppe (BillingPeriodService::runningPeriodFor), je Anfrage einmal.
     *
     * @return array<string,mixed>|null
     */
    public function runningPeriod(string $utility, array $meter, string $today): ?array
    {
        $key = $utility . '|' . ($meter['id'] ?? '') . '|' . $today . '|' . $this->store->generation();
        if (array_key_exists($key, $this->runningMemo)) return $this->runningMemo[$key];
        try {
            $p = $this->billingPeriods()->runningPeriodFor($utility, $meter, $today);
        } catch (\InvalidArgumentException) {
            $p = null;
        }
        return $this->runningMemo[$key] = $p;
    }

    /** @var array<string,?array> v3.9.0 — runningPeriod() je Anfrage */
    private array $runningMemo = [];

    private ?BillingPeriodService $billingPeriods = null;

    /** v3.9.0 (V1) — Abrechnungsjahre für den Vertragsstatus (lazy, ohne Verdrahtung: dieselben Bausteine). */
    private function billingPeriods(): BillingPeriodService
    {
        return $this->billingPeriods ??= new BillingPeriodService($this->settings, $this->meters, $this->readings, $this,
            new BillService($this->store, $this->meters, $this->contracts, $this, $this->i18n));
    }

    /**
     * v3.9.0 (L1) — letzter Tag eines Abrechnungszeitraums aus `effective_end`:
     * das gepflegte (bzw. wirksame) Vertragsende selbst, sonst der Tag vor dem
     * Stichtag der nächsten Abrechnung.
     */
    private static function lastDayOf(string $effEnd, ?string $contractEnd): string
    {
        if ($contractEnd !== null && $contractEnd === $effEnd) return $effEnd;
        return (new \DateTimeImmutable($effEnd))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * v3.9.0 (V1) — Sonderzahlungen, die im Saldo einer angeschnittenen
     * Abrechnungsperiode zählen: nur zusätzliche Abschlagszahlungen, datiert in
     * [von, bis] (bis = Abrechnungstag; ohne ihn alle ab von), kein Ausgleich
     * einer erfassten Rechnung. Rück- und Nachzahlungen gleichen eine frühere
     * Abrechnung aus — dieselbe Regel wie im Abrechnungsjahr.
     *
     * @param list<array<string,mixed>> $payments
     * @param array<string,true> $billPayments
     * @return list<array<string,mixed>>
     */
    private static function periodPayments(array $payments, string $from, ?string $until, array $billPayments): array
    {
        return array_values(array_filter($payments, fn($sp) => is_array($sp)
            && (string)($sp['kind'] ?? '') === 'abschlagszahlung'
            && (string)($sp['date'] ?? '') >= $from && ($until === null || (string)($sp['date'] ?? '') <= $until)
            && !isset($billPayments[(string)($sp['id'] ?? '')])));
    }

    /**
     * v3.6.0 (F1030) — Sonderzahlungen, die im Saldo ab einem Tag zählen: ab
     * dem Tag datiert und kein Ausgleich einer erfassten Rechnung (der gehört
     * zu deren Zeitraum).
     *
     * @param list<array<string,mixed>> $payments
     * @param array<string,true> $billPayments
     * @return list<array<string,mixed>>
     */
    private static function paymentsFrom(array $payments, string $from, array $billPayments): array
    {
        return array_values(array_filter($payments, fn($sp) => is_array($sp)
            && (string)($sp['date'] ?? '') >= $from && !isset($billPayments[(string)($sp['id'] ?? '')])));
    }

    /**
     * F-03: the next billing-cycle anchor date (>= today) for a utility.
     *
     * The anchor is a Settings value `billing_cycle_anchor_<utility>` in
     * 'MM-TT' form (default '01-01' = Kalenderjahr). Used to project the
     * Saldo of open-ended contracts up to the next Jahresabrechnung instead
     * of the arbitrary "today + 12 months" of v1.0.x.
     */
    /**
     * v3.7.0 (#33) — der letzte Tag der Periode hinter `effective_end` eines
     * Vertragsstatus. Ist `effective_end` das gepflegte Ende des Vertrags,
     * ist es selbst der letzte Tag (einschließlich). Sonst ist es der
     * Stichtag der nächsten Abrechnung, also der erste Tag der nächsten
     * Periode, und die Periode endet am Tag davor — auch beim weiterlaufenden
     * Vertrag (sein Ende liegt vor heute) und bei der Einspeisung, wenn der
     * Stichtag vor dem Ende kommt. Dieselbe Grenze wie `$endExcl` in
     * balanceProjection().
     */
    public static function periodLastDay(array $status): ?string
    {
        // v3.9.0 (L1) — der Vertragsstatus sagt es selbst (auch beim abgelösten Vertrag,
        // dessen wirksames Ende nicht das gespeicherte ist)
        if (is_string($status['settle_on'] ?? null) && $status['settle_on'] !== '') return $status['settle_on'];
        $eff = $status['effective_end'] ?? null;
        if (!is_string($eff) || $eff === '') return null;
        if ($eff === (string)($status['end'] ?? '')) return $eff;
        return (new \DateTimeImmutable($eff))->modify('-1 day')->format('Y-m-d');
    }

    private function nextBillingAnchor(string $utility, string $today): string
    {
        $anchor = (string)$this->settings->get('billing_cycle_anchor_' . $utility, '01-01');
        // v2.9.0 (CALC-24) — auch Form-gültige, aber unmögliche Tage („13-45")
        // aus Import oder API fallen auf den Jahresbeginn zurück
        if (!SettingsService::isMonthDay($anchor)) {
            $anchor = '01-01';
        }
        preg_match('/^(\d{2})-(\d{2})$/', $anchor, $mm);
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
            // v3.1.0 (H6, #17) — eine Zählergruppe als Summe ihrer Mitglieder
            $rows = !empty($meter['is_group'])
                ? $this->computeForGroup($utility, $meter, $hddBaseOverride)
                : (Utilities::isDelivery($utility)
                    ? $this->computeForDeliveryMeter($utility, $meter, $hddBaseOverride)
                    : $this->computeForMeter($utility, $meter, $hddBaseOverride));
            // v3.9.0 (#38) — die Gradtage des Heizgrenze-Rasters je Monat (enrichWithWeather) gehören
            // nicht in die Antwort; gemerkt für heatLimitSuggestion()
            $grid = [];
            foreach ($rows as &$r) {
                if (!is_array($r) || !array_key_exists('_hdd_grid', $r)) continue;
                $grid[(string)($r['ym'] ?? '')] = $r['_hdd_grid'];
                unset($r['_hdd_grid']);
            }
            unset($r);
            $this->hddGridMemo[$memoKey] = $grid;
            return $this->forMeterMemo[$memoKey] = $rows;
        } finally {
            array_pop($this->meterComputeStack);
        }
    }

    /** @var array<string,array<string,array<string,float>>> v3.9.0 (#38) — je forMeter-Aufruf: Monat → Grenze → Gradtage */
    private array $hddGridMemo = [];

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
     * v3.9.0 (ablesung-pruefen) — IDs der Ablesungen, die die Rechnung übergeht (verdächtig,
     * eingeklemmter Ausreißer), dieselbe Prüfung wie plausibleReadings(), ohne die ganze
     * Monatsrechnung: Die Agenda prüft bei jedem Aufruf alle Zähler (ReadingCheckService).
     *
     * @param list<array<string,mixed>>|null $readings die Ablesungen des Zählers, sonst gelesen
     * @return array<string,true>
     */
    public function skippedReadingIds(string $utility, array $meter, ?array $readings = null): array
    {
        [, $warnings] = $this->plausibleReadings($readings ?? $this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
        $out = [];
        foreach ($warnings as $w) if (!empty($w['reading_id'])) $out[(string)$w['reading_id']] = true;
        return $out;
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
        $temps    = $this->temperatures();
        $today    = date('Y-m-d');
        $hddBase  = $hddBaseOverride ?? $this->hddBaseFor($meter);   // v3.7.0 — je Zähler
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

        // v3.1.0 (Paket H3, B2) — Zähler mit Verbrauch je Zeitraum: Die
        // Intervalle kommen direkt aus den Zeiträumen (Beginn bis Ende + 1 Tag,
        // Tagesrate = Wert / Tage). Danach dieselben Schritte wie bei Ständen.
        if (($meter['capture'] ?? 'counter') === 'period') {
            $warnKey = $utility . '|' . ($meter['id'] ?? '');
            $this->readingWarnings[$warnKey] = [];
            $this->readingWarningsGen[$warnKey] = $this->store->generation();
            [$monthly, $coverage] = $this->periodMonths($utility, $meter, $factorOn, $factorBoundaries);
            if ($monthly === []) return [];
            return $this->finishMonthly($monthly, $coverage, $utility, $meter, $temps, $hddBase);
        }

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

        // v2.8.0 — ym → abgedeckte Tagesspannen (`$coverage`)
        [$monthly, $coverage] = $this->readingMonths($actual, $prices, $devicesById, $meter, $factorOn, $factorBoundaries, $warnKey);

        // v3.9.0 (ablesung-pruefen) — Stände „nur für die Abrechnung“ (`is_billing_only`):
        // Rechnung, Saldo und Kosten rechnen mit ihnen wie bisher (`$coverage` und `cost`
        // dieser Rechnung), die Monatswerte überspringen sie. Der Abstand von der Ablesung
        // davor bis zur nächsten ohne Merker wird nach dem Wetter verteilt; die Monatszeile
        // trägt dann `bridged` und für die Verträge `_bill_kwh` (s. finishMonthly).
        $bridges = $this->billingOnlyBridges($actual, $devicesById, $meter);
        if ($bridges['skip'] !== []) {
            $analysis = array_values(array_filter($actual, fn($r, $i) => !isset($bridges['skip'][$i]), ARRAY_FILTER_USE_BOTH));
            $weights = [];
            $model = null;
            $exclusion = $this->markBaselineMonths(array_map(fn($ym) => ['ym' => $ym], array_keys($monthly)), $meter);
            foreach ($bridges['spans'] as [$from, $to]) {
                $weights["$from|$to"] = $this->bridgeDayWeights($utility, $meter, $from, $to, $hddBase, $model, $exclusion);
            }
            [$aMonthly] = $this->readingMonths($analysis, $prices, $devicesById, $meter, $factorOn, $factorBoundaries, null, $weights);
            foreach ($monthly as $ym => &$row) {
                $a = $aMonthly[$ym] ?? null;
                if ($a === null || empty($a['_bridged'])) continue;
                $row['_bill_kwh'] = $row['kwh'];
                foreach (['kwh', 'raw', '_measured_kwh', '_measured_days', '_gap_days'] as $k) $row[$k] = $a[$k] ?? 0;
                if (!empty($a['_gap_est'])) $row['_gap_est'] = true; else unset($row['_gap_est']);
                $row['bridged'] = true;
            }
            unset($row);
        }

        return $this->finishMonthly($monthly, $coverage, $utility, $meter, $temps, $hddBase);
    }

    /**
     * Monatssummen aus aufeinanderfolgenden Ablesungen (aus computeForMeter()
     * herausgelöst, v3.9.0): je Abstand die Tagesrate, tagesgenau auf Monate und
     * Faktor-Stichtage verteilt; dazu die Tagesabschnitte (`$coverage`) und die
     * Buchführung „gemessen“.
     *
     * v3.9.0 (ablesung-pruefen) — `$weights` je Abstand („von|bis“): Gewichte je Tag
     * (Mittel 1) für einen überbrückten Abstand; die Monatszeile trägt dann `_bridged`.
     * Ohne Eintrag gleichmäßig wie bisher. `$warnKey` null: keine Warnungen (zweiter
     * Durchlauf ohne die Stände „nur für die Abrechnung“).
     *
     * @param array<string,?array<string,float>> $weights
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,list<array>>}
     */
    private function readingMonths(array $actual, array $prices, array $devicesById, array $meter,
                                   callable $factorOn, callable $factorBoundaries, ?string $warnKey, array $weights = []): array
    {
        $monthly = [];
        $coverage = [];
        // v3.7.0 (#37) — je Ablesepaar die Länge des Ablesungsabstands, aus dem es stammt
        $gaps = self::measuringGaps($actual);
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
                if ($warnKey !== null && $consumptionRaw !== null && ($prev['device_id'] ?? null) === ($curr['device_id'] ?? null)) {
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
            $bridgeKey    = $prev['date'] . '|' . $curr['date'];
            $bridged      = array_key_exists($bridgeKey, $weights);

            foreach ($this->distributeToMonths(
                $prev['date'], $curr['date'], $rawPerDay, $pricePerUnit,
                $factorOn, $factorBoundaries($prev['date'], $curr['date']), $bridged ? $weights[$bridgeKey] : null
            ) as $ym => $v) {
                if (!isset($monthly[$ym])) {
                    $monthly[$ym] = ['kwh' => 0.0, 'raw' => 0.0, 'days' => 0, 'cost' => 0.0];
                }
                $monthly[$ym]['kwh']  += $v['kwh'];
                $monthly[$ym]['raw']  += $v['raw'];
                $monthly[$ym]['days'] += $v['days'];
                $monthly[$ym]['cost'] += $v['cost'];
                self::addMeasured($monthly[$ym], $v, $gaps[$i], !empty($prev['is_estimated']) || !empty($curr['is_estimated']));
                if ($bridged) $monthly[$ym]['_bridged'] = true;
                foreach ($v['spans'] as $span) $coverage[$ym][] = $span;
            }
        }
        return [$monthly, $coverage];
    }

    /**
     * v3.9.0 (ablesung-pruefen) — Welche Stände „nur für die Abrechnung“ die
     * Monatswerte überspringen: Läufe aufeinanderfolgender Stände mit Merker, mit
     * einer Ablesung ohne Merker davor und danach auf demselben Gerät und rechenbaren
     * Abständen dazwischen. Ein Stand ohne Ablesung danach (oder über einen
     * Zählertausch) bleibt, wie er ist: Überbrücken geht erst mit einem Stand danach.
     *
     * @param list<array<string,mixed>> $actual plausible Ablesungen, nach Datum sortiert
     * @return array{skip: array<int,true>, spans: list<array{0:string,1:string}>} Indizes in `$actual`, überbrückte Abstände [von, bis)
     */
    private function billingOnlyBridges(array $actual, array $devicesById, array $meter): array
    {
        $out = ['skip' => [], 'spans' => []];
        $n = count($actual);
        $dev = fn(array $r): ?string => $r['device_id'] ?? $this->deviceIdOnDate($meter, (string)$r['date']);
        for ($i = 1; $i < $n - 1; $i++) {
            if (empty($actual[$i]['is_billing_only'])) continue;
            $j = $i;
            while ($j + 1 < $n && !empty($actual[$j + 1]['is_billing_only'])) $j++;
            if ($j + 1 >= $n) break;   // kein Stand danach
            $ok = (string)$actual[$i - 1]['date'] < (string)$actual[$j + 1]['date'];
            $d0 = $dev($actual[$i - 1]);
            for ($k = $i; $ok && $k <= $j + 1; $k++) {
                $raw = $this->consumptionBetween($actual[$k - 1], $actual[$k], $devicesById, $meter);
                $ok = $dev($actual[$k]) === $d0 && $raw !== null && $raw >= 0;
            }
            if ($ok) {
                for ($k = $i; $k <= $j; $k++) $out['skip'][$k] = true;
                $out['spans'][] = [(string)$actual[$i - 1]['date'], (string)$actual[$j + 1]['date']];
            }
            $i = $j;
        }
        return $out;
    }

    /**
     * v3.9.0 (ablesung-pruefen) — Gewichte je Tag (Mittel 1) für einen überbrückten
     * Abstand [from, to): „nach dem Wetter“, also a × Gradtage + c mit dem Heizmodell
     * aus den Ablesungsabständen (heatIntervalPoints, ohne die Zeit vor der Zäsur) und
     * der Heizgrenze des Zählers. Tage ohne Temperatur bekommen die Gradtage des
     * Normals für ihren Monat. Null heißt gleichmäßig wie bisher: keine Heizart, kein
     * Modell (zu wenig Abstände), ohne Heizsignal oder mit mehr als einem Zehntel
     * Tagen ohne Temperatur und Normal.
     *
     * @param array{a:float,c:float}|false|null $model wird beim ersten Aufruf gerechnet (false = keins)
     * @param list<array<string,mixed>> $exclusion Monate mit `pre_baseline`
     * @return array<string,float>|null
     */
    private function bridgeDayWeights(string $utility, array $meter, string $from, string $to, float $hddBase, mixed &$model, array $exclusion): ?array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return null;
        if ($model === null) {
            $pts = $this->heatIntervalPoints($utility, $meter, $exclusion, $hddBase);
            $model = count($pts) >= self::MIN_POINTS_REGRESSION ? self::fitHeatPoints($pts) : false;
        }
        if ($model === false || $model['a'] <= 0) return null;
        $temps = $this->temperatures();
        $normals = $this->hddNormals($hddBase);
        $hdd = []; $missing = 0;
        for ($t = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 00:00:00'); $t < $e; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (isset($temps[$d]['avg'])) { $hdd[$d] = max(0.0, $hddBase - (float)$temps[$d]['avg']); continue; }
            $norm = $normals[(int)date('n', $t)]['mean'] ?? null;
            if ($norm === null) { $missing++; $hdd[$d] = null; continue; }
            $hdd[$d] = (float)$norm / (int)date('t', $t);
        }
        $n = count($hdd);
        if ($n === 0 || $missing > $n / 10) return null;
        $known = array_filter($hdd, fn($v) => $v !== null);
        $fill = $known !== [] ? array_sum($known) / count($known) : 0.0;
        $w = [];
        foreach ($hdd as $d => $h) $w[$d] = $model['a'] * ($h ?? $fill) + $model['c'];
        $sum = array_sum($w);
        if ($sum <= 0) return null;
        foreach ($w as $d => $v) $w[$d] = $v * $n / $sum;
        return $w;
    }

    /**
     * v3.7.0 (#37) — Länge des Ablesungsabstands je Ablesepaar (Index des
     * späteren Stands): von der letzten echten Ablesung am oder vor dem Paar
     * bis zur ersten echten danach. Geschätzte Stände messen nichts; fehlt
     * eine echte Ablesung auf einer Seite, ist der Abstand unbegrenzt.
     *
     * @param list<array<string,mixed>> $rows  nach Datum sortiert; `date`, `is_estimated` (Ablesungen) bzw. `from`/`to` (Zeiträume)
     * @return array<int,int>
     */
    private static function measuringGaps(array $rows): array
    {
        $n = count($rows);
        $before = []; $last = null;
        for ($i = 0; $i < $n; $i++) {
            if (empty($rows[$i]['is_estimated'])) $last = $i;
            $before[$i] = $last;
        }
        $after = []; $next = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            if (empty($rows[$i]['is_estimated'])) $next = $i;
            $after[$i] = $next;
        }
        $out = [];
        for ($i = 1; $i < $n; $i++) {
            $a = $before[$i - 1]; $b = $after[$i];
            $out[$i] = $a === null || $b === null ? PHP_INT_MAX
                : (int)(new \DateTime((string)$rows[$a]['date']))->diff(new \DateTime((string)$rows[$b]['date']))->days;
        }
        return $out;
    }

    /**
     * v3.7.0 (#37) — Buchführung „gemessen“ je Monat: Verbrauch und Tage aus
     * Abständen bis MEASURED_GAP_MAX_DAYS, dazu der längste Abstand im Monat.
     *
     * @param array<string,mixed> $row  Monatssumme (wird ergänzt)
     * @param array{kwh:float,days:int} $part  Anteil eines Abstands an diesem Monat
     */
    private static function addMeasured(array &$row, array $part, int $gap, bool $estimatedSupport = false): void
    {
        $measured = $gap <= self::MEASURED_GAP_MAX_DAYS;
        $row['_measured_kwh']  = ($row['_measured_kwh'] ?? 0.0) + ($measured ? (float)$part['kwh'] : 0.0);
        $row['_measured_days'] = ($row['_measured_days'] ?? 0) + ($measured ? (int)$part['days'] : 0);
        $row['_gap_days']      = max((int)($row['_gap_days'] ?? 0), $gap);
        // v3.7.0 (Nachlauf) — verteilt an einer geschätzten Ablesung als Stütze: je Seite eine eigene Tagesrate
        if (!$measured && $estimatedSupport) $row['_gap_est'] = true;
    }

    /**
     * v3.7.0 (#37, #39) — die Kennung je Monatszeile aus der Buchführung:
     *
     *   measured_share    Anteil des Monatsverbrauchs aus Ablesungsabständen
     *                     bis MEASURED_GAP_MAX_DAYS (ohne Verbrauch: Anteil der Tage)
     *   interpolated      true, wenn dieser Anteil unter MEASURED_SHARE_MIN liegt —
     *                     der Wert ist dann vor allem verteilt, nicht gemessen
     *   reading_gap_days  längster Ablesungsabstand, aus dem der Monat stammt
     *                     (null, wenn eine echte Ablesung auf einer Seite fehlt)
     *
     * Die Monatswerte selbst ändern sich nicht — nur, was aus ihnen gelernt wird.
     */
    private static function markMeasured(array $monthly): array
    {
        foreach ($monthly as &$m) {
            $kwh = (float)($m['kwh'] ?? 0.0);
            $days = (int)($m['days'] ?? 0);
            $share = abs($kwh) > 1e-9 ? (float)($m['_measured_kwh'] ?? 0.0) / $kwh
                : ($days > 0 ? (int)($m['_measured_days'] ?? 0) / $days : 1.0);
            $share = max(0.0, min(1.0, $share));
            // v3.9.0 (L8) — zusätzlich nach Tagen: Stammen die meisten Tage aus einem langen
            // Abstand, ist der Monat verteilt, auch wenn die wenigen gemessenen Tage mehr
            // Verbrauch tragen. Im Testhaushalt kam der Dezember 2024 zu 18 von 31 Tagen aus
            // einem Abstand von 103 Tagen und galt nur deshalb als gemessen, weil der
            // verteilte Teil die milderen Herbstmonate mitnimmt (Tagesrate zu niedrig).
            $dayShare = $days > 0 ? max(0.0, min(1.0, (int)($m['_measured_days'] ?? 0) / $days)) : 1.0;
            $gap = (int)($m['_gap_days'] ?? 0);
            $m['measured_share'] = round($share, 3);
            $m['measured_day_share'] = round($dayShare, 3);   // v3.9.0 (L8, additiv)
            $m['interpolated'] = $share < self::MEASURED_SHARE_MIN || $dayShare < self::MEASURED_SHARE_MIN;
            $m['reading_gap_days'] = $gap === PHP_INT_MAX ? null : $gap;
            // v3.7.0 (Nachlauf) — eine geschätzte Ablesung im Abstand: nicht „gleichmäßig verteilt“
            $m['reading_gap_estimated'] = !empty($m['_gap_est']);
            unset($m['_measured_kwh'], $m['_measured_days'], $m['_gap_days'], $m['_gap_est']);
        }
        unset($m);
        return $monthly;
    }

    /**
     * v3.7.0 (#39) — Kennung einer Summe mehrerer Zähler (Gruppe, Summe der
     * Art): Anteil gewichtet nach Verbrauch, ohne Verbrauch nach Tagen.
     * `$acc`: [Σ Anteil × Wert, Σ Wert, Σ Anteil × Tage, Σ Tage, längster Abstand, ohne Kennung?]
     *
     * @return array{measured_share:?float,interpolated:?bool,reading_gap_days:?int}
     */
    private static function sharedMarker(array $acc): array
    {
        [$wv, $v, $wd, $d, $gap, $none] = $acc;
        if ($none) return ['measured_share' => null, 'interpolated' => null, 'reading_gap_days' => null];
        $share = abs($v) > 1e-9 ? $wv / $v : ($d > 0 ? $wd / $d : 1.0);
        $share = max(0.0, min(1.0, $share));
        return ['measured_share' => round($share, 3), 'interpolated' => $share < self::MEASURED_SHARE_MIN, 'reading_gap_days' => $gap];
    }

    /** Eine Monatszeile in die Kennung einer Summe aufnehmen (s. sharedMarker). */
    private static function accumulateMarker(?array $acc, array $m, string $vf): array
    {
        $acc ??= [0.0, 0.0, 0.0, 0, null, false];
        if (!array_key_exists('measured_share', $m) || $m['measured_share'] === null) { $acc[5] = true; return $acc; }
        $share = (float)$m['measured_share'];
        $v = abs((float)($m[$vf] ?? 0.0));
        $d = (int)($m['days'] ?? 0);
        $acc[0] += $share * $v; $acc[1] += $v; $acc[2] += $share * $d; $acc[3] += $d;
        if (($m['reading_gap_days'] ?? null) !== null) $acc[4] = max((int)($acc[4] ?? 0), (int)$m['reading_gap_days']);
        return $acc;
    }

    /**
     * v3.1.0 (H6, #17) — Monatsreihe einer Zählergruppe: die Summe der
     * Mitglieder, jedes nach seinem Anteil am Gruppenvertrag gerechnet
     * ({@see contractScope()}). Wetterfelder vom ersten Mitglied mit dem Monat;
     * Vertragsteile und Saldo je Vertrag neu zusammengezählt.
     */
    private function computeForGroup(string $utility, array $group, ?float $hddBaseOverride = null): array
    {
        $sumKeys = ['kwh', 'm3', 'raw', 'cost', 'kwh_cost', 'co2_kg', 'heat_adjusted', 'grid_reduction_eur'];
        $nullable = ['base_price_eur', 'advance_eur', 'bonus_eur'];
        $rows = [];
        $parts = [];   // ym → Vertrag → Teil
        $markers = []; // v3.7.0 (#37) — ym → Kennung „gemessen“ der Summe
        $vfG = (Utilities::get($utility)['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';
        // v3.9.0 (#38) — hat die Gruppe eine eigene Heizgrenze, rechnen alle Mitglieder für die
        // Summe mit ihr (dieselben Gradtage in jeder Zeile); sonst jedes mit seiner wie bisher
        $memberBase = $hddBaseOverride ?? (is_numeric($group['hdd_base_temp'] ?? null) ? (float)$group['hdd_base_temp'] : null);
        // v3.6.0 — ohne Subzähler, deren Elternzähler Mitglied ist (sonst doppelt)
        $sumMembers = $this->meters->groupSumMembers($utility, (string)$group['id']);
        $sumIds = array_map(fn($m) => (string)($m['id'] ?? ''), $sumMembers);
        // v3.9.0 (F1032) — … außer ihrem Geld, wenn sie nach dem Gruppenvertrag rechnen: Dann
        // rechnet der Elternzähler nur den Rest, und der Subzähler trägt seinen Teil selbst
        $moneyOnly = array_values(array_filter($this->meters->groupMembers($utility, (string)$group['id']),
            fn($m) => !in_array((string)($m['id'] ?? ''), $sumIds, true) && $this->isDeductingSubmeter($utility, $m)));
        $billed = [];   // v3.9.0 (F1032) — ym → Menge der Verträge (nur mit Rest-Abrechnung)
        $restOf = [];   // ym → zusammengezählter Hinweis
        foreach ($sumMembers as $member) {
            foreach ($this->forMeter($utility, $member, $memberBase) as $r) {
                $ym = (string)$r['ym'];
                if (array_key_exists('billed_kwh', $r)) $billed[$ym] = true;
                if (isset($r['rest_of'])) $restOf[$ym] = SubmeterRest::mergeRestOf($restOf[$ym] ?? null, $r['rest_of']);
                $markers[$ym] = self::accumulateMarker($markers[$ym] ?? null, $r, $vfG);
                if (!isset($rows[$ym])) {
                    $rows[$ym] = $r;
                    foreach ($sumKeys as $k) if (isset($r[$k])) $rows[$ym][$k] = 0.0;
                    foreach ($nullable as $k) $rows[$ym][$k] = null;
                    unset($rows[$ym]['contract_parts']);
                    $rows[$ym]['days'] = 0;
                    $rows[$ym]['estimated_days'] = 0;
                }
                foreach ($sumKeys as $k) if (isset($r[$k]) && is_numeric($r[$k])) $rows[$ym][$k] = (float)($rows[$ym][$k] ?? 0.0) + (float)$r[$k];
                foreach ($nullable as $k) if (isset($r[$k]) && $r[$k] !== null) $rows[$ym][$k] = (float)($rows[$ym][$k] ?? 0.0) + (float)$r[$k];
                $rows[$ym]['days'] = max((int)$rows[$ym]['days'], (int)($r['days'] ?? 0));
                $rows[$ym]['estimated_days'] = max((int)$rows[$ym]['estimated_days'], (int)($r['estimated_days'] ?? 0));
                $rows[$ym]['pre_baseline'] = !empty($rows[$ym]['pre_baseline']) || !empty($r['pre_baseline']);
                $list = !empty($r['contract_parts']) ? $r['contract_parts']
                    : (!empty($r['contract_id']) ? [[
                        'contract_id' => $r['contract_id'], 'days' => (int)($r['days'] ?? 0), 'kwh' => self::billedQuantity($r),
                        'kwh_cost' => (float)($r['kwh_cost'] ?? 0), 'base_price_eur' => $r['base_price_eur'] ?? null,
                        'advance_eur' => $r['advance_eur'] ?? null, 'bonus_eur' => (float)($r['bonus_eur'] ?? 0),
                        'cost' => (float)($r['cost'] ?? 0), 'assumed' => !empty($r['contract_assumed']),
                    ]] : []);
                self::addGroupParts($parts, $ym, $list);
            }
        }
        // v3.9.0 (F1032) — Subzähler eines Mitglieds mit eigenem Vertrag: Geld und Vertragsteile, keine Menge
        foreach ($moneyOnly as $member) {
            foreach ($this->forMeter($utility, $member, $memberBase) as $r) {
                $ym = (string)$r['ym'];
                if (!isset($rows[$ym]) || empty($r['contract_id'])) continue;
                foreach (['cost', 'kwh_cost', 'grid_reduction_eur'] as $k) if (isset($r[$k]) && is_numeric($r[$k])) $rows[$ym][$k] = (float)($rows[$ym][$k] ?? 0.0) + (float)$r[$k];
                foreach (['cost', 'kwh_cost'] as $k) $rows[$ym][$k] = (float)($rows[$ym][$k] ?? 0.0) - (float)($r['uncontracted_cost'] ?? 0.0);
                foreach ($nullable as $k) if (isset($r[$k]) && $r[$k] !== null) $rows[$ym][$k] = (float)($rows[$ym][$k] ?? 0.0) + (float)$r[$k];
                $billed[$ym] = true;
                self::addGroupParts($parts, $ym, !empty($r['contract_parts']) ? $r['contract_parts'] : [[
                    'contract_id' => $r['contract_id'], 'days' => (int)($r['days'] ?? 0), 'kwh' => (float)($r['kwh'] ?? 0),
                    'kwh_cost' => (float)($r['kwh_cost'] ?? 0), 'base_price_eur' => $r['base_price_eur'] ?? null,
                    'advance_eur' => $r['advance_eur'] ?? null, 'bonus_eur' => (float)($r['bonus_eur'] ?? 0),
                    'cost' => (float)($r['cost'] ?? 0), 'assumed' => !empty($r['contract_assumed']),
                ]]);
            }
        }
        ksort($rows);
        $running = [];
        foreach ($rows as $ym => &$row) {
            $ps = $parts[$ym] ?? [];
            uasort($ps, fn($a, $b) => $b['days'] <=> $a['days']);
            $main = $ps !== [] ? (string)array_key_first($ps) : null;
            $row['contract_id'] = $main;
            $row['contract_assumed'] = $main !== null && $ps[$main]['assumed'];
            if (count($ps) > 1) {
                $row['contract_parts'] = array_values(array_map(fn($p) => array_merge($p, [
                    'kwh' => round($p['kwh'], 1), 'kwh_cost' => round($p['kwh_cost'], 2), 'cost' => round($p['cost'], 2),
                    'base_price_eur' => $p['base_price_eur'] !== null ? round($p['base_price_eur'], 2) : null,
                    'advance_eur' => $p['advance_eur'] !== null ? round($p['advance_eur'], 2) : null,
                    'bonus_eur' => round($p['bonus_eur'], 2)]), $ps));
            }
            foreach ($ps as $cid => $p) {
                if ($p['advance_eur'] !== null) $running[$cid] = ($running[$cid] ?? 0.0) + $p['cost'] - $p['advance_eur'];
            }
            foreach (['kwh', 'm3', 'raw'] as $k) if (isset($row[$k])) $row[$k] = round((float)$row[$k], 3);
            foreach (['cost', 'kwh_cost', 'base_price_eur', 'advance_eur', 'bonus_eur', 'grid_reduction_eur'] as $k) {
                if (isset($row[$k])) $row[$k] = round((float)$row[$k], 2);
            }
            $row = array_merge($row, self::sharedMarker($markers[$ym]));
            // v3.9.0 (F1032) — Rest-Abrechnung: Menge der Verträge und Hinweis der Mitglieder
            unset($row['billed_kwh'], $row['rest_of']);
            if (isset($billed[$ym])) {
                $row['billed_kwh'] = round(array_sum(array_map(fn($p) => (float)$p['kwh'], $ps)), 1);
                if (isset($restOf[$ym])) $row['rest_of'] = $restOf[$ym];
            }
            $qty = isset($row['billed_kwh']) ? (float)$row['billed_kwh'] : (float)($row['kwh'] ?? 0);
            $row['working_price_ct'] = $qty > 0 && isset($row['kwh_cost']) ? round((float)$row['kwh_cost'] / $qty * 100, 4) : null;
            $row['monthly_balance'] = isset($row['advance_eur']) ? round((float)$row['cost'] - (float)$row['advance_eur'], 2) : null;
            $row['cumulative_balance'] = $main !== null && isset($running[$main]) && isset($row['advance_eur']) ? round($running[$main], 2) : null;
        }
        unset($row);
        $vf = (Utilities::get($utility)['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';
        $rows = array_values($rows);
        // v3.9.0 (#38) — die Erwartung der Summe aus dem Heizmodell der Summe. Bis v3.8 stand in
        // jeder Zeile die Erwartung des ersten Mitglieds; Gütesperre, Abweichungen und der
        // Heizgrenze-Vorschlag einer Gruppe verglichen so die Summe mit einem Teil
        if (($model = $this->heatModel($utility, $rows)) !== null) {
            $minDays = (int)$this->settings->get('min_days_period', 20);
            foreach ($rows as &$row) {
                if (!array_key_exists('expected_heat', $row) || !empty($row['pre_baseline']) || !self::hasTemperatureCoverage($row)) continue;
                $days = (int)($row['days'] ?? 0);
                $exp = $model['a'] * (float)($row['hdd'] ?? 0) + $model['c'] * $days;
                $row['expected_heat'] = round($exp, 1);
                $row['expected_base'] = round($model['c'] * $days, 1);
                $row['weather_delta_pct'] = $days >= $minDays && $exp > 0 ? round(((float)($row[$vf] ?? 0) - $exp) / $exp * 100, 1) : null;
            }
            unset($row);
        }
        return $this->addMovingAverages($rows, $vf);
    }

    /**
     * Vertragsteile eines Mitglieds in die Teile der Gruppe aufnehmen
     * (computeForGroup; v3.9.0 herausgelöst, damit auch Subzähler mit
     * eigenem Vertrag ihren Teil beitragen).
     *
     * @param array<string,array<string,array<string,mixed>>> $parts ym → Vertrag → Teil
     * @param list<array<string,mixed>> $list
     */
    private static function addGroupParts(array &$parts, string $ym, array $list): void
    {
        foreach ($list as $p) {
            $cid = (string)$p['contract_id'];
            $q = $parts[$ym][$cid] ?? ['contract_id' => $cid, 'days' => 0, 'kwh' => 0.0, 'kwh_cost' => 0.0,
                'base_price_eur' => null, 'advance_eur' => null, 'bonus_eur' => 0.0, 'cost' => 0.0, 'assumed' => false];
            $q['days'] = max($q['days'], (int)($p['days'] ?? 0));
            foreach (['kwh', 'kwh_cost', 'bonus_eur', 'cost'] as $k) $q[$k] += (float)($p[$k] ?? 0);
            foreach (['base_price_eur', 'advance_eur'] as $k) if (($p[$k] ?? null) !== null) $q[$k] = (float)($q[$k] ?? 0.0) + (float)$p[$k];
            $q['assumed'] = $q['assumed'] || !empty($p['assumed']);
            $parts[$ym][$cid] = $q;
        }
    }

    /**
     * Gemeinsamer Rest der Rechnung für Stände und Zeiträume (v3.1.0
     * herausgelöst): Wetter, Felder der Art, Verträge, Markierungen,
     * Wetterbereinigung, gleitende Mittel.
     */
    private function finishMonthly(array $monthly, array $coverage, string $utility, array $meter, array $temps, float $hddBase): array
    {
        $u = Utilities::get($utility);
        // v3.9.0 (F1032) — die Tagesabschnitte merken: Ein Elternzähler zieht damit die Menge
        // eines Subzählers mit eigenem Vertrag tagesgenau ab (deductingSubmeters)
        $this->coverageMemo[$this->coverageKey($utility, $meter)] = $coverage;
        // v3.7.0 (#37) — vor dem Runden: gemessen oder verteilt
        $monthly = self::markMeasured($monthly);
        $monthly = $this->enrichWithWeather($monthly, $temps, $hddBase, $coverage);
        $monthly = $this->applyUtilityFields($monthly, $utility, $meter);
        $monthly = $this->applyContracts($monthly, $utility, $meter, $coverage);
        // v3.9.0 (ablesung-pruefen) — überbrückter Monat: Die Verträge haben mit der Menge
        // nach dem Stand gerechnet (`_bill_kwh`, Abschnitte aus `$coverage`); sie steht als
        // `billed_kwh` daneben, wie bei der Rest-Abrechnung. `kwh` ist der Monatswert.
        foreach ($monthly as &$m) {
            if (!array_key_exists('_bill_kwh', $m)) continue;
            if ($utility !== 'wasser' && !array_key_exists('billed_kwh', $m)) $m['billed_kwh'] = round((float)$m['_bill_kwh'], 1);
            // Skeptiker M9: bei Wasser die abgerechnete Menge in m³ (für die Monatszeile)
            if ($utility === 'wasser') $m['billed_m3'] = round((float)$m['_bill_kwh'], 2);
            unset($m['_bill_kwh']);
        }
        unset($m);
        ksort($monthly);
        $monthly = array_values($monthly);
        // v3.9.0 (L7) — Abschlag der gemessenen Tage am Rand der Ablesungen
        $monthly = self::addMeasuredAdvance($monthly, ConsumptionService::contractStarts($this->contractScope($utility, $meter)[0]));
        // v1.6.1 — Issue #13: Wechsel-Monate flaggen
        $monthly = $this->markSwapMonths($monthly, $meter);
        // v1.4.0 — F1011: Monate vor der Zäsur flaggen. Muss VOR der
        // Wetterbereinigung laufen — die liest die Markierung.
        $monthly = $this->markBaselineMonths($monthly, $meter);
        // v2.8.0 — welche Monate ins Heizkurven-Modell gehen, steht einmal
        // am Monat (`regression_point`), statt in jeder Ansicht neu gefiltert.
        $monthly = $this->markRegressionPoints($monthly, $utility);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $monthly = $this->applyWeatherAdjustment($monthly, $utility, $valueField, $meter, $hddBase);
        return $this->addMovingAverages($monthly, $valueField);
    }

    /**
     * v3.1.0 (H3, B2) — Monatswerte aus den Zeiträumen eines Zählers.
     * `value_unit: 'meter'` rechnet bei Gas mit den datierten Faktoren (m³ →
     * kWh), `'consumption'` mit 1 (die kWh stehen schon da); die m³ werden dann
     * zurückgerechnet, damit Rechnungsprüfung und CSV sie zeigen können.
     *
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,list<array>>}
     */
    private function periodMonths(string $utility, array $meter, callable $factorOn, callable $factorBoundaries): array
    {
        $all = $this->store->read("$utility/periods.json", []);
        $monthly = []; $coverage = [];
        foreach (is_array($all) ? $all : [] as $p) {
            if (!is_array($p) || ($p['meter_id'] ?? null) !== ($meter['id'] ?? null)) continue;
            $from = (string)($p['from'] ?? '');
            $to = (string)($p['to'] ?? '');
            if (!Dates::isIsoDate($from) || !Dates::isIsoDate($to) || $from > $to) continue;
            $end = date('Y-m-d', (int)strtotime($to . ' +1 day'));
            $days = (int)(new \DateTime($from))->diff(new \DateTime($end))->days;
            if ($days <= 0) continue;
            $meterUnit = ($p['value_unit'] ?? 'consumption') === 'meter';
            $fOn = $meterUnit ? $factorOn : static fn(string $d): float => 1.0;
            $bounds = $meterUnit ? $factorBoundaries($from, $end) : [];
            // v3.7.0 (#37) — der Zeitraum ist der Ablesungsabstand; ein geschätzter misst nichts
            $gap = empty($p['is_estimated']) ? $days : PHP_INT_MAX;
            foreach ($this->distributeToMonths($from, $end, max(0.0, (float)($p['value'] ?? 0)) / $days, 0.0, $fOn, $bounds) as $ym => $v) {
                if (!$meterUnit && $utility === 'gas') {
                    $f = $factorOn($ym . '-15');
                    $v['raw'] = $f > 0 ? $v['kwh'] / $f : 0.0;
                }
                if (!isset($monthly[$ym])) $monthly[$ym] = ['kwh' => 0.0, 'raw' => 0.0, 'days' => 0, 'cost' => 0.0];
                $monthly[$ym]['kwh']  += $v['kwh'];
                $monthly[$ym]['raw']  += $v['raw'];
                $monthly[$ym]['days'] += $v['days'];
                self::addMeasured($monthly[$ym], $v, $gap);
                foreach ($v['spans'] as $span) $coverage[$ym][] = $span;
            }
        }
        return [$monthly, $coverage];
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
        // v2.6.0 — dieselbe Plausibilitätsprüfung wie die Verbrauchsrechnung;
        // Intervalle mit Tagesrate (roh). v3.7.0 — einmal je Zähler und Anfrage.
        [$actual, $warnings, $intervals] = $this->billBasis($utility, $meter);

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
        $k = 0;
        for ($i = 0; $i < count($dates) - 1; $i++) {
            $a = $dates[$i];
            $b = $dates[$i + 1];
            $days = (int)(new \DateTime($a))->diff(new \DateTime($b))->days;
            if ($days <= 0) continue;

            // Ableseintervall, das $a umschließt (halboffen). v3.7.0 — Zeilen
            // und Intervalle sind aufsteigend: weiterzählen statt jedes Mal von
            // vorn suchen (bei täglichen Ständen Zeilen × Intervalle Vergleiche)
            [$rate, $k] = self::rateAt($intervals, $a, $k);
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
     * v3.1.0 (Paket H5, MKT-17) — Rechnungsprüfung für alle Arten mit Verträgen.
     *
     * Gas: die Zeilen aus gasBillBreakdown() unverändert (Ablesungen,
     * Faktorwechsel), dazu je Zeile und in den Summen die Kosten. Strom und
     * Fernwärme: Zeilen an Ablesungen und an Vertrags- und Preisstichtagen.
     * Wasser: dieselben Grenzen, Kosten je Komponente.
     *
     * Die Kosten rechnen mit denselben Bausteinen wie die Monatssicht und der
     * Saldo (Vertragsabschnitte, priceDate, fixedPerMonthOn, Grundpreis nach
     * Kalendertagen des Monats) — keine zweite Formel. Zeilenfelder additiv:
     * `ct_per_kwh`, `energy_cost`, `fixed_days`, `fixed_cost`, `contract_id`
     * (Wasser: `tw_m3`, `tw_cost`, `sw_m3`, `sw_cost`, `nw_cost`); Summen
     * `energy_cost`, `fixed_cost`, `bonus`, `total`.
     *
     * @return array<string,mixed>
     */
    public function billBreakdown(string $utility, array $meter, string $from, string $to): array
    {
        if (!Utilities::supportsBillCheck($utility)) {
            throw new \Energietracker\Support\LocalizedException('errors.billCheck.unsupportedUtility', ['utility' => $utility], "bill check $utility");
        }
        // v3.1.0 (H6) — Gruppe: die Abschnitte jedes Mitglieds nacheinander
        if (!empty($meter['is_group'])) return $this->groupBillBreakdown($utility, $meter, $from, $to);
        [$contracts, $ctx] = $this->contractScope($utility, $meter);
        $out = $utility === 'gas'
            ? $this->gasBillBreakdown($meter, $from, $to)
            : $this->genericBillRows($utility, $meter, $from, $to, $contracts);
        // v3.9.0 (F1032, #40) — Subzähler mit eigenem Vertrag: Je Abschnitt rechnet der Vertrag nur
        // den Rest (`kwh`); daneben die Messung (`measured_kwh`) und der Abzug (`submeter_kwh`)
        $out = $this->restBillRows($utility, $meter, $out);
        $energy = 0.0; $fixed = 0.0; $missingPrice = false;
        $cuts = $this->contracts->cutDates($contracts);
        foreach ($out['rows'] as &$row) {
            $qty = $utility === 'wasser' ? ($row['m3'] ?? null) : ($row['kwh'] ?? null);
            $c = $utility === 'wasser'
                ? $this->waterRowCosts($row['from'], $row['to'], $qty, $contracts, $meter)
                : $this->rowCosts($row['from'], $row['to'], $qty, $contracts, $ctx, $cuts);
            $row += $c;
            $energy += (float)($c['energy_cost'] ?? 0);
            $fixed += (float)($c['fixed_cost'] ?? 0);
            if ($qty !== null && $c['energy_cost'] === null) $missingPrice = true;
        }
        unset($row);
        $bonus = 0.0; $bonusItems = [];
        foreach ($contracts as $c) {
            if (!self::carriesFixed($c, $ctx)) continue;
            foreach ($c['bonuses'] ?? [] as $b) {
                $d = (string)($b['credit_date'] ?? '');
                if ($d >= $from && $d < $to && is_numeric($b['amount_eur'] ?? null)) {
                    $bonus += (float)$b['amount_eur'];
                    $bonusItems[] = ContractService::bonusItem($b);   // v3.7.0 (#33)
                }
            }
        }
        usort($bonusItems, fn($a, $b) => $a['credit_date'] <=> $b['credit_date']);
        $out['totals'] += [
            'energy_cost' => round($energy, 2),
            'fixed_cost'  => round($fixed, 2),
            'bonus'       => round($bonus, 2),
            'total'       => round($energy + $fixed - $bonus, 2),
            'price_missing' => $missingPrice,
            // v3.7.0 (#33) — die Boni einzeln (Datum, Betrag, Art, Notiz), additiv
            'bonus_items' => $bonusItems,
        ];
        $out['utility'] = $utility;
        return $out;
    }

    /**
     * v3.9.0 (F1032, #40) — Abschnitte der Rechnungsprüfung mit Rest-Abrechnung: Je
     * Abschnitt zieht die Menge der Subzähler mit eigenem Vertrag ab (tagesgenau wie
     * die Monatsrechnung). `kwh` ist danach die Menge des Vertrags (nie unter null),
     * `measured_kwh` die Messung, `submeter_kwh` der Abzug; die Summen ebenso, dazu
     * der Hinweis `rest_of`. Ohne solche Subzähler unverändert.
     *
     * @param array<string,mixed> $out Ergebnis von gasBillBreakdown()/genericBillRows()
     * @return array<string,mixed>
     */
    private function restBillRows(string $utility, array $meter, array $out): array
    {
        $subs = $this->deductingSubmeters($utility, $meter);
        if ($subs === []) return $out;
        $names = [];
        foreach ($subs as $s) $names[(string)$s['meter']['id']] = (string)($s['meter']['name'] ?? $s['meter']['id']);
        $acc = null; $billed = 0.0; $measured = 0.0;
        foreach ($out['rows'] as &$row) {
            $q = $row['kwh'] ?? null;
            if ($q === null) continue;
            $measured += (float)$q;
            $d = $this->submeterDeduction($subs, (string)$row['from'], (string)$row['to']);
            if ($d['by'] === []) { $billed += (float)$q; continue; }
            $rest = (float)$q - $d['total'];
            $row['measured_kwh'] = $q;
            $row['submeter_kwh'] = round($d['total'], 1);
            $row['kwh'] = round(max(0.0, $rest), 1);
            $billed += max(0.0, $rest);
            $acc = SubmeterRest::mergeRestOf($acc, SubmeterRest::restOf((float)$q, $d['by'], $names, $d['estimated'],
                $d['open'] === [] ? 0 : max($d['open']), $rest < 0 ? -$rest : 0.0));
        }
        unset($row);
        if ($acc === null) return $out;
        $out['totals']['measured_kwh'] = round($measured, 1);
        $out['totals']['kwh'] = round($billed, 1);
        $out['totals']['rest_of'] = $acc;
        return $out;
    }

    /** v3.1.0 (H6) — Rechnungsprüfung einer Gruppe: die Mitglieder nacheinander, Summen zusammen. */
    private function groupBillBreakdown(string $utility, array $group, string $from, string $to): array
    {
        $rows = []; $warnings = [];
        $tot = ['days' => 0, 'kwh' => 0.0, 'm3' => 0.0, 'gaps' => 0, 'energy_cost' => 0.0, 'fixed_cost' => 0.0,
                'bonus' => 0.0, 'total' => 0.0, 'price_missing' => false, 'bonus_items' => []];
        $restOf = null;
        // v3.6.0 — ohne Subzähler, deren Elternzähler Mitglied ist (wie computeForGroup)
        // v3.9.0 (F1032) — außer sie rechnen nach dem Gruppenvertrag: Dann rechnet der Elternzähler
        // nur den Rest, und ihr Teil gehört dazu
        $sumMembers = $this->meters->groupSumMembers($utility, (string)$group['id']);
        $sumIds = array_map(fn($m) => (string)($m['id'] ?? ''), $sumMembers);
        $members = array_merge($sumMembers, array_values(array_filter($this->meters->groupMembers($utility, (string)$group['id']),
            fn($m) => !in_array((string)($m['id'] ?? ''), $sumIds, true) && $this->isDeductingSubmeter($utility, $m))));
        foreach ($members as $m) {
            $b = $this->billBreakdown($utility, $m, $from, $to);
            if (isset($b['totals']['rest_of'])) $restOf = SubmeterRest::mergeRestOf($restOf, $b['totals']['rest_of']);
            foreach ($b['rows'] as $r) $rows[] = $r + ['meter_id' => (string)$m['id'], 'meter_name' => (string)($m['name'] ?? $m['id'])];
            $t = $b['totals'];
            $tot['days'] = max($tot['days'], (int)($t['days'] ?? 0));
            $tot['bonus_items'] = array_merge($tot['bonus_items'], (array)($t['bonus_items'] ?? []));
            foreach (['kwh', 'm3', 'energy_cost', 'fixed_cost', 'bonus', 'total'] as $k) $tot[$k] += (float)($t[$k] ?? 0);
            $tot['gaps'] += (int)($t['gaps'] ?? 0);
            $tot['price_missing'] = $tot['price_missing'] || !empty($t['price_missing']);
            foreach ($b['warnings'] ?? [] as $w) $warnings[] = $w;
        }
        $tot['kwh'] = round($tot['kwh'], 1);
        $tot['m3'] = round($tot['m3'], 2);
        foreach (['energy_cost', 'fixed_cost', 'bonus', 'total'] as $k) $tot[$k] = round($tot[$k], 2);
        if ($utility !== 'gas') unset($tot['m3']);
        if ($restOf !== null) $tot['rest_of'] = $restOf;   // v3.9.0 (F1032) — additiv
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $tot, 'warnings' => $warnings,
                'utility' => $utility, 'group_id' => (string)$group['id']];
    }

    /**
     * v3.7.0 (Qualität) — Tagesrate des Ableseintervalls, das `$date`
     * umschließt (halboffen [start, end)), ab Position `$from` gesucht.
     * Intervalle aufsteigend und überschneidungsfrei (aufeinanderfolgende
     * Ablesungen); wer mit aufsteigenden Tagen fragt, gibt die gelieferte
     * Position zurück und sucht nie zweimal von vorn.
     *
     * @param list<array{start:string,end:string,rate:float}> $intervals
     * @return array{0:?float,1:int} Rate (null = Lücke) und Position für die nächste Frage
     */
    private static function rateAt(array $intervals, string $date, int $from = 0): array
    {
        $n = count($intervals);
        $k = $from;
        while ($k < $n && $intervals[$k]['end'] <= $date) $k++;
        return [$k < $n && $intervals[$k]['start'] <= $date ? (float)$intervals[$k]['rate'] : null, $k];
    }

    /** @var array<string,array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}> */
    private array $billBasisMemo = [];

    /**
     * v3.7.0 (Qualität) — Grundlage der Rechnungsprüfung eines Zählers:
     * plausible Ablesungen, übergangene (Warnungen) und die Intervalle mit
     * Tagesrate (roh). Je Zähler und Anfrage einmal (bis zum nächsten
     * Schreiben): Die Abrechnungsperioden prüfen jede Periode und — mit Anhang
     * im Jahresbericht — jeden Monat; jede Prüfung las bisher alle Ablesungen
     * und baute alle Intervalle neu.
     *
     * Intervall: `start`, `end`, `rate`, `estimated_end`, `start_counter`,
     * `same_device` (v2.5.2 — nur auf demselben Gerät ist ein Stand
     * interpolierbar; über einen Zählertausch hinweg gibt es keinen
     * fortlaufenden Zählerstand).
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    private function billBasis(string $utility, array $meter): array
    {
        $key = $utility . '|' . md5(serialize($meter)) . '|' . $this->store->generation();
        if (isset($this->billBasisMemo[$key])) return $this->billBasisMemo[$key];
        [$actual, $warnings] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) $devicesById[$d['id']] = $d;
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
                'start_counter' => (float)($prev['counter'] ?? 0),
                'same_device'   => ($prev['device_id'] ?? null) === ($curr['device_id'] ?? null),
            ];
        }
        return $this->billBasisMemo[$key] = [$actual, $warnings, $intervals];
    }

    /**
     * v3.7.0 (treffsicher) — Ableseart echter Ablesungen an einzelnen Tagen
     * (`reading` | `reading_estimated`), aus denselben plausiblen Ablesungen
     * wie die Rechnungsprüfung (billBasis). Nur lesend — für die Einstufung der
     * Grenzstände in BillService::compare(); an der Rechnung ändert es nichts.
     *
     * @param list<string> $dates
     * @return array<string,string> Tag → Ableseart; Tage ohne Ablesung fehlen
     */
    public function readingKindsOn(string $utility, array $meter, array $dates): array
    {
        if (!Utilities::isCumulative($utility) || !empty($meter['is_group'])) return [];
        [$actual] = $this->billBasis($utility, $meter);
        $want = array_flip($dates);
        $out = [];
        foreach ($actual as $r) {
            $d = (string)$r['date'];
            if (isset($want[$d])) $out[$d] = !empty($r['is_estimated']) ? 'reading_estimated' : 'reading';
        }
        return $out;
    }

    /** Zeilen für Strom, Fernwärme, Wasser: Grenzen an Ablesungen und Vertrags-/Preisstichtagen. */
    private function genericBillRows(string $utility, array $meter, string $from, string $to, array $contracts): array
    {
        [$actual, $warnings, $intervals] = $this->billBasis($utility, $meter);   // v3.7.0 — wie gasBillBreakdown
        $byDate = [];
        foreach ($actual as $r) $byDate[(string)$r['date']] = $r;
        $counterAt = function (string $date) use ($byDate, $intervals): array {
            if (isset($byDate[$date])) {
                return ['value' => round((float)($byDate[$date]['counter'] ?? 0), 1),
                        'kind' => !empty($byDate[$date]['is_estimated']) ? 'reading_estimated' : 'reading'];
            }
            foreach ($intervals as $iv) {
                if ($iv['start'] < $date && $date < $iv['end']) {
                    if (!$iv['same_device']) return ['value' => null, 'kind' => 'interpolated'];
                    $d = (int)(new \DateTime($iv['start']))->diff(new \DateTime($date))->days;
                    return ['value' => round($iv['start_counter'] + $iv['rate'] * $d, 1), 'kind' => 'interpolated'];
                }
            }
            return ['value' => null, 'kind' => null];
        };
        $bounds = [$from => 'start', $to => 'end'];
        foreach ($actual as $r) {
            $d = (string)$r['date'];
            if ($d > $from && $d < $to) $bounds[$d] = !empty($r['is_estimated']) ? 'reading_estimated' : 'reading';
        }
        foreach ($this->contracts->segmentsBetween($contracts, $from, $to) as $seg) {
            if ($seg['from'] > $from) $bounds[$seg['from']] = isset($bounds[$seg['from']]) ? $bounds[$seg['from']] . '+price' : 'price';
        }
        ksort($bounds);
        $dates = array_keys($bounds);
        $unitKey = $utility === 'wasser' ? 'm3' : 'kwh';
        $rows = []; $tot = 0.0; $totDays = 0; $gaps = 0;
        $k = 0;
        for ($i = 0; $i < count($dates) - 1; $i++) {
            [$a, $b] = [$dates[$i], $dates[$i + 1]];
            $days = (int)(new \DateTime($a))->diff(new \DateTime($b))->days;
            if ($days <= 0) continue;
            [$rate, $k] = self::rateAt($intervals, $a, $k);   // v3.7.0 — wie gasBillBreakdown
            $q = $rate !== null ? $rate * $days : null;
            if ($q === null) $gaps++;
            $cFrom = $counterAt($a); $cTo = $counterAt($b);
            $rows[] = [
                'from' => $a, 'to' => $b, 'to_inclusive' => (new \DateTime($b))->modify('-1 day')->format('Y-m-d'),
                'days' => $days, 'reason' => $bounds[$a],
                $unitKey => $q !== null ? round($q, $unitKey === 'm3' ? 2 : 1) : null,
                'counter_from' => $cFrom['value'], 'counter_from_kind' => $cFrom['kind'],
                'counter_to' => $cTo['value'], 'counter_to_kind' => $cTo['kind'],
            ];
            $tot += $q ?? 0.0;
            $totDays += $days;
        }
        return ['from' => $from, 'to' => $to, 'rows' => $rows,
                'totals' => ['days' => $totDays, $unitKey => round($tot, $unitKey === 'm3' ? 2 : 1), 'gaps' => $gaps],
                'warnings' => $warnings];
    }

    /**
     * Kosten eines Abschnitts [a, b) mit den Vertragsabschnitten der Saldo-
     * Rechnung: Arbeitspreis am priceDate, feste Kosten je Monat nach Tagen.
     *
     * @return array<string,mixed>
     */
    private function rowCosts(string $a, string $b, ?float $kwh, array $contracts, ?array $ctx = null, ?array $cuts = null): array
    {
        $days = max(1, self::daysBetweenDates($a, $b));
        $energy = $kwh === null ? null : 0.0;
        $fixed = 0.0; $hasFixed = false; $cid = null; $ct = null;
        // v3.7.0 — Schnitttage vom Aufrufer (einmal je Rechnungsprüfung statt je Zeile)
        $segments = $cuts !== null ? $this->contracts->segmentsWithin($contracts, $cuts, $a, $b) : $this->contracts->segmentsBetween($contracts, $a, $b);
        foreach ($segments as $seg) {
            $c = $seg['contract'];
            if ($c === null) { if ($kwh !== null) $energy = null; continue; }
            $cid ??= (string)$c['id'];
            $pd = ContractService::priceDate($seg);
            $wp = $this->contracts->valueOnDate(ContractService::workingPricesFor($c, $ctx['member'] ?? null), 'ct_per_kwh', $pd);
            $ct ??= $wp;
            if ($kwh !== null && $energy !== null) {
                $energy = $wp === null ? null : $energy + $kwh * $seg['days'] / $days * $wp / 100;
            }
            // feste Kosten nach Kalendertagen je Monat, wie applyStandardContracts
            for ($p = $seg['from']; $p < $seg['to'];) {
                $next = min($seg['to'], date('Y-m-01', (int)strtotime(substr($p, 0, 7) . '-01 +1 month')));
                $f = self::carriesFixed($c, $ctx) ? $this->contracts->fixedPerMonthOn($c, $pd) : null;
                if ($f !== null) {
                    $dim = (int)date('t', (int)strtotime($p));
                    $fixed += $f * self::daysBetweenDates($p, $next) / $dim;
                    $hasFixed = true;
                }
                $p = $next;
            }
        }
        return [
            'ct_per_kwh'  => $ct !== null ? round($ct, 4) : null,
            'energy_cost' => $energy !== null ? round($energy, 2) : null,
            'fixed_days'  => $days,
            'fixed_cost'  => $hasFixed ? round($fixed, 2) : null,
            'contract_id' => $cid,
        ];
    }

    /**
     * Wasser: Trinkwasser (Arbeits- und Grundpreis), Schmutzwasser und
     * Niederschlagswasser je Monat nach Tagen — wie applyWaterContracts.
     *
     * v3.6.0 (F1030) — Schmutzwasser auch mit eigenem Zähler
     * (`separater_zaehler`) und mit Abzugszählern (`trinkwasser_minus_abzug`,
     * Garten): die Menge des anderen Zählers im selben Abschnitt, tagesgenau
     * wie die Rechnung. Bis v3.5 fehlte das Schmutzwasser dann ganz (`null`) —
     * und mit den Abrechnungsjahren (F1030) auch in deren Kosten, während das
     * Kalenderjahr es aus den Monatszeilen mitzählte.
     *
     * @return array<string,mixed>
     */
    private function waterRowCosts(string $a, string $b, ?float $m3, array $contracts, array $meter): array
    {
        $days = max(1, self::daysBetweenDates($a, $b));
        $tw = 0.0; $sw = 0.0; $nw = 0.0; $base = 0.0; $swKnown = true; $priced = $m3 !== null; $cid = null;
        $swM3 = 0.0;
        $swBase = 0.0;   // v3.9.0 (F1034) — Grundpreis Schmutzwasser
        $other = [];   // Menge anderer Zähler in [a, b), einmal je Zähler
        $otherM3 = function (string $id) use (&$other, $a, $b): float {
            return $other[$id] ??= $this->waterVolumeBetween($id, $a, $b);
        };
        for ($p = $a; $p < $b;) {
            $next = min($b, date('Y-m-01', (int)strtotime(substr($p, 0, 7) . '-01 +1 month')));
            $d = self::daysBetweenDates($p, $next);
            $dim = (int)date('t', (int)strtotime($p));
            $y = (int)substr($p, 0, 4); $mn = (int)substr($p, 5, 2);
            $c = $this->contracts->findActiveForDate($contracts, substr($p, 0, 7) . '-01');
            if ($c !== null) {
                $cid ??= (string)$c['id'];
                $part = $m3 !== null ? $m3 * $d / $days : 0.0;
                $twWp = $this->contracts->valueValidOn($c['trinkwasser']['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $twBp = $this->contracts->valueValidOn($c['trinkwasser']['base_prices'] ?? [], 'eur_per_month', $y, $mn);
                if ($twWp === null) $priced = false; else $tw += $part * $twWp / 100;
                if ($twBp !== null) $base += $twBp * $d / $dim;
                $sws = $c['schmutzwasser'] ?? [];
                $basis = (string)($sws['basis'] ?? 'trinkwasser');
                // Schmutzwassermenge dieses Monatsstücks — dieselben Regeln wie applyWaterContracts
                $swPart = match ($basis) {
                    'trinkwasser' => $part,
                    // ohne Verweis bewusst 0 (v2.1.4), nicht die Trinkwassermenge
                    'separater_zaehler' => !empty($sws['separater_zaehler_meter_id'])
                        ? $otherM3((string)$sws['separater_zaehler_meter_id']) * $d / $days : 0.0,
                    'trinkwasser_minus_abzug' => max(0.0, $part - array_sum(array_map(
                        fn($id) => (string)$id === '' ? 0.0 : $otherM3((string)$id) * $d / $days, (array)($sws['abzug_meter_ids'] ?? [])))),
                    default => null,
                };
                if ($swPart === null) {
                    $swKnown = false;
                } else {
                    $swM3 += $swPart;
                    $swWp = $this->contracts->valueValidOn($sws['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                    if ($swWp !== null) $sw += $swPart * $swWp / 100;
                }
                // v3.9.0 (F1034) — Grundpreis Schmutzwasser nach Tagen wie der des Trinkwassers
                $swBp = $this->contracts->valueValidOn($sws['base_prices'] ?? [], 'eur_per_month', $y, $mn);
                if ($swBp !== null) $swBase += $swBp * $d / $dim;
                $nws = $c['niederschlagswasser'] ?? [];
                $nwP = $this->valueValidOnGeneric($nws['rates'] ?? [], 'eur_per_m2_year', $y, $mn);
                $nwA = $this->valueValidOnGeneric($nws['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
                if ($nwP !== null && $nwA !== null) $nw += $nwP * $nwA / 12 * $d / $dim;
            } else {
                $priced = false;
            }
            $p = $next;
        }
        return [
            'tw_m3'       => $m3 !== null ? round($m3, 2) : null,
            'tw_cost'     => $priced ? round($tw + $base, 2) : null,
            'sw_m3'       => $swKnown && $m3 !== null ? round($swM3, 2) : null,
            'sw_cost'     => $swKnown && $priced ? round($sw + $swBase, 2) : null,
            'nw_cost'     => round($nw, 2),
            'energy_cost' => $priced ? round($tw + ($swKnown ? $sw : 0.0), 2) : null,
            'fixed_days'  => $days,
            'fixed_cost'  => round($base + $swBase + $nw, 2),
            'contract_id' => $cid,
        ];
    }

    /**
     * v3.6.0 (F1030) — Menge eines anderen Wasserzählers in [a, b): getrennter
     * Schmutzwasserzähler oder Abzugszähler, mit denselben Abschnitten wie die
     * Rechnung (Tagesrate zwischen zwei Ständen). Unbekannter Zähler oder
     * Lücke: 0 — wie die Monatszeilen.
     */
    private function waterVolumeBetween(string $meterId, string $a, string $b): float
    {
        $m = $this->meters->get('wasser', $meterId);
        if ($m === null || !empty($m['is_group'])) return 0.0;
        return (float)($this->genericBillRows('wasser', $m, $a, $b, [])['totals']['m3'] ?? 0.0);
    }

    private static function daysBetweenDates(string $a, string $b): int
    {
        return (int)(new \DateTime($a))->diff(new \DateTime($b))->days;
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
     *
     * v3.7.0 (#37) — und wenn er gemessen ist: Ein aus einem längeren
     * Ablesungsabstand verteilter Monat liegt nicht auf der Heizkurve, sondern
     * auf der Geraden „Tagesrate des Abstands × Tage“.
     */
    public static function isRegressionCandidate(array $m, string $valueField, float $minHdd, int $minDays): bool
    {
        return (int)($m['days'] ?? 0) >= $minDays
            && empty($m['interpolated'])
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
     * v3.7.0 (#37) — verteilte Monate (`interpolated`) gehen nicht ein: Ihr
     * Wert ist die gleichmäßige Verteilung eines längeren Ablesungsabstands,
     * kein Verbrauch bei den Heizgradtagen dieses Monats; als Punkte zogen sie
     * a und c und damit jede Erwartung. Bleiben weniger als
     * MIN_POINTS_REGRESSION gemessene Monate, rechnet das Modell — mit dem
     * Zähler als drittem Argument — über die Ablesungsabstände selbst
     * ({@see heatIntervalPoints()}): Das Modell ist eine Summe über Tage und
     * gilt für drei Monate genauso wie für einen. Kein stiller Rückfall auf
     * die verteilten Monate (Lektion 33). `basis` sagt, worüber gerechnet wurde.
     *
     * @param array<string,mixed>|null $meter  für den Rückfall über die Ablesungsabstände
     * @param float|null $hddBase              Heizgrenze der Monatszeilen (Analyse mit `hdd_base`)
     * @return array{a:float,c:float,n:int,resid_sd:float,basis:string}|null
     */
    public function heatModel(string $utility, array $monthly, ?array $meter = null, ?float $hddBase = null): ?array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return null;
        $u = Utilities::get($utility);
        $vf = ($u['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $pts = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline']) || !empty($m['device_swap']) || !empty($m['interpolated'])) continue;
            $days = (int)($m['days'] ?? 0);
            $y = (float)($m[$vf] ?? 0);
            if ($days < $minDays || $y <= 0 || !self::hasTemperatureCoverage($m)) continue;
            $pts[] = [(float)($m['hdd'] ?? 0), (float)$days, $y, 1.0];
        }
        if (count($pts) >= self::MIN_POINTS_REGRESSION) return self::fitHeatPoints($pts) + ['basis' => 'months'];
        if ($meter === null) return null;
        $spans = $this->heatIntervalPoints($utility, $meter, $monthly, $hddBase);
        return count($spans) >= self::MIN_POINTS_REGRESSION ? self::fitHeatPoints($spans) + ['basis' => 'intervals'] : null;
    }

    /**
     * Fit mit einem Robustheitsschritt: Ein einzelner Ausreißer (etwa ein
     * Sommer mit defekter Therme) zöge sonst die Grundlast c mit hoch — und
     * damit genau die Erwartung, an der er gemessen wird. Punkte jenseits von
     * 3,5 robusten Streuungen fallen einmal heraus, dann wird neu gefittet.
     * Residuen gewichtet (√w), `resid_sd` damit je mittleren Monat.
     *
     * v3.9.0 (#38) — `dropped`: die Punkte, die der Robustheitsschritt verworfen hat, je
     * [Erwartung des fertigen Modells, Verbrauch] (gewichtet wie die Residuen). Der
     * Heizgrenze-Vorschlag braucht sie: Bei echten Daten verwarf der Schritt bei einer
     * einzigen Grenze ein Viertel der Monate (die Übergangsmonate), das Modell passte auf
     * den Rest sehr gut, und die Grenze sah als schmales Tal im Raster am besten aus. Bei
     * fast rauschfreien Daten verwirft er dagegen Monate, die nur 2 % daneben liegen
     * (3,5 × eine winzige Streuung); das ist kein Fehler des Modells.
     *
     * v3.9.0 (ablesung-pruefen) — öffentlich: Die Prüfung der Zwischenablesungen fittet
     * dasselbe Modell über Ablesungsabstände ohne die geprüften (ReadingCheckService).
     *
     * @param list<array{0:float,1:float,2:float,3:float}> $pts  [HGT, Tage, Verbrauch, Gewicht]
     * @return array{a:float,c:float,n:int,resid_sd:float,dropped:list<array{0:float,1:float}>}
     */
    public static function fitHeatPoints(array $pts): array
    {
        [$a, $c] = self::fitHeatModel($pts);
        $res = [];
        foreach ($pts as $i => [$h, $d, $y, $w]) $res[$i] = ($y - ($a * $h + $c * $d)) * sqrt($w);
        [$center, $scale] = AnomalyService::robustScale(array_values($res));
        $out = [];
        if ($scale > 0) {
            $kept = array_values(array_filter($pts, fn($p, $i) => abs($res[$i] - $center) <= 3.5 * $scale, ARRAY_FILTER_USE_BOTH));
            if (count($kept) < count($pts) && count($kept) >= self::MIN_POINTS_REGRESSION) {
                $out = array_values(array_filter($pts, fn($p, $i) => abs($res[$i] - $center) > 3.5 * $scale, ARRAY_FILTER_USE_BOTH));
                $pts = $kept;
                [$a, $c] = self::fitHeatModel($pts);
            }
        }
        // bei Gewicht 1 dieselbe Rechnung wie bis v3.6 (Summe der Quadrate in derselben Reihenfolge)
        $sq = [];
        foreach ($pts as [$h, $d, $y, $w]) { $r = $y - ($a * $h + $c * $d); $sq[] = $w * $r * $r; }
        $sd = count($pts) > 2 ? sqrt(array_sum($sq) / (count($pts) - 2)) : 0.0;
        $dropped = array_map(fn($p) => [($a * $p[0] + $c * $p[1]) * sqrt($p[3]), $p[2] * sqrt($p[3])], $out);
        // v3.9.0 (#38, Rückblick) — Gradtage je Tag der Punkte, aufsteigend: Wo das Modell keine
        // so warmen Monate kennt, ist die Grundlast c geraten (ForecastService::HEAT_SUPPORT_MIN)
        $hddDay = [];
        foreach ($pts as [$h, $d]) if ($d > 0) $hddDay[] = round($h / $d, 3);
        sort($hddDay);
        return ['a' => $a, 'c' => $c, 'n' => count($pts), 'resid_sd' => $sd, 'dropped' => $dropped, 'hdd_per_day' => $hddDay];
    }

    /**
     * v3.7.0 (#37) — Punkte des Heizmodells aus den Ablesungsabständen statt aus
     * Monaten: je Abstand zwischen zwei echten Ablesungen (bzw. je Zeitraum)
     * Verbrauch, Heizgradtage und Tage. Gewicht MONTH_DAYS / Tage: Die Streuung
     * eines Abstands wächst mit seiner Länge, so zählt ein Vierteljahr wie drei
     * Monate, nicht wie neun. Ausgelassen werden Abstände mit Tagen vor der
     * Zäsur (anderes Gebäude), ohne Temperaturen (weniger als 90 % der Tage) und
     * unter `min_days_period` Tagen. Einen Abstand über einen Zählertausch gibt
     * es nicht (measuringSpans); ein Abstand auf einem Gerät ist auch im
     * Tausch-Monat eine saubere Messung — anders als der Monat, der die
     * Überbrückung enthält.
     *
     * @return list<array{0:float,1:float,2:float,3:float}>
     */
    private function heatIntervalPoints(string $utility, array $meter, array $monthly, ?float $hddBase = null): array
    {
        if (!empty($meter['is_group']) || !Utilities::isCumulative($utility)) return [];
        $hddBase ??= $this->hddBaseFor($meter);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $excluded = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) $excluded[(string)$m['ym']] = true;
        }
        $temps = $this->temperatures();
        $out = [];
        foreach ($this->measuringSpans($utility, $meter) as [$from, $to, $y]) {
            $days = 0; $tempDays = 0; $hdd = 0.0; $skip = false;
            for ($t = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 00:00:00'); $t < $e; $t += 86400) {
                $d = date('Y-m-d', $t);
                if (isset($excluded[substr($d, 0, 7)])) { $skip = true; break; }
                $days++;
                if (!isset($temps[$d]['avg'])) continue;
                $tempDays++;
                $hdd += max(0.0, $hddBase - (float)$temps[$d]['avg']);
            }
            if ($skip || $days < $minDays || $y <= 0 || $tempDays < (int)floor($days * 0.9)) continue;
            $out[] = [$hdd, (float)$days, $y, self::MONTH_DAYS / $days];
        }
        return $out;
    }

    /**
     * v3.7.0 (#37) — die Ablesungsabstände eines Zählers: von einer echten
     * Ablesung zur nächsten (geschätzte dazwischen messen nichts), mit dem
     * Verbrauch dazwischen in der Verbrauchseinheit (Gas mit den datierten
     * Faktoren). Ein Abstand, in dem ein Paar nicht rechenbar ist oder das
     * Gerät wechselt, fehlt. Zähler mit Zeiträumen: je echter Zeitraum einer.
     *
     * @return list<array{0:string,1:string,2:float}>  [von, bis (ohne), Verbrauch]
     */
    private function measuringSpans(string $utility, array $meter): array
    {
        $u = Utilities::get($utility);
        $factorOn = !empty($u['unit_to_kwh'])
            ? fn(string $date): float => $this->factors()->factorOn($utility, $date)
            : static fn(string $date): float => 1.0;
        $bounds = fn(string $a, string $b): array => $utility === 'gas' ? $this->factors()->boundariesBetween($a, $b) : [];
        $sumKwh = function (string $a, string $b, float $perDay, callable $fOn, array $cuts): float {
            $s = 0.0;
            foreach ($this->distributeToMonths($a, $b, $perDay, 0.0, $fOn, $cuts) as $v) $s += $v['kwh'];
            return $s;
        };
        $days = static fn(string $a, string $b): int => (int)(new \DateTime($a))->diff(new \DateTime($b))->days;

        if (($meter['capture'] ?? 'counter') === 'period') {
            $out = [];
            foreach ((array)$this->store->read("$utility/periods.json", []) as $p) {
                if (!is_array($p) || ($p['meter_id'] ?? null) !== ($meter['id'] ?? null) || !empty($p['is_estimated'])) continue;
                $from = (string)($p['from'] ?? ''); $to = (string)($p['to'] ?? '');
                if (!Dates::isIsoDate($from) || !Dates::isIsoDate($to) || $from > $to) continue;
                $end = date('Y-m-d', (int)strtotime($to . ' +1 day'));
                $n = $days($from, $end);
                $meterUnit = ($p['value_unit'] ?? 'consumption') === 'meter';
                $y = $sumKwh($from, $end, max(0.0, (float)($p['value'] ?? 0)) / $n,
                    $meterUnit ? $factorOn : static fn(string $d): float => 1.0, $meterUnit ? $bounds($from, $end) : []);
                $out[] = [$from, $end, $y];
            }
            usort($out, fn($a, $b) => strcmp($a[0], $b[0]));
            return $out;
        }

        [$actual] = $this->plausibleReadings($this->readings->list($utility, (string)$meter['id']), $meter);
        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) $devicesById[$d['id'] ?? ''] = $d;
        $dev = fn(array $r): ?string => $r['device_id'] ?? $this->deviceIdOnDate($meter, (string)$r['date']);
        $out = [];
        $start = null; $y = 0.0; $ok = true;
        foreach ($actual as $i => $r) {
            if ($i > 0 && $start !== null) {
                $prev = $actual[$i - 1];
                $n = $days((string)$prev['date'], (string)$r['date']);
                $raw = $n > 0 ? $this->consumptionBetween($prev, $r, $devicesById, $meter) : 0.0;
                if ($raw === null || $raw < 0 || $dev($prev) !== $dev($r)) $ok = false;
                elseif ($n > 0) $y += $sumKwh((string)$prev['date'], (string)$r['date'], $raw / $n, $factorOn, $bounds((string)$prev['date'], (string)$r['date']));
            }
            // v3.9.0 (ablesung-pruefen) — ein Stand „nur für die Abrechnung“ misst so wenig wie ein geschätzter
            if (!empty($r['is_estimated']) || !empty($r['is_billing_only'])) continue;
            if ($start !== null && $ok && (string)$r['date'] > $start) $out[] = [$start, (string)$r['date'], $y];
            $start = (string)$r['date']; $y = 0.0; $ok = true;
        }
        return $out;
    }

    /**
     * Gewichtete kleinste Quadrate für y = a·h + c·d ohne Achsenabschnitt;
     * a, c ≥ 0. Bei Monatspunkten ist jedes Gewicht 1 — dieselben Zahlen wie
     * bis v3.6.
     *
     * @param list<array{0:float,1:float,2:float,3?:float}> $pts  [HGT, Tage, Verbrauch, Gewicht]
     * @return array{0:float,1:float}
     */
    private static function fitHeatModel(array $pts): array
    {
        $shh = $shd = $sdd = $shy = $sdy = 0.0;
        foreach ($pts as $p) {
            [$h, $d, $y] = $p;
            $w = (float)($p[3] ?? 1.0);
            $shh += $w * $h * $h; $shd += $w * $h * $d; $sdd += $w * $d * $d; $shy += $w * $h * $y; $sdy += $w * $d * $y;
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
            $temps = $this->temperatures();
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
                $out[$m] = ['mean' => $mean, 'sd' => $sd, 'source' => 'temperature_history', 'years' => count($v)];   // v3.7.0 — `years` additiv
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
    private function applyHeatModel(array $monthly, string $utility, string $valueField, ?array $meter = null, ?float $hddBase = null): array
    {
        $model   = $this->heatModel($utility, $monthly, $meter, $hddBase);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $minHdd  = (float)$this->settings->get('min_hdd_regression', 5.0);
        // v3.7.0 — das Normal für dieselbe Heizgrenze wie die Gradtage des Monats
        // (die des Zählers bzw. die angefragte; bis v3.6 immer die Einstellung)
        $normals = $this->hddNormals($hddBase ?? $this->hddBaseFor($meter));
        foreach ($monthly as &$m) {
            $m['expected_heat'] = null;
            // v3.9.0 (#38) — davon die Grundlast c × Tage: Die Grundlast-Prüfung der
            // Gütesperre misst nach unten c selbst (AnomalyService::quality)
            $m['expected_base'] = null;
            $m['weather_delta_pct'] = null;
            $m['heat_adjusted'] = null;
            $m['heat_adjusted_basis'] = null;   // v3.7.0 (wirklichkeit-2)
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
            $m['expected_base'] = round($model['c'] * $days, 1);
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
                // v3.7.0 (wirklichkeit-2) — warum „bereinigt“ gleich dem Rohwert ist
                // (bei echten Daten in vielen gemessenen Monaten): Sommer, unter der
                // Grundlast, oder das Normal ist dieser Monat selbst (eigene
                // Temperaturhistorie mit nur einem Jahr, kein Klimanormal)
                $src = (string)($normals[(int)($m['month'] ?? 0)]['source'] ?? '');
                $m['heat_adjusted_basis'] = $hdd <= $minHdd ? 'summer'
                    : ($val <= $base ? 'base_load'
                    : ($src === 'temperature_history' && (int)($normals[(int)$m['month']]['years'] ?? 2) < 2 ? 'normal_is_actual' : 'model'));
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
            // v3.7.0 (#37) — dieselbe Auswahl wie AnomalyService::detect: verteilte Monate zählen nicht
            if (($m['days'] ?? 0) >= $minDays && empty($m['device_swap']) && empty($m['interpolated'])) $anomalyMonths++;
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
     * v3.7.0 (GitHub #37, #39) — Wie viele Monatswerte eines Zählers sind
     * verteilt statt gemessen, und auf wie vielen Ablesungen beruhen sie?
     * Grundlage für den Hinweis in Verbrauchsart, Analyse und PV-Übersicht
     * (`readings` zählt echte Ablesungen, die in die Rechnung eingehen — ohne
     * geschätzte und ohne übergangene; bei Zählern mit Zeiträumen die echten
     * Zeiträume). `heat_basis`: worüber das Heizmodell rechnet (`months`,
     * `intervals`), null ohne Modell. Lieferarten: null.
     *
     * @return array<string,mixed>|null
     */
    public function interpolationInfo(string $utility, array $meter, array $monthly): ?array
    {
        if (Utilities::isDelivery($utility) || $monthly === []) return null;
        $yms = [];
        foreach ($monthly as $m) if (!empty($m['interpolated'])) $yms[] = (string)$m['ym'];
        $byYear = $this->measuringReadingsByYear($utility, $meter);
        $heat = Utilities::isHgtRelevant($utility) ? $this->heatModel($utility, $monthly, $meter) : null;
        return [
            'max_gap_days'        => self::MEASURED_GAP_MAX_DAYS,
            'months'              => count($monthly),
            'interpolated_months' => count($yms),
            'interpolated'        => $yms,
            'readings'            => array_sum($byYear),
            'readings_by_year'    => (object)$byYear,
            // v3.9.0 (L10, additiv) — geschätzte Stände je Jahr: Sie messen nicht, stehen aber
            // in der Liste („8 Ablesungen und 1 Schätzung“ statt „8“ neben 9 Einträgen)
            'estimated_by_year'   => (object)$this->estimatedReadingsByYear($utility, $meter),
            'from'                => (string)$monthly[0]['ym'],
            'to'                  => (string)$monthly[count($monthly) - 1]['ym'],
            'heat_basis'          => $heat['basis'] ?? null,
            'heat_points'         => $heat['n'] ?? null,
        ];
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Liegt der Beginn des Tages `$date` genau auf einer
     * echten Ablesung (bzw. Zeitraumgrenze) oder in einem kurzen Abstand
     * (≤ MEASURED_GAP_MAX_DAYS)? Dann ist eine Summe, die an diesem Tag beginnt
     * oder endet, gemessen — auch wenn Monate dazwischen verteilt sind: Die Summe
     * über verteilte Monate ist die Differenz der Stände, nur ihre Aufteilung ist
     * geschätzt. Geschätzte Ablesungen zählen nicht; eine Gruppe nur, wenn jedes
     * Mitglied gemessen ist.
     */
    public function boundaryMeasured(string $utility, array $meter, string $date): bool
    {
        if (!empty($meter['is_group'])) {
            $members = $this->meters->groupSumMembers($utility, (string)$meter['id']);
            foreach ($members as $m) if (!$this->boundaryMeasured($utility, $m, $date)) return false;
            return $members !== [];
        }
        $spans = [];   // [von, bis (ohne)]
        if (($meter['capture'] ?? 'counter') === 'period') {
            foreach ((array)$this->store->read("$utility/periods.json", []) as $p) {
                if (!is_array($p) || ($p['meter_id'] ?? null) !== ($meter['id'] ?? null) || !empty($p['is_estimated'])) continue;
                if (!Dates::isIsoDate($p['from'] ?? null) || !Dates::isIsoDate($p['to'] ?? null) || $p['from'] > $p['to']) continue;
                $spans[] = [(string)$p['from'], date('Y-m-d', (int)strtotime($p['to'] . ' +1 day'))];
            }
        } else {
            [$actual] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
            $dates = [];
            // v3.9.0 (ablesung-pruefen) — Monatswerte überspringen Stände „nur für die Abrechnung“
            foreach ($actual as $r) if (empty($r['is_estimated']) && empty($r['is_billing_only'])) $dates[] = (string)$r['date'];
            sort($dates);
            for ($i = 1; $i < count($dates); $i++) $spans[] = [$dates[$i - 1], $dates[$i]];
        }
        foreach ($spans as [$a, $b]) {
            if ($a === $date || $b === $date) return true;
            if ($a < $date && $date < $b) {
                return (int)(new \DateTime($a))->diff(new \DateTime($b))->days <= self::MEASURED_GAP_MAX_DAYS;
            }
        }
        return false;
    }

    /**
     * v3.9.0 (L10) — Geschätzte Stände (bzw. Zeiträume) je Jahr: die Gegenstücke zu
     * measuringReadingsByYear(), damit die Zahl im Hinweis zur Liste passt.
     *
     * @return array<string,int>
     */
    private function estimatedReadingsByYear(string $utility, array $meter): array
    {
        $out = [];
        if (!empty($meter['is_group'])) {
            foreach ($this->meters->groupSumMembers($utility, (string)$meter['id']) as $member) {
                foreach ($this->estimatedReadingsByYear($utility, $member) as $y => $n) $out[$y] = ($out[$y] ?? 0) + $n;
            }
        } elseif (($meter['capture'] ?? 'counter') === 'period') {
            foreach ((array)$this->store->read("$utility/periods.json", []) as $p) {
                if (!is_array($p) || ($p['meter_id'] ?? null) !== ($meter['id'] ?? null) || empty($p['is_estimated']) || !Dates::isIsoDate($p['to'] ?? null)) continue;
                $y = substr((string)$p['to'], 0, 4);
                $out[$y] = ($out[$y] ?? 0) + 1;
            }
        } else {
            [$actual] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
            foreach ($actual as $r) {
                if (empty($r['is_estimated'])) continue;
                $y = substr((string)$r['date'], 0, 4);
                $out[$y] = ($out[$y] ?? 0) + 1;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Echte Ablesungen (bzw. Zeiträume) je Jahr, wie sie in die Rechnung eingehen.
     *
     * @return array<string,int>
     */
    private function measuringReadingsByYear(string $utility, array $meter): array
    {
        $out = [];
        if (!empty($meter['is_group'])) {
            foreach ($this->meters->groupSumMembers($utility, (string)$meter['id']) as $member) {
                foreach ($this->measuringReadingsByYear($utility, $member) as $y => $n) $out[$y] = ($out[$y] ?? 0) + $n;
            }
        } elseif (($meter['capture'] ?? 'counter') === 'period') {
            foreach ((array)$this->store->read("$utility/periods.json", []) as $p) {
                if (!is_array($p) || ($p['meter_id'] ?? null) !== ($meter['id'] ?? null) || !empty($p['is_estimated'])) continue;
                if (!Dates::isIsoDate($p['to'] ?? null)) continue;
                $y = substr((string)$p['to'], 0, 4);
                $out[$y] = ($out[$y] ?? 0) + 1;
            }
        } else {
            [$actual] = $this->plausibleReadings($this->readings->list($utility, (string)($meter['id'] ?? '')), $meter);
            foreach ($actual as $r) {
                if (!empty($r['is_estimated'])) continue;
                $y = substr((string)$r['date'], 0, 4);
                $out[$y] = ($out[$y] ?? 0) + 1;
            }
        }
        ksort($out);
        return $out;
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
        if (MeterService::activeBaselineDate($meter) === null) return null;
        // v3.1.0 (H7, MKT-16) — ohne Gradtage: dieselben Kalendermonate vorher und nachher
        if (!Utilities::isHgtRelevant($utility)) return $this->seasonalBaselineComparison($utility, $monthly);

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
        $out['method'] = 'hdd_slope';   // v3.1.0 (H7) — additiv
        return $out;
    }

    /**
     * v3.1.0 (H7, MKT-16) — Vorher/Nachher für Arten ohne Heizkurve (Strom,
     * Wasser, PV): Tagesmittel je Kalendermonat vor und nach der Zäsur, nur über
     * Monate, die es in beiden Phasen gibt (mindestens drei). Änderung in % und
     * je Jahr; das 95-%-Intervall aus der Streuung der Monatsverhältnisse.
     *
     * @return array<string,mixed>|null
     */
    private function seasonalBaselineComparison(string $utility, array $monthly): ?array
    {
        $vf = (Utilities::get($utility)['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $rates = ['before' => [], 'after' => []];
        foreach ($monthly as $m) {
            $days = (int)($m['days'] ?? 0);
            if ($days < $minDays) continue;
            $rates[!empty($m['pre_baseline']) ? 'before' : 'after'][(int)$m['month']][] = (float)($m[$vf] ?? 0) / $days;
        }
        $common = array_values(array_intersect(array_keys($rates['before']), array_keys($rates['after'])));
        if (count($common) < 3) return null;
        $before = 0.0; $after = 0.0; $dimSum = 0; $ratios = [];
        foreach ($common as $mo) {
            $rb = array_sum($rates['before'][$mo]) / count($rates['before'][$mo]);
            $ra = array_sum($rates['after'][$mo]) / count($rates['after'][$mo]);
            $dim = (int)date('t', (int)mktime(0, 0, 0, $mo, 1, 2025));
            $before += $rb * $dim; $after += $ra * $dim; $dimSum += $dim;
            if ($rb > 0) $ratios[] = $ra / $rb;
        }
        if ($before <= 0 || count($ratios) < 3) return null;
        $scale = 365 / $dimSum;
        $deltaPct = ($after - $before) / $before * 100;
        $n = count($ratios);
        $mean = array_sum($ratios) / $n;
        $sd = sqrt(array_sum(array_map(fn($r) => ($r - $mean) ** 2, $ratios)) / max(1, $n - 1));
        $half = 1.96 * $sd / sqrt($n) * 100;
        $ci = [round($deltaPct - $half, 1), round($deltaPct + $half, 1)];
        return [
            'method'          => 'seasonal_mean',
            'before'          => ['per_year' => round($before * $scale, 1), 'months' => count($common)],
            'after'           => ['per_year' => round($after * $scale, 1), 'months' => count($common)],
            'delta_pct'       => round($deltaPct, 1),
            'delta_per_year'  => round(($after - $before) * $scale, 1),
            'delta_pct_ci95'  => $ci,
            'significant'     => $ci[0] > 0 || $ci[1] < 0,
            'months_compared' => count($common),
            'unit'            => (string)(Utilities::get($utility)['consumption_unit'] ?? ''),
        ];
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
     * v3.9.0 (ablesung-pruefen) — `$dayWeights` (Tag → Gewicht, Mittel 1 über den
     * Abstand): ein überbrückter Abstand verteilt nach dem Wetter statt gleichmäßig
     * (s. bridgeDayWeights). Ohne Gewichte bit-gleich wie bisher.
     *
     * @param  array<string,float>|null $dayWeights
     * @return array<string,array{kwh:float,raw:float,days:int,cost:float,spans:list<array{0:string,1:string}>}>
     */
    private function distributeToMonths(
        string $start,
        string $end,
        float $rawPerDay,
        float $priceCents,
        callable $factorOn,
        array $boundaries = [],
        ?array $dayWeights = null,
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
                if ($dayWeights !== null) {
                    $wsum = 0.0;
                    for ($t = strtotime($cur->format('Y-m-d') . ' 12:00:00'), $te = strtotime($seg->format('Y-m-d') . ' 00:00:00'); $t < $te; $t += 86400) {
                        $wsum += $dayWeights[date('Y-m-d', $t)] ?? 1.0;
                    }
                    $raw = $rawPerDay * $wsum;
                }
                $kwh = $raw * $factorOn($cur->format('Y-m-d'));
                $out[$ym]['kwh']  += $kwh;
                $out[$ym]['raw']  += $raw;
                $out[$ym]['days'] += $d;
                $out[$ym]['cost'] += $kwh * $priceCents / 100.0;
                // v2.9.0 — mit den kWh des Abschnitts: Die Vertragsrechnung
                // teilt sie an Vertrags- und Preisstichtagen (CALC-10)
                $out[$ym]['spans'][] = [$cur->format('Y-m-d'), $seg->format('Y-m-d'), $kwh];
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
        // v3.9.0 (#38) — dazu die Gradtage jeder Grenze des Heizgrenze-Rasters über dieselben Tage
        // (`_hdd_grid`, intern: forMeter() nimmt das Feld heraus und merkt es sich). Der Vorschlag
        // rechnet so nicht 25-mal den ganzen Zähler; bei zehn Jahren Tageswerten dauerte „Zu tun“
        // sonst Sekunden
        [$gFrom, $gTo, $gStep] = self::HEAT_LIMIT_GRID;
        $bases = [];
        for ($b = $gFrom; $b <= $gTo + 1e-9; $b += $gStep) $bases[sprintf('%.1f', round($b, 1))] = round($b, 1);
        foreach ($monthly as $ym => &$data) {
            [$yr, $mn] = array_map('intval', explode('-', $ym));
            $dim = (int)date('t', mktime(0, 0, 0, $mn, 1, $yr));
            $sum = 0.0; $min = PHP_FLOAT_MAX; $max = -PHP_FLOAT_MAX; $cnt = 0; $hdd = 0.0; $hddTop = 0.0;
            $grid = array_fill_keys(array_keys($bases), 0.0);
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
                $hddTop += max(0.0, AnomalyService::SUMMER_REF_BASE - $avg);
                foreach ($bases as $k => $b) $grid[$k] += max(0.0, $b - $avg);
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
            // v3.7.1 (#38) — Heizgradtage zur obersten Rastergrenze, unabhängig von der Heizgrenze:
            // die Sommer-Referenz der Grundlast-Prüfung (AnomalyService::summerReference)
            $data['hdd_top']   = round($hddTop, 1);
            $data['_hdd_grid'] = array_map(fn($v) => round($v, 1), $grid);   // v3.9.0 (#38), intern
            $data['temp_days'] = $cnt;
            $data['kwh_per_hdd'] = $data['hdd'] > 5 ? round($data['kwh'] / $data['hdd'], 2) : null;
        }
        unset($data);
        return $monthly;
    }

    private function applyUtilityFields(array $monthly, string $utility, array $meter = []): array
    {
        $u = Utilities::get($utility);
        // v3.1.0 (H3, B8) — Heizwärme: CO₂ über den Energieträger der Heizung
        $co2Setting = Utilities::co2Setting($utility, $this->settings->get('waerme_energietraeger'));
        // v3.1.0 (H3, CALC-28) — Warmwasserzähler: Wärme als Rechenwert nach
        // HeizkostenV § 9 Abs. 2 (Q = 2,5 kWh/(m³·K) × V × (t_w − 10 °C))
        $dhwTemp = $utility === 'wasser' && Utilities::roleOf($utility, $meter) === 'warm'
            ? (float)$this->settings->get('warmwasser_temp_c', 60) : null;
        // v3.1.0 (H5, CALC-31) — Fernwärme: Emissionsfaktor des Netzes aus dem Vertrag des Monats
        $fwContracts = $utility === 'fernwaerme' && !empty($meter['id'])
            ? array_values(array_filter($this->contracts->list('fernwaerme', (string)$meter['id']),
                fn($c) => empty($c['is_shadow']) && isset($c['co2_g_per_kwh']) && is_numeric($c['co2_g_per_kwh'])))
            : [];

        // v3.1.0 (H7, CALC-29) — PV: eigener Vermeidungsfaktor statt Strommix (leer = wie bisher)
        $pvAvoided = in_array($utility, ['pv_erzeugung', 'pv_einspeisung'], true) && is_numeric($this->settings->get('co2_pv_avoided'))
            ? (float)$this->settings->get('co2_pv_avoided') : null;
        foreach ($monthly as &$m) {
            // v2.10.0 (CALC-19) — Strom je Jahr (Umweltbundesamt), sonst ein Wert
            $co2Factor = $co2Setting === null ? 0.0
                : $this->settings->co2Factor($co2Setting, (int)($m['year'] ?? (int)substr((string)($m['ym'] ?? ''), 0, 4)));
            if ($pvAvoided !== null) $co2Factor = $pvAvoided;
            if ($fwContracts !== []) {
                $fc = $this->contracts->findActiveForDate($fwContracts, (string)($m['ym'] ?? '') . '-15');
                if ($fc !== null) $co2Factor = (float)$fc['co2_g_per_kwh'];
            }
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
            if ($dhwTemp !== null) {
                $m['dhw_kwh'] = self::dhwKwh((float)($m['m3'] ?? 0.0), $dhwTemp);
                $m['dhw_temp_c'] = $dhwTemp;
            }
            unset($m['raw']);
        }
        unset($m);
        return $monthly;
    }

    /**
     * v3.1.0 (CALC-28) — Wärmemenge für Warmwasser nach HeizkostenV § 9 Abs. 2:
     * 2,5 kWh je m³ und Kelvin über 10 °C. 1 m³ bei 60 °C = 125 kWh. Ein
     * Rechenwert, kein Messwert.
     */
    public static function dhwKwh(float $m3, float $tempC): float
    {
        return $m3 <= 0 || $tempC <= 10 ? 0.0 : round(2.5 * $m3 * ($tempC - 10), 1);
    }

    /**
     * @param array<string,list<array{0:string,1:string,2?:float}>> $coverage
     *        v2.9.0 — Tage mit Verbrauch je Monat und ihre Menge; ohne Angabe
     *        gilt ein Abschnitt ab dem Monatsersten über `days` Tage.
     */
    private function applyContracts(array $monthly, string $utility, array $meter, array $coverage = []): array
    {
        // v1.3.0 — Schattenverträge fließen NICHT in den Saldo ein.
        // v3.1.0 (H6, #17) — dazu der Gruppenvertrag, wenn der Zähler Mitglied ist
        [$contracts, $ctx] = $this->contractScope($utility, $meter);
        // v3.9.0 (F1032, #40) — Subzähler mit eigenem Vertrag: Der Vertrag dieses Zählers rechnet
        // nur den Rest. Die Abschnitte tragen danach die Restmenge, die Zeile `rest_of`.
        $subs = $this->deductingSubmeters($utility, $meter);
        if ($subs !== []) [$monthly, $coverage] = $this->deductSubmeters($monthly, $coverage, $subs);
        if (empty($contracts)) {
            return $this->applyEmptyContractFields($monthly, $utility);
        }
        $monthly = $utility === 'wasser'
            ? $this->applyWaterContracts($monthly, $contracts, $utility)
            : $this->applyStandardContracts($monthly, $contracts, $coverage, $ctx);
        // v3.1.0 (H7, MKT-16) — Einspeisung: Gutschriften des Direktvermarkters ersetzen kWh × ct im Zeitraum
        return Utilities::isFeedIn($utility) ? $this->applyRevenueStatements($monthly, $contracts) : $monthly;
    }

    /**
     * v3.1.0 (H7, MKT-16) — Gutschriften (`revenue_statements`) eines
     * Einspeisevertrags: Der Betrag verteilt sich tagesgenau auf die Monate und
     * ersetzt dort die Rechnung kWh × ct für die abgedeckten Tage. Der Monat trägt
     * `revenue_source` statement (ganz abgedeckt) bzw. mixed.
     */
    private function applyRevenueStatements(array $monthly, array $contracts): array
    {
        $statements = [];
        foreach ($contracts as $c) foreach ((array)($c['revenue_statements'] ?? []) as $s) if (is_array($s)) $statements[] = $s;
        if ($statements === []) return $monthly;
        foreach ($monthly as &$m) {
            $ms = sprintf('%04d-%02d-01', (int)$m['year'], (int)$m['month']);
            $me = date('Y-m-t', (int)strtotime($ms));
            $dim = (int)date('t', (int)strtotime($ms));
            $share = 0.0; $covered = 0;
            foreach ($statements as $s) {
                $a = max($ms, (string)$s['from']); $b = min($me, (string)$s['to']);
                if ($a > $b) continue;
                $days = self::daysBetweenDates($a, $b) + 1;
                $total = self::daysBetweenDates((string)$s['from'], (string)$s['to']) + 1;
                $share += (float)$s['amount_eur'] * $days / max(1, $total);
                $covered += $days;
            }
            if ($covered === 0) continue;
            $covered = min($dim, $covered);
            $old = (float)($m['kwh_cost'] ?? 0);
            $new = $share + $old * ($dim - $covered) / $dim;
            $m['kwh_cost'] = round($new, 2);
            $m['cost'] = round((float)($m['cost'] ?? 0) + $new - $old, 2);
            $m['revenue_source'] = $covered >= $dim ? 'statement' : 'mixed';
        }
        unset($m);
        return $monthly;
    }

    /**
     * v3.1.0 (H6, #17) — Die echten Verträge, nach denen ein Zähler rechnet:
     * seine eigenen und, ist er Mitglied einer Gruppe mit Gruppenvertrag, dieser.
     * Im Gruppenvertrag rechnet jedes Mitglied seinen Verbrauch zu seinem
     * Arbeitspreis (HT/NT); Grundpreis, Abschläge und Boni trägt nur das erste
     * Mitglied. So zählen sie einmal, und jede Summe über Zähler (Art,
     * Dashboard, PDF, CSV, Effizienz) stimmt ohne Sonderweg.
     *
     * @return array{0: list<array<string,mixed>>, 1: ?array{member:string, primary:bool}}
     */
    public function contractScope(string $utility, array $meter): array
    {
        $own = array_values(array_filter($this->contracts->list($utility, (string)($meter['id'] ?? '')), fn($c) => empty($c['is_shadow'])));
        $gid = (string)($meter['meter_group_id'] ?? '');
        if (!empty($meter['is_group']) || $gid === '' || $utility === 'wasser') return [$own, null];
        $group = array_values(array_filter($this->contracts->list($utility, $gid),
            fn($c) => empty($c['is_shadow']) && ($c['meter_group_id'] ?? null) === $gid));
        if ($group === []) return [$own, null];
        $members = $this->meters->groupMembers($utility, $gid);
        return [array_merge($own, $group), ['member' => (string)$meter['id'], 'primary' => (string)($members[0]['id'] ?? '') === (string)$meter['id']]];
    }

    // ── v3.9.0 (F1032, GitHub #40) — Rest-Abrechnung am Elternzähler ─────────
    //
    // Hat ein Subzähler einen eigenen Vertrag (eigener oder Gruppenvertrag, kein
    // Angebot), rechnet der Vertrag des Elternzählers nur den Rest: gemessen
    // minus Subzähler, tagesgenau an den Tagen, an denen der Subzähler nach
    // seinem Vertrag rechnet. Ohne eigenen Vertrag bleibt der Subzähler eine
    // Aufschlüsselung (der Elternvertrag rechnet alles). Die Monatszeile des
    // Elternzählers behält `kwh` = Messung und trägt `billed_kwh` (Menge seiner
    // Verträge) und den Hinweis `rest_of`. Summen über Zähler zählen die Menge
    // weiter nur aus Zählern ohne Elternzähler, das Geld zusätzlich aus
    // Subzählern mit eigenem Vertrag (isDeductingSubmeter). Seit v3.1.0
    // versprach die Doku (Modul 2) genau das; umgesetzt war es nie.

    /** @var array<string,array<string,list<array>>> Tagesabschnitte je Zähler und Anfrage (finishMonthly) */
    private array $coverageMemo = [];
    /** @var array<string,list<array<string,mixed>>> deductingSubmeters() je Zähler und Anfrage */
    private array $deductingMemo = [];

    /**
     * So lange nach seiner letzten Ablesung wird ein Subzähler mit seiner
     * letzten Tagesrate fortgeschrieben, wenn der Elternzähler schon weiter
     * abgelesen ist (ein Quartal). Danach zieht nichts mehr ab, der Hinweis
     * nennt die Tage (`open_days`).
     */
    public const REST_EXTRAPOLATE_DAYS = 92;

    private function coverageKey(string $utility, array $meter): string
    {
        return $utility . '|' . ($meter['id'] ?? '') . '|' . md5(serialize($meter)) . '|' . $this->store->generation();
    }

    /**
     * Arten mit Rest-Abrechnung: Zählerstände mit Verträgen, außer Wasser — dort
     * zieht seit v3.1.0 der Schmutzwasser-Abzug (`trinkwasser_minus_abzug`) ab.
     */
    public static function restBillingApplies(string $utility): bool
    {
        return Utilities::exists($utility) && Utilities::isCumulative($utility) && Utilities::hasContracts($utility) && $utility !== 'wasser';
    }

    /**
     * Ein Subzähler, der nach einem eigenen Vertrag rechnet: Seine Menge steckt im
     * Elternzähler, sein Geld nicht mehr. Summen über Zähler zählen sein Geld
     * (Kosten, Abschläge, Saldo), nicht seine Menge.
     */
    public function isDeductingSubmeter(string $utility, array $meter): bool
    {
        if (!self::restBillingApplies($utility) || empty($meter['parent_meter_id']) || !empty($meter['is_group'])) return false;
        return $this->contractScope($utility, $meter)[0] !== [];
    }

    /** Menge, die die Verträge einer Monatszeile rechnen: `billed_kwh` (Rest) oder die Messung. */
    public static function billedQuantity(array $row, string $vf = 'kwh'): float
    {
        return $vf === 'kwh' && array_key_exists('billed_kwh', $row) && $row['billed_kwh'] !== null
            ? (float)$row['billed_kwh'] : (float)($row[$vf] ?? 0.0);
    }

    /**
     * Die Subzähler, deren Menge der Vertrag dieses Zählers nicht rechnet — mit
     * Verträgen, Schnitttagen und Tagesabschnitten. Für eine Gruppe die
     * Subzähler ihrer Mitglieder, die nicht selbst Mitglied sind (Mitglieder
     * rechnet derselbe Gruppenvertrag).
     *
     * @return list<array{meter:array<string,mixed>,contracts:list<array<string,mixed>>,cuts:list<string>,spans:list<array{0:int,1:int,2:float}>}>
     */
    public function deductingSubmeters(string $utility, array $meter): array
    {
        if (!self::restBillingApplies($utility) || !empty($meter['parent_meter_id'])) return [];
        if (!empty($meter['is_group'])) {
            $memberIds = array_map(fn($m) => (string)($m['id'] ?? ''), $this->meters->groupMembers($utility, (string)$meter['id']));
            $out = [];
            foreach ($this->meters->groupSumMembers($utility, (string)$meter['id']) as $m) {
                foreach ($this->deductingSubmeters($utility, $m) as $s) {
                    if (!in_array((string)$s['meter']['id'], $memberIds, true)) $out[] = $s;
                }
            }
            return $out;
        }
        $id = (string)($meter['id'] ?? '');
        if ($id === '') return [];
        $key = $utility . '|' . $id . '|' . $this->store->generation();
        if (isset($this->deductingMemo[$key])) return $this->deductingMemo[$key];
        $this->deductingMemo[$key] = [];   // Schutz gegen Rekursion (max. eine Ebene ist ohnehin Regel)
        $out = [];
        foreach ($this->meters->list($utility) as $sub) {
            if ((string)($sub['parent_meter_id'] ?? '') !== $id) continue;
            [$contracts] = $this->contractScope($utility, $sub);
            if ($contracts === []) continue;
            $out[] = [
                'meter'     => $sub,
                'contracts' => $contracts,
                'cuts'      => $this->contracts->cutDates($contracts),
                'spans'     => SubmeterRest::prepare($this->meterCoverage($utility, $sub)),
            ];
        }
        return $this->deductingMemo[$key] = $out;
    }

    /** Tagesabschnitte eines Zählers aus seiner Monatsrechnung (einmal je Anfrage). */
    private function meterCoverage(string $utility, array $meter): array
    {
        $key = $this->coverageKey($utility, $meter);
        if (!isset($this->coverageMemo[$key])) $this->forMeter($utility, $meter);
        return $this->coverageMemo[$key] ?? [];
    }

    /**
     * Abzug der Subzähler in [a, b): je Subzähler seine Menge an den Tagen mit
     * eigenem Vertrag (auch einem, der ohne Kündigung weiterläuft).
     *
     * @param list<array<string,mixed>> $subs deductingSubmeters()
     * @return array{total:float,by:array<string,float>,estimated:float,open:array<string,int>}
     */
    private function submeterDeduction(array $subs, string $a, string $b): array
    {
        $out = ['total' => 0.0, 'by' => [], 'estimated' => 0.0, 'open' => []];
        foreach ($subs as $s) {
            $id = (string)$s['meter']['id'];
            $hit = false; $kwh = 0.0;
            $maxEst = MeterService::inService($s['meter']) ? self::REST_EXTRAPOLATE_DAYS : 0;
            foreach ($this->contracts->segmentsWithin($s['contracts'], $s['cuts'], $a, $b) as $seg) {
                if ($seg['contract'] === null) continue;
                $hit = true;
                [$m, $e, $open] = SubmeterRest::between($s['spans'], SubmeterRest::day($seg['from']), SubmeterRest::day($seg['to']), $maxEst);
                $kwh += $m + $e;
                $out['estimated'] += $e;
                if ($open > 0) $out['open'][$id] = ($out['open'][$id] ?? 0) + $open;
            }
            if (!$hit) continue;
            $out['by'][$id] = ($out['by'][$id] ?? 0.0) + $kwh;
            $out['total'] += $kwh;
        }
        return $out;
    }

    /**
     * Zieht die Subzähler von den Tagesabschnitten eines Elternzählers ab
     * (Monatszeilen nach Monat geschlüsselt) und setzt je betroffenem Monat
     * den Hinweis `rest_of`. Ein Rest unter null bleibt hier stehen; die
     * Vertragsrechnung rechnet ihn nicht negativ ab (applyStandardContracts).
     *
     * @param list<array<string,mixed>> $subs
     * @return array{0:array<string,array<string,mixed>>,1:array<string,list<array>>}
     */
    private function deductSubmeters(array $monthly, array $coverage, array $subs): array
    {
        $names = [];
        foreach ($subs as $s) $names[(string)$s['meter']['id']] = (string)($s['meter']['name'] ?? $s['meter']['id']);
        foreach ($coverage as $ym => $list) {
            if (!isset($monthly[$ym])) continue;
            $measured = 0.0; $by = []; $est = 0.0; $open = []; $hit = false;
            foreach ($list as $i => $span) {
                $q = (float)($span[2] ?? 0.0);
                $measured += $q;
                $d = $this->submeterDeduction($subs, (string)$span[0], (string)$span[1]);
                if ($d['by'] === []) continue;
                $hit = true;
                foreach ($d['by'] as $id => $k) $by[$id] = ($by[$id] ?? 0.0) + $k;
                foreach ($d['open'] as $id => $n) $open[$id] = ($open[$id] ?? 0) + $n;
                $est += $d['estimated'];
                $coverage[$ym][$i][2] = $q - $d['total'];
            }
            if ($hit) $monthly[$ym]['rest_of'] = SubmeterRest::restOf($measured, $by, $names, $est, $open === [] ? 0 : max($open));
        }
        return [$monthly, $coverage];
    }

    /**
     * Der Hinweis „wie gerechnet wird“ für einen Zeitraum [from, toExcl):
     * gemessen am Zähler (bzw. an den Mitgliedern einer Gruppe), abgezogen je
     * Subzähler, Rest. Null, wenn im Zeitraum kein Subzähler nach einem eigenen
     * Vertrag rechnet. Für die Vertragskarte (contractStatus) und die
     * Rechnungsprüfung.
     *
     * @return array<string,mixed>|null
     */
    public function restBreakdown(string $utility, array $meter, string $from, string $toExcl): ?array
    {
        if ($toExcl <= $from || !self::restBillingApplies($utility)) return null;
        if (!empty($meter['is_group'])) {
            $memberIds = array_map(fn($m) => (string)($m['id'] ?? ''), $this->meters->groupMembers($utility, (string)$meter['id']));
            $acc = null;
            foreach ($this->meters->groupSumMembers($utility, (string)$meter['id']) as $m) {
                $subs = array_values(array_filter($this->deductingSubmeters($utility, $m), fn($s) => !in_array((string)$s['meter']['id'], $memberIds, true)));
                $r = $this->restBreakdownWith($utility, $m, $subs, $from, $toExcl);
                if ($r !== null) $acc = SubmeterRest::mergeRestOf($acc, $r);
            }
            return $acc;
        }
        return $this->restBreakdownWith($utility, $meter, $this->deductingSubmeters($utility, $meter), $from, $toExcl);
    }

    /** @param list<array<string,mixed>> $subs */
    private function restBreakdownWith(string $utility, array $meter, array $subs, string $from, string $toExcl): ?array
    {
        if ($subs === []) return null;
        $names = [];
        foreach ($subs as $s) $names[(string)$s['meter']['id']] = (string)($s['meter']['name'] ?? $s['meter']['id']);
        $f = SubmeterRest::day($from); $t = SubmeterRest::day($toExcl);
        $measured = 0.0; $by = []; $est = 0.0; $open = []; $hit = false;
        $restYm = [];   // Rest je Monat: ein Monat unter null wird nicht abgerechnet (wie applyStandardContracts)
        foreach (SubmeterRest::prepare($this->meterCoverage($utility, $meter)) as [$a, $b, $rate]) {
            $x = max($a, $f); $y = min($b, $t);
            if ($y <= $x) continue;
            $q = ($y - $x) * $rate;
            $measured += $q;
            $ym = substr(SubmeterRest::date($x), 0, 7);
            $restYm[$ym] = ($restYm[$ym] ?? 0.0) + $q;
            $d = $this->submeterDeduction($subs, SubmeterRest::date($x), SubmeterRest::date($y));
            if ($d['by'] === []) continue;
            $hit = true;
            foreach ($d['by'] as $id => $k) $by[$id] = ($by[$id] ?? 0.0) + $k;
            foreach ($d['open'] as $id => $n) $open[$id] = ($open[$id] ?? 0) + $n;
            $est += $d['estimated'];
            $restYm[$ym] -= $d['total'];
        }
        if (!$hit) return null;
        $short = array_sum(array_map(fn($r) => $r < 0 ? -$r : 0.0, $restYm));
        return SubmeterRest::restOf($measured, $by, $names, $est, $open === [] ? 0 : max($open), $short);
    }

    /**
     * v3.9.0 (F1032, #40) — Eigener Verbrauch eines Zählers je Monat: seine Messung minus die
     * Messung seiner direkten Subzähler (mit oder ohne Vertrag), nie unter null. Für
     * Auswertungen nach Rolle (Jahresarbeitszahl, Effizienz): In der Kaskade misst der
     * vordere Zähler alles, die Wärmepumpe ist der Rest. Ein Subzähler, eine Gruppe und
     * ein Zähler ohne Subzähler: wie forMeter(). `heat_adjusted` wird mit abgezogen, wo
     * beide Seiten einen Wert haben, sonst null.
     *
     * @return list<array<string,mixed>>
     */
    public function ownMonthly(string $utility, array $meter): array
    {
        $rows = $this->forMeter($utility, $meter);
        if (!empty($meter['parent_meter_id']) || !empty($meter['is_group']) || !Utilities::isCumulative($utility)) return $rows;
        $id = (string)($meter['id'] ?? '');
        $subs = array_values(array_filter($this->meters->list($utility), fn($m) => (string)($m['parent_meter_id'] ?? '') === $id && $id !== ''));
        if ($subs === []) return $rows;
        $vf = (Utilities::get($utility)['consumption_unit'] ?? 'kWh') === 'kWh' ? 'kwh' : 'm3';
        $sub = [];
        foreach ($subs as $s) {
            foreach ($this->forMeter($utility, $s) as $r) {
                $ym = (string)$r['ym'];
                $sub[$ym]['v'] = ($sub[$ym]['v'] ?? 0.0) + (float)($r[$vf] ?? 0);
                $sub[$ym]['adj'] = ($sub[$ym]['adj'] ?? 0.0) + (float)($r['heat_adjusted'] ?? 0);
                if (($r['heat_adjusted'] ?? null) === null) $sub[$ym]['no_adj'] = true;
            }
        }
        foreach ($rows as &$r) {
            $s = $sub[(string)$r['ym']] ?? null;
            if ($s === null) continue;
            $r[$vf] = round(max(0.0, (float)($r[$vf] ?? 0) - $s['v']), 3);
            $r['heat_adjusted'] = ($r['heat_adjusted'] ?? null) !== null && empty($s['no_adj'])
                ? round(max(0.0, (float)$r['heat_adjusted'] - $s['adj']), 1) : null;
            $r['own_of_submeters'] = round($s['v'], 1);   // abgezogen (Auskunft)
        }
        unset($r);
        return $rows;
    }

    /**
     * Schätzung der Restmenge je Tag für Lücke und Rest des Saldos: die Schätzung
     * des Zählers minus die der Subzähler an den Tagen mit eigenem Vertrag (je
     * Subzähler sein eigenes Modell; ein Tag nie unter null).
     */
    private function restEstimator(string $utility, array $meter, ?callable $est, string $vf): ?callable
    {
        if ($est === null) return null;
        $subs = $this->deductingSubmeters($utility, $meter);
        if ($subs === []) return $est;
        $saved = $this->lastProjection;   // volumeEstimator() merkt sich die Faktoren des Elternzählers
        $parts = [];
        foreach ($subs as $s) {
            $e = $this->volumeEstimator($utility, $this->forMeter($utility, $s['meter']), $vf, $s['meter']);
            if ($e !== null) $parts[] = [$s, $e];
        }
        $this->lastProjection = $saved;
        if ($parts === []) return $est;
        return function (string $from, string $to, ?array $opt = null) use ($est, $parts): array {
            $out = $est($from, $to, $opt);
            foreach ($parts as [$s, $e]) {
                $segs = array_values(array_filter($this->contracts->segmentsWithin($s['contracts'], $s['cuts'], $from, $to), fn($g) => $g['contract'] !== null));
                if ($segs === []) continue;
                $sub = $e($from, $to);
                foreach ($out as $d => $v) {
                    foreach ($segs as $g) {
                        if ($d >= $g['from'] && $d < $g['to']) { $out[$d] = $v - (float)($sub[$d] ?? 0.0); break; }
                    }
                }
            }
            return array_map(fn($v) => max(0.0, (float)$v), $out);
        };
    }

    /**
     * v3.6.0 — Abschlag einer Monatszeile für [from, toExcl), tagesanteilig.
     * Teilen sich zwei Verträge den Monat (`contract_parts`, etwa ein
     * Lieferantenwechsel zum 15.), zählt jeder Teil nur mit seinen eigenen
     * Tagen: Er beginnt am Vertragsbeginn, frühestens am Monatsersten, und läuft
     * so viele Tage, wie er im Monat hat. Bis v3.5 verteilte „Rechnung prüfen“
     * den Abschlag beider Verträge gleichmäßig über den Monat — eine
     * Schlussrechnung zum 14. bekam einen Teil des neuen Abschlags. Liegen die
     * Teile nicht hintereinander (ein Vertrag mitten in einem anderen), bleibt
     * es beim Monatsmittel.
     *
     * @param array<string,string> $starts Vertragsbeginn je Vertrags-ID ({@see contractStarts()})
     */
    public static function advanceBetween(array $row, string $from, string $toExcl, array $starts): float
    {
        $ms = (string)($row['ym'] ?? '') . '-01';
        if (!Dates::isIsoDate($ms) || !isset($row['advance_eur']) || $row['advance_eur'] === null) return 0.0;
        $me = date('Y-m-d', (int)strtotime("$ms +1 month"));
        $days = fn(string $a, string $b): int => (int)round(((new \DateTimeImmutable("$b 12:00"))->getTimestamp() - (new \DateTimeImmutable("$a 12:00"))->getTimestamp()) / 86400);
        $overlap = fn(string $a, string $b): int => max(0, $days(max($a, $from), min($b, $toExcl)));
        $dim = $days($ms, $me);
        $part = $overlap($ms, $me);
        if ($part === 0) return 0.0;
        // v3.9.0 (auswertung, Nachtrag) — Raten mit Rhythmus (alle 2 oder 3 Monate, Termine
        // laut Rechnung) zählen ganz an ihrem Tag, wie im Saldo der Karte und in der Prognose;
        // anteilig nach Tagen nur der monatliche Teil. Bis dahin verteilte „Rechnung prüfen“
        // und das Abrechnungsjahr die Rate über die Tage ihres Monats.
        $rateIn = 0.0; $rateBy = [];
        foreach ((array)($row['advance_dates'] ?? []) as $r) {
            $amt = (float)($r['amount_eur'] ?? 0.0);
            $cid = (string)($r['contract_id'] ?? '');
            $rateBy[$cid] = ($rateBy[$cid] ?? 0.0) + $amt;
            $d = (string)($r['date'] ?? '');
            if ($d >= $from && $d < $toExcl) $rateIn += $amt;
        }
        $parts = (array)($row['contract_parts'] ?? []);
        if ($part < $dim && count($parts) > 1) {
            $spans = [];
            foreach ($parts as $p) {
                $start = $starts[(string)($p['contract_id'] ?? '')] ?? null;
                $n = (int)($p['days'] ?? 0);
                if ($start === null || $n <= 0) { $spans = null; break; }
                $a = max($ms, $start);
                $spans[] = [$a, date('Y-m-d', (int)strtotime("$a +$n days")), $n,
                    (($p['advance_eur'] ?? null) === null ? 0.0 : (float)$p['advance_eur']) - ($rateBy[(string)($p['contract_id'] ?? '')] ?? 0.0)];
            }
            if ($spans !== null) {
                usort($spans, fn($x, $y) => $x[0] <=> $y[0]);
                $ok = true;
                foreach ($spans as $i => $s) if ($s[1] > $me || ($i > 0 && $s[0] < $spans[$i - 1][1])) $ok = false;
                if ($ok) return array_sum(array_map(fn($s) => $s[3] * $overlap($s[0], $s[1]) / $s[2], $spans)) + $rateIn;
            }
        }
        return ((float)$row['advance_eur'] - array_sum($rateBy)) * $part / $dim + $rateIn;
    }

    /**
     * v3.9.0 (L7) — Abschlag der gemessenen Tage (`advance_measured_eur`, additiv):
     * Der erste und der letzte Monat einer Ablesereihe sind oft nur zum Teil
     * gemessen (letzte Ablesung am 05.01.: vier Tage Januar). Der Abschlag ist für
     * den ganzen Monat fällig; neben den Kosten von vier Tagen ergab der volle
     * Januarabschlag aber „Saldo: Guthaben 100,84 €“ in der Kachel. Für Summen über
     * gemessene Monate (Kachel, Monatstabelle) zählt deshalb der Anteil der
     * gemessenen Tage — tagesanteilig wie im Abrechnungsjahr (advanceBetween).
     * Ebenso der Grundpreis in `cost_measured`: Kosten der gemessenen Tage, wie
     * die Abrechnungsperiode sie bis zur letzten Ablesung zählt. Sonst sind die
     * Werte gleich `advance_eur` bzw. `cost`.
     *
     * @param list<array<string,mixed>> $monthly
     * @param array<string,string> $starts
     * @return list<array<string,mixed>>
     */
    public static function addMeasuredAdvance(array $monthly, array $starts): array
    {
        $last = count($monthly) - 1;
        foreach ($monthly as $i => &$m) {
            if (!array_key_exists('advance_eur', $m)) continue;
            $m['advance_measured_eur'] = $m['advance_eur'];
            $m['cost_measured'] = $m['cost'] ?? null;
            if ($i !== 0 && $i !== $last) continue;
            $ms0 = (string)($m['ym'] ?? '') . '-01';
            $dim0 = Dates::isIsoDate($ms0) ? (int)date('t', (int)strtotime($ms0)) : 0;
            $d0 = (int)($m['days'] ?? $dim0);
            if ($dim0 > 0 && $d0 > 0 && $d0 < $dim0 && isset($m['cost']) && is_numeric($m['base_price_eur'] ?? null)) {
                $m['cost_measured'] = round((float)$m['cost'] - (float)$m['base_price_eur'] * (1 - $d0 / $dim0), 2);
            }
            if ($m['advance_eur'] === null) continue;
            $ms = (string)($m['ym'] ?? '') . '-01';
            if (!Dates::isIsoDate($ms)) continue;
            $dim = (int)date('t', (int)strtotime($ms));
            $days = (int)($m['days'] ?? $dim);
            if ($days <= 0 || $days >= $dim) continue;
            $me = date('Y-m-d', (int)strtotime("$ms +1 month"));
            // letzter Monat: vom Monatsersten an gemessen; erster: bis zum Monatsende
            [$from, $toExcl] = $i === $last ? [$ms, date('Y-m-d', (int)strtotime("$ms +$days days"))]
                : [date('Y-m-d', (int)strtotime("$me -$days days")), $me];
            $m['advance_measured_eur'] = round(self::advanceBetween($m, $from, $toExcl, $starts), 2);
        }
        unset($m);
        return $monthly;
    }

    /** v3.6.0 — Vertragsbeginn je ID (für {@see advanceBetween()}). @return array<string,string> */
    public static function contractStarts(array $contracts): array
    {
        $out = [];
        foreach ($contracts as $c) if (is_array($c) && isset($c['id'])) $out[(string)$c['id']] = (string)($c['start'] ?? '');
        return $out;
    }

    /** v3.1.0 (H6) — Trägt ein Vertragsanteil Grundpreis, Abschlag und Boni? Im Gruppenvertrag nur das erste Mitglied. */
    private static function carriesFixed(array $c, ?array $ctx): bool
    {
        return empty($c['meter_group_id']) || $ctx === null || $ctx['primary'];
    }

    /** v3.1.0 (H6) — Zähler und Gruppen mit Vertrag (für Empfehlungen). */
    public function contractTargets(string $utility): array
    {
        return $this->contracts->targets($utility);
    }

    /**
     * v3.1.0 (H6) — Ein Gruppenvertrag mit Arbeitspreisen je Mitglied (HT/NT),
     * gesehen von der ganzen Gruppe: ein Mischpreis je Stichtag, gewichtet mit
     * dem Verbrauch der Mitglieder in ihren letzten zwölf Monaten. Für
     * Hochrechnung, Prognose und Wechsel, die mit einer Gesamtmenge rechnen.
     * Sonst unverändert.
     */
    public function contractView(string $utility, array $meter, array $c): array
    {
        if (empty($meter['is_group']) || empty($c['working_prices_by_meter'])) return $c;
        $kwh = [];
        foreach ($this->meters->groupMembers($utility, (string)$meter['id']) as $m) {
            $rows = array_slice($this->forMeter($utility, $m), -12);
            $kwh[(string)$m['id']] = array_sum(array_map(fn($r) => (float)($r['kwh'] ?? 0), $rows));
        }
        $total = array_sum($kwh);
        $dates = array_column((array)($c['working_prices'] ?? []), 'from');
        foreach ((array)$c['working_prices_by_meter'] as $list) foreach ((array)$list as $e) $dates[] = (string)($e['from'] ?? '');
        $dates = array_values(array_unique(array_filter($dates)));
        sort($dates);
        $blend = [];
        foreach ($dates as $d) {
            $sum = 0.0; $w = 0.0;
            foreach ($kwh as $mid => $q) {
                $p = $this->contracts->valueOnDate(ContractService::workingPricesFor($c, (string)$mid), 'ct_per_kwh', $d);
                if ($p === null) continue;
                $share = $total > 0 ? $q / $total : 1 / max(1, count($kwh));
                $sum += $share * $p; $w += $share;
            }
            if ($w > 0) $blend[] = ['from' => $d, 'ct_per_kwh' => round($sum / $w, 4)];
        }
        return ['working_prices' => $blend, 'working_prices_blended' => true] + $c;
    }

    private function applyEmptyContractFields(array $monthly, string $utility): array
    {
        foreach ($monthly as &$m) {
            if (isset($m['rest_of'])) $m['billed_kwh'] = max(0.0, (float)$m['rest_of']['rest']);   // v3.9.0 (F1032)
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

    /**
     * Gas / Strom / Fernwärme / Einspeisung — Arbeitspreis, Grundpreis,
     * Abschlag und Boni je Monat.
     *
     * v2.9.0 (Review CALC-10) — tagesgenau wie die Rechnung. Bis v2.8 galt der
     * ganze Monat nach Vertrag und Preisen vom Monatsersten: Ein Wechsel zum 15.
     * rechnete den Juni komplett zum alten Preis, eine Preiserhöhung zum
     * 15. März griff erst im April, und nach einem vergessenen Vertragsende
     * kostete Verbrauch 0 €. Jetzt:
     *   Arbeitspreis  je Verbrauchsabschnitt (`$coverage`: die Tage mit
     *                 Ablesung und ihre kWh), geschnitten an Vertrags- und
     *                 Preisstichtagen
     *   Grundpreis    tagesanteilig je Vertrag und Preis
     *   Abschlag      Monatsbetrag am ersten Vertragstag des Monats, anteilig
     *                 nach den Vertragstagen (wie calendarSums)
     *   Boni          je Vertrag nach Gutschriftmonat
     * Liegen zwei Verträge in einem Monat, trägt die Zeile den mit den meisten
     * Tagen als `contract_id` und die Teile einzeln in `contract_parts`. Läuft
     * ein beendeter Vertrag mangels Kündigung weiter, ist die Zeile
     * `contract_assumed` — seine letzten Preise gelten fort.
     *
     * @param array<string,list<array{0:string,1:string,2?:float}>> $coverage
     */
    private function applyStandardContracts(array $monthly, array $contracts, array $coverage = [], ?array $ctx = null): array
    {
        $running = [];
        // v3.7.0 (Qualität) — Schnitttage einmal je Zähler statt je Ableseintervall
        $cuts = $this->contracts->cutDates($contracts);
        foreach ($monthly as $ym => &$m) {
            $y = (int)$m['year']; $mn = (int)$m['month'];
            $monthStart  = sprintf('%04d-%02d-01', $y, $mn);
            $nextMonth   = date('Y-m-d', strtotime($monthStart . ' +1 month'));
            $dim         = (int)date('t', strtotime($monthStart));
            // v3.9.0 (ablesung-pruefen) — überbrückt: die Menge nach dem Stand (wie `$coverage`)
            $kwh         = (float)($m['_bill_kwh'] ?? $m['kwh'] ?? 0);
            $readingCost = (float)($m['cost'] ?? 0);   // aus dem Ablesepreis: Rückfall ohne Vertragspreis
            $spans = $coverage[$ym] ?? [[
                $monthStart,
                date('Y-m-d', strtotime($monthStart . ' +' . max(1, min($dim, (int)($m['days'] ?? $dim))) . ' days')),
                $kwh,
            ]];

            /** @var array<string,array<string,mixed>> $parts */
            $parts = [];
            $looseCost = 0.0;   // Verbrauch an Tagen ohne Vertrag
            foreach ($spans as $span) {
                [$a, $b] = $span;
                $spanKwh  = (float)($span[2] ?? 0.0);
                $spanDays = max(1, (int)round((strtotime($b) - strtotime($a)) / 86400));
                foreach ($this->contracts->segmentsWithin($contracts, $cuts, $a, $b) as $seg) {
                    $share = $spanKwh * $seg['days'] / $spanDays;
                    $c = $seg['contract'];
                    if ($c === null) {
                        $looseCost += $kwh > 0 ? $readingCost * $share / $kwh : 0.0;
                        continue;
                    }
                    $parts[$c['id']] ??= self::emptyContractPart($c);
                    $wp = $this->contracts->valueOnDate(ContractService::workingPricesFor($c, $ctx['member'] ?? null), 'ct_per_kwh', ContractService::priceDate($seg));
                    $parts[$c['id']]['kwh']      += $share;
                    $parts[$c['id']]['kwh_cost'] += $wp !== null
                        ? $share * $wp / 100.0
                        : ($kwh > 0 ? $readingCost * $share / $kwh : 0.0);
                    $parts[$c['id']]['assumed']  = $parts[$c['id']]['assumed'] || $seg['assumed'];
                }
            }
            // v3.9.0 (F1032) — Rest unter null: Der Subzähler misst in diesem Monat mehr als der
            // Elternzähler (Ablesungen prüfen). Nicht negativ abrechnen, aber ausweisen (`short_kwh`)
            $short = 0.0;
            foreach ($parts as &$p) {
                if ($p['kwh'] >= 0) continue;
                $short -= $p['kwh'];
                $p['kwh'] = 0.0;
                $p['kwh_cost'] = 0.0;
            }
            unset($p);
            if ($looseCost < 0) $looseCost = 0.0;
            // Grundpreis und Abschlag nach Kalendertagen des Monats
            foreach ($this->contracts->segmentsWithin($contracts, $cuts, $monthStart, $nextMonth) as $seg) {
                $c = $seg['contract'];
                if ($c === null) continue;
                $parts[$c['id']] ??= self::emptyContractPart($c);
                $date = ContractService::priceDate($seg);
                $bp = self::carriesFixed($c, $ctx) ? $this->contracts->fixedPerMonthOn($c, $date) : null;
                if ($bp !== null) {
                    $parts[$c['id']]['base'] += $bp * $seg['days'] / $dim;
                    $parts[$c['id']]['has_base'] = true;
                }
                // v3.1.0 (H6, § 14a Modul 1) — steckt im Grundpreis, einzeln ausgewiesen
                if (!empty($c['grid_reduction']) && self::carriesFixed($c, $ctx)
                    && ($red = $this->contracts->valueOnDate($c['grid_reduction'], 'eur_per_year', $date)) !== null) {
                    $parts[$c['id']]['grid_reduction'] = ($parts[$c['id']]['grid_reduction'] ?? 0.0) + $red / 12 * $seg['days'] / $dim;
                }
                $parts[$c['id']]['days'] += $seg['days'];
                $parts[$c['id']]['advance_date'] ??= $date;
                // v3.9.0 (F1034) — Tage des Vertrags im Monat, für die Raten eines Plans mit Rhythmus
                $parts[$c['id']]['first_from'] ??= $seg['from'];
                $parts[$c['id']]['last_to'] = $seg['to'];
                $parts[$c['id']]['assumed'] = $parts[$c['id']]['assumed'] || $seg['assumed'];
            }

            if ($parts === []) {
                $m['contract_id'] = null; $m['advance_eur'] = null;
                $m['base_price_eur'] = null; $m['working_price_ct'] = null;
                $m['bonus_eur'] = null;
                $m['kwh_cost'] = $m['cost'] ?? null;
                $m['monthly_balance'] = null; $m['cumulative_balance'] = null;
                $m['contract_assumed'] = false;
                if (isset($m['rest_of'])) $m['billed_kwh'] = max(0.0, (float)$m['rest_of']['rest']);   // v3.9.0 (F1032)
                continue;
            }

            $sum = ['kwh' => 0.0, 'kwh_cost' => $looseCost, 'base' => 0.0, 'bonus' => 0.0, 'advance' => null];
            $hasBase = false;
            foreach ($parts as $cid => &$p) {
                $c = $p['contract'];
                // F1003 — effektiver Abschlagsplan inkl. „mit Auswirkung"
                // v3.9.0 (F1034) — mit Rhythmus: die Raten, die in den Vertragstagen des Monats fällig sind
                $ap = $p['advance_date'] !== null
                    ? ContractService::advanceIn($this->contracts->effectiveAdvanceSchedule($c), $p['advance_date'],
                        (string)($p['first_from'] ?? $monthStart), (string)($p['last_to'] ?? $nextMonth), min(1.0, $p['days'] / $dim), $dim)
                    : null;
                $fixedShare = self::carriesFixed($c, $ctx);   // v3.1.0 (H6) — Gruppenvertrag: einmal
                $p['advance'] = $ap !== null && $fixedShare ? $ap : null;
                // v3.9.0 (auswertung, Nachtrag) — die Raten mit Rhythmus samt Tag: Teilzeiträume
                // (Abrechnungsjahr, Rechnung prüfen, gemessene Tage) zählen sie nach Termin, nicht anteilig
                $p['advance_dates'] = $p['advance'] !== null
                    ? array_map(fn($r) => $r + ['contract_id' => (string)$cid], ContractService::installments($this->contracts->effectiveAdvanceSchedule($c),
                        (string)($p['first_from'] ?? $monthStart), (string)($p['last_to'] ?? $nextMonth)))
                    : [];
                // v3.7.0 (#33) — die Boni einzeln (Datum, Art, Notiz) für „Bonus −X € am …“
                $p['bonus_items'] = !$fixedShare || ($p['assumed'] && ($c['end'] ?? '') < $monthStart) ? [] : ContractService::bonusesForMonth($c, $y, $mn);
                $p['bonus']   = (float)array_sum(array_column($p['bonus_items'], 'amount_eur'));
                $p['cost']    = $p['kwh_cost'] + $p['base'] - $p['bonus'];
                $sum['kwh']      += $p['kwh'];
                $sum['kwh_cost'] += $p['kwh_cost'];
                $sum['base']     += $p['base'];
                $sum['bonus']    += $p['bonus'];
                $hasBase = $hasBase || $p['has_base'];
                if ($p['advance'] !== null) $sum['advance'] = ($sum['advance'] ?? 0.0) + $p['advance'];
                if ($p['advance'] !== null) {
                    $running[$cid] = ($running[$cid] ?? 0.0) + $p['cost'] - $p['advance'];
                }
            }
            unset($p);

            // Die Zeile gehört dem Vertrag mit den meisten Tagen im Monat
            uasort($parts, fn($x, $z) => [$z['days'], (string)($z['contract']['start'] ?? '')] <=> [$x['days'], (string)($x['contract']['start'] ?? '')]);
            $mainId = (string)array_key_first($parts);
            $main   = $parts[$mainId];
            $wpNow  = $sum['kwh'] > 0
                ? $sum['kwh_cost'] / $sum['kwh'] * 100.0
                : $this->contracts->valueOnDate($main['contract']['working_prices'] ?? [], 'ct_per_kwh', $main['advance_date'] ?? $monthStart);
            $combined = round($sum['kwh_cost'] + $sum['base'] - $sum['bonus'], 2);

            $m['contract_id']      = $mainId;
            $m['contract_assumed'] = $main['assumed'];
            $m['advance_eur']      = $sum['advance'] !== null ? round($sum['advance'], 2) : null;
            $dates = array_merge(...array_values(array_map(fn($p) => $p['advance_dates'] ?? [], $parts)));
            if ($dates !== []) $m['advance_dates'] = $dates;   // v3.9.0 (auswertung, Nachtrag) — additiv, nur mit Rhythmus
            $m['base_price_eur']   = $hasBase ? round($sum['base'], 2) : null;
            $m['working_price_ct'] = $wpNow !== null ? round($wpNow, 4) : null;
            $m['bonus_eur']        = round($sum['bonus'], 2);
            $items = array_merge(...array_values(array_map(fn($p) => $p['bonus_items'] ?? [], $parts)));
            if ($items !== []) $m['bonus_items'] = $items;   // v3.7.0 — additiv, nur in Monaten mit Bonus
            $red = array_sum(array_map(fn($p) => (float)($p['grid_reduction'] ?? 0.0), $parts));
            if ($red > 0) $m['grid_reduction_eur'] = round($red, 2);   // v3.1.0 (H6) — additiv, nur mit § 14a Modul 1
            $m['kwh_cost']         = round($sum['kwh_cost'], 2);
            $m['cost']             = $combined;
            // v3.9.0 (F1032) — Rest-Abrechnung: die Menge, die die Verträge dieses Zählers rechnen
            // (`kwh` bleibt die Messung). Nur an Zeilen mit Hinweis `rest_of`, sonst unverändert.
            if (isset($m['rest_of'])) {
                $m['billed_kwh'] = round($sum['kwh'], 1);
                if ($short > 0.05) $m['rest_of']['short_kwh'] = round($short, 1);
            }
            // v3.9.0 (F1032) — Kosten an Tagen ohne Vertrag (aus dem Ablesepreis), additiv: Summen
            // über Zähler zählen beim Subzähler nur das Geld seiner Verträge (addSubmeterMoney)
            if ($looseCost > 0.005) $m['uncontracted_cost'] = round($looseCost, 2);
            if (count($parts) > 1) {
                $m['contract_parts'] = array_values(array_map(fn($id, $p) => [
                    'contract_id'    => $id,
                    'days'           => $p['days'],
                    'kwh'            => round($p['kwh'], 1),
                    'kwh_cost'       => round($p['kwh_cost'], 2),
                    'base_price_eur' => $p['has_base'] ? round($p['base'], 2) : null,
                    'advance_eur'    => $p['advance'] !== null ? round($p['advance'], 2) : null,
                    'bonus_eur'      => round($p['bonus'], 2),
                    'cost'           => round($p['cost'], 2),
                    'assumed'        => $p['assumed'],
                ], array_keys($parts), $parts));
            }
            if ($m['advance_eur'] !== null) {
                $m['monthly_balance']    = round($combined - $m['advance_eur'], 2);
                $m['cumulative_balance'] = isset($running[$mainId]) ? round($running[$mainId], 2) : null;
            } else {
                $m['monthly_balance']    = null;
                $m['cumulative_balance'] = null;
            }
        }
        unset($m);
        return $monthly;
    }

    /** @return array<string,mixed> leerer Anteil eines Vertrags an einem Monat */
    private static function emptyContractPart(array $contract): array
    {
        return ['contract' => $contract, 'kwh' => 0.0, 'kwh_cost' => 0.0, 'base' => 0.0, 'has_base' => false,
                'days' => 0, 'advance_date' => null, 'first_from' => null, 'last_to' => null, 'assumed' => false];
    }

    /**
     * Water — three priced components per contract (v1.0.3).
     *
     * Per month, computes:
     *   trinkwasser   = m³_trinkwasser × ct/m³ + Grundpreis
     *   schmutzwasser = m³_schmutzwasser × ct/m³
     *                 (m³_schmutzwasser = m³_trinkwasser when basis = 'trinkwasser';
     *                  when basis = 'separater_zaehler' the volume is read from
     *                  the referenced meter — F-01 fix, v1.1.0; when basis =
     *                  'trinkwasser_minus_abzug' it is m³_trinkwasser minus the
     *                  deduction meters, at least 0 — v3.1.0, CALC-20)
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

            // v3.9.0 (ablesung-pruefen) — überbrückter Monat: Kosten nach dem Stand (`_bill_kwh` trägt bei Wasser m³)
            $m3 = (float)($m['_bill_kwh'] ?? $m['m3'] ?? 0);
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
            } elseif ($swBasis === 'trinkwasser_minus_abzug') {
                // v3.1.0 (Review CALC-20) — Hauptzähler minus Abzugszähler (Garten).
                // Der Hauptzähler misst alles; was im Garten versickert, kostet
                // Trinkwasser, aber kein Schmutzwasser. Nie unter 0.
                $deductM3 = 0.0;
                foreach ((array)($sw['abzug_meter_ids'] ?? []) as $did) {
                    $did = (string)$did;
                    if ($did === '') continue;
                    if (!array_key_exists($did, $sepMeterM3Cache)) {
                        $sepMeterM3Cache[$did] = $this->monthlyM3ForMeterId($utility, $did);
                    }
                    $deductM3 += (float)($sepMeterM3Cache[$did][$m['ym']] ?? 0.0);
                }
                $swM3 = max(0.0, $m3 - $deductM3);
            } else {
                $swM3 = $m3;
            }
            $swWorking = ($swWp !== null && $swM3 > 0) ? round($swM3 * $swWp / 100.0, 2) : 0.0;
            // v3.9.0 (F1034) — Grundpreis Schmutzwasser (Basis- und Bereitstellungspreis), je Monat
            $swBp = $this->contracts->valueValidOn($sw['base_prices'] ?? [], 'eur_per_month', $y, $mn);
            $swBase = $swBp !== null ? (float)$swBp : 0.0;
            $swTotal = round($swWorking + $swBase, 2);

            // ── Niederschlagswasser ────────────────────────────────
            $nwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year', $y, $mn);
            $nwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            $nwTotal = ($nwRate !== null && $nwArea !== null)
                ? round(((float)$nwArea * (float)$nwRate) / 12.0, 2)
                : 0.0;

            // v3.5.0 (GitHub #34) — der effektive Abschlagsplan wie beim
            // Standardvertrag: eine Rück- oder Nachzahlung „mit Auswirkung“
            // setzt den Abschlag ab ihrem Stichtag (ohne Sonderzahlungen
            // derselbe Wert wie advance_payments)
            $ap = $this->contracts->valueValidOn($this->contracts->effectiveAdvanceSchedule($c), 'amount_eur', $y, $mn);
            // v3.9.0 (F1034) — mit Rhythmus: die Raten, die im Monat fällig sind (sonst 0); monatlich wie bisher
            $apPlan = $this->contracts->effectiveAdvanceSchedule($c);
            if (array_filter($apPlan, fn($r) => is_array($r) && ContractService::hasRhythm($r)) !== []) {
                $ap = ContractService::advanceIn($apPlan, $first, $first,
                    date('Y-m-d', (int)strtotime($first . ' +1 month')), 1.0, (int)date('t', (int)strtotime($first)));
                // v3.9.0 (auswertung, Nachtrag) — die Raten samt Tag (advanceBetween zählt sie nach Termin)
                $wDates = array_map(fn($r) => $r + ['contract_id' => (string)$c['id']],
                    ContractService::installments($apPlan, $first, date('Y-m-d', (int)strtotime($first . ' +1 month'))));
                if ($wDates !== []) $m['advance_dates'] = $wDates;
            }
            $bonusItems = ContractService::bonusesForMonth($c, $y, $mn);
            $bn = (float)array_sum(array_column($bonusItems, 'amount_eur'));

            $combined = round($twTotal + $swTotal + $nwTotal - $bn, 2);

            $m['contract_id']  = $c['id'];
            $m['advance_eur']  = $ap;
            $m['bonus_eur']    = round($bn, 2);
            if ($bonusItems !== []) $m['bonus_items'] = $bonusItems;   // v3.7.0 (#33) — additiv
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
                // v3.1.0 — Abzugszähler und abgezogene Menge (nur bei trinkwasser_minus_abzug)
                'abzug_meter_ids'             => $swBasis === 'trinkwasser_minus_abzug' ? array_values((array)($sw['abzug_meter_ids'] ?? [])) : [],
                'abzug_m3'                    => $swBasis === 'trinkwasser_minus_abzug' ? round($m3 - $swM3, 3) : null,
                'm3'                          => $swM3,
                'ct_per_m3'                   => $swWp,
                'total'                       => $swTotal,
            ] + ($swBp !== null ? ['working_cost' => $swWorking, 'base_price_eur' => $swBp, 'base_cost' => $swBase] : []);   // v3.9.0 (F1034) — additiv, nur mit Grundpreis
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
            $m['base_price_eur']   = round($twBase + $swBase + $nwTotal, 2);   // v3.9.0 (F1034) — mit Grundpreis Schmutzwasser
            $m['kwh_cost']         = round($twWorking + $swWorking, 2); // Verbrauchsabhängig

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
    private function applyWeatherAdjustment(array $monthly, string $utility, string $valueField, ?array $meter = null, ?float $hddBase = null): array
    {
        if (!Utilities::isHgtRelevant($utility)) return $monthly;
        // v2.8.0 — die neuen Felder brauchen keine zwölf eigenen Monate: Die
        // Referenz kommt aus dem Klimanormal, die Erwartung aus dem Heizmodell.
        $monthly = $this->applyHeatModel($monthly, $utility, $valueField, $meter, $hddBase);
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
     * v2.10.0 — Delegiert an DeliveryConsumptionService::tankModel().
     * @return array<string,mixed>
     */
    public function tankModel(string $utility, array $meter): array
    {
        return $this->getDeliveryConsumption()->tankModel($utility, $meter);
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
        $temps   = $this->temperatures();
        // v3.9.0 (#38) — auch Tanks haben ihre eigene Grenze (verteilt wird mit derselben, tankModel)
        $hddBase = $hddBaseOverride ?? $this->hddBaseFor($meter);

        // v2.10.0 — Tankbuch (CALC-04): Verbrauch und Kosten aus derselben
        // Rechnung wie die Bestandskurve. Kosten zum gleitenden
        // Durchschnittspreis des Tankinhalts statt zum Preis der letzten
        // Lieferung; der Anfangsbestand ist nicht mehr kostenlos.
        $delivery   = $this->getDeliveryConsumption();
        $model      = $delivery->tankModel($utility, $meter);
        if (empty($model['days'])) return [];
        $kwhPerUnit = $delivery->kwhPerUnit($utility);

        // Monatsaggregation
        $monthly = [];
        $coverage = [];   // v2.9.0 — je Tag ein Abschnitt: die Vertragsrechnung schneidet tagesgenau
        foreach ($model['days'] as $d => $row) {
            $ym = substr($d, 0, 7);
            if (!isset($monthly[$ym])) {
                $monthly[$ym] = ['kwh' => 0.0, 'days' => 0, 'cost' => 0.0, 'estimated_days' => 0];
            }
            $kwh = $row['draw'] * $kwhPerUnit;
            $coverage[$ym][] = [$d, date('Y-m-d', strtotime($d . ' +1 day')), (float)$kwh];
            $monthly[$ym]['kwh']  += $kwh;
            $monthly[$ym]['days'] += 1;
            if ($row['cost_eur'] !== null) $monthly[$ym]['cost'] += $row['cost_eur'];
            // v2.10.0 — Tage nach dem letzten bekannten Bestand sind geschätzt
            if ($row['estimated']) $monthly[$ym]['estimated_days']++;
        }

        // Mit der Abdeckung zählen die Gradtage des laufenden Monats nur bis
        // heute — gespeicherte Vorhersagetage liegen nicht mehr darin.
        $monthly = $this->enrichWithWeather($monthly, $temps, $hddBase, $coverage);
        $monthly = $this->applyUtilityFields($monthly, $utility);
        $monthly = $this->applyContracts($monthly, $utility, $meter, $coverage);
        // v2.10.0 — effektiver Preis je kWh aus dem Tankbuch (gleitender
        // Durchschnitt des Tankinhalts); bis v2.9 stand hier nichts
        foreach ($monthly as &$m) {
            if ($m['contract_id'] === null && ($m['kwh'] ?? 0) > 0 && ($m['cost'] ?? 0) > 0) {
                $m['working_price_ct'] = round($m['cost'] / $m['kwh'] * 100.0, 4);
            }
        }
        unset($m);
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
        // v3.7.0 (#37) — die Kennung „gemessen oder verteilt“ gilt für sie nicht
        // (null): Ihre Monate sind nach Gradtagen modelliert und gehen schon
        // seit v2.8.0 in keine Abweichung, Empfehlung und Heizkurve ein.
        foreach ($monthly as &$m) {
            $m['regression_point'] = false;
            $m['measured_share'] = null;
            $m['interpolated'] = null;
            $m['reading_gap_days'] = null;
        }
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
