<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;

/**
 * v3.5.0 (F1029) — „Abrechnung erwartet“: Endet ein Abrechnungszeitraum eines
 * Vertrags und ist für ihn keine Versorgerrechnung erfasst, erwartet die App
 * eine Rechnung. Eine Stelle für Agenda, Verlauf und Rechnungserfassung.
 *
 * Welcher Zeitraum (je Vertrag nur der jüngste beendete):
 *   - Vertrag beendet (Ende vor heute, läuft nicht weiter): Schlussrechnung
 *     vom letzten Stichtag (`billing_cycle_anchor_<art>`) bzw. Vertragsbeginn
 *     bis zum Ende. Ein weiterlaufender Vertrag, den ein Folgevertrag ablöst,
 *     endet am Tag vor dessen Beginn. Schließt ein Folgevertrag desselben
 *     Versorgers lückenlos an, endet das Lieferverhältnis nicht — keine
 *     Schlussrechnung, es gilt der Stichtag.
 *   - Vertrag läuft (auch weiterlaufend): die zuletzt abgeschlossene Periode
 *     nach dem Stichtag, ab Vertragsbeginn. Kürzer als MIN_PERIOD_DAYS (der
 *     Vertrag begann kurz vor dem Stichtag): kein Hinweis — ob dafür eine
 *     eigene Rechnung kommt, legt der Versorger fest.
 *
 * Kein Hinweis für Zeiträume, die vor mehr als WINDOW_DAYS (120) Tagen endeten
 * (nicht rückwirkend für alte Jahre), die vor der ersten Ablesung bzw. dem ersten
 * Zeitraum des Zählers enden oder vor einer wirksamen Analyse-Zäsur
 * (`baseline_events`). Nur Arten mit Rechnungsprüfung
 * (Utilities::supportsBillCheck: Gas, Strom, Wasser, Fernwärme — nie Heizöl,
 * Pellets, Einspeisung), nur echte Verträge (nie Schattenverträge), nur Zähler
 * in Betrieb und Gruppen mit Gruppenvertrag (ContractService::targets()).
 *
 * Passende Rechnung (tolerant): am Zähler, an einem Mitglied der Gruppe oder an
 * der Gruppe, ohne oder mit diesem Vertrag, Rechnungsende höchstens
 * TOLERANCE_DAYS vom Periodenende entfernt — Versorger lesen selten genau am
 * Stichtag ab.
 *
 * Frist: Countries::billDeadlineDays() — in Deutschland für Strom und Gas
 * sechs Wochen nach dem Ende des Zeitraums bzw. der Belieferung (§ 40c Abs. 2
 * EnWG), gerechnet Ende + 42 Tage; danach „überfällig“ mit dem Hinweis auf die
 * gesetzliche Frist. Fernwärme (§ 4 Abs. 3 FFVAV), Wasser (§ 24 Abs. 1
 * AVBWasserV) und andere Länder kennen keine solche Frist: dort bleibt es bei
 * „erwartet“, ohne „gesetzlich“ und ohne erfundenen Erfahrungswert.
 */
final class BillExpectedService
{
    /** Rechnungsende so viele Tage vor oder nach dem Periodenende gilt als diese Abrechnung. */
    public const TOLERANCE_DAYS = 31;
    /**
     * Perioden, deren Ende länger her ist, erwartet die App nicht mehr — der
     * Hinweis verschwindet dann ohne Verlaufseintrag. 120 Tage = gesetzliche
     * Frist (42 Tage, § 40c Abs. 2 EnWG) plus gut elf Wochen Spielraum.
     * Bewusst kurz: Bestandsinstallationen erfassen Versorgerrechnungen bisher
     * kaum; mit einem Jahr sähen sie nach dem Update für fast jeden Vertrag
     * „überfällig“, obwohl die Rechnung längst da und nur nicht erfasst ist.
     */
    public const WINDOW_DAYS = 120;
    /** Kürzere Anfangszeiträume eines laufenden Vertrags lösen keinen Hinweis aus. */
    public const MIN_PERIOD_DAYS = 60;

    public function __construct(
        private SettingsService $settings,
        private MeterService $meters,
        private ContractService $contracts,
        private ReadingService $readings,
        private PeriodService $periods,
        private BillService $bills,
    ) {}

    /**
     * Offene Erwartungen: ohne passende Rechnung.
     *
     * @return list<array<string,mixed>>
     */
    public function open(?string $today = null): array
    {
        return array_values(array_filter($this->expected($today), fn($x) => $x['bill_id'] === null));
    }

    /**
     * Alle Erwartungen im Fenster, je mit `bill_id` der passenden Rechnung
     * (oder null): {uid, utility, target_id, meter_ids, is_group, contract_id,
     * provider, from, until, final, deadline, overdue, bill_id}.
     *
     * @return list<array<string,mixed>>
     */
    public function expected(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $country = (string)$this->settings->get('country', Countries::DEFAULT);
        $oldest = self::addDays($today, -self::WINDOW_DAYS);
        $out = [];
        foreach ($this->activeUtilities() as $u) {
            if (!Utilities::supportsBillCheck($u)) continue;
            $anchor = (string)$this->settings->get('billing_cycle_anchor_' . $u, '01-01');
            if (!SettingsService::isMonthDay($anchor)) $anchor = '01-01';
            $deadlineDays = Countries::billDeadlineDays($country, $u);
            $bills = null;
            $firstByMeter = null;
            foreach ($this->contracts->targets($u) as $target) {
                $tid = (string)$target['id'];
                $real = array_values(array_filter($this->contracts->list($u, $tid), fn($c) => empty($c['is_shadow'])));
                if ($real === []) continue;
                $meterIds = !empty($target['is_group']) ? array_map('strval', (array)($target['members'] ?? [])) : [$tid];
                foreach ($real as $c) {
                    $p = self::periodOf($c, $real, $anchor, $today);
                    if ($p === null) continue;
                    [$from, $until, $final] = $p;
                    if ($until < $oldest) continue;
                    // vor der ersten Ablesung bzw. dem ersten Zeitraum: nichts erwartet
                    $firstByMeter ??= $this->firstDataByMeter($u);
                    $first = null;
                    foreach ($meterIds as $mid) {
                        $d = $firstByMeter[$mid] ?? null;
                        if ($d !== null && ($first === null || $d < $first)) $first = $d;
                    }
                    if ($first === null || $first > $until) continue;
                    if ($this->baselineAfter($u, $meterIds, $until)) continue;
                    $bills ??= $this->bills->list($u);
                    $match = self::matchingBill($bills, $meterIds, $tid, (string)$c['id'], $until);
                    $deadline = $deadlineDays !== null ? self::addDays($until, $deadlineDays) : null;
                    $out[] = [
                        'uid'         => AgendaService::uid('bill_expected', (string)$c['id'], $until),
                        'utility'     => $u,
                        'target_id'   => $tid,
                        'meter_ids'   => $meterIds,
                        'is_group'    => !empty($target['is_group']),
                        'contract_id' => (string)$c['id'],
                        'provider'    => (string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? '') ?: '—'),
                        'from'        => $from,
                        'until'       => $until,
                        'final'       => $final,
                        'deadline'    => $deadline,
                        'overdue'     => $deadline !== null && $today > $deadline,
                        'bill_id'     => $match !== null ? (string)$match['id'] : null,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Die jüngste beendete Abrechnungsperiode eines Vertrags bis heute:
     * [von, bis (einschließlich), Schlussrechnung?] oder null.
     *
     * @param array<string,mixed>            $c
     * @param array<int,array<string,mixed>> $real echte Verträge desselben Ziels
     * @return array{0:string, 1:string, 2:bool}|null
     */
    public static function periodOf(array $c, array $real, string $anchor, string $today): ?array
    {
        $start = (string)($c['start'] ?? '');
        if (!Dates::isIsoDate($start) || !empty($c['is_shadow'])) return null;
        $end = (string)($c['end'] ?? '');
        $succ = ContractService::successorOf($c, $real);
        // Wirksames Ende: Ein Vertrag, der sich ohne Kündigung verlängert, läuft
        // bis zum Folgevertrag weiter — ohne Folgevertrag läuft er noch
        $effEnd = $end;
        if ($end !== '' && ContractService::renews($c)) {
            $effEnd = $succ === null ? '' : ((string)$succ['start'] > $end ? self::addDays((string)$succ['start'], -1) : $end);
        }
        if ($effEnd !== '' && $effEnd < $today) {
            // Derselbe Versorger, lückenlos: Das Lieferverhältnis endet nicht
            if ($succ !== null && self::sameProvider($c, $succ) && (string)$succ['start'] <= self::addDays($effEnd, 1)) return null;
            [$from] = TenancyBudgetService::periodAround($anchor, $effEnd);
            return [max($from, $start), $effEnd, true];
        }
        // läuft: die zuletzt abgeschlossene Periode nach dem Stichtag
        [$current] = TenancyBudgetService::periodAround($anchor, $today);
        $until = self::addDays($current, -1);
        if ($start > $until) return null;
        [$from] = TenancyBudgetService::periodAround($anchor, $until);
        $from = max($from, $start);
        if (self::days($from, $until) + 1 < self::MIN_PERIOD_DAYS) return null;
        return [$from, $until, false];
    }

    /**
     * Die Rechnung, die eine Periode abdeckt (nächstes Rechnungsende), oder null.
     *
     * @param list<array<string,mixed>> $bills    Versorgerrechnungen der Art
     * @param list<string>              $meterIds Zähler des Ziels (bei einer Gruppe die Mitglieder)
     */
    public static function matchingBill(array $bills, array $meterIds, string $targetId, string $contractId, string $until): ?array
    {
        $best = null;
        $bestGap = null;
        foreach ($bills as $b) {
            if (!is_array($b)) continue;
            $mid = (string)($b['meter_id'] ?? '');
            if ($mid !== $targetId && !in_array($mid, $meterIds, true)) continue;
            $cid = (string)($b['contract_id'] ?? '');
            if ($cid !== '' && $cid !== $contractId) continue;
            $to = (string)($b['period_to'] ?? '');
            if (!Dates::isIsoDate($to)) continue;
            $gap = abs(self::days($to, $until));
            if ($gap > self::TOLERANCE_DAYS) continue;
            if ($best === null || $gap < $bestGap) {
                $best = $b;
                $bestGap = $gap;
            }
        }
        return $best;
    }

    /** Katalogschlüssel des Titels: Jahres- oder Schlussrechnung, erwartet oder überfällig. */
    public static function titleKey(array $x, bool $withOverdue = true): string
    {
        $over = $withOverdue && !empty($x['overdue']);
        return 'calendar.event.' . (!empty($x['final'])
            ? ($over ? 'finalBillOverdue' : 'finalBillExpected')
            : ($over ? 'billOverdue' : 'billExpected'));
    }

    /** @return array<string,string> Bausteine des Titels (Tage als JJJJ-MM-TT) */
    public static function params(array $x, I18nService $i18n): array
    {
        return ['label' => $i18n->utilityLabel((string)$x['utility']), 'provider' => (string)$x['provider'],
                'from' => (string)$x['from'], 'until' => (string)$x['until']];
    }

    /** Sprung zum Erfassen: Rechnung prüfen mit Zeitraum, Abschnitt „Rechnung des Versorgers“ offen. */
    public static function href(array $x): string
    {
        return '#/bill-check?' . http_build_query([
            'utility' => (string)$x['utility'], 'meter' => (string)($x['meter_ids'][0] ?? $x['target_id']),
            'from' => (string)$x['from'], 'until' => (string)$x['until'], 'capture' => '1',
        ]);
    }

    // ── intern ───────────────────────────────────────────────────────────

    private static function sameProvider(array $c, array $succ): bool
    {
        $a = mb_strtolower(trim((string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? ''))));
        $b = mb_strtolower(trim((string)$succ['provider']));
        return $a !== '' && $a === $b;
    }

    /**
     * Tag der ersten Ablesung (gültig, nicht geplant, nicht verdächtig) bzw.
     * Beginn des ersten Zeitraums je Zähler einer Art.
     *
     * @return array<string,string>
     */
    private function firstDataByMeter(string $u): array
    {
        $first = [];
        $take = function (string $mid, string $d) use (&$first): void {
            if (Dates::isIsoDate($d) && (!isset($first[$mid]) || $d < $first[$mid])) $first[$mid] = $d;
        };
        foreach ($this->readings->list($u) as $r) {
            if (!empty($r['is_future']) || !empty($r['is_suspect'])) continue;
            $take((string)($r['meter_id'] ?? ''), (string)($r['date'] ?? ''));
        }
        foreach ($this->periods->list($u) as $p) $take((string)($p['meter_id'] ?? ''), (string)($p['from'] ?? ''));
        return $first;
    }

    /** Liegt die Periode vor einer wirksamen Analyse-Zäsur eines der Zähler? */
    private function baselineAfter(string $u, array $meterIds, string $until): bool
    {
        foreach ($meterIds as $mid) {
            $m = $this->meters->get($u, $mid);
            $b = $m !== null ? MeterService::activeBaselineDate($m) : null;
            if ($b !== null && $b > $until) return true;
        }
        return false;
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }

    private static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Tage von $a bis $b (negativ, wenn $b vorher liegt). */
    private static function days(string $a, string $b): int
    {
        return (int)(new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->format('%r%a');
    }
}
