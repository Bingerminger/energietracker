<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 — Danke sagen: Die Einstellung `support_hint` ist an, solange niemand
 * sie ausschaltet, und bleibt ein Wahrheitswert (der Browser prüft `=== false`).
 */
#[CoversClass(SettingsService::class)]
final class SupportHintSettingTest extends ServiceTestCase
{
    public function testTheHintIsOnByDefault(): void
    {
        self::assertTrue($this->settings->all()['support_hint'] ?? null);
    }

    public function testTheSettingIsStoredAsABoolean(): void
    {
        self::assertFalse($this->settings->set(['support_hint' => 0])['support_hint']);
        self::assertFalse($this->settings->all()['support_hint']);
        self::assertTrue($this->settings->set(['support_hint' => '1'])['support_hint']);
    }

    /**
     * v3.13.0 (Paket gegenproben) — gespeichert wird der Wahrheitswert selbst, nicht die Eingabe:
     * all() normalisiert beim Lesen auch einen gespeicherten Text, darum prüft der Test die Datei.
     */
    public function testTheStoredValueIsABooleanNotTheInput(): void
    {
        $this->settings->set(['support_hint' => 0]);
        self::assertSame(false, $this->store->read('settings.json', [])['support_hint'] ?? null, 'nach 0: genau false');
        $this->settings->set(['support_hint' => '1']);
        self::assertSame(true, $this->store->read('settings.json', [])['support_hint'] ?? null, "nach '1': genau true");
    }
}
