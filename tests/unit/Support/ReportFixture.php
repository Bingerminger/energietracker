<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\EvChargingReportService;
use Energietracker\Services\EvccService;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ReportModelService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;

/**
 * v3.4.0 (F1025) — Jahresbericht vollständig verdrahtet wie in
 * src/bootstrap.php, für Tests auf ServiceTestCase (ohne HTTP-Schicht).
 */
trait ReportFixture
{
    /** @return array{model:ReportModelService,pdf:PdfReportService,recommendations:RecommendationService} */
    protected function reportServices(): array
    {
        $benchmark = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $budget = new TenancyBudgetService($tenancies, $this->meters, $this->consumption, $this->i18n);
        $co2 = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $tenancies);
        $split = new Co2SplitService($co2, $tenancies, $this->settings, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            $reminders, $recs, $this->i18n, $tenancies, $split);
        $intervals = new IntervalService($this->store, $this->settings);
        $flex = new FlexibilityService($intervals, $this->meters, $this->contracts);
        $evcc = new EvccService($this->store, $this->meters, $this->readings, new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings);
        $forecasts = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new TariffSwitchService($forecasts, $this->contracts, $this->meters, $this->i18n, new MarketPriceService($this->store, $this->i18n, null, $intervals));
        $model = new ReportModelService($this->meters, $this->consumption, $this->contracts, $this->settings, $this->i18n, $benchmark, $recs,
            new ReportAdviceService($this->i18n), $agenda, new PvSummaryService($this->consumption, $this->settings),
            new HeatPumpService($this->meters, $this->consumption), $flex, $evcc,
            new EvChargingReportService($this->meters, $this->readings, $this->consumption, $this->settings, $this->i18n),
            $tenancies, $budget, $split, new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n),
            $switch, $deliveries, $forecasts);
        $pdf = new PdfReportService($this->meters, $this->consumption, $this->settings, $benchmark, $recs, $this->i18n, $model);
        return ['model' => $model, 'pdf' => $pdf, 'recommendations' => $recs];
    }

    protected function importPersona(string $id): void
    {
        $file = dirname(__DIR__, 3) . ($id === 'demo' ? '/demo-data/energietracker-demo-backup.json' : "/demo-data/personas/$id.json");
        (new BackupService($this->store, $this->i18n))->import(json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
    }
}
