<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\StorageNotWritableException;

/**
 * v3.1.0 — Kennung dieser Installation, beim ersten Bedarf zufällig angelegt.
 *
 * Der Kalender bildet daraus die UIDs seiner Ereignisse; `GET /api/summary`
 * liefert sie als `instance_id` mit, damit eine Integration (z. B. die geplante
 * HACS-Integration) eindeutige IDs je Installation bilden kann. Die
 * REST-Sensor-Vorlage der App nutzt sie nicht. Sie liegt in `instance.json` und gehört bewusst
 * NICHT ins Backup: Nach einem Restore auf eine zweite Installation hätten
 * sonst beide dieselbe Kennung (und HA würde ihre Sensoren verwechseln).
 *
 * v3.13.0 — Ohne Schreibrecht (fremder Besitzer des Datenordners, etwa das Image ohne root auf einem bestehenden
 * Volume) lesen Zusammenfassung, „Zu tun“ und Kalender weiter. Bisher endeten diese drei Lesewege mit 503, wenn
 * `instance.json` fehlte, weil der erste Bedarf die Datei schreiben wollte. Jetzt:
 *   - Eine neue Installation legt die Kennung beim Erststart an (ensure(), zufällig wie bisher).
 *   - Fehlt sie später (ein Ordner aus einer älteren Version, der die Kennung nie brauchte), gilt eine Kennung, die
 *     aus dem Datenordner selbst folgt (Pfad, Gerät und Inode): ohne Schreibrecht nur geliefert, mit Schreibrecht
 *     gespeichert. Sie ist von Anfrage zu Anfrage dieselbe, auch über den Wechsel von „gesperrt“ zu „beschreibbar“:
 *     Eine Integration, die in der Zwischenzeit eingerichtet wurde, behält ihre Sensoren.
 */
final class InstanceService
{
    private const FILE = 'instance.json';
    private const ID = '/^et_[0-9a-f]{16}$/';

    /** @var \Closure(): bool */
    private \Closure $writable;

    /** @param (\Closure(): bool)|null $writable darf geschrieben werden? (Tests; sonst is_writable des Datenordners) */
    public function __construct(private JsonStore $store, ?\Closure $writable = null)
    {
        $this->writable = $writable ?? fn(): bool => is_writable($this->store->rootDir());
    }

    public function id(): string
    {
        return $this->stored() ?? $this->save($this->derivedId());
    }

    /** v3.13.0 — beim Erststart: eine zufällige Kennung anlegen, wenn keine da ist (wie bis v3.12 beim ersten Bedarf). */
    public function ensure(): void
    {
        if ($this->stored() === null) $this->save('et_' . bin2hex(random_bytes(8)));
    }

    private function stored(): ?string
    {
        $data = $this->store->read(self::FILE, []);
        $id = is_array($data) ? (string)($data['instance_id'] ?? '') : '';
        return preg_match(self::ID, $id) ? $id : null;
    }

    /** Speichern, wenn geschrieben werden darf; die Kennung gilt in jedem Fall. */
    private function save(string $id): string
    {
        if (!($this->writable)()) return $id;
        try {
            $this->store->write(self::FILE, ['instance_id' => $id, 'created_at' => date('c')]);
        } catch (StorageNotWritableException) {
            // zwischen Prüfung und Schreiben gesperrt: gilt trotzdem, gespeichert wird beim nächsten Bedarf
        }
        return $id;
    }

    /** Aus dem Datenordner abgeleitet: gleich bei jeder Anfrage, solange es derselbe Ordner ist. */
    private function derivedId(): string
    {
        $dir = $this->store->rootDir();
        $st = @stat($dir);
        $seed = (realpath($dir) ?: $dir) . '|' . (is_array($st) ? $st['dev'] . '|' . $st['ino'] : '');
        return 'et_' . substr(hash('sha256', 'energietracker-instance|' . $seed), 0, 16);
    }
}
