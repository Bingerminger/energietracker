<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v2.5.3 — Eine Datumsprüfung für alle Schreibpfade.
 *
 * Bis v2.5.2 gab es zwei private Kopien (MeterService, ConversionFactorService),
 * und die meisten Schreibpfade prüften nur das Muster `\d{4}-\d{2}-\d{2}` oder
 * gar nichts. Ein Datum wie `2025-13-01` kam so über Ingest, Ablesung oder
 * Vertrag in die Daten — und `new \DateTime()` im Rechenpfad brach danach
 * Verbrauch, Prognose, Empfehlungen, CSV und PDF mit HTTP 500 ab. Ein
 * beliebiger String im Vertragsdatum landete zudem ungefiltert im DOM (XSS).
 *
 * Kalendergültig heißt: Muster JJJJ-MM-TT UND `checkdate()` — der 31.02. ist
 * kein Datum.
 *
 * v3.12.0 — Das Muster endet jetzt wirklich am Ende (Modifier `D`): Ohne ihn
 * passte `$` auch vor einem abschließenden Zeilenumbruch, „2026-11-04⏎“ galt als
 * Datum und landete so in Ablesung, Gerätetausch und Temperaturen.
 */
final class Dates
{
    /**
     * v3.12.0 — Jahresbereich für Daten aus Eingaben (Ablesung, Vertrag, Termin,
     * Lieferung, Zeitraum, Rechnung, Zähler). Eine Ablesung im Jahr 0001 (oder ein
     * Tippfehler 0202) wurde angenommen; die Monatsrechnung legte danach für die
     * zweitausend Jahre bis heute Monatszeilen an und brach Übersicht, „Zu tun“
     * und Agenda am Speicher ab. Gelesen wird tolerant (isIsoDate), geschrieben
     * nur in diesem Bereich (isInputDate).
     */
    public const MIN_INPUT_YEAR = 1900;
    public const MAX_INPUT_YEAR = 2100;

    public static function isIsoDate(mixed $value): bool
    {
        if (!is_string($value)) return false;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** v3.12.0 — Kalendertag JJJJ-MM-TT im Bereich MIN_INPUT_YEAR bis MAX_INPUT_YEAR. */
    public static function isInputDate(mixed $value): bool
    {
        return self::isIsoDate($value) && self::inInputRange($value);
    }

    /** v3.12.0 — liegt das Jahr eines Kalendertags im Eingabebereich? (ohne Formprüfung) */
    public static function inInputRange(string $isoDate): bool
    {
        $y = (int)substr($isoDate, 0, 4);
        return $y >= self::MIN_INPUT_YEAR && $y <= self::MAX_INPUT_YEAR;
    }

    /**
     * v3.1.0 (Review I18N-11) — Datum aus einer Tabelle, wie Menschen es
     * schreiben: ISO `JJJJ-MM-TT` oder Tag vor Monat mit vierstelligem Jahr —
     * `15.01.2026` (de), `15/01/2026` (fr, it, es, pt, en-GB), `15-01-2026` (nl),
     * Tag und Monat auch einstellig. Bis v3.0 nur deutsch oder ISO: Tabellen aus
     * Frankreich oder den Niederlanden scheiterten Zeile für Zeile.
     *
     * Monat vor Tag (US) wird nie still umgedeutet: Steht an zweiter Stelle ein
     * Wert über 12, kommt null mit `$error = 'monthFirst'` zurück.
     */
    public static function parseUserDate(string $value, ?string &$error = null): ?string
    {
        $error = null;
        $s = trim($value, " \t\"'");
        if (self::isIsoDate($s)) return $s;
        if (!preg_match('/^(\d{1,2})([.\/-])(\d{1,2})\2(\d{4})$/', $s, $m)) return null;
        [$day, $month, $year] = [(int)$m[1], (int)$m[3], (int)$m[4]];
        if ($month > 12 && $day <= 12) {
            $error = 'monthFirst';
            return null;
        }
        $iso = sprintf('%04d-%02d-%02d', $year, $month, $day);
        return self::isIsoDate($iso) ? $iso : null;
    }
}
