<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;

/**
 * Tarif-Was-wäre-wenn (Schattenverträge) — v2.2.0 neu aufgesetzt.
 *
 * Wendet jeden Vertrag eines Zählers (echt + Schatten) auf die TATSÄCHLICH
 * gemessenen historischen Monatsverbräuche an und stellt die fiktiven Kosten
 * gegenüber. Beantwortet: „Was hätte ich bei Tarif X bezahlt?"
 *
 * ── Was sich gegenüber v1.3.0 geändert hat ──────────────────────────────
 *
 * Die alte Fassung meldete für JEDE Zeile den Gesamtverbrauch des Zeitraums,
 * berechnete die Kosten aber nur über die Monate, in denen der Vertrag lief.
 * Ein Schattenvertrag ab Juli zeigte damit den vollen Jahresverbrauch neben
 * einem halben Jahr Kosten — und wirkte doppelt so günstig, wie er ist. Ebenso
 * verglich `vs_real_eur` die Teilzeitraum-Kosten gegen die Jahressumme aller
 * echten Verträge.
 *
 * Jetzt gilt durchgängig: **Jede Kennzahl einer Zeile bezieht sich auf genau
 * die Monate, die dieser Vertrag abdeckt.** Zusätzlich:
 *
 *   - `unit_cost_ct` — Vollkosten je Verbrauchseinheit (Arbeitspreis, Grundpreis
 *     und Boni zusammen, geteilt durch die Menge). Das ist die einzige
 *     zeitraum-unabhängige Größe und damit der faire Vergleichsmaßstab
 *     zwischen unterschiedlich langen Laufzeiten.
 *   - `projected_full_eur` — die Teilzeitraum-Kosten auf die volle gewählte
 *     Periode hochgerechnet, damit „was hätte das ganze Jahr gekostet?"
 *     beantwortbar bleibt, ohne die Ist-Zahlen zu verfälschen.
 *   - `vs_real_eur` / `vs_real_pct` — Differenz gegen die real abgerechneten
 *     Kosten **derselben Monate**.
 *
 * ── Bewusst NICHT enthalten ─────────────────────────────────────────────
 *
 * Sonderzahlungen (F1003) und Abschläge sind Zahlungsströme gegen den Saldo,
 * keine Tarifkosten: Eine Rückzahlung entsteht, weil die Abschläge zu hoch
 * waren, nicht weil der Tarif günstiger ist. Sie in die Vergleichskosten zu
 * mischen würde zwei verschiedene Fragen vermengen. Der Vergleich zeigt reine
 * Tarifkosten (Arbeitspreis × Menge + Grundpreis − Bonus); der Saldo lebt
 * unverändert in `ConsumptionService::contractStatus()`.
 *
 * Wasser bleibt ausgenommen (Drei-Komponenten-Tarifmodell, eigene UI nötig);
 * Heizöl/Pellets sind lieferbasiert und haben keine Verträge — dort ist die
 * Lieferrechnung die Kostenbasis.
 */
final class TariffComparisonService
{
    public function __construct(
        private ConsumptionService $consumption,
        private ContractService $contracts,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /**
     * @return array{
     *   utility: string,
     *   meter_id: string,
     *   unit: string,
     *   period: array{from: ?string, to: ?string, label: string, months: int},
     *   supported: bool,
     *   note: ?string,
     *   real_total_eur: ?float,
     *   rows: array<int, array{
     *     contract_id: string, label: string, is_shadow: bool,
     *     provider: string, tariff_name: string,
     *     months_covered: int, covers_full_period: bool,
     *     consumption: float, total_eur: ?float, unit_cost_ct: ?float,
     *     projected_full_eur: ?float,
     *     vs_real_eur: ?float, vs_real_pct: ?float
     *   }>
     * }
     */
    public function compare(string $utility, string $meterId, ?int $year = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }

        $u    = Utilities::get($utility);
        $unit = (string)($u['consumption_unit'] ?? 'kWh');

        if ($utility === 'wasser') {
            return $this->emptyResult($utility, $meterId, $unit, '—', false,
                $this->i18n->t('errors.tariff.waterUnsupported'));
        }
        if (Utilities::isDelivery($utility)) {
            return $this->emptyResult($utility, $meterId, $unit, '—', false,
                $this->i18n->t('errors.tariff.deliveryUnsupported', ['label' => $u['label']]));
        }

        // Rohe Monatsreihe. `cost` trägt hier bereits die real abgerechneten
        // Kosten (ConsumptionService wendet die echten Verträge an) — daraus
        // bauen wir die Referenzlinie.
        $monthly = $this->consumption->forMeter($utility, $meter);
        if (empty($monthly)) {
            return $this->emptyResult($utility, $meterId, $unit, '—', true,
                $this->i18n->t('errors.tariff.noConsumption'));
        }

        if ($year !== null) {
            $monthly = array_values(array_filter($monthly, fn($m) => (int)($m['year'] ?? 0) === $year));
            $label   = (string)$year;
        } else {
            $label = $this->i18n->t('tariff.wholePeriod');
        }
        if (empty($monthly)) {
            return $this->emptyResult($utility, $meterId, $unit, $label, true,
                $this->i18n->t('errors.tariff.noConsumptionInPeriod'));
        }

        $totalMonths = count($monthly);
        $from = $monthly[0]['ym'] ?? null;
        $to   = $monthly[$totalMonths - 1]['ym'] ?? null;

        // Real abgerechnete Kosten je Monat — Referenz für den Soll-Ist-Vergleich.
        $realByYm = [];
        foreach ($monthly as $m) {
            if (isset($m['cost'])) $realByYm[(string)$m['ym']] = (float)$m['cost'];
        }
        $realTotal = $realByYm ? array_sum($realByYm) : null;

        $rows = [];
        foreach ($this->contracts->list($utility, $meterId) as $c) {
            $calc = $this->calculateForContract($c, $monthly);
            if ($calc['months'] === 0) {
                // Vertrag liegt komplett außerhalb des gewählten Zeitraums —
                // eine Zeile mit lauter „–" ist nur Rauschen.
                continue;
            }

            $isShadow = !empty($c['is_shadow']);
            $rowLabel = $isShadow
                ? (string)($c['shadow_label'] ?: $c['tariff_name'] ?: $this->i18n->t('tariff.shadowFallbackLabel'))
                : trim(((string)($c['provider'] ?? '')) . ' ' . ((string)($c['tariff_name'] ?? '')));
            if ($rowLabel === '') $rowLabel = (string)$c['id'];

            // Referenz über GENAU die Monate dieses Vertrags — nicht über die
            // gesamte Periode. Sonst vergleicht ein Halbjahresvertrag seine
            // sechs Monate gegen zwölf reale.
            $realSame = null;
            foreach ($calc['yms'] as $ym) {
                if (isset($realByYm[$ym])) $realSame = ($realSame ?? 0.0) + $realByYm[$ym];
            }

            $total   = $calc['total'];
            $consump = $calc['consumption'];

            $rows[] = [
                'contract_id'        => (string)$c['id'],
                'label'              => $rowLabel,
                'is_shadow'          => $isShadow,
                'provider'           => (string)($c['provider'] ?? ''),
                'tariff_name'        => (string)($c['tariff_name'] ?? ''),
                'months_covered'     => $calc['months'],
                'covers_full_period' => $calc['months'] >= $totalMonths,
                'consumption'        => round($consump, 1),
                'total_eur'          => $total !== null ? round($total, 2) : null,
                // Vollkosten je Einheit in ct — zeitraumunabhängig, damit
                // unterschiedlich lange Laufzeiten vergleichbar bleiben.
                'unit_cost_ct'       => ($total !== null && $consump > 0)
                                        ? round($total * 100.0 / $consump, 3) : null,
                // Hochrechnung auf die volle Periode; bei Vollabdeckung ist sie
                // identisch mit total_eur und wird vom Frontend ausgeblendet.
                'projected_full_eur' => ($total !== null && $calc['months'] > 0)
                                        ? round($total / $calc['months'] * $totalMonths, 2) : null,
                'vs_real_eur'        => ($total !== null && $realSame !== null)
                                        ? round($total - $realSame, 2) : null,
                'vs_real_pct'        => ($total !== null && $realSame !== null && $realSame != 0.0)
                                        ? round(($total - $realSame) / $realSame * 100.0, 1) : null,
            ];
        }

        // Sortierung: echte Verträge zuerst (die Ist-Linie), dann die
        // Hypothesen nach effektivem Einheitspreis — die faire Rangfolge.
        // Zeilen ohne rechenbaren Preis ans Ende.
        usort($rows, function ($a, $b) {
            if ($a['is_shadow'] !== $b['is_shadow']) return $a['is_shadow'] <=> $b['is_shadow'];
            $au = $a['unit_cost_ct'] ?? PHP_FLOAT_MAX;
            $bu = $b['unit_cost_ct'] ?? PHP_FLOAT_MAX;
            if ($au !== $bu) return $au <=> $bu;
            return ($a['total_eur'] ?? PHP_FLOAT_MAX) <=> ($b['total_eur'] ?? PHP_FLOAT_MAX);
        });

        return [
            'utility'        => $utility,
            'meter_id'       => $meterId,
            'unit'           => $unit,
            'period'         => ['from' => $from, 'to' => $to, 'label' => $label, 'months' => $totalMonths],
            'supported'      => true,
            'note'           => $rows ? null : $this->i18n->t('errors.tariff.noContracts'),
            'real_total_eur' => $realTotal !== null ? round($realTotal, 2) : null,
            'rows'           => $rows,
        ];
    }

    /**
     * Kosten, Menge und Laufzeit eines Vertrags über die Monatsreihe.
     *
     * Nur Monate, in denen der Vertrag aktiv war, zählen — für Kosten UND
     * Menge. Monate ohne gepflegten Arbeitspreis bleiben außen vor, weil sie
     * die Kosten nicht rechenbar machen; sie dürfen dann auch nicht in die
     * Menge einfließen, sonst sinkt der Einheitspreis künstlich.
     *
     * @return array{total: ?float, consumption: float, months: int, yms: array<int,string>}
     */
    private function calculateForContract(array $c, array $monthly): array
    {
        $total = 0.0;
        $consumption = 0.0;
        $yms = [];

        foreach ($monthly as $m) {
            $ym = (string)($m['ym'] ?? '');
            if ($ym === '') continue;
            if (!$this->contracts->findActiveForDate([$c], $ym . '-01')) continue;

            $y  = (int)($m['year'] ?? 0);
            $mn = (int)($m['month'] ?? 0);
            $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
            if ($wp === null) continue; // ohne Arbeitspreis nicht rechenbar

            $bp = $this->contracts->valueValidOn($c['base_prices'] ?? [], 'eur_per_month', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $value = (float)($m['kwh'] ?? 0);
            $total += $value * $wp / 100.0 + (float)($bp ?? 0) - $bn;
            $consumption += $value;
            $yms[] = $ym;
        }

        return [
            'total'       => $yms ? $total : null,
            'consumption' => $consumption,
            'months'      => count($yms),
            'yms'         => $yms,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyResult(
        string $utility, string $meterId, string $unit,
        string $label, bool $supported, string $note
    ): array {
        return [
            'utility'        => $utility,
            'meter_id'       => $meterId,
            'unit'           => $unit,
            'period'         => ['from' => null, 'to' => null, 'label' => $label, 'months' => 0],
            'supported'      => $supported,
            'note'           => $note,
            'real_total_eur' => null,
            'rows'           => [],
        ];
    }
}
