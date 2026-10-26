<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AgendaService;
use Energietracker\Services\HomeAssistant\Todo;
use Energietracker\Services\HomeAssistantNotifyService;
use Energietracker\Services\InstanceService;

/**
 * v3.9.0 (F1033, GitHub #36) — Home Assistant: Sensor „Zu tun“ und
 * Benachrichtigungen.
 *
 *   GET   /api/todo                       fällige Punkte aus „Zu tun“: Anzahl,
 *                                         überfällig, nächster Punkt, Liste (Klasse A)
 *   GET   /api/homeassistant/notify       Stand der Benachrichtigungen, nie
 *                                         Webhook-ID oder Token (`has_webhook`, `has_token`)
 *   PATCH /api/homeassistant/notify       speichern, nur Verwalter (App::adminOnly)
 *   POST  /api/homeassistant/notify/test  Testnachricht, nur Verwalter
 */
final class HomeAssistantController
{
    public function __construct(
        private HomeAssistantNotifyService $notify,
        private AgendaService $agenda,
        private InstanceService $instance,
    ) {}

    public function todo(Request $req): never
    {
        Response::json(Todo::summary($this->agenda->events(HomeAssistantNotifyService::HORIZON_DAYS), $this->instance->id()));
    }

    public function show(Request $req): never
    {
        Response::json($this->notify->status());
    }

    public function update(Request $req): never
    {
        Response::json($this->notify->update(is_array($req->body) ? $req->body : []));
    }

    public function test(Request $req): never
    {
        Response::json($this->notify->sendTest());
    }
}
