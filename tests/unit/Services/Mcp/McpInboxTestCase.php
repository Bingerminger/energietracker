<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 (F1037, Paket schreiben) — MCP-Tests mit Posteingang und Verlauf: die
 * Dienste wie im App-Container (Ablesungen melden sich beim Verlauf), ein
 * erfundener Gas-Haushalt aus InboxFixture (Musterstadt) und eine Momentaufnahme
 * der Daten, um „schreibt nichts“ zu prüfen.
 */
abstract class McpInboxTestCase extends McpTestCase
{
    /** Stichtag der Tests: Kontext der KI und Vorschläge des Posteingangs */
    protected const TODAY = '2026-03-01';

    protected ActivityService $activity;
    protected AttachmentService $att;
    protected BillService $bills;
    protected ReminderService $reminders;
    protected InboxService $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        // v3.12.0 (Skeptiker M1) — diese Tests prüfen die Übernahme über apply_proposal (Schalter aus);
        // mit Bestätigung in der App: McpApplyInAppTest
        $this->settings->set(['mcp_apply_in_app' => false]);
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        $this->readings->attachActivity($this->activity);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings);
        $proposals->setToday(self::TODAY);
        $this->inbox = new InboxService($this->store, $this->i18n, $this->att, new ProviderRegistry($this->settings, InboxFixture::provider('local')),
            $proposals, new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $this->inbox->attachActivity($this->activity);
    }

    protected function buildService(string $class): ?object
    {
        return match ($class) {
            ActivityService::class   => $this->activity,
            InboxService::class      => $this->inbox,
            AttachmentService::class => $this->att,
            default                  => null,
        };
    }

    /** Lesen und Vorschlagen (`mcp_write`), Stichtag TODAY. */
    protected function writer(string $keyId = 'k_test0001', string $locale = 'de'): \Energietracker\Services\Mcp\McpContext
    {
        return $this->context('mcp_write', self::TODAY, $locale, $keyId);
    }

    /** Gas-Hauptzähler mit Nummer und laufendem Vertrag, wie InboxServiceTest (erfunden). */
    protected function gasHousehold(): array
    {
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['serial'] = InboxFixture::METER;
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('gas/meters.json', $all);
        return $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]]);
    }

    /**
     * Alle Datendateien außer dem Ordner der Vorschläge (`mcp/`): Pfad → Prüfsumme.
     *
     * @return array<string,string>
     */
    protected function snapshot(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr((string)$f->getPathname(), strlen($this->dataDir) + 1);
            if (str_starts_with($rel, 'mcp/') || !$f->isFile()) continue;
            $out[$rel] = (string)md5_file((string)$f->getPathname());
        }
        ksort($out);
        return $out;
    }

    /** Dateien, die sich zwischen zwei Momentaufnahmen geändert haben (oder neu sind). */
    protected static function changed(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) if (($before[$k] ?? null) !== $v) $out[] = $k;
        foreach ($before as $k => $_) if (!isset($after[$k])) $out[] = $k;
        sort($out);
        return $out;
    }
}
