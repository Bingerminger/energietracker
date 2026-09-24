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
 */
final class Dates
{
    public static function isIsoDate(mixed $value): bool
    {
        if (!is_string($value)) return false;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
