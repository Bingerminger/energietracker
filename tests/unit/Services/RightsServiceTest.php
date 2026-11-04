<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\RightsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (F1035, Paket basis) — die Befund-Form und ihr Weg durch die App, an einer
 * Beispiel-Prüfung. Die echten Prüfungen (A1 bis A7) liefern die Fachpakete; hier steht, was
 * jede von ihnen bekommt: Form und Fehler, stabile ID, nur Deutschland, das Fenster
 * (Grundsatz 5), Empfehlung `recht`, „Zu tun“ (`rights`) mit Ausblenden, Abhaken, Frist und
 * Zuständigkeit. Die Beispiel-Prüfung ist bewusst einfach: Sie meldet je Vertrag mit
 * Neukundenbonus einen erfundenen „Bonus fehlt“ zur Rechnung vom Anlass `$this->billDate`.
 */
#[CoversClass(RightsService::class)]
final class RightsServiceTest extends ServiceTestCase
{
    private RightsService $rights;
    private RecommendationService $recs;
    private AgendaService $agenda;
    private ActivityService $activity;
    private string $today;
    private string $meterId;
    private string $contractId;
    private string $billDate;
    private ?string $deadline = null;
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');   // Agenda und Ausblenden rechnen mit dem echten Tag; alle Daten relativ dazu
        $this->billDate = $this->day(-10);
        // verdrahtet wie in src/bootstrap.php
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $this->recs, $this->i18n);
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n, fn(): ?array => null);
        foreach ([$this->recs, $this->agenda, $this->contracts, $reminders] as $svc) $svc->attachActivity($this->activity);
        $this->activity->attach($reminders, $this->contracts, $this->recs);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $this->recs->attachRights($this->rights);
        $this->agenda->attachRights($this->rights);

        $this->meterId = $this->meters->defaultId('strom');
        $this->contractId = (string)$this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis',
            'start' => $this->day(-400), 'signup_bonus_eur' => 100.0,
            'working_prices' => [['from' => $this->day(-400), 'ct_per_kwh' => 30.0]],
        ])['id'];
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    /** Die Beispiel-Prüfung: so sieht ein Rohbefund aus, den eine Prüfung zurückgibt. */
    private function exampleCheck(): callable
    {
        return function (string $today, string $country): array {
            $this->calls++;
            $out = [];
            foreach ($this->contracts->list('strom') as $c) {
                if (empty($c['signup_bonus_eur'])) continue;
                $out[] = [
                    'severity'   => 'warning',
                    'utility'    => 'strom',
                    'meter_id'   => (string)$c['meter_id'],
                    'contract_id' => (string)$c['id'],
                    'bill_id'    => 'b_beispiel',
                    'date'       => $this->billDate,              // Anlass: Rechnungsdatum (stabil, nie „heute“)
                    'deadline'   => $this->deadline,
                    'amount_eur' => (float)$c['signup_bonus_eur'],
                    'facts'      => ['bonus_eur' => (float)$c['signup_bonus_eur'], 'bill_date' => $this->billDate, 'tariff' => (string)$c['tariff_name']],
                    'rules'      => Rights::applicable($country, ['de.enwg.41.4', 'de.vz.bonus', 'de.avbwasserv.25'], 'strom'),
                    'title_key'  => 'rights.check.bonus_missing',
                    'text_key'   => 'rights.deadline',             // Beispiel mit Datum im Text ({date})
                    'params'     => ['date' => $this->billDate],
                    'letter'     => 'complaint',
                ];
            }
            return $out;
        };
    }

    private function onlyFinding(?string $today = null): array
    {
        $all = $this->rights->findings($today ?? $this->today);
        self::assertCount(1, $all, json_encode($this->rights->failed()) ?: '');
        return $all[0];
    }

    /** @return list<array<string,mixed>> */
    private function rightsEvents(): array
    {
        return array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'rights'));
    }

    /** @return list<array<string,mixed>> */
    private function rightsRecs(): array
    {
        return array_values(array_filter($this->recs->all(), fn($r) => $r['category'] === 'recht'));
    }

    // ── Form ────────────────────────────────────────────────────────────

    public function testTheExampleCheckYieldsTheFullFindingForm(): void
    {
        $this->rights->register('bonus_missing', $this->exampleCheck());
        self::assertSame(['bonus_missing'], $this->rights->registered());
        $f = $this->onlyFinding();
        self::assertSame(['id', 'check', 'severity', 'utility', 'meter_id', 'contract_id', 'bill_id', 'date', 'deadline', 'remind_on',
            'amount_eur', 'facts', 'params', 'rules', 'norms', 'title_key', 'text_key', 'title', 'text', 'footer', 'help', 'letter', 'todo', 'href'],
            array_keys($f), 'Felder und Reihenfolge der Befund-Form');
        self::assertMatchesRegularExpression(RightsService::ID_PATTERN, $f['id']);
        self::assertSame(RightsService::id('bonus_missing', ['strom', $this->meterId, $this->contractId, 'b_beispiel']), $f['id']);
        self::assertSame(['bonus_missing', 'warning', 'strom', $this->contractId, 'b_beispiel', $this->billDate, null, null, 100.0, 'complaint', true],
            [$f['check'], $f['severity'], $f['utility'], $f['contract_id'], $f['bill_id'], $f['date'], $f['deadline'], $f['remind_on'], $f['amount_eur'], $f['letter'], $f['todo']]);
        self::assertSame(['de.enwg.41.4', 'de.vz.bonus'], $f['rules'], 'Wasser-Norm fällt über applicable() heraus');
        self::assertSame(['key' => 'de.enwg.41.4', 'norm' => '§ 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG',
            'url' => 'https://www.gesetze-im-internet.de/enwg_2005/__41.html', 'checked_on' => Rights::CHECKED_ON, 'status' => 'W'], $f['norms'][0]);
        // Texte in der Sprache der Anfrage, Tage in deren Schreibweise; params bleiben roh
        self::assertSame('Bonus nicht gutgeschrieben', $f['title']);
        self::assertSame('Frist: ' . $this->i18n->date($this->billDate), $f['text']);
        self::assertSame(['date' => $this->billDate], $f['params']);
        self::assertSame('Hinweis, keine Rechtsberatung. Grundlage: § 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG, '
            . '§ 40c Abs. 3 EnWG (Auslegung der Verbraucherzentrale) (Stand 03.11.2026). Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie.', $f['footer']);
        self::assertSame('Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie.', $f['help']);
        self::assertSame('#/bill-check?utility=strom&meter=' . $this->meterId, $f['href']);
        // dieselbe ID bei jedem Lauf (Ausblenden und Erledigt hängen daran)
        self::assertSame($f['id'], $this->onlyFinding()['id']);
        self::assertSame($f, $this->rights->find($f['id'], $this->today));
        self::assertNull($this->rights->find('rights-bonus_missing-000000000000', $this->today));
    }

    public function testParamsFollowTheLanguage(): void
    {
        $p = $this->rights->displayParams(['bonus_eur' => 100.0, 'share_pct' => 23.4, 'kwh' => 3000.0, 'days' => 3, 'on' => '2026-05-15', 'none' => null, 'name' => 'Basis']);
        self::assertSame([$this->i18n->money(100.0), '23', '3.000', 3, '15.05.2026', '–', 'Basis'],
            [$p['bonus_eur'], $p['share_pct'], $p['kwh'], $p['days'], $p['on'], $p['none'], $p['name']]);
    }

    public function testWaterGetsTheConsumerCentreButNoEnergyArbitration(): void
    {
        $f = $this->rights->finding(['check' => 'fee_notice_deadline', 'severity' => 'info', 'utility' => 'wasser', 'date' => '2026-01-27',
            'deadline' => '2026-02-28', 'rules' => ['de.vwgo.70.1', 'de.bgb.188'], 'title_key' => 'rights.check.fee_notice_deadline',
            'text_key' => 'rights.deadline', 'params' => ['date' => '2026-02-28'], 'letter' => 'objection'], '2026-02-01');
        self::assertStringEndsWith('Hilfe: Verbraucherzentrale.', $f['footer']);
        self::assertSame('2026-02-14', $f['remind_on']);
        self::assertTrue($f['todo'], 'Frist läuft noch');
        self::assertSame('#/utility/wasser', $f['href']);
        self::assertSame([], $f['facts']);
    }

    /** @return array<string, array{0:array<string,mixed>}> */
    public static function badFindings(): array
    {
        return [
            'unbekannte Prüfung'    => [['check' => 'nope']],
            'Schwere urgent'        => [['severity' => 'urgent']],
            'unbekannte Art'        => [['utility' => 'kohle']],
            'Zähler leer'           => [['meter_id' => '']],
            'Datum ungültig'        => [['date' => '31.12.2026']],
            'Frist ungültig'        => [['deadline' => '2026-13-01']],
            'Betrag als Text'       => [['amount_eur' => '100']],
            'keine Norm'            => [['rules' => []]],
            'Norm unbekannt'        => [['rules' => ['de.erfunden']]],
            'Norm unsicher (U)'     => [['rules' => ['de.enwg.2026']]],
            'Norm nur Gestaltung'   => [['rules' => ['de.bgh.i_zr_113_20']]],
            'Fakten als Liste'      => [['facts' => [1, 2]]],
            'Parameter verschachtelt' => [['params' => ['x' => ['y']]]],
            'Textschlüssel fremd'   => [['text_key' => 'recommendations.title']],
            'Brief nicht angeboten' => [['letter' => 'objection']],
            'Schlüssel kein Skalar' => [['key' => [['x']]]],
            'Ziel extern'           => [['href' => 'https://example.org']],
        ];
    }

    /** @param array<string,mixed> $patch */
    #[DataProvider('badFindings')]
    public function testAFormErrorIsAProgrammingError(array $patch): void
    {
        $raw = $patch + ['check' => 'bonus_missing', 'severity' => 'info', 'utility' => 'strom', 'date' => '2026-05-15',
            'rules' => ['de.enwg.41.4'], 'title_key' => 'rights.check.bonus_missing', 'text_key' => 'rights.footer'];
        $this->expectException(\LogicException::class);
        $this->rights->finding($raw, '2026-06-01');
    }

    public function testUnknownChecksCannotRegister(): void
    {
        $this->expectException(\LogicException::class);
        $this->rights->register('bonus', fn() => []);
    }

    // ── Land, Fenster, Fehler ──────────────────────────────────────────

    public function testOnlyCountriesWithRulesRunChecks(): void
    {
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $this->settings->set(['country' => 'AT']);
        self::assertFalse($this->rights->enabled());
        self::assertSame([], $this->rights->findings($this->today));
        self::assertSame([], $this->rights->run('bonus_missing', $this->today));
        self::assertSame(0, $this->calls, 'keine Prüfung läuft');
        self::assertSame([], $this->rightsRecs());
        $this->settings->set(['country' => 'DE']);
        self::assertCount(1, $this->rights->findings($this->today));
    }

    /**
     * v3.11.0 (integration) — Empfehlungen, „Zu tun“ und Zusammenfassung fragen in einer Anfrage dieselben
     * Befunde ab: Die Prüfungen laufen einmal je Tag, Land, Sprache und Datenstand; jede Änderung rechnet neu.
     */
    public function testFindingsAreComputedOncePerDayAndDataState(): void
    {
        $this->rights->register('bill_late', fn() => throw new \RuntimeException('kaputt'));
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $first = $this->rights->findings($this->today);
        $this->rightsRecs();
        $this->rightsEvents();
        self::assertSame($first, $this->rights->findings($this->today));
        self::assertSame(1, $this->calls, 'Empfehlungen und Agenda rechnen nicht noch einmal');
        self::assertSame([['check' => 'bill_late', 'error' => 'kaputt']], $this->rights->failed(), 'gescheiterte Prüfungen bleiben genannt');
        $this->rights->findings($this->day(1));
        self::assertSame(2, $this->calls, 'anderer Tag');
        $this->contracts->update('strom', $this->contractId, ['signup_bonus_eur' => 75.0]);
        self::assertSame(75.0, $this->rights->findings($this->day(1))[0]['amount_eur'], 'geänderte Daten');
        self::assertSame(3, $this->calls);
        $this->i18n->setLocale('en');
        $this->rights->findings($this->day(1));
        self::assertSame(4, $this->calls, 'andere Sprache (Texte der Befunde)');
    }

    public function testTheWindowDecidesWhatGoesToToDo(): void
    {
        self::assertSame(120, RightsService::WINDOW_DAYS);
        self::assertTrue(RightsService::inWindow(null, '2026-02-01', '2026-06-01'), '120 Tage');
        self::assertFalse(RightsService::inWindow(null, '2026-01-31', '2026-06-01'), '121 Tage');
        self::assertTrue(RightsService::inWindow('2026-06-01', '2025-01-01', '2026-06-01'), 'Frist heute: läuft noch');
        self::assertFalse(RightsService::inWindow('2026-05-31', '2026-05-30', '2026-06-01'), 'Frist vorbei');
        // ein alter Befund steht in der Liste (für „Rechnung prüfen“), nicht als Empfehlung und nicht unter „Zu tun“
        $this->billDate = $this->day(-200);
        $this->rights->register('bonus_missing', $this->exampleCheck());
        self::assertFalse($this->onlyFinding()['todo']);
        self::assertSame([], $this->rightsRecs());
        self::assertSame([], $this->rightsEvents());
    }

    public function testABrokenCheckDoesNotHideTheOthers(): void
    {
        $this->rights->register('bill_late', fn() => throw new \RuntimeException('kaputt'));
        $this->rights->register('bonus_missing', $this->exampleCheck());
        self::assertSame('bonus_missing', $this->onlyFinding()['check']);
        self::assertSame([['check' => 'bill_late', 'error' => 'kaputt']], $this->rights->failed());
        $this->expectException(\RuntimeException::class);
        $this->rights->run('bill_late', $this->today);   // streng: für Tests der Fachpakete
    }

    public function testTwoFindingsWithTheSameIdNeedAKey(): void
    {
        $check = $this->exampleCheck();
        $this->rights->register('bonus_missing', fn(string $t, string $c) => [...$check($t, $c), ...$check($t, $c)]);
        self::assertSame([], $this->rights->findings($this->today), 'doppelt: die Prüfung scheitert sichtbar');
        self::assertStringContainsString('add a key', $this->rights->failed()[0]['error']);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $this->rights->register('bonus_missing', fn(string $t, string $c) => [
            ['key' => ['2026-12-01']] + $check($t, $c)[0], ['key' => ['2027-01-01']] + $check($t, $c)[0]]);
        self::assertCount(2, $this->rights->findings($this->today));
    }

    // ── Empfehlung und „Zu tun“ ────────────────────────────────────────

    public function testAFindingIsARecommendationAndATaskUntilDismissedOrDone(): void
    {
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $f = $this->onlyFinding();
        $rec = $this->rightsRecs()[0] ?? self::fail('Empfehlung fehlt');
        self::assertSame([$f['id'], 'warning', 'Bonus nicht gutgeschrieben', $f['text']], [$rec['id'], $rec['severity'], $rec['title'], $rec['detail']]);
        self::assertSame(['utility' => 'strom', 'meter_id' => $this->meterId, 'contract_id' => $this->contractId, 'bill_id' => 'b_beispiel',
            'check' => 'bonus_missing', 'finding_id' => $f['id'], 'date' => $this->billDate, 'deadline' => null, 'amount_eur' => 100.0,
            'letter' => 'complaint', 'norms' => $f['norms'], 'footer' => $f['footer'], 'help' => $f['help'], 'href' => $f['href']], $rec['evidence']);

        $ev = $this->rightsEvents()[0] ?? self::fail('„Zu tun“ fehlt');
        self::assertSame([$f['id'], $this->today, 'due', true, true, 'rights.check.bonus_missing', $f['href']],
            [$ev['uid'], $ev['date'], $ev['severity'], $ev['due_now'], $ev['can_mark_done'], $ev['title_key'], $ev['href']]);
        self::assertSame(['check' => 'bonus_missing', 'deadline' => null, 'letter' => 'complaint', 'amount_eur' => 100.0, 'recommendation_id' => $f['id']],
            array_intersect_key($ev['ref'], array_flip(['check', 'deadline', 'letter', 'amount_eur', 'recommendation_id'])));
        self::assertSame($f['title'], $ev['title']);

        // ausblenden: ohne Frist weg aus „Zu tun“; wieder einblenden: zurück
        $this->recs->dismiss($f['id']);
        self::assertSame([], $this->rightsRecs());
        self::assertSame([], $this->rightsEvents());
        $this->recs->restore($f['id']);
        self::assertCount(1, $this->rightsEvents());

        // abhaken (Vorkommen am Anlass): Empfehlung und Aufgabe weg, im Verlauf mit Art `rights`
        $res = $this->agenda->markDone(['uid' => $f['id'], 'note' => 'Bonus kam per Überweisung']);
        self::assertSame('rights', $res['entry']['subject']['kind']);
        self::assertSame(ActivityService::occurrence($f['id'], $this->billDate), $res['entry']['subject']['occurrence']);
        self::assertSame([], $this->rightsRecs());
        self::assertSame([], $this->rightsEvents());
    }

    public function testAFindingWithADeadlineIsADateFromTheReminderOn(): void
    {
        $this->deadline = $this->day(30);
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $ev = $this->rightsEvents()[0] ?? self::fail('Termin fehlt');
        self::assertSame([$this->deadline, 'upcoming', false], [$ev['date'], $ev['severity'], $ev['due_now']], '30 Tage vorher: im Kalender, nicht fällig');
        self::assertSame($this->deadline, $this->rightsRecs()[0]['evidence']['deadline']);

        $this->deadline = $this->day(10);
        $this->rights->forget();   // v3.11.0 (integration) — die Frist der Beispielprüfung liegt nicht in den Daten
        $ev = $this->rightsEvents()[0];
        self::assertSame([$this->deadline, 'due', true], [$ev['date'], $ev['severity'], $ev['due_now']], 'ab 14 Tage vorher fällig');

        // ausgeblendet: die Frist bleibt als Termin (wie die Preiserhöhung), nur nicht mehr fällig;
        // das Ausblenden hält mindestens bis zur Frist
        $res = $this->recs->dismiss($ev['uid']);
        self::assertGreaterThanOrEqual($this->deadline, $res['until']);
        $ev = $this->rightsEvents()[0] ?? self::fail('Termin weg');
        self::assertSame(['upcoming', false], [$ev['severity'], $ev['due_now']]);
        self::assertArrayNotHasKey('recommendation_id', $ev['ref']);
    }

    /**
     * v3.11.0 (integration) — im Kalender bekommt eine Frist aus „Deine Rechte als Kunde“ eine
     * Vorab-Erinnerung wie der Kündigungsstichtag (VALARM, „Erinnern … Tage vorher“); ein Hinweis
     * ohne Frist keine.
     */
    public function testAFindingWithADeadlineGetsAnAlarmInTheCalendar(): void
    {
        $this->settings->set(['reminder_warn_days_before' => 14]);
        $cal = new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n);
        $block = fn(string $ics, string $uid) => (preg_match('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '@.*?END:VEVENT/s', $ics, $mm) ? $mm[0] : '');
        $this->deadline = $this->day(30);
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $id = $this->rightsEvents()[0]['uid'];
        $ev = $block($cal->ics($this->today), $id);
        self::assertStringContainsString("\r\nBEGIN:VALARM\r\n", $ev);
        self::assertStringContainsString("\r\nTRIGGER:-P14D\r\n", $ev);
        // ohne Frist ein Hinweis: im Kalender (am heutigen Tag), ohne Erinnerung
        $this->deadline = null;
        $this->rights->forget();
        $ev = $block($cal->ics($this->today), $id);
        self::assertStringContainsString('CATEGORIES:RIGHTS', $ev);
        self::assertStringNotContainsString('VALARM', $ev);
    }

    public function testResponsibilityIsInheritedFromTheContract(): void
    {
        $all = $this->store->read('strom/contracts.json', []);
        foreach ($all as &$c) $c['assignee_id'] = 'p_sabine';
        unset($c);
        $this->store->write('strom/contracts.json', $all);
        $resp = new ResponsibilityService($this->store);
        $resp->attachPeople(fn(string $id): ?array => $id === 'p_sabine' ? ['id' => 'p_sabine', 'name' => 'Sabine'] : null);
        $this->agenda->attachResponsibility($resp);
        $this->rights->register('bonus_missing', $this->exampleCheck());
        $ev = $this->rightsEvents()[0] ?? self::fail('„Zu tun“ fehlt');
        self::assertSame(['Sabine', 'p_sabine', 'contract'], [$ev['assignee'], $ev['assignee_id'], $ev['assignee_source']]);
    }

    public function testTheAgendaKnowsTheKind(): void
    {
        self::assertContains('rights', AgendaService::KINDS);
        self::assertContains('rights', ActivityService::MANUAL_KINDS);
        self::assertSame(['special_termination', 'advance_adjustment', 'estimate_query', 'complaint', 'objection'], RightsService::LETTERS);
        foreach (RightsService::CHECKS as $check => $letters) {
            foreach ($letters as $l) self::assertContains($l, RightsService::LETTERS, $check);
            self::assertNotSame("rights.check.$check", $this->i18n->t("rights.check.$check"), "Name der Prüfung $check im Katalog");
        }
    }
}
