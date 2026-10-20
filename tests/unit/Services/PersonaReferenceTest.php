<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\DemoService;
use Energietracker\Services\ReferenceService;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.6.0 (Entscheidung des Maintainers) — ein Beispielhaushalt mit eigenen
 * Vergleichswerten (erfunden, tools/build-personas.mjs withReferenceValues):
 * Eigenheim klassisch zeigt die volle Ampel — Haushaltsstrom rot, Heizung
 * gelb, Wasser grün —, die übrigen laden zu den Vergleichswerten ein. Alle
 * vier Haushalte tragen Wohnfläche und Personen ein (Standardwerte zählen
 * nicht); das Schaufenster hat keine Wohnfläche.
 */
final class PersonaReferenceTest extends ServiceTestCase
{
    /** Tag der Veröffentlichung von v3.6.0 — „heute“ der ausgerichteten Demo. */
    private const TODAY = '2026-10-20';

    private function load(string $persona, string $today = self::TODAY): array
    {
        $demo = new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
        $demo->import(true, $today, $persona === 'showcase' ? null : $persona);
        return (new ReferenceService($this->consumption, $this->meters, $this->settings))->compare((int)substr($today, 0, 4) - 1);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    public function testTheClassicHouseShowsTheFullTrafficLight(): void
    {
        $r = $this->load('eigenheim-klassisch');
        self::assertSame([], $r['missing'], 'nichts fehlt');
        self::assertSame([], $this->settings->assumedKeys(), 'Wohnfläche und Personen eingetragen');
        self::assertSame(['high', 'near', 'good'], [$r['strom']['rating'], $r['heating'][0]['rating'] ?? null, $r['water']['rating']],
            'rot, gelb, grün: ' . json_encode([$r['strom']['delta_pct'], $r['heating'][0]['delta_pct'] ?? null, $r['water']['delta_pct']]));
        self::assertSame('gas', $r['heating'][0]['utility']);
        self::assertNull($r['source'], 'keine Quelle: der Text bliebe in jeder Sprache deutsch');
        // auch das Jahr davor (Abstand zu den Grenzen, falls die Demo später erzeugt wird)
        $prev = (new ReferenceService($this->consumption, $this->meters, $this->settings))->compare(2024);
        self::assertSame(['high', 'near', 'good'], [$prev['strom']['rating'], $prev['heating'][0]['rating'] ?? null, $prev['water']['rating']]);
    }

    public function testTheOtherHouseholdsInviteToTheirOwnReferenceValues(): void
    {
        foreach (['mieterin', 'etw-fernwaerme', 'eigenheim-modern'] as $p) {
            $r = $this->load($p);
            self::assertSame([], $this->settings->assumedKeys(), "$p: Wohnfläche und Personen eingetragen");
            self::assertSame('reference_strom_kwh', $r['missing'][0] ?? null, "$p: Einladung zum Vergleichswert");
            self::assertNotContains('wohnflaeche_m2', $r['missing'], $p);
            self::assertNotContains('wasser_personen_anzahl', $r['missing'], $p);
        }
        $this->load('showcase');
        self::assertSame(['wohnflaeche_m2'], $this->settings->assumedKeys(), 'Schaufenster: Fläche nur angenommen');
    }
}
