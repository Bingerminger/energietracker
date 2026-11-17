<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;

/**
 * v3.1.0 (Paket H4, B4/CALC-27) — Der CO₂-Preis im Brennstoff.
 *
 * Seit 2021 steckt im Preis für Gas und Heizöl der CO₂-Preis des
 * Brennstoffemissionshandels (BEHG). Dieser Dienst weist ihn aus: je
 * Verbrauchsart und Jahr die Emissionen nach den Standardfaktoren des BEHG
 * (nicht den BAFA-Faktoren der CO₂-Bilanz mit Vorkette), der maßgebliche Preis
 * in €/t und daraus der Betrag — netto und mit Umsatzsteuer. Das ist ein Ausweis,
 * kein Aufschlag: Der Betrag steckt schon im Arbeitspreis.
 *
 * Quellen der Emissionen, in dieser Reihenfolge:
 *   bill      die Versorgerrechnung (`<art>/bills.json`, Feld co2), Paket H5
 *   contract  der Netz-Emissionsfaktor aus dem Fernwärmevertrag (co2_g_per_kwh, CALC-31)
 *   computed  Verbrauch × Standardfaktor (Gas je kWh Brennwert, Heizöl je kWh Heizwert)
 * Fernwärme ohne Angabe des Versorgers erscheint nicht (es gibt keinen
 * Standardfaktor), Pellets und Strom nie (nicht im BEHG). Heizwärme rechnet mit
 * dem Faktor ihres Energieträgers als Näherung (`approx`).
 *
 * Dazu die Stufe nach CO2KostAufG (Anlage zu §§ 5–7): kg CO₂ je m² Wohnfläche
 * und Jahr, auf eine Nachkommastelle gerundet, zehn Stufen; Grundlage der
 * Aufteilung zwischen Mieter und Vermieter (Co2SplitService).
 *
 * Nur in Ländern mit Schema (`Countries::co2Scheme`, heute DE: behg).
 */
final class Co2CostService
{
    /** Obergrenze (exklusiv) in kg CO₂/(m²·a) → Anteil des Vermieters in %. Anlage CO2KostAufG. */
    public const CO2KOSTAUFG_STAGES = [
        [12.0, 0], [17.0, 10], [22.0, 20], [27.0, 30], [32.0, 40],
        [37.0, 50], [42.0, 60], [47.0, 70], [52.0, 80], [INF, 95],
    ];

    /** Verbrauchsarten, die einen CO₂-Preis tragen können. */
    public const UTILITIES = ['gas', 'heizoel', 'fernwaerme', 'waerme'];

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private MeterService $meters,
        private ConsumptionService $consumption,
        private ContractService $contracts,
        private I18nService $i18n,
        private ?TenancyService $tenancies = null,
    ) {}

    /**
     * @param array{from:string,to:string,days:int}|null $window v3.13.0 — nur die Tage [from, to] des Jahres (Mietzeitraum,
     *        periodOf()); null oder das ganze Jahr: wie bisher
     * @return array<string,mixed>
     */
    public function forYear(int $year, ?array $window = null): array
    {
        $country = (string)$this->settings->get('country', 'DE');
        $scheme = Countries::co2Scheme($country);
        if ($scheme === null) {
            return ['supported' => false, 'scheme' => null, 'year' => $year, 'rows' => [], 'total' => null, 'per_m2' => null,
                    'price' => null, 'note' => $this->i18n->t('co2cost.notSupported')];
        }
        if ($window !== null && self::isWholeYear($window, $year)) $window = null;
        $price = self::priceForYear($this->settings, $year);
        $vat = Countries::CO2_VAT[$scheme] ?? 0.0;
        $rows = [];
        foreach (self::UTILITIES as $u) {
            if (!Utilities::exists($u)) continue;
            $row = $this->row($u, $year, $price['eur_t'], $scheme, $window);
            if ($row !== null) $rows[] = $row;
        }
        $total = null;
        if ($rows !== []) {
            $total = [
                'emissions_kg'   => round(array_sum(array_column($rows, 'emissions_kg')), 1),
                'cost_eur_net'   => round(array_sum(array_column($rows, 'cost_eur_net')), 2),
                'cost_eur_gross' => round(array_sum(array_column($rows, 'cost_eur_gross')), 2),
            ];
        }
        [$area, $assumed] = $this->area($year);
        $perM2 = null;
        if ($total !== null && $area > 0) {
            // v3.12.0 — Teiljahr (Einzug oder Auszug im Jahr): Grenzen anteilig gekürzt wie in der Aufteilung.
            // v3.13.0 — und die Emissionen über dieselben Tage (§ 5 Abs. 1 Satz 5 CO2KostAufG): Die Kennzahl je m² rechnet
            // mit dem Ausstoß im Mietzeitraum, wie die Aufteilung. Bis v3.12 stand der Ausstoß des ganzen Jahres gegen
            // die gekürzten Grenzen. Zeilen und Summe dieser Seite bleiben das ganze Jahr.
            $period = self::periodOf($this->tenancyOf($year), $year);
            $emissions = $window === null && !self::isWholeYear($period, $year)
                ? (float)($this->forYear($year, $period)['total']['emissions_kg'] ?? 0.0) : (float)$total['emissions_kg'];
            $kg = round($emissions / $area, 1);
            $days = $period['days'];
            [$stage, $share] = self::stage($kg, $days);
            // v3.6.0 — area_assumed: weder im Mietverhältnis noch in den Einstellungen eingetragen
            $perM2 = ['kg' => $kg, 'area_m2' => $area, 'area_assumed' => $assumed, 'stage' => $stage, 'landlord_share_pct' => $share, 'days' => $days];
            // v3.13.0 (additiv) — im Teiljahr: der Ausstoß im Mietzeitraum, aus dem `kg` stammt
            if (!self::isWholeYear($period, $year)) $perM2 += ['emissions_kg' => round($emissions, 1), 'from' => $period['from'], 'to' => $period['to']];
        }
        return ['supported' => true, 'scheme' => $scheme, 'year' => $year, 'rows' => $rows, 'total' => $total,
                'per_m2' => $perM2, 'price' => $price, 'vat' => $vat, 'note' => null];
    }

    /**
     * Stufe (1–10) und Vermieteranteil in % für kg CO₂ je m² und Jahr. Der Wert
     * wird vorher auf eine Nachkommastelle gerundet; ist der Zeitraum kürzer als
     * ein Jahr, werden die Grenzen anteilig gekürzt (§ 5 Abs. 1).
     *
     * @return array{0:int, 1:int}
     */
    public static function stage(float $kgPerM2, int $days = 365): array
    {
        $kg = round($kgPerM2, 1);
        $scale = $days > 0 && $days < 365 ? $days / 365 : 1.0;
        foreach (self::CO2KOSTAUFG_STAGES as $i => [$upper, $share]) {
            if ($kg < $upper * $scale) return [$i + 1, $share];
        }
        return [10, 95];
    }

    /**
     * v3.12.0 — Tage des Jahres, über die die Stufe gilt: bei Miete die Tage des Mietverhältnisses im Kalenderjahr
     * (Einzug bzw. Auszug im Jahr ergibt ein Teiljahr, § 5 Abs. 3 Satz 4 mit Abs. 1 CO2KostAufG: Grenzen anteilig
     * kürzen), sonst das ganze Jahr. Eine Regel für die Kennzahl je m² und die Aufteilung (Co2SplitService).
     */
    public static function periodDays(?array $tenancy, int $year): int
    {
        return self::periodOf($tenancy, $year)['days'];
    }

    /**
     * v3.13.0 — Der Zeitraum des Jahres, über den Grenzen und Ausstoß gelten: bei Miete die Tage des Mietverhältnisses im
     * Kalenderjahr [from, to] (beide eingeschlossen), sonst das ganze Jahr. Eine Regel für die Grenzen (periodDays) und
     * die Emissionen und Kosten (forYear mit Zeitraum): § 5 Abs. 1 Satz 4 und 5, Abs. 3 Satz 4 CO2KostAufG.
     *
     * @return array{from:string, to:string, days:int}
     */
    public static function periodOf(?array $tenancy, int $year): array
    {
        $whole = ['from' => "$year-01-01", 'to' => "$year-12-31",
                  'days' => (new \DateTimeImmutable("$year-01-01"))->diff(new \DateTimeImmutable("$year-12-31"))->days + 1];
        if ($tenancy === null) return $whole;
        $from = max("$year-01-01", (string)($tenancy['start'] ?? "$year-01-01"));
        $to = min("$year-12-31", (string)(($tenancy['end'] ?? null) ?: "$year-12-31"));
        if ($to < $from) return $whole;
        return ['from' => $from, 'to' => $to, 'days' => min($whole['days'], (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1)];
    }

    /** @param array{from:string,to:string} $period */
    private static function isWholeYear(array $period, int $year): bool
    {
        return $period['from'] <= "$year-01-01" && $period['to'] >= "$year-12-31";
    }

    /** v3.12.0 — das Mietverhältnis eines Jahres wie für die Fläche (area()): nur bei Miete, sonst null. */
    private function tenancyOf(int $year): ?array
    {
        if ($this->tenancies === null || $this->settings->get('wohnverhaeltnis', 'eigentum') !== 'miete') return null;
        $any = null;
        foreach ($this->tenancies->list() as $t) {
            if ((string)$t['start'] > "$year-12-31" || (!empty($t['end']) && (string)$t['end'] < "$year-01-01")) continue;
            if (!empty($t['wohnflaeche_m2'])) return $t;
            $any ??= $t;
        }
        return $any;
    }

    /**
     * Maßgeblicher Preis eines Jahres: Einstellung co2_price_eur_t_years, sonst
     * das Länderprofil, sonst der letzte bekannte Wert als Annahme.
     *
     * @return array{eur_t: ?float, assumed: bool}
     */
    public static function priceForYear(SettingsService $settings, int $year): array
    {
        $own = (array)$settings->get('co2_price_eur_t_years', []);
        if (isset($own[$year]) && is_numeric($own[$year])) return ['eur_t' => (float)$own[$year], 'assumed' => false];
        $profile = (array)(Countries::get((string)$settings->get('country', 'DE'))['co2_price_years'] ?? []);
        $all = $own + $profile;
        if (isset($all[$year]) && is_numeric($all[$year])) return ['eur_t' => (float)$all[$year], 'assumed' => false];
        ksort($all);
        $best = null;
        foreach ($all as $y => $p) if ((int)$y < $year && is_numeric($p)) $best = (float)$p;
        return ['eur_t' => $best, 'assumed' => $best !== null];
    }

    /**
     * Standardfaktor einer Verbrauchsart in kg CO₂ je kWh, wie sie gerechnet
     * wird; Heizwärme über ihren Energieträger. Null = kein Standardfaktor.
     */
    public static function factorFor(SettingsService $settings, string $utility): ?float
    {
        if ($utility === 'waerme') {
            $src = (string)$settings->get('waerme_energietraeger', '');
            return Countries::CO2_BEHG_FACTORS[$src] ?? null;
        }
        return Countries::CO2_BEHG_FACTORS[$utility] ?? null;
    }

    /**
     * v3.1.0 (MKT-26) — Mehrkosten je kWh, wenn der CO₂-Preis `$scenario` statt
     * des Preises von `$year` gälte, in ct (mit Umsatzsteuer). Null ohne
     * Faktor oder ohne Schema.
     */
    public static function scenarioDeltaCtPerKwh(SettingsService $settings, string $utility, float $scenario, int $year): ?float
    {
        $scheme = Countries::co2Scheme((string)$settings->get('country', 'DE'));
        $factor = self::factorFor($settings, $utility);
        $price = self::priceForYear($settings, $year)['eur_t'];
        if ($scheme === null || $factor === null || $price === null) return null;
        return ($scenario - $price) * $factor / 10 * (1 + (Countries::CO2_VAT[$scheme] ?? 0.0));
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * @param array{from:string,to:string,days:int}|null $window v3.13.0 — nur diese Tage des Jahres (Mietzeitraum): Menge
     *        tagesgenau aus denselben Tagesabschnitten wie die Monatszeilen (ConsumptionService::amountBetween), Rechnungen
     *        anteilig (fromBills)
     * @return array<string,mixed>|null
     */
    private function row(string $u, int $year, ?float $priceEurT, string $scheme, ?array $window = null): ?array
    {
        $kwh = 0.0; $days = []; $kwhByMonth = [];
        foreach ($this->meters->list($u) as $meter) {
            if (!MeterService::countsInTotals($meter)) continue;
            if ($u === 'waerme' && Utilities::roleOf('waerme', $meter) !== 'consumption') continue;
            if ($window !== null) {
                // je Monat der Teil im Zeitraum [from, to]
                for ($mo = 1; $mo <= 12; $mo++) {
                    $a = max(sprintf('%04d-%02d-01', $year, $mo), $window['from']);
                    $last = date('Y-m-t', (int)strtotime(sprintf('%04d-%02d-01', $year, $mo)));
                    $b = min($last, $window['to']);
                    if ($b < $a) continue;
                    $part = $this->consumption->amountBetween($u, $meter, $a, date('Y-m-d', (int)strtotime("$b +1 day")));
                    $covered = Rights::daysBetween($a, $b) + 1 - $part['open'];
                    if ($covered <= 0) continue;
                    $kwh += $part['kwh'];
                    $kwhByMonth[$mo] = ($kwhByMonth[$mo] ?? 0.0) + $part['kwh'];
                    $days[$mo] = max($days[$mo] ?? 0, $covered);
                }
                continue;
            }
            foreach ($this->consumption->forMeter($u, $meter) as $m) {
                if ((int)($m['year'] ?? 0) !== $year) continue;
                $kwh += (float)($m['kwh'] ?? 0);
                $kwhByMonth[(int)$m['month']] = ($kwhByMonth[(int)$m['month']] ?? 0.0) + (float)($m['kwh'] ?? 0);
                $days[(int)$m['month']] = max($days[(int)$m['month']] ?? 0, (int)($m['days'] ?? 0));
            }
        }
        // v3.2.0 — Umsatzsteuer je Monat (7 % auf Gas und Fernwärme 10/2022–3/2024),
        // für das Jahr nach Verbrauch gewichtet; ohne Verbrauch das Mittel der Monate
        // Heizwärme: der Satz des Energieträgers dahinter (Gas, Fernwärme …)
        $vatUtility = $u === 'waerme' ? (string)($this->settings->get('waerme_energietraeger') ?? '') : $u;
        $vatOf = fn(int $m) => Countries::co2Vat($scheme, $vatUtility, sprintf('%04d-%02d', $year, $m));
        $vat = $kwh > 0
            ? array_sum(array_map(fn($m, $k) => $vatOf((int)$m) * $k, array_keys($kwhByMonth), $kwhByMonth)) / $kwh
            : array_sum(array_map($vatOf, range(1, 12))) / 12;
        $bill = $this->fromBills($u, $year, $window);
        $source = 'computed';
        $factor = null;
        if ($bill !== null) {
            $emissions = $bill['emissions_kg'];
            $source = 'bill';
        } else {
            $factor = $u === 'fernwaerme' ? $this->contractFactor($year) : self::factorFor($this->settings, $u);
            if ($factor === null || $kwh <= 0) return null;
            $emissions = $kwh * $factor;
            if ($u === 'fernwaerme') $source = 'contract';
        }
        if ($emissions <= 0 || $priceEurT === null) return null;
        // v3.2.0 — Die Rechnung weist den CO₂-Preisbestandteil „zuzüglich einer
        // auf diesen Betrag anfallenden Umsatzsteuer“ aus (CO2KostAufG § 3 Abs. 3),
        // also brutto. Bis v3.1 wurde er als netto gelesen und die Steuer ein
        // zweites Mal aufgeschlagen.
        if ($bill !== null && isset($bill['cost_eur'])) {
            $gross = (float)$bill['cost_eur'];
            $net = $gross / (1 + $vat);
        } else {
            $net = $emissions / 1000 * $priceEurT;
            $gross = $net * (1 + $vat);
        }
        return [
            'utility'           => $u,
            'kwh'               => round($kwh, 1),
            'factor_kg_per_kwh' => $factor !== null ? round($factor, 5) : ($kwh > 0 ? round($emissions / $kwh, 5) : null),
            'emissions_kg'      => round($emissions, 1),
            'price_eur_t'       => $priceEurT,
            'cost_eur_net'      => round($net, 2),
            'cost_eur_gross'    => round($gross, 2),
            'ct_per_kwh'        => $kwh > 0 ? round($gross / $kwh * 100, 3) : null,
            'source'            => $source,
            'vat'               => round($vat, 4),   // v3.2.0 — angewandte Umsatzsteuer (7 % für Gas/Fernwärme 10/2022–3/2024)
            'approx'            => $u === 'waerme' && $source === 'computed',
            'coverage_days'     => array_sum($days),
        ];
    }

    /**
     * Emissionen und Kosten laut Versorgerrechnungen, deren Zeitraum im Jahr endet (H5).
     * v3.13.0 — mit Zeitraum (Mietzeitraum): je Rechnung nur der Teil in [from, to] (§ 5 Abs. 1 Satz 5 CO2KostAufG: die
     * ausgewiesenen Emissionen auf den Zeitraum umrechnen), nach der Menge des Zählers in beiden Zeiträumen; ohne Menge
     * nach Tagen. Eine Rechnung ganz im Zeitraum zählt ganz, eine ganz außerhalb nicht.
     */
    private function fromBills(string $u, int $year, ?array $window = null): ?array
    {
        $bills = \Energietracker\Support\FieldRules::view($this->store, "$u/bills.json", 'bills')[0];   // v3.13.0 (K2d) — Sicht der Rechnung
        $em = 0.0; $cost = 0.0; $haveCost = true; $found = false;
        foreach (is_array($bills) ? $bills : [] as $b) {
            if (!is_array($b) || (int)substr((string)($b['period_to'] ?? ''), 0, 4) !== $year) continue;
            if (!isset($b['co2']['emissions_kg']) || !is_numeric($b['co2']['emissions_kg'])) continue;
            $share = $window === null ? 1.0 : $this->billShare($u, $b, $window);
            if ($share <= 0.0) continue;
            $found = true;
            $em += (float)$b['co2']['emissions_kg'] * $share;
            if (isset($b['co2']['cost_eur']) && is_numeric($b['co2']['cost_eur'])) $cost += (float)$b['co2']['cost_eur'] * $share;
            else $haveCost = false;
        }
        if (!$found) return null;
        return ['emissions_kg' => $em] + ($haveCost ? ['cost_eur' => $cost] : []);
    }

    /**
     * v3.13.0 — Anteil einer Rechnung am Zeitraum [from, to]: Menge des Zählers im gemeinsamen Teil ÷ Menge im ganzen
     * Rechnungszeitraum; ohne Menge (keine Ablesungen) nach Kalendertagen.
     *
     * @param array<string,mixed> $b
     * @param array{from:string,to:string} $window
     */
    private function billShare(string $u, array $b, array $window): float
    {
        $bf = (string)($b['period_from'] ?? ''); $bt = (string)($b['period_to'] ?? '');
        if (!Dates::isIsoDate($bf) || !Dates::isIsoDate($bt) || $bt < $bf) return 0.0;
        $a = max($bf, $window['from']); $z = min($bt, $window['to']);
        if ($z < $a) return 0.0;
        if ($a === $bf && $z === $bt) return 1.0;
        $meter = $this->meters->get($u, (string)($b['meter_id'] ?? ''));
        if ($meter !== null) {
            $next = fn(string $d) => date('Y-m-d', (int)strtotime("$d +1 day"));
            $all = $this->consumption->amountBetween($u, $meter, $bf, $next($bt));
            $part = $this->consumption->amountBetween($u, $meter, $a, $next($z));
            if ($all['open'] === 0 && $all['kwh'] > 0) return $part['kwh'] / $all['kwh'];
        }
        return (Rights::daysBetween($a, $z) + 1) / (Rights::daysBetween($bf, $bt) + 1);
    }

    /**
     * Frist für die Erstattung bei Etagenheizung (CO2KostAufG § 6 Abs. 2): zwölf
     * Monate, nachdem der Lieferant abgerechnet hat (Rechnungsdatum der
     * Gasrechnung, deren Zeitraum im Jahr endet). Ohne
     * Rechnungsdatum gilt der Tag nach dem Zeitraum. Null ohne Rechnung (H5).
     */
    public function claimDeadline(int $year): ?string
    {
        $latest = null;
        // v3.13.0 (K2d) — Sicht der Rechnung: ein Rechnungsdatum als Liste aus einer älteren Version brach bisher die Frist (500)
        foreach (\Energietracker\Support\FieldRules::view($this->store, 'gas/bills.json', 'bills')[0] as $b) {
            if (!is_array($b) || (int)substr((string)($b['period_to'] ?? ''), 0, 4) !== $year) continue;
            $got = (string)($b['issued_on'] ?? '') ?: date('Y-m-d', (int)strtotime((string)$b['period_to'] . ' +1 day'));
            if ($latest === null || $got > $latest) $latest = $got;
        }
        return $latest === null ? null : date('Y-m-d', (int)strtotime("$latest +1 year"));
    }

    /** Netz-Emissionsfaktor aus einem Fernwärmevertrag, der im Jahr gilt (CALC-31, H5). */
    private function contractFactor(int $year): ?float
    {
        foreach ($this->meters->list('fernwaerme') as $meter) {
            foreach ($this->contracts->list('fernwaerme', (string)$meter['id']) as $c) {
                if (!empty($c['is_shadow']) || !isset($c['co2_g_per_kwh']) || !is_numeric($c['co2_g_per_kwh'])) continue;
                if ((string)($c['start'] ?? '') > "$year-12-31") continue;
                if (!empty($c['end']) && (string)$c['end'] < "$year-01-01") continue;
                return (float)$c['co2_g_per_kwh'] / 1000;
            }
        }
        return null;
    }

    /**
     * Wohnfläche: aus dem Mietverhältnis des Jahres, sonst die Einstellung.
     * v3.6.0 — dazu, ob sie nur angenommen ist (Default, nirgends eingetragen).
     *
     * @return array{0:float, 1:bool}
     */
    private function area(int $year): array
    {
        if ($this->tenancies !== null && $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete') {
            foreach ($this->tenancies->list() as $t) {
                if ((string)$t['start'] > "$year-12-31" || (!empty($t['end']) && (string)$t['end'] < "$year-01-01")) continue;
                if (!empty($t['wohnflaeche_m2'])) return [(float)$t['wohnflaeche_m2'], false];
            }
        }
        $area = (float)$this->settings->get('wohnflaeche_m2', 0);
        return [$area, $area > 0 && !$this->settings->isEntered('wohnflaeche_m2')];
    }
}
