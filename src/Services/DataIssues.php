<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\FieldRules;
use Energietracker\Support\TextLimits;

/**
 * v3.13.0 (K2d) — Bestand beim Start: gespeicherte Werte, die die Schreibwege heute abweisen würden.
 *
 * Ältere Versionen nahmen Werte an, die heute abgewiesen werden (ein Stand im Jahr 0150, ein Vertragsende als Liste,
 * `forecast_model: []`, eine Heizgrenze von 10³⁰⁰, ein Zeitraum ab dem Jahr 0003, ein Tankbestand von 10³⁰⁰). Nach
 * dem Update brachen sie Lesewege mit 500 ab. Dieselbe Prüfung wie auf den Schreibwegen und im Backup-Import
 * (Support\FieldRules, SettingsService::problems) liest den Bestand jetzt so, wie die Rechnung ihn braucht: Ein
 * Datensatz mit unbrauchbarem Pflichtfeld fällt aus der Rechnung, ein anderes Feld fällt weg, eine Einstellung gilt
 * mit ihrer Vorgabe. Die Dateien bleiben, wie sie sind.
 *
 * v3.13.0 — Der Bestand meldet dieselben Funde wie der Import seines eigenen Exports (DataIssuesImportParityTest):
 * dazu Verweise ins Leere (FieldRules::references), Freitext über seiner Höchstlänge (TextLimits) und Einstellungen,
 * die ihr Leser nachsichtig liest (der Fälligkeitstag wird geklemmt). Diese Werte bleiben in der Rechnung, wie sie
 * gespeichert sind (`effect: kept`); bisher meldete sie niemand, und erst das Einspielen einer Sicherung scheiterte.
 *
 * Dieser Dienst sammelt die Funde für /api/health (`data_issues`: Anzahl und Arten, Status bleibt 200) und die
 * Diagnose (Liste mit Topf, Kennung, Fund und Wirkung `effect`: `excluded` aus der Rechnung, `field` Feld gilt als
 * nicht eingetragen, `default` Vorgabe der Einstellung, `kept` bleibt, wie gespeichert). Er liest nur.
 */
final class DataIssues
{
    /** Töpfe je Verbrauchsart mit Regeln (BackupService::UTILITY_POTS ohne die ohne Regel). */
    public const UTILITY_POTS = ['meters', 'meter_groups', 'readings', 'contracts', 'deliveries', 'periods', 'bills'];
    /** Töpfe auf oberster Ebene mit Regeln. */
    public const TOP_POTS = ['reminders', 'tenancies'];
    /** v3.13.0 — Töpfe auf oberster Ebene, für die nur Höchstlängen gelten (wie im Backup-Import): Topf → Datei. */
    private const LENGTH_POTS = ['tenancy_statements' => 'tenancy_statements.json', 'people' => PeopleService::FILE,
        'inbox' => InboxService::FILE, 'activity' => 'activity.json', 'feed_in_payouts' => FeedInPayoutService::FILE];

    public function __construct(private JsonStore $store) {}

    /**
     * Alle Funde.
     *
     * @return list<array{pot:string, index:?int, id:?string, problems:list<string>, excluded:bool, effect:string}>
     */
    public function scan(): array
    {
        $out = [];
        foreach (Utilities::keys() as $u) {
            $refs = null;
            foreach (self::UTILITY_POTS as $pot) {
                if (!$this->store->exists("$u/$pot.json")) continue;
                $refs ??= ['meters' => FieldRules::ids($this->safeRead("$u/meters.json")), 'groups' => FieldRules::ids($this->safeRead("$u/meter_groups.json"))];
                foreach ($this->issuesOf("$u/$pot.json", $pot, $refs) as $i) $out[] = ['pot' => "$u/$pot"] + $i;
            }
        }
        foreach (self::TOP_POTS as $pot) {
            if (!$this->store->exists("$pot.json")) continue;
            foreach ($this->issuesOf("$pot.json", $pot, null) as $i) $out[] = ['pot' => $pot] + $i;
        }
        foreach (self::LENGTH_POTS as $pot => $file) {
            if (!$this->store->exists($file)) continue;
            $rows = $this->safeRead($file);
            if ($pot === 'people') $rows = is_array($rows['people'] ?? null) ? $rows['people'] : [];
            foreach (array_is_list($rows) ? $rows : [] as $i => $row) {
                if (!is_array($row) || ($long = self::tooLong($row, $pot)) === []) continue;
                $id = $row['id'] ?? null;
                $out[] = ['pot' => $pot, 'index' => (int)$i, 'id' => is_scalar($id) ? (string)$id : null, 'problems' => $long, 'excluded' => false, 'effect' => 'kept'];
            }
        }
        // Einstellungen: dieselbe Prüfung wie PATCH /api/settings und der Backup-Import (problems()); was der Bestand
        // nicht liest, gilt mit der Vorgabe, der Rest bleibt (der Fälligkeitstag geklemmt, ein langer Ortsname lesbar)
        $settings = $this->safeRead('settings.json');
        $svc = new SettingsService($this->store);
        $fallback = $svc->storedProblems($settings);
        // null heißt „nicht eingetragen“ (wie in storedProblems() und im Import): kein Fund
        foreach ($svc->problems(array_filter($settings, fn($v) => $v !== null)) as $k) {
            $kind = TextLimits::problems([$k => $settings[$k] ?? null], 'settings') !== [] ? 'too_long' : 'setting';
            $out[] = ['pot' => 'settings', 'index' => null, 'id' => $k, 'problems' => ["$kind:$k"], 'excluded' => false,
                'effect' => in_array($k, $fallback, true) ? 'default' : 'kept'];
        }
        // ausgeblendete Empfehlungen: je Kennung ein Tag; ohne Tag gilt die Empfehlung als nicht ausgeblendet
        foreach ($this->safeRead('recommendations_dismissed.json') as $rid => $until) {
            if (is_string($until) && Dates::isIsoDate($until)) continue;
            $out[] = ['pot' => 'recommendations_dismissed', 'index' => null, 'id' => (string)$rid, 'problems' => ['date:until'], 'excluded' => true, 'effect' => 'excluded'];
        }
        return $out;
    }

    /**
     * Für /api/health: Anzahl der Funde und je Art, wie oft sie vorkommt (`date_range`, `setting` …).
     *
     * @return array{count:int, kinds:array<string,int>|\stdClass}
     */
    public function summary(): array
    {
        $kinds = [];
        $all = $this->scan();
        foreach ($all as $i) {
            foreach ($i['problems'] as $p) {
                $k = explode(':', $p, 2)[0];
                $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            }
        }
        ksort($kinds);
        // leer als Objekt `{}` (JSON), damit die Form der Antwort nicht wechselt
        return ['count' => count($all), 'kinds' => $kinds === [] ? new \stdClass() : $kinds];
    }

    /**
     * Funde eines Topfs: die Regeln der Felder (Sicht der Rechnung), dazu Verweise ins Leere und Freitext über der
     * Höchstlänge, je Zeile ein Eintrag.
     *
     * @param array{meters: array<string,true>, groups: array<string,true>}|null $refs
     * @return list<array{index:?int, id:?string, problems:list<string>, excluded:bool, effect:string}>
     */
    private function issuesOf(string $file, string $pot, ?array $refs): array
    {
        try {
            [, $issues, $rows] = FieldRules::view($this->store, $file, $pot);
        } catch (\Throwable) {
            return [];   // beschädigt: meldet der Gesundheitscheck unter `files`
        }
        $byIndex = [];
        foreach ($issues as $i) {
            $byIndex[(int)$i['index']] = $i + ['effect' => $i['excluded'] ? 'excluded' : 'field'];
        }
        foreach (is_array($rows) ? $rows : [] as $i => $row) {
            if (!is_array($row)) continue;
            $refsOf = $refs === null ? [] : array_map(fn(string $f): string => 'reference:' . $f, FieldRules::references($pot, $row, $refs));
            $more = [...$refsOf, ...self::tooLong($row, $pot)];
            if ($more === []) continue;
            $id = $row['id'] ?? null;
            $byIndex[(int)$i] ??= ['index' => (int)$i, 'id' => is_scalar($id) ? (string)$id : null, 'problems' => [], 'excluded' => false, 'effect' => 'kept'];
            $byIndex[(int)$i]['problems'] = array_values(array_unique([...$byIndex[(int)$i]['problems'], ...$more]));
        }
        ksort($byIndex);
        return array_values($byIndex);
    }

    /**
     * Freitext über seiner Höchstlänge als `too_long:feld` (wie der Backup-Import).
     *
     * @param array<array-key,mixed> $row
     * @return list<string>
     */
    private static function tooLong(array $row, string $pot): array
    {
        return array_map(fn(array $p): string => 'too_long:' . $p['field'], TextLimits::problems($row, $pot));
    }

    /** @return array<array-key,mixed> */
    private function safeRead(string $file): array
    {
        try {
            $v = $this->store->read($file, []);
        } catch (\Throwable) {
            return [];
        }
        return is_array($v) ? $v : [];
    }
}
