<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

/**
 * v3.7.0 (Posteingang) — dasselbe Dokument (gleiche Prüfsumme) liegt schon im
 * Posteingang. Trägt den vorhandenen Eintrag, damit die Oberfläche sagen kann,
 * seit wann („liegt schon seit … im Posteingang“), und dorthin springt. Der
 * InboxController antwortet mit 409 und `detail.existing`.
 */
final class DuplicateDocumentException extends \RuntimeException
{
    /** @param array<string,mixed> $existing */
    public function __construct(public readonly array $existing)
    {
        parent::__construct('inbox: duplicate document');
    }
}
