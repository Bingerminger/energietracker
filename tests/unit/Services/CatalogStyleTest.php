<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 — Stilprüfungen der Sprachkataloge (Paket G).
 *
 * `LocaleCatalogTest` prüft die Struktur (gleiche Schlüssel, gleiche
 * Platzhalter, Pluralformen). Hier geht es um den Inhalt: dieselbe Sache heißt
 * überall gleich, die Anrede bleibt in einer Sprache dieselbe, Kürzel passen
 * zur Sprache. Jede dieser Regeln war schon einmal gebrochen, ohne dass ein
 * Test es bemerkt hätte — die Stilregeln selbst stehen in
 * docs/entwicklung/uebersetzen.md.
 */
final class CatalogStyleTest extends TestCase
{
    /** HTML-Tags, die Kataloge benutzen — „<token>" in einem Beispiel ist keiner. */
    private const TAG = '/<\/?(?:a|b|br|code|em|i|kbd|li|ol|p|small|span|strong|sub|sup|ul)\b[^>]*>/u';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] */
    private static function languages(): array
    {
        $out = [];
        foreach (glob(self::root() . '/public/locales/*.json') ?: [] as $f) {
            $name = basename($f, '.json');
            if ($name !== 'languages') $out[] = $name;
        }
        sort($out);
        return $out;
    }

    /** @var array<string,array<string,string>> */
    private static array $flat = [];

    /** @return array<string,string> flache Punkt-Notation */
    private static function catalog(string $lang): array
    {
        if (!isset(self::$flat[$lang])) {
            $data = json_decode((string)file_get_contents(self::root() . "/public/locales/$lang.json"), true);
            self::assertIsArray($data, "$lang.json ist kein gültiges JSON");
            self::$flat[$lang] = self::flatten($data);
        }
        return self::$flat[$lang];
    }

    /** @return array<string,string> */
    private static function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            $key = $prefix === '' ? (string)$k : "$prefix.$k";
            if (is_array($v)) $out += self::flatten($v, $key);
            else $out[$key] = (string)$v;
        }
        return $out;
    }

    /** @param string[] $patterns Schlüssel oder Präfixe mit `*` am Ende */
    private static function keyMatches(string $key, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (str_ends_with($p, '*') ? str_starts_with($key, substr($p, 0, -1)) : $key === $p) return true;
        }
        return false;
    }

    /** Text ohne Platzhalter und HTML — was der Nutzer liest. */
    private static function plain(string $value): string
    {
        return (string)preg_replace(['/\{[^}]*\}/u', self::TAG], ' ', $value);
    }

    /**
     * I18N-06: Ein Rechnungsposten heißt in Vertragsmaske, Tabelle und
     * Tarifvergleich gleich. Vor v3.1.0 hatte der Arbeitspreis in fünf Sprachen
     * je zwei bis drei Namen, und das Glossar führte für die Zustandszahl auf
     * Französisch den Begriff, der bei GRDF den Gesamtfaktor meint.
     */
    public function testTermbase(): void
    {
        $base = json_decode((string)file_get_contents(self::root() . '/tests/fixtures/termbase.json'), true);
        self::assertIsArray($base['terms'] ?? null, 'tests/fixtures/termbase.json fehlt oder ist kaputt');
        $langs = self::languages();
        $hits = [];
        foreach ($base['terms'] as $term) {
            $allow = $term['allowKeys'] ?? [];
            foreach ($term['avoid'] ?? [] as $lang => $phrases) {
                self::assertContains($lang, $langs, "Termbase {$term['id']}: unbekannte Sprache $lang");
                foreach (self::catalog($lang) as $key => $value) {
                    if (self::keyMatches($key, $allow)) continue;
                    foreach ($phrases as $phrase) {
                        // Wortanfang zählt, das Ende nicht: Plurale werden mitgefangen,
                        // „préréglages" aber nicht von „réglages".
                        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '/iu', $value)) {
                            $hits[] = "$lang $key: „{$phrase}“ statt „{$term['use'][$lang]}“";
                        }
                    }
                }
            }
            if (($term['glossary'] ?? null) !== null) {
                foreach ($term['use'] as $lang => $use) {
                    $glossary = self::catalog($lang)["glossary.{$term['glossary']}.term"] ?? null;
                    self::assertNotNull($glossary, "glossary.{$term['glossary']}.term fehlt in $lang");
                    if (mb_stripos($glossary, $use) === false) {
                        $hits[] = "$lang glossary.{$term['glossary']}.term: „{$glossary}“ enthält nicht „{$use}“";
                    }
                }
            }
        }
        self::assertSame([], $hits, "Abweichungen von der Termbase:\n" . implode("\n", $hits));
    }

    /**
     * I18N-15: Eine Ansicht spricht den Nutzer nicht erst mit „du" und dann mit
     * „Sie" an. Festgelegt: de du, en you, fr vous, it tu, es tú, pt Sie-Form
     * ohne Pronomen (3. Person), nl je. Die Muster suchen die jeweils andere Form.
     */
    public function testAddressFormPerLanguage(): void
    {
        $patterns = [
            'de' => '/(?<![.!?:]\s|^|„|\()\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihnen)\b/u',
            'fr' => '/(?<!\p{L})(tu|ton|ta|tes|toi)(?!\p{L})|saisis-|(?:^|[.!?]\s)(Vérifie|Saisis|Ajoute|Choisis|Clique|Corrige)\b/u',
            'es' => '/(?<!\p{L})(usted|ustedes)(?!\p{L})|(?:^|[.!?]\s+)(Introduzca|Indique|Busque|Seleccione|Elija|Añada|Guarde|Compruebe|Pulse)\b|\bdebe (introducir|indicar|seleccionar)\b/u',
            'it' => '/(?<!\p{L})(Lei|Sua|Suo|Suoi|Sue)(?!\p{L})|(?:^|[.!?]\s+)(Inserisca|Selezioni|Scelga|Imposti|Aggiunga|Verifichi)\b/u',
            'pt' => '/(?<!\p{L})(tu|teu|tua|teus|tuas|você|vocês)(?!\p{L})/u',
            'nl' => '/(?<![\p{L}{])(u|uw)(?![\p{L}}])/u',
        ];
        $hits = [];
        foreach ($patterns as $lang => $re) {
            foreach (self::catalog($lang) as $key => $value) {
                if (preg_match($re, self::plain($value), $m)) $hits[] = "$lang $key: „{$m[0]}“";
            }
        }
        self::assertSame([], $hits, "Anrede weicht ab:\n" . implode("\n", $hits));
    }

    /**
     * I18N-19: Der deutsche Katalog ist die Vorlage aller Übersetzungen. Keine
     * ASCII-Anführungszeichen (außer in Code und HTML-Attributen), keine
     * englischen Restwörter und Klassennamen, Datumsformat JJJJ-MM-TT,
     * Auslassungspunkte nach Duden mit Leerzeichen („Lädt …").
     */
    public function testGermanCanon(): void
    {
        $hits = [];
        foreach (self::catalog('de') as $key => $value) {
            $text = (string)preg_replace(['/<code>.*?<\/code>/u', self::TAG], ' ', $value);
            if (str_contains($text, '"')) $hits[] = "$key: ASCII-Anführungszeichen";
            if (preg_match('/\b(Utility|Device|Settings|Forecast|What-if|Fit|ReadingService|Sync)\b|is_planned=/u', $text, $m)) {
                $hits[] = "$key: „{$m[0]}“";
            }
            if (preg_match('/\b(YYYY|DD|MM-DD)\b/u', $text, $m)) $hits[] = "$key: Datumsformat „{$m[0]}“ statt JJJJ-MM-TT";
            if (preg_match('/\p{L}…/u', $text)) $hits[] = "$key: „…“ ohne Leerzeichen davor";
        }
        self::assertSame([], $hits, "Deutscher Kanon:\n" . implode("\n", $hits));
    }

    /**
     * I18N-20: Anführungszeichen, Apostrophe, Prozent und Rechtschreibung je
     * Sprache. Vor v3.1.0 standen in englischen Meldungen deutsche „…“, im
     * Niederländischen drei Formen nebeneinander, im Portugiesischen Schreibungen
     * von vor der Reform 1990. Geschützte Leerzeichen (fr) setzt die Ausgabe —
     * siehe I18nService::typography().
     */
    public function testTypographyPerLanguage(): void
    {
        // Zeichen, die in der Sprache nicht vorkommen dürfen
        $forbidden = [
            'de' => '/[”«»]/u',
            'en' => '/[„«»]/u',
            'fr' => '/[„“”]/u',
            'it' => '/[„“”]|«\s|\s»/u',
            'es' => '/[„“”]|«\s|\s»/u',
            'pt' => '/[„“”]|«\s|\s»/u',
            'nl' => '/[„«»]/u',
        ];
        // Prozent ohne Leerzeichen, wie CLDR und Intl es schreiben
        $tightPercent = ['en', 'it', 'pt', 'nl'];
        $hits = [];
        foreach ($forbidden as $lang => $re) {
            foreach (self::catalog($lang) as $key => $value) {
                if (str_starts_with($key, 'csv.')) continue;   // Format 1, eingefroren
                $text = (string)preg_replace(['/<code>.*?<\/code>/u', self::TAG], ' ', $value);
                if (preg_match($re, $text, $m)) $hits[] = "$lang $key: „{$m[0]}“";
                if (str_contains($text, '"')) $hits[] = "$lang $key: ASCII-Anführungszeichen";
                if (in_array($lang, ['fr', 'it'], true) && preg_match("/\\p{L}'\\p{L}/u", $text)) {
                    $hits[] = "$lang $key: gerader Apostroph";
                }
                if (in_array($lang, $tightPercent, true) && preg_match('/[\d}] %/u', $text)) {
                    $hits[] = "$lang $key: „ %“ statt „%“";
                }
            }
        }
        // Portugiesisch nach dem Orthographieabkommen 1990
        foreach (self::catalog('pt') as $key => $value) {
            if (preg_match('/(?<!\p{L})(exact|factur|seleccion|activ|direcç|acç[ãõ]|óptim|correcç|colecç|projecç|efectiv)/iu', $value, $m)) {
                $hits[] = "pt $key: „{$m[0]}…“ (vor AO1990)";
            }
        }
        self::assertSame([], $hits, "Typografie:\n" . implode("\n", $hits));
    }

    /** I18N-20: Französisch schützt Leerzeichen bei der Ausgabe, CSV-Format 1 bleibt unberührt. */
    public function testFrenchTypographyAtOutput(): void
    {
        self::assertSame("Total\u{00A0}: 12\u{00A0}%", I18nService::typography('fr', 'Total : 12 %'));
        self::assertSame("«\u{00A0}Gaz\u{00A0}» ?", str_replace("\u{202F}?", ' ?', I18nService::typography('fr', '« Gaz » ?')));
        self::assertSame("Vraiment\u{202F}!", I18nService::typography('fr', 'Vraiment !'));
        self::assertSame("12\u{00A0}%", I18nService::typography('none', '12 %'));
        self::assertSame('a : b', I18nService::typography('none', 'a : b'));
        foreach (self::languages() as $lang) {
            self::assertArrayHasKey('format.typography', self::catalog($lang), "$lang: format.typography fehlt");
        }
    }

    /**
     * I18N-21: Eingesetzte Bezeichnungen („Heizöl", „Dringend") sind groß
     * geschrieben und tragen keinen Artikel. Mitten im Satz ergaben sie „Keine
     * Dringend-Empfehlungen", „Planifier la prochaine livraison de Fioul",
     * „Plan next Heating oil delivery". Außer im Deutschen steht ein Platzhalter
     * für eine Bezeichnung deshalb nur am Anfang, nach „:", „—", „(" oder
     * einem öffnenden Anführungszeichen.
     */
    public function testLabelsNotMidSentence(): void
    {
        $hits = [];
        foreach (['en', 'fr', 'it', 'es', 'pt', 'nl'] as $lang) {
            foreach (self::catalog($lang) as $key => $value) {
                if (str_starts_with($key, 'csv.')) continue;
                if (!preg_match_all('/\{(label|utility|sev|stage)\}/u', $value, $all, PREG_OFFSET_CAPTURE)) continue;
                foreach ($all[0] as [$ph, $pos]) {
                    $before = substr($value, 0, $pos);
                    if (!preg_match('/(^|[:—(·|\/]\s*|[.!?]\s+|[«“„‘]\s*)$/u', $before)) {
                        $hits[] = "$lang $key: $ph mitten im Satz";
                    }
                }
            }
        }
        self::assertSame([], $hits, "Bezeichnung mitten im Satz:\n" . implode("\n", $hits));
    }

    /**
     * I18N-09: Ein Zähl-Platzhalter steht in einer Pluralgruppe (`.one`/`.other`),
     * sonst stand „vor 1 Tagen" da. Ausnahmen haben eine Form, die für jede Zahl
     * passt: Klammer oder Doppelpunkt („Alle (3)", „Probleme: 3"), Ordnungszahl
     * („#3", „Gerät 2"), Bereich mit fester Obergrenze („14 von 31 Tagen"),
     * Abkürzung („6 Mon.") oder ein Wert, der nie 1 ist.
     */
    public function testCountPlaceholdersLiveInPluralGroups(): void
    {
        $allow = [
            'dashboard.efficiency.certificateTitle', // „vorhanden: {months}"
            'dashboard.chart.totalTitle',            // fester Zeitraum von 12 Monaten
            'dashboard.chart.totalAlt',              // dito
            'dashboard.card.partial',                // „{days} von {total} Tagen"
            'chart.partialMonth',                    // dito
            'forecast.warn.historyShort',            // „{months} von 12 Kalendermonaten"
            'meters.card.digits',                    // Zählwerk mit mindestens 4 Stellen
            'meters.card.deviceLine',                // „Gerät 2" (Ordnungszahl)
            'meters.import.moreErrors',              // „… und 3 weitere"
            'tariff.projectedFull',                  // „6 Mon." (Abkürzung)
            'analysis.yoy.needTwo',                  // „aktuell 1."
            'analysis.spar.basis',                   // setzt fertige Pluralformen ein
            'analysis.baseline.points',              // Modell braucht mindestens 8 Punkte
        ];
        $hits = [];
        foreach (self::catalog('de') as $key => $value) {
            if (!preg_match('/\{(count|days|months)\}/u', $value)) continue;
            if (preg_match('/\.(one|few|many|other)$/', $key) || in_array($key, $allow, true)) continue;
            // Klammer- oder Doppelpunktform: „(…{count})", „: {count}"
            if (preg_match('/\(\{count\}\)|:\s\{count\}/u', $value)) continue;
            $hits[] = "$key: $value";
        }
        // Klammer-Plurale („Problem(e)", „problème(s)", „problema/i") in keiner Sprache
        foreach (self::languages() as $lang) {
            foreach (self::catalog($lang) as $key => $value) {
                if (preg_match('/\p{L}\((?:e|en|n|s|es|x|i)\)|\p{L}\/(?:i|e|s|en)\b/u', $value, $m)) $hits[] = "$lang $key: „{$m[0]}“";
            }
        }
        self::assertSame([], $hits, "Zählstring ohne Pluralgruppe (tp() benutzen):\n" . implode("\n", $hits));
    }

    /**
     * I18N-18: Was eine neue Sprache mitbringen muss. Eine Kopie von en.json als
     * „xx" in languages.json lässt genau diesen Test scheitern, bis Formate,
     * Pluralregel und Länderzuordnung stehen (Checkliste in
     * docs/entwicklung/uebersetzen.md).
     */
    public function testEveryLanguageIsComplete(): void
    {
        $registry = (array)json_decode((string)file_get_contents(self::root() . '/public/locales/languages.json'), true);
        $byLanguage = (new \ReflectionClassConstant(\Energietracker\Config\Countries::class, 'BY_LANGUAGE'))->getValue();
        foreach (array_keys($registry) as $lang) {
            self::assertFileExists(self::root() . "/public/locales/$lang.json", "$lang: Katalog fehlt");
            $c = self::catalog($lang);
            foreach (['decimal', 'group', 'date', 'monthsShort', 'money', 'pdfCharset', 'typography'] as $f) {
                self::assertArrayHasKey("format.$f", $c, "$lang: format.$f fehlt");
            }
            self::assertCount(12, explode(',', $c['format.monthsShort']), "$lang: zwölf Monatsnamen");
            self::assertArrayHasKey($lang, I18nService::PLURAL_RULES, "$lang: I18nService::PLURAL_RULES");
            self::assertArrayHasKey($lang, $byLanguage, "$lang: Countries::BY_LANGUAGE (Land für den Erststart)");
        }
    }

    /** I18N-10: Dateinamen der CSV-Exporte bleiben ASCII-Slugs in jeder Sprache. */
    public function testCsvFileSlugs(): void
    {
        foreach (self::languages() as $lang) {
            foreach (self::catalog($lang) as $key => $value) {
                if (str_starts_with($key, 'csvLocal.file.')) {
                    self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $value, "$lang $key");
                }
            }
        }
    }

    /**
     * I18N-08: Die Rechnungsprüfung kennzeichnet geschätzte und interpolierte
     * Stände so, wie die Rechnungen der Sprache es tun. Englisch las „E" als
     * „estimated", die App meinte „Ersatzwert".
     */
    public function testBillCheckMarksFollowLanguage(): void
    {
        $expected = [
            'de' => ['S', 'E'], 'en' => ['E', 'I'], 'fr' => ['E', 'I'], 'it' => ['S', 'I'],
            'es' => ['E', 'I'], 'pt' => ['E', 'I'], 'nl' => ['S', 'I'],
        ];
        foreach (self::languages() as $lang) {
            self::assertArrayHasKey($lang, $expected, "Kürzel der Rechnungsprüfung für $lang festlegen");
            $c = self::catalog($lang);
            [$est, $int] = $expected[$lang];
            self::assertSame($est, $c['utility.billCheck.mark.estimated'] ?? null, "$lang: Kürzel für geschätzt");
            self::assertSame($int, $c['utility.billCheck.mark.interpolated'] ?? null, "$lang: Kürzel für interpoliert");
            self::assertNotSame($est, $int, "$lang: beide Kürzel gleich");
            $legend = $c['utility.billCheck.legend'] ?? '';
            self::assertStringContainsString('{est}', $legend, "$lang: Legende setzt das Kürzel nicht ein");
            self::assertStringContainsString('{int}', $legend, "$lang: Legende setzt das Kürzel nicht ein");
        }
    }
}
