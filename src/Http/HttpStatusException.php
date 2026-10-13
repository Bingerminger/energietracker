<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * v3.4.0 (N1020) — Eine Anfrage scheitert mit einem bestimmten HTTP-Status,
 * der nicht aus dem Fehlertyp folgt: 413 (Körper zu groß) oder 503 (die
 * Schreibsperre ist innerhalb der Frist nicht frei). Trägt wie
 * `LocalizedException` Katalogschlüssel und Parameter; der ErrorHandler
 * übersetzt in der Sprache der Anfrage, meldet den Schlüssel als `code` und
 * setzt die Kopfzeilen (etwa `Retry-After`).
 */
final class HttpStatusException extends \RuntimeException
{
    /**
     * @param array<string,scalar> $params
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $key,
        public readonly array $params = [],
        public readonly array $headers = [],
        string $logMessage = '',
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $key);
    }
}
