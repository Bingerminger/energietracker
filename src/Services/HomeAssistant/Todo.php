<?php
declare(strict_types=1);

namespace Energietracker\Services\HomeAssistant;

/**
 * v3.9.0 (F1033) — die Liste „Zu tun“ für Home Assistant (`GET /api/todo`,
 * Sensor „Zu tun“, HACS-Integration, Benachrichtigungen).
 *
 * Quelle sind die Ereignisse der Agenda mit `due_now` — dieselben, aus denen
 * die Übersicht „Zu tun“ baut. Gezählt wird jedes fällige Ereignis, gleich
 * welcher Art: Eine neue Art (etwa „Heizgrenze anpassen“) zählt mit, ohne
 * dass hier etwas nachgezogen werden muss. Drei fällige Ablesungen sind drei
 * Punkte (die Übersicht fasst sie in einer Zeile zusammen).
 *
 * Reihenfolge: überfällig, dringend, fällig; dann nach Datum, dann nach Art
 * und UID (stabil). „Nächster Punkt“ ist der erste dieser Reihenfolge.
 */
final class Todo
{
    public const VERSION = 1;
    public const MAX_ITEMS = 50;
    private const RANK = ['overdue' => 0, 'urgent' => 1, 'due' => 2, 'upcoming' => 3];

    /**
     * @param list<array<string,mixed>> $events aus AgendaService::events()
     * @return list<array<string,mixed>> fällige Punkte, sortiert
     */
    public static function due(array $events): array
    {
        $due = array_values(array_filter($events, fn($e) => is_array($e) && !empty($e['due_now']) && empty($e['done'])));
        usort($due, fn($a, $b) => [self::RANK[$a['severity'] ?? 'due'] ?? 2, (string)($a['date'] ?? ''), (string)($a['kind'] ?? ''), (string)($a['uid'] ?? '')]
            <=> [self::RANK[$b['severity'] ?? 'due'] ?? 2, (string)($b['date'] ?? ''), (string)($b['kind'] ?? ''), (string)($b['uid'] ?? '')]);
        return $due;
    }

    /**
     * Antwort von `GET /api/todo` (Klasse A: Schlüssel bleiben, neue kommen dazu).
     *
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function summary(array $events, string $instanceId = ''): array
    {
        $due = self::due($events);
        $items = array_map(self::item(...), array_slice($due, 0, self::MAX_ITEMS));
        return [
            'todo_version' => self::VERSION,
            'instance_id'  => $instanceId,
            'generated_at' => date('c'),
            'count'        => count($due),
            'overdue'      => count(array_filter($due, fn($e) => in_array($e['severity'] ?? '', ['overdue', 'urgent'], true))),
            'next'         => $items[0] ?? null,
            'items'        => $items,
        ];
    }

    /** @return array{uid:string, kind:string, title:string, date:string, severity:string, href:string} */
    public static function item(array $e): array
    {
        return [
            'uid'      => (string)($e['uid'] ?? ''),
            'kind'     => (string)($e['kind'] ?? ''),
            'title'    => (string)($e['title'] ?? ''),
            'date'     => (string)($e['date'] ?? ''),
            'severity' => (string)($e['severity'] ?? 'due'),
            'href'     => (string)($e['href'] ?? ''),
        ];
    }
}
