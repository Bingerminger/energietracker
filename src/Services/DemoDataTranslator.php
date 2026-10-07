<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.1.0 (Review I18N-24) — Demo-Daten in der Sprache der Oberfläche.
 *
 * Bis v3.0 trug der Beispielhaushalt deutsche Zählernamen, Tarife, Notizen und
 * Termine — auch für jemanden, der die App auf Englisch oder Niederländisch
 * ausprobierte (und in der öffentlichen Demo in jeder Sprache). Die
 * Übersetzungen liegen in demo-data/translations.json, Schlüssel ist der
 * deutsche Text aus dem Demo-Backup.
 *
 * Übersetzt werden nur beschreibende Felder; Firmennamen (`provider`,
 * `supplier`) bleiben Eigennamen, IDs und Zahlen unberührt. Reine Funktion.
 */
final class DemoDataTranslator
{
    /** Felder mit Text, den Menschen lesen. */
    public const FIELDS = ['name', 'notes', 'note', 'reason', 'label', 'tariff_name', 'shadow_label', 'title'];

    /**
     * @param array<string,mixed> $payload Backup im Format 3.0
     * @param array<string,array<string,string>> $strings deutscher Text → Sprache → Übersetzung
     * @return array<string,mixed>
     */
    public static function translate(array $payload, string $lang, array $strings): array
    {
        if ($lang === 'de' || $strings === []) return $payload;
        foreach (['utilities', 'reminders'] as $part) {
            if (isset($payload[$part]) && is_array($payload[$part])) {
                $payload[$part] = self::walk($payload[$part], $lang, $strings);
            }
        }
        return $payload;
    }

    private static function walk(array $node, string $lang, array $strings): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::walk($value, $lang, $strings);
            } elseif (is_string($value) && is_string($key) && in_array($key, self::FIELDS, true)) {
                $node[$key] = $strings[$value][$lang] ?? $value;
            }
        }
        return $node;
    }

    /** @return array<string,array<string,string>> */
    public static function load(string $file): array
    {
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($data['strings'] ?? null) ? $data['strings'] : [];
    }
}
