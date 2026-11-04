<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Config\Rights;
use Energietracker\Services\ContractService;
use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1035, Paket posteingang, Konzept §5 „C“) — ein Preisänderungsschreiben aus
 * Sicht der Rechte als Kunde, an einer Stelle gerechnet: Vorschläge (Herkunft am Preis,
 * Termin, Proposals) und Kernaussage (Gist) lesen dieselbe Bewertung (Lektion 92).
 *
 *   Richtung    up | down | same | unknown — je Bestandteil (Arbeits-, Grundpreis) neu
 *               gegen alt; der alte Preis aus dem Schreiben, sonst aus dem Vertrag am Vortag
 *               (bei Zweitarif die Liste des zugeordneten Zählers). Arbeitspreis hoch und
 *               Grundpreis runter bleibt eine Erhöhung (Konzept A3, Grenzfälle).
 *   Steuer      reine Umsatzsteueränderung: Steuersatz des Schreibens ≠ Steuersatz am
 *               Preis-Eintrag des Vortags (`vat_pct`, sonst unbekannt), und jeder genannte
 *               Bestandteil hat denselben Nettopreis (Toleranz wie beim Abgleich der Preise).
 *               Kein Kündigungsrecht beim Sondervertrag (§ 41 Abs. 6 EnWG, Prüfbericht 20).
 *               Der Satz wird gelesen, nie vermutet.
 *   Frist       nur Strom und Gas in einem Land mit Regeln, nur bei einer Erhöhung (oder
 *               unbekannter Richtung), nie bei reiner Steueränderung: Zugang bis zum Tag vor
 *               dem Wirksamwerden (Rights::specialTerminationDeadline). Nennt das Schreiben
 *               ein früheres Datum, ist das der Termin, der Text nennt beide (Entscheidung 7c).
 *   Ankündigung Datum des Schreibens (`document_date`, nur sicher gelesen) gegen einen Monat
 *               (Sondervertrag, auch bei unbekannter Art: die mildere Frist) bzw. sechs Wochen
 *               und Monatsbeginn (Grundversorgung) — Rights::announcement.
 *   Art         `supply_kind` am Vertrag, sonst `basic_supply` des Schreibens, sonst null.
 *
 * Die Sätze (`lines`) tragen nur Regeln aus Config\Rights (Prüfbericht §12); ein Satz,
 * dessen Regel im Land nicht nennbar ist, entfällt. Hinweis, keine Rechtsberatung.
 */
final class PriceChange
{
    /**
     * App-Schwelle (keine Rechtsregel): Der neue Abschlag im Schreiben steigt um mehr als so
     * viele Prozentpunkte stärker als die Kosten laut Prognose → Satz mit der Regel zum
     * Abschlag (§ 13 Abs. 2 GVV bzw. § 41b Abs. 3 EnWG). Fängt das Runden auf ganze Euro ab.
     */
    public const ADVANCE_EXCESS_PCT = 5.0;

    /** Toleranz beim Vergleich zweier Preise (ct/kWh bzw. € je Monat), wie Proposals::PRICE_TOL. */
    private const TOL = 0.011;

    /**
     * @param array<string,mixed>      $entry  Eintrag des Posteingangs (Art price_change)
     * @param array<string,mixed>|null $ct     zugeordneter Vertrag
     * @return array<string,mixed>|null null ohne gültiges `valid_from`
     */
    public static function assess(array $entry, string $u, ?array $ct, ?string $meterId, string $country,
                                  ContractService $contracts, string $today): ?array
    {
        $from = Proposals::value($entry, 'valid_from');
        if (!Dates::isIsoDate($from)) return null;
        $before = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $doc = Proposals::value($entry, 'document_date');
        $announced = Dates::isIsoDate($doc) && !Proposals::flagged($entry, 'document_date') ? $doc : null;

        $wpList = $ct !== null ? ContractService::workingPricesFor($ct, $meterId) : [];
        $bpList = $ct !== null ? (array)($ct['base_prices'] ?? []) : [];
        $year = Proposals::value($entry, 'base_price_period') === 'year';
        $comps = [];
        $wpNew = self::num(Proposals::value($entry, 'working_price_new'));
        if ($wpNew !== null) {
            $comps['working'] = ['new' => $wpNew, 'old' => self::num(Proposals::value($entry, 'working_price_old'))
                ?? ($ct !== null ? $contracts->valueOnDate($wpList, 'ct_per_kwh', $before) : null)];
        }
        $bpNew = self::num(Proposals::value($entry, 'base_price_new'));
        if ($bpNew !== null) {
            $bpOld = self::num(Proposals::value($entry, 'base_price_old'));
            if ($year) { $bpNew /= 12; if ($bpOld !== null) $bpOld /= 12; }
            $bpOld ??= $ct !== null ? $contracts->valueOnDate($bpList, 'eur_per_month', $before) : null;
            $comps['base'] = ['new' => $bpNew, 'old' => $bpOld];
        }

        $up = $down = $unknown = false;
        foreach ($comps as $c) {
            if ($c['old'] === null) { $unknown = true; continue; }
            if ($c['new'] > $c['old'] + self::TOL) $up = true;
            elseif ($c['new'] < $c['old'] - self::TOL) $down = true;
        }
        $direction = $up ? 'up' : ($unknown || $comps === [] ? 'unknown' : ($down ? 'down' : 'same'));

        // Steuersatz des Vortags: der Eintrag, der dann gilt (nicht der letzte mit Satz)
        $vatNew = self::num(Proposals::value($entry, 'vat_pct'));
        $vatOld = null;
        foreach (['working' => $wpList, 'base' => $bpList] as $k => $list) {
            if (!isset($comps[$k]) || $vatOld !== null) continue;
            $vatOld = self::num(self::rowOn($list, $before)['vat_pct'] ?? null);
        }
        $vatOnly = $vatNew !== null && $vatOld !== null && abs($vatNew - $vatOld) > 0.001 && $comps !== []
            && self::sameNet($comps, $vatOld, $vatNew);

        $rights = Rights::supports($country);
        $energy = $rights && Rights::isEnergy($country, $u);
        $increase = in_array($direction, ['up', 'unknown'], true) && !$vatOnly;
        $legal = $energy && $increase ? Rights::specialTerminationDeadline($from) : null;
        $letter = Proposals::value($entry, 'special_termination_until');
        $letter = Dates::isIsoDate($letter) ? $letter : null;
        // Termin: der frühere Tag, solange er nicht vorbei ist — ein früheres Datum des Anbieters
        // bindet nicht (Prüfbericht 21), danach zählt wieder der gesetzliche
        $open = array_values(array_filter([$letter !== null && $legal !== null && $letter < $legal ? $letter : null, $legal],
            fn($d) => $d !== null && $d >= $today));
        $termin = $legal === null ? null : ($open !== [] ? min($open) : $legal);

        $kind = in_array($ct['supply_kind'] ?? null, Rights::SUPPLY_KINDS, true) ? (string)$ct['supply_kind'] : null;
        $basic = Proposals::value($entry, 'basic_supply');
        if ($kind === null && is_bool($basic) && !Proposals::flagged($entry, 'basic_supply')) $kind = $basic ? 'basic' : 'special';

        $announcement = null;
        if ($energy && $increase && $announced !== null && ($a = Rights::announcement($country, $announced, $from)) !== null) {
            $isBasic = $kind === 'basic';
            $announcement = [
                'days_before' => $a['days_before'],
                'announce_by' => $isBasic ? $a['basic']['announce_by'] : $a['special']['announce_by'],
                'late' => $isBasic ? $a['basic']['late'] : $a['special']['late'],
                'required' => $isBasic ? 'six_weeks' : 'month',
                'month_start' => $isBasic ? $a['basic']['month_start'] : null,
            ];
        }
        $guarantee = $ct['price_guarantee_until'] ?? null;

        return [
            'utility' => $u, 'country' => $country, 'valid_from' => $from, 'announced_on' => $announced,
            'rights' => $rights, 'energy' => $energy, 'heat' => $rights && $u === 'fernwaerme',
            'kind' => $kind, 'components' => $comps, 'direction' => $direction,
            'vat_only' => $vatOnly, 'vat_old' => $vatOld, 'vat_new' => $vatNew,
            'legal_deadline' => $legal, 'letter_deadline' => $letter, 'termin' => $termin,
            'termin_passed' => $legal !== null && $open === [],
            'announcement' => $announcement,
            'guarantee_until' => Dates::isIsoDate($guarantee) && $guarantee >= $from && $increase ? $guarantee : null,
        ];
    }

    /**
     * Sätze für die Kernaussage, je Satz die Regeln, die er nennt. Ein Satz, dessen Regel im
     * Land nicht nennbar ist (Status U, `cite: false`, unbekannt), entfällt.
     *
     * @param array<string,mixed> $a aus assess()
     * @param array{advance_pct: float, cost_pct: float}|null $advance Abschlag laut Schreiben gegen die Kosten
     * @return list<array{key: string, params: array<string,mixed>, rules: list<string>}>
     */
    public static function lines(array $a, ?array $advance = null): array
    {
        $u = (string)$a['utility'];
        $gvv = fn(string $par) => 'de.' . ($u === 'gas' ? 'gas' : 'strom') . 'gvv.' . $par;
        $kind = $a['kind'];
        $out = [];
        $add = function (string $key, array $params, array $rules) use (&$out): void {
            $out[] = ['key' => 'inbox.gist.rights.' . $key, 'params' => $params, 'rules' => $rules];
        };
        if ($a['heat']) $add('heat', [], ['de.avbfernwaermev.24.4']);
        if ($a['energy']) {
            if ($a['vat_only']) {
                $kind === 'basic' ? $add('vatOnlyBasic', [], [$gvv('20')]) : $add('vatOnly', [], ['de.enwg.41.6']);
            } elseif ($a['legal_deadline'] !== null) {
                $p = ['date' => $a['valid_from'], 'deadline' => $a['legal_deadline']];
                if ($a['termin_passed']) $add('terminationPassed', $p, $kind === 'basic' ? [$gvv('5.3')] : ($kind === 'special' ? ['de.enwg.41.5.s4'] : ['de.enwg.41.5.s4', $gvv('5.3')]));
                else match ($kind) {
                    'special' => $add('terminationSpecial', $p, ['de.enwg.41.5.s4', 'de.vz.special_termination']),
                    'basic'   => $add('terminationBasic', $p, [$gvv('5.3'), $gvv('20'), 'de.vz.special_termination']),
                    default   => $add('terminationUnknown', $p, ['de.enwg.41.5.s4', $gvv('5.3'), $gvv('20'), 'de.enwg.40.2.13', 'de.vz.special_termination']),
                };
                if ($a['letter_deadline'] !== null && $a['letter_deadline'] !== $a['legal_deadline']) {
                    $add($a['letter_deadline'] < $a['legal_deadline'] ? 'letterEarlier' : 'letterLater',
                        ['letter' => $a['letter_deadline'], 'deadline' => $a['legal_deadline']], ['de.vz.special_termination']);
                }
                $an = $a['announcement'];
                if ($an !== null && $an['late']) {
                    if ($an['days_before'] <= 0) $add('announcedAfter', ['date' => $a['announced_on']], $kind === 'basic' ? [$gvv('5.2')] : ['de.enwg.41.5.s1']);
                    elseif ($kind === 'basic') $add('lateBasic', ['days' => $an['days_before']], [$gvv('5.2')]);
                    else $add('lateSpecial', ['days' => $an['days_before']], ['de.enwg.41.5.s1']);
                }
                if ($an !== null && $an['month_start'] === false) $add('monthStart', ['date' => $a['valid_from']], [$gvv('5.2')]);
                if ($a['guarantee_until'] !== null) $add('guarantee', ['date' => $a['guarantee_until']], []);
            }
            if ($advance !== null && !$a['vat_only'] && $advance['advance_pct'] > $advance['cost_pct'] + self::ADVANCE_EXCESS_PCT) {
                $p = ['advance_pct' => (int)round($advance['advance_pct']), 'cost_pct' => (int)round($advance['cost_pct'])];
                match ($kind) {
                    'basic'   => $add('advanceBasic', $p, [$gvv('13')]),
                    'special' => $add('advanceSpecial', $p, ['de.enwg.41b.3']),
                    default   => $add('advanceUnknown', $p, ['de.enwg.41b.3', $gvv('13')]),
                };
            }
        }
        $country = (string)$a['country'];
        return array_values(array_filter($out, function (array $l) use ($country): bool {
            foreach ($l['rules'] as $r) if (!Rights::citable($country, $r)) return false;
            return true;
        }));
    }

    /**
     * Die Normen der Sätze, ohne Doppel, in der Reihenfolge der Nennung (für die Fußzeile
     * „Hinweis, keine Rechtsberatung. Grundlage: …“, lib/letter.js rightsFooterHtml).
     *
     * @param list<array{rules: list<string>}> $lines
     * @return list<array{key:string, norm:string, url:string, checked_on:string, status:string}>
     */
    public static function norms(array $lines, string $country): array
    {
        $out = [];
        foreach ($lines as $l) {
            foreach ($l['rules'] as $k) {
                if (isset($out[$k]) || ($r = Rights::rule($country, $k)) === null) continue;
                $out[$k] = ['key' => $k, 'norm' => $r['norm'], 'url' => $r['url'], 'checked_on' => $r['checked_on'], 'status' => $r['status']];
            }
        }
        return array_values($out);
    }

    /**
     * Gleicher Nettopreis bei anderem Steuersatz? Jeder Bestandteil mit altem Preis: alt ohne
     * alten Satz = neu ohne neuen Satz. v3.11.0 (integration) — dieselbe Regel wie am Vertrag
     * (A3, B4): ContractService::pureVatChange je Bestandteil, eine Toleranz für beide.
     *
     * @param array<string, array{new: float, old: ?float}> $comps
     */
    public static function sameNet(array $comps, float $vatOld, float $vatNew): bool
    {
        foreach ($comps as $c) {
            if ($c['old'] === null) return false;
            if (!ContractService::pureVatChange(['p' => $c['old'], 'vat_pct' => $vatOld], ['p' => $c['new'], 'vat_pct' => $vatNew], 'p')) return false;
        }
        return true;
    }

    /** Der Preis-Eintrag, der an einem Tag gilt (spätestes `from` bis dahin), oder null. */
    private static function rowOn(array $list, string $date): ?array
    {
        $best = null;
        foreach ($list as $r) {
            $f = (string)($r['from'] ?? '');
            if ($f !== '' && $f <= $date && ($best === null || $f >= (string)$best['from'])) $best = $r;
        }
        return $best;
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float)$v : null;
    }
}
