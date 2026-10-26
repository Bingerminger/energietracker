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
 * v3.9.0 (F1033) — dazu nach einer beliebigen Anfrage höchstens alle
 * TICK_SECONDS (tick(), App::afterResponse): Im Docker-Image und in der App
 * für Home Assistant ruft der Gesundheitscheck die App alle 30 Sekunden auf,
 * ein abgelegtes PDF ist so nach spätestens fünf Minuten im Posteingang.
 *
 * Ort: `<Datenverzeichnis>/posteingang/`, oder seit v3.9.0 ein eigener Ordner
 * aus der Umgebung `ET_INBOX_DIR` (absoluter Pfad; die App für Home Assistant
 * setzt `/share/energietracker/posteingang`). Den legt der Betreiber fest, er
 * darf deshalb außerhalb des Datenverzeichnisses liegen.
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
    /** v3.9.0 — höchstens so oft liest tick() den Ordner (Marke TICK_MARK im Datenverzeichnis). */
    public const TICK_SECONDS = 300;
    public const TICK_MARK = '.inbox-folder.tick';

    /** @var callable(): (string|false) liefert ET_INBOX_DIR (Tests setzen eigene) */
    private $env;

    public function __construct(private JsonStore $store, private InboxService $inbox, ?callable $env = null)
    {
        $this->env = $env ?? static fn(): string|false => getenv('ET_INBOX_DIR');
    }

    /** v3.9.0 — eigener Ordner aus `ET_INBOX_DIR` (absolut), sonst null. */
    private function custom(): ?string
    {
        $v = ($this->env)();
        $v = is_string($v) ? rtrim(trim($v), '/') : '';
        return $v !== '' && str_starts_with($v, '/') && !str_contains($v, "\0") ? $v : null;
    }

    /** Absoluter Pfad des Ordners (er muss nicht existieren). */
    public function folder(): string
    {
        return $this->custom() ?? rtrim($this->store->rootDir(), '/') . '/' . self::DIR;
    }

    /** Name für Oberfläche und Antwort: relativ zum Datenverzeichnis, bei `ET_INBOX_DIR` der volle Pfad. */
    private function label(): string
    {
        return $this->custom() ?? self::DIR;
    }

    /**
     * v3.9.0 (F1033) — Ist ein Lauf nach der Antwort fällig? Nur ein `stat`:
     * Der Ordner existiert und die Marke ist älter als TICK_SECONDS.
     */
    public function tickDue(?int $now = null): bool
    {
        $root = $this->folder();
        if (!is_dir($root) || is_link($root)) return false;
        $mark = rtrim($this->store->rootDir(), '/') . '/' . self::TICK_MARK;
        clearstatcache(true, $mark);
        return !is_file($mark) || (int)@filemtime($mark) <= ($now ?? time()) - self::TICK_SECONDS;
    }

    /**
     * v3.9.0 (F1033) — Lauf nach der Antwort: Marke setzen, dann einlesen,
     * wenn etwas wartet. Lässt sich die Marke nicht setzen, bleibt es beim
     * Einlesen beim Öffnen (sonst liefe der Ordner bei jeder Anfrage).
     *
     * @return array<string,mixed>|null Ergebnis von scan(), null ohne Lauf
     */
    public function tick(?int $now = null): ?array
    {
        $now ??= time();
        if (!$this->tickDue($now)) return null;
        if (!@touch(rtrim($this->store->rootDir(), '/') . '/' . self::TICK_MARK, $now)) return null;
        return $this->status()['pending'] > 0 ? $this->scan($now) : null;
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
        return ['dir' => $this->label(), 'present' => $present, 'pending' => $pending];
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
        $out = ['dir' => $this->label(), 'imported' => [], 'duplicates' => [], 'failed' => [], 'skipped' => [], 'waiting' => 0, 'more' => false];
        $base = realpath($this->store->rootDir());
        $root = $this->folder();
        if ($base === false) return $out;
        if (is_link($root)) {
            $out['skipped'][] = ['name' => $this->label(), 'reason' => 'symlink'];
            return $out;
        }
        if (!is_dir($root)) {
            @mkdir($root, 0775, $this->custom() !== null);
            return $out;
        }
        $real = realpath($root);
        // im Datenverzeichnis, außer der Betreiber hat einen eigenen Ordner gesetzt (ET_INBOX_DIR)
        if ($real === false || ($this->custom() === null && !str_starts_with($real . '/', $base . '/'))) return $out;
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
