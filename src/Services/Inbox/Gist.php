<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\TariffSwitchService;

/**
 * v3.7.0 (Paket betrieb) — Kernaussage und nächster Schritt je Dokument: Was
 * bedeutet der Brief des Versorgers, und was ist jetzt zu tun? Ein Satz, eine
 * Zahl, ein Knopf; alles Weitere steht in `details` (aufklappbar).
 *
 * Alle Zahlen kommen aus den vorhandenen Diensten, nichts wird hier neu
 * modelliert:
 *   Preisänderung      Mehrkosten im Jahr = Δ Arbeitspreis × erwarteter Verbrauch
 *                      der nächsten zwölf Monate (TariffSwitchService, dieselbe
 *                      Prognose wie die Wechselentscheidung) + Δ Grundpreis × 12;
 *                      der alte Preis aus dem Dokument, sonst aus dem Vertrag am
 *                      Vortag. Frist des Sonderkündigungsrechts aus dem Dokument.
 *                      Spart ein gespeichertes Angebot laut Wechselentscheidung,
 *                      führt der Knopf zum Tarifvergleich.
 *   Rechnung           nach der Übernahme BillService::compare (Urteil, Abweichung
 *                      in €, Gründe), dazu Nachzahlung oder Guthaben
 *   Abschlagsänderung  neuer Abschlag gegen den Abschlag, mit dem der erwartete
 *                      Saldo der laufenden Abrechnung bei null endet
 *                      (ConsumptionService::contractStatus); „passt“, solange der
 *                      Rest unter der Schwelle des Jahresberichts bleibt
 *                      (ReportAdviceService::ADVANCE_MIN_EUR, mindestens ein Abschlag)
 *   Kündigung          Lieferende; ohne Anschlussvertrag (ContractService::successorOf)
 *                      der Termin aus den Vorschlägen und der Weg zum Tarifvergleich
 *   Zählerstandsanforderung  Frist und zuständige Person (ResponsibilityService:
 *                      Vertrag, sonst Zähler), Knopf in die Erfassung des Zählers
 *   Zählerwechsel      Zählertausch-Dialog mit End- und Anfangsstand vorbelegt
 *   Mahnung            Termin mit Frist (Vorschlag: DUNNING_DAYS nach Eingang)
 *   Widerruf, Sonstiges  nur ablegen
 *
 * Form: {type, key, params, figure: {kind, value, tone, signed?}|null,
 *        action: {kind: apply|link|remind|file, key, params?, href?, due?}|null,
 *        details: [{key, params}], computed_at}
 * `figure.kind`: money_year | money_month | money | result | date | price_ct.
 * `tone`: good | warn | bad | neutral. Die Texte entstehen in der Oberfläche
 * (lib/inbox.js `gistText`), die Werte bleiben roh (Datum ISO, Beträge als Zahl).
 */
final class Gist
{
    /** Vorschlag für die Frist einer Mahnung: Tage nach dem Eingang. */
    public const DUNNING_DAYS = 7;
    /** Ein Zählerstand so kurz vor der Frist (oder danach) erledigt die Anforderung. */
    public const READING_WINDOW_DAYS = 30;

    public function __construct(
        private MeterService $meters,
        private ContractService $contracts,
        private ConsumptionService $consumption,
        private BillService $bills,
        private ReadingService $readings,
        private ?TariffSwitchService $tariffSwitch = null,
        private ?ResponsibilityService $responsibility = null,
    ) {}

    /**
     * @param array<string,mixed>       $entry     Eintrag im Posteingang
     * @param array<string,mixed>       $as        Zuordnung {utility, meter_id, contract_id}
     * @param list<array<string,mixed>> $proposals aus Proposals::build
     * @return array<string,mixed>|null null, solange die Art nicht feststeht oder nicht ausgelesen ist
     */
    public function describe(array $entry, array $as, array $proposals, ?string $today = null): ?array
    {
        $today ??= date('Y-m-d');
        $type = (string)($entry['type'] ?? '');
        if (!InboxSchema::isType($type)) return null;
        if (InboxSchema::extracts($type) && !is_array($entry['extraction']['fields'] ?? null)) return null;
        $u = is_string($as['utility'] ?? null) && Utilities::exists($as['utility']) ? $as['utility'] : null;
        $meter = $u !== null && !empty($as['meter_id']) ? $this->meters->get($u, (string)$as['meter_id']) : null;
        $contract = $u !== null && !empty($as['contract_id']) && Utilities::hasContracts($u) ? $this->contracts->get($u, (string)$as['contract_id']) : null;
        $c = ['entry' => $entry, 'u' => $u, 'meter' => $meter, 'contract' => $contract, 'proposals' => $proposals, 'today' => $today];
        $g = match ($type) {
            'price_change'          => $this->priceChange($c),
            'invoice'               => $this->invoice($c),
            'advance_change'        => $this->advanceChange($c),
            'cancellation'          => $this->cancellation($c),
            'reading_request'       => $this->readingRequest($c),
            'meter_change'          => $this->meterChange($c),
            'contract_confirmation' => $this->confirmation($c),
            'supply_start'          => $this->supplyStart($c),
            'dunning'               => $this->dunning($c),
            default                 => $this->filed($c),
        };
        // ohne Zuordnung fehlt jede Zahl, die am Bestand hängt
        if ($u === null && InboxSchema::extracts($type)) array_unshift($g['details'], ['key' => 'inbox.gist.detail.assignFirst', 'params' => []]);
        // v3.7.0 (treffsicher) — offene Punkte der Vorschläge (fehlt im Dokument / unsicher,
        // nicht übernommen) und ein inhaltsgleiches Dokument (InboxService: `duplicates`)
        $open = ['missing' => [], 'unsure' => []];
        foreach ($proposals as $p) {
            if (($p['status'] ?? '') === 'exists') continue;
            foreach ((array)($p['open'] ?? []) as $o) {
                if (isset($open[$o['reason'] ?? '']) && !in_array($o['field'], $open[$o['reason']], true)) $open[$o['reason']][] = (string)$o['field'];
            }
        }
        foreach (['missing' => 'inbox.note.missing', 'unsure' => 'inbox.note.unsure'] as $r => $k) {
            if ($open[$r] !== []) $g['details'][] = ['key' => $k, 'params' => ['fields' => $open[$r]]];
        }
        // Den Hinweis mit Sprung zeigt die Vorschau in dieser Karte (lib/inbox-marks.js duplicateHtml);
        // hat das andere Dokument schon alles übernommen, ist der nächste Schritt: ablegen
        $dup = (array)(($entry['duplicates'] ?? [])[0] ?? []);
        $g['duplicate_of'] = !empty($dup['id']) ? (string)$dup['id'] : null;
        if ($g['duplicate_of'] !== null && $g['action'] === null && !in_array($entry['status'] ?? '', ['done', 'filed'], true)) {
            $g['action'] = ['kind' => 'file', 'key' => 'inbox.gist.action.file'];
        }
        return ['type' => $type] + $g + ['computed_at' => date('c')];
    }

    // ── je Art ───────────────────────────────────────────────────────────

    private function priceChange(array $c): array
    {
        $e = $c['entry']; $ct = $c['contract'];
        $from = self::str(Proposals::value($e, 'valid_from'));
        $provider = (string)($ct['provider'] ?? Proposals::value($e, 'supplier') ?? '');
        $before = $from !== null ? date('Y-m-d', (int)strtotime("$from -1 day")) : null;
        $year = Proposals::value($e, 'base_price_period') === 'year';
        $wpNew = self::num(Proposals::value($e, 'working_price_new'));
        $wpOld = self::num(Proposals::value($e, 'working_price_old'))
            ?? ($ct !== null && $before !== null ? $this->contracts->valueOnDate((array)($ct['working_prices'] ?? []), 'ct_per_kwh', $before) : null);
        $bpNew = self::num(Proposals::value($e, 'base_price_new'));
        $bpOld = self::num(Proposals::value($e, 'base_price_old'));
        if ($bpNew !== null && $year) $bpNew /= 12;
        if ($bpOld !== null && $year) $bpOld /= 12;
        $bpOld ??= $ct !== null && $before !== null ? $this->contracts->valueOnDate((array)($ct['base_prices'] ?? []), 'eur_per_month', $before) : null;

        $switch = $this->switchView($c);
        $kwh = $switch['kwh'] ?? null;
        $feedIn = $c['u'] !== null && Utilities::isFeedIn($c['u']);
        $extra = null;
        $wpChanged = $wpNew !== null && $wpOld !== null && abs($wpNew - $wpOld) > 1e-9;
        if (!$feedIn && ($wpNew === null || $wpOld === null || !$wpChanged || $kwh !== null)) {
            $parts = 0;
            if ($wpChanged && $kwh !== null) { $extra = ($extra ?? 0.0) + ($wpNew - $wpOld) * $kwh / 100; $parts++; }
            if ($bpNew !== null && $bpOld !== null) { $extra = ($extra ?? 0.0) + ($bpNew - $bpOld) * 12; $parts++; }
            if ($parts === 0) $extra = null;
        }
        $details = [];
        if ($wpNew !== null) $details[] = ['key' => 'inbox.gist.detail.workingPrice', 'params' => ['old' => $wpOld, 'new' => $wpNew]];
        if ($bpNew !== null) $details[] = ['key' => 'inbox.gist.detail.basePrice', 'params' => ['old' => $bpOld !== null ? round($bpOld, 2) : null, 'new' => round($bpNew, 2)]];
        if ($kwh !== null) $details[] = ['key' => 'inbox.gist.detail.basis', 'params' => ['kwh' => round($kwh), 'unit' => $switch['unit'] ?? 'kWh']];
        elseif ($wpChanged && !$feedIn) $details[] = ['key' => 'inbox.gist.detail.noForecast', 'params' => []];
        $until = self::str(Proposals::value($e, 'special_termination_until'));
        if ($until !== null) $details[] = ['key' => $until < $c['today'] ? 'inbox.gist.detail.terminationPassed' : 'inbox.gist.detail.termination', 'params' => ['date' => $until]];
        $saving = $switch['saving'] ?? null;
        if ($saving !== null) $details[] = ['key' => 'inbox.gist.detail.saving', 'params' => ['amount' => $saving, 'offer' => $switch['offer']]];
        elseif (($switch['offers'] ?? 0) === 0 && $switch !== null) $details[] = ['key' => 'inbox.gist.detail.noOffers', 'params' => []];
        elseif ($switch !== null) $details[] = ['key' => 'inbox.gist.detail.noBetterOffer', 'params' => []];

        $key = $extra === null ? 'inbox.gist.priceChange.new'
            : ($extra > 0.5 ? 'inbox.gist.priceChange.more' : ($extra < -0.5 ? 'inbox.gist.priceChange.less' : 'inbox.gist.priceChange.same'));
        $figure = $extra !== null
            ? ['kind' => 'money_year', 'value' => round($extra, 2), 'tone' => $extra > 0.5 ? 'bad' : 'good', 'signed' => true]
            : ($wpNew !== null ? ['kind' => 'price_ct', 'value' => $wpNew, 'tone' => 'neutral'] : null);
        $action = $saving !== null
            ? ['kind' => 'link', 'key' => 'inbox.gist.action.compareTariffs', 'params' => ['amount' => $saving], 'href' => '#/tariffs']
            : $this->applyAction($c, 'inbox.gist.action.applyPrices');
        return ['key' => $key, 'params' => ['date' => $from, 'provider' => $provider, 'amount' => $extra !== null ? round(abs($extra), 2) : null],
            'figure' => $figure, 'action' => $action, 'details' => $details];
    }

    private function invoice(array $c): array
    {
        $e = $c['entry']; $u = $c['u'];
        $bill = self::proposal($c['proposals'], 'bill');
        $from = self::str(Proposals::value($e, 'period_from'));
        $to = self::str(Proposals::value($e, 'period_to'));
        $result = $bill['data']['invoice']['result_eur'] ?? null;
        if ($result === null) {
            // v3.7.0 (treffsicher) — Vorzeichen wie in den Vorschlägen, ohne Art kein stilles „Nachzahlung“
            $amount = self::num(Proposals::value($e, 'result_amount'));
            $sign = Proposals::resultSign($e);
            if ($amount !== null && $sign !== null) $result = round($sign * abs($amount), 2);
        }
        $billId = self::appliedRef($e, 'bill') ?? ($bill !== null && $bill['status'] === 'exists' ? ($bill['detail']['bill_id'] ?? null) : null);
        $cmp = null;
        if ($u !== null && is_string($billId) && $billId !== '') {
            try { $cmp = $this->bills->compare($u, $billId); } catch (\Throwable) { $cmp = null; }
        }
        $details = [];
        $params = ['from' => $from, 'to' => $to];
        if ($cmp === null) {
            $key = 'inbox.gist.invoice.pending';
        } elseif ($cmp['verdict'] === 'ok') {
            $key = 'inbox.gist.invoice.ok';
        } elseif ($cmp['verdict'] === 'no_prices') {
            // v3.7.0 (treffsicher) — ohne Preise ist der Betrag nicht nachzurechnen: wo sie fehlen, und der Weg zum Vertrag
            $key = 'inbox.gist.invoice.noPrices';
            foreach ((array)($cmp['price_missing_at'] ?? []) as $pm) {
                $details[] = ['key' => !empty($pm['contract_id']) ? 'billCheck.noPrices.at' : 'billCheck.noPrices.noContract',
                    'params' => ['from' => $pm['from'] ?? null, 'to' => $pm['to'] ?? null, 'provider' => $pm['provider'] ?? null]];
            }
        } elseif ($cmp['verdict'] === 'check' && (array)($cmp['price_missing_at'] ?? []) !== []) {
            // v3.7.0 (treffsicher, Nachbesserung) — die Menge weicht ab UND Preise fehlen: der
            // Satz nennt beides (der Betrag ohne Preise sagt nichts), die Abschnitte stehen darunter
            $qty = $cmp['delta'][($u === 'wasser' ? 'm3' : 'kwh') . '_pct'] ?? null;
            $key = 'inbox.gist.invoice.offQuantityNoPrices';
            $params['pct'] = $qty !== null ? round(abs((float)$qty), 1) : null;
            foreach ((array)$cmp['price_missing_at'] as $pm) {
                $details[] = ['key' => !empty($pm['contract_id']) ? 'billCheck.noPrices.at' : 'billCheck.noPrices.noContract',
                    'params' => ['from' => $pm['from'] ?? null, 'to' => $pm['to'] ?? null, 'provider' => $pm['provider'] ?? null]];
            }
        } elseif ($cmp['verdict'] === 'check' && ($cmp['delta']['eur'] ?? null) !== null && abs((float)$cmp['delta']['eur']) > 0.005) {
            $d = (float)$cmp['delta']['eur'];
            $key = $d > 0 ? 'inbox.gist.invoice.offMore' : 'inbox.gist.invoice.offLess';
            $params['amount'] = round(abs($d), 2);
        } elseif ($cmp['verdict'] === 'check') {
            $qty = $cmp['delta'][($u === 'wasser' ? 'm3' : 'kwh') . '_pct'] ?? null;
            $key = 'inbox.gist.invoice.offQuantity';
            $params['pct'] = $qty !== null ? round(abs((float)$qty), 1) : null;
        } else {
            $key = 'inbox.gist.invoice.noCompare';
        }
        if ($cmp !== null) {
            foreach ((array)($cmp['reasons'] ?? []) as $r) $details[] = ['key' => 'billCheck.reason.' . $r, 'params' => []];
            if (isset($cmp['invoice']['amount_eur'])) {
                $details[] = ['key' => 'inbox.gist.detail.billTotals', 'params' => ['theirs' => (float)$cmp['invoice']['amount_eur'], 'ours' => (float)$cmp['ours']['total']]];
            }
        }
        $adv = self::num(Proposals::value($e, 'advance_new'));
        if ($adv !== null) {
            $advFrom = self::str(Proposals::value($e, 'advance_new_from'));
            $details[] = ['key' => 'inbox.gist.detail.newAdvance', 'params' => ['amount' => $adv, 'date' => $advFrom]];
            if ($advFrom !== null) {
                $v = $this->advanceVerdict($c, $adv, $advFrom);
                if ($v !== null) $details[] = ['key' => 'inbox.gist.advance.' . $v['verdict'], 'params' => ['amount' => $adv, 'diff' => $v['diff'], 'recommended' => $v['recommended']]];
            }
        }
        $figure = is_numeric($result) && (float)$result != 0.0
            ? ['kind' => 'result', 'value' => (float)$result, 'tone' => (float)$result > 0 ? 'bad' : 'good'] : null;
        $action = $this->applyAction($c, 'inbox.gist.action.applyCheck');
        if ($action === null && $cmp !== null && $cmp['verdict'] === 'no_prices' && $u !== null) {
            $action = ['kind' => 'link', 'key' => 'inbox.gist.action.addPrices', 'href' => '#/utility/' . rawurlencode($u) . '/contracts'];
        } elseif ($action === null && $cmp !== null && $cmp['verdict'] !== 'ok' && $u !== null && $from !== null && $to !== null) {
            $action = ['kind' => 'link', 'key' => 'inbox.gist.action.openCheck',
                'href' => '#/bill-check?' . http_build_query(['utility' => $u, 'meter' => (string)$cmp['meter_id'], 'from' => $from, 'until' => $to])];
        }
        return ['key' => $key, 'params' => $params + ['result' => $result], 'figure' => $figure, 'action' => $action, 'details' => $details,
            'check' => $cmp !== null ? ['verdict' => $cmp['verdict'], 'delta_eur' => $cmp['delta']['eur'] ?? null, 'reasons' => $cmp['reasons'] ?? [], 'bill_id' => $billId] : null];
    }

    private function advanceChange(array $c): array
    {
        $e = $c['entry'];
        $new = self::num(Proposals::value($e, 'advance_new'));
        $from = self::str(Proposals::value($e, 'valid_from'));
        $v = $new !== null && $from !== null ? $this->advanceVerdict($c, $new, $from) : null;
        $details = [];
        if ($v === null) {
            $key = 'inbox.gist.advance.unknown';
            $figure = $new !== null ? ['kind' => 'money_month', 'value' => $new, 'tone' => 'neutral'] : null;
            $details[] = ['key' => $c['contract'] === null ? 'inbox.gist.detail.noContract' : 'inbox.gist.detail.noProjection', 'params' => []];
        } else {
            $key = 'inbox.gist.advance.' . $v['verdict'];
            $figure = $v['verdict'] === 'ok'
                ? ['kind' => 'money_month', 'value' => $new, 'tone' => 'good']
                : ['kind' => 'money_month', 'value' => $v['diff'], 'tone' => $v['verdict'] === 'low' ? 'bad' : 'warn', 'signed' => true];
            $details[] = ['key' => 'inbox.gist.detail.advanceBasis', 'params' => ['recommended' => $v['recommended'], 'months' => $v['months'], 'balance' => $v['balance']]];
        }
        $action = $this->applyAction($c, 'inbox.gist.action.applyAdvance');
        return ['key' => $key, 'params' => ['amount' => $new, 'date' => $from, 'diff' => $v !== null ? abs($v['diff']) : null,
            'recommended' => $v['recommended'] ?? null], 'figure' => $figure, 'action' => $action, 'details' => $details];
    }

    private function cancellation(array $c): array
    {
        $e = $c['entry']; $ct = $c['contract'];
        $end = self::str(Proposals::value($e, 'supply_end'));
        $follow = $ct !== null && $c['u'] !== null ? $this->followUp($c['u'], $c['meter'], $ct) : null;
        $details = [];
        if (Proposals::value($e, 'cancel_kind') === 'switch') $details[] = ['key' => 'inbox.gist.detail.switch', 'params' => []];
        $rem = self::proposal($c['proposals'], 'reminder');
        if ($rem !== null && $rem['data']['label'] === 'followUp') {
            $details[] = ['key' => 'inbox.gist.detail.followUpReminder', 'params' => ['date' => $rem['data']['next_due'], 'person' => $rem['data']['assignee'] ?? null]];
        }
        if ($follow !== null) {
            $key = 'inbox.gist.cancellation.followed';
            $params = ['date' => $end, 'provider' => $follow['provider'], 'start' => $follow['start']];
            if ($follow['gap_days'] > 0) $details[] = ['key' => 'inbox.gist.detail.gap', 'params' => ['days' => $follow['gap_days']]];
            $action = $this->applyAction($c, 'inbox.gist.action.applyEnd');
        } else {
            $key = $ct === null ? 'inbox.gist.cancellation.end' : 'inbox.gist.cancellation.open';
            $params = ['date' => $end];
            $action = $ct !== null ? ['kind' => 'link', 'key' => 'inbox.gist.action.findFollowUp', 'href' => '#/tariffs'] : $this->applyAction($c, 'inbox.gist.action.applyEnd');
        }
        $figure = $end !== null ? ['kind' => 'date', 'value' => $end, 'tone' => $follow !== null ? 'neutral' : 'bad'] : null;
        return ['key' => $key, 'params' => $params + ['provider_old' => (string)($ct['provider'] ?? '')], 'figure' => $figure, 'action' => $action, 'details' => $details];
    }

    private function readingRequest(array $c): array
    {
        $e = $c['entry']; $m = $c['meter']; $u = $c['u'];
        $by = self::str(Proposals::value($e, 'requested_by'));
        $who = $this->responsibility?->inherit($c['contract'], $m) ?? ResponsibilityService::NOBODY;
        $person = is_string($who['assignee'] ?? null) && empty($who['assignee_deleted']) ? $who['assignee'] : null;
        $name = (string)($m['name'] ?? Proposals::value($e, 'meter_number') ?? '');
        $last = null;
        if ($m !== null && $u !== null && Utilities::isCumulative($u)) {
            foreach ($this->readings->list($u, (string)$m['id']) as $r) {
                if (!empty($r['is_future'])) continue;
                if ($last === null || (string)$r['date'] > $last) $last = (string)$r['date'];
            }
        }
        $done = $by !== null && $last !== null && $last >= date('Y-m-d', (int)strtotime("$by -" . self::READING_WINDOW_DAYS . ' days'));
        $details = [];
        if ($person !== null) $details[] = ['key' => 'inbox.gist.detail.person', 'params' => ['person' => $person, 'source' => $who['assignee_source']]];
        else $details[] = ['key' => 'inbox.gist.detail.nobody', 'params' => []];
        if ($last !== null) $details[] = ['key' => 'inbox.gist.detail.lastReading', 'params' => ['date' => $last]];
        if ($done) {
            $key = 'inbox.gist.reading.done';
            $action = null;
        } else {
            $key = $person !== null ? 'inbox.gist.reading.who' : 'inbox.gist.reading.todo';
            $action = $m !== null ? ['kind' => 'link', 'key' => 'inbox.gist.action.enterReading', 'href' => '#/zaehlerstaende?meter=' . rawurlencode((string)$m['id'])] : null;
        }
        $tone = $done ? 'good' : ($by !== null && $by < $c['today'] ? 'bad' : ($by !== null && $by <= date('Y-m-d', (int)strtotime($c['today'] . ' +7 days')) ? 'warn' : 'neutral'));
        return ['key' => $key, 'params' => ['date' => $by, 'meter' => $name, 'person' => $person, 'last' => $last],
            'figure' => $by !== null ? ['kind' => 'date', 'value' => $by, 'tone' => $tone] : null, 'action' => $action, 'details' => $details];
    }

    private function meterChange(array $c): array
    {
        $p = self::proposal($c['proposals'], 'meter_change');
        $e = $c['entry'];
        $date = self::str(Proposals::value($e, 'change_date'));
        $old = self::num(Proposals::value($e, 'old_reading'));
        $new = self::num(Proposals::value($e, 'new_reading'));
        $serial = self::str(Proposals::value($e, 'new_meter_number'));
        $params = ['date' => $date, 'old' => $old, 'new' => $new, 'serial' => $serial];
        $details = [];
        if ($p !== null && $p['status'] === 'conflict') $details[] = ['key' => 'inbox.gist.detail.serialConflict', 'params' => ['serial' => (string)($p['detail']['serial'] ?? '')]];
        if ($p !== null && $p['status'] === 'exists') {
            return ['key' => 'inbox.gist.meterChange.done', 'params' => $params,
                'figure' => $date !== null ? ['kind' => 'date', 'value' => $date, 'tone' => 'good'] : null, 'action' => null, 'details' => $details];
        }
        $action = null;
        if ($c['meter'] !== null && $c['u'] !== null && Utilities::isCumulative($c['u'])) {
            $q = array_filter(['replace' => (string)$c['meter']['id'], 'date' => $date, 'old' => $old, 'new' => $new, 'serial' => $serial,
                'inbox' => (string)($e['id'] ?? '')], fn($v) => $v !== null && $v !== '');
            $action = ['kind' => 'link', 'key' => 'inbox.gist.action.openSwap', 'href' => '#/utility/' . rawurlencode($c['u']) . '/meters?' . http_build_query($q)];
        }
        return ['key' => 'inbox.gist.meterChange.todo', 'params' => $params,
            'figure' => $date !== null ? ['kind' => 'date', 'value' => $date, 'tone' => 'warn'] : null, 'action' => $action, 'details' => $details];
    }

    private function confirmation(array $c): array
    {
        $e = $c['entry'];
        $p = self::proposal($c['proposals'], 'contract_create');
        $start = self::str(Proposals::value($e, 'supply_start'));
        $adv = self::num(Proposals::value($e, 'advance'));
        $wp = self::num(Proposals::value($e, 'working_price'));
        $details = [];
        if ($wp !== null) $details[] = ['key' => 'inbox.gist.detail.workingPrice', 'params' => ['old' => null, 'new' => $wp]];
        if (($p['data']['min_term_end'] ?? null) !== null) $details[] = ['key' => 'inbox.gist.detail.minTerm', 'params' => ['date' => $p['data']['min_term_end']]];
        if (($p['data']['price_guarantee_until'] ?? null) !== null) $details[] = ['key' => 'inbox.gist.detail.guarantee', 'params' => ['date' => $p['data']['price_guarantee_until']]];
        $done = $p !== null && $p['status'] === 'exists';
        return ['key' => $done ? 'inbox.gist.confirmation.done' : 'inbox.gist.confirmation.todo',
            'params' => ['provider' => (string)(Proposals::value($e, 'supplier') ?? ''), 'date' => $start],
            'figure' => $adv !== null ? ['kind' => 'money_month', 'value' => $adv, 'tone' => 'neutral'] : ($wp !== null ? ['kind' => 'price_ct', 'value' => $wp, 'tone' => 'neutral'] : null),
            'action' => $this->applyAction($c, 'inbox.gist.action.applyContract'), 'details' => $details];
    }

    private function supplyStart(array $c): array
    {
        $e = $c['entry'];
        $start = self::str(Proposals::value($e, 'supply_start'));
        return ['key' => 'inbox.gist.supplyStart', 'params' => ['provider' => (string)(Proposals::value($e, 'supplier') ?? ''), 'date' => $start],
            'figure' => $start !== null ? ['kind' => 'date', 'value' => $start, 'tone' => 'neutral'] : null,
            'action' => $this->applyAction($c, 'inbox.gist.action.apply'), 'details' => []];
    }

    private function dunning(array $c): array
    {
        $e = $c['entry'];
        $set = null;
        foreach ((array)($e['applied'] ?? []) as $a) {
            if (($a['kind'] ?? '') === 'reminder') $set = substr((string)($a['key'] ?? ''), strlen('dunning@')) ?: null;
        }
        if ($set !== null) {
            return ['key' => 'inbox.gist.dunning.set', 'params' => ['date' => $set],
                'figure' => ['kind' => 'date', 'value' => $set, 'tone' => $set < $c['today'] ? 'bad' : 'warn'], 'action' => null, 'details' => []];
        }
        $base = substr((string)($e['created_at'] ?? ''), 0, 10) ?: $c['today'];
        $due = max($c['today'], date('Y-m-d', (int)strtotime("$base +" . self::DUNNING_DAYS . ' days')));
        return ['key' => 'inbox.gist.dunning.todo', 'params' => ['date' => $due],
            'figure' => ['kind' => 'date', 'value' => $due, 'tone' => 'bad'],
            'action' => ['kind' => 'remind', 'key' => 'inbox.gist.action.remind', 'due' => $due], 'details' => [['key' => 'inbox.gist.detail.dunning', 'params' => []]]];
    }

    private function filed(array $c): array
    {
        $filed = ($c['entry']['status'] ?? '') === 'filed';
        return ['key' => $filed ? 'inbox.gist.filed' : 'inbox.gist.fileIt', 'params' => [], 'figure' => null,
            'action' => $filed ? null : ['kind' => 'file', 'key' => 'inbox.gist.action.file'], 'details' => []];
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    /**
     * Neuer Abschlag gegen den Abschlag, mit dem die laufende Abrechnung bei null
     * endet: Abschlag laut Plan ab `$from` + erwarteter Endsaldo ÷ Monate ab
     * `$from` bis zum Ende der Abrechnung. Dieselbe Rechnung, egal ob der neue
     * Abschlag schon im Vertrag steht (dann ist er Teil von Plan und Saldo).
     *
     * @return array{verdict: string, recommended: float, diff: float, months: int, balance: float}|null
     */
    public function advanceVerdict(array $c, float $new, string $from): ?array
    {
        $u = $c['u']; $ct = $c['contract'];
        if ($u === null || $ct === null) return null;
        $gid = (string)($ct['meter_group_id'] ?? '');
        $targetId = $gid !== '' ? $gid : (string)($ct['meter_id'] ?? ($c['meter']['id'] ?? ''));
        $target = $targetId !== '' ? $this->meters->target($u, $targetId) : null;
        if ($target === null) return null;
        try {
            $status = $this->consumption->contractStatus($u, $target);
        } catch (\Throwable) {
            return null;
        }
        $row = null;
        foreach ($status['contracts'] ?? [] as $r) if (($r['contract_id'] ?? null) === $ct['id']) $row = $r;
        if ($row === null || !is_array($row['balance_path'] ?? null) || ($row['projected_end_balance'] ?? null) === null) return null;
        $ym = substr($from, 0, 7);
        $months = count(array_filter($row['balance_path'], fn($p) => (string)($p['ym'] ?? '') >= $ym));
        if ($months < 1) return null;
        $plan = $this->contracts->effectiveAdvanceSchedule($ct);
        $atFrom = $this->contracts->valueOnDate($plan, 'amount_eur', $from) ?? 0.0;
        $rec = max(0.0, round($atFrom + (float)$row['projected_end_balance'] / $months));
        $balance = round(($rec - $new) * $months, 2);   // Buchhaltungssicht: positiv = Nachzahlung
        $limit = max(ReportAdviceService::ADVANCE_MIN_EUR, $new);
        $verdict = abs($balance) < $limit ? 'ok' : ($new > $rec ? 'high' : 'low');
        return ['verdict' => $verdict, 'recommended' => $rec, 'diff' => round($new - $rec, 2), 'months' => $months, 'balance' => $balance];
    }

    /** Anschlussvertrag desselben Zählers bzw. seiner Gruppe (ContractService::successorOf). */
    public function followUp(string $u, ?array $meter, array $contract): ?array
    {
        $list = $this->contracts->list($u, (string)($meter['id'] ?? $contract['meter_id'] ?? ''));
        $gid = (string)($contract['meter_group_id'] ?? ($meter['meter_group_id'] ?? ''));
        if ($gid !== '') $list = [...$list, ...$this->contracts->list($u, $gid)];
        return ContractService::successorOf($contract, $list);
    }

    /**
     * Erwarteter Verbrauch und bestes Angebot laut Wechselentscheidung; null ohne
     * Prognose (zu wenig Monate, Wasser, Lieferarten) oder ohne Zähler.
     *
     * @return array{kwh: ?float, unit: string, saving: ?float, offer: ?string, offers: int}|null
     */
    private function switchView(array $c): ?array
    {
        $u = $c['u']; $m = $c['meter'];
        if ($this->tariffSwitch === null || $u === null || $m === null || !Utilities::hasAdvancePaymentContracts($u)) return null;
        try {
            $a = $this->tariffSwitch->analyze($u, (string)$m['id'], ['today' => $c['today']]);
        } catch (\Throwable) {
            return null;
        }
        if (empty($a['supported'])) return null;
        $best = null; $offers = 0;
        foreach ($a['candidates'] ?? [] as $cand) {
            if (!empty($cand['is_reference'])) continue;
            $offers++;
            if (($cand['vs_reference_year2_eur'] ?? null) === null) continue;
            if ($best === null || $cand['vs_reference_year2_eur'] < $best['vs_reference_year2_eur']) $best = $cand;
        }
        $saving = $best !== null && (float)$best['vs_reference_year2_eur'] < -0.5 ? round(-(float)$best['vs_reference_year2_eur'], 2) : null;
        return ['kwh' => isset($a['expected_consumption']) ? (float)$a['expected_consumption'] : null, 'unit' => (string)($a['unit'] ?? 'kWh'),
            'saving' => $saving, 'offer' => $saving !== null ? (string)($best['label'] ?? '') : null, 'offers' => $offers];
    }

    /** „Übernehmen“, solange etwas Angehaktes aussteht und das Dokument nicht erledigt ist. */
    private function applyAction(array $c, string $key): ?array
    {
        if (in_array($c['entry']['status'] ?? '', ['done', 'filed'], true)) return null;
        foreach ($c['proposals'] as $p) {
            if (!empty($p['checked'])) return ['kind' => 'apply', 'key' => $key];
        }
        return null;
    }

    /** @param list<array<string,mixed>> $proposals */
    private static function proposal(array $proposals, string $kind): ?array
    {
        foreach ($proposals as $p) if (($p['kind'] ?? '') === $kind) return $p;
        return null;
    }

    /** ID des Datensatzes, den ein übernommener Vorschlag dieser Art angelegt hat. */
    private static function appliedRef(array $entry, string $type): ?string
    {
        $id = null;
        foreach ((array)($entry['applied'] ?? []) as $a) {
            if (($a['ref']['type'] ?? null) === $type && is_string($a['ref']['id'] ?? null)) $id = $a['ref']['id'];
        }
        return $id;
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float)$v : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
