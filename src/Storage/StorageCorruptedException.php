<?php
declare(strict_types=1);

namespace Energietracker\Storage;

/**
 * v2.5.3 — Eine Datendatei ist vorhanden, aber nicht lesbar oder kein
 * gültiges JSON. Der ErrorHandler antwortet mit HTTP 503.
 *
 * Bis v2.5.2 las `JsonStore` eine solche Datei still als leer; der nächste
 * Schreibzugriff (z. B. der tägliche Home-Assistant-Push) ersetzte den
 * Bestand dann endgültig durch einen einzigen Eintrag. Jetzt bleibt die Datei
 * unangetastet, eine Kopie liegt daneben, und jede Anfrage, die sie braucht,
 * scheitert sichtbar statt still.
 *
 * Die Meldung ist deutsch und geht ins Log: `JsonStore` steht vor dem
 * I18nService in der Abhängigkeitskette (I18n → Settings → JsonStore) und kann
 * nicht übersetzen. v3.1.0 (Review I18N-14): Art, Datei und Kopie reisen mit;
 * der ErrorHandler übersetzt daraus `errors.storage.<kind>` für den Client.
 */
final class StorageCorruptedException extends \RuntimeException
{
    /** @param 'corrupted'|'corruptedCopy'|'unreadable'|'locked' $kind */
    public function __construct(
        string $message,
        public readonly string $kind = 'corrupted',
        public readonly string $dataFile = '',
        public readonly ?string $copy = null,
    ) {
        parent::__construct($message);
    }
}
