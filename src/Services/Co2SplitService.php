<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\Pdf\PdfWriter;

/**
 * v3.1.0 (Paket H4, MKT-15) — CO₂-Kosten zwischen Mieter und Vermieter teilen
 * (CO2KostAufG). Eine Hilfsrechnung, keine Rechtsberatung.
 *
 * Zwei Fälle:
 *   self_supplied  Etagenheizung mit eigenem Gas-/Heizölvertrag: Der Mieter
 *                  rechnet den Vermieteranteil selbst aus und fordert ihn binnen
 *                  12 Monaten nach der Abrechnung des Lieferanten in Textform ein
 *                  (§ 6 Abs. 2). Emissionen und Kosten aus der Versorgerrechnung,
 *                  sonst gerechnet (Co2CostService), mit Umsatzsteuer.
 *                  Kürzungen: eigene Geräte wie ein Gasherd −5 % (§ 6 Abs. 3),
 *                  öffentlich-rechtliche Vorgaben (§ 9) halbieren den Anteil bzw.
 *                  heben ihn auf — am Mietverhältnis eingetragen.
 *   central        Zentralheizung: Der Vermieter weist Stufe und Anteil in der
 *                  Heizkostenabrechnung aus (§ 7). Die App rechnet aus Emissionen
 *                  und Fläche nach und meldet Abweichungen (`checks`).
 *
 * Nur zur Miete (`wohnverhaeltnis = miete`) und in Ländern mit Schema (DE).
 */
final class Co2SplitService
{
    public function __construct(
        private Co2CostService $costs,
        private TenancyService $tenancies,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /** @return array<string,mixed> */
    public function forYear(int $year): array
    {
        $scheme = Countries::co2Scheme((string)$this->settings->get('country', 'DE'));
        if ($scheme === null || $this->settings->get('wohnverhaeltnis', 'eigentum') !== 'miete') {
            return ['supported' => false, 'year' => $year, 'note' => $this->i18n->t('co2split.notSupported')];
        }
        $t = $this->tenancyIn($year);
        $area = (float)(($t['wohnflaeche_m2'] ?? null) ?: $this->settings->get('wohnflaeche_m2', 0));
        $base = ['supported' => true, 'year' => $year, 'tenancy_id' => $t['id'] ?? null, 'area_m2' => $area > 0 ? $area : null,
                 'checks' => [], 'reductions' => [], 'deadline' => null];

        // Zentralheizung: Angaben aus der Abrechnung des Vermieters prüfen
        foreach ($t !== null ? $this->tenancies->statements((string)$t['id']) : [] as $s) {
            if ((int)substr((string)$s['period_to'], 0, 4) !== $year || !is_array($s['co2'] ?? null)) continue;
            $co2 = $s['co2'];
            $days = (int)round((strtotime((string)$s['period_to']) - strtotime((string)$s['period_from'])) / 86400) + 1;
            $em = (float)($co2['emissions_kg'] ?? 0);
            $cost = (float)($co2['cost_eur'] ?? 0);
            if ($em <= 0 || $area <= 0) {
                return $base + ['case' => 'central', 'source' => 'statement', 'statement_id' => $s['id'],
                    'checks' => ['missing_values'], 'emissions_kg' => $em ?: null, 'co2_cost_eur' => $cost ?: null];
            }
            $kg = round($em / $area, 1);
            [$stage, $share] = Co2CostService::stage($kg, $days);
            $amount = round($cost * $share / 100, 2);
            $checks = [];
            if (isset($co2['stage']) && (int)$co2['stage'] !== $stage) $checks[] = 'stage';
            if (isset($co2['landlord_share_pct']) && (int)$co2['landlord_share_pct'] !== $share) $checks[] = 'share';
            if (isset($co2['landlord_amount_eur']) && abs((float)$co2['landlord_amount_eur'] - $amount) > 0.5) $checks[] = 'amount';
            return array_merge($base, [
                'case' => 'central', 'source' => 'statement', 'statement_id' => $s['id'], 'days' => $days,
                'emissions_kg' => round($em, 1), 'kg_per_m2' => $kg, 'stage' => $stage, 'landlord_share_pct' => $share,
                'co2_cost_eur' => round($cost, 2), 'landlord_amount_eur' => $amount, 'checks' => $checks,
                'stated' => array_intersect_key($co2, array_flip(['stage', 'landlord_share_pct', 'landlord_amount_eur'])),
            ]);
        }

        // Etagenheizung: selbst rechnen (Gas, Heizöl)
        $costs = $this->costs->forYear($year);
        $rows = array_values(array_filter($costs['rows'] ?? [], fn($r) => in_array($r['utility'], ['gas', 'heizoel'], true)));
        if ($rows === []) return $base + ['case' => null, 'note' => $this->i18n->t('co2split.noData')];
        $em = array_sum(array_column($rows, 'emissions_kg'));
        $cost = array_sum(array_column($rows, 'cost_eur_gross'));
        if ($area <= 0) return $base + ['case' => 'self_supplied', 'emissions_kg' => round($em, 1), 'co2_cost_eur' => round($cost, 2),
            'checks' => ['area_missing']];
        $kg = round($em / $area, 1);
        [$stage, $share] = Co2CostService::stage($kg);
        $factor = 1.0;
        $reductions = [];
        if (!empty($t['co2_own_appliances'])) { $factor *= 0.95; $reductions[] = 'own_appliances'; }
        $restriction = (string)($t['co2_restriction'] ?? 'none');
        if ($restriction === 'one') { $factor *= 0.5; $reductions[] = 'restriction_one'; }
        if ($restriction === 'both') { $factor = 0.0; $reductions[] = 'restriction_both'; }
        return array_merge($base, [
            'case' => 'self_supplied', 'source' => implode(',', array_unique(array_column($rows, 'source'))),
            'emissions_kg' => round($em, 1), 'kg_per_m2' => $kg, 'stage' => $stage, 'landlord_share_pct' => $share,
            'co2_cost_eur' => round($cost, 2), 'landlord_amount_eur' => round($cost * $share / 100 * $factor, 2),
            'reductions' => $reductions, 'price' => $costs['price'] ?? null,
            'utilities' => array_column($rows, 'utility'),
            'deadline' => $this->costs->claimDeadline($year),   // H5: Zugang der Gasrechnung + 12 Monate
        ]);
    }

    /** Frist für die Erstattung (Etagenheizung), ohne die ganze Rechnung — für die Agenda. */
    public function claimDeadline(int $year): ?string
    {
        return $this->costs->claimDeadline($year);
    }

    /** Anschreiben an den Vermieter (Etagenheizung) bzw. Prüfergebnis (Zentralheizung) als PDF. */
    public function letterPdf(int $year): string
    {
        $r = $this->forYear($year);
        $pdf = new PdfWriter();
        $pdf->addPage();
        $x = 56.0; $y = 70.0; $w = $pdf->pageWidth() - 2 * $x;
        $eur = fn(?float $v): string => $v === null ? '–' : $this->i18n->money($v);
        $num = fn(?float $v, int $d = 1): string => $v === null ? '–' : $this->i18n->number($v, $d);
        $para = function (string $text, float $size = 10.5, bool $bold = false) use ($pdf, $x, $w, &$y): void {
            foreach ($this->wrap($pdf, $text, $size, $bold, $w) as $line) { $pdf->text($x, $y, $line, $size, $bold); $y += $size * 1.45; }
            $y += 6;
        };
        $t = !empty($r['tenancy_id']) ? $this->tenancies->get((string)$r['tenancy_id']) : null;
        $para($this->i18n->t('co2split.letter.title'), 15, true);
        $para($this->i18n->t('co2split.letter.subtitle', ['year' => $year, 'label' => (string)($t['label'] ?? '') ?: '—']));
        if (empty($r['supported']) || empty($r['stage'])) {
            $para((string)($r['note'] ?? $this->i18n->t('co2split.noData')));
        } else {
            $rows = [
                [$this->i18n->t('co2split.letter.emissions'), $num($r['emissions_kg']) . ' kg CO₂'],
                [$this->i18n->t('co2split.letter.area'), $num($r['area_m2']) . ' m²'],
                [$this->i18n->t('co2split.letter.perM2'), $num($r['kg_per_m2']) . ' kg/(m²·a)'],
                [$this->i18n->t('co2split.letter.stage'), (string)$r['stage']],
                [$this->i18n->t('co2split.letter.share'), $r['landlord_share_pct'] . ' %'],
                [$this->i18n->t('co2split.letter.cost'), $eur($r['co2_cost_eur'])],
            ];
            foreach ($r['reductions'] as $red) $rows[] = [$this->i18n->t('co2split.reduction.' . $red), ''];
            $rows[] = [$this->i18n->t('co2split.letter.amount'), $eur($r['landlord_amount_eur'])];
            foreach ($rows as $i => [$label, $value]) {
                $bold = $i === count($rows) - 1;
                $pdf->text($x, $y, $label, 10.5, $bold);
                $pdf->textRight($x + $w, $y, $value, 10.5, $bold);
                $y += 17;
            }
            $y += 8;
            $para($this->i18n->t($r['case'] === 'central' ? 'co2split.letter.bodyCentral' : 'co2split.letter.body'));
            foreach ($r['checks'] as $c) $para('• ' . $this->i18n->t('co2split.check.' . $c));
        }
        $y += 12;
        $para($this->i18n->t('co2split.disclaimer'), 9);
        return $pdf->output();
    }

    /** @return list<string> */
    private function wrap(PdfWriter $pdf, string $text, float $size, bool $bold, float $width): array
    {
        $lines = []; $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && $pdf->textWidth($try, $size, $bold) > $width) { $lines[] = $line; $line = $word; }
            else $line = $try;
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    /** Das Mietverhältnis, das im Jahr galt (das jüngste). */
    private function tenancyIn(int $year): ?array
    {
        foreach ($this->tenancies->list() as $t) {
            if ((string)$t['start'] > "$year-12-31" || (!empty($t['end']) && (string)$t['end'] < "$year-01-01")) continue;
            return $t;
        }
        return null;
    }
}
