<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;

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

    /** @return array<string,mixed> */
    public function forYear(int $year): array
    {
        $country = (string)$this->settings->get('country', 'DE');
        $scheme = Countries::co2Scheme($country);
        if ($scheme === null) {
            return ['supported' => false, 'scheme' => null, 'year' => $year, 'rows' => [], 'total' => null, 'per_m2' => null,
                    'price' => null, 'note' => $this->i18n->t('co2cost.notSupported')];
        }
        $price = self::priceForYear($this->settings, $year);
        $vat = Countries::CO2_VAT[$scheme] ?? 0.0;
        $rows = [];
        foreach (self::UTILITIES as $u) {
            if (!Utilities::exists($u)) continue;
            $row = $this->row($u, $year, $price['eur_t'], $vat);
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
        $area = $this->area($year);
        $perM2 = null;
        if ($total !== null && $area > 0) {
            $kg = round($total['emissions_kg'] / $area, 1);
            [$stage, $share] = self::stage($kg);
            $perM2 = ['kg' => $kg, 'area_m2' => $area, 'stage' => $stage, 'landlord_share_pct' => $share];
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

    /** @return array<string,mixed>|null */
    private function row(string $u, int $year, ?float $priceEurT, float $vat): ?array
    {
        $kwh = 0.0; $days = [];
        foreach ($this->meters->list($u) as $meter) {
            if (!MeterService::countsInTotals($meter)) continue;
            if ($u === 'waerme' && Utilities::roleOf('waerme', $meter) !== 'consumption') continue;
            foreach ($this->consumption->forMeter($u, $meter) as $m) {
                if ((int)($m['year'] ?? 0) !== $year) continue;
                $kwh += (float)($m['kwh'] ?? 0);
                $days[(int)$m['month']] = max($days[(int)$m['month']] ?? 0, (int)($m['days'] ?? 0));
            }
        }
        $bill = $this->fromBills($u, $year);
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
        // Rechnungen weisen den CO₂-Preisbestandteil netto aus (CO2KostAufG § 3 Abs. 3)
        $net = $bill !== null && isset($bill['cost_eur']) ? (float)$bill['cost_eur'] : $emissions / 1000 * $priceEurT;
        $gross = $net * (1 + $vat);
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
            'approx'            => $u === 'waerme' && $source === 'computed',
            'coverage_days'     => array_sum($days),
        ];
    }

    /** Emissionen und Kosten laut Versorgerrechnungen, deren Zeitraum im Jahr endet (H5). */
    private function fromBills(string $u, int $year): ?array
    {
        $bills = $this->store->read("$u/bills.json", []);
        $em = 0.0; $cost = 0.0; $haveCost = true; $found = false;
        foreach (is_array($bills) ? $bills : [] as $b) {
            if (!is_array($b) || (int)substr((string)($b['period_to'] ?? ''), 0, 4) !== $year) continue;
            if (!isset($b['co2']['emissions_kg']) || !is_numeric($b['co2']['emissions_kg'])) continue;
            $found = true;
            $em += (float)$b['co2']['emissions_kg'];
            if (isset($b['co2']['cost_eur']) && is_numeric($b['co2']['cost_eur'])) $cost += (float)$b['co2']['cost_eur'];
            else $haveCost = false;
        }
        if (!$found) return null;
        return ['emissions_kg' => $em] + ($haveCost ? ['cost_eur' => $cost] : []);
    }

    /**
     * Frist für die Erstattung bei Etagenheizung (CO2KostAufG § 6 Abs. 2): zwölf
     * Monate nach Zugang der Gasrechnung, deren Zeitraum im Jahr endet. Ohne
     * Rechnungsdatum gilt der Tag nach dem Zeitraum. Null ohne Rechnung (H5).
     */
    public function claimDeadline(int $year): ?string
    {
        $latest = null;
        foreach ((array)$this->store->read('gas/bills.json', []) as $b) {
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

    /** Wohnfläche: aus dem Mietverhältnis des Jahres, sonst die Einstellung. */
    private function area(int $year): float
    {
        if ($this->tenancies !== null && $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete') {
            foreach ($this->tenancies->list() as $t) {
                if ((string)$t['start'] > "$year-12-31" || (!empty($t['end']) && (string)$t['end'] < "$year-01-01")) continue;
                if (!empty($t['wohnflaeche_m2'])) return (float)$t['wohnflaeche_m2'];
            }
        }
        return (float)$this->settings->get('wohnflaeche_m2', 0);
    }
}
