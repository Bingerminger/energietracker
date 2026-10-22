<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

/**
 * v3.7.0 (Posteingang) — ein Dienst, der Versorgerpost ausliest.
 *
 * Der lokale Anschluss (LocalProvider: Ollama oder OpenAI-kompatibel im
 * Heimnetz) ist der Standard; Cloud-Anschlüsse melden sich über
 * ProviderRegistry::register() an. Welcher gilt, entscheidet die Einstellung
 * `inbox_provider`. Den Ablauf (erst die Art, dann die Felder), die
 * Belegprüfung, den Zwischenspeicher und das Übernehmen macht der
 * InboxService — ein Anbieter liefert nur die Antworten.
 *
 * Text: die Textebene mit Seitenmarken „[Seite N]“, AGB-Seiten schon entfernt
 * (TextPrep). `$pdfBase64` nur, wenn `supportsPdf()` wahr ist.
 *
 * Fehler: Support\LocalizedException mit einem Schlüssel aus `errors.inbox.*`
 * oder `errors.ocr.*` (nicht erreichbar, Zeitlimit, unbrauchbare Antwort);
 * der Controller antwortet dann mit 502.
 */
interface ExtractionProvider
{
    /** 'local' oder die Kennung eines Cloud-Anschlusses (z. B. 'openai') */
    public function id(): string;

    /**
     * Name und Ziel für den Hinweis vor dem Auslesen („Dieses Dokument geht an
     * …“), z. B. „Ollama im Heimnetz (192.168.178.20)“. In der Sprache der
     * Anfrage, ohne Schlüssel oder Zugangsdaten.
     */
    public function label(): string;

    /** Nimmt das PDF selbst statt nur Text. */
    public function supportsPdf(): bool;

    /**
     * Art des Dokuments (eine aus InboxSchema::TYPES).
     *
     * @return array{type: string, confidence: ?float, model: string, seconds: float}
     */
    public function classify(string $text, ?string $pdfBase64 = null): array;

    /**
     * Felder einer Art nach `$schema` (InboxSchema::forProvider()). Je Feld
     * Wert, wörtliches Zitat und Seite; Listenfelder (`kind: list`) als
     * `value` = Liste von Objekten mit den Unterfeldern und je eigenem
     * `quote`/`page`. Werte wie gedruckt (deutsche Zahlen), nicht umgerechnet.
     *
     * @param array<string,mixed> $schema
     * @return array{fields: array<string, array{value: mixed, quote: ?string, page: ?int}>, model: string, seconds: float}
     */
    public function extract(string $type, array $schema, string $text, ?string $pdfBase64 = null): array;
}
