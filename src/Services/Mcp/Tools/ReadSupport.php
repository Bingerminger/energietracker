<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Countries;
use Energietracker\Config\Utilities;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\RightsService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket lesen) — Gemeinsames der lesenden Werkzeuge: Zähler
 * auflösen (ID, Gruppe, Alias), Einheiten, Währung und die Zahl-Objekte eines
 * Vertrags (Preise, Saldo, Schätzung) aus derselben Zeile wie contract-status.
 *
 * Nur für Gruppen unter `Tools/` (braucht `$this->services`).
 */
trait ReadSupport
{
    /**
     * Zähler oder Gruppe zu einer ID, sonst zum Alias (`external_id`) — wie
     * SummaryService (`meter=`) und MeterService::target. Unbekannt → fachlicher Fehler.
     *
     * @return array<string,mixed>
     */
    private function target(string $utility, string $ref): array
    {
        $meters = $this->services->get(MeterService::class);
        $t = $meters->target($utility, $ref) ?? $meters->getByExternalId($utility, $ref);
        if ($t === null) {
            throw new LocalizedException('errors.mcp.meterNotFound', ['meter' => Sanitize::name($ref), 'utility' => $utility], 'mcp meter');
        }
        return $t;
    }

    /** @return list<string> eingeschaltete Verbrauchsarten in der Reihenfolge der SSOT */
    private function activeUtilities(): array
    {
        $a = $this->services->get(SettingsService::class)->get('active_utilities', []);
        if (!is_array($a) || $a === []) return Utilities::keys();
        return array_values(array_filter(Utilities::keys(), fn($k) => in_array($k, $a, true)));
    }

    private function currency(): string
    {
        $c = (string)$this->services->get(SettingsService::class)->get('currency', 'EUR');
        return isset(Countries::CURRENCIES[$c]) ? $c : 'EUR';
    }

    /**
     * v3.12.0 (Skeptiker H4) — Name des Geldfelds einer Art: Kosten, bei Einspeisung die Vergütung
     * als `revenue` (eine KI, die Kosten zusammenzählt, zählte sonst den Erlös als Ausgabe), bei
     * reiner Erzeugung keins.
     */
    private static function moneyField(string $utility): ?string
    {
        return match (Utilities::accountingKind($utility)) {
            'feed_in'    => 'revenue',
            'generation' => null,
            default      => 'cost',
        };
    }

    /** Sprache der Texte in dieser Antwort (Kontext der Anfrage); ohne Angabe die aktuelle. */
    private ?string $textLocale = null;

    /**
     * v3.12.0 — ein Saldo in Klartext neben `sign`: `meaning` und ein fertiger Satz in der Sprache
     * des Servers. Geschätzt heißt es im Satz auch so. Ohne Arbeitspreis (`$noPrice`) gibt es
     * keinen Saldo, nur die Abschläge: `no_price` (Skeptiker M5).
     *
     * @param array<string,mixed>|null $num Zahl-Objekt
     * @return array<string,mixed>|null
     */
    private function withMeaning(?array $num, string $utility, bool $noPrice = false, ?string $since = null): ?array
    {
        if ($num === null) return null;
        $i18n = $this->services->get(I18nService::class);
        $value = (float)$num['value'];
        $meaning = $noPrice ? 'no_price' : Provenance::meaning($value, Utilities::isFeedIn($utility));
        $text = $i18n->t("mcp.balance.$meaning", ['amount' => $i18n->money(abs($value))], $this->textLocale);
        if ($since !== null && !$noPrice && abs($value) >= 0.005) $text = $i18n->t('mcp.balance.since', ['text' => $text, 'date' => $i18n->date($since)], $this->textLocale);
        if (!$noPrice && ($num['basis'] ?? null) === 'estimated') $text = $i18n->t('mcp.balance.estimated', ['text' => $text], $this->textLocale);
        return $num + ['meaning' => $meaning, 'text' => $text];
    }

    /**
     * Letzter echter Stand hinter einem Ziel: der früheste der Zähler (wie contractStatus bei Gruppen).
     *
     * @param list<array<string,mixed>> $members
     */
    private function lastMeasuredOf(string $utility, array $members): ?string
    {
        $dates = [];
        foreach ($members as $m) {
            $last = null;
            foreach ($this->services->get(ReadingService::class)->list($utility, (string)$m['id']) as $r) {
                if (empty($r['is_future']) && empty($r['is_suspect']) && empty($r['is_estimated']) && is_numeric($r['counter'] ?? null)) $last = (string)$r['date'];
            }
            if ($last !== null) $dates[] = $last;
        }
        return $dates !== [] ? min($dates) : null;
    }

    /** Einheit des Verbrauchs (kWh, m³ …) */
    private static function consumptionUnit(string $utility): string
    {
        return (string)Utilities::get($utility)['consumption_unit'];
    }

    /** Feld der Menge in den Monatszeilen */
    private static function valueField(string $utility): string
    {
        return self::consumptionUnit($utility) === 'kWh' ? 'kwh' : 'm3';
    }

    /** Einheit eines Arbeitspreises: Untereinheit der Währung je Verbrauchseinheit (ct/kWh, Rp./m³) */
    private function priceUnit(string $utility): string
    {
        return Countries::CURRENCIES[$this->currency()]['minor'] . '/' . self::consumptionUnit($utility);
    }

    /**
     * Letzte echte Ablesung eines Zählers (nicht geplant, nicht verdächtig), wie
     * Erfassung, Übersicht und list_meters sie zeigen.
     *
     * @return array<string,mixed>|null
     */
    private function lastRealReading(string $utility, string $meterId): ?array
    {
        $last = null;
        foreach ($this->services->get(ReadingService::class)->list($utility, $meterId) as $r) {
            if (!empty($r['is_future']) || !empty($r['is_suspect']) || !is_numeric($r['counter'] ?? null)) continue;
            $last = $r;
        }
        return $last;
    }

    /** Zahl ohne Leerwert: null bleibt null (das Feld fällt dann weg). */
    private static function maybe(mixed $v, callable $make): ?array
    {
        return is_numeric($v) ? $make((float)$v) : null;
    }

    /** Leerfelder (null, '', []) weglassen. */
    private static function compact(array $a): array
    {
        return array_filter($a, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Preise eines Vertrags (Zeile aus contractStatus) als Zahl-Objekte: eingetragen,
     * nach der Preisgarantie oder fortgeschrieben angenommen (Provenance::price).
     *
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function contractPrices(string $utility, array $c, string $today): array
    {
        $cur = $this->currency();
        $every = (int)($c['current_advance_every_months'] ?? 1);
        // v3.12.0 (integration) — je Wert aus seinem Eintrag: Ein Preis, der erst nach dem Ende oder nach der
        // Preisgarantie beginnt, ist eingetragen (v3.11.0: Preise nach dem Ende gelten), der Rest läuft angenommen fort
        $raw = $this->storedContract($utility, (string)($c['contract_id'] ?? ''));
        $day = !empty($c['is_future']) && is_string($c['start'] ?? null) ? $c['start'] : $today;
        $prices = $utility === 'wasser' ? (array)($raw['trinkwasser'] ?? []) : $raw;
        $basis = fn(array $rows): string => Provenance::price($c, $today, self::priceFrom($rows, $day));
        $advance = $basis($raw !== [] ? $this->services->get(ContractService::class)->effectiveAdvanceSchedule($raw) : []);
        return self::compact([
            'working_price' => self::maybe($c['current_working_price_ct'] ?? null, fn($v) => Num::of($v, $this->priceUnit($utility), $basis((array)($prices['working_prices'] ?? [])), ['decimals' => 4])),
            'base_price'    => self::maybe($c['current_base_price_eur'] ?? null, fn($v) => Num::money($v, "$cur/month", $basis((array)($prices['base_prices'] ?? [])))),
            'advance'       => self::maybe($c['current_advance_amount'] ?? null, fn($v) => Num::money($v, $cur, $advance)),
            // Abschlag alle n Monate (v3.9.0, F1034); ohne Rhythmus monatlich
            'advance_every_months' => $every > 1 ? Num::of($every, 'months', $advance) : null,
            // v3.12.0 (Skeptiker N7) — Gruppenvertrag HT/NT: der Preis je Zähler (Zähler-ID und Wert), der gemeinsame Eintrag allein führt in die Irre
            'working_price_by_meter' => $this->pricesByMeter($utility, $raw, $day, $basis),
        ]);
    }

    /**
     * @param array<string,mixed> $raw gespeicherter Vertrag
     * @return list<array{meter_id:string, price:array<string,mixed>}>
     */
    private function pricesByMeter(string $utility, array $raw, string $day, callable $basis): array
    {
        $out = [];
        $contracts = $this->services->get(ContractService::class);
        foreach ((array)($raw['working_prices_by_meter'] ?? []) as $mid => $rows) {
            $v = $contracts->valueOnDate((array)$rows, 'ct_per_kwh', $day);
            if (is_numeric($v)) $out[] = ['meter_id' => (string)$mid, 'price' => Num::of((float)$v, $this->priceUnit($utility), $basis((array)$rows), ['decimals' => 4])];
        }
        return $out;
    }

    /**
     * Gespeicherter Vertrag zur Zeile aus contractStatus: Preislisten, Kennnummern, Versorgungsart.
     *
     * @return array<string,mixed>
     */
    private function storedContract(string $utility, string $id): array
    {
        foreach ($this->services->get(ContractService::class)->list($utility) as $c) {
            if (($c['id'] ?? null) === $id) return $c;
        }
        return [];
    }

    /**
     * Beginn des Eintrags, der in diesem Monat gilt (wie ContractService::valueValidOn: zum Monatsersten).
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function priceFrom(array $rows, string $day): ?string
    {
        $first = substr($day, 0, 7) . '-01';
        $from = null;
        foreach ($rows as $r) {
            $f = is_array($r) ? ($r['from'] ?? null) : null;
            if (is_string($f) && $f <= $first && ($from === null || $f > $from)) $from = $f;
        }
        return $from;
    }

    /**
     * v3.11.0 (F1035) — ein Befund „Deine Rechte als Kunde“ für die KI: Titel und Text können Namen
     * aus den Daten tragen und stehen nur unter `untrusted`; Normen, Fußzeile („Hinweis, keine
     * Rechtsberatung“), Frist und Bezug offen. `nominal_end`: nur, wenn das Fristende auf den nächsten
     * Werktag gerückt ist (Rights::feeNoticeDeadline). Fakten (Rechnungs-, Aktenzeichen) gehen nicht hinaus.
     *
     * @param array<string,mixed> $f Befund aus RightsService::findings
     * @return array<string,mixed>
     */
    private function rightsRow(array $f): array
    {
        $facts = (array)($f['facts'] ?? []);
        return self::compact([
            'id'          => (string)$f['id'],
            'check'       => (string)$f['check'],
            'severity'    => (string)$f['severity'],
            'contract_id' => is_string($f['contract_id'] ?? null) ? $f['contract_id'] : null,
            'bill_id'     => is_string($f['bill_id'] ?? null) ? $f['bill_id'] : null,
            'date'        => (string)$f['date'],
            'deadline'    => is_string($f['deadline'] ?? null) ? $f['deadline'] : null,
            'nominal_end' => !empty($facts['deadline_shifted']) && is_string($facts['nominal_end'] ?? null) ? $facts['nominal_end'] : null,
            'known_on'    => is_string($facts['known_on'] ?? null) ? $facts['known_on'] : null,
            'amount'      => self::maybe($f['amount_eur'] ?? null, fn($v) => Num::money($v, $this->currency(), 'entered', ['derived' => true])),
            'rules'       => array_values(array_map('strval', (array)$f['rules'])),
            'norms'       => array_map(fn(array $n): array => ['norm' => (string)$n['norm'], 'url' => (string)$n['url'],
                                                               'checked_on' => (string)$n['checked_on']], (array)$f['norms']),
            'letter'      => is_string($f['letter'] ?? null) ? $f['letter'] : null,
            'footer'      => (string)$f['footer'],
            // v3.12.0 (Skeptiker M10) — Text aus dem Katalog: eigene Länge, der letzte Satz bleibt
            // v3.12.0 — Titel und Text nennen Anbieter und Zähler: dieselbe Maske wie dort (Kennnummern, Angaben für Briefe)
            'untrusted'   => Sanitize::finding($this->masked((string)$f['title']), $this->masked((string)$f['text'])),
        ]);
    }

    /**
     * v3.12.0 (Skeptiker N2) — die eingetragene Preisstufe: ab wann und die neuen Preise (bis dahin nur der Tag, der
     * Preis stand nur im Fließtext).
     *
     * @param array<string,mixed> $c Zeile aus contractStatus
     * @return array<string,mixed>|null
     */
    private function priceIncreaseRow(string $utility, array $c): ?array
    {
        $pi = is_array($c['price_increase'] ?? null) ? $c['price_increase'] : null;
        if ($pi === null || !is_string($pi['from'] ?? null)) return null;
        // ContractService::priceIncrease: je Preis [alt, neu]
        return self::compact(['from' => $pi['from'],
            'working_price' => self::maybe($pi['working_price_ct'][1] ?? null, fn($v) => Num::of($v, $this->priceUnit($utility), 'entered', ['decimals' => 4])),
            'base_price'    => self::maybe($pi['base_price_eur'][1] ?? null, fn($v) => Num::money($v, $this->currency() . '/month', 'entered'))]);
    }

    private static function priceIncreaseSchema(): array
    {
        return self::obj(['from' => self::date(), 'working_price' => Num::schema(), 'base_price' => Num::schema()], ['from']);
    }

    /** Schema einer Zeile aus rightsRow(). */
    private static function rightsSchema(): array
    {
        return self::arr(self::obj([
            'id' => self::str(), 'check' => self::str(array_keys(RightsService::CHECKS)), 'severity' => self::str(RightsService::SEVERITIES),
            'contract_id' => self::str(), 'bill_id' => self::str(), 'date' => self::date(), 'deadline' => self::date(),
            'nominal_end' => self::date(), 'known_on' => self::date(), 'amount' => Num::schema(), 'rules' => self::arr(self::str()),
            'norms' => self::arr(self::obj(['norm' => self::str(), 'url' => self::str(), 'checked_on' => self::str()])),
            'letter' => self::str(), 'footer' => self::str(), 'untrusted' => self::untrustedSchema('title', 'note'),
        ], ['id', 'check', 'severity', 'date', 'footer']));
    }

    // ── Herkunft von Summen über einen Zeitraum ─────────────────────────────

    /**
     * Die Zähler hinter einem Ziel: der Zähler selbst, die Mitglieder einer Gruppe,
     * ohne Ziel die Zähler der Art, die in Summen zählen (wie forUtility).
     *
     * @param array<string,mixed>|null $target
     * @return list<array<string,mixed>>
     */
    private function membersOf(string $utility, ?array $target): array
    {
        $meters = $this->services->get(MeterService::class);
        if ($target === null) {
            return array_values(array_filter($meters->list($utility), fn($m) => MeterService::countsInTotals($m)));
        }
        return !empty($target['is_group']) ? $meters->groupMembers($utility, (string)$target['id']) : [$target];
    }

    /**
     * Monatszeilen der Zähler (forMeter, je Anfrage einmal gerechnet), mit `meter_id`.
     *
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    private function memberRows(string $utility, array $members): array
    {
        $consumption = $this->services->get(ConsumptionService::class);
        $out = [];
        foreach ($members as $m) {
            try {
                foreach ($consumption->forMeter($utility, $m) as $r) $out[] = $r + ['meter_id' => (string)$m['id']];
            } catch (\Throwable) {
                // ohne Verbrauch (wie SummaryService)
            }
        }
        return $out;
    }

    /**
     * Liegt an beiden Rändern von [start, endExcl) bei jedem Zähler eine Ablesung
     * (oder beginnen bzw. enden seine Daten dort)? Dann umfasst die Summe ganze
     * Ablesungsabstände (Provenance::aligned). Nur kumulative Zähler mit Ständen.
     *
     * @param list<array<string,mixed>> $members
     */
    private function alignedWindow(string $utility, array $members, string $start, string $endExcl): bool
    {
        if (!Utilities::isCumulative($utility) || $members === []) return false;
        $readings = $this->services->get(ReadingService::class);
        foreach ($members as $m) {
            if (!empty($m['is_group']) || ($m['capture'] ?? null) === 'period') return false;
            $dates = [];
            foreach ($readings->list($utility, (string)$m['id']) as $r) {
                if (!empty($r['is_future']) || !empty($r['is_suspect']) || !is_numeric($r['counter'] ?? null)) continue;
                $dates[(string)$r['date']] = true;
            }
            if ($dates === []) continue;
            ksort($dates);
            $okStart = isset($dates[$start]) || $start <= (string)array_key_first($dates);
            $okEnd = isset($dates[$endExcl]) || $endExcl > (string)array_key_last($dates);
            if (!$okStart || !$okEnd) return false;
        }
        return true;
    }

    /**
     * Anteile je Herkunft an einer Summe über [start, endExcl): aus den Monatszeilen
     * der Zähler in diesem Zeitraum, über ganze Ablesungsabstände ohne „verteilt“.
     * `$field` und `$basisOf` wie Provenance::partsOf; bei Kosten wird „verteilt“
     * über ganze Abstände zu „eingetragen“.
     *
     * @param list<array<string,mixed>> $members
     * @param list<array<string,mixed>> $rows Monatszeilen der Zähler (memberRows)
     * @return array<string,float>
     */
    private function windowParts(string $utility, array $members, array $rows, string $start, string $endExcl,
                                 ?string $field = null, ?callable $basisOf = null, string $alignedTo = 'measured'): array
    {
        if ($endExcl <= $start) return [];
        $from = substr($start, 0, 7);
        $to = substr(date('Y-m-d', (int)strtotime($endExcl . ' -1 day')), 0, 7);
        $in = array_values(array_filter($rows, fn($r) => (string)($r['ym'] ?? '') >= $from && (string)($r['ym'] ?? '') <= $to));
        $parts = Provenance::partsOf($in, $field ?? self::valueField($utility), $basisOf);
        return $parts !== [] && $this->alignedWindow($utility, $members, $start, $endExcl) ? Provenance::aligned($parts, $alignedTo) : $parts;
    }

    /** Erster Tag des Monats nach `$ym` (Ende einer Monatsspanne, ausschließlich). */
    private static function afterMonth(string $ym): string
    {
        return date('Y-m-01', (int)strtotime($ym . '-01 +1 month'));
    }

    /**
     * Saldo und Schätzung eines Vertrags (Zeile aus contractStatus) als Zahl-Objekte
     * (Konzept §3.1): Saldo bis heute aus Teilen (Arbeitskosten bis zur letzten Ablesung
     * mit der Herkunft ihrer Monate, geschätzte danach, Grundpreis, Bonus und Abschläge
     * eingetragen), Saldo zur letzten Ablesung mit der Herkunft der Monate seines
     * Zeitraums (gemessen, an einer geschätzten Ablesung geschätzt), erwarteter Saldo
     * und Abschlagsvorschlag geschätzt mit Spanne und Gründen.
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $target Zähler oder Gruppe der Zeile
     * @return array<string,mixed>
     */
    private function contractStatusNums(string $utility, array $c, array $target): array
    {
        $cur = $this->currency();
        $sign = Provenance::signFor(Utilities::isFeedIn($utility));
        $members = $this->membersOf($utility, $target);
        $rows = $this->memberRows($utility, $members);
        $measuredUntil = is_string($c['measured_until'] ?? null) ? $c['measured_until'] : null;
        // v3.12.0 (Skeptiker H4) — ohne Hochrechnung (Einspeisung): Die Summe reicht bis zur letzten Ablesung, nicht bis heute
        $projected = array_key_exists('energy_cost_to_date', $c);
        $asOfToday = $projected ? (string)($c['balance_as_of'] ?? '') : (string)($this->lastMeasuredOf($utility, $members) ?? $c['balance_as_of'] ?? '');
        if (!$projected && $measuredUntil === null && Utilities::isCumulative($utility) && $asOfToday !== '') $measuredUntil = $asOfToday;
        $from = (string)($c['balance_from'] ?? $c['start'] ?? '');
        $monthParts = $measuredUntil !== null && $from !== '' ? $this->windowParts($utility, $members, $rows, $from, $measuredUntil) : [];
        $unc = is_array($c['projection_uncertain'] ?? null) ? $c['projection_uncertain'] : null;
        $reasons = $unc !== null ? array_values(array_map('strval', (array)($unc['reasons'] ?? []))) : [];
        $band = is_array($c['projection_band'] ?? null) ? $c['projection_band'] : null;
        $range = static fn(?array $r): ?array => is_array($r) && is_numeric($r[0] ?? null) && is_numeric($r[1] ?? null)
            ? [min((float)$r[0], (float)$r[1]), max((float)$r[0], (float)$r[1])] : null;
        $projRange = $band !== null && is_numeric($band['low'] ?? null) ? $range([$band['low'], $band['high']]) : $range($unc['projected_range'] ?? null);
        $estOpts = static fn(?array $rg) => array_filter(['range' => $rg, 'uncertain' => $reasons]);
        $measured = is_array($c['balance_measured'] ?? null) ? $c['balance_measured'] : null;
        $measuredParts = $measured !== null && is_string($measured['from'] ?? null) && is_string($measured['until'] ?? null)
            ? ($this->windowParts($utility, $members, $rows, $measured['from'], $measured['until']) ?: ['measured' => 1.0]) : ['measured' => 1.0];
        $settleUnc = is_array($c['settle_uncertain'] ?? null) ? $c['settle_uncertain'] : null;
        // v3.12.0 (Skeptiker M5) — ohne Arbeitspreis sind das nur die Abschläge: keine Bewertung, kein „Rückzahlung“
        $noPrice = !empty($c['price_missing']);
        $priceFlag = $noPrice ? ['price_missing'] : [];

        return self::compact([
            // v3.12.0 (Nachprüfung C4) — Einspeisung: Die Summe läuft seit `balance_from` (seit v3.12.0 der Beginn des
            // Abrechnungsjahres, wie bei Strom); der Satz sagt das
            'balance_today'     => $this->withMeaning(self::maybe($c['current_balance'] ?? null, fn($v) => Provenance::money($v, $cur,
                Provenance::balanceParts($c, $monthParts), array_filter(['as_of' => $asOfToday ?: null, 'uncertain' => $priceFlag]) + ['sign' => $sign])), $utility, $noPrice,
                Utilities::isFeedIn($utility) && $from !== '' ? $from : null),
            // v3.12.0 (Nachprüfung C5) — ohne Arbeitspreis sind das nur Abschläge: eingetragen, nicht gemessen
            'balance_measured'  => $measured !== null ? $this->withMeaning(self::maybe($measured['balance'] ?? null, fn($v) => Provenance::money($v, $cur,
                $noPrice ? ['entered' => 1.0] : $measuredParts, array_filter(['uncertain' => $priceFlag]) + ['as_of' => (string)$measured['until'], 'sign' => $sign])), $utility, $noPrice) : null,
            'expected_balance'  => $this->withMeaning(self::maybe($c['projected_end_balance'] ?? null, fn($v) => Num::money($v, $cur, 'estimated',
                array_filter(['range' => $projRange, 'uncertain' => array_values(array_unique([...$reasons, ...$priceFlag]))]) + ['sign' => $sign])), $utility, $noPrice),
            // eine Abrechnung über mehrere Verträge (v3.10.0): die Summe wie die Saldo-Karte
            'settle_balance'    => $this->withMeaning(self::maybe($c['settle_balance'] ?? null, fn($v) => Num::money($v, $cur, 'estimated',
                array_filter(['range' => $range($settleUnc['projected_range'] ?? null),
                    'uncertain' => array_values(array_map('strval', (array)($settleUnc['reasons'] ?? [])))]) + ['sign' => $sign])), $utility, $noPrice),
            'suggested_advance' => self::maybe($c['suggested_advance'] ?? null, fn($v) => Num::money($v, $cur, 'estimated',
                $estOpts($range($unc['suggested_range'] ?? null)))),
            // ohne einzelnen Vorschlag (unsichere Schätzung) nur die Spanne
            'suggested_advance_range' => ($c['suggested_advance'] ?? null) === null && ($r = $range($unc['suggested_range'] ?? null)) !== null
                ? Num::money(($r[0] + $r[1]) / 2, $cur, 'estimated', ['range' => $r, 'uncertain' => $reasons]) : null,
            'advance_ongoing'   => self::maybe($c['advance_ongoing'] ?? null, fn($v) => Num::money($v, $cur, 'estimated', array_filter(['uncertain' => $reasons]))),
            // v3.11.0 (#56) — der Bonus in diesem Saldo: nach dem Tag der Gutschrift, nicht je Kalendermonat
            'bonus_to_date'     => (float)($c['bonus_to_date'] ?? 0.0) !== 0.0 ? Num::money((float)$c['bonus_to_date'], $cur, 'entered') : null,
            'measured_until'    => $measuredUntil,
            'balance_from'      => $c['balance_from'] ?? null,
            'months_left'       => isset($c['months_left']) && is_numeric($c['months_left']) ? (int)$c['months_left'] : null,
            'suggestion_withheld' => is_string($c['suggestion_withheld'] ?? null) ? $c['suggestion_withheld'] : null,
            'verdict'           => !$noPrice && is_string($c['verdict'] ?? null) ? $c['verdict'] : null,
        ]);
    }

    /**
     * Abrechnungsjahre (BillingPeriodService::forTarget/forUtility) als Zeilen. Die
     * Herkunft der Menge aus den Monaten ihres Zeitraums bis zur letzten Ablesung
     * (`as_of`); liegt an beiden Rändern eine Ablesung, ist die Summe gemessen
     * (windowParts). Die schwächste Herkunft je Zeile, dazu der gemessene Anteil.
     * Zeiträume ohne Ablesung fehlen.
     *
     * @param list<array<string,mixed>> $periods
     * @param list<array<string,mixed>> $members
     * @param list<array<string,mixed>> $rows Monatszeilen der Zähler (memberRows)
     * @return list<array<string,mixed>>
     */
    private function billingRows(string $utility, array $periods, array $members, array $rows): array
    {
        $vf = self::valueField($utility);
        $sign = Provenance::signFor(Utilities::isFeedIn($utility));
        $out = [];
        foreach ($periods as $p) {
            $asOf = $p['as_of'] ?? null;
            if (!is_string($asOf)) continue;
            $endExcl = date('Y-m-d', (int)strtotime($asOf . ' +1 day'));
            $parts = $this->windowParts($utility, $members, $rows, (string)$p['from'], $endExcl) ?: ['measured' => 1.0];
            $basis = Provenance::weakest(...array_keys($parts));
            $avc = is_array($p['advance_vs_cost'] ?? null) ? $p['advance_vs_cost'] : null;
            $balance = round((float)($p['balance'] ?? 0.0), 2);
            $i18n = $this->services->get(I18nService::class);
            $noPrice = !empty($p['price_missing']);
            $meaning = $noPrice ? 'no_price' : Provenance::meaning($balance, Utilities::isFeedIn($utility));
            // v3.12.0 (Nachprüfung B2) — kein Vertrag in der Periode: kein Betrag und kein Satz wie an der Monatszeile.
            // Bis dahin stand dort „Ausgeglichen“, die KI las das als Aussage über den Saldo.
            $noContract = !empty($p['no_contract']);
            $hasMoney = self::moneyField($utility) !== null;
            $out[] = self::compact([
                'period'         => $p['from'] . '/' . $p['until'],
                'from'           => (string)$p['from'],
                'until'          => (string)$p['until'],
                'as_of'          => $asOf,
                // v3.12.0 (Skeptiker N6) — `as_of` ist der letzte gezählte Tag; die Ablesung lag einen Tag später (so nennt sie `balance_measured.as_of`)
                'reading_on'     => $endExcl,
                'value'          => round((float)($p[$vf] ?? 0.0), 3),
                'cost'           => $noContract ? null : round((float)($p['cost'] ?? 0.0), 2),
                'advances'       => $noContract ? null : round((float)($p['advances'] ?? 0.0), 2),
                'balance'        => $noContract ? null : $balance,
                'balance_sign'   => $noContract ? null : $sign,
                'balance_meaning' => $noContract ? null : $meaning,
                'balance_text'   => $noContract ? null : $i18n->t("mcp.balance.$meaning", ['amount' => $i18n->money(abs($balance))], $this->textLocale),
                'no_contract'    => $noContract && $hasMoney ? true : null,
                'included_in_parent' => $noContract && $hasMoney && count($members) === 1 && !empty($members[0]['parent_meter_id']) ? true : null,
                'days'           => (int)($p['days'] ?? 0),
                'basis'          => $basis,
                'measured_share' => round((float)($parts['measured'] ?? 0.0), 3),
                'running'        => !empty($p['running']),
                'complete'       => !empty($p['complete']),
                'source'         => (string)($p['source'] ?? ''),
                'price_missing'  => !empty($p['price_missing']) ? true : null,
                // v3.11.0 (#54) — Abschläge mindestens das Doppelte der Kosten, mit dem Vertrag, der die meisten Tage abrechnet
                'advance_vs_cost' => $avc !== null ? self::compact(['factor' => (float)$avc['factor'], 'basis' => $basis,
                    'contract_id' => is_string($avc['contract_id'] ?? null) ? $avc['contract_id'] : null]) : null,
            ]);
        }
        return $out;
    }

    /** Schema einer Zeile aus billingRows(). */
    private static function billingRowSchema(): array
    {
        return self::obj([
            'period' => self::str(), 'from' => self::date(), 'until' => self::date(), 'as_of' => self::date(), 'reading_on' => self::date(),
            'value' => self::number(), 'cost' => self::number(), 'advances' => self::number(), 'balance' => self::number(),
            'balance_sign' => self::str(Provenance::SIGNS), 'balance_meaning' => self::str(Provenance::MEANINGS), 'balance_text' => self::str(),
            'no_contract' => self::bool(), 'included_in_parent' => self::bool(),
            'days' => self::int(), 'basis' => self::basisSchema(),
            'measured_share' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'running' => self::bool(), 'complete' => self::bool(),
            'source' => self::str(), 'price_missing' => self::bool(),
            'advance_vs_cost' => self::obj(['factor' => self::number(), 'basis' => self::basisSchema(), 'contract_id' => self::str()], ['factor', 'basis']),
        ], ['period', 'from', 'until', 'value', 'basis']);
    }

    // ── Schema-Bausteine (Mcp\Schema::KEYWORDS) ─────────────────────────────

    /** @param array<string,mixed> $props @param list<string> $required */
    private static function obj(array $props, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $props] + ($required !== [] ? ['required' => $required] : []) + ['additionalProperties' => false];
    }

    private static function arr(array $items, ?int $max = null): array
    {
        return ['type' => 'array', 'items' => $items] + ($max !== null ? ['maxItems' => $max] : []);
    }

    private static function str(?array $enum = null): array
    {
        return ['type' => 'string'] + ($enum !== null ? ['enum' => $enum] : []);
    }

    private static function date(): array
    {
        return ['type' => 'string', 'format' => 'date'];
    }

    private static function bool(): array
    {
        return ['type' => 'boolean'];
    }

    private static function int(int $min = 0): array
    {
        return ['type' => 'integer', 'minimum' => $min];
    }

    private static function number(): array
    {
        return ['type' => 'number'];
    }

    private static function basisSchema(): array
    {
        return ['type' => 'string', 'enum' => Provenance::BASES];
    }

    /** Eingabe `utility` (Aufzählung aus der SSOT, auf Wunsch eingeschränkt) */
    private static function utilityArg(?array $keys = null): array
    {
        return ['type' => 'string', 'enum' => $keys ?? Utilities::keys()];
    }

    /** Eingabe `meter`: ID eines Zählers oder einer Gruppe, oder Alias (external_id) */
    private static function meterArg(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 120];
    }

    /** JSON-Schema eines Fremdtext-Felds mit den genannten Feldern. */
    private static function untrustedSchema(string ...$fields): array
    {
        return ['type' => 'object', 'properties' => array_fill_keys($fields, ['type' => 'string']), 'additionalProperties' => false];
    }

    /** Schema der Preise (contractPrices). */
    private static function pricesSchema(): array
    {
        return ['type' => 'object', 'properties' => array_fill_keys(['working_price', 'base_price', 'advance', 'advance_every_months'], Num::schema())
                + ['working_price_by_meter' => self::arr(self::obj(['meter_id' => self::str(), 'price' => Num::schema()], ['meter_id', 'price']))],
                'additionalProperties' => false];
    }

    /** Schema von Saldo und Schätzung (contractStatusNums). */
    private static function statusSchema(): array
    {
        $num = Num::schema();
        return ['type' => 'object', 'properties' => [
            'balance_today' => $num, 'balance_measured' => $num, 'expected_balance' => $num, 'settle_balance' => $num,
            'suggested_advance' => $num, 'suggested_advance_range' => $num, 'advance_ongoing' => $num, 'bonus_to_date' => $num,
            'measured_until' => ['type' => 'string', 'format' => 'date'], 'balance_from' => ['type' => 'string', 'format' => 'date'],
            'months_left' => ['type' => 'integer', 'minimum' => 0], 'suggestion_withheld' => ['type' => 'string'], 'verdict' => ['type' => 'string'],
        ], 'additionalProperties' => false];
    }
}
