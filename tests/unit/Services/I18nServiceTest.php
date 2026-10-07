<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * N1007 / v2.0.0 — Backend-Lokalisierung. Testet gegen die ECHTEN
 * Sprachkataloge unter public/locales/, sodass der Test zugleich deren
 * Vorhandensein und Grundstruktur absichert.
 */
#[CoversClass(I18nService::class)]
final class I18nServiceTest extends TestCase
{
    private string $dataDir;
    private I18nService $i18n;
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = sys_get_temp_dir() . '/et-i18n-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);
        $this->dataDir = realpath($tmp) ?: $tmp;

        $store = new JsonStore($this->dataDir);
        $this->settings = new SettingsService($store);
        $localeDir = dirname(__DIR__, 3) . '/public/locales';
        $this->i18n = new I18nService($localeDir, $this->settings);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    public function testReturnsGermanByDefault(): void
    {
        self::assertSame('de', $this->i18n->locale());
        self::assertSame('Speichern', $this->i18n->t('common.save'));
    }

    public function testReturnsEnglishWhenLocaleSet(): void
    {
        $this->i18n->setLocale('en');
        self::assertSame('en', $this->i18n->locale());
        self::assertSame('Save', $this->i18n->t('common.save'));
        self::assertSame('Overview', $this->i18n->t('nav.dashboard'));   // v2.11.0: Menü = Seitentitel
    }

    public function testUnknownKeyReturnsKeyItself(): void
    {
        self::assertSame('does.not.exist', $this->i18n->t('does.not.exist'));
    }

    /**
     * v3.1.0 (Review I18N-18) — mit eigenem Katalog statt der echten: Bis v3.0
     * prüfte dieser Test laut eigenem Kommentar keinen Rückfall, sondern nur,
     * dass ein vorhandener englischer Schlüssel nicht roh erscheint.
     */
    private function fixtureI18n(): I18nService
    {
        $dir = $this->dataDir . '/locales';
        @mkdir($dir, 0755, true);
        file_put_contents("$dir/languages.json", json_encode(['de' => 'Deutsch', 'en' => 'English']));
        file_put_contents("$dir/de.json", json_encode(['x' => ['only' => 'Nur deutsch', 'p' => 'Hallo {name}, {n} € kostet {name}']]));
        file_put_contents("$dir/en.json", json_encode(['y' => 'English only']));
        return new I18nService($dir, $this->settings);
    }

    public function testFallsBackToGermanWhenKeyMissingInEnglish(): void
    {
        $i18n = $this->fixtureI18n();
        $i18n->setLocale('en');
        self::assertSame('Nur deutsch', $i18n->t('x.only'), 'fehlt in en → de');
        self::assertSame('English only', $i18n->t('y'));
        self::assertSame('x.missing', $i18n->t('x.missing'), 'fehlt überall → Schlüssel');
        foreach (glob($this->dataDir . '/locales/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dataDir . '/locales');
    }

    public function testParamInterpolation(): void
    {
        $i18n = $this->fixtureI18n();
        // jeder Platzhalter, auch mehrfach; `$`-Folgen bleiben wörtlich
        self::assertSame('Hallo A$$B, 3 € kostet A$$B', $i18n->t('x.p', ['name' => 'A$$B', 'n' => 3]));
        self::assertSame('Hallo {name}, 1 € kostet {name}', $i18n->t('x.p', ['n' => 1]), 'ohne Wert bleibt der Platzhalter stehen');
        foreach (glob($this->dataDir . '/locales/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dataDir . '/locales');
    }

    public function testNegotiateAcceptLanguage(): void
    {
        self::assertSame('en', $this->i18n->negotiate('en-GB,en;q=0.9,de;q=0.5'));
        self::assertSame('de', $this->i18n->negotiate('de-DE,de;q=0.9'));
        // Höchster q gewinnt, auch wenn später gelistet — unsupported (ja) wird
        // übersprungen, en (0.8) schlägt de (0.3).
        self::assertSame('en', $this->i18n->negotiate('ja-JP,de;q=0.3,en;q=0.8'));
        // Eine in languages.json registrierte Sprache (fr) mit höchstem q gewinnt.
        self::assertSame('fr', $this->i18n->negotiate('fr-FR,en;q=0.8'));
        // Keine unterstützte Sprache → null (Aufrufer behält Setting/Default).
        self::assertNull($this->i18n->negotiate('ja-JP,zh;q=0.8'));
        self::assertNull($this->i18n->negotiate(null));
        self::assertNull($this->i18n->negotiate(''));
    }

    public function testLocaleFollowsLanguageSetting(): void
    {
        $this->settings->set(['language' => 'en']);
        // Frischer Service liest das Setting beim ersten locale()-Aufruf.
        $fresh = new I18nService(dirname(__DIR__, 3) . '/public/locales', $this->settings);
        self::assertSame('en', $fresh->locale());
        self::assertSame('Settings', $fresh->t('nav.settings'));
    }

    /**
     * v2.2.0 — Die beiden Helfer lösen Verbrauchsart-Namen und Default-
     * Zählernamen zentral auf. Vorher trug jeder Konsument seine eigene Kopie
     * (BenchmarkService und ReadingService hatten gar keine, weshalb dort
     * deutsche Namen in die übersetzte Oberfläche durchschlugen).
     */
    public function testUtilityLabelAndMeterNameFollowTheLocale(): void
    {
        $this->i18n->setLocale('en');
        self::assertSame('District heating', $this->i18n->utilityLabel('fernwaerme'));
        self::assertSame('Main meter', $this->i18n->defaultMeterName('strom'));
        self::assertSame('Oil tank', $this->i18n->defaultMeterName('heizoel'));

        $this->i18n->setLocale('de');
        self::assertSame('Fernwärme', $this->i18n->utilityLabel('fernwaerme'));
        self::assertSame('Hauptzähler', $this->i18n->defaultMeterName('strom'));
    }

    /** Unbekannte Schlüssel fallen sauber auf die SSOT zurück, statt zu werfen. */
    public function testHelpersFallBackForUnknownUtility(): void
    {
        self::assertSame('nichtvorhanden', $this->i18n->utilityLabel('nichtvorhanden'));
        self::assertSame('nichtvorhanden', $this->i18n->defaultMeterName('nichtvorhanden'));
    }

    /**
     * Jede Verbrauchsart der SSOT braucht in JEDER Sprache einen Namen und
     * einen Default-Zählernamen — genau die Vollständigkeitsprüfung, deren
     * Fehlen die deutschen Reste in fünf Sprachen überleben ließ.
     */
    public function testEveryUtilityHasNamesInEveryLanguage(): void
    {
        foreach ($this->i18n->supported() as $lang) {
            foreach (\Energietracker\Config\Utilities::keys() as $key) {
                self::assertNotSame(
                    "utilityNames.$key", $this->i18n->t("utilityNames.$key", [], $lang),
                    "utilityNames.$key fehlt in $lang"
                );
                self::assertNotSame(
                    "meterNames.$key", $this->i18n->t("meterNames.$key", [], $lang),
                    "meterNames.$key fehlt in $lang"
                );
            }
        }
    }
}
