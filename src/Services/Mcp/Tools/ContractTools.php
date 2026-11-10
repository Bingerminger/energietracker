<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\RightsService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket lesen) — `get_contracts`: Verträge und ihr Stand je Zähler
 * oder Gruppe (Konzept §3.2), aus derselben Zeile wie
 * `GET …/meters/{id}/contract-status` (ConsumptionService::contractStatus mit
 * ContractService::withPhases): Laufzeit, Fristen, Preise (eingetragen oder
 * angenommen), Saldo bis heute aus Teilen, Saldo zur letzten Ablesung (gemessen),
 * erwarteter Saldo und Abschlagsvorschlag (geschätzt, mit Spanne). Angebote zum
 * Vergleich (Schattenverträge) fehlen, abgelaufene nur auf Wunsch. Vertrags- und
 * Kundennummer nur mit `mcp_share_identifiers`.
 */
final class ContractTools extends ToolGroup
{
    use ReadSupport;

    /** `notice_basis` nach dem Gesetz (angenommen) bzw. aus Ende und Frist des Eintrags (ContractService::switchTiming) */
    private const NOTICE_BY_LAW = ['renewal_term', 'basic_supply'];
    private const NOTICE_ENTERED = ['fixed_end', 'open_ended', 'min_term'];

    public function tools(): array
    {
        $keys = array_values(array_filter(Utilities::keys(), [Utilities::class, 'hasContracts']));
        return [new Tool('get_contracts', Tool::READ, self::obj([
            'utility'      => self::utilityArg($keys),
            'meter'        => self::meterArg(),
            'include_past' => ['type' => 'boolean', 'default' => false],
        ], ['utility']), fn(array $a, McpContext $c): array => $this->contracts($a, $c), self::outputSchema(),
            ['readOnlyHint' => true, 'idempotentHint' => true])];
    }

    /** @return array<string,mixed> */
    private function contracts(array $args, McpContext $ctx): array
    {
        $u = (string)$args['utility'];
        if (!Utilities::hasContracts($u)) {
            throw new LocalizedException('errors.mcp.noContracts', ['utility' => $u], 'mcp contracts');
        }
        $past = ($args['include_past'] ?? false) === true;
        $this->textLocale = $ctx->locale;
        $consumption = $this->services->get(ConsumptionService::class);
        $service = $this->services->get(ContractService::class);
        $share = $this->services->get(Config::class)->shareIdentifiers();
        $raw = array_column($service->list($u), null, 'id');
        $targets = isset($args['meter']) ? [$this->target($u, (string)$args['meter'])] : $consumption->contractTargets($u);
        // v3.11.0 (F1035) — Rechte-Hinweise zum Vertrag: die im Fenster wie „Zu tun“ und Empfehlungen
        $rights = [];
        if ($this->services->has(RightsService::class)) {
            foreach ($this->services->get(RightsService::class)->findings($ctx->today) as $f) {
                if (!empty($f['todo']) && is_string($f['contract_id'] ?? null) && ($f['utility'] ?? null) === $u) $rights[$f['contract_id']][] = $this->rightsRow($f);
            }
        }

        $out = [];
        foreach ($targets as $t) {
            $status = $service->withPhases($u, $consumption->contractStatus($u, $t, $ctx->today), $ctx->today);
            $rows = [];
            foreach ($status['contracts'] ?? [] as $c) {
                $phase = (string)($c['phase'] ?? '');
                // Angebote zum Vergleich (Schattenverträge) kennt contractStatus gar nicht
                if ($phase === 'past' && !$past) continue;
                $cid = (string)($c['contract_id'] ?? '');
                $rows[] = $this->contract($u, $c, $raw[$cid] ?? [], $t, $ctx->today, $share) + (isset($rights[$cid]) ? ['rights' => $rights[$cid]] : []);
            }
            $group = is_array($status['group_contract'] ?? null) ? $status['group_contract'] : null;
            $out[] = self::compact([
                'target'         => ['id' => (string)$t['id'], 'is_group' => !empty($t['is_group'])],
                'untrusted'      => Sanitize::untrusted(['name' => $this->masked((string)($t['name'] ?? ''))]),
                // Mitglied einer Gruppe mit Gruppenvertrag: Saldo und Abschläge stehen an der Gruppe
                'group_contract' => $group !== null && is_string($group['group_id'] ?? null) ? ['group_id' => $group['group_id']] : null,
            ]) + ['contracts' => $rows];
        }
        return ['utility' => $u, 'as_of' => $ctx->today, 'currency' => $this->currency(), 'targets' => $out];
    }

    /**
     * @param array<string,mixed> $c   Zeile aus contractStatus (mit phase)
     * @param array<string,mixed> $raw gespeicherter Vertrag (Kennnummern)
     * @param array<string,mixed> $target Zähler oder Gruppe
     * @return array<string,mixed>
     */
    private function contract(string $u, array $c, array $raw, array $target, string $today, bool $share): array
    {
        $current = !empty($c['is_current']);
        $succ = is_array($c['successor'] ?? null) ? $c['successor'] : null;
        return self::compact([
            'contract_id'           => (string)($c['contract_id'] ?? ''),
            'phase'                 => (string)($c['phase'] ?? ''),
            'untrusted'             => Sanitize::untrusted(['provider' => $this->masked((string)($c['provider'] ?? '')), 'tariff' => $this->masked((string)($c['tariff_name'] ?? '')),
                'contract_number' => $share && is_string($raw['contract_number'] ?? null) ? $raw['contract_number'] : null,
                'customer_number' => $share && is_string($raw['customer_number'] ?? null) ? $raw['customer_number'] : null]),
            'supply_kind'           => is_string($raw['supply_kind'] ?? null) ? $raw['supply_kind'] : null,
            'start'                 => $c['start'] ?? null,
            'end'                   => $c['end'] ?? null,
            'settle_on'             => $c['settle_on'] ?? null,
            'auto_renews'           => (bool)($c['auto_renews'] ?? true),
            'renewed'               => !empty($c['renewed']) ? true : null,
            'min_term_end'          => $c['min_term_end'] ?? null,
            'price_guarantee_until' => $c['price_guarantee_until'] ?? null,
            'notice_basis'          => is_string($c['notice_basis'] ?? null) ? $c['notice_basis'] : null,
            'notice_source'         => self::noticeSource($c),
            'cancel_by'             => $c['cancel_by'] ?? null,
            'switch_date'           => $c['switch_date'] ?? null,
            'cancel_missed'         => !empty($c['cancel_missed']) ? true : null,
            'renewal_term'          => self::renewalTerm($c),
            'price_increase_from'   => is_string($c['price_increase']['from'] ?? null) ? $c['price_increase']['from'] : null,
            'price_increase'        => $this->priceIncreaseRow($u, $c),
            // v3.12.0 (Skeptiker N13) — wie die App: Stichtag, Vorschlag aus der letzten Rechnung und bis wann sie reichte
            'billing_period'        => self::billingPeriodHint($c),
            'successor'             => $succ !== null ? ['contract_id' => (string)$succ['contract_id'], 'start' => (string)$succ['start'],
                'gap_days' => (int)$succ['gap_days'], 'gap_covered' => !empty($succ['gap_covered'])] : null,
            'price_missing'         => !empty($c['price_missing']) ? true : null,
            // v3.12.0 (Skeptiker N2) — auch vergangene Verträge: ihre Preise bis zuletzt
            'prices'                => $this->contractPrices($u, $c, $current || in_array($c['phase'] ?? '', ['renewed', 'future'], true) ? $today : self::lastDay($c, $today)),
            'status'                => $this->contractStatusNums($u, $c, $target),
        ]);
    }

    /** Tag, zu dem die Preise eines vergangenen Vertrags gelten: sein letzter. */
    private static function lastDay(array $c, string $today): string
    {
        $end = is_string($c['end'] ?? null) ? $c['end'] : '';
        return $end !== '' && $end < $today ? $end : $today;
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,string>|null
     */
    private static function billingPeriodHint(array $c): ?array
    {
        $p = is_array($c['billing_period'] ?? null) ? $c['billing_period'] : null;
        if ($p === null) return null;
        return self::compact(array_filter(['anchor' => $p['anchor'] ?? null, 'suggested_anchor' => $p['suggested_anchor'] ?? null,
            'suggested_anchor_source' => $p['suggested_anchor_source'] ?? null, 'last_bill_until' => $p['last_bill_until'] ?? null], 'is_string')) ?: null;
    }

    /**
     * v3.12.0 (integration) — Woher die Frist kommt: aus dem Eintrag (Ende, Kündigungsfrist) oder
     * angenommen nach dem Gesetz (§ 32 AVBFernwärmeV ohne eingetragene Frist, Grundversorgung nach
     * § 20 StromGVV/GasGVV); ein abweichender Vertrag geht vor. Ohne Frist null.
     *
     * @param array<string,mixed> $c
     */
    private static function noticeSource(array $c): ?string
    {
        $basis = (string)($c['notice_basis'] ?? '');
        if (is_array($c['renewal_term'] ?? null) || in_array($basis, self::NOTICE_BY_LAW, true)) return 'assumed';
        return in_array($basis, self::NOTICE_ENTERED, true) ? 'entered' : null;
    }

    /**
     * v3.11.0 (Fernwärme, § 32 AVBFernwärmeV) — die Verlängerung um fünf Jahre, die ohne Kündigung
     * folgt bzw. läuft: bis wann und bis wann zu kündigen ist. Niemand hat sie eingetragen, sie gilt
     * nach dem Gesetz: `basis: assumed`. Der schon verlängerte Vertrag nennt sie nur über
     * `notice_basis: renewal_term`, `switch_date` (Tag danach) und `cancel_by` (wie die Vertragskarte).
     *
     * @param array<string,mixed> $c
     * @return array{until:string, cancel_by:string, basis:string}|null
     */
    private static function renewalTerm(array $c): ?array
    {
        $t = $c['renewal_term'] ?? null;
        if (is_array($t) && is_string($t['until'] ?? null) && is_string($t['cancel_by'] ?? null)) {
            return ['until' => $t['until'], 'cancel_by' => $t['cancel_by'], 'basis' => 'assumed'];
        }
        if (($c['notice_basis'] ?? null) !== 'renewal_term' || !is_string($c['switch_date'] ?? null) || !is_string($c['cancel_by'] ?? null)) return null;
        return ['until' => date('Y-m-d', (int)strtotime($c['switch_date'] . ' -1 day')), 'cancel_by' => $c['cancel_by'], 'basis' => 'assumed'];
    }

    private static function outputSchema(): array
    {
        return self::obj([
            'utility' => self::utilityArg(), 'as_of' => self::date(), 'currency' => self::str(),
            'targets' => self::arr(self::obj([
                'target'    => self::obj(['id' => self::str(), 'is_group' => self::bool()], ['id', 'is_group']),
                'untrusted' => self::untrustedSchema('name'),
                'contracts' => self::arr(self::obj([
                    'contract_id' => self::str(), 'phase' => self::str(ContractService::PHASES),
                    'untrusted' => self::untrustedSchema('provider', 'tariff', 'contract_number', 'customer_number'),
                    'supply_kind' => self::str(ContractService::SUPPLY_KINDS),
                    'start' => self::date(), 'end' => self::date(), 'settle_on' => self::date(), 'auto_renews' => self::bool(),
                    'renewed' => self::bool(), 'min_term_end' => self::date(), 'price_guarantee_until' => self::date(),
                    'notice_basis' => self::str(), 'notice_source' => self::str(['entered', 'assumed']),
                    'cancel_by' => self::date(), 'switch_date' => self::date(), 'cancel_missed' => self::bool(),
                    'renewal_term' => self::obj(['until' => self::date(), 'cancel_by' => self::date(), 'basis' => self::str(['assumed'])], ['until', 'cancel_by', 'basis']),
                    'price_increase_from' => self::date(), 'price_increase' => self::priceIncreaseSchema(),
                    'billing_period' => self::obj(['anchor' => self::str(), 'suggested_anchor' => self::str(), 'suggested_anchor_source' => self::str(), 'last_bill_until' => self::str()]),
                    'successor' => self::obj(['contract_id' => self::str(), 'start' => self::date(), 'gap_days' => self::int(), 'gap_covered' => self::bool()],
                        ['contract_id', 'start', 'gap_days', 'gap_covered']),
                    'price_missing' => self::bool(),
                    'prices' => self::pricesSchema(), 'status' => self::statusSchema(), 'rights' => self::rightsSchema(),
                ], ['contract_id', 'phase', 'auto_renews'])),
                'group_contract' => self::obj(['group_id' => self::str()], ['group_id']),
            ], ['target', 'contracts'])),
        ], ['utility', 'as_of', 'currency', 'targets']);
    }
}
