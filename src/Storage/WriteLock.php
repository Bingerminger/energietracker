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
 */
final class WriteLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $dataDir) {}

    public function acquire(): bool
    {
        if ($this->handle !== null) return true;
        $fp = @fopen(rtrim($this->dataDir, '/') . '/.write.lock', 'c');
        if ($fp === false) return false;   // nicht beschreibbar: jeder Schreibversuch scheitert ohnehin
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        $this->handle = $fp;
        return true;
    }

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
