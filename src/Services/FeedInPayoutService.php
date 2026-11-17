<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\Input;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\TextLimits;

/**
 * v3.13.0 (Paket vertrag) — Auszahlungen der Einspeisung erfassen und gegen das Erwartete stellen.
 *
 * Die Saldo-Karte der Einspeisung rechnet seit v3.12.0 je Abrechnungsjahr, was der Netzbetreiber für die
 * eingespeiste Menge schuldet („erwartet“). Was er tatsächlich überwiesen hat, kannte die App nicht. Jetzt steht
 * es in einem eigenen Topf `feed_in_payouts.json` (Schlüssel `feed_in_payouts` im Backup), eine Liste:
 *
 *   id            fip_<12 hex>
 *   meter_id      Zähler der PV-Einspeisung
 *   date          Tag der Gutschrift auf dem Konto
 *   amount        Betrag in der Währung der Installation, positiv (eine Rückforderung des Netzbetreibers negativ)
 *   period_from, period_until   optional, beide oder keiner: der Zeitraum, den die Auszahlung abrechnet
 *                 (`period_until` einschließlich wie auf der Rechnung)
 *   note          optional, höchstens NOTE_MAX Zeichen
 *   source        manual (von Hand) | inbox (aus einem Dokument übernommen)
 *   created_at
 *
 * Zuordnung zum Abrechnungsjahr (received): mit Zeitraum anteilig nach Tagen, die in das Jahr fallen (eine
 * Jahresabrechnung für 2025, im Februar 2026 überwiesen, zählt für 2025); ohne Zeitraum am Tag der Gutschrift.
 * Gezählt wird nur, was bis zum Stichtag gutgeschrieben ist.
 *
 * Der Vertragsstatus behält `current_balance` und `projected_end_balance` (der Anspruch, ohne Auszahlungen);
 * an der laufenden Zeile der Einspeisung steht additiv `payouts` (annotate()) mit erhalten und offen. Ohne
 * Auszahlungen ändert sich keine Zahl.
 */
final class FeedInPayoutService
{
    public const FILE = 'feed_in_payouts.json';
    public const UTILITY = 'pv_einspeisung';
    public const ID = '/^fip_[0-9a-f]{12}$/';
    public const SOURCES = ['manual', 'inbox'];
    /** v3.13.0 — aus der einen Liste der Höchstlängen (TextLimits::FIELDS, auch im Backup-Import) */
    public const NOTE_MAX = TextLimits::SHORT_NOTE;
    /** Betrag höchstens so groß (beide Vorzeichen): eine Anlage im Haushalt, kein Kraftwerk. */
    public const AMOUNT_MAX = 1000000.0;

    private ?ActivityService $activity = null;

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /** Verlauf (nachträglich, wie bei den Rechnungen). */
    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /** @return list<array<string,mixed>> nach Tag, dann Kennung */
    public function list(?string $meterId = null): array
    {
        $all = $this->store->read(self::FILE, []);
        $all = array_values(array_filter(is_array($all) ? $all : [], fn($p) => is_array($p)
            && ($meterId === null || (string)($p['meter_id'] ?? '') === $meterId)));
        // JSON schreibt 120.0 als 120 (Lektion 90): Beträge immer als Kommazahl
        foreach ($all as &$p) if (is_numeric($p['amount'] ?? null)) $p['amount'] = (float)$p['amount'];
        unset($p);
        usort($all, fn($a, $b) => [(string)($a['date'] ?? ''), (string)($a['id'] ?? '')] <=> [(string)($b['date'] ?? ''), (string)($b['id'] ?? '')]);
        return $all;
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        foreach ($this->list() as $p) if (($p['id'] ?? null) === $id) return $p;
        throw new NotFoundException($this->i18n->t('errors.feedInPayout.notFound'));
    }

    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        $p = ['id' => 'fip_' . bin2hex(random_bytes(6))] + $this->normalize($input, null) + ['created_at' => date('c')];
        $all = $this->list();
        $all[] = $p;
        $this->store->write('feed_in_payouts.json', array_values($all));
        $this->activity?->record([
            'action'    => 'updated',
            'subject'   => ['kind' => 'feed_in_payout', 'uid' => 'feed_in_payout-' . $p['id'],
                            'occurrence' => 'feed_in_payout-' . $p['id'] . '@' . $p['date']],
            'ref'       => ['utility' => self::UTILITY, 'meter_id' => $p['meter_id'], 'payout_id' => $p['id']],
            'title_key' => 'activity.changed.feedInPayout',
            'params'    => ['amount' => $this->i18n->money((float)$p['amount'], 2), 'date' => $p['date']],
            'done_on'   => $p['date'] <= date('Y-m-d') ? $p['date'] : date('Y-m-d'),
        ]);
        return $p;
    }

    /** @return array<string,mixed> */
    public function update(string $id, array $input): array
    {
        $all = $this->list();
        foreach ($all as $i => $p) {
            if (($p['id'] ?? null) !== $id) continue;
            $all[$i] = array_merge($p, $this->normalize($input, $p));
            $this->store->write('feed_in_payouts.json', array_values($all));
            return $all[$i];
        }
        throw new NotFoundException($this->i18n->t('errors.feedInPayout.notFound'));
    }

    /**
     * Löschen; die Antwort ist der gelöschte Eintrag (die Oberfläche legt ihn bei „Rückgängig“ neu an). Der Eintrag
     * im Verlauf wird durchgestrichen.
     *
     * @return array<string,mixed>
     */
    public function delete(string $id): array
    {
        $old = $this->get($id);
        $this->store->write('feed_in_payouts.json', array_values(array_filter($this->list(), fn($p) => ($p['id'] ?? null) !== $id)));
        $this->activity?->refRevoked('payout_id', $id);
        return $old;
    }

    /**
     * Erhalten in einem Zeitraum [from, until] (einschließlich): mit Zeitraum der Auszahlung anteilig nach Tagen,
     * sonst am Tag der Gutschrift; nur, was bis `$today` gutgeschrieben ist.
     *
     * @return array{received:float,count:int}
     */
    public function receivedIn(string $meterId, string $from, string $until, string $today): array
    {
        $sum = 0.0; $n = 0;
        foreach ($this->list($meterId) as $p) {
            $date = (string)($p['date'] ?? '');
            if (!Dates::isIsoDate($date) || $date > $today) continue;
            $pf = $p['period_from'] ?? null; $pu = $p['period_until'] ?? null;
            if (Dates::isIsoDate($pf) && Dates::isIsoDate($pu) && $pf <= $pu) {
                $a = max($from, (string)$pf); $b = min($until, (string)$pu);
                if ($a > $b) continue;
                $share = (float)$p['amount'] * (self::days($a, $b) / self::days((string)$pf, (string)$pu));
            } elseif ($date >= $from && $date <= $until) {
                $share = (float)$p['amount'];
            } else {
                continue;
            }
            $sum += $share; $n++;
        }
        return ['received' => round($sum, 2), 'count' => $n];
    }

    /**
     * An der laufenden Zeile der Einspeisung (Vertragsstatus) additiv `payouts`: Zeitraum des Abrechnungsjahrs,
     * erhalten, Anzahl, offen bis heute (`current_balance` − erhalten) und offen zur Abrechnung
     * (`projected_end_balance` − erhalten). Vorzeichen wie der Saldo der Einspeisung: positiv = der Netzbetreiber
     * schuldet noch, negativ = mehr ausgezahlt als erwartet.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function annotate(array $meter, array $rows, string $today): array
    {
        $mid = (string)($meter['id'] ?? '');
        if ($mid === '' || !empty($meter['is_group'])) return $rows;
        foreach ($rows as $i => $r) {
            if (empty($r['is_current'])) continue;
            $from = (string)($r['balance_from'] ?? $r['start'] ?? '');
            $until = (string)($r['settle_on'] ?? '');
            if (!Dates::isIsoDate($from) || !Dates::isIsoDate($until)) continue;
            $got = $this->receivedIn($mid, $from, $until, $today);
            $cur = is_numeric($r['current_balance'] ?? null) ? (float)$r['current_balance'] : null;
            $proj = is_numeric($r['projected_end_balance'] ?? null) ? (float)$r['projected_end_balance'] : null;
            $rows[$i]['payouts'] = [
                'from' => $from, 'until' => $until,
                'received' => $got['received'], 'count' => $got['count'],
                'open' => $cur !== null ? round($cur - $got['received'], 2) : null,
                'open_at_settle' => $proj !== null ? round($proj - $got['received'], 2) : null,
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> geprüfte Felder (beim Ändern nur die übergebenen) */
    private function normalize(array $in, ?array $old): array
    {
        $isNew = $old === null;
        Input::assertTexts($in, ['meter_id', 'date', 'period_from', 'period_until', 'note', 'source']);
        // v3.13.0 (Paket F) — zu lang ist ein Fehler mit Feld und Grenze, gekürzt wird nie
        TextLimits::assert($in, 'feed_in_payouts', $old ?? []);
        $out = [];
        if ($isNew || array_key_exists('meter_id', $in)) {
            $mid = (string)($in['meter_id'] ?? '');
            if ($this->meters->get(self::UTILITY, $mid) === null) {
                throw new LocalizedException('errors.feedInPayout.meterInvalid', ['id' => mb_substr($mid, 0, 80)], 'feed-in payout: meter');
            }
            $out['meter_id'] = $mid;
        }
        if ($isNew || array_key_exists('date', $in)) {
            $d = (string)($in['date'] ?? '');
            if (!Dates::isIsoDate($d)) throw new LocalizedException('errors.feedInPayout.dateInvalid', ['value' => mb_substr($d, 0, 40)], 'feed-in payout: date');
            Input::assertDateInRange($d, 'date');
            $out['date'] = $d;
        }
        if ($isNew || array_key_exists('amount', $in)) {
            $a = Input::decimal($in['amount'] ?? null);
            if ($a === null || abs($a) < 0.005 || abs($a) > self::AMOUNT_MAX) {
                throw new LocalizedException('errors.feedInPayout.amountInvalid', ['max' => $this->i18n->number(self::AMOUNT_MAX, 0)], 'feed-in payout: amount');
            }
            $out['amount'] = round($a, 2);
        }
        if ($isNew || array_key_exists('period_from', $in) || array_key_exists('period_until', $in)) {
            $pf = array_key_exists('period_from', $in) ? ($in['period_from'] ?? null) : ($old['period_from'] ?? null);
            $pu = array_key_exists('period_until', $in) ? ($in['period_until'] ?? null) : ($old['period_until'] ?? null);
            $pf = $pf === '' ? null : $pf; $pu = $pu === '' ? null : $pu;
            if ($pf !== null || $pu !== null) {
                if (!Dates::isIsoDate($pf) || !Dates::isIsoDate($pu) || $pf > $pu) {
                    throw new LocalizedException('errors.feedInPayout.periodInvalid', [], 'feed-in payout: period');
                }
                Input::assertDateInRange((string)$pf, 'period_from');
                Input::assertDateInRange((string)$pu, 'period_until');
            }
            $out['period_from'] = $pf; $out['period_until'] = $pu;
        }
        if ($isNew || array_key_exists('note', $in)) $out['note'] = trim((string)($in['note'] ?? ''));
        if ($isNew || array_key_exists('source', $in)) {
            $s = (string)($in['source'] ?? 'manual');
            if (!in_array($s, self::SOURCES, true)) {
                throw new LocalizedException('errors.feedInPayout.sourceInvalid', ['allowed' => implode(', ', self::SOURCES)], 'feed-in payout: source');
            }
            $out['source'] = $s;
        }
        return $out;
    }

    /** Tage von bis einschließlich. */
    private static function days(string $from, string $until): int
    {
        return (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($until))->days + 1;
    }
}
