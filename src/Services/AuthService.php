<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * F1009 — API-Token-Verwaltung für externe Schreibzugriffe (Home Assistant).
 *
 * Designprinzip (Multiple-Choice-Entscheidung 2026-06-01):
 *  - **Opt-in**: Solange kein Token gesetzt ist, ist die API unverändert offen
 *    (LAN-Annahme, keine Breaking Change). `requiresAuth()` ist dann false.
 *  - Der Token wird **einmalig** im Klartext erzeugt und zurückgegeben; gespeichert
 *    wird nur sein **SHA-256-Hash** in einer separaten `data/auth.json` — NICHT in
 *    `settings.json`, weil `GET /api/settings` den gesamten Settings-Block
 *    ausliefert. So ist der Token nach dem Erzeugen nicht mehr auslesbar.
 *  - `verify()` nutzt `hash_equals()` (konstante Zeit) gegen Timing-Angriffe.
 *
 * Genau ein aktiver Token; `revoke()` entfernt ihn wieder (zurück in den
 * offenen Modus). `data/auth.json` wird vom Backup ausgenommen.
 */
final class AuthService
{
    private const FILE = 'auth.json';

    public function __construct(private JsonStore $store) {}

    /** Ist überhaupt ein Token gesetzt? Nur dann ist Auth erforderlich. */
    public function requiresAuth(): bool
    {
        $data = $this->store->read(self::FILE, []);
        return is_array($data) && !empty($data['token_hash']);
    }

    /**
     * Status für die UI (NIE der Klartext-Token):
     *   { enabled: bool, created_at: ?string }
     */
    public function status(): array
    {
        $data = $this->store->read(self::FILE, []);
        $enabled = is_array($data) && !empty($data['token_hash']);
        return [
            'enabled'    => $enabled,
            'created_at' => $enabled ? ($data['created_at'] ?? null) : null,
        ];
    }

    /**
     * Erzeugt einen neuen Token, speichert dessen Hash und gibt den
     * **Klartext** zurück (nur dieses eine Mal sichtbar). Ein bereits
     * existierender Token wird dabei ersetzt.
     */
    public function generate(): string
    {
        $token = 'et_' . bin2hex(random_bytes(24)); // 48 Hex-Zeichen + Präfix
        $this->store->write(self::FILE, [
            'token_hash' => hash('sha256', $token),
            'created_at' => date('c'),
        ]);
        return $token;
    }

    /** Entfernt den Token → API ist wieder offen. */
    public function revoke(): void
    {
        $this->store->write(self::FILE, []);
    }

    /**
     * Prüft einen Klartext-Token gegen den gespeicherten Hash.
     * Ist kein Token gesetzt, gilt jede Anfrage als autorisiert (offener Modus).
     */
    public function verify(?string $token): bool
    {
        $data = $this->store->read(self::FILE, []);
        if (!is_array($data) || empty($data['token_hash'])) {
            return true; // offener Modus: keine Auth konfiguriert
        }
        if ($token === null || $token === '') return false;
        return hash_equals((string)$data['token_hash'], hash('sha256', $token));
    }
}
