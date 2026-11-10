<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037, Paket protokoll) — Zugriffsprotokoll der KI-Schnittstelle
 * (Konzept §2.7): wer wann was gefragt hat, für Verwalter
 * (`GET /api/mcp/journal`, Karte „KI-Assistenten“).
 *
 * Eine Zeile je Anfrage an /api/mcp (JSON Lines in `data/mcp/journal.jsonl`):
 * Zeit, Schlüssel (ID und Name), Client (Selbstauskunft, bereinigt), Methode,
 * Werkzeug/Prompt/Ressource, Ergebnis, HTTP-Status, Fehlercode, Fassung, Dauer.
 * **Nie** Argumente, Inhalte, Zählerstände, Dokumenttexte oder Schlüssel: Die
 * Einträge entstehen nur aus ENTRY_FIELDS, der Server liefert dafür `log`
 * (ohne Argumente). Ausgeschaltet (`mcp_enabled`, `ET_MCP`) schreibt nichts.
 *
 * Aufbewahrung begrenzt: ab MAX_BYTES wird die Datei zu `journal.1.jsonl` (die
 * vorige fällt weg), Einträge älter als KEEP_DAYS fallen beim Lesen weg und
 * spätestens einen Tag später auch aus den Dateien. Eigene Sperre
 * (`.journal.lock`), nicht über JsonStore, nicht im Backup. Ein Fehler beim
 * Schreiben hält keine Antwort auf (das Protokoll ist ein Nebenprodukt).
 */
final class Journal
{
    public const FILE = 'journal.jsonl';
    public const OLD_FILE = 'journal.1.jsonl';
    /** ab dieser Größe wird gewechselt (höchstens zwei Dateien) */
    public const MAX_BYTES = 1048576;
    /** Einträge älter als so viele Tage fallen weg */
    public const KEEP_DAYS = 90;
    /** GET /api/mcp/journal: Standard und Höchstzahl der Einträge */
    public const LIST_DEFAULT = 100;
    public const LIST_MAX = 500;
    /** Ergebnis einer Anfrage */
    public const RESULTS = ['ok', 'tool_error', 'error', 'denied', 'rate_limited'];
    /** die einzigen Felder eines Eintrags */
    public const ENTRY_FIELDS = ['at', 'key_id', 'key_name', 'client', 'method', 'name', 'result', 'status', 'code', 'error_id', 'version', 'ms'];

    private string $dir;
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock Unix-Zeit (Tests) */
    public function __construct(string $dataDir, ?\Closure $clock = null)
    {
        $this->dir = rtrim($dataDir, '/') . '/mcp';
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Ein Eintrag aus dem `log` des Servers (oder der Ablehnung im Controller).
     *
     * @param array<string,mixed> $log `{method, name, result, version, client, code?, error_id?}`
     * @param array{id:string, name:string, scope?:string}|null $key der geprüfte Schlüssel (nie der Schlüssel selbst)
     * @return array<string,mixed>
     */
    public function entry(array $log, ?array $key, int $status, int $ms): array
    {
        $client = Sanitize::clientInfo($log['client'] ?? null);
        $str = static fn(mixed $v, int $max): ?string => is_string($v) && ($t = Sanitize::text($v, $max)) !== '' ? $t : null;
        $result = in_array($log['result'] ?? null, self::RESULTS, true) ? (string)$log['result'] : 'error';
        $code = is_string($log['code'] ?? null) && preg_match('/^errors\.[A-Za-z0-9_.]{1,80}$/', $log['code']) ? $log['code'] : null;
        $errorId = is_string($log['error_id'] ?? null) && preg_match('/^err_[0-9a-f]{8}$/', $log['error_id']) ? $log['error_id'] : null;
        return array_filter([
            'at'       => date('c', ($this->clock)()),
            'key_id'   => $key !== null && preg_match('/^k_[0-9a-f]{1,32}$/', $key['id']) ? $key['id'] : null,
            'key_name' => $key !== null ? $str($key['name'], 60) : null,
            'client'   => $client !== [] ? $client : null,
            'method'   => $str($log['method'] ?? null, 128),
            'name'     => $str($log['name'] ?? null, 200),
            'result'   => $result,
            'status'   => $status,
            'code'     => $code,
            'error_id' => $errorId,
            'version'  => is_string($log['version'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $log['version']) ? $log['version'] : null,
            'ms'       => max(0, $ms),
        ], fn($v) => $v !== null);
    }

    /** Anhängen; Fehler beim Schreiben werden verschluckt. */
    public function append(array $entry): void
    {
        $entry = array_intersect_key($entry, array_flip(self::ENTRY_FIELDS));
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) return;
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) return;
        $lock = @fopen($this->dir . '/.journal.lock', 'c');
        if ($lock === false) return;
        try {
            if (!flock($lock, LOCK_EX)) return;
            $this->maintain();
            $path = $this->dir . '/' . self::FILE;
            $new = !is_file($path);
            @file_put_contents($path, $line . "\n", FILE_APPEND);
            if ($new) @chmod($path, 0644);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Die jüngsten Einträge, neueste zuerst, ohne die älter als KEEP_DAYS.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = self::LIST_DEFAULT): array
    {
        $limit = max(1, min(self::LIST_MAX, $limit));
        $cutoff = ($this->clock)() - self::KEEP_DAYS * 86400;
        $out = [];
        foreach ([self::OLD_FILE, self::FILE] as $name) {
            foreach (self::lines($this->dir . '/' . $name) as $line) {
                $e = json_decode($line, true);
                if (!is_array($e) || !is_string($e['at'] ?? null)) continue;
                $t = strtotime($e['at']);
                if ($t === false || $t < $cutoff) continue;
                $out[] = array_intersect_key($e, array_flip(self::ENTRY_FIELDS));
            }
        }
        return array_slice(array_reverse($out), 0, $limit);
    }

    /** Wechsel ab MAX_BYTES; alte Einträge aus beiden Dateien (höchstens einmal am Tag). Unter der Sperre. */
    private function maintain(): void
    {
        $path = $this->dir . '/' . self::FILE;
        clearstatcache(true, $path);
        if (is_file($path) && (int)@filesize($path) >= self::MAX_BYTES) {
            @rename($path, $this->dir . '/' . self::OLD_FILE);
        }
        $now = ($this->clock)();
        $cutoff = $now - self::KEEP_DAYS * 86400;
        foreach ([self::FILE, self::OLD_FILE] as $name) {
            $file = $this->dir . '/' . $name;
            $first = self::firstTime($file);
            // ein Tag Spielraum: sonst schriebe bei stetem Verkehr jede Anfrage die Datei neu
            if ($first === null || $first >= $cutoff - 86400) continue;
            $keep = [];
            foreach (self::lines($file) as $line) {
                $e = json_decode($line, true);
                $t = is_array($e) && is_string($e['at'] ?? null) ? strtotime($e['at']) : false;
                if ($t !== false && $t >= $cutoff) $keep[] = $line;
            }
            if ($keep === []) {
                @unlink($file);
                continue;
            }
            $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, implode("\n", $keep) . "\n") !== false && @rename($tmp, $file)) continue;
            @unlink($tmp);
        }
    }

    /** Zeit des ersten Eintrags einer Datei (null: keine Datei oder leer; 0: unlesbar, wird aufgeräumt). */
    private static function firstTime(string $file): ?int
    {
        $fp = @fopen($file, 'rb');
        if ($fp === false) return null;
        $line = fgets($fp);
        fclose($fp);
        if (!is_string($line) || trim($line) === '') return null;
        $e = json_decode($line, true);
        $t = is_array($e) && is_string($e['at'] ?? null) ? strtotime($e['at']) : false;
        return $t === false ? 0 : $t;
    }

    /** @return list<string> nichtleere Zeilen einer Datei */
    private static function lines(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return [];
        return array_values(array_filter(explode("\n", $raw), fn(string $l) => trim($l) !== ''));
    }
}
