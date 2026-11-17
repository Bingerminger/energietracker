<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Eine Gruppe von Werkzeugen in einer Datei unter `Tools/`.
 * Jedes Paket füllt nur seine Gruppen (Liste in ToolRegistry::GROUPS), damit
 * das Zusammenführen ohne Konflikt geht.
 */
abstract class ToolGroup
{
    public function __construct(protected Services $services) {}

    /** @return list<Tool> in fester Reihenfolge */
    abstract public function tools(): array;

    private ?IdentifierMask $householdMask = null;

    /**
     * v3.12.0 (Nachprüfung A2) — die Nummern, die der Haushalt kennt, auch in Texten außerhalb der Dokumente: Anbieter
     * und Tarif, Zähler- und Gruppennamen, Titel, Notizen. Bis dahin stand dieselbe Nummer dort im Klartext, die das
     * Feld `contract_number` zurückhielt. Anbieter und Tarif selbst gelten nicht als Kennnummer (Karte, Anleitung).
     */
    protected function householdMask(): IdentifierMask
    {
        return $this->householdMask ??= (new Tools\DocumentTools($this->services))->mask([]);
    }

    /** Anbieter und Tarif: nur die bekannten Nummern ersetzt (sie selbst gelten nicht als Kennnummer). */
    protected function masked(string $text): string
    {
        return $this->householdMask()->known($text);
    }

    /**
     * v3.13.0 — Härtung: Namen und Titel (Zähler, Gruppe, Termin, Hinweis, Rechte-Hinweis) bekommen dazu den zweiten Schritt
     * wie Zitate und Notizen: Was danach noch wie eine Nummer aussieht, wird ersetzt (IdentifierMask::name).
     */
    protected function maskedName(string $text): string
    {
        return $this->householdMask()->name($text);
    }
}
