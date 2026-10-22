<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConversionFactorService;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\Dates;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — ein Entwurf des ganzen Haushalts aus
 * allen ausgelesenen, noch nicht erledigten Dokumenten des Posteingangs.
 *
 * Statt je Dokument eine Liste: Zähler, Verträge mit Preiskette und
 * Abschlägen, Ablesungen, Rechnungen und Termine, zusammengeführt über die
 * Dokumente hinweg. Gearbeitet wird nur mit den gespeicherten Auslesungen
 * (`extraction`, dazu die Korrekturen `edits`) — ohne Aufruf eines Modells.
 *
 * Zusammenführung:
 *   - Lieferverhältnis je Verbrauchsart: Vertrags- oder Kundennummer oder
 *     derselbe Versorger in anderer Schreibweise (Proposals::supplierMatch,
 *     eine Regel für den ganzen Posteingang). Ein Lieferverhältnis trägt einen
 *     Vertrag oder eine Kette (Tarifwechsel beim selben Versorger).
 *   - Messstelle aus Zählernummer und Marktlokation. Nummern derselben
 *     Messstelle (gleiche Marktlokation, Zählerwechsel-Brief, lückenlose
 *     Folgerechnung mit anderer Nummer) sind ein Gerätewechsel am selben
 *     Zähler. Der leere Standardzähler des Erststarts wird übernommen.
 *   - Vertragsbeginn aus der Bestätigung, sonst aus dem Lieferbeginn-Brief,
 *     sonst aus der ersten Rechnungsperiode; Ende aus Kündigung,
 *     Schlussrechnung oder Folgevertrag. Der Abschlag ist kein Pflichtfeld.
 *   - Preiskette: Bestätigung → Preisänderungen → Rechnungspreise. Ein
 *     Rechnungspreis, der zur Kette passt, bestätigt sie; ein abweichender ist
 *     eine Frage.
 *   - Abgleich: dieselbe Angabe in zwei Dokumenten gleich → bestätigt,
 *     verschieden → eine Entscheidung mit beiden Belegstellen; Endstand der
 *     einen Rechnung = Anfangsstand der nächsten; Lücken und Überschneidungen
 *     zwischen Verträgen werden gemeldet, nicht still korrigiert; ein fallender
 *     Stand ohne Gerätewechsel wird ein vorgeschlagener Gerätewechsel.
 *   - Dokumente mit demselben Inhalt zählen einmal („wohl doppelt“, wie in
 *     der Liste: InboxService::olderDuplicateOf), die Datei bleibt im
 *     Posteingang.
 *
 * Ein Eintrag (`item`) ist eine Feldgruppe mit einem Haken: Zähler,
 * Gerätewechsel, Vertrag, Preise eines Vertrags, Abschläge, Ablesungen eines
 * Zählers, Rechnung, Gas-Umrechnung, Termin. Jedes Feld trägt seine Belege
 * (Dokument, Seite, Zitat), `check` (zweifelhaft), `status` gegen den Bestand
 * (new | exists | conflict) und bei Widersprüchen `options`. Zweifelhaft ist ein
 * Feld, wenn seine Belegstelle markiert ist — mit den Marken des Posteingangs
 * nach dem heutigen Bestand (Proposals::marks) — und kein zweites Dokument es
 * bestätigt; zwei Rechnungen mit demselben Preis bestätigen ihn nicht (netto,
 * brutto, Teilbetrag — derselbe Fehler zweimal). Was fehlt oder unsicher
 * gelesen ist (offene Punkte der Vorschläge), steht unter „Fehlt noch“.
 *
 * Kernfelder (`core`) entscheiden über den Haken: Ist eines zweifelhaft, ist
 * der Eintrag nicht vorbelegt, und wer ihn anhakt, bestätigt sie. Andere
 * zweifelhafte Felder übernimmt erst eine ausdrückliche Bestätigung
 * (DraftApplier).
 *
 * Der Entwurf ändert nichts. Bestand gilt wie beim einzelnen Dokument:
 * schon vorhanden, Widerspruch, Überschneidung.
 */
final class HouseholdDraft
{
    /** Reihenfolge der Übernahme und der Anzeige. */
    public const ORDER = ['meter', 'meter_change', 'contract', 'contract_end', 'prices', 'advances', 'readings', 'bill', 'gas_factor', 'reminder'];

    /** Rang der Belege: Bestätigung vor Lieferbeginn vor Preisänderung vor Rechnung. */
    private const PRIO = ['contract_confirmation' => 0, 'supply_start' => 1, 'price_change' => 2, 'advance_change' => 3,
        'cancellation' => 4, 'invoice' => 5, 'meter_change' => 6, 'reading_request' => 7];

    /**
     * Offene Punkte der Vorschläge (Proposals::build), die der Entwurf je
     * Vertrag meldet („Preise“, „Abschlag“, „Vertragsbeginn“) oder selbst
     * anlegt (der Vertrag) — sie stehen nicht noch einmal je Dokument da.
     */
    private const OPEN_PER_CONTRACT = ['contract', 'working_price', 'working_prices', 'base_price', 'advance', 'advance_new'];

    /** Ein Sprung im Stand bis so viele Tage vor einem Nummernwechsel ist derselbe Zählertausch. */
    private const SWAP_WINDOW_DAYS = 7;

    /** @var array<string,array<string,mixed>> */
    private array $docs = [];
    /** @var array<string,array<string,mixed>> alle Einträge des Posteingangs (Namen der Originale von Doppeln) */
    private array $entries = [];
    /** @var list<array<string,mixed>> */
    private array $items = [];
    /** @var list<array<string,mixed>> */
    private array $findings = [];
    /** @var list<array<string,mixed>> */
    private array $missing = [];
    /** @var array<string,array<string,mixed>> Messstellen */
    private array $mps = [];
    /** @var array<string,array<string,mixed>> Vertragskandidaten */
    private array $cands = [];
    private string $today = '';

    public function __construct(
        private MeterService $meters,
        private ContractService $contracts,
        private ReadingService $readings,
        private BillService $bills,
        private ReminderService $reminders,
        private SettingsService $settings,
        private I18nService $i18n,
        private Proposals $proposals,
    ) {}

    // ── Einstieg ─────────────────────────────────────────────────────────

    /**
     * Entwurf aus den Einträgen des Posteingangs (alle ausgelesenen, noch
     * nicht erledigten; mit `$only` eine Auswahl).
     *
     * @param list<array<string,mixed>> $entries InboxService::all(), je Eintrag `duplicate_of`
     *                                          wie in der Liste (InboxService::olderDuplicateOf)
     * @param list<string>|null         $only
     * @return array<string,mixed>
     */
    public function build(array $entries, ?array $only = null, ?string $today = null): array
    {
        $this->today = $today ?? date('Y-m-d');
        $this->items = $this->findings = $this->missing = $this->mps = $this->cands = [];
        $this->entries = [];
        foreach ($entries as $e) if (is_array($e) && isset($e['id'])) $this->entries[(string)$e['id']] = $e;
        $this->docs = $this->collect($entries, $only);
        $this->markDuplicates();
        $byU = [];
        foreach ($this->docs as $id => $d) {
            if ($d['dup'] !== null) continue;
            if ($d['utility'] === null) { $this->docs[$id]['note'] = 'no_utility'; continue; }
            $byU[$d['utility']][] = $id;
        }
        foreach (Utilities::keys() as $u) {
            if (isset($byU[$u])) $this->utility($u, $byU[$u]);
        }
        $this->openPoints();
        return $this->result();
    }

    // ── Dokumente ────────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function collect(array $entries, ?array $only): array
    {
        // nach Ablage; gleiche Sekunde: Reihenfolge im Posteingang (usort ist stabil)
        usort($entries, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        $out = [];
        foreach ($entries as $e) {
            if (!is_array($e) || !isset($e['id'])) continue;
            if ($only !== null && !in_array($e['id'], $only, true)) continue;
            $type = (string)($e['type'] ?? '');
            if (($e['status'] ?? '') !== 'read' || !InboxSchema::extracts($type) || !is_array($e['extraction']['fields'] ?? null)) continue;
            // v3.7.0 (treffsicher) — Marken nach dem heutigen Bestand, wie Liste und
            // Vorschau (gespeicherte Zuordnung, sonst die Vorbelegung); die offenen
            // Punkte der Vorschläge (fehlt / unsicher) für „Fehlt noch“
            $marked = is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($e);
            $e = $this->proposals->marks($e, $marked);
            $open = [];
            foreach ($this->proposals->build($e, $marked) as $p) {
                foreach ((array)($p['open'] ?? []) as $o) if (is_string($o['field'] ?? null)) $open[$o['field']] ??= ['field' => $o['field'], 'reason' => (string)($o['reason'] ?? 'missing')];
            }
            $str = static function (string $f) use ($e): string {
                $v = Proposals::value($e, $f);
                return is_string($v) ? trim($v) : (is_int($v) || is_float($v) ? (string)$v : '');
            };
            $as = is_array($e['assignment'] ?? null) ? $e['assignment'] : [];
            $u0 = Proposals::value($e, 'utility');
            $u = is_string($as['utility'] ?? null) && Utilities::exists($as['utility']) ? $as['utility']
                : (in_array($u0, InboxSchema::UTILITIES, true) ? $u0 : null);
            $meterId = is_string($as['meter_id'] ?? null) && $u !== null && $this->meters->get($u, $as['meter_id']) !== null ? $as['meter_id'] : null;
            $malo = preg_replace('/\D/', '', $str('market_location')) ?? '';
            $serials = $type === 'meter_change'
                ? array_values(array_filter([$str('old_meter_number'), $str('new_meter_number')], fn($s) => $s !== ''))
                : array_values(array_filter([$str('meter_number')], fn($s) => $s !== ''));
            // Rechnung mit Ständen je Zähler (`meter_readings`, wo die Auslesung sie hat): alle Nummern
            foreach (self::meterReadings($e) as $mr) {
                $s = trim((string)($mr['value']['meter_number'] ?? ''));
                if ($s !== '' && !in_array($s, $serials, true)) $serials[] = $s;
            }
            $out[(string)$e['id']] = [
                'id' => (string)$e['id'], 'name' => (string)($e['name'] ?? ''), 'type' => $type, 'att' => (string)($e['attachment_id'] ?? ''),
                'entry' => $e, 'utility' => $u, 'supplier' => $str('supplier'),
                'serial' => $type === 'meter_change' ? '' : $str('meter_number'), 'serials' => $serials,
                'malo' => MeterService::isValidMalo($malo) ? $malo : '',
                'contract_no' => $str('contract_number'), 'customer_no' => $str('customer_number'),
                'meter_id' => $meterId, 'contract_id' => is_string($as['contract_id'] ?? null) ? $as['contract_id'] : null,
                'date' => self::refDate($e), 'first' => self::firstDate($e), 'dup' => null, 'note' => null,
                'mp' => null, 'rel' => null, 'cand' => null, 'nodes' => [], 'open' => array_values($open), 'superseded' => null,
            ];
        }
        return $out;
    }

    /**
     * Stände je Zähler einer Rechnung (`meter_readings`: {meter_number,
     * reading_old, reading_old_date, reading_new, reading_new_date}, bei einem
     * Tausch beide Zähler) — nur, wo die Auslesung sie hat (Schema von
     * treffsicher ab Nachbesserung 4); sonst leer und es gelten die Felder der
     * Rechnung.
     *
     * @return array<int,array<string,mixed>> Einträge mit `value`, Zitat, Marke
     */
    private static function meterReadings(array $e): array
    {
        if (($e['type'] ?? '') !== 'invoice') return [];
        $items = $e['extraction']['fields']['meter_readings']['items'] ?? [];
        return is_array($items) ? array_filter($items, fn($it) => is_array($it['value'] ?? null)) : [];
    }

    /**
     * Stehen Einträge derselben Zählernummer mit sich überlappenden Zeiträumen
     * da (zwei Zählwerke)? Aufeinanderfolgende Zeiträume (der nächste beginnt
     * frühestens am Ende des vorigen) sind derselbe Zähler; ohne Datum lässt es
     * sich nicht trennen — dann gilt es als überlappend.
     *
     * @param array<int,array<string,mixed>> $mrs
     * @return string|null die Nummer mit zwei Zählwerken
     */
    private static function overlappingRegisters(array $mrs): ?string
    {
        $by = []; $label = [];
        foreach ($mrs as $mr) {
            $v = $mr['value'];
            $k = self::idKey((string)($v['meter_number'] ?? ''));
            $by[$k][] = [(string)($v['reading_old_date'] ?? ''), (string)($v['reading_new_date'] ?? '')];
            $label[$k] ??= trim((string)($v['meter_number'] ?? ''));
        }
        foreach ($by as $k => $spans) {
            if (count($spans) < 2) continue;
            foreach ($spans as [$a, $b]) if ($a === '' || $b === '') return $label[$k];
            usort($spans, fn($x, $y) => strcmp($x[0], $y[0]) ?: strcmp($x[1], $y[1]));
            for ($i = 1; $i < count($spans); $i++) if (strcmp($spans[$i][0], $spans[$i - 1][1]) < 0) return $label[$k];
        }
        return null;
    }

    /** Stichtag eines Dokuments (wie Proposals::referenceDate), sonst der Tag der Ablage. */
    private static function refDate(array $e): string
    {
        foreach (['period_to', 'valid_from', 'supply_end', 'supply_start', 'change_date', 'requested_by'] as $f) {
            $v = Proposals::value($e, $f);
            if (is_string($v) && Dates::isIsoDate($v)) return $v;
        }
        return substr((string)($e['created_at'] ?? ''), 0, 10) ?: date('Y-m-d');
    }

    /** Frühestes Datum eines Dokuments (Beginn eines Zeitraums, Ablesung, Wechsel). */
    private static function firstDate(array $e): string
    {
        $all = [];
        foreach (['period_from', 'reading_old_date', 'supply_start', 'change_date', 'valid_from', 'reading_new_date', 'period_to', 'supply_end'] as $f) {
            $v = Proposals::value($e, $f);
            if (is_string($v) && Dates::isIsoDate($v)) $all[] = $v;
        }
        return $all !== [] ? min($all) : self::refDate($e);
    }

    /** Wert eines Felds (Korrektur vor Auslesung). */
    private function val(string $docId, string $field): mixed
    {
        return Proposals::value($this->docs[$docId]['entry'], $field);
    }

    private function date(string $docId, string $field): ?string
    {
        $v = $this->val($docId, $field);
        return is_string($v) && Dates::isIsoDate($v) ? $v : null;
    }

    private function num(string $docId, string $field): ?float
    {
        $v = $this->val($docId, $field);
        return is_int($v) || is_float($v) ? (float)$v : (is_string($v) && is_numeric($v) ? (float)$v : null);
    }

    // ── Dubletten nach Inhalt ────────────────────────────────────────────

    /**
     * v3.7.0 — Doppel nach Inhalt aus einer Quelle: `duplicate_of` wie in der
     * Liste und der Akte (InboxService::olderDuplicateOf, gleiche Art und
     * Kernfelder, das ältere zählt). Ein Doppel trägt nichts bei; ist sein
     * Original nicht im Entwurf (schon übernommen), steht es trotzdem als Doppel da.
     */
    private function markDuplicates(): void
    {
        foreach ($this->docs as $id => $d) {
            $of = $d['entry']['duplicate_of'] ?? null;
            if (!is_string($of) || $of === '' || $of === $id || !isset($this->entries[$of])) continue;
            $this->docs[$id]['dup'] = $of;
            $this->findings[] = ['kind' => 'duplicate', 'utility' => $d['utility'], 'docs' => array_values(array_filter([$id, isset($this->docs[$of]) ? $of : null])),
                'params' => ['name' => $d['name'], 'of' => (string)($this->entries[$of]['name'] ?? $of)]];
        }
    }

    // ── Versorger und Kennungen ──────────────────────────────────────────

    /**
     * Derselbe Versorger in anderer Schreibweise — eine Regel für den ganzen
     * Posteingang (Proposals::supplierMatch: Rechtsform, Umlaute, Satzzeichen
     * zählen nicht; allgemeine Wörter wie „Stadtwerke“ allein passen nie).
     */
    private static function sameSupplier(string $a, string $b): bool
    {
        return Proposals::supplierMatch($a, $b);
    }

    /** Name des Versorgers an einem Vertrag im Bestand (sonst der Tarifname, wie Proposals). */
    private static function providerOf(array $c): string
    {
        return (string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? ''));
    }

    /** Zählernummer als Schlüssel (wie Proposals::sameId). */
    private static function idKey(string $s): string
    {
        return ltrim(strtoupper(preg_replace('/[\s.\-\/_]/', '', $s) ?? ''), '0');
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[\s.,\-\/_"\'„“”]/u', '', mb_strtolower(trim($s), 'UTF-8')) ?? $s;
    }

    private static function find(array &$p, string $x): string
    {
        while ($p[$x] !== $x) { $p[$x] = $p[$p[$x]]; $x = $p[$x]; }
        return $x;
    }

    private static function union(array &$p, string $a, string $b): void
    {
        $ra = self::find($p, $a); $rb = self::find($p, $b);
        if ($ra !== $rb) $p[$ra] = $rb;
    }

    // ── je Verbrauchsart ─────────────────────────────────────────────────

    /** @param list<string> $ids */
    private function utility(string $u, array $ids): void
    {
        $rels = $this->relationships($u, $ids);
        $this->measuringPoints($u, $ids, $rels);
        $this->markCorrections($u, $ids);
        $this->timelines($u);
        $this->contractCandidates($u, $rels);
        $this->contractItems($u);
        $this->chainItems($u);
        $this->billItems($u, $ids);
        if ($u === 'gas') $this->gasFactorItems($ids);
        $this->reminderItems($u, $ids);
        $this->pruneEmptyMeters($u);
        $this->holdPending($u);
    }

    /**
     * Kein leerer Zähler: Ein neuer (oder übernommener Standard-)Zähler ohne
     * Nummer und Marktlokation, an dem nichts hängt — keine Ablesung, kein
     * Wechsel, kein Vertrag, keine Rechnung —, entsteht nicht. Solange etwa
     * einer Bestätigung der Beginn fehlt, steht sie unter „Fehlt noch“. Wird
     * dabei der übernommene Standardzähler frei, übernimmt ihn der früheste
     * andere neue Zähler.
     */
    private function pruneEmptyMeters(string $u): void
    {
        $used = [];
        foreach ($this->items as $it) if ($it['utility'] === $u && $it['kind'] !== 'meter' && !empty($it['meter_key'])) $used[$it['meter_key']] = true;
        foreach ($this->cands as $c) if ($c['utility'] === $u) $used[$c['mp']] = true;
        $freed = null;
        foreach ($this->items as $i => $it) {
            if ($it['utility'] !== $u || $it['kind'] !== 'meter') continue;
            $mp = $this->mps[$it['meter_key']];
            // angelegt (oder als Standardzähler übernommen) wird nur, woran ein Eintrag hängt —
            // auch mit Nummer: eine verstrichene Zählerstandsanforderung allein hält keinen Zähler
            if (isset($used[$mp['key']])) continue;
            // ein vorhandener Zähler, an dem der Entwurf nichts einträgt, erscheint nicht (ändert nichts)
            if ($it['action'] === 'existing' && array_filter($it['fields'], fn($f) => ($f['status'] ?? 'new') !== 'exists') !== []) continue;
            if ($it['action'] === 'adopt') $freed = $mp['meter_id'];
            unset($this->items[$i]);
            unset($this->mps[$mp['key']]);
            foreach ($this->docs as $id => $d) if ($d['mp'] === $mp['key']) $this->docs[$id]['mp'] = null;
        }
        $this->items = array_values($this->items);
        if ($freed === null) return;
        $next = null;
        foreach ($this->items as $i => $it) {
            if ($it['utility'] !== $u || $it['kind'] !== 'meter' || $it['action'] !== 'create') continue;
            if ($next === null || $this->mps[$it['meter_key']]['first'] < $this->mps[$this->items[$next]['meter_key']]['first']) $next = $i;
        }
        if ($next === null) return;
        $key = $this->items[$next]['meter_key'];
        $this->mps[$key]['meter_id'] = $freed;
        $this->mps[$key]['action'] = 'adopt';
        $this->items[$next]['action'] = 'adopt';
        $this->items[$next]['meter_id'] = $freed;
        $this->items[$next]['label_key'] = 'inboxDraft.item.meter.adopt';
        $this->items[$next]['params']['name'] = (string)($this->meters->get($u, $freed)['name'] ?? $this->items[$next]['params']['name']);
        $this->items[$next]['fields'] = array_values(array_filter($this->items[$next]['fields'], fn($f) => $f['name'] !== 'name'));
    }

    /**
     * Lieferverhältnisse: Dokumente mit gleicher Vertrags- oder Kundennummer
     * oder demselben Versorger (Zählerwechsel-Briefe gehören zur Messstelle).
     *
     * @param list<string> $ids
     * @return array<string,list<string>> Schlüssel → Dokumente
     */
    private function relationships(string $u, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn($id) => $this->docs[$id]['type'] !== 'meter_change'));
        $p = [];
        foreach ($ids as $id) $p[$id] = $id;
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->linked($this->docs[$ids[$i]], $this->docs[$ids[$j]])) self::union($p, $ids[$i], $ids[$j]);
            }
        }
        $groups = [];
        foreach ($ids as $id) $groups[self::find($p, $id)][] = $id;
        $out = [];
        $k = 0;
        foreach ($groups as $g) {
            $key = "$u:r" . $k++;
            $out[$key] = $g;
            foreach ($g as $id) $this->docs[$id]['rel'] = $key;
        }
        return $out;
    }

    private function linked(array $a, array $b): bool
    {
        if ($a['contract_no'] !== '' && $b['contract_no'] !== '' && Proposals::sameId($a['contract_no'], $b['contract_no'])) return true;
        if ($a['customer_no'] !== '' && $b['customer_no'] !== '' && Proposals::sameId($a['customer_no'], $b['customer_no'])) return true;
        if ($a['contract_id'] !== null && $a['contract_id'] === $b['contract_id']) return true;
        // Marke und Versorger: nennt ein Dokument beide („Musterstrom, eine Marke der Muster Energie
        // GmbH“), passt es zu beiden Namen (supplierMatch: alle Wörter des kürzeren im längeren) und
        // verbindet so Dokumente unter der Marke mit denen des Versorgers (über die Kette)
        return self::sameSupplier($a['supplier'], $b['supplier']);
    }

    // ── Messstellen ──────────────────────────────────────────────────────

    /**
     * Messstellen aus Zählernummern und Marktlokation, verbunden über den
     * Bestand, über Dokumente mit beiden Angaben, über Zählerwechsel-Briefe und
     * über lückenlose Folgerechnungen mit anderer Nummer.
     *
     * @param list<string> $ids
     * @param array<string,list<string>> $rels
     */
    private function measuringPoints(string $u, array $ids, array $rels): void
    {
        $p = []; $label = []; $meterOf = [];
        $add = function (string $node) use (&$p): void { if (!isset($p[$node])) $p[$node] = $node; };
        foreach ($this->meters->list($u) as $m) {
            $nodes = [];
            foreach ($m['devices'] ?? [] as $dv) {
                $s = trim((string)($dv['serial'] ?? ''));
                if ($s !== '' && self::idKey($s) !== '') $nodes[] = 's:' . self::idKey($s);
            }
            if (!empty($m['malo_id'])) $nodes[] = 'm:' . $m['malo_id'];
            foreach ($nodes as $nd) { $add($nd); $meterOf[$nd] ??= (string)$m['id']; }
            for ($k = 1; $k < count($nodes); $k++) self::union($p, $nodes[0], $nodes[$k]);
        }
        foreach ($ids as $id) {
            $d = $this->docs[$id];
            $nodes = [];
            foreach ($d['serials'] as $s) {
                if (self::idKey($s) === '') continue;
                $nd = 's:' . self::idKey($s);
                $nodes[] = $nd;
                $label[$nd] ??= $s;
            }
            if ($d['malo'] !== '') $nodes[] = 'm:' . $d['malo'];
            foreach ($nodes as $nd) $add($nd);
            for ($k = 1; $k < count($nodes); $k++) self::union($p, $nodes[0], $nodes[$k]);
            $this->docs[$id]['nodes'] = $nodes;
        }
        // lückenlose Folgerechnung mit anderer Zählernummer: derselbe Zählpunkt, neues Gerät
        foreach ($rels as $rel) {
            $inv = [];
            foreach ($rel as $id) {
                $d = $this->docs[$id];
                $from = $this->date($id, 'period_from'); $to = $this->date($id, 'period_to');
                if ($d['type'] !== 'invoice' || $d['serial'] === '' || $from === null || $to === null || self::idKey($d['serial']) === '') continue;
                $inv[] = ['id' => $id, 'node' => 's:' . self::idKey($d['serial']), 'from' => $from, 'to' => $to];
            }
            usort($inv, fn($a, $b) => strcmp($a['from'], $b['from']));
            $span = [];
            foreach ($inv as $x) {
                $span[$x['node']] = ['from' => min($span[$x['node']]['from'] ?? $x['from'], $x['from']), 'to' => max($span[$x['node']]['to'] ?? $x['to'], $x['to'])];
            }
            for ($i = 1; $i < count($inv); $i++) {
                [$a, $b] = [$inv[$i - 1], $inv[$i]];
                if ($a['node'] === $b['node'] || self::find($p, $a['node']) === self::find($p, $b['node'])) continue;
                if (!self::adjacent($a['to'], $b['from'])) continue;
                // die Nummern lösen einander ab, sie laufen nicht nebeneinander (zwei Zähler)
                if ($span[$a['node']]['to'] > $b['from'] || $span[$b['node']]['from'] < $a['to']) continue;
                self::union($p, $a['node'], $b['node']);
            }
        }
        // Messstellen = Komponenten mit mindestens einem Dokument
        $byRoot = [];
        foreach ($ids as $id) {
            $nodes = $this->docs[$id]['nodes'];
            if ($nodes === []) continue;
            $byRoot[self::find($p, $nodes[0])][] = $id;
        }
        foreach ($byRoot as $root => $docIds) {
            $nodes = array_values(array_filter(array_keys($p), fn($nd) => self::find($p, $nd) === $root));
            sort($nodes);
            $meterId = null;
            foreach ($nodes as $nd) if (isset($meterOf[$nd])) { $meterId = $meterOf[$nd]; break; }
            $mp = $this->newMp($u, implode(',', $nodes), $meterId);
            $mp['nodes'] = $nodes;
            $mp['labels'] = array_intersect_key($label, array_flip($nodes));
            foreach ($docIds as $id) $this->assignMp($id, $mp['key'], $mp);
            $this->mps[$mp['key']] = $mp;
        }
        $this->adoptExistingMeters($u);
        $this->assignWithoutNumber($u, $ids);
        $this->decideTargets($u);
    }

    /**
     * 3.7.1 — Messstellen, deren Nummern und Marktlokation im Bestand nicht
     * stehen, an einem Haushalt, der seine Zähler ohne Nummer führt: Statt
     * eines zweiten Zählers (samt Verträgen, Rechnungen und Ablesungen doppelt)
     *   1. gilt eine Zuordnung aus dem Posteingang (Vorschau: Zähler gewählt) —
     *      hat diesen Zähler schon eine Messstelle, wird es eine (Tauschregeln);
     *   2. übernimmt der Entwurf den einen aktiven Hauptzähler mit Daten, der
     *      passt (keine andere Nummer am laufenden Gerät, keine andere
     *      Marktlokation) und den keine andere Messstelle hat — Nummer und
     *      Marktlokation trägt er nach (Ergänzung, zu bestätigen);
     *   3. kommen mehrere in Frage, hat ihn schon eine andere Messstelle oder
     *      passt er nur halb: kein neuer Zähler, eine Frage (`meter_choice`,
     *      holdPending) — beantwortet, sobald ein Dokument im Posteingang einem
     *      Zähler zugeordnet ist.
     * Passt kein Zähler mit Daten (eigene Nummer und andere Marktlokation),
     * entsteht er neu wie bisher.
     */
    private function adoptExistingMeters(string $u): void
    {
        $open = array_values(array_filter(array_keys($this->mps), fn($k) => $this->mps[$k]['utility'] === $u && $this->mps[$k]['meter_id'] === null));
        if ($open === []) return;
        foreach ($open as $i => $key) {
            $chosen = array_values(array_unique(array_filter(array_map(fn($id) => $this->docs[$id]['meter_id'], $this->mps[$key]['docs']))));
            if (count($chosen) !== 1) continue;
            $target = null;
            foreach ($this->mps as $k => $m) if ($k !== $key && $m['utility'] === $u && $m['meter_id'] === $chosen[0]) $target = $k;
            if ($target !== null) $this->mergeMp($key, $target);
            else $this->mps[$key] = ['meter_id' => $chosen[0], 'action' => 'existing'] + $this->mps[$key];
            unset($open[$i]);
        }
        if ($open === []) return;
        $data = array_values(array_filter($this->meters->list($u), fn($m) => MeterService::inService($m) && empty($m['parent_meter_id']) && $this->hasData($u, $m)));
        if ($data === []) return;
        $claimed = array_values(array_filter(array_map(fn($m) => $m['utility'] === $u ? $m['meter_id'] : null, $this->mps)));
        $fits = [];
        foreach ($open as $key) foreach ($data as $m) {
            $fit = $this->meterFit($this->mps[$key], $m);
            if ($fit !== null) $fits[$key][(string)$m['id']] = $fit;
        }
        foreach ($open as $key) {
            $c = $fits[$key] ?? [];
            if ($c === []) continue;   // jeder Zähler mit Daten ist ein anderer: neu wie bisher
            $mid = (string)array_key_first($c);
            $rivals = array_filter($open, fn($k) => $k !== $key && isset($fits[$k][$mid]));
            if (count($c) === 1 && $c[$mid] === 'fits' && !in_array($mid, $claimed, true) && $rivals === []) {
                $this->mps[$key]['meter_id'] = $mid;
                $this->mps[$key]['action'] = 'existing';
                $claimed[] = $mid;
                continue;
            }
            $this->mps[$key]['action'] = 'pending';
            $this->mps[$key]['candidates'] = array_map('strval', array_keys($c));
        }
    }

    /**
     * Passt ein Zähler aus dem Bestand zu einer Messstelle, deren Kennungen er
     * nicht trägt? `fits`: nichts widerspricht (keine Nummer am laufenden
     * Gerät, Marktlokation leer oder nicht genannt); `maybe`: eine andere Nummer
     * am laufenden Gerät (vielleicht ein Tausch) oder nur eine andere
     * Marktlokation; null: eigene Nummer und andere Marktlokation — ein anderer
     * Zähler.
     */
    private function meterFit(array $mp, array $m): ?string
    {
        $malo = (string)($m['malo_id'] ?? '');
        $docMalo = array_values(array_filter($mp['nodes'], fn($n) => str_starts_with($n, 'm:')));
        $docSerial = array_values(array_filter($mp['nodes'], fn($n) => str_starts_with($n, 's:')));
        $otherMalo = $malo !== '' && $docMalo !== [] && !in_array("m:$malo", $docMalo, true);
        $open = null;
        foreach ($m['devices'] ?? [] as $dv) if (empty($dv['removed_on'])) $open = $dv;
        $otherSerial = trim((string)($open['serial'] ?? '')) !== '';
        if ($otherMalo && $docSerial !== []) return null;
        return $otherMalo || $otherSerial ? 'maybe' : 'fits';
    }

    /** Hat der Zähler Daten (Ablesungen, Verträge auch seiner Gruppe, Rechnungen)? */
    private function hasData(string $u, array $m): bool
    {
        $id = (string)$m['id'];
        return (Utilities::isCumulative($u) && $this->readings->list($u, $id) !== [])
            || (Utilities::hasContracts($u) && $this->existingContracts($u, $m) !== [])
            || (Utilities::supportsBillCheck($u) && $this->bills->list($u, $id) !== []);
    }

    /** Verträge eines Zählers im Bestand, auch die seiner Gruppe (ohne Schattenverträge). */
    private function existingContracts(string $u, array $meter): array
    {
        $list = $this->contracts->list($u, (string)$meter['id']);
        $gid = (string)($meter['meter_group_id'] ?? '');
        if ($gid !== '') $list = [...$list, ...$this->contracts->list($u, $gid)];
        return array_values(array_filter($list, fn($c) => empty($c['is_shadow'])));
    }

    /** Zwei Messstellen sind eine (Dokumente, Nummern, Beschriftungen). */
    private function mergeMp(string $from, string $into): void
    {
        foreach ($this->mps[$from]['docs'] as $id) $this->assignMp($id, $into);
        $this->mps[$into]['nodes'] = array_values(array_unique([...$this->mps[$into]['nodes'], ...$this->mps[$from]['nodes']]));
        $this->mps[$into]['labels'] += $this->mps[$from]['labels'];
        unset($this->mps[$from]);
    }

    /**
     * Messstellen mit offener Zuordnung (adoptExistingMeters): Ihre Einträge
     * entstehen nicht — kein Zähler, keine Verträge, Ablesungen, Rechnungen
     * daran —, ihre Dokumente bleiben offen; „Fehlt noch“ nennt die Zähler, die
     * in Frage kommen, und führt zum Dokument, wo man den Zähler wählt.
     */
    private function holdPending(string $u): void
    {
        $pending = array_values(array_filter(array_keys($this->mps), fn($k) => $this->mps[$k]['utility'] === $u && $this->mps[$k]['action'] === 'pending'));
        if ($pending === []) return;
        $cands = array_values(array_filter(array_keys($this->cands), fn($k) => in_array($this->cands[$k]['mp'], $pending, true)));
        $this->items = array_values(array_filter($this->items, fn($it) => !in_array($it['meter_key'] ?? null, $pending, true)));
        $this->missing = array_values(array_filter($this->missing, fn($m) => !in_array($m['key'] ?? null, $cands, true)));
        foreach ($cands as $k) unset($this->cands[$k]);
        foreach ($pending as $key) {
            $mp = $this->mps[$key];
            $docs = array_values(array_filter(array_keys($this->docs), fn($id) => $this->docs[$id]['mp'] === $key || in_array($this->docs[$id]['cand'], $cands, true)));
            foreach ($docs as $id) $this->docs[$id]['note'] = 'meter_choice';
            $label = array_values($mp['labels'])[0] ?? (string)substr((string)(array_values(array_filter($mp['nodes'], fn($n) => str_starts_with($n, 'm:')))[0] ?? ''), 2);
            $names = array_map(fn($id) => (string)($this->meters->get($u, $id)['name'] ?? $id), $mp['candidates'] ?? []);
            $this->missing[] = ['kind' => 'meter_choice', 'utility' => $u, 'key' => "choice:$key", 'docs' => $docs,
                'params' => ['meter' => $label, 'count' => count($docs), 'meters' => implode(', ', $names)],
                'href' => '#/inbox/' . rawurlencode($mp['docs'][0] ?? '')];
        }
    }

    /**
     * Rechnungskorrektur: zwei Rechnungen derselben Messstelle für denselben
     * Zeitraum, eine als Korrektur gekennzeichnet („Korrektur“, „korrigiert“,
     * „berichtigt“ im Namen oder in einer Belegstelle) oder mit späterem Datum
     * in ihren Belegstellen. Die spätere Fassung gilt (ihre Werte, vorgewählt);
     * die frühere ist nur Beleg — sie bringt keine Ablesungen, Preise oder
     * Abschläge. Ohne Kennzeichen und Datum bleibt es eine Entscheidung.
     *
     * @param list<string> $ids
     */
    private function markCorrections(string $u, array $ids): void
    {
        $groups = [];
        foreach ($ids as $id) {
            $d = $this->docs[$id];
            $from = $this->date($id, 'period_from'); $to = $this->date($id, 'period_to');
            if ($d['type'] !== 'invoice' || $d['mp'] === null || $from === null || $to === null) continue;
            $groups[$d['mp'] . "|$from|$to"][] = $id;
        }
        foreach ($groups as $list) {
            if (count($list) < 2) continue;
            // 1. ein späteres Ausstellungsdatum (`document_date`, wo die Auslesung es hat)
            $dated = array_filter(array_combine($list, array_map(fn($id) => (string)($this->date($id, 'document_date') ?? ''), $list)), fn($x) => $x !== '');
            $current = null;
            if (count($dated) >= 2) {
                $max = max($dated);
                $top = array_keys(array_filter($dated, fn($x) => $x === $max));
                if (count($top) === 1) $current = (string)$top[0];
            }
            // 2. als Korrektur gekennzeichnet (Dateiname, Titel, Belegstelle)
            $marked = array_values(array_filter($list, fn($id) => $this->correctionMark($id)));
            if ($current === null && count($marked) === 1) $current = $marked[0];
            // 3. das spätere Datum nach dem Zeitraum in den Belegstellen
            if ($current === null && $marked === []) {
                $latest = array_map(fn($id) => $this->latestQuotedDate($id), $list);
                $max = max($latest);
                $top = array_keys(array_filter($latest, fn($x) => $x === $max));
                if ($max !== '' && count($top) === 1) $current = $list[$top[0]];
            }
            if ($current === null) continue;
            $older = array_values(array_diff($list, [$current]));
            foreach ($older as $o) $this->docs[$o]['superseded'] = $current;
            $this->findings[] = ['kind' => 'bill_corrected', 'utility' => $u, 'docs' => [$current, ...$older],
                'params' => ['name' => $this->docs[$current]['name'], 'of' => implode(', ', array_map(fn($x) => $this->docs[$x]['name'], $older))]];
        }
    }

    /**
     * Als Korrektur gekennzeichnet? „korr“ im Dateinamen; „Korrektur“,
     * „korrigiert“, „berichtigt“ im Titel (`document_title`, wo die Auslesung
     * ihn hat) oder in einer Belegstelle.
     */
    private function correctionMark(string $id): bool
    {
        if (preg_match('/korr/iu', $this->docs[$id]['name'])) return true;
        $text = (string)($this->val($id, 'document_title') ?? '');
        foreach ((array)($this->docs[$id]['entry']['extraction']['fields'] ?? []) as $name => $f) {
            if ($name !== 'document_title' && is_string($f['quote'] ?? null)) $text .= ' ' . $f['quote'];
        }
        return (bool)preg_match('/korrektur|korrigiert|berichtig/iu', $text);
    }

    /**
     * Felder, deren Daten nichts über das Ausstellungsdatum sagen (Pläne, Preise,
     * Stände) — und `document_date`, das markCorrections vorher selbst liest.
     */
    private const PLAN_DATE_FIELDS = ['document_date', 'meter_readings', 'advance_new_from', 'advance_new', 'advances_paid', 'working_prices', 'gas_factors',
        'reading_old', 'reading_old_date', 'reading_new', 'reading_new_date', 'period_from', 'period_to'];

    /**
     * Das späteste Datum nach dem Zeitraum in den übrigen Belegstellen einer
     * Rechnung (etwa „Rechnungsdatum …“ am Betrag), leer ohne.
     */
    private function latestQuotedDate(string $id): string
    {
        $max = '';
        $to = (string)$this->date($id, 'period_to');
        $defs = InboxSchema::fields($this->docs[$id]['type']);
        foreach ((array)($this->docs[$id]['entry']['extraction']['fields'] ?? []) as $name => $f) {
            // Hilfsfelder (`aux`: netto/brutto, Steuersatz) sagen nichts über das Datum
            if (in_array($name, self::PLAN_DATE_FIELDS, true) || !empty($defs[$name]['aux']) || !is_string($f['quote'] ?? null)) continue;
            foreach (Evidence::datesIn($f['quote']) as $dt) if ($dt > $to && $dt > $max) $max = $dt;
        }
        return $max;
    }

    /** @return array<string,mixed> */
    private function newMp(string $u, string $seed, ?string $meterId): array
    {
        return ['key' => 'mp_' . substr(sha1("$u|$seed"), 0, 10), 'utility' => $u, 'nodes' => [], 'labels' => [],
            'meter_id' => $meterId, 'action' => $meterId !== null ? 'existing' : 'create', 'docs' => [], 'first' => '9999-12-31',
            'devices' => [], 'changes' => [], 'rows' => []];
    }

    private function assignMp(string $docId, string $key, ?array &$mp = null): void
    {
        $this->docs[$docId]['mp'] = $key;
        if ($mp !== null) {
            $mp['docs'][] = $docId;
            $mp['first'] = min($mp['first'], $this->docs[$docId]['first']);
        } else {
            $this->mps[$key]['docs'][] = $docId;
            $this->mps[$key]['first'] = min($this->mps[$key]['first'], $this->docs[$docId]['first']);
        }
    }

    /**
     * Dokumente ohne Zählernummer und Marktlokation: über die eigene Zuordnung,
     * über das Lieferverhältnis, sonst über die einzige Messstelle der Art.
     * Gibt es gar keine Nummer, steht ein Zähler ohne Nummer für die Art.
     *
     * @param list<string> $ids
     */
    private function assignWithoutNumber(string $u, array $ids): void
    {
        $open = array_values(array_filter($ids, fn($id) => $this->docs[$id]['mp'] === null));
        if ($open === []) return;
        foreach ($open as $id) {
            $d = $this->docs[$id];
            if ($d['meter_id'] !== null) {
                $key = $this->mpForMeter($u, $d['meter_id']);
                $this->assignMp($id, $key);
                continue;
            }
            // gleiche Vertrags- oder Kundennummer zuerst, dann das Lieferverhältnis
            $cand = [];
            foreach ($ids as $other) {
                $o = $this->docs[$other];
                if ($other === $id || $o['mp'] === null) continue;
                if (($d['contract_no'] !== '' && $o['contract_no'] !== '' && Proposals::sameId($d['contract_no'], $o['contract_no']))
                    || ($d['customer_no'] !== '' && $o['customer_no'] !== '' && Proposals::sameId($d['customer_no'], $o['customer_no']))) $cand[$o['mp']][] = $other;
            }
            if (count($cand) === 1) { $this->assignMp($id, (string)array_key_first($cand)); continue; }
            $cand = [];
            foreach ($ids as $other) {
                if ($other === $id || $this->docs[$other]['rel'] === null || $this->docs[$other]['rel'] !== $d['rel'] || $this->docs[$other]['mp'] === null) continue;
                $cand[$this->docs[$other]['mp']][] = $other;
            }
            if (count($cand) > 1) {
                // die Messstelle, deren Dokumente den Stichtag umschließen
                $hit = [];
                foreach ($cand as $key => $others) {
                    $dates = array_map(fn($o) => $this->docs[$o]['first'], $others);
                    $last = array_map(fn($o) => $this->docs[$o]['date'], $others);
                    if (min($dates) <= $d['date'] && $d['date'] <= max($last)) $hit[] = $key;
                }
                $cand = count($hit) === 1 ? [$hit[0] => true] : $cand;
            }
            if (count($cand) === 1) { $this->assignMp($id, (string)array_key_first($cand)); continue; }
        }
        $still = array_values(array_filter($ids, fn($id) => $this->docs[$id]['mp'] === null));
        if ($still === []) return;
        $ofU = array_values(array_filter($this->mps, fn($m) => $m['utility'] === $u));
        // die einzige Messstelle der Art — oder die einzige mit Daten (Rechnung, Bestätigung,
        // Lieferbeginn, Zählerwechsel; eine Zählerstandsanforderung allein bringt keine)
        $withData = array_values(array_filter($ofU, fn($m) => array_filter($m['docs'],
            fn($o) => in_array($this->docs[$o]['type'], ['invoice', 'contract_confirmation', 'supply_start', 'meter_change'], true)) !== []));
        if (count($ofU) === 1 || count($withData) === 1) {
            $key = count($ofU) === 1 ? $ofU[0]['key'] : $withData[0]['key'];
            foreach ($still as $id) $this->assignMp($id, $key);
            return;
        }
        if ($ofU === []) {
            // keine Nummer in keinem Dokument: der einzige Zähler der Art, sonst ein neuer ohne Nummer
            $active = array_values(array_filter($this->meters->list($u), fn($m) => MeterService::inService($m) && empty($m['parent_meter_id'])));
            $key = count($active) === 1 ? $this->mpForMeter($u, (string)$active[0]['id']) : null;
            if ($key === null) {
                $mp = $this->newMp($u, 'none', null);
                $this->mps[$mp['key']] = $mp;
                $key = $mp['key'];
            }
            foreach ($still as $id) $this->assignMp($id, $key);
            return;
        }
        // die eine Messstelle der Art, deren Dokumente den Stichtag umschließen (laufen zwei nebeneinander: keine)
        foreach ($still as $si => $id) {
            $d = $this->docs[$id];
            $hit = [];
            foreach ($ofU as $m) {
                if ($m['docs'] === []) continue;
                $first = min(array_map(fn($o) => $this->docs[$o]['first'], $m['docs']));
                $last = max(array_map(fn($o) => $this->docs[$o]['date'], $m['docs']));
                if ($first <= $d['date'] && $d['date'] <= $last) $hit[] = $m['key'];
            }
            if (count($hit) === 1) { $this->assignMp($id, $hit[0]); unset($still[$si]); }
        }
        foreach ($still as $id) {
            $this->docs[$id]['note'] = 'no_meter';
            $this->findings[] = ['kind' => 'no_meter', 'utility' => $u, 'docs' => [$id], 'params' => ['name' => $this->docs[$id]['name']]];
        }
    }

    /** Messstelle für einen vorhandenen Zähler (angelegt, wenn es sie noch nicht gibt). */
    private function mpForMeter(string $u, string $meterId): string
    {
        foreach ($this->mps as $key => $mp) if ($mp['utility'] === $u && $mp['meter_id'] === $meterId) return $key;
        $mp = $this->newMp($u, 'meter:' . $meterId, $meterId);
        $this->mps[$mp['key']] = $mp;
        return $mp['key'];
    }

    /**
     * Neu anlegen oder den leeren Standardzähler des Erststarts übernehmen
     * (ohne Nummer, ohne Ablesungen, Verträge, Rechnungen) — dann für die
     * früheste neue Messstelle, statt eines zweiten Zählers.
     */
    private function decideTargets(string $u): void
    {
        $all = $this->meters->list($u);
        $taken = array_filter(array_map(fn($m) => $m['meter_id'], $this->mps));
        $empty = count($all) === 1 && !in_array((string)$all[0]['id'], $taken, true) && $this->isEmptyDefault($u, $all[0]) ? (string)$all[0]['id'] : null;
        if ($empty === null) return;
        $new = array_values(array_filter($this->mps, fn($m) => $m['utility'] === $u && $m['meter_id'] === null && $m['action'] !== 'pending'));
        if ($new === []) return;
        usort($new, fn($a, $b) => strcmp($a['first'], $b['first']));
        $this->mps[$new[0]['key']]['meter_id'] = $empty;
        $this->mps[$new[0]['key']]['action'] = 'adopt';
    }

    private function isEmptyDefault(string $u, array $m): bool
    {
        foreach ($m['devices'] ?? [] as $dv) if (trim((string)($dv['serial'] ?? '')) !== '') return false;
        if (!empty($m['malo_id']) || ($m['capture'] ?? 'counter') === 'period' || count($m['devices'] ?? []) > 1) return false;
        $id = (string)$m['id'];
        if (Utilities::isCumulative($u) && $this->readings->list($u, $id) !== []) return false;
        if (Utilities::hasContracts($u) && $this->contracts->list($u, $id) !== []) return false;
        if (Utilities::supportsBillCheck($u) && $this->bills->list($u, $id) !== []) return false;
        return true;
    }

    // ── Geräte, Wechsel, Ablesungen ──────────────────────────────────────

    /**
     * Je Messstelle: Ablesungen aus den Dokumenten, Reihenfolge der Geräte,
     * Wechsel (aus dem Zählerwechsel-Brief, sonst aus den Ständen), fallende
     * Stände; daraus die Einträge Zähler, Gerätewechsel und Ablesungen.
     */
    private function timelines(string $u): void
    {
        foreach ($this->mps as $key => $mp) {
            if ($mp['utility'] !== $u || $mp['action'] === 'pending') continue;
            $obs = []; $changes = [];
            foreach ($mp['docs'] as $id) {
                $d = $this->docs[$id];
                if ($d['superseded'] !== null) continue;   // überholte Fassung einer Rechnung: nur Beleg
                $prio = self::PRIO[$d['type']] ?? 9;
                $sk = $d['serial'] !== '' ? 's:' . self::idKey($d['serial']) : '';
                // Stände je Zähler aus `meter_readings` (beide Zähler eines Tauschs) statt der Felder der Rechnung
                $mrs = self::meterReadings($d['entry']);
                // dieselbe Nummer mehrfach: aufeinanderfolgende Ablesezeiträume (gespeicherte Auslesungen mit
                // Schema 3, vor dem Zusammenfassen) sind ein Zähler — ihre Stände werden Ablesungen; überlappen
                // sich die Zeiträume (Zweitarifzähler, HT/NT): kein Tausch, keine Doppelablesung — die Stände je
                // Zählwerk trägt man von Hand ein (das Schema kennt kein Zählwerk)
                if (($twin = self::overlappingRegisters($mrs)) !== null) {
                    $this->missing[] = ['kind' => 'two_registers', 'utility' => $u, 'key' => "reg:$id", 'docs' => [$id],
                        'params' => ['name' => $d['name'], 'meter' => $twin],
                        'href' => "#/utility/$u/meters"];
                    continue;
                }
                foreach ($mrs as $i => $mr) {
                    $v = $mr['value'];
                    $s = trim((string)($v['meter_number'] ?? ''));
                    // Anfang, Zwischenstände (zusammengefasste Zeiträume, Evidence::mergeReadings: `between`), Ende;
                    // geschätzt je Stand, wo zusammengefasst (`estimated_ends`), sonst für beide Stände des Eintrags
                    $both = ($v['estimated'] ?? null) === true;
                    $ends = (array)($mr['estimated_ends'] ?? [$both, $both]);
                    $stands = [[$v['reading_old'] ?? null, $v['reading_old_date'] ?? null, !empty($ends[0])]];
                    foreach ((array)($mr['between'] ?? []) as $b) $stands[] = [$b['counter'] ?? null, $b['date'] ?? null, !empty($b['estimated'])];
                    $stands[] = [$v['reading_new'] ?? null, $v['reading_new_date'] ?? null, !empty($ends[1])];
                    foreach ($stands as [$raw, $rawDate, $est]) {
                        $val = is_numeric($raw) ? (float)$raw : null;
                        $dt = is_string($rawDate) && Dates::isIsoDate($rawDate) ? $rawDate : null;
                        if ($val === null || $dt === null || $val < 0) continue;
                        $obs[] = ['date' => $dt, 'value' => $val, 'serial' => $s !== '' && self::idKey($s) !== '' ? 's:' . self::idKey($s) : $sk, 'doc' => $id,
                            'o' => self::obs($val, [$this->src($id, 'meter_readings', (int)$i)], $prio) + ['is_estimated' => $est]];
                    }
                }
                if ($mrs !== []) continue;
                $pairs = match ($d['type']) {
                    'invoice' => [['reading_old', 'reading_old_date', 'reading_old_estimated'], ['reading_new', 'reading_new_date', 'reading_new_estimated']],
                    'supply_start' => [['reading_start', 'supply_start', null]],
                    default => [],
                };
                foreach ($pairs as [$vf, $df, $ef]) {
                    $v = $this->num($id, $vf); $dt = $this->date($id, $df);
                    if ($v === null || $dt === null || $v < 0) continue;
                    $obs[] = ['date' => $dt, 'value' => $v, 'serial' => $sk, 'doc' => $id,
                        'o' => self::obs($v, [$this->src($id, $vf), $this->src($id, $df)], $prio) + ['is_estimated' => $ef !== null && $this->val($id, $ef) === true]];
                }
                if ($d['type'] === 'meter_change') {
                    $dt = $this->date($id, 'change_date');
                    if ($dt === null) continue;
                    $old = trim((string)($this->val($id, 'old_meter_number') ?? ''));
                    $new = trim((string)($this->val($id, 'new_meter_number') ?? ''));
                    $changes[] = ['date' => $dt, 'doc' => $id, 'old' => $old !== '' ? 's:' . self::idKey($old) : '', 'new' => $new !== '' ? 's:' . self::idKey($new) : '',
                        'old_label' => $old, 'new_label' => $new, 'final' => $this->num($id, 'old_reading'), 'initial' => $this->num($id, 'new_reading')];
                }
            }
            usort($obs, fn($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['o']['prio'] <=> $b['o']['prio']));
            $this->mps[$key] = $this->deviceChain($this->mps[$key], $obs, $changes);
            $this->meterItem($this->mps[$key]);
            $this->changeItems($this->mps[$key]);
            $this->readingItem($this->mps[$key]);
        }
    }

    /**
     * Reihenfolge der Geräte und ihre Wechsel. Ein Gerät = eine Zählernummer;
     * Ablesungen ohne Nummer gehören dem Gerät, das am Tag eingebaut ist.
     *
     * @param list<array<string,mixed>> $obs
     * @param list<array<string,mixed>> $changes
     */
    private function deviceChain(array $mp, array $obs, array $changes): array
    {
        $span = [];
        $touch = function (string $sk, string $date) use (&$span): void {
            if ($sk === '') return;
            $span[$sk] = ['first' => min($span[$sk]['first'] ?? $date, $date), 'last' => max($span[$sk]['last'] ?? $date, $date)];
        };
        foreach ($obs as $o) $touch($o['serial'], $o['date']);
        foreach ($changes as $c) { $touch($c['old'], $c['date']); $touch($c['new'], $c['date']); }
        uasort($span, fn($a, $b) => strcmp($a['first'], $b['first']) ?: strcmp($a['last'], $b['last']));
        $serials = array_keys($span);
        $labels = $mp['labels'];
        foreach ($changes as $c) {
            if ($c['old'] !== '') $labels[$c['old']] ??= $c['old_label'];
            if ($c['new'] !== '') $labels[$c['new']] ??= $c['new_label'];
        }
        $mp['labels'] = $labels;
        $mp['devices'] = array_map(fn($s) => ['serial' => $s, 'label' => $labels[$s] ?? substr($s, 2)] + $span[$s], $serials);

        // Wechsel zwischen aufeinanderfolgenden Nummern
        $trans = [];
        $used = [];
        for ($i = 1; $i < count($serials); $i++) {
            [$a, $b] = [$serials[$i - 1], $serials[$i]];
            $doc = null;
            foreach ($changes as $ci => $c) {
                if (($c['new'] === $b && ($c['old'] === $a || $c['old'] === '')) || ($c['old'] === $a && $c['new'] === '')) { $doc = $c; $used[$ci] = true; break; }
            }
            $trans[] = $this->transition($obs, $a, $b, $doc, $labels[$b] ?? substr($b, 2));
        }
        // Wechsel-Briefe ohne neue Nummer (oder ohne bekannte Nummern): nach Datum
        foreach ($changes as $ci => $c) {
            if (isset($used[$ci])) continue;
            $known = false;
            foreach ($trans as $t) if ($t !== null && $t['date'] === $c['date']) $known = true;
            if ($known) continue;
            $trans[] = $this->transition($obs, $c['old'], $c['new'], $c, $c['new_label']);
        }
        $trans = array_values(array_filter($trans));
        usort($trans, fn($x, $y) => strcmp($x['date'], $y['date']));

        // Ablesungen je Tag; Stände des alten Geräts ab dem Wechseltag sind sein Endstand
        $deviceAt = function (string $date, string $serial) use ($trans): int {
            $n = 0;
            foreach ($trans as $t) if ($date >= $t['date']) $n++;
            return $n;
        };
        $rows = [];
        foreach ($obs as $o) {
            $dev = $deviceAt($o['date'], $o['serial']);
            if ($o['serial'] !== '') {
                // die Nummer verrät das Gerät: ein Stand des alten Geräts am Wechseltag ist dessen Endstand
                $own = array_search($o['serial'], $serials, true);
                if ($own !== false && $dev > 0 && $trans !== [] && $own < $dev && ($trans[$dev - 1]['date'] ?? '') === $o['date']) continue;
            }
            $rows[$o['date']][] = $o + ['dev' => $dev];
        }
        ksort($rows);
        // Endstand = Anfangsstand am Folgetag: eine Ablesung
        $dates = array_keys($rows);
        for ($i = 1; $i < count($dates); $i++) {
            [$p, $q] = [$dates[$i - 1], $dates[$i]];
            if (!isset($rows[$p], $rows[$q]) || !self::adjacent($p, $q)) continue;
            $pv = $rows[$p][0]['value']; $qv = $rows[$q][0]['value'];
            $docsP = array_column($rows[$p], 'doc'); $docsQ = array_column($rows[$q], 'doc');
            if (array_intersect($docsP, $docsQ) !== [] || $rows[$p][0]['dev'] !== $rows[$q][0]['dev']) continue;
            if (self::same('number', $pv, $qv)) {
                $rows[$p] = [...$rows[$p], ...array_map(fn($o) => ['date' => $p] + $o, $rows[$q])];
                unset($rows[$q]);
            } elseif (!($qv < $pv && array_filter($trans, fn($t) => $t['date'] >= (string)$q && self::daysBetween((string)$q, $t['date']) <= self::SWAP_WINDOW_DAYS) !== [])) {
                // (ein Sprung nach unten kurz vor einem Nummernwechsel ist der Zählertausch, kein Widerspruch)
                $this->findings[] = ['kind' => 'chain_mismatch', 'utility' => $mp['utility'], 'docs' => array_values(array_unique([...$docsP, ...$docsQ])),
                    'params' => ['date' => $p, 'a' => $pv, 'b' => $qv]];
                foreach ([$p, $q] as $d) foreach ($rows[$d] as $k => $o) { $rows[$d][$k]['o']['flagged'] = true; $rows[$d][$k]['o']['reasons'][] = 'chain_mismatch'; }
            }
        }
        // fallender Stand ohne Wechsel: ein Gerätewechsel als Vorschlag (zu prüfen)
        $prev = null;
        foreach (array_keys($rows) as $date) {
            $cur = $rows[$date][0];
            if ($prev !== null && $prev['dev'] === $cur['dev'] && $cur['value'] < $prev['value'] - 0.0005) {
                // ein Nummernwechsel oder Wechsel-Brief wenige Tage danach erklärt den Sprung: derselbe
                // Wechsel, kein zweiter — er liegt am Tag des Sprungs; der Endstand ist der Stand davor
                $near = null;
                foreach ($trans as $ti => $t) {
                    if ($t['date'] >= (string)$date && self::daysBetween((string)$date, $t['date']) <= self::SWAP_WINDOW_DAYS) { $near = $ti; break; }
                }
                if ($near !== null) {
                    $t = $trans[$near];
                    foreach (array_keys($rows) as $d2) if ((string)$d2 >= (string)$date && (string)$d2 < $t['date']) foreach (array_keys($rows[$d2]) as $kk) $rows[$d2][$kk]['dev']++;
                    if ($t['doc'] === null) {
                        $f = self::obs($prev['value'], $prev['o']['src'], 9);
                        if (!self::adjacent((string)$prev['date'], (string)$date)) { $f['flagged'] = true; $f['reasons'][] = 'inferred'; }
                        $near7 = self::daysBetween((string)$prev['date'], (string)$date) <= self::SWAP_WINDOW_DAYS;
                        $trans[$near] = ['date' => (string)$date, 'final' => $near7 ? [$f] : [], 'initial' => [self::obs($cur['value'], $cur['o']['src'], 9)], 'inferred' => null] + $t;
                    }
                    $prev = $rows[$date][0];
                    continue;
                }
                $this->findings[] = ['kind' => 'falling', 'utility' => $mp['utility'], 'docs' => array_values(array_unique([$prev['doc'], $cur['doc']])),
                    'params' => ['date' => (string)$date, 'a' => $prev['value'], 'b' => $cur['value']]];
                $f = self::obs($prev['value'], $prev['o']['src'], 9);
                $f['flagged'] = true; $f['reasons'][] = 'inferred';
                // erschlossen nur aus einem Stand höchstens SWAP_WINDOW_DAYS vor dem Sprung, sonst „Fehlt noch“
                $trans[] = ['date' => (string)$date, 'serial' => null, 'label' => '', 'inferred' => 'falling_reading', 'doc' => null,
                    'final' => self::daysBetween((string)$prev['date'], (string)$date) <= self::SWAP_WINDOW_DAYS ? [$f] : [],
                    'initial' => [self::obs($cur['value'], $cur['o']['src'], 9)]];
                foreach (array_keys($rows) as $d2) if ($d2 >= $date) foreach (array_keys($rows[$d2]) as $kk) $rows[$d2][$kk]['dev']++;
                $cur = $rows[$date][0];
            }
            $prev = $cur;
        }
        usort($trans, fn($x, $y) => strcmp($x['date'], $y['date']));
        $mp['changes'] = $trans;
        $mp['rows'] = $rows;
        return $mp;
    }

    /**
     * Ein Wechsel von Gerät A zu B: aus dem Brief (Tag, Endstand, Anfangsstand),
     * ergänzt um die Stände der Rechnungen am Wechseltag; ohne Brief aus der
     * letzten Ablesung von A und der ersten von B (dann zu prüfen).
     *
     * @param list<array<string,mixed>> $obs
     * @return array<string,mixed>|null
     */
    private function transition(array $obs, string $a, string $b, ?array $doc, string $label): ?array
    {
        $ofA = array_values(array_filter($obs, fn($o) => $a !== '' && $o['serial'] === $a));
        $ofB = array_values(array_filter($obs, fn($o) => $b !== '' && $o['serial'] === $b));
        $lastA = $ofA !== [] ? $ofA[count($ofA) - 1] : null;
        $firstB = $ofB[0] ?? null;
        $final = []; $initial = [];
        if ($doc !== null) {
            $date = $doc['date'];
            if ($doc['final'] !== null) $final[] = self::obs($doc['final'], [$this->src($doc['doc'], 'old_reading'), $this->src($doc['doc'], 'change_date')], 6);
            if ($doc['initial'] !== null) $initial[] = self::obs($doc['initial'], [$this->src($doc['doc'], 'new_reading'), $this->src($doc['doc'], 'change_date')], 6);
            // Stände der Rechnungen am Wechseltag bestätigen (oder widersprechen)
            foreach ($ofA as $o) if ($o['date'] === $date) $final[] = $o['o'];
            foreach ($ofB as $o) if ($o['date'] === $date) $initial[] = $o['o'];
            if ($final === []) {
                // Endstand fehlt im Brief: die letzte Ablesung davor (Annahme) — nur, wenn sie
                // höchstens SWAP_WINDOW_DAYS vor dem Tausch liegt; sonst „Fehlt noch: Endstand“
                $before = array_values(array_filter($obs, fn($o) => $o['date'] <= $date && ($a === '' || $o['serial'] === $a || $o['serial'] === '')));
                $last = $before !== [] ? $before[count($before) - 1] : null;
                if ($last !== null && self::daysBetween($last['date'], $date) <= self::SWAP_WINDOW_DAYS) {
                    $x = $last['o']; $x['flagged'] = true; $x['reasons'][] = 'inferred'; $final[] = $x;
                }
            }
            if ($initial === []) {
                $after = array_values(array_filter($obs, fn($o) => $o['date'] >= $date && ($b === '' || $o['serial'] === $b)));
                if ($after !== [] && $after[0]['date'] === $date) $initial[] = $after[0]['o'];
            }
            return ['date' => $date, 'serial' => $b !== '' ? $b : null, 'label' => $label, 'inferred' => null, 'doc' => $doc['doc'], 'final' => $final, 'initial' => $initial];
        }
        if ($lastA === null || $firstB === null || $firstB['date'] < $lastA['date']) return null;
        $date = $firstB['date'];
        $f = $lastA['o'];
        // Endstand des alten Geräts am Wechseltag oder am Tag davor (Ende der einen, Beginn der
        // nächsten Rechnung): Nummernwechsel und Standsprung passen zusammen — belegt, vorbelegt.
        // Bis SWAP_WINDOW_DAYS davor erschlossen (zu prüfen), weiter davor gar nicht: „Fehlt noch“
        if (!self::adjacent($lastA['date'], $date)) { $f['flagged'] = true; $f['reasons'][] = 'inferred'; }
        $final = self::daysBetween($lastA['date'], $date) <= self::SWAP_WINDOW_DAYS ? [$f] : [];
        return ['date' => $date, 'serial' => $b, 'old' => $a, 'label' => $label, 'inferred' => 'number_change', 'doc' => null, 'final' => $final, 'initial' => [$firstB['o']]];
    }

    /** Eintrag „Zähler“: anlegen, Standardzähler übernehmen oder vorhandenen ergänzen. */
    private function meterItem(array $mp): void
    {
        $u = $mp['utility'];
        $meter = $mp['meter_id'] !== null ? $this->meters->get($u, $mp['meter_id']) : null;
        $first = $mp['devices'][0] ?? null;
        $serialObs = []; $maloObs = [];
        foreach ($mp['docs'] as $id) {
            $d = $this->docs[$id];
            if ($first !== null && $d['serial'] !== '' && 's:' . self::idKey($d['serial']) === $first['serial']) {
                $serialObs[] = self::obs($first['label'], [$this->src($id, 'meter_number')], self::PRIO[$d['type']] ?? 9);
            }
            if ($first !== null && $d['type'] === 'meter_change' && $d['serials'] !== [] && 's:' . self::idKey($d['serials'][0]) === $first['serial']) {
                $serialObs[] = self::obs($first['label'], [$this->src($id, 'old_meter_number')], 6);
            }
            foreach (self::meterReadings($d['entry']) as $i => $mr) {
                if ($first !== null && 's:' . self::idKey((string)($mr['value']['meter_number'] ?? '')) === $first['serial']) {
                    $serialObs[] = self::obs($first['label'], [$this->src($id, 'meter_readings', (int)$i)], self::PRIO[$d['type']] ?? 9);
                }
            }
            if ($d['malo'] !== '') $maloObs[] = self::obs($d['malo'], [$this->src($id, 'market_location')], self::PRIO[$d['type']] ?? 9);
        }
        $fields = [];
        $serial = self::field('serial', 'text', $serialObs);
        $malo = $this->preferNewest(self::field('malo', 'text', $maloObs));
        if ($mp['action'] === 'existing' && $meter !== null) {
            $onMeter = [];
            foreach ($meter['devices'] ?? [] as $dv) if (trim((string)($dv['serial'] ?? '')) !== '') $onMeter[] = 's:' . self::idKey((string)$dv['serial']);
            $open = null;
            foreach ($meter['devices'] ?? [] as $dv) if (empty($dv['removed_on'])) $open = $dv;
            if ($serial !== null) {
                $dev = $this->deviceWithoutSerial($meter, $first);
                if (in_array($first['serial'], $onMeter, true)) $serial['status'] = 'exists';
                elseif ($dev !== null) { $serial['status'] = 'new'; $serial['device_id'] = (string)$dev['id']; }
                else $serial = null;   // eine andere Nummer am Zähler: das ist ein Gerätewechsel, kein Nachtrag
            }
            if ($malo !== null) {
                $cur = (string)($meter['malo_id'] ?? '');
                if ($cur === (string)$malo['value'] && !isset($malo['options'])) $malo['status'] = 'exists';
                elseif ($cur !== '') self::conflict($malo, $cur);
            }
            $fields = array_values(array_filter([$serial, $malo]));
        } else {
            $name = $this->i18n->defaultMeterName($u) . ($first !== null && count(array_filter($this->mps, fn($m) => $m['utility'] === $u)) > 1 ? ' ' . $first['label'] : '');
            $fields = array_values(array_filter([
                $mp['action'] === 'create' ? ['name' => 'name', 'kind' => 'text', 'value' => $name, 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'estimated' => false] : null,
                $serial,
                $malo,
                $mp['first'] !== '9999-12-31' ? ['name' => 'installed_on', 'kind' => 'date', 'value' => $mp['first'], 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'estimated' => false, 'hint' => 'earliest'] : null,
            ]));
        }
        $this->items[] = [
            'kind' => 'meter', 'utility' => $u, 'key' => $mp['key'], 'meter_key' => $mp['key'], 'action' => $mp['action'],
            // ein vorhandener Zähler: „ergänzen“ (Nummer, Marktlokation nachtragen), nie vorbelegt
            'meter_id' => $mp['meter_id'], 'status' => $mp['action'] === 'existing' ? 'exists' : 'new',
            'label_key' => 'inboxDraft.item.meter.' . $mp['action'],
            'params' => ['serial' => $first['label'] ?? '', 'name' => $meter['name'] ?? ($fields[0]['value'] ?? ''), 'malo' => $malo['value'] ?? ''],
            'fields' => $fields, 'devices' => array_map(fn($dv) => ['label' => $dv['label'], 'first' => $dv['first'], 'last' => $dv['last']], $mp['devices']),
        ];
    }

    /**
     * Das Gerät eines vorhandenen Zählers, dem die erste Nummer der Dokumente
     * fehlt: das einzige Gerät ohne Nummer — bei mehreren das ohne Nummer, das
     * in der Zeit dieser Nummer lief (eingebaut vor ihrem ersten, nicht
     * ausgebaut vor ihrem letzten Stand). Sonst null (kein Nachtrag).
     */
    private function deviceWithoutSerial(array $meter, ?array $first): ?array
    {
        $devs = (array)($meter['devices'] ?? []);
        $bare = array_values(array_filter($devs, fn($dv) => trim((string)($dv['serial'] ?? '')) === ''));
        if ($first === null || $bare === []) return null;
        if (count($devs) === 1) return empty($bare[0]['removed_on']) ? $bare[0] : null;
        $hit = array_values(array_filter($bare, fn($dv) => (string)($dv['installed_on'] ?? '') <= $first['first']
            && (empty($dv['removed_on']) || (string)$dv['removed_on'] >= $first['last'])));
        return count($hit) === 1 ? $hit[0] : null;
    }

    /**
     * Passt ein Tausch aus den Ständen (Nummernwechsel) zusammen? Wie im
     * Einzeldokument: Die alte Nummer ist das Gerät, das bis zum Tauschtag lief
     * (am vorhandenen Zähler das laufende Gerät); Ausbau am Tauschtag oder am
     * Vortag (sonst ist der Endstand ohnehin erschlossen und zu prüfen); der
     * Endstand liegt über jedem bekannten Stand des alten Geräts (Dokumente und
     * Bestand); der neue Zähler steigt ab dem Anfangsstand; im Bestand noch kein
     * Stand ab dem Tauschtag.
     */
    private function swapFits(array $mp, array $t, ?array $meter, float $final, ?array $initial): bool
    {
        $tol = 0.0005;
        if ($meter !== null) {
            $open = null;
            foreach ($meter['devices'] ?? [] as $dv) if (empty($dv['removed_on'])) $open = $dv;
            $openSerial = trim((string)($open['serial'] ?? ''));
            if ($openSerial !== '' && 's:' . self::idKey($openSerial) !== ($t['old'] ?? '')) return false;
            foreach ($this->readings->list($mp['utility'], (string)$meter['id']) as $r) {
                if ((string)$r['date'] >= $t['date']) return false;
                // bekannte Stände des laufenden Geräts
                if ($open !== null && !empty($r['device_id']) && $r['device_id'] !== ($open['id'] ?? null)) continue;
                if ((float)$r['counter'] > $final + $tol) return false;
            }
        }
        $k = array_search($t, array_values($mp['changes']), true);
        $dev = $k === false ? null : $k + 1;
        foreach ($mp['rows'] as $date => $list) {
            foreach ($list as $o) {
                // Stände des alten Geräts (seine Nummer, ohne Nummer: das Gerät davor) nicht über dem Endstand
                $old = $o['serial'] === ($t['old'] ?? null) || ($o['serial'] === '' && $dev !== null && $o['dev'] === $dev - 1);
                if ($old && (float)$o['value'] > $final + $tol) return false;
                // der neue Zähler steigt ab dem Anfangsstand
                if ($o['serial'] === $t['serial'] && (string)$date > $t['date'] && $initial !== null && (float)$o['value'] < (float)$initial['value'] - $tol) return false;
            }
        }
        return true;
    }

    /** Einträge „Gerätewechsel“ (Brief, Nummernwechsel oder fallender Stand). */
    private function changeItems(array $mp): void
    {
        $u = $mp['utility'];
        if (!Utilities::isCumulative($u)) return;
        $meter = $mp['meter_id'] !== null && $mp['action'] === 'existing' ? $this->meters->get($u, $mp['meter_id']) : null;
        foreach ($mp['changes'] as $t) {
            $date = ['name' => 'date', 'kind' => 'date', 'value' => $t['date'], 'check' => false, 'reasons' => [], 'core' => true, 'status' => 'new', 'estimated' => false,
                'sources' => $t['doc'] !== null ? self::sources([self::obs($t['date'], [$this->src($t['doc'], 'change_date')])]) : []];
            if ($t['doc'] !== null) {
                $df = self::field('date', 'date', [self::obs($t['date'], [$this->src($t['doc'], 'change_date')], 6)], ['core' => true]);
                if ($df !== null) $date = $df;
            }
            $final = self::field('old_final', 'number', $t['final'], ['core' => true]);
            $initial = self::field('new_initial', 'number', $t['initial']);
            // Tausch aus den Ständen zweier Nummern (Folgerechnung, `meter_readings`): vorbelegt nur, wenn alles
            // zusammenpasst — dieselben Regeln wie im Einzeldokument (Proposals::swapFromReadings)
            if ($final !== null && !$final['check'] && $t['doc'] === null && $t['serial'] !== null && !$this->swapFits($mp, $t, $meter, (float)$final['value'], $initial)) {
                $final['check'] = true;
                $final['reasons'][] = 'swap_readings';
            }
            $serial = $t['serial'] !== null ? ['name' => 'serial', 'kind' => 'text', 'value' => $t['label'], 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'estimated' => false] : null;
            $item = [
                'kind' => 'meter_change', 'utility' => $u, 'key' => $mp['key'] . '@' . $t['date'], 'meter_key' => $mp['key'],
                'label_key' => 'inboxDraft.item.meterChange', 'params' => ['date' => $t['date'], 'serial' => $t['label']],
                'fields' => array_values(array_filter([$date, $final, $initial, $serial])),
                'reasons' => $t['inferred'] !== null ? [$t['inferred']] : [],
            ];
            if ($final === null) {
                // ohne Endstand kein Wechsel (MeterService::replaceDevice verlangt ihn)
                $this->missing[] = ['kind' => 'old_final', 'utility' => $u, 'key' => $item['key'], 'params' => ['date' => $t['date']], 'href' => "#/utility/$u/meters"];
                continue;
            }
            // ein fallender Stand ist nur ein Verdacht: sein Endstand ist erschlossen (`inferred`, Kernfeld) —
            // der Eintrag ist damit zu prüfen; ein Nummernwechsel ist belegt (Zweifel stehen an den Ständen)
            if ($meter !== null) {
                foreach ($meter['devices'] ?? [] as $dv) {
                    if ((string)($dv['installed_on'] ?? '') === $t['date'] && $dv !== ($meter['devices'][0] ?? null)) $item['status'] = 'exists';
                    if ($t['serial'] !== null && trim((string)($dv['serial'] ?? '')) !== '' && 's:' . self::idKey((string)$dv['serial']) === $t['serial']) $item['status'] = 'exists';
                }
            }
            $this->items[] = $item;
        }
    }

    /** Eintrag „Ablesungen“ eines Zählers: je Tag ein Feld, gegen den Bestand. */
    private function readingItem(array $mp): void
    {
        $u = $mp['utility'];
        if (!Utilities::isCumulative($u) || $mp['rows'] === []) return;
        $meter = $mp['meter_id'] !== null ? $this->meters->get($u, $mp['meter_id']) : null;
        if ($meter !== null && ($meter['capture'] ?? 'counter') === 'period') return;
        $existing = [];
        if ($meter !== null) foreach ($this->readings->list($u, (string)$meter['id']) as $r) $existing[(string)$r['date']] = $r;
        // ab einem Tausch ohne Endstand des alten Zählers gehören die Stände einem Gerät, das es noch
        // nicht gibt — sie fragen nach (sonst stünde am alten Gerät ein fallender Stand)
        $open = [];
        foreach (array_values($mp['changes']) as $k => $t) if (($t['final'] ?? []) === []) $open[] = $k + 1;
        $unsafeFrom = $open !== [] ? min($open) : null;
        // am Tauschtag keine Ablesung: Endstand des alten und Anfangsstand des neuen Geräts trägt der
        // Tausch (wie im Einzeldokument); eine Ablesung dort wäre derselbe Stand ein zweites Mal
        $swapDay = [];
        foreach ($mp['changes'] as $t) {
            $ini = self::field('x', 'number', $t['initial'] ?? []);
            // (nicht bei einem bloßen Verdacht aus einem fallenden Stand: dort bleibt die Ablesung)
            if ($ini !== null && ($t['final'] ?? []) !== [] && ($t['inferred'] ?? null) !== 'falling_reading') $swapDay[$t['date']] = (float)$ini['value'];
        }
        $fields = [];
        foreach ($mp['rows'] as $date => $list) {
            // zwei Geräte am selben Tag: der Stand des neuen gilt
            $maxDev = max(array_column($list, 'dev'));
            $list = array_values(array_filter($list, fn($o) => $o['dev'] === $maxDev));
            $f = self::field('r:' . $date, 'number', array_column($list, 'o'), ['date' => $date]);
            if ($f === null) continue;
            if (isset($swapDay[(string)$date]) && !isset($f['options']) && abs((float)$f['value'] - $swapDay[(string)$date]) <= 0.0005) continue;
            if ($unsafeFrom !== null && $maxDev >= $unsafeFrom) { $f['check'] = true; $f['reasons'][] = 'swap_open'; }
            // geschätzt laut Rechnung (Wort oder erklärter Code am Zitat): die Ablesung trägt es weiter —
            // sonst zählte die Monatskennung sie als Messung (lieber kein Messwert als ein falscher)
            if (array_filter(array_column($list, 'o'), fn($o) => !empty($o['is_estimated'])) !== []) $f['is_estimated'] = true;
            if (isset($existing[$date])) {
                if (self::same('number', (float)$existing[$date]['counter'], $f['value']) && !isset($f['options'])) $f['status'] = 'exists';
                else { self::conflict($f, (float)$existing[$date]['counter']); $f['reading_id'] = (string)$existing[$date]['id']; }
            } elseif (!isset($f['options'])) {
                // 3.7.1 — derselbe Stand einen Tag daneben im Bestand (Endstand am 31.12., Anfangsstand am 01.01.):
                // schon vorhanden, keine zweite Ablesung
                foreach (['-1 day', '+1 day'] as $shift) {
                    $near = $existing[date('Y-m-d', (int)strtotime("$date $shift"))] ?? null;
                    if ($near !== null && self::same('number', (float)$near['counter'], $f['value'])) $f['status'] = 'exists';
                }
            }
            $fields[] = $f;
        }
        if ($fields === []) return;
        $this->items[] = ['kind' => 'readings', 'utility' => $u, 'key' => $mp['key'] . '#r', 'meter_key' => $mp['key'],
            'label_key' => 'inboxDraft.item.readings', 'params' => ['count' => count($fields), 'serial' => $mp['devices'][0]['label'] ?? ''], 'fields' => $fields];
    }

    // ── Verträge ─────────────────────────────────────────────────────────

    /**
     * Verträge je Lieferverhältnis: aus den Bestätigungen (gleiche
     * Vertragsnummer, gleicher Beginn oder gleicher Tarif = derselbe Vertrag),
     * Beginn notfalls aus dem Lieferbeginn-Brief; ein Brief ohne Bestätigung
     * legt einen Vertrag an; ohne beide beginnt der Vertrag mit der ersten
     * Rechnung — oder es gilt ein vorhandener Vertrag desselben Versorgers.
     *
     * @param array<string,list<string>> $rels
     */
    private function contractCandidates(string $u, array $rels): void
    {
        if (!Utilities::hasContracts($u)) return;
        $invoiceOnly = [];
        // 1. je Lieferverhältnis: Bestätigungen zu Verträgen, Lieferbeginn-Briefe mit gleichem Beginn dazu
        $plan = [];
        foreach ($rels as $rk => $rel) {
            $byType = [];
            foreach ($rel as $id) $byType[$this->docs[$id]['type']][] = $id;
            $groups = [];
            foreach ($byType['contract_confirmation'] ?? [] as $id) {
                $start = $this->date($id, 'supply_start');
                $tariff = self::norm((string)($this->val($id, 'tariff_name') ?? ''));
                $hit = null;
                $no = $this->docs[$id]['contract_no'];
                foreach ($groups as $gi => $g) {
                    $nos = array_values(array_filter(array_map(fn($o) => $this->docs[$o]['contract_no'], $g['docs']), fn($x) => $x !== ''));
                    $sameNo = $no !== '' && array_filter($nos, fn($x) => Proposals::sameId($x, $no)) !== [];
                    // verschiedene Vertragsnummern: hier zwei Gruppen (zwei Zähler beim selben Versorger); am
                    // selben Zähler führt sie der gleiche Beginn unten wieder zusammen (ein Vertrag)
                    if ($no !== '' && $nos !== [] && !$sameNo) continue;
                    if ($sameNo || ($start !== null && $g['start'] === $start) || ($tariff !== '' && $tariff === $g['tariff'] && ($start === null || $g['start'] === null))) { $hit = $gi; break; }
                }
                if ($hit === null) { $groups[] = ['docs' => [$id], 'start' => $start, 'tariff' => $tariff, 'basis' => 'confirmation']; continue; }
                $groups[$hit]['docs'][] = $id;
                if ($groups[$hit]['start'] === null && $start !== null) $groups[$hit]['start'] = $start;
                if ($groups[$hit]['tariff'] === '') $groups[$hit]['tariff'] = $tariff;
            }
            // Lieferbeginn-Briefe: bestätigen einen Beginn, füllen eine Bestätigung ohne Beginn oder legen einen Vertrag an
            $letters = array_values(array_filter($byType['supply_start'] ?? [], fn($id) => $this->date($id, 'supply_start') !== null));
            usort($letters, fn($a, $b) => strcmp((string)$this->date($a, 'supply_start'), (string)$this->date($b, 'supply_start')));
            $used = [];
            foreach ($letters as $id) {
                $s = $this->date($id, 'supply_start');
                foreach ($groups as $gi => $g) if ($g['start'] === $s) { $groups[$gi]['docs'][] = $id; $used[$id] = true; break; }
            }
            $plan[$rk] = ['rel' => $rel, 'groups' => $groups, 'letters' => $letters, 'used' => $used];
        }
        // 2. Bestätigungen ohne Beginn (über alle Lieferverhältnisse der Art): Nummer, gleiche Preise, der eine Brief
        $this->startsForConfirmations($u, $plan);
        // 3. je Lieferverhältnis: übrige Briefe legen Verträge an; ohne Beginn kein Vertrag (und kein Zähler)
        foreach ($plan as $rk => $p) {
            $rel = $p['rel'];
            $groups = $p['groups'];
            foreach ($p['letters'] as $id) {
                if (isset($p['used'][$id])) continue;
                $groups[] = ['docs' => [$id], 'start' => $this->date($id, 'supply_start'), 'tariff' => '', 'basis' => 'supply_start'];
            }
            // gleicher Beginn am selben Zähler im selben Lieferverhältnis: ein Vertrag, nicht zwei
            $merged = [];
            foreach ($groups as $g) {
                $hit = null;
                if ($g['start'] !== null) foreach ($merged as $mi => $m) {
                    if ($m['start'] === $g['start'] && !array_filter($g['docs'], fn($o) => $this->otherMeter($o, $m['docs']))) { $hit = $mi; break; }
                }
                if ($hit === null) { $merged[] = $g; continue; }
                $merged[$hit]['docs'] = [...$merged[$hit]['docs'], ...$g['docs']];
            }
            $groups = $merged;
            foreach ($groups as $g) {
                if ($g['start'] === null) {
                    foreach ($g['docs'] as $id) $this->docs[$id]['note'] ??= 'no_start';
                    $this->missing[] = ['kind' => 'contract_start', 'utility' => $u, 'key' => $rk, 'params' => ['provider' => $this->supplierOf($g['docs'])], 'href' => "#/utility/$u/contracts",
                        'docs' => $g['docs']];
                }
            }
            $groups = array_values(array_filter($groups, fn($g) => $g['start'] !== null));
            $mp = $this->mpOfDocs($rel);
            if ($groups === []) {
                // ohne Bestätigung und Brief: die vorhandenen Verträge desselben Versorgers (je Rechnung der,
                // der damals lief), sonst Beginn mit der ersten Rechnung
                $existing = $mp !== null ? $this->existingContractsFor($u, $mp, $rel) : [];
                if ($existing !== []) {
                    foreach ($existing as $ex) $groups[] = ['docs' => [], 'start' => (string)$ex['start'], 'tariff' => '', 'basis' => 'existing', 'existing' => $ex];
                } else {
                    $firsts = array_filter(array_map(fn($id) => $this->docs[$id]['type'] === 'invoice' ? $this->date($id, 'period_from') : null, $rel));
                    if ($firsts !== []) {
                        // erst nach den anderen: vielleicht dieselbe Lieferung in anderer Schreibweise
                        $invoiceOnly[$rk] = ['rel' => $rel, 'mp' => $mp, 'start' => min($firsts)];
                        continue;
                    } elseif (array_filter($rel, fn($id) => in_array($this->docs[$id]['type'], ['price_change', 'advance_change', 'cancellation'], true)) !== []) {
                        $this->missing[] = ['kind' => 'contract_start', 'utility' => $u, 'key' => $rk, 'params' => ['provider' => $this->supplierOf($rel)], 'href' => "#/utility/$u/contracts", 'docs' => $rel];
                        foreach ($rel as $id) $this->docs[$id]['note'] ??= 'no_start';
                    }
                }
            }
            foreach ($groups as $g) $this->addCandidate($u, $rk, $g, $mp);
        }
        // Rechnungen ohne eigenen Vertrag: dieselbe Lieferung wie ein Vertrag an ihrem
        // Zähler (sameDelivery), sonst beginnt ihr Vertrag mit der ersten Rechnung
        foreach ($invoiceOnly as $rk => $x) {
            $target = $this->sameDelivery($u, $x['rel']);
            if ($target === null) {
                $this->addCandidate($u, $rk, ['docs' => [], 'start' => $x['start'], 'tariff' => '', 'basis' => 'invoice'], $x['mp']);
                continue;
            }
            foreach ($x['rel'] as $id) $this->docs[$id]['rel'] = $this->cands[$target]['rel'];
            $this->findings[] = ['kind' => 'same_supplier', 'utility' => $u, 'docs' => $x['rel'],
                'params' => ['name' => $this->supplierOf($x['rel']), 'of' => $this->candProvider($target)]];
        }
        $this->matchExistingContracts($u);
        $this->assignToContracts($u);
        // vorhandene Verträge, zu denen kein Dokument gehört (Kandidaten für Rechnungen über mehrere Jahre): nicht im Entwurf
        foreach ($this->cands as $k => $c) {
            if ($c['utility'] === $u && $c['basis'] === 'existing' && array_filter($this->docs, fn($d) => $d['cand'] === $k) === []) unset($this->cands[$k]);
        }
        $this->invoicesBeforeStart($u);
        $this->contractEnds($u);
    }

    /** Liegt das Dokument an einem anderen Zähler als die Dokumente der Gruppe (beide bekannt)? */
    private function otherMeter(string $docId, array $group): bool
    {
        $mine = $this->docs[$docId]['nodes'] !== [] ? $this->docs[$docId]['mp'] : null;
        if ($mine === null) return false;
        foreach ($group as $o) if ($this->docs[$o]['nodes'] !== [] && $this->docs[$o]['mp'] !== null && $this->docs[$o]['mp'] !== $mine) return true;
        return false;
    }

    /**
     * Bestätigungen ohne Lieferbeginn, streng und nur innerhalb der
     * Verbrauchsart — der Reihe nach:
     *   1. gleiche Vertrags- oder Kundennummer wie ein Lieferbeginn-Brief (auch
     *      eines anderen Lieferverhältnisses, etwa unter dem Markennamen)
     *   2. gleiche Preise wie genau ein Vertrag mit Beginn (dann gehört sie zu
     *      ihm) oder wie die Rechnungen genau eines Lieferverhältnisses (Beginn
     *      mit der ersten dieser Rechnungen)
     *   3. der eine noch freie Brief desselben Versorgers; ohne ihn der eine
     *      freie Brief der Art, dessen Lieferverhältnis keine Bestätigung hat
     *      und dessen Versorger nicht anders heißt
     * Mehrdeutig heißt: kein Beginn (lieber „Fehlt noch“ als eine Schätzung).
     *
     * @param array<string,array<string,mixed>> $plan
     */
    private function startsForConfirmations(string $u, array &$plan): void
    {
        $free = function () use (&$plan): array {
            $out = [];
            foreach ($plan as $rk => $p) foreach ($p['letters'] as $id) if (!isset($p['used'][$id])) $out[$id] = $rk;
            return $out;
        };
        $take = function (string $rk, int $gi, string $letter) use (&$plan, $free): void {
            $from = $free()[$letter] ?? $rk;
            $plan[$from]['used'][$letter] = true;
            $plan[$rk]['groups'][$gi]['start'] = $this->date($letter, 'supply_start');
            $plan[$rk]['groups'][$gi]['basis'] = 'supply_start';
            $plan[$rk]['groups'][$gi]['docs'][] = $letter;
            $this->docs[$letter]['rel'] = $rk;
        };
        foreach (array_keys($plan) as $rk) {
            foreach (array_keys($plan[$rk]['groups']) as $gi) {
                $g = $plan[$rk]['groups'][$gi] ?? null;
                if ($g === null || $g['start'] !== null) continue;
                // 1. Nummer
                $hits = array_keys(array_filter($free(), fn($from, $l) => $this->sharesNumber($l, $g['docs']), ARRAY_FILTER_USE_BOTH));
                if (count($hits) === 1) { $take($rk, $gi, (string)$hits[0]); continue; }
                // 2. gleiche Preise
                if ($this->joinByPrices($u, $plan, $rk, $gi)) continue;
                // 3. der eine freie Brief desselben Versorgers, sonst der eine verwaiste der Art
                $mine = array_keys(array_filter($free(), fn($from) => $from === $rk));
                if (count($mine) === 1) { $take($rk, $gi, (string)$mine[0]); continue; }
                if ($mine !== []) continue;
                $supplier = $this->supplierOf($g['docs']);
                $orphans = array_keys(array_filter($free(), function ($from, $l) use ($plan, $supplier) {
                    $hasConf = array_filter($plan[$from]['rel'], fn($o) => $this->docs[$o]['type'] === 'contract_confirmation') !== [];
                    $s = $this->docs[$l]['supplier'];
                    return !$hasConf && ($s === '' || $supplier === '' || self::sameName($s, $supplier) || self::sameSupplier($s, $supplier));
                }, ARRAY_FILTER_USE_BOTH));
                if (count($orphans) === 1) $take($rk, $gi, (string)$orphans[0]);
            }
        }
    }

    /** Gleiche Vertrags- oder Kundennummer wie eines der Dokumente? */
    private function sharesNumber(string $docId, array $others): bool
    {
        $d = $this->docs[$docId];
        foreach ($others as $o) {
            $x = $this->docs[$o];
            if ($d['contract_no'] !== '' && $x['contract_no'] !== '' && Proposals::sameId($d['contract_no'], $x['contract_no'])) return true;
            if ($d['customer_no'] !== '' && $x['customer_no'] !== '' && Proposals::sameId($d['customer_no'], $x['customer_no'])) return true;
        }
        return false;
    }

    /**
     * Bestätigung ohne Beginn mit denselben Preisen (Arbeitspreis, dazu der
     * Grundpreis, wo beide ihn nennen) wie genau ein Vertrag mit Beginn — dann
     * gehört sie zu ihm — oder wie die Rechnungen genau eines
     * Lieferverhältnisses ohne Bestätigung: Beginn mit der ersten dieser
     * Rechnungen.
     *
     * @param array<string,array<string,mixed>> $plan
     */
    private function joinByPrices(string $u, array &$plan, string $rk, int $gi): bool
    {
        $g = $plan[$rk]['groups'][$gi];
        $wp = null; $bp = null;
        foreach ($g['docs'] as $id) {
            $wp ??= $this->priceOf($id, 'working_price');
            $bp ??= $this->monthlyPrice($id, 'base_price');
        }
        if ($wp === null) return false;
        $fits = function (?float $w, ?float $b) use ($wp, $bp): bool {
            return $w !== null && abs($w - $wp) <= Proposals::PRICE_TOL && ($bp === null || $b === null || abs($b - $bp) <= Proposals::PRICE_TOL);
        };
        // Verträge mit Beginn (aus Bestätigungen)
        $groupHits = [];
        foreach ($plan as $rk2 => $p) {
            foreach ($p['groups'] as $gi2 => $g2) {
                if (($rk2 === $rk && $gi2 === $gi) || $g2['start'] === null) continue;
                foreach ($g2['docs'] as $o) {
                    if ($this->docs[$o]['type'] === 'contract_confirmation' && $fits($this->priceOf($o, 'working_price'), $this->monthlyPrice($o, 'base_price'))) { $groupHits["$rk2#$gi2"] = [$rk2, $gi2]; break; }
                }
            }
        }
        if (count($groupHits) === 1) {
            [$rk2, $gi2] = array_values($groupHits)[0];
            foreach ($g['docs'] as $id) { $plan[$rk2]['groups'][$gi2]['docs'][] = $id; $this->docs[$id]['rel'] = $rk2; }
            unset($plan[$rk]['groups'][$gi]);
            return true;
        }
        if ($groupHits !== []) return false;
        // Rechnungen mit diesem Preis: im eigenen Lieferverhältnis oder in genau einem ohne Bestätigung
        $relHits = [];
        foreach ($plan as $rk2 => $p) {
            if ($rk2 !== $rk && array_filter($p['rel'], fn($o) => $this->docs[$o]['type'] === 'contract_confirmation') !== []) continue;
            foreach ($p['rel'] as $o) {
                if ($this->docs[$o]['type'] !== 'invoice' || $this->date($o, 'period_from') === null) continue;
                foreach ((array)($this->docs[$o]['entry']['extraction']['fields']['working_prices']['items'] ?? []) as $i => $_) {
                    if ($fits($this->priceOf($o, 'working_prices', (int)$i), $this->monthlyPrice($o, 'base_price'))) { $relHits[$rk2][] = $o; break; }
                }
            }
        }
        if (count($relHits) !== 1) return false;
        $rk2 = (string)array_key_first($relHits);
        // Beginn mit der ersten Rechnung, die diesen Preis nennt
        $g['start'] = min(array_map(fn($o) => (string)$this->date($o, 'period_from'), $relHits[$rk2]));
        $g['basis'] = 'invoice';
        unset($plan[$rk]['groups'][$gi]);
        // läuft zu dieser Zeit schon ein Vertrag desselben Versorgers (Bestätigung oder Lieferbeginn-Brief),
        // gehört die Bestätigung zu ihm — kein zweiter Vertrag mit gleichem Beginn
        $supplier = $this->supplierOf([...$g['docs'], ...$relHits[$rk2]]);
        $sameSupplier = fn(string $rk3, array $docs) => $rk3 === $rk2 || ($supplier !== '' && self::sameSupplier($this->supplierOf($docs), $supplier));
        $best = null;
        foreach ($plan as $rk3 => $p) {
            foreach ($p['groups'] as $gi3 => $g3) {
                if ($g3['start'] === null || $g3['start'] > $g['start'] || !$sameSupplier($rk3, $g3['docs'])) continue;
                $wp3 = null;
                foreach ($g3['docs'] as $o) $wp3 ??= $this->priceOf($o, 'working_price');
                if ($wp3 !== null && abs($wp3 - $wp) > Proposals::PRICE_TOL) continue;   // ein anderer Tarif
                if ($best === null || $g3['start'] > $best['start']) $best = ['start' => $g3['start'], 'group' => [$rk3, $gi3]];
            }
            foreach ($p['letters'] as $l) {
                $ls = (string)$this->date($l, 'supply_start');
                if (isset($p['used'][$l]) || $ls > $g['start'] || !$sameSupplier($rk3, [$l])) continue;
                if ($best === null || $ls > $best['start']) $best = ['start' => $ls, 'letter' => [$rk3, $l]];
            }
        }
        if (isset($best['group'])) {
            [$rk3, $gi3] = $best['group'];
            foreach ($g['docs'] as $id) { $plan[$rk3]['groups'][$gi3]['docs'][] = $id; $this->docs[$id]['rel'] = $rk3; }
            return true;
        }
        if (isset($best['letter'])) {
            [$rk2, $letter] = $best['letter'];
            $plan[$rk2]['used'][$letter] = true;
            $g['start'] = $best['start'];
            $g['basis'] = 'supply_start';
            $g['docs'][] = $letter;
        }
        if ($rk2 !== $rk) {
            foreach ($g['docs'] as $id) $this->docs[$id]['rel'] = $rk2;
            $plan[$rk2]['rel'] = [...$plan[$rk2]['rel'], ...$g['docs']];
        }
        $plan[$rk2]['groups'][] = $g;
        return true;
    }

    /**
     * Rechnungen vor dem Beginn des Vertrags ihres Lieferverhältnisses: Gehört
     * die Rechnung zu ihm (keine andere Vertragsnummer), beginnt er mit der
     * ersten dieser Rechnungen — mit Hinweis; sonst entsteht aus ihnen ein
     * Vorvertrag (Beginn mit der ersten, Ende am Tag vor dem Nachfolger), am
     * Zähler der Rechnungen.
     */
    private function invoicesBeforeStart(string $u): void
    {
        $byCand = [];
        foreach ($this->docs as $id => $d) {
            if ($d['utility'] !== $u || $d['dup'] !== null || $d['cand'] !== null || $d['rel'] === null || $d['type'] !== 'invoice') continue;
            $from = $this->date($id, 'period_from');
            if ($from === null) continue;
            // der früheste Vertrag desselben Lieferverhältnisses, der nach der Rechnung beginnt
            $next = null;
            foreach ($this->cands as $k => $c) {
                if ($c['utility'] !== $u || $c['rel'] !== $d['rel'] || $c['start'] <= $from) continue;
                if ($d['mp'] !== null && $c['mp'] !== $d['mp']) continue;
                if ($next === null || $c['start'] < $this->cands[$next]['start']) $next = $k;
            }
            if ($next !== null) $byCand[$next][] = $id;
        }
        foreach ($byCand as $k => $ids) {
            $c = $this->cands[$k];
            if ($c['existing'] !== null) continue;   // ein Vertrag im Bestand wird nicht verschoben
            $nos = array_values(array_filter(array_map(fn($o) => $this->docs[$o]['contract_no'], $c['docs']), fn($x) => $x !== ''));
            if (!empty($c['existing']['contract_number'])) $nos[] = (string)$c['existing']['contract_number'];
            $same = []; $other = [];
            foreach ($ids as $id) {
                $no = $this->docs[$id]['contract_no'];
                $conflict = $no !== '' && $nos !== [] && array_filter($nos, fn($x) => Proposals::sameId($x, $no)) === [];
                if ($conflict) $other[] = $id; else $same[] = $id;
            }
            if ($same !== []) {
                $start = min(array_map(fn($id) => (string)$this->date($id, 'period_from'), $same));
                $this->findings[] = ['kind' => 'start_from_invoice', 'utility' => $u, 'docs' => [...$same, ...$c['docs']],
                    'params' => ['provider' => $this->candProvider($k), 'from' => $start, 'to' => $c['start']]];
                $this->cands[$k]['doc_start'] = $c['start'];
                $this->cands[$k]['start'] = $start;
                $this->cands[$k]['basis'] = 'invoice_earlier';
                foreach ($same as $id) $this->docs[$id]['cand'] = $k;
            }
            if ($other !== []) {
                $start = min(array_map(fn($id) => (string)$this->date($id, 'period_from'), $other));
                $pk = $this->addCandidate($u, $c['rel'], ['docs' => [], 'start' => $start, 'tariff' => '', 'basis' => 'invoice'], $this->mpOfDocs($other));
                if ($pk !== null) foreach ($other as $id) $this->docs[$id]['cand'] = $pk;
            }
        }
    }

    /** @param array{docs: list<string>, start: string, tariff: string, basis: string, existing?: array<string,mixed>} $g */
    private function addCandidate(string $u, string $rk, array $g, ?string $mp): ?string
    {
        $cmp = $this->mpOfDocs($g['docs']) ?? $mp ?? $this->singleMp($u);
        if ($cmp === null) return null;
        $key = 'c_' . substr(sha1("$u|$rk|" . $g['start'] . '|' . implode(',', $g['docs'])), 0, 10);
        $this->cands[$key] = ['key' => $key, 'utility' => $u, 'rel' => $rk, 'mp' => $cmp, 'start' => $g['start'], 'basis' => $g['basis'],
            'docs' => $g['docs'], 'end' => null, 'end_obs' => [], 'end_basis' => null, 'existing' => $g['existing'] ?? null, 'status' => 'new', 'detail' => null];
        return $key;
    }

    /**
     * Rechnungen eines Lieferverhältnisses ohne Bestätigung, Brief oder
     * Vertrag im Bestand: Läuft an ihrem Zähler zu ihrer Zeit schon ein Vertrag
     * (Beginn vor dem Ende der Rechnungen, der späteste) und teilt sein
     * Versorger mit ihrem ein Wort, das allein beide bezeichnet (sameName), ist
     * es dieselbe Lieferung — an einem Zähler liefert zur selben Zeit nur einer.
     * Sonst null (eigener Vertrag ab der ersten Rechnung).
     *
     * @param list<string> $rel
     */
    private function sameDelivery(string $u, array $rel): ?string
    {
        $mp = $this->mpOfDocs($rel);
        $supplier = $this->supplierOf($rel);
        $ends = array_filter(array_map(fn($id) => $this->docs[$id]['type'] === 'invoice' ? $this->date($id, 'period_to') : null, $rel));
        if ($mp === null || $supplier === '' || $ends === []) return null;
        $best = null;
        foreach ($this->cands as $k => $c) {
            if ($c['utility'] !== $u || $c['mp'] !== $mp || $c['start'] > max($ends)) continue;
            if (!self::sameName($supplier, $this->candProvider($k))) continue;
            if ($best === null || $c['start'] > $this->cands[$best]['start']) $best = $k;
        }
        return $best;
    }

    /** Versorger eines Vertragskandidaten: aus seinen Dokumenten, sonst aus dem Bestand. */
    private function candProvider(string $k): string
    {
        return $this->supplierOf($this->cands[$k]['docs']) ?: self::providerOf((array)($this->cands[$k]['existing'] ?? []));
    }

    /**
     * Teilen zwei Versorgernamen ein Wort, das allein beide bezeichnet
     * (Proposals::supplierMatch, mindestens vier Zeichen)? „Musterhausen“ in
     * „Stadtwerke Musterhausen“ und „Musterhausen Energie“ — nie „Stadtwerke“.
     * Nur zusammen mit Zähler und Zeit (sameDelivery), der Name allein reicht nicht.
     */
    private static function sameName(string $a, string $b): bool
    {
        foreach (array_intersect(Proposals::supplierTokens($a), Proposals::supplierTokens($b)) as $w) {
            if (strlen($w) >= 4 && Proposals::supplierMatch($w, $a) && Proposals::supplierMatch($w, $b)) return true;
        }
        return false;
    }

    private function supplierOf(array $ids): string
    {
        $count = [];
        foreach ($ids as $id) {
            $s = $this->docs[$id]['supplier'];
            if ($s === '') continue;
            $w = $this->docs[$id]['type'] === 'contract_confirmation' ? 10 : 1;
            $count[$s] = ($count[$s] ?? 0) + $w;
        }
        if ($count === []) return '';
        arsort($count);
        return (string)array_key_first($count);
    }

    /**
     * Messstelle, der die meisten dieser Dokumente angehören — Dokumente mit
     * Zählernummer oder Marktlokation zählen vor solchen ohne (ein Vertrag
     * gehört an den Zähler seiner Rechnungen, nicht an den eines Briefs ohne Nummer).
     */
    private function mpOfDocs(array $ids): ?string
    {
        $n = [];
        foreach ($ids as $id) if ($this->docs[$id]['mp'] !== null) $n[$this->docs[$id]['mp']] = ($n[$this->docs[$id]['mp']] ?? 0) + ($this->docs[$id]['nodes'] !== [] ? 10 : 1);
        if ($n === []) return null;
        arsort($n);
        return (string)array_key_first($n);
    }

    private function singleMp(string $u): ?string
    {
        $of = array_values(array_filter($this->mps, fn($m) => $m['utility'] === $u));
        return count($of) === 1 ? $of[0]['key'] : null;
    }

    /**
     * Vorhandene Verträge des Zählers (auch seiner Gruppe) mit derselben
     * Vertrags- oder Kundennummer oder demselben Versorger — alle, nach Beginn:
     * Rechnungen über mehrere Jahre gehören je an den Vertrag, der damals lief.
     *
     * @return list<array<string,mixed>>
     */
    private function existingContractsFor(string $u, string $mpKey, array $rel): array
    {
        $mid = $this->mps[$mpKey]['meter_id'];
        if ($mid === null || $this->mps[$mpKey]['action'] === 'adopt') return [];
        $meter = $this->meters->get($u, $mid);
        if ($meter === null) return [];
        $out = [];
        foreach ($this->existingContracts($u, $meter) as $c) {
            $hit = false;
            foreach ($rel as $id) {
                $d = $this->docs[$id];
                if ($d['contract_id'] === ($c['id'] ?? null)) $hit = true;
                if ($d['contract_no'] !== '' && Proposals::sameId((string)($c['contract_number'] ?? ''), $d['contract_no'])) $hit = true;
                if ($d['customer_no'] !== '' && Proposals::sameId((string)($c['customer_number'] ?? ''), $d['customer_no'])) $hit = true;
                if (self::sameSupplier(self::providerOf($c), $d['supplier'])) $hit = true;
            }
            if ($hit) $out[] = $c;
        }
        usort($out, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
        return $out;
    }

    /**
     * 3.7.1 — Beginn bis so viele Tage neben dem Bestand ist derselbe Vertrag
     * (gleicher Zähler, gleicher Versorger): Bestätigung, Lieferbeginn-Brief und
     * erste Rechnung nennen oft verschiedene Tage (Vertragsbeginn, erster
     * Liefertag, Ablesung am Tag davor oder danach, Umzug zum Monatsanfang),
     * ein Wechsel desselben Versorgers am selben Zähler liegt nie eine Woche
     * neben dem vorigen Beginn. Die Woche entspricht dem Fenster des Tauschs
     * (SWAP_WINDOW_DAYS).
     */
    private const START_NEAR_DAYS = 7;

    /**
     * Abgleich mit den Verträgen am vorhandenen Zähler (auch seiner Gruppe):
     * gleicher Beginn = vorhanden (anderer Versorger: Widerspruch); derselbe
     * Versorger (oder dieselbe Vertragsnummer) mit Beginn bis START_NEAR_DAYS
     * daneben = vorhanden, der Beginn bleibt der des Bestands (Befund
     * `start_near`); sonst eine Überschneidung, wenn ein vorhandener zu der Zeit
     * läuft. Zwei Kandidaten für denselben vorhandenen Vertrag sind einer.
     * Verträge im Bestand ohne Nummer erkennt so der Versorger; eine Angabe, die
     * vom Bestand abweicht, bleibt ein Widerspruch (contractItems).
     */
    private function matchExistingContracts(string $u): void
    {
        $held = [];
        foreach ($this->cands as $k => $c) if ($c['utility'] === $u && $c['existing'] !== null) $held[(string)$c['existing']['id']] = $k;
        foreach (array_keys($this->cands) as $k) {
            $c = $this->cands[$k] ?? null;
            if ($c === null || $c['utility'] !== $u || $c['existing'] !== null) continue;
            $mp = $this->mps[$c['mp']];
            if ($mp['meter_id'] === null || $mp['action'] === 'adopt') continue;
            $meter = $this->meters->get($u, $mp['meter_id']);
            if ($meter === null) continue;
            $provider = $this->supplierOf($c['docs']);
            $nos = array_values(array_filter(array_map(fn($id) => $this->docs[$id]['contract_no'], $c['docs']), fn($x) => $x !== ''));
            $list = $this->existingContracts($u, $meter);
            $best = null; $bestDays = null;
            foreach ($list as $ex) {
                $days = abs(self::daysBetween((string)($ex['start'] ?? ''), $c['start']));
                if ($days > self::START_NEAR_DAYS) continue;
                $sameNo = array_filter($nos, fn($n) => Proposals::sameId((string)($ex['contract_number'] ?? ''), $n)) !== [];
                $sameSupplier = $provider !== '' && self::sameSupplier(self::providerOf($ex), $provider);
                if (!($sameNo || $sameSupplier || ($days === 0 && $provider === ''))) {
                    // gleicher Beginn, anderer Versorger: ein Widerspruch zum Bestand
                    if ($days === 0) { $this->cands[$k]['status'] = 'conflict'; $this->cands[$k]['detail'] = ['provider' => (string)($ex['provider'] ?? ''), 'start' => $ex['start'], 'end' => $ex['end'] ?? null]; }
                    continue;
                }
                if ($bestDays === null || $days < $bestDays) { $best = $ex; $bestDays = $days; }
            }
            if ($best !== null) {
                $id = (string)$best['id'];
                if (isset($held[$id]) && isset($this->cands[$held[$id]])) {
                    // derselbe vorhandene Vertrag aus zwei Lieferverhältnissen (andere Schreibweise): einer
                    $this->cands[$held[$id]]['docs'] = array_values(array_unique([...$this->cands[$held[$id]]['docs'], ...$c['docs']]));
                    unset($this->cands[$k]);
                    continue;
                }
                $held[$id] = $k;
                $this->cands[$k]['existing'] = $best;
                $this->cands[$k]['status'] = 'exists';
                $this->cands[$k]['detail'] = null;
                if ($bestDays > 0) {
                    $this->findings[] = ['kind' => 'start_near', 'utility' => $u, 'docs' => $c['docs'],
                        'params' => ['provider' => self::providerOf($best) ?: $provider, 'from' => $c['start'], 'date' => (string)$best['start']]];
                    $this->cands[$k]['near_start'] = $c['start'];
                    $this->cands[$k]['start'] = (string)$best['start'];
                }
                continue;
            }
            if ($this->cands[$k]['status'] === 'conflict') continue;
            foreach ($list as $ex) {
                if ((string)$ex['start'] <= $c['start'] && (empty($ex['end']) || (string)$ex['end'] >= $c['start'])) {
                    $this->cands[$k]['status'] = 'overlap';
                    $this->cands[$k]['detail'] = ['provider' => (string)($ex['provider'] ?? ''), 'start' => $ex['start'], 'end' => $ex['end'] ?? null];
                }
            }
        }
    }

    /**
     * Vertrag eines Dokuments an seinem Stichtag (der späteste Beginn davor):
     * zuerst unter den Verträgen mit derselben Vertrags- oder Kundennummer, dann
     * unter denen seines Zählers im Lieferverhältnis, dann im Lieferverhältnis.
     */
    private function candFor(string $docId, string $date): ?string
    {
        $d = $this->docs[$docId];
        $sameNo = function (array $c) use ($d): bool {
            foreach ($c['docs'] as $o) {
                $x = $this->docs[$o];
                if ($d['contract_no'] !== '' && $x['contract_no'] !== '' && Proposals::sameId($d['contract_no'], $x['contract_no'])) return true;
                if ($d['customer_no'] !== '' && $x['customer_no'] !== '' && Proposals::sameId($d['customer_no'], $x['customer_no'])) return true;
            }
            $ex = $c['existing'];
            return $ex !== null && (($d['contract_no'] !== '' && Proposals::sameId((string)($ex['contract_number'] ?? ''), $d['contract_no']))
                || ($d['contract_id'] !== null && $d['contract_id'] === ($ex['id'] ?? null)));
        };
        $filters = [
            fn($c) => $c['utility'] === $d['utility'] && $sameNo($c),
            fn($c) => $c['rel'] === $d['rel'] && $d['mp'] !== null && $c['mp'] === $d['mp'],
            fn($c) => $c['rel'] === $d['rel'],
        ];
        foreach ($filters as $ok) {
            $best = null;
            foreach ($this->cands as $k => $c) {
                if (!$ok($c) || $c['start'] > $date) continue;
                if ($best === null || $c['start'] > $this->cands[$best]['start']) $best = $k;
            }
            if ($best !== null) return $best;
        }
        return null;
    }

    /** Briefe und Rechnungen dem Vertrag zuordnen, der an ihrem Stichtag läuft. */
    private function assignToContracts(string $u): void
    {
        foreach ($this->cands as $k => $c) if ($c['utility'] === $u) foreach ($c['docs'] as $id) $this->docs[$id]['cand'] = $k;
        foreach ($this->docs as $id => $d) {
            if ($d['utility'] !== $u || $d['dup'] !== null || $d['rel'] === null || $d['cand'] !== null) continue;
            $date = match ($d['type']) {
                'price_change', 'advance_change' => $this->date($id, 'valid_from'),
                'cancellation' => $this->date($id, 'supply_end'),
                'invoice' => $this->date($id, 'period_to'),
                default => null,
            };
            if ($date === null) continue;
            $k = $this->candFor($id, $date);
            if ($k !== null) $this->docs[$id]['cand'] = $k;
        }
    }

    /**
     * Vertragsende aus Kündigung oder Schlussrechnung, sonst am Tag vor dem
     * Folgevertrag derselben Messstelle. Ein genanntes Ende, das nicht an den
     * Nachfolger anschließt, ist eine Lücke oder Überschneidung — gemeldet,
     * nicht korrigiert.
     */
    private function contractEnds(string $u): void
    {
        foreach ($this->docs as $id => $d) {
            if ($d['utility'] !== $u || $d['dup'] !== null || $d['cand'] === null) continue;
            if ($d['type'] === 'cancellation' && ($e = $this->date($id, 'supply_end')) !== null) {
                $this->cands[$d['cand']]['end_obs'][] = self::obs($e, [$this->src($id, 'supply_end')], 4);
                if (!in_array($id, $this->cands[$d['cand']]['docs'], true)) $this->cands[$d['cand']]['docs'][] = $id;
            }
            if ($d['type'] === 'invoice' && $this->val($id, 'kind') === 'final' && ($e = $this->date($id, 'period_to')) !== null) {
                $this->cands[$d['cand']]['end_obs'][] = self::obs($e, [$this->src($id, 'period_to'), $this->src($id, 'kind')], 5);
            }
        }
        $byMp = [];
        foreach ($this->cands as $k => $c) if ($c['utility'] === $u) $byMp[$c['mp']][] = $k;
        foreach ($byMp as $list) {
            usort($list, fn($a, $b) => strcmp($this->cands[$a]['start'], $this->cands[$b]['start']));
            foreach ($list as $i => $k) {
                $c = $this->cands[$k];
                $next = $list[$i + 1] ?? null;
                $f = self::field('end', 'date', $c['end_obs']);
                // ein zweifelhaftes Ende bei bekanntem Folgevertrag: der Tag vor dessen Beginn, vorbelegt
                // (bis dahin blieb das Ende offen und der Vertrag überschnitt sich mit dem Nachfolger)
                if ($f !== null && $f['check'] && $next !== null && $c['existing'] === null && $this->cands[$next]['start'] > $c['start']) {
                    $succ = date('Y-m-d', (int)strtotime($this->cands[$next]['start'] . ' -1 day'));
                    $f = $f['value'] === $succ
                        ? ['check' => false, 'reasons' => []] + $f
                        : ['name' => 'end', 'kind' => 'date', 'value' => $succ, 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'estimated' => false];
                    $this->cands[$k]['end'] = $succ;
                    $this->cands[$k]['end_basis'] = 'successor';
                    $this->cands[$k]['end_field'] = $f + ['basis' => 'successor'];
                    continue;
                }
                if ($f !== null) {
                    $this->cands[$k]['end'] = (string)$f['value'];
                    $this->cands[$k]['end_basis'] = 'document';
                    $this->cands[$k]['end_field'] = $f;
                    if ($next !== null) {
                        $ns = $this->cands[$next]['start'];
                        $dayAfter = date('Y-m-d', (int)strtotime($f['value'] . ' +1 day'));
                        if ($ns > $dayAfter) $this->findings[] = ['kind' => 'gap', 'utility' => $u, 'docs' => [...$c['docs'], ...$this->cands[$next]['docs']],
                            'params' => ['from' => $dayAfter, 'to' => date('Y-m-d', (int)strtotime($ns . ' -1 day'))]];
                        elseif ($ns <= (string)$f['value']) $this->findings[] = ['kind' => 'overlap', 'utility' => $u, 'docs' => [...$c['docs'], ...$this->cands[$next]['docs']],
                            'params' => ['from' => $ns, 'to' => (string)$f['value']]];
                    }
                } elseif ($next !== null && $c['existing'] === null) {
                    // zwei Verträge mit demselben Beginn am selben Zähler: melden, kein Ende vor dem Beginn
                    if ($this->cands[$next]['start'] <= $c['start']) {
                        $this->findings[] = ['kind' => 'overlap', 'utility' => $u, 'docs' => [...$c['docs'], ...$this->cands[$next]['docs']],
                            'params' => ['from' => $c['start'], 'to' => $c['start']]];
                        continue;
                    }
                    $this->cands[$k]['end'] = date('Y-m-d', (int)strtotime($this->cands[$next]['start'] . ' -1 day'));
                    $this->cands[$k]['end_basis'] = 'successor';
                }
            }
        }
    }

    /** Einträge „Vertrag“ und „bisheriger Vertrag endet“. */
    private function contractItems(string $u): void
    {
        $firstByMp = [];
        foreach ($this->cands as $k => $c) {
            if ($c['utility'] !== $u) continue;
            if (!isset($firstByMp[$c['mp']]) || $c['start'] < $this->cands[$firstByMp[$c['mp']]]['start']) $firstByMp[$c['mp']] = $k;
            $conf = array_values(array_filter($c['docs'], fn($id) => $this->docs[$id]['type'] === 'contract_confirmation'));
            // die eigenen Dokumente und die, die an seinem Stichtag zu ihm gehören (Rechnungen, Briefe)
            $all = array_values(array_unique([...$c['docs'], ...array_keys(array_filter($this->docs, fn($d) => $d['cand'] === $k && $d['dup'] === null))]));
            $prio = fn(string $id) => self::PRIO[$this->docs[$id]['type']] ?? 9;
            $o = fn(string $field, array $ids, ?callable $map = null) => array_values(array_filter(array_map(function ($id) use ($field, $map, $prio) {
                $v = $this->val($id, $field);
                if ($v === null || $v === '') return null;
                $v = $map !== null ? $map($v, $id) : $v;
                return $v === null ? null : self::obs($v, [$this->src($id, $field)], $prio($id));
            }, $ids)));
            $provider = $this->supplierOf($all) ?: ($c['existing']['provider'] ?? '');
            $providerSrc = array_values(array_filter(array_map(fn($id) => $this->docs[$id]['supplier'] !== '' && self::sameSupplier($this->docs[$id]['supplier'], $provider)
                ? $this->src($id, 'supplier') : null, $all)));
            $startObs = [];
            foreach ($all as $id) if (in_array($this->docs[$id]['type'], ['contract_confirmation', 'supply_start'], true) && $this->date($id, 'supply_start') === $c['start']) {
                $startObs[] = self::obs($c['start'], [$this->src($id, 'supply_start')], $prio($id));
            }
            $start = self::field('start', 'date', $startObs, ['core' => true, 'basis' => $c['basis']])
                ?? ['name' => 'start', 'kind' => 'date', 'value' => $c['start'], 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'core' => true, 'estimated' => false, 'basis' => $c['basis']];
            // Versorger: verschiedene Schreibweisen sind derselbe (keine Entscheidung); fehlt er ganz, fragt der Eintrag
            $providerCheck = $provider === '' || ($providerSrc !== [] && array_filter($providerSrc, fn($s) => !$s['flagged']) === []
                && count(array_unique(array_column($providerSrc, 'doc'))) < 2);
            $fields = [
                ['name' => 'provider', 'kind' => 'text', 'value' => $provider, 'check' => $providerCheck, 'reasons' => $provider === '' ? ['empty'] : ($providerCheck ? array_values(array_unique(array_merge(...array_column($providerSrc, 'reasons')))) : []),
                    'sources' => self::sources([self::obs($provider, $providerSrc)]), 'status' => 'new', 'core' => true, 'estimated' => false],
                $start,
                self::field('tariff_name', 'text', $o('tariff_name', $conf)),
            ];
            if ($c['end'] !== null) {
                $fields[] = isset($c['end_field']) ? $c['end_field'] + ['basis' => 'document']
                    : ['name' => 'end', 'kind' => 'date', 'value' => $c['end'], 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'estimated' => false, 'basis' => $c['end_basis']];
            }
            $termEnd = function ($v) use ($c) {
                $n = is_numeric($v) ? (int)$v : 0;
                if ($n <= 0 || $n > 120) return null;
                return Proposals::termEnd($c['start'], $n);
            };
            $notStartBefore = fn($v) => is_string($v) && $v >= $c['start'] ? $v : null;
            array_push($fields,
                self::field('min_term_end', 'date', $o('min_term_months', $conf, $termEnd)),
                self::field('notice', 'text', $o('notice_period', $conf, fn($v) => is_string($v) && (Proposals::notice($v)['months'] !== null || Proposals::notice($v)['days'] !== null) ? $v : null)),
                self::field('price_guarantee_until', 'date', $o('price_guarantee_until', $conf, $notStartBefore)),
                self::field('signup_bonus_eur', 'money', $o('bonus', $conf, fn($v) => is_numeric($v) && (float)$v >= 0 ? (float)$v : null)),
                $this->preferNewest(self::field('contract_number', 'text', $o('contract_number', $all))),
                $this->preferNewest(self::field('customer_number', 'text', $o('customer_number', $all))),
            );
            $fields = array_values(array_filter($fields));
            $status = $c['status'];
            if ($c['existing'] !== null) {
                $ex = $c['existing'];
                $map = ['provider' => 'provider', 'start' => 'start', 'tariff_name' => 'tariff_name', 'end' => 'end', 'min_term_end' => 'min_term_end',
                    'price_guarantee_until' => 'price_guarantee_until', 'signup_bonus_eur' => 'signup_bonus_eur', 'contract_number' => 'contract_number', 'customer_number' => 'customer_number'];
                foreach ($fields as $i => $f) {
                    $cur = $f['name'] === 'notice' ? (($ex['notice_period_months'] ?? null) !== null || ($ex['notice_period_days'] ?? null) !== null ? 'set' : null) : ($ex[$map[$f['name']] ?? ''] ?? null);
                    if ($f['name'] === 'provider' || $f['name'] === 'start') { $fields[$i]['status'] = 'exists'; continue; }
                    if ($cur === null || $cur === '') continue;
                    if ($f['name'] === 'notice' || self::same($f['kind'], $cur, $f['value']) || ($f['kind'] === 'text' && Proposals::sameId((string)$cur, (string)$f['value']))) $fields[$i]['status'] = 'exists';
                    else self::conflict($fields[$i], $cur);
                }
                $status = 'exists';
            }
            $this->cands[$k]['provider'] = $provider;
            $atts = array_values(array_unique(array_filter(array_map(fn($id) => in_array($this->docs[$id]['type'], ['invoice'], true) ? null : ($this->docs[$id]['att'] ?: null),
                array_keys(array_filter($this->docs, fn($d) => $d['cand'] === $k && $d['dup'] === null))))));
            $this->items[] = [
                'kind' => 'contract', 'utility' => $u, 'key' => $k, 'meter_key' => $c['mp'], 'contract_key' => $k,
                'contract_id' => $c['existing']['id'] ?? null, 'status' => $status, 'detail' => $c['detail'],
                'label_key' => 'inboxDraft.item.contract.' . ($c['existing'] !== null ? 'existing' : 'new'),
                'params' => ['provider' => $provider, 'from' => $c['start'], 'to' => $c['end'], 'tariff' => self::fieldValue($fields, 'tariff_name') ?? ''],
                'fields' => $fields, 'attachments' => $atts, 'docs' => array_values(array_unique([...$c['docs'], ...array_keys(array_filter($this->docs, fn($d) => $d['cand'] === $k && $d['dup'] === null && $d['type'] !== 'invoice'))])),
            ];
        }
        // bisheriger Vertrag am vorhandenen Zähler ohne Ende: endet am Tag vor dem ersten neuen (nur als Angebot)
        foreach ($firstByMp as $mpKey => $k) {
            $mp = $this->mps[$mpKey];
            $c = $this->cands[$k];
            if ($mp['meter_id'] === null || $mp['action'] === 'adopt' || $c['existing'] !== null) continue;
            $dayBefore = date('Y-m-d', (int)strtotime($c['start'] . ' -1 day'));
            $meter = $this->meters->get($u, $mp['meter_id']);
            foreach ($meter !== null ? $this->existingContracts($u, $meter) : [] as $prev) {
                if ((string)($prev['start'] ?? '') >= $c['start']) continue;
                if (!empty($prev['end']) && (string)$prev['end'] <= $dayBefore) continue;
                $this->items[] = ['kind' => 'contract_end', 'utility' => $u, 'key' => 'end:' . $prev['id'], 'contract_id' => (string)$prev['id'], 'optional' => true,
                    'label_key' => 'inboxDraft.item.contractEnd', 'params' => ['provider' => (string)($prev['provider'] ?? ''), 'date' => $dayBefore],
                    'fields' => [['name' => 'end', 'kind' => 'date', 'value' => $dayBefore, 'check' => false, 'reasons' => [], 'sources' => [], 'status' => 'new', 'core' => true, 'estimated' => false, 'basis' => 'successor']],
                    'docs' => $c['docs']];
            }
        }
    }

    /**
     * Preis aus einem Dokument in ct/kWh bzw. € **brutto** (bei Listen der
     * Eintrag `$item`) — die **eine** Stelle, an der der Entwurf Preise liest:
     * Bestätigung, Preisänderung, Rechnung. Eine Rechnung mit Nettopreis und
     * gelesenem Steuersatz zählt umgerechnet, mit derselben Umrechnung wie der
     * Posteingang (Evidence::itemTax/fieldTax, grossOf); Bestätigung und
     * Preisänderung sind brutto (Schema). Ein bestätigter oder korrigierter Wert
     * steht in derselben Basis wie gedruckt und wird genauso umgerechnet.
     */
    private function priceOf(string $docId, string $field, ?int $item = null): ?float
    {
        $e = $this->docs[$docId]['entry'];
        $fields = (array)($e['extraction']['fields'] ?? []);
        $invoice = $this->docs[$docId]['type'] === 'invoice';
        if ($item !== null) {
            $it = (array)($fields[$field]['items'][$item] ?? []);
            $v = $it['value']['ct'] ?? null;
            if (!is_numeric($v)) return null;
            return $invoice ? Evidence::grossOf((float)$v, Evidence::itemTax($it)) : (float)$v;
        }
        // bestätigt heißt: der gedruckte Wert gilt; korrigiert: ein Wert in derselben Basis wie gedruckt —
        // beide werden wie gedruckt umgerechnet (eine Lesart mit Proposals)
        $v = $this->val($docId, $field);
        if (!is_numeric($v)) return null;
        return $invoice ? Evidence::grossOf((float)$v, Evidence::fieldTax($fields, $field)) : (float)$v;
    }

    /**
     * Gelesener Steuersatz eines Preises (wie im Posteingang): an der Rechnung
     * aus Zitat und Hilfsfeldern, an Bestätigung und Preisänderung aus dem Zitat
     * des Preises oder dem Feld `vat_pct` (brutto laut Schema). Null, wenn kein
     * Satz gelesen ist — vermutet wird nie.
     *
     * @return array{vat_pct: float, net: bool}|null
     */
    private function priceTax(string $docId, string $field, ?int $item = null): ?array
    {
        $fields = (array)($this->docs[$docId]['entry']['extraction']['fields'] ?? []);
        if ($this->docs[$docId]['type'] === 'invoice') {
            return $item !== null ? Evidence::itemTax((array)($fields[$field]['items'][$item] ?? [])) : Evidence::fieldTax($fields, $field);
        }
        $quotes = implode(' | ', array_filter([$fields[$field]['quote'] ?? null, $fields['vat_pct']['quote'] ?? null], 'is_string'));
        return Evidence::taxOf($quotes, 'gross', $fields['vat_pct']['value'] ?? null);
    }

    /** Preisfelder, deren Belegstelle einen Steuersatz tragen kann. */
    private const PRICE_FIELDS = ['working_price', 'base_price', 'working_price_new', 'base_price_new', 'working_prices'];

    /**
     * Gleicher Nettopreis wie das Glied der Kette, nur ein anderer Steuersatz?
     * Beide Sätze müssen gelesen sein (priceTax), umgerechnet wird wie im
     * Posteingang (Evidence::netOf); `$known` ist der Bruttopreis des Glieds.
     *
     * @param list<array<string,mixed>> $chainObs
     * @param array{tax: ?array, net: ?float} $x
     */
    private function sameNetOtherRate(array $chainObs, float $known, array $x): bool
    {
        if ($x['tax'] === null || $x['net'] === null) return false;
        foreach ($chainObs as $o) {
            foreach ($o['src'] as $s) {
                if (!in_array($s['field'], self::PRICE_FIELDS, true)) continue;
                $tax = $this->priceTax($s['doc'], $s['field'], $s['item'] ?? null);
                if ($tax === null || abs($tax['vat_pct'] - $x['tax']['vat_pct']) < 0.001) continue;
                if (abs(Evidence::netOf($known, ['vat_pct' => $tax['vat_pct'], 'net' => false]) - $x['net']) <= Proposals::PRICE_TOL) return true;
            }
        }
        return false;
    }

    /**
     * Ein Rechnungspreis mit belegt erkanntem netto/brutto: Der Zweifel „Preis
     * von der Rechnung“ entfällt. Andere Zweifel (Belegstelle, Wert) bleiben.
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private static function withoutInvoiceDoubt(array $o): array
    {
        if (!$o['flagged'] || array_diff($o['reasons'], ['price_from_invoice']) !== []) return $o;
        return ['flagged' => false, 'reasons' => []] + $o;
    }

    /**
     * Merkmal eines Rechnungspreises, der netto mit gelesenem Satz dasteht: die
     * Oberfläche zeigt am Feld Basis und Ergebnis (lib/price-basis.js).
     *
     * @param array{vat_pct: float, net: bool}|null $tax
     * @return array<string,mixed>
     */
    private static function netMark(?array $tax): array
    {
        return $tax !== null && $tax['net'] ? ['net' => ['vat_pct' => $tax['vat_pct']]] : [];
    }

    /** Grundpreis je Monat (aus einem Jahrespreis umgerechnet), über priceOf(). */
    private function monthlyPrice(string $docId, string $field): ?float
    {
        $v = $this->priceOf($docId, $field);
        if ($v === null) return null;
        return $this->val($docId, 'base_price_period') === 'year' ? round($v / 12, 4) : $v;
    }

    /**
     * Preise (Arbeits- und Grundpreis) und Abschläge je Vertrag als Kette.
     * Bestätigung und Preisänderungen bilden sie und sind vorbelegt. Ein
     * Rechnungspreis ist nur ein Beleg: Passt er zur Kette, bestätigt er sie;
     * weicht er ab, steht das unter „Aufgefallen“ — kein Glied der Kette. Nur
     * wo kein anderes Dokument für den Zeitraum einen Preis nennt, wird der
     * Rechnungspreis ein Glied — dann zu prüfen.
     */
    private function chainItems(string $u): void
    {
        foreach ($this->cands as $k => $c) {
            if ($c['utility'] !== $u) continue;
            $docs = array_keys(array_filter($this->docs, fn($d) => $d['cand'] === $k && $d['dup'] === null && $d['superseded'] === null));
            $end = $c['end'] ?? '9999-12-31';
            $inRange = fn(?string $f) => $f !== null && $f >= $c['start'] && $f <= $end;
            $prio = fn(string $id) => self::PRIO[$this->docs[$id]['type']] ?? 9;
            $monthly = fn(string $id, string $field) => $this->monthlyPrice($id, $field);
            $rows = ['wp' => [], 'bp' => [], 'ap' => []];
            foreach ($docs as $id) {
                $t = $this->docs[$id]['type'];
                if ($t === 'contract_confirmation') {
                    // die Preise der Bestätigung gelten ab ihrem Beginn (der Vertrag kann früher beginnen: Rechnungen davor)
                    $cs = $this->date($id, 'supply_start');
                    // (Beginn wenige Tage neben dem Bestand, matchExistingContracts: ab dem Beginn des Bestands)
                    $at = $cs !== null && $cs === ($c['near_start'] ?? null) ? $c['start'] : ($cs !== null && $inRange($cs) ? $cs : $c['start']);
                    if (($v = $this->priceOf($id, 'working_price')) !== null) $rows['wp'][$at][] = self::obs($v, [$this->src($id, 'working_price')], 0);
                    if (($v = $monthly($id, 'base_price')) !== null) $rows['bp'][$at][] = self::obs($v, [$this->src($id, 'base_price'), $this->src($id, 'base_price_period')], 0);
                    if (($v = $this->num($id, 'advance')) !== null) $rows['ap'][$at][] = self::obs($v, [$this->src($id, 'advance')], 0);
                }
                if ($t === 'price_change' && $inRange($f = $this->date($id, 'valid_from'))) {
                    if (($v = $this->priceOf($id, 'working_price_new')) !== null) $rows['wp'][$f][] = self::obs($v, [$this->src($id, 'working_price_new'), $this->src($id, 'valid_from')], 2);
                    if (($v = $monthly($id, 'base_price_new')) !== null) $rows['bp'][$f][] = self::obs($v, [$this->src($id, 'base_price_new'), $this->src($id, 'base_price_period'), $this->src($id, 'valid_from')], 2);
                }
                if ($t === 'advance_change' && $inRange($f = $this->date($id, 'valid_from')) && ($v = $this->num($id, 'advance_new')) !== null) {
                    $rows['ap'][$f][] = self::obs($v, [$this->src($id, 'advance_new'), $this->src($id, 'valid_from')], 3);
                }
                if ($t === 'invoice') {
                    if (($v = $this->num($id, 'advance_new')) !== null) {
                        $f = $this->date($id, 'advance_new_from');
                        $assumed = $f === null;
                        $to = $this->date($id, 'period_to');
                        if ($assumed && $to !== null) $f = date('Y-m-01', (int)strtotime($to . ' +1 day +1 month'));
                        if ($f !== null && $f >= $c['start'] && $f <= $end) {
                            $o = self::obs($v, array_filter([$this->src($id, 'advance_new'), $assumed ? null : $this->src($id, 'advance_new_from')]), 5);
                            if ($assumed) { $o['flagged'] = true; $o['reasons'][] = 'assumed_date'; }
                            $rows['ap'][$f][] = $o;
                        }
                    }
                }
            }
            // Rechnungspreise gegen die Kette
            $inv = ['wp' => [], 'bp' => []];
            foreach ($docs as $id) {
                if ($this->docs[$id]['type'] !== 'invoice') continue;
                foreach ((array)($this->docs[$id]['entry']['extraction']['fields']['working_prices']['items'] ?? []) as $i => $item) {
                    $v = $item['value'] ?? [];
                    $ct = $this->priceOf($id, 'working_prices', (int)$i);
                    if ($ct === null || !is_string($v['from'] ?? null) || !$inRange($v['from'])) continue;
                    $tax = $this->priceTax($id, 'working_prices', (int)$i);
                    $inv['wp'][] = ['from' => $v['from'], 'doc' => $id, 'o' => self::obs($ct, [$this->src($id, 'working_prices', (int)$i)], 5) + self::netMark($tax),
                        'tax' => $tax, 'net' => $tax !== null ? Evidence::netOf((float)$v['ct'], $tax) : null];
                }
                $from = $this->date($id, 'period_from');
                if (($v = $monthly($id, 'base_price')) !== null && $from !== null) {
                    $f = max($from, $c['start']);
                    $tax = $this->priceTax($id, 'base_price');
                    $raw = $this->num($id, 'base_price');
                    $net = $tax !== null && $raw !== null ? Evidence::netOf($raw, $tax) / ($this->val($id, 'base_price_period') === 'year' ? 12 : 1) : null;
                    if ($inRange($f)) $inv['bp'][] = ['from' => $f, 'doc' => $id, 'o' => self::obs($v, [$this->src($id, 'base_price'), $this->src($id, 'base_price_period')], 5) + self::netMark($tax),
                        'tax' => $tax, 'net' => $net];
                }
            }
            foreach (['wp', 'bp'] as $kind) {
                $chain = $rows[$kind];
                ksort($chain);
                foreach ($inv[$kind] as $x) {
                    // der Preis, den Bestätigung oder Preisänderung für diesen Tag nennen (Rechnung brutto, priceOf)
                    $at = null;
                    foreach ($chain as $f => $_) if ($f <= $x['from']) $at = $f;
                    if ($at !== null) {
                        $known = self::field("x:$at", $kind === 'wp' ? 'price_ct' : 'money', $chain[$at]);
                        if ($known !== null && abs((float)$known['value'] - (float)$x['o']['value']) <= Proposals::PRICE_TOL) {
                            // passt zur Kette (wie im Posteingang auf Proposals::PRICE_TOL, ein umgerechneter
                            // Nettopreis rundet anders): bestätigt sie mit dem Wert der Kette
                            $rows[$kind][$at][] = ['value' => $known['value']] + $x['o'];
                        } elseif ($known !== null && $this->sameNetOtherRate($chain[$at], (float)$known['value'], $x)) {
                            // gleicher Nettopreis, nur ein anderer Steuersatz (beide gelesen): kein Widerspruch,
                            // eine neue Preiszeile ab dem Tag, den die Rechnung nennt — brutto, vorbelegt. Am Tag
                            // des Glieds selbst (etwa am Vertragsbeginn) ersetzt sie es; dessen Belege bleiben
                            $o = ['flagged' => false, 'reasons' => [], 'vat_changed' => true] + $x['o'];
                            if ($x['from'] === $at) {
                                $o['src'] = [...$o['src'], ...array_merge(...array_map(fn($r) => $r['src'], $rows[$kind][$at]))];
                                $rows[$kind][$at] = [$o];
                            } else {
                                $rows[$kind][$x['from']][] = $o;
                            }
                            $chain[$x['from']] = [$o];
                            ksort($chain);
                        } elseif ($known !== null) {
                            // weicht ab: ein Befund, kein Glied der Kette (die Kette bleibt vorbelegt)
                            $this->findings[] = ['kind' => 'invoice_' . $kind, 'utility' => $u, 'docs' => [$x['doc']],
                                'params' => ['name' => $this->docs[$x['doc']]['name'], 'date' => $x['from'], 'invoice' => $x['o']['value'], 'chain' => $known['value']]];
                        }
                        continue;
                    }
                    // kein anderes Dokument nennt einen Preis für den Zeitraum: der Rechnungspreis ist das Glied —
                    // vorbelegt, wenn netto/brutto belegt erkannt ist (Steuersatz gelesen, priceTax); sonst zu prüfen
                    $rows[$kind][$x['from']][] = $x['tax'] !== null ? self::withoutInvoiceDoubt($x['o']) : $x['o'];
                }
            }
            $ex = $c['existing'];
            $mk = function (string $kind, string $list, string $key, string $vk) use ($rows, $ex) {
                $fields = [];
                $r = $rows[$kind];
                ksort($r);
                foreach ($r as $from => $obs) {
                    $f = self::field("$kind:$from", $vk, $obs, ['from' => (string)$from, 'list' => $list, 'key' => $key]);
                    if ($f === null) continue;
                    // neue Preiszeile wegen eines anderen Steuersatzes (gleicher Nettopreis): mit Herkunft
                    if (array_filter($obs, fn($o) => !empty($o['vat_changed'])) !== []) $f['basis'] = 'vat_changed';
                    // nur aus Nettopreisen der Rechnung: am Feld Basis und Ergebnis zeigen
                    if (array_filter($obs, fn($o) => empty($o['net'])) === []) $f['net'] = $obs[0]['net'];
                    if ($ex !== null) {
                        foreach ((array)($ex[$list] ?? []) as $row) {
                            if ((string)($row['from'] ?? '') !== (string)$from) continue;
                            if (self::same($vk, (float)($row[$key] ?? 0), $f['value']) && !isset($f['options'])) $f['status'] = 'exists';
                            else self::conflict($f, (float)($row[$key] ?? 0));
                        }
                        // Rechnungspreis wie im Vertrag an dem Tag (Marke des Posteingangs
                        // `price_matches`): schon vorhanden, keine neue Zeile
                        $matches = array_filter($obs, fn($o) => array_filter($o['src'], fn($s) => in_array('price_matches', $s['hints'] ?? [], true)) !== []) !== [];
                        if ($f['status'] === 'new' && !isset($f['options']) && $matches) $f['status'] = 'exists';
                    }
                    $fields[] = $f;
                }
                return $fields;
            };
            $prices = [];
            if (Proposals::standardContract($u)) {
                $prices = [...$mk('wp', 'working_prices', 'ct_per_kwh', 'price_ct'), ...(Utilities::isFeedIn($u) ? [] : $mk('bp', 'base_prices', 'eur_per_month', 'money'))];
            }
            $advances = Utilities::isFeedIn($u) ? [] : $mk('ap', 'advance_payments', 'amount_eur', 'money');
            $current = ($c['end'] === null || $c['end'] >= $this->today);
            if ($prices !== []) {
                // an einem vorhandenen Vertrag: neue Zeilen sind eine Ergänzung (nie vorbelegt)
                $this->items[] = ['kind' => 'prices', 'utility' => $u, 'key' => "$k#p", 'contract_key' => $k, 'meter_key' => $c['mp'],
                    'status' => $ex !== null ? 'exists' : 'new', 'label_key' => 'inboxDraft.item.prices', 'params' => ['provider' => $c['provider'] ?? '', 'count' => count($prices)], 'fields' => $prices];
            }
            if (Proposals::standardContract($u)) {
                // Ohne Arbeits- oder Grundpreis nach dem Übernehmen (nur Vorbelegtes): „Fehlt noch“ —
                // auch für beendete Verträge, sonst urteilt „Rechnung prüfen“ dort „keine Preise“
                $taken = fn(string $p, string $list) => !empty($ex[$list]) || array_filter($prices,
                    fn($f) => str_starts_with((string)$f['name'], "$p:") && ($f['status'] === 'exists' || (!$f['check'] && $f['status'] !== 'conflict'))) !== [];
                $asked = fn(string $p) => array_filter($prices, fn($f) => str_starts_with((string)$f['name'], "$p:")) !== [];
                $need = array_values(array_filter(['wp', 'bp'], fn($p) => !($p === 'bp' && Utilities::isFeedIn($u))
                    && !$taken($p, $p === 'wp' ? 'working_prices' : 'base_prices')));
                if ($need !== []) {
                    $kind = array_filter($need, $asked) !== [] ? 'prices_unconfirmed' : (count($need) === 2 ? 'prices' : ($need[0] === 'wp' ? 'working_price' : 'base_price'));
                    $this->missing[] = ['kind' => $kind, 'utility' => $u, 'key' => $k, 'params' => ['provider' => $c['provider'] ?? ''], 'which' => $need,
                        'href' => "#/utility/$u/contracts"];
                }
                // Abschnitte ohne Preis: der erste (vorbelegte oder vorhandene) Preis beginnt nach dem
                // Vertragsbeginn — etwa wenn der Beginn auf die erste Rechnung vorgezogen wurde
                foreach (['wp', 'bp'] as $p) {
                    if (in_array($p, $need, true) || ($p === 'bp' && Utilities::isFeedIn($u))) continue;
                    $froms = array_map(fn($row) => (string)($row['from'] ?? ''), (array)($ex[$p === 'wp' ? 'working_prices' : 'base_prices'] ?? []));
                    foreach ($prices as $f) {
                        if (str_starts_with((string)$f['name'], "$p:") && ($f['status'] === 'exists' || (!$f['check'] && $f['status'] !== 'conflict'))) $froms[] = (string)$f['from'];
                    }
                    $froms = array_filter($froms);
                    if ($froms === [] || min($froms) <= $c['start']) continue;
                    $this->missing[] = ['kind' => $p === 'wp' ? 'working_price_gap' : 'base_price_gap', 'utility' => $u, 'key' => $k,
                        'params' => ['provider' => $c['provider'] ?? '', 'from' => $c['start'], 'to' => date('Y-m-d', (int)strtotime(min($froms) . ' -1 day'))],
                        'href' => "#/utility/$u/contracts"];
                }
            } elseif ($u === 'wasser' && $current) {
                $this->missing[] = ['kind' => 'water_prices', 'utility' => $u, 'key' => $k, 'params' => ['provider' => $c['provider'] ?? ''], 'href' => "#/utility/$u/contracts"];
            }
            if ($advances !== []) {
                $this->items[] = ['kind' => 'advances', 'utility' => $u, 'key' => "$k#a", 'contract_key' => $k, 'meter_key' => $c['mp'],
                    'status' => $ex !== null ? 'exists' : 'new', 'label_key' => 'inboxDraft.item.advances', 'params' => ['provider' => $c['provider'] ?? '', 'count' => count($advances)], 'fields' => $advances];
            } elseif (Utilities::hasAdvancePaymentContracts($u) && $current && empty($ex['advance_payments'])) {
                $this->missing[] = ['kind' => 'advance', 'utility' => $u, 'key' => $k, 'params' => ['provider' => $c['provider'] ?? ''], 'href' => "#/utility/$u/contracts"];
            }
        }
    }

    // ── Rechnungen, Gas-Umrechnung, Termine ──────────────────────────────

    /**
     * Rechnungen je Zähler und Zeitraum (zwei Dokumente mit demselben Zeitraum:
     * eine Rechnung, verschiedene Angaben sind Entscheidungen).
     *
     * @param list<string> $ids
     */
    private function billItems(string $u, array $ids): void
    {
        if (!Utilities::supportsBillCheck($u)) return;
        $groups = [];
        foreach ($ids as $id) {
            $d = $this->docs[$id];
            $from = $this->date($id, 'period_from'); $to = $this->date($id, 'period_to');
            if ($d['type'] !== 'invoice' || $d['mp'] === null || $from === null || $to === null || $from > $to) continue;
            $groups[$d['mp'] . "|$from|$to"][] = $id;
        }
        foreach ($groups as $g => $list) {
            [$mpKey, $from, $to] = explode('|', $g);
            // Rechnungskorrektur (markCorrections): die Werte aus der gültigen Fassung, die überholte ist Beleg
            $all = $list;
            $fresh = array_values(array_filter($list, fn($id) => $this->docs[$id]['superseded'] === null));
            if ($fresh !== [] && count($fresh) < count($list)) $list = $fresh;
            $o = fn(string $field, ?callable $map = null) => array_values(array_filter(array_map(function ($id) use ($field, $map) {
                $v = $map !== null ? $map($id) : $this->num($id, $field);
                return $v === null ? null : self::obs($v, $map !== null ? [$this->src($id, 'result_amount'), $this->src($id, 'result_kind')] : [$this->src($id, $field)], 5);
            }, $list)));
            $periodObs = fn(string $field, string $value) => array_map(fn($id) => self::obs($value, [$this->src($id, $field)], 5), $all);
            $result = function (string $id): ?float {
                $r = $this->num($id, 'result_amount');
                if ($r === null) return null;
                return round(($this->val($id, 'result_kind') === 'credit' ? -1 : 1) * abs($r), 2);
            };
            $advances = function (string $id): ?float {
                $t = $this->num($id, 'advances_total');
                if ($t !== null) return $t;
                $s = $this->docs[$id]['entry']['extraction']['fields']['advances_paid']['sum'] ?? null;
                return is_numeric($s) ? (float)$s : null;
            };
            $advObs = array_values(array_filter(array_map(function ($id) use ($advances) {
                $v = $advances($id);
                return $v === null ? null : self::obs($v, [$this->src($id, $this->num($id, 'advances_total') !== null ? 'advances_total' : 'advances_paid')], 5);
            }, $list)));
            $kindObs = array_values(array_filter(array_map(fn($id) => in_array($this->val($id, 'kind'), ['annual', 'final'], true)
                ? self::obs((string)$this->val($id, 'kind'), [$this->src($id, 'kind')], 5) : null, $list)));
            $fields = array_values(array_filter([
                self::field('period_from', 'date', $periodObs('period_from', $from), ['core' => true]),
                self::field('period_to', 'date', $periodObs('period_to', $to), ['core' => true]),
                self::field('kind', 'enum', $kindObs),
                self::field('consumption', 'number', $o('consumption'), ['unit' => Utilities::get($u)['consumption_unit'] ?? '']),
                self::field('total_amount', 'money', $o('total_amount')),
                self::field('advances_paid', 'money', $advObs),
                self::field('result', 'money', $o('result_amount', $result)),
            ]));
            $res = self::fieldValue($fields, 'result');
            $cand = null;
            foreach ($all as $id) if ($this->docs[$id]['cand'] !== null) { $cand = $this->docs[$id]['cand']; break; }
            $item = ['kind' => 'bill', 'utility' => $u, 'key' => "bill:$g", 'meter_key' => $mpKey, 'contract_key' => $cand,
                'label_key' => 'inboxDraft.item.bill', 'params' => ['from' => $from, 'to' => $to, 'result' => $res],
                'fields' => $fields, 'attachments' => array_values(array_filter(array_map(fn($id) => $this->docs[$id]['att'] ?: null, $all))), 'docs' => $all,
                'book' => $res !== null && (float)$res != 0.0 && Utilities::hasSpecialPayments($u)];
            $mp = $this->mps[$mpKey];
            if ($mp['meter_id'] !== null && $mp['action'] === 'existing') {
                foreach ($this->bills->list($u, $mp['meter_id']) as $b) {
                    if (($b['period_from'] ?? '') === $from && ($b['period_to'] ?? '') === $to) {
                        $item['status'] = 'exists';
                        $item['bill_id'] = (string)$b['id'];
                        $item['booked'] = !empty($b['special_payment_id']);
                        $inv = (array)($b['invoice'] ?? []);
                        $map = ['consumption' => $u === 'wasser' ? 'volume_m3' : 'energy_kwh', 'total_amount' => 'amount_eur', 'advances_paid' => 'advances_paid_eur', 'result' => 'result_eur'];
                        foreach ($item['fields'] as $i => $f) {
                            if (in_array($f['name'], ['period_from', 'period_to'], true) || ($f['name'] === 'kind' && ($b['kind'] ?? null) === $f['value'])) { $item['fields'][$i]['status'] = 'exists'; continue; }
                            $cur = $map[$f['name']] ?? null;
                            if ($cur === null || !isset($inv[$cur])) continue;
                            if (self::same('money', (float)$inv[$cur], $f['value'])) $item['fields'][$i]['status'] = 'exists';
                            else self::conflict($item['fields'][$i], (float)$inv[$cur]);
                        }
                        break;
                    }
                    if (($b['period_from'] ?? '') <= $to && $from <= ($b['period_to'] ?? '')) {
                        $item['status'] = 'overlap';
                        $item['detail'] = ['bill_id' => (string)$b['id'], 'period_from' => $b['period_from'], 'period_to' => $b['period_to']];
                    }
                }
            }
            $this->items[] = $item;
        }
    }

    /** @param list<string> $ids */
    private function gasFactorItems(array $ids): void
    {
        $rows = [];
        foreach ($ids as $id) {
            if ($this->docs[$id]['type'] !== 'invoice') continue;
            foreach ((array)($this->docs[$id]['entry']['extraction']['fields']['gas_factors']['items'] ?? []) as $i => $item) {
                $v = $item['value'] ?? [];
                if (!is_numeric($v['z'] ?? null) || !is_numeric($v['hs'] ?? null) || !is_string($v['from'] ?? null) || !Dates::isIsoDate($v['from'])) continue;
                $rows[$v['from']][] = self::obs(['z' => (float)$v['z'], 'hs' => (float)$v['hs']], [$this->src($id, 'gas_factors', $i)], 5);
            }
        }
        if ($rows === []) return;
        ksort($rows);
        $list = ConversionFactorService::normalizeList($this->settings->get('gas_conversion_factors', []), null, false);
        $fields = [];
        foreach ($rows as $from => $obs) {
            $f = self::field("gf:$from", 'factor', $obs, ['from' => (string)$from]);
            if ($f === null) continue;
            foreach ($list as $x) {
                if (($x['from'] ?? null) !== $from) continue;
                $cur = ['z' => (float)($x['zustandszahl'] ?? 0), 'hs' => (float)($x['brennwert'] ?? 0)];
                if (self::same('factor', $cur, $f['value']) && !isset($f['options'])) $f['status'] = 'exists';
                else self::conflict($f, $cur);
            }
            $fields[] = $f;
        }
        $this->items[] = ['kind' => 'gas_factor', 'utility' => 'gas', 'key' => 'gas_factors', 'label_key' => 'inboxDraft.item.gasFactor',
            'params' => ['count' => count($fields)], 'fields' => $fields];
    }

    /**
     * Termine: Zählerstand melden und Sonderkündigungsrecht — nur, was noch
     * kommt (aus alter Post entstünden sonst lauter verstrichene Termine).
     *
     * @param list<string> $ids
     */
    private function reminderItems(string $u, array $ids): void
    {
        $existing = array_column($this->reminders->list(), 'id');
        foreach ($ids as $id) {
            $d = $this->docs[$id];
            [$label, $field, $params] = match ($d['type']) {
                'reading_request' => ['reading', 'requested_by', ['meter' => $d['serial'] !== '' ? $d['serial'] : $this->i18n->defaultMeterName($u)]],
                'price_change' => ['specialTermination', 'special_termination_until', ['provider' => $d['supplier']]],
                default => [null, null, []],
            };
            if ($label === null || ($due = $this->date($id, $field)) === null || $due < $this->today) continue;
            $f = self::field('due', 'date', [self::obs($due, [$this->src($id, $field)], self::PRIO[$d['type']] ?? 9)], ['core' => true]);
            $status = 'new';
            foreach ((array)($d['entry']['applied'] ?? []) as $a) {
                if (($a['kind'] ?? '') === 'reminder' && ($a['key'] ?? '') === "$label@$due" && in_array($a['ref']['id'] ?? '', $existing, true)) $status = 'exists';
            }
            $this->items[] = ['kind' => 'reminder', 'utility' => $u, 'key' => "rem:$label@$due:" . self::norm(implode('|', $params)), 'status' => $status,
                'label_key' => 'inboxDraft.item.reminder.' . $label, 'params' => ['date' => $due] + $params, 'reminder' => ['label' => $label, 'params' => $params],
                'fields' => $f !== null ? [$f] : [], 'docs' => [$id]];
        }
    }

    // ── Felder ───────────────────────────────────────────────────────────

    /**
     * Beleg eines Felds (bei Listenfeldern eines Eintrags) mit Marke. Die Marke
     * ist die des Posteingangs nach dem heutigen Bestand (Proposals::marks →
     * Evidence::refine, in collect()): zweifelhaft nur mit Status `check`;
     * Hinweise (Evidence::HINTS: voraussichtlich, gestützt, Preis wie im
     * Vertrag, fehlt) sind kein Zweifel. Keine eigene Markenregel.
     */
    private function src(string $docId, string $field, ?int $item = null): array
    {
        $d = $this->docs[$docId];
        $e = $d['entry'];
        $f = (array)($e['extraction']['fields'][$field] ?? []);
        if ($item !== null) $f = (array)($f['items'][$item] ?? []);
        $edited = $item === null && array_key_exists($field, (array)($e['edits'] ?? []));
        $flagged = !$edited && ($f['status'] ?? '') === 'check';
        $reasons = $flagged ? array_values(array_diff((array)($f['reasons'] ?? []), Evidence::HINTS)) : [];
        return ['doc' => $docId, 'name' => $d['name'], 'type' => $d['type'], 'field' => $field, 'item' => $item,
            'quote' => is_string($f['quote'] ?? null) ? $f['quote'] : null, 'page' => is_int($f['page'] ?? null) ? $f['page'] : null,
            'flagged' => $flagged, 'reasons' => $reasons,
            'hints' => $edited ? [] : array_values(array_intersect((array)($f['reasons'] ?? []), Evidence::HINTS)),
            'estimated' => !$edited && in_array('estimated', (array)($f['reasons'] ?? []), true)];
    }

    /** Eine Beobachtung: Wert mit Belegen und Rang. */
    private static function obs(mixed $value, array $sources, int $prio = 9): array
    {
        $flagged = false; $reasons = []; $est = false;
        foreach ($sources as $s) {
            if (!empty($s['flagged'])) { $flagged = true; foreach ($s['reasons'] as $r) $reasons[$r] = true; }
            $est = $est || !empty($s['estimated']);
        }
        return ['value' => $value, 'src' => array_values($sources), 'prio' => $prio, 'flagged' => $flagged, 'reasons' => array_keys($reasons), 'estimated' => $est];
    }

    /**
     * Ein Feld aus Beobachtungen: gleiche Werte zusammen (bestätigt, wenn ein
     * Beleg unmarkiert ist oder zwei Dokumente übereinstimmen — außer bei
     * Rechnungspreisen), verschiedene als Entscheidung (`options`, der Beleg mit
     * dem besten Rang zuerst).
     *
     * @param list<array<string,mixed>> $obs
     */
    private static function field(string $name, string $kind, array $obs, array $extra = []): ?array
    {
        $opts = [];
        foreach ($obs as $o) {
            if ($o['value'] === null || $o['value'] === '') continue;
            $hit = null;
            foreach ($opts as $i => $op) if (self::same($kind, $op['value'], $o['value'])) { $hit = $i; break; }
            if ($hit === null) { $opts[] = ['value' => $o['value'], 'obs' => [$o], 'prio' => $o['prio'], 'n' => count($opts)]; continue; }
            $opts[$hit]['obs'][] = $o;
            $opts[$hit]['prio'] = min($opts[$hit]['prio'], $o['prio']);
        }
        if ($opts === []) return null;
        usort($opts, fn($a, $b) => [$a['prio'], -self::docCount($a['obs']), $a['n']] <=> [$b['prio'], -self::docCount($b['obs']), $b['n']]);
        $first = $opts[0];
        $check = false; $reasons = []; $est = false;
        if (count($opts) > 1) {
            $check = true;
            $reasons = ['differs'];
        } else {
            $unflagged = array_filter($first['obs'], fn($o) => !$o['flagged']) !== [];
            $agree = self::docCount($first['obs']) >= 2
                && array_filter($first['obs'], fn($o) => !in_array('price_from_invoice', $o['reasons'], true) && !in_array('invoice_differs', $o['reasons'], true)
                    && !in_array('chain_mismatch', $o['reasons'], true)) !== [];
            if (!$unflagged && !$agree) {
                $check = true;
                foreach ($first['obs'] as $o) foreach ($o['reasons'] as $r) $reasons[$r] = true;
                $reasons = array_keys($reasons);
            }
        }
        foreach ($first['obs'] as $o) $est = $est || $o['estimated'];
        $f = $extra + ['name' => $name, 'kind' => $kind, 'value' => $first['value'], 'check' => $check, 'reasons' => array_values($reasons),
            'estimated' => $est, 'sources' => self::sources($first['obs']), 'status' => 'new'];
        if (count($opts) > 1) $f['options'] = array_map(fn($o) => ['value' => $o['value'], 'sources' => self::sources($o['obs'])], $opts);
        return $f;
    }

    /**
     * Wirklich verschiedene Kennungen in den Dokumenten (Kunden-, Vertragsnummer,
     * Marktlokation): vorgewählt ist die aus dem jüngsten Dokument — nach
     * `document_date`, wo die Auslesung es hat, sonst nach dem Stichtag —, die
     * anderen stehen zur Wahl. Keine Frage mehr (eine neue Nummer ersetzt die alte).
     */
    private function preferNewest(?array $f): ?array
    {
        if ($f === null || empty($f['options'])) return $f;
        $age = fn(array $op) => max(array_map(fn($s) => $this->docDate((string)$s['doc']), $op['sources'] ?: [['doc' => '']]));
        $opts = $f['options'];
        usort($opts, fn($a, $b) => strcmp($age($b), $age($a)));
        return ['value' => $opts[0]['value'], 'sources' => $opts[0]['sources'], 'options' => $opts, 'check' => false, 'reasons' => [], 'hint' => 'newest'] + $f;
    }

    /** Datum eines Dokuments: `document_date`, wo die Auslesung es hat, sonst sein Stichtag. */
    private function docDate(string $docId): string
    {
        if (!isset($this->docs[$docId])) return '';
        return $this->date($docId, 'document_date') ?? $this->docs[$docId]['date'];
    }

    /** Widerspruch zum Bestand: zu prüfen, mit dem Wert im Bestand. */
    private static function conflict(array &$f, mixed $current): void
    {
        $f['status'] = 'conflict';
        $f['check'] = true;
        $f['existing'] = $current;
        if (!in_array('conflict_existing', $f['reasons'], true)) $f['reasons'][] = 'conflict_existing';
    }

    private static function docCount(array $obs): int
    {
        $docs = [];
        foreach ($obs as $o) foreach ($o['src'] as $s) $docs[$s['doc']] = true;
        return count($docs);
    }

    /** Belege für die Anzeige: Dokument, Feld, Seite, Zitat (je Dokument und Feld einmal). */
    private static function sources(array $obs): array
    {
        $out = [];
        foreach ($obs as $o) foreach ($o['src'] as $s) {
            $k = $s['doc'] . '|' . $s['field'] . '|' . ($s['quote'] ?? '');
            if (isset($out[$k])) continue;
            $out[$k] = ['doc' => $s['doc'], 'name' => $s['name'], 'type' => $s['type'], 'field' => $s['field'], 'quote' => $s['quote'], 'page' => $s['page'],
                'flagged' => $s['flagged']];
        }
        return array_values($out);
    }

    private static function fieldValue(array $fields, string $name): mixed
    {
        foreach ($fields as $f) if ($f['name'] === $name) return $f['value'];
        return null;
    }

    /** Gleicher Wert nach der Art des Felds. */
    public static function same(string $kind, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        return match ($kind) {
            'date', 'enum' => (string)$a === (string)$b,
            // Kennungen (Kunden-, Vertrags-, Zählernummer, MaLo) auch mit anderen Leerzeichen, Strichen, führenden Nullen
            'text' => self::norm((string)$a) === self::norm((string)$b) || Proposals::sameId((string)$a, (string)$b),
            'money' => abs((float)$a - (float)$b) < 0.005,
            'price_ct' => abs((float)$a - (float)$b) < 0.00005,
            'factor' => is_array($a) && is_array($b) && abs((float)$a['z'] - (float)$b['z']) < 0.00005 && abs((float)$a['hs'] - (float)$b['hs']) < 0.0005,
            default => abs((float)$a - (float)$b) <= max(0.0005, 1e-6 * abs((float)$a)),
        };
    }

    /** Tage von `$a` bis `$b` (ganze Tage, über den Mittag gerechnet — Zeitumstellung zählt nicht). */
    private static function daysBetween(string $a, string $b): int
    {
        return (int)round(((int)strtotime("$b 12:00") - (int)strtotime("$a 12:00")) / 86400);
    }

    /** Folgt `$b` lückenlos auf `$a` (gleicher Tag oder Folgetag)? */
    private static function adjacent(string $a, string $b): bool
    {
        return $a === $b || date('Y-m-d', (int)strtotime($a . ' +1 day')) === $b;
    }

    // ── Offene Punkte ────────────────────────────────────────────────────

    /**
     * „Fehlt noch“ aus den offenen Punkten der Vorschläge (Proposals::build:
     * `missing` = steht nicht im Dokument, `unsure` = unsicher gelesen, wird
     * nicht still übernommen) — eine Zeile je Vertrag bzw. Lieferverhältnis
     * mit seinen Dokumenten, soweit der Entwurf die Angabe nicht anders hat:
     * Steht sie in einem Eintrag desselben Vertrags bzw.
     * Lieferverhältnisses (aus diesem Dokument, dann fragt der Eintrag, oder aus
     * einem anderen), fehlt sie nicht. Preise, Abschlag und Vertrag meldet der
     * Entwurf je Vertrag (OPEN_PER_CONTRACT).
     */
    private function openPoints(): void
    {
        $scope = fn(array $d): string => (string)($d['cand'] ?? $d['rel'] ?? $d['mp'] ?? $d['utility'] ?? '');
        $have = [];
        foreach ($this->items as $it) {
            foreach ($it['fields'] as $f) {
                foreach ([$f, ...($f['options'] ?? [])] as $x) {
                    foreach ((array)($x['sources'] ?? []) as $s) {
                        if (isset($this->docs[$s['doc']])) $have[$scope($this->docs[$s['doc']])][$s['field']] = true;
                    }
                }
            }
        }
        // je Vertrag bzw. Lieferverhältnis eine Zeile: dieselbe Lücke in zwei Briefen zum selben Vertrag einmal
        $by = [];
        foreach ($this->docs as $id => $d) {
            if ($d['dup'] !== null || $d['utility'] === null) continue;
            $open = array_values(array_filter($d['open'], fn($o) => !in_array($o['field'], self::OPEN_PER_CONTRACT, true)
                && empty($have[$scope($d)][$o['field']])));
            if ($open === []) continue;
            $k = $d['utility'] . '|' . $scope($d);
            $by[$k] ??= ['utility' => $d['utility'], 'docs' => [], 'names' => [], 'open' => []];
            $by[$k]['docs'][] = $id;
            $by[$k]['names'][] = $d['name'];
            foreach ($open as $o) $by[$k]['open'][$o['field'] . '|' . $o['reason']] = $o;
        }
        foreach ($by as $g) {
            $this->missing[] = ['kind' => 'open', 'utility' => $g['utility'], 'key' => 'open:' . implode(',', $g['docs']),
                'params' => ['name' => implode(', ', array_unique($g['names']))], 'open' => array_values($g['open']), 'docs' => $g['docs'],
                'href' => '#/inbox/' . rawurlencode($g['docs'][0])];
        }
    }

    // ── Ergebnis ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function result(): array
    {
        $order = array_flip(Utilities::keys());
        $kinds = array_flip(self::ORDER);
        foreach ($this->items as $i => $it) $this->items[$i]['_n'] = $i;
        usort($this->items, fn($a, $b) => [$order[$a['utility']] ?? 99, $kinds[$a['kind']] ?? 99, $a['_n']] <=> [$order[$b['utility']] ?? 99, $kinds[$b['kind']] ?? 99, $b['_n']]);
        $byDoc = [];
        $targets = ['meters' => [], 'contracts' => []];
        foreach ($this->mps as $k => $mp) $targets['meters'][$k] = $mp['meter_id'];
        foreach ($this->cands as $k => $c) $targets['contracts'][$k] = $c['existing']['id'] ?? null;
        $items = [];
        foreach ($this->items as $it) {
            unset($it['_n']);
            $it += ['status' => 'new', 'check' => false, 'reasons' => [], 'detail' => null, 'docs' => [], 'optional' => false];
            foreach ($it['fields'] as $f) if (!empty($f['core']) && $f['check']) {
                $it['check'] = true;
                foreach ($f['reasons'] as $r) if (!in_array($r, $it['reasons'], true)) $it['reasons'][] = $r;
            }
            $todo = array_filter($it['fields'], fn($f) => ($f['status'] ?? 'new') !== 'exists') !== [];
            if ($it['status'] === 'exists' && $todo && !in_array($it['kind'], ['meter_change', 'reminder'], true)) $it['status'] = 'update';
            if (in_array($it['kind'], ['readings', 'prices', 'advances', 'gas_factor', 'meter'], true) && !$todo) $it['status'] = 'exists';
            if ($it['kind'] === 'bill' && ($it['status'] === 'exists') && !empty($it['book']) && empty($it['booked'])) $it['status'] = 'update';
            // 3.7.1 — was den Bestand berührt (ergänzen, Überschneidung), ist immer eine Frage: vorbelegt
            // ist nur, was neu ist und nichts Vorhandenes ändert
            if (in_array($it['status'], ['update', 'overlap'], true)) {
                $it['check'] = true;
                $r = $it['status'] === 'update' ? 'existing_update' : 'existing_overlap';
                if (!in_array($r, $it['reasons'], true)) $it['reasons'][] = $r;
            }
            $it['actionable'] = $it['status'] !== 'exists';
            $it['checked'] = $it['actionable'] && in_array($it['status'], ['new', 'overlap', 'update'], true) && !$it['check'] && !$it['optional'];
            $docs = $it['docs'];
            foreach ($it['fields'] as $f) {
                foreach ($f['sources'] as $s) $docs[] = $s['doc'];
                foreach ($f['options'] ?? [] as $op) foreach ($op['sources'] as $s) $docs[] = $s['doc'];
            }
            $it['docs'] = array_values(array_unique(array_filter($docs, fn($d) => isset($this->docs[$d]))));
            $it['id'] = 'd_' . substr(sha1($it['kind'] . '|' . $it['key'] . '|' . json_encode(array_map(fn($f) => [$f['name'], $f['value'], array_column($f['options'] ?? [], 'value'), $f['status'] ?? 'new'], $it['fields']))
                . '|' . json_encode([$it['params'], $it['status']], JSON_UNESCAPED_UNICODE)), 0, 12);
            foreach ($it['docs'] as $d) $byDoc[$d][] = $it['id'];
            $items[] = $it;
        }
        $docs = [];
        foreach ($this->docs as $id => $d) {
            $state = match (true) {
                $d['dup'] !== null => 'duplicate',
                $d['note'] === 'no_utility' => 'no_utility',
                $d['note'] === 'no_meter' => 'no_meter',
                $d['note'] === 'meter_choice' => 'meter_choice',
                isset($byDoc[$id]) => 'used',
                $d['note'] === 'no_start' => 'no_start',
                default => 'nothing',
            };
            $docs[] = ['id' => $id, 'name' => $d['name'], 'type' => $d['type'], 'utility' => $d['utility'], 'state' => $state,
                'duplicate_of' => $d['dup'], 'items' => $byDoc[$id] ?? [], 'attachment_id' => $d['att'] ?: null,
                'meter_key' => $d['mp'], 'contract_key' => $d['cand']];
        }
        $count = fn(string $kind, bool $fieldsToo = false) => array_sum(array_map(fn($it) => $it['kind'] !== $kind || !$it['actionable'] ? 0
            : ($fieldsToo ? count(array_filter($it['fields'], fn($f) => ($f['status'] ?? 'new') !== 'exists')) : 1), $items));
        $questions = 0;
        foreach ($items as $it) {
            if (!$it['actionable']) continue;
            if ($it['check']) { $questions++; continue; }
            foreach ($it['fields'] as $f) if ($f['check'] && ($f['status'] ?? 'new') !== 'exists') $questions++;
        }
        $used = count(array_filter($docs, fn($d) => $d['state'] === 'used'));
        return [
            'generated_at' => date('c'),
            'summary' => [
                'documents' => count($docs), 'used' => $used, 'duplicates' => count(array_filter($docs, fn($d) => $d['state'] === 'duplicate')),
                'without' => count($docs) - $used - count(array_filter($docs, fn($d) => $d['state'] === 'duplicate')),
                'meters' => $count('meter'), 'meter_changes' => $count('meter_change'), 'contracts' => $count('contract'),
                'prices' => $count('prices', true), 'advances' => $count('advances', true), 'readings' => $count('readings', true),
                'bills' => $count('bill'), 'reminders' => $count('reminder'), 'questions' => $questions,
            ],
            'utilities' => array_values(array_unique(array_column($items, 'utility'))),
            'items' => $items,
            'documents' => $docs,
            'findings' => $this->findings,
            'missing' => $this->missing,
            'targets' => $targets,
        ];
    }
}
