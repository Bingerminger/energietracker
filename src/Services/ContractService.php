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
        private I18nService $i18n,
    ) {}

    public function list(string $utility, ?string $meterId = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
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
        // F1005 — pv_erzeugung ist rein statistisch (Wechselrichter-Stand);
        // ein Vertrag dort wäre semantisch unsinnig. Hart ablehnen, bevor
        // ein Datensatz angelegt wird.
        if (!Utilities::hasContracts($utility)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.contract.noContracts', ['label' => Utilities::get($utility)['label']])
            );
        }
        $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
        if (!$this->meters->get($utility, $meterId)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
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
            // v1.3.0 — Schattenvertrag: rein hypothetisch, fließt NICHT in
            // Saldo/Forecast/contractStatus ein, nur in den Tarifvergleich.
            'is_shadow'    => (bool)($input['is_shadow'] ?? false),
            'shadow_label' => isset($input['shadow_label']) ? (string)$input['shadow_label'] : null,
        ];

        if ($utility === 'wasser') {
            $base['trinkwasser']         = $input['trinkwasser']         ?? [];
            $base['schmutzwasser']       = $input['schmutzwasser']       ?? [];
            $base['niederschlagswasser'] = $input['niederschlagswasser'] ?? [];
            $base['advance_payments']    = $input['advance_payments']    ?? [];
            $contract = $this->normalizeWater($base);
        } else {
            $base['working_prices']   = $input['working_prices']   ?? [];
            // F1005 — PV-Einspeisung: vereinfachtes Schema (nur ct/kWh-
            // Einspeisevergütung). Kein Grundpreis, kein Abschlagsplan
            // (Verteilnetzbetreiber zahlt nach Erzeugung, nicht nach Plan),
            // keine Sonderzahlungen. Eingaben in diesen Feldern werden
            // ignoriert, damit das Frontend keine versteckten Felder
            // hinterhertragen muss.
            $isFeedIn = Utilities::isFeedIn($utility);
            $base['base_prices']      = $isFeedIn ? [] : ($input['base_prices']      ?? []);
            $base['advance_payments'] = $isFeedIn ? [] : ($input['advance_payments'] ?? []);
            // F1003 — Sonderzahlungen (nur Standard-Vertrags-Utilities mit
            // Abschlags-Saldierung: Gas/Strom/Fernwärme). Bei Heizöl/Pellets
            // und PV-Einspeisung existiert keine Abschlagslogik.
            $base['special_payments'] = Utilities::hasAdvancePaymentContracts($utility)
                ? ($input['special_payments'] ?? [])
                : [];
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

        // F1005 — PV-Einspeisung: nur working_prices updaten; die anderen
        // Preis-Felder gibt es semantisch nicht (kein Grundpreis, kein
        // Abschlagsplan, keine Sonderzahlungen). Anbieter/Tarif-Stammdaten
        // ja, Felder die das Frontend ohnehin nicht anzeigt nein.
        $isFeedIn = Utilities::isFeedIn($utility);
        $standardFields = $isFeedIn
            ? ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
               'working_prices', 'bonuses',
               'is_shadow', 'shadow_label']
            : ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
               'working_prices', 'base_prices', 'advance_payments', 'bonuses',
               'special_payments',
               'is_shadow', 'shadow_label'];
        $waterFields    = ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
                           'trinkwasser', 'schmutzwasser', 'niederschlagswasser',
                           'advance_payments', 'bonuses',
                           'is_shadow', 'shadow_label'];

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
        if (!$found) throw new \InvalidArgumentException($this->i18n->t('errors.contract.notFound'));
        $this->store->write("$utility/contracts.json", $all);
        return $found;
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($c) => ($c['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new \InvalidArgumentException($this->i18n->t('errors.contract.notFound'));
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
        // F1003 — Sonderzahlungen
        $c['special_payments'] = $this->normalizeSpecialPayments($c['special_payments'] ?? []);
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
                $this->i18n->t('errors.contract.swBasisInvalid', ['basis' => $basis])
            );
        }
        $sw['basis'] = $basis;
        $sw['separater_zaehler_meter_id'] = $basis === 'separater_zaehler'
            ? (string)($sw['separater_zaehler_meter_id'] ?? '')
            : null;
        if ($basis === 'separater_zaehler' && $sw['separater_zaehler_meter_id'] === '') {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.contract.swSeparateMeterRequired')
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
                if ($from === '')    $missing[] = $this->i18n->t('errors.contract.fields.date');
                if (!$filled($rate)) $missing[] = $this->i18n->t('errors.contract.fields.ratePerM2');
                if (!$filled($area)) $missing[] = $this->i18n->t('errors.contract.fields.sealedArea');
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.swEntryMissing', ['n' => $i + 1, 'fields' => implode(', ', $missing)])
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
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.startRequired'));
        }
        if (!empty($c['end']) && $c['end'] < $c['start']) {
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.endBeforeStart'));
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
                $missing = $this->i18n->t($dateFilled ? 'errors.contract.fields.amount' : 'errors.contract.fields.date');
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.entryMissing', ['label' => $label, 'n' => $i + 1, 'field' => $missing])
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
                $missing = $this->i18n->t($cdFilled ? 'errors.contract.fields.amount' : 'errors.contract.fields.creditDate');
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.bonusEntryMissing', ['n' => $i + 1, 'field' => $missing])
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

    // ── F1003 — Sonderzahlungen ──────────────────────────────────────────

    /**
     * Die fünf zulässigen Arten einer Sonderzahlung. Sie bilden exakt die
     * fachliche Spezifikation ab:
     *
     *   rueckzahlung_mit   — Rückzahlung (Provider → Kunde), mit Auswirkung
     *                        auf die künftigen Abschläge
     *   rueckzahlung_ohne  — Rückzahlung, ohne Auswirkung auf Abschläge
     *   nachzahlung_mit    — Nachzahlung (Kunde → Provider), mit Auswirkung
     *   nachzahlung_ohne   — Nachzahlung, ohne Auswirkung
     *   abschlagszahlung   — zusätzliche/einmalige Abschlagszahlung des
     *                        Kunden (keine mit/ohne-Variante)
     *
     * Vorzeichen-Wirkung auf den Saldo (current_balance = Kosten −
     * gezahlte Abschläge; positiv = Nachzahlung/Unterzahlung):
     *   - Rückzahlung erhalten  → Saldo  += Betrag  (Überzahlung wird
     *                              ausgeglichen / verringert)
     *   - Nachzahlung geleistet → Saldo  −= Betrag  (Schuld verringert)
     *   - Abschlagszahlung      → Saldo  −= Betrag  (wie zusätzlicher
     *                              Abschlag)
     *
     * "mit Auswirkung auf Abschlagszahlungen" bedeutet, dass die Zeile
     * zusätzlich einen neuen monatlichen Abschlag (`new_advance_eur`) ab
     * einem Stichtag (`advance_from`) setzt. Diese synthetischen Punkte
     * werden in {@see effectiveAdvanceSchedule()} in den Abschlagsplan
     * gemischt — die monatliche Abschlagsberechnung in ConsumptionService
     * bleibt dadurch unverändert und greift automatisch.
     */
    public const SPECIAL_PAYMENT_KINDS = [
        'rueckzahlung_mit',
        'rueckzahlung_ohne',
        'nachzahlung_mit',
        'nachzahlung_ohne',
        'abschlagszahlung',
    ];

    /** Arten, die den künftigen Abschlag verändern dürfen. */
    private const SPECIAL_KINDS_AFFECTING_ADVANCE = [
        'rueckzahlung_mit',
        'nachzahlung_mit',
    ];

    /**
     * F4-analoge Validierung/Normalisierung der Sonderzahlungen.
     *
     *   - Zeile komplett leer (kein Datum, kein Betrag) → still verworfen
     *   - Datum oder Betrag halb gefüllt                → Fehler
     *   - unbekannte `kind`                             → Fehler
     *   - `*_mit` mit nur einem von new_advance_eur /
     *     advance_from gefüllt                          → Fehler
     *   - Beträge werden als positiv erzwungen (das Vorzeichen ergibt
     *     sich aus `kind`, nicht aus der Eingabe)
     */
    private function normalizeSpecialPayments(array $entries): array
    {
        $cleaned = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) continue;
            $date   = isset($e['date']) ? trim((string)$e['date']) : '';
            $amount = $e['amount_eur'] ?? null;
            $amountFilled = $amount !== null && $amount !== '' && $amount !== false;
            $dateFilled   = $date !== '';
            if (!$dateFilled && !$amountFilled) continue; // silent drop
            $n = $i + 1;
            if ($dateFilled !== $amountFilled) {
                $missing = $this->i18n->t($dateFilled ? 'errors.contract.fields.amount' : 'errors.contract.fields.date');
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.specialPaymentMissing', ['n' => $n, 'field' => $missing])
                );
            }
            $kind = (string)($e['kind'] ?? '');
            if (!in_array($kind, self::SPECIAL_PAYMENT_KINDS, true)) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.specialPaymentUnknownKind', ['n' => $n, 'kind' => $kind])
                );
            }
            $amt = abs((float)$amount); // Vorzeichen kommt aus kind

            $row = [
                'id'         => isset($e['id']) && $e['id'] !== ''
                                ? (string)$e['id']
                                : 'sp_' . bin2hex(random_bytes(5)),
                'date'       => $date,
                'kind'       => $kind,
                'amount_eur' => $amt,
                'note'       => (string)($e['note'] ?? ''),
            ];

            if (in_array($kind, self::SPECIAL_KINDS_AFFECTING_ADVANCE, true)) {
                $na  = $e['new_advance_eur'] ?? null;
                $af  = isset($e['advance_from']) ? trim((string)$e['advance_from']) : '';
                $naFilled = $na !== null && $na !== '' && $na !== false;
                $afFilled = $af !== '';
                if ($naFilled !== $afFilled) {
                    $missing = $this->i18n->t($naFilled ? 'errors.contract.fields.advanceDate' : 'errors.contract.fields.newAdvanceAmount');
                    throw new \InvalidArgumentException(
                        $this->i18n->t('errors.contract.specialPaymentImpactMissing', ['n' => $n, 'field' => $missing])
                    );
                }
                $row['new_advance_eur'] = $naFilled ? (float)$na : null;
                $row['advance_from']    = $afFilled ? $af : null;
            } else {
                // ohne-Auswirkung-Arten und abschlagszahlung tragen keine
                // Abschlagsänderung
                $row['new_advance_eur'] = null;
                $row['advance_from']    = null;
            }
            $cleaned[] = $row;
        }
        usort($cleaned, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $cleaned;
    }

    /**
     * F1003 — der effektive Abschlagsplan eines Standard-Vertrags:
     * `advance_payments` plus die synthetischen Punkte aus allen
     * `*_mit`-Sonderzahlungen (new_advance_eur ab advance_from).
     *
     * Wird von ConsumptionService anstelle von `$c['advance_payments']`
     * verwendet, damit "mit Auswirkung" automatisch in jede monatliche
     * Abschlagsbildung einfließt — ohne Sonderlogik in der Saldo-
     * Aggregation.
     *
     * @return array<int,array{from:string,amount_eur:float}>
     */
    public function effectiveAdvanceSchedule(array $contract): array
    {
        $schedule = [];
        foreach ($contract['advance_payments'] ?? [] as $e) {
            if (isset($e['from'], $e['amount_eur'])) {
                $schedule[] = [
                    'from'       => (string)$e['from'],
                    'amount_eur' => (float)$e['amount_eur'],
                ];
            }
        }
        foreach ($contract['special_payments'] ?? [] as $sp) {
            if (!in_array($sp['kind'] ?? '', self::SPECIAL_KINDS_AFFECTING_ADVANCE, true)) {
                continue;
            }
            $from = $sp['advance_from']    ?? null;
            $amt  = $sp['new_advance_eur'] ?? null;
            if ($from === null || $from === '' || $amt === null) continue;
            $schedule[] = [
                'from'       => (string)$from,
                'amount_eur' => (float)$amt,
            ];
        }
        usort($schedule, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $schedule;
    }

    /**
     * F1003 — aggregierte Wirkung der Sonderzahlungen eines Vertrags.
     *
     * @return array{
     *   refund_total:float, surcharge_total:float, advance_total:float,
     *   net:float, count:int
     * }
     *   refund_total    = Σ Rückzahlungen (Kunde erhält)
     *   surcharge_total = Σ Nachzahlungen (Kunde zahlt zur Abrechnung)
     *   advance_total   = Σ zusätzliche Abschlagszahlungen (Kunde zahlt)
     *   net             = refund_total − surcharge_total − advance_total
     *                     (Term, der in current_balance addiert wird)
     */
    public function specialPaymentSummary(array $contract): array
    {
        $refund = 0.0; $surcharge = 0.0; $advance = 0.0; $count = 0;
        foreach ($contract['special_payments'] ?? [] as $sp) {
            $amt  = abs((float)($sp['amount_eur'] ?? 0));
            $kind = (string)($sp['kind'] ?? '');
            if ($amt <= 0 && $kind === '') continue;
            $count++;
            switch ($kind) {
                case 'rueckzahlung_mit':
                case 'rueckzahlung_ohne':
                    $refund += $amt; break;
                case 'nachzahlung_mit':
                case 'nachzahlung_ohne':
                    $surcharge += $amt; break;
                case 'abschlagszahlung':
                    $advance += $amt; break;
            }
        }
        return [
            'refund_total'    => round($refund, 2),
            'surcharge_total' => round($surcharge, 2),
            'advance_total'   => round($advance, 2),
            'net'             => round($refund - $surcharge - $advance, 2),
            'count'           => $count,
        ];
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
