<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;
use Energietracker\Storage\JsonStore;
use Energietracker\Services\DemoService;
use Energietracker\Services\BackupService;
use Energietracker\Services\EvccService;
use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;

/**
 * F1007 (v1.7.4) — Demo-Daten-Komfort-Import.
 *
 * Bewusst NICHT auf ServiceTestCase aufgebaut (das seedet bereits Zähler);
 * F1007 braucht einen echt leeren Store, daher ein eigener Temp-JsonStore.
 * Als Fixture dient das mitgelieferte Demo-Backup demo-data/…json.
 */
final class DemoServiceTest extends TestCase
{
    private string $dir;
    private JsonStore $store;
    private I18nService $i18n;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->dir = $base . '/et_demo_' . uniqid();
        mkdir($this->dir, 0777, true);
        $this->store = new JsonStore($this->dir);
        $this->i18n  = new I18nService(dirname(__DIR__, 3) . '/public/locales', new SettingsService($this->store));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    private function rmrf(string $p): void
    {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $this->rmrf("$p/$f");
        }
        @rmdir($p);
    }

    private function service(): DemoService
    {
        return new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
    }

    public function testFreshStoreIsEmptyAndBackupAvailable(): void
    {
        $svc = $this->service();
        $this->assertTrue($svc->isAvailable(), 'Demo-Backup-Datei sollte im Repo vorhanden sein');
        $this->assertTrue($svc->isEmpty(), 'frischer Store hat keine Zähler');
        $status = $svc->status();
        $this->assertTrue($status['available']);
        $this->assertTrue($status['is_empty']);
    }

    public function testImportIntoEmptyPopulatesMeters(): void
    {
        $svc = $this->service();
        $report = $svc->import(); // force nicht nötig, da leer
        $this->assertTrue($report['demo_import']);
        $this->assertArrayHasKey('utilities', $report);

        $this->assertFalse($svc->isEmpty());
        $strom = $this->store->read('strom/meters.json', []);
        $this->assertNotEmpty($strom, 'Strom sollte nach Demo-Import Zähler haben');
    }

    public function testImportRefusesWhenDataPresentWithoutForce(): void
    {
        $svc = $this->service();
        $svc->import(); // füllt den Store

        $this->expectException(\InvalidArgumentException::class);
        $svc->import(false); // ohne force bei vorhandenen Daten → Abbruch
    }

    public function testForcedImportOverwritesExistingData(): void
    {
        $svc = $this->service();
        $svc->import();
        $report = $svc->import(true); // mit force erneut — darf nicht werfen
        $this->assertTrue($report['demo_import']);
        $this->assertFalse($svc->isEmpty());
    }

    /**
     * v2.1.2 — Das Demo-Backup enthält Heizöl-/Pellets-Lieferungen, und der
     * Restore-Pfad muss sie wiederherstellen. Vorher fielen deliveries still
     * aus Export+Import → leere Demo-Tanks ohne Verbrauch/Kosten.
     */
    public function testImportRestoresDeliveriesForDeliveryUtilities(): void
    {
        $svc = $this->service();
        // v3.0.0 — am Exporttag: genau die drei Lieferungen des Backups
        // (später kommen fortgeschriebene dazu, s. DemoDataAlignerTest)
        $svc->import(false, '2026-05-31');

        $oil = $this->store->read('heizoel/deliveries.json', []);
        $pellets = $this->store->read('pellets/deliveries.json', []);
        $this->assertCount(3, $oil, 'Heizöl-Lieferungen müssen aus dem Demo-Backup kommen');
        $this->assertCount(3, $pellets, 'Pellets-Lieferungen müssen aus dem Demo-Backup kommen');
    }

    /**
     * v3.0.0 — „Mit Beispieldaten ausprobieren“ zeigt einen Haushalt von heute:
     * Monate nach dem Export reichen die Stände bis an heute heran.
     */
    public function testImportBringsTheDemoUpToToday(): void
    {
        $svc = $this->service();
        $svc->import(false, '2027-02-10');
        $gas = $this->store->read('gas/readings.json', []);
        $latest = max(array_column($gas, 'date'));
        $this->assertGreaterThan('2026-11-01', $latest, 'jüngste Gas-Ablesung höchstens ein Vierteljahr alt');
        $this->assertLessThanOrEqual('2027-02-10', $latest);
        $temps = $this->store->read('temperatures.json', []);
        $this->assertArrayHasKey('2027-02-10', $temps, 'Temperaturen bis heute');
    }

    /**
     * v2.2.2 — Dieselbe Klasse wie die Lieferungen ein Release zuvor: Das
     * Demo-Backup trägt eine feste Feldliste, und `reminders` fehlte darin.
     * Wer die Demo-Daten über den Knopf in den Einstellungen lud, bekam eine
     * leere Termin-Ansicht, obwohl die Anwendung das Modul mitbringt.
     */
    public function testImportRestoresReminders(): void
    {
        $svc = $this->service();
        $svc->import();

        $reminders = $this->store->read('reminders.json', []);
        $this->assertNotEmpty($reminders,
            'Das Demo-Backup muss Termine mitbringen — sonst ist die Ansicht nach dem Demo-Import leer');

        foreach ($reminders as $r) {
            foreach (['id', 'title', 'category', 'next_due', 'recurrence'] as $field) {
                $this->assertArrayHasKey($field, $r, "Terminfeld $field fehlt");
            }
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)$r['next_due'],
                'next_due muss ein ISO-Datum sein');
            $this->assertContains($r['recurrence'],
                ['none', 'yearly', 'semi-yearly', 'custom-months'],
                'Unbekannte Wiederholung: ' . $r['recurrence']);
        }
    }

    /**
     * Die Demo soll die Bandbreite zeigen, nicht nur einen Fall: mehrere
     * Kategorien und mindestens ein Termin, der bereits fällig ist.
     */
    public function testDemoRemindersCoverSeveralCategoriesAndStates(): void
    {
        $svc = $this->service();
        $svc->import();
        $reminders = $this->store->read('reminders.json', []);

        $categories = array_unique(array_column($reminders, 'category'));
        $this->assertGreaterThanOrEqual(3, count($categories),
            'Die Demo sollte mehrere Terminarten zeigen');

        $due = array_filter($reminders, fn($r) => (string)$r['next_due'] <= date('Y-m-d'));
        $this->assertNotEmpty($due,
            'Mindestens ein Demo-Termin sollte fällig sein, damit die Statusfarben sichtbar werden');
    }

    /**
     * Datei-Pfad und Backup-Pfad müssen dieselben Termine liefern. Beide Wege
     * existieren (Verzeichnis kopieren bzw. „Demo laden") und liefen bisher
     * auseinander.
     */
    public function testDemoDirectoryAndBackupCarryTheSameReminders(): void
    {
        $root = dirname(__DIR__, 3);
        $fromFile = json_decode(
            (string)file_get_contents("$root/demo-data/reminders.json"), true);
        $backup = json_decode(
            (string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);

        $this->assertIsArray($fromFile);
        $this->assertArrayHasKey('reminders', $backup,
            'Das Demo-Backup braucht ein reminders-Feld');
        $this->assertSame(
            array_column($fromFile, 'id'),
            array_column($backup['reminders'], 'id'),
            'demo-data/reminders.json und das Demo-Backup müssen dieselben Termine führen'
        );
    }

    /**
     * v2.4.0 — F1011: Die Demo führt eine Analyse-Zäsur vor, damit der
     * Vorher/Nachher-Vergleich nach „Demo laden" tatsächlich zu sehen ist.
     *
     * Dieselbe Doppelpflege wie bei den Terminen: Verzeichnisform UND Backup.
     * In v2.1.2 und v2.2.2 landete ein neuer Datentopf jeweils nur an einer
     * der beiden Stellen — deshalb prüft der Test beide.
     */
    public function testDemoDataDemonstratesTheBaselineCutoff(): void
    {
        $root = dirname(__DIR__, 3);
        $fromFile = json_decode(
            (string)file_get_contents("$root/demo-data/gas/meters.json"), true);
        $backup = json_decode(
            (string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);

        $pick = static function (array $meters): ?array {
            foreach ($meters as $m) {
                if (($m['id'] ?? null) === 'm_gas_main') return $m;
            }
            return null;
        };

        $dir = $pick($fromFile ?? []);
        $bak = $pick($backup['utilities']['gas']['meters'] ?? $backup['gas']['meters'] ?? []);

        $this->assertNotNull($dir, 'Demo-Verzeichnis führt den Gas-Hauptzähler');
        $this->assertNotNull($bak, 'Demo-Backup führt den Gas-Hauptzähler');
        $this->assertNotEmpty(
            $dir['baseline_events'] ?? [],
            'demo-data/gas/meters.json soll eine Zäsur vorführen'
        );
        $this->assertSame(
            $dir['baseline_events'],
            $bak['baseline_events'] ?? [],
            'Verzeichnisform und Backup müssen dieselbe Zäsur führen'
        );
    }

    /**
     * v2.5.0 — F1012: Die Demo führt datierte Gas-Faktoren vor (Zustandszahl ×
     * Brennwert je Stichtag), damit Verbrauchsrechnung und Rechnungsprüfung
     * nach „Demo laden" etwas zu zeigen haben. Verzeichnisform UND Backup —
     * und kein Rest des alten Skalars mehr.
     */
    public function testDemoDataCarriesDatedGasConversionFactors(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = json_decode((string)file_get_contents("$root/demo-data/settings.json"), true);
        $bak = json_decode((string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);
        $bakSettings = $bak['settings'] ?? [];

        foreach (['Verzeichnis' => $dir, 'Backup' => $bakSettings] as $name => $s) {
            $this->assertArrayNotHasKey('gas_conversion_factor', $s, "$name: alter Skalar entfernt");
            $this->assertArrayHasKey('gas_conversion_factors', $s, "$name: Liste vorhanden");
            $list = $s['gas_conversion_factors'];
            $this->assertGreaterThanOrEqual(3, count($list), "$name: mehrere Perioden");
            $this->assertNull($list[0]['from'], "$name: undatierter Altwert zuerst");
            $dated = array_filter($list, fn($e) => $e['from'] !== null);
            $this->assertNotEmpty($dated, "$name: mindestens ein Stichtag");
            foreach ($dated as $e) {
                $this->assertNotNull($e['zustandszahl'], "$name: Beleg Zustandszahl");
                $this->assertNotNull($e['brennwert'],    "$name: Beleg Brennwert");
            }
        }
        $this->assertSame(
            $dir['gas_conversion_factors'],
            $bakSettings['gas_conversion_factors'],
            'Verzeichnisform und Backup müssen dieselben Faktoren führen'
        );
    }

    /**
     * v2.10.0 — Tankbuch: Der Demo-Heizöltank führt einen Peilstand vor,
     * damit „gerechnet bis …, danach geschätzt" nach „Demo laden" zu sehen
     * ist. Verzeichnisform UND Backup.
     */
    public function testDemoDataDemonstratesTheTankBook(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = json_decode((string)file_get_contents("$root/demo-data/heizoel/meters.json"), true);
        $bak = json_decode((string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);
        $pick = static function (array $meters): ?array {
            foreach ($meters as $m) {
                if (($m['id'] ?? null) === 'm_heizoel_tank') return $m;
            }
            return null;
        };
        $d = $pick($dir ?? []);
        $b = $pick($bak['utilities']['heizoel']['meters'] ?? []);
        $this->assertNotNull($d);
        $this->assertNotNull($b);
        $this->assertNotEmpty($d['tank_levels'] ?? [], 'demo-data/heizoel/meters.json soll einen Peilstand vorführen');
        $this->assertEquals($d['tank_levels'], $b['tank_levels'] ?? [],
            'Verzeichnisform und Backup müssen denselben Peilstand führen');
    }

    /**
     * v3.2.0 (F1018) — Ein Beispielhaushalt ersetzt den ganzen Haushalt: Nach
     * der Mieterin bleibt beim Schaufenster kein Mietverhältnis stehen, und die
     * eigene Nutzungsstufe überlebt den Import.
     */
    public function testSwitchingHouseholdsLeavesNoRemainsAndKeepsTheLevel(): void
    {
        $svc = $this->service();
        $this->store->write('settings.json', ['ui_level' => 'beginner', 'setup_pending' => true]);
        $report = $svc->import(false, null, 'mieterin');
        $this->assertSame('mieterin', $report['persona']);
        $this->assertNotEmpty($this->store->read('tenancies.json', []));
        $settings = $this->store->read('settings.json', []);
        $this->assertSame(['beginner', false, 'mieterin'], [$settings['ui_level'] ?? null, $settings['setup_pending'] ?? null, $settings['setup_persona'] ?? null]);

        $svc->import(true);
        $this->assertSame([], $this->store->read('tenancies.json', []), 'kein Mietverhältnis der Mieterin mehr');
        $this->assertSame([], $this->store->read('waerme/periods.json', []), 'keine Zeiträume der Mieterin mehr');
        $this->assertContains('mieterin', $svc->status()['personas']);

        // F1022 — die Ladevorgänge des Hauses mit Wallbox bleiben beim Wechsel nicht stehen
        $svc->import(true, null, 'eigenheim-modern');
        $this->assertNotEmpty($this->store->read(EvccService::FILE, []));
        $svc->import(true);
        $this->assertSame([], $this->store->read(EvccService::FILE, []), 'keine Ladevorgänge mehr');
        $this->expectException(\InvalidArgumentException::class);
        $svc->import(true, null, 'gibt-es-nicht');
    }

    /**
     * v3.5.0 (N1022) — Sprache und Land aus Schritt 1 der Einrichtung
     * überleben den Beispielhaushalt. Bis v3.4 fiel die Sprache nach „Mit
     * Beispieldaten ansehen“ auf Deutsch zurück: Der Haushalt bringt keine mit.
     * Standort und CO₂ kommen dagegen aus dem Beispielhaushalt (Leipzig).
     */
    public function testLanguageAndCountryStayButTheHouseholdBringsItsLocation(): void
    {
        $svc = $this->service();
        $this->store->write('settings.json', ['language' => 'en', 'country' => 'GB', 'currency' => 'GBP',
            'timezone' => 'Europe/London', 'gas_cv_unit' => 'mj', 'location_name' => 'London', 'setup_pending' => true]);
        $svc->import(false, null, 'mieterin');
        $s = $this->store->read('settings.json', []);
        $this->assertSame(['en', 'GB', 'GBP', 'Europe/London', 'mj'],
            [$s['language'] ?? null, $s['country'] ?? null, $s['currency'] ?? null, $s['timezone'] ?? null, $s['gas_cv_unit'] ?? null]);
        $this->assertNotSame('London', $s['location_name'] ?? null, 'Standort aus dem Beispielhaushalt');

        // nichts festgeschrieben vorher → nichts festgeschrieben danach (Default bleibt Default)
        $this->store->write('settings.json', []);
        $svc->import(true, null, 'mieterin');
        $after = $this->store->read('settings.json', []);
        foreach (DemoService::KEEP_SETTINGS as $k) {
            $this->assertArrayNotHasKey($k, $after, "$k war nicht festgeschrieben und bleibt es nicht");
        }
    }

    // ── v3.6.0 — gewanderte Rechnungen nachrechnen ──────────────────────

    public function testMovedBillTakesTheOwnCalculation(): void
    {
        $b = ['id' => 'b_1', 'invoice' => ['energy_kwh' => 1000.0, 'amount_eur' => 300.0, 'advances_paid_eur' => 280.0, 'result_eur' => 20.0],
              'co2' => ['emissions_kg' => 200.0]];
        $out = DemoService::recalculatedBill($b, ['kwh' => 1200.04, 'total' => 333.333, 'advances' => 350.0], 'gas');
        $this->assertSame(['energy_kwh' => 1200.0, 'amount_eur' => 333.33, 'advances_paid_eur' => 350.0, 'result_eur' => -16.67], $out['invoice']);
        $this->assertSame(240.0, $out['co2']['emissions_kg'], 'CO₂ im Verhältnis der Menge');
        $w = DemoService::recalculatedBill(['invoice' => ['volume_m3' => 100.0]], ['m3' => 90.0, 'total' => 400.0, 'advances' => null], 'wasser');
        $this->assertSame(['volume_m3' => 90.0, 'amount_eur' => 400.0, 'advances_paid_eur' => 0.0, 'result_eur' => 400.0], $w['invoice']);
    }

    public function testSpecialPaymentFollowsTheResult(): void
    {
        $sp = ['id' => 'sp_1', 'date' => '2025-01-20', 'kind' => 'nachzahlung_mit', 'amount_eur' => 20.0, 'new_advance_eur' => 90];
        $this->assertSame(['id' => 'sp_1', 'date' => '2025-09-10', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 16.67, 'new_advance_eur' => 90],
            DemoService::settledPayment($sp, -16.67, '2025-09-10'), 'Richtung kippt, „mit Abschlagsänderung“ bleibt');
        $ohne = DemoService::settledPayment(['kind' => 'rueckzahlung_ohne', 'amount_eur' => 3.0, 'date' => '2025-01-01'], 42.5, null);
        $this->assertSame(['kind' => 'nachzahlung_ohne', 'amount_eur' => 42.5, 'date' => '2025-01-01'], $ohne);
    }

    /** Ohne die Verdrahtung blieben gewanderte Rechnungen bei den alten Beträgen („prüfen“ statt „passt“). */
    public function testTheAppRecalculatesMovedBills(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/src/bootstrap.php');
        $this->assertStringContainsString('$this->demo->attachBills($this->bills);', $src);
    }
}
