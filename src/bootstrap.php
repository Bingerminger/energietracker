<?php
declare(strict_types=1);

namespace Energietracker;

use Energietracker\Http\CrossSiteGuard;
use Energietracker\Http\ErrorHandler;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Http\Router;
use Energietracker\Logging\Logger;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Storage\WriteLock;
use Energietracker\Config\Countries;
use Energietracker\Services\{
    MeterService, ReadingService, ContractService, ConsumptionService,
    TemperatureService, RegressionService, ForecastService, AnomalyService,
    WeatherService, BackupService, SettingsService, DiagnosticsService,
    MigrationService, ReadingImportService, CsvExportService,
    DeliveryService, DeliveryConsumptionService, BenchmarkService, ConversionFactorService,
    TariffComparisonService, TariffSwitchService,
    RecommendationService, ReminderService, PdfReportService,
    StromSaldoService, PvSummaryService, HealthCheckService, DemoService,
    AuthService, IngestService, I18nService
};
use Energietracker\Controllers\{
    MeterController, ReadingController, ContractController,
    ConsumptionController, ForecastController, TemperatureController,
    SettingsController, BackupController, DiagnosticsController, UtilitiesController,
    MigrationController, ExportController,
    DeliveryController, BenchmarkController, TariffComparisonController,
    TariffSwitchController,
    RecommendationController, ReminderController, ReportController,
    StromSaldoController, PvSummaryController, HealthController, DemoController,
    AuthController, IngestController, SessionController
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
    public ConversionFactorService $factors;   // v2.5.0 — F1012
    public BenchmarkService $benchmark;
    public TariffComparisonService $tariffs;
    public TariffSwitchService $tariffSwitch;
    public RecommendationService $recommendations;
    public ReminderService $reminders;
    public PdfReportService $reports;
    public StromSaldoService $stromSaldo;
    public PvSummaryService $pvSummary;
    public HealthCheckService $health;
    public DemoService $demo;
    public AuthService $auth;
    public IngestService $ingest;
    public I18nService $i18n;

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
        // v2.7.0 — Zeitzone aus dem Länderprofil („heute" für Ablesungen,
        // Fälligkeiten, Open-Meteo). Ein unlesbarer Wert lässt Berlin stehen.
        $tz = (string)$this->settings->get('timezone', 'Europe/Berlin');
        if ($tz !== 'Europe/Berlin' && in_array($tz, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            date_default_timezone_set($tz);
        }
        // N1007 (v2.0.0) — Lokalisierung: liest die JSON-Kataloge aus
        // public/locales/ (Single source mit dem Frontend).
        $this->i18n         = new I18nService(dirname(__DIR__) . '/public/locales', $this->settings);
        // v2.5.0 — F1012: Settings validiert die Faktorliste beim Speichern
        // und braucht dafür lokalisierte Meldungen; der Zirkel I18n → Settings
        // wird durch nachträgliches Anhängen aufgelöst.
        $this->settings->attachI18n($this->i18n);
        // v2.6.0 — stabile Fehlercodes und übersetzte generische Fehlermeldung
        Response::setErrorCodeResolver(fn(string $message): ?string => $this->i18n->errorCodeFor($message));
        ErrorHandler::attachTranslator(fn(string $key, array $params): string => $this->i18n->t($key, $params));
        $this->meters       = new MeterService($this->store, $this->i18n);
        $this->readings     = new ReadingService($this->store, $this->meters, $this->i18n);
        $this->contracts    = new ContractService($this->store, $this->meters, $this->i18n);
        $this->regression   = new RegressionService();
        $this->deliveryConsumption = new DeliveryConsumptionService($this->store, $this->settings);
        // v2.5.0 — F1012: datierte Gas-Umrechnungsfaktoren (Zustandszahl × Brennwert)
        $this->factors      = new ConversionFactorService($this->settings, $this->i18n);
        $this->consumption  = new ConsumptionService(
            $this->store, $this->meters, $this->readings, $this->contracts, $this->settings,
            $this->i18n, $this->regression, $this->deliveryConsumption, $this->factors
        );
        $this->weather      = new WeatherService();
        $this->temperatures = new TemperatureService($this->store, $this->settings, $this->weather);
        $this->forecasts    = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
        $this->anomalies    = new AnomalyService($this->regression, $this->settings);
        $this->backups      = new BackupService($this->store, $this->i18n);
        $this->diagnostics  = new DiagnosticsService($this->store, $this->settings);
        $this->migrationLegacy = new MigrationService($this->store, $this->backups, $this->i18n);
        $this->readingImport = new ReadingImportService($this->readings, $this->meters, $this->i18n);
        $this->deliveries   = new DeliveryService($this->store, $this->meters, $this->i18n);
        $this->csvExport    = new CsvExportService(
            $this->consumption, $this->readings, $this->meters, $this->temperatures, $this->deliveries, $this->i18n
        );
        $this->benchmark    = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $this->tariffs      = new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n);
        $this->tariffSwitch = new TariffSwitchService($this->forecasts, $this->contracts, $this->meters, $this->i18n);
        $this->recommendations = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $this->benchmark, $this->deliveries, $this->i18n);
        $this->reminders    = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->reports      = new PdfReportService($this->meters, $this->consumption, $this->settings, $this->benchmark, $this->recommendations, $this->i18n);
        // F1005 + N1003 (v1.7.0)
        $this->stromSaldo   = new StromSaldoService($this->consumption);
        $this->pvSummary    = new PvSummaryService($this->consumption);
        $this->health       = new HealthCheckService($this->store);
        // F1007 (v1.7.4)
        $this->demo         = new DemoService($this->store, $this->backups, $this->i18n);
        // F1009 — HA-Anbindung: Token-Auth + idempotenter Push-Ingest.
        $this->auth         = new AuthService($this->store);
        $this->ingest       = new IngestService($this->meters, $this->readings, $this->i18n);

        // Auto-migrate or initialize on first run.
        // Reihenfolge wichtig: ein komplett leeres Verzeichnis (echter
        // Erststart, z. B. frischer Docker-Container) wird per initFresh()
        // mit Standard-Zählern bestückt — NICHT per migrate(), das ohne
        // Altdaten einen leeren Tracker hinterließe.
        // v2.5.3 — Start-Logik im Migrator (runOnStartup), unter der
        // Schreibsperre und mit Sicherungs-Snapshot vor jeder Migration. Die
        // Sperre verhindert, dass zwei gleichzeitige erste Anfragen nach
        // einem Update beide migrieren; runOnStartup prüft danach erneut.
        $this->writeLock = new WriteLock($this->store->rootDir());
        $migrator = new Migrator($this->store, $this->i18n);
        if ($migrator->isPristine() || $migrator->needsMigration() || !$migrator->isAlreadyMigrated()) {
            $this->writeLock->acquire();
            try {
                // v2.7.0 — Erststart (I18N-01): Sprache und Land aus dem Browser,
                // BEVOR die Standard-Zähler ihren Namen bekommen. Bis v2.6.0
                // begrüßte jede Neuinstallation auf Deutsch und schrieb
                // „Hauptzähler" dauerhaft in die Daten.
                $firstStart = $migrator->isPristine() ? $this->firstStartSettings($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) : null;
                if ($firstStart !== null) $this->i18n->setLocale($firstStart['language'] ?? null);
                $result = $migrator->runOnStartup(fn(string $from): string => $this->backups->saveSnapshot(
                    'pre-migration-' . preg_replace('/[^0-9A-Za-z.]/', '', $from) . '_'
                ));
                if ($firstStart !== null && ($result['action'] ?? null) === 'fresh' && $firstStart !== []) {
                    $this->settings->set($firstStart);
                }
                $this->logStartup($result, $dataDir);
            } finally {
                $this->writeLock->release();
            }
        }

        $this->router = new Router();
        $this->registerRoutes();
    }

    /** v2.5.3 — eine Sperre je schreibender Anfrage, s. Storage\WriteLock */
    private WriteLock $writeLock;

    /**
     * v2.7.0 — Einstellungen einer Neuinstallation aus Accept-Language:
     * Sprache (wie negotiate()), Land (Region im Header, sonst aus der
     * Sprache) und die Werte des Länderprofils — nur, was vom Default
     * abweicht. So greifen spätere Korrekturen der Defaults auch bei neuen
     * deutschen Installationen. Ohne Header (CLI, Tests) bleibt alles deutsch.
     *
     * @return array<string,mixed>
     */
    private function firstStartSettings(?string $acceptLanguage): array
    {
        $language = $this->i18n->negotiate($acceptLanguage) ?? I18nService::DEFAULT_LOCALE;
        $country  = Countries::fromAcceptLanguage($acceptLanguage) ?? Countries::forLanguage($language);
        $wanted   = ['language' => $language] + Countries::settingsFor($country);
        $defaults = $this->settings->all();
        return array_filter($wanted, fn($v, $k) => ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * v2.6.0 — Schemaversion der Daten, wenn sie von einer NEUEREN App-Version
     * stammen. Dann antwortet jede Route außer /api/health mit 503, und nichts
     * wird geschrieben (s. Migrator::dataSchemaIsNewer()).
     */
    private ?string $dataTooNew = null;

    /** @param array<string,mixed>|null $result aus Migrator::runOnStartup() */
    private function logStartup(?array $result, string $dataDir): void
    {
        if ($result === null) return;
        if ($result['action'] === 'too-new') {
            $this->dataTooNew = (string)$result['schema'];
            $this->logger->error('Daten stammen von einer neueren Version — Zugriff gesperrt', [
                'data_schema' => $this->dataTooNew,
                'app_schema'  => Migrator::SCHEMA_VERSION,
            ]);
            return;
        }
        if ($result['action'] === 'fresh') {
            $this->logger->info('Datenverzeichnis frisch initialisiert', ['data_dir' => $dataDir]);
            return;
        }
        if (!empty($result['snapshot_error'])) {
            $this->logger->error('Snapshot vor der Migration fehlgeschlagen', ['error' => $result['snapshot_error']]);
        }
        $this->logger->info('Datenmigration ausgeführt', [
            'from'           => $result['from'] ?? null,
            'schema_version' => $this->store->read('meta.json', [])['schema_version'] ?? null,
            'snapshot'       => $result['snapshot'] ?? null,
        ]);
    }

    public function handle(Request $req): void
    {
        // N1010: ein Access-Log-Eintrag pro Request auf debug-Ebene
        // (im Default-Level info also stumm; ET_LOG_LEVEL=debug aktiviert ihn).
        // Methode + URI ergänzt der Logger selbst aus $_SERVER.
        $this->logger->debug('request');

        // N1007 — das `language`-Setting ist die Quelle der Wahrheit (das
        // Frontend nutzt es exklusiv). Es gilt für ALLE serverseitigen Texte,
        // auch für Downloads wie den PDF-Report, die als Browser-Navigation
        // laufen und sonst über den Accept-Language-Header verfälscht würden.
        // Accept-Language greift nur, wenn kein gültiges Setting vorliegt.
        $langSetting = (string)$this->settings->get('language', '');
        if (in_array($langSetting, $this->i18n->supported(), true)) {
            $this->i18n->setLocale($langSetting);
        } else {
            $negotiated = $this->i18n->negotiate($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
            if ($negotiated !== null) {
                $this->i18n->setLocale($negotiated);
            }
        }

        // Common headers
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header_remove('X-Powered-By');   // v2.6.0 — keine PHP-Version nach außen
        }

        // v2.6.0 — Host-Liste (opt-in, ET_ALLOWED_HOSTS) gegen DNS-Rebinding:
        // Eine fremde Domain, die auf die LAN-Adresse zeigt, wäre sonst
        // „same-origin" und läse alles. IP-Adressen und localhost gelten immer.
        if (!self::hostAllowed((string)($_SERVER['HTTP_HOST'] ?? ''))) {
            Response::error($this->i18n->t('errors.http.hostNotAllowed', [
                'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
            ]), 421, null, 'errors.http.hostNotAllowed');
        }
        // v2.6.0 — Daten einer neueren Version: nur /api/health antwortet.
        if ($this->dataTooNew !== null && $req->path !== '/api/health') {
            Response::error($this->i18n->t('errors.storage.dataTooNew', [
                'schema' => $this->dataTooNew, 'app' => Migrator::SCHEMA_VERSION,
            ]), 503, null, 'errors.storage.dataTooNew');
        }

        // v2.5.3 — schreibende Anfragen fremder Webseiten abweisen (CSRF), s. CrossSiteGuard
        if (CrossSiteGuard::isForeignWrite($req->method, $_SERVER)) {
            $this->logger->warning('Schreibende Anfrage einer fremden Webseite abgelehnt', [
                'method' => $req->method,
                'path'   => $req->path,
                'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
                'site'   => (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''),
            ]);
            Response::error($this->i18n->t('errors.http.crossSite'), 403);
        }
        // v2.6.0 — Anmeldung (opt-in, s. AuthService). Ohne Anmeldung bleibt
        // alles wie bisher offen.
        $this->authenticated = $this->authenticate($req);
        if (!$this->authenticated && !self::isPublic($req)) {
            Response::error($this->i18n->t('errors.auth.required'), 401, null, 'errors.auth.required');
        }
        if ($this->readOnlyKey && !in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            Response::error($this->i18n->t('errors.auth.readOnlyKey'), 403, null, 'errors.auth.readOnlyKey');
        }

        if (in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $this->writeLock->acquire();   // endet mit dem Prozess der Anfrage
        }
        $this->router->dispatch($req);
    }

    /** v2.6.0 — Stand der Anmeldung dieser Anfrage (für Health und /api/session). */
    private bool $authenticated = false;
    /** v2.6.0 — die Anfrage kam mit einem API-Schlüssel, der nur lesen darf. */
    private bool $readOnlyKey = false;

    /**
     * Angemeldet: Anmeldung aus, gültiges Sitzungs-Cookie, API-Schlüssel
     * (Bearer etk_…) oder — im Proxy-Modus — ein Benutzer vom
     * vertrauenswürdigen Proxy.
     */
    private function authenticate(Request $req): bool
    {
        $mode = $this->auth->mode();
        if ($mode === 'off') return true;
        if ($this->auth->validSession($_COOKIE[AuthService::COOKIE] ?? null)) return true;
        $scope = $this->auth->apiKeyScope($req->bearerToken());
        if ($scope !== null) {
            $this->readOnlyKey = $scope === 'read';
            return true;
        }
        return $mode === 'proxy' && $this->auth->proxyUser($_SERVER) !== null;
    }

    /**
     * Ohne Anmeldung erreichbar: Ingest (eigener Token), Health (Minimalform),
     * die Anmeldung selbst.
     */
    private static function isPublic(Request $req): bool
    {
        return ($req->method === 'POST' && $req->path === '/api/ingest')
            || (in_array($req->method, ['GET', 'HEAD'], true) && $req->path === '/api/health')
            || ($req->path === '/api/session' && in_array($req->method, ['GET', 'POST', 'DELETE'], true))
            || $req->method === 'OPTIONS';
    }

    /** ET_ALLOWED_HOSTS: Kommaliste, `*.example.org` als Platzhalter. Leer = alle. */
    public static function hostAllowed(string $hostHeader): bool
    {
        $list = array_filter(array_map(fn($h) => strtolower(trim($h)), explode(',', (string)getenv('ET_ALLOWED_HOSTS'))));
        if ($list === []) return true;
        $host = strtolower($hostHeader);
        if (preg_match('/^\[(.+)\](:\d+)?$/', $host, $m)) $host = $m[1];              // [IPv6]:port
        elseif (substr_count($host, ':') === 1) $host = explode(':', $host)[0];         // name:port
        if ($host === '' ) return false;
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
        foreach ($list as $allowed) {
            if ($allowed === $host) return true;
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) return true;
        }
        return false;
    }

    private function registerRoutes(): void
    {
        $r = $this->router;

        // ── Utilities listing ──
        $utilCtrl = new UtilitiesController($this->i18n);
        $r->get('/api/utilities', fn($req) => $utilCtrl->index($req));

        // ── Meters per utility ──
        $meterCtrl = new MeterController($this->meters, $this->i18n);
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
        $contractCtrl = new ContractController($this->contracts, $this->i18n);
        $r->get('/api/utility/{utility}/contracts',         fn($req) => $contractCtrl->index($req));
        $r->post('/api/utility/{utility}/contracts',        fn($req) => $contractCtrl->create($req));
        $r->get('/api/utility/{utility}/contracts/{id}',    fn($req) => $contractCtrl->show($req));
        $r->patch('/api/utility/{utility}/contracts/{id}',  fn($req) => $contractCtrl->update($req));
        $r->delete('/api/utility/{utility}/contracts/{id}', fn($req) => $contractCtrl->destroy($req));

        // ── Consumption (monthly aggregates) ──
        $cCtrl = new ConsumptionController($this->consumption, $this->anomalies, $this->meters, $this->regression, $this->settings, $this->i18n);
        $r->get('/api/utility/{utility}/consumption',              fn($req) => $cCtrl->utility($req));
        $r->get('/api/utility/{utility}/meters/{id}/consumption',  fn($req) => $cCtrl->meter($req));
        $r->get('/api/utility/{utility}/meters/{id}/contract-status', fn($req) => $cCtrl->contractStatus($req));
        // v2.5.0 — F1012: Rechnungsprüfung (nur Gas)
        $r->get('/api/utility/{utility}/meters/{id}/bill-check',      fn($req) => $cCtrl->billCheck($req));

        // ── Forecast ──
        $fCtrl = new ForecastController($this->forecasts, $this->meters, $this->i18n);
        $r->get('/api/utility/{utility}/meters/{id}/forecast', fn($req) => $fCtrl->forMeter($req));

        // ── Temperatures ──
        $tCtrl = new TemperatureController($this->temperatures, $this->i18n);
        $r->get('/api/temperatures',                  fn($req) => $tCtrl->index($req));
        $r->post('/api/temperatures',                 fn($req) => $tCtrl->upsert($req));
        $r->post('/api/temperatures/import-csv',      fn($req) => $tCtrl->importCsv($req));
        $r->post('/api/temperatures/sync-open-meteo', fn($req) => $tCtrl->syncOpenMeteo($req));
        $r->delete('/api/temperatures/{date}',        fn($req) => $tCtrl->delete($req));

        // ── Settings / Backup / Diagnostics ──
        $sCtrl = new SettingsController($this->settings);
        $r->get('/api/settings',   fn($req) => $sCtrl->index($req));
        $r->patch('/api/settings', fn($req) => $sCtrl->update($req));
        $r->get('/api/countries',  fn($req) => $sCtrl->countries($req));   // v2.7.0 — Länderprofile

        $bCtrl = new BackupController($this->backups);
        $r->get('/api/backup/export',     fn($req) => $bCtrl->export($req));
        $r->post('/api/backup/import',    fn($req) => $bCtrl->import($req));
        $r->post('/api/backup/snapshot',  fn($req) => $bCtrl->snapshot($req));
        // v2.6.0 — Snapshots verwalten (bisher nur anlegen)
        $r->get('/api/backup/snapshots',                  fn($req) => $bCtrl->listSnapshots($req));
        $r->get('/api/backup/snapshots/{name}',           fn($req) => $bCtrl->downloadSnapshot($req));
        $r->post('/api/backup/snapshots/{name}/restore',  fn($req) => $bCtrl->restoreSnapshot($req));
        $r->delete('/api/backup/snapshots/{name}',        fn($req) => $bCtrl->deleteSnapshot($req));

        // ── CSV-Export (F-07) ──
        $exCtrl = new ExportController($this->csvExport);
        $r->get('/api/export/temperatures.csv',          fn($req) => $exCtrl->temperatures($req));
        $r->get('/api/export/{utility}/monthly.csv',     fn($req) => $exCtrl->monthly($req));
        $r->get('/api/export/{utility}/readings.csv',    fn($req) => $exCtrl->readings($req));
        $r->get('/api/export/{utility}/deliveries.csv',  fn($req) => $exCtrl->deliveries($req));

        // ── Migration aus v0.9.0 ──
        $mgCtrl = new MigrationController($this->migrationLegacy, $this->i18n);
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

        // ── Wechselentscheidung (v2.3.0 — Prognose gegen Angebot) ──
        $tsCtrl = new TariffSwitchController($this->tariffSwitch);
        $r->get('/api/utility/{utility}/meters/{id}/tariff-switch',
                                              fn($req) => $tsCtrl->analyze($req));

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
        $repCtrl = new ReportController($this->reports, $this->i18n);
        $r->get('/api/reports/yearly.pdf', fn($req) => $repCtrl->yearly($req));

        // ── F1005 (v1.7.0) — Strom-Saldo + PV-Summary ──
        $saldoCtrl = new StromSaldoController($this->stromSaldo);
        $r->get('/api/strom-saldo', fn($req) => $saldoCtrl->index($req));
        $pvCtrl = new PvSummaryController($this->pvSummary);
        $r->get('/api/pv-summary',  fn($req) => $pvCtrl->index($req));

        // ── N1003 (v1.7.0) — Health-Check ──
        // v2.6.0 — mit Anmeldung sehen Nicht-Angemeldete nur {status, version}
        $hCtrl = new HealthController($this->health, fn(): bool => $this->authenticated);
        $r->get('/api/health', fn($req) => $hCtrl->index($req));

        // ── v2.6.0 — Anmeldung (opt-in) und API-Schlüssel ──
        $sessCtrl = new SessionController($this->auth, $this->i18n, fn(): bool => $this->authenticated);
        $r->get('/api/session',              fn($req) => $sessCtrl->status($req));
        $r->post('/api/session',             fn($req) => $sessCtrl->login($req));
        $r->delete('/api/session',           fn($req) => $sessCtrl->logout($req));
        $r->post('/api/session/password',    fn($req) => $sessCtrl->setPassword($req));
        $r->delete('/api/session/password',  fn($req) => $sessCtrl->disable($req));
        $r->get('/api/auth/keys',            fn($req) => $sessCtrl->listKeys($req));
        $r->post('/api/auth/keys',           fn($req) => $sessCtrl->createKey($req));
        $r->delete('/api/auth/keys/{id}',    fn($req) => $sessCtrl->revokeKey($req));

        // ── F1007 (v1.7.4) — Demo-Daten-Komfort-Import ──
        $demoCtrl = new DemoController($this->demo);
        $r->get('/api/demo/status',  fn($req) => $demoCtrl->status($req));
        $r->post('/api/demo/import', fn($req) => $demoCtrl->import($req));

        // ── F1009 — Home-Assistant-Anbindung ──
        $authCtrl = new AuthController($this->auth, $this->i18n);
        $r->get('/api/auth/token',    fn($req) => $authCtrl->status($req));
        $r->post('/api/auth/token',   fn($req) => $authCtrl->generate($req));
        $r->delete('/api/auth/token', fn($req) => $authCtrl->revoke($req));
        $ingestCtrl = new IngestController($this->ingest, $this->auth, $this->i18n);
        $r->post('/api/ingest',       fn($req) => $ingestCtrl->store($req));
    }
}
