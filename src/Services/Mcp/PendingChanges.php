<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037) — Vorschläge der KI (Entscheidung 8, Konzept §3.3).
 *
 * Schreiben geht nur in zwei Schritten: `propose_*` prüft alles wie beim
 * Schreiben und legt hier einen Vorschlag an (schreibt sonst nichts),
 * `apply_proposal(proposal_id, summary)` übernimmt ihn. Ein Vorschlag ist
 *  - an den Schlüssel gebunden, der ihn angelegt hat (ein fremder sieht ihn nicht),
 *  - über MCP 15 Minuten gültig (TTL_SECONDS) und nur einmal zu übernehmen; ein zweites
 *    Übernehmen liefert dieselbe Antwort, ohne erneut zu schreiben,
 *  - nur mit der wörtlich gleichen Zusammenfassung zu übernehmen (die der Server
 *    aus Strukturdaten gebaut hat; Leerraum zählt nicht),
 *  - höchstens MAX_OPEN_PER_KEY-mal je Schlüssel gleichzeitig offen.
 * Kennung `mp_` + 24 Hex (96 Bit Zufall). Ablage `data/mcp/pending.json`, eigene
 * Sperre, nicht im Backup. Abgelaufene und übernommene Vorschläge bleiben einen
 * Tag stehen (Meldung „abgelaufen“ statt „unbekannt“, gleiche Antwort beim
 * Wiederholen) und fallen dann weg; verworfene sofort.
 *
 * v3.12.0 (Skeptiker M1) — Übernahme nur in der App (`mcp_apply_in_app`, ab Werk an):
 * Ein Vorschlag gilt dann TTL_APP_SECONDS, weil die Person dafür in die App muss und
 * sie nicht neben dem KI-Programm sitzen muss. `status()` beantwortet `apply_proposal`,
 * ohne zu schreiben; `consumeInApp()` übernimmt (nur Verwalter, Karte „KI-Assistenten“),
 * gleich von welchem Schlüssel, solange der Schlüssel noch gilt. Über MCP gelten auch für
 * einen so angelegten Vorschlag nur die 15 Minuten, wenn der Schalter später aus ist.
 *
 * Sperren immer in derselben Reihenfolge: consume() und consumeInApp() holen zuerst die
 * globale Schreibsperre (JsonStore::lock()), dann die eigene; create() und cancel() nur
 * die eigene. So warten nie zwei Anfragen gegenseitig aufeinander.
 */
final class PendingChanges
{
    public const TTL_SECONDS = 900;
    /** Gültigkeit mit `mcp_apply_in_app`: Die Person muss die App erst öffnen (Bericht Paket skeptiker, Entscheidung E1). */
    public const TTL_APP_SECONDS = 86400;
    public const MAX_OPEN_PER_KEY = 50;
    public const KEEP_SECONDS = 86400;
    public const ID_PATTERN = '/^mp_[0-9a-f]{24}$/';
    public const FILE = 'pending.json';

    private StateFile $file;
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock Unix-Zeit (Tests) */
    public function __construct(string $dataDir, private ?JsonStore $store = null, ?\Closure $clock = null)
    {
        $this->file = new StateFile($dataDir, self::FILE);
        $this->clock = $clock ?? fn(): int => time();
    }

    /**
     * Neuer Vorschlag. `$payload` ist schon normalisiert und geprüft (wie für
     * das Schreiben); `$summary` baut der Aufrufer aus Strukturdaten, nie aus
     * Dokumenttext. `$untrusted`: bereinigter Fremdtext für die Karte (Anbieter,
     * Zählername), steht nie in der Zusammenfassung.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $untrusted
     * @return array{id:string, kind:string, summary:string, key_name:string, status:string, created_at:string, expires_at:string}
     * @throws LocalizedException errors.mcp.proposal.limit
     */
    public function create(McpContext $ctx, string $kind, array $payload, string $summary, int $ttl = self::TTL_SECONDS, array $untrusted = []): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $kind)) throw new \LogicException("mcp: invalid proposal kind '$kind'");
        if (trim($summary) === '') throw new \LogicException('mcp: a proposal needs a summary');
        $now = ($this->clock)();
        return $this->file->update(function (array $state) use ($ctx, $kind, $payload, $summary, $now, $ttl, $untrusted): array {
            $state = $this->prune($state, $now);
            $open = 0;
            foreach ($state as $p) {
                if (($p['key_id'] ?? null) === $ctx->keyId && ($p['status'] ?? null) === 'open' && (int)$p['expires'] > $now) $open++;
            }
            if ($open >= self::MAX_OPEN_PER_KEY) {
                throw new LocalizedException('errors.mcp.proposal.limit', ['max' => self::MAX_OPEN_PER_KEY], 'too many open proposals');
            }
            $id = 'mp_' . bin2hex(random_bytes(12));
            $state[$id] = [
                'id' => $id, 'key_id' => $ctx->keyId, 'key_name' => $ctx->keyName, 'kind' => $kind,
                'payload' => $payload, 'summary' => $summary, 'status' => 'open',
                'created' => $now, 'expires' => $now + $ttl,
            ] + ($untrusted !== [] ? ['untrusted' => $untrusted] : []);
            return [$state, self::public($state[$id])];
        });
    }

    /**
     * Ein offener, gültiger Vorschlag dieses Schlüssels samt `payload`, sonst
     * null (unbekannt, fremd, abgelaufen, schon übernommen).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $id, McpContext $ctx): ?array
    {
        $p = $this->file->read()[$id] ?? null;
        $now = ($this->clock)();
        if (!is_array($p) || ($p['key_id'] ?? null) !== $ctx->keyId || ($p['status'] ?? null) !== 'open' || (int)$p['expires'] <= $now) return null;
        return self::public($p) + ['payload' => $p['payload'] ?? []];
    }

    /**
     * Prüft einen Aufruf von `apply_proposal`: fremd = unbekannt, abgelehnt, abweichende
     * Zusammenfassung. Gibt den Vorschlag zurück; Ablauf prüft der Aufrufer (je nach Weg).
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function checked(array $state, string $id, McpContext $ctx, string $summary): array
    {
        $p = preg_match(self::ID_PATTERN, $id) ? ($state[$id] ?? null) : null;
        // fremd = unbekannt: Ein anderer Schlüssel erfährt nicht, dass es ihn gibt
        if (!is_array($p) || ($p['key_id'] ?? null) !== $ctx->keyId) {
            throw new LocalizedException('errors.mcp.proposal.notFound', ['id' => $id], 'proposal not found');
        }
        // v3.12.0 (Paket oberflaeche) — in der App abgelehnt: nie übernehmen, und die KI erfährt warum
        if (($p['status'] ?? null) === 'rejected') {
            throw new LocalizedException('errors.mcp.proposal.rejected', ['id' => $id], 'proposal rejected');
        }
        if (self::normalize($summary) !== self::normalize((string)$p['summary'])) {
            throw new LocalizedException('errors.mcp.proposal.summaryMismatch', [], 'summary differs');
        }
        return $p;
    }

    /**
     * v3.12.0 (Skeptiker M1) — Wie steht der Vorschlag? Antwort von `apply_proposal` bei
     * `mcp_apply_in_app`: dieselben Prüfungen wie beim Übernehmen, geschrieben wird nichts.
     * `result` ist gesetzt, wenn er (in der App) schon übernommen ist.
     *
     * @return array{proposal: array<string,mixed>, result: array<string,mixed>|null}
     * @throws LocalizedException errors.mcp.proposal.notFound|rejected|summaryMismatch|expired
     */
    public function status(string $id, McpContext $ctx, string $summary): array
    {
        $now = ($this->clock)();
        $p = $this->checked($this->prune($this->file->read(), $now), $id, $ctx, $summary);
        if ($p['status'] === 'applied') return ['proposal' => self::public($p), 'result' => (array)($p['result'] ?? [])];
        if ((int)$p['expires'] <= $now) throw self::expired((int)$p['expires'] - (int)$p['created']);
        return ['proposal' => self::public($p), 'result' => null];
    }

    /**
     * Übernehmen, genau einmal. `$apply(payload, proposal)` schreibt über den
     * Dienst (die globale Sperre ist dann schon gehalten, also frisch lesen) und
     * gibt das Ergebnis zurück; es wird gemerkt und beim Wiederholen wieder
     * geliefert (`repeated: true`). Wirft `$apply`, bleibt der Vorschlag offen.
     * Über MCP nie länger als TTL_SECONDS, auch wenn er mit längerer Frist angelegt wurde.
     *
     * @param callable(array<string,mixed>, array<string,mixed>): array<string,mixed> $apply
     * @return array{result: array<string,mixed>, repeated: bool, proposal: array<string,mixed>}
     * @throws LocalizedException errors.mcp.proposal.notFound|expired|summaryMismatch
     */
    public function consume(string $id, McpContext $ctx, string $summary, callable $apply): array
    {
        $this->store?->lock();
        $now = ($this->clock)();
        return $this->file->update(function (array $state) use ($id, $ctx, $summary, $apply, $now): array {
            $state = $this->prune($state, $now);
            $p = $this->checked($state, $id, $ctx, $summary);
            if ($p['status'] === 'applied') {
                return [$state, ['result' => (array)($p['result'] ?? []), 'repeated' => true, 'proposal' => self::public($p)]];
            }
            if (min((int)$p['expires'], (int)$p['created'] + self::TTL_SECONDS) <= $now) {
                throw self::expired(self::TTL_SECONDS);
            }
            return $this->finish($state, $p, $apply, $now);
        });
    }

    /**
     * v3.12.0 (Skeptiker M1) — Übernehmen in der App (`POST /api/mcp/proposals/{id}/apply`):
     * dieselbe Schreibsperre, dasselbe `$apply`, einmalig wie über MCP. Keine Bindung an einen
     * Schlüssel (die Person ist Verwalter); der Vorschlag eines widerrufenen Schlüssels gilt nicht mehr.
     *
     * @param callable(array<string,mixed>, array<string,mixed>): array<string,mixed> $apply
     * @param list<string>|null $activeKeys gültige Schlüssel (null = nicht prüfen)
     * @return array{result: array<string,mixed>, repeated: bool, proposal: array<string,mixed>}
     * @throws LocalizedException errors.mcp.proposal.gone
     */
    public function consumeInApp(string $id, callable $apply, ?array $activeKeys = null, ?string $confirmedBy = null): array
    {
        $this->store?->lock();
        $now = ($this->clock)();
        return $this->file->update(function (array $state) use ($id, $apply, $activeKeys, $confirmedBy, $now): array {
            $state = $this->prune($state, $now);
            $p = preg_match(self::ID_PATTERN, $id) ? ($state[$id] ?? null) : null;
            if (!is_array($p) || ($p['status'] ?? null) !== 'open' || (int)($p['expires'] ?? 0) <= $now
                || ($activeKeys !== null && !in_array((string)($p['key_id'] ?? ''), $activeKeys, true))) {
                throw new LocalizedException('errors.mcp.proposal.gone', [], 'proposal gone');
            }
            return $this->finish($state, $p, $apply, $now, ['applied_in_app' => true, 'confirmed_by' => $confirmedBy]);
        });
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $p
     * @param array<string,mixed> $extra
     * @return array{0: array<string,mixed>, 1: array{result: array<string,mixed>, repeated: bool, proposal: array<string,mixed>}}
     */
    private function finish(array $state, array $p, callable $apply, int $now, array $extra = []): array
    {
        $result = $apply((array)($p['payload'] ?? []), self::public($p));
        $p['status'] = 'applied';
        $p['applied'] = $now;
        $p['result'] = $result;
        $state[(string)$p['id']] = array_filter($extra, fn($v) => $v !== null) + $p;
        return [$state, ['result' => $result, 'repeated' => false, 'proposal' => self::public($p)]];
    }

    private static function expired(int $seconds): LocalizedException
    {
        return $seconds > self::TTL_SECONDS
            ? new LocalizedException('errors.mcp.proposal.expiredHours', ['hours' => intdiv($seconds, 3600)], 'proposal expired')
            : new LocalizedException('errors.mcp.proposal.expired', ['minutes' => intdiv($seconds, 60)], 'proposal expired');
    }

    /** Verwerfen (nur der eigene, offene); true, wenn es ihn gab. */
    public function cancel(string $id, McpContext $ctx): bool
    {
        $now = ($this->clock)();
        return $this->file->update(function (array $state) use ($id, $ctx, $now): array {
            $state = $this->prune($state, $now);
            $p = $state[$id] ?? null;
            if (!is_array($p) || ($p['key_id'] ?? null) !== $ctx->keyId || $p['status'] !== 'open') return [$state, false];
            unset($state[$id]);
            return [$state, true];
        });
    }

    /**
     * v3.12.0 (Paket oberflaeche) — Ablehnen in der App (`DELETE /api/mcp/proposals/{id}`,
     * nur Verwalter): jeder offene, gültige Vorschlag, gleich von welchem Schlüssel.
     * Er bleibt ohne Nutzdaten als `rejected` stehen (bis einen Tag nach Ablauf), damit
     * `apply_proposal` „abgelehnt“ meldet statt „unbekannt“; übernehmen lässt er sich nie.
     * Nur die eigene Sperre (die Anfrage hält die globale schon, Reihenfolge wie consume()).
     */
    public function reject(string $id): bool
    {
        if (!preg_match(self::ID_PATTERN, $id)) return false;
        $now = ($this->clock)();
        return $this->file->update(function (array $state) use ($id, $now): array {
            $state = $this->prune($state, $now);
            $p = $state[$id] ?? null;
            if (!is_array($p) || ($p['status'] ?? null) !== 'open' || (int)($p['expires'] ?? 0) <= $now) return [$state, false];
            $p['status'] = 'rejected';
            $p['rejected'] = $now;
            unset($p['payload']);
            $state[$id] = $p;
            return [$state, true];
        });
    }

    /**
     * Offene, gültige Vorschläge aller Schlüssel (für die Oberfläche,
     * `GET /api/mcp/proposals`), ohne `payload`, älteste zuerst.
     * v3.12.0 (Skeptiker N8) — `$activeKeys`: Vorschläge widerrufener Schlüssel fallen weg
     * (übernehmen lassen sie sich nicht mehr, und niemand soll sie noch bestätigen).
     *
     * @param list<string>|null $activeKeys gültige Schlüssel (null = nicht prüfen)
     * @return list<array<string,mixed>>
     */
    public function listOpen(?array $activeKeys = null): array
    {
        $now = ($this->clock)();
        $out = [];
        foreach ($this->file->read() as $p) {
            if (!is_array($p) || ($p['status'] ?? null) !== 'open' || (int)($p['expires'] ?? 0) <= $now) continue;
            if ($activeKeys !== null && !in_array((string)($p['key_id'] ?? ''), $activeKeys, true)) continue;
            $out[] = self::public($p);
        }
        usort($out, fn($a, $b) => strcmp($a['created_at'] . $a['id'], $b['created_at'] . $b['id']));
        return $out;
    }

    /**
     * `client_ref` einer Ablesung aus einem Vorschlag: Der Bindestrich passt zum
     * Muster von ReadingService (`[A-Za-z0-9-]{8,64}`), ein zweites Schreiben
     * desselben Vorschlags wird dort zum Duplikat.
     */
    public static function clientRef(string $id): string
    {
        return str_replace('_', '-', $id);
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function prune(array $state, int $now): array
    {
        foreach ($state as $id => $p) {
            $since = !is_array($p) ? 0 : (int)(($p['status'] ?? '') === 'applied' ? ($p['applied'] ?? 0) : ($p['expires'] ?? 0));
            if (!is_array($p) || $since <= $now - self::KEEP_SECONDS) unset($state[$id]);
        }
        return $state;
    }

    /**
     * Ein Vorschlag ohne Nutzdaten und Ergebnis.
     *
     * @param array<string,mixed> $p
     * @return array{id:string, kind:string, summary:string, key_name:string, status:string, created_at:string, expires_at:string}
     */
    private static function public(array $p): array
    {
        return [
            'id' => (string)$p['id'], 'kind' => (string)$p['kind'], 'summary' => (string)$p['summary'],
            'key_name' => (string)($p['key_name'] ?? ''), 'status' => (string)$p['status'],
            'created_at' => date('c', (int)$p['created']), 'expires_at' => date('c', (int)$p['expires']),
        ] + (is_array($p['untrusted'] ?? null) && $p['untrusted'] !== [] ? ['untrusted' => $p['untrusted']] : []);
    }

    private static function normalize(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }
}
