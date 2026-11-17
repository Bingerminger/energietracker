<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Support\Dates;
use Energietracker\Support\Input;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\TextLimits;

/**
 * v3.13.0 (Paket F, Bekannte Grenze v3.12.0) — Listenfelder im Posteingang je Eintrag bestätigen,
 * korrigieren oder verwerfen.
 *
 * Felder mit mehreren Werten (Arbeitspreise, Wasserpreise, Stände je Zähler, Abschläge, Gas-Umrechnung,
 * Gutschriften, Abschlagstermine) zeigte der Posteingang bis v3.12 nur als Ganzes; ein markierter Eintrag
 * blieb markiert, ein falscher ließ sich nicht korrigieren (`edits` nimmt für eine Liste nichts an).
 *
 * Ablage additiv am Eintrag des Posteingangs (kein Schemaschritt; im Backup mit dem Topf `inbox`):
 *
 *   item_edits  {<feld>: {"<n>": {action, value?, at}}}
 *               n       Stelle des Eintrags in `extraction.fields.<feld>.items` (wie ausgelesen; die
 *                       Stellen ändern sich nur mit einem neuen Auslesen, das die Entscheidungen leert)
 *               action  confirm  „Stimmt“: der gelesene Wert gilt, die Marke fällt weg
 *                       correct  „Korrigieren“: `value` mit den geänderten Unterfeldern gilt
 *                       discard  „Verwerfen“: der Eintrag zählt nicht (kein Vorschlag aus ihm)
 *
 * Die gespeicherte Auslesung bleibt unverändert (Herkunft: gelesen). Zwei Sichten rechnet apply() bzw.
 * annotate() bei jedem Abruf:
 *   - apply()     für die Vorschläge (Proposals::build) und den Haushalt aus Dokumenten: verworfene
 *                 Einträge fehlen, korrigierte tragen den neuen Wert, entschiedene sind nicht markiert;
 *                 jeder Eintrag nennt `index` (Stelle wie gespeichert), `origin` (read | corrected) und
 *                 `confirmed`. Wert, Marke und Summe des Felds (`value`, `status`, `reasons`,
 *                 `advances_paid.sum`, Bruttopreis `net`) folgen den Einträgen.
 *   - annotate()  für die Anzeige (Vorschau, KI): alle Einträge an ihrer Stelle, dazu `decision`,
 *                 `origin` und beim korrigierten der gelesene Wert (`read_value`); verworfene mit
 *                 Status `discarded`.
 */
final class ItemEdits
{
    public const ACTIONS = ['confirm', 'correct', 'discard'];
    /** höchstens so viele Einträge je Liste (eine Rechnung hat selten mehr als 40 Preiszeilen) */
    public const MAX_INDEX = 500;

    /**
     * Neue Entscheidungen aus einer Anfrage (`PATCH /api/inbox/{id}` mit `item_edits`): je Feld und Stelle
     * `{action, value?}` oder null (zurücksetzen auf „wie gelesen“). Geprüft gegen die Auslesung.
     *
     * @param array<string,mixed> $entry Eintrag des Posteingangs
     * @return array<string,array<string,array<string,mixed>>> neue `item_edits`
     * @throws LocalizedException errors.inbox.itemEditInvalid | errors.inbox.editInvalid | errors.common.tooLong
     */
    public static function patch(array $entry, mixed $patch): array
    {
        $type = (string)($entry['type'] ?? '');
        if (!is_array($patch) || array_is_list($patch) && $patch !== []) throw self::invalid('', null);
        $out = is_array($entry['item_edits'] ?? null) ? $entry['item_edits'] : [];
        $now = date('c');
        foreach ($patch as $field => $byIndex) {
            $field = (string)$field;
            $def = InboxSchema::fields($type)[$field] ?? null;
            $items = (array)($entry['extraction']['fields'][$field]['items'] ?? []);
            if ($def === null || $def['kind'] !== 'list' || !is_array($byIndex)) throw self::invalid($field, null);
            foreach ($byIndex as $i => $edit) {
                if (!is_int($i) && !(is_string($i) && ctype_digit($i))) throw self::invalid($field, null);
                $i = (int)$i;
                if ($i < 0 || $i > self::MAX_INDEX || !is_array($items[$i] ?? null)) throw self::invalid($field, $i);
                if ($edit === null) { unset($out[$field][(string)$i]); continue; }
                $action = is_array($edit) ? ($edit['action'] ?? null) : null;
                if (!in_array($action, self::ACTIONS, true)) throw self::invalid($field, $i);
                $row = ['action' => $action, 'at' => $now];
                if ($action === 'correct') {
                    $vals = $edit['value'] ?? null;
                    if (!is_array($vals) || $vals === [] || array_is_list($vals)) throw self::invalid($field, $i);
                    $clean = [];
                    foreach ($vals as $sub => $v) {
                        $sub = (string)$sub;
                        $subDef = $def['items'][$sub] ?? null;
                        if ($subDef === null) throw self::invalid($field, $i);
                        $clean[$sub] = self::subValue($subDef, "$field.$sub", $v);
                    }
                    // eine zweite Korrektur ergänzt die erste (die Oberfläche schickt nur Geändertes)
                    $prev = $out[$field][(string)$i] ?? null;
                    if (is_array($prev) && ($prev['action'] ?? null) === 'correct' && is_array($prev['value'] ?? null)) $clean = array_merge($prev['value'], $clean);
                    $row['value'] = $clean;
                }
                $out[$field][(string)$i] = $row;
            }
            if (($out[$field] ?? null) === []) unset($out[$field]);
        }
        return $out;
    }

    /** Hat der Eintrag `$i` von `$field` eine Entscheidung? */
    public static function decided(array $entry, string $field, int $i): bool
    {
        return is_array($entry['item_edits'][$field][(string)$i] ?? null);
    }

    /**
     * Sicht für die Vorschläge: Entscheidungen angewandt, verworfene Einträge entfernt.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function apply(array $entry): array
    {
        return self::view($entry, false);
    }

    /**
     * Sicht für die Anzeige: alle Einträge an ihrer Stelle mit Entscheidung und Herkunft.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function annotate(array $entry): array
    {
        return self::view($entry, true);
    }

    /** @return array<string,mixed> */
    private static function view(array $entry, bool $keepDiscarded): array
    {
        $fields = $entry['extraction']['fields'] ?? null;
        if (!is_array($fields)) return $entry;
        $edits = is_array($entry['item_edits'] ?? null) ? $entry['item_edits'] : [];
        $defs = InboxSchema::fields((string)($entry['type'] ?? ''));
        foreach ($fields as $name => $f) {
            if (!is_array($f) || !is_array($f['items'] ?? null) || ($defs[$name]['kind'] ?? null) !== 'list') continue;
            $mine = is_array($edits[$name] ?? null) ? $edits[$name] : [];
            $items = [];
            $kept = 0;
            foreach (array_values($f['items']) as $i => $it) {
                if (!is_array($it)) continue;
                $e = is_array($mine[(string)$i] ?? null) ? $mine[(string)$i] : null;
                $it['index'] = $i;
                $it['origin'] = 'read';
                $it['confirmed'] = false;
                if ($e !== null) {
                    $it['decision'] = (string)$e['action'];
                    if ($e['action'] === 'discard') {
                        if (!$keepDiscarded) continue;
                        $it['status'] = 'discarded';
                        $items[] = $it;
                        continue;
                    }
                    if ($e['action'] === 'correct' && is_array($e['value'] ?? null)) {
                        if ($keepDiscarded) $it['read_value'] = $it['value'];
                        $it['value'] = array_merge((array)($it['value'] ?? []), $e['value']);
                        $it['origin'] = 'corrected';
                        $it['reasons'] = [];
                    } else {
                        $it['confirmed'] = true;
                        // „Stimmt“: Zweifel fallen weg, Hinweise (etwa „netto“) bleiben sichtbar
                        $it['reasons'] = array_values(array_intersect((array)($it['reasons'] ?? []), Evidence::HINTS));
                    }
                    $it['status'] = 'ok';
                    $pk = Evidence::PRICE_ITEM_KEY[$name] ?? null;
                    if ($pk !== null) {
                        // Bruttopreis neu aus Wert, Basis und Satz des Eintrags (korrigiert: die neuen)
                        unset($it['net']);
                        $tax = Evidence::itemTax($it);
                        if ($tax !== null && $tax['net'] && is_numeric($it['value'][$pk] ?? null)) {
                            $it['net'] = ['vat_pct' => $tax['vat_pct'], 'gross' => Evidence::grossOf((float)$it['value'][$pk], $tax)];
                        }
                    }
                }
                $items[] = $it;
                $kept++;
            }
            $active = array_values(array_filter($items, fn($it) => ($it['status'] ?? '') !== 'discarded'));
            $f['items'] = $items;
            $f['value'] = array_map(fn($it) => $it['value'] ?? [], $active);
            $check = array_filter($active, fn($it) => ($it['status'] ?? '') === 'check') !== [];
            $f['reasons'] = array_values(array_unique(array_merge(...array_map(fn($it) => (array)($it['reasons'] ?? []), [[], ...$active]))));
            $f['status'] = $kept === 0 ? 'empty' : ($check ? 'check' : 'ok');
            if ($kept === 0) $f['reasons'] = ['discarded'];
            if ($name === 'advances_paid') {
                // wie Evidence::crossCheckInvoice: Summe der Abschläge aus den geltenden Einträgen
                $amounts = array_filter(array_map(fn($it) => $it['value']['amount'] ?? null, $active), 'is_numeric');
                $f['sum'] = $amounts !== [] ? round(array_sum($amounts), 2) : null;
            }
            $fields[$name] = $f;
        }
        $entry['extraction']['fields'] = $fields;
        $entry['extraction']['flags'] = Evidence::flagCount($fields, (array)($entry['edits'] ?? []));
        return $entry;
    }

    /**
     * Wert eines Unterfelds nach seiner Art (wie die Korrektur eines Einzelfelds, InboxService::editValue):
     * Tag, Zahl ab 0, Auswahl, ja/nein, Text bis TextLimits::DOC_VALUE. Leer (null, '') heißt: nicht angegeben.
     *
     * @param array<string,mixed> $def
     */
    private static function subValue(array $def, string $field, mixed $value): mixed
    {
        $bad = fn() => new LocalizedException('errors.inbox.editInvalid', ['field' => $field, 'value' => is_scalar($value) ? (string)$value : ''], 'item edit');
        if ($value === null || $value === '') return null;
        switch ($def['kind']) {
            case 'date':
                $v = is_string($value) && Dates::isIsoDate($value) ? $value : Evidence::dateValue($value);
                if ($v === null) throw $bad();
                Input::assertDateInRange($v, $field);
                return $v;
            case 'number': case 'money': case 'price_ct':
                $n = is_string($value) ? (Evidence::valueReadings($value)[0] ?? null) : (is_int($value) || is_float($value) ? (float)$value : null);
                if ($n === null || !is_finite($n) || $n < 0 || $n > 1e12) throw $bad();
                return round($n, 6);
            case 'enum':
                if (!in_array($value, (array)$def['options'], true)) throw $bad();
                return $value;
            case 'bool':
                return Input::bool($value) ?? throw $bad();
            default:
                if (!is_string($value) && !is_int($value)) throw $bad();
                $v = trim((string)$value);
                if (mb_strlen($v) > TextLimits::DOC_VALUE) throw TextLimits::tooLong($field, TextLimits::DOC_VALUE, mb_strlen($v));
                return $v;
        }
    }

    private static function invalid(string $field, ?int $item): LocalizedException
    {
        return new LocalizedException('errors.inbox.itemEditInvalid', ['field' => $field, 'item' => $item === null ? '' : (string)($item + 1)],
            "item edit $field #" . ($item ?? '-'));
    }
}
