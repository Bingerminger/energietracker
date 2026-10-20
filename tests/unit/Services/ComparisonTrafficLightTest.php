<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReferenceService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Kachel „Einordnung“ als Ampel: Bewertung gegen den eigenen
 * Vergleichswert, Wasser gegen den Richtwert je Person (Bänder des
 * Spar-Index), Einladung zu genau der Angabe, die fehlt. Kalenderjahr wie
 * bisher (F1030).
 */
#[CoversClass(ReferenceService::class)]
final class ComparisonTrafficLightTest extends ServiceTestCase
{
    private function svc(): ReferenceService
    {
        return new ReferenceService($this->consumption, $this->meters, $this->settings);
    }

    /** Monatsablesungen 2025-01-01 … bis $until mit gleichmäßigem Tagesverbrauch. */
    private function readings(string $utility, string $meterId, float $perDay, string $until = '2026-01-01'): void
    {
        $dev = $this->meters->get($utility, $meterId)['devices'][0]['id'];
        $all = array_values(array_filter((array)$this->store->read("$utility/readings.json", []), fn($r) => $r['meter_id'] !== $meterId));
        $start = new \DateTimeImmutable('2025-01-01');
        for ($d = $start; $d->format('Y-m-d') <= $until; $d = $d->modify('first day of next month')) {
            $all[] = ['id' => 'r_' . $meterId . '_' . $d->format('Ym'), 'meter_id' => $meterId, 'device_id' => $dev,
                'date' => $d->format('Y-m-d'), 'counter' => round($perDay * (int)$start->diff($d)->days, 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write("$utility/readings.json", $all);
    }

    public function testRatingThresholds(): void
    {
        self::assertNull(ReferenceService::rating(null), 'ohne Abweichung keine Ampel');
        self::assertSame('good', ReferenceService::rating(-12.0));
        self::assertSame('good', ReferenceService::rating(0.0), 'genau auf dem Vergleichswert ist grün');
        self::assertSame('near', ReferenceService::rating(0.1));
        self::assertSame('near', ReferenceService::rating(ReferenceService::NEAR_PCT));
        self::assertSame('high', ReferenceService::rating(ReferenceService::NEAR_PCT + 0.1));

        // Die Doku nennt dieselbe Schwelle (Ansichten und API, DE/EN)
        $root = dirname(__DIR__, 3);
        $n = (string)(int)ReferenceService::NEAR_PCT;
        foreach (['docs/referenz/ansichten.md' => "bis $n % darüber", 'docs/referenz/api.md' => "bis $n % darüber",
                  'docs/en/referenz/ansichten.md' => "up to $n % above", 'docs/en/referenz/api.md' => "up to $n % above"] as $file => $needle) {
            self::assertStringContainsString($needle, (string)file_get_contents("$root/$file"), $file);
        }
    }

    public function testOwnElectricityValueBecomesATrafficLight(): void
    {
        $this->readings('strom', (string)$this->meters->defaultId('strom'), 10.0);   // 3650 kWh
        $r = $this->svc()->compare(2025);
        self::assertNull($r['strom']['rating']);
        self::assertContains('reference_strom_kwh', $r['missing'], 'ohne eigenen Wert: Einladung');

        foreach ([[4000, 'good'], [3400, 'near'], [3000, 'high']] as [$own, $want]) {
            $this->settings->set(['reference_strom_kwh' => $own]);
            $r = $this->svc()->compare(2025);
            self::assertSame($want, $r['strom']['rating'], "Vergleichswert $own kWh");
            self::assertNotContains('reference_strom_kwh', $r['missing']);
        }
        $this->readings('strom', (string)$this->meters->defaultId('strom'), 10.0, '2025-07-01');
        self::assertNull($this->svc()->compare(2025)['strom']['rating'], 'halbes Jahr: keine Ampel');
    }

    public function testWaterAgainstBenchmarkPerPerson(): void
    {
        $main = (string)$this->meters->defaultId('wasser');
        $this->readings('wasser', $main, 0.4);                           // 400 L am Tag
        // Subzähler (Garten) zählt nicht doppelt
        $garden = (string)$this->meters->create('wasser', ['name' => 'Garten', 'installed_on' => '2024-01-01',
            'parent_meter_id' => $main, 'role' => 'garden'])['id'];
        $this->readings('wasser', $garden, 5.0);
        $this->settings->set(['wasser_personen_referenz' => 122]);

        $cases = [[4, 100, 'good'], [3, 133, 'near'], [2, 200, 'high']];
        foreach ($cases as [$persons, $liters, $want]) {
            $this->settings->set(['wasser_personen_anzahl' => $persons]);
            $w = $this->svc()->compare(2025)['water'];
            self::assertEqualsWithDelta($liters, $w['liters_per_person_day'], 1, "$persons Personen");
            self::assertSame($want, $w['rating'], "$persons Personen");
            self::assertTrue($w['complete']);
        }
        self::assertEqualsWithDelta(146.0, $this->svc()->compare(2025)['water']['m3'], 0.5, 'ohne den Gartenzähler');

        // v3.6.0 — Wasser erst ab 5 Punkten über „gut“ gelb (100 L je Person und Tag)
        $this->settings->set(['wasser_personen_anzahl' => 4, 'wasser_personen_referenz' => 97]);   // Index ≈ 103
        self::assertSame('good', $this->svc()->compare(2025)['water']['rating'], 'knapp über dem Richtwert');
        $this->settings->set(['wasser_personen_referenz' => 94]);                                  // Index ≈ 106
        self::assertSame('near', $this->svc()->compare(2025)['water']['rating'], 'mehr als 5 % darüber');
        $this->settings->set(['wasser_personen_anzahl' => 2, 'wasser_personen_referenz' => 122]);

        // eigene Bänder des Spar-Index gelten
        $this->settings->set(['wasser_sparindex_warnung' => 300]);
        self::assertSame('near', $this->svc()->compare(2025)['water']['rating']);

        // fehlende Personenzahl: keine Ampel, Einladung
        $this->settings->set(['wasser_personen_anzahl' => 0]);
        $r = $this->svc()->compare(2025);
        self::assertNull($r['water']['rating']);
        self::assertContains('wasser_personen_anzahl', $r['missing']);

        // unvollständiges Jahr: keine Ampel
        $this->settings->set(['wasser_personen_anzahl' => 4]);
        $this->readings('wasser', $main, 0.4, '2025-05-01');
        self::assertNull($this->svc()->compare(2025)['water']['rating']);
    }

    public function testHeatingInvitesTheLivingAreaFirst(): void
    {
        $this->readings('gas', (string)$this->meters->defaultId('gas'), 3.0);
        $this->settings->set(['wohnflaeche_m2' => 0]);
        $r = $this->svc()->compare(2025);
        self::assertSame([], $r['heating'], 'ohne Wohnfläche keine kWh/m²');
        self::assertContains('wohnflaeche_m2', $r['missing']);
        self::assertNotContains('reference_heat_kwh_m2', $r['missing'], 'erst die Fläche, dann der Vergleichswert');

        $this->settings->set(['wohnflaeche_m2' => 120]);
        $r = $this->svc()->compare(2025);
        self::assertContains('reference_heat_kwh_m2', $r['missing']);
        $perM2 = $r['heating'][0]['kwh_per_m2'];
        $this->settings->set(['reference_heat_kwh_m2' => round($perM2 * 0.8)]);
        $r = $this->svc()->compare(2025);
        self::assertSame('high', $r['heating'][0]['rating']);
        self::assertSame([], array_values(array_intersect(['wohnflaeche_m2', 'reference_heat_kwh_m2'], $r['missing'])));
    }

    /**
     * v3.6.0 (Entscheidung des Maintainers) — Standardwerte gelten als „fehlt“:
     * 100 m² und 2 Personen ordnen nichts ein, bis jemand sie einträgt. Ein
     * eingetragener Wert gleich dem Default zählt; leeren nimmt ihn zurück.
     */
    public function testDefaultAreaAndPersonsCountAsMissingUntilEntered(): void
    {
        $this->readings('gas', (string)$this->meters->defaultId('gas'), 3.0);
        $this->readings('wasser', (string)$this->meters->defaultId('wasser'), 0.3);   // 300 L am Tag
        $this->settings->set(['reference_heat_kwh_m2' => 100]);
        self::assertSame(['wohnflaeche_m2', 'wasser_personen_anzahl'], $this->settings->assumedKeys(), 'Neuinstallation: nichts eingetragen');
        self::assertSame([100, 2], [$this->settings->get('wohnflaeche_m2'), $this->settings->get('wasser_personen_anzahl')], 'andere Leser rechnen mit dem Default');

        $r = $this->svc()->compare(2025);
        self::assertSame([], $r['heating'], 'Default-Fläche: keine Zeile Heizung');
        self::assertNull($r['area_m2']);
        self::assertSame([0, null, null], [$r['water']['persons'], $r['water']['liters_per_person_day'], $r['water']['rating']], 'Default-Personen: keine Ampel');
        self::assertSame(['wohnflaeche_m2', 'wasser_personen_anzahl'], $r['missing'], 'Einladung zu beiden Feldern');

        // bewusst eingetragen, gleich dem Default: zählt
        $this->settings->set(['wohnflaeche_m2' => 100, 'wasser_personen_anzahl' => 2]);
        self::assertSame([], $this->settings->assumedKeys());
        $r = $this->svc()->compare(2025);
        self::assertSame(['high', 100.0], [$r['heating'][0]['rating'], $r['area_m2']]);
        self::assertSame(['near', 2], [$r['water']['rating'], $r['water']['persons']], '150 L je Person: gelb');
        self::assertSame([], $r['missing']);

        // leeren nimmt die Angabe zurück (in settings.json steht der Schlüssel nicht mehr)
        $this->settings->set(['wohnflaeche_m2' => '', 'wasser_personen_anzahl' => null]);
        $stored = (array)$this->store->read('settings.json', []);
        self::assertFalse(array_key_exists('wohnflaeche_m2', $stored) || array_key_exists('wasser_personen_anzahl', $stored));
        self::assertSame(['wohnflaeche_m2', 'wasser_personen_anzahl'], $this->svc()->compare(2025)['missing']);
        self::assertSame([100, 2], [$this->settings->get('wohnflaeche_m2'), $this->settings->get('wasser_personen_anzahl')]);

        // Die Doku sagt dasselbe (Einstellungen, Ansichten, API; DE/EN)
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/einstellungen.md' => '### Wohnfläche und Personen: eingetragen oder angenommen',
                  'docs/en/referenz/einstellungen.md' => '### Living area and people: entered or assumed',
                  'docs/referenz/ansichten.md' => '**Standardwerte zählen nicht**',
                  'docs/en/referenz/ansichten.md' => '**Default values do not count**',
                  'docs/referenz/api.md' => '### Angenommene Angaben: `assumed_keys`',
                  'docs/en/referenz/api.md' => '### Assumed values: `assumed_keys`'] as $file => $needle) {
            self::assertStringContainsString($needle, (string)file_get_contents("$root/$file"), $file);
        }
    }

    /** v3.6.0 — Bestand: wer die Werte gespeichert hat (auch ältere Fassungen schrieben sie), behält die Ampel. */
    public function testStoredValuesOfExistingInstallationsKeepTheirTrafficLight(): void
    {
        $this->readings('wasser', (string)$this->meters->defaultId('wasser'), 0.3);
        $this->store->write('settings.json', ['wasser_personen_anzahl' => 2, 'wohnflaeche_m2' => 100, 'wasser_personen_referenz' => 122.0]);
        $r = $this->svc()->compare(2025);
        self::assertSame('near', $r['water']['rating']);
        self::assertNotContains('wasser_personen_anzahl', $r['missing']);
        self::assertSame([], $this->settings->assumedKeys());
    }

    public function testNothingToCompareMeansNothingMissing(): void
    {
        $r = $this->svc()->compare(2025);
        self::assertNull($r['strom']);
        self::assertSame([], $r['heating']);
        self::assertNull($r['water']);
        self::assertSame([], $r['missing'], 'ohne Daten keine Einladung — die Kachel bleibt weg');
    }
}
