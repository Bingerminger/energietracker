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
    /** v3.13.0 — Einheiten direkt an einer Zahl („100kWh“); dieselbe Liste nimmt die Belegprüfung (Inbox\Evidence) */
    public const UNITS = 'kwh|mwh|wh|m3|m³|eur|euro|ct|cent|l|kg|km';
    /** Menge mit Einheit in einem Stück („100kWh“) */
    private const WITH_UNIT = '/^\d[\d.,]*(?:' . self::UNITS . '|%|€)$/iu';

    /**
     * v3.13.0 — Trenner zwischen den Zeichen einer bekannten Nummer: kurze Werte (unter LONG_DIGITS Zeichen) wie
     * bisher nur Leerraum, Punkt, Bindestrich, Unterstrich und Schrägstrich, damit eine vierstellige Nummer keine
     * Uhrzeit („12:34“) trifft; längere auch Doppelpunkt, jeder Gedankenstrich, Minus, Mittelpunkt und Apostroph.
     * Nie das Komma: Es stünde sonst in jedem Betrag.
     */
    private const SEP_SHORT = '[\s\p{Z}.\-_\/]*';
    private const SEP_LONG = '[\s\p{Z}.\-_\/:\p{Pd}\x{2212}\x{00B7}\'\x{2019}]*';
    /** v3.13.0 — ab so vielen Zeichen darf eine Nummer mit einem Buchstaben am Rand auch an Buchstaben kleben („MeLoDE…“) */
    private const GLUED_LETTERS = 8;

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
        return new self(false, self::sorted(self::patterns($personal)));
    }

    /**
     * @param iterable<mixed> $known bekannte Nummern (Leeres und zu Kurzes fällt weg)
     * @param iterable<mixed> $personal v3.12.0 (m4b) — Angaben für Briefe, ebenso ersetzt
     */
    public static function of(iterable $known, iterable $personal = []): self
    {
        // v3.13.0 — Nummern nach eigener Regel (auch an Buchstaben, mehr Trenner), Angaben für Briefe wie bisher
        return new self(true, self::sorted(self::patterns($known, true) + self::patterns($personal)));
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

    /**
     * @param iterable<mixed> $values
     * @param bool $identifier v3.13.0 — Kennnummer (Gerät, Markt- und Messlokation, Vertrags- und Kundennummer):
     *        auch an Buchstaben (eine Bezeichnung oder ein Länderkürzel direkt davor) und mit weiteren Trennern; sonst (Angaben für Briefe) nie
     *        als Teil eines längeren Worts („Muster“ nicht in „Musterstadt“)
     * @return array<string,string> Wert in Kleinbuchstaben → Muster
     */
    private static function patterns(iterable $values, bool $identifier = false): array
    {
        $patterns = [];
        foreach ($values as $v) {
            if (!is_scalar($v)) continue;
            $chars = preg_split('//u', (string)preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$v), -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars) || count($chars) < self::MIN_KNOWN) continue;
            $quoted = array_map(fn(string $c) => preg_quote($c, '/'), $chars);
            if (!$identifier) {
                // auch mit Leerzeichen oder Trennzeichen dazwischen, Groß und klein egal, nie als Teil eines längeren Worts
                $patterns[mb_strtolower(implode('', $chars))] = '/(?<![\p{L}\p{N}])' . implode('[\s.\-_\/]*', $quoted) . '(?![\p{L}\p{N}])/iu';
                continue;
            }
            $long = count($chars) >= self::LONG_DIGITS;
            $glued = count($chars) >= self::GLUED_LETTERS;
            // am Rand eine Ziffer: keine Ziffer daneben (sonst eine andere Zahl), Buchstaben dürfen kleben;
            // ein Buchstabe am Rand: bei langen Nummern egal, bei kurzen keine Buchstaben und Ziffern daneben
            $edge = fn(string $c, bool $before): string => preg_match('/\p{N}/u', $c)
                ? ($before ? '(?<!\p{N})' : '(?!\p{N})')
                : ($glued ? '' : ($before ? '(?<![\p{L}\p{N}])' : '(?![\p{L}\p{N}])'));
            $key = mb_strtolower(implode('', $chars));
            $patterns[$key] = '/' . $edge($chars[0], true) . implode($long ? self::SEP_LONG : self::SEP_SHORT, $quoted)
                . $edge($chars[count($chars) - 1], false) . '/iu';
        }
        return $patterns;
    }

    /** @param array<string,string> $patterns @return list<string> längere zuerst: „12345 Musterstadt“ vor einem Wort daraus */
    private static function sorted(array $patterns): array
    {
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

    /**
     * v3.13.0 — Härtung: Name oder Titel (Zähler, Gruppe, Termin, Hinweis). Bekannte Werte ersetzt wie überall, dazu
     * ohne den Schalter derselbe zweite Schritt wie bei Zitaten und Notizen: Was danach noch wie eine Nummer
     * aussieht (looksLikeIdentifier), wird ersetzt. Anders als ein Zitat fällt ein Name nicht ganz weg, nur das
     * Stück: Die KI braucht ihn, um den Zähler oder Termin zu nennen. Bleibt trotzdem etwas, der ganze Text.
     */
    public function name(string $text): string
    {
        $clean = $this->known($text);
        if (!$this->active || !self::looksLikeIdentifier(self::settled($clean))) return $clean;
        $clean = self::maskPieces($clean);
        return self::looksLikeIdentifier(self::settled($clean)) ? self::MASK : $clean;
    }

    /**
     * Für die Prüfung eines Namens: Eine Bezeichnung, deren Wert schon ersetzt ist („Vertragskonto[…]“), ist erledigt. Sonst
     * hielte looksLikeIdentifier() das nächste Stück dahinter („/“, ein Wort) für ihren Wert.
     */
    private static function settled(string $text): string
    {
        $mask = preg_quote(self::MASK, '/');
        return (string)preg_replace('/(?<![\p{L}\p{N}])(?:' . self::LABEL_STRICT . '|' . self::LABEL_DIGITS . ')[\s:#.]*(?:\p{L}{0,3})' . $mask . '/iu', self::MASK, $text);
    }

    /**
     * Jedes Stück, das looksLikeIdentifier() als Nummer sähe, durch MASK ersetzt (dieselben Regeln, in derselben
     * Reihenfolge): Wert hinter einer Bezeichnung, lange Ziffernfolge, Ziffern in Gruppen, mit Bindestrich oder
     * Schrägstrich, ein Stück aus Buchstaben und mindestens drei Ziffern. Tage, Jahre, Beträge und Mengen mit
     * Einheit bleiben.
     */
    private static function maskPieces(string $text): string
    {
        $m = self::MASK;
        $punct = ".,;:()[]\"'„“”‚‘’«»";
        // Wert hinter einer strengen Bezeichnung (nur Tag, Jahr oder Betrag mit Währung sind harmlos)
        $text = (string)preg_replace_callback('/(?<![\p{L}\p{N}])(' . self::LABEL_STRICT . ')([\s:#.]*)(\S+)((?:\s*(?:€|eur\b|euro\b))?)/iu',
            function (array $x) use ($m, $punct): string {
                $v = trim($x[3] . $x[4], $punct);
                if ($v === '' || str_contains($x[3], $m) || preg_match(self::STRICT_PLAIN, $v) || preg_match(self::STRICT_MONEY, $v)) return $x[0];
                return $x[1] . $x[2] . $m;
            }, $text);
        $text = (string)preg_replace_callback('/(?<![\p{L}\p{N}])(' . self::LABEL_DIGITS . ')([\s:#]+)([^\s,;]+)/iu',
            function (array $x) use ($m, $punct): string {
                $v = trim($x[3], $punct);
                return preg_match('/\p{N}/u', $v) && !preg_match(self::PLAIN_VALUE, $v) ? $x[1] . $x[2] . $m : $x[0];
            }, $text);
        $text = (string)preg_replace('/\d{' . self::LONG_DIGITS . ',}/u', $m, $text);
        $text = (string)preg_replace_callback('/(?<![\d.,])\d{2,5}(?:[ \x{00A0}]\d{2,5}){2,}(?![\d,.])/u',
            fn(array $x): string => strlen((string)preg_replace('/\D/', '', $x[0])) >= 9 ? $m : $x[0], $text);
        $text = (string)preg_replace_callback('/(?<![\d.,\-\/])\d{2,}(?:[\-\/]\d{2,})+(?![\d,.\-\/])/u', function (array $x) use ($m): string {
            $g = $x[0];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g) || preg_match('/^(?:19|20)\d{2}(?:[\-\/](?:19|20)\d{2})*$/', $g)) return $g;
            return strlen((string)preg_replace('/\D/', '', $g)) >= 7 ? $m : $g;
        }, $text);
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 1) continue;
            $t = trim($token, $punct);
            if ($t === '' || !preg_match('/\p{N}/u', $t) || !preg_match('/\p{L}/u', $t) || preg_match(self::WITH_UNIT, $t)) continue;
            foreach (preg_split('/[-_\/]/u', $t) ?: [] as $part) {
                if (preg_match('/^(?:19|20)\d{2}$/', $part)) continue;
                if (strlen((string)preg_replace('/\D/u', '', $part)) >= 3) { $tokens[$i] = str_replace($t, $m, $token); break; }
            }
        }
        return implode('', $tokens);
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
