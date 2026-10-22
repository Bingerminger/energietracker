<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 — Zuständigkeit an Zählern und Verträgen: Fristen landen bei einer
 * Person.
 *
 * Ein Zähler oder Vertrag trägt optional `assignee_id`, eine Person aus dem
 * Personenverzeichnis (PeopleService) — anders als an Terminen keine freien
 * Namen. Fehlt das Feld, ist niemand zuständig (additiv, kein Schemaschritt).
 * Den Namen liest die Anzeige über die ID aus dem Verzeichnis; eine gelöschte
 * Person steht dort weiter („Name (gelöscht)“).
 *
 * Termine und Fristen erben (AgendaService, Kalender, Verlauf):
 *   eigene Zuständigkeit am Termin → Vertrag → Zähler → niemand.
 * `assignee_source` sagt, woher sie kommt: `own`, `contract`, `meter` oder
 * null. Eine eigene Zuständigkeit geht immer vor, auch eine gelöschte oder
 * „ehemalige Person“ — sie bleibt stehen, bis jemand den Termin neu zuweist.
 *
 * Person löschen (wie offene Termine seit v3.6.0): Zuständigkeiten an Zählern
 * und Verträgen gehen an die löschende Person. Ohne sie (Anmeldung aus,
 * API-Schlüssel) bleibt die ID stehen und zeigt „Name (gelöscht)“; mit
 * „ehemalige Person“ ersetzt `assignee_former: true` die ID.
 */
final class ResponsibilityService
{
    /** Herkunft einer Zuständigkeit an einem Ereignis. */
    public const SOURCES = ['own', 'contract', 'meter'];
    /** Töpfe je Verbrauchsart mit Zuständigkeit. */
    public const POTS = ['meters', 'contracts'];
    /** Katalogschlüssel (und stabiler Fehlercode) für eine Person, die es nicht (mehr) gibt. */
    public const UNKNOWN = 'errors.assignee.unknown';

    /** @var (callable(string): ?array)|null Person zu einer ID ({id, name, deleted?}), auch gelöschte */
    private $people = null;

    public function __construct(private JsonStore $store) {}

    /** App: Personenverzeichnis (PeopleService::find, dazu der Verwalter aus auth.json). */
    public function attachPeople(callable $lookup): void
    {
        $this->people = $lookup;
    }

    /** Eine Person (auch gelöscht) oder null. */
    public function person(string $id): ?array
    {
        return $id !== '' && $this->people !== null ? ($this->people)($id) : null;
    }

    /**
     * Eingabe `assignee_id` prüfen: null oder leer = niemand; sonst eine
     * Person, die es gibt und die nicht gelöscht ist. Die bisherige Person darf
     * stehen bleiben, auch wenn sie inzwischen gelöscht ist (ein Formular, das
     * sie unverändert zeigt, speichert sie unverändert).
     *
     * @throws LocalizedException errors.assignee.unknown
     */
    public function checkInput(mixed $raw, ?string $current = null): ?string
    {
        if ($raw === null || $raw === '') return null;
        $id = is_string($raw) ? trim($raw) : '';
        if (!preg_match(PeopleService::ID, $id)) throw new LocalizedException(self::UNKNOWN, [], 'assignee id malformed');
        if ($id === $current) return $id;
        // ohne Verzeichnis (Dienst einzeln, etwa im Test) genügt die Form
        if ($this->people === null) return $id;
        $p = ($this->people)($id);
        if ($p === null || !empty($p['deleted'])) throw new LocalizedException(self::UNKNOWN, [], 'assignee not in register');
        return $id;
    }

    /**
     * Zuständigkeit an einen Zähler oder Vertrag schreiben: eine ID oder null
     * (niemand). Ersetzt auch den Vermerk „ehemalige Person“.
     *
     * @param array<string,mixed> $x
     * @return array<string,mixed>
     */
    public static function apply(array $x, ?string $id): array
    {
        unset($x['assignee_id'], $x['assignee_former']);
        if ($id !== null) $x['assignee_id'] = $id;
        return $x;
    }

    /**
     * Anzeige einer Zuständigkeit an einem Zähler oder Vertrag: immer alle
     * vier Felder. Eine ID, die das Verzeichnis nicht kennt (fremdes Backup),
     * zeigt niemanden.
     *
     * @param array<string,mixed> $x
     * @return array{assignee_id:?string, assignee:?string, assignee_former:bool, assignee_deleted:bool}
     */
    public function view(array $x): array
    {
        $id = is_string($x['assignee_id'] ?? null) ? $x['assignee_id'] : '';
        $p = $this->person($id);
        $name = $p !== null && is_string($p['name'] ?? null) && $p['name'] !== '' ? $p['name'] : null;
        return [
            'assignee_id'      => $name !== null ? $id : null,
            'assignee'         => $name,
            'assignee_former'  => $name === null && !empty($x['assignee_former']),
            'assignee_deleted' => $name !== null && !empty($p['deleted']),
        ];
    }

    /**
     * Zähler oder Vertrag für die Antwort der API (additiv: `assignee`,
     * `assignee_former`, `assignee_deleted`; `assignee_id` nur mit Person).
     *
     * @param array<string,mixed> $x
     * @return array<string,mixed>
     */
    public function decorate(array $x): array
    {
        return $this->view($x) + $x;
    }

    /**
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    public function decorateAll(array $list): array
    {
        return array_map(fn($x) => is_array($x) ? $this->decorate($x) : $x, $list);
    }

    /**
     * Wer bei einem Ereignis zuständig ist. `$own` = die Zuständigkeit des
     * Termins (ReminderService::withAssignee) oder null; sie geht vor, auch
     * gelöscht oder als „ehemalige Person“. Sonst der Vertrag, sonst der
     * Zähler, sonst niemand.
     *
     * @param array<string,mixed>|null $own
     * @param array<string,mixed>|null $contract gespeicherter Vertrag
     * @param array<string,mixed>|null $meter    gespeicherter Zähler (keine Gruppe)
     * @return array{assignee:?string, assignee_id:?string, assignee_former:bool, assignee_deleted:bool, assignee_source:?string}
     */
    public function resolve(?array $own, ?array $contract = null, ?array $meter = null): array
    {
        return self::own($own) ?? $this->inherit($contract, $meter);
    }

    /** Niemand zuständig. */
    public const NOBODY = ['assignee' => null, 'assignee_id' => null, 'assignee_former' => false, 'assignee_deleted' => false, 'assignee_source' => null];

    /**
     * Die eigene Zuständigkeit eines Termins (Name, auch gelöscht, oder
     * „ehemalige Person“), sonst null — dann erbt er.
     *
     * @param array<string,mixed>|null $own
     * @return array{assignee:?string, assignee_id:?string, assignee_former:bool, assignee_deleted:bool, assignee_source:string}|null
     */
    public static function own(?array $own): ?array
    {
        $name = is_string($own['assignee'] ?? null) && trim($own['assignee']) !== '' ? trim($own['assignee']) : null;
        if ($name === null && empty($own['assignee_former'])) return null;
        return [
            'assignee'         => $name,
            'assignee_id'      => $name !== null && is_string($own['assignee_id'] ?? null) && $own['assignee_id'] !== '' ? $own['assignee_id'] : null,
            'assignee_former'  => $name === null,
            'assignee_deleted' => $name !== null && !empty($own['assignee_deleted']),
            'assignee_source'  => 'own',
        ];
    }

    /**
     * Geerbt: die Zuständigkeit des Vertrags, sonst die des Zählers (eine
     * Zählergruppe hat keine), sonst niemand.
     *
     * @param array<string,mixed>|null $contract
     * @param array<string,mixed>|null $meter
     * @return array{assignee:?string, assignee_id:?string, assignee_former:bool, assignee_deleted:bool, assignee_source:?string}
     */
    public function inherit(?array $contract, ?array $meter): array
    {
        foreach (['contract' => $contract, 'meter' => $meter] as $source => $x) {
            if ($x === null || !empty($x['is_group'])) continue;
            $v = $this->view($x);
            if ($v['assignee'] !== null || $v['assignee_former']) {
                return ['assignee' => $v['assignee'], 'assignee_id' => $v['assignee_id'], 'assignee_former' => $v['assignee_former'],
                        'assignee_deleted' => $v['assignee_deleted'], 'assignee_source' => $source];
            }
        }
        return self::NOBODY;
    }

    /**
     * Momentaufnahme für den Verlauf (ActivityService::record, `assignee`):
     * {name, id?, deleted?}, {former: true} oder null (niemand).
     *
     * @param array<string,mixed>|null $resolved aus resolve()/inherit()
     * @return array<string,mixed>|null
     */
    public static function snapshot(?array $resolved): ?array
    {
        if ($resolved === null) return null;
        if (is_string($resolved['assignee'] ?? null) && $resolved['assignee'] !== '') {
            return array_filter(['name' => $resolved['assignee'], 'id' => $resolved['assignee_id'] ?? null,
                                 'deleted' => !empty($resolved['assignee_deleted']) ? true : null], fn($v) => $v !== null);
        }
        return !empty($resolved['assignee_former']) ? ['former' => true] : null;
    }

    /**
     * Zähler eines Termins (`utility`, `meter_id`), wenn er einen hat und es
     * ihn noch gibt.
     *
     * @param array<string,mixed> $reminder
     * @return array<string,mixed>|null
     */
    public function linkedMeter(array $reminder): ?array
    {
        $u = is_string($reminder['utility'] ?? null) ? $reminder['utility'] : '';
        $mid = is_string($reminder['meter_id'] ?? null) ? $reminder['meter_id'] : '';
        if ($u === '' || $mid === '' || !Utilities::exists($u)) return null;
        foreach ($this->rows("$u/meters.json") as $m) {
            if (is_array($m) && ($m['id'] ?? null) === $mid) return $m;
        }
        return null;
    }

    /**
     * Was für einen Termin ohne eigene Zuständigkeit gilt: die des Zählers,
     * an dem er hängt, sonst null. Für `GET /api/reminders`
     * (`assignee_inherited`), damit Liste und Filter sie zeigen.
     *
     * @param array<string,mixed> $reminder
     * @return array{assignee:?string, assignee_id:?string, assignee_former:bool, assignee_deleted:bool, assignee_source:string}|null
     */
    public function inheritedFor(array $reminder): ?array
    {
        $r = $this->inherit(null, $this->linkedMeter($reminder));
        return $r['assignee_source'] === null ? null : $r;
    }

    /**
     * Gehört ein Ereignis (mit aufgelöster Zuständigkeit) dieser Person? Per
     * ID oder — ohne ID — mit ihrem Namen (ein freier Name am Termin, Groß/klein
     * egal). Gelöschte und „ehemalige Person“ gehören niemandem mehr.
     *
     * @param array<string,mixed> $e
     */
    public static function belongsTo(array $e, string $personId, string $name = ''): bool
    {
        if (!is_string($e['assignee'] ?? null) || $e['assignee'] === '' || !empty($e['assignee_deleted']) || !empty($e['assignee_former'])) return false;
        if (($e['assignee_id'] ?? null) === $personId) return true;
        return empty($e['assignee_id']) && $name !== '' && mb_strtolower(trim($e['assignee'])) === mb_strtolower(trim($name));
    }

    /**
     * Wie viele Zähler und Verträge eine Person hat (Vorschau „Person löschen“).
     *
     * @return array{meters:int, contracts:int}
     */
    public function countFor(string $personId): array
    {
        $n = ['meters' => 0, 'contracts' => 0];
        if ($personId === '') return $n;
        foreach (Utilities::keys() as $u) {
            foreach (self::POTS as $pot) {
                foreach ($this->rows("$u/$pot.json") as $x) {
                    if (is_array($x) && ($x['assignee_id'] ?? null) === $personId) $n[$pot]++;
                }
            }
        }
        return $n;
    }

    /**
     * Eine Person wurde gelöscht. Zähler und Verträge gehen an die löschende
     * Person `$to` ({id, name}); ohne sie bleibt die ID stehen (das Verzeichnis
     * führt die Person als gelöscht), mit `$anonymize` ersetzt „ehemalige
     * Person“ die ID. Dieselbe Regel wie für offene Termine
     * (ReminderService::personDeleted).
     *
     * @param array{id:string, name:string}|null $to
     * @return array{handed_over:int, kept:int}
     */
    public function personDeleted(string $personId, bool $anonymize, ?array $to = null): array
    {
        $out = ['handed_over' => 0, 'kept' => 0];
        if ($personId === '') return $out;
        $toId = is_string($to['id'] ?? null) && $to['id'] !== '' && $to['id'] !== $personId ? $to['id'] : null;
        foreach (Utilities::keys() as $u) {
            foreach (self::POTS as $pot) {
                $file = "$u/$pot.json";
                $rows = $this->rows($file);
                $changed = false;
                foreach ($rows as $i => $x) {
                    if (!is_array($x) || ($x['assignee_id'] ?? null) !== $personId) continue;
                    if ($toId !== null) {
                        $rows[$i] = self::apply($x, $toId);
                        $out['handed_over']++;
                        $changed = true;
                        continue;
                    }
                    $out['kept']++;
                    if ($anonymize) {
                        unset($rows[$i]['assignee_id']);
                        $rows[$i]['assignee_former'] = true;
                        $changed = true;
                    }
                }
                if ($changed) $this->store->write($file, array_values($rows));
            }
        }
        return $out;
    }

    /** @return list<mixed> */
    private function rows(string $file): array
    {
        if (!$this->store->exists($file)) return [];
        $rows = $this->store->read($file, []);
        return is_array($rows) ? array_values($rows) : [];
    }
}
