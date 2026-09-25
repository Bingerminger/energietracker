<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Config\Utilities;

/**
 * v1.3.0 — PDF-Jahresbericht.
 *
 * Erzeugt einen mehrseitigen A4-Bericht (Deckblatt, Übersicht/Effizienz,
 * je Verbrauchsart eine Seite mit Tabelle + Mini-Liniendiagramm,
 * Empfehlungen) mit dem dependency-freien PdfWriter. Keine externe
 * Library, kein gd/mbstring nötig.
 *
 * v2.7.0 — Zahlen, Beträge, Daten und Monate in der Schreibweise von Sprache
 * und Land (I18nService::number/money/date/month). Vorher stand hier fest
 * number_format(…, ',', '.') und „€" — ein englischer Bericht zeigte
 * „10.359 kWh", ein Schweizer „€" statt CHF.
 */
final class PdfReportService
{
    private const M = 48.0;     // Seitenrand
    private const INK    = [30, 41, 59];
    private const MUTE   = [100, 116, 139];
    private const RULE   = [203, 213, 225];
    private const ACCENT = [37, 99, 235];

    public function __construct(
        private MeterService $meters,
        private ConsumptionService $consumption,
        private SettingsService $settings,
        private BenchmarkService $benchmark,
        private RecommendationService $recommendations,
        private I18nService $i18n,
    ) {}

    /**
     * Lokalisierter Verbrauchsart-Name. v2.2.0: der Fallback liegt jetzt
     * zentral in I18nService::utilityLabel() — vorher trug jeder Konsument
     * seine eigene Kopie, und zwei hatten sie schlicht nicht.
     */
    private function utilLabel(string $utility): string
    {
        return $this->i18n->utilityLabel($utility);
    }

    public function build(int $year): string
    {
        $pdf = new PdfWriter(false);
        $W = $pdf->pageWidth();

        // ── Seite 1 — Deckblatt ──
        $pdf->addPage();
        $loc = (string)$this->settings->get('location_name', '');
        $pdf->rect(0, 0, $W, 180, self::ACCENT);
        $pdf->text(self::M, 80, 'ENERGIETRACKER', 14, true, [255, 255, 255]);
        $pdf->text(self::M, 120, $this->i18n->t('report.title', ['year' => $year]), 30, true, [255, 255, 255]);
        $pdf->text(self::M, 150, $loc, 12, false, [255, 255, 255]);
        $pdf->text(self::M, 240, $this->i18n->t('report.createdOn', ['date' => $this->i18n->date(date('Y-m-d'))]), 10, false, self::MUTE);
        $pdf->text(self::M, 760, 'Energietracker v' . trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')), 8, false, self::MUTE);

        // ── Seite 2 — Übersicht & Effizienz ──
        $pdf->addPage();
        $y = $this->header($pdf, $this->i18n->t('report.overview', ['year' => $year]), $W);
        $eff = $this->benchmark->efficiency($year);

        $y += 10;
        $perSource = $eff['per_source'] ?? [];
        // v2.7.0 — ohne Effizienzskala des Landes keine Klasse (BenchmarkService)
        $hasScale = ($eff['scale'] ?? null) !== null;
        $perM2 = fn(array $s) => $this->i18n->number((float)($s['kwh_per_m2'] ?? 0), 0) . ' kWh/m²·a';
        if (!empty($perSource)) {
            if (count($perSource) === 1) {
                $s = $perSource[0];
                $pdf->rect(self::M, $y, $W - 2 * self::M, 70, [241, 245, 249]);
                if ($hasScale) {
                    $pdf->text(self::M + 16, $y + 24, $this->i18n->t('report.efficiencyClass', ['label' => (string)$s['label']]), 11, false, self::MUTE);
                    $pdf->text(self::M + 16, $y + 54, (string)($s['class'] ?? '–'), 26, true, self::ACCENT);
                    $pdf->textRight($W - self::M - 16, $y + 30, $perM2($s), 13, true, self::INK);
                } else {
                    $pdf->text(self::M + 16, $y + 24, $this->i18n->t('report.heatDemand', ['label' => (string)$s['label']]), 11, false, self::MUTE);
                    $pdf->text(self::M + 16, $y + 54, $perM2($s), 22, true, self::ACCENT);
                }
                $pdf->textRight($W - self::M - 16, $y + 52, $this->i18n->t('report.livingArea', ['area' => (int)$eff['wohnflaeche_m2']]), 9, false, self::MUTE);
                $y += 90;
            } else {
                $pdf->text(self::M, $y + 8, $this->i18n->t('report.efficiencyPerSource'), 12, true, self::INK);
                $y += 28;
                foreach ($perSource as $s) {
                    $pdf->text(self::M + 8, $y, (string)$s['label'], 10, false, self::INK);
                    if ($hasScale) {
                        $pdf->text(self::M + 180, $y, $this->i18n->t('report.class', ['class' => (string)($s['class'] ?? '–')]), 10, true, self::ACCENT);
                    }
                    $pdf->textRight($W - self::M - 8, $y, $perM2($s), 10, false, self::INK);
                    $y += 18;
                }
                $y += 12;
            }
            // v2.10.0 (CALC-07) — zweite Zahl: energieausweis-nah (Heizwert,
            // witterungsbereinigt, Gebäudenutzfläche); Hinweis bei Teiljahren
            if (!empty($eff['certificate'])) {
                $c = $eff['certificate'];
                $line = $this->i18n->t('report.certificate', [
                    'value' => $this->i18n->number((float)$c['kwh_per_m2'], 0),
                    'area'  => $this->i18n->number((float)$c['area_m2'], 0),
                ]) . ($hasScale && !empty($c['class']) ? ' · ' . $this->i18n->t('report.class', ['class' => (string)$c['class']]) : '');
                $pdf->text(self::M, $y, $line, 9, false, self::MUTE);
                $y += 16;
            }
            if (in_array(false, array_column($perSource, 'complete'), true) && !empty($eff['note'])) {
                foreach ($this->wrap((string)$eff['note'], 100) as $ln) {
                    $pdf->text(self::M, $y, $ln, 8, false, self::MUTE);
                    $y += 12;
                }
                $y += 6;
            }
            if (!$hasScale && !empty($eff['scale_note'])) {
                foreach ($this->wrap((string)$eff['scale_note'], 100) as $ln) {
                    $pdf->text(self::M, $y, $ln, 8, false, self::MUTE);
                    $y += 12;
                }
                $y += 12;
            }
        } else {
            $pdf->text(self::M, $y + 20, (string)($eff['note'] ?? $this->i18n->t('report.noEfficiencyData')), 10, false, self::MUTE);
            $y += 50;
        }

        // Kosten/CO2-Übersicht je aktiver Utility
        $pdf->text(self::M, $y, $this->i18n->t('report.consumptionCosts'), 12, true, self::INK);
        $y += 24;
        $cols = [self::M, self::M + 160, self::M + 290, self::M + 410];
        $pdf->text($cols[0], $y, $this->i18n->t('report.colUtility'), 9, true, self::MUTE);
        $pdf->text($cols[1], $y, $this->i18n->t('report.colConsumption'), 9, true, self::MUTE);
        $pdf->text($cols[2], $y, $this->i18n->t('report.colCost'), 9, true, self::MUTE);
        $pdf->text($cols[3], $y, $this->i18n->t('report.colCo2'), 9, true, self::MUTE);
        $y += 6;
        $pdf->line(self::M, $y, $W - self::M, $y, 0.5, self::RULE);
        $y += 16;

        // v2.10.0 (Review CALC-17) — PV nach ihrer Bedeutung: Einspeisung ist
        // ein Erlös, keine Kosten; Erzeugung emittiert nichts, sie vermeidet.
        // Die vermiedene Menge steht einmal — bei der Erzeugung, wenn es sie
        // gibt (die Einspeisung ist ein Teil davon), sonst bei der Einspeisung.
        $active = $this->activeUtilities();
        $hasGeneration = in_array('pv_erzeugung', $active, true)
            && $this->yearAggregate('pv_erzeugung', $year)['kwh'] > 0;
        foreach ($active as $utility) {
            $agg = $this->yearAggregate($utility, $year);
            if ($agg['kwh'] <= 0 && $agg['m3'] <= 0) continue;
            $u = Utilities::get($utility);
            $kind = Utilities::accountingKind($utility);
            $valStr = $u['consumption_unit'] === 'kWh'
                ? $this->i18n->number($agg['kwh'], 0) . ' kWh'
                : $this->i18n->number($agg['m3'], 1) . ' m³';
            $avoided = $this->i18n->t('report.co2Avoided', ['kg' => $this->i18n->number($agg['co2'], 0)]);
            $costStr = match ($kind) {
                'feed_in'    => $this->i18n->t('report.revenueValue', ['amount' => $this->i18n->money($agg['cost'])]),
                'generation' => '–',
                default      => $this->i18n->money($agg['cost']),
            };
            $co2Str = match ($kind) {
                'generation' => $avoided,
                'feed_in'    => $hasGeneration ? $this->i18n->t('report.co2InGeneration') : $avoided,
                default      => $this->i18n->number($agg['co2'], 0) . ' kg',
            };
            $pdf->text($cols[0], $y, $this->utilLabel($utility), 10, false, self::INK);
            $pdf->text($cols[1], $y, $valStr, 10, false, self::INK);
            $pdf->text($cols[2], $y, $costStr, 10, false, self::INK);
            $pdf->text($cols[3], $y, $co2Str, 10, false, self::INK);
            $y += 20;
        }

        // ── Pro Verbrauchsart eine Seite ──
        foreach ($this->activeUtilities() as $utility) {
            $meters = $this->meters->list($utility);
            foreach ($meters as $meter) {
                // v2.9.0 (CALC-15) — auch außer Betrieb: Die Seite erscheint
                // für jedes Jahr, in dem der Zähler Verbrauch hatte
                $monthly = $this->consumption->forMeter($utility, $meter);
                $monthly = array_values(array_filter($monthly, fn($m) => (int)($m['year'] ?? 0) === $year));
                if (empty($monthly)) continue;
                $this->utilityPage($pdf, $utility, $meter, $monthly, $year, $W);
            }
        }

        // ── Letzte Seite — Empfehlungen ──
        $pdf->addPage();
        $y = $this->header($pdf, $this->i18n->t('report.recommendations'), $W);
        $recs = $this->recommendations->all();
        if (empty($recs)) {
            $pdf->text(self::M, $y + 20, $this->i18n->t('report.noRecommendations'), 10, false, self::MUTE);
        } else {
            $y += 14;
            foreach (array_slice($recs, 0, 12) as $r) {
                if ($y > 780) { $pdf->addPage(); $y = $this->header($pdf, $this->i18n->t('report.recommendationsCont'), $W) + 14; }
                // v2.7.0 — Stufe aus dem Katalog statt des englischen Schlüssels
                $sev = $this->i18n->t('recommendations.sev.' . (string)$r['severity']);
                $col = $r['severity'] === 'urgent' ? [220,38,38] : ($r['severity'] === 'warning' ? [217,119,6] : self::MUTE);
                $pdf->text(self::M, $y, '[' . $sev . ']', 8, true, $col);
                $pdf->text(self::M + 70, $y, (string)$r['title'], 10, true, self::INK);
                $y += 16;
                foreach ($this->wrap((string)$r['detail'], 95) as $ln) {
                    $pdf->text(self::M + 70, $y, $ln, 9, false, self::MUTE);
                    $y += 13;
                }
                $y += 8;
            }
        }

        return $pdf->output();
    }

    private function utilityPage(PdfWriter $pdf, string $utility, array $meter, array $monthly, int $year, float $W): void
    {
        $u = Utilities::get($utility);
        $label = $this->utilLabel($utility);
        $pdf->addPage();
        $y = $this->header($pdf, $this->i18n->t('report.meterPageTitle', [
            'label' => $label, 'year' => $year, 'meter' => (string)($meter['name'] ?? ''),
        ]), $W);
        $isKwh = $u['consumption_unit'] === 'kWh';
        $vf = $isKwh ? 'kwh' : 'm3';
        $unit = $isKwh ? 'kWh' : 'm³';

        // v1.4.2 — Kennzahlen-Leiste statt achsenlosem Mini-Chart.
        // Verdichtet den Jahresverlauf in belastbare Eckwerte.
        $vals = array_map(fn($m) => (float)($m[$vf] ?? 0), $monthly);
        $sum  = array_sum($vals);
        $cnt  = max(1, count(array_filter($monthly, fn($m) => (float)($m[$vf] ?? 0) > 0)));
        $avg  = $sum / $cnt;
        $cost = array_sum(array_map(fn($m) => (float)($m['cost'] ?? 0), $monthly));
        $maxM = null; $minM = null;
        foreach ($monthly as $m) {
            $v = (float)($m[$vf] ?? 0);
            if ($v <= 0) continue;
            if ($maxM === null || $v > (float)($maxM[$vf] ?? 0)) $maxM = $m;
            if ($minM === null || $v < (float)($minM[$vf] ?? 0)) $minM = $m;
        }
        $nf = fn($v, $d = 0) => $this->i18n->number((float)$v, $d);
        // v2.10.0 (CALC-17) — Einspeisung: Erlös statt Kosten; Erzeugung: keine Geldspalte
        $kind = Utilities::accountingKind($utility);
        $kpis = [
            [$this->i18n->t('report.kpiAnnual'),    $nf($sum, $isKwh ? 0 : 1) . ' ' . $unit],
            [$this->i18n->t('report.kpiAvgMonth'),  $nf($avg, $isKwh ? 0 : 1) . ' ' . $unit],
        ];
        if ($kind === 'feed_in') $kpis[] = [$this->i18n->t('report.kpiRevenue'), $this->i18n->money($cost)];
        elseif ($kind !== 'generation') $kpis[] = [$this->i18n->t('report.kpiTotalCost'), $this->i18n->money($cost)];
        $kpis[] = [$this->i18n->t('report.kpiPeakMonth'), $maxM ? ($this->i18n->month((string)$maxM['ym']) . ' · ' . $nf($maxM[$vf], $isKwh ? 0 : 1)) : '–'];
        $kpis[] = [$this->i18n->t('report.kpiLowMonth'),  $minM ? ($this->i18n->month((string)$minM['ym']) . ' · ' . $nf($minM[$vf], $isKwh ? 0 : 1)) : '–'];
        $ky = $y + 6;
        $kw = ($W - 2 * self::M) / count($kpis);
        foreach ($kpis as $i => [$lab, $val]) {
            $kx = self::M + $i * $kw;
            $pdf->text($kx, $ky, $lab, 8, false, self::MUTE);
            $pdf->text($kx, $ky + 18, $val, 11, true, self::INK);
        }
        $y = $ky + 40;
        $pdf->line(self::M, $y, $W - self::M, $y, 0.5, self::RULE);
        $y += 20;

        // Monatstabelle
        $headers = [
            $this->i18n->t('report.tableMonth'),
            $isKwh ? 'kWh' : 'm³',
            $kind === 'feed_in' ? $this->i18n->t('report.tableRevenue') : $this->i18n->t('report.tableCost'),
            $this->i18n->t('report.tableTemp'),
            $this->i18n->t('report.tableHdd'),
        ];
        // v2.8.0 (Review CALC-05) — die neuen Felder: witterungsbereinigt nach
        // dem Heizmodell (`heat_adjusted`) und Abweichung von der Erwartung bei
        // diesem Wetter (`weather_delta_pct`). Das alte `delta_pct` zeigte
        // jeden Januar als +71 %.
        $hasWa = $this->anyKey($monthly, 'heat_adjusted') || $this->anyKey($monthly, 'weather_delta_pct');
        if ($hasWa) { $headers[] = $this->i18n->t('report.tableWeatherAdj'); $headers[] = $this->i18n->t('report.tableDelta'); }
        $colX = [self::M];
        $cwTab = $W - 2 * self::M;
        $step = $cwTab / count($headers);
        for ($i = 1; $i < count($headers); $i++) $colX[$i] = self::M + $i * $step;

        foreach ($headers as $i => $h) $pdf->text($colX[$i], $y, $h, 9, true, self::MUTE);
        $y += 6;
        $pdf->line(self::M, $y, $W - self::M, $y, 0.5, self::RULE);
        $y += 14;

        foreach ($monthly as $m) {
            if ($y > 790) { $pdf->addPage(); $y = $this->header($pdf, $label . ' ' . $this->i18n->t('report.continued'), $W) + 14; }
            $row = [
                $this->i18n->month((string)($m['ym'] ?? '')),
                $nf($m[$vf] ?? 0, $isKwh ? 0 : 1),
                $kind === 'generation' ? '–' : $nf($m['cost'] ?? 0, 2),
                $m['avg_temp'] !== null ? $nf($m['avg_temp'], 1) : '–',
                $nf($m['hdd'] ?? 0, 0),
            ];
            if ($hasWa) {
                $row[] = ($m['heat_adjusted'] ?? null) !== null ? $nf($m['heat_adjusted'], 0) : '–';
                $row[] = ($m['weather_delta_pct'] ?? null) !== null
                    ? ((float)$m['weather_delta_pct'] < 0 ? '-' : '+') . $nf(abs((float)$m['weather_delta_pct']), 0)
                    : '–';
            }
            foreach ($row as $i => $v) $pdf->text($colX[$i], $y, $v, 9, false, self::INK);
            $y += 16;
        }
        if ($hasWa) {
            $y += 4;
            foreach ($this->wrap($this->i18n->t('report.weatherNote'), 110) as $ln) {
                $pdf->text(self::M, $y, $ln, 7, false, self::MUTE);
                $y += 10;
            }
        }
    }

    private function header(PdfWriter $pdf, string $title, float $W): float
    {
        $pdf->text(self::M, 56, $title, 16, true, self::INK);
        $pdf->line(self::M, 70, $W - self::M, 70, 1.0, self::ACCENT);
        return 92.0;
    }

    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => Utilities::exists($k)));
    }

    private function yearAggregate(string $utility, int $year): array
    {
        $kwh = $m3 = $cost = $co2 = 0.0;
        foreach ($this->meters->list($utility) as $meter) {
            // v2.1.3 — F1006: Subzähler nicht mitzählen; der Elternzähler trägt
            // den Brutto-Verbrauch bereits inklusive (sonst Doppelzählung,
            // analog ConsumptionService::forUtility-Gesamtsumme).
            // v2.9.0 (CALC-15) — Zähler außer Betrieb zählen mit ihrer Historie.
            if (!MeterService::countsInTotals($meter)) continue;
            foreach ($this->consumption->forMeter($utility, $meter) as $m) {
                if ((int)($m['year'] ?? 0) !== $year) continue;
                $kwh  += (float)($m['kwh'] ?? 0);
                $m3   += (float)($m['m3'] ?? 0);
                $cost += (float)($m['cost'] ?? 0);
                $co2  += (float)($m['co2_kg'] ?? 0);
            }
        }
        return ['kwh' => $kwh, 'm3' => $m3, 'cost' => $cost, 'co2' => $co2];
    }

    private function anyKey(array $rows, string $key): bool
    {
        foreach ($rows as $r) if (($r[$key] ?? null) !== null) return true;
        return false;
    }

    /** @return string[] */
    private function wrap(string $s, int $width): array
    {
        $words = explode(' ', $s);
        $lines = []; $cur = '';
        foreach ($words as $w) {
            if (strlen($cur . ' ' . $w) > $width) { $lines[] = trim($cur); $cur = $w; }
            else { $cur .= ' ' . $w; }
        }
        if (trim($cur) !== '') $lines[] = trim($cur);
        return $lines;
    }
}
