<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Support\Dates;

/**
 * v3.12.0 (F1037) — Wer fragt, in welcher Fassung und an welchem Tag: eine
 * MCP-Anfrage für sich (zustandslos). Der Controller legt sie aus dem geprüften
 * Schlüssel an, der Server ergänzt Fassung und Client aus der Nachricht.
 *
 * Name und Version des Clients sind Selbstauskünfte (nur Anzeige im Protokoll),
 * nie Grundlage einer Entscheidung. Der Schlüssel selbst steht hier nicht, nur
 * seine ID und sein Name.
 */
final class McpContext
{
    /** Bereiche eines Schlüssels, die an /api/mcp gelten; `mcp_write` schließt `mcp` ein */
    public const SCOPES = ['mcp', 'mcp_write'];

    public readonly string $today;

    /**
     * @param string $keyId   ID des API-Schlüssels (`k_…`), nie der Schlüssel
     * @param string $keyName Name des Schlüssels (Verlauf: „KI am Laptop“)
     * @param string $scope   mcp | mcp_write
     * @param string $locale  Sprache der Texte (Server::LOCALES)
     * @param string|null $today JJJJ-MM-TT; null = heute (Zeitzone der Installation)
     * @param array{name?:string, version?:string} $client bereinigt (Sanitize::clientInfo)
     */
    public function __construct(
        public readonly string $keyId,
        public readonly string $keyName,
        public readonly string $scope,
        public readonly string $locale = 'de',
        ?string $today = null,
        public readonly string $version = '',
        public readonly bool $modern = false,
        public readonly array $client = [],
    ) {
        if (!in_array($scope, self::SCOPES, true)) throw new \InvalidArgumentException("mcp: scope '$scope' is not an MCP scope");
        if ($today !== null && !Dates::isIsoDate($today)) throw new \InvalidArgumentException('mcp: today must be YYYY-MM-DD');
        $this->today = $today ?? date('Y-m-d');
    }

    /** Darf dieser Schlüssel ein Werkzeug mit Bereich `$scope` nutzen? */
    public function allows(string $scope): bool
    {
        return match ($scope) {
            'mcp'       => true,
            'mcp_write' => $this->scope === 'mcp_write',
            default     => false,
        };
    }

    /** Dieselbe Anfrage mit Fassung und Client aus der Nachricht. */
    public function withProtocol(string $version, bool $modern, array $client): self
    {
        return new self($this->keyId, $this->keyName, $this->scope, $this->locale, $this->today, $version, $modern, $client);
    }
}
