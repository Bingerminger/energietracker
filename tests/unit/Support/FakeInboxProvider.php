<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\Inbox\ExtractionProvider;

/**
 * v3.7.0 (Posteingang) — Anbieter für die Tests: vorbereitete Antworten, zählt
 * die Aufrufe mit (Zwischenspeicher) und merkt sich Text und Schema. Mit einer
 * anderen Kennung als `local` steht er für einen Cloud-Anschluss.
 */
final class FakeInboxProvider implements ExtractionProvider
{
    public int $classifyCalls = 0;
    public int $extractCalls = 0;
    public ?string $lastText = null;
    public ?array $lastSchema = null;

    /**
     * @param array<string,string> $types  Stichwort im Text → Art
     * @param array<string,array>  $fields Art → Rohfelder
     */
    public function __construct(private string $id, private array $types, private array $fields, private string $model) {}

    public function id(): string { return $this->id; }
    public function label(): string { return 'Testdienst (' . $this->id . ')'; }
    public function supportsPdf(): bool { return false; }
    public function model(): string { return $this->model; }

    public function classify(string $text, ?string $pdfBase64 = null): array
    {
        $this->classifyCalls++;
        $this->lastText = $text;
        foreach ($this->types as $needle => $type) {
            if (str_contains($text, $needle)) return ['type' => $type, 'confidence' => 0.9, 'model' => $this->model, 'seconds' => 1.0];
        }
        return ['type' => 'other', 'confidence' => 0.5, 'model' => $this->model, 'seconds' => 1.0];
    }

    public function extract(string $type, array $schema, string $text, ?string $pdfBase64 = null): array
    {
        $this->extractCalls++;
        $this->lastText = $text;
        $this->lastSchema = $schema;
        return ['fields' => $this->fields[$type] ?? [], 'model' => $this->model, 'seconds' => 2.0];
    }
}
