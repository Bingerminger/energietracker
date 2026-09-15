<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v2.5.0 — F1012: Datierte Gas-Umrechnungsfaktoren (Zustandszahl × Brennwert).
 *
 * Ein Gaszähler zählt Kubikmeter. Die Kilowattstunden entstehen erst über
 * `m³ × Zustandszahl × Brennwert`. Die Zustandszahl hängt an der
 * Entnahmestelle und ändert sich praktisch nie; der Brennwert ist ein
 * Periodenmittel des Netzbetreibers und wechselt mehrmals im Jahr — eine
 * Jahresrechnung führt ihn typischerweise mit drei bis vier verschiedenen
 * Werten, jeder mit eigenem Zeitraum.
 *
 * Bis v2.4.2 kannte der Tracker einen einzigen Skalar `gas_conversion_factor`.
 * Jetzt ist es eine Liste datierter Einträge in den Einstellungen:
 *
 *     gas_conversion_factors: [
 *       { from: null,         kwh_per_m3: 11.5 },                        ← Altwert
 *       { from: "2024-01-01", zustandszahl: 0.96,   brennwert: 11.4,
 *                             kwh_per_m3: 10.944 },
 *       …
 *     ]
 *
 * **Wirksam** an einem Tag ist der letzte Eintrag, dessen `from` nicht nach
 * dem Tag liegt. Vor dem ersten datierten Eintrag gilt der undatierte
 * (`from: null`) — das ist der migrierte Altwert, und damit rechnet die
 * Historie exakt wie vor v2.5.0. Nichts springt rückwirkend.
 *
 * `kwh_per_m3` ist immer der Wert, der rechnet. Sind Zustandszahl und
 * Brennwert angegeben, wird er daraus mit fünf Nachkommastellen abgeleitet —
 * ein auf zwei Stellen gerundeter Faktor läge bei 1.100 m³ schon zwei
 * Kilowattstunden neben der Rechnung.
 *
 * Nur Gas ist datiert. Heizöl und Pellets behalten ihren Skalar; dort gibt
 * es keinen Netzbetreiber, der monatlich einen neuen Brennwert veröffentlicht.
 */
final class ConversionFactorService
{
    /** Nachkommastellen des abgeleiteten Faktors. */
    public const FACTOR_SCALE = 5;

    /** Vernünftige Grenzen: Zustandszahl 0,8–1,1 · Brennwert 8–13 · Faktor 5–15. */
    private const Z_MIN  = 0.8;
    private const Z_MAX  = 1.1;
    private const HS_MIN = 8.0;
    private const HS_MAX = 13.0;
    private const F_MIN  = 5.0;
    private const F_MAX  = 15.0;

    public function __construct(
        private SettingsService $settings,
        private ?I18nService $i18n = null,
    ) {}

    // ── Lesen ───────────────────────────────────────────────────────────

    /**
     * Die normalisierte, sortierte Liste aus den Einstellungen. Ist sie leer
     * oder unbrauchbar, greift der Default aus `SettingsService`.
     *
     * @return array<int,array{from:?string,zustandszahl:?float,brennwert:?float,kwh_per_m3:float}>
     */
    public function gasFactors(): array
    {
        $raw = $this->settings->get('gas_conversion_factors', []);
        try {
            // Lesen ist tolerant (strict = false): Was gespeichert wurde, gilt.
            $list = self::normalizeList($raw, null, false);
        } catch (\InvalidArgumentException) {
            $list = [];
        }
        if ($list === []) {
            $list = self::normalizeList(
                SettingsService::defaultGasConversionFactors(), null, false
            );
        }
        return $list;
    }

    /**
     * Der wirksame Faktor an einem Tag. Für Gas aus der datierten Liste, für
     * alle anderen Verbrauchsarten unverändert der Skalar aus den Einstellungen.
     */
    public function factorOn(string $utility, string $date): float
    {
        if ($utility === 'gas') {
            return (float)($this->entryOn($date)['kwh_per_m3'] ?? 1.0);
        }
        $u = Utilities::get($utility);
        if (empty($u['unit_to_kwh'])) return 1.0;
        return (float)$this->settings->get((string)($u['conversion_setting'] ?? ''), 1.0);
    }

    /**
     * Der wirksame Eintrag an einem Tag — mit Zustandszahl und Brennwert,
     * sofern hinterlegt. Die Rechnungsprüfung zeigt sie neben dem Faktor.
     *
     * @return array{from:?string,zustandszahl:?float,brennwert:?float,kwh_per_m3:float}
     */
    public function entryOn(string $date): array
    {
        $list  = $this->gasFactors();
        $best  = null;
        foreach ($list as $e) {
            if ($e['from'] === null) { $best ??= $e; continue; }
            if ($e['from'] <= $date) $best = $e;
        }
        // Kein undatierter Eintrag und alle datierten liegen in der Zukunft:
        // der früheste gilt — besser als gar keine Umrechnung.
        return $best ?? $list[0];
    }

    /**
     * Alle Stichtage, die echt INNERHALB von ($from, $to) liegen — die Tage,
     * an denen die tagesgenaue Verteilung ein Intervall teilen muss.
     *
     * @return string[] ISO-Daten, aufsteigend
     */
    public function boundariesBetween(string $from, string $to): array
    {
        $out = [];
        foreach ($this->gasFactors() as $e) {
            if ($e['from'] !== null && $e['from'] > $from && $e['from'] < $to) {
                $out[] = $e['from'];
            }
        }
        return $out;
    }

    // ── Normalisieren ───────────────────────────────────────────────────

    /**
     * Normalisiert und validiert eine Liste. **Statisch und rein**, damit
     * `SettingsService::set()` sie beim Speichern aufrufen kann, ohne eine
     * Abhängigkeit auf diesen Dienst aufzubauen (dasselbe Muster wie
     * `MeterService::activeBaselineEvent()` in F1011).
     *
     * Regeln:
     *  - Jeder Eintrag braucht entweder `zustandszahl` UND `brennwert` (dann
     *    wird `kwh_per_m3` daraus abgeleitet) oder ein direktes `kwh_per_m3`.
     *  - `from` ist ein ISO-Datum oder null; höchstens EIN Eintrag darf
     *    undatiert sein, und kein Datum darf doppelt vorkommen.
     *  - Sortierung: undatiert zuerst, dann aufsteigend nach Datum.
     *  - Werte außerhalb plausibler Grenzen werden abgelehnt — ein Tippfehler
     *    wie 115 statt 11,5 würde sonst jeden Verbrauch verzehnfachen.
     *
     * `$strict` unterscheidet Eingabe von Bestand: Beim **Speichern** (strict)
     * werden die Plausibilitätsgrenzen erzwungen. Beim **Lesen** von der
     * Platte (nicht strict) wird nur verlangt, dass der Faktor positiv ist —
     * ein Wert, der einmal gespeichert wurde, darf nicht beim nächsten Lesen
     * still durch den Default ersetzt werden (F1011-Lehre: kein stiller
     * Rückfall).
     *
     * @param  callable|null $t  Übersetzer für Fehlermeldungen (key, params)
     * @return array<int,array{from:?string,zustandszahl:?float,brennwert:?float,kwh_per_m3:float}>
     */
    public static function normalizeList(mixed $raw, ?callable $t, bool $strict = true): array
    {
        $t ??= static fn(string $key, array $p = []): string => $key;

        if ($raw === null || $raw === '' || $raw === false) return [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException($t('errors.settings.factorsNotList'));
        }

        $out = [];
        $seenNull = false;
        $seenDates = [];
        foreach ($raw as $e) {
            if (!is_array($e)) {
                throw new \InvalidArgumentException($t('errors.settings.factorsNotList'));
            }

            $from = $e['from'] ?? null;
            if ($from === '' ) $from = null;
            if ($from !== null) {
                $from = trim((string)$from);
                if (!self::isIsoDate($from)) {
                    throw new \InvalidArgumentException(
                        $t('errors.settings.factorInvalidDate', ['date' => $from])
                    );
                }
                if (isset($seenDates[$from])) {
                    throw new \InvalidArgumentException(
                        $t('errors.settings.factorDuplicateDate', ['date' => $from])
                    );
                }
                $seenDates[$from] = true;
            } else {
                if ($seenNull) {
                    throw new \InvalidArgumentException($t('errors.settings.factorTwoUndated'));
                }
                $seenNull = true;
            }

            $z  = self::floatOrNull($e['zustandszahl'] ?? null);
            $hs = self::floatOrNull($e['brennwert'] ?? null);
            $f  = self::floatOrNull($e['kwh_per_m3'] ?? null);

            if ($z !== null && $hs !== null) {
                if ($strict && ($z < self::Z_MIN || $z > self::Z_MAX)) {
                    throw new \InvalidArgumentException(
                        $t('errors.settings.factorZOutOfRange', ['value' => $z])
                    );
                }
                if ($strict && ($hs < self::HS_MIN || $hs > self::HS_MAX)) {
                    throw new \InvalidArgumentException(
                        $t('errors.settings.factorHsOutOfRange', ['value' => $hs])
                    );
                }
                $f = round($z * $hs, self::FACTOR_SCALE);
            } elseif ($z !== null || $hs !== null) {
                // Nur eine Hälfte angegeben — das ist kein vollständiger Beleg.
                throw new \InvalidArgumentException($t('errors.settings.factorHalfPair'));
            } elseif ($f === null) {
                throw new \InvalidArgumentException($t('errors.settings.factorMissing'));
            }

            if ($f <= 0) {
                throw new \InvalidArgumentException($t('errors.settings.factorMissing'));
            }
            if ($strict && ($f < self::F_MIN || $f > self::F_MAX)) {
                throw new \InvalidArgumentException(
                    $t('errors.settings.factorOutOfRange', ['value' => $f])
                );
            }

            $out[] = [
                'from'         => $from,
                'zustandszahl' => $z,
                'brennwert'    => $hs,
                'kwh_per_m3'   => round($f, self::FACTOR_SCALE),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['from'] === null) return $b['from'] === null ? 0 : -1;
            if ($b['from'] === null) return 1;
            return strcmp($a['from'], $b['from']);
        });
        return $out;
    }

    private static function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === false) return null;
        if (is_string($v)) $v = str_replace(',', '.', trim($v));
        if (!is_numeric($v)) return null;
        return (float)$v;
    }

    private static function isIsoDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
