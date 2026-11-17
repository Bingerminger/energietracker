<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.13.0 — Kleine erfundene Haushalte für die Tests der KI-Schnittstelle: Zähler, Stände und Verträge in wenigen
 * Zeilen, mit festem „heute“ im Kontext (nie das echte Datum).
 */
abstract class McpHouseholdTestCase extends McpReadTestCase
{
    /** „heute“ der kleinen Haushalte */
    public const DAY = '2026-11-13';

    /**
     * Zähler einer Art (überschreibt die vorhandenen); ein Gerät je Zähler ab 2020.
     *
     * @param array<string,string> $names ID → Name
     */
    protected function meters(string $utility, array $names, array $extra = []): void
    {
        $rows = [];
        foreach ($names as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => '2020-01-01', 'active' => true, 'notes' => '',
                'devices' => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
                    'removed_on' => null, 'final_counter' => null, 'reason' => null]]] + ($extra[$id] ?? []);
        }
        $this->store->write("$utility/meters.json", $rows);
        $active = (array)$this->settings->get('active_utilities', []);
        if (!in_array($utility, $active, true)) $this->settings->set(['active_utilities' => [...$active, $utility]]);
    }

    /**
     * Stände je Zähler: `[Tag, Stand]` oder `[Tag, Stand, true]` für einen geschätzten Stand.
     *
     * @param array<string,list<array{0:string,1:float|int,2?:bool}>> $byMeter
     */
    protected function counters(string $utility, array $byMeter): void
    {
        $rows = [];
        foreach ($byMeter as $id => $list) {
            foreach ($list as $i => $r) {
                $rows[] = ['id' => sprintf('r_%s_%03d', $id, $i), 'meter_id' => $id, 'date' => $r[0], 'counter' => (float)$r[1],
                    'device_id' => "d_$id", 'price_cents' => null, 'note' => '', 'is_estimated' => !empty($r[2]), 'is_future' => false];
            }
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $rows);
    }

    /** Monatsstände am Ersten von `$from` bis `$to` (JJJJ-MM), Verbrauch `$perMonth` je Monat. @return list<array{0:string,1:float}> */
    protected static function monthly(string $from, string $to, float $perMonth, float $start = 0.0): array
    {
        $out = [];
        $c = $start;
        for ($d = new \DateTimeImmutable("$from-01"); $d->format('Y-m') <= $to; $d = $d->modify('+1 month')) {
            $out[] = [$d->format('Y-m-d'), $c];
            $c += $perMonth;
        }
        return $out;
    }

    /** @param array<string,mixed> $args */
    protected function tool(string $name, array $args = [], string $today = self::DAY): array
    {
        return $this->callTool($name, $args, $this->context('mcp', $today));
    }

    /** Zeile einer Antwort nach `period`. @param list<array<string,mixed>> $rows */
    protected static function row(array $rows, string $period): array
    {
        foreach ($rows as $r) if (($r['period'] ?? null) === $period) return $r;
        self::fail("Zeile $period fehlt: " . implode(', ', array_column($rows, 'period')));
    }
}
