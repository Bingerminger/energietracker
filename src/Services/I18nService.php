<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;

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
 *   2. `language`-Setting aus `settings.json` (App::handle() setzt es je Anfrage),
 *   3. `Accept-Language`-Header — nur ohne gültiges Setting und beim Erststart,
 *   4. Default `de`.
 * (Bis v2.6.0 stand hier die umgekehrte Reihenfolge von 2 und 3.)
 *
 * Fehlt ein Key in der Ziel-Sprache, greift der Default-Katalog (de); fehlt er
 * auch dort, wird der Key selbst zurückgegeben (im Dev sofort sichtbar).
 *
 * v2.7.0 — Länderprofil: Die Kataloge schreiben Währungen als Platzhalter
 * ({cur} Symbol, {minor} Untereinheit, {code} ISO-Code); t() setzt sie aus
 * der Einstellung `currency` ein. Dazu Zahl, Betrag, Datum und Monat in der
 * Schreibweise von Sprache und Land (number(), money(), date(), month()) —
 * bis v2.6.0 formatierte das Backend fest deutsch, und im englischen
 * PDF-Bericht las man „10.359 kWh" als rund zehn Kilowattstunden.
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

    /**
     * v2.6.0 — gerenderte Fehlermeldung → Katalogschlüssel dieser Anfrage.
     *
     * Fehlerantworten tragen seit v2.6.0 einen stabilen `code`, damit Skripte
     * und Home-Assistant-Automationen sprachunabhängig reagieren können. Die
     * rund 115 Wurfstellen übergeben ihre Meldung als fertigen Text; statt jede
     * umzubauen, merkt sich t() hier, aus welchem `errors.*`-Schlüssel ein Text
     * entstand, und der Fehlerpfad schlägt ihn nach (errorCodeFor()).
     *
     * @var array<string,string>
     */
    private array $errorKeys = [];

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
        if (str_contains($value, '{')) {
            // explizite Parameter haben Vorrang vor den Währungs-Platzhaltern
            foreach ($params + $this->currencyParams() as $k => $v) {
                $value = str_replace('{' . $k . '}', (string)$v, $value);
            }
        }
        if (str_starts_with($key, 'errors.')) {
            $this->errorKeys[$value] = $key;
        }
        return $value;
    }

    /**
     * Stabiler Fehlercode (Katalogschlüssel) zu einer Meldung, die in dieser
     * Anfrage über t() entstand. Wurfstellen hängen manchmal etwas an
     * („… #3"); dann gilt der längste bekannte Anfang.
     */
    public function errorCodeFor(string $message): ?string
    {
        if (isset($this->errorKeys[$message])) return $this->errorKeys[$message];
        $best = null; $bestLen = 0;
        foreach ($this->errorKeys as $text => $key) {
            $len = strlen($text);
            if ($len > $bestLen && $len >= 8 && str_starts_with($message, $text)) {
                $best = $key; $bestLen = $len;
            }
        }
        return $best;
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

    // ── v2.7.0 — Schreibweise nach Sprache und Land (I18N-02) ─────────────

    /**
     * Währungs-Platzhalter aus der Einstellung `currency`.
     *
     * @return array{cur:string, minor:string, code:string}
     */
    public function currencyParams(): array
    {
        $code = (string)$this->settings->get('currency', 'EUR');
        if (!isset(Countries::CURRENCIES[$code])) $code = 'EUR';
        $c = Countries::currency($code);
        return ['cur' => $c['symbol'], 'minor' => $c['minor'], 'code' => $code];
    }

    /** Zahl mit landesüblichen Trennzeichen (Katalog `format.*`, Länder-Ausnahmen in Countries). */
    public function number(float $value, int $decimals = 0): string
    {
        [$decimal, $group] = $this->separators();
        return number_format($value, $decimals, $decimal, $group);
    }

    /** Betrag in der Währung der Einstellung („1.234,56 €", „£1,234.56", „CHF 1’234.56"). */
    public function money(float $value, int $decimals = 2): string
    {
        // Beträge trennen in manchen Ländern anders als Zahlen (Intl: de-AT
        // „€ 1.234,56" neben „1 234,5"; fr-CH „1'234.56 CHF" neben „1'234,5").
        $o = $this->formatOverride();
        [$decimal, $group] = $this->separators();
        $amount = number_format(abs($value), $decimals, $o['money_decimal'] ?? $decimal, $o['money_group'] ?? $group);
        $pattern = $o['money'] ?? $this->t('format.money');
        if (!str_contains($pattern, '{amount}')) $pattern = '{amount} {cur}';
        $out = str_replace(['{amount}', '{cur}'], [$amount, $this->currencyParams()['cur']], $pattern);
        return $value < 0 ? '-' . $out : $out;
    }

    /** Datum (ISO oder beliebig von strtotime lesbar) im Muster des Katalogs `format.date`. */
    public function date(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) return $value;
        $pattern = $this->formatOverride()['date'] ?? $this->t('format.date');
        return date($pattern === 'format.date' ? 'd.m.Y' : $pattern, $ts);
    }

    /** Monat „2025-01" als „Jan. 2025" (Kurznamen aus `format.monthsShort`). */
    public function month(string $ym): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
        $names = array_map('trim', explode(',', $this->t('format.monthsShort')));
        $i = (int)$m[2] - 1;
        return count($names) === 12 && isset($names[$i]) ? $names[$i] . ' ' . $m[1] : $ym;
    }

    /** @return array{0:string,1:string} Dezimal- und Tausendertrenner */
    private function separators(): array
    {
        $o = $this->formatOverride();
        $decimal = $o['decimal'] ?? $this->t('format.decimal');
        $group   = $o['group']   ?? $this->t('format.group');
        // fehlender Katalogschlüssel → deutsche Schreibweise statt des Schlüsselnamens
        return [$decimal === 'format.decimal' ? ',' : $decimal, $group === 'format.group' ? '.' : $group];
    }

    /** @return array<string,string> */
    private function formatOverride(): array
    {
        return Countries::formatOverride((string)$this->settings->get('country', Countries::DEFAULT), $this->locale());
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
