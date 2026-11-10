<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — Einstellungen nehmen nur den Typ ihrer Vorgabe und Zahlen in fachlichen Grenzen.
 * Bisher wurde `forecast_model: []` gespeichert und legte danach Übersicht, Zu tun und Jahresbericht
 * mit 500 lahm; eine Heizgrenze von 10³⁰⁸ legte jede Auswertung lahm, 10³⁰⁸ Monate ergaben beim Speichern
 * 500 (PHP 8.5: Kommazahl nicht als Ganzzahl darstellbar), „12,5“ Monate blieben 12,5.
 */
#[CoversClass(SettingsService::class)]
final class SettingsTypesTest extends ServiceTestCase
{
    /** @return array<string, array{0: string, 1: mixed}> */
    public static function rejected(): array
    {
        return [
            'Modell als Liste'        => ['forecast_model', []],
            'Modell unbekannt'        => ['forecast_model', 'quadratisch'],
            'Knick als Liste'         => ['segmented_split_mode', []],
            'Gebäudetyp als Objekt'   => ['gebaeudetyp', ['a' => 1]],
            'Gebäudetyp unbekannt'    => ['gebaeudetyp', 'burg'],
            'Arten als Objekt'        => ['active_utilities', ['a' => 1]],
            'Arten als Text'          => ['active_utilities', 'ja'],
            'Art unbekannt'           => ['active_utilities', ['gas', 'kohle']],
            'Ort als Liste'           => ['location_name', []],
            'Quelle als Liste'        => ['reference_source', []],
            'Umgebung als Schalter'   => ['env_name', true],
            'Sprache unbekannt'       => ['language', 'xx'],
            'Sprache als Liste'       => ['language', ['de']],
            'Baujahr als Wort'        => ['baujahr', 'abc'],
            'Heizgrenze 10^308'       => ['hdd_base_temp', 1e308],
            'Heizgrenze 40 °C'        => ['hdd_base_temp', 40],
            'Breite über 90'          => ['latitude', 91],
            'Länge unter −180'        => ['longitude', -181],
            'CO2 negativ'             => ['co2_gas', -1],
            'CO2 riesig'              => ['co2_strom', 1e308],
            'Monate 10^308'           => ['dashboard_months', 1e308],
            'Monate 12,5'             => ['forecast_months', '12,5'],
            'Tage 10^308'             => ['alert_days_since_reading', 1e308],
            'Frist 10^308'            => ['contract_remind_days_1', 1e308],
            'Zeitlimit 10^308'        => ['ocr_timeout_s', 1e308],
            'Zeitlimit 30,5'          => ['ocr_timeout_s', 30.5],
            'Anteil über 1'           => ['delivery_baseload_share', 1.5],
            'Klassen als Liste'       => ['efficiency_class_thresholds', [30, 50]],
            'Klasse unbekannt'        => ['efficiency_class_thresholds', ['Z' => 10]],
        ];
    }

    #[DataProvider('rejected')]
    public function testAValueOfTheWrongTypeOrOutsideIsRejectedAndNothingStored(string $key, mixed $value): void
    {
        $before = $this->settings->all();
        try {
            $this->settings->set([$key => $value]);
            self::fail("$key: " . json_encode($value) . ' wurde angenommen');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($before[$key], $this->settings->get($key), 'nichts gespeichert');
    }

    /** Jede Zahlen-Einstellung hat eine Grenze: ±10³⁰⁸ ist nie eine Angabe (und nie ein 500). */
    public function testEveryNumberSettingHasALimit(): void
    {
        $defaults = (new \ReflectionClassConstant(SettingsService::class, 'DEFAULTS'))->getValue();
        $numeric = array_keys(array_filter($defaults, fn($v) => is_int($v) || is_float($v)));
        self::assertGreaterThan(30, count($numeric));
        foreach ($numeric as $k) {
            foreach ([1e308, -1e308] as $huge) {
                try {
                    $this->settings->set([$k => $huge]);
                    self::fail("$k: $huge angenommen");
                } catch (\InvalidArgumentException) {
                }
            }
            self::assertSame($defaults[$k], $this->settings->get($k), $k);
        }
    }

    public function testValidValuesStillWork(): void
    {
        $s = $this->settings->set([
            'forecast_model' => 'robust', 'segmented_split_mode' => 'fixed', 'gebaeudetyp' => 'rh',
            'active_utilities' => ['gas', 'strom', 'gas'], 'location_name' => 'Musterstadt', 'env_name' => 'TEST',
            'hdd_base_temp' => '14,5', 'latitude' => -33.9, 'longitude' => 151.2, 'dashboard_months' => 24,
            'forecast_months' => '18', 'ocr_timeout_s' => 60, 'delivery_baseload_share' => 0.2, 'baujahr' => '1975',
            'efficiency_class_thresholds' => ['A+' => 25, 'A' => 45.5],
        ]);
        self::assertSame('robust', $s['forecast_model']);
        self::assertSame(['gas', 'strom'], $s['active_utilities'], 'doppelte Art einmal');
        self::assertSame(14.5, $s['hdd_base_temp'], 'Dezimalkomma');
        self::assertSame(-33.9, $s['latitude']);
        self::assertSame(18, $s['forecast_months']);
        self::assertSame(60, $s['ocr_timeout_s']);
        self::assertSame(1975, $s['baujahr']);
        self::assertSame(45.5, $s['efficiency_class_thresholds']['A']);
        // eine Zahl in einem Textfeld ist Text (wie bisher)
        self::assertSame('12345', $this->settings->set(['location_name' => 12345])['location_name']);
    }

    /** Ein Schalter, der in älteren Daten als null oder Text steht, liest sich wie die Vorgabe bzw. nach Input::bool. */
    public function testStoredSwitchesFromOlderDataReadAsBooleans(): void
    {
        $this->store->write('settings.json', ['weather_auto_fill' => null, 'update_check' => 'false', 'support_hint' => 'nein', 'home_tls_insecure' => 'vielleicht']);
        $s = $this->settings->all();
        self::assertTrue($s['weather_auto_fill'], 'null = Vorgabe (an)');
        self::assertFalse($s['update_check'], '„false“ ist aus');
        self::assertFalse($s['support_hint']);
        self::assertFalse($s['home_tls_insecure'], 'Unlesbares = Vorgabe (aus)');
    }
}
