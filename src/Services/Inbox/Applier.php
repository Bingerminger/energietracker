<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

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
 * v3.7.0 (Posteingang) — angehakte Vorschläge übernehmen, über dieselben
 * Dienste und Prüfungen wie die Handeingabe (ReadingService, ContractService,
 * BillService, MeterService, ReminderService, SettingsService). Nichts wird an
 * den Diensten vorbei in eine Datei geschrieben.
 *
 * Reihenfolge: Kennungen, Vertrag, Preise und Abschläge, Vertragsende,
 * Ablesungen, Zählertausch, Rechnung, Buchung, Gas-Umrechnung, Termine. Ein
 * Fehler hält die übrigen Vorschläge nicht auf; was schon übernommen ist,
 * bleibt, und ein zweiter Lauf erkennt es als vorhanden.
 *
 * Das Dokument hängt danach als Beleg am Haupteintrag: an der Rechnung
 * (Rechnungen), sonst am Vertrag.
 */
final class Applier
{
    // v3.7.0 (treffsicher, Nachbesserung 4) — der Zählertausch vor den Ablesungen: Die Stände
    // nach dem Tauschtag einer Rechnung gehören zum neuen Gerät (ReadingService nach Datum)
    private const ORDER = ['meter_ids', 'contract_create', 'contract_start', 'contract_ids', 'contract_price', 'contract_base_price',
        'contract_advance', 'contract_end', 'meter_change', 'reading', 'bill', 'bill_book', 'gas_factor', 'reminder'];

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
     * @param list<array<string,mixed>> $proposals ausgewählte Vorschläge (aus Proposals::build)
     * @param array<string,mixed>       $entry     Eintrag im Posteingang
     * @return array{applied: list<array<string,mixed>>, skipped: list<array<string,mixed>>, failed: list<array<string,mixed>>}
     */
    public function apply(string $u, array $proposals, array $entry): array
    {
        usort($proposals, fn($a, $b) => array_search($a['kind'], self::ORDER, true) <=> array_search($b['kind'], self::ORDER, true));
        $res = ['applied' => [], 'skipped' => [], 'failed' => []];
        $att = (string)($entry['attachment_id'] ?? '');
        $type = (string)($entry['type'] ?? '');
        $note = $this->i18n->t('inbox.applied.note', ['name' => (string)($entry['name'] ?? '')]);
        $billId = null;
        foreach ($proposals as $p) {
            if ($p['status'] === 'exists') { $res['skipped'][] = ['id' => $p['id'], 'kind' => $p['kind'], 'reason' => 'exists']; continue; }
            try {
                $d = $p['data'];
                $ref = match ($p['kind']) {
                    'reading' => $this->reading($u, $p, $note),
                    'bill' => $billId = $this->bill($u, $p),
                    'bill_book' => $this->book($u, $p, $billId, $entry),
                    'contract_create' => ['type' => 'contract', 'utility' => $u, 'id' => (string)$this->contracts->create($u, $d)['id']],
                    'contract_end' => $this->contractPatch($u, (string)$d['contract_id'], ['end' => $d['end'], 'auto_renews' => false],
                        $type === 'cancellation' ? $att : ''),
                    'contract_start' => $this->contractPatch($u, (string)$d['contract_id'], ['start' => $d['start']], $type === 'supply_start' ? $att : ''),
                    'contract_ids' => $this->contractPatch($u, (string)$d['contract_id'],
                        array_intersect_key($d, array_flip(['contract_number', 'customer_number']))),
                    'contract_price', 'contract_base_price', 'contract_advance' => $this->priceRow($u, $d,
                        in_array($type, ['price_change', 'advance_change'], true) ? $att : ''),
                    'meter_ids' => $this->meterPatch($u, (string)$d['meter_id'], ['malo_id' => $d['malo_id']]),
                    'meter_change' => $this->meterChange($u, $d),
                    'gas_factor' => $this->gasFactor($d),
                    'reminder' => $this->reminder($d, $note),
                    // eine Art, die diese Version nicht kennt: Die Vorschau stammt von woanders
                    default => throw new LocalizedException('errors.inbox.proposalStale', [], 'proposal kind ' . $p['kind']),
                };
                if (is_array($ref) && isset($ref['bill_id'])) $ref = ['type' => 'bill', 'utility' => $u, 'id' => $ref['bill_id']];
                $res['applied'][] = ['id' => $p['id'], 'kind' => $p['kind'], 'ref' => $ref, 'key' => self::key($p)];
            } catch (\Throwable $e) {
                $msg = $e instanceof LocalizedException ? $this->i18n->t($e->key, $e->params) : $e->getMessage();
                $res['failed'][] = ['id' => $p['id'], 'kind' => $p['kind'], 'error' => $msg,
                    'code' => $e instanceof LocalizedException ? $e->key : null];
            }
        }
        return $res;
    }

    /** Wiedererkennung über Läufe hinweg (Termine: Anlass und Tag). */
    private static function key(array $p): string
    {
        return $p['kind'] === 'reminder' ? $p['data']['label'] . '@' . $p['data']['next_due'] : (string)$p['id'];
    }

    private function reading(string $u, array $p, string $note): array
    {
        $d = $p['data'];
        // v3.7.0 (treffsicher, Nachbesserung 4) — geschätzt laut Rechnung: als geschätzt angelegt
        $est = !empty($d['is_estimated']) ? ['is_estimated' => true] : [];
        if ($p['status'] === 'conflict' && !empty($p['detail']['reading_id'])) {
            $r = $this->readings->update($u, (string)$p['detail']['reading_id'], ['counter' => $d['counter']] + $est);
        } else {
            $r = $this->readings->create($u, $est + ['meter_id' => $d['meter_id'], 'date' => $d['date'], 'counter' => $d['counter'],
                'note' => $note, 'source' => 'inbox']);
        }
        return ['type' => 'reading', 'utility' => $u, 'id' => (string)$r['id']];
    }

    /** @return array{bill_id: string} */
    private function bill(string $u, array $p): array
    {
        $d = $p['data'];
        if ($p['status'] === 'conflict' && !empty($p['detail']['bill_id'])) {
            $old = $this->bills->get($u, (string)$p['detail']['bill_id']);
            $ids = array_values(array_unique([...(array)($old['attachment_ids'] ?? []), ...(array)$d['attachment_ids']]));
            $b = $this->bills->update($u, (string)$old['id'], ['invoice' => $d['invoice'], 'kind' => $d['kind'], 'attachment_ids' => $ids]);
        } else {
            $b = $this->bills->create($u, $d);
        }
        return ['bill_id' => (string)$b['id']];
    }

    private function book(string $u, array $p, ?array $billRef, array $entry): array
    {
        $id = $billRef['bill_id'] ?? null;
        if ($id === null) {
            foreach ($this->bills->list($u) as $b) {
                if (($b['period_from'] ?? '') === $p['data']['period_from'] && ($b['period_to'] ?? '') === $p['data']['period_to']) { $id = (string)$b['id']; break; }
            }
        }
        if ($id === null) throw new LocalizedException('errors.inbox.billMissing', [], 'book without bill');
        // v3.7.0 (treffsicher) — ohne zugeordneten Vertrag nur an einen Vertrag des
        // Versorgers dieser Rechnung, der ihren Zeitraum abrechnet; sonst ablehnen,
        // nie still an den eines anderen Lieferanten
        $b = $this->bills->get($u, $id);
        $supplier = trim((string)(Proposals::value($entry, 'supplier') ?? ''));
        if (empty($b['special_payment_id']) && empty($b['contract_id']) && $supplier !== '') {
            $c = $this->bills->contractFor($u, (string)$b['meter_id'], (string)$b['period_from'], (string)$b['period_to'],
                fn($x) => Proposals::supplierMatch((string)(($x['provider'] ?? '') ?: ($x['tariff_name'] ?? '')), $supplier));
            if ($c === null) {
                throw new LocalizedException('errors.bill.noContract',
                    ['from' => $this->i18n->date((string)$b['period_from']), 'to' => $this->i18n->date((string)$b['period_to'])], 'book: no contract of the supplier');
            }
            $this->bills->update($u, $id, ['contract_id' => (string)$c['id']]);
        }
        $this->bills->book($u, $id);
        return ['type' => 'bill', 'utility' => $u, 'id' => $id];
    }

    /** Ein Feld am Vertrag ändern; mit `$att` hängt das Dokument danach als Beleg am Vertrag. */
    private function contractPatch(string $u, string $id, array $patch, string $att = ''): array
    {
        $c = $this->contracts->get($u, $id) ?? throw new LocalizedException('errors.inbox.contractMissing', [], "contract $id");
        if ($att !== '') $patch['attachment_ids'] = array_values(array_unique([...(array)($c['attachment_ids'] ?? []), $att]));
        $this->contracts->update($u, $id, $patch);
        return ['type' => 'contract', 'utility' => $u, 'id' => $id];
    }

    /** Preiszeile, Grundpreis oder Abschlag: gleiche Zeile ersetzen, sonst anhängen. */
    private function priceRow(string $u, array $d, string $att): array
    {
        $c = $this->contracts->get($u, (string)$d['contract_id']) ?? throw new LocalizedException('errors.inbox.contractMissing', [], 'contract');
        $rows = array_values(array_filter((array)($c[$d['list']] ?? []), fn($r) => (string)($r['from'] ?? '') !== $d['from']));
        $rows[] = ['from' => $d['from'], $d['key'] => $d['value']];
        usort($rows, fn($a, $b) => strcmp((string)$a['from'], (string)$b['from']));
        return $this->contractPatch($u, (string)$c['id'], [$d['list'] => $rows], $att);
    }

    private function meterPatch(string $u, string $id, array $patch): array
    {
        $this->meters->update($u, $id, $patch);
        return ['type' => 'meter', 'utility' => $u, 'id' => $id];
    }

    private function meterChange(string $u, array $d): array
    {
        $this->meters->replaceDevice($u, (string)$d['meter_id'], array_diff_key($d, ['meter_id' => 1]));
        return ['type' => 'meter', 'utility' => $u, 'id' => (string)$d['meter_id']];
    }

    /** Gas-Umrechnung als neuer Stichtag der Liste (dieselbe Prüfung wie die Einstellungen). */
    private function gasFactor(array $d): array
    {
        $list = ConversionFactorService::normalizeList($this->settings->get('gas_conversion_factors', []), null, false);
        $list = array_values(array_filter($list, fn($f) => ($f['from'] ?? null) !== $d['from']));
        $list[] = ['from' => $d['from'], 'zustandszahl' => $d['zustandszahl'], 'brennwert' => $d['brennwert']];
        $this->settings->set(['gas_conversion_factors' => $list]);
        return ['type' => 'setting', 'key' => 'gas_conversion_factors', 'id' => (string)$d['from']];
    }

    /**
     * Termin aus einem Dokument. v3.7.0 (Paket betrieb) — am Zähler (`utility`,
     * `meter_id`), mit Kategorie und eigener Zuständigkeit, wenn die Vorschau sie
     * mitgibt (Proposals::reminderTarget).
     */
    private function reminder(array $d, string $note): array
    {
        $input = [
            'title' => $this->i18n->t('inbox.reminder.' . $d['label'], array_map(fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $this->i18n->date($v) : $v, $d['params'])),
            'next_due' => $d['next_due'], 'category' => (string)($d['category'] ?? 'custom'), 'recurrence' => 'none', 'notes' => $note,
        ];
        if (!empty($d['utility']) && !empty($d['meter_id'])) $input += ['utility' => (string)$d['utility'], 'meter_id' => (string)$d['meter_id']];
        if (!empty($d['assignee_id'])) $input['assignee_id'] = (string)$d['assignee_id'];
        $r = $this->reminders->create($input);
        return ['type' => 'reminder', 'id' => (string)$r['id']];
    }

    /**
     * v3.7.0 (Paket betrieb) — Termin zu einem Dokument, das nur abgelegt wird
     * (Mahnung): derselbe Weg wie die Termine aus den Vorschlägen.
     *
     * @param array<string,mixed> $data {label, next_due, params, utility?, meter_id?, assignee_id?}
     * @return array{type: string, id: string}
     */
    public function documentReminder(array $data, array $entry): array
    {
        return $this->reminder($data, $this->i18n->t('inbox.applied.note', ['name' => (string)($entry['name'] ?? '')]));
    }
}
