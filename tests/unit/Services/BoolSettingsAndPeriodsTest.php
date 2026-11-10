<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\PeriodService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wahrheitswerte als Text: Bisher machte `(bool)"false"` bzw. `!empty("nein")` daraus
 * true. Eine Einstellung wie weather_auto_fill wurde so durch „false“ eingeschaltet und ein
 * Zeitraum mit „nein“ als geschätzt gespeichert. Jetzt gilt dieselbe Regel wie an Ablesungen
 * (Input::bool), Unsinn gibt einen Fehler statt still „an“.
 */
#[CoversClass(SettingsService::class)]
#[CoversClass(PeriodService::class)]
final class BoolSettingsAndPeriodsTest extends ServiceTestCase
{
    public function testABooleanSettingReadsFalseAsFalse(): void
    {
        $this->settings->set(['weather_auto_fill' => true]);
        $this->settings->set(['weather_auto_fill' => 'false']);
        self::assertFalse($this->settings->get('weather_auto_fill'), '„false“ schaltet aus, nicht ein');
        $this->settings->set(['weather_auto_fill' => 'nein']);
        self::assertFalse($this->settings->get('weather_auto_fill'));
        $this->settings->set(['weather_auto_fill' => '1']);
        self::assertTrue($this->settings->get('weather_auto_fill'));
    }

    public function testNonsenseForABooleanSettingIsRejected(): void
    {
        $this->settings->set(['weather_auto_fill' => true]);
        try {
            $this->settings->set(['weather_auto_fill' => 'vielleicht']);
            self::fail('„vielleicht“ ist kein Wahrheitswert');
        } catch (\InvalidArgumentException) {
        }
        self::assertTrue($this->settings->get('weather_auto_fill'), 'der alte Wert bleibt');
    }

    public function testAPeriodWithNeinIsNotEstimated(): void
    {
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $m = (string)$this->meters->create('strom', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => '2025-01-01'])['id'];
        $p = $periods->create('strom', ['meter_id' => $m, 'from' => '2025-02-01', 'to' => '2025-02-28', 'value' => 300, 'is_estimated' => 'nein']);
        self::assertFalse($p['is_estimated'], '„nein“ ist nicht geschätzt');
        $q = $periods->create('strom', ['meter_id' => $m, 'from' => '2025-03-01', 'to' => '2025-03-31', 'value' => 310, 'is_estimated' => 'false']);
        self::assertFalse($q['is_estimated']);
        $r = $periods->create('strom', ['meter_id' => $m, 'from' => '2025-04-01', 'to' => '2025-04-30', 'value' => 320, 'is_estimated' => 'ja']);
        self::assertTrue($r['is_estimated']);
    }

    public function testNonsenseForEstimatedIsRejected(): void
    {
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $m = (string)$this->meters->create('strom', ['name' => 'Portal', 'capture' => 'period', 'installed_on' => '2025-01-01'])['id'];
        $this->expectException(LocalizedException::class);
        $periods->create('strom', ['meter_id' => $m, 'from' => '2025-02-01', 'to' => '2025-02-28', 'value' => 300, 'is_estimated' => 'vielleicht']);
    }
}
