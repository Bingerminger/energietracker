<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.9.0 (Gruppenverträge, Fehler aus dem Doku-Paket) — Mitglieder einer
 * Gruppe mit Gruppenvertrag tragen keinen eigenen Vertrag. „Kosten & Verträge“
 * zeigte an ihnen „Kein laufender Vertrag erfasst. Vertrag anlegen“, und
 * `/api/summary` (Grundlage von „Bekomme ich Geld zurück?“) nannte an ihnen
 * `contract: null` — der Saldo des Gruppenvertrags fehlte dort ganz. Geprüft
 * am Schaufenster (Wärmepumpe mit Doppeltarifzähler hinter dem Hauptzähler)
 * und an „Eigenheim klassisch“ (Einliegerwohnung HT/NT, keine Subzähler):
 * dieselbe Gruppe, derselbe Vertrag, derselbe Saldo wie contract-status der
 * Gruppe; im Jahresbericht der Saldo genau einmal, an den Mitgliedern keiner.
 */
final class GroupContractViewsTest extends ServiceTestCase
{
    use ReportFixture;

    /** @return array<string,array{0:string,1:string,2:list<string>}> */
    public static function households(): array
    {
        return [
            'Schaufenster'        => ['demo', 'g_strom_wp', ['m_strom_wp_ht', 'm_strom_wp_nt']],
            'Eigenheim klassisch' => ['eigenheim-klassisch', 'g_strom_einlieger', ['m_strom_einlieger_ht', 'm_strom_einlieger_nt']],
        ];
    }

    private function summary(string $today): array
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        return (new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $agenda,
            new InstanceService($this->store)))->build('strom', null, $today);
    }

    /** @param list<string> $members */
    #[DataProvider('households')]
    public function testMembersCarryTheGroupContractAndItsBalanceCountsOnce(string $persona, string $gid, array $members): void
    {
        $this->importPersona($persona);
        $model = $this->reportServices()['model']->build((int)date('Y'));
        $today = (string)$model['today'];
        $group = $this->meters->groupTarget('strom', $gid);
        self::assertNotNull($group);
        $gc = array_values(array_filter($this->consumption->contractStatus('strom', $group, $today)['contracts'], fn($c) => !empty($c['is_current'])))[0] ?? null;
        self::assertNotNull($gc, 'der Gruppenvertrag läuft');
        self::assertNotNull($gc['projected_end_balance']);

        // /api/summary: an jedem Mitglied die Gruppe und ihr Vertrag, `contract` bleibt null (kein doppelter Saldo)
        $rows = array_column($this->summary($today)['meters'], null, 'meter_id');
        foreach ($members as $id) {
            self::assertNull($rows[$id]['contract'], "$id: kein eigener Vertrag");
            $g = $rows[$id]['group_contract'];
            self::assertSame([$gid, (string)$group['name'], (string)$gc['contract_id']], [$g['group_id'] ?? null, $g['name'] ?? null, $g['contract']['contract_id'] ?? null], $id);
            self::assertEqualsWithDelta((float)$gc['projected_end_balance'], (float)$g['contract']['projected_end_balance'], 0.005, "$id: Saldo der Gruppe");
        }
        foreach ($rows as $id => $row) {
            if (!in_array($id, $members, true)) self::assertNull($row['group_contract'], "$id: kein Mitglied");
        }

        // Jahresbericht: der Gruppenvertrag einmal (über die Gruppe), an den Mitgliedern keiner
        $balances = array_values(array_filter($model['money']['balances'] ?? [], fn($b) => $b['utility'] === 'strom'));
        $bal = array_values(array_filter($balances, fn($b) => $b['meter_name'] === (string)$group['name']));
        self::assertCount(1, $bal, 'Saldo des Gruppenvertrags genau einmal');
        self::assertEqualsWithDelta(round((float)$gc['projected_end_balance'], 2), $bal[0]['projected_end_balance'], 0.005);
        $names = array_map(fn($id) => (string)$this->meters->get('strom', $id)['name'], $members);
        self::assertSame([], array_values(array_filter($balances, fn($b) => in_array($b['meter_name'], $names, true))), 'keiner an den Mitgliedern');
    }
}
