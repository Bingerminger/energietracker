<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Http\ErrorHandler;
use Energietracker\Http\HttpStatusException;
use Energietracker\Logging\Logger;
use Energietracker\Support\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * v3.10.0 (#41) — Was der Client bei einer Ausnahme sieht und was ins Log geht.
 *
 * Seit v2.6.0 ersetzt der ErrorHandler die Meldung eines unerwarteten 5xx
 * durch eine allgemeine mit Fehler-ID (Pfade und Benutzernamen bleiben im
 * Log). Bis v3.9.0 traf das auch eine HttpStatusException mit eigenem
 * Katalogtext: Die Absage eines Cloud-Diensts (502) erschien als „Interner
 * Fehler … Server-Log“ und stand als `error` im Log. Jetzt bleibt der eigene
 * Text stehen; das Verdecken unerwarteter Fehler ist unverändert.
 */
final class ErrorHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        ErrorHandler::attachTranslator(fn(string $key, array $params): string
            => $key === 'errors.http.internal' ? 'Interner Fehler (' . $params['id'] . ')' : "Text:$key");
    }

    protected function tearDown(): void
    {
        ErrorHandler::attachTranslator(null);
    }

    /** Unerwartete Ausnahme mit Pfad in der Meldung: verdeckt, mit ID, als error im Log (wie seit v2.6.0). */
    public function testAnUnexpectedErrorStaysHiddenBehindAnId(): void
    {
        $d = ErrorHandler::describe(new \TypeError('Argument #1 in /home/someone/app/src/X.php'), 'err_00000001');
        self::assertSame(500, $d['status']);
        self::assertSame('Interner Fehler (err_00000001)', $d['payload']['error']);
        self::assertSame('err_00000001', $d['payload']['error_id']);
        self::assertSame('errors.http.internal', $d['payload']['code']);
        self::assertStringNotContainsString('/home/', (string)json_encode($d['payload']));
        self::assertSame([Logger::ERROR, 'err_00000001'], [$d['level'], $d['context']['error_id']]);
    }

    /** Die Absage eines Diensts (502 mit Katalogschlüssel): eigener Text, keine ID, Warnung im Log. */
    public function testARefusalWithACatalogKeyKeepsItsOwnText(): void
    {
        $e = new HttpStatusException(502, 'errors.inboxCloud.rejected', ['code' => 400, 'detail' => 'x'], [], 'cloud rejected the request (400)');
        $d = ErrorHandler::describe($e, 'err_00000002');
        self::assertSame(502, $d['status']);
        self::assertSame(['success' => false, 'error' => 'Text:errors.inboxCloud.rejected', 'code' => 'errors.inboxCloud.rejected'], $d['payload']);
        self::assertArrayNotHasKey('error_id', $d['payload'], 'Fehler-ID nur bei unerwarteten Ausnahmen');
        self::assertSame(Logger::WARNING, $d['level'], 'erwartet: nicht als error im Log');
        self::assertSame('errors.inboxCloud.rejected', $d['context']['code']);
        self::assertArrayNotHasKey('error_id', $d['context']);
    }

    /** Zeitüberschreitung (504) und belegte Sperre (503 mit Retry-After) genauso. */
    public function testTimeoutAndBusyKeepTheirTextAndHeaders(): void
    {
        $t = ErrorHandler::describe(new HttpStatusException(504, 'errors.inboxCloud.timeout', ['seconds' => 30]), 'err_00000003');
        self::assertSame([504, 'Text:errors.inboxCloud.timeout'], [$t['status'], $t['payload']['error']]);
        self::assertArrayNotHasKey('error_id', $t['payload']);
        $b = ErrorHandler::describe(new HttpStatusException(503, 'errors.http.busy', [], ['Retry-After' => '5']), 'err_00000004');
        self::assertSame([503, ['Retry-After' => '5'], Logger::WARNING], [$b['status'], $b['headers'], $b['level']]);
    }

    /** Eine Validierung (400) bleibt, wie sie war: Text aus dem Katalog, keine ID in der Antwort. */
    public function testAValidationErrorIsUnchanged(): void
    {
        $d = ErrorHandler::describe(new LocalizedException('errors.settings.valueInvalid', ['key' => 'k', 'value' => 'v'], 'bad'), 'err_00000005');
        self::assertSame([400, 'Text:errors.settings.valueInvalid', 'errors.settings.valueInvalid'], [$d['status'], $d['payload']['error'], $d['payload']['code']]);
        self::assertArrayNotHasKey('error_id', $d['payload']);
    }

    /** Ein fataler Fehler (Shutdown) ist immer unerwartet: verdeckt mit ID. */
    public function testTheFatalPathStillHidesTheMessage(): void
    {
        $p = ErrorHandler::payload(500, 'Allowed memory size exhausted in /var/www/src/Y.php', [], 'err_00000006');
        self::assertSame(['Interner Fehler (err_00000006)', 'err_00000006'], [$p['error'], $p['error_id']]);
        $q = ErrorHandler::payload(502, 'Eigener Text', [], null, 'errors.x');
        self::assertSame(['Eigener Text', 'errors.x'], [$q['error'], $q['code']]);
        self::assertArrayNotHasKey('error_id', $q);
    }
}
