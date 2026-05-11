<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Contract management. Each contract belongs to exactly one meter (F3).
 *
 * F4 validation: price/base/advance entries must have BOTH date and amount
 * filled, or both empty (in which case they are dropped).
 *
 * Bonuses have credit_date (when the bonus hits the bank), not a "from".
 */
final class ContractService
{
    private const FIELD_GROUPS = [
        'working_prices' => ['from', 'ct_per_kwh'],
        'base_prices'    => ['from', 'eur_per_month'],
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
        $contract = $this->normalize([
            'id'              => 'c_' . bin2hex(random_bytes(6)),
            'meter_id'        => $meterId,
            'provider'        => (string)($input['provider'] ?? ''),
            'tariff_name'     => (string)($input['tariff_name'] ?? ''),
            'start'           => $input['start'] ?? null,
            'end'             => $input['end'] ?? null,
            'notes'           => (string)($input['notes'] ?? ''),
            'working_prices'  => $input['working_prices']   ?? [],
            'base_prices'     => $input['base_prices']      ?? [],
            'advance_payments'=> $input['advance_payments'] ?? [],
            'bonuses'         => $input['bonuses']          ?? [],
        ]);

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
        foreach ($all as &$c) {
            if (($c['id'] ?? null) !== $id) continue;
            foreach (['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
                      'working_prices', 'base_prices', 'advance_payments', 'bonuses'] as $f) {
                if (array_key_exists($f, $input)) $c[$f] = $input[$f];
            }
            $c = $this->normalize($c);
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
     * F4: Validate & normalize a contract.
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
        if (empty($c['start'])) {
            throw new \InvalidArgumentException('Vertragsbeginn (start) ist erforderlich');
        }
        if (!empty($c['end']) && $c['end'] < $c['start']) {
            throw new \InvalidArgumentException('Vertragsende liegt vor Vertragsbeginn');
        }

        foreach (self::FIELD_GROUPS as $group => [$dateKey, $amountKey]) {
            $entries = $c[$group] ?? [];
            if (!is_array($entries)) $entries = [];
            $cleaned = [];
            foreach ($entries as $i => $e) {
                $date   = isset($e[$dateKey])   ? trim((string)$e[$dateKey]) : '';
                $amount = isset($e[$amountKey]) ? $e[$amountKey] : null;
                $amountFilled = $amount !== null && $amount !== '' && $amount !== false;
                $dateFilled   = $date !== '';
                if (!$dateFilled && !$amountFilled) continue; // silent drop
                if ($dateFilled !== $amountFilled) {
                    $groupLabel = match($group) {
                        'working_prices'   => 'Arbeitspreis',
                        'base_prices'      => 'Grundpreis',
                        'advance_payments' => 'Abschlag',
                    };
                    $missing = $dateFilled ? 'Betrag' : 'Datum';
                    throw new \InvalidArgumentException(
                        "$groupLabel-Eintrag #" . ($i + 1) . ": $missing fehlt"
                    );
                }
                $cleaned[] = [
                    $dateKey   => $date,
                    $amountKey => (float)$amount,
                ];
            }
            // Sort by date ascending
            usort($cleaned, fn($a, $b) => strcmp($a[$dateKey], $b[$dateKey]));
            $c[$group] = $cleaned;
        }

        // Bonuses: credit_date + amount_eur + type + label
        $bonuses = $c['bonuses'] ?? [];
        if (!is_array($bonuses)) $bonuses = [];
        $cleanedBonuses = [];
        foreach ($bonuses as $i => $b) {
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
            $cleanedBonuses[] = [
                'credit_date' => $cd,
                'amount_eur'  => (float)$am,
                'type'        => (string)($b['type'] ?? 'sofort'),
                'label'       => (string)($b['label'] ?? ''),
            ];
        }
        $c['bonuses'] = $cleanedBonuses;

        return $c;
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
