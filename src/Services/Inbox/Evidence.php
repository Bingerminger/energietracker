<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

/**
 * v3.7.0 (Posteingang) — Belegprüfung: Jedes Feld kommt mit Wert, wörtlicher
 * Belegstelle und Seite. Ein Feld ist zu prüfen, wenn
 *   - es leer ist (nur Kernfelder, sonst wäre jedes Dokument voller Marken),
 *   - es keine Belegstelle hat oder das Zitat nach Normalisierung (Leerraum,
 *     Silbentrennung, Groß/klein) nicht im Text steht,
 *   - der Wert nicht in seinem eigenen Zitat steht.
 * Mit dieser Regel rutschte in der Machbarkeitsprobe kein falscher Kernwert
 * durch. Dazu kommen die Pflichtprüfungen (Zählerwechsel, Zählerstands-
 * anforderung, Abschlagsänderung, Preise aus Rechnungen), „voraussichtlich“ an
 * einem Datum und zwei Gegenrechnungen der Rechnung (Summe der Abschläge,
 * Ergebnis = Gesamtbetrag − Abschläge).
 *
 * Deutsche Zahlen: „12.345“ ist zwölftausenddreihundertfünfundvierzig, nicht
 * zwölf Komma drei. Das Modell hielt den Tausenderpunkt gern für ein Komma —
 * deshalb nimmt die Prüfung jede Lesart einer Zahl als Kandidaten und behält
 * die, die im Zitat steht (die deutsche zuerst).
 */
final class Evidence
{
    /** Kürzeste Belegstelle (normalisiert): ein Zitat „1“ stünde in jedem Text. */
    private const MIN_QUOTE = 4;

    private const MONTHS = ['januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mär' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4, 'mai' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'november' => 11, 'nov' => 11, 'dezember' => 12, 'dez' => 12];

    // ── Text ─────────────────────────────────────────────────────────────

    /**
     * v3.7.0 (treffsicher, Nachbesserung 2) — Umlaute als zwei Zeichen in der
     * Textebene: UTF-8, als Windows-1252 bzw. Latin-1 gelesen („Ã¤“ statt „ä“),
     * und zerlegte Umlaute (Buchstabe + Trema U+0308); Nachbesserung 3: auch als
     * Mac Roman gelesen („√§“).
     */
    private const MOJIBAKE = [
        'Ã¤' => 'ä', 'Ã¶' => 'ö', 'Ã¼' => 'ü', 'ÃŸ' => 'ß', 'Ã„' => 'Ä', 'Ã–' => 'Ö', 'Ãœ' => 'Ü', 'Ã©' => 'é',
        "Ã\u{0084}" => 'Ä', "Ã\u{0096}" => 'Ö', "Ã\u{009C}" => 'Ü', "Ã\u{009F}" => 'ß',
        'â‚¬' => '€', 'Â³' => '³', 'Â²' => '²', 'Â°' => '°', 'Â§' => '§', "Â\u{00A0}" => ' ',
        'â€ž' => '„', 'â€œ' => '“', 'â€' => '”', 'â€“' => '–', 'â€”' => '—', 'â€™' => '’',
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü', "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
        // Nachbesserung 3 — UTF-8, als Mac Roman gelesen („√§“ statt „ä“, „‚Ç¨“ statt „€“)
        '√§' => 'ä', '√∂' => 'ö', '√º' => 'ü', '√ü' => 'ß', '√Ñ' => 'Ä', '√ñ' => 'Ö', '√ú' => 'Ü', '√©' => 'é',
        '‚Ç¨' => '€', '¬≥' => '³', '¬≤' => '²', '¬∞' => '°', '¬ß' => '§', '¬†' => ' ',
        '‚Äû' => '„', '‚Äú' => '“', '‚Äù' => '”', '‚Äì' => '–', '‚Äî' => '—', '‚Äô' => '’',
        // Nachbesserung 5 — Gegenrichtung: Mac-Roman-Bytes, als Windows-1252 gelesen („Š“ statt „ä“).
        // Nur die drei eindeutigen; Ä, Ö, ß ergäben „€“, „…“, „§“ und blieben, wie sie sind.
        'Š' => 'ä', 'š' => 'ö', 'Ÿ' => 'ü',
    ];

    /** Leerraum, Silbentrennung am Zeilenende, Anführungszeichen, Striche, Groß/klein, Umlaute als zwei Zeichen. */
    public static function normalizeText(string $s): string
    {
        $s = strtr($s, self::MOJIBAKE);
        $s = str_replace("\u{00AD}", '', $s);                                   // weiches Trennzeichen
        $s = preg_replace('/(\p{L})-[ \t]*\R[ \t]*(\p{Ll})/u', '$1$2', $s) ?? $s; // Abrech-\nnung → Abrechnung
        $s = strtr($s, [
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{2007}" => ' ',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
            "\u{201E}" => '"', "\u{201C}" => '"', "\u{201D}" => '"', "\u{00BB}" => '"', "\u{00AB}" => '"',
            "\u{201A}" => "'", "\u{2018}" => "'", "\u{2019}" => "'",
        ]);
        $s = mb_strtolower($s, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private static function squeeze(string $normalized): string
    {
        return preg_replace('/\s+/u', '', $normalized) ?? $normalized;
    }

    /**
     * Seite (1-basiert), auf der das Zitat steht — die genannte zuerst, sonst
     * jede andere, zuletzt über Seitengrenzen hinweg (0). null: nicht gefunden.
     *
     * @param list<string> $pages
     */
    public static function locate(string $quote, array $pages, ?int $page = null): ?int
    {
        $q = self::normalizeText($quote);
        if (mb_strlen($q) < self::MIN_QUOTE) return null;
        $norm = array_map([self::class, 'normalizeText'], $pages);
        $order = self::pageOrder($norm, $page);
        foreach ($order as $i) {
            if (self::onPage($norm[$i], $q)) return $i + 1;
        }
        // v3.7.0 (treffsicher, Nachbesserung 2) — Zitat aus mehreren Stellen („Bezeichnung
        // … Wert“, Tabellen): gefunden, wenn jeder Teil auf derselben Seite steht. Dass der
        // Wert in einem Teil steht, prüft valueFor() an quoteForValue().
        // Nachbesserung 3 — und zwar in der Reihenfolge des Zitats, jeder Teil nah am vorigen
        // (sonst verband „Marktlokation … <Kennung>“ die Bezeichnung mit einer Kennung aus
        // einer ganz anderen Zeile); Nachbesserung 5: nah = höchstens SPLIT_LINES Textzeilen
        $parts = self::quoteParts($q);
        if ($parts !== null) {
            foreach ($order as $i) {
                if (self::chain(self::lined((string)$pages[$i]), $parts)
                    || self::chain(self::lined((string)$pages[$i], true), array_map([self::class, 'squeeze'], $parts))) return $i + 1;
            }
            return null;
        }
        $all = implode(' ', $norm);
        return self::onPage($all, $q) ? 0 : null;
    }

    /** Die genannte Seite zuerst, dann die übrigen (0-basiert). @param list<string> $norm @return list<int> */
    private static function pageOrder(array $norm, ?int $page): array
    {
        $order = array_keys($norm);
        if ($page !== null && isset($norm[$page - 1])) array_unshift($order, $page - 1);
        return array_values(array_unique($order));
    }

    /**
     * Textebene einer Seite zeilenweise (Nachbesserung 5): die normalisierten,
     * nicht leeren Zeilen, verbunden mit einem Leerzeichen (bzw. ganz ohne
     * Leerraum), und wo jede Zeile darin beginnt.
     *
     * @return array{text: string, starts: list<int>}
     */
    private static function lined(string $page, bool $squeeze = false): array
    {
        $text = ''; $starts = [];
        foreach (preg_split('/\R/u', $page) ?: [] as $raw) {
            $l = self::normalizeText($raw);
            if ($squeeze) $l = self::squeeze($l);
            if ($l === '') continue;
            if ($text !== '' && !$squeeze) $text .= ' ';
            $starts[] = mb_strlen($text);
            $text .= $l;
        }
        return ['text' => $text, 'starts' => $starts];
    }

    /** Zeile (0-basiert) einer Stelle im verbundenen Text. @param list<int> $starts */
    private static function lineAt(array $starts, int $pos): int
    {
        $lo = 0; $hi = count($starts) - 1; $line = 0;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($starts[$mid] <= $pos) { $line = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        return $line;
    }

    /**
     * Stehen die Teile in dieser Reihenfolge im Text, jeder höchstens SPLIT_LINES
     * Textzeilen unter dem Ende des vorigen? Jede Fundstelle wird probiert.
     *
     * @param array{text: string, starts: list<int>} $lined
     * @param list<string> $parts
     */
    private static function chain(array $lined, array $parts, ?int $from = null): bool
    {
        if ($parts === []) return true;
        $part = array_shift($parts);
        $hay = $lined['text'];
        if ($part === '') return false;
        for ($p = mb_strpos($hay, $part, $from ?? 0); $p !== false; $p = mb_strpos($hay, $part, $p + 1)) {
            if ($from !== null && self::lineAt($lined['starts'], $p) - self::lineAt($lined['starts'], max(0, $from - 1)) > self::SPLIT_LINES) return false;
            if (self::chain($lined, $parts, $p + mb_strlen($part))) return true;
        }
        return false;
    }

    /** Steht der (normalisierte) Text auf der (normalisierten) Seite, auch ohne Leerraum? */
    private static function onPage(string $normPage, string $normText): bool
    {
        return str_contains($normPage, $normText) || str_contains(self::squeeze($normPage), self::squeeze($normText));
    }

    /** Auslassungszeichen zwischen den Teilen eines Zitats: „…“ oder drei Punkte. */
    private const ELLIPSIS = '/\s*(?:…|\.{3,})\s*/u';

    /**
     * Teile eines Zitats mit „…“ (normalisiert), jeder mindestens zwei Zeichen;
     * null ohne Auslassungszeichen.
     *
     * @return list<string>|null
     */
    private static function quoteParts(string $normQuote): ?array
    {
        if (!preg_match(self::ELLIPSIS, $normQuote)) return null;
        $parts = array_values(array_filter(array_map('trim', preg_split(self::ELLIPSIS, $normQuote) ?: []), fn(string $p) => $p !== ''));
        if ($parts === [] || array_filter($parts, fn(string $p) => mb_strlen($p) < 2) !== []) return [$normQuote];
        return $parts;
    }

    /**
     * Zitat, an dem der Wert geprüft wird: Die Teile eines Zitats mit „…“ werden
     * getrennt („ | “), damit ein Wert nicht über die Lücke hinweg zusammengesetzt
     * wird — er muss in einem Teil stehen.
     */
    public static function quoteForValue(string $quote): string
    {
        return preg_match(self::ELLIPSIS, $quote) ? (string)preg_replace(self::ELLIPSIS, ' | ', $quote) : $quote;
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 2) — Zitat ohne „…“ aus zwei Stellen
     * (Spaltenkopf und Zeile): Es darf an einer Wortgrenze in zwei Teile zerfallen,
     * wenn beide auf derselben Seite stehen, der erste vor dem zweiten und
     * höchstens SPLIT_LINES Textzeilen darüber, jeder mindestens MIN_QUOTE Zeichen
     * lang, und der Wert im zweiten steht, nicht im ersten. Streng gehalten, damit
     * kein falscher Wert unmarkiert durchrutscht. Ergebnis: Seite (1-basiert) und
     * zweiter Teil, sonst null.
     *
     * @param list<string> $pages
     * @param callable(string): bool $hasValue steht ein Wert in diesem Teil? (der erste: nein)
     * @param (callable(string): bool)|null $hasAll stehen alle Werte darin? (der zweite: ja; sonst $hasValue)
     * @return array{0: int, 1: string}|null
     */
    public static function locateSplit(string $quote, array $pages, ?int $page, callable $hasValue, ?callable $hasAll = null): ?array
    {
        $hasAll ??= $hasValue;
        $q = self::normalizeText($quote);
        if (preg_match(self::ELLIPSIS, $q)) return null;
        $words = explode(' ', $q);
        if (count($words) < 2) return null;
        $lined = array_map(fn($p) => self::lined((string)$p), $pages);
        for ($k = 1; $k < count($words); $k++) {
            $a = implode(' ', array_slice($words, 0, $k));
            $b = implode(' ', array_slice($words, $k));
            if (mb_strlen($a) < self::MIN_QUOTE || mb_strlen($b) < self::MIN_QUOTE) continue;
            if ($hasValue($a) || !$hasAll($b)) continue;
            foreach (self::pageOrder($lined, $page) as $i) {
                // jede Stelle des ersten Teils; der zweite folgt ihr nah (Spaltenkopf → Zeile)
                if (self::chain($lined[$i], [$a, $b])) return [$i + 1, $b];
            }
        }
        return null;
    }

    /**
     * Größter Abstand zweier Teile eines Zitats — mit „…“ wie ohne — in
     * Textzeilen der Textebene: Der nächste Teil beginnt höchstens so viele Zeilen
     * unter der Zeile, in der der vorige endet (0 = dieselbe Zeile). In Tabellen
     * stehen Bezeichnung und Wert in derselben Zeile oder darunter (Spaltenkopf →
     * Wertzeile, dazwischen höchstens ein zweizeiliger Kopf). In derselben Zeile
     * zählt der Abstand nicht: Eine breite Preiszeile darf lang sein.
     * v3.7.0 (treffsicher): Nachbesserung 2 mit 300 Zeichen, Nachbesserung 3 mit
     * 160 Zeichen (auch für „…“), Nachbesserung 5 nach Zeilen — 7 Preiszitate mit
     * „…“ lagen in einer breiten Tabellenzeile über 160 Zeichen auseinander.
     */
    private const SPLIT_LINES = 2;

    // ── Zahlen ───────────────────────────────────────────────────────────

    /**
     * Lesarten eines Zahlworts, deutsche zuerst: „12.345“ → [12345, 12.345],
     * „1.234,5“ → [1234.5], „12,5“ → [12.5], „0,2345“ → [0.2345].
     *
     * @return list<float>
     */
    public static function readings(string $token): array
    {
        $neg = (bool)preg_match('/^\s*[-\x{2212}]/u', $token);
        $s = preg_replace("/[\\s'\u{00A0}\u{202F}\u{2009}\u{2212}+-]/u", '', $token) ?? '';
        if (!preg_match('/^\d[\d.,]*$/', $s) || preg_match('/[.,]$/', $s)) return [];
        $dots = substr_count($s, '.'); $commas = substr_count($s, ',');
        $out = [];
        if ($dots && $commas) {
            // das hintere Zeichen trennt die Dezimalstellen, das andere gruppiert
            $dec = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $out[] = (float)str_replace([$dec === '.' ? ',' : '.', $dec], ['', '.'], $s);
        } elseif ($dots + $commas > 1) {
            $sep = $dots ? '.' : ',';
            $groups = explode($sep, $s);
            $tail = array_slice($groups, 1);
            if (array_filter($tail, fn($g) => strlen($g) !== 3) === []) $out[] = (float)str_replace($sep, '', $s);   // 1.234.567
        } elseif ($dots === 1 || $commas === 1) {
            $sep = $dots ? '.' : ',';
            [$int, $frac] = explode($sep, $s);
            $decimal = (float)($int . '.' . $frac);
            $grouped = strlen($frac) === 3 && $int !== '0' && strlen($int) <= 3 ? (float)($int . $frac) : null;
            // deutsch: Punkt + drei Stellen gruppiert, Komma trennt Dezimalstellen
            if ($sep === '.') { if ($grouped !== null) $out[] = $grouped; $out[] = $decimal; }
            else { $out[] = $decimal; if ($grouped !== null) $out[] = $grouped; }
        } else {
            $out[] = (float)$s;
        }
        return array_values(array_unique(array_map(fn(float $v) => $neg ? -$v : $v, $out), SORT_REGULAR));
    }

    /**
     * Zahlwörter im Zitat, je Wort seine Lesarten (deutsche zuerst, Beträge
     * ohne Vorzeichen).
     *
     * @return list<list<float>>
     */
    public static function numberTokens(string $quote): array
    {
        preg_match_all("/\\d[\\d.,'\u{00A0}\u{202F} ]*\\d|\\d/u", $quote, $m);
        $out = [];
        foreach ($m[0] as $tok) {
            // „1.234,56 € 12,00“ — Leerzeichen trennen auch zwei Zahlen: beide Lesarten
            foreach (array_unique([$tok, ...preg_split('/\s+/u', trim($tok)) ?: []]) as $part) {
                $r = array_map('abs', self::readings($part));
                if ($r !== []) $out[] = $r;
            }
        }
        return $out;
    }

    /** @return list<float> alle Lesarten aller Zahlen im Zitat */
    public static function numbersIn(string $quote): array
    {
        return array_values(array_unique(array_merge(...[[], ...self::numberTokens($quote)]), SORT_REGULAR));
    }

    /**
     * Lesarten eines Werts aus der Antwort des Modells: Text wie ein Zahlwort;
     * eine JSON-Zahl mit genau drei Nachkommastellen auch als Tausendergruppe
     * (12.345 → 12345 — das Modell las den Tausenderpunkt als Dezimalpunkt).
     *
     * @return list<float>
     */
    public static function valueReadings(mixed $v): array
    {
        if (is_int($v)) return [(float)$v];
        if (is_float($v)) {
            if (!is_finite($v)) return [];
            $out = [$v];
            $s = rtrim(sprintf('%.6F', abs($v)), '0');
            if (preg_match('/^[1-9]\d{0,2}\.\d{3}$/', $s)) $out[] = round($v * 1000, 6);
            return $out;
        }
        if (!is_string($v)) return [];
        $s = trim(preg_replace('/(€|eur|ct|kwh|m³|m3|cent|kw)/iu', '', $v) ?? $v);
        $r = self::readings($s);
        if ($r !== []) return $r;
        // „ca. 1.200“, „1.200 (geschätzt)“: das erste Zahlwort
        return preg_match("/[-\x{2212}]?\\d[\\d.,'\u{00A0}\u{202F}]*\\d|\\d/u", $s, $m) ? self::readings($m[0]) : [];
    }

    private static function sameNumber(float $a, float $b): bool
    {
        return abs(abs($a) - abs($b)) <= 1e-6 * max(1.0, abs($a));
    }

    // ── Daten ────────────────────────────────────────────────────────────

    /** @return list<string> ISO-Daten im Text (TT.MM.JJJJ, TT.MM.JJ, JJJJ-MM-TT, „1. März 2026“) */
    public static function datesIn(string $text): array
    {
        $out = [];
        $t = self::normalizeText($text);
        if (preg_match_all('/(?<!\d)(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4}|\d{2})(?!\d)/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out[] = self::iso((int)$x[3], (int)$x[2], (int)$x[1]);
        }
        if (preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $out[] = self::iso((int)$x[1], (int)$x[2], (int)$x[3]);
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})\.?\s+([a-zäöü]+)\.?\s+(\d{4})(?!\d)/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) if (isset(self::MONTHS[$x[2]])) $out[] = self::iso((int)$x[3], self::MONTHS[$x[2]], (int)$x[1]);
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** Ein Datum aus der Antwort (ISO oder deutsch) als ISO, sonst null. */
    public static function dateValue(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') return null;
        $d = self::datesIn($v);
        return $d[0] ?? null;
    }

    private static function iso(int $y, int $m, int $d): ?string
    {
        if ($y < 100) $y += $y < 70 ? 2000 : 1900;
        return checkdate($m, $d, $y) && $y >= 1990 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    // ── Netto und Steuersatz (v3.7.0, treffsicher, Nachbesserung 2) ──────

    /** Wörter, mit denen ein Dokument einen Preis ohne Umsatzsteuer ausweist (normalisiert). */
    private const NET_WORDS = ['netto', 'zzgl', 'zuzügl', 'exkl', 'ohne ust', 'ohne mwst', 'ohne umsatzsteuer', 'ohne mehrwertsteuer'];

    /** … und mit Umsatzsteuer. */
    private const GROSS_WORDS = ['brutto', 'inkl', 'einschl', 'enthält', 'enthalten'];

    /**
     * Steuersätze im Zitat, nur mit Prozentzeichen („19 %“, „19,00 %“, „7%“),
     * 0 < p < 30.
     *
     * @return list<float>
     */
    public static function percentsIn(string $quote): array
    {
        preg_match_all('/(?<![\d.,])(\d{1,2}(?:[.,]\d{1,2})?)\s*%/u', self::normalizeText($quote), $m);
        $out = [];
        foreach ($m[1] as $p) {
            $v = (float)str_replace(',', '.', $p);
            if ($v > 0 && $v < 30 && !in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    /**
     * Steuer eines Preises, wenn sie gelesen ist: Satz und ob der Preis netto
     * dasteht. Gelesen heißt: Der Satz steht im Zitat (nennt das Modell einen
     * Satz, genau dieser; sonst genau einer) — ein Satz wird nie vermutet, auch
     * nicht nach Verbrauchsart oder Datum. Netto oder brutto: vom Modell
     * (`basis`) oder im Zitat ausgewiesen (netto, zzgl. … bzw. brutto, inkl. …);
     * widersprechen sich die Angaben oder fehlt beides, null. Dann bleibt der
     * Preis, wie er gedruckt ist, und die Marke wie bisher.
     *
     * v3.7.0 (treffsicher, Nachbesserung 3) — `$read`: ein Satz, den die Prüfung
     * der Auslesung im Dokument gelesen hat (Steuerspalte oder die eine
     * Umsatzsteuerzeile, readRates()); er gilt nur, wenn im Zitat kein Satz steht.
     *
     * @return array{vat_pct: float, net: bool}|null
     */
    public static function taxOf(?string $quote, mixed $basis = null, mixed $vat = null, ?float $read = null): ?array
    {
        $q = self::normalizeText((string)$quote);
        if ($q === '') return null;
        $rates = self::percentsIn($q);
        if ($rates === [] && $read !== null) $rates = [$read];
        $rate = null;
        if (is_numeric($vat)) {
            foreach ($rates as $r) if (abs($r - (float)$vat) < 0.001) $rate = $r;
        } elseif (count($rates) === 1) {
            $rate = $rates[0];
        }
        if ($rate === null) return null;
        $has = fn(array $words): bool => array_filter($words, fn($w) => str_contains($q, $w)) !== [];
        $net = $has(self::NET_WORDS);
        $gross = $has(self::GROSS_WORDS);
        if ($basis === 'net') return $gross ? null : ['vat_pct' => $rate, 'net' => true];
        if ($basis === 'gross') return $net ? null : ['vat_pct' => $rate, 'net' => false];
        if ($net === $gross) return null;
        return ['vat_pct' => $rate, 'net' => $net];
    }

    /** Bruttopreis: netto mal (1 + Satz); ohne erkannte Steuer oder brutto der Preis, wie er dasteht. */
    public static function grossOf(float $value, ?array $tax, int $decimals = 4): float
    {
        return $tax !== null && $tax['net'] ? round($value * (1 + $tax['vat_pct'] / 100), $decimals) : $value;
    }

    /** Nettopreis zu einem Preis mit erkannter Steuer (netto: er selbst, brutto: herausgerechnet). */
    public static function netOf(float $value, array $tax): float
    {
        return $tax['net'] ? $value : $value / (1 + $tax['vat_pct'] / 100);
    }

    /**
     * Steuer eines Listeneintrags der Arbeitspreise (Zitat, `basis`, `vat_pct`).
     *
     * @param array<string,mixed> $item
     * @return array{vat_pct: float, net: bool}|null
     */
    public static function itemTax(array $item): ?array
    {
        $v = (array)($item['value'] ?? []);
        return self::taxOf(self::foundQuote($item), $v['basis'] ?? null, $v['vat_pct'] ?? null, self::readOf($item));
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 5) — das Zitat eines Felds bzw. Eintrags,
     * soweit es gefunden ist: ganz, wenn es im Dokument steht; sonst nur die Teile
     * eines Zitats mit „…“, die dastehen (`found_parts`); sonst null. Ein Satz wird
     * nur aus Gefundenem gelesen.
     *
     * @param array<string,mixed> $f
     */
    public static function foundQuote(array $f): ?string
    {
        $q = is_string($f['quote'] ?? null) ? $f['quote'] : null;
        $lost = array_intersect(['quote_not_found', 'no_quote'], (array)($f['reasons'] ?? [])) !== [] || ($f['quote_found'] ?? true) === false;
        if ($q === null || !$lost) return $q;
        return is_string($f['found_parts'] ?? null) ? $f['found_parts'] : null;
    }

    /**
     * Teile eines nicht gefundenen Zitats mit „…“, die auf einer Seite stehen
     * (normalisiert, mit „ | “ verbunden); null ohne Auslassungszeichen oder ohne Treffer.
     *
     * @param list<string> $pages
     */
    private static function foundParts(string $quote, array $pages): ?string
    {
        $parts = self::quoteParts(self::normalizeText($quote));
        if ($parts === null || count($parts) < 2) return null;
        $norm = array_map([self::class, 'normalizeText'], $pages);
        $hit = array_values(array_filter($parts, fn(string $p) => array_filter($norm, fn(string $page) => self::onPage($page, $p)) !== []));
        return $hit !== [] ? implode(' | ', $hit) : null;
    }

    /** Nachbesserung 3 — der beim Prüfen gelesene Satz eines Felds bzw. Eintrags (`vat_read`), sonst null. */
    private static function readOf(array $f): ?float
    {
        return is_numeric($f['vat_read']['vat_pct'] ?? null) ? (float)$f['vat_read']['vat_pct'] : null;
    }

    /**
     * Steuer eines einzelnen Preisfelds (Grundpreis): sein Zitat samt dem Zitat
     * des Steuersatzes, netto/brutto und Satz aus den Hilfsfeldern
     * `<feld>_basis` und `<feld>_vat_pct`.
     *
     * @param array<string,array<string,mixed>> $fields
     * @return array{vat_pct: float, net: bool}|null
     */
    public static function fieldTax(array $fields, string $name): ?array
    {
        // Nachbesserung 5 — nur gefundene Zitate (foundQuote)
        $quotes = array_filter([self::foundQuote((array)($fields[$name] ?? [])), self::foundQuote((array)($fields[$name . '_vat_pct'] ?? []))], 'is_string');
        return self::taxOf(implode(' | ', $quotes), $fields[$name . '_basis']['value'] ?? null, $fields[$name . '_vat_pct']['value'] ?? null,
            self::readOf((array)($fields[$name] ?? [])));
    }

    // ── Steuersatz ohne „%“ im Zitat (v3.7.0, treffsicher, Nachbesserung 3) ──

    /**
     * Übliche Umsatzsteuersätze — nur für eine Zahl ohne Prozentzeichen in einer
     * Steuerspalte: 19 % (§ 12 Abs. 1 UStG), 7 % (§ 12 Abs. 2; für Gas über das
     * Erdgasnetz vom 01.10.2022 bis 31.03.2024 nach § 28 Abs. 5), 16 % und 5 %
     * (01.07. bis 31.12.2020, § 28 Abs. 1 und 2), 0 % (§ 12 Abs. 3, u. a.
     * Photovoltaik). Welcher Satz gilt, liest die App; diese Liste schließt nur
     * Zahlen aus, die kein Satz sein können.
     */
    private const USUAL_RATES = [0.0, 5.0, 7.0, 16.0, 19.0];

    /** Wort für die Umsatzsteuer (normalisiert, als ganzes Wort: nicht in „August“, nicht „USt-IdNr.“). */
    private const TAX_WORD = '(?<!\p{L})(?:umsatzsteuer|mehrwertsteuer|ust|mwst)(?!\p{L})(?!\.?\s?-?\s?id)';

    /**
     * Kopf einer Steuerspalte: „%“ und das Steuerwort dicht beieinander, ohne
     * Zahl dazwischen und ohne Zahl vor dem „%“ („USt %“, „MwSt.-Satz in %“,
     * „% USt“ — nicht „19 % USt“, das ist ein Satz).
     */
    private static function taxColumnAt(string $normPage): ?int
    {
        $pct = '(?<!\d)(?<!\d )%';
        if (!preg_match('/' . self::TAX_WORD . '[^\d%]{0,20}' . $pct . '|' . $pct . '[^\d%]{0,20}' . self::TAX_WORD . '/u', $normPage, $m, PREG_OFFSET_CAPTURE)) return null;
        return mb_strlen(substr($normPage, 0, $m[0][1]));
    }

    /**
     * Satz aus der Steuerspalte: Das Zitat (ohne „%“) trägt genau eine Zahl, die
     * ein üblicher Satz ist („19,00“) — allein stehend, ohne Einheit dahinter,
     * nicht der Preis selbst —, und auf derselben Seite steht davor der Kopf einer
     * Steuerspalte. Sonst null.
     *
     * @param list<string> $norm normalisierte Seiten
     */
    private static function columnRate(string $quote, ?int $page, array $norm, float $price): ?float
    {
        if ($page === null || !isset($norm[$page - 1])) return null;
        $q = self::normalizeText($quote);
        $head = self::taxColumnAt($norm[$page - 1]);
        if ($head === null) return null;
        $first = (self::quoteParts($q) ?? [$q])[0];
        $at = mb_strpos($norm[$page - 1], $first);
        if ($at !== false && $at < $head) return null;   // der Kopf steht über der Zeile, nicht darunter
        preg_match_all('/(?<![\d.,\p{L}])(\d{1,2})(?:,(\d{1,2}))?(?![\d.,]|\s*[\p{L}%€³²°\/])/u', $q, $m, PREG_SET_ORDER);
        $rates = [];
        foreach ($m as $x) {
            $v = (float)($x[1] . '.' . ($x[2] ?? '0'));
            if (abs($v - $price) < 0.0001) continue;      // der Preis selbst ist kein Satz
            foreach (self::USUAL_RATES as $r) if (abs($v - $r) < 0.0001) $rates[(string)$r] = $r;
        }
        return count($rates) === 1 ? array_values($rates)[0] : null;
    }

    /**
     * Satz aus einer eigenen Umsatzsteuerzeile („Umsatzsteuer 19 % auf …“,
     * „zzgl. 19 % MwSt“): nur wenn das Dokument genau einen Satz ausweist. Stehen
     * zwei da (etwa 7 % und 19 %), wäre die Zuordnung Zeile zu Satz geraten — null.
     *
     * @param list<string> $norm normalisierte Seiten
     */
    private static function vatLineRate(array $norm): ?float
    {
        $num = '(\d{1,2}(?:,\d{1,2})?)';
        $re = '/' . self::TAX_WORD . '[^\d%]{0,25}(?<![\d.,])' . $num . '\s*%|(?<![\d.,])' . $num . '\s*%\s*' . self::TAX_WORD . '/u';
        $stated = [];
        foreach ($norm as $p) {
            preg_match_all($re, $p, $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $v = (float)str_replace(',', '.', ($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''));
                if ($v > 0 && $v < 30) $stated[(string)$v] = $v;
            }
        }
        return count($stated) === 1 ? array_values($stated)[0] : null;   // zwei Sätze: keiner
    }

    /**
     * Steuersatz der Preise einer Rechnung, deren Zitat keinen Satz trägt: aus der
     * Steuerspalte, sonst aus der einen Umsatzsteuerzeile. Steht am Eintrag bzw.
     * Feld als `vat_read: {vat_pct, from: column|line}` — gelesen beim Prüfen der
     * Auslesung (die Textebene wird nicht gespeichert), genutzt von taxOf().
     *
     * @param array<string,array<string,mixed>> $out
     * @param list<string> $pages
     */
    private static function readRates(array &$out, array $pages): void
    {
        $norm = array_map([self::class, 'normalizeText'], $pages);
        $line = self::vatLineRate($norm);
        $set = function (array &$f, mixed $price, string $quotes, mixed $basis, mixed $vat) use ($norm, $pages, $line): void {
            unset($f['vat_read']);
            $q = is_string($f['quote'] ?? null) ? $f['quote'] : '';
            if ($q === '' || !is_numeric($price) || self::percentsIn($quotes) !== []) return;
            if (self::foundQuote($f) !== $q) return;   // Nachbesserung 5 — nur zu einem gefundenen Zitat
            $page = is_numeric($f['page'] ?? null) ? (int)$f['page'] : null;
            $row = in_array($basis, ['net', 'gross'], true) && is_numeric($vat) ? self::rowRate($q, $page, $pages, (float)$vat) : null;
            $col = $row === null ? self::columnRate($q, $page, $norm, (float)$price) : null;
            if ($row !== null) $f['vat_read'] = ['vat_pct' => $row, 'from' => 'row'];
            elseif ($col !== null) $f['vat_read'] = ['vat_pct' => $col, 'from' => 'column'];
            elseif ($line !== null) $f['vat_read'] = ['vat_pct' => $line, 'from' => 'line'];
        };
        foreach (array_keys((array)($out['working_prices']['items'] ?? [])) as $i) {
            $it = &$out['working_prices']['items'][$i];
            $set($it, $it['value']['ct'] ?? null, (string)($it['quote'] ?? ''), $it['value']['basis'] ?? null, $it['value']['vat_pct'] ?? null);
            unset($it);
        }
        if (isset($out['base_price']) && ($out['base_price']['status'] ?? '') !== 'empty') {
            $set($out['base_price'], $out['base_price']['value'] ?? null,
                implode(' | ', array_filter([$out['base_price']['quote'] ?? null, $out['base_price_vat_pct']['quote'] ?? null], 'is_string')),
                $out['base_price_basis']['value'] ?? null, $out['base_price_vat_pct']['value'] ?? null);
        }
    }

    // ── Geschätzte Zählerstände (v3.7.0, treffsicher, Nachbesserung 4) ────

    /** Wörter, mit denen eine Rechnung einen Stand als geschätzt ausweist (normalisiert; Nachbesserung 5: „hochgerechnet“). */
    private const ESTIMATE_WORDS = '(?:geschätzt|schätzung|schätzwert|hochgerechnet|hochrechnung|rechnerisch(?: ermittelt)?)';

    /** Code oder Fußnotenzeichen einer Erklärung: Buchstaben, eine Zahl, „5)“, „*“. */
    private const ESTIMATE_CODE = '(?:[a-z]{1,2}|\d{1,3})';

    /** Kopf einer Spalte mit Ablesecodes (normalisiert). */
    private const READING_CODE_HEAD = '(?<!\p{L})(?:ableseart|ablesegrund|ablesecodes?|ableseschlüssel)(?!\p{L})';

    /** Nachbesserung 5 — so viele Zeichen hinter dem Zitat gehören noch zum Stand (bis zum nächsten Wert). */
    private const ESTIMATE_TAIL = 25;

    /**
     * Was das Dokument selbst als Schätzung erklärt — nur das zählt:
     *   letters  „S = geschätzt“, „R: rechnerisch ermittelt“, „geschätzt (S)“
     *   digits   „12 = geschätzt“ (Nachbesserung 5: Codes einer Codegruppe „10/12/26“
     *            oder einer Spalte „Ableseart“)
     *   marks    Fußnoten „5) hochgerechnet“, „* geschätzt“ (Nachbesserung 5)
     *   column   die Seite hat eine Spalte „Ableseart“/„Ablesegrund“ (darunter
     *            gilt auch eine Legende „S Schätzung“ ohne Zeichen dazwischen)
     *
     * @param list<string> $pages Textebene je Seite
     * @return array{letters: list<string>, digits: list<string>, marks: list<string>, column: bool}
     */
    private static function estimateCodes(array $pages): array
    {
        $c = ['letters' => [], 'digits' => [], 'marks' => [], 'column' => false];
        $w = self::ESTIMATE_WORDS; $code = self::ESTIMATE_CODE;
        $put = function (string $x) use (&$c): void { $c[ctype_digit($x) ? 'digits' : 'letters'][$x] = true; };
        foreach ($pages as $page) {
            $lines = array_values(array_filter(array_map([self::class, 'normalizeText'], preg_split('/\R/u', (string)$page) ?: []), fn($l) => $l !== ''));
            $p = implode(' ', $lines);
            if (preg_match_all('/(?<![\p{L}\d])(' . $code . ')\s*(?:=|:|-)\s*' . $w . '/u', $p, $m)) foreach ($m[1] as $x) $put((string)$x);
            if (preg_match_all('/' . $w . '\s*\((' . $code . ')\)/u', $p, $m)) foreach ($m[1] as $x) $put((string)$x);
            if (preg_match_all('/(?:^|\s)(\d{1,2}\)|\*{1,3})\s*(?:=|:|-)?\s*' . $w . '/u', $p, $m)) foreach ($m[1] as $x) $c['marks'][(string)$x] = true;
            foreach ($lines as $i => $l) {
                if (!preg_match('/' . self::READING_CODE_HEAD . '/u', $l)) continue;
                $c['column'] = true;
                foreach (array_slice($lines, $i + 1, 8) as $x) {
                    if (preg_match('/^(' . $code . ')\s+' . $w . '/u', $x, $m)) $put((string)$m[1]);
                }
            }
        }
        $keys = fn(array $a): array => array_map('strval', array_keys($a));
        return ['letters' => $keys($c['letters']), 'digits' => $keys($c['digits']), 'marks' => $keys($c['marks']), 'column' => $c['column']];
    }

    /**
     * Weist der Text den Stand als geschätzt aus? `word` (geschätzt, hochgerechnet,
     * rechnerisch ermittelt …), `code` (ein Ablesecode, den das Dokument als
     * Schätzung erklärt — allein, in einer Codegruppe „10/12/26“ oder als Zahl
     * unter einer Spalte „Ableseart“) oder `mark` (ein erklärtes Fußnotenzeichen
     * „5)“, „*“), sonst null. Die Erklärungen selbst werden vorher entfernt: Sie
     * belegen nicht, dass gerade dieser Stand geschätzt ist.
     *
     * @param array{letters: list<string>, digits: list<string>, marks: list<string>, column: bool} $codes
     */
    private static function estimatedIn(string $quote, array $codes): ?string
    {
        $q = self::normalizeText($quote);
        $q = (string)preg_replace(['/(?<![\p{L}\d])' . self::ESTIMATE_CODE . '\s*(?:=|:|-)\s*' . self::ESTIMATE_WORDS . '/u',
            '/' . self::ESTIMATE_WORDS . '\s*\(' . self::ESTIMATE_CODE . '\)/u', '/(?:^|\s)(?:\d{1,2}\)|\*{1,3})\s*(?:=|:|-)?\s*' . self::ESTIMATE_WORDS . '/u'], ' ', $q);
        if ($q === '' || preg_match('/nicht geschätzt|kein schätzwert/u', $q)) return null;
        if (preg_match('/' . self::ESTIMATE_WORDS . '/u', $q)) return 'word';
        foreach ($codes['letters'] as $c) if (preg_match('/(?<![\p{L}\d])' . preg_quote($c, '/') . '(?![\p{L}\d])/u', $q)) return 'code';
        foreach ($codes['marks'] as $c) {
            $re = str_starts_with($c, '*') ? '(?<!\*)' . preg_quote($c, '/') . '(?!\*)' : '(?<![\d)])' . preg_quote($c, '/');
            if (preg_match('/' . $re . '/u', $q)) return 'mark';
        }
        if (preg_match_all('/(?<![\d.,\/])\d{1,3}(?:\/\d{1,3})+(?![\d.,\/])/u', $q, $m)) {
            foreach ($m[0] as $group) if (array_intersect(explode('/', $group), $codes['digits']) !== []) return 'code';
        }
        if ($codes['column']) {
            foreach ($codes['digits'] as $d) if (preg_match('/(?<![\d.,\/\-\p{L}])' . preg_quote($d, '/') . '(?![\d.,\/\-\p{L}]|\s*(?:%|€|ct|kwh|m³|m3))/u', $q)) return 'code';
        }
        return null;
    }

    /**
     * Nachbesserung 5 — Zitat samt dem, was in seiner Zeile direkt dahinter steht
     * (höchstens ESTIMATE_TAIL Zeichen, nur bis zum nächsten Wert oder Datum):
     * Ein Code oder Fußnotenzeichen steht oft hinter dem Stand, außerhalb des Zitats.
     *
     * @param list<string> $pages
     */
    private static function estimateContext(string $quote, ?int $page, array $pages): string
    {
        if (trim($quote) === '' || $page === null || !isset($pages[$page - 1])) return $quote;
        $q = self::normalizeText($quote);
        $parts = self::quoteParts($q) ?? [$q];
        $last = (string)end($parts);
        foreach (preg_split('/\R/u', (string)$pages[$page - 1]) ?: [] as $raw) {
            $l = self::normalizeText($raw);
            $pos = $last !== '' ? mb_strpos($l, $last) : false;
            if ($pos === false) continue;
            $tail = mb_substr($l, $pos + mb_strlen($last), self::ESTIMATE_TAIL);
            $tail = (string)preg_split('/\d{4,}|\d[\d.]*[.,]\d|\d{1,2}\.\d{1,2}\./u', $tail)[0];
            return $quote . ' ' . $tail;
        }
        return $quote;
    }

    /**
     * Ablese-Art der Zählerstände einer Rechnung: `reading_new_estimated`,
     * `reading_old_estimated` und `estimated` je Eintrag von `meter_readings`.
     * Entschieden wird am Zitat des Stands (und dem des Kennzeichens), nicht an
     * der Antwort des Modells: geschätzt nur mit Wort oder erklärtem Code
     * (`estimated_by: word|code`), sonst nicht.
     *
     * @param array<string,array<string,mixed>> $out
     * @param list<string> $pages
     */
    private static function readEstimates(array &$out, array $pages): void
    {
        $codes = self::estimateCodes($pages);
        $page = fn(array $f): ?int => is_numeric($f['page'] ?? null) ? (int)$f['page'] : null;
        foreach (['reading_new', 'reading_old'] as $r) {
            $flag = $r . '_estimated';
            if (!isset($out[$flag]) || ($out[$r]['value'] ?? null) === null) continue;
            // Nachbesserung 5 — auch was in der Zeile direkt hinter dem Zitat des Stands steht
            $ctx = self::estimateContext((string)($out[$r]['quote'] ?? ''), $page($out[$r]), $pages);
            $by = self::estimatedIn(implode(' | ', array_filter([$ctx, $out[$flag]['quote'] ?? null], 'is_string')), $codes);
            unset($out[$flag]['estimated_by']);
            $out[$flag] = ['value' => $by !== null, 'status' => 'ok', 'reasons' => []] + ($by !== null ? ['estimated_by' => $by] : []) + $out[$flag];
        }
        foreach (array_keys((array)($out['meter_readings']['items'] ?? [])) as $i) {
            $it = &$out['meter_readings']['items'][$i];
            $by = self::estimatedIn(self::estimateContext((string)($it['quote'] ?? ''), $page($it), $pages), $codes);
            $it['value']['estimated'] = $by !== null;
            unset($it['estimated_by']);
            if ($by !== null) $it['estimated_by'] = $by;
            if (isset($out['meter_readings']['value'][$i]) && is_array($out['meter_readings']['value'][$i])) $out['meter_readings']['value'][$i]['estimated'] = $by !== null;
            unset($it);
        }
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 5) — Einträge von `meter_readings` mit
     * derselben Zählernummer (je Ablesezeitraum einer) zu einem: frühester
     * Anfangsstand, spätester Endstand; die Stände dazwischen stehen am Eintrag als
     * `between: [{date, counter, estimated}]` (die Form von `value` bleibt, weil
     * „erstbefuellung“ sie liest). Geschätzt, wenn ein Teil es ist; Marke und
     * Gründe aller Teile; `merged` = Zahl der Teile. Fallen die Stände mit dem Tag
     * nicht (`inconsistent`), ist das markiert. Zwei verschiedene Stände am selben
     * Tag sind kein Zeitraum nach dem anderen, sondern zwei Zählwerke
     * (Zweitarifzähler): Diese Einträge bleiben getrennt — und ohne Vorschlag wie
     * bisher (Proposals::meterReadings).
     *
     * @param array<string,array<string,mixed>> $out
     */
    private static function mergeReadings(array &$out): void
    {
        $items = array_values((array)($out['meter_readings']['items'] ?? []));
        if (count($items) < 2) return;
        $groups = [];
        // v3.9.0 (F1032) — nur Einträge desselben Zählwerks (HT und NT derselben Nummer bleiben getrennt)
        $reg = fn(array $it): ?string => \Energietracker\Services\MeterService::obisKey(is_string($it['value']['obis'] ?? null) ? $it['value']['obis'] : null)
            ?? \Energietracker\Services\MeterService::registerKey(is_string($it['value']['register'] ?? null) ? $it['value']['register'] : null);
        foreach ($items as $it) {
            $n = (string)($it['value']['meter_number'] ?? '');
            $k = null;
            foreach (array_keys($groups) as $g) if ($n !== '' && Proposals::sameId((string)$groups[$g][0]['value']['meter_number'], $n) && $reg($groups[$g][0]) === $reg($it)) $k = $g;
            if ($k === null) $groups[] = [$it]; else $groups[$k][] = $it;
        }
        if (count($groups) === count($items)) return;
        $merged = [];
        foreach ($groups as $parts) {
            if (count($parts) === 1) { $merged[] = $parts[0]; continue; }
            $stands = [];
            foreach ($parts as $p) {
                $v = (array)$p['value'];
                foreach ([['reading_old', 'reading_old_date'], ['reading_new', 'reading_new_date']] as [$vf, $df]) {
                    if (!is_numeric($v[$vf] ?? null) || !is_string($v[$df] ?? null)) continue;
                    $stands[] = ['date' => (string)$v[$df], 'counter' => (float)$v[$vf], 'estimated' => !empty($v['estimated'])];
                }
            }
            usort($stands, fn($a, $b) => [$a['date'], $a['counter']] <=> [$b['date'], $b['counter']]);
            $byDate = [];
            $clash = false;
            foreach ($stands as $s) {
                if (isset($byDate[$s['date']]) && abs($byDate[$s['date']]['counter'] - $s['counter']) > 0.0005) $clash = true;
                $byDate[$s['date']] ??= $s;
                $byDate[$s['date']]['estimated'] = $byDate[$s['date']]['estimated'] || $s['estimated'];
            }
            if ($clash) { array_push($merged, ...$parts); continue; }
            $list = array_values($byDate);
            $falls = false;
            for ($i = 1; $i < count($list); $i++) if ($list[$i]['counter'] < $list[$i - 1]['counter'] - 0.0005) $falls = true;
            $first = $list[0] ?? null; $last = count($list) > 1 ? $list[count($list) - 1] : null;
            $value = ['meter_number' => $parts[0]['value']['meter_number'],
                'reading_old' => $first['counter'] ?? null, 'reading_old_date' => $first['date'] ?? null,
                'reading_new' => $last['counter'] ?? null, 'reading_new_date' => $last['date'] ?? null,
                'estimated' => array_filter($parts, fn($p) => !empty($p['value']['estimated'])) !== [],
                'obis' => $parts[0]['value']['obis'] ?? null, 'register' => $parts[0]['value']['register'] ?? null];
            $one = ['value' => $value,
                'quote' => mb_substr(implode(' | ', array_filter(array_map(fn($p) => $p['quote'] ?? null, $parts), 'is_string')), 0, 800),
                'page' => $parts[0]['page'] ?? null,
                'status' => array_filter($parts, fn($p) => ($p['status'] ?? '') === 'check') !== [] || $falls ? 'check' : 'ok',
                'reasons' => array_values(array_unique(array_merge(...array_map(fn($p) => (array)($p['reasons'] ?? []), $parts)))),
                'between' => array_slice($list, 1, max(0, count($list) - 2)),
                'estimated_ends' => [(bool)($first['estimated'] ?? false), (bool)($last['estimated'] ?? false)],
                'merged' => count($parts)];
            if ($falls && !in_array('inconsistent', $one['reasons'], true)) $one['reasons'][] = 'inconsistent';
            if ($falls) self::flag($out['meter_readings'], 'inconsistent');
            foreach (['number_from', 'estimated_by'] as $k) foreach ($parts as $p) if (isset($p[$k])) { $one[$k] = $p[$k]; break; }
            $merged[] = $one;
        }
        $out['meter_readings']['items'] = $merged;
        $out['meter_readings']['value'] = array_map(fn($it) => $it['value'], $merged);
    }

    /**
     * v3.7.0 (treffsicher, Nachbesserung 4) — Satz in derselben Textzeile wie das
     * Zitat („… 10,00 ct/kWh“ endet direkt vor „19%“): Nennt das Modell netto
     * oder brutto **und** einen Satz, und steht genau dieser Satz mit „%“ in der
     * Zeile der Textebene, in der das ganze Zitat steht — und kein anderer —,
     * ist er gelesen. Eine Zeile tiefer nicht.
     *
     * @param list<string> $pages Textebene je Seite (mit Zeilenumbrüchen)
     */
    private static function rowRate(string $quote, ?int $page, array $pages, float $vat): ?float
    {
        if ($page === null || !isset($pages[$page - 1])) return null;
        $q = self::normalizeText($quote);
        $parts = self::quoteParts($q) ?? [$q];
        foreach (preg_split('/\R/u', $pages[$page - 1]) ?: [] as $raw) {
            $line = self::normalizeText($raw);
            if (!self::chain(self::lined($raw), $parts) && !self::chain(self::lined($raw, true), array_map([self::class, 'squeeze'], $parts))) continue;
            $rates = self::percentsIn($line);
            return count($rates) === 1 && abs($rates[0] - $vat) < 0.001 ? $rates[0] : null;
        }
        return null;
    }

    /**
     * Nachbesserung 5 — steht die Zählernummer in der Kopfzeile direkt über den
     * Ständen? Von der Zeile, in der das Zitat beginnt, aufwärts über die
     * Standzeilen (Zeilen mit Datum) bis zur ersten ohne Datum: Diese Kopfzeile
     * muss die Nummer tragen. Höchstens sechs Zeilen weit, nur auf derselben Seite.
     */
    private static function headerAbove(string $page, string $quote, string $number): bool
    {
        $lines = array_values(array_filter(array_map([self::class, 'normalizeText'], preg_split('/\R/u', $page) ?: []), fn($l) => $l !== ''));
        $first = (self::quoteParts(self::normalizeText($quote)) ?? [self::normalizeText($quote)])[0];
        $n = self::squeeze(self::normalizeText($number));
        if ($first === '' || $n === '') return false;
        foreach ($lines as $i => $line) {
            if (!str_contains($line, $first) && !str_contains(self::squeeze($line), self::squeeze($first))) continue;
            for ($k = $i - 1, $steps = 0; $k >= 0 && $steps < 6; $k--, $steps++) {
                if (self::datesIn($lines[$k]) !== []) continue;   // noch eine Standzeile
                return str_contains(self::squeeze($lines[$k]), $n);
            }
            return false;
        }
        return false;
    }

    /**
     * Form eines Werts nach `format` am Schemafeld:
     *   malo   Marktlokations-ID — 11 Ziffern, erste 1–9, Prüfziffer nach BDEW
     *          (Nachbesserung 3, MeterService::isValidMalo, Quelle dort)
     *   meter  Zählernummer — ohne Sternchen im Wert oder im Zitat (Nachbesserung 5:
     *          ein Sternchen-Kürzel vor der Nummer — „*EMH“ — ließ offen, was zur
     *          Nummer gehört; ein Wert daraus ging unmarkiert falsch durch)
     */
    private static function formatOk(array $def, mixed $value, string $quote = ''): bool
    {
        $v = (string)preg_replace('/\s+/u', '', (string)$value);
        return match ($def['format'] ?? null) {
            'malo' => \Energietracker\Services\MeterService::isValidMalo($v),
            'meter' => !str_contains($v, '*') && !str_contains($quote, '*'),
            default => true,
        };
    }

    // ── Prüfung ──────────────────────────────────────────────────────────

    /**
     * Rohfelder eines Anbieters gegen den Text prüfen und normalisieren.
     *
     * Ergebnis je Feld: {value, raw, quote, page, status: ok|check|empty,
     * reasons[]}; Listenfelder zusätzlich `items` (je Eintrag dieselbe Form,
     * `value` als Objekt der Unterfelder) und `value` = Liste der Werte.
     *
     * @param array<string,mixed> $raw   ['name' => ['value'=>…, 'quote'=>…, 'page'=>…]]
     * @param list<string>        $pages Textebene je Seite
     * @return array<string,array<string,mixed>>
     */
    public static function check(string $type, array $raw, array $pages): array
    {
        $out = [];
        foreach (InboxSchema::fields($type) as $name => $def) {
            $f = is_array($raw[$name] ?? null) ? $raw[$name] : ['value' => $raw[$name] ?? null];
            $out[$name] = $def['kind'] === 'list' ? self::checkList($def, $f, $pages) : self::checkScalar($def, $f, $pages);
            if ($out[$name]['status'] !== 'empty') {
                // Nachbesserung 5 — Hilfsangaben (Datum, Titel) nimmt refine() aus der Pflichtprüfung (auch im Bestand)
                $must = in_array($type, InboxSchema::REVIEW_ALL, true) ? 'mandatory' : (!empty($def['review']) ? 'price_from_invoice' : null);
                if ($must !== null) {
                    self::flag($out[$name], $must);
                    foreach ($out[$name]['items'] ?? [] as $i => $_) self::flag($out[$name]['items'][$i], $must);
                }
            }
        }
        if ($type === 'invoice') {
            self::crossCheckInvoice($out);
            self::readRates($out, $pages);   // Nachbesserung 3 — Satz aus Steuerspalte oder Umsatzsteuerzeile
            self::readEstimates($out, $pages);   // Nachbesserung 4 — geschätzte Zählerstände
            self::mergeReadings($out);           // Nachbesserung 5 — Einträge derselben Nummer zusammen
        }
        // v3.7.0 (treffsicher) — leere Felder und Hinweise wie beim Bauen der Vorschläge (ohne Bestand)
        return self::refine($type, $out);
    }

    // ── Marken (v3.7.0, treffsicher) ─────────────────────────────────────

    /**
     * Gründe, die nicht markieren, sondern nur erklären:
     *   missing        fehlt im Dokument, kein Vorschlag braucht es (offener Punkt)
     *   estimated      „voraussichtlich“: übernommen als Hinweis, keine Sperre
     *   supported      Auswahlfeld ohne gültiges Zitat, aber von anderen Feldern
     *                  gestützt (Einheit, Zählernummer am bekannten Zähler, Tarif)
     *   price_matches  Preis aus der Rechnung = Preis des Vertrags an dem Tag
     *   net_price      (Nachbesserung 2) netto mit gelesenem Steuersatz, für den
     *                  Vertrag in brutto umgerechnet (die App führt Bruttopreise)
     *   vat_changed    (Nachbesserung 2) gleicher Nettopreis wie im Vertrag, nur
     *                  ein anderer Steuersatz — keine Marke, die neue Preiszeile
     *                  gilt ab dem Zeitraum, für den die Rechnung sie ausweist
     */
    public const HINTS = ['missing', 'estimated', 'supported', 'price_matches', 'net_price', 'vat_changed'];

    /** Gründe, die refine() aus dem Rest neu bestimmt (der Rest bleibt, wie das Auslesen ihn fand). */
    private const DERIVED = ['empty', 'missing', 'supported', 'price_matches', 'net_price', 'vat_changed'];

    /** Zweifel am Zitat; bei einem gestützten Auswahlfeld kein Grund zur Marke. */
    private const QUOTE_REASONS = ['no_quote', 'quote_not_found', 'value_not_in_quote'];

    /**
     * v3.7.0 (treffsicher) — Marken neu bestimmen, aus den Gründen des
     * Auslesens und dem Bestand. Idempotent und umkehrbar: Die abgeleiteten
     * Gründe (DERIVED) fallen weg und kommen nach dem heutigen Stand wieder
     * dazu; alle anderen bleiben stehen. So gilt eine geänderte Regel auch für
     * schon ausgelesene Dokumente, und eine Stütze, die wegfällt (Vertrag mit
     * anderem Preis), macht die Marke wieder auf.
     *
     * Status: `empty` (fehlt, Grund `missing`), `check` (zu prüfen: ein
     * Zweifel ohne Stütze, oder leer und von einem Vorschlag gebraucht — Grund
     * `empty`), sonst `ok` — auch mit Hinweisen (HINTS).
     *
     * `$ctx` (aus Proposals::marks): `utility` und `utility_by_bestand` (die
     * Zuordnung steht über Zählernummer, Marktlokation oder Vertragsnummer
     * fest, oder der Nutzer hat sie gesetzt), `prices` (Feld → true, wenn der
     * Preis zum Vertrag passt; Listen je Eintrag).
     *
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed> $ctx
     * @return array<string,array<string,mixed>>
     */
    public static function refine(string $type, array $fields, array $ctx = []): array
    {
        $defs = InboxSchema::fields($type);
        $present = [];
        foreach ($fields as $name => $f) {
            if (!is_array($f)) continue;
            $f['reasons'] = array_values(array_diff((array)($f['reasons'] ?? []), self::DERIVED));
            foreach ((array)($f['items'] ?? []) as $i => $it) {
                $f['items'][$i]['reasons'] = array_values(array_diff((array)($it['reasons'] ?? []), self::DERIVED));
            }
            $present[$name] = !self::absent($f);
            $fields[$name] = $f;
        }
        $needed = InboxSchema::needed($type, $present);
        foreach ($fields as $name => $f) {
            if (!is_array($f)) continue;
            $def = $defs[$name] ?? ['kind' => 'text'];
            // Nachbesserung 5 — auch in schon gespeicherten Auslesungen: keine Pflichtprüfung einer Hilfsangabe
            if (!empty($def['helper']) && !isset($needed[$name])) $f['reasons'] = array_values(array_diff($f['reasons'], ['mandatory']));
            if (!$present[$name]) {
                $f['status'] = isset($needed[$name]) ? 'check' : 'empty';
                $f['reasons'] = [isset($needed[$name]) ? 'empty' : 'missing'];
                $fields[$name] = $f;
                continue;
            }
            if ($def['kind'] === 'enum' && array_intersect($f['reasons'], self::QUOTE_REASONS) !== []
                && self::supported($type, $name, $f['value'] ?? null, $fields, $ctx)) $f['reasons'][] = 'supported';
            if (!empty($def['review'])) {
                // Nachbesserung 2 — netto mit gelesenem Satz: Bruttopreis daneben (`net`), Hinweis net_price;
                // ctx `prices`: true = wie im Vertrag, 'vat_changed' = gleicher Nettopreis, anderer Satz
                $mark = fn(mixed $p): ?string => $p === true ? 'price_matches' : ($p === 'vat_changed' ? 'vat_changed' : null);
                if ($def['kind'] === 'list') {
                    foreach ((array)($f['items'] ?? []) as $i => $it) {
                        unset($f['items'][$i]['net']);
                        $tax = $name === 'working_prices' ? self::itemTax($it) : null;
                        if ($tax !== null && $tax['net'] && is_numeric($it['value']['ct'] ?? null)) {
                            $f['items'][$i]['net'] = ['vat_pct' => $tax['vat_pct'], 'gross' => self::grossOf((float)$it['value']['ct'], $tax)];
                            $f['items'][$i]['reasons'][] = 'net_price';
                        }
                        $r = $mark($ctx['prices'][$name][$i] ?? null);
                        if ($r !== null) $f['items'][$i]['reasons'][] = $r;
                    }
                } else {
                    unset($f['net']);
                    $tax = self::fieldTax($fields, $name);
                    if ($tax !== null && $tax['net'] && is_numeric($f['value'] ?? null)) {
                        $f['net'] = ['vat_pct' => $tax['vat_pct'], 'gross' => self::grossOf((float)$f['value'], $tax)];
                        $f['reasons'][] = 'net_price';
                    }
                    $r = $mark($ctx['prices'][$name] ?? null);
                    if ($r !== null) $f['reasons'][] = $r;
                }
            }
            if (!empty($f['items'])) {
                $all = [];
                $check = false;
                foreach ($f['items'] as $i => $it) {
                    $f['items'][$i]['status'] = self::statusOf($def, $it['reasons']);
                    $check = $check || $f['items'][$i]['status'] === 'check';
                    array_push($all, ...$it['reasons']);
                }
                $f['reasons'] = array_values(array_unique($all));
                $f['status'] = $check ? 'check' : 'ok';
            } else {
                $f['status'] = self::statusOf($def, $f['reasons']);
            }
            $fields[$name] = $f;
        }
        return $fields;
    }

    /** Fehlt das Feld im Dokument (ohne Wert, ohne eigenen Grund)? */
    private static function absent(array $f): bool
    {
        if (($f['status'] ?? '') === 'empty') return true;
        $v = $f['value'] ?? null;
        return ($v === null || $v === '' || $v === []) && empty($f['items'])
            && array_diff((array)($f['reasons'] ?? []), self::DERIVED) === [];
    }

    /** @param list<string> $reasons */
    private static function statusOf(array $def, array $reasons): string
    {
        $doubt = array_diff($reasons, self::HINTS);
        if (in_array('supported', $reasons, true)) $doubt = array_diff($doubt, self::QUOTE_REASONS);
        if (array_intersect(['price_matches', 'vat_changed'], $reasons) !== []) $doubt = array_diff($doubt, ['price_from_invoice']);
        return $doubt === [] ? 'ok' : 'check';
    }

    /**
     * Stützen andere Felder die Wahl eines Auswahlfelds? Für die Verbrauchsart:
     * Zählernummer, Marktlokation oder Vertragsnummer am bekannten Zähler bzw.
     * Vertrag (Bestand), sonst Einheiten und Stichwörter in den übrigen
     * Zitaten und im Tarifnamen, die genau auf diese Art zeigen. Für die
     * übrigen: Stichwörter der gewählten Option (und keiner anderen) in den
     * übrigen Zitaten, dazu Rechnungsart (neuer Abschlag → Jahresabrechnung),
     * Ergebnis (Betrag gegen Abschläge) und Grundpreis je Monat/Jahr (passt
     * zum Vertrag).
     */
    private static function supported(string $type, string $name, mixed $value, array $fields, array $ctx): bool
    {
        if (!is_string($value) || $value === '') return false;
        $text = self::otherText($fields, $name);
        if ($name === 'utility') {
            if (($ctx['utility'] ?? null) === $value && !empty($ctx['utility_by_bestand'])) return true;
            return self::utilityEvidence($text) === [$value];
        }
        if ($type === 'invoice' && $name === 'result_kind') {
            $total = $fields['total_amount']['value'] ?? null;
            $adv = $fields['advances_total']['value'] ?? ($fields['advances_paid']['sum'] ?? null);
            if (is_numeric($total) && is_numeric($adv) && abs((float)$total - (float)$adv) > 0.005) {
                return $value === ((float)$total > (float)$adv ? 'due' : 'credit');
            }
        }
        if ($type === 'invoice' && $name === 'kind' && $value === 'annual' && is_numeric($fields['advance_new']['value'] ?? null)) return true;
        if ($name === 'base_price_period') {
            foreach (['base_price', 'base_price_new'] as $bp) if (($ctx['prices'][$bp] ?? null) === true) return true;
        }
        $def = InboxSchema::fields($type)[$name] ?? [];
        $hints = (array)($def['hints'] ?? []);
        $hit = fn(string $o): bool => array_filter((array)($hints[$o] ?? []), fn($h) => str_contains($text, (string)$h)) !== [];
        if (!$hit($value)) return false;
        foreach ((array)($def['options'] ?? []) as $o) if ($o !== $value && $hit($o)) return false;
        return true;
    }

    /**
     * Auf welche Verbrauchsart zeigen Stichwörter und Einheiten? m³ und kWh
     * zusammen nur bei Gas, m³ allein bei Gas oder Wasser, kWh allein bei Gas,
     * Strom oder Fernwärme; Stichwörter wie im Schema der Verbrauchsart.
     *
     * @return list<string>
     */
    public static function utilityEvidence(string $normalizedText): array
    {
        $words = [];
        foreach ((array)(InboxSchema::fields('invoice')['utility']['hints'] ?? []) as $u => $hs) {
            foreach ((array)$hs as $h) if (str_contains($normalizedText, (string)$h)) { $words[] = (string)$u; break; }
        }
        $m3 = (bool)preg_match('/m³|\bm3\b|kubikmeter/u', $normalizedText);
        $kwh = str_contains($normalizedText, 'kwh');
        $units = $m3 && $kwh ? ['gas'] : ($m3 ? ['gas', 'wasser'] : ($kwh ? ['gas', 'strom', 'fernwaerme'] : []));
        if ($words === []) return $units;
        return $units === [] ? $words : array_values(array_intersect($words, $units));
    }

    /** Zitate der übrigen Felder (samt Einträgen) und Werte der Textfelder, normalisiert. */
    private static function otherText(array $fields, string $except): string
    {
        $parts = [];
        foreach ($fields as $n => $f) {
            if ($n === $except || !is_array($f)) continue;
            if (is_string($f['quote'] ?? null)) $parts[] = $f['quote'];
            if (in_array($n, ['tariff_name', 'supplier'], true) && is_string($f['value'] ?? null)) $parts[] = $f['value'];
            foreach ((array)($f['items'] ?? []) as $it) if (is_string($it['quote'] ?? null)) $parts[] = $it['quote'];
        }
        return self::normalizeText(implode(' · ', $parts));
    }

    /** @param array<string,mixed> $field */
    private static function flag(array &$field, string $reason): void
    {
        $field['status'] = 'check';
        if (!in_array($reason, $field['reasons'], true)) $field['reasons'][] = $reason;
    }

    /**
     * @param array<string,mixed> $def
     * @param array<string,mixed> $f
     * @param list<string> $pages
     * @return array<string,mixed>
     */
    private static function checkScalar(array $def, array $f, array $pages): array
    {
        $rawValue = $f['value'] ?? null;
        $quote = is_string($f['quote'] ?? null) ? trim($f['quote']) : '';
        $page = isset($f['page']) && is_numeric($f['page']) ? (int)$f['page'] : null;
        $res = ['value' => null, 'raw' => is_scalar($rawValue) ? $rawValue : null, 'quote' => $quote !== '' ? mb_substr($quote, 0, 400) : null,
                'page' => $page, 'status' => 'ok', 'reasons' => []];
        if ($rawValue === null || $rawValue === '' || (is_string($rawValue) && in_array(mb_strtolower(trim($rawValue)), ['null', 'n/a', '-', 'none'], true))) {
            $res['status'] = 'empty';
            return $res;
        }
        $found = $quote !== '' ? self::locate($quote, $pages, $page) : null;
        $valueQuote = self::quoteForValue($quote);
        if ($found === null && $quote !== '') {
            // zwei Stellen ohne „…“: der Wert im zweiten Teil (streng)
            $split = self::locateSplit($quote, $pages, $page, fn(string $part) => self::valueFor($def, $rawValue, $part)[1]);
            if ($split !== null) { $found = $split[0]; $valueQuote = $split[1]; }
        }
        // v3.7.0 (treffsicher, Nachbesserung 2) — Hilfsfelder (`aux`: netto/brutto,
        // Steuersatz) markieren nie; ob ihr Wert gelesen ist, prüft taxOf() am Zitat
        $aux = !empty($def['aux']);
        if (!$aux && $quote === '') self::flag($res, 'no_quote');
        elseif (!$aux && $found === null) self::flag($res, 'quote_not_found');
        if ($found !== null && $found > 0) $res['page'] = $found;
        // Nachbesserung 5 — ob ein Zitat gefunden ist, merkt sich auch ein Hilfsfeld (es markiert ja nicht);
        // von einem nicht gefundenen Zitat mit „…“ die Teile, die dastehen (foundQuote)
        if ($quote !== '') {
            if ($aux) $res['quote_found'] = $found !== null;
            if ($found === null && ($fp = self::foundParts($quote, $pages)) !== null) $res['found_parts'] = $fp;
        }
        [$value, $inQuote] = self::valueFor($def, $rawValue, $valueQuote);
        $res['value'] = $value;
        if ($aux) return $res;
        if ($value === null) { self::flag($res, 'unreadable'); return $res; }
        if ($quote !== '' && !$inQuote) self::flag($res, 'value_not_in_quote');
        // v3.7.0 (treffsicher, Nachbesserung 3) — Form der Marktlokations-ID: 11 Ziffern, erste
        // 1–9, Prüfziffer nach dem Lok- und Waggon-Kennzeichnungsverfahren (BDEW-Anwendungshilfe
        // „Identifikatoren in der Marktkommunikation“, Version 1.2 vom 07.02.2025, Kap. 3.2, 3.3
        // und 8.1). Dieselbe Prüfung wie beim Speichern am Zähler (MeterService::isValidMalo).
        if (!self::formatOk($def, $value, $quote)) self::flag($res, 'invalid_format');
        // „voraussichtlich“: Wert übernehmen, aber markieren (das Modell ließ ihn gern leer)
        if ($def['kind'] === 'date' && preg_match('/voraussichtlich|geplant|vorläufig/u', self::normalizeText($quote))) self::flag($res, 'estimated');
        return $res;
    }

    /**
     * @param array<string,mixed> $def
     * @param array<string,mixed> $f
     * @param list<string> $pages
     * @return array<string,mixed>
     */
    private static function checkList(array $def, array $f, array $pages): array
    {
        $items = $f['value'] ?? ($f['items'] ?? null);
        $res = ['value' => [], 'raw' => null, 'quote' => null, 'page' => null, 'status' => 'ok', 'reasons' => [], 'items' => []];
        if (!is_array($items) || $items === []) { $res['status'] = 'empty'; return $res; }
        $outerQuote = is_string($f['quote'] ?? null) ? $f['quote'] : '';
        foreach (array_values($items) as $item) {
            if (!is_array($item)) continue;
            $quote = trim((string)($item['quote'] ?? $outerQuote));
            $page = isset($item['page']) && is_numeric($item['page']) ? (int)$item['page'] : (isset($f['page']) && is_numeric($f['page']) ? (int)$f['page'] : null);
            $one = ['value' => [], 'quote' => $quote !== '' ? mb_substr($quote, 0, 400) : null, 'page' => $page, 'status' => 'ok', 'reasons' => []];
            $raws = [];
            foreach ($def['items'] as $sub => $subDef) {
                $raws[$sub] = $item[$sub] ?? (is_array($item['value'] ?? null) ? ($item['value'][$sub] ?? null) : null);
            }
            $found = $quote !== '' ? self::locate($quote, $pages, $page) : null;
            $valueQuote = self::quoteForValue($quote);
            if ($found === null && $quote !== '') {
                // v3.7.0 (treffsicher, Nachbesserung 2) — zwei Stellen ohne „…“: alle Werte im zweiten Teil
                $subs = array_filter(array_keys($raws), fn($s) => empty($def['items'][$s]['aux']) && $raws[$s] !== null && $raws[$s] !== '');
                $in = fn(string $part): array => array_filter($subs, fn($s) => self::valueFor($def['items'][$s], $raws[$s], $part)[1]);
                // der erste Teil trägt keinen der Werte, der zweite alle
                $split = $subs === [] ? null : self::locateSplit($quote, $pages, $page,
                    fn(string $part) => $in($part) !== [], fn(string $part) => count($in($part)) === count($subs));
                if ($split !== null) { $found = $split[0]; $valueQuote = $split[1]; }
            }
            if ($quote === '') self::flag($one, 'no_quote');
            elseif ($found === null) self::flag($one, 'quote_not_found');
            elseif ($found > 0) $one['page'] = $found;
            if ($quote !== '' && $found === null && ($fp = self::foundParts($quote, $pages)) !== null) $one['found_parts'] = $fp;   // Nachbesserung 5
            $any = false;
            foreach ($def['items'] as $sub => $subDef) {
                $raw = $raws[$sub];
                if ($raw === null || $raw === '') { $one['value'][$sub] = null; continue; }
                [$v, $in] = self::valueFor($subDef, $raw, $valueQuote);
                $one['value'][$sub] = $v;
                // Hilfsfelder (netto/brutto, Steuersatz) markieren nie
                if (!empty($subDef['aux'])) continue;
                // Nachbesserung 5 — eine Zählernummer aus der Kopfzeile direkt über den Ständen gilt als belegt
                if ($v !== null && !$in && ($subDef['format'] ?? null) === 'meter' && $found !== null && $found > 0
                    && self::headerAbove((string)($pages[$found - 1] ?? ''), $quote, (string)$v)) { $in = true; $one['number_from'] = 'header'; }
                if ($v === null) self::flag($one, 'unreadable');
                elseif ($quote !== '' && !$in) self::flag($one, 'value_not_in_quote');
                elseif (!self::formatOk($subDef, $v)) self::flag($one, 'invalid_format');   // im Eintrag nur der Wert (ein „*“ der Zeile kann eine Fußnote sein)
                $any = $any || $v !== null;
            }
            if (!$any) continue;
            $res['items'][] = $one;
            $res['value'][] = $one['value'];
            if ($one['status'] === 'check') foreach ($one['reasons'] as $r) self::flag($res, $r);
        }
        if ($res['items'] === []) $res['status'] = 'empty';
        return $res;
    }

    /**
     * Normalisierter Wert und ob er im Zitat steht.
     *
     * @param array<string,mixed> $def
     * @return array{0: mixed, 1: bool}
     */
    private static function valueFor(array $def, mixed $raw, string $quote): array
    {
        switch ($def['kind']) {
            case 'date':
                $iso = is_string($raw) ? self::dateValue($raw) : null;
                return [$iso, $iso !== null && in_array($iso, self::datesIn($quote), true)];
            case 'number': case 'money': case 'price_ct':
                $cands = self::valueReadings($raw);
                if ($cands === []) return [null, false];
                // Passt eine Lesart des Werts zu einem Zahlwort des Zitats, gilt die
                // deutsche Lesart dieses Worts: „13.580“ im Zitat ist 13580, auch
                // wenn das Modell 13.58 schrieb.
                foreach (self::numberTokens($quote) as $tok) {
                    foreach ($cands as $c) {
                        foreach ($tok as $q) if (self::sameNumber($c, $q)) return [round($tok[0], 6), true];
                    }
                }
                return [round(abs($cands[0]), 6), false];
            case 'bool':   // v3.7.0 (treffsicher, Nachbesserung 4) — Ablese-Art; entschieden wird am Zitat (readEstimates)
                if (is_bool($raw)) return [$raw, true];
                $s = is_scalar($raw) ? strtolower(trim((string)$raw)) : '';
                return [in_array($s, ['true', '1', 'ja', 'yes'], true) ? true : (in_array($s, ['false', '0', 'nein', 'no'], true) ? false : null), true];
            case 'enum':
                $v = is_string($raw) ? strtolower(trim($raw)) : null;
                if (!in_array($v, $def['options'], true)) return [null, false];
                $hints = $def['hints'][$v] ?? null;
                if ($hints === null) return [$v, true];
                $q = self::normalizeText($quote);
                foreach ($hints as $h) if (str_contains($q, $h)) return [$v, true];
                return [$v, false];
            default:   // text
                $v = is_scalar($raw) ? trim((string)$raw) : '';
                if ($v === '') return [null, false];
                $v = mb_substr($v, 0, 200);
                $nv = self::normalizeText($v);
                $nq = self::normalizeText($quote);
                return [$v, str_contains($nq, $nv) || str_contains(self::squeeze($nq), self::squeeze($nv))];
        }
    }

    /**
     * Gegenrechnungen der Rechnung: Die Summe der genannten Abschläge bildet
     * der Server (stehen oft auf mehreren Zeilen); steht eine Summe im
     * Dokument, muss sie dazu passen. Das Ergebnis muss Gesamtbetrag minus
     * Abschläge sein — sonst hat das Modell netto und brutto oder einen
     * Teilbetrag erwischt.
     *
     * @param array<string,array<string,mixed>> $out
     */
    private static function crossCheckInvoice(array &$out): void
    {
        $list = array_filter(array_map(fn($v) => $v['amount'] ?? null, (array)($out['advances_paid']['value'] ?? [])), fn($x) => $x !== null);
        $sum = $list !== [] ? round(array_sum($list), 2) : null;
        $out['advances_paid']['sum'] = $sum;
        $stated = $out['advances_total']['value'] ?? null;
        if ($sum !== null && $stated !== null && abs($sum - (float)$stated) > 0.05) self::flag($out['advances_total'], 'sum_mismatch');
        $advances = $stated ?? $sum;
        $total = $out['total_amount']['value'] ?? null;
        $result = $out['result_amount']['value'] ?? null;
        if ($advances !== null && $total !== null && $result !== null && abs(abs((float)$total - (float)$advances) - (float)$result) > 0.05) {
            // v3.7.0 (treffsicher, Nachbesserung 5) — alle beteiligten Felder: Welcher Wert falsch ist (oft ein
            // Teilbetrag statt des Gesamtbetrags), sagt die Rechnung nicht; bisher ging der Gesamtbetrag unmarkiert durch
            self::flag($out['result_amount'], 'inconsistent');
            self::flag($out['total_amount'], 'inconsistent');
            if ($stated !== null) self::flag($out['advances_total'], 'inconsistent');
            else foreach (array_keys((array)($out['advances_paid']['items'] ?? [])) as $i) self::flag($out['advances_paid']['items'][$i], 'inconsistent');
        }
    }

    /**
     * Zahl der markierten Felder (je Listeneintrag einzeln). v3.7.0
     * (treffsicher) — ohne die vom Nutzer bestätigten oder korrigierten
     * (`$edits`), also das, was noch zu prüfen ist.
     *
     * @param array<string,mixed> $edits
     */
    public static function flagCount(array $fields, array $edits = []): int
    {
        $n = 0;
        foreach ($fields as $name => $f) {
            if (array_key_exists($name, $edits)) continue;
            if (!empty($f['items'])) { foreach ($f['items'] as $i) $n += ($i['status'] ?? '') === 'check' ? 1 : 0; continue; }
            $n += ($f['status'] ?? '') === 'check' ? 1 : 0;
        }
        return $n;
    }
}
