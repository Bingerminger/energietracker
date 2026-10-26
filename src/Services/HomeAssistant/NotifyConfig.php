<?php
declare(strict_types=1);

namespace Energietracker\Services\HomeAssistant;

use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.9.0 (F1033, GitHub #36) — Einstellungen der Benachrichtigungen an Home
 * Assistant. Muster wie Inbox\CloudConfig:
 *
 *   - Schalter, Ziel, Adresse, Aktion, Auswahl der Ereignisse und Uhrzeit der
 *     täglichen Prüfung stehen in `settings.json` (`ha_notify_*`, im Backup).
 *   - Webhook-ID und Token stehen in SECRET_FILE (Rechte 0600): nie in
 *     `settings.json`, also nie in `GET /api/settings`, im Backup oder in einem
 *     Snapshot; nie zurück an den Browser (die API meldet nur `has_webhook`
 *     und `has_token`). Nach dem Einspielen einer Sicherung auf einem anderen
 *     Server fehlen sie, dort geht ohne neue Einrichtung nichts hinaus.
 *   - Der Token des Supervisors (`SUPERVISOR_TOKEN`, nur in der App für Home
 *     Assistant mit `homeassistant_api: true`) kommt aus der Umgebung und wird
 *     nirgends gespeichert.
 *
 * Drei Ziele:
 *   supervisor  App in Home Assistant: http://supervisor/core/api/services/<domain>/<service>
 *               mit dem Token des Supervisors (Doku: contributors.home-assistant.io,
 *               „Communication“, geprüft 2026-10-26)
 *   webhook     <Adresse>/api/webhook/<id>: eine eigene Automation entscheidet,
 *               was mit der Nachricht geschieht (kein Token nötig)
 *   token       <Adresse>/api/services/<domain>/<service> mit einem
 *               Long-Lived-Token aus dem Profil in Home Assistant
 *
 * Einschalten, Adresse und Aktion ändert nur ein Verwalter (App::adminOnly).
 */
final class NotifyConfig
{
    public const TARGETS = ['supervisor', 'webhook', 'token'];
    /**
     * Ereignisse: Termine (Art `reminder`), alle übrigen Punkte aus „Zu tun“
     * (Fristen, Ablesungen, Abrechnungen und jede neue Art, ohne Sonderfall),
     * Warnungen (Empfehlungen der Stufe Warnung oder dringend) und neue
     * Dokumente im Posteingang.
     */
    public const EVENTS = ['reminder', 'deadline', 'warning', 'document'];
    public const SECRET_FILE = 'ha_notify_secret.json';
    public const SUPERVISOR_API = 'http://supervisor/core/api';
    public const DEFAULT_SERVICE = 'persistent_notification.create';
    /** `persistent_notification.create` oder ein Benachrichtigungsdienst `notify.<name>` (Daten title, message). */
    public const SERVICE_PATTERN = '/^(persistent_notification\.create|notify\.[a-z0-9_]{1,100})$/';

    /** @var callable(string): (string|false) */
    private $env;

    public function __construct(private SettingsService $settings, private JsonStore $store, ?callable $env = null)
    {
        $this->env = $env ?? static fn(string $k): string|false => getenv($k);
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('ha_notify_enabled', false);
    }

    public function target(): string
    {
        $t = (string)$this->settings->get('ha_notify_target', 'supervisor');
        return in_array($t, self::TARGETS, true) ? $t : 'supervisor';
    }

    /** Adresse von Home Assistant ohne Schrägstrich am Ende (Webhook und Token). */
    public function url(): string
    {
        return rtrim(trim((string)$this->settings->get('ha_notify_url', '')), '/');
    }

    public function service(): string
    {
        $s = (string)$this->settings->get('ha_notify_service', self::DEFAULT_SERVICE);
        return preg_match(self::SERVICE_PATTERN, $s) ? $s : self::DEFAULT_SERVICE;
    }

    /** @return list<string> */
    public function events(): array
    {
        $e = $this->settings->get('ha_notify_events', self::EVENTS);
        return is_array($e) ? array_values(array_intersect(self::EVENTS, $e)) : self::EVENTS;
    }

    public function wants(string $event): bool
    {
        return in_array($event, $this->events(), true);
    }

    /** Stunde (0–23), ab der die tägliche Prüfung läuft. */
    public function hour(): int
    {
        return max(0, min(23, (int)$this->settings->get('ha_notify_hour', 8)));
    }

    /** Nur für den Versand — nie in eine Antwort, ein Log oder eine Meldung. */
    public function supervisorToken(): string
    {
        $t = ($this->env)('SUPERVISOR_TOKEN');
        return is_string($t) ? trim($t) : '';
    }

    public function supervisorAvailable(): bool
    {
        return $this->supervisorToken() !== '';
    }

    /** Nur für den Versand — nie in eine Antwort, ein Log oder eine Meldung. */
    public function webhookId(): string
    {
        return (string)($this->secrets()['webhook_id'] ?? '');
    }

    /** Nur für den Versand — nie in eine Antwort, ein Log oder eine Meldung. */
    public function token(): string
    {
        return (string)($this->secrets()['token'] ?? '');
    }

    /** Ist das Ziel vollständig eingerichtet (ohne Rücksicht auf den Schalter)? */
    public function complete(?string $target = null): bool
    {
        return match ($target ?? $this->target()) {
            'supervisor' => $this->supervisorAvailable(),
            'webhook'    => $this->url() !== '' && $this->webhookId() !== '',
            'token'      => $this->url() !== '' && $this->token() !== '',
            default      => false,
        };
    }

    /** Sendet die tägliche Prüfung bzw. ein neues Dokument wirklich? Eingeschaltet und vollständig. */
    public function ready(): bool
    {
        return $this->enabled() && $this->complete();
    }

    /**
     * Was die Oberfläche sehen darf (ohne Webhook-ID, ohne Token).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return [
            'enabled'              => $this->enabled(),
            'target'               => $this->target(),
            'url'                  => $this->url(),
            'service'              => $this->service(),
            'events'               => $this->events(),
            'hour'                 => $this->hour(),
            'has_webhook'          => $this->webhookId() !== '',
            'has_token'            => $this->token() !== '',
            'supervisor_available' => $this->supervisorAvailable(),
            'complete'             => $this->complete(),
            'ready'                => $this->ready(),
            'targets'              => self::TARGETS,
            'all_events'           => self::EVENTS,
        ];
    }

    /**
     * Karte „Benachrichtigungen“ speichern (PATCH /api/homeassistant/notify):
     * `{enabled, target, url, service, events, hour, webhook_id, token,
     * clear_webhook, clear_token}`, alles optional. Ein leeres Geheimnis lässt
     * das gespeicherte stehen. Eingeschaltet wird nur ein vollständiges Ziel.
     * Geprüft wird alles, bevor etwas geschrieben wird.
     *
     * @param array<string,mixed> $in
     */
    public function update(array $in): void
    {
        $patch = [];
        foreach (['target', 'url', 'service', 'events', 'hour'] as $k) {
            if (array_key_exists($k, $in)) $patch["ha_notify_$k"] = self::normalizeSetting("ha_notify_$k", $in[$k]);
        }
        $webhook = array_key_exists('webhook_id', $in) ? self::webhookFrom($in['webhook_id']) : '';
        $token = array_key_exists('token', $in) ? self::tokenFrom($in['token']) : '';
        $secrets = $this->secrets();
        if (!empty($in['clear_webhook'])) unset($secrets['webhook_id']);
        if (!empty($in['clear_token'])) unset($secrets['token']);
        if ($webhook !== '') $secrets['webhook_id'] = $webhook;
        if ($token !== '') $secrets['token'] = $token;

        $enabled = array_key_exists('enabled', $in) ? $in['enabled'] : $this->enabled();
        if (!is_bool($enabled)) {
            throw new LocalizedException('errors.settings.valueInvalid', ['key' => 'ha_notify_enabled', 'value' => is_scalar($enabled) ? (string)$enabled : gettype($enabled)], 'ha_notify_enabled');
        }
        if ($enabled) {
            $target = (string)($patch['ha_notify_target'] ?? $this->target());
            $url = (string)($patch['ha_notify_url'] ?? $this->url());
            $ok = match ($target) {
                'supervisor' => $this->supervisorAvailable(),
                'webhook'    => $url !== '' && ($secrets['webhook_id'] ?? '') !== '',
                'token'      => $url !== '' && ($secrets['token'] ?? '') !== '',
                default      => false,
            };
            if (!$ok) throw new LocalizedException('errors.haNotify.incomplete', [], "ha notify: target $target incomplete");
        }
        $patch['ha_notify_enabled'] = $enabled;
        $this->settings->set($patch);
        if ($secrets !== $this->secrets()) $this->writeSecrets($secrets);
    }

    /**
     * Eine Stelle für die Prüfung der Schlüssel `ha_notify_*` — auch, wenn sie
     * über PATCH /api/settings kommen (SettingsService::set ruft sie auf).
     */
    public static function normalizeSetting(string $key, mixed $v): mixed
    {
        $bad = static fn() => throw new LocalizedException('errors.settings.valueInvalid',
            ['key' => $key, 'value' => is_scalar($v) ? mb_substr((string)$v, 0, 80) : gettype($v)], "invalid $key");
        switch ($key) {
            case 'ha_notify_enabled':
                return (bool)$v;
            case 'ha_notify_target':
                return is_string($v) && in_array($v, self::TARGETS, true) ? $v : $bad();
            case 'ha_notify_url':
                if (!SettingsService::isHttpUrlOrEmpty($v)) $bad();
                $u = rtrim(trim((string)$v), '/');
                $p = parse_url($u);
                // nur die Adresse von Home Assistant, ohne Pfad, Abfrage und Anker
                if ($u !== '' && (isset($p['query']) || isset($p['fragment']) || (($p['path'] ?? '') !== ''))) $bad();
                return $u;
            case 'ha_notify_service':
                $s = is_string($v) ? trim($v) : '';
                return preg_match(self::SERVICE_PATTERN, $s) && $s !== 'notify.send_message' ? $s : $bad();
            case 'ha_notify_events':
                if (!is_array($v)) $bad();
                foreach ($v as $e) if (!is_string($e) || !in_array($e, self::EVENTS, true)) $bad();
                return array_values(array_intersect(self::EVENTS, $v));
            case 'ha_notify_hour':
                return is_numeric($v) && (int)$v == $v && (int)$v >= 0 && (int)$v <= 23 ? (int)$v : $bad();
        }
        return $v;
    }

    /**
     * Webhook-ID: wie Home Assistant sie vergibt (Buchstaben, Ziffern, `-`,
     * `_`). Eine ganze Adresse `…/api/webhook/<id>` wird auf die ID gekürzt.
     */
    public static function webhookFrom(mixed $v): string
    {
        if ($v === null) return '';
        if (!is_string($v)) throw new LocalizedException('errors.haNotify.webhookFormat', [], 'webhook id: not a string');
        $v = trim($v);
        if (preg_match('#/api/webhook/([^/?\#\s]+)#', $v, $m)) $v = $m[1];
        if ($v !== '' && !preg_match('/^[A-Za-z0-9_-]{8,200}$/', $v)) {
            throw new LocalizedException('errors.haNotify.webhookFormat', [], 'webhook id: letters, digits, - and _, 8 to 200');
        }
        return $v;
    }

    public static function tokenFrom(mixed $v): string
    {
        if ($v === null) return '';
        if (!is_string($v)) throw new LocalizedException('errors.haNotify.tokenFormat', [], 'token: not a string');
        $v = trim($v);
        if (str_starts_with(strtolower($v), 'bearer ')) $v = trim(substr($v, 7));
        if ($v !== '' && !preg_match('/^[\x21-\x7E]{20,2000}$/', $v)) {
            throw new LocalizedException('errors.haNotify.tokenFormat', [], 'token: printable characters, 20 to 2000');
        }
        return $v;
    }

    /** @return array<string,string> */
    private function secrets(): array
    {
        $s = $this->store->exists(self::SECRET_FILE) ? $this->store->read(self::SECRET_FILE, []) : [];
        return is_array($s) ? array_filter($s, fn($x) => is_string($x) && $x !== '') : [];
    }

    /** @param array<string,string> $secrets */
    private function writeSecrets(array $secrets): void
    {
        if ($secrets === []) {
            $this->store->delete(self::SECRET_FILE);
            return;
        }
        $this->store->write(self::SECRET_FILE, $secrets);
        @chmod($this->store->path(self::SECRET_FILE), 0600);   // nur der Webserver-Benutzer liest sie
    }
}
