<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\RightsService;

/**
 * v3.12.0 (F1037, Paket lesen) — Versorgerrechnungen (Konzept §3.2).
 *
 *   list_bills  BillService::list (GET …/bills): Zeitraum, Beträge und Menge laut
 *               Rechnung (eingetragen), gebucht oder nicht.
 *   check_bill  BillService::compare (GET …/bills/{id}/check, „Rechnung prüfen“):
 *               Urteil, Gründe (Schlüssel und Text), Rechnung (eingetragen) gegen
 *               die eigene Rechnung, deren Menge die Herkunft der Stände an den
 *               Rändern trägt (Ablesung gemessen, verteilt, geschätzt), Abweichung,
 *               fehlende Preise, abweichende Gasfaktoren; dazu die Befunde „Deine
 *               Rechte als Kunde“ zu dieser Rechnung (v3.11.0, mit Fußzeile
 *               „Hinweis, keine Rechtsberatung“).
 *
 * Beide nennen die Rechnungsnummer nur mit `mcp_share_identifiers`, und auch dann nur
 * unter `untrusted` (sie kommt oft aus dem Dokument); das Aktenzeichen eines Bescheids nie.
 */
final class BillTools extends ToolGroup
{
    use ReadSupport;

    public const MAX_BILLS = 50;

    public function tools(): array
    {
        $keys = array_values(array_filter(Utilities::keys(), [Utilities::class, 'supportsBillCheck']));
        $ro = ['readOnlyHint' => true, 'idempotentHint' => true];
        return [
            new Tool('list_bills', Tool::READ, self::obj([
                'utility' => self::utilityArg($keys),
                'meter'   => self::meterArg(),
            ], ['utility']), fn(array $a, McpContext $c): array => $this->listBills($a), self::listSchema(), $ro),
            new Tool('check_bill', Tool::READ, self::obj([
                'utility' => self::utilityArg($keys),
                'bill_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            ], ['utility', 'bill_id']), fn(array $a, McpContext $c): array => $this->checkBill($a, $c), self::checkSchema(), $ro),
        ];
    }

    /** @return array<string,mixed> */
    private function listBills(array $args): array
    {
        $u = (string)$args['utility'];
        $meterId = isset($args['meter']) ? (string)$this->target($u, (string)$args['meter'])['id'] : null;
        $all = $this->services->get(BillService::class)->list($u, $meterId);
        $share = $this->services->get(Config::class)->shareIdentifiers();
        $out = [];
        foreach (array_slice($all, 0, self::MAX_BILLS) as $b) {
            $out[] = self::compact([
                'id'          => (string)$b['id'],
                'meter_id'    => (string)($b['meter_id'] ?? ''),
                'contract_id' => is_string($b['contract_id'] ?? null) ? $b['contract_id'] : null,
                'kind'        => (string)($b['kind'] ?? ''),
                'period_from' => (string)($b['period_from'] ?? ''),
                'period_to'   => (string)($b['period_to'] ?? ''),
                'issued_on'   => is_string($b['issued_on'] ?? null) ? $b['issued_on'] : null,
                'received_on' => is_string($b['received_on'] ?? null) ? $b['received_on'] : null,
                'invoice'     => $this->invoice($u, (array)($b['invoice'] ?? [])),
                'booked'      => !empty($b['special_payment_id']),
            ] + $this->stated($u, $b, $share) + [
                'untrusted'   => Sanitize::untrusted(['note' => is_string($b['note'] ?? null) ? $this->householdMask()->quote($b['note']) : null,
                    'invoice_number' => $share ? self::invoiceNumber($b) : null]),
            ]);
        }
        return ['utility' => $u, 'currency' => $this->currency(), 'bills' => $out] + (count($all) > self::MAX_BILLS ? ['truncated' => true] : []);
    }

    /**
     * Beträge und Menge laut Rechnung: eingetragen (von Hand oder aus dem Posteingang).
     *
     * @param array<string,mixed> $inv
     * @return array<string,mixed>
     */
    private function invoice(string $u, array $inv): array
    {
        $cur = $this->currency();
        $qty = $u === 'wasser' ? ($inv['volume_m3'] ?? null) : ($inv['energy_kwh'] ?? null);
        return self::compact([
            'quantity'      => self::maybe($qty, fn($v) => Num::of($v, self::consumptionUnit($u), 'entered')),
            // v3.12.0 (Skeptiker N4) — Gas-Rechnung nennt nur m³: auch die Menge laut Rechnung gehört dazu
            'volume'        => $u !== 'wasser' ? self::maybe($inv['volume_m3'] ?? null, fn($v) => Num::of($v, 'm³', 'entered')) : null,
            'amount'        => self::maybe($inv['amount_eur'] ?? null, fn($v) => Num::money($v, $cur, 'entered')),
            'advances_paid' => self::maybe($inv['advances_paid_eur'] ?? null, fn($v) => Num::money($v, $cur, 'entered')),
            'result'        => $this->withMeaning(self::maybe($inv['result_eur'] ?? null, fn($v) => Num::money($v, $cur, 'entered',
                ['sign' => Provenance::signFor(Utilities::isFeedIn($u))])), $u),
        ]);
    }

    /**
     * v3.11.0 — Was die Rechnung selbst über ihre Stände sagt: Anfangs- bzw. Endstand als geschätzt
     * ausgewiesen, der geschätzte Endstand laut Rechnung (keine Ablesung, geht nicht in den Verbrauch).
     *
     * @param array<string,mixed> $b gespeicherte Rechnung
     * @return array<string,mixed>
     */
    private function stated(string $u, array $b, bool $share): array
    {
        $flags = is_array($b['reading_estimated'] ?? null) ? $b['reading_estimated'] : null;
        $end = is_array($b['estimated_end_reading'] ?? null) ? $b['estimated_end_reading'] : null;
        return [
            'reading_estimated'     => $flags !== null ? ['start' => !empty($flags['start']), 'end' => !empty($flags['end'])] : null,
            'estimated_end_reading' => $end !== null && is_numeric($end['value'] ?? null)
                ? Num::of((float)$end['value'], (string)Utilities::get($u)['unit'], 'estimated', array_filter(['as_of' => is_string($end['on'] ?? null) ? $end['on'] : null])) : null,
        ];
    }

    /** Rechnungsnummer (Text bis 80 Zeichen, oft aus dem Dokument): nur mit `mcp_share_identifiers`, nur unter `untrusted`. */
    private static function invoiceNumber(array $b): ?string
    {
        return is_string($b['invoice_number'] ?? null) && $b['invoice_number'] !== '' ? $b['invoice_number'] : null;
    }

    /** @return array<string,mixed> */
    private function checkBill(array $args, McpContext $ctx): array
    {
        $this->textLocale = $ctx->locale;
        $u = (string)$args['utility'];
        $bills = $this->services->get(BillService::class);
        $share = $this->services->get(Config::class)->shareIdentifiers();
        $raw = $bills->get($u, (string)$args['bill_id']);
        $c = $bills->compare($u, (string)$args['bill_id']);
        $i18n = $this->services->get(I18nService::class);
        $cur = $this->currency();
        $unit = self::consumptionUnit($u);
        $qk = $u === 'wasser' ? 'm3' : 'kwh';
        $start = $c['boundaries']['start'] ?? null;
        $end = $c['boundaries']['end'] ?? null;
        // Menge der eigenen Rechnung: so gut wie die Stände an ihren Rändern
        $qtyBasis = Provenance::weakest(Provenance::boundary($start), Provenance::boundary($end));
        // Kosten: Menge mal eingetragener Preis (die schwächere Herkunft)
        $costBasis = Provenance::weakest($qtyBasis, 'entered');
        $o = $c['ours'];
        $d = $c['delta'];
        $ours = self::compact([
            'quantity'       => self::maybe($o[$qk] ?? null, fn($v) => Num::of($v, $unit, $qtyBasis, ['derived' => true])),
            'quantity_until' => is_string($o['quantity_until'] ?? null) ? $o['quantity_until'] : null,
            'energy_cost'    => self::maybe($o['energy_cost'] ?? null, fn($v) => Num::money($v, $cur, $costBasis, ['derived' => true])),
            'fixed_cost'     => self::maybe($o['fixed_cost'] ?? null, fn($v) => Num::money($v, $cur, 'entered', ['derived' => true])),
            'bonus'          => self::maybe($o['bonus'] ?? null, fn($v) => Num::money($v, $cur, 'entered')),
            'items_total'    => self::maybe($o['items_total'] ?? null, fn($v) => Num::money($v, $cur, 'entered')),
            'total'          => self::maybe($o['total'] ?? null, fn($v) => Num::money($v, $cur, $costBasis, ['derived' => true])),
            'advances'       => self::maybe($o['advances'] ?? null, fn($v) => Num::money($v, $cur, 'entered', ['derived' => true])),
        ]);
        $deltaBasis = $costBasis;
        $delta = self::compact([
            'quantity'     => self::maybe($d[$qk] ?? null, fn($v) => Num::of($v, $unit, $qtyBasis, ['derived' => true, 'decimals' => 1])),
            'quantity_pct' => self::maybe($d[$qk . '_pct'] ?? null, fn($v) => Num::of($v, '%', $qtyBasis, ['derived' => true, 'decimals' => 2])),
            'amount'       => self::maybe($d['eur'] ?? null, fn($v) => Num::money($v, $cur, $deltaBasis, ['derived' => true])),
            'amount_pct'   => self::maybe($d['eur_pct'] ?? null, fn($v) => Num::of($v, '%', $deltaBasis, ['derived' => true, 'decimals' => 2])),
        ]);
        $reasons = array_map(fn(string $k): array => ['code' => $k, 'text' => $i18n->t("billCheck.reason.$k")], (array)$c['reasons']);
        $rights = [];
        foreach ($this->rightsFor((string)$c['bill_id'], $ctx->today) as $f) $rights[] = $f;

        return self::compact([
            'bill_id'           => (string)$c['bill_id'],
            'utility'           => $u,
            'meter_id'          => (string)$c['meter_id'],
            'group_id'          => is_string($c['group_id'] ?? null) ? $c['group_id'] : null,
            'period_from'       => (string)$c['period_from'],
            'period_to'         => (string)$c['period_to'],
            'currency'          => $cur,
            'verdict'           => is_string($c['verdict'] ?? null) ? $c['verdict'] : null,
            'reasons'           => $reasons,
            'invoice'           => $this->invoice($u, (array)$c['invoice']),
            'ours'              => $ours,
            'delta'             => $delta,
            // v3.12.0 (Skeptiker N4) — ohne kWh auf der Rechnung gibt es keinen Vergleich der Menge: sagen, statt still wegzulassen
            'quantity_compare'  => $u !== 'wasser' && !is_numeric($c['invoice']['energy_kwh'] ?? null) && is_numeric($c['invoice']['volume_m3'] ?? null) ? 'invoice_in_m3_only' : null,
            'boundaries'        => self::compact([
                'start' => $start, 'end' => $end,
                'start_basis' => Provenance::boundary($start), 'end_basis' => Provenance::boundary($end),
            ]),
            'price_missing_at'  => array_map(fn(array $p): array => self::compact([
                'from' => (string)$p['from'], 'to' => (string)$p['to'],
                'contract_id' => is_string($p['contract_id'] ?? null) ? $p['contract_id'] : null,
                'meter_id' => is_string($p['meter_id'] ?? null) ? $p['meter_id'] : null,
                'untrusted' => Sanitize::untrusted(['provider' => is_string($p['provider'] ?? null) ? $this->masked($p['provider']) : null]),
            ]), (array)($c['price_missing_at'] ?? [])),
            'factor_mismatches' => array_map(fn(array $m): array => [
                'from'    => (string)$m['from'],
                'invoice' => Num::of((float)$m['invoice']['kwh_per_m3'], 'kWh/m³', 'entered', ['decimals' => 4]),
                'list'    => Num::of((float)$m['list']['kwh_per_m3'], 'kWh/m³', 'entered', ['decimals' => 4]),
            ], (array)($c['factor_mismatches'] ?? [])),
            'rights'            => $rights,
            'untrusted'         => Sanitize::untrusted(['invoice_number' => $share ? self::invoiceNumber($raw) : null]),
        ] + $this->stated($u, $raw, $share)) + ['reasons' => []];
    }

    /**
     * v3.11.0 (F1035) — Befunde „Deine Rechte als Kunde“ zu dieser Rechnung, auch
     * außerhalb des Fensters von „Zu tun“ (wie „Rechnung prüfen“). Titel und Text
     * können Namen aus den Daten tragen: nur unter `untrusted`.
     *
     * @return list<array<string,mixed>>
     */
    private function rightsFor(string $billId, string $today): array
    {
        if (!$this->services->has(RightsService::class)) return [];
        $rights = $this->services->get(RightsService::class);
        $out = [];
        foreach ($rights->findings($today) as $f) {
            if (($f['bill_id'] ?? null) === $billId) $out[] = $this->rightsRow($f);
        }
        return $out;
    }

    private static function listSchema(): array
    {
        return self::obj([
            'utility' => self::utilityArg(), 'currency' => self::str(),
            'bills' => self::arr(self::obj([
                'id' => self::str(), 'meter_id' => self::str(), 'contract_id' => self::str(), 'kind' => self::str(BillService::KINDS),
                'period_from' => self::date(), 'period_to' => self::date(), 'issued_on' => self::date(), 'received_on' => self::date(),
                'invoice' => self::invoiceSchema(), 'booked' => self::bool(), 'untrusted' => self::untrustedSchema('note', 'invoice_number'),
                'reading_estimated' => self::obj(['start' => self::bool(), 'end' => self::bool()], ['start', 'end']), 'estimated_end_reading' => Num::schema(),
            ], ['id', 'period_from', 'period_to', 'booked']), self::MAX_BILLS),
            'truncated' => self::bool(),
        ], ['utility', 'currency', 'bills']);
    }

    private static function invoiceSchema(): array
    {
        $num = Num::schema();
        return self::obj(['quantity' => $num, 'volume' => $num, 'amount' => $num, 'advances_paid' => $num, 'result' => $num]);
    }

    private static function checkSchema(): array
    {
        $num = Num::schema();
        $kinds = ['reading', 'reading_adjacent', 'reading_estimated', 'interpolated'];
        return self::obj([
            'bill_id' => self::str(), 'utility' => self::utilityArg(), 'meter_id' => self::str(), 'group_id' => self::str(),
            'period_from' => self::date(), 'period_to' => self::date(), 'currency' => self::str(),
            'verdict' => self::str(['ok', 'check', 'no_prices']),
            'reasons' => self::arr(self::obj(['code' => self::str(), 'text' => self::str()], ['code', 'text'])),
            'invoice' => self::invoiceSchema(),
            'ours' => self::obj(['quantity' => $num, 'quantity_until' => self::date(), 'energy_cost' => $num, 'fixed_cost' => $num,
                'bonus' => $num, 'items_total' => $num, 'total' => $num, 'advances' => $num]),
            'delta' => self::obj(['quantity' => $num, 'quantity_pct' => $num, 'amount' => $num, 'amount_pct' => $num]),
            'quantity_compare' => self::str(['invoice_in_m3_only']),
            'boundaries' => self::obj(['start' => self::str($kinds), 'end' => self::str($kinds),
                'start_basis' => self::basisSchema(), 'end_basis' => self::basisSchema()]),
            'price_missing_at' => self::arr(self::obj(['from' => self::date(), 'to' => self::date(), 'contract_id' => self::str(),
                'meter_id' => self::str(), 'untrusted' => self::untrustedSchema('provider')], ['from', 'to'])),
            'factor_mismatches' => self::arr(self::obj(['from' => self::date(), 'invoice' => $num, 'list' => $num], ['from', 'invoice', 'list'])),
            'rights' => self::rightsSchema(),
            'reading_estimated' => self::obj(['start' => self::bool(), 'end' => self::bool()], ['start', 'end']), 'estimated_end_reading' => $num,
            'untrusted' => self::untrustedSchema('invoice_number'),
        ], ['bill_id', 'utility', 'period_from', 'period_to', 'reasons']);
    }
}
