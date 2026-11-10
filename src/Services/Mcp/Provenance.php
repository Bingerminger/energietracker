<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Herkunft einer Zahl für die KI (Konzept §3.1).
 *
 * Fünf Stufen, von stark nach schwach. Die KI rechnet nichts selbst: Jede Zahl
 * kommt aus einem Werkzeug und trägt ihre Herkunft, als Zahl-Objekt (`Num`)
 * oder, in Reihen, als `basis` je Zeile.
 *
 *  - `measured`    aus echten Ablesungen (Monat aus Abständen bis 45 Tage, Viertelstunden)
 *  - `entered`     von einer Person oder aus einem übernommenen Dokument eingetragen
 *                  (Preise, Abschläge, Rechnungsbeträge, datierte Faktoren)
 *  - `distributed` zwischen zwei echten Ablesungen gleichmäßig auf die Tage verteilt
 *  - `assumed`     ohne Eintrag fortgeschrieben (Preis nach der Preisgarantie,
 *                  weiterlaufender Vertrag, undatierter Gasfaktor, Klimanormal)
 *  - `estimated`   über die letzte Ablesung hinaus hochgerechnet, Prognose, Szenario,
 *                  als geschätzt markierte Ablesung
 *
 * Die Abbildungen aus den Diensten (Monatszeile, Saldo, Rechnungsprüfung …) bringt
 * das Paket „lesen“; hier stehen nur Vokabular und Regeln.
 */
final class Provenance
{
    /** Reihenfolge von stark nach schwach (Index = Rang). */
    public const BASES = ['measured', 'entered', 'distributed', 'assumed', 'estimated'];

    /** Saldo-Vorzeichen: positiv heißt Nachzahlung (Verbrauch) … */
    public const SIGN_BACK_PAYMENT = 'positive_is_back_payment';
    /** … bzw. Auszahlung (Einspeisung). */
    public const SIGN_PAYOUT = 'positive_is_payout';
    public const SIGNS = [self::SIGN_BACK_PAYMENT, self::SIGN_PAYOUT];
    /**
     * v3.12.0 — was ein Saldo heißt, ohne dass jemand das Vorzeichen deuten muss. Im Praxistest las ein
     * lokales Modell ohne Server-Anweisungen −828,01 als „Nachzahlung“, obwohl `sign` danebenstand.
     * `no_price`: Vertrag ohne Arbeitspreis, die Zahl sind nur die Abschläge (Skeptiker M5).
     */
    public const MEANINGS = ['credit', 'back_payment', 'balanced', 'payout', 'reclaim', 'no_price'];

    public static function isBasis(mixed $basis): bool
    {
        return is_string($basis) && in_array($basis, self::BASES, true);
    }

    /** Rang einer Herkunft (0 = gemessen … 4 = geschätzt). */
    public static function rank(string $basis): int
    {
        $i = array_search($basis, self::BASES, true);
        if ($i === false) throw new \LogicException("mcp: unknown basis '$basis'");
        return (int)$i;
    }

    /**
     * Schwächste Herkunft der Bestandteile: Ein Ergebnis aus gemessenem und
     * geschätztem Verbrauch ist geschätzt.
     */
    public static function weakest(string ...$bases): string
    {
        if ($bases === []) throw new \LogicException('mcp: weakest() needs at least one basis');
        $worst = 0;
        foreach ($bases as $b) $worst = max($worst, self::rank($b));
        return self::BASES[$worst];
    }

    // ── Abbildungen aus den Diensten (Paket lesen, Konzept §3.1) ──────────

    /** Anteile unter diesem Wert fallen weg (Num rundet Anteile auf zwei Stellen). */
    public const PART_MIN = 0.005;

    /**
     * Monatszeile (ConsumptionService::forMeter, `monthly_total`) → Herkunft der Menge,
     * wie die Oberfläche sie kennzeichnet (lib/interpolation.js):
     *  - Tage nach dem letzten bekannten Bestand (`estimated_days`, Tankbuch) → geschätzt
     *  - Monat nach `$measuredUntil` (letzte Ablesung) → geschätzt
     *  - `interpolated: true` → verteilt; mit geschätzter Ablesung als Stütze (`reading_gap_estimated`) → geschätzt
     *  - `interpolated: false` → gemessen
     *  - ohne Kennung (`null`: Lieferarten nach Gradtagen, Summe mit einem Mitglied ohne Kennung) → verteilt
     *
     * @param array<string,mixed> $row
     */
    public static function month(array $row, ?string $measuredUntil = null): string
    {
        if ($measuredUntil !== null && (string)($row['ym'] ?? '') > substr($measuredUntil, 0, 7)) return 'estimated';
        if ((int)($row['estimated_days'] ?? 0) > 0) return 'estimated';
        $interpolated = $row['interpolated'] ?? null;
        if ($interpolated === null) return 'distributed';
        if ($interpolated === true) return !empty($row['reading_gap_estimated']) ? 'estimated' : 'distributed';
        return 'measured';
    }

    /** Herkunft eines Preises der Monatszeile: fortgeschriebener Vertrag → angenommen. */
    public static function monthPrice(array $row): string
    {
        return !empty($row['contract_assumed']) ? 'assumed' : 'entered';
    }

    /** Ablesung → gemessen, als geschätzt erfasst → geschätzt. */
    public static function reading(array $reading): string
    {
        return empty($reading['is_estimated']) ? 'measured' : 'estimated';
    }

    /**
     * Randart der Rechnungsprüfung (BillService::boundaries) → Herkunft des Stands:
     * Ablesung dort oder am Nachbartag gemessen, geschätzt erfasst geschätzt,
     * zwischen Ablesungen gerechnet verteilt; ohne Ablesung (Lücke) geschätzt.
     */
    public static function boundary(?string $kind): string
    {
        return match ($kind) {
            'reading', 'reading_adjacent' => 'measured',
            'interpolated'                => 'distributed',
            default                       => 'estimated',
        };
    }

    /**
     * Preise und Abschlag eines Vertrags (Zeile aus contractStatus): eingetragen; nach
     * dem Ende der Preisgarantie oder fortgeschrieben (weiterlaufend ohne Eintrag) angenommen.
     * v3.12.0 (integration) — `$priceFrom`: Beginn des Eintrags, aus dem der Wert stammt. Beginnt er
     * erst nach dem Vertragsende bzw. nach der Preisgarantie, hat jemand ihn eingetragen (v3.11.0:
     * Preis-Einträge nach dem Ende eines verlängerten Vertrags gelten): eingetragen.
     *
     * @param array<string,mixed> $contract
     */
    public static function price(array $contract, string $today, ?string $priceFrom = null): string
    {
        if (!empty($contract['renewed'])) {
            $end = (string)($contract['end'] ?? '');
            return $priceFrom !== null && $end !== '' && $priceFrom > $end ? 'entered' : 'assumed';
        }
        $guarantee = (string)($contract['price_guarantee_until'] ?? '');
        if ($guarantee === '' || $guarantee >= $today) return 'entered';
        return $priceFrom !== null && $priceFrom > $guarantee ? 'entered' : 'assumed';
    }

    /**
     * Anteile je Herkunft an einer Summe: `$weights` Herkunft → Gewicht (Betrag).
     * Sehr kleine Anteile fallen weg, der Rest wird wieder auf 1 gebracht; leer, wenn
     * nichts wiegt. Reihenfolge wie BASES.
     *
     * @param array<string,float|int> $weights
     * @return array<string,float>
     */
    public static function normalize(array $weights): array
    {
        $weights = array_filter($weights, fn($w) => is_numeric($w) && (float)$w > 0);
        $sum = array_sum($weights);
        if ($sum <= 0) return [];
        $weights = array_filter($weights, fn($w) => (float)$w / $sum >= self::PART_MIN);
        $sum = array_sum($weights);
        $out = [];
        foreach (self::BASES as $b) {
            if (isset($weights[$b])) $out[$b] = (float)$weights[$b] / $sum;
        }
        return $out;
    }

    /**
     * Anteile je Herkunft an der Summe von Zeilen, gewogen nach dem Betrag des Felds
     * `$field` (ohne Menge nach Tagen). `$basisOf` liefert die Herkunft einer Zeile
     * (Standard: Monatszeile).
     *
     * @param list<array<string,mixed>> $rows
     * @param (callable(array<string,mixed>): string)|null $basisOf
     * @return array<string,float>
     */
    public static function partsOf(array $rows, string $field, ?callable $basisOf = null): array
    {
        $basisOf ??= static fn(array $r): string => self::month($r);
        $byValue = []; $byDays = [];
        foreach ($rows as $r) {
            $b = $basisOf($r);
            $byValue[$b] = ($byValue[$b] ?? 0.0) + abs((float)($r[$field] ?? 0.0));
            $byDays[$b] = ($byDays[$b] ?? 0) + max(0, (int)($r['days'] ?? 0));
        }
        return array_sum($byValue) > 1e-9 ? self::normalize($byValue) : self::normalize($byDays);
    }

    /**
     * Zahl-Objekt einer Summe mit Anteilen: eine Herkunft → schlicht, mehrere →
     * `derived` mit `parts` (Herkunft = die schwächste). Ohne Anteile null.
     *
     * @param array<string,float> $parts
     * @param array<string,mixed> $opts wie Num::of()
     * @return array<string,mixed>|null
     */
    public static function num(float|int $value, string $unit, array $parts, array $opts = []): ?array
    {
        if ($parts === []) return null;
        if (count($parts) === 1) return Num::of($value, $unit, (string)array_key_first($parts), $opts);
        return Num::derived($value, $unit, $parts, $opts);
    }

    /** Betrag einer Summe mit Anteilen (wie num(), auf Cent). */
    public static function money(float|int $value, string $currency, array $parts, array $opts = []): ?array
    {
        return self::num($value, $currency, $parts, ['decimals' => Num::MONEY_DECIMALS] + $opts);
    }

    /**
     * Herkunft der Kosten einer Zeile: die schwächere aus Menge und Preis.
     *
     * @param array<string,mixed> $row
     */
    public static function monthCost(array $row): string
    {
        return self::weakest(self::month($row), self::monthPrice($row));
    }

    /**
     * Saldo bis heute (`current_balance` aus contractStatus): Arbeitskosten bis zur
     * letzten Ablesung mit der Herkunft ihrer Monate (`$monthParts`, gemessen bzw.
     * verteilt), geschätzte Arbeitskosten danach (`estimated_cost_to_date`), Grundpreis,
     * Bonus und Abschläge eingetragen. Anteile am Betrag der Bestandteile.
     *
     * @param array<string,mixed> $contract
     * @param array<string,float> $monthParts Anteile der Monate bis zur letzten Ablesung
     * @return array<string,float>
     */
    public static function balanceParts(array $contract, array $monthParts): array
    {
        if (array_key_exists('energy_cost_to_date', $contract)) {
            $estimated = (float)($contract['estimated_cost_to_date'] ?? 0.0);
            $energy = abs((float)($contract['energy_cost_to_date'] ?? 0.0) - $estimated);
            $entered = abs((float)($contract['base_to_date'] ?? 0.0)) + abs((float)($contract['bonus_to_date'] ?? 0.0))
                + abs((float)($contract['cost_to_date'] ?? 0.0) - (float)($contract['current_balance'] ?? 0.0));
        } else {
            // abgelaufener Vertrag: nur die abgelesenen Monate (actual_*), keine Schätzung
            $estimated = 0.0;
            $energy = abs((float)($contract['actual_kwh_cost'] ?? 0.0));
            $entered = abs((float)($contract['actual_base_total'] ?? 0.0)) + abs((float)($contract['actual_bonus_total'] ?? 0.0))
                + abs((float)($contract['advance_paid'] ?? 0.0));
        }
        $weights = ['estimated' => abs($estimated), 'entered' => $entered];
        foreach ($monthParts !== [] ? $monthParts : ['measured' => 1.0] as $b => $share) {
            $weights[$b] = ($weights[$b] ?? 0.0) + $energy * $share;
        }
        return self::normalize($weights) ?: ['entered' => 1.0];
    }

    /**
     * Summe über ganze Ablesungsabstände (an beiden Rändern liegt eine Ablesung, oder
     * die Daten beginnen bzw. enden dort): Das Verteilen auf Monate gleicht sich
     * innerhalb jedes Abstands aus, die Summe ist so gut wie ihre Ablesungen. Der Anteil
     * „verteilt“ wird dann zu `$to` (Menge: gemessen; Kosten: eingetragen, die
     * schwächere aus gemessener Menge und eingetragenem Preis). Geschätztes bleibt.
     *
     * @param array<string,float> $parts
     * @return array<string,float>
     */
    public static function aligned(array $parts, string $to = 'measured'): array
    {
        if (!isset($parts['distributed'])) return $parts;
        $parts[$to] = ($parts[$to] ?? 0.0) + $parts['distributed'];
        unset($parts['distributed']);
        return self::normalize($parts);
    }

    /** Bedeutung eines Saldos auf Cent genau: 0 ist ausgeglichen. */
    public static function meaning(float $value, bool $feedIn): string
    {
        if (abs($value) < 0.005) return 'balanced';
        if ($feedIn) return $value > 0 ? 'payout' : 'reclaim';
        return $value > 0 ? 'back_payment' : 'credit';
    }

    /** Vorzeichen eines Saldos der Art: Einspeisung zahlt aus, sonst ist positiv eine Nachzahlung. */
    public static function signFor(bool $feedIn): string
    {
        return $feedIn ? self::SIGN_PAYOUT : self::SIGN_BACK_PAYMENT;
    }
}
