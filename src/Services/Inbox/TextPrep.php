<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

/**
 * v3.7.0 (Posteingang) — die Textebene vor dem Senden vorbereiten.
 *
 * Der Browser liest die Textebene je Seite (pdf.js) und schickt sie mit. Lange
 * Dokumente mit AGB-Anhang kosteten in der Probe viel Zeit, ohne dass dort ein
 * Feld stand: Seiten ab der zweiten, die als Bedingungen erkennbar sind
 * (Überschrift im Kopf der Seite oder viele Paragrafen), fallen weg. Danach
 * gilt eine Obergrenze für die Zeichen; die Seitenmarken „[Seite N]“ lassen das
 * Modell die Seite einer Belegstelle nennen.
 */
final class TextPrep
{
    /** Obergrenze für die Felder (≈ 12.000 Token). */
    public const MAX_CHARS = 40000;
    /** Für die Erkennung der Art genügt der Anfang. */
    public const CLASSIFY_CHARS = 6000;
    /** Mehr nimmt der Server gar nicht an (je Anfrage, alle Seiten). */
    public const MAX_INPUT_CHARS = 2000000;
    public const MAX_PAGES = 400;

    private const TERMS_HEADINGS = [
        'allgemeine geschäftsbedingungen', 'allgemeine bedingungen', 'allgemeine lieferbedingungen',
        'ergänzende bedingungen', 'besondere bedingungen', 'vertragsbedingungen', 'lieferbedingungen',
        'widerrufsbelehrung', 'muster-widerrufsformular', 'widerrufsformular', 'datenschutzhinweise',
        'informationen zum datenschutz', 'hinweise zum datenschutz', 'agb',
    ];

    /**
     * Seiten aus der Anfrage: Liste von Texten, höchstens MAX_PAGES, zusammen
     * höchstens MAX_INPUT_CHARS (sonst InvalidArgumentException).
     *
     * @return list<string>
     */
    public static function pages(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input)) throw new \InvalidArgumentException('pages: list expected');
        if (count($input) > self::MAX_PAGES) throw new \InvalidArgumentException('pages: too many');
        $out = []; $total = 0;
        foreach ($input as $p) {
            $s = is_string($p) ? $p : '';
            $s = mb_scrub($s, 'UTF-8');
            $total += strlen($s);
            if ($total > self::MAX_INPUT_CHARS) throw new \InvalidArgumentException('pages: too long');
            $out[] = $s;
        }
        return $out;
    }

    /** Hat das Dokument überhaupt eine Textebene? */
    public static function hasText(array $pages): bool
    {
        $letters = 0;
        foreach ($pages as $p) $letters += preg_match_all('/\p{L}/u', (string)$p);
        return $letters >= 40;
    }

    /** Ist die Seite ein Bedingungs-Anhang? (nie die erste) */
    public static function isTermsPage(string $page, int $index): bool
    {
        if ($index === 0) return false;
        $head = Evidence::normalizeText(mb_substr($page, 0, 300));
        foreach (self::TERMS_HEADINGS as $h) {
            if ($h === 'agb' ? (bool)preg_match('/(^|\s)agb(\s|$|:)/u', $head) : str_contains($head, $h)) return true;
        }
        return preg_match_all('/§\s*\d+/u', $page) >= 6;
    }

    /**
     * Text für das Modell: AGB-Seiten heraus, Seitenmarken davor, gekürzt.
     *
     * @param list<string> $pages
     * @return array{text: string, kept: list<int>, dropped: list<int>, truncated: bool, chars: int}
     */
    public static function prepare(array $pages, int $maxChars = self::MAX_CHARS): array
    {
        $kept = []; $dropped = []; $parts = []; $len = 0; $truncated = false;
        foreach ($pages as $i => $p) {
            if (self::isTermsPage($p, $i)) { $dropped[] = $i + 1; continue; }
            $body = trim(preg_replace("/[ \t]+/u", ' ', $p) ?? $p);
            $chunk = '[Seite ' . ($i + 1) . "]\n" . $body . "\n\n";
            if ($len + mb_strlen($chunk) > $maxChars) {
                $rest = $maxChars - $len;
                if ($rest > 200) { $parts[] = mb_substr($chunk, 0, $rest); $kept[] = $i + 1; }
                $truncated = true;
                break;
            }
            $parts[] = $chunk;
            $kept[] = $i + 1;
            $len += mb_strlen($chunk);
        }
        $text = rtrim(implode('', $parts));
        return ['text' => $text, 'kept' => $kept, 'dropped' => $dropped, 'truncated' => $truncated, 'chars' => mb_strlen($text)];
    }
}
