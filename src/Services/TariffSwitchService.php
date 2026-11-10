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
        private ?MarketPriceService $market = null,   // v3.1.0 (H6, MKT-12)
        private ?SettingsService $settings = null,    // v3.11.0 (Nachprüfung) — Land für switchTiming
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
        $meter = $this->meters->target($utility, $meterId);   // v3.1.0 (H6): auch Gruppe
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

        // v3.1.0 (H6) — HT/NT einer Gruppe als Mischpreis
        $all    = array_map(fn($c) => $this->forecast->contractView($utility, $meter, $c), $this->contracts->list($utility, $meterId));
        $real   = array_values(array_filter($all, fn($c) => empty($c['is_shadow'])));
        $shadow = array_values(array_filter($all, fn($c) => !empty($c['is_shadow'])));
        // v3.1.0 (H6, MKT-12) — dynamischer Kandidat: Monatspreise aus den Marktdaten,
        // künftige Monate nach dem Vorjahresmonat; ohne Marktdaten kein Kandidat
        $dynamicNoData = false;
        foreach ($shadow as $i => $s) {
            if (($s['price_model'] ?? null) !== 'dynamic') continue;
            $s = $this->market?->expand($s, date('Y-m', strtotime('-36 months')), date('Y-m', strtotime('+36 months'))) ?? $s;
            if (empty($s['working_prices'])) { $dynamicNoData = true; unset($shadow[$i]); continue; }
            $shadow[$i] = $s;
        }
        $shadow = array_values($shadow);

        // ── Wechseltermin ───────────────────────────────────────────────
        // Nicht der heute laufende Vertrag entscheidet, sondern das Ende der
        // gesamten Bindung: Wer den Folgevertrag bereits abgeschlossen hat,
        // kann nicht zu dessen Beginn wechseln — der Wechsel ist dann schon
        // vollzogen. Ohne diese Kette meldete das Modul den Beginn des
        // Folgevertrags als „Wechseltermin" und rechnete die Referenz mit den
        // Preisen des auslaufenden Vertrags weiter.
        $chain   = $this->bindingChain($real, $today);
        // v2.9.0 (Review CALC-10) — kein Vertrag läuft heute, aber einer ist
        // ohne Kündigung abgelaufen: Er läuft weiter. Bisher hieß das hier „kein Vertrag".
        // v3.11.0 (Skeptiker H3) — ohne Frist laut Vertrag kein Wechseltermin aus einer
        // angenommenen Monatsfrist mehr: der Platzhalter (nächster Monatserster) unten.
        $renewed = false;
        if ($chain === []) {
            $r = $this->contracts->resolveForDate($real, $today);
            if ($r !== null && $r['assumed']) {
                $chain = [$r['contract']];
                $renewed = true;
            }
        }
        $current = $chain ? $chain[0] : null;
        $last    = $chain ? $chain[count($chain) - 1] : null;
        // v3.11.0 (Nachprüfung NP3, NP4) — mit dem Land (Fernwärme nach § 32 AVBFernwärmeV, Grundversorgung) und,
        // verlängert, dem Tag vor dem Folgevertrag (wie der Vertragsstatus)
        $country = $this->settings !== null ? (string)$this->settings->get('country', 'DE') : null;
        $renewedUntil = $renewed && $last ? ($this->contracts->coverageOf($last, $real)['to'] ?? null) : null;
        $timing  = $last ? $this->contracts->switchTiming($last, $today, $renewed, $utility, $country, $renewedUntil) : null;

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
        $seasonal = $this->seasonalExpectation($utility, $meter, $today);
        if ($seasonal === null) {
            return $this->unsupported($utility, $meterId, $unit, $today,
                $this->i18n->t('errors.forecast.tooFewMonths'));
        }

        $window = $this->buildWindow($switchDate, $seasonal['by_month']);
        // v3.12.0 — „ab 2. Jahr“: die Monate 13 bis 24 nach dem Wechsel, gleiche Menge je Kalendermonat,
        // die Preise, die dann gelten (Preisstaffel ab dem 13. Monat)
        $window2 = $this->buildWindow(ContractService::addMonthsClamped(substr($switchDate, 0, 7) . '-01', self::WINDOW_MONTHS), $seasonal['by_month']);
        $expectedConsumption = array_sum(array_column($window, 'consumption'));

        // ── Kandidaten ──────────────────────────────────────────────────
        // Die Referenz ist die Vertragskette, nicht ein einzelner Vertrag:
        // Fällt das Fenster über eine Vertragsgrenze, muss jeder Monat mit
        // dem Tarif rechnen, der dann gilt.
        $candidates = [];
        if ($chain) {
            $candidates[] = $this->evaluateChain($chain, $window, $window2, true);
        }
        foreach ($shadow as $s) {
            $candidates[] = $this->evaluate($s, $window, $window2, $unit, false)
                + (($s['price_model'] ?? '') === 'dynamic' ? ['price_model' => 'dynamic', 'dynamic_assumed_months' => count($s['dynamic_assumed'] ?? [])] : []);
        }

        $reference = null;
        foreach ($candidates as $c) {
            if ($c['is_reference']) { $reference = $c; break; }
        }

        foreach ($candidates as &$c) {
            $c = $this->compareToReference($c, $reference, $window, $expectedConsumption, Utilities::isFeedIn($utility));
        }
        unset($c);

        // Referenz bleibt oben; die Angebote dahinter nach dem DAUERHAFTEN
        // Preis sortiert. Nach Jahr 1 zu sortieren würde Lockangebote
        // belohnen — genau die Verzerrung, die das Modul auflösen soll.
        // v2.10.0 (Review CALC-17) — bei der Einspeisung sind die Beträge
        // Erlöse: mehr ist besser, die Rangfolge kehrt sich um. Bis v2.9 stand
        // ein Angebot mit höherer Vergütung als „teurer" in Rot hinten.
        $higherIsBetter = Utilities::isFeedIn($utility);
        usort($candidates, function ($a, $b) use ($higherIsBetter) {
            if ($a['is_reference'] !== $b['is_reference']) return $b['is_reference'] <=> $a['is_reference'];
            $none = $higherIsBetter ? -PHP_FLOAT_MAX : PHP_FLOAT_MAX;
            $cmp = ($a['year2_eur'] ?? $none) <=> ($b['year2_eur'] ?? $none);
            return $higherIsBetter ? -$cmp : $cmp;
        });

        return [
            'utility'   => $utility,
            'meter_id'  => $meterId,
            'unit'      => $unit,
            'supported' => true,
            'higher_is_better' => $higherIsBetter,   // v2.10.0 — Einspeisung: Beträge sind Erlöse
            'note'      => $candidates ? null : $this->i18n->t('errors.tariff.noContracts'),
            'today'     => $today,
            // `current` beschreibt den heute laufenden Vertrag, die Termine
            // stammen aber vom ENDE der Bindung: Ist ein Folgevertrag
            // abgeschlossen, zählt dessen Laufzeit für Kündigung und Wechsel.
            'current'   => $current ? [
                'contract_id'          => (string)$current['id'],
                'label'                => $this->labelFor($current),
                'provider'             => (string)($current['provider'] ?? ''),
                'tariff_name'          => (string)($current['tariff_name'] ?? ''),
                'end'                  => $current['end'] ?? null,
                'notice_period_months' => $current['notice_period_months'] ?? null,
                'notice_period_days'   => $current['notice_period_days'] ?? null,   // v2.9.0
                'notice_mode'          => $current['notice_mode'] ?? null,          // v2.9.0
                'renewed'              => $renewed,                                 // v2.9.0
                'switch_date'          => $timing['switch_date'] ?? null,
                'cancel_by'            => $timing['cancel_by'] ?? null,
                'days_to_cancel'       => $timing['days_to_cancel'] ?? null,
                'basis'                => $timing['basis'] ?? 'unknown',
                // v3.11.0 (Nachprüfung NP3, additiv) — Fernwärme ohne Frist: Verlängerung nach § 32 AVBFernwärmeV
                'renewal_term'         => $timing['renewal_term'] ?? null,
                // Folgevertrag bereits abgeschlossen?
                'bound_until'          => count($chain) > 1 ? ($last['end'] ?? null) : null,
                'follow_up'            => count($chain) > 1 ? [
                    'label' => $this->labelFor($last),
                    'start' => $last['start'] ?? null,
                    'end'   => $last['end'] ?? null,
                ] : null,
                'chain_length'         => count($chain),
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
            'dynamic_missing_market' => $dynamicNoData,   // v3.1.0 (H6)
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
    private function seasonalExpectation(string $utility, array $meter, string $today): ?array
    {
        // v3.12.0 — die Prognose am Stichtag des Vergleichs (bis v3.11 am echten Datum)
        $fc = $this->forecast->forMeter($utility, $meter, ['forecast_months' => self::WINDOW_MONTHS, 'today' => $today]);
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
            // v3.9.0 (F1032) — mit Rest-Abrechnung die Menge, die der Vertrag rechnet (ohne Subzähler mit eigenem Vertrag)
            if (!isset($byMonth[$mn])) $byMonth[$mn] = (float)($m['billed_kwh'] ?? $m[$valueField] ?? 0);
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
                // v2.8.0 (CALC-03) — Datenbasis offenlegen: kurze Historie,
                // fehlende Kalendermonate, Herkunft der Heizgradtage
                'warnings'          => $fc['warnings'] ?? [],
                'hdd_source'        => $fc['hdd_source'] ?? null,
                'annual_band'       => $fc['annual'] ?? null,
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
     * Die Bindungskette: der heute laufende Vertrag plus alles, was lückenlos
     * anschließt.
     *
     * Wer den Nachfolger schon abgeschlossen hat, ist bis zu dessen Ende
     * gebunden. Ein Wechsel ist dann erst danach möglich — und bis dahin gilt
     * nicht mehr der alte Tarif, sondern der neue. Ohne diese Unterscheidung
     * meldete das Modul auf Produktivdaten einen Wechseltermin, der längst
     * durch einen abgeschlossenen Folgevertrag belegt war, und rechnete die
     * Vergleichsbasis mit den Preisen des auslaufenden Vertrags.
     *
     * Eine Lücke von mehr als einem Tag beendet die Kette: Danach ist der
     * Nutzer frei, auch wenn später wieder ein Vertrag gepflegt ist.
     *
     * @param array<int,array<string,mixed>> $real
     * @return array<int,array<string,mixed>> chronologisch, leer wenn nichts greift
     */
    private function bindingChain(array $real, string $today): array
    {
        $sorted = $real;
        usort($sorted, fn($a, $b) => strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? '')));

        $chain = [];
        foreach ($sorted as $c) {
            $start = (string)($c['start'] ?? '');
            $end   = $c['end'] ?? null;

            if ($chain === []) {
                // Einstieg: der heute laufende Vertrag. Läuft heute keiner,
                // greift der nächste bereits abgeschlossene.
                $active = $start <= $today && (empty($end) || $end >= $today);
                if ($active || $start > $today) {
                    $chain[] = $c;
                    if (empty($end)) break;   // unbefristet — Kette endet hier
                }
                continue;
            }

            $prevEnd = $chain[count($chain) - 1]['end'] ?? null;
            if (empty($prevEnd)) break;       // unbefristet, kein Anschluss möglich

            $dayAfter = date('Y-m-d', strtotime($prevEnd . ' +1 day'));
            if ($start !== $dayAfter && $start !== $prevEnd) {
                break;                        // Lücke — die Bindung endet
            }
            $chain[] = $c;
            if (empty($end)) break;
        }

        return $chain;
    }

    /**
     * v3.12.0 — Kosten eines Vertrags (oder der Bindungskette) über die Monate eines Fensters: je Monat Menge ×
     * Arbeitspreis + Grundpreis, mit den Preisen, die in diesem Monat gelten. Eine Rechnung für Jahr 1 und Jahr 2.
     * Mit `$chain` gilt je Monat der Vertrag der Kette, der dann läuft, sonst ihr letzter (fortgeschrieben).
     * Null, wenn ein Monat keinen Arbeitspreis hat.
     *
     * @param array<int,array<string,mixed>> $window
     * @param array<int,array<string,mixed>>|null $chain
     * @return array{total:float,consumption:float,monthly:list<array<string,mixed>>,beyond:bool,used:array<string,bool>}|null
     */
    private function costOver(array $c, array $window, ?array $chain = null): ?array
    {
        $out = ['total' => 0.0, 'consumption' => 0.0, 'monthly' => [], 'beyond' => false, 'used' => []];
        foreach ($window as $m) {
            $first = sprintf('%04d-%02d-01', $m['year'], $m['month']);
            // v3.12.0 — tagesgenau wie Prognose und Rechnung: der Monat geteilt an den Preisstufen (und mit `$chain` an
            // den Vertragsgrenzen), die Menge gleich je Tag, Grundpreis tagesanteilig. Bis dahin galten Vertrag und
            // Preis des Monatsersten; eine Preisstufe zum 15. wirkte erst im Folgemonat.
            $wpDays = 0.0; $cost = 0.0; $k0 = null;
            foreach ($this->monthSegments($chain ?? [$c], $first, $chain !== null) as [$from, $days, $dim]) {
                $k = $chain !== null ? ($this->contracts->findActiveForDate($chain, $from) ?? $c) : $c;
                $k0 ??= $k;
                $out['used'][(string)$k['id']] = true;
                $p = $this->priceOnDay($k, 'working_prices', 'ct_per_kwh', $from);
                if ($p === null) return null;
                $b = $this->priceOnDay($k, 'base_prices', 'eur_per_month', $from) ?? 0.0;
                $cost += $m['consumption'] * $days / $dim * $p / 100.0 + $b * $days / $dim;
                $wpDays += $p * $days / $dim;
            }
            // Preis des Monats nach Tagen: Menge × Preis bleibt der Arbeitspreisanteil (costAtScale, Werkstatt)
            $wp = $wpDays;
            $out['total'] += $cost;
            $out['consumption'] += $m['consumption'];
            // Nach Ablauf der Preisgarantie ist der Preis eine Annahme. Der Wert wird trotzdem fortgeschrieben —
            // er wird nur markiert, damit die Oberfläche ihn als unsicher darstellen kann.
            $guarantee = ($k0 ?? $c)['price_guarantee_until'] ?? null;
            $beyond = $guarantee !== null && ($m['ym'] . '-01') > $guarantee;
            if ($beyond) $out['beyond'] = true;
            $out['monthly'][] = [
                'ym'               => $m['ym'],
                'consumption'      => round($m['consumption'], 1),
                'cost'             => round($cost, 2),
                'working_price_ct' => round($wp, 4),
                'beyond_guarantee' => $beyond,
            ];
        }
        return $out;
    }

    /**
     * Kosten der Bindungskette über das Fenster.
     *
     * Für jeden Monat gilt der Vertrag, der dann läuft. Reicht die Kette nicht
     * bis ans Fensterende — der Normalfall, denn das Fenster beginnt ja nach
     * ihrem Ablauf —, wird der letzte Vertrag fortgeschrieben. Das ist die
     * konservative Annahme „nichts tun": bestehende Preise laufen weiter.
     * v3.12.0 — Jahr 2 sind die Monate 13 bis 24 mit den Preisen, die dann gelten (`$window2`).
     *
     * @param array<int,array<string,mixed>> $chain
     * @param array<int,array<string,mixed>> $window
     * @param array<int,array<string,mixed>> $window2
     * @return array<string,mixed>
     */
    private function evaluateChain(array $chain, array $window, array $window2, bool $isReference): array
    {
        $last = $chain[count($chain) - 1];
        $y1 = $this->costOver($last, $window, $chain);
        $y2 = $y1 !== null ? $this->costOver($last, $window2, $chain) : null;
        if ($y1 === null || $y2 === null) {
            return [
                'contract_id'      => (string)$last['id'],
                'label'            => $this->labelFor($last),
                'is_shadow'        => false,
                'is_reference'     => $isReference,
                'computable'       => false,
                'note'             => $this->i18n->t('errors.tariff.noWorkingPrice'),
                'year1_eur'        => null,
                'year2_eur'        => null,
                'signup_bonus_eur' => null,
                'monthly'          => [],
            ];
        }
        $usedIds = $y1['used'];

        // Deckt das Fenster mehrere Verträge ab, muss das Label das sagen —
        // sonst steht dort ein Tarif, der nur einen Teil der Zahlen erklärt.
        $label = count($usedIds) > 1
            ? $this->i18n->tp('tariff.switch.contractChain', count($usedIds), [
                'first' => $this->labelFor($chain[0]),
                'count' => count($usedIds),
              ])
            : $this->labelFor($last);

        return [
            'contract_id'  => (string)$last['id'],
            'label'        => $label,
            'provider'     => (string)($last['provider'] ?? ''),
            'tariff_name'  => (string)($last['tariff_name'] ?? ''),
            'is_shadow'    => false,
            'is_reference' => $isReference,
            'computable'   => true,
            'note'         => null,

            'year1_eur'        => round($y1['total'], 2),
            'year2_eur'        => round($y2['total'], 2),
            'signup_bonus_eur' => null,

            'consumption'        => round($y1['consumption'], 1),
            'unit_cost_ct_year1' => $y1['consumption'] > 0 ? round($y1['total'] * 100.0 / $y1['consumption'], 3) : null,
            'unit_cost_ct_year2' => $y2['consumption'] > 0 ? round($y2['total'] * 100.0 / $y2['consumption'], 3) : null,

            'guarantee_until'          => $last['price_guarantee_until'] ?? null,
            'guarantee_ends_in_window' => $y1['beyond'],

            'chain_length'  => count($usedIds),
            'monthly'       => $y1['monthly'],
            'monthly_year2' => $y2['monthly'],   // v3.12.0 — die Monate 13 bis 24 (additiv)
        ];
    }

    /**
     * Kosten eines Kandidaten über das Fenster — einmal für das erste Jahr
     * (mit Neukundenbonus) und einmal dauerhaft (ohne).
     * v3.12.0 — dauerhaft heißt: die Monate 13 bis 24 mit den Preisen, die dann gelten. Bis v3.11 war es dasselbe
     * Fenster wie Jahr 1 ohne Bonus; eine Preisstaffel ab dem 13. Monat fiel weg, das Lockangebot stand vorn.
     *
     * @param array<int,array<string,mixed>> $window
     * @param array<int,array<string,mixed>> $window2
     * @return array<string,mixed>
     */
    private function evaluate(array $c, array $window, array $window2, string $unit, bool $isReference): array
    {
        $guaranteeUntil = $c['price_guarantee_until'] ?? null;
        $y1 = $this->costOver($c, $window);
        $y2 = $y1 !== null ? $this->costOver($c, $window2) : null;

        if ($y1 === null || $y2 === null) {
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

            // Jahr 1 mit Bonus, Jahr 2 ohne, mit den Preisen der Monate 13 bis 24 — beide über dieselbe Menge je Monat.
            'year1_eur'        => round($y1['total'] - $bonus, 2),
            'year2_eur'        => round($y2['total'], 2),
            'signup_bonus_eur' => $bonus > 0 ? round($bonus, 2) : null,

            'consumption'        => round($y1['consumption'], 1),
            'unit_cost_ct_year1' => $y1['consumption'] > 0 ? round(($y1['total'] - $bonus) * 100.0 / $y1['consumption'], 3) : null,
            'unit_cost_ct_year2' => $y2['consumption'] > 0 ? round($y2['total'] * 100.0 / $y2['consumption'], 3) : null,

            'guarantee_until'          => $guaranteeUntil,
            'guarantee_ends_in_window' => $y1['beyond'],

            'monthly'       => $y1['monthly'],
            'monthly_year2' => $y2['monthly'],   // v3.12.0 — die Monate 13 bis 24 (additiv)
        ];
    }

    /**
     * v3.6.0 (F1031) — Preis eines Angebots für einen Monat, nach derselben
     * Regel wie in der Wechselentscheidung (die Prognose-Werkstatt rechnet
     * „Wechsel zum Angebot“ damit, nicht mit einer zweiten Preislogik).
     */
    public function offerPrice(array $c, string $group, string $field, int $year, int $month): ?float
    {
        // v3.12.0 — der Preis des Monats nach Tagen (eine Stufe zum 15. zählt ab dem 15.), wie in costOver()
        $first = sprintf('%04d-%02d-01', $year, $month);
        $sum = 0.0;
        foreach ($this->monthSegments([$c], $first, false) as [$from, $days, $dim]) {
            $p = $this->priceOnDay($c, $group, $field, $from);
            if ($p === null) return null;
            $sum += $p * $days / $dim;
        }
        return $sum;
    }

    /**
     * v3.12.0 — Abschnitte eines Monats [Beginn, Tage, Tage des Monats], geteilt an jedem Stichtag der Arbeits- und
     * Grundpreise der Verträge und mit `$contractCuts` an ihrem Beginn und am Tag nach ihrem Ende (Bindungskette).
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return list<array{0:string,1:int,2:int}>
     */
    private function monthSegments(array $contracts, string $first, bool $contractCuts): array
    {
        $next = date('Y-m-d', (int)strtotime($first . ' +1 month'));
        $dim  = (int)date('t', (int)strtotime($first));
        $cuts = [$first => true];
        foreach ($contracts as $x) {
            $dates = [];
            foreach (['working_prices', 'base_prices'] as $g) {
                foreach ((array)($x[$g] ?? []) as $e) if (is_array($e)) $dates[] = (string)($e['from'] ?? '');
            }
            if ($contractCuts) {
                $dates[] = (string)($x['start'] ?? '');
                if (!empty($x['end']) && is_string($x['end'])) $dates[] = date('Y-m-d', (int)strtotime($x['end'] . ' +1 day'));
            }
            foreach ($dates as $d) if ($d > $first && $d < $next) $cuts[$d] = true;
        }
        $list = array_keys($cuts);
        sort($list);
        $list[] = $next;
        $out = [];
        for ($i = 0; $i < count($list) - 1; $i++) {
            $days = (int)round((strtotime($list[$i + 1] . ' 12:00') - strtotime($list[$i] . ' 12:00')) / 86400);
            if ($days > 0) $out[] = [(string)$list[$i], $days, $dim];
        }
        return $out;
    }

    /**
     * Arbeits- bzw. Grundpreis eines Kandidaten an einem Tag.
     *
     * Gilt an dem Tag ein gepflegter Preis, wird er genommen. Sonst greift
     * der früheste Eintrag: Ein eingetragenes Angebot beschreibt einen Tarif,
     * der ab dem Wechsel gelten soll — sein Startdatum liegt beim Anlegen
     * naturgemäß noch nicht auf dem Wechseltermin.
     * v3.12.0 — je Tag statt je Monat (bis dahin der Preis des Monatsersten).
     */
    private function priceOnDay(array $c, string $group, string $field, string $date): ?float
    {
        $entries = $c[$group] ?? [];
        if (!is_array($entries) || !$entries) return null;

        $sorted = array_values(array_filter($entries, 'is_array'));
        usort($sorted, fn($a, $b) => strcmp((string)($a['from'] ?? ''), (string)($b['from'] ?? '')));
        $v = null;
        foreach ($sorted as $e) {
            if (((string)($e['from'] ?? '9999')) <= $date) {
                if (isset($e[$field]) && is_numeric($e[$field])) $v = (float)$e[$field];
            } else break;
        }
        if ($v !== null) return $v;
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
    private function compareToReference(array $cand, ?array $reference, array $window, float $expected, bool $higherIsBetter = false): array
    {
        $cand['vs_reference_year1_eur'] = null;
        $cand['vs_reference_year2_eur'] = null;
        $cand['vs_reference_year2_pct'] = null;
        $cand['break_even_consumption'] = null;
        $cand['break_even_delta_pct']   = null;
        $cand['break_even_direction']   = null;   // v3.12.0 — above | below (additiv)
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
                // v3.12.0 — in welche Richtung das Angebot gewinnt: Steigt es je kWh langsamer als der Vertrag (bei
                // der Einspeisung: schneller), ist es oberhalb der Menge besser („lohnt ab“), sonst nur darunter
                // („lohnt bis“). Bisher stand die Menge immer unter „Lohnt ab“, auch bei einem höheren Arbeitspreis
                // mit niedrigerem Grundpreis, der nur bei wenig Verbrauch günstiger ist.
                $cand['break_even_direction'] = ($higherIsBetter ? $slopeC > $slopeR : $slopeC < $slopeR) ? 'above' : 'below';
            }
        }

        return $cand;
    }

    /**
     * Kosten des Kandidaten, wenn der Verbrauch jedes Monats mit `$scale`
     * skaliert wird. Bonus bleibt außen vor — verglichen wird der dauerhafte
     * Preis (Jahr 2). v3.12.0 — über die Monate von Jahr 2 (`monthly_year2`), wie `year2_eur`.
     *
     * @param array<int,array<string,mixed>> $window
     */
    private function costAtScale(array $cand, array $window, float $scale): float
    {
        $sum = 0.0;
        foreach ($cand['monthly_year2'] ?? $cand['monthly'] as $i => $m) {
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
            // gleiche Form wie die volle Antwort (Prüfung vor dem v2.10.0-Release)
            'higher_is_better'     => Utilities::exists($utility) && Utilities::isFeedIn($utility),
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
