<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Services\Pdf\Chart;
use Energietracker\Services\Pdf\Metrics;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Config\Utilities;

/**
 * v1.3.0 — PDF-Jahresbericht.
 *
 * v2.7.0 — Zahlen, Beträge, Daten und Monate in der Schreibweise von Sprache
 * und Land (I18nService::number/money/date/month).
 *
 * v3.4.0 (F1025) — „Jahresbericht für alle“: Der Dienst setzt nur noch das
 * Layout; was im Bericht steht, liefert ReportModelService (dieselbe Quelle wie
 * die Druckansicht). Reihenfolge für Menschen ohne Technikhintergrund: Das
 * Wichtigste (große Zahlen, vier Ampeln, drei Aufgaben) · Was hat sich gelohnt
 * · Was ist zu tun · Fristen und Termine · Geld · je Verbrauchsart eine Seite
 * · Themenseiten (nur mit Daten) · Kleines Wörterbuch · auf Wunsch der Anhang
 * „Zahlen im Detail“ (`detail`).
 *
 * Gestaltung: Fließtext ≥ 10 pt, Beträge auf ganze Euro mit „ca.“, je Grafik
 * ein Aussagesatz, Ampeln mit Symbol und Wort, „Stand heute“ als Kasten.
 * Barrierefreiheit ohne getaggtes PDF: /Lang, Titel statt Dateiname,
 * Lesezeichen, „Seite x von y“, Lesereihenfolge = Zeichenreihenfolge.
 */
final class PdfReportService
{
    private const M = 48.0;          // Seitenrand links/rechts
    private const TOP = 48.0;
    private const BOTTOM = 790.0;    // Inhalt endet hier, darunter die Fußzeile
    private const INK    = [30, 41, 59];
    private const MUTE   = [71, 85, 105];
    private const RULE   = [203, 213, 225];
    private const ACCENT = [29, 78, 216];
    private const SOFT   = [241, 245, 249];
    private const TODAY  = [255, 251, 235];
    private const TODAY_RULE = [217, 119, 6];
    private const BODY = 10.5;
    private const SMALL = 10.0;
    /** Ein Abschnitt teilt eine Seite, wenn noch so viel Platz ist (pt). */
    private const SHARE_MIN = 300.0;

    private bool $measuring = false;

    private ?ReportModelService $modelService;

    // Layoutzustand während build()
    private PdfWriter $pdf;
    private float $y = 0.0;
    private string $pageTitle = '';
    private array $model = [];

    public function __construct(
        private MeterService $meters,
        private ConsumptionService $consumption,
        private SettingsService $settings,
        private BenchmarkService $benchmark,
        private RecommendationService $recommendations,
        private I18nService $i18n,
        ?ReportModelService $model = null,
    ) {
        // Ohne vollständige Verdrahtung (Tests, ältere Aufrufer): das Modell
        // mit den Kerndiensten; Themenseiten ohne ihre Dienste entfallen
        $this->modelService = $model;
    }

    private function modelService(): ReportModelService
    {
        return $this->modelService ??= new ReportModelService(
            $this->meters, $this->consumption, $this->consumption->contractService(), $this->settings, $this->i18n,
            $this->benchmark, $this->recommendations, new ReportAdviceService($this->i18n),
            pv: new PvSummaryService($this->consumption, $this->settings),
            heatPumps: new HeatPumpService($this->meters, $this->consumption),
        );
    }

    /** Lokalisierter Verbrauchsart-Name (zentraler Fallback in I18nService). */
    private function utilLabel(string $utility): string
    {
        return $this->i18n->utilityLabel($utility);
    }

    /**
     * @param array{utilities?:?list<string>,detail?:bool,today?:string} $opts
     */
    public function build(int $year, array $opts = []): string
    {
        $this->model = $this->modelService()->build($year, $opts);
        return $this->render($this->model);
    }

    /** Ein fertiges Modell als PDF setzen (auch für Tests mit festem Modell). */
    public function render(array $m): string
    {
        $this->model = $m;
        $this->pdf = new PdfWriter(false);
        $pdf = $this->pdf;
        $version = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
        $pdf->setInfo([
            'Title' => (string)$m['texts']['title'] . ((string)$m['location_name'] !== '' ? ' – ' . $m['location_name'] : ''),
            'Subject' => (string)$m['texts']['title'], 'Creator' => 'Energietracker v' . $version, 'Producer' => 'Energietracker v' . $version,
        ]);
        $pdf->setLang((string)$m['lang']);
        $footerLeft = (string)$m['texts']['title'] . ((string)$m['location_name'] !== '' ? ' · ' . $m['location_name'] : '');
        $pdf->setFooter(function (PdfWriter $w, int $page, int $count) use ($footerLeft): void {
            $W = $w->pageWidth();
            $w->line(self::M, 806, $W - self::M, 806, 0.5, self::RULE);
            $w->text(self::M, 820, $footerLeft, 8.5, false, self::MUTE);
            $w->textRight($W - self::M, 820, $this->i18n->t('report.footer.page', ['page' => $page, 'pages' => $count]), 8.5, false, self::MUTE);
        });

        $this->pageImportant($m);
        $this->pageSuccesses($m);
        $this->pageTasks($m);
        if (($m['agenda']['events'] ?? []) !== [] || ($m['agenda']['timelines'] ?? []) !== []) $this->pageAgenda($m);
        if (!empty($m['money'])) $this->pageMoney($m);
        foreach ($m['pages'] ?? [] as $p) $this->pageUtility($p, $m);
        $t = $m['topics'] ?? [];
        if (!empty($t['pv'])) $this->topicPv($t['pv'], $m);
        if (!empty($t['heat_pump'])) $this->topicHeatPump($t['heat_pump'], $m);
        if (!empty($t['ev'])) $this->topicEv($t['ev'], $m);
        if (!empty($t['flexibility'])) $this->topicFlex($t['flexibility'], $m);
        if (!empty($t['tenancy'])) $this->topicTenancy($t['tenancy'], $m);
        // v3.6.0 — Seite „Flexibilität“ nur auf Wunsch (`flex=1`)
        if (!empty($m['options']['flex']) && !empty($m['flex_page'])) $this->pageFlex($m['flex_page'], $m);
        $this->pageGlossary($m);
        if (!empty($m['appendix'])) $this->pageAppendix($m);
        // v3.6.0 (F1030) — Anhang „Abrechnungsjahre“ nur auf Wunsch
        if (!empty($m['options']['billing']) && !empty($m['billing'])) $this->pageAppendix($m, 'billing');
        return $pdf->output();
    }

    /**
     * Box-Protokoll des zuletzt gesetzten Berichts (PdfWriter::boxes()) und
     * seine Lesezeichen — für Tests: nichts über dem Rand, keine Überdeckung.
     *
     * @return array{boxes:list<array>,bookmarks:list<array>,width:float,height:float}
     */
    public function layoutLog(): array
    {
        return ['boxes' => $this->pdf->boxes(), 'bookmarks' => $this->pdf->bookmarks(),
                'width' => $this->pdf->pageWidth(), 'height' => $this->pdf->pageHeight()];
    }

    // ── Seitenbausteine ──────────────────────────────────────────────────

    private function W(): float
    {
        return $this->pdf->pageWidth();
    }

    private function cw(): float
    {
        return $this->W() - 2 * self::M;
    }

    /** Neue Seite mit Überschrift und Lesezeichen. */
    private function newPage(string $title, int $level = 0): void
    {
        $this->pdf->addPage();
        $this->heading($title, self::TOP, $level);
    }

    /**
     * Abschnitt, der eine angefangene Seite teilen darf (Themen, Termine,
     * Wörterbuch): Ist noch mindestens SHARE_MIN Platz, beginnt er unter dem
     * vorigen — kurze Themenseiten füllen sonst halbe Blätter.
     */
    private function newSection(string $title): void
    {
        if ($this->pdf->currentPage() > 0 && self::BOTTOM - $this->y >= self::SHARE_MIN) {
            $this->heading($title, $this->y + 14, 0);
            return;
        }
        $this->newPage($title);
    }

    private function heading(string $title, float $top, int $level): void
    {
        $this->pageTitle = $title;
        $this->pdf->bookmark($title, $level, $top);
        $this->pdf->text(self::M, $top + 18, $title, 19, true, self::INK);
        $this->pdf->line(self::M, $top + 28, $this->W() - self::M, $top + 28, 1.2, self::ACCENT);
        $this->y = $top + 52;
    }

    /**
     * Höhe eines Blocks durch Probezeichnen (ohne Seitenumbruch), danach
     * zurückgenommen — so passen Kästen genau um ihren Inhalt.
     */
    private function measure(callable $draw): float
    {
        $cp = $this->pdf->checkpoint();
        $y0 = $this->y;
        $this->measuring = true;
        try {
            $draw();
        } finally {
            $this->measuring = false;
        }
        $h = $this->y - $y0;
        $this->pdf->rollback($cp);
        $this->y = $y0;
        return $h;
    }

    /** Platz sicherstellen; sonst Folgeseite mit kleiner Überschrift. */
    private function ensure(float $h): void
    {
        if ($this->measuring || $this->y + $h <= self::BOTTOM) return;
        $this->pdf->addPage();
        $this->pdf->text(self::M, self::TOP + 12, $this->pageTitle . ' ' . $this->i18n->t('report.continued'), 12, true, self::MUTE);
        $this->pdf->line(self::M, self::TOP + 20, $this->W() - self::M, self::TOP + 20, 0.6, self::RULE);
        $this->y = self::TOP + 42;
    }

    private function h2(string $text, float $after = 8.0): void
    {
        $this->ensure(48);
        $this->y += 4;
        $this->pdf->text(self::M, $this->y + 10, $text, 13, true, self::INK);
        $this->y += 18 + $after;
    }

    /** Absatz; hält ihn zusammen, wenn er auf die Seite passt. */
    private function para(string $text, float $size = self::BODY, ?array $rgb = null, bool $bold = false, float $indent = 0.0, float $after = 6.0): void
    {
        if (trim($text) === '') return;
        $w = $this->cw() - $indent;
        $lines = $this->pdf->wrap($text, $size, $w, $bold);
        $lead = round($size * 1.38, 2);
        $this->ensure(min(count($lines), 6) * $lead);
        foreach ($lines as $line) {
            $this->ensure($lead);
            $this->pdf->paragraph(self::M + $indent, $this->y + $size, $line, $size, $w, $bold, $rgb ?? self::INK, $lead);
            $this->y += $lead;
        }
        $this->y += $after;
    }

    private function gap(float $h): void
    {
        $this->y += $h;
    }

    /**
     * Kasten „Stand heute (Datum)“ um einen Block: Höhe durch Probezeichnen,
     * dann Kasten, dann Inhalt. Passt der Block nicht mehr auf die Seite,
     * beginnt er auf der nächsten.
     */
    private function todayBox(callable $draw): void
    {
        $h = $this->measure($draw);
        $this->ensure($h + 36);
        $top = $this->y;
        $this->pdf->roundRect(self::M, $top, $this->cw(), $h + 32, 6, self::TODAY, self::TODAY_RULE, 0.8);
        $this->pdf->text(self::M + 12, $top + 16, $this->model['texts']['asOfToday'], 9.5, true, [146, 64, 14]);
        $this->y = $top + 26;
        $draw();
        $this->y = $top + $h + 32 + 10;
    }

    private function utilityColor(string $u): array
    {
        $hex = Utilities::exists($u) ? (string)Utilities::get($u)['color'] : '#64748b';
        return Chart::printColor($hex);
    }

    // ── 1. Das Wichtigste ────────────────────────────────────────────────

    private function pageImportant(array $m): void
    {
        $pdf = $this->pdf;
        $pdf->addPage();
        $this->pageTitle = $m['texts']['important'];
        $pdf->bookmark($m['texts']['important'], 0, self::TOP);
        $W = $this->W(); $cw = $this->cw();
        // Kopf: Titel, Haushalt, Datum
        $pdf->roundRect(self::M, self::TOP, $cw, 86, 8, self::ACCENT);
        $pdf->text(self::M + 18, self::TOP + 22, 'ENERGIETRACKER', 9, true, [219, 234, 254]);
        $pdf->text(self::M + 18, self::TOP + 50, (string)$m['texts']['title'], 24, true, [255, 255, 255]);
        $sub = trim((string)$m['location_name']);
        $created = $this->i18n->t('report.createdOn', ['date' => $this->i18n->date((string)$m['created_on'])]);
        $pdf->text(self::M + 18, self::TOP + 72, $sub !== '' ? "$sub · $created" : $created, 10, false, [255, 255, 255]);
        $this->y = self::TOP + 104;
        $pdf->text(self::M, $this->y + 10, $m['texts']['important'], 15, true, self::INK);
        $this->y += 24;

        // Kacheln
        $tiles = $m['headline']['tiles'] ?? [];
        if ($tiles !== []) {
            $n = count($tiles); $gap = 10.0;
            $tw = ($cw - ($n - 1) * $gap) / $n;
            foreach ($tiles as $i => $t) {
                $x = self::M + $i * ($tw + $gap);
                $pdf->roundRect($x, $this->y, $tw, 108, 6, self::SOFT);
                foreach (array_slice($pdf->wrap((string)$t['label'], 9, $tw - 20), 0, 2) as $k => $line) {
                    $pdf->text($x + 10, $this->y + 16 + $k * 11, $line, 9, false, self::MUTE);
                }
                $size = $pdf->textWidth((string)$t['value_text'], 20, true) > $tw - 20 ? 15 : 20;
                $pdf->text($x + 10, $this->y + 56, (string)$t['value_text'], $size, true, self::INK);
                $subLines = $pdf->wrap((string)$t['sub'], 8.5, $tw - 20);
                foreach (array_slice($subLines, 0, 2) as $k => $line) $pdf->text($x + 10, $this->y + 71 + $k * 10, $line, 8.5, false, self::MUTE);
                $spark = array_values($t['spark'] ?? []);
                if (count(array_filter($spark, fn($v) => $v !== null)) >= 2) {
                    Chart::sparkline($pdf, $x + 10, $this->y + 88, min(70.0, $tw - 20), 12, $spark, self::ACCENT);
                }
            }
            $this->y += 120;
            $pdf->text(self::M, $this->y, $this->i18n->t('report.tile.sparkNote', ['from' => $this->model['year'] - ReportModelService::HISTORY_YEARS + 1, 'to' => $this->model['year']]), 8.5, false, self::MUTE);
            $this->y += 16;
        }

        // Ampeln (2 × 2)
        $lights = $m['headline']['lights'] ?? [];
        if ($lights !== []) {
            $pdf->text(self::M, $this->y + 10, $m['texts']['lights'], 13, true, self::INK);
            $this->y += 24;
            $colW = ($cw - 16) / 2;
            $rowTop = $this->y;
            $maxH = 0.0;
            foreach ($lights as $i => $l) {
                $col = $i % 2;
                if ($col === 0 && $i > 0) { $rowTop += $maxH + 8; $maxH = 0.0; }
                $x = self::M + $col * ($colW + 16);
                $title = $this->i18n->t('report.light.title.' . $l['key']) . (!empty($l['as_of_today']) ? ' · ' . $this->i18n->t('report.light.today') : '');
                $pdf->text($x, $rowTop + 9, $title, 9, true, self::MUTE);
                Chart::light($pdf, $x, $rowTop + 26, (string)$l['level'], (string)$l['word'], 11);
                $yy = $rowTop + 42;
                foreach ($pdf->wrap((string)$l['detail'], self::SMALL, $colW) as $line) {
                    $pdf->paragraph($x, $yy, $line, self::SMALL, $colW, false, self::INK);
                    $yy += 13.8;
                }
                $maxH = max($maxH, $yy - $rowTop);
            }
            $this->y = $rowTop + $maxH + 12;
        }

        // Die drei wichtigsten Aufgaben — Stand heute
        $top = array_values($m['headline']['top_tasks'] ?? []);
        $this->todayBox(function () use ($top, $m, $cw): void {
            $pdf = $this->pdf;
            $pdf->text(self::M + 12, $this->y + 10, $m['texts']['topTasks'], 12, true, self::INK);
            $this->y += 22;
            if ($top === []) {
                $this->para($this->noTasksText($m), self::BODY, self::INK, false, 12);
                return;
            }
            foreach ($top as $t) $this->taskBlock($t, 12.0, $cw - 24, true);
        });
    }

    private function noTasksText(array $m): string
    {
        return ($m['agenda']['events'] ?? []) === [] ? $m['texts']['tasksNone'] : $m['texts']['tasksNoneButAgenda'];
    }

    /** Eine Aufgabe: Prioritätssymbol, Titel, € und Frist, Begründung, Schritte. */
    private function taskBlock(array $t, float $indent, float $width, bool $compact = false): void
    {
        $pdf = $this->pdf;
        $x = self::M + $indent;
        $sym = ['one', 'two', 'three'][max(0, min(2, (int)($t['priority'] ?? 3) - 1))];
        $col = [1 => [185, 28, 28], 2 => [161, 98, 7], 3 => [71, 85, 105]][(int)($t['priority'] ?? 3)] ?? self::MUTE;
        // eine Aufgabe nicht über zwei Seiten reißen
        if (!$this->measuring) $this->ensure($this->measure(fn() => $this->taskBlock($t, $indent, $width, $compact)));
        $pdf->symbol($x, $this->y + 11, $sym, 12, $col);
        $titleLines = $pdf->wrap((string)$t['title'], 11, $width - 22, true);
        foreach ($titleLines as $k => $line) $pdf->text($x + 20, $this->y + 11 + $k * 14, $line, 11, true, self::INK);
        $this->y += 11 + count($titleLines) * 14 - 2;
        $meta = array_filter([
            !empty($t['euro']) ? $t['euro']['text'] . ' (' . $t['euro']['basis_text'] . ')' : null,
            $t['deadline_text'] ?? null,
        ]);
        if ($meta !== []) {
            $line = implode(' · ', $meta);
            foreach ($pdf->wrap($line, self::SMALL, $width - 20, true) as $l) {
                $pdf->text($x + 20, $this->y + 10, $l, self::SMALL, true, $col);
                $this->y += 13.8;
            }
        }
        $this->para((string)$t['reason'], self::SMALL, self::INK, false, $indent + 20, $compact ? 6 : 3);
        if (!$compact) {
            foreach ($t['steps'] ?? [] as $s) $this->para((string)$s, self::SMALL, self::MUTE, false, $indent + 20, 3);
            $this->gap(6);
        }
    }

    // ── 2. Was hat sich gelohnt ──────────────────────────────────────────

    private function pageSuccesses(array $m): void
    {
        $this->newPage($m['texts']['successes']);
        $pdf = $this->pdf;
        $items = $m['successes'] ?? [];
        if ($items === []) $this->para($m['texts']['successesNone'], self::BODY, self::MUTE);
        foreach ($items as $s) {
            $this->ensure(60);
            $pdf->symbol(self::M, $this->y + 11, 'star', 12, [21, 128, 61]);
            $lines = $pdf->wrap((string)$s['title'], 11.5, $this->cw() - 22, true);
            foreach ($lines as $k => $line) $pdf->text(self::M + 20, $this->y + 11 + $k * 14, $line, 11.5, true, self::INK);
            $this->y += 11 + count($lines) * 14 - 2;
            if (!empty($s['euro'])) {
                $pdf->text(self::M + 20, $this->y + 10, $s['euro']['text'] . ' (' . $s['euro']['basis_text'] . ')', self::SMALL, true, [21, 128, 61]);
                $this->y += 14;
            }
            $this->para((string)$s['reason'], self::SMALL, self::INK, false, 20, 10);
            if (($s['rule'] ?? '') === 'pvBenefit' && !empty($m['topics']['pv']['payback'])) {
                $pb = $m['topics']['pv']['payback'];
                $this->ensure(30);
                Chart::progress($pdf, self::M + 20, $this->y, 220, 10, (float)$pb['benefit_to_date_eur'], (float)$pb['investment_eur'], [21, 128, 61]);
                $this->y += 16;
                $this->para((string)$pb['text'], self::SMALL, self::MUTE, false, 20, 10);
            }
        }
        $infos = $m['infos'] ?? [];
        if ($infos !== []) {
            $this->h2($m['texts']['goodToKnow']);
            foreach ($infos as $i) {
                $this->ensure(36);
                $pdf->symbol(self::M, $this->y + 10, 'diamond', 9, self::MUTE);
                $pdf->text(self::M + 20, $this->y + 10, (string)$i['title'], 11, true, self::INK);
                $this->y += 14;
                $this->para((string)$i['reason'], self::SMALL, self::INK, false, 20, 8);
            }
        }
    }

    // ── 3. Was ist zu tun ────────────────────────────────────────────────

    private function pageTasks(array $m): void
    {
        $this->newSection($m['texts']['tasks']);
        $tasks = $m['tasks'] ?? [];
        $this->para($m['texts']['tasksIntro'], self::SMALL, self::MUTE);
        if ($tasks === []) {
            $this->para($this->noTasksText($m));
            return;
        }
        foreach ($tasks as $t) $this->taskBlock($t, 0.0, $this->cw());
    }

    // ── 4. Fristen und Termine ───────────────────────────────────────────

    private function pageAgenda(array $m): void
    {
        $this->newSection($m['texts']['agenda']);
        $pdf = $this->pdf;
        $this->para($m['texts']['agendaIntro'], self::SMALL, self::MUTE);
        $events = $m['agenda']['events'] ?? [];
        if ($events === []) $this->para($m['texts']['agendaNone']);
        $cw = $this->cw();
        foreach ($events as $e) {
            $lines = $pdf->wrap((string)$e['title'], self::SMALL, $cw - 200);
            $h = max(1, count($lines)) * 13.8 + 6;
            $this->ensure($h);
            $col = $e['days'] < 0 ? [185, 28, 28] : ($e['days'] <= ReportAdviceService::LIGHT_DEADLINE_DAYS['alert'] ? [161, 98, 7] : self::INK);
            $pdf->text(self::M, $this->y + 10, (string)$e['date_text'], self::SMALL, true, $col);
            foreach ($lines as $k => $line) $pdf->text(self::M + 80, $this->y + 10 + $k * 13.8, $line, self::SMALL, false, self::INK);
            $pdf->textRight(self::M + $cw, $this->y + 10, (string)$e['state'], self::SMALL, false, $col);
            $this->y += $h;
            $pdf->line(self::M, $this->y - 3, self::M + $cw, $this->y - 3, 0.3, self::RULE);
        }
        $tl = $m['agenda']['timelines'] ?? [];
        if ($tl !== []) {
            $this->h2($m['texts']['contracts']);
            foreach ($tl as $c) {
                $draw = function () use ($pdf, $c, $m, $cw): void {
                    $pdf->text(self::M, $this->y + 10, $c['label'] . ($c['provider'] !== '' ? ' · ' . $c['provider'] : ''), 11, true, self::INK);
                    $this->y += 14;
                    $h = Chart::timeline($pdf, self::M, $this->y, $cw, $c['points'], (string)$m['today'], $this->i18n->t('report.timeline.today'), $this->utilityColor((string)$c['utility']));
                    $this->y += $h + 4;
                    $this->para((string)$c['sentence'], self::SMALL, self::INK, false, 0, 12);
                };
                $this->ensure($this->measure($draw));
                $draw();
            }
        }
    }

    // ── 5. Geld ──────────────────────────────────────────────────────────

    private function pageMoney(array $m): void
    {
        $money = $m['money'];
        $this->newPage($m['texts']['money']);
        $pdf = $this->pdf;
        $cw = $this->cw();
        $this->para((string)$money['total']['sentence']);
        // Kosten je Art gegen Vorjahr
        if (($money['by_utility'] ?? []) !== []) {
            $this->h2($m['texts']['costsByUtility']);
            $rows = array_map(fn($r) => ['label' => $r['label'], 'value' => $r['cost'], 'prev' => $r['prev'],
                'color' => $this->utilityColor((string)$r['utility']), 'text' => $r['text']], $money['by_utility']);
            $this->ensure(count($rows) * 26 + 20);
            $this->y = Chart::hbars($pdf, self::M, $this->y, $cw, $rows, 140);
            // Balken tragen ihre Werte; die Legende erklärt nur den grauen Vorjahresstrich
            $this->y = Chart::legend($pdf, self::M + 140, $this->y, [[(string)($m['year'] - 1), 'prev']], self::ACCENT) + 6;
        }
        foreach ($money['revenue'] ?? [] as $r) $this->para((string)$r['text'], self::SMALL);
        // Kosten je Monat
        if (array_filter(array_column($money['monthly'] ?? [], 'value')) !== []) {
            $this->h2($m['texts']['costsByMonth']);
            $this->ensure(170);
            $this->y = Chart::monthBars($pdf, self::M, $this->y, $cw, 140, $money['monthly'], [
                'color' => self::ACCENT, 'fmt' => fn(float $v) => $this->i18n->money(round($v), 0),
                'legend' => [[(string)$m['year'], 'now'], [(string)($m['year'] - 1), 'prev']],
            ]) + 6;
            foreach ($money['bonus_notes'] ?? [] as $b) $this->para((string)$b, self::SMALL, self::MUTE);
        }
        // Saldo je Vertrag — Stand heute
        $bal = $money['balances'] ?? [];
        if ($bal !== []) {
            $this->h2($m['texts']['balances']);
            foreach ($bal as $b) {
                $this->todayBox(function () use ($b, $cw): void {
                    $pdf = $this->pdf;
                    $pdf->text(self::M + 12, $this->y + 10, $b['label'] . ($b['provider'] !== '' ? ' · ' . $b['provider'] : ''), 11, true, self::INK);
                    $this->y += 18;
                    if (count($b['path']) >= 2) {
                        Chart::lineChart($pdf, self::M + 12, $this->y, $cw - 24, 108, $b['path'], [
                            'color' => $this->utilityColor((string)$b['utility']), 'fmt' => fn(float $v) => $this->i18n->money(round($v), 0),
                            'above' => [254, 226, 226], 'below' => [220, 252, 231],
                        ]);
                        $this->y += 118;
                    }
                    $this->para((string)$b['text'], self::BODY, self::INK, true, 12, 4);
                    if ($b['advance_text'] !== null) $this->para((string)$b['advance_text'], self::SMALL, self::INK, false, 12, 4);
                });
            }
            $this->para($this->i18n->t('report.money.balanceLegend'), self::SMALL, self::MUTE);
        }
        if (!empty($money['forecast'])) $this->para((string)$money['forecast']['text'], self::SMALL, self::MUTE);
    }

    // ── 6. Je Verbrauchsart ──────────────────────────────────────────────

    private function pageUtility(array $p, array $m): void
    {
        $this->newPage($this->i18n->t('report.page.title', ['label' => $p['label'], 'year' => $m['year']]));
        $pdf = $this->pdf;
        $cw = $this->cw();
        $color = $this->utilityColor((string)$p['utility']);
        // große Zahl und Aussagesatz
        $pdf->text(self::M, $this->y + 22, (string)$p['value_text'], 24, true, $color);
        $this->y += 34;
        $this->para((string)$p['sentence']);
        $this->para((string)$p['cost']['text'], self::SMALL, self::INK);
        // Monatsbalken mit Vorjahr
        $legend = [[(string)$m['year'], 'now'], [(string)($m['year'] - 1), 'prev']];
        if (in_array(true, array_column($p['months'], 'estimated'), true)) $legend[] = [$m['texts']['estimated'], 'estimated'];
        $this->ensure(170);
        $dec = $p['unit'] === 'kWh' ? 0 : 1;
        $this->y = Chart::monthBars($pdf, self::M, $this->y, $cw, 140, $p['months'], [
            'color' => $color, 'fmt' => fn(float $v) => $this->i18n->number($v, $v < 10 && $dec > 0 ? 1 : 0), 'legend' => $legend,
        ]) + 8;
        if (!empty($p['weather'])) {
            $this->h2($this->i18n->t('report.page.weatherTitle'));
            $this->para((string)$p['weather']['text']);
        }
        if (!empty($p['efficiency'])) {
            $e = $p['efficiency'];
            $this->h2($this->i18n->t('report.page.efficiencyTitle'));
            if (!empty($e['thresholds'])) {
                $this->ensure(60);
                $this->y = Chart::efficiencyScale($pdf, self::M, $this->y, $cw, $e['thresholds'], (float)$e['kwh_per_m2'], (string)$e['value_text']) + 6;
            }
            $this->para((string)$e['text'], self::SMALL);
            if (!empty($e['area_note'])) $this->para((string)$e['area_note'], self::SMALL, null, true);   // v3.6.0 — angenommene Fläche
        }
        if (!empty($p['roles'])) {
            $this->h2($this->i18n->t('report.page.rolesTitle'));
            $rows = array_map(fn($r) => ['label' => $r['label'], 'value' => $r['kwh'], 'color' => $color, 'text' => $r['text']], $p['roles']['rows']);
            $this->ensure(count($rows) * 26 + 10);
            $this->y = Chart::hbars($pdf, self::M, $this->y, $cw, $rows, 140) + 2;
            $this->para((string)$p['roles']['text'], self::SMALL, self::MUTE);
        }
        if (!empty($p['water'])) $this->para((string)$p['water']['text']);
        if (!empty($p['tank'])) {
            $this->todayBox(function () use ($p, $cw): void {
                if ($p['tank']['percent'] !== null) {
                    Chart::progress($this->pdf, self::M + 12, $this->y, min(220.0, $cw - 24), 10, (float)$p['tank']['percent'], 100, $this->utilityColor((string)$p['utility']));
                    $this->y += 18;
                }
                $this->para((string)$p['tank']['text'], self::BODY, self::INK, false, 12);
            });
        }
        foreach ($p['sub_meters'] ?? [] as $s) $this->para((string)$s['text'], self::SMALL, self::MUTE);
        foreach ($p['notes'] ?? [] as $n) $this->para((string)$n, self::SMALL, self::MUTE);
    }

    // ── Themenseiten ─────────────────────────────────────────────────────

    private function topicPv(array $t, array $m): void
    {
        $this->newPage($m['texts']['topicPv']);
        $pdf = $this->pdf;
        $cw = $this->cw();
        $tiles = $t['tiles'];
        $n = count($tiles); $gap = 10.0; $tw = ($cw - ($n - 1) * $gap) / $n;
        $this->ensure(70);
        foreach ($tiles as $i => $x) {
            $xx = self::M + $i * ($tw + $gap);
            $pdf->roundRect($xx, $this->y, $tw, 58, 6, self::SOFT);
            $pdf->text($xx + 8, $this->y + 16, $pdf->wrap((string)$x['label'], 9, $tw - 16)[0] ?? '', 9, false, self::MUTE);
            $pdf->text($xx + 8, $this->y + 38, (string)$x['text'], 15, true, self::INK);
            if ($x['sub'] !== '') $pdf->text($xx + 8, $this->y + 51, $pdf->wrap((string)$x['sub'], 8, $tw - 16)[0] ?? '', 8, false, self::MUTE);
        }
        $this->y += 70;
        $f = $t['flow'];
        $hasBat = $f['charged'] > 0 || $f['discharged'] > 0;
        $gh = $hasBat ? 235.0 : 165.0;
        $this->ensure($gh + 20);
        $pv = Chart::printColor((string)Utilities::get('pv_erzeugung')['color']);
        Chart::energyFlow($pdf, self::M + ($cw - 340) / 2, $this->y, 340, $gh, $f, $t['labels'], [
            'pv' => $pv, 'feed' => Chart::printColor((string)Utilities::get('pv_einspeisung')['color']),
            'grid' => Chart::printColor((string)Utilities::get('strom')['color']), 'battery' => [21, 128, 61],
        ], fn(float $v) => $this->i18n->number($v, 0) . ' kWh');
        $this->y += $gh + 8;
        $this->para((string)$t['sentence']);
        // v3.9.0 (V5) — Zeitraum, Schätzung, Widerspruch, fehlender Vergleich
        foreach ((array)($t['notes'] ?? []) as $n) $this->para((string)$n, self::SMALL, self::MUTE);
        if (!empty($t['co2_text'])) $this->para((string)$t['co2_text'], self::SMALL);
        if (array_filter(array_column($t['months'], 'value')) !== []) {
            $this->h2($this->i18n->t('report.topic.pv.monthsTitle'));
            $this->ensure(170);
            $legend = [[(string)$m['year'], 'now'], [(string)($m['year'] - 1), 'prev']];
            if (array_filter(array_column($t['months'], 'estimated')) !== []) $legend[] = [$this->i18n->t('interpolation.markTitleOpen'), 'estimated'];
            $this->y = Chart::monthBars($pdf, self::M, $this->y, $cw, 130, $t['months'], [
                'color' => $pv, 'fmt' => fn(float $v) => $this->i18n->number($v, 0),
                'legend' => $legend,
            ]) + 8;
        }
        if (!empty($t['payback'])) {
            $pb = $t['payback'];
            $this->h2($this->i18n->t('report.topic.pv.paybackTitle'));
            $this->ensure(40);
            Chart::progress($pdf, self::M, $this->y, $cw, 12, (float)$pb['benefit_to_date_eur'], (float)$pb['investment_eur'], [21, 128, 61]);
            $this->y += 20;
            $this->para((string)$pb['text'], self::SMALL);
        }
        if (!empty($t['battery'])) {
            $this->h2($this->i18n->t('report.topic.pv.batteryTitle'));
            $this->para((string)$t['battery']['text'], self::SMALL);
        }
    }

    private function topicHeatPump(array $t, array $m): void
    {
        $this->newSection($m['texts']['topicHeatPump']);
        $pdf = $this->pdf;
        $cw = $this->cw();
        foreach ($t['pumps'] as $p) {
            $this->para((string)$p['sentence']);
            $this->ensure(170);
            $color = $this->utilityColor('waerme');
            $top = $this->y;
            $this->y = Chart::monthBars($pdf, self::M, $this->y, $cw, 130, $p['months'], [
                'color' => $color, 'fmt' => fn(float $v) => $this->i18n->number($v, 1),
                'legend' => [[$m['texts']['cop'], 'now'], [$m['texts']['reference'], 'projected', self::INK]],
            ]) + 8;
            // Referenzlinie (Feldtest-Mittel Luft/Wasser)
            $max = Chart::niceMax(max(1e-9, ...array_map(fn($x) => (float)$x['value'], $p['months']), ...[(float)$p['reference']]));
            $axisW = 0.0;
            for ($i = 0; $i <= 4; $i++) $axisW = max($axisW, $pdf->textWidth($this->i18n->number($max * $i / 4, 1), 8));
            $px = self::M + $axisW + 6; $ph = 130 - 16;
            $ry = $top + $ph - $ph * min((float)$p['reference'], $max) / $max;
            $pdf->line($px, $ry, self::M + $cw - 1, $ry, 1.4, self::INK, [4, 3]);
            $this->para((string)$p['reference_text'], self::SMALL, self::MUTE);
        }
    }

    private function topicEv(array $t, array $m): void
    {
        $this->newSection($m['texts']['topicEv']);
        $pdf = $this->pdf;
        $cw = $this->cw();
        foreach ($t['meters'] as $e) {
            $this->para((string)$e['sentence']);
            if ($e['cost_text'] !== null) $this->para((string)$e['cost_text'], self::SMALL);
            $this->ensure(170);
            $color = $this->utilityColor('strom');
            $pv = Chart::printColor((string)Utilities::get('pv_erzeugung')['color']);
            $top = $this->y;
            $this->y = Chart::monthBars($pdf, self::M, $this->y, $cw, 130, array_map(fn($x) => $x + ['estimated' => false], $e['months']), [
                'color' => $color, 'fmt' => fn(float $v) => $this->i18n->number($v, 0), 'legend' => [],
            ]);
            // Sonnenanteil als unterer Teil des Balkens
            if ($e['has_solar']) {
                $vals = array_map(fn($x) => (float)($x['value'] ?? 0), $e['months']);
                $max = Chart::niceMax(max(1e-9, ...$vals));
                $axisW = 0.0;
                for ($i = 0; $i <= 4; $i++) $axisW = max($axisW, $pdf->textWidth($this->i18n->number($max * $i / 4, 0), 8));
                $px = self::M + $axisW + 6; $pw = $cw - $axisW - 6; $ph = 130 - 16;
                $slot = $pw / 12; $bw = min(14.0, $slot * 0.36);
                foreach (array_values($e['months']) as $i => $x) {
                    $part = (float)($x['part'] ?? 0);
                    if ($part <= 0) continue;
                    $bh = $ph * min($part, (float)($x['value'] ?? 0)) / $max;
                    $cx = $px + $slot * ($i + 0.5);
                    $pdf->rect($cx + 1, $top + $ph - $bh, $bw, $bh, $pv);
                }
            }
            $this->y = Chart::legend($pdf, self::M, $this->y + 6, $e['has_solar']
                ? [[$this->i18n->t('report.topic.ev.grid'), 'now'], [$m['texts']['solarShare'], 'now', $pv]]
                : [[$this->i18n->t('report.topic.ev.kwh'), 'now']], $color) + 6;
            if ($e['sub_text'] !== null) $this->para((string)$e['sub_text'], self::SMALL, self::MUTE);
        }
    }

    private function topicFlex(array $t, array $m): void
    {
        $this->newSection($m['texts']['topicFlex']);
        $pdf = $this->pdf;
        $this->para((string)$t['sentence']);
        $color = $this->utilityColor('strom');
        $rows = array_map(fn($r) => ['label' => $r['name'], 'value' => $r['cheap_share'] * 100, 'color' => $color, 'text' => $r['text']], $t['rows']);
        $this->h2($m['texts']['cheapShare']);
        $this->ensure(count($rows) * 26 + 10);
        $this->y = Chart::hbars($pdf, self::M, $this->y, $this->cw(), $rows, 150) + 4;
        if ($t['module3_text'] !== null) $this->para((string)$t['module3_text']);
        if ($t['battery_text'] !== null) $this->para((string)$t['battery_text']);
        $this->para($this->i18n->t('report.topic.flex.note'), self::SMALL, self::MUTE);
    }

    /**
     * v3.6.0 — Seite „Flexibilität“: große Zahl, Handlungssätze, Tagesband je
     * Halbjahr (Stufen als Farbe UND Wort in der Legende, Sonne als Punkt — das
     * PDF setzt nur CP1252, ☀ gäbe es dort nicht), Zeitfenster, Hebel, Sonnenstrom.
     */
    private function pageFlex(array $f, array $m): void
    {
        $this->newPage($m['texts']['flexPage']);
        $pdf = $this->pdf;
        $cw = $this->cw();
        $green = [4, 120, 87];
        $this->ensure(60);
        $pdf->text(self::M, $this->y + 30, (string)$f['big'], 32, true, $green);
        $this->y += 42;
        $this->para((string)$f['sentence'], self::BODY, self::INK, true);
        $facts = $f['facts'] ?? [];
        if ($facts !== []) {
            $n = count($facts); $gap = 10.0; $tw = ($cw - ($n - 1) * $gap) / $n;
            $this->ensure(52);
            foreach ($facts as $i => $x) {
                $xx = self::M + $i * ($tw + $gap);
                $pdf->roundRect($xx, $this->y, $tw, 44, 6, self::SOFT);
                $pdf->text($xx + 8, $this->y + 15, $pdf->wrap((string)$x['label'], 8.5, $tw - 16)[0] ?? '', 8.5, false, self::MUTE);
                $pdf->text($xx + 8, $this->y + 34, (string)$x['value'], 13, true, self::INK);
            }
            $this->y += 54;
        }
        if (($f['actions'] ?? []) !== []) {
            $this->h2($this->i18n->t('flex.actions.title'));
            $this->para((string)$f['actions_lead'], self::SMALL, self::MUTE);
            foreach ($f['actions'] as $a) {
                $this->para('• ' . $a['text'], self::BODY, self::INK, false, 0, 1);
                $this->para($a['tag'] . ($a['other'] !== null ? ' · ' . $a['other'] : ''), self::SMALL, self::MUTE, false, 9, 5);
            }
        }
        if (($f['bands'] ?? []) !== []) {
            $this->h2($this->i18n->t('flex.ampel.title'));
            $this->para((string)($f['band_lead_pdf'] ?? $f['band_lead']), self::SMALL, self::MUTE);
            $col = ['cheap' => [21, 128, 61], 'mid' => [202, 138, 4], 'expensive' => [185, 28, 28]];
            $sun = Chart::printColor((string)Utilities::get('pv_erzeugung')['color']);
            $labelW = 92.0; $cell = ($cw - $labelW) / 24;
            foreach ($f['bands'] as $b) {
                $this->ensure(44);
                $top = $this->y;
                $pdf->text(self::M, $top + 18, (string)$b['label'], 9, true, self::INK);
                foreach ($b['hours'] as $h => $x) {
                    $cx = self::M + $labelW + $h * $cell;
                    if (in_array($x['sun'], ['strong', 'some'], true)) $pdf->circle($cx + $cell / 2, $top + 4, $x['sun'] === 'strong' ? 2.6 : 1.6, $sun);
                    $pdf->rect($cx + 0.5, $top + 9, $cell - 1, 13, $col[$x['level'] ?? ''] ?? [226, 232, 240]);
                    if ($h % 3 === 0) $pdf->textCenter($cx + $cell / 2, $top + 31, (string)$h, 7, false, self::MUTE);
                }
                $this->y = $top + 36;
                if (!empty($b['summary'])) $this->para((string)$b['summary'], self::SMALL, self::INK, false, $labelW, 4);
            }
            // Legende: Farbe und Wort
            $this->ensure(18);
            $x0 = self::M;
            foreach ([['cheap', 0], ['mid', 1], ['expensive', 2]] as [$k, $i]) {
                $pdf->rect($x0, $this->y + 2, 10, 8, $col[$k]);
                $pdf->text($x0 + 14, $this->y + 9.5, (string)$f['legend'][$i], 8.5, false, self::MUTE);
                $x0 += 22 + $pdf->textWidth((string)$f['legend'][$i], 8.5);
            }
            $pdf->circle($x0 + 4, $this->y + 6, 2.6, $sun);
            $pdf->text($x0 + 10, $this->y + 9.5, (string)$f['legend'][3], 8.5, false, self::MUTE);
            $this->y += 18;
        }
        if (!empty($f['windows'])) {
            $this->h2($this->i18n->t('flex.windows.title'));
            $this->para((string)$f['windows']['lead'], self::SMALL, self::MUTE);
            $this->table($f['windows']['head'], $f['windows']['rows'], null, 190.0);
        }
        if (!empty($f['levers'])) {
            $this->h2($this->i18n->t('flex.lever.title'));
            // Spalten schmal halten: „mit dynamischem Tarif“ als Sternchen mit Fußnote
            $rows = array_map(fn($r) => [$r['label'], $r['signal'] . ($r['real'] ? '' : ' *'), $r['kwh'], $r['eur']], $f['levers']['rows']);
            $this->table($f['levers']['head'], $rows, null, 160.0);
            $this->para((string)$f['levers']['total']);
            if (array_filter($f['levers']['rows'], fn($r) => !$r['real']) !== []) $this->para((string)$f['levers']['dynamic_note'], self::SMALL, self::MUTE);
        }
        if (!empty($f['solar_text'])) {
            $this->h2($this->i18n->t('flex.solar.title'));
            $this->para((string)$f['solar_text']);
        }
        foreach ($f['notes'] ?? [] as $n) $this->para((string)$n, self::SMALL, self::MUTE);
        if (!empty($f['footer'])) $this->para((string)$f['footer'], self::SMALL, self::MUTE);
    }

    private function topicTenancy(array $t, array $m): void
    {
        $this->newSection($m['texts']['topicTenancy']);
        $cw = $this->cw();
        $hasPath = count($t['path'] ?? []) >= 2;
        $this->todayBox(function () use ($t, $cw, $hasPath): void {
            $pdf = $this->pdf;
            $pdf->text(self::M + 12, $this->y + 10, (string)$t['label'], 11, true, self::INK);
            $this->y += 18;
            if ($hasPath) {
                Chart::lineChart($pdf, self::M + 12, $this->y, $cw - 24, 108, $t['path'], [
                    'color' => $this->utilityColor('waerme'), 'fmt' => fn(float $v) => $this->i18n->money(round($v), 0),
                    'above' => [254, 226, 226], 'below' => [220, 252, 231],
                ]);
                $this->y += 116;
            }
            foreach ($t['sentences'] as $s) $this->para((string)$s, self::BODY, self::INK, false, 12);
        });
        $this->para($this->i18n->t('report.topic.tenancy.pathLegend'), self::SMALL, self::MUTE);
    }

    // ── Wörterbuch und Anhang ────────────────────────────────────────────

    private function pageGlossary(array $m): void
    {
        $this->newSection($m['texts']['glossary']);
        $pdf = $this->pdf;
        foreach ($m['glossary'] ?? [] as $g) {
            $this->ensure(34);
            $pdf->text(self::M, $this->y + 10, (string)$g['term'], 11, true, self::INK);
            $this->y += 15;
            $this->para((string)$g['text'], self::SMALL, self::INK, false, 0, 8);
        }
        $this->h2($m['texts']['sources']);
        foreach ($m['sources'] ?? [] as $s) $this->para((string)$s, self::SMALL, self::MUTE, false, 0, 3);
        if (($m['errors'] ?? []) !== []) {
            $this->gap(6);
            $this->para($m['texts']['errors'], self::SMALL, self::MUTE);
        }
    }

    /** Anhang aus Tabellen: „Zahlen im Detail“ (`appendix`) oder v3.6.0 „Abrechnungsjahre“ (`billing`, breitere erste Spalte). */
    private function pageAppendix(array $m, string $key = 'appendix'): void
    {
        $first = true;
        foreach ($m[$key] as $tbl) {
            if ($first) { $this->newPage($m['texts'][$key]); $first = false; }
            $this->pdf->bookmark((string)$tbl['label'], 1, $this->y);
            $this->h2((string)$tbl['label']);
            $this->table($tbl['head'], $tbl['rows'], $tbl['total'], $key === 'billing' ? 128.0 : 74.0);
            foreach ($tbl['notes'] as $n) $this->para((string)$n, self::SMALL, self::MUTE);
            $this->gap(8);
        }
    }

    /** Tabelle: erste Spalte links, Zahlen rechtsbündig; Kopf wiederholt sich auf Folgeseiten. v3.6.0 — `total` optional. */
    private function table(array $head, array $rows, ?array $total, float $first = 74.0): void
    {
        $pdf = $this->pdf;
        $cw = $this->cw();
        $n = count($head);
        $colW = ($cw - $first) / max(1, $n - 1);
        $size = 9.5;
        $right = fn(int $i): float => self::M + $first + $colW * $i;   // rechte Kante der Spalte i (ab 1)
        $drawHead = function () use ($pdf, $head, $right, $colW, $cw, $size): void {
            $pdf->text(self::M, $this->y + 10, (string)$head[0], $size, true, self::MUTE);
            for ($i = 1; $i < count($head); $i++) {
                $label = $pdf->wrap((string)$head[$i], $size, $colW - 6, true)[0] ?? '';
                $pdf->textRight($right($i), $this->y + 10, $label, $size, true, self::MUTE);
            }
            $this->y += 15;
            $pdf->line(self::M, $this->y, self::M + $cw, $this->y, 0.6, self::RULE);
            $this->y += 4;
        };
        $this->ensure(60);
        $drawHead();
        foreach ($rows as $r) {
            if ($this->y + 15 > self::BOTTOM) { $this->ensure(1000); $drawHead(); }
            $pdf->text(self::M, $this->y + 10, (string)$r[0], $size, false, self::INK);
            for ($i = 1; $i < count($r); $i++) $pdf->textRight($right($i), $this->y + 10, (string)$r[$i], $size, false, self::INK);
            $this->y += 14.5;
        }
        $pdf->line(self::M, $this->y + 1, self::M + $cw, $this->y + 1, 0.6, self::RULE);
        $this->y += 4;
        if ($total === null) { $this->y += 6; return; }
        $pdf->text(self::M, $this->y + 10, (string)$total[0], $size, true, self::INK);
        for ($i = 1; $i < count($total); $i++) $pdf->textRight($right($i), $this->y + 10, (string)$total[$i], $size, true, self::INK);
        $this->y += 20;
    }

    // ── Daten für die Druckansicht (Klasse B, additiv) ───────────────────

    /**
     * v3.1.0 (Review I18N-12) — der Jahresbericht als Daten, ohne Formatierung.
     * Die Felder bis v3.3 bleiben (utilities, meters, recommendations …);
     * v3.4.0 (F1025) — dazu `model`: das Datenmodell, aus dem auch das PDF
     * entsteht (ReportModelService).
     *
     * @param array{utilities?:?list<string>,detail?:bool,today?:string} $opts
     * @return array<string,mixed>
     */
    public function yearlyData(int $year, array $opts = []): array
    {
        $active = $this->activeUtilities();
        if (($opts['utilities'] ?? null) !== null) $active = array_values(array_filter($active, fn($u) => in_array($u, $opts['utilities'], true)));
        $utilities = [];
        foreach ($active as $utility) {
            $agg = $this->yearAggregate($utility, $year);
            if ($agg['kwh'] <= 0 && $agg['m3'] <= 0) continue;
            $isKwh = Utilities::get($utility)['consumption_unit'] === 'kWh';
            $utilities[] = [
                'utility'         => $utility,
                'label'           => $this->utilLabel($utility),
                'accounting_kind' => Utilities::accountingKind($utility),
                'unit'            => $isKwh ? 'kWh' : 'm³',
                'consumption'     => round($isKwh ? $agg['kwh'] : $agg['m3'], 3),
                'cost'            => round($agg['cost'], 2),
                'co2_kg'          => round($agg['co2'], 1),
            ];
        }
        $meters = [];
        foreach ($active as $utility) {
            $isKwh = Utilities::get($utility)['consumption_unit'] === 'kWh';
            $vf = $isKwh ? 'kwh' : 'm3';
            foreach ($this->meters->list($utility) as $meter) {
                $monthly = array_values(array_filter(
                    $this->consumption->forMeter($utility, $meter),
                    fn($m) => (int)($m['year'] ?? 0) === $year
                ));
                if (empty($monthly)) continue;
                $months = array_map(fn($m) => [
                    'ym'                => (string)($m['ym'] ?? ''),
                    'value'             => round((float)($m[$vf] ?? 0), 3),
                    'cost'              => round((float)($m['cost'] ?? 0), 2),
                    'avg_temp'          => $m['avg_temp'] ?? null,
                    'hdd'               => $m['hdd'] ?? null,
                    'heat_adjusted'     => $m['heat_adjusted'] ?? null,
                    'weather_delta_pct' => $m['weather_delta_pct'] ?? null,
                ], $monthly);
                $used = array_values(array_filter($months, fn($m) => $m['value'] > 0));
                $sum = array_sum(array_column($months, 'value'));
                $peak = $low = null;
                foreach ($used as $m) {
                    if ($peak === null || $m['value'] > $peak['value']) $peak = ['ym' => $m['ym'], 'value' => $m['value']];
                    if ($low === null || $m['value'] < $low['value']) $low = ['ym' => $m['ym'], 'value' => $m['value']];
                }
                $meters[] = [
                    'utility'         => $utility,
                    'label'           => $this->utilLabel($utility),
                    'meter_id'        => (string)($meter['id'] ?? ''),
                    'meter_name'      => (string)($meter['name'] ?? ''),
                    'accounting_kind' => Utilities::accountingKind($utility),
                    'unit'            => $isKwh ? 'kWh' : 'm³',
                    'months'          => $months,
                    'kpis'            => [
                        'sum'       => round($sum, 3),
                        'avg_month' => round($sum / max(1, count($used)), 3),
                        'cost'      => round(array_sum(array_column($months, 'cost')), 2),
                        'peak'      => $peak,
                        'low'       => $low,
                    ],
                    'has_weather_adjusted' => $this->anyKey($monthly, 'heat_adjusted') || $this->anyKey($monthly, 'weather_delta_pct'),
                    'has_temperature'      => $this->anyKey($monthly, 'avg_temp'),
                ];
            }
        }
        return [
            'year'           => $year,
            'created_on'     => date('Y-m-d'),
            'location_name'  => (string)$this->settings->get('location_name', ''),
            'version'        => trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
            'efficiency'     => $this->benchmark->efficiency($year),
            'utilities'      => $utilities,
            'has_generation' => in_array('pv_erzeugung', array_column($utilities, 'utility'), true),
            'meters'         => $meters,
            'recommendations'=> array_slice($this->recommendations->all(), 0, 12),
            'model'          => $this->modelService()->build($year, $opts),
        ];
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
            // v2.1.3 — F1006: Subzähler nicht mitzählen (Doppelzählung);
            // v2.9.0 (CALC-15) — Zähler außer Betrieb zählen mit ihrer Historie.
            if (!MeterService::countsInTotals($meter)) {
                // v3.9.0 (F1032, #40) — Subzähler mit eigenem Vertrag: sein Geld zählt, seine Menge nicht
                if ($this->consumption->isDeductingSubmeter($utility, $meter)) {
                    foreach ($this->consumption->forMeter($utility, $meter) as $m) {
                        if ((int)($m['year'] ?? 0) === $year && !empty($m['contract_id'])) $cost += (float)($m['cost'] ?? 0) - (float)($m['uncontracted_cost'] ?? 0);
                    }
                }
                continue;
            }
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
}
