<?php
declare(strict_types=1);

namespace Energietracker\Http;

use Energietracker\Support\LocalizedException;

/**
 * v3.13.0 — Die hochgeladene Datei einer Anfrage, eine Regel für Beleg (`POST /api/attachments`) und Posteingang
 * (`POST /api/inbox`): roh im Körper oder als Formular (`multipart/form-data`, Feld `file` oder die einzige Datei).
 *
 * Grenzen von PHP, die vor der App greifen, heißen „zu groß“ mit der Grenze, die gegriffen hat, nie „falscher Typ“:
 *   - Körper über `post_max_size`: PHP liest ihn nicht. Roh kommt er leer an, ein Formular ohne Datei; je nach Server
 *     liefert `php://input` dann den rohen Körper des Formulars. Darum entscheidet die angegebene Länge, bevor der
 *     Körper als Datei gilt. Bisher prüfte der Beleg so die Bytes `--…` des Formulars als Inhalt („nur Fotos und PDF“),
 *     der Posteingang meldete fest 10 MB oder „nur PDF“.
 *   - Datei eines Formulars über `upload_max_filesize`: diese Grenze. Bisher nannte der Posteingang 10 MB.
 * Ein Formular ohne Datei ist ein falscher Inhalt (leere Bytes), den der Dienst meldet, kein zu großer.
 */
final class UploadBody
{
    /**
     * @return array{0:string, 1:?string} Inhalt und Dateiname des Formulars (roh: null)
     * @throws LocalizedException errors.attachment.size
     */
    public static function read(Request $req): array
    {
        $length = (int)(Request::declaredLength() ?? 0);
        $postMax = self::iniBytes((string)ini_get('post_max_size'));
        if ($postMax > 0 && $length > $postMax) throw self::tooLarge($postMax, 'body over post_max_size');
        if (self::isMultipart()) return self::uploadedFile();
        $bytes = $req->rawBody;
        // angekündigt, aber leer angekommen: eine Grenze vor der App (post_max_size oder davor)
        if ($bytes === '' && $length > 0) throw self::tooLarge($postMax, 'body empty although announced');
        return [$bytes, null];
    }

    public static function isMultipart(): bool
    {
        return str_starts_with(strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))), 'multipart/form-data');
    }

    /**
     * Die Datei eines Formulars: Feld `file`, sonst die einzige Datei.
     *
     * @return array{0:string, 1:?string} Inhalt und Dateiname ('' ohne Datei)
     */
    private static function uploadedFile(): array
    {
        $f = $_FILES['file'] ?? (count($_FILES) === 1 ? reset($_FILES) : null);
        if (!is_array($f) || is_array($f['error'] ?? null)) return ['', null];   // keine oder mehrere Dateien unter einem Namen
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw self::tooLarge(self::iniBytes((string)ini_get('upload_max_filesize')), 'upload over upload_max_filesize');
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) return ['', null];
        $name = is_string($f['name'] ?? null) && trim($f['name']) !== '' ? basename($f['name']) : null;
        return [(string)file_get_contents($tmp), $name];
    }

    private static function tooLarge(int $bytes, string $log): LocalizedException
    {
        return new LocalizedException('errors.attachment.size', ['max' => max(1, (int)floor($bytes / 1048576))], $log);
    }

    /** „8M" → Bytes */
    private static function iniBytes(string $v): int
    {
        $n = (int)$v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }
}
