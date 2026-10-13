<?php
declare(strict_types=1);

namespace Energietracker\Storage;

/**
 * v2.5.3 — Eine Schreibsperre für das ganze Datenverzeichnis.
 *
 * `JsonStore` schreibt jede Datei atomar (tmp + rename), der Zyklus Lesen →
 * Ändern → Schreiben in den Diensten war aber ungeschützt. Zwei gleichzeitige
 * Schreiber lasen denselben Stand, und der zweite überschrieb den ersten —
 * beide bekamen 201. Gemessen: 18 von 50 Ablesungen verloren, wenn
 * Home-Assistant-Pushes und Oberfläche parallel schrieben.
 *
 * Die App hält die Sperre für die Dauer jeder schreibenden Anfrage und
 * während einer Migration. Lesen bleibt ungesperrt; das atomare `rename`
 * liefert immer einen vollständigen Stand. `flock` gilt prozessübergreifend
 * (php-fpm-Worker) und endet spätestens mit dem Prozess.
 *
 * v3.4.0 (N1020) — mit Frist: Wer die Sperre nicht innerhalb von
 * `waitSeconds()` bekommt, erhält `false` (die App antwortet mit 503 und
 * `Retry-After`), statt unbegrenzt zu warten. Bis v3.3 hielt eine
 * Texterkennung die Sperre bis zu fünf Minuten, und jede schreibende Anfrage
 * — auch der Home-Assistant-Push — stand so lange, bis der FPM-Pool voll war.
 */
final class WriteLock
{
    /** Vorgabe der Frist; `ET_LOCK_WAIT_S` (1–120) ändert sie. */
    public const WAIT_SECONDS = 15;

    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $dataDir) {}

    /** Frist in Sekunden (Umgebung `ET_LOCK_WAIT_S`, sonst WAIT_SECONDS). */
    public static function waitSeconds(): int
    {
        $env = getenv('ET_LOCK_WAIT_S');
        return is_string($env) && ctype_digit($env) && (int)$env >= 1 && (int)$env <= 120 ? (int)$env : self::WAIT_SECONDS;
    }

    /**
     * @param float|null $timeout Sekunden; null = waitSeconds()
     * @return bool false: Sperre nicht frei innerhalb der Frist (oder Datei nicht anlegbar)
     */
    public function acquire(?float $timeout = null): bool
    {
        if ($this->handle !== null) return true;
        $this->timedOut = false;
        $fp = @fopen(rtrim($this->dataDir, '/') . '/.write.lock', 'c');
        if ($fp === false) return false;   // nicht beschreibbar: jeder Schreibversuch scheitert ohnehin
        $deadline = microtime(true) + ($timeout ?? self::waitSeconds());
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($fp);
                $this->timedOut = true;
                return false;
            }
            usleep(50_000);
        }
        $this->handle = $fp;
        return true;
    }

    /** Scheiterte der letzte acquire() an der Frist (und nicht an der Datei)? */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    private bool $timedOut = false;

    public function release(): void
    {
        if ($this->handle === null) return;
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }
}
