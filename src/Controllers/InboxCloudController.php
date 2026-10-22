<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\Inbox\AnthropicProvider;
use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Services\Inbox\OpenAiCompatibleProvider;
use Energietracker\Services\OcrService;
use Energietracker\Services\SettingsService;

/**
 * v3.7.0 (Paket cloud) — Karte „Auslesen in der Cloud“; seit der Karte
 * „Auslesen“ (Umschalter Lokal | Cloud) auch die Prüfung des eigenen Diensts.
 *
 *   GET /api/inbox/cloud  Stand samt Anbietern (Name, Ziel-Host, Hinweistext,
 *                         was hinausgeht) und Voreinstellungen — nie der
 *                         Schlüssel, nur `has_key`.
 *   PATCH /api/inbox/cloud  speichern, nur Verwalter (App::adminOnly).
 *   GET /api/inbox/cloud/models  Modelle des eingerichteten Diensts, nur
 *                         Verwalter; der Schlüssel bleibt auf dem Server.
 *   GET /api/inbox/local/check  „Verbindung prüfen“ für den eigenen Dienst im
 *                         Heimnetz (`?endpoint=&api=`, sonst die gespeicherten), nur Verwalter.
 */
final class InboxCloudController
{
    public function __construct(
        private CloudConfig $config,
        private AnthropicProvider $anthropic,
        private OpenAiCompatibleProvider $openai,
        private ?OcrService $ocr = null,
        private ?SettingsService $settings = null,
    ) {}

    public function show(Request $req): never
    {
        Response::json($this->status());
    }

    public function update(Request $req): never
    {
        $this->config->update(is_array($req->body) ? $req->body : []);
        Response::json($this->status());
    }

    /** Modellliste des eingerichteten Diensts (gespeicherter Anbieter, Adresse und Schlüssel). */
    public function models(Request $req): never
    {
        $p = $this->config->provider() === AnthropicProvider::ID ? $this->anthropic : $this->openai;
        Response::json(['provider' => $p->id(), 'preset' => $this->config->preset(), 'models' => $p->models()]);
    }

    /** Verbindung zum eigenen Dienst prüfen: erreichbar, welche Modelle, ist das des Posteingangs dabei. */
    public function localCheck(Request $req): never
    {
        $endpoint = $req->queryParam('endpoint') ?? (string)$this->settings?->get('ocr_endpoint', '');
        $api = $req->queryParam('api') ?? (string)$this->settings?->get('ocr_api', 'ollama');
        $models = $this->ocr?->listModels($endpoint, $api) ?? [];
        $model = trim((string)$this->settings?->get('inbox_model', ''));
        // Ollama hängt gern „:latest“ an
        $found = $model === '' ? null : (in_array($model, $models, true) || in_array("$model:latest", $models, true));
        Response::json(['host' => strtolower((string)parse_url($endpoint, PHP_URL_HOST)), 'api' => $api,
            'models' => array_slice($models, 0, 200), 'model' => $model, 'model_found' => $found]);
    }

    /** @return array<string,mixed> */
    private function status(): array
    {
        $describe = static fn($p, string $sends, string $default): array => [
            'id' => $p->id(), 'label' => $p->label(), 'destination' => $p->destination(),
            'notice' => $p->notice(), 'sends' => $sends, 'default_model' => $default,
        ];
        return $this->config->status() + ['providers' => [
            $describe($this->anthropic, 'pdf', AnthropicProvider::DEFAULT_MODEL),
            $describe($this->openai, 'text', ''),
        ]];
    }
}
