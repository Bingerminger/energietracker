<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Services\ContractService;

/**
 * v3.9.0 (F1034, vertrag-aus-rechnung) — Preise und Abschlagsplan einer
 * Rechnung so, wie ein Vertrag sie führt: brutto (die App führt Bruttopreise),
 * je Preisabschnitt ab seinem Tag, Bestandteile zu einem Preis zusammengezählt
 * und mit Aufschlüsselung für den Vorschlag.
 *
 *   - Wasser (`water_prices`): je Sparte der Arbeitspreis je m³, der Grundpreis
 *     je Monat (Basis-, Bereitstellungs- und Grundpreis derselben Sparte und
 *     desselben Abschnitts zusammen, ein Jahrespreis geteilt durch 12) und das
 *     Niederschlagswasser je m² und Jahr mit der Fläche.
 *   - Gas, Strom, Fernwärme (`working_prices`): Zeilen desselben Tags, die die
 *     Rechnung als Bestandteile ausweist (Arbeitspreis, Energiesteuer,
 *     CO₂-Preis), ergeben einen Arbeitspreis.
 *   - der neue Abschlag mit seinem Rhythmus (ContractService::installments).
 *
 * Umgerechnet wird mit denselben Bausteinen wie im Posteingang und im Entwurf
 * des Haushalts (Evidence::itemTax, Evidence::grossOf — wie
 * HouseholdDraft::priceOf): Ein Steuersatz wird gelesen, nie vermutet.
 */
final class InvoicePrices
{
    /** Einheiten der Wasserpreise und was sie im Vertrag werden. */
    private const WATER_KIND = ['m3' => 'working', 'year' => 'base', 'month' => 'base', 'm2_year' => 'rate'];

    /**
     * Ist ein Rechnungspreis sicher genug für einen Vertrag ohne Rückfrage? Wie
     * im Entwurf des Haushalts (HouseholdDraft::withoutInvoiceDoubt): Der
     * Zweifel „Preis von der Rechnung“ entfällt, wenn netto oder brutto belegt
     * erkannt ist (Steuersatz gelesen); jeder andere Zweifel an der Belegstelle
     * bleibt. Ein Preis ohne Marke (wie im Vertrag) ist sicher.
     *
     * @param array<string,mixed> $item Feld bzw. Listeneintrag nach Evidence::refine
     * @param array{vat_pct: float, net: bool}|null $tax
     */
    public static function sure(array $item, ?array $tax): bool
    {
        if (($item['status'] ?? '') !== 'check') return true;
        if ($tax === null) return false;
        return array_diff((array)($item['reasons'] ?? []), Evidence::HINTS, ['price_from_invoice']) === [];
    }

    /**
     * Ein Bestandteil zur Anzeige: wie gedruckt, Basis, Satz, brutto.
     *
     * @return array{label: string, price: float, unit: string, net: bool, vat_pct: ?float, gross: float}
     */
    private static function part(array $it, string $priceKey, string $unit): array
    {
        $v = (array)($it['value'] ?? []);
        $tax = Evidence::itemTax($it);
        $price = (float)$v[$priceKey];
        return ['label' => trim((string)($v['label'] ?? '')), 'price' => $price, 'unit' => $unit,
                'net' => $tax !== null && $tax['net'], 'vat_pct' => $tax['vat_pct'] ?? null, 'gross' => Evidence::grossOf($price, $tax)];
    }

    /**
     * Wasserpreise je Sparte und Abschnitt.
     *
     * @param array<string,array<string,mixed>> $fields Felder der Auslesung (nach Evidence::refine)
     * @param bool $confirmed der Nutzer hat das Feld bestätigt oder korrigiert (alle Einträge gelten)
     * @return array<string,array{working: list<array<string,mixed>>, base: list<array<string,mixed>>, rate: list<array<string,mixed>>}>
     *   je Sparte und Art die Abschnitte {from, value, area?, parts, sure, items};
     *   working in ct/m³, base in € je Monat, rate in € je m² und Jahr
     */
    public static function water(array $fields, bool $confirmed = false): array
    {
        $groups = [];
        foreach ((array)($fields['water_prices']['items'] ?? []) as $i => $it) {
            $v = (array)($it['value'] ?? []);
            $section = (string)($v['section'] ?? '');
            $unit = (string)($v['unit'] ?? '');
            $kind = self::WATER_KIND[$unit] ?? null;
            if ($kind === null || !in_array($section, ['trinkwasser', 'schmutzwasser', 'niederschlagswasser'], true)
                || !is_numeric($v['price'] ?? null) || !is_string($v['from'] ?? null)) continue;
            if ($section === 'niederschlagswasser' && $kind !== 'rate') continue;   // ein Grund- oder Mengenpreis dort hat keinen Platz im Vertrag
            if ($section !== 'niederschlagswasser' && $kind === 'rate') continue;
            $groups[$section][$kind][(string)$v['from']][] = [(int)$i, $it, $unit];
        }
        $out = [];
        foreach (['trinkwasser', 'schmutzwasser', 'niederschlagswasser'] as $section) {
            $out[$section] = ['working' => [], 'base' => [], 'rate' => []];
            foreach ((array)($groups[$section] ?? []) as $kind => $byFrom) {
                ksort($byFrom);
                foreach ($byFrom as $from => $list) {
                    $parts = []; $sum = 0.0; $sure = true; $areas = [];
                    foreach ($list as [$i, $it, $unit]) {
                        $p = self::part($it, 'price', $unit);
                        $parts[] = $p;
                        $tax = Evidence::itemTax($it);
                        $sure = $sure && ($confirmed || self::sure($it, $tax));
                        // je Monat: ein Jahrespreis geteilt durch 12
                        $sum += $kind === 'base' && $unit === 'year' ? $p['gross'] / 12 : $p['gross'];
                        if (is_numeric($it['value']['area_m2'] ?? null)) $areas[(string)round((float)$it['value']['area_m2'], 4)] = (float)$it['value']['area_m2'];
                    }
                    $row = ['from' => (string)$from, 'parts' => $parts, 'sure' => $sure, 'items' => array_column($list, 0)];
                    if ($kind === 'working') $row['value'] = round($sum * 100, 4);          // €/m³ → ct/m³
                    elseif ($kind === 'base') $row['value'] = round($sum, 4);              // € je Monat
                    else {
                        $row['value'] = round($sum, 4);                                    // € je m² und Jahr
                        // verschiedene Flächen im selben Abschnitt: nicht zu entscheiden
                        $row['area'] = count($areas) === 1 ? array_values($areas)[0] : null;
                        if ($row['area'] === null) $row['sure'] = false;
                    }
                    $out[$section][$kind][] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Arbeitspreise der Rechnung je Abschnitt. Zeilen desselben Tags, die die
     * Rechnung als Bestandteile ausweist — genau ein Arbeitspreis (`part:
     * energy`) und daneben Energiesteuer, CO₂-Preis oder ein Aufschlag je kWh —,
     * ergeben einen Arbeitspreis; ohne diese Angabe bleibt jede Zeile für sich
     * (wie bis v3.8).
     *
     * @param array<string,array<string,mixed>> $fields
     * @return list<array{from: string, value: float, items: list<int>, parts: list<array<string,mixed>>, merged: bool}>
     */
    public static function workingGroups(array $fields): array
    {
        $byFrom = [];
        foreach ((array)($fields['working_prices']['items'] ?? []) as $i => $it) {
            $v = (array)($it['value'] ?? []);
            if (!is_numeric($v['ct'] ?? null) || !is_string($v['from'] ?? null)) continue;
            $byFrom[(string)$v['from']][] = [(int)$i, $it];
        }
        $out = [];
        foreach ($byFrom as $from => $list) {
            $partsOf = array_map(fn($x) => (string)($x[1]['value']['part'] ?? ''), $list);
            $merge = count($list) > 1 && !in_array('', $partsOf, true)
                && count(array_keys($partsOf, 'energy', true)) === 1 && count(array_unique($partsOf)) === count($partsOf);
            if (!$merge) {
                foreach ($list as [$i, $it]) {
                    $p = self::part($it, 'ct', 'kwh');
                    $out[] = ['from' => (string)$from, 'value' => $p['gross'], 'items' => [$i], 'parts' => [$p + ['part' => (string)($it['value']['part'] ?? '')]], 'merged' => false];
                }
                continue;
            }
            $parts = []; $sum = 0.0;
            foreach ($list as [$i, $it]) {
                $p = self::part($it, 'ct', 'kwh') + ['part' => (string)$it['value']['part']];
                $parts[] = $p;
                $sum += $p['gross'];
            }
            $out[] = ['from' => (string)$from, 'value' => round($sum, 4), 'items' => array_column($list, 0), 'parts' => $parts, 'merged' => true];
        }
        usort($out, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $out;
    }

    /**
     * Der neue Abschlag einer Rechnung als Zeile eines Abschlagsplans: Betrag je
     * Termin; ab `advance_new_from`, sonst ab dem ersten genannten Termin (der
     * frühere gilt); mit den genannten Terminen. Der Rhythmus folgt aus den
     * Terminen (rhythmOfDates, mit Spielraum für Monatsende und Wochenende),
     * sonst aus dem Rhythmus laut Rechnung (`advance_new_every`, tolerant
     * gelesen: everyOf), sonst monatlich wie bisher. Widersprechen sich Feld und
     * Termine, gelten die Termine, und `rhythm_conflict` sagt es (der Aufrufer
     * belegt dann nicht vor). Unsichere Termine (markiert, nicht bestätigt) zählen
     * nicht. Ohne Betrag null; ohne Beginn `from` null (der Aufrufer nimmt dann
     * wie bisher den Monat nach dem Zeitraum an).
     *
     * @param array<string,mixed> $entry Eintrag des Posteingangs
     * @return array{from: ?string, amount_eur: float, every_months?: int, due_dates?: list<string>, rhythm_conflict?: true}|null
     */
    public static function advancePlan(array $entry): ?array
    {
        $amount = Proposals::value($entry, 'advance_new');
        if (!is_numeric($amount)) return null;
        $from = Proposals::value($entry, 'advance_new_from');
        $from = is_string($from) && $from !== '' ? $from : null;
        $dates = [];
        $field = (array)($entry['extraction']['fields']['advance_new_dates'] ?? []);
        $edited = array_key_exists('advance_new_dates', (array)($entry['edits'] ?? []));
        $list = $edited ? (array)($entry['edits']['advance_new_dates'] ?? []) : (array)($field['items'] ?? []);
        foreach ($list as $it) {
            $d = $edited ? (is_array($it) ? ($it['date'] ?? null) : $it) : ($it['value']['date'] ?? null);
            if (!$edited && ($it['status'] ?? '') === 'check') continue;
            if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $dates[$d] = true;
        }
        $dates = array_map('strval', array_keys($dates));
        sort($dates);
        $every = self::everyOf(Proposals::value($entry, 'advance_new_every'));
        $row = ['from' => $from, 'amount_eur' => (float)$amount];
        $conflict = false;
        if ($dates !== []) {
            $row['from'] = $from === null ? $dates[0] : min($from, $dates[0]);
            $due = array_values(array_filter($dates, fn($d) => $d >= $row['from']));
            $n = self::rhythmOfDates($due);
            // ein Termin allein sagt keinen Rhythmus: dann der laut Rechnung
            $rhythm = count($due) >= 2 ? $n : $every;
            if ($rhythm !== null && $rhythm > 1) $row['every_months'] = $rhythm;
            $row['due_dates'] = $due;
            $conflict = $every !== null && count($due) >= 2 && $n !== $every;
        } elseif ($every !== null && $every > 1) {
            $row['every_months'] = $every;
        }
        return $row + ($conflict ? ['rhythm_conflict' => true] : []);
    }

    /**
     * v3.9.0 (Skeptiker H2) — die laut Rechnung im abgerechneten Zeitraum gezahlten
     * Abschläge als Zeile des Abschlagsplans, damit Abrechnungsjahr, Bericht und
     * Rechnung dasselbe Bezahlte zählen. Mit gleich hohen, datierten Zahlungen
     * (mindestens zwei, alle im Zeitraum, Summe wie die Gesamtsumme): Betrag je
     * Termin an diesen Terminen (Rhythmus aus ihnen); sonst die Summe gleichmäßig
     * je Monat des Zeitraums (auf vier Stellen, die Summe geht auf den Cent auf).
     * Unsichere Angaben zählen nicht. Ohne Summe: leer.
     *
     * @param array<string,mixed> $entry Eintrag des Posteingangs
     * @return array{from: string, amount_eur: float, every_months?: int, due_dates?: list<string>, total: float}|null
     */
    public static function paidPlan(array $entry, string $from, string $to): ?array
    {
        $fields = (array)($entry['extraction']['fields'] ?? []);
        $edits = (array)($entry['edits'] ?? []);
        $items = [];
        foreach ((array)($fields['advances_paid']['items'] ?? []) as $it) {
            if (($it['status'] ?? '') === 'check' || !is_numeric($it['value']['amount'] ?? null)) continue;
            $items[] = ['amount' => (float)$it['value']['amount'], 'date' => is_string($it['value']['date'] ?? null) ? (string)$it['value']['date'] : null];
        }
        $totalSure = array_key_exists('advances_total', $edits) || ($fields['advances_total']['status'] ?? '') !== 'check';
        $total = $totalSure && is_numeric(Proposals::value($entry, 'advances_total')) ? (float)Proposals::value($entry, 'advances_total')
            : ($items !== [] ? array_sum(array_column($items, 'amount')) : null);
        if ($total === null || $total <= 0) return null;
        $dates = array_values(array_filter(array_column($items, 'date'), fn($d) => is_string($d) && $d >= $from && $d <= $to));
        sort($dates);
        $amounts = array_column($items, 'amount');
        if (count($items) >= 2 && count($dates) === count($items) && count(array_unique($dates)) === count($dates)
            && max($amounts) - min($amounts) < 0.005 && abs(array_sum($amounts) - $total) < 0.005) {
            $n = self::rhythmOfDates($dates);
            return ['from' => $from, 'amount_eur' => $amounts[0]] + ($n !== null && $n > 1 ? ['every_months' => $n] : []) + ['due_dates' => $dates, 'total' => round($total, 2)];
        }
        $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $months = max(1, (int)round($days / (365.25 / 12)));
        return ['from' => $from, 'amount_eur' => round($total / $months, 4), 'total' => round($total, 2)];
    }

    /** Auswahl des Felds `advance_new_every` je Rhythmus in Monaten. */
    public const EVERY_OPTION = [1 => 'monthly', 2 => 'bimonthly', 3 => 'quarterly'];

    /** Wörter für einen Rhythmus, in den Sprachen der App (kleingeschrieben, Leerraum zusammengefasst). */
    private const EVERY_WORDS = [
        1 => ['monthly', 'every month', 'monatlich', 'jeden monat', 'mtl.', 'mtl', 'mensuel', 'mensuelle', 'tous les mois', 'chaque mois',
              'mensile', 'ogni mese', 'mensual', 'cada mes', 'mensal', 'todos os meses', 'maandelijks', 'elke maand', 'iedere maand'],
        2 => ['bimonthly', 'bi-monthly', 'every other month', 'every second month', 'zweimonatlich', 'zweimonatig', 'zwei-monatlich',
              'bimestriel', 'bimestrielle', 'bimestrale', 'bimestral', 'tweemaandelijks'],
        3 => ['quarterly', 'three-monthly', 'vierteljährlich', 'vierteljaehrlich', 'quartalsweise', 'dreimonatlich', 'trimestriel',
              'trimestrielle', 'trimestrale', 'trimestral', 'per kwartaal', 'elk kwartaal', 'ieder kwartaal', 'driemaandelijks'],
    ];

    /**
     * Rhythmus eines Abschlags aus einer Angabe, tolerant: 1, 2, 3 als Zahl oder
     * Text, die Auswahl des Felds und Wörter wie „zweimonatlich“, „alle 2
     * Monate“, „every two months“, „tous les trois mois“, „ogni 2 mesi“, „cada
     * dos meses“, „de 2 em 2 meses“, „om de 2 maanden“. Unbekanntes: null (nicht
     * raten; „bimensuel“ oder „bimensal“ heißen zweimal im Monat).
     */
    public static function everyOf(mixed $v): ?int
    {
        if (is_int($v) || is_float($v)) return in_array((float)$v, [1.0, 2.0, 3.0], true) ? (int)$v : null;
        if (!is_string($v)) return null;
        $s = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $v)));
        if ($s === '') return null;
        if (preg_match('/^([123])(?:[.,]0+)?$/', $s, $m)) return (int)$m[1];
        foreach (self::EVERY_WORDS as $n => $words) if (in_array($s, $words, true)) return $n;
        $num = ['1' => 1, '2' => 2, '3' => 3, 'zwei' => 2, 'two' => 2, 'deux' => 2, 'due' => 2, 'dos' => 2, 'dois' => 2, 'twee' => 2,
                'drei' => 3, 'three' => 3, 'trois' => 3, 'tre' => 3, 'tres' => 3, 'três' => 3, 'drie' => 3];
        if (preg_match('/^(?:alle|every|tous les|ogni|cada|a cada|om de|elke|iedere) (\S+) (?:monate|months|mois|mesi|meses|maanden)$/u', $s, $m)) return $num[$m[1]] ?? null;
        if (preg_match('/^de (\S+) em (\S+) meses$/u', $s, $m) && $m[1] === $m[2]) return $num[$m[1]] ?? null;
        return null;
    }

    /**
     * Rhythmus genannter Termine in Monaten (1, 2 oder 3), sonst null: jeder Termin
     * höchstens drei Tage neben dem Tag, den der erste Termin plus N Monate ergibt
     * (am Monatsende geklemmt) — so zählen Termine, die ein Wochenende verschiebt
     * („16.12. → 15.02.“), und Monatsletzte. Mindestens zwei Termine.
     *
     * @param list<string> $dates aufsteigend
     */
    public static function rhythmOfDates(array $dates): ?int
    {
        if (count($dates) < 2) return null;
        foreach ([1, 2, 3] as $n) {
            $fits = true;
            foreach ($dates as $i => $d) {
                $want = ContractService::addMonthsClamped($dates[0], $n * $i);
                if (abs((int)round((strtotime($d) - strtotime($want)) / 86400)) > 3) { $fits = false; break; }
            }
            if ($fits) return $n;
        }
        return null;
    }
}
