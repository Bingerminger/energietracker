<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.2.0 (F1022) — Ladevorgänge aus evcc übernehmen.
 *
 * evcc steuert die Wallbox (PV-Überschuss, günstige Stunden) und führt je
 * Ladevorgang Beginn, Ende, Energie, Sonnenanteil, Preis und — wo die Wallbox
 * es misst — den Zählerstand. Der Energietracker rechnet damit nach:
 *
 *   • Ladevorgänge  → `ev_sessions.json` (je Vorgang, für Sonnenanteil und
 *     den Preis, den evcc ausgewiesen hat; Monatssummen in monthly())
 *   • Zählerstände  → Ablesungen des Wallbox-Zählers: der höchste
 *     Endzählerstand je Tag; ohne Zählerstände die aufsummierte Energie ab
 *     dem letzten Stand davor (oder dem Anfangsstand des Geräts)
 *
 * Zwei Wege: der CSV-Export aus evcc (Kopfzeilen in der Sprache, in der er
 * heruntergeladen wurde — erkannt werden die Sprachen der App und die
 * englischen Spaltennamen) und der Abruf von `GET /api/sessions` — nur im
 * eigenen Netz wie die Texterkennung: Der Name wird aufgelöst, jede Adresse
 * muss lokal sein, verbunden wird mit genau der geprüften, Weiterleitungen
 * werden nicht verfolgt.
 *
 * Zeiten: evcc schreibt „JJJJ-MM-TT HH:MM:SS“ (CSV) bzw. RFC 3339 (JSON); ein
 * Tag ist ein Tag in der Zeitzone der Installation.
 */
final class EvccService
{
    public const FILE = 'ev_sessions.json';
    /** Woher die Ablesungen kommen: auto = Zählerstand der Wallbox, sonst Energie aufsummiert. */
    public const COUNTERS = ['auto', 'energy', 'none'];

    /**
     * Spalten des evcc-Exports (i18n `sessions.csv.*` in de, en, fr, it, es,
     * pt, nl, dazu die englischen Struct-Namen und die JSON-Felder), klein
     * geschrieben.
     */
    private const COLUMNS = [
        'created'       => ['created', 'startzeit', 'créé', 'creato', 'hora de inicio', 'hora de início', 'aangemaakt'],
        'finished'      => ['finished', 'endzeit', 'terminé', 'finito', 'terminado', 'hora final', 'beëindigd'],
        'loadpoint'     => ['loadpoint', 'charging point', 'ladepunkt', 'point de charge', 'punto di ricarica', 'punto de carga', 'posto de carregamento', 'oplaadpunt'],
        'vehicle'       => ['vehicle', 'fahrzeug', 'véhicule', 'veicolo', 'vehículo', 'veículo', 'voertuig'],
        'meter_start'   => ['meterstart', 'meter start (kwh)', 'anfangszählerstand (kwh)', 'début de compteur (kwh)', 'inizio contatore (kwh)', 'inicio de metro (kwh)', 'início do contador (kwh)', 'beginwaarde meter (kwh)'],
        'meter_stop'    => ['meterstop', 'meter stop (kwh)', 'endzählerstand (kwh)', 'fin de compteur (kwh)', 'ferma contatore (kwh)', 'parón de metro (kwh)', 'contagem final (kwh)', 'eindwaarde meter (kwh)'],
        'charged_kwh'   => ['chargedenergy', 'charged energy (kwh)', 'energy (kwh)', 'energie (kwh)', 'énergie (kwh)', 'energia (kwh)', 'energía (kwh)'],
        'solar_pct'     => ['solarpercentage', 'solar (%)', 'sonne (%)', 'solaire (%)', 'solare (%)', 'zonne-energie (%)'],
        'price'         => ['price', 'cost', 'kosten', 'coût', 'prezzo', 'precio', 'custo'],
        'price_per_kwh' => ['priceperkwh', 'price/kwh', 'preis/kwh', 'prix/kwh', 'prezzo/kwh', 'precio/kwh', 'preço/kwh', 'prijs/kwh'],
    ];

    /** @var callable(string, int, ?string): array{ok:bool, body:?string, http_code:?int, error_code:?string} */
    private $transport;
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ReadingService $readings,
        private ReadingImportService $import,
        private SettingsService $settings,
        ?callable $transport = null,
        ?callable $resolver = null,
    ) {
        $this->transport = $transport ?? self::curlGet(...);
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * CSV-Export aus evcc übernehmen.
     *
     * Optionen: `dry_run` (nur Vorschau), `counters` — `auto` (Zählerstände
     * der Wallbox, sonst aufsummierte Energie), `energy` (immer aufsummieren,
     * etwa wenn der Zähler im Energietracker ein anderer ist als der in der
     * Wallbox) oder `none` (nur die Vorgänge, keine Ablesungen) —, `loadpoint`
     * (nur dieser Ladepunkt).
     *
     * @param array{dry_run?:bool, counters?:string, loadpoint?:?string} $opts
     * @return array<string,mixed> Vorschau bzw. Ergebnis
     */
    public function importCsv(string $meterId, string $csv, array $opts = []): array
    {
        return $this->apply($meterId, self::parseCsv($csv), 'csv', $opts);
    }

    /**
     * Ladevorgänge von evcc abrufen (`evcc_endpoint`, nur im eigenen Netz).
     *
     * @param array{dry_run?:bool, counters?:string, loadpoint?:?string} $opts wie importCsv()
     * @return array<string,mixed>
     */
    public function sync(string $meterId, array $opts = []): array
    {
        $base = rtrim(trim((string)$this->settings->get('evcc_endpoint', '')), '/');
        if ($base === '') throw new LocalizedException('errors.evcc.off', [], 'evcc endpoint not configured');
        $url = $base . '/api/sessions';
        $ip = $this->checkLocal($url);
        $r = ($this->transport)($url, 30, $ip);
        if (!$r['ok']) throw new LocalizedException('errors.evcc.unreachable', ['code' => (string)($r['http_code'] ?? $r['error_code'] ?? '')], 'evcc unreachable');
        $json = json_decode((string)$r['body'], true);
        if (is_array($json) && isset($json['result']) && is_array($json['result'])) $json = $json['result'];   // ältere evcc: {result: […]}
        if (!is_array($json)) throw new LocalizedException('errors.evcc.badAnswer', [], 'evcc answer is not a list');
        $rows = [];
        foreach ($json as $s) {
            if (!is_array($s)) continue;
            $rows[] = [
                'created' => $s['created'] ?? null, 'finished' => $s['finished'] ?? null,
                'loadpoint' => $s['loadpoint'] ?? '', 'vehicle' => $s['vehicle'] ?? '',
                'meter_start' => $s['meterStart'] ?? null, 'meter_stop' => $s['meterStop'] ?? null,
                'charged_kwh' => $s['chargedEnergy'] ?? null, 'solar_pct' => $s['solarPercentage'] ?? null,
                'price' => $s['price'] ?? null, 'price_per_kwh' => $s['pricePerKWh'] ?? null,
            ];
        }
        return $this->apply($meterId, $rows, 'evcc', $opts);
    }

    /**
     * Ladevorgänge eines Zählers, neueste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function sessions(?string $meterId = null, ?int $year = null): array
    {
        $all = array_values(array_filter((array)$this->store->read(self::FILE, []), 'is_array'));
        $all = array_values(array_filter($all, fn($s) => ($meterId === null || ($s['meter_id'] ?? null) === $meterId)
            && ($year === null || str_starts_with((string)($s['date'] ?? ''), (string)$year))));
        usort($all, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
        return $all;
    }

    /**
     * Monatssummen: geladene kWh, davon aus der Sonne (nach dem Sonnenanteil
     * je Vorgang), und der Preis, den evcc ausgewiesen hat.
     *
     * @return array<string, array{kwh:float, solar_kwh:float, solar_pct:?float, price_eur:?float, sessions:int}>
     */
    public function monthly(string $meterId, int $year): array
    {
        $out = [];
        foreach ($this->sessions($meterId, $year) as $s) {
            $ym = substr((string)$s['date'], 0, 7);
            $m = $out[$ym] ?? ['kwh' => 0.0, 'solar_kwh' => 0.0, 'solar_pct' => null, 'price_eur' => null, 'sessions' => 0];
            $kwh = (float)($s['charged_kwh'] ?? 0);
            $m['kwh'] += $kwh;
            if (isset($s['solar_pct'])) $m['solar_kwh'] += $kwh * (float)$s['solar_pct'] / 100;
            if (isset($s['price_eur'])) $m['price_eur'] = ($m['price_eur'] ?? 0.0) + (float)$s['price_eur'];
            $m['sessions']++;
            $out[$ym] = $m;
        }
        ksort($out);
        foreach ($out as $ym => $m) {
            $out[$ym]['kwh'] = round($m['kwh'], 3);
            $out[$ym]['solar_kwh'] = round($m['solar_kwh'], 3);
            $out[$ym]['solar_pct'] = $m['kwh'] > 0 ? round($m['solar_kwh'] / $m['kwh'] * 100, 1) : null;
            if ($m['price_eur'] !== null) $out[$ym]['price_eur'] = round($m['price_eur'], 2);
        }
        return $out;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * Gemeinsamer Weg für CSV und Abruf: Vorgänge prüfen, speichern
     * (Wiederholung ersetzt), Zählerstände ableiten.
     *
     * @param list<array<string,mixed>> $rows
     * @param array{dry_run?:bool, counters?:string, loadpoint?:?string} $opts
     * @return array<string,mixed>
     */
    private function apply(string $meterId, array $rows, string $source, array $opts): array
    {
        $meter = $this->meters->get('strom', $meterId)
            ?? throw new LocalizedException('errors.common.meterNotFound', ['id' => $meterId], "meter $meterId");
        if (($meter['capture'] ?? 'counter') === 'period') throw new LocalizedException('errors.reading.periodMeter', [], 'evcc on period meter');
        $counters = (string)($opts['counters'] ?? 'auto');
        if (!in_array($counters, self::COUNTERS, true)) {
            throw new LocalizedException('errors.evcc.countersInvalid', ['value' => $counters], 'evcc counters');
        }
        $only = trim((string)($opts['loadpoint'] ?? ''));
        $tz = new \DateTimeZone((string)$this->settings->get('timezone', 'Europe/Berlin'));
        $sessions = []; $skipped = 0; $running = 0; $loadpoints = [];
        foreach ($rows as $r) {
            $created = self::time($r['created'] ?? null, $tz);
            $kwh = self::num($r['charged_kwh'] ?? null);
            if ($created === null || $kwh === null || $kwh < 0) { $skipped++; continue; }
            $loadpoint = mb_substr(trim((string)($r['loadpoint'] ?? '')), 0, 60);
            if ($loadpoint !== '') $loadpoints[$loadpoint] = true;
            if ($only !== '' && $loadpoint !== $only) continue;
            // Läuft noch (evcc: Ende leer bzw. 0001-01-01) — kommt beim nächsten Mal vollständig
            $finished = self::time($r['finished'] ?? null, $tz);
            if ($finished === null) { $running++; continue; }
            $sessions[] = array_filter([
                'id'            => 'evs_' . substr(sha1($created->format('c') . '|' . $loadpoint), 0, 12),
                'meter_id'      => $meterId,
                'created'       => $created->format('Y-m-d\TH:i:sP'),
                'finished'      => $finished->format('Y-m-d\TH:i:sP'),
                'date'          => $finished->format('Y-m-d'),
                'loadpoint'     => $loadpoint,
                'vehicle'       => mb_substr(trim((string)($r['vehicle'] ?? '')), 0, 60),
                'charged_kwh'   => round($kwh, 3),
                'solar_pct'     => ($v = self::num($r['solar_pct'] ?? null)) !== null ? round(max(0, min(100, $v)), 1) : null,
                'price_eur'     => ($v = self::num($r['price'] ?? null)) !== null ? round($v, 2) : null,
                'price_per_kwh' => ($v = self::num($r['price_per_kwh'] ?? null)) !== null ? round($v, 4) : null,
                'meter_start'   => ($v = self::num($r['meter_start'] ?? null)) !== null ? round($v, 3) : null,
                'meter_stop'    => ($v = self::num($r['meter_stop'] ?? null)) !== null ? round($v, 3) : null,
                'source'        => $source,
            ], fn($v) => $v !== null);
        }
        if ($sessions === []) throw new LocalizedException('errors.evcc.noSessions', [], 'no sessions');
        usort($sessions, fn($a, $b) => strcmp($a['created'], $b['created']));

        $counterSource = $counters === 'none' ? 'none'
            : ($counters === 'auto' && array_filter(array_column($sessions, 'meter_stop')) !== [] ? 'meter' : 'energy');
        // Zählerstände gehören zu genau einer Wallbox: Stände (oder Summen)
        // zweier Ladepunkte an einem Zähler ergäben Unsinn
        $chosen = array_values(array_unique(array_column($sessions, 'loadpoint')));
        if ($counterSource !== 'none' && count($chosen) > 1) {
            throw new LocalizedException('errors.evcc.loadpointNeeded', ['names' => implode(', ', $chosen)], 'evcc: several loadpoints');
        }
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $readings = $counterSource === 'none' ? [] : $this->readingsFrom($meter, $sessions, $counterSource === 'meter', $today);
        $existing = array_column(array_filter($this->readings->list('strom', $meterId), fn($r) => empty($r['is_future'])), 'date');
        $total = array_sum(array_column($sessions, 'charged_kwh'));
        $result = [
            'sessions' => count($sessions), 'skipped' => $skipped, 'running' => $running,
            'loadpoints' => array_keys($loadpoints),
            'from' => $sessions[0]['date'], 'to' => end($sessions)['date'],
            'charged_kwh' => round($total, 3),
            'solar_kwh' => round(array_sum(array_map(fn($s) => $s['charged_kwh'] * ($s['solar_pct'] ?? 0) / 100, $sessions)), 3),
            'readings' => count($readings),
            'replaces' => count(array_intersect(array_column($readings, 'date'), $existing)),
            'counter_source' => $counterSource,
        ];
        if (!empty($opts['dry_run'])) return $result + ['dry_run' => true];

        $stored = [];
        foreach ((array)$this->store->read(self::FILE, []) as $s) if (is_array($s) && isset($s['id'])) $stored[$s['id']] = $s;
        foreach ($sessions as $s) $stored[$s['id']] = $s;
        $this->store->write(self::FILE, array_values($stored));
        $result['import'] = $readings === [] ? null : $this->import->importRows('strom', $meterId, $readings);
        return $result;
    }

    /**
     * Zählerstände aus den Vorgängen. Ein Stand am Tag D ist in der App der
     * Stand zu Beginn des Tages — der Verbrauch von D liegt zwischen D und
     * D + 1. Daher: der Anfangsstand am Tag des Beginns, der Endstand am Tag
     * nach dem Ende. So landet jede Ladung in ihrem Tag (und Monat). Ohne
     * Zählerstände der Wallbox: die aufsummierte Energie ab dem letzten Stand
     * bis zum Tag des ersten Vorgangs. Stände nach heute entfallen (sie kommen
     * mit dem nächsten Abruf), ein Rückgang ebenso.
     *
     * @param list<array<string,mixed>> $sessions
     * @return list<array{date:string, counter:float, note:string, is_estimated:bool}>
     */
    private function readingsFrom(array $meter, array $sessions, bool $fromMeter, string $today): array
    {
        $byDay = [];
        $next = fn(string $d): string => (new \DateTimeImmutable($d . ' 12:00:00'))->modify('+1 day')->format('Y-m-d');
        if ($fromMeter) {
            foreach ($sessions as $s) {
                if (isset($s['meter_start'])) {
                    $d = substr($s['created'], 0, 10);
                    $byDay[$d] = isset($byDay[$d]) ? min($byDay[$d], $s['meter_start']) : $s['meter_start'];
                }
                if (isset($s['meter_stop'])) {
                    $d = $next($s['date']);
                    $byDay[$d] = max($byDay[$d] ?? 0.0, $s['meter_stop']);
                }
            }
        } else {
            $first = $sessions[0]['date'];
            $anchor = null; $anchorDate = '';
            foreach ($this->readings->list('strom', (string)$meter['id']) as $r) {
                if (!empty($r['is_future']) || (string)$r['date'] > $first) continue;
                if ((string)$r['date'] >= $anchorDate) { $anchorDate = (string)$r['date']; $anchor = (float)$r['counter']; }
            }
            if ($anchor === null) {
                foreach ((array)($meter['devices'] ?? []) as $dev) {
                    if (empty($dev['removed_on'])) $anchor = (float)($dev['initial_counter'] ?? 0);
                }
            }
            $cum = $anchor ?? 0.0;
            foreach ($sessions as $s) {
                $cum += (float)$s['charged_kwh'];
                $byDay[$next($s['date'])] = $cum;
            }
        }
        ksort($byDay);
        $out = []; $last = null;
        foreach ($byDay as $d => $c) {
            if ($d > $today || ($last !== null && $c < $last)) continue;
            $out[] = ['date' => (string)$d, 'counter' => round((float)$c, 3), 'note' => 'evcc', 'is_estimated' => false];
            $last = $c;
        }
        return $out;
    }

    /**
     * CSV-Export von evcc lesen: Komma (oder Semikolon) als Trenner, Kopfzeile
     * in einer der Sprachen; Zahlen mit Punkt (ältere Exporte auch mit Komma).
     *
     * @return list<array<string,mixed>>
     */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) throw new LocalizedException('errors.evcc.noSessions', [], 'empty csv');
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(fn($h) => mb_strtolower(trim((string)$h)), str_getcsv($lines[0], $sep, '"', ''));
        $col = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($head as $i => $h) if (in_array($h, $names, true)) { $col[$field] = $i; break; }
        }
        if (!isset($col['created'], $col['charged_kwh'])) {
            throw new LocalizedException('errors.evcc.columns', [], 'evcc csv without created/energy');
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line, $sep, '"', '');
            $row = [];
            foreach ($col as $field => $i) $row[$field] = $cells[$i] ?? null;
            $rows[] = $row;
        }
        return $rows;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        return is_scalar($v) ? ReadingImportService::parseNum((string)$v) : null;
    }

    private static function time(mixed $v, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $s = trim((string)$v);
        if ($s === '' || str_starts_with($s, '0001-01-01')) return null;
        try {
            $hasZone = (bool)preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s);
            $t = new \DateTimeImmutable($s, $tz);
            return $hasZone ? $t->setTimezone($tz) : $t;
        } catch (\Exception) {
            return null;
        }
    }

    /** Name auflösen; jede Adresse muss lokal sein (wie die Texterkennung). */
    private function checkLocal(string $url): string
    {
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) throw new LocalizedException('errors.evcc.unreachable', ['code' => 'dns'], "evcc host $host does not resolve");
        foreach ($ips as $ip) {
            if (!OcrService::isLocalIp($ip)) throw new LocalizedException('errors.evcc.notLocal', ['host' => $host], "evcc host $host is not local ($ip)");
        }
        return $ips[0];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /**
     * GET über curl an die geprüfte Adresse (CURLOPT_RESOLVE), ohne
     * Weiterleitungen.
     *
     * @return array{ok:bool, body:?string, http_code:?int, error_code:?string}
     */
    private static function curlGet(string $url, int $timeout, ?string $ip): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'noTransport'];
        $p = parse_url($url);
        $port = (int)($p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $host = trim((string)($p['host'] ?? ''), '[]');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Energietracker (PHP)',
        ];
        if ($ip !== null && !filter_var($host, FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) return ['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network'];
        if ($code < 200 || $code >= 300) return ['ok' => false, 'body' => is_string($body) ? $body : null, 'http_code' => $code, 'error_code' => 'http'];
        return ['ok' => true, 'body' => (string)$body, 'http_code' => $code, 'error_code' => null];
    }
}
