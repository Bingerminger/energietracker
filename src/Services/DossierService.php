<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;

/**
 * v3.7.0 (Paket betrieb) — Akte je Vertrag und Zähler: alle Dokumente an einer
 * Stelle, neueste zuerst. Gelesen wird nur, was es schon gibt:
 *   inbox    erledigte oder abgelegte Dokumente des Posteingangs, die dem Zähler
 *            bzw. Vertrag zugeordnet sind (Zuordnung, beim Vertrag auch, was ein
 *            Übernehmen dort angelegt hat) — mit ihrer Kernaussage (`gist`)
 *   bill     Versorgerrechnungen mit ihren PDFs (`attachment_ids`)
 *   receipt  Belege am Vertrag (`attachment_ids`)
 *   photo    Fotos der Ablesungen (nur beim Zähler)
 * Ein PDF steht einmal: Stammt eine Rechnung oder ein Beleg aus dem Posteingang,
 * trägt der Eintrag des Posteingangs die Rechnung mit (`bill_id`).
 *
 * Ein Eintrag: {kind, type, date, title, attachment_id, attachment_ids?,
 * inbox_id?, bill_id?, contract_id?, status?, gist?, duplicate_of? (v3.7.0,
 * treffsicher: älteres inhaltsgleiches Dokument), period_from?, period_to?,
 * result_eur?, amount_eur?, counter?}. Datum: Stichtag des Dokuments (Zeitraum-
 * ende, gültig ab, Lieferende …), sonst der Eingang.
 */
final class DossierService
{
    public const KINDS = ['inbox', 'bill', 'receipt', 'photo'];
    /** Felder, deren Datum ein Dokument im Zeitstrahl verortet (in dieser Reihenfolge). */
    private const DATE_FIELDS = ['period_to', 'valid_from', 'supply_end', 'supply_start', 'change_date', 'requested_by'];

    public function __construct(
        private InboxService $inbox,
        private AttachmentService $attachments,
        private BillService $bills,
        private ContractService $contracts,
        private MeterService $meters,
        private ReadingService $readings,
        private I18nService $i18n,
    ) {}

    /** @return list<array<string,mixed>> */
    public function forMeter(string $u, string $meterId): array
    {
        $this->assertUtility($u);
        $meter = $this->meters->get($u, $meterId) ?? throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        $contracts = [];
        if (Utilities::hasContracts($u)) {
            $contracts = $this->contracts->list($u, $meterId);
            $gid = (string)($meter['meter_group_id'] ?? '');
            if ($gid !== '') $contracts = [...$contracts, ...$this->contracts->list($u, $gid)];
            $contracts = array_values(array_filter($contracts, fn($c) => empty($c['is_shadow'])));
        }
        $cids = array_map(fn($c) => (string)$c['id'], $contracts);
        $items = $this->inboxItems(fn(array $as, array $e) => ($as['meter_id'] ?? null) === $meterId || in_array($as['contract_id'] ?? null, $cids, true), $u);
        foreach ($this->billsOf($u, $meterId) as $b) $this->addBill($items, $b);
        foreach ($contracts as $c) $this->addReceipts($items, $c);
        if (Utilities::isCumulative($u)) {
            foreach ($this->readings->list($u, $meterId) as $r) {
                if (empty($r['attachment_id']) || $this->seen($items, (string)$r['attachment_id'])) continue;
                $items[] = ['kind' => 'photo', 'type' => 'reading', 'date' => (string)($r['date'] ?? ''), 'title' => $this->name((string)$r['attachment_id']),
                    'attachment_id' => (string)$r['attachment_id'], 'counter' => isset($r['counter']) ? (float)$r['counter'] : null];
            }
        }
        return self::sorted($items);
    }

    /** @return list<array<string,mixed>> */
    public function forContract(string $u, string $contractId): array
    {
        $this->assertUtility($u);
        $c = Utilities::hasContracts($u) ? $this->contracts->get($u, $contractId) : null;
        if ($c === null) throw new NotFoundException($this->i18n->t('errors.contract.notFound'));
        $items = $this->inboxItems(function (array $as, array $e) use ($contractId): bool {
            if (($as['contract_id'] ?? null) === $contractId) return true;
            foreach ((array)($e['applied'] ?? []) as $a) {
                if (($a['ref']['type'] ?? null) === 'contract' && ($a['ref']['id'] ?? null) === $contractId) return true;
            }
            return false;
        }, $u);
        $target = (string)(($c['meter_group_id'] ?? '') ?: ($c['meter_id'] ?? ''));
        foreach ($this->billsOf($u, null) as $b) {
            $mine = ($b['contract_id'] ?? null) === $contractId
                || (empty($b['contract_id']) && ($b['meter_id'] ?? null) === $target
                    && (string)($b['period_to'] ?? '') >= (string)($c['start'] ?? '') && (empty($c['end']) || (string)($b['period_to'] ?? '') <= (string)$c['end']));
            if ($mine) $this->addBill($items, $b);
        }
        $this->addReceipts($items, $c);
        return self::sorted($items);
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @param callable(array, array): bool $match */
    private function inboxItems(callable $match, string $u): array
    {
        $out = [];
        $all = $this->inbox->all();
        foreach ($all as $e) {
            if (!in_array($e['status'] ?? '', ['done', 'filed'], true)) continue;
            $as = is_array($e['assignment'] ?? null) ? $e['assignment'] : [];
            if (($as['utility'] ?? null) !== $u || !$match($as, $e)) continue;
            $att = (string)($e['attachment_id'] ?? '');
            $out[] = ['kind' => 'inbox', 'type' => (string)($e['type'] ?? 'other'), 'date' => self::documentDate($e),
                'title' => (string)($e['name'] ?? ''), 'attachment_id' => $att !== '' ? $att : null, 'inbox_id' => (string)$e['id'],
                'status' => (string)$e['status'], 'gist' => is_array($e['gist'] ?? null) ? $e['gist'] : null,
                // v3.7.0 (treffsicher) — dasselbe Schreiben in einer zweiten Datei: als Doppel kenntlich
                'duplicate_of' => $this->inbox->olderDuplicateOf($e, $all)];
        }
        return $out;
    }

    /** Rechnungen einer Art (die nicht jede Art kennt). */
    private function billsOf(string $u, ?string $meterId): array
    {
        if (!Utilities::supportsBillCheck($u)) return [];
        return $this->bills->list($u, $meterId);
    }

    private function addBill(array &$items, array $b): void
    {
        $ids = array_values(array_filter((array)($b['attachment_ids'] ?? []), 'is_string'));
        $result = isset($b['invoice']['result_eur']) && is_numeric($b['invoice']['result_eur']) ? (float)$b['invoice']['result_eur'] : null;
        foreach ($items as &$it) {
            if ($it['kind'] === 'inbox' && $it['attachment_id'] !== null && in_array($it['attachment_id'], $ids, true)) {
                $it['bill_id'] = (string)$b['id'];
                return;
            }
        }
        unset($it);
        $items[] = ['kind' => 'bill', 'type' => (string)($b['kind'] ?? 'annual'), 'date' => (string)($b['issued_on'] ?? '') ?: (string)($b['period_to'] ?? ''),
            'title' => $ids !== [] ? $this->name($ids[0]) : '', 'attachment_id' => $ids[0] ?? null, 'attachment_ids' => $ids,
            'bill_id' => (string)$b['id'], 'period_from' => (string)($b['period_from'] ?? ''), 'period_to' => (string)($b['period_to'] ?? ''),
            'result_eur' => $result, 'amount_eur' => isset($b['invoice']['amount_eur']) ? (float)$b['invoice']['amount_eur'] : null];
    }

    private function addReceipts(array &$items, array $c): void
    {
        foreach ((array)($c['attachment_ids'] ?? []) as $a) {
            if (!is_string($a) || $a === '' || $this->seen($items, $a)) continue;
            $meta = $this->attachments->get($a);
            if ($meta === null) continue;
            $items[] = ['kind' => 'receipt', 'type' => 'contract', 'date' => substr((string)($meta['created_at'] ?? ''), 0, 10),
                'title' => (string)($meta['original_name'] ?? ''), 'attachment_id' => $a, 'contract_id' => (string)$c['id'],
                'provider' => (string)($c['provider'] ?? '')];
        }
    }

    private function seen(array $items, string $att): bool
    {
        foreach ($items as $it) {
            if (($it['attachment_id'] ?? null) === $att || in_array($att, (array)($it['attachment_ids'] ?? []), true)) return true;
        }
        return false;
    }

    private ?array $names = null;

    private function name(string $att): string
    {
        if ($this->names === null) {
            $this->names = [];
            foreach ($this->attachments->list() as $a) $this->names[(string)($a['id'] ?? '')] = (string)($a['original_name'] ?? '');
        }
        return $this->names[$att] ?? '';
    }

    /** Stichtag des Dokuments laut Auslesung (Korrekturen zuerst), sonst der Eingang. */
    public static function documentDate(array $e): string
    {
        foreach (self::DATE_FIELDS as $f) {
            $v = array_key_exists($f, (array)($e['edits'] ?? [])) ? $e['edits'][$f] : ($e['extraction']['fields'][$f]['value'] ?? null);
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        }
        return substr((string)($e['created_at'] ?? ''), 0, 10);
    }

    /** Neueste zuerst; bei gleichem Tag Posteingang vor Rechnung, Beleg, Foto. */
    private static function sorted(array $items): array
    {
        $rank = array_flip(self::KINDS);
        usort($items, fn($a, $b) => [(string)$b['date'], $rank[$a['kind']]] <=> [(string)$a['date'], $rank[$b['kind']]]);
        return array_values($items);
    }

    private function assertUtility(string $u): void
    {
        if (!Utilities::exists($u)) throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $u]));
    }
}
