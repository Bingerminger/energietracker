<?php
declare(strict_types=1);

namespace Energietracker;

use Energietracker\Http\ErrorHandler;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Http\Router;
use Energietracker\Logging\Logger;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Services\{
    MeterService, ReadingService, ContractService, ConsumptionService,
    TemperatureService, RegressionService, ForecastService, AnomalyService,
    WeatherService, BackupService, SettingsService, DiagnosticsService,
    MigrationService, ReadingImportService, CsvExportService,
    DeliveryService, DeliveryConsumptionService, BenchmarkService,
    TariffComparisonService, RecommendationService, ReminderService, PdfReportService,
    StromSaldoService, PvSummaryService, HealthCheckService, DemoService,
    AuthService, IngestService
};
use Energietracker\Controllers\{
    MeterController, ReadingController, ContractController,
    ConsumptionController, ForecastController, TemperatureController,
    SettingsController, BackupController, DiagnosticsController, UtilitiesController,
    MigrationController, ExportController,
    DeliveryController, BenchmarkController, TariffComparisonController,
    RecommendationController, ReminderController, ReportController,
    StromSaldoController, PvSummaryController, HealthController, DemoController,
    AuthController, IngestController
};

/**
 * Minimal hand-rolled autoloader for the Energietracker namespace.
 * No Composer required — keeps deployment a single-file copy.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Energietracker\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});

final class App
{
    public Router $router;
    public Logger $logger;
    public JsonStore $store;
    public SettingsService $settings;
    public MeterService $meters;
    public ReadingService $readings;
    public ContractService $contracts;
    public ConsumptionService $consumption;
    public TemperatureService $temperatures;
    public WeatherService $weather;
    public RegressionService $regression;
    public ForecastService $forecasts;
    public AnomalyService $anomalies;
    public BackupService $backups;
    public DiagnosticsService $diagnostics;
    public MigrationService $migrationLegacy;
    public ReadingImportService $readingImport;
    public CsvExportService $csvExport;
    public DeliveryService $deliveries;
    public DeliveryConsumptionService $deliveryConsumption;
    public BenchmarkService $benchmark;
    public TariffComparisonService $tariffs;
    public RecommendationService $recommendations;
    public ReminderService $reminders;
    public PdfReportService $reports;
    public StromSaldoService $stromSaldo;
    public PvSummaryService $pvSummary;
    public HealthCheckService $health;
    public DemoService $demo;
    public AuthService $auth;
    public IngestService $ingest;

    public function __construct(string $dataDir)
    {
        // N1010: strukturierter Logger. Default-Datei-Ziel liegt unter dem
        // Datenverzeichnis, greift aber nur bei ET_LOG_DEST=file; Default
        // ist stderr (Docker-idiomatisch).
        $this->logger = new Logger(file: rtrim($dataDir, '/') . '/logs/app.log');
        ErrorHandler::install($this->logger);
        date_default_timezone_set('Europe/Berlin');

        $this->store        = new JsonStore($dataDir);
        $this->settings     = new SettingsService($this->store);
        $this->meters       = new MeterService($this->store);
        $this->readings     = new ReadingService($this->store, $this->meters);
        $this->contracts    = new ContractService($this->store, $this->meters);
        $this->regression   = new RegressionService();
        $this->deliveryConsumption = new DeliveryConsumptionService($this->store, $this->settings);
        $this->consumption  = new ConsumptionService(
            $this->store, $this->meters, $this->readings, $this->contracts, $this->settings,
            $this->regression, $this->deliveryConsumption
        );
        $this->weather      = new WeatherService();
        $this->temperatures = new TemperatureService($this->store, $this->settings, $this->weather);
        $this->forecasts    = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts
        );
        $this->anomalies    = new AnomalyService($this->regression, $this->settings);
        $this->backups      = new BackupService($this->store);
        $this->diagnostics  = new DiagnosticsService($this->store, $this->settings);
        $this->migrationLegacy = new MigrationService($this->store, $this->backups);
        $this->readingImport = new ReadingImportService($this->readings, $this->meters);
        $this->deliveries   = new DeliveryService($this->store, $this->meters);
        $this->csvExport    = new CsvExportService(
            $this->consumption, $this->readings, $this->meters, $this->temperatures, $this->deliveries
        );
        $this->benchmark    = new BenchmarkService($this->consumption, $this->meters, $this->settings);
        $this->tariffs      = new TariffComparisonService($this->consumption, $this->contracts, $this->meters);
        $this->recommendations = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $this->benchmark, $this->deliveries);
        $this->reminders    = new ReminderService($this->store, $this->settings);
        $this->reports      = new PdfReportService($this->meters, $this->consumption, $this->settings, $this->benchmark, $this->recommendations);
        // F1005 + N1003 (v1.7.0)
        $this->stromSaldo   = new StromSaldoService($this->consumption);
        $this->pvSummary    = new PvSummaryService($this->consumption);
        $this->health       = new HealthCheckService($this->store);
        // F1007 (v1.7.4)
        $this->demo         = new DemoService($this->store, $this->backups);
        // F1009 — HA-Anbindung: Token-Auth + idempotenter Push-Ingest.
        $this->auth         = new AuthService($this->store);
        $this->ingest       = new IngestService($this->meters, $this->readings);

        // Auto-migrate or initialize on first run
        $migrator = new Migrator($this->store);
        if ($migrator->needsMigration()) {
            $migrator->migrate();
            $this->logger->info('Datenmigration ausgeführt', [
                'schema_version' => $this->store->read('meta.json', [])['schema_version'] ?? null,
            ]);
        } elseif (!$migrator->isAlreadyMigrated()) {
            $migrator->initFresh();
            $this->logger->info('Datenverzeichnis frisch initialisiert', ['data_dir' => $dataDir]);
        }

        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $req): void
    {
        // N1010: ein Access-Log-Eintrag pro Request auf debug-Ebene
        // (im Default-Level info also stumm; ET_LOG_LEVEL=debug aktiviert ihn).
        // Methode + URI ergänzt der Logger selbst aus $_SERVER.
        $this->logger->debug('request');

        // Common headers
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        if ($req->method === 'OPTIONS') exit;
        $this->router->dispatch($req);
    }

    private function registerRoutes(): void
    {
        $r = $this->router;

        // ── Utilities listing ──
        $utilCtrl = new UtilitiesController();
        $r->get('/api/utilities', fn($req) => $utilCtrl->index($req));

        // ── Meters per utility ──
        $meterCtrl = new MeterController($this->meters);
        $r->get('/api/utility/{utility}/meters',                fn($req) => $meterCtrl->index($req));
        $r->post('/api/utility/{utility}/meters',               fn($req) => $meterCtrl->create($req));
        $r->get('/api/utility/{utility}/meters/{id}',           fn($req) => $meterCtrl->show($req));
        $r->patch('/api/utility/{utility}/meters/{id}',         fn($req) => $meterCtrl->update($req));
        $r->delete('/api/utility/{utility}/meters/{id}',        fn($req) => $meterCtrl->destroy($req));
        $r->post('/api/utility/{utility}/meters/{id}/replace-device',
                                                                fn($req) => $meterCtrl->replaceDevice($req));

        // ── Zählergruppen (v1.2.0 — F1006 Meter-Topologie) ──
        $r->get('/api/utility/{utility}/meter-groups',          fn($req) => $meterCtrl->listGroups($req));
        $r->post('/api/utility/{utility}/meter-groups',         fn($req) => $meterCtrl->createGroup($req));
        // Merge-Wizard: vor der {groupId}-Route, damit „merge" nicht als id gilt.
        $r->post('/api/utility/{utility}/meter-groups/merge',   fn($req) => $meterCtrl->mergeGroup($req));
        $r->patch('/api/utility/{utility}/meter-groups/{groupId}',  fn($req) => $meterCtrl->updateGroup($req));
        $r->delete('/api/utility/{utility}/meter-groups/{groupId}', fn($req) => $meterCtrl->destroyGroup($req));

        // ── Readings ──
        $readingCtrl = new ReadingController($this->readings, $this->readingImport, $this->settings);
        $r->get('/api/utility/{utility}/readings',     fn($req) => $readingCtrl->index($req));
        $r->post('/api/utility/{utility}/readings',    fn($req) => $readingCtrl->create($req));
        $r->patch('/api/utility/{utility}/readings/{id}', fn($req) => $readingCtrl->update($req));
        $r->delete('/api/utility/{utility}/readings/{id}',fn($req) => $readingCtrl->destroy($req));
        // F-06: zähler-gebundener CSV-Bulk-Import (Body: text/plain CSV)
        $r->post('/api/utility/{utility}/meters/{id}/readings/import-csv',
                                                       fn($req) => $readingCtrl->importCsv($req));
        // F1004 (v1.6.0): Aggregat für den zentralen Zählerstand-Erfassungs-View
        $r->get('/api/readings-overview',              fn($req) => $readingCtrl->overview($req));

        // ── Deliveries (v1.3.0 — Heizöl/Pellets) ──
        $deliveryCtrl = new DeliveryController($this->deliveries, $this->consumption, $this->meters);
        $r->get('/api/utility/{utility}/deliveries',         fn($req) => $deliveryCtrl->index($req));
        $r->post('/api/utility/{utility}/deliveries',        fn($req) => $deliveryCtrl->create($req));
        $r->patch('/api/utility/{utility}/deliveries/{id}',  fn($req) => $deliveryCtrl->update($req));
        $r->delete('/api/utility/{utility}/deliveries/{id}', fn($req) => $deliveryCtrl->destroy($req));
        $r->get('/api/utility/{utility}/meters/{id}/stock-history',
                                                             fn($req) => $deliveryCtrl->stockHistory($req));

        // ── Contracts ──
        $contractCtrl = new ContractController($this->contracts);
        $r->get('/api/utility/{utility}/contracts',         fn($req) => $contractCtrl->index($req));
        $r->post('/api/utility/{utility}/contracts',        fn($req) => $contractCtrl->create($req));
        $r->get('/api/utility/{utility}/contracts/{id}',    fn($req) => $contractCtrl->show($req));
        $r->patch('/api/utility/{utility}/contracts/{id}',  fn($req) => $contractCtrl->update($req));
        $r->delete('/api/utility/{utility}/contracts/{id}', fn($req) => $contractCtrl->destroy($req));

        // ── Consumption (monthly aggregates) ──
        $cCtrl = new ConsumptionController($this->consumption, $this->anomalies, $this->meters, $this->regression, $this->settings);
        $r->get('/api/utility/{utility}/consumption',              fn($req) => $cCtrl->utility($req));
        $r->get('/api/utility/{utility}/meters/{id}/consumption',  fn($req) => $cCtrl->meter($req));
        $r->get('/api/utility/{utility}/meters/{id}/contract-status', fn($req) => $cCtrl->contractStatus($req));

        // ── Forecast ──
        $fCtrl = new ForecastController($this->forecasts, $this->meters);
        $r->get('/api/utility/{utility}/meters/{id}/forecast', fn($req) => $fCtrl->forMeter($req));

        // ── Temperatures ──
        $tCtrl = new TemperatureController($this->temperatures);
        $r->get('/api/temperatures',                  fn($req) => $tCtrl->index($req));
        $r->post('/api/temperatures',                 fn($req) => $tCtrl->upsert($req));
        $r->post('/api/temperatures/import-csv',      fn($req) => $tCtrl->importCsv($req));
        $r->post('/api/temperatures/sync-open-meteo', fn($req) => $tCtrl->syncOpenMeteo($req));
        $r->delete('/api/temperatures/{date}',        fn($req) => $tCtrl->delete($req));

        // ── Settings / Backup / Diagnostics ──
        $sCtrl = new SettingsController($this->settings);
        $r->get('/api/settings',   fn($req) => $sCtrl->index($req));
        $r->patch('/api/settings', fn($req) => $sCtrl->update($req));

        $bCtrl = new BackupController($this->backups);
        $r->get('/api/backup/export',     fn($req) => $bCtrl->export($req));
        $r->post('/api/backup/import',    fn($req) => $bCtrl->import($req));
        $r->post('/api/backup/snapshot',  fn($req) => $bCtrl->snapshot($req));

        // ── CSV-Export (F-07) ──
        $exCtrl = new ExportController($this->csvExport);
        $r->get('/api/export/temperatures.csv',          fn($req) => $exCtrl->temperatures($req));
        $r->get('/api/export/{utility}/monthly.csv',     fn($req) => $exCtrl->monthly($req));
        $r->get('/api/export/{utility}/readings.csv',    fn($req) => $exCtrl->readings($req));
        $r->get('/api/export/{utility}/deliveries.csv',  fn($req) => $exCtrl->deliveries($req));

        // ── Migration aus v0.9.0 ──
        $mgCtrl = new MigrationController($this->migrationLegacy);
        $r->post('/api/migration/v09/preview', fn($req) => $mgCtrl->preview($req));
        $r->post('/api/migration/v09/import',  fn($req) => $mgCtrl->import($req));

        $dCtrl = new DiagnosticsController($this->diagnostics);
        $r->get('/api/diagnostics', fn($req) => $dCtrl->index($req));

        // ── Benchmark (v1.3.0 — Effizienzklasse kWh/m²) ──
        $bCtrl = new BenchmarkController($this->benchmark);
        $r->get('/api/benchmarks/efficiency', fn($req) => $bCtrl->efficiency($req));

        // ── Tarifvergleich (v1.3.0 — Schattenverträge) ──
        $tcCtrl = new TariffComparisonController($this->tariffs);
        $r->get('/api/utility/{utility}/meters/{id}/tariff-comparison',
                                              fn($req) => $tcCtrl->compare($req));

        // ── Empfehlungen (v1.3.0 — statistische Insights) ──
        $recCtrl = new RecommendationController($this->recommendations);
        $r->get('/api/recommendations', fn($req) => $recCtrl->index($req));
        $r->post('/api/recommendations/{id}/dismiss', fn($req) => $recCtrl->dismiss($req));

        // ── Termine/Erinnerungen (v1.3.0) ──
        $remCtrl = new ReminderController($this->reminders);
        $r->get('/api/reminders',            fn($req) => $remCtrl->index($req));
        $r->post('/api/reminders',           fn($req) => $remCtrl->create($req));
        $r->patch('/api/reminders/{id}',     fn($req) => $remCtrl->update($req));
        $r->delete('/api/reminders/{id}',    fn($req) => $remCtrl->destroy($req));
        $r->post('/api/reminders/{id}/done', fn($req) => $remCtrl->done($req));

        // ── PDF-Jahresbericht (v1.3.0) ──
        $repCtrl = new ReportController($this->reports);
        $r->get('/api/reports/yearly.pdf', fn($req) => $repCtrl->yearly($req));

        // ── F1005 (v1.7.0) — Strom-Saldo + PV-Summary ──
        $saldoCtrl = new StromSaldoController($this->stromSaldo);
        $r->get('/api/strom-saldo', fn($req) => $saldoCtrl->index($req));
        $pvCtrl = new PvSummaryController($this->pvSummary);
        $r->get('/api/pv-summary',  fn($req) => $pvCtrl->index($req));

        // ── N1003 (v1.7.0) — Health-Check ──
        $hCtrl = new HealthController($this->health);
        $r->get('/api/health', fn($req) => $hCtrl->index($req));

        // ── F1007 (v1.7.4) — Demo-Daten-Komfort-Import ──
        $demoCtrl = new DemoController($this->demo);
        $r->get('/api/demo/status',  fn($req) => $demoCtrl->status($req));
        $r->post('/api/demo/import', fn($req) => $demoCtrl->import($req));

        // ── F1009 — Home-Assistant-Anbindung ──
        $authCtrl = new AuthController($this->auth);
        $r->get('/api/auth/token',    fn($req) => $authCtrl->status($req));
        $r->post('/api/auth/token',   fn($req) => $authCtrl->generate($req));
        $r->delete('/api/auth/token', fn($req) => $authCtrl->revoke($req));
        $ingestCtrl = new IngestController($this->ingest, $this->auth);
        $r->post('/api/ingest',       fn($req) => $ingestCtrl->store($req));
    }
}
