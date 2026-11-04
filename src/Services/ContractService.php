<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Config\Rights;   // v3.11.0 (F1035, Paket vertrag) — priceIncrease()
use Energietracker\Support\Dates;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\LocalizedException;

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
 *    Plus the common `advance_payments` and `bonuses`, and since v3.5.0
 *    (GitHub #34) `special_payments` like Gas / Strom.
 *
 *    The component shapes:
 *      trinkwasser: { working_prices[{from,ct_per_m3}], base_prices[{from,eur_per_month}] }
 *      schmutzwasser: {
 *          basis: 'trinkwasser' | 'separater_zaehler' | 'trinkwasser_minus_abzug',
 *          separater_zaehler_meter_id: ?string,
 *          abzug_meter_ids: string[],   // v3.1.0 — Abzugszähler (Garten), nur bei trinkwasser_minus_abzug
 *          working_prices[{from,ct_per_m3}],
 *          base_prices[{from,eur_per_month}]   // v3.9.0 (F1034) — Grundpreis Schmutzwasser, optional
 *      }
 *      niederschlagswasser: {
 *          rates[{from, eur_per_m2_year, versiegelte_flaeche_m2}]
 *      }
 *
 * F4 validation: price/base/advance entries must have ALL their keys filled,
 * or all empty (in which case they are dropped silently).
 *
 * Bonuses have credit_date (when the bonus hits the bank), not a "from".
 *
 * v3.9.0 (F1034) — Abschlagsplan mit Rhythmus, für jede Art mit Abschlägen:
 * eine Zeile von `advance_payments` trägt optional `every_months` (2 oder 3:
 * alle zwei bzw. drei Monate ab `from`; fehlt = monatlich wie bisher) oder
 * `due_dates` (die Termine, die die Rechnung nennt; danach jährlich zu
 * denselben Tagen). `amount_eur` ist dann der Betrag je Termin, nicht je
 * Monat — er wird nie still in einen Monatsbetrag umgerechnet
 * ({@see installments()}, {@see advanceParts()}).
 */
final class ContractService
{
    /** Standard contract field groups (Gas / Strom). */
    private const FIELD_GROUPS_STANDARD = [
        'working_prices'   => ['from', 'ct_per_kwh'],
        'base_prices'      => ['from', 'eur_per_month'],
        'advance_payments' => ['from', 'amount_eur'],
    ];

    /**
     * v2.9.0 (Review CALC-11) — wie gekündigt wird: zum Laufzeitende (der
     * befristete Normalfall), jederzeit zum Monatsende (bisher die Annahme für
     * unbefristete Verträge) oder jederzeit zu jedem Tag (Grundversorgung,
     * verlängerte Verträge).
     */
    public const NOTICE_MODES = ['term_end', 'month_end', 'any_day'];

    /**
     * v3.1.0 (Paket H5, CALC-31) — Felder eines Fernwärmevertrags: Anschluss-
     * leistung in kW, Leistungspreis €/kW·a, Mess-/Verrechnungspreis €/a (beide
     * datiert), Emissionsfaktor des Netzes in g CO₂/kWh laut Versorger und der
     * Primärenergiefaktor (nur zur Information).
     */
    public const DISTRICT_HEATING_FIELDS = ['capacity_kw', 'capacity_prices', 'metering_prices', 'co2_g_per_kwh', 'primary_energy_factor'];

    /** Water component field groups (v1.0.3). */
    private const FIELD_GROUPS_WATER_COMMON = [
        'advance_payments' => ['from', 'amount_eur'],
    ];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /** v3.4.0 (F1024) — Verlauf: ein lückenloser Folgevertrag erledigt die Hinweise des Vorgängers. */
    private ?ActivityService $activity = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /** v3.7.0 — Zuständigkeit (`assignee_id`) gegen das Personenverzeichnis prüfen. */
    private ?ResponsibilityService $responsibility = null;

    public function attachResponsibility(ResponsibilityService $responsibility): void
    {
        $this->responsibility = $responsibility;
    }

    /**
     * v3.7.0 — zuständige Person aus der Eingabe (fehlt = unverändert, null
     * oder leer = niemand): Kündigungsfrist, Vertragsende und „Abrechnung
     * erwartet“ landen bei ihr. Ohne Personenverzeichnis genügt die Form der ID.
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withAssignee(array $c, array $input, ?string $current): array
    {
        if (!array_key_exists('assignee_id', $input)) return $c;
        $id = ($this->responsibility ?? new ResponsibilityService($this->store))->checkInput($input['assignee_id'], $current);
        return ResponsibilityService::apply($c, $id);
    }

    /**
     * v3.4.0 (F1024) — Nach dem Speichern oder Löschen eines Vertrags: Für
     * jeden Vorgänger desselben Ziels, dem er lückenlos folgt (und der noch
     * läuft), ein automatischer Eintrag „erledigt: Folgevertrag"; Einträge,
     * die nicht mehr stimmen, werden durchgestrichen. Die Agenda liest den
     * Folgevertrag ohnehin aus den Daten (contractStatus); hier geht es nur um
     * „wann und von wem".
     */
    private function noteSuccession(string $utility, string $contractId, ?array $saved): void
    {
        if ($this->activity === null) return;
        $all = $this->list($utility);
        $real = array_values(array_filter($all, fn($c) => empty($c['is_shadow'])));
        $today = date('Y-m-d');
        $peers = function (array $c) use ($real): array {
            $gid = $c['meter_group_id'] ?? null;
            return array_values(array_filter($real, fn($o) => !empty($gid)
                ? ($o['meter_group_id'] ?? null) === $gid
                : empty($o['meter_group_id']) && ($o['meter_id'] ?? null) === ($c['meter_id'] ?? null)));
        };
        $renewedIn = function (array $c, array $list) use ($today): bool {
            $r = $this->resolveForDate($list, $today);
            return $r !== null && $r['assumed'] && ($r['contract']['id'] ?? null) === ($c['id'] ?? null);
        };
        // noch ein lückenloser Folgevertrag?
        $this->activity->successorRevoked($contractId, function (string $pid) use ($real, $peers, $renewedIn, $contractId): bool {
            foreach ($real as $p) {
                if (($p['id'] ?? null) !== $pid) continue;
                $list = $peers($p);
                $s = self::successorOf($p, $list, $renewedIn($p, $list));
                return $s !== null && $s['contract_id'] === $contractId && $s['gap_days'] === 0;
            }
            return false;
        });
        if ($saved === null || !empty($saved['is_shadow'])) return;
        $list = $peers($saved);
        foreach ($list as $p) {
            if (($p['id'] ?? null) === $contractId) continue;
            $renewed = $renewedIn($p, $list);
            // abgelaufen und nicht weiterlaufend: nichts mehr zu erinnern
            if (!$renewed && !empty($p['end']) && (string)$p['end'] < $today) continue;
            $s = self::successorOf($p, $list, $renewed);
            if ($s === null || $s['contract_id'] !== $contractId || $s['gap_days'] > 0) continue;
            $this->activity->successorRecorded($utility, $p, $s, $renewed);
        }
    }

    public function list(string $utility, ?string $meterId = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        if ($meterId !== null) {
            // v3.1.0 (H6, #17) — eine Gruppen-ID liefert die Gruppenverträge
            $all = array_values(array_filter($all, fn($c) => ($c['meter_id'] ?? null) === $meterId
                || ($c['meter_group_id'] ?? null) === $meterId));
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
        // v3.1.0 (H6, #17) — Vertrag für eine Zählergruppe statt für einen Zähler
        $groupId = self::groupTarget($input);
        if ($groupId !== null) {
            $this->assertGroupTarget($utility, $groupId);
            $meterId = null;
        } else {
            $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
            if (!$this->meters->get($utility, $meterId)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
            }
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
            // v2.3.0 — Wechselplanung. Diese drei entscheiden, ab wann ein
            // neuer Tarif überhaupt greifen kann; ohne sie lässt sich ein
            // Wechsel nicht terminieren, nur hypothetisch durchrechnen.
            // Alle optional — wer sie nicht pflegt, verliert genau den
            // errechneten Termin und die Fristwarnung, sonst nichts.
            'notice_period_months'  => $input['notice_period_months']  ?? null,
            'min_term_end'          => $input['min_term_end']          ?? null,
            'price_guarantee_until' => $input['price_guarantee_until'] ?? null,
            // v2.9.0 (Review CALC-10, CALC-11) — Verlängerung und Fristen wie
            // im Vertrag: Ohne Kündigung läuft ein Vertrag nach dem Ende weiter
            // (null = ja, der Normalfall), Fristen gibt es auch in Wochen und
            // Tagen, und nicht jeder Vertrag endet nur zum Laufzeitende.
            'auto_renews'           => $input['auto_renews']           ?? null,
            'notice_period_days'    => $input['notice_period_days']    ?? null,
            'notice_mode'           => $input['notice_mode']           ?? null,
            // Neukundenbonus als Betrag, nicht als Gutschriftstermin. Auf dem
            // Vergleichsportal steht „Bonus 130 €" — wann er gutgeschrieben
            // wird, weiß beim Anlegen niemand. `bonuses[]` bleibt für echte,
            // bereits terminierte Gutschriften.
            'signup_bonus_eur'      => $input['signup_bonus_eur']      ?? null,
        ];
        // v3.7.0 (Posteingang) — Kennungen für die Zuordnung von Versorgerpost
        // und Belege (das Dokument, aus dem der Vertrag stammt); nur, wenn gesetzt
        foreach (self::DOCUMENT_FIELDS as $f) {
            if (array_key_exists($f, $input)) $base[$f] = $input[$f];
        }
        // v3.11.0 (F1035) — Grundversorgung oder Sondervertrag, nur wenn gesetzt
        if (array_key_exists('supply_kind', $input)) $base['supply_kind'] = $input['supply_kind'];

        if ($utility === 'wasser') {
            $base['trinkwasser']         = $input['trinkwasser']         ?? [];
            $base['schmutzwasser']       = $input['schmutzwasser']       ?? [];
            $base['niederschlagswasser'] = $input['niederschlagswasser'] ?? [];
            $base['advance_payments']    = $input['advance_payments']    ?? [];
            // v3.5.0 (GitHub #34) — Nach- und Rückzahlungen der Wasserabrechnung
            $base['special_payments']    = $input['special_payments']    ?? [];
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
            // F1003 — Sonderzahlungen (Gas/Strom/Fernwärme; Wasser oben). Bei
            // Heizöl/Pellets und PV-Einspeisung existiert keine Abschlagslogik.
            $base['special_payments'] = Utilities::hasSpecialPayments($utility)
                ? ($input['special_payments'] ?? [])
                : [];
            // v3.1.0 (H5, CALC-31) — Fernwärme: Leistungs- und Messpreis, Netz-CO₂-Faktor
            if ($utility === 'fernwaerme') {
                foreach (self::DISTRICT_HEATING_FIELDS as $f) {
                    if (array_key_exists($f, $input)) $base[$f] = $input[$f];
                }
            }
            // v3.1.0 (H6) — Gruppenvertrag: Arbeitspreise je Mitglied (HT/NT);
            // Strom: § 14a EnWG Modul 1 als jährliche Reduzierung des Netzentgelts
            if ($groupId !== null) {
                $base['meter_group_id'] = $groupId;
                $base['working_prices_by_meter'] = $input['working_prices_by_meter'] ?? [];
            }
            if ($utility === 'strom' && array_key_exists('grid_reduction', $input)) $base['grid_reduction'] = $input['grid_reduction'];
            if ($utility === 'strom' && array_key_exists('grid_tou', $input)) $base['grid_tou'] = $input['grid_tou'];   // v3.3.0 (F1021)
            // v3.1.0 (H7, MKT-16) — Einspeisung: Gutschriften des Direktvermarkters
            if (Utilities::isFeedIn($utility) && array_key_exists('revenue_statements', $input)) $base['revenue_statements'] = $input['revenue_statements'];
            // v3.1.0 (H6, MKT-12) — Preismodell: fest (fehlt), Monatspreise, dynamisch (Schattenvertrag Strom)
            if (array_key_exists('price_model', $input)) $base['price_model'] = $input['price_model'];
            if (array_key_exists('dynamic', $input)) $base['dynamic'] = $input['dynamic'];
            $contract = $this->normalize($base);
            if ($groupId !== null) $this->assertGroupPrices($utility, $contract);
            $this->assertPriceModel($utility, $contract);
        }

        $contract = $this->withAssignee($contract, $input, null);   // v3.7.0
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $this->assertNoGroupOverlap($utility, $contract, $all);
        $all[] = $contract;
        $this->store->write("$utility/contracts.json", $all);
        $this->linkAttachments($utility, (string)$contract['id'], [], (array)($contract['attachment_ids'] ?? []));   // v3.7.0
        $this->noteSuccession($utility, (string)$contract['id'], $contract);   // v3.4.0 (F1024)
        return $contract + self::warnings($contract, $utility);
    }

    /**
     * v3.7.0 (Posteingang) — optionale Felder an jedem Vertrag: Vertrags- und
     * Kundennummer (Zuordnung der Versorgerpost) und Belege (`attachment_ids`,
     * wie an der Versorgerrechnung).
     */
    public const DOCUMENT_FIELDS = ['contract_number', 'customer_number', 'attachment_ids'];

    /** v3.7.0 — Belege verknüpfen wie BillService: entfernte lösen, alle übrigen auf diesen Vertrag zeigen lassen. */
    private function linkAttachments(string $utility, string $id, array $old, array $new): void
    {
        if ($old === $new) return;
        if (array_diff($old, $new) !== []) AttachmentService::unlinkRef($this->store, 'contract', $id);
        foreach ($new as $att) AttachmentService::link($this->store, (string)$att, null, ['type' => 'contract', 'utility' => $utility, 'id' => $id]);
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
        // v2.3.0 — die Wechselfelder gelten für jede Vertragsart, auch für
        // Wasser und Einspeisung: eine Kündigungsfrist hat jeder Vertrag.
        $switchFields = ['notice_period_months', 'min_term_end', 'price_guarantee_until',
                         'signup_bonus_eur', 'auto_renews', 'notice_period_days', 'notice_mode',
                         ...self::DOCUMENT_FIELDS,    // v3.7.0 (Posteingang)
                         'supply_kind'];              // v3.11.0 (F1035)
        $standardFields = array_merge($isFeedIn
            ? ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
               'working_prices', 'bonuses', 'revenue_statements',   // v3.1.0 (H7)
               'is_shadow', 'shadow_label']
            : ['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
               'working_prices', 'base_prices', 'advance_payments', 'bonuses',
               'is_shadow', 'shadow_label'], $switchFields);
        if ($utility === 'fernwaerme') $standardFields = array_merge($standardFields, self::DISTRICT_HEATING_FIELDS);   // v3.1.0
        if ($utility === 'strom') array_push($standardFields, 'grid_reduction', 'grid_tou');   // v3.1.0 (H6, MKT-13); v3.3.0 Modul 3
        array_push($standardFields, 'price_model', 'dynamic');            // v3.1.0 (H6, MKT-12)
        $waterFields    = array_merge(['provider', 'tariff_name', 'start', 'end', 'notes', 'meter_id',
                           'trinkwasser', 'schmutzwasser', 'niederschlagswasser',
                           'advance_payments', 'bonuses',
                           'is_shadow', 'shadow_label'], $switchFields);
        // F1003 — Sonderzahlungen nur, wo es sie gibt (wie in create()). v3.5.0
        // (GitHub #34): auch Wasser; bis v3.4 verwarf update() sie dort still —
        // auch die Buchung einer Wasserrechnung (BillService::book) kam nie an.
        // Heizöl/Pellets nahm update() bisher an, gerechnet wurde damit nie.
        if (Utilities::hasSpecialPayments($utility)) {
            $standardFields[] = 'special_payments';
            $waterFields[]    = 'special_payments';
        }

        $oldAttachments = [];
        foreach ($all as &$c) {
            if (($c['id'] ?? null) !== $id) continue;
            $oldAttachments = (array)($c['attachment_ids'] ?? []);   // v3.7.0
            $c = $this->withAssignee($c, $input, is_string($c['assignee_id'] ?? null) ? $c['assignee_id'] : null);   // v3.7.0
            $fields = $utility === 'wasser' ? $waterFields : $standardFields;
            foreach ($fields as $f) {
                if (array_key_exists($f, $input)) $c[$f] = $input[$f];
            }
            // v3.1.0 (H6, #17) — Ziel wechseln: Gruppe oder Zähler
            if (($gid = self::groupTarget($input)) !== null) {
                $this->assertGroupTarget($utility, $gid);
                $c['meter_group_id'] = $gid;
                $c['meter_id'] = null;
            } elseif (!empty($input['meter_id'])) {
                unset($c['meter_group_id'], $c['working_prices_by_meter']);
            }
            if (!empty($c['meter_group_id']) && array_key_exists('working_prices_by_meter', $input)) {
                $c['working_prices_by_meter'] = $input['working_prices_by_meter'];
            }
            $c = $utility === 'wasser' ? $this->normalizeWater($c) : $this->normalize($c);
            if (!empty($c['meter_group_id'])) $this->assertGroupPrices($utility, $c);
            if ($utility !== 'wasser') $this->assertPriceModel($utility, $c);
            $this->assertNoGroupOverlap($utility, $c, $all);
            $found = $c;
            break;
        }
        unset($c);
        if (!$found) throw new NotFoundException($this->i18n->t('errors.contract.notFound'));
        $this->store->write("$utility/contracts.json", $all);
        if (array_key_exists('attachment_ids', $input)) $this->linkAttachments($utility, $id, $oldAttachments, (array)($found['attachment_ids'] ?? []));   // v3.7.0
        $this->noteSuccession($utility, $id, $found);   // v3.4.0 (F1024)
        return $found + self::warnings($found, $utility);
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/contracts.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($c) => ($c['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.contract.notFound'));
        $this->store->write("$utility/contracts.json", $kept);
        AttachmentService::unlinkRef($this->store, 'contract', $id);   // v3.7.0 — Belege des Vertrags lösen
        $this->noteSuccession($utility, $id, null);   // v3.4.0 (F1024)
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
            // v3.9.0 (F1034) — Abschläge mit Rhythmus (every_months, due_dates)
            $c[$group] = $group === 'advance_payments'
                ? $this->normalizeAdvancePayments($c[$group] ?? [], $this->groupLabel($group))
                : $this->normalizePriceList($c[$group] ?? [], $dateKey, $amountKey, $this->groupLabel($group));
        }
        $c['bonuses'] = $this->normalizeBonuses($c['bonuses'] ?? []);
        // F1003 — Sonderzahlungen
        $c['special_payments'] = $this->normalizeSpecialPayments($c['special_payments'] ?? []);
        // v3.1.0 (H6) — Gruppenvertrag: Arbeitspreise je Mitglied; Strom: § 14a Modul 1
        if (!empty($c['meter_group_id'])) {
            $byMeter = [];
            foreach ((array)($c['working_prices_by_meter'] ?? []) as $mid => $list) {
                $list = $this->normalizePriceList(is_array($list) ? $list : [], 'from', 'ct_per_kwh', $this->groupLabel('working_prices'));
                if ($list !== []) $byMeter[(string)$mid] = $list;
            }
            $c['working_prices_by_meter'] = $byMeter;
        } else {
            unset($c['meter_group_id'], $c['working_prices_by_meter']);
        }
        if (array_key_exists('grid_reduction', $c)) {
            $c['grid_reduction'] = array_map(fn($e) => $e + ['module' => 1],
                $this->normalizePriceList((array)($c['grid_reduction'] ?? []), 'from', 'eur_per_year', $this->i18n->t('contracts.gridReduction.title')));
        }
        // v3.3.0 (F1021) — § 14a Modul 3: zeitvariables Netzentgelt
        if (array_key_exists('grid_tou', $c)) {
            $tou = self::normalizeGridTou($c['grid_tou']);
            if ($tou === null) unset($c['grid_tou']); else $c['grid_tou'] = $tou;
        }
        // v3.1.0 (H7, MKT-16) — Gutschriften des Direktvermarkters (Einspeisung)
        if (array_key_exists('revenue_statements', $c)) $c['revenue_statements'] = $this->normalizeRevenueStatements((array)($c['revenue_statements'] ?? []));
        // v3.1.0 (H6, MKT-12) — Preismodell; „fest" wird nicht gespeichert
        if (array_key_exists('price_model', $c)) {
            $pm = $c['price_model'];
            if ($pm === null || $pm === '' || $pm === 'fixed') unset($c['price_model']);
            elseif (!in_array($pm, ['monthly', 'dynamic'], true)) {
                throw new LocalizedException('errors.contract.priceModelInvalid', ['value' => is_scalar($pm) ? (string)$pm : ''], 'price model');
            }
        }
        if (($c['price_model'] ?? null) === 'dynamic') {
            $d = is_array($c['dynamic'] ?? null) ? $c['dynamic'] : [];
            $num = function (string $k, float $def, float $max) use ($d): float {
                $v = $d[$k] ?? null;
                if ($v === null || $v === '') return $def;
                $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_numeric($v) ? (float)$v : null);
                if ($n === null || $n < 0 || $n > $max) {
                    throw new LocalizedException('errors.contract.valueInvalid', ['field' => $k, 'value' => is_scalar($v) ? (string)$v : ''], 'dynamic');
                }
                return round($n, 4);
            };
            $c['dynamic'] = ['markup_ct_per_kwh' => $num('markup_ct_per_kwh', 0.0, 100), 'base_eur_month' => $num('base_eur_month', 0.0, 1000),
                             'vat_pct' => $num('vat_pct', 19.0, 30), 'weighting' => 'flat'];
        } else {
            unset($c['dynamic']);
        }
        // v3.1.0 (CALC-31) — Fernwärme-Fixkosten und Netzfaktor (nur wenn gesetzt)
        if (array_key_exists('capacity_prices', $c)) {
            $c['capacity_prices'] = $this->normalizePriceList($c['capacity_prices'] ?? [], 'from', 'eur_per_kw_year', $this->i18n->t('contracts.fw.capacityPrice'));
        }
        if (array_key_exists('metering_prices', $c)) {
            $c['metering_prices'] = $this->normalizePriceList($c['metering_prices'] ?? [], 'from', 'eur_per_year', $this->i18n->t('contracts.fw.meteringPrice'));
        }
        foreach (['capacity_kw' => 100000, 'co2_g_per_kwh' => 2000, 'primary_energy_factor' => 5] as $f => $max) {
            if (!array_key_exists($f, $c)) continue;
            $v = $c[$f];
            if ($v === null || $v === '') { $c[$f] = null; continue; }
            $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_numeric($v) ? (float)$v : null);
            if ($n === null || $n < 0 || $n > $max) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.valueInvalid', ['field' => $this->i18n->t(['capacity_kw' => 'contracts.fw.capacityKw', 'co2_g_per_kwh' => 'contracts.fw.co2Factor', 'primary_energy_factor' => 'contracts.fw.primaryEnergy'][$f]), 'value' => is_scalar($v) ? (string)$v : '']));
            }
            $c[$f] = round($n, 4);
        }
        if (!empty($c['capacity_prices']) && empty($c['capacity_kw'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.capacityMissing'));
        }
        return $this->normalizeSwitchFields($c);
    }

    /**
     * F4 for water: three component blocks (trinkwasser, schmutzwasser,
     * niederschlagswasser) plus shared advance_payments, bonuses and (since
     * v3.5.0) special_payments.
     */
    private function normalizeWater(array $c): array
    {
        $this->validateMeta($c);

        // ── Trinkwasser ─────────────────────────────────────────────
        $tw = is_array($c['trinkwasser'] ?? null) ? $c['trinkwasser'] : [];
        $tw['working_prices'] = $this->normalizePriceList(
            $tw['working_prices'] ?? [], 'from', 'ct_per_m3', $this->i18n->t('contracts.priceGroup.twWorking')
        );
        $tw['base_prices']    = $this->normalizePriceList(
            $tw['base_prices']    ?? [], 'from', 'eur_per_month', $this->i18n->t('contracts.priceGroup.twBase')
        );
        $c['trinkwasser'] = $tw;

        // ── Schmutzwasser ───────────────────────────────────────────
        $sw = is_array($c['schmutzwasser'] ?? null) ? $c['schmutzwasser'] : [];
        $basis = (string)($sw['basis'] ?? 'trinkwasser');
        if (!in_array($basis, ['trinkwasser', 'separater_zaehler', 'trinkwasser_minus_abzug'], true)) {
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
        // v3.1.0 (Review CALC-20) — Schmutzwasser = Trinkwasser minus Abzugszähler
        // (Gartenwasser fließt nicht in den Kanal). Der häufigste Fall auf der
        // Abwasserrechnung; bis v3.0 nur über einen separaten Zähler abbildbar.
        $deduct = [];
        if ($basis === 'trinkwasser_minus_abzug') {
            foreach ((array)($sw['abzug_meter_ids'] ?? []) as $id) {
                $id = trim((string)$id);
                if ($id !== '' && !in_array($id, $deduct, true)) $deduct[] = $id;
            }
            if ($deduct === []) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.swDeductionRequired'));
            }
            if (in_array((string)($c['meter_id'] ?? ''), $deduct, true)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.swDeductionSelf'));
            }
        }
        $sw['abzug_meter_ids'] = $deduct;
        $sw['working_prices'] = $this->normalizePriceList(
            $sw['working_prices'] ?? [], 'from', 'ct_per_m3', $this->i18n->t('contracts.priceGroup.swWorking')
        );
        // v3.9.0 (F1034) — Grundpreis auch für Schmutzwasser (Basis- und Bereitstellungspreis der
        // Abwasserrechnung). Optional: Ein Vertrag ohne das Feld bleibt ohne (gelesen mit `?? []`),
        // so rechnet der Bestand byte-gleich weiter
        if (array_key_exists('base_prices', $sw)) {
            $sw['base_prices'] = $this->normalizePriceList(
                (array)($sw['base_prices'] ?? []), 'from', 'eur_per_month', $this->i18n->t('contracts.priceGroup.swBase')
            );
            if ($sw['base_prices'] === []) unset($sw['base_prices']);
        }
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
            $this->assertDate($from, $this->i18n->t('errors.contract.fields.date') . ' #' . ($i + 1));
            $cleanedRates[] = [
                'from'                   => $from,
                'eur_per_m2_year'        => $this->parseAmount($rate, $this->i18n->t('errors.contract.fields.ratePerM2'), $i + 1),
                'versiegelte_flaeche_m2' => $this->parseAmount($area, $this->i18n->t('errors.contract.fields.sealedArea'), $i + 1),
            ];
        }
        usort($cleanedRates, fn($a, $b) => strcmp($a['from'], $b['from']));
        $c['niederschlagswasser'] = ['rates' => $cleanedRates];

        // ── Advance payments + bonuses (gleiche Logik wie Standard) ─
        // v3.9.0 (F1034) — mit Rhythmus (every_months, due_dates), wie beim Standardvertrag
        $c['advance_payments'] = $this->normalizeAdvancePayments(
            $c['advance_payments'] ?? [], $this->i18n->t('contracts.priceGroup.advance')
        );
        $c['bonuses'] = $this->normalizeBonuses($c['bonuses'] ?? []);
        // v3.5.0 (GitHub #34) — Sonderzahlungen wie beim Standardvertrag. Ein
        // älterer Wasservertrag ohne das Feld behält es fehlend, bis welche
        // kommen (gelesen wird überall mit `?? []`); so bleiben Beispiel- und
        // Bestandsdaten beim Speichern anderer Felder, wie sie sind.
        if (array_key_exists('special_payments', $c)) {
            $c['special_payments'] = $this->normalizeSpecialPayments((array)($c['special_payments'] ?? []));
        }
        return $this->normalizeSwitchFields($c);
    }

    private function validateMeta(array $c): void
    {
        if (empty($c['start'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.startRequired'));
        }
        // v2.5.3 — Vertragsdaten kalendergültig. Ein beliebiger String landete
        // bisher unverändert in der Datei und von dort ungefiltert im DOM.
        $this->assertDate($c['start'], $this->i18n->t('errors.contract.fields.start'));
        if (!empty($c['end'])) {
            $this->assertDate($c['end'], $this->i18n->t('errors.contract.fields.end'));
        }
        if (!empty($c['end']) && $c['end'] < $c['start']) {
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.endBeforeStart'));
        }
    }

    /**
     * v2.3.0 — Wechselfelder säubern.
     *
     * Leere Eingaben werden zu null, nicht zu 0 bzw. "": Eine Kündigungsfrist
     * von null heißt „nicht gepflegt" und schaltet die Terminrechnung ab; eine
     * von 0 hieße „jederzeit kündbar" und ist eine Aussage. Der Unterschied
     * entscheidet, ob die Oberfläche einen Wechseltermin verspricht oder nach
     * der Angabe fragt.
     */
    private function normalizeSwitchFields(array $c): array
    {
        $notice = $c['notice_period_months'] ?? null;
        if ($notice === null || $notice === '' || $notice === false) {
            $c['notice_period_months'] = null;
        } else {
            if (!is_numeric($notice)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.noticeNotNumeric'));
            }
            $n = (int)$notice;
            if ($n < 0 || $n > 24) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.noticeOutOfRange'));
            }
            $c['notice_period_months'] = $n;
        }

        // v2.9.0 — Frist in Tagen (Wochen speichert die Oberfläche als Tage).
        // Gesetzt hat sie Vorrang vor den Monaten.
        $days = $c['notice_period_days'] ?? null;
        if ($days === null || $days === '' || $days === false) {
            $c['notice_period_days'] = null;
        } else {
            if (!is_numeric($days)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.noticeNotNumeric'));
            }
            $d = (int)$days;
            if ($d < 0 || $d > 730) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.noticeDaysOutOfRange'));
            }
            $c['notice_period_days'] = $d;
        }

        $mode = $c['notice_mode'] ?? null;
        if ($mode === null || $mode === '' || $mode === false) {
            $c['notice_mode'] = null;
        } elseif (!in_array($mode, self::NOTICE_MODES, true)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.contract.noticeModeInvalid', ['mode' => (string)$mode]));
        }

        // null = nicht angegeben (verlängert sich, der gesetzliche Normalfall)
        $renews = $c['auto_renews'] ?? null;
        $c['auto_renews'] = match (true) {
            $renews === null || $renews === '' => null,
            $renews === false || $renews === 0 || $renews === '0' || $renews === 'false' => false,
            default => true,
        };

        foreach (['min_term_end', 'price_guarantee_until'] as $field) {
            $v = $c[$field] ?? null;
            $v = ($v === null || $v === false) ? '' : trim((string)$v);
            if ($v === '') { $c[$field] = null; continue; }
            $this->assertDate($v, $field);
            if (!empty($c['start']) && $v < $c['start']) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.dateBeforeStart', ['field' => $field])
                );
            }
            $c[$field] = $v;
        }

        $bonus = $c['signup_bonus_eur'] ?? null;
        if ($bonus === null || $bonus === '' || $bonus === false) {
            $c['signup_bonus_eur'] = null;
        } else {
            if (!is_numeric($bonus)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.bonusNotNumeric'));
            }
            if ((float)$bonus < 0) {
                throw new \InvalidArgumentException($this->i18n->t('errors.contract.bonusNegative'));
            }
            $c['signup_bonus_eur'] = (float)$bonus;
        }

        // v3.7.0 (Posteingang) — Vertrags-/Kundennummer: kurzer Text, leer entfernt;
        // Belege: Liste gültiger Beleg-IDs (verknüpft in create()/update())
        foreach (['contract_number', 'customer_number'] as $f) {
            if (!array_key_exists($f, $c)) continue;
            $v = is_scalar($c[$f]) ? trim((string)$c[$f]) : '';
            if ($v === '') unset($c[$f]);
            else $c[$f] = mb_substr($v, 0, 64);
        }
        if (array_key_exists('attachment_ids', $c)) {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array)$c['attachment_ids']), fn($x) => $x !== '')));
            $known = array_column(array_filter((array)$this->store->read(AttachmentService::INDEX, []), 'is_array'), 'id');
            foreach ($ids as $x) {
                if (!preg_match(AttachmentService::ID, $x) || !in_array($x, $known, true)) {
                    throw new LocalizedException('errors.attachment.notFound', [], "contract attachment $x");
                }
            }
            if ($ids === []) unset($c['attachment_ids']); else $c['attachment_ids'] = $ids;
        }

        // v3.11.0 (F1035, Entscheidung 6a) — Grundversorgung (`basic`) oder Sondervertrag (`special`);
        // fehlt bzw. null = nicht angegeben (Hinweise nennen dann beide Normen). Optional, leer entfernt.
        if (array_key_exists('supply_kind', $c)) {
            $v = $c['supply_kind'];
            if ($v === null || $v === '') unset($c['supply_kind']);
            elseif (!in_array($v, self::SUPPLY_KINDS, true)) {
                throw new LocalizedException('errors.contract.valueInvalid', ['field' => 'supply_kind', 'value' => is_scalar($v) ? (string)$v : ''], 'supply_kind');
            }
        }

        return $c;
    }

    /** v3.11.0 (F1035) — Versorgungsart eines Vertrags (Config\Rights::SUPPLY_KINDS). */
    public const SUPPLY_KINDS = \Energietracker\Config\Rights::SUPPLY_KINDS;

    /**
     * v2.3.0 — Wann ist ein Wechsel frühestens möglich, und bis wann muss dafür
     * gekündigt werden?
     *
     * Zwei verschiedene Daten, die gern verwechselt werden:
     *   cancel_by   — letzter Tag, an dem die Kündigung raus muss
     *   switch_date — erster Tag, an dem ein neuer Tarif liefert
     *
     * Befristeter Vertrag (`end` gesetzt): Er endet ohnehin, der Wechsel greift
     * am Folgetag. Die Frist bestimmt nur, wann gekündigt werden muss, damit er
     * sich nicht verlängert.
     *
     * Unbefristeter Vertrag: Gekündigt wird zum Monatsende nach Ablauf der
     * Frist, frühestens jedoch zum Ende der Mindestlaufzeit.
     *
     * Ohne gepflegte Frist gibt es keinen belastbaren Termin — dann liefert die
     * Methode `null` und die Oberfläche fragt danach, statt etwas zu erfinden.
     *
     * v2.9.0 (Review CALC-11):
     *   - Fristen auch in Tagen (`notice_period_days`, hat Vorrang).
     *   - `notice_mode`: `term_end` (zum Laufzeitende, Standard bei Verträgen
     *     mit Ende), `month_end` (jederzeit zum Monatsende, Standard ohne
     *     Ende), `any_day` (jederzeit, etwa die Grundversorgung mit zwei
     *     Wochen). Ein befristeter Vertrag, der jederzeit kündbar ist, endet
     *     frühestens nach der Frist, spätestens zu seinem Ende.
     *   - `$renewed`: Die Laufzeit ist abgelaufen, der Vertrag läuft mangels
     *     Kündigung weiter.
     *     v3.11.0 (Skeptiker H3) — bis v3.10 hieß es hier für jede Verbrauchsart „jederzeit mit
     *     höchstens einem Monat Frist kündbar (§ 309 Nr. 9 b BGB)“ samt berechnetem frühestem
     *     Ende. Für Fernwärme widerspricht das § 32 AVBFernwärmeV, für Strom und Gas ist es nicht
     *     sicher belegt (§ 310 Abs. 2 BGB, Übergangsrecht; Prüfbericht 31, 32, 59). Jetzt:
     *       Fernwärme in Deutschland (`$utility`, `$country`): § 32 Abs. 1 AVBFernwärmeV
     *         (Prüfbericht 30) — ohne Kündigung neun Monate vor Ablauf verlängert sich der
     *         Vertrag um jeweils fünf Jahre: `renewal_term` mit dem Ende der laufenden
     *         Verlängerung (`switch_date` − 1) und dem Tag, bis zu dem gekündigt sein muss;
     *       sonst `renewed` ohne Termin (`switch_date`, `cancel_by` null): Die Frist steht im
     *         Vertrag, die App erfindet keine.
     *     v3.11.0 (Nachprüfung):
     *       NP4 — `$renewedUntil`: Ein Folgevertrag beginnt am Tag danach (coverage `to`); dann gilt er,
     *         `renewed` mit `switch_date` = sein Beginn, ohne Ende einer Verlängerung nach § 32.
     *       Grundversorgung (Strom, Gas, `supply_kind: basic`, Land mit Regeln): jederzeit mit zwei
     *         Wochen Frist (§ 20 Abs. 1 StromGVV/GasGVV, Prüfbericht 26): `basic_supply`, `switch_date`
     *         = heute + 14 Tage + 1, ohne datierten Stichtag (`cancel_by` null, wie jederzeit kündbar).
     *   - NP3 — Fernwärme in Deutschland, befristet, verlängert sich, keine Frist eingetragen: § 32 Abs. 1
     *     AVBFernwärmeV schon vor dem Ende. `cancel_by` = Ende − neun Monate (Basis `fixed_end`, damit
     *     Erinnerung, „verpasst“ und Termin wie bei einer eingetragenen Frist laufen), dazu
     *     `renewal_term` {until, cancel_by}: die Verlängerung um fünf Jahre, die ohne Kündigung folgt.
     *     Ist der Kündigungstag vorbei, ist `switch_date` der Tag nach deren Ende (kein „jetzt
     *     wechseln“ zum Laufzeitende). Eine eingetragene Frist geht vor.
     *
     * @return array{switch_date: ?string, cancel_by: ?string, days_to_cancel: ?int, basis: string, renewal_term?: array{until:string, cancel_by:string}}
     */
    public function switchTiming(array $contract, ?string $today = null, bool $renewed = false, ?string $utility = null, ?string $country = null,
                                 ?string $renewedUntil = null): array
    {
        $today  = $today ?: date('Y-m-d');
        $notice = self::noticeOf($contract);
        $end    = $contract['end'] ?? null;
        $mode   = $contract['notice_mode'] ?? null;
        $rules  = $country !== null && Rights::supports($country);
        $heat   = $utility === 'fernwaerme' && $rules;

        if ($renewed) {
            if ($renewedUntil !== null && Dates::isIsoDate($renewedUntil)) {
                return ['switch_date' => date('Y-m-d', (int)strtotime($renewedUntil . ' +1 day')), 'cancel_by' => null, 'days_to_cancel' => null, 'basis' => 'renewed'];
            }
            if ($heat && Dates::isIsoDate($end)) {
                $term = self::heatRenewalTerm((string)$end, $today);
                return [
                    'switch_date'    => date('Y-m-d', (int)strtotime($term['until'] . ' +1 day')),
                    'cancel_by'      => $term['cancel_by'],
                    'days_to_cancel' => self::daysBetween($today, $term['cancel_by']),
                    'basis'          => 'renewal_term',
                ];
            }
            if ($rules && $utility !== null && Rights::isEnergy((string)$country, $utility) && ($contract['supply_kind'] ?? null) === 'basic') {
                $last = self::addNotice($today, ['months' => null, 'days' => self::BASIC_NOTICE_DAYS]);
                return ['switch_date' => date('Y-m-d', (int)strtotime($last . ' +1 day')), 'cancel_by' => null, 'days_to_cancel' => null, 'basis' => 'basic_supply'];
            }
            return ['switch_date' => null, 'cancel_by' => null, 'days_to_cancel' => null, 'basis' => 'renewed'];
        }

        if (!empty($end) && ($mode === null || $mode === 'term_end')) {
            $switch   = date('Y-m-d', strtotime($end . ' +1 day'));
            $heatLaw  = $notice === null && $heat && self::renews($contract) && Dates::isIsoDate($end);
            $cancelBy = $notice !== null ? self::subNotice($end, $notice)
                : ($heatLaw ? self::addMonthsClamped((string)$end, -self::HEAT_NOTICE_MONTHS) : null);
            $out = [
                'switch_date'    => $switch,
                'cancel_by'      => $cancelBy,
                'days_to_cancel' => $cancelBy !== null ? self::daysBetween($today, $cancelBy) : null,
                'basis'          => 'fixed_end',
            ];
            if ($heatLaw) {
                // die Verlängerung, die ohne Kündigung folgt (die erste, deren Kündigungstag noch kommt)
                $term = self::heatRenewalTerm((string)$end, $today);
                if ($cancelBy < $today) $out['switch_date'] = date('Y-m-d', (int)strtotime($term['until'] . ' +1 day'));
                $out['renewal_term'] = $term;
            }
            return $out;
        }

        if ($notice === null) {
            return ['switch_date' => null, 'cancel_by' => null, 'days_to_cancel' => null, 'basis' => 'unknown'];
        }

        // Kündigung heute → wirksam nach Ablauf der Frist: zu jedem Tag oder
        // zum Monatsende danach (über den Monatsersten gerechnet, den es in
        // jedem Monat gibt).
        $effective = $mode === 'any_day'
            ? self::addNotice($today, $notice)
            : (new \DateTimeImmutable(self::addNotice($today, $notice)))->modify('last day of this month')->format('Y-m-d');
        $basis = 'open_ended';
        $minTerm = $contract['min_term_end'] ?? null;
        if (!empty($minTerm) && $minTerm > $effective) {
            $effective = $minTerm;
            $basis = 'min_term';
        }
        if (!empty($end) && $end < $effective) {
            // Die Laufzeit endet vor Ablauf der Frist — dann eben zum Ende.
            $effective = $end;
            $basis = 'fixed_end';
        }
        return [
            'switch_date'    => date('Y-m-d', strtotime($effective . ' +1 day')),
            'cancel_by'      => $today,
            'days_to_cancel' => 0,
            'basis'          => $basis,
        ];
    }

    /**
     * v3.4.0 (F1024) — Hat der Vertrag einen datierten Kündigungsstichtag?
     * Nur ein befristeter Vertrag, der zum Laufzeitende kündbar ist (der
     * erste Zweig von switchTiming()). Weiterlaufende, unbefristete und
     * jederzeit kündbare Verträge haben keinen: Dort liefert switchTiming() als
     * `cancel_by` „heute" — für die Wechselplanung richtig (kündigen und
     * wechseln geht ab sofort), als Termin aber ein Ereignis, das täglich
     * weiterwandert. switchTiming() selbst bleibt unverändert.
     */
    /** v3.11.0 (Skeptiker H3) — § 32 Abs. 1 AVBFernwärmeV: Verlängerung um jeweils fünf Jahre (Prüfbericht 30). */
    public const HEAT_RENEWAL_MONTHS = 60;
    /** … wenn nicht neun Monate vor Ablauf gekündigt wird. */
    public const HEAT_NOTICE_MONTHS = 9;
    /** v3.11.0 (Nachprüfung) — Grundversorgung: Kündigung mit zwei Wochen Frist (§ 20 Abs. 1 StromGVV/GasGVV, Prüfbericht 26). */
    public const BASIC_NOTICE_DAYS = 14;

    /**
     * v3.11.0 (Skeptiker H3) — die laufende Verlängerung eines Fernwärmevertrags nach § 32 Abs. 1
     * AVBFernwärmeV: die erste Verlängerung um fünf Jahre ab dem Laufzeitende, deren Kündigungstag
     * (neun Monate vor ihrem Ablauf) heute noch nicht vorbei ist. Monate am Monatsende geklemmt
     * (Lektion 26). Ein abweichender Vertrag geht vor; das sagt der Text dazu.
     *
     * @return array{until:string, cancel_by:string}
     */
    public static function heatRenewalTerm(string $end, string $today): array
    {
        for ($k = 1; $k <= 40; $k++) {
            $until = self::addMonthsClamped($end, self::HEAT_RENEWAL_MONTHS * $k);
            $cancelBy = self::addMonthsClamped($until, -self::HEAT_NOTICE_MONTHS);
            if ($cancelBy >= $today) return ['until' => $until, 'cancel_by' => $cancelBy];
        }
        return ['until' => $until, 'cancel_by' => $cancelBy];
    }

    public static function hasFixedCancelBy(array $contract, bool $renewed = false): bool
    {
        return !$renewed && !empty($contract['end'])
            && in_array($contract['notice_mode'] ?? null, [null, '', 'term_end'], true);
    }

    /**
     * v3.4.0 (F1024) — Der Folgevertrag: der nächste echte Vertrag desselben
     * Ziels (Zähler oder Gruppe; die Liste stellt der Aufrufer), der später
     * beginnt und nicht vor diesem endet. `gap_days` = Tage ohne Vertrag
     * zwischen Ende + 1 und Beginn (0 = lückenlos, auch bei Überlappung). Ein
     * weiterlaufender oder unbefristeter Vertrag läuft bis zum Folgevertrag —
     * dort ist die Lücke immer 0. Schattenverträge zählen nie.
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{contract_id:string, provider:string, start:string, gap_days:int}|null
     */
    public static function successorOf(array $contract, array $contracts, bool $renewed = false): ?array
    {
        $id    = (string)($contract['id'] ?? '');
        $start = (string)($contract['start'] ?? '');
        $end   = (string)($contract['end'] ?? '');
        $best  = null;
        foreach ($contracts as $o) {
            if ((string)($o['id'] ?? '') === $id || !empty($o['is_shadow'])) continue;
            $os = (string)($o['start'] ?? '');
            if ($os === '' || $os <= $start) continue;
            // endet er nicht nach diesem, folgt er ihm nicht (liegt darin)
            if ($end !== '' && !empty($o['end']) && (string)$o['end'] <= $end) continue;
            if ($best === null || $os < (string)$best['start']) $best = $o;
        }
        if ($best === null) return null;
        $gap = 0;
        if ($end !== '' && !$renewed) {
            $gap = max(0, self::daysBetween(date('Y-m-d', (int)strtotime("$end +1 day")), (string)$best['start']));
        }
        return [
            'contract_id' => (string)$best['id'],
            'provider'    => (string)(($best['provider'] ?? '') ?: ($best['tariff_name'] ?? '')),
            'start'       => (string)$best['start'],
            'gap_days'    => $gap,
            // v3.11.0 (Skeptiker N10, additiv) — die Lücke füllt die Verlängerung ohne Kündigung
            // (resolveForDate): kein „… Tage ohne Vertrag“; die Erinnerung ans Ende bleibt
            'gap_covered' => $gap > 0 && self::renews($contract),
        ];
    }

    /**
     * v3.6.0 — Derselbe Versorger? Anbieter (sonst Tarifname), ohne
     * Groß/klein und Leerzeichen am Rand; ohne Namen nie derselbe. Eine Regel
     * für „Abrechnung erwartet“ (BillExpectedService) und die
     * Abrechnungsperioden (BillingPeriodService).
     */
    public static function sameSupplier(array $a, array $b): bool
    {
        $name = fn(array $c) => mb_strtolower(trim((string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? ''))));
        return $name($a) !== '' && $name($a) === $name($b);
    }

    /**
     * v3.6.0 — Endet mit diesem Vertrag das Lieferverhältnis, weil ein
     * Folgevertrag ({@see successorOf()}) eines anderen Versorgers beginnt?
     * Dann rechnet der alte Versorger mit einer Schlussrechnung ab.
     *
     * Ein Wechsel ist:
     *   - ein Folgevertrag eines anderen Versorgers ({@see sameSupplier()}),
     *   - ein Folgevertrag desselben Versorgers erst nach einer Lücke (der
     *     Vertrag verlängert sich nicht, `auto_renews` = false, und der neue
     *     beginnt später als am Tag nach dem Ende).
     * Kein Wechsel: ein Folgevertrag desselben Versorgers, der lückenlos
     * anschließt (Verlängerung, neuer Tarif), ein weiterlaufender Vertrag ohne
     * Folgevertrag. So erwartet auch „Abrechnung erwartet“ eine Schlussrechnung.
     *
     * `until` = letzter Tag beim alten Versorger: das Vertragsende; ein
     * Vertrag, der sich verlängert (oder ohne Ende), läuft bis zum Tag vor dem
     * Folgevertrag; überlappen sie, gilt ab dessen Beginn der neue (wie
     * {@see findActiveForDate()}). `next` = Beginn des Folgevertrags. Liegt
     * dazwischen eine Lücke, gilt dort kein Vertrag ({@see resolveForDate()}).
     *
     * @param array<int,array<string,mixed>> $contracts echte Verträge desselben Ziels (bzw. was für den Zähler gilt)
     * @return array{until:string,next:string,successor_id:string}|null
     */
    public static function supplierSwitchAfter(array $contract, array $contracts): ?array
    {
        if (!empty($contract['is_shadow'])) return null;
        $succ = self::successorOf($contract, $contracts);
        if ($succ === null) return null;
        $end = (string)($contract['end'] ?? '');
        $before = date('Y-m-d', (int)strtotime($succ['start'] . ' -1 day'));
        $until = $end === '' || $end >= $before || self::renews($contract) ? $before : $end;
        if ($succ['start'] === date('Y-m-d', (int)strtotime("$until +1 day"))
            && self::sameSupplier($contract, ['provider' => $succ['provider']])) return null;
        return ['until' => $until, 'next' => $succ['start'], 'successor_id' => $succ['contract_id']];
    }

    /**
     * v2.9.0 — Kündigungsfrist eines Vertrags: Tage haben Vorrang vor
     * Monaten; null, wenn keine gepflegt ist.
     *
     * @return array{months:?int,days:?int}|null
     */
    public static function noticeOf(array $contract): ?array
    {
        $days = $contract['notice_period_days'] ?? null;
        if ($days !== null && $days !== '') return ['months' => null, 'days' => (int)$days];
        $months = $contract['notice_period_months'] ?? null;
        if ($months !== null && $months !== '') return ['months' => (int)$months, 'days' => null];
        return null;
    }

    /** Datum plus Frist (Monate über den Monatsersten geklemmt, Lesson 26). */
    private static function addNotice(string $date, array $notice): string
    {
        if (($notice['days'] ?? null) !== null) {
            return (new \DateTimeImmutable($date))->modify('+' . (int)$notice['days'] . ' days')->format('Y-m-d');
        }
        $months = (int)($notice['months'] ?? 0);
        $d      = new \DateTimeImmutable($date);
        $target = $d->modify('first day of this month')->add(new \DateInterval("P{$months}M"));
        $day    = min((int)$d->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    /** Datum minus Frist — der letzte Tag, an dem die Kündigung zugehen muss. */
    private static function subNotice(string $date, array $notice): string
    {
        if (($notice['days'] ?? null) !== null) {
            return (new \DateTimeImmutable($date))->modify('-' . (int)$notice['days'] . ' days')->format('Y-m-d');
        }
        return self::subMonthsClamped($date, (int)($notice['months'] ?? 0));
    }

    /**
     * Monate abziehen, ohne in den Folgemonat zu rutschen.
     *
     * PHPs `strtotime('2026-03-31 -1 month')` liefert **2026-03-03**: Es zieht
     * den Monat ab, landet auf dem nicht existierenden 31. Februar und lässt
     * den Überlauf stehen. Für eine Kündigungsfrist ist das Ergebnis nicht nur
     * ungenau, sondern gefährlich — es nennt einen Stichtag vier Wochen nach
     * dem echten. Deshalb wird der Tag auf den letzten des Zielmonats geklemmt.
     */
    private static function subMonthsClamped(string $date, int $months): string
    {
        $d      = new \DateTimeImmutable($date);
        $target = $d->modify('first day of this month')->sub(new \DateInterval("P{$months}M"));
        $day    = min((int)$d->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from);
        $b = new \DateTimeImmutable($to);
        return (int)$a->diff($b)->format('%r%a');
    }

    /** v2.5.3 — kalendergültiges Datum oder 400 mit Feldangabe. */
    private function assertDate(mixed $value, string $field): void
    {
        if (!Dates::isIsoDate($value)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.contract.dateInvalid', ['field' => $field, 'value' => is_scalar($value) ? (string)$value : gettype($value)])
            );
        }
    }

    /**
     * v2.5.3 — Beträge müssen Zahlen sein. `(float)"12,5"` ergab bisher still
     * 12, `(float)"abc"` still 0 — ein Tarif „0 ct ab Stichtag" (Lektion 24).
     */
    private function parseAmount(mixed $value, string $label, int $n): float
    {
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.contract.amountInvalid', ['label' => $label, 'n' => $n, 'value' => is_scalar($value) ? (string)$value : gettype($value)])
            );
        }
        return (float)$value;
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
            $this->assertDate($date, $label . ' #' . ($i + 1));
            $row = [
                $dateKey   => $date,
                $amountKey => $this->parseAmount($amount, $label, $i + 1),
            ];
            // v3.11.0 (F1035, A3/C, Entscheidung 8a) — Herkunft eines Preises, nur wenn gesetzt:
            // `announced_on` (Datum des Schreibens: eine einseitige Preisänderung, kein Stufenpreis)
            // und `vat_pct` (Steuersatz, den der Preis enthält: reine Steueränderung erkennen)
            $ann = isset($e['announced_on']) ? trim((string)$e['announced_on']) : '';
            if ($ann !== '') {
                $this->assertDate($ann, $label . ' #' . ($i + 1));
                $row['announced_on'] = $ann;
            }
            $vat = $e['vat_pct'] ?? null;
            if ($vat !== null && $vat !== '' && $vat !== false) {
                $n = is_string($vat) ? ReadingImportService::parseNum($vat) : (is_int($vat) || is_float($vat) ? (float)$vat : null);
                if ($n === null || !is_finite($n) || $n < 0 || $n > 30) {
                    throw new LocalizedException('errors.contract.valueInvalid', ['field' => 'vat_pct', 'value' => is_scalar($vat) ? (string)$vat : ''], 'price vat_pct');
                }
                $row['vat_pct'] = round($n, 2);
            }
            // v3.11.0 (F1035, Paket vertrag, A3, Entscheidung 7c) — „Kündigung bis …“ laut Schreiben, nur
            // wenn gesetzt: Liegt das Datum vor dem gesetzlichen Termin, gilt es als Termin (beide im Text)
            $until = isset($e['special_termination_until']) ? trim((string)$e['special_termination_until']) : '';
            if ($until !== '') {
                $this->assertDate($until, $label . ' #' . ($i + 1));
                $row['special_termination_until'] = $until;
            }
            $cleaned[] = $row;
        }
        usort($cleaned, fn($a, $b) => strcmp($a[$dateKey], $b[$dateKey]));
        return $cleaned;
    }

    /** v3.9.0 (F1034) — erlaubter Rhythmus eines Abschlags in Monaten (1 = monatlich, wird nicht gespeichert). */
    public const ADVANCE_EVERY = [1, 2, 3];

    /**
     * v3.9.0 (F1034) — Abschläge wie normalizePriceList (Datum und Betrag beide
     * oder keins), dazu der Rhythmus der Zeile:
     *   every_months  2 oder 3 (alle zwei bzw. drei Monate ab `from`); 1 heißt
     *                 monatlich und fehlt dann, wie bei jedem Bestandsvertrag
     *   due_dates     die genannten Termine, aufsteigend, keiner vor `from`;
     *                 ohne every_months aus regelmäßigen Terminen abgeleitet
     *                 (nur zur Anzeige, gerechnet wird mit den Terminen)
     * Ein anderer Rhythmus oder ein ungültiger Termin ist ein Fehler, kein
     * stiller Rückfall auf „monatlich“ (Lektion 4).
     */
    private function normalizeAdvancePayments(array $entries, string $label): array
    {
        $cleaned = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) continue;
            $n = $i + 1;
            $date   = isset($e['from']) ? trim((string)$e['from']) : '';
            $amount = $e['amount_eur'] ?? null;
            $amountFilled = $amount !== null && $amount !== '' && $amount !== false;
            if ($date === '' && !$amountFilled) continue; // silent drop
            if (($date !== '') !== $amountFilled) {
                $missing = $this->i18n->t($date !== '' ? 'errors.contract.fields.amount' : 'errors.contract.fields.date');
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.contract.entryMissing', ['label' => $label, 'n' => $n, 'field' => $missing])
                );
            }
            $this->assertDate($date, $label . ' #' . $n);
            $row = ['from' => $date, 'amount_eur' => $this->parseAmount($amount, $label, $n)];
            $every = $e['every_months'] ?? null;
            if ($every !== null && $every !== '' && $every !== false) {
                if (!is_numeric($every) || (float)$every != (int)$every || !in_array((int)$every, self::ADVANCE_EVERY, true)) {
                    throw new LocalizedException('errors.contract.advanceEveryInvalid', ['n' => $n, 'value' => is_scalar($every) ? (string)$every : ''], 'advance every');
                }
                if ((int)$every > 1) $row['every_months'] = (int)$every;
            }
            $dates = [];
            foreach ((array)($e['due_dates'] ?? []) as $d) {
                $d = trim((string)$d);
                if ($d === '') continue;
                $this->assertDate($d, $label . ' #' . $n);
                if ($d < $date) throw new LocalizedException('errors.contract.advanceDueBeforeStart', ['n' => $n, 'date' => $d], 'advance due date');
                $dates[$d] = true;
            }
            if ($dates !== []) {
                $dates = array_map('strval', array_keys($dates));
                sort($dates);
                $derived = self::rhythmOf($dates);
                if (!isset($row['every_months']) && $derived !== null && $derived > 1) $row['every_months'] = $derived;
                $row['due_dates'] = $dates;   // nach every_months: eine Form, ob abgeleitet oder angegeben
            }
            $cleaned[] = $row;
        }
        usort($cleaned, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $cleaned;
    }

    /**
     * v3.9.0 (F1034) — Rhythmus regelmäßiger Termine in Monaten (gleicher Tag,
     * am Monatsende geklemmt, gleicher Abstand), sonst null. Zwei Termine
     * genügen; nur Abstände von einem bis zwölf Monaten.
     *
     * @param list<string> $dates aufsteigend
     */
    public static function rhythmOf(array $dates): ?int
    {
        if (count($dates) < 2) return null;
        $months = fn(string $a, string $b): int => ((int)substr($b, 0, 4) - (int)substr($a, 0, 4)) * 12 + (int)substr($b, 5, 2) - (int)substr($a, 5, 2);
        $n = $months($dates[0], $dates[1]);
        if ($n < 1 || $n > 12) return null;
        // vom ersten Termin aus gezählt wie installments(): 31.01., 28.02., 31.03. ist monatlich
        for ($i = 1; $i < count($dates); $i++) {
            if (self::addMonthsClamped($dates[0], $n * $i) !== $dates[$i]) return null;
        }
        return $n;
    }

    /** Tag plus N Monate über den Monatsersten, Tag auf das Monatsende geklemmt (Lektion 26). */
    public static function addMonthsClamped(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date);
        $target = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $day = min((int)$d->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->format('Y-m-d');
    }

    /** v3.9.0 (F1034) — Hat die Zeile eines Abschlagsplans einen Rhythmus (nicht monatlich)? */
    public static function hasRhythm(array $row): bool
    {
        return (int)($row['every_months'] ?? 1) > 1 || !empty($row['due_dates']);
    }

    /** Rhythmus einer Zeile zur Anzeige: every_months, sonst 1. */
    public static function everyMonths(?array $row): int
    {
        return $row === null ? 1 : max(1, (int)($row['every_months'] ?? 1));
    }

    /**
     * Die Zeile eines Plans, die an einem Tag gilt — dieselbe Wahl wie
     * valueOnDate(): der letzte Eintrag mit `from` ≤ Tag, bei gleichem Tag der
     * spätere in der Liste.
     */
    public static function planRowOn(array $plan, string $date): ?array
    {
        $row = null; $best = '';
        foreach ($plan as $e) {
            $from = (string)($e['from'] ?? '');
            if ($from === '' || $from > $date || !isset($e['amount_eur']) || !is_numeric($e['amount_eur'])) continue;
            if ($from >= $best) { $best = $from; $row = $e; }
        }
        return $row;
    }

    /**
     * v3.9.0 (F1034) — Termine der Zeilen mit Rhythmus in [von, bis): je Zeile
     * ab ihrem `from` bis vor die nächste Zeile, alle N Monate (`every_months`,
     * Tag wie `from`, am Monatsende geklemmt) bzw. an ihren `due_dates` und
     * danach jährlich zu denselben Tagen: Die Rechnung nennt die Termine eines
     * Abrechnungsjahrs (etwa fünf, weil die sechste Rate die nächste Rechnung
     * ist); ohne neuen Plan geht es im selben Takt weiter.
     *
     * @param array<int,array<string,mixed>> $plan Abschlagsplan (effectiveAdvanceSchedule)
     * @return list<array{date:string, amount_eur:float}>
     */
    public static function installments(array $plan, string $from, string $toExcl): array
    {
        $rows = array_values(array_filter($plan, fn($r) => is_array($r) && isset($r['from'], $r['amount_eur']) && is_numeric($r['amount_eur'])));
        usort($rows, fn($a, $b) => strcmp((string)$a['from'], (string)$b['from']));
        $out = [];
        foreach ($rows as $i => $r) {
            if (!self::hasRhythm($r)) continue;
            $start = (string)$r['from'];
            $until = isset($rows[$i + 1]) ? (string)$rows[$i + 1]['from'] : '9999-12-31';
            $lo = max($start, $from);
            $hi = min($until, $toExcl);
            if ($lo >= $hi) continue;
            $dates = [];
            $listed = array_values(array_filter(array_map('strval', (array)($r['due_dates'] ?? [])), fn($d) => $d >= $start));
            if ($listed !== []) {
                $last = max($listed);
                foreach ($listed as $d) {
                    for ($k = 0; $k <= 100; $k++) {
                        $x = $k === 0 ? $d : self::addMonthsClamped($d, 12 * $k);
                        if ($x >= $hi) break;
                        if ($k > 0 && $x <= $last) continue;
                        if ($x >= $lo) $dates[$x] = true;
                    }
                }
            } else {
                $every = self::everyMonths($r);
                for ($k = 0; $k <= 1200; $k++) {
                    $x = self::addMonthsClamped($start, $k * $every);
                    if ($x >= $hi) break;
                    if ($x >= $lo) $dates[$x] = true;
                }
            }
            foreach (array_keys($dates) as $d) $out[] = ['date' => (string)$d, 'amount_eur' => (float)$r['amount_eur']];
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /**
     * v3.9.0 (F1034) — Abschlag eines Abschnitts [von, bis) eines Monats, eine
     * Rechnung für Saldo, Monatszeilen und Prognose:
     *   monthly  der Monatsbetrag der Zeile, die am ersten Tag `$apDate` gilt,
     *            mal `$share` (der Anteil, den der Aufrufer wie bisher rechnet)
     *            — nur, wenn diese Zeile monatlich ist; beginnt im Abschnitt
     *            eine Zeile mit Rhythmus, nur bis zu deren Beginn
     *   rates    die Raten der Zeilen mit Rhythmus, die im Abschnitt fällig
     *            sind, ganz an ihrem Tag (installments())
     *   planned  gilt überhaupt ein Plan (sonst ist der Abschlag null, nicht 0)
     * Ohne Zeilen mit Rhythmus ist `monthly` genau der bisherige Wert.
     *
     * @param array<int,array<string,mixed>> $plan
     * @return array{monthly: ?float, rates: list<array{date:string, amount_eur:float}>, planned: bool}
     */
    public static function advanceParts(array $plan, string $apDate, string $from, string $toExcl, float $share, int $dim): array
    {
        $row = self::planRowOn($plan, $apDate);
        $monthly = null;
        if ($row !== null && !self::hasRhythm($row)) {
            $cut = null;
            foreach ($plan as $r) {
                $f = (string)($r['from'] ?? '');
                if (is_array($r) && self::hasRhythm($r) && $f > $apDate && $f < $toExcl && ($cut === null || $f < $cut)) $cut = $f;
            }
            if ($cut !== null) $share = min($share, max(0, (int)round((strtotime($cut) - strtotime(max($from, $apDate))) / 86400)) / max(1, $dim));
            $monthly = (float)$row['amount_eur'] * $share;
        }
        $rates = self::installments($plan, $from, $toExcl);
        return ['monthly' => $monthly, 'rates' => $rates, 'planned' => $row !== null || $rates !== []];
    }

    /** advanceParts() als Betrag: Monatsanteil plus Raten, null ohne Plan. */
    public static function advanceIn(array $plan, string $apDate, string $from, string $toExcl, float $share, int $dim): ?float
    {
        $p = self::advanceParts($plan, $apDate, $from, $toExcl, $share, $dim);
        if (!$p['planned']) return null;
        return ($p['monthly'] ?? 0.0) + array_sum(array_column($p['rates'], 'amount_eur'));
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
            $this->assertDate($cd, $this->i18n->t('errors.contract.fields.creditDate') . ' #' . ($i + 1));
            $cleaned[] = [
                'credit_date' => $cd,
                'amount_eur'  => $this->parseAmount($am, $this->i18n->t('contracts.bonus.title'), $i + 1),
                'type'        => (string)($b['type']  ?? 'sofort'),
                'label'       => (string)($b['label'] ?? ''),
            ];
        }
        return $cleaned;
    }

    private function groupLabel(string $group): string
    {
        return match($group) {
            'working_prices'   => $this->i18n->t('contracts.priceGroup.working'),
            'base_prices'      => $this->i18n->t('contracts.priceGroup.base'),
            'advance_payments' => $this->i18n->t('contracts.priceGroup.advance'),
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
            $spLabel = $this->i18n->t('contracts.special.title');
            $this->assertDate($date, $spLabel . ' #' . $n);
            $amt = abs($this->parseAmount($amount, $spLabel, $n)); // Vorzeichen kommt aus kind

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
                if ($afFilled) {
                    $this->assertDate($af, $this->i18n->t('errors.contract.fields.advanceDate') . ' #' . $n);
                }
                $row['new_advance_eur'] = $naFilled ? $this->parseAmount($na, $spLabel, $n) : null;
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
     * F1003 — der effektive Abschlagsplan eines Vertrags (Standard und seit
     * v3.5.0 auch Wasser): `advance_payments` plus die synthetischen Punkte
     * aus allen `*_mit`-Sonderzahlungen (new_advance_eur ab advance_from).
     *
     * Wird von ConsumptionService anstelle von `$c['advance_payments']`
     * verwendet, damit "mit Auswirkung" automatisch in jede monatliche
     * Abschlagsbildung einfließt — ohne Sonderlogik in der Saldo-
     * Aggregation. Ohne Sonderzahlungen ist er `advance_payments`.
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
                ] + self::rhythmFields($e);   // v3.9.0 (F1034) — Rhythmus der Zeile, nur wenn gesetzt
            }
        }
        $base = $schedule;
        foreach ($contract['special_payments'] ?? [] as $sp) {
            if (!in_array($sp['kind'] ?? '', self::SPECIAL_KINDS_AFFECTING_ADVANCE, true)) {
                continue;
            }
            $from = $sp['advance_from']    ?? null;
            $amt  = $sp['new_advance_eur'] ?? null;
            if ($from === null || $from === '' || $amt === null) continue;
            // v3.9.0 (F1034) — der neue Betrag gilt im Takt des Plans, der dann läuft
            // (alle zwei Monate bleibt alle zwei Monate), nicht still monatlich
            $schedule[] = [
                'from'       => (string)$from,
                'amount_eur' => (float)$amt,
            ] + self::rhythmFields(self::planRowOn($base, (string)$from) ?? []);
        }
        usort($schedule, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $schedule;
    }

    /** v3.9.0 (F1034) — die Rhythmusfelder einer Abschlagszeile (leer = monatlich). */
    private static function rhythmFields(array $row): array
    {
        $out = [];
        if ((int)($row['every_months'] ?? 1) > 1) $out['every_months'] = (int)$row['every_months'];
        if (!empty($row['due_dates']) && is_array($row['due_dates'])) $out['due_dates'] = array_values(array_map('strval', $row['due_dates']));
        return $out;
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

    /**
     * Der an einem Datum gültige Vertrag.
     *
     * v2.3.2 — Bei Überlappungen entscheidet jetzt der **späteste Beginn**,
     * nicht mehr die Reihenfolge in der Datei.
     *
     * Vorher gewann der erste passende Eintrag des Arrays, und dessen Position
     * ergibt sich aus der Anlage-Reihenfolge — nicht aus der Fachlichkeit. Auf
     * Produktivdaten fand sich genau dieser Fall: ein Vertrag, der komplett
     * innerhalb eines anderen liegt. Welcher von beiden für einen Monat
     * angesetzt wurde, hing damit davon ab, in welcher Reihenfolge sie
     * irgendwann gespeichert wurden — dieselbe Datenlage konnte
     * unterschiedliche Kosten ergeben.
     *
     * Der spätere Beginn gewinnt, weil ein neu abgeschlossener Vertrag den
     * älteren ablöst. Ohne Überlappung ändert sich nichts.
     *
     * @param array<int,array<string,mixed>> $contracts
     */
    public function findActiveForDate(array $contracts, string $date): ?array
    {
        $best = null;
        foreach ($contracts as $c) {
            if ($date < ($c['start'] ?? '9999')) continue;
            // v3.7.0 (wirklichkeit-2) — ein abgelöster Vertrag gilt nur bis zum Vortag des Folgevertrags
            $end = self::effectiveEnd($c, $contracts);
            if ($end !== null && $date > $end) continue;
            if ($best === null || ($c['start'] ?? '') > ($best['start'] ?? '')) {
                $best = $c;
            }
        }
        return $best;
    }

    /**
     * v2.9.0 (Review CALC-10) — Wert einer datierten Liste an einem Tag: der
     * letzte Eintrag mit `from` ≤ Tag. Anders als {@see valueValidOn()}, das
     * nur den Monatsersten prüft, greift eine Preisänderung zum 15. am 15. —
     * wie auf der Rechnung, nicht erst im Folgemonat.
     */
    /**
     * v3.1.0 (H5, MKT-24) — Hinweise beim Speichern (nicht gespeichert):
     * `term_over_24_months` — eine Erstlaufzeit über 24 Monate ist für
     * Verbraucher unwirksam (§ 309 Nr. 9 BGB).
     *
     * v3.11.0 (F1035, Paket vertrag, B2, GitHub #55) — nach Verbrauchsart: die 24 Monate nur bei
     * Strom und Gas (Prüfbericht 32; §§ 308, 309 BGB gelten für Sonderabnehmerverträge der
     * Versorger nur, soweit sie von den AVB-Verordnungen abweichen, § 310 Abs. 2 BGB, Prüfbericht 31).
     * Fernwärme darf bis zehn Jahre laufen (§ 32 AVBFernwärmeV, Prüfbericht 30):
     * `term_over_10_years` erst darüber. Wasser und alle anderen Arten: kein Hinweis.
     *
     * @return array{warnings?: list<string>}
     */
    public static function warnings(array $c, string $utility): array
    {
        $w = [];
        $start = (string)($c['start'] ?? '');
        $min = (string)($c['min_term_end'] ?? '');
        $months = match ($utility) { 'strom', 'gas' => 24, 'fernwaerme' => 120, default => null };
        if ($months !== null && $start !== '' && $min !== '' && $min > date('Y-m-d', (int)strtotime("$start +$months months -1 day"))) {
            $w[] = $months === 24 ? 'term_over_24_months' : 'term_over_10_years';
        }
        return $w === [] ? [] : ['warnings' => $w];
    }

    /**
     * v3.11.0 (F1035, Paket vertrag, A3, B1/B3/B4) — die nächste Preiserhöhung eines Vertrags (bis
     * v3.10 `ConsumptionService::upcomingPriceIncrease`): der erste Stichtag nach heute, an dem
     * Arbeits- oder Grundpreis steigt, dazu, was das Recht in Deutschland dazu sagt (eine Stelle für
     * Vertragsstatus, Saldo-Karte, Jahresbericht und die Prüfung A3):
     *
     *   announced_on   Datum des Schreibens am neuen Preis (Entscheidung 8a); fehlt es, kann es ein
     *                  bei Vertragsschluss vereinbarter Stufenpreis sein (Hinweis bleibt, bedingt)
     *   vat_only       nur die Umsatzsteuer ändert sich: beide Preise tragen `vat_pct`, die Sätze
     *                  unterscheiden sich, die Nettopreise nicht (± 0,01) — § 41 Abs. 6 EnWG
     *   termination    `special` (§ 41 Abs. 5 EnWG), `basic` (§ 5 Abs. 3 StromGVV/GasGVV) oder
     *                  `unknown` (Versorgungsart nicht angegeben: beide Normen) — null, wo kein
     *                  Sonderkündigungsrecht gilt: anderes Land, Fernwärme (§ 24 AVBFernwärmeV, B1),
     *                  Wasser, reine Steueränderung (B4)
     *   legal_deadline Tag vor dem Wirksamwerden: bis dahin muss die Kündigung zugehen
     *   deadline       das frühere aus `legal_deadline` und „Kündigung bis …“ laut Schreiben
     *                  (`special_termination_until`, Entscheidung 7c); null ohne `termination`
     *   notes          Sätze des Hinweises (`rights.priceNote.<note>`): special|basic|unknown (+ stepped),
     *                  vat, heat — leer außerhalb Deutschlands und bei Wasser
     *
     * v3.11.0 (Skeptiker H1) — `$cov` (Eintrag aus {@see coverage()} bzw. {@see coverageOf()}): bis zu
     * welchem Tag der Vertrag abrechnet, eine Verlängerung ohne Kündigung eingeschlossen (`to` null =
     * offen). Ohne `$cov` wie bisher bis zum eingetragenen Ende. Bis v3.10 fiel jede Preisänderung
     * nach dem eingetragenen Ende weg, auch wenn der Vertrag mangels Kündigung weiterlief: Kein
     * Sonderkündigungsrecht, keine Erhöhung in der Saldo-Karte.
     *
     * @param array{from:?string,to:?string}|null $cov
     * @return array{from:string, working_price_ct:?array{0:float,1:float}, base_price_eur:?array{0:float,1:float},
     *     announced_on:?string, special_termination_until:?string, vat_only:bool, supply_kind:?string,
     *     termination:?string, legal_deadline:?string, deadline:?string, notes:list<string>}|null
     */
    public function priceIncrease(array $c, string $today, string $utility, string $country, ?array $cov = null): ?array
    {
        // letzter Tag, an dem der Vertrag abrechnet ('' = offen)
        $until = $cov !== null && ($cov['from'] ?? null) !== null ? (string)($cov['to'] ?? '') : (string)($c['end'] ?? '');
        $dates = [];
        foreach (['working_prices', 'base_prices'] as $group) {
            foreach ($c[$group] ?? [] as $e) {
                $d = (string)($e['from'] ?? '');
                if ($d > $today && ($until === '' || $d <= $until)) $dates[$d] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        foreach ($dates as $d) {
            $out = ['from' => $d, 'working_price_ct' => null, 'base_price_eur' => null];
            $announced = null; $until = null; $vatOnly = true;
            foreach (['working_prices' => ['ct_per_kwh', 'working_price_ct'], 'base_prices' => ['eur_per_month', 'base_price_eur']] as $group => [$field, $key]) {
                $old = self::entryOn($c[$group] ?? [], $field, $today);
                $new = self::entryOn($c[$group] ?? [], $field, $d);
                if ($new !== null && (string)$new['from'] === $d) {
                    $announced ??= isset($new['announced_on']) ? (string)$new['announced_on'] : null;
                    $until ??= isset($new['special_termination_until']) ? (string)$new['special_termination_until'] : null;
                }
                if ($old === null || $new === null || (float)$new[$field] <= (float)$old[$field] + 1e-9) continue;
                $out[$key] = [(float)$old[$field], (float)$new[$field]];
                $vatOnly = $vatOnly && self::pureVatChange($old, $new, $field);
            }
            if ($out['working_price_ct'] === null && $out['base_price_eur'] === null) continue;
            $kind = isset($c['supply_kind']) && in_array($c['supply_kind'], self::SUPPLY_KINDS, true) ? (string)$c['supply_kind'] : null;
            // isEnergy() kennt nur Länder mit Regeln (DE): anderswo kein Sonderkündigungsrecht
            $termination = Rights::isEnergy($country, $utility) && !$vatOnly ? ($kind ?? 'unknown') : null;
            $legal = $termination !== null ? Rights::specialTerminationDeadline($d) : null;
            // v3.11.0 (integration) — ein früheres Datum des Versorgers bindet nicht (Prüfbericht 21): Termin
            // nur, solange es nicht vorbei ist, danach wieder der gesetzliche (wie Inbox\PriceChange::assess)
            $deadline = $legal !== null && $until !== null && Dates::isIsoDate($until) && $until >= $today && $until < $legal ? $until : $legal;
            // die Sätze des Hinweises (Katalog `rights.priceNote.<note>`, Rights\ContractChecks::noteParts)
            $notes = match (true) {
                $termination !== null => $announced === null ? [$termination, 'stepped'] : [$termination],
                !Rights::supports($country) => [],
                $vatOnly && Rights::isEnergy($country, $utility) => ['vat'],
                $utility === 'fernwaerme' => ['heat'],
                default => [],
            };
            return $out + ['announced_on' => $announced, 'special_termination_until' => $until, 'vat_only' => $vatOnly,
                'supply_kind' => $kind, 'termination' => $termination, 'legal_deadline' => $legal, 'deadline' => $deadline,
                'notes' => $notes, 'note_params' => ['gvv' => $utility === 'gas' ? 'GasGVV' : 'StromGVV']];
        }
        return null;
    }

    /**
     * B4 — reine Umsatzsteueränderung zwischen zwei Preis-Einträgen (§ 41 Abs. 6 EnWG): beide tragen
     * `vat_pct` (der alte Satz kommt nur vom Eintrag, nie vermutet), die Sätze unterscheiden sich, die
     * Nettopreise nicht (± VAT_NET_TOL in der Einheit des Preises: Rundung gedruckter Bruttopreise).
     * v3.11.0 (integration) — die eine Regel: `Inbox\PriceChange::sameNet` (Preisänderungsschreiben im
     * Posteingang) ruft sie je Bestandteil.
     */
    public static function pureVatChange(array $old, array $new, string $field): bool
    {
        $vo = $old['vat_pct'] ?? null; $vn = $new['vat_pct'] ?? null;
        if (!is_numeric($vo) || !is_numeric($vn) || abs((float)$vo - (float)$vn) <= 1e-9) return false;
        return abs((float)$old[$field] / (1 + (float)$vo / 100) - (float)$new[$field] / (1 + (float)$vn / 100)) <= self::VAT_NET_TOL;
    }

    /** Toleranz beim Vergleich zweier Nettopreise (wie Inbox\Proposals::PRICE_TOL: Rundung, Gleitkomma). */
    public const VAT_NET_TOL = 0.011;

    /** Der Eintrag einer datierten Liste, der an einem Tag gilt (dieselbe Wahl wie valueOnDate()). */
    private static function entryOn(array $entries, string $field, string $date): ?array
    {
        $row = null; $best = '';
        foreach ($entries as $e) {
            $from = (string)($e['from'] ?? '');
            if ($from === '' || $from > $date || !isset($e[$field]) || !is_numeric($e[$field])) continue;
            if ($from >= $best) { $best = $from; $row = $e; }
        }
        return $row;
    }

    /**
     * v3.1.0 (Paket H5, CALC-31) — feste Kosten je Monat an einem Tag: Grundpreis
     * plus (Fernwärme) Anschlussleistung × Leistungspreis / 12 plus Messpreis / 12.
     * Null, wenn nichts davon gepflegt ist. Eine Formel für Saldo, Prognose und
     * Rechnungsprüfung.
     */
    public function fixedPerMonthOn(array $c, string $date): ?float
    {
        $bp = $this->valueOnDate($c['base_prices'] ?? [], 'eur_per_month', $date);
        $cap = !empty($c['capacity_kw']) ? $this->valueOnDate($c['capacity_prices'] ?? [], 'eur_per_kw_year', $date) : null;
        $met = $this->valueOnDate($c['metering_prices'] ?? [], 'eur_per_year', $date);
        $red = $this->valueOnDate($c['grid_reduction'] ?? [], 'eur_per_year', $date);   // v3.1.0 (H6, § 14a Modul 1)
        if ($bp === null && $cap === null && $met === null && $red === null) return null;
        return ($bp ?? 0.0) + ($cap !== null ? (float)$c['capacity_kw'] * $cap / 12 : 0.0) + ($met !== null ? $met / 12 : 0.0)
            - ($red !== null ? $red / 12 : 0.0);
    }

    /** v3.1.0 (CALC-31) — Aufteilung der festen Kosten je Monat (für Tooltip und Rechnungsprüfung). */
    public function fixedPartsOn(array $c, string $date): array
    {
        $cap = !empty($c['capacity_kw']) ? $this->valueOnDate($c['capacity_prices'] ?? [], 'eur_per_kw_year', $date) : null;
        $met = $this->valueOnDate($c['metering_prices'] ?? [], 'eur_per_year', $date);
        return [
            'base'     => $this->valueOnDate($c['base_prices'] ?? [], 'eur_per_month', $date),
            'capacity' => $cap !== null ? (float)$c['capacity_kw'] * $cap / 12 : null,
            'metering' => $met !== null ? $met / 12 : null,
            // v3.1.0 (H6) — § 14a Modul 1, negativ
            'grid_reduction' => ($red = $this->valueOnDate($c['grid_reduction'] ?? [], 'eur_per_year', $date)) !== null ? -$red / 12 : null,
        ];
    }

    public function valueOnDate(array $entries, string $field, string $date): ?float
    {
        $val = null;
        $best = '';
        foreach ($entries as $e) {
            $from = (string)($e['from'] ?? '');
            if ($from === '' || $from > $date || !isset($e[$field]) || !is_numeric($e[$field])) continue;
            if ($from >= $best) {
                $best = $from;
                $val = (float)$e[$field];
            }
        }
        return $val;
    }

    /**
     * v2.9.0 (Review CALC-10) — Der Vertrag, nach dem ein Tag abgerechnet
     * wird. Gilt keiner, läuft der zuletzt beendete weiter, sofern er sich
     * verlängert (`auto_renews`, fehlend = ja): Ohne Kündigung endet ein
     * Energievertrag nicht, er verlängert sich (wie lange und mit welcher Frist,
     * steht im Vertrag; v3.11.0, Skeptiker H3). Bisher kostete Verbrauch nach
     * einem vergessenen Vertragsende 0 €.
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{contract:array<string,mixed>,assumed:bool}|null
     */
    public function resolveForDate(array $contracts, string $date): ?array
    {
        $c = $this->findActiveForDate($contracts, $date);
        if ($c !== null) return ['contract' => $c, 'assumed' => false];
        $last = self::lastEndedBefore($contracts, $date);
        if ($last !== null && self::renews($last)) return ['contract' => $last, 'assumed' => true];
        return null;
    }

    /** v2.9.0 — Verlängert sich der Vertrag ohne Kündigung? Fehlt die Angabe: ja. */
    public static function renews(array $contract): bool
    {
        return ($contract['auto_renews'] ?? null) !== false;
    }

    /**
     * Der zuletzt beendete Vertrag vor einem Tag (für die Fortschreibung).
     *
     * @param array<int,array<string,mixed>> $contracts
     */
    public static function lastEndedBefore(array $contracts, string $date): ?array
    {
        $best = null; $bestEnd = '';
        foreach ($contracts as $c) {
            $end = (string)(self::effectiveEnd($c, $contracts) ?? '');   // v3.7.0 — abgelöst = beendet
            if ($end === '' || $end >= $date || ($c['start'] ?? '9999') > $date) continue;
            if ($best === null || $end > $bestEnd) { $best = $c; $bestEnd = $end; }
        }
        return $best;
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Der Tag, an dem ein Vertrag abgelöst ist: Beginnt
     * während seiner Laufzeit (offen, oder bis einschließlich seines Endes) ein
     * späterer Vertrag **desselben Zählers bzw. derselben Gruppe**, endet er am Tag
     * davor — auch wenn der neue ganz innerhalb des alten liegt (bei echten Daten
     * liefen die Abschläge des alten sonst weiter). Der früheste solche Beginn
     * zählt. Zwei Zähler oder Gruppen (Doppelbelieferung) lösen sich nicht ab;
     * Schattenverträge nie. Ohne einen solchen: null.
     */
    public static function supersededOn(array $c, array $contracts): ?string
    {
        $start = (string)($c['start'] ?? '');
        if ($start === '' || !empty($c['is_shadow'])) return null;
        $end = (string)($c['end'] ?? '');
        $target = fn(array $x): string => (string)(($x['meter_group_id'] ?? null) ?: ($x['meter_id'] ?? ''));
        $best = null;
        foreach ($contracts as $o) {
            if ((string)($o['id'] ?? '') === (string)($c['id'] ?? '') || !empty($o['is_shadow']) || $target($o) !== $target($c)) continue;
            $os = (string)($o['start'] ?? '');
            if ($os === '' || $os <= $start || ($end !== '' && $os > $end)) continue;
            if ($best === null || $os < $best) $best = $os;
        }
        return $best !== null ? date('Y-m-d', (int)strtotime("$best -1 day")) : null;
    }

    /** Wirksames Ende: abgelöst am Vortag des Folgevertrags, sonst das gepflegte Ende (oder null). */
    public static function effectiveEnd(array $c, array $contracts): ?string
    {
        return self::supersededOn($c, $contracts) ?? (!empty($c['end']) ? (string)$c['end'] : null);
    }

    /** v3.4.0 (N1021) — Phasen eines Vertrags für die Anzeige, in der Rangfolge der Listen. */
    public const PHASES = ['future', 'current', 'renewed', 'past', 'shadow'];

    /**
     * v3.4.0 (N1021) — Phase eines Vertrags an einem Tag: eine Quelle für
     * „Kosten → Verträge“ und „Verträge & Abschläge“. Bis v3.3 leitete jede
     * Ansicht den Status selbst ab, und derselbe abgelaufene Vertrag hieß dort
     * „VERGANGEN“, hier „VERLÄNGERT“.
     *
     *  - `shadow`  Angebot zum Vergleich (Schattenvertrag), rechnet nirgends mit
     *  - `future`  beginnt nach dem Tag
     *  - `current` gilt an dem Tag ({@see resolveForDate()} für sein Ziel)
     *  - `renewed` ist beendet und läuft ohne Kündigung weiter
     *  - `past`    beendet oder von einem später begonnenen Vertrag abgelöst
     *
     * Verglichen wird nur mit Verträgen desselben Ziels (Zähler bzw.
     * Zählergruppe); Schattenverträge zählen dabei nicht.
     *
     * @param array<int,array<string,mixed>> $all Verträge der Verbrauchsart
     */
    public function phase(array $contract, array $all, ?string $today = null): string
    {
        if (!empty($contract['is_shadow'])) return 'shadow';
        $today ??= date('Y-m-d');
        if ((string)($contract['start'] ?? '') > $today) return 'future';
        // v3.11.0 (D, #54) — aus coverage(), derselben Quelle wie Vertragsstatus und Abrechnungsjahr
        $on = self::coveredOn($this->coverageOf($contract, $all), $today);
        return $on === null ? 'past' : ($on ? 'renewed' : 'current');
    }

    /**
     * v3.11.0 (Skeptiker H1) — der Eintrag aus {@see coverage()} für einen Vertrag, gerechnet mit den
     * Verträgen desselben Ziels (ohne Angebote), wie phase(). Null für ein Angebot.
     *
     * @param array<int,array<string,mixed>> $all Verträge der Verbrauchsart
     * @return array{from:?string,to:?string,assumed:bool,assumed_from:?string,superseded_on:?string,renewed_until:?string,claimed_by:?array}|null
     */
    public function coverageOf(array $contract, array $all): ?array
    {
        if (!empty($contract['is_shadow'])) return null;
        $target = self::targetKey($contract);
        $id = $contract['id'] ?? null;
        $peers = array_values(array_filter($all, fn($c) => empty($c['is_shadow']) && self::targetKey($c) === $target
            && ($c['id'] ?? null) !== $id));
        $peers[] = $contract;
        return $this->coverage($peers)[(string)$id] ?? null;
    }

    /** Ziel eines Vertrags: Zählergruppe oder Zähler. */
    private static function targetKey(array $c): string
    {
        $gid = (string)($c['meter_group_id'] ?? '');
        return $gid !== '' ? "g:$gid" : 'm:' . (string)($c['meter_id'] ?? '');
    }

    /**
     * v3.4.0 (N1021) — die Werte, die eine Vertragskarte nennt, an einem
     * Stichtag `date`: heute, bei einem beendeten Vertrag sein letzter Tag
     * (so rechnet auch die Verlängerung), bei einem künftigen sein erster.
     * Arbeitspreis in der Untereinheit je Verbrauchseinheit (Wasser:
     * Trinkwasser je m³), Grundpreis je Monat (Wasser: Trinkwasser), Abschlag
     * nach dem effektiven Plan, also mit Sonderzahlungen, die ihn ändern.
     * Fehlt ein Wert, steht null.
     *
     * v3.11.0 (Skeptiker H1) — `$renewed`: Der Vertrag läuft ohne Kündigung weiter; dann die Werte
     * von heute (eine Preisänderung nach dem Ende gilt, wie in Saldo und Prognose).
     *
     * @return array{date:string,working_price_ct:?float,base_price_eur:?float,advance_eur:?float}
     */
    public function valuesOn(array $c, ?string $today = null, bool $renewed = false): array
    {
        $date = $today ?? date('Y-m-d');
        $start = (string)($c['start'] ?? '');
        $end = (string)($c['end'] ?? '');
        if ($start !== '' && $date < $start) $date = $start;
        if ($end !== '' && $date > $end && !$renewed) $date = $end;
        $water = is_array($c['trinkwasser'] ?? null);
        $base = $this->valueOnDate((array)($water ? ($c['trinkwasser']['base_prices'] ?? []) : ($c['base_prices'] ?? [])), 'eur_per_month', $date);
        // v3.9.0 (F1034) — Wasser: der Grundpreis des Schmutzwassers kommt dazu (fehlt er, wie bisher)
        $swBase = $water ? $this->valueOnDate((array)($c['schmutzwasser']['base_prices'] ?? []), 'eur_per_month', $date) : null;
        if ($swBase !== null) $base = ($base ?? 0.0) + $swBase;
        $plan = $this->effectiveAdvanceSchedule($c);
        $every = self::everyMonths(self::planRowOn($plan, $date));
        return [
            'date'             => $date,
            'working_price_ct' => $water ? $this->valueOnDate((array)($c['trinkwasser']['working_prices'] ?? []), 'ct_per_m3', $date)
                                         : $this->valueOnDate((array)($c['working_prices'] ?? []), 'ct_per_kwh', $date),
            'base_price_eur'   => $base,
            'advance_eur'      => $this->valueOnDate($plan, 'amount_eur', $date),
        ] + ($every > 1 ? ['advance_every_months' => $every] : []);   // v3.9.0 (F1034) — Rhythmus, nur wenn nicht monatlich (additiv)
    }

    /**
     * v3.4.0 (N1021) — Verträge für die Antwort: additiv `phase` und
     * `values_on`. Gespeichert wird beides nicht (update() übernimmt nur
     * bekannte Felder).
     *
     * @param array<int,array<string,mixed>> $list Verträge der Antwort
     * @param array<int,array<string,mixed>> $all  alle Verträge der Verbrauchsart
     */
    public function withPhase(array $list, array $all, ?string $today = null): array
    {
        $cov = $this->coverageByTarget($all);   // v3.11.0 (D, #54)
        return array_map(fn($c) => array_merge($c, [
            'phase'     => $phase = $this->phase($c, $all, $today),
            'values_on' => $this->valuesOn($c, $today, $phase === 'renewed'),
            // v3.11.0 (D, #54) — was der Vertrag abrechnet: „abgelöst am“, „verlängert bis“ (additiv)
            'coverage'  => $cov[(string)($c['id'] ?? '')] ?? null,
        ]), $list);
    }

    // ── v3.11.0 (D, GitHub #54) — eine Regel für Liste und Abrechnungsjahr ──────

    /**
     * Welchen Zeitraum jeder Vertrag einer Liste abrechnet, nach genau der Regel von
     * {@see resolveForDate()}: Der spätere Beginn gewinnt, ein abgelöster Vertrag endet am Vortag
     * des Folgevertrags, ein beendeter läuft ohne Kündigung weiter (Entscheidung 11a, unverändert).
     * Eine Quelle für die Phase der Vertragsliste („abgelöst am“, „verlängert bis“), den
     * Vertragsstatus und das Abrechnungsjahr; bis v3.10 leitete jede Stelle ihren Teil selbst ab, und
     * niemand zeigte, dass ein späterer Vertrag Jahre über sein Ende hinaus fortgeschrieben wurde.
     *
     * Je Vertrags-ID:
     *  - `from`, `to`       erster und letzter Tag, an dem er rechnet (`to` null = offen; `from`
     *                       null = rechnet nie, etwa zwei Verträge mit demselben Beginn)
     *  - `assumed`          rechnet über sein wirksames Ende hinaus weiter (Fortschreibung)
     *  - `assumed_from`     erster Tag der Fortschreibung
     *  - `superseded_on`    {@see supersededOn()}: letzter Tag vor dem Folgevertrag
     *  - `renewed_until`    letzter Tag der Fortschreibung (null = offen oder keine)
     *  - `claimed_by`       `{contract_id, until}`: Die Fortschreibung liegt in der eingetragenen
     *                       Laufzeit eines anderen Vertrags desselben Ziels — im Abrechnungsjahr
     *                       „angenommen“; sonst null
     *
     * Die Liste gilt, wie der Aufrufer rechnet (ein Ziel oder Zähler mit Gruppe). Die Antwort von
     * resolveForDate() wechselt nur am Beginn und am Tag nach dem wirksamen Ende eines Vertrags,
     * deshalb genügt sie an diesen Tagen (ein Test vergleicht Tag für Tag).
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array<string,array{from:?string,to:?string,assumed:bool,assumed_from:?string,superseded_on:?string,renewed_until:?string,claimed_by:?array}>
     */
    public function coverage(array $contracts): array
    {
        $days = [];
        foreach ($contracts as $c) {
            $s = (string)($c['start'] ?? '');
            if (Dates::isIsoDate($s)) $days[$s] = true;
            $e = self::effectiveEnd($c, $contracts);
            if ($e !== null && Dates::isIsoDate($e)) $days[self::shiftDay($e, 1)] = true;
        }
        $days = array_map('strval', array_keys($days));
        sort($days);
        $out = [];
        $byId = [];
        foreach ($contracts as $c) {
            $id = (string)($c['id'] ?? '');
            $byId[$id] = $c;
            $out[$id] = ['from' => null, 'to' => null, 'assumed' => false, 'assumed_from' => null,
                         'superseded_on' => self::supersededOn($c, $contracts), 'renewed_until' => null, 'claimed_by' => null];
        }
        foreach ($days as $i => $d) {
            $r = $this->resolveForDate($contracts, $d);
            $id = $r !== null ? (string)($r['contract']['id'] ?? '') : null;
            if ($id === null || !isset($out[$id])) continue;
            $out[$id]['from'] ??= $d;
            $out[$id]['to'] = isset($days[$i + 1]) ? self::shiftDay($days[$i + 1], -1) : null;
            if ($r['assumed']) $out[$id]['assumed_from'] ??= $d;
        }
        foreach ($out as $id => &$o) {
            if ($o['assumed_from'] === null) continue;
            $o['assumed'] = true;
            $o['renewed_until'] = $o['to'];
            $o['claimed_by'] = self::claimOn($byId[$id], $contracts, $o['assumed_from'], $o['to']);
        }
        unset($o);
        return $out;
    }

    /**
     * coverage() je Ziel (Zähler bzw. Gruppe), ohne Angebote — für Listen über mehrere Zähler
     * (Vertragsliste der Art, Abrechnungsjahr einer Gruppe oder der ganzen Art).
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array<string,array<string,mixed>>
     */
    public function coverageByTarget(array $contracts): array
    {
        $byTarget = [];
        foreach ($contracts as $c) if (empty($c['is_shadow'])) $byTarget[self::targetKey($c)][] = $c;
        $out = [];
        foreach ($byTarget as $list) $out += $this->coverage($list);
        return $out;
    }

    /** Rechnet der Vertrag (Eintrag aus coverage()) an dem Tag? null = nein, false = nach Eintrag, true = fortgeschrieben. */
    public static function coveredOn(?array $cov, string $day): ?bool
    {
        if ($cov === null || $cov['from'] === null || $day < $cov['from'] || ($cov['to'] !== null && $day > $cov['to'])) return null;
        return $cov['assumed_from'] !== null && $day >= $cov['assumed_from'];
    }

    /**
     * Ein anderer Vertrag desselben Ziels, dessen eingetragenes Ende in die Fortschreibung
     * [$from, $to] reicht und der vor ihr begann (das späteste Ende). Ein offenes Ende beansprucht
     * nichts: Wer beim Wechsel kein Ende nachträgt, meint den Folgevertrag.
     *
     * @return array{contract_id:string,until:string}|null
     */
    private static function claimOn(array $c, array $contracts, string $from, ?string $to): ?array
    {
        $best = null; $bestEnd = '';
        foreach ($contracts as $o) {
            if ((string)($o['id'] ?? '') === (string)($c['id'] ?? '') || !empty($o['is_shadow']) || self::targetKey($o) !== self::targetKey($c)) continue;
            $end = (string)($o['end'] ?? '');
            if (!Dates::isIsoDate($end) || $end < $from || (string)($o['start'] ?? '9999') >= $from || $end <= $bestEnd) continue;
            $best = ['contract_id' => (string)($o['id'] ?? ''), 'until' => $to !== null && $to < $end ? $to : $end];
            $bestEnd = $end;
        }
        return $best;
    }

    /** Ein Kalendertag vor oder zurück (UTC, ohne Uhrzeit). */
    private static function shiftDay(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->modify(sprintf('%+d day', $days))->format('Y-m-d');
    }

    // ── v3.11.0 (D, GitHub #54) — Verträge widersprechen sich ──────────────────

    /**
     * Bis so viele Tage dürfen sich zwei Verträge desselben Ziels laut Eintrag überschneiden, ohne
     * dass die App fragt: die übliche Unschärfe beim Wechsel (Ende zum Monatsende, Lieferbeginn ein
     * paar Tage später oder früher).
     */
    public const OVERLAP_TOLERANCE_DAYS = 31;
    /** Arbeitspreis außerhalb dieses Bands um den Median der anderen Verträge desselben Ziels. */
    public const PRICE_BAND = [0.4, 2.5];
    /** So viele andere Verträge mit Arbeitspreis braucht der Median. */
    public const PRICE_PEERS_MIN = 2;
    /** Abschläge mindestens so viel mal die Kosten eines abgeschlossenen Abrechnungsjahres. */
    public const ADVANCE_COST_FACTOR = 2.0;
    /** Arten eines Widerspruchs (`kind`, Empfehlung `r_contract_conflict`). */
    public const CONFLICT_KINDS = ['overlap', 'nested', 'foreign_tariff', 'advance_vs_cost'];

    /**
     * Verträge einer Verbrauchsart, die sich laut Eintrag widersprechen — je Ziel (Zähler bzw.
     * Gruppe), ohne Angebote. Rein; gerechnet wird weiter nach coverage() (Entscheidung 11a), die
     * App fragt nur nach.
     *
     *  - `overlap`  B beginnt nach A und vor dem eingetragenen Ende von A, läuft mindestens bis
     *               dahin, und die Überschneidung ist länger als OVERLAP_TOLERANCE_DAYS. Die App
     *               rechnet ab dem Beginn von B mit B.
     *  - `nested`   B liegt ganz in A und endet mehr als OVERLAP_TOLERANCE_DAYS vor A. Die App
     *               rechnet danach mit B weiter (gekündigt: mit keinem), nicht wieder mit A.
     *  - `foreign_tariff`  B trägt wohl den Tarif einer anderen Verbrauchsart — sprachunabhängige
     *               Signale, nie der Tarifname allein: `copy` (Versorger, Tarifname und Arbeitspreis
     *               wie ein Vertrag einer anderen Art), `document` (aus einem Dokument übernommen, das
     *               der Posteingang einer anderen Art zuordnet), `price` (Arbeitspreis außerhalb
     *               PRICE_BAND um den Median von mindestens PRICE_PEERS_MIN anderen Verträgen).
     *               `copy` und `price` zählen nur zusammen mit einer Überschneidung oder miteinander:
     *               Allein meldete der Preis jeden Krisentarif 2022, die Kopie auch den echten Vertrag.
     *
     * Ein offenes Ende widerspricht nichts: Der Folgevertrag löst den alten ab, wie beim Wechsel ohne
     * nachgetragenes Ende üblich. Je Vertrag A zählt nur der erste spätere Beginn (er löst A ab).
     * `fix_end`: das Ende, mit dem der Eintrag von A zur Rechnung passt (Tag vor dem Beginn von B).
     *
     * @param array<int,array<string,mixed>> $contracts Verträge einer Verbrauchsart
     * @param array{utility?:string,others?:array<string,list<array>>,documents?:array<string,string>} $ctx
     *        `others`: Verträge der anderen Arten je Art; `documents`: Anhang-ID → erkannte Art
     * @return list<array<string,mixed>>
     */
    public static function conflicts(array $contracts, array $ctx = []): array
    {
        $byTarget = [];
        foreach ($contracts as $c) {
            if (empty($c['is_shadow']) && Dates::isIsoDate((string)($c['start'] ?? ''))) $byTarget[self::targetKey($c)][] = $c;
        }
        $out = [];
        foreach ($byTarget as $list) {
            usort($list, fn($a, $b) => [(string)$a['start'], (string)($a['id'] ?? '')] <=> [(string)$b['start'], (string)($b['id'] ?? '')]);
            $involved = [];
            foreach ($list as $a) {
                $aEnd = (string)($a['end'] ?? '');
                if (!Dates::isIsoDate($aEnd)) continue;
                $b = null;
                foreach ($list as $x) {
                    $xs = (string)$x['start'];
                    if ($xs > (string)$a['start'] && $xs <= $aEnd && ($b === null || $xs < (string)$b['start'])) $b = $x;
                }
                if ($b === null) continue;
                $bEnd = (string)($b['end'] ?? '');
                $nested = Dates::isIsoDate($bEnd) && $bEnd < $aEnd;
                $days = $nested ? self::daysBetween($bEnd, $aEnd) : self::daysBetween((string)$b['start'], $aEnd) + 1;
                if ($days <= self::OVERLAP_TOLERANCE_DAYS) continue;
                $out[] = self::conflictRow($nested ? 'nested' : 'overlap', $b, (string)($a['id'] ?? ''), [
                    'from' => (string)$b['start'], 'until' => $nested ? $bEnd : $aEnd, 'days' => $days,
                    'other_end' => $aEnd, 'fix_end' => self::shiftDay((string)$b['start'], -1)]);
                $involved[(string)($a['id'] ?? '')] = $involved[(string)($b['id'] ?? '')] = true;
            }
            foreach ($list as $b) {
                $f = self::foreignTariff($b, $list, $ctx, isset($involved[(string)($b['id'] ?? '')]));
                if ($f !== null) $out[] = self::conflictRow('foreign_tariff', $b, null, $f);
            }
        }
        return $out;
    }

    /**
     * conflicts() für eine Verbrauchsart aus den Daten: dazu die Verträge der anderen Arten und die
     * erkannte Art der Dokumente im Posteingang (Bestätigung oder Korrektur des Nutzers geht vor).
     *
     * @return list<array<string,mixed>>
     */
    public function conflictsFor(string $utility): array
    {
        $others = [];
        foreach (Utilities::keys() as $u) {
            if ($u === $utility || !Utilities::hasContracts($u)) continue;
            $rows = $this->store->read("$u/contracts.json", []);
            $others[$u] = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }
        $documents = [];
        $inbox = $this->store->exists('inbox.json') ? $this->store->read('inbox.json', []) : [];
        foreach (is_array($inbox) ? $inbox : [] as $e) {
            if (!is_array($e) || empty($e['attachment_id'])) continue;
            $v = $e['edits']['utility'] ?? ($e['extraction']['fields']['utility'] ?? null);
            if (is_array($v)) $v = $v['value'] ?? null;
            if (is_string($v) && Utilities::exists($v)) $documents[(string)$e['attachment_id']] = $v;
        }
        return self::conflicts($this->list($utility), ['utility' => $utility, 'others' => $others, 'documents' => $documents]);
    }

    /**
     * Antwort beim Speichern: Widerspricht der Vertrag einem anderen, steht `contract_conflict` in
     * `warnings` und die Zeilen in `conflicts` (nicht gespeichert, nicht blockierend — Lektion 45).
     *
     * @param array<string,mixed> $saved
     * @return array<string,mixed>
     */
    public function withConflictWarnings(string $utility, array $saved): array
    {
        $id = (string)($saved['id'] ?? '');
        $rows = array_values(array_filter($this->conflictsFor($utility), fn($r) => $r['contract_id'] === $id || $r['other_id'] === $id));
        if ($rows === []) return $saved;
        $saved['warnings'] = array_values(array_unique([...(array)($saved['warnings'] ?? []), 'contract_conflict']));
        $saved['conflicts'] = $rows;
        return $saved;
    }

    /**
     * Vertragsliste der API: je Vertrag additiv `conflicts` — die Zeilen aus conflictsFor(), an denen
     * er beteiligt ist, mit `recommendation_id` (Empfehlung `r_contract_conflict`) und `dismissed`
     * (ausgeblendet, „Beides stimmt“). Die Karte zeigt den Hinweis nur, solange er nicht ausgeblendet ist.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    public function withConflicts(string $utility, array $list): array
    {
        $rows = $this->conflictsFor($utility);
        $dismissed = $this->store->read('recommendations_dismissed.json', []);
        $today = date('Y-m-d');
        $byId = [];
        foreach ($this->list($utility) as $c) $byId[(string)($c['id'] ?? '')] = $c;
        foreach ($rows as &$r) {
            $r['recommendation_id'] = RecommendationService::ruleId('r_contract_conflict', [$utility, $r['kind'], $r['contract_id'], (string)($r['other_id'] ?? '')]);
            $until = is_array($dismissed) ? ($dismissed[$r['recommendation_id']] ?? null) : null;
            $r['dismissed'] = is_string($until) && $until >= $today;
            $r += $this->conflictText($utility, $r, $byId);
        }
        unset($r);
        return array_map(function ($c) use ($rows) {
            $id = (string)($c['id'] ?? '');
            $c['conflicts'] = array_values(array_filter($rows, fn($r) => $r['contract_id'] === $id || $r['other_id'] === $id));
            return $c;
        }, $list);
    }

    /**
     * Titel und Text eines Widerspruchs in der Sprache der Anfrage — eine Stelle für Empfehlung und
     * Vertragskarte. Namen „Versorger · Tarif“, Tage in der Schreibweise der Sprache.
     *
     * @param array<string,mixed> $r Zeile aus conflicts()
     * @param array<string,array<string,mixed>> $byId Verträge der Art je ID
     * @return array{title:string, detail:string}
     */
    public function conflictText(string $utility, array $r, array $byId): array
    {
        $k = 'recommendations.engine.rContractConflict.';
        $name = fn(?array $c): string => $c === null ? '—' : (implode(' · ', array_filter([(string)($c['provider'] ?? ''), (string)($c['tariff_name'] ?? '')], fn($s) => $s !== '')) ?: '—');
        $label = $this->i18n->utilityLabel($utility);
        $b = $byId[(string)$r['contract_id']] ?? null;
        $a = $r['other_id'] !== null ? ($byId[(string)$r['other_id']] ?? null) : null;
        if ($r['kind'] === 'foreign_tariff') {
            $parts = [];
            foreach ($r['signals'] as $s) {
                $parts[] = $this->i18n->t($k . 'foreign' . ucfirst((string)$s), [
                    'other' => $r['other_utility'] !== null ? $this->i18n->utilityLabel((string)$r['other_utility']) : '—',
                    'factor' => $this->i18n->number((float)($r['factor'] ?? 0), 1)]);
            }
            return ['title' => $this->i18n->t($k . 'foreignTitle', ['label' => $label, 'b' => $name($b)]),
                    'detail' => implode(' ', [...$parts, $this->i18n->t($k . 'foreignAsk')])];
        }
        $p = ['a' => $name($a), 'b' => $name($b), 'aEnd' => $this->i18n->date((string)$r['other_end']),
              'bStart' => $this->i18n->date((string)$r['from']), 'bEnd' => $this->i18n->date((string)$r['until'])];
        $key = $r['kind'] === 'overlap' ? 'overlap' : ($b !== null && !self::renews($b) ? 'nestedCancelled' : 'nested');
        return ['title' => $this->i18n->t($k . 'title', ['label' => $label]), 'detail' => $this->i18n->t($k . $key, $p)];
    }

    /**
     * Signale für `foreign_tariff` (siehe conflicts()) oder null. Das Dokument zählt allein; Kopie und
     * Preis nur zusammen mit einer Überschneidung oder miteinander — eine Kopie allein sagt nicht, welcher
     * der beiden Verträge der richtige ist (der echte Gasvertrag bliebe sonst als „Tarif von Strom?“
     * stehen), ein Preis allein wäre jeder Krisentarif.
     */
    private static function foreignTariff(array $b, array $peers, array $ctx, bool $involved): ?array
    {
        $copyOf = null;
        $nameOf = null;
        $p = self::startPrice($b);
        $pair = self::tariffKey($b);
        if ($pair !== null && $p !== null) {
            foreach ((array)($ctx['others'] ?? []) as $u => $rows) {
                foreach ((array)$rows as $x) {
                    if (!is_array($x) || !empty($x['is_shadow']) || self::tariffKey($x) !== $pair) continue;
                    $xp = self::startPrice($x);
                    if ($xp !== null && abs($xp - $p) < 0.0005) { $copyOf = (string)$u; break 2; }
                    // v3.11.0 (Skeptiker N13) — derselbe Versorger und Tarif einer anderen Art mit einem Preis in
                    // deren Größenordnung (PRICE_BAND um ihren Preis): ein fremder Tarif mit geändertem Preis.
                    // Derselbe Tarifname für Strom und Gas eines Versorgers hat Preise weit auseinander.
                    if ($xp !== null && $xp > 0 && $p / $xp >= self::PRICE_BAND[0] && $p / $xp <= self::PRICE_BAND[1]) $nameOf ??= (string)$u;
                }
            }
        }
        if ($copyOf !== null) $nameOf = null;
        $docOf = null;
        $own = (string)($ctx['utility'] ?? '');
        foreach ((array)($b['attachment_ids'] ?? []) as $att) {
            $du = $ctx['documents'][(string)$att] ?? null;
            if ($own !== '' && is_string($du) && $du !== $own) { $docOf = $du; break; }
        }
        $factor = null;
        if ($p !== null) {
            $prices = [];
            foreach ($peers as $o) {
                if ((string)($o['id'] ?? '') !== (string)($b['id'] ?? '') && ($op = self::startPrice($o)) !== null) $prices[] = $op;
            }
            if (count($prices) >= self::PRICE_PEERS_MIN) {
                sort($prices);
                $n = count($prices);
                $median = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2;
                $f = $p / $median;
                if ($f < self::PRICE_BAND[0] || $f > self::PRICE_BAND[1]) $factor = round($f, 2);
            }
        }
        $pairs = $involved || (($copyOf ?? $nameOf) !== null && $factor !== null);
        $signals = array_values(array_filter([
            $copyOf !== null && $pairs ? 'copy' : null,
            $nameOf !== null && $pairs ? 'name' : null,
            $docOf !== null ? 'document' : null,
            $factor !== null && $pairs ? 'price' : null,
        ]));
        return $signals === [] ? null : ['signals' => $signals, 'other_utility' => $docOf ?? ($pairs ? $copyOf ?? $nameOf : null),
                                          'factor' => $factor, 'price_ct' => $p !== null ? round($p, 4) : null];
    }

    /** Versorger und Tarifname, klein und ohne doppelte Leerzeichen; null ohne Tarifnamen. */
    private static function tariffKey(array $c): ?string
    {
        $norm = fn($v): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)$v))) ?? '';
        $tariff = $norm($c['tariff_name'] ?? '');
        return $tariff === '' ? null : $norm($c['provider'] ?? '') . '|' . $tariff;
    }

    /** Arbeitspreis am Beginn (sonst der früheste) in ct je Einheit, null ohne einen über 0. */
    private static function startPrice(array $c): ?float
    {
        $start = (string)($c['start'] ?? '');
        $at = null; $atFrom = ''; $first = null; $firstFrom = '';
        foreach ((array)($c['working_prices'] ?? []) as $e) {
            $from = (string)($e['from'] ?? '');
            $v = $e['ct_per_kwh'] ?? null;
            if ($from === '' || !is_numeric($v)) continue;
            if ($from <= $start && $from >= $atFrom) { $at = (float)$v; $atFrom = $from; }
            if ($first === null || $from < $firstFrom) { $first = (float)$v; $firstFrom = $from; }
        }
        $p = $at ?? $first;
        return $p !== null && $p > 0 ? $p : null;
    }

    /** Eine Zeile aus conflicts(): `contract_id` = der Vertrag, nach dem die App rechnet bzw. der fremde Tarif. */
    private static function conflictRow(string $kind, array $c, ?string $otherId, array $extra): array
    {
        return ['kind' => $kind, 'contract_id' => (string)($c['id'] ?? ''), 'other_id' => $otherId,
                'meter_id' => ($c['meter_id'] ?? null) ?: null, 'meter_group_id' => ($c['meter_group_id'] ?? null) ?: null] + $extra;
    }

    /**
     * v2.9.0 (Review CALC-10) — Zerlegt [von, bis) in Abschnitte, in denen
     * Vertrag und Preise gleich bleiben: geschnitten an Beginn und Ende jedes
     * Vertrags und an jedem Stichtag seiner Arbeitspreise, Grundpreise und
     * Abschläge (effektiver Plan). Wie die Rechnung, die an denselben Tagen
     * eine neue Zeile beginnt. `assumed` markiert Abschnitte, in denen ein
     * beendeter Vertrag weiterläuft; seine Preise gelten dann wie an seinem
     * letzten Tag, bis ein Eintrag danach sie ändert ({@see priceDate()}).
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return list<array{from:string,to:string,days:int,contract:?array,assumed:bool}>
     */
    public function segmentsBetween(array $contracts, string $from, string $to): array
    {
        return $this->segmentsWithin($contracts, $this->cutDates($contracts), $from, $to);
    }

    /**
     * v3.7.0 (Qualität) — Alle Schnitttage einer Vertragsliste, sortiert:
     * Beginn, Tag nach dem Ende, Stichtage aller Preislisten und des
     * effektiven Abschlagsplans.
     *
     * Herausgelöst aus segmentsBetween(): Die Monatsrechnung ruft es je
     * Ableseintervall auf (bei täglichen Ständen tausende Male je Zähler) und
     * baute die Liste jedes Mal neu — bei zehn Jahren Tageswerten mit einem
     * Vertrag je Jahr der größte Posten der Antwortzeit
     * (docs/entwicklung/messung-grosse-daten.md). Wer viele Abschnitte derselben
     * Liste braucht, holt die Tage einmal und ruft segmentsWithin().
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return list<string>
     */
    public function cutDates(array $contracts): array
    {
        $cuts = [];
        foreach ($contracts as $c) {
            $dates = [(string)($c['start'] ?? '')];
            if (!empty($c['end'])) $dates[] = date('Y-m-d', strtotime($c['end'] . ' +1 day'));
            // v3.1.0 (CALC-31) — dazu Leistungs- und Messpreis der Fernwärme
            foreach (['working_prices', 'base_prices', 'capacity_prices', 'metering_prices', 'grid_reduction'] as $group) {
                foreach ($c[$group] ?? [] as $e) $dates[] = (string)($e['from'] ?? '');
            }
            // v3.1.0 (H6) — Arbeitspreise je Mitglied eines Gruppenvertrags
            foreach ((array)($c['working_prices_by_meter'] ?? []) as $list) {
                foreach ((array)$list as $e) $dates[] = (string)($e['from'] ?? '');
            }
            foreach ($this->effectiveAdvanceSchedule($c) as $e) $dates[] = $e['from'];
            foreach ($dates as $d) $cuts[$d] = true;
        }
        $cuts = array_map('strval', array_keys($cuts));
        sort($cuts);
        return $cuts;
    }

    /**
     * segmentsBetween() mit vorab geholten Schnitttagen ({@see cutDates()} derselben Liste).
     *
     * @param array<int,array<string,mixed>> $contracts
     * @param list<string> $allCuts
     * @return list<array{from:string,to:string,days:int,contract:?array,assumed:bool}>
     */
    public function segmentsWithin(array $contracts, array $allCuts, string $from, string $to): array
    {
        if ($to <= $from) return [];
        $cuts = [];
        foreach ($allCuts as $d) {
            if ($d >= $to) break;
            if ($d > $from) $cuts[] = $d;
        }
        $bounds = array_merge([$from], $cuts, [$to]);
        $out = [];
        for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
            [$a, $b] = [$bounds[$i], $bounds[$i + 1]];
            $r = $this->resolveForDate($contracts, $a);
            $out[] = [
                'from'     => $a,
                'to'       => $b,
                'days'     => self::daysBetween($a, $b),
                'contract' => $r['contract'] ?? null,
                'assumed'  => $r['assumed'] ?? false,
            ];
        }
        return $out;
    }

    // ── v3.1.0 (H6, #17) — Gruppenvertrag ──────────────────────────────

    /** Gruppen-ID aus der Eingabe (`meter_group_id`), sonst null. */
    private static function groupTarget(array $input): ?string
    {
        $g = $input['meter_group_id'] ?? null;
        return is_string($g) && $g !== '' ? $g : null;
    }

    /** Gruppe derselben Art mit Mitgliedern; nur Arten mit Abschlagsverträgen (Gas, Strom, Fernwärme). */
    private function assertGroupTarget(string $utility, string $groupId): void
    {
        if (!Utilities::hasAdvancePaymentContracts($utility) || $this->meters->getGroup($utility, $groupId) === null
            || $this->meters->groupMembers($utility, $groupId) === []) {
            throw new LocalizedException('errors.contract.targetInvalid', ['id' => $groupId], 'contract target');
        }
    }

    /** Preise je Mitglied nur für Zähler der Gruppe. */
    private function assertGroupPrices(string $utility, array $c): void
    {
        $ids = array_column($this->meters->groupMembers($utility, (string)$c['meter_group_id']), 'id');
        foreach (array_keys((array)($c['working_prices_by_meter'] ?? [])) as $mid) {
            if (!in_array((string)$mid, $ids, true)) {
                throw new LocalizedException('errors.contract.targetInvalid', ['id' => (string)$mid], 'price for non-member');
            }
        }
    }

    /**
     * Ein Mitglied hat im Zeitraum eines Gruppenvertrags keinen eigenen echten
     * Vertrag (und umgekehrt) — sonst zählten Grundpreis und Verbrauch doppelt.
     * Schattenverträge zählen nicht.
     */
    private function assertNoGroupOverlap(string $utility, array $c, array $all): void
    {
        if (!empty($c['is_shadow']) || $utility === 'wasser') return;
        $overlaps = fn(array $a, array $b): bool => (string)($a['start'] ?? '') <= (string)(($b['end'] ?? '') ?: '9999-12-31')
            && (string)($b['start'] ?? '') <= (string)(($a['end'] ?? '') ?: '9999-12-31');
        $clash = function (array $o) use ($c, $overlaps): bool {
            return is_array($o) && ($o['id'] ?? null) !== ($c['id'] ?? null) && empty($o['is_shadow']) && $overlaps($c, $o);
        };
        if (!empty($c['meter_group_id'])) {
            $members = array_column($this->meters->groupMembers($utility, (string)$c['meter_group_id']), 'id');
            foreach ($all as $o) {
                if (is_array($o) && in_array($o['meter_id'] ?? null, $members, true) && $clash($o)) {
                    throw new LocalizedException('errors.contract.groupMemberOverlap', ['start' => (string)($o['start'] ?? '')], 'group member overlap');
                }
            }
            return;
        }
        $meter = $this->meters->get($utility, (string)($c['meter_id'] ?? ''));
        $gid = (string)($meter['meter_group_id'] ?? '');
        if ($gid === '') return;
        foreach ($all as $o) {
            if (is_array($o) && ($o['meter_group_id'] ?? null) === $gid && $clash($o)) {
                throw new LocalizedException('errors.contract.groupMemberOverlap', ['start' => (string)($o['start'] ?? '')], 'group member overlap');
            }
        }
    }

    /**
     * v3.3.0 (F1021) — § 14a Modul 3: {standard_ct, windows: [{quarters, from,
     * to, ct}]}. Zeiten „HH:MM“ (bis 24:00), from < to — ein Fenster über
     * Mitternacht sind zwei. Leer (ohne Standardpreis und Fenster) = null.
     *
     * @return array<string,mixed>|null
     */
    public static function normalizeGridTou(mixed $in): ?array
    {
        if ($in === null || $in === '' || $in === []) return null;
        if (!is_array($in)) throw new LocalizedException('errors.contract.gridTouInvalid', ['field' => 'grid_tou'], 'grid_tou');
        $ct = function (mixed $v, string $field): float {
            $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_numeric($v) ? (float)$v : null);
            if ($n === null || $n < 0 || $n > 100) throw new LocalizedException('errors.contract.gridTouInvalid', ['field' => $field], "grid_tou $field");
            return round($n, 4);
        };
        $windows = [];
        foreach (array_values((array)($in['windows'] ?? [])) as $i => $w) {
            if (!is_array($w)) continue;
            $q = array_values(array_unique(array_map('intval', array_filter((array)($w['quarters'] ?? []), 'is_numeric'))));
            sort($q);
            $from = (string)($w['from'] ?? ''); $to = (string)($w['to'] ?? '');
            $ok = fn(string $t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $t);
            if ($q === [] || array_diff($q, [1, 2, 3, 4]) !== [] || !$ok($from) || !$ok($to)
                || FlexibilityService::minutes($from) >= FlexibilityService::minutes($to)) {
                throw new LocalizedException('errors.contract.gridTouInvalid', ['field' => "windows[$i]"], "grid_tou window $i");
            }
            $windows[] = ['quarters' => $q, 'from' => $from, 'to' => $to, 'ct' => $ct($w['ct'] ?? null, "windows[$i].ct")];
        }
        if (count($windows) > 24) throw new LocalizedException('errors.contract.gridTouInvalid', ['field' => 'windows'], 'grid_tou too many');
        if ($windows === [] && (($in['standard_ct'] ?? '') === '' || $in['standard_ct'] === null)) return null;
        $out = ['standard_ct' => $ct($in['standard_ct'] ?? null, 'standard_ct'), 'windows' => $windows];
        // v3.7.0 — Schalter „ohne USt“: die Beträge stehen netto am Vertrag; gerechnet
        // wird brutto (FlexibilityService::gridTouByMonth). Fehlt er, gelten sie wie
        // eingetragen (brutto) — so bleiben gespeicherte Verträge, wie sie sind.
        if (filter_var($in['net'] ?? false, FILTER_VALIDATE_BOOLEAN)) $out['net'] = true;
        return $out;
    }

    /**
     * v3.1.0 (H7, MKT-16) — Gutschriften: {from, to (inklusive), amount_eur, kwh?,
     * attachment_id?}. Ganz leere Zeilen fallen weg.
     */
    private function normalizeRevenueStatements(array $list): array
    {
        $out = [];
        $label = $this->i18n->t('contracts.revenue.title');
        foreach (array_values($list) as $i => $s) {
            if (!is_array($s)) continue;
            $from = trim((string)($s['from'] ?? '')); $to = trim((string)($s['to'] ?? ''));
            $amt = $s['amount_eur'] ?? null;
            if ($from === '' && $to === '' && ($amt === null || $amt === '')) continue;
            $this->assertDate($from, $label . ' #' . ($i + 1));
            $this->assertDate($to, $label . ' #' . ($i + 1));
            if ($to < $from) throw new \InvalidArgumentException($this->i18n->t('errors.contract.endBeforeStart'));
            $e = ['from' => $from, 'to' => $to, 'amount_eur' => round($this->parseAmount(is_string($amt) ? (ReadingImportService::parseNum($amt) ?? $amt) : $amt, $label, $i + 1), 2)];
            if (isset($s['kwh']) && is_numeric($s['kwh'])) $e['kwh'] = round((float)$s['kwh'], 1);
            if (!empty($s['attachment_id'])) $e['attachment_id'] = (string)$s['attachment_id'];
            $out[] = $e;
        }
        usort($out, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $out;
    }

    /** v3.1.0 (H6, MKT-12) — dynamisch nur als Schattenvertrag für Strom (Dynamik-Check). */
    private function assertPriceModel(string $utility, array $c): void
    {
        if (($c['price_model'] ?? null) === 'dynamic' && ($utility !== 'strom' || empty($c['is_shadow']))) {
            throw new LocalizedException('errors.contract.dynamicShadowOnly', [], 'dynamic contract');
        }
    }

    /**
     * v3.1.0 (H6, MKT-12) — Monatspreise aus einer Datei: `monat;ct_kwh[;grundpreis]`
     * (Monat als MM.JJJJ, MM/JJJJ oder JJJJ-MM; Kopfzeile erlaubt). Schreibt je
     * Monat einen Arbeitspreis (und Grundpreis) ab dem Monatsersten und setzt das
     * Preismodell „monthly". Einträge mit demselben Stichtag werden ersetzt.
     *
     * @return array<string,mixed>
     */
    public function importMonthlyPrices(string $utility, string $id, string $text, bool $dryRun = false): array
    {
        $c = $this->get($utility, $id) ?? throw new NotFoundException($this->i18n->t('errors.contract.notFound'));
        if ($utility === 'wasser' || Utilities::isFeedIn($utility)) {
            throw new LocalizedException('errors.contract.priceImportUnsupported', [], 'price import');
        }
        $wp = []; $bp = []; $errors = [];
        $lines = preg_split('/\r\n|\n|\r/', trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $text))) ?: [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, ';', '"', '');
            $month = PeriodService::parseMonth((string)($cells[0] ?? ''));
            $ct = ReadingImportService::parseNum((string)($cells[1] ?? ''));
            if ($month === null || $ct === null) {
                if ($i > 0 || $month !== null) $errors[] = ['line' => $i + 1, 'text' => mb_substr($line, 0, 80)];
                continue;
            }
            $wp[$month[0]] = ['from' => $month[0], 'ct_per_kwh' => round($ct, 4)];
            $base = isset($cells[2]) && trim((string)$cells[2]) !== '' ? ReadingImportService::parseNum((string)$cells[2]) : null;
            if ($base !== null) $bp[$month[0]] = ['from' => $month[0], 'eur_per_month' => round($base, 2)];
        }
        if ($wp === []) throw new LocalizedException('errors.contract.priceImportEmpty', [], 'price import empty');
        ksort($wp);
        $result = ['months' => count($wp), 'from' => array_key_first($wp), 'to' => array_key_last($wp),
                   'base_prices' => count($bp), 'errors' => $errors];
        if ($dryRun) return $result + ['would_import' => count($wp)];
        $merge = function (array $old, array $new): array {
            $byFrom = [];
            foreach ($old as $e) if (is_array($e)) $byFrom[(string)($e['from'] ?? '')] = $e;
            foreach ($new as $f => $e) $byFrom[$f] = $e;
            ksort($byFrom);
            return array_values($byFrom);
        };
        $this->update($utility, $id, ['working_prices' => $merge((array)($c['working_prices'] ?? []), $wp),
            'base_prices' => $merge((array)($c['base_prices'] ?? []), $bp), 'price_model' => 'monthly']);
        return $result;
    }

    /** Arbeitspreise, die für einen Zähler gelten: im Gruppenvertrag die des Mitglieds, sonst die allgemeinen. */
    public static function workingPricesFor(array $c, ?string $meterId): array
    {
        if ($meterId !== null && !empty($c['working_prices_by_meter'][$meterId])) return (array)$c['working_prices_by_meter'][$meterId];
        return (array)($c['working_prices'] ?? []);
    }

    /**
     * Zähler und Gruppen mit Vertrag einer Art: die Zähler in Betrieb und je
     * Gruppe mit einem echten Gruppenvertrag die Gruppe als Ziel
     * ({@see MeterService::groupTarget()}). Für Agenda und Empfehlungen.
     *
     * @return list<array<string,mixed>>
     */
    public function targets(string $utility): array
    {
        $out = array_values(array_filter($this->meters->list($utility), fn($m) => MeterService::inService($m)));
        $seen = [];
        foreach ($this->list($utility) as $c) {
            $gid = (string)($c['meter_group_id'] ?? '');
            if ($gid === '' || !empty($c['is_shadow']) || isset($seen[$gid])) continue;
            $seen[$gid] = true;
            if (($g = $this->meters->groupTarget($utility, $gid)) !== null) $out[] = $g;
        }
        return $out;
    }

    /**
     * v2.9.0 — Der Tag, dessen Preise in einem Abschnitt gelten: der Beginn
     * des Abschnitts.
     *
     * v3.11.0 (Skeptiker H1) — auch bei einem weiterlaufenden Vertrag der Beginn des Abschnitts,
     * nicht mehr sein letzter Tag: Ohne Eintrag nach dem Ende gilt der letzte Preis wie bisher
     * (der letzte Eintrag bis zu diesem Tag ist derselbe), eine eingetragene Preisänderung nach
     * dem Ende gilt ab ihrem Tag. Bis v3.10 fiel sie in der Verlängerung weg; Saldo und Prognose
     * rechneten mit dem alten Preis und dem alten Abschlag.
     */
    public static function priceDate(array $segment): string
    {
        return (string)$segment['from'];
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

    /**
     * Summe der Boni, die in einem Monat der Vertragslaufzeit wirken.
     *
     * v2.5.3 — Ein Bonus gehört zu seinem Vertrag, nicht zu seinem
     * Gutschriftsmonat. Die Wechsel- und Neukundenboni kommen typisch mit der
     * Schlussrechnung, also NACH dem Vertragsende; in jenem Monat gilt schon
     * der Folgevertrag, und bis v2.5.2 fiel der Bonus deshalb aus Saldo und
     * Tarifvergleich heraus (im Test 120 €). Jetzt zählt eine Gutschrift nach
     * dem Ende im letzten Vertragsmonat, eine vor dem Beginn im ersten.
     */
    public function bonusForMonth(array $contract, int $year, int $month): float
    {
        return array_sum(array_column(self::bonusesForMonth($contract, $year, $month), 'amount_eur'));
    }

    /**
     * v3.7.0 (GitHub #33, #35) — die Boni hinter bonusForMonth() einzeln:
     * Gutschriftsdatum, Betrag, Art (`sofort`, `wechsel`, `neukunde`,
     * `sonstige`) und Notiz (`label`). Für die Zeile „Bonus −X € am …“ unter
     * der Kachel „Kosten“ und die Sätze im Jahresbericht.
     *
     * @return list<array{credit_date:string,amount_eur:float,type:string,label:string}>
     */
    public static function bonusesForMonth(array $contract, int $year, int $month): array
    {
        $ym      = sprintf('%04d-%02d', $year, $month);
        $startYm = substr((string)($contract['start'] ?? ''), 0, 7);
        $endYm   = !empty($contract['end']) ? substr((string)$contract['end'], 0, 7) : null;
        $out = [];
        foreach ($contract['bonuses'] ?? [] as $b) {
            if (!is_array($b) || empty($b['credit_date'])) continue;
            $bYm = substr((string)$b['credit_date'], 0, 7);
            if ($endYm !== null && $bYm > $endYm) $bYm = $endYm;
            if ($startYm !== '' && $bYm < $startYm) $bYm = $startYm;
            if ($bYm === $ym) $out[] = self::bonusItem($b);
        }
        return $out;
    }

    /**
     * v3.11.0 (GitHub #56) — Boni mit ihrem Gutschriftstag in [von, bis): für Summen über einen
     * Zeitraum, der mitten im Monat beginnt oder endet (Saldo der laufenden Abrechnungsperiode).
     * Gezählt am wirksamen Tag: Gutschriftstag, nach dem Vertragsende das Ende, vor dem Beginn der
     * Beginn (dieselbe Regel wie bonusesForMonth(), nur tagesgenau). Bis v3.10 zählten die Summen
     * den ganzen Kalendermonat (bonusForMonth): Lag der Bonus am letzten Tag der alten Periode, stand
     * er in der laufenden noch einmal.
     */
    public static function bonusBetween(array $contract, string $from, string $toExcl): float
    {
        $start = (string)($contract['start'] ?? '');
        $end   = (string)($contract['end'] ?? '');
        $sum = 0.0;
        foreach ($contract['bonuses'] ?? [] as $b) {
            if (!is_array($b) || !Dates::isIsoDate($b['credit_date'] ?? null)) continue;
            $d = (string)$b['credit_date'];
            if ($end !== '' && $d > $end) $d = $end;
            if ($start !== '' && $d < $start) $d = $start;
            if ($d >= $from && $d < $toExcl) $sum += (float)($b['amount_eur'] ?? 0);
        }
        return $sum;
    }

    /** v3.7.0 (#35) — die Bonusarten; unbekannte zeigt die Oberfläche als „Bonus“. */
    public const BONUS_TYPES = ['sofort', 'wechsel', 'neukunde', 'sonstige'];

    /** @return array{credit_date:string,amount_eur:float,type:string,label:string} */
    public static function bonusItem(array $b): array
    {
        return ['credit_date' => (string)($b['credit_date'] ?? ''), 'amount_eur' => (float)($b['amount_eur'] ?? 0),
                'type' => (string)($b['type'] ?? 'sofort'), 'label' => (string)($b['label'] ?? '')];
    }
}
