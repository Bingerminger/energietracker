<?php
declare(strict_types=1);

namespace Energietracker;

use Energietracker\Http\CrossSiteGuard;
use Energietracker\Http\ErrorHandler;
use Energietracker\Http\HostList;
use Energietracker\Http\HttpStatusException;
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
    ReadingImportService, CsvExportService,
    DeliveryService, DeliveryConsumptionService, BenchmarkService, ConversionFactorService,
    TariffComparisonService, TariffSwitchService,
    RecommendationService, ReminderService, PdfReportService,
    StromSaldoService, PvSummaryService, HealthCheckService, DemoService,
    AuthService, IngestService, I18nService, ClimateNormalService,
    AgendaService, CalendarService, SummaryService, InstanceService,
    AttachmentService, OcrService, PeriodService, TenancyService, TenancyBudgetService,
    Co2CostService, Co2SplitService, BillService, MarketPriceService, EvChargingReportService, HeatPumpService,
    SeriesImportService, ReferenceService, EvccService, IntervalService, FlexibilityService,
    DocsService, ReportModelService, ReportAdviceService, ActivityService, BillExpectedService,
    BillingPeriodService
};
use Energietracker\Controllers\{
    MeterController, ReadingController, ContractController,
    ConsumptionController, ForecastController, TemperatureController,
    SettingsController, BackupController, DiagnosticsController, UtilitiesController,
    ExportController,
    DeliveryController, BenchmarkController, TariffComparisonController,
    TariffSwitchController,
    RecommendationController, ReminderController, ReportController,
    StromSaldoController, PvSummaryController, HealthController, DemoController,
    AuthController, IngestController, SessionController, ManifestController, AgendaController,
    AttachmentController, PeriodController, TenancyController, Co2Controller, BillController, MarketPriceController, EvChargingController, HeatPumpController,
    SeriesImportController, EvccController, FlexibilityController, DocsController, ActivityController
};
use Energietracker\Services\EnergyFlowDayService;        // v3.5.0 (F1027)
use Energietracker\Controllers\EnergyFlowController;     // v3.5.0 (F1027)
use Energietracker\Controllers\UpdateCheckController;    // v3.6.0
use Energietracker\Services\ScenarioService;             // v3.6.0 (F1031)
use Energietracker\Controllers\ScenarioController;       // v3.6.0 (F1031)
use Energietracker\Services\FlexibilityInsightService;   // v3.6.0 — Flexibilität: Ampel, Hebel, Zeitfenster
use Energietracker\Services\InboxService;                // v3.7.0 — Posteingang für Versorgerpost
use Energietracker\Services\Inbox\{ProviderRegistry, LocalProvider, Proposals as InboxProposals, Applier as InboxApplier};
use Energietracker\Controllers\InboxController;          // v3.7.0

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
    public ClimateNormalService $climate;   // v2.8.0
    public RegressionService $regression;
    public ForecastService $forecasts;
    public AnomalyService $anomalies;
    public BackupService $backups;
    public DiagnosticsService $diagnostics;
    public ReadingImportService $readingImport;
    public CsvExportService $csvExport;
    public DeliveryService $deliveries;
    public DeliveryConsumptionService $deliveryConsumption;
    public ConversionFactorService $factors;   // v2.5.0 — F1012
    public BenchmarkService $benchmark;
    public TariffComparisonService $tariffs;
    public TariffSwitchService $tariffSwitch;
    public \Energietracker\Services\Inbox\FolderIntake $inboxFolder;   // v3.7.0 (Paket betrieb) — überwachter Ordner
    public \Energietracker\Services\DossierService $dossier;           // v3.7.0 (Paket betrieb) — Akte je Zähler und Vertrag
    public RecommendationService $recommendations;
    public ReminderService $reminders;
    public PdfReportService $reports;
    public StromSaldoService $stromSaldo;
    public PvSummaryService $pvSummary;
    public HealthCheckService $health;
    public DemoService $demo;
    public AuthService $auth;
    public \Energietracker\Services\PeopleService $people;   // v3.6.0 — Personenverzeichnis
    public IngestService $ingest;
    public I18nService $i18n;
    public InstanceService $instance;   // v3.1.0
    public \Energietracker\Services\HomeAssistantNotifyService $haNotify;   // v3.9.0 (F1033) — Benachrichtigungen an Home Assistant
    public AgendaService $agenda;       // v3.1.0 (H1, B5)
    public CalendarService $calendar;   // v3.1.0 (H1, MKT-09)
    public SummaryService $summary;     // v3.1.0 (H1, API-33)
    public AttachmentService $attachments;   // v3.1.0 (H2, B1)
    public OcrService $ocr;                  // v3.1.0 (H2, MKT-08)
    public PeriodService $periods;           // v3.1.0 (H3, B2)
    public TenancyService $tenancies;        // v3.1.0 (H3, F1008)
    public TenancyBudgetService $tenancyBudget;
    public Co2CostService $co2Costs;         // v3.1.0 (H4, CALC-27)
    public Co2SplitService $co2Split;        // v3.1.0 (H4, MKT-15)
    public BillService $bills;               // v3.1.0 (H5, B3/UI-35)
    public MarketPriceService $marketPrices; // v3.1.0 (H6, B7/MKT-12)
    public EvChargingReportService $evCharging; // v3.1.0 (H6, MKT-14)
    public HeatPumpService $heatPumps;      // v3.1.0 (H7, MKT-18)
    public SeriesImportService $seriesImport; // v3.1.0 (H8, MKT-19)
    public ReferenceService $references;    // v3.1.0 (H8, MKT-11)
    public EvccService $evcc;               // v3.2.0 (F1022)
    public IntervalService $intervals;      // v3.3.0 (F1021)
    public FlexibilityService $flexibility; // v3.3.0 (F1021)
    public FlexibilityInsightService $flexInsights; // v3.6.0 — Ampel, Hebel, Zeitfenster
    public EnergyFlowDayService $energyFlowDay; // v3.5.0 (F1027)
    public ReportModelService $reportModel; // v3.4.0 (F1025)
    public ActivityService $activity;       // v3.4.0 (F1024)
    public BillExpectedService $billsExpected; // v3.5.0 (F1029)
    public BillingPeriodService $billingPeriods; // v3.6.0 (F1030)
    public ScenarioService $scenarios;           // v3.6.0 (F1031) — Prognose-Werkstatt
    public \Energietracker\Services\MeteringConceptService $meteringConcepts;   // v3.9.0 (F1032) — Assistent „Messkonzept“
    public \Energietracker\Services\InboxDraftService $inboxDraft;   // v3.7.0 — Haushalt aus Dokumenten
    public \Energietracker\Services\ResponsibilityService $responsibility;   // v3.7.0 — Zuständig an Zählern und Verträgen
    public InboxService $inbox;                  // v3.7.0 — Posteingang
    public ProviderRegistry $inboxProviders;     // v3.7.0 — Anbieter fürs Auslesen (lokal; Cloud meldet sich an)
    // ── v3.7.0 (Paket cloud) — Auslesen in der Cloud, Opt-in ──
    public \Energietracker\Services\Inbox\CloudConfig $inboxCloud;
    public \Energietracker\Services\Inbox\AnthropicProvider $inboxAnthropic;
    public \Energietracker\Services\Inbox\OpenAiCompatibleProvider $inboxOpenAi;

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
        // v2.8.0 — Klimanormal (CALC-30): vom Temperatur-Sync geholt, von
        // Prognose, Wetterbereinigung, Saldo und (v2.10.0) Tankbuch gelesen.
        $this->climate      = new ClimateNormalService($this->store, $this->settings);
        $this->deliveryConsumption = new DeliveryConsumptionService($this->store, $this->settings, $this->climate);
        // v2.5.0 — F1012: datierte Gas-Umrechnungsfaktoren (Zustandszahl × Brennwert)
        $this->factors      = new ConversionFactorService($this->settings, $this->i18n);
        $this->consumption  = new ConsumptionService(
            $this->store, $this->meters, $this->readings, $this->contracts, $this->settings,
            $this->i18n, $this->regression, $this->deliveryConsumption, $this->factors
        );
        $this->weather      = new WeatherService();
        $this->temperatures = new TemperatureService($this->store, $this->settings, $this->weather, $this->climate, $this->i18n);
        $this->forecasts    = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
        $this->anomalies    = new AnomalyService($this->regression, $this->settings);
        $this->backups      = new BackupService($this->store, $this->i18n);
        // v3.4.0 (N1020) — dazu die Sicherheitshinweise (Proxy-Kopfzeile, Hostliste)
        $this->diagnostics  = new DiagnosticsService($this->store, $this->settings, fn(): array => $this->securityStatus());
        $this->readingImport = new ReadingImportService($this->readings, $this->meters, $this->i18n);
        $this->deliveries   = new DeliveryService($this->store, $this->meters, $this->i18n);
        $this->csvExport    = new CsvExportService(
            $this->consumption, $this->readings, $this->meters, $this->temperatures, $this->deliveries, $this->i18n
        );
        $this->benchmark    = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n, $this->climate);
        // v3.3.0 (F1021) — Werte je Viertelstunde und was das Verschieben gebracht hat
        $this->intervals    = new IntervalService($this->store, $this->settings);
        $this->flexibility  = new FlexibilityService($this->intervals, $this->meters, $this->contracts);
        $this->flexInsights = new FlexibilityInsightService($this->intervals, $this->meters, $this->contracts, $this->flexibility, $this->settings);
        $this->energyFlowDay = new EnergyFlowDayService($this->intervals, $this->meters);   // v3.5.0 (F1027) — Tagesfilm
        $this->marketPrices = new MarketPriceService($this->store, $this->i18n, null, $this->intervals);   // v3.1.0 (H6)
        $this->tariffs      = new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n, $this->marketPrices, $this->flexibility);
        $this->tariffSwitch = new TariffSwitchService($this->forecasts, $this->contracts, $this->meters, $this->i18n, $this->marketPrices);
        $this->recommendations = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $this->benchmark, $this->deliveries, $this->i18n);
        $this->reminders    = new ReminderService($this->store, $this->settings, $this->i18n);
        // F1005 + N1003 (v1.7.0)
        $this->stromSaldo   = new StromSaldoService($this->consumption);
        $this->pvSummary    = new PvSummaryService($this->consumption, $this->settings);
        $this->health       = new HealthCheckService($this->store);
        // F1007 (v1.7.4)
        $this->demo         = new DemoService($this->store, $this->backups, $this->i18n);
        // F1009 — HA-Anbindung: Token-Auth + idempotenter Push-Ingest.
        $this->auth         = new AuthService($this->store);
        // v3.6.0 — Personenverzeichnis: fortlaufende Nummer, Löschvermerk, im Backup
        $this->people       = new \Energietracker\Services\PeopleService($this->store);
        $this->people->attachAccounts(fn(): array => $this->auth->accounts());
        $this->auth->attachIdAdoption(fn(string $name): ?string => $this->people->adoptableId($name));
        $this->backups->beforeExport(fn() => $this->people->all());
        $this->ingest       = new IngestService($this->meters, $this->readings, $this->i18n, $this->store, $this->intervals);
        // v3.1.0 (Paket H1) — Agenda, Kalender, Kennzahlen für Home Assistant
        $this->instance     = new InstanceService($this->store);
        // v3.1.0 (Paket H3) — Verbrauch je Zeitraum, Mietverhältnis
        $this->periods      = new PeriodService($this->store, $this->meters, $this->i18n);
        $this->tenancies    = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->tenancyBudget = new TenancyBudgetService($this->tenancies, $this->meters, $this->consumption, $this->i18n);
        // v3.1.0 (Paket H4) — CO₂-Preis im Brennstoff, Aufteilung Mieter/Vermieter
        $this->co2Costs     = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption,
            $this->contracts, $this->i18n, $this->tenancies);
        $this->co2Split     = new Co2SplitService($this->co2Costs, $this->tenancies, $this->settings, $this->i18n);
        $this->evCharging   = new EvChargingReportService($this->meters, $this->readings, $this->consumption, $this->settings, $this->i18n);
        $this->heatPumps    = new HeatPumpService($this->meters, $this->consumption);
        // v3.1.0 (Paket H8) — Zeitreihen aus Portalen, Einordnung mit eigenen Vergleichswerten
        $this->seriesImport = new SeriesImportService($this->readingImport, $this->readings, $this->meters, $this->periods, $this->settings, $this->i18n, $this->intervals);
        $this->references   = new ReferenceService($this->consumption, $this->meters, $this->settings);
        // v3.1.0 (Paket H5) — Versorgerrechnungen
        $this->bills        = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->agenda       = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminders, $this->recommendations, $this->i18n, $this->tenancies, $this->co2Split);
        $this->calendar     = new CalendarService($this->agenda, $this->settings, $this->instance, $this->i18n);
        $this->summary      = new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            $this->forecasts, $this->deliveries, $this->pvSummary, $this->stromSaldo, $this->agenda, $this->instance, $this->i18n);
        // v3.1.0 (Paket H2) — Belege und Texterkennung im Heimnetz
        $this->attachments  = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->ocr          = new OcrService($this->settings, $this->attachments);
        // v3.2.0 (F1022) — Ladevorgänge aus evcc (CSV oder Abruf im Heimnetz)
        $this->evcc         = new EvccService($this->store, $this->meters, $this->readings, $this->readingImport, $this->settings);
        $this->flexInsights->attachSessions($this->evcc);   // v3.6.0 — Ladefenster des Autos aus den Steckzeiten
        // v3.4.0 (F1025) — Jahresbericht für alle: ein Datenmodell für PDF und
        // Druckansicht, Empfehlungen mit Priorität, € und Frist
        $this->reportModel  = new ReportModelService($this->meters, $this->consumption, $this->contracts, $this->settings, $this->i18n,
            $this->benchmark, $this->recommendations, new ReportAdviceService($this->i18n), $this->agenda, $this->pvSummary,
            $this->heatPumps, $this->flexibility, $this->evcc, $this->evCharging, $this->tenancies, $this->tenancyBudget,
            $this->co2Split, $this->bills, $this->tariffSwitch, $this->deliveries, $this->forecasts);
        $this->reports      = new PdfReportService($this->meters, $this->consumption, $this->settings, $this->benchmark,
            $this->recommendations, $this->i18n, $this->reportModel);
        // v3.4.0 (F1024) — Erledigt mit Verlauf: wer hat einen Hinweis wann
        // erledigt. Die Dienste, die Hinweise erledigen (von Hand oder durch
        // neue Daten), schreiben dorthin; Rückgängig stellt über sie zurück.
        $this->activity     = new ActivityService($this->store, $this->settings, $this->i18n, fn(): ?array => $this->actor());
        foreach ([$this->readings, $this->deliveries, $this->tenancies, $this->contracts, $this->reminders,
                  $this->recommendations, $this->agenda] as $svc) $svc->attachActivity($this->activity);
        $this->activity->attach($this->reminders, $this->contracts, $this->recommendations);
        $this->activity->setTankProbe(fn(string $u, string $m): bool => $this->recommendations->tankLow($u, $m));
        // v3.5.0 — Zuständig an Terminen (F1028), Abrechnung erwartet (F1029),
        // Verlauf auch für Zeiträume und geänderte Abrechnungen (Nachtrag F1024)
        // v3.6.0 — aus dem Personenverzeichnis (auch gelöschte, `deleted: true`);
        // der Verwalter aus der Zeit vor v3.2 steht nur in auth.json
        $findPerson = function (string $id): ?array {
            $p = $this->people->find($id);
            if ($p !== null) return $p;
            $u = $this->auth->userById($id);
            return $u === null ? null : ['id' => (string)$u['id'], 'name' => (string)($u['name'] ?? '')];
        };
        $this->reminders->attachPeople($findPerson);
        // v3.7.0 — Zuständig an Zählern und Verträgen; Termine und Fristen erben
        // (eigene → Vertrag → Zähler), Kalender je Person
        $this->responsibility = new \Energietracker\Services\ResponsibilityService($this->store);
        $this->responsibility->attachPeople($findPerson);
        foreach ([$this->meters, $this->contracts, $this->reminders, $this->agenda, $this->calendar] as $svc) {
            $svc->attachResponsibility($this->responsibility);
        }
        $this->periods->attachActivity($this->activity);
        $this->billsExpected = new BillExpectedService($this->settings, $this->meters, $this->contracts,
            $this->readings, $this->periods, $this->bills);
        $this->bills->attachActivity($this->activity);
        $this->bills->attachExpectations($this->billsExpected);
        $this->agenda->attachBillExpectations($this->billsExpected);
        // v3.6.0 — Beispieldaten: gewanderte Versorgerrechnungen nachrechnen
        $this->demo->attachBills($this->bills);
        // v3.6.0 (F1030, #33) — Abrechnungsperioden: eine Quelle für Ansichten, Bericht und CSV
        $this->billingPeriods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills);
        $this->csvExport->attachBillingPeriods($this->billingPeriods);
        $this->reportModel->attachBillingPeriods($this->billingPeriods);
        $this->reportModel->attachFlexInsights($this->flexInsights);   // v3.6.0 — Seite „Flexibilität“
        // v3.6.0 (F1031) — Prognose-Werkstatt: Szenarien auf der Prognose, liest nur
        $this->scenarios = new ScenarioService($this->forecasts, $this->consumption, $this->contracts, $this->meters,
            $this->settings, $this->tariffSwitch, $this->billingPeriods);
        // v3.9.0 (F1032, #40) — Assistent „Messkonzept“: Zähler, Gruppen, Subzähler, Verträge aus dem Aufbau
        $this->meteringConcepts = new \Energietracker\Services\MeteringConceptService($this->meters, $this->contracts, $this->settings, $this->i18n, $this->backups);
        // v3.7.0 — Posteingang: lokal über die Einstellung der Texterkennung;
        // weitere Anbieter melden sich mit $this->inboxProviders->register() an
        $this->inboxProviders = new ProviderRegistry($this->settings, new LocalProvider($this->settings, $this->ocr, $this->i18n));
        $inboxProposals = new InboxProposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $this->attachments, $this->inboxProviders,
            $inboxProposals,
            new InboxApplier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $this->inbox->attachActivity($this->activity);
        // v3.7.0 (Paket betrieb) — Kernaussage je Dokument, überwachter Ordner, Akte
        $inboxProposals->attachResponsibility($this->responsibility);
        $this->inbox->attachGist(new \Energietracker\Services\Inbox\Gist($this->meters, $this->contracts, $this->consumption,
            $this->bills, $this->readings, $this->tariffSwitch, $this->responsibility));
        $this->inboxFolder = new \Energietracker\Services\Inbox\FolderIntake($this->store, $this->inbox);
        $this->dossier = new \Energietracker\Services\DossierService($this->inbox, $this->attachments, $this->bills,
            $this->contracts, $this->meters, $this->readings, $this->i18n);
        // v3.7.0 (Haushalt aus Dokumenten) — ein Entwurf über alle ausgelesenen Dokumente
        $this->inboxDraft = new \Energietracker\Services\InboxDraftService($this->inbox,
            new \Energietracker\Services\Inbox\HouseholdDraft($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n, $inboxProposals),
            new \Energietracker\Services\Inbox\DraftApplier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $this->inboxDraft->attach($this->backups, $this->activity);

        // ── v3.7.0 (Paket cloud) — Auslesen in der Cloud (Opt-in) ───────────
        // Zwei Anschlüsse am Posteingang, Auswahl über `inbox_provider`. Sie
        // senden nur eingeschaltet, gewählt und mit Schlüssel
        // (CloudConfig::allows); der Schlüssel liegt in inbox_cloud_key.json,
        // nicht im Backup. Die Registry legt der Posteingang an, hier wird nur
        // angemeldet.
        $this->inboxCloud     = new \Energietracker\Services\Inbox\CloudConfig($this->settings, $this->store);
        $this->inboxAnthropic = new \Energietracker\Services\Inbox\AnthropicProvider($this->inboxCloud, $this->i18n);
        $this->inboxOpenAi    = new \Energietracker\Services\Inbox\OpenAiCompatibleProvider($this->inboxCloud, $this->i18n);
        $this->inboxProviders->register($this->inboxOpenAi);
        $this->inboxProviders->register($this->inboxAnthropic);
        // ── Ende v3.7.0 (Paket cloud) ──

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
        // v3.4.0 (N1020) — jedes Schreiben holt die Sperre (mit Frist), auch
        // das eines Abgleichs, der erst nach dem Abruf sperrt (s. LOCK_LATE)
        $this->store->setWriteGuard(fn() => $this->lockOrFail());
        $migrator = new Migrator($this->store, $this->i18n);
        if ($migrator->isPristine() || $migrator->needsMigration() || !$migrator->isAlreadyMigrated()) {
            $this->lockOrFail();
            try {
                // v2.7.0 — Erststart (I18N-01): Sprache und Land aus dem Browser,
                // BEVOR die Standard-Zähler ihren Namen bekommen. Bis v2.6.0
                // begrüßte jede Neuinstallation auf Deutsch und schrieb
                // „Hauptzähler" dauerhaft in die Daten.
                $firstStart = $migrator->isPristine() ? $this->firstStartChoice($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) : null;
                if ($firstStart !== null) $this->i18n->setLocale($firstStart['language']);
                $result = $migrator->runOnStartup(fn(string $from): string => $this->backups->saveSnapshot(
                    'pre-migration-' . preg_replace('/[^0-9A-Za-z.]/', '', $from) . '_'
                ));
                // v3.2.0 (F1018) — eine Neuinstallation beginnt mit dem
                // Einrichtungsassistenten; nach einem Update nie
                if ($firstStart !== null && ($result['action'] ?? null) === 'fresh') {
                    // v3.5.0 (N1022) — das Land über dieselbe Regel wie Einrichtung
                    // und Einstellungen (SettingsService::applyCountry)
                    $this->settings->applyCountry($firstStart['country']);
                    $lang = SettingsService::isDefault('language', $firstStart['language']) ? [] : ['language' => $firstStart['language']];
                    $this->settings->set($lang + ['setup_pending' => true]);
                }
                $this->logStartup($result, $dataDir);
            } finally {
                $this->writeLock->release();
            }
        }

        // ── v3.9.0 (F1033, #36) — Benachrichtigungen an Home Assistant (Opt-in) ──
        // Webhook-ID und Token in ha_notify_secret.json (nicht im Backup, nie im
        // Browser); gesendet wird nach der Antwort (afterResponse)
        $this->haNotify = new \Energietracker\Services\HomeAssistantNotifyService(
            new \Energietracker\Services\HomeAssistant\NotifyConfig($this->settings, $this->store),
            new \Energietracker\Services\HomeAssistant\NotifyTransport(),
            $this->store, $this->i18n, $this->instance,
            fn(int $days, string $today): array => $this->agenda->events($days, $today),
            fn(): array => $this->recommendations->all(),
            fn(): array => $this->inbox->all());
        $this->inbox->onUpload(fn(array $e) => $this->haNotify->documentArrived($e));

        $this->router = new Router();
        $this->registerRoutes();
    }

    /** v2.5.3 — eine Sperre je schreibender Anfrage, s. Storage\WriteLock */
    private WriteLock $writeLock;

    /**
     * v3.4.0 (N1020) — Schreibsperre holen; ist sie innerhalb der Frist nicht
     * frei, 503 mit `Retry-After` statt unbegrenzt zu warten. Ein nicht
     * beschreibbares Datenverzeichnis meldet das Schreiben selbst.
     */
    private function lockOrFail(): void
    {
        if ($this->writeLock->acquire() || !$this->writeLock->timedOut()) return;
        throw new HttpStatusException(503, 'errors.http.busy', [], ['Retry-After' => '5'], 'write lock busy');
    }

    /**
     * v3.4.0 (N1020) — Abgleiche mit Diensten außerhalb (Texterkennung,
     * Wetter, SMARD, evcc) sperren nicht vorab: Die Texterkennung schreibt gar
     * nichts, die übrigen holen erst die Daten und sperren dann für das
     * Schreiben (JsonStore::lock()). Bis v3.3 hielt jede dieser Anfragen die
     * Sperre für die ganze Wartezeit auf den fremden Dienst — bis zu fünf
     * Minuten, in denen kein Zählerstand gespeichert werden konnte.
     */
    private const LOCK_LATE = [
        '#^/api/ocr/reading$#',
        '#^/api/temperatures/sync-open-meteo$#',
        '#^/api/market-prices/sync-smard(-intervals)?$#',
        '#^/api/utility/strom/meters/[^/]+/sync-evcc$#',
        '#^/api/forecast/scenario$#',   // v3.6.0 (F1031) — schreibt nichts
        '#^/api/inbox/[^/]+/(classify|extract)$#',   // v3.7.0 — Posteingang: erst die KI, dann schreiben
        '#^/api/metering-concept/preview$#',   // v3.9.0 (F1032) — Vorschau, schreibt nichts
    ];

    /**
     * v3.6.0 (F1031) — POST-Routen, die nur rechnen und nichts schreiben: auch
     * mit einem API-Schlüssel erlaubt, der nur lesen darf.
     */
    private const READ_ONLY_POST = ['#^/api/forecast/scenario$#', '#^/api/metering-concept/preview$#'];   // v3.9.0 — Vorschau des Messkonzepts

    public static function locksLate(Request $req): bool
    {
        if ($req->method !== 'POST') return false;
        foreach (self::LOCK_LATE as $re) if (preg_match($re, $req->path)) return true;
        return false;
    }

    public static function readOnlyPost(Request $req): bool
    {
        if ($req->method !== 'POST') return false;
        foreach (self::READ_ONLY_POST as $re) if (preg_match($re, $req->path)) return true;
        return false;
    }

    /**
     * v3.4.0 (N1020) — Größe des Körpers. Ohne Anmeldung erreichbare Routen
     * (Ingest, Anmeldung) höchstens 1 MB; Backup-Import, Belege und die
     * CSV-/Zeitreihen-Importe bis 256 MB; alles andere 8 MB. Dieselbe Liste
     * steht in docker/nginx.conf (WebrootRulesTest gleicht ab).
     */
    public const BODY_PUBLIC = 1048576;
    public const BODY_DEFAULT = 8 * 1048576;
    public const BODY_LARGE = 256 * 1048576;
    public const LARGE_BODY_ROUTES = '#^/api/(backup/import|attachments|inbox|.+/import-(csv|series|evcc))$#';   // v3.7.0 — inbox: PDF ablegen

    public static function bodyLimit(Request $req): int
    {
        if (self::isPublic($req)) return self::BODY_PUBLIC;
        return preg_match(self::LARGE_BODY_ROUTES, $req->path) ? self::BODY_LARGE : self::BODY_DEFAULT;
    }

    /**
     * v2.7.0 — Sprache und Land einer Neuinstallation aus Accept-Language:
     * Sprache (wie negotiate()), Land (Region im Header, sonst aus der
     * Sprache). Geschrieben werden danach die Werte des Länderprofils — nur,
     * was vom Default abweicht (v3.5.0: SettingsService::applyCountry, dieselbe
     * Regel wie im Einrichtungsassistenten). So greifen spätere Korrekturen der
     * Defaults auch bei neuen deutschen Installationen. Ohne Header (CLI,
     * Tests) bleibt alles deutsch.
     *
     * @return array{language:string,country:string}
     */
    private function firstStartChoice(?string $acceptLanguage): array
    {
        $language = $this->i18n->negotiate($acceptLanguage) ?? I18nService::DEFAULT_LOCALE;
        $country  = Countries::fromAcceptLanguage($acceptLanguage) ?? Countries::forLanguage($language);
        return ['language' => $language, 'country' => $country];
    }

    /**
     * v2.6.0 — Schemaversion der Daten, wenn sie von einer NEUEREN App-Version
     * stammen. Dann antwortet jede Route außer /api/health mit 503, und nichts
     * wird geschrieben (s. Migrator::dataSchemaIsNewer()).
     */
    private ?string $dataTooNew = null;

    /**
     * v3.5.0 (N1024) — Das Datenverzeichnis ist nicht beschreibbar, und die App
     * müsste beim Start anlegen oder migrieren. Wie bei $dataTooNew antwortet
     * nur /api/health (503, `data_dir_writable: false`), alle anderen Routen
     * mit 503 und einer Meldung statt 500.
     */
    private bool $dataUnwritable = false;

    /** @param array<string,mixed>|null $result aus Migrator::runOnStartup() */
    private function logStartup(?array $result, string $dataDir): void
    {
        if ($result === null) return;
        if ($result['action'] === 'unwritable') {
            $this->dataUnwritable = true;
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            $gid = function_exists('posix_getegid') ? posix_getegid() : null;
            $this->logger->error('Datenverzeichnis nicht beschreibbar — Rechte am Volume anpassen', [
                'data_dir' => $dataDir,
                'uid'      => $uid,
                'gid'      => $gid,
                'hint'     => $uid !== null ? "auf dem Host: chown -R $uid:$gid <Datenordner>" : null,
            ]);
            return;
        }
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

        // Sprache der serverseitigen Texte, in dieser Reihenfolge:
        // 1. v3.1.0 (Review I18N-29) — `X-ET-Language`: die Sprache, die die
        //    Oberfläche auf diesem Gerät zeigt (api.js schickt sie bei jeder
        //    Anfrage). Seit die Sprache pro Gerät gilt, kann sie von der
        //    Einstellung abweichen; Bezeichnungen und Meldungen folgen ihr.
        // 2. N1007 — das `language`-Setting, die Standardsprache der Installation.
        //    Es gilt für alles ohne Header: Downloads wie PDF und CSV (laufen als
        //    Browser-Navigation, deren Accept-Language die Browsersprache ist),
        //    Home Assistant, Skripte.
        // 3. Accept-Language, solange kein gültiges Setting vorliegt.
        $device = $this->i18n->normalize($_SERVER['HTTP_X_ET_LANGUAGE'] ?? null);
        $langSetting = (string)$this->settings->get('language', '');
        if ($device !== null) {
            $this->i18n->setLocale($device);
        } elseif (in_array($langSetting, $this->i18n->supported(), true)) {
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
        // v3.4.0 (N1020) — dazu die Einstellung `allowed_hosts` (Apache und
        // Synology haben keine Umgebungsvariablen)
        if (!self::hostAllowed((string)($_SERVER['HTTP_HOST'] ?? ''), (string)$this->settings->get('allowed_hosts', ''))) {
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
        // v3.5.0 (N1024) — Datenverzeichnis nicht beschreibbar: nur /api/health antwortet.
        if ($this->dataUnwritable && $req->path !== '/api/health') {
            Response::error($this->i18n->t('errors.storage.notWritable'), 503, null, 'errors.storage.notWritable');
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
        // v3.4.0 (N1020) — der Körper wird erst jetzt gelesen, nach der
        // Anmeldung und nach der Prüfung seiner angegebenen Größe (413)
        $req->limitBody(self::bodyLimit($req));
        if ($this->readOnlyKey && !in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true) && !self::readOnlyPost($req)) {
            Response::error($this->i18n->t('errors.auth.readOnlyKey'), 403, null, 'errors.auth.readOnlyKey');
        }
        // v3.2.0 (F1023) — Mitglieder erfassen und sehen alles; Zugriff,
        // Backups einspielen und Netzadressen ändern nur Verwalter
        if ($this->role === 'member' && $this->adminOnly($req)) {
            Response::error($this->i18n->t('errors.auth.adminOnly'), 403, null, 'errors.auth.adminOnly');
        }

        if (in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !self::locksLate($req)) {
            $this->lockOrFail();   // endet mit dem Prozess der Anfrage
        }
        // v3.9.0 (F1033) — nach der Antwort: überwachter Ordner, Benachrichtigungen an Home Assistant
        register_shutdown_function(fn() => $this->afterResponse());
        $this->router->dispatch($req);
    }

    /**
     * v3.9.0 (F1033) — Arbeit nach der Antwort: der überwachte Ordner
     * (höchstens alle fünf Minuten, FolderIntake::tick) und die
     * Benachrichtigungen an Home Assistant (neue Dokumente; einmal am Tag die
     * Prüfung). Einen Zeitplan hat die App nicht; jede Anfrage stößt es an, im
     * Image alle 30 Sekunden der Gesundheitscheck. Unter PHP-FPM ist die
     * Antwort dann schon beim Aufrufer (fastcgi_finish_request). Die
     * Schreibsperre der Anfrage wird vorher freigegeben: Das Warten auf Home
     * Assistant hält keinen anderen Schreibzugriff auf.
     */
    public function afterResponse(): void
    {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        try {
            $folder = $this->inboxFolder->tickDue();
            if (!$folder && !$this->haNotify->due()) return;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            @set_time_limit(120);
            $this->writeLock->release();
            if ($folder) {
                $this->inboxFolder->tick();
                $this->writeLock->release();
            }
            $this->haNotify->tick();
        } catch (\Throwable $e) {
            $this->logger->warning('Arbeit nach der Antwort fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    /** v2.6.0 — Stand der Anmeldung dieser Anfrage (für Health und /api/session). */
    private bool $authenticated = false;
    /** v2.6.0 — die Anfrage kam mit einem API-Schlüssel, der nur lesen darf. */
    private bool $readOnlyKey = false;
    /** v3.2.0 (F1023) — angemeldete Person dieser Anfrage (ohne Anmeldung: null) */
    private ?string $userId = null;
    /** v3.2.0 (F1023) — admin | member; ohne Anmeldung darf jeder alles (admin) */
    private string $role = 'admin';

    /**
     * v3.2.0 (F1023) — Routen nur für Verwalter: Personen, API-Schlüssel,
     * Ingest-Token und Anmeldung; Backups und Beispieldaten einspielen (beides
     * ersetzt alle Daten); Einstellungen, die die App mit anderen Adressen
     * sprechen lassen — bei diesen zählt nur eine echte Änderung: Die Seite
     * „Experte“ schickt alle ihre Felder mit, auch unveränderte Adressen.
     */
    private function adminOnly(Request $req): bool
    {
        $p = $req->path;
        if (str_starts_with($p, '/api/users') || str_starts_with($p, '/api/auth/')) return true;
        if ($p === '/api/session/password') return true;
        if ($req->method === 'POST' && ($p === '/api/backup/import' || $p === '/api/demo/import'
            || preg_match('#^/api/backup/snapshots/[^/]+/restore$#', $p))) return true;
        // v3.4.0 (N1020) — auch Snapshots löschen: Darunter sind die
        // Sicherungen vor einem Import (pre-restore-*), der Rückweg
        if ($req->method === 'DELETE' && preg_match('#^/api/backup/snapshots/[^/]+$#', $p)) return true;
        // v3.4.0 (F1024) — den Verlauf leeren (abhaken, Rückgängig und Notiz dürfen Mitglieder)
        if ($req->method === 'DELETE' && $p === '/api/activity') return true;
        // v3.7.0 (Paket cloud) — Auslesen in der Cloud einschalten, Schlüssel, Adresse
        if ($p === '/api/inbox/cloud' && !in_array($req->method, ['GET', 'HEAD'], true)) return true;
        // v3.7.0 (Karte „Auslesen“) — Modellliste mit dem Schlüssel, Prüfung des eigenen Diensts:
        // beide fragen einen Dienst, auch wenn sie nichts ändern
        if ($p === '/api/inbox/cloud/models' || $p === '/api/inbox/local/check') return true;
        // v3.7.0 (Paket betrieb) — den überwachten Ordner liest nur ein Verwalter ein (Dateien des Servers)
        if ($p === '/api/inbox/folder') return true;
        // v3.9.0 (F1033) — Benachrichtigungen an Home Assistant: Ziel, Geheimnisse, Testnachricht
        if (str_starts_with($p, '/api/homeassistant/notify') && !in_array($req->method, ['GET', 'HEAD'], true)) return true;
        if (in_array($req->method, ['PATCH', 'PUT', 'POST'], true) && $p === '/api/settings' && is_array($req->body)) {
            foreach (self::ADMIN_SETTINGS as $k) {
                if (!array_key_exists($k, $req->body)) continue;
                $new = is_scalar($req->body[$k]) || $req->body[$k] === null ? (string)$req->body[$k] : '*';
                $old = (string)$this->settings->get($k, '');
                $changed = $k === 'allowed_hosts'
                    ? HostList::parse($new) !== HostList::parse($old)
                    : trim($new) !== trim($old);
                if ($changed) return true;
            }
        }
        return false;
    }

    /**
     * Einstellungen, die nur Verwalter ändern (Einbettung, Adressen im Netz,
     * Hostliste; v3.4.0 (F1024) — wie lange der Verlauf aufbewahrt wird).
     */
    private const ADMIN_SETTINGS = ['frame_ancestors', 'ocr_endpoint', 'evcc_endpoint', 'allowed_hosts', 'activity_keep_months',
        // v3.7.0 — `inbox_provider`: wohin Versorgerpost zum Auslesen geht; (Paket cloud) ob Dokumente in die Cloud gehen
        'inbox_provider', 'inbox_cloud_enabled', 'inbox_cloud_provider', 'inbox_cloud_endpoint', 'inbox_cloud_model',
        // v3.9.0 (F1033) — ob und wohin Benachrichtigungen an Home Assistant gehen
        'ha_notify_enabled', 'ha_notify_target', 'ha_notify_url', 'ha_notify_service'];

    /** v3.2.0 — Person dieser Anfrage (öffentlich, mit Einstellungen), sonst null. */
    public function currentUser(): ?array
    {
        if ($this->userId === null) return null;
        $u = $this->auth->userById($this->userId);
        return $u === null ? null : AuthService::publicUser($u);
    }

    /** v3.4.0 (F1024) — Name des API-Schlüssels dieser Anfrage (für den Verlauf). */
    private ?string $keyName = null;

    /**
     * v3.4.0 (F1024) — Wer handelt (für den Verlauf): die angemeldete Person,
     * sonst der API-Schlüssel; ohne Anmeldung niemand (null).
     *
     * @return array{type:string, id?:string, name:string}|null
     */
    public function actor(): ?array
    {
        if ($this->userId !== null) {
            $u = $this->currentUser();
            return $u === null ? null : ['type' => 'person', 'id' => (string)$u['id'], 'name' => (string)$u['name']];
        }
        return $this->keyName !== null ? ['type' => 'key', 'name' => $this->keyName] : null;
    }

    /**
     * Angemeldet: Anmeldung aus, gültiges Sitzungs-Cookie, API-Schlüssel
     * (Bearer etk_…) oder — im Proxy-Modus — ein Benutzer vom
     * vertrauenswürdigen Proxy.
     */
    private function authenticate(Request $req): bool
    {
        $mode = $this->auth->mode();
        if ($mode === 'off') return true;
        // v3.1.0 (MKT-09) — Kalender-Abo: Kalender-Apps können keine Kopfzeile
        // senden, der Schlüssel steht im Link. Dort gilt NUR ein Schlüssel mit
        // Bereich `calendar` (ein `read`/`admin`-Schlüssel im Link landete in
        // Logs und Kalender-Synchronisationen), und er gilt nirgends sonst.
        $feedToken = $req->queryParam('token');
        if ($req->path === '/api/calendar.ics' && $feedToken !== null && $feedToken !== '') {
            return $this->auth->apiKeyScope($feedToken) === 'calendar';
        }
        $cookie = $_COOKIE[AuthService::COOKIE] ?? null;
        $sessionUser = $this->auth->sessionUserId(is_string($cookie) ? $cookie : null);
        if ($sessionUser !== null) {
            $this->userId = $sessionUser;
            $this->role = (string)($this->auth->userById($sessionUser)['role'] ?? 'member');
            // v3.4.0 (N1020) — gleitende Verlängerung (nicht beim Abmelden selbst)
            if (!($req->method !== 'GET' && str_starts_with($req->path, '/api/session'))) {
                $renewed = $this->auth->renewedSession((string)$cookie);
                if ($renewed !== null) SessionController::cookie($renewed, $this->auth->sessionTtl());
            }
            return true;
        }
        $scope = $this->auth->apiKeyScope($req->bearerToken());
        if ($scope === 'calendar') return false;
        // v3.7.0 (Paket betrieb) — Schlüssel „Posteingang“ (Kurzbefehl, Home Assistant):
        // darf genau eines, ein PDF ablegen; überall sonst gilt er nicht
        if ($scope === 'inbox') {
            if ($req->method !== 'POST' || $req->path !== '/api/inbox') return false;
            $this->keyName = $this->auth->apiKeyName($req->bearerToken());
            return true;
        }
        if ($scope !== null) {
            $this->readOnlyKey = $scope === 'read';
            $this->keyName = $this->auth->apiKeyName($req->bearerToken());   // v3.4.0 (F1024)
            return true;
        }
        // v3.4.0 (N1020) — Proxy: nur die eine zuständige Kopfzeile (s.
        // AuthService::proxyHeaderMode), Personen aus Home Assistant über ihre Kennung
        $proxy = $mode === 'proxy' ? $this->auth->proxyIdentity($_SERVER) : null;
        if ($proxy === null) return false;
        $user = $this->auth->proxyUserRecord($proxy['name'], $proxy['id']);
        $this->userId = (string)$user['id'];
        $this->role = (string)($user['role'] ?? 'member');
        return true;
    }

    /**
     * v3.4.0 (N1020) — Sicherheitshinweise für Zugriff und Diagnose
     * (`GET /api/diagnostics` → `security`): Proxy-Anmeldung ohne feste
     * Kopfzeile, offener Betrieb ohne Hostliste. Den Selbsttest „liefert der
     * Webserver data/ aus?" macht der Browser.
     *
     * @return array<string,mixed>
     */
    public function securityStatus(): array
    {
        $mode = $this->auth->mode();
        $setting = HostList::parse((string)$this->settings->get('allowed_hosts', ''));
        $host = HostList::hostOf((string)($_SERVER['HTTP_HOST'] ?? ''));
        $notices = [];
        $proxyMode = AuthService::proxyHeaderMode($_SERVER);
        if ($mode === 'proxy' && $proxyMode === 'legacy') {
            $notices[] = ['code' => 'proxy_header', 'level' => 'warning', 'headers' => AuthService::LEGACY_PROXY_HEADERS];
        }
        if ($mode === 'off' && HostList::fromEnv() === [] && $setting === []) {
            $notices[] = ['code' => 'open_without_hosts', 'level' => 'warning',
                          'host' => $host !== '' && !HostList::isAddress($host) ? $host : null];
        }
        return [
            'auth_mode'     => $mode,
            'proxy_header'  => ['mode' => $proxyMode, 'header' => AuthService::configuredProxyHeader()],
            'allowed_hosts' => ['env' => HostList::fromEnv(), 'setting' => $setting],
            'current_host'  => $host,
            'notices'       => $notices,
        ];
    }

    /**
     * Ohne Anmeldung erreichbar: Ingest (eigener Token), Health (Minimalform),
     * die Anmeldung selbst und das Manifest (v3.1.0 — Browser holen es ohne
     * Cookies; es enthält keine Daten).
     */
    private static function isPublic(Request $req): bool
    {
        return ($req->method === 'POST' && $req->path === '/api/ingest')
            || (in_array($req->method, ['GET', 'HEAD'], true) && $req->path === '/api/health')
            || (in_array($req->method, ['GET', 'HEAD'], true) && $req->path === '/api/manifest')
            || ($req->path === '/api/session' && in_array($req->method, ['GET', 'POST', 'DELETE'], true))
            || $req->method === 'OPTIONS';
    }

    /**
     * ET_ALLOWED_HOSTS: Kommaliste, `*.example.org` als Platzhalter. Leer = alle.
     * v3.4.0 (N1020) — zusammen mit der Einstellung `allowed_hosts` (beide Listen gelten).
     */
    public static function hostAllowed(string $hostHeader, string $setting = ''): bool
    {
        return HostList::allows($hostHeader, array_values(array_unique(array_merge(HostList::fromEnv(), HostList::parse($setting)))));
    }

    private function registerRoutes(): void
    {
        $r = $this->router;

        // ── Utilities listing ──
        $utilCtrl = new UtilitiesController($this->i18n);
        $r->get('/api/utilities', fn($req) => $utilCtrl->index($req));

        // ── v3.1.0 (I18N-30) — Web-App-Manifest in der Sprache des Geräts ──
        $manifestCtrl = new ManifestController($this->i18n);
        $r->get('/api/manifest', fn($req) => $manifestCtrl->show($req));

        // ── v3.4.0 (N1019) — Hilfe auf dem Server: die Doku dieses Release ──
        // Nur über die Freigabeliste (DocsService); Anmeldung wie jede Route.
        $docsCtrl = new DocsController(new DocsService(dirname(__DIR__)), $this->i18n);
        $r->get('/api/docs',       fn($req) => $docsCtrl->show($req));
        $r->get('/api/docs/asset', fn($req) => $docsCtrl->asset($req));

        // ── Meters per utility ──
        $meterCtrl = new MeterController($this->meters, $this->i18n, $this->responsibility);   // v3.7.0 — Zuständig
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

        // v3.1.0 (Paket H3, B2) — Verbrauch je Zeitraum
        $periodCtrl = new PeriodController($this->periods);
        $r->get('/api/utility/{utility}/periods',          fn($req) => $periodCtrl->index($req));
        $r->post('/api/utility/{utility}/periods',         fn($req) => $periodCtrl->create($req));
        $r->patch('/api/utility/{utility}/periods/{id}',   fn($req) => $periodCtrl->update($req));
        $r->delete('/api/utility/{utility}/periods/{id}',  fn($req) => $periodCtrl->destroy($req));
        $r->post('/api/utility/{utility}/meters/{id}/periods/import-csv', fn($req) => $periodCtrl->importCsv($req));

        // v3.1.0 (Paket H3, F1008) — Mietverhältnis, Abrechnungen, Budget
        $tenCtrl = new TenancyController($this->tenancies, $this->tenancyBudget);
        $r->get('/api/tenancies',                              fn($req) => $tenCtrl->index($req));
        $r->post('/api/tenancies',                             fn($req) => $tenCtrl->create($req));
        $r->patch('/api/tenancies/{id}',                       fn($req) => $tenCtrl->update($req));
        $r->delete('/api/tenancies/{id}',                      fn($req) => $tenCtrl->destroy($req));
        $r->get('/api/tenancies/{id}/budget',                  fn($req) => $tenCtrl->budget($req));
        $r->get('/api/tenancies/{id}/statements',              fn($req) => $tenCtrl->statements($req));
        $r->post('/api/tenancies/{id}/statements',             fn($req) => $tenCtrl->createStatement($req));
        $r->patch('/api/tenancies/{id}/statements/{sid}',      fn($req) => $tenCtrl->updateStatement($req));
        $r->delete('/api/tenancies/{id}/statements/{sid}',     fn($req) => $tenCtrl->destroyStatement($req));

        // v3.1.0 (Paket H4) — CO₂-Preis, Aufteilung, Anschreiben
        $co2Ctrl = new Co2Controller($this->co2Costs, $this->co2Split, $this->i18n);
        $r->get('/api/co2-costs',              fn($req) => $co2Ctrl->costs($req));
        $r->get('/api/co2-split',              fn($req) => $co2Ctrl->split($req));
        $r->get('/api/reports/co2-split.pdf',  fn($req) => $co2Ctrl->letter($req));

        // v3.1.0 (H6, MKT-14) — Ladestrom-Nachweis (Dienstwagen)
        $evCtrl = new EvChargingController($this->evCharging, $this->csvExport, $this->i18n);
        $r->get('/api/reports/ev-charging',      fn($req) => $evCtrl->json($req));
        $r->get('/api/reports/ev-charging.csv',  fn($req) => $evCtrl->csv($req));
        $r->get('/api/reports/ev-charging.pdf',  fn($req) => $evCtrl->pdf($req));

        // v3.1.0 (H7, MKT-18) — Jahresarbeitszahl der Wärmepumpe
        $hpCtrl = new HeatPumpController($this->heatPumps, $this->i18n);
        $r->get('/api/heat-pump', fn($req) => $hpCtrl->index($req));

        // v3.1.0 (H8, MKT-19) — Zeitreihe mit Spaltenzuordnung (Body: {csv, mapping})
        $siCtrl = new SeriesImportController($this->seriesImport);
        $r->post('/api/utility/{utility}/meters/{id}/import-series', fn($req) => $siCtrl->import($req));

        // v3.2.0 (F1022) — Ladevorgänge aus evcc: CSV-Export oder Abruf im Heimnetz
        $evccCtrl = new EvccController($this->evcc, $this->i18n);
        // v3.4.0 (F1024) — im Verlauf erledigt „evcc", nicht die Person am Knopf
        $r->post('/api/utility/strom/meters/{id}/import-evcc', function ($req) use ($evccCtrl) { $this->activity->actAs('evcc'); $evccCtrl->importCsv($req); });
        $r->post('/api/utility/strom/meters/{id}/sync-evcc',   function ($req) use ($evccCtrl) { $this->activity->actAs('evcc'); $evccCtrl->sync($req); });
        $r->get('/api/ev-sessions',                            fn($req) => $evccCtrl->sessions($req));

        // v3.3.0 (F1021) — Flexibilität bewerten: Viertelstunden, Börsenpreise, Auswertung
        $flexCtrl = new FlexibilityController($this->flexibility, $this->intervals, $this->marketPrices, $this->i18n, $this->flexInsights);
        $r->get('/api/flexibility',                         fn($req) => $flexCtrl->analyze($req));
        $r->get('/api/intervals',                           fn($req) => $flexCtrl->list($req));
        $r->delete('/api/intervals/{id}',                   fn($req) => $flexCtrl->delete($req));
        $r->post('/api/market-prices/sync-smard-intervals', fn($req) => $flexCtrl->syncSmard($req));

        // v3.5.0 (F1027) — Tagesfilm: Energiefluss eines Tages bzw. des typischen Tages je Viertelstunde
        $efCtrl = new EnergyFlowController($this->energyFlowDay, $this->i18n);
        $r->get('/api/energy-flow/days',        fn($req) => $efCtrl->days($req));
        $r->get('/api/energy-flow/day',         fn($req) => $efCtrl->day($req));
        $r->get('/api/energy-flow/typical-day', fn($req) => $efCtrl->typicalDay($req));

        // v3.1.0 (Paket H5) — Versorgerrechnungen erfassen, prüfen, buchen
        $billCtrl = new BillController($this->bills);
        $r->get('/api/utility/{utility}/bills',              fn($req) => $billCtrl->index($req));
        $r->post('/api/utility/{utility}/bills',             fn($req) => $billCtrl->create($req));
        $r->patch('/api/utility/{utility}/bills/{id}',       fn($req) => $billCtrl->update($req));
        $r->delete('/api/utility/{utility}/bills/{id}',      fn($req) => $billCtrl->destroy($req));
        $r->get('/api/utility/{utility}/bills/{id}/check',   fn($req) => $billCtrl->check($req));
        $r->post('/api/utility/{utility}/bills/{id}/book',   fn($req) => $billCtrl->book($req));

        // ── Deliveries (v1.3.0 — Heizöl/Pellets) ──
        $deliveryCtrl = new DeliveryController($this->deliveries, $this->consumption, $this->meters);
        $r->get('/api/utility/{utility}/deliveries',         fn($req) => $deliveryCtrl->index($req));
        $r->post('/api/utility/{utility}/deliveries',        fn($req) => $deliveryCtrl->create($req));
        $r->patch('/api/utility/{utility}/deliveries/{id}',  fn($req) => $deliveryCtrl->update($req));
        $r->delete('/api/utility/{utility}/deliveries/{id}', fn($req) => $deliveryCtrl->destroy($req));
        $r->get('/api/utility/{utility}/meters/{id}/stock-history',
                                                             fn($req) => $deliveryCtrl->stockHistory($req));

        // ── Contracts ──
        $contractCtrl = new ContractController($this->contracts, $this->i18n, $this->responsibility);   // v3.7.0 — Zuständig
        $r->get('/api/utility/{utility}/contracts',         fn($req) => $contractCtrl->index($req));
        $r->post('/api/utility/{utility}/contracts',        fn($req) => $contractCtrl->create($req));
        $r->get('/api/utility/{utility}/contracts/{id}',    fn($req) => $contractCtrl->show($req));
        $r->patch('/api/utility/{utility}/contracts/{id}',  fn($req) => $contractCtrl->update($req));
        $r->post('/api/utility/{utility}/contracts/{id}/prices/import-csv', fn($req) => $contractCtrl->importPrices($req));   // v3.1.0 (H6)

        // v3.1.0 (H6, B7/MKT-12) — Großhandelspreise Strom (SMARD) für den Dynamik-Check
        $mpCtrl = new MarketPriceController($this->marketPrices, $this->i18n);
        $r->get('/api/market-prices',              fn($req) => $mpCtrl->index($req));
        $r->post('/api/market-prices/import-csv',  fn($req) => $mpCtrl->importCsv($req));
        $r->post('/api/market-prices/sync-smard',  fn($req) => $mpCtrl->syncSmard($req));
        $r->delete('/api/utility/{utility}/contracts/{id}', fn($req) => $contractCtrl->destroy($req));

        // ── Consumption (monthly aggregates) ──
        $cCtrl = new ConsumptionController($this->consumption, $this->anomalies, $this->meters, $this->regression, $this->settings, $this->i18n, $this->contracts, $this->billingPeriods);
        $r->get('/api/utility/{utility}/consumption',              fn($req) => $cCtrl->utility($req));
        $r->get('/api/utility/{utility}/meters/{id}/consumption',  fn($req) => $cCtrl->meter($req));
        $r->get('/api/utility/{utility}/meters/{id}/contract-status', fn($req) => $cCtrl->contractStatus($req));
        // v2.5.0 — F1012: Rechnungsprüfung (nur Gas)
        $r->get('/api/utility/{utility}/meters/{id}/bill-check',      fn($req) => $cCtrl->billCheck($req));
        // v3.6.0 (F1030, #33) — Abrechnungsperioden mit Summen (Zähler, Gruppe, ganze Art)
        $r->get('/api/utility/{utility}/meters/{id}/billing-periods', fn($req) => $cCtrl->billingPeriods($req));
        $r->get('/api/utility/{utility}/billing-periods',             fn($req) => $cCtrl->utilityBillingPeriods($req));

        // ── Forecast ──
        $fCtrl = new ForecastController($this->forecasts, $this->meters, $this->i18n);
        $r->get('/api/utility/{utility}/meters/{id}/forecast', fn($req) => $fCtrl->forMeter($req));
        // v3.6.0 (F1031) — Prognose-Werkstatt: Szenario rechnen (liest nur)
        $scCtrl = new ScenarioController($this->scenarios, $this->meters, $this->i18n);
        $r->post('/api/forecast/scenario', fn($req) => $scCtrl->compute($req));
        // v3.9.0 (F1032, #40) — Assistent „Messkonzept“: Vorschau (schreibt nichts) und Anlegen
        $mcCtrl = new \Energietracker\Controllers\MeteringConceptController($this->meteringConcepts, $this->i18n);
        $r->post('/api/metering-concept/preview', fn($req) => $mcCtrl->preview($req));
        $r->post('/api/metering-concept',         fn($req) => $mcCtrl->apply($req));

        // ── Temperatures ──
        $tCtrl = new TemperatureController($this->temperatures, $this->i18n, $this->settings);
        $r->get('/api/temperatures',                  fn($req) => $tCtrl->index($req));
        $r->post('/api/temperatures',                 fn($req) => $tCtrl->upsert($req));
        $r->post('/api/temperatures/import-csv',      fn($req) => $tCtrl->importCsv($req));
        $r->post('/api/temperatures/sync-open-meteo', fn($req) => $tCtrl->syncOpenMeteo($req));
        $r->delete('/api/temperatures/{date}',        fn($req) => $tCtrl->delete($req));
        $r->get('/api/geocode',                       fn($req) => $tCtrl->geocode($req));   // v2.12.0

        // ── Settings / Backup / Diagnostics ──
        $sCtrl = new SettingsController($this->settings);
        $r->get('/api/settings',   fn($req) => $sCtrl->index($req));
        $r->patch('/api/settings', fn($req) => $sCtrl->update($req));
        $r->get('/api/countries',  fn($req) => $sCtrl->countries($req));   // v2.7.0 — Länderprofile
        $r->post('/api/settings/country', fn($req) => $sCtrl->country($req));   // v3.5.0 (N1022) — Land wechseln
        $r->get('/api/settings/default-updates', fn($req) => $sCtrl->defaultUpdates($req));   // v2.10.0

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
        $exCtrl = new ExportController($this->csvExport, $this->periods);
        $r->get('/api/export/temperatures.csv',          fn($req) => $exCtrl->temperatures($req));
        $r->get('/api/export/{utility}/monthly.csv',     fn($req) => $exCtrl->monthly($req));
        $r->get('/api/export/{utility}/readings.csv',    fn($req) => $exCtrl->readings($req));
        $r->get('/api/export/{utility}/deliveries.csv',  fn($req) => $exCtrl->deliveries($req));
        $r->get('/api/export/{utility}/periods.csv',     fn($req) => $exCtrl->periods($req));   // v3.1.0

        // v3.4.0 (N1020) — die Migration aus v0.9.0 ist entfallen (wer noch eine
        // v0.9.0 hat: v3.3.0 installieren, dort migrieren, dann aktualisieren)

        $dCtrl = new DiagnosticsController($this->diagnostics);
        $r->get('/api/diagnostics', fn($req) => $dCtrl->index($req));

        // ── Benchmark (v1.3.0 — Effizienzklasse kWh/m²) ──
        $bCtrl = new BenchmarkController($this->benchmark, $this->references);
        $r->get('/api/benchmarks/efficiency', fn($req) => $bCtrl->efficiency($req));
        $r->get('/api/benchmarks/comparison', fn($req) => $bCtrl->comparison($req));   // v3.1.0 (H8, MKT-11)

        // ── Tarifvergleich (v1.3.0 — Schattenverträge) ──
        $tcCtrl = new TariffComparisonController($this->tariffs);
        $r->get('/api/utility/{utility}/meters/{id}/tariff-comparison',
                                              fn($req) => $tcCtrl->compare($req));

        // ── Wechselentscheidung (v2.3.0 — Prognose gegen Angebot) ──
        $tsCtrl = new TariffSwitchController($this->tariffSwitch);
        $r->get('/api/utility/{utility}/meters/{id}/tariff-switch',
                                              fn($req) => $tsCtrl->analyze($req));

        // v3.1.0 (H6, #17) — Gruppenvertrag: dieselben Auswertungen für eine Zählergruppe
        $r->get('/api/utility/{utility}/meter-groups/{id}/consumption',     fn($req) => $cCtrl->meter($req));
        $r->get('/api/utility/{utility}/meter-groups/{id}/contract-status', fn($req) => $cCtrl->contractStatus($req));
        $r->get('/api/utility/{utility}/meter-groups/{id}/bill-check',      fn($req) => $cCtrl->billCheck($req));
        $r->get('/api/utility/{utility}/meter-groups/{id}/billing-periods', fn($req) => $cCtrl->billingPeriods($req));   // v3.6.0 (F1030)
        $r->get('/api/utility/{utility}/meter-groups/{id}/forecast',        fn($req) => $fCtrl->forMeter($req));
        $r->get('/api/utility/{utility}/meter-groups/{id}/tariff-switch',   fn($req) => $tsCtrl->analyze($req));

        // ── Empfehlungen (v1.3.0 — statistische Insights) ──
        $recCtrl = new RecommendationController($this->recommendations);
        $r->get('/api/recommendations', fn($req) => $recCtrl->index($req));
        $r->post('/api/recommendations/{id}/dismiss', fn($req) => $recCtrl->dismiss($req));
        $r->delete('/api/recommendations/{id}/dismiss', fn($req) => $recCtrl->restore($req));

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
        $r->get('/api/reports/yearly',     fn($req) => $repCtrl->yearlyData($req));   // v3.1.0 (I18N-12)

        // v3.1.0 (Paket H1) — Agenda, Kalender-Abo, Kennzahlen für Home Assistant
        $agCtrl = new AgendaController($this->agenda, $this->calendar, $this->summary, $this->i18n);
        $r->get('/api/agenda',        fn($req) => $agCtrl->agenda($req));
        $r->get('/api/calendar.ics',  fn($req) => $agCtrl->calendar($req));
        $r->get('/api/summary',       fn($req) => $agCtrl->summary($req));
        // v3.9.0 (F1033, #36) — Sensor „Zu tun“ und Benachrichtigungen an Home Assistant
        // (PATCH und Test nur Verwalter, adminOnly)
        $haCtrl = new \Energietracker\Controllers\HomeAssistantController($this->haNotify, $this->agenda, $this->instance);
        $r->get('/api/todo',                         fn($req) => $haCtrl->todo($req));
        $r->get('/api/homeassistant/notify',         fn($req) => $haCtrl->show($req));
        $r->patch('/api/homeassistant/notify',       fn($req) => $haCtrl->update($req));
        $r->post('/api/homeassistant/notify/test',   fn($req) => $haCtrl->test($req));

        // v3.4.0 (F1024) — Erledigt mit Verlauf: abhaken, Verlauf, Rückgängig, Notiz;
        // leeren nur Verwalter (adminOnly)
        $r->post('/api/agenda/done',  fn($req) => $agCtrl->done($req));
        $actCtrl = new ActivityController($this->activity, $this->csvExport);
        $r->get('/api/activity',              fn($req) => $actCtrl->index($req));
        $r->get('/api/activity.csv',          fn($req) => $actCtrl->csv($req));   // v3.5.0 — zum Nachweis
        $r->delete('/api/activity',           fn($req) => $actCtrl->clear($req));
        $r->post('/api/activity/{id}/undo',   fn($req) => $actCtrl->undo($req));
        $r->patch('/api/activity/{id}',       fn($req) => $actCtrl->update($req));

        // v3.1.0 (Paket H2) — Belege (Fotos, PDFs) und Texterkennung
        $attCtrl = new AttachmentController($this->attachments, $this->ocr, $this->i18n);
        $r->get('/api/attachments',          fn($req) => $attCtrl->index($req));
        $r->post('/api/attachments',         fn($req) => $attCtrl->create($req));
        $r->get('/api/attachments/{id}',     fn($req) => $attCtrl->show($req));
        $r->delete('/api/attachments/{id}',  fn($req) => $attCtrl->destroy($req));
        $r->post('/api/ocr/reading',         fn($req) => $attCtrl->ocr($req));

        // v3.7.0 — Posteingang für Versorgerpost (feste Pfade vor {id})
        $inCtrl = new InboxController($this->inbox, $this->i18n, $this->inboxFolder);
        $r->get('/api/inbox',                 fn($req) => $inCtrl->index($req));
        $r->post('/api/inbox',                fn($req) => $inCtrl->create($req));
        $r->get('/api/inbox/review',          fn($req) => $inCtrl->review($req));
        // v3.7.0 (Haushalt aus Dokumenten) — Entwurf über alle ausgelesenen Dokumente (vor {id})
        $draftCtrl = new \Energietracker\Controllers\InboxDraftController($this->inboxDraft);
        $r->get('/api/inbox/draft',           fn($req) => $draftCtrl->show($req));
        $r->post('/api/inbox/draft/apply',    fn($req) => $draftCtrl->apply($req));
        $r->post('/api/inbox/folder',         fn($req) => $inCtrl->folder($req));   // v3.7.0 (Paket betrieb) — überwachter Ordner, nur Verwalter
        $r->post('/api/inbox/queue',          fn($req) => $inCtrl->queue($req));
        $r->delete('/api/inbox/queue',        fn($req) => $inCtrl->cancelQueue($req));
        // ── v3.7.0 (Paket cloud) — Karte „Auslesen in der Cloud“ (PATCH nur Verwalter) ──
        $icCtrl = new \Energietracker\Controllers\InboxCloudController($this->inboxCloud, $this->inboxAnthropic, $this->inboxOpenAi, $this->ocr, $this->settings);
        $r->get('/api/inbox/cloud/models',    fn($req) => $icCtrl->models($req));       // v3.7.0 (Karte „Auslesen“), nur Verwalter
        $r->get('/api/inbox/local/check',     fn($req) => $icCtrl->localCheck($req));   // v3.7.0 (Karte „Auslesen“), nur Verwalter
        $r->get('/api/inbox/cloud',           fn($req) => $icCtrl->show($req));
        $r->patch('/api/inbox/cloud',         fn($req) => $icCtrl->update($req));
        $r->get('/api/inbox/{id}',            fn($req) => $inCtrl->show($req));
        $r->patch('/api/inbox/{id}',          fn($req) => $inCtrl->update($req));
        $r->delete('/api/inbox/{id}',         fn($req) => $inCtrl->destroy($req));
        $r->post('/api/inbox/{id}/classify',  fn($req) => $inCtrl->classify($req));
        $r->post('/api/inbox/{id}/extract',   fn($req) => $inCtrl->extract($req));
        $r->post('/api/inbox/{id}/apply',     fn($req) => $inCtrl->apply($req));
        $r->post('/api/inbox/{id}/remind',    fn($req) => $inCtrl->remind($req));   // v3.7.0 (Paket betrieb) — Termin zu einer Mahnung
        // v3.7.0 (Paket betrieb) — Akte je Zähler und Vertrag
        $dosCtrl = new \Energietracker\Controllers\DossierController($this->dossier, $this->i18n);
        $r->get('/api/utility/{utility}/dossier', fn($req) => $dosCtrl->show($req));

        // ── F1005 (v1.7.0) — Strom-Saldo + PV-Summary ──
        $saldoCtrl = new StromSaldoController($this->stromSaldo);
        $r->get('/api/strom-saldo', fn($req) => $saldoCtrl->index($req));
        $pvCtrl = new PvSummaryController($this->pvSummary);
        $r->get('/api/pv-summary',  fn($req) => $pvCtrl->index($req));

        // ── N1003 (v1.7.0) — Health-Check ──
        // v2.6.0 — mit Anmeldung sehen Nicht-Angemeldete nur {status, version}
        $hCtrl = new HealthController($this->health, fn(): bool => $this->authenticated);
        $r->get('/api/health', fn($req) => $hCtrl->index($req));

        // ── v3.6.0 — Hinweis auf neue Versionen (nur eingeschaltet; einmal am Tag) ──
        $ucCtrl = new UpdateCheckController(new \Energietracker\Services\UpdateCheckService(
            $this->store, $this->settings, trim((string)@file_get_contents(__DIR__ . '/../VERSION'))));
        $r->get('/api/update-check', fn($req) => $ucCtrl->check($req));

        // ── v2.6.0 — Anmeldung (opt-in) und API-Schlüssel ──
        $sessCtrl = new SessionController($this->auth, $this->i18n, fn(): bool => $this->authenticated,
            fn(): ?array => $this->currentUser(), fn(): string => $this->role,
            fn(string $id) => $this->activity->anonymize($id),   // v3.4.0 (F1024)
            // v3.5.0 (F1028); v3.6.0 — offene Termine an die löschende Person;
            // v3.7.0 — ebenso Zähler und Verträge (`records`)
            fn(string $id, bool $anon, ?array $to, string $name): array => $this->reminders->personDeleted($id, $anon, $to, $name)
                + ['records' => $this->responsibility->personDeleted($id, $anon, $to)],
            // v3.6.0 — Personenverzeichnis, „(gelöscht)“ im Verlauf, Vorschau für den Dialog
            $this->people,
            fn(string $id): int => $this->activity->markDeleted($id),
            fn(string $id, string $name): array => ['open' => $this->reminders->openCountFor($id, $name),
                                                    'done' => $this->activity->mentions($id)]
                + $this->responsibility->countFor($id));   // v3.7.0 — {meters, contracts}
        $r->get('/api/session',              fn($req) => $sessCtrl->status($req));
        $r->post('/api/session',             fn($req) => $sessCtrl->login($req));
        $r->delete('/api/session',           fn($req) => $sessCtrl->logout($req));
        $r->post('/api/session/logout-everywhere', fn($req) => $sessCtrl->logoutEverywhere($req));   // v3.4.0 (N1020)
        $r->post('/api/session/password',    fn($req) => $sessCtrl->setPassword($req));
        $r->delete('/api/session/password',  fn($req) => $sessCtrl->disable($req));
        $r->get('/api/auth/keys',            fn($req) => $sessCtrl->listKeys($req));
        $r->post('/api/auth/keys',           fn($req) => $sessCtrl->createKey($req));
        $r->delete('/api/auth/keys/{id}',    fn($req) => $sessCtrl->revokeKey($req));
        // v3.2.0 (F1023) — Benutzer im Haushalt: eigene Einstellungen, eigenes
        // Passwort; Personen verwalten nur Verwalter (adminOnly)
        $r->patch('/api/session/me',          fn($req) => $sessCtrl->updateMe($req));
        $r->post('/api/session/me/password',  fn($req) => $sessCtrl->changeMyPassword($req));
        $r->get('/api/users',                 fn($req) => $sessCtrl->listUsers($req));
        // v3.5.0 (F1028) — nur ID und Name, für die Auswahl „Zuständig“ (jede Rolle)
        $r->get('/api/people',                fn($req) => $sessCtrl->people($req));
        $r->post('/api/users',                fn($req) => $sessCtrl->createUser($req));
        $r->patch('/api/users/{id}',          fn($req) => $sessCtrl->updateUser($req));
        $r->delete('/api/users/{id}',         fn($req) => $sessCtrl->deleteUser($req));
        // v3.6.0 — Vorschau „Person löschen“: was an wen übergeht (nur Verwalter, adminOnly)
        $r->get('/api/users/{id}/handover',   fn($req) => $sessCtrl->handover($req));

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
        // v3.4.0 (F1024) — Verlauf: Home Assistant als Integration
        $r->post('/api/ingest',       function ($req) use ($ingestCtrl) { $this->activity->actAs('home_assistant'); $ingestCtrl->store($req); });
    }
}
