<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.1 — „Nicht gefunden" muss am Typ hängen, nicht am Wortlaut.
 *
 * `ErrorHandler::statusFor()` leitete den HTTP-Status aus der Meldung ab:
 * `str_contains($msg, 'nicht gefunden')` bzw. `'not found'`. Seit v2.0.0 werfen
 * die Dienste lokalisiert — eine spanische Oberfläche meldet „Contador no
 * encontrado", eine französische „Compteur introuvable". Beide Muster greifen
 * dann nicht, und der Client bekam **500 statt 404**: ein fehlender Datensatz
 * sah aus wie ein Serverfehler.
 *
 * Der Test prüft beides: dass der Typ geworfen wird, und dass er unabhängig von
 * der eingestellten Sprache gilt.
 */
#[CoversClass(NotFoundException::class)]
#[CoversClass(ReminderService::class)]
#[CoversClass(DeliveryService::class)]
final class NotFoundStatusTest extends ServiceTestCase
{
    private function reminders(): ReminderService
    {
        return new ReminderService($this->store, $this->settings, $this->i18n);
    }

    private function deliveries(): DeliveryService
    {
        return new DeliveryService($this->store, $this->meters, $this->i18n);
    }

    public function testMissingReminderThrowsTheTypedException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->reminders()->update('gibtsnicht', ['title' => 'x']);
    }

    public function testMissingDeliveryThrowsTheTypedException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->deliveries()->update('heizoel', 'gibtsnicht', ['quantity' => 1.0]);
    }

    /**
     * Der Kern: In einer Sprache, deren Wort für „nicht gefunden" weder das
     * deutsche noch das englische Muster enthält, muss der Typ trotzdem
     * greifen. Spanisch sagt „no encontrado" — die alte Textprüfung hätte
     * hier 500 geliefert.
     */
    public function testTypeSurvivesALocaleWithoutTheGermanOrEnglishWording(): void
    {
        $this->settings->set(['language' => 'es']);
        $this->i18n->setLocale('es');

        try {
            $this->reminders()->delete('gibtsnicht');
            self::fail('Es wurde keine Ausnahme geworfen');
        } catch (NotFoundException $e) {
            $msg = strtolower($e->getMessage());
            // Beleg, dass die Textprüfung hier tatsächlich versagt hätte:
            self::assertStringNotContainsString('nicht gefunden', $msg);
            self::assertStringNotContainsString('not found', $msg);
            self::assertNotSame('', trim($msg), 'Die Meldung darf nicht leer sein');
        }
    }

    /**
     * Gegenprobe zur Statusabbildung selbst — sie ist privat, also prüfen wir
     * die dokumentierte Zusage über die Vererbung: NotFoundException bleibt
     * eine RuntimeException, damit bestehende catch-Blöcke weiter greifen.
     */
    public function testNotFoundStaysARuntimeException(): void
    {
        self::assertInstanceOf(
            \RuntimeException::class,
            new NotFoundException('x'),
            'Bestehende catch(RuntimeException)-Blöcke dürfen nicht brechen'
        );
    }
}
