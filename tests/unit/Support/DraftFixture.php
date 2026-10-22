<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Storage\JsonStore;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — erfundene Auslesungen für den Entwurf.
 *
 * Ein Dokument entsteht aus einer Liste „Feld → Wert wie gedruckt“: Je Feld
 * steht eine Zeile in der Textebene, und die Antwort des „Modells“ zitiert
 * genau diese Zeile (Belegstelle gültig). Mit `$flag` bekommt ein Feld ein
 * Zitat, das nicht im Text steht — es ist dann markiert, wie in echt bei einem
 * unsicheren Wert. Listenfelder (Arbeitspreise, Abschläge, Gas-Umrechnung) als
 * Liste von Einträgen. Geprüft wird über die echte Belegprüfung
 * (Evidence::check), gespeichert wie nach dem Auslesen (Status „read“).
 *
 * Anbieter, Nummern und Beträge sind ausgedacht; Marktlokationen sind
 * synthetische Nummern mit gültiger Prüfziffer.
 */
final class DraftFixture
{
    public const MALO_A = '51234567895';
    public const MALO_B = '41234567896';

    /**
     * Textebene und Antwort für eine Art.
     *
     * @param array<string,mixed> $values Feld → Wert wie gedruckt (Listenfelder: Liste von Einträgen)
     * @param list<string>        $flag   Felder mit falscher Belegstelle
     * @return array{0: list<string>, 1: array<string,mixed>}
     */
    public static function make(string $type, array $values, array $flag = []): array
    {
        $defs = InboxSchema::fields($type);
        $lines = [];
        $answer = [];
        foreach ($values as $name => $v) {
            $def = $defs[$name] ?? null;
            if ($def === null) throw new \InvalidArgumentException("unknown field $type/$name");
            if ($def['kind'] === 'list') {
                $items = [];
                foreach ($v as $i => $item) {
                    $line = "$name " . ($i + 1) . ': ' . implode(' ', array_map('strval', array_values($item)));
                    $lines[] = $line;
                    $items[] = $item + ['quote' => in_array($name, $flag, true) ? 'nicht im Dokument 99' : $line, 'page' => 1];
                }
                $answer[$name] = ['value' => $items, 'quote' => null, 'page' => null];
                continue;
            }
            $hint = $def['kind'] === 'enum' ? ' ' . ($def['hints'][$v][0] ?? '') : '';
            $line = "$name: $v$hint";
            $lines[] = $line;
            $answer[$name] = ['value' => $v, 'quote' => in_array($name, $flag, true) ? 'nicht im Dokument 99' : $line, 'page' => 1];
        }
        return [[implode("\n", $lines)], $answer];
    }

    /**
     * Ein ausgelesenes Dokument in den Posteingang legen: PDF über den Dienst
     * (Beleg), dann Art, Felder und Status wie nach dem Auslesen.
     *
     * @param callable(string, ?string): array $upload  (bytes, name) → Eintrag
     * @param array<string,mixed> $values
     * @param list<string> $flag
     * @param array<string,mixed> $extra weitere Felder am Eintrag (assignment, edits …)
     */
    public static function add(JsonStore $store, callable $upload, string $tag, string $type, array $values, array $flag = [], array $extra = []): string
    {
        $e = $upload(InboxFixture::pdf($tag), "$tag.pdf");
        self::patch($store, (string)$e['id'], $type, $values, $flag, $extra);
        return (string)$e['id'];
    }

    /** Einen abgelegten Eintrag so setzen, als wäre er ausgelesen (auch für tests/fixtures/draft-seed.php). */
    public static function patch(JsonStore $store, string $id, string $type, array $values, array $flag = [], array $extra = []): void
    {
        [$pages, $answer] = self::make($type, $values, $flag);
        $fields = Evidence::check($type, $answer, $pages);
        $all = $store->read('inbox.json', []);
        foreach ($all as $i => $x) {
            if ($x['id'] !== $id) continue;
            $all[$i] = $extra + $x;
            $all[$i]['type'] = $type;
            $all[$i]['status'] = 'read';
            $all[$i]['pages'] = 1;
            $all[$i]['extraction'] = ['provider' => 'local', 'model' => 'fake-1', 'schema_version' => InboxSchema::VERSION, 'type' => $type,
                'fields' => $fields, 'flags' => Evidence::flagCount($fields), 'seconds' => ['classify' => 1, 'extract' => 2], 'extracted_at' => date('c')];
        }
        $store->write('inbox.json', $all);
    }
}
