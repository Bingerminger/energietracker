<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket K1, aus Paket F) — Jede Problemart, die der Backup-Import melden kann, hat einen Text im Katalog
 * (`settings.backup.problem.<art>`, alle sieben Sprachen). Bis v3.12 fehlte er für rund zwanzig Arten (`date_range`,
 * `bool`, `number` …), und der Dialog „Backup einspielen“ zeigte den Schlüssel.
 *
 * Die Arten kommen aus dem Quelltext von BackupService (jeder Aufruf `$problem(…)` bzw. `$bad(…)`): Eine neue Art ohne
 * Text macht diesen Test rot. Die Angabe hinter dem Doppelpunkt (Feld, Tag, Kennung) nennt der Text als `{field}` bzw.
 * `{what}`, wenn die Art in PROBLEM_ARG_IN_TEXT (public/js/lib/backup-problems.js) steht; sonst setzt der Dialog sie in
 * Klammern dahinter. Eine Art, die auch ohne Angabe vorkommt, darf deshalb keinen Platzhalter tragen.
 */
final class BackupProblemCatalogTest extends TestCase
{
    private const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Problemarten aus dem Quelltext: Art => [mit Angabe, ohne Angabe] (ob sie mit bzw. ohne `:…` vorkommt).
     *
     * @return array<string,array{0:bool,1:bool}>
     */
    public static function kindsFromSource(): array
    {
        $tokens = \PhpToken::tokenize((string)file_get_contents(self::root() . '/src/Services/BackupService.php'));
        $kinds = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!$t->is(T_VARIABLE) || !in_array($t->text, ['$problem', '$bad'], true)) continue;
            $j = $i + 1;
            while ($j < $n && $tokens[$j]->isIgnorable()) $j++;
            if ($j >= $n || $tokens[$j]->text !== '(') continue;   // Übergabe als Parameter, kein Aufruf
            // Argumente auf oberster Ebene: $problem(Topf, Zeile, Art, …), $bad(Zeile, Art)
            $want = $t->text === '$problem' ? 2 : 1;
            $arg = 0; $depth = 0; $first = null;
            for ($k = $j + 1; $k < $n; $k++) {
                $x = $tokens[$k];
                if (in_array($x->text, ['(', '[', '{'], true) || $x->is(T_CURLY_OPEN) || $x->is(T_DOLLAR_OPEN_CURLY_BRACES)) $depth++;
                elseif (in_array($x->text, [')', ']', '}'], true)) { if ($depth === 0) break; $depth--; }
                elseif ($x->text === ',' && $depth === 0) { $arg++; continue; }
                if ($arg === $want && $first === null && !$x->isIgnorable()) { $first = [$x, $tokens[$k + 1] ?? null]; break; }
            }
            self::assertNotNull($first, "Aufruf {$t->text} in Zeile {$t->line}: Art nicht gefunden");
            [$a, $b] = $first;
            // die Weiterleitung in `$bad = function (int $i, string $what) … { $problem($name, $i, $what); }`
            if ($a->is(T_VARIABLE) && $a->text === '$what') continue;
            // v3.13.0 (K2d) — Funde der gemeinsamen Regeln (Support\FieldRules): ihre Arten stehen in FieldRules::KINDS
            if ($a->is(T_VARIABLE) && $a->text === '$fieldRule') continue;
            if ($a->is(T_CONSTANT_ENCAPSED_STRING)) {
                $lit = substr($a->text, 1, -1);   // 'too_long:' . $p['field'] beginnt mit dem Literal
            } elseif ($a->text === '"' && $b !== null && $b->is(T_ENCAPSED_AND_WHITESPACE)) {
                $lit = $b->text;   // "date_range:$f"
            } else {
                self::fail("Aufruf {$t->text} in Zeile {$t->line}: die Art ist kein Literal ({$a->text})");
            }
            self::assertSame(1, preg_match('/^([a-z_0-9]+)(:?)/', $lit, $m), "Zeile {$t->line}: Art „{$lit}“");
            $kinds[$m[1]] ??= [false, false];
            $kinds[$m[1]][$m[2] === ':' ? 0 : 1] = true;
        }
        // v3.13.0 (K2d) — die Arten der gemeinsamen Regeln, immer mit Feld (`bool:is_shadow`, `missing:devices`)
        foreach ([...\Energietracker\Support\FieldRules::KINDS, 'missing'] as $k) {
            $kinds[$k] ??= [false, false];
            $kinds[$k][0] = true;
        }
        ksort($kinds);
        return $kinds;
    }

    /**
     * v3.13.0 (K2d) — Die API-Referenz nennt unter dem Backup-Import jede Art, die der Server melden kann (DE und EN,
     * Abschnitt `doc-claim: backup-problems`), und keine, die es nicht gibt. Bisher stand dort ein Teil der Arten.
     */
    public function testTheApiReferenceListsEveryKind(): void
    {
        $kinds = array_keys(self::kindsFromSource());
        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $rel) {
            $doc = (string)file_get_contents(self::root() . "/$rel");
            self::assertSame(1, preg_match('/<!-- doc-claim: backup-problems -->(.*?)<!-- \/doc-claim -->/s', $doc, $m), "$rel: Abschnitt fehlt");
            preg_match_all('/`([a-z_0-9]+)(?::[^`]*)?`/', $m[1], $mm);
            $named = array_values(array_unique($mm[1]));
            foreach ($kinds as $k) self::assertContains($k, $named, "$rel: Art `$k` fehlt");
            // genannte Felder und Töpfe, keine Arten
            $unknown = array_diff($named, $kinds, ['problem', 'false', 'field', 'max', 'attachments', 'attachment_files']);
            self::assertSame([], array_values($unknown), "$rel: Arten, die der Server nicht meldet");
        }
    }

    /** v3.13.0 (K2d) — FieldRules meldet nur Arten aus FieldRules::KINDS (und `missing`), je mit Feld. */
    public function testTheSharedRulesReportOnlyListedKinds(): void
    {
        $bad = ['meter_id' => ['p1'], 'date' => '0099-01-01', 'counter' => -4, 'is_estimated' => 'vielleicht', 'note' => ['a' => 1],
                'price_cents' => 'unklar', 'devices' => 'x', 'start' => [], 'working_prices' => 'x', 'trinkwasser' => [1, 2]];
        $seen = [];
        foreach (array_keys(\Energietracker\Support\FieldRules::FIELDS) as $pot) {
            foreach (\Energietracker\Support\FieldRules::problems($bad + ['quantity' => 'x', 'tank_levels' => [['date' => 'x']]], $pot) as $p) {
                self::assertSame(1, preg_match('/^([a-z_]+):[a-z_.]+$/', $p, $m), "Fund $p");
                self::assertContains($m[1], [...\Energietracker\Support\FieldRules::KINDS, 'missing'], "Fund $p");
                $seen[$m[1]] = true;
            }
        }
        self::assertGreaterThanOrEqual(7, count($seen), implode(', ', array_keys($seen)));
    }

    /** @return list<string> Arten, deren Text die Angabe selbst nennt (aus dem Modul des Dialogs) */
    private static function argInText(): array
    {
        $js = (string)file_get_contents(self::root() . '/public/js/lib/backup-problems.js');
        self::assertSame(1, preg_match('/PROBLEM_ARG_IN_TEXT = new Set\(\[([^\]]*)\]\)/', $js, $m), 'PROBLEM_ARG_IN_TEXT in backup-problems.js');
        preg_match_all("/'([a-z_0-9]+)'/", $m[1], $mm);
        return $mm[1];
    }

    public function testTheSourceYieldsTheKnownKinds(): void
    {
        $kinds = self::kindsFromSource();
        // eine Untergrenze, damit ein kaputter Leser nicht still nichts findet
        self::assertGreaterThanOrEqual(30, count($kinds), implode(', ', array_keys($kinds)));
        foreach (['not_an_object', 'missing', 'date_range', 'bool', 'number', 'too_long', 'setting', 'sha256', 'recurrence'] as $k) {
            self::assertArrayHasKey($k, $kinds, "Art $k aus dem Quelltext");
        }
        self::assertSame([true, true], $kinds['amount'], '`amount` kommt mit und ohne Angabe vor');
        self::assertSame([true, true], $kinds['mime'], '`mime` kommt mit und ohne Angabe vor');
    }

    public function testEveryKindTheServerReportsHasACatalogText(): void
    {
        $kinds = self::kindsFromSource();
        $arg = self::argInText();
        foreach (self::LANGS as $l) {
            $cat = json_decode((string)file_get_contents(self::root() . "/public/locales/$l.json"), true, 512, JSON_THROW_ON_ERROR);
            $texts = (array)($cat['settings']['backup']['problem'] ?? []);
            foreach ($kinds as $kind => [$with, $without]) {
                self::assertIsString($texts[$kind] ?? null, "$l: settings.backup.problem.$kind fehlt");
                $text = (string)$texts[$kind];
                self::assertNotSame('', trim($text), "$l: $kind leer");
                $named = str_contains($text, '{field}') || str_contains($text, '{what}');
                if (in_array($kind, $arg, true)) {
                    self::assertTrue($named, "$l: $kind steht in PROBLEM_ARG_IN_TEXT, der Text nennt die Angabe nicht: $text");
                    self::assertFalse($without, "$kind kommt auch ohne Angabe vor und darf sie nicht im Text erwarten");
                } else {
                    self::assertFalse($named, "$l: $kind trägt einen Platzhalter, steht aber nicht in PROBLEM_ARG_IN_TEXT: $text");
                }
                // nur die Platzhalter, die der Dialog füllt
                preg_match_all('/\{([a-z]+)\}/', $text, $ph);
                self::assertSame([], array_diff($ph[1], ['field', 'what', 'max']), "$l: $kind mit unbekanntem Platzhalter: $text");
            }
        }
    }

    /** Jede Art im Modul des Dialogs gibt es auch am Server (keine verwaiste Ausnahme). */
    public function testTheDialogListsOnlyKindsTheServerReports(): void
    {
        self::assertSame([], array_values(array_diff(self::argInText(), array_keys(self::kindsFromSource()))));
    }
}
