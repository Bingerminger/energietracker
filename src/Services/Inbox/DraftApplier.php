<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConversionFactorService;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — die ausgewählten Einträge eines Entwurfs
 * (HouseholdDraft) übernehmen, über dieselben Dienste und Prüfungen wie die
 * Handeingabe. Nichts wird an den Diensten vorbei geschrieben.
 *
 * Reihenfolge: Zähler → Gerätewechsel → Verträge → Preise und Abschläge →
 * Ablesungen → Rechnungen (mit Buchung) → Gas-Umrechnung → Termine. Ein
 * Fehler hält die übrigen Einträge nicht auf; was übernommen ist, bleibt, und
 * ein zweiter Entwurf erkennt es als vorhanden (idempotent).
 *
 * Welche Felder ein Eintrag schreibt: alle, die nicht schon vorhanden sind und
 * nicht zweifelhaft — dazu die Kernfelder (wer den Eintrag anhakt, bestätigt
 * sie), die ausdrücklich bestätigten (`confirm`) und die gewählten Optionen
 * einer Entscheidung (`choose`). Ein Widerspruch zum Bestand überschreibt nur,
 * wenn er bestätigt ist.
 */
final class DraftApplier
{
    public function __construct(
        private MeterService $meters,
        private ContractService $contracts,
        private ReadingService $readings,
        private BillService $bills,
        private ReminderService $reminders,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /**
     * Auswahl prüfen, bevor etwas geschrieben wird. Eine unbekannte ID, ein
     * unbekanntes Feld oder eine Option außerhalb der Liste heißt: Der Entwurf
     * hat sich seit der Anzeige geändert (wie InboxService::apply).
     *
     * @param list<string> $ids
     * @param array<string,list<string>> $confirm
     * @param array<string,array<string,int>> $choose
     * @return list<array<string,mixed>> die ausgewählten Einträge in der Reihenfolge der Übernahme
     */
    public function select(array $draft, array $ids, array $confirm, array $choose): array
    {
        $byId = array_column($draft['items'], null, 'id');
        $out = [];
        foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
            $it = $byId[$id] ?? null;
            if ($it === null || empty($it['actionable'])) throw new LocalizedException('errors.inbox.proposalStale', [], "stale draft item $id");
            $out[] = $it;
        }
        $fieldOf = function (string $id, string $name) use ($byId): array {
            foreach ((array)($byId[$id]['fields'] ?? []) as $f) if ((string)$f['name'] === $name) return $f;
            throw new LocalizedException('errors.inbox.proposalStale', [], "stale draft field $id/$name");
        };
        foreach ($confirm as $id => $names) {
            if (!is_array($names)) throw new LocalizedException('errors.inbox.proposalStale', [], "stale draft confirm $id");
            foreach ($names as $name) $fieldOf((string)$id, (string)$name);
        }
        foreach ($choose as $id => $picks) {
            if (!is_array($picks)) throw new LocalizedException('errors.inbox.proposalStale', [], "stale draft choice $id");
            foreach ($picks as $name => $i) {
                $f = $fieldOf((string)$id, (string)$name);
                if (!is_int($i) || !isset($f['options'][$i])) throw new LocalizedException('errors.inbox.proposalStale', [], "stale draft option $id/$name");
            }
        }
        $order = array_flip(HouseholdDraft::ORDER);
        $pos = array_flip(array_column($draft['items'], 'id'));
        usort($out, fn($a, $b) => [$order[$a['kind']] ?? 99, $pos[$a['id']]] <=> [$order[$b['kind']] ?? 99, $pos[$b['id']]]);
        return $out;
    }

    /**
     * Werte der Felder, die dieser Eintrag schreibt (Name → Wert).
     *
     * @param list<string>      $confirm
     * @param array<string,int> $choose
     * @return array<string,mixed>
     */
    public static function values(array $item, array $confirm = [], array $choose = []): array
    {
        $vals = [];
        foreach ($item['fields'] as $f) {
            if (($f['status'] ?? 'new') === 'exists') continue;
            $n = (string)$f['name'];
            $chosen = array_key_exists($n, $choose) ? (int)$choose[$n] : null;
            $core = !empty($f['core']) && ($f['status'] ?? 'new') !== 'conflict';
            if (!(empty($f['check']) || $core || in_array($n, $confirm, true) || $chosen !== null)) continue;
            $vals[$n] = $chosen !== null ? $f['options'][$chosen]['value'] : $f['value'];
        }
        return $vals;
    }

    /**
     * @param list<array<string,mixed>> $selected aus select()
     * @param array<string,list<string>> $confirm
     * @param array<string,array<string,int>> $choose
     * @return array{applied: list<array<string,mixed>>, failed: list<array<string,mixed>>, meters: array<string,?string>, contracts: array<string,?string>}
     */
    public function apply(array $draft, array $selected, array $confirm, array $choose): array
    {
        $meterIds = (array)($draft['targets']['meters'] ?? []);
        $contractIds = (array)($draft['targets']['contracts'] ?? []);
        $res = ['applied' => [], 'failed' => []];
        foreach ($selected as $it) {
            $vals = self::values($it, (array)($confirm[$it['id']] ?? []), (array)($choose[$it['id']] ?? []));
            $u = (string)$it['utility'];
            $failedFields = [];
            try {
                $ref = match ($it['kind']) {
                    'meter' => $this->meter($u, $it, $vals, $meterIds),
                    'meter_change' => $this->meterChange($u, $it, $vals, $meterIds),
                    'contract' => $this->contract($u, $it, $vals, $meterIds, $contractIds),
                    'contract_end' => $this->contractPatch($u, (string)$it['contract_id'], ['end' => $vals['end'] ?? null, 'auto_renews' => false]),
                    'prices', 'advances' => $this->rows($u, $it, $vals, $contractIds),
                    'readings' => $this->readingRows($u, $it, $vals, $meterIds, $failedFields),
                    'bill' => $this->bill($u, $it, $vals, $meterIds, $contractIds, $failedFields),
                    'gas_factor' => $this->gasFactors($vals),
                    'reminder' => $this->reminder($it, $vals),
                    default => throw new LocalizedException('errors.inbox.proposalStale', [], 'draft kind ' . $it['kind']),
                };
                $res['applied'][] = ['id' => $it['id'], 'kind' => $it['kind'], 'utility' => $u, 'ref' => $ref, 'fields' => array_keys($vals),
                    'key' => $it['kind'] === 'reminder' ? $it['reminder']['label'] . '@' . ($vals['due'] ?? '') : (string)$it['id']];
                foreach ($failedFields as $ff) $res['failed'][] = $ff + ['id' => $it['id'], 'kind' => $it['kind']];
            } catch (\Throwable $e) {
                $res['failed'][] = ['id' => $it['id'], 'kind' => $it['kind'], 'field' => null, 'error' => $this->message($e),
                    'code' => $e instanceof LocalizedException ? $e->key : null];
            }
        }
        return $res + ['meters' => $meterIds, 'contracts' => $contractIds];
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof LocalizedException ? $this->i18n->t($e->key, $e->params) : $e->getMessage();
    }

    private static function need(?string $id, string $what): string
    {
        if ($id === null || $id === '') throw new LocalizedException('errors.inboxDraft.needs.' . $what, [], "draft needs $what");
        return $id;
    }

    /** Zähler anlegen, den leeren Standardzähler übernehmen oder einen vorhandenen ergänzen. */
    private function meter(string $u, array $it, array $vals, array &$meterIds): array
    {
        $key = (string)$it['meter_key'];
        if ($it['action'] === 'create') {
            $m = $this->meters->create($u, array_filter([
                'name' => (string)($vals['name'] ?? $this->i18n->defaultMeterName($u)),
                'installed_on' => $vals['installed_on'] ?? null,
                'device_serial' => $vals['serial'] ?? null,
                'malo_id' => $vals['malo'] ?? null,
            ], fn($v) => $v !== null && $v !== ''));
            $meterIds[$key] = (string)$m['id'];
            return ['type' => 'meter', 'utility' => $u, 'id' => (string)$m['id'], 'created' => true];
        }
        $id = self::need($it['meter_id'] ?? null, 'meter');
        if (isset($vals['installed_on'])) $this->meters->backdateFirstDevice($u, $id, (string)$vals['installed_on']);
        if (isset($vals['serial'])) {
            $m = $this->meters->get($u, $id) ?? throw new LocalizedException('errors.inboxDraft.needs.meter', [], 'meter gone');
            $first = $m['devices'][0] ?? null;
            if ($first === null) throw new LocalizedException('errors.meter.noOpenDevice', [], 'meter without device');
            $this->meters->setDeviceSerial($u, $id, (string)$first['id'], (string)$vals['serial']);
        }
        if (isset($vals['malo'])) $this->meters->update($u, $id, ['malo_id' => (string)$vals['malo']]);
        $meterIds[$key] = $id;
        return ['type' => 'meter', 'utility' => $u, 'id' => $id, 'created' => false];
    }

    private function meterChange(string $u, array $it, array $vals, array $meterIds): array
    {
        $id = self::need($meterIds[$it['meter_key']] ?? null, 'meter');
        $this->meters->replaceDevice($u, $id, array_filter([
            'date' => $vals['date'] ?? null,
            'old_final_counter' => $vals['old_final'] ?? null,
            'new_initial_counter' => $vals['new_initial'] ?? null,
            'serial' => $vals['serial'] ?? null,
            'reason' => 'inbox',
        ], fn($v) => $v !== null));
        return ['type' => 'meter', 'utility' => $u, 'id' => $id, 'change' => $vals['date'] ?? null];
    }

    /** Vertrag anlegen, oder einen vorhandenen um fehlende Angaben ergänzen. */
    private function contract(string $u, array $it, array $vals, array $meterIds, array &$contractIds): array
    {
        $data = array_filter([
            'provider' => isset($vals['provider']) ? (string)$vals['provider'] : null,
            'tariff_name' => isset($vals['tariff_name']) ? (string)$vals['tariff_name'] : null,
            'start' => $vals['start'] ?? null,
            'end' => $vals['end'] ?? null,
            'min_term_end' => $vals['min_term_end'] ?? null,
            'price_guarantee_until' => $vals['price_guarantee_until'] ?? null,
            'signup_bonus_eur' => $vals['signup_bonus_eur'] ?? null,
            'contract_number' => $vals['contract_number'] ?? null,
            'customer_number' => $vals['customer_number'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
        if (isset($data['end'])) $data['auto_renews'] = false;
        if (isset($vals['notice'])) {
            $n = Proposals::notice((string)$vals['notice']);
            if ($n['months'] !== null) $data['notice_period_months'] = $n['months'];
            if ($n['days'] !== null) $data['notice_period_days'] = $n['days'];
        }
        $atts = array_values(array_filter((array)($it['attachments'] ?? [])));
        $existing = $it['contract_id'] ?? null;
        if ($existing !== null && $it['status'] !== 'conflict') {
            $c = $this->contracts->get($u, (string)$existing) ?? throw new LocalizedException('errors.inbox.contractMissing', [], 'contract gone');
            unset($data['provider'], $data['start']);
            if ($atts !== []) $data['attachment_ids'] = array_values(array_unique([...(array)($c['attachment_ids'] ?? []), ...$atts]));
            if ($data !== []) $this->contracts->update($u, (string)$existing, $data);
            $contractIds[$it['contract_key']] = (string)$existing;
            return ['type' => 'contract', 'utility' => $u, 'id' => (string)$existing, 'created' => false];
        }
        $data['meter_id'] = self::need($meterIds[$it['meter_key']] ?? null, 'meter');
        if ($atts !== []) $data['attachment_ids'] = $atts;
        $c = $this->contracts->create($u, $data);
        $contractIds[$it['contract_key']] = (string)$c['id'];
        return ['type' => 'contract', 'utility' => $u, 'id' => (string)$c['id'], 'created' => true];
    }

    private function contractPatch(string $u, string $id, array $patch): array
    {
        $this->contracts->get($u, $id) ?? throw new LocalizedException('errors.inbox.contractMissing', [], "contract $id");
        $this->contracts->update($u, $id, $patch);
        return ['type' => 'contract', 'utility' => $u, 'id' => $id, 'created' => false];
    }

    /** Preis-, Grundpreis- und Abschlagszeilen: gleiche Zeile ersetzen, sonst anhängen; ein Schreibvorgang. */
    private function rows(string $u, array $it, array $vals, array $contractIds): array
    {
        $cid = self::need($contractIds[$it['contract_key']] ?? null, 'contract');
        $c = $this->contracts->get($u, $cid) ?? throw new LocalizedException('errors.inbox.contractMissing', [], 'contract');
        $byName = array_column($it['fields'], null, 'name');
        $patch = [];
        foreach ($vals as $name => $v) {
            $f = $byName[$name];
            $list = (string)$f['list'];
            $rows = $patch[$list] ?? (array)($c[$list] ?? []);
            $rows = array_values(array_filter($rows, fn($r) => (string)($r['from'] ?? '') !== $f['from']));
            $rows[] = ['from' => $f['from'], $f['key'] => (float)$v];
            usort($rows, fn($a, $b) => strcmp((string)$a['from'], (string)$b['from']));
            $patch[$list] = $rows;
        }
        if ($patch !== []) $this->contracts->update($u, $cid, $patch);
        return ['type' => 'contract', 'utility' => $u, 'id' => $cid, 'rows' => count($vals)];
    }

    /** Ablesungen je Tag; ein Widerspruch zum Bestand ändert die vorhandene (nur bestätigt). */
    private function readingRows(string $u, array $it, array $vals, array $meterIds, array &$failed): array
    {
        $mid = self::need($meterIds[$it['meter_key']] ?? null, 'meter');
        $byName = array_column($it['fields'], null, 'name');
        $n = 0;
        foreach ($vals as $name => $v) {
            $f = $byName[$name];
            $note = $this->note($f['sources'] ?? []);
            // geschätzt laut Rechnung → `is_estimated` (wie Applier::reading im Einzeldokument)
            $est = !empty($f['is_estimated']) ? ['is_estimated' => true] : [];
            try {
                if (($f['status'] ?? 'new') === 'conflict' && !empty($f['reading_id'])) {
                    $this->readings->update($u, (string)$f['reading_id'], ['counter' => (float)$v] + $est);
                } else {
                    $this->readings->create($u, $est + ['meter_id' => $mid, 'date' => (string)$f['date'], 'counter' => (float)$v, 'note' => $note, 'source' => 'inbox']);
                }
                $n++;
            } catch (\Throwable $e) {
                $failed[] = ['field' => $name, 'error' => $this->message($e), 'code' => $e instanceof LocalizedException ? $e->key : null,
                    'docs' => array_values(array_unique(array_column($f['sources'], 'doc')))];
            }
        }
        return ['type' => 'meter', 'utility' => $u, 'id' => $mid, 'readings' => $n];
    }

    /** Rechnung erfassen (oder die vorhandene ergänzen), danach das Ergebnis als Sonderzahlung buchen. */
    private function bill(string $u, array $it, array $vals, array $meterIds, array $contractIds, array &$failed): array
    {
        $mid = self::need($meterIds[$it['meter_key']] ?? null, 'meter');
        $inv = array_filter([
            ($u === 'wasser' ? 'volume_m3' : 'energy_kwh') => $vals['consumption'] ?? null,
            'amount_eur' => $vals['total_amount'] ?? null,
            'advances_paid_eur' => $vals['advances_paid'] ?? null,
            'result_eur' => $vals['result'] ?? null,
        ], fn($v) => $v !== null);
        $cid = $it['contract_key'] !== null ? ($contractIds[$it['contract_key']] ?? null) : null;
        $billId = $it['bill_id'] ?? null;
        if ($billId !== null) {
            $old = $this->bills->get($u, (string)$billId);
            $patch = [];
            if ($inv !== []) $patch['invoice'] = array_merge((array)($old['invoice'] ?? []), $inv);
            $ids = array_values(array_unique([...(array)($old['attachment_ids'] ?? []), ...(array)($it['attachments'] ?? [])]));
            if ($ids !== (array)($old['attachment_ids'] ?? [])) $patch['attachment_ids'] = $ids;
            if (isset($vals['kind'])) $patch['kind'] = $vals['kind'];
            if ($patch !== []) $this->bills->update($u, (string)$billId, $patch);
        } else {
            $kind = $vals['kind'] ?? null;
            foreach ($it['fields'] as $f) if ($f['name'] === 'kind' && $kind === null && ($f['status'] ?? '') === 'exists') $kind = $f['value'];
            $b = $this->bills->create($u, ['meter_id' => $mid, 'contract_id' => $cid, 'kind' => $kind === 'final' ? 'final' : 'annual',
                'period_from' => $vals['period_from'] ?? self::fieldValue($it, 'period_from'), 'period_to' => $vals['period_to'] ?? self::fieldValue($it, 'period_to'),
                'invoice' => $inv, 'note' => '', 'attachment_ids' => array_values((array)($it['attachments'] ?? []))]);
            $billId = (string)$b['id'];
        }
        $booked = false;
        $result = $vals['result'] ?? (self::fieldStatus($it, 'result') === 'exists' ? self::fieldValue($it, 'result') : null);
        if (!empty($it['book']) && empty($it['booked']) && is_numeric($result) && (float)$result != 0.0 && Utilities::hasSpecialPayments($u)) {
            try {
                $this->bills->book($u, (string)$billId);
                $booked = true;
            } catch (\Throwable $e) {
                $failed[] = ['field' => 'book', 'error' => $this->message($e), 'code' => $e instanceof LocalizedException ? $e->key : null, 'docs' => (array)($it['docs'] ?? [])];
            }
        }
        $b = $this->bills->get($u, (string)$billId);
        return ['type' => 'bill', 'utility' => $u, 'id' => (string)$billId, 'meter_id' => (string)$b['meter_id'],
            'period_from' => (string)$b['period_from'], 'period_to' => (string)$b['period_to'], 'booked' => $booked];
    }

    private static function fieldValue(array $it, string $name): mixed
    {
        foreach ($it['fields'] as $f) if ($f['name'] === $name) return $f['value'];
        return null;
    }

    private static function fieldStatus(array $it, string $name): ?string
    {
        foreach ($it['fields'] as $f) if ($f['name'] === $name) return $f['status'] ?? 'new';
        return null;
    }

    /** Gas-Umrechnung als Stichtage der Liste (dieselbe Prüfung wie die Einstellungen). */
    private function gasFactors(array $vals): array
    {
        $list = ConversionFactorService::normalizeList($this->settings->get('gas_conversion_factors', []), null, false);
        foreach ($vals as $name => $v) {
            $from = substr((string)$name, 3);
            $list = array_values(array_filter($list, fn($f) => ($f['from'] ?? null) !== $from));
            $list[] = ['from' => $from, 'zustandszahl' => (float)$v['z'], 'brennwert' => (float)$v['hs']];
        }
        if ($vals !== []) $this->settings->set(['gas_conversion_factors' => $list]);
        return ['type' => 'setting', 'key' => 'gas_conversion_factors', 'id' => implode(',', array_map(fn($n) => substr((string)$n, 3), array_keys($vals)))];
    }

    /** Vermerk wie beim einzelnen Dokument: „Aus dem Dokument … übernommen“ (das erste Dokument mit dem Wert). */
    private function note(array $sources): string
    {
        return $this->i18n->t('inbox.applied.note', ['name' => (string)($sources[0]['name'] ?? '')]);
    }

    private function reminder(array $it, array $vals): array
    {
        $note = $this->note($it['fields'][0]['sources'] ?? []);
        $due = (string)($vals['due'] ?? self::fieldValue($it, 'due'));
        $params = ['date' => $this->i18n->date($due)] + (array)($it['reminder']['params'] ?? []);
        $r = $this->reminders->create([
            'title' => $this->i18n->t('inbox.reminder.' . $it['reminder']['label'], $params),
            'next_due' => $due, 'category' => 'custom', 'recurrence' => 'none', 'notes' => $note,
        ]);
        return ['type' => 'reminder', 'id' => (string)$r['id']];
    }
}
