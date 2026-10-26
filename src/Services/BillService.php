<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H5, B3/UI-35) — Versorgerrechnungen erfassen und gegen die
 * eigene Rechnung halten.
 *
 * Topf `<art>/bills.json`, ein Eintrag:
 *   {id 'b_…', meter_id, contract_id?, kind annual|final|interim, period_from,
 *    period_to (inklusive), issued_on?, invoice {energy_kwh?, volume_m3?,
 *    amount_eur, advances_paid_eur?, result_eur? (positiv = Nachzahlung)},
 *    items [{label, amount_eur, kind levy|fee|credit|other}],
 *    co2? {emissions_kg, cost_eur?, stated_factor?}, attachment_ids [],
 *    special_payment_id?, note, created_at}
 *
 * `compare()` rechnet den Zeitraum mit billBreakdown() nach (dieselben Bausteine
 * wie Monatssicht und Saldo) und nennt die Abweichung; `book()` legt das
 * Ergebnis als Sonderzahlung im Vertrag an (einmal — erneutes Buchen ändert
 * nichts). Freie Posten (Umlagen) bildet die App nicht nach; sie kommen zur
 * eigenen Summe dazu, damit der Vergleich aufgeht.
 */
final class BillService
{
    public const KINDS = ['annual', 'final', 'interim'];
    public const ITEM_KINDS = ['levy', 'fee', 'credit', 'other'];
    /** ok: |Δ Menge| ≤ 1 % und (|Δ €| ≤ 1 % oder ≤ 2 €) */
    private const OK_PCT = 1.0;
    private const OK_EUR = 2.0;

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ContractService $contracts,
        private ConsumptionService $consumption,
        private I18nService $i18n,
    ) {}

    /** v3.5.0 (F1029) — Verlauf und erwartete Abrechnungen (nachträglich; beide kennen diesen Dienst). */
    private ?ActivityService $activity = null;
    private ?BillExpectedService $expected = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    public function attachExpectations(BillExpectedService $expected): void
    {
        $this->expected = $expected;
    }

    /**
     * v3.5.0 (F1029) — Erwartungen dieser Art, die vor dem Schreiben offen waren.
     *
     * @return list<array<string,mixed>>
     */
    private function openBefore(string $utility): array
    {
        if ($this->activity === null || $this->expected === null) return [];
        try {
            return array_values(array_filter($this->expected->open(), fn($x) => $x['utility'] === $utility));
        } catch (\Throwable) {
            return [];   // der Verlauf darf das Speichern einer Rechnung nie verhindern
        }
    }

    /**
     * v3.5.0 (F1029) — Nach dem Schreiben: Erledigungen durch diese Rechnung,
     * die sie nicht mehr abdeckt, durchstreichen; Erwartungen, die sie jetzt
     * abdeckt (und die vorher offen waren), im Verlauf erledigen.
     *
     * @param list<array<string,mixed>> $openBefore
     * @param array<string,mixed>|null  $bill die Rechnung nach dem Schreiben (null = gelöscht)
     */
    private function noteExpectations(string $utility, string $billId, array $openBefore, ?array $bill): void
    {
        if ($this->activity === null || $this->expected === null) return;
        try {
            $covered = [];
            foreach ($this->expected->expected() as $x) {
                if ($x['utility'] === $utility && $x['bill_id'] === $billId) $covered[(string)$x['uid']] = $x;
            }
            $this->activity->billRevoked($billId, function (string $occurrence) use ($covered): bool {
                foreach ($covered as $x) if (ActivityService::occurrence((string)$x['uid'], (string)$x['until']) === $occurrence) return true;
                return false;
            });
            if ($bill === null) return;
            foreach ($openBefore as $x) {
                if (isset($covered[(string)$x['uid']])) $this->activity->billRecorded($covered[(string)$x['uid']], $bill);
            }
        } catch (\Throwable) {
            // wie oben: nur der Verlauf fehlt, die Rechnung ist gespeichert
        }
    }

    /** @return list<array<string,mixed>> neueste zuerst */
    public function list(string $utility, ?string $meterId = null): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        $all = is_array($all) ? array_values(array_filter($all, 'is_array')) : [];
        if ($meterId !== null) $all = array_values(array_filter($all, fn($b) => ($b['meter_id'] ?? null) === $meterId));
        usort($all, fn($a, $b) => strcmp((string)($b['period_to'] ?? ''), (string)($a['period_to'] ?? '')));
        // v3.5.0 (GitHub #34) — Bei Wasser kam die Buchung bis v3.4 nie an
        // (ContractService::update() verwarf das Feld still), die Rechnung hieß
        // trotzdem „gebucht“. Erkennbar daran, dass ihr Vertrag das Feld
        // `special_payments` gar nicht hat — jede Buchung legt es an. Solche
        // Rechnungen gelten als nicht gebucht (gelesen, nicht gespeichert) und
        // lassen sich jetzt buchen; book() überschreibt die ID. Bewusst nicht
        // „ID fehlt im Vertrag“: Der Vertragsdialog vergibt beim Speichern neue
        // IDs, gebuchte Gas- und Stromrechnungen sähen sonst ungebucht aus.
        $withoutField = [];
        foreach ($this->contracts->list($utility) as $c) {
            if (!array_key_exists('special_payments', $c)) $withoutField[(string)($c['id'] ?? '')] = true;
        }
        foreach ($all as &$b) {
            if (!empty($b['special_payment_id']) && isset($withoutField[(string)($b['contract_id'] ?? '')])) $b['special_payment_id'] = null;
        }
        unset($b);
        return $all;
    }

    /** @return array<string,mixed> */
    public function get(string $utility, string $id): array
    {
        foreach ($this->list($utility) as $b) if (($b['id'] ?? null) === $id) return $b;
        throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
    }

    /** @return array<string,mixed> */
    public function create(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $meterId = (string)($input['meter_id'] ?? $this->meters->defaultId($utility));
        if (!$this->meters->get($utility, $meterId)) {
            throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }
        $b = ['id' => 'b_' . bin2hex(random_bytes(6)), 'meter_id' => $meterId]
            + $this->normalize($input, null) + ['attachment_ids' => [], 'created_at' => date('c')];
        $this->linkAttachments($utility, $b, [], $input['attachment_ids'] ?? []);
        $openBefore = $this->openBefore($utility);   // v3.5.0 (F1029)
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        $all[] = $b;
        $this->store->write("$utility/bills.json", array_values($all));
        $this->noteExpectations($utility, (string)$b['id'], $openBefore, $b);
        return $b;
    }

    /** @return array<string,mixed> */
    public function update(string $utility, string $id, array $input): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $b) {
            if (!is_array($b) || ($b['id'] ?? null) !== $id) continue;
            $next = array_merge($b, $this->normalize($input, $b));
            if (array_key_exists('attachment_ids', $input)) $this->linkAttachments($utility, $next, (array)($b['attachment_ids'] ?? []), $input['attachment_ids']);
            // v3.5.0 (F1029) — nur wenn Zeitraum, Zähler oder Vertrag sich ändern
            $moved = array_intersect_key($next, array_flip(['period_to', 'meter_id', 'contract_id'])) !== array_intersect_key($b, array_flip(['period_to', 'meter_id', 'contract_id']));
            $openBefore = $moved ? $this->openBefore($utility) : [];
            $all[$i] = $next;
            $this->store->write("$utility/bills.json", array_values($all));
            if ($moved) $this->noteExpectations($utility, $id, $openBefore, $next);
            return $next;
        }
        throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
    }

    public function delete(string $utility, string $id): void
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($b) => is_array($b) && ($b['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
        $this->store->write("$utility/bills.json", $kept);
        AttachmentService::unlinkRef($this->store, 'bill', $id);
        $this->noteExpectations($utility, $id, [], null);   // v3.5.0 (F1029) — Hinweis wieder offen
    }

    /**
     * Eigene Rechnung gegen die Rechnung des Versorgers.
     *
     * @return array<string,mixed>
     */
    public function compare(string $utility, string $id): array
    {
        $b = $this->get($utility, $id);
        $meter = $this->meters->get($utility, (string)$b['meter_id'])
            ?? throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => (string)$b['meter_id']]));
        $toExcl = date('Y-m-d', (int)strtotime((string)$b['period_to'] . ' +1 day'));
        $meter = $this->billTarget($utility, $meter, (string)$b['period_from'], (string)$b['period_to']);
        $bd = $this->consumption->billBreakdown($utility, $meter, (string)$b['period_from'], $toExcl);
        $isWater = $utility === 'wasser';
        $qtyKey = $isWater ? 'm3' : 'kwh';
        $items = array_sum(array_map(fn($x) => (float)($x['amount_eur'] ?? 0), (array)($b['items'] ?? [])));
        // v3.9.0 — Endstand der Rechnung ist dein gemeldeter Stand eines früheren Tags (`end_reading_on`, aus dem
        // Posteingang): Menge und Arbeitspreis bis zu diesem Tag, feste Kosten über den ganzen Zeitraum — wie der
        // Versorger rechnet; der Rest folgt in seiner nächsten Rechnung
        $endOn = (string)($b['end_reading_on'] ?? '');
        $cut = $endOn !== '' && $endOn >= (string)$b['period_from'] && $endOn < (string)$b['period_to'];
        // (bis zur Ablesung selbst: ihr Stand ist der Endstand der Rechnung)
        $bdQty = $cut ? $this->consumption->billBreakdown($utility, $meter, (string)$b['period_from'], $endOn) : $bd;
        $ours = [
            $qtyKey        => $bdQty['totals'][$qtyKey] ?? null,
            'energy_cost'  => $bdQty['totals']['energy_cost'],
            'fixed_cost'   => $bd['totals']['fixed_cost'],
            'bonus'        => $bd['totals']['bonus'],
            'items_total'  => round($items, 2),
            'total'        => round($bd['totals']['total'] - $bd['totals']['energy_cost'] + $bdQty['totals']['energy_cost'] + $items, 2),
            'advances'     => $this->advancesBetween($utility, $meter, (string)$b['period_from'], (string)$b['period_to']),
        ] + ($cut ? ['quantity_until' => $endOn] : []);
        $inv = (array)($b['invoice'] ?? []);
        $invQty = $isWater ? ($inv['volume_m3'] ?? null) : ($inv['energy_kwh'] ?? null);
        $delta = [
            $qtyKey          => $invQty !== null && $ours[$qtyKey] !== null ? round((float)$invQty - (float)$ours[$qtyKey], 1) : null,
            $qtyKey . '_pct' => $invQty !== null && !empty($ours[$qtyKey]) ? round(((float)$invQty - (float)$ours[$qtyKey]) / (float)$ours[$qtyKey] * 100, 2) : null,
            'eur'            => isset($inv['amount_eur']) ? round((float)$inv['amount_eur'] - $ours['total'], 2) : null,
            'eur_pct'        => isset($inv['amount_eur']) && $ours['total'] != 0 ? round(((float)$inv['amount_eur'] - $ours['total']) / $ours['total'] * 100, 2) : null,
        ];
        $qtyOk = $delta[$qtyKey . '_pct'] === null || abs($delta[$qtyKey . '_pct']) <= self::OK_PCT;
        $eurOk = $delta['eur'] === null || abs($delta['eur']) <= self::OK_EUR || ($delta['eur_pct'] !== null && abs($delta['eur_pct']) <= self::OK_PCT);
        $reasons = [];
        // v3.7.0 (treffsicher) — Einstufung, nicht Rechnung: Die Zeilen bleiben, wie
        // billBreakdown() sie liefert; geändert ist nur, was als Grund zählt.
        //  - Geschätzt ist ein Stand nur an den beiden Rändern des Zeitraums.
        //    Innen (Preis- und Brennwertstichtage) schätzt auch der Versorger,
        //    und die Summe hängt dort nicht am Stand. Bis v3.6 zählte jede Zeile.
        //  - Eine Ablesung am letzten Tag (bzw. am Tag vor dem ersten) ist ein
        //    Grenzstand wie auf der Rechnung, keine Schätzung; der eine Tag danach
        //    ohne Ablesung ist dann auch keine Lücke.
        $bounds = $this->boundaries($utility, $meter, (string)$b['period_from'], $cut ? date('Y-m-d', (int)strtotime("$endOn -1 day")) : (string)$b['period_to'], $bdQty['rows']);
        if ($bounds['estimated']) $reasons['estimated_reading_at_boundary'] = true;
        foreach ($bd['rows'] as $r) {
            if (str_contains((string)($r['reason'] ?? ''), 'price')) $reasons['price_change_inside'] = true;
        }
        //  - Gas: Ein Wechsel von Brennwert oder Zustandszahl ist kein Grund, wenn
        //    die Stichtag-Liste ihn genauso kennt wie die Rechnung (`factors`, aus
        //    dem Posteingang); sonst beide Werte. Ohne Angaben der Rechnung bleibt
        //    der Hinweis wie bisher.
        $factors = $utility === 'gas' ? $this->factorCheck($b, $bd['rows']) : ['inside' => false, 'known' => false, 'mismatches' => []];
        if ($factors['mismatches'] !== []) $reasons['factor_mismatch'] = true;
        elseif ($factors['inside'] && !$factors['known']) $reasons['factor_change_inside'] = true;
        if ($items != 0) $reasons['items_not_modelled'] = true;
        if (!empty($bd['totals']['price_missing'])) $reasons['price_missing'] = true;
        if ((int)($bdQty['totals']['gaps'] ?? 0) - $bounds['trailing_gap'] > 0) $reasons['gaps'] = true;
        //  - Ohne Preise ist der Betrag nicht nachzurechnen: eigenes Urteil
        //    `no_prices` mit den Abschnitten, in denen sie fehlen (Sprung zum
        //    Vertrag). Eine abweichende Menge bleibt „prüfen“.
        $verdict = null;
        if ($invQty !== null || isset($inv['amount_eur'])) {
            $verdict = !$qtyOk ? 'check' : (isset($inv['amount_eur']) && !empty($bd['totals']['price_missing']) ? 'no_prices' : ($eurOk ? 'ok' : 'check'));
        }
        return [
            'bill_id' => $b['id'], 'utility' => $utility, 'meter_id' => $b['meter_id'],
            'group_id' => !empty($meter['is_group']) ? (string)$meter['id'] : null,   // v3.1.0 (H6) — verglichen mit der Gruppe
            'period_from' => $b['period_from'], 'period_to' => $b['period_to'],
            'ours' => $ours, 'invoice' => $inv, 'delta' => $delta,
            'verdict' => $verdict,
            'reasons' => array_keys($reasons),
            // v3.7.0 (treffsicher) — additiv: Ableseart an den Rändern, fehlende Preise, Brennwert laut Rechnung gegen die Liste
            'boundaries' => ['start' => $bounds['start'], 'end' => $bounds['end']],
            'price_missing_at' => $this->priceMissingAt($utility, $bd['rows']),
            'factor_mismatches' => $factors['mismatches'],
            'rows' => $bd['rows'],
        ];
    }

    /**
     * v3.7.0 (treffsicher) — Ableseart an den Rändern des Zeitraums: am ersten
     * Tag (Beginn) und am Tag nach dem letzten (Ende, `period_to` ist
     * einschließlich).
     *
     *   reading           Ablesung genau dort
     *   reading_adjacent  Ablesung am Tag davor (Beginn) bzw. am letzten Tag
     *                     (Ende) — der Grenzstand der Rechnung: Sie nennt den
     *                     Stand am letzten Tag ihres Zeitraums, die Prüfung
     *                     rechnet bis zum Folgetag
     *   reading_estimated als geschätzt erfasst
     *   interpolated      kein Stand in der Nähe, zwischen Ablesungen gerechnet
     *   null              keine Ablesung davor bzw. danach (Lücke)
     *
     * Bei einer Gruppe je Mitglied, gemeldet wird das schwächste.
     * `trailing_gap`: Abschnitte, die nur der eine Tag nach einem Grenzstand am
     * letzten Tag sind und deshalb keine Menge haben (keine echte Lücke).
     *
     * @param list<array<string,mixed>> $rows
     * @return array{start: ?string, end: ?string, estimated: bool, trailing_gap: int}
     */
    private function boundaries(string $utility, array $meter, string $from, string $to, array $rows): array
    {
        $group = !empty($meter['is_group']);
        $members = $group ? $this->meters->groupSumMembers($utility, (string)$meter['id']) : [$meter];
        $before = date('Y-m-d', (int)strtotime("$from -1 day"));
        $toExcl = date('Y-m-d', (int)strtotime("$to +1 day"));
        $qty = in_array($utility, ['gas', 'wasser'], true) ? 'm3' : 'kwh';
        $rank = fn(?string $k): int => ['reading' => 0, 'reading_adjacent' => 1, 'reading_estimated' => 3, 'interpolated' => 4][$k ?? ''] ?? 2;
        $out = ['start' => null, 'end' => null, 'estimated' => false, 'trailing_gap' => 0];
        $seen = false;
        foreach ($members as $m) {
            $mid = (string)($m['id'] ?? '');
            $mine = $group ? array_values(array_filter($rows, fn($r) => (string)($r['meter_id'] ?? '') === $mid)) : $rows;
            if ($mine === []) continue;
            $k = $this->consumption->readingKindsOn($utility, $m, [$before, $from, $to, $toExcl]);
            $first = $mine[0];
            $last = $mine[count($mine) - 1];
            $s = $k[$from] ?? (isset($k[$before]) ? ($k[$before] === 'reading' ? 'reading_adjacent' : $k[$before]) : ($first['counter_from_kind'] ?? null));
            $e = $k[$toExcl] ?? (isset($k[$to]) ? ($k[$to] === 'reading' ? 'reading_adjacent' : $k[$to]) : ($last['counter_to_kind'] ?? null));
            if ($e === 'reading_adjacent' && (string)($last['from'] ?? '') === $to && ($last[$qty] ?? null) === null) $out['trailing_gap']++;
            foreach (['start' => $s, 'end' => $e] as $side => $kind) {
                if (in_array($kind, ['reading_estimated', 'interpolated'], true)) $out['estimated'] = true;
                if (!$seen || $rank($kind) > $rank($out[$side])) $out[$side] = $kind;
            }
            $seen = true;
        }
        return $out;
    }

    /**
     * v3.7.0 (treffsicher) — Gas: Brennwert und Zustandszahl laut Rechnung
     * (`factors` an der Rechnung) gegen die Stichtag-Liste, an jedem Tag, an
     * dem eine der beiden wechselt. `inside`: Die Liste wechselt im Zeitraum.
     * `known`: Die Rechnung nennt ihre Werte.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{inside: bool, known: bool, mismatches: list<array<string,mixed>>}
     */
    private function factorCheck(array $bill, array $rows): array
    {
        $from = (string)$bill['period_from'];
        $to = (string)$bill['period_to'];
        $inside = false;
        $dates = [$from => true];
        foreach ($rows as $r) {
            if (!str_contains((string)($r['reason'] ?? ''), 'factor')) continue;
            $inside = true;
            $dates[(string)$r['from']] = true;
        }
        $inv = array_values(array_filter((array)($bill['factors'] ?? []), fn($f) => is_array($f) && Dates::isIsoDate($f['from'] ?? null)
            && is_numeric($f['zustandszahl'] ?? null) && is_numeric($f['brennwert'] ?? null)));
        if ($inv === []) return ['inside' => $inside, 'known' => false, 'mismatches' => []];
        usort($inv, fn($a, $b) => strcmp((string)$a['from'], (string)$b['from']));
        foreach ($inv as $f) if ($f['from'] > $from && $f['from'] <= $to) $dates[(string)$f['from']] = true;
        ksort($dates);
        $mismatches = [];
        foreach (array_keys($dates) as $d) {
            $i = $inv[0];   // vor dem ersten Eintrag der Rechnung: dessen Werte
            foreach ($inv as $f) if ((string)$f['from'] <= $d) $i = $f;
            $row = null;
            foreach ($rows as $r) if ((string)$r['from'] <= $d && $d < (string)$r['to']) { $row = $r; break; }
            if ($row === null) continue;
            $z = (float)$i['zustandszahl']; $hs = (float)$i['brennwert'];
            $same = $row['zustandszahl'] !== null && $row['brennwert'] !== null
                ? abs((float)$row['zustandszahl'] - $z) < 0.00005 && abs((float)$row['brennwert'] - $hs) < 0.0005
                : abs((float)$row['kwh_per_m3'] - $z * $hs) < 0.0005 * max(1.0, $z * $hs);
            if ($same) continue;
            $mismatches[] = ['from' => (string)$d,
                'invoice' => ['zustandszahl' => $z, 'brennwert' => $hs, 'kwh_per_m3' => round($z * $hs, 4)],
                'list' => ['zustandszahl' => $row['zustandszahl'], 'brennwert' => $row['brennwert'], 'kwh_per_m3' => $row['kwh_per_m3']]];
        }
        return ['inside' => $inside, 'known' => true, 'mismatches' => $mismatches];
    }

    /**
     * v3.7.0 (treffsicher) — Wo fehlt ein Preis? Zusammenhängende Abschnitte mit
     * Menge, aber ohne Verbrauchskosten, je Vertrag (null: kein Vertrag) und
     * Zähler (Gruppe), `to` einschließlich.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function priceMissingAt(string $utility, array $rows): array
    {
        $qty = $utility === 'wasser' ? 'm3' : 'kwh';
        $out = [];
        foreach ($rows as $r) {
            if (($r[$qty] ?? null) === null || ($r['energy_cost'] ?? null) !== null) continue;
            $cid = isset($r['contract_id']) ? (string)$r['contract_id'] : null;
            $mid = isset($r['meter_id']) ? (string)$r['meter_id'] : null;
            $n = count($out) - 1;
            if ($n >= 0 && $out[$n]['contract_id'] === $cid && $out[$n]['meter_id'] === $mid && $out[$n]['to_excl'] === (string)$r['from']) {
                $out[$n]['to_excl'] = (string)$r['to'];
                continue;
            }
            $out[] = ['from' => (string)$r['from'], 'to_excl' => (string)$r['to'], 'contract_id' => $cid, 'meter_id' => $mid];
        }
        return array_map(function (array $x) use ($utility): array {
            $c = $x['contract_id'] !== null ? $this->contracts->get($utility, $x['contract_id']) : null;
            return array_filter(['from' => $x['from'], 'to' => date('Y-m-d', (int)strtotime($x['to_excl'] . ' -1 day')),
                'contract_id' => $x['contract_id'], 'provider' => $c !== null ? (string)($c['provider'] ?? '') : null,
                'meter_id' => $x['meter_id']], fn($v) => $v !== null) + ['contract_id' => null];
        }, $out);
    }

    /**
     * Ergebnis der Rechnung als Sonderzahlung im Vertrag (F1003): Nachzahlung
     * oder Rückzahlung „ohne Auswirkung" auf den Abschlag. Einmal je Rechnung —
     * für Gas, Strom, Wasser und Fernwärme (Utilities::hasSpecialPayments
     * deckt sich mit supportsBillCheck).
     *
     * @return array<string,mixed> die Rechnung mit special_payment_id
     */
    public function book(string $utility, string $id): array
    {
        $b = $this->get($utility, $id);
        if (!empty($b['special_payment_id'])) return $b;
        $result = $b['invoice']['result_eur'] ?? null;
        if (!is_numeric($result) || (float)$result == 0.0) {
            throw new LocalizedException('errors.bill.noResult', [], 'bill without result');
        }
        $date = (string)($b['issued_on'] ?? '') ?: date('Y-m-d', (int)strtotime((string)$b['period_to'] . ' +1 day'));
        $contract = !empty($b['contract_id']) ? $this->contracts->get($utility, (string)$b['contract_id']) : null;
        $contract ??= $this->contractFor($utility, (string)$b['meter_id'], (string)$b['period_from'], (string)$b['period_to']);
        if ($contract === null) {
            throw new LocalizedException('errors.bill.noContract',
                ['from' => $this->i18n->date((string)$b['period_from']), 'to' => $this->i18n->date((string)$b['period_to'])], 'bill without contract');
        }
        $spId = 'sp_' . bin2hex(random_bytes(5));
        $payments = (array)($contract['special_payments'] ?? []);
        $payments[] = ['id' => $spId, 'date' => $date, 'kind' => (float)$result > 0 ? 'nachzahlung_ohne' : 'rueckzahlung_ohne',
                       'amount_eur' => abs((float)$result), 'note' => $this->i18n->t('billCheck.bookNote', ['from' => $this->i18n->date((string)$b['period_from']), 'to' => $this->i18n->date((string)$b['period_to'])])];
        $saved = $this->contracts->update($utility, (string)$contract['id'], ['special_payments' => $payments]);
        // v3.5.0 (GitHub #34) — nie „gebucht“ melden, wenn der Vertrag die
        // Zahlung nicht übernommen hat (bis v3.4 bei jeder Wasserrechnung)
        if (!in_array($spId, array_column((array)($saved['special_payments'] ?? []), 'id'), true)) {
            throw new \LogicException("special payment not stored in contract ($utility)");
        }
        return $this->update($utility, $id, ['special_payment_id' => $spId, 'contract_id' => (string)$contract['id']]);
    }

    /**
     * v3.7.0 (treffsicher) — Der Vertrag einer Rechnung, wenn sie keinen nennt:
     *   1. der Vertrag, der am letzten Tag des Zeitraums gilt
     *      (ContractService::resolveForDate, also auch ein weiterlaufender),
     *      wenn er mehr als die Hälfte der Tage abrechnet;
     *   2. sonst der, der mehr als die Hälfte der Tage abrechnet — etwa der
     *      alte Vertrag einer Schlussrechnung, die einen Tag über sein Ende
     *      reicht, während am letzten Tag schon der neue Versorger liefert;
     *   3. sonst der am letzten Tag (z. B. drei Tarife nacheinander);
     *   4. sonst keiner: Die Buchung wird abgelehnt.
     * Bis v3.6 nahm book() ohne Vertrag am Periodenende den jüngsten Vertrag
     * des Zählers — oft einen, der den Zeitraum gar nicht berührt.
     *
     * Abgerechnet wird je Tag wie im Saldo (ContractService::segmentsBetween);
     * Gruppenverträge (#17) zählen mit, Schattenverträge nicht. `$accept`
     * schränkt die Verträge ein (der Posteingang: nur desselben Versorgers).
     *
     * @param (callable(array<string,mixed>): bool)|null $accept
     * @return array<string,mixed>|null
     */
    public function contractFor(string $utility, string $meterId, string $from, string $to, ?callable $accept = null): ?array
    {
        $list = $this->contracts->list($utility, $meterId);
        // v3.1.0 (H6, #17) — ein Gruppenvertrag gilt auch für die Rechnung eines Mitglieds
        $gid = (string)(($this->meters->get($utility, $meterId) ?? [])['meter_group_id'] ?? '');
        if ($gid !== '') $list = [...$list, ...$this->contracts->list($utility, $gid)];
        $list = array_values(array_filter($list, fn($c) => empty($c['is_shadow']) && ($accept === null || $accept($c))));
        if ($list === [] || !Dates::isIsoDate($from) || !Dates::isIsoDate($to) || $to < $from) return null;
        $toExcl = date('Y-m-d', (int)strtotime("$to +1 day"));
        $days = []; $total = 0;
        foreach ($this->contracts->segmentsBetween($list, $from, $toExcl) as $seg) {
            $total += (int)$seg['days'];
            if ($seg['contract'] !== null) $days[(string)$seg['contract']['id']] = ($days[(string)$seg['contract']['id']] ?? 0) + (int)$seg['days'];
        }
        $most = fn(?array $c): bool => $c !== null && 2 * ($days[(string)($c['id'] ?? '')] ?? 0) > $total;
        $atEnd = $this->contracts->resolveForDate($list, $to)['contract'] ?? null;
        if ($most($atEnd)) return $atEnd;
        arsort($days);
        foreach ($list as $c) if ((string)($c['id'] ?? '') === (string)array_key_first($days) && $most($c)) return $c;
        return $atEnd;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** Abschläge im Zeitraum aus den Monatswerten, anteilig nach Tagen. */
    /**
     * v3.1.0 (H6, #17) — Wen rechnet der Versorger ab? Berührt ein Gruppenvertrag
     * den Zeitraum, kommt eine Rechnung für die ganze Gruppe (HT und NT auf
     * einer Rechnung), auch wenn sie an einem Mitglied erfasst ist.
     */
    private function billTarget(string $utility, array $meter, string $from, string $to): array
    {
        $gid = (string)($meter['meter_group_id'] ?? '');
        if ($gid === '') return $meter;
        foreach ($this->contracts->list($utility, $gid) as $c) {
            if (!empty($c['is_shadow']) || ($c['meter_group_id'] ?? null) !== $gid) continue;
            if ((string)($c['start'] ?? '') <= $to && $from <= ((string)($c['end'] ?? '') ?: '9999-12-31')) {
                return $this->meters->groupTarget($utility, $gid) ?? $meter;
            }
        }
        return $meter;
    }

    private function advancesBetween(string $utility, array $meter, string $from, string $to): ?float
    {
        $sum = 0.0; $any = false;
        // v3.6.0 — ein Monat mit zwei Verträgen je Vertrag nach seinen Tagen (wie die Abrechnungsperioden)
        $starts = ConsumptionService::contractStarts($this->contracts->list($utility));
        $toExcl = date('Y-m-d', (int)strtotime("$to +1 day"));
        foreach ($this->consumption->forMeter($utility, $meter) as $m) {
            if (!isset($m['advance_eur']) || $m['advance_eur'] === null) continue;
            $ms = (string)$m['ym'] . '-01';
            $me = date('Y-m-t', (int)strtotime($ms));
            if (max($ms, $from) > min($me, $to)) continue;
            $sum += ConsumptionService::advanceBetween($m, $from, $toExcl, $starts);
            $any = true;
        }
        return $any ? round($sum, 2) : null;
    }

    /** @return array<string,mixed> */
    private function normalize(array $in, ?array $old): array
    {
        $isNew = $old === null;
        $out = [];
        foreach (['period_from', 'period_to'] as $f) {
            if ($isNew || array_key_exists($f, $in)) {
                $v = (string)($in[$f] ?? '');
                if (!Dates::isIsoDate($v)) throw new LocalizedException('errors.bill.periodInvalid', ['from' => (string)($in['period_from'] ?? ''), 'to' => (string)($in['period_to'] ?? '')], 'bill date');
                $out[$f] = $v;
            }
        }
        $from = $out['period_from'] ?? $old['period_from'];
        $to = $out['period_to'] ?? $old['period_to'];
        if ($from > $to) throw new LocalizedException('errors.bill.periodInvalid', ['from' => $from, 'to' => $to], 'bill order');
        if ($isNew || array_key_exists('issued_on', $in)) {
            $v = (string)($in['issued_on'] ?? '');
            if ($v !== '' && !Dates::isIsoDate($v)) throw new LocalizedException('errors.bill.periodInvalid', ['from' => $v, 'to' => ''], 'issued_on');
            $out['issued_on'] = $v === '' ? null : $v;
        }
        if ($isNew || array_key_exists('kind', $in)) $out['kind'] = in_array($in['kind'] ?? null, self::KINDS, true) ? $in['kind'] : 'annual';
        if (array_key_exists('contract_id', $in)) $out['contract_id'] = ($in['contract_id'] ?? '') === '' ? null : (string)$in['contract_id'];
        if (array_key_exists('special_payment_id', $in)) $out['special_payment_id'] = $in['special_payment_id'] === null ? null : (string)$in['special_payment_id'];
        if ($isNew || array_key_exists('invoice', $in)) {
            $inv = is_array($in['invoice'] ?? null) ? $in['invoice'] : [];
            $o = [];
            foreach (['energy_kwh' => [0, 1e8], 'volume_m3' => [0, 1e7], 'amount_eur' => [-1e7, 1e7],
                      'advances_paid_eur' => [0, 1e7], 'result_eur' => [-1e7, 1e7]] as $f => [$lo, $hi]) {
                if (isset($inv[$f]) && $inv[$f] !== '') $o[$f] = $this->num($inv[$f], $lo, $hi);
            }
            if (!isset($o['result_eur']) && isset($o['amount_eur'], $o['advances_paid_eur'])) {
                $o['result_eur'] = round($o['amount_eur'] - $o['advances_paid_eur'], 2);
            }
            if (array_key_exists('net', $inv)) $o['net'] = !empty($inv['net']);
            $out['invoice'] = $o;
        }
        if ($isNew || array_key_exists('items', $in)) {
            $items = [];
            foreach ((array)($in['items'] ?? []) as $x) {
                if (!is_array($x) || (($x['label'] ?? '') === '' && ($x['amount_eur'] ?? '') === '')) continue;
                $items[] = [
                    'label'      => mb_substr(trim((string)($x['label'] ?? '')), 0, 120),
                    'amount_eur' => $this->num($x['amount_eur'] ?? 0, -1e6, 1e6),
                    'kind'       => in_array($x['kind'] ?? null, self::ITEM_KINDS, true) ? $x['kind'] : 'other',
                ];
            }
            $out['items'] = $items;
        }
        if ($isNew || array_key_exists('co2', $in)) {
            $c = $in['co2'] ?? null;
            $co2 = [];
            if (is_array($c)) {
                if (isset($c['emissions_kg']) && $c['emissions_kg'] !== '') $co2['emissions_kg'] = $this->num($c['emissions_kg'], 0, 1e7);
                if (isset($c['cost_eur']) && $c['cost_eur'] !== '') $co2['cost_eur'] = $this->num($c['cost_eur'], 0, 1e6);
                if (isset($c['stated_factor']) && $c['stated_factor'] !== '') $co2['stated_factor'] = $this->num($c['stated_factor'], 0, 10);
            }
            $out['co2'] = $co2 === [] ? null : $co2;
        }
        // v3.7.0 (treffsicher) — Gas: Zustandszahl und Brennwert laut Rechnung je
        // Abschnitt (aus dem Posteingang), für den Abgleich mit der Stichtag-Liste
        // in compare(). Optional; ohne Angabe fehlt das Feld.
        if (array_key_exists('factors', $in)) {
            $fs = [];
            foreach ((array)($in['factors'] ?? []) as $f) {
                if (!is_array($f)) continue;
                $ff = (string)($f['from'] ?? '');
                $ft = (string)($f['to'] ?? '');
                if (!Dates::isIsoDate($ff) || ($ft !== '' && !Dates::isIsoDate($ft))) {
                    throw new LocalizedException('errors.bill.periodInvalid', ['from' => $ff, 'to' => $ft], 'bill factor date');
                }
                $fs[] = ['from' => $ff, 'to' => $ft === '' ? null : $ft,
                         'zustandszahl' => $this->num($f['zustandszahl'] ?? null, 0.5, 1.5), 'brennwert' => $this->num($f['brennwert'] ?? null, 5, 20)];
            }
            usort($fs, fn($a, $b) => strcmp($a['from'], $b['from']));
            $out['factors'] = $fs === [] ? null : $fs;
        }
        // v3.9.0 — der Endstand der Rechnung ist dein gemeldeter Stand dieses Tags (der Versorger datiert ihn auf
        // das Zeitraumende); compare() rechnet die Menge bis dahin. Optional, im Zeitraum.
        if (array_key_exists('end_reading_on', $in)) {
            $v = (string)($in['end_reading_on'] ?? '');
            if ($v !== '' && (!Dates::isIsoDate($v) || $v < (string)($out['period_from'] ?? $old['period_from'] ?? '') || $v > (string)($out['period_to'] ?? $old['period_to'] ?? ''))) {
                throw new LocalizedException('errors.bill.periodInvalid', ['from' => $v, 'to' => (string)($out['period_to'] ?? $old['period_to'] ?? '')], 'bill end reading date');
            }
            $out['end_reading_on'] = $v === '' ? null : $v;
        }
        if ($isNew || array_key_exists('note', $in)) $out['note'] = mb_substr((string)($in['note'] ?? ''), 0, 2000);
        return $out;
    }

    private function linkAttachments(string $utility, array &$b, array $old, mixed $new): void
    {
        $new = array_values(array_unique(array_filter(array_map('strval', is_array($new) ? $new : []), fn($x) => $x !== '')));
        if (array_diff($old, $new) !== []) AttachmentService::unlinkRef($this->store, 'bill', (string)$b['id']);
        foreach ($new as $id) {
            AttachmentService::link($this->store, $id, null, ['type' => 'bill', 'utility' => $utility, 'id' => (string)$b['id']]);
        }
        $b['attachment_ids'] = $new;
    }

    private function num(mixed $v, float $min, float $max): float
    {
        $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_int($v) || is_float($v) ? (float)$v : null);
        if ($n === null || !is_finite($n) || $n < $min || $n > $max) {
            throw new LocalizedException('errors.bill.amountInvalid', ['value' => is_scalar($v) ? (string)$v : ''], 'bill number');
        }
        return round($n, 4);
    }

    private function assertUtility(string $utility): void
    {
        if (!Utilities::supportsBillCheck($utility)) {
            throw new LocalizedException('errors.billCheck.unsupportedUtility', ['utility' => $utility], "bills for $utility");
        }
    }
}
