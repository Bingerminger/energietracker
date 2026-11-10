<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1035, Paket basis) — „Deine Rechte als Kunde“: Registry der Prüfungen und die
 * eine Befund-Form für Liste (`GET /api/rights`), Empfehlung (Kategorie `recht`), „Zu tun“
 * (Agenda-Art `rights`), „Rechnung prüfen“ und Brief.
 *
 * Die Prüfungen liefern die Fachpakete (A1 bis A7, Konzept §3); sie melden sich in
 * src/bootstrap.php mit register() an. Eine Prüfung ist eine Funktion
 *   fn(string $today, string $country): list<array>   — Rohbefunde, s. finding()
 * Sie liest selbst, was sie braucht (Rechnungen, Verträge, Zähler), und gibt für jeden
 * Fund einen Rohbefund zurück. Alles Übrige macht dieser Dienst:
 *   - Land: nur, wenn Config\Rights Regeln für das Land hat (Entscheidung 1a: nur DE);
 *     sonst ruft er keine Prüfung auf;
 *   - Form: prüft und ergänzt jeden Rohbefund (finding()); ein Fehler in der Form ist ein
 *     Programmierfehler (\LogicException), all() hält ihn von den anderen fern (`failed`);
 *   - ID: stabil aus Prüfung und Bezug (`rights-<check>-<12 hex>`), daran hängen Ausblenden
 *     (RecommendationService) und Erledigt (ActivityService, Vorkommen am `date`);
 *   - Fenster (Grundsatz 5, Lektion 93): `todo` nur, wenn eine Frist noch läuft oder der
 *     Anlass höchstens WINDOW_DAYS zurückliegt; „Rechnung prüfen“ zeigt alle;
 *   - Texte: Titel, Text und Fußzeile („Hinweis, keine Rechtsberatung. Grundlage: …“) in
 *     der Sprache der Anfrage, Normen mit Quelle und Stand aus Config\Rights.
 *
 * Die Form eines Befunds beschreibt docs/referenz/api.md, Abschnitt „Rechte als Kunde“; ein
 * Beispiel steht in tests/unit/Services/RightsServiceTest.php (Beispiel-Prüfung).
 */
final class RightsService
{
    /**
     * Prüfungen (Konzept §3) und die Briefe, die ihre Befunde anbieten dürfen (Entscheidung
     * 2a: nur auf Knopfdruck). Eine neue Prüfung kommt hier dazu, bevor sie sich anmeldet.
     */
    public const CHECKS = [
        'bill_late'                => ['complaint'],            // A1 Rechnung zu spät
        'advance_high'             => ['advance_adjustment'],   // A2 Abschlag deutlich zu hoch
        'special_termination'      => ['special_termination'],  // A3 Preiserhöhung, Sonderkündigung, Frist
        'bonus_missing'            => ['complaint'],            // A4 Bonus nicht gutgeschrieben
        'estimate_despite_reading' => ['estimate_query'],       // A5 Schätzung trotz gemeldetem Stand
        'metering_cap'             => ['complaint'],            // A6 Messentgelt über der Obergrenze
        'fee_notice_deadline'      => ['objection'],            // A7 Frist beim Gebührenbescheid
    ];

    /** Briefvorlagen (Konzept §4, Paket briefe). */
    public const LETTERS = ['special_termination', 'advance_adjustment', 'estimate_query', 'complaint', 'objection'];

    public const SEVERITIES = ['info', 'warning'];

    /** Grundsatz 5 — dasselbe Fenster wie „Abrechnung erwartet“ (120 Tage). */
    public const WINDOW_DAYS = BillExpectedService::WINDOW_DAYS;

    public const ID_PATTERN = '/^rights-[a-z_]+-[0-9a-f]{12}$/';

    /** Felder des Bezugs: Verbrauchsart, Zähler (oder Gruppe), Vertrag, Rechnung. */
    public const REF_FIELDS = ['utility', 'meter_id', 'contract_id', 'bill_id'];

    /** @var array<string, list<callable>> */
    private array $checks = [];

    /** @var list<array{check:string, error:string}> Prüfungen, die beim letzten all() scheiterten */
    private array $failed = [];

    public function __construct(
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /**
     * Prüfung anmelden: `$fn(string $today, string $country): list<array>` liefert Rohbefunde.
     * Mehrere Funktionen je Prüfung sind erlaubt (z. B. Strom/Gas und Fernwärme getrennt).
     */
    public function register(string $check, callable $fn): void
    {
        // ein Programmierfehler (beim Start), kein Text für Nutzer
        if (!isset(self::CHECKS[$check])) throw new \LogicException("rights: check '$check' is not listed in RightsService::CHECKS");
        $this->checks[$check][] = $fn;
        $this->memo = null;
    }

    /**
     * v3.11.0 (integration) — Zwischenspeicher je Anfrage: Empfehlungen, „Zu tun“ und Zusammenfassung
     * fragen in einer Anfrage dieselben Befunde ab. Gilt für denselben Tag, dasselbe Land, dieselbe
     * Sprache und denselben Schreibstand der Daten (JsonStore::generation über SettingsService::version):
     * Jede Änderung an den Daten rechnet neu. [Schlüssel, Befunde, gescheiterte Prüfungen]
     *
     * @var array{0:string, 1:list<array<string,mixed>>, 2:list<array{check:string, error:string}>}|null
     */
    private ?array $memo = null;

    /** Zwischenspeicher verwerfen: für Prüfungen, deren Zustand nicht in den Daten liegt (Tests). */
    public function forget(): void
    {
        $this->memo = null;
    }

    private function memoKey(string $today): string
    {
        return $today . '|' . $this->country() . '|' . $this->i18n->locale() . '|' . $this->settings->version();
    }

    /** @return list<string> angemeldete Prüfungen */
    public function registered(): array
    {
        return array_keys($this->checks);
    }

    public function country(): string
    {
        return (string)$this->settings->get('country', 'DE');
    }

    /** Hinweise für dieses Land? (Grundsatz 1: nur DE; Gate wie RecommendationService) */
    public function enabled(): bool
    {
        return Rights::supports($this->country());
    }

    /**
     * Alle Befunde, sortiert (Frist zuerst, dann Anlass, dann ID). Eine Prüfung, die eine
     * Ausnahme wirft, fehlt — die anderen bleiben (`failed()` nennt sie, `GET /api/rights`
     * auch). Ohne Regeln für das Land: leer, keine Prüfung läuft.
     *
     * @return list<array<string,mixed>>
     */
    public function findings(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if ($this->memo !== null && $this->memo[0] === $this->memoKey($today)) {
            $this->failed = $this->memo[2];
            return $this->memo[1];
        }
        $this->failed = [];
        $out = [];   // das Land prüft run(): ohne Regeln läuft keine Prüfung
        foreach (array_keys($this->checks) as $check) {
            try {
                foreach ($this->run($check, $today) as $f) $out[$f['id']] = $f;
            } catch (\Throwable $e) {
                $this->failed[] = ['check' => $check, 'error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        $out = array_values($out);
        usort($out, fn($a, $b) => [$a['deadline'] ?? '9999-12-31', $a['date'], $a['id']] <=> [$b['deadline'] ?? '9999-12-31', $b['date'], $b['id']]);
        // Schlüssel nach dem Rechnen: Prüfungen lesen nur, ein Zwischenspeicher einer Rechnung darf schreiben
        $this->memo = [$this->memoKey($today), $out, $this->failed];
        return $out;
    }

    /** @return list<array{check:string, error:string}> Prüfungen, die beim letzten findings() scheiterten */
    public function failed(): array
    {
        return $this->failed;
    }

    /**
     * Eine Prüfung streng ausführen (Tests, Fehlersuche): Ausnahmen gehen durch, jeder
     * Rohbefund läuft durch finding(). Eine unbekannte oder nicht angemeldete Prüfung liefert [].
     *
     * @return list<array<string,mixed>>
     */
    public function run(string $check, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $country = $this->country();
        if (!Rights::supports($country)) return [];
        $out = [];
        foreach ($this->checks[$check] ?? [] as $fn) {
            $raw = $fn($today, $country);
            if (!is_array($raw)) throw new \LogicException("rights: check '$check' must return a list");
            foreach ($raw as $r) {
                if (!is_array($r)) throw new \LogicException("rights: check '$check' returned a non-array finding");
                $f = $this->finding(['check' => $check] + $r, $today, $country);
                // zwei Befunde mit derselben ID: einer ginge still verloren (Ausblenden, Erledigt) → `key`
                if (isset($out[$f['id']])) throw new \LogicException("rights: check '$check' returned the same id twice, add a key");
                $out[$f['id']] = $f;
            }
        }
        return array_values($out);
    }

    /** Ein Befund über seine ID (auch außerhalb des Fensters) oder null — für den Brief. */
    public function find(string $id, ?string $today = null): ?array
    {
        foreach ($this->findings($today) as $f) if ($f['id'] === $id) return $f;
        return null;
    }

    /**
     * Stabile ID: Prüfung und Bezug (wie RecommendationService::ruleId). Ohne `key` des
     * Rohbefunds die Felder des Bezugs; mit `key` zusätzlich dessen Teile (zwei Befunde
     * derselben Prüfung am selben Vertrag, etwa zwei Preisänderungen).
     *
     * @param list<scalar|null> $parts
     */
    public static function id(string $check, array $parts): string
    {
        return 'rights-' . $check . '-' . substr(md5('rights|' . $check . '|' . implode('|', array_map(fn($p) => (string)$p, $parts))), 0, 12);
    }

    /** Grundsatz 5 — erscheint der Befund unter „Zu tun“ und als Empfehlung? */
    public static function inWindow(?string $deadline, string $date, string $today): bool
    {
        if ($deadline !== null) return $deadline >= $today;
        return $date >= (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * Rohbefund prüfen und ergänzen (die Befund-Form). Pflicht: check, severity, utility,
     * date, rules, title_key, text_key. Optional: meter_id, contract_id, bill_id, deadline,
     * todo_until (v3.12.0), amount_eur, facts, params, letter, key, href. Fehler → \LogicException (ein Fehler der
     * Prüfung, kein Fehler der Daten).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function finding(array $raw, string $today, ?string $country = null): array
    {
        $country ??= $this->country();
        $check = (string)($raw['check'] ?? '');
        $bad = fn(string $what) => new \LogicException("rights: $check: $what");
        if (!isset(self::CHECKS[$check])) throw $bad('unknown check');
        $severity = $raw['severity'] ?? null;
        if (!in_array($severity, self::SEVERITIES, true)) throw $bad('severity must be info or warning');
        $utility = $raw['utility'] ?? null;
        if (!is_string($utility) || !Utilities::exists($utility)) throw $bad('utility missing or unknown');
        $ref = ['utility' => $utility];
        foreach (['meter_id', 'contract_id', 'bill_id'] as $k) {
            $v = $raw[$k] ?? null;
            if ($v !== null && (!is_string($v) || $v === '')) throw $bad("$k must be a non-empty string or null");
            $ref[$k] = $v;
        }
        $date = $raw['date'] ?? null;
        if (!Dates::isIsoDate($date)) throw $bad('date must be YYYY-MM-DD');
        $deadline = $raw['deadline'] ?? null;
        if ($deadline !== null && !Dates::isIsoDate($deadline)) throw $bad('deadline must be YYYY-MM-DD or null');
        // v3.12.0 — optional: „Zu tun“ bis zu diesem Tag, auch über die Frist hinaus (Weichzone beim Gebührenbescheid)
        $todoUntil = $raw['todo_until'] ?? null;
        if ($todoUntil !== null && (!Dates::isIsoDate($todoUntil) || $deadline === null || $todoUntil < $deadline)) {
            throw $bad('todo_until must be YYYY-MM-DD, not before the deadline');
        }
        $amount = $raw['amount_eur'] ?? null;
        if ($amount !== null && (!(is_int($amount) || is_float($amount)) || !is_finite((float)$amount))) throw $bad('amount_eur must be a number or null');

        $rules = $raw['rules'] ?? null;
        if (!is_array($rules) || $rules === [] || !array_is_list($rules)) throw $bad('rules must be a non-empty list');
        $norms = [];
        foreach ($rules as $key) {
            if (!is_string($key) || !Rights::citable($country, $key)) throw $bad('rule ' . (is_string($key) ? $key : '?') . ' unknown or not citable');
            $r = Rights::rule($country, $key);
            $norms[] = ['key' => $key, 'norm' => $r['norm'], 'url' => $r['url'], 'checked_on' => $r['checked_on'], 'status' => $r['status']];
        }

        $facts = $raw['facts'] ?? [];
        if (!is_array($facts) || ($facts !== [] && array_is_list($facts))) throw $bad('facts must be an object');
        if (json_encode($facts) === false) throw $bad('facts must be JSON');
        $params = $raw['params'] ?? array_filter($facts, fn($v) => $v === null || is_scalar($v));
        if (!is_array($params)) throw $bad('params must be an object');
        foreach ($params as $k => $v) {
            if (!is_string($k) || !($v === null || is_scalar($v))) throw $bad("param $k must be scalar");
        }
        foreach (['title_key', 'text_key'] as $k) {
            if (!is_string($raw[$k] ?? null) || !str_starts_with($raw[$k], 'rights.')) throw $bad("$k must be a catalog key below rights.");
        }
        $letter = $raw['letter'] ?? null;
        if ($letter !== null && !in_array($letter, self::CHECKS[$check], true)) throw $bad("letter '$letter' not offered by this check");
        $key = $raw['key'] ?? [];
        if (!is_array($key) || !array_is_list($key) || array_filter($key, fn($p) => !($p === null || is_scalar($p))) !== []) throw $bad('key must be a list of scalars');
        $href = $raw['href'] ?? null;
        if ($href !== null && (!is_string($href) || !str_starts_with($href, '#/'))) throw $bad('href must start with #/');

        $id = self::id($check, [...array_values($ref), ...$key]);
        // v3.11.0 (Skeptiker N1) — jeder Titel kann die Verbrauchsart nennen (`{label}`): In „Zu tun“ standen
        // sonst zwei gleiche „Rechnung schätzt den Endstand …“ (Gas, Wasser) ohne Hinweis, welche
        $display = $this->displayParams($params + ['label' => $this->i18n->utilityLabel($utility)]);
        return [
            'id'          => $id,
            'check'       => $check,
            'severity'    => $severity,
            ...$ref,
            'date'        => $date,
            'deadline'    => $deadline,
            'remind_on'   => $deadline !== null ? Rights::remindOn($deadline) : null,
            'amount_eur'  => $amount === null ? null : round((float)$amount, 2),
            'facts'       => $facts,
            'params'      => $params,
            'rules'       => array_values($rules),
            'norms'       => $norms,
            'title_key'   => $raw['title_key'],
            'text_key'    => $raw['text_key'],
            'title'       => $this->i18n->t($raw['title_key'], $display),
            'text'        => $this->i18n->t($raw['text_key'], $display),
            'footer'      => $this->footer($norms, $utility),
            'help'        => $this->help($utility),
            'letter'      => $letter,
            'todo'        => self::inWindow($todoUntil ?? $deadline, $date, $today),
            'href'        => $href ?? self::defaultHref($ref),
        ];
    }

    /**
     * Fußzeile jedes Hinweises (Grundsatz 3): „Hinweis, keine Rechtsberatung. Grundlage: {norms}
     * (Stand {date}).“ — Stand ist der älteste Abruf der genannten Regeln. Bei Strom und Gas der
     * Weg der Beschwerde (Verbraucherzentrale, Schlichtungsstelle Energie; Prüfbericht 54, 57).
     *
     * @param list<array{norm:string, checked_on:string}> $norms
     */
    public function footer(array $norms, string $utility): string
    {
        $date = min(array_column($norms, 'checked_on'));
        $text = $this->i18n->t('rights.footer', ['norms' => implode(', ', array_column($norms, 'norm')), 'date' => $this->i18n->date($date)]);
        return $text . ' ' . $this->help($utility);
    }

    /** Wo es Hilfe gibt: Verbraucherzentrale; bei Strom und Gas dazu die Schlichtungsstelle Energie. */
    public function help(string $utility): string
    {
        return $this->i18n->t(Rights::isEnergy($this->country(), $utility) ? 'rights.helpEnergy' : 'rights.help');
    }

    /**
     * Parameter für Titel und Text in der Schreibweise der Sprache: Tage (JJJJ-MM-TT) als
     * Datum, Schlüssel auf `_eur` als Geldbetrag, auf `_pct` als ganze Prozent, andere
     * Kommazahlen mit bis zu zwei Stellen. `params` im Befund bleiben roh.
     *
     * @param array<string,scalar|null> $params
     * @return array<string,scalar>
     */
    public function displayParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[$k] = match (true) {
                $v === null => '–',
                is_bool($v) => $v ? '1' : '0',
                is_string($v) && Dates::isIsoDate($v) => $this->i18n->date($v),
                (is_int($v) || is_float($v)) && str_ends_with($k, '_eur') => $this->i18n->money((float)$v),
                (is_int($v) || is_float($v)) && str_ends_with($k, '_pct') => $this->i18n->number((float)$v, 0),
                is_float($v) => $this->i18n->number($v, abs($v - round($v)) < 1e-9 ? 0 : 2),
                default => $v,
            };
        }
        return $out;
    }

    /**
     * Ziel eines Befunds ohne eigenes `href`: Rechnung → „Rechnung prüfen“ der Art und des
     * Zählers, Vertrag → Verträge der Art, Zähler → die Verbrauchsart mit dem Zähler.
     *
     * @param array{utility:string, meter_id:?string, contract_id:?string, bill_id:?string} $ref
     */
    public static function defaultHref(array $ref): string
    {
        $u = rawurlencode($ref['utility']);
        if ($ref['bill_id'] !== null) {
            return '#/bill-check?' . http_build_query(array_filter(['utility' => $ref['utility'], 'meter' => $ref['meter_id']], fn($v) => $v !== null));
        }
        if ($ref['contract_id'] !== null) return "#/utility/$u/contracts";
        if ($ref['meter_id'] !== null) return "#/utility/$u?meter=" . rawurlencode($ref['meter_id']);
        return "#/utility/$u";
    }
}
