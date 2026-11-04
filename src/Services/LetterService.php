<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Http\HttpStatusException;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1036, Paket briefe) — Briefe aus Befunden „Deine Rechte als Kunde“ (Konzept §4).
 *
 * Fünf feste Vorlagen (RightsService::LETTERS), Bausteine in src/Services/Letters/de.json,
 * immer Deutsch (Empfänger in Deutschland, Entscheidung 10a). Aus einem Befund
 * (RightsService::find) und den Eingaben des Dialogs entsteht EIN Modell; Text und PDF lesen
 * beide daraus. Nur auf Knopfdruck (Entscheidung 2a), nie aus einem Sprachmodell
 * (Grundsatz 4). Absender und Empfänger werden nicht gespeichert (Entscheidung 10a).
 *
 * Regeln für den Inhalt (Konzept §4 „Nicht im Brief“):
 *   - Normen nur aus Config\Rights (Prüfbericht §12), nie als Text in de.json; eine Regel
 *     mit Status U oder `cite: false` nennt kein Brief (Rights::citable);
 *   - fehlende Angaben stehen als sichtbare Lücke „[…]“ im Brief (`gaps` nennt sie), nie
 *     erfunden; ein optionaler Baustein ohne Angaben entfällt;
 *   - kein „Hinweis, keine Rechtsberatung“ im Brief (der steht im Dialog), keine Aussage,
 *     dass etwas unwirksam oder rechtswidrig ist, keine Drohung, keine angekündigte Kürzung.
 *
 * Angaben aus dem Befund (`facts`, die Prüfungen der Pakete rechnung und vertrag setzen sie;
 * Tage als JJJJ-MM-TT, Beträge in Euro, Zahlen als Zahl):
 *   alle                 issued_on, invoice_number, period_from, period_to
 *   special_termination  announced_on, valid_from, (supply_kind)
 *   advance_adjustment   period_from, period_to, quantity, unit, assessment_eur, next_due,
 *                        every_months, working_price_ct, base_price_month_eur
 *   estimate_query       issued_on, period_to, bill_reading, own_reading, own_reading_on,
 *                        reported_on, unit, photo (bool: Foto zur Ablesung vorhanden)
 *   complaint            bill_late: period_from, period_to, issued_on, received_on,
 *                        deadline_on, bill_kind · bonus_missing: bonus_eur, tariff, issued_on ·
 *                        metering_cap: annual_eur, cap_eur, cap_rule, device, issued_on
 *   objection            dated_on, file_number, levy (Rechnung `notice` als Rückfall)
 */
final class LetterService
{
    public const KINDS = RightsService::LETTERS;
    public const FORMATS = ['text', 'pdf'];

    /** Höchstens so viele Zeilen je Anschrift (DIN 5008: Anschriftzone mit sechs Zeilen). */
    public const ADDRESS_LINES = 6;
    public const LINE_MAX = 60;
    public const TEXT_MAX = 2000;

    /** Bezugszeichen (Reihenfolge im Brief) und die Vorlagen, die sie brauchen (sonst „[…]“). */
    public const REF_KEYS = ['customer_number', 'contract_number', 'meter_serial', 'malo_id', 'supply_address', 'invoice_number', 'file_number'];
    private const REF_REQUIRED = [
        'special_termination' => ['customer_number'],
        'advance_adjustment'  => ['customer_number'],
        'estimate_query'      => ['customer_number', 'meter_serial'],
        'complaint'           => ['customer_number'],
        'objection'           => ['file_number'],
    ];

    /** Eingaben je Vorlage (Dialog): Name → Typ. */
    public const EXTRA = [
        'special_termination' => ['reading_value' => 'number', 'reading_date' => 'date'],
        'advance_adjustment'  => ['amount_eur' => 'number', 'reasons' => 'text'],
        'estimate_query'      => ['attach_photo' => 'bool'],
        'complaint'           => ['note' => 'text'],
        'objection'           => ['confirm_objection' => 'bool', 'levy' => 'line', 'reason' => 'text', 'suspend' => 'bool'],
    ];

    /**
     * Normen im Brief (Schlüssel in Config\Rights; Rights::applicable wählt nach Verbrauchsart
     * und Grundversorgung/Sondervertrag, ohne Angabe beide). Konzept §4, Tabelle „Norm im Brief“.
     */
    private const NORMS = [
        'special_termination' => ['de.enwg.41.5.s4', 'de.stromgvv.5.3', 'de.gasgvv.5.3'],     // Prüfbericht 19, 25
        'advance_adjustment'  => ['de.enwg.41b.3', 'de.stromgvv.13', 'de.gasgvv.13',
                                  'de.avbfernwaermev.25', 'de.avbwasserv.25'],                  // 12 bis 15
        'estimate_query'      => ['de.enwg.40a.2.s3'],                                          // 9
        'bill_late'           => ['de.enwg.40c.2'],                                             // 1
        'answer'              => ['de.enwg.111a'],                                              // 54
    ];
    /**
     * v3.11.0 (Skeptiker N12) — Dateiname des PDF je Vorlage (der Brief ist immer Deutsch), statt des
     * internen Schlüssels („brief-special_termination-…“): `brief-<name>-<JJJJ-MM-TT>.pdf`.
     */
    public const FILE_NAMES = [
        'special_termination' => 'sonderkuendigung', 'advance_adjustment' => 'abschlagsanpassung',
        'estimate_query' => 'rueckfrage-schaetzung', 'complaint' => 'beanstandung', 'objection' => 'widerspruch',
    ];

    public static function fileName(string $kind, string $today): string
    {
        return 'brief-' . (self::FILE_NAMES[$kind] ?? 'brief') . '-' . $today . '.pdf';
    }

    /** A6: die Grenze nennt die Regel ihrer Fallgruppe (Config\Rights::METERING_CAPS). */
    private const CAP_RULES = ['de.msbg.30', 'de.msbg.30.1.4', 'de.msbg.32.1'];

    /** @var array<string,mixed>|null */
    private ?array $tpl = null;
    /** @var list<string> Lücken des Modells, das gerade entsteht */
    private array $gaps = [];

    public function __construct(
        private RightsService $rights,
        private ContractService $contracts,
        private MeterService $meters,
        private ?BillService $bills = null,
        private string $templates = __DIR__ . '/Letters/de.json',
    ) {}

    /**
     * POST /api/letters/render — Body prüfen, Befund lesen, Modell bauen.
     * Fehler: 400 (Vorlage, Format, Feld), 404 (Befund), 422 (Befund bietet die Vorlage
     * nicht an, Widerspruch ohne Bestätigung der Rechtsbehelfsbelehrung).
     *
     * @param array<string,mixed> $body
     * @return array{format:string, model:array<string,mixed>}
     */
    public function render(array $body, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $format = $body['format'] ?? 'text';
        if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
            throw new HttpStatusException(400, 'errors.letter.formatInvalid', ['value' => is_scalar($format) ? mb_substr((string)$format, 0, 20) : '']);
        }
        $id = $body['finding_id'] ?? null;
        $finding = is_string($id) && preg_match(RightsService::ID_PATTERN, $id) ? $this->rights->find($id, $today) : null;
        if ($finding === null) {
            throw new HttpStatusException(404, 'errors.letter.findingNotFound', ['id' => is_scalar($id) ? mb_substr((string)$id, 0, 60) : '']);
        }
        $kind = $body['kind'] ?? $finding['letter'];
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new HttpStatusException(400, 'errors.letter.kindInvalid', ['value' => is_scalar($kind) ? mb_substr((string)$kind, 0, 40) : '']);
        }
        // v3.11.0 (Skeptiker H2) — kein Widerspruch nach dem Ende der Frist (der Befund bietet ihn dann auch
        // nicht an; der Server sagt es eigens, statt einen Brief mit „Begründung reiche ich nach“ zu bauen)
        if ($kind === 'objection' && is_string($finding['deadline'] ?? null) && $finding['deadline'] < $today) {
            throw new HttpStatusException(422, 'errors.letter.deadlinePassed');
        }
        if ($finding['letter'] !== $kind) throw new HttpStatusException(422, 'errors.letter.notOffered', ['kind' => $kind]);
        $input = [
            'sender'    => $this->lines($body['sender'] ?? [], 'sender'),
            'recipient' => $this->lines($body['recipient'] ?? null, 'recipient'),
            'place'     => $this->line($body['place'] ?? '', 'place'),
            'refs'      => $this->refsInput($body['refs'] ?? []),
            'extra'     => $this->extraInput($kind, $body['extra'] ?? []),
        ];
        // Entscheidung 5b: Widerspruch nur, wenn die Rechtsbehelfsbelehrung „Widerspruch“ nennt
        // (Pflichtbestätigung im Dialog) — auch für Vorschau und Text, nicht erst für das PDF
        if ($kind === 'objection' && $input['extra']['confirm_objection'] !== true) {
            throw new HttpStatusException(422, 'errors.letter.confirmRequired');
        }
        return ['format' => $format, 'model' => $this->model($finding, $kind, $input, $today)];
    }

    /**
     * Das eine Modell (Text und PDF). `$input` wie aus render(); Felder ohne Eingabe nehmen
     * die Vorbelegung aus Befund, Vertrag, Zähler und Rechnung (`defaults`).
     *
     * @param array<string,mixed> $finding Befund (RightsService::finding)
     * @param array{sender:list<string>, recipient:?list<string>, place:string, refs:array<string,?string>, extra:array<string,mixed>} $input
     * @return array<string,mixed>
     */
    public function model(array $finding, string $kind, array $input, string $today): array
    {
        $this->gaps = [];
        $t = $this->templates();
        $u = (string)$finding['utility'];
        $facts = is_array($finding['facts'] ?? null) ? $finding['facts'] : [];
        $contract = $finding['contract_id'] !== null ? $this->contracts->get($u, (string)$finding['contract_id']) : null;
        $meter = $finding['meter_id'] !== null ? $this->meters->get($u, (string)$finding['meter_id']) : null;
        $bill = $this->bill($u, $finding['bill_id']);
        $supplyKind = $facts['supply_kind'] ?? $contract['supply_kind'] ?? null;
        $supplyKind = in_array($supplyKind, Rights::SUPPLY_KINDS, true) ? $supplyKind : null;
        $country = $this->rights->country();

        // Rückfall auf die Rechnung (Datum, Zeitraum, Bescheid)
        $notice = is_array($bill['notice'] ?? null) ? $bill['notice'] : [];
        $facts += array_filter([
            'issued_on' => $bill['issued_on'] ?? null, 'period_from' => $bill['period_from'] ?? null,
            'period_to' => $bill['period_to'] ?? null, 'dated_on' => $notice['dated_on'] ?? null,
            'file_number' => $notice['file_number'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        // ── Vorbelegung ──
        $device = $meter !== null ? $this->meters->deviceOnDate($meter, $today) : null;
        // v3.11.0 (Skeptiker N3) — der Brief zum Messentgelt geht an den Messstellenbetreiber: Kunden- und
        // Vertragsnummer des Lieferanten gehören nicht hinein, die Zählernummer schon (Pflicht)
        $toMsb = $finding['check'] === 'metering_cap';
        $required = $toMsb ? ['meter_serial'] : self::REF_REQUIRED[$kind];
        $defaults = [
            'recipient' => $this->defaultRecipient($kind, $finding, $contract),
            'refs' => array_filter([
                'customer_number' => $toMsb ? null : $this->str($contract['customer_number'] ?? null),
                'contract_number' => $toMsb ? null : $this->str($contract['contract_number'] ?? null),
                'meter_serial'    => $this->str($device['serial'] ?? null),
                'malo_id'         => Rights::isEnergy($country, $u) ? $this->str($meter['malo_id'] ?? null) : null,
                'invoice_number'  => $this->str($facts['invoice_number'] ?? null),
                'file_number'     => $kind === 'objection' ? $this->str($facts['file_number'] ?? null) : null,
            ], fn($v) => $v !== null),
            'extra' => array_filter([
                'amount_eur'   => $kind === 'advance_adjustment' && is_numeric($facts['assessment_eur'] ?? null) ? (float)ceil((float)$facts['assessment_eur']) : null,
                'attach_photo' => $kind === 'estimate_query' ? !empty($facts['photo']) : null,
                'levy'         => $kind === 'objection' ? $this->str($facts['levy'] ?? null) : null,
            ], fn($v) => $v !== null),
        ];
        $extra = array_replace($defaults['extra'], array_filter($input['extra'], fn($v) => $v !== null && $v !== ''));

        // ── Kopf ──
        $sender = $input['sender'];
        if ($sender === []) $this->gap('sender');
        $recipient = $input['recipient'] ?? $defaults['recipient'];
        if ($recipient === []) $this->gap('recipient');
        $refs = [];
        $refValues = array_replace($defaults['refs'], array_filter($input['refs'], fn($v) => $v !== null));
        foreach (self::REF_KEYS as $k) {
            $v = $refValues[$k] ?? null;
            if ($v === '' ) continue;   // im Dialog bewusst geleert
            if ($v === null && !in_array($k, $required, true)) continue;
            if ($v === null) $this->gap($k);
            $refs[] = ['key' => $k, 'label' => $t['common']['ref'][$k], 'value' => $v ?? $t['common']['gap']];
        }
        $date = self::date($today);
        $placeDate = $input['place'] !== '' ? $input['place'] . ', ' . $date : $date;

        // ── Inhalt ──
        $v = $this->values($facts, $u);
        $v['file_number'] = $this->str($refValues['file_number'] ?? null);   // wie im Bezugszeichen
        $norms = [];
        $paras = match ($kind) {
            'special_termination' => $this->specialTermination($t, $v, $extra, $u, $supplyKind, $country, $norms),
            'advance_adjustment'  => $this->advanceAdjustment($t, $v, $facts, $extra, $u, $supplyKind, $country, $norms),
            'estimate_query'      => $this->estimateQuery($t, $v, $u, $country, $norms),
            'complaint'           => $this->complaint($t, $v, $facts, $extra, (string)$finding['check'], $u, $country, $norms),
            'objection'           => $this->objection($t, $v, $extra),
        };
        $subjectTpl = match (true) {
            $kind === 'complaint' => $t['complaint'][$finding['check']]['subject'] ?? $t['complaint']['subject'],
            default => $t[$kind]['subject'],
        };
        $subject = $this->fill($subjectTpl, $v, true);

        $attachments = [];
        if ($kind === 'estimate_query' && !empty($extra['attach_photo'])) $attachments[] = $this->fill($t['estimate_query']['photo'], $v);

        $model = [
            'kind'        => $kind,
            'file_name'   => self::fileName($kind, $today),   // v3.11.0 (Skeptiker N12)
            'finding_id'  => (string)$finding['id'],
            'form'        => $kind === 'objection' ? 'written' : 'text',
            'sender'      => $sender,
            'recipient'   => $recipient,
            'return_line' => implode(', ', $sender),
            'place_date'  => $placeDate,
            'refs'        => $refs,
            'subject'     => $subject,
            'salutation'  => $t['common']['salutation'],
            'paragraphs'  => $paras,
            'closing'     => $t['common']['closing'],
            'signature'   => $sender[0] ?? $t['common']['gap'],
            'signature_line' => $kind === 'objection',
            'attachments' => $attachments,
            'norms'       => array_values(array_map(fn($k) => array_intersect_key(Rights::rule($country, $k), array_flip(['key', 'norm', 'url', 'checked_on', 'status'])), array_unique($norms))),
            'gaps'        => array_values(array_unique($this->gaps)),
            'defaults'    => $defaults,
            'extra'       => $extra,
            // für den Dialog: Hinweis und Fußzeile in der Sprache der Anfrage (nicht im Brief)
            'finding'     => array_intersect_key($finding, array_flip(['id', 'check', 'utility', 'date', 'deadline', 'todo',
                                                                         'title', 'text', 'footer', 'help', 'norms'])),
        ];
        $model['lines'] = $this->textLines($model, $t);
        $model['text'] = implode("\n", $model['lines']);
        return $model;
    }

    /** Das Modell als JSON-Antwort: Objekte bleiben Objekte, auch leer. */
    public function publicModel(array $m): array
    {
        $m['defaults']['refs'] = (object)$m['defaults']['refs'];
        $m['defaults']['extra'] = (object)$m['defaults']['extra'];
        $m['extra'] = (object)$m['extra'];
        return $m;
    }

    // ── Vorlagen ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private function specialTermination(array $t, array $v, array $extra, string $u, ?string $supplyKind, string $country, array &$norms): array
    {
        $tpl = $t['special_termination'];
        $keys = $this->cite(self::NORMS['special_termination'], $country, $u, $supplyKind);
        $norms = [...$norms, ...$keys];
        $v['norms'] = $this->normText($keys, $country, $t);
        $v['reading_value'] = isset($extra['reading_value']) ? self::number((float)$extra['reading_value']) : null;
        $v['reading_date'] = isset($extra['reading_date']) ? self::date((string)$extra['reading_date']) : null;
        $v['unit'] ??= $this->unit($u, 'unit');
        $body = $tpl['body'];
        if ($keys === []) $body[0] = $tpl['body_without_norm'];
        return $this->paragraphs($body, $v);
    }

    /** @return list<string> */
    private function advanceAdjustment(array $t, array $v, array $facts, array $extra, string $u, ?string $supplyKind, string $country, array &$norms): array
    {
        $tpl = $t['advance_adjustment'];
        $keys = $this->cite(self::NORMS['advance_adjustment'], $country, $u, $supplyKind);
        $norms = [...$norms, ...$keys];
        $v['norms'] = $keys === [] ? null : $this->normText($keys, $country, $t);
        $v['unit'] ??= $this->unit($u, 'consumption_unit');
        $wp = is_numeric($facts['working_price_ct'] ?? null)
            ? $this->fill($tpl['working_price'], ['value' => self::decimal((float)$facts['working_price_ct'], 2), 'unit' => $v['unit']]) : null;
        $bp = is_numeric($facts['base_price_month_eur'] ?? null)
            ? $this->fill($tpl['base_price'], ['value' => self::money((float)$facts['base_price_month_eur'])]) : null;
        $v['prices'] = $wp !== null && $bp !== null ? $this->fill($tpl['prices'], ['working_price' => $wp, 'base_price' => $bp])
            : ($wp ?? $bp ? ' (' . ($wp ?? $bp) . ')' : '');
        // v3.11.0 (Skeptiker M6) — die Preise des Fälligkeitstags: „ab dem …“, wenn sie sich bis dahin ändern
        $v['price_basis'] = !empty($facts['prices_change']) ? $this->fill($tpl['prices_from'], $v) : $tpl['prices_now'];
        $months = (int)($facts['every_months'] ?? 1);
        $v['rhythm'] = $t['common']['rhythm'][(string)$months] ?? $this->fill($t['common']['rhythm']['other'], ['n' => (string)$months]);
        $v['amount_eur'] = isset($extra['amount_eur']) ? self::money((float)$extra['amount_eur']) : null;
        $v['reasons'] = isset($extra['reasons']) ? (string)$extra['reasons'] : null;
        return $this->paragraphs($tpl['body'], $v);
    }

    /** @return list<string> */
    private function estimateQuery(array $t, array $v, string $u, string $country, array &$norms): array
    {
        $tpl = $t['estimate_query'];
        $keys = $this->cite(self::NORMS['estimate_query'], $country, $u);
        $norms = [...$norms, ...$keys];
        $v['norms'] = $this->normText($keys, $country, $t);
        $v['unit'] ??= $this->unit($u, 'unit');
        $v['reported'] = $v['reported_on'] !== null ? $this->fill($tpl['reported'], $v) : '';
        $body = $tpl['body'];
        if ($keys === []) $body[1] = $tpl['request_without_norm'];
        return $this->paragraphs($body, $v);
    }

    /** @return list<string> */
    private function complaint(array $t, array $v, array $facts, array $extra, string $check, string $u, string $country, array &$norms): array
    {
        $tpl = $t['complaint'];
        $part = $tpl[$check] ?? $tpl['other'];
        $keys = [];
        if ($check === 'bill_late') {
            $keys = $this->cite(self::NORMS['bill_late'], $country, $u);
            $v['received'] = $v['received_on'] !== null ? $this->fill($part['received'], $v) : '';
            $v['period_end'] = ($facts['bill_kind'] ?? null) === 'final' ? $part['period_end_final'] : $part['period_end'];
        } elseif ($check === 'bonus_missing') {
            $v['tariff'] = $this->str($facts['tariff'] ?? null) !== null ? $this->fill($part['tariff'], ['tariff' => (string)$facts['tariff']]) : '';
        } elseif ($check === 'metering_cap') {
            $rule = $facts['cap_rule'] ?? null;
            $keys = is_string($rule) && in_array($rule, self::CAP_RULES, true) ? $this->cite([$rule], $country, $u) : [];
            $v['device'] = $t['common']['device'][$facts['device'] ?? ''] ?? null;
        }
        $norms = [...$norms, ...$keys];
        $v['norms'] = $keys === [] ? null : $this->normText($keys, $country, $t);
        $body = $part['body'];
        if (isset($extra['note'])) array_splice($body, count($body) - 1, 0, [['opt' => $tpl['note']]]);
        $v['note'] = $extra['note'] ?? null;
        $out = $this->paragraphs($body, $v);
        // Verbraucherbeschwerde bei Strom und Gas: Antwort binnen vier Wochen (§ 111a EnWG, Prüfbericht 54)
        $answer = $this->cite(self::NORMS['answer'], $country, $u);
        if ($answer !== []) {
            $norms = [...$norms, ...$answer];
            $out[] = $this->fill($tpl['answer'], ['norms' => $this->normText($answer, $country, $t)]);
        }
        return $out;
    }

    /** @return list<string> */
    private function objection(array $t, array $v, array $extra): array
    {
        $tpl = $t['objection'];
        $v['levy'] = $extra['levy'] ?? null;
        $v['file'] = $v['file_number'] !== null ? $this->fill($tpl['file'], ['file_label' => $t['common']['ref']['file_number'], 'file_number' => $v['file_number']]) : '';
        $body = $tpl['body'];
        if (isset($extra['reason'])) $body[1] = $this->fill($tpl['reason'], ['reason' => (string)$extra['reason']]);
        if (!empty($extra['suspend'])) array_splice($body, 2, 0, [$tpl['suspend']]);
        return $this->paragraphs($body, $v);
    }

    // ── Text ─────────────────────────────────────────────────────────────

    /** @return list<string> Zeilen des Briefs als Text (E-Mail, Kundenportal) */
    private function textLines(array $m, array $t): array
    {
        $gap = $t['common']['gap'];
        $out = [...($m['sender'] ?: [$gap]), '', ...($m['recipient'] ?: [$gap]), ''];
        foreach ($m['refs'] as $r) $out[] = $r['label'] . ': ' . $r['value'];
        if ($m['refs'] !== []) $out[] = '';
        array_push($out, $m['place_date'], '', $m['subject'], '', $m['salutation'], '');
        foreach ($m['paragraphs'] as $p) array_push($out, $p, '');
        array_push($out, $m['closing'], '');
        if ($m['signature_line']) array_push($out, '', '______________________________');
        $out[] = $m['signature'];
        if ($m['attachments'] !== []) {
            array_push($out, '', (count($m['attachments']) === 1 ? $t['common']['attachment'] : $t['common']['attachments']) . ':');
            foreach ($m['attachments'] as $a) $out[] = '- ' . $a;
        }
        return $out;
    }

    // ── PDF (DIN 5008 Form B, Fensterumschlag DL/C6/5 mit Fenster links) ──

    /** Millimeter in Punkt. */
    public const MM = 72 / 25.4;
    /** Anschriftfeld (Form B): links 20 mm, oben 45 mm, 85 × 45 mm; Vermerkzone 17,7 mm, Anschriftzone 27,3 mm. */
    public const WINDOW = ['x' => 20.0, 'y' => 45.0, 'w' => 85.0, 'h' => 45.0, 'note_h' => 17.7];
    /** Ränder des Textes: links 25 mm, rechts 20 mm; Informationsblock ab 125 mm; Falzmarken 105 und 210 mm, Lochmarke 148,5 mm. */
    public const MARGIN_LEFT = 25.0;
    public const MARGIN_RIGHT = 20.0;
    public const INFO_X = 125.0;
    public const FOLDS = [105.0, 148.5, 210.0];
    public const BODY_SIZE = 11.0;

    public function pdf(array $m): string
    {
        return $this->pdfWriter($m)->output();
    }

    /** Der Brief auf dem Writer, vor output() — die Layout-Prüfung liest dessen Box-Protokoll. */
    public function pdfWriter(array $m): PdfWriter
    {
        $mm = self::MM;
        $pdf = new PdfWriter();
        $pdf->setLang('de');
        $pdf->setInfo(['Title' => $m['subject'], 'Creator' => 'Energietracker']);
        $pdf->addPage();
        $left = self::MARGIN_LEFT * $mm;
        $right = $pdf->pageWidth() - self::MARGIN_RIGHT * $mm;
        $width = $right - $left;
        $grey = [140, 140, 140];
        $marks = function () use ($pdf, $mm, $grey): void {
            foreach (self::FOLDS as $i => $y) $pdf->line(4 * $mm, $y * $mm, ($i === 1 ? 7 : 9) * $mm, $y * $mm, 0.4, $grey);
        };
        $marks();

        // Briefkopf rechts oben: Absender
        $infoX = self::INFO_X * $mm;
        $infoW = $right - $infoX;
        $y = 15 * $mm;
        foreach ($m['sender'] ?: ['[…]'] as $line) {
            $size = $this->fit($pdf, [$line], 9.0, $infoW);
            $pdf->text($infoX, $y, $this->clip($pdf, $line, $size, $infoW), $size);
            $y += 4 * $mm;
        }

        // Anschriftfeld: Rücksendeangabe in der Vermerkzone, Anschrift in der Anschriftzone
        $w = self::WINDOW;
        $innerX = self::MARGIN_LEFT * $mm;
        $innerW = ($w['x'] + $w['w']) * $mm - $innerX - 2 * $mm;
        if ($m['return_line'] !== '') {
            $size = $this->fit($pdf, [$m['return_line']], 7.0, $innerW, 6.0);
            $pdf->text($innerX, ($w['y'] + $w['note_h'] - 2.5) * $mm, $this->clip($pdf, $m['return_line'], $size, $innerW), $size, false, [90, 90, 90]);
        }
        $lines = $m['recipient'] ?: ['[…]'];
        $size = $this->fit($pdf, $lines, 10.0, $innerW, 7.0);
        $lead = min(4.4 * $mm, ($w['h'] - $w['note_h'] - 2.0) * $mm / max(1, count($lines)));
        $y = ($w['y'] + $w['note_h']) * $mm + 0.718 * $size + 0.8 * $mm;
        foreach ($lines as $line) {
            $pdf->text($innerX, $y, $this->clip($pdf, $line, $size, $innerW), $size);
            $y += $lead;
        }

        // Informationsblock: Bezugszeichen, Ort und Datum
        $y = 50 * $mm;
        foreach ($m['refs'] as $r) {
            foreach ($pdf->wrap($r['label'] . ': ' . $r['value'], 9, $infoW) as $line) { $pdf->text($infoX, $y, $line, 9); $y += 4 * $mm; }
        }
        if ($m['refs'] !== []) $y += 2 * $mm;
        foreach ($pdf->wrap($m['place_date'], 9, $infoW) as $line) { $pdf->text($infoX, $y, $line, 9); $y += 4 * $mm; }

        // Betreff unter dem Anschriftfeld (und unter dem Informationsblock)
        $y = max(($w['y'] + $w['h'] + 13.4) * $mm, $y + 8 * $mm);
        $bottom = $pdf->pageHeight() - 20 * $mm;
        $lead = round(self::BODY_SIZE * 1.35, 2);
        $block = function (string $text, bool $bold = false, float $after = 0.0) use ($pdf, $left, $width, $bottom, $lead, &$y): void {
            foreach ($pdf->wrap($text, self::BODY_SIZE, $width, $bold) as $line) {
                if ($y > $bottom) { $pdf->addPage(); $y = 25 * self::MM; }
                $pdf->text($left, $y, $line, self::BODY_SIZE, $bold);
                $y += $lead;
            }
            $y += $after;
        };
        $block($m['subject'], true, $lead);
        // DIN 5008: Absätze mit einer Leerzeile
        $block($m['salutation'], false, $lead);
        foreach ($m['paragraphs'] as $p) $block($p, false, $lead);
        $block($m['closing'], false, $lead * 2.5);
        if ($m['signature_line']) {
            if ($y > $bottom - $lead) { $pdf->addPage(); $y = 25 * $mm; }
            $pdf->line($left, $y - $lead * 0.6, $left + 70 * $mm, $y - $lead * 0.6, 0.5, [0, 0, 0]);
        }
        $block($m['signature']);
        if ($m['attachments'] !== []) {
            $y += $lead * 0.5;
            $block(count($m['attachments']) === 1 ? 'Anlage' : 'Anlagen', true);
            foreach ($m['attachments'] as $a) $block($a);
        }

        $pdf->setFooter(function (PdfWriter $p, int $page, int $pages): void {
            if ($pages < 2) return;
            $p->textRight($p->pageWidth() - self::MARGIN_RIGHT * self::MM, $p->pageHeight() - 10 * self::MM, "Seite $page von $pages", 8, false, [110, 110, 110]);
        });
        return $pdf;
    }

    /** Schriftgröße, bei der die breiteste Zeile passt (höchstens `$size`, mindestens `$min`). */
    private function fit(PdfWriter $pdf, array $lines, float $size, float $width, float $min = 7.0): float
    {
        while ($size > $min && max(array_map(fn($l) => $pdf->textWidth((string)$l, $size), $lines ?: [''])) > $width) $size -= 0.5;
        return $size;
    }

    /** Eine Zeile, die auch in der kleinsten Größe nicht passt, endet mit „…“. */
    private function clip(PdfWriter $pdf, string $line, float $size, float $width): string
    {
        if ($pdf->textWidth($line, $size) <= $width) return $line;
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($chars !== [] && $pdf->textWidth(implode('', $chars) . '…', $size) > $width) array_pop($chars);
        return implode('', $chars) . '…';
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function templates(): array
    {
        return $this->tpl ??= (array)json_decode((string)file_get_contents($this->templates), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Werte für die Platzhalter: Tage deutsch (TT.MM.JJJJ), `_eur` als Geld, Zahlen deutsch.
     *
     * @return array<string,?string>
     */
    private function values(array $facts, string $utility): array
    {
        $out = [];
        foreach ($facts as $k => $v) {
            if (!is_string($k) || !($v === null || is_scalar($v))) continue;
            $out[$k] = match (true) {
                $v === null || $v === '' || is_bool($v) => null,
                is_string($v) && Dates::isIsoDate($v) => self::date($v),
                (is_int($v) || is_float($v)) && str_ends_with($k, '_eur') => self::money((float)$v),
                is_int($v) || is_float($v) => self::number((float)$v),
                default => (string)$v,
            };
        }
        foreach (['issued_on', 'period_from', 'period_to', 'received_on', 'reported_on', 'invoice_number', 'file_number', 'unit'] as $k) $out[$k] ??= null;
        return $out;
    }

    /**
     * Absätze aus Bausteinen: eine Zeichenkette ist Pflicht (fehlende Angaben → „[…]“),
     * `{opt: …}` entfällt, wenn ihm eine Angabe fehlt.
     *
     * @param list<string|array{opt:string}> $body
     * @return list<string>
     */
    private function paragraphs(array $body, array $v): array
    {
        $out = [];
        foreach ($body as $b) {
            if (is_array($b)) {
                if ($this->missing($b['opt'], $v) !== []) continue;
                $b = $b['opt'];
            }
            $out[] = $this->fill($b, $v, true);
        }
        return $out;
    }

    /** Eingeschobene Satzteile: leer heißt „entfällt“, nicht „fehlt“. */
    private const FRAGMENTS = ['prices', 'reported', 'received', 'tariff', 'file'];

    /** @return list<string> Platzhalter ohne Wert */
    private function missing(string $s, array $v): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $s, $m);
        return array_values(array_filter($m[1], function (string $k) use ($v): bool {
            $val = $v[$k] ?? null;
            return $val === null || ($val === '' && !in_array($k, self::FRAGMENTS, true));
        }));
    }

    /** Platzhalter ersetzen; ohne Wert die Lücke „[…]“ (als Lücke gemeldet, wenn `$track`). */
    private function fill(string $s, array $v, bool $track = false): string
    {
        $gap = $this->templates()['common']['gap'];
        return (string)preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($v, $gap, $track) {
            $val = $v[$m[1]] ?? null;
            if ($val === null) { if ($track) $this->gap($m[1]); return $gap; }
            return (string)$val;
        }, $s);
    }

    private function gap(string $what): void
    {
        $this->gaps[] = $what;
    }

    /**
     * Die Regeln, die ein Brief nennen darf: einschlägig für Verbrauchsart und Versorgungsart
     * (ohne Angabe beide, Entscheidung 6a) und nennbar (nicht Status U, nicht `cite: false`).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function cite(array $keys, string $country, string $utility, ?string $supplyKind = null): array
    {
        return array_values(array_filter(Rights::applicable($country, $keys, $utility, $supplyKind), fn($k) => Rights::citable($country, $k)));
    }

    /** Normen als Text: „§ 41 Abs. 5 Satz 4 EnWG bzw. § 5 Abs. 3 StromGVV“. */
    private function normText(array $keys, string $country, array $t): ?string
    {
        $names = array_map(fn($k) => Rights::rule($country, $k)['norm'], $keys);
        return $names === [] ? null : implode($t['common']['norms_join'], $names);
    }

    /** Empfänger vorbelegen: der Anbieter des Vertrags; Messstellenbetreiber und Behörde kennt die App nicht. */
    private function defaultRecipient(string $kind, array $finding, ?array $contract): array
    {
        if ($kind === 'objection' || $finding['check'] === 'metering_cap') return [];
        $p = $this->str($contract['provider'] ?? null);
        return $p !== null ? self::wrap($p) : [];
    }

    /**
     * v3.11.0 (Skeptiker N2) — ein langer Name auf Zeilen zu höchstens LINE_MAX Zeichen, an Wortgrenzen
     * (ein Wort über LINE_MAX geteilt), höchstens drei Zeilen (Platz für Straße und Ort). Bis dahin wurde
     * er mitten im Wort auf 60 Zeichen gekürzt („… Energievertrieb Musterstad“).
     *
     * @return list<string>
     */
    public static function wrap(string $s, int $maxLines = 3): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            while (mb_strlen($w) > self::LINE_MAX) {
                if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                $lines[] = mb_substr($w, 0, self::LINE_MAX);
                $w = mb_substr($w, self::LINE_MAX);
            }
            if ($w === '') continue;
            if ($cur === '') $cur = $w;
            elseif (mb_strlen($cur) + 1 + mb_strlen($w) <= self::LINE_MAX) $cur .= ' ' . $w;
            else { $lines[] = $cur; $cur = $w; }
        }
        if ($cur !== '') $lines[] = $cur;
        return array_slice($lines, 0, $maxLines);
    }

    private function bill(string $utility, ?string $id): ?array
    {
        if ($id === null || $this->bills === null) return null;
        try { return $this->bills->get($utility, $id); } catch (\Throwable) { return null; }
    }

    private function unit(string $utility, string $field): ?string
    {
        return Utilities::exists($utility) ? (string)(Utilities::get($utility)[$field] ?? '') ?: null : null;
    }

    private function str(mixed $v): ?string
    {
        if (!is_scalar($v)) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    // ── Eingaben prüfen ─────────────────────────────────────────────────

    /** @return list<string>|null null = keine Angabe (Vorbelegung gilt) */
    private function lines(mixed $in, string $field): ?array
    {
        if ($in === null) return null;
        if (is_string($in)) $in = preg_split('/\R/u', $in) ?: [];
        if (!is_array($in) || !array_is_list($in)) $this->invalid($field);
        $out = [];
        foreach ($in as $l) {
            if (!is_scalar($l)) $this->invalid($field);
            $l = $this->clean((string)$l);
            if ($l === '') continue;
            if (mb_strlen($l) > self::LINE_MAX) $this->invalid($field);
            $out[] = $l;
        }
        if (count($out) > self::ADDRESS_LINES) $this->invalid($field);
        return $out;
    }

    private function line(mixed $in, string $field): string
    {
        if ($in === null) return '';
        if (!is_scalar($in)) $this->invalid($field);
        $s = $this->clean((string)$in);
        if (mb_strlen($s) > self::LINE_MAX || str_contains($s, "\n")) $this->invalid($field);
        return $s;
    }

    /** @return array<string,?string> Bezugszeichen: Wert, '' = bewusst leer, fehlt = Vorbelegung */
    private function refsInput(mixed $in): array
    {
        if ($in === null || $in === []) return [];
        if (!is_array($in) || array_is_list($in)) $this->invalid('refs');
        $out = [];
        foreach (self::REF_KEYS as $k) {
            if (!array_key_exists($k, $in)) continue;
            $out[$k] = $in[$k] === null ? null : $this->line($in[$k], "refs.$k");
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function extraInput(string $kind, mixed $in): array
    {
        if ($in === null || $in === []) $in = [];
        if (!is_array($in) || ($in !== [] && array_is_list($in))) $this->invalid('extra');
        $out = [];
        foreach (self::EXTRA[$kind] as $k => $type) {
            $v = $in[$k] ?? null;
            if ($v === null || $v === '') { $out[$k] = null; continue; }
            $out[$k] = match ($type) {
                'bool'   => is_bool($v) ? $v : $this->invalid("extra.$k"),
                'number' => is_numeric($v) && (float)$v >= 0 && (float)$v < 1e9 ? (float)$v : $this->invalid("extra.$k"),
                'date'   => is_string($v) && Dates::isIsoDate($v) ? $v : $this->invalid("extra.$k"),
                'line'   => $this->line($v, "extra.$k") ?: null,
                'text'   => is_scalar($v) && mb_strlen($t = trim($this->clean((string)$v, true))) <= self::TEXT_MAX ? ($t !== '' ? $t : null) : $this->invalid("extra.$k"),
            };
        }
        return $out;
    }

    /** Steuerzeichen raus; Zeilenumbrüche nur in Freitext (dort zu Leerzeichen, ein Absatz). */
    private function clean(string $s, bool $text = false): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        if ($text) $s = preg_replace('/\s*\R\s*/u', ' ', $s) ?? $s;
        return trim($s);
    }

    private function invalid(string $field): never
    {
        throw new HttpStatusException(400, 'errors.letter.fieldInvalid', ['field' => $field]);
    }

    // ── Deutsche Schreibweise (der Brief ist immer Deutsch) ──

    public static function date(string $iso): string
    {
        return Dates::isIsoDate($iso) ? substr($iso, 8, 2) . '.' . substr($iso, 5, 2) . '.' . substr($iso, 0, 4) : $iso;
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', '.') . "\u{00A0}€";
    }

    public static function decimal(float $v, int $digits): string
    {
        return number_format($v, $digits, ',', '.');
    }

    /** Zahl mit bis zu drei Nachkommastellen, ohne Nullen am Ende. */
    public static function number(float $v): string
    {
        $s = number_format($v, 3, ',', '.');
        return rtrim(rtrim($s, '0'), ',');
    }
}
