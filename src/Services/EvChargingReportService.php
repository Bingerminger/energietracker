<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Http\NotFoundException;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H6, MKT-14) — Ladestrom-Nachweis für den Dienstwagen
 * (BMF-Schreiben vom 11.11.2025): je Monat die geladene Menge am eigenen
 * Zähler der Wallbox und ihr Preis. Eine Aufstellung, keine Steuerberatung.
 *
 *   contract  Preis des zahlenden Vertrags (Rn. 28): hat die Wallbox einen
 *             eigenen Vertrag (§ 14a Modul 2), dessen Monatswerte; sonst der
 *             Vertrag des Elternzählers (F1006) — Arbeitspreis des Monats
 *             (Arbeitskosten ÷ kWh, tagesgenau) plus Grundpreis anteilig nach
 *             kWh (Wallbox ÷ Elternzähler). Die Aufteilung nach kWh ist eine
 *             Annahme; das Schreiben sagt nur „anteilig".
 *   flat      Strompreispauschale je Jahr (Rn. 30; 2026: 34, 2027: 32 ct/kWh,
 *             Countries::EV_FLAT_RATE_DE) × kWh.
 */
final class EvChargingReportService
{
    public const METHODS = ['contract', 'flat'];

    public function __construct(
        private MeterService $meters,
        private ReadingService $readings,
        private ConsumptionService $consumption,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /** @return array<string,mixed> */
    public function report(string $meterId, int $year, string $method = 'contract', ?float $flatCt = null): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new LocalizedException('errors.evReport.methodInvalid', ['value' => $method], 'ev method');
        }
        $meter = $this->meters->get('strom', $meterId)
            ?? throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        $own = array_values(array_filter($this->consumption->forMeter('strom', $meter), fn($r) => (int)($r['year'] ?? 0) === $year));
        $readings = array_values(array_filter($this->readings->list('strom', $meterId),
            fn($r) => empty($r['is_future']) && str_starts_with((string)($r['date'] ?? ''), "$year-")));
        usort($readings, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

        $payer = null; $payerRows = [];
        $flat = null;
        if ($method === 'flat') {
            $flat = $flatCt ?? self::flatRate((string)$this->settings->get('country', 'DE'), $year);
            if ($flat === null) throw new LocalizedException('errors.evReport.flatMissing', ['year' => $year], 'ev flat rate');
        } else {
            [$contracts] = $this->consumption->contractScope('strom', $meter);
            if ($contracts !== []) {
                $payer = $meter; $payerRows = array_column($own, null, 'ym');
            } elseif (!empty($meter['parent_meter_id']) && ($parent = $this->meters->get('strom', (string)$meter['parent_meter_id'])) !== null) {
                [$pc] = $this->consumption->contractScope('strom', $parent);
                if ($pc !== []) { $payer = $parent; $payerRows = array_column($this->consumption->forMeter('strom', $parent), null, 'ym'); }
            }
            if ($payer === null) throw new LocalizedException('errors.evReport.noContract', [], 'ev without contract');
        }

        $rows = []; $tot = ['kwh' => 0.0, 'amount_eur' => 0.0];
        $missing = false;
        foreach ($own as $r) {
            $ym = (string)$r['ym'];
            $kwh = (float)($r['kwh'] ?? 0);
            $price = null; $baseShare = 0.0;
            if ($method === 'flat') {
                $price = $flat;
            } else {
                $p = $payerRows[$ym] ?? null;
                if ($p !== null && !empty($p['contract_id']) && (float)($p['kwh'] ?? 0) > 0) {
                    $price = (float)$p['kwh_cost'] / (float)$p['kwh'] * 100;
                    $base = (float)($p['base_price_eur'] ?? 0);
                    // v3.2.0 — höchstens der ganze Grundpreis: Lädt PV hinter dem
                    // Hauszähler, misst die Wallbox mehr, als aus dem Netz kam
                    $baseShare = $payer === $meter ? $base : $base * min(1.0, $kwh / (float)$p['kwh']);
                }
            }
            if ($price === null) $missing = true;
            $amount = $price !== null ? $kwh * $price / 100 + $baseShare : null;
            $inMonth = array_values(array_filter($readings, fn($x) => str_starts_with((string)$x['date'], $ym)));
            $rows[] = [
                'ym' => $ym, 'kwh' => round($kwh, 2), 'price_ct' => $price !== null ? round($price, 4) : null,
                'base_share_eur' => round($baseShare, 2), 'amount_eur' => $amount !== null ? round($amount, 2) : null,
                'estimated' => (int)($r['estimated_days'] ?? 0) > 0,
                'first_reading' => $inMonth ? ['date' => (string)$inMonth[0]['date'], 'counter' => (float)$inMonth[0]['counter']] : null,
                'last_reading'  => $inMonth ? ['date' => (string)end($inMonth)['date'], 'counter' => (float)end($inMonth)['counter']] : null,
            ];
            $tot['kwh'] += $kwh;
            $tot['amount_eur'] += $amount ?? 0.0;
        }
        $device = null;
        foreach ($meter['devices'] ?? [] as $d) if (empty($d['removed_on'])) $device = $d;
        return [
            'meter_id' => $meterId, 'meter_name' => (string)($meter['name'] ?? $meterId), 'serial' => $device['serial'] ?? null,
            'role' => \Energietracker\Config\Utilities::roleOf('strom', $meter),
            'year' => $year, 'method' => $method, 'flat_ct' => $flat,
            'payer_meter_id' => $payer['id'] ?? null,
            'rows' => $rows,
            'total' => ['kwh' => round($tot['kwh'], 2), 'amount_eur' => round($tot['amount_eur'], 2)],
            'price_missing' => $missing,
        ];
    }

    /**
     * Strompreispauschale eines Jahres in ct/kWh aus dem Länderprofil (DE: 2026 = 34,
     * 2027 = 32), sonst null — auch für ein Jahr, dessen Wert Destatis noch nicht
     * veröffentlicht hat: Die Pauschale wird jedes Jahr neu gebildet, der Vorjahreswert
     * gilt nicht weiter.
     */
    public static function flatRate(string $country, int $year): ?float
    {
        $map = (array)(Countries::get($country)['ev_flat_rate_ct_years'] ?? []);
        return isset($map[$year]) && is_numeric($map[$year]) ? (float)$map[$year] : null;
    }

    /** Aufstellung als PDF: Zähler, Monate mit Ständen, Menge, Preis, Betrag, Summe, Unterschrift. */
    public function pdf(array $r): string
    {
        $pdf = new PdfWriter();
        $pdf->addPage();
        $x = 50.0; $y = 64.0; $w = $pdf->pageWidth() - 2 * $x;
        $num = fn(?float $v, int $d = 2): string => $v === null ? '–' : $this->i18n->number($v, $d);
        $eur = fn(?float $v): string => $v === null ? '–' : $this->i18n->money($v);
        $pdf->text($x, $y, $this->i18n->t('evReport.pdf.title', ['year' => $r['year']]), 15, true);
        $y += 24;
        foreach ([
            [$this->i18n->t('evReport.pdf.meter'), $r['meter_name'] . ($r['serial'] ? ' · ' . $r['serial'] : '')],
            [$this->i18n->t('evReport.pdf.method'), $this->i18n->t('evReport.method.' . $r['method'])
                . ($r['method'] === 'flat' ? ' (' . $num($r['flat_ct'], 0) . ' ct/kWh)' : '')],
        ] as [$k, $v]) {
            $pdf->text($x, $y, $k, 10, true);
            $pdf->text($x + 140, $y, (string)$v, 10);
            $y += 15;
        }
        $y += 10;
        // v3.4.0 (F1025, Mangel 9) — Spalten mit fester Breite, Köpfe umbrochen:
        // „Cent/kWhGrundpreis-Anteil“ stieß zusammen, sobald ein Kopf breiter war
        // als geschätzt. Linke Spalten: Monat, Zählerstände; rechtsbündig: kWh,
        // Preis, Grundpreis-Anteil, Betrag (rechte Kante und Breite je Spalte).
        $right = [[$this->i18n->t('evReport.col.kwh'), 230.0, 48.0], [$this->i18n->t('evReport.col.price'), 300.0, 62.0],
                  [$this->i18n->t('evReport.col.baseShare'), 400.0, 92.0], [$this->i18n->t('evReport.col.amount'), $w, 80.0]];
        $head = [[$this->i18n->t('evReport.col.month'), 0.0, 60.0, false], [$this->i18n->t('evReport.col.readings'), 62.0, 120.0, false]];
        foreach ($right as [$label, $edge, $cw]) $head[] = [$label, $edge, $cw, true];
        $rows = 1;
        foreach ($head as [$label, , $cw]) $rows = max($rows, count($pdf->wrap($label, 8.5, $cw, true)));
        foreach ($head as [$label, $off, $cw, $alignRight]) {
            foreach ($pdf->wrap($label, 8.5, $cw, true) as $k => $line) {
                $alignRight ? $pdf->textRight($x + $off, $y + $k * 10, $line, 8.5, true) : $pdf->text($x + $off, $y + $k * 10, $line, 8.5, true);
            }
        }
        $y += ($rows - 1) * 10 + 6;
        $pdf->line($x, $y, $x + $w, $y);
        $y += 14;
        foreach ($r['rows'] as $row) {
            if ($y > $pdf->pageHeight() - 90) { $pdf->addPage(); $y = 64.0; }
            $reads = $row['first_reading'] && $row['last_reading']
                ? [$this->i18n->date($row['first_reading']['date']) . ' ' . $num($row['first_reading']['counter'], 1),
                   $this->i18n->date($row['last_reading']['date']) . ' ' . $num($row['last_reading']['counter'], 1)]
                : ['–'];
            $pdf->text($x, $y, $this->i18n->month((string)$row['ym']), 9);
            foreach ($reads as $k => $line) $pdf->text($x + 62, $y + $k * 9.5, $line, 7.5);
            $pdf->textRight($x + 230, $y, $num($row['kwh'], 1), 9);
            $pdf->textRight($x + 300, $y, $num($row['price_ct'], 2), 9);
            $pdf->textRight($x + 400, $y, $eur($row['base_share_eur']), 9);
            $pdf->textRight($x + $w, $y, $eur($row['amount_eur']), 9);
            $y += count($reads) > 1 ? 22 : 15;
        }
        $pdf->line($x, $y - 8, $x + $w, $y - 8);
        $y += 4;
        $pdf->text($x, $y, $this->i18n->t('evReport.col.total'), 10, true);
        $pdf->textRight($x + 230, $y, $num($r['total']['kwh'], 1), 10, true);
        $pdf->textRight($x + $w, $y, $eur($r['total']['amount_eur']), 10, true);
        $y += 40;
        foreach ([$this->i18n->t('evReport.pdf.basis'), $this->i18n->t('evReport.disclaimer')] as $p) {
            if ($y > $pdf->pageHeight() - 90) { $pdf->addPage(); $y = 64.0; }
            foreach ($pdf->wrap($p, 9, $w) as $line) { $pdf->text($x, $y, $line, 9); $y += 13; }
            $y += 6;
        }
        if ($y > $pdf->pageHeight() - 80) { $pdf->addPage(); $y = 64.0; }
        $y += 30;
        $pdf->line($x, $y, $x + 220, $y);
        $pdf->text($x, $y + 12, $this->i18n->t('evReport.pdf.signature'), 8);
        return $pdf->output();
    }
}
