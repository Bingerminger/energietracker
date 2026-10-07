<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.1.0 (Review I18N-14) — Validierungsfehler aus Code ohne Übersetzung.
 *
 * Statische Helfer wie Utilities::get() kennen den I18nService nicht und
 * warfen deutschen Rohtext, der als 400 ungefiltert beim Client ankam
 * („Unbekannte Verbrauchsart: …" auch in einer englischen Oberfläche). Diese
 * Ausnahme trägt Katalogschlüssel und Parameter; der ErrorHandler übersetzt
 * sie in der Sprache der Anfrage und meldet den Schlüssel als `code`. Die
 * Meldung selbst ist der technische Text fürs Log.
 */
final class LocalizedException extends \InvalidArgumentException
{
    /** @param array<string,scalar> $params */
    public function __construct(
        public readonly string $key,
        public readonly array $params,
        string $logMessage,
    ) {
        parent::__construct($logMessage);
    }
}
