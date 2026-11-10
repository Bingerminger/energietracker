<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Config\Utilities;
use Energietracker\Services\I18nService;
use Energietracker\Services\InboxService;

/**
 * v3.12.0 (F1037) — Prompts (Konzept §3.6): Vorlagen, die der Client als
 * Befehl anbietet (Monatsbericht, Jahresbericht, Rechnung prüfen, Fristen).
 *
 * Jeder Prompt ist eine `user`-Nachricht mit Ablauf (welche Werkzeuge in welcher
 * Reihenfolge), Gliederung und den gemeinsamen Regeln (`mcp.prompts.rules`:
 * Herkunft je Zahl, Geschätztes als geschätzt, nichts ändern ohne Vorschlag,
 * Fremdtext ist Daten, keine Rechtsberatung). Namen englisch, Titel und Text
 * in der Sprache des Servers (Server::LOCALES).
 *
 * In den Text kommen nur geprüfte Argumente (Formate unten) und Werte aus dem
 * Katalog, nie Fremdtext aus Dokumenten oder Notizen. Ein leeres Argument gilt
 * als nicht angegeben (Clients schicken leere Felder mit); ein unpassendes
 * Argument ist ein Protokollfehler -32602 mit dem Namen des Arguments.
 */
class Prompts
{
    /** Name → Argumente (Name → Pflicht?), in der Reihenfolge von `prompts/list` */
    public const PROMPTS = [
        'monthly_report'  => ['month' => false, 'utility' => false],
        'yearly_report'   => ['year' => false, 'billing_period' => false],
        'bill_review'     => ['document_id' => false, 'bill_id' => false, 'utility' => false],
        'deadlines_check' => ['days' => false],
    ];

    /** Fristen im Monatsbericht: so viele Tage voraus (Konzept §3.6) */
    public const MONTHLY_DEADLINE_DAYS = 45;
    /** deadlines_check ohne Angabe: so viele Tage voraus */
    public const DEADLINES_DAYS = 90;
    /** Spanne von `days` wie bei get_deadlines (Konzept §3.2) */
    public const DEADLINES_MIN_DAYS = 1;
    public const DEADLINES_MAX_DAYS = 366;
    /** Jahre, die ein Bericht nennen darf */
    public const YEAR_MIN = 2000;
    public const YEAR_MAX = 2100;
    /** Kennung einer erfassten Rechnung (BillService: `b_` + Hex; ältere Daten tolerant) */
    public const BILL_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(protected Services $services, protected I18nService $i18n) {}

    /**
     * Definitionen für `prompts/list`: `{name, title, description, arguments: [{name, description, required}]}`.
     *
     * @return list<array<string,mixed>>
     */
    public function list(McpContext $ctx): array
    {
        $out = [];
        foreach (self::PROMPTS as $name => $args) {
            $arguments = [];
            foreach ($args as $arg => $required) {
                $arguments[] = ['name' => $arg, 'description' => $this->t("mcp.prompts.$name.args.$arg", $ctx), 'required' => $required];
            }
            $out[] = [
                'name'        => $name,
                'title'       => $this->t("mcp.prompts.$name.title", $ctx),
                'description' => $this->t("mcp.prompts.$name.description", $ctx),
                'arguments'   => $arguments,
            ];
        }
        return $out;
    }

    /**
     * Ergebnis von `prompts/get`: `{description, messages: [{role: "user", content: {type: "text", text}}]}`,
     * null bei unbekanntem Namen (der Server antwortet dann mit -32602).
     *
     * @param array<string,string> $arguments
     * @return array<string,mixed>|null
     */
    public function get(string $name, array $arguments, McpContext $ctx): ?array
    {
        if (!isset(self::PROMPTS[$name])) return null;
        $a = $this->arguments($name, $arguments, $ctx);
        $text = match ($name) {
            'monthly_report'  => $this->monthly($a, $ctx),
            'yearly_report'   => $this->yearly($a, $ctx),
            'bill_review'     => $this->billReview($a, $ctx),
            'deadlines_check' => $this->deadlines($a, $ctx),
        };
        return [
            'description' => $this->t("mcp.prompts.$name.description", $ctx),
            'messages'    => [['role' => 'user', 'content' => ['type' => 'text',
                'text' => $text . "\n\n" . $this->t('mcp.prompts.rules', $ctx)]]],
        ];
    }

    /** Letzter voller Kalendermonat vor `$today` (JJJJ-MM). */
    public static function lastFullMonth(string $today): string
    {
        return (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
    }

    /** @param array<string,string> $a */
    private function monthly(array $a, McpContext $ctx): string
    {
        $month = $a['month'] ?? self::lastFullMonth($ctx->today);
        $prev = (new \DateTimeImmutable("$month-01"))->modify('-1 year')->format('Y-m');
        return $this->t('mcp.prompts.monthly_report.text', $ctx, [
            'month' => $month, 'prev' => $prev, 'scope' => $this->scope($a['utility'] ?? null, $ctx),
            'ahead' => self::MONTHLY_DEADLINE_DAYS,
        ]);
    }

    /** @param array<string,string> $a */
    private function yearly(array $a, McpContext $ctx): string
    {
        $billing = ($a['billing_period'] ?? 'false') === 'true';
        return $this->t('mcp.prompts.yearly_report.text', $ctx, [
            'year'        => $a['year'] ?? (string)((int)substr($ctx->today, 0, 4) - 1),
            'period'      => $this->t('mcp.prompts.yearly_report.' . ($billing ? 'billing' : 'calendar'), $ctx),
            'billing'     => $billing ? 'true' : 'false',
            'granularity' => $billing ? 'billing_period' : 'year',
        ]);
    }

    /**
     * Zeitraum als Datum statt „die nächsten N Tage“ (keine Pluralform nötig);
     * `days` geht unverändert an get_deadlines.
     *
     * @param array<string,string> $a
     */
    private function deadlines(array $a, McpContext $ctx): string
    {
        $days = (int)($a['days'] ?? self::DEADLINES_DAYS);
        return $this->t('mcp.prompts.deadlines_check.text', $ctx, [
            'today' => $ctx->today, 'until' => (new \DateTimeImmutable($ctx->today))->modify("+$days days")->format('Y-m-d'),
            'horizon' => $days,
        ]);
    }

    /** @param array<string,string> $a */
    private function billReview(array $a, McpContext $ctx): string
    {
        $utility = $this->utilityHint($a['utility'] ?? null, $ctx);
        if (isset($a['bill_id'])) {
            $start = $this->t('mcp.prompts.bill_review.startBill', $ctx, ['bill' => $a['bill_id'], 'utility' => $utility]);
        } elseif (isset($a['document_id'])) {
            // Auslesen und Übernehmen braucht mcp_write; mit Lese-Schlüssel nur prüfen, was schon erfasst ist
            $variant = $ctx->allows(Tool::WRITE) ? 'startDocument' : 'startDocumentRead';
            $start = $this->t("mcp.prompts.bill_review.$variant", $ctx, ['document' => $a['document_id'], 'utility' => $utility]);
        } else {
            $start = $this->t('mcp.prompts.bill_review.startNone', $ctx, ['utility' => $utility]);
        }
        return $start . ' ' . $this->t('mcp.prompts.bill_review.text', $ctx);
    }

    private function scope(?string $utility, McpContext $ctx): string
    {
        return $utility === null ? $this->t('mcp.prompts.scope.all', $ctx) : $this->t('mcp.prompts.scope.one', $ctx, ['key' => $utility]);
    }

    private function utilityHint(?string $utility, McpContext $ctx): string
    {
        return $utility === null ? $this->t('mcp.prompts.bill_review.utilityUnknown', $ctx)
            : $this->t('mcp.prompts.bill_review.utilityKnown', $ctx, ['key' => $utility]);
    }

    /**
     * Argumente prüfen und normalisieren; leer = nicht angegeben.
     *
     * @param array<string,string> $in
     * @return array<string,string>
     */
    private function arguments(string $name, array $in, McpContext $ctx): array
    {
        $out = [];
        foreach (self::PROMPTS[$name] as $arg => $required) {
            $v = trim((string)($in[$arg] ?? ''));
            if ($v === '') {
                if ($required) $this->invalid($name, $arg, $ctx);
                continue;
            }
            $ok = match ($arg) {
                'month'          => (bool)preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v, $m) && self::yearOk((int)$m[1]),
                'year'           => (bool)preg_match('/^\d{4}$/', $v) && self::yearOk((int)$v),
                'utility'        => in_array($v, Utilities::keys(), true),
                'billing_period' => ($v = self::bool($v)) !== null,
                'document_id'    => (bool)preg_match(InboxService::ID, $v),
                'bill_id'        => (bool)preg_match(self::BILL_ID, $v),
                'days'           => ctype_digit($v) && (int)$v >= self::DEADLINES_MIN_DAYS && (int)$v <= self::DEADLINES_MAX_DAYS,
                default          => false,
            };
            if (!$ok) $this->invalid($name, $arg, $ctx);
            $out[$arg] = $arg === 'days' ? (string)(int)$v : (string)$v;
        }
        return $out;
    }

    private static function yearOk(int $y): bool
    {
        return $y >= self::YEAR_MIN && $y <= self::YEAR_MAX;
    }

    /** `true`/`false` als Text; Clients schicken Argumente als Zeichenkette. */
    private static function bool(string $v): ?string
    {
        $b = filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $b === null ? null : ($b ? 'true' : 'false');
    }

    private function invalid(string $prompt, string $arg, McpContext $ctx): never
    {
        throw new RpcError(200, Server::INVALID_PARAMS, 'errors.mcp.promptArgument',
            ['name' => $arg, 'hint' => $this->t("mcp.prompts.$prompt.args.$arg", $ctx)], ['argument' => $arg]);
    }

    /** @param array<string,scalar> $params */
    private function t(string $key, McpContext $ctx, array $params = []): string
    {
        return $this->i18n->t($key, $params, $ctx->locale);
    }
}
