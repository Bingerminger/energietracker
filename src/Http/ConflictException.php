<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * v2.6.0 — Die Aktion ist im jetzigen Zustand nicht möglich, lässt sich aber
 * mit einer ausdrücklichen Zustimmung wiederholen (HTTP 409). Beispiel: Der
 * Sicherungs-Snapshot vor einem Backup-Import scheitert.
 */
final class ConflictException extends \RuntimeException
{
}
