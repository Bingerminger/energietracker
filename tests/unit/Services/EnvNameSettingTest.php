<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.1 — Umgebungsname (`env_name`): ein kurzer Text als Abzeichen neben dem
 * Logo. Der Server begrenzt und bereinigt ihn; leer ist der Standard.
 */
#[CoversClass(SettingsService::class)]
final class EnvNameSettingTest extends ServiceTestCase
{
    public function testEmptyByDefault(): void
    {
        self::assertSame('', $this->settings->all()['env_name']);
    }

    public function testCleanedAndLimitedToTwelveCharacters(): void
    {
        $this->settings->set(['env_name' => "  <b>Test</b>\u{0007}   Haus \u{200B}2026 mit Rest "]);
        $v = $this->settings->get('env_name');
        self::assertSame('Test Haus 20', $v, 'ohne Tag, Steuer- und Formatzeichen, Leerraum zusammengefasst, 12 Zeichen');
        self::assertSame(SettingsService::ENV_NAME_MAX, mb_strlen($v));
        $this->settings->set(['env_name' => 'Ferienwohnung Süd']);
        self::assertSame('Ferienwohnun', $this->settings->get('env_name'));
        $this->settings->set(['env_name' => '   ']);
        self::assertSame('', $this->settings->get('env_name'), 'nur Leerraum = kein Abzeichen');
        $this->settings->set(['env_name' => null]);
        self::assertSame('', $this->settings->get('env_name'));
    }

    public function testRejectsNonText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['env_name' => ['TEST']]);
    }
}
