<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * v2.2.1 — „Nicht gefunden" als Typ statt als Textmuster.
 *
 * `ErrorHandler::statusFor()` leitete den HTTP-Status bis dahin aus dem
 * Wortlaut der Meldung ab: `str_contains($msg, 'nicht gefunden')` bzw.
 * `'not found'`. Seit v2.0.0 werfen die Dienste ihre Meldungen aber
 * lokalisiert — eine spanische Oberfläche liefert „Contador no encontrado",
 * eine französische „Compteur introuvable". Beide Muster greifen dann nicht,
 * und der Client bekam **500 statt 404**.
 *
 * Der Typ trägt die Bedeutung, der Text nur die Anzeige. Die alte Textprüfung
 * bleibt als Rückfall für Stellen erhalten, die noch eine nackte
 * RuntimeException werfen.
 */
final class NotFoundException extends \RuntimeException
{
}
