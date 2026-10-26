<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Services\HomeAssistant\NotifyConfig;
use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\HomeAssistant\Todo;
use Energietracker\Storage\JsonStore;

/**
 * v3.9.0 (F1033, GitHub #36) — Energietracker meldet sich selbst bei Home
 * Assistant: fällige Termine, Fristen und weitere Punkte aus „Zu tun“,
 * Warnungen und neue Dokumente im Posteingang.
 *
 * Wann:
 *   - Dokument eingegangen: beim Ablegen (InboxService::onUpload — Browser,
 *     Kurzbefehl, E-Mail über Home Assistant, überwachter Ordner). Gesendet
 *     wird nach der Antwort; kommen mehrere kurz nacheinander (ein Stapel),
 *     fasst eine Nachricht nach DOC_DEBOUNCE_S den Rest zusammen.
 *   - Tägliche Prüfung: einmal am Tag ab der eingestellten Stunde
 *     (`ha_notify_hour`), mit der ersten Anfrage danach. Die App hat keinen
 *     eigenen Zeitplan (kein Cron im Image, wie Wetterabgleich und
 *     Versionshinweis); im Docker-Image und in der App für Home Assistant
 *     ruft der Gesundheitscheck die App alle 30 Sekunden auf, sonst genügt
 *     jede Anfrage — etwa der Sensor „Zu tun“ aus Home Assistant.
 *   - Beides läuft nach der Antwort (App::afterResponse, fastcgi_finish_request).
 *
 * Jede Nachricht nur einmal: LOG_FILE führt die gesendeten Schlüssel —
 * `todo:<Vorkommen>:<Stufe>` (AgendaService::occurrence, ein neuer Termin
 * oder eine verschärfte Stufe wie „dringend“ ist eine neue Nachricht),
 * `warn:<Empfehlung>:<Stufe>`, `doc:<Dokument>`. Einträge älter als KEEP_DAYS
 * fallen heraus.
 *
 * Das Protokoll gehört NICHT ins Backup (BackupSafetyTest führt es als
 * bewusst ausgenommen): Es beschreibt, was diese Installation an dieses Home
 * Assistant geschickt hat — wie Webhook-ID und Token, die ebenfalls auf dem
 * Server bleiben. Ein Import ersetzt nur die Töpfe des Backups; das
 * Protokoll bleibt stehen, also kommt nach einer Wiederherstellung auf
 * demselben Server nichts doppelt. Auf einem neuen Server geht ohne neue
 * Einrichtung ohnehin nichts hinaus; danach kommen die offenen Punkte einmal,
 * höchstens MAX_PER_RUN Nachrichten (der Rest in einer Sammelnachricht).
 *
 * Sprache: die der Installation (`language`), nicht die des Geräts, dessen
 * Anfrage die Prüfung zufällig auslöst.
 */
final class HomeAssistantNotifyService
{
    public const LOG_FILE = 'ha_notify_log.json';
    public const LOCK_FILE = '.ha-notify.lock';
    public const MAX_PER_RUN = 5;
    public const KEEP_DAYS = 400;
    public const DOC_DEBOUNCE_S = 60;
    public const RETRY_S = 1800;
    public const HORIZON_DAYS = 365;
    /** Empfehlungen dieser Kategorien stehen schon als Punkt in „Zu tun“ (Frist, Bestand). */
    private const IN_TODO = ['vertrag', 'bestand'];
    private const MORE_LINES = 10;

    /** @var list<string> in dieser Anfrage abgelegte Dokumente */
    private array $arrived = [];
    /** @var callable(): int */
    private $clock;
    /** @var \Closure(int, string): list<array<string,mixed>> Ereignisse der Agenda (AgendaService::events) */
    private \Closure $events;
    /** @var \Closure(): list<array<string,mixed>> Empfehlungen (RecommendationService::all) */
    private \Closure $warnings;
    /** @var \Closure(): list<array<string,mixed>> Einträge des Posteingangs (InboxService::all) */
    private \Closure $documents;

    /**
     * Die Quellen kommen als Funktionen: Ein Test kann so jede Art von
     * Ereignis einspeisen, auch eine, die es heute noch nicht gibt.
     *
     * @param callable(int, string): list<array<string,mixed>> $events
     * @param callable(): list<array<string,mixed>> $warnings
     * @param callable(): list<array<string,mixed>> $documents
     */
    public function __construct(
        private NotifyConfig $cfg,
        private NotifyTransport $transport,
        private JsonStore $store,
        private I18nService $i18n,
        private InstanceService $instance,
        callable $events,
        callable $warnings,
        callable $documents,
        ?callable $clock = null,
    ) {
        $this->events = $events(...);
        $this->warnings = $warnings(...);
        $this->documents = $documents(...);
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function config(): NotifyConfig
    {
        return $this->cfg;
    }

    /** Hook für InboxService::onUpload — merkt sich das Dokument, gesendet wird nach der Antwort. */
    public function documentArrived(array $entry): void
    {
        if (isset($entry['id'])) $this->arrived[] = (string)$entry['id'];
    }

    /**
     * Gibt es nach dieser Antwort etwas zu tun? Billig (Einstellungen und das
     * kleine Protokoll), läuft nach jeder Anfrage.
     */
    public function due(?int $now = null): bool
    {
        if (!$this->cfg->ready()) return false;
        $now ??= ($this->clock)();
        $log = $this->log();
        if ($this->cfg->wants('document') && ($this->arrived !== [] || !empty($log['docs_pending']))) return true;
        return $this->dailyDue($log, $now);
    }

    /**
     * Status für die Karte (ohne Geheimnisse): Einstellungen, letzter Versand.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $log = $this->log();
        return $this->cfg->status() + [
            'last'       => is_array($log['last'] ?? null) ? $log['last'] : null,
            'daily_on'   => $log['daily_on'] ?? null,
            'sent_count' => is_array($log['sent'] ?? null) ? count($log['sent']) : 0,
        ];
    }

    /**
     * Einstellungen speichern; beim Einschalten zählen ab jetzt nur neue
     * Dokumente (die alten im Posteingang lösen keine Nachricht aus).
     *
     * @param array<string,mixed> $in
     */
    public function update(array $in): array
    {
        $wasOn = $this->cfg->enabled();
        $this->cfg->update($in);
        if (!$wasOn && $this->cfg->enabled()) {
            $log = $this->log();
            $log['docs_after'] = date('c', ($this->clock)());
            $log['docs_pending'] = false;
            $log['retry_after'] = 0;
            $this->saveLog($log, ($this->clock)());
        }
        return $this->status();
    }

    /**
     * Lauf nach der Antwort: neue Dokumente, dann die tägliche Prüfung.
     * Zwei gleichzeitige Läufe schließt eine eigene Sperre aus (nicht
     * blockierend: der zweite lässt es).
     *
     * @return array{documents:?array, daily:?array, busy?:bool}
     */
    public function tick(?int $now = null): array
    {
        $now ??= ($this->clock)();
        $out = ['documents' => null, 'daily' => null];
        if (!$this->cfg->ready()) { $this->arrived = []; return $out; }
        $fp = @fopen(rtrim($this->store->rootDir(), '/') . '/' . self::LOCK_FILE, 'c');
        if ($fp === false) return $out;
        if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return $out + ['busy' => true]; }
        try {
            $log = $this->log();
            $this->inInstallationLanguage(function () use (&$log, &$out, $now): void {
                if ($this->cfg->wants('document')) $out['documents'] = $this->documents($log, $now);
                if ($this->dailyDue($log, $now)) $out['daily'] = $this->daily($log, $now);
            });
            $this->saveLog($log, $now);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            $this->arrived = [];
        }
        return $out;
    }

    /**
     * „Testnachricht senden“ — auch vor dem Einschalten, sobald das Ziel
     * vollständig ist. Zählt nicht als gesendeter Punkt.
     *
     * @return array{ok:bool, status:?int, error:?string, target:string}
     */
    public function sendTest(): array
    {
        $target = $this->cfg->target();
        if (!$this->cfg->complete()) return ['ok' => false, 'status' => null, 'error' => 'incomplete', 'target' => $target];
        $r = null;
        $this->inInstallationLanguage(function () use (&$r): void {
            $r = $this->send([
                'key' => 'test', 'category' => 'test', 'kind' => 'test', 'date' => date('Y-m-d', ($this->clock)()), 'severity' => 'info',
                'title' => $this->i18n->t('haNotify.title.test'), 'message' => $this->i18n->t('haNotify.test'),
            ]);
        });
        $log = $this->log();
        $log['last'] = $this->lastEntry($r, 'test');
        $this->saveLog($log, ($this->clock)());
        return $r + ['target' => $target];
    }

    // ── Dokumente ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $log */
    private function documents(array &$log, int $now): ?array
    {
        if ($this->arrived === [] && empty($log['docs_pending'])) return null;
        // Ohne Einschalten über die Karte (etwa per PATCH /api/settings) gibt es keine
        // Marke: Neu ist dann, was in den letzten fünf Minuten kam, nie der alte Bestand.
        if (!is_string($log['docs_after'] ?? null)) $log['docs_after'] = date('c', $now - 300);
        if ((int)($log['retry_after'] ?? 0) > $now) { $log['docs_pending'] = true; return ['waiting' => true]; }
        if ($now - (int)($log['docs_sent_at'] ?? 0) < self::DOC_DEBOUNCE_S) {
            $log['docs_pending'] = true;
            return ['debounced' => true];
        }
        $after = strtotime((string)$log['docs_after']) ?: $now;
        $sent = is_array($log['sent'] ?? null) ? $log['sent'] : [];
        $new = [];
        foreach (($this->documents)() as $e) {
            $id = (string)$e['id'];
            if (isset($sent["doc:$id"])) continue;
            // ≥: ein zweites Dokument in derselben Sekunde wie das zuletzt gemeldete zählt mit
            $created = strtotime((string)($e['created_at'] ?? '')) ?: 0;
            if (in_array($id, $this->arrived, true) || $created >= $after) $new[] = $e;
        }
        if ($new === []) { $log['docs_pending'] = false; return ['sent' => 0]; }
        $names = array_map(fn($e) => (string)($e['name'] ?? ''), $new);
        $n = count($new);
        $r = $this->send([
            'key' => 'doc:' . (string)$new[0]['id'], 'category' => 'document', 'kind' => 'document',
            'date' => date('Y-m-d', $now), 'severity' => 'info',
            'title' => $this->i18n->t('haNotify.title.document'),
            'message' => $n === 1
                ? $this->i18n->t('haNotify.document.single', ['name' => $names[0]])
                : $this->i18n->tp('haNotify.document.many', $n, ['names' => self::listText($names)]),
            'items' => array_map(fn($e) => ['id' => (string)$e['id'], 'name' => (string)($e['name'] ?? '')], $new),
        ]);
        $log['last'] = $this->lastEntry($r, 'document');
        $log['docs_sent_at'] = $now;
        if (!$r['ok']) {
            $log['docs_pending'] = true;
            $log['retry_after'] = $now + self::RETRY_S;
            return ['sent' => 0, 'error' => $r['error']];
        }
        foreach ($new as $e) $log['sent']['doc:' . (string)$e['id']] = date('c', $now);
        $latest = max(array_map(fn($e) => strtotime((string)($e['created_at'] ?? '')) ?: 0, $new));
        if ($latest > (strtotime((string)$log['docs_after']) ?: 0)) $log['docs_after'] = date('c', $latest);
        $log['docs_pending'] = false;
        return ['sent' => $n];
    }

    // ── tägliche Prüfung ────────────────────────────────────────────────

    /** @param array<string,mixed> $log */
    private function dailyDue(array $log, int $now): bool
    {
        if (!$this->cfg->wants('reminder') && !$this->cfg->wants('deadline') && !$this->cfg->wants('warning')) return false;
        if (($log['daily_on'] ?? null) === date('Y-m-d', $now)) return false;
        if ((int)date('G', $now) < $this->cfg->hour()) return false;
        return (int)($log['retry_after'] ?? 0) <= $now;
    }

    /** @param array<string,mixed> $log */
    private function daily(array &$log, int $now): array
    {
        $today = date('Y-m-d', $now);
        $sent = is_array($log['sent'] ?? null) ? $log['sent'] : [];
        $items = [];
        if ($this->cfg->wants('reminder') || $this->cfg->wants('deadline')) {
            foreach (Todo::due(($this->events)(self::HORIZON_DAYS, $today)) as $e) {
                $cat = ($e['kind'] ?? '') === 'reminder' ? 'reminder' : 'deadline';
                if (!$this->cfg->wants($cat)) continue;
                $key = 'todo:' . (string)($e['occurrence'] ?? $e['uid'] ?? '') . ':' . (string)($e['severity'] ?? 'due');
                if (isset($sent[$key])) continue;
                $items[] = ['key' => $key, 'category' => $cat, 'kind' => (string)($e['kind'] ?? ''), 'date' => (string)($e['date'] ?? $today),
                            'severity' => (string)($e['severity'] ?? 'due'), 'title' => $this->i18n->t("haNotify.title.$cat"),
                            'message' => trim((string)($e['title'] ?? '') . "\n" . $this->when($e, $today)), 'subject' => (string)($e['title'] ?? '')];
            }
        }
        if ($this->cfg->wants('warning')) {
            foreach (($this->warnings)() as $r) {
                if (!in_array($r['severity'] ?? '', ['warning', 'urgent'], true) || in_array($r['category'] ?? '', self::IN_TODO, true)) continue;
                $key = 'warn:' . (string)$r['id'] . ':' . (string)$r['severity'];
                if (isset($sent[$key])) continue;
                $items[] = ['key' => $key, 'category' => 'warning', 'kind' => (string)($r['category'] ?? ''), 'date' => $today,
                            'severity' => (string)$r['severity'], 'title' => $this->i18n->t('haNotify.title.warning'),
                            'message' => trim((string)($r['title'] ?? '') . "\n" . (string)($r['detail'] ?? '')), 'subject' => (string)($r['title'] ?? '')];
            }
        }
        $single = count($items) > self::MAX_PER_RUN ? array_slice($items, 0, self::MAX_PER_RUN - 1) : $items;
        $rest = array_slice($items, count($single));
        $done = 0;
        foreach ($single as $it) {
            $r = $this->send($it);
            $log['last'] = $this->lastEntry($r, $it['category']);
            if (!$r['ok']) return $this->failed($log, $now, $done, $r['error']);
            $log['sent'][$it['key']] = date('c', $now);
            $done++;
        }
        if ($rest !== []) {
            $lines = array_map(fn($it) => '• ' . $it['subject'], array_slice($rest, 0, self::MORE_LINES));
            if (count($rest) > self::MORE_LINES) $lines[] = '…';
            $r = $this->send(['key' => 'more:' . $today, 'category' => 'more', 'kind' => 'more', 'date' => $today, 'severity' => 'info',
                'title' => $this->i18n->t('haNotify.title.more'),
                'message' => $this->i18n->tp('haNotify.more', count($rest)) . "\n" . implode("\n", $lines),
                'items' => array_map(fn($it) => ['key' => $it['key'], 'category' => $it['category'], 'kind' => $it['kind'], 'title' => $it['subject'], 'date' => $it['date']], $rest)]);
            $log['last'] = $this->lastEntry($r, 'more');
            if (!$r['ok']) return $this->failed($log, $now, $done, $r['error']);
            foreach ($rest as $it) $log['sent'][$it['key']] = date('c', $now);
            $done += count($rest);
        }
        $log['daily_on'] = $today;
        $log['retry_after'] = 0;
        return ['sent' => $done, 'messages' => count($single) + ($rest !== [] ? 1 : 0)];
    }

    /** @param array<string,mixed> $log */
    private function failed(array &$log, int $now, int $done, ?string $error): array
    {
        $log['retry_after'] = $now + self::RETRY_S;   // später heute noch einmal, nicht bei jeder Anfrage
        return ['sent' => $done, 'error' => $error];
    }

    /** Wann: überfällig seit, heute, am Datum — in der Sprache der Installation. */
    private function when(array $e, string $today): string
    {
        $date = (string)($e['date'] ?? '');
        if ($date === '') return '';
        if (($e['severity'] ?? '') === 'overdue' || $date < $today) return $this->i18n->t('haNotify.when.overdue', ['date' => $this->i18n->date($date)]);
        if ($date === $today) return $this->i18n->t('haNotify.when.today');
        return $this->i18n->t('haNotify.when.on', ['date' => $this->i18n->date($date)]);
    }

    /** @param list<string> $names */
    private static function listText(array $names): string
    {
        $shown = array_slice($names, 0, self::MORE_LINES);
        return implode(', ', $shown) . (count($names) > count($shown) ? ' …' : '');
    }

    // ── Versand ─────────────────────────────────────────────────────────

    /**
     * Eine Nachricht an das eingestellte Ziel.
     *
     * @param array<string,mixed> $n {key, category, kind, date, severity, title, message, items?}
     * @return array{ok:bool, status:?int, error:?string}
     */
    private function send(array $n): array
    {
        $target = $this->cfg->target();
        if ($target === 'webhook') {
            $payload = [
                'source' => 'energietracker', 'event' => $n['category'], 'kind' => $n['kind'], 'key' => $n['key'],
                'title' => $n['title'], 'message' => $n['message'], 'date' => $n['date'], 'severity' => $n['severity'],
                'language' => $this->i18n->locale(), 'instance_id' => $this->instance->id(), 'sent_at' => date('c', ($this->clock)()),
            ] + (isset($n['items']) ? ['items' => $n['items']] : []);
            return $this->transport->post($this->cfg->url() . '/api/webhook/' . rawurlencode($this->cfg->webhookId()), $payload);
        }
        [$domain, $service] = explode('.', $this->cfg->service(), 2);
        $data = ['title' => $n['title'], 'message' => $n['message']];
        // dieselbe ID überschreibt die Meldung in Home Assistant, statt eine zweite anzulegen
        if ($domain === 'persistent_notification') $data['notification_id'] = 'energietracker_' . substr(hash('sha256', (string)$n['key']), 0, 16);
        $base = $target === 'supervisor' ? NotifyConfig::SUPERVISOR_API : $this->cfg->url() . '/api';
        $token = $target === 'supervisor' ? $this->cfg->supervisorToken() : $this->cfg->token();
        return $this->transport->post("$base/services/$domain/$service", $data, ['Authorization: Bearer ' . $token]);
    }

    /** @param array{ok:bool, status:?int, error:?string} $r */
    private function lastEntry(array $r, string $category): array
    {
        return ['at' => date('c', ($this->clock)()), 'ok' => $r['ok'], 'status' => $r['status'], 'error' => $r['error'], 'category' => $category];
    }

    private function inInstallationLanguage(callable $fn): void
    {
        $prev = $this->i18n->locale();
        $this->i18n->setLocale($this->i18n->installationLocale());
        try {
            $fn();
        } finally {
            $this->i18n->setLocale($prev);
        }
    }

    // ── Protokoll ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function log(): array
    {
        $log = $this->store->exists(self::LOG_FILE) ? $this->store->read(self::LOG_FILE, []) : [];
        if (!is_array($log)) $log = [];
        if (!is_array($log['sent'] ?? null)) $log['sent'] = [];
        return $log;
    }

    /** @param array<string,mixed> $log */
    private function saveLog(array $log, int $now): void
    {
        $cut = $now - self::KEEP_DAYS * 86400;
        $log['sent'] = array_filter($log['sent'] ?? [], fn($at) => is_string($at) && (strtotime($at) ?: 0) >= $cut);
        $this->store->write(self::LOG_FILE, $log);
    }
}
