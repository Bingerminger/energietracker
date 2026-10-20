<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Support\Outbound;

/**
 * v3.6.0 — Hinweis auf neue Versionen (nur wenn eingeschaltet).
 *
 * Fragt höchstens einmal am Tag die neueste veröffentlichte Version bei
 * GitHub ab (`releases/latest` des Projekts) und vergleicht sie mit der
 * laufenden. Ohne die Einstellung `update_check` geht keine Anfrage hinaus;
 * bestehende Installationen schalten sie nicht ungefragt ein — die
 * Einrichtung fragt danach, die Einstellungen haben einen Schalter.
 *
 * GitHub sieht dabei die Adresse des Servers, sonst nichts; gesendet wird
 * keine Kennung und keine Nutzungsangabe. Das Ergebnis liegt als Zwischen-
 * stand in `update_check.json` (kein Teil des Backups — beim nächsten Abruf
 * ohnehin neu).
 */
final class UpdateCheckService
{
    public const FILE = 'update_check.json';
    public const URL = 'https://api.github.com/repos/Bingerminger/energietracker/releases/latest';
    /** Abstand zwischen zwei Abfragen. */
    public const TTL_S = 86400;

    /** @param (\Closure(string): string)|null $fetch Abruf (Tests), sonst curl über Outbound */
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private string $current,
        private ?\Closure $fetch = null,
        private ?\Closure $now = null,
    ) {}

    /**
     * @return array{enabled:bool, current:string, latest?:?string, newer?:bool, url?:?string, checked_at?:?string}
     */
    public function check(): array
    {
        if (!$this->settings->get('update_check', false)) return ['enabled' => false, 'current' => $this->current];
        $now = $this->now !== null ? ($this->now)() : time();
        $cache = $this->store->read(self::FILE, []);
        $cache = is_array($cache) ? $cache : [];
        $fresh = isset($cache['checked_ts']) && is_int($cache['checked_ts']) && $now - $cache['checked_ts'] < self::TTL_S;
        if (!$fresh) {
            $latest = null;
            $url = null;
            try {
                $r = json_decode($this->fetch(self::URL), true);
                $tag = is_array($r) ? (string)($r['tag_name'] ?? '') : '';
                if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m)) {
                    $latest = $m[1];
                    $html = (string)($r['html_url'] ?? '');
                    $url = str_starts_with($html, 'https://github.com/Bingerminger/energietracker/') ? $html : null;
                }
            } catch (\Throwable) {
                // offline oder GitHub nicht erreichbar: still, beim nächsten Mal wieder
            }
            // auch ein Fehlschlag merkt sich die Zeit — sonst fragte jede Anfrage erneut
            $cache = ['checked_ts' => $now, 'latest' => $latest ?? ($cache['latest'] ?? null), 'url' => $url ?? ($cache['url'] ?? null)];
            $this->store->write(self::FILE, $cache);
        }
        $latest = isset($cache['latest']) && is_string($cache['latest']) ? $cache['latest'] : null;
        return [
            'enabled'    => true,
            'current'    => $this->current,
            'latest'     => $latest,
            'newer'      => $latest !== null && version_compare($latest, $this->current, '>'),
            'url'        => $cache['url'] ?? null,
            'checked_at' => gmdate('c', (int)$cache['checked_ts']),
        ];
    }

    private function fetch(string $url): string
    {
        if ($this->fetch !== null) return ($this->fetch)($url);
        if (!function_exists('curl_init')) throw new \RuntimeException('no curl');
        $ch = curl_init($url);
        $body = '';
        // nur HTTPS, wenige Weiterleitungen, kleine Antwort (Support\Outbound)
        curl_setopt_array($ch, [CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                                CURLOPT_USERAGENT => 'Energietracker (PHP)'] + Outbound::curlOptions(true, true, $body));
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false || $code !== 200) throw new \RuntimeException("github http $code");
        return $body;
    }
}
