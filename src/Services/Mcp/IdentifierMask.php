<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (Skeptiker H1) — Kennnummern ohne `mcp_share_identifiers` in Fremdtext aus
 * Dokumenten. Auf Rechnungen steht der Zählerstand meist in einer Zeile mit der
 * Zählernummer, das Datum neben der Rechnungsnummer: Zurückhalten nur des Felds gleichen
 * Namens reichte nicht, die Nummer kam über die Zitate anderer Felder und den Dateinamen.
 *
 * Eine Stelle für alle Dokument-Werkzeuge (Tools\DocumentTools, Tools\WriteTools):
 *  - `known`: Werte der Kennnummern-Felder dieses Dokuments und die Nummern, die der
 *    Haushalt schon kennt (Geräte, Marktlokation, Vertrags- und Kundennummer), auch mit
 *    anderen Trennzeichen geschrieben. Sie werden durch MASK ersetzt.
 *  - Bleibt danach etwas, das wie eine Nummer aussieht (looksLikeIdentifier), fällt ein
 *    Zitat oder Textwert ganz weg (`quote()`); beim Dateinamen wird das Stück ersetzt
 *    (`fileName()`), denn die KI braucht ihn nur als Anhaltspunkt.
 *
 * Mit dem Schalter (`IdentifierMask::off()`) bleibt alles unverändert. Grenze: Was die KI
 * selbst aus einem Original liest (`mcp_share_documents`) oder die Person ihr gibt, kann
 * die App nicht zurückhalten; die Karte und die Anleitung sagen das.
 */
final class IdentifierMask
{
    public const MASK = '[…]';
    /** kürzere Werte taugen nicht zum Ersetzen (sie träfen Teile von Zahlen und Wörtern) */
    public const MIN_KNOWN = 4;
    /** so lange Ziffernfolgen sind nie Betrag oder Datum */
    public const LONG_DIGITS = 6;

    /** Bezeichnung einer Nummer: Was dahinter steht, ist die Nummer */
    // v3.12.0 (Nachprüfung A2) — auch mit Bindestrich oder Leerzeichen („Kunden-Nummer“, „Kunden Nr.“), Konto, Kassenzeichen, Geschäftspartner
    // v3.12.0 — öffentlich: dieselbe Liste nimmt die Belegprüfung (Inbox\Evidence), eine Nummer dahinter belegt keinen Wert
    public const LABEL_STRICT = '(?:(?:kunden|vertrags|rechnungs|zähler|zaehler|konto|beleg|geschäftspartner|geschaeftspartner)[\s\-]*(?:nummer|nr\b\.?)|kd\.?[\s\-]*nr\.?|vertragskonto|geschäftspartner|geschaeftspartner|kassenzeichen|marktlokation|messlokation|malo(?:-id)?|melo(?:-id)?|aktenzeichen|mandatsreferenz|customer\s+(?:no\.?|number|id)|contract\s+(?:no\.?|number)|invoice\s+(?:no\.?|number)|meter\s+(?:no\.?|number)|account\s+(?:no\.?|number)|market\s+location)';
    /** … nur, wenn dahinter Ziffern stehen („Rechnung vom 15.08.“ ist keine Nummer) */
    private const LABEL_DIGITS = '(?:rechnung|zähler|zaehler|invoice|meter|vertrag|contract)';
    /** Tag oder Betrag direkt hinter einer Bezeichnung */
    private const PLAIN_VALUE = '/^(?:\d{1,2}\.\d{1,2}\.\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,3}(?:[.,]\d{3})*(?:[.,]\d+)?|(?:19|20)\d{2})$/';
    /** v3.12.0 (Nachprüfung A2) — hinter einer strengen Bezeichnung harmlos: nur ein Tag oder ein Jahr; ein Betrag nur mit Währung (STRICT_MONEY) */
    private const STRICT_PLAIN = '/^(?:\d{1,2}\.\d{1,2}\.\d{2,4}|\d{4}-\d{2}-\d{2}|(?:19|20)\d{2})$/';
    private const STRICT_MONEY = '/^\d[\d.,]*\s*(?:€|eur|euro)/iu';
    /** Menge mit Einheit in einem Stück („100kWh“) */
    private const WITH_UNIT = '/^\d[\d.,]*(?:kwh|mwh|wh|m3|m³|eur|euro|ct|cent|l|kg|km|%|€)$/iu';

    /**
     * @param bool $active Kennnummern zurückhalten (ohne `mcp_share_identifiers`)
     * @param list<string> $patterns bekannte Werte, die immer ersetzt werden
     */
    private function __construct(private bool $active, private array $patterns) {}

    /**
     * Mit `mcp_share_identifiers`: keine Kennnummern ersetzen. v3.12.0 (m4b) — die Angaben für Briefe des Haushalts
     * (Name und Anschrift des Absenders, Lieferanschrift) bleiben trotzdem zurück: Sie sind keine Kennnummer, und
     * der Schalter gibt sie nicht frei.
     *
     * @param iterable<mixed> $personal Angaben für Briefe (personal())
     */
    public static function off(iterable $personal = []): self
    {
        return new self(false, self::patterns($personal));
    }

    /**
     * @param iterable<mixed> $known bekannte Nummern (Leeres und zu Kurzes fällt weg)
     * @param iterable<mixed> $personal v3.12.0 (m4b) — Angaben für Briefe, ebenso ersetzt
     */
    public static function of(iterable $known, iterable $personal = []): self
    {
        return new self(true, self::patterns([...$known, ...$personal]));
    }

    /**
     * v3.12.0 (m4b) — Angaben für Briefe als Werte für die Maske: jede Zeile ganz, dazu jedes Wort des Namens
     * ab MIN_KNOWN Zeichen („Mustermann, Erika“ auf der Rechnung) und die Straße ohne Hausnummer („Musterweg“ aus
     * „Musterweg 12“). Der Ort allein bleibt: Er steht auch im Namen von Stadtwerken. Grenze: Was anders
     * geschrieben ist (abgekürzt, „Str.“ statt „Straße“), erkennt die Maske nicht.
     *
     * @param list<string> $nameLines Zeilen, deren Wörter einzeln zählen (der Name)
     * @param list<string> $lines übrige Zeilen (Anschrift, Lieferanschrift)
     * @return list<string>
     */
    public static function personal(array $nameLines, array $lines): array
    {
        $out = [];
        foreach ($nameLines as $l) {
            $out[] = $l;
            foreach (preg_split('/[\s,]+/u', $l) ?: [] as $w) if (mb_strlen($w) >= self::MIN_KNOWN) $out[] = $w;
        }
        foreach ($lines as $l) {
            $out[] = $l;
            foreach (preg_split('/\s*,\s*/u', $l) ?: [] as $part) {
                if (preg_match('/^(\D*\p{L}\D*?)\s+\d+\s*[\p{L}]?$/u', trim($part), $m) && mb_strlen(trim($m[1])) >= self::MIN_KNOWN) $out[] = trim($m[1]);
            }
        }
        return $out;
    }

    /** @param iterable<mixed> $values @return list<string> */
    private static function patterns(iterable $values): array
    {
        $patterns = [];
        foreach ($values as $v) {
            if (!is_scalar($v)) continue;
            $chars = preg_split('//u', (string)preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$v), -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars) || count($chars) < self::MIN_KNOWN) continue;
            // auch mit Leerzeichen oder Trennzeichen dazwischen, Groß und klein egal, nie als Teil eines längeren Worts
            $patterns[mb_strtolower(implode('', $chars))] = '/(?<![\p{L}\p{N}])' . implode('[\s.\-_\/]*', array_map(fn(string $c) => preg_quote($c, '/'), $chars)) . '(?![\p{L}\p{N}])/iu';
        }
        // längere zuerst: „12345 Musterstadt“ vor einem Wort daraus
        uksort($patterns, fn($a, $b) => mb_strlen((string)$b) <=> mb_strlen((string)$a));
        return array_values($patterns);
    }

    /** Hält die App Kennnummern zurück (ohne den Schalter `mcp_share_identifiers`)? */
    public function active(): bool
    {
        return $this->active;
    }

    /** Bekannte Nummern und die Angaben für Briefe ersetzen. */
    public function known(string $text): string
    {
        foreach ($this->patterns as $p) $text = (string)preg_replace($p, self::MASK, $text);
        return $text;
    }

    /** Zitat oder Textwert: bekannte Werte ersetzt; trägt er ohne den Schalter sonst noch eine Nummer, null. */
    public function quote(?string $text): ?string
    {
        if ($text === null) return $text;
        $clean = $this->known($text);
        return $this->active && self::looksLikeIdentifier($clean) ? null : $clean;
    }

    /** Dateiname: bekannte Werte und (ohne den Schalter) was wie eine Nummer aussieht ersetzt, Endung bleibt. */
    public function fileName(?string $name): ?string
    {
        if ($name === null) return $name;
        if (!$this->active) return $this->known($name);
        $clean = $this->known($name);
        $parts = preg_split('/([\s_]+)/u', $clean, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$clean];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) continue;
            $ext = preg_match('/\.[A-Za-z0-9]{1,5}$/', $part, $m) ? $m[0] : '';
            $stem = $ext !== '' ? substr($part, 0, -strlen($ext)) : $part;
            if ($stem !== '' && !self::isDayDigits($stem) && (self::looksLikeIdentifier($stem) || self::hasNumberInName($stem))) $parts[$i] = self::MASK . $ext;
        }
        return implode('', $parts);
    }

    /**
     * v3.12.0 (Nachprüfung A2) — im Dateinamen jede Ziffernfolge ab fünf Stellen, die kein Tag ist
     * („Rechnung 12345.pdf“); dort steht keine Menge und kein Betrag, nach denen die KI fragt.
     */
    private static function hasNumberInName(string $stem): bool
    {
        if (!preg_match_all('/\d{5,}/', $stem, $m)) return false;
        foreach ($m[0] as $run) {
            if (!self::isDayDigits($run)) return true;
        }
        return false;
    }

    /** „20260115“: acht Ziffern, die ein Tag sind */
    private static function isDayDigits(string $s): bool
    {
        return (bool)preg_match('/^(?:19|20)\d{6}$/', $s) && checkdate((int)substr($s, 4, 2), (int)substr($s, 6, 2), (int)substr($s, 0, 4));
    }

    /**
     * Sieht ein Text nach einer Kennnummer aus? Lange Ziffernfolge (auch in Gruppen), ein Stück
     * aus Buchstaben und mindestens drei Ziffern, oder ein Wert hinter einer Bezeichnung
     * wie „Kundennummer“. Tage, Beträge und Mengen mit Einheit zählen nicht.
     */
    public static function looksLikeIdentifier(string $text): bool
    {
        // Ersetztes ist erledigt
        $text = str_replace(self::MASK, ' ', $text);
        if (preg_match('/\d{' . self::LONG_DIGITS . ',}/u', $text)) return true;
        // Ziffern in Gruppen („50 000 000 001“), mindestens neun, kein Betrag mit Komma am Ende
        if (preg_match_all('/(?<![\d.,])\d{2,5}(?:[ \x{00A0}]\d{2,5}){2,}(?![\d,.])/u', $text, $m)) {
            foreach ($m[0] as $g) if (strlen((string)preg_replace('/\D/', '', $g)) >= 9) return true;
        }
        // v3.12.0 (Nachprüfung A2) — Ziffern mit Bindestrich oder Schrägstrich („123-456-789“), ohne Tage und Zeiträume aus Jahren
        if (preg_match_all('/(?<![\d.,\-\/])\d{2,}(?:[\-\/]\d{2,})+(?![\d,.\-\/])/u', $text, $m)) {
            foreach ($m[0] as $g) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g) || preg_match('/^(?:19|20)\d{2}(?:[\-\/](?:19|20)\d{2})*$/', $g)) continue;
                if (strlen((string)preg_replace('/\D/', '', $g)) >= 7) return true;
            }
        }
        foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
            $t = trim($token, ".,;:()[]\"'„“”‚‘’«»");
            if ($t === '' || !preg_match('/\p{N}/u', $t) || !preg_match('/\p{L}/u', $t) || preg_match(self::WITH_UNIT, $t)) continue;
            foreach (preg_split('/[-_\/]/u', $t) ?: [] as $part) {
                if (preg_match('/^(?:19|20)\d{2}$/', $part)) continue;
                if (strlen((string)preg_replace('/\D/u', '', $part)) >= 3) return true;
            }
        }
        // v3.12.0 (Nachprüfung A2) — „Kundennummer 123.456.789“ galt als Betrag: Hinter einer strengen Bezeichnung ist nur ein Tag,
        // ein Jahr oder ein Betrag mit Währung harmlos, jede weitere Fundstelle zählt mit
        if (preg_match_all('/(?<![\p{L}\p{N}])' . self::LABEL_STRICT . '[\s:#.]*(\S+(?:\s*(?:€|eur\b|euro\b))?)/iu', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $v = trim($m[1], ".,;:()[]\"'„“”‚‘’«»");
                if ($v !== '' && !preg_match(self::STRICT_PLAIN, $v) && !preg_match(self::STRICT_MONEY, $v)) return true;
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])' . self::LABEL_DIGITS . '[\s:#]+([^\s,;]+)/iu', $text, $m)) {
            foreach ($m[1] as $raw) {
                $v = trim((string)$raw, ".,;:()[]\"'„“”‚‘’«»");
                if (preg_match('/\p{N}/u', $v) && !preg_match(self::PLAIN_VALUE, $v)) return true;
            }
        }
        return false;
    }
}
