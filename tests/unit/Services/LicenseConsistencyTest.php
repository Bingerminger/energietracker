<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.0.0 — Die Lizenz steht an mehreren Stellen: Lizenzdatei, README-Abzeichen,
 * composer.json, Docker-Label, CONTRIBUTING und die Seite „System“ in sieben
 * Sprachen. Eine Aussage, die nur im Text steht, driftet (Lektion „Doku-Behauptungen
 * brauchen Tests“) — hier wird sie gegen die Wirklichkeit geprüft.
 */
final class LicenseConsistencyTest extends TestCase
{
    private const SPDX = 'AGPL-3.0-or-later';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    public function testLicenseFileIsTheAgplText(): void
    {
        $text = self::read('LICENSE');
        self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', $text);
        self::assertStringContainsString('Version 3, 19 November 2007', $text);
        // unveränderter Text, damit GitHub die Lizenz erkennt
        self::assertStringNotContainsString('MIT License', $text);
        self::assertGreaterThan(600, substr_count($text, "\n"), 'vollständiger Lizenztext erwartet');
    }

    public function testComposerAndDockerDeclareTheSameLicense(): void
    {
        $composer = json_decode(self::read('composer.json'), true);
        self::assertSame(self::SPDX, $composer['license'] ?? null, 'composer.json');
        self::assertStringContainsString(
            'org.opencontainers.image.licenses="' . self::SPDX . '"',
            self::read('Dockerfile'),
            'Dockerfile-Label'
        );
    }

    public function testReadmesShowTheLicenseAndTheMitHistory(): void
    {
        foreach (['README.md', 'README.de.md'] as $f) {
            $md = self::read($f);
            self::assertStringContainsString('AGPL--3.0--or--later', $md, "$f: Abzeichen");
            self::assertStringNotContainsString('License-MIT', $md, "$f: altes MIT-Abzeichen");
            self::assertMatchesRegularExpression('/2\.16\.0/', $md, "$f: MIT-Stand bis 2.16.0 genannt");
            self::assertStringContainsString('CREDITS.md', $md, "$f: Verweis auf Drittanbieter");
        }
        foreach (['CONTRIBUTING.md', 'CONTRIBUTING.de.md'] as $f) {
            self::assertStringContainsString(self::SPDX, self::read($f), "$f: Lizenz der Beiträge");
        }
    }

    /** Die Seite „System“ nennt die Lizenz in jeder Sprache und verlinkt den Quellcode (AGPL §13). */
    public function testSystemPageNamesTheLicenseInEveryLanguage(): void
    {
        foreach (glob(self::root() . '/public/locales/*.json') as $file) {
            $lang = basename($file, '.json');
            if ($lang === 'languages') continue; // Sprachliste, kein Katalog
            $cat = json_decode((string)file_get_contents($file), true);
            $license = $cat['settings']['system']['license'] ?? '';
            self::assertStringContainsString('AGPL', $license, "$lang: settings.system.license");
            self::assertStringNotContainsString('MIT', $license, "$lang: settings.system.license");
            self::assertNotSame('', $cat['settings']['system']['source'] ?? '', "$lang: settings.system.source");
        }
        $js = self::read('public/js/views/settings.js');
        self::assertStringContainsString('/tree/v${version}', $js, 'Link auf den Tag der laufenden Version');
    }
}
