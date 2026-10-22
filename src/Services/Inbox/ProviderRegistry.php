<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Services\SettingsService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Posteingang) — welcher Anbieter liest aus.
 *
 * Kennt `local` (LocalProvider, die Einstellung der Texterkennung) und nimmt
 * weitere über register() an — die Cloud-Anschlüsse melden sich so an, ohne
 * dass der Posteingang sie kennen muss. Die Einstellung `inbox_provider`
 * (Standard `local`) wählt aus; ein unbekannter oder nicht angemeldeter
 * Anbieter fällt nie still auf einen anderen zurück, sondern meldet sich als
 * Fehler — sonst ginge ein Dokument an einen Dienst, den niemand gewählt hat.
 */
final class ProviderRegistry
{
    public const DEFAULT = 'local';

    /** @var array<string, ExtractionProvider> */
    private array $providers = [];

    public function __construct(private SettingsService $settings, ExtractionProvider $local)
    {
        $this->register($local);
    }

    public function register(ExtractionProvider $p): void
    {
        $this->providers[$p->id()] = $p;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function get(string $id): ExtractionProvider
    {
        return $this->providers[$id]
            ?? throw new LocalizedException('errors.inbox.providerUnknown', ['provider' => $id], "inbox provider $id not registered");
    }

    /** Kennung laut Einstellung (auch wenn der Anbieter nicht angemeldet ist). */
    public function activeId(): string
    {
        $id = trim((string)$this->settings->get('inbox_provider', self::DEFAULT));
        return $id === '' ? self::DEFAULT : $id;
    }

    public function active(): ExtractionProvider
    {
        return $this->get($this->activeId());
    }

    /** @return list<string> angemeldete Anbieter */
    public function ids(): array
    {
        return array_keys($this->providers);
    }
}
