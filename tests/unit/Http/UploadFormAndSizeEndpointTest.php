<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 — Beleg (`POST /api/attachments`) und Posteingang (`POST /api/inbox`) lesen eine Datei nach derselben Regel
 * (Http\UploadBody), roh, als JSON (nur Posteingang) und als Formular (`multipart/form-data`):
 *   - Eine kleine Datei als Formular wird angenommen, auch im Posteingang. Bisher hieß es dort „zu groß, höchstens
 *     10 MB“, auch bei wenigen hundert Bytes.
 *   - Über `upload_max_filesize` von PHP (nur Formular) nennt die Meldung diese Grenze, über `post_max_size` „zu groß“
 *     mit jener Grenze, nie „falscher Dateityp“. Bisher prüfte der Beleg den rohen Körper des Formulars als Inhalt
 *     („nur Fotos und PDF“), der Posteingang meldete fest 10 MB oder „nur PDF“.
 *   - Ein Dateiname über 120 Zeichen verliert seine Endung nicht: Gekürzt wird der Stamm, die Endung bleibt.
 *
 * PHP-Grenzen des Testservers über `-d` (upload_max_filesize 1 MB, post_max_size 2 MB); die Gegenprobe roh mit 1,5 MB
 * (angenommen) und 2,5 MB (zu groß) zeigt, dass sie wirklich gelten. Eigene Werte: PDF-Kopf mit Füllbytes.
 */
#[CoversNothing]
final class UploadFormAndSizeEndpointTest extends HttpServerTestCase
{
    private static int $n = 0;

    /** Ein PDF mit genau `$bytes` Bytes (Kopf `%PDF-`, eigene Füllbytes, jedes anders: kein Doppel im Posteingang). */
    private static function pdf(int $bytes = 300): string
    {
        $head = "%PDF-1.4\n% k5b Beleg " . (++self::$n) . "\n";
        return $head . str_repeat('0', max(0, $bytes - strlen($head)));
    }

    /** @return array{status:int, code:?string, message:string, data:mixed} */
    private function answer(array $r): array
    {
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => $j['code'] ?? null, 'message' => (string)($j['message'] ?? $j['error'] ?? ''), 'data' => $j['data'] ?? null];
    }

    /** @return array{status:int, code:?string, message:string, data:mixed} */
    private function form(string $path, string $file, string $bytes, string $field = 'file'): array
    {
        $b = 'k5bGrenze' . bin2hex(random_bytes(6));
        $body = "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$file\"\r\nContent-Type: application/pdf\r\n\r\n$bytes\r\n--$b--\r\n";
        return $this->answer($this->request('POST', $path, ["Content-Type: multipart/form-data; boundary=$b", "Origin: {$this->base}", 'X-ET-Language: de'], $body));
    }

    /** @return array{status:int, code:?string, message:string, data:mixed} */
    private function raw(string $path, string $bytes): array
    {
        return $this->answer($this->request('POST', $path, ['Content-Type: application/pdf', "Origin: {$this->base}", 'X-ET-Language: de'], $bytes));
    }

    private function stored(): int
    {
        return count((array)$this->json('GET', '/api/attachments')['attachments']);
    }

    public function testTheInboxTakesASmallFormLikeTheReceipt(): void
    {
        $this->startServer();
        $r = $this->form('/api/inbox', 'brief-maerz.pdf', self::pdf(913));
        self::assertSame(201, $r['status'], json_encode($r));
        self::assertSame('brief-maerz.pdf', $r['data']['name'] ?? null);
        // ein anderer Feldname: die einzige Datei; &name= geht vor
        $r = $this->form('/api/inbox?name=abschlag.pdf', 'scan.pdf', self::pdf(4711), 'dokument');
        self::assertSame([201, 'abschlag.pdf'], [$r['status'], $r['data']['name'] ?? null], json_encode($r));
        // derselbe Weg am Beleg (wie bisher)
        $r = $this->form('/api/attachments?kind=bill_pdf', 'rechnung.pdf', self::pdf(913));
        self::assertSame([201, 'rechnung.pdf'], [$r['status'], $r['data']['original_name'] ?? null], json_encode($r));
        // kein PDF als Formular: „nur PDF“ wie roh; ein Formular ohne Datei ebenso (kein „zu groß“)
        $r = $this->form('/api/inbox', 'foto.png', "\x89PNG\r\n\x1A\n" . str_repeat('1', 400));
        self::assertSame([400, 'errors.inbox.pdfOnly'], [$r['status'], $r['code']], json_encode($r));
        $b = 'k5bLeer';
        $r = $this->answer($this->request('POST', '/api/inbox', ["Content-Type: multipart/form-data; boundary=$b", "Origin: {$this->base}"],
            "--$b\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nkein Dokument\r\n--$b--\r\n"));
        self::assertSame([400, 'errors.inbox.pdfOnly'], [$r['status'], $r['code']], json_encode($r));
        // roh und als JSON wie bisher
        self::assertSame(201, $this->raw('/api/inbox?name=roh.pdf', self::pdf(1913))['status']);
        $j = $this->answer($this->request('POST', '/api/inbox', ['Content-Type: application/json', "Origin: {$this->base}"],
            json_encode(['name' => 'mail.pdf', 'pdf_base64' => base64_encode(self::pdf(2222))])));
        self::assertSame([201, 'mail.pdf'], [$j['status'], $j['data']['name'] ?? null], json_encode($j));
    }

    public function testOverTheLimitsOfPhpBothRoutesNameTheLimitThatApplied(): void
    {
        $this->serverIni = ['upload_max_filesize' => '1M', 'post_max_size' => '2M'];
        $this->startServer();
        $mb = 1048576;
        // Gegenprobe: die Grenzen gelten (roh 1,5 MB unter post_max_size angenommen, 2,5 MB darüber zu groß)
        self::assertSame(201, $this->raw('/api/attachments?kind=bill_pdf', self::pdf((int)(1.5 * $mb)))['status'], 'roh unter post_max_size');
        $before = $this->stored();

        foreach (['/api/attachments?kind=bill_pdf', '/api/inbox'] as $path) {
            // Formular über upload_max_filesize (1 MB), unter post_max_size: diese Grenze
            $r = $this->form($path, 'gross.pdf', self::pdf((int)(1.5 * $mb)));
            self::assertSame([400, 'errors.attachment.size'], [$r['status'], $r['code']], "$path Formular 1,5 MB: " . json_encode($r));
            self::assertStringContainsString('höchstens 1 MB', $r['message'], "$path: die Grenze, die griff");
            // Formular über post_max_size (2 MB): zu groß mit dieser Grenze, nicht „falscher Typ“
            $r = $this->form($path, 'groesser.pdf', self::pdf((int)(2.5 * $mb)));
            self::assertSame([400, 'errors.attachment.size'], [$r['status'], $r['code']], "$path Formular 2,5 MB: " . json_encode($r));
            self::assertStringContainsString('höchstens 2 MB', $r['message'], $path);
            // roh über post_max_size: dieselbe Meldung (am Beleg wie bisher, im Posteingang bisher fest 10 MB)
            $r = $this->raw($path, self::pdf((int)(2.5 * $mb)));
            self::assertSame([400, 'errors.attachment.size'], [$r['status'], $r['code']], "$path roh 2,5 MB: " . json_encode($r));
            self::assertStringContainsString('höchstens 2 MB', $r['message'], $path);
            // knapp unter den Grenzen als Formular angenommen
            $r = $this->form($path, 'klein.pdf', self::pdf((int)(0.8 * $mb)));
            self::assertSame(201, $r['status'], "$path Formular 0,8 MB: " . json_encode($r));
        }
        self::assertSame($before + 2, $this->stored(), 'nur die zwei kleinen gespeichert');
    }

    public function testALongFileNameKeepsItsExtension(): void
    {
        $this->startServer();
        $cases = [
            // gesendet => gespeichert (höchstens 120 Zeichen, Endung bleibt)
            str_repeat('a', 200) . '.pdf'          => str_repeat('a', 116) . '.pdf',
            str_repeat('b', 116) . '.pdf'          => str_repeat('b', 116) . '.pdf',   // genau 120: unverändert
            str_repeat('c', 117) . '.pdf'          => str_repeat('c', 116) . '.pdf',   // 121
            str_repeat('ä', 150) . '.PDF'          => str_repeat('ä', 116) . '.PDF',   // Zeichen, nicht Bytes
            'Rechnung.2025.' . str_repeat('f', 120) . '.pdf' => 'Rechnung.2025.' . str_repeat('f', 102) . '.pdf',
            str_repeat('d', 130)                   => str_repeat('d', 120),            // ohne Endung
            'x.' . str_repeat('e', 140)            => 'x.' . str_repeat('e', 118),     // keine Endung, nur ein Punkt
        ];
        foreach ($cases as $sent => $want) {
            $sent = (string)$sent;
            $r = $this->raw('/api/inbox?name=' . rawurlencode($sent), self::pdf());
            self::assertSame([201, $want], [$r['status'], $r['data']['name'] ?? null], 'Posteingang roh ' . mb_strlen($sent));
            $r = $this->raw('/api/attachments?kind=bill_pdf&name=' . rawurlencode($sent), self::pdf());
            self::assertSame([201, $want], [$r['status'], $r['data']['original_name'] ?? null], 'Beleg roh ' . mb_strlen($sent));
        }
        // als Formular und als JSON derselbe Name
        $long = 'Jahresabrechnung-Musterstadt-' . str_repeat('Seite-', 20) . 'Ende.pdf';
        foreach ([$this->form('/api/inbox', $long, self::pdf()), $this->form('/api/attachments?kind=other', $long, self::pdf())] as $r) {
            $name = (string)($r['data']['name'] ?? $r['data']['original_name'] ?? '');
            self::assertSame(201, $r['status'], json_encode($r));
            self::assertSame([120, '.pdf'], [mb_strlen($name), substr($name, -4)], $name);
            self::assertStringStartsWith('Jahresabrechnung-Musterstadt-Seite-', $name);
        }
        $j = $this->answer($this->request('POST', '/api/inbox', ['Content-Type: application/json', "Origin: {$this->base}"],
            json_encode(['name' => str_repeat('m', 125) . '.pdf', 'pdf_base64' => base64_encode(self::pdf())])));
        self::assertSame([201, str_repeat('m', 116) . '.pdf'], [$j['status'], $j['data']['name'] ?? null], json_encode($j));
    }
}
