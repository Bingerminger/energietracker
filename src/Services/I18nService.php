<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * N1007 / v2.0.0 — Lokalisierung (Full-Stack-Anteil Backend).
 *
 * Liest dieselben JSON-Sprachkataloge wie das Frontend
 * (`public/locales/<lang>.json`) und löst Übersetzungs-Keys in Punkt-Notation
 * auf (`t('errors.not_found')`). Single source of truth: ein Katalog je Sprache,
 * von Backend und Frontend gemeinsam genutzt.
 *
 * Locale-Auflösung (Vorrang absteigend):
 *   1. explizit per `setLocale()` / `t($key, …, $locale)`,
 *   2. `Accept-Language`-Header des Requests (per `negotiate()`),
 *   3. `language`-Setting aus `settings.json`,
 *   4. Default `de`.
 *
 * Fehlt ein Key in der Ziel-Sprache, greift der Default-Katalog (de); fehlt er
 * auch dort, wird der Key selbst zurückgegeben (im Dev sofort sichtbar).
 */
final class I18nService
{
    public const DEFAULT_LOCALE = 'de';
    /** Minimal-Fallback, falls languages.json fehlt. */
    private const FALLBACK_SUPPORTED = ['de', 'en'];

    private string $localeDir;
    private ?string $locale = null;
    /** @var string[]|null lazy-geladene Liste unterstützter Sprachen */
    private ?array $supportedCache = null;

    /** @var array<string,array<string,mixed>> catalog cache per locale */
    private array $catalogs = [];

    public function __construct(string $localeDir, private SettingsService $settings)
    {
        $this->localeDir = rtrim($localeDir, '/');
    }

    /**
     * Unterstützte Sprachen — datengetrieben aus public/locales/languages.json
     * (Schlüssel der Registry). Fällt auf de/en zurück, wenn die Datei fehlt.
     * @return string[]
     */
    public function supported(): array
    {
        if ($this->supportedCache !== null) {
            return $this->supportedCache;
        }
        $codes = self::FALLBACK_SUPPORTED;
        $file = $this->localeDir . '/languages.json';
        if (is_file($file)) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && $data !== []) {
                $codes = array_keys($data);
            }
        }
        return $this->supportedCache = $codes;
    }

    /** Setzt die aktive Sprache; unbekannte Werte werden ignoriert. */
    public function setLocale(?string $locale): void
    {
        $norm = $this->normalize($locale);
        if ($norm !== null) {
            $this->locale = $norm;
        }
    }

    /** Aktive Sprache; fällt auf das `language`-Setting bzw. den Default zurück. */
    public function locale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }
        $fromSetting = $this->normalize((string)$this->settings->get('language', self::DEFAULT_LOCALE));
        return $this->locale = $fromSetting ?? self::DEFAULT_LOCALE;
    }

    /**
     * Wählt aus einem `Accept-Language`-Header die beste unterstützte Sprache.
     * Gibt `null` zurück, wenn keine passt (Aufrufer behält dann den Vorrang
     * aus Setting/Default).
     */
    public function negotiate(?string $acceptLanguage): ?string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return null;
        }
        // Liste "de-DE,de;q=0.9,en;q=0.8" → nach q-Wert sortiert auswerten.
        $candidates = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $q = 1.0;
            if (preg_match('/;\s*q\s*=\s*([0-9.]+)/i', $part, $m)) {
                $q = (float)$m[1];
            }
            $tag = strtolower(trim(preg_split('/;/', $part)[0]));
            if ($tag === '') continue;
            $candidates[] = [$tag, $q];
        }
        usort($candidates, static fn($a, $b) => $b[1] <=> $a[1]);
        foreach ($candidates as [$tag, $_q]) {
            $norm = $this->normalize($tag);
            if ($norm !== null) {
                return $norm;
            }
        }
        return null;
    }

    /**
     * Übersetzt einen Punkt-Key. Platzhalter `{name}` werden aus `$params`
     * ersetzt.
     *
     * @param array<string,scalar> $params
     */
    public function t(string $key, array $params = [], ?string $locale = null): string
    {
        $loc = $this->normalize($locale) ?? $this->locale();

        $value = $this->lookup($loc, $key);
        if ($value === null && $loc !== self::DEFAULT_LOCALE) {
            $value = $this->lookup(self::DEFAULT_LOCALE, $key);
        }
        if ($value === null) {
            return $key;
        }
        foreach ($params as $k => $v) {
            $value = str_replace('{' . $k . '}', (string)$v, $value);
        }
        return $value;
    }

    /**
     * v2.2.0 — Lokalisierter Name einer Verbrauchsart, Fallback auf das
     * deutsche Label aus der Utilities-SSOT.
     *
     * Der Übersetzungsschritt lag bisher als Ad-hoc-Zeile in
     * UtilitiesController, PdfReportService und RecommendationService — und
     * fehlte in BenchmarkService und ReadingService, weshalb dort deutsche
     * Namen in eine ansonsten übersetzte Oberfläche durchschlugen. Eine
     * gemeinsame Methode kann nicht an einer Stelle vergessen werden.
     */
    public function utilityLabel(string $utility): string
    {
        $key = 'utilityNames.' . $utility;
        $name = $this->t($key);
        if ($name !== $key) {
            return $name;
        }
        $def = \Energietracker\Config\Utilities::exists($utility)
            ? \Energietracker\Config\Utilities::get($utility)
            : [];
        return (string)($def['label'] ?? $utility);
    }

    /**
     * v2.2.0 — Lokalisierter Default-Zählername („Hauptzähler", „Heizöltank" …).
     *
     * Wird beim Anlegen einmal in die Daten geschrieben und danach nicht mehr
     * nachgeführt — der Name gehört ab dann dem Nutzer. Entscheidend ist also
     * nur, dass eine Frischinstallation in der eingestellten Sprache startet.
     */
    public function defaultMeterName(string $utility): string
    {
        $key = 'meterNames.' . $utility;
        $name = $this->t($key);
        if ($name !== $key) {
            return $name;
        }
        $def = \Energietracker\Config\Utilities::exists($utility)
            ? \Energietracker\Config\Utilities::get($utility)
            : [];
        return (string)($def['default_meter_name'] ?? $utility);
    }

    private function lookup(string $locale, string $key): ?string
    {
        $node = $this->catalog($locale);
        foreach (explode('.', $key) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                return null;
            }
        }
        return is_string($node) ? $node : null;
    }

    /** @return array<string,mixed> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }
        $file = $this->localeDir . '/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return $this->catalogs[$locale] = is_array($data) ? $data : [];
    }

    private function normalize(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }
        $loc = strtolower(substr($locale, 0, 2));
        return in_array($loc, $this->supported(), true) ? $loc : null;
    }
}
