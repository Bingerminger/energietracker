<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\TenancyService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (E) — Zuständigkeit am Mietverhältnis (Entscheidung 12a): eigenes Feld `assignee_id`, Rückfall
 * auf den ersten Wärmezähler des Mietverhältnisses, sonst niemand. Bis v3.10 landeten Nebenkosten- und
 * CO₂-Fristen bei niemandem. Erfundene Personen und Daten, feste Stichtage.
 */
#[CoversClass(ResponsibilityService::class)]
#[CoversClass(TenancyService::class)]
#[CoversClass(AgendaService::class)]
final class TenancyResponsibilityTest extends ServiceTestCase
{
    private TenancyService $ten;
    private ResponsibilityService $resp;
    /** @var array<string,string> */
    private array $people = ['u_anna' => 'Anna', 'u_ben' => 'Ben'];
    private string $heat = '';

    protected function setUp(): void
    {
        parent::setUp();
        $lookup = fn(string $id): ?array => isset($this->people[$id]) ? ['id' => $id, 'name' => $this->people[$id]] : null;
        $this->resp = new ResponsibilityService($this->store);
        $this->resp->attachPeople($lookup);
        $this->ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->ten->attachResponsibility($this->resp);
        $this->settings->set(['wohnverhaeltnis' => 'miete', 'co2_price_eur_t_years' => ['2025' => 60]]);
        // ein Wärmezähler (Wohnungsstation), zuständig Ben
        $this->heat = 'm_waerme_test';
        $this->store->write('waerme/meters.json', [['id' => $this->heat, 'name' => 'Wohnungsstation', 'active' => true, 'assignee_id' => 'u_ben',
            'devices' => [['id' => 'd1', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0]]]]);
    }

    private function agenda(): AgendaService
    {
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $split = new Co2SplitService(new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $this->ten),
            $this->ten, $this->settings, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n, $this->ten, $split);
        $agenda->attachResponsibility($this->resp);
        return $agenda;
    }

    /** @return array<string,array<string,mixed>> Ereignisse der drei Arten je Art */
    private function tenancyEvents(string $today): array
    {
        $out = [];
        foreach ($this->agenda()->events(400, $today) as $e) {
            if (in_array($e['kind'], ['tenancy_statement_due', 'objection_deadline', 'co2_claim_deadline'], true)) $out[$e['kind']] = $e;
        }
        return $out;
    }

    /** Ein Haushalt mit allen drei Fristen am 2027-01-20: Abrechnung 2025 fehlt, Einwand gegen 2024, CO₂ 2025. */
    private function household(array $tenancy): string
    {
        $t = $this->ten->create(['start' => '2024-01-01', 'wohnflaeche_m2' => 80, 'label' => 'Musterstraße'] + $tenancy);
        // Abrechnung 2024 am 2026-02-15 erhalten → Einwände bis 2027-02-15
        $this->ten->createStatement($t['id'], ['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'received_on' => '2026-02-15',
            'total_cost_eur' => 1800, 'prepaid_eur' => 1700, 'positions' => []]);
        // Gas 12.000 kWh in 2025, Rechnung vom 2026-02-15 → CO₂-Frist 2027-02-15
        $f = (new \Energietracker\Services\ConversionFactorService($this->settings, $this->i18n))->factorOn('gas', '2025-06-01');
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [['date' => '2025-01-01', 'counter' => 1000], ['date' => '2026-01-01', 'counter' => 1000 + 12000 / $f]]);
        $this->store->write('gas/bills.json', [['id' => 'b1', 'meter_id' => 'x', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-02-15']]);
        return (string)$t['id'];
    }

    public function testTheTenancyAssigneeComesFirst(): void
    {
        $this->household(['assignee_id' => 'u_anna', 'meter_ids' => ['heat' => [$this->heat]]]);
        $ev = $this->tenancyEvents('2027-01-20');
        self::assertSame(['co2_claim_deadline', 'objection_deadline', 'tenancy_statement_due'], array_keys(self::sorted($ev)));
        foreach ($ev as $kind => $e) {
            self::assertSame(['Anna', 'u_anna', 'tenancy'], [$e['assignee'], $e['assignee_id'], $e['assignee_source']], $kind);
        }
        // v3.11.0 (integration) — der Kalender nennt die Herkunft wie bei Vertrag und Zähler
        $ics = (new CalendarService($this->agenda(), $this->settings, new InstanceService($this->store), $this->i18n))->ics('2027-01-20');
        self::assertSame(3, substr_count($ics, "\r\nDESCRIPTION:Zuständig: Anna (über das Mietverhältnis)\r\n"), $ics);
    }

    /** Ohne eigenes Feld: die Person am Wärmezähler (Bestand bleibt nicht leer); ohne Wärmezähler niemand. */
    public function testFallbackToTheHeatMeterThenNobody(): void
    {
        $id = $this->household(['meter_ids' => ['heat' => [$this->heat]]]);
        foreach ($this->tenancyEvents('2027-01-20') as $kind => $e) {
            self::assertSame(['Ben', 'meter'], [$e['assignee'], $e['assignee_source']], $kind);
        }
        $this->ten->update($id, ['meter_ids' => ['heat' => []]]);   // Etagenheizung mit Gaszähler: kein Wärmezähler
        foreach ($this->tenancyEvents('2027-01-20') as $kind => $e) {
            self::assertSame([null, null], [$e['assignee'], $e['assignee_source']], $kind);
        }
    }

    public function testFieldInputDisplayAndPersonDeletion(): void
    {
        $t = $this->ten->create(['start' => '2024-01-01', 'assignee_id' => 'u_anna']);
        self::assertSame('u_anna', $this->ten->get($t['id'])['assignee_id']);
        self::assertSame('Anna', $this->ten->forApi($this->ten->get($t['id']))['assignee']);
        // ohne Feld unverändert, leer = niemand, unbekannt = Fehler
        $this->ten->update($t['id'], ['label' => 'Musterstraße']);
        self::assertSame('u_anna', $this->ten->get($t['id'])['assignee_id']);
        try {
            $this->ten->update($t['id'], ['assignee_id' => 'u_zoe']);
            self::fail('unbekannte Person angenommen');
        } catch (LocalizedException $e) {
            self::assertSame(ResponsibilityService::UNKNOWN, $e->key);
        }
        self::assertSame(['meters' => 0, 'contracts' => 0, 'tenancies' => 1], $this->resp->countFor('u_anna'));
        // Person löschen: an die löschende Person, sonst „ehemalige Person“
        self::assertSame(['handed_over' => 1, 'kept' => 0], $this->resp->personDeleted('u_anna', false, ['id' => 'u_ben', 'name' => 'Ben']));
        self::assertSame('u_ben', $this->ten->get($t['id'])['assignee_id']);
        self::assertSame(['handed_over' => 0, 'kept' => 2], $this->resp->personDeleted('u_ben', true), 'Mietverhältnis und Wärmezähler');
        $stored = $this->ten->get($t['id']);
        self::assertSame([false, true], [isset($stored['assignee_id']), $stored['assignee_former']]);
        $this->ten->update($t['id'], ['assignee_id' => null]);
        self::assertArrayNotHasKey('assignee_former', $this->ten->get($t['id']), 'neu zugewiesen ersetzt den Vermerk');
    }

    /** Das Feld übersteht Sicherung und Wiederherstellung; der Import prüft seine Form. */
    public function testBackupRoundtrip(): void
    {
        $t = $this->ten->create(['start' => '2024-01-01', 'assignee_id' => 'u_anna']);
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export(false);
        $this->store->write('tenancies.json', []);
        $backups->import($dump, false, true);
        self::assertSame('u_anna', $this->ten->get($t['id'])['assignee_id']);
        $bad = $dump;
        $bad['tenancies'][0]['assignee_id'] = 'Anna';
        try {
            $backups->import($bad, true);
            self::fail('ungültige Zuständigkeit angenommen');
        } catch (\Energietracker\Services\BackupInvalidException $e) {
            self::assertContains('tenancies:assignee_id', array_map(fn($p) => $p['pot'] . ':' . $p['problem'], $e->problems));
        }
    }

    private static function sorted(array $a): array
    {
        ksort($a);
        return $a;
    }
}
