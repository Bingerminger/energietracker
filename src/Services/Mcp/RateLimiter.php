<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037, Paket protokoll) — Ratenbegrenzung der KI-Schnittstelle
 * (Konzept §2.6, Entscheidung 11a: feste Grenzen, keine Einstellung; die
 * Doku nennt sie, ein Test hält beides zusammen).
 *
 * Je Schlüssel zählen Töpfe (LIMITS): alle Anfragen, lesende Werkzeugaufrufe,
 * Vorschläge samt `read_document`, Übernahmen. Ein Fenster beginnt mit der
 * ersten Anfrage nach dem Ende des vorigen (kein Kalendertakt: 120 Anfragen in
 * einer Minute bleiben 120, auch über einen Minutenwechsel). Ist ein Topf voll,
 * antwortet die Schnittstelle mit 429 und `Retry-After` (Sekunden bis zum
 * Ende des Fensters); abgewiesene Anfragen zählen nicht mit.
 *
 * Falsche Schlüssel je Adresse (`AuthService::clientIp`, hinter einem Proxy aus
 * `ET_TRUSTED_PROXIES` die aus `X-Forwarded-For`): nach KEY_FAILURES innerhalb
 * von FAILURE_WINDOW ist die Adresse LOCK_SECONDS gesperrt, für jede Anfrage an
 * /api/mcp (wie die Sperre der Anmeldung). Es zählen nur vorgelegte, unbekannte
 * Schlüssel; eine Anfrage ganz ohne Schlüssel ist kein Rateversuch.
 *
 * Zustand in `data/mcp/rate.json` mit eigener Sperre (StateFile, ohne fsync),
 * nicht über JsonStore: dessen Schreiben holte bei jeder Leseanfrage die
 * globale Schreibsperre. Nicht im Backup; höchstens MAX_ENTRIES Einträge.
 */
final class RateLimiter
{
    /** Topf → [Anzahl, Fenster in Sekunden], je Schlüssel */
    public const LIMITS = [
        'all'     => [120, 60],     // jede Anfrage an /api/mcp
        'call'    => [60, 60],      // tools/call mit einem lesenden Werkzeug
        'propose' => [30, 3600],    // propose_*, read_document
        'apply'   => [30, 3600],    // apply_proposal
        'inbox'   => [30, 3600],    // v3.12.0 (Skeptiker M8) — POST /api/inbox mit einem KI-Schlüssel
    ];
    /** Werkzeuge im Topf `propose` außer denen mit Präfix `propose_` */
    public const PROPOSE_TOOLS = ['read_document'];
    /** Werkzeuge im Topf `apply` */
    public const APPLY_TOOLS = ['apply_proposal'];
    /**
     * v3.12.0 (integration) — Schreibwerkzeuge im Topf `call`: Verwerfen ändert nichts am Haushalt und räumt nur
     * eigene offene Vorschläge weg. Im Topf `propose` ginge es genau dann nicht mehr, wenn der voll ist (und bei
     * 50 offenen Vorschlägen käme die KI eine Stunde lang nicht weiter). Ein neues Schreibwerkzeug gehört in einen
     * der Töpfe oben oder ausdrücklich hierher (McpIntegrationTest prüft die Registry).
     */
    public const WRITE_TOOLS_IN_CALL = ['cancel_proposal'];

    /** falsche Schlüssel je Adresse bis zur Sperre */
    public const KEY_FAILURES = 20;
    /** … innerhalb dieser Sekunden */
    public const FAILURE_WINDOW = 600;
    /** Dauer der Sperre in Sekunden */
    public const LOCK_SECONDS = 300;
    /** Obergrenze der Einträge (wechselnde Adressen füllen die Datei nicht beliebig) */
    public const MAX_ENTRIES = 500;

    private StateFile $file;
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock Unix-Zeit (Tests) */
    public function __construct(string $dataDir, ?\Closure $clock = null)
    {
        $this->file = new StateFile($dataDir, 'rate.json', false);
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Topf eines Werkzeugaufrufs. */
    public static function bucketForTool(string $name): string
    {
        if (in_array($name, self::APPLY_TOOLS, true)) return 'apply';
        if (str_starts_with($name, 'propose_') || in_array($name, self::PROPOSE_TOOLS, true)) return 'propose';
        return 'call';
    }

    /**
     * Eine Anfrage des Schlüssels `$keyId` im Topf `$bucket`: null = erlaubt
     * (und gezählt), sonst Sekunden, bis der Topf wieder frei ist (mindestens 1).
     */
    public function hit(string $bucket, string $keyId): ?int
    {
        if (!isset(self::LIMITS[$bucket])) throw new \LogicException("mcp: rate bucket '$bucket' is not defined");
        [$limit, $window] = self::LIMITS[$bucket];
        $id = "key:$keyId:$bucket";
        $now = ($this->clock)();
        return $this->file->update(function (array $s) use ($id, $limit, $window, $now): array {
            $e = self::current($s[$id] ?? null, $window, $now);
            $wait = $e['n'] >= $limit ? max(1, $e['since'] + $window - $now) : null;
            if ($wait === null) $e['n']++;
            $s[$id] = $e;
            return [self::prune($s, $now), $wait];
        });
    }

    /** Sekunden, die `$ip` noch gesperrt ist; null = nicht gesperrt. Liest ohne Sperre. */
    public function lockedFor(string $ip): ?int
    {
        $e = $this->file->read()[self::ipId($ip)] ?? null;
        $until = is_array($e) ? (int)($e['until'] ?? 0) : 0;
        $now = ($this->clock)();
        return $until > $now ? $until - $now : null;
    }

    /**
     * Ein vorgelegter, unbekannter Schlüssel von `$ip`. Gibt die Sekunden der
     * Sperre zurück, wenn dieser Versuch sie auslöst, sonst null.
     */
    public function failure(string $ip): ?int
    {
        $id = self::ipId($ip);
        $now = ($this->clock)();
        return $this->file->update(function (array $s) use ($id, $now): array {
            $e = self::current($s[$id] ?? null, self::FAILURE_WINDOW, $now);
            $e['n']++;
            $lock = $e['n'] >= self::KEY_FAILURES ? self::LOCK_SECONDS : null;
            // gesperrt: danach zählt ein neues Fenster
            $s[$id] = $lock === null ? $e : ['n' => 0, 'since' => $now, 'w' => self::FAILURE_WINDOW, 'until' => $now + $lock];
            return [self::prune($s, $now), $lock];
        });
    }

    /** Ein gültiger Schlüssel von `$ip`: die Fehlversuche dieser Adresse verfallen. */
    public function success(string $ip): void
    {
        $id = self::ipId($ip);
        if (!array_key_exists($id, $this->file->read())) return;   // der Normalfall: nichts zu schreiben
        $this->file->update(function (array $s) use ($id): array {
            $e = $s[$id] ?? null;
            // eine laufende Sperre bleibt (sie wird vor dem Schlüssel geprüft; hier nur zur Sicherheit)
            if (is_array($e) && (int)($e['until'] ?? 0) > ($this->clock)()) return [$s, null];
            unset($s[$id]);
            return [$s, null];
        });
    }

    private static function ipId(string $ip): string
    {
        return 'ip:' . ($ip !== '' ? $ip : '-');
    }

    /**
     * Eintrag im laufenden Fenster oder ein frischer.
     *
     * @return array{n:int, since:int, w:int, until?:int}
     */
    private static function current(mixed $e, int $window, int $now): array
    {
        $until = is_array($e) ? (int)($e['until'] ?? 0) : 0;
        if (!is_array($e) || (int)($e['since'] ?? 0) + $window <= $now) {
            return ['n' => 0, 'since' => $now, 'w' => $window] + ($until > $now ? ['until' => $until] : []);
        }
        return ['n' => (int)($e['n'] ?? 0), 'since' => (int)$e['since'], 'w' => $window] + ($until > $now ? ['until' => $until] : []);
    }

    /**
     * Abgelaufene Einträge weg (Fenster vorbei, keine Sperre), höchstens
     * MAX_ENTRIES (die jüngsten; laufende Sperren zuerst behalten).
     *
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private static function prune(array $s, int $now): array
    {
        $s = array_filter($s, fn($e) => is_array($e)
            && ((int)($e['until'] ?? 0) > $now || (int)($e['since'] ?? 0) + (int)($e['w'] ?? 0) > $now));
        if (count($s) > self::MAX_ENTRIES) {
            uasort($s, fn($a, $b) => [(int)(($b['until'] ?? 0) > $now), (int)$b['since']] <=> [(int)(($a['until'] ?? 0) > $now), (int)$a['since']]);
            $s = array_slice($s, 0, self::MAX_ENTRIES, true);
        }
        return $s;
    }
}
