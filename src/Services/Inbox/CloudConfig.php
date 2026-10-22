<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Paket cloud) — Einstellungen für das Auslesen in der Cloud (Opt-in).
 *
 * Anbieter, Adresse, Modell, Zeitlimit und der Schalter stehen in
 * `settings.json` (Schlüssel `inbox_cloud_*`, im Backup). Der API-Schlüssel
 * steht in einer eigenen Datei (KEY_FILE, Rechte 0600):
 *   - nie in `settings.json`, also nie in `GET /api/settings`, im Backup oder
 *     in einem Snapshot (BackupService sichert nur seine Töpfe);
 *   - nie zurück an den Browser — die API meldet nur `has_key`;
 *   - nach dem Einspielen einer Sicherung auf einem anderen Server fehlt er,
 *     dort geht ohne neuen Schlüssel nichts hinaus.
 *
 * Ohne Opt-in verlässt kein Dokument das Heimnetz: Ein Anschluss sendet nur,
 * wenn er eingeschaltet, gewählt und mit Schlüssel versehen ist (allows()).
 * Einschalten darf nur ein Verwalter (App::adminOnly).
 */
final class CloudConfig
{
    public const PROVIDERS = ['anthropic', 'openai'];
    public const KEY_FILE = 'inbox_cloud_key.json';

    /**
     * v3.7.0 (Karte „Auslesen“) — Voreinstellungen. Alle OpenAI-kompatiblen
     * laufen über OpenAiCompatibleProvider und CloudHttp (nur HTTPS, nie das
     * Heimnetz, geprüfte Adresse, keine Weiterleitung); sie füllen nur Anbieter
     * und Adresse vor. Welche gilt, folgt aus `inbox_cloud_provider` und
     * `inbox_cloud_endpoint` (preset()) — keine eigene Einstellung, also nichts
     * zu migrieren: eine andere Adresse ist „eigene Adresse“ (`custom`).
     * Adressen laut der Doku der Anbieter (geprüft 2026-10-21):
     *   OpenAI      https://api.openai.com/v1         (GET /models, Bearer)
     *   Mistral     https://api.mistral.ai/v1         (POST /v1/chat/completions, GET /v1/models)
     *   OpenRouter  https://openrouter.ai/api/v1      (GET /api/v1/models)
     *   Gemini      https://generativelanguage.googleapis.com/v1beta/openai  (OpenAI-Kompatibilität)
     */
    public const PRESETS = [
        'anthropic'  => ['provider' => 'anthropic', 'endpoint' => null, 'label' => AnthropicProvider::LABEL],
        'openai'     => ['provider' => 'openai', 'endpoint' => 'https://api.openai.com/v1', 'label' => 'OpenAI'],
        'mistral'    => ['provider' => 'openai', 'endpoint' => 'https://api.mistral.ai/v1', 'label' => 'Mistral'],
        'openrouter' => ['provider' => 'openai', 'endpoint' => 'https://openrouter.ai/api/v1', 'label' => 'OpenRouter'],
        'gemini'     => ['provider' => 'openai', 'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'label' => 'Google Gemini'],
        'custom'     => ['provider' => 'openai', 'endpoint' => null, 'label' => null],   // eigene Adresse
    ];

    public function __construct(private SettingsService $settings, private JsonStore $store) {}

    /** Voreinstellung, die zu Anbieter und Adresse passt; sonst `custom`. */
    public function preset(): string
    {
        if ($this->provider() === 'anthropic') return 'anthropic';
        $e = rtrim(strtolower($this->endpoint()), '/');
        foreach (self::PRESETS as $id => $p) {
            if ($p['provider'] === 'openai' && $p['endpoint'] !== null && $p['endpoint'] === $e) return $id;
        }
        return 'custom';
    }

    /** Name der Voreinstellung (OpenAI, Mistral …); leer bei eigener Adresse. */
    public function presetLabel(): string
    {
        return (string)(self::PRESETS[$this->preset()]['label'] ?? '');
    }

    public static function hostOf(string $url): string
    {
        return strtolower(trim((string)parse_url(trim($url), PHP_URL_HOST), '[]'));
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('inbox_cloud_enabled', false);
    }

    public function provider(): string
    {
        $p = (string)$this->settings->get('inbox_cloud_provider', 'anthropic');
        return in_array($p, self::PROVIDERS, true) ? $p : 'anthropic';
    }

    public function endpoint(): string
    {
        return trim((string)$this->settings->get('inbox_cloud_endpoint', ''));
    }

    public function timeout(): int
    {
        return max(10, min(600, (int)$this->settings->get('inbox_cloud_timeout_s', 120)));
    }

    /** Eingetragenes Modell dieses Anbieters; leer = Standard des Anschlusses. */
    public function model(string $provider): string
    {
        return $provider === $this->provider() ? trim((string)$this->settings->get('inbox_cloud_model', '')) : '';
    }

    /** Darf dieser Anschluss jetzt senden? Eingeschaltet, als Anbieter gewählt, mit Schlüssel. */
    public function allows(string $provider): bool
    {
        return $this->enabled() && $this->provider() === $provider && $this->hasKey($provider);
    }

    /**
     * Nur für den Anschluss selbst — nie in eine Antwort, ein Log oder eine Meldung.
     *
     * v3.7.0 (Karte „Auslesen“) — beim OpenAI-kompatiblen Weg gehört der
     * Schlüssel zum Host (`openai@<host>`): Wer von Mistral auf OpenRouter
     * umstellt, schickt den Mistral-Schlüssel nicht an OpenRouter, und beim
     * Zurückstellen ist er noch da. Ein Schlüssel von vorher (`openai`, ohne
     * Host) gilt für die eingetragene Adresse; update() bindet ihn an sie,
     * sobald sich die Adresse ändert.
     */
    public function key(string $provider): string
    {
        return $this->keyFor($provider, self::hostOf($this->endpoint()));
    }

    public function hasKey(string $provider): bool
    {
        return $this->key($provider) !== '';
    }

    private function keyFor(string $provider, string $host): string
    {
        $keys = $this->keys();
        if ($provider === 'openai' && $host !== '' && is_string($keys["openai@$host"] ?? null)) return $keys["openai@$host"];
        return is_string($keys[$provider] ?? null) ? $keys[$provider] : '';
    }

    /** @return array<string,mixed> */
    private function keys(): array
    {
        $keys = $this->store->exists(self::KEY_FILE) ? $this->store->read(self::KEY_FILE, []) : [];
        return is_array($keys) ? $keys : [];
    }

    /** Hosts mit eigenem Schlüssel (OpenAI-kompatibel) — Hosts, keine Schlüssel. @return list<string> */
    private function keyHosts(): array
    {
        $hosts = [];
        foreach ($this->keys() as $slot => $v) {
            if (is_string($v) && $v !== '' && str_starts_with((string)$slot, 'openai@')) $hosts[] = substr((string)$slot, 7);
        }
        $legacy = self::hostOf($this->endpoint());
        if ($legacy !== '' && is_string($this->keys()['openai'] ?? null)) $hosts[] = $legacy;
        return array_values(array_unique($hosts));
    }

    /** Ein Schlüssel ohne Host (von vorher) gehört ab jetzt zu diesem Host. */
    private function bindLegacyKey(string $host): void
    {
        $keys = $this->keys();
        if ($host === '' || !is_string($keys['openai'] ?? null)) return;
        if (!isset($keys["openai@$host"])) $keys["openai@$host"] = $keys['openai'];
        unset($keys['openai']);
        $this->writeKeys($keys);
    }

    /**
     * Was die Oberfläche sehen darf (ohne Schlüssel).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $provider = $this->provider();
        return [
            'enabled'   => $this->enabled(),
            'provider'  => $provider,
            'endpoint'  => $this->endpoint(),
            'model'     => $this->model($provider),
            'timeout_s' => $this->timeout(),
            'has_key'   => $this->hasKey($provider),
            'keys'      => array_combine(self::PROVIDERS, array_map(fn(string $p): bool => $this->hasKey($p), self::PROVIDERS)),
            // sendet wirklich (eingeschaltet UND Schlüssel) — nach dem Einspielen
            // einer Sicherung auf einem anderen Server ist `enabled` wahr, `ready` nicht
            'ready'     => $this->allows($provider),
            // liest gerade aus: bereit UND im Posteingang gewählt (`inbox_provider`)
            'active'    => $this->allows($provider) && $this->settings->get('inbox_provider', 'local') === $provider,
            // v3.7.0 (Karte „Auslesen“) — Voreinstellungen samt „Schlüssel da?“ je Ziel
            'preset'    => $this->preset(),
            'presets'   => $this->presets(),
            // alle Cloud-Wege, auch Claude: noch nicht mit echten Rückmeldungen geprüft
            'experimental' => true,
        ];
    }

    /** @return list<array{id:string, provider:string, endpoint:?string, label:?string, host:string, has_key:bool}> */
    private function presets(): array
    {
        $hosts = $this->keyHosts();
        $out = [];
        foreach (self::PRESETS as $id => $p) {
            $host = $p['endpoint'] !== null ? self::hostOf($p['endpoint']) : ($id === 'anthropic' ? AnthropicProvider::HOST : '');
            $hasKey = match (true) {
                $id === 'anthropic' => $this->hasKey('anthropic'),
                $id === 'custom'    => $this->preset() === 'custom' && $this->hasKey('openai'),
                default             => in_array($host, $hosts, true),
            };
            $out[] = ['id' => $id, 'provider' => $p['provider'], 'endpoint' => $p['endpoint'], 'label' => $p['label'], 'host' => $host, 'has_key' => $hasKey];
        }
        return $out;
    }

    /**
     * Die Cloud-Seite der Karte „Auslesen“ speichern (PATCH /api/inbox/cloud):
     * `{enabled, provider, endpoint, model, timeout_s, api_key, clear_key}`,
     * alles optional. Ein leerer `api_key` lässt den gespeicherten stehen,
     * `clear_key` löscht einen (true = den dieses Anbieters, sonst die Kennung
     * eines Anbieters; die Auswahl bleibt dabei). Eingeschaltet wird nur ein
     * vollständiger Anschluss (Schlüssel; bei OpenAI-kompatibel Adresse und
     * Modell). Geprüft wird alles, bevor etwas geschrieben wird.
     *
     * v3.7.0 (Karte „Auslesen“), additiv: `preset` (Kennung aus PRESETS) setzt
     * Anbieter und Adresse; `select: true` lässt den Dienst auslesen, sobald er
     * bereit ist (`inbox_provider`), `false` lässt die Auswahl, wie sie ist.
     *
     * @param array<string,mixed> $in
     */
    public function update(array $in): void
    {
        if (array_key_exists('preset', $in)) {
            $pre = $in['preset'];
            if (!is_string($pre) || !isset(self::PRESETS[$pre])) {
                throw new LocalizedException('errors.settings.valueInvalid', ['key' => 'preset', 'value' => is_scalar($pre) ? (string)$pre : gettype($pre)], 'preset');
            }
            $in['provider'] = self::PRESETS[$pre]['provider'];
            if (self::PRESETS[$pre]['endpoint'] !== null) $in['endpoint'] = self::PRESETS[$pre]['endpoint'];
        }
        // ein Schlüssel ohne Host gehört zur bisher eingetragenen Adresse (ändert nichts an dem, was gilt)
        $this->bindLegacyKey(self::hostOf($this->endpoint()));
        $provider = array_key_exists('provider', $in) ? $in['provider'] : $this->provider();
        if (!is_string($provider) || !in_array($provider, self::PROVIDERS, true)) {
            throw new LocalizedException('errors.settings.valueInvalid', ['key' => 'inbox_cloud_provider', 'value' => is_scalar($provider) ? (string)$provider : gettype($provider)], 'inbox_cloud_provider');
        }
        $patch = ['inbox_cloud_provider' => $provider];
        if (array_key_exists('endpoint', $in)) $patch['inbox_cloud_endpoint'] = $in['endpoint'];
        // Das Modell gehört zum Anbieter (und zum Dienst hinter der Adresse):
        // ein Wechsel ohne Modell nimmt den Standard
        $host = self::hostOf(is_string($patch['inbox_cloud_endpoint'] ?? null) ? $patch['inbox_cloud_endpoint'] : $this->endpoint());
        if (array_key_exists('model', $in)) $patch['inbox_cloud_model'] = $in['model'];
        elseif ($provider !== $this->provider() || ($provider === 'openai' && $host !== self::hostOf($this->endpoint()))) $patch['inbox_cloud_model'] = '';
        if (array_key_exists('timeout_s', $in)) $patch['inbox_cloud_timeout_s'] = $in['timeout_s'];

        $enabled = array_key_exists('enabled', $in) ? $in['enabled'] : $this->enabled();
        if (!is_bool($enabled)) {
            throw new LocalizedException('errors.settings.valueInvalid', ['key' => 'inbox_cloud_enabled', 'value' => is_scalar($enabled) ? (string)$enabled : gettype($enabled)], 'inbox_cloud_enabled');
        }

        $newKey = $in['api_key'] ?? null;
        if ($newKey !== null && !is_string($newKey)) $newKey = '*';
        $newKey = is_string($newKey) ? trim($newKey) : '';
        if ($newKey !== '' && !preg_match('/^[\x21-\x7E]{8,500}$/', $newKey)) {
            throw new LocalizedException('errors.inboxCloud.keyFormat', [], 'api key: printable characters, 8 to 500');
        }
        // `clear_key`: true = der Schlüssel dieses Anbieters, oder die Kennung eines Anbieters
        $clear = $in['clear_key'] ?? null;
        $clearFor = $clear === true ? $provider : (is_string($clear) && in_array($clear, self::PROVIDERS, true) ? $clear : null);
        $willHaveKey = $clearFor !== $provider && ($newKey !== '' || $this->keyFor($provider, $host) !== '');

        if ($enabled) {
            if (!$willHaveKey) throw new LocalizedException('errors.inboxCloud.noKey', ['name' => $provider], 'cannot switch on without a key');
            if ($provider === 'openai') {
                $endpoint = trim((string)($patch['inbox_cloud_endpoint'] ?? $this->endpoint()));
                $model = trim((string)($patch['inbox_cloud_model'] ?? $this->model($provider)));
                if ($endpoint === '') throw new LocalizedException('errors.inboxCloud.noEndpoint', [], 'openai-compatible needs an address');
                if ($model === '') throw new LocalizedException('errors.inboxCloud.noModel', [], 'openai-compatible needs a model');
            }
        }
        $patch['inbox_cloud_enabled'] = $enabled;
        // Wer ausliest, ist `inbox_provider` — eine Wahrheit mit dem Umschalter
        // Lokal | Cloud der Karte „Auslesen“ (Einstellungen → Experte). Wird die Cloud
        // bereit (eingeschaltet mit Schlüssel), wählt das diesen Dienst;
        // Ausschalten führt zurück auf lokal (eine Stelle dafür:
        // SettingsService::guardInboxProvider); ein Wechsel des Diensts nimmt
        // die Auswahl mit, wenn die Cloud gerade ausliest. Wer bei bereiter
        // Cloud dort „Lokal“ gewählt hat, behält das beim Speichern.
        $reader = (string)$this->settings->get('inbox_provider', 'local');
        $select = $enabled && (!$this->allows($this->provider()) || in_array($reader, self::PROVIDERS, true));
        // die Karte „Auslesen“ sagt es ausdrücklich (Umschalter Lokal | Cloud)
        if (array_key_exists('select', $in)) $select = $enabled && $in['select'] === true;

        $this->settings->set($patch);   // prüft Adresse, Modell und Zeitlimit, schreibt erst danach
        // ein noch freier Schlüssel (eingetragen, bevor es eine Adresse gab) gehört zur neuen
        $this->bindLegacyKey($host);
        if ($clearFor !== null) $this->storeKey($clearFor, null, $host);
        if ($newKey !== '' && $clearFor !== $provider) $this->storeKey($provider, $newKey, $host);
        // erst mit Schlüssel wählbar (SettingsService prüft es)
        if ($select) $this->settings->set(['inbox_provider' => $provider]);
    }

    private function storeKey(string $provider, ?string $key, string $host): void
    {
        $keys = $this->keys();
        $slot = $provider === 'openai' && $host !== '' ? "openai@$host" : $provider;
        if ($key === null) unset($keys[$slot], $keys[$provider]);
        else $keys[$slot] = $key;
        $this->writeKeys($keys);
    }

    /** @param array<string,mixed> $keys */
    private function writeKeys(array $keys): void
    {
        if ($keys === []) {
            $this->store->delete(self::KEY_FILE);
            return;
        }
        $this->store->write(self::KEY_FILE, $keys);
        @chmod($this->store->path(self::KEY_FILE), 0600);   // nur der Webserver-Benutzer liest ihn
    }
}
