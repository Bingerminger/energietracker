<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * v3.1.0 — Kennung dieser Installation, beim ersten Bedarf zufällig angelegt.
 *
 * Der Kalender bildet daraus die UIDs seiner Ereignisse; `GET /api/summary`
 * liefert sie als `instance_id` mit, damit eine Integration (z. B. die geplante
 * HACS-Integration) eindeutige IDs je Installation bilden kann. Die
 * REST-Sensor-Vorlage der App nutzt sie nicht. Sie liegt in `instance.json` und gehört bewusst
 * NICHT ins Backup: Nach einem Restore auf eine zweite Installation hätten
 * sonst beide dieselbe Kennung (und HA würde ihre Sensoren verwechseln).
 */
final class InstanceService
{
    private const FILE = 'instance.json';

    public function __construct(private JsonStore $store) {}

    public function id(): string
    {
        $data = $this->store->read(self::FILE, []);
        $id = is_array($data) ? (string)($data['instance_id'] ?? '') : '';
        if (preg_match('/^et_[0-9a-f]{16}$/', $id)) return $id;
        $id = 'et_' . bin2hex(random_bytes(8));
        $this->store->write(self::FILE, ['instance_id' => $id, 'created_at' => date('c')]);
        return $id;
    }
}
