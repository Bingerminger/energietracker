<?php
declare(strict_types=1);

namespace Energietracker\Storage;

/**
 * v3.13.0 (K2d) — Das Datenverzeichnis nimmt keine Datei an (fremder Besitzer nach einem Wechsel der UID, Volume nur
 * lesbar). Der ErrorHandler antwortet mit 503 und `errors.storage.notWritable` statt mit 500 „Interner Fehler“; die
 * Meldung sagt, dass die Rechte am Datenordner anzupassen sind (welcher Benutzer, steht im Server-Log).
 */
final class StorageNotWritableException extends \RuntimeException
{
    public function __construct(public readonly string $dataFile)
    {
        parent::__construct('Datenverzeichnis nicht beschreibbar: ' . $dataFile);
    }
}
