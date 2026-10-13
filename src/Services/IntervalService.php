<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.3.0 (F1021) — Werte je Viertelstunde: Verbrauch je Zähler und
 * Börsenpreis, je Kalenderjahr eine Reihe.
 *
 * Ablage (Schema 1.8.0):
 *   intervals.json            Index: {series: [...], samples: {...}}
 *   intervals/<id>.json       {id, kind, year, step, start, values: [...]}
 *
 * Eine Reihe hat für jede Viertelstunde des Jahres einen Platz: Platz i
 * beginnt um start + i × 900 s (UTC). Das Jahr läuft in der Zeitzone der
 * Installation von 1. Januar 00:00 bis 1. Januar 00:00 — so hat ein Jahr
 * 35.040 Plätze, der Tag der Zeitumstellung 92 bzw. 100. Fehlende Werte sind
 * null. Verbrauch in kWh je Viertelstunde, Preise in ct/kWh.
 *
 * Quellen: Zeitreihen aus Portalen (SeriesImportService), Zählerstände mit
 * Zeitstempel aus Home Assistant (addCounterSample) und Börsenpreise von
 * SMARD (MarketPriceService). Ein neuer Wert ersetzt den alten am selben
 * Platz.
 */
final class IntervalService
{
    public const INDEX = 'intervals.json';
    public const DIR = 'intervals';
    public const ID = '/^[a-z0-9][a-z0-9_.-]{1,100}$/';
    public const STEP = 900;
    public const AREA = 'DE-LU';
    /** Längste Lücke zwischen zwei Zählerständen, die noch verteilt wird. */
    public const MAX_SAMPLE_GAP = 6 * 3600;

    public function __construct(private JsonStore $store, private SettingsService $settings) {}

    public function tz(): \DateTimeZone
    {
        return new \DateTimeZone((string)$this->settings->get('timezone', 'Europe/Berlin'));
    }

    /** Beginn eines Jahres (1.1. 00:00 in der Zeitzone der Installation) als UTC-Sekunden. */
    public function yearStart(int $year): int
    {
        return (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $this->tz()))->getTimestamp();
    }

    public function slots(int $year): int
    {
        return intdiv($this->yearStart($year + 1) - $this->yearStart($year), self::STEP);
    }

    /** Jahr (Zeitzone der Installation) eines UTC-Zeitpunkts. */
    public function yearOf(int $ts): int
    {
        return (int)(new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz())->format('Y');
    }

    public static function energyId(string $utility, string $meterId, int $year): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '-', "e-$utility-$meterId-$year") ?? '');
    }

    public static function priceId(int $year): string
    {
        return 'p-' . strtolower(self::AREA) . '-' . $year;
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** @return array{series: list<array<string,mixed>>, samples: array<string,mixed>} */
    private function index(): array
    {
        $raw = $this->store->read(self::INDEX, []);
        $series = is_array($raw['series'] ?? null) ? array_values(array_filter($raw['series'], 'is_array')) : [];
        $samples = is_array($raw['samples'] ?? null) ? $raw['samples'] : [];
        return ['series' => $series, 'samples' => $samples];
    }

    /** @return list<array<string,mixed>> Index der Reihen, nach Art, Zähler und Jahr */
    public function list(): array
    {
        $s = $this->index()['series'];
        usort($s, fn($a, $b) => [$a['kind'] ?? '', $a['meter_id'] ?? '', $a['year'] ?? 0] <=> [$b['kind'] ?? '', $b['meter_id'] ?? '', $b['year'] ?? 0]);
        return $s;
    }

    /** Steht die Reihe im Index? Nur dann gilt ihre Datei. */
    private function known(string $id): bool
    {
        if (!preg_match(self::ID, $id)) return false;
        foreach ($this->index()['series'] as $e) if (($e['id'] ?? null) === $id) return true;
        return false;
    }

    /** @return list<?float>|null Werte einer Reihe (null, wenn es sie nicht gibt) */
    public function values(string $id): ?array
    {
        if (!$this->known($id) || !$this->store->exists(self::DIR . "/$id.json")) return null;
        $d = $this->store->read(self::DIR . "/$id.json", []);
        // JSON macht aus 10.0 eine 10 — einheitlich float (oder null)
        return is_array($d['values'] ?? null) ? array_map(fn($v) => $v === null ? null : (float)$v, array_values($d['values'])) : null;
    }

    /** @return list<?float>|null */
    public function energy(string $utility, string $meterId, int $year): ?array
    {
        return $this->values(self::energyId($utility, $meterId, $year));
    }

    /** @return list<?float>|null */
    public function prices(int $year): ?array
    {
        return $this->values(self::priceId($year));
    }

    /** Ganze Reihe (für das Backup). @return array<string,mixed>|null */
    public function series(string $id): ?array
    {
        if (!$this->known($id) || !$this->store->exists(self::DIR . "/$id.json")) return null;
        $d = $this->store->read(self::DIR . "/$id.json", []);
        return is_array($d) ? $d : null;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Verbrauch je Viertelstunde übernehmen. Schlüssel: UTC-Beginn der
     * Viertelstunde (auf 900 s gerundet). Ersetzt vorhandene Werte.
     *
     * @param array<int,float> $slots
     * @return array{series: int, slots: int}
     */
    public function mergeEnergy(string $utility, string $meterId, array $slots, string $source): array
    {
        if (!Utilities::exists($utility)) throw new LocalizedException('errors.common.unknownUtility', ['utility' => $utility], 'interval utility');
        return $this->merge($slots, fn(int $y) => [
            'id' => self::energyId($utility, $meterId, $y), 'kind' => 'energy', 'utility' => $utility,
            'meter_id' => $meterId, 'unit' => 'kWh', 'year' => $y,
        ], $source, 4);
    }

    /**
     * Börsenpreise je Viertelstunde (ct/kWh) übernehmen.
     *
     * @param array<int,float> $slots
     * @return array{series: int, slots: int}
     */
    public function mergePrices(array $slots, string $source): array
    {
        return $this->merge($slots, fn(int $y) => [
            'id' => self::priceId($y), 'kind' => 'price', 'area' => self::AREA, 'unit' => 'ct/kWh', 'year' => $y,
        ], $source, 3);
    }

    /**
     * @param array<int,float> $slots
     * @param callable(int): array<string,mixed> $meta
     * @return array{series: int, slots: int}
     */
    private function merge(array $slots, callable $meta, string $source, int $decimals): array
    {
        $byYear = [];
        foreach ($slots as $ts => $v) {
            if (!is_numeric($v)) continue;
            $ts = intdiv((int)$ts, self::STEP) * self::STEP;
            $byYear[$this->yearOf($ts)][$ts] = (float)$v;
        }
        if ($byYear === []) return ['series' => 0, 'slots' => 0];
        // v3.4.0 (N1020) — Sperre vor dem Lesen-Ändern-Schreiben (beim SMARD-Abruf erst nach dem Abruf)
        $this->store->lock();
        $idx = $this->index();
        $total = 0;
        foreach ($byYear as $year => $vals) {
            $m = $meta((int)$year);
            $values = $this->values($m['id']) ?? array_fill(0, $this->slots((int)$year), null);
            $start = $this->yearStart((int)$year);
            foreach ($vals as $ts => $v) {
                $i = intdiv($ts - $start, self::STEP);
                if ($i < 0 || $i >= count($values)) continue;
                $values[$i] = round($v, $decimals);
                $total++;
            }
            $this->write($idx, $m, $values, $source, (int)$year);
        }
        $this->store->write(self::INDEX, $idx);
        return ['series' => count($byYear), 'slots' => $total];
    }

    /** Reihe schreiben und ihren Indexeintrag nachführen. */
    private function write(array &$idx, array $meta, array $values, string $source, int $year): void
    {
        $start = $this->yearStart($year);
        $filled = array_keys(array_filter($values, fn($v) => $v !== null));
        $this->store->write(self::DIR . '/' . $meta['id'] . '.json', [
            'id' => $meta['id'], 'kind' => $meta['kind'], 'year' => $year, 'step' => self::STEP,
            'start' => (new \DateTimeImmutable('@' . $start))->setTimezone($this->tz())->format('c'),
            'values' => $values,
        ]);
        $iso = fn(int $i) => (new \DateTimeImmutable('@' . ($start + $i * self::STEP)))->setTimezone($this->tz())->format('c');
        $entry = $meta + [
            'step' => self::STEP, 'slots' => count($values), 'filled' => count($filled),
            'from' => $filled ? $iso((int)$filled[0]) : null, 'to' => $filled ? $iso((int)end($filled) + 1) : null,
            'source' => $source, 'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($idx['series'] as $k => $e) {
            if (($e['id'] ?? null) === $meta['id']) { $idx['series'][$k] = $entry; $found = true; }
        }
        if (!$found) $idx['series'][] = $entry;
    }

    /**
     * Zählerstand mit Zeitstempel (Home Assistant): Die Differenz zum
     * vorigen Stand verteilt sich nach Zeitanteil auf die Viertelstunden
     * dazwischen. Gleicher oder älterer Zeitpunkt, ein Rückgang oder eine
     * Lücke über sechs Stunden verteilen nichts (der neue Stand gilt dann als
     * Anfang).
     *
     * @return int Zahl der berührten Viertelstunden
     */
    public function addCounterSample(string $utility, string $meterId, int $ts, float $counter): int
    {
        $idx = $this->index();
        $key = "$utility/$meterId";
        $prev = $idx['samples'][$key] ?? null;
        $idx['samples'][$key] = ['ts' => $ts, 'counter' => $counter];
        if (!is_array($prev) || !isset($prev['ts'], $prev['counter'])) {
            $this->store->write(self::INDEX, $idx);
            return 0;
        }
        $t0 = (int)$prev['ts'];
        $delta = $counter - (float)$prev['counter'];
        if ($ts <= $t0) return 0;                       // doppelt oder zu spät: nichts ändern
        $this->store->write(self::INDEX, $idx);
        if ($delta < 0 || $ts - $t0 > self::MAX_SAMPLE_GAP) return 0;
        $slots = [];
        $first = intdiv($t0, self::STEP) * self::STEP;
        for ($s = $first; $s < $ts; $s += self::STEP) {
            $overlap = min($ts, $s + self::STEP) - max($t0, $s);
            if ($overlap > 0) $slots[$s] = $delta * $overlap / ($ts - $t0);
        }
        // Lag der vorige Stand mitten in einer Viertelstunde, hat sie schon
        // ihren ersten Teil — dazu addieren statt ersetzen
        if ($t0 > $first && isset($slots[$first])) {
            $y = $this->yearOf($first);
            $old = ($this->energy($utility, $meterId, $y) ?? [])[intdiv($first - $this->yearStart($y), self::STEP)] ?? null;
            if ($old !== null) $slots[$first] += (float)$old;
        }
        $this->mergeEnergy($utility, $meterId, $slots, 'ingest');
        return count($slots);
    }

    public function delete(string $id): void
    {
        $idx = $this->index();
        $before = count($idx['series']);
        $idx['series'] = array_values(array_filter($idx['series'], fn($e) => ($e['id'] ?? null) !== $id));
        if (count($idx['series']) === $before) throw new LocalizedException('errors.intervals.notFound', ['id' => $id], "interval $id");
        $this->store->write(self::INDEX, $idx);
        $this->store->delete(self::DIR . "/$id.json");
    }

    // ── Zeit ────────────────────────────────────────────────────────────

    /**
     * Ortszeit je Platz eines Jahres: Monat (1–12), Quartal (1–4) und Minute
     * des Tages (0–1439). Rechnet mit den Übergängen der Zeitzone, nicht je
     * Platz mit DateTime.
     *
     * @return array{month: list<int>, minute: list<int>}
     */
    public function localClock(int $year): array
    {
        $tz = $this->tz();
        $start = $this->yearStart($year);
        $n = $this->slots($year);
        $end = $start + $n * self::STEP;
        $transitions = $tz->getTransitions($start - 86400, $end) ?: [];
        $offsetAt = function (int $ts) use ($transitions): int {
            $off = 0;
            foreach ($transitions as $t) { if ($t['ts'] <= $ts) $off = (int)$t['offset']; else break; }
            return $off;
        };
        $month = []; $minute = [];
        $nextChange = PHP_INT_MAX; $off = $offsetAt($start);
        foreach ($transitions as $t) { if ($t['ts'] > $start) { $nextChange = $t['ts']; break; } }
        for ($i = 0; $i < $n; $i++) {
            $ts = $start + $i * self::STEP;
            if ($ts >= $nextChange) {
                $off = $offsetAt($ts);
                $nextChange = PHP_INT_MAX;
                foreach ($transitions as $t) { if ($t['ts'] > $ts) { $nextChange = $t['ts']; break; } }
            }
            $local = $ts + $off;
            $month[$i] = (int)gmdate('n', $local);
            $minute[$i] = intdiv($local % 86400, 60);
        }
        return ['month' => $month, 'minute' => $minute];
    }
}
