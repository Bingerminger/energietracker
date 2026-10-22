<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConversionFactorService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\SettingsService;

/**
 * v3.7.0 (Posteingang) — aus der Auslesung die vorgeschlagenen Änderungen,
 * abgeglichen mit dem Bestand.
 *
 * Ein Vorschlag: {id, kind, label (Schlüssel + Parameter), data (was der
 * Dienst bekommt), sources (Felder, aus denen er stammt), check (eine Quelle ist
 * markiert und nicht bestätigt), status, detail, checked (Vorbelegung)}.
 *
 * status — Abgleich mit dem Bestand:
 *   new       noch nicht da
 *   exists    schon vorhanden (gleiche Ablesung, gleiche Preiszeile, gleiche
 *             Rechnungsperiode …) — wird beim Übernehmen übersprungen
 *   conflict  an derselben Stelle steht ein anderer Wert (überschreibt nur,
 *             wenn angehakt)
 *   overlap   neu, überschneidet sich aber (etwa ein Vertrag im Zeitraum eines
 *             anderen; es gilt dann der spätere Beginn)
 *
 * Vorbelegt angehakt ist nur, was neu ist und keine Markierung trägt. Die ID
 * hängt an Art und Inhalt: Ändert sich ein Wert, ist es ein anderer Vorschlag
 * (InboxService::apply lehnt veraltete IDs ab).
 *
 * v3.7.0 (treffsicher) — additiv je Vorschlag:
 *   core     die Felder, deren Zweifel ihn markieren (sonst alle `sources`);
 *            ein leeres Feld markiert nur, wenn ein Vorschlag es braucht
 *            (InboxSchema::NEEDS)
 *   reasons  [{field, reasons}] — warum er markiert ist
 *   hints    [{field, reason}] — sichtbar, ohne Marke („voraussichtlich“)
 *   open     [{field, reason: missing|unsure}] — was fehlt oder als unsicher
 *            nicht übernommen wird (der Vertrag entsteht ohne Abschlag, der
 *            Abschlag bleibt offen)
 * Die Marken selbst bestimmt marks() mit dem Bestand (Evidence::refine);
 * build() rechnet immer mit ihnen.
 */
final class Proposals
{
    /** Toleranz beim Abgleich eines Preises aus der Rechnung mit dem Vertrag: Rundung auf beiden Seiten (ct/kWh, € je Monat bzw. Jahr wie gedruckt). */
    public const PRICE_TOL = 0.011;

    /** v3.7.0 (Paket betrieb) — Tage vor dem Lieferende für den Termin „Anschlussvertrag abschließen“. */
    public const FOLLOW_UP_LEAD_DAYS = 14;

    public function __construct(
        private MeterService $meters,
        private ContractService $contracts,
        private ReadingService $readings,
        private BillService $bills,
        private ReminderService $reminders,
        private SettingsService $settings,
    ) {}

    /** v3.7.0 (Paket betrieb) — Zuständigkeit an Vertrag und Zähler für Termine aus Dokumenten. */
    private ?ResponsibilityService $responsibility = null;

    public function attachResponsibility(ResponsibilityService $responsibility): void
    {
        $this->responsibility = $responsibility;
    }

    /**
     * Wert eines Felds: Korrektur des Nutzers vor der Auslesung.
     *
     * @param array<string,mixed> $entry
     */
    public static function value(array $entry, string $name): mixed
    {
        $edits = (array)($entry['edits'] ?? []);
        if (array_key_exists($name, $edits)) return $edits[$name];
        return $entry['extraction']['fields'][$name]['value'] ?? null;
    }

    /** Ist das Feld markiert (und nicht vom Nutzer bestätigt bzw. korrigiert)? */
    public static function flagged(array $entry, string $name): bool
    {
        if (array_key_exists($name, (array)($entry['edits'] ?? []))) return false;
        return ($entry['extraction']['fields'][$name]['status'] ?? '') === 'check';
    }

    // ── Zuordnung ────────────────────────────────────────────────────────

    /**
     * Vorbelegung von Verbrauchsart, Zähler und Vertrag: Zählernummer und
     * Marktlokation zuerst (eindeutig), dann Vertrags-/Kundennummer, dann
     * Verbrauchsart laut Dokument mit dem einzigen Zähler, zuletzt der Vertrag,
     * der am Stichtag des Dokuments gilt (Anbieter im Namen bevorzugt).
     *
     * @param array<string,mixed> $entry
     * @return array{utility: ?string, meter_id: ?string, contract_id: ?string, by: list<string>}
     */
    public function suggest(array $entry): array
    {
        $by = [];
        $utility = null; $meterId = null; $contractId = null;
        $u0 = self::value($entry, 'utility');
        $candidates = in_array($u0, InboxSchema::UTILITIES, true) ? [$u0] : [];
        foreach (InboxSchema::UTILITIES as $u) if (!in_array($u, $candidates, true)) $candidates[] = $u;
        $serials = array_filter([self::value($entry, 'meter_number'), self::value($entry, 'old_meter_number')], 'is_string');
        $malo = is_string(self::value($entry, 'market_location')) ? preg_replace('/\D/', '', (string)self::value($entry, 'market_location')) : '';
        foreach ($candidates as $u) {
            foreach ($this->meters->list($u) as $m) {
                foreach ($m['devices'] ?? [] as $d) {
                    foreach ($serials as $s) {
                        if (self::sameId((string)($d['serial'] ?? ''), $s)) { $utility = $u; $meterId = (string)$m['id']; $by[] = 'meter_number'; break 4; }
                    }
                }
                if ($malo !== '' && ($m['malo_id'] ?? '') === $malo) { $utility = $u; $meterId = (string)$m['id']; $by[] = 'market_location'; break 2; }
            }
        }
        $numbers = array_filter([self::value($entry, 'contract_number'), self::value($entry, 'customer_number')], 'is_string');
        if ($numbers !== []) {
            foreach ($utility !== null ? [$utility] : $candidates as $u) {
                if (!Utilities::hasContracts($u)) continue;
                foreach ($this->contracts->list($u) as $c) {
                    if (!empty($c['is_shadow'])) continue;
                    foreach ($numbers as $n) {
                        if (self::sameId((string)($c['contract_number'] ?? ''), $n) || self::sameId((string)($c['customer_number'] ?? ''), $n)) {
                            $utility = $u; $contractId = (string)$c['id']; $by[] = 'contract_number';
                            $meterId ??= ($c['meter_id'] ?? null) !== null ? (string)$c['meter_id'] : null;
                            break 3;
                        }
                    }
                }
            }
        }
        if ($utility === null && in_array($u0, InboxSchema::UTILITIES, true)) { $utility = $u0; $by[] = 'utility'; }
        if ($utility !== null && $meterId === null) {
            $active = array_values(array_filter($this->meters->list($utility), fn($m) => ($m['active'] ?? true) !== false && empty($m['parent_meter_id'])));
            if (count($active) === 1) { $meterId = (string)$active[0]['id']; $by[] = 'single_meter'; }
        }
        if ($utility !== null && $meterId !== null && $contractId === null && Utilities::hasContracts($utility)
            && ($entry['type'] ?? '') !== 'contract_confirmation') {
            // v3.7.0 (treffsicher) — nach Datum nur beim selben Versorger. Bis dahin
            // nahm die Vorbelegung jeden Vertrag des Zählers an diesem Tag; im
            // Nutzentest war es jedes Mal der eines anderen Lieferanten
            // (Scheinwidersprüche, Werte am falschen Vertrag). Ohne passenden
            // Vertrag bleibt die Zuordnung leer und der Vorschlag „Vertrag aus
            // diesem Dokument anlegen“ steht bereit, wo das Dokument genug hergibt.
            $c = $this->contractForDocument($utility, $meterId, $entry);
            if ($c !== null) { $contractId = (string)$c['id']; $by[] = 'date'; }
        }
        return ['utility' => $utility, 'meter_id' => $meterId, 'contract_id' => $contractId, 'by' => $by];
    }

    /**
     * v3.7.0 (treffsicher) — Vertrag desselben Versorgers zum Dokument: bei
     * einer Rechnung der, der ihren Zeitraum abrechnet (wie BillService::book),
     * sonst der am Stichtag des Dokuments. Ohne Versorger im Dokument keiner.
     *
     * @param array<string,mixed> $entry
     */
    private function contractForDocument(string $u, string $meterId, array $entry): ?array
    {
        $supplier = trim((string)(self::value($entry, 'supplier') ?? ''));
        if ($supplier === '') return null;
        $from = self::value($entry, 'period_from');
        $to = self::value($entry, 'period_to');
        if (($entry['type'] ?? '') === 'invoice' && is_string($from) && is_string($to) && $from <= $to && Utilities::supportsBillCheck($u)) {
            return $this->bills->contractFor($u, $meterId, $from, $to, fn($c) => self::supplierMatch(self::providerOf($c), $supplier));
        }
        return $this->contractOn($u, $meterId, $this->referenceDate($entry), $supplier);
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung) — Löst der neue Vertrag den bestehenden ab?
     * Ja bei anderem Versorger (Wechsel) und bei demselben Versorger mit anderem
     * Tarif oder anderer Vertragsnummer (Tarifwechsel); fehlt eine Angabe auf einer
     * Seite, zählt sie nicht als Unterschied. Sonst ist es wohl derselbe Vertrag.
     *
     * @param array<string,mixed> $prev bestehender Vertrag
     * @param array<string,mixed> $next Daten des neuen Vertrags
     */
    public static function isContractChange(array $prev, array $next): bool
    {
        if (!self::supplierMatch(self::providerOf($prev), (string)($next['provider'] ?? ''))) return true;
        $t = fn(mixed $s) => mb_strtolower(trim((string)($s ?? '')), 'UTF-8');
        if ($t($prev['tariff_name'] ?? '') !== '' && $t($next['tariff_name'] ?? '') !== '' && $t($prev['tariff_name']) !== $t($next['tariff_name'])) return true;
        $a = (string)($prev['contract_number'] ?? ''); $b = (string)($next['contract_number'] ?? '');
        return $a !== '' && $b !== '' && !self::sameId($a, $b);
    }

    /** Name des Versorgers am Vertrag (sonst der Tarifname, wie ContractService::sameSupplier). */
    private static function providerOf(array $c): string
    {
        return (string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? ''));
    }

    /** Rechtsformen und Bindewörter, die einen Versorgernamen nicht unterscheiden. */
    private const LEGAL_WORDS = ['gmbh', 'mbh', 'ag', 'kg', 'kgaa', 'ohg', 'gbr', 'eg', 'se', 'ug', 'co', 'und', 'haftungsbeschraenkt', 'ev'];

    /** Wörter, die allein keinen Versorger bezeichnen („Stadtwerke“ passt zu jedem Stadtwerk). */
    private const GENERIC_WORDS = ['stadtwerke', 'stadtwerk', 'gemeindewerke', 'werke', 'energie', 'energy', 'energieversorgung', 'versorgung',
        'gas', 'erdgas', 'strom', 'waerme', 'fernwaerme', 'wasser', 'vertrieb', 'service', 'kundenservice', 'netz', 'netze', 'die', 'der'];

    /** @return list<string> Wörter eines Versorgernamens, klein, ohne Rechtsform (ä → ae …) */
    public static function supplierTokens(string $name): array
    {
        $s = strtr(mb_strtolower($name, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e']);
        $t = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($t, fn($w) => !in_array($w, self::LEGAL_WORDS, true)));
    }

    /**
     * v3.7.0 (treffsicher) — Derselbe Versorger, normalisiert: Groß/klein,
     * Umlaute, Satzzeichen und Rechtsform zählen nicht („Stadtwerke Musterstadt
     * GmbH“ = „Stadtwerke Musterstadt“, „E.ON“ = „EON“). Ein kürzerer Name
     * passt, wenn alle seine Wörter im längeren stehen oder er dessen Anfang
     * ist (mindestens sechs Zeichen) — nur allgemeine Wörter („Stadtwerke“,
     * „Energie“) genügen nie.
     */
    public static function supplierMatch(string $a, string $b): bool
    {
        $x = self::supplierTokens($a);
        $y = self::supplierTokens($b);
        if ($x === [] || $y === []) return false;
        if (implode('', $x) === implode('', $y)) return true;
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];
        if (array_diff($short, self::GENERIC_WORDS) === []) return false;
        if (array_diff($short, $long) === []) return true;
        $s = implode('', $short);
        return strlen($s) >= 6 && str_starts_with(implode('', $long), $s);
    }

    /**
     * v3.7.0 (treffsicher) — Vorzeichen des Ergebnisses einer Rechnung: +1
     * Nachzahlung, −1 Guthaben. Aus der Art laut Dokument, sonst aus Betrag
     * gegen Abschläge; sonst null — nie still „Nachzahlung“ (bis dahin der
     * Rückfall). Eine Regel für Vorschläge und Kernaussage (Gist).
     *
     * @param array<string,mixed> $entry
     */
    public static function resultSign(array $entry): ?int
    {
        $kind = self::value($entry, 'result_kind');
        if ($kind === 'credit') return -1;
        if ($kind === 'due') return 1;
        $total = self::value($entry, 'total_amount');
        $advances = self::value($entry, 'advances_total') ?? ($entry['extraction']['fields']['advances_paid']['sum'] ?? null);
        if (is_numeric($total) && is_numeric($advances) && abs((float)$total - (float)$advances) > 0.005) return (float)$total > (float)$advances ? 1 : -1;
        return null;
    }

    /** Zählernummer, Vertragsnummer: ohne Leerraum, Punkte, Striche, Groß/klein; führende Nullen egal. */
    public static function sameId(string $a, string $b): bool
    {
        // v3.7.0 (treffsicher, Nachbesserung 5) — auch ohne Sternchen („*“ vor oder in der Nummer)
        $n = fn(string $s) => ltrim(strtoupper(preg_replace('/[\s.\-\/_*]/', '', $s) ?? ''), '0');
        return $n($a) !== '' && $n($a) === $n($b);
    }

    /** Stichtag eines Dokuments für die Vertragswahl. */
    public function referenceDate(array $entry): string
    {
        foreach (['period_to', 'valid_from', 'supply_end', 'supply_start', 'change_date', 'requested_by'] as $f) {
            $v = self::value($entry, $f);
            if (is_string($v) && $v !== '') return $v;
        }
        return date('Y-m-d');
    }

    /**
     * Vertrag des Zählers (oder seiner Gruppe) an einem Tag — v3.7.0
     * (treffsicher) nur unter den Verträgen desselben Versorgers
     * (supplierMatch); ohne Versorger keiner. Bei Überlappung gewinnt der
     * spätere Beginn, ohne Vertrag an dem Tag der weiterlaufende
     * (ContractService::resolveForDate).
     */
    private function contractOn(string $u, string $meterId, string $date, string $supplier): ?array
    {
        if (trim($supplier) === '') return null;
        $list = array_values(array_filter($this->contractsOf($u, $meterId),
            fn($c) => empty($c['is_shadow']) && self::supplierMatch(self::providerOf($c), $supplier)));
        if ($list === []) return null;
        $r = $this->contracts->resolveForDate($list, $date);
        return is_array($r['contract'] ?? null) ? $r['contract'] : null;
    }

    /** @return list<array<string,mixed>> Verträge des Zählers und seiner Gruppe */
    private function contractsOf(string $u, string $meterId): array
    {
        $list = $this->contracts->list($u, $meterId);
        $gid = (string)(($this->meters->get($u, $meterId) ?? [])['meter_group_id'] ?? '');
        if ($gid !== '') $list = [...$list, ...$this->contracts->list($u, $gid)];
        return $list;
    }

    // ── Marken mit Bestand (v3.7.0, treffsicher) ─────────────────────────

    /**
     * Eintrag mit den Marken nach dem heutigen Bestand (Evidence::refine):
     * gestützte Auswahlfelder, Preise aus der Rechnung gegen den Vertrag,
     * leere Felder je Vorschlag. `extraction.flags` zählt, was noch zu prüfen
     * ist (ohne Bestätigte). Gilt genauso für Dokumente, die vor dieser
     * Version ausgelesen wurden.
     *
     * @param array<string,mixed> $entry
     * @param array{utility?: ?string, meter_id?: ?string, contract_id?: ?string} $as
     * @return array<string,mixed>
     */
    public function marks(array $entry, array $as): array
    {
        $type = (string)($entry['type'] ?? '');
        if (!InboxSchema::extracts($type) || !is_array($entry['extraction']['fields'] ?? null)) return $entry;
        $fields = Evidence::refine($type, $entry['extraction']['fields'], $this->markContext($entry, $as));
        $entry['extraction']['fields'] = $fields;
        $entry['extraction']['flags'] = Evidence::flagCount($fields, (array)($entry['edits'] ?? []));
        return $entry;
    }

    /** @return array<string,mixed> Bestand für Evidence::refine() */
    private function markContext(array $entry, array $as): array
    {
        $u = $as['utility'] ?? null;
        $ctx = ['utility' => is_string($u) ? $u : null, 'utility_by_bestand' => false, 'prices' => []];
        if (!is_string($u) || !Utilities::exists($u)) return $ctx;
        $meter = !empty($as['meter_id']) ? $this->meters->get($u, (string)$as['meter_id']) : null;
        $contract = !empty($as['contract_id']) && Utilities::hasContracts($u) ? $this->contracts->get($u, (string)$as['contract_id']) : null;
        // Die Verbrauchsart steht fest: Zählernummer oder Marktlokation am zugeordneten
        // Zähler, Vertrags-/Kundennummer am Vertrag, oder der Nutzer hat zugeordnet
        $by = is_array($entry['assignment'] ?? null) && ($entry['assignment']['utility'] ?? null) === $u;
        if ($meter !== null) {
            foreach (['meter_number', 'old_meter_number'] as $f) {
                $s = self::value($entry, $f);
                foreach ($meter['devices'] ?? [] as $d) if (is_string($s) && self::sameId((string)($d['serial'] ?? ''), $s)) $by = true;
            }
            $malo = preg_replace('/\D/', '', (string)(self::value($entry, 'market_location') ?? '')) ?? '';
            if ($malo !== '' && (string)($meter['malo_id'] ?? '') === $malo) $by = true;
        }
        if ($contract !== null) {
            foreach (['contract_number', 'customer_number'] as $f) {
                $n = self::value($entry, $f);
                if (is_string($n) && (self::sameId((string)($contract['contract_number'] ?? ''), $n) || self::sameId((string)($contract['customer_number'] ?? ''), $n))) $by = true;
            }
        }
        $ctx['utility_by_bestand'] = $by;
        // Preise aus der Rechnung gegen die Preiszeile des Vertrags am selben Tag.
        // v3.7.0 (treffsicher, Nachbesserung 2) — brutto gegen brutto: Ein Nettopreis mit
        // gelesenem Steuersatz zählt umgerechnet. Passt er nicht, aber der Nettopreis ist
        // derselbe wie im Vertrag und nur der Satz ein anderer (der Satz des Vertrags
        // gelesen in seinen Dokumenten oder in derselben Rechnung), heißt das
        // 'vat_changed': keine Marke, eine neue Preiszeile ab dem Tag der Rechnung.
        if ($contract !== null && ($entry['type'] ?? '') === 'invoice') {
            $fields = (array)$entry['extraction']['fields'];
            $wp = ContractService::workingPricesFor($contract, $meter !== null ? (string)$meter['id'] : null);
            $taxed = null;
            foreach ((array)($fields['working_prices']['items'] ?? []) as $i => $it) {
                $v = $it['value'] ?? [];
                if (!is_numeric($v['ct'] ?? null) || !is_string($v['from'] ?? null)) continue;
                $known = $this->contracts->valueOnDate($wp, 'ct_per_kwh', (string)$v['from']);
                $tax = Evidence::itemTax($it);
                $gross = Evidence::grossOf((float)$v['ct'], $tax);
                $ok = $known !== null && abs($known - $gross) <= self::PRICE_TOL;
                if (!$ok && $known !== null && $tax !== null) {
                    $taxed ??= $this->taxedPrices($contract, $fields, 'working');
                    $ok = self::sameNetOtherRate([$known], Evidence::netOf((float)$v['ct'], $tax), $tax['vat_pct'], $taxed) ? 'vat_changed' : false;
                }
                $ctx['prices']['working_prices'][$i] = $ok;
            }
            $bp = self::value($entry, 'base_price');
            $from = self::value($entry, 'period_from');
            if (is_numeric($bp) && is_string($from)) {
                $known = $this->contracts->valueOnDate((array)($contract['base_prices'] ?? []), 'eur_per_month', $from);
                $per = self::value($entry, 'base_price_period');
                $tax = Evidence::fieldTax($fields, 'base_price');
                $gross = Evidence::grossOf((float)$bp, $tax);
                $ok = $known !== null
                    && (($per !== 'year' && abs($known - $gross) <= self::PRICE_TOL) || ($per !== 'month' && abs(12 * $known - $gross) <= 12 * self::PRICE_TOL));
                if (!$ok && $known !== null && $tax !== null) {
                    // je Monat; ohne Angabe der Periode beide Lesarten
                    $net = Evidence::netOf((float)$bp, $tax);
                    $monthly = array_merge($per !== 'year' ? [$net] : [], $per !== 'month' ? [$net / 12] : []);
                    $taxed = $this->taxedPrices($contract, $fields, 'base');
                    foreach ($monthly as $n) if (self::sameNetOtherRate([$known], $n, $tax['vat_pct'], $taxed)) $ok = 'vat_changed';
                }
                $ctx['prices']['base_price'] = $ok;
            }
        }
        return $ctx;
    }

    /** v3.7.0 (treffsicher, Nachbesserung 2) — Dokumente am Vertrag (Posteingang, nach Anhang), für den Steuersatz laut Bestätigung. */
    private ?\Closure $documents = null;

    /** @param \Closure(list<string>): list<array<string,mixed>> $byAttachment Einträge des Posteingangs zu diesen Anhängen */
    public function attachDocuments(\Closure $byAttachment): void
    {
        $this->documents = $byAttachment;
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 2) — Bruttopreise mit gelesenem Steuersatz,
     * die zum Vertrag gehören können: aus den Dokumenten am Vertrag (Bestätigung,
     * Preisänderung; ihre Preise sind brutto, der Satz steht im Zitat oder im Feld
     * `vat_pct`) und aus der Rechnung selbst (jede Zeile mit gelesenem Satz).
     * Arbeitspreise in ct/kWh, Grundpreise je Monat.
     *
     * @param array<string,array<string,mixed>> $invoiceFields
     * @return list<array{gross: float, vat_pct: float}>
     */
    private function taxedPrices(array $contract, array $invoiceFields, string $what): array
    {
        $out = [];
        if ($what === 'working') {
            foreach ((array)($invoiceFields['working_prices']['items'] ?? []) as $it) {
                $tax = Evidence::itemTax($it);
                if ($tax !== null && is_numeric($it['value']['ct'] ?? null)) $out[] = ['gross' => Evidence::grossOf((float)$it['value']['ct'], $tax), 'vat_pct' => $tax['vat_pct']];
            }
        }
        $ids = array_values(array_filter(array_map('strval', (array)($contract['attachment_ids'] ?? []))));
        if ($ids === [] || $this->documents === null) return $out;
        foreach (($this->documents)($ids) as $doc) {
            $f = (array)($doc['extraction']['fields'] ?? []);
            $type = (string)($doc['type'] ?? '');
            $names = match ($type) {
                'contract_confirmation' => $what === 'working' ? ['working_price'] : ['base_price'],
                'price_change' => $what === 'working' ? ['working_price_new', 'working_price_old'] : ['base_price_new', 'base_price_old'],
                default => [],
            };
            foreach ($names as $n) {
                $v = self::value($doc, $n);
                if (!is_numeric($v)) continue;
                // brutto laut Schema; der Satz aus dem Zitat des Preises oder aus `vat_pct` (mit dessen Zitat)
                // Nachbesserung 5 — nur gefundene Zitate (Evidence::foundQuote)
                $quotes = implode(' | ', array_filter([Evidence::foundQuote((array)($f[$n] ?? [])), Evidence::foundQuote((array)($f['vat_pct'] ?? []))], 'is_string'));
                $tax = Evidence::taxOf($quotes, 'gross', $f['vat_pct']['value'] ?? null);
                if ($tax === null) continue;
                $value = (float)$v;
                if ($what === 'base') $value = self::value($doc, 'base_price_period') === 'year' ? $value / 12 : $value;
                $out[] = ['gross' => $value, 'vat_pct' => $tax['vat_pct']];
            }
        }
        return $out;
    }

    /**
     * Gleicher Nettopreis, anderer Steuersatz? Ein Vertragspreis (brutto) mit
     * gelesenem Satz, der vom Satz der Rechnung abweicht und herausgerechnet
     * denselben Nettopreis ergibt.
     *
     * @param list<float> $known Vertragspreise (brutto) am Tag
     * @param list<array{gross: float, vat_pct: float}> $taxed
     */
    private static function sameNetOtherRate(array $known, float $net, float $rate, array $taxed): bool
    {
        foreach ($known as $k) {
            foreach ($taxed as $t) {
                if (abs($t['gross'] - $k) > self::PRICE_TOL || abs($t['vat_pct'] - $rate) < 0.001) continue;
                if (abs($k / (1 + $t['vat_pct'] / 100) - $net) <= self::PRICE_TOL) return true;
            }
        }
        return false;
    }

    /**
     * Wert eines Felds, wenn er sicher ist (nicht markiert oder bestätigt); ein
     * markierter Wert kommt in `$withheld` und wird nicht übernommen.
     *
     * @param array<string,mixed> $entry
     * @param list<string> $withheld
     */
    private static function sure(array $entry, string $name, array &$withheld): mixed
    {
        $v = self::value($entry, $name);
        if ($v === null || $v === '') return null;
        if (self::flagged($entry, $name)) { $withheld[] = $name; return null; }
        return $v;
    }

    /**
     * Offene Punkte eines Vorschlags: Felder, die im Dokument fehlen, und
     * unsichere, die nicht übernommen werden.
     *
     * @param list<string> $fields
     * @param list<string> $withheld
     * @return list<array{field: string, reason: string}>
     */
    private static function openPoints(array $entry, array $fields, array $withheld = []): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = self::value($entry, $f);
            if (in_array($f, $withheld, true)) $out[] = ['field' => $f, 'reason' => 'unsure'];
            elseif ($v === null || $v === '' || $v === []) $out[] = ['field' => $f, 'reason' => 'missing'];
        }
        return $out;
    }

    // ── Vorschläge ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $entry
     * @param array{utility: ?string, meter_id: ?string, contract_id: ?string} $as
     * @return list<array<string,mixed>>
     */
    public function build(array $entry, array $as): array
    {
        $type = (string)($entry['type'] ?? '');
        if (!InboxSchema::extracts($type) || !is_array($entry['extraction']['fields'] ?? null)) return [];
        $u = $as['utility'] ?? null;
        if (!is_string($u) || !Utilities::exists($u)) return [];
        $entry = $this->marks($entry, $as);   // v3.7.0 (treffsicher) — Marken nach dem Bestand
        $meter = !empty($as['meter_id']) ? $this->meters->get($u, (string)$as['meter_id']) : null;
        $contract = !empty($as['contract_id']) && Utilities::hasContracts($u) ? $this->contracts->get($u, (string)$as['contract_id']) : null;
        $ctx = ['entry' => $entry, 'u' => $u, 'meter' => $meter, 'contract' => $contract,
                'att' => (string)($entry['attachment_id'] ?? ''), 'name' => (string)($entry['name'] ?? '')];
        $out = match ($type) {
            'invoice'               => $this->invoice($ctx),
            'contract_confirmation' => $this->confirmation($ctx),
            'price_change'          => $this->priceChange($ctx),
            'cancellation'          => $this->cancellation($ctx),
            'supply_start'          => $this->supplyStart($ctx),
            'meter_change'          => $this->meterChange($ctx),
            'reading_request'       => $this->readingRequest($ctx),
            'advance_change'        => $this->advanceChange($ctx),
            default                 => [],
        };
        $fields = $entry['extraction']['fields'];
        foreach ($out as &$p) {
            // v3.7.0 (treffsicher) — markiert nur ein Zweifel an einem Kernfeld des Vorschlags
            $why = (array)($p['reasons'] ?? []);
            foreach ((array)($p['core'] ?? $p['sources']) as $s) {
                if (self::flagged($entry, $s)) $why[] = ['field' => $s, 'reasons' => array_values((array)($fields[$s]['reasons'] ?? []))];
            }
            $p['reasons'] = $why;
            $p['check'] = (bool)($p['check'] ?? false) || $why !== [];
            $hints = array_values((array)($p['hints'] ?? []));   // Nachbesserung 2 — net_price, vat_changed am Preis
            foreach ($p['sources'] as $s) {
                if (!array_key_exists($s, (array)($entry['edits'] ?? [])) && in_array('estimated', (array)($fields[$s]['reasons'] ?? []), true)) $hints[] = ['field' => $s, 'reason' => 'estimated'];
            }
            $p['hints'] = $hints;
            $p['open'] = array_values((array)($p['open'] ?? []));
            $p['checked'] = $p['status'] === 'new' || $p['status'] === 'overlap' ? !$p['check'] && empty($p['optional']) : false;
            $p['id'] = 'p_' . substr(sha1($p['kind'] . '|' . json_encode($p['data'], JSON_UNESCAPED_UNICODE)), 0, 12);
        }
        unset($p);
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $extra detail, check, optional — gehen vor
     */
    private static function p(string $kind, string $label, array $params, array $data, array $sources, string $status = 'new', array $extra = []): array
    {
        return $extra + ['kind' => $kind, 'label_key' => 'inbox.proposal.' . $label, 'params' => $params, 'data' => $data,
                'sources' => $sources, 'status' => $status, 'detail' => null];
    }

    private function invoice(array $c): array
    {
        $e = $c['entry']; $u = $c['u']; $m = $c['meter']; $out = [];
        if ($m !== null) {
            foreach ([['reading_new', 'reading_new_date'], ['reading_old', 'reading_old_date']] as $i => [$vf, $df]) {
                $p = $this->readingProposal($c, $df, $vf, $i === 1);
                if ($p !== null) $out[] = $p;
            }
            $out = $this->meterReadings($c, $out);   // v3.7.0 (treffsicher, Nachbesserung 4) — Stände je Zähler, Zählertausch
        }
        $from = self::value($e, 'period_from'); $to = self::value($e, 'period_to');
        $bill = null;
        $fields = $e['extraction']['fields'];
        if ($m !== null && Utilities::supportsBillCheck($u) && is_string($from) && is_string($to) && $from <= $to) {
            $advances = self::value($e, 'advances_total') ?? ($fields['advances_paid']['sum'] ?? null);
            $result = self::value($e, 'result_amount');
            $sign = self::resultSign($e);
            $inv = array_filter([
                ($u === 'wasser' ? 'volume_m3' : 'energy_kwh') => self::value($e, 'consumption'),
                'amount_eur'        => self::value($e, 'total_amount'),
                'advances_paid_eur' => $advances,
                'result_eur'        => is_numeric($result) && $sign !== null ? round($sign * abs((float)$result), 2) : null,
            ], fn($v) => $v !== null);
            $withheld = [];
            $billKind = self::sure($e, 'kind', $withheld);
            $data = ['meter_id' => (string)$m['id'], 'contract_id' => $c['contract']['id'] ?? null,
                     'kind' => $billKind === 'final' ? 'final' : 'annual',
                     'period_from' => $from, 'period_to' => $to, 'invoice' => $inv,
                     'note' => '', 'attachment_ids' => $c['att'] !== '' ? [$c['att']] : []];
            // Gas: Zustandszahl und Brennwert laut Rechnung an der Rechnung, für „Rechnung prüfen“
            $factors = [];
            foreach ($u === 'gas' ? (array)($fields['gas_factors']['items'] ?? []) : [] as $item) {
                $v = $item['value'] ?? [];
                if (($item['status'] ?? '') === 'check' || !is_numeric($v['z'] ?? null) || !is_numeric($v['hs'] ?? null) || !is_string($v['from'] ?? null)) continue;
                $factors[] = ['from' => (string)$v['from'], 'to' => is_string($v['to'] ?? null) ? (string)$v['to'] : null,
                              'zustandszahl' => (float)$v['z'], 'brennwert' => (float)$v['hs']];
            }
            if ($factors !== []) $data['factors'] = $factors;
            [$status, $detail] = $this->compareBill($u, (string)$m['id'], $from, $to, $inv);
            $core = ['period_from', 'period_to', 'consumption', 'total_amount', 'result_amount', 'result_kind', 'advances_total', 'advances_paid'];
            $bill = self::p('bill', 'bill', ['from' => $from, 'to' => $to, 'result' => $inv['result_eur'] ?? null],
                $data, ['period_from', 'period_to', 'consumption', 'total_amount', 'result_amount', 'result_kind', 'advances_total', 'advances_paid', 'kind'], $status,
                ['core' => $core, 'open' => self::openPoints($e, ['consumption', 'total_amount'], $withheld)]);
            $bill['detail'] = $detail;
            $out[] = $bill;
            if (isset($inv['result_eur']) && (float)$inv['result_eur'] != 0.0 && Utilities::hasSpecialPayments($u)) {
                $booked = $detail !== null && !empty($detail['special_payment_id']);
                // Buchen setzt die Rechnung voraus: dieselben Quellen, also dieselbe Marke
                // v3.7.0 (treffsicher) — ohne Vertrag dieses Versorgers nur als Angebot: Gebucht
                // wird dann am Vertrag aus dem Dokument (anhaken) oder gar nicht (Applier::book)
                $out[] = self::p('bill_book', 'billBook', ['result' => $inv['result_eur']], ['period_from' => $from, 'period_to' => $to],
                    $bill['sources'], $booked ? 'exists' : 'new', ['core' => $core]
                    + ($c['contract'] === null && !$booked ? ['optional' => true, 'open' => [['field' => 'contract', 'reason' => 'missing']]] : []));
            }
        }
        $ct = $c['contract'];
        $adv = self::value($e, 'advance_new');
        if ($ct !== null && is_numeric($adv)) {
            $advFrom = self::value($e, 'advance_new_from');
            $assumed = !is_string($advFrom) || $advFrom === '';
            if ($assumed && is_string($to)) $advFrom = date('Y-m-01', (int)strtotime($to . ' +1 day +1 month'));
            if (is_string($advFrom) && $advFrom !== '') {
                // Der neue Abschlag gehört zum Vertrag, der dann läuft — oft schon der
                // Folgevertrag, v3.7.0 (treffsicher) aber nur beim selben Versorger
                $target = $this->advanceTarget($u, $m, $ct, $advFrom);
                if ($target !== null) {
                    $out[] = $this->priceEntry('contract_advance', 'advance', $target, 'advance_payments', 'amount_eur', $advFrom, (float)$adv,
                        ['advance_new', 'advance_new_from'], $assumed ? ['check' => true, 'assumed' => 'date',
                            'reasons' => [['field' => 'advance_new_from', 'reasons' => ['assumed']]]] : []);
                }
            }
        }
        if ($u === 'gas') {
            foreach ($fields['gas_factors']['items'] ?? [] as $item) {
                $v = $item['value'] ?? [];
                if (!is_numeric($v['z'] ?? null) || !is_numeric($v['hs'] ?? null) || !is_string($v['from'] ?? null)) continue;
                $out[] = $this->gasFactor((string)$v['from'], (float)$v['z'], (float)$v['hs'], $item);
            }
        }
        if ($ct !== null && $u !== 'wasser' && !Utilities::isFeedIn($u)) {
            // v3.7.0 (treffsicher) — Preise aus der Rechnung: markiert nur, wenn sie von
            // der Preiszeile des Vertrags am selben Tag abweichen oder keine bekannt
            // ist; passt der Preis (Rundung), ist er vorhanden, ohne Marke
            // v3.7.0 (treffsicher, Nachbesserung 2) — ein Nettopreis mit gelesenem Satz geht
            // brutto in den Vertrag (Hinweis net_price); gleicher Nettopreis mit anderem Satz:
            // neue Preiszeile ab dem Tag der Rechnung, ohne Marke (Hinweis vat_changed)
            $taxHints = fn(array $reasons, string $field): array => array_values(array_map(fn($r) => ['field' => $field, 'reason' => $r],
                array_intersect(['net_price', 'vat_changed'], $reasons)));
            foreach ($fields['working_prices']['items'] ?? [] as $item) {
                $v = $item['value'] ?? [];
                if (!is_numeric($v['ct'] ?? null) || !is_string($v['from'] ?? null)) continue;
                $p = $this->priceEntry('contract_price', 'workingPrice', $ct, 'working_prices', 'ct_per_kwh', (string)$v['from'],
                    Evidence::grossOf((float)$v['ct'], Evidence::itemTax($item)),
                    ['working_prices'], ['core' => [], 'check' => ($item['status'] ?? '') === 'check',
                        'reasons' => ($item['status'] ?? '') === 'check' ? [['field' => 'working_prices', 'reasons' => array_values((array)($item['reasons'] ?? []))]] : [],
                        'hints' => $taxHints((array)($item['reasons'] ?? []), 'working_prices')]);
                if (in_array('price_matches', (array)($item['reasons'] ?? []), true)) { $p['status'] = 'exists'; $p['detail'] = null; }
                $out[] = $p;
            }
            $bp = self::value($e, 'base_price');
            if (is_numeric($bp) && is_string($from)) {
                $bp = Evidence::grossOf((float)$bp, Evidence::fieldTax($fields, 'base_price'));
                $monthly = self::value($e, 'base_price_period') === 'year' ? round($bp / 12, 4) : $bp;
                $p = $this->priceEntry('contract_base_price', 'basePrice', $ct, 'base_prices', 'eur_per_month', $from, $monthly,
                    ['base_price', 'base_price_period'], ['hints' => $taxHints((array)($fields['base_price']['reasons'] ?? []), 'base_price')]);
                if (in_array('price_matches', (array)($fields['base_price']['reasons'] ?? []), true)) { $p['status'] = 'exists'; $p['detail'] = null; }
                $out[] = $p;
            }
        }
        // v3.7.0 (treffsicher) — kein Vertrag dieses Versorgers: aus der Rechnung anlegen (Angebot)
        if ($ct === null && $m !== null && self::standardContract($u) && is_string($from) && is_string($to) && $from <= $to) {
            $p = $this->contractFromInvoice($c, $from, $to);
            if ($p !== null) $out[] = $p;
        }
        array_push($out, ...$this->identifiers($c));
        return $out;
    }

    /**
     * Vertrag für den neuen Abschlag: der Vertrag desselben Versorgers, der ab
     * dann läuft (oft der Folgevertrag), sonst der zugeordnete, solange er dann
     * noch gilt. Liefert ab dann ein anderer Versorger, gibt es keinen.
     */
    private function advanceTarget(string $u, ?array $m, array $ct, string $from): ?array
    {
        $name = self::providerOf($ct);
        if ($m !== null && $name !== '') {
            $t = $this->contractOn($u, (string)$m['id'], $from, $name);
            if ($t !== null) return $t;
        }
        return $this->contracts->resolveForDate([$ct], $from) !== null ? $ct : null;
    }

    /**
     * v3.7.0 (treffsicher) — „Vertrag aus diesem Dokument anlegen“: eine Rechnung
     * mit Zeitraum und Versorger, zu der kein Vertrag dieses Versorgers passt.
     * Beginn = Beginn des Zeitraums (der Vertrag kann früher begonnen haben),
     * eine Schlussrechnung endet mit ihrem Zeitraum. Preise und Abschlag aus der
     * Rechnung nur, wenn sicher; sonst bleiben sie offene Punkte. Nur als
     * Angebot (nicht vorbelegt), das Dokument hängt an der Rechnung.
     */
    private function contractFromInvoice(array $c, string $from, string $to): ?array
    {
        $e = $c['entry']; $u = $c['u']; $m = $c['meter'];
        $supplier = trim((string)(self::value($e, 'supplier') ?? ''));
        if ($supplier === '') return null;
        $withheld = [];
        $final = self::sure($e, 'kind', $withheld) === 'final';
        $wp = [];
        foreach ((array)($e['extraction']['fields']['working_prices']['items'] ?? []) as $item) {
            $v = $item['value'] ?? [];
            if (!is_numeric($v['ct'] ?? null) || !is_string($v['from'] ?? null)) continue;
            if (($item['status'] ?? '') === 'check') { $withheld[] = 'working_prices'; continue; }
            $wp[] = ['from' => max($from, (string)$v['from']), 'ct_per_kwh' => Evidence::grossOf((float)$v['ct'], Evidence::itemTax($item))];
        }
        $bp = self::sure($e, 'base_price', $withheld);
        if (is_numeric($bp)) $bp = Evidence::grossOf((float)$bp, Evidence::fieldTax((array)$e['extraction']['fields'], 'base_price'));   // Nachbesserung 2 — brutto
        $per = self::sure($e, 'base_price_period', $withheld);
        $adv = self::sure($e, 'advance_new', $withheld);
        $advFrom = self::sure($e, 'advance_new_from', $withheld);
        $data = array_filter([
            'meter_id' => (string)$m['id'], 'provider' => $supplier, 'start' => $from,
            'end' => $final ? $to : null, 'auto_renews' => $final ? false : null,
            'working_prices' => $wp,
            'base_prices' => is_numeric($bp) && !Utilities::isFeedIn($u) ? [['from' => $from, 'eur_per_month' => $per === 'year' ? round((float)$bp / 12, 4) : (float)$bp]] : [],
            'advance_payments' => !$final && is_numeric($adv) && is_string($advFrom) && !Utilities::isFeedIn($u) ? [['from' => $advFrom, 'amount_eur' => (float)$adv]] : [],
            'contract_number' => self::sure($e, 'contract_number', $withheld),
            'customer_number' => self::sure($e, 'customer_number', $withheld),
        ], fn($v) => $v !== null && $v !== '');
        [$status, $detail] = $this->compareContract($u, (string)$m['id'], $from, $supplier);
        $open = self::openPoints($e, array_merge(['working_prices', 'base_price'], $final ? [] : ['advance_new']), array_values(array_unique($withheld)));
        return self::p('contract_create', 'contractFromInvoice', ['provider' => $supplier, 'from' => $from], $data,
            ['supplier', 'period_from', 'period_to', 'kind', 'working_prices', 'base_price', 'base_price_period', 'advance_new', 'advance_new_from', 'contract_number', 'customer_number'],
            $status, ['core' => ['supplier', 'period_from', 'period_to'], 'optional' => true, 'detail' => $detail, 'open' => $open]);
    }

    private function confirmation(array $c): array
    {
        $e = $c['entry']; $u = $c['u']; $m = $c['meter']; $out = [];
        $start = self::value($e, 'supply_start');
        if ($m !== null && is_string($start) && self::standardContract($u)) {
            $wp = self::value($e, 'working_price');
            $bp = self::value($e, 'base_price');
            $monthly = is_numeric($bp) ? (self::value($e, 'base_price_period') === 'year' ? round((float)$bp / 12, 4) : (float)$bp) : null;
            // v3.7.0 (treffsicher) — Der Vertrag braucht Beginn, Versorger und Preise; alles
            // Weitere übernimmt er nur, wenn es sicher ist. Ein unsicherer oder fehlender
            // Abschlag (Tarif, Frist …) markiert den Vertrag nicht, er bleibt ein offener Punkt.
            $withheld = [];
            $adv = self::sure($e, 'advance', $withheld);
            $months = self::sure($e, 'min_term_months', $withheld);
            $notice = self::notice((string)(self::sure($e, 'notice_period', $withheld) ?? ''));
            $bonus = self::sure($e, 'bonus', $withheld);
            $data = array_filter([
                'meter_id' => (string)$m['id'],
                'provider' => (string)(self::value($e, 'supplier') ?? ''),
                'tariff_name' => (string)(self::sure($e, 'tariff_name', $withheld) ?? ''),
                'start' => $start,
                'working_prices' => is_numeric($wp) ? [['from' => $start, 'ct_per_kwh' => (float)$wp]] : [],
                'base_prices' => $monthly !== null && !Utilities::isFeedIn($u) ? [['from' => $start, 'eur_per_month' => $monthly]] : [],
                'advance_payments' => is_numeric($adv) && !Utilities::isFeedIn($u) ? [['from' => $start, 'amount_eur' => (float)$adv]] : [],
                'min_term_end' => is_numeric($months) && (int)$months > 0 ? self::termEnd($start, (int)$months) : null,
                'notice_period_months' => $notice['months'],
                'notice_period_days' => $notice['days'],
                'price_guarantee_until' => self::sure($e, 'price_guarantee_until', $withheld),
                'signup_bonus_eur' => is_numeric($bonus) ? (float)$bonus : null,
                'contract_number' => self::sure($e, 'contract_number', $withheld),
                'customer_number' => self::sure($e, 'customer_number', $withheld),
                'attachment_ids' => $c['att'] !== '' ? [$c['att']] : [],
            ], fn($v) => $v !== null && $v !== '');
            [$status, $detail] = $this->compareContract($u, (string)$m['id'], $start, (string)($data['provider'] ?? ''));
            $p = self::p('contract_create', 'contract', ['provider' => $data['provider'] ?? '', 'tariff' => $data['tariff_name'] ?? '', 'from' => $start],
                $data, ['supply_start', 'supplier', 'tariff_name', 'working_price', 'base_price', 'base_price_period', 'advance', 'min_term_months', 'notice_period', 'bonus', 'price_guarantee_until'], $status,
                ['core' => ['supply_start', 'supplier', 'working_price', 'base_price', 'base_price_period'],
                 'open' => self::openPoints($e, ['working_price', 'base_price', 'advance', 'notice_period', 'tariff_name', 'min_term_months', 'bonus', 'price_guarantee_until'], $withheld)]);
            $p['open'] = array_values(array_filter($p['open'], fn($o) => $o['reason'] === 'unsure' || in_array($o['field'], ['working_price', 'base_price', 'advance', 'notice_period'], true)));
            $p['detail'] = $detail;
            $out[] = $p;
            // Vorgänger ohne Ende (oder mit späterem): endet am Vortag. v3.7.0 (treffsicher,
            // Nachbesserung nach dem Nutzentest) — vorbelegt, wenn der jüngste Vorgänger am
            // selben Zähler läuft und es ein Wechsel ist (anderer Versorger) oder ein
            // Tarifwechsel (derselbe Versorger, anderer Tarif oder andere Vertragsnummer);
            // als Angebot bei Gruppenvertrag oder Subzähler (uneindeutig), bei offenbar
            // demselben Vertrag und bei älteren Vorgängern. Ein Ende, das eine Kündigung
            // belegt (`auto_renews: false`), ist ein Widerspruch. Bis dahin nur als Angebot —
            // im Nutzentest überschnitten sich so 6 von 7 Vertragsübergängen.
            $dayBefore = date('Y-m-d', (int)strtotime($start . ' -1 day'));
            $prevs = [];
            foreach ($this->contractsOf($u, (string)$m['id']) as $prev) {
                if (!empty($prev['is_shadow']) || (string)($prev['start'] ?? '') >= $start) continue;
                if (!empty($prev['end']) && (string)$prev['end'] <= $dayBefore) continue;
                $prevs[] = $prev;
            }
            usort($prevs, fn($a, $b) => strcmp((string)($b['start'] ?? ''), (string)($a['start'] ?? '')));
            foreach ($prevs as $i => $prev) {
                $backed = !empty($prev['end']) && ($prev['auto_renews'] ?? null) === false;
                // ein Gruppenvertrag trägt keine meter_id: nicht dasselbe Ziel
                $sameTarget = (string)($prev['meter_id'] ?? '') === (string)$m['id'] && empty($m['parent_meter_id']);
                // ein Widerspruch (belegtes Ende) ist ohnehin nie vorbelegt
                $pre = $i === 0 && $sameTarget && self::isContractChange($prev, $data);
                $p = self::p('contract_end', 'predecessorEnd', ['provider' => (string)($prev['provider'] ?? ''), 'date' => $dayBefore],
                    ['contract_id' => (string)$prev['id'], 'end' => $dayBefore, 'auto_renews' => false], ['supply_start'], $backed ? 'conflict' : 'new',
                    $pre ? [] : ['optional' => true]);
                if ($backed) $p['detail'] = ['end' => (string)$prev['end']];
                $out[] = $p;
            }
        }
        array_push($out, ...$this->identifiers($c));
        return $out;
    }

    private function priceChange(array $c): array
    {
        $e = $c['entry']; $u = $c['u']; $ct = $c['contract']; $out = [];
        $from = self::value($e, 'valid_from');
        if ($ct !== null && is_string($from) && self::standardContract($u)) {
            $wp = self::value($e, 'working_price_new');
            if (is_numeric($wp)) {
                $out[] = $this->priceEntry('contract_price', 'workingPriceChange', $ct, 'working_prices', 'ct_per_kwh', $from, (float)$wp,
                    ['working_price_new', 'valid_from'], [], ['old' => self::value($e, 'working_price_old')]);
            }
            $bp = self::value($e, 'base_price_new');
            if (is_numeric($bp) && !Utilities::isFeedIn($u)) {
                $monthly = self::value($e, 'base_price_period') === 'year' ? round((float)$bp / 12, 4) : (float)$bp;
                $out[] = $this->priceEntry('contract_base_price', 'basePriceChange', $ct, 'base_prices', 'eur_per_month', $from, $monthly,
                    ['base_price_new', 'base_price_period', 'valid_from'], [], ['old' => self::value($e, 'base_price_old')]);
            }
            $until = self::value($e, 'special_termination_until');
            if (is_string($until) && $until !== '') {
                $out[] = $this->reminder($c, 'specialTermination', $until, ['provider' => (string)($ct['provider'] ?? '')], ['special_termination_until']);
            }
        }
        array_push($out, ...$this->identifiers($c));
        return $out;
    }

    private function cancellation(array $c): array
    {
        $e = $c['entry']; $ct = $c['contract']; $out = [];
        $end = self::value($e, 'supply_end');
        if ($ct !== null && is_string($end)) {
            $cur = (string)($ct['end'] ?? '');
            $status = $cur === $end && ($ct['auto_renews'] ?? null) === false ? 'exists' : ($cur !== '' && $cur !== $end ? 'conflict' : 'new');
            $p = self::p('contract_end', 'contractEnd', ['provider' => (string)($ct['provider'] ?? ''), 'date' => $end],
                ['contract_id' => (string)$ct['id'], 'end' => $end, 'auto_renews' => false, 'attachment' => $c['att']], ['supply_end'], $status);
            if ($status === 'conflict') $p['detail'] = ['end' => $cur];
            $out[] = $p;
            // v3.7.0 (Paket betrieb) — ohne Anschlussvertrag ein Termin vor dem Lieferende
            $due = self::followUpDue($end, date('Y-m-d'));
            $target = (string)($c['meter']['id'] ?? (($ct['meter_group_id'] ?? '') ?: ($ct['meter_id'] ?? '')));
            $list = $target !== '' ? $this->contractsOf($c['u'], $target) : [];
            if ($due !== null && ContractService::successorOf($ct, $list) === null) {
                $out[] = $this->reminder($c, 'followUp', $due, ['provider' => (string)($ct['provider'] ?? ''), 'end' => $end], ['supply_end']);
            }
        }
        array_push($out, ...$this->identifiers($c));
        return $out;
    }

    /**
     * Fälligkeit des Termins „Anschlussvertrag abschließen“: FOLLOW_UP_LEAD_DAYS
     * vor dem Lieferende, frühestens heute; nach dem Lieferende keiner mehr.
     */
    public static function followUpDue(string $end, string $today): ?string
    {
        if ($end < $today) return null;
        return max($today, date('Y-m-d', (int)strtotime("$end -" . self::FOLLOW_UP_LEAD_DAYS . ' days')));
    }

    private function supplyStart(array $c): array
    {
        $e = $c['entry']; $ct = $c['contract']; $out = [];
        if ($c['meter'] !== null) {
            $p = $this->readingProposal($c, 'supply_start', 'reading_start', false);
            if ($p !== null) $out[] = $p;
        }
        $start = self::value($e, 'supply_start');
        if ($ct !== null && is_string($start) && (string)($ct['start'] ?? '') !== $start) {
            $out[] = self::p('contract_start', 'contractStart', ['provider' => (string)($ct['provider'] ?? ''), 'date' => $start, 'old' => (string)($ct['start'] ?? '')],
                ['contract_id' => (string)$ct['id'], 'start' => $start], ['supply_start'], 'conflict', ['detail' => ['start' => (string)($ct['start'] ?? '')]]);
        }
        // v3.7.0 (treffsicher) — Lieferbeginn ohne passenden Vertrag: „Vertrag anlegen“ statt nichts
        $supplier = trim((string)(self::value($e, 'supplier') ?? ''));
        if ($ct === null && $c['meter'] !== null && is_string($start) && $supplier !== '' && self::standardContract($c['u'])) {
            $withheld = [];
            $data = array_filter(['meter_id' => (string)$c['meter']['id'], 'provider' => $supplier, 'start' => $start,
                'contract_number' => self::sure($e, 'contract_number', $withheld), 'customer_number' => self::sure($e, 'customer_number', $withheld),
                'attachment_ids' => $c['att'] !== '' ? [$c['att']] : []], fn($v) => $v !== null && $v !== '');
            [$status, $detail] = $this->compareContract($c['u'], (string)$c['meter']['id'], $start, $supplier);
            $out[] = self::p('contract_create', 'contractFromSupplyStart', ['provider' => $supplier, 'from' => $start], $data,
                ['supply_start', 'supplier', 'contract_number', 'customer_number'], $status,
                ['core' => ['supply_start', 'supplier'], 'optional' => true, 'detail' => $detail,
                 'open' => [['field' => 'working_price', 'reason' => 'missing'], ['field' => 'base_price', 'reason' => 'missing']]]);
        }
        array_push($out, ...$this->identifiers($c));
        return $out;
    }

    private function meterChange(array $c): array
    {
        $e = $c['entry']; $m = $c['meter']; $out = [];
        $date = self::value($e, 'change_date');
        $old = self::value($e, 'old_reading');
        if ($m !== null && is_string($date) && is_numeric($old) && Utilities::isCumulative($c['u'])) {
            $newSerial = (string)(self::value($e, 'new_meter_number') ?? '');
            $exists = false;
            foreach ($m['devices'] ?? [] as $d) {
                if ((string)($d['installed_on'] ?? '') === $date && $d !== ($m['devices'][0] ?? null)) $exists = true;
                if ($newSerial !== '' && self::sameId((string)($d['serial'] ?? ''), $newSerial)) $exists = true;
            }
            $open = null;
            foreach ($m['devices'] ?? [] as $d) if (empty($d['removed_on'])) $open = $d;
            $oldSerial = (string)(self::value($e, 'old_meter_number') ?? '');
            $p = self::p('meter_change', 'meterChange', ['date' => $date, 'serial' => $newSerial],
                array_filter(['meter_id' => (string)$m['id'], 'date' => $date, 'old_final_counter' => (float)$old,
                    'new_initial_counter' => is_numeric(self::value($e, 'new_reading')) ? (float)self::value($e, 'new_reading') : null,
                    'serial' => $newSerial !== '' ? $newSerial : null, 'reason' => 'inbox'], fn($v) => $v !== null),
                ['change_date', 'old_reading', 'new_reading', 'new_meter_number', 'old_meter_number'], $exists ? 'exists' : 'new');
            if (!$exists && $oldSerial !== '' && $open !== null && (string)($open['serial'] ?? '') !== '' && !self::sameId((string)$open['serial'], $oldSerial)) {
                $p['status'] = 'conflict';
                $p['detail'] = ['serial' => (string)$open['serial']];
            }
            $out[] = $p;
        }
        return $out;
    }

    private function readingRequest(array $c): array
    {
        $by = self::value($c['entry'], 'requested_by');
        if (!is_string($by)) return [];
        $name = $c['meter']['name'] ?? (string)(self::value($c['entry'], 'meter_number') ?? '');
        return [$this->reminder($c, 'reading', $by, ['meter' => (string)$name], ['requested_by', 'meter_number'])];
    }

    private function advanceChange(array $c): array
    {
        $e = $c['entry']; $ct = $c['contract'];
        $adv = self::value($e, 'advance_new'); $from = self::value($e, 'valid_from');
        if ($ct === null || !is_numeric($adv) || !is_string($from)) return [];
        return [$this->priceEntry('contract_advance', 'advance', $ct, 'advance_payments', 'amount_eur', $from, (float)$adv, ['advance_new', 'valid_from'])];
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    /** Gas, Strom, Fernwärme, Einspeisung: Verträge mit Preiszeilen (Wasser hat drei Bestandteile, die erfasst man von Hand). */
    public static function standardContract(string $u): bool
    {
        return Utilities::hasContracts($u) && $u !== 'wasser';
    }

    private function readingProposal(array $c, string $dateField, string $valueField, bool $optional): ?array
    {
        $date = self::value($c['entry'], $dateField);
        $value = self::value($c['entry'], $valueField);
        if (!is_string($date) || !is_numeric($value)) return null;
        // v3.7.0 (treffsicher, Nachbesserung nach dem Nutzentest) — der Anfangsstand der
        // Rechnung ist vorbelegt, wenn an dem Tag noch kein Stand steht und er zwischen die
        // bekannten Stände passt (samt Endstand derselben Rechnung). Ohne ihn fehlte im
        // Nutzentest jede zweite Ablesung, und die Menge stimmte seltener.
        $extra = [];
        if ($optional && $valueField === 'reading_old') {
            $newDate = self::value($c['entry'], 'reading_new_date');
            $newValue = self::value($c['entry'], 'reading_new');
            $extra = is_string($newDate) && is_numeric($newValue) ? [['date' => $newDate, 'counter' => (float)$newValue]] : [];
        }
        // Nachbesserung 4 — ein geschätzter Stand (Evidence::readEstimates) wird als geschätzt angelegt
        return $this->readingAt($c, $date, (float)$value, [$valueField, $dateField], $optional, $extra, [],
            self::value($c['entry'], $valueField . '_estimated') === true);
    }

    /**
     * Ablesung am zugeordneten Zähler: vorhanden, Widerspruch (anderer Stand am Tag)
     * oder neu. Ein Anfangsstand (`$optional`) ist vorbelegt, wenn er zwischen die
     * bekannten Stände passt (fitsBetween, mit `$extra`).
     *
     * @param list<string> $sources
     * @param list<array{date: string, counter: float}> $extra
     * @param array<string,mixed> $more check, reasons, core
     * @param bool $estimated Nachbesserung 4 — die Rechnung weist den Stand als geschätzt aus:
     *        angelegt mit `is_estimated` (zählt in der Monatskennung nicht als Messung)
     */
    private function readingAt(array $c, string $date, float $value, array $sources, bool $optional, array $extra = [], array $more = [], bool $estimated = false): ?array
    {
        $m = $c['meter'];
        if ($m === null || !Utilities::isCumulative($c['u']) || ($m['capture'] ?? 'counter') === 'period') return null;
        $status = 'new'; $detail = null;
        foreach ($this->readings->list($c['u'], (string)$m['id']) as $r) {
            if (($r['date'] ?? '') !== $date) continue;
            if (abs((float)$r['counter'] - $value) < 0.0005) { $status = 'exists'; break; }
            $status = 'conflict'; $detail = ['reading_id' => (string)$r['id'], 'counter' => (float)$r['counter']];
        }
        if ($optional && $status === 'new' && $this->fitsBetween($c['u'], $m, $date, $value, $extra)) $optional = false;
        return self::p('reading', $estimated ? 'readingEstimated' : 'reading', ['date' => $date, 'counter' => $value, 'meter' => (string)($m['name'] ?? '')],
            ['meter_id' => (string)$m['id'], 'date' => $date, 'counter' => $value] + ($estimated ? ['is_estimated' => true] : []), $sources, $status,
            $more + ['detail' => $detail] + ($optional ? ['optional' => true] : []));
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 4) — Stände je Zähler (`meter_readings`).
     * Zugeordnet nach Zählernummer (sameId gegen die Geräte des zugeordneten
     * Zählers): Ein Eintrag des Zählers ergibt Ablesungen wie die Einzelfelder (ohne
     * Doppel). Steht genau ein Eintrag mit fremder Nummer neben genau einem des
     * Zählers, ist das ein Zählertausch mit beiden Ständen (Endstand alt,
     * Anfangsstand neu): Vorschlag `meter_change`, vorbelegt, wenn Nummern und
     * Stände zusammenpassen (swapFromReadings). Die Stände am Tauschtag trägt dann
     * der Tausch, nicht eine Ablesung (am Tauschtag gehört eine Ablesung zum neuen
     * Gerät, `>= removed_on`). Ohne Paar bleibt ein fremder Eintrag ohne Vorschlag;
     * dieselbe Nummer zweimal (Zweitarifzähler) ebenso.
     * Nachbesserung 5: Einträge derselben Nummer kommen zusammengefasst
     * (Evidence::mergeReadings) — ihre Zwischenstände (`between`) werden Ablesungen
     * wie der Endstand. Die Stände des neuen Zählers sind nur vorbelegt, wenn der
     * Tausch es ist; stehen mehrere Nummern auf der Rechnung, ist ein Stand der
     * Einzelfelder nur vorbelegt, wenn ein vorbelegbarer Eintrag ihn trägt.
     *
     * @param list<array<string,mixed>> $out Vorschläge der Rechnung bisher
     * @return list<array<string,mixed>>
     */
    private function meterReadings(array $c, array $out): array
    {
        $m = $c['meter'];
        $items = array_values(array_filter((array)($c['entry']['extraction']['fields']['meter_readings']['items'] ?? []),
            fn($it) => is_string($it['value']['meter_number'] ?? null) && trim((string)$it['value']['meter_number']) !== ''));
        if ($m === null || $items === [] || !Utilities::isCumulative($c['u']) || ($m['capture'] ?? 'counter') === 'period') return $out;
        foreach ($items as $i => $a) {
            foreach (array_slice($items, $i + 1) as $b) if (self::sameId((string)$a['value']['meter_number'], (string)$b['value']['meter_number'])) return $out;
        }
        $serials = array_values(array_filter(array_map(fn($d) => (string)($d['serial'] ?? ''), (array)($m['devices'] ?? []))));
        $own = []; $foreign = [];
        foreach ($items as $it) {
            $n = (string)$it['value']['meter_number'];
            if (array_filter($serials, fn($s) => self::sameId($s, $n)) !== []) $own[] = $it; else $foreign[] = $it;
        }
        $swap = count($own) === 1 && count($foreign) === 1 ? $this->swapFromReadings($c, $own[0], $foreign[0]) : null;
        // Nachbesserung 5 — Stände des neuen Zählers gehören erst nach dem Tausch an sein Gerät:
        // vorbelegt nur, wenn der Tausch es ist (oder schon angelegt); sonst landeten sie am alten
        $foreignOk = $swap !== null && ($swap['status'] === 'exists' || empty($swap['optional']));
        $flag = fn(array $it): array => ($it['status'] ?? '') === 'check'
            ? ['check' => true, 'core' => [], 'reasons' => [['field' => 'meter_readings', 'reasons' => array_values((array)($it['reasons'] ?? []))]]]
            : ['core' => []];
        // Stände eines Eintrags: Anfang, Zwischenstände (zusammengefasste Zeiträume, Evidence::mergeReadings), Ende;
        // geschätzt je Stand, wo zusammengefasst (`estimated_ends`, `between`), sonst je Eintrag (Nachbesserung 4)
        $stands = function (array $it): array {
            $v = (array)$it['value'];
            $ends = (array)($it['estimated_ends'] ?? [!empty($v['estimated']), !empty($v['estimated'])]);
            $all = [['date' => $v['reading_old_date'] ?? null, 'counter' => $v['reading_old'] ?? null, 'estimated' => !empty($ends[0]), 'first' => true]];
            foreach ((array)($it['between'] ?? []) as $b) $all[] = ['date' => $b['date'] ?? null, 'counter' => $b['counter'] ?? null, 'estimated' => !empty($b['estimated'])];
            $all[] = ['date' => $v['reading_new_date'] ?? null, 'counter' => $v['reading_new'] ?? null, 'estimated' => !empty($ends[1]), 'last' => true];
            return array_values(array_filter($all, fn($s) => is_string($s['date']) && is_numeric($s['counter'])));
        };
        $wanted = [];   // [Eintrag, Stand, Anfangsstand?, nie vorbelegt?]
        foreach ($own as $it) foreach ($stands($it) as $s) {
            if (!empty($s['last']) && $swap !== null) continue;                                   // beim Tausch: Endstand am Gerät
            $wanted[] = [$it, $s, !empty($s['first']), false];
        }
        if ($swap !== null) {
            foreach ($stands($foreign[0]) as $s) if (empty($s['first'])) $wanted[] = [$foreign[0], $s, false, !$foreignOk];   // Anfangsstand am Gerät
            $day = (string)$swap['data']['date'];
            $out = array_values(array_filter($out, fn($p) => $p['kind'] !== 'reading' || $p['status'] === 'exists' || ($p['data']['date'] ?? '') !== $day));
        }
        $proposed = [];   // [Tag, Stand] aus den Ständen je Zähler, die vorbelegt sein dürfen
        foreach ($wanted as [$it, $s, $optional, $never]) {
            $v = (array)$it['value'];
            $day = (string)$s['date']; $value = (float)$s['counter'];
            if ($swap !== null && $day === $swap['data']['date']) continue;
            if (!$never) $proposed[] = [$day, $value];
            $known = array_filter($out, fn($p) => $p['kind'] === 'reading' && ($p['data']['date'] ?? '') === $day);
            $same = array_filter($known, fn($p) => abs((float)$p['data']['counter'] - $value) < 0.0005);
            $extra = $optional ? array_map(fn($x) => ['date' => (string)$x['date'], 'counter' => (float)$x['counter']], array_slice($stands($it), 1)) : [];
            $more = $flag($it);
            // ein anderer Stand am selben Tag aus den Einzelfeldern oder ein Gerät, das an dem Tag
            // eine andere Nummer trägt: Widerspruch, markiert (ein Stand am falschen Gerät verfälscht den Verbrauch)
            $dev = in_array($it, $own, true) ? $this->meters->deviceOnDate($m, $day) : null;
            $otherDevice = $dev !== null && (string)($dev['serial'] ?? '') !== '' && !self::sameId((string)$dev['serial'], (string)$v['meter_number']);
            if (($known !== [] && $same === []) || $otherDevice) $more = ['check' => true, 'core' => [], 'reasons' => [['field' => 'meter_readings', 'reasons' => ['inconsistent']]]];
            // Nachbesserung 4 — „geschätzt“ am Eintrag gilt für seine Stände (das Zitat trennt sie nicht)
            $est = $s['estimated'];
            $p = $this->readingAt($c, $day, $value, ['meter_readings'], $optional, $extra, $more, $est);
            if ($p === null) continue;
            if ($never) $p['optional'] = true;
            if ($same !== []) {
                // derselbe Stand aus den Einzelfeldern: einmal. Passt er erst mit dem Endstand
                // desselben Zählers (beim Tausch nicht der Endstand der Rechnung), gilt dieser
                // Vorschlag; gehört er an dem Tag zu einem anderen Gerät, gilt die Marke
                $keep = !$otherDevice && array_filter($same, fn($q) => empty($q['optional']) || !empty($p['optional']) || !empty($p['check'])) !== [];
                if ($keep && $est) {
                    // geschätzt laut Stände je Zähler: auch der Vorschlag der Einzelfelder (lieber kein Messwert als ein falscher)
                    foreach ($out as $k => $q) if (in_array($q, $same, true) && empty($q['data']['is_estimated'])) {
                        $out[$k]['data']['is_estimated'] = true;
                        $out[$k]['label_key'] = 'inbox.proposal.readingEstimated';
                    }
                }
                if ($keep) continue;
                $out = array_values(array_filter($out, fn($q) => !in_array($q, $same, true)));
            }
            $out[] = $p;
        }
        // Nachbesserung 5 — mehrere Nummern auf der Rechnung: Ein Stand der Einzelfelder (ein Stand
        // je Rechnung, das Modell wählt) ist nur vorbelegt, wenn ein Eintrag ihn an diesem Gerät
        // trägt — sonst kann es der Endstand des neuen Zählers sein, am alten Gerät
        if (count($items) > 1) foreach ($out as $k => $q) {
            if ($q['kind'] !== 'reading' || in_array('meter_readings', (array)$q['sources'], true)) continue;
            $mine = array_filter($proposed, fn($x) => $x[0] === ($q['data']['date'] ?? '') && abs($x[1] - (float)$q['data']['counter']) < 0.0005);
            if ($mine === []) $out[$k]['optional'] = true;
        }
        if ($swap !== null) $out[] = $swap;
        return $out;
    }

    /**
     * Zählertausch aus zwei Einträgen von `meter_readings`: `$o` mit der Nummer
     * eines Geräts des Zählers (Endstand = `reading_new`), `$f` mit fremder Nummer
     * (Anfangsstand = `reading_old`, Tauschtag = sein `reading_old_date`). Beide
     * Stände sind Pflicht — ein fehlender Anfangsstand würde sonst still 0.
     * Vorbelegt nur, wenn alles zusammenpasst: `$o` trägt die Nummer des Geräts,
     * das bis zum Tauschtag lief; Ausbau am selben Tag oder am Vortag; der
     * Endstand nicht kleiner als der Anfangsstand des alten und als jeder bekannte
     * Stand des alten Geräts; der Endstand des neuen nicht kleiner als sein
     * Anfangsstand; noch kein Stand ab dem Tauschtag; nichts markiert.
     *
     * @param array<string,mixed> $o
     * @param array<string,mixed> $f
     */
    private function swapFromReadings(array $c, array $o, array $f): ?array
    {
        $m = $c['meter']; $ov = (array)$o['value']; $fv = (array)$f['value'];
        $final = $ov['reading_new'] ?? null; $initial = $fv['reading_old'] ?? null; $date = $fv['reading_old_date'] ?? null;
        if (!is_numeric($final) || !is_numeric($initial) || !is_string($date)) return null;
        $serial = (string)$fv['meter_number'];
        $exists = false;
        foreach (array_values((array)($m['devices'] ?? [])) as $i => $d) {
            if (self::sameId((string)($d['serial'] ?? ''), $serial) || ($i > 0 && (string)($d['installed_on'] ?? '') === $date)) $exists = true;
        }
        $before = $this->meters->deviceOnDate($m, date('Y-m-d', (int)strtotime($date . ' -1 day')));
        $removed = $ov['reading_new_date'] ?? null;
        $flagged = ($o['status'] ?? '') === 'check' || ($f['status'] ?? '') === 'check';
        $fits = !$exists && !$flagged && $before !== null
            && self::sameId((string)($before['serial'] ?? ''), (string)$ov['meter_number'])
            && is_string($removed) && ($removed === $date || date('Y-m-d', (int)strtotime($removed . ' +1 day')) === $date)
            && (!is_numeric($ov['reading_old'] ?? null) || (float)$final >= (float)$ov['reading_old'] - 0.0005)
            && (!is_numeric($fv['reading_new'] ?? null) || (float)$fv['reading_new'] >= (float)$initial - 0.0005)
            && $this->finalFits($c['u'], $m, $before, $date, (float)$final);
        $reasons = array_values(array_unique([...((array)($o['reasons'] ?? [])), ...((array)($f['reasons'] ?? []))]));
        return self::p('meter_change', 'meterSwapInvoice',
            ['date' => $date, 'serial' => $serial, 'old_serial' => (string)$ov['meter_number'], 'old_final' => (float)$final, 'new_initial' => (float)$initial],
            ['meter_id' => (string)$m['id'], 'date' => $date, 'old_final_counter' => (float)$final, 'new_initial_counter' => (float)$initial,
             'serial' => $serial, 'reason' => 'inbox'],
            ['meter_readings'], $exists ? 'exists' : 'new',
            ['core' => [], 'check' => $flagged, 'reasons' => $flagged ? [['field' => 'meter_readings', 'reasons' => $reasons]] : []]
            + ($fits || $exists ? [] : ['optional' => true]));
    }

    /** Passt der Endstand zum alten Gerät: kein bekannter Stand darüber, noch keiner ab dem Tauschtag? */
    private function finalFits(string $u, array $meter, array $device, string $date, float $final): bool
    {
        foreach ($this->readings->list($u, (string)$meter['id']) as $r) {
            if (!empty($r['is_future']) || !is_numeric($r['counter'] ?? null)) continue;
            $d = (string)($r['date'] ?? '');
            if ($d >= $date) return false;   // schon Stände ab dem Tauschtag: zu welchem Gerät, bliebe offen
            if ($d >= (string)($device['installed_on'] ?? '') && (float)$r['counter'] > $final + 0.0005) return false;
        }
        return true;
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung) — Passt ein Stand an einem Tag zwischen den
     * vorigen und den nächsten bekannten Stand des Zählers (monoton)? Liegt ein
     * Zählertausch zwischen den Nachbarn (Einbau eines weiteren Geräts oder Ausbau,
     * Stichtag-Konvention `>= removed_on`), ist das nicht zu entscheiden: nein.
     *
     * @param list<array{date: string, counter: float}> $extra weitere Stände (etwa der Endstand derselben Rechnung)
     */
    private function fitsBetween(string $u, array $meter, string $date, float $value, array $extra = []): bool
    {
        $prev = null; $next = null;
        foreach ([...$this->readings->list($u, (string)$meter['id']), ...$extra] as $r) {
            if (!empty($r['is_future']) || !is_numeric($r['counter'] ?? null)) continue;
            $d = (string)($r['date'] ?? '');
            if ($d < $date && ($prev === null || $d > $prev['date'])) $prev = ['date' => $d, 'counter' => (float)$r['counter']];
            if ($d > $date && ($next === null || $d < $next['date'])) $next = ['date' => $d, 'counter' => (float)$r['counter']];
        }
        $lo = $prev['date'] ?? $date;
        $hi = $next['date'] ?? $date;
        foreach (array_values((array)($meter['devices'] ?? [])) as $i => $dev) {
            foreach ([$i > 0 ? ($dev['installed_on'] ?? null) : null, $dev['removed_on'] ?? null] as $b) {
                if (is_string($b) && $b !== '' && $b > $lo && $b <= $hi) return false;
            }
        }
        if ($prev !== null && $value < $prev['counter'] - 0.0005) return false;
        return $next === null || $value <= $next['counter'] + 0.0005;
    }

    /**
     * Eine datierte Zeile (Preis, Grundpreis, Abschlag) am Vertrag: gleiche
     * Zeile da → vorhanden, gleiches Datum mit anderem Wert → Widerspruch.
     */
    private function priceEntry(string $kind, string $label, array $ct, string $list, string $key, string $from, float $value, array $sources,
                                array $extra = [], array $params = []): array
    {
        $status = 'new'; $detail = null;
        foreach ((array)($ct[$list] ?? []) as $row) {
            if ((string)($row['from'] ?? '') !== $from) continue;
            if (abs((float)($row[$key] ?? 0) - $value) < 0.00005) { $status = 'exists'; break; }
            $status = 'conflict'; $detail = ['value' => (float)($row[$key] ?? 0)];
        }
        return self::p($kind, $label, ['from' => $from, 'value' => $value, 'provider' => (string)($ct['provider'] ?? '')] + $params,
            ['contract_id' => (string)$ct['id'], 'list' => $list, 'key' => $key, 'from' => $from, 'value' => $value], $sources, $status,
            ['detail' => $detail] + $extra);
    }

    /** @param array<string,mixed> $item Eintrag der Liste `gas_factors` (Marke je Eintrag) */
    private function gasFactor(string $from, float $z, float $hs, array $item): array
    {
        $flagged = ($item['status'] ?? '') === 'check';
        $status = 'new'; $detail = null;
        $list = ConversionFactorService::normalizeList($this->settings->get('gas_conversion_factors', []), null, false);
        foreach ($list as $f) {
            if (($f['from'] ?? null) !== $from) continue;
            if (abs((float)($f['zustandszahl'] ?? 0) - $z) < 0.00005 && abs((float)($f['brennwert'] ?? 0) - $hs) < 0.0005) { $status = 'exists'; break; }
            $status = 'conflict'; $detail = ['zustandszahl' => $f['zustandszahl'] ?? null, 'brennwert' => $f['brennwert'] ?? null, 'kwh_per_m3' => $f['kwh_per_m3'] ?? null];
        }
        return self::p('gas_factor', 'gasFactor', ['from' => $from, 'z' => $z, 'hs' => $hs, 'factor' => round($z * $hs, 4)],
            ['from' => $from, 'zustandszahl' => $z, 'brennwert' => $hs], ['gas_factors'], $status, ['detail' => $detail, 'check' => $flagged,
                'core' => [], 'reasons' => $flagged ? [['field' => 'gas_factors', 'reasons' => array_values((array)($item['reasons'] ?? []))]] : []]);
    }

    /**
     * Termin (Zählerstand melden, Sonderkündigungsrecht). Vorhanden ist er, wenn
     * dieses Dokument ihn schon angelegt hat und es ihn noch gibt — der Titel
     * hängt an der Sprache und taugt nicht zum Abgleich.
     */
    private function reminder(array $c, string $label, string $due, array $params, array $sources): array
    {
        $status = 'new';
        $ids = array_column($this->reminders->list(), 'id');
        // v3.7.0 (treffsicher) — auch ein inhaltsgleiches Dokument zählt (InboxService: `duplicates_applied`)
        foreach ([...(array)($c['entry']['applied'] ?? []), ...(array)($c['entry']['duplicates_applied'] ?? [])] as $a) {
            if (($a['kind'] ?? '') !== 'reminder' || !in_array($a['ref']['id'] ?? '', $ids, true)) continue;
            $key = (string)($a['key'] ?? '');
            // v3.7.0 (Paket betrieb) — der Termin zum Anschlussvertrag rückt mit „heute“
            // (followUpDue): einer je Dokument, auch wenn sein Tag sich verschoben hat
            if ($key === "$label@$due" || ($label === 'followUp' && str_starts_with($key, 'followUp@'))) { $status = 'exists'; break; }
        }
        $target = $this->reminderTarget($c, $label);
        return self::p('reminder', 'reminder' . ucfirst($label), ['date' => $due] + $params + ['person' => $target['assignee'] ?? null],
            ['label' => $label, 'next_due' => $due, 'params' => $params] + $target, $sources, $status);
    }

    /**
     * v3.7.0 (Paket betrieb) — Daten eines Termins zu einem Dokument ohne
     * Vorschläge (Mahnung): Zähler und Zuständigkeit wie bei den Vorschlägen.
     *
     * @param array<string,mixed> $as Zuordnung
     * @return array<string,mixed> für Applier::documentReminder
     */
    public function documentReminderData(array $entry, array $as, string $label, string $due, array $params): array
    {
        $u = is_string($as['utility'] ?? null) && Utilities::exists($as['utility']) ? $as['utility'] : null;
        $c = ['entry' => $entry, 'u' => $u,
              'meter' => $u !== null && !empty($as['meter_id']) ? $this->meters->get($u, (string)$as['meter_id']) : null,
              'contract' => $u !== null && !empty($as['contract_id']) && Utilities::hasContracts($u) ? $this->contracts->get($u, (string)$as['contract_id']) : null];
        return ['label' => $label, 'next_due' => $due, 'params' => $params] + $this->reminderTarget($c, $label);
    }

    /**
     * v3.7.0 (Paket betrieb) — Wo ein Termin aus einem Dokument hängt und wer ihn
     * hat: am zugeordneten Zähler (dann erbt er dessen Zuständige, wie jeder
     * Termin am Zähler), mit der Person des Vertrags als eigener Zuständigkeit
     * (die Kette eigene → Vertrag → Zähler kennt am Termin nur den Zähler).
     * `assignee` ist nur die Anzeige, übernommen wird `assignee_id`.
     *
     * @return array{utility?:string, meter_id?:string, category?:string, assignee_id?:string, assignee?:string}
     */
    private function reminderTarget(array $c, string $label): array
    {
        $out = [];
        $m = $c['meter'];
        if ($m !== null && empty($m['is_group'])) $out += ['utility' => (string)$c['u'], 'meter_id' => (string)$m['id']];
        if ($label === 'reading') $out['category'] = ReminderService::CATEGORY_READING;
        if ($this->responsibility === null) return $out;
        if ($c['contract'] !== null) {
            $v = $this->responsibility->view($c['contract']);
            if ($v['assignee_id'] !== null && !$v['assignee_deleted']) return $out + ['assignee_id' => $v['assignee_id'], 'assignee' => (string)$v['assignee']];
        }
        $who = $this->responsibility->inherit(null, $m);
        if (is_string($who['assignee']) && !$who['assignee_deleted']) $out['assignee'] = $who['assignee'];
        return $out;
    }

    /** Marktlokation am Zähler, Vertrags-/Kundennummer am Vertrag, wenn dort leer. */
    private function identifiers(array $c, bool $withMeter = true): array
    {
        $e = $c['entry']; $out = [];
        $m = $c['meter'];
        $malo = preg_replace('/\D/', '', (string)(self::value($e, 'market_location') ?? '')) ?? '';
        if ($withMeter && $m !== null && $malo !== '' && MeterService::isValidMalo($malo)) {
            $cur = (string)($m['malo_id'] ?? '');
            if ($cur !== $malo) {
                $out[] = self::p('meter_ids', 'malo', ['malo' => $malo, 'meter' => (string)($m['name'] ?? '')],
                    ['meter_id' => (string)$m['id'], 'malo_id' => $malo], ['market_location'], $cur === '' ? 'new' : 'conflict',
                    ['detail' => $cur !== '' ? ['malo_id' => $cur] : null]);
            }
        }
        $ct = $c['contract'];
        if ($ct !== null) {
            $data = [];
            foreach (['contract_number', 'customer_number'] as $f) {
                $v = self::value($e, $f);
                if (is_string($v) && trim($v) !== '' && !self::sameId((string)($ct[$f] ?? ''), $v)) $data[$f] = trim($v);
            }
            if ($data !== []) {
                $conflict = array_filter(array_keys($data), fn($f) => (string)($ct[$f] ?? '') !== '') !== [];
                $out[] = self::p('contract_ids', 'contractIds', ['provider' => (string)($ct['provider'] ?? '')] + $data,
                    ['contract_id' => (string)$ct['id']] + $data, array_keys($data), $conflict ? 'conflict' : 'new');
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: ?array} */
    private function compareBill(string $u, string $meterId, string $from, string $to, array $inv): array
    {
        foreach ($this->bills->list($u, $meterId) as $b) {
            if (($b['period_from'] ?? '') === $from && ($b['period_to'] ?? '') === $to) {
                $same = true;
                foreach ($inv as $k => $v) if (isset($b['invoice'][$k]) && abs((float)$b['invoice'][$k] - (float)$v) > 0.005) $same = false;
                return [$same ? 'exists' : 'conflict', ['bill_id' => (string)$b['id'], 'special_payment_id' => $b['special_payment_id'] ?? null, 'invoice' => $b['invoice'] ?? []]];
            }
            if (($b['period_from'] ?? '') <= $to && $from <= ($b['period_to'] ?? '')) {
                return ['overlap', ['bill_id' => (string)$b['id'], 'period_from' => $b['period_from'], 'period_to' => $b['period_to']]];
            }
        }
        return ['new', null];
    }

    /** @return array{0: string, 1: ?array} */
    private function compareContract(string $u, string $meterId, string $start, string $provider): array
    {
        $over = null;
        foreach ($this->contractsOf($u, $meterId) as $c) {
            if (!empty($c['is_shadow'])) continue;
            if ((string)($c['start'] ?? '') === $start) {
                $same = $provider === '' || self::supplierMatch(self::providerOf($c), $provider);
                return [$same ? 'exists' : 'conflict', ['contract_id' => (string)$c['id'], 'provider' => (string)($c['provider'] ?? ''), 'start' => $c['start'], 'end' => $c['end'] ?? null]];
            }
            if ((string)$c['start'] <= $start && (empty($c['end']) || (string)$c['end'] >= $start)) {
                $over = ['contract_id' => (string)$c['id'], 'provider' => (string)($c['provider'] ?? ''), 'start' => $c['start'], 'end' => $c['end'] ?? null];
            }
        }
        return $over !== null ? ['overlap', $over] : ['new', null];
    }

    /** Ende der Mindestlaufzeit: Beginn + N Monate − 1 Tag (über den Monatsersten, Lektion 26). */
    public static function termEnd(string $start, int $months): string
    {
        $d = new \DateTimeImmutable($start);
        $target = $d->modify('first day of this month')->add(new \DateInterval("P{$months}M"));
        $day = min((int)$d->format('j'), (int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Kündigungsfrist aus dem Text: „1 Monat“, „3 Monate zum Laufzeitende“,
     * „6 Wochen“, „14 Tage“ — Wochen und Tage als Tage (wie das Vertragsformular).
     *
     * @return array{months: ?int, days: ?int}
     */
    public static function notice(string $text): array
    {
        $t = Evidence::normalizeText($text);
        $words = ['einen' => 1, 'einem' => 1, 'ein' => 1, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'sechs' => 6, 'zwölf' => 12];
        $t = preg_replace_callback('/\b(' . implode('|', array_keys($words)) . ')\b/u', fn($m) => (string)$words[$m[1]], $t) ?? $t;
        if (preg_match('/(\d+)\s*monat/u', $t, $m)) return ['months' => min(24, (int)$m[1]), 'days' => null];
        if (preg_match('/(\d+)\s*woche/u', $t, $m)) return ['months' => null, 'days' => min(730, 7 * (int)$m[1])];
        if (preg_match('/(\d+)\s*tag/u', $t, $m)) return ['months' => null, 'days' => min(730, (int)$m[1])];
        return ['months' => null, 'days' => null];
    }
}
