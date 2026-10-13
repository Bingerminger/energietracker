<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.4.0 (F1025) — Handlungsempfehlungen, Erfolge und Ampeln für den
 * Jahresbericht.
 *
 * Eine reine Rechnung: evaluate() bekommt die Fakten, die ReportModelService
 * aus den Diensten der App sammelt (Verträge, Heizung, Anomalien, PV, Agenda …),
 * und liefert Einträge mit
 *   kind      task | success | info
 *   priority  1–3 (nur Aufgaben): Frist ≤ 14 Tage oder über 200 € → 1,
 *             Frist ≤ 60 Tage oder über 50 € → 2, sonst 3
 *   euro      Wert, Bezug (Jahr/einmalig), Art (gemessen, hochgerechnet,
 *             Rechenbeispiel) — AUSSCHLIESSLICH aus eigenen Daten und
 *             eigenen Vertragspreisen, nie aus Marktannahmen; ohne Preis kein €
 *   deadline  bis wann (Stand heute)
 *   reason    der Rechenweg als Satz mit den eigenen Zahlen
 *   steps     ein bis zwei Hinweise, unpersönlich und ohne Befehlsform
 *
 * Ausgeblendete Empfehlungen (gleiche ID wie in RecommendationService)
 * bleiben draußen. Die Schwellen stehen als Konstanten hier und sind getestet
 * (ReportAdviceServiceTest) — keine zweite, freihändig gepflegte Liste.
 */
final class ReportAdviceService
{
    // ── Ampeln ──
    /** Verbrauch (witterungsbereinigt gegen Vorjahr, %): grün ≤ ok, gelb ≤ warn, sonst rot. */
    public const LIGHT_CONSUMPTION = ['ok' => 0.0, 'warn' => 5.0];
    /** Kosten gegen Vorjahr (%). */
    public const LIGHT_COST = ['ok' => 3.0, 'warn' => 10.0];
    /** Abrechnung: erwartete Nachzahlung in Monatsabschlägen. */
    public const LIGHT_BILLING = ['ok' => 1.0, 'warn' => 2.0];
    /** Fristen: rot bis alert Tage (oder überfällig), gelb bis warn Tage. */
    public const LIGHT_DEADLINE_DAYS = ['alert' => 14, 'warn' => 60];

    // ── Priorität ──
    public const PRIORITY_1 = ['days' => 14, 'eur' => 200.0];
    public const PRIORITY_2 = ['days' => 60, 'eur' => 50.0];

    // ── Regeln ──
    /** Abschlag anpassen ab max(50 €, ein Monatsabschlag). */
    public const ADVANCE_MIN_EUR = 50.0;
    /** Kündigungsstichtag in höchstens so vielen Tagen. */
    public const CANCEL_WINDOW_DAYS = 90;
    /** Heizverbrauch gestiegen ab +5 % (witterungsbereinigt); Erfolg ab −2 %. */
    public const HEATING_RISE_PCT = 5.0;
    public const HEATING_FALL_PCT = -2.0;
    /** Höchstens so viele auffällige Monate. */
    public const MAX_ANOMALIES = 3;
    /** Wärmepumpe: Aufgabe unter Referenz − 0,3. */
    public const JAZ_MARGIN = 0.3;
    /** Eigenverbrauchsquote darunter gilt als niedrig. */
    public const SELF_RATE_LOW = 0.30;
    /** Speicher-Wirkungsgrad darunter (%). */
    public const BATTERY_EFF_MIN = 85.0;
    /** Rechnungsprüfung: Abweichung ab so viel €. */
    public const BILL_DELTA_EUR = 10.0;
    /** Mietverhältnis: Nachzahlung ab so viel €. */
    public const TENANCY_MIN_EUR = 50.0;
    /** Effizienz: Rechenbeispiel mit 10 % weniger. */
    public const EFFICIENCY_EXAMPLE_SHARE = 0.10;
    /** Effizienzklassen, die eine Aufgabe ergeben. */
    public const WEAK_CLASSES = ['E', 'F', 'G', 'H'];
    /** Agenda-Arten, die hier als Aufgabe erscheinen (Verträge und CO₂ haben eigene Regeln). */
    public const AGENDA_TASK_KINDS = ['reminder', 'reading_due', 'tank_reorder', 'tenancy_statement_due', 'objection_deadline'];
    /** Agenda-Arten, die als Frist zählen (Ampel). */
    public const DEADLINE_KINDS = ['reminder', 'cancel_by', 'term_end', 'price_guarantee_end', 'price_increase',
        'tank_reorder', 'tenancy_statement_due', 'objection_deadline', 'co2_claim_deadline'];

    public function __construct(private I18nService $i18n) {}

    /**
     * @param array<string,mixed> $f Fakten (siehe ReportModelService::facts())
     * @return array{tasks:list<array>,successes:list<array>,infos:list<array>,lights:list<array>}
     */
    public function evaluate(array $f): array
    {
        $items = [];
        $dismissed = array_flip((array)($f['dismissed'] ?? []));
        $add = function (?array $item) use (&$items, $dismissed): void {
            if ($item === null) return;
            if (isset($item['id']) && isset($dismissed[$item['id']])) return;
            $items[] = $item;
        };
        foreach ((array)($f['contracts'] ?? []) as $c) {
            foreach ($this->contractRules($c, $f) as $it) $add($it);
        }
        foreach ((array)($f['heating'] ?? []) as $h) $add($this->heatingRule($h, $f));
        $n = 0;
        foreach ((array)($f['anomalies'] ?? []) as $a) {
            if ($n >= self::MAX_ANOMALIES) break;
            $it = $this->anomalyRule($a);
            if ($it !== null && !isset($dismissed[$it['id']])) { $add($it); $n++; }
        }
        foreach ((array)($f['efficiency'] ?? []) as $e) $add($this->efficiencyRule($e, $f));
        foreach ((array)($f['heat_pumps'] ?? []) as $hp) $add($this->heatPumpRule($hp));
        if (!empty($f['pv'])) foreach ($this->pvRules((array)$f['pv']) as $it) $add($it);
        foreach ((array)($f['flex'] ?? []) as $fx) $add($this->flexRule($fx));
        if (($f['module3_eur'] ?? 0) > 0) $add($this->success('module3', null, 'report.advice.module3.title', [],
            'report.advice.module3.reason', [], $this->euro((float)$f['module3_eur'], 'year', 'measured', 'earned')));
        if (!empty($f['tenancy'])) $add($this->tenancyRule((array)$f['tenancy']));
        if (!empty($f['co2_claim'])) $add($this->co2Rule((array)$f['co2_claim'], $f));
        foreach ($this->agendaRules((array)($f['agenda'] ?? []), (string)$f['today']) as $it) $add($it);
        foreach ((array)($f['bills'] ?? []) as $b) $add($this->billRule($b));
        foreach ((array)($f['baselines'] ?? []) as $b) $add($this->baselineRule($b));
        if (!empty($f['ev'])) $add($this->evRule((array)$f['ev']));
        $add($this->costSuccess((array)($f['totals'] ?? []), (int)$f['year']));

        $tasks = array_values(array_filter($items, fn($i) => $i['kind'] === 'task'));
        foreach ($tasks as &$t) {
            $t['priority'] = $this->priority($t, (string)$f['today']);
            if ($t['deadline'] !== null && $t['deadline'] < (string)$f['today']) {
                $t['deadline_text'] = $this->i18n->t('report.advice.overdueSince', ['date' => $this->i18n->date((string)$t['deadline'])]);
            }
        }
        unset($t);
        usort($tasks, function ($a, $b) {
            return [$a['priority'], -abs((float)($a['euro']['value'] ?? 0)), $a['deadline'] ?? '9999']
               <=> [$b['priority'], -abs((float)($b['euro']['value'] ?? 0)), $b['deadline'] ?? '9999'];
        });
        $successes = array_values(array_filter($items, fn($i) => $i['kind'] === 'success'));
        usort($successes, fn($a, $b) => abs((float)($b['euro']['value'] ?? 0)) <=> abs((float)($a['euro']['value'] ?? 0)));
        $infos = array_values(array_filter($items, fn($i) => $i['kind'] === 'info'));
        if ($successes === []) {
            $neutral = $this->neutral((array)($f['totals'] ?? []), (int)$f['year']);
            if ($neutral !== null) $infos[] = $neutral;
        }
        return [
            'tasks'     => $tasks,
            'successes' => $successes,
            'infos'     => $infos,
            'lights'    => $this->lights($f),
        ];
    }

    // ── Priorität und Ampeln ─────────────────────────────────────────────

    /** Priorität 1–3 aus Frist und Betrag (Bericht §5). */
    public function priority(array $task, string $today): int
    {
        $eur = abs((float)($task['euro']['value'] ?? 0));
        $days = isset($task['deadline']) && $task['deadline'] !== null ? self::days($today, (string)$task['deadline']) : null;
        if (($days !== null && $days <= self::PRIORITY_1['days']) || $eur > self::PRIORITY_1['eur']) return 1;
        if (($days !== null && $days <= self::PRIORITY_2['days']) || $eur > self::PRIORITY_2['eur']) return 2;
        return 3;
    }

    /** Stufe der Verbrauchs-Ampel aus der Veränderung in %. */
    public static function consumptionLevel(?float $pct): string
    {
        if ($pct === null) return 'none';
        return $pct <= self::LIGHT_CONSUMPTION['ok'] ? 'ok' : ($pct <= self::LIGHT_CONSUMPTION['warn'] ? 'warn' : 'alert');
    }

    public static function costLevel(?float $pct): string
    {
        if ($pct === null) return 'none';
        return $pct <= self::LIGHT_COST['ok'] ? 'ok' : ($pct <= self::LIGHT_COST['warn'] ? 'warn' : 'alert');
    }

    /** Abrechnung: Nachzahlung in Monatsabschlägen (negativ = Guthaben). */
    public static function billingLevel(?float $advances): string
    {
        if ($advances === null) return 'none';
        return $advances < self::LIGHT_BILLING['ok'] ? 'ok' : ($advances <= self::LIGHT_BILLING['warn'] ? 'warn' : 'alert');
    }

    /** Fristen: Tage bis zur nächsten Frist (negativ = überfällig), null = keine. */
    public static function deadlineLevel(?int $days): string
    {
        if ($days === null || $days > self::LIGHT_DEADLINE_DAYS['warn']) return 'ok';
        return $days <= self::LIGHT_DEADLINE_DAYS['alert'] ? 'alert' : 'warn';
    }

    /** @return list<array{key:string,level:string,word:string,detail:string,as_of_today:bool}> */
    private function lights(array $f): array
    {
        $t = (array)($f['totals'] ?? []);
        $year = (int)$f['year'];
        $out = [];
        // Kosten — immer mit Preis- und Mengeneffekt
        $costPct = $t['cost_pct'] ?? null;
        $lv = self::costLevel($costPct);
        $out[] = ['key' => 'cost', 'level' => $lv, 'as_of_today' => false,
            'word' => $this->i18n->t("report.light.cost.$lv"),
            'detail' => $costPct === null ? $this->i18n->t('report.light.noPrevious', ['prev' => $year - 1])
                : $this->i18n->t('report.light.costDetail', [
                    'pct' => $this->pct((float)$costPct), 'prev' => $year - 1,
                    'price' => $this->signedEur((float)($t['price_effect'] ?? 0)),
                    'volume' => $this->signedEur((float)($t['volume_effect'] ?? 0)),
                ])];
        // Verbrauch — witterungsbereinigt, wenn möglich
        $cPct = $t['consumption_pct'] ?? null;
        $lv = self::consumptionLevel($cPct);
        $out[] = ['key' => 'consumption', 'level' => $lv, 'as_of_today' => false,
            'word' => $this->i18n->t("report.light.consumption.$lv"),
            'detail' => $cPct === null ? $this->i18n->t('report.light.noPrevious', ['prev' => $year - 1])
                : $this->i18n->t(!empty($t['consumption_adjusted']) ? 'report.light.consumptionAdjusted' : 'report.light.consumptionRaw',
                    ['pct' => $this->pct((float)$cPct), 'prev' => $year - 1])];
        // Abrechnung — Stand heute, der ungünstigste laufende Vertrag
        $worst = null; $worstC = null;
        foreach ((array)($f['contracts'] ?? []) as $c) {
            if (empty($c['has_advance']) || ($c['projected_end_balance'] ?? null) === null) continue;
            $adv = (float)($c['current_advance'] ?? 0);
            $ratio = $adv > 0 ? (float)$c['projected_end_balance'] / $adv : ((float)$c['projected_end_balance'] > 0 ? INF : -INF);
            if ($worst === null || $ratio > $worst) { $worst = $ratio; $worstC = $c; }
        }
        $lv = self::billingLevel($worst === null ? null : (is_infinite($worst) ? ($worst > 0 ? 99.0 : -99.0) : $worst));
        $out[] = ['key' => 'billing', 'level' => $lv, 'as_of_today' => true,
            'word' => $this->i18n->t("report.light.billing.$lv"),
            'detail' => $worstC === null ? $this->i18n->t('report.light.billingNone')
                : $this->i18n->t((float)$worstC['projected_end_balance'] > 0 ? 'report.light.billingDue' : 'report.light.billingCredit', [
                    'label' => (string)$worstC['label'], 'amount' => $this->approx(abs((float)$worstC['projected_end_balance'])),
                ])];
        // Fristen — Stand heute
        $next = null; $nextE = null;
        foreach ((array)($f['agenda'] ?? []) as $e) {
            if (!in_array($e['kind'] ?? '', self::DEADLINE_KINDS, true)) continue;
            $d = self::days((string)$f['today'], (string)$e['date']);
            if ($next === null || $d < $next) { $next = $d; $nextE = $e; }
        }
        $lv = self::deadlineLevel($next);
        $out[] = ['key' => 'deadlines', 'level' => $lv, 'as_of_today' => true,
            'word' => $this->i18n->t("report.light.deadlines.$lv"),
            'detail' => $nextE === null || $next > self::LIGHT_DEADLINE_DAYS['warn']
                ? $this->i18n->tp('report.light.deadlinesNone', self::LIGHT_DEADLINE_DAYS['warn'], ['days' => self::LIGHT_DEADLINE_DAYS['warn']])
                : $this->i18n->t($next < 0 ? 'report.light.deadlineOverdue' : 'report.light.deadlineNext', [
                    'title' => (string)$nextE['title'], 'date' => $this->i18n->date((string)$nextE['date']),
                ])];
        return $out;
    }

    // ── Verträge ─────────────────────────────────────────────────────────

    /** @return list<array> */
    private function contractRules(array $c, array $f): array
    {
        $out = [];
        $today = (string)$f['today'];
        $label = (string)$c['label'];
        $provider = (string)($c['provider'] ?? '') ?: '—';
        // Abschlag
        if (!empty($c['has_advance']) && ($c['projected_end_balance'] ?? null) !== null && !empty($c['is_current'])) {
            $bal = (float)$c['projected_end_balance'];
            $adv = (float)($c['current_advance'] ?? 0);
            $limit = max(self::ADVANCE_MIN_EUR, $adv);
            $date = $this->i18n->date((string)($c['settle_date'] ?? $c['effective_end'] ?? $today));
            $sugg = $c['suggested_advance'] ?? null;
            if (abs($bal) >= $limit) {
                $due = $bal > 0;
                $steps = [];
                if ($due && $sugg !== null && (float)$sugg > $adv) {
                    $steps[] = $this->i18n->t('report.advice.advance.stepRaise', ['new' => $this->eur((float)$sugg), 'old' => $this->eur($adv)]);
                } elseif (!$due && $sugg !== null && (float)$sugg > 0 && (float)$sugg < $adv) {
                    $steps[] = $this->i18n->t('report.advice.advance.stepLower', ['new' => $this->eur((float)$sugg), 'old' => $this->eur($adv)]);
                } elseif (!$due) {
                    // Mangel 11 — Vorschlag ≤ 0: keine Zahl, nur „senken lassen“
                    $steps[] = $this->i18n->t('report.advice.advance.stepLowerNoAmount');
                }
                $out[] = $this->task('advance', $c['utility'] ?? null, 'advance|' . ($c['key'] ?? ''),
                    'report.advice.advance.title', ['label' => $label],
                    $due ? 'report.advice.advance.reasonDue' : 'report.advice.advance.reasonCredit',
                    ['date' => $date, 'amount' => $this->approx(abs($bal)), 'advance' => $this->eur($adv)],
                    $this->euro(abs($bal), 'once', 'projected', $due ? 'avoids' : 'refund'),
                    null, $steps, true);
            } else {
                $out[] = $this->success('advanceOk', $c['utility'] ?? null, 'report.advice.advanceOk.title', ['label' => $label],
                    'report.advice.advanceOk.reason', ['date' => $date, 'amount' => $this->approx(abs($bal))], null, true);
            }
        }
        // Kündigen oder wechseln — nicht, wo es nichts zu wechseln gibt (Wasser)
        $cancel = $c['cancel_by'] ?? null;
        if (!empty($c['switchable']) && is_string($cancel) && $cancel >= $today && self::days($today, $cancel) <= self::CANCEL_WINDOW_DAYS) {
            $steps = [];
            $euro = null;
            $offer = $c['offer'] ?? null;
            if (is_array($offer) && ($offer['vs_reference_year2_eur'] ?? null) !== null && (float)$offer['vs_reference_year2_eur'] < 0) {
                $saving = -(float)$offer['vs_reference_year2_eur'];
                $euro = $this->euro($saving, 'year', 'projected', 'saves');
                $steps[] = $this->i18n->t('report.advice.cancel.stepOffer', ['offer' => (string)$offer['label'], 'amount' => $this->approx($saving)]);
            } elseif (($c['expected_consumption'] ?? null) !== null) {
                $steps[] = $this->i18n->t('report.advice.cancel.stepPortal', [
                    'value' => $this->i18n->number((float)$c['expected_consumption'], 0), 'unit' => (string)($c['unit'] ?? 'kWh'),
                ]);
            }
            $steps[] = $this->i18n->t('report.advice.cancel.stepRenew');
            $out[] = $this->task('cancel', $c['utility'] ?? null, (string)($c['rec_r6'] ?? ''),
                'report.advice.cancel.title', ['label' => $label],
                'report.advice.cancel.reason', [
                    'provider' => $provider, 'date' => $this->i18n->date($cancel),
                    'end' => $this->i18n->date((string)($c['switch_date'] ?? $c['end'] ?? $cancel)),
                ], $euro, $cancel, $steps, true);
        } elseif (!empty($c['switchable']) && !empty($c['cancel_missed']) && !empty($c['is_current'])) {
            $out[] = $this->info('cancelMissed', $c['utility'] ?? null, 'report.advice.cancelMissed.title', ['label' => $label],
                'report.advice.cancelMissed.reason', ['provider' => $provider,
                    'end' => $this->i18n->date((string)($c['effective_end'] ?? $c['end'] ?? $today))], true);
        }
        // Preiserhöhung
        $pi = $c['price_increase'] ?? null;
        if (is_array($pi) && is_string($pi['from'] ?? null) && $pi['from'] >= $today) {
            $parts = [];
            $extra = 0.0;
            $kwh = (float)($c['expected_consumption'] ?? 0);
            if (!empty($pi['working_price_ct'])) {
                [$old, $new] = $pi['working_price_ct'];
                $parts[] = $this->i18n->t('report.advice.priceIncrease.reasonWp', [
                    'old' => $this->i18n->number((float)$old, 2), 'new' => $this->i18n->number((float)$new, 2), 'unit' => (string)($c['unit'] ?? 'kWh')]);
                $extra += ((float)$new - (float)$old) * $kwh / 100;
            }
            if (!empty($pi['base_price_eur'])) {
                [$old, $new] = $pi['base_price_eur'];
                $parts[] = $this->i18n->t('report.advice.priceIncrease.reasonBp', ['old' => $this->eur((float)$old, 2), 'new' => $this->eur((float)$new, 2)]);
                $extra += ((float)$new - (float)$old) * 12;
            }
            $steps = [];
            // Rechtshinweis nur, wo Code und Katalog ihn schon führen (Deutschland)
            if (($f['country'] ?? 'DE') === 'DE') $steps[] = $this->i18n->t('report.advice.priceIncrease.legalDe');
            $out[] = $this->task('priceIncrease', $c['utility'] ?? null, (string)($c['rec_price'] ?? ''),
                'report.advice.priceIncrease.title', ['label' => $label],
                'report.advice.priceIncrease.reason', ['date' => $this->i18n->date((string)$pi['from']), 'parts' => implode(' ', $parts)],
                $extra > 0 && $kwh > 0 ? $this->euro($extra, 'year', 'projected', 'costs') : null,
                (string)$pi['from'], $steps, true);
        }
        return $out;
    }

    // ── Heizung, Anomalien, Effizienz ────────────────────────────────────

    private function heatingRule(array $h, array $f): ?array
    {
        $now = (float)($h['adj_now'] ?? 0); $prev = (float)($h['adj_prev'] ?? 0);
        if ($now <= 0 || $prev <= 0) return null;
        $pct = ($now / $prev - 1) * 100;
        $kwh = abs($now - $prev);
        $price = $h['price_ct'] ?? null;
        $params = ['label' => (string)$h['label'], 'pct' => $this->pct($pct), 'prev' => (int)$f['year'] - 1,
                   'kwh' => $this->i18n->number($kwh, 0)];
        if ($pct >= self::HEATING_RISE_PCT) {
            return $this->task('heatingRise', $h['utility'] ?? null, 'heating|' . ($h['utility'] ?? ''),
                'report.advice.heatingRise.title', ['label' => (string)$h['label']], 'report.advice.heatingRise.reason', $params,
                $price !== null ? $this->euro($kwh * (float)$price / 100, 'year', 'measured', 'costs') : null,
                null, [$this->i18n->t('report.advice.heatingRise.step')], false);
        }
        if ($pct <= self::HEATING_FALL_PCT) {
            return $this->success('heatingFall', $h['utility'] ?? null, 'report.advice.heatingFall.title', ['label' => (string)$h['label']],
                'report.advice.heatingFall.reason', $params,
                $price !== null ? $this->euro($kwh * (float)$price / 100, 'year', 'measured', 'earned') : null);
        }
        return null;
    }

    private function anomalyRule(array $a): ?array
    {
        $actual = (float)($a['actual'] ?? 0); $expected = (float)($a['expected'] ?? 0);
        if ($actual <= $expected || $expected <= 0) return null;
        $price = $a['price_ct'] ?? null;
        $month = $this->i18n->month((string)$a['ym']);
        $dec = ($a['unit'] ?? 'kWh') === 'kWh' ? 0 : 1;
        $item = $this->task('anomaly', $a['utility'] ?? null, (string)($a['id'] ?? ''),
            'report.advice.anomaly.title', ['label' => (string)$a['label'], 'month' => $month],
            'report.advice.anomaly.reason', [
                'month' => $month, 'pct' => $this->pct(($actual / $expected - 1) * 100),
                'actual' => $this->i18n->number($actual, $dec), 'expected' => $this->i18n->number($expected, $dec), 'unit' => (string)($a['unit'] ?? 'kWh'),
            ],
            $price !== null ? $this->euro(($actual - $expected) * (float)$price / 100, 'once', 'measured', 'costs') : null,
            null, [$this->i18n->t('report.advice.anomaly.step')], false);
        return $item;
    }

    private function efficiencyRule(array $e, array $f): ?array
    {
        if (!in_array($e['class'] ?? null, self::WEAK_CLASSES, true)) return null;
        $price = $e['price_ct'] ?? null;
        $kwh = (float)($e['kwh'] ?? 0);
        return $this->task('efficiency', $e['utility'] ?? null, (string)($e['rec_id'] ?? ''),
            'report.advice.efficiency.title', ['label' => (string)$e['label'], 'class' => (string)$e['class']],
            'report.advice.efficiency.reason', ['value' => $this->i18n->number((float)$e['kwh_per_m2'], 0)],
            $price !== null && $kwh > 0 ? $this->euro($kwh * self::EFFICIENCY_EXAMPLE_SHARE * (float)$price / 100, 'year', 'example', 'saves') : null,
            null, [$this->i18n->t('report.advice.efficiency.step')], false);
    }

    private function heatPumpRule(array $hp): ?array
    {
        $jaz = $hp['jaz'] ?? null; $ref = $hp['reference'] ?? null;
        if ($jaz === null || $ref === null) return null;
        $params = ['jaz' => $this->i18n->number((float)$jaz, 1), 'ref' => $this->i18n->number((float)$ref, 1)];
        if ((float)$jaz < (float)$ref - self::JAZ_MARGIN) {
            $price = $hp['price_ct'] ?? null;
            $eur = $price !== null ? (float)$hp['elec_kwh'] * (1 - (float)$jaz / (float)$ref) * (float)$price / 100 : null;
            return $this->task('heatPump', 'waerme', 'heatpump|' . ($hp['id'] ?? ''), 'report.advice.heatPumpLow.title', [],
                'report.advice.heatPumpLow.reason', $params,
                $eur !== null ? $this->euro($eur, 'year', 'example', 'saves') : null,
                null, [$this->i18n->t('report.advice.heatPumpLow.step')], false);
        }
        if ((float)$jaz >= (float)$ref) {
            return $this->success('heatPump', 'waerme', 'report.advice.heatPumpGood.title', [], 'report.advice.heatPumpGood.reason', $params, null);
        }
        return $this->info('heatPump', 'waerme', 'report.advice.heatPumpOk.title', [], 'report.advice.heatPumpOk.reason', $params, false);
    }

    // ── Sonnenstrom ──────────────────────────────────────────────────────

    /** @return list<array> */
    private function pvRules(array $pv): array
    {
        $out = [];
        $grid = $pv['grid_ct'] ?? null; $feed = $pv['feed_ct'] ?? null;
        if (($pv['benefit_eur'] ?? null) !== null && (float)$pv['benefit_eur'] > 0) {
            $out[] = $this->success('pvBenefit', 'pv_erzeugung', 'report.advice.pvBenefit.title', [], 'report.advice.pvBenefit.reason', [
                'savings' => $this->approx((float)($pv['savings_eur'] ?? 0)), 'feed' => $this->approx((float)($pv['feed_in_revenue_eur'] ?? 0)),
            ], $this->euro((float)$pv['benefit_eur'], 'year', 'measured', 'earned'));
        }
        $rate = $pv['self_rate'] ?? null;
        if ($rate !== null && (float)$rate < self::SELF_RATE_LOW && $grid !== null && $feed !== null && (float)$grid > (float)$feed) {
            $out[] = $this->task('pvSelfUse', 'pv_erzeugung', 'pvself', 'report.advice.pvSelfUse.title', [],
                'report.advice.pvSelfUse.reason', ['pct' => $this->share((float)$rate * 100), 'feed' => $this->i18n->number((float)$feed, 1)],
                $this->euro(((float)$grid - (float)$feed), 'per100', 'example', 'saves'),
                null, [$this->i18n->t('report.advice.pvSelfUse.step')], false);
        }
        $bat = $pv['battery'] ?? null;
        if (is_array($bat) && ($bat['efficiency_pct'] ?? null) !== null) {
            if ((float)$bat['efficiency_pct'] < self::BATTERY_EFF_MIN) {
                $out[] = $this->task('batteryLoss', 'pv_erzeugung', 'batteryloss', 'report.advice.batteryLoss.title', [],
                    'report.advice.batteryLoss.reason', ['pct' => $this->share((float)$bat['efficiency_pct']),
                        'kwh' => $this->i18n->number((float)($bat['losses_kwh'] ?? 0), 0)],
                    $grid !== null ? $this->euro((float)($bat['losses_kwh'] ?? 0) * (float)$grid / 100, 'year', 'measured', 'costs') : null,
                    null, [$this->i18n->t('report.advice.batteryLoss.step')], false);
            }
            if ((float)($bat['discharged_kwh'] ?? 0) > 0) {
                $val = $grid !== null && $feed !== null ? (float)$bat['discharged_kwh'] * max(0.0, (float)$grid - (float)$feed) / 100 : null;
                $out[] = $this->success('battery', 'pv_erzeugung', 'report.advice.battery.title', [], 'report.advice.battery.reason',
                    ['kwh' => $this->i18n->number((float)$bat['discharged_kwh'], 0)],
                    $val !== null && $val > 0 ? $this->euro($val, 'year', 'measured', 'earned') : null);
            }
        }
        return $out;
    }

    private function flexRule(array $fx): ?array
    {
        $pf = $fx['profile_factor'] ?? null;
        if ($pf === null || (float)$pf <= 1.0 || empty($fx['controllable'])) return null;
        $eur = ((float)($fx['own_ct'] ?? 0) - (float)($fx['market_ct'] ?? 0)) * (float)($fx['kwh'] ?? 0) / 100;
        return $this->task('flex', 'strom', 'flex|' . ($fx['meter_id'] ?? ''), 'report.advice.flex.title', ['name' => (string)$fx['name']],
            'report.advice.flex.reason', ['name' => (string)$fx['name'], 'pf' => $this->i18n->number((float)$pf, 2)],
            $eur > 0 ? $this->euro($eur, 'year', 'measured', 'costs') : null,
            null, [$this->i18n->t('report.advice.flex.step')], false);
    }

    private function evRule(array $ev): ?array
    {
        $kwh = (float)($ev['kwh'] ?? 0); $solar = (float)($ev['solar_kwh'] ?? 0);
        if ($kwh <= 0 || $solar <= 0) return null;
        $grid = $ev['grid_ct'] ?? null; $feed = $ev['feed_ct'] ?? null;
        $val = $grid !== null && $feed !== null ? $solar * max(0.0, (float)$grid - (float)$feed) / 100 : null;
        return $this->success('evSolar', 'strom', 'report.advice.evSolar.title', [], 'report.advice.evSolar.reason', [
            'pct' => $this->share($solar / $kwh * 100), 'kwh' => $this->i18n->number($solar, 0),
        ], $val !== null && $val > 0 ? $this->euro($val, 'year', 'measured', 'earned') : null);
    }

    // ── Miete, CO₂, Agenda, Rechnungen, Zäsur ────────────────────────────

    private function tenancyRule(array $t): ?array
    {
        $res = $t['projected_result_eur'] ?? null;
        if ($res === null) return null;
        $label = (string)($t['label'] ?? '');
        $date = $this->i18n->date((string)($t['period_to'] ?? ''));
        if ((float)$res >= self::TENANCY_MIN_EUR) {
            $steps = ($t['suggested_prepayment_eur'] ?? null) !== null
                ? [$this->i18n->t('report.advice.tenancy.step', ['new' => $this->eur((float)$t['suggested_prepayment_eur'])])] : [];
            return $this->task('tenancy', null, 'tenancy|' . ($t['id'] ?? ''), 'report.advice.tenancy.title', ['label' => $label],
                'report.advice.tenancy.reasonDue', ['date' => $date, 'amount' => $this->approx((float)$res)],
                $this->euro((float)$res, 'once', 'projected', 'avoids'), null, $steps, true);
        }
        if ((float)$res <= -self::TENANCY_MIN_EUR) {
            return $this->info('tenancy', null, 'report.advice.tenancyCredit.title', ['label' => $label],
                'report.advice.tenancyCredit.reason', ['date' => $date, 'amount' => $this->approx(abs((float)$res))], true);
        }
        return $this->success('tenancyOk', null, 'report.advice.tenancyOk.title', ['label' => $label],
            'report.advice.tenancyOk.reason', ['date' => $date], null, true);
    }

    private function co2Rule(array $c, array $f): ?array
    {
        $amount = (float)($c['landlord_amount_eur'] ?? 0);
        if ($amount <= 0) return null;
        $params = ['year' => (int)$f['year'], 'amount' => $this->approx($amount), 'stage' => (string)($c['stage'] ?? '–')];
        if (($c['case'] ?? '') === 'central') {
            return $this->success('co2Split', null, 'report.advice.co2Central.title', [], 'report.advice.co2Central.reason', $params, null);
        }
        return $this->task('co2Claim', null, 'co2claim|' . (int)$f['year'], 'report.advice.co2Claim.title', [],
            'report.advice.co2Claim.reason', $params, $this->euro($amount, 'once', 'measured', 'saves'),
            $c['deadline'] ?? null, [$this->i18n->t('report.advice.co2Claim.step')], true);
    }

    /** @return list<array> */
    private function agendaRules(array $events, string $today): array
    {
        $out = [];
        $readings = [];
        foreach ($events as $e) {
            $kind = (string)($e['kind'] ?? '');
            if (!in_array($kind, self::AGENDA_TASK_KINDS, true)) continue;
            $date = (string)($e['date'] ?? $today);
            $days = self::days($today, $date);
            if ($kind === 'reading_due') {
                if (!empty($e['due_now'])) $readings[] = $e;
                continue;
            }
            // nur, was in den nächsten 60 Tagen fällig ist oder schon überfällig
            if ($days > self::LIGHT_DEADLINE_DAYS['warn']) continue;
            $out[] = $this->task('agenda', null, 'agenda|' . ($e['uid'] ?? $kind . $date), 'report.advice.agenda.title', ['title' => (string)$e['title']],
                "report.advice.agenda.kind.$kind", ['date' => $this->i18n->date($date)],
                null, $date, [], true);
        }
        if ($readings !== []) {
            $names = array_values(array_unique(array_map(fn($e) => (string)($e['params']['meter'] ?? $e['title']), $readings)));
            $last = array_filter(array_map(fn($e) => $e['ref']['last_reading'] ?? null, $readings));
            $out[] = $this->task('readings', null, 'readings', 'report.advice.readings.title', [],
                'report.advice.readings.reason', ['meters' => implode(', ', $names),
                    'date' => $last !== [] ? $this->i18n->date((string)min($last)) : '–'],
                null, $today, [], true);
        }
        return $out;
    }

    private function billRule(array $b): ?array
    {
        $delta = (float)($b['delta_eur'] ?? 0);
        if (($b['verdict'] ?? 'ok') === 'ok' || abs($delta) < self::BILL_DELTA_EUR) return null;
        return $this->task('bill', $b['utility'] ?? null, 'bill|' . ($b['id'] ?? ''), 'report.advice.bill.title', ['label' => (string)$b['label']],
            $delta > 0 ? 'report.advice.bill.reasonHigher' : 'report.advice.bill.reasonLower',
            ['amount' => $this->approx(abs($delta)), 'from' => $this->i18n->date((string)$b['from']), 'to' => $this->i18n->date((string)$b['to'])],
            $delta > 0 ? $this->euro($delta, 'once', 'measured', 'saves') : null, null, [$this->i18n->t('report.advice.bill.step')], false);
    }

    private function baselineRule(array $b): ?array
    {
        if (empty($b['significant']) || (float)($b['delta_pct'] ?? 0) >= 0) return null;
        $kwh = ((float)$b['slope_before'] - (float)$b['slope_after']) * (float)($b['hdd_normal_year'] ?? 0);
        if ($kwh <= 0) return null;
        $price = $b['price_ct'] ?? null;
        return $this->success('baseline', $b['utility'] ?? null, 'report.advice.baseline.title', ['label' => (string)$b['label']],
            'report.advice.baseline.reason', [
                'event' => (string)($b['event'] ?? ''), 'date' => $this->i18n->date((string)($b['date'] ?? '')),
                'pct' => $this->pct((float)$b['delta_pct']), 'kwh' => $this->i18n->number($kwh, 0),
            ], $price !== null ? $this->euro($kwh * (float)$price / 100, 'year', 'projected', 'earned') : null);
    }

    private function costSuccess(array $t, int $year): ?array
    {
        $now = $t['cost_now'] ?? null; $prev = $t['cost_prev'] ?? null;
        if ($now === null || $prev === null || (float)$prev <= 0 || (float)$now >= (float)$prev) return null;
        return $this->success('costDown', null, 'report.advice.costDown.title', [], 'report.advice.costDown.reason', [
            'prev' => $year - 1, 'pct' => $this->pct(((float)$now / (float)$prev - 1) * 100),
        ], $this->euro((float)$prev - (float)$now, 'year', 'measured', 'earned'));
    }

    private function neutral(array $t, int $year): ?array
    {
        if (($t['cost_pct'] ?? null) === null && ($t['consumption_pct'] ?? null) === null) {
            return $this->info('neutral', null, 'report.advice.neutral.title', [], 'report.advice.neutral.noPrevious', ['prev' => $year - 1], false);
        }
        return $this->info('neutral', null, 'report.advice.neutral.title', [], 'report.advice.neutral.reason', [
            'prev' => $year - 1,
            'cost' => ($t['cost_pct'] ?? null) !== null ? $this->pct((float)$t['cost_pct']) : '–',
            'consumption' => ($t['consumption_pct'] ?? null) !== null ? $this->pct((float)$t['consumption_pct']) : '–',
        ], false);
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    /**
     * @param 'year'|'once'|'per100' $per
     * @param 'measured'|'projected'|'example' $basis
     * @param 'saves'|'avoids'|'costs'|'earned'|'refund' $effect
     */
    private function euro(float $value, string $per, string $basis, string $effect): array
    {
        $value = round($value, 2);
        return [
            'value'  => $value,
            'per'    => $per,
            'basis'  => $basis,
            'effect' => $effect,
            'text'   => $this->i18n->t("report.euro.$effect.$per", ['amount' => $this->approx(abs($value))]),
            'basis_text' => $this->i18n->t("report.euro.basis.$basis"),
        ];
    }

    private function base(string $kind, string $rule, ?string $utility, string $id, string $titleKey, array $titleParams,
                          string $reasonKey, array $params, ?array $euro, ?string $deadline, array $steps, bool $today): array
    {
        return [
            'id'          => $id !== '' ? $id : $rule,
            'kind'        => $kind,
            'rule'        => $rule,
            'priority'    => null,
            'utility'     => $utility,
            'title'       => $this->i18n->t($titleKey, $titleParams),
            'reason'      => $this->i18n->t($reasonKey, $params),
            'euro'        => $euro,
            'deadline'    => $deadline,
            'deadline_text' => $deadline !== null ? $this->i18n->t('report.advice.deadline', ['date' => $this->i18n->date($deadline)]) : null,
            'steps'       => array_values(array_filter($steps, fn($s) => $s !== '')),
            'as_of_today' => $today,
        ];
    }

    private function task(string $rule, ?string $utility, string $id, string $titleKey, array $titleParams, string $reasonKey,
                          array $params, ?array $euro, ?string $deadline, array $steps, bool $today): array
    {
        return $this->base('task', $rule, $utility, $id, $titleKey, $titleParams, $reasonKey, $params, $euro, $deadline, $steps, $today);
    }

    private function success(string $rule, ?string $utility, string $titleKey, array $titleParams, string $reasonKey,
                             array $params, ?array $euro, bool $today = false): array
    {
        return $this->base('success', $rule, $utility, '', $titleKey, $titleParams, $reasonKey, $params, $euro, null, [], $today);
    }

    private function info(string $rule, ?string $utility, string $titleKey, array $titleParams, string $reasonKey, array $params, bool $today): array
    {
        return $this->base('info', $rule, $utility, '', $titleKey, $titleParams, $reasonKey, $params, null, null, [], $today);
    }

    /** „ca. 120 €“ — ganze Euro, mit „ca.“ (Bericht §3). */
    public function approx(float $v): string
    {
        return $this->i18n->t('report.fmt.approx', ['amount' => $this->i18n->money(round($v), 0)]);
    }

    private function eur(float $v, int $dec = 0): string
    {
        return $this->i18n->money($dec === 0 ? round($v) : $v, $dec);
    }

    private function signedEur(float $v): string
    {
        $r = round($v);
        return ($r > 0 ? '+' : ($r < 0 ? '-' : '±')) . $this->i18n->money(abs($r), 0);
    }

    /** Prozent mit Vorzeichen, gerundet — „-0“ gibt es nicht (Mangel 2). */
    public function pct(float $v, int $dec = 0): string
    {
        $r = round($v, $dec);
        $num = $r == 0.0 ? $this->i18n->number(0, $dec) : ($r > 0 ? '+' : '-') . $this->i18n->number(abs($r), $dec);
        return $this->i18n->t('report.fmt.pct', ['value' => $num]);
    }

    /** Anteil in Prozent ohne Vorzeichen („39 %“ bzw. „39%“ je Sprache). */
    public function share(float $v, int $dec = 0): string
    {
        return $this->i18n->t('report.fmt.pct', ['value' => $this->i18n->number(round($v, $dec), $dec)]);
    }

    public static function days(string $from, string $to): int
    {
        return (int)round(((int)strtotime($to) - (int)strtotime($from)) / 86400);
    }
}
