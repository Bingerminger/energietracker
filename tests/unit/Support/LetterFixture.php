<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\BillService;
use Energietracker\Services\LetterService;
use Energietracker\Services\RightsService;

/**
 * v3.11.0 (F1036, Paket briefe) — erfundene Befunde für die Briefe, je Prüfung einer, in der
 * Form, die RightsService::finding() verlangt. Die echten Prüfungen (Pakete rechnung, vertrag)
 * melden sich in src/bootstrap.php an; die Briefe hängen nur an der Befund-Form und an den
 * `facts`, die LetterService im Kopf beschreibt. Alle Angaben erfunden (Musterstadt).
 *
 * Für Tests auf ServiceTestCase: `$this->letterSetUp()` in setUp().
 */
trait LetterFixture
{
    protected RightsService $rights;
    protected LetterService $letters;
    protected string $stromContract;
    protected string $gasContract;
    protected string $stromMeter;
    protected string $wasserMeter;
    /** @var array<string,string> Prüfung → ID des Befunds */
    protected array $ids = [];
    /** @var array<string,array<string,mixed>> Prüfung → Rohbefund (änderbar vor dem ersten find) */
    protected array $raw = [];

    protected const TODAY = '2026-11-03';

    protected function letterSetUp(): void
    {
        $this->settings->set(['country' => 'DE']);
        $this->stromMeter = $this->meters->defaultId('strom');
        $this->setMeterDevices('strom', [['id' => 'd_strom_1', 'serial' => 'Z-1234567', 'installed_on' => '2020-01-01', 'initial_counter' => 0]], $this->stromMeter);
        $this->meters->update('strom', $this->stromMeter, ['malo_id' => '51234567895']);
        $this->wasserMeter = $this->meters->defaultId('wasser');
        $this->stromContract = (string)$this->contracts->create('strom', [
            'meter_id' => $this->stromMeter, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis',
            'start' => '2025-01-01', 'customer_number' => 'K-4711', 'contract_number' => 'V-0815', 'supply_kind' => 'special',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
        ])['id'];
        $gasMeter = $this->meters->defaultId('gas');
        $this->gasContract = (string)$this->contracts->create('gas', [
            'meter_id' => $gasMeter, 'provider' => 'Gaswerk Musterstadt', 'tariff_name' => 'Grundversorgung',
            'start' => '2024-01-01', 'customer_number' => 'G-0042', 'supply_kind' => 'basic',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10.5]],
        ])['id'];

        $base = fn(string $u, ?string $meter, ?string $contract, array $more) => ['severity' => 'info', 'utility' => $u,
            'meter_id' => $meter, 'contract_id' => $contract, 'bill_id' => null, 'date' => '2026-10-20'] + $more;
        $this->raw = [
            'special_termination' => $base('strom', $this->stromMeter, $this->stromContract, [
                'severity' => 'warning', 'deadline' => '2026-11-30', 'rules' => ['de.enwg.41.5.s4'], 'letter' => 'special_termination',
                'facts' => ['announced_on' => '2026-10-20', 'valid_from' => '2026-12-01'],
            ]),
            'advance_high' => $base('gas', $gasMeter, $this->gasContract, [
                'rules' => ['de.gasgvv.13'], 'letter' => 'advance_adjustment', 'amount_eur' => 41.6,
                'facts' => ['period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'quantity' => 12000, 'assessment_eur' => 98.4,
                            'every_months' => 1, 'next_due' => '2026-12-01', 'working_price_ct' => 10.5, 'base_price_month_eur' => 12.0, 'rate_eur' => 140.0],
            ]),
            'estimate_despite_reading' => $base('strom', $this->stromMeter, $this->stromContract, [
                'rules' => ['de.enwg.40a.2.s3'], 'letter' => 'estimate_query', 'amount_eur' => 96.0,
                'facts' => ['issued_on' => '2026-10-15', 'period_to' => '2026-09-30', 'bill_reading' => 45210, 'own_reading' => 44890.5,
                            'own_reading_on' => '2026-09-28', 'reported_on' => '2026-09-29', 'photo' => true],
            ]),
            'bill_late' => $base('strom', $this->stromMeter, $this->stromContract, [
                'rules' => ['de.enwg.40c.2'], 'letter' => 'complaint',
                'facts' => ['period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-03-01',
                            'received_on' => '2026-03-04', 'deadline_on' => '2026-02-11', 'days_late' => 21],
            ]),
            'bonus_missing' => $base('gas', $gasMeter, $this->gasContract, [
                'rules' => ['de.vz.bonus'], 'letter' => 'complaint', 'amount_eur' => 100.0,
                'facts' => ['bonus_eur' => 100.0, 'tariff' => 'Grundversorgung', 'issued_on' => '2026-02-20', 'invoice_number' => 'R-2026-17'],
            ]),
            'metering_cap' => $base('strom', $this->stromMeter, $this->stromContract, [
                'rules' => ['de.msbg.30'], 'letter' => 'complaint', 'amount_eur' => 30.0,
                'facts' => ['annual_eur' => 60.0, 'cap_eur' => 30.0, 'cap_rule' => 'de.msbg.30', 'device' => 'imsys', 'issued_on' => '2026-10-01'],
            ]),
            'fee_notice_deadline' => $base('wasser', $this->wasserMeter, null, [
                'severity' => 'warning', 'deadline' => '2026-11-30', 'rules' => ['de.vwgo.70.1'], 'letter' => 'objection',
                'facts' => ['dated_on' => '2026-10-27', 'file_number' => 'KZ 2026-0815', 'levy' => 'Abwassergebühren 2026'],
            ]),
        ];

        $this->rights = new RightsService($this->settings, $this->i18n);
        foreach (array_keys(RightsService::CHECKS) as $check) {
            $this->rights->register($check, fn(string $today, string $country): array => isset($this->raw[$check])
                ? [$this->raw[$check] + ['title_key' => "rights.check.$check", 'text_key' => "rights.check.$check"]] : []);
        }
        foreach ($this->raw as $check => $r) {
            $this->ids[$check] = RightsService::id($check, [$r['utility'], $r['meter_id'], $r['contract_id'], $r['bill_id']]);
        }
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->letters = new LetterService($this->rights, $this->contracts, $this->meters, $bills);
    }

    /** Sender und Empfänger wie aus dem Dialog. */
    protected function person(): array
    {
        return ['sender' => ['Erika Mustermann', 'Musterweg 1', '12345 Musterstadt'], 'place' => 'Musterstadt'];
    }

    /** Brief zu einer Prüfung rendern (Modell). */
    protected function letter(string $check, array $body = []): array
    {
        $body += ['finding_id' => $this->ids[$check]] + $this->person();
        if ($check === 'fee_notice_deadline') $body['extra'] = ($body['extra'] ?? []) + ['confirm_objection' => true];
        return $this->letters->render($body, self::TODAY)['model'];
    }
}
