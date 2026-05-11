<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Contract management. Each contract belongs to exactly one meter (F3).
 *
 * Two contract shapes depending on the utility:
 *
 *  - **Gas / Strom**: a flat shape with `working_prices` (ct/kWh) +
 *    `base_prices` (€/month) + `advance_payments` (€/month) + `bonuses`.
 *
 *  - **Wasser** (v1.0.3): three priced components per contract — fresh-water
 *    (`trinkwasser`), waste-water (`schmutzwasser`), and storm-water
 *    (`niederschlagswasser`). Each component has its own price history.
 *    Plus the common `advance_payments` and `bonuses`.
 *
 *    The component shapes:
 *      trinkwasser: { working_prices[{from,ct_per_m3}], base_prices[{from,eur_per_month}] }
 *      schmutzwasser: {
 *          basis: 'trinkwasser' | 'separater_zaehler',
 *          separater_zaehler_meter_id: ?string,
 *          working_prices[{from,ct_per_m3}]
 *      }
 *      niederschlagswasser: {
 *          rates[{from, eur_per_m2_year, versiegelte_flaeche_m2}]
 *      }
 *
 * F4 validation: price/base/advance entries must have ALL their keys filled,
 * or all empty (in which case they are dropped silently).
 *
 * Bonuses have credit_date (when the bonus hits the bank), not a "from".
 */
final class ContractService
{
    /** Standard contract field groups (Gas / Strom). */
    private const FIELD_GROUPS_STANDARD = [
        'working_prices'   => ['from', 'ct_per_kwh'],
        'base_prices'      => ['from', 'eur_per_month'],
        'advance_payments' => ['from', 'amount_eur'],
    ];

    /** Water component field groups (v1.0.3). */
    private const FIELD_GROUPS_WATER_COMMON = [
        'advance_payments' => ['from', 'amount_eur'],
    ];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
    ) {}

    public function list(string $utility, ?string $meterId = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        if ($meterId !== null) {
            $all = array_values(array_filter($all, fn($c) => ($c['meter_id'] ?? null) === $meterId));
        }
        usort($all, fn($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));
        return $all;
    }

    public function get(string $utility, string $id): ?array
    {
        foreach ($this->list($utility) as $c) {
            if (($c['id'] ?? null) === $id) return $c;
        }
        return null;
    }

    public function create(string $utility, array $input): array
    {
        $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
        if (!$this->meters->get($utility, $meterId)) {
            throw new \InvalidArgumentException('Zähler nicht gefunden: ' . $meterId);
        }
        $base = [
            'id'           => 'c_' . bin2hex(random_bytes(6)),
            'meter_id'     => $meterId,
            'provider'     => (string)($input['provider'] ?? ''),
            'tariff_name'  => (string)($input['tariff_name'] ?? ''),
            'start'        => $input['start'] ?? null,
            'end'          => $input['end'] ?? null,
            'notes'        => (string)($input['notes'] ?? ''),
            'bonuses'      => $input['bonuses'] ?? [],
        ];

        if ($utility === 'wasser') {
            $base['trinkwasser']         = $input['trinkwasser']         ?? [];
            $base['schmutzwasser']       = $input['schmutzwasser']       ?? [];
            $base['niederschlagswasser'] = $input['niederschlagswasser'] ?? [];
            $base['advance_payments']    = $input['advance_payments']    ?? [];
            $contract = $this->normalizeWater($base);
        } else {
            $base['working_prices']   = $input['working_prices']   ?? [];
            $base['base_prices']      = $input['base_prices']      ?? [];
            $base['advance_payments'] = $input['advance_payments'] ?? [];
            $contract = $this->normalize($base);
        }

        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $all[] = $contract;
        $this->store->write("$utility/contracts.json", $all);
        return $contract;
    }

    public function update(string $utility, string $id, array $input): array
    {
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $found = null;

        $standardFields = ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
                           'working_prices', 'base_prices', 'advance_payments', 'bonuses'];
        $waterFields    = ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
                           'trinkwasser', 'schmutzwasser', 'niederschlagswasser',
                           'advance_payments', 'bonuses'];

        foreach ($all as &$c) {
            if (($c['id'] ?? null) !== $id) continue;
            $fields = $utility === 'wasser' ? $waterFields : $standardFields;
            foreach ($fields as $f) {
                if (array_key_exists($f, $input)) $c[$f] = $input[$f];
            }
            $c = $utility === 'wasser' ? $this->normalizeWater($c) : $this->normalize($c);
            $found = $c;
            break;
        }
        unset($c);
        if (!$found) throw new \InvalidArgumentException('Vertrag nicht gefunden');
        $this->store->write("$utility/contracts.json", $all);
        return $found;
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($c) => ($c['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new \InvalidArgumentException('Vertrag nicht gefunden');
        $this->store->write("$utility/contracts.json", $kept);
    }

    /**
     * F4: Validate & normalize a Gas / Strom contract.
     *
     * For each entry in working_prices / base_prices / advance_payments:
     *   - both date and amount empty → drop (silent)
     *   - one filled, the other not  → throw (user must fix)
     *   - both filled                → keep, types coerced
     *
     * Empty entries are dropped silently so a "blank row" template doesn't
     * cause validation noise. Half-filled entries are an error so the user
     * doesn't accidentally lose the data they typed in.
     */
    private function normalize(array $c): array
    {
        $this->validateMeta($c);
        foreach (self::FIELD_GROUPS_STANDARD as $group => [$dateKey, $amountKey]) {
            $c[$group] = $this->normalizePriceList($c[$group] ?? [], $dateKey, $amountKey, $this->groupLabel($group));
        }
        $c['bonuses'] = $this->normalizeBonuses($c['bonuses'] ?? []);
        return $c;
    }

    /**
     * F4 for water: three component blocks (trinkwasser, schmutzwasser,
     * niederschlagswasser) plus shared advance_payments and bonuses.
     */
    private function normalizeWater(array $c): array
    {
        $this->validateMeta($c);

        // ── Trinkwasser ─────────────────────────────────────────────
        $tw = is_array($c['trinkwasser'] ?? null) ? $c['trinkwasser'] : [];
        $tw['working_prices'] = $this->normalizePriceList(
            $tw['working_prices'] ?? [], 'from', 'ct_per_m3', 'Trinkwasser-Arbeitspreis'
        );
        $tw['base_prices']    = $this->normalizePriceList(
            $tw['base_prices']    ?? [], 'from', 'eur_per_month', 'Trinkwasser-Grundpreis'
        );
        $c['trinkwasser'] = $tw;

        // ── Schmutzwasser ───────────────────────────────────────────
        $sw = is_array($c['schmutzwasser'] ?? null) ? $c['schmutzwasser'] : [];
        $basis = (string)($sw['basis'] ?? 'trinkwasser');
        if (!in_array($basis, ['trinkwasser', 'separater_zaehler'], true)) {
            throw new \InvalidArgumentException(
                'Schmutzwasser-Basis muss "trinkwasser" oder "separater_zaehler" sein, war: ' . $basis
            );
        }
        $sw['basis'] = $basis;
        $sw['separater_zaehler_meter_id'] = $basis === 'separater_zaehler'
            ? (string)($sw['separater_zaehler_meter_id'] ?? '')
            : null;
        if ($basis === 'separater_zaehler' && $sw['separater_zaehler_meter_id'] === '') {
            throw new \InvalidArgumentException(
                'Bei Schmutzwasser-Basis "separater_zaehler" muss separater_zaehler_meter_id gesetzt sein'
            );
        }
        $sw['working_prices'] = $this->normalizePriceList(
            $sw['working_prices'] ?? [], 'from', 'ct_per_m3', 'Schmutzwasser-Arbeitspreis'
        );
        $c['schmutzwasser'] = $sw;

        // ── Niederschlagswasser ────────────────────────────────────
        $nw = is_array($c['niederschlagswasser'] ?? null) ? $c['niederschlagswasser'] : [];
        $rawRates = is_array($nw['rates'] ?? null) ? $nw['rates'] : [];
        $cleanedRates = [];
        foreach ($rawRates as $i => $r) {
            $from = isset($r['from']) ? trim((string)$r['from']) : '';
            $rate = $r['eur_per_m2_year']         ?? null;
            $area = $r['versiegelte_flaeche_m2']  ?? null;
            $filled = fn($v) => $v !== null && $v !== '' && $v !== false;
            $any = $from !== '' || $filled($rate) || $filled($area);
            $all = $from !== '' && $filled($rate) && $filled($area);
            if (!$any) continue;
            if (!$all) {
                $missing = [];
                if ($from === '')    $missing[] = 'Datum';
                if (!$filled($rate)) $missing[] = '€/m²/Jahr';
                if (!$filled($area)) $missing[] = 'versiegelte Fläche';
                throw new \InvalidArgumentException(
                    'Niederschlagswasser-Eintrag #' . ($i + 1) . ': ' . implode(', ', $missing) . ' fehlt'
                );
            }
            $cleanedRates[] = [
                'from'                   => $from,
                'eur_per_m2_year'        => (float)$rate,
                'versiegelte_flaeche_m2' => (float)$area,
            ];
        }
        usort($cleanedRates, fn($a, $b) => strcmp($a['from'], $b['from']));
        $c['niederschlagswasser'] = ['rates' => $cleanedRates];

        // ── Advance payments + bonuses (gleiche Logik wie Standard) ─
        $c['advance_payments'] = $this->normalizePriceList(
            $c['advance_payments'] ?? [], 'from', 'amount_eur', 'Abschlag'
        );
        $c['bonuses'] = $this->normalizeBonuses($c['bonuses'] ?? []);
        return $c;
    }

    private function validateMeta(array $c): void
    {
        if (empty($c['start'])) {
            throw new \InvalidArgumentException('Vertragsbeginn (start) ist erforderlich');
        }
        if (!empty($c['end']) && $c['end'] < $c['start']) {
            throw new \InvalidArgumentException('Vertragsende liegt vor Vertragsbeginn');
        }
    }

    private function normalizePriceList(array $entries, string $dateKey, string $amountKey, string $label): array
    {
        $cleaned = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) continue;
            $date   = isset($e[$dateKey])   ? trim((string)$e[$dateKey]) : '';
            $amount = $e[$amountKey] ?? null;
            $amountFilled = $amount !== null && $amount !== '' && $amount !== false;
            $dateFilled   = $date !== '';
            if (!$dateFilled && !$amountFilled) continue; // silent drop
            if ($dateFilled !== $amountFilled) {
                $missing = $dateFilled ? 'Betrag' : 'Datum';
                throw new \InvalidArgumentException(
                    "$label-Eintrag #" . ($i + 1) . ": $missing fehlt"
                );
            }
            $cleaned[] = [
                $dateKey   => $date,
                $amountKey => (float)$amount,
            ];
        }
        usort($cleaned, fn($a, $b) => strcmp($a[$dateKey], $b[$dateKey]));
        return $cleaned;
    }

    private function normalizeBonuses(array $bonuses): array
    {
        $cleaned = [];
        foreach ($bonuses as $i => $b) {
            if (!is_array($b)) continue;
            $cd = isset($b['credit_date']) ? trim((string)$b['credit_date']) : '';
            $am = $b['amount_eur'] ?? null;
            $amFilled = $am !== null && $am !== '' && $am !== false;
            $cdFilled = $cd !== '';
            if (!$cdFilled && !$amFilled) continue;
            if ($cdFilled !== $amFilled) {
                $missing = $cdFilled ? 'Betrag' : 'Gutschriftsdatum';
                throw new \InvalidArgumentException(
                    "Bonus-Eintrag #" . ($i + 1) . ": $missing fehlt"
                );
            }
            $cleaned[] = [
                'credit_date' => $cd,
                'amount_eur'  => (float)$am,
                'type'        => (string)($b['type']  ?? 'sofort'),
                'label'       => (string)($b['label'] ?? ''),
            ];
        }
        return $cleaned;
    }

    private function groupLabel(string $group): string
    {
        return match($group) {
            'working_prices'   => 'Arbeitspreis',
            'base_prices'      => 'Grundpreis',
            'advance_payments' => 'Abschlag',
            default            => $group,
        };
    }

    // ── Lookup helpers used by ConsumptionService ────────────────────────

    public function findActiveForDate(array $contracts, string $date): ?array
    {
        foreach ($contracts as $c) {
            if ($date < ($c['start'] ?? '9999')) continue;
            if (!empty($c['end']) && $date > $c['end']) continue;
            return $c;
        }
        return null;
    }

    public function valueValidOn(array $entries, string $field, int $year, int $month): ?float
    {
        if (empty($entries)) return null;
        $first = sprintf('%04d-%02d-01', $year, $month);
        usort($entries, fn($a, $b) => strcmp($a['from'] ?? '', $b['from'] ?? ''));
        $val = null;
        foreach ($entries as $e) {
            if (($e['from'] ?? '9999') <= $first) {
                if (isset($e[$field]) && is_numeric($e[$field])) $val = (float)$e[$field];
            } else break;
        }
        return $val;
    }

    public function bonusForMonth(array $contract, int $year, int $month): float
    {
        $ym = sprintf('%04d-%02d', $year, $month);
        $sum = 0.0;
        foreach ($contract['bonuses'] ?? [] as $b) {
            if (!isset($b['credit_date'])) continue;
            if (substr($b['credit_date'], 0, 7) === $ym) {
                $sum += (float)($b['amount_eur'] ?? 0);
            }
        }
        return $sum;
    }
}
