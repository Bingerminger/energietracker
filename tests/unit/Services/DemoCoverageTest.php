<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DemoDataAligner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.6.0 (Paket abrechnung) — Die Beispieldaten decken alle Anwendungsfälle ab.
 *
 * Je Anwendungsfall (Feature-Code aus roadmap.md bzw. dem Handbuch) eine
 * Prüfung auf den Daten eines Haushalts; jeder Fall muss in mindestens einem
 * der vier Beispielhaushalte oder im Schaufenster vorkommen. Fälle, die vom
 * Tag abhängen (Stichtag relativ zu heute, gewanderte Rechnungen), prüft der
 * Test auf den ausgerichteten Daten (DemoDataAligner) an mehreren Tagen.
 *
 * Die Matrix (Fall × Haushalt) liefert `matrix()` aus derselben Liste — auch
 * für die Übersicht im Plan des Release.
 */
final class DemoCoverageTest extends TestCase
{
    public const HOUSEHOLDS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern', 'showcase'];

    /** Tage für die ausgerichteten Fälle — wie PersonaUseCasesTest. */
    public const TODAYS = ['2026-10-13', '2027-03-01', '2027-11-20'];

    private static array $cache = [];

    public static function load(string $id): array
    {
        $root = dirname(__DIR__, 3);
        $file = $id === 'showcase' ? "$root/demo-data/energietracker-demo-backup.json" : "$root/demo-data/personas/$id.json";
        return self::$cache[$id] ??= json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── Bausteine ───────────────────────────────────────────────────────

    /** @return iterable<array{0:string,1:array}> Art → Topf */
    private static function pots(array $p, string $pot): iterable
    {
        foreach ($p['utilities'] ?? [] as $u => $b) foreach ($b[$pot] ?? [] as $x) yield [$u, $x];
    }

    private static function anyPot(array $p, string $pot, callable $fn): bool
    {
        foreach (self::pots($p, $pot) as [$u, $x]) if ($fn($x, $u)) return true;
        return false;
    }

    private static function realContract(array $c): bool
    {
        return empty($c['is_shadow']);
    }

    private static function spKind(array $p, callable $kind): bool
    {
        return self::anyPot($p, 'contracts', fn($c) => array_filter($c['special_payments'] ?? [], fn($s) => $kind((string)($s['kind'] ?? ''))) !== []);
    }

    private static function addYear(string $d): string
    {
        return DemoDataAligner::addYears($d, 1);
    }

    private static function dayAfter(string $d): string
    {
        return date('Y-m-d', (int)strtotime("$d +1 day"));
    }

    /**
     * Anwendungsfälle: ID → [Code, Fall, Prüfung(roh, ausgerichtet je Tag)].
     *
     * @return array<string,array{0:string,1:string,2:callable(array,list<array>):bool}>
     */
    public static function cases(): array
    {
        $raw = fn(callable $fn) => fn(array $p, array $aligned) => $fn($p);
        $anyDay = fn(callable $fn) => fn(array $p, array $aligned) => array_filter($aligned, $fn) !== [];
        return [
            // Verträge und Geld
            'F1003-rueck'   => ['F1003', 'Sonderzahlung: Rückzahlung', $raw(fn($p) => self::spKind($p, fn($k) => str_starts_with($k, 'rueckzahlung')))],
            'F1003-nach'    => ['F1003', 'Sonderzahlung: Nachzahlung', $raw(fn($p) => self::spKind($p, fn($k) => str_starts_with($k, 'nachzahlung')))],
            'F1003-mit'     => ['F1003', 'Sonderzahlung mit neuem Abschlag („mit Auswirkung“)', $raw(fn($p) => self::spKind($p, fn($k) => str_ends_with($k, '_mit')))],
            'F1003-extra'   => ['F1003', 'Zusätzliche Abschlagszahlung (zählt im Saldo der Periode)', $raw(fn($p) => self::spKind($p, fn($k) => $k === 'abschlagszahlung'))],
            '#34-water'     => ['#34', 'Sonderzahlung am Wasservertrag', $raw(fn($p) => array_filter($p['utilities']['wasser']['contracts'] ?? [], fn($c) => !empty($c['special_payments'])) !== [])],
            'F1014-renews'  => ['F1014', 'Vertrag läuft ohne Kündigung weiter (Laufzeit vorbei, kein Folgevertrag)', $anyDay(fn($a) => self::anyPot($a['p'], 'contracts', function ($c, $u) use ($a) {
                if (!self::realContract($c) || empty($c['end']) || $c['end'] >= $a['today'] || !ContractService::renews($c)) return false;
                $peers = array_values(array_filter($a['p']['utilities'][$u]['contracts'], fn($o) => self::realContract($o) && ($o['meter_id'] ?? null) === ($c['meter_id'] ?? null)));
                return ContractService::successorOf($c, $peers, true) === null;
            }))],
            'F1014-open'    => ['F1014', 'Unbefristeter Vertrag', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c, $u) => self::realContract($c) && empty($c['end']) && $u !== 'pv_einspeisung'))],
            'F1014-notice'  => ['F1014', 'Kündigungsfrist gepflegt', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => self::realContract($c) && (($c['notice_period_months'] ?? null) !== null || ($c['notice_period_days'] ?? null) !== null)))],
            'F1014-price'   => ['F1014', 'Preisänderung im laufenden Vertrag', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => self::realContract($c) && count($c['working_prices'] ?? []) >= 2))],
            'F1014-retired' => ['F1014', 'Zähler außer Betrieb mit seiner Historie', $raw(fn($p) => self::anyPot($p, 'meters', fn($m, $u) => ($m['active'] ?? true) === false
                && array_filter($p['utilities'][$u]['readings'] ?? [], fn($r) => $r['meter_id'] === $m['id']) !== []))],
            'F1024-cancel'  => ['F1024', 'Gekündigt — verlängert sich nicht', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => self::realContract($c) && ($c['auto_renews'] ?? null) === false))],
            'F1024-succ'    => ['F1024', 'Lückenloser Folgevertrag', $raw(fn($p) => self::anyPot($p, 'contracts', function ($c, $u) use ($p) {
                if (!self::realContract($c) || empty($c['end'])) return false;
                $peers = array_values(array_filter($p['utilities'][$u]['contracts'], fn($o) => self::realContract($o) && ($o['meter_id'] ?? null) === ($c['meter_id'] ?? null)));
                $s = ContractService::successorOf($c, $peers, false);
                return $s !== null && $s['gap_days'] === 0;
            }))],
            'v2.3-shadow'   => ['v2.3.0', 'Angebot als Schattenvertrag (Tarifvergleich)', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['is_shadow'])))],
            'v2.3-bonus'    => ['v2.3.0', 'Bonus (Neukunde, Wechsel)', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['bonuses'])))],
            'v2.3-guarantee'=> ['v2.3.0', 'Preisgarantie', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['price_guarantee_until'])))],
            // Rechnungen und Abrechnungsjahr
            'H5-bill'       => ['F1029', 'Versorgerrechnung erfasst', $raw(fn($p) => self::anyPot($p, 'bills', fn() => true))],
            'H5-final'      => ['F1029', 'Schlussrechnung zum Vertragsende', $raw(fn($p) => self::anyPot($p, 'bills', fn($b) => ($b['kind'] ?? '') === 'final'))],
            'H5-booked'     => ['F1030', 'Rechnung als Rück- oder Nachzahlung gebucht', $raw(fn($p) => self::anyPot($p, 'bills', fn($b) => !empty($b['special_payment_id'])))],
            'H5-unbooked'   => ['F1030', 'Rechnung erfasst, Ergebnis noch nicht gebucht', $raw(fn($p) => self::anyPot($p, 'bills', fn($b) => empty($b['special_payment_id'])))],
            'H5-items'      => ['F1029', 'Rechnung mit Posten, die die App nicht nachrechnet', $raw(fn($p) => self::anyPot($p, 'bills', fn($b) => !empty($b['items'])))],
            'H5-group'      => ['F1017', 'Rechnung für eine Zählergruppe (Gruppenvertrag)', $raw(fn($p) => self::anyPot($p, 'bills', function ($b, $u) use ($p) {
                $c = array_column($p['utilities'][$u]['contracts'], null, 'id')[$b['contract_id'] ?? ''] ?? null;
                return $c !== null && !empty($c['meter_group_id']);
            }))],
            'F1029-open'    => ['F1029', 'Abrechnung erwartet: beendete Periode ohne Rechnung', $anyDay(fn($a) => self::openPeriod($a['p'], $a['today']))],
            'F1030-anchor'  => ['F1030', 'Abrechnungsstichtag nicht am 1. Januar', $anyDay(fn($a) => array_filter($a['p']['settings'], fn($v, $k) => str_starts_with((string)$k, 'billing_cycle_anchor_') && $v !== '01-01', ARRAY_FILTER_USE_BOTH) !== [])],
            'F1030-midmonth'=> ['F1030', 'Stichtag mitten im Monat', $anyDay(fn($a) => array_filter($a['p']['settings'], fn($v, $k) => str_starts_with((string)$k, 'billing_cycle_anchor_') && substr((string)$v, 3) !== '01', ARRAY_FILTER_USE_BOTH) !== [])],
            'F1030-irregular' => ['F1030', 'Abrechnungsperiode kürzer oder länger als ein Jahr (wie auf der Rechnung)', $anyDay(fn($a) => self::anyPot($a['p'], 'bills',
                fn($b) => self::addYear((string)$b['period_from']) !== self::dayAfter((string)$b['period_to'])))],
            // v3.6.0 (abrplus) — die Periode endet am Lieferantenwechsel, ohne Schlussrechnung
            'F1030-switch'  => ['F1030', 'Lieferantenwechsel ohne erfasste Schlussrechnung (Periode endet am Vertragsende)', $raw(fn($p) => self::anyPot($p, 'contracts', function ($c, $u) use ($p) {
                if (!self::realContract($c) || !Utilities::supportsBillCheck($u)) return false;
                $peers = array_values(array_filter($p['utilities'][$u]['contracts'], fn($o) => self::realContract($o)
                    && ($o['meter_id'] ?? null) === ($c['meter_id'] ?? null) && ($o['meter_group_id'] ?? null) === ($c['meter_group_id'] ?? null)));
                $s = ContractService::supplierSwitchAfter($c, $peers);
                return $s !== null && array_filter($p['utilities'][$u]['bills'] ?? [], fn($b) => (string)$b['period_to'] === $s['until']) === [];
            }))],
            'F1030-window'  => ['F1030', 'Vertrag läuft über eine gebuchte Rechnung hinaus (Saldo ab dem Tag danach)', $raw(fn($p) => self::anyPot($p, 'bills', function ($b, $u) use ($p) {
                if (empty($b['special_payment_id'])) return false;
                $peers = array_values(array_filter($p['utilities'][$u]['contracts'], fn($o) => self::realContract($o)));
                foreach ($peers as $c) {
                    if (($c['id'] ?? null) !== ($b['contract_id'] ?? null) || (string)$c['start'] >= (string)$b['period_to']) continue;
                    // läuft weiter: unbefristet, endet später oder verlängert sich ohne Folgevertrag
                    if (empty($c['end']) || (string)$c['end'] > (string)$b['period_to']
                        || (ContractService::renews($c) && ContractService::successorOf($c, $peers, true) === null)) return true;
                }
                return false;
            }))],
            // Zähler
            'F1006-sub'     => ['F1006', 'Subzähler (im Elternzähler enthalten)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => !empty($m['parent_meter_id'])))],
            'F1006-group'   => ['F1006', 'Zählergruppe mit Mitgliedern', $raw(fn($p) => self::anyPot($p, 'meter_groups', fn($g, $u) => self::anyPot(['utilities' => [$u => $p['utilities'][$u]]], 'meters', fn($m) => ($m['meter_group_id'] ?? null) === $g['id'])))],
            'F1017-contract'=> ['F1017', 'Vertrag für eine Zählergruppe', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['meter_group_id'])))],
            'F1017-htnt'    => ['F1017', 'Arbeitspreis je Mitglied (HT/NT)', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['working_prices_by_meter'])))],
            'F1017-14a'     => ['F1017', '§ 14a EnWG Modul 1 (reduziertes Netzentgelt)', $raw(fn($p) => self::anyPot($p, 'contracts', fn($c) => !empty($c['grid_reduction'])))],
            'F1009-alias'   => ['F1009', 'Home-Assistant-Alias am Zähler (`external_id`)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => !empty($m['external_id'])))],
            'F1011-baseline'=> ['F1011', 'Analyse-Zäsur (bauliche Maßnahme)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => !empty($m['baseline_events'])))],
            'F1012-factors' => ['F1012', 'Gas-Umrechnung mit Stichtagen', $raw(fn($p) => ($p['utilities']['gas']['meters'] ?? []) !== []
                && array_filter($p['settings']['gas_conversion_factors'] ?? [], fn($f) => !empty($f['from'])) !== [])],
            '#13-swap'      => ['#13', 'Zählertausch (zwei Geräte an einem Zähler)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => count($m['devices'] ?? []) >= 2))],
            'F1004-est'     => ['F1004', 'Geschätzte Ablesung', $raw(fn($p) => self::anyPot($p, 'readings', fn($r) => !empty($r['is_estimated'])))],
            'water-3'       => ['v1.0.3', 'Wasser: Trink-, Schmutz- und Niederschlagswasser', $raw(fn($p) => array_filter($p['utilities']['wasser']['contracts'] ?? [], fn($c) => !empty($c['niederschlagswasser']['rates'])) !== [])],
            'F1008-deduct'  => ['F1008', 'Schmutzwasser abzüglich Gartenzähler', $raw(fn($p) => array_filter($p['utilities']['wasser']['contracts'] ?? [], fn($c) => ($c['schmutzwasser']['basis'] ?? '') === 'trinkwasser_minus_abzug') !== [])],
            'F1008-warm'    => ['F1008', 'Warmwasserzähler', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'warm'))],
            // Energieträger und Technik
            'F1015-oil'     => ['F1015', 'Heizöl-Tank mit Lieferungen', $raw(fn($p) => ($p['utilities']['heizoel']['deliveries'] ?? []) !== [])],
            'F1015-pellets' => ['F1015', 'Pellets mit Lieferungen', $raw(fn($p) => ($p['utilities']['pellets']['deliveries'] ?? []) !== [])],
            'F1015-level'   => ['F1015', 'Peilstand oder Lieferung „bis voll“', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => !empty($m['tank_levels']))
                || self::anyPot($p, 'deliveries', fn($d) => !empty($d['fill_to_full'])))],
            'F1005-pv'      => ['F1005', 'PV-Einspeisung und -Erzeugung', $raw(fn($p) => ($p['utilities']['pv_einspeisung']['meters'] ?? []) !== [] && ($p['utilities']['pv_erzeugung']['meters'] ?? []) !== [])],
            'F1021-battery' => ['F1021', 'Speicher (Laden und Entladen)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'battery_charge')
                && self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'battery_discharge'))],
            'F1021-quarter' => ['F1021', 'Werte je Viertelstunde (Tagesfilm F1027)', $raw(fn($p) => ($p['intervals']['series'] ?? []) !== [])],
            'F1031-heatpump'=> ['F1031', 'Wärmepumpe mit Wärmemengenzähler (JAZ)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'heat_pump')
                && self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'heat_pump_output'))],
            'F1031-heat'    => ['F1031', 'Heizart mit Heizmodell (Werkstatt: Winter, Dämmung)', $raw(fn($p) => ($p['utilities']['gas']['readings'] ?? []) !== [] || ($p['utilities']['fernwaerme']['readings'] ?? []) !== [])],
            'F1022-wallbox' => ['F1022', 'Wallbox (Ladestrom, E-Auto in der Werkstatt)', $raw(fn($p) => self::anyPot($p, 'meters', fn($m) => ($m['role'] ?? null) === 'ev_charger'))],
            // Mieter
            'F1008-period'  => ['F1008', 'Verbrauch je Zeitraum (Heizwärme)', $raw(fn($p) => self::anyPot($p, 'periods', fn() => true))],
            'F1008-tenancy' => ['F1008', 'Mietverhältnis mit Nebenkostenabrechnung', $raw(fn($p) => ($p['tenancies'] ?? []) !== [] && ($p['tenancy_statements'] ?? []) !== [])],
            // Hinweise
            'F1024-history' => ['F1024', 'Verlauf „Erledigt“', $raw(fn($p) => ($p['activity'] ?? []) !== [])],
            'F1028-assignee'=> ['F1028', 'Zuständig an Terminen', $raw(fn($p) => array_filter($p['reminders'] ?? [], fn($r) => !empty($r['assignee']) || !empty($r['assignee_id'])) !== [])],
        ];
    }

    /**
     * Beendete Periode ohne Rechnung (F1029): die Periode vor der laufenden
     * (Stichtag der Art) endete in den letzten 120 Tagen, keine Rechnung endet
     * ±31 Tage um sie — wie BillExpectedService, nur nach dem Stichtag.
     */
    private static function openPeriod(array $p, string $today): bool
    {
        foreach (['gas', 'strom', 'wasser', 'fernwaerme'] as $u) {
            if (($p['utilities'][$u]['meters'] ?? []) === []) continue;
            [$start] = BillingPeriodService::gridAround((string)($p['settings']["billing_cycle_anchor_$u"] ?? '01-01'), $today);
            $end = date('Y-m-d', (int)strtotime("$start -1 day"));
            if ((strtotime($today) - strtotime($end)) / 86400 > 120) continue;
            $near = array_filter($p['utilities'][$u]['bills'] ?? [], fn($b) => abs(strtotime((string)$b['period_to']) - strtotime($end)) <= 31 * 86400);
            if ($near === []) return true;
        }
        return false;
    }

    /**
     * Die Matrix: Fall → Haushalt → abgedeckt.
     *
     * @return array<string,array{code:string,label:string,households:array<string,bool>}>
     */
    public static function matrix(): array
    {
        $aligned = [];
        foreach (self::HOUSEHOLDS as $id) {
            foreach (self::TODAYS as $d) $aligned[$id][] = ['today' => $d, 'p' => DemoDataAligner::align(self::load($id), $d)];
        }
        $out = [];
        foreach (self::cases() as $key => [$code, $label, $fn]) {
            $row = [];
            foreach (self::HOUSEHOLDS as $id) $row[$id] = (bool)$fn(self::load($id), $aligned[$id]);
            $out[$key] = ['code' => $code, 'label' => $label, 'households' => $row];
        }
        return $out;
    }

    /** @return array<string,array{0:string}> */
    public static function caseIds(): array
    {
        return array_combine(array_keys(self::cases()), array_map(fn($k) => [$k], array_keys(self::cases())));
    }

    #[DataProvider('caseIds')]
    public function testEveryUseCaseIsShownByAtLeastOneHousehold(string $case): void
    {
        static $matrix = null;
        $matrix ??= self::matrix();
        $row = $matrix[$case];
        self::assertContains(true, $row['households'], "{$row['code']} „{$row['label']}“: in keinem Beispielhaushalt");
    }

    /**
     * Doku-Behauptung (docs/einstieg/einrichtung.md DE/EN, Eigenheim klassisch,
     * „seit v3.6.0“): Einliegerwohnung mit HT/NT-Gruppe und Gruppenvertrag samt
     * Rechnungen (die erste kürzer), stillgelegter Zähler, Home-Assistant-Alias,
     * Zählermiete als Posten — in genau diesem Haushalt.
     */
    public function testTheClassicHouseShowsWhatTheHandbookSays(): void
    {
        $m = self::matrix();
        foreach (['F1006-group', 'F1017-contract', 'F1017-htnt', 'H5-group', 'F1030-irregular', 'F1014-retired', 'F1009-alias', 'H5-items', 'F1004-est'] as $case) {
            self::assertTrue($m[$case]['households']['eigenheim-klassisch'], "Eigenheim klassisch: {$m[$case]['label']}");
        }
        foreach (['docs/einstieg/einrichtung.md' => 'Zählergruppe mit Gruppenvertrag', 'docs/en/einstieg/einrichtung.md' => 'meter group with group contract'] as $file => $needle) {
            self::assertStringContainsString($needle, (string)file_get_contents(dirname(__DIR__, 3) . "/$file"), $file);
        }
    }

    /**
     * Ein unbefristeter Vertrag ist in den Beispieldaten jedes ganze Jahr mit
     * Daten abgerechnet: eine Rechnung des Zählers endet in dem Jahr, oder im
     * Jahr danach steht eine Rück- bzw. Nachzahlung am Vertrag. Sonst zählte
     * der Saldo des laufenden Vertrags mehrere Jahre zusammen (das Schaufenster
     * meldete so bis v3.6.0 „Rückzahlung 1.710 €“ bei der Fernwärme).
     */
    public function testOpenEndedContractsAreSettledEveryYear(): void
    {
        $checked = 0;
        foreach (self::HOUSEHOLDS as $id) {
            $p = self::load($id);
            foreach (['gas', 'strom', 'wasser', 'fernwaerme'] as $u) {
                foreach ($p['utilities'][$u]['contracts'] ?? [] as $c) {
                    if (!self::realContract($c) || !empty($c['end']) || empty($c['meter_id'])) continue;
                    $dates = array_column(array_filter($p['utilities'][$u]['readings'] ?? [], fn($r) => $r['meter_id'] === $c['meter_id']), 'date');
                    if ($dates === []) continue;
                    $first = max((string)$c['start'], min($dates));
                    for ($y = (int)substr($first, 0, 4); $y < (int)substr(max($dates), 0, 4); $y++) {
                        $bill = array_filter($p['utilities'][$u]['bills'] ?? [], fn($b) => $b['meter_id'] === $c['meter_id'] && str_starts_with((string)$b['period_to'], "$y-"));
                        $paid = array_filter($c['special_payments'] ?? [], fn($s) => str_starts_with((string)$s['date'], ($y + 1) . '-')
                            && preg_match('/^(rueck|nach)zahlung/', (string)$s['kind']));
                        self::assertTrue($bill !== [] || $paid !== [], "$id $u {$c['id']}: Abrechnung $y fehlt (weder Rechnung noch Rück-/Nachzahlung)");
                        $checked++;
                    }
                }
            }
        }
        self::assertGreaterThanOrEqual(5, $checked, 'Jahre geprüft');
    }

    /** Die Liste der Fälle ist nicht leer und nennt je Fall einen Code (Feature-Tabelle der Roadmap, Issue oder Release). */
    public function testCasesNameTheirFeature(): void
    {
        $cases = self::cases();
        self::assertGreaterThanOrEqual(40, count($cases));
        foreach ($cases as $k => [$code, $label]) {
            self::assertMatchesRegularExpression('/^(F|N)\d{4}$|^#\d+$|^v\d+\.\d+\.\d+$/', $code, $k);
            self::assertNotSame('', $label);
        }
    }
}
