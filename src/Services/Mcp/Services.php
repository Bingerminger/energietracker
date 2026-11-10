<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Die Dienste der App für die Werkzeuge, nach Klasse.
 *
 * Werkzeuge rufen Dienste, nicht Routen (Entscheidung 3). Damit jedes Paket nur
 * seine Gruppendatei anfasst, holt sich eine Gruppe, was sie braucht:
 * `$this->services->get(SummaryService::class)`. In der App löst ein Abruf über
 * die Eigenschaften des Containers auf (genau diese Klasse), im Test über die
 * übergebenen Objekte (McpTestCase). Eigene Bausteine der Schnittstelle
 * (Config, PendingChanges) kommen als `$extra` dazu.
 */
final class Services
{
    /** @var array<class-string,object> */
    private array $extra = [];

    /**
     * @param \Closure(class-string): ?object $resolve
     * @param list<object> $extra
     */
    public function __construct(private \Closure $resolve, array $extra = [])
    {
        foreach ($extra as $o) $this->extra[$o::class] = $o;
    }

    /** Aus fertigen Objekten (Tests). */
    public static function of(object ...$objects): self
    {
        return new self(fn(string $class): ?object => null, $objects);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        $o = $this->extra[$class] ?? ($this->resolve)($class);
        if (!$o instanceof $class) throw new \LogicException("mcp: service $class is not available");
        return $o;
    }

    public function has(string $class): bool
    {
        return isset($this->extra[$class]) || ($this->resolve)($class) instanceof $class;
    }
}
