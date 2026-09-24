<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v2.6.0 — Ein Backup, das die Prüfung vor dem Einspielen nicht besteht.
 * Trägt die Problemliste (Topf, Eintrag, Art), damit die Oberfläche zeigen
 * kann, was nicht stimmt, statt nur „ungültig" zu sagen.
 */
final class BackupInvalidException extends \InvalidArgumentException
{
    /** @param list<array{pot:string, index:?int, problem:string}> $problems */
    public function __construct(string $message, public readonly array $problems)
    {
        parent::__construct($message);
    }
}
