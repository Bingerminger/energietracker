<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\InboxService;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Paket betrieb) — überwachter Ordner: PDFs, die in
 * `<Datenverzeichnis>/posteingang/` liegen, wandern in den Posteingang
 * (InboxService::upload, dieselben Prüfungen wie beim Hochladen); die Datei
 * danach nach `posteingang/übernommen/`, eine abgelehnte nach
 * `posteingang/fehler/`. Ein Doppel (gleiche Prüfsumme) gilt als übernommen.
 *
 * Wann: beim Öffnen des Posteingangs und beim Start der App, jeweils nur mit
 * Verwalterrechten (`POST /api/inbox/folder` ist adminOnly). Einen eigenen
 * Zeitplan hat die App nicht (kein Cron im Image); Home Assistant oder ein
 * Cron mit curl können die Route regelmäßig aufrufen.
 *
 * Sicherheit:
 *   - nur PDF nach Inhalt (`%PDF-`, AttachmentService::sniff), nie nach Endung
 *   - Größengrenze wie beim Hochladen (AttachmentService::PDF_MAX)
 *   - keine symbolischen Links (weder der Ordner noch eine Datei darin noch die
 *     Ablagen), keine Unterordner, keine versteckten Dateien (`.…`, auch die
 *     `._…`-Begleitdateien von macOS)
 *   - jede Datei muss nach realpath direkt im Ordner liegen, der Ordner im
 *     Datenverzeichnis
 *   - eine gerade erst geschriebene Datei (jünger als SETTLE_SECONDS) wartet —
 *     eine Kopie über das Netz könnte noch laufen
 *   - höchstens MAX_PER_RUN Dateien je Aufruf (Zeitgrenze des Webservers)
 */
final class FolderIntake
{
    public const DIR = 'posteingang';
    public const DONE = 'übernommen';
    public const FAILED = 'fehler';
    public const MAX_PER_RUN = 20;
    public const SETTLE_SECONDS = 10;

    public function __construct(private JsonStore $store, private InboxService $inbox) {}

    /** Absoluter Pfad des Ordners (er muss nicht existieren). */
    public function folder(): string
    {
        return rtrim($this->store->rootDir(), '/') . '/' . self::DIR;
    }

    /**
     * Für die Oberfläche: Name des Ordners (relativ zum Datenverzeichnis), ob es
     * ihn gibt, und wie viele Dateien darin auf das Einlesen warten.
     *
     * @return array{dir: string, present: bool, pending: int}
     */
    public function status(): array
    {
        $root = $this->folder();
        $present = is_dir($root) && !is_link($root);
        $pending = 0;
        if ($present) {
            foreach (@scandir($root) ?: [] as $name) {
                if (self::ignored($name)) continue;
                if (is_file("$root/$name") && !is_link("$root/$name")) $pending++;
            }
        }
        return ['dir' => self::DIR, 'present' => $present, 'pending' => $pending];
    }

    /**
     * Ordner einlesen. Fehlt er, wird er angelegt (damit man ihn in der Freigabe
     * findet).
     *
     * @return array{dir: string, imported: list<array>, duplicates: list<array>, failed: list<array>, skipped: list<array>, waiting: int, more: bool}
     */
    public function scan(?int $now = null): array
    {
        $now ??= time();
        $out = ['dir' => self::DIR, 'imported' => [], 'duplicates' => [], 'failed' => [], 'skipped' => [], 'waiting' => 0, 'more' => false];
        $base = realpath($this->store->rootDir());
        $root = $this->folder();
        if ($base === false) return $out;
        if (is_link($root)) {
            $out['skipped'][] = ['name' => self::DIR, 'reason' => 'symlink'];
            return $out;
        }
        if (!is_dir($root)) {
            @mkdir($root, 0775);
            return $out;
        }
        $real = realpath($root);
        if ($real === false || !str_starts_with($real . '/', $base . '/')) return $out;
        $names = @scandir($real) ?: [];
        sort($names, SORT_STRING);
        $n = 0;
        foreach ($names as $name) {
            if (self::ignored($name)) continue;
            $path = $real . '/' . $name;
            $show = self::display($name);
            if (is_link($path)) { $out['skipped'][] = ['name' => $show, 'reason' => 'symlink']; continue; }
            if (!is_file($path)) { $out['skipped'][] = ['name' => $show, 'reason' => 'not_a_file']; continue; }
            $rp = realpath($path);
            if ($rp === false || dirname($rp) !== $real) { $out['skipped'][] = ['name' => $show, 'reason' => 'outside']; continue; }
            clearstatcache(true, $path);
            if ((int)@filemtime($path) > $now - self::SETTLE_SECONDS) { $out['waiting']++; continue; }
            if ($n >= self::MAX_PER_RUN) { $out['more'] = true; break; }
            $n++;
            if ((int)@filesize($path) > AttachmentService::PDF_MAX) {
                $out['failed'][] = ['name' => $show, 'reason' => 'size', 'moved' => $this->move($real, $name, self::FAILED)];
                continue;
            }
            $bytes = @file_get_contents($path, false, null, 0, AttachmentService::PDF_MAX + 1);
            if ($bytes === false) { $out['skipped'][] = ['name' => $show, 'reason' => 'unreadable']; continue; }
            if (AttachmentService::sniff($bytes) !== 'application/pdf') {
                $out['failed'][] = ['name' => $show, 'reason' => 'type', 'moved' => $this->move($real, $name, self::FAILED)];
                continue;
            }
            try {
                $e = $this->inbox->upload($bytes, $show);
                $out['imported'][] = ['name' => $show, 'id' => (string)$e['id'], 'moved' => $this->move($real, $name, self::DONE)];
            } catch (DuplicateDocumentException $d) {
                $out['duplicates'][] = ['name' => $show, 'id' => (string)($d->existing['id'] ?? ''), 'moved' => $this->move($real, $name, self::DONE)];
            } catch (LocalizedException $x) {
                // Speicher für Belege voll: Die Datei kann nichts dafür, sie bleibt liegen
                if ($x->key === 'errors.attachment.quota') {
                    $out['failed'][] = ['name' => $show, 'reason' => 'quota', 'moved' => null];
                    break;
                }
                $out['failed'][] = ['name' => $show, 'reason' => 'rejected', 'code' => $x->key, 'moved' => $this->move($real, $name, self::FAILED)];
            }
        }
        return $out;
    }

    /** Punkt-Dateien, die eigenen Ablagen und die Verzeichniseinträge selbst. */
    private static function ignored(string $name): bool
    {
        return $name === '' || $name[0] === '.' || $name === self::DONE || $name === self::FAILED;
    }

    /** Dateiname für Liste und Antwort: gültiges UTF-8 ohne Steuerzeichen. */
    private static function display(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    }

    /** Datei in eine Ablage verschieben (frei gewählter Name bei Gleichstand); null, wenn das nicht geht. */
    private function move(string $real, string $name, string $sub): ?string
    {
        $dir = $real . '/' . $sub;
        if (is_link($dir)) return null;
        if (!is_dir($dir) && !@mkdir($dir, 0775)) return null;
        $target = self::freeName($dir, self::display($name));
        return @rename($real . '/' . $name, $dir . '/' . $target) ? $sub . '/' . $target : null;
    }

    private static function freeName(string $dir, string $name): string
    {
        $free = fn(string $n) => !file_exists("$dir/$n") && !is_link("$dir/$n");
        if ($free($name)) return $name;
        $dot = strrpos($name, '.');
        $stem = $dot !== false && $dot > 0 ? substr($name, 0, $dot) : $name;
        $ext = $dot !== false && $dot > 0 ? substr($name, $dot) : '';
        for ($i = 2; $i < 1000; $i++) {
            if ($free("$stem ($i)$ext")) return "$stem ($i)$ext";
        }
        return $stem . ' ' . bin2hex(random_bytes(4)) . $ext;
    }
}
